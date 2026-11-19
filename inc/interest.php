<?php
declare(strict_types=1);

/**
 * Bunga tabungan otomatis (SeaBank).
 *
 * Modul ini sengaja dipisah dari api.php supaya bisa dipakai dua jalur:
 *   1. cron/bunga_seabank.php  -> dijalankan cron job (CLI atau HTTP bertoken)
 *   2. api.php?action=interest.* -> pratinjau/eksekusi dari aplikasi
 *
 * Semua tulisan ke database dilakukan lewat db_mutate() (satu lock + satu
 * penulisan file), sehingga penambahan transaksi dan perubahan saldo tidak
 * mungkin berjalan setengah. Bunga yang sudah tercatat untuk satu akun pada
 * satu tanggal dikenali lewat "ref" unik: bunga:<akun>:<tanggal>.
 */

if (!defined('APP_ROOT')) {
    http_response_code(403);
    exit('Forbidden');
}

/** Batas minimal bunga bersih yang layak dicatat (di bawah ini dilewati). */
const INTEREST_MIN_NET = 0.01;

/** Alias setelan bawaan (didefinisikan di inc/config.php sebagai data, bukan kode). */
function interest_defaults(): array
{
    return default_interest_settings();
}

/* ------------------------------------------------------------------ setelan */

/** Setelan bunga yang selalu lengkap, walau data lama belum punya kuncinya. */
function interest_settings(array $db): array
{
    $raw = $db['settings']['interest'] ?? [];
    if (!is_array($raw)) {
        $raw = [];
    }
    $out = interest_defaults();
    foreach (array_keys($out) as $k) {
        if (array_key_exists($k, $raw)) {
            $out[$k] = $raw[$k];
        }
    }

    $out['enabled']     = (bool)$out['enabled'];
    $out['mode']        = $out['mode'] === 'aktual' ? 'aktual' : 'simulasi';
    $out['accountId']   = is_string($out['accountId']) ? $out['accountId'] : '';
    $out['bankLabel']   = trim((string)$out['bankLabel']) !== '' ? trim((string)$out['bankLabel']) : 'SeaBank';
    $out['time']        = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)$out['time']) ? (string)$out['time'] : '01:30';
    $out['rateBelow']   = max(0.0, (float)$out['rateBelow']);
    $out['rateAtLeast'] = max(0.0, (float)$out['rateAtLeast']);
    $out['threshold']   = max(0.0, (float)$out['threshold']);
    $out['taxRate']     = min(100.0, max(0.0, (float)$out['taxRate']));
    $out['daysPerYear'] = max(1, (int)$out['daysPerYear']);
    $out['categoryName'] = trim((string)$out['categoryName']) !== '' ? trim((string)$out['categoryName']) : 'Bunga Tabungan';
    $out['note']        = trim((string)$out['note']) !== '' ? trim((string)$out['note']) : 'Bunga Harian SeaBank';
    $out['lastRunAt']   = (string)$out['lastRunAt'];
    $out['lastRunDate'] = (string)$out['lastRunDate'];
    $out['lastStatus']  = (string)$out['lastStatus'];
    $out['lastNet']     = $out['lastNet'] === null ? null : (float)$out['lastNet'];

    return $out;
}

/**
 * Validasi masukan dari aplikasi (api.php?action=interest.save).
 * Hanya kunci yang dikenal yang diterima; lastRun* tidak bisa diubah pengguna.
 */
