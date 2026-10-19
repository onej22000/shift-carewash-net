<?php
declare(strict_types=1);

require_once __DIR__ . '/accounting_uchiwake.php';

/**
 * 税務の設定（事業年度ごと）と消費税の計算。
 * 税率・経過措置の割合などは令和8年（2026年）10月時点の制度に基づく。法改正で変わる部分は定数と設定画面の値で変更する。
 */

/** 消費税の税率（国税分 7.8%・地方消費税分 2.2%、軽減税率 6.24% + 1.76%） */
const ACC_CT_NATIONAL10 = [78, 1000];   // 7.8%
const ACC_CT_NATIONAL8 = [624, 10000];  // 6.24%

/** 免税事業者等からの課税仕入れ（インボイス無し）の経過措置の控除割合（%）。日付が属する期間で決まる（令和8年度税制改正後の取扱い） */
const ACC_CT_TRANSITION = [
    ['2023-10-01', '2026-09-30', 80],
    ['2026-10-01', '2028-09-30', 70],
    ['2028-10-01', '2030-09-30', 50],
    ['2030-10-01', '2031-09-30', 30],
];

const ACC_SIMPLE_RATES = [1 => 90, 2 => 80, 3 => 70, 4 => 60, 5 => 50, 6 => 40];
const ACC_SIMPLE_LABELS = [1 => '第一種（卸売業）', 2 => '第二種（小売業）', 3 => '第三種（製造業等）', 4 => '第四種（その他）', 5 => '第五種（サービス業等）', 6 => '第六種（不動産業）'];
const ACC_CT_METHODS = ['general' => '原則課税（一般）', 'simple' => '簡易課税', 'special20' => '2割特例', 'none' => '免税事業者（申告なし）'];

function acc_tax_defaults(): array
{
    return [
        'consumption' => [
            'method' => 'general', 'simple_class' => 5, 'interim_national' => 0, 'interim_local' => 0, 'book_entry' => 0,
            'allocation' => 'individual', // 課税売上割合95%未満のときの方式: individual=個別対応方式 / bulk=一括比例配分方式
            'bulk_ratio' => 1,
        ],
        'corp' => [],
    ];
}

/** 事業年度の税務設定（保存が無い項目は既定値） */
function acc_tax_year_load(PDO $pdo, int $bookId, int $fiscalYear): array
{
    $data = [];
    try {
        $stmt = $pdo->prepare('SELECT data_json FROM acc_tax_year WHERE book_id = :b AND fiscal_year = :y');
        $stmt->execute([':b' => $bookId, ':y' => $fiscalYear]);
        $json = $stmt->fetchColumn();
        $data = $json ? (json_decode((string) $json, true) ?: []) : [];
    } catch (PDOException $e) {
        // テーブル未作成の間は既定値
    }
    $defaults = acc_tax_defaults();
    foreach ($defaults as $k => $v) {
        $data[$k] = array_merge($v, is_array($data[$k] ?? null) ? $data[$k] : []);
    }
    return $data;
}

function acc_tax_year_save(PDO $pdo, int $bookId, int $fiscalYear, array $data): void
{
    $pdo->prepare(
        'INSERT INTO acc_tax_year (book_id, fiscal_year, data_json) VALUES (:b, :y, :d) ON DUPLICATE KEY UPDATE data_json = VALUES(data_json)'
    )->execute([':b' => $bookId, ':y' => $fiscalYear, ':d' => json_encode($data, JSON_UNESCAPED_UNICODE)]);
}

/** 経過措置の控除割合（%）。対象外の期間は 0 */
function acc_ct_transition_percent(string $date): int
{
    foreach (ACC_CT_TRANSITION as [$from, $to, $pct]) {
        if ($date >= $from && $date <= $to) {
            return $pct;
        }
    }
    return 0;
}

/**
 * 仕訳の税区分から、課税売上・課税仕入の金額（税込）を集計する。
 * 売上は貸方がプラス（借方に付いた課税売上=売上返還はマイナス）、仕入は借方がプラス。
 *
 * @param list<array> $entries
 */
