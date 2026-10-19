<?php
declare(strict_types=1);

require_once __DIR__ . '/accounting_tax.php';

/**
 * 法人税・地方法人税・防衛特別法人税・法人住民税・事業税・特別法人事業税の計算と、別表四／別表五(一)(二)／別表七(一)／別表十五 の元になる数字。
 * 対象は「資本金1億円以下の中小法人」で、事業年度は12か月。税率は設定画面で変更できる（初期値は令和8年10月時点の標準的な値）。
 * 計算結果は申告の下書き（検算用）であり、提出前に税理士または電子申告ソフトで確認すること。
 */

const ACC_CORP_LOW_LIMIT = 8000000;         // 軽減税率の対象となる所得（年800万円）
const ACC_BIZ_TIER1 = 4000000;              // 事業税の区分（年400万円・年800万円）
const ACC_BIZ_TIER2 = 8000000;
const ACC_DEFENSE_START = '2026-04-01';     // 防衛特別法人税は令和8年4月1日以後に開始する事業年度から
const ACC_DEFENSE_DEDUCTION = 5000000;      // 年500万円の基礎控除
const ACC_ENTERTAIN_LIMIT = 8000000;        // 交際費等の定額控除限度額（年800万円）

function acc_corp_defaults(): array
{
    return [
        'rates' => [
            'corp_low' => 15.0, 'corp_high' => 23.2, 'local_corp' => 10.3, 'defense' => 4.0,
            'pref' => 1.0, 'city' => 6.0, 'biz1' => 3.5, 'biz2' => 5.3, 'biz3' => 7.0, 'special_biz' => 37.0,
        ],
        'equal' => ['pref' => 20000, 'city' => 50000],
        'interim' => ['corp' => 0, 'local_corp' => 0, 'defense' => 0, 'pref' => 0, 'city' => 0, 'biz' => 0, 'special_biz' => 0],
        'tax_credit' => 0,
        'adjustments' => [],     // [['kind'=>'add|sub','label'=>'','amount'=>0,'flow'=>'retain|out','note'=>'']]
        'loss_cf' => [],         // [['fy'=>2024,'amount'=>1000000]] 青色欠損金（繰越控除前の残額）
        'entertain' => ['food_expense' => 0],
        'prior_biz_tax' => null, // 前期に計上した事業税・特別法人事業税（当期に認容）。空なら前期の計算結果
        'opening_unpaid' => ['corp' => 0, 'pref' => 0, 'city' => 0, 'biz' => 0], // 記帳開始時の未納税額（前期末の未納の法人税・住民税・事業税）
        'book_entry' => 0,
        'dividends' => 0,
    ];
}

function acc_corp_cfg(array $taxYear): array
{
    $d = acc_corp_defaults();
    $c = is_array($taxYear['corp'] ?? null) ? $taxYear['corp'] : [];
    foreach (['rates', 'equal', 'interim', 'entertain', 'opening_unpaid'] as $k) {
        $d[$k] = array_merge($d[$k], is_array($c[$k] ?? null) ? $c[$k] : []);
    }
    foreach (['tax_credit', 'adjustments', 'loss_cf', 'prior_biz_tax', 'book_entry', 'dividends'] as $k) {
        if (array_key_exists($k, $c)) {
            $d[$k] = $c[$k];
        }
    }
    return $d;
}

/** 金額 × 率(%)（円未満切捨て） */
function acc_pct(int $amount, float $ratePct): int
{
    $r = (int) round($ratePct * 1000);
    return $amount >= 0 ? intdiv($amount * $r, 100000) : -intdiv(-$amount * $r, 100000);
}

/** 仕訳の一覧から、事業年度の損益（法人税等を除く）を集計する */
function acc_pl_from_entries(array $entries, array $chart, string $first, string $last): array
{
    $byAccount = [];
    foreach ($entries as $e) {
        if ($e['date'] < $first || $e['date'] > $last) {
            continue;
        }
        foreach (['debits' => 1, 'credits' => -1] as $side => $sign) {
            foreach ($e[$side] as $l) {
                $byAccount[$l['account']] = ($byAccount[$l['account']] ?? 0) + $sign * (int) $l['amount'];
            }
        }
    }
    $profit = 0;
    foreach ($byAccount as $account => $signed) {
        $cat = $chart[$account]['category'] ?? null;
        if ($account === '法人税等') {
            continue;
        }
        if ($cat === 'revenue') {
            $profit += -$signed;
        } elseif ($cat === 'expense') {
            $profit -= $signed;
        }
    }
    return ['before_tax' => $profit, 'by_account' => $byAccount, 'booked_tax' => $byAccount['法人税等'] ?? 0];
}

