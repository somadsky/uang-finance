<?php
declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/helpers.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/companion.php';

auth_security_headers();
header('Cache-Control: no-store, no-cache, must-revalidate');

if (!auth_is_https()) {
    http_response_code(426);
    exit('Halaman Android Companion wajib dibuka melalui HTTPS.');
}


auth_security_headers();
header('Cache-Control: no-store, no-cache, must-revalidate');

if (!auth_page_allowed()) {
    header('Location: index.php');
    exit;
}

$csrf = json_encode(auth_csrf_token(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$endpoint = json_encode(rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/api.php', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Android Companion · UANG</title>
<style>
:root{color-scheme:dark;--bg:#0b1220;--panel:#111c2e;--line:#26364e;--text:#edf4ff;--muted:#9aabc2;--green:#27c281;--red:#f87171}
*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at top,#172842,var(--bg) 52%);color:var(--text);font:15px/1.6 system-ui,-apple-system,Segoe UI,sans-serif;padding:28px 16px}
main{max-width:820px;margin:auto}.top{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:22px}.brand{font-weight:800;letter-spacing:.04em}.muted{color:var(--muted)}.panel{background:rgba(17,28,46,.94);border:1px solid var(--line);border-radius:18px;padding:22px;margin:14px 0;box-shadow:0 18px 50px #0002}h1{font-size:26px;margin:0 0 4px}h2{font-size:18px;margin:0 0 12px}p{margin:7px 0}.field{display:grid;gap:6px;margin:13px 0}label{font-size:13px;color:var(--muted)}input{width:100%;padding:12px 13px;background:#0b1525;color:var(--text);border:1px solid var(--line);border-radius:10px;font:inherit}button,.link{display:inline-flex;align-items:center;justify-content:center;padding:10px 14px;border-radius:10px;border:1px solid var(--line);background:#1d3553;color:var(--text);font:600 14px system-ui;text-decoration:none;cursor:pointer}button.primary{background:#087f56;border-color:#0b9968}button.danger{background:#48252a;color:#fecaca}button:disabled{opacity:.55;cursor:not-allowed}.row{display:flex;flex-wrap:wrap;align-items:center;gap:10px}.status{margin-top:12px;padding:12px;border-radius:10px;background:#0a1727;white-space:pre-wrap;overflow-wrap:anywhere}.token{font:13px ui-monospace,monospace;color:#bbf7d0;word-break:break-all}.device{display:flex;align-items:center;justify-content:space-between;gap:14px;border-top:1px solid var(--line);padding:13px 0}.device:first-child{border-top:0}.pill{font-size:12px;color:#86efac}.pill.off{color:#fca5a5}.small{font-size:12px}.warning{border-left:3px solid #eab308;padding-left:12px;color:#fde68a}
</style>
</head>
<body>
<main>
  <div class="top"><div class="brand">UANG <span class="muted">/ ANDROID COMPANION</span></div><a class="link" href="index.php">Kembali ke UANG</a></div>
  <h1>Hubungkan notifikasi SeaBank</h1>
  <p class="muted">Daftarkan HP Android, lalu masukkan token perangkat ke aplikasi UANG Companion. Token hanya ditampilkan sekali.</p>

  <section class="panel">
    <h2>Daftarkan perangkat</h2>
    <div class="field"><label for="label">Nama perangkat</label><input id="label" maxlength="80" value="Android saya" autocomplete="off"></div>
    <button id="create" class="primary">Buat token perangkat</button>
    <div id="newToken" class="status" hidden></div>
    <p class="small muted">Salin token dan simpan langsung di aplikasi Companion. Jangan bagikan token ini. Kamu dapat mencabut akses perangkat kapan saja dari halaman ini.</p>
  </section>

  <section class="panel">
    <h2>Perangkat terdaftar</h2>
    <div id="devices" class="muted">Memuat perangkat…</div>
  </section>

  <section class="panel">
    <h2>Catatan privasi dan batasan</h2>
    <p>Companion hanya memproses notifikasi dari aplikasi SeaBank resmi (Android package: <code>id.co.bankbkemobile.digitalbank</code>). Notifikasi aplikasi lain tidak diteruskan.</p>
    <p>Notifikasi yang tidak memuat nominal atau jenis transaksi secara jelas tidak boleh ditebak. Data yang berhasil diparse akan ditandai untuk pemeriksaan kategori di UANG.</p>
    <p class="warning">Gunakan HTTPS. Jangan masukkan PIN, password, atau OTP bank ke aplikasi Companion.</p>
  </section>
</main>
<script>
const API = <?= $endpoint ?>;
const CSRF = <?= $csrf ?>;
const headers = {'Content-Type':'application/json','X-Requested-With':'fetch','X-CSRF-Token':CSRF};
async function callApi(action, body={}) {
  const response = await fetch(API+'?action='+encodeURIComponent(action), {
    method:'POST', headers, credentials:'same-origin', body:JSON.stringify(body)
  });
  const data = await response.json();
  if (!response.ok || !data.ok) throw new Error(data.error || 'Permintaan gagal.');
  return data.data;
}
function esc(value) { return String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
async function loadDevices() {
  const box=document.getElementById('devices');
  try {
    const response=await fetch(API+'?action=companion.devices',{credentials:'same-origin'});
    const data=await response.json();
    if(!response.ok || !data.ok) throw new Error(data.error || 'Tidak dapat memuat daftar.');
    if(!data.data.length){box.textContent='Belum ada perangkat terdaftar.';return;}
    box.innerHTML=data.data.map(d=>'<div class="device"><div><strong>'+esc(d.label)+'</strong><div class="small muted">Didaftarkan: '+esc(d.createdAt)+'<br>Sinkron terakhir: '+esc(d.lastSeen || 'Belum pernah')+'</div><div class="pill '+(d.active?'':'off')+'">'+(d.active?'Aktif':'Dicabut')+'</div></div>'+(d.active?'<button class="danger" data-revoke="'+esc(d.id)+'">Cabut akses</button>':'')+'</div>').join('');
    box.querySelectorAll('[data-revoke]').forEach(btn=>btn.addEventListener('click',async()=>{
      if(!confirm('Cabut akses perangkat ini?')) return;
      btn.disabled=true;
      try { await callApi('companion.revoke',{id:btn.dataset.revoke}); await loadDevices(); }
      catch(e){alert(e.message);btn.disabled=false;}
    }));
  } catch(e) { box.textContent=e.message; }
}
document.getElementById('create').addEventListener('click',async()=>{
 const btn=document.getElementById('create'),out=document.getElementById('newToken');
 btn.disabled=true;out.hidden=false;out.textContent='Membuat token…';
 try {
  const result=await callApi('companion.register',{label:document.getElementById('label').value});
  out.innerHTML='<strong>Token perangkat (tampilkan satu kali):</strong><p class="token">'+esc(result.token)+'</p><button id="copyToken">Salin token</button>';
  document.getElementById('copyToken').addEventListener('click',async()=>{
    await navigator.clipboard.writeText(result.token);
    document.getElementById('copyToken').textContent='Tersalin';
  });
  await loadDevices();
 } catch(e) {out.textContent=e.message;}
 finally {btn.disabled=false;}
});
loadDevices();
</script>
</body>
</html>
