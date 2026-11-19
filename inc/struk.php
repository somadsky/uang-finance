<?php
declare(strict_types=1);

/**
 * Fitur Scan Struk Belanja.
 *
 * Bagian ini menangani dua hal:
 *  1. Menyimpan foto struk yang sudah diperkecil di browser ke data/struk/.
 *  2. Membaca teks hasil OCR (tesseract di browser) dan menebak nama toko,
 *     tanggal, total, rincian barang, serta kategori pengeluaran.
 *
 * Semua aturan penebakan di bawah ini adalah DATA (daftar kata kunci) yang
 * bisa disesuaikan tanpa mengubah alur program.
 */

if (!defined('APP_ROOT')) {
    http_response_code(403);
    exit('Forbidden');
}

define('STRUK_MAX_BYTES', 1572864); // 1,5 MB setelah di-decode
define('STRUK_MAX_ITEMS', 40);
define('STRUK_MAX_TEXT', 20000);

/* --------------------------------------------------------------- penyimpanan */

function struk_dir(): string
{
    return DATA_DIR . '/struk';
}

function struk_ensure_dir(): void
{
    $dir = struk_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "Require all denied\nOrder allow,deny\nDeny from all\nOptions -Indexes\n");
    }
}

/** Jenis gambar yang diterima: mime => ekstensi. */
function struk_mime_map(): array
{
    return [
        'image/jpeg' => 'jpg',
        'image/jpg'  => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];
}

/**
 * Simpan foto struk dari data URL base64. Mengembalikan nama berkas atau null.
 * Berkas selalu divalidasi ulang lewat getimagesize agar yang tersimpan
 * benar-benar gambar, bukan berkas lain yang disamarkan.
 */
function struk_save(?string $dataUrl, string $id): ?string
{
    if (!is_string($dataUrl) || $dataUrl === '') {
        return null;
    }
    if (strlen($dataUrl) > 4 * 1024 * 1024) {
        return null;
    }
    if (!preg_match('#^data:(image/[a-z+]+);base64,(.+)$#is', $dataUrl, $m)) {
        return null;
    }
    $mime = strtolower($m[1]);
    $map = struk_mime_map();
    if (!isset($map[$mime])) {
        return null;
    }
    $bin = base64_decode(preg_replace('/\s+/', '', $m[2]) ?? '', true);
    if ($bin === false || $bin === '' || strlen($bin) > STRUK_MAX_BYTES) {
        return null;
    }
    $info = @getimagesizefromstring($bin);
    if (!is_array($info) || empty($info[2])) {
        return null;
    }
    $typeToMime = [IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_PNG => 'image/png', IMAGETYPE_WEBP => 'image/webp'];
    $realMime = $typeToMime[$info[2]] ?? '';
    if ($realMime === '' || $map[$realMime] !== $map[$mime]) {
        return null;
    }

    struk_ensure_dir();
    $safe = preg_replace('/[^A-Za-z0-9_]/', '', $id) ?? '';
    if ($safe === '') {
        return null;
    }
    $file = $safe . '.' . $map[$realMime];
    $path = struk_dir() . '/' . $file;
    if (@file_put_contents($path, $bin, LOCK_EX) === false) {
        return null;
    }
    @chmod($path, 0644);
    return $file;
}

/** Hapus satu foto struk (nama berkas divalidasi ketat). */
function struk_remove(?string $file): void
{
    if (!is_string($file) || $file === '') {
        return;
    }
    $path = struk_path($file);
    if ($path !== null) {
        @unlink($path);
    }
}

/** Path absolut foto struk bila nama berkas valid dan ada. */
function struk_path(string $file): ?string
{
    if (!preg_match('/^[A-Za-z0-9_]+\.(jpg|png|webp)$/', $file)) {
        return null;
    }
    $path = struk_dir() . '/' . $file;
    return is_file($path) ? $path : null;
}

function struk_mime_of(string $file): string
{
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if ($ext === 'png') return 'image/png';
    if ($ext === 'webp') return 'image/webp';
    return 'image/jpeg';
}

/** Buang semua foto struk (dipakai saat data dikosongkan / dimuat contoh). */
function struk_clear(): void
{
    foreach (glob(struk_dir() . '/*.{jpg,png,webp}', GLOB_BRACE) ?: [] as $f) {
        @unlink($f);
    }
}

/** Hapus foto yang tidak lagi dirujuk transaksi mana pun. */
function struk_prune(array $db): void
{
    $keep = [];
    foreach (($db['transactions'] ?? []) as $t) {
        if (!empty($t['receipt']) && is_string($t['receipt'])) {
            $keep[$t['receipt']] = true;
        }
    }
    foreach (glob(struk_dir() . '/*.{jpg,png,webp}', GLOB_BRACE) ?: [] as $f) {
        if (!isset($keep[basename($f)])) {
            @unlink($f);
        }
    }
}

/* -------------------------------------------------------------------- parser */

function struk_up(string $s): string
{
    return function_exists('mb_strtoupper') ? mb_strtoupper($s, 'UTF-8') : strtoupper($s);
}

function struk_lower(string $s): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

