<?php
declare(strict_types=1);

/**
 * 弥生会計 連携（admin/accounting*.php）の共通処理。
 *
 * シフトシステムの請求書・給与・定型仕訳・手入力から「仕訳」を作り、弥生会計の仕訳インポート形式（25列・カンマ区切り・Shift-JIS）の
 * CSVに書き出す。弥生に取り込んだ後は、弥生から出した仕訳日記帳と突き合わせて取込漏れ・金額の食い違いを確認できる。
 *
 * 考え方:
 *  - 仕訳（entry）はシフトシステム側のデータから毎回作り直す。DBには「出力済みの記録」だけを持つ（acc_export_items）。
 *  - 各仕訳には短い識別キー（例: I12 = 請求書ID12）を付け、弥生の「仕訳メモ」欄に "SF:I12#1" の形で書き込む。
 *    照合はこのキーで行う。
 *  - 出力済みの仕訳の元データが後から変わった場合は、出力済みの仕訳の逆仕訳（赤伝）＋新しい仕訳、として出す。
 *    元データが無くなった場合（請求書の無効化など）は逆仕訳だけを出す。
 *  - 金額はすべて整数（円）。浮動小数は使わない。
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/invoice_common.php';
require_once __DIR__ . '/payroll.php';

const ACC_MEMO_PREFIX = 'SF:';
const ACC_TAX_NONE = '対象外';
const ACC_TAX_SALES10 = '課税売上内10%';
const ACC_TAX_PURCHASE10 = '課対仕入内10%適格';
/** 弥生の税区分のうち、このシステムが扱うもの（税額を内税10%で自動計算するのは 課税売上内10% / 課対仕入内10%適格 系） */
const ACC_TAX_CLASSES = [
    '対象外', '課税売上内10%', '課税売上内8%軽', '非課売上', '課対仕入内10%適格', '課対仕入内10%適格50%', '課対仕入内10%', '非課仕入', '課対仕入内8%軽適格',
    // 個別対応方式用（共通対応・非課税売上対応）
    '共対仕入内10%適格', '共対仕入内10%適格50%', '共対仕入内10%', '共対仕入内8%軽適格', '非対仕入内10%適格', '非対仕入内10%適格50%', '非対仕入内10%', '非対仕入内8%軽適格',
];
const ACC_SUMMARY_MAX_BYTES = 64;   // 弥生の摘要（全角32文字）
const ACC_SUB_MAX_CHARS = 20;       // 弥生の補助科目名
const ACC_MAX_ENTRY_SPAN_YEARS = 3;

/** 仕訳の元データの種類（identifier のキー接頭辞） */
const ACC_SOURCE_LABELS = ['I' => '請求書', 'P' => '給与', 'T' => '定型仕訳', 'M' => '手入力', 'D' => '固定資産', 'X' => '税金計上'];

/** 仕訳マッピングの初期値（弥生の標準的な科目名。会社ごとに「仕訳設定」で変更できる） */
const ACC_DEFAULT_MAP = [
    'inv_receivable' => ['label' => '請求書：借方（売掛金）', 'account' => '売掛金', 'sub' => '{client}', 'tax' => ACC_TAX_NONE],
    'inv_sales' => ['label' => '請求書：貸方（売上）', 'account' => 'クリーニング売上', 'sub' => '', 'tax' => ACC_TAX_SALES10],
    'pay_wage' => ['label' => '給与：従業員の給与・手当', 'account' => '給料手当', 'sub' => '', 'tax' => ACC_TAX_NONE],
    'pay_officer' => ['label' => '給与：役員報酬', 'account' => '役員報酬', 'sub' => '', 'tax' => ACC_TAX_NONE],
    'pay_commute' => ['label' => '給与：通勤手当・駐車場代（消費税は課税仕入）', 'account' => '旅費交通費', 'sub' => '', 'tax' => ACC_TAX_PURCHASE10],
    'pay_si_expense' => ['label' => '給与：社会保険料の会社負担分（費用）', 'account' => '法定福利費', 'sub' => '', 'tax' => ACC_TAX_NONE],
    'pay_si_payable' => ['label' => '給与：社会保険料の会社負担分（未払）', 'account' => '未払費用', 'sub' => '社会保険料', 'tax' => ACC_TAX_NONE],
    'pay_unpaid' => ['label' => '給与：差引支給額（支給日まで）', 'account' => '未払金', 'sub' => '{employee}', 'tax' => ACC_TAX_NONE],
    'pay_dep_tax' => ['label' => '給与：預り金（源泉所得税）', 'account' => '預り金', 'sub' => '源泉所得税', 'tax' => ACC_TAX_NONE],
    'pay_dep_resident' => ['label' => '給与：預り金（住民税）', 'account' => '預り金', 'sub' => '住民税', 'tax' => ACC_TAX_NONE],
    'pay_dep_si' => ['label' => '給与：預り金（社会保険料の本人負担）', 'account' => '預り金', 'sub' => '社会保険料', 'tax' => ACC_TAX_NONE],
    'pay_dep_ei' => ['label' => '給与：預り金（雇用保険料）', 'account' => '預り金', 'sub' => '雇用保険料', 'tax' => ACC_TAX_NONE],
    'pay_dep_other' => ['label' => '給与：預り金（その他控除）', 'account' => '預り金', 'sub' => 'その他', 'tax' => ACC_TAX_NONE],
    'pay_bank' => ['label' => '給与支給日：振込（預金）', 'account' => '普通預金', 'sub' => '', 'tax' => ACC_TAX_NONE],
    'pay_cash' => ['label' => '給与支給日：現金', 'account' => '現金', 'sub' => '', 'tax' => ACC_TAX_NONE],
];

// ---------------------------------------------------------------------------
// 日付・金額・文字
// ---------------------------------------------------------------------------

function acc_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function acc_is_date(string $value): bool
{
    if (!preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $value, $m)) {
        return false;
    }
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}

function acc_is_month(string $value): bool
{
    return (bool) preg_match('/\A\d{4}-(0[1-9]|1[0-2])\z/', $value);
}

function acc_month_first_day(string $month): string
{
    return $month . '-01';
}

function acc_month_last_day(string $month): string
{
    [$y, $m] = array_map('intval', explode('-', $month));
    return sprintf('%04d-%02d-%02d', $y, $m, (int) date('t', mktime(0, 0, 0, $m, 1, $y)));
}

function acc_add_months(string $month, int $delta): string
{
    [$y, $m] = array_map('intval', explode('-', $month));
    $index = $y * 12 + ($m - 1) + $delta;
    return sprintf('%04d-%02d', intdiv($index, 12), $index % 12 + 1);
}

/** 'YYYY-MM-DD' → 弥生の和暦表記 'R.07/01/31'（令和より前は 'YYYY/MM/DD'） */
function acc_yayoi_date(string $ymd): string
{
    [$y, $m, $d] = array_map('intval', explode('-', $ymd));
    if ($ymd >= '2019-05-01') {
        return sprintf('R.%02d/%02d/%02d', $y - 2018, $m, $d);
    }
    return sprintf('%04d/%02d/%02d', $y, $m, $d);
}

/** 弥生の日付表記（R.07/01/31・H.31/04/30・2026/1/31・2026-01-31）→ 'YYYY-MM-DD'。解釈できなければ null */
function acc_parse_yayoi_date(string $text): ?string
{
    $text = trim($text);
    if (preg_match('/\A([RHS])\.?\s*(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{1,2})\z/u', $text, $m)) {
        $base = ['R' => 2018, 'H' => 1988, 'S' => 1925][$m[1]];
        $y = $base + (int) $m[2];
        $mo = (int) $m[3];
        $d = (int) $m[4];
    } elseif (preg_match('/\A(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})\z/', $text, $m)) {
        $y = (int) $m[1];
        $mo = (int) $m[2];
        $d = (int) $m[3];
    } else {
        return null;
    }
    return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
}

/** 内税10%の税額（税込金額 × 10 ÷ 110、円未満切捨て。負数は絶対値で計算して符号を戻す）。10%の課税区分以外は0 */
function acc_tax_amount(int $amountIncl, string $taxClass): int
{
    if (!preg_match('/\A(課税売上内|課対仕入内|共対仕入内|非対仕入内)10%/u', $taxClass)) {
        return 0;
    }
    $tax = intdiv(abs($amountIncl) * 10, 110);
    return $amountIncl < 0 ? -$tax : $tax;
}

/** 弥生に取り込む文字列を Shift-JIS（CP932）にする。変換できない文字は ? にする */
function acc_sjis(string $text): string
{
    $text = strtr($text, ['〜' => '～', '‖' => '∥', '−' => '－', '—' => '―', '–' => '－', '¢' => '￠', '£' => '￡', '¬' => '￢']);
    $previous = mb_substitute_character();
    mb_substitute_character(0x3F);
    $converted = mb_convert_encoding($text, 'CP932', 'UTF-8');
    mb_substitute_character($previous);
    return $converted;
}

