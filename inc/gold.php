<?php
declare(strict_types=1);

/**
 * Harga & pembelian emas digital Lakuemas.
 *
 * Harga diambil dari API publik Lakuemas (dipakai halaman /harga):
 *   GET  https://www.lakuemas.com/harga      -> token CSRF + cookie sesi
 *   POST https://www.lakuemas.com/api/get_buy   (range, buy)  -> deret harga beli
 *   POST https://www.lakuemas.com/api/get_sell  (range, sell) -> deret harga jual
 * Nilai terakhir pada deret = harga per gram saat ini.
 *
 * Hasil disimpan di cache (data/cache/gold.json) selama beberapa menit supaya
 * halaman tidak menembak API Lakuemas setiap kali dibuka (batas 60 permintaan
 * per menit). Bila jaringan gagal, cache lama tetap dipakai dan ditandai usang.
 */

if (!defined('APP_ROOT')) {
    http_response_code(403);
    exit('Forbidden');
}

const GOLD_API_BASE   = 'https://www.lakuemas.com';
const GOLD_CACHE_TTL  = 300;   // detik
const GOLD_MIN_GRAMS  = 0.01;
const GOLD_MAX_GRAMS  = 1000.0;

function gold_cache_path(): string
{
    return CACHE_DIR . '/gold.json';
}

function gold_cache_read(): ?array
{
    $path = gold_cache_path();
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return null;
    }
    $j = json_decode($raw, true);
    return is_array($j) ? $j : null;
}

