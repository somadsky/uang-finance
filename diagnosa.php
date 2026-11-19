<?php
/**
 * diagnosa.php — pemeriksa mandiri untuk hosting.
 *
 * Buka di browser: https://domain-anda.com/diagnosa.php
 * Hasilnya menunjukkan versi PHP, izin folder, dan penyebab umum HTTP 500.
 *
 * Berkas ini tidak menampilkan isi data keuangan Anda.
 * Sebaiknya HAPUS berkas ini setelah aplikasi berjalan normal.
 */

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

/* Halaman ini tidak menampilkan data keuangan, tetapi tetap dijaga:
   bila token sudah dibuat, hanya pemilik yang sudah masuk boleh membukanya. */
define('UANG_ROOT', __DIR__);
require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/helpers.php';
require __DIR__ . '/inc/auth.php';
if (auth_token_exists() && !auth_logged_in()) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><title>Akses ditolak</title>'
        . '<body style="font-family:system-ui;background:#0b1220;color:#e2e8f0;padding:40px">'
        . '<h1 style="font-size:18px">Akses ditolak</h1>'
        . '<p>Masukkan token lebih dulu di <a style="color:#38bdf8" href="index.php">halaman utama</a>.</p>'
        . '</body>';
    exit;
}

$cek = [];

function uang_cek($nama, $ok, $detail)
{
    $GLOBALS['cek'][] = ['nama' => $nama, 'ok' => (bool)$ok, 'detail' => $detail];
}

/* ---------- 1. PHP & server ---------- */
$ver = PHP_VERSION;
$verOk = version_compare($ver, '7.4.0', '>=');
uang_cek(
    'Versi PHP',
    $verOk,
    $verOk
        ? $ver . ' — memenuhi syarat (minimal 7.4).'
        : $ver . ' — TERLALU LAMA. Naikkan ke PHP 8.1/8.2/8.3 lewat cPanel → MultiPHP Manager.'
);

uang_cek('Server API', true, PHP_SAPI . (isset($_SERVER['SERVER_SOFTWARE']) ? ' · ' . $_SERVER['SERVER_SOFTWARE'] : ''));

foreach (['json', 'pcre', 'date', 'fileinfo'] as $ext) {
    uang_cek('Ekstensi ' . $ext, extension_loaded($ext), extension_loaded($ext) ? 'tersedia' : 'TIDAK ADA — hubungi hosting');
}
uang_cek(
    'Ekstensi mbstring',
    true,
    extension_loaded('mbstring') ? 'tersedia' : 'tidak ada (opsional, aplikasi tetap jalan)'
);

/* ---------- 2. Berkas & folder ---------- */
$root = __DIR__;
$wajib = [
    'index.php', 'api.php', 'inc/config.php', 'inc/helpers.php', 'inc/store.php',
    'inc/finance.php', 'inc/demo.php', 'inc/bootstrap.php', 'inc/galat.php',
    'assets/style.css', 'assets/app.js', 'data/db.php',
];
$hilang = [];
foreach ($wajib as $f) {
    if (!is_file($root . '/' . $f)) {
        $hilang[] = $f;
    }
}
uang_cek(
    'Kelengkapan berkas',
    count($hilang) === 0,
    count($hilang) === 0 ? count($wajib) . ' berkas inti ditemukan' : 'BELUM ADA: ' . implode(', ', $hilang)
);

foreach (['data', 'backup'] as $dir) {
    $p = $root . '/' . $dir;
    $ada = is_dir($p);
    $tulis = $ada && is_writable($p);
    uang_cek(
        'Folder ' . $dir . '/ bisa ditulis',
        $tulis,
        !$ada ? 'folder tidak ada — ekstrak ulang ZIP' : ($tulis ? 'OK (izin ' . substr(sprintf('%o', fileperms($p)), -4) . ')' : 'TIDAK BISA DITULIS — setel izin 755, atau 775 bila perlu')
    );
}

$dbFile = $root . '/data/db.php';
if (is_file($dbFile)) {
    $bisaTulis = is_writable($dbFile);
    uang_cek(
        'Berkas data/db.php bisa ditulis',
        $bisaTulis,
        $bisaTulis ? 'OK (izin ' . substr(sprintf('%o', fileperms($dbFile)), -4) . ')' : 'TIDAK BISA DITULIS — setel izin 644 atau 664'
    );
} else {
    uang_cek('Berkas data/db.php', false, 'tidak ada — akan dibuat otomatis saat aplikasi pertama dibuka (pastikan folder data/ bisa ditulis)');
}

