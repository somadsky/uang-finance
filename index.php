<?php
declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';

try {
    require __DIR__ . '/inc/config.php';
    require __DIR__ . '/inc/helpers.php';
    require __DIR__ . '/inc/store.php';
    require __DIR__ . '/inc/finance.php';
    require __DIR__ . '/inc/auth.php';

    auth_security_headers();

    // Seluruh halaman aplikasi dijaga token. Tanpa sesi, hanya halaman masuk
    // yang ditampilkan — data keuangan tidak dibaca sama sekali.
    if (!auth_page_allowed()) {
        $loginMode = auth_token_exists() ? 'login' : 'setup';
        $loginAppName = APP_NAME;
        require __DIR__ . '/inc/login.php';
        exit;
    }

    $sesiAktif = auth_session();

    $db = db_read();
    $settings = $db['settings'];
    $writable = is_writable(DATA_DIR);
    $bootstrap = json_encode(
        $db,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
    $appName = $settings['appName'] !== '' ? $settings['appName'] : APP_NAME;

    // Versi aset dari waktu ubah berkas: browser selalu memuat CSS/JS terbaru
    // setelah aplikasi diperbarui, tanpa perlu menaikkan APP_VERSION manual.
    $assetVer = static function (string $relative): string {
        $full = APP_ROOT . '/' . ltrim($relative, '/');
        $mtime = @filemtime($full);
        return $mtime ? (string)$mtime : APP_VERSION;
    };
} catch (Throwable $e) {
    require __DIR__ . '/inc/galat.php';
    exit;
}
?>
<!DOCTYPE html>
<html lang="id" data-theme="<?= h($settings['theme']) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= h($appName) ?> — Keuangan Pribadi</title>
<meta name="description" content="Pencatat keuangan pribadi: pemasukan, pengeluaran, anggaran, target tabungan, utang-piutang. Tanpa login, data tersimpan di server Anda sendiri.">
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='24' fill='%2310b981'/><text y='72' x='50' text-anchor='middle' font-size='58' font-family='sans-serif'>%F0%9F%92%B0</text></svg>">
<link rel="stylesheet" href="assets/style.css?v=<?= h($assetVer('assets/style.css')) ?>">
<link rel="stylesheet" href="assets/scan.css?v=<?= h($assetVer('assets/scan.css')) ?>">
<link rel="stylesheet" href="assets/fitur.css?v=<?= h($assetVer('assets/fitur.css')) ?>">
</head>
<body>

<div class="app-shell">
  <header class="topbar">
    <div class="topbar-inner">
      <div class="brand">
        <span class="brand-mark">💠</span>
        <span class="brand-text">
          <strong id="brandName"><?= h($appName) ?></strong>
          <small id="brandOwner"><?= h($settings['ownerName'] !== '' ? $settings['ownerName'] : 'Keuangan pribadi') ?></small>
        </span>
      </div>

      <nav class="nav" id="mainNav" aria-label="Navigasi utama">
        <button class="nav-item" data-view="dashboard">Ringkasan</button>
        <button class="nav-item" data-view="transactions">Transaksi</button>
        <button class="nav-item" data-view="budgets">Anggaran</button>
        <button class="nav-item" data-view="goals">Target</button>
        <button class="nav-item" data-view="debts">Utang/Piutang</button>
        <button class="nav-item" data-view="reports">Laporan</button>
        <button class="nav-item" data-view="gold">Emas</button>
        <button class="nav-item" data-view="settings">Pengaturan</button>
      </nav>

      <div class="topbar-actions">
        <button class="icon-btn" id="btnScan" title="Scan struk belanja" aria-label="Scan struk belanja">📷</button>
        <button class="icon-btn" id="btnTheme" title="Ganti tema terang/gelap" aria-label="Ganti tema">🌙</button>
        <button class="icon-btn" id="btnLogout" title="Keluar (token tetap sama)" aria-label="Keluar">⏻</button>
        <button class="btn btn-primary" id="btnQuickAdd">+ Catat</button>
      </div>
    </div>
  </header>

  <?php if (!$writable): ?>
  <div class="banner banner-warn">
    Folder <code>data/</code> tidak dapat ditulis. Jalankan <code>chmod 775 data</code> (dan <code>backup/data</code>) di server agar data bisa disimpan.
  </div>
  <?php endif; ?>

  <div id="demoBanner" class="banner banner-demo" hidden>
    🧪 <strong>DATA MOCK/SINTETIS (TESTING)</strong> — angka di halaman ini hanya contoh. ⚠️ Data SINTETIS/MOCK - testing only.
    <button class="link-btn" id="btnWipeDemo">Hapus data contoh</button>
  </div>

  <main id="view" class="view" aria-live="polite">
    <div class="loading">Memuat data…</div>
  </main>

  <footer class="app-footer">
    <span><?= h($appName) ?> v<?= h(APP_VERSION) ?> — data pribadi Anda tersimpan di berkas JSON pada server ini.</span>
  </footer>
</div>

<div class="modal-root" id="modalRoot" hidden>
  <div class="modal-backdrop" data-close></div>
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
    <div class="modal-head">
      <h2 id="modalTitle">Judul</h2>
      <button class="icon-btn" data-close aria-label="Tutup">✕</button>
    </div>
    <div class="modal-body" id="modalBody"></div>
    <div class="modal-foot" id="modalFoot"></div>
  </div>
</div>

<div class="toast-root" id="toastRoot" aria-live="polite"></div>

<script>
window.__DB__ = <?= $bootstrap ?>;
window.__APP__ = { version: <?= json_encode(APP_VERSION) ?>, api: 'api.php' };
window.__AUTH__ = <?= json_encode([
    'csrf'      => auth_csrf_token(),
    'loginAt'   => $sesiAktif['loginAt'] ?? '',
    'expiresAt' => $sesiAktif['expiresAt'] ?? '',
    'device'    => ($sesiAktif['browser'] ?? '') . ' · ' . ($sesiAktif['os'] ?? ''),
    'ip'        => $sesiAktif['ip'] ?? '',
    'https'     => auth_is_https(),
], JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="assets/app.js?v=<?= h($assetVer('assets/app.js')) ?>"></script>
</body>
</html>
