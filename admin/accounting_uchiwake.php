<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/accounting_uchiwake.php';

$admin = require_login('admin');
$pdo = getPdo();

/**
 * 勘定科目内訳書。決算書の科目ごとに、補助科目（取引先・金融機関）別の期末残高を表にする。
 * 取引先の所在地・口座番号・借入金の利率などの補足情報は、この画面で入力して保存する（次の年度にも引き継ぐ）。
 */

[$book, $books] = acc_select_book($pdo);
$bookId = (int) $book['id'];
$fiscalYear = (int) ($_GET['fy'] ?? $_POST['fy'] ?? acc_current_fiscal_year($book));
$code = (string) ($_GET['form'] ?? $_POST['form'] ?? 'uc01');
if (!isset(ACC_UCHIWAKE_FORMS[$code])) {
    $code = 'uc01';
}
$form = ACC_UCHIWAKE_FORMS[$code];
$self = '/admin/accounting_uchiwake.php?' . http_build_query(['book' => $bookId, 'fy' => $fiscalYear, 'form' => $code]);
$chart = acc_chart_map($pdo, $book);
$balances = acc_balances($pdo, $book, $fiscalYear);
$settings = acc_book_settings($pdo, $bookId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('error', '不正なリクエストです。画面を再読み込みしてやり直してください。');
    } else {
        $n = 0;
        foreach ((array) ($_POST['rows'] ?? []) as $r) {
            $account = acc_clean_text((string) ($r['account'] ?? ''));
            $sub = acc_clean_text((string) ($r['sub'] ?? ''));
            if ($account === '') {
                continue;
            }
            $allowed = array_merge(array_keys($form['fields']), ['memo']);
            $data = [];
            foreach ($allowed as $k) {
                $data[$k] = (string) ($r['info'][$k] ?? '');
            }
            acc_sub_info_save($pdo, $bookId, $code, $account, $sub, $data);
            $n++;
        }
        set_flash('success', $n . '行の補足情報を保存しました。');
    }
    header('Location: ' . $self);
    exit;
}

$flash = pop_flash();
$yearOptions = range(acc_current_fiscal_year($book) + 1, acc_current_fiscal_year($book) - 5);
$company = (string) ($settings['profile']['legal_name'] ?? $book['name']);
$built = $balances['ok'] ? acc_uchiwake_build($pdo, $book, $balances, $chart, $code, $fiscalYear) : null;