/**
 * 1事業年度の法人税等の計算。
 *
 * @param list<array> $entries 帳簿のすべての仕訳（acc_generate_all の結果）
 * @return array<string, mixed>
 */
function acc_corp_calc(PDO $pdo, array $book, int $fiscalYear, array $entries, array $chart, int $depth = 0): array
{
    $bookId = (int) $book['id'];
    $taxYear = acc_tax_year_load($pdo, $bookId, $fiscalYear);
    $cfg = acc_corp_cfg($taxYear);
    $rates = $cfg['rates'];
    [$first, $last] = acc_fy_bounds($book, $fiscalYear);
    $months = 12;
    $settings = acc_book_settings($pdo, $bookId);
    $openingFy = $settings['opening_fy'];

    $pl = acc_pl_from_entries($entries, $chart, $first, $last);
    $pre = $pl['before_tax'];
    $booked = $pl['booked_tax'];

    // 前期の計算結果（前期末の未納税額・前期に計上した事業税）
    $prev = null;
    if ($openingFy !== null && $fiscalYear - 1 >= $openingFy && $depth < 12) {
        $prev = acc_corp_calc($pdo, $book, $fiscalYear - 1, $entries, $chart, $depth + 1);
    }
    $priorBiz = $cfg['prior_biz_tax'] !== null && $cfg['prior_biz_tax'] !== '' ? (int) $cfg['prior_biz_tax'] : ($prev ? $prev['biz_total'] : (int) $cfg['opening_unpaid']['biz']);

    // 交際費等（別表十五）
    $entertain = (int) ($pl['by_account']['接待交際費'] ?? 0);
    $limitFixed = intdiv(ACC_ENTERTAIN_LIMIT * $months, 12);
    $notFixed = max(0, $entertain - $limitFixed);
    $food = max(0, (int) $cfg['entertain']['food_expense']);
    $notFood = $food > 0 ? max(0, $entertain - intdiv($food, 2)) : null;
    $entertainNot = $notFood !== null ? min($notFixed, $notFood) : $notFixed;

    // 別表四
    $adds = [];
    $subs = [];
    $adds[] = ['label' => '損金経理をした納税充当金', 'amount' => $booked, 'flow' => 'retain', 'auto' => true];
    if ($entertainNot > 0) {
        $adds[] = ['label' => '交際費等の損金不算入額', 'amount' => $entertainNot, 'flow' => 'out', 'auto' => true];
    }
    foreach ((array) $cfg['adjustments'] as $a) {
        $amount = (int) ($a['amount'] ?? 0);
        if ($amount === 0 || ($a['label'] ?? '') === '') {
            continue;
        }
        if (($a['kind'] ?? 'add') === 'sub') {
            $subs[] = ['label' => (string) $a['label'], 'amount' => $amount, 'flow' => ($a['flow'] ?? 'retain') === 'out' ? 'out' : 'retain', 'auto' => false];
        } else {
            $adds[] = ['label' => (string) $a['label'], 'amount' => $amount, 'flow' => ($a['flow'] ?? 'out') === 'retain' ? 'retain' : 'out', 'auto' => false];
        }
    }
    if ($priorBiz !== 0) {
        $subs[] = ['label' => '納税充当金から支出した事業税等の金額', 'amount' => $priorBiz, 'flow' => 'retain', 'auto' => true];
    }
    $profitBook = $pre - $booked;
    $addTotal = array_sum(array_column($adds, 'amount'));
    $subTotal = array_sum(array_column($subs, 'amount'));
    $income = $profitBook + $addTotal - $subTotal;                  // 差引計＝欠損金控除前の所得金額

    // 別表七(一) 欠損金の繰越控除（青色・10年繰越。中小法人は所得の100%まで）
    $losses = [];
    foreach ((array) $cfg['loss_cf'] as $l) {
        $y = (int) ($l['fy'] ?? 0);
        $amt = (int) ($l['amount'] ?? 0);
        if ($amt > 0 && $y > 0) {
            $losses[] = ['fy' => $y, 'amount' => $amt, 'expired' => $y + 10 < $fiscalYear];
        }
    }
    usort($losses, static fn (array $a, array $b): int => $a['fy'] <=> $b['fy']);
    $room = max(0, $income);
    $lossUsed = 0;
    foreach ($losses as &$l) {
        $l['used'] = 0;
        if (!$l['expired'] && $room > 0) {
            $l['used'] = min($l['amount'], $room);
            $room -= $l['used'];
            $lossUsed += $l['used'];
        }
        $l['remain'] = $l['expired'] ? 0 : $l['amount'] - $l['used'];
    }
    unset($l);
    $newLoss = $income < 0 ? -$income : 0;
    $taxable = max(0, $income - $lossUsed);
    $taxableK = intdiv($taxable, 1000) * 1000;                       // 千円未満切捨て

    // 法人税
    $lowCap = intdiv(ACC_CORP_LOW_LIMIT * $months, 12);
    $low = min($taxableK, $lowCap);
    $high = $taxableK - $low;
    $corpTaxBefore = acc_pct($low, (float) $rates['corp_low']) + acc_pct($high, (float) $rates['corp_high']);
    $credit = max(0, (int) $cfg['tax_credit']);
    $corpTax = max(0, $corpTaxBefore - $credit);
    $baseCorp = intdiv($corpTaxBefore, 1000) * 1000;                 // 課税標準法人税額（基準法人税額）
    $localCorp = acc_pct($baseCorp, (float) $rates['local_corp']);   // 地方法人税
    $defenseApplies = $first >= ACC_DEFENSE_START;
    $defense = $defenseApplies ? acc_pct(max(0, $baseCorp - intdiv(ACC_DEFENSE_DEDUCTION * $months, 12)), (float) $rates['defense']) : 0;

    // 法人住民税
    $residentBase = intdiv($corpTax, 1000) * 1000;
    $prefLevy = intdiv(acc_pct($residentBase, (float) $rates['pref']), 100) * 100;
    $cityLevy = intdiv(acc_pct($residentBase, (float) $rates['city']), 100) * 100;
    $prefEqual = intdiv((int) $cfg['equal']['pref'] * $months, 12);
    $cityEqual = intdiv((int) $cfg['equal']['city'] * $months, 12);
    $pref = $prefLevy + $prefEqual;
    $city = $cityLevy + $cityEqual;

    // 事業税・特別法人事業税
    $t1 = min($taxableK, intdiv(ACC_BIZ_TIER1 * $months, 12));
    $t2 = min(max(0, $taxableK - $t1), intdiv(ACC_BIZ_TIER2 * $months, 12) - $t1);
    $t3 = max(0, $taxableK - $t1 - $t2);
    $biz = intdiv(acc_pct($t1, (float) $rates['biz1']) + acc_pct($t2, (float) $rates['biz2']) + acc_pct($t3, (float) $rates['biz3']), 100) * 100;
    $bizStd = acc_pct($t1, 3.5) + acc_pct($t2, 5.3) + acc_pct($t3, 7.0);
    $specialBiz = intdiv(acc_pct(intdiv($bizStd, 100) * 100, (float) $rates['special_biz']), 100) * 100;

    $im = $cfg['interim'];
    $imCorpGroup = (int) $im['corp'] + (int) $im['local_corp'] + (int) $im['defense'];
    $corpGroupTotal = $corpTax + $localCorp + $defense;
    $bizTotal = $biz + $specialBiz;
    $bizInterim = (int) $im['biz'] + (int) $im['special_biz'];
    $total = $corpGroupTotal + $pref + $city + $bizTotal;
    $unpaid = [
        'corp' => $corpTax - (int) $im['corp'],
        'local_corp' => $localCorp - (int) $im['local_corp'],
        'defense' => $defense - (int) $im['defense'],
        'pref' => $pref - (int) $im['pref'],
        'city' => $city - (int) $im['city'],
        'biz' => $biz - (int) $im['biz'],
        'special_biz' => $specialBiz - (int) $im['special_biz'],
    ];

    $prevUnpaid = [
        'corp' => $prev ? $prev['unpaid']['corp'] + $prev['unpaid']['local_corp'] + $prev['unpaid']['defense'] : (int) $cfg['opening_unpaid']['corp'],
        'pref' => $prev ? $prev['unpaid']['pref'] : (int) $cfg['opening_unpaid']['pref'],
        'city' => $prev ? $prev['unpaid']['city'] : (int) $cfg['opening_unpaid']['city'],
        'biz' => $prev ? $prev['unpaid']['biz'] + $prev['unpaid']['special_biz'] : (int) $cfg['opening_unpaid']['biz'],
    ];

    return [
        'fy' => $fiscalYear, 'first' => $first, 'last' => $last, 'months' => $months,
        'pre_tax_profit' => $pre, 'booked_tax' => $booked, 'profit_book' => $profitBook,
        'adds' => $adds, 'subs' => $subs, 'add_total' => $addTotal, 'sub_total' => $subTotal, 'income' => $income,
        'entertain' => ['total' => $entertain, 'limit_fixed' => $limitFixed, 'not_fixed' => $notFixed, 'food' => $food, 'not_food' => $notFood, 'not_deductible' => $entertainNot],
        'losses' => $losses, 'loss_used' => $lossUsed, 'new_loss' => $newLoss, 'taxable' => $taxable, 'taxable_k' => $taxableK,
        'low' => $low, 'high' => $high, 'corp_before' => $corpTaxBefore, 'credit' => $credit, 'corp_tax' => $corpTax, 'base_corp' => $baseCorp,
        'local_corp' => $localCorp, 'defense' => $defense, 'defense_applies' => $defenseApplies,
        'resident_base' => $residentBase, 'pref_levy' => $prefLevy, 'city_levy' => $cityLevy, 'pref_equal' => $prefEqual, 'city_equal' => $cityEqual, 'pref' => $pref, 'city' => $city,
        'biz_tiers' => [$t1, $t2, $t3], 'biz' => $biz, 'biz_std' => $bizStd, 'special_biz' => $specialBiz, 'biz_total' => $bizTotal,
        'corp_group_total' => $corpGroupTotal, 'total' => $total, 'interim' => $im, 'unpaid' => $unpaid, 'prev_unpaid' => $prevUnpaid, 'prior_biz' => $priorBiz,
        'cfg' => $cfg, 'prev' => $prev ? ['fy' => $prev['fy'], 'total' => $prev['total']] : null,
    ];
}