function acc_consumption_aggregate(array $entries, string $from, string $to): array
{
    $blank = static fn (): array => ['purch10' => 0, 'purch8' => 0, 'purch10_50' => 0, 'purch_nq' => []];
    $a = [
        'sales10' => 0, 'sales8' => 0, 'nontax_sales' => 0, 'nontax_purch' => 0, 'purch_nq_total' => 0,
    ] + $blank();                                  // 課税売上対応（または用途を区別しない）課税仕入れ
    $a['common'] = $blank();                       // 共通対応
    $a['nonuse'] = $blank();                       // 非課税売上対応
    foreach ($entries as $e) {
        if ($e['date'] < $from || $e['date'] > $to) {
            continue;
        }
        foreach (['debits' => 1, 'credits' => -1] as $side => $sign) {
            foreach ($e[$side] as $l) {
                $amount = (int) $l['amount'];
                $cls = (string) ($l['tax_class'] ?? ACC_TAX_NONE);
                if ($amount === 0 || $cls === ACC_TAX_NONE) {
                    continue;
                }
                $sales = -$sign * $amount;   // 貸方=+
                $purch = $sign * $amount;    // 借方=+
                switch ($cls) {
                    case '課税売上内10%':
                        $a['sales10'] += $sales;
                        continue 2;
                    case '課税売上内8%軽':
                        $a['sales8'] += $sales;
                        continue 2;
                    case '非課売上':
                        $a['nontax_sales'] += $sales;
                        continue 2;
                    case '非課仕入':
                        $a['nontax_purch'] += $purch;
                        continue 2;
                }
                if (!preg_match('/\A(課対|共対|非対)仕入内(10%|8%軽)(適格50%|適格)?\z/u', $cls, $m)) {
                    continue;
                }
                $use = ['課対' => null, '共対' => 'common', '非対' => 'nonuse'][$m[1]];
                $bucket = &$a;
                if ($use !== null) {
                    $bucket = &$a[$use];
                }
                if ($m[2] === '8%軽') {
                    $bucket['purch8'] += $purch;
                } elseif (($m[3] ?? '') === '適格50%') {
                    $bucket['purch10_50'] += $purch;
                } elseif (($m[3] ?? '') === '適格') {
                    $bucket['purch10'] += $purch;
                } else { // 適格請求書なし → 経過措置（日付で割合が決まる）
                    $pct = acc_ct_transition_percent((string) $e['date']);
                    $bucket['purch_nq'][$pct] = ($bucket['purch_nq'][$pct] ?? 0) + $purch;
                    $a['purch_nq_total'] += $purch;
                }
                unset($bucket);
            }
        }
    }
    return $a;
}

/** 課税仕入れの区分ごとの仕入控除税額（割戻し計算）。[合計, 明細] */
function acc_ct_purchase_tax(array $b, string $prefix): array
{
    $q10 = intdiv($b['purch10'] * 78, 1100);
    $q10_50 = intdiv(intdiv($b['purch10_50'] * 78, 1100) * 50, 100);
    $q8 = intdiv($b['purch8'] * 624, 10800);
    $detail = [
        ['label' => $prefix . '課税仕入（10%・適格）', 'base' => $b['purch10'], 'tax' => $q10],
        ['label' => $prefix . '課税仕入（8%軽減・適格）', 'base' => $b['purch8'], 'tax' => $q8],
        ['label' => $prefix . '課税仕入（10%・経過措置50%固定）', 'base' => $b['purch10_50'], 'tax' => $q10_50],
    ];
    $nq = 0;
    foreach ($b['purch_nq'] as $pct => $amt) {
        $part = intdiv(intdiv($amt * 78, 1100) * (int) $pct, 100);
        $detail[] = ['label' => $prefix . '適格請求書なしの仕入（経過措置 ' . $pct . '%控除）', 'base' => $amt, 'tax' => $part];
        $nq += $part;
    }
    return [$q10 + $q10_50 + $q8 + $nq, $detail];
}

function acc_floor_to(int $n, int $unit): int
{
    return $n >= 0 ? intdiv($n, $unit) * $unit : -intdiv(-$n, $unit) * $unit;
}

/**
 * 消費税及び地方消費税の申告（一般用）の計算。
 *
 * @return array<string, mixed>
 */