/** Shift-JIS で $maxBytes バイト以内になるよう文字単位で切り詰める（UTF-8 のまま返す） */
function acc_trim_sjis_bytes(string $text, int $maxBytes): string
{
    $out = '';
    $bytes = 0;
    foreach (mb_str_split($text, 1, 'UTF-8') as $char) {
        $size = strlen(acc_sjis($char));
        if ($bytes + $size > $maxBytes) {
            break;
        }
        $out .= $char;
        $bytes += $size;
    }
    return $out;
}

/** CSVに入れる文字列から改行・タブ・制御文字を取り除く */
function acc_clean_text(?string $text): string
{
    if ($text === null) {
        return '';
    }
    $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? '';
    return trim($text);
}

// ---------------------------------------------------------------------------
// 仕訳（entry）の組み立て
//   entry = [key, date, desc, settle, debits[], credits[]]   line = [account, sub, tax_class, amount, tax, desc?]
// ---------------------------------------------------------------------------

function acc_line(string $account, ?string $sub, string $taxClass, int $amount, ?int $tax = null, string $desc = ''): array
{
    return [
        'account' => $account,
        'sub' => $sub === null || $sub === '' ? null : $sub,
        'tax_class' => $taxClass,
        'amount' => $amount,
        'tax' => $tax ?? acc_tax_amount($amount, $taxClass),
        'desc' => $desc,
    ];
}

function acc_entry(string $key, string $date, string $desc, array $debits, array $credits, bool $settle = false): array
{
    return [
        'key' => $key, 'date' => $date, 'desc' => $desc, 'settle' => $settle,
        'debits' => array_values($debits), 'credits' => array_values($credits),
    ];
}

/** 金額0の行を取り除く */
function acc_drop_zero_lines(array $lines): array
{
    return array_values(array_filter($lines, static fn (array $l): bool => (int) $l['amount'] !== 0));
}

/** 借方・貸方の合計 */
function acc_entry_totals(array $entry): array
{
    $debit = 0;
    $credit = 0;
    foreach ($entry['debits'] as $l) {
        $debit += (int) $l['amount'];
    }
    foreach ($entry['credits'] as $l) {
        $credit += (int) $l['amount'];
    }
    return ['debit' => $debit, 'credit' => $credit];
}

/**
 * 仕訳の検査。error は出力不可、warning は確認事項。
 *
 * @param array<string,true>|null $knownAccounts 弥生の勘定科目一覧（名称→true）。null なら科目名の存在確認はしない
 * @return array{errors:string[], warnings:string[]}
 */
function acc_entry_check(array $entry, ?array $knownAccounts): array
{
    $errors = [];
    $warnings = [];
    $t = acc_entry_totals($entry);
    if ($entry['debits'] === [] || $entry['credits'] === []) {
        $errors[] = '借方または貸方がありません。';
    }
    if ($t['debit'] !== $t['credit']) {
        $errors[] = '借方合計（' . number_format($t['debit']) . '）と貸方合計（' . number_format($t['credit']) . '）が一致しません。';
    }
    foreach (array_merge($entry['debits'], $entry['credits']) as $l) {
        if ($l['account'] === '') {
            $errors[] = '勘定科目が空の行があります。';
        } elseif ($knownAccounts !== null && !isset($knownAccounts[$l['account']])) {
            $warnings[] = '勘定科目「' . $l['account'] . '」が弥生の勘定科目一覧にありません。';
        }
        if (!in_array($l['tax_class'], ACC_TAX_CLASSES, true)) {
            $warnings[] = '税区分「' . $l['tax_class'] . '」は想定外の名称です（弥生の税区分名と一致するか確認）。';
        }
        if ($l['sub'] !== null && mb_strlen((string) $l['sub']) > ACC_SUB_MAX_CHARS) {
            $warnings[] = '補助科目「' . $l['sub'] . '」が' . ACC_SUB_MAX_CHARS . '文字を超えています（出力時に切り詰め）。';
        }
    }
    return ['errors' => array_values(array_unique($errors)), 'warnings' => array_values(array_unique($warnings))];
}

/** 出力済みかどうかの比較に使うハッシュ。日付・科目・補助・税区分・金額・税額・決算フラグだけを見る（摘要は含めない） */
function acc_entry_hash(array $entry): string
{
    $canon = static function (array $lines): array {
        $out = [];
        foreach ($lines as $l) {
            $out[] = [$l['account'], (string) ($l['sub'] ?? ''), $l['tax_class'], (int) $l['amount'], (int) $l['tax']];
        }
        return $out;
    };
    return sha1(json_encode([$entry['date'], (bool) $entry['settle'], $canon($entry['debits']), $canon($entry['credits'])], JSON_UNESCAPED_UNICODE));
}

/** 逆仕訳（借方と貸方を入れ替えただけの同額仕訳）。日付は $date（省略時は元の日付） */
function acc_reverse_entry(array $entry, ?string $date = null): array
{
    $copy = $entry;
    $copy['debits'] = $entry['credits'];
    $copy['credits'] = $entry['debits'];
    $copy['date'] = $date ?? $entry['date'];
    return $copy;
}

/** 弥生の仕訳メモ欄に入れる識別子（"SF:I12#1" / 逆仕訳は末尾に "-"） */
function acc_memo(string $key, int $revision, bool $reversal): string
{
    return ACC_MEMO_PREFIX . $key . '#' . $revision . ($reversal ? '-' : '');
}

/** 仕訳メモの識別子 → [key, revision, reversal]。識別子でなければ null */
function acc_parse_memo(string $memo): ?array
{
    if (!preg_match('/\A' . preg_quote(ACC_MEMO_PREFIX, '/') . '([A-Za-z0-9.\-_]+?)#(\d+)(-?)\z/', trim($memo), $m)) {
        return null;
    }
    return ['key' => $m[1], 'revision' => (int) $m[2], 'reversal' => $m[3] === '-'];
}

// ---------------------------------------------------------------------------
// 弥生インポート形式（25列）への変換
// ---------------------------------------------------------------------------

/** 数値で出す列（0始まりの列番号）。それ以外は文字列として "…" で囲む */
const ACC_NUMERIC_COLUMNS = [1, 8, 9, 14, 15, 19];

/**
 * 1仕訳を25列の行にする。借方1行・貸方1行なら1行（識別フラグ2000・タイプ0）。
 * それ以外（複合仕訳）は、借方の行を上から順に、続けて貸方の行を並べ、片側だけを埋める（2110 / 2100 / 2101・タイプ3）。
 * 複合仕訳の各行の摘要は「仕訳の摘要＋行の見出し（源泉所得税など）」にする。
 *
 * @return list<list<int|string>>
 */
function acc_entry_rows(array $entry, int $voucherNo, string $memo): array
{
    $blank = ['account' => '', 'sub' => null, 'tax_class' => ACC_TAX_NONE, 'amount' => 0, 'tax' => 0, 'desc' => ''];
    $slots = [];
    if (count($entry['debits']) === 1 && count($entry['credits']) === 1) {
        $slots[] = [$entry['debits'][0], $entry['credits'][0]];
    } else {
        foreach ($entry['debits'] as $d) {
            $slots[] = [$d, $blank];
        }
        foreach ($entry['credits'] as $c) {
            $slots[] = [$blank, $c];
        }
    }
    $count = count($slots);
    $rows = [];
    foreach ($slots as $i => [$d, $c]) {
        if ($count === 1) {
            $flag = '2000';
        } elseif ($i === 0) {
            $flag = '2110';
        } elseif ($i === $count - 1) {
            $flag = '2101';
        } else {
            $flag = '2100';
        }
        $label = $d['desc'] !== '' ? $d['desc'] : $c['desc'];
        $summary = $label !== '' && $count > 1 ? trim($entry['desc'] . ' ' . $label) : $entry['desc'];
        $rows[] = [
            $flag,
            $voucherNo,
            $entry['settle'] ? '本期' : '',
            acc_yayoi_date($entry['date']),
            $d['account'],
            mb_substr((string) ($d['sub'] ?? ''), 0, ACC_SUB_MAX_CHARS),
            '',
            $d['tax_class'],
            (int) $d['amount'],
            (int) $d['tax'],
            $c['account'],
            mb_substr((string) ($c['sub'] ?? ''), 0, ACC_SUB_MAX_CHARS),
            '',
            $c['tax_class'],
            (int) $c['amount'],
            (int) $c['tax'],
            acc_trim_sjis_bytes(acc_clean_text($summary), ACC_SUMMARY_MAX_BYTES),
            '',
            '',
            $count === 1 ? 0 : 3,
            '',
            $memo,
            '0',
            '0',
            'no',
        ];
    }
    return $rows;
}

/** 25列の行を UTF-8 の CSV 文字列にする（区切りはカンマ、改行は CRLF） */
function acc_rows_to_csv(array $rows): string
{
    $out = '';
    foreach ($rows as $row) {
        $cells = [];
        foreach ($row as $i => $value) {
            if (in_array($i, ACC_NUMERIC_COLUMNS, true)) {
                $cells[] = (string) (int) $value;
            } else {
                $cells[] = '"' . str_replace('"', '""', acc_clean_text((string) $value)) . '"';
            }
        }
        $out .= implode(',', $cells) . "\r\n";
    }
    return $out;
}

function acc_csv_to_sjis(string $csv): string
{
    return acc_sjis($csv);
}

