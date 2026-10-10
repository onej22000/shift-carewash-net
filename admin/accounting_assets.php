<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/accounting_assets.php';

$admin = require_login('admin');
$pdo = getPdo();

/**
 * 固定資産台帳。取得した資産を登録すると、年末（売却・除却した年は売却日）の減価償却費の仕訳が自動で作られ、
 * 元帳・試算表・決算書に反映される。取得そのものの仕訳（工具器具備品／現金 など）は「取引入力」で入れておく。
 */

[$book, $books] = acc_select_book($pdo);
$bookId = (int) $book['id'];
$chart = acc_chart_map($pdo, $book);
$settings = acc_asset_settings($pdo, $bookId);
$fiscalYear = (int) ($_GET['fy'] ?? acc_current_fiscal_year($book));
$self = '/admin/accounting_assets.php?book=' . $bookId . '&fy=' . $fiscalYear;
$editId = (int) ($_GET['edit'] ?? 0);

$assetAccounts = [];
foreach ($chart as $name => $c) {
    if ($c['category'] === 'asset' && in_array($c['section'], ['有形固定資産', '無形固定資産'], true) && (int) $c['is_contra'] === 0 && (int) $c['is_active'] === 1) {
        $assetAccounts[] = $name;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('error', '不正なリクエストです。画面を再読み込みしてやり直してください。');
        header('Location: ' . $self);
        exit;
    }
    $action = (string) ($_POST['action'] ?? '');
    $errors = [];

    if ($action === 'save_settings') {
        $cur = acc_book_settings($pdo, $bookId);
        $profile = $cur['profile'];
        $profile['dep_entry'] = ($_POST['dep_entry'] ?? 'direct') === 'indirect' ? 'indirect' : 'direct';
        $profile['dep_offset'] = !empty($_POST['dep_offset']) ? 1 : 0;
        acc_book_settings_save($pdo, $bookId, $cur['opening_fy'], $profile);
        set_flash('success', '減価償却の設定を保存しました。');
        header('Location: ' . $self);
        exit;
    }

    if ($action === 'save_asset') {
        $id = (int) ($_POST['id'] ?? 0);
        $num = static fn (string $k): int => (int) str_replace([',', '，', ' ', '円'], '', (string) ($_POST[$k] ?? ''));
        $name = acc_clean_text((string) ($_POST['name'] ?? ''));
        $account = acc_clean_text((string) ($_POST['asset_account'] ?? ''));
        $acquired = (string) ($_POST['acquired_date'] ?? '');
        $service = (string) ($_POST['service_date'] ?? '') ?: $acquired;
        $method = (string) ($_POST['method'] ?? 'sl');
        $cost = $num('cost');
        $life = $num('life');
        $pct = (float) ($_POST['business_pct'] ?? 100);
        if ($name === '' || mb_strlen($name) > 100) {
            $errors[] = '名称を入力してください。';
        }
        if (!isset($chart[$account])) {
            $errors[] = '資産の勘定科目を選んでください。';
        }
        if (!acc_is_date($acquired) || !acc_is_date($service)) {
            $errors[] = '取得年月日と事業供用日を入力してください。';
        } elseif ($service < $acquired) {
            $errors[] = '事業供用日が取得日より前です。';
        }
        if (!isset(ACC_ASSET_METHODS[$method])) {
            $errors[] = '償却方法を選んでください。';
        }
        if ($cost <= 0) {
            $errors[] = '取得価額は1円以上で入力してください。';
        }
        if ($pct <= 0 || $pct > 100) {
            $errors[] = '事業専用割合は0超〜100の範囲で入力してください。';
        }
        $row = [
            'method' => $method, 'life' => $life > 0 ? $life : null, 'service_date' => $service, 'acquired_date' => $acquired, 'cost' => $cost,
            'manual_annual' => $num('manual_annual'), 'status' => 'active', 'business_pct' => $pct, 'prior_accum' => $num('prior_accum'),
        ];
        $w = acc_asset_warnings($row);
        foreach ($w as $msg) {
            if (str_contains($msg, '計算できません') || str_contains($msg, '入力してください') || str_contains($msg, '対応していません')) {
                $errors[] = $msg;
            }
        }
        if ($errors) {
            set_flash('error', implode("\n", $errors));
            header('Location: ' . $self . ($id > 0 ? '&edit=' . $id : ''));
            exit;
        }
        $params = [
            ':b' => $bookId, ':n' => $name, ':a' => $account, ':s' => acc_clean_text((string) ($_POST['ledger_sub'] ?? '')) ?: null,
            ':ad' => $acquired, ':sd' => $service, ':c' => $cost, ':q' => max(1, $num('quantity')), ':m' => $method, ':l' => $life > 0 ? $life : null,
            ':ma' => $num('manual_annual') ?: null, ':pa' => $num('prior_accum'), ':bp' => $pct, ':loc' => acc_clean_text((string) ($_POST['location'] ?? '')) ?: null,
            ':memo' => acc_clean_text((string) ($_POST['memo'] ?? '')) ?: null, ':src' => (int) ($_POST['source_manual_id'] ?? 0) ?: null,
        ];
        if ($id > 0) {
            $pdo->prepare(
                'UPDATE acc_assets SET name=:n, asset_account=:a, ledger_sub=:s, acquired_date=:ad, service_date=:sd, cost=:c, quantity=:q, method=:m, life=:l, manual_annual=:ma, prior_accum=:pa, business_pct=:bp, location=:loc, memo=:memo, source_manual_id=:src
                 WHERE id=:id AND book_id=:b'
            )->execute($params + [':id' => $id]);
            set_flash('success', '固定資産を更新しました。');
        } else {
            $pdo->prepare(
                'INSERT INTO acc_assets (book_id, name, asset_account, ledger_sub, acquired_date, service_date, cost, quantity, method, life, manual_annual, prior_accum, business_pct, location, memo, source_manual_id)
                 VALUES (:b,:n,:a,:s,:ad,:sd,:c,:q,:m,:l,:ma,:pa,:bp,:loc,:memo,:src)'
            )->execute($params);
            set_flash('success', '固定資産を登録しました。減価償却の仕訳は年末（決算）に自動で作られます。');
        }
        header('Location: ' . $self);
        exit;
    }

    if ($action === 'dispose') {
        $id = (int) ($_POST['id'] ?? 0);
        $date = (string) ($_POST['disposed_date'] ?? '');
        $kind = ($_POST['disposal_kind'] ?? 'sale') === 'scrap' ? 'scrap' : 'sale';
        $price = (int) str_replace([',', ' ', '円'], '', (string) ($_POST['disposal_price'] ?? '0'));
        $account = acc_clean_text((string) ($_POST['disposal_account'] ?? ''));
        $stmt = $pdo->prepare('SELECT * FROM acc_assets WHERE id = :id AND book_id = :b');
        $stmt->execute([':id' => $id, ':b' => $bookId]);
        $a = $stmt->fetch();
        if (!$a) {
            $errors[] = '資産が見つかりません。';
        } elseif (!acc_is_date($date) || $date < $a['service_date']) {
            $errors[] = '売却・除却日を正しく入力してください。';
        }
        if ($kind === 'scrap') {
            $price = 0;
        }
        if ($price > 0 && !isset($chart[$account])) {
            $errors[] = '売却代金の入金先の科目を選んでください。';
        }
        if ($errors) {
            set_flash('error', implode("\n", $errors));
        } else {
            $pdo->prepare("UPDATE acc_assets SET status='disposed', disposed_date=:d, disposal_kind=:k, disposal_price=:p, disposal_account=:a WHERE id=:id AND book_id=:b")
                ->execute([':d' => $date, ':k' => $kind, ':p' => $price, ':a' => $account ?: null, ':id' => $id, ':b' => $bookId]);
            set_flash('success', ($kind === 'scrap' ? '除却' : '売却') . 'を登録しました。売却・除却の仕訳は自動で作られます。');
        }
        header('Location: ' . $self);
        exit;
    }

    if ($action === 'undispose') {
        $pdo->prepare("UPDATE acc_assets SET status='active', disposed_date=NULL, disposal_kind=NULL, disposal_price=NULL, disposal_account=NULL WHERE id=:id AND book_id=:b")
            ->execute([':id' => (int) ($_POST['id'] ?? 0), ':b' => $bookId]);
        set_flash('success', '売却・除却を取り消しました。');
        header('Location: ' . $self);
        exit;
    }

    if ($action === 'delete_asset') {
        $pdo->prepare('DELETE FROM acc_assets WHERE id=:id AND book_id=:b')->execute([':id' => (int) ($_POST['id'] ?? 0), ':b' => $bookId]);
        set_flash('success', '固定資産を削除しました。');
        header('Location: ' . $self);
        exit;
    }
    header('Location: ' . $self);
    exit;
}

