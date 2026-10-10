<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/accounting.php';

$admin = require_login('admin');
$pdo = getPdo();

/**
 * 弥生会計連携の設定。
 *   books     … 帳簿（会社・個人）の名称・事業年度の開始月・請求書／給与の自動作成の有無
 *   accounts  … 弥生から出力した「勘定科目一覧（汎用形式）」の取り込み（科目名の存在確認に使う）
 *   map       … 請求書・給与の各項目の勘定科目・補助科目・税区分
 *   templates … 定型仕訳・決算仕訳（毎月の概算計上、翌月・翌期首の戻しなど）
 */

[$book, $books] = acc_select_book($pdo);
$bookId = (int) $book['id'];
$tabs = ['templates' => '定型仕訳・決算仕訳', 'accounts' => '勘定科目', 'map' => '仕訳設定', 'books' => '帳簿'];
$tab = (string) ($_GET['tab'] ?? $_POST['tab'] ?? 'templates');
if (!isset($tabs[$tab])) {
    $tab = 'templates';
}
$self = '/admin/accounting_settings.php?book=' . $bookId . '&tab=' . $tab;

function settings_back(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function settings_text(string $key, int $max, array &$errors, string $label, bool $required = false): ?string
{
    $value = acc_clean_text((string) ($_POST[$key] ?? ''));
    if ($value === '') {
        if ($required) {
            $errors[] = $label . 'を入力してください。';
        }
        return null;
    }
    if (mb_strlen($value) > $max) {
        $errors[] = $label . 'は' . $max . '文字以内で入力してください。';
    }
    return $value;
}

function settings_tax(string $key, array &$errors, string $label): string
{
    $value = acc_clean_text((string) ($_POST[$key] ?? ''));
    if ($value === '') {
        return ACC_TAX_NONE;
    }
    if (!in_array($value, ACC_TAX_CLASSES, true)) {
        $errors[] = $label . 'の税区分「' . $value . '」は一覧にありません。';
    }
    return $value;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('error', '不正なリクエストです。画面を再読み込みしてやり直してください。');
        settings_back($self);
    }
    $action = (string) ($_POST['action'] ?? '');
    $errors = [];

    if ($action === 'save_book') {
        $name = settings_text('name', 100, $errors, '帳簿名', true);
        $start = (int) ($_POST['fiscal_start_month'] ?? 0);
        if ($start < 1 || $start > 12) {
            $errors[] = '事業年度の開始月は1〜12にしてください。';
        }
        $targetId = (int) ($_POST['id'] ?? 0);
        if ($errors) {
            set_flash('error', implode("\n", $errors));
        } else {
            $pdo->prepare('UPDATE acc_books SET name = :n, fiscal_start_month = :s, use_invoice = :i, use_payroll = :p WHERE id = :id')
                ->execute([':n' => $name, ':s' => $start, ':i' => !empty($_POST['use_invoice']) ? 1 : 0, ':p' => !empty($_POST['use_payroll']) ? 1 : 0, ':id' => $targetId]);
            set_flash('success', '帳簿を保存しました。請求書・給与の自動作成を切り替えた場合、すでに出力済みの仕訳は次回の出力で「元データなし（取消）」の逆仕訳になります。');
        }
        settings_back($self);
    }

    if ($action === 'add_book') {
        $name = settings_text('name', 100, $errors, '帳簿名', true);
        $code = strtolower(trim((string) ($_POST['code'] ?? '')));
        if (!preg_match('/\A[a-z0-9_]{2,20}\z/', $code)) {
            $errors[] = 'コードは半角英数字とアンダースコア（2〜20文字）にしてください。';
        }
        $kind = ($_POST['kind'] ?? '') === 'personal' ? 'personal' : 'corporate';
        if ($errors) {
            set_flash('error', implode("\n", $errors));
        } else {
            try {
                $pdo->prepare('INSERT INTO acc_books (code, name, kind) VALUES (:c, :n, :k)')->execute([':c' => $code, ':n' => $name, ':k' => $kind]);
                set_flash('success', '帳簿を追加しました。');
            } catch (PDOException $e) {
                set_flash('error', 'そのコードは使われています。');
            }
        }
        settings_back($self);
    }

    if ($action === 'import_accounts') {
        $file = $_FILES['accounts_file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            set_flash('error', 'ファイルを選択してください。');
            settings_back($self);
        }
        if ((int) $file['size'] > 2 * 1024 * 1024) {
            set_flash('error', 'ファイルが大きすぎます（2MBまで）。');
            settings_back($self);
        }
        $accounts = acc_parse_account_list((string) file_get_contents($file['tmp_name']));
        if ($accounts === []) {
            set_flash('error', '勘定科目が見つかりません。弥生会計の［設定］→［科目設定］の「エクスポート」で「汎用形式」を選んで出力したCSVを選んでください。');
            settings_back($self);
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM acc_accounts WHERE book_id = :b')->execute([':b' => $bookId]);
            $insert = $pdo->prepare('INSERT INTO acc_accounts (book_id, name, code, side, tax_class, hidden) VALUES (:b, :n, :c, :s, :t, :h)');
            foreach ($accounts as $a) {
                $insert->execute([':b' => $bookId, ':n' => mb_substr($a['name'], 0, 60), ':c' => $a['code'], ':s' => $a['side'], ':t' => $a['tax_class'], ':h' => $a['hidden']]);
            }
            $pdo->commit();
            set_flash('success', count($accounts) . '件の勘定科目を取り込みました（この帳簿の以前の一覧は置き換えました）。');
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('accounting account import failed: ' . $e->getMessage());
            set_flash('error', '取り込みに失敗しました。');
        }
        settings_back($self);
    }

    if ($action === 'save_map') {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO acc_map (book_id, event_key, account, sub, tax_class) VALUES (:b, :k, :a, :s, :t)
                 ON DUPLICATE KEY UPDATE account = VALUES(account), sub = VALUES(sub), tax_class = VALUES(tax_class)'
            );
            foreach (ACC_DEFAULT_MAP as $key => $d) {
                $account = acc_clean_text((string) ($_POST['account'][$key] ?? ''));
                $sub = acc_clean_text((string) ($_POST['sub'][$key] ?? ''));
                $tax = acc_clean_text((string) ($_POST['tax'][$key] ?? ACC_TAX_NONE));
                if ($account === '') {
                    $errors[] = $d['label'] . 'の勘定科目を入力してください。';
                }
                if (!in_array($tax, ACC_TAX_CLASSES, true)) {
                    $errors[] = $d['label'] . 'の税区分が一覧にありません。';
                }
                if ($errors === []) {
                    $stmt->execute([':b' => $bookId, ':k' => $key, ':a' => $account, ':s' => $sub === '' ? null : $sub, ':t' => $tax]);
                }
            }
            if ($errors) {
                $pdo->rollBack();
                set_flash('error', implode("\n", $errors));
            } else {
                $pdo->commit();
                set_flash('success', '仕訳設定を保存しました。出力済みの仕訳の科目が変わる場合は、次回の出力で「変更あり（逆仕訳＋新仕訳）」になります。');
            }
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('accounting map save failed: ' . $e->getMessage());
            set_flash('error', '保存に失敗しました。');
        }
        settings_back($self);
    }

    if ($action === 'add_template') {
        $name = settings_text('name', 100, $errors, '名称', true);
        $da = settings_text('debit_account', 60, $errors, '借方勘定科目', true);
        $ca = settings_text('credit_account', 60, $errors, '貸方勘定科目', true);
        $ds = settings_text('debit_sub', 60, $errors, '借方補助科目');
        $cs = settings_text('credit_sub', 60, $errors, '貸方補助科目');
        $dt = settings_tax('debit_tax', $errors, '借方');
        $ct = settings_tax('credit_tax', $errors, '貸方');
        $description = settings_text('description', 100, $errors, '摘要');
        $amountRaw = str_replace([',', ' '], '', (string) ($_POST['amount'] ?? ''));
        if (!preg_match('/\A-?\d+\z/', $amountRaw) || (int) $amountRaw === 0) {
            $errors[] = '金額は0以外の整数で入力してください。';
        }
        $from = (string) ($_POST['from_month'] ?? '');
        $to = (string) ($_POST['to_month'] ?? '');
        if (!acc_is_month($from)) {
            $errors[] = '計上開始月を選んでください。';
        }
        if ($to !== '' && (!acc_is_month($to) || $to < $from)) {
            $errors[] = '計上終了月は開始月以降にしてください。';
        }
        $monthsInput = array_values(array_unique(array_map('intval', (array) ($_POST['months'] ?? []))));
        $monthsInput = array_values(array_filter($monthsInput, static fn (int $m): bool => $m >= 1 && $m <= 12));
        sort($monthsInput);
        $months = ($monthsInput === [] || count($monthsInput) === 12) ? 'all' : implode(',', $monthsInput);
        $dayRule = (string) ($_POST['day_rule'] ?? 'last');
        if ($dayRule !== 'last' && !(ctype_digit($dayRule) && (int) $dayRule >= 1 && (int) $dayRule <= 28)) {
            $errors[] = '計上日は「月末」または1〜28日にしてください。';
        }
        $reverse = (string) ($_POST['reverse_rule'] ?? 'none');
        if (!in_array($reverse, ['none', 'next_month', 'fy_start'], true)) {
            $reverse = 'none';
        }
        if ($errors) {
            set_flash('error', implode("\n", $errors));
        } else {
            $amount = (int) $amountRaw;
            if ($amount < 0) { // マイナスは借方・貸方を入れ替える
                [$da, $ca, $ds, $cs, $dt, $ct] = [$ca, $da, $cs, $ds, $ct, $dt];
                $amount = -$amount;
            }
            $pdo->prepare(
                'INSERT INTO acc_templates (book_id, name, debit_account, debit_sub, debit_tax, credit_account, credit_sub, credit_tax, amount, months, day_rule, from_month, to_month, reverse_rule, is_settlement, description)
                 VALUES (:b, :n, :da, :ds, :dt, :ca, :cs, :ct, :a, :m, :d, :f, :t, :r, :s, :desc)'
            )->execute([
                ':b' => $bookId, ':n' => $name, ':da' => $da, ':ds' => $ds, ':dt' => $dt, ':ca' => $ca, ':cs' => $cs, ':ct' => $ct,
                ':a' => $amount, ':m' => $months, ':d' => $dayRule, ':f' => $from, ':t' => $to === '' ? null : $to, ':r' => $reverse,
                ':s' => !empty($_POST['is_settlement']) ? 1 : 0, ':desc' => $description,
            ]);
            set_flash('success', '定型仕訳を追加しました。仕訳日記帳の画面で内容を確認してください。');
        }
        settings_back($self);
    }

    if ($action === 'toggle_template' || $action === 'delete_template') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($action === 'toggle_template') {
            $pdo->prepare('UPDATE acc_templates SET is_active = 1 - is_active WHERE id = :id AND book_id = :b')->execute([':id' => $id, ':b' => $bookId]);
            set_flash('success', '切り替えました。無効にした定型仕訳で出力済みの仕訳は、次回の出力で逆仕訳になります。');
        } else {
            $pdo->prepare('DELETE FROM acc_templates WHERE id = :id AND book_id = :b')->execute([':id' => $id, ':b' => $bookId]);
            set_flash('success', '削除しました。出力済みの仕訳は、次回の出力で逆仕訳になります。');
        }
        settings_back($self);
    }

    settings_back($self);
}

