#!/usr/bin/env python3
# --- file: db_backup.py ---
"""SQLite online backup, zip, and AES-256-GCM for Settings (A9).

PHP checks the admin password and the restore confirm, then runs this script.
The encryption key is read here from the environment or .env. It is never a
command-line argument.

File layout matches the budget envelope and the previous PHP openssl path:
PBKDF2-HMAC-SHA256, 100000 iterations, 32-byte key, AES-256-GCM,
salt (16) + IV (12) + ciphertext + tag (16). The plaintext is a zip whose
first entry is meter_history.db.<stamp>.bak.

AES-GCM uses libcrypto (already on the NAS) so a large database is not
encrypted in pure Python. The pure-Python path is the fallback and is
checked against the NIST SP 800-38D AES-256 empty-AAD examples.
"""
import ctypes
import ctypes.util
import glob
import hashlib
import hmac
import os
import re
import sqlite3
import sys
import tempfile
import zipfile
from typing import BinaryIO, List, Optional, Sequence, Tuple

_PBKDF2_ROUNDS = 100000
_SALT_LEN = 16
_IV_LEN = 12
_TAG_LEN = 16
_CHUNK = 1024 * 1024
_STAMP_OK = re.compile(r'^\d{6}-\d{6}$')

# EVP_CTRL_GCM_* from openssl/evp.h. Same values on OpenSSL 1.1 and 3.
_GCM_SET_IVLEN = 0x9
_GCM_GET_TAG = 0x10
_GCM_SET_TAG = 0x11

_SBOX = bytes([
    0x63, 0x7c, 0x77, 0x7b, 0xf2, 0x6b, 0x6f, 0xc5, 0x30, 0x01, 0x67, 0x2b, 0xfe, 0xd7, 0xab, 0x76,
    0xca, 0x82, 0xc9, 0x7d, 0xfa, 0x59, 0x47, 0xf0, 0xad, 0xd4, 0xa2, 0xaf, 0x9c, 0xa4, 0x72, 0xc0,
    0xb7, 0xfd, 0x93, 0x26, 0x36, 0x3f, 0xf7, 0xcc, 0x34, 0xa5, 0xe5, 0xf1, 0x71, 0xd8, 0x31, 0x15,
    0x04, 0xc7, 0x23, 0xc3, 0x18, 0x96, 0x05, 0x9a, 0x07, 0x12, 0x80, 0xe2, 0xeb, 0x27, 0xb2, 0x75,
    0x09, 0x83, 0x2c, 0x1a, 0x1b, 0x6e, 0x5a, 0xa0, 0x52, 0x3b, 0xd6, 0xb3, 0x29, 0xe3, 0x2f, 0x84,
    0x53, 0xd1, 0x00, 0xed, 0x20, 0xfc, 0xb1, 0x5b, 0x6a, 0xcb, 0xbe, 0x39, 0x4a, 0x4c, 0x58, 0xcf,
    0xd0, 0xef, 0xaa, 0xfb, 0x43, 0x4d, 0x33, 0x85, 0x45, 0xf9, 0x02, 0x7f, 0x50, 0x3c, 0x9f, 0xa8,
    0x51, 0xa3, 0x40, 0x8f, 0x92, 0x9d, 0x38, 0xf5, 0xbc, 0xb6, 0xda, 0x21, 0x10, 0xff, 0xf3, 0xd2,
    0xcd, 0x0c, 0x13, 0xec, 0x5f, 0x97, 0x44, 0x17, 0xc4, 0xa7, 0x7e, 0x3d, 0x64, 0x5d, 0x19, 0x73,
    0x60, 0x81, 0x4f, 0xdc, 0x22, 0x2a, 0x90, 0x88, 0x46, 0xee, 0xb8, 0x14, 0xde, 0x5e, 0x0b, 0xdb,
    0xe0, 0x32, 0x3a, 0x0a, 0x49, 0x06, 0x24, 0x5c, 0xc2, 0xd3, 0xac, 0x62, 0x91, 0x95, 0xe4, 0x79,
    0xe7, 0xc8, 0x37, 0x6d, 0x8d, 0xd5, 0x4e, 0xa9, 0x6c, 0x56, 0xf4, 0xea, 0x65, 0x7a, 0xae, 0x08,
    0xba, 0x78, 0x25, 0x2e, 0x1c, 0xa6, 0xb4, 0xc6, 0xe8, 0xdd, 0x74, 0x1f, 0x4b, 0xbd, 0x8b, 0x8a,
    0x70, 0x3e, 0xb5, 0x66, 0x48, 0x03, 0xf6, 0x0e, 0x61, 0x35, 0x57, 0xb9, 0x86, 0xc1, 0x1d, 0x9e,
    0xe1, 0xf8, 0x98, 0x11, 0x69, 0xd9, 0x8e, 0x94, 0x9b, 0x1e, 0x87, 0xe9, 0xce, 0x55, 0x28, 0xdf,
    0x8c, 0xa1, 0x89, 0x0d, 0xbf, 0xe6, 0x42, 0x68, 0x41, 0x99, 0x2d, 0x0f, 0xb0, 0x54, 0xbb, 0x16,
])
_RCON = (0x00, 0x01, 0x02, 0x04, 0x08, 0x10, 0x20, 0x40, 0x80, 0x1b, 0x36)


