<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Penyimpanan JSON — satu file, tulis atomik dengan flock.
 * db_mutate() menjamin baca-ubah-tulis terjadi di dalam satu kunci eksklusif,
 * sehingga dua permintaan yang hampir bersamaan tidak saling menimpa.
 */

function db_ensure_dir(): void
{
    if (!is_dir(DATA_DIR)) {
        @mkdir(DATA_DIR, 0775, true);
    }
    if (!is_dir(BACKUP_DIR)) {
        @mkdir(BACKUP_DIR, 0775, true);
    }
    // Cegah langsung diakses lewat web bila file .htaccess belum ikut terunggah.
    $ht = DATA_DIR . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "Require all denied\nOrder allow,deny\nDeny from all\nOptions -Indexes\n");
    }
}

/** Normalisasi struktur supaya file lama/rusak sebagian tetap bisa dipakai. */
function db_normalize(array $db): array
{
    $def = db_defaults();
    foreach ($def as $key => $fallback) {
        if (!array_key_exists($key, $db) || gettype($db[$key]) !== gettype($fallback)) {
            $db[$key] = $fallback;
        }
    }
    $db['settings'] = array_merge($def['settings'], is_array($db['settings']) ? $db['settings'] : []);
    foreach (['accounts', 'categories', 'transactions', 'budgets', 'goals', 'goalEntries', 'debts', 'gold'] as $list) {
        if (!is_array($db[$list])) {
            $db[$list] = [];
        }
        $db[$list] = array_values(array_filter($db[$list], 'is_array'));
    }
    $db['settings']['accountRules'] = db_seed_account_rules($db);
    return $db;
}


/**
 * Aturan "kategori -> akun" yang bisa diubah pengguna.
 * Bila belum diatur, dicoba tebakan awal: kategori bernama OSINT memakai
 * akun DANA Business. Semua hasilnya berupa DATA yang bisa diedit di
 * Pengaturan, bukan aturan kaku di dalam kode.
 */
function db_account_rules(array $rules): array
{
    $bersih = [];
    foreach ($rules as $catId => $accId) {
        if (is_string($catId) && is_string($accId) && $catId !== '' && $accId !== '') {
            $bersih[$catId] = $accId;
        }
    }
    return $bersih;
}

/** Tebakan awal aturan akun untuk data yang belum punya setelan. */
function db_seed_account_rules(array $db): array
{
    if (array_key_exists('accountRules', $db['settings'])) {
        return db_account_rules(is_array($db['settings']['accountRules']) ? $db['settings']['accountRules'] : []);
    }
    $rules = [];
    $akun = '';
    foreach ($db['accounts'] as $a) {
        $nama = strtolower((string)($a['name'] ?? ''));
        if ($nama !== '' && str_contains($nama, 'dana') && (str_contains($nama, 'business') || str_contains($nama, 'bisnis'))) {
            $akun = (string)$a['id'];
            break;
        }
    }
    if ($akun === '') {
        return [];
    }
    foreach ($db['categories'] as $c) {
        $nama = strtolower((string)($c['name'] ?? ''));
        if (($c['type'] ?? '') === 'income' && str_contains($nama, 'osint')) {
            $rules[(string)$c['id']] = $akun;
        }
    }
    return db_account_rules($rules);
}

function db_decode(string $raw): array
{
    // Buang penutup PHP di awal berkas (lihat DB_GUARD) sebelum diurai.
    if (str_starts_with($raw, '<?php')) {
        $end = strpos($raw, '?>');
        $raw = $end === false ? '' : substr($raw, $end + 2);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return db_defaults();
    }
    return db_normalize($data);
}

/** Baca seluruh data. Membuat file awal bila belum ada. */
function db_read(): array
{
    db_ensure_dir();
    db_migrate_legacy();
    if (!is_file(DB_FILE)) {
        $db = db_defaults();
        db_write($db);
        return $db;
    }
    $raw = @file_get_contents(DB_FILE);
    if ($raw === false || $raw === '') {
        return db_defaults();
    }

    // Periksa keutuhan isi. Data yang rusak TIDAK ditimpa diam-diam: berkasnya
    // disalin ke backup/ lebih dulu, lalu aplikasi menampilkan pesan yang jelas.
    $body = $raw;
    if (str_starts_with($body, '<?php')) {
        $end = strpos($body, '?>');
        $body = $end === false ? '' : substr($body, $end + 2);
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        if (trim($body) !== '') {
            db_ensure_dir();
            @copy(DB_FILE, BACKUP_DIR . '/db-rusak-' . date('Ymd_His') . '.php');
            throw new RuntimeException(
                'Berkas data (data/db.php) tidak bisa dibaca — kemungkinan rusak atau terpotong saat diunggah. '
                . 'Salinan berkas disimpan di folder backup/. Pulihkan dari cadangan terakhir, atau minta hosting '
                . 'memulihkan berkas dari backup harian.'
            );
        }
        return db_defaults();
    }
    return db_normalize($data);
}

