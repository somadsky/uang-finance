<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Perhitungan keuangan. Dipakai untuk ekspor & ringkasan sisi server.
 * Tampilan utama menghitung di browser dari snapshot yang sama, jadi rumus
 * di assets/app.js harus tetap sepadan dengan fungsi-fungsi di sini.
 */

/** Peta akun berdasarkan id. */
function fin_account_map(array $db): array
{
    $map = [];
    foreach ($db['accounts'] as $a) {
        $map[$a['id']] = $a;
    }
    return $map;
}

function fin_category_map(array $db): array
{
    $map = [];
    foreach ($db['categories'] as $c) {
        $map[$c['id']] = $c;
    }
    return $map;
}

/** Saldo tiap akun: saldo awal + pemasukan - pengeluaran + transfer masuk - transfer keluar. */
function fin_account_balances(array $db): array
{
    $bal = [];
    foreach ($db['accounts'] as $a) {
        $bal[$a['id']] = (float)($a['initial'] ?? 0);
    }
    foreach ($db['transactions'] as $t) {
        $amount = (float)($t['amount'] ?? 0);
        $acc = $t['accountId'] ?? null;
        $type = $t['type'] ?? 'expense';
        if ($type === 'income' && $acc !== null && isset($bal[$acc])) {
            $bal[$acc] += $amount;
        } elseif ($type === 'expense' && $acc !== null && isset($bal[$acc])) {
            $bal[$acc] -= $amount;
        } elseif ($type === 'transfer') {
            if ($acc !== null && isset($bal[$acc])) $bal[$acc] -= $amount;
            $to = $t['toAccountId'] ?? null;
            if ($to !== null && isset($bal[$to])) $bal[$to] += $amount;
        }
    }
    return $bal;
}

function fin_total_balance(array $db): float
{
    $sum = 0.0;
    foreach (fin_account_balances($db) as $b) {
        $sum += $b;
    }
    return $sum;
}

/** Ringkasan satu bulan (YYYY-MM). */
function fin_month_summary(array $db, string $month): array
{
    $income = 0.0;
    $expense = 0.0;
    $count = 0;
    foreach ($db['transactions'] as $t) {
        if (substr((string)($t['date'] ?? ''), 0, 7) !== $month) {
            continue;
        }
        $amount = (float)($t['amount'] ?? 0);
        if (($t['type'] ?? '') === 'income') {
            $income += $amount;
            $count++;
        } elseif (($t['type'] ?? '') === 'expense') {
            $expense += $amount;
            $count++;
        }
    }
    return [
        'month' => $month,
        'income' => $income,
        'expense' => $expense,
        'net' => $income - $expense,
        'count' => $count,
    ];
}

/** Ringkasan beberapa bulan terakhir (termasuk bulan berjalan). */
function fin_month_series(array $db, int $months = 6, ?string $endMonth = null): array
{
    $end = $endMonth ?: date('Y-m');
    $ts = strtotime($end . '-01');
    $out = [];
    for ($i = $months - 1; $i >= 0; $i--) {
        $m = date('Y-m', strtotime("-$i month", $ts));
        $out[] = fin_month_summary($db, $m);
    }
    return $out;
}

/** Total pengeluaran per kategori dalam satu bulan. */
function fin_category_totals(array $db, string $month): array
{
    $cats = fin_category_map($db);
    $tot = [];
    foreach ($db['transactions'] as $t) {
        if (($t['type'] ?? '') !== 'expense') {
            continue;
        }
        if (substr((string)($t['date'] ?? ''), 0, 7) !== $month) {
            continue;
        }
        $cid = $t['categoryId'] ?? 'cat_lain_exp';
        if (!isset($tot[$cid])) {
            $tot[$cid] = [
                'categoryId' => $cid,
                'name' => $cats[$cid]['name'] ?? 'Tanpa kategori',
                'color' => $cats[$cid]['color'] ?? '#94a3b8',
                'icon' => $cats[$cid]['icon'] ?? '•',
                'total' => 0.0,
            ];
        }
        $tot[$cid]['total'] += (float)($t['amount'] ?? 0);
    }
    usort($tot, fn($a, $b) => $b['total'] <=> $a['total']);
    return array_values($tot);
}