function interest_sanitize(array $input, array $current): array
{
    $out = $current;

    foreach (['enabled', 'mode', 'accountId', 'bankLabel', 'time', 'categoryName', 'note'] as $k) {
        if (!array_key_exists($k, $input)) {
            continue;
        }
        $v = $input[$k];
        if ($k === 'enabled') {
            $out[$k] = (bool)$v;
        } elseif ($k === 'mode') {
            $out[$k] = $v === 'aktual' ? 'aktual' : 'simulasi';
        } else {
            $out[$k] = trim((string)$v);
        }
    }

    $num = [
        'rateBelow'   => [0, 100],
        'rateAtLeast' => [0, 100],
        'threshold'   => [0, 1e15],
        'taxRate'     => [0, 100],
        'daysPerYear' => [1, 366],
    ];
    foreach ($num as $k => [$min, $max]) {
        if (!array_key_exists($k, $input)) {
            continue;
        }
        $v = $input[$k];
        if (!is_numeric($v)) {
            throw new InvalidArgumentException('Nilai ' . $k . ' harus berupa angka.');
        }
        $out[$k] = min($max, max($min, (float)$v));
    }

    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)$out['time'])) {
        throw new InvalidArgumentException('Jam eksekusi harus berformat HH:MM (contoh 01:30).');
    }
    if ($out['bankLabel'] === '') {
        $out['bankLabel'] = 'SeaBank';
    }
    if ($out['categoryName'] === '') {
        $out['categoryName'] = 'Bunga Tabungan';
    }
    if ($out['note'] === '') {
        $out['note'] = 'Bunga Harian ' . $out['bankLabel'];
    }

    return interest_settings(['settings' => ['interest' => $out]]);
}

/* --------------------------------------------------------------- perhitungan */

/**
 * Cari akun yang dipakai. Bila accountId kosong/tidak ada, cari akun aktif
 * yang namanya mengandung "seabank" (tidak peka huruf besar/kecil).
 */
function interest_account(array $db, array $s): ?array
{
    $accounts = is_array($db['accounts'] ?? null) ? $db['accounts'] : [];

    if ($s['accountId'] !== '') {
        foreach ($accounts as $a) {
            if (($a['id'] ?? '') === $s['accountId']) {
                return $a;
            }
        }
    }

    $needle = strtolower($s['bankLabel']);
    foreach ($accounts as $a) {
        if (!empty($a['archived'])) {
            continue;
        }
        $name = strtolower((string)($a['name'] ?? ''));
        if ($name !== '' && str_contains($name, $needle)) {
            return $a;
        }
    }

    return null;
}

/** Saldo akun pada akhir hari sebelum $date (transaksi bertanggal < $date). */
function interest_balance_before(array $db, string $accountId, string $date): float
{
    $balance = 0.0;
    foreach ($db['accounts'] ?? [] as $a) {
        if (($a['id'] ?? '') === $accountId) {
            $balance = (float)($a['initial'] ?? 0);
            break;
        }
    }

    foreach ($db['transactions'] ?? [] as $t) {
        if (($t['accountId'] ?? '') !== $accountId) {
            continue;
        }
        if ((string)($t['date'] ?? '') >= $date) {
            continue;
        }
        $amount = (float)($t['amount'] ?? 0);
        $type   = (string)($t['type'] ?? '');
        if ($type === 'income') {
            $balance += $amount;
        } elseif ($type === 'expense') {
            $balance -= $amount;
        } elseif ($type === 'transfer') {
            $balance -= $amount;
        }
    }

    // Transfer masuk dari akun lain.
    foreach ($db['transactions'] ?? [] as $t) {
        if (($t['type'] ?? '') !== 'transfer') {
            continue;
        }
        if ((string)($t['toAccountId'] ?? '') !== $accountId) {
            continue;
        }
        if ((string)($t['date'] ?? '') >= $date) {
            continue;
        }
        $balance += (float)($t['amount'] ?? 0);
    }

    return round($balance, 2);
}

/**
 * Hitung bunga kotor, pajak, dan bunga bersih.
 * Suku bunga dipilih dari ambang saldo (>= ambang memakai rateAtLeast).
 */
