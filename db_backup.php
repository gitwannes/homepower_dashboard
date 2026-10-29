<?php
# --- file: db_backup.php ---
/**
 * Full database download and restore (A9).
 *
 * PHP checks the admin password and the restore confirm, then runs db_backup.py.
 * Python does the SQLite online backup, the zip, and AES-256-GCM. The key stays
 * in .env or the environment. It is never a command-line argument.
 * The browser receives the encrypted file. The plain backup and the encrypted
 * file on the server are deleted. p1-sync does not pull either file.
 */

/**
 * Encryption key from the environment or .env. Never stored in the database.
 */
function hp_backup_encryption_key(): string
{
    $key = getenv('DB_BACKUP_ENCRYPTION_KEY');
    if (is_string($key) && trim($key) !== '') {
        return trim($key);
    }
    $env = hp_read_env();
    if (isset($env['DB_BACKUP_ENCRYPTION_KEY'])) {
        return trim((string)$env['DB_BACKUP_ENCRYPTION_KEY']);
    }
    return '';
}

/**
 * Local stamp used in backup file names: yymmdd-HHMMSS.
 * Europe/Brussels is set by the page that calls this.
 */
function hp_backup_stamp(): string
{
    return date('ymd-His');
}

/**
 * Remove a temp directory and the files in it.
 */
function hp_backup_remove_tree(string $dir): void
{
    if ($dir === '' || !is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            hp_backup_remove_tree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

/**
 * Private temp directory. Not the web root.
 */
function hp_backup_work_dir(): string
{
    $dir = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'hp-backup-' . bin2hex(random_bytes(8));
    if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create a temporary directory for the backup.');
    }
    return $dir;
}

/**
 * Same Python picker as the dashboard poll button (hp_python_bin).
 */
function hp_backup_python(): string
{
    return hp_python_bin();
}

/**
 * Run db_backup.py. Arguments are the operation and paths. Never the key.
 *
 * @param array<int, string> $args
 */
function hp_backup_run(array $args): void
{
    // The child does the slow work. On Linux this wait is outside
    // max_execution_time, but a limit of 0 still covers the PHP that follows.
    @set_time_limit(0);
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (!function_exists('exec') || in_array('exec', $disabled, true)) {
        throw new RuntimeException('PHP exec() is disabled. Enable it for this Web Station profile.');
    }
    $script = __DIR__ . DIRECTORY_SEPARATOR . 'db_backup.py';
    if (!is_file($script)) {
        throw new RuntimeException('db_backup.py was not found.');
    }
    $cmd = escapeshellarg(hp_backup_python()) . ' ' . escapeshellarg($script);
    foreach ($args as $arg) {
        $cmd .= ' ' . escapeshellarg((string)$arg);
    }
    $output = [];
    $code = 0;
    exec($cmd . ' 2>&1', $output, $code);
    if ($code !== 0) {
        $text = trim(implode("\n", $output));
        if (strlen($text) > 500) {
            $text = substr($text, -500);
        }
        if ($text === '') {
            $text = 'Backup failed (Python exit ' . (string)$code . ').';
        }
        throw new RuntimeException($text);
    }
}

/**
 * Build the encrypted download into $encPath. Returns the browser file name.
 * Python deletes the plain bak and the zip. Caller deletes $encPath after send.
 */
function hp_backup_build_encrypted(string $livePath, string $workDir, string $encPath): string
{
    if (!is_dir($workDir)) {
        throw new RuntimeException('Could not create a temporary directory for the backup.');
    }
    $stamp = hp_backup_stamp();
    if (!preg_match('/^\d{6}-\d{6}$/', $stamp)) {
        throw new RuntimeException('Could not build the backup name.');
    }
    hp_backup_run(['-export', $livePath, $encPath, $stamp]);
    $size = is_file($encPath) ? filesize($encPath) : false;
    if ($size === false || $size < 44) {
        throw new RuntimeException('The encrypted backup was not created.');
    }
    return 'meter_history.db.' . $stamp . '.backup';
}

/**
 * Send a file and return. Caller deletes it afterwards.
 */
function hp_backup_send_file(string $path, string $downloadName): void
{
    $size = filesize($path);
    if ($size === false) {
        throw new RuntimeException('Could not read the encrypted backup.');
    }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . (string)$size);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Could not read the encrypted backup.');
    }
    fpassthru($handle);
    fclose($handle);
}

/**
 * Decrypt an upload into a checked SQLite file at $destPath.
 * The live database is not touched. The upload is not loaded into PHP memory.
 *
 * @param array<string, mixed> $file $_FILES entry
 */
function hp_backup_prepare_upload(array $file, string $key, string $destPath): void
{
    $err = isset($file['error']) ? (int)$file['error'] : UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        throw new RuntimeException('The upload is larger than PHP allows. Nothing was restored.');
    }
    if ($err !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed. Nothing was restored.');
    }
    $tmp = isset($file['tmp_name']) ? (string)$file['tmp_name'] : '';
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('No backup file was uploaded.');
    }
    if ($key === '') {
        throw new RuntimeException('DB_BACKUP_ENCRYPTION_KEY is missing from .env.');
    }
    hp_backup_run(['-import', $tmp, $destPath]);
    if (!is_file($destPath)) {
        throw new RuntimeException('The restored database was not created. The live database was kept.');
    }
}

/**
 * Fold the WAL into the live file so a later rename does not depend on it.
 */
function hp_backup_checkpoint(PDO $pdo): void
{
    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
}

/**
 * Replace the live database with a checked file that sits beside it.
 * Deletes the WAL and SHM sidecars so they cannot replay onto the new file.
 * On failure the previous live file is put back when that is still possible.
 */
function hp_backup_install(string $livePath, string $incomingPath): void
{
    $previous = $livePath . '.previous';
    $wal = $livePath . '-wal';
    $shm = $livePath . '-shm';
    if (is_file($previous) && !@unlink($previous)) {
        throw new RuntimeException('Could not remove an older previous database file. Nothing was replaced.');
    }
    if (!@rename($livePath, $previous)) {
        throw new RuntimeException('Could not move the live database aside. Nothing was replaced.');
    }
    if (!@rename($incomingPath, $livePath)) {
        @rename($previous, $livePath);
        throw new RuntimeException('Could not put the restored database in place. The live database was kept.');
    }
    $walGone = !is_file($wal) || @unlink($wal);
    $shmGone = !is_file($shm) || @unlink($shm);
    if (!$walGone || !$shmGone) {
        @unlink($livePath);
        @rename($previous, $livePath);
        throw new RuntimeException('Could not remove the old WAL files. The live database was put back.');
    }
    if (is_file($previous) && !@unlink($previous)) {
        throw new RuntimeException('The database was restored, but the previous file could not be removed yet: ' . basename($previous));
    }
}
