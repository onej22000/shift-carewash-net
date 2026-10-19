<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/quotation_common.php';

$admin = require_login('admin');
$pdo = getPdo();

$facilities = quotation_fetch_facilities($pdo);
$today = (new DateTime('today'))->format('Y-m-d');
$errors = [];

// 初期値（?copy=ID なら既存の見積書の内容を引き継ぎ、発行日・有効期限だけ今日基準にする）
$form = [
    'facility_id' => '',
    'recipient_name' => '',
    'recipient_honorific' => '御中',
    'recipient_address' => '',
    'contact_person' => '',
    'resident_count' => '',
    'unit_price' => (string) QUOTATION_DEFAULT_UNIT_PRICE,
    'has_odor_mini_option' => '',
    'issue_date' => $today,
    'valid_until' => quotation_default_valid_until($today),
    'note' => '',
    'lead_id' => '',
];
$copySource = isset($_GET['copy']) ? quotation_fetch($pdo, (int) $_GET['copy']) : null;
if ($copySource !== null) {
    foreach (['recipient_name', 'recipient_honorific', 'recipient_address', 'contact_person', 'resident_count', 'unit_price', 'note'] as $key) {
        $form[$key] = (string) ($copySource[$key] ?? '');
    }
    $form['facility_id'] = (string) ($copySource['facility_id'] ?? '');
    $form['has_odor_mini_option'] = (int) $copySource['has_odor_mini_option'] === 1 ? '1' : '';
}
// 営業先の詳細画面から作成する場合（?lead_id=ID）は宛先をその営業先の内容で埋め、発行時もその営業先に紐づける
$leadSource = isset($_GET['lead_id']) ? lead_fetch($pdo, (int) $_GET['lead_id']) : null;
if ($leadSource !== null) {
    $form['lead_id'] = (string) $leadSource['id'];
    $form['facility_id'] = (string) ($leadSource['facility_id'] ?? '');
    $form['recipient_name'] = (string) $leadSource['name'];
    $form['recipient_address'] = (string) ($leadSource['address'] ?? '');
    $form['contact_person'] = (string) ($leadSource['contact_person'] ?? '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (array_keys($form) as $key) {
        $form[$key] = (string) ($_POST[$key] ?? '');
    }
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = '不正なリクエストです。画面を再読み込みしてやり直してください。';
    } else {
        $values = quotation_parse_input($pdo, $_POST);
        if (isset($values['errors'])) {
            $errors = $values['errors'];
        } else {
            $leadId = (int) $form['lead_id'] > 0 ? (int) $form['lead_id'] : null;
            $id = quotation_insert($pdo, $values, (int) $admin['id'], $leadId);
            $created = quotation_fetch($pdo, $id);
            set_flash('success', '見積書 ' . $created['quote_no'] . ' を発行しました。');
            header('Location: /admin/quotation_view.php?id=' . $id);
            exit;
        }
    }
}