/* Uji tulis nyata. */
$uji = $root . '/data/.uji-tulis-' . substr(md5((string)mt_rand()), 0, 8) . '.tmp';
$tulisOk = @file_put_contents($uji, 'ok') !== false;
if ($tulisOk) {
    @unlink($uji);
}
uang_cek('Uji tulis di folder data/', $tulisOk, $tulisOk ? 'berhasil membuat dan menghapus berkas uji' : 'GAGAL — penyebab paling umum HTTP 500 saat menyimpan transaksi');

/* ---------- 2b. Fitur scan struk ---------- */
$ocrWajib = [
    'assets/ocr/tesseract.min.js' => 'pustaka OCR',
    'assets/ocr/worker.min.js' => 'worker OCR',
    'assets/ocr/lang/ind.traineddata' => 'data bahasa Indonesia',
    'assets/ocr/tesseract-core-simd-lstm.wasm.js' => 'mesin OCR (SIMD)',
];
$ocrHilang = [];
foreach ($ocrWajib as $rel => $label) {
    if (!is_file($root . '/' . $rel)) {
        $ocrHilang[] = $label;
    }
}
uang_cek(
    'Berkas scan struk',
    count($ocrHilang) === 0,
    count($ocrHilang) === 0
        ? 'lengkap — pemindai struk siap dipakai'
        : 'BELUM ADA: ' . implode(', ', $ocrHilang) . '. Unggah folder assets/ocr secara utuh.'
);
$strukDir = $root . '/data/struk';
if (!is_dir($strukDir)) {
    @mkdir($strukDir, 0775, true);
}
uang_cek(
    'Folder data/struk/ untuk foto struk',
    is_dir($strukDir) && is_writable($strukDir),
    is_dir($strukDir) && is_writable($strukDir)
        ? 'OK — foto struk bisa disimpan'
        : 'TIDAK BISA DITULIS — setel izin folder data/ menjadi 755 atau 775'
);

/* ---------- 2c. Otomatisasi bunga & emas digital ---------- */
foreach ([
    'inc/interest.php' => 'modul bunga otomatis',
    'inc/gold.php' => 'modul emas Lakuemas',
    'cron/bunga_seabank.php' => 'skrip cron bunga',
] as $rel => $label) {
    $ada = is_file($root . '/' . $rel);
    uang_cek('Berkas ' . $label, $ada, $ada ? 'ada' : 'TIDAK ADA — unggah ulang folder inc/ dan cron/');
}
foreach (['data/logs' => 'log eksekusi cron', 'data/cache' => 'cache harga emas'] as $dir => $label) {
    $p = $root . '/' . $dir;
    if (!is_dir($p)) {
        @mkdir($p, 0775, true);
    }
    $siap = is_dir($p) && is_writable($p);
    uang_cek('Folder ' . $dir . '/ untuk ' . $label, $siap, $siap ? 'OK' : 'TIDAK BISA DITULIS — setel izin 755 atau 775');
}
$phpCli = PHP_BINARY !== '' ? PHP_BINARY : 'tidak terdeteksi';
uang_cek('PHP CLI untuk cron job', PHP_BINARY !== '', 'jalur PHP: ' . $phpCli);
$cronToken = $root . '/data/cron-token.php';
uang_cek(
    'Token cron (bila memakai URL)',
    !is_file($cronToken) || is_readable($cronToken),
    is_file($cronToken) ? 'sudah dibuat' : 'belum dibuat — otomatis dibuat saat kartu Bunga di Pengaturan dibuka'
);

/* ---------- 3. Berkas pelindung ---------- */
foreach (['.htaccess', 'data/.htaccess', 'inc/.htaccess', 'backup/.htaccess'] as $f) {
    uang_cek('Pelindung ' . $f, is_file($root . '/' . $f), is_file($root . '/' . $f) ? 'ada' : 'TIDAK ADA — unggah ulang dengan opsi Show Hidden Files');
}

/* ---------- 4. Uji memuat aplikasi ---------- */
$muatOk = true;
$muatPesan = 'aplikasi berhasil dimuat dan data terbaca';
try {
    require $root . '/inc/bootstrap.php';
    require $root . '/inc/config.php';
    require $root . '/inc/helpers.php';
    require $root . '/inc/store.php';
    require $root . '/inc/finance.php';
    require $root . '/inc/interest.php';
    require $root . '/inc/gold.php';
    $db = db_read();
    if (!isset($db['settings'], $db['accounts'])) {
        $muatOk = false;
        $muatPesan = 'data terbaca tetapi strukturnya tidak dikenal — pulihkan dari cadangan';
    } else {
        $muatPesan = 'data terbaca: ' . count($db['accounts']) . ' dompet, ' . count($db['transactions']) . ' transaksi';
    }
} catch (Throwable $e) {
    $muatOk = false;
    $muatPesan = get_class($e) . ': ' . $e->getMessage();
}
uang_cek('Memuat aplikasi & membaca data', $muatOk, $muatPesan);

