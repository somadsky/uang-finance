<?php
declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';

try {
    require __DIR__ . '/inc/config.php';
    require __DIR__ . '/inc/helpers.php';
    require __DIR__ . '/inc/store.php';
    require __DIR__ . '/inc/finance.php';
    require __DIR__ . '/inc/demo.php';
    require __DIR__ . '/inc/struk.php';
    require __DIR__ . '/inc/interest.php';
    require __DIR__ . '/inc/gold.php';
    require __DIR__ . '/inc/auth.php';
} catch (Throwable $e) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Robots-Tag: noindex, nofollow');
    }
    echo json_encode([
        'ok' => false,
        'error' => 'Server gagal memuat komponen aplikasi. Periksa izin folder data/ dan versi PHP (minimal 7.4), '
            . 'atau buka diagnosa.php. Rincian: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Galat tak terduga tetap dijawab sebagai JSON, bukan halaman HTML 500.
set_exception_handler(static function (Throwable $e): void {
    error_log('Uang API: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'ok' => false,
        'error' => 'Terjadi kesalahan di server. Bila berulang, buka diagnosa.php untuk memeriksa izin folder data/.',
    ], JSON_UNESCAPED_UNICODE);
});

header('X-Content-Type-Options: nosniff');

$action = isset($_GET['action']) ? (string)$_GET['action'] : '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

auth_security_headers();
header('Cache-Control: no-store, no-cache, must-revalidate');

/* ---------- Penjaga autentikasi ----------
 * Semua aksi butuh sesi 24 jam, kecuali tiga aksi publik di bawah (untuk
 * halaman masuk). Aksi tulis (POST) juga wajib membawa token CSRF.
 */
$aksiPublik = ['auth.status', 'auth.login', 'auth.setup'];
if (!in_array($action, $aksiPublik, true)) {
    if (!auth_logged_in()) {
        json_err('Sesi berakhir atau Anda belum masuk. Masukkan token untuk melanjutkan.', 401, ['needLogin' => true]);
    }
    if ($method === 'POST' && !auth_csrf_valid($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
        json_err('Token keamanan (CSRF) tidak cocok. Muat ulang halaman lalu coba lagi.', 419, ['needCsrf' => true]);
    }
}

/* ---------- GET: baca data & unduhan ---------- */

if ($method === 'GET') {
    switch ($action) {
        /* ---------- Autentikasi (status publik, info wajib sesi) ---------- */
        case 'auth.status': {
            $sesi = auth_session();
            json_ok([
                'needSetup'  => !auth_token_exists(),
                'loggedIn'   => $sesi !== null,
                'csrf'       => $sesi !== null ? (string)$sesi['csrf'] : '',
                'loginAt'    => $sesi !== null ? (string)$sesi['loginAt'] : '',
                'expiresAt'  => $sesi !== null ? (string)$sesi['expiresAt'] : '',
                'https'      => auth_is_https(),
                'serverTime' => auth_iso(auth_now()),
                'ttl'        => AUTH_SESSION_TTL,
            ]);
            break;
        }

        case 'auth.info':
            json_ok(auth_info());
            break;

        case 'db':
            json_ok(db_read());
            break;

        /* ---------- Bunga otomatis ---------- */
        case 'interest.get': {
            $date = isset($_GET['date']) ? (string)$_GET['date'] : date('Y-m-d');
            if (!valid_date($date)) {
                $date = date('Y-m-d');
            }
            json_ok(interest_summary(db_read(), $date));
            break;
        }

        /* ---------- Harga emas Lakuemas ---------- */
        case 'gold.price':
            json_ok(gold_fetch(!empty($_GET['force'])));
            break;

        case 'export.csv':
            $db = db_read();
            $month = isset($_GET['month']) ? (string)$_GET['month'] : '';
            if ($month !== '' && !valid_month($month)) {
                json_err('Format bulan harus YYYY-MM.', 422);
            }
            // Rentang tanggal opsional (dari–sampai), dipakai filter tanggal di UI.
            $from = isset($_GET['from']) ? (string)$_GET['from'] : '';
            $to = isset($_GET['to']) ? (string)$_GET['to'] : '';
            if ($from !== '' && !valid_date($from)) {
                json_err('Format tanggal awal harus YYYY-MM-DD.', 422);
            }
            if ($to !== '' && !valid_date($to)) {
                json_err('Format tanggal akhir harus YYYY-MM-DD.', 422);
            }
            if ($from !== '' && $to !== '' && $from > $to) {
                json_err('Tanggal awal tidak boleh setelah tanggal akhir.', 422);
            }
            $accMap = fin_account_map($db);
            $catMap = fin_category_map($db);
            $rows = [];
            foreach ($db['transactions'] as $t) {
                $d = (string)$t['date'];
                if ($month !== '' && substr($d, 0, 7) !== $month) {
                    continue;
                }
                if ($from !== '' && $d < $from) {
                    continue;
                }
                if ($to !== '' && $d > $to) {
                    continue;
                }
                $rows[] = $t;
            }
            usort($rows, fn($a, $b) => strcmp((string)$a['date'], (string)$b['date']));
            $typeLabel = ['income' => 'Pemasukan', 'expense' => 'Pengeluaran', 'transfer' => 'Transfer'];
            $scope = $month !== '' ? $month : ($from !== '' || $to !== '' ? ($from !== '' ? $from : 'awal') . '_sd_' . ($to !== '' ? $to : 'akhir') : 'semua');
            $fname = 'uang-transaksi-' . $scope . '-' . date('Ymd-His') . '.csv';
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $fname . '"');
            echo "\xEF\xBB\xBF"; // BOM agar Excel membaca UTF-8
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Tanggal', 'Tipe', 'Kategori', 'Akun', 'Tujuan', 'Jumlah', 'Catatan']);
            foreach ($rows as $t) {
                fputcsv($out, [
                    $t['date'],
                    $typeLabel[$t['type']] ?? $t['type'],
                    $t['categoryId'] ? ($catMap[$t['categoryId']]['name'] ?? '-') : '-',
                    $accMap[$t['accountId']]['name'] ?? '-',
                    $t['toAccountId'] ? ($accMap[$t['toAccountId']]['name'] ?? '-') : '-',
                    (float)$t['amount'],
                    (string)($t['note'] ?? ''),
                ]);
            }
            fclose($out);
            exit;

        case 'backup.download':
            $db = db_read();
            $fname = 'uang-backup-' . date('Ymd-His') . '.json';
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $fname . '"');
            echo json_encode($db, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            exit;

        case 'backup.list':
            json_ok(db_backup_list());
            break;

        /* ---------- Foto struk belanja ---------- */
        case 'receipt.get': {
            $id = in_str($_GET, 'id', '', 60);
            if ($id === '') {
                json_err('Transaksi tidak ditentukan.', 422);
            }
            $row = null;
            foreach (db_read()['transactions'] as $t) {
                if ((string)($t['id'] ?? '') === $id) {
                    $row = $t;
                    break;
                }
            }
            $file = is_array($row) ? (string)($row['receipt'] ?? '') : '';
            $path = $file !== '' ? struk_path($file) : null;
            if ($path === null) {
                json_err('Foto struk tidak ditemukan.', 404);
            }
            $etag = '"' . md5($file . '|' . filesize($path) . '|' . filemtime($path)) . '"';
            header('Content-Type: ' . struk_mime_of($file));
            header('Content-Length: ' . (string)filesize($path));
            header('Cache-Control: private, max-age=604800');
            header('ETag: ' . $etag);
            if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
                http_response_code(304);
                exit;
            }
            readfile($path);
            exit;
        }

        default:
            json_err('Aksi tidak dikenal.', 404);
    }
}