class BackupError(Exception):
    """Operator-facing failure. The message must not contain the key."""


def encryption_key() -> str:
    """Key from the process environment, otherwise .env. Never from argv."""
    env = os.environ.get('DB_BACKUP_ENCRYPTION_KEY', '').strip()
    if env:
        return env
    try:
        import site_config
        values = site_config.read_env()
    except Exception:
        return ''
    return str(values.get('DB_BACKUP_ENCRYPTION_KEY', '')).strip()


def _require_key() -> bytes:
    key = encryption_key()
    if key == '':
        raise BackupError('DB_BACKUP_ENCRYPTION_KEY is missing from .env.')
    # PHP passes the .env bytes through. UTF-8 matches that for a UTF-8 file
    # and for any ASCII key.
    return key.encode('utf-8')


def _check_stamp(stamp: str) -> str:
    if _STAMP_OK.fullmatch(stamp) is None:
        raise BackupError('Backup name stamp is not valid.')
    return stamp


def _unlink(path: str) -> None:
    if path and os.path.isfile(path):
        try:
            os.remove(path)
        except OSError:
            pass


def _sub_word(word: int) -> int:
    return (
        (_SBOX[(word >> 24) & 0xff] << 24)
        | (_SBOX[(word >> 16) & 0xff] << 16)
        | (_SBOX[(word >> 8) & 0xff] << 8)
        | _SBOX[word & 0xff]
    )


def _rot_word(word: int) -> int:
    return ((word << 8) | (word >> 24)) & 0xffffffff