// ---------------------------------------------------------------------------
// 弥生の出力ファイルの読み取り（勘定科目一覧・仕訳日記帳）
// ---------------------------------------------------------------------------

/** Shift-JIS（弥生の標準）または UTF-8 のバイト列を UTF-8 の文字列にする */
function acc_decode_text(string $bytes): string
{
    if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
        $bytes = substr($bytes, 3);
    }
    if (mb_check_encoding($bytes, 'UTF-8') && preg_match('/[\x80-\xFF]/', $bytes) === 1) {
        return $bytes;
    }
    return mb_convert_encoding($bytes, 'UTF-8', 'CP932');
}

/** CSV文字列を行の配列にする（フィールド内の改行・"" に対応） */
function acc_parse_csv(string $text): array
{
    $rows = [];
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $text);
    rewind($stream);
    while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
        if ($row === [null]) {
            continue;
        }
        $rows[] = array_map(static fn ($v): string => (string) $v, $row);
    }
    fclose($stream);
    return $rows;
}

/**
 * 弥生の「勘定科目一覧」（汎用形式）→ 科目の配列。
 * 明細行（先頭が [明細行]）の4〜6列目のうち、[ ] で囲まれていない最初の空でない値が科目名。
 *
 * @return list<array{name:string, code:?string, side:?string, tax_class:?string, hidden:int}>
 */
function acc_parse_account_list(string $bytes): array
{
    $accounts = [];
    foreach (acc_parse_csv(acc_decode_text($bytes)) as $row) {
        if (($row[0] ?? '') !== '[明細行]') {
            continue;
        }
        $name = '';
        for ($i = 4; $i <= 6; $i++) {
            $cell = trim($row[$i] ?? '');
            if ($cell !== '' && !preg_match('/\A\[.*\]\z/u', $cell)) {
                $name = $cell;
                break;
            }
        }
        if ($name === '') {
            continue;
        }
        $side = trim($row[10] ?? '');
        $accounts[$name] = [
            'name' => $name,
            'code' => ($row[8] ?? '') !== '' ? trim($row[8]) : null,
            'side' => $side !== '' ? trim($side, '[]') : null,
            'tax_class' => ($row[11] ?? '') !== '' ? trim($row[11]) : null,
            'hidden' => strtolower(trim($row[19] ?? '')) === 'yes' ? 1 : 0,
        ];
    }
    return array_values($accounts);
}

/**
 * 弥生の仕訳日記帳（インポート形式・25列）→ 仕訳の配列。複合仕訳（2110/2100/2101）は1仕訳にまとめる。
 *
 * @return list<array{date:?string, debits:array, credits:array, memo:string, desc:string, settle:string}>
 */
function acc_parse_journal(string $bytes): array
{
    $entries = [];
    $current = null;
    foreach (acc_parse_csv(acc_decode_text($bytes)) as $row) {
        if (count($row) < 17 || !preg_match('/\A2[01]\d\d\z/', $row[0])) {
            continue;
        }
        $flag = $row[0];
        if ($flag === '2000' || $flag === '2111' || $flag === '2110') {
            if ($current !== null) {
                $entries[] = $current;
            }
            $current = ['date' => acc_parse_yayoi_date($row[3]), 'debits' => [], 'credits' => [], 'memo' => '', 'desc' => $row[16], 'settle' => $row[2]];
        } elseif ($current === null) {
            $current = ['date' => acc_parse_yayoi_date($row[3]), 'debits' => [], 'credits' => [], 'memo' => '', 'desc' => $row[16], 'settle' => $row[2]];
        }
        if ($row[4] !== '' || (int) $row[8] !== 0) {
            $current['debits'][] = ['account' => $row[4], 'sub' => $row[5] !== '' ? $row[5] : null, 'amount' => (int) $row[8], 'tax' => (int) $row[9], 'tax_class' => $row[7]];
        }
        if ($row[10] !== '' || (int) $row[14] !== 0) {
            $current['credits'][] = ['account' => $row[10], 'sub' => $row[11] !== '' ? $row[11] : null, 'amount' => (int) $row[14], 'tax' => (int) $row[15], 'tax_class' => $row[13]];
        }
        if (($row[21] ?? '') !== '' && $current['memo'] === '') {
            $current['memo'] = (string) $row[21];
        }
        if ($flag === '2000' || $flag === '2111' || $flag === '2101') {
            $entries[] = $current;
            $current = null;
        }
    }
    if ($current !== null) {
        $entries[] = $current;
    }
    return $entries;
}

// ---------------------------------------------------------------------------
// 仕訳設定（マッピング）
// ---------------------------------------------------------------------------

/**
 * 帳簿の仕訳設定。保存が無い項目は初期値で補う。
 *
 * @return array<string, array{label:string, account:string, sub:string, tax:string, is_default:bool}>
 */
function acc_fetch_map(PDO $pdo, int $bookId): array
{
    $map = [];
    foreach (ACC_DEFAULT_MAP as $key => $d) {
        $map[$key] = $d + ['is_default' => true];
        $map[$key]['sub'] = (string) $d['sub'];
    }
    $stmt = $pdo->prepare('SELECT event_key, account, sub, tax_class FROM acc_map WHERE book_id = :b');
    $stmt->execute([':b' => $bookId]);
    foreach ($stmt->fetchAll() as $row) {
        if (isset($map[$row['event_key']])) {
            $map[$row['event_key']]['account'] = (string) $row['account'];
            $map[$row['event_key']]['sub'] = (string) ($row['sub'] ?? '');
            $map[$row['event_key']]['tax'] = (string) $row['tax_class'];
            $map[$row['event_key']]['is_default'] = false;
        }
    }
    return $map;
}

/** マッピングから1行分（借方または貸方）を作る。補助科目の {client} {employee} を置き換える */
function acc_map_line(array $map, string $eventKey, int $amount, array $vars = [], ?int $tax = null, string $desc = ''): array
{
    $m = $map[$eventKey];
    $sub = strtr($m['sub'], array_map(static fn ($v): string => (string) $v, ['{client}' => $vars['client'] ?? '', '{employee}' => $vars['employee'] ?? '']));
    return acc_line($m['account'], $sub, $m['tax'], $amount, $tax, $desc);
}

/**
 * 使える勘定科目の名称（名称→true）。勘定科目マスタ（acc_chart）と、取り込んだ弥生の科目一覧（acc_accounts）の合算。
 * どちらも空なら null（科目名の存在確認をしない）。
 */
function acc_known_accounts(PDO $pdo, int $bookId): ?array
{
    $names = [];
    try {
        if (function_exists('acc_chart_ensure')) {
            $book = acc_fetch_book($pdo, $bookId);
            if ($book !== null) {
                acc_chart_ensure($pdo, $book);
            }
        }
        $stmt = $pdo->prepare('SELECT name FROM acc_chart WHERE book_id = :b AND is_active = 1');
        $stmt->execute([':b' => $bookId]);
        $names = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        // 帳簿機能のテーブルが未作成（acc_ledger_tables.sql 未実行）の間は弥生の一覧だけを使う
    }
    $stmt = $pdo->prepare('SELECT name FROM acc_accounts WHERE book_id = :b');
    $stmt->execute([':b' => $bookId]);
    $names = array_merge($names, $stmt->fetchAll(PDO::FETCH_COLUMN));
    return $names === [] ? null : array_fill_keys($names, true);
}

// ---------------------------------------------------------------------------
// 仕訳の生成（請求書・給与・定型仕訳・手入力）
// ---------------------------------------------------------------------------

/**
 * 発行済みの請求書1通 → 売上仕訳。借方 売掛金（補助=請求先）／貸方 売上、税込金額、税額は請求書の消費税。
 *
 * @return array{entry:?array, problems:string[]}
 */
function acc_invoice_entry(array $invoice, array $lines, array $client, array $map): array
{
    $problems = [];
    $total = 0;
    foreach ($lines as $l) {
        if ((float) $l['tax_rate'] !== 10.0) {
            $problems[] = '請求書No.' . inv_format_no($invoice['invoice_no'] === null ? null : (int) $invoice['invoice_no']) . 'に消費税率10%以外の明細があります（未対応）。';
        }
        $total += (int) ($l['amount'] ?? 0);
    }
    if ($problems !== []) {
        return ['entry' => null, 'problems' => $problems];
    }
    if ($total !== (int) $invoice['total_incl']) {
        return ['entry' => null, 'problems' => ['請求書No.' . inv_format_no((int) $invoice['invoice_no']) . 'の明細合計と請求額が一致しません。']];
    }
    if ($total === 0) {
        return ['entry' => null, 'problems' => []];
    }
    $abs = abs($total);
    $tax = abs((int) $invoice['tax']);
    $label = substr((string) $invoice['billing_month'], 0, 4) . '年' . (int) substr((string) $invoice['billing_month'], 5, 2) . '月分洗濯代行 No.' . inv_format_no((int) $invoice['invoice_no']);
    $desc = $label . ' ' . $client['name'];
    $vars = ['client' => $client['name']];
    $receivable = acc_map_line($map, 'inv_receivable', $abs, $vars, 0);
    $sales = acc_map_line($map, 'inv_sales', $abs, $vars, $tax);
    $entry = $total > 0
        ? acc_entry('I' . $invoice['id'], (string) $invoice['issue_date'], $desc, [$receivable], [$sales])
        : acc_entry('I' . $invoice['id'], (string) $invoice['issue_date'], $desc . '（訂正）', [$sales], [$receivable]);
    return ['entry' => $entry, 'problems' => []];
}