$flash = pop_flash();
$register = acc_asset_register($pdo, $book, $fiscalYear);
$edit = null;
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM acc_assets WHERE id = :id AND book_id = :b');
    $stmt->execute([':id' => $editId, ':b' => $bookId]);
    $edit = $stmt->fetch() ?: null;
}
// 取得の取引の候補（固定資産の科目に借方計上されていて、まだ台帳に結びついていない手入力の取引）
$candidates = [];
if ($assetAccounts !== []) {
    $in = implode(',', array_fill(0, count($assetAccounts), '?'));
    $stmt = $pdo->prepare(
        "SELECT m.* FROM acc_manual m WHERE m.book_id = ? AND m.debit_account IN ($in)
         AND m.id NOT IN (SELECT source_manual_id FROM acc_assets WHERE source_manual_id IS NOT NULL) ORDER BY m.entry_date DESC LIMIT 20"
    );
    $stmt->execute(array_merge([$bookId], $assetAccounts));
    $candidates = $stmt->fetchAll();
}
$form = $edit ?: [
    'id' => 0, 'name' => '', 'asset_account' => '', 'ledger_sub' => '', 'acquired_date' => '', 'service_date' => '', 'cost' => '', 'quantity' => 1, 'method' => 'sl', 'life' => '',
    'manual_annual' => '', 'prior_accum' => 0, 'business_pct' => '100', 'location' => '', 'memo' => '', 'source_manual_id' => '',
];
$pre = (int) ($_GET['from'] ?? 0);
if ($pre > 0 && !$edit) {
    foreach ($candidates as $c) {
        if ((int) $c['id'] === $pre) {
            $form['name'] = (string) $c['description'];
            $form['asset_account'] = $c['debit_account'];
            $form['acquired_date'] = $form['service_date'] = $c['entry_date'];
            $form['cost'] = (string) $c['amount'];
            $form['source_manual_id'] = (string) $c['id'];
        }
    }
}
$yearOptions = range(acc_current_fiscal_year($book) + 1, acc_current_fiscal_year($book) - 8);
$totals = ['cost' => 0, 'open' => 0, 'dep' => 0, 'expense' => 0, 'accum' => 0, 'book' => 0];
$smallTotal = 0;
foreach ($register as $r) {
    if ($r['row'] === null) {
        continue;
    }
    $totals['cost'] += (int) $r['cost'];
    $totals['open'] += $r['row']['open_book'];
    $totals['dep'] += $r['row']['dep'];
    $totals['expense'] += $r['row']['expense'];
    $totals['accum'] += $r['row']['accum'];
    $totals['book'] += $r['row']['book'];
    if (in_array($r['method'], ['small', 'small_expensed'], true) && $r['acquired_in_year']) {
        $smallTotal += (int) $r['cost'];
    }
}

