<?php
declare(strict_types=1);

require_once __DIR__ . '/accounting.php';

/**
 * 帳簿機能: 勘定科目マスタ・期首残高・元帳・試算表・決算書（損益計算書／貸借対照表）・証憑の保存。
 * 仕訳の元になるのは acc_generate_all()（請求書・給与・定型仕訳・手入力）と、期首残高。
 * 金額はすべて「借方 − 貸方」の符号付き（借方残高が正）で集計し、表示のときに科目の区分で向きを直す。
 */

const ACC_RE_ACCOUNT = '繰越利益剰余金';
const ACC_UNREGISTERED = '未登録科目';

/** 区分 → 決算書の表示区分（この順に並べる） */
const ACC_SECTIONS = [
    'asset' => ['流動資産', '有形固定資産', '無形固定資産', '投資その他の資産', '繰延資産'],
    'liability' => ['流動負債', '固定負債'],
    'equity' => ['資本金', '資本剰余金', '利益剰余金', '元入金'],
    'revenue' => ['売上高', '営業外収益', '特別利益'],
    'expense' => ['売上原価', '販売費及び一般管理費', '営業外費用', '特別損失', '法人税等'],
];
const ACC_CATEGORY_LABELS = ['asset' => '資産', 'liability' => '負債', 'equity' => '純資産', 'revenue' => '収益', 'expense' => '費用'];

/** 勘定科目内訳書の種類 */
const ACC_UCHIWAKE_KINDS = ['預貯金', '受取手形', '売掛金', '有価証券', '棚卸資産', '仮払金', '貸付金', '固定資産', '支払手形', '買掛金', '仮受金', '借入金'];

/**
 * 標準の勘定科目。書式: 「区分:科目,科目…」。科目の後ろに @内訳書の種類 / ! 評価勘定(資産の控除) / ~P 課対仕入内10%適格 / ~S 課税売上内10%。
 */
const ACC_CHART_CORP = [
    'asset/流動資産' => '現金,小口現金,当座預金@預貯金,普通預金@預貯金,定期預金@預貯金,定期積金@預貯金,受取手形@受取手形,売掛金@売掛金,有価証券@有価証券,商品@棚卸資産,製品@棚卸資産,貯蔵品@棚卸資産,前渡金@仮払金,前払費用,仮払金@仮払金,立替金,短期貸付金@貸付金,未収入金@売掛金,未収消費税等,貸倒引当金!',
    'asset/有形固定資産' => '建物@固定資産,建物附属設備@固定資産,構築物@固定資産,機械装置@固定資産,車両運搬具@固定資産,工具器具備品@固定資産,土地@固定資産,減価償却累計額!',
    'asset/無形固定資産' => 'ソフトウェア@固定資産,商標権@固定資産,電話加入権@固定資産',
    'asset/投資その他の資産' => '投資有価証券@有価証券,出資金,長期貸付金@貸付金,差入保証金,長期前払費用,保険積立金,繰延税金資産',
    'asset/繰延資産' => '開業費,創立費',
    'liability/流動負債' => '支払手形@支払手形,買掛金@買掛金,短期借入金@借入金,未払金@買掛金,未払費用@買掛金,未払法人税等,未払消費税等,預り金@仮受金,前受金@仮受金,仮受金@仮受金,賞与引当金',
    'liability/固定負債' => '長期借入金@借入金,役員借入金@借入金,長期未払金',
    'equity/資本金' => '資本金',
    'equity/資本剰余金' => '資本準備金',
    'equity/利益剰余金' => '利益準備金,繰越利益剰余金',
    'revenue/売上高' => 'クリーニング売上~S,売上高~S',
    'revenue/営業外収益' => '受取利息,受取配当金,雑収入~S,為替差益,補助金収入',
    'revenue/特別利益' => '固定資産売却益,貸倒引当金戻入益',
    'expense/売上原価' => '期首商品棚卸高,仕入高~P,期末商品棚卸高',
    'expense/販売費及び一般管理費' => '役員報酬,給料手当,雑給,賞与,退職金,法定福利費,福利厚生費~P,採用教育費~P,外注費~P,荷造運賃~P,広告宣伝費~P,接待交際費~P,会議費~P,旅費交通費~P,通信費~P,販売手数料~P,消耗品費~P,事務用品費~P,修繕費~P,水道光熱費~P,新聞図書費~P,諸会費~P,支払手数料~P,車両費~P,地代家賃~P,賃借料~P,リース料~P,保険料,租税公課,支払報酬料~P,研修費~P,減価償却費,貸倒損失,貸倒繰入額,雑費~P',
    'expense/営業外費用' => '支払利息,雑損失,為替差損',
    'expense/特別損失' => '固定資産売却損,固定資産除却損',
    'expense/法人税等' => '法人税等',
];

