<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/accounting_personal.php';

$admin = require_login('admin');
$pdo = getPdo();

/**
 * 税務: 税務設定・消費税申告書・法人税等の申告書（別表一／四／五(一)(二)／七(一)／十五／十六・地方税）。
 * 計算結果は申告書の下書き（検算用）。提出前に税率・経過措置・各別表の記載を確認すること。
 */

[$book, $books] = acc_select_book($pdo);
$bookId = (int) $book['id'];
$fiscalYear = (int) ($_GET['fy'] ?? $_POST['fy'] ?? acc_current_fiscal_year($book));
$tab = (string) ($_GET['tab'] ?? $_POST['tab'] ?? 'settings');
$tabs = ['settings' => '税務設定', 'ct' => '消費税', 'b1' => '別表一', 'b4' => '別表四', 'b5' => '別表五(一)(二)', 'b7' => '別表七(一)', 'b15' => '別表十五', 'b16' => '別表十六', 'local' => '地方税', 'bpl' => '青色申告決算書（損益）', 'bbs' => '青色申告決算書（貸借）', 'ret' => '確定申告書'];
if (!isset($tabs[$tab])) {
    $tab = 'settings';
}
$isCorp = $book['kind'] === 'corporate';
$self = '/admin/accounting_tax.php?' . http_build_query(['book' => $bookId, 'fy' => $fiscalYear, 'tab' => $tab]);
$settings = acc_book_settings($pdo, $bookId);
$company = (string) ($settings['profile']['legal_name'] ?? $book['name']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('error', '不正なリクエストです。画面を再読み込みしてやり直してください。');
    } else {
        $int = static fn ($v): int => (int) preg_replace('/[^\d\-]/', '', (string) $v);
        $data = acc_tax_year_load($pdo, $bookId, $fiscalYear);
        $c = (array) ($_POST['ct'] ?? []);
        $data['consumption'] = array_merge($data['consumption'], [
            'method' => isset(ACC_CT_METHODS[$c['method'] ?? '']) ? $c['method'] : 'general',
            'simple_class' => max(1, min(6, (int) ($c['simple_class'] ?? 5))),
            'interim_national' => $int($c['interim_national'] ?? 0), 'interim_local' => $int($c['interim_local'] ?? 0),
            'book_entry' => empty($c['book_entry']) ? 0 : 1, 'allocation' => ($c['allocation'] ?? '') === 'bulk' ? 'bulk' : 'individual',
        ]);
        if ($isCorp) {
            $p = (array) ($_POST['corp'] ?? []);
            $corp = acc_corp_defaults();
            foreach (array_keys($corp['rates']) as $k) {
                $corp['rates'][$k] = (float) ($p['rates'][$k] ?? $corp['rates'][$k]);
            }
            foreach (array_keys($corp['equal']) as $k) {
                $corp['equal'][$k] = $int($p['equal'][$k] ?? 0);
            }
            foreach (array_keys($corp['interim']) as $k) {
                $corp['interim'][$k] = $int($p['interim'][$k] ?? 0);
            }
            foreach (array_keys($corp['opening_unpaid']) as $k) {
                $corp['opening_unpaid'][$k] = $int($p['opening_unpaid'][$k] ?? 0);
            }
            $corp['tax_credit'] = $int($p['tax_credit'] ?? 0);
            $corp['dividends'] = $int($p['dividends'] ?? 0);
            $corp['entertain']['food_expense'] = $int($p['food_expense'] ?? 0);
            $corp['prior_biz_tax'] = trim((string) ($p['prior_biz_tax'] ?? '')) === '' ? null : $int($p['prior_biz_tax']);
            $corp['book_entry'] = empty($p['book_entry']) ? 0 : 1;
            foreach ((array) ($p['adj'] ?? []) as $a) {
                $label = acc_clean_text((string) ($a['label'] ?? ''));
                $amount = $int($a['amount'] ?? 0);
                if ($label !== '' && $amount !== 0) {
                    $corp['adjustments'][] = ['kind' => ($a['kind'] ?? 'add') === 'sub' ? 'sub' : 'add', 'label' => $label, 'amount' => $amount, 'flow' => ($a['flow'] ?? 'out') === 'retain' ? 'retain' : 'out'];
                }
            }
            foreach ((array) ($p['loss'] ?? []) as $l) {
                if ($int($l['amount'] ?? 0) > 0 && $int($l['fy'] ?? 0) > 0) {
                    $corp['loss_cf'][] = ['fy' => $int($l['fy']), 'amount' => $int($l['amount'])];
                }
            }
            $data['corp'] = $corp;
        }
        if (!$isCorp) {
            $q = (array) ($_POST['pers'] ?? []);
            $pers = acc_personal_defaults();
            $pers['blue_deduction'] = isset(ACC_BLUE_DEDUCTIONS[(int) ($q['blue_deduction'] ?? 0)]) ? (int) $q['blue_deduction'] : 0;
            $pers['expense_target'] = in_array($q['expense_target'] ?? '', ['realestate', 'business'], true) ? $q['expense_target'] : 'auto';
            $pers['salary'] = ['income' => $int($q['salary_income'] ?? 0), 'withheld' => $int($q['salary_withheld'] ?? 0)];
            foreach (['social_insurance', 'small_biz_mutual', 'tax_credits', 'prepaid', 'other_withheld'] as $k) {
                $pers[$k] = $int($q[$k] ?? 0);
            }
            foreach ((array) ($q['ded'] ?? []) as $o) {
                $label = acc_clean_text((string) ($o['label'] ?? ''));
                if ($label !== '' && $int($o['amount'] ?? 0) > 0) {
                    $pers['other_deductions'][] = ['label' => $label, 'amount' => $int($o['amount'])];
                }
            }
            $data['personal'] = $pers;
        }
        acc_tax_year_save($pdo, $bookId, $fiscalYear, $data);
        set_flash('success', $fiscalYear . '年度の税務設定を保存しました。');
    }
    header('Location: ' . $self);
    exit;
}

$flash = pop_flash();
$curYear = acc_current_fiscal_year($book);
$yearOptions = range($curYear + 1, $curYear - 5);
$headerKey = $tab === 'settings' ? 'tax_settings' : ($tab === 'ct' ? 'tax_ct' : (in_array($tab, ['bpl', 'bbs', 'ret'], true) ? 'tax_personal' : 'tax_corp'));
$taxYear = acc_tax_year_load($pdo, $bookId, $fiscalYear);
$ctCfg = $taxYear['consumption'];
$corpCfg = acc_corp_cfg($taxYear);
[$first, $last] = acc_fy_bounds($book, $fiscalYear);
$ready = $settings['opening_fy'] !== null;

