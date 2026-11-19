<?php
declare(strict_types=1);

/**
 * Halaman masuk (token) — ditampilkan index.php selama belum ada sesi.
 * Halaman ini tidak memuat data keuangan apa pun.
 *
 * $loginMode: 'setup' (token belum pernah dibuat) atau 'login'.
 */

$loginMode = $loginMode ?? 'login';
$appTitle = $loginAppName ?? APP_NAME;
$versi = defined('APP_VERSION') ? APP_VERSION : '';
$https = auth_is_https();
?>
<!DOCTYPE html>
<html lang="id" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Masuk — <?= h($appTitle) ?></title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='24' fill='%2310b981'/><text y='72' x='50' text-anchor='middle' font-size='58' font-family='sans-serif'>%F0%9F%94%92</text></svg>">
<link rel="stylesheet" href="assets/style.css?v=<?= h((string)@filemtime(APP_ROOT . '/assets/style.css')) ?>">
<style>
  .login-wrap { min-height: 100vh; display: grid; place-items: center; padding: 24px 16px; }
  .login-card { width: 100%; max-width: 420px; }
  .login-head { text-align: center; margin-bottom: 18px; }
  .login-head .brand-mark { font-size: 34px; display: block; margin-bottom: 6px; }
  .login-head h1 { font-size: 22px; margin: 0 0 6px; }
  .login-head p { margin: 0; }
  .login-card .field { margin-bottom: 14px; }
  .token-row { display: flex; gap: 8px; }
  .token-row input { flex: 1; }
  .login-msg { margin: 0 0 14px; padding: 10px 12px; border-radius: 10px; font-size: 13px; line-height: 1.45; display: none; }
  .login-msg.err { display: block; background: rgba(244, 63, 94, .12); border: 1px solid rgba(244, 63, 94, .45); color: #fda4af; }
  .login-msg.ok { display: block; background: rgba(16, 185, 129, .12); border: 1px solid rgba(16, 185, 129, .45); color: #6ee7b7; }
  .login-note { margin-top: 16px; font-size: 12px; line-height: 1.6; }
  .login-note ul { margin: 8px 0 0; padding-left: 18px; }
  .login-foot { text-align: center; margin-top: 16px; font-size: 11px; }
  @media (max-width: 380px) { .token-row { flex-direction: column; } }
</style>
</head>
<body>

<div class="login-wrap">
  <div class="card login-card">
    <div class="login-head">
      <span class="brand-mark"><?= $loginMode === 'setup' ? '🔐' : '💠' ?></span>
      <h1><?= $loginMode === 'setup' ? 'Buat token akses' : 'Masuk ke ' . h($appTitle) ?></h1>
      <p class="small dim">
        <?= $loginMode === 'setup'
            ? 'Token ini adalah kunci aplikasi Anda. Buat sekali, lalu simpan di tempat aman.'
            : 'Masukkan token akses untuk membuka catatan keuangan Anda.' ?>
      </p>
    </div>

    <p class="login-msg" id="loginMsg" role="alert"></p>

    <?php if (!$https): ?>
      <p class="login-msg err" style="display:block">
        Koneksi ini belum HTTPS. Token bisa terlihat di jaringan. Sebaiknya pasang sertifikat SSL di hosting Anda.
      </p>
    <?php endif; ?>

    <form id="loginForm" autocomplete="off" novalidate>
      <div class="field">
        <label for="tokenInput"><?= $loginMode === 'setup' ? 'Token baru' : 'Token akses' ?></label>
        <div class="token-row">
          <input id="tokenInput" name="token" type="password" required minlength="12"
                 autocomplete="current-password" spellcheck="false"
                 placeholder="<?= $loginMode === 'setup' ? 'Minimal 12 karakter' : 'Token Anda' ?>">
          <button class="btn btn-sm" type="button" id="btnLihat" aria-label="Tampilkan token">Lihat</button>
        </div>
      </div>

      <?php if ($loginMode === 'setup'): ?>
      <div class="field">
        <label for="tokenUlang">Ulangi token</label>
        <input id="tokenUlang" name="token2" type="password" required minlength="12"
               autocomplete="new-password" spellcheck="false" placeholder="Ketik ulang token">
      </div>
      <?php endif; ?>

      <button class="btn btn-primary btn-block" type="submit" id="btnMasuk">
        <?= $loginMode === 'setup' ? 'Buat token & masuk' : 'Masuk' ?>
      </button>
    </form>

    <div class="login-note dim">
      <strong>Yang perlu Anda tahu</strong>
      <ul>
        <li>Token <strong>tidak berubah</strong> meskipun Anda login berkali-kali.</li>
        <li>Sesi berlaku <strong>24 jam</strong>. Setelah itu masukkan token yang sama lagi.</li>
        <li>Menutup browser tidak mengakhiri sesi selama belum 24 jam.</li>
        <li>Token bisa diganti kapan saja di <strong>Pengaturan → Keamanan</strong>.</li>
        <?php if ($loginMode === 'setup'): ?>
        <li>Simpan token di tempat aman. Bila lupa, buka <code>tools/token.php</code> lewat server.</li>
        <?php endif; ?>
      </ul>
    </div>

    <p class="login-foot dim"><?= h($appTitle) ?><?= $versi !== '' ? ' v' . h($versi) : '' ?> — data tersimpan di server Anda sendiri.</p>
  </div>
</div>

<script>
(function () {
  var form = document.getElementById('loginForm');
  var msg = document.getElementById('loginMsg');
  var input = document.getElementById('tokenInput');
  var ulang = document.getElementById('tokenUlang');
  var tombol = document.getElementById('btnMasuk');
  var lihat = document.getElementById('btnLihat');
  var mode = <?= json_encode($loginMode) ?>;

  function tampil(teks, jenis) {
    msg.textContent = teks;
    msg.className = 'login-msg ' + (jenis || 'err');
  }

  lihat.addEventListener('click', function () {
    var terbuka = input.type === 'text';
    input.type = terbuka ? 'password' : 'text';
    if (ulang) { ulang.type = input.type; }
    lihat.textContent = terbuka ? 'Lihat' : 'Sembunyikan';
    lihat.setAttribute('aria-label', terbuka ? 'Tampilkan token' : 'Sembunyikan token');
  });

  form.addEventListener('submit', async function (ev) {
    ev.preventDefault();
    var token = input.value.trim();
    if (token.length < 12) {
      tampil('Token minimal 12 karakter.');
      input.focus();
      return;
    }
    if (mode === 'setup' && token !== ((ulang && ulang.value) || '').trim()) {
      tampil('Token dan ulangi token tidak sama.');
      ulang.focus();
      return;
    }

    tombol.disabled = true;
    tombol.textContent = 'Memeriksa…';
    msg.className = 'login-msg';

    try {
      var aksi = mode === 'setup' ? 'auth.setup' : 'auth.login';
      var res = await fetch('api.php?action=' + aksi, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' },
        body: JSON.stringify({ token: token, token2: ((ulang && ulang.value) || '').trim() })
      });
      var json = null;
      try { json = await res.json(); } catch (e) { json = null; }
      if (!res.ok || !json || !json.ok) {
        tampil((json && json.error) || 'Gagal masuk (' + res.status + ').');
        tombol.disabled = false;
        tombol.textContent = mode === 'setup' ? 'Buat token & masuk' : 'Masuk';
        return;
      }
      tampil('Berhasil. Membuka aplikasi…', 'ok');
      window.location.replace('index.php');
    } catch (e) {
      tampil('Tidak dapat menghubungi server. Periksa koneksi Anda.');
      tombol.disabled = false;
      tombol.textContent = mode === 'setup' ? 'Buat token & masuk' : 'Masuk';
    }
  });

  input.focus();
})();
</script>
</body>
</html>