const ACC_CHART_PERSONAL = [
    'asset/流動資産' => '現金,普通預金@預貯金,定期預金@預貯金,売掛金@売掛金,未収入金@売掛金,前払金,事業主貸,暗号資産,貸倒引当金!',
    'asset/有形固定資産' => '建物@固定資産,建物附属設備@固定資産,構築物@固定資産,車両運搬具@固定資産,工具器具備品@固定資産,土地@固定資産,減価償却累計額!',
    'liability/流動負債' => '買掛金@買掛金,未払金@買掛金,預り金@仮受金,借入金@借入金,事業主借',
    'equity/元入金' => '元入金',
    'revenue/売上高' => '家賃収入,礼金収入,更新料収入,売上高~S,暗号資産売却益,暗号資産その他収入',
    'revenue/営業外収益' => '受取利息,雑収入',
    'expense/売上原価' => '仕入高~P',
    'expense/販売費及び一般管理費' => '給料賃金,外注工賃~P,租税公課,損害保険料,修繕費~P,減価償却費,地代家賃~P,利子割引料,荷造運賃~P,水道光熱費~P,旅費交通費~P,通信費~P,広告宣伝費~P,接待交際費~P,消耗品費~P,福利厚生費~P,管理費~P,支払手数料~P,貸倒金,雑費~P,暗号資産売却原価,暗号資産手数料',
];

/** 標準科目を [name, category, section, uchiwake, contra, default_tax, sort] の一覧にする */
function acc_chart_defaults(string $kind): array
{
    $source = $kind === 'personal' ? ACC_CHART_PERSONAL : ACC_CHART_CORP;
    $rows = [];
    $sort = 0;
    foreach ($source as $head => $names) {
        [$category, $section] = explode('/', $head, 2);
        foreach (explode(',', $names) as $raw) {
            $name = $raw;
            $contra = 0;
            $tax = ACC_TAX_NONE;
            $uchiwake = null;
            if (str_ends_with($name, '!')) {
                $contra = 1;
                $name = substr($name, 0, -1);
            }
            if (($p = strpos($name, '~')) !== false) {
                $tax = substr($name, $p + 1) === 'S' ? ACC_TAX_SALES10 : ACC_TAX_PURCHASE10;
                $name = substr($name, 0, $p);
            }
            if (($p = strpos($name, '@')) !== false) {
                $uchiwake = substr($name, $p + 1);
                $name = substr($name, 0, $p);
            }
            $sort += 10;
            $rows[] = ['name' => $name, 'category' => $category, 'section' => $section, 'uchiwake' => $uchiwake, 'contra' => $contra, 'default_tax' => $tax, 'sort' => $sort];
        }
    }
    return $rows;
}

/** 勘定科目マスタが空なら標準科目を取り込む */
function acc_chart_ensure(PDO $pdo, array $book): void
{
    $bookId = (int) $book['id'];
    $count = $pdo->prepare('SELECT COUNT(*) FROM acc_chart WHERE book_id = :b');
    $count->execute([':b' => $bookId]);
    if ((int) $count->fetchColumn() > 0) {
        return;
    }
    $insert = $pdo->prepare(
        'INSERT IGNORE INTO acc_chart (book_id, name, category, section, uchiwake, is_contra, default_tax, sort_no)
         VALUES (:b, :n, :c, :s, :u, :k, :t, :o)'
    );
    foreach (acc_chart_defaults((string) $book['kind']) as $r) {
        $insert->execute([':b' => $bookId, ':n' => $r['name'], ':c' => $r['category'], ':s' => $r['section'], ':u' => $r['uchiwake'], ':k' => $r['contra'], ':t' => $r['default_tax'], ':o' => $r['sort']]);
    }
}

/** 科目名 → マスタの行 */
function acc_chart_map(PDO $pdo, array $book): array
{
    acc_chart_ensure($pdo, $book);
    $stmt = $pdo->prepare('SELECT * FROM acc_chart WHERE book_id = :b ORDER BY sort_no, id');
    $stmt->execute([':b' => $book['id']]);
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(string) $row['name']] = $row;
    }
    return $map;
}

