<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/accounting_ledger.php';

$admin = require_login('admin');
$pdo = getPdo();

/**
 * 取引入力。入金・出金（経費）・口座間の振替を、仕訳を意識せずに入れる画面。保存すると手入力の仕訳（acc_manual）になり、
 * 元帳・試算表・決算書に反映される。領収書・請求書の画像／PDFは取引に紐づけて保存できる（取引日・金額・取引先で検索できる）。
 */

[$book, $books] = acc_select_book($pdo);
$bookId = (int) $book['id'];
$chart = acc_chart_map($pdo, $book);
$settings = acc_book_settings($pdo, $bookId);
$kind = (string) ($_GET['kind'] ?? $_POST['kind'] ?? 'out');
if (!in_array($kind, ['in', 'out', 'xfer'], true)) {
    $kind = 'out';
}
$kindLabels = ['in' => '入金', 'out' => '出金・経費', 'xfer' => '口座間の振替'];
$editId = (int) ($_GET['edit'] ?? $_POST['edit_id'] ?? 0);
$fiscalYear = (int) ($_GET['fy'] ?? acc_current_fiscal_year($book));
$self = '/admin/accounting_input.php?book=' . $bookId . '&kind=' . $kind;

/** 資金の科目（現金・預貯金・未払金など、入金先／支払元になるもの） */
$fundNames = [];
foreach ($chart as $name => $c) {
    if ((int) $c['is_active'] === 1 && ($c['uchiwake'] === '預貯金' || in_array($name, ['現金', '小口現金', '未払金', '事業主借', '役員借入金', '短期借入金', '長期借入金', '預り金', '事業主貸'], true))) {
        $fundNames[] = $name;
    }
}
$accountNames = array_keys(array_filter($chart, static fn (array $c): bool => (int) $c['is_active'] === 1));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('error', '不正なリクエストです。画面を再読み込みしてやり直してください。');
        header('Location: ' . $self);
        exit;
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM acc_manual WHERE id = :id AND book_id = :b');
        $stmt->execute([':id' => $id, ':b' => $bookId]);
        $row = $stmt->fetch();
        if ($row) {
            $pdo->prepare('DELETE FROM acc_manual WHERE id = :id AND book_id = :b')->execute([':id' => $id, ':b' => $bookId]);
            $pdo->prepare('UPDATE acc_attachments SET manual_id = NULL WHERE manual_id = :id AND book_id = :b')->execute([':id' => $id, ':b' => $bookId]);
            acc_audit_log($pdo, $bookId, 'manual', $id, 'delete', $row, (int) $admin['id']);
            set_flash('success', '取引を削除しました（添付した証憑は残ります）。');
        }
        header('Location: ' . $self);
        exit;
    }

    if ($action === 'void_attachment') {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare('UPDATE acc_attachments SET voided_at = NOW() WHERE id = :id AND book_id = :b AND voided_at IS NULL')->execute([':id' => $id, ':b' => $bookId]);
        acc_audit_log($pdo, $bookId, 'attachment', $id, 'void', [], (int) $admin['id']);
        set_flash('success', '証憑を無効にしました（ファイルと履歴は残ります）。');
        header('Location: ' . $self . ($editId > 0 ? '&edit=' . $editId : ''));
        exit;
    }

    if ($action === 'save') {
        $errors = [];
        $date = (string) ($_POST['entry_date'] ?? '');
        if (!acc_is_date($date)) {
            $errors[] = '日付を入力してください。';
        } elseif ($settings['opening_fy'] !== null && $date < acc_fy_bounds($book, $settings['opening_fy'])[0]) {
            $errors[] = '日付が記帳開始年度の期首より前です。';
        }
        $amountRaw = str_replace([',', '，', ' ', '円'], '', (string) ($_POST['amount'] ?? ''));
        if (!preg_match('/\A\d+\z/', $amountRaw) || (int) $amountRaw === 0) {
            $errors[] = '金額は1円以上の整数で入力してください。';
        }
        $amount = (int) $amountRaw;
        $counterparty = acc_clean_text((string) ($_POST['counterparty'] ?? ''));
        $description = acc_clean_text((string) ($_POST['description'] ?? ''));
        $tax = (string) ($_POST['tax'] ?? ACC_TAX_NONE);
        if (!in_array($tax, ACC_TAX_CLASSES, true)) {
            $errors[] = '税区分が一覧にありません。';
        }
        $taxAmountRaw = str_replace([',', ' '], '', (string) ($_POST['tax_amount'] ?? ''));
        $taxAmount = $taxAmountRaw === '' ? null : (int) $taxAmountRaw;
        if ($taxAmount !== null && ($taxAmount < 0 || $taxAmount > $amount)) {
            $errors[] = '消費税額が金額を超えています。';
        }
        $get = static fn (string $k): string => acc_clean_text((string) ($_POST[$k] ?? ''));
        $a = $get('account_a');   // in: 入金先 / out: 科目 / xfer: 振替先
        $b = $get('account_b');   // in: 相手科目 / out: 支払方法 / xfer: 振替元
        $subA = $get('sub_a');
        $subB = $get('sub_b');
        foreach ([$a, $b] as $name) {
            if ($name === '' || !isset($chart[$name])) {
                $errors[] = '勘定科目「' . $name . '」が勘定科目マスタにありません。';
            }
        }
        if ($kind === 'xfer' && $a === $b && $subA === $subB) {
            $errors[] = '振替元と振替先が同じです。';
        }
        if ($kind === 'xfer') {
            $tax = ACC_TAX_NONE;
            $taxAmount = null;
        }
        $files = acc_files_list('files');
        if ($errors) {
            set_flash('error', implode("\n", $errors));
            header('Location: ' . $self . ($editId > 0 ? '&edit=' . $editId : ''));
            exit;
        }
        if ($kind === 'in') {
            $debit = [$a, $subA, ACC_TAX_NONE, null];
            $credit = [$b, $subB, $tax, $taxAmount];
        } elseif ($kind === 'out') {
            $debit = [$a, $subA, $tax, $taxAmount];
            $credit = [$b, $subB, ACC_TAX_NONE, null];
        } else {
            $debit = [$a, $subA, ACC_TAX_NONE, null];
            $credit = [$b, $subB, ACC_TAX_NONE, null];
        }
        $params = [
            ':b' => $bookId, ':d' => $date, ':da' => $debit[0], ':ds' => $debit[1] !== '' ? $debit[1] : null, ':dt' => $debit[2], ':dta' => $debit[3],
            ':ca' => $credit[0], ':cs' => $credit[1] !== '' ? $credit[1] : null, ':ct' => $credit[2], ':cta' => $credit[3],
            ':amt' => $amount, ':desc' => mb_substr($description, 0, 100), ':kind' => $kind, ':cp' => $counterparty !== '' ? mb_substr($counterparty, 0, 100) : null,
        ];
        $notes = [];
        if ($editId > 0) {
            $old = $pdo->prepare('SELECT * FROM acc_manual WHERE id = :id AND book_id = :b');
            $old->execute([':id' => $editId, ':b' => $bookId]);
            $oldRow = $old->fetch();
            if (!$oldRow) {
                set_flash('error', '編集する取引が見つかりません。');
                header('Location: ' . $self);
                exit;
            }
            $pdo->prepare(
                'UPDATE acc_manual SET entry_date = :d, debit_account = :da, debit_sub = :ds, debit_tax = :dt, debit_tax_amount = :dta,
                 credit_account = :ca, credit_sub = :cs, credit_tax = :ct, credit_tax_amount = :cta, amount = :amt, description = :desc, kind = :kind, counterparty = :cp, updated_at = NOW()
                 WHERE id = :id AND book_id = :b'
            )->execute($params + [':id' => $editId]);
            acc_audit_log($pdo, $bookId, 'manual', $editId, 'update', ['before' => $oldRow], (int) $admin['id']);
            $manualId = $editId;
        } else {
            $pdo->prepare(
                'INSERT INTO acc_manual (book_id, entry_date, debit_account, debit_sub, debit_tax, debit_tax_amount, credit_account, credit_sub, credit_tax, credit_tax_amount, amount, description, kind, counterparty, created_by)
                 VALUES (:b, :d, :da, :ds, :dt, :dta, :ca, :cs, :ct, :cta, :amt, :desc, :kind, :cp, ' . (int) $admin['id'] . ')'
            )->execute($params);
            $manualId = (int) $pdo->lastInsertId();
            acc_audit_log($pdo, $bookId, 'manual', $manualId, 'create', $params, (int) $admin['id']);
        }
        foreach ($files as $file) {
            $err = acc_attach_store($pdo, $bookId, $manualId, $file, $date, $amount, $counterparty, (int) $admin['id']);
            if ($err !== null) {
                $notes[] = $err;
            }
        }
        $msg = ($editId > 0 ? '取引を更新しました。' : '取引を登録しました。') . ($notes ? "\n証憑の保存でエラー: " . implode(' / ', $notes) : '');
        set_flash($notes ? 'error' : 'success', $msg);
        header('Location: ' . '/admin/accounting_input.php?book=' . $bookId . '&kind=' . $kind);
        exit;
    }
    header('Location: ' . $self);
    exit;
}