function interest_calc(float $balance, array $s): array
{
    $useHigh = $s['threshold'] > 0 && $balance >= $s['threshold'];
    $rate = $useHigh ? (float)$s['rateAtLeast'] : (float)$s['rateBelow'];

    $gross = round($balance * $rate / 100 / max(1, (int)$s['daysPerYear']), 2);
    $tax   = round($gross * (float)$s['taxRate'] / 100, 2);
    $net   = round($gross - $tax, 2);

    return [
        'rate'     => $rate,
        'rateTier' => $useHigh ? 'atLeast' : 'below',
        'gross'    => $gross,
        'tax'      => $tax,
        'net'      => $net,
    ];
}

function interest_ref(string $accountId, string $date): string
{
    return 'bunga:' . $accountId . ':' . $date;
}

/** Transaksi bunga yang sudah ada untuk ref tertentu (null bila belum). */
function interest_existing(array $db, string $ref): ?array
{
    foreach ($db['transactions'] ?? [] as $t) {
        if (($t['ref'] ?? '') === $ref) {
            return $t;
        }
    }
    return null;
}

/**
 * Rencana pencatatan bunga untuk satu tanggal — tanpa mengubah apa pun.
 */
function interest_plan(array $db, string $date, ?array $settings = null): array
{
    $s = $settings ?? interest_settings($db);
    $acc = interest_account($db, $s);

    if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return ['ok' => false, 'status' => 'bad-date', 'message' => 'Tanggal tidak valid (format YYYY-MM-DD).'];
    }
    if ($acc === null) {
        return ['ok' => false, 'status' => 'no-account', 'date' => $date,
                'message' => 'Akun ' . $s['bankLabel'] . ' belum ada. Tambahkan akun dulu di Pengaturan.'];
    }

    $accountId = (string)$acc['id'];
    $balance   = interest_balance_before($db, $accountId, $date);
    $calc      = interest_calc($balance, $s);
    $ref       = interest_ref($accountId, $date);
    $existing  = interest_existing($db, $ref);

    $status = 'ok';
    $message = 'Siap dicatat.';
    if ($existing !== null) {
        $status = 'already';
        $message = 'Bunga tanggal ini sudah tercatat — tidak ditambahkan lagi.';
    } elseif ($balance <= 0) {
        $status = 'zero-balance';
        $message = 'Saldo akun nol/negatif pada awal hari, tidak ada bunga.';
    } elseif ($calc['net'] < INTEREST_MIN_NET) {
        $status = 'too-small';
        $message = 'Bunga bersih di bawah Rp0,01 — tidak dicatat.';
    }

    return [
        'ok'           => $status === 'ok',
        'status'       => $status,
        'message'      => $message,
        'date'         => $date,
        'accountId'    => $accountId,
        'accountName'  => (string)($acc['name'] ?? ''),
        'balance'      => $balance,
        'rate'         => $calc['rate'],
        'rateTier'     => $calc['rateTier'],
        'gross'        => $calc['gross'],
        'tax'          => $calc['tax'],
        'net'          => $calc['net'],
        'taxRate'      => (float)$s['taxRate'],
        'daysPerYear'  => (int)$s['daysPerYear'],
        'threshold'    => (float)$s['threshold'],
        'ref'          => $ref,
        'already'      => $existing !== null,
        'existingTxId' => $existing['id'] ?? null,
        'mode'         => $s['mode'],
        'enabled'      => $s['enabled'],
    ];
}

/** Kategori bunga: pakai yang sudah ada (nama sama, tipe income) atau buat baru. */
function interest_category_id(array &$db, array $s): string
{
    $want = mb_strtolower($s['categoryName']);
    foreach ($db['categories'] ?? [] as $c) {
        if (($c['type'] ?? '') === 'income' && mb_strtolower((string)($c['name'] ?? '')) === $want) {
            return (string)$c['id'];
        }
    }

    $id = 'cat_bunga_' . substr(bin2hex(random_bytes(4)), 0, 6);
    $db['categories'][] = [
        'id'        => $id,
        'name'      => $s['categoryName'],
        'type'      => 'income',
        'icon'      => '🏦',
        'color'     => '#f59e0b',
        'archived'  => false,
        'createdAt' => date('c'),
    ];
    return $id;
}

