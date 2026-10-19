<?php
declare(strict_types=1);

require_once __DIR__ . '/accounting_ledger.php';

/**
 * 固定資産台帳と減価償却。
 *   定額法（平成19年4月1日以後取得）／定率法（200%・平成24年4月1日以後取得）／一括償却資産（3年）／少額減価償却資産／償却額を指定／非償却資産。
 * 償却額は事業年度ごとに計算し、年末（売却・除却した年は売却日）に「減価償却費／資産（直接法）または減価償却累計額（間接法）」の仕訳を自動で作る。
 * 金額は円未満切り捨て。備忘価額は1円。
 */

const ACC_ACCT_DEP_EXPENSE = '減価償却費';
const ACC_ACCT_DEP_ACCUM = '減価償却累計額';
const ACC_ACCT_GAIN = '固定資産売却益';
const ACC_ACCT_LOSS = '固定資産売却損';
const ACC_ACCT_SCRAP = '固定資産除却損';
const ACC_ACCT_OWNER_DRAW = '事業主貸';

const ACC_ASSET_METHODS = [
    'sl' => '定額法',
    'db' => '定率法（200%）',
    'lump' => '一括償却資産（3年）',
    'small' => '少額減価償却資産（全額・資産計上）',
    'small_expensed' => '少額減価償却資産（取得時に経費計上済み・台帳のみ）',
    'manual' => '償却額を指定',
    'none' => '非償却（土地など）',
];

/** 国税庁「平成24年4月1日以後に取得をされた減価償却資産の定率法の償却率、改定償却率及び保証率の表」（耐用年数省令別表第十）。[償却率‰, 改定償却率‰|null, 保証率×10^5|null] */
const ACC_DB200 = [
    2 => [1000, null, null], 3 => [667, 1000, 11089], 4 => [500, 1000, 12499], 5 => [400, 500, 10800], 6 => [333, 334, 9911], 7 => [286, 334, 8680],
    8 => [250, 334, 7909], 9 => [222, 250, 7126], 10 => [200, 250, 6552], 11 => [182, 200, 5992], 12 => [167, 200, 5566], 13 => [154, 167, 5180],
    14 => [143, 167, 4854], 15 => [133, 143, 4565], 16 => [125, 143, 4294], 17 => [118, 125, 4038], 18 => [111, 112, 3884], 19 => [105, 112, 3693],
    20 => [100, 112, 3486], 21 => [95, 100, 3335], 22 => [91, 100, 3182], 23 => [87, 91, 3052], 24 => [83, 84, 2969], 25 => [80, 84, 2841],
    26 => [77, 84, 2716], 27 => [74, 77, 2624], 28 => [71, 72, 2568], 29 => [69, 72, 2463], 30 => [67, 72, 2366], 31 => [65, 67, 2286],
    32 => [63, 67, 2216], 33 => [61, 63, 2161], 34 => [59, 63, 2097], 35 => [57, 59, 2051], 36 => [56, 59, 1974], 37 => [54, 56, 1950],
    38 => [53, 56, 1882], 39 => [51, 53, 1860], 40 => [50, 53, 1791], 41 => [49, 50, 1741], 42 => [48, 50, 1694], 43 => [47, 48, 1664],
    44 => [45, 46, 1664], 45 => [44, 46, 1634], 46 => [43, 44, 1601], 47 => [43, 44, 1532], 48 => [42, 44, 1499], 49 => [41, 42, 1475], 50 => [40, 42, 1440],
];

/** 少額減価償却資産の特例の上限額（取得日による。令和8年4月1日以後の取得は40万円未満、それ以前は30万円未満。年間合計300万円まで） */
function acc_small_asset_limit(string $acquiredDate): int
{
    return $acquiredDate >= '2026-04-01' ? 400000 : 300000;
}

/** 日付が属する事業年度（開始年） */
function acc_fy_of(array $book, string $date): int
{
    $start = max(1, min(12, (int) $book['fiscal_start_month']));
    $y = (int) substr($date, 0, 4);
    return (int) substr($date, 5, 2) >= $start ? $y : $y - 1;
}

/** 事業年度の開始月から数えた月の番号（0〜11） */
function acc_fy_month_index(array $book, string $date): int
{
    $start = max(1, min(12, (int) $book['fiscal_start_month']));
    return ((int) substr($date, 5, 2) - $start + 12) % 12;
}

/** 定額法の償却率（‰。小数第4位を切り上げた償却率） */
function acc_sl_rate_milli(int $life): int
{
    return (int) ceil(1000 / max(1, $life));
}

