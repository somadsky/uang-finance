<?php
declare(strict_types=1);

/**
 * Autentikasi Uang — satu token permanen milik pemilik aplikasi.
 *
 * Aturan yang dipegang berkas ini:
 * - Token TIDAK pernah berubah sendiri. Hanya berubah bila diganti manual
 *   lewat menu Pengaturan → Keamanan (atau tools/token.php di CLI).
 * - Token disimpan sebagai hash password_hash(), diverifikasi password_verify().
 * - Sesi berlaku tepat 24 jam sejak login berhasil dan TIDAK diperpanjang
 *   oleh aktivitas/refresh halaman.
 * - Logout hanya menghapus sesi, token tetap sama sehingga bisa dipakai
 *   login lagi kapan pun.
 * - IP dan perangkat dicatat untuk riwayat, tetapi IP bukan identitas:
 *   perubahan IP tidak mengakhiri sesi.
 */

if (!defined('AUTH_FILE')) {
    define('AUTH_FILE', DATA_DIR . '/auth.php');
}
if (!defined('AUTH_GUARD')) {
    define('AUTH_GUARD', "<?php exit; /* berkas autentikasi Uang - jangan diakses langsung */ ?>\n");
}
if (!defined('AUTH_COOKIE')) {
    define('AUTH_COOKIE', 'uang_sid');
}
if (!defined('AUTH_DEVICE_COOKIE')) {
    define('AUTH_DEVICE_COOKIE', 'uang_dev');
}
if (!defined('AUTH_SESSION_TTL')) {
    define('AUTH_SESSION_TTL', 86400); // tepat 24 jam
}
if (!defined('AUTH_MAX_ATTEMPTS')) {
    define('AUTH_MAX_ATTEMPTS', 5);    // percobaan token salah per IP
}
if (!defined('AUTH_LOCK_WINDOW')) {
    define('AUTH_LOCK_WINDOW', 900);   // 15 menit
}
if (!defined('AUTH_MIN_TOKEN')) {
    define('AUTH_MIN_TOKEN', 12);
}
if (!defined('AUTH_MAX_SESSIONS')) {
    define('AUTH_MAX_SESSIONS', 20);
}
if (!defined('AUTH_HISTORY_MAX')) {
    define('AUTH_HISTORY_MAX', 60);
}

/* ------------------------------------------------------------------ util */

function auth_now(): int
{
    return time();
}

function auth_iso(int $ts): string
{
    return date('c', $ts);
}

function auth_ts($iso): int
{
    if (!is_string($iso) || $iso === '') {
        return 0;
    }
    $t = strtotime($iso);
    return $t === false ? 0 : (int)$t;
}

/** Apakah permintaan ini lewat HTTPS (termasuk di belakang proxy). */
function auth_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if ((string)($_SERVER['SERVER_PORT'] ?? '') === '443') {
        return true;
    }
    $proto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if ($proto === 'https') {
        return true;
    }
    return false;
}