/**
 * Catat bunga ke database. HARUS dipanggil di dalam db_mutate().
 *
 * @param string $trigger cron|manual|api|cli (untuk log)
 * @return array hasil (status: created|simulated|already|disabled|zero-balance|
 *               too-small|no-account|bad-date|error)
 */
function interest_apply(array &$db, string $date, string $trigger = 'manual', bool $force = false): array
{
    $s = interest_settings($db);
    $mode = $s['mode'];

    $plan = interest_plan($db, $date, $s);

    if (!$s['enabled'] && !$force) {
        $result = ['status' => 'disabled', 'message' => 'Otomatisasi bunga sedang nonaktif.',
                   'date' => $date, 'mode' => $mode, 'net' => 0.0];
        interest_touch($db, $s, $result);
        return $result;
    }

    if ($plan['status'] !== 'ok') {
        $result = array_merge($plan, ['txId' => null, 'trigger' => $trigger]);
        interest_touch($db, $s, $result);
        return $result;
    }

    // Simulasi: angka dihitung, transaksi & saldo TIDAK diubah.
    if ($mode === 'simulasi') {
        $result = array_merge($plan, [
            'status'  => 'simulated',
            'message' => 'Simulasi: bunga tidak dicatat ke transaksi dan saldo tidak berubah.',
            'txId'    => null,
            'trigger' => $trigger,
        ]);
        interest_touch($db, $s, $result);
        return $result;
    }

    $categoryId = interest_category_id($db, $s);
    $txId = 'tx_' . bin2hex(random_bytes(8));
    $now = date('c');

    $db['transactions'][] = [
        'id'         => $txId,
        'type'       => 'income',
        'date'       => $date,
        'amount'     => $plan['net'],
        'accountId'  => $plan['accountId'],
        'categoryId' => $categoryId,
        'note'       => $s['note'],
        'merchant'   => '',
        'ref'        => $plan['ref'],
        'source'     => 'cron-bunga',
        'interest'   => [
            'bank'          => $s['bankLabel'],
            'balanceBefore' => $plan['balance'],
            'rate'          => $plan['rate'],
            'rateTier'      => $plan['rateTier'],
            'threshold'     => $plan['threshold'],
            'daysPerYear'   => $plan['daysPerYear'],
            'gross'         => $plan['gross'],
            'taxRate'       => $plan['taxRate'],
            'tax'           => $plan['tax'],
            'net'           => $plan['net'],
            'mode'          => 'aktual',
            'calculatedAt'  => $now,
        ],
        'createdAt'  => $now,
        'updatedAt'  => $now,
    ];

    $result = array_merge($plan, [
        'status'  => 'created',
        'message' => 'Bunga bersih ' . number_format($plan['net'], 2, ',', '.') . ' dicatat.',
        'txId'    => $txId,
        'trigger' => $trigger,
    ]);

    interest_touch($db, $s, $result);
    return $result;
}

/**
 * Jalankan pencatatan bunga secara aman: satu lock, satu penulisan, dan log
 * hanya ditulis setelah database benar-benar tersimpan.
 */
function interest_run(string $date, string $trigger = 'manual', bool $force = false): array
{
    $result = null;
    $ok = db_mutate(static function (array &$db) use ($date, $trigger, $force, &$result): void {
        $result = interest_apply($db, $date, $trigger, $force);
    });

    if (!is_array($result)) {
        $result = ['status' => 'error', 'message' => 'Tidak ada hasil dari proses bunga.', 'date' => $date];
    }
    if (!$ok && ($result['status'] ?? '') === 'created') {
        // Penulisan gagal: saldo & transaksi tidak berubah.
        $result['status'] = 'error';
        $result['message'] = 'Database gagal ditulis. Bunga tidak dicatat dan saldo tidak berubah.';
        $result['txId'] = null;
    }
    $result['persisted'] = (bool)$ok;
    interest_log($trigger, $result);
    return $result;
}