/**
 * 1つの固定資産の償却スケジュール（事業年度ごと）。
 * 各行: fy・months(償却月数)・open_book(期首帳簿価額)・dep(償却額=帳簿価額の減少額)・accum(期末の償却累計額)・book(期末帳簿価額)・expense(必要経費算入額)
 *
 * @return array<int, array{fy:int, months:int, open_book:int, dep:int, accum:int, book:int, expense:int}>
 */
function acc_dep_schedule(array $asset, array $book, int $lastFy, ?int $openingFy = null): array
{
    $cost = (int) $asset['cost'];
    $method = (string) $asset['method'];
    $life = (int) ($asset['life'] ?? 0);
    $pct = (float) ($asset['business_pct'] ?? 100);
    $acqFy = acc_fy_of($book, (string) $asset['service_date']);
    $disposedFy = ($asset['status'] ?? 'active') === 'disposed' && !empty($asset['disposed_date']) ? acc_fy_of($book, (string) $asset['disposed_date']) : null;
    $endFy = $disposedFy !== null ? min($disposedFy, $lastFy) : $lastFy;
    $startFy = $acqFy;
    $accum = 0;
    if ($method === 'manual') {
        $accum = (int) ($asset['prior_accum'] ?? 0);
        $startFy = max($acqFy, $openingFy ?? $acqFy);
    }
    $rows = [];
    $revBase = null;
    for ($fy = $startFy; $fy <= $endFy; $fy++) {
        $openBook = $cost - $accum;
        $startIdx = $fy === $acqFy ? acc_fy_month_index($book, (string) $asset['service_date']) : 0;
        $endIdx = ($disposedFy === $fy) ? acc_fy_month_index($book, (string) $asset['disposed_date']) : 11;
        $months = max(0, $endIdx - $startIdx + 1);
        $dep = 0;
        $k = $fy - $acqFy;
        switch ($method) {
            case 'sl':
                if ($life >= 2) {
                    $annual = intdiv($cost * acc_sl_rate_milli($life), 1000);
                    $dep = intdiv($annual * $months, 12);
                }
                break;
            case 'db':
                if (isset(ACC_DB200[$life])) {
                    [$rate, $rev, $guar] = ACC_DB200[$life];
                    if ($revBase !== null && $rev !== null) {
                        $dep = intdiv(intdiv($revBase * $rev, 1000) * $months, 12);
                    } else {
                        $pre = intdiv($openBook * $rate, 1000);
                        if ($guar === null || $pre >= intdiv($cost * $guar, 100000)) {
                            $dep = intdiv($pre * $months, 12);
                        } else {
                            $revBase = $openBook;
                            $dep = intdiv(intdiv($revBase * (int) $rev, 1000) * $months, 12);
                        }
                    }
                }
                break;
            case 'lump':
                $third = intdiv($cost, 3);
                $dep = $k === 0 || $k === 1 ? $third : ($k === 2 ? $cost - 2 * $third : 0);
                $months = 12;
                break;
            case 'small':
            case 'small_expensed':
                $dep = $k === 0 ? $cost : 0;
                break;
            case 'manual':
                $dep = intdiv((int) ($asset['manual_annual'] ?? 0) * $months, 12);
                break;
            default:
                $dep = 0;
        }
        $floor = in_array($method, ['lump', 'small', 'small_expensed'], true) ? 0 : 1; // 備忘価額1円
        $dep = max(0, min($dep, $openBook - $floor));
        $accum += $dep;
        $rows[$fy] = [
            'fy' => $fy, 'months' => $months, 'open_book' => $openBook, 'dep' => $dep, 'accum' => $accum, 'book' => $cost - $accum,
            'expense' => $method === 'small_expensed' ? 0 : (int) floor($dep * $pct / 100),
        ];
    }
    return $rows;
}

/** 売却・除却の損益と、その時点の帳簿価額 */
function acc_asset_disposal(array $asset, array $rowAtDisposal): array
{
    $bookValue = $rowAtDisposal['book'];
    $price = (int) ($asset['disposal_price'] ?? 0);
    return ['book' => $bookValue, 'price' => $price, 'gain' => max(0, $price - $bookValue), 'loss' => max(0, $bookValue - $price), 'accum' => $rowAtDisposal['accum']];
}

function acc_asset_settings(PDO $pdo, int $bookId): array
{
    $s = acc_book_settings($pdo, $bookId);
    return [
        'opening_fy' => $s['opening_fy'],
        'dep_entry' => ($s['profile']['dep_entry'] ?? 'direct') === 'indirect' ? 'indirect' : 'direct',
        'dep_offset' => !empty($s['profile']['dep_offset']),
        'profile' => $s['profile'],
    ];
}

