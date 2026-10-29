# --- file: site_config.py ---
"""Site settings stored in meter_history.db (A6).

Device endpoints, UI titles, plant limits, and device passwords live here.
DB_BACKUP_ENCRYPTION_KEY stays in .env and is never imported.
PHP site_settings.php must use the same key names and the same x1.05 spike rule.
"""
import os
import sqlite3
from typing import Dict, Optional

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
    'sunnyportal_user': '',
    'sunnyportal_password': '',
    'sunnyportal_plant_id': '',
    'sunnyportal_timezone': '',
    'max_inverter_ac_w': str(int(DEFAULT_MAX_INVERTER_AC_W)),
    'pac_night_start': str(DEFAULT_NIGHT_START),
    'pac_night_end': str(DEFAULT_NIGHT_END),
    'pac_shoulder_start': str(DEFAULT_SHOULDER_START),
    'pac_shoulder_end': str(DEFAULT_SHOULDER_END),
    'pac_rad_floor': str(DEFAULT_RAD_FLOOR),
    'fluvius_ean_electricity': '',
    'fluvius_ean_gas': '',
    # A15: active flags are written on Save probe. Absent key + filled = grandfather active.
}

# Live integrations that support *_active (Fluvius is CSV-only, not listed).
INTEGRATION_ACTIVE_KEYS = {
    'p1': 'p1_active',
    'sma': 'sma_active',
    'sunnywebbox': 'sunnywebbox_active',
    'sunnyportal': 'sunnyportal_active',
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

    Used for DB_BACKUP_ENCRYPTION_KEY only. Device and portal settings are
    not read from .env.
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


def sunnyportal_login() -> Dict[str, str]:
    """User, password, plant id, and timezone from the Settings page.

    Raises SystemExit when user, password, or plant id is empty, or when
    sunnyportal_active is explicitly off after a failed Save probe.
    Timezone defaults to Europe/Paris.
    """
    conn = sqlite3.connect(resolve_db_path(), timeout=30.0)
    try:
        ensure_schema(conn)
        settings = load_settings(conn)
        stored = load_stored(conn)
    finally:
        conn.close()
    # Grandfather: missing active key in stored rows counts as active when filled.
    check = dict(settings)
    for key in INTEGRATION_ACTIVE_KEYS.values():
        if key not in stored:
            check.pop(key, None)
    if not integration_active(check, 'sunnyportal'):
        if integration_filled(settings, 'sunnyportal'):
            raise SystemExit(
                'Sunny Portal is inactive (last Settings probe failed or was offline). '
                'Fix credentials on Settings and Save again.'
            )
        raise SystemExit(
            'Sunny Portal is not configured. Set user, password, and plant id on Settings.'
        )
    user = settings.get('sunnyportal_user', '').strip()
    password = settings.get('sunnyportal_password', '').strip()
    plant_id = settings.get('sunnyportal_plant_id', '').strip()
    timezone_name = settings.get('sunnyportal_timezone', '').strip() or 'Europe/Paris'
    missing = [
        label
        for label, value in (
            ('user', user),
            ('password', password),
            ('plant id', plant_id),
        )
        if value == ''
    ]
    if missing:
        raise SystemExit(
            'Sunny Portal ' + ', '.join(missing) + ' is empty. Set it on the Settings page.'
        )
    return {
        'user': user,
        'password': password,
        'plant_id': plant_id,
        'timezone': timezone_name,
    }


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


def integration_filled(settings: Dict[str, str], name: str) -> bool:
    """True when the integration has enough stored fields to count as configured."""
    if name == 'p1':
        return (settings.get('p1_ip') or '').strip() != ''
    if name == 'sma':
        return (settings.get('sma_ip') or '').strip() != ''
    if name == 'sunnywebbox':
        return (settings.get('sunnywebbox_ip') or '').strip() != ''
    if name == 'sunnyportal':
        return (
            (settings.get('sunnyportal_user') or '').strip() != ''
            or (settings.get('sunnyportal_password') or '').strip() != ''
            or (settings.get('sunnyportal_plant_id') or '').strip() != ''
        )
    return False


def integration_active(settings: Dict[str, str], name: str) -> bool:
    """Active only when filled and flag is 1, or filled with missing flag (grandfather)."""
    if not integration_filled(settings, name):
        return False
    key = INTEGRATION_ACTIVE_KEYS.get(name)
    if key is None:
        return False
    if key not in settings:
        # load_settings merges defaults only; absent means never written -> grandfather.
        return True
    raw = (settings.get(key) or '').strip()
    if raw == '':
        return True
    return raw == '1'


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