/* ---------- POST: semua perubahan data ---------- */

if ($method !== 'POST') {
    json_err('Metode tidak didukung.', 405);
}

// Penjaga sederhana: permintaan lintas-situs tidak boleh mengubah data.
if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'fetch') {
    json_err('Permintaan tidak sah. Muat ulang halaman.', 403);
}

$body = read_json_body();

/* ---------- Aksi autentikasi (tidak menyentuh data keuangan) ---------- */
if ($action === 'auth.login') {
    $token = in_str($body, 'token', '', 400);
    $hasil = auth_login($token);
    if (!$hasil['ok']) {
        json_err((string)$hasil['error'], (int)($hasil['status'] ?? 401), isset($hasil['retryAfter']) ? ['retryAfter' => $hasil['retryAfter']] : []);
    }
    json_ok(['csrf' => $hasil['csrf'], 'expiresAt' => $hasil['expiresAt'], 'loginAt' => $hasil['loginAt'], 'device' => $hasil['device']]);
}

if ($action === 'auth.setup') {
    $token = in_str($body, 'token', '', 400);
    $ulangi = in_str($body, 'token2', '', 400);
    $hasil = auth_setup($token, $ulangi);
    if (!$hasil['ok']) {
        json_err((string)$hasil['error'], (int)($hasil['status'] ?? 422));
    }
    $masuk = auth_login($token);
    if (!$masuk['ok']) {
        json_ok(['created' => true, 'csrf' => '', 'needLogin' => true]);
    }
    json_ok(['created' => true, 'csrf' => $masuk['csrf'], 'expiresAt' => $masuk['expiresAt'], 'loginAt' => $masuk['loginAt'], 'device' => $masuk['device']]);
}

if ($action === 'auth.logout') {
    auth_logout_current();
    json_ok(['loggedOut' => true]);
}

if ($action === 'auth.logoutDevice') {
    $id = in_str($body, 'id', '', 40);
    if ($id === '') {
        json_err('Perangkat tidak dikenali.', 422);
    }
    $okHapus = auth_logout_device($id);
    json_ok(['removed' => $okHapus, 'info' => auth_info()]);
}

if ($action === 'auth.logoutAll') {
    $kecuali = !empty($body['keepCurrent']);
    $jumlah = auth_logout_all($kecuali);
    json_ok(['removed' => $jumlah, 'keptCurrent' => $kecuali, 'loggedOut' => !$kecuali, 'info' => $kecuali ? auth_info() : null]);
}

