<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/accounting_ledger.php';

$admin = require_login('admin');
$pdo = getPdo();

/**
 * 総勘定元帳・補助元帳。科目（と補助科目）を選ぶと、期首残高、日付順の明細、残高を表示する。
 * 科目を選ばない場合は全科目の残高一覧。
 */

[$book, $books] = acc_select_book($pdo);
$bookId = (int) $book['id'];
$fiscalYear = (int) ($_GET['fy'] ?? acc_current_fiscal_year($book));
$period = (string) ($_GET['p'] ?? 'all');
if ($period !== 'all' && !(ctype_digit($period) && (int) $period >= 1 && (int) $period <= 12)) {
    $period = 'all';
}
$account = acc_clean_text((string) ($_GET['account'] ?? ''));
$sub = isset($_GET['sub']) && $_GET['sub'] !== '' ? acc_clean_text((string) $_GET['sub']) : null;
$chart = acc_chart_map($pdo, $book);
$balances = acc_balances($pdo, $book, $fiscalYear);
$yearOptions = range(acc_current_fiscal_year($book) + 1, acc_current_fiscal_year($book) - 5);

acc_render_header($admin, '総勘定元帳', 'ledger', $bookId);
?>
<div class="yb-panel">
    <div class="yb-toolbar">
        <?php acc_render_book_selector($books, $book, '/admin/accounting_ledger.php', ['p' => $period, 'account' => $account]); ?>
        <form method="get" action="/admin/accounting_ledger.php" class="inline">
            <input type="hidden" name="book" value="<?= $bookId ?>"><input type="hidden" name="p" value="<?= acc_h($period) ?>">
            <label>事業年度: <select name="fy" onchange="this.form.submit()">
                <?php foreach ($yearOptions as $y) : ?><option value="<?= $y ?>"<?= $y === $fiscalYear ? ' selected' : '' ?>><?= $y ?>年<?= (int) $book['fiscal_start_month'] ?>月開始</option><?php endforeach; ?>
            </select></label>
            <label>科目: <select name="account" onchange="this.form.sub.value='';this.form.submit()">
                <option value="">（全科目の残高一覧）</option>
                <?php foreach ($balances['accounts'] as $name => $v) : ?><option<?= $name === $account ? ' selected' : '' ?>><?= acc_h((string) $name) ?></option><?php endforeach; ?>
            </select></label>
            <?php if ($account !== '' && isset($balances['accounts'][$account]) && count($balances['accounts'][$account]['subs']) > 1 || ($account !== '' && isset($balances['accounts'][$account]) && array_keys($balances['accounts'][$account]['subs']) !== [''])) : ?>
                <label>補助科目: <select name="sub" onchange="this.form.submit()">
                    <option value="">（すべて）</option>
                    <?php foreach (array_keys($balances['accounts'][$account]['subs']) as $s) : ?><option value="<?= acc_h((string) $s) ?>"<?= $sub === (string) $s ? ' selected' : '' ?>><?= acc_h($s === '' ? '（補助なし）' : (string) $s) ?></option><?php endforeach; ?>
                </select></label>
            <?php else : ?><input type="hidden" name="sub" value=""><?php endif; ?>
        </form>
        <?php if ($account !== '') : ?>
            <div class="yb-periods">
                <?php
                $q = static fn (string $p): string => '/admin/accounting_ledger.php?' . http_build_query(['book' => $bookId, 'fy' => $fiscalYear, 'account' => $account, 'sub' => $sub ?? '', 'p' => $p]);
                $start = max(1, min(12, (int) $book['fiscal_start_month']));
                ?>
                <a href="<?= acc_h($q('all')) ?>" class="<?= $period === 'all' ? 'sel' : '' ?>">全期間</a>
                <?php for ($i = 1; $i <= 12; $i++) : ?><a href="<?= acc_h($q((string) $i)) ?>" class="<?= $period === (string) $i ? 'sel' : '' ?>"><?= $i ?></a><?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>

<?php if (!$balances['ok']) : ?>
    <p class="notice"><?= acc_h($balances['message']) ?></p>