$flash = pop_flash();
acc_render_header($admin, '弥生会計の設定', $tab, $bookId);
acc_render_messages($flash);
?>
<div class="yb-toolbar" style="margin-top:8px"><?php acc_render_book_selector($books, $book, '/admin/accounting_settings.php', ['tab' => $tab]); ?></div>
<div class="tabs">
    <?php foreach ($tabs as $key => $label) : ?>
        <a href="/admin/accounting_settings.php?book=<?= $bookId ?>&amp;tab=<?= $key ?>"<?= $key === $tab ? ' class="active"' : '' ?>><?= acc_h($label) ?></a>
    <?php endforeach; ?>
</div>
<div class="yb-panel">
<?php if ($tab === 'books') : ?>
    <p class="muted">弥生会計はデータファイル（会社・個人）ごとに帳簿が別です。帳簿ごとに仕訳の出力先・事業年度・自動作成を分けます。シフトシステムの請求書・給与は合同会社BITBASE（請求書の発行元）のものなので、BITBASEだけ自動作成を有効にしています。</p>
    <?php foreach ($books as $b) : ?>
        <form method="post" action="<?= acc_h($self) ?>">
            <input type="hidden" name="csrf_token" value="<?= acc_h(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_book">
            <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
            <fieldset>
                <legend><?= acc_h($b['name']) ?>（<?= $b['kind'] === 'personal' ? '個人' : '法人' ?>・コード <?= acc_h($b['code']) ?>）</legend>
                <p><label>帳簿名: <input type="text" name="name" value="<?= acc_h($b['name']) ?>" size="30" maxlength="100"></label>
                   <label>事業年度の開始月: <input type="number" name="fiscal_start_month" min="1" max="12" value="<?= (int) $b['fiscal_start_month'] ?>"></label></p>
                <p><label><input type="checkbox" name="use_invoice" value="1"<?= (int) $b['use_invoice'] === 1 ? ' checked' : '' ?>> 請求書（発行済み）から売上仕訳を作る</label><br>
                   <label><input type="checkbox" name="use_payroll" value="1"<?= (int) $b['use_payroll'] === 1 ? ' checked' : '' ?>> 給与（確定済み）から給与仕訳を作る</label></p>
                <button type="submit">保存</button>
            </fieldset>
        </form>
    <?php endforeach; ?>
    <form method="post" action="<?= acc_h($self) ?>">
        <input type="hidden" name="csrf_token" value="<?= acc_h(csrf_token()) ?>">
        <input type="hidden" name="action" value="add_book">
        <fieldset>
            <legend>帳簿を追加</legend>
            <label>帳簿名: <input type="text" name="name" size="24" maxlength="100"></label>
            <label>コード（半角英数字）: <input type="text" name="code" size="12" maxlength="20"></label>
            <label>種別: <select name="kind"><option value="corporate">法人</option><option value="personal">個人</option></select></label>
            <button type="submit">追加</button>
        </fieldset>
    </form>