/**
 * 別表五(一)・(二) の計算。
 *
 * @return array{rows:list<array>, total:array, capital:list<array>, schedule2:list<array>}
 */
function acc_corp_schedule5(array $calc, array $balances, array $chart): array
{
    $acc = $balances['accounts'];
    $reOpen = -($acc[ACC_RE_ACCOUNT]['open'] ?? 0);                       // 期首の繰越利益剰余金（貸方=プラス）
    $net = $calc['pre_tax_profit'] - $calc['booked_tax'];                    // 当期純利益（法人税等の計上後）
    $dividends = (int) ($calc['cfg']['dividends'] ?? 0);
    $reEnd = $reOpen + $net - $dividends;
    $reserveOpen = -($acc['未払法人税等']['open'] ?? 0);                    // 納税充当金（期首）
    $reserveEnd = -($acc['未払法人税等']['end'] ?? 0);
    $reserveAdd = $calc['booked_tax'];
    $reserveSub = $reserveOpen + $reserveAdd - $reserveEnd;

    $rows = [];
    $rows[] = ['label' => '繰越損益金（損は赤）', 'open' => $reOpen, 'dec' => $reOpen, 'inc' => $reEnd];
    $rows[] = ['label' => '納税充当金', 'open' => $reserveOpen, 'dec' => $reserveSub, 'inc' => $reserveAdd];
    $up = $calc['unpaid'];
    $pu = $calc['prev_unpaid'];
    $rows[] = ['label' => '未納法人税（附帯税を除く。地方法人税・防衛特別法人税を含む）', 'open' => -$pu['corp'], 'dec' => -$pu['corp'], 'inc' => -($up['corp'] + $up['local_corp'] + $up['defense'])];
    $rows[] = ['label' => '未納道府県民税（均等割額を含む）', 'open' => -$pu['pref'], 'dec' => -$pu['pref'], 'inc' => -$up['pref']];
    $rows[] = ['label' => '未納市町村民税（均等割額を含む）', 'open' => -$pu['city'], 'dec' => -$pu['city'], 'inc' => -$up['city']];
    // 別表四で「留保」とした調整のうち、納税充当金・事業税認容以外（減価償却超過額など）
    foreach (array_merge($calc['adds'], $calc['subs']) as $i => $a) {
        if ($a['auto'] || $a['flow'] !== 'retain') {
            continue;
        }
        $isSub = in_array($a, $calc['subs'], true);
        $rows[] = ['label' => $a['label'], 'open' => 0, 'dec' => $isSub ? -$a['amount'] : 0, 'inc' => $isSub ? 0 : $a['amount'], 'manual' => true];
    }
    $total = ['open' => 0, 'dec' => 0, 'inc' => 0, 'end' => 0];
    foreach ($rows as &$r) {
        $r['end'] = $r['open'] - $r['dec'] + $r['inc'];
        foreach (['open', 'dec', 'inc', 'end'] as $f) {
            $total[$f] += $r[$f];
        }
    }
    unset($r);

    $capital = [];
    $capEnd = 0;
    foreach (['資本金', '資本準備金'] as $name) {
        $v = -($acc[$name]['end'] ?? 0);
        $o = -($acc[$name]['open'] ?? 0);
        if ($v !== 0 || $o !== 0) {
            $capital[] = ['label' => $name, 'open' => $o, 'inc' => $v - $o, 'end' => $v];
            $capEnd += $v;
        }
    }

    // 別表五(二) 租税公課の納付状況等
    $im = $calc['interim'];
    $s2 = [
        ['label' => '法人税（地方法人税・防衛特別法人税を含む）', 'open' => $pu['corp'], 'occurred' => $calc['corp_group_total'], 'paid' => $pu['corp'] + (int) $im['corp'] + (int) $im['local_corp'] + (int) $im['defense']],
        ['label' => '道府県民税', 'open' => $pu['pref'], 'occurred' => $calc['pref'], 'paid' => $pu['pref'] + (int) $im['pref']],
        ['label' => '市町村民税', 'open' => $pu['city'], 'occurred' => $calc['city'], 'paid' => $pu['city'] + (int) $im['city']],
        ['label' => '事業税（特別法人事業税を含む）', 'open' => $pu['biz'], 'occurred' => $calc['biz_total'], 'paid' => $pu['biz'] + (int) $im['biz'] + (int) $im['special_biz']],
    ];
    foreach ($s2 as &$r) {
        $r['end'] = $r['open'] + $r['occurred'] - $r['paid'];
    }
    unset($r);
    return ['rows' => $rows, 'total' => $total, 'capital' => $capital, 'capital_end' => $capEnd, 'schedule2' => $s2, 're_end' => $reEnd, 'reserve_end' => $reserveEnd];
}

