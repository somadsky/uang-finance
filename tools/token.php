<?php
declare(strict_types=1);

/**
 * Alat baris perintah pemilik: mengelola token akses Uang.
 * Dipakai saat pemilik lupa token atau ingin mengeluarkan semua perangkat
 * tanpa membuka aplikasi (mis. server hanya bisa diakses lewat SSH/cPanel).
 *
 * Contoh (jalankan dari folder aplikasi):
 *   php tools/token.php                 -> status token & sesi
 *   php tools/token.php --ganti "token-baru-minimal-12"
 *   php tools/token.php --keluar-semua
 *   php tools/token.php --riwayat
 *   php tools/token.php --hapus-token   -> token dihapus, aplikasi minta setup ulang
 *
 * Token TIDAK pernah ditampilkan; yang tersimpan hanya hash password_hash().
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Alat ini hanya untuk baris perintah.\n");
}

define('UANG_ROOT', dirname(__DIR__));
chdir(UANG_ROOT);

require UANG_ROOT . '/inc/config.php';
require UANG_ROOT . '/inc/helpers.php';
require UANG_ROOT . '/inc/auth.php';

$args = array_slice($argv, 1);
$mode = $args[0] ?? '';

function info_baris(string $label, string $nilai): void
{
    printf("%-18s %s\n", $label, $nilai);
}

if ($mode === '' || $mode === '--status') {
    $state = auth_state();
    info_baris('Aplikasi', APP_NAME . ' v' . APP_VERSION);
    info_baris('Token', auth_token_exists() ? 'sudah dibuat' : 'BELUM ADA (buka aplikasi untuk membuat)');
    if (!empty($state['tokenUpdatedAt'])) {
        info_baris('Token diganti', (string)$state['tokenUpdatedAt']);
    }
    info_baris('Sesi aktif', (string)auth_count_active_sessions());
    info_baris('Perangkat', (string)count($state['devices'] ?? []));
    info_baris('Berkas token', AUTH_FILE);
    exit(0);
}

if ($mode === '--ganti') {
    $baru = $args[1] ?? '';
    if (strlen($baru) < 12) {
        fwrite(STDERR, "Token baru minimal 12 karakter.\n");
        exit(1);
    }
    auth_set_token($baru);
    auth_logout_all();
    echo "Token diganti. Semua sesi dikeluarkan; masuk lagi dengan token baru.\n";
    exit(0);
}

if ($mode === '--keluar-semua') {
    $n = auth_logout_all();
    echo "Sesi dikeluarkan: {$n}. Token tidak berubah.\n";
    exit(0);
}

if ($mode === '--riwayat') {
    $state = auth_state();
    $riwayat = array_slice($state['history'] ?? [], 0, 20);
    if (!$riwayat) {
        echo "Belum ada riwayat.\n";
        exit(0);
    }
    foreach ($riwayat as $h) {
        printf("%-20s %-10s %-15s %s\n",
            (string)($h['time'] ?? '-'),
            (string)($h['result'] ?? '-'),
            (string)($h['ip'] ?? '-'),
            trim(((string)($h['browser'] ?? '')) . ' ' . ((string)($h['os'] ?? ''))));
    }
    exit(0);
}

if ($mode === '--hapus-token') {
    if (is_file(AUTH_FILE)) {
        unlink(AUTH_FILE);
    }
    echo "Token dihapus. Buka aplikasi untuk membuat token baru.\n";
    exit(0);
}

fwrite(STDERR, "Perintah tidak dikenal.\n"
    . "Pakai: --status | --ganti <token> | --keluar-semua | --riwayat | --hapus-token\n");
exit(1);