function auth_client_ip(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

/** Uraian kasar browser/OS dari User-Agent — cukup untuk ditampilkan. */
function auth_parse_ua(string $ua): array
{
    $browser = 'Peramban';
    $os = 'Tidak dikenal';
    $device = 'Komputer';

    $peta = [
        '/Edg\/([\d.]+)/i'                => 'Edge',
        '/OPR\/([\d.]+)/i'                => 'Opera',
        '/SamsungBrowser\/([\d.]+)/i'     => 'Samsung Internet',
        '/Firefox\/([\d.]+)/i'            => 'Firefox',
        '/Chrome\/([\d.]+)/i'             => 'Chrome',
        '/Version\/([\d.]+).*Safari/i'    => 'Safari',
    ];
    foreach ($peta as $pola => $nama) {
        if (preg_match($pola, $ua, $m)) {
            $versi = explode('.', $m[1]);
            $browser = $nama . ' ' . (int)$versi[0];
            break;
        }
    }

    if (preg_match('/Windows NT 10/i', $ua)) {
        $os = 'Windows 10/11';
    } elseif (preg_match('/Windows NT 6\.3/i', $ua)) {
        $os = 'Windows 8.1';
    } elseif (preg_match('/Windows/i', $ua)) {
        $os = 'Windows';
    } elseif (preg_match('/iPhone|iPad|iPod/i', $ua)) {
        $os = preg_match('/iPad/i', $ua) ? 'iPadOS' : 'iOS';
        $device = 'Ponsel';
    } elseif (preg_match('/Android/i', $ua)) {
        $os = 'Android';
        $device = 'Ponsel';
    } elseif (preg_match('/Mac OS X/i', $ua)) {
        $os = 'macOS';
    } elseif (preg_match('/CrOS/i', $ua)) {
        $os = 'ChromeOS';
    } elseif (preg_match('/Linux/i', $ua)) {
        $os = 'Linux';
    }

    if (preg_match('/Mobile|Android|iPhone/i', $ua) && $device === 'Komputer') {
        $device = 'Ponsel';
    }

    return ['browser' => $browser, 'os' => $os, 'device' => $device];
}

/* ------------------------------------------------------------- penyimpanan */

function auth_state(): array
{
    $kosong = [
        'version'        => 1,
        'tokenHash'      => '',
        'tokenUpdatedAt' => '',
        'createdAt'      => '',
        'sessions'       => [],
        'devices'        => [],
        'history'        => [],
        'attempts'       => [],
    ];
    if (!is_file(AUTH_FILE)) {
        return $kosong;
    }
    $isi = @file_get_contents(AUTH_FILE);
    if ($isi === false || $isi === '') {
        return $kosong;
    }
    $pos = strpos($isi, "\n");
    $json = $pos === false ? $isi : substr($isi, $pos + 1);
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return $kosong;
    }
    return array_merge($kosong, $data);
}

function auth_save(array $state): bool
{
    $dir = dirname(AUTH_FILE);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }
    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    $ok = @file_put_contents(AUTH_FILE, AUTH_GUARD . $json, LOCK_EX) !== false;
    if ($ok) {
        @chmod(AUTH_FILE, 0600);
    }
    return $ok;
}

function auth_token_exists(): bool
{
    $s = auth_state();
    return $s['tokenHash'] !== '';
}

/**
 * Pasang token baru (hash). Dipakai saat setup awal dan saat ganti token.
 * Token lama otomatis tidak berlaku karena hash-nya diganti.
 */
function auth_set_token(string $plain): array
{
    $plain = trim($plain);
    if (strlen($plain) < AUTH_MIN_TOKEN) {
        return ['ok' => false, 'error' => 'Token minimal ' . AUTH_MIN_TOKEN . ' karakter.'];
    }
    $state = auth_state();
    $state['tokenHash'] = password_hash($plain, PASSWORD_DEFAULT);
    $state['tokenUpdatedAt'] = auth_iso(auth_now());
    if ($state['createdAt'] === '') {
        $state['createdAt'] = $state['tokenUpdatedAt'];
    }
    // Percobaan salah yang lama tidak relevan lagi setelah token baru.
    $state['attempts'] = [];
    if (!auth_save($state)) {
        return ['ok' => false, 'error' => 'Berkas data tidak dapat ditulis. Periksa izin folder data/.'];
    }
    return ['ok' => true];
}

function auth_verify_token(string $plain): bool
{
    $state = auth_state();
    if ($state['tokenHash'] === '') {
        return false;
    }
    // Tetap jalankan verifikasi walau token kosong agar waktu respons serupa.
    return password_verify($plain, $state['tokenHash']);
}

/** Siapkan token pertama kali (hanya bila belum ada token). */
function auth_setup(string $plain, string $ulangi): array
{
    if (auth_token_exists()) {
        return ['ok' => false, 'status' => 409, 'error' => 'Token sudah dibuat. Masuk memakai token tersebut.'];
    }
    if ($plain !== $ulangi) {
        return ['ok' => false, 'status' => 422, 'error' => 'Token dan ulangi token tidak sama.'];
    }
    if (strlen(trim($plain)) < AUTH_MIN_TOKEN) {
        return ['ok' => false, 'status' => 422, 'error' => 'Token minimal ' . AUTH_MIN_TOKEN . ' karakter.'];
    }
    return auth_set_token($plain);
}

