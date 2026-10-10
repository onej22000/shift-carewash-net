<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/accounting_ledger.php';

$admin = require_login('admin');
$pdo = getPdo();

/**
 * 試算表・決算書。残高試算表、損益計算書（販売費及び一般管理費の内訳つき）、貸借対照表。
 * 数字はすべて、請求書・給与・定型仕訳・手入力の仕訳と期首残高から集計する（ブラウザの印刷でそのまま紙／PDFにできる）。
 */

[$book, $books] = acc_select_book($pdo);
$bookId = (int) $book['id'];
$fiscalYear = (int) ($_GET['fy'] ?? acc_current_fiscal_year($book));
$view = (string) ($_GET['view'] ?? 'all');
if (!in_array($view, ['all', 'trial', 'pl', 'bs'], true)) {
    $view = 'all';
}
$withSub = !empty($_GET['sub']);
$chart = acc_chart_map($pdo, $book);
$balances = acc_balances($pdo, $book, $fiscalYear);
$yearOptions = range(acc_current_fiscal_year($book) + 1, acc_current_fiscal_year($book) - 5);
$settings = acc_book_settings($pdo, $bookId);
$st = $balances['ok'] ? acc_statements($balances, $chart) : null;

// 試算表のCSV
if ($balances['ok'] && ($_GET['csv'] ?? '') === 'trial') {
    $out = "\xEF\xBB\xBF" . "区分,表示区分,勘定科目,補助科目,期首残高,借方,貸方,期末残高\r\n";
    foreach (acc_trial_rows($balances, $chart) as $r) {
        foreach ($r['subs'] as $s => $v) {
            $out .= implode(',', [
                ACC_CATEGORY_LABELS[$r['category']] ?? '未登録', '"' . $r['section'] . '"', '"' . $r['account'] . '"', '"' . $s . '"', $v['open'], $v['dr'], $v['cr'], $v['end'],
            ]) . "\r\n";
        }
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="trial_balance_' . $book['code'] . '_' . $fiscalYear . '.csv"');
    echo $out;
    exit;
}

$company = (string) ($settings['profile']['legal_name'] ?? $settings['profile']['name'] ?? $book['name']);
[$fyFirst, $fyLast] = acc_fy_bounds($book, $fiscalYear);

/** 損益計算書の1区分を表の行にする */
function stmt_rows(array $list, bool $withSub, string $indent = ''): void
{
    foreach ($list as $r) {
        echo '<tr><td>' . $indent . acc_h($r['account']) . '</td><td class="num">' . acc_h(acc_money($r['amount'], true)) . '</td></tr>';
        if ($withSub && count($r['subs']) > 0 && array_keys($r['subs']) !== ['']) {
            foreach ($r['subs'] as $sub => $v) {
                $isDebit = in_array($r['category'], ['asset', 'expense', 'unknown'], true);
                echo '<tr class="subrow"><td>' . $indent . '　　' . acc_h($sub === '' ? '（補助なし）' : (string) $sub) . '</td><td class="num">' . acc_h(acc_money($isDebit ? $v['end'] : -$v['end'], true)) . '</td></tr>';
            }
        }
    }
}

function stmt_total(string $label, int $amount, bool $strong = true): void
{
    echo '<tr class="tot' . ($strong ? ' strong' : '') . '"><td>' . acc_h($label) . '</td><td class="num">' . acc_h(acc_money($amount, true)) . '</td></tr>';
}

acc_render_header($admin, '試算表・決算書', 'statements', $bookId);
?>
<style>
    table.stmt { border-collapse: collapse; width: 100%; max-width: 640px; background: #fff; font-size: 0.95em; }
    table.stmt th { background: #e9d5bd; border: 1px solid #c9ae92; padding: 3px 8px; }
    table.stmt td { border: 1px solid #e3cdb4; padding: 2px 8px; }
    table.stmt td.num { text-align: right; white-space: nowrap; }
    table.stmt tr.head td { background: #f3e1c9; font-weight: bold; }
    table.stmt tr.tot td { background: #faf5ea; font-weight: bold; }
    table.stmt tr.strong td { background: #f3e1c9; }
    table.stmt tr.subrow td { color: #666; font-size: 0.92em; }
    .stmt-wrap { display: flex; flex-wrap: wrap; gap: 24px; align-items: flex-start; }
    .stmt-title { text-align: center; margin: 14px 0 4px; }
    @media print { .yb-menubar, .no-print, .yb-title { display: none !important; } body { background: #fff; } .yb-panel { border: none; background: #fff; } }
</style>
<div class="yb-panel">
    <div class="yb-toolbar no-print">
        <?php acc_render_book_selector($books, $book, '/admin/accounting_statements.php', ['view' => $view, 'sub' => $withSub ? '1' : '']); ?>
        <form method="get" action="/admin/accounting_statements.php" class="inline">
            <input type="hidden" name="book" value="<?= $bookId ?>">
            <label>事業年度: <select name="fy" onchange="this.form.submit()">
                <?php foreach ($yearOptions as $y) : ?><option value="<?= $y ?>"<?= $y === $fiscalYear ? ' selected' : '' ?>><?= $y ?>年<?= (int) $book['fiscal_start_month'] ?>月開始</option><?php endforeach; ?>
            </select></label>
            <label>表示: <select name="view" onchange="this.form.submit()">
                <option value="all"<?= $view === 'all' ? ' selected' : '' ?>>すべて</option>
                <option value="trial"<?= $view === 'trial' ? ' selected' : '' ?>>残高試算表</option>
                <option value="pl"<?= $view === 'pl' ? ' selected' : '' ?>>損益計算書</option>
                <option value="bs"<?= $view === 'bs' ? ' selected' : '' ?>>貸借対照表</option>
            </select></label>
            <label><input type="checkbox" name="sub" value="1"<?= $withSub ? ' checked' : '' ?> onchange="this.form.submit()"> 補助科目も表示</label>
        </form>
        <a href="#" onclick="window.print();return false;">印刷</a>
        <?php if ($balances['ok']) : ?><a href="/admin/accounting_statements.php?<?= acc_h(http_build_query(['book' => $bookId, 'fy' => $fiscalYear, 'csv' => 'trial'])) ?>">試算表をCSVで保存</a><?php endif; ?>
    </div>

<?php if (!$balances['ok']) : ?>
    <p class="notice"><?= acc_h($balances['message']) ?></p>
<?php else : ?>
    <?php if ($balances['unregistered'] !== []) : ?>
        <div class="message error"><strong>勘定科目マスタにない科目があります:</strong> <?= acc_h(implode('、', $balances['unregistered'])) ?>
            — 「設定 → 勘定科目マスタ」で追加すると決算書の正しい位置に出ます（今は「未登録科目」として別に表示しています）。</div>
    <?php endif; ?>
    <?php if ($st['check']['diff'] !== 0) : ?>
        <div class="message error"><strong>貸借が一致していません（資産 − 負債 − 純資産 = <?= acc_h(number_format($st['check']['diff'])) ?>円）。</strong>
            期首残高の不一致、または未登録科目が原因です。</div>
    <?php else : ?>
        <p class="no-print"><span class="badge badge-ok">貸借一致</span>
            <span class="muted">期間 <?= acc_h($fyFirst) ?> 〜 <?= acc_h($fyLast) ?></span></p>
    <?php endif; ?>

    <?php if (in_array($view, ['all', 'trial'], true)) : ?>
        <h3 class="stmt-title"><?= acc_h($company) ?>　残高試算表</h3>
        <p style="text-align:center" class="muted"><?= acc_h($fyFirst) ?> 〜 <?= acc_h($fyLast) ?>（借方残高が正、貸方残高が△）</p>
        <div class="scroll"><table class="stmt" style="max-width:900px">
            <thead><tr><th>勘定科目</th><th>期首残高</th><th>借方</th><th>貸方</th><th>期末残高</th></tr></thead>
            <tbody>
            <?php
            $lastHead = '';
            foreach (acc_trial_rows($balances, $chart) as $r) {
                $head = ACC_CATEGORY_LABELS[$r['category']] ?? '未登録';
                if ($head . $r['section'] !== $lastHead) {
                    echo '<tr class="head"><td colspan="5">' . acc_h($head . '／' . $r['section']) . '</td></tr>';
                    $lastHead = $head . $r['section'];
                }
                echo '<tr><td>' . acc_h($r['account']) . '</td><td class="num">' . acc_h(acc_money($r['open'])) . '</td><td class="num">' . acc_h(acc_money($r['dr'])) . '</td><td class="num">' . acc_h(acc_money($r['cr'])) . '</td><td class="num">' . acc_h(acc_money($r['end'])) . '</td></tr>';
                if ($withSub && array_keys($r['subs']) !== ['']) {
                    foreach ($r['subs'] as $s => $v) {
                        echo '<tr class="subrow"><td>　　' . acc_h($s === '' ? '（補助なし）' : (string) $s) . '</td><td class="num">' . acc_h(acc_money($v['open'])) . '</td><td class="num">' . acc_h(acc_money($v['dr'])) . '</td><td class="num">' . acc_h(acc_money($v['cr'])) . '</td><td class="num">' . acc_h(acc_money($v['end'])) . '</td></tr>';
                    }
                }
            }
            ?>
            </tbody>
        </table></div>
    <?php endif; ?>

    <?php if (in_array($view, ['all', 'pl'], true)) : $pl = $st['pl']; ?>
        <h3 class="stmt-title"><?= acc_h($company) ?>　損益計算書</h3>
        <p style="text-align:center" class="muted"><?= acc_h($fyFirst) ?> 〜 <?= acc_h($fyLast) ?></p>
        <table class="stmt">
            <thead><tr><th>科目</th><th>金額（円）</th></tr></thead>
            <tbody>
            <?php
            echo '<tr class="head"><td colspan="2">売上高</td></tr>';
            stmt_rows($pl['sections']['revenue']['売上高'] ?? [], $withSub, '　');
            stmt_total('売上高合計', $pl['sales']);
            if (isset($pl['sections']['expense']['売上原価'])) {
                echo '<tr class="head"><td colspan="2">売上原価</td></tr>';
                stmt_rows($pl['sections']['expense']['売上原価'], $withSub, '　');
                stmt_total('売上原価合計', $pl['cogs']);
            }
            stmt_total('売上総利益', $pl['gross']);
            echo '<tr class="head"><td colspan="2">販売費及び一般管理費</td></tr>';
            stmt_rows($pl['sections']['expense']['販売費及び一般管理費'] ?? [], $withSub, '　');
            stmt_total('販売費及び一般管理費合計', $pl['sga']);
            stmt_total('営業利益', $pl['operating']);
            if (isset($pl['sections']['revenue']['営業外収益'])) {
                echo '<tr class="head"><td colspan="2">営業外収益</td></tr>';
                stmt_rows($pl['sections']['revenue']['営業外収益'], $withSub, '　');
                stmt_total('営業外収益合計', $pl['non_op_income'], false);
            }
            if (isset($pl['sections']['expense']['営業外費用'])) {
                echo '<tr class="head"><td colspan="2">営業外費用</td></tr>';
                stmt_rows($pl['sections']['expense']['営業外費用'], $withSub, '　');
                stmt_total('営業外費用合計', $pl['non_op_expense'], false);
            }
            stmt_total('経常利益', $pl['ordinary']);
            if (isset($pl['sections']['revenue']['特別利益'])) {
                echo '<tr class="head"><td colspan="2">特別利益</td></tr>';
                stmt_rows($pl['sections']['revenue']['特別利益'], $withSub, '　');
                stmt_total('特別利益合計', $pl['special_gain'], false);
            }
            if (isset($pl['sections']['expense']['特別損失'])) {
                echo '<tr class="head"><td colspan="2">特別損失</td></tr>';
                stmt_rows($pl['sections']['expense']['特別損失'], $withSub, '　');
                stmt_total('特別損失合計', $pl['special_loss'], false);
            }
            stmt_total('税引前当期純利益', $pl['before_tax']);
            if (isset($pl['sections']['expense']['法人税等'])) {
                stmt_rows($pl['sections']['expense']['法人税等'], false);
            }
            stmt_total('当期純利益', $pl['net']);
            ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if (in_array($view, ['all', 'bs'], true)) : $bs = $st['bs']; ?>
        <h3 class="stmt-title"><?= acc_h($company) ?>　貸借対照表</h3>
        <p style="text-align:center" class="muted"><?= acc_h($fyLast) ?> 現在</p>
        <div class="stmt-wrap">
            <table class="stmt" style="max-width:420px">
                <thead><tr><th>資産の部</th><th>金額（円）</th></tr></thead>
                <tbody>
                <?php
                foreach (ACC_SECTIONS['asset'] as $sec) {
                    if (!isset($bs['asset'][$sec])) {
                        continue;
                    }
                    echo '<tr class="head"><td colspan="2">' . acc_h($sec) . '</td></tr>';
                    stmt_rows($bs['asset'][$sec], $withSub, '　');
                    stmt_total($sec . '合計', array_sum(array_column($bs['asset'][$sec], 'amount')), false);
                }
                stmt_total('資産合計', $bs['asset_total']);
                ?>
                </tbody>
            </table>
            <table class="stmt" style="max-width:420px">
                <thead><tr><th>負債・純資産の部</th><th>金額（円）</th></tr></thead>
                <tbody>
                <?php
                foreach (ACC_SECTIONS['liability'] as $sec) {
                    if (!isset($bs['liability'][$sec])) {
                        continue;
                    }
                    echo '<tr class="head"><td colspan="2">' . acc_h($sec) . '</td></tr>';
                    stmt_rows($bs['liability'][$sec], $withSub, '　');
                    stmt_total($sec . '合計', array_sum(array_column($bs['liability'][$sec], 'amount')), false);
                }
                stmt_total('負債合計', $bs['liability_total']);
                echo '<tr class="head"><td colspan="2">純資産</td></tr>';
                foreach (ACC_SECTIONS['equity'] as $sec) {
                    if (!isset($bs['equity'][$sec])) {
                        continue;
                    }
                    stmt_rows($bs['equity'][$sec], $withSub, '　');
                }
                echo '<tr><td>　当期純利益</td><td class="num">' . acc_h(acc_money($bs['net'], true)) . '</td></tr>';
                stmt_total('純資産合計', $bs['equity_total']);
                stmt_total('負債・純資産合計', $bs['liability_total'] + $bs['equity_total']);
                ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($st['check']['unknown'] !== []) : ?>
        <h3>未登録科目（決算書には含めていません）</h3>
        <table class="stmt"><tbody>
        <?php foreach ($st['check']['unknown'] as $sec => $list) { stmt_rows($list, false); } ?>
        </tbody></table>
    <?php endif; ?>
<?php endif; ?>
</div>
<?php acc_render_footer(); ?>
