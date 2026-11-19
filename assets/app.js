/* ==========================================================================
   Uang — keuangan pribadi (vanilla JS, tanpa dependensi)
   Semua perhitungan dilakukan di browser dari snapshot data server.
   Rumus saldo harus sepadan dengan inc/finance.php.
   ========================================================================== */
(() => {
  'use strict';

  const API = (window.__APP__ && window.__APP__.api) || 'api.php';
  let DB = window.__DB__ || { settings: {}, accounts: [], categories: [], transactions: [], budgets: [], goals: [], goalEntries: [], debts: [] };

  const state = {
    view: 'dashboard',
    month: thisMonth(),
    tx: { q: '', type: 'all', cat: 'all', acc: 'all', month: thisMonth(), from: '', to: '', limit: 40 },
    reportMonth: thisMonth(),
    reportMode: 'monthly',
    reportAnchor: todayISO(),
    gold: { price: null, loading: false, tried: false, dirty: false, error: '' },
    interest: { info: null, loading: false, busy: false, result: null, error: '' },
    auth: { info: null, loading: false, error: '' },
    busy: false
  };

  /* ---------------------------------------------------------------- utils */

  const MONTHS = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
  const MONTHS_SHORT = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
  const DAYS = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];

  function pad(n) { return String(n).padStart(2, '0'); }
  function thisMonth() { const d = new Date(); return d.getFullYear() + '-' + pad(d.getMonth() + 1); }
  function todayISO() { const d = new Date(); return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function ymAdd(ym, delta) {
    let [y, m] = ym.split('-').map(Number);
    m += delta;
    while (m < 1) { m += 12; y -= 1; }
    while (m > 12) { m -= 12; y += 1; }
    return y + '-' + pad(m);
  }
  function monthName(ym, short) {
    if (!ym || !/^\d{4}-\d{2}$/.test(ym)) return '-';
    const [y, m] = ym.split('-').map(Number);
    return (short ? MONTHS_SHORT[m - 1] : MONTHS[m - 1]) + ' ' + y;
  }
  function dateLabel(iso, withDay) {
    if (!iso || !/^\d{4}-\d{2}-\d{2}$/.test(iso)) return iso || '-';
    const [y, m, d] = iso.split('-').map(Number);
    const dt = new Date(y, m - 1, d);
    const base = d + ' ' + MONTHS_SHORT[m - 1] + ' ' + y;
    return withDay ? DAYS[dt.getDay()] + ', ' + base : base;
  }
  function daysInMonth(ym) {
    const [y, m] = ym.split('-').map(Number);
    return new Date(y, m, 0).getDate();
  }
  function num(v) { const n = Number(v); return Number.isFinite(n) ? n : 0; }
  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  function symbol() { return (DB.settings && DB.settings.currencySymbol) || 'Rp'; }
  function money(v) {
    const n = Math.round(num(v));
    const sign = n < 0 ? '-' : '';
    return sign + symbol() + ' ' + Math.abs(n).toLocaleString('id-ID');
  }
  function moneySigned(v) {
    const n = num(v);
    return (n > 0 ? '+' : n < 0 ? '-' : '') + money(Math.abs(n)).replace('-', '');
  }
  function compact(v) {
    const n = Math.abs(num(v));
    if (n >= 1e9) return (num(v) / 1e9).toLocaleString('id-ID', { maximumFractionDigits: 1 }) + ' M';
    if (n >= 1e6) return (num(v) / 1e6).toLocaleString('id-ID', { maximumFractionDigits: 1 }) + ' jt';
    if (n >= 1e3) return (num(v) / 1e3).toLocaleString('id-ID', { maximumFractionDigits: 0 }) + ' rb';
    return String(Math.round(num(v)));
  }
  function parseMoney(v) { const s = String(v == null ? '' : v).replace(/[^\d]/g, ''); return s ? parseInt(s, 10) : 0; }
  function clamp(v, a, b) { return Math.min(b, Math.max(a, v)); }
  function pct(part, whole) { return whole > 0 ? (part / whole) * 100 : 0; }

  /* ------------------------------------------------------------- selectors */

  const accById = (id) => DB.accounts.find((a) => a.id === id) || null;
  const catById = (id) => DB.categories.find((c) => c.id === id) || null;
  function accounts(includeArchived) { return DB.accounts.filter((a) => includeArchived || !a.archived); }
  function categories(type, includeArchived) {
    return DB.categories.filter((c) => (includeArchived || !c.archived) && (!type || c.type === type));
  }
  function txList() {
    return DB.transactions.slice().sort((a, b) => (b.date === a.date ? String(b.createdAt || '').localeCompare(String(a.createdAt || '')) : b.date.localeCompare(a.date)));
  }
  function balanceMap() {
    const bal = {};
    DB.accounts.forEach((a) => { bal[a.id] = num(a.initial); });
    DB.transactions.forEach((t) => {
      const amt = num(t.amount);
      if (t.type === 'income' && bal[t.accountId] !== undefined) bal[t.accountId] += amt;
      else if (t.type === 'expense' && bal[t.accountId] !== undefined) bal[t.accountId] -= amt;
      else if (t.type === 'transfer') {
        if (bal[t.accountId] !== undefined) bal[t.accountId] -= amt;
        if (bal[t.toAccountId] !== undefined) bal[t.toAccountId] += amt;
      }
    });
    return bal;
  }
  function totalBalance() { return Object.values(balanceMap()).reduce((s, v) => s + v, 0); }
  function monthTx(ym) { return DB.transactions.filter((t) => String(t.date || '').slice(0, 7) === ym); }
  function monthSummary(ym) {
    let income = 0, expense = 0, count = 0;
    monthTx(ym).forEach((t) => {
      const a = num(t.amount);
      if (t.type === 'income') { income += a; count++; }
      else if (t.type === 'expense') { expense += a; count++; }
    });
    return { income, expense, net: income - expense, count };
  }
  function series(months, endYm) {
    const end = endYm || thisMonth();
    const out = [];
    for (let i = months - 1; i >= 0; i--) {
      const ym = ymAdd(end, -i);
      out.push(Object.assign({ month: ym }, monthSummary(ym)));
    }
    return out;
  }
  function catTotals(ym) {
    const map = new Map();
    monthTx(ym).forEach((t) => {
      if (t.type !== 'expense') return;
      const c = catById(t.categoryId);
      const key = t.categoryId || 'none';
      if (!map.has(key)) map.set(key, { id: key, name: c ? c.name : 'Tanpa kategori', color: c ? c.color : '#94a3b8', icon: c ? c.icon : '📦', total: 0 });
      map.get(key).total += num(t.amount);
    });
    return [...map.values()].sort((a, b) => b.total - a.total);
  }
  /* -------- rentang tanggal (dipakai filter transaksi & laporan) -------- */

  function isoOf(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function parseISO(iso) { const [y, m, d] = String(iso).split('-').map(Number); return new Date(y, m - 1, d); }
  function dateAddISO(iso, days) { const d = parseISO(iso); d.setDate(d.getDate() + days); return isoOf(d); }
  function weekStartISO(iso) { const d = parseISO(iso); d.setDate(d.getDate() - ((d.getDay() + 6) % 7)); return isoOf(d); }
  function shortDate(iso) { const d = parseISO(iso); return d.getDate() + ' ' + MONTHS_SHORT[d.getMonth()]; }
  function rangeTx(from, to) {
    return DB.transactions.filter((t) => { const d = String(t.date || ''); return (!from || d >= from) && (!to || d <= to); });
  }
  function rangeSummary(from, to) {
    let income = 0, expense = 0, transfer = 0, count = 0;
    rangeTx(from, to).forEach((t) => {
      const a = num(t.amount);
      count++;
      if (t.type === 'income') income += a;
      else if (t.type === 'expense') expense += a;
      else transfer += a;
    });
    return { income, expense, net: income - expense, transfer, count };
  }
  function catTotalsRange(from, to) {
    const map = new Map();
    rangeTx(from, to).forEach((t) => {
      if (t.type !== 'expense') return;
      const c = catById(t.categoryId);
      const key = t.categoryId || 'none';
      if (!map.has(key)) map.set(key, { id: key, name: c ? c.name : 'Tanpa kategori', color: c ? c.color : '#94a3b8', icon: c ? c.icon : '📦', total: 0 });
      map.get(key).total += num(t.amount);
    });
    return [...map.values()].sort((a, b) => b.total - a.total);
  }

  /* -------- periode laporan: harian, mingguan, bulanan, tahunan -------- */

  const REPORT_MODES = [['daily', 'Harian'], ['weekly', 'Mingguan'], ['monthly', 'Bulanan'], ['yearly', 'Tahunan']];

  function reportRange(mode, anchor) {
    if (mode === 'daily') {
      return { from: anchor, to: anchor, label: dateLabel(anchor) };
    }
    if (mode === 'weekly') {
      const from = weekStartISO(anchor), to = dateAddISO(from, 6);
      return { from, to, label: shortDate(from) + ' – ' + shortDate(to) + ' ' + parseISO(to).getFullYear() };
    }
    if (mode === 'yearly') {
      const y = String(anchor).slice(0, 4);
      return { from: y + '-01-01', to: y + '-12-31', label: 'Tahun ' + y };
    }
    const ym = String(anchor).slice(0, 7);
    return { from: ym + '-01', to: ym + '-' + pad(daysInMonth(ym)), label: monthName(ym) };
  }

  function reportShift(anchor, mode, delta) {
    if (mode === 'daily') return dateAddISO(anchor, delta);
    if (mode === 'weekly') return dateAddISO(anchor, delta * 7);
    if (mode === 'yearly') return (parseISO(anchor).getFullYear() + delta) + '-01-01';
    return ymAdd(String(anchor).slice(0, 7), delta) + '-01';
  }

  /** Sub-periode untuk grafik & tabel rincian pada laporan. */
  function reportSubPeriods(mode, range) {
    const out = [];
    if (mode === 'daily') {
      // Tren 7 hari yang berakhir pada tanggal terpilih.
      for (let i = 6; i >= 0; i--) {
        const d = dateAddISO(range.to, -i);
        out.push({ from: d, to: d, label: shortDate(d), short: String(parseISO(d).getDate()) });
      }
      return out;
    }
    if (mode === 'weekly') {
      for (let i = 0; i < 7; i++) {
        const d = dateAddISO(range.from, i);
        out.push({ from: d, to: d, label: DAYS[parseISO(d).getDay()], short: DAYS[parseISO(d).getDay()].slice(0, 3) });
      }
      return out;
    }
    if (mode === 'yearly') {
      const y = range.from.slice(0, 4);
      for (let i = 0; i < 12; i++) {
        const ym = y + '-' + pad(i + 1);
        out.push({ from: ym + '-01', to: ym + '-' + pad(daysInMonth(ym)), label: MONTHS[i], short: MONTHS_SHORT[i] });
      }
      return out;
    }
    const ym = range.from.slice(0, 7), n = daysInMonth(ym);
    for (let d = 1; d <= n; d++) {
      const iso = ym + '-' + pad(d);
      out.push({ from: iso, to: iso, label: String(d), short: String(d) });
    }
    return out;
  }

  function budgetRows(ym) {
    const spentMap = new Map();
    monthTx(ym).forEach((t) => { if (t.type === 'expense') spentMap.set(t.categoryId, (spentMap.get(t.categoryId) || 0) + num(t.amount)); });
    return DB.budgets
      .filter((b) => b.month === ym)
      .map((b) => {
        const c = catById(b.categoryId);
        const spent = spentMap.get(b.categoryId) || 0;
        return { id: b.id, categoryId: b.categoryId, name: c ? c.name : 'Kategori dihapus', icon: c ? c.icon : '🏷️', color: c ? c.color : '#94a3b8', amount: num(b.amount), spent, left: num(b.amount) - spent, used: pct(spent, num(b.amount)) };
      })
      .sort((a, b) => b.used - a.used);
  }
  function goalSaved(goalId) {
    return DB.goalEntries.filter((e) => e.goalId === goalId).reduce((s, e) => s + (e.dir === 'out' ? -num(e.amount) : num(e.amount)), 0);
  }
  function debtPaid(d) { return (d.payments || []).reduce((s, p) => s + num(p.amount), 0); }
  function typeLabel(t) { return t === 'income' ? 'Pemasukan' : t === 'transfer' ? 'Transfer' : 'Pengeluaran'; }
  function typePill(t) { return 'pill-' + t; }

  /* --------------------------------------------------------------- toast */

  function toast(msg, kind) {
    const root = document.getElementById('toastRoot');
    const el = document.createElement('div');
    el.className = 'toast' + (kind === 'err' ? ' err' : '');
    el.textContent = msg;
    root.appendChild(el);
    setTimeout(() => { el.style.opacity = '0'; el.style.transition = 'opacity .3s'; setTimeout(() => el.remove(), 320); }, 2600);
  }

  /* ----------------------------------------------------------------- api */

  const AUTH = window.__AUTH__ || {};

  async function call(action, payload) {
    const res = await fetch(API + '?action=' + encodeURIComponent(action), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'fetch',
        'X-CSRF-Token': AUTH.csrf || ''
      },
      body: JSON.stringify(payload || {})
    });
    let json = null;
    try { json = await res.json(); } catch (e) { throw new Error('Server mengirim respons tidak valid.'); }
    if (res.status === 401) { window.location.replace('index.php'); throw new Error('Sesi berakhir. Masukkan token kembali.'); }
    if (res.status === 419) { window.location.reload(); throw new Error('Halaman kedaluwarsa. Memuat ulang…'); }
    if (!res.ok || !json || !json.ok) {
      throw new Error((json && json.error) || 'Gagal memproses permintaan (' + res.status + ').');
    }
    if (json.data && json.data.db) { DB = json.data.db; applyChrome(); }
    return json.data;
  }

  async function apiGet(action, params) {
    const qs = new URLSearchParams(Object.assign({ action: action }, params || {}));
    const res = await fetch(API + '?' + qs.toString(), { headers: { 'X-Requested-With': 'fetch' } });
    if (res.status === 401) { window.location.replace('index.php'); throw new Error('Sesi berakhir.'); }
    let json = null;
    try { json = await res.json(); } catch (e) { throw new Error('Server mengirim respons tidak valid.'); }
    if (!res.ok || !json || !json.ok) {
      throw new Error((json && json.error) || 'Gagal memuat data (' + res.status + ').');
    }
    return json.data;
  }

  // Alamat folder aplikasi (untuk contoh perintah cron / URL cron).
  function appBaseUrl() {
    const u = new URL(window.location.href);
    u.search = '';
    u.hash = '';
    u.pathname = u.pathname.replace(/[^/]*$/, '');
    return u.href.replace(/\/+$/, '');
  }

  /* --------------------------------------------------------------- modal */

  const modalRoot = document.getElementById('modalRoot');
  let modalSubmit = null;

  function openModal(opts) {
    document.getElementById('modalTitle').textContent = opts.title || '';
    document.getElementById('modalBody').innerHTML = opts.body || '';
    const foot = document.getElementById('modalFoot');
    foot.innerHTML =
      '<button class="btn" data-close type="button">Batal</button>' +
      '<button class="btn ' + (opts.danger ? 'btn-danger' : 'btn-primary') + '" id="modalSubmit" type="button">' + esc(opts.submitLabel || 'Simpan') + '</button>';
    modalRoot.hidden = false;
    modalSubmit = opts.onSubmit || null;
    const first = modalRoot.querySelector('input:not([type=hidden]), select, textarea');
    if (first) setTimeout(() => first.focus(), 40);
    modalRoot.querySelector('#modalSubmit').onclick = submitModal;
    modalRoot.querySelector('form')?.addEventListener('submit', (e) => { e.preventDefault(); submitModal(); });
  }

  function closeModal() { modalRoot.hidden = true; modalSubmit = null; }

  async function submitModal() {
    if (!modalSubmit) return closeModal();
    const btn = document.getElementById('modalSubmit');
    const body = document.getElementById('modalBody');
    const data = {};
    body.querySelectorAll('[name]').forEach((el) => {
      if (el.type === 'radio') { if (el.checked) data[el.name] = el.value; }
      else if (el.type === 'checkbox') data[el.name] = el.checked;
      else data[el.name] = el.value;
    });
    btn.disabled = true;
    const original = btn.textContent;
    btn.textContent = 'Menyimpan…';
    try {
      await modalSubmit(data, body);
      closeModal();
    } catch (err) {
      toast(err.message || 'Gagal menyimpan.', 'err');
      btn.disabled = false;
      btn.textContent = original;
    }
  }

  function confirmDialog(title, message, onYes, yesLabel) {
    openModal({
      title: title,
      danger: true,
      submitLabel: yesLabel || 'Hapus',
      body: '<p class="dim">' + esc(message) + '</p>',
      onSubmit: async () => { await onYes(); }
    });
  }

  /* -------------------------------------------------------------- charts */

  function barChart(data, opts) {
    const o = opts || {};
    const w = 660, h = 210, padL = 42, padR = 10, padT = 12, padB = 28;
    const innerW = w - padL - padR, innerH = h - padT - padB;
    const max = Math.max(1, ...data.map((d) => Math.max(d.income, d.expense)));
    const niceMax = Math.ceil(max / 1e6) * 1e6 || 1;
    const groupW = innerW / Math.max(1, data.length);
    const barW = Math.min(20, groupW * 0.26);
    let g = '';
    for (let i = 0; i <= 3; i++) {
      const y = padT + (innerH / 3) * i;
      const val = niceMax - (niceMax / 3) * i;
      g += '<line x1="' + padL + '" y1="' + y.toFixed(1) + '" x2="' + (w - padR) + '" y2="' + y.toFixed(1) + '" stroke="currentColor" stroke-opacity="0.10" stroke-width="1"/>';
      g += '<text x="' + (padL - 6) + '" y="' + (y + 4).toFixed(1) + '" text-anchor="end" font-size="9" fill="currentColor" fill-opacity="0.45">' + compact(val) + '</text>';
    }
    const labelStep = Math.ceil(data.length / 12);
    data.forEach((d, i) => {
      const cx = padL + groupW * i + groupW / 2;
      const hi = (d.income / niceMax) * innerH;
      const he = (d.expense / niceMax) * innerH;
      const [y, m] = (d.month || '2000-01').split('-').map(Number);
      const tip = d.full || (MONTHS[m - 1] + ' ' + y);
      g += '<rect x="' + (cx - barW - 2).toFixed(1) + '" y="' + (padT + innerH - hi).toFixed(1) + '" width="' + barW + '" height="' + Math.max(0, hi).toFixed(1) + '" rx="3" fill="var(--income)" opacity="0.9"><title>' + esc(tip) + ' — pemasukan ' + money(d.income) + '</title></rect>';
      g += '<rect x="' + (cx + 2).toFixed(1) + '" y="' + (padT + innerH - he).toFixed(1) + '" width="' + barW + '" height="' + Math.max(0, he).toFixed(1) + '" rx="3" fill="var(--expense)" opacity="0.9"><title>' + esc(tip) + ' — pengeluaran ' + money(d.expense) + '</title></rect>';
      if (data.length <= 12 || i % labelStep === 0 || i === data.length - 1) {
        const tick = d.label != null ? String(d.label) : MONTHS_SHORT[m - 1];
        g += '<text x="' + cx.toFixed(1) + '" y="' + (h - 8) + '" text-anchor="middle" font-size="10" fill="currentColor" fill-opacity="0.6">' + esc(tick) + '</text>';
      }
    });
    return '<svg class="chart" viewBox="0 0 ' + w + ' ' + h + '" role="img" aria-label="' + esc(o.title || ('Grafik pemasukan dan pengeluaran ' + (o.months || 6) + ' bulan terakhir')) + '">' + g + '</svg>';
  }

  function donutChart(items, total) {
    const size = 200, cx = size / 2, cy = size / 2, r = 64, sw = 24;
    const circ = 2 * Math.PI * r;
    let offset = 0;
    let seg = '';
    const sum = total || items.reduce((s, i) => s + i.total, 0);
    if (sum <= 0) {
      seg = '<circle cx="' + cx + '" cy="' + cy + '" r="' + r + '" fill="none" stroke="currentColor" stroke-opacity="0.12" stroke-width="' + sw + '"/>';
    } else {
      items.forEach((it) => {
        const frac = it.total / sum;
        const len = frac * circ;
        seg += '<circle cx="' + cx + '" cy="' + cy + '" r="' + r + '" fill="none" stroke="' + esc(it.color) + '" stroke-width="' + sw + '" stroke-dasharray="' + (len - 1.5).toFixed(2) + ' ' + (circ - len + 1.5).toFixed(2) + '" stroke-dashoffset="' + (-offset).toFixed(2) + '" transform="rotate(-90 ' + cx + ' ' + cy + ')" stroke-linecap="butt"><title>' + esc(it.name) + ': ' + money(it.total) + ' (' + frac.toFixed(1) + '%)</title></circle>';
        offset += len;
      });
    }
    return '<svg class="chart" viewBox="0 0 ' + size + ' ' + size + '" style="max-width:200px" role="img" aria-label="Komposisi pengeluaran per kategori">' +
      seg +
      '<text x="' + cx + '" y="' + (cy - 2) + '" text-anchor="middle" class="donut-sub">TOTAL</text>' +
      '<text x="' + cx + '" y="' + (cy + 18) + '" text-anchor="middle" class="donut-center">' + compact(sum) + '</text>' +
      '</svg>';
  }

  /* --------------------------------------------------------------- views */

  function emptyState(icon, title, sub, actionHtml) {
    return '<div class="empty"><span class="empty-icon">' + icon + '</span><strong>' + esc(title) + '</strong><span>' + esc(sub || '') + '</span>' + (actionHtml ? '<div style="margin-top:12px">' + actionHtml + '</div>' : '') + '</div>';
  }

  function monthNav(ym, act) {
    return '<div class="row" style="gap:4px">' +
      '<button class="icon-btn btn-sm" data-act="' + act + '" data-delta="-1" title="Bulan sebelumnya" aria-label="Bulan sebelumnya">‹</button>' +
      '<strong class="nowrap" style="min-width:112px;text-align:center">' + esc(monthName(ym)) + '</strong>' +
      '<button class="icon-btn btn-sm" data-act="' + act + '" data-delta="1" title="Bulan berikutnya" aria-label="Bulan berikutnya">›</button>' +
      '</div>';
  }

  /* -------- dashboard -------- */

  function viewDashboard() {
    const ym = state.month;
    const s = monthSummary(ym);
    const bal = balanceMap();
    const total = totalBalance();
    const prev = monthSummary(ymAdd(ym, -1));
    const diff = s.expense - prev.expense;
    const cats = catTotals(ym);
    const budgets = budgetRows(ym).slice(0, 5);
    const recent = txList().slice(0, 8);
    const goals = DB.goals.slice(0, 3);

    const statBalance =
      '<div class="stat stat-balance"><div class="stat-label">💰 Saldo total</div>' +
      '<div class="stat-value ' + (total < 0 ? 'neg' : '') + '">' + money(total) + '</div>' +
      '<div class="stat-foot">' + accounts().length + ' akun aktif</div></div>';

    const statIn =
      '<div class="stat stat-in"><div class="stat-label">⬆️ Pemasukan</div>' +
      '<div class="stat-value pos">' + money(s.income) + '</div>' +
      '<div class="stat-foot">' + esc(monthName(ym)) + '</div></div>';

    const statOut =
      '<div class="stat stat-out"><div class="stat-label">⬇️ Pengeluaran</div>' +
      '<div class="stat-value neg">' + money(s.expense) + '</div>' +
      '<div class="stat-foot">' + (prev.expense > 0 ? (diff >= 0 ? '▲ ' + compact(diff) + ' lebih tinggi' : '▼ ' + compact(-diff) + ' lebih rendah') + ' dari bulan lalu' : 'belum ada data bulan lalu') + '</div></div>';

    const statNet =
      '<div class="stat stat-net"><div class="stat-label">📊 Arus kas bersih</div>' +
      '<div class="stat-value ' + (s.net < 0 ? 'neg' : 'pos') + '">' + (s.net > 0 ? '+' : '') + money(s.net) + '</div>' +
      '<div class="stat-foot">' + s.count + ' transaksi bulan ini</div></div>';

    const seriesData = series(6, ym);

    const catDonut = cats.length
      ? '<div class="donut-wrap">' + donutChart(cats.slice(0, 7), s.expense) +
        '<div class="cat-list">' + cats.slice(0, 7).map((c) =>
          '<div class="cat-row"><span class="legend-dot" style="background:' + esc(c.color) + '"></span>' +
          '<span class="cat-name">' + esc(c.name) + '</span>' +
          '<span class="cat-val">' + compact(c.total) + '</span></div>').join('') + '</div></div>'
      : emptyState('🥧', 'Belum ada pengeluaran', 'Catat pengeluaran bulan ' + monthName(ym) + ' untuk melihat komposisinya.');

    const accountChips = accounts().map((a) => {
      const v = bal[a.id] || 0;
      return '<div class="row-between" style="padding:8px 0;border-bottom:1px solid var(--border-soft)">' +
        '<span class="row" style="gap:8px"><span class="tx-icon" style="width:30px;height:30px;border-radius:9px;font-size:.9rem">' + esc(a.icon || '💳') + '</span>' +
        '<span><span style="font-weight:650">' + esc(a.name) + '</span><br><small class="muted">' + esc(a.type) + '</small></span></span>' +
        '<strong class="mono ' + (v < 0 ? 'neg' : '') + '">' + money(v) + '</strong></div>';
    }).join('');

    return '' +
      '<div class="page-head"><div><h1>Ringkasan</h1><p>Kondisi keuangan Anda pada ' + esc(monthName(ym)) + '.</p></div>' +
        '<div class="row"><button class="btn" data-act="scan-open" title="Baca struk belanja dengan kamera">📷 Scan struk</button>' + monthNav(ym, 'dash-month') + '</div></div>' +
      '<div class="grid grid-4">' + statBalance + statIn + statOut + statNet + '</div>' +
      '<div class="grid grid-main">' +
        '<div class="card"><div class="card-head"><h2>Arus kas 6 bulan</h2>' +
          '<div class="chart-legend"><span><span class="legend-dot" style="background:var(--income)"></span>Pemasukan</span><span><span class="legend-dot" style="background:var(--expense)"></span>Pengeluaran</span></div></div>' +
          barChart(seriesData, { months: 6 }) +
          (seriesData.every((d) => !d.income && !d.expense) ? '<p class="tiny muted" style="margin:6px 0 0">Grafik akan terisi setelah ada pemasukan atau pengeluaran.</p>' : '') + '</div>' +
        '<div class="card"><div class="card-head"><h2>Komposisi pengeluaran</h2><span class="card-sub">' + esc(monthName(ym, true)) + '</span></div>' + catDonut + '</div>' +
      '</div>' +
      '<div class="grid grid-main">' +
        '<div class="card"><div class="card-head"><h2>Transaksi terbaru</h2><button class="btn btn-sm" data-act="go" data-view="transactions">Lihat semua</button></div>' +
          (recent.length ? '<div class="tx-list">' + recent.map(txItemHtml).join('') + '</div>' : emptyState('🧾', 'Belum ada transaksi', 'Mulai catat pemasukan atau pengeluaran pertama Anda.', '<button class="btn btn-primary btn-sm" data-act="tx-new">+ Catat transaksi</button>')) +
        '</div>' +
        '<div class="stack">' +
          '<div class="card"><div class="card-head"><h2>Anggaran</h2><button class="btn btn-sm" data-act="go" data-view="budgets">Kelola</button></div>' +
            (budgets.length ? budgets.map(budgetRowHtml).join('') : '<p class="muted small" style="margin:0">Belum ada anggaran bulan ini. Tetapkan batas belanja per kategori agar pengeluaran terkendali.</p>') +
          '</div>' +
          '<div class="card"><div class="card-head"><h2>Saldo akun</h2><button class="btn btn-sm" data-act="go" data-view="settings">Atur</button></div>' +
            (accountChips || '<p class="muted small">Belum ada akun.</p>') + '</div>' +
          (goals.length ? '<div class="card"><div class="card-head"><h2>Target tabungan</h2><button class="btn btn-sm" data-act="go" data-view="goals">Semua</button></div>' +
            goals.map((g) => {
              const saved = goalSaved(g.id);
              const p = clamp(pct(saved, num(g.target)), 0, 100);
              return '<div style="margin-bottom:10px"><div class="row-between small"><span>' + esc(g.icon || '🎯') + ' ' + esc(g.name) + '</span><strong class="goal-pct">' + Math.round(p) + '%</strong></div>' +
                '<div class="progress" style="margin-top:4px"><span style="width:' + p.toFixed(1) + '%;background:' + esc(g.color || 'var(--accent)') + '"></span></div>' +
                '<div class="tiny muted" style="margin-top:3px">' + money(saved) + ' dari ' + money(g.target) + '</div></div>';
            }).join('') + '</div>' : '') +
        '</div>' +
      '</div>';
  }

  function txItemHtml(t, opts) {
    const o = opts || {};
    const cat = catById(t.categoryId);
    const acc = accById(t.accountId);
    const to = t.toAccountId ? accById(t.toAccountId) : null;
    const icon = t.type === 'transfer' ? '🔁' : (cat && cat.icon) || (t.type === 'income' ? '💰' : '📦');
    const title = t.type === 'transfer'
      ? (acc ? acc.name : '?') + ' → ' + (to ? to.name : '?')
      : (cat ? cat.name : 'Tanpa kategori');
    const meta = [
      t.merchant ? esc(t.merchant) : '',
      esc(acc ? acc.name : 'Akun dihapus'),
      t.type === 'transfer' && to ? 'ke ' + esc(to.name) : '',
      dateLabel(t.date)
    ].filter(Boolean).join(' · ');
    const sign = t.type === 'income' ? '+' : t.type === 'expense' ? '−' : '';
    return '<div class="tx-item" data-tx="' + esc(t.id) + '">' +
      '<div class="tx-icon">' + esc(icon) + '</div>' +
      '<div class="tx-main"><div class="tx-title">' + esc(title) + ((t.interestRef || t.source === 'cron-bunga') ? ' <span class="pill pill-income tiny">otomatis</span>' : '') + (t.gold ? ' <span class="pill tiny">emas</span>' : '') + (t.note ? ' <span class="muted tiny">· ' + esc(t.note) + '</span>' : '') + '</div>' +
      '<div class="tx-meta">' + meta + '</div></div>' +
      '<div class="row" style="gap:6px">' +
        '<div class="tx-amount ' + esc(t.type) + '">' + sign + money(t.amount) + '</div>' +
        (o.actions === false ? '' :
          '<div class="tx-actions">' +
          (t.receipt ? '<button class="icon-btn btn-sm" data-act="tx-receipt" data-id="' + esc(t.id) + '" title="Lihat foto struk" aria-label="Lihat foto struk">🧾</button>' : '') +
          '<button class="icon-btn btn-sm" data-act="tx-edit" data-id="' + esc(t.id) + '" title="Ubah" aria-label="Ubah transaksi">✎</button>' +
          '<button class="icon-btn btn-sm" data-act="tx-del" data-id="' + esc(t.id) + '" title="Hapus" aria-label="Hapus transaksi">🗑</button>' +
          '</div>') +
      '</div></div>';
  }

  function budgetRowHtml(b, opts) {
    const o = opts || {};
    const cls = b.used > 100 ? 'over' : b.used >= 80 ? 'warn' : '';
    return '<div class="budget-row"><div class="budget-top">' +
      '<span class="budget-name">' + esc(b.icon) + ' ' + esc(b.name) + '</span>' +
      '<span class="budget-num">' + money(b.spent) + ' <span class="muted">/ ' + money(b.amount) + '</span></span></div>' +
      '<div class="progress ' + cls + '"><span style="width:' + clamp(b.used, 0, 100).toFixed(1) + '%"></span></div>' +
      '<div class="budget-foot"><span>' + Math.round(b.used) + '% terpakai</span><span class="' + (b.left < 0 ? 'neg' : 'muted') + '">' + (b.left < 0 ? 'Lewat ' + money(-b.left) : 'Sisa ' + money(b.left)) + '</span></div>' +
      (o.actions === false ? '' : '<div class="row" style="margin-top:8px;gap:6px"><button class="btn btn-sm" data-act="budget-edit" data-id="' + esc(b.id) + '">Ubah batas</button><button class="btn btn-sm btn-danger" data-act="budget-del" data-id="' + esc(b.id) + '">Hapus</button></div>') +
      '</div>';
  }

  /* -------- transactions -------- */

  function viewTransactions() {
    const f = state.tx;
    let rows = txList().filter((t) => {
      if (f.month !== 'all' && String(t.date).slice(0, 7) !== f.month) return false;
      if (f.from && String(t.date) < f.from) return false;
      if (f.to && String(t.date) > f.to) return false;
      if (f.type !== 'all' && t.type !== f.type) return false;
      if (f.cat !== 'all' && t.categoryId !== f.cat) return false;
      if (f.acc !== 'all' && t.accountId !== f.acc && t.toAccountId !== f.acc) return false;
      if (f.q && !txMatchesQuery(t, f.q)) return false;
      return true;
    });
    const total = rows.length;
    const shown = rows.slice(0, f.limit);
    let income = 0, expense = 0;
    rows.forEach((t) => { if (t.type === 'income') income += num(t.amount); else if (t.type === 'expense') expense += num(t.amount); });

    const grouped = [];
    shown.forEach((t) => {
      const last = grouped[grouped.length - 1];
      if (last && last.date === t.date) last.items.push(t);
      else grouped.push({ date: t.date, items: [t] });
    });

    const listHtml = grouped.map((g) => {
      const daySum = g.items.reduce((s, t) => s + (t.type === 'income' ? num(t.amount) : t.type === 'expense' ? -num(t.amount) : 0), 0);
      return '<div style="margin-bottom:6px"><div class="row-between" style="padding:8px 2px;border-bottom:1px solid var(--border)">' +
        '<strong class="small dim">' + esc(dateLabel(g.date, true)) + '</strong>' +
        '<span class="small mono ' + (daySum < 0 ? 'neg' : daySum > 0 ? 'pos' : 'muted') + '">' + (daySum > 0 ? '+' : '') + money(daySum) + '</span></div>' +
        '<div class="tx-list">' + g.items.map((t) => txItemHtml(t)).join('') + '</div></div>';
    }).join('');

    const rangeTxt = (f.from || f.to)
      ? ' pada rentang ' + (f.from ? shortDate(f.from) : 'awal') + ' – ' + (f.to ? shortDate(f.to) + ' ' + parseISO(f.to).getFullYear() : 'terakhir')
      : (f.month === 'all' ? ' (semua bulan)' : ' pada ' + monthName(f.month));

    return '<div class="page-head"><div><h1>Transaksi</h1><p>' + total + ' transaksi ditemukan' + rangeTxt + '.</p></div>' +
      '<div class="row"><button class="btn" data-act="scan-open" title="Baca struk belanja dengan kamera">📷 Scan struk</button>' +
      '<button class="btn btn-expense" data-act="tx-new" data-type="expense">− Pengeluaran</button>' +
      '<button class="btn btn-income" data-act="tx-new" data-type="income">+ Pemasukan</button>' +
      '<button class="btn" data-act="tx-transfer">🔁 Transfer</button></div></div>' +

      '<div class="filters">' +
        '<input type="month" value="' + esc(f.month === 'all' ? '' : f.month) + '" data-filter="month" aria-label="Filter bulan">' +
        '<input type="date" value="' + esc(f.from) + '" data-filter="from" aria-label="Dari tanggal" title="Dari tanggal">' +
        '<input type="date" value="' + esc(f.to) + '" data-filter="to" aria-label="Sampai tanggal" title="Sampai tanggal">' +
        '<select data-filter="type" aria-label="Filter tipe"><option value="all">Semua tipe</option>' +
          ['income', 'expense', 'transfer'].map((t) => '<option value="' + t + '"' + (f.type === t ? ' selected' : '') + '>' + typeLabel(t) + '</option>').join('') +
        '</select>' +
        '<select data-filter="cat" aria-label="Filter kategori"><option value="all">Semua kategori</option>' +
          categories(null, true).map((c) => '<option value="' + esc(c.id) + '"' + (f.cat === c.id ? ' selected' : '') + '>' + esc(c.icon + ' ' + c.name + (c.archived ? ' (arsip)' : '')) + '</option>').join('') +
        '</select>' +
        '<select data-filter="acc" aria-label="Filter akun"><option value="all">Semua akun</option>' +
          accounts(true).map((a) => '<option value="' + esc(a.id) + '"' + (f.acc === a.id ? ' selected' : '') + '>' + esc(a.name) + '</option>').join('') +
        '</select>' +
        '<input type="search" class="grow" placeholder="Cari nama, toko, atau nominal (mis. 58000, 25rb, &gt;=100000)…" value="' + esc(f.q) + '" data-filter="q" aria-label="Cari transaksi">' +
        '<button class="btn btn-sm ' + (f.month === 'all' ? 'btn-primary' : '') + '" data-act="tx-all-month">Semua</button>' +
        '<button class="btn btn-sm" data-act="tx-reset">Reset</button>' +
        '<a class="btn btn-sm btn-ghost" href="' + API + '?action=export.csv&month=' + encodeURIComponent((f.from || f.to) ? '' : (f.month === 'all' ? '' : f.month)) + '&from=' + encodeURIComponent(f.from) + '&to=' + encodeURIComponent(f.to) + '">⬇ CSV</a>' +
      '</div>' +

      '<div class="grid grid-3">' +
        '<div class="stat stat-in"><div class="stat-label">Pemasukan</div><div class="stat-value pos" style="font-size:1.15rem">' + money(income) + '</div></div>' +
        '<div class="stat stat-out"><div class="stat-label">Pengeluaran</div><div class="stat-value neg" style="font-size:1.15rem">' + money(expense) + '</div></div>' +
        '<div class="stat"><div class="stat-label">Selisih</div><div class="stat-value ' + (income - expense < 0 ? 'neg' : 'pos') + '" style="font-size:1.15rem">' + money(income - expense) + '</div></div>' +
      '</div>' +

      '<div class="card">' + (total ? listHtml : emptyState('🧾', 'Tidak ada transaksi', f.q || f.type !== 'all' || f.cat !== 'all' || f.acc !== 'all' ? 'Coba ubah filter pencarian Anda.' : 'Belum ada catatan pada periode ini.', '<button class="btn btn-primary btn-sm" data-act="tx-new">+ Catat transaksi</button>')) +
      (total > shown.length ? '<div class="center" style="padding-top:14px"><button class="btn" data-act="tx-more">Muat ' + Math.min(40, total - shown.length) + ' lagi (' + (total - shown.length) + ' tersisa)</button></div>' : '') +
      '</div>';
  }

  /* -------- budgets -------- */

  function viewBudgets() {
    const ym = state.month;
    const rows = budgetRows(ym);
    const totalBudget = rows.reduce((s, r) => s + r.amount, 0);
    const totalSpent = rows.reduce((s, r) => s + r.spent, 0);
    const usedPct = pct(totalSpent, totalBudget);
    const prevRows = budgetRows(ymAdd(ym, -1));
    const usedCats = new Set(rows.map((r) => r.categoryId));
    const available = categories('expense').filter((c) => !usedCats.has(c.id));

    return '<div class="page-head"><div><h1>Anggaran</h1><p>Tetapkan batas pengeluaran per kategori setiap bulan.</p></div>' +
      '<div class="row">' + monthNav(ym, 'dash-month') + '<button class="btn btn-primary" data-act="budget-new">+ Anggaran</button></div></div>' +

      '<div class="grid grid-3">' +
        '<div class="stat"><div class="stat-label">Total anggaran</div><div class="stat-value" style="font-size:1.2rem">' + money(totalBudget) + '</div><div class="stat-foot">' + rows.length + ' kategori</div></div>' +
        '<div class="stat stat-out"><div class="stat-label">Terpakai</div><div class="stat-value neg" style="font-size:1.2rem">' + money(totalSpent) + '</div><div class="stat-foot">' + Math.round(usedPct) + '% dari anggaran</div></div>' +
        '<div class="stat stat-in"><div class="stat-label">Sisa</div><div class="stat-value ' + (totalBudget - totalSpent < 0 ? 'neg' : 'pos') + '" style="font-size:1.2rem">' + money(totalBudget - totalSpent) + '</div><div class="stat-foot">' + esc(monthName(ym)) + '</div></div>' +
      '</div>' +

      '<div class="grid grid-main">' +
        '<div class="card"><div class="card-head"><h2>Anggaran ' + esc(monthName(ym)) + '</h2>' +
          '<div class="row">' + (available.length ? '<button class="btn btn-sm" data-act="budget-new">+ Kategori</button>' : '') +
          (prevRows.length ? '<button class="btn btn-sm" data-act="budget-copy">Salin bulan lalu</button>' : '') + '</div></div>' +
          (rows.length ? rows.map((b) => budgetRowHtml(b)).join('') : emptyState('📅', 'Belum ada anggaran', 'Tetapkan batas belanja per kategori, misalnya Makan & Minum ' + money(1500000) + '.', '<button class="btn btn-primary btn-sm" data-act="budget-new">+ Buat anggaran</button>')) +
        '</div>' +
        '<div class="card"><div class="card-head"><h3>Belum dianggarkan</h3></div>' +
          (available.length ? '<div class="chip-row">' + available.map((c) => '<button class="btn btn-sm" data-act="budget-new" data-cat="' + esc(c.id) + '">' + esc(c.icon + ' ' + c.name) + '</button>').join('') + '</div>' +
            '<p class="small muted" style="margin-top:10px">Kategori tanpa anggaran tetap tercatat, hanya tidak punya batas.</p>'
            : '<p class="small muted" style="margin:0">Semua kategori pengeluaran sudah punya anggaran bulan ini. 🎉</p>') +
          '<div class="divider"></div><p class="tiny muted" style="margin:0">Anggaran bersifat per bulan — bulan baru tidak otomatis terisi. Gunakan tombol “Salin bulan lalu” untuk mengulang pola yang sama.</p>' +
        '</div>' +
      '</div>';
  }

  /* -------- goals -------- */

  function viewGoals() {
    const goals = DB.goals.slice().sort((a, b) => (a.done === b.done ? 0 : a.done ? 1 : -1));
    const totalTarget = goals.reduce((s, g) => s + num(g.target), 0);
    const totalSaved = goals.reduce((s, g) => s + goalSaved(g.id), 0);

    return '<div class="page-head"><div><h1>Target Tabungan</h1><p>Rencanakan dana untuk tujuan tertentu dan pantau setorannya.</p></div>' +
      '<button class="btn btn-primary" data-act="goal-new">+ Target baru</button></div>' +

      '<div class="grid grid-3">' +
        '<div class="stat"><div class="stat-label">Total target</div><div class="stat-value" style="font-size:1.2rem">' + money(totalTarget) + '</div><div class="stat-foot">' + goals.length + ' target</div></div>' +
        '<div class="stat stat-in"><div class="stat-label">Terkumpul</div><div class="stat-value pos" style="font-size:1.2rem">' + money(totalSaved) + '</div><div class="stat-foot">' + Math.round(pct(totalSaved, totalTarget)) + '% dari total target</div></div>' +
        '<div class="stat stat-net"><div class="stat-label">Kurang</div><div class="stat-value" style="font-size:1.2rem">' + money(Math.max(0, totalTarget - totalSaved)) + '</div><div class="stat-foot">sisa yang perlu dikumpulkan</div></div>' +
      '</div>' +

      (goals.length
        ? '<div class="grid grid-3">' + goals.map((g) => {
            const saved = goalSaved(g.id);
            const p = clamp(pct(saved, num(g.target)), 0, 100);
            const done = saved >= num(g.target);
            let deadlineTxt = 'Tanpa tenggat';
            if (g.deadline) {
              const days = Math.ceil((new Date(g.deadline + 'T00:00:00') - new Date(todayISO() + 'T00:00:00')) / 86400000);
              deadlineTxt = days < 0 ? 'Lewat ' + Math.abs(days) + ' hari' : days + ' hari lagi (' + dateLabel(g.deadline) + ')';
            }
            return '<div class="card goal-card">' +
              '<div class="goal-top"><div class="goal-icon">' + esc(g.icon || '🎯') + '</div>' +
              '<div style="flex:1;min-width:0"><div class="goal-name">' + esc(g.name) + (done ? ' <span class="pill pill-income">tercapai</span>' : '') + '</div>' +
              '<div class="goal-meta">' + esc(deadlineTxt) + '</div></div>' +
              '<button class="icon-btn btn-sm" data-act="goal-edit" data-id="' + esc(g.id) + '" title="Ubah target">✎</button></div>' +
              '<div class="progress"><span style="width:' + p.toFixed(1) + '%;background:' + esc(g.color || 'var(--accent)') + '"></span></div>' +
              '<div class="goal-figures"><span>' + money(saved) + '</span><span class="goal-pct">' + Math.round(p) + '%</span></div>' +
              '<div class="goal-figures"><span class="muted">dari ' + money(g.target) + '</span><span class="muted">' + (done ? 'Lunas 🎉' : 'kurang ' + money(num(g.target) - saved)) + '</span></div>' +
              (g.note ? '<div class="tiny muted">' + esc(g.note) + '</div>' : '') +
              '<div class="row" style="gap:6px;margin-top:4px">' +
                '<button class="btn btn-sm btn-primary" data-act="goal-in" data-id="' + esc(g.id) + '">Setor</button>' +
                '<button class="btn btn-sm" data-act="goal-out" data-id="' + esc(g.id) + '">Tarik</button>' +
                '<button class="btn btn-sm btn-ghost" data-act="goal-history" data-id="' + esc(g.id) + '">Riwayat</button>' +
                '<button class="btn btn-sm btn-danger" data-act="goal-del" data-id="' + esc(g.id) + '">Hapus</button>' +
              '</div></div>';
          }).join('') + '</div>'
        : emptyState('🎯', 'Belum ada target', 'Mulai dari yang paling dekat, misalnya dana darurat atau laptop baru.', '<button class="btn btn-primary btn-sm" data-act="goal-new">+ Buat target</button>')) +

      '<div class="card"><p class="tiny muted" style="margin:0">Catatan: setoran target adalah catatan perencanaan dan <strong>tidak mengubah saldo akun</strong>. Bila dana benar-benar dipindahkan, catat juga sebagai transfer antar akun di menu Transaksi.</p></div>';
  }

  /* -------- debts -------- */

  function viewDebts() {
    const debts = DB.debts.filter((d) => d.type === 'debt');
    const recvs = DB.debts.filter((d) => d.type === 'receivable');
    const sum = (list, key) => list.reduce((s, d) => s + (key === 'paid' ? debtPaid(d) : num(d.amount)), 0);
    const debtTotal = sum(debts, 'amount');
    const debtPaidTotal = sum(debts, 'paid');
    const recvTotal = sum(recvs, 'amount');
    const recvPaidTotal = sum(recvs, 'paid');

    const rowHtml = (d) => {
      const paid = debtPaid(d);
      const p = clamp(pct(paid, num(d.amount)), 0, 100);
      const remaining = num(d.amount) - paid;
      const done = remaining <= 0.5 || d.settled;
      let dueTxt = '';
      if (d.dueDate) {
        const days = Math.ceil((new Date(d.dueDate + 'T00:00:00') - new Date(todayISO() + 'T00:00:00')) / 86400000);
        dueTxt = days < 0 ? '<span class="neg">lewat ' + Math.abs(days) + ' hari</span>' : 'jatuh tempo ' + dateLabel(d.dueDate) + ' (' + days + ' hari)';
      }
      return '<div class="budget-row">' +
        '<div class="budget-top"><span class="budget-name">' + esc(d.person) + (done ? ' <span class="pill pill-income">lunas</span>' : '') + '</span>' +
        '<span class="budget-num">' + money(remaining) + ' <span class="muted">/ ' + money(d.amount) + '</span></span></div>' +
        '<div class="progress ' + (done ? '' : p >= 80 ? 'warn' : '') + '"><span style="width:' + p.toFixed(1) + '%"></span></div>' +
        '<div class="budget-foot"><span>' + money(paid) + ' terbayar · ' + Math.round(p) + '%' + (dueTxt ? ' · ' + dueTxt : '') + '</span>' +
        '<span>' + esc(dateLabel(d.date)) + '</span></div>' +
        (d.note ? '<div class="tiny muted" style="margin-top:4px">' + esc(d.note) + '</div>' : '') +
        '<div class="row" style="gap:6px;margin-top:8px">' +
          (done ? '' : '<button class="btn btn-sm btn-primary" data-act="debt-pay" data-id="' + esc(d.id) + '">' + (d.type === 'debt' ? 'Bayar' : 'Terima bayaran') + '</button>') +
          '<button class="btn btn-sm" data-act="debt-history" data-id="' + esc(d.id) + '">Riwayat</button>' +
          '<button class="btn btn-sm" data-act="debt-edit" data-id="' + esc(d.id) + '">Ubah</button>' +
          '<button class="btn btn-sm btn-danger" data-act="debt-del" data-id="' + esc(d.id) + '">Hapus</button>' +
        '</div></div>';
    };

    return '<div class="page-head"><div><h1>Utang & Piutang</h1><p>Catat kewajiban Anda dan uang yang dipinjam orang lain.</p></div>' +
      '<div class="row"><button class="btn" data-act="debt-new" data-type="receivable">+ Piutang</button><button class="btn btn-primary" data-act="debt-new" data-type="debt">+ Utang</button></div></div>' +

      '<div class="grid grid-3">' +
        '<div class="stat stat-out"><div class="stat-label">Sisa utang saya</div><div class="stat-value neg" style="font-size:1.2rem">' + money(Math.max(0, debtTotal - debtPaidTotal)) + '</div><div class="stat-foot">dari total ' + money(debtTotal) + '</div></div>' +
        '<div class="stat stat-in"><div class="stat-label">Piutang belum kembali</div><div class="stat-value pos" style="font-size:1.2rem">' + money(Math.max(0, recvTotal - recvPaidTotal)) + '</div><div class="stat-foot">dari total ' + money(recvTotal) + '</div></div>' +
        '<div class="stat stat-net"><div class="stat-label">Posisi bersih</div><div class="stat-value" style="font-size:1.2rem">' + money(Math.max(0, recvTotal - recvPaidTotal) - Math.max(0, debtTotal - debtPaidTotal)) + '</div><div class="stat-foot">piutang − utang</div></div>' +
      '</div>' +

      '<div class="grid grid-2">' +
        '<div class="card"><div class="card-head"><h2>Utang saya</h2><button class="btn btn-sm" data-act="debt-new" data-type="debt">+ Tambah</button></div>' +
          (debts.length ? debts.map(rowHtml).join('') : '<p class="muted small" style="margin:0">Tidak ada utang tercatat. 👍</p>') + '</div>' +
        '<div class="card"><div class="card-head"><h2>Piutang saya</h2><button class="btn btn-sm" data-act="debt-new" data-type="receivable">+ Tambah</button></div>' +
          (recvs.length ? recvs.map(rowHtml).join('') : '<p class="muted small" style="margin:0">Tidak ada piutang tercatat.</p>') + '</div>' +
      '</div>';
  }

  /* -------- reports -------- */

  function viewReports() {
    const mode = state.reportMode;
    const range = reportRange(mode, state.reportAnchor);
    const s = rangeSummary(range.from, range.to);
    const cats = catTotalsRange(range.from, range.to);
    const bal = balanceMap();
    const total = totalBalance();
    const span = Math.max(1, Math.round((parseISO(range.to) - parseISO(range.from)) / 86400000) + 1);
    const perDay = s.expense / span;
    const isMonthly = mode === 'monthly';

    const subs = reportSubPeriods(mode, range).map((p) => {
      const rs = rangeSummary(p.from, p.to);
      return { label: p.short, full: p.label, from: p.from, to: p.to, month: p.from.slice(0, 7), income: rs.income, expense: rs.expense, net: rs.net, count: rs.count };
    });
    // Tabel rincian: untuk periode pendek cukup baris yang ada transaksinya.
    const detailRows = mode === 'yearly' ? subs : subs.filter((r) => r.count > 0);

    const modeChips = '<div class="type-toggle" role="group" aria-label="Periode laporan">' +
      REPORT_MODES.map(([m, label]) => '<label><input type="radio" name="reportMode" data-act="report-mode" data-mode="' + m + '"' + (mode === m ? ' checked' : '') + '><span>' + label + '</span></label>').join('') +
      '</div>';

    const nav = '<div class="row" style="gap:4px">' +
      '<button class="icon-btn btn-sm" data-act="report-shift" data-delta="-1" title="Periode sebelumnya" aria-label="Periode sebelumnya">‹</button>' +
      '<strong class="nowrap" style="min-width:150px;text-align:center">' + esc(range.label) + '</strong>' +
      '<button class="icon-btn btn-sm" data-act="report-shift" data-delta="1" title="Periode berikutnya" aria-label="Periode berikutnya">›</button>' +
      '<button class="btn btn-sm btn-ghost" data-act="report-now" title="Kembali ke periode berjalan">Sekarang</button>' +
      '</div>';

    const csvHref = API + '?action=export.csv&from=' + encodeURIComponent(range.from) + '&to=' + encodeURIComponent(range.to);

    // Rincian transaksi khusus mode harian.
    const dayRows = mode === 'daily' ? txList().filter((t) => String(t.date) === range.from) : [];
    const dayCard = mode === 'daily'
      ? '<div class="card"><div class="card-head"><h2>Transaksi ' + esc(range.label) + '</h2><span class="card-sub">' + dayRows.length + ' catatan</span></div>' +
        (dayRows.length ? '<div class="tx-list">' + dayRows.map((t) => txItemHtml(t)).join('') + '</div>'
          : '<p class="muted small" style="margin:0">Belum ada transaksi pada tanggal ini.</p>') + '</div>'
      : '';

    const budgetMap = new Map(budgetRows(isMonthly ? range.from.slice(0, 7) : thisMonth()).map((b) => [b.categoryId, b]));
    const catTable = cats.length
      ? '<div class="table-wrap"><table><thead><tr><th>Kategori</th><th class="num">Jumlah</th><th class="num">Porsi</th>' +
        (isMonthly ? '<th class="num">Anggaran</th><th class="num">Status</th>' : '') + '</tr></thead><tbody>' +
        cats.map((c) => {
          const b = budgetMap.get(c.id);
          const share = pct(c.total, s.expense);
          let extra = '';
          if (isMonthly) {
            let status = '<span class="pill pill-muted">—</span>';
            if (b) status = b.used > 100 ? '<span class="pill pill-expense">' + Math.round(b.used) + '% · lewat</span>' : '<span class="pill pill-income">' + Math.round(b.used) + '%</span>';
            extra = '<td class="num">' + (b ? money(b.amount) : '—') + '</td><td class="num">' + status + '</td>';
          }
          return '<tr><td><span class="row" style="gap:7px"><span class="legend-dot" style="background:' + esc(c.color) + '"></span>' + esc(c.name) + '</span></td>' +
            '<td class="num">' + money(c.total) + '</td><td class="num">' + share.toFixed(1) + '%</td>' + extra + '</tr>';
        }).join('') +
        '</tbody><tfoot class="tfoot"><tr><td>Total</td><td class="num">' + money(s.expense) + '</td><td class="num">100%</td>' +
        (isMonthly ? '<td class="num">' + money(budgetRows(range.from.slice(0, 7)).reduce((a, b) => a + b.amount, 0)) + '</td><td></td>' : '') +
        '</tr></tfoot></table></div>'
      : '<p class="muted small" style="margin:0">Belum ada pengeluaran pada periode ini.</p>';

    const accRows = accounts().map((a) => {
      let inc = 0, exp = 0;
      rangeTx(range.from, range.to).forEach((t) => {
        const amt = num(t.amount);
        if (t.type === 'income' && t.accountId === a.id) inc += amt;
        if (t.type === 'expense' && t.accountId === a.id) exp += amt;
        if (t.type === 'transfer' && t.accountId === a.id) exp += amt;
        if (t.type === 'transfer' && t.toAccountId === a.id) inc += amt;
      });
      return { acc: a, inc, exp, net: inc - exp, balance: bal[a.id] || 0 };
    });

    const chartSub = subs.length > 16
      ? '<p class="tiny muted" style="margin:6px 0 0">Grafik menampilkan seluruh ' + subs.length + ' titik periode; ketuk batang untuk melihat nominal.</p>'
      : '';

    return '<div class="page-head"><div><h1>Laporan</h1><p>Rekap ' + esc(range.label) + ' · ' + s.count + ' transaksi.</p></div>' +
      '<div class="row">' + nav + '<button class="btn btn-sm" data-act="report-print">🖨 Cetak</button>' +
      '<a class="btn btn-sm" href="' + csvHref + '">⬇ CSV</a></div></div>' +

      '<div class="card" style="padding:12px 14px">' + modeChips + '</div>' +

      '<div class="grid grid-4">' +
        '<div class="stat stat-in"><div class="stat-label">Pemasukan</div><div class="stat-value pos" style="font-size:1.15rem">' + money(s.income) + '</div><div class="stat-foot">' + esc(range.label) + '</div></div>' +
        '<div class="stat stat-out"><div class="stat-label">Pengeluaran</div><div class="stat-value neg" style="font-size:1.15rem">' + money(s.expense) + '</div><div class="stat-foot">rata-rata ' + money(perDay) + '/hari</div></div>' +
        '<div class="stat stat-net"><div class="stat-label">Selisih (arus kas)</div><div class="stat-value ' + (s.net < 0 ? 'neg' : 'pos') + '" style="font-size:1.15rem">' + (s.net > 0 ? '+' : '') + money(s.net) + '</div><div class="stat-foot">transfer ' + money(s.transfer) + ' tidak dihitung</div></div>' +
        '<div class="stat"><div class="stat-label">Saldo total</div><div class="stat-value ' + (total < 0 ? 'neg' : '') + '" style="font-size:1.15rem">' + money(total) + '</div><div class="stat-foot">' + accounts().length + ' dompet aktif</div></div>' +
      '</div>' +

      '<div class="grid grid-main">' +
        '<div class="card"><div class="card-head"><h2>Grafik arus kas</h2>' +
          '<div class="chart-legend"><span><span class="legend-dot" style="background:var(--income)"></span>Pemasukan</span><span><span class="legend-dot" style="background:var(--expense)"></span>Pengeluaran</span></div></div>' +
          barChart(subs, { months: subs.length }) + chartSub + '</div>' +
        '<div class="card"><div class="card-head"><h2>Komposisi pengeluaran</h2></div>' +
          (cats.length
            ? '<div class="donut-wrap">' + donutChart(cats.slice(0, 7), s.expense) + '<div class="cat-list">' +
              cats.slice(0, 7).map((c) => '<div class="cat-row"><span class="legend-dot" style="background:' + esc(c.color) + '"></span><span class="cat-name">' + esc(c.name) + '</span><span class="cat-val">' + compact(c.total) + '</span></div>').join('') +
              '</div></div>'
            : emptyState('🥧', 'Belum ada pengeluaran', 'Tidak ada pengeluaran pada ' + range.label + '.')) +
          '</div>' +
      '</div>' +

      dayCard +

      '<div class="card"><div class="card-head"><h2>Pengeluaran per kategori</h2><span class="card-sub">' + esc(range.label) + '</span></div>' + catTable + '</div>' +

      '<div class="grid grid-2">' +
        '<div class="card"><div class="card-head"><h2>Per dompet</h2><span class="card-sub">' + esc(range.label) + '</span></div>' +
          '<div class="table-wrap"><table><thead><tr><th>Dompet</th><th class="num">Masuk</th><th class="num">Keluar</th><th class="num">Saldo kini</th></tr></thead><tbody>' +
          accRows.map((r) => '<tr><td>' + esc((r.acc.icon || '') + ' ' + r.acc.name) + '</td><td class="num pos">' + (r.inc ? money(r.inc) : '—') + '</td><td class="num neg">' + (r.exp ? money(r.exp) : '—') + '</td><td class="num">' + money(r.balance) + '</td></tr>').join('') +
          '</tbody></table></div></div>' +
        '<div class="card"><div class="card-head"><h2>Rincian ' + (mode === 'yearly' ? 'bulanan' : mode === 'weekly' ? 'harian' : mode === 'daily' ? '7 hari terakhir' : 'harian') + '</h2><span class="card-sub">' + esc(range.label) + '</span></div>' +
          '<div class="table-wrap">' + (detailRows.length
            ? '<table><thead><tr><th>' + (mode === 'yearly' ? 'Bulan' : 'Tanggal') + '</th><th class="num">Masuk</th><th class="num">Keluar</th><th class="num">Selisih</th></tr></thead><tbody>' +
              detailRows.map((r) => '<tr><td class="nowrap">' + esc(mode === 'yearly' ? r.full : shortDate(r.from)) + '</td>' +
                '<td class="num pos">' + (r.income ? compact(r.income) : '—') + '</td>' +
                '<td class="num neg">' + (r.expense ? compact(r.expense) : '—') + '</td>' +
                '<td class="num ' + (r.net < 0 ? 'neg' : 'pos') + '">' + (r.income || r.expense ? (r.net > 0 ? '+' : '') + compact(r.net) : '—') + '</td></tr>').join('') +
              '</tbody><tfoot class="tfoot"><tr><td>Total</td><td class="num">' + compact(s.income) + '</td><td class="num">' + compact(s.expense) + '</td><td class="num ' + (s.net < 0 ? 'neg' : 'pos') + '">' + compact(s.net) + '</td></tr></tfoot></table>'
            : '<p class="empty-sm">Belum ada transaksi pada periode ini.</p>') + '</div></div>' +
      '</div>' +
      '<p class="tiny muted" style="margin:0">Nilai bertanda "<strong>rb</strong>" = ribu, "<strong>jt</strong>" = juta, "<strong>M</strong>" = miliar. Transfer antar dompet tidak dihitung sebagai pemasukan atau pengeluaran.</p>';
  }

  /* -------- settings -------- */

  function viewSettings() {
    const s = DB.settings;
    if (!state.interest.info && !state.interest.loading && !state.interest.error) loadInterestInfo(false);
    if (!state.auth.info && !state.auth.loading && !state.auth.error) loadAuthInfo(false);
    const bal = balanceMap();
    const accRows = DB.accounts.map((a) => '<tr>' +
      '<td><span class="row" style="gap:8px">' + esc(a.icon || '💳') + ' <strong>' + esc(a.name) + '</strong>' + (a.archived ? ' <span class="pill">arsip</span>' : '') + '</span></td>' +
      '<td>' + esc(a.type) + '</td>' +
      '<td class="num">' + money(a.initial) + '</td>' +
      '<td class="num">' + money(bal[a.id] || 0) + '</td>' +
      '<td class="num"><div class="row" style="justify-content:flex-end;gap:4px">' +
        '<button class="btn btn-sm" data-act="acc-edit" data-id="' + esc(a.id) + '">Ubah</button>' +
        '<button class="btn btn-sm" data-act="acc-archive" data-id="' + esc(a.id) + '">' + (a.archived ? 'Aktifkan' : 'Arsip') + '</button>' +
        '<button class="btn btn-sm btn-danger" data-act="acc-del" data-id="' + esc(a.id) + '">Hapus</button>' +
      '</div></td></tr>').join('');

    const catRows = (type) => categories(type, true).map((c) => '<div class="row-between" style="padding:7px 0;border-bottom:1px solid var(--border-soft)">' +
      '<span class="row" style="gap:8px"><span class="legend-dot" style="background:' + esc(c.color) + '"></span>' + esc(c.icon + ' ' + c.name) + (c.archived ? ' <span class="pill">arsip</span>' : '') + '</span>' +
      '<span class="row" style="gap:4px"><button class="icon-btn btn-sm" data-act="cat-edit" data-id="' + esc(c.id) + '" title="Ubah">✎</button>' +
      '<button class="icon-btn btn-sm" data-act="cat-del" data-id="' + esc(c.id) + '" title="Hapus">🗑</button></span></div>').join('');

    return '<div class="page-head"><div><h1>Pengaturan</h1><p>Nama, mata uang, akun, kategori, dan pencadangan data.</p></div></div>' +

      '<div class="grid grid-main">' +
        '<div class="stack">' +
          '<div class="card"><div class="card-head"><h2>Profil</h2></div>' +
            '<form data-form="profile" class="form-grid">' +
              '<div class="field"><label for="setAppName">Nama aplikasi</label><input id="setAppName" name="appName" value="' + esc(s.appName) + '" maxlength="40"></div>' +
              '<div class="field"><label for="setOwner">Nama pemilik</label><input id="setOwner" name="ownerName" value="' + esc(s.ownerName) + '" maxlength="60" placeholder="mis. Rumah Tangga Saya"></div>' +
              '<div class="field"><label for="setSymbol">Simbol mata uang</label><input id="setSymbol" name="currencySymbol" value="' + esc(s.currencySymbol) + '" maxlength="6"></div>' +
              '<div class="field"><label for="setCurrency">Kode mata uang</label><input id="setCurrency" name="currency" value="' + esc(s.currency) + '" maxlength="8"></div>' +
              '<div class="field-full"><button class="btn btn-primary" type="submit">Simpan profil</button></div>' +
            '</form></div>' +

          '<div class="card"><div class="card-head"><h2>Akun & dompet</h2><button class="btn btn-sm btn-primary" data-act="acc-new">+ Akun</button></div>' +
            '<div class="table-wrap"><table><thead><tr><th>Nama</th><th>Jenis</th><th class="num">Saldo awal</th><th class="num">Saldo kini</th><th></th></tr></thead><tbody>' + accRows + '</tbody></table></div>' +
            '<p class="tiny muted" style="margin:10px 0 0">Saldo awal dipakai sebagai titik mulai. Transaksi setelahnya dihitung otomatis. Akun yang sudah dipakai tidak bisa dihapus — arsipkan saja.</p></div>' +

          '<div class="grid grid-2">' +
            '<div class="card"><div class="card-head"><h3>Kategori pengeluaran</h3><button class="btn btn-sm" data-act="cat-new" data-type="expense">+</button></div>' + catRows('expense') + '</div>' +
            '<div class="card"><div class="card-head"><h3>Kategori pemasukan</h3><button class="btn btn-sm" data-act="cat-new" data-type="income">+</button></div>' + catRows('income') + '</div>' +
          '</div>' +
        '</div>' +

        '<div class="stack">' +
          securityCardHtml() +

          '<div class="card"><div class="card-head"><h2>Data & cadangan</h2></div>' +
            '<p class="small dim">Semua data tersimpan di <code>data/db.php</code> pada server ini. Unduh cadangan secara berkala; foto struk ada di <code>data/struk/</code>.</p>' +
            '<div class="stack" style="gap:8px">' +
              '<a class="btn btn-block" href="' + API + '?action=backup.download">⬇ Unduh cadangan (JSON)</a>' +
              '<label class="btn btn-block" style="cursor:pointer">⬆ Pulihkan dari berkas<input type="file" accept="application/json,.json" id="restoreInput" hidden></label>' +
              '<button class="btn btn-block" data-act="data-demo">🧪 Muat data contoh (sintetis)</button>' +
              '<button class="btn btn-block btn-danger" data-act="data-reset">Hapus semua data</button>' +
            '</div>' +
            '<div class="divider"></div>' +
            '<p class="tiny muted" style="margin:0">Pemulihan akan mengganti seluruh data saat ini. Cadangan otomatis dibuat setiap kali data berubah (maksimal 40 berkas terakhir di <code>backup/data</code>).</p>' +
          '</div>' +

          '<div class="card"><div class="card-head"><h2>Scan struk belanja</h2></div>' +
            '<p class="small dim" style="margin:0 0 8px">Foto struk dibaca di perangkat Anda (OCR bahasa Indonesia), lalu diisi otomatis menjadi transaksi. ' +
              (function () { const n = DB.transactions.filter((t) => t.receipt).length; return n ? n + ' transaksi sudah punya foto struk.' : 'Belum ada transaksi dengan foto struk.'; })() +
            '</p>' +
            '<button class="btn btn-block" data-act="scan-open">📷 Scan struk sekarang</button>' +
            '<p class="tiny muted" style="margin:8px 0 0">Foto disimpan di <code>data/struk/</code> (maksimal 1,5 MB per struk, sudah diperkecil otomatis) dan hanya bisa dibuka lewat aplikasi ini. Foto ikut terhapus bila transaksinya dihapus.</p>' +
          '</div>' +

          accountRulesCardHtml() +

          interestCardHtml() +

          '<div class="card"><div class="card-head"><h2>Emas digital (Lakuemas)</h2></div>' +
            '<p class="small dim" style="margin:0 0 8px">Harga beli/jual per gram diambil dari Lakuemas, lalu pembelian dicatat sebagai pengeluaran. ' +
              (function () { const n = (DB.gold || []).length; return n ? n + ' pembelian tercatat.' : 'Belum ada pembelian tercatat.'; })() +
            '</p>' +
            '<button class="btn btn-block" data-act="go-gold">🪙 Buka halaman emas</button>' +
            '<p class="tiny muted" style="margin:8px 0 0">Harga hanya acuan; transaksi resmi tetap terjadi di aplikasi Lakuemas.</p>' +
          '</div>' +

          '<div class="card"><div class="card-head"><h2>Tentang</h2></div>' +
            '<p class="small dim" style="margin:0 0 6px">Versi <strong>' + esc(window.__APP__ ? window.__APP__.version : '') + '</strong> · tanpa login · tanpa database.</p>' +
            '<p class="tiny muted" style="margin:0">Aplikasi ini berjalan sepenuhnya di hosting Anda. Tidak ada data yang dikirim ke pihak ketiga. Karena tanpa login, lindungi folder ini (mis. lewat password direktori hosting) bila dipasang di internet.</p>' +
          '</div>' +
        '</div>' +
      '</div>';
  }

  /* ---------------------------------------------------------- scan struk */

  const SCAN_PATHS = {
    lib: 'assets/ocr/tesseract.min.js',
    worker: 'assets/ocr/worker.min.js',
    core: 'assets/ocr/',
    lang: 'assets/ocr/lang'
  };

  // URL absolut wajib: worker OCR dijalankan dari blob dan tidak bisa
  // menyelesaikan path relatif terhadap halaman.
  function scanAbs(path) {
    try { return new URL(path, window.location.href).href; } catch (e) { return path; }
  }
  let tesseractPromise = null;
  let ocrWorker = null;
  const scanState = { photo: '', ocrCanvas: null, text: '', parsed: null, busy: false, type: 'expense', accountAuto: true };

  function resetScanState() {
    scanState.photo = '';
    scanState.ocrCanvas = null;
    scanState.text = '';
    scanState.parsed = null;
    scanState.busy = false;
    scanState.type = 'expense';
    scanState.accountAuto = true;
  }

  function fileToImage(file) {
    return new Promise((resolve, reject) => {
      if (!file || !/^image\//i.test(file.type || '')) {
        reject(new Error('Pilih berkas gambar (JPG atau PNG).'));
        return;
      }
      const url = URL.createObjectURL(file);
      const img = new Image();
      img.onload = () => { URL.revokeObjectURL(url); resolve(img); };
      img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('Gambar tidak dapat dibaca. Coba format JPG/PNG.')); };
      img.src = url;
    });
  }

  // Foto diperkecil agar hemat ruang, lalu dibuat salinan kontras tinggi untuk OCR.
  function prepareScanImage(img) {
    const srcW = img.naturalWidth || img.width || 1;
    const srcH = img.naturalHeight || img.height || 1;
    const scale = Math.min(1, 1600 / Math.max(srcW, srcH));
    const w = Math.max(1, Math.round(srcW * scale));
    const h = Math.max(1, Math.round(srcH * scale));

    const canvas = document.createElement('canvas');
    canvas.width = w;
    canvas.height = h;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, w, h);
    ctx.drawImage(img, 0, 0, w, h);
    const photo = canvas.toDataURL('image/jpeg', 0.72);

    const up = w < 900 ? Math.min(2, 900 / w) : 1;
    const ocr = document.createElement('canvas');
    ocr.width = Math.round(w * up);
    ocr.height = Math.round(h * up);
    const octx = ocr.getContext('2d');
    octx.drawImage(canvas, 0, 0, ocr.width, ocr.height);
    try {
      const data = octx.getImageData(0, 0, ocr.width, ocr.height);
      const px = data.data;
      for (let i = 0; i < px.length; i += 4) {
        const g = 0.299 * px[i] + 0.587 * px[i + 1] + 0.114 * px[i + 2];
        const v = g < 128 ? Math.max(0, g - 45) : Math.min(255, g + 45);
        px[i] = v; px[i + 1] = v; px[i + 2] = v;
      }
      octx.putImageData(data, 0, 0);
    } catch (e) {
      // Kanvas tidak bisa dibaca (mis. gambar lintas domain): pakai apa adanya.
    }
    return { photo: photo, ocr: ocr };
  }

  function loadTesseract() {
    if (window.Tesseract) return Promise.resolve(window.Tesseract);
    if (!tesseractPromise) {
      tesseractPromise = new Promise((resolve, reject) => {
        const s = document.createElement('script');
        s.src = scanAbs(SCAN_PATHS.lib);
        s.async = true;
        s.onload = () => { window.Tesseract ? resolve(window.Tesseract) : reject(new Error('Mesin OCR tidak siap dipakai.')); };
        s.onerror = () => reject(new Error('Mesin OCR gagal dimuat. Pastikan folder assets/ocr lengkap.'));
        document.head.appendChild(s);
      });
    }
    return tesseractPromise;
  }

  async function ocrImage(canvas, onProgress) {
    const T = await loadTesseract();
    if (!ocrWorker) {
      onProgress(0.03, 'Menyiapkan mesin OCR (± beberapa detik pertama kali)…');
      ocrWorker = await T.createWorker('ind', 1, {
        workerPath: scanAbs(SCAN_PATHS.worker),
        corePath: scanAbs(SCAN_PATHS.core),
        langPath: scanAbs(SCAN_PATHS.lang),
        workerBlobURL: false,
        gzip: false,
        cacheMethod: 'none',
        logger: (m) => {
          if (m && m.status === 'recognizing text') {
            onProgress(0.15 + 0.82 * (m.progress || 0), 'Membaca teks struk…');
          }
        }
      });
    }
    const res = await ocrWorker.recognize(canvas);
    const text = res && res.data && res.data.text ? res.data.text : '';
    return String(text).trim();
  }


  function scanEmptyResult() {
    return { merchant: '', date: todayISO(), total: 0, items: [], warnings: [], categoryId: '', raw: '' };
  }

  function scanCatOptions(type) {
    return categories(type === 'income' ? 'income' : 'expense')
      .map((c) => '<option value="' + esc(c.id) + '">' + esc(c.icon + ' ' + c.name) + '</option>').join('');
  }

  function scanModalHtml() {
    const accOpts = accounts().map((a) => '<option value="' + esc(a.id) + '">' + esc((a.icon || '') + ' ' + a.name) + '</option>').join('');
    return '' +
      '<div id="scanStep1">' +
        '<p class="small dim" style="margin-top:0">Pilih <strong>foto struk belanja</strong> atau <strong>tangkapan layar pembayaran QRIS/DANA Business</strong>. Teks dibaca di perangkat Anda; hanya hasilnya yang dikirim ke server ini.</p>' +
        '<div class="scan-sources">' +
          '<label class="scan-drop compact" for="scanFile">' +
            '<span class="scan-drop-icon">📷</span>' +
            '<strong>Kamera</strong>' +
            '<small>Foto langsung</small>' +
          '</label>' +
          '<label class="scan-drop compact" for="scanGallery">' +
            '<span class="scan-drop-icon">🖼️</span>' +
            '<strong>Galeri</strong>' +
            '<small>Pilih dari penyimpanan</small>' +
          '</label>' +
        '</div>' +
        '<p class="small dim" style="margin:8px 0 0">Format JPG, PNG, atau WebP. Foto HEIC (iPhone) sebaiknya diubah dulu ke JPG.</p>' +
        '<input type="file" id="scanFile" accept="image/*" capture="environment" hidden>' +
        '<input type="file" id="scanGallery" accept="image/*" hidden>' +
        '<div class="scan-preview" id="scanPreview" hidden>' +
          '<img id="scanPreviewImg" alt="Pratinjau gambar">' +
          '<button class="btn btn-sm" type="button" id="scanClear">Ganti gambar</button>' +
        '</div>' +
        '<div class="scan-actions">' +
          '<button class="btn btn-primary" type="button" id="scanRun" disabled>Baca struk</button>' +
          '<button class="btn btn-sm btn-ghost" type="button" id="scanManual">Isi manual</button>' +
          '<span class="tiny muted" id="scanHint">Belum ada gambar.</span>' +
        '</div>' +
        '<div class="scan-progress" id="scanProgress" hidden>' +
          '<div class="progress"><span id="scanBar" style="width:0%"></span></div>' +
          '<p class="tiny muted" id="scanStatus">Menyiapkan…</p>' +
        '</div>' +
      '</div>' +
      '<div id="scanStep2" hidden>' +
        '<div class="scan-info" id="scanInfo"></div>' +
        '<div class="field-full" style="margin:10px 0 2px"><div class="type-toggle" id="scanTypeToggle">' +
          '<label><input type="radio" name="scanType" value="expense"><span>− Pengeluaran</span></label>' +
          '<label><input type="radio" name="scanType" value="income"><span>+ Pemasukan</span></label>' +
        '</div></div>' +
        '<ul class="scan-warn" id="scanWarn" hidden></ul>' +
        '<form id="scanForm"><div class="form-grid">' +
          '<div class="field field-full"><label for="scanMerchant" id="scanMerchantLabel">Nama toko</label><input id="scanMerchant" name="merchant" maxlength="80" placeholder="mis. Indomaret"></div>' +
          '<div class="field field-full"><label for="scanAmount" id="scanAmountLabel">Total belanja</label><input id="scanAmount" class="input-money" name="amount" inputmode="numeric" data-money placeholder="0" required></div>' +
          '<div class="field"><label for="scanDate">Tanggal</label><input id="scanDate" type="date" name="date" required></div>' +
          '<div class="field"><label for="scanCat">Kategori</label><select id="scanCat" name="categoryId">' + scanCatOptions('expense') + '</select></div>' +
          '<div class="field"><label for="scanAcc">Sumber rekening</label><select id="scanAcc" name="accountId">' + accOpts + '</select></div>' +
          '<div class="field"><label for="scanNote">Keterangan</label><input id="scanNote" name="note" maxlength="300" placeholder="mis. belanja mingguan"></div>' +
        '</div>' +
        '<div class="scan-items-head" id="scanItemsHead"><strong class="small">Rincian barang</strong>' +
          '<label class="check tiny"><input type="checkbox" name="includeItems" checked> simpan rincian</label></div>' +
        '<div class="items-table-wrap" id="scanItemsWrap"><table class="items-table"><thead><tr><th>Barang</th><th>Qty</th><th>Total</th></tr></thead><tbody id="scanItems"></tbody></table></div>' +
        '<p class="tiny muted" id="scanItemsSum" style="margin:6px 0 0"></p>' +
        '<details class="scan-raw"><summary>Teks hasil pembacaan</summary><pre id="scanRawText"></pre></details>' +
        '<label class="check small"><input type="checkbox" name="keepPhoto" checked> Simpan foto pada transaksi</label>' +
        '</form>' +
      '</div>';
  }

  function scanItemRow(it) {
    const qty = Math.max(1, parseInt(it.qty || 1, 10) || 1);
    const total = Math.round(num(it.total != null ? it.total : it.price));
    return '<tr data-row="1">' +
      '<td><input class="scan-item-name" value="' + esc(it.name || '') + '" maxlength="80" aria-label="Nama barang"></td>' +
      '<td><input class="scan-item-qty" type="number" min="1" max="999" value="' + qty + '" aria-label="Jumlah"></td>' +
      '<td><input class="scan-item-total" inputmode="numeric" value="' + (total ? total.toLocaleString('id-ID') : '') + '" aria-label="Total harga"></td>' +
      '</tr>';
  }

  function renderScanItems(items) {
    const body = document.getElementById('scanItems');
    if (!body) return;
    const list = Array.isArray(items) ? items : [];
    body.innerHTML = list.length
      ? list.map(scanItemRow).join('')
      : '<tr><td colspan="3" class="tiny muted">Rincian barang tidak terbaca — isi total belanja secara manual.</td></tr>';
    scanItemsSum();
  }

  function scanItemsSum() {
    const el = document.getElementById('scanItemsSum');
    if (!el) return;
    const items = readScanItems();
    if (!items.length) { el.textContent = ''; return; }
    const sum = items.reduce((s, it) => s + num(it.total), 0);
    el.textContent = items.length + ' barang · jumlah rincian ' + money(sum);
  }

  function readScanItems() {
    return [...document.querySelectorAll('#scanItems tr[data-row]')].map((tr) => {
      const nameEl = tr.querySelector('.scan-item-name');
      const qtyEl = tr.querySelector('.scan-item-qty');
      const totalEl = tr.querySelector('.scan-item-total');
      const qty = Math.max(1, parseInt((qtyEl && qtyEl.value) || '1', 10) || 1);
      const total = parseMoney(totalEl ? totalEl.value : '');
      return { name: ((nameEl && nameEl.value) || '').trim(), qty: qty, total: total, price: Math.round(total / qty) };
    }).filter((it) => it.name && it.total > 0);
  }

  function accountRuleFor(catId) {
    const rules = (DB.settings && DB.settings.accountRules) || {};
    return rules[catId] || '';
  }

  function applyAccountRule(catSel, accSel) {
    if (!catSel || !accSel) return false;
    const id = accountRuleFor(catSel.value);
    if (id && [...accSel.options].some((o) => o.value === id)) {
      accSel.value = id;
      return true;
    }
    return false;
  }

  function syncScanType(type, p) {
    const res = p || {};
    const accSel = document.getElementById('scanAcc');
    const catSel = document.getElementById('scanCat');
    const t = type === 'income' ? 'income' : 'expense';
    scanState.type = t;
    document.querySelectorAll('#scanTypeToggle input').forEach((r) => { r.checked = r.value === t; });
    if (catSel) {
      const keep = res.categoryId || catSel.value;
      catSel.innerHTML = scanCatOptions(t);
      if (keep && [...catSel.options].some((o) => o.value === keep)) {
        catSel.value = keep;
      } else {
        // Dahulukan kategori yang sudah punya aturan akun.
        const beraturan = [...catSel.options].find((o) => accountRuleFor(o.value));
        if (beraturan) catSel.value = beraturan.value;
      }
    }
    if (accSel && res.accountId && [...accSel.options].some((o) => o.value === res.accountId)) {
      accSel.value = res.accountId;
      scanState.accountAuto = false;
    } else if (catSel && accSel && scanState.accountAuto) {
      applyAccountRule(catSel, accSel);
    }
    const mLabel = document.getElementById('scanMerchantLabel');
    const aLabel = document.getElementById('scanAmountLabel');
    const mInput = document.getElementById('scanMerchant');
    const nInput = document.getElementById('scanNote');
    if (t === 'income') {
      if (mLabel) mLabel.textContent = 'Nama pengirim / keterangan';
      if (aLabel) aLabel.textContent = 'Nominal masuk';
      if (mInput) mInput.placeholder = 'mis. pembeli QRIS';
      if (nInput) nInput.placeholder = 'mis. pembayaran QRIS';
    } else {
      if (mLabel) mLabel.textContent = 'Nama toko';
      if (aLabel) aLabel.textContent = 'Total belanja';
      if (mInput) mInput.placeholder = 'mis. Indomaret';
      if (nInput) nInput.placeholder = 'mis. belanja mingguan';
    }
    const head = document.getElementById('scanItemsHead');
    const wrap = document.getElementById('scanItemsWrap');
    if (head) head.hidden = t === 'income';
    if (wrap) wrap.hidden = t === 'income';
  }

  function fillScanForm(p) {
    const setVal = (id, v) => { const el = document.getElementById(id); if (el) el.value = v; };
    const res = p || scanEmptyResult();
    const type = res.type === 'income' ? 'income' : 'expense';
    scanState.accountAuto = !res.accountId;
    setVal('scanMerchant', res.merchant || '');
    setVal('scanDate', res.date || todayISO());
    setVal('scanAmount', res.total ? Math.round(num(res.total)).toLocaleString('id-ID') : '');
    setVal('scanNote', res.sender || res.merchant || (type === 'income' ? 'Pemasukan QRIS' : 'Belanja'));
    syncScanType(type, res);
    renderScanItems(res.items || []);
    const raw = document.getElementById('scanRawText');
    if (raw) raw.textContent = (res.raw || scanState.text || '').trim() || '(tidak ada teks)';
    const warn = document.getElementById('scanWarn');
    if (warn) {
      const list = Array.isArray(res.warnings) ? res.warnings.filter(Boolean) : [];
      warn.hidden = list.length === 0;
      warn.innerHTML = list.map((w) => '<li>' + esc(w) + '</li>').join('');
    }
    const info = document.getElementById('scanInfo');
    if (info) {
      const parts = [];
      if (res.kind === 'qris') parts.push('<span class="pill">QRIS masuk</span>');
      if (res.merchant) parts.push('<strong>' + esc(res.merchant) + '</strong>');
      if (res.total) parts.push((type === 'income' ? 'nominal terbaca ' : 'total terbaca ') + '<strong>' + money(res.total) + '</strong>');
      if (type === 'expense' && Array.isArray(res.items) && res.items.length) parts.push(res.items.length + ' barang');
      info.innerHTML = parts.length ? parts.join(' · ') : 'Isi data transaksi secara manual di bawah.';
    }
  }

  function showScanStep(p) {
    const step1 = document.getElementById('scanStep1');
    const step2 = document.getElementById('scanStep2');
    if (!step2) return;
    if (step1) step1.hidden = true;
    step2.hidden = false;
    fillScanForm(p);
    const submit = document.getElementById('modalSubmit');
    if (submit) { submit.disabled = false; submit.textContent = 'Simpan transaksi'; }
    const amount = document.getElementById('scanAmount');
    if (amount) setTimeout(() => amount.focus(), 60);
  }


  function bindScanModal() {
    const camera = document.getElementById('scanFile');
    const gallery = document.getElementById('scanGallery');
    const preview = document.getElementById('scanPreview');
    const previewImg = document.getElementById('scanPreviewImg');
    const hint = document.getElementById('scanHint');
    const runBtn = document.getElementById('scanRun');
    if (!camera || !runBtn || !preview) return;

    const handleFile = async (file, input) => {
      if (!file) return;
      runBtn.disabled = true;
      hint.textContent = 'Menyiapkan gambar…';
      try {
        const img = await fileToImage(file);
        const prepared = prepareScanImage(img);
        scanState.photo = prepared.photo;
        scanState.ocrCanvas = prepared.ocr;
        scanState.parsed = null;
        previewImg.src = prepared.photo;
        preview.hidden = false;
        runBtn.disabled = false;
        runBtn.textContent = 'Baca struk';
        const kb = Math.max(1, Math.round((prepared.photo.length * 0.75) / 1024));
        hint.textContent = 'Gambar siap dibaca (±' + kb + ' KB).';
      } catch (err) {
        hint.textContent = err.message || 'Gambar tidak dapat dibaca.';
        toast(err.message || 'Gambar tidak dapat dibaca.', 'err');
      } finally {
        if (input) input.value = '';
      }
    };

    [camera, gallery].forEach((input) => {
      if (!input) return;
      input.addEventListener('change', () => {
        const f = input.files && input.files[0];
        handleFile(f, input);
      });
    });

    const clear = document.getElementById('scanClear');
    if (clear) {
      clear.addEventListener('click', () => {
        scanState.photo = '';
        scanState.ocrCanvas = null;
        scanState.parsed = null;
        camera.value = '';
        if (gallery) gallery.value = '';
        preview.hidden = true;
        runBtn.disabled = true;
        runBtn.textContent = 'Baca struk';
        hint.textContent = 'Belum ada gambar.';
        const progress = document.getElementById('scanProgress');
        if (progress) progress.hidden = true;
      });
    }

    document.querySelectorAll('#scanTypeToggle input').forEach((r) => {
      r.addEventListener('change', () => {
        scanState.accountAuto = true;
        syncScanType(r.value, null);
      });
    });

    const catSel = document.getElementById('scanCat');
    const accSel = document.getElementById('scanAcc');
    if (catSel) catSel.addEventListener('change', () => { if (scanState.accountAuto) applyAccountRule(catSel, accSel); });
    if (accSel) accSel.addEventListener('change', () => { scanState.accountAuto = false; });

    runBtn.addEventListener('click', runScan);
    const manual = document.getElementById('scanManual');
    if (manual) manual.addEventListener('click', () => {
      scanState.parsed = scanEmptyResult();
      showScanStep(scanState.parsed);
      syncScanType(scanState.type, scanState.parsed);
    });
  }

  async function runScan() {
    if (scanState.busy) return;
    if (!scanState.ocrCanvas) { toast('Pilih foto struk dulu.', 'err'); return; }
    scanState.busy = true;
    const runBtn = document.getElementById('scanRun');
    const progress = document.getElementById('scanProgress');
    const bar = document.getElementById('scanBar');
    const status = document.getElementById('scanStatus');
    if (runBtn) { runBtn.disabled = true; runBtn.textContent = 'Membaca…'; }
    if (progress) progress.hidden = false;
    const setProgress = (p, label) => {
      if (bar) bar.style.width = Math.round(clamp(p, 0, 1) * 100) + '%';
      if (status && label) status.textContent = label;
    };
    setProgress(0.02, 'Menyiapkan mesin OCR…');
    try {
      const text = await ocrImage(scanState.ocrCanvas, setProgress);
      if (!text) throw new Error('Tulisan pada struk tidak terbaca. Coba foto lebih terang, atau isi manual.');
      scanState.text = text;
      setProgress(0.96, 'Mencocokkan nominal & tanggal…');
      const parsed = await call('receipt.parse', { text: text });
      scanState.parsed = parsed || scanEmptyResult();
      showScanStep(scanState.parsed);
      setProgress(1, 'Selesai.');
      toast('Struk terbaca. Periksa hasilnya sebelum menyimpan.');
    } catch (err) {
      const msg = (err && err.message) || 'Gagal membaca struk.';
      if (status) status.textContent = msg;
      toast(msg, 'err');
      if (runBtn) { runBtn.disabled = false; runBtn.textContent = 'Baca ulang'; }
    } finally {
      scanState.busy = false;
    }
  }

  function openScanModal() {
    resetScanState();
    openModal({
      title: 'Scan struk belanja',
      submitLabel: 'Simpan transaksi',
      body: scanModalHtml(),
      onSubmit: async (data) => {
        if (!scanState.parsed) throw new Error('Tekan “Baca struk” dulu agar hasilnya bisa disimpan.');
        const amount = parseMoney(data.amount);
        if (!amount) throw new Error('Total belanja wajib diisi dan lebih dari 0.');
        const items = data.includeItems ? readScanItems() : [];
        const isIncome = scanState.type === 'income';
        const payload = {
          type: isIncome ? 'income' : 'expense',
          amount: amount,
          date: data.date || todayISO(),
          accountId: data.accountId,
          categoryId: data.categoryId,
          note: (data.note || '').trim(),
          merchant: (data.merchant || '').trim(),
          items: isIncome ? [] : items,
          sender: isIncome ? (data.merchant || '').trim() : ''
        };
        if (scanState.photo && data.keepPhoto) payload.receipt = scanState.photo;
        await call('tx.create', payload);
        resetScanState();
        toast(isIncome ? 'Pemasukan QRIS tersimpan.' : 'Transaksi dari struk tersimpan.');
        render();
      }
    });
    const submit = document.getElementById('modalSubmit');
    if (submit) submit.disabled = true;
    bindScanModal();
  }

  function openReceiptModal(id) {
    const t = DB.transactions.find((x) => x.id === id);
    if (!t || !t.receipt) { toast('Transaksi ini tidak memiliki foto struk.', 'err'); return; }
    const cat = catById(t.categoryId);
    const items = Array.isArray(t.items) ? t.items : [];
    const itemsHtml = items.length
      ? '<div class="items-table-wrap"><table class="items-table"><thead><tr><th>Barang</th><th>Qty</th><th>Total</th></tr></thead><tbody>' +
        items.map((it) => '<tr><td>' + esc(it.name || '') + '</td><td>' + num(it.qty || 1) + '</td><td class="mono">' + money(it.total != null ? it.total : it.price) + '</td></tr>').join('') +
        '</tbody></table></div>'
      : '';
    openModal({
      title: 'Foto struk',
      submitLabel: 'Tutup',
      body: '<div class="receipt-view">' +
          '<img class="receipt-img" src="' + API + '?action=receipt.get&id=' + encodeURIComponent(id) + '" alt="Foto struk ' + esc(t.merchant || '') + '">' +
          '<div class="receipt-summary">' +
            '<div class="row-between"><span class="small dim">' + esc(dateLabel(t.date, true)) + '</span><strong class="mono">' + money(t.amount) + '</strong></div>' +
            (t.merchant ? '<div class="small">🏪 ' + esc(t.merchant) + '</div>' : '') +
            '<div class="small muted">' + esc(cat ? cat.name : 'Tanpa kategori') + (t.note ? ' · ' + esc(t.note) : '') + '</div>' +
          '</div>' + itemsHtml +
        '</div>',
      onSubmit: async () => {}
    });
  }

  /* ------------------------------------------------------------- actions */

  const views = { dashboard: viewDashboard, transactions: viewTransactions, budgets: viewBudgets, goals: viewGoals, debts: viewDebts, reports: viewReports, gold: viewGold, settings: viewSettings };

  function render() {
    const view = views[state.view] || viewDashboard;
    document.getElementById('view').innerHTML = view();
    document.querySelectorAll('.nav-item').forEach((b) => b.classList.toggle('active', b.dataset.view === state.view));
    document.getElementById('demoBanner').hidden = !DB.demo;
    applyChrome();
  }

  function applyChrome() {
    const name = (DB.settings && DB.settings.appName) || 'Uang';
    document.getElementById('brandName').textContent = name;
    document.getElementById('brandOwner').textContent = (DB.settings && DB.settings.ownerName) || 'Keuangan pribadi';
    document.title = name + ' — Keuangan Pribadi';
    applyTheme(DB.settings.theme);
  }

  function applyTheme(theme) {
    const t = theme === 'light' ? 'light' : 'dark';
    document.documentElement.dataset.theme = t;
    document.getElementById('btnTheme').textContent = t === 'dark' ? '🌙' : '☀️';
  }

  function go(view) {
    state.view = view;
    if (view === 'transactions') { state.tx.month = state.month; }
    if (view === 'reports') { state.reportMonth = state.month; }
    render();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  function txFormHtml(t, forcedType) {
    const type = forcedType || (t && t.type) || 'expense';
    const date = (t && t.date) || todayISO();
    const amount = t ? num(t.amount) : '';
    const accId = (t && t.accountId) || (accounts()[0] || {}).id || '';
    const toId = (t && t.toAccountId) || ((accounts()[1] || accounts()[0] || {}).id || '');
    const catList = (tp) => categories(tp).map((c) => '<option value="' + esc(c.id) + '"' + (t && t.categoryId === c.id ? ' selected' : '') + '>' + esc(c.icon + ' ' + c.name) + '</option>').join('');
    const accOpts = (sel) => accounts().map((a) => '<option value="' + esc(a.id) + '"' + (a.id === sel ? ' selected' : '') + '>' + esc((a.icon || '') + ' ' + a.name) + '</option>').join('');

    return '<form id="txForm">' +
      '<div class="field-full" style="margin-bottom:12px"><div class="type-toggle">' +
        '<label><input type="radio" name="type" value="expense"' + (type === 'expense' ? ' checked' : '') + '><span>− Pengeluaran</span></label>' +
        '<label><input type="radio" name="type" value="income"' + (type === 'income' ? ' checked' : '') + '><span>+ Pemasukan</span></label>' +
        '<label><input type="radio" name="type" value="transfer"' + (type === 'transfer' ? ' checked' : '') + '><span>🔁 Transfer</span></label>' +
      '</div></div>' +
      '<div class="form-grid">' +
        '<div class="field field-full"><label for="fAmount">Nominal</label><input id="fAmount" class="input-money" name="amount" inputmode="numeric" data-money placeholder="0" value="' + (amount ? num(amount).toLocaleString('id-ID') : '') + '" required></div>' +
        '<div class="field"><label for="fDate">Tanggal</label><input id="fDate" type="date" name="date" value="' + esc(date) + '" required></div>' +
        '<div class="field" id="fCatWrap"><label for="fCat">Kategori</label><select id="fCat" name="categoryId">' + catList(type === 'transfer' ? 'expense' : type) + '</select></div>' +
        '<div class="field"><label for="fAcc">Sumber rekening</label><select id="fAcc" name="accountId">' + accOpts(accId) + '</select></div>' +
        '<div class="field" id="fToWrap"><label for="fTo">Rekening tujuan</label><select id="fTo" name="toAccountId">' + accOpts(toId) + '</select></div>' +
        '<div class="field field-full"><label for="fNote">Keterangan <span class="muted">(opsional)</span></label><input id="fNote" name="note" maxlength="300" placeholder="mis. makan siang" value="' + esc(t ? t.note : '') + '"></div>' +
        (t && t.receipt
          ? '<div class="field field-full"><label>Foto struk</label>' +
            '<div class="scan-preview"><img src="' + API + '?action=receipt.get&id=' + encodeURIComponent(t.id) + '" alt="Foto struk"></div>' +
            '<label class="check small" style="margin-top:6px"><input type="checkbox" name="removeReceipt"> Hapus foto struk ini</label></div>'
          : '') +
      '</div></form>';
  }

  function bindTxForm(t) {
    const form = document.getElementById('txForm');
    if (!form) return;
    const catSel = form.querySelector('#fCat');
    const accSel = form.querySelector('#fAcc');
    // Transaksi baru: akun mengikuti aturan kategori sampai pengguna memilih sendiri.
    let autoAcc = !t;

    const sync = () => {
      const type = form.querySelector('input[name=type]:checked').value;
      const catWrap = form.querySelector('#fCatWrap');
      const toWrap = form.querySelector('#fToWrap');
      catWrap.classList.toggle('hidden', type === 'transfer');
      toWrap.classList.toggle('hidden', type !== 'transfer');
      const keep = catSel.value;
      catSel.innerHTML = categories(type).map((c) => '<option value="' + esc(c.id) + '">' + esc(c.icon + ' ' + c.name) + '</option>').join('');
      if ([...catSel.options].some((o) => o.value === keep)) catSel.value = keep;
      if (autoAcc) applyAccountRule(catSel, accSel);
      form.querySelector('#fAmount').focus();
    };

    if (accSel) accSel.addEventListener('change', () => { autoAcc = false; });
    if (catSel) catSel.addEventListener('change', () => { if (autoAcc) applyAccountRule(catSel, accSel); });
    form.querySelectorAll('input[name=type]').forEach((r) => r.addEventListener('change', () => { autoAcc = !t; sync(); }));
    sync();
  }

  function openTxModal(id, forcedType) {
    const t = id ? DB.transactions.find((x) => x.id === id) : null;
    openModal({
      title: t ? 'Ubah transaksi' : (forcedType === 'transfer' ? 'Transfer antar akun' : 'Catat transaksi'),
      submitLabel: t ? 'Simpan perubahan' : 'Simpan',
      body: txFormHtml(t, forcedType),
      onSubmit: async (data) => {
        const type = data.type;
        const payload = {
          type: type,
          amount: parseMoney(data.amount),
          date: data.date,
          accountId: data.accountId,
          toAccountId: type === 'transfer' ? data.toAccountId : '',
          categoryId: type === 'transfer' ? '' : data.categoryId,
          note: data.note || ''
        };
        if (!payload.amount) throw new Error('Jumlah wajib diisi dan lebih dari 0.');
        if (id && data.removeReceipt) payload.receipt = '';
        if (id) { payload.id = id; await call('tx.update', payload); toast('Transaksi diperbarui.'); }
        else { await call('tx.create', payload); toast('Transaksi tersimpan.'); }
        render();
      }
    });
    bindTxForm(t);
  }

  /* ------------- modal builders ------------- */

  function openBudgetModal(catId) {
    const ym = state.month;
    const cats = categories('expense');
    if (!cats.length) return toast('Tambahkan kategori pengeluaran dulu di Pengaturan.', 'err');
    const preselect = catId || cats[0].id;
    openModal({
      title: 'Atur anggaran ' + monthName(ym),
      submitLabel: 'Simpan',
      body: '<form id="bgForm"><div class="form-grid">' +
        '<div class="field field-full"><label for="bgCat">Kategori pengeluaran</label><select id="bgCat" name="categoryId">' +
          cats.map((c) => '<option value="' + esc(c.id) + '"' + (c.id === preselect ? ' selected' : '') + '>' + esc(c.icon + ' ' + c.name) + '</option>').join('') + '</select></div>' +
        '<div class="field field-full"><label for="bgAmount">Batas bulanan</label><input id="bgAmount" class="input-money" name="amount" inputmode="numeric" data-money placeholder="0" required></div>' +
        '<p class="field-full tiny muted" style="margin:0">Anggaran hanya untuk bulan ' + esc(monthName(ym)) + '. Bulan lain diatur terpisah.</p>' +
      '</div></form>',
      onSubmit: async (data) => {
        const amount = parseMoney(data.amount);
        if (!amount) throw new Error('Nominal anggaran wajib diisi.');
        await call('budget.set', { month: ym, categoryId: data.categoryId, amount });
        toast('Anggaran disimpan.');
        render();
      }
    });
  }

  function openGoalModal(id) {
    const g = id ? DB.goals.find((x) => x.id === id) : null;
    openModal({
      title: g ? 'Ubah target' : 'Target tabungan baru',
      submitLabel: 'Simpan',
      body: '<form id="goalForm"><div class="form-grid">' +
        '<div class="field"><label for="gIcon">Ikon</label><input id="gIcon" name="icon" maxlength="4" value="' + esc(g ? g.icon : '🎯') + '"></div>' +
        '<div class="field"><label for="gName">Nama target</label><input id="gName" name="name" maxlength="80" value="' + esc(g ? g.name : '') + '" placeholder="mis. Dana Darurat" required></div>' +
        '<div class="field"><label for="gTarget">Nominal target</label><input id="gTarget" class="input-money" name="target" inputmode="numeric" data-money value="' + (g ? num(g.target).toLocaleString('id-ID') : '') + '" required></div>' +
        '<div class="field"><label for="gDeadline">Tenggat <span class="muted">(opsional)</span></label><input id="gDeadline" type="date" name="deadline" value="' + esc(g ? g.deadline : '') + '"></div>' +
        '<div class="field"><label for="gColor">Warna</label><input id="gColor" type="color" name="color" value="' + esc(g ? g.color : '#38bdf8') + '" style="height:40px;padding:3px"></div>' +
        '<div class="field"><label for="gNote">Catatan</label><input id="gNote" name="note" maxlength="200" value="' + esc(g ? g.note : '') + '"></div>' +
      '</div></form>',
      onSubmit: async (data) => {
        const payload = { name: data.name, target: parseMoney(data.target), deadline: data.deadline, color: data.color, icon: data.icon || '🎯', note: data.note || '' };
        if (!payload.target) throw new Error('Nominal target wajib diisi.');
        if (g) { payload.id = g.id; await call('goal.update', payload); toast('Target diperbarui.'); }
        else { await call('goal.create', payload); toast('Target dibuat.'); }
        render();
      }
    });
  }

  function openGoalEntryModal(id) {
    const g = DB.goals.find((x) => x.id === id);
    if (!g) return;
    const saved = goalSaved(id);
    const inDir = arguments[1] !== 'out';
    openModal({
      title: (inDir ? 'Setor ke ' : 'Tarik dari ') + g.name,
      submitLabel: inDir ? 'Setor' : 'Tarik',
      body: '<form id="geForm"><div class="form-grid">' +
        '<p class="field-full small dim" style="margin:0">Terkumpul saat ini <strong>' + money(saved) + '</strong> dari target ' + money(g.target) + '.</p>' +
        '<div class="field"><label for="geAmount">Nominal</label><input id="geAmount" class="input-money" name="amount" inputmode="numeric" data-money required></div>' +
        '<div class="field"><label for="geDate">Tanggal</label><input id="geDate" type="date" name="date" value="' + todayISO() + '"></div>' +
        '<div class="field field-full"><label for="geNote">Catatan</label><input id="geNote" name="note" maxlength="200" placeholder="opsional"></div>' +
      '</div></form>',
      onSubmit: async (data) => {
        const amount = parseMoney(data.amount);
        if (!amount) throw new Error('Nominal wajib diisi.');
        await call('goal.entry', { goalId: id, dir: inDir ? 'in' : 'out', amount, date: data.date, note: data.note || '' });
        toast(inDir ? 'Setoran dicatat.' : 'Penarikan dicatat.');
        render();
      }
    });
  }

  function openGoalHistory(id) {
    const g = DB.goals.find((x) => x.id === id);
    if (!g) return;
    const entries = DB.goalEntries.filter((e) => e.goalId === id).sort((a, b) => b.date.localeCompare(a.date));
    openModal({
      title: 'Riwayat · ' + g.name,
      submitLabel: 'Tutup',
      body: entries.length
        ? '<div class="table-wrap"><table><thead><tr><th>Tanggal</th><th>Jenis</th><th class="num">Nominal</th><th>Catatan</th><th></th></tr></thead><tbody>' +
          entries.map((e) => '<tr><td>' + esc(dateLabel(e.date)) + '</td><td>' + (e.dir === 'out' ? '<span class="pill pill-expense">Tarik</span>' : '<span class="pill pill-income">Setor</span>') + '</td>' +
            '<td class="num">' + money(e.amount) + '</td><td class="small dim">' + esc(e.note || '—') + '</td>' +
            '<td class="num"><button class="icon-btn btn-sm" data-act="goal-entry-del" data-id="' + esc(e.id) + '" data-goal="' + esc(id) + '" title="Hapus">🗑</button></td></tr>').join('') +
          '</tbody></table></div>'
        : '<p class="muted small" style="margin:0">Belum ada setoran.</p>',
      onSubmit: async () => { /* hanya menutup */ }
    });
  }

  function openDebtModal(id, forcedType) {
    const d = id ? DB.debts.find((x) => x.id === id) : null;
    const type = (d && d.type) || forcedType || 'debt';
    openModal({
      title: (d ? 'Ubah' : 'Catat') + (type === 'debt' ? ' utang' : ' piutang'),
      submitLabel: 'Simpan',
      body: '<form id="debtForm"><div class="form-grid">' +
        '<div class="field"><label for="dPerson">Nama orang / pihak</label><input id="dPerson" name="person" maxlength="80" value="' + esc(d ? d.person : '') + '" required></div>' +
        '<div class="field"><label for="dAmount">Nominal</label><input id="dAmount" class="input-money" name="amount" inputmode="numeric" data-money value="' + (d ? num(d.amount).toLocaleString('id-ID') : '') + '" required></div>' +
        '<div class="field"><label for="dDate">Tanggal</label><input id="dDate" type="date" name="date" value="' + esc(d ? d.date : todayISO()) + '"' + (d ? ' readonly' : '') + '></div>' +
        '<div class="field"><label for="dDue">Jatuh tempo <span class="muted">(opsional)</span></label><input id="dDue" type="date" name="dueDate" value="' + esc(d ? d.dueDate : '') + '"></div>' +
        '<div class="field field-full"><label for="dNote">Catatan</label><input id="dNote" name="note" maxlength="200" value="' + esc(d ? d.note : '') + '"></div>' +
      '</div></form>',
      onSubmit: async (data) => {
        const amount = parseMoney(data.amount);
        if (!amount) throw new Error('Nominal wajib diisi.');
        if (!data.person.trim()) throw new Error('Nama wajib diisi.');
        const payload = { person: data.person, amount, dueDate: data.dueDate || '', note: data.note || '' };
        if (d) { payload.id = d.id; await call('debt.update', payload); toast('Catatan diperbarui.'); }
        else { payload.type = type; payload.date = data.date || todayISO(); await call('debt.create', payload); toast('Catatan dibuat.'); }
        render();
      }
    });
  }

  function openDebtPayModal(id) {
    const d = DB.debts.find((x) => x.id === id);
    if (!d) return;
    const remaining = num(d.amount) - debtPaid(d);
    openModal({
      title: (d.type === 'debt' ? 'Bayar utang · ' : 'Terima bayaran · ') + d.person,
      submitLabel: 'Simpan',
      body: '<form id="payForm"><div class="form-grid">' +
        '<p class="field-full small dim" style="margin:0">Sisa ' + money(remaining) + ' dari ' + money(d.amount) + '.</p>' +
        '<div class="field"><label for="pAmount">Nominal</label><input id="pAmount" class="input-money" name="amount" inputmode="numeric" data-money value="' + num(remaining).toLocaleString('id-ID') + '" required></div>' +
        '<div class="field"><label for="pDate">Tanggal</label><input id="pDate" type="date" name="date" value="' + todayISO() + '"></div>' +
        '<div class="field field-full"><label for="pNote">Catatan</label><input id="pNote" name="note" maxlength="200" placeholder="opsional"></div>' +
      '</div></form>',
      onSubmit: async (data) => {
        const amount = parseMoney(data.amount);
        if (!amount) throw new Error('Nominal wajib diisi.');
        await call('debt.pay', { id, amount, date: data.date, note: data.note || '' });
        toast('Pembayaran dicatat.');
        render();
      }
    });
  }

  function openDebtHistory(id) {
    const d = DB.debts.find((x) => x.id === id);
    if (!d) return;
    const pays = (d.payments || []).slice().sort((a, b) => String(b.date).localeCompare(String(a.date)));
    openModal({
      title: 'Riwayat pembayaran · ' + d.person,
      submitLabel: 'Tutup',
      body: pays.length ? '<div class="table-wrap"><table><thead><tr><th>Tanggal</th><th class="num">Nominal</th><th>Catatan</th><th></th></tr></thead><tbody>' +
        pays.map((p) => '<tr><td>' + esc(dateLabel(p.date)) + '</td><td class="num">' + money(p.amount) + '</td><td class="small dim">' + esc(p.note || '—') + '</td>' +
          '<td class="num"><button class="icon-btn btn-sm" data-act="debt-payment-del" data-id="' + esc(id) + '" data-payment="' + esc(p.id) + '" title="Hapus">🗑</button></td></tr>').join('') +
        '</tbody></table></div>' : '<p class="muted small" style="margin:0">Belum ada pembayaran.</p>',
      onSubmit: async () => { }
    });
  }

  function openAccModal(id) {
    const a = id ? DB.accounts.find((x) => x.id === id) : null;
    openModal({
      title: a ? 'Ubah akun' : 'Akun baru',
      submitLabel: 'Simpan',
      body: '<form id="accForm"><div class="form-grid">' +
        '<div class="field"><label for="aName">Nama akun</label><input id="aName" name="name" maxlength="60" value="' + esc(a ? a.name : '') + '" placeholder="mis. BCA" required></div>' +
        '<div class="field"><label for="aType">Jenis</label><select id="aType" name="type">' +
          [['cash', 'Tunai'], ['bank', 'Rekening bank'], ['ewallet', 'E-wallet'], ['credit', 'Kartu kredit'], ['other', 'Lainnya']].map(([v, l]) => '<option value="' + v + '"' + (a && a.type === v ? ' selected' : '') + '>' + l + '</option>').join('') + '</select></div>' +
        '<div class="field"><label for="aInitial">Saldo awal</label><input id="aInitial" class="input-money" name="initial" inputmode="numeric" data-money value="' + (a ? num(a.initial).toLocaleString('id-ID') : '') + '"></div>' +
        '<div class="field"><label for="aIcon">Ikon</label><input id="aIcon" name="icon" maxlength="4" value="' + esc(a ? a.icon : '💳') + '"></div>' +
        '<div class="field"><label for="aColor">Warna</label><input id="aColor" type="color" name="color" value="' + esc(a ? a.color : '#3b82f6') + '" style="height:40px;padding:3px"></div>' +
      '</div></form>',
      onSubmit: async (data) => {
        if (!data.name.trim()) throw new Error('Nama akun wajib diisi.');
        const payload = { name: data.name, type: data.type, initial: parseMoney(data.initial), icon: data.icon || '💳', color: data.color };
        if (a) { payload.id = a.id; await call('acc.update', payload); toast('Akun diperbarui.'); }
        else { await call('acc.create', payload); toast('Akun dibuat.'); }
        render();
      }
    });
  }

  function openCatModal(id, forcedType) {
    const c = id ? DB.categories.find((x) => x.id === id) : null;
    const type = (c && c.type) || forcedType || 'expense';
    openModal({
      title: c ? 'Ubah kategori' : 'Kategori baru',
      submitLabel: 'Simpan',
      body: '<form id="catForm"><div class="form-grid">' +
        '<div class="field"><label for="cName">Nama kategori</label><input id="cName" name="name" maxlength="60" value="' + esc(c ? c.name : '') + '" required></div>' +
        '<div class="field"><label for="cType">Tipe</label><select id="cType" name="type">' +
          ['expense', 'income'].map((t) => '<option value="' + t + '"' + (type === t ? ' selected' : '') + '>' + typeLabel(t) + '</option>').join('') + '</select></div>' +
        '<div class="field"><label for="cIcon">Ikon</label><input id="cIcon" name="icon" maxlength="4" value="' + esc(c ? c.icon : '🏷️') + '"></div>' +
        '<div class="field"><label for="cColor">Warna</label><input id="cColor" type="color" name="color" value="' + esc(c ? c.color : '#6366f1') + '" style="height:40px;padding:3px"></div>' +
      '</div></form>',
      onSubmit: async (data) => {
        if (!data.name.trim()) throw new Error('Nama kategori wajib diisi.');
        const payload = { name: data.name, type: data.type, icon: data.icon || '🏷️', color: data.color };
        if (c) { payload.id = c.id; await call('cat.update', payload); toast('Kategori diperbarui.'); }
        else { await call('cat.create', payload); toast('Kategori dibuat.'); }
        render();
      }
    });
  }

  /* -------------------------------------------------------------- events */

  document.addEventListener('click', async (ev) => {
    const closer = ev.target.closest('[data-close]');
    if (closer) { closeModal(); return; }

    const nav = ev.target.closest('.nav-item[data-view]');
    if (nav) { go(nav.dataset.view); return; }

    const target = ev.target.closest('[data-act]');
    if (!target) return;
    const act = target.dataset.act;
    const id = target.dataset.id;

    try {
      switch (act) {
        case 'go': go(target.dataset.view); break;

        case 'dash-month': {
          state.month = ymAdd(state.month, Number(target.dataset.delta));
          render(); break;
        }
        case 'report-month': {
          state.reportMonth = ymAdd(state.reportMonth, Number(target.dataset.delta));
          render(); break;
        }
        case 'report-mode': {
          state.reportMode = target.dataset.mode || 'monthly';
          render(); break;
        }
        case 'report-shift': {
          state.reportAnchor = reportShift(state.reportAnchor, state.reportMode, Number(target.dataset.delta) || 0);
          render(); break;
        }
        case 'report-now': {
          state.reportAnchor = todayISO();
          state.reportMonth = thisMonth();
          render(); break;
        }

        case 'tx-new': openTxModal(null, target.dataset.type || 'expense'); break;
        case 'tx-transfer': openTxModal(null, 'transfer'); break;
        case 'tx-edit': openTxModal(id); break;
        case 'scan-open': openScanModal(); break;
        case 'tx-receipt': openReceiptModal(id); break;
        case 'tx-del': {
          const t = DB.transactions.find((x) => x.id === id);
          confirmDialog('Hapus transaksi?', 'Transaksi ' + money(t ? t.amount : 0) + ' pada ' + dateLabel(t ? t.date : '') + ' akan dihapus dan saldo ikut menyesuaikan.', async () => {
            await call('tx.delete', { id }); toast('Transaksi dihapus.'); render();
          });
          break;
        }
        case 'tx-more': state.tx.limit += 40; render(); break;
        case 'tx-reset': state.tx = { q: '', type: 'all', cat: 'all', acc: 'all', month: thisMonth(), from: '', to: '', limit: 40 }; render(); break;
        case 'tx-all-month': state.tx.month = 'all'; render(); break;

        case 'budget-new': openBudgetModal(target.dataset.cat); break;
        case 'budget-edit': {
          const b = DB.budgets.find((x) => x.id === id);
          if (b) {
            state.month = b.month;
            openModal({
              title: 'Ubah batas anggaran',
              submitLabel: 'Simpan',
              body: '<form id="bgEdit"><div class="form-grid">' +
                '<p class="field-full small dim" style="margin:0">' + esc((catById(b.categoryId) || {}).name || 'Kategori') + ' · ' + esc(monthName(b.month)) + '</p>' +
                '<div class="field field-full"><label for="bgAmt">Batas bulanan</label><input id="bgAmt" class="input-money" name="amount" inputmode="numeric" data-money value="' + num(b.amount).toLocaleString('id-ID') + '"></div>' +
                '</div></form>',
              onSubmit: async (data) => {
                const amount = parseMoney(data.amount);
                if (!amount) throw new Error('Nominal wajib diisi.');
                await call('budget.set', { month: b.month, categoryId: b.categoryId, amount });
                toast('Anggaran diperbarui.'); render();
              }
            });
          }
          break;
        }
        case 'budget-del': {
          confirmDialog('Hapus anggaran?', 'Batas anggaran kategori ini akan dihapus. Transaksi tetap tersimpan.', async () => {
            await call('budget.delete', { id }); toast('Anggaran dihapus.'); render();
          });
          break;
        }
        case 'budget-copy': {
          const from = ymAdd(state.month, -1);
          confirmDialog('Salin anggaran?', 'Anggaran ' + monthName(from) + ' akan disalin ke ' + monthName(state.month) + '. Kategori yang sudah punya anggaran tidak diubah.', async () => {
            const res = await call('budget.copy', { from, to: state.month });
            toast(res.result.copied + ' anggaran disalin.'); render();
          }, 'Salin');
          break;
        }

        case 'goal-new': openGoalModal(null); break;
        case 'goal-edit': openGoalModal(id); break;
        case 'goal-in': openGoalEntryModal(id, 'in'); break;
        case 'goal-out': openGoalEntryModal(id, 'out'); break;
        case 'goal-history': openGoalHistory(id); break;
        case 'goal-entry-del': {
          await call('goal.entry.delete', { id });
          closeModal();
          openGoalHistory(target.dataset.goal);
          toast('Catatan dihapus.');
          render();
          break;
        }
        case 'goal-del': {
          const g = DB.goals.find((x) => x.id === id);
          confirmDialog('Hapus target?', 'Target “' + (g ? g.name : '') + '” beserta riwayat setorannya akan dihapus.', async () => {
            await call('goal.delete', { id }); toast('Target dihapus.'); render();
          });
          break;
        }

        case 'debt-new': openDebtModal(null, target.dataset.type || 'debt'); break;
        case 'debt-edit': openDebtModal(id); break;
        case 'debt-pay': openDebtPayModal(id); break;
        case 'debt-history': openDebtHistory(id); break;
        case 'debt-payment-del': {
          await call('debt.payment.delete', { id, paymentId: target.dataset.payment });
          closeModal(); openDebtHistory(id); render();
          toast('Pembayaran dihapus.');
          break;
        }
        case 'debt-del': {
          const d = DB.debts.find((x) => x.id === id);
          confirmDialog('Hapus catatan?', 'Catatan ' + (d ? d.person : '') + ' akan dihapus permanen.', async () => {
            await call('debt.delete', { id }); toast('Catatan dihapus.'); render();
          });
          break;
        }

        case 'acc-new': openAccModal(null); break;
        case 'acc-edit': openAccModal(id); break;
        case 'acc-archive': {
          const a = DB.accounts.find((x) => x.id === id);
          await call('acc.update', { id, name: a.name, type: a.type, color: a.color, icon: a.icon, archived: !a.archived });
          toast(a.archived ? 'Akun diaktifkan.' : 'Akun diarsipkan.'); render();
          break;
        }
        case 'acc-del': {
          const a = DB.accounts.find((x) => x.id === id);
          confirmDialog('Hapus akun?', 'Akun “' + (a ? a.name : '') + '” akan dihapus. Akun yang sudah punya transaksi tidak bisa dihapus.', async () => {
            await call('acc.delete', { id }); toast('Akun dihapus.'); render();
          });
          break;
        }

        case 'cat-new': openCatModal(null, target.dataset.type); break;
        case 'cat-edit': openCatModal(id); break;
        case 'cat-del': {
          const c = DB.categories.find((x) => x.id === id);
          confirmDialog('Hapus kategori?', 'Kategori “' + (c ? c.name : '') + '” akan dihapus. Kategori yang sudah dipakai transaksi tidak bisa dihapus — arsipkan saja.', async () => {
            await call('cat.delete', { id }); toast('Kategori dihapus.'); render();
          });
          break;
        }

        case 'data-demo': {
          confirmDialog('Muat data contoh?', 'Seluruh data saat ini akan DIGANTI dengan data sintetis untuk mencoba aplikasi. Unduh cadangan dulu bila perlu.', async () => {
            await call('data.demo', {}); toast('Data contoh (sintetis) dimuat.'); go('dashboard');
          }, 'Muat data contoh');
          break;
        }
        case 'data-reset': {
          confirmDialog('Hapus semua data?', 'Semua transaksi, anggaran, target, dan utang akan dihapus. Akun & kategori kembali ke bawaan. Tindakan ini tidak bisa dibatalkan.', async () => {
            await call('data.reset', {}); toast('Data dikosongkan.'); go('dashboard');
          }, 'Hapus semua');
          break;
        }

        case 'gold-refresh': loadGoldPrice(true); break;
        case 'go-gold': go('gold'); break;
        case 'gold-del': {
          const g = (DB.gold || []).find((x) => x.id === id);
          confirmDialog('Hapus catatan emas?', (g ? num(g.grams).toLocaleString('id-ID') + ' gram tanggal ' + shortDate(g.date) : 'Catatan ini') + ' akan dihapus beserta transaksi pengeluarannya.', async () => {
            await call('gold.delete', { id });
            toast('Catatan emas dihapus.');
            render();
          });
          break;
        }
        case 'interest-simulate': runInterest('simulate'); break;
        case 'interest-run': {
          const cfg = interestCfg();
          if (cfg.mode !== 'aktual') { toast('Ubah mode ke Aktual dulu untuk mencatat transaksi.', 'err'); break; }
          confirmDialog('Jalankan pencatatan bunga?', 'Bunga bersih hari ini dicatat sebagai pemasukan dan saldo akun bertambah. Sistem menolak catatan kedua pada tanggal yang sama.', () => runInterest('run'), 'Jalankan');
          break;
        }
        case 'interest-token': {
          try {
            const data = await call('interest.token', {});
            state.interest.info = null;
            await loadInterestInfo(true);
            toast('Token cron baru dibuat: ' + String(data.token || '').slice(0, 8) + '…');
          } catch (err) { toast(err.message, 'err'); }
          break;
        }
        case 'interest-copy': {
          const text = '30 1 * * * /usr/bin/php /path/ke/uang/cron/bunga_seabank.php --quiet';
          try { await navigator.clipboard.writeText(text); toast('Perintah cron disalin.'); }
          catch (e) { toast('Salin manual: ' + text, 'err'); }
          break;
        }
        case 'auth-logout': {
          try { await call('auth.logout'); } catch (e) { /* tetap keluar */ }
          window.location.replace('index.php');
          break;
        }
        case 'auth-logout-device': {
          confirmDialog('Keluarkan perangkat?', 'Sesi pada perangkat itu langsung berakhir. Token tetap sama, jadi pemilik bisa masuk lagi kapan saja.', async () => {
            try {
              const data = await call('auth.logoutDevice', { id: id });
              state.auth.info = (data && data.info) || null;
              toast('Perangkat dikeluarkan.');
              render();
            } catch (err) { toast(err.message, 'err'); }
          });
          break;
        }
        case 'auth-logout-all': {
          confirmDialog('Keluarkan semua perangkat?', 'Termasuk sesi di browser ini. Anda harus memasukkan token lagi untuk masuk.', async () => {
            try {
              await call('auth.logoutAll', { keepCurrent: false });
            } catch (err) { /* sesi mungkin sudah berakhir */ }
            window.location.replace('index.php');
          });
          break;
        }
        case 'report-print': window.print(); break;
        default: break;
      }
    } catch (err) {
      toast(err.message || 'Terjadi kesalahan.', 'err');
    }
  });

  // tombol di luar view
  document.getElementById('btnQuickAdd').addEventListener('click', () => openTxModal(null, 'expense'));
  document.getElementById('btnScan')?.addEventListener('click', openScanModal);
  document.getElementById('btnLogout').addEventListener('click', async () => {
    try { await call('auth.logout'); } catch (e) { /* tetap keluar */ }
    window.location.replace('index.php');
  });

  document.getElementById('btnTheme').addEventListener('click', async () => {
    const next = (DB.settings.theme === 'light') ? 'dark' : 'light';
    applyTheme(next);
    try { await call('settings.update', { theme: next }); } catch (e) { toast('Tema tidak tersimpan di server.', 'err'); }
  });
  document.getElementById('btnWipeDemo')?.addEventListener('click', () => {
    confirmDialog('Hapus data contoh?', 'Data sintetis akan dihapus dan aplikasi kembali kosong.', async () => {
      await call('data.reset', {}); toast('Data contoh dihapus.'); go('dashboard');
    }, 'Hapus');
  });

  // filter transaksi
  document.addEventListener('change', (ev) => {
    const f = ev.target.closest('[data-filter]');
    if (!f) return;
    const key = f.dataset.filter;
    if (key === 'month') {
      state.tx.month = f.value || 'all';
      // Memilih bulan berarti kembali ke tampilan per bulan.
      if (f.value) { state.tx.from = ''; state.tx.to = ''; }
    } else {
      state.tx[key] = f.value;
      // Mengisi rentang tanggal melepas batas bulan agar rentang lintas bulan bekerja.
      if ((key === 'from' || key === 'to') && f.value) state.tx.month = 'all';
    }
    state.tx.limit = 40;
    render();
  });
  document.addEventListener('input', (ev) => {
    const f = ev.target.closest('[data-filter="q"]');
    if (f) {
      state.tx.q = f.value;
      clearTimeout(window.__qTimer);
      window.__qTimer = setTimeout(() => {
        render();
        const input = document.querySelector('[data-filter="q"]');
        if (input) { input.focus(); input.setSelectionRange(input.value.length, input.value.length); }
      }, 260);
    }
    // format ribuan pada input uang
    const m = ev.target.closest('[data-money]');
    if (m) {
      const digits = String(m.value).replace(/[^\d]/g, '');
      m.value = digits ? Number(digits).toLocaleString('id-ID') : '';
    }
  });

  // form profil
  document.addEventListener('submit', async (ev) => {
    const form = ev.target.closest('[data-form="profile"]');
    if (!form) return;
    ev.preventDefault();
    const fd = new FormData(form);
    try {
      await call('settings.update', {
        appName: fd.get('appName'), ownerName: fd.get('ownerName'),
        currencySymbol: fd.get('currencySymbol'), currency: fd.get('currency'),
        theme: DB.settings.theme
      });
      toast('Profil disimpan.');
      applyChrome();
      render();
    } catch (err) { toast(err.message, 'err'); }
  });

  // ganti token manual
  document.addEventListener('submit', async (ev) => {
    const form = ev.target.closest('[data-form="change-token"]');
    if (!form) return;
    ev.preventDefault();
    const fd = new FormData(form);
    const baru = String(fd.get('newToken') || '');
    const ulang = String(fd.get('newToken2') || '');
    if (baru !== ulang) { toast('Ulangi token baru belum sama.', 'err'); return; }
    if (baru.length < 12) { toast('Token baru minimal 12 karakter.', 'err'); return; }
    const tombol = form.querySelector('button[type="submit"]');
    if (tombol) tombol.disabled = true;
    try {
      const data = await call('auth.changeToken', { current: fd.get('current'), newToken: baru, newToken2: ulang });
      form.reset();
      state.auth.info = (data && data.info) || null;
      toast('Token diganti. Token lama tidak berlaku lagi.');
      render();
    } catch (err) {
      toast(err.message, 'err');
    } finally {
      if (tombol) tombol.disabled = false;
    }
  });

  // aturan akun otomatis per kategori
  document.addEventListener('submit', async (ev) => {
    const form = ev.target.closest('[data-form="rules"]');
    if (!form) return;
    ev.preventDefault();
    const rules = {};
    form.querySelectorAll('select[data-cat]').forEach((sel) => {
      if (sel.value) rules[sel.dataset.cat] = sel.value;
    });
    try {
      await call('settings.rules', { rules: rules });
      toast('Aturan akun disimpan.');
      render();
    } catch (err) { toast(err.message, 'err'); }
  });

  // restore berkas
  document.addEventListener('change', (ev) => {
    if (ev.target.id !== 'restoreInput') return;
    const file = ev.target.files && ev.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = () => {
      let parsed = null;
      try { parsed = JSON.parse(String(reader.result)); } catch (e) { toast('Berkas bukan JSON yang valid.', 'err'); return; }
      if (!parsed || !Array.isArray(parsed.transactions)) { toast('Struktur berkas cadangan tidak dikenali.', 'err'); return; }
      confirmDialog('Pulihkan data?', 'Data saat ini akan diganti dengan isi berkas. Lanjutkan?', async () => {
        await call('data.restore', { db: parsed });
        toast('Data dipulihkan.');
        go('dashboard');
      }, 'Pulihkan');
    };
    reader.readAsText(file);
    ev.target.value = '';
  });

  // tutup modal dengan Esc
  document.addEventListener('keydown', (ev) => {
    if (ev.key === 'Escape' && !document.getElementById('modalRoot').hidden) closeModal();
    if (ev.key === 'n' && !ev.ctrlKey && !ev.metaKey && !/input|select|textarea/i.test(document.activeElement.tagName) && document.getElementById('modalRoot').hidden) {
      openTxModal(null, 'expense');
    }
  });

  /* ------------------------------------------------- pencarian transaksi */

  // Token "58000", "58.000", "25rb", "1,5jt", ">=100000" dianggap nominal.
  function parseAmountToken(tok) {
    const m = String(tok).match(/^(>=|<=|>|<)?\s*(?:rp\.?)?\s*(\d[\d.,]*)\s*(rb|ribu|k|jt|juta)?$/i);
    if (!m) return null;
    const suf = (m[3] || '').toLowerCase();
    let val;
    if (suf && /^\d+[.,]\d{1,2}$/.test(m[2])) {
      val = Number(m[2].replace(',', '.'));
    } else {
      val = Number(m[2].replace(/[.,]/g, ''));
    }
    if (suf === 'rb' || suf === 'ribu' || suf === 'k') val *= 1000;
    else if (suf === 'jt' || suf === 'juta') val *= 1000000;
    if (!isFinite(val) || val <= 0) return null;
    return { op: m[1] || '', value: val };
  }

  function txMatchesAmount(t, tok) {
    const amt = Math.round(num(t.amount));
    if (tok.op === '>') return amt > tok.value;
    if (tok.op === '>=') return amt >= tok.value;
    if (tok.op === '<') return amt < tok.value;
    if (tok.op === '<=') return amt <= tok.value;
    if (amt === Math.round(tok.value)) return true;
    // Tanpa operator: nominal harus sama persis (toleransi pembulatan rupiah).
    return false;
  }

  function txSearchHaystack(t) {
    const cat = catById(t.categoryId);
    const acc = accById(t.accountId);
    const to = t.toAccountId ? accById(t.toAccountId) : null;
    const parts = [
      t.note, t.merchant, cat && cat.name, acc && acc.name, to && to.name,
      typeLabel(t.type), dateLabel(t.date, true), dateLabel(t.date)
    ];
    (Array.isArray(t.items) ? t.items : []).forEach((it) => { if (it && it.name) parts.push(it.name); });
    return parts.filter(Boolean).join(' ').toLowerCase();
  }

  // Semua kata harus cocok: teks dicocokkan ke nama/toko/kategori/akun/barang,
  // angka dicocokkan ke nominal. Contoh: "indomaret 58rb", ">=100000", "bunga".
  function txMatchesQuery(t, q) {
    const tokens = String(q || '').trim().toLowerCase().split(/\s+/).filter(Boolean);
    if (!tokens.length) return true;
    const hay = txSearchHaystack(t);
    return tokens.every((tok) => {
      const amt = parseAmountToken(tok);
      return amt ? txMatchesAmount(t, amt) : hay.indexOf(tok) !== -1;
    });
  }

  /* ------------------------------------------------------ bunga otomatis */

  const INTEREST_DEFAULTS = {
    enabled: false, mode: 'simulasi', accountId: '', time: '01:30',
    rateBelow: 2.5, rateAtLeast: 3.5, threshold: 150000000, taxRate: 20,
    daysPerYear: 365, categoryName: 'Bunga Tabungan', note: 'Bunga Harian SeaBank'
  };
  const INTEREST_STATUS_LABEL = {
    created: 'Transaksi bunga dibuat',
    simulated: 'Simulasi selesai (tidak disimpan)',
    already: 'Sudah tercatat untuk tanggal ini',
    disabled: 'Otomatisasi sedang nonaktif',
    'no-account': 'Akun SeaBank belum dipilih',
    'zero-balance': 'Saldo akun kosong / minus',
    'too-small': 'Bunga bersih terlalu kecil',
    error: 'Gagal dijalankan',
    'bad-date': 'Tanggal tidak valid'
  };

  function interestCfg() {
    const dbVal = (DB.settings && DB.settings.interest) || {};
    const infoVal = (state.interest.info && state.interest.info.settings) || {};
    return Object.assign({}, INTEREST_DEFAULTS, dbVal, infoVal);
  }

  // Cermin perhitungan server, dipakai hanya untuk pratinjau di layar.
  function interestPreview(balance, cfg) {
    const days = Math.max(1, num(cfg.daysPerYear) || 365);
    const rate = balance >= num(cfg.threshold) ? num(cfg.rateAtLeast) : num(cfg.rateBelow);
    const round2 = (v) => Math.round(v * 100) / 100;
    const gross = round2(balance * rate / 100 / days);
    const tax = round2(gross * num(cfg.taxRate) / 100);
    return { rate: rate, gross: gross, tax: tax, net: round2(gross - tax) };
  }

  function interestStatusPill(cfg) {
    return cfg.enabled
      ? '<span class="pill pill-income">Aktif</span>'
      : '<span class="pill">Nonaktif</span>';
  }

  function interestResultHtml() {
    const r = state.interest.result;
    if (!r) return '';
    const label = INTEREST_STATUS_LABEL[r.status] || r.status;
    const cls = r.status === 'created' ? 'ok' : (r.status === 'error' || r.status === 'no-account') ? 'err' : 'info';
    let rows = '';
    if (r.balance !== undefined && r.balance !== null) {
      rows = '<div class="mini-rows">' +
        '<div><span>Saldo awal</span><strong class="mono">' + money(r.balance) + '</strong></div>' +
        '<div><span>Suku bunga</span><strong>' + num(r.rate).toLocaleString('id-ID') + '% / tahun</strong></div>' +
        '<div><span>Bunga kotor</span><strong class="mono">' + money(r.gross) + '</strong></div>' +
        '<div><span>Pajak ' + num(r.taxRate).toLocaleString('id-ID') + '%</span><strong class="mono neg">−' + money(r.tax) + '</strong></div>' +
        '<div><span>Bunga bersih</span><strong class="mono pos">' + money(r.net) + '</strong></div>' +
        '</div>';
    }
    return '<div class="notice ' + cls + '" data-interest-result>' +
      '<strong>' + esc(label) + '</strong>' + rows +
      (r.message ? '<div class="tiny" style="margin-top:6px">' + esc(r.message) + '</div>' : '') +
      (r.txId ? '<div class="tiny muted" style="margin-top:4px">Transaksi: ' + esc(r.txId) + '</div>' : '') +
      '</div>';
  }

  function accountRulesCardHtml() {
    const rules = (DB.settings && DB.settings.accountRules) || {};
    const accOpts = accounts().map((a) => '<option value="' + esc(a.id) + '">' + esc((a.icon || '') + ' ' + a.name) + '</option>').join('');
    const rows = categories().filter((c) => !c.archived).map((c) => {
      const aktif = rules[c.id] || '';
      const opsi = '<option value="">— tidak diatur —</option>' + accounts().map((a) =>
        '<option value="' + esc(a.id) + '"' + (a.id === aktif ? ' selected' : '') + '>' + esc((a.icon || '') + ' ' + a.name) + '</option>').join('');
      return '<div class="rule-row"><span class="rule-cat">' + esc((c.icon || '') + ' ' + c.name) + '</span><select data-cat="' + esc(c.id) + '">' + opsi + '</select></div>';
    }).join('');
    return '<div class="card" id="cardRules"><div class="card-head"><h2>Akun otomatis per kategori</h2></div>' +
      '<p class="small muted" style="margin-top:0">Saat mencatat transaksi dan memilih kategori, sumber rekening langsung terisi akun di bawah ini. Bisa diubah kapan saja.</p>' +
      '<form data-form="rules"><div class="rule-list">' + rows + '</div>' +
      '<div class="row-end" style="margin-top:12px"><button class="btn btn-primary" type="submit">Simpan aturan</button>' +
      '<span class="tiny muted">Contoh: kategori Bisnis Osint → akun Dana Business.</span></div></form></div>';
  }

  function authWhen(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    if (isNaN(d.getTime())) return '—';
    return d.getDate() + ' ' + MONTHS_SHORT[d.getMonth()] + ' ' + d.getFullYear() + ', ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
  }

  function authSisa(iso) {
    const akhir = new Date(iso).getTime();
    if (!akhir || isNaN(akhir)) return '';
    const sisa = akhir - Date.now();
    if (sisa <= 0) return 'sudah berakhir';
    const jam = Math.floor(sisa / 3600000);
    const menit = Math.floor((sisa % 3600000) / 60000);
    return jam > 0 ? jam + ' jam ' + menit + ' menit lagi' : menit + ' menit lagi';
  }

  function securityCardHtml() {
    const info = state.auth.info;
    if (!info) {
      return '<div class="card"><div class="card-head"><h2>Keamanan &amp; akses</h2></div>' +
        (state.auth.error ? '<p class="small" style="color:var(--danger)">' + esc(state.auth.error) + '</p>' : '<p class="small dim">Memuat info sesi…</p>') +
        '</div>';
    }
    const sesi = info.session || {};
    const devices = info.devices || [];
    const history = info.history || [];
    const labelHasil = { berhasil: 'Masuk berhasil', gagal: 'Gagal masuk', keluar: 'Keluar', token: 'Token diganti' };

    const deviceRows = devices.length
      ? devices.map((d) => '<div class="row-between" style="padding:8px 0;border-bottom:1px solid var(--border-soft);gap:10px;align-items:flex-start">' +
          '<div style="min-width:0"><div class="small"><strong>' + esc(d.label || 'Perangkat') + '</strong>' +
            (d.current ? ' <span class="pill pill-income">ini</span>' : '') + '</div>' +
            '<div class="tiny muted">' + esc(d.ip || '-') + ' · masuk ' + esc(authWhen(d.loginAt)) + '<br>aktif ' + esc(authWhen(d.lastSeen)) + ' · sesi berakhir ' + esc(authWhen(d.expiresAt)) + '</div></div>' +
          (d.current ? '<span class="tiny muted">aktif</span>' :
            '<button class="btn btn-sm btn-danger" data-act="auth-logout-device" data-id="' + esc(d.id) + '">Keluarkan</button>') +
          '</div>').join('')
      : '<p class="small dim" style="margin:0">Tidak ada perangkat lain yang aktif.</p>';

    const historyRows = history.length
      ? history.slice(0, 8).map((h) => '<div class="row-between tiny" style="padding:5px 0;border-bottom:1px solid var(--border-soft);gap:8px">' +
          '<span><strong>' + esc(labelHasil[h.result] || h.result || '-') + '</strong>' + (h.note ? ' — ' + esc(h.note) : '') +
          '<br><span class="muted">' + esc(h.ip || '-') + ' · ' + esc((h.browser || '') + ' · ' + (h.os || '')) + '</span></span>' +
          '<span class="muted" style="white-space:nowrap">' + esc(authWhen(h.time)) + '</span></div>').join('')
      : '<p class="small dim" style="margin:0">Belum ada riwayat.</p>';

    return '<div class="card"><div class="card-head"><h2>Keamanan &amp; akses</h2>' +
        '<button class="btn btn-sm" data-act="auth-logout">⏻ Keluar</button></div>' +

        (info.https ? '' : '<p class="small" style="color:#fbbf24;margin:0 0 10px">Koneksi belum HTTPS. Pasang SSL di hosting agar token tidak terkirim terbuka.</p>') +

        '<p class="small dim" style="margin:0 0 4px">Sesi ini masuk ' + esc(authWhen(sesi.loginAt)) + ' dari ' + esc(sesi.ip || '-') +
          ' (' + esc((sesi.browser || '') + ' · ' + (sesi.os || '')) + ').</p>' +
        '<p class="small dim" style="margin:0 0 12px">Berakhir ' + esc(authWhen(sesi.expiresAt)) + ' — ' + esc(authSisa(sesi.expiresAt)) +
          '. Sesi tidak diperpanjang oleh aktivitas.</p>' +

        '<div class="divider"></div>' +
        '<h3 style="margin:0 0 8px">Ganti token</h3>' +
        '<form data-form="change-token" class="form-grid">' +
          '<div class="field"><label for="tokLama">Token sekarang</label><input id="tokLama" name="current" type="password" autocomplete="current-password" required></div>' +
          '<div class="field"><label for="tokBaru">Token baru</label><input id="tokBaru" name="newToken" type="password" autocomplete="new-password" minlength="12" required placeholder="Minimal 12 karakter"></div>' +
          '<div class="field"><label for="tokUlang">Ulangi token baru</label><input id="tokUlang" name="newToken2" type="password" autocomplete="new-password" minlength="12" required></div>' +
          '<div class="field-full"><button class="btn btn-primary" type="submit">Ganti token</button>' +
            '<p class="tiny muted" style="margin:8px 0 0">Token lama langsung tidak berlaku. Perangkat yang sedang aktif tetap masuk sampai sesinya berakhir.</p></div>' +
        '</form>' +

        '<div class="divider"></div>' +
        '<div class="row-between"><h3 style="margin:0">Perangkat aktif</h3>' +
          '<button class="btn btn-sm btn-danger" data-act="auth-logout-all">Keluarkan semua</button></div>' +
        '<div style="margin-top:8px">' + deviceRows + '</div>' +

        '<div class="divider"></div>' +
        '<h3 style="margin:0 0 6px">Riwayat login</h3>' + historyRows +
        '<p class="tiny muted" style="margin:10px 0 0">Token hanya disimpan sebagai hash (password_hash). Tidak ada token di URL, halaman, atau log.</p>' +
      '</div>';
  }

  async function loadAuthInfo(force) {
    if (state.auth.loading) return;
    if (state.auth.info && !force) return;
    state.auth.loading = true;
    try {
      state.auth.info = await apiGet('auth.info');
      state.auth.error = '';
    } catch (err) {
      state.auth.error = err.message || 'Gagal memuat info keamanan.';
    } finally {
      state.auth.loading = false;
      if (state.view === 'settings') render();
    }
  }

  function interestCardHtml() {
    const cfg = interestCfg();
    const info = state.interest.info;
    const plan = info && info.plan ? info.plan : null;
    const accId = cfg.accountId || (info && info.account ? info.account.id : '');
    const bal = balanceMap();
    const saldo = accId ? num(bal[accId]) : 0;
    const pv = interestPreview(saldo, cfg);
    const busy = state.interest.busy;
    const opts = accounts(true).map((a) => '<option value="' + esc(a.id) + '"' + (a.id === accId ? ' selected' : '') + '>' + esc((a.icon || '') + ' ' + a.name) + '</option>').join('');
    const token = info && info.token ? info.token : '';
    const base = appBaseUrl();
    const cmd = '30 1 * * * /usr/bin/php /path/ke/uang/cron/bunga_seabank.php --quiet';
    const httpUrl = base + '/cron/bunga_seabank.php?token=' + encodeURIComponent(token || 'TOKEN');

    return '<div class="card" id="interestCard"><div class="card-head"><h2>Bunga SeaBank otomatis</h2>' + interestStatusPill(cfg) + '</div>' +
      '<p class="small dim" style="margin:0 0 10px">Cron PHP mencatat bunga tabungan sekali sehari pada pukul ' + esc(cfg.time) + ' WIB. ' +
        'Mode <strong>simulasi</strong> hanya menghitung dan menulis log — transaksi &amp; saldo tidak berubah.</p>' +
      '<form data-form="interest" class="form-grid">' +
        '<div class="field field-full"><label class="check"><input type="checkbox" name="enabled"' + (cfg.enabled ? ' checked' : '') + '> Aktifkan pencatatan otomatis</label></div>' +
        '<div class="field"><label for="iMode">Mode</label><select id="iMode" name="mode">' +
          '<option value="simulasi"' + (cfg.mode === 'simulasi' ? ' selected' : '') + '>Simulasi (tidak menyimpan)</option>' +
          '<option value="aktual"' + (cfg.mode === 'aktual' ? ' selected' : '') + '>Aktual (catat ke transaksi)</option>' +
        '</select></div>' +
        '<div class="field"><label for="iAcc">Akun SeaBank</label><select id="iAcc" name="accountId">' + opts + '</select></div>' +
        '<div class="field"><label for="iTime">Jam jalan (WIB)</label><input id="iTime" name="time" type="time" value="' + esc(cfg.time) + '"></div>' +
        '<div class="field"><label for="iRateBelow">Bunga bila saldo &lt; ambang (%/tahun)</label><input id="iRateBelow" name="rateBelow" inputmode="decimal" value="' + esc(cfg.rateBelow) + '"></div>' +
        '<div class="field"><label for="iRateAt">Bunga bila saldo ≥ ambang (%/tahun)</label><input id="iRateAt" name="rateAtLeast" inputmode="decimal" value="' + esc(cfg.rateAtLeast) + '"></div>' +
        '<div class="field"><label for="iThreshold">Ambang saldo (Rp)</label><input id="iThreshold" name="threshold" class="input-money" inputmode="numeric" data-money value="' + num(cfg.threshold).toLocaleString('id-ID') + '"></div>' +
        '<div class="field"><label for="iTax">Pajak bunga (%)</label><input id="iTax" name="taxRate" inputmode="decimal" value="' + esc(cfg.taxRate) + '"></div>' +
        '<div class="field"><label for="iDays">Hari per tahun</label><input id="iDays" name="daysPerYear" inputmode="numeric" value="' + esc(cfg.daysPerYear) + '"></div>' +
        '<div class="field"><label for="iCat">Kategori pemasukan</label><input id="iCat" name="categoryName" value="' + esc(cfg.categoryName) + '" maxlength="40"></div>' +
        '<div class="field field-full"><label for="iNote">Keterangan transaksi</label><input id="iNote" name="note" value="' + esc(cfg.note) + '" maxlength="60"></div>' +
        '<div class="field-full row" style="gap:8px;flex-wrap:wrap"><button class="btn btn-primary" type="submit">Simpan setelan</button>' +
          '<button class="btn" type="button" data-act="interest-simulate"' + (busy ? ' disabled' : '') + '>🧪 Simulasi hari ini</button>' +
          '<button class="btn" type="button" data-act="interest-run"' + (busy ? ' disabled' : '') + '>▶ Jalankan sekarang</button>' +
        '</div>' +
      '</form>' +

      '<div class="divider"></div>' +
      '<div class="mini-rows">' +
        '<div><span>Perkiraan hari ini</span><strong class="mono">' + money(plan && plan.status === 'ok' ? plan.net : pv.net) + '</strong></div>' +
        '<div><span>Saldo dasar</span><strong class="mono">' + money(plan && plan.balance !== undefined ? plan.balance : saldo) + '</strong></div>' +
        '<div><span>Suku bunga berlaku</span><strong>' + num(plan && plan.rate !== undefined ? plan.rate : pv.rate).toLocaleString('id-ID') + '% / tahun</strong></div>' +
        (plan && plan.exists ? '<div><span>Status tanggal ini</span><strong class="pos">sudah tercatat</strong></div>' : '') +
        '<div><span>Jadwal berikutnya</span><strong>' + esc(info && info.nextRun ? dateLabel(String(info.nextRun).slice(0, 10), true) + ' ' + String(info.nextRun).slice(11, 16) + ' WIB' : cfg.time + ' WIB') + '</strong></div>' +
        '<div><span>Eksekusi terakhir</span><strong>' + esc(cfg.lastRunAt ? String(cfg.lastRunAt).replace('T', ' ').slice(0, 16) + ' · ' + (INTEREST_STATUS_LABEL[cfg.lastStatus] || cfg.lastStatus || '-') : 'belum pernah') + '</strong></div>' +
      '</div>' +
      interestResultHtml() +

      '<details class="cron-box"><summary>Cara memasang cron job</summary>' +
        '<p class="tiny dim" style="margin:8px 0 4px">cPanel → Cron Jobs → tambahkan jadwal <code>30 1 * * *</code> (01:30 WIB) dengan perintah:</p>' +
        '<pre class="log-pre">' + esc(cmd) + '</pre>' +
        '<p class="tiny dim" style="margin:8px 0 4px">Bila hosting hanya mengizinkan URL, panggil dengan token rahasia:</p>' +
        '<pre class="log-pre">' + esc(httpUrl) + '</pre>' +
        '<div class="row" style="gap:8px;flex-wrap:wrap"><button class="btn btn-sm" type="button" data-act="interest-copy">Salin perintah CLI</button>' +
        '<button class="btn btn-sm" type="button" data-act="interest-token">Ganti token</button></div>' +
        '<p class="tiny muted" style="margin:8px 0 0">Zona waktu aplikasi: <strong>' + esc(info && info.timezone ? info.timezone : 'Asia/Jakarta') + '</strong>. Log: <code>data/logs/</code>.</p>' +
      '</details>' +

      (info && info.log && info.log.length
        ? '<details class="cron-box"><summary>Log eksekusi terakhir (' + info.log.length + ')</summary><pre class="log-pre">' + esc(info.logText || '') + '</pre></details>'
        : '') +
    '</div>';
  }

  async function loadInterestInfo(force) {
    if (state.interest.loading) return;
    if (state.interest.info && !force) return;
    state.interest.loading = true;
    try {
      state.interest.info = await apiGet('interest.get');
      state.interest.error = '';
    } catch (err) {
      state.interest.error = err.message || 'Gagal memuat setelan bunga.';
    } finally {
      state.interest.loading = false;
      if (state.view === 'settings') render();
    }
  }

  async function runInterest(kind) {
    if (state.interest.busy) return;
    const cfg = interestCfg();
    if (kind === 'run' && cfg.mode !== 'aktual') {
      toast('Mode masih simulasi — tidak ada transaksi yang dicatat.', 'err');
      return;
    }
    state.interest.busy = true;
    render();
    try {
      const data = await call(kind === 'simulate' ? 'interest.simulate' : 'interest.run', {});
      state.interest.result = data.result || null;
      state.interest.info = null;
      const r = data.result || {};
      if (r.status === 'created') toast('Bunga ' + money(r.net) + ' tercatat.');
      else if (r.status === 'already') toast('Sudah tercatat untuk tanggal ini — tidak dobel.');
      else toast(INTEREST_STATUS_LABEL[r.status] || 'Selesai.');
      await loadInterestInfo(true);
    } catch (err) {
      toast(err.message, 'err');
    } finally {
      state.interest.busy = false;
      render();
    }
  }

  /* --------------------------------------------------------- emas digital */

  function goldPrice() {
    const p = state.gold.price;
    return p && (num(p.buy) > 0 || num(p.sell) > 0) ? p : null;
  }

  function goldRows() {
    const list = Array.isArray(DB.gold) ? DB.gold.slice() : [];
    return list.sort((a, b) => String(b.date).localeCompare(String(a.date)) || String(b.id).localeCompare(String(a.id)));
  }

  function goldTotals() {
    const list = Array.isArray(DB.gold) ? DB.gold : [];
    const grams = list.reduce((s, g) => s + num(g.grams), 0);
    const cost = list.reduce((s, g) => s + num(g.total), 0);
    const p = goldPrice();
    const value = p && num(p.sell) > 0 ? grams * num(p.sell) : 0;
    return { grams: grams, cost: cost, value: value, pl: value - cost, priced: !!(p && num(p.sell) > 0) };
  }

  async function loadGoldPrice(force) {
    if (state.gold.loading) return;
    state.gold.loading = true;
    state.gold.tried = true;
    try {
      const data = await apiGet('gold.price', force ? { force: 1 } : {});
      state.gold.price = data || null;
      state.gold.error = data && data.ok ? (data.stale ? 'Harga dari simpanan terakhir — koneksi ke Lakuemas sedang gagal.' : '') : ((data && data.error) || 'Harga emas belum tersedia.');
    } catch (err) {
      state.gold.error = err.message || 'Gagal mengambil harga emas.';
    } finally {
      state.gold.loading = false;
      if (state.view === 'gold' && !state.gold.dirty) render();
    }
  }

  function viewGold() {
    const p = goldPrice();
    const t = goldTotals();
    const err = state.gold.error;
    if (!p && !state.gold.loading && !state.gold.tried) loadGoldPrice(false);

    const priceCard = (label, val, cls) => '<div class="stat ' + cls + '"><div class="stat-label">' + label + '</div>' +
      '<div class="stat-value mono" style="font-size:1.3rem">' + (val ? money(val) : '—') + '</div>' +
      '<div class="tiny muted">per gram</div></div>';

    const buyPrice = p && num(p.buy) > 0 ? num(p.buy) : '';
    const rows = goldRows().map((g) => {
      const now = p && num(p.sell) > 0 ? num(g.grams) * num(p.sell) : 0;
      const pl = now ? now - num(g.total) : 0;
      return '<tr>' +
        '<td class="nowrap">' + esc(shortDate(g.date)) + '</td>' +
        '<td class="num mono">' + num(g.grams).toLocaleString('id-ID') + ' gr</td>' +
        '<td class="num mono">' + money(g.pricePerGram) + '</td>' +
        '<td class="num mono">' + money(g.total) + '</td>' +
        '<td class="num mono">' + (now ? money(now) : '—') + '</td>' +
        '<td class="num mono ' + (now ? (pl >= 0 ? 'pos' : 'neg') : 'muted') + '">' + (now ? (pl >= 0 ? '+' : '−') + money(Math.abs(pl)) : '—') + '</td>' +
        '<td class="num"><button class="icon-btn btn-sm" data-act="gold-del" data-id="' + esc(g.id) + '" title="Hapus catatan">🗑</button></td>' +
      '</tr>';
    }).join('');

    return '<div class="page-head"><div><h1>Emas Digital</h1><p>Harga acuan Lakuemas dan catatan pembelian emas Anda.</p></div>' +
      '<div class="row"><button class="btn" data-act="gold-refresh"' + (state.gold.loading ? ' disabled' : '') + '>' + (state.gold.loading ? 'Memuat…' : '🔄 Perbarui harga') + '</button></div></div>' +

      '<div class="grid grid-3">' +
        priceCard('Harga beli', p ? num(p.buy) : 0, 'stat-in') +
        priceCard('Harga jual', p ? num(p.sell) : 0, 'stat-out') +
        '<div class="stat"><div class="stat-label">Pembelian tercatat</div><div class="stat-value" style="font-size:1.3rem">' + num(t.grams).toLocaleString('id-ID') + ' gr</div>' +
          '<div class="tiny muted">' + (p && p.fetchedAt ? 'harga ' + String(p.fetchedAt).replace('T', ' ').slice(0, 16) : 'harga belum dimuat') + '</div></div>' +
      '</div>' +

      (err ? '<div class="notice info" style="margin-top:12px">' + esc(err) + '</div>' : '') +

      '<div class="grid grid-main" style="margin-top:12px">' +
        '<div class="card"><div class="card-head"><h2>Catat pembelian emas</h2></div>' +
          '<p class="small dim" style="margin:0 0 10px">Harga terisi otomatis dari Lakuemas (harga beli). Ubah bila transaksi Anda memakai harga lain. ' +
            'Pembelian dicatat sebagai pengeluaran di akun yang dipilih, tanpa mengubah saldo emas di aplikasi Lakuemas.</p>' +
          '<form data-form="gold-buy" id="goldForm" class="form-grid">' +
            '<div class="field"><label for="gGrams">Berat (gram)</label><input id="gGrams" name="grams" inputmode="decimal" value="' + esc(state.gold.grams || '1') + '" required></div>' +
            '<div class="field"><label for="gPrice">Harga per gram</label><input id="gPrice" name="pricePerGram" class="input-money" inputmode="numeric" data-money value="' + (buyPrice ? Math.round(buyPrice).toLocaleString('id-ID') : '') + '" required></div>' +
            '<div class="field"><label for="gFee">Biaya tambahan <span class="muted">(opsional)</span></label><input id="gFee" name="fee" class="input-money" inputmode="numeric" data-money value="" placeholder="0"></div>' +
            '<div class="field"><label for="gDate">Tanggal</label><input id="gDate" name="date" type="date" value="' + todayISO() + '" required></div>' +
            '<div class="field"><label for="gAcc">Akun pembayaran</label><select id="gAcc" name="accountId">' +
              accounts().map((a) => '<option value="' + esc(a.id) + '">' + esc((a.icon || '') + ' ' + a.name) + '</option>').join('') +
            '</select></div>' +
            '<div class="field"><label for="gNote">Keterangan <span class="muted">(opsional)</span></label><input id="gNote" name="note" maxlength="120" placeholder="mis. emas digital Lakuemas"></div>' +
            '<div class="field-full total-line"><span>Total pembayaran</span><strong class="mono" id="gTotal">—</strong></div>' +
            '<div class="field-full"><button class="btn btn-primary" type="submit">Simpan pembelian</button></div>' +
          '</form>' +
        '</div>' +

        '<div class="stack">' +
          '<div class="card"><div class="card-head"><h2>Ringkasan emas</h2></div>' +
            '<div class="mini-rows">' +
              '<div><span>Total berat</span><strong class="mono">' + num(t.grams).toLocaleString('id-ID') + ' gr</strong></div>' +
              '<div><span>Total modal</span><strong class="mono">' + money(t.cost) + '</strong></div>' +
              '<div><span>Nilai kini (harga jual)</span><strong class="mono">' + (t.priced ? money(t.value) : '—') + '</strong></div>' +
              '<div><span>Selisih</span><strong class="mono ' + (t.priced ? (t.pl >= 0 ? 'pos' : 'neg') : 'muted') + '">' + (t.priced ? (t.pl >= 0 ? '+' : '−') + money(Math.abs(t.pl)) : '—') + '</strong></div>' +
            '</div>' +
          '</div>' +
          '<div class="card"><div class="card-head"><h2>Riwayat pembelian</h2></div>' +
            (goldRows().length
              ? '<div class="table-wrap"><table><thead><tr><th>Tanggal</th><th class="num">Berat</th><th class="num">Harga/gr</th><th class="num">Total</th><th class="num">Nilai kini</th><th class="num">Selisih</th><th></th></tr></thead><tbody>' + rows + '</tbody></table></div>'
              : '<p class="small muted" style="margin:0">Belum ada pembelian emas yang dicatat.</p>') +
          '</div>' +
        '</div>' +
      '</div>';
  }

  /* --------------------------------------------- form bunga & beli emas */

  document.addEventListener('submit', async (ev) => {
    const intForm = ev.target.closest('[data-form="interest"]');
    const goldForm = ev.target.closest('[data-form="gold-buy"]');
    if (!intForm && !goldForm) return;
    ev.preventDefault();
    const fd = new FormData(ev.target);
    const angka = (v) => Number(String(v || '').replace(/\./g, '').replace(',', '.')) || 0;

    try {
      if (intForm) {
        await call('interest.save', {
          enabled: fd.get('enabled') === 'on',
          mode: String(fd.get('mode') || 'simulasi'),
          accountId: String(fd.get('accountId') || ''),
          time: String(fd.get('time') || '01:30'),
          rateBelow: angka(fd.get('rateBelow')),
          rateAtLeast: angka(fd.get('rateAtLeast')),
          threshold: angka(fd.get('threshold')),
          taxRate: angka(fd.get('taxRate')),
          daysPerYear: angka(fd.get('daysPerYear')) || 365,
          categoryName: String(fd.get('categoryName') || 'Bunga Tabungan'),
          note: String(fd.get('note') || 'Bunga Harian SeaBank')
        });
        state.interest.info = null;
        state.interest.result = null;
        await loadInterestInfo(true);
        toast('Setelan bunga otomatis disimpan.');
        render();
        return;
      }

      const grams = Number(String(fd.get('grams') || '').replace(',', '.'));
      const data = await call('gold.buy', {
        grams: grams,
        pricePerGram: angka(fd.get('pricePerGram')),
        fee: angka(fd.get('fee')),
        accountId: String(fd.get('accountId') || ''),
        date: String(fd.get('date') || todayISO()),
        note: String(fd.get('note') || '')
      });
      state.gold.dirty = false;
      state.gold.grams = '1';
      toast('Pembelian ' + (data.result ? num(data.result.grams).toLocaleString('id-ID') + ' gram' : 'emas') + ' dicatat.');
      render();
    } catch (err) { toast(err.message, 'err'); }
  });

  document.addEventListener('input', (ev) => {
    const form = ev.target.closest('#goldForm');
    if (!form) return;
    state.gold.dirty = true;
    state.gold.grams = form.querySelector('#gGrams').value;
    const grams = Number(String(form.querySelector('#gGrams').value || '').replace(',', '.')) || 0;
    const price = Number(String(form.querySelector('#gPrice').value || '').replace(/\./g, '')) || 0;
    const fee = Number(String(form.querySelector('#gFee').value || '').replace(/\./g, '')) || 0;
    const totalEl = document.getElementById('gTotal');
    if (totalEl) totalEl.textContent = grams && price ? money(grams * price + fee) : '—';
  });

  /* --------------------------------------------------------------- start */

  applyChrome();
  render();
})();
