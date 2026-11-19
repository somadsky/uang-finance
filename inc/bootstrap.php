<?php
/**
 * Bootstrap Uang — dijalankan sebelum berkas lain.
 *
 * Tugasnya dua:
 * 1. Memastikan versi PHP cukup (minimal 7.4) dan memberi pesan yang jelas
 *    kalau hosting memakai versi lebih lama — bukan halaman "HTTP 500" kosong.
 * 2. Menyediakan polyfill untuk fungsi PHP 8 (str_contains, str_starts_with,
 *    str_ends_with) agar aplikasi tetap jalan di PHP 7.4.
 *
 * Berkas ini sengaja hanya memakai sintaks PHP 7.4 ke bawah.
 */

if (defined('UANG_BOOTSTRAP')) {
    return;
}
define('UANG_BOOTSTRAP', true);

// Berkas ini hanya untuk di-include. Bila dibuka langsung lewat URL, diamkan.
if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME'])) {
    $diriSendiri = realpath(__FILE__);
    $ygDiminta = realpath((string)$_SERVER['SCRIPT_FILENAME']);
    if ($diriSendiri !== false && $ygDiminta !== false && $diriSendiri === $ygDiminta) {
        if (!headers_sent()) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            header('X-Robots-Tag: noindex, nofollow');
        }
        echo 'Berkas internal aplikasi.';
        exit;
    }
}

if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle)
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle)
    {
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle)
    {
        if ($needle === '') {
            return true;
        }
        return substr($haystack, -strlen($needle)) === $needle;
    }
}

if (PHP_VERSION_ID < 70400) {
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8', true, 500);
        header('X-Robots-Tag: noindex, nofollow');
    }
    $v = PHP_VERSION;
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Butuh PHP 7.4 atau lebih baru</title>'
        . '<style>body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:#0f172a;color:#e6edf7;'
        . 'margin:0;padding:32px;line-height:1.6}main{max-width:640px;margin:0 auto;background:#16223a;border:1px solid #253552;'
        . 'border-radius:16px;padding:24px 26px}h1{font-size:1.25rem;margin:0 0 12px}code{background:#0b1526;padding:2px 6px;'
        . 'border-radius:6px}ol{padding-left:20px}li{margin:6px 0}.muted{color:#93a4bf;font-size:.9rem}</style></head><body><main>'
        . '<h1>Hosting memakai PHP ' . htmlspecialchars($v, ENT_QUOTES, 'UTF-8') . ' — aplikasi butuh PHP 7.4+</h1>'
        . '<p>Aplikasi <strong>Uang</strong> belum bisa dijalankan karena versi PHP di hosting terlalu lama. '
        . 'Ini bukan kerusakan berkas, jadi cukup naikkan versi PHP.</p>'
        . '<ol><li>Buka cPanel → <strong>MultiPHP Manager</strong> (atau <strong>Select PHP Version</strong>).</li>'
        . '<li>Pilih domain/subfolder aplikasi ini, lalu ganti versi ke <strong>PHP 8.1</strong>, <strong>8.2</strong>, atau <strong>8.3</strong>.</li>'
        . '<li>Muat ulang halaman ini.</li></ol>'
        . '<p class="muted">Bila tidak ada menu tersebut, hubungi penyedia hosting dan minta versi PHP minimal 7.4 '
        . '(disarankan 8.1+).</p>'
        . '<p class="muted">Setelah PHP diperbarui, buka <code>diagnosa.php</code> untuk memeriksa izin tulis folder data.</p>'
        . '</main></body></html>';
    exit;
}