acc_render_header($admin, '固定資産台帳', 'assets', $bookId);
acc_render_messages($flash);
?>
<div class="yb-panel">
    <div class="yb-toolbar">
        <?php acc_render_book_selector($books, $book, '/admin/accounting_assets.php', ['fy' => $fiscalYear]); ?>
        <form method="get" action="/admin/accounting_assets.php" class="inline">
            <input type="hidden" name="book" value="<?= $bookId ?>">
            <label>事業年度: <select name="fy" onchange="this.form.submit()">
                <?php foreach ($yearOptions as $y) : ?><option value="<?= $y ?>"<?= $y === $fiscalYear ? ' selected' : '' ?>><?= $y ?>年<?= (int) $book['fiscal_start_month'] ?>月開始</option><?php endforeach; ?>
            </select></label>
        </form>
        <a href="#" onclick="window.print();return false;">印刷</a>
    </div>
    <?php if ($settings['opening_fy'] === null) : ?>
        <p class="notice">記帳開始年度が未設定のため、減価償却の仕訳はまだ作られません。「設定 → 期首残高・基本情報」で登録してください。</p>
    <?php endif; ?>

    <h3>固定資産台帳（<?= $fiscalYear ?>年度）</h3>
    <div class="scroll"><table class="list">
        <thead><tr><th>名称</th><th>科目</th><th>取得日</th><th class="num">取得価額</th><th>償却方法</th><th>耐用年数</th><th class="num">月数</th><th class="num">期首帳簿価額</th><th class="num">当期償却額</th><th class="num">うち必要経費</th><th class="num">償却累計額</th><th class="num">期末帳簿価額</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($register as $r) : $row = $r['row']; ?>
            <tr>
                <td><?= acc_h($r['name']) ?><?= $r['status'] === 'disposed' ? ' <span class="badge badge-orphan">' . ($r['disposal_kind'] === 'scrap' ? '除却' : '売却') . ' ' . acc_h($r['disposed_date']) . '</span>' : '' ?>
                    <?php foreach ($r['warnings'] as $w) : ?><br><span class="neg"><?= acc_h($w) ?></span><?php endforeach; ?></td>
                <td><?= acc_h($r['asset_account']) ?></td>
                <td><?= acc_h($r['acquired_date']) ?></td>
                <td class="num"><?= number_format((int) $r['cost']) ?></td>
                <td><?= acc_h(ACC_ASSET_METHODS[$r['method']]) ?><?= (float) $r['business_pct'] < 100 ? '（事業' . acc_h(rtrim(rtrim(number_format((float) $r['business_pct'], 2), '0'), '.')) . '%）' : '' ?></td>
                <td class="num"><?= $r['life'] ? (int) $r['life'] . '年' : '' ?></td>
                <td class="num"><?= $row ? $row['months'] : '' ?></td>
                <td class="num"><?= $row ? number_format($row['open_book']) : '' ?></td>
                <td class="num"><?= $row ? number_format($row['dep']) : '' ?></td>
                <td class="num"><?= $row ? number_format($row['expense']) : '' ?></td>
                <td class="num"><?= $row ? number_format($row['accum']) : '' ?></td>
                <td class="num"><?= $row ? number_format($row['book']) : '' ?></td>
                <td>
                    <a href="<?= acc_h($self . '&edit=' . (int) $r['id']) ?>">編集</a>
                    <?php if ($r['status'] === 'active') : ?>
                        <details style="display:inline"><summary style="display:inline;cursor:pointer;color:#0b5ed7">売却・除却</summary>
                            <form method="post" action="<?= acc_h($self) ?>">
                                <input type="hidden" name="csrf_token" value="<?= acc_h(csrf_token()) ?>">
                                <input type="hidden" name="action" value="dispose">
                                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                <label>日付 <input type="date" name="disposed_date" required></label>
                                <label><select name="disposal_kind"><option value="sale">売却</option><option value="scrap">除却（廃棄）</option></select></label>
                                <label>売却額 <input type="text" name="disposal_price" size="9" value="0"></label>
                                <label>入金先 <input type="text" name="disposal_account" size="8" list="accts" placeholder="普通預金"></label>
                                <button type="submit">登録</button>
                            </form></details>
                    <?php else : ?>
                        <form method="post" action="<?= acc_h($self) ?>" class="inline"><input type="hidden" name="csrf_token" value="<?= acc_h(csrf_token()) ?>"><input type="hidden" name="action" value="undispose"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button type="submit">売却を取消</button></form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($register === []) : ?><tr><td colspan="13" class="muted">この年度の固定資産はありません。</td></tr><?php endif; ?>
        <tr style="font-weight:bold"><td colspan="3">合計</td><td class="num"><?= number_format($totals['cost']) ?></td><td colspan="3"></td><td class="num"><?= number_format($totals['open']) ?></td><td class="num"><?= number_format($totals['dep']) ?></td><td class="num"><?= number_format($totals['expense']) ?></td><td class="num"><?= number_format($totals['accum']) ?></td><td class="num"><?= number_format($totals['book']) ?></td><td></td></tr>
        </tbody>
    </table></div>
    <datalist id="accts"><?php foreach (array_keys($chart) as $n) : ?><option value="<?= acc_h($n) ?>"><?php endforeach; ?></datalist>
    <?php if ($smallTotal > 3000000) : ?>
        <p class="neg">この年度に取得した少額減価償却資産の合計が <?= number_format($smallTotal) ?> 円で、特例の年間上限（300万円）を超えています。</p>
    <?php endif; ?>
    <p class="muted">少額減価償却資産の特例は、取得価額が30万円未満（令和8年4月1日以後の取得は40万円未満）、年間合計300万円までです（常時使用する従業員が400人以下の中小企業者等・青色申告者が対象）。</p>