if ($action === 'auth.changeToken') {
    $lama = in_str($body, 'current', '', 400);
    $baru = in_str($body, 'newToken', '', 400);
    $ulangi = in_str($body, 'newToken2', '', 400);
    $hasil = auth_change_token($lama, $baru, $ulangi);
    if (!$hasil['ok']) {
        json_err((string)$hasil['error'], (int)($hasil['status'] ?? 422));
    }
    json_ok(['changed' => true, 'tokenUpdatedAt' => auth_state()['tokenUpdatedAt'], 'info' => auth_info()]);
}

$db = db_read();

/* ---------- Scan struk: baca teks OCR tanpa mengubah data ---------- */
if ($action === 'receipt.parse') {
    $text = in_str($body, 'text', '', STRUK_MAX_TEXT);
    if ($text === '') {
        json_err('Teks struk kosong. Pastikan foto struk terlihat jelas dan coba lagi.', 422);
    }
    $today = in_str($body, 'today', '', 10);
    json_ok(struk_parse_any($text, $db['categories'], $db['accounts'], valid_date($today) ? $today : null));
}

/* ---------- Bunga otomatis: jalankan di luar db_mutate (hindari lock ganda) ---------- */
if (in_array($action, ['interest.run', 'interest.simulate', 'interest.token'], true)) {
    $date = in_str($body, 'date', '', 10);
    if (!valid_date($date)) {
        $date = date('Y-m-d');
    }

    if ($action === 'interest.simulate') {
        json_ok(['result' => interest_simulate($date, 'api'), 'db' => db_read()]);
    }

    if ($action === 'interest.token') {
        @unlink(CRON_TOKEN_FILE);
        json_ok(['token' => interest_token(true)]);
    }

    // Menjalankan otomatis: mode simulasi tetap tidak menyentuh transaksi/saldo.
    $res = interest_run($date, 'api', !empty($body['force']));
    json_ok(['result' => $res, 'db' => db_read()]);
}

/* ---------- Harga emas: ambil sebelum lock agar tidak menahan file data ---------- */
$goldPriceForBuy = null;
if ($action === 'gold.buy') {
    $given = $body['pricePerGram'] ?? null;
    if (!is_numeric($given) || (float)$given <= 0) {
        $fetched = gold_fetch();
        if (!$fetched['ok']) {
            json_err('Harga emas belum tersedia: ' . ($fetched['error'] ?? 'gagal menghubungi Lakuemas')
                . '. Isi harga per gram secara manual.', 503);
        }
        $goldPriceForBuy = $fetched;
    } else {
        $goldPriceForBuy = gold_cache_read();
    }
}

/** Cari indeks elemen berdasarkan id. */
function idx(array $list, string $id): ?int
{
    foreach ($list as $i => $row) {
        if (($row['id'] ?? null) === $id) {
            return $i;
        }
    }
    return null;
}

function find_row(array $list, string $id): ?array
{
    foreach ($list as $row) {
        if (($row['id'] ?? null) === $id) {
            return $row;
        }
    }
    return null;
}

/** Validasi & normalisasi satu transaksi. */
function tx_payload(array $input, array $db, ?array $existing = null): array
{
    $type = in_str($input, 'type', $existing['type'] ?? 'expense', 20);
    if (!in_array($type, ['income', 'expense', 'transfer'], true)) {
        json_err('Tipe transaksi harus pemasukan, pengeluaran, atau transfer.', 422);
    }
    $date = in_str($input, 'date', $existing['date'] ?? date('Y-m-d'), 10);
    if (!valid_date($date)) {
        json_err('Tanggal tidak valid (format YYYY-MM-DD).', 422);
    }
    $amount = in_amount($input, 'amount');
    if ($amount <= 0) {
        json_err('Jumlah harus lebih besar dari 0.', 422);
    }
    if ($amount > 1_000_000_000_000) {
        json_err('Jumlah terlalu besar.', 422);
    }
    $accountId = in_str($input, 'accountId', $existing['accountId'] ?? '', 60);
    if ($accountId === '' || !find_row($db['accounts'], $accountId)) {
        json_err('Pilih akun yang valid.', 422);
    }
    $toAccountId = in_str($input, 'toAccountId', $existing['toAccountId'] ?? '', 60);
    $categoryId = in_str($input, 'categoryId', $existing['categoryId'] ?? '', 60);

    if ($type === 'transfer') {
        if ($toAccountId === '' || !find_row($db['accounts'], $toAccountId)) {
            json_err('Pilih akun tujuan transfer.', 422);
        }
        if ($toAccountId === $accountId) {
            json_err('Akun asal dan tujuan tidak boleh sama.', 422);
        }
        $categoryId = '';
    } else {
        $toAccountId = '';
        $cat = $categoryId !== '' ? find_row($db['categories'], $categoryId) : null;
        if (!$cat) {
            json_err('Pilih kategori yang valid.', 422);
        }
        if (($cat['type'] ?? '') !== $type) {
            json_err('Kategori tidak cocok dengan tipe transaksi.', 422);
        }
    }

    $note = in_str($input, 'note', $existing['note'] ?? '', 300);

    return [
        'date' => $date,
        'type' => $type,
        'amount' => $amount,
        'accountId' => $accountId,
        'toAccountId' => $toAccountId !== '' ? $toAccountId : null,
        'categoryId' => $categoryId !== '' ? $categoryId : null,
        'note' => $note,
    ];
}

