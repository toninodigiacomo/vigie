<?php
declare(strict_types=1);

// Authentication of the single administrator: password + TOTP (RFC 6238), both mandatory.
//
// First-run setup is protected by a one-time code written to the container log
// (docker logs 8213-vigie-api). Without it, nobody can take over Vigie by simply
// reaching the page first. The account is only created once the TOTP has been
// confirmed: an administrator without MFA cannot exist.

const VIGIE_SESSION_IDLE     = 30 * 60;       // maximum inactivity
const VIGIE_SESSION_ABSOLUTE = 12 * 3600;     // maximum session lifetime
const VIGIE_SESSION_REMEMBER = 7 * 86400;     // "stay signed in": lifetime and inactivity limit
const VIGIE_MAX_FAILURES     = 5;             // attempts before lockout
const VIGIE_LOCK_SECONDS     = 15 * 60;
const VIGIE_SETUP_TTL        = 3600;          // validity of the setup code
const VIGIE_PASSWORD_MIN     = 12;

// ── Cryptography ────────────────────────────────────────────────────────────

function vigie_app_key(): string
{
    $hex = (string) getenv('VIGIE_APP_KEY');
    if (!preg_match('/^[0-9a-fA-F]{64}$/', $hex)) throw new VigieError('app_key_invalid', [], 503);
    return hex2bin($hex);
}

function vigie_encrypt(string $plain): string
{
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, vigie_app_key()));
}

function vigie_decrypt(string $stored): string
{
    $raw = base64_decode($stored, true);
    $n = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
    $plain = $raw === false ? false : sodium_crypto_secretbox_open(substr($raw, $n), substr($raw, 0, $n), vigie_app_key());
    if ($plain === false) throw new VigieError('totp_undecryptable', [], 500);
    return $plain;
}

function vigie_password_algo(): string|int|null
{
    return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
}

function vigie_password_hash(string $password): string
{
    return password_hash($password, vigie_password_algo());
}

// ── TOTP ────────────────────────────────────────────────────────────────────

function base32_encode(string $bin): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 5) as $chunk) $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
    return $out;
}

function totp_code(string $secret, int $counter): string
{
    $h = hash_hmac('sha1', pack('J', $counter), $secret, true);
    $o = ord($h[19]) & 0x0f;
    $v = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
    return str_pad((string) ($v % 1000000), 6, '0', STR_PAD_LEFT);
}

/** Returns the accepted time step (±30 s tolerance), or null. Refuses any step already used. */
function totp_verify(string $secret, string $code, int $lastCounter = 0): ?int
{
    $code = preg_replace('/\s+/', '', $code);
    if (!preg_match('/^\d{6}$/', $code)) return null;
    $now = intdiv(time(), 30);
    foreach ([0, -1, 1] as $d) {
        $c = $now + $d;
        if ($c > $lastCounter && hash_equals(totp_code($secret, $c), $code)) return $c;
    }
    return null;
}

// ── Sessions ────────────────────────────────────────────────────────────────

function vigie_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' || !empty($_SERVER['HTTPS']);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    // Longest possible lifetime; the actual limits are enforced in vigie_is_logged_in()
    ini_set('session.gc_maxlifetime', (string) VIGIE_SESSION_REMEMBER);
    $dir = getenv('VIGIE_SESSION_DIR') ?: '';
    if ($dir !== '' && is_dir($dir) && is_writable($dir)) session_save_path($dir);
    session_name('vigie_sid');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict',
    ]);
    session_start();
}

function vigie_admin_stamp(): string
{
    $a = vigie_db()->query('SELECT created_at, password_hash FROM admin WHERE id = 1')->fetch();
    return $a ? hash('sha256', $a['created_at'] . '|' . $a['password_hash']) : '';
}

function vigie_is_logged_in(): bool
{
    vigie_session_start();
    $now = time();
    if (empty($_SESSION['admin'])) return false;
    $remember = !empty($_SESSION['remember']);
    $idle = $remember ? VIGIE_SESSION_REMEMBER : VIGIE_SESSION_IDLE;
    $max  = $remember ? VIGIE_SESSION_REMEMBER : VIGIE_SESSION_ABSOLUTE;
    if ($now - (int) $_SESSION['seen_at'] > $idle || $now - (int) $_SESSION['auth_at'] > $max) {
        $_SESSION = [];
        session_regenerate_id(true);
        return false;
    }
    // The session is bound to the account's state: a reset or a password change
    // immediately invalidates every open session.
    if (!hash_equals((string) ($_SESSION['stamp'] ?? ''), vigie_admin_stamp())) {
        $_SESSION = [];
        session_regenerate_id(true);
        return false;
    }
    $_SESSION['seen_at'] = $now;
    return true;
}