// 編集の初期値
$form = ['entry_date' => date('Y-m-d'), 'amount' => '', 'counterparty' => '', 'description' => '', 'tax' => ACC_TAX_NONE, 'tax_amount' => '', 'account_a' => '', 'sub_a' => '', 'account_b' => '', 'sub_b' => ''];
$editRow = null;
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM acc_manual WHERE id = :id AND book_id = :b');
    $stmt->execute([':id' => $editId, ':b' => $bookId]);
    $editRow = $stmt->fetch() ?: null;
    if ($editRow === null || !in_array($editRow['kind'], ['in', 'out', 'xfer'], true)) {
        set_flash('error', 'この取引は「振替伝票」の画面で編集してください。');
        header('Location: /admin/accounting_manual.php?book=' . $bookId);
        exit;
    }
    $kind = (string) $editRow['kind'];
    $self = '/admin/accounting_input.php?book=' . $bookId . '&kind=' . $kind;
    $form = [
        'entry_date' => $editRow['entry_date'], 'amount' => (string) $editRow['amount'], 'counterparty' => (string) $editRow['counterparty'], 'description' => (string) $editRow['description'],
        'tax' => $kind === 'in' ? $editRow['credit_tax'] : ($kind === 'out' ? $editRow['debit_tax'] : ACC_TAX_NONE),
        'tax_amount' => (string) ($kind === 'in' ? ($editRow['credit_tax_amount'] ?? '') : ($editRow['debit_tax_amount'] ?? '')),
        'account_a' => $editRow['debit_account'], 'sub_a' => (string) $editRow['debit_sub'], 'account_b' => $editRow['credit_account'], 'sub_b' => (string) $editRow['credit_sub'],
    ];
}

