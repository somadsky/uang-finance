<?php
declare(strict_types=1);

/**
 * Konfigurasi aplikasi Uang — keuangan pribadi, flat-file (tanpa database).
 * Semua aturan/nilai awal di bawah ini adalah DATA yang bisa diedit lewat
 * menu Pengaturan di dalam aplikasi; jangan menaruh aturan pribadi di logika.
 */

define('APP_NAME', 'Uang');
define('APP_VERSION', '1.4.0');
// APP_ROOT/DATA_DIR boleh ditentukan lebih dulu (dipakai pengujian dengan folder sementara).
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}
if (!defined('DATA_DIR')) {
    define('DATA_DIR', APP_ROOT . '/data');
}
/**
 * Berkas data memakai ekstensi .php dan dibuka dengan "<?php exit; ?>".
 * Bila seseorang membuka berkas ini lewat browser, PHP berhenti di baris
 * pertama sehingga isi catatan keuangan tidak pernah terkirim — berlaku di
 * Apache maupun nginx, tidak bergantung pada .htaccess.
 */
if (!defined('DB_FILE')) {
    define('DB_FILE', DATA_DIR . '/db.php');
}
if (!defined('DB_LEGACY_FILE')) {
    define('DB_LEGACY_FILE', DATA_DIR . '/db.json');
}
define('DB_GUARD', "<?php exit; /* berkas data Uang - jangan diakses langsung */ ?>\n");
if (!defined('BACKUP_DIR')) {
    define('BACKUP_DIR', APP_ROOT . '/backup/data');
}
if (!defined('MAX_BACKUPS')) {
    define('MAX_BACKUPS', 40);
}
define('MAX_IMPORT_BYTES', 8 * 1024 * 1024);

/** Folder catatan cron & cache harga (boleh ditulis, tidak bisa diakses langsung). */
if (!defined('LOG_DIR')) {
    define('LOG_DIR', DATA_DIR . '/logs');
}
if (!defined('CACHE_DIR')) {
    define('CACHE_DIR', DATA_DIR . '/cache');
}
if (!defined('CRON_TOKEN_FILE')) {
    define('CRON_TOKEN_FILE', DATA_DIR . '/cron-token.php');
}

date_default_timezone_set('Asia/Jakarta');

/** Pengaturan awal (bisa diubah pengguna). */
function default_settings(): array
{
    return [
        'appName'      => 'Uang',
        'ownerName'    => '',
        'currency'     => 'IDR',
        'currencySymbol' => 'Rp',
        'locale'       => 'id-ID',
        'monthStartDay'=> 1,
        'theme'        => 'dark',
        'createdAt'    => date('c'),
        'interest'     => default_interest_settings(),
    ];
}

/**
 * Ketentuan bunga tabungan SeaBank — DATA, bukan logika.
 * Semua angka di sini bisa diubah pengguna lewat Pengaturan, sehingga aplikasi
 * tetap bisa dipakai untuk bank lain tanpa menyentuh kode.
 */
function default_interest_settings(): array
{
    return [
        'enabled'      => false,
        'mode'         => 'simulasi',   // 'simulasi' (tidak menyentuh saldo) | 'aktual'
        'accountId'    => '',           // kosong = cari akun bernama "SeaBank"
        'bankLabel'    => 'SeaBank',
        'time'         => '01:30',
        'rateBelow'    => 2.5,          // % per tahun untuk saldo di bawah ambang
        'rateAtLeast'  => 3.5,          // % per tahun untuk saldo >= ambang
        'threshold'    => 150000000,    // ambang saldo (Rp)
        'taxRate'      => 20,           // % pajak atas bunga
        'daysPerYear'  => 365,
        'categoryName' => 'Bunga Tabungan',
        'note'         => 'Bunga Harian SeaBank',
        'lastRunAt'    => '',
        'lastRunDate'  => '',
        'lastStatus'   => '',
        'lastNet'      => null,
    ];
}

/**
 * Dompet/rekening awal.
 * Daftar ini hanya NILAI AWAL yang disimpan sebagai data — pengguna dapat
 * menambah, mengubah, atau mengarsipkannya kapan pun lewat menu Dompet.
 */
function default_accounts(): array
{
    $now = date('c');
    return [
        ['id' => 'acc_bca',     'name' => 'BCA',      'type' => 'bank',    'initial' => 0, 'color' => '#0060af', 'icon' => '🏦', 'archived' => false, 'createdAt' => $now],
        ['id' => 'acc_gopay',   'name' => 'GoPay',    'type' => 'ewallet', 'initial' => 0, 'color' => '#00aed6', 'icon' => '📱', 'archived' => false, 'createdAt' => $now],
        ['id' => 'acc_dana',    'name' => 'DANA',     'type' => 'ewallet', 'initial' => 0, 'color' => '#118eea', 'icon' => '💳', 'archived' => false, 'createdAt' => $now],
        ['id' => 'acc_seabank', 'name' => 'SeaBank',  'type' => 'bank',    'initial' => 0, 'color' => '#ff7a00', 'icon' => '🏛️', 'archived' => false, 'createdAt' => $now],
    ];
}

/** Kategori awal (pengeluaran & pemasukan). */
function default_categories(): array
{
    $now = date('c');
    $rows = [
        ['cat_makan',      'Makan & Minum',        'expense', '🍜', '#f97316'],
        ['cat_transport',  'Transportasi',         'expense', '🚌', '#0ea5e9'],
        ['cat_belanja',    'Belanja Harian',       'expense', '🛒', '#eab308'],
        ['cat_tagihan',    'Tagihan & Utilitas',   'expense', '💡', '#f43f5e'],
        ['cat_kesehatan',  'Kesehatan',            'expense', '🩺', '#14b8a6'],
        ['cat_pendidikan', 'Pendidikan',           'expense', '📚', '#8b5cf6'],
        ['cat_hiburan',    'Hiburan',              'expense', '🎬', '#ec4899'],
        ['cat_rumah',      'Rumah & Sewa',         'expense', '🏠', '#84cc16'],
        ['cat_cicilan',    'Cicilan & Utang',      'expense', '💳', '#ef4444'],
        ['cat_donasi',     'Donasi & Zakat',       'expense', '🤝', '#06b6d4'],
        ['cat_investasi',  'Tabungan & Investasi', 'expense', '📈', '#10b981'],
        ['cat_lain_exp',   'Pengeluaran Lain',     'expense', '📦', '#94a3b8'],
        ['cat_gaji',       'Gaji',                 'income',  '💼', '#22c55e'],
        ['cat_bonus',      'Bonus & THR',          'income',  '🎁', '#84cc16'],
        ['cat_usaha',      'Usaha',                'income',  '🏪', '#0ea5e9'],
        ['cat_hasil_inv',  'Hasil Investasi',      'income',  '📊', '#8b5cf6'],
        ['cat_lain_inc',   'Pemasukan Lain',       'income',  '💰', '#64748b'],
    ];

    $out = [];
    foreach ($rows as [$id, $name, $type, $icon, $color]) {
        $out[] = [
            'id' => $id, 'name' => $name, 'type' => $type, 'icon' => $icon,
            'color' => $color, 'archived' => false, 'createdAt' => $now,
        ];
    }
    return $out;
}

/** Struktur database kosong. */
function db_defaults(): array
{
    return [
        'version'      => 1,
        'settings'     => default_settings(),
        'accounts'     => default_accounts(),
        'categories'   => default_categories(),
        'transactions' => [],
        'budgets'      => [],
        'goals'        => [],
        'goalEntries'  => [],
        'debts'        => [],
        'gold'         => [],
        'demo'         => false,
    ];
}
