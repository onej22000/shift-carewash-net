<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/accounting.php';

$admin = require_login('admin');
$pdo = getPdo();

/**
 * 振替伝票（手入力の仕訳）。シフトシステムに元データが無い取引（個人の不動産収入・暗号資産の雑所得、会社の銀行取引など）を
 * 1行1仕訳で登録し、他の仕訳と同じ「仕訳日記帳」から弥生会計へ出力する。
 * 出力後に削除した仕訳は、次回の出力で逆仕訳になる。
 */

[$book, $books] = acc_select_book($pdo);
$bookId = (int) $book['id'];
$self = '/admin/accounting_manual.php?book=' . $bookId;

/** 日付（YYYY-MM-DD・YYYY/M/D・和暦）を YYYY-MM-DD にする。解釈できなければ null */
function manual_parse_date(string $text): ?string
{
    return acc_parse_yayoi_date(str_replace(['年', '月'], ['-', '-'], rtrim(trim($text), '日')));
}

function manual_normalize_tax(string $text): ?string
{
    $text = trim($text);
    if ($text === '') {
        return ACC_TAX_NONE;
    }
    return in_array($text, ACC_TAX_CLASSES, true) ? $text : null;
}

/** 手入力1行の検証。成功なら保存用の配列、失敗ならエラー文字列の配列 */
function manual_validate(array $in): array
{
    $errors = [];
    $date = manual_parse_date((string) ($in['date'] ?? ''));
    if ($date === null) {
        $errors[] = '日付が正しくありません。';
    }
    $amount = preg_match('/\A-?\d+\z/', str_replace([',', ' '], '', (string) ($in['amount'] ?? ''))) ? (int) str_replace([',', ' '], '', (string) $in['amount']) : null;
    if ($amount === null || $amount === 0) {
        $errors[] = '金額は0以外の整数で入力してください。';
    }
    $debit = acc_clean_text((string) ($in['debit_account'] ?? ''));
    $credit = acc_clean_text((string) ($in['credit_account'] ?? ''));
    if ($debit === '' || $credit === '') {
        $errors[] = '借方・貸方の勘定科目を入力してください。';
    }
    $debitTax = manual_normalize_tax((string) ($in['debit_tax'] ?? ''));
    $creditTax = manual_normalize_tax((string) ($in['credit_tax'] ?? ''));
    if ($debitTax === null || $creditTax === null) {
        $errors[] = '税区分は ' . implode(' / ', ACC_TAX_CLASSES) . ' のいずれかにしてください。';
    }
    if ($errors) {
        return ['errors' => $errors];
    }
    if ($amount < 0) { // マイナス金額は借方・貸方を入れ替えた正の仕訳にする
        [$debit, $credit, $debitTax, $creditTax] = [$credit, $debit, $creditTax, $debitTax];
        [$in['debit_sub'], $in['credit_sub']] = [$in['credit_sub'] ?? '', $in['debit_sub'] ?? ''];
        $amount = -$amount;
    }
    $sub = static fn ($v): ?string => ($t = acc_clean_text((string) $v)) === '' ? null : mb_substr($t, 0, ACC_SUB_MAX_CHARS);
    return ['values' => [
        'entry_date' => $date, 'debit_account' => $debit, 'debit_sub' => $sub($in['debit_sub'] ?? ''), 'debit_tax' => $debitTax,
        'credit_account' => $credit, 'credit_sub' => $sub($in['credit_sub'] ?? ''), 'credit_tax' => $creditTax,
        'amount' => $amount, 'description' => mb_substr(acc_clean_text((string) ($in['description'] ?? '')), 0, 100),
        'is_settlement' => !empty($in['is_settlement']) ? 1 : 0,
    ]];
}

