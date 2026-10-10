<?php
declare(strict_types=1);

require_once __DIR__ . '/accounting_corptax.php';

/**
 * 個人の所得税（青色申告決算書・確定申告書）の計算。
 * 対象: 不動産所得（青色申告）・事業所得・雑所得（暗号資産など）・給与所得（源泉徴収票の金額を入力）の総合課税。
 * 分離課税（株式等の譲渡・配当の申告分離など）や住宅ローン控除、医療費控除の明細などは対象外（所得控除・税額控除として金額を入力する）。
 * 数字は申告書の下書き（検算用）。提出前に必ず確認すること。
 */

const ACC_PERSONAL_INCOME_TYPES = ['realestate' => '不動産所得', 'business' => '事業所得', 'misc' => '雑所得', 'interest' => '利子所得（源泉分離などで申告不要の想定）'];
const ACC_PERSONAL_REALESTATE_ACCOUNTS = ['家賃収入', '礼金収入', '更新料収入'];
const ACC_BLUE_DEDUCTIONS = [0 => 'なし（白色）', 100000 => '10万円', 550000 => '55万円', 650000 => '65万円'];

/** 所得税の速算表（課税所得の上限, 税率%, 控除額） */
const ACC_INCOME_TAX_BRACKETS = [
    [1950000, 5, 0], [3300000, 10, 97500], [6950000, 20, 427500], [9000000, 23, 636000],
    [18000000, 33, 1536000], [40000000, 40, 2796000], [PHP_INT_MAX, 45, 4796000],
];
const ACC_RECONSTRUCTION_RATE = 2.1; // 復興特別所得税（基準所得税額の2.1%。令和19年分まで）

function acc_personal_defaults(): array
{
    return [
        'blue_deduction' => 650000,
        'expense_target' => 'auto',        // 経費の帰属: auto / realestate / business
        'salary' => ['income' => 0, 'withheld' => 0],           // 源泉徴収票: 給与所得控除後の金額・源泉徴収税額
        'social_insurance' => 0,           // 社会保険料控除（給与天引き分を含む合計）
        'small_biz_mutual' => 0,           // 小規模企業共済等掛金控除
        'other_deductions' => [],          // [['label'=>,'amount'=>]] 配偶者・扶養・生命保険料・医療費・寄附金など
        'tax_credits' => 0,                // 住宅借入金等特別控除など
        'prepaid' => 0,                    // 予定納税額
        'other_withheld' => 0,             // 給与以外の源泉徴収税額（報酬の源泉など）
    ];
}

function acc_personal_cfg(array $taxYear): array
{
    $d = acc_personal_defaults();
    $c = is_array($taxYear['personal'] ?? null) ? $taxYear['personal'] : [];
    foreach ($c as $k => $v) {
        if ($k === 'salary') {
            $d['salary'] = array_merge($d['salary'], is_array($v) ? $v : []);
        } else {
            $d[$k] = $v;
        }
    }
    return $d;
}

/** 基礎控除（暦年 = 事業年度の開始年。令和7年分は95/88/68/63/58万円、令和8・9年分は特例あり、令和10年分以後は本則） */
function acc_basic_deduction(int $year, int $totalIncome): int
{
    if ($totalIncome > 25000000) {
        return 0;
    }
    if ($totalIncome > 24500000) {
        return 160000;
    }
    if ($totalIncome > 24000000) {
        return 320000;
    }
    if ($totalIncome > 23500000) {
        return 480000;
    }
    if ($year <= 2024) {
        return 480000;
    }
    if ($year === 2025) {
        return $totalIncome <= 1320000 ? 950000 : ($totalIncome <= 3360000 ? 880000 : ($totalIncome <= 4890000 ? 680000 : ($totalIncome <= 6550000 ? 630000 : 580000)));
    }
    if ($year <= 2027) {
        return $totalIncome <= 4890000 ? 1040000 : ($totalIncome <= 6550000 ? 670000 : 620000);
    }
    return $totalIncome <= 1320000 ? 990000 : 620000;
}

function acc_income_tax_amount(int $taxable): int
{
    foreach (ACC_INCOME_TAX_BRACKETS as [$limit, $rate, $deduct]) {
        if ($taxable <= $limit) {
            return max(0, intdiv($taxable * $rate, 100) - $deduct);
        }
    }
    return 0;
}

function acc_personal_income_type(string $account, array $chartRow): string
{
    if (str_starts_with($account, '暗号資産')) {
        return 'misc';
    }
    if (in_array($account, ACC_PERSONAL_REALESTATE_ACCOUNTS, true)) {
        return 'realestate';
    }
    if ($account === '受取利息') {
        return 'interest';
    }
    if ($account === '雑収入') {
        return 'misc';
    }
    return 'business';
}

/**
 * @return array<string, mixed>
 */