<?php elseif ($account === '') : ?>
    <div class="scroll"><table class="list">
        <thead><tr><th>科目</th><th class="num">期首残高</th><th class="num">借方</th><th class="num">貸方</th><th class="num">期末残高</th></tr></thead>
        <tbody>
        <?php foreach (acc_trial_rows($balances, $chart) as $r) : ?>
            <tr>
                <td><a href="/admin/accounting_ledger.php?<?= acc_h(http_build_query(['book' => $bookId, 'fy' => $fiscalYear, 'account' => $r['account']])) ?>"><?= acc_h($r['account']) ?></a>
                    <span class="muted"><?= acc_h($r['section']) ?></span></td>
                <td class="num"><?= acc_h(acc_money($r['open'])) ?></td><td class="num"><?= acc_h(acc_money($r['dr'])) ?></td>
                <td class="num"><?= acc_h(acc_money($r['cr'])) ?></td><td class="num"><?= acc_h(acc_money($r['end'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <p class="muted">金額は借方残高が正、貸方残高が△です。</p>
<?php else : ?>
    <?php
    $ledger = acc_ledger($balances, $chart, $account, $sub);
    $open = $ledger['open'];
    $rows = $ledger['rows'];
    if ($period !== 'all') {
        $monthFirst = acc_month_first_day(acc_period_range($book, $fiscalYear, $period)['from']);
        $monthLast = acc_month_last_day(acc_period_range($book, $fiscalYear, $period)['to']);
        $before = array_filter($rows, static fn (array $r): bool => $r['date'] < $monthFirst);
        $open = $before === [] ? $open : end($before)['balance'];
        $rows = array_values(array_filter($rows, static fn (array $r): bool => $r['date'] >= $monthFirst && $r['date'] <= $monthLast));
    }
    $drTotal = array_sum(array_column($rows, 'dr'));
    $crTotal = array_sum(array_column($rows, 'cr'));
    ?>
    <h3><?= acc_h($account) ?><?= $sub !== null ? '（' . acc_h($sub === '' ? '補助なし' : $sub) . '）' : '' ?>
        <span class="muted"><?= isset($chart[$account]) ? acc_h(ACC_CATEGORY_LABELS[$chart[$account]['category']] . '／' . $chart[$account]['section']) : '未登録の科目' ?></span></h3>
    <div class="scroll"><table class="list">
        <thead><tr><th>日付</th><th>伝票</th><th>補助科目</th><th>相手科目</th><th>摘要</th><th class="num">借方</th><th class="num">貸方</th><th class="num">残高</th></tr></thead>
        <tbody>
            <tr><td></td><td></td><td></td><td></td><td><?= $period === 'all' ? '前期繰越（期首残高）' : '前月繰越' ?></td><td></td><td></td><td class="num"><?= acc_h(acc_money($open, true)) ?></td></tr>
            <?php foreach ($rows as $r) : ?>
                <tr>
                    <td><?= acc_h($r['date']) ?></td>
                    <td><?= acc_h($r['key']) ?><?= $r['settle'] ? ' <span class="muted">決算</span>' : '' ?></td>
                    <td><?= acc_h($r['sub']) ?></td>
                    <td><?= acc_h($r['other']) ?></td>
                    <td><?= acc_h($r['desc']) ?></td>
                    <td class="num"><?= acc_h(acc_money($r['dr'])) ?></td>
                    <td class="num"><?= acc_h(acc_money($r['cr'])) ?></td>
                    <td class="num"><?= acc_h(acc_money($r['balance'], true)) ?></td>
                </tr>
            <?php endforeach; ?>
            <tr style="font-weight:bold"><td colspan="5">合計</td><td class="num"><?= acc_h(acc_money($drTotal, true)) ?></td><td class="num"><?= acc_h(acc_money($crTotal, true)) ?></td><td class="num"><?= acc_h(acc_money($rows === [] ? $open : end($rows)['balance'], true)) ?></td></tr>
        </tbody>
    </table></div>
    <p class="muted">残高は借方残高が正、貸方残高が△です。</p>
<?php endif; ?>
</div>
<?php acc_render_footer(); ?>