/** Baris teks OCR yang sudah dibersihkan. */
function struk_lines(string $text): array
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', ' ', $text) ?? $text;
    $out = [];
    foreach (explode("\n", $text) as $line) {
        $line = trim(preg_replace('/[ \t]+/u', ' ', $line) ?? $line);
        if ($line !== '') {
            $out[] = $line;
        }
    }
    return $out;
}

/**
 * Ubah potongan angka struk menjadi nilai uang.
 * Contoh: "45.000" -> 45000, "1.234.567,89" -> 1234567.89, "45,000" -> 45000.
 */
function struk_amount(string $raw): float
{
    $s = preg_replace('/[^0-9.,]/u', '', $raw) ?? '';
    if ($s === '') {
        return 0.0;
    }
    $hasDot = strpos($s, '.') !== false;
    $hasComma = strpos($s, ',') !== false;

    if ($hasDot && $hasComma) {
        // Pemisah desimal adalah tanda yang muncul paling akhir.
        $lastComma = strrpos($s, ',');
        $lastDot = strrpos($s, '.');
        if ($lastComma !== false && $lastDot !== false && $lastComma > $lastDot) {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } else {
            $s = str_replace(',', '', $s);
        }
    } elseif ($hasComma) {
        // 1,234,567 = ribuan. 45,00 = desimal.
        if (preg_match('/^\d{1,3}(,\d{3})+$/', $s)) {
            $s = str_replace(',', '', $s);
        } else {
            $s = str_replace(',', '.', $s);
        }
    } elseif ($hasDot) {
        // 1.234.567 = ribuan (gaya Indonesia). 12.5 = desimal.
        if (preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) {
            $s = str_replace('.', '', $s);
        }
    }
    $val = (float)$s;
    return is_finite($val) ? round($val, 2) : 0.0;
}

/**
 * Ambil nama toko dari baris-baris awal struk.
 * Baris yang tampak seperti alamat, nomor telepon, tanggal, atau nomor struk
 * dilewati supaya tidak salah pilih.
 */
function struk_detect_merchant(array $lines): string
{
    $skip = '/\b(JL|JALAN|TELP|TELP\.|PHONE|HP|WA|NPWP|KASIR|CASHIER|STRUK|INVOICE|NOTA|TANGGAL|TGL|JAM|BUKTI|TERIMA\s*KASIH|NPK|NO\.?\s*\d|SIMPAN|SALINAN)\b|@|www\.|http/i';
    $candidates = [];
    foreach (array_slice($lines, 0, 8) as $idx => $line) {
        if (preg_match($skip, $line)) {
            continue;
        }
        $letters = preg_match_all('/[A-Za-zÀ-ÿ]/u', $line);
        $digits = preg_match_all('/\d/', $line);
        if ($letters < 3) {
            continue;
        }
        // Nomor telepon / NPWP punya digit jauh lebih banyak daripada huruf.
        if ($digits > $letters + 6) {
            continue;
        }
        $len = function_exists('mb_strlen') ? mb_strlen($line, 'UTF-8') : strlen($line);
        if ($len < 3 || $len > 48) {
            continue;
        }
        // Baris pertama yang masuk akal biasanya nama toko.
        // Baris paling atas hampir selalu nama toko, jadi diberi bobot besar.
        $score = $letters * 2 - $digits - $idx;
        $candidates[] = ['line' => $line, 'score' => $score + ($idx === 0 ? 30 : 0)];
    }
    if (!$candidates) {
        return '';
    }
    usort($candidates, fn($a, $b) => $b['score'] <=> $a['score']);
    $name = trim($candidates[0]['line'], " \t-–—*#:.,/|");
    $name = preg_replace('/\s{2,}/', ' ', $name) ?? $name;
    // Nama toko yang seluruhnya kapital dibuat lebih enak dibaca.
    if ($name !== '' && !preg_match('/\d/', $name) && $name === struk_up($name) && $name !== struk_lower($name)) {
        $name = function_exists('mb_convert_case') ? mb_convert_case($name, MB_CASE_TITLE, 'UTF-8') : ucwords(strtolower($name));
    }
    return $name;
}