$flash = pop_flash();
[$fyFirst, $fyLast] = acc_fy_bounds($book, $fiscalYear);
$list = $pdo->prepare(
    "SELECT m.*, (SELECT COUNT(*) FROM acc_attachments a WHERE a.manual_id = m.id AND a.voided_at IS NULL) AS files
     FROM acc_manual m WHERE m.book_id = :b AND m.kind IN ('in','out','xfer') AND m.entry_date BETWEEN :f AND :t ORDER BY m.entry_date DESC, m.id DESC LIMIT 500"
);
$list->execute([':b' => $bookId, ':f' => $fyFirst, ':t' => $fyLast]);
$rows = $list->fetchAll();
$attachments = [];
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM acc_attachments WHERE manual_id = :m AND book_id = :b AND voided_at IS NULL ORDER BY id');
    $stmt->execute([':m' => $editId, ':b' => $bookId]);
    $attachments = $stmt->fetchAll();
}
$subStmt = $pdo->prepare(
    'SELECT DISTINCT s FROM (SELECT debit_sub AS s FROM acc_manual WHERE book_id = :b1 UNION SELECT credit_sub FROM acc_manual WHERE book_id = :b2 UNION SELECT sub FROM acc_opening WHERE book_id = :b3) x WHERE s IS NOT NULL AND s <> "" ORDER BY s'
);
$subStmt->execute([':b1' => $bookId, ':b2' => $bookId, ':b3' => $bookId]);
$subNames = $subStmt->fetchAll(PDO::FETCH_COLUMN);
$taxDefaults = [];
foreach ($chart as $name => $c) {
    $taxDefaults[$name] = $c['default_tax'];
}
$yearOptions = range(acc_current_fiscal_year($book) + 1, acc_current_fiscal_year($book) - 5);

$labels = [
    'in' => ['a' => '入金先', 'b' => '相手科目（何の入金か）', 'tax' => '相手科目の税区分', 'cp' => '入金元'],
    'out' => ['a' => '勘定科目（何の支払いか）', 'b' => '支払方法', 'tax' => '税区分', 'cp' => '支払先'],
    'xfer' => ['a' => '振替先', 'b' => '振替元', 'tax' => '', 'cp' => ''],
][$kind];
$aList = $kind === 'out' ? $accountNames : ($kind === 'in' ? $fundNames : $fundNames);
$bList = $kind === 'in' ? $accountNames : $fundNames;