/**
 * 給与明細1人分 → 計上仕訳（勤務期間の末日付け）。
 *   借方: 給料手当（または役員報酬）＝総支給額−通勤手当・駐車場代／旅費交通費＝通勤手当＋駐車場代／法定福利費＝社会保険料の会社負担分
 *   貸方: 預り金（源泉所得税・住民税・社会保険料・雇用保険料・その他控除）／未払金＝差引支給額／未払費用＝社会保険料の会社負担分
 * 社会保険料の会社負担分は本人負担と同額（労使折半）として計上する。納付額と数円違う場合は、納付時に調整する。
 *
 * @return array{entry:?array, problems:string[]}
 */
function acc_payslip_entry(array $run, array $slip, string $employeeName, array $map): array
{
    $isOfficer = ($slip['employment_type'] ?? 'employee') === 'officer';
    $commute = $isOfficer ? 0 : (int) $slip['commute_total'] + (int) $slip['parking_total'];
    $wage = (int) $slip['gross_total'] - $commute;
    $siEmployee = pay_si_total($slip);
    $siEmployer = $siEmployee;
    $name = acc_clean_text($employeeName);
    $vars = ['employee' => $name];
    $problems = [];
    if ($wage < 0) {
        $problems[] = $name . '：通勤手当・駐車場代が総支給額を超えています。';
    }
    $debits = acc_drop_zero_lines([
        acc_map_line($map, $isOfficer ? 'pay_officer' : 'pay_wage', $wage, $vars, null, $isOfficer ? '役員報酬' : '給与・手当'),
        acc_map_line($map, 'pay_commute', $commute, $vars, null, '通勤手当'),
        acc_map_line($map, 'pay_si_expense', $siEmployer, $vars, null, '社会保険料（会社負担）'),
    ]);
    $credits = acc_drop_zero_lines([
        acc_map_line($map, 'pay_dep_tax', (int) $slip['withholding_tax'], $vars, null, '源泉所得税'),
        acc_map_line($map, 'pay_dep_resident', (int) $slip['resident_tax'], $vars, null, '住民税'),
        acc_map_line($map, 'pay_dep_si', $siEmployee, $vars, null, '社会保険料（本人負担）'),
        acc_map_line($map, 'pay_dep_ei', (int) $slip['emp_insurance'], $vars, null, '雇用保険料'),
        acc_map_line($map, 'pay_dep_other', (int) $slip['other_deduction'], $vars, null, (string) ($slip['other_deduction_label'] ?: 'その他控除')),
        acc_map_line($map, 'pay_unpaid', (int) $slip['net_pay'], $vars, null, '差引支給額'),
        acc_map_line($map, 'pay_si_payable', $siEmployer, $vars, null, '社会保険料（会社負担）'),
    ]);
    if ($debits === [] && $credits === []) {
        return ['entry' => null, 'problems' => $problems];
    }
    $month = (string) $run['work_month'];
    $desc = substr($month, 0, 4) . '年' . (int) substr($month, 5, 2) . '月分給与 ' . $name;
    $entry = acc_entry('P' . $run['id'] . '.' . $slip['employee_id'] . 'a', (string) $run['period_end'], $desc, $debits, $credits);
    return ['entry' => $entry, 'problems' => $problems];
}

/**
 * 給与の支給日の仕訳（支給方法ごとに1仕訳）。借方 未払金（補助=氏名、人数分）／貸方 普通預金（振込）または現金。
 *
 * @param list<array{name:string, net_pay:int, method:string}> $payments
 * @return list<array>
 */
function acc_payment_entries(array $run, array $payments, array $map): array
{
    $entries = [];
    foreach (['bank' => ['b', 'pay_bank', '振込'], 'cash' => ['c', 'pay_cash', '現金']] as $method => [$suffix, $eventKey, $label]) {
        $debits = [];
        $total = 0;
        foreach ($payments as $p) {
            if ($p['method'] !== $method || (int) $p['net_pay'] <= 0) {
                continue;
            }
            $debits[] = acc_map_line($map, 'pay_unpaid', (int) $p['net_pay'], ['employee' => acc_clean_text($p['name'])], null, acc_clean_text($p['name']));
            $total += (int) $p['net_pay'];
        }
        if ($total === 0) {
            continue;
        }
        $month = (string) $run['work_month'];
        $desc = substr($month, 0, 4) . '年' . (int) substr($month, 5, 2) . '月分給与支給（' . $label . '）';
        $entries[] = acc_entry('P' . $run['id'] . $suffix, (string) $run['pay_date'], $desc, $debits, [acc_map_line($map, $eventKey, $total)]);
    }
    return $entries;
}

/**
 * 定型仕訳（毎月の概算計上など）を $from〜$to（YYYY-MM）の分だけ展開する。
 * 戻し（reverse_rule）:
 *   next_month … 計上の翌月末日付けで同額を逆仕訳
 *   fy_start   … 翌事業年度の期首月の末日付けで、前事業年度に計上した合計額を逆仕訳（概算→確定の入れ替え用）
 *
 * @return list<array>
 */
function acc_expand_template(array $tpl, int $fiscalStartMonth, string $from, string $to): array
{
    $months = [];
    for ($m = $from; $m <= $to; $m = acc_add_months($m, 1)) {
        $months[] = $m;
    }
    $monthSet = $tpl['months'] === 'all' || $tpl['months'] === '' ? range(1, 12) : array_map('intval', explode(',', (string) $tpl['months']));
    $amount = (int) $tpl['amount'];
    $isScheduled = static function (string $m) use ($tpl, $monthSet): bool {
        if ($tpl['from_month'] !== null && $tpl['from_month'] !== '' && $m < $tpl['from_month']) {
            return false;
        }
        if ($tpl['to_month'] !== null && $tpl['to_month'] !== '' && $m > $tpl['to_month']) {
            return false;
        }
        return in_array((int) substr($m, 5, 2), $monthSet, true);
    };
    $dateIn = static function (string $m) use ($tpl): string {
        if ($tpl['day_rule'] === 'last') {
            return acc_month_last_day($m);
        }
        $last = (int) substr(acc_month_last_day($m), 8, 2);
        return $m . '-' . sprintf('%02d', min((int) $tpl['day_rule'], $last));
    };
    $make = static function (string $key, string $date, string $suffixDesc, int $amt, bool $reverse) use ($tpl): array {
        $debit = acc_line((string) $tpl['debit_account'], $tpl['debit_sub'], (string) $tpl['debit_tax'], $amt);
        $credit = acc_line((string) $tpl['credit_account'], $tpl['credit_sub'], (string) $tpl['credit_tax'], $amt);
        $desc = trim((string) ($tpl['description'] !== null && $tpl['description'] !== '' ? $tpl['description'] : $tpl['name']) . $suffixDesc);
        return $reverse
            ? acc_entry($key, $date, $desc, [$credit], [$debit], (bool) $tpl['is_settlement'])
            : acc_entry($key, $date, $desc, [$debit], [$credit], (bool) $tpl['is_settlement']);
    };

    $entries = [];
    $id = (string) $tpl['id'];
    foreach ($months as $m) {
        if ($isScheduled($m) && $amount !== 0) {
            $entries[] = $make('T' . $id . '.' . str_replace('-', '', $m), $dateIn($m), '', $amount, false);
        }
        if ($tpl['reverse_rule'] === 'next_month') {
            $prev = acc_add_months($m, -1);
            if ($isScheduled($prev) && $amount !== 0) {
                $entries[] = $make('T' . $id . '.' . str_replace('-', '', $prev) . 'x', acc_month_last_day($m), '（戻し）', $amount, true);
            }
        }
        if ($tpl['reverse_rule'] === 'fy_start' && (int) substr($m, 5, 2) === $fiscalStartMonth) {
            // 前事業年度（期首月の12か月前〜1か月前）に計上した合計額を戻す
            $sum = 0;
            for ($k = 12; $k >= 1; $k--) {
                if ($isScheduled(acc_add_months($m, -$k))) {
                    $sum += $amount;
                }
            }
            if ($sum !== 0) {
                $entries[] = $make('T' . $id . '.' . str_replace('-', '', $m) . 'x', acc_month_last_day($m), '（前期概算の戻し）', $sum, true);
            }
        }
    }
    return $entries;
}

/** 手入力の1行 → 仕訳 */
function acc_manual_entry(array $row): array
{
    $amount = (int) $row['amount'];
    $debit = acc_line((string) $row['debit_account'], $row['debit_sub'], (string) $row['debit_tax'], $amount, $row['debit_tax_amount'] === null ? null : (int) $row['debit_tax_amount']);
    $credit = acc_line((string) $row['credit_account'], $row['credit_sub'], (string) $row['credit_tax'], $amount, $row['credit_tax_amount'] === null ? null : (int) $row['credit_tax_amount']);
    return acc_entry('M' . $row['id'], (string) $row['entry_date'], (string) $row['description'], [$debit], [$credit], (bool) $row['is_settlement']);
}