/* ---------- 5. Batas PHP ---------- */
$batas = [
    'upload_max_filesize' => ini_get('upload_max_filesize'),
    'post_max_size' => ini_get('post_max_size'),
    'max_execution_time' => ini_get('max_execution_time'),
    'memory_limit' => ini_get('memory_limit'),
    'display_errors' => ini_get('display_errors') ? 'On' : 'Off',
];

/* ---------- 6. Kesimpulan ---------- */
$gagal = array_values(array_filter($cek, static function ($c) {
    return !$c['ok'];
}));
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Diagnosa — Uang</title>
<style>
  body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background: #0f172a; color: #e6edf7; margin: 0; padding: 30px 16px; line-height: 1.55; }
  main { max-width: 780px; margin: 0 auto; }
  h1 { font-size: 1.3rem; margin: 0 0 4px; }
  h2 { font-size: .82rem; letter-spacing: .05em; text-transform: uppercase; color: #9db0cb; margin: 26px 0 8px; }
  .card { background: #16223a; border: 1px solid #253552; border-radius: 14px; padding: 18px 20px; }
  ul { list-style: none; padding: 0; margin: 0; }
  li { display: flex; gap: 10px; padding: 7px 0; border-bottom: 1px solid #1e2c45; font-size: .92rem; }
  li:last-child { border-bottom: 0; }
  .badge { flex: 0 0 auto; font-weight: 700; }
  .ok { color: #34d399; }
  .no { color: #f87171; }
  .detail { color: #a9bad4; }
  code { background: #0b1526; padding: 1px 5px; border-radius: 5px; font-size: .86em; }
  .muted { color: #8fa3bf; font-size: .85rem; }
  .kesimpulan-ok { border-color: #14532d; background: #10241c; }
  .kesimpulan-no { border-color: #7f1d1d; background: #2a1416; }
  table { width: 100%; border-collapse: collapse; font-size: .88rem; }
  th, td { text-align: left; padding: 6px 0; border-bottom: 1px solid #1e2c45; }
  th { color: #9db0cb; font-weight: 600; }
  @media (max-width: 520px) { li { flex-direction: column; gap: 2px; } }
</style>
</head>
<body>
<main>
  <h1>Diagnosa aplikasi Uang</h1>
  <p class="muted">Halaman ini memeriksa penyebab umum HTTP 500 di hosting. Tidak menampilkan isi data keuangan.</p>

  <h2>Kesimpulan</h2>
  <div class="card <?= count($gagal) === 0 ? 'kesimpulan-ok' : 'kesimpulan-no' ?>">
    <?php if (count($gagal) === 0): ?>
      <strong class="ok">Semua pemeriksaan lolos.</strong>
      <p class="muted" style="margin:6px 0 0">Bila halaman utama masih 500, kemungkinan penyebabnya berkas <code>.htaccess</code> yang tidak didukung hosting.
      Coba ganti nama sementara <code>.htaccess</code> menjadi <code>.htaccess-off</code> lalu buka aplikasi. Bila jadi normal, kirimkan isi berkas itu ke pengembang.</p>
    <?php else: ?>
      <strong class="no"><?= count($gagal) ?> masalah ditemukan</strong>
      <p class="muted" style="margin:6px 0 0">Perbaiki baris bertanda merah di bawah, lalu muat ulang halaman ini.</p>
    <?php endif; ?>
  </div>

  <h2>Pemeriksaan</h2>
  <div class="card">
    <ul>
      <?php foreach ($cek as $c): ?>
        <li>
          <span class="badge <?= $c['ok'] ? 'ok' : 'no' ?>"><?= $c['ok'] ? '✔' : '✖' ?></span>
          <span><strong><?= htmlspecialchars($c['nama'], ENT_QUOTES, 'UTF-8') ?></strong><br>
            <span class="detail"><?= htmlspecialchars($c['detail'], ENT_QUOTES, 'UTF-8') ?></span></span>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>

  <h2>Batas PHP</h2>
  <div class="card">
    <table>
      <?php foreach ($batas as $k => $v): ?>
        <tr><th><?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?></th><td><?= htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') ?></td></tr>
      <?php endforeach; ?>
    </table>
    <p class="muted" style="margin:10px 0 0">Bila <code>post_max_size</code> kecil, pemulihan cadangan berukuran besar bisa gagal. Ubah lewat cPanel → MultiPHP INI Editor.</p>
  </div>

  <h2>Setelah selesai</h2>
  <p class="muted">Hapus berkas <code>diagnosa.php</code> dari hosting supaya tidak meninggalkan halaman informasi terbuka. Buka <a href="index.php" style="color:#6ee7b7">aplikasi</a>.</p>
</main>
</body>
</html>