<?php elseif ($tab === 'accounts') : ?>
    <?php
    $stmt = $pdo->prepare('SELECT * FROM acc_accounts WHERE book_id = :b ORDER BY CAST(code AS UNSIGNED), name');
    $stmt->execute([':b' => $bookId]);
    $accounts = $stmt->fetchAll();
    ?>
    <p>弥生会計（<?= acc_h($book['name']) ?>）の勘定科目一覧を取り込むと、仕訳の科目名が弥生に存在するかを出力前に確認できます。</p>
    <ol class="muted">
        <li>弥生会計で［設定］→［科目設定］を開き、［ファイル］→［エクスポート］（または画面のエクスポートボタン）を選びます。</li>
        <li>形式は<strong>汎用形式</strong>を選び、保存したCSVを下で選択します（文字コードはShift-JIS・UTF-8のどちらでも読めます）。</li>
    </ol>
    <form method="post" action="<?= acc_h($self) ?>" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= acc_h(csrf_token()) ?>">
        <input type="hidden" name="action" value="import_accounts">
        <input type="file" name="accounts_file" accept=".csv,.txt" required>
        <button type="submit" class="primary">取り込む（この帳簿の一覧を置き換え）</button>
    </form>
    <h2>取込済みの勘定科目（<?= count($accounts) ?>件）</h2>
    <div class="scroll"><table class="list">
        <thead><tr><th>コード</th><th>勘定科目</th><th>貸借</th><th>既定の税区分</th></tr></thead>
        <tbody>
        <?php foreach ($accounts as $a) : ?>
            <tr><td><?= acc_h($a['code']) ?></td><td><?= acc_h($a['name']) ?><?= $a['hidden'] ? ' <span class="muted">（非表示）</span>' : '' ?></td><td><?= acc_h($a['side']) ?></td><td><?= acc_h($a['tax_class']) ?></td></tr>
        <?php endforeach; ?>
        <?php if ($accounts === []) : ?><tr><td colspan="4" class="muted">未取込です。</td></tr><?php endif; ?>
        </tbody>
    </table></div>

