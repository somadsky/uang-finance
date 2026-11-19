<?php
/**
 * Halaman galat yang bisa dibaca manusia (bukan "HTTP 500" kosong).
 * Dipakai index.php bila terjadi kegagalan saat memuat data.
 *
 * Variabel yang diharapkan: $e (Throwable).
 */

if (!isset($e) || !($e instanceof Throwable)) {
    // Dibuka langsung, bukan lewat index.php: jangan tampilkan apa pun.
    http_response_code(403);
    header('X-Robots-Tag: noindex, nofollow');
    echo 'Berkas internal aplikasi.';
    return;
}

$debug = false;
if (isset($_GET['debug']) && (string)$_GET['debug'] === '1') {
    $debug = true;
}

if (!headers_sent()) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
}

$pesan = $e->getMessage();
$jenis = get_class($e);

/* Petunjuk yang paling mungkin sesuai jenis galat. */
$saran = [];
if (stripos($pesan, 'tidak bisa dibaca') !== false) {
    $saran[] = 'Berkas <code>data/db.php</code> rusak atau terpotong. Aplikasi sudah menyimpan salinannya di folder <code>backup/</code> supaya tidak hilang.';
    $saran[] = 'Pulihkan dari salinan cadangan terakhir (berkas <code>.json</code> hasil menu Pengaturan → Cadangan) atau minta hosting memulihkan berkas dari backup harian.';
} elseif (stripos($pesan, 'Permission denied') !== false || stripos($pesan, 'failed to open stream') !== false) {
    $saran[] = 'Folder <code>data/</code> (dan <code>backup/</code>) harus bisa ditulis. Setel izinnya menjadi <strong>755</strong> — atau <strong>775/777</strong> bila hosting memakai PHP sebagai pemilik berbeda.';
    $saran[] = 'Buka cPanel → File Manager → klik kanan folder <code>data</code> → <em>Change Permissions</em>.';
} elseif (stripos($pesan, 'No such file') !== false) {
    $saran[] = 'Ada berkas yang belum terunggah lengkap. Pastikan seluruh isi ZIP diekstrak ke dalam <code>public_html</code> (termasuk folder <code>inc/</code> dan <code>assets/</code>).';
} else {
    $saran[] = 'Buka <code>diagnosa.php</code> untuk memeriksa versi PHP dan izin folder.';
    $saran[] = 'Bila baru mengunggah, pastikan seluruh isi ZIP sudah diekstrak (bukan hanya berkas ZIP-nya).';
}

$v = PHP_VERSION;
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Aplikasi belum bisa dibuka</title>
<style>
  body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background: #0f172a; color: #e6edf7;
         margin: 0; padding: 32px 18px; line-height: 1.6; }
  main { max-width: 660px; margin: 0 auto; background: #16223a; border: 1px solid #253552; border-radius: 16px; padding: 24px 26px; }
  h1 { font-size: 1.2rem; margin: 0 0 6px; }
  h2 { font-size: .95rem; margin: 22px 0 8px; color: #a9bad4; text-transform: uppercase; letter-spacing: .04em; }
  code { background: #0b1526; padding: 2px 6px; border-radius: 6px; font-size: .88em; }
  ol, ul { padding-left: 20px; margin: 6px 0; }
  li { margin: 6px 0; }
  .box { background: #0b1526; border: 1px solid #253552; border-radius: 10px; padding: 12px 14px; overflow-x: auto; }
  .muted { color: #93a4bf; font-size: .88rem; }
  a { color: #6ee7b7; }
</style>
</head>
<body>
<main>
  <h1>Aplikasi belum bisa dibuka</h1>
  <p class="muted">Data Anda tidak hilang. Ini pesan bantuan, bukan tampilan aplikasi.</p>

  <h2>Kemungkinan penyebab &amp; solusi</h2>
  <ol>
    <?php foreach ($saran as $s): ?>
      <li><?= $s ?></li>
    <?php endforeach; ?>
  </ol>

  <h2>Rincian teknis</h2>
  <div class="box"><code><?= htmlspecialchars($jenis, ENT_QUOTES, 'UTF-8') ?></code><br>
    <?= htmlspecialchars($pesan, ENT_QUOTES, 'UTF-8') ?></div>
  <p class="muted">PHP <?= htmlspecialchars($v, ENT_QUOTES, 'UTF-8') ?><?php if ($debug): ?> · baris <?= (int)$e->getLine() ?> · <?= htmlspecialchars($e->getFile(), ENT_QUOTES, 'UTF-8') ?><?php endif; ?></p>

  <?php if (!$debug): ?>
    <p class="muted">Untuk detail berkas dan baris, tambahkan <code>?debug=1</code> pada alamat halaman ini.</p>
  <?php endif; ?>

  <p class="muted">Alat bantu: <a href="diagnosa.php">diagnosa.php</a> — memeriksa versi PHP, ekstensi, dan izin folder.</p>
</main>
</body>
</html>