/** Cari tanggal struk. Mengembalikan ['date' => YYYY-MM-DD, 'source' => ...]. */
function struk_detect_date(array $lines, string $today): array
{
    $months = [
        'JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MEI' => 5, 'MAY' => 5,
        'JUN' => 6, 'JUL' => 7, 'AGU' => 8, 'AUG' => 8, 'SEP' => 9, 'OKT' => 10,
        'OCT' => 10, 'NOV' => 11, 'NOP' => 11, 'DES' => 12, 'DEC' => 12,
    ];
    $text = implode("\n", array_slice($lines, 0, 20));
    $up = struk_up($text);

    // 1) Format ISO: 2026-11-18
    if (preg_match('/\b(20\d{2})-(\d{2})-(\d{2})\b/', $text, $m)) {
        $iso = sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
        if (valid_date($iso)) {
            return ['date' => $iso, 'source' => 'struk'];
        }
    }
    // 2) 18/11/2026, 18-11-26, 18.11.2026
    if (preg_match('/\b(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2,4})\b/', $up, $m)) {
        $d = (int)$m[1];
        $mo = (int)$m[2];
        $y = (int)$m[3];
        if ($y < 100) {
            $y += 2000;
        }
        if ($mo > 12 && $d <= 12) {
            // Kemungkinan format bulan/tanggal (MM/DD/YYYY).
            [$d, $mo] = [$mo, $d];
        }
        if ($y >= 2000 && $y <= 2100 && checkdate($mo, $d, $y)) {
            return ['date' => sprintf('%04d-%02d-%02d', $y, $mo, $d), 'source' => 'struk'];
        }
    }
    // 3) 18 NOV 2026 / 18 NOVEMBER 26
    if (preg_match('/\b(\d{1,2})\s+([A-Z]{3,9})\s+(\d{2,4})\b/', $up, $m)) {
        $key = substr($m[2], 0, 3);
        if (isset($months[$key])) {
            $d = (int)$m[1];
            $mo = $months[$key];
            $y = (int)$m[3];
            if ($y < 100) {
                $y += 2000;
            }
            if ($y >= 2000 && $y <= 2100 && checkdate($mo, $d, $y)) {
                return ['date' => sprintf('%04d-%02d-%02d', $y, $mo, $d), 'source' => 'struk'];
            }
        }
    }
    return ['date' => $today, 'source' => 'default'];
}

/**
 * Kata kunci yang tidak boleh dianggap sebagai barang.
 * Dipakai untuk menyaring baris total, pembayaran, dan potongan.
 */
function struk_non_item_regex(): string
{
    return '/\b(TOTAL|SUBTOTAL|TUNAI|CASH|KEMBALI|KEMBALIAN|CHANGE|BAYAR|PEMBAYARAN|DISKON|DISCOUNT|POTONGAN|HEMAT|VOUCHER|PPN|PAJAK|TAX|VAT|SERVICE|SERVIS|ADMIN|BIAYA|DP|DEBIT|KREDIT|QRIS|CARD|KARTU|STRUK|NOTA|INVOICE|TERIMA\s*KASIH|PELANGGAN|KASIR|CASHIER|ROUNDING|PEMBULATAN)\b/u';
}

/** Baris kepala struk (alamat, telepon, kasir, nomor pajak) bukan barang. */
function struk_header_regex(): string
{
    return '/\b(JL\.?|JALAN|TELP|TELP\.|PHONE|HP|WA|NPWP|KASIR|CASHIER|TANGGAL|TGL|JAM|PUKUL|INVOICE|NOTA|BUKTI|STRUK|NO\.?\s*(STRUK|NOTA|INV)|SELAMAT|DATANG|MEMBER|MEMBERSHIP|POIN|SALDO|BANK|BCA|MANDIRI|BRI|BNI|CHIP|CONTACT|WEBSITE|SIMPAN|SALINAN|HOST|TERMINAL|VERSION|VERSI)\b|www\.|http|@/iu';
}