function acc_consumption_calc(array $agg, array $cfg): array
{
    $method = (string) $cfg['method'];
    $base10 = acc_floor_to(intdiv($agg['sales10'] * 100, 110), 1000);
    $base8 = acc_floor_to(intdiv($agg['sales8'] * 100, 108), 1000);
    $tax10 = intdiv($base10 * ACC_CT_NATIONAL10[0], ACC_CT_NATIONAL10[1]);
    $tax8 = intdiv($base8 * ACC_CT_NATIONAL8[0], ACC_CT_NATIONAL8[1]);
    $salesTax = $tax10 + $tax8;                                       // ② 消費税額

    // 課税売上割合（税抜）
    $taxableSalesExcl = intdiv($agg['sales10'] * 100, 110) + intdiv($agg['sales8'] * 100, 108);
    $nonTaxSales = $agg['nontax_sales'];
    $ratioDen = $taxableSalesExcl + $nonTaxSales;
    $ratioPct = $ratioDen > 0 ? $taxableSalesExcl / $ratioDen * 100 : 100.0;

    $deduction = 0;                                                   // ④ 控除対象仕入税額
    $detail = [];
    if ($method === 'general') {
        [$gMain, $dMain] = acc_ct_purchase_tax($agg, '');
        [$gCommon, $dCommon] = acc_ct_purchase_tax($agg['common'] ?? ['purch10' => 0, 'purch8' => 0, 'purch10_50' => 0, 'purch_nq' => []], '【共通対応】');
        [$gNon, $dNon] = acc_ct_purchase_tax($agg['nonuse'] ?? ['purch10' => 0, 'purch8' => 0, 'purch10_50' => 0, 'purch_nq' => []], '【非課税売上対応】');
        $gross = $gMain + $gCommon + $gNon;
        $detail = array_merge($dMain, $dCommon, $dNon);
        $over5 = $taxableSalesExcl > 500000000;
        if ($ratioPct < 95 || $over5) {
            $mode = ($cfg['allocation'] ?? (!empty($cfg['bulk_ratio']) ? 'bulk' : 'individual')) === 'bulk' ? 'bulk' : 'individual';
            if ($mode === 'bulk') {
                $deduction = $ratioDen > 0 ? intdiv($gross * $taxableSalesExcl, $ratioDen) : $gross;
                $detail[] = ['label' => '課税売上割合 ' . number_format($ratioPct, 2) . '%（一括比例配分方式）', 'base' => $gross, 'tax' => $deduction];
            } else {
                $apportioned = $ratioDen > 0 ? intdiv($gCommon * $taxableSalesExcl, $ratioDen) : $gCommon;
                $deduction = $gMain + $apportioned;
                $detail[] = ['label' => '課税売上対応の仕入税額（全額控除）', 'base' => 0, 'tax' => $gMain];
                $detail[] = ['label' => '共通対応の仕入税額 × 課税売上割合 ' . number_format($ratioPct, 2) . '%（個別対応方式）', 'base' => $gCommon, 'tax' => $apportioned];
                $detail[] = ['label' => '非課税売上対応の仕入税額（控除なし）', 'base' => $gNon, 'tax' => 0];
            }
        } else {
            $deduction = $gross;   // 課税売上割合95%以上かつ課税売上高5億円以下: 全額控除
        }
    } elseif ($method === 'simple') {
        $rate = ACC_SIMPLE_RATES[(int) $cfg['simple_class']] ?? 50;
        $deduction = intdiv($salesTax * $rate, 100);
        $detail[] = ['label' => 'みなし仕入率 ' . $rate . '%（' . (ACC_SIMPLE_LABELS[(int) $cfg['simple_class']] ?? '') . '）', 'base' => $salesTax, 'tax' => $deduction];
    } elseif ($method === 'special20') {
        $deduction = intdiv($salesTax * 80, 100);
        $detail[] = ['label' => '2割特例（売上税額の80%を控除）', 'base' => $salesTax, 'tax' => $deduction];
    } else {
        $salesTax = 0;
    }
    $diffRaw = $salesTax - $deduction;                                // ⑨ 差引税額
    $refund = $diffRaw < 0 ? -$diffRaw : 0;
    $diff = $diffRaw > 0 ? acc_floor_to($diffRaw, 100) : 0;
    $interimN = (int) $cfg['interim_national'];
    $interimL = (int) $cfg['interim_local'];
    $payableNational = $diff - $interimN;                             // ⑪ 納付税額（マイナス=中間納付還付）
    $localBase = $diff;                                               // 地方消費税の課税標準となる消費税額
    $localAmount = $diffRaw > 0 ? acc_floor_to(intdiv($localBase * 22, 78), 100) : 0; // 譲渡割額
    $payableLocal = $localAmount - $interimL;
    $refundLocal = $refund > 0 ? intdiv($refund * 22, 78) : 0;
    return [
        'method' => $method, 'base10' => $base10, 'base8' => $base8, 'base_total' => $base10 + $base8, 'tax10' => $tax10, 'tax8' => $tax8, 'sales_tax' => $salesTax,
        'deduction' => $deduction, 'diff_raw' => $diffRaw, 'diff' => $diff, 'refund' => $refund, 'interim_national' => $interimN, 'interim_local' => $interimL,
        'payable_national' => $payableNational, 'local_amount' => $localAmount, 'payable_local' => $payableLocal, 'refund_local' => $refundLocal,
        'total_payable' => $payableNational + $payableLocal,
        'total_tax' => $diff + $localAmount,           // 年間の税額（中間納付を引く前）。決算の「未払消費税等」の計上額
        'ratio_pct' => $ratioPct, 'taxable_sales_excl' => $taxableSalesExcl, 'nontax_sales' => $nonTaxSales,
        'detail' => $detail, 'agg' => $agg,
    ];
}