</div>

<div class="yb-panel">
    <h3>減価償却の仕訳の設定</h3>
    <form method="post" action="<?= acc_h($self) ?>">
        <input type="hidden" name="csrf_token" value="<?= acc_h(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_settings">
        <p><label><input type="radio" name="dep_entry" value="direct"<?= $settings['dep_entry'] === 'direct' ? ' checked' : '' ?>> 直接法（減価償却費／資産の科目。貸借対照表は帳簿価額で表示）</label><br>
           <label><input type="radio" name="dep_entry" value="indirect"<?= $settings['dep_entry'] === 'indirect' ? ' checked' : '' ?>> 間接法（減価償却費／減価償却累計額）</label></p>
        <p><label><input type="checkbox" name="dep_offset" value="1"<?= $settings['dep_offset'] ? ' checked' : '' ?>>
            毎月の概算（定型仕訳）で減価償却費を計上している。決算で実額に合わせて調整する</label>
            <span class="muted">（その事業年度に台帳以外の仕訳で計上した減価償却費を、決算日の仕訳で「減価償却累計額／減価償却費」に振り替えて取り消します）</span></p>
        <button type="submit">保存</button>
    </form>
</div>

<div class="yb-panel">
    <h3><?= $edit ? '固定資産の編集' : '固定資産の登録' ?></h3>
    <?php if (!$edit && $candidates !== []) : ?>
        <p>取得の取引から登録:
        <?php foreach ($candidates as $c) : ?>
            <a href="<?= acc_h($self . '&from=' . (int) $c['id']) ?>"><?= acc_h($c['entry_date'] . ' ' . $c['debit_account'] . ' ' . number_format((int) $c['amount']) . '円' . ($c['description'] ? '（' . $c['description'] . '）' : '')) ?></a>
        <?php endforeach; ?></p>
    <?php endif; ?>
    <form method="post" action="<?= acc_h($self) ?>">
        <input type="hidden" name="csrf_token" value="<?= acc_h(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_asset">
        <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">
        <input type="hidden" name="source_manual_id" value="<?= acc_h((string) $form['source_manual_id']) ?>">
        <table class="list" style="max-width:820px">
            <tr><th style="text-align:left;width:12em">名称</th><td><input type="text" name="name" value="<?= acc_h($form['name']) ?>" size="40" required maxlength="100"></td></tr>
            <tr><th style="text-align:left">資産の科目</th><td><select name="asset_account" required><option value="">選んでください</option>
                <?php foreach ($assetAccounts as $n) : ?><option<?= $n === $form['asset_account'] ? ' selected' : '' ?>><?= acc_h($n) ?></option><?php endforeach; ?></select>
                補助科目 <input type="text" name="ledger_sub" value="<?= acc_h((string) $form['ledger_sub']) ?>" size="14" placeholder="なし"></td></tr>
            <tr><th style="text-align:left">取得年月日</th><td><input type="date" name="acquired_date" value="<?= acc_h((string) $form['acquired_date']) ?>" required>
                事業供用日 <input type="date" name="service_date" value="<?= acc_h((string) $form['service_date']) ?>"> <span class="muted">（空なら取得日）</span></td></tr>
            <tr><th style="text-align:left">取得価額（円）</th><td><input type="text" name="cost" value="<?= acc_h((string) $form['cost']) ?>" size="12" required inputmode="numeric">
                数量 <input type="number" name="quantity" value="<?= (int) $form['quantity'] ?>" min="1" style="width:70px"> <span class="muted">帳簿の経理方式のまま（税込経理なら税込）</span></td></tr>
            <tr><th style="text-align:left">償却方法</th><td><select name="method"><?php foreach (ACC_ASSET_METHODS as $k => $l) : ?><option value="<?= acc_h($k) ?>"<?= $k === $form['method'] ? ' selected' : '' ?>><?= acc_h($l) ?></option><?php endforeach; ?></select>
                耐用年数 <input type="number" name="life" value="<?= acc_h((string) $form['life']) ?>" min="2" max="100" style="width:70px"> 年</td></tr>
            <tr><th style="text-align:left">償却額を指定する場合</th><td>年間償却額 <input type="text" name="manual_annual" value="<?= acc_h((string) $form['manual_annual']) ?>" size="10">
                記帳開始前の償却累計額 <input type="text" name="prior_accum" value="<?= acc_h((string) $form['prior_accum']) ?>" size="10"></td></tr>
            <tr><th style="text-align:left">事業専用割合（%）</th><td><input type="text" name="business_pct" value="<?= acc_h((string) $form['business_pct']) ?>" size="6"> <span class="muted">個人で自宅兼用などのとき。100未満の分は事業主貸になります</span></td></tr>
            <tr><th style="text-align:left">設置場所・備考</th><td><input type="text" name="location" value="<?= acc_h((string) $form['location']) ?>" size="20" placeholder="設置場所"> <input type="text" name="memo" value="<?= acc_h((string) $form['memo']) ?>" size="30" placeholder="備考"></td></tr>
        </table>
        <p><button type="submit" class="primary"><?= $edit ? '更新' : '登録' ?></button>
            <?php if ($edit) : ?><a href="<?= acc_h($self) ?>">編集をやめる</a>
                <button type="submit" form="del_asset" class="danger" onclick="return confirm('この資産を削除します。よろしいですか？');">削除</button><?php endif; ?></p>
    </form>
    <?php if ($edit) : ?>
        <form method="post" action="<?= acc_h($self) ?>" id="del_asset"><input type="hidden" name="csrf_token" value="<?= acc_h(csrf_token()) ?>"><input type="hidden" name="action" value="delete_asset"><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"></form>
        <?php $sched = acc_dep_schedule($edit, $book, max($fiscalYear, acc_fy_of($book, (string) $edit['service_date']) + 6), $settings['opening_fy']); ?>
        <h4>償却スケジュール</h4>
        <table class="list" style="max-width:640px"><thead><tr><th>年度</th><th class="num">月数</th><th class="num">期首帳簿価額</th><th class="num">償却額</th><th class="num">償却累計額</th><th class="num">期末帳簿価額</th></tr></thead><tbody>
        <?php foreach ($sched as $s) : if ($s['dep'] === 0 && $s['open_book'] <= 1 && $s['fy'] > $fiscalYear) { continue; } ?>
            <tr><td><?= $s['fy'] ?></td><td class="num"><?= $s['months'] ?></td><td class="num"><?= number_format($s['open_book']) ?></td><td class="num"><?= number_format($s['dep']) ?></td><td class="num"><?= number_format($s['accum']) ?></td><td class="num"><?= number_format($s['book']) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
    <?php endif; ?>
</div>
<?php acc_render_footer(); ?>
