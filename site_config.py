# --- file: site_config.py ---
"""Site settings stored in meter_history.db (A6).

Device endpoints, UI titles, plant limits, and device passwords live here.
DB_BACKUP_ENCRYPTION_KEY stays in .env and is never imported.
PHP site_settings.php must use the same key names and the same x1.05 spike rule.
"""
import os
import sqlite3
from typing import Dict, Mapping, Optional

# Nameplate watts used when the operator has not saved a value yet.
# Spike rejection threshold is this value times SPIKE_HEADROOM.
DEFAULT_MAX_INVERTER_AC_W = 12000.0
SPIKE_HEADROOM = 1.05

# Match rollup.py / analytics.php gates before they were editable.
DEFAULT_NIGHT_START = 22
DEFAULT_NIGHT_END = 5
DEFAULT_SHOULDER_START = 20
DEFAULT_SHOULDER_END = 6
DEFAULT_RAD_FLOOR = 5.0

# .env key -> app_settings key. Encryption key is intentionally absent.
ENV_TO_SETTING = {
    'P1_IP': 'p1_ip',
    'SMA_IP': 'sma_ip',
    'SMA_USER': 'sma_user',
    'SMA_PASSWORD': 'sma_password',
    'SUNNYWEBBOX_IP': 'sunnywebbox_ip',
    'SUNNYWEBBOX_PASSWORD': 'sunnywebbox_password',
    'SITE_TITLE': 'site_title',
    'SITE_TAGLINE': 'site_tagline',
    'SITE_PAGE_TITLE': 'site_page_title',
}

SETTING_DEFAULTS = {
    'p1_ip': '',
    'sma_ip': '',
    'sma_user': '',
    'sma_password': '',
    'sunnywebbox_ip': '',
    'sunnywebbox_password': '',
    'site_title': 'Energy Dashboard',
    'site_tagline': 'Home Energy Hub',
    'site_page_title': 'Energy Dashboard',
    'max_inverter_ac_w': str(int(DEFAULT_MAX_INVERTER_AC_W)),
    'pac_night_start': str(DEFAULT_NIGHT_START),
    'pac_night_end': str(DEFAULT_NIGHT_END),
    'pac_shoulder_start': str(DEFAULT_SHOULDER_START),
    'pac_shoulder_end': str(DEFAULT_SHOULDER_END),
    'pac_rad_floor': str(DEFAULT_RAD_FLOOR),
}


def resolve_db_path() -> str:
    """Same database logger.py uses. HP_DB_FILE overrides for a local test only."""
    override = os.environ.get('HP_DB_FILE', '').strip()
    if override:
        return override
    base = os.path.dirname(os.path.abspath(__file__))
    is_synology = os.path.exists('/etc/synoinfo.conf') or os.path.exists('/etc.defaults/synoinfo.conf')
    if not is_synology:
        log_dir = '/var/log/p1'
        if os.path.exists(log_dir) and os.access(log_dir, os.W_OK):
            return os.path.join(log_dir, 'meter_history.db')
        shm = '/dev/shm'
        if os.path.exists(shm) and os.access(shm, os.W_OK):
            return os.path.join(shm, 'meter_history.db')
    return os.path.join(base, 'meter_history.db')


def read_env() -> Dict[str, str]:
    """Read .env next to this file or its parent. Missing file returns {}.

    Does not filter keys. Callers must ignore DB_BACKUP_ENCRYPTION_KEY.
    """
    base = os.path.dirname(os.path.abspath(__file__))
    candidates = [
        os.path.join(base, '.env'),
        os.path.join(os.path.dirname(base), '.env'),
        '.env',
    ]
    env_path = None
    for candidate in candidates:
        if os.path.exists(candidate) and os.access(candidate, os.R_OK):
            env_path = candidate
            break
    if env_path is None:
        return {}
    config: Dict[str, str] = {}
    with open(env_path, 'r', encoding='utf-8') as handle:
        for line in handle:
            line = line.strip()
            if not line or line.startswith('#') or '=' not in line:
                continue
            key, value = line.split('=', 1)
            config[key.strip()] = value.strip().strip("'\"")
    return config


