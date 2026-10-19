<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/accounting.php';

$admin = require_login('admin');
$pdo = getPdo();

/**
 * 照合。弥生会計から出力した「仕訳日記帳」（インポート形式・25列のCSV）を読み込み、
 * シフトシステムが作った仕訳と突き合わせて、取込漏れ・金額の食い違い・逆仕訳の取込漏れを一覧にする。
 * 突き合わせは、取り込み時に仕訳メモへ入れた識別子（SF:…）で行う。アップロードしたファイルは保存しない。
 *
 * 弥生側の出力方法: ［帳簿・伝票］→［仕訳日記帳］で期間を選び、［ファイル］→［エクスポート］（インポート形式）。
 */

[$book, $books] = acc_select_book($pdo);
$bookId = (int) $book['id'];
$fiscalYear = (int) ($_GET['fy'] ?? $_POST['fy'] ?? acc_current_fiscal_year($book));
$period = (string) ($_GET['p'] ?? $_POST['p'] ?? 'all');
if (!in_array($period, ['all', 'k'], true) && !(ctype_digit($period) && (int) $period >= 1 && (int) $period <= 12)) {
    $period = 'all';
}
$range = acc_period_range($book, $fiscalYear, $period);
$first = acc_month_first_day($range['from']);
$last = acc_month_last_day($range['to']);

$result = null;
$notices = [];
$error = '';
$statusLabels = ['ok' => '一致', 'diff' => '差異', 'missing' => '未取込', 'extra' => '余分', 'candidate' => '手入力?'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = '不正なリクエストです。画面を再読み込みしてやり直してください。';
    } else {
        $file = $_FILES['journal_file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            $error = 'ファイルを選択してください。';
        } elseif ((int) $file['size'] > 20 * 1024 * 1024) {
            $error = 'ファイルが大きすぎます（20MBまで）。';
        } else {
            $imported = acc_parse_journal((string) file_get_contents($file['tmp_name']));
            if ($imported === []) {
                $error = '仕訳が読み取れませんでした。弥生会計の仕訳日記帳をインポート形式（25列）でエクスポートしたCSVを選んでください。';
            } else {
                $dates = array_filter(array_map(static fn (array $e): ?string => $e['date'], $imported));
                $fileFrom = $dates === [] ? null : min($dates);
                $fileTo = $dates === [] ? null : max($dates);
                if ($fileFrom !== null && ($fileFrom > $first || $fileTo < $last)) {
                    $notices[] = 'ファイルの仕訳の日付は ' . $fileFrom . '〜' . $fileTo . ' で、照合する期間（' . $first . '〜' . $last . '）全体は含まれていません。期間外の仕訳は「未取込」に見えることがあります。';
                }
                $generated = acc_generate_all($pdo, $book, $range['to'])['entries'];
                $generated = array_filter($generated, static fn (array $e): bool => $e['date'] >= $first && $e['date'] <= $last && (!$range['settle_only'] || $e['settle']));
                $importedInRange = array_values(array_filter($imported, static fn (array $e): bool => $e['date'] !== null && $e['date'] >= $first && $e['date'] <= $last));
                $result = acc_reconcile($generated, $importedInRange);
                $result['file_entries'] = count($importedInRange);
                $result['system_entries'] = count($generated);
            }
        }
    }
}

acc_render_header($admin, '弥生の仕訳日記帳と照合', 'reconcile', $bookId);
if ($error !== '') {
    echo '<p class="message error">' . acc_h($error) . '</p>';
}
?>
<div class="yb-panel">
    <div class="yb-toolbar">
        <?php acc_render_book_selector($books, $book, '/admin/accounting_reconcile.php', ['p' => $period]); ?>
        <?php acc_render_period_bar($book, $fiscalYear, $period, '/admin/accounting_reconcile.php'); ?>
    </div>
    <form method="post" action="/admin/accounting_reconcile.php" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= acc_h(csrf_token()) ?>">
        <input type="hidden" name="book" value="<?= $bookId ?>">
        <input type="hidden" name="fy" value="<?= $fiscalYear ?>">
        <input type="hidden" name="p" value="<?= acc_h($period) ?>">
        <p>照合する期間: <strong><?= acc_h($first) ?>〜<?= acc_h($last) ?></strong><?= $range['settle_only'] ? '（決算整理仕訳のみ）' : '' ?>　帳簿: <strong><?= acc_h($book['name']) ?></strong></p>
        <p><input type="file" name="journal_file" accept=".csv,.txt" required>
            <button type="submit" class="primary">照合する</button></p>
        <p class="muted">弥生会計の［帳簿・伝票］→［仕訳日記帳］で期間を選び、エクスポート（インポート形式）で出力したCSVを選びます。ファイルは保存しません。</p>
    </form>
</div>

<?php if ($result !== null) : ?>
    <?php foreach ($notices as $n) : ?><p class="notice"><?= acc_h($n) ?></p><?php endforeach; ?>
    <div class="yb-panel">
        <p>
            システムの仕訳 <?= (int) $result['system_entries'] ?> 件 ／ 弥生の仕訳 <?= (int) $result['file_entries'] ?> 件 →
            一致 <strong><?= $result['summary']['ok'] ?></strong> ／ 差異 <strong class="neg"><?= $result['summary']['diff'] ?></strong> ／
            未取込 <strong><?= $result['summary']['missing'] ?></strong> ／ 余分 <strong class="neg"><?= $result['summary']['extra'] ?></strong> ／
            手入力の可能性 <?= $result['summary']['candidate'] ?>
        </p>
        <p class="muted">識別子（SF:…）の無い弥生の仕訳 <?= (int) $result['unmarked'] ?> 件（銀行取引・手入力など）は比較していません。</p>
        <div class="scroll"><table class="list">
            <thead><tr><th>結果</th><th>日付</th><th>識別子</th><th>摘要</th><th>内容</th></tr></thead>
            <tbody>
            <?php foreach ($result['rows'] as $r) : ?>
                <?php if ($r['status'] === 'ok' && ($_POST['show_ok'] ?? '') !== '1') { continue; } ?>
                <tr>
                    <td><span class="badge badge-<?= acc_h($r['status']) ?>"><?= acc_h($statusLabels[$r['status']] ?? $r['status']) ?></span></td>
                    <td><?= acc_h($r['date']) ?></td>
                    <td><?= acc_h($r['key']) ?></td>
                    <td><?= acc_h($r['desc']) ?></td>
                    <td><p class="pre"><?= acc_h($r['detail']) ?></p></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <p class="muted">一致した仕訳は表示を省略しています（件数のみ）。</p>
    </div>
<?php endif; ?>
<?php acc_render_footer(); ?>