/**
 * Terapkan data hasil scan struk pada baris transaksi.
 *  - $input['receipt'] berisi data URL  -> simpan foto baru
 *  - $input['receipt'] berisi ''        -> hapus foto lama
 *  - kunci 'receipt' tidak dikirim      -> foto lama dipertahankan
 */
function tx_apply_struk(array $row, array $input, ?array $existing, string $id): array
{
    $old = is_array($existing) ? $existing : [];

    if (array_key_exists('receipt', $input)) {
        $incoming = $input['receipt'];
        if (is_string($incoming) && $incoming !== '') {
            $saved = struk_save($incoming, $id);
            if ($saved !== null) {
                struk_remove(isset($old['receipt']) ? (string)$old['receipt'] : null);
                $row['receipt'] = $saved;
            } elseif (!empty($old['receipt']) && struk_path((string)$old['receipt']) !== null) {
                $row['receipt'] = $old['receipt'];
            } else {
                json_err('Foto struk tidak valid atau terlalu besar (maksimal 1,5 MB).', 422);
            }
        } elseif (!empty($old['receipt'])) {
            struk_remove((string)$old['receipt']);
        }
    } elseif (!empty($old['receipt']) && struk_path((string)$old['receipt']) !== null) {
        $row['receipt'] = $old['receipt'];
    }

    if (array_key_exists('items', $input)) {
        $items = struk_clean_items($input['items']);
        if ($items) {
            $row['items'] = $items;
        }
    } elseif (!empty($old['items'])) {
        $row['items'] = $old['items'];
    }

    if (array_key_exists('merchant', $input)) {
        $merchant = in_str($input, 'merchant', '', 80);
        if ($merchant !== '') {
            $row['merchant'] = $merchant;
        }
    } elseif (!empty($old['merchant'])) {
        $row['merchant'] = $old['merchant'];
    }

    return $row;
}

$result = null;
$error = null;
$status = 200;

