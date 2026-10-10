<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/accounting.php';

$admin = require_login('admin');
$pdo = getPdo();

/**
 * 仕訳日記帳（弥生会計への出力）。請求書・給与・定型仕訳・手入力から作った仕訳を、弥生会計の仕訳インポート形式
 * （25列・カンマ区切り・Shift-JIS）のCSVにして出力する。
 * 既定では「未出力の仕訳」と「出力後に元データが変わった／無くなった仕訳の逆仕訳」だけを出す（二重取込の防止）。
 */

[$book, $books] = acc_select_book($pdo);
$fiscalYear = (int) ($_GET['fy'] ?? $_POST['fy'] ?? acc_current_fiscal_year($book));
$period = (string) ($_GET['p'] ?? $_POST['p'] ?? 'all');
if (!in_array($period, ['all', 'k'], true) && !(ctype_digit($period) && (int) $period >= 1 && (int) $period <= 12)) {
    $period = 'all';
}
$mode = ($_GET['mode'] ?? $_POST['mode'] ?? 'new') === 'all' ? 'all' : 'new';
$voucherStart = max(1, (int) ($_GET['vs'] ?? $_POST['vs'] ?? 1));
$range = acc_period_range($book, $fiscalYear, $period);

$plan = acc_plan($pdo, $book, $range['from'], $range['to'], $mode);
$items = $plan['items'];
if ($range['settle_only']) {
    $items = array_values(array_filter($items, static fn (array $i): bool => (bool) $i['entry']['settle']));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $self = '/admin/accounting.php?' . http_build_query(['book' => $book['id'], 'fy' => $fiscalYear, 'p' => $period, 'mode' => $mode, 'vs' => $voucherStart]);
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('error', '不正なリクエストです。画面を再読み込みしてやり直してください。');
        header('Location: ' . $self);
        exit;
    }
    if ($plan['problems'] !== []) {
        set_flash('error', '出力できない仕訳があります。画面の「確認が必要な仕訳」を解消してください。');
        header('Location: ' . $self);
        exit;
    }
    if ($items === []) {
        set_flash('error', 'この期間に出力する仕訳はありません。');
        header('Location: ' . $self);
        exit;
    }
    $record = ($_POST['record'] ?? '1') === '1';
    $fileName = sprintf('yayoi_%s_%s_%s_%s.csv', $book['code'], $range['from'], $range['to'], date('His'));
    $csv = acc_csv_to_sjis(acc_rows_to_csv(acc_plan_rows($items, $voucherStart)));
    if ($record) {
        try {
            acc_record_export($pdo, $book, $range['from'], $range['to'], $mode, $voucherStart, $items, $fileName, (int) $admin['id']);
        } catch (Throwable $e) {
            error_log('accounting export record failed: ' . $e->getMessage());
            set_flash('error', '出力の記録に失敗したため、CSVは出力していません。もう一度お試しください。');
            header('Location: ' . $self);
            exit;
        }
    }
    header('Content-Type: text/csv; charset=Shift_JIS');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . strlen($csv));
    header('Cache-Control: no-store');
    echo $csv;
    exit;
}

$flash = pop_flash();
$historyStmt = $pdo->prepare(
    'SELECT x.*, e.name AS user_name FROM acc_exports x LEFT JOIN employees e ON e.id = x.created_by
     WHERE x.book_id = :b ORDER BY x.id DESC LIMIT 15'
);
$historyStmt->execute([':b' => $book['id']]);
$history = $historyStmt->fetchAll();
$knownAccounts = acc_known_accounts($pdo, (int) $book['id']);

$yearOptions = range(acc_current_fiscal_year($book) + 1, acc_current_fiscal_year($book) - 5);
$counts = $plan['counts'];
$actionLabels = ['new' => '新規', 'same' => '出力済み', 'changed_reverse' => '変更前を取消', 'changed_new' => '変更後', 'orphan' => '元データなし（取消）'];