/**
 * Simulasi murni: hitung tanpa menulis database sama sekali.
 * Dipakai tombol "Simulasi" di Pengaturan dan opsi --simulate pada cron.
 */
function interest_simulate(string $date, string $trigger = 'simulasi'): array
{
    $db = db_read();
    $s = interest_settings($db);
    $plan = interest_plan($db, $date, $s);

    if ($plan['status'] === 'ok') {
        $plan['status'] = 'simulated';
        $plan['message'] = 'Simulasi: bunga tidak dicatat dan saldo tidak berubah.';
    }
    $plan['txId'] = null;
    $plan['trigger'] = $trigger;
    $plan['persisted'] = false;

    interest_log($trigger, $plan);
    return $plan;
}

/** Simpan jejak eksekusi terakhir ke setelan (tanpa menyentuh transaksi). */
function interest_touch(array &$db, array $s, array $result): void
{
    $db['settings']['interest'] = array_merge($s, [
        'lastRunAt'   => date('c'),
        'lastRunDate' => (string)($result['date'] ?? ''),
        'lastStatus'  => (string)($result['status'] ?? ''),
        'lastNet'     => isset($result['net']) ? (float)$result['net'] : null,
    ]);
}

/* ---------------------------------------------------------------------- log */

function interest_log_path(?string $date = null): string
{
    $date = $date ?: date('Y-m-d');
    return LOG_DIR . '/bunga-' . substr($date, 0, 7) . '.log';
}

/**
 * Tambahkan satu baris log eksekusi (JSON per baris).
 * Kegagalan menulis log tidak boleh menggagalkan pencatatan bunga.
 */
function interest_log(string $trigger, array $result): void
{
    if (!is_dir(LOG_DIR)) {
        @mkdir(LOG_DIR, 0775, true);
    }

    $entry = [
        'at'        => date('c'),
        'trigger'   => $trigger,
        'mode'      => $result['mode'] ?? '',
        'status'    => $result['status'] ?? '',
        'date'      => $result['date'] ?? '',
        'accountId' => $result['accountId'] ?? '',
        'balance'   => $result['balance'] ?? null,
        'rate'      => $result['rate'] ?? null,
        'gross'     => $result['gross'] ?? null,
        'tax'       => $result['tax'] ?? null,
        'net'       => $result['net'] ?? null,
        'txId'      => $result['txId'] ?? null,
        'ref'       => $result['ref'] ?? '',
        'message'   => $result['message'] ?? '',
    ];

    $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line === false) {
        return;
    }

    $path = interest_log_path($entry['date'] !== '' ? $entry['date'] : null);
    @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);

    if (is_file($path) && filesize($path) > 512 * 1024) {
        @rename($path, $path . '.1');
    }
}

/** N baris log terakhir (bulan ini + bulan lalu), terbaru di akhir. */
function interest_log_tail(int $limit = 20): array
{
    $months = [date('Y-m'), date('Y-m', strtotime('-1 month'))];
    $rows = [];
    foreach (array_reverse($months) as $ym) {
        $path = LOG_DIR . '/bunga-' . $ym . '.log';
        if (!is_file($path)) {
            continue;
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $j = json_decode($line, true);
            if (is_array($j)) {
                $rows[] = $j;
            }
        }
    }
    return array_slice($rows, -max(1, $limit));
}

function interest_log_text(int $limit = 20): string
{
    $out = [];
    foreach (interest_log_tail($limit) as $r) {
        $out[] = sprintf(
            '%s  %-7s %-10s %s  saldo=%s rate=%s%% kotor=%s pajak=%s bersih=%s',
            substr((string)($r['at'] ?? ''), 0, 19),
            (string)($r['mode'] ?? '-'),
            (string)($r['status'] ?? '-'),
            (string)($r['date'] ?? ''),
            number_format((float)($r['balance'] ?? 0), 0, ',', '.'),
            number_format((float)($r['rate'] ?? 0), 2, ',', '.'),
            number_format((float)($r['gross'] ?? 0), 2, ',', '.'),
            number_format((float)($r['tax'] ?? 0), 2, ',', '.'),
            number_format((float)($r['net'] ?? 0), 2, ',', '.')
        );
    }
    return implode("\n", $out);
}