function manual_insert(PDO $pdo, int $bookId, array $v, int $userId): void
{
    $pdo->prepare(
        'INSERT INTO acc_manual (book_id, entry_date, debit_account, debit_sub, debit_tax, credit_account, credit_sub, credit_tax, amount, description, is_settlement, created_by)
         VALUES (:b, :d, :da, :ds, :dt, :ca, :cs, :ct, :a, :desc, :s, :u)'
    )->execute([
        ':b' => $bookId, ':d' => $v['entry_date'], ':da' => $v['debit_account'], ':ds' => $v['debit_sub'], ':dt' => $v['debit_tax'],
        ':ca' => $v['credit_account'], ':cs' => $v['credit_sub'], ':ct' => $v['credit_tax'], ':a' => $v['amount'],
        ':desc' => $v['description'], ':s' => $v['is_settlement'], ':u' => $userId,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('error', '不正なリクエストです。画面を再読み込みしてやり直してください。');
        header('Location: ' . $self);
        exit;
    }
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'add') {
        $result = manual_validate($_POST);
        if (isset($result['errors'])) {
            set_flash('error', implode("\n", $result['errors']));
        } else {
            manual_insert($pdo, $bookId, $result['values'], (int) $admin['id']);
            set_flash('success', '仕訳を登録しました。');
        }
    } elseif ($action === 'bulk') {
        // 1行1仕訳: 日付, 借方科目, 借方補助, 借方税区分, 貸方科目, 貸方補助, 貸方税区分, 金額, 摘要（タブまたはカンマ区切り）
        $lines = preg_split('/\R/u', trim((string) ($_POST['bulk'] ?? ''))) ?: [];
        $parsed = [];
        $errors = [];
        foreach ($lines as $n => $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = str_contains($line, "\t") ? explode("\t", $line) : str_getcsv($line, ',', '"', '');
            $cells = array_pad($cells, 9, '');
            $result = manual_validate([
                'date' => $cells[0], 'debit_account' => $cells[1], 'debit_sub' => $cells[2], 'debit_tax' => $cells[3],
                'credit_account' => $cells[4], 'credit_sub' => $cells[5], 'credit_tax' => $cells[6], 'amount' => $cells[7], 'description' => $cells[8],
            ]);
            if (isset($result['errors'])) {
                $errors[] = ($n + 1) . '行目: ' . implode(' ', $result['errors']);
            } else {
                $parsed[] = $result['values'];
            }
        }
        if ($errors) {
            set_flash('error', "一括登録は1件も登録していません。\n" . implode("\n", array_slice($errors, 0, 10)));
        } elseif ($parsed === []) {
            set_flash('error', '登録する行がありません。');
        } else {
            $pdo->beginTransaction();
            try {
                foreach ($parsed as $v) {
                    manual_insert($pdo, $bookId, $v, (int) $admin['id']);
                }
                $pdo->commit();
                set_flash('success', count($parsed) . '件の仕訳を登録しました。');
            } catch (Throwable $e) {
                $pdo->rollBack();
                error_log('accounting bulk insert failed: ' . $e->getMessage());
                set_flash('error', '一括登録に失敗しました（1件も登録していません）。');
            }
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare('DELETE FROM acc_manual WHERE id = :id AND book_id = :b')->execute([':id' => $id, ':b' => $bookId]);
        set_flash('success', '仕訳を削除しました。弥生へ出力済みだった場合は、次回の出力で逆仕訳になります。');
    }
    header('Location: ' . $self);
    exit;
}

$flash = pop_flash();
$state = acc_export_state($pdo, $bookId);
$stmt = $pdo->prepare('SELECT * FROM acc_manual WHERE book_id = :b ORDER BY entry_date DESC, id DESC LIMIT 300');
$stmt->execute([':b' => $bookId]);
$rows = $stmt->fetchAll();
$accountNames = $pdo->prepare('SELECT name FROM acc_accounts WHERE book_id = :b ORDER BY code, name');
$accountNames->execute([':b' => $bookId]);
$accountNames = $accountNames->fetchAll(PDO::FETCH_COLUMN);

acc_render_header($admin, '振替伝票（手入力）', 'manual', $bookId);
acc_render_messages($flash);
?>
<div class="yb-panel">
    <div class="yb-toolbar"><?php acc_render_book_selector($books, $book, '/admin/accounting_manual.php'); ?></div>
    <datalist id="accounts"><?php foreach ($accountNames as $n) : ?><option value="<?= acc_h($n) ?>"><?php endforeach; ?></datalist>
    <datalist id="taxes"><?php foreach (ACC_TAX_CLASSES as $t) : ?><option value="<?= acc_h($t) ?>"><?php endforeach; ?></datalist>

    <form method="post" action="<?= acc_h($self) ?>">
        <input type="hidden" name="csrf_token" value="<?= acc_h(csrf_token()) ?>">
        <input type="hidden" name="action" value="add">
        <fieldset>
            <legend>仕訳を1件入力</legend>
            <table class="list">
                <thead><tr><th>日付</th><th>借方勘定科目</th><th>借方補助科目</th><th>借方税区分</th><th>貸方勘定科目</th><th>貸方補助科目</th><th>貸方税区分</th><th>金額（税込）</th><th>摘要</th><th>決算</th></tr></thead>
                <tbody><tr>
                    <td><input type="date" name="date" required></td>
                    <td><input type="text" name="debit_account" list="accounts" required size="14"></td>
                    <td><input type="text" name="debit_sub" size="10"></td>
                    <td><input type="text" name="debit_tax" list="taxes" placeholder="対象外" size="12"></td>
                    <td><input type="text" name="credit_account" list="accounts" required size="14"></td>
                    <td><input type="text" name="credit_sub" size="10"></td>
                    <td><input type="text" name="credit_tax" list="taxes" placeholder="対象外" size="12"></td>
                    <td><input type="text" name="amount" inputmode="numeric" required size="10"></td>
                    <td><input type="text" name="description" size="24" maxlength="100"></td>
                    <td><label><input type="checkbox" name="is_settlement" value="1"> 本期</label></td>
                </tr></tbody>
            </table>
            <button type="submit" class="primary">登録</button>
            <span class="muted">税額は税区分から自動計算（内税10%・円未満切捨て）します。弥生にある科目名・税区分名のとおりに入力してください。</span>
        </fieldset>
    </form>

    <form method="post" action="<?= acc_h($self) ?>">
        <input type="hidden" name="csrf_token" value="<?= acc_h(csrf_token()) ?>">
        <input type="hidden" name="action" value="bulk">
        <fieldset>
            <legend>まとめて貼り付け（家賃収入・暗号資産の損益など）</legend>
            <textarea name="bulk" rows="5" style="width:100%;box-sizing:border-box" placeholder="2026-09-30,普通預金,,対象外,地代家賃収入,,対象外,80000,9月分家賃"></textarea>
            <p class="muted">1行1仕訳。列: 日付, 借方科目, 借方補助, 借方税区分, 貸方科目, 貸方補助, 貸方税区分, 金額, 摘要（タブ区切り・カンマ区切りどちらも可。Excelからそのまま貼れます）。1行でも誤りがあれば1件も登録しません。マイナス金額は借方・貸方を入れ替えて登録します。</p>
            <button type="submit">一括登録</button>
        </fieldset>
    </form>

    <h2>登録済みの仕訳（新しい順・最大300件）</h2>
    <table class="list">
        <thead><tr><th>日付</th><th>借方</th><th>貸方</th><th class="num">金額</th><th>摘要</th><th>弥生への出力</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r) : ?>
            <?php $exported = isset($state['M' . $r['id']]); ?>
            <tr>
                <td><?= acc_h($r['entry_date']) ?><?= $r['is_settlement'] ? ' <span class="muted">（決算）</span>' : '' ?></td>
                <td><?= acc_h($r['debit_account'] . ($r['debit_sub'] !== null ? '（' . $r['debit_sub'] . '）' : '')) ?><br><span class="muted"><?= acc_h($r['debit_tax']) ?></span></td>
                <td><?= acc_h($r['credit_account'] . ($r['credit_sub'] !== null ? '（' . $r['credit_sub'] . '）' : '')) ?><br><span class="muted"><?= acc_h($r['credit_tax']) ?></span></td>
                <td class="num"><?= number_format((int) $r['amount']) ?></td>
                <td><?= acc_h($r['description']) ?></td>
                <td><?= $exported ? '出力済み' : '未出力' ?></td>
                <td>
                    <form method="post" action="<?= acc_h($self) ?>" class="inline" onsubmit="return confirm('この仕訳を削除します。<?= $exported ? '弥生へ出力済みのため、次回の出力で逆仕訳になります。' : '' ?>よろしいですか？');">
                        <input type="hidden" name="csrf_token" value="<?= acc_h(csrf_token()) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                        <button type="submit" class="danger">削除</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($rows === []) : ?><tr><td colspan="7" class="muted">登録された仕訳はありません。</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php acc_render_footer(); ?>