/** Baris yang layak dijadikan barang. */
function struk_parse_items(array $lines): array
{
    $items = [];
    $nonItem = struk_non_item_regex();
    $header = struk_header_regex();

    $count = count($lines);
    for ($i = 0; $i < $count; $i++) {
        $line = $lines[$i];
        $up = struk_up($line);
        if (preg_match($nonItem, $up) || preg_match($header, $up)) {
            continue;
        }
        // Baris tanggal/jam bukan nama barang.
        if (preg_match('/\b\d{1,2}\s+(JAN|FEB|MAR|APR|MEI|MAY|JUN|JUL|AGU|AUG|SEP|OKT|OCT|NOV|DES|DEC)/iu', $line)) {
            continue;
        }
        if (preg_match('#^\d{1,4}[/\-.]\d{1,2}([/\-.]\d{2,4})?(\s|$)#', trim($line))) {
            continue;
        }

        $rest = '';
        $trailing = 0.0;
        // Angka terakhir pada baris = harga/nilai baris.
        if (preg_match('/^(.*?)(\d[\d.,]*)\s*$/u', $line, $m)) {
            $rest = trim($m[1]);
            $trailing = struk_amount($m[2]);
        }

        $qty = 1;
        $unit = $trailing;
        $total = $trailing;
        $qtyDone = false;

        // Harga barang di struk nyaris tidak pernah di bawah Rp100.
        if ($trailing < 100 || $trailing > 100000000) {
            $nextLine = $i + 1 < $count ? trim($lines[$i + 1]) : '';
            $nextQty = [];
            if (preg_match('/^(\d{1,3})\s*[xX×*]\s*([\d.,]+)$/u', $nextLine, $nextQty)) {
                // Pola tiga baris: nama barang / 2 x 8.750 / 17.500
                $qty = max(1, (int)$nextQty[1]);
                $unit = struk_amount($nextQty[2]);
                $total = $qty * $unit;
                $i++; // baris "qty x harga" terpakai
                $after = $i + 1 < $count ? trim($lines[$i + 1]) : '';
                if ($after !== '' && preg_match('/^[\d.,]+$/', $after)) {
                    $afterAmount = struk_amount($after);
                    if ($afterAmount >= 100 && $afterAmount <= 100000000) {
                        $total = $afterAmount;
                        $i++; // baris total terpakai
                    }
                }
                if ($unit < 100) {
                    continue;
                }
                if ($qty > 0 && $total > 0) {
                    $unit = round($total / $qty, 2);
                }
                $rest = trim($line);
                $qtyDone = true;
            } elseif ($nextLine !== '' && preg_match('/^[\d.,]+$/', $nextLine)
                && preg_match_all('/[A-Za-zÀ-ÿ]/u', $line) >= 3) {
                // Pola dua baris: nama barang di satu baris, harganya di bawahnya.
                $nextAmount = struk_amount($nextLine);
                if ($nextAmount < 100 || $nextAmount > 100000000) {
                    continue;
                }
                $rest = trim($line);
                $trailing = $nextAmount;
                $unit = $nextAmount;
                $total = $nextAmount;
                $i++; // baris harga sudah dipakai
            } else {
                continue;
            }
        }

        // "2 x 5.000 10.000" atau "2 x 5.000"
        if (!$qtyDone && preg_match('/^(.*?)(\d{1,3})\s*[xX×*]\s*(\d[\d.,]*)\s*$/u', $rest, $q)) {
            $qty = max(1, (int)$q[2]);
            $unit = struk_amount($q[3]);
            $rest = trim($q[1]);
            if ($qty > 1 && $unit > 0) {
                $total = $trailing > $unit ? $trailing : $unit * $qty;
                if ($trailing <= $unit) {
                    // Hanya harga satuan yang terbaca.
                    $total = $unit * $qty;
                } else {
                    $unit = round($total / $qty, 2);
                }
            } else {
                $qty = 1;
                $unit = $trailing;
                $total = $trailing;
            }
        } elseif (preg_match('/^(.*?)\s+(\d{1,3})\s*[xX×*]$/u', $rest, $q)) {
            $qty = max(1, (int)$q[2]);
            $rest = trim($q[1]);
            $total = $trailing;
            $unit = $qty > 0 ? round($total / $qty, 2) : $total;
        }

        $name = trim($rest, " \t-–—*#:.,/|+");
        $name = preg_replace('/\s{2,}/', ' ', $name) ?? $name;
        $name = preg_replace('/\s+Rp\.?$/i', '', $name) ?? $name;
        $letters = preg_match_all('/[A-Za-zÀ-ÿ]/u', $name);
        if ($letters < 2) {
            continue;
        }
        $len = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
        if ($len < 2 || $len > 60) {
            continue;
        }
        if ($total <= 0) {
            continue;
        }
        $items[] = [
            'name'  => $name,
            'qty'   => $qty,
            'price' => round($unit, 2),
            'total' => round($total, 2),
        ];
        if (count($items) >= STRUK_MAX_ITEMS) {
            break;
        }
    }
    return $items;
}