/* -------------------------------------------------------------------- cron */

/** Token untuk memicu cron lewat HTTP (dibuat sekali, disimpan di data/). */
function interest_token(bool $create = false): string
{
    $token = '';
    $lama = DATA_DIR . '/cron-token.txt';

    // Berkas token disimpan sebagai .php dengan penjaga, supaya tetap tidak bisa
    // dibaca lewat URL pada server yang mengabaikan .htaccess.
    if (is_file(CRON_TOKEN_FILE)) {
        $isi = (string)@file_get_contents(CRON_TOKEN_FILE);
        $isi = preg_replace('/^<\?php.*?\?>/s', '', $isi);
        $token = trim((string)$isi);
    } elseif (is_file($lama)) {
        $token = trim((string)@file_get_contents($lama));
        if ($token !== '') {
            interest_token_write($token);
        }
        @unlink($lama);
    }

    if ($token === '' && $create) {
        $token = bin2hex(random_bytes(16));
        interest_token_write($token);
    }
    if ($token !== '' && !preg_match('/^[a-f0-9]{16,64}$/', $token)) {
        return '';
    }
    return $token;
}

/** Tulis token ke berkas .php berpenjaga (tidak bisa dibaca langsung dari browser). */
function interest_token_write(string $token): void
{
    if (!is_dir(DATA_DIR)) {
        @mkdir(DATA_DIR, 0775, true);
    }
    @file_put_contents(CRON_TOKEN_FILE, "<?php exit; ?>\n" . $token . "\n", LOCK_EX);
    @chmod(CRON_TOKEN_FILE, 0640);
}

/** Jadwal berikutnya menurut jam setelan (zona waktu aplikasi: Asia/Jakarta). */
function interest_next_run(array $s): string
{
    [$h, $m] = array_map('intval', explode(':', $s['time']));
    $now = new DateTimeImmutable('now');
    $next = $now->setTime($h, $m);
    if ($next <= $now) {
        $next = $next->modify('+1 day');
    }
    return $next->format('c');
}

/** Ringkasan untuk UI: setelan + akun + pratinjau hari ini + log terakhir. */
function interest_summary(array $db, string $date): array
{
    $s = interest_settings($db);
    $acc = interest_account($db, $s);
    $plan = interest_plan($db, $date, $s);

    $history = [];
    foreach (array_reverse($db['transactions'] ?? []) as $t) {
        if (($t['source'] ?? '') !== 'cron-bunga') {
            continue;
        }
        $history[] = [
            'id'     => $t['id'] ?? '',
            'date'   => $t['date'] ?? '',
            'amount' => (float)($t['amount'] ?? 0),
            'note'   => $t['note'] ?? '',
            'interest' => $t['interest'] ?? null,
        ];
        if (count($history) >= 8) {
            break;
        }
    }

    return [
        'settings'    => $s,
        'accounts'    => array_values(array_filter($db['accounts'] ?? [], static fn ($a) => empty($a['archived']))),
        'account'     => $acc ? ['id' => $acc['id'], 'name' => $acc['name'], 'icon' => $acc['icon'] ?? '🏦'] : null,
        'plan'        => $plan,
        'nextRun'     => interest_next_run($s),
        'serverTime'  => date('c'),
        'timezone'    => date_default_timezone_get(),
        'token'       => interest_token(true),
        'history'     => $history,
        'log'         => interest_log_tail(15),
        'logText'     => interest_log_text(15),
        'logDir'      => 'data/logs',
        'mode'        => $s['mode'],
        'enabled'     => $s['enabled'],
        'categories'  => array_values(array_filter($db['categories'] ?? [], static fn ($c) => ($c['type'] ?? '') === 'income')),
    ];
}