def ensure_schema(conn: sqlite3.Connection) -> None:
    """Create app_settings if needed. Does not touch energy tables."""
    conn.execute('PRAGMA busy_timeout = 10000')
    conn.execute(
        """
        CREATE TABLE IF NOT EXISTS app_settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        )
        """
    )
    conn.commit()


def _settings_count(conn: sqlite3.Connection) -> int:
    row = conn.execute('SELECT COUNT(*) FROM app_settings').fetchone()
    return int(row[0]) if row else 0


def import_env_if_empty(
    conn: sqlite3.Connection,
    env: Optional[Mapping[str, str]] = None,
) -> bool:
    """Copy device and title keys from .env once, when app_settings has no rows.

    Returns True when this call inserted rows. Leaves the .env file unchanged.
    DB_BACKUP_ENCRYPTION_KEY is never copied.
    """
    ensure_schema(conn)
    conn.execute('BEGIN IMMEDIATE')
    try:
        if _settings_count(conn) > 0:
            conn.commit()
            return False
        source = dict(read_env() if env is None else env)
        inserted = False
        for env_key, setting_key in ENV_TO_SETTING.items():
            if env_key not in source:
                continue
            conn.execute(
                'INSERT INTO app_settings (key, value) VALUES (?, ?)',
                (setting_key, source[env_key]),
            )
            inserted = True
        if inserted:
            conn.execute(
                'INSERT INTO app_settings (key, value) VALUES (?, ?)',
                ('env_imported', '1'),
            )
        conn.commit()
        return inserted
    except Exception:
        conn.rollback()
        raise


def load_stored(conn: sqlite3.Connection) -> Dict[str, str]:
    """Raw app_settings rows. Missing keys are absent, not defaulted."""
    ensure_schema(conn)
    rows = conn.execute('SELECT key, value FROM app_settings').fetchall()
    return {str(key): '' if value is None else str(value) for key, value in rows}


def load_settings(conn: sqlite3.Connection) -> Dict[str, str]:
    """Stored values over defaults. Safe to read before the first save."""
    stored = load_stored(conn)
    merged = dict(SETTING_DEFAULTS)
    for key, value in stored.items():
        merged[key] = value
    return merged


def is_configured(conn: sqlite3.Connection) -> bool:
    """True when a P1 address has been imported or saved."""
    stored = load_stored(conn)
    return stored.get('p1_ip', '').strip() != ''


def _bounded_int(raw: str, default: int, low: int, high: int) -> int:
    try:
        value = int(float(raw))
    except (TypeError, ValueError):
        return default
    if value < low or value > high:
        return default
    return value


def _positive_float(raw: str, default: float) -> float:
    try:
        value = float(raw)
    except (TypeError, ValueError):
        return default
    if value <= 0:
        return default
    return value


def pac_runtime(conn: sqlite3.Connection) -> Dict[str, float]:
    """Spike cap and night/shoulder gates for rollup and charts.

    cap_w is max inverter AC times SPIKE_HEADROOM (1.05).
    Hour fields use the same wrap as the old hardcoded predicates:
    drop PAC when hour >= start OR hour < end.
    """
    settings = load_settings(conn)
    nameplate = _positive_float(settings.get('max_inverter_ac_w', ''), DEFAULT_MAX_INVERTER_AC_W)
    rad = settings.get('pac_rad_floor', '')
    try:
        rad_floor = float(rad)
    except (TypeError, ValueError):
        rad_floor = DEFAULT_RAD_FLOOR
    if rad_floor < 0:
        rad_floor = DEFAULT_RAD_FLOOR
    return {
        'cap_w': nameplate * SPIKE_HEADROOM,
        'nameplate_w': nameplate,
        'rad_floor': rad_floor,
        'night_start': float(_bounded_int(settings.get('pac_night_start', ''), DEFAULT_NIGHT_START, 0, 23)),
        'night_end': float(_bounded_int(settings.get('pac_night_end', ''), DEFAULT_NIGHT_END, 0, 23)),
        'shoulder_start': float(_bounded_int(settings.get('pac_shoulder_start', ''), DEFAULT_SHOULDER_START, 0, 23)),
        'shoulder_end': float(_bounded_int(settings.get('pac_shoulder_end', ''), DEFAULT_SHOULDER_END, 0, 23)),
    }
