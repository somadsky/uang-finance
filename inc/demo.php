<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Data contoh SINTETIS untuk mencoba aplikasi.
 * ⚠️ Data SINTETIS/MOCK - testing only. Tidak ada data orang nyata di sini.
 * Setiap catatan diberi penanda agar jelas bahwa ini bukan data asli pengguna.
 */
function api_demo_data(array $db): array
{
    $tag = '🧪 DATA MOCK/SINTETIS (TESTING)';
    $expensePlan = [
        ['cat_makan',      [22000, 35000, 45000, 60000, 28000]],
        ['cat_transport',  [12000, 20000, 35000, 50000]],
        ['cat_belanja',    [85000, 120000, 210000, 175000]],
        ['cat_tagihan',    [150000, 385000, 210000]],
        ['cat_kesehatan',  [65000, 120000]],
        ['cat_pendidikan', [95000, 250000]],
        ['cat_hiburan',    [45000, 80000, 150000]],
        ['cat_rumah',      [450000, 750000]],
        ['cat_donasi',     [50000, 100000, 150000]],
        ['cat_investasi',  [250000, 500000]],
    ];
    $incomePlan = [
        ['cat_gaji',      [8500000, 8750000]],
        ['cat_bonus',     [1200000]],
        ['cat_usaha',     [650000, 900000, 1450000]],
        ['cat_hasil_inv', [125000, 180000]],
    ];
    $noteMakan = ['Makan siang', 'Kopi & camilan', 'Belanja bulanan dapur', 'Makan malam keluarga'];
    $noteTransport = ['Bensin', 'Ojek online', 'Tiket transport umum', 'Servis ringan'];

    mt_srand(20261116); // deterministik: data contoh selalu sama

    $cats = fin_category_map($db);
    $accounts = array_values(array_filter($db['accounts'], fn($a) => empty($a['archived'])));
    if (count($accounts) === 0) {
        $accounts = $db['accounts'];
    }
    $accIds = array_map(fn($a) => $a['id'], $accounts);

    $db['transactions'] = [];
    $db['budgets'] = [];
    $db['goals'] = [];
    $db['goalEntries'] = [];
    $db['debts'] = [];

    $today = new DateTimeImmutable('now');
    $start = $today->modify('first day of -5 month');

    for ($mi = 0; $mi < 6; $mi++) {
        $monthStart = $start->modify("+$mi month");
        $ym = $monthStart->format('Y-m');
        $daysInMonth = (int)$monthStart->format('t');
        $maxDay = ($ym === $today->format('Y-m')) ? max(3, (int)$today->format('j')) : $daysInMonth;

        // Pemasukan: gaji di awal bulan + pemasukan tambahan.
        $db['transactions'][] = [
            'id' => gen_id('tx'),
            'date' => sprintf('%s-%02d', $ym, min(2, $maxDay)),
            'type' => 'income',
            'amount' => (float)(8500000 + ($mi * 50000)),
            'categoryId' => 'cat_gaji',
            'accountId' => 'acc_bca',
            'toAccountId' => null,
            'note' => "$tag — gaji bulanan (contoh)",
            'createdAt' => date('c'),
            'updatedAt' => date('c'),
        ];
        foreach ($incomePlan as [$catId, $amounts]) {
            if ($catId === 'cat_gaji' || !isset($cats[$catId])) {
                continue;
            }
            $n = mt_rand(1, 2);
            for ($k = 0; $k < $n; $k++) {
                $amt = (float)$amounts[array_rand($amounts)];
                $db['transactions'][] = [
                    'id' => gen_id('tx'),
                    'date' => sprintf('%s-%02d', $ym, mt_rand(1, $maxDay)),
                    'type' => 'income',
                    'amount' => $amt,
                    'categoryId' => $catId,
                    'accountId' => 'acc_bca',
                    'toAccountId' => null,
                    'note' => "$tag — pemasukan tambahan (contoh)",
                    'createdAt' => date('c'),
                    'updatedAt' => date('c'),
                ];
            }
        }

        // Pengeluaran.
        foreach ($expensePlan as [$catId, $amounts]) {
            if (!isset($cats[$catId])) {
                continue;
            }
            $n = mt_rand(2, 5);
            for ($k = 0; $k < $n; $k++) {
                $amt = (float)$amounts[array_rand($amounts)];
                $acc = in_array($catId, ['cat_tagihan', 'cat_rumah', 'cat_investasi'], true) ? 'acc_bca' : $accIds[array_rand($accIds)];
                if ($catId === 'cat_makan') {
                    $note = $noteMakan[array_rand($noteMakan)];
                } elseif ($catId === 'cat_transport') {
                    $note = $noteTransport[array_rand($noteTransport)];
                } else {
                    $note = ($cats[$catId]['name'] ?? 'Pengeluaran') . ' (contoh)';
                }
                $db['transactions'][] = [
                    'id' => gen_id('tx'),
                    'date' => sprintf('%s-%02d', $ym, mt_rand(1, $maxDay)),
                    'type' => 'expense',
                    'amount' => $amt,
                    'categoryId' => $catId,
                    'accountId' => $acc,
                    'toAccountId' => null,
                    'note' => "$tag — $note",
                    'createdAt' => date('c'),
                    'updatedAt' => date('c'),
                ];
            }
        }

        // Beberapa transfer antar akun.
        if (count($accIds) > 1) {
            $db['transactions'][] = [
                'id' => gen_id('tx'),
                'date' => sprintf('%s-05', $ym),
                'type' => 'transfer',
                'amount' => 500000.0,
                'categoryId' => null,
                'accountId' => 'acc_bca',
                'toAccountId' => 'acc_gopay',
                'note' => "$tag — isi saldo e-wallet (contoh)",
                'createdAt' => date('c'),
                'updatedAt' => date('c'),
            ];
        }

        // Anggaran untuk bulan berjalan.
        if ($ym === $today->format('Y-m')) {
            $plan = [
                'cat_makan' => 2500000, 'cat_transport' => 800000, 'cat_belanja' => 1500000,
                'cat_tagihan' => 900000, 'cat_hiburan' => 500000, 'cat_rumah' => 1500000,
                'cat_investasi' => 1000000, 'cat_donasi' => 300000,
            ];
            foreach ($plan as $catId => $amount) {
                if (!isset($cats[$catId])) continue;
                $db['budgets'][] = [
                    'id' => gen_id('bg'),
                    'month' => $ym,
                    'categoryId' => $catId,
                    'amount' => (float)$amount,
                    'createdAt' => date('c'),
                    'updatedAt' => date('c'),
                ];
            }
        }
    }

    // Saldo awal akun contoh.
    foreach ($db['accounts'] as $i => $a) {
        $saldo = [
            'acc_bca' => 4500000.0,
            'acc_gopay' => 250000.0,
            'acc_dana' => 350000.0,
            'acc_seabank' => 1200000.0,
        ];
        if (isset($saldo[$a['id'] ?? ''])) $db['accounts'][$i]['initial'] = $saldo[$a['id']];
    }

    // Target tabungan contoh.
    $goals = [
        ['gl_demo1', '🎯', 'Dana Darurat', 15000000.0, $today->modify('+8 month'), '#22c55e', 6750000.0],
        ['gl_demo2', '💻', 'Laptop Baru', 12000000.0, $today->modify('+5 month'), '#38bdf8', 3200000.0],
        ['gl_demo3', '🕋', 'Umroh', 35000000.0, $today->modify('+24 month'), '#a855f7', 5000000.0],
    ];
    foreach ($goals as [$id, $icon, $name, $target, $deadline, $color, $saved]) {
        $db['goals'][] = [
            'id' => $id,
            'name' => "$name (contoh)",
            'target' => $target,
            'deadline' => $deadline->format('Y-m-d'),
            'color' => $color,
            'icon' => $icon,
            'note' => $tag,
            'done' => false,
            'createdAt' => date('c'),
        ];
        $db['goalEntries'][] = [
            'id' => gen_id('ge'),
            'goalId' => $id,
            'dir' => 'in',
            'amount' => $saved,
            'date' => $today->modify('-1 month')->format('Y-m-d'),
            'note' => "$tag — setoran awal (contoh)",
            'createdAt' => date('c'),
        ];
    }

    // Utang & piutang contoh (nama fiktif).
    $db['debts'][] = [
        'id' => gen_id('dt'),
        'type' => 'debt',
        'person' => 'Pihak Fiktif A (contoh)',
        'amount' => 2000000.0,
        'date' => $today->modify('-20 day')->format('Y-m-d'),
        'dueDate' => $today->modify('+40 day')->format('Y-m-d'),
        'note' => "$tag — pinjaman perbaikan motor",
        'payments' => [[
            'id' => gen_id('dp'),
            'amount' => 500000.0,
            'date' => $today->modify('-5 day')->format('Y-m-d'),
            'note' => 'Angsuran pertama (contoh)',
        ]],
        'settled' => false,
        'createdAt' => date('c'),
    ];
    $db['debts'][] = [
        'id' => gen_id('dt'),
        'type' => 'receivable',
        'person' => 'Pihak Fiktif B (contoh)',
        'amount' => 750000.0,
        'date' => $today->modify('-12 day')->format('Y-m-d'),
        'dueDate' => $today->modify('+18 day')->format('Y-m-d'),
        'note' => "$tag — pinjam dulu buat kuota",
        'payments' => [],
        'settled' => false,
        'createdAt' => date('c'),
    ];

    usort($db['transactions'], fn($a, $b) => strcmp($b['date'], $a['date']));
    $db['demo'] = true;
    return $db;
}
