<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    http_response_code(403);
    exit('Forbidden');
}

/** Escape untuk output HTML. */
function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Kirim JSON lalu berhenti. */
function json_out($payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_ok($data = null, int $status = 200): void
{
    json_out(['ok' => true, 'data' => $data], $status);
}

function json_err(string $message, int $status = 400, array $extra = []): void
{
    json_out(array_merge(['ok' => false, 'error' => $message], $extra), $status);
}

/** ID unik dengan prefix. */
function gen_id(string $prefix): string
{
    return $prefix . '_' . date('ymdHis') . '_' . bin2hex(random_bytes(4));
}

/** Validasi tanggal YYYY-MM-DD yang benar-benar ada. */
function valid_date(string $d): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        return false;
    }
    [$y, $m, $day] = array_map('intval', explode('-', $d));
    return checkdate($m, $day, $y);
}

function valid_month(string $m): bool
{
    if (!preg_match('/^\d{4}-\d{2}$/', $m)) {
        return false;
    }
    [$y, $mo] = array_map('intval', explode('-', $m));
    return $y >= 1900 && $y <= 2999 && $mo >= 1 && $mo <= 12;
}

/** Ambil nilai string dari input, dipangkas & dibatasi panjangnya. */
function in_str($src, string $key, string $default = '', int $max = 300): string
{
    $v = is_array($src) && array_key_exists($key, $src) ? $src[$key] : $default;
    if (is_array($v) || is_object($v)) {
        return $default;
    }
    $v = trim((string)$v);
    if (function_exists('mb_substr')) {
        $v = mb_substr($v, 0, $max, 'UTF-8');
    } else {
        $v = substr($v, 0, $max);
    }
    return $v;
}

/** Angka rupiah: terima "1.500.000", "1500000", "1500000,50". */
function in_amount($src, string $key): float
{
    $raw = is_array($src) && array_key_exists($key, $src) ? $src[$key] : 0;
    if (is_string($raw)) {
        $raw = trim($raw);
        $raw = str_replace([' ', "\u{00A0}", 'Rp', 'rp', 'RP'], '', $raw);
        if (str_contains($raw, ',') && str_contains($raw, '.')) {
            // format Indonesia: 1.234.567,89
            $raw = str_replace('.', '', $raw);
            $raw = str_replace(',', '.', $raw);
        } elseif (str_contains($raw, ',')) {
            $raw = str_replace(',', '.', $raw);
        } else {
            $raw = str_replace('.', '', $raw); // titik = pemisah ribuan
        }
    }
    $val = (float)$raw;
    if (!is_finite($val)) {
        return 0.0;
    }
    return round(abs($val), 2);
}

function in_bool($src, string $key, bool $default = false): bool
{
    if (!is_array($src) || !array_key_exists($key, $src)) {
        return $default;
    }
    $v = $src[$key];
    if (is_bool($v)) return $v;
    if (is_int($v)) return $v !== 0;
    $s = strtolower(trim((string)$v));
    return in_array($s, ['1', 'true', 'ya', 'yes', 'on'], true);
}

/** Baca body JSON permintaan. */
function read_json_body(): array
{
    $ctype = $_SERVER['CONTENT_TYPE'] ?? '';
    $raw = file_get_contents('php://input') ?: '';
    if (str_contains(strtolower($ctype), 'application/json')) {
        $data = json_decode($raw, true);
        if (is_array($data)) {
            return $data;
        }
        return $_POST ?: [];
    }
    if ($_POST) {
        return $_POST;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function format_rupiah($n, string $symbol = 'Rp'): string
{
    return $symbol . ' ' . number_format((float)$n, 0, ',', '.');
}

function csv_cell($v): string
{
    $s = (string)$v;
    if (preg_match('/^[=+\-@]/', $s)) {
        $s = "'" . $s; // cegah formula injection di Excel
    }
    return '"' . str_replace('"', '""', $s) . '"';
}