/** Cari nilai total, subtotal, dan pajak. */
function struk_detect_total(array $lines, float $itemSum): array
{
    $rules = [
        ['re' => '/GRAND\s*TOTAL/', 'w' => 100],
        ['re' => '/TOTAL\s*(BAYAR|BELANJA|TAGIHAN|AKHIR|TRANSAKSI|HARGA)/', 'w' => 95],
        ['re' => '/JUMLAH\s*(BAYAR|TOTAL|TAGIHAN|HARGA)/', 'w' => 92],
        ['re' => '/\bTOTAL\b/', 'w' => 82],
        ['re' => '/\b(BAYAR|PEMBAYARAN)\b/', 'w' => 60],
    ];
    // "TOTAL 3 ITEM 65.000" tetap dipakai sebagai kandidat: angka jumlah barang
    // sudah tersaring oleh aturan nominal minimal Rp100 di bawah.
    $skip = '/SUBTOTAL|PPN|PAJAK|TAX|DISKON|KEMBALI|CHANGE|HEMAT|POTONGAN|SERVICE/';

    $candidates = [];
    $subtotal = 0.0;
    $tax = 0.0;

    foreach ($lines as $li => $line) {
        $up = struk_up($line);
        $amounts = [];
        if (preg_match_all('/\d[\d.,]*/', $line, $nums)) {
            foreach ($nums[0] as $n) {
                $v = struk_amount($n);
                // Angka kecil hampir selalu jumlah barang, bukan nominal rupiah.
                if ($v >= 100) {
                    $amounts[] = $v;
                }
            }
        }
        if (!$amounts) {
            // Sebagian struk mencetak nominal total di baris berikutnya.
            $next = isset($lines[$li + 1]) ? trim($lines[$li + 1]) : '';
            if ($next !== '' && preg_match('/^[\d.,]+$/', $next)) {
                $v = struk_amount($next);
                if ($v >= 100) {
                    $amounts[] = $v;
                }
            }
        }
        if (!$amounts) {
            continue;
        }
        $lineTotal = end($amounts);

        if (preg_match('/\b(PPN|PAJAK|TAX|VAT)\b/', $up) && $tax <= 0) {
            $tax = $lineTotal;
            continue;
        }
        if (preg_match('/SUBTOTAL/', $up) && $subtotal <= 0) {
            $subtotal = $lineTotal;
            continue;
        }
        if (preg_match($skip, $up)) {
            continue;
        }
        foreach ($rules as $rule) {
            if (preg_match($rule['re'], $up)) {
                // Bila satu baris memuat total dan uang tunai, ambil nilai
                // yang paling dekat dengan jumlah barang atau yang terbesar.
                $pick = $lineTotal;
                if (count($amounts) > 1) {
                    if ($itemSum > 0) {
                        $best = null;
                        foreach ($amounts as $a) {
                            if ($best === null || abs($a - $itemSum) < abs($best - $itemSum)) {
                                $best = $a;
                            }
                        }
                        $pick = $best ?? $lineTotal;
                    } else {
                        $pick = max($amounts);
                    }
                }
                $candidates[] = ['w' => $rule['w'], 'amount' => $pick];
                break;
            }
        }
    }

    $warnings = [];
    if (!$candidates) {
        // Tidak ada kata "total": pakai angka terbesar pada struk.
        $max = 0.0;
        foreach ($lines as $line) {
            $up = struk_up($line);
            if (preg_match($skip, $up) || preg_match(struk_header_regex(), $up)) {
                continue;
            }
            if (preg_match_all('/\d[\d.,]*/', $line, $nums)) {
                foreach ($nums[0] as $n) {
                    $max = max($max, struk_amount($n));
                }
            }
        }
        if ($max > 0) {
            $warnings[] = 'Kata "TOTAL" tidak terbaca; nominal diambil dari angka terbesar pada struk.';
            return ['total' => $max, 'subtotal' => $subtotal, 'tax' => $tax, 'warnings' => $warnings];
        }
        return ['total' => 0.0, 'subtotal' => $subtotal, 'tax' => $tax, 'warnings' => ['Total belanja tidak terbaca. Isi nominal secara manual.']];
    }

    // Prioritas: bobot kata kunci tertinggi; bila seri, nilai terbesar.
    usort($candidates, fn($a, $b) => ($b['w'] <=> $a['w']) ?: ($b['amount'] <=> $a['amount']));
    $total = (float)$candidates[0]['amount'];

    if ($itemSum > 0 && $total > 0 && abs($total - $itemSum) > max(1000, $itemSum * 0.2)) {
        $warnings[] = 'Jumlah barang (' . number_format($itemSum, 0, ',', '.') . ') berbeda dengan total pada struk. Periksa kembali.';
    }
    if ($subtotal > 0 && $total > 0 && $total < $subtotal) {
        $warnings[] = 'Total lebih kecil dari subtotal; kemungkinan angka tertukar.';
    }
    return ['total' => $total, 'subtotal' => $subtotal, 'tax' => $tax, 'warnings' => $warnings];
}

/**
 * Tebak kategori pengeluaran dari nama toko/isi struk.
 * Aturan ditulis sebagai data agar mudah ditambah tanpa mengubah logika.
 */
function struk_category_rules(): array
{
    return [
        ['names' => ['Makan & Minum', 'Makan', 'Minum'], 'words' => [
            'RESTO', 'RESTORAN', 'WARUNG', 'WARTEG', 'KAFE', 'CAFE', 'KOPI', 'COFFEE', 'BAKSO', 'SATE', 'AYAM', 'NASI', 'MARTABAK', 'PIZZA', 'BURGER', 'KFC', 'MCD', 'MCDONALD', 'HOKBEN', 'DIMSUM', 'MIE', 'BAKERY', 'ROTI', 'CAKWE', 'ES TEH', 'BOBA', 'FOOD', 'EATERY', 'KANTIN',
        ]],
        ['names' => ['Belanja Harian', 'Belanja', 'Belanja Bulanan'], 'words' => [
            'INDOMARET', 'ALFAMART', 'ALFAMIDI', 'SUPERINDO', 'HYPERMART', 'TRANSMART', 'CARREFOUR', 'LOTTE', 'GIANT', 'SUPERMARKET', 'MINIMARKET', 'TOKO', 'SWALAYAN', 'GROSIR', 'PASAR', 'TOKOPEDIA', 'SHOPEE', 'LAZADA', 'BLIBLI',
        ]],
        ['names' => ['Transportasi', 'Transport'], 'words' => [
            'SPBU', 'PERTAMINA', 'PERTALITE', 'PERTAMAX', 'SHELL', 'BP', 'BENSIN', 'BBM', 'PARKIR', 'TOL', 'GRAB', 'GOJEK', 'GOPAY', 'OJOL', 'TIKET', 'KERETA', 'BUS', 'ANGKOT',
        ]],
        ['names' => ['Kesehatan', 'Kesehatan & Obat'], 'words' => [
            'APOTEK', 'FARMA', 'KLINIK', 'RUMAH SAKIT', 'RS ', 'DOKTER', 'LAB', 'OPTIK', 'VITAMIN', 'OBAT',
        ]],
        ['names' => ['Tagihan & Utilitas', 'Tagihan'], 'words' => [
            'PLN', 'PDAM', 'TELKOM', 'INDIHOME', 'TOKEN LISTRIK', 'LISTRIK', 'PULSA', 'KUOTA', 'PAKET DATA', 'AIR MINUM', 'GAS LPG',
        ]],
        ['names' => ['Hiburan'], 'words' => [
            'CINEMA', 'XXI', 'CGV', 'BIOSKOP', 'NETFLIX', 'SPOTIFY', 'GAME', 'STEAM', 'KARAOKE',
        ]],
        ['names' => ['Rumah & Sewa', 'Rumah'], 'words' => [
            'SEWA', 'KOS', 'KONTRAKAN', 'MATERIAL', 'BANGUNAN', 'DEPO',
        ]],
        ['names' => ['Pendidikan'], 'words' => [
            'ATK', 'ALAT TULIS', 'BUKU', 'FOTOKOPI', 'PRINT', 'SEKOLAH', 'KURSUS', 'LES',
        ]],
    ];
}

