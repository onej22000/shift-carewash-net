<?php
declare(strict_types=1);

require_once __DIR__ . '/accounting_assets.php';

/**
 * 勘定科目内訳書（法人税申告書に添付する内訳書）。金額は元帳の補助科目ごとの期末残高から作り、
 * 相手先の所在地・金融機関の支店名／口座番号・借入金の利率／担保などの補足情報は画面で入力して acc_sub_info に保存する。
 */

/**
 * @var array<string, array{no:string, title:string, kinds:list<string>, side:string, name:string, fields:array<string,string>, memo:string, extra?:string}>
 */
const ACC_UCHIWAKE_FORMS = [
    'uc01' => ['no' => '①', 'title' => '預貯金等の内訳書', 'kinds' => ['預貯金'], 'side' => 'asset', 'name' => '金融機関名', 'fields' => ['branch' => '支店名', 'type' => '種類', 'number' => '口座番号'], 'memo' => '摘要'],
    'uc02' => ['no' => '②', 'title' => '受取手形の内訳書', 'kinds' => ['受取手形'], 'side' => 'asset', 'name' => '支払人', 'fields' => ['issue_date' => '振出年月日', 'due_date' => '支払期日', 'bank' => '支払銀行名'], 'memo' => '摘要'],
    'uc03' => ['no' => '③', 'title' => '売掛金（未収入金）の内訳書', 'kinds' => ['売掛金'], 'side' => 'asset', 'name' => '相手先（名称）', 'fields' => ['address' => '相手先（所在地）'], 'memo' => '摘要'],
    'uc04a' => ['no' => '④-1', 'title' => '仮払金（前渡金）の内訳書', 'kinds' => ['仮払金'], 'side' => 'asset', 'name' => '相手先（名称）', 'fields' => ['address' => '相手先（所在地）', 'relation' => '法人・代表者との関係'], 'memo' => '取引の内容'],
    'uc04b' => ['no' => '④-2', 'title' => '貸付金及び受取利息の内訳書', 'kinds' => ['貸付金'], 'side' => 'asset', 'name' => '貸付先（名称）', 'fields' => ['address' => '貸付先（所在地）', 'relation' => '法人・代表者との関係', 'rate' => '利率', 'collateral' => '担保の内容'], 'memo' => '摘要', 'extra' => 'interest_in'],
    'uc05' => ['no' => '⑤', 'title' => '棚卸資産の内訳書', 'kinds' => ['棚卸資産'], 'side' => 'asset', 'name' => '品名', 'fields' => ['qty' => '数量', 'unit_price' => '単価'], 'memo' => '摘要'],
    'uc06' => ['no' => '⑥', 'title' => '有価証券の内訳書', 'kinds' => ['有価証券'], 'side' => 'asset', 'name' => '銘柄', 'fields' => ['kind' => '区分', 'qty' => '数量', 'acquired' => '取得価額'], 'memo' => '摘要'],
    'uc07' => ['no' => '⑦', 'title' => '固定資産の内訳書（土地・土地の上に存する権利・通常の建物を除く）', 'kinds' => [], 'side' => 'asset', 'name' => '種類', 'fields' => [], 'memo' => '摘要', 'extra' => 'assets'],
    'uc08' => ['no' => '⑧', 'title' => '支払手形の内訳書', 'kinds' => ['支払手形'], 'side' => 'liability', 'name' => '支払先', 'fields' => ['issue_date' => '振出年月日', 'due_date' => '支払期日', 'bank' => '支払銀行名'], 'memo' => '摘要'],
    'uc09' => ['no' => '⑨', 'title' => '買掛金（未払金・未払費用）の内訳書', 'kinds' => ['買掛金'], 'side' => 'liability', 'name' => '相手先（名称）', 'fields' => ['address' => '相手先（所在地）'], 'memo' => '摘要'],
    'uc10' => ['no' => '⑩', 'title' => '仮受金（前受金・預り金）の内訳書', 'kinds' => ['仮受金'], 'side' => 'liability', 'name' => '相手先（名称）', 'fields' => ['address' => '相手先（所在地）', 'relation' => '法人・代表者との関係'], 'memo' => '取引の内容'],
    'uc11' => ['no' => '⑪', 'title' => '借入金及び支払利子の内訳書', 'kinds' => ['借入金'], 'side' => 'liability', 'name' => '借入先（名称）', 'fields' => ['address' => '借入先（所在地）', 'relation' => '法人・代表者との関係', 'rate' => '利率', 'collateral' => '担保の内容'], 'memo' => '摘要', 'extra' => 'interest_out'],
    'uc14' => ['no' => '⑭', 'title' => '役員報酬手当等及び人件費の内訳書', 'kinds' => [], 'side' => 'expense', 'name' => '氏名', 'fields' => ['title' => '役職名', 'attend' => '常勤・非常勤の別'], 'memo' => '摘要', 'extra' => 'officers'],
    'uc15' => ['no' => '⑮', 'title' => '地代家賃等の内訳書', 'kinds' => [], 'side' => 'expense', 'name' => '支払先（名称）', 'fields' => ['address' => '支払先（所在地）', 'use' => '物件の用途'], 'memo' => '摘要', 'extra' => 'rent'],
    'uc16' => ['no' => '⑯', 'title' => '雑益、雑損失等の内訳書', 'kinds' => [], 'side' => 'expense', 'name' => '内容', 'fields' => [], 'memo' => '摘要', 'extra' => 'misc'],
];