function vigie_admin_exists(): bool
{
    return (bool) vigie_db()->query('SELECT COUNT(*) FROM admin')->fetchColumn();
}

function vigie_require_admin(): void
{
    if (!vigie_is_logged_in()) {
        vigie_error('session_required', [], 401, ['auth' => vigie_admin_exists() ? 'login' : 'setup']);
    }
}

/**
 * Opens an authenticated session. With $remember, the cookie is persistent and the
 * session lasts 7 days; it is still bound to the account's state (see above).
 */
function vigie_open_session(bool $remember = false): void
{
    vigie_session_start();
    session_regenerate_id(true);
    $_SESSION = ['admin' => true, 'auth_at' => time(), 'seen_at' => time(), 'stamp' => vigie_admin_stamp(), 'remember' => $remember];
    if ($remember) {
        $p = session_get_cookie_params();
        setcookie(session_name(), session_id(), [
            'expires' => time() + VIGIE_SESSION_REMEMBER, 'path' => $p['path'], 'secure' => $p['secure'],
            'httponly' => true, 'samesite' => 'Strict',
        ]);
    }
}

// ── First-run setup code ────────────────────────────────────────────────────

function vigie_state_get(string $k): ?string
{
    $st = vigie_db()->prepare('SELECT v FROM app_state WHERE k = ?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string) $v;
}

function vigie_state_set(string $k, ?string $v): void
{
    if ($v === null) {
        vigie_db()->prepare('DELETE FROM app_state WHERE k = ?')->execute([$k]);
    } else {
        vigie_db()->prepare('INSERT INTO app_state (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')->execute([$k, $v]);
    }
}

/** Issues a new code and writes it to the container log. */
function vigie_setup_code_issue(): string
{
    $code = vigie_enroll_code();
    vigie_state_set('setup_hash', hash('sha256', $code));
    vigie_state_set('setup_expires', (string) (time() + VIGIE_SETUP_TTL));
    vigie_state_set('setup_failures', '0');
    error_log("vigie: SETUP CODE: $code (valid " . (VIGIE_SETUP_TTL / 60) . ' min)');
    vigie_audit('system', 'setup.code', 'setup code issued');
    return $code;
}

function vigie_setup_code_ensure(): void
{
    if ((int) (vigie_state_get('setup_expires') ?? 0) < time()) vigie_setup_code_issue();
}

// ── Endpoints ───────────────────────────────────────────────────────────────

// GET /api/auth/status → setup | login | ok
function auth_status(): never
{
    vigie_app_key();                                   // incomplete configuration: explicit message
    if (!vigie_admin_exists()) {
        vigie_setup_code_ensure();
        vigie_session_start();
        vigie_json(['state' => 'setup', 'step' => isset($_SESSION['setup_secret']) ? 'totp' : 'code',
                    'password_min' => VIGIE_PASSWORD_MIN]);
    }
    vigie_json(['state' => vigie_is_logged_in() ? 'ok' : 'login']);
}

// POST /api/auth/setup/start {code, password}
function auth_setup_start(): never
{
    $d = vigie_body();
    if (vigie_admin_exists()) throw new VigieError('already_configured', [], 409);
    $failures = (int) (vigie_state_get('setup_failures') ?? 0);
    if ($failures >= VIGIE_MAX_FAILURES) throw new VigieError('setup_too_many_attempts', [], 429);
    $code = strtoupper(trim((string) ($d['code'] ?? '')));
    $valid = (int) (vigie_state_get('setup_expires') ?? 0) >= time()
          && hash_equals((string) vigie_state_get('setup_hash'), hash('sha256', $code));
    if (!$valid) {
        vigie_state_set('setup_failures', (string) ($failures + 1));
        vigie_audit('ip:' . vigie_client_ip(), 'setup.refused', 'invalid setup code');
        if ($failures + 1 >= VIGIE_MAX_FAILURES) vigie_setup_code_issue();
        throw new VigieError('setup_code_invalid', [], 403);
    }
    $password = (string) ($d['password'] ?? '');
    if (mb_strlen($password) < VIGIE_PASSWORD_MIN) throw new VigieError('password_too_short', ['min' => VIGIE_PASSWORD_MIN]);
    vigie_session_start();
    session_regenerate_id(true);
    $secret = random_bytes(20);
    $_SESSION['setup_secret'] = base64_encode($secret);
    $_SESSION['setup_password'] = vigie_password_hash($password);
    $b32 = base32_encode($secret);
    vigie_json(['secret' => $b32, 'uri' => 'otpauth://totp/Vigie:admin?secret=' . $b32 . '&issuer=Vigie&digits=6&period=30']);
}

// POST /api/auth/setup/finish {totp}
function auth_setup_finish(): never
{
    $d = vigie_body();
    vigie_session_start();
    if (empty($_SESSION['setup_secret'])) throw new VigieError('setup_restart', [], 409);
    $secret = base64_decode($_SESSION['setup_secret']);
    $counter = totp_verify($secret, (string) ($d['totp'] ?? ''));
    if ($counter === null) throw new VigieError('totp_incorrect');

    $now = time();
    try {
        vigie_db()->prepare(<<<'SQL'
            INSERT INTO admin (id, password_hash, totp_secret, totp_last, last_login, created_at, updated_at)
            VALUES (1, ?, ?, ?, ?, ?, ?)
            SQL)->execute([$_SESSION['setup_password'], vigie_encrypt($secret), $counter, $now, $now, $now]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') throw new VigieError('already_configured', [], 409);
        throw $e;
    }
    vigie_state_set('setup_hash', null);
    vigie_state_set('setup_expires', null);
    vigie_audit('admin', 'setup.done', 'administrator created, TOTP enabled, from ' . vigie_client_ip());
    vigie_open_session();
    vigie_json(['ok' => true]);
}

// POST /api/auth/login {password, totp}
function auth_login(): never
{
    $d = vigie_body();
    $db = vigie_db();
    $admin = $db->query('SELECT * FROM admin WHERE id = 1')->fetch();
    if (!$admin) throw new VigieError('not_configured', [], 409);
    $now = time();
    if ((int) $admin['locked_until'] > $now) {
        throw new VigieError('login_locked', ['until' => date('H:i', (int) $admin['locked_until']), 'until_ts' => (int) $admin['locked_until']], 429);
    }
    $passOk = password_verify((string) ($d['password'] ?? ''), $admin['password_hash']);
    $counter = $passOk ? totp_verify(vigie_decrypt($admin['totp_secret']), (string) ($d['totp'] ?? ''), (int) $admin['totp_last']) : null;

    if (!$passOk || $counter === null) {
        $fails = (int) $admin['failed_count'] + 1;
        $lock = $fails >= VIGIE_MAX_FAILURES ? $now + VIGIE_LOCK_SECONDS : 0;
        $db->prepare('UPDATE admin SET failed_count = ?, locked_until = ? WHERE id = 1')
           ->execute([$lock ? 0 : $fails, $lock]);
        vigie_audit('ip:' . vigie_client_ip(), 'login.failed', $lock ? 'locked for ' . (VIGIE_LOCK_SECONDS / 60) . ' min' : "failure $fails");
        // Same message whether the password or the code was wrong
        throw new VigieError('login_invalid', [], 401);
    }
    $db->prepare('UPDATE admin SET failed_count = 0, locked_until = 0, totp_last = ?, last_login = ? WHERE id = 1')
       ->execute([$counter, $now]);
    if (password_needs_rehash($admin['password_hash'], vigie_password_algo())) {
        $db->prepare('UPDATE admin SET password_hash = ? WHERE id = 1')->execute([vigie_password_hash((string) $d['password'])]);
    }
    $remember = !empty($d['remember']);
    vigie_audit('admin', 'login', 'from ' . vigie_client_ip() . ($remember ? ', stay signed in 7 days' : ''));
    vigie_open_session($remember);
    vigie_json(['ok' => true]);
}

// POST /api/auth/logout
function auth_logout(): never
{
    vigie_session_start();
    $_SESSION = [];
    session_regenerate_id(true);
    session_destroy();
    vigie_json(['ok' => true]);
}

// POST /api/auth/password {current, totp, password}
function auth_password(): never
{
    vigie_require_admin();
    $d = vigie_body();
    $db = vigie_db();
    $admin = $db->query('SELECT * FROM admin WHERE id = 1')->fetch();
    $counter = password_verify((string) ($d['current'] ?? ''), $admin['password_hash'])
        ? totp_verify(vigie_decrypt($admin['totp_secret']), (string) ($d['totp'] ?? ''), (int) $admin['totp_last'])
        : null;
    if ($counter === null) throw new VigieError('current_invalid', [], 403);
    $new = (string) ($d['password'] ?? '');
    if (mb_strlen($new) < VIGIE_PASSWORD_MIN) throw new VigieError('password_too_short', ['min' => VIGIE_PASSWORD_MIN]);
    $db->prepare('UPDATE admin SET password_hash = ?, totp_last = ?, updated_at = ? WHERE id = 1')
       ->execute([vigie_password_hash($new), $counter, time()]);
    vigie_audit('admin', 'password.changed');
    vigie_open_session(!empty($_SESSION['remember']));
    vigie_json(['ok' => true]);
}