$csrfToken = csrf_token();
$facilityJson = [];
foreach ($facilities as $f) {
    $facilityJson[(int) $f['id']] = ['name' => $f['name'], 'address' => (string) ($f['address'] ?? '')];
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>見積書の作成 | 管理者</title>
    <style>
        body { font-family: sans-serif; margin: 16px; color: #222; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 8px; }
        h1 { font-size: 1.3em; margin: 0; }
        .message { padding: 8px 12px; border-radius: 4px; margin-bottom: 12px; }
        .message.error { background: #fdecea; color: #b3261e; }
        .message ul { margin: 0; padding-left: 18px; }
        fieldset { border: 1px solid #ccc; border-radius: 4px; padding: 12px; margin-bottom: 16px; max-width: 760px; }
        legend { font-weight: bold; }
        .form-row { margin-bottom: 10px; display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
        .form-row > label { width: 130px; }
        .form-row input[type="text"] { width: 320px; max-width: 100%; }
        .form-row input[type="number"] { width: 120px; }
        .form-row textarea { width: 460px; max-width: 100%; height: 90px; }
        .hint { font-size: 0.85em; color: #666; }
        table.calc { border-collapse: collapse; }
        table.calc th, table.calc td { border: 1px solid #ccc; padding: 6px 12px; }
        table.calc th { background: #f5f5f5; text-align: left; }
        table.calc td { text-align: right; min-width: 120px; }
        table.calc tr.total td, table.calc tr.total th { font-weight: bold; }
        button.primary { padding: 8px 20px; font-size: 1em; }
    </style>
</head>
<body>
<header>
    <h1>見積書の作成</h1>
    <nav>ログイン中: <?= quotation_esc($admin['name']) ?>さん（管理者） | <a href="/admin/quotations.php">見積書一覧</a> | <a href="/admin/dashboard.php">ダッシュボード</a> | <a href="/admin/logout.php">ログアウト</a></nav>
</header>

<?php if ($errors !== []): ?>
    <div class="message error"><ul><?php foreach ($errors as $e): ?><li><?= quotation_esc($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<?php if ($copySource !== null && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
    <p class="hint"><?= quotation_esc($copySource['quote_no']) ?> の内容を引き継いでいます。発行すると新しい見積番号が付きます。</p>
<?php endif; ?>
<?php if ($leadSource !== null && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
    <p class="hint">営業先「<?= quotation_esc($leadSource['name']) ?>」の見積書を作成します。発行するとこの営業先に紐づきます。</p>
<?php endif; ?>

<form method="post" action="/admin/quotation_edit.php">
    <input type="hidden" name="csrf_token" value="<?= quotation_esc($csrfToken) ?>">
    <input type="hidden" name="lead_id" value="<?= quotation_esc($form['lead_id']) ?>">

    <fieldset>
        <legend>宛先</legend>
        <div class="form-row">
            <label for="facility_id">施設から選択</label>
            <select id="facility_id" name="facility_id">
                <option value="">（手入力）</option>
                <?php foreach ($facilities as $f): ?>
                    <option value="<?= (int) $f['id'] ?>"<?= $form['facility_id'] === (string) $f['id'] ? ' selected' : '' ?>><?= quotation_esc($f['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <span class="hint">選ぶと施設名・住所が入ります（入居者数は入りません）</span>
        </div>
        <div class="form-row">
            <label for="recipient_name">施設名・会社名</label>
            <input type="text" id="recipient_name" name="recipient_name" maxlength="100" required value="<?= quotation_esc($form['recipient_name']) ?>">
            <select name="recipient_honorific" aria-label="敬称">
                <?php foreach (QUOTATION_HONORIFICS as $h): ?>
                    <option value="<?= quotation_esc($h) ?>"<?= $form['recipient_honorific'] === $h ? ' selected' : '' ?>><?= quotation_esc($h) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-row">
            <label for="recipient_address">住所</label>
            <input type="text" id="recipient_address" name="recipient_address" maxlength="255" value="<?= quotation_esc($form['recipient_address']) ?>">
        </div>
        <div class="form-row">
            <label for="contact_person">ご担当者名</label>
            <input type="text" id="contact_person" name="contact_person" maxlength="100" value="<?= quotation_esc($form['contact_person']) ?>">
            <span class="hint">PDFには「ご担当：◯◯ 様」と出ます</span>
        </div>
    </fieldset>

    <fieldset>
        <legend>金額</legend>
        <div class="form-row">
            <label for="resident_count">入居者数</label>
            <input type="number" id="resident_count" name="resident_count" min="1" max="9999" step="1" required value="<?= quotation_esc($form['resident_count']) ?>"> 名
        </div>
        <div class="form-row">
            <label for="unit_price">単価（税抜）</label>
            <input type="number" id="unit_price" name="unit_price" min="0" max="999999" step="1" required value="<?= quotation_esc($form['unit_price']) ?>"> 円／人・月
            <span class="hint">既定 <?= number_format(QUOTATION_DEFAULT_UNIT_PRICE) ?>円。この見積りだけ変更できます</span>
        </div>
        <div class="form-row">
            <label for="has_odor_mini_option">オプション</label>
            <label><input type="checkbox" id="has_odor_mini_option" name="has_odor_mini_option" value="1"<?= $form['has_odor_mini_option'] === '1' ? ' checked' : '' ?>> <?= quotation_esc(QUOTATION_ODOR_MINI_ITEM_NAME) ?>を追加する</label>
            <span class="hint">入居者数 × <?= number_format(QUOTATION_ODOR_MINI_UNIT_PRICE) ?>円（税抜）を上乗せします</span>
        </div>
        <p class="hint">集荷頻度：<?= quotation_esc(QUOTATION_PICKUP_FREQUENCY) ?>（すべての見積書に固定で記載されます）</p>
        <table class="calc">
            <tr><th>基本利用料</th><td id="calc-base">-</td></tr>
            <tr id="calc-odor-row"><th><?= quotation_esc(QUOTATION_ODOR_MINI_ITEM_NAME) ?></th><td id="calc-odor">-</td></tr>
            <tr><th>税抜金額（小計）</th><td id="calc-subtotal">-</td></tr>
            <tr><th>消費税（<?= QUOTATION_TAX_RATE ?>%・切り捨て）</th><td id="calc-tax">-</td></tr>
            <tr class="total"><th>税込金額（月額）</th><td id="calc-total">-</td></tr>
        </table>
    </fieldset>

    <fieldset>
        <legend>発行情報</legend>
        <div class="form-row">
            <label for="issue_date">発行日</label>
            <input type="date" id="issue_date" name="issue_date" required value="<?= quotation_esc($form['issue_date']) ?>">
            <span class="hint">見積番号は発行時に自動で付きます（Q-発行日-連番）</span>
        </div>
        <div class="form-row">
            <label for="valid_until">有効期限</label>
            <input type="date" id="valid_until" name="valid_until" required value="<?= quotation_esc($form['valid_until']) ?>">
            <span class="hint">発行日を変えると1か月後に合わせ直します</span>
        </div>
        <div class="form-row">
            <label for="note">備考</label>
            <textarea id="note" name="note" maxlength="2000"><?= quotation_esc($form['note']) ?></textarea>
        </div>
    </fieldset>

    <button type="submit" class="primary">見積書を発行する</button>
</form>

<script>
(function () {
    var facilities = <?= json_encode($facilityJson, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var taxRate = <?= QUOTATION_TAX_RATE ?>;
    var odorMiniUnitPrice = <?= QUOTATION_ODOR_MINI_UNIT_PRICE ?>;
    var $ = function (id) { return document.getElementById(id); };
    var yen = function (n) { return '¥' + n.toLocaleString('ja-JP'); };

    function recalc() {
        var count = parseInt($('resident_count').value, 10);
        var price = parseInt($('unit_price').value, 10);
        var withOdorMini = $('has_odor_mini_option').checked;
        $('calc-odor-row').style.display = withOdorMini ? '' : 'none';
        if (!(count > 0) || !(price >= 0)) {
            ['calc-base', 'calc-odor', 'calc-subtotal', 'calc-tax', 'calc-total'].forEach(function (id) { $(id).textContent = '-'; });
            return;
        }
        // サーバー側の quotation_calc_amounts() と同じ計算
        var base = count * price;
        var odorMini = withOdorMini ? count * odorMiniUnitPrice : 0;
        var subtotal = base + odorMini;
        var tax = Math.floor(subtotal * taxRate / 100);
        $('calc-base').textContent = yen(base);
        $('calc-odor').textContent = yen(odorMini);
        $('calc-subtotal').textContent = yen(subtotal);
        $('calc-tax').textContent = yen(tax);
        $('calc-total').textContent = yen(subtotal + tax);
    }

    // サーバー側の quotation_default_valid_until() と同じ（翌月に同じ日がなければ翌月末）
    function oneMonthLater(ymd) {
        var p = ymd.split('-').map(Number);
        var y = p[1] === 12 ? p[0] + 1 : p[0];
        var m = p[1] === 12 ? 1 : p[1] + 1;
        var last = new Date(y, m, 0).getDate();
        var pad = function (n) { return (n < 10 ? '0' : '') + n; };
        return y + '-' + pad(m) + '-' + pad(Math.min(p[2], last));
    }

    $('facility_id').addEventListener('change', function () {
        var f = facilities[this.value];
        if (!f) { return; }
        $('recipient_name').value = f.name;
        $('recipient_address').value = f.address;
    });
    $('issue_date').addEventListener('change', function () {
        if (/^\d{4}-\d{2}-\d{2}$/.test(this.value)) { $('valid_until').value = oneMonthLater(this.value); }
    });
    $('resident_count').addEventListener('input', recalc);
    $('unit_price').addEventListener('input', recalc);
    $('has_odor_mini_option').addEventListener('change', recalc);
    recalc();
})();
</script>
</body>
</html>