/**
 * @param array $haystacks Teks yang diperiksa berurutan; nama toko lebih
 *                         dipercaya daripada isi struk.
 */
function struk_guess_category(array $categories, array $haystacks): array
{
    $expense = [];
    foreach ($categories as $c) {
        if (($c['type'] ?? 'expense') === 'expense') {
            $expense[] = $c;
        }
    }
    if (!$expense) {
        return ['categoryId' => '', 'categoryName' => ''];
    }
    foreach ($haystacks as $hay) {
        $up = struk_up($hay);
        if (trim($up) === '') {
            continue;
        }
        foreach (struk_category_rules() as $rule) {
            $hit = false;
            foreach ($rule['words'] as $w) {
                if ($w !== '' && strpos($up, struk_up($w)) !== false) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) {
                continue;
            }
            foreach ($rule['names'] as $want) {
                foreach ($expense as $c) {
                    if (struk_up((string)$c['name']) === struk_up($want)) {
                        return ['categoryId' => (string)$c['id'], 'categoryName' => (string)$c['name']];
                    }
                }
            }
            // Nama kategori tidak sama persis — cocokkan sebagian.
            foreach ($rule['names'] as $want) {
                foreach ($expense as $c) {
                    if (strpos(struk_up((string)$c['name']), struk_up($want)) !== false) {
                        return ['categoryId' => (string)$c['id'], 'categoryName' => (string)$c['name']];
                    }
                }
            }
        }
    }
    // Bawaan: kategori "Belanja Harian" bila ada.
    foreach ($expense as $c) {
        if (strpos(struk_up((string)$c['name']), 'BELANJA') !== false) {
            return ['categoryId' => (string)$c['id'], 'categoryName' => (string)$c['name']];
        }
    }
    return ['categoryId' => (string)$expense[0]['id'], 'categoryName' => (string)$expense[0]['name']];
}

/**
 * Fungsi utama: teks OCR -> data transaksi yang bisa diperiksa pengguna.
 */
function struk_parse_text(string $text, array $categories = [], ?string $today = null): array
{
    $today = $today && valid_date($today) ? $today : date('Y-m-d');
    $text = function_exists('mb_substr') ? mb_substr($text, 0, STRUK_MAX_TEXT, 'UTF-8') : substr($text, 0, STRUK_MAX_TEXT);
    $lines = struk_lines($text);
    $warnings = [];

    if (!$lines) {
        return [
            'merchant' => '', 'date' => $today, 'dateSource' => 'default',
            'total' => 0.0, 'subtotal' => 0.0, 'tax' => 0.0, 'items' => [],
            'categoryId' => '', 'categoryName' => '',
            'warnings' => ['Teks struk kosong. Pastikan foto jelas dan tidak buram.'],
            'raw' => '', 'lineCount' => 0,
        ];
    }

    $merchant = struk_detect_merchant($lines);
    $bodyText = implode("\n", array_slice($lines, 0, 30));
    $dateInfo = struk_detect_date($lines, $today);
    $items = struk_parse_items($lines);
    $itemSum = array_reduce($items, fn($s, $it) => $s + (float)$it['total'], 0.0);
    $amounts = struk_detect_total($lines, (float)$itemSum);
    $warnings = array_merge($warnings, $amounts['warnings']);

    $parsedDate = $dateInfo['date'];
    if ($parsedDate > $today) {
        $warnings[] = 'Tanggal pada struk melebihi hari ini; tanggal diubah ke hari ini.';
        $parsedDate = $today;
    }
    if ($dateInfo['source'] === 'default') {
        $warnings[] = 'Tanggal tidak terbaca; dipakai tanggal hari ini.';
    }
    if ($merchant === '') {
        $warnings[] = 'Nama toko tidak terbaca.';
    }

    $haystack = implode("\n", array_slice($lines, 0, 12));
    $guess = struk_guess_category($categories, [$merchant, $haystack]);

    return [
        'merchant'   => $merchant,
        'date'       => $parsedDate,
        'dateSource' => $dateInfo['source'],
        'total'      => (float)$amounts['total'],
        'subtotal'   => (float)$amounts['subtotal'],
        'tax'        => (float)$amounts['tax'],
        'items'      => $items,
        'itemSum'    => round((float)$itemSum, 2),
        'categoryId' => $guess['categoryId'],
        'categoryName' => $guess['categoryName'],
        'warnings'   => array_values(array_unique($warnings)),
        'raw'        => implode("\n", array_slice($lines, 0, 60)),
        'lineCount'  => count($lines),
    ];
}