/**
 * 帳簿の仕訳をすべて作る（期間で絞る前の全件）。キー → 仕訳。
 *
 * @return array{entries:array<string,array>, problems:string[], warnings:string[]}
 */
function acc_generate_all(PDO $pdo, array $book, ?string $toMonth = null): array
{
    $bookId = (int) $book['id'];
    $map = acc_fetch_map($pdo, $bookId);
    $known = acc_known_accounts($pdo, $bookId);
    $entries = [];
    $problems = [];
    $warnings = [];
    $add = static function (array $entry) use (&$entries, &$problems, &$warnings, $known): void {
        $check = acc_entry_check($entry, $known);
        foreach ($check['errors'] as $e) {
            $problems[] = '[' . $entry['key'] . '] ' . $entry['desc'] . '：' . $e;
        }
        foreach ($check['warnings'] as $w) {
            $warnings[] = $w;
        }
        $entries[$entry['key']] = $entry;
    };

    if ((int) $book['use_invoice'] === 1) {
        $invoices = $pdo->query("SELECT * FROM inv_invoices WHERE status = 'issued' ORDER BY issue_date, id")->fetchAll();
        foreach ($invoices as $invoice) {
            $client = inv_fetch_client($pdo, (int) $invoice['client_id']);
            if ($client === null) {
                $problems[] = '請求書No.' . inv_format_no((int) $invoice['invoice_no']) . 'の請求先が見つかりません。';
                continue;
            }
            $snapshot = json_decode((string) $invoice['client_snapshot'], true);
            if (is_array($snapshot) && !empty($snapshot['name'])) {
                $client['name'] = (string) $snapshot['name'];
            }
            $result = acc_invoice_entry($invoice, inv_fetch_lines($pdo, (int) $invoice['id']), $client, $map);
            array_push($problems, ...$result['problems']);
            if ($result['entry'] !== null) {
                $add($result['entry']);
            }
        }
    }

    if ((int) $book['use_payroll'] === 1) {
        $runs = $pdo->query("SELECT * FROM pay_runs WHERE status = 'closed' ORDER BY pay_date, id")->fetchAll();
        $slipStmt = $pdo->prepare('SELECT s.*, e.name AS current_name FROM pay_slips s LEFT JOIN employees e ON e.id = s.employee_id WHERE s.run_id = :r ORDER BY s.employee_id');
        foreach ($runs as $run) {
            $slipStmt->execute([':r' => $run['id']]);
            $payments = [];
            foreach ($slipStmt->fetchAll() as $slip) {
                $snapshot = json_decode((string) $slip['employee_snapshot'], true) ?: [];
                $name = (string) ($snapshot['name'] ?? $slip['current_name'] ?? ('従業員' . $slip['employee_id']));
                $result = acc_payslip_entry($run, $slip, $name, $map);
                array_push($problems, ...$result['problems']);
                if ($result['entry'] !== null) {
                    $add($result['entry']);
                }
                $payments[] = ['name' => $name, 'net_pay' => (int) $slip['net_pay'], 'method' => ($snapshot['payment_method'] ?? 'cash') === 'bank' ? 'bank' : 'cash'];
            }
            foreach (acc_payment_entries($run, $payments, $map) as $entry) {
                $add($entry);
            }
        }
    }

    $fiscalStart = max(1, min(12, (int) $book['fiscal_start_month']));
    $templates = $pdo->prepare('SELECT * FROM acc_templates WHERE book_id = :b AND is_active = 1 ORDER BY id');
    $templates->execute([':b' => $bookId]);
    $endMonth = $toMonth ?? date('Y-m');
    foreach ($templates->fetchAll() as $tpl) {
        $startMonth = (string) $tpl['from_month'];
        if (!acc_is_month($startMonth) || $startMonth > $endMonth) {
            continue;
        }
        $limit = $tpl['to_month'] !== null && $tpl['to_month'] !== '' ? min((string) $tpl['to_month'], acc_add_months($endMonth, 0)) : $endMonth;
        $stop = $tpl['reverse_rule'] === 'none' ? $limit : acc_add_months($limit, 12);
        foreach (acc_expand_template($tpl, $fiscalStart, $startMonth, $stop) as $entry) {
            $add($entry);
        }
    }

    $manual = $pdo->prepare('SELECT * FROM acc_manual WHERE book_id = :b ORDER BY entry_date, id');
    $manual->execute([':b' => $bookId]);
    foreach ($manual->fetchAll() as $row) {
        $add(acc_manual_entry($row));
    }

    if (function_exists('acc_asset_entries')) { // 固定資産台帳の減価償却・売却除却（includes/accounting_assets.php）
        foreach (acc_asset_entries($pdo, $book, $endMonth, $entries) as $entry) {
            $add($entry);
        }
    }

    if (function_exists('acc_tax_entries')) { // 年末の消費税・法人税等の計上（設定で有効にした事業年度のみ。includes/accounting_corptax.php）
        foreach (acc_tax_entries($pdo, $book, $endMonth, $entries) as $entry) {
            $add($entry);
        }
    }

    return ['entries' => $entries, 'problems' => array_values(array_unique($problems)), 'warnings' => array_values(array_unique($warnings))];
}

// ---------------------------------------------------------------------------
// 出力済みの管理（二重出力の防止・訂正・取消）
// ---------------------------------------------------------------------------

/**
 * 出力済みで、まだ取り消されていない仕訳の最新版。キー → [revision, hash, entry]
 *
 * @return array<string, array{revision:int, hash:string, entry:array}>
 */
function acc_export_state(PDO $pdo, int $bookId): array
{
    $stmt = $pdo->prepare('SELECT entry_key, revision, is_reversal, row_hash, entry_json FROM acc_export_items WHERE book_id = :b ORDER BY entry_key, revision, is_reversal');
    $stmt->execute([':b' => $bookId]);
    $state = [];
    $maxRevision = [];
    foreach ($stmt->fetchAll() as $row) {
        $key = (string) $row['entry_key'];
        $rev = (int) $row['revision'];
        $maxRevision[$key] = max($maxRevision[$key] ?? 0, $rev);
        if ((int) $row['is_reversal'] === 1) {
            if (isset($state[$key]) && $state[$key]['revision'] === $rev) {
                unset($state[$key]);
            }
            continue;
        }
        $state[$key] = ['revision' => $rev, 'hash' => (string) $row['row_hash'], 'entry' => json_decode((string) $row['entry_json'], true) ?: []];
    }
    foreach ($state as $key => $_) {
        $state[$key]['max_revision'] = $maxRevision[$key];
    }
    $state['__max'] = $maxRevision; // キーごとの使用済み最大版数（取消済みを含む）
    return $state;
}

/**
 * 出力計画。期間（YYYY-MM〜YYYY-MM）の仕訳について、新規・変更・取消と、出力する行の元になる仕訳の並びを返す。
 *
 * mode:
 *  - 'new' … 未出力の仕訳と、出力後に内容が変わった仕訳（逆仕訳＋新仕訳）と、元データが無くなった仕訳（逆仕訳）だけ
 *  - 'all' … 上に加えて、出力済みで変わっていない仕訳も再出力（弥生側のデータを消して取り込み直すとき用）
 *
 * @return array{items:list<array>, problems:string[], warnings:string[], counts:array<string,int>}
 */
function acc_plan(PDO $pdo, array $book, string $fromMonth, string $toMonth, string $mode): array
{
    $generated = acc_generate_all($pdo, $book, $toMonth);
    $state = acc_export_state($pdo, (int) $book['id']);
    $maxRevision = $state['__max'];
    unset($state['__max']);
    $first = acc_month_first_day($fromMonth);
    $last = acc_month_last_day($toMonth);
    $items = [];
    $counts = ['new' => 0, 'changed' => 0, 'same' => 0, 'orphan' => 0];

    foreach ($generated['entries'] as $key => $entry) {
        if ($entry['date'] < $first || $entry['date'] > $last) {
            continue;
        }
        $hash = acc_entry_hash($entry);
        if (!isset($state[$key])) {
            $rev = ($maxRevision[$key] ?? 0) + 1;
            $items[] = ['action' => 'new', 'entry' => $entry, 'key' => $key, 'revision' => $rev, 'reversal' => false, 'hash' => $hash];
            $counts['new']++;
        } elseif ($state[$key]['hash'] === $hash) {
            $counts['same']++;
            if ($mode === 'all') {
                $items[] = ['action' => 'same', 'entry' => $entry, 'key' => $key, 'revision' => $state[$key]['revision'], 'reversal' => false, 'hash' => $hash];
            }
        } else {
            $old = $state[$key];
            $items[] = ['action' => 'changed_reverse', 'entry' => acc_reverse_entry($old['entry']), 'key' => $key, 'revision' => $old['revision'], 'reversal' => true, 'hash' => $old['hash']];
            $items[] = ['action' => 'changed_new', 'entry' => $entry, 'key' => $key, 'revision' => $maxRevision[$key] + 1, 'reversal' => false, 'hash' => $hash];
            $counts['changed']++;
        }
    }
    foreach ($state as $key => $old) {
        if (isset($generated['entries'][$key])) {
            continue;
        }
        $date = (string) ($old['entry']['date'] ?? '');
        if ($date < $first || $date > $last) {
            continue;
        }
        $items[] = ['action' => 'orphan', 'entry' => acc_reverse_entry($old['entry']), 'key' => $key, 'revision' => $old['revision'], 'reversal' => true, 'hash' => $old['hash']];
        $counts['orphan']++;
    }
    usort($items, static fn (array $a, array $b): int => [$a['entry']['date'], $a['key'], $a['reversal'] ? 0 : 1] <=> [$b['entry']['date'], $b['key'], $b['reversal'] ? 0 : 1]);
    return ['items' => $items, 'problems' => $generated['problems'], 'warnings' => $generated['warnings'], 'counts' => $counts];
}