$ct = $calc = $sch5 = $register = $balances = $pc = $st = null;
if ($ready && $tab !== 'settings') {
    $gen = acc_generate_all($pdo, $book, substr($last, 0, 7));
    $entries = $gen['entries'];
    $chart = acc_chart_map($pdo, $book);
    if ($tab === 'ct') {
        $ct = acc_consumption_calc(acc_consumption_aggregate($entries, $first, $last), $ctCfg);
    } elseif (!$isCorp) {
        $balances = acc_balances($pdo, $book, $fiscalYear);
        if ($balances['ok']) {
            $pc = acc_personal_calc($pdo, $book, $fiscalYear, $balances, $chart);
            $st = acc_statements($balances, $chart);
        }
    } elseif ($isCorp) {
        $calc = acc_corp_calc($pdo, $book, $fiscalYear, $entries, $chart);
        if ($tab === 'b5') {
            $balances = acc_balances($pdo, $book, $fiscalYear);
            $sch5 = $balances['ok'] ? acc_corp_schedule5($calc, $balances, $chart) : null;
        }
        if ($tab === 'b16') {
            $register = acc_asset_register($pdo, $book, $fiscalYear);
        }
    }
}
$y = static fn (int $n): string => $n < 0 ? '△' . number_format(-$n) : number_format($n);