/** 区分・表示区分の並び順の番号 */
function acc_section_rank(string $category, string $section): int
{
    $order = array_keys(ACC_CATEGORY_LABELS);
    $c = array_search($category, $order, true);
    $s = array_search($section, ACC_SECTIONS[$category] ?? [], true);
    return ((int) $c) * 100 + ($s === false ? 99 : (int) $s);
}

// ---------------------------------------------------------------------------
// 帳簿の設定・期首残高
// ---------------------------------------------------------------------------

/** @return array{opening_fy:?int, profile:array} */
function acc_book_settings(PDO $pdo, int $bookId): array
{
    $stmt = $pdo->prepare('SELECT * FROM acc_book_settings WHERE book_id = :b');
    $stmt->execute([':b' => $bookId]);
    $row = $stmt->fetch();
    if (!$row) {
        return ['opening_fy' => null, 'profile' => []];
    }
    $profile = json_decode((string) $row['profile_json'], true);
    return ['opening_fy' => $row['opening_fy'] === null ? null : (int) $row['opening_fy'], 'profile' => is_array($profile) ? $profile : []];
}

function acc_book_settings_save(PDO $pdo, int $bookId, ?int $openingFy, array $profile): void
{
    $pdo->prepare(
        'INSERT INTO acc_book_settings (book_id, opening_fy, profile_json) VALUES (:b, :f, :p)
         ON DUPLICATE KEY UPDATE opening_fy = VALUES(opening_fy), profile_json = VALUES(profile_json)'
    )->execute([':b' => $bookId, ':f' => $openingFy, ':p' => json_encode($profile, JSON_UNESCAPED_UNICODE)]);
}

/** 期首残高: "科目\x1f補助" → 符号付き金額 */
function acc_opening_balances(PDO $pdo, int $bookId): array
{
    $stmt = $pdo->prepare('SELECT account, sub, amount FROM acc_opening WHERE book_id = :b');
    $stmt->execute([':b' => $bookId]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[$r['account'] . "\x1f" . $r['sub']] = (int) $r['amount'];
    }
    return $out;
}

/** 事業年度（開始年）の最初の日・最後の日 */
function acc_fy_bounds(array $book, int $fiscalYear): array
{
    $range = acc_period_range($book, $fiscalYear, 'all');
    return [acc_month_first_day($range['from']), acc_month_last_day($range['to'])];
}

// ---------------------------------------------------------------------------
// 残高の集計
// ---------------------------------------------------------------------------

/**
 * 事業年度の勘定科目別の残高を集計する。
 *  - 貸借の科目: 期首（期首残高＋記帳開始から前期末までの動き）・当期借方・当期貸方・期末
 *  - 損益の科目: 当期の動きだけ（前期までの損益は繰越利益剰余金へ振り替える）
 * 符号は借方 − 貸方。
 *
 * @return array{ok:bool, message:string, accounts:array, unregistered:list<string>, first:string, last:string, prior_net:int, entries:array}
 */