/**
 * 年末の税金の仕訳（消費税の未払計上・法人税等の計上）を作る。設定で「仕訳を自動で作る」にした事業年度だけ。
 * 消費税の仕訳は損益に影響するので、法人税等の計算には消費税の仕訳を含めて行う。
 *
 * @return list<array>
 */
function acc_tax_entries(PDO $pdo, array $book, string $toMonth, array $others): array
{
    $bookId = (int) $book['id'];
    try {
        $settings = acc_book_settings($pdo, $bookId);
        $chart = acc_chart_map($pdo, $book);
    } catch (PDOException $e) {
        return [];
    }
    $openingFy = $settings['opening_fy'];
    if ($openingFy === null || $book['kind'] !== 'corporate') {
        return [];
    }
    $lastDay = acc_month_last_day($toMonth);
    $lastFy = acc_fy_of($book, $lastDay);
    $out = [];
    $all = array_values($others);
    for ($fy = $openingFy; $fy <= $lastFy; $fy++) {
        [$first, $last] = acc_fy_bounds($book, $fy);
        if ($last > $lastDay) {
            break;
        }
        $data = acc_tax_year_load($pdo, $bookId, $fy);
        $ct = $data['consumption'];
        if (!empty($ct['book_entry']) && $ct['method'] !== 'none') {
            $calc = acc_consumption_calc(acc_consumption_aggregate($all, $first, $last), $ct);
            $amount = $calc['total_tax'] - ($calc['refund'] > 0 ? 0 : 0);
            if ($calc['refund'] > 0) {
                $amount = -($calc['refund'] + $calc['refund_local']);
            }
            if ($amount > 0) {
                $e = acc_entry('XC.' . $fy, $last, '消費税及び地方消費税の納付見込額', [acc_line('租税公課', null, ACC_TAX_NONE, $amount, 0, '')], [acc_line('未払消費税等', null, ACC_TAX_NONE, $amount, 0, '')], true);
            } elseif ($amount < 0) {
                $e = acc_entry('XC.' . $fy, $last, '消費税及び地方消費税の還付見込額', [acc_line('未収消費税等', null, ACC_TAX_NONE, -$amount, 0, '')], [acc_line('租税公課', null, ACC_TAX_NONE, -$amount, 0, '')], true);
            } else {
                $e = null;
            }
            if ($e !== null) {
                $out[] = $e;
                $all[] = $e;
            }
        }
        $corp = acc_corp_cfg($data);
        if (!empty($corp['book_entry'])) {
            // 計算には自動計上分（XT）を含めない
            $calcAll = array_values(array_filter($all, static fn (array $e): bool => !str_starts_with((string) $e['key'], 'XT.')));
            $c = acc_corp_calc($pdo, $book, $fy, $calcAll, $chart);
            if ($c['total'] > 0) {
                $e = acc_entry('XT.' . $fy, $last, '法人税、住民税及び事業税の計上', [acc_line('法人税等', null, ACC_TAX_NONE, $c['total'], 0, '')], [acc_line('未払法人税等', null, ACC_TAX_NONE, $c['total'], 0, '')], true);
                $out[] = $e;
                $all[] = $e;
            }
        }
    }
    return $out;
}