/**
 * 固定資産台帳から減価償却・売却除却の仕訳を作る（acc_generate_all から呼ぶ）。
 * $others は他の仕訳（概算の定型仕訳など）。「決算で実額に調整する」設定のときの調整額の計算に使う。
 *
 * @return list<array> 仕訳
 */
function acc_asset_entries(PDO $pdo, array $book, string $toMonth, array $others): array
{
    $bookId = (int) $book['id'];
    try {
        $stmt = $pdo->prepare('SELECT * FROM acc_assets WHERE book_id = :b ORDER BY id');
        $stmt->execute([':b' => $bookId]);
        $assets = $stmt->fetchAll();
        $settings = acc_asset_settings($pdo, $bookId);
    } catch (PDOException $e) {
        return [];
    }
    $openingFy = $settings['opening_fy'];
    if ($openingFy === null) {
        return [];
    }
    $lastDay = acc_month_last_day($toMonth);
    $lastFy = acc_fy_of($book, $lastDay);
    $direct = $settings['dep_entry'] === 'direct';
    $entries = [];
    foreach ($assets as $asset) {
        if (in_array($asset['method'], ['small_expensed'], true)) {
            continue;
        }
        $schedule = acc_dep_schedule($asset, $book, $lastFy, $openingFy);
        $disposedFy = $asset['status'] === 'disposed' && $asset['disposed_date'] ? acc_fy_of($book, (string) $asset['disposed_date']) : null;
        $sub = ($asset['ledger_sub'] ?? '') !== '' ? (string) $asset['ledger_sub'] : null;
        foreach ($schedule as $fy => $row) {
            if ($fy < $openingFy || $row['dep'] <= 0) {
                continue;
            }
            $date = $fy === $disposedFy ? (string) $asset['disposed_date'] : acc_fy_bounds($book, $fy)[1];
            if ($date > $lastDay) {
                continue;
            }
            $expense = $row['expense'];
            $debits = [];
            if ($expense > 0) {
                $debits[] = acc_line(ACC_ACCT_DEP_EXPENSE, null, ACC_TAX_NONE, $expense, 0, $asset['name']);
            }
            if ($row['dep'] - $expense > 0) {
                $debits[] = acc_line(ACC_ACCT_OWNER_DRAW, null, ACC_TAX_NONE, $row['dep'] - $expense, 0, $asset['name'] . '（家事分）');
            }
            $credit = $direct
                ? acc_line((string) $asset['asset_account'], $sub, ACC_TAX_NONE, $row['dep'], 0, '')
                : acc_line(ACC_ACCT_DEP_ACCUM, $sub, ACC_TAX_NONE, $row['dep'], 0, '');
            $entries[] = acc_entry('D' . $asset['id'] . '.' . $fy, $date, $asset['name'] . ' 減価償却費', $debits, [$credit], true);
        }
        if ($disposedFy !== null && $asset['disposed_date'] && (string) $asset['disposed_date'] <= $lastDay && isset($schedule[$disposedFy])) {
            $d = acc_asset_disposal($asset, $schedule[$disposedFy]);
            $accountLoss = $asset['disposal_kind'] === 'scrap' ? ACC_ACCT_SCRAP : ACC_ACCT_LOSS;
            $debits = [];
            $credits = [];
            if ($d['price'] > 0) {
                $debits[] = acc_line((string) ($asset['disposal_account'] ?: '未収入金'), null, ACC_TAX_NONE, $d['price'], 0, '');
            }
            if ($d['loss'] > 0) {
                $debits[] = acc_line($accountLoss, null, ACC_TAX_NONE, $d['loss'], 0, '');
            }
            if ($d['gain'] > 0) {
                $credits[] = acc_line(ACC_ACCT_GAIN, null, ACC_TAX_NONE, $d['gain'], 0, '');
            }
            if ($direct) {
                $credits[] = acc_line((string) $asset['asset_account'], $sub, ACC_TAX_NONE, $d['book'], 0, '');
            } else {
                $debits[] = acc_line(ACC_ACCT_DEP_ACCUM, $sub, ACC_TAX_NONE, $d['accum'], 0, '');
                $credits[] = acc_line((string) $asset['asset_account'], $sub, ACC_TAX_NONE, (int) $asset['cost'], 0, '');
            }
            $entries[] = acc_entry('D' . $asset['id'] . 'x', (string) $asset['disposed_date'], $asset['name'] . ($asset['disposal_kind'] === 'scrap' ? ' 除却' : ' 売却'), acc_drop_zero_lines($debits), acc_drop_zero_lines($credits), true);
        }
    }
    if ($settings['dep_offset']) {
        for ($fy = $openingFy; $fy <= $lastFy; $fy++) {
            [$first, $last] = acc_fy_bounds($book, $fy);
            if ($last > $lastDay) {
                break;
            }
            $b = 0;
            foreach ($others as $e) {
                if ($e['date'] < $first || $e['date'] > $last || str_starts_with((string) $e['key'], 'D')) {
                    continue;
                }
                foreach ($e['debits'] as $l) {
                    $b += $l['account'] === ACC_ACCT_DEP_EXPENSE ? (int) $l['amount'] : 0;
                }
                foreach ($e['credits'] as $l) {
                    $b -= $l['account'] === ACC_ACCT_DEP_EXPENSE ? (int) $l['amount'] : 0;
                }
            }
            if ($b > 0) {
                $entries[] = acc_entry('DO.' . $fy, $last, '減価償却費の実額への調整（概算分の振替）', [acc_line(ACC_ACCT_DEP_ACCUM, null, ACC_TAX_NONE, $b, 0, '')], [acc_line(ACC_ACCT_DEP_EXPENSE, null, ACC_TAX_NONE, $b, 0, '')], true);
            } elseif ($b < 0) {
                $entries[] = acc_entry('DO.' . $fy, $last, '減価償却費の実額への調整（概算分の振替）', [acc_line(ACC_ACCT_DEP_EXPENSE, null, ACC_TAX_NONE, -$b, 0, '')], [acc_line(ACC_ACCT_DEP_ACCUM, null, ACC_TAX_NONE, -$b, 0, '')], true);
            }
        }
    }
    return $entries;
}