function acc_balances(PDO $pdo, array $book, int $fiscalYear, ?array $generated = null): array
{
    $bookId = (int) $book['id'];
    $settings = acc_book_settings($pdo, $bookId);
    $fail = static fn (string $m): array => ['ok' => false, 'message' => $m, 'accounts' => [], 'unregistered' => [], 'first' => '', 'last' => '', 'prior_net' => 0, 'entries' => []];
    if ($settings['opening_fy'] === null) {
        return $fail('記帳の開始年度が未設定です。「設定 → 期首残高」で、記帳を始める事業年度と期首残高を登録してください。');
    }
    if ($fiscalYear < $settings['opening_fy']) {
        return $fail('記帳開始年度（' . $settings['opening_fy'] . '年度）より前の年度は集計できません。');
    }
    [$openFirst] = acc_fy_bounds($book, $settings['opening_fy']);
    [$first, $last] = acc_fy_bounds($book, $fiscalYear);
    $chart = acc_chart_map($pdo, $book);
    if ($generated === null) {
        $generated = acc_generate_all($pdo, $book, substr($last, 0, 7))['entries'];
    }

    $isPl = static fn (string $account): bool => isset($chart[$account]) && in_array($chart[$account]['category'], ['revenue', 'expense'], true);
    $acc = [];
    $touch = static function (string $account, string $sub) use (&$acc): void {
        $acc[$account][$sub] ??= ['open' => 0, 'dr' => 0, 'cr' => 0];
    };
    foreach (acc_opening_balances($pdo, $bookId) as $k => $amount) {
        [$account, $sub] = explode("\x1f", $k, 2);
        $touch($account, $sub);
        $acc[$account][$sub]['open'] += $amount;
    }
    $entries = [];
    foreach ($generated as $e) {
        if ($e['date'] < $openFirst || $e['date'] > $last) {
            continue;
        }
        $entries[] = $e;
        foreach (['debits' => 1, 'credits' => -1] as $side => $sign) {
            foreach ($e[$side] as $l) {
                $amount = (int) $l['amount'];
                if ($amount === 0) {
                    continue;
                }
                $account = (string) $l['account'];
                $sub = (string) ($l['sub'] ?? '');
                $touch($account, $sub);
                if ($e['date'] < $first) {
                    $acc[$account][$sub]['open'] += $sign * $amount;
                } elseif ($sign > 0) {
                    $acc[$account][$sub]['dr'] += $amount;
                } else {
                    $acc[$account][$sub]['cr'] += $amount;
                }
            }
        }
    }
    // 前期までの損益は繰越利益剰余金へ（損益科目の期首は 0 にする）
    $priorPl = 0;
    foreach ($acc as $account => &$subs) {
        if ($isPl((string) $account)) {
            foreach ($subs as &$v) {
                $priorPl += $v['open'];
                $v['open'] = 0;
            }
            unset($v);
        }
    }
    unset($subs);
    if ($priorPl !== 0) {
        $touch(ACC_RE_ACCOUNT, '');
        $acc[ACC_RE_ACCOUNT][''] ['open'] += $priorPl;
    }
    $unregistered = [];
    $out = [];
    foreach ($acc as $account => $subs) {
        $account = (string) $account;
        if (!isset($chart[$account])) {
            $unregistered[] = $account;
        }
        $total = ['open' => 0, 'dr' => 0, 'cr' => 0, 'end' => 0, 'subs' => []];
        ksort($subs);
        foreach ($subs as $sub => $v) {
            $v['end'] = $v['open'] + $v['dr'] - $v['cr'];
            if ($v['open'] === 0 && $v['dr'] === 0 && $v['cr'] === 0) {
                continue;
            }
            $total['subs'][(string) $sub] = $v;
            foreach (['open', 'dr', 'cr', 'end'] as $f) {
                $total[$f] += $v[$f];
            }
        }
        if ($total['subs'] !== []) {
            $out[$account] = $total;
        }
    }
    return ['ok' => true, 'message' => '', 'accounts' => $out, 'unregistered' => array_values(array_unique($unregistered)), 'first' => $first, 'last' => $last, 'prior_net' => -$priorPl, 'entries' => $entries];
}

/**
 * 試算表の行（科目の並び順に）。各行: 科目・区分・表示区分・期首/借方/貸方/期末（符号付き）と補助科目の内訳。
 */
function acc_trial_rows(array $balances, array $chart): array
{
    $rows = [];
    foreach ($balances['accounts'] as $account => $v) {
        $c = $chart[$account] ?? null;
        $rows[] = $v + [
            'account' => (string) $account,
            'category' => $c['category'] ?? 'unknown',
            'section' => $c['section'] ?? ACC_UNREGISTERED,
            'contra' => (int) ($c['is_contra'] ?? 0),
            'sort' => $c === null ? PHP_INT_MAX : (int) $c['sort_no'],
        ];
    }
    usort($rows, static fn (array $a, array $b): int => [acc_section_rank($a['category'], $a['section']), $a['sort'], $a['account']] <=> [acc_section_rank($b['category'], $b['section']), $b['sort'], $b['account']]);
    return $rows;
}

/**
 * 損益計算書・貸借対照表。表示金額は科目の区分に合わせた向き（資産・費用=借方、負債・純資産・収益=貸方）。
 *
 * @return array{pl:array, bs:array, check:array}
 */