acc_render_header($admin, '仕訳日記帳', 'journal', (int) $book['id']);
acc_render_messages($flash);
?>
<div class="yb-panel">
    <div class="yb-toolbar">
        <?php acc_render_book_selector($books, $book, '/admin/accounting.php', ['p' => $period, 'mode' => $mode]); ?>
        <form method="get" action="/admin/accounting.php" class="inline">
            <input type="hidden" name="book" value="<?= (int) $book['id'] ?>">
            <input type="hidden" name="p" value="<?= acc_h($period) ?>">
            <label>事業年度:
                <select name="fy" onchange="this.form.submit()">
                    <?php foreach ($yearOptions as $y) : ?>
                        <option value="<?= $y ?>"<?= $y === $fiscalYear ? ' selected' : '' ?>><?= $y ?>年<?= (int) $book['fiscal_start_month'] ?>月開始</option>
                    <?php endforeach; ?>
                </select>
            </label>
        </form>
        <?php acc_render_period_bar($book, $fiscalYear, $period, '/admin/accounting.php', ['mode' => $mode, 'vs' => $voucherStart]); ?>
    </div>

    <?php if ((int) $book['use_invoice'] === 0 && (int) $book['use_payroll'] === 0) : ?>
        <p class="notice">この帳簿は請求書・給与の自動作成が無効です（手入力の仕訳と定型仕訳だけが対象）。変更は「設定 → 帳簿」。</p>
    <?php endif; ?>
    <?php if ($knownAccounts === null) : ?>
        <p class="notice">弥生の勘定科目一覧が未取込のため、科目名の存在確認ができません。「設定 → 勘定科目」で弥生から出力した勘定科目一覧（汎用形式）を取り込んでください。</p>
    <?php endif; ?>

    <?php if ($plan['problems'] !== []) : ?>
        <div class="message error"><strong>確認が必要な仕訳（解消するまで出力できません）</strong>
            <ul><?php foreach ($plan['problems'] as $p) : ?><li><?= acc_h($p) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>
    <?php if ($plan['warnings'] !== []) : ?>
        <div class="notice"><strong>注意</strong>
            <ul><?php foreach ($plan['warnings'] as $w) : ?><li><?= acc_h($w) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <p>
        新規 <strong><?= $counts['new'] ?></strong> 件 ／ 変更あり <strong><?= $counts['changed'] ?></strong> 件（逆仕訳＋新仕訳） ／
        元データなし <strong><?= $counts['orphan'] ?></strong> 件（逆仕訳） ／ 出力済み・変更なし <?= $counts['same'] ?> 件
        <span class="muted">（出力する仕訳 <?= count($items) ?> 件）</span>
    </p>

    <?php acc_render_journal($items, $voucherStart, $actionLabels); ?>

    <form method="post" action="/admin/accounting.php" style="margin-top:12px">
        <input type="hidden" name="csrf_token" value="<?= acc_h(csrf_token()) ?>">
        <input type="hidden" name="book" value="<?= (int) $book['id'] ?>">
        <input type="hidden" name="fy" value="<?= $fiscalYear ?>">
        <input type="hidden" name="p" value="<?= acc_h($period) ?>">
        <label>出力する仕訳:
            <select name="mode">
                <option value="new"<?= $mode === 'new' ? ' selected' : '' ?>>未出力・変更分だけ（通常）</option>
                <option value="all"<?= $mode === 'all' ? ' selected' : '' ?>>出力済みも含めすべて（取り込み直し用）</option>
            </select>
        </label>
        <label>伝票No.の開始番号: <input type="number" name="vs" min="1" value="<?= $voucherStart ?>"></label>
        <button type="submit" class="primary" name="record" value="1"<?= ($items === [] || $plan['problems'] !== []) ? ' disabled' : '' ?>>弥生用CSVを出力（出力済みとして記録）</button>
        <button type="submit" name="record" value="0"<?= ($items === [] || $plan['problems'] !== []) ? ' disabled' : '' ?>>記録せずに出力（確認用）</button>
        <p class="muted">
            出力するCSVは、弥生会計の「仕訳日記帳インポート形式」（25列・カンマ区切り・Shift-JIS）です。
            弥生会計の［ファイル］→［インポート］（または［ツール］→［データ取込］の仕訳日記帳）で取り込んでください。
            各仕訳の「仕訳メモ」に識別子（SF:…）を入れてあり、照合に使います。取り込む帳簿は上で選んだ「<?= acc_h($book['name']) ?>」のデータファイルです。
        </p>
    </form>
</div>

<h2>出力履歴</h2>
<table class="list">
    <thead><tr><th>出力日時</th><th>期間</th><th>範囲</th><th class="num">仕訳数</th><th class="num">借方合計</th><th>ファイル名</th><th>出力者</th></tr></thead>
    <tbody>
    <?php foreach ($history as $h) : ?>
        <tr>
            <td><?= acc_h($h['created_at']) ?></td>
            <td><?= acc_h($h['period_from'] . '〜' . $h['period_to']) ?></td>
            <td><?= $h['mode'] === 'all' ? 'すべて' : '未出力・変更分' ?></td>
            <td class="num"><?= (int) $h['entry_count'] ?></td>
            <td class="num"><?= number_format((int) $h['total_debit']) ?></td>
            <td><?= acc_h($h['file_name']) ?></td>
            <td><?= acc_h($h['user_name'] ?? '') ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if ($history === []) : ?><tr><td colspan="7" class="muted">まだ出力していません。</td></tr><?php endif; ?>
    </tbody>
</table>
<?php acc_render_footer(); ?>