def _expand_key(key: bytes) -> List[int]:
    """AES-256 round keys. 60 big-endian words."""
    words = [int.from_bytes(key[i:i + 4], 'big') for i in range(0, 32, 4)]
    for i in range(8, 60):
        temp = words[i - 1]
        if i % 8 == 0:
            temp = _sub_word(_rot_word(temp)) ^ (_RCON[i // 8] << 24)
        elif i % 8 == 4:
            temp = _sub_word(temp)
        words.append((words[i - 8] ^ temp) & 0xffffffff)
    return words


def _aes_encrypt_block(block: bytes, words: Sequence[int]) -> bytes:
    state = [[0] * 4 for _ in range(4)]
    for i, value in enumerate(block):
        state[i % 4][i // 4] = value
    _add_round_key(state, words[0:4])
    for rnd in range(1, 14):
        _sub_bytes(state)
        _shift_rows(state)
        _mix_columns(state)
        _add_round_key(state, words[rnd * 4:(rnd + 1) * 4])
    _sub_bytes(state)
    _shift_rows(state)
    _add_round_key(state, words[56:60])
    out = bytearray(16)
    for i in range(16):
        out[i] = state[i % 4][i // 4]
    return bytes(out)


def _add_round_key(state: List[List[int]], words: Sequence[int]) -> None:
    for col in range(4):
        word = words[col]
        for row in range(4):
            state[row][col] ^= (word >> (24 - 8 * row)) & 0xff


def _sub_bytes(state: List[List[int]]) -> None:
    for row in range(4):
        for col in range(4):
            state[row][col] = _SBOX[state[row][col]]


def _shift_rows(state: List[List[int]]) -> None:
    for row in range(1, 4):
        state[row] = state[row][row:] + state[row][:row]


def _xtime(value: int) -> int:
    value <<= 1
    if value & 0x100:
        value ^= 0x1b
    return value & 0xff


def _mix_columns(state: List[List[int]]) -> None:
    for col in range(4):
        a = state[0][col]
        b = state[1][col]
        c = state[2][col]
        d = state[3][col]
        state[0][col] = _xtime(a) ^ (_xtime(b) ^ b) ^ c ^ d
        state[1][col] = a ^ _xtime(b) ^ (_xtime(c) ^ c) ^ d
        state[2][col] = a ^ b ^ _xtime(c) ^ (_xtime(d) ^ d)
        state[3][col] = (_xtime(a) ^ a) ^ b ^ c ^ _xtime(d)


def _gmul(x: int, y: int) -> int:
    """GHASH multiply in GF(2^128), NIST bit order."""
    z = 0
    v = x
    reduction = 0xE1000000000000000000000000000000
    mask = (1 << 128) - 1
    for i in range(128):
        if (y >> (127 - i)) & 1:
            z ^= v
        if v & 1:
            v = (v >> 1) ^ reduction
        else:
            v >>= 1
        v &= mask
    return z & mask


def _inc32(block: int) -> int:
    return (block & ~0xffffffff) | ((block + 1) & 0xffffffff)


def gcm_encrypt(key: bytes, iv: bytes, plain: bytes) -> Tuple[bytes, bytes]:
    """AES-256-GCM, 96-bit IV, empty AAD. Returns ciphertext and tag."""
    if len(key) != 32 or len(iv) != 12:
        raise BackupError('Backup encryption failed.')
    words = _expand_key(key)
    h = int.from_bytes(_aes_encrypt_block(b'\x00' * 16, words), 'big')
    j0 = int.from_bytes(iv + b'\x00\x00\x00\x01', 'big')
    counter = _inc32(j0)
    ciphertext = bytearray()
    y = 0
    offset = 0
    while offset < len(plain):
        block = plain[offset:offset + 16]
        keystream = _aes_encrypt_block(counter.to_bytes(16, 'big'), words)
        enc = bytes(a ^ b for a, b in zip(block, keystream))
        ciphertext.extend(enc)
        padded = enc + (b'\x00' * (16 - len(enc)))
        y = _gmul(y ^ int.from_bytes(padded, 'big'), h)
        counter = _inc32(counter)
        offset += 16
    length_block = (0).to_bytes(8, 'big') + ((len(plain) * 8).to_bytes(8, 'big'))
    y = _gmul(y ^ int.from_bytes(length_block, 'big'), h)
    tag_int = y ^ int.from_bytes(_aes_encrypt_block(j0.to_bytes(16, 'big'), words), 'big')
    return bytes(ciphertext), tag_int.to_bytes(16, 'big')


def gcm_decrypt(key: bytes, iv: bytes, ciphertext: bytes, tag: bytes) -> bytes:
    """Reverse gcm_encrypt. Raises BackupError when the tag does not match."""
    if len(tag) != 16:
        raise BackupError('Could not decrypt the backup. The key may be wrong, or the file is damaged.')
    words = _expand_key(key)
    h = int.from_bytes(_aes_encrypt_block(b'\x00' * 16, words), 'big')
    j0 = int.from_bytes(iv + b'\x00\x00\x00\x01', 'big')
    counter = _inc32(j0)
    plain = bytearray()
    y = 0
    offset = 0
    while offset < len(ciphertext):
        block = ciphertext[offset:offset + 16]
        padded = block + (b'\x00' * (16 - len(block)))
        y = _gmul(y ^ int.from_bytes(padded, 'big'), h)
        keystream = _aes_encrypt_block(counter.to_bytes(16, 'big'), words)
        plain.extend(bytes(a ^ b for a, b in zip(block, keystream)))
        counter = _inc32(counter)
        offset += 16
    length_block = (0).to_bytes(8, 'big') + ((len(ciphertext) * 8).to_bytes(8, 'big'))
    y = _gmul(y ^ int.from_bytes(length_block, 'big'), h)
    expect = y ^ int.from_bytes(_aes_encrypt_block(j0.to_bytes(16, 'big'), words), 'big')
    if not hmac.compare_digest(expect.to_bytes(16, 'big'), tag):
        raise BackupError('Could not decrypt the backup. The key may be wrong, or the file is damaged.')
    return bytes(plain)


def _libcrypto_candidates() -> List[str]:
    found: List[str] = []
    try:
        import _ssl
        ssl_dir = os.path.dirname(os.path.abspath(_ssl.__file__))
        found.extend(glob.glob(os.path.join(ssl_dir, 'libcrypto*')))
        found.extend(glob.glob(os.path.join(os.path.dirname(ssl_dir), 'DLLs', 'libcrypto*')))
    except Exception:
        pass
    located = ctypes.util.find_library('crypto')
    if located:
        found.append(located)
    found.extend([
        '/usr/lib/libcrypto.so.3',
        '/usr/lib/libcrypto.so.1.1',
        '/lib/libcrypto.so.3',
        '/lib/libcrypto.so.1.1',
        '/usr/local/lib/libcrypto.so.3',
        '/usr/local/lib/libcrypto.so.1.1',
        'libcrypto.so.3',
        'libcrypto.so.1.1',
        'libcrypto.so',
        'libcrypto-3.dll',
        'libcrypto-1_1-x64.dll',
    ])
    found.extend(glob.glob('/usr/lib/libcrypto.so*'))
    found.extend(glob.glob('/lib/libcrypto.so*'))
    unique: List[str] = []
    seen = set()
    for path in found:
        if path and path not in seen:
            seen.add(path)
            unique.append(path)
    return unique


def _load_libcrypto() -> Optional[ctypes.CDLL]:
    for path in _libcrypto_candidates():
        try:
            directory = os.path.dirname(path)
            if os.name == 'nt' and directory and os.path.isdir(directory):
                os.add_dll_directory(directory)
            lib = ctypes.CDLL(path)
        except (OSError, AttributeError):
            continue
        if not hasattr(lib, 'EVP_aes_256_gcm') or not hasattr(lib, 'EVP_EncryptInit_ex'):
            continue
        _bind_evp(lib)
        return lib
    return None


def _bind_evp(lib: ctypes.CDLL) -> None:
    void = ctypes.c_void_p
    lib.EVP_CIPHER_CTX_new.restype = void
    lib.EVP_CIPHER_CTX_free.argtypes = [void]
    lib.EVP_aes_256_gcm.restype = void
    lib.EVP_EncryptInit_ex.argtypes = [void, void, void, void, void]
    lib.EVP_EncryptInit_ex.restype = ctypes.c_int
    lib.EVP_DecryptInit_ex.argtypes = [void, void, void, void, void]
    lib.EVP_DecryptInit_ex.restype = ctypes.c_int
    update_args = [void, void, ctypes.POINTER(ctypes.c_int), void, ctypes.c_int]
    lib.EVP_EncryptUpdate.argtypes = update_args
    lib.EVP_EncryptUpdate.restype = ctypes.c_int
    lib.EVP_DecryptUpdate.argtypes = update_args
    lib.EVP_DecryptUpdate.restype = ctypes.c_int
    final_args = [void, void, ctypes.POINTER(ctypes.c_int)]
    lib.EVP_EncryptFinal_ex.argtypes = final_args
    lib.EVP_EncryptFinal_ex.restype = ctypes.c_int
    lib.EVP_DecryptFinal_ex.argtypes = final_args
    lib.EVP_DecryptFinal_ex.restype = ctypes.c_int
    lib.EVP_CIPHER_CTX_ctrl.argtypes = [void, ctypes.c_int, ctypes.c_int, void]
    lib.EVP_CIPHER_CTX_ctrl.restype = ctypes.c_int


_LIB = _load_libcrypto()


class _EvpGcm:
    """Streaming AES-256-GCM over an open libcrypto context."""

    def __init__(self, key: bytes, iv: bytes, decrypt: bool) -> None:
        if _LIB is None:
            raise BackupError('Backup encryption failed.')
        self._lib = _LIB
        self._decrypt = decrypt
        self._ctx = self._lib.EVP_CIPHER_CTX_new()
        if not self._ctx:
            raise BackupError('Backup encryption failed.')
        try:
            cipher = self._lib.EVP_aes_256_gcm()
            init = self._lib.EVP_DecryptInit_ex if decrypt else self._lib.EVP_EncryptInit_ex
            if init(self._ctx, cipher, None, None, None) != 1:
                raise BackupError('Backup encryption failed.')
            if self._lib.EVP_CIPHER_CTX_ctrl(self._ctx, _GCM_SET_IVLEN, len(iv), None) != 1:
                raise BackupError('Backup encryption failed.')
            key_buf = ctypes.create_string_buffer(key)
            iv_buf = ctypes.create_string_buffer(iv)
            if init(self._ctx, None, None, key_buf, iv_buf) != 1:
                raise BackupError('Backup encryption failed.')
            self._ready = True
        except Exception:
            self.close()
            raise

    def update(self, data: bytes) -> bytes:
        if not data:
            return b''
        out_buf = ctypes.create_string_buffer(len(data) + 16)
        out_len = ctypes.c_int(0)
        in_buf = ctypes.create_string_buffer(data)
        update = self._lib.EVP_DecryptUpdate if self._decrypt else self._lib.EVP_EncryptUpdate
        if update(self._ctx, out_buf, ctypes.byref(out_len), in_buf, len(data)) != 1:
            raise BackupError('Backup encryption failed.')
        return out_buf.raw[:out_len.value]

    def finish_encrypt(self) -> bytes:
        out_buf = ctypes.create_string_buffer(16)
        out_len = ctypes.c_int(0)
        if self._lib.EVP_EncryptFinal_ex(self._ctx, out_buf, ctypes.byref(out_len)) != 1:
            raise BackupError('Backup encryption failed.')
        tag_buf = ctypes.create_string_buffer(16)
        if self._lib.EVP_CIPHER_CTX_ctrl(self._ctx, _GCM_GET_TAG, 16, tag_buf) != 1:
            raise BackupError('Backup encryption failed.')
        return out_buf.raw[:out_len.value] + tag_buf.raw[:16]

    def finish_decrypt(self, tag: bytes) -> bytes:
        if len(tag) != 16:
            raise BackupError('Could not decrypt the backup. The key may be wrong, or the file is damaged.')
        tag_buf = ctypes.create_string_buffer(tag)
        if self._lib.EVP_CIPHER_CTX_ctrl(self._ctx, _GCM_SET_TAG, 16, tag_buf) != 1:
            raise BackupError('Could not decrypt the backup. The key may be wrong, or the file is damaged.')
        out_buf = ctypes.create_string_buffer(16)
        out_len = ctypes.c_int(0)
        if self._lib.EVP_DecryptFinal_ex(self._ctx, out_buf, ctypes.byref(out_len)) != 1:
            raise BackupError('Could not decrypt the backup. The key may be wrong, or the file is damaged.')
        return out_buf.raw[:out_len.value]

    def close(self) -> None:
        if getattr(self, '_ctx', None):
            self._lib.EVP_CIPHER_CTX_free(self._ctx)
            self._ctx = None


def _derive(passphrase: bytes, salt: bytes) -> bytes:
    return hashlib.pbkdf2_hmac('sha256', passphrase, salt, _PBKDF2_ROUNDS, dklen=32)


def _openssl_seal(plain: bytes, key: bytes, iv: bytes) -> Tuple[bytes, bytes]:
    ctx = _EvpGcm(key, iv, False)
    try:
        ciphertext = ctx.update(plain)
        tail = ctx.finish_encrypt()
    finally:
        ctx.close()
    return ciphertext + tail[:-16], tail[-16:]


def _require_libcrypto() -> None:
    """A missing libcrypto must fail fast. Pure Python AES is too slow for the live database."""
    if _LIB is None:
        raise BackupError('OpenSSL libcrypto was not found. Backup encryption cannot run.')


def encrypt_file(src: str, dest: str, passphrase: bytes) -> None:
    """Stream src into dest as salt + iv + ciphertext + tag."""
    _require_libcrypto()
    salt = os.urandom(_SALT_LEN)
    iv = os.urandom(_IV_LEN)
    derived = _derive(passphrase, salt)
    partial = dest + '.partial'
    _unlink(partial)
    try:
        _encrypt_file_openssl(src, partial, derived, salt, iv)
        os.replace(partial, dest)
    except Exception:
        _unlink(partial)
        _unlink(dest)
        raise


def _encrypt_file_openssl(src: str, dest: str, key: bytes, salt: bytes, iv: bytes) -> None:
    ctx = _EvpGcm(key, iv, False)
    try:
        with open(src, 'rb') as inp, open(dest, 'wb') as out:
            out.write(salt)
            out.write(iv)
            while True:
                chunk = inp.read(_CHUNK)
                if not chunk:
                    break
                out.write(ctx.update(chunk))
            out.write(ctx.finish_encrypt())
    finally:
        ctx.close()


def _encrypt_file_python(src: str, dest: str, key: bytes, salt: bytes, iv: bytes) -> None:
    """Fallback when libcrypto is missing. Correct, slow on a large zip."""
    with open(src, 'rb') as inp, open(dest, 'wb') as out:
        out.write(salt)
        out.write(iv)
        words = _expand_key(key)
        h = int.from_bytes(_aes_encrypt_block(b'\x00' * 16, words), 'big')
        j0 = int.from_bytes(iv + b'\x00\x00\x00\x01', 'big')
        counter = _inc32(j0)
        y = 0
        total = 0
        pending = b''
        while True:
            incoming = inp.read(_CHUNK)
            if not incoming and not pending:
                break
            pending += incoming
            while len(pending) >= 16:
                block = pending[:16]
                pending = pending[16:]
                keystream = _aes_encrypt_block(counter.to_bytes(16, 'big'), words)
                enc = bytes(a ^ b for a, b in zip(block, keystream))
                out.write(enc)
                y = _gmul(y ^ int.from_bytes(enc, 'big'), h)
                counter = _inc32(counter)
                total += 16
            if not incoming:
                break
        if pending:
            keystream = _aes_encrypt_block(counter.to_bytes(16, 'big'), words)
            enc = bytes(a ^ b for a, b in zip(pending, keystream))
            out.write(enc)
            padded = enc + (b'\x00' * (16 - len(enc)))
            y = _gmul(y ^ int.from_bytes(padded, 'big'), h)
            total += len(enc)
        length_block = (0).to_bytes(8, 'big') + ((total * 8).to_bytes(8, 'big'))
        y = _gmul(y ^ int.from_bytes(length_block, 'big'), h)
        tag_int = y ^ int.from_bytes(_aes_encrypt_block(j0.to_bytes(16, 'big'), words), 'big')
        out.write(tag_int.to_bytes(16, 'big'))


def decrypt_file(src: str, dest: str, passphrase: bytes) -> None:
    """Reverse encrypt_file. Deletes dest when the tag or header is wrong."""
    size = os.path.getsize(src)
    _require_libcrypto()
    if size < _SALT_LEN + _IV_LEN + _TAG_LEN:
        raise BackupError('This file is not an encrypted backup.')
    ct_len = size - _SALT_LEN - _IV_LEN - _TAG_LEN
    with open(src, 'rb') as inp:
        header = inp.read(15)
        if header == b'SQLite format 3':
            raise BackupError('Restore accepts only an encrypted backup file.')
        inp.seek(0)
        salt = inp.read(_SALT_LEN)
        iv = inp.read(_IV_LEN)
        derived = _derive(passphrase, salt)
        _unlink(dest)
        try:
            _decrypt_file_openssl(inp, dest, derived, iv, ct_len)
        except Exception:
            _unlink(dest)
            raise


def _decrypt_file_openssl(
    inp: BinaryIO,
    dest: str,
    key: bytes,
    iv: bytes,
    ct_len: int,
) -> None:
    ctx = _EvpGcm(key, iv, True)
    try:
        with open(dest, 'wb') as out:
            remaining = ct_len
            while remaining > 0:
                chunk = inp.read(min(_CHUNK, remaining))
                if not chunk:
                    raise BackupError('This file is not an encrypted backup.')
                remaining -= len(chunk)
                out.write(ctx.update(chunk))
            tag = inp.read(_TAG_LEN)
            if len(tag) != _TAG_LEN:
                raise BackupError('This file is not an encrypted backup.')
            out.write(ctx.finish_decrypt(tag))
    finally:
        ctx.close()


def _decrypt_file_python(inp: BinaryIO, dest: str, key: bytes, iv: bytes, ct_len: int) -> None:
    words = _expand_key(key)
    h = int.from_bytes(_aes_encrypt_block(b'\x00' * 16, words), 'big')
    j0 = int.from_bytes(iv + b'\x00\x00\x00\x01', 'big')
    counter = _inc32(j0)
    y = 0
    remaining = ct_len
    with open(dest, 'wb') as out:
        while remaining > 0:
            block = inp.read(min(16, remaining))
            if len(block) == 0:
                raise BackupError('This file is not an encrypted backup.')
            remaining -= len(block)
            padded = block + (b'\x00' * (16 - len(block)))
            y = _gmul(y ^ int.from_bytes(padded, 'big'), h)
            keystream = _aes_encrypt_block(counter.to_bytes(16, 'big'), words)
            out.write(bytes(a ^ b for a, b in zip(block, keystream)))
            counter = _inc32(counter)
        tag = inp.read(_TAG_LEN)
    length_block = (0).to_bytes(8, 'big') + ((ct_len * 8).to_bytes(8, 'big'))
    y = _gmul(y ^ int.from_bytes(length_block, 'big'), h)
    expect = y ^ int.from_bytes(_aes_encrypt_block(j0.to_bytes(16, 'big'), words), 'big')
    if len(tag) != _TAG_LEN or not hmac.compare_digest(expect.to_bytes(16, 'big'), tag):
        raise BackupError('Could not decrypt the backup. The key may be wrong, or the file is damaged.')


def sqlite_backup(live_path: str, dest_path: str) -> None:
    """Consistent copy, including pages still in the WAL."""
    if not os.path.isfile(live_path):
        raise BackupError('Database file was not found.')
    _unlink(dest_path)
    source = sqlite3.connect(live_path, timeout=30)
    dest = sqlite3.connect(dest_path)
    try:
        source.execute('PRAGMA busy_timeout = 15000')
        source.backup(dest, pages=64, sleep=0.05)
    except sqlite3.Error:
        raise BackupError('SQLite backup failed.')
    finally:
        dest.close()
        source.close()


def zip_one(source_path: str, inner_name: str, zip_path: str) -> None:
    _unlink(zip_path)
    with zipfile.ZipFile(zip_path, 'w', compression=zipfile.ZIP_DEFLATED, allowZip64=True) as archive:
        archive.write(source_path, arcname=inner_name)


def export_db(live_path: str, enc_path: str, stamp: str) -> None:
    """Backup, zip, encrypt. Deletes the plain bak and the zip before return."""
    stamp = _check_stamp(stamp)
    passphrase = _require_key()
    work = os.path.dirname(os.path.abspath(enc_path))
    inner = 'meter_history.db.' + stamp + '.bak'
    bak_path = os.path.join(work, inner)
    zip_path = os.path.join(work, 'meter_history.db.' + stamp + '.zip')
    wrote = False
    try:
        sqlite_backup(live_path, bak_path)
        zip_one(bak_path, inner, zip_path)
        encrypt_file(zip_path, enc_path, passphrase)
        wrote = True
    finally:
        _unlink(bak_path)
        _unlink(zip_path)
        if not wrote:
            _unlink(enc_path)


def _unzip_sqlite(zip_path: str, dest_path: str) -> None:
    try:
        archive = zipfile.ZipFile(zip_path, 'r')
    except zipfile.BadZipFile:
        raise BackupError('The decrypted backup is not a zip archive.')
    try:
        files = [info for info in archive.infolist() if not info.is_dir()]
        if not files:
            raise BackupError('The backup archive is empty.')
        with archive.open(files[0], 'r') as src, open(dest_path, 'wb') as dst:
            header = src.read(15)
            if header != b'SQLite format 3':
                raise BackupError('The backup archive does not contain a SQLite database.')
            dst.write(header)
            while True:
                chunk = src.read(_CHUNK)
                if not chunk:
                    break
                dst.write(chunk)
    finally:
        archive.close()


def assert_intact(sqlite_path: str) -> None:
    conn = sqlite3.connect(sqlite_path)
    try:
        conn.execute('PRAGMA query_only = ON')
        row = conn.execute('PRAGMA integrity_check').fetchone()
    except sqlite3.Error:
        raise BackupError('The restored database failed its integrity check. The live database was kept.')
    finally:
        conn.close()
    _unlink(sqlite_path + '-wal')
    _unlink(sqlite_path + '-shm')
    if row is None or row[0] != 'ok':
        raise BackupError('The restored database failed its integrity check. The live database was kept.')


def import_db(enc_path: str, sqlite_path: str) -> None:
    """Decrypt, unzip, and integrity-check. Does not touch the live database."""
    if not os.path.isfile(enc_path):
        raise BackupError('Could not read the uploaded backup.')
    passphrase = _require_key()
    zip_path = sqlite_path + '.zip'
    _unlink(zip_path)
    _unlink(sqlite_path)
    try:
        decrypt_file(enc_path, zip_path, passphrase)
        with open(zip_path, 'rb') as handle:
            magic = handle.read(4)
        if magic != b'PK\x03\x04':
            raise BackupError('The decrypted backup is not a zip archive.')
        _unzip_sqlite(zip_path, sqlite_path)
        assert_intact(sqlite_path)
    except Exception:
        _unlink(sqlite_path)
        raise
    finally:
        _unlink(zip_path)


def _selftest() -> None:
    """NIST AES-256 block, NIST GCM empty-AAD examples, then a sqlite round trip."""
    block_key = bytes.fromhex('000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f')
    block_plain = bytes.fromhex('00112233445566778899aabbccddeeff')
    block_cipher = bytes.fromhex('8ea2b7ca516745bfeafc49904b496089')
    got = _aes_encrypt_block(block_plain, _expand_key(block_key))
    if got != block_cipher:
        raise SystemExit('AES-256 block does not match FIPS-197 C.3')

    gcm_key = bytes.fromhex('feffe9928665731c6d6a8f9467308308feffe9928665731c6d6a8f9467308308')
    gcm_iv = bytes.fromhex('cafebabefacedbaddecaf888')
    empty_ct, empty_tag = gcm_encrypt(gcm_key, gcm_iv, b'')
    if empty_ct != b'' or empty_tag.hex() != 'fd2caa16a5832e76aa132c1453eeda7e':
        raise SystemExit('GCM empty plaintext does not match NIST SP 800-38D')
    sample = bytes.fromhex(
        'd9313225f88406e5a55909c5aff5269a86a7a9531534f7da2e4c303d8a318a72'
        '1c3c0c95956809532fcf0e2449a6b525b16aedf5aa0de657ba637b391aafd255'
    )
    sample_ct = bytes.fromhex(
        '522dc1f099567d07f47f37a32a84427d643a8cdcbfe5c0c97598a2bd2555d1aa'
        '8cb08e48590dbb3da7b08b1056828838c5f61e6393ba7a0abcc9f662898015ad'
    )
    sample_tag = bytes.fromhex('b094dac5d93471bdec1a502270e3cc6c')
    ct, tag = gcm_encrypt(gcm_key, gcm_iv, sample)
    if ct != sample_ct or tag != sample_tag:
        raise SystemExit('GCM sample does not match NIST SP 800-38D')
    if gcm_decrypt(gcm_key, gcm_iv, ct, tag) != sample:
        raise SystemExit('GCM decrypt did not return the sample')
    partial = sample + b'\x42'
    pct, ptag = gcm_encrypt(gcm_key, gcm_iv, partial)
    if gcm_decrypt(gcm_key, gcm_iv, pct, ptag) != partial:
        raise SystemExit('GCM partial-block round trip failed')

    pure_dir = tempfile.mkdtemp(prefix='hp-backup-pure-')
    pure_src = os.path.join(pure_dir, 'in.bin')
    pure_enc = os.path.join(pure_dir, 'in.enc')
    pure_out = os.path.join(pure_dir, 'in.out')
    try:
        payload = os.urandom(10007)
        with open(pure_src, 'wb') as handle:
            handle.write(payload)
        salt = b's' * 16
        iv = b'i' * 12
        derived = _derive(b'selftest-key', salt)
        _encrypt_file_python(pure_src, pure_enc, derived, salt, iv)
        with open(pure_enc, 'rb') as handle:
            handle.seek(_SALT_LEN + _IV_LEN)
            _decrypt_file_python(handle, pure_out, derived, iv, len(payload))
        with open(pure_out, 'rb') as handle:
            if handle.read() != payload:
                raise SystemExit('pure-python file round trip did not match')
    finally:
        for path in (pure_src, pure_enc, pure_out):
            _unlink(path)
        try:
            os.rmdir(pure_dir)
        except OSError:
            pass

    if _LIB is not None:
        oct, otag = _openssl_seal(sample, gcm_key, gcm_iv)
        if oct != sample_ct or otag != sample_tag:
            raise SystemExit('libcrypto AES-GCM does not match NIST SP 800-38D')
        empty_oct, empty_otag = _openssl_seal(b'', gcm_key, gcm_iv)
        if empty_oct != b'' or empty_otag.hex() != 'fd2caa16a5832e76aa132c1453eeda7e':
            raise SystemExit('libcrypto empty GCM does not match NIST SP 800-38D')

    os.environ['DB_BACKUP_ENCRYPTION_KEY'] = 'selftest-key'
    tmp = tempfile.mkdtemp(prefix='hp-backup-selftest-')
    try:
        live = os.path.join(tmp, 'live.db')
        enc = os.path.join(tmp, 'out.backup')
        restored = os.path.join(tmp, 'restored.db')
        conn = sqlite3.connect(live)
        conn.execute('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)')
        conn.execute('INSERT INTO t (v) VALUES (?)', ('keep-me',))
        conn.commit()
        conn.close()
        blob = os.path.join(tmp, 'blob.bin')
        back = os.path.join(tmp, 'blob.out')
        with open(blob, 'wb') as handle:
            handle.write(os.urandom(3000))
            handle.write(b'partial')
        encrypt_file(blob, enc, b'selftest-key')
        decrypt_file(enc, back, b'selftest-key')
        with open(blob, 'rb') as left, open(back, 'rb') as right:
            if left.read() != right.read():
                raise SystemExit('file encrypt round trip did not match')
        _unlink(enc)
        _unlink(back)
        export_db(live, enc, '261027-083600')
        names = set(os.listdir(tmp))
        if 'meter_history.db.261027-083600.bak' in names or 'meter_history.db.261027-083600.zip' in names:
            raise SystemExit('plain bak or zip was left behind')
        import_db(enc, restored)
        conn = sqlite3.connect(restored)
        row = conn.execute('SELECT v FROM t').fetchone()
        conn.close()
        if row is None or row[0] != 'keep-me':
            raise SystemExit('sqlite round trip lost the row')
        try:
            import_db(live, os.path.join(tmp, 'rejected.db'))
        except BackupError:
            pass
        else:
            raise SystemExit('raw sqlite upload was accepted')
        os.environ['DB_BACKUP_ENCRYPTION_KEY'] = 'other-key'
        try:
            import_db(enc, os.path.join(tmp, 'wrong.db'))
        except BackupError:
            pass
        else:
            raise SystemExit('wrong key was accepted')
        if os.path.isfile(os.path.join(tmp, 'wrong.db')):
            raise SystemExit('failed decrypt left a database file')
    finally:
        for root, dirs, files in os.walk(tmp, topdown=False):
            for name in files:
                _unlink(os.path.join(root, name))
            for name in dirs:
                try:
                    os.rmdir(os.path.join(root, name))
                except OSError:
                    pass
        try:
            os.rmdir(tmp)
        except OSError:
            pass
    engine = 'libcrypto' if _LIB is not None else 'pure-python'
    print('db_backup selftest ok (' + engine + ')')


def main(argv: Sequence[str]) -> int:
    try:
        if len(argv) == 2 and argv[1] == '-selftest':
            _selftest()
            return 0
        if len(argv) == 5 and argv[1] == '-export':
            export_db(argv[2], argv[3], argv[4])
            return 0
        if len(argv) == 4 and argv[1] == '-import':
            import_db(argv[2], argv[3])
            return 0
        print('Usage: db_backup.py -export LIVE OUT STAMP | -import ENC SQLITE_OUT', file=sys.stderr)
        return 2
    except BackupError as exc:
        print(str(exc), file=sys.stderr)
        return 1


if __name__ == '__main__':
    sys.exit(main(sys.argv))