/** 計画 → 弥生の25列の行（伝票番号は $voucherStart から連番） */
function acc_plan_rows(array $items, int $voucherStart): array
{
    $rows = [];
    $no = $voucherStart;
    foreach ($items as $item) {
        foreach (acc_entry_rows($item['entry'], $no, acc_memo($item['key'], $item['revision'], $item['reversal'])) as $row) {
            $rows[] = $row;
        }
        $no++;
    }
    return $rows;
}

/** 出力を記録する（新規・変更後の仕訳は出力済みに、逆仕訳は元の版を取消済みにする） */
function acc_record_export(PDO $pdo, array $book, string $fromMonth, string $toMonth, string $mode, int $voucherStart, array $items, string $fileName, int $userId): int
{
    $pdo->beginTransaction();
    try {
        $totalDebit = 0;
        foreach ($items as $item) {
            $totalDebit += acc_entry_totals($item['entry'])['debit'];
        }
        $pdo->prepare(
            'INSERT INTO acc_exports (book_id, period_from, period_to, mode, voucher_start, entry_count, total_debit, file_name, created_by, created_at)
             VALUES (:b, :f, :t, :m, :v, :c, :d, :n, :u, NOW())'
        )->execute([':b' => $book['id'], ':f' => $fromMonth, ':t' => $toMonth, ':m' => $mode, ':v' => $voucherStart, ':c' => count($items), ':d' => $totalDebit, ':n' => $fileName, ':u' => $userId]);
        $exportId = (int) $pdo->lastInsertId();
        $insert = $pdo->prepare(
            'INSERT INTO acc_export_items (book_id, entry_key, revision, is_reversal, row_hash, entry_json, export_id)
             VALUES (:b, :k, :r, :rev, :h, :j, :e)
             ON DUPLICATE KEY UPDATE export_id = VALUES(export_id)'
        );
        foreach ($items as $item) {
            $stored = $item['reversal'] ? acc_reverse_entry($item['entry']) : $item['entry']; // 逆仕訳は元の向きで保存する
            $insert->execute([
                ':b' => $book['id'], ':k' => $item['key'], ':r' => $item['revision'], ':rev' => $item['reversal'] ? 1 : 0,
                ':h' => $item['hash'], ':j' => json_encode($stored, JSON_UNESCAPED_UNICODE), ':e' => $exportId,
            ]);
        }
        $pdo->commit();
        return $exportId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ---------------------------------------------------------------------------
// 照合（弥生の仕訳日記帳 ⇔ シフトシステムの仕訳）
// ---------------------------------------------------------------------------

/**
 * 仕訳 → 科目（補助科目）ごとの正味の増減。借方を +、貸方を − にして足す。
 * 逆仕訳（借方と貸方を入れ替えた仕訳）はそのまま足せば元の仕訳と打ち消し合う。
 */
function acc_entry_vector(array $entry): array
{
    $v = [];
    foreach ($entry['debits'] as $l) {
        if ((int) $l['amount'] !== 0) {
            $k = $l['account'] . '|' . (string) ($l['sub'] ?? '');
            $v[$k] = ($v[$k] ?? 0) + (int) $l['amount'];
        }
    }
    foreach ($entry['credits'] as $l) {
        if ((int) $l['amount'] !== 0) {
            $k = $l['account'] . '|' . (string) ($l['sub'] ?? '');
            $v[$k] = ($v[$k] ?? 0) - (int) $l['amount'];
        }
    }
    return array_filter($v, static fn (int $n): bool => $n !== 0);
}

function acc_vector_text(array $vector): string
{
    $parts = [];
    ksort($vector);
    foreach ($vector as $k => $n) {
        [$account, $sub] = explode('|', $k, 2);
        $parts[] = ($n > 0 ? '借 ' : '貸 ') . $account . ($sub !== '' ? '（' . $sub . '）' : '') . ' ' . number_format(abs($n));
    }
    return implode(' / ', $parts);
}

/**
 * 照合。弥生側は仕訳メモの識別子（SF:キー#版数）でシステムの仕訳と対応づけ、版数違い・逆仕訳は差し引いた正味で比べる。
 * 識別子の無い弥生の仕訳は比較しない（件数だけ報告し、同日・同額・同じ科目の候補があれば挙げる）。
 *
 * @param array<string,array> $generated キー → 仕訳（期間で絞り込み済み）
 * @param list<array>         $imported  acc_parse_journal() の結果（期間で絞り込み済み）
 * @return array{rows:list<array>, summary:array<string,int>, unmarked:int}
 */
function acc_reconcile(array $generated, array $imported): array
{
    $net = [];      // key => ['vector'=>[], 'date'=>?, 'count'=>int]
    $unmarked = [];
    foreach ($imported as $e) {
        $memo = acc_parse_memo($e['memo']);
        $entryLike = ['debits' => $e['debits'], 'credits' => $e['credits']];
        if ($memo === null) {
            $unmarked[] = $e;
            continue;
        }
        $key = $memo['key'];
        $net[$key] ??= ['vector' => [], 'date' => null, 'count' => 0];
        foreach (acc_entry_vector($entryLike) as $k => $n) {
            $net[$key]['vector'][$k] = ($net[$key]['vector'][$k] ?? 0) + $n;
        }
        if (!$memo['reversal']) {
            $net[$key]['date'] = $e['date'];
        }
        $net[$key]['count']++;
    }
    foreach ($net as $key => $info) {
        $net[$key]['vector'] = array_filter($info['vector'], static fn (int $n): bool => $n !== 0);
    }

    $rows = [];
    $summary = ['ok' => 0, 'diff' => 0, 'missing' => 0, 'extra' => 0, 'candidate' => 0];
    $usedUnmarked = [];
    foreach ($generated as $key => $entry) {
        $expected = acc_entry_vector($entry);
        $found = $net[$key] ?? null;
        if ($found !== null && $found['vector'] !== [] ) {
            $same = $found['vector'] == $expected && $found['date'] === $entry['date'];
            if ($same) {
                $summary['ok']++;
                $rows[] = ['status' => 'ok', 'key' => $key, 'date' => $entry['date'], 'desc' => $entry['desc'], 'detail' => ''];
            } else {
                $summary['diff']++;
                $detail = $found['vector'] != $expected
                    ? 'システム: ' . acc_vector_text($expected) . "\n弥生: " . acc_vector_text($found['vector'])
                    : '日付が違います（システム ' . $entry['date'] . ' / 弥生 ' . ($found['date'] ?? '不明') . '）';
                $rows[] = ['status' => 'diff', 'key' => $key, 'date' => $entry['date'], 'desc' => $entry['desc'], 'detail' => $detail];
            }
            continue;
        }
        // 識別子の仕訳が無い → 識別子の無い仕訳に同日・同額・同じ科目のものがあれば候補にする
        $candidate = null;
        foreach ($unmarked as $i => $u) {
            if (isset($usedUnmarked[$i]) || $u['date'] !== $entry['date']) {
                continue;
            }
            $vector = acc_entry_vector(['debits' => $u['debits'], 'credits' => $u['credits']]);
            if ($vector == $expected) {
                $candidate = $i;
                break;
            }
        }
        if ($candidate !== null) {
            $usedUnmarked[$candidate] = true;
            $summary['candidate']++;
            $rows[] = ['status' => 'candidate', 'key' => $key, 'date' => $entry['date'], 'desc' => $entry['desc'], 'detail' => '識別子はありませんが、同日・同額・同じ科目の仕訳が弥生にあります（手入力済みの可能性）。'];
        } else {
            $summary['missing']++;
            $rows[] = ['status' => 'missing', 'key' => $key, 'date' => $entry['date'], 'desc' => $entry['desc'], 'detail' => '弥生にありません（未取込）。システム: ' . acc_vector_text($expected)];
        }
    }
    foreach ($net as $key => $info) {
        if (isset($generated[$key]) || $info['vector'] === []) {
            continue;
        }
        $summary['extra']++;
        $rows[] = ['status' => 'extra', 'key' => $key, 'date' => $info['date'] ?? '', 'desc' => '', 'detail' => 'システム側に該当する仕訳がありません（元データの取消後に逆仕訳が未取込、など）。弥生: ' . acc_vector_text($info['vector'])];
    }
    usort($rows, static fn (array $a, array $b): int => [$a['date'], $a['key']] <=> [$b['date'], $b['key']]);
    return ['rows' => $rows, 'summary' => $summary, 'unmarked' => count($unmarked) - count($usedUnmarked)];
}

// ---------------------------------------------------------------------------
// 帳簿・画面の共通部品
// ---------------------------------------------------------------------------

function acc_fetch_books(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM acc_books WHERE is_active = 1 ORDER BY id')->fetchAll();
}

function acc_fetch_book(PDO $pdo, int $bookId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM acc_books WHERE id = :id AND is_active = 1');
    $stmt->execute([':id' => $bookId]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/**
 * 管理画面共通ヘッダー（弥生会計に近いメニューバー）。管理者のみ。各ページで require_login('admin') 済みであること。
 *   帳簿・伝票 … 仕訳日記帳（出力） / 振替伝票（手入力）
 *   決算・申告 … 定型仕訳・決算仕訳
 *   設定 … 勘定科目 / 仕訳設定 / 帳簿
 *   ツール … 弥生の仕訳日記帳と照合
 * $current は 'input' | 'manual' | 'journal' | 'ledger' | 'statements' | 'assets' | 'uchiwake' | 'templates' | 'opening' | 'chart' | 'accounts' | 'map' | 'books' | 'reconcile'
 */
function acc_render_header(array $admin, string $title, string $current, ?int $bookId = null): void
{
    $q = $bookId !== null ? 'book=' . $bookId : '';
    $url = static fn (string $path, string $extra = ''): string => $path . (($q !== '' || $extra !== '') ? '?' . implode('&', array_filter([$q, $extra])) : '');
    $menus = [
        '帳簿・伝票(C)' => [
            'input' => [$url('/admin/accounting_input.php'), '取引入力（入金・出金・経費・振替）'],
            'manual' => [$url('/admin/accounting_manual.php'), '振替伝票（複合・一括入力）'],
            'journal' => [$url('/admin/accounting.php'), '仕訳日記帳（弥生への出力）'],
        ],
        '帳簿(B)' => [
            'ledger' => [$url('/admin/accounting_ledger.php'), '総勘定元帳・補助元帳'],
            'statements' => [$url('/admin/accounting_statements.php'), '試算表・決算書（損益計算書／貸借対照表）'],
        ],
        '決算・申告(K)' => [
            'assets' => [$url('/admin/accounting_assets.php'), '固定資産台帳・減価償却'],
            'uchiwake' => [$url('/admin/accounting_uchiwake.php'), '勘定科目内訳書'],
            'tax_ct' => [$url('/admin/accounting_tax.php', 'tab=ct'), '消費税申告書'],
            'tax_corp' => [$url('/admin/accounting_tax.php', 'tab=b1'), '法人税等の申告書（別表）'],
            'tax_personal' => [$url('/admin/accounting_tax.php', 'tab=ret'), '個人の確定申告書・青色申告決算書'],
            'tax_settings' => [$url('/admin/accounting_tax.php', 'tab=settings'), '税務設定（税率・中間納付・調整）'],
            'templates' => [$url('/admin/accounting_settings.php', 'tab=templates'), '定型仕訳・決算仕訳'],
        ],
        '設定(S)' => [
            'opening' => [$url('/admin/accounting_opening.php'), '期首残高・基本情報'],
            'chart' => [$url('/admin/accounting_chart.php'), '勘定科目マスタ'],
            'accounts' => [$url('/admin/accounting_settings.php', 'tab=accounts'), '勘定科目（弥生から取込）'],
            'map' => [$url('/admin/accounting_settings.php', 'tab=map'), '仕訳設定（科目・税区分）'],
            'books' => [$url('/admin/accounting_settings.php', 'tab=books'), '帳簿'],
        ],
        'ツール(T)' => [
            'reconcile' => [$url('/admin/accounting_reconcile.php'), '弥生の仕訳日記帳と照合'],
        ],
    ];
    $bar = '';
    foreach ($menus as $label => $items) {
        $active = array_key_exists($current, $items);
        $bar .= '<li class="' . ($active ? 'on' : '') . '"><span>' . acc_h($label) . '</span><ul>';
        foreach ($items as $key => [$href, $text]) {
            $bar .= '<li><a href="' . acc_h($href) . '"' . ($key === $current ? ' class="cur"' : '') . '>' . acc_h($text) . '</a></li>';
        }
        $bar .= '</ul></li>';
    }
    ?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= acc_h($title) ?> | 管理者</title>
    <style>
        <?= inv_common_css() ?>

        body { margin: 0; background: #f0f0f0; }
        .yb-menubar { background: #f7f4ee; border-bottom: 1px solid #b9b2a4; display: flex; align-items: stretch; flex-wrap: wrap; font-size: 0.92em; }
        .yb-menubar > ul { list-style: none; margin: 0; padding: 0; display: flex; flex-wrap: wrap; }
        .yb-menubar li { position: relative; }
        .yb-menubar > ul > li > span { display: block; padding: 6px 14px; cursor: default; }
        .yb-menubar > ul > li.on > span { font-weight: bold; }
        .yb-menubar > ul > li:hover > span, .yb-menubar > ul > li:focus-within > span { background: #dfe9f7; }
        .yb-menubar ul ul { display: none; position: absolute; left: 0; top: 100%; min-width: 230px; background: #fff; border: 1px solid #8a8472; box-shadow: 2px 2px 4px rgba(0,0,0,.25); list-style: none; margin: 0; padding: 2px 0; z-index: 20; }
        .yb-menubar li:hover > ul, .yb-menubar li:focus-within > ul { display: block; }
        .yb-menubar ul ul a { display: block; padding: 5px 16px; color: #222; text-decoration: none; white-space: nowrap; }
        .yb-menubar ul ul a:hover, .yb-menubar ul ul a.cur { background: #cfe0f7; }
        .yb-menubar .yb-right { margin-left: auto; padding: 6px 12px; font-size: 0.9em; }
        .yb-menubar .yb-right a { margin-left: 10px; }
        .yb-body { padding: 12px 16px 24px; }
        .yb-title { background: #c58a6e; color: #fff; padding: 6px 14px; font-size: 1.15em; letter-spacing: 0.15em; border-radius: 2px 2px 0 0; margin: 0; }
        .yb-panel { background: #fdf3e3; border: 1px solid #c58a6e; padding: 10px 12px 14px; margin-bottom: 14px; }
        .yb-toolbar { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-bottom: 8px; font-size: 0.92em; }
        .yb-periods { display: inline-flex; border: 1px solid #a8896f; background: #f3e1c9; }
        .yb-periods a, .yb-periods span { padding: 3px 10px; border-right: 1px solid #c9ae92; text-decoration: none; color: #6a3b22; font-size: 0.92em; }
        .yb-periods a:last-child, .yb-periods span:last-child { border-right: none; }
        .yb-periods .sel { background: #fff; font-weight: bold; color: #222; }
        .yb-periods .dim { color: #b8a58f; }
        table.yb-journal { border-collapse: collapse; width: 100%; background: #fdf3e3; font-size: 0.9em; }
        table.yb-journal th { background: #e9d5bd; border: 1px solid #c9ae92; padding: 3px 6px; font-weight: normal; text-align: center; white-space: nowrap; }
        table.yb-journal td { border: 1px solid #e3cdb4; padding: 2px 6px; vertical-align: top; }
        table.yb-journal tr.l1 td { border-bottom: 1px dotted #e3cdb4; }
        table.yb-journal tr.l2 td { border-top: none; border-bottom: 1px solid #c9ae92; color: #555; }
        table.yb-journal td.num { text-align: right; white-space: nowrap; }
        table.yb-journal tr.rev td { color: #b3261e; }
        table.yb-journal tr.same td { color: #888; }
        .yb-type { color: #3a5fa0; white-space: nowrap; }
        .badge { display: inline-block; font-size: 0.8em; padding: 1px 8px; border-radius: 10px; }
        .badge-new { background: #e7f0fd; color: #0b5ed7; }
        .badge-changed { background: #fff3cd; color: #856404; }
        .badge-same { background: #eee; color: #666; }
        .badge-orphan { background: #fdecea; color: #b3261e; }
        .badge-ok { background: #e6f4ea; color: #1e7e34; }
        .badge-diff { background: #fdecea; color: #b3261e; }
        .badge-missing { background: #fff3cd; color: #856404; }
        .badge-extra { background: #fdecea; color: #b3261e; }
        .badge-candidate { background: #eef; color: #335; }
        .scroll { overflow-x: auto; }
        .pre { white-space: pre-wrap; margin: 0; font-size: 0.85em; }
        fieldset { border: 1px solid #c9ae92; border-radius: 4px; padding: 10px 12px; margin-bottom: 12px; background: #fffaf2; }
        legend { font-weight: bold; }
        input[type=number] { width: 120px; }
        .tabs { margin: 0 0 -1px; }
        .tabs a { display: inline-block; padding: 4px 12px; border: 1px solid #c58a6e; border-bottom: none; text-decoration: none; margin-right: 4px; background: #f3e1c9; color: #6a3b22; }
        .tabs a.active { background: #fdf3e3; font-weight: bold; color: #222; }
        .yb-body h1 { font-size: 1.1em; }
        .notice { margin: 8px 0; }
    </style>
</head>
<body>
<div class="yb-menubar">
    <ul><?= $bar ?></ul>
    <div class="yb-right"><?= acc_h($admin['name']) ?>さん（管理者）<a href="/admin/dashboard.php">ダッシュボード</a><a href="/admin/logout.php">ログアウト</a></div>
</div>
<div class="yb-body">
<h2 class="yb-title"><?= acc_h($title) ?></h2>
    <?php
}

/** 画面の最後に閉じるタグを出す */
function acc_render_footer(): void
{
    echo "</div>\n</body>\n</html>\n";
}

/** 事業年度（開始年）・期間（'all' | 1〜12 | 'k'=決算月の決算整理）から、集計する月の範囲を決める */
function acc_period_range(array $book, int $fiscalYear, string $period): array
{
    $start = max(1, min(12, (int) $book['fiscal_start_month']));
    $startMonth = sprintf('%04d-%02d', $fiscalYear, $start);
    if ($period === 'all') {
        return ['from' => $startMonth, 'to' => acc_add_months($startMonth, 11), 'settle_only' => false];
    }
    if ($period === 'k') {
        $m = acc_add_months($startMonth, 11);
        return ['from' => $m, 'to' => $m, 'settle_only' => true];
    }
    $n = max(1, min(12, (int) $period));
    $m = acc_add_months($startMonth, $n - 1);
    return ['from' => $m, 'to' => $m, 'settle_only' => false];
}

/** 今日が属する事業年度の開始年 */
function acc_current_fiscal_year(array $book): int
{
    $start = max(1, min(12, (int) $book['fiscal_start_month']));
    $y = (int) date('Y');
    return (int) date('n') >= $start ? $y : $y - 1;
}

/** 弥生の仕訳日記帳の上部にある「期間(O) 1〜12 決 全期間(Y)」の帯 */
function acc_render_period_bar(array $book, int $fiscalYear, string $period, string $script, array $extraQuery = []): void
{
    $base = array_merge(['book' => (int) $book['id'], 'fy' => $fiscalYear], $extraQuery);
    $link = static function (string $label, string $p, string $class = '') use ($base, $script, $period): string {
        $href = $script . '?' . http_build_query($base + ['p' => $p]);
        return '<a href="' . acc_h($href) . '" class="' . ($period === $p ? 'sel' : $class) . '">' . acc_h($label) . '</a>';
    };
    $start = max(1, min(12, (int) $book['fiscal_start_month']));
    echo '<div class="yb-periods">' . $link('全期間', 'all');
    for ($i = 1; $i <= 12; $i++) {
        $cal = (($start - 1 + $i - 1) % 12) + 1;
        echo $link((string) $i, (string) $i) ;
    }
    echo $link('決', 'k') . '</div>';
    $first = acc_period_range($book, $fiscalYear, 'all');
    echo ' <span class="muted">' . acc_h(inv_month_label($first['from'])) . '〜' . acc_h(inv_month_label($first['to'])) . '</span>';
}

/**
 * 仕訳日記帳の表示（弥生と同じ2段表示）。1段目: 決算・日付・タイプ・借方勘定科目・借方金額・貸方勘定科目・貸方金額・摘要、
 * 2段目: 伝票No.・生成元・借方補助科目・借方消費税額・貸方補助科目・貸方消費税額・税区分。
 *
 * @param list<array{entry:array, action?:string, reversal?:bool, key:string, revision:int}> $items
 */
function acc_render_journal(array $items, int $voucherStart, array $actionLabels = []): void
{
    echo '<div class="scroll"><table class="yb-journal"><thead>';
    echo '<tr><th>決算</th><th>日付</th><th>タイプ</th><th>借方勘定科目</th><th>借方金額</th><th>貸方勘定科目</th><th>貸方金額</th><th>摘要</th></tr>';
    echo '<tr><th>状態</th><th>伝票No.</th><th>生成元</th><th>借方補助科目</th><th>消費税額</th><th>貸方補助科目</th><th>消費税額</th><th>借方税区分 ／ 貸方税区分</th></tr>';
    echo '</thead><tbody>';
    $no = $voucherStart;
    foreach ($items as $item) {
        $e = $item['entry'];
        $slots = [];
        if (count($e['debits']) === 1 && count($e['credits']) === 1) {
            $slots[] = [$e['debits'][0], $e['credits'][0]];
        } else {
            foreach ($e['debits'] as $d) {
                $slots[] = [$d, null];
            }
            foreach ($e['credits'] as $c) {
                $slots[] = [null, $c];
            }
        }
        $class = ($item['reversal'] ?? false) ? 'rev' : (($item['action'] ?? '') === 'same' ? 'same' : '');
        $source = ACC_SOURCE_LABELS[substr($item['key'], 0, 1)] ?? '';
        foreach ($slots as $i => [$d, $c]) {
            $label = $d !== null ? ($d['desc'] ?? '') : ($c['desc'] ?? '');
            $summary = $label !== '' && count($slots) > 1 ? trim($e['desc'] . ' ' . $label) : $e['desc'];
            echo '<tr class="l1 ' . $class . '">';
            echo '<td>' . ($i === 0 ? ($e['settle'] ? '決算' : '') : '') . '</td>';
            echo '<td>' . ($i === 0 ? acc_h(substr($e['date'], 5, 2) . '/' . substr($e['date'], 8, 2)) : '') . '</td>';
            echo '<td class="yb-type">' . ($i === 0 ? (count($slots) > 1 ? '[振伝]' : '') : '') . '</td>';
            echo '<td>' . acc_h($d['account'] ?? '') . '</td><td class="num">' . ($d !== null ? number_format((int) $d['amount']) : '') . '</td>';
            echo '<td>' . acc_h($c['account'] ?? '') . '</td><td class="num">' . ($c !== null ? number_format((int) $c['amount']) : '') . '</td>';
            echo '<td>' . acc_h($summary) . '</td></tr>';
            echo '<tr class="l2 ' . $class . '">';
            $badge = '';
            if ($i === 0 && isset($item['action'])) {
                $actionLabel = $actionLabels[$item['action']] ?? $item['action'];
                $badge = '<span class="badge badge-' . acc_h(str_replace(['changed_reverse', 'changed_new'], ['changed', 'changed'], $item['action'])) . '">' . acc_h($actionLabel) . '</span>';
            }
            echo '<td>' . $badge . '</td>';
            echo '<td>' . ($i === 0 ? $no : '') . '</td>';
            echo '<td class="yb-type">' . ($i === 0 ? '[' . acc_h($source) . ' ' . acc_h($item['key']) . ']' : '') . '</td>';
            echo '<td>' . acc_h($d['sub'] ?? '') . '</td><td class="num">' . ($d !== null && (int) $d['tax'] !== 0 ? number_format((int) $d['tax']) : '') . '</td>';
            echo '<td>' . acc_h($c['sub'] ?? '') . '</td><td class="num">' . ($c !== null && (int) $c['tax'] !== 0 ? number_format((int) $c['tax']) : '') . '</td>';
            echo '<td>' . acc_h(($d['tax_class'] ?? '') . (($d !== null && $c !== null) ? ' ／ ' : '') . ($c['tax_class'] ?? '')) . '</td></tr>';
        }
        $no++;
    }
    if ($items === []) {
        echo '<tr><td colspan="8" class="muted" style="text-align:center;padding:18px">この期間に出力する仕訳はありません。</td></tr>';
    }
    echo '</tbody></table></div>';
}

function acc_render_messages(?array $flash): void
{
    if ($flash !== null) {
        echo '<p class="message ' . acc_h($flash['type']) . '">' . acc_h($flash['message']) . '</p>';
    }
}

/** 画面で使う帳簿を決める（?book= または POST の book。無効なら先頭の帳簿） */
function acc_select_book(PDO $pdo): array
{
    $books = acc_fetch_books($pdo);
    if ($books === []) {
        http_response_code(500);
        exit('帳簿が登録されていません。admin/sql/acc_tables.sql を実行してください。');
    }
    $wanted = (int) ($_GET['book'] ?? $_POST['book'] ?? 0);
    foreach ($books as $book) {
        if ((int) $book['id'] === $wanted) {
            return [$book, $books];
        }
    }
    return [$books[0], $books];
}

function acc_render_book_selector(array $books, array $book, string $action, array $hidden = []): void
{
    echo '<form method="get" action="' . acc_h($action) . '" class="inline">';
    foreach ($hidden as $name => $value) {
        echo '<input type="hidden" name="' . acc_h((string) $name) . '" value="' . acc_h((string) $value) . '">';
    }
    echo '<label>帳簿: <select name="book" onchange="this.form.submit()">';
    foreach ($books as $b) {
        echo '<option value="' . (int) $b['id'] . '"' . ((int) $b['id'] === (int) $book['id'] ? ' selected' : '') . '>' . acc_h($b['name']) . '</option>';
    }
    echo '</select></label> <noscript><button type="submit">切替</button></noscript></form>';
}

// 帳簿機能（元帳・決算書）と固定資産台帳の仕訳も acc_generate_all に含めるため、最後に読み込む
require_once __DIR__ . '/accounting_ledger.php';
require_once __DIR__ . '/accounting_assets.php';
require_once __DIR__ . '/accounting_corptax.php';
require_once __DIR__ . '/accounting_personal.php';