<?php elseif ($tab === 'map') : ?>
    <?php $map = acc_fetch_map($pdo, $bookId); $names = acc_known_accounts($pdo, $bookId) ?? []; ?>
    <p class="muted">請求書・給与の各項目をどの勘定科目・補助科目・税区分で仕訳するかを決めます。補助科目の <code>{client}</code> は請求先名、<code>{employee}</code> は従業員名に置き換わります。
        「初期値」と表示された行は、保存すると上書きされます。通勤手当は消費税法上は課税仕入れのため、税区分の初期値は「課対仕入内10%適格」です（顧問税理士の方針に合わせて変更してください）。</p>
    <datalist id="taxes"><?php foreach (ACC_TAX_CLASSES as $t) : ?><option value="<?= acc_h($t) ?>"><?php endforeach; ?></datalist>
    <datalist id="accounts"><?php foreach (array_keys($names) as $n) : ?><option value="<?= acc_h($n) ?>"><?php endforeach; ?></datalist>
    <form method="post" action="<?= acc_h($self) ?>">
        <input type="hidden" name="csrf_token" value="<?= acc_h(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_map">
        <table class="list">
            <thead><tr><th>項目</th><th>勘定科目</th><th>補助科目</th><th>税区分</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($map as $key => $m) : ?>
                <tr>
                    <td><?= acc_h($m['label']) ?></td>
                    <td><input type="text" name="account[<?= acc_h($key) ?>]" list="accounts" value="<?= acc_h($m['account']) ?>" size="16"><?= ($names !== [] && !isset($names[$m['account']])) ? ' <span class="neg">弥生に無い名称</span>' : '' ?></td>
                    <td><input type="text" name="sub[<?= acc_h($key) ?>]" value="<?= acc_h($m['sub']) ?>" size="14"></td>
                    <td><input type="text" name="tax[<?= acc_h($key) ?>]" list="taxes" value="<?= acc_h($m['tax']) ?>" size="16"></td>
                    <td class="muted"><?= $m['is_default'] ? '初期値' : '保存済み' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <button type="submit" class="primary">保存</button>
    </form>