/**
 * Apakah teks ini bukti pembayaran QRIS / notifikasi DANA Business?
 * Dipakai supaya hasil scan bisa langsung dibaca sebagai PEMASUKAN.
 */
function qris_detect(array $lines): bool
{
    $text = struk_lower(implode("\n", $lines));
    if ($text === '') {
        return false;
    }
    $score = 0;
    if (preg_match('/\bqris\b/u', $text)) {
        $score += 2;
    }
    if (preg_match('/\bdana\b/u', $text)) {
        $score += 2;
    }
    if (preg_match('/(kamu menerima|anda menerima|pembayaran diterima|dana diterima|dana masuk|pembayaran berhasil|transaksi berhasil|pembayaran masuk)/u', $text)) {
        $score += 3;
    }
    if (preg_match('/(pengirim|sumber dana|id transaksi|no\.?\s*referensi|nomor referensi|merchant)/u', $text)) {
        $score += 1;
    }
    if (preg_match('/\b(rp|idr)\b/u', $text)) {
        $score += 1;
    }
    // Bukti belanja di toko bukan pemasukan QRIS.
    if (!preg_match('/\bqris\b/u', $text)
        && preg_match('/(total belanja|kembalian|kasir|cashier|ppn|struk|item|qty)/u', $text)) {
        $score -= 4;
    }
    return $score >= 5;
}

/** Nominal uang pada notifikasi QRIS: utamakan baris "total/nominal/diterima". */
function qris_detect_amount(array $lines): array
{
    $prioritas = [];
    $lain = [];
    $abaikan = '/(saldo|sisa saldo|saldo akhir|biaya admin|biaya layanan|admin|ppn|pajak|fee|cashback|poin)/iu';
    foreach ($lines as $line) {
        if (preg_match($abaikan, $line) && !preg_match('/(diterima|menerima|masuk)/iu', $line)) {
            continue;
        }
        if (!preg_match('/(rp|idr)/iu', $line)) {
            continue;
        }
        if (!preg_match('/(\d[\d.,]*\d|\d)/', $line, $m)) {
            continue;
        }
        $nilai = struk_amount($m[1]);
        if ($nilai <= 0 || $nilai > 1_000_000_000_000) {
            continue;
        }
        if (preg_match('/(total|nominal|jumlah|diterima|menerima|masuk|bayar)/iu', $line)) {
            $prioritas[] = $nilai;
        } else {
            $lain[] = $nilai;
        }
    }
    if ($prioritas) {
        return ['amount' => max($prioritas), 'source' => 'nominal'];
    }
    if ($lain) {
        return ['amount' => max($lain), 'source' => 'perkiraan'];
    }
    return ['amount' => 0.0, 'source' => 'tidak-terbaca'];
}

/** Nama pengirim / keterangan pada notifikasi QRIS. */
function qris_detect_sender(array $lines): string
{
    foreach ($lines as $line) {
        if (preg_match('/^(?:dari|pengirim|nama pengirim|sumber dana|nama)\s*[:\-]\s*(.+)$/iu', $line, $m)) {
            $nama = trim(preg_replace('/\s{2,}/u', ' ', $m[1]));
            $nama = preg_replace('/\b(rp|idr)\b.*$/iu', '', $nama);
            $nama = trim($nama, " \t:-·|");
            if (struk_strlen($nama) >= 2 && !preg_match('/^\d+$/', $nama)) {
                return struk_clean_label($nama);
            }
        }
    }
    foreach ($lines as $line) {
        if (preg_match('/pembayaran dari\s+(.+)$/iu', $line, $m)) {
            $nama = trim($m[1], " \t:-·|");
            if (struk_strlen($nama) >= 2) {
                return struk_clean_label($nama);
            }
        }
    }
    return '';
}

/** Panjang teks aman walau mbstring tidak ada. */
function struk_strlen(string $s): int
{
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}

/** Bersihkan label nama agar aman disimpan. */
function struk_clean_label(string $nama): string
{
    $nama = preg_replace('/\s+/u', ' ', $nama);
    $nama = preg_replace('/[^\p{L}\p{N} .,\'&+\-()\/]/u', '', $nama);
    $nama = trim($nama, " .,-");
    return function_exists('mb_substr') ? mb_substr($nama, 0, 80, 'UTF-8') : substr($nama, 0, 80);
}