$ok = db_mutate(function (array &$db) use ($action, $body, $goldPriceForBuy, &$result, &$error, &$status) {
    switch ($action) {
        /* ---------- Transaksi ---------- */
        case 'tx.create': {
            $row = tx_payload($body, $db);
            $row['id'] = gen_id('tx');
            $row['createdAt'] = date('c');
            $row['updatedAt'] = date('c');
            $row = tx_apply_struk($row, $body, null, $row['id']);
            $db['transactions'][] = $row;
            $result = $row;
            break;
        }

        case 'tx.update': {
            $id = in_str($body, 'id', '', 60);
            $i = idx($db['transactions'], $id);
            if ($i === null) { $error = 'Transaksi tidak ditemukan.'; $status = 404; break; }
            $row = tx_payload($body, $db, $db['transactions'][$i]);
            $row['id'] = $id;
            $row['createdAt'] = $db['transactions'][$i]['createdAt'] ?? date('c');
            $row['updatedAt'] = date('c');
            $row = tx_apply_struk($row, $body, $db['transactions'][$i], $id);
            $db['transactions'][$i] = $row;
            $result = $row;
            break;
        }

        case 'tx.delete': {
            $id = in_str($body, 'id', '', 60);
            $i = idx($db['transactions'], $id);
            if ($i === null) { $error = 'Transaksi tidak ditemukan.'; $status = 404; break; }
            struk_remove(isset($db['transactions'][$i]['receipt']) ? (string)$db['transactions'][$i]['receipt'] : null);
            array_splice($db['transactions'], $i, 1);
            $result = ['id' => $id];
            break;
        }

        /* ---------- Akun ---------- */
        case 'acc.create': {
            $name = in_str($body, 'name', '', 60);
            if ($name === '') { $error = 'Nama akun wajib diisi.'; $status = 422; break; }
            $row = [
                'id' => gen_id('acc'),
                'name' => $name,
                'type' => in_str($body, 'type', 'cash', 20),
                'initial' => in_amount($body, 'initial'),
                'color' => in_str($body, 'color', '#3b82f6', 9),
                'icon' => in_str($body, 'icon', '💳', 8),
                'archived' => false,
                'createdAt' => date('c'),
            ];
            $db['accounts'][] = $row;
            $result = ['id' => $row['id']];
            break;
        }

        case 'acc.update': {
            $id = in_str($body, 'id', '', 60);
            $i = idx($db['accounts'], $id);
            if ($i === null) { $error = 'Akun tidak ditemukan.'; $status = 404; break; }
            $name = in_str($body, 'name', $db['accounts'][$i]['name'], 60);
            if ($name === '') { $error = 'Nama akun wajib diisi.'; $status = 422; break; }
            $db['accounts'][$i]['name'] = $name;
            $db['accounts'][$i]['type'] = in_str($body, 'type', $db['accounts'][$i]['type'], 20);
            $db['accounts'][$i]['color'] = in_str($body, 'color', $db['accounts'][$i]['color'], 9);
            $db['accounts'][$i]['icon'] = in_str($body, 'icon', $db['accounts'][$i]['icon'], 8);
            if (array_key_exists('initial', $body)) {
                $db['accounts'][$i]['initial'] = in_amount($body, 'initial');
            }
            if (array_key_exists('archived', $body)) {
                $db['accounts'][$i]['archived'] = in_bool($body, 'archived');
            }
            $result = ['id' => $id];
            break;
        }

        case 'acc.delete': {
            $id = in_str($body, 'id', '', 60);
            $i = idx($db['accounts'], $id);
            if ($i === null) { $error = 'Akun tidak ditemukan.'; $status = 404; break; }
            foreach ($db['transactions'] as $t) {
                if (($t['accountId'] ?? '') === $id || ($t['toAccountId'] ?? '') === $id) {
                    $error = 'Akun masih dipakai transaksi. Arsipkan saja agar riwayat tetap utuh.';
                    $status = 409;
                    break 2;
                }
            }
            array_splice($db['accounts'], $i, 1);
            $result = ['id' => $id];
            break;
        }

        /* ---------- Kategori ---------- */
        case 'cat.create': {
            $name = in_str($body, 'name', '', 60);
            $type = in_str($body, 'type', 'expense', 20);
            if ($name === '') { $error = 'Nama kategori wajib diisi.'; $status = 422; break; }
            if (!in_array($type, ['income', 'expense'], true)) { $error = 'Tipe kategori tidak valid.'; $status = 422; break; }
            $row = [
                'id' => gen_id('cat'),
                'name' => $name,
                'type' => $type,
                'icon' => in_str($body, 'icon', '🏷️', 8),
                'color' => in_str($body, 'color', '#6366f1', 9),
                'archived' => false,
                'createdAt' => date('c'),
            ];
            $db['categories'][] = $row;
            $result = ['id' => $row['id']];
            break;
        }

        case 'cat.update': {
            $id = in_str($body, 'id', '', 60);
            $i = idx($db['categories'], $id);
            if ($i === null) { $error = 'Kategori tidak ditemukan.'; $status = 404; break; }
            $name = in_str($body, 'name', $db['categories'][$i]['name'], 60);
            if ($name === '') { $error = 'Nama kategori wajib diisi.'; $status = 422; break; }
            $type = in_str($body, 'type', $db['categories'][$i]['type'], 20);
            if (!in_array($type, ['income', 'expense'], true)) { $error = 'Tipe kategori tidak valid.'; $status = 422; break; }
            if ($type !== $db['categories'][$i]['type']) {
                foreach ($db['transactions'] as $t) {
                    if (($t['categoryId'] ?? '') === $id) {
                        $error = 'Kategori sudah dipakai transaksi, tipe tidak boleh diubah.';
                        $status = 409;
                        break 2;
                    }
                }
            }
            $db['categories'][$i]['name'] = $name;
            $db['categories'][$i]['type'] = $type;
            $db['categories'][$i]['icon'] = in_str($body, 'icon', $db['categories'][$i]['icon'], 8);
            $db['categories'][$i]['color'] = in_str($body, 'color', $db['categories'][$i]['color'], 9);
            if (array_key_exists('archived', $body)) {
                $db['categories'][$i]['archived'] = in_bool($body, 'archived');
            }
            $result = ['id' => $id];
            break;
        }

        case 'cat.delete': {
            $id = in_str($body, 'id', '', 60);
            $i = idx($db['categories'], $id);
            if ($i === null) { $error = 'Kategori tidak ditemukan.'; $status = 404; break; }
            foreach ($db['transactions'] as $t) {
                if (($t['categoryId'] ?? '') === $id) {
                    $error = 'Kategori masih dipakai transaksi. Arsipkan saja.';
                    $status = 409;
                    break 2;
                }
            }
            foreach ($db['budgets'] as $j => $b) {
                if (($b['categoryId'] ?? '') === $id) {
                    unset($db['budgets'][$j]);
                }
            }
            $db['budgets'] = array_values($db['budgets']);
            array_splice($db['categories'], $i, 1);
            $result = ['id' => $id];
            break;
        }

        /* ---------- Anggaran ---------- */
        case 'budget.set': {
            $month = in_str($body, 'month', date('Y-m'), 7);
            if (!valid_month($month)) { $error = 'Bulan tidak valid.'; $status = 422; break; }
            $categoryId = in_str($body, 'categoryId', '', 60);
            $cat = find_row($db['categories'], $categoryId);
            if (!$cat || ($cat['type'] ?? '') !== 'expense') {
                $error = 'Pilih kategori pengeluaran yang valid.';
                $status = 422;
                break;
            }
            $amount = in_amount($body, 'amount');
            if ($amount <= 0) { $error = 'Nominal anggaran harus lebih dari 0.'; $status = 422; break; }
            $found = false;
            foreach ($db['budgets'] as $j => $b) {
                if ($b['month'] === $month && $b['categoryId'] === $categoryId) {
                    $db['budgets'][$j]['amount'] = $amount;
                    $db['budgets'][$j]['updatedAt'] = date('c');
                    $found = true;
                    $result = ['id' => $b['id']];
                    break;
                }
            }
            if (!$found) {
                $row = [
                    'id' => gen_id('bg'),
                    'month' => $month,
                    'categoryId' => $categoryId,
                    'amount' => $amount,
                    'createdAt' => date('c'),
                    'updatedAt' => date('c'),
                ];
                $db['budgets'][] = $row;
                $result = ['id' => $row['id']];
            }
            break;
        }

        case 'budget.delete': {
            $id = in_str($body, 'id', '', 60);
            $i = idx($db['budgets'], $id);
            if ($i === null) { $error = 'Anggaran tidak ditemukan.'; $status = 404; break; }
            array_splice($db['budgets'], $i, 1);
            $result = ['id' => $id];
            break;
        }

        case 'budget.copy': {
            $from = in_str($body, 'from', '', 7);
            $to = in_str($body, 'to', '', 7);
            if (!valid_month($from) || !valid_month($to)) { $error = 'Bulan tidak valid.'; $status = 422; break; }
            if ($from === $to) { $error = 'Bulan asal dan tujuan sama.'; $status = 422; break; }
            $copied = 0;
            foreach ($db['budgets'] as $b) {
                if (($b['month'] ?? '') !== $from) continue;
                $exists = false;
                foreach ($db['budgets'] as $c) {
                    if ($c['month'] === $to && $c['categoryId'] === $b['categoryId']) { $exists = true; break; }
                }
                if ($exists) continue;
                $db['budgets'][] = [
                    'id' => gen_id('bg'),
                    'month' => $to,
                    'categoryId' => $b['categoryId'],
                    'amount' => (float)$b['amount'],
                    'createdAt' => date('c'),
                    'updatedAt' => date('c'),
                ];
                $copied++;
            }
            $result = ['copied' => $copied];
            break;
        }

        /* ---------- Target tabungan ---------- */
        case 'goal.create': {
            $name = in_str($body, 'name', '', 80);
            if ($name === '') { $error = 'Nama target wajib diisi.'; $status = 422; break; }
            $target = in_amount($body, 'target');
            if ($target <= 0) { $error = 'Nominal target harus lebih dari 0.'; $status = 422; break; }
            $deadline = in_str($body, 'deadline', '', 10);
            if ($deadline !== '' && !valid_date($deadline)) { $error = 'Tanggal target tidak valid.'; $status = 422; break; }
            $row = [
                'id' => gen_id('gl'),
                'name' => $name,
                'target' => $target,
                'deadline' => $deadline,
                'color' => in_str($body, 'color', '#38bdf8', 9),
                'icon' => in_str($body, 'icon', '🎯', 8),
                'note' => in_str($body, 'note', '', 200),
                'done' => false,
                'createdAt' => date('c'),
            ];
            $db['goals'][] = $row;
            $result = ['id' => $row['id']];
            break;
        }

        case 'goal.update': {
            $id = in_str($body, 'id', '', 60);
            $i = idx($db['goals'], $id);
            if ($i === null) { $error = 'Target tidak ditemukan.'; $status = 404; break; }
            $name = in_str($body, 'name', $db['goals'][$i]['name'], 80);
            if ($name === '') { $error = 'Nama target wajib diisi.'; $status = 422; break; }
            $target = in_amount($body, 'target');
            if ($target <= 0) { $error = 'Nominal target harus lebih dari 0.'; $status = 422; break; }
            $deadline = in_str($body, 'deadline', $db['goals'][$i]['deadline'] ?? '', 10);
            if ($deadline !== '' && !valid_date($deadline)) { $error = 'Tanggal target tidak valid.'; $status = 422; break; }
            $db['goals'][$i]['name'] = $name;
            $db['goals'][$i]['target'] = $target;
            $db['goals'][$i]['deadline'] = $deadline;
            $db['goals'][$i]['color'] = in_str($body, 'color', $db['goals'][$i]['color'], 9);
            $db['goals'][$i]['icon'] = in_str($body, 'icon', $db['goals'][$i]['icon'], 8);
            $db['goals'][$i]['note'] = in_str($body, 'note', $db['goals'][$i]['note'] ?? '', 200);
            $db['goals'][$i]['updatedAt'] = date('c');
            $result = ['id' => $id];
            break;
        }

        case 'goal.delete': {
            $id = in_str($body, 'id', '', 60);
            $i = idx($db['goals'], $id);
            if ($i === null) { $error = 'Target tidak ditemukan.'; $status = 404; break; }
            array_splice($db['goals'], $i, 1);
            $db['goalEntries'] = array_values(array_filter($db['goalEntries'], fn($e) => ($e['goalId'] ?? '') !== $id));
            $result = ['id' => $id];
            break;
        }

        case 'goal.entry': {
            $goalId = in_str($body, 'goalId', '', 60);
            if (!find_row($db['goals'], $goalId)) { $error = 'Target tidak ditemukan.'; $status = 404; break; }
            $dir = in_str($body, 'dir', 'in', 10);
            if (!in_array($dir, ['in', 'out'], true)) { $error = 'Jenis setoran tidak valid.'; $status = 422; break; }
            $amount = in_amount($body, 'amount');
            if ($amount <= 0) { $error = 'Nominal harus lebih dari 0.'; $status = 422; break; }
            $date = in_str($body, 'date', date('Y-m-d'), 10);
            if (!valid_date($date)) { $error = 'Tanggal tidak valid.'; $status = 422; break; }
            $row = [
                'id' => gen_id('ge'),
                'goalId' => $goalId,
                'dir' => $dir,
                'amount' => $amount,
                'date' => $date,
                'note' => in_str($body, 'note', '', 200),
                'createdAt' => date('c'),
            ];
            $db['goalEntries'][] = $row;
            $result = ['id' => $row['id']];
            break;
        }

        case 'goal.entry.delete': {
            $id = in_str($body, 'id', '', 60);
            $i = idx($db['goalEntries'], $id);
            if ($i === null) { $error = 'Catatan target tidak ditemukan.'; $status = 404; break; }
            array_splice($db['goalEntries'], $i, 1);
            $result = ['id' => $id];
            break;
        }

        /* ---------- Utang & piutang ---------- */
        case 'debt.create': {
            $type = in_str($body, 'type', 'debt', 20);
            if (!in_array($type, ['debt', 'receivable'], true)) { $error = 'Jenis tidak valid.'; $status = 422; break; }
            $person = in_str($body, 'person', '', 80);
            if ($person === '') { $error = 'Nama orang/pihak wajib diisi.'; $status = 422; break; }
            $amount = in_amount($body, 'amount');
            if ($amount <= 0) { $error = 'Nominal harus lebih dari 0.'; $status = 422; break; }
            $date = in_str($body, 'date', date('Y-m-d'), 10);
            if (!valid_date($date)) { $error = 'Tanggal tidak valid.'; $status = 422; break; }
            $due = in_str($body, 'dueDate', '', 10);
            if ($due !== '' && !valid_date($due)) { $error = 'Jatuh tempo tidak valid.'; $status = 422; break; }
            $row = [
                'id' => gen_id('dt'),
                'type' => $type,
                'person' => $person,
                'amount' => $amount,
                'date' => $date,
                'dueDate' => $due,
                'note' => in_str($body, 'note', '', 200),
                'payments' => [],
                'settled' => false,
                'createdAt' => date('c'),
            ];
            $db['debts'][] = $row;
            $result = ['id' => $row['id']];
            break;
        }

        case 'debt.update': {
            $id = in_str($body, 'id', '', 60);
            $i = idx($db['debts'], $id);
            if ($i === null) { $error = 'Catatan tidak ditemukan.'; $status = 404; break; }
            $person = in_str($body, 'person', $db['debts'][$i]['person'], 80);
            if ($person === '') { $error = 'Nama orang/pihak wajib diisi.'; $status = 422; break; }
            $amount = in_amount($body, 'amount');
            if ($amount <= 0) { $error = 'Nominal harus lebih dari 0.'; $status = 422; break; }
            $due = in_str($body, 'dueDate', $db['debts'][$i]['dueDate'] ?? '', 10);
            if ($due !== '' && !valid_date($due)) { $error = 'Jatuh tempo tidak valid.'; $status = 422; break; }
            $db['debts'][$i]['person'] = $person;
            $db['debts'][$i]['amount'] = $amount;
            $db['debts'][$i]['dueDate'] = $due;
            $db['debts'][$i]['note'] = in_str($body, 'note', $db['debts'][$i]['note'] ?? '', 200);
            $db['debts'][$i]['updatedAt'] = date('c');
            $result = ['id' => $id];
            break;
        }

        case 'debt.delete': {
            $id = in_str($body, 'id', '', 60);
            $i = idx($db['debts'], $id);
            if ($i === null) { $error = 'Catatan tidak ditemukan.'; $status = 404; break; }
            array_splice($db['debts'], $i, 1);
            $result = ['id' => $id];
            break;
        }

        case 'debt.pay': {
            $id = in_str($body, 'id', '', 60);
            $i = idx($db['debts'], $id);
            if ($i === null) { $error = 'Catatan tidak ditemukan.'; $status = 404; break; }
            $amount = in_amount($body, 'amount');
            if ($amount <= 0) { $error = 'Nominal pembayaran harus lebih dari 0.'; $status = 422; break; }
            $date = in_str($body, 'date', date('Y-m-d'), 10);
            if (!valid_date($date)) { $error = 'Tanggal tidak valid.'; $status = 422; break; }
            $db['debts'][$i]['payments'][] = [
                'id' => gen_id('dp'),
                'amount' => $amount,
                'date' => $date,
                'note' => in_str($body, 'note', '', 200),
            ];
            $paid = 0.0;
            foreach ($db['debts'][$i]['payments'] as $p) { $paid += (float)$p['amount']; }
            $db['debts'][$i]['settled'] = $paid >= (float)$db['debts'][$i]['amount'] - 0.001;
            $result = ['id' => $id, 'paid' => $paid];
            break;
        }

        case 'debt.payment.delete': {
            $id = in_str($body, 'id', '', 60);
            $pid = in_str($body, 'paymentId', '', 60);
            $i = idx($db['debts'], $id);
            if ($i === null) { $error = 'Catatan tidak ditemukan.'; $status = 404; break; }
            $db['debts'][$i]['payments'] = array_values(array_filter(
                $db['debts'][$i]['payments'],
                fn($p) => ($p['id'] ?? '') !== $pid
            ));
            $paid = 0.0;
            foreach ($db['debts'][$i]['payments'] as $p) { $paid += (float)$p['amount']; }
            $db['debts'][$i]['settled'] = $paid >= (float)$db['debts'][$i]['amount'] - 0.001;
            $result = ['id' => $id];
            break;
        }

        /* ---------- Pengaturan ---------- */
        case 'settings.update': {
            $s = $db['settings'];
            $s['appName'] = in_str($body, 'appName', $s['appName'], 40) ?: 'Uang';
            $s['ownerName'] = in_str($body, 'ownerName', $s['ownerName'], 60);
            $s['currency'] = in_str($body, 'currency', $s['currency'], 8);
            $s['currencySymbol'] = in_str($body, 'currencySymbol', $s['currencySymbol'], 6);
            $theme = in_str($body, 'theme', $s['theme'], 10);
            $s['theme'] = in_array($theme, ['dark', 'light'], true) ? $theme : 'dark';
            $db['settings'] = $s;
            $result = ['settings' => $s];
            break;
        }

        /* ---------- Akun otomatis per kategori ---------- */
        case 'settings.rules': {
            $input = is_array($body['rules'] ?? null) ? $body['rules'] : [];
            $catIds = array_map(fn($c) => (string)$c['id'], $db['categories']);
            $accIds = array_map(fn($x) => (string)$x['id'], $db['accounts']);
            $rules = [];
            foreach ($input as $catId => $accId) {
                $catId = (string)$catId;
                $accId = (string)$accId;
                if ($accId === '') {
                    continue;
                }
                if (!in_array($catId, $catIds, true) || !in_array($accId, $accIds, true)) {
                    json_err('Kategori atau akun tidak dikenal.', 422);
                }
                $rules[$catId] = $accId;
            }
            $db['settings']['accountRules'] = $rules;
            $result = ['accountRules' => $rules];
            break;
        }

        /* ---------- Bunga otomatis (setelan) ---------- */
        case 'interest.save': {
            $current = interest_settings($db);
            $input = is_array($body['interest'] ?? null) ? $body['interest'] : $body;
            try {
                $next = interest_sanitize($input, $current);
            } catch (InvalidArgumentException $e) {
                $error = $e->getMessage();
                $status = 422;
                break;
            }
            $db['settings']['interest'] = $next;
            $result = interest_summary($db, date('Y-m-d'));
            break;
        }

        /* ---------- Emas digital ---------- */
        case 'gold.buy': {
            try {
                $result = gold_buy_apply($db, $body, $goldPriceForBuy);
            } catch (InvalidArgumentException $e) {
                $error = $e->getMessage();
                $status = 422;
                break;
            }
            break;
        }

        case 'gold.delete': {
            try {
                $result = gold_delete_apply($db, in_str($body, 'id', '', 40));
            } catch (InvalidArgumentException $e) {
                $error = $e->getMessage();
                $status = 404;
                break;
            }
            break;
        }

        /* ---------- Data ---------- */
        case 'data.restore': {
            $incoming = $body['db'] ?? null;
            if (!is_array($incoming) || !isset($incoming['transactions']) || !is_array($incoming['transactions'])) {
                $error = 'Berkas cadangan tidak valid.';
                $status = 422;
                break;
            }
            $restored = db_normalize($incoming);
            $db = $restored;
            struk_prune($db);
            $result = ['restored' => true];
            break;
        }

        case 'data.reset': {
            $keepSettings = $db['settings'];
            $fresh = db_defaults();
            $fresh['settings'] = $keepSettings;
            $db = $fresh;
            struk_clear();
            $result = ['reset' => true];
            break;
        }

        case 'data.demo': {
            $db = api_demo_data($db);
            struk_clear();
            $result = ['demo' => true];
            break;
        }

        default:
            $error = 'Aksi tidak dikenal.';
            $status = 404;
    }
    return $result;
}, $result);

if ($ok === false) {
    json_err('Gagal menyimpan data. Pastikan folder data/ dapat ditulis (chmod 775 data).', 500);
}
if ($error !== null) {
    json_err($error, $status);
}
json_ok(['result' => $result, 'db' => db_read()]);