function acc_personal_calc(PDO $pdo, array $book, int $fiscalYear, array $balances, array $chart): array
{
    $cfg = acc_personal_cfg(acc_tax_year_load($pdo, (int) $book['id'], $fiscalYear));
    $types = array_fill_keys(array_keys(ACC_PERSONAL_INCOME_TYPES), ['revenue' => [], 'expense' => [], 'rev_total' => 0, 'exp_total' => 0]);
    $revAccounts = [];
    foreach ($balances['accounts'] as $account => $row) {
        $cat = $chart[$account]['category'] ?? null;
        if ($cat === 'revenue') {
            $revAccounts[$account] = -$row['end'];
        }
    }
    $hasRealestate = false;
    $hasBusiness = false;
    foreach ($revAccounts as $account => $amount) {
        $t = acc_personal_income_type($account, $chart[$account] ?? []);
        $hasRealestate = $hasRealestate || ($t === 'realestate' && $amount !== 0);
        $hasBusiness = $hasBusiness || ($t === 'business' && $amount !== 0);
    }
    $target = $cfg['expense_target'];
    if (!in_array($target, ['realestate', 'business'], true)) {
        $target = $hasRealestate || !$hasBusiness ? 'realestate' : 'business';
    }
    foreach ($revAccounts as $account => $amount) {
        if ($amount === 0) {
            continue;
        }
        $t = acc_personal_income_type($account, $chart[$account] ?? []);
        $types[$t]['revenue'][] = ['account' => $account, 'amount' => $amount];
        $types[$t]['rev_total'] += $amount;
    }
    foreach ($balances['accounts'] as $account => $row) {
        if (($chart[$account]['category'] ?? null) !== 'expense' || $row['end'] === 0) {
            continue;
        }
        $t = str_starts_with($account, '暗号資産') ? 'misc' : $target;
        $types[$t]['expense'][] = ['account' => $account, 'amount' => $row['end']];
        $types[$t]['exp_total'] += $row['end'];
    }
    foreach ($types as $k => &$t) {
        $t['before_blue'] = $t['rev_total'] - $t['exp_total'];
    }
    unset($t);

    // 青色申告特別控除: 不動産所得・事業所得のうち所得が出ている分から（赤字にはならない）。複数ある場合は事業所得→不動産所得の順に
    $blue = max(0, (int) $cfg['blue_deduction']);
    $blueUsed = ['business' => 0, 'realestate' => 0];
    foreach (['business', 'realestate'] as $k) {
        $avail = max(0, $types[$k]['before_blue']);
        $use = min($blue, $avail);
        $blueUsed[$k] = $use;
        $blue -= $use;
    }
    $realestate = $types['realestate']['before_blue'] - $blueUsed['realestate'];
    $business = $types['business']['before_blue'] - $blueUsed['business'];
    $miscRaw = $types['misc']['before_blue'];
    $misc = max(0, $miscRaw);                                      // 雑所得の赤字は他の所得と通算できない
    $salary = max(0, (int) $cfg['salary']['income']);
    $total = $realestate + $business + $salary + $misc;           // 損益通算後の合計（不動産・事業の赤字は通算可）
    $totalIncome = max(0, $total);

    $basic = acc_basic_deduction($fiscalYear, $totalIncome);
    $social = max(0, (int) $cfg['social_insurance']);
    $mutual = max(0, (int) $cfg['small_biz_mutual']);
    $others = [];
    foreach ((array) $cfg['other_deductions'] as $o) {
        if (($o['label'] ?? '') !== '' && (int) ($o['amount'] ?? 0) > 0) {
            $others[] = ['label' => (string) $o['label'], 'amount' => (int) $o['amount']];
        }
    }
    $deductions = $basic + $social + $mutual + array_sum(array_column($others, 'amount'));
    $taxable = acc_floor_to(max(0, $totalIncome - $deductions), 1000);
    $tax = acc_income_tax_amount($taxable);
    $credits = max(0, (int) $cfg['tax_credits']);
    $afterCredit = max(0, $tax - $credits);
    $recon = intdiv($afterCredit * 21, 1000);
    $sumTax = acc_floor_to($afterCredit + $recon, 100);
    $withheld = max(0, (int) $cfg['salary']['withheld']) + max(0, (int) $cfg['other_withheld']);
    $prepaid = max(0, (int) $cfg['prepaid']);
    $payable = $sumTax - $withheld - $prepaid;                     // マイナス=還付

    return [
        'fy' => $fiscalYear, 'cfg' => $cfg, 'types' => $types, 'expense_target' => $target,
        'blue_used' => $blueUsed, 'blue_total' => array_sum($blueUsed),
        'realestate' => $realestate, 'business' => $business, 'misc' => $misc, 'misc_raw' => $miscRaw, 'salary' => $salary,
        'total' => $total, 'total_income' => $totalIncome,
        'basic' => $basic, 'social' => $social, 'mutual' => $mutual, 'others' => $others, 'deductions' => $deductions,
        'taxable' => $taxable, 'tax' => $tax, 'credits' => $credits, 'after_credit' => $afterCredit, 'recon' => $recon, 'sum_tax' => $sumTax,
        'withheld' => $withheld, 'prepaid' => $prepaid, 'payable' => $payable,
    ];
}