<?php else : ?>
    <?php
    $stmt = $pdo->prepare('SELECT * FROM acc_templates WHERE book_id = :b ORDER BY id');
    $stmt->execute([':b' => $bookId]);
    $templates = $stmt->fetchAll();
    $reverseLabels = ['none' => '戻さない', 'next_month' => '翌月末に同額を戻す', 'fy_start' => '翌期首月の末日に前期の累計を戻す'];
    $accountNames = array_keys(acc_known_accounts($pdo, $bookId) ?? []);
    ?>
    <p class="muted">毎月同額の概算計上（例: 減価償却費の概算）や、決算整理仕訳を登録します。「戻し」を選ぶと、翌月末（または翌期首月の末日に前期の累計額）に逆仕訳を自動で作ります。
        2025年12月期の仕訳にある「減価償却費／減価償却累計額（概算）→ 翌期首月に概算戻し」と同じ形です。決算整理仕訳は「決算（本期）」にチェックを入れます。</p>
    <datalist id="taxes"><?php foreach (ACC_TAX_CLASSES as $t) : ?><option value="<?= acc_h($t) ?>"><?php endforeach; ?></datalist>
    <datalist id="accounts"><?php foreach ($accountNames as $n) : ?><option value="<?= acc_h($n) ?>"><?php endforeach; ?></datalist>
    <table class="list">
        <thead><tr><th>名称</th><th>借方</th><th>貸方</th><th class="num">金額</th><th>計上月・日</th><th>期間</th><th>戻し</th><th>状態</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($templates as $t) : ?>
            <tr>
                <td><?= acc_h($t['name']) ?><?= $t['is_settlement'] ? ' <span class="muted">（決算）</span>' : '' ?></td>
                <td><?= acc_h($t['debit_account'] . ($t['debit_sub'] !== null ? '（' . $t['debit_sub'] . '）' : '')) ?><br><span class="muted"><?= acc_h($t['debit_tax']) ?></span></td>
                <td><?= acc_h($t['credit_account'] . ($t['credit_sub'] !== null ? '（' . $t['credit_sub'] . '）' : '')) ?><br><span class="muted"><?= acc_h($t['credit_tax']) ?></span></td>
                <td class="num"><?= number_format((int) $t['amount']) ?></td>
                <td><?= $t['months'] === 'all' ? '毎月' : acc_h(str_replace(',', '・', $t['months']) . '月') ?> ／ <?= $t['day_rule'] === 'last' ? '月末' : acc_h($t['day_rule']) . '日' ?></td>
                <td><?= acc_h($t['from_month']) ?>〜<?= acc_h($t['to_month'] ?? '継続') ?></td>
                <td><?= acc_h($reverseLabels[$t['reverse_rule']] ?? '') ?></td>
                <td><?= $t['is_active'] ? '有効' : '無効' ?></td>
                <td>
                    <form method="post" action="<?= acc_h($self) ?>" class="inline">
                        <input type="hidden" name="csrf_token" value="<?= acc_h(csrf_token()) ?>">
                        <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                        <button type="submit" name="action" value="toggle_template"><?= $t['is_active'] ? '無効にする' : '有効にする' ?></button>
                        <button type="submit" name="action" value="delete_template" class="danger" onclick="return confirm('削除します。出力済みの仕訳は次回の出力で逆仕訳になります。よろしいですか？');">削除</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($templates === []) : ?><tr><td colspan="9" class="muted">定型仕訳はまだありません。</td></tr><?php endif; ?>
        </tbody>
    </table>

    <form method="post" action="<?= acc_h($self) ?>">
        <input type="hidden" name="csrf_token" value="<?= acc_h(csrf_token()) ?>">
        <input type="hidden" name="action" value="add_template">
        <fieldset>
            <legend>定型仕訳を追加</legend>
            <p><label>名称: <input type="text" name="name" size="28" maxlength="100" required placeholder="減価償却費（概算）"></label>
               <label>摘要: <input type="text" name="description" size="24" maxlength="100" placeholder="概算（空なら名称）"></label></p>
            <p>借方: <input type="text" name="debit_account" list="accounts" size="14" placeholder="勘定科目" required>
               <input type="text" name="debit_sub" size="10" placeholder="補助科目">
               <input type="text" name="debit_tax" list="taxes" size="14" placeholder="税区分（対象外）"></p>
            <p>貸方: <input type="text" name="credit_account" list="accounts" size="14" placeholder="勘定科目" required>
               <input type="text" name="credit_sub" size="10" placeholder="補助科目">
               <input type="text" name="credit_tax" list="taxes" size="14" placeholder="税区分（対象外）"></p>
            <p><label>金額（税込）: <input type="text" name="amount" inputmode="numeric" size="10" required></label>
               <label>計上開始月: <input type="month" name="from_month" required></label>
               <label>終了月（空=継続）: <input type="month" name="to_month"></label>
               <label>計上日: <select name="day_rule"><option value="last">月末</option><?php for ($d = 1; $d <= 28; $d++) : ?><option value="<?= $d ?>"><?= $d ?>日</option><?php endfor; ?></select></label></p>
            <p>計上する月（暦月・未選択=毎月）:
                <?php for ($m = 1; $m <= 12; $m++) : ?><label><input type="checkbox" name="months[]" value="<?= $m ?>"><?= $m ?></label> <?php endfor; ?></p>
            <p><label>戻し: <select name="reverse_rule">
                <?php foreach ($reverseLabels as $v => $l) : ?><option value="<?= $v ?>"><?= acc_h($l) ?></option><?php endforeach; ?>
            </select></label>
               <label><input type="checkbox" name="is_settlement" value="1"> 決算（本期）の仕訳にする</label></p>
            <button type="submit" class="primary">追加</button>
        </fieldset>
    </form>
<?php endif; ?>
</div>
<?php acc_render_footer(); ?>