acc_render_header($admin, '税務', $headerKey, $bookId);
acc_render_messages($flash);
?>
<style>
    table.tx { border-collapse: collapse; background: #fff; font-size: 0.93em; margin-bottom: 10px; }
    table.tx th { background: #e9d5bd; border: 1px solid #c9ae92; padding: 3px 8px; font-weight: normal; }
    table.tx td { border: 1px solid #d8c4a8; padding: 2px 8px; }
    table.tx td.num { text-align: right; white-space: nowrap; }
    table.tx td.no { color: #888; text-align: center; width: 2.5em; }
    table.tx tr.sum td { font-weight: bold; background: #faf3e6; }
    .uc-tabs a { display: inline-block; padding: 3px 8px; margin: 0 2px 4px 0; border: 1px solid #c58a6e; background: #f3e1c9; color: #6a3b22; text-decoration: none; font-size: 0.88em; }
    .uc-tabs a.active { background: #fff; font-weight: bold; color: #222; }
    .tx-form input[type=text], .tx-form select { padding: 2px 4px; }
    .tx-form input.n { width: 9em; text-align: right; }
    .tx-form input.r { width: 4.5em; text-align: right; }
    @media print { .yb-menubar, .no-print, .yb-title { display: none !important; } .yb-panel { border: none; background: #fff; } }
</style>
<div class="yb-panel">
    <div class="yb-toolbar no-print">
        <?php acc_render_book_selector($books, $book, '/admin/accounting_tax.php', ['fy' => $fiscalYear, 'tab' => $tab]); ?>
        <form method="get" action="/admin/accounting_tax.php" class="inline">
            <input type="hidden" name="book" value="<?= $bookId ?>"><input type="hidden" name="tab" value="<?= acc_h($tab) ?>">
            <label>事業年度: <select name="fy" onchange="this.form.submit()">
                <?php foreach ($yearOptions as $yy) : ?><option value="<?= $yy ?>"<?= $yy === $fiscalYear ? ' selected' : '' ?>><?= $yy ?>年<?= (int) $book['fiscal_start_month'] ?>月開始</option><?php endforeach; ?>
            </select></label>
        </form>
        <a href="#" onclick="window.print();return false;">印刷</a>
    </div>
    <div class="uc-tabs no-print">
        <?php foreach ($tabs as $k => $label) : if (($isCorp && in_array($k, ['bpl', 'bbs', 'ret'], true)) || (!$isCorp && !in_array($k, ['settings', 'ct', 'bpl', 'bbs', 'ret'], true))) { continue; } ?>
            <a href="/admin/accounting_tax.php?<?= acc_h(http_build_query(['book' => $bookId, 'fy' => $fiscalYear, 'tab' => $k])) ?>" class="<?= $k === $tab ? 'active' : '' ?>"><?= acc_h($label) ?></a>
        <?php endforeach; ?>
    </div>
    <p class="notice">この画面の数字は申告書の下書き（検算用）です。税率・経過措置・各別表の記載は提出前に必ず確認してください（税理士または電子申告ソフトでの確認を推奨）。</p>
<?php if (!$ready) : ?>
    <p class="notice">先に「期首残高・基本情報」で記帳開始年度を設定してください。</p>
<?php elseif ($tab === 'settings') : ?>
    <h3><?= acc_h($company) ?>　<?= $fiscalYear ?>年度（<?= acc_h($first) ?> 〜 <?= acc_h($last) ?>）の税務設定</h3>
    <form method="post" action="<?= acc_h($self) ?>" class="tx-form">
        <input type="hidden" name="csrf_token" value="<?= acc_h(csrf_token()) ?>"><input type="hidden" name="fy" value="<?= $fiscalYear ?>"><input type="hidden" name="tab" value="settings">
        <h4>消費税</h4>
        <table class="tx">
            <tr><th>申告方式</th><td><select name="ct[method]"><?php foreach (ACC_CT_METHODS as $k => $l) : ?><option value="<?= $k ?>"<?= $ctCfg['method'] === $k ? ' selected' : '' ?>><?= acc_h($l) ?></option><?php endforeach; ?></select></td></tr>
            <tr><th>簡易課税の事業区分</th><td><select name="ct[simple_class]"><?php foreach (ACC_SIMPLE_LABELS as $k => $l) : ?><option value="<?= $k ?>"<?= (int) $ctCfg['simple_class'] === $k ? ' selected' : '' ?>><?= acc_h($l) ?>（<?= ACC_SIMPLE_RATES[$k] ?>%）</option><?php endforeach; ?></select></td></tr>
            <tr><th>中間納付（国税）</th><td><input type="text" class="n" name="ct[interim_national]" value="<?= (int) $ctCfg['interim_national'] ?>"> 円</td></tr>
            <tr><th>中間納付（地方消費税）</th><td><input type="text" class="n" name="ct[interim_local]" value="<?= (int) $ctCfg['interim_local'] ?>"> 円</td></tr>
            <tr><th>課税売上割合95%未満（または課税売上5億円超）の方式</th><td><select name="ct[allocation]"><option value="individual"<?= ($ctCfg['allocation'] ?? 'individual') !== 'bulk' ? ' selected' : '' ?>>個別対応方式</option><option value="bulk"<?= ($ctCfg['allocation'] ?? '') === 'bulk' ? ' selected' : '' ?>>一括比例配分方式</option></select>　<span class="muted">個別対応は、仕入の税区分を「課対／共対／非対」で付けます（共対・非対の区分を使っていない仕入は課税売上対応として扱います）</span></td></tr>
            <tr><th>決算仕訳</th><td><label><input type="checkbox" name="ct[book_entry]" value="1"<?= !empty($ctCfg['book_entry']) ? ' checked' : '' ?>> 年度末に納付（還付）見込額を仕訳として自動計上する（租税公課 / 未払消費税等）</label></td></tr>
        </table>
<?php if ($isCorp) : ?>
        <h4>法人税等の税率</h4>
        <table class="tx">
            <?php $rl = ['corp_low' => '法人税 軽減税率（年800万円以下）', 'corp_high' => '法人税 標準税率（超える部分）', 'local_corp' => '地方法人税', 'defense' => '防衛特別法人税（令和8年4月1日以後開始の事業年度。基礎控除年500万円）', 'pref' => '道府県民税 法人税割', 'city' => '市町村民税 法人税割', 'biz1' => '事業税（年400万円以下）', 'biz2' => '事業税（400万円超〜800万円）', 'biz3' => '事業税（800万円超）', 'special_biz' => '特別法人事業税（事業税所得割に対する率）']; ?>
            <?php foreach ($rl as $k => $l) : ?><tr><th><?= acc_h($l) ?></th><td><input type="text" class="r" name="corp[rates][<?= $k ?>]" value="<?= acc_h((string) $corpCfg['rates'][$k]) ?>"> %</td></tr><?php endforeach; ?>
            <tr><th>均等割（年額）道府県</th><td><input type="text" class="n" name="corp[equal][pref]" value="<?= (int) $corpCfg['equal']['pref'] ?>"> 円</td></tr>
            <tr><th>均等割（年額）市町村</th><td><input type="text" class="n" name="corp[equal][city]" value="<?= (int) $corpCfg['equal']['city'] ?>"> 円</td></tr>
        </table>
        <p class="muted">税率は初期値です。所在地の条例（超過課税など）に合わせて変更してください。</p>
        <h4>中間納付額・その他</h4>
        <table class="tx">
            <?php $il = ['corp' => '法人税', 'local_corp' => '地方法人税', 'defense' => '防衛特別法人税', 'pref' => '道府県民税', 'city' => '市町村民税', 'biz' => '事業税', 'special_biz' => '特別法人事業税']; ?>
            <?php foreach ($il as $k => $l) : ?><tr><th>中間納付 <?= acc_h($l) ?></th><td><input type="text" class="n" name="corp[interim][<?= $k ?>]" value="<?= (int) $corpCfg['interim'][$k] ?>"> 円</td></tr><?php endforeach; ?>
            <tr><th>所得税額控除など税額控除</th><td><input type="text" class="n" name="corp[tax_credit]" value="<?= (int) $corpCfg['tax_credit'] ?>"> 円</td></tr>
            <tr><th>交際費のうち飲食費（社内飲食費を除く）</th><td><input type="text" class="n" name="corp[food_expense]" value="<?= (int) $corpCfg['entertain']['food_expense'] ?>"> 円（50%基準と比べて有利な方を適用）</td></tr>
            <tr><th>前期に計上した事業税等（認容額）</th><td><input type="text" class="n" name="corp[prior_biz_tax]" value="<?= $corpCfg['prior_biz_tax'] === null ? '' : (int) $corpCfg['prior_biz_tax'] ?>"> 円（空欄＝前期の計算結果。記帳開始年度は前期末の未納事業税）</td></tr>
            <tr><th>剰余金の配当（当期）</th><td><input type="text" class="n" name="corp[dividends]" value="<?= (int) $corpCfg['dividends'] ?>"> 円（別表五(一)の繰越損益金に反映）</td></tr>
            <?php foreach (['corp' => '前期末の未納 法人税等（地方法人税含む）', 'pref' => '前期末の未納 道府県民税', 'city' => '前期末の未納 市町村民税', 'biz' => '前期末の未納 事業税等'] as $k => $l) : ?>
                <tr><th><?= acc_h($l) ?>（記帳開始年度のみ）</th><td><input type="text" class="n" name="corp[opening_unpaid][<?= $k ?>]" value="<?= (int) $corpCfg['opening_unpaid'][$k] ?>"> 円</td></tr>
            <?php endforeach; ?>
            <tr><th>決算仕訳</th><td><label><input type="checkbox" name="corp[book_entry]" value="1"<?= !empty($corpCfg['book_entry']) ? ' checked' : '' ?>> 年度末に法人税等（法人税・住民税・事業税）を自動計上する（法人税等 / 未払法人税等）</label></td></tr>
        </table>
        <h4>別表四の調整（減価償却超過額・役員給与の損金不算入など）</h4>
        <table class="tx">
            <tr><th>加算／減算</th><th>項目</th><th>金額</th><th>処分</th></tr>
            <?php $adjRows = array_merge((array) $corpCfg['adjustments'], [['kind' => 'add'], ['kind' => 'add'], ['kind' => 'add']]); foreach ($adjRows as $i => $a) : ?>
                <tr><td><select name="corp[adj][<?= $i ?>][kind]"><option value="add"<?= ($a['kind'] ?? 'add') === 'add' ? ' selected' : '' ?>>加算</option><option value="sub"<?= ($a['kind'] ?? '') === 'sub' ? ' selected' : '' ?>>減算</option></select></td>
                    <td><input type="text" name="corp[adj][<?= $i ?>][label]" value="<?= acc_h((string) ($a['label'] ?? '')) ?>" size="34"></td>
                    <td><input type="text" class="n" name="corp[adj][<?= $i ?>][amount]" value="<?= isset($a['amount']) ? (int) $a['amount'] : '' ?>"></td>
                    <td><select name="corp[adj][<?= $i ?>][flow]"><option value="out"<?= ($a['flow'] ?? 'out') === 'out' ? ' selected' : '' ?>>社外流出</option><option value="retain"<?= ($a['flow'] ?? '') === 'retain' ? ' selected' : '' ?>>留保</option></select></td></tr>
            <?php endforeach; ?>
        </table>
        <p class="muted">保存すると金額が空の行は消えます。「留保」にした項目は別表五(一)に載ります。</p>
        <h4>青色欠損金の繰越し（別表七(一)）</h4>
        <table class="tx">
            <tr><th>欠損金が生じた事業年度（開始年）</th><th>繰越前の欠損金額</th></tr>
            <?php $lossRows = array_merge((array) $corpCfg['loss_cf'], [[], []]); foreach ($lossRows as $i => $l) : ?>
                <tr><td><input type="text" class="r" name="corp[loss][<?= $i ?>][fy]" value="<?= isset($l['fy']) ? (int) $l['fy'] : '' ?>"></td><td><input type="text" class="n" name="corp[loss][<?= $i ?>][amount]" value="<?= isset($l['amount']) ? (int) $l['amount'] : '' ?>"></td></tr>
            <?php endforeach; ?>
        </table>
        <p class="muted">この事業年度より前に生じた欠損金の「この事業年度の期首残額」を入力します（10年を超えたものは自動で除外）。</p>
<?php else : $pc0 = acc_personal_cfg($taxYear); ?>
        <h4>所得税（個人）</h4>
        <table class="tx">
            <tr><th>青色申告特別控除</th><td><select name="pers[blue_deduction]"><?php foreach (ACC_BLUE_DEDUCTIONS as $k => $l) : ?><option value="<?= $k ?>"<?= (int) $pc0['blue_deduction'] === $k ? ' selected' : '' ?>><?= acc_h($l) ?></option><?php endforeach; ?></select>　<span class="muted">65万円は複式簿記＋e-Tax提出など、55万円は書面提出など（不動産所得は事業的規模の場合）。それ以外は10万円</span></td></tr>
            <tr><th>経費の帰属</th><td><select name="pers[expense_target]"><?php foreach (['auto' => '自動（家賃収入があれば不動産所得）', 'realestate' => '不動産所得', 'business' => '事業所得'] as $k => $l) : ?><option value="<?= $k ?>"<?= $pc0['expense_target'] === $k ? ' selected' : '' ?>><?= acc_h($l) ?></option><?php endforeach; ?></select>　<span class="muted">「暗号資産」で始まる経費は雑所得に入ります</span></td></tr>
            <tr><th>給与所得（源泉徴収票の「給与所得控除後の金額」）</th><td><input type="text" class="n" name="pers[salary_income]" value="<?= (int) $pc0['salary']['income'] ?>"> 円</td></tr>
            <tr><th>給与の源泉徴収税額</th><td><input type="text" class="n" name="pers[salary_withheld]" value="<?= (int) $pc0['salary']['withheld'] ?>"> 円</td></tr>
            <tr><th>社会保険料控除（国保・年金・給与天引き分の合計）</th><td><input type="text" class="n" name="pers[social_insurance]" value="<?= (int) $pc0['social_insurance'] ?>"> 円</td></tr>
            <tr><th>小規模企業共済等掛金控除</th><td><input type="text" class="n" name="pers[small_biz_mutual]" value="<?= (int) $pc0['small_biz_mutual'] ?>"> 円</td></tr>
            <tr><th>税額控除（住宅借入金等特別控除など）</th><td><input type="text" class="n" name="pers[tax_credits]" value="<?= (int) $pc0['tax_credits'] ?>"> 円</td></tr>
            <tr><th>給与以外の源泉徴収税額</th><td><input type="text" class="n" name="pers[other_withheld]" value="<?= (int) $pc0['other_withheld'] ?>"> 円</td></tr>
            <tr><th>予定納税額</th><td><input type="text" class="n" name="pers[prepaid]" value="<?= (int) $pc0['prepaid'] ?>"> 円</td></tr>
        </table>
        <h4>その他の所得控除（配偶者・扶養・生命保険料・医療費・寄附金など。金額は自分で計算して入力）</h4>
        <table class="tx"><tr><th>控除の名称</th><th>控除額</th></tr>
            <?php foreach (array_merge((array) $pc0['other_deductions'], [[], [], []]) as $i => $o) : ?>
                <tr><td><input type="text" name="pers[ded][<?= $i ?>][label]" value="<?= acc_h((string) ($o['label'] ?? '')) ?>" size="30"></td><td><input type="text" class="n" name="pers[ded][<?= $i ?>][amount]" value="<?= isset($o['amount']) ? (int) $o['amount'] : '' ?>"></td></tr>
            <?php endforeach; ?>
        </table>
        <p class="muted">基礎控除は事業年度（暦年）と合計所得金額から自動で計算します。</p>
<?php endif; ?>
        <p><button type="submit" class="primary">保存</button></p>
    </form>

<?php elseif ($tab === 'ct') : ?>
    <h3><?= acc_h($company) ?>　消費税及び地方消費税の申告書（<?= acc_h($first) ?> 〜 <?= acc_h($last) ?>）　<?= acc_h(ACC_CT_METHODS[$ct['method']]) ?></h3>
    <?php if ($ct['method'] === 'none') : ?><p class="notice">免税事業者（申告なし）に設定されています。</p><?php else : ?>
    <table class="tx">
        <tr><th></th><th>項目</th><th>金額（円）</th></tr>
        <tr><td class="no">①</td><td>課税標準額（10%分 <?= number_format($ct['base10']) ?> ＋ 軽減8%分 <?= number_format($ct['base8']) ?>）</td><td class="num"><?= number_format($ct['base_total']) ?></td></tr>
        <tr><td class="no">②</td><td>消費税額（10%分 <?= number_format($ct['tax10']) ?> ＋ 軽減8%分 <?= number_format($ct['tax8']) ?>）</td><td class="num"><?= number_format($ct['sales_tax']) ?></td></tr>
        <tr><td class="no">④</td><td>控除対象仕入税額</td><td class="num"><?= number_format($ct['deduction']) ?></td></tr>
        <tr><td class="no">⑨</td><td>差引税額<?= $ct['refund'] > 0 ? '（還付 ' . number_format($ct['refund']) . '）' : '' ?></td><td class="num"><?= number_format($ct['diff']) ?></td></tr>
        <tr><td class="no">⑩</td><td>中間納付税額</td><td class="num"><?= number_format($ct['interim_national']) ?></td></tr>
        <tr class="sum"><td class="no">⑪</td><td>納付税額（国税）<?= $ct['payable_national'] < 0 ? '（中間納付の還付）' : '' ?></td><td class="num"><?= $y($ct['payable_national']) ?></td></tr>
        <tr><td class="no">㉖</td><td>譲渡割額（地方消費税。⑨×22/78）</td><td class="num"><?= number_format($ct['local_amount']) ?></td></tr>
        <tr><td class="no">㉗</td><td>中間納付譲渡割額</td><td class="num"><?= number_format($ct['interim_local']) ?></td></tr>
        <tr class="sum"><td class="no">㉘</td><td>納付譲渡割額（地方消費税）</td><td class="num"><?= $y($ct['payable_local']) ?></td></tr>
        <tr class="sum"><td class="no"></td><td>合計納付税額（国税＋地方消費税）</td><td class="num"><?= $y($ct['total_payable']) ?></td></tr>
    </table>
    <h4>控除対象仕入税額の内訳</h4>
    <table class="tx"><tr><th>区分</th><th>対象額（税込）</th><th>税額</th></tr>
        <?php foreach ($ct['detail'] as $d) : ?><tr><td><?= acc_h($d['label']) ?></td><td class="num"><?= number_format((int) $d['base']) ?></td><td class="num"><?= number_format((int) $d['tax']) ?></td></tr><?php endforeach; ?>
    </table>
    <h4>参考: 集計</h4>
    <table class="tx">
        <tr><td>課税売上（10%・税込）</td><td class="num"><?= number_format($ct['agg']['sales10']) ?></td><td>課税売上（軽減8%・税込）</td><td class="num"><?= number_format($ct['agg']['sales8']) ?></td></tr>
        <tr><td>非課税売上</td><td class="num"><?= number_format($ct['nontax_sales']) ?></td><td>課税売上割合</td><td class="num"><?= number_format($ct['ratio_pct'], 2) ?>%</td></tr>
        <tr><td>課税仕入（適格・10%）</td><td class="num"><?= number_format($ct['agg']['purch10']) ?></td><td>課税仕入（適格・軽減8%）</td><td class="num"><?= number_format($ct['agg']['purch8']) ?></td></tr>
        <tr><td>共通対応の課税仕入（税込）</td><td class="num"><?= number_format($ct['agg']['common']['purch10'] + $ct['agg']['common']['purch8'] + $ct['agg']['common']['purch10_50'] + array_sum($ct['agg']['common']['purch_nq'])) ?></td><td>非課税売上対応の課税仕入（税込）</td><td class="num"><?= number_format($ct['agg']['nonuse']['purch10'] + $ct['agg']['nonuse']['purch8'] + $ct['agg']['nonuse']['purch10_50'] + array_sum($ct['agg']['nonuse']['purch_nq'])) ?></td></tr>
        <tr><td>課税仕入（適格請求書なし）</td><td class="num"><?= number_format($ct['agg']['purch_nq_total']) ?></td><td>非課税・不課税の仕入</td><td class="num"><?= number_format($ct['agg']['nontax_purch']) ?></td></tr>
    </table>
    <p class="muted">仕訳の税区分から集計し、課税標準額は千円未満、差引税額は百円未満を切り捨てています（割戻し計算）。2割特例は課税期間の末日が令和8年9月30日までのものが対象です。経過措置の控除割合は設定済みの表（80%／70%／50%／30%）に基づきます。</p>
    <?php endif; ?>

<?php elseif (!$isCorp && $pc === null) : ?>
    <p class="notice"><?= acc_h((string) ($balances['message'] ?? '集計できませんでした。')) ?></p>
<?php elseif (!$isCorp && $tab === 'bpl') : $names = ['realestate' => '不動産所得', 'business' => '事業所得', 'misc' => '雑所得（暗号資産など）']; ?>
    <h3><?= acc_h($company) ?>　青色申告決算書（損益計算）<?= $fiscalYear ?>年分</h3>
    <?php foreach ($names as $k => $label) : $t = $pc['types'][$k]; if ($t['rev_total'] === 0 && $t['exp_total'] === 0) { continue; } ?>
        <h4><?= acc_h($label) ?></h4>
        <table class="tx"><tr><th>科目</th><th>金額（円）</th></tr>
            <?php foreach ($t['revenue'] as $r) : ?><tr><td>収入: <?= acc_h($r['account']) ?></td><td class="num"><?= $y($r['amount']) ?></td></tr><?php endforeach; ?>
            <tr class="sum"><td>収入金額 計</td><td class="num"><?= $y($t['rev_total']) ?></td></tr>
            <?php foreach ($t['expense'] as $r) : ?><tr><td>経費: <?= acc_h($r['account']) ?></td><td class="num"><?= $y($r['amount']) ?></td></tr><?php endforeach; ?>
            <tr class="sum"><td>必要経費 計</td><td class="num"><?= $y($t['exp_total']) ?></td></tr>
            <tr class="sum"><td>差引金額（青色申告特別控除前の所得金額）</td><td class="num"><?= $y($t['before_blue']) ?></td></tr>
            <?php if ($k !== 'misc') : ?><tr><td>青色申告特別控除額</td><td class="num"><?= $y($pc['blue_used'][$k]) ?></td></tr>
            <tr class="sum"><td><?= acc_h($label) ?>の金額</td><td class="num"><?= $y($t['before_blue'] - $pc['blue_used'][$k]) ?></td></tr><?php endif; ?>
        </table>
    <?php endforeach; ?>
    <?php if ($pc['types']['interest']['rev_total'] !== 0) : ?><p class="muted">受取利息 <?= number_format($pc['types']['interest']['rev_total']) ?>円は利子所得（源泉分離課税で申告不要の想定）として所得に含めていません。</p><?php endif; ?>
    <p class="muted">事業主貸・事業主借や家事按分は帳簿の仕訳に従います。青色申告特別控除は所得の範囲内で適用します（不動産所得は事業的規模でない場合は10万円まで）。</p>

<?php elseif (!$isCorp && $tab === 'bbs') : ?>
    <h3><?= acc_h($company) ?>　青色申告決算書（貸借対照表）<?= $fiscalYear ?>年12月31日現在</h3>
    <?php foreach (['asset' => '資産の部', 'liability' => '負債の部', 'equity' => '資本の部'] as $cat => $label) : ?>
        <table class="tx"><tr><th><?= acc_h($label) ?></th><th>期首</th><th>期末</th></tr>
        <?php foreach (($st['bs'][$cat] ?? $st['bs']['sections'][$cat] ?? []) as $sec => $list) : foreach ($list as $r) : ?>
            <tr><td><?= acc_h($r['account']) ?></td><td class="num"><?= $y((int) ($cat === 'asset' ? $r['open'] : -$r['open'])) ?></td><td class="num"><?= $y((int) $r['amount']) ?></td></tr>
        <?php endforeach; endforeach; ?>
        </table>
    <?php endforeach; ?>
    <p class="muted">青色申告決算書の貸借対照表は、この「貸借対照表」画面（決算書）と同じ数字です。元入金・事業主貸借の整理は「決算書」の貸借対照表で確認してください。</p>

<?php elseif (!$isCorp && $tab === 'ret') : $r = $pc; ?>
    <h3><?= acc_h($company) ?>　所得税の確定申告書（第一表）<?= $fiscalYear ?>年分</h3>
    <table class="tx">
        <tr><th></th><th>項目</th><th>金額（円）</th></tr>
        <tr><td class="no">ア</td><td>事業所得</td><td class="num"><?= $y($r['business']) ?></td></tr>
        <tr><td class="no">ウ</td><td>不動産所得</td><td class="num"><?= $y($r['realestate']) ?></td></tr>
        <tr><td class="no">カ</td><td>給与所得</td><td class="num"><?= $y($r['salary']) ?></td></tr>
        <tr><td class="no">ク</td><td>雑所得（赤字は通算しない）<?= $r['misc_raw'] < 0 ? '　※損失 ' . number_format(-$r['misc_raw']) . '円は切捨て' : '' ?></td><td class="num"><?= $y($r['misc']) ?></td></tr>
        <tr class="sum"><td class="no">⑫</td><td>合計（総所得金額等）</td><td class="num"><?= $y($r['total_income']) ?></td></tr>
        <tr><td class="no">⑬</td><td>社会保険料控除</td><td class="num"><?= $y($r['social']) ?></td></tr>
        <tr><td class="no">⑭</td><td>小規模企業共済等掛金控除</td><td class="num"><?= $y($r['mutual']) ?></td></tr>
        <?php foreach ($r['others'] as $o) : ?><tr><td class="no">　</td><td><?= acc_h($o['label']) ?></td><td class="num"><?= $y($o['amount']) ?></td></tr><?php endforeach; ?>
        <tr><td class="no">⑳</td><td>基礎控除</td><td class="num"><?= $y($r['basic']) ?></td></tr>
        <tr class="sum"><td class="no">㉔</td><td>所得控除の合計</td><td class="num"><?= $y($r['deductions']) ?></td></tr>
        <tr class="sum"><td class="no">㉕</td><td>課税される所得金額（千円未満切捨て）</td><td class="num"><?= $y($r['taxable']) ?></td></tr>
        <tr><td class="no">㉖</td><td>上の㉕に対する税額</td><td class="num"><?= $y($r['tax']) ?></td></tr>
        <tr><td class="no">㉚</td><td>税額控除</td><td class="num"><?= $y($r['credits']) ?></td></tr>
        <tr><td class="no">㉝</td><td>基準所得税額</td><td class="num"><?= $y($r['after_credit']) ?></td></tr>
        <tr><td class="no">㊱</td><td>復興特別所得税額（2.1%）</td><td class="num"><?= $y($r['recon']) ?></td></tr>
        <tr class="sum"><td class="no">㊲</td><td>所得税及び復興特別所得税の額（百円未満切捨て）</td><td class="num"><?= $y($r['sum_tax']) ?></td></tr>
        <tr><td class="no">㊳</td><td>源泉徴収税額（給与＋その他）</td><td class="num"><?= $y($r['withheld']) ?></td></tr>
        <tr><td class="no">㊴</td><td>予定納税額</td><td class="num"><?= $y($r['prepaid']) ?></td></tr>
        <tr class="sum"><td class="no">㊶</td><td>申告納税額（マイナスは還付）</td><td class="num"><?= $y($r['payable']) ?></td></tr>
    </table>
    <p class="muted">総合課税のみの計算です。株式等の申告分離課税・配当控除・医療費控除の明細・住宅ローン控除の計算などは含みません（該当する場合は控除額・税額控除に金額を入力してください）。申告書の項目番号は年分で変わることがあるため、様式に転記する前に確認してください。</p>
<?php elseif ($tab === 'b1') : $c = $calc; ?>
    <h3><?= acc_h($company) ?>　別表一（各事業年度の所得に係る申告書）<?= $fiscalYear ?>年度</h3>
    <table class="tx">
        <tr><th></th><th>項目</th><th>金額（円）</th></tr>
        <tr><td class="no">1</td><td>所得金額又は欠損金額（別表四 欠損金控除後）</td><td class="num"><?= $y($c['income'] >= 0 ? $c['taxable'] : $c['income']) ?></td></tr>
        <tr><td class="no">2</td><td>法人税額（軽減 <?= number_format($c['low']) ?> × <?= acc_h((string) $c['cfg']['rates']['corp_low']) ?>% ＋ 標準 <?= number_format($c['high']) ?> × <?= acc_h((string) $c['cfg']['rates']['corp_high']) ?>%）</td><td class="num"><?= number_format($c['corp_before']) ?></td></tr>
        <tr><td class="no">6</td><td>控除税額</td><td class="num"><?= number_format($c['credit']) ?></td></tr>
        <tr><td class="no">9</td><td>差引所得に対する法人税額</td><td class="num"><?= number_format($c['corp_tax']) ?></td></tr>
        <tr><td class="no">12</td><td>中間申告分の法人税額</td><td class="num"><?= number_format((int) $c['interim']['corp']) ?></td></tr>
        <tr class="sum"><td class="no">13</td><td>差引確定法人税額</td><td class="num"><?= $y($c['unpaid']['corp']) ?></td></tr>
        <tr><td class="no">　</td><td>課税標準法人税額（千円未満切捨て）</td><td class="num"><?= number_format($c['base_corp']) ?></td></tr>
        <tr><td class="no">　</td><td>地方法人税額（<?= acc_h((string) $c['cfg']['rates']['local_corp']) ?>%）</td><td class="num"><?= number_format($c['local_corp']) ?></td></tr>
        <tr><td class="no">　</td><td>中間申告分の地方法人税額</td><td class="num"><?= number_format((int) $c['interim']['local_corp']) ?></td></tr>
        <tr class="sum"><td class="no">　</td><td>差引確定地方法人税額</td><td class="num"><?= $y($c['unpaid']['local_corp']) ?></td></tr>
        <?php if ($c['defense_applies']) : ?>
        <tr><td class="no">　</td><td>防衛特別法人税額（（基準法人税額 − 500万円）× <?= acc_h((string) $c['cfg']['rates']['defense']) ?>%）</td><td class="num"><?= number_format($c['defense']) ?></td></tr>
        <tr class="sum"><td class="no">　</td><td>差引確定防衛特別法人税額（中間 <?= number_format((int) $c['interim']['defense']) ?>）</td><td class="num"><?= $y($c['unpaid']['defense']) ?></td></tr>
        <?php endif; ?>
        <tr class="sum"><td class="no">　</td><td>法人税・地方法人税<?= $c['defense_applies'] ? '・防衛特別法人税' : '' ?> の納付額合計</td><td class="num"><?= $y($c['unpaid']['corp'] + $c['unpaid']['local_corp'] + $c['unpaid']['defense']) ?></td></tr>
    </table>
    <p class="muted">納付額がマイナスの場合は中間納付額の還付になります。</p>

<?php elseif ($tab === 'b4') : $c = $calc; ?>
    <h3><?= acc_h($company) ?>　別表四（所得の金額の計算に関する明細書）<?= $fiscalYear ?>年度</h3>
    <table class="tx">
        <tr><th>区分</th><th>総額</th><th>留保</th><th>社外流出</th></tr>
        <tr class="sum"><td>当期利益又は当期欠損の額</td><td class="num"><?= $y($c['profit_book']) ?></td><td class="num"><?= $y($c['profit_book']) ?></td><td class="num"></td></tr>
        <tr><th colspan="4">加算</th></tr>
        <?php foreach ($c['adds'] as $a) : ?><tr><td><?= acc_h($a['label']) ?></td><td class="num"><?= $y($a['amount']) ?></td><td class="num"><?= $a['flow'] === 'retain' ? $y($a['amount']) : '' ?></td><td class="num"><?= $a['flow'] === 'out' ? $y($a['amount']) : '' ?></td></tr><?php endforeach; ?>
        <tr class="sum"><td>加算 小計</td><td class="num"><?= $y($c['add_total']) ?></td><td></td><td></td></tr>
        <tr><th colspan="4">減算</th></tr>
        <?php foreach ($c['subs'] as $a) : ?><tr><td><?= acc_h($a['label']) ?></td><td class="num"><?= $y($a['amount']) ?></td><td class="num"><?= $a['flow'] === 'retain' ? $y($a['amount']) : '' ?></td><td class="num"><?= $a['flow'] === 'out' ? $y($a['amount']) : '' ?></td></tr><?php endforeach; ?>
        <tr class="sum"><td>減算 小計</td><td class="num"><?= $y($c['sub_total']) ?></td><td></td><td></td></tr>
        <tr class="sum"><td>仮計＝差引計（欠損金等の控除前）</td><td class="num"><?= $y($c['income']) ?></td><td></td><td></td></tr>
        <tr><td>欠損金又は災害損失金等の当期控除額（別表七(一)）</td><td class="num"><?= $y(-$c['loss_used']) ?></td><td></td><td></td></tr>
        <tr class="sum"><td>所得金額又は欠損金額</td><td class="num"><?= $y($c['income'] >= 0 ? $c['taxable'] : $c['income']) ?></td><td></td><td></td></tr>
    </table>
    <p class="muted">当期利益は法人税等の計上前の利益から法人税等（<?= number_format($c['booked_tax']) ?>円）を引いた金額です。法人税等を帳簿に計上していない場合は、納税充当金の加算も0円になります。</p>

<?php elseif ($tab === 'b5') : ?>
    <h3><?= acc_h($company) ?>　別表五(一) 利益積立金額及び資本金等の額の計算に関する明細書／別表五(二) 租税公課の納付状況　<?= $fiscalYear ?>年度</h3>
    <?php if ($sch5 === null) : ?><p class="notice">貸借対照表が作成できないため表示できません。</p><?php else : ?>
    <table class="tx"><tr><th>区分</th><th>期首現在</th><th>当期の減</th><th>当期の増</th><th>期末現在</th></tr>
        <?php foreach ($sch5['rows'] as $r) : ?><tr><td><?= acc_h($r['label']) ?></td><td class="num"><?= $y($r['open']) ?></td><td class="num"><?= $y($r['dec']) ?></td><td class="num"><?= $y($r['inc']) ?></td><td class="num"><?= $y($r['end']) ?></td></tr><?php endforeach; ?>
        <tr class="sum"><td>差引合計額（利益積立金額）</td><td class="num"><?= $y($sch5['total']['open']) ?></td><td class="num"><?= $y($sch5['total']['dec']) ?></td><td class="num"><?= $y($sch5['total']['inc']) ?></td><td class="num"><?= $y($sch5['total']['end']) ?></td></tr>
        <?php foreach ($sch5['capital'] as $r) : ?><tr><td>資本金等の額: <?= acc_h($r['label']) ?></td><td class="num"><?= $y($r['open']) ?></td><td class="num"></td><td class="num"><?= $y($r['inc']) ?></td><td class="num"><?= $y($r['end']) ?></td></tr><?php endforeach; ?>
    </table>
    <p class="muted">「繰越損益金」は帳簿の繰越利益剰余金、「納税充当金」は帳簿の未払法人税等の残高です。減価償却超過額などを別表四で留保にした場合は、その行が追加されます。</p>
    <h4>別表五(二) 租税公課の納付状況等</h4>
    <table class="tx"><tr><th>税目</th><th>期首現在未納税額</th><th>当期発生税額</th><th>当期中の納付額</th><th>期末現在未納税額</th></tr>
        <?php foreach ($sch5['schedule2'] as $r) : ?><tr><td><?= acc_h($r['label']) ?></td><td class="num"><?= $y($r['open']) ?></td><td class="num"><?= $y($r['occurred']) ?></td><td class="num"><?= $y($r['paid']) ?></td><td class="num"><?= $y($r['end']) ?></td></tr><?php endforeach; ?>
    </table>
    <p class="muted">当期中の納付額は、前期末の未納額の納付（期限内に全額納付した前提）と、設定した中間納付額の合計です。</p>
    <?php endif; ?>

<?php elseif ($tab === 'b7') : $c = $calc; ?>
    <h3><?= acc_h($company) ?>　別表七(一) 欠損金の損金算入に関する明細書　<?= $fiscalYear ?>年度</h3>
    <table class="tx"><tr><th>欠損金の生じた事業年度</th><th>控除前の金額</th><th>当期控除額</th><th>翌期繰越額</th></tr>
        <?php foreach ($c['losses'] as $l) : ?><tr><td><?= (int) $l['fy'] ?>年度<?= $l['expired'] ? '（繰越期間経過）' : '' ?></td><td class="num"><?= number_format($l['amount']) ?></td><td class="num"><?= number_format($l['used']) ?></td><td class="num"><?= number_format($l['remain']) ?></td></tr><?php endforeach; ?>
        <?php if ($c['new_loss'] > 0) : ?><tr><td><?= $fiscalYear ?>年度（当期分）</td><td class="num"><?= number_format($c['new_loss']) ?></td><td class="num">0</td><td class="num"><?= number_format($c['new_loss']) ?></td></tr><?php endif; ?>
        <tr class="sum"><td>合計</td><td class="num"><?= number_format(array_sum(array_column($c['losses'], 'amount')) + $c['new_loss']) ?></td><td class="num"><?= number_format($c['loss_used']) ?></td><td class="num"><?= number_format(array_sum(array_column($c['losses'], 'remain')) + $c['new_loss']) ?></td></tr>
    </table>
    <p class="muted">控除限度額は控除前の所得金額（中小法人等は100%）です。翌年度の「青色欠損金の繰越し」には、この「翌期繰越額」を入力します。</p>

<?php elseif ($tab === 'b15') : $c = $calc; $e = $c['entertain']; ?>
    <h3><?= acc_h($company) ?>　別表十五 交際費等の損金算入に関する明細書　<?= $fiscalYear ?>年度</h3>
    <table class="tx">
        <tr><td>支出交際費等の額（接待交際費の合計）</td><td class="num"><?= number_format($e['total']) ?></td></tr>
        <tr><td>定額控除限度額（800万円 × <?= (int) $c['months'] ?>/12）</td><td class="num"><?= number_format($e['limit_fixed']) ?></td></tr>
        <tr><td>損金不算入額（定額控除限度額を超える額）</td><td class="num"><?= number_format($e['not_fixed']) ?></td></tr>
        <tr><td>接待飲食費の額 <?= number_format($e['food']) ?> の50%を損金算入した場合の不算入額</td><td class="num"><?= $e['not_food'] === null ? '―' : number_format($e['not_food']) ?></td></tr>
        <tr class="sum"><td>損金不算入額（有利な方）→ 別表四へ</td><td class="num"><?= number_format($e['not_deductible']) ?></td></tr>
    </table>
    <p class="muted">資本金1億円以下の中小法人が前提です。1人5,000円以下の飲食費は交際費から除かれるため、帳簿の科目を分けていない場合は税務設定の「飲食費」を入力してください。</p>

<?php elseif ($tab === 'b16') : ?>
    <h3><?= acc_h($company) ?>　別表十六 減価償却資産の償却額の計算に関する明細書　<?= $fiscalYear ?>年度</h3>
    <table class="tx"><tr><th>資産</th><th>科目</th><th>取得年月</th><th>取得価額</th><th>方法</th><th>耐用年数</th><th>償却額（当期）</th><th>期末帳簿価額</th></tr>
        <?php $t = ['cost' => 0, 'dep' => 0, 'book' => 0]; foreach ($register as $r) : if ($r['row'] === null) { continue; } $t['cost'] += (int) $r['cost']; $t['dep'] += $r['row']['dep']; $t['book'] += $r['row']['book']; ?>
            <tr><td><?= acc_h($r['name']) ?></td><td><?= acc_h($r['asset_account']) ?></td><td><?= acc_h(substr((string) $r['acquired_date'], 0, 7)) ?></td><td class="num"><?= number_format((int) $r['cost']) ?></td><td><?= acc_h(ACC_ASSET_METHODS[$r['method']]) ?></td><td class="num"><?= $r['life'] ? (int) $r['life'] : '' ?></td><td class="num"><?= number_format($r['row']['dep']) ?></td><td class="num"><?= number_format($r['row']['book']) ?></td></tr>
        <?php endforeach; ?>
        <tr class="sum"><td colspan="3">合計</td><td class="num"><?= number_format($t['cost']) ?></td><td colspan="2"></td><td class="num"><?= number_format($t['dep']) ?></td><td class="num"><?= number_format($t['book']) ?></td></tr>
    </table>
    <p class="muted">償却限度額＝帳簿の償却額として計算しています（償却超過額は0）。会社の決算で償却額を限度額より少なく／多く計上した場合は、別表四で調整してください。</p>

<?php elseif ($tab === 'local') : $c = $calc; ?>
    <h3><?= acc_h($company) ?>　地方税（法人住民税・事業税）の計算　<?= $fiscalYear ?>年度</h3>
    <table class="tx">
        <tr><th>項目</th><th>課税標準</th><th>税額</th><th>中間納付</th><th>確定納付額</th></tr>
        <tr><td>法人税割（道府県民税 <?= acc_h((string) $c['cfg']['rates']['pref']) ?>%）</td><td class="num"><?= number_format($c['resident_base']) ?></td><td class="num"><?= number_format($c['pref_levy']) ?></td><td></td><td></td></tr>
        <tr><td>均等割（道府県民税）</td><td></td><td class="num"><?= number_format($c['pref_equal']) ?></td><td></td><td></td></tr>
        <tr class="sum"><td>道府県民税 計</td><td></td><td class="num"><?= number_format($c['pref']) ?></td><td class="num"><?= number_format((int) $c['interim']['pref']) ?></td><td class="num"><?= $y($c['unpaid']['pref']) ?></td></tr>
        <tr><td>法人税割（市町村民税 <?= acc_h((string) $c['cfg']['rates']['city']) ?>%）</td><td class="num"><?= number_format($c['resident_base']) ?></td><td class="num"><?= number_format($c['city_levy']) ?></td><td></td><td></td></tr>
        <tr><td>均等割（市町村民税）</td><td></td><td class="num"><?= number_format($c['city_equal']) ?></td><td></td><td></td></tr>
        <tr class="sum"><td>市町村民税 計</td><td></td><td class="num"><?= number_format($c['city']) ?></td><td class="num"><?= number_format((int) $c['interim']['city']) ?></td><td class="num"><?= $y($c['unpaid']['city']) ?></td></tr>
        <tr><td>事業税 所得割（400万円以下 / 〜800万円 / 800万円超）</td><td class="num"><?= number_format($c['biz_tiers'][0]) ?> / <?= number_format($c['biz_tiers'][1]) ?> / <?= number_format($c['biz_tiers'][2]) ?></td><td class="num"><?= number_format($c['biz']) ?></td><td class="num"><?= number_format((int) $c['interim']['biz']) ?></td><td class="num"><?= $y($c['unpaid']['biz']) ?></td></tr>
        <tr><td>特別法人事業税（事業税所得割の標準税額 × <?= acc_h((string) $c['cfg']['rates']['special_biz']) ?>%）</td><td class="num"><?= number_format(intdiv($c['biz_std'], 100) * 100) ?></td><td class="num"><?= number_format($c['special_biz']) ?></td><td class="num"><?= number_format((int) $c['interim']['special_biz']) ?></td><td class="num"><?= $y($c['unpaid']['special_biz']) ?></td></tr>
        <tr class="sum"><td>法人税等 合計（法人税・地方法人税<?= $c['defense_applies'] ? '・防衛特別法人税' : '' ?>・住民税・事業税）</td><td></td><td class="num"><?= number_format($c['total']) ?></td><td></td><td></td></tr>
    </table>
    <p class="muted">事業税は、所得金額が年400万円以下／800万円以下／800万円超の3区分の税率（標準税率）で計算しています。税率や均等割は所在地・資本金・従業者数で異なるため、税務設定で調整してください。</p>
<?php endif; ?>
</div>
<?php acc_render_footer(); ?>