/**
 * 固定資産台帳（指定した事業年度の一覧）。各行に、その年度の期首帳簿価額・償却額・期末の償却累計額と帳簿価額を付ける。
 *
 * @return list<array> 資産の行 + ['row' => スケジュールの当期の行 | null, 'acquired_in_year' => bool, 'disposed_in_year' => bool, 'warnings' => string[]]
 */
function acc_asset_register(PDO $pdo, array $book, int $fiscalYear): array
{
    $stmt = $pdo->prepare('SELECT * FROM acc_assets WHERE book_id = :b ORDER BY asset_account, acquired_date, id');
    $stmt->execute([':b' => $book['id']]);
    $settings = acc_asset_settings($pdo, (int) $book['id']);
    $out = [];
    foreach ($stmt->fetchAll() as $a) {
        $schedule = acc_dep_schedule($a, $book, $fiscalYear, $settings['opening_fy']);
        $acqFy = acc_fy_of($book, (string) $a['service_date']);
        $disposedFy = $a['status'] === 'disposed' && $a['disposed_date'] ? acc_fy_of($book, (string) $a['disposed_date']) : null;
        if ($acqFy > $fiscalYear || ($disposedFy !== null && $disposedFy < $fiscalYear)) {
            continue; // この年度には存在しない（取得前／前年度までに売却済み）
        }
        $warnings = acc_asset_warnings($a);
        $out[] = $a + ['row' => $schedule[$fiscalYear] ?? null, 'schedule' => $schedule, 'acquired_in_year' => $acqFy === $fiscalYear, 'disposed_in_year' => $disposedFy === $fiscalYear, 'warnings' => $warnings];
    }
    return $out;
}

/** 台帳の入力内容の点検 */
function acc_asset_warnings(array $a): array
{
    $w = [];
    $life = (int) ($a['life'] ?? 0);
    if ($a['method'] === 'sl' && ($life < 2 || $life > 100)) {
        $w[] = '耐用年数を入力してください。';
    }
    if ($a['method'] === 'sl' && $a['service_date'] < '2007-04-01') {
        $w[] = '平成19年3月31日以前に取得した資産の旧定額法は計算できません（「償却額を指定」を使ってください）。';
    }
    if ($a['method'] === 'db') {
        if (!isset(ACC_DB200[$life])) {
            $w[] = '定率法は耐用年数2〜50年のみ対応しています。';
        }
        if ($a['service_date'] < '2012-04-01') {
            $w[] = '平成24年3月31日以前に取得した資産の250%定率法等は計算できません（「償却額を指定」を使ってください）。';
        }
    }
    if ($a['method'] === 'manual' && (int) ($a['manual_annual'] ?? 0) <= 0) {
        $w[] = '年間償却額を入力してください。';
    }
    if (in_array($a['method'], ['small', 'small_expensed'], true) && (int) $a['cost'] >= acc_small_asset_limit((string) $a['acquired_date'])) {
        $w[] = '取得価額が少額減価償却資産の上限（' . number_format(acc_small_asset_limit((string) $a['acquired_date'])) . '円）以上です。';
    }
    if ($a['method'] === 'lump' && (int) $a['cost'] >= 200000) {
        $w[] = '一括償却資産は取得価額20万円未満が対象です。';
    }
    return $w;
}