acc_render_header($admin, '取引入力', 'input', $bookId);
acc_render_messages($flash);
?>
<div class="yb-toolbar" style="margin-top:8px"><?php acc_render_book_selector($books, $book, '/admin/accounting_input.php', ['kind' => $kind]); ?></div>
<?php if ($settings['opening_fy'] === null) : ?>
    <p class="notice">記帳開始年度が未設定です。先に「設定 → 期首残高・基本情報」で登録してください（取引は登録できますが、決算書には期首残高が必要です）。</p>
<?php endif; ?>
<div class="tabs">
    <?php foreach ($kindLabels as $k => $l) : ?>
        <a href="/admin/accounting_input.php?book=<?= $bookId ?>&amp;kind=<?= $k ?>"<?= $k === $kind ? ' class="active"' : '' ?>><?= acc_h($l) ?></a>
    <?php endforeach; ?>
</div>
<div class="yb-panel">
    <h3><?= $editRow ? '取引の訂正（No.' . (int) $editRow['id'] . '）' : acc_h($kindLabels[$kind]) . 'を入力' ?></h3>
    <datalist id="taxes"><?php foreach (ACC_TAX_CLASSES as $t) : ?><option value="<?= acc_h($t) ?>"><?php endforeach; ?></datalist>
    <datalist id="list_a"><?php foreach ($aList as $n) : ?><option value="<?= acc_h($n) ?>"><?php endforeach; ?></datalist>
    <datalist id="list_b"><?php foreach ($bList as $n) : ?><option value="<?= acc_h($n) ?>"><?php endforeach; ?></datalist>
    <datalist id="subs"><?php foreach ($subNames as $n) : ?><option value="<?= acc_h($n) ?>"><?php endforeach; ?></datalist>
    <form method="post" action="<?= acc_h($self) ?>" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= acc_h(csrf_token()) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="kind" value="<?= acc_h($kind) ?>">
        <?php if ($editRow) : ?><input type="hidden" name="edit_id" value="<?= (int) $editRow['id'] ?>"><?php endif; ?>
        <table class="list" style="max-width:760px">
            <tr><th style="text-align:left;width:11em">日付</th><td><input type="date" name="entry_date" value="<?= acc_h($form['entry_date']) ?>" required></td></tr>
            <tr><th style="text-align:left"><?= acc_h($labels['a']) ?></th>
                <td><input type="text" name="account_a" list="list_a" value="<?= acc_h($form['account_a']) ?>" size="18" required id="account_a">
                    補助科目 <input type="text" name="sub_a" list="subs" value="<?= acc_h($form['sub_a']) ?>" size="16" placeholder="口座名・カード名など"></td></tr>
            <tr><th style="text-align:left"><?= acc_h($labels['b']) ?></th>
                <td><input type="text" name="account_b" list="list_b" value="<?= acc_h($form['account_b']) ?>" size="18" required id="account_b">
                    補助科目 <input type="text" name="sub_b" list="subs" value="<?= acc_h($form['sub_b']) ?>" size="16"></td></tr>
            <tr><th style="text-align:left">金額（税込）</th><td><input type="text" name="amount" inputmode="numeric" value="<?= acc_h($form['amount']) ?>" size="12" required> 円</td></tr>
            <?php if ($kind !== 'xfer') : ?>
                <tr><th style="text-align:left"><?= acc_h($labels['tax']) ?></th>
                    <td><input type="text" name="tax" list="taxes" value="<?= acc_h($form['tax']) ?>" size="18" id="tax">
                        消費税額（空なら自動） <input type="text" name="tax_amount" inputmode="numeric" value="<?= acc_h($form['tax_amount']) ?>" size="8"></td></tr>
                <tr><th style="text-align:left"><?= acc_h($labels['cp']) ?></th><td><input type="text" name="counterparty" value="<?= acc_h($form['counterparty']) ?>" size="30" maxlength="100"></td></tr>
            <?php endif; ?>
            <tr><th style="text-align:left">摘要</th><td><input type="text" name="description" value="<?= acc_h($form['description']) ?>" size="50" maxlength="100"></td></tr>
            <tr><th style="text-align:left">領収書・請求書<br><span class="muted">画像／PDF</span></th>
                <td>
                    <input type="file" name="files[]" multiple accept="application/pdf,image/jpeg,image/png,image/webp,image/heic">
                    <span class="muted">（1ファイル10MBまで。取引日・金額・取引先で検索できる形で保存します）</span>
                    <?php foreach ($attachments as $at) : ?>
                        <div>
                            <a href="/admin/accounting_file.php?id=<?= (int) $at['id'] ?>&amp;book=<?= $bookId ?>" target="_blank" rel="noopener"><?= acc_h($at['original_name']) ?></a>
                            <span class="muted"><?= acc_h(number_format((int) $at['size'] / 1024, 0)) ?>KB</span>
                            <button type="submit" form="void_<?= (int) $at['id'] ?>" class="danger" onclick="return confirm('この証憑を無効にします（履歴は残ります）。よろしいですか？');">無効にする</button>
                        </div>
                    <?php endforeach; ?>
                </td></tr>
        </table>
        <p>
            <button type="submit" class="primary"><?= $editRow ? '更新' : '登録' ?></button>
            <?php if ($editRow) : ?><a href="<?= acc_h($self) ?>">編集をやめる</a><?php endif; ?>
        </p>
    </form>
    <?php foreach ($attachments as $at) : ?>
        <form method="post" action="<?= acc_h($self . '&edit=' . $editId) ?>" id="void_<?= (int) $at['id'] ?>">
            <input type="hidden" name="csrf_token" value="<?= acc_h(csrf_token()) ?>">
            <input type="hidden" name="action" value="void_attachment">
            <input type="hidden" name="id" value="<?= (int) $at['id'] ?>">
            <input type="hidden" name="edit_id" value="<?= $editId ?>">
        </form>
    <?php endforeach; ?>