function acc_statements(array $balances, array $chart): array
{
    $rows = acc_trial_rows($balances, $chart);
    $sections = [];
    foreach ($rows as $r) {
        $cat = $r['category'];
        $debitSide = in_array($cat, ['asset', 'expense', 'unknown'], true);
        $r['amount'] = $debitSide ? $r['end'] : -$r['end'];
        $sections[$cat][$r['section']][] = $r;
    }
    $sum = static function (array $secs, string $name): int {
        return array_sum(array_map(static fn (array $r): int => $r['amount'], $secs[$name] ?? []));
    };
    $rev = $sections['revenue'] ?? [];
    $exp = $sections['expense'] ?? [];
    $sales = $sum($rev, '売上高');
    $cogs = $sum($exp, '売上原価');
    $gross = $sales - $cogs;
    $sga = $sum($exp, '販売費及び一般管理費');
    $operating = $gross - $sga;
    $ordinary = $operating + $sum($rev, '営業外収益') - $sum($exp, '営業外費用');
    $beforeTax = $ordinary + $sum($rev, '特別利益') - $sum($exp, '特別損失');
    $tax = $sum($exp, '法人税等');
    $net = $beforeTax - $tax;

    $asset = $sections['asset'] ?? [];
    $liab = $sections['liability'] ?? [];
    $equity = $sections['equity'] ?? [];
    $assetTotal = 0;
    foreach ($asset as $list) {
        $assetTotal += array_sum(array_column($list, 'amount'));
    }
    $liabTotal = 0;
    foreach ($liab as $list) {
        $liabTotal += array_sum(array_column($list, 'amount'));
    }
    $equityTotal = $net;
    foreach ($equity as $list) {
        $equityTotal += array_sum(array_column($list, 'amount'));
    }
    $unknown = $sections['unknown'] ?? [];
    $unknownTotal = 0;
    foreach ($unknown as $list) {
        $unknownTotal += array_sum(array_column($list, 'amount'));
    }
    return [
        'pl' => [
            'sections' => ['revenue' => $rev, 'expense' => $exp],
            'sales' => $sales, 'cogs' => $cogs, 'gross' => $gross, 'sga' => $sga, 'operating' => $operating,
            'non_op_income' => $sum($rev, '営業外収益'), 'non_op_expense' => $sum($exp, '営業外費用'), 'ordinary' => $ordinary,
            'special_gain' => $sum($rev, '特別利益'), 'special_loss' => $sum($exp, '特別損失'), 'before_tax' => $beforeTax, 'tax' => $tax, 'net' => $net,
        ],
        'bs' => [
            'asset' => $asset, 'liability' => $liab, 'equity' => $equity,
            'asset_total' => $assetTotal, 'liability_total' => $liabTotal, 'equity_total' => $equityTotal, 'net' => $net,
        ],
        'check' => ['diff' => $assetTotal - $liabTotal - $equityTotal, 'unknown' => $unknown, 'unknown_total' => $unknownTotal],
    ];
}

// ---------------------------------------------------------------------------
// 元帳
// ---------------------------------------------------------------------------

/**
 * 総勘定元帳（補助科目を指定すれば補助元帳）。期首残高と日付順の明細、残高。
 *
 * @return array{open:int, rows:list<array>, end:int}
 */
function acc_ledger(array $balances, array $chart, string $account, ?string $sub): array
{
    $open = 0;
    $rows = [];
    if (!isset($balances['accounts'][$account])) {
        return ['open' => 0, 'rows' => [], 'end' => 0];
    }
    foreach ($balances['accounts'][$account]['subs'] as $s => $v) {
        if ($sub === null || $sub === (string) $s) {
            $open += $v['open'];
        }
    }
    $isPl = isset($chart[$account]) && in_array($chart[$account]['category'], ['revenue', 'expense'], true);
    $lines = [];
    foreach ($balances['entries'] as $e) {
        if ($e['date'] < $balances['first'] || $e['date'] > $balances['last']) {
            continue;
        }
        foreach (['debits' => 1, 'credits' => -1] as $side => $sign) {
            foreach ($e[$side] as $l) {
                if ($l['account'] !== $account || ($sub !== null && (string) ($l['sub'] ?? '') !== $sub)) {
                    continue;
                }
                $other = [];
                foreach ($e[$side === 'debits' ? 'credits' : 'debits'] as $o) {
                    $other[] = $o['account'] . ((string) ($o['sub'] ?? '') !== '' ? '（' . $o['sub'] . '）' : '');
                }
                $lines[] = [
                    'date' => $e['date'], 'key' => $e['key'], 'desc' => $e['desc'], 'sub' => (string) ($l['sub'] ?? ''),
                    'other' => implode('／', array_unique($other)), 'dr' => $sign > 0 ? (int) $l['amount'] : 0, 'cr' => $sign < 0 ? (int) $l['amount'] : 0,
                    'settle' => $e['settle'],
                ];
            }
        }
    }
    usort($lines, static fn (array $a, array $b): int => [$a['date'], $a['key']] <=> [$b['date'], $b['key']]);
    $balance = $isPl ? 0 : $open;
    foreach ($lines as $l) {
        $balance += $l['dr'] - $l['cr'];
        $l['balance'] = $balance;
        $rows[] = $l;
    }
    return ['open' => $isPl ? 0 : $open, 'rows' => $rows, 'end' => $balance];
}