/* --------------------------------------------------------------- percobaan */

function auth_attempt_key(): string
{
    return hash('sha256', auth_client_ip() . '|uang');
}

function auth_attempt_locked(): int
{
    $state = auth_state();
    $key = auth_attempt_key();
    if (!isset($state['attempts'][$key])) {
        return 0;
    }
    $row = $state['attempts'][$key];
    $reset = auth_ts($row['resetAt'] ?? '');
    if ($reset <= auth_now()) {
        return 0;
    }
    if ((int)($row['count'] ?? 0) >= AUTH_MAX_ATTEMPTS) {
        return max(1, $reset - auth_now());
    }
    return 0;
}

function auth_attempt_fail(): void
{
    $state = auth_state();
    $key = auth_attempt_key();
    $now = auth_now();
    $row = $state['attempts'][$key] ?? ['count' => 0, 'resetAt' => auth_iso($now + AUTH_LOCK_WINDOW)];
    if (auth_ts($row['resetAt']) <= $now) {
        $row = ['count' => 0, 'resetAt' => auth_iso($now + AUTH_LOCK_WINDOW)];
    }
    $row['count'] = (int)$row['count'] + 1;
    if ($row['count'] >= AUTH_MAX_ATTEMPTS) {
        $row['resetAt'] = auth_iso($now + AUTH_LOCK_WINDOW);
    }
    $state['attempts'][$key] = $row;
    // Buang catatan percobaan yang sudah kedaluwarsa.
    foreach ($state['attempts'] as $k => $v) {
        if (auth_ts($v['resetAt'] ?? '') <= $now) {
            unset($state['attempts'][$k]);
        }
    }
    auth_save($state);
}

function auth_attempt_reset(): void
{
    $state = auth_state();
    unset($state['attempts'][auth_attempt_key()]);
    auth_save($state);
}

/* -------------------------------------------------------------------- cookie */