function gold_cache_write(array $data): void
{
    if (!is_dir(CACHE_DIR)) {
        @mkdir(CACHE_DIR, 0775, true);
    }
    @file_put_contents(
        gold_cache_path(),
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

/**
 * Ambil angka terakhir dari respons Lakuemas.
 * Contoh: {"data":{"buy_price":[2276000,2266000],"label":[...]}} -> 2266000.0
 */
function gold_parse_series(array $payload, string $key): ?float
{
    $rows = $payload['data'][$key] ?? null;
    if (!is_array($rows) || $rows === []) {
        return null;
    }
    for ($i = count($rows) - 1; $i >= 0; $i--) {
        $v = $rows[$i];
        if (is_numeric($v) && (float)$v > 0) {
            return (float)$v;
        }
    }
    return null;
}

/** Token CSRF dari halaman /harga. */
function gold_parse_csrf(string $html): ?string
{
    if (preg_match('/name="csrf-token"\s+content="([^"]+)"/i', $html, $m)) {
        return $m[1];
    }
    if (preg_match('/content="([^"]+)"\s+name="csrf-token"/i', $html, $m)) {
        return $m[1];
    }
    return null;
}

/**
 * Permintaan HTTP sederhana. $jar = berkas cookie (dibuat/dipakai curl).
 * @return array{code:int, body:string, error:?string}
 */
function gold_http(string $url, array $opts = []): array
{
    $method  = strtoupper((string)($opts['method'] ?? 'GET'));
    $body    = $opts['body'] ?? null;
    $headers = $opts['headers'] ?? [];
    $jar     = $opts['jar'] ?? null;
    $timeout = (int)($opts['timeout'] ?? 15);

    if (!function_exists('curl_init')) {
        return ['code' => 0, 'body' => '', 'error' => 'Ekstensi cURL tidak tersedia di server.'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => array_merge([
            'Accept: application/json, text/plain, */*',
            'Accept-Language: id-ID,id;q=0.9,en;q=0.8',
        ], $headers),
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? http_build_query($body) : (string)$body);
    }
    if ($jar) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
    }

    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = $resp === false ? (curl_error($ch) ?: 'Permintaan gagal.') : null;
    curl_close($ch);

    return ['code' => $code, 'body' => is_string($resp) ? $resp : '', 'error' => $err];
}

/**
 * Harga emas terkini (Rp per gram).
 *
 * @param callable|null $http Pengganti gold_http untuk pengujian.
 * @return array{ok:bool,buy:?float,sell:?float,fetchedAt:string,stale:bool,error:?string,source:string}
 */
function gold_fetch(bool $force = false, int $ttl = GOLD_CACHE_TTL, ?callable $http = null): array
{
    $http = $http ?? 'gold_http';
    $cache = gold_cache_read();
    $now = time();

    if (!$force && is_array($cache) && !empty($cache['fetchedTs']) && ($now - (int)$cache['fetchedTs']) < $ttl
        && !empty($cache['buy']) && !empty($cache['sell'])) {
        return [
            'ok'        => true,
            'buy'       => (float)$cache['buy'],
            'sell'      => (float)$cache['sell'],
            'fetchedAt' => (string)($cache['fetchedAt'] ?? date('c')),
            'stale'     => false,
            'error'     => null,
            'source'    => 'Lakuemas (cache)',
        ];
    }

    $jar = sys_get_temp_dir() . '/uang-laku-' . bin2hex(random_bytes(4)) . '.txt';
    $page = $http(GOLD_API_BASE . '/harga', ['jar' => $jar, 'timeout' => 15]);
    $csrf = $page['code'] === 200 ? gold_parse_csrf($page['body']) : null;

    if ($csrf === null) {
        @unlink($jar);
        return gold_failure($cache, $page['error'] ?? 'Halaman harga Lakuemas tidak bisa dibaca.');
    }

    $headers = [
        'X-CSRF-TOKEN: ' . $csrf,
        'X-Requested-With: XMLHttpRequest',
        'Referer: ' . GOLD_API_BASE . '/harga',
        'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
        'Origin: ' . GOLD_API_BASE,
    ];

    $buyRes = $http(GOLD_API_BASE . '/api/get_buy', [
        'method' => 'POST', 'body' => ['range' => '1 day', 'buy' => 'buy'],
        'headers' => $headers, 'jar' => $jar, 'timeout' => 15,
    ]);
    $sellRes = $http(GOLD_API_BASE . '/api/get_sell', [
        'method' => 'POST', 'body' => ['range' => '1 day', 'sell' => 'sell'],
        'headers' => $headers, 'jar' => $jar, 'timeout' => 15,
    ]);
    @unlink($jar);

    $buyJson  = json_decode($buyRes['body'], true);
    $sellJson = json_decode($sellRes['body'], true);
    $buy  = is_array($buyJson) ? gold_parse_series($buyJson, 'buy_price') : null;
    $sell = is_array($sellJson) ? gold_parse_series($sellJson, 'sell_price') : null;

    if ($buy === null || $sell === null) {
        return gold_failure($cache, 'Respons harga Lakuemas tidak dikenali.');
    }

    $data = [
        'buy'       => $buy,
        'sell'      => $sell,
        'fetchedAt' => date('c'),
        'fetchedTs' => $now,
        'source'    => 'Lakuemas',
    ];
    gold_cache_write($data);

    return [
        'ok' => true, 'buy' => $buy, 'sell' => $sell,
        'fetchedAt' => $data['fetchedAt'], 'stale' => false,
        'error' => null, 'source' => 'Lakuemas',
    ];
}

/** Pakai cache lama bila ada; kalau tidak ada sama sekali, laporkan gagal. */
function gold_failure(?array $cache, string $error): array
{
    if (is_array($cache) && !empty($cache['buy']) && !empty($cache['sell'])) {
        return [
            'ok'        => true,
            'buy'       => (float)$cache['buy'],
            'sell'      => (float)$cache['sell'],
            'fetchedAt' => (string)($cache['fetchedAt'] ?? ''),
            'stale'     => true,
            'error'     => $error,
            'source'    => 'Lakuemas (cache lama)',
        ];
    }
    return [
        'ok' => false, 'buy' => null, 'sell' => null,
        'fetchedAt' => '', 'stale' => true, 'error' => $error, 'source' => 'Lakuemas',
    ];
}

/* ------------------------------------------------------------- pembelian */

function gold_normalize_grams($value): float
{
    if (!is_numeric($value)) {
        throw new InvalidArgumentException('Berat emas harus berupa angka (gram).');
    }
    $grams = round((float)$value, 4);
    if ($grams < GOLD_MIN_GRAMS) {
        throw new InvalidArgumentException('Berat minimal ' . number_format(GOLD_MIN_GRAMS, 2, ',', '.') . ' gram.');
    }
    if ($grams > GOLD_MAX_GRAMS) {
        throw new InvalidArgumentException('Berat maksimal ' . number_format(GOLD_MAX_GRAMS, 0, ',', '.') . ' gram per catatan.');
    }
    return $grams;
}

/** Kategori pengeluaran untuk pembelian emas (dibuat bila belum ada). */
function gold_category_id(array &$db, string $name = 'Emas Digital'): string
{
    $want = mb_strtolower($name);
    foreach ($db['categories'] ?? [] as $c) {
        if (($c['type'] ?? '') === 'expense' && mb_strtolower((string)($c['name'] ?? '')) === $want) {
            return (string)$c['id'];
        }
    }
    $id = 'cat_emas_' . substr(bin2hex(random_bytes(4)), 0, 6);
    $db['categories'][] = [
        'id' => $id, 'name' => $name, 'type' => 'expense', 'icon' => '🪙',
        'color' => '#eab308', 'archived' => false, 'createdAt' => date('c'),
    ];
    return $id;
}

/**
 * Catat pembelian emas: satu transaksi pengeluaran + satu baris kepemilikan.
 * Harus dipanggil di dalam db_mutate().
 */
function gold_buy_apply(array &$db, array $in, ?array $price = null): array
{
    $grams = gold_normalize_grams($in['grams'] ?? null);

    $perGram = $in['pricePerGram'] ?? null;
    if (!is_numeric($perGram) || (float)$perGram <= 0) {
        $perGram = $price['buy'] ?? null;
    }
    if (!is_numeric($perGram) || (float)$perGram <= 0) {
        throw new InvalidArgumentException('Harga per gram belum tersedia. Muat harga terbaru atau isi manual.');
    }
    $perGram = round((float)$perGram, 2);

    $accountId = (string)($in['accountId'] ?? '');
    $account = null;
    foreach ($db['accounts'] ?? [] as $a) {
        if (($a['id'] ?? '') === $accountId) {
            $account = $a;
            break;
        }
    }
    if ($account === null) {
        throw new InvalidArgumentException('Pilih akun pembayaran yang valid.');
    }

    $date = (string)($in['date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new InvalidArgumentException('Tanggal pembelian tidak valid.');
    }

    $fee = is_numeric($in['fee'] ?? null) ? round(max(0.0, (float)$in['fee']), 2) : 0.0;
    $subtotal = round($grams * $perGram, 2);
    $total = round($subtotal + $fee, 2);

    $note = trim((string)($in['note'] ?? ''));
    if ($note === '') {
        $note = 'Beli emas digital ' . rtrim(rtrim(number_format($grams, 4, ',', '.'), '0'), ',') . ' gram @ ' . number_format($perGram, 0, ',', '.');
    }

    $categoryId = gold_category_id($db, (string)($in['categoryName'] ?? 'Emas Digital'));
    $now = date('c');
    $txId = 'tx_' . bin2hex(random_bytes(8));
    $goldId = 'gold_' . bin2hex(random_bytes(6));

    $db['transactions'][] = [
        'id' => $txId, 'type' => 'expense', 'date' => $date, 'amount' => $total,
        'accountId' => $accountId, 'categoryId' => $categoryId,
        'note' => $note . ' (Lakuemas)', 'merchant' => 'Lakuemas',
        'source' => 'beli-emas',
        'gold' => ['grams' => $grams, 'pricePerGram' => $perGram, 'subtotal' => $subtotal, 'fee' => $fee],
        'createdAt' => $now, 'updatedAt' => $now,
    ];

    $db['gold'][] = [
        'id' => $goldId, 'date' => $date, 'grams' => $grams,
        'pricePerGram' => $perGram, 'subtotal' => $subtotal, 'fee' => $fee, 'total' => $total,
        'accountId' => $accountId, 'txId' => $txId, 'note' => $note,
        'priceSource' => (string)($in['priceSource'] ?? 'Lakuemas'),
        'createdAt' => $now,
    ];

    return [
        'id' => $goldId, 'txId' => $txId, 'date' => $date, 'grams' => $grams,
        'pricePerGram' => $perGram, 'subtotal' => $subtotal, 'fee' => $fee, 'total' => $total,
        'accountId' => $accountId, 'accountName' => (string)($account['name'] ?? ''),
        'note' => $note,
    ];
}

/** Hapus catatan emas beserta transaksi pengeluarannya. */
function gold_delete_apply(array &$db, string $id): array
{
    $keep = [];
    $found = null;
    foreach ($db['gold'] ?? [] as $g) {
        if (($g['id'] ?? '') === $id) {
            $found = $g;
            continue;
        }
        $keep[] = $g;
    }
    if ($found === null) {
        throw new InvalidArgumentException('Catatan emas tidak ditemukan.');
    }
    $db['gold'] = $keep;

    $txId = (string)($found['txId'] ?? '');
    if ($txId !== '') {
        $db['transactions'] = array_values(array_filter(
            $db['transactions'] ?? [],
            static fn ($t) => ($t['id'] ?? '') !== $txId
        ));
    }

    return ['id' => $id, 'txId' => $txId, 'grams' => (float)($found['grams'] ?? 0)];
}

/** Ringkasan kepemilikan emas + nilai kini memakai harga jual. */
function gold_summary(array $db, ?array $price = null): array
{
    $sell = isset($price['sell']) && is_numeric($price['sell']) ? (float)$price['sell'] : 0.0;
    $buy  = isset($price['buy']) && is_numeric($price['buy']) ? (float)$price['buy'] : 0.0;

    $items = [];
    $grams = 0.0;
    $cost = 0.0;
    foreach ($db['gold'] ?? [] as $g) {
        $gr = (float)($g['grams'] ?? 0);
        $tot = (float)($g['total'] ?? 0);
        $grams += $gr;
        $cost += $tot;
        $value = $sell > 0 ? round($gr * $sell, 2) : null;
        $items[] = array_merge($g, [
            'valueNow' => $value,
            'diff'     => $value === null ? null : round($value - $tot, 2),
        ]);
    }

    usort($items, static fn ($a, $b) => strcmp((string)$b['date'], (string)$a['date']));

    $valueNow = $sell > 0 ? round($grams * $sell, 2) : null;

    return [
        'items'      => $items,
        'grams'      => round($grams, 4),
        'cost'       => round($cost, 2),
        'valueNow'   => $valueNow,
        'diff'       => $valueNow === null ? null : round($valueNow - $cost, 2),
        'diffPct'    => ($valueNow === null || $cost <= 0) ? null : round(($valueNow - $cost) / $cost * 100, 2),
        'sellPrice'  => $sell ?: null,
        'buyPrice'   => $buy ?: null,
        'count'      => count($items),
    ];
}