/** Akun sumber pemasukan QRIS: cari "Dana Business" lebih dulu. */
function qris_pick_account(array $accounts): string
{
    $kandidat = '';
    foreach ($accounts as $a) {
        if (!is_array($a) || !empty($a['archived'])) {
            continue;
        }
        $nama = struk_lower((string)($a['name'] ?? ''));
        if ($nama === '') {
            continue;
        }
        if (str_contains($nama, 'dana') && (str_contains($nama, 'business') || str_contains($nama, 'bisnis'))) {
            return (string)$a['id'];
        }
        if ($kandidat === '' && str_contains($nama, 'dana')) {
            $kandidat = (string)$a['id'];
        }
    }
    return $kandidat;
}

/** Kategori pemasukan QRIS: utamakan kategori bisnis/OSINT. */
function qris_pick_category(array $categories): array
{
    $pola = ['/osint/u', '/bisnis/u', '/usaha/u'];
    foreach ($pola as $re) {
        foreach ($categories as $c) {
            if (!is_array($c) || ($c['type'] ?? '') !== 'income') {
                continue;
            }
            if (preg_match($re, struk_lower((string)($c['name'] ?? '')))) {
                return ['id' => (string)$c['id'], 'name' => (string)$c['name']];
            }
        }
    }
    // Cadangan: kategori pemasukan pertama agar form tidak kosong.
    foreach ($categories as $c) {
        if (is_array($c) && ($c['type'] ?? '') === 'income') {
            return ['id' => (string)$c['id'], 'name' => (string)$c['name']];
        }
    }
    return ['id' => '', 'name' => ''];
}

/**
 * Baca notifikasi QRIS / DANA Business sebagai pemasukan.
 * Bentuk hasil sama dengan struk_parse_text, ditambah type/kind/accountId.
 */
function qris_parse_text(string $text, array $categories = [], array $accounts = [], ?string $today = null): array
{
    $today = $today && valid_date($today) ? $today : date('Y-m-d');
    $text = function_exists('mb_substr') ? mb_substr($text, 0, STRUK_MAX_TEXT, 'UTF-8') : substr($text, 0, STRUK_MAX_TEXT);
    $lines = struk_lines($text);
    $warnings = [];

    $amount = qris_detect_amount($lines);
    $sender = qris_detect_sender($lines);
    $dateInfo = struk_detect_date($lines, $today);
    $parsedDate = $dateInfo['date'] > $today ? $today : $dateInfo['date'];
    if ($dateInfo['source'] === 'default') {
        $warnings[] = 'Tanggal tidak terbaca; dipakai tanggal hari ini.';
    }
    if ((float)$amount['amount'] <= 0) {
        $warnings[] = 'Nominal pemasukan tidak terbaca; isi manual.';
    } elseif ($amount['source'] === 'perkiraan') {
        $warnings[] = 'Nominal diambil dari angka terbesar; periksa sebelum menyimpan.';
    }
    if ($sender === '') {
        $warnings[] = 'Nama pengirim tidak terbaca; keterangan bisa diisi manual.';
        $sender = 'QRIS';
    }
    $cat = qris_pick_category($categories);

    return [
        'kind'       => 'qris',
        'type'       => 'income',
        'merchant'   => $sender,
        'sender'     => $sender,
        'date'       => $parsedDate,
        'dateSource' => $dateInfo['source'],
        'total'      => (float)$amount['amount'],
        'subtotal'   => (float)$amount['amount'],
        'tax'        => 0.0,
        'items'      => [],
        'itemSum'    => 0.0,
        'categoryId' => $cat['id'],
        'categoryName' => $cat['name'],
        'accountId'  => qris_pick_account($accounts),
        'warnings'   => array_values(array_unique($warnings)),
        'raw'        => implode("\n", array_slice($lines, 0, 60)),
        'lineCount'  => count($lines),
    ];
}

/** Pilih pembaca yang tepat: notifikasi QRIS atau struk belanja. */
function struk_parse_any(string $text, array $categories = [], array $accounts = [], ?string $today = null): array
{
    $lines = struk_lines($text);
    if ($lines && qris_detect($lines)) {
        return qris_parse_text($text, $categories, $accounts, $today);
    }
    $hasil = struk_parse_text($text, $categories, $today);
    $hasil['kind'] = 'struk';
    $hasil['type'] = 'expense';
    $hasil['sender'] = '';
    $hasil['accountId'] = '';
    return $hasil;
}

/** Normalisasi rincian barang yang dikirim dari browser. */
function struk_clean_items($items): array
{
    if (!is_array($items)) {
        return [];
    }
    $out = [];
    foreach ($items as $it) {
        if (!is_array($it)) {
            continue;
        }
        $name = in_str($it, 'name', '', 80);
        if ($name === '') {
            continue;
        }
        $qty = (int)round((float)in_amount($it, 'qty'));
        if ($qty < 1) {
            $qty = 1;
        }
        if ($qty > 9999) {
            $qty = 9999;
        }
        $total = in_amount($it, 'total');
        $price = in_amount($it, 'price');
        if ($total <= 0 && $price > 0) {
            $total = $price * $qty;
        }
        if ($total > 1_000_000_000_000) {
            $total = 1_000_000_000_000;
        }
        $out[] = ['name' => $name, 'qty' => $qty, 'total' => round($total, 2)];
        if (count($out) >= STRUK_MAX_ITEMS) {
            break;
        }
    }
    return $out;
}