function auth_cookie_set(string $name, string $value, int $expires): void
{
    if (headers_sent()) {
        return;
    }
    $opsi = [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => auth_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    if (PHP_VERSION_ID >= 70300) {
        setcookie($name, $value, $opsi);
    } else {
        setcookie($name, $value, $expires, '/; samesite=Lax', '', auth_is_https(), true);
    }
}

function auth_cookie_clear(string $name): void
{
    auth_cookie_set($name, '', auth_now() - 3600);
}

/** ID perangkat (cookie tahan lama) — hanya untuk mengenali perangkat, bukan identitas. */
function auth_device_id(): string
{
    $ada = (string)($_COOKIE[AUTH_DEVICE_COOKIE] ?? '');
    if (preg_match('/^[a-f0-9]{32}$/', $ada)) {
        return $ada;
    }
    $baru = bin2hex(random_bytes(16));
    auth_cookie_set(AUTH_DEVICE_COOKIE, $baru, auth_now() + 31536000);
    $_COOKIE[AUTH_DEVICE_COOKIE] = $baru;
    return $baru;
}

function auth_sid_from_cookie(): string
{
    $sid = (string)($_COOKIE[AUTH_COOKIE] ?? '');
    return preg_match('/^[a-f0-9]{64}$/', $sid) ? $sid : '';
}

function auth_sid_hash(string $sid): string
{
    return hash('sha256', $sid);
}

/* ------------------------------------------------------------------- sesi */

/**
 * Buat sesi baru (tanpa cookie) — dipakai proses login dan pengujian.
 * Sesi selalu berakhir tepat AUTH_SESSION_TTL detik setelah dibuat.
 */
function auth_create_session(string $ua, string $ip, string $deviceId, ?int $mulai = null): array
{
    $state = auth_state();
    $now = $mulai ?? auth_now();
    $sid = bin2hex(random_bytes(32));
    $hash = auth_sid_hash($sid);
    $uaInfo = auth_parse_ua($ua);

    $sesi = [
        'id'        => substr($hash, 0, 16),
        'deviceId'  => $deviceId,
        'ip'        => $ip,
        'browser'   => $uaInfo['browser'],
        'os'        => $uaInfo['os'],
        'device'    => $uaInfo['device'],
        'loginAt'   => auth_iso($now),
        'expiresAt' => auth_iso($now + AUTH_SESSION_TTL),
        'lastSeen'  => auth_iso($now),
        'csrf'      => bin2hex(random_bytes(24)),
    ];

    $state['sessions'][$hash] = $sesi;

    // Batasi jumlah sesi: buang yang paling tua/kedaluwarsa lebih dulu.
    if (count($state['sessions']) > AUTH_MAX_SESSIONS) {
        uasort($state['sessions'], static function ($a, $b) {
            return auth_ts($a['loginAt'] ?? '') <=> auth_ts($b['loginAt'] ?? '');
        });
        while (count($state['sessions']) > AUTH_MAX_SESSIONS) {
            $kunci = array_key_first($state['sessions']);
            unset($state['sessions'][$kunci]);
        }
    }

    $state['devices'][$deviceId] = [
        'id'        => $deviceId,
        'label'     => $uaInfo['browser'] . ' · ' . $uaInfo['os'],
        'browser'   => $uaInfo['browser'],
        'os'        => $uaInfo['os'],
        'device'    => $uaInfo['device'],
        'ip'        => $ip,
        'firstSeen' => $state['devices'][$deviceId]['firstSeen'] ?? auth_iso($now),
        'lastSeen'  => auth_iso($now),
    ];

    auth_save($state);
    return ['sid' => $sid, 'session' => $sesi, 'csrf' => $sesi['csrf']];
}

function auth_session(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    $sid = auth_sid_from_cookie();
    if ($sid === '') {
        $cache = null;
        return null;
    }
    $hash = auth_sid_hash($sid);
    $state = auth_state();
    if (!isset($state['sessions'][$hash])) {
        $cache = null;
        return null;
    }
    $sesi = $state['sessions'][$hash];
    $kedaluwarsa = auth_ts($sesi['expiresAt'] ?? '');
    if ($kedaluwarsa <= auth_now()) {
        unset($state['sessions'][$hash]);
        auth_save($state);
        auth_cookie_clear(AUTH_COOKIE);
        $cache = null;
        return null;
    }
    $sesi['sidHash'] = $hash;

    // Catat aktivitas terakhir, tanpa mengubah masa berlaku sesi.
    $terakhir = auth_ts($sesi['lastSeen'] ?? '');
    if (auth_now() - $terakhir >= 120) {
        $state['sessions'][$hash]['lastSeen'] = auth_iso(auth_now());
        // IP hanya informasi; perubahan IP tidak mengakhiri sesi.
        if (isset($sesi['deviceId'], $state['devices'][$sesi['deviceId']])) {
            $state['devices'][$sesi['deviceId']]['lastSeen'] = auth_iso(auth_now());
        }
        auth_save($state);
    }

    $cache = $sesi;
    return $cache;
}

function auth_logged_in(): bool
{
    return auth_session() !== null;
}

/**
 * Login dengan token permanen. Mengembalikan array hasil; bila berhasil
 * cookie sesi sudah dipasang dan sesi berakhir 24 jam sejak saat ini.
 */
function auth_login(string $plain): array
{
    $tunggu = auth_attempt_locked();
    if ($tunggu > 0) {
        $menit = (int)ceil($tunggu / 60);
        return [
            'ok' => false,
            'status' => 429,
            'error' => 'Terlalu banyak percobaan. Coba lagi dalam ' . $menit . ' menit.',
            'retryAfter' => $tunggu,
        ];
    }
    if (!auth_token_exists()) {
        return ['ok' => false, 'status' => 409, 'error' => 'Token belum dibuat. Buka aplikasi untuk membuat token awal.'];
    }
    if (trim($plain) === '' || !auth_verify_token($plain)) {
        auth_attempt_fail();
        auth_history_add('gagal', 'Token salah');
        return ['ok' => false, 'status' => 401, 'error' => 'Token salah.'];
    }

    auth_attempt_reset();
    $ip = auth_client_ip();
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $deviceId = auth_device_id();
    $hasil = auth_create_session($ua, $ip, $deviceId);

    auth_cookie_set(AUTH_COOKIE, $hasil['sid'], auth_now() + AUTH_SESSION_TTL);
    auth_history_add('berhasil', '');
    auth_cleanup();

    return [
        'ok' => true,
        'csrf' => $hasil['csrf'],
        'expiresAt' => $hasil['session']['expiresAt'],
        'loginAt' => $hasil['session']['loginAt'],
        'device' => $hasil['session']['browser'] . ' · ' . $hasil['session']['os'],
        'ip' => $ip,
    ];
}

/** Jumlah sesi yang belum kedaluwarsa (untuk alat CLI). */
function auth_count_active_sessions(): int
{
    $state = auth_state();
    $now = auth_now();
    $n = 0;
    foreach ($state['sessions'] as $s) {
        if (auth_ts($s['expiresAt'] ?? '') > $now) {
            $n++;
        }
    }
    return $n;
}

function auth_logout_current(): void
{
    $sesi = auth_session();
    if ($sesi !== null) {
        $state = auth_state();
        unset($state['sessions'][$sesi['sidHash']]);
        auth_save($state);
        auth_history_add('keluar', 'Perangkat ini');
    }
    auth_cookie_clear(AUTH_COOKIE);
}

function auth_logout_device(string $sessionId): bool
{
    $state = auth_state();
    $ketemu = false;
    foreach ($state['sessions'] as $hash => $sesi) {
        if (($sesi['id'] ?? '') === $sessionId) {
            unset($state['sessions'][$hash]);
            $ketemu = true;
        }
    }
    if ($ketemu) {
        auth_save($state);
        auth_history_add('keluar', 'Perangkat lain');
    }
    return $ketemu;
}

/** Keluarkan semua perangkat. Bila $kecualiSekarang, sesi aktif dipertahankan. */
function auth_logout_all(bool $kecualiSekarang = false): int
{
    $state = auth_state();
    $sekarang = auth_session();
    $jumlah = 0;
    foreach ($state['sessions'] as $hash => $sesi) {
        if ($kecualiSekarang && $sekarang !== null && $hash === $sekarang['sidHash']) {
            continue;
        }
        unset($state['sessions'][$hash]);
        $jumlah++;
    }
    auth_save($state);
    if (!$kecualiSekarang) {
        auth_cookie_clear(AUTH_COOKIE);
    }
    auth_history_add('keluar', 'Semua perangkat (' . $jumlah . ')');
    return $jumlah;
}

function auth_change_token(string $lama, string $baru, string $ulangi): array
{
    if (!auth_logged_in()) {
        return ['ok' => false, 'status' => 401, 'error' => 'Sesi berakhir. Masuk kembali.'];
    }
    if (!auth_verify_token($lama)) {
        auth_history_add('gagal', 'Ganti token: token lama salah');
        return ['ok' => false, 'status' => 422, 'error' => 'Token lama tidak cocok.'];
    }
    if ($baru !== $ulangi) {
        return ['ok' => false, 'status' => 422, 'error' => 'Token baru dan ulanginya tidak sama.'];
    }
    if (strlen(trim($baru)) < AUTH_MIN_TOKEN) {
        return ['ok' => false, 'status' => 422, 'error' => 'Token baru minimal ' . AUTH_MIN_TOKEN . ' karakter.'];
    }
    if (hash_equals(trim($lama), trim($baru))) {
        return ['ok' => false, 'status' => 422, 'error' => 'Token baru harus berbeda dari token lama.'];
    }
    $hasil = auth_set_token($baru);
    if ($hasil['ok']) {
        auth_history_add('token', 'Token diganti');
    }
    return $hasil;
}

/* ------------------------------------------------------------------- csrf */

function auth_csrf_token(): string
{
    $sesi = auth_session();
    return $sesi === null ? '' : (string)$sesi['csrf'];
}

function auth_csrf_valid(?string $diberikan): bool
{
    $sesi = auth_session();
    if ($sesi === null || $diberikan === null || $diberikan === '') {
        return false;
    }
    return hash_equals((string)$sesi['csrf'], (string)$diberikan);
}

/* -------------------------------------------------------- riwayat & device */

function auth_history_add(string $jenis, string $catatan): void
{
    $state = auth_state();
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $info = auth_parse_ua($ua);
    array_unshift($state['history'], [
        'time'    => auth_iso(auth_now()),
        'result'  => $jenis,       // berhasil | gagal | keluar | token
        'note'    => $catatan,
        'ip'      => auth_client_ip(),
        'browser' => $info['browser'],
        'os'      => $info['os'],
    ]);
    if (count($state['history']) > AUTH_HISTORY_MAX) {
        $state['history'] = array_slice($state['history'], 0, AUTH_HISTORY_MAX);
    }
    auth_save($state);
}

/** Ringkasan untuk halaman Pengaturan (tanpa token/hash). */
function auth_info(): array
{
    $state = auth_state();
    $sesi = auth_session();
    $sekarang = auth_now();

    $perangkat = [];
    foreach ($state['sessions'] as $hash => $s) {
        if (auth_ts($s['expiresAt'] ?? '') <= $sekarang) {
            continue;
        }
        $perangkat[] = [
            'id'        => (string)($s['id'] ?? substr($hash, 0, 16)),
            'label'     => (string)($s['browser'] ?? '') . ' · ' . (string)($s['os'] ?? ''),
            'device'    => (string)($s['device'] ?? ''),
            'ip'        => (string)($s['ip'] ?? ''),
            'loginAt'   => (string)($s['loginAt'] ?? ''),
            'lastSeen'  => (string)($s['lastSeen'] ?? ''),
            'expiresAt' => (string)($s['expiresAt'] ?? ''),
            'current'   => $sesi !== null && $hash === $sesi['sidHash'],
        ];
    }
    usort($perangkat, static function ($a, $b) {
        return strcmp((string)$b['lastSeen'], (string)$a['lastSeen']);
    });

    return [
        'loggedIn'       => $sesi !== null,
        'csrf'           => $sesi !== null ? (string)$sesi['csrf'] : '',
        'tokenUpdatedAt' => (string)$state['tokenUpdatedAt'],
        'session'        => $sesi === null ? null : [
            'loginAt'   => $sesi['loginAt'],
            'expiresAt' => $sesi['expiresAt'],
            'ip'        => $sesi['ip'],
            'browser'   => $sesi['browser'],
            'os'        => $sesi['os'],
            'device'    => $sesi['device'],
        ],
        'devices'        => $perangkat,
        'history'        => array_slice($state['history'], 0, 20),
        'https'          => auth_is_https(),
        'serverTime'     => auth_iso($sekarang),
        'ttl'            => AUTH_SESSION_TTL,
    ];
}

/** Buang sesi kedaluwarsa (dipanggil sesekali, bukan tiap request). */
function auth_cleanup(): void
{
    $state = auth_state();
    $sekarang = auth_now();
    $ubah = false;
    foreach ($state['sessions'] as $hash => $s) {
        if (auth_ts($s['expiresAt'] ?? '') <= $sekarang) {
            unset($state['sessions'][$hash]);
            $ubah = true;
        }
    }
    if ($ubah) {
        auth_save($state);
    }
}

/* ------------------------------------------------------------------ header */

function auth_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('X-Robots-Tag: noindex, nofollow');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    if (auth_is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

/** Halaman web: wajib punya sesi. Mengembalikan false bila belum login. */
function auth_page_allowed(): bool
{
    return auth_logged_in();
}