/**
 * Pindahkan berkas lama data/db.json ke format baru (db.php + penutup PHP).
 * Berkas lama TIDAK dihapus: dipindahkan ke backup/data agar tetap bisa diperiksa.
 */
function db_migrate_legacy(): void
{
    if (is_file(DB_FILE) || !is_file(DB_LEGACY_FILE)) {
        return;
    }
    $raw = @file_get_contents(DB_LEGACY_FILE);
    if ($raw === false || trim($raw) === '') {
        return;
    }
    $db = db_decode($raw);
    if (db_write($db)) {
        // Simpan salinan berkas lama dalam format berpenutup PHP, lalu buang .json
        // supaya tidak ada berkas data ber-ekstensi .json yang bisa diunduh.
        @file_put_contents(BACKUP_DIR . '/db-json-lama-' . date('Ymd_His') . '.php', db_payload($db), LOCK_EX);
        @unlink(DB_LEGACY_FILE);
    }
}

function db_encode(array $db): string
{
    return json_encode(
        $db,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );
}

/** Isi berkas yang ditulis ke disk: penutup PHP + JSON. */
function db_payload(array $db): string
{
    return DB_GUARD . db_encode($db);
}

/** Tulis seluruh data (dipakai juga oleh restore). */
function db_write(array $db): bool
{
    db_ensure_dir();
    $db = db_normalize($db);
    $json = db_encode($db);
    if ($json === false) {
        return false;
    }
    $payload = DB_GUARD . $json;
    $tmp = DB_FILE . '.tmp';
    if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
        return false;
    }
    if (!@rename($tmp, DB_FILE)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/**
 * Baca → ubah → tulis di bawah satu kunci eksklusif.
 * $fn menerima &$db dan boleh mengembalikan nilai apa pun (diteruskan ke $result).
 */
function db_mutate(callable $fn, &$result = null, bool $makeBackup = true): bool
{
    db_ensure_dir();
    if (!is_file(DB_FILE)) {
        db_write(db_defaults());
    }
    $fp = @fopen(DB_FILE, 'c+b');
    if (!$fp) {
        return false;
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return false;
    }
    $raw = stream_get_contents($fp);
    $db = db_decode($raw === false ? '' : $raw);

    if ($makeBackup) {
        db_backup_snapshot($db);
    }

    $before = $db;
    $ret = $fn($db);
    $db = db_normalize(is_array($db) ? $db : $before);
    $result = $ret;

    $json = db_encode($db);
    $ok = false;
    if ($json !== false) {
        $payload = DB_GUARD . $json;
        ftruncate($fp, 0);
        rewind($fp);
        $ok = fwrite($fp, $payload) !== false;
        fflush($fp);
    }
    flock($fp, LOCK_UN);
    fclose($fp);
    return $ok;
}

/** Snapshot berkala untuk jaring pengaman (maksimal MAX_BACKUPS file). */
function db_backup_snapshot(array $db): void
{
    if (!is_dir(BACKUP_DIR) && !@mkdir(BACKUP_DIR, 0775, true)) {
        return;
    }
    $stamp = date('Ymd_His');
    $file = BACKUP_DIR . '/db-' . $stamp . '.php';
    if (is_file($file)) {
        return; // sudah ada snapshot pada detik ini
    }
    @file_put_contents($file, db_payload($db), LOCK_EX);

    $files = array_merge(glob(BACKUP_DIR . '/db-*.php') ?: [], glob(BACKUP_DIR . '/db-*.json') ?: []);
    if (count($files) > MAX_BACKUPS) {
        sort($files);
        foreach (array_slice($files, 0, count($files) - MAX_BACKUPS) as $old) {
            @unlink($old);
        }
    }
}

function db_backup_list(): array
{
    $out = [];
    foreach (array_merge(glob(BACKUP_DIR . '/db-*.php') ?: [], glob(BACKUP_DIR . '/db-*.json') ?: []) as $f) {
        $out[] = ['file' => basename($f), 'size' => (int)@filesize($f), 'mtime' => (int)@filemtime($f)];
    }
    usort($out, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    return $out;
}