/** 補足情報の読み込み: "科目\x1f補助" → 項目の配列 */
function acc_sub_info_load(PDO $pdo, int $bookId, string $formCode): array
{
    try {
        $stmt = $pdo->prepare('SELECT account, sub, data_json FROM acc_sub_info WHERE book_id = :b AND form_code = :f');
        $stmt->execute([':b' => $bookId, ':f' => $formCode]);
    } catch (PDOException $e) {
        return [];
    }
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[$r['account'] . "\x1f" . $r['sub']] = json_decode((string) $r['data_json'], true) ?: [];
    }
    return $out;
}

function acc_sub_info_save(PDO $pdo, int $bookId, string $formCode, string $account, string $sub, array $data): void
{
    $clean = array_filter(array_map(static fn ($v): string => acc_clean_text((string) $v), $data), static fn (string $v): bool => $v !== '');
    $pdo->prepare(
        'INSERT INTO acc_sub_info (book_id, form_code, account, sub, data_json) VALUES (:b, :f, :a, :s, :d)
         ON DUPLICATE KEY UPDATE data_json = VALUES(data_json)'
    )->execute([':b' => $bookId, ':f' => $formCode, ':a' => $account, ':s' => $sub, ':d' => json_encode($clean, JSON_UNESCAPED_UNICODE)]);
}

/** 補助科目ごとの金額（資産・費用=借方 / 負債・収益=貸方 の向き） */
function acc_uc_amount(array $balances, string $account, string $sub, bool $debitSide): int
{
    $v = $balances['accounts'][$account]['subs'][$sub]['end'] ?? 0;
    return $debitSide ? $v : -$v;
}

/** 補助科目ごとの当期の動き（借方 − 貸方。費用・収益の補助科目別の合計に使う） */
function acc_uc_flow(array $balances, string $account, string $sub, bool $debitSide): int
{
    $v = $balances['accounts'][$account]['subs'][$sub] ?? null;
    if ($v === null) {
        return 0;
    }
    $n = $v['dr'] - $v['cr'];
    return $debitSide ? $n : -$n;
}

/**
 * 内訳書1枚分の行を作る。
 *
 * @return array{rows:list<array>, total:int, total2:int, notes:string[]}
 *   各行: account, sub, name, amount, info(array), amount2(利子など), key
 */
