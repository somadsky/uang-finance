<?php
declare(strict_types=1);

/**
 * Cron job: pencatatan bunga harian SeaBank.
 *
 * Contoh pemasangan di VPS (crontab -e):
 *   30 1 * * * /usr/bin/php /home/user/public_html/uang/cron/bunga_seabank.php --quiet
 *
 * Contoh di cPanel (Cron Jobs), kolom Command:
 *   /usr/local/bin/php /home/user/public_html/uang/cron/bunga_seabank.php >/dev/null 2>&1
 *
 * Bila hosting tidak menyediakan cron berbasis CLI, berkas ini juga bisa
 * dipanggil lewat URL dengan token rahasia (lihat Pengaturan → Bunga SeaBank):
 *   https://domain-anda.com/uang/cron/bunga_seabank.php?token=TOKEN
 *
 * Opsi CLI:
 *   --date=YYYY-MM-DD   hitung untuk tanggal tertentu (default: hari ini WIB)
 *   --simulate          hanya simulasi (tidak menulis transaksi/saldo)
 *   --force             jalankan walau otomatisasi sedang nonaktif
 *   --json              keluarkan hasil dalam JSON
 *   --quiet             tidak mencetak apa pun (tetap menulis log)
 */

$isCli = PHP_SAPI === 'cli';
$root  = dirname(__DIR__);

require $root . '/inc/bootstrap.php';
require $root . '/inc/config.php';
require $root . '/inc/helpers.php';
require $root . '/inc/store.php';
require $root . '/inc/finance.php';
require $root . '/inc/interest.php';

/* ------------------------------------------------------------ argumen */

$args = [];
if ($isCli) {
    foreach (array_slice($argv ?? [], 1) as $a) {
        if (str_starts_with($a, '--')) {
            $parts = explode('=', substr($a, 2), 2);
            $args[$parts[0]] = $parts[1] ?? true;
        } else {
            $args[$a] = true;
        }
    }
} else {
    $args = $_GET;
}

$wantsJson = $isCli ? !empty($args['json']) : true;
$quiet     = $isCli && !empty($args['quiet']);
$simulate  = !empty($args['simulate']) || !empty($args['dry-run']);

if ($isCli && !empty($args['help'])) {
    echo "bunga_seabank.php --date=YYYY-MM-DD --simulate --force --json --quiet\n";
    exit(0);
}

/* ------------------------------------------------------------ keamanan */

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');

    $sent = (string)($_GET['token'] ?? ($_SERVER['HTTP_X_CRON_TOKEN'] ?? ''));
    $want = interest_token(false);
    if ($want === '' || $sent === '' || !hash_equals($want, $sent)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Token cron tidak valid.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Metode tidak didukung.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/* ------------------------------------------------------------ eksekusi */

$date = isset($args['date']) && is_string($args['date']) ? trim($args['date']) : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4))) {
    $out = ['ok' => false, 'status' => 'bad-date', 'message' => 'Tanggal tidak valid (YYYY-MM-DD).', 'date' => $date];
    if ($wantsJson) {
        http_response_code(422);
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
    } elseif (!$quiet) {
        fwrite(STDERR, $out['message'] . "\n");
    }
    exit(1);
}

try {
    $trigger = $isCli ? ($simulate ? 'cron-simulasi' : 'cron') : 'cron-http';
    $result = $simulate
        ? interest_simulate($date, $trigger)
        : interest_run($date, $trigger, !empty($args['force']));
} catch (Throwable $e) {
    $result = ['status' => 'error', 'message' => 'Gagal: ' . $e->getMessage(), 'date' => $date];
    error_log('bunga_seabank: ' . $e->getMessage());
}

$okStatus = in_array($result['status'] ?? '', ['created', 'simulated', 'already', 'disabled', 'zero-balance', 'too-small'], true);
$result['ok'] = $okStatus;
$result['date'] = $result['date'] ?? $date;
$result['time'] = date('c');

if ($wantsJson) {
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} elseif (!$quiet) {
    $fmt = static fn ($v) => number_format((float)$v, 2, ',', '.');
    echo '[' . date('Y-m-d H:i:s') . '] ' . strtoupper((string)($result['mode'] ?? '-')) . ' ' . ($result['status'] ?? '-') . "\n";
    echo '  tanggal    : ' . $result['date'] . "\n";
    if (isset($result['accountName'])) {
        echo '  akun       : ' . $result['accountName'] . "\n";
    }
    if (isset($result['balance'])) {
        echo '  saldo awal : Rp ' . $fmt($result['balance']) . "\n";
        echo '  suku bunga : ' . $fmt($result['rate']) . '% / tahun (' . ($result['rateTier'] === 'atLeast' ? 'saldo >= ambang' : 'saldo di bawah ambang') . ")\n";
        echo '  bunga kotor: Rp ' . $fmt($result['gross']) . "\n";
        echo '  pajak ' . $fmt($result['taxRate']) . '% : Rp ' . $fmt($result['tax']) . "\n";
        echo '  bunga bersih: Rp ' . $fmt($result['net']) . "\n";
    }
    echo '  hasil      : ' . ($result['message'] ?? '') . "\n";
    if (!empty($result['txId'])) {
        echo '  transaksi  : ' . $result['txId'] . "\n";
    }
    if (!empty($result['persisted'])) {
        echo "  tersimpan  : ya\n";
    }
}

exit($okStatus ? 0 : 1);