// ---------------------------------------------------------------------------
// 証憑ファイル
// ---------------------------------------------------------------------------

const ACC_ATTACH_MAX_BYTES = 10 * 1024 * 1024;
const ACC_ATTACH_TYPES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/heic' => 'heic'];

/** 証憑の保存先（includes 配下。Web から直接は開けず、admin/accounting_file.php 経由で配信する） */
function acc_attach_dir(): string
{
    $dir = __DIR__ . '/acc_files';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    if (!is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    }
    return $dir;
}

/**
 * アップロードされた1ファイルを保存して acc_attachments に記録する。失敗時は理由の文字列、成功時は null。
 */
function acc_attach_store(PDO $pdo, int $bookId, ?int $manualId, array $file, ?string $docDate, ?int $docAmount, string $counterparty, int $userId): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? 0) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
        return ($file['name'] ?? 'ファイル') . ': アップロードに失敗しました。';
    }
    if ((int) $file['size'] > ACC_ATTACH_MAX_BYTES) {
        return $file['name'] . ': 10MBを超えています。';
    }
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset(ACC_ATTACH_TYPES[$mime])) {
        return $file['name'] . ': PDF・JPEG・PNG・WebP・HEIC の画像だけ保存できます。';
    }
    $hash = hash_file('sha256', $file['tmp_name']);
    $stored = bin2hex(random_bytes(12)) . '.' . ACC_ATTACH_TYPES[$mime];
    if (!move_uploaded_file($file['tmp_name'], acc_attach_dir() . '/' . $stored)) {
        return $file['name'] . ': 保存できませんでした。';
    }
    $pdo->prepare(
        'INSERT INTO acc_attachments (book_id, manual_id, doc_date, doc_amount, counterparty, original_name, stored_name, mime, size, sha256, created_by)
         VALUES (:b, :m, :d, :a, :c, :o, :s, :t, :z, :h, :u)'
    )->execute([
        ':b' => $bookId, ':m' => $manualId, ':d' => $docDate, ':a' => $docAmount, ':c' => $counterparty !== '' ? mb_substr($counterparty, 0, 100) : null,
        ':o' => mb_substr(basename((string) $file['name']), 0, 200), ':s' => $stored, ':t' => $mime, ':z' => (int) $file['size'], ':h' => $hash, ':u' => $userId,
    ]);
    return null;
}

/** $_FILES['name'] の複数ファイル形式を1ファイルずつの配列にする */
function acc_files_list(string $field): array
{
    $f = $_FILES[$field] ?? null;
    if (!is_array($f) || !is_array($f['name'] ?? null)) {
        return is_array($f) && ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE ? [$f] : [];
    }
    $out = [];
    foreach ($f['name'] as $i => $name) {
        if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $out[] = ['name' => $name, 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
    }
    return $out;
}

function acc_audit_log(PDO $pdo, int $bookId, string $target, int $targetId, string $action, array $detail, ?int $userId): void
{
    $pdo->prepare('INSERT INTO acc_audit (book_id, target, target_id, action, detail, user_id) VALUES (:b, :t, :i, :a, :d, :u)')
        ->execute([':b' => $bookId, ':t' => $target, ':i' => $targetId, ':a' => $action, ':d' => json_encode($detail, JSON_UNESCAPED_UNICODE), ':u' => $userId]);
}

/** 金額の表示。0 は空欄、負は △ */
function acc_money(int $n, bool $zero = false): string
{
    if ($n === 0) {
        return $zero ? '0' : '';
    }
    return ($n < 0 ? '△' : '') . number_format(abs($n));
}