function acc_uchiwake_build(PDO $pdo, array $book, array $balances, array $chart, string $code, int $fiscalYear): array
{
    $form = ACC_UCHIWAKE_FORMS[$code];
    $bookId = (int) $book['id'];
    $info = acc_sub_info_load($pdo, $bookId, $code);
    $rows = [];
    $notes = [];
    $debitSide = $form['side'] === 'asset';
    $extra = $form['extra'] ?? '';

    $push = static function (string $account, string $sub, string $name, int $amount, ?int $amount2 = null) use (&$rows, $info): void {
        $k = $account . "\x1f" . $sub;
        $rows[] = ['account' => $account, 'sub' => $sub, 'name' => $name, 'amount' => $amount, 'amount2' => $amount2, 'info' => $info[$k] ?? []];
    };

    if ($extra === 'assets') {
        foreach (acc_asset_register($pdo, $book, $fiscalYear) as $a) {
            if (in_array($a['asset_account'], ['土地', '建物'], true) || $a['method'] === 'small_expensed' || $a['row'] === null || $a['row']['book'] === 0 && !$a['acquired_in_year']) {
                continue;
            }
            $k = 'asset' . "\x1f" . $a['id'];
            $rows[] = ['account' => $a['asset_account'], 'sub' => (string) $a['id'], 'name' => $a['name'], 'amount' => $a['row']['book'], 'amount2' => (int) $a['cost'],
                'info' => $info[$k] ?? [], 'acquired' => $a['acquired_date'], 'qty' => (int) $a['quantity']];
        }
        return acc_uc_finish($rows, $notes, $form);
    }

    if ($extra === 'officers') {
        $stmt = $pdo->prepare(
            "SELECT s.employee_id, COALESCE(JSON_UNQUOTE(JSON_EXTRACT(s.employee_snapshot, '\$.name')), e.name) AS name, SUM(s.gross_total) AS total
             FROM pay_slips s JOIN pay_runs r ON r.id = s.run_id LEFT JOIN employees e ON e.id = s.employee_id
             WHERE r.status = 'closed' AND s.employment_type = 'officer' AND r.period_end BETWEEN :f AND :l GROUP BY s.employee_id, name ORDER BY s.employee_id"
        );
        try {
            $stmt->execute([':f' => $balances['first'], ':l' => $balances['last']]);
            foreach ($stmt->fetchAll() as $r) {
                $push('役員報酬', (string) $r['name'], (string) $r['name'], (int) $r['total']);
            }
        } catch (PDOException $e) {
            $notes[] = '給与のデータを読めませんでした。';
        }
        $rows = array_values(array_filter($rows, static fn (array $r): bool => $r['amount'] !== 0));
        // 人件費の科目別合計（参考）
        $people = [];
        foreach (['役員報酬', '給料手当', '雑給', '賞与', '退職金', '法定福利費', '福利厚生費'] as $acct) {
            $n = $balances['accounts'][$acct]['dr'] ?? 0;
            $n -= $balances['accounts'][$acct]['cr'] ?? 0;
            if ($n !== 0) {
                $people[] = ['name' => $acct, 'amount' => $n];
            }
        }
        $res = acc_uc_finish($rows, $notes, $form);
        $res['people'] = $people;
        return $res;
    }

    if ($extra === 'rent') {
        foreach (['地代家賃', '賃借料'] as $acct) {
            foreach ($balances['accounts'][$acct]['subs'] ?? [] as $sub => $v) {
                $amount = $v['dr'] - $v['cr'];
                if ($amount !== 0) {
                    $push($acct, (string) $sub, (string) $sub === '' ? '（補助科目なし）' : (string) $sub, $amount);
                }
            }
        }
        return acc_uc_finish($rows, $notes, $form);
    }

    if ($extra === 'misc') {
        $groups = [];
        foreach ($balances['entries'] as $e) {
            if ($e['date'] < $balances['first'] || $e['date'] > $balances['last']) {
                continue;
            }
            foreach (['debits' => 1, 'credits' => -1] as $side => $sign) {
                foreach ($e[$side] as $l) {
                    if (in_array($l['account'], ['雑収入', '雑損失'], true)) {
                        $sgn = $l['account'] === '雑収入' ? -$sign : $sign; // 雑収入は貸方、雑損失は借方が正
                        $key = $l['account'] . "\x1f" . ((string) $e['desc'] !== '' ? $e['desc'] : (string) ($l['sub'] ?? ''));
                        $groups[$key] = ($groups[$key] ?? 0) + $sgn * (int) $l['amount'];
                    }
                }
            }
        }
        ksort($groups);
        foreach ($groups as $k => $amount) {
            if ($amount !== 0) {
                [$account, $label] = explode("\x1f", $k, 2);
                $push($account, $label, $label === '' ? '（摘要なし）' : $label, $amount);
            }
        }
        return acc_uc_finish($rows, $notes, $form);
    }

    foreach ($chart as $account => $c) {
        if (!in_array($c['uchiwake'], $form['kinds'], true) || !isset($balances['accounts'][$account])) {
            continue;
        }
        foreach ($balances['accounts'][$account]['subs'] as $sub => $v) {
            $amount = acc_uc_amount($balances, (string) $account, (string) $sub, $debitSide);
            if ($amount === 0) {
                continue;
            }
            $amount2 = null;
            if ($extra === 'interest_out') {
                $amount2 = acc_uc_flow($balances, '支払利息', (string) $sub, true);
            } elseif ($extra === 'interest_in') {
                $amount2 = acc_uc_flow($balances, '受取利息', (string) $sub, false);
            }
            $push((string) $account, (string) $sub, (string) $sub === '' ? '（補助科目なし）' : (string) $sub, $amount, $amount2);
        }
    }
    if ($extra === 'interest_out') {
        $listed = array_sum(array_map(static fn (array $r): int => (int) $r['amount2'], $rows));
        $all = ($balances['accounts']['支払利息']['dr'] ?? 0) - ($balances['accounts']['支払利息']['cr'] ?? 0);
        if ($all !== $listed) {
            $notes[] = '支払利息 ' . number_format($all) . '円のうち、借入金の補助科目と一致しない ' . number_format($all - $listed) . '円は、この表の支払利子額に入っていません（支払利息の補助科目に借入先名を入れてください）。';
        }
    }
    return acc_uc_finish($rows, $notes, $form);
}

function acc_uc_finish(array $rows, array $notes, array $form): array
{
    return [
        'rows' => $rows,
        'total' => array_sum(array_column($rows, 'amount')),
        'total2' => array_sum(array_map(static fn (array $r): int => (int) ($r['amount2'] ?? 0), $rows)),
        'notes' => $notes,
    ];
}