</div>
<script>
(function () {
    var defaults = <?= json_encode($taxDefaults, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var kind = <?= json_encode($kind) ?>;
    var tax = document.getElementById('tax');
    var target = document.getElementById(kind === 'in' ? 'account_b' : 'account_a');
    if (!tax || !target) { return; }
    target.addEventListener('change', function () {
        if (defaults[target.value] && (tax.value === '' || tax.value === '対象外' || !tax.dataset.touched)) { tax.value = defaults[target.value]; }
    });
    tax.addEventListener('input', function () { tax.dataset.touched = '1'; });
})();
</script>

<div class="yb-panel">
    <div class="yb-toolbar">
        <form method="get" action="/admin/accounting_input.php" class="inline">
            <input type="hidden" name="book" value="<?= $bookId ?>"><input type="hidden" name="kind" value="<?= acc_h($kind) ?>">
            <label>事業年度: <select name="fy" onchange="this.form.submit()">
                <?php foreach ($yearOptions as $y) : ?><option value="<?= $y ?>"<?= $y === $fiscalYear ? ' selected' : '' ?>><?= $y ?>年<?= (int) $book['fiscal_start_month'] ?>月開始</option><?php endforeach; ?>
            </select></label>
        </form>
        <span class="muted">この事業年度の入金・出金・振替（<?= count($rows) ?>件）</span>
    </div>
    <div class="scroll"><table class="list">
        <thead><tr><th>No.</th><th>日付</th><th>種類</th><th>借方</th><th>貸方</th><th class="num">金額</th><th>取引先</th><th>摘要</th><th>証憑</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r) : ?>
            <tr>
                <td>M<?= (int) $r['id'] ?></td>
                <td><?= acc_h($r['entry_date']) ?></td>
                <td><?= acc_h($kindLabels[$r['kind']] ?? $r['kind']) ?></td>
                <td><?= acc_h($r['debit_account'] . ($r['debit_sub'] ? '（' . $r['debit_sub'] . '）' : '')) ?></td>
                <td><?= acc_h($r['credit_account'] . ($r['credit_sub'] ? '（' . $r['credit_sub'] . '）' : '')) ?></td>
                <td class="num"><?= number_format((int) $r['amount']) ?></td>
                <td><?= acc_h($r['counterparty']) ?></td>
                <td><?= acc_h($r['description']) ?></td>
                <td><?= (int) $r['files'] > 0 ? (int) $r['files'] . '件' : '<span class="muted">なし</span>' ?></td>
                <td>
                    <a href="/admin/accounting_input.php?book=<?= $bookId ?>&amp;kind=<?= acc_h($r['kind']) ?>&amp;edit=<?= (int) $r['id'] ?>">訂正</a>
                    <form method="post" action="<?= acc_h($self) ?>" class="inline">
                        <input type="hidden" name="csrf_token" value="<?= acc_h(csrf_token()) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                        <button type="submit" class="danger" onclick="return confirm('この取引を削除します（履歴は残ります）。よろしいですか？');">削除</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($rows === []) : ?><tr><td colspan="10" class="muted">この事業年度の取引はまだありません。</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
<?php acc_render_footer(); ?>