acc_render_header($admin, '勘定科目内訳書', 'uchiwake', $bookId);
acc_render_messages($flash);
?>
<style>
    table.uc { border-collapse: collapse; width: 100%; background: #fff; font-size: 0.93em; }
    table.uc th { background: #e9d5bd; border: 1px solid #c9ae92; padding: 3px 6px; font-weight: normal; }
    table.uc td { border: 1px solid #d8c4a8; padding: 2px 6px; }
    table.uc td.num { text-align: right; white-space: nowrap; }
    table.uc input[type=text] { width: 100%; box-sizing: border-box; border: none; background: #fffbe8; padding: 2px; }
    .uc-tabs a { display: inline-block; padding: 3px 8px; margin: 0 2px 4px 0; border: 1px solid #c58a6e; background: #f3e1c9; color: #6a3b22; text-decoration: none; font-size: 0.88em; }
    .uc-tabs a.active { background: #fff; font-weight: bold; color: #222; }
    @media print { .yb-menubar, .no-print, .yb-title { display: none !important; } table.uc input[type=text] { background: none; } .yb-panel { border: none; background: #fff; } }
</style>
<div class="yb-panel">
    <div class="yb-toolbar no-print">
        <?php acc_render_book_selector($books, $book, '/admin/accounting_uchiwake.php', ['fy' => $fiscalYear, 'form' => $code]); ?>
        <form method="get" action="/admin/accounting_uchiwake.php" class="inline">
            <input type="hidden" name="book" value="<?= $bookId ?>"><input type="hidden" name="form" value="<?= acc_h($code) ?>">
            <label>事業年度: <select name="fy" onchange="this.form.submit()">
                <?php foreach ($yearOptions as $y) : ?><option value="<?= $y ?>"<?= $y === $fiscalYear ? ' selected' : '' ?>><?= $y ?>年<?= (int) $book['fiscal_start_month'] ?>月開始</option><?php endforeach; ?>
            </select></label>
        </form>
        <a href="#" onclick="window.print();return false;">印刷</a>
    </div>
    <div class="uc-tabs no-print">
        <?php foreach (ACC_UCHIWAKE_FORMS as $c => $f) : ?>
            <a href="/admin/accounting_uchiwake.php?<?= acc_h(http_build_query(['book' => $bookId, 'fy' => $fiscalYear, 'form' => $c])) ?>" class="<?= $c === $code ? 'active' : '' ?>"><?= acc_h($f['no']) ?> <?= acc_h(mb_substr($f['title'], 0, 7)) ?></a>
        <?php endforeach; ?>
    </div>

<?php if (!$balances['ok']) : ?>
    <p class="notice"><?= acc_h($balances['message']) ?></p>
<?php else : ?>
    <h3><?= acc_h($form['no']) ?> <?= acc_h($form['title']) ?></h3>
    <p class="muted"><?= acc_h($company) ?>　事業年度 <?= acc_h($balances['first']) ?> 〜 <?= acc_h($balances['last']) ?></p>
    <?php foreach ($built['notes'] as $n) : ?><p class="notice"><?= acc_h($n) ?></p><?php endforeach; ?>
    <form method="post" action="<?= acc_h($self) ?>">
        <input type="hidden" name="csrf_token" value="<?= acc_h(csrf_token()) ?>">
        <input type="hidden" name="form" value="<?= acc_h($code) ?>">
        <input type="hidden" name="fy" value="<?= $fiscalYear ?>">
        <div class="scroll"><table class="uc">
            <thead><tr>
                <th>科目</th><th><?= acc_h($form['name']) ?></th>
                <?php foreach ($form['fields'] as $label) : ?><th><?= acc_h($label) ?></th><?php endforeach; ?>
                <?php if (($form['extra'] ?? '') === 'assets') : ?><th>取得年月日</th><th>数量</th><th>取得価額</th><?php endif; ?>
                <th><?= $code === 'uc14' ? '報酬額' : ($code === 'uc15' || $code === 'uc16' ? '金額' : '期末現在高') ?></th>
                <?php if (($form['extra'] ?? '') === 'interest_out') : ?><th>期中の支払利子額</th><?php endif; ?>
                <?php if (($form['extra'] ?? '') === 'interest_in') : ?><th>期中の受取利息額</th><?php endif; ?>
                <th><?= acc_h($form['memo']) ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($built['rows'] as $i => $r) : ?>
                <tr>
                    <td><?= acc_h($r['account']) ?>
                        <input type="hidden" name="rows[<?= $i ?>][account]" value="<?= acc_h($r['account']) ?>"><input type="hidden" name="rows[<?= $i ?>][sub]" value="<?= acc_h($r['sub']) ?>"></td>
                    <td><?= acc_h($r['name']) ?></td>
                    <?php foreach ($form['fields'] as $k => $label) : ?>
                        <td><input type="text" name="rows[<?= $i ?>][info][<?= acc_h($k) ?>]" value="<?= acc_h((string) ($r['info'][$k] ?? '')) ?>"></td>
                    <?php endforeach; ?>
                    <?php if (($form['extra'] ?? '') === 'assets') : ?><td><?= acc_h($r['acquired']) ?></td><td class="num"><?= (int) $r['qty'] ?></td><td class="num"><?= number_format((int) $r['amount2']) ?></td><?php endif; ?>
                    <td class="num"><?= number_format($r['amount']) ?></td>
                    <?php if (in_array($form['extra'] ?? '', ['interest_out', 'interest_in'], true)) : ?><td class="num"><?= number_format((int) $r['amount2']) ?></td><?php endif; ?>
                    <td><input type="text" name="rows[<?= $i ?>][info][memo]" value="<?= acc_h((string) ($r['info']['memo'] ?? '')) ?>"></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($built['rows'] === []) : ?><tr><td colspan="12" class="muted">該当する残高はありません。</td></tr><?php endif; ?>
            <tr style="font-weight:bold"><td>合計</td><td></td>
                <?php foreach ($form['fields'] as $k => $label) : ?><td></td><?php endforeach; ?>
                <?php if (($form['extra'] ?? '') === 'assets') : ?><td></td><td></td><td class="num"><?= number_format(array_sum(array_column($built['rows'], 'amount2'))) ?></td><?php endif; ?>
                <td class="num"><?= number_format($built['total']) ?></td>
                <?php if (in_array($form['extra'] ?? '', ['interest_out', 'interest_in'], true)) : ?><td class="num"><?= number_format($built['total2']) ?></td><?php endif; ?>
                <td></td></tr>
            </tbody>
        </table></div>
        <?php if (!empty($built['people'])) : ?>
            <h4>人件費の科目別合計（参考）</h4>
            <table class="uc" style="max-width:420px"><tbody>
            <?php foreach ($built['people'] as $p) : ?><tr><td><?= acc_h($p['name']) ?></td><td class="num"><?= number_format($p['amount']) ?></td></tr><?php endforeach; ?>
            </tbody></table>
        <?php endif; ?>
        <?php if ($built['rows'] !== [] && $form['fields'] !== [] || $built['rows'] !== []) : ?>
            <p class="no-print"><button type="submit" class="primary">補足情報を保存</button>
                <span class="muted">（金額は元帳から自動で集計されます。入力欄は相手先の所在地などの補足情報です）</span></p>
        <?php endif; ?>
    </form>
    <?php
    // 決算書との突き合わせ（内訳書の合計 = 該当科目の期末残高）
    if (in_array($code, ['uc01', 'uc03', 'uc09', 'uc10', 'uc11', 'uc08', 'uc02'], true)) {
        $sum = 0;
        foreach ($chart as $account => $c) {
            if (in_array($c['uchiwake'], $form['kinds'], true) && isset($balances['accounts'][$account])) {
                $sum += $form['side'] === 'asset' ? $balances['accounts'][$account]['end'] : -$balances['accounts'][$account]['end'];
            }
        }
        echo '<p class="muted no-print">決算書の該当科目の残高合計: ' . number_format($sum) . '円 ' . ($sum === $built['total'] ? '<span class="badge badge-ok">内訳書と一致</span>' : '<span class="badge badge-diff">差額 ' . number_format($sum - $built['total']) . '円</span>') . '</p>';
    }
    ?>
<?php endif; ?>
</div>
<?php acc_render_footer(); ?>
