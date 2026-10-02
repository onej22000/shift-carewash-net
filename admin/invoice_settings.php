<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/invoice_common.php';

$admin = require_login('admin');
$pdo = getPdo();

$tabs = ['issuer' => '当方事業所・振込口座', 'clients' => '請求先', 'prices' => '施設別単価'];
$tab = (string) ($_GET['tab'] ?? $_POST['tab'] ?? 'issuer');
if (!isset($tabs[$tab])) {
    $tab = 'issuer';
}

function settings_redirect(string $tab, string $query = ''): void
{
    header('Location: /admin/invoice_settings.php?tab=' . urlencode($tab) . $query);
    exit;
}

// 文字列項目を取り出す（前後空白除去・最大長チェック）。空文字は null。
function settings_text(array $source, string $key, int $maxLength, array &$errors, string $label, bool $required = false): ?string
{
    $value = trim((string) ($source[$key] ?? ''));
    if ($value === '') {
        if ($required) {
            $errors[] = $label . 'を入力してください。';
        }
        return null;
    }
    if (mb_strlen($value) > $maxLength) {
        $errors[] = $label . 'は' . $maxLength . '文字以内で入力してください。';
    }
    return $value;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('error', '不正なリクエストです。画面を再読み込みしてやり直してください。');
        settings_redirect($tab);
    }
    $action = (string) ($_POST['action'] ?? '');
    $errors = [];

    if ($action === 'save_issuer') {
        $fields = [
            'company_name' => [100, '会社名', true], 'representative' => [100, '代表者', false],
            'postal' => [10, '郵便番号', false], 'address1' => [200, '住所1', false], 'address2' => [200, '住所2', false],
            'tel' => [20, 'TEL', false], 'registration_no' => [14, '登録番号', false],
            'bank_name' => [100, '銀行名', false], 'bank_branch' => [100, '支店名', false], 'account_type' => [10, '口座種別', false],
            'account_no' => [20, '口座番号', false], 'account_holder' => [100, '口座名義', false], 'payment_terms' => [200, '支払条件', false],
        ];
        $values = [];
        foreach ($fields as $key => [$max, $label, $required]) {
            $values[$key] = settings_text($_POST, $key, $max, $errors, $label, $required);
        }
        if ($values['registration_no'] !== null) {
            $values['registration_no'] = strtoupper(str_replace(['-', ' '], '', $values['registration_no']));
            if (!preg_match('/\AT\d{13}\z/', $values['registration_no'])) {
                $errors[] = '登録番号は「T」＋13桁の数字で入力してください。';
            }
        }
        $nextNo = inv_parse_int($_POST['next_invoice_no'] ?? null);
        $maxIssued = (int) $pdo->query('SELECT COALESCE(MAX(invoice_no), 0) FROM inv_invoices')->fetchColumn();
        if ($nextNo === null || $nextNo < 1) {
            $errors[] = '次回の請求書番号は1以上の整数で入力してください。';
        } elseif ($nextNo <= $maxIssued) {
            $errors[] = '次回の請求書番号は、発行済みの最大番号（' . $maxIssued . '）より大きくしてください。';
        }
        if ($errors) {
            set_flash('error', '保存していません。' . implode(' ／ ', $errors));
            settings_redirect('issuer');
        }
        $columns = array_keys($values);
        $placeholders = array_map(fn ($col) => ':' . $col, $columns);
        $updates = array_map(fn ($col) => $col . ' = VALUES(' . $col . ')', $columns);
        $params = [];
        foreach ($values as $key => $value) {
            $params[':' . $key] = $value;
        }
        $params[':next_invoice_no'] = $nextNo;
        $pdo->prepare(
            'INSERT INTO inv_issuer (id, ' . implode(', ', $columns) . ', next_invoice_no, updated_at)
             VALUES (1, ' . implode(', ', $placeholders) . ', :next_invoice_no, NOW())
             ON DUPLICATE KEY UPDATE ' . implode(', ', $updates) . ', next_invoice_no = VALUES(next_invoice_no), updated_at = NOW()'
        )->execute($params);
        set_flash('success', '当方事業所情報を保存しました（確定済みの請求書の表示は変わりません）。');
        settings_redirect('issuer');
    }

    if ($action === 'save_client') {
        $clientId = (int) ($_POST['client_id'] ?? 0);
        $values = [
            'client_code' => settings_text($_POST, 'client_code', 10, $errors, '請求先コード'),
            'name' => settings_text($_POST, 'name', 100, $errors, '請求先名', true),
            'honorific' => settings_text($_POST, 'honorific', 10, $errors, '敬称') ?? '御中',
            'postal' => settings_text($_POST, 'postal', 10, $errors, '郵便番号'),
            'address1' => settings_text($_POST, 'address1', 200, $errors, '住所1'),
            'address2' => settings_text($_POST, 'address2', 200, $errors, '住所2'),
            'tel' => settings_text($_POST, 'tel', 20, $errors, 'TEL'),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ];
        if ($clientId > 0 && inv_fetch_client($pdo, $clientId) === null) {
            $errors[] = '請求先が見つかりません。';
        }
        if ($errors) {
            set_flash('error', '保存していません。' . implode(' ／ ', $errors));
            settings_redirect('clients', $clientId > 0 ? '&edit=' . $clientId : '');
        }
        $params = [];
        foreach ($values as $key => $value) {
            $params[':' . $key] = $value;
        }
        if ($clientId > 0) {
            $params[':id'] = $clientId;
            $pdo->prepare(
                'UPDATE inv_clients SET client_code = :client_code, name = :name, honorific = :honorific, postal = :postal,
                     address1 = :address1, address2 = :address2, tel = :tel, is_active = :is_active, updated_at = NOW()
                 WHERE id = :id'
            )->execute($params);
        } else {
            $pdo->prepare(
                'INSERT INTO inv_clients (client_code, name, honorific, postal, address1, address2, tel, is_active, updated_at)
                 VALUES (:client_code, :name, :honorific, :postal, :address1, :address2, :tel, :is_active, NOW())'
            )->execute($params);
        }
        set_flash('success', '請求先を保存しました（確定済みの請求書の表示は変わりません）。');
        settings_redirect('clients');
    }

    if ($action === 'save_price') {
        $priceId = (int) ($_POST['price_id'] ?? 0);
        $clientId = (int) ($_POST['client_id'] ?? 0);
        $facilityId = (int) ($_POST['facility_id'] ?? 0);
        $productCode = settings_text($_POST, 'product_code', 10, $errors, '商品コード');
        $itemName = settings_text($_POST, 'item_name', 200, $errors, '商品名', true);
        $unit = settings_text($_POST, 'unit', 10, $errors, '単位');
        $unitPrice = inv_parse_int($_POST['unit_price'] ?? null);
        $effectiveFrom = (string) ($_POST['effective_from'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        if (inv_fetch_client($pdo, $clientId) === null) {
            $errors[] = '請求先を選択してください。';
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM facilities WHERE id = :id');
        $stmt->execute([':id' => $facilityId]);
        if ((int) $stmt->fetchColumn() === 0) {
            $errors[] = '施設を選択してください。';
        }
        if ($unitPrice === null || $unitPrice < 0) {
            $errors[] = '単価は0以上の整数（円・税込）で入力してください。';
        }
        if (!inv_is_date($effectiveFrom)) {
            $errors[] = '適用開始日を入力してください。';
        }
        if (!$errors) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM inv_unit_prices WHERE facility_id = :facility_id AND effective_from = :effective_from AND id <> :id');
            $stmt->execute([':facility_id' => $facilityId, ':effective_from' => $effectiveFrom, ':id' => $priceId]);
            if ((int) $stmt->fetchColumn() > 0) {
                $errors[] = '同じ施設・同じ適用開始日の単価が既に登録されています。';
            }
        }
        if ($errors) {
            set_flash('error', '保存していません。' . implode(' ／ ', $errors));
            settings_redirect('prices', $priceId > 0 ? '&edit=' . $priceId : '');
        }
        $params = [
            ':client_id' => $clientId, ':facility_id' => $facilityId, ':product_code' => $productCode,
            ':item_name' => $itemName, ':unit' => $unit ?? '人', ':unit_price' => $unitPrice,
            ':effective_from' => $effectiveFrom, ':is_active' => $isActive,
        ];
        if ($priceId > 0) {
            $params[':id'] = $priceId;
            $pdo->prepare(
                'UPDATE inv_unit_prices SET client_id = :client_id, facility_id = :facility_id, product_code = :product_code,
                     item_name = :item_name, unit = :unit, unit_price = :unit_price, effective_from = :effective_from, is_active = :is_active
                 WHERE id = :id'
            )->execute($params);
        } else {
            $pdo->prepare(
                'INSERT INTO inv_unit_prices (client_id, facility_id, product_code, item_name, unit, unit_price, effective_from, is_active, created_at)
                 VALUES (:client_id, :facility_id, :product_code, :item_name, :unit, :unit_price, :effective_from, :is_active, NOW())'
            )->execute($params);
        }
        set_flash('success', '単価を保存しました（確定済みの請求書の金額は変わりません。下書きは作り直すと反映されます）。');
        settings_redirect('prices');
    }

    set_flash('error', '不明な操作です。');
    settings_redirect($tab);
}

$flash = pop_flash();
$csrfToken = csrf_token();
$issuer = inv_fetch_issuer($pdo);
$clients = $pdo->query('SELECT * FROM inv_clients ORDER BY is_active DESC, client_code, id')->fetchAll();
$facilities = $pdo->query(
    "SELECT id, name, onboarding_start_date FROM facilities
     WHERE facility_type IS NULL OR facility_type != 'クリーニング所'
     ORDER BY onboarding_start_date IS NULL, onboarding_start_date, id"
)->fetchAll();
$prices = $pdo->query(
    'SELECT p.*, f.name AS facility_name, c.name AS client_name
     FROM inv_unit_prices p
     LEFT JOIN facilities f ON f.id = p.facility_id
     LEFT JOIN inv_clients c ON c.id = p.client_id
     ORDER BY f.onboarding_start_date, p.facility_id, p.effective_from DESC'
)->fetchAll();
$editId = (int) ($_GET['edit'] ?? 0);
$editClient = null;
$editPrice = null;
if ($tab === 'clients' && $editId > 0) {
    $editClient = inv_fetch_client($pdo, $editId);
}
if ($tab === 'prices' && $editId > 0) {
    foreach ($prices as $price) {
        if ((int) $price['id'] === $editId) {
            $editPrice = $price;
        }
    }
}
$facilityNames = [];
foreach ($facilities as $facility) {
    $facilityNames[(int) $facility['id']] = $facility['name'];
}
$fv = fn (?array $row, string $key, string $default = ''): string => inv_h($row[$key] ?? $default);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>請求設定 | 管理者</title>
    <style>
<?= inv_common_css() ?>
        .tabs { display: flex; gap: 4px; border-bottom: 2px solid #0b5ed7; margin-bottom: 16px; }
        .tabs a { padding: 8px 16px; text-decoration: none; color: #0b5ed7; border: 1px solid #ccc; border-bottom: none; border-radius: 6px 6px 0 0; background: #f5f5f5; }
        .tabs a.active { background: #0b5ed7; color: #fff; border-color: #0b5ed7; }
    </style>
</head>
<body>
<header>
    <h1>請求設定</h1>
    <nav><?= inv_admin_nav($admin, 'settings') ?></nav>
</header>

<?php if ($flash !== null): ?>
    <p class="message <?= inv_h($flash['type']) ?>"><?= inv_h($flash['message']) ?></p>
<?php endif; ?>

<div class="tabs">
    <?php foreach ($tabs as $key => $label): ?>
        <a href="?tab=<?= inv_h($key) ?>" class="<?= $key === $tab ? 'active' : '' ?>"><?= inv_h($label) ?></a>
    <?php endforeach; ?>
</div>

<?php if ($tab === 'issuer'): ?>
    <p class="muted">ここでの変更は下書き・今後確定する請求書に反映されます。確定済みの請求書は確定時の内容のまま表示されます。</p>
    <form method="post" action="/admin/invoice_settings.php">
        <input type="hidden" name="csrf_token" value="<?= inv_h($csrfToken) ?>">
        <input type="hidden" name="action" value="save_issuer">
        <input type="hidden" name="tab" value="issuer">
        <div class="form-grid">
            <label>会社名（必須）</label><input type="text" name="company_name" value="<?= $fv($issuer, 'company_name') ?>" maxlength="100" required>
            <label>代表者</label><input type="text" name="representative" value="<?= $fv($issuer, 'representative') ?>" maxlength="100">
            <label>郵便番号</label><input type="text" name="postal" value="<?= $fv($issuer, 'postal') ?>" maxlength="10" placeholder="606-0856">
            <label>住所1</label><input type="text" name="address1" value="<?= $fv($issuer, 'address1') ?>" maxlength="200">
            <label>住所2</label><input type="text" name="address2" value="<?= $fv($issuer, 'address2') ?>" maxlength="200">
            <label>TEL</label><input type="text" name="tel" value="<?= $fv($issuer, 'tel') ?>" maxlength="20">
            <label>登録番号</label><input type="text" name="registration_no" value="<?= $fv($issuer, 'registration_no') ?>" maxlength="14" placeholder="T1234567890123（適格請求書発行事業者登録番号。入力時のみ印字）">
            <label>銀行名</label><input type="text" name="bank_name" value="<?= $fv($issuer, 'bank_name') ?>" maxlength="100">
            <label>支店名</label><input type="text" name="bank_branch" value="<?= $fv($issuer, 'bank_branch') ?>" maxlength="100">
            <label>口座種別</label><input type="text" name="account_type" value="<?= $fv($issuer, 'account_type') ?>" maxlength="10" placeholder="普通">
            <label>口座番号</label><input type="text" name="account_no" value="<?= $fv($issuer, 'account_no') ?>" maxlength="20">
            <label>口座名義</label><input type="text" name="account_holder" value="<?= $fv($issuer, 'account_holder') ?>" maxlength="100">
            <label>支払条件（任意）</label><input type="text" name="payment_terms" value="<?= $fv($issuer, 'payment_terms') ?>" maxlength="200" placeholder="例：翌月末日までにお振込ください">
            <label>次回の請求書番号</label><input type="number" name="next_invoice_no" value="<?= $fv($issuer, 'next_invoice_no', '10') ?>" min="1" required>
        </div>
        <p><button type="submit" class="primary">保存</button></p>
    </form>

<?php elseif ($tab === 'clients'): ?>
    <table class="list">
        <thead><tr><th>コード</th><th>請求先名</th><th>住所</th><th>TEL</th><th>状態</th><th></th></tr></thead>
        <tbody>
            <?php foreach ($clients as $client): ?>
                <tr>
                    <td><?= inv_h($client['client_code']) ?></td>
                    <td><?= inv_h($client['name'] . ' ' . $client['honorific']) ?></td>
                    <td>〒<?= inv_h($client['postal']) ?> <?= inv_h($client['address1']) ?> <?= inv_h($client['address2']) ?></td>
                    <td><?= inv_h($client['tel']) ?></td>
                    <td><?= $client['is_active'] ? '有効' : '無効' ?></td>
                    <td><a href="?tab=clients&amp;edit=<?= (int) $client['id'] ?>">編集</a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <h2><?= $editClient !== null ? '請求先を編集' : '請求先を追加' ?></h2>
    <form method="post" action="/admin/invoice_settings.php">
        <input type="hidden" name="csrf_token" value="<?= inv_h($csrfToken) ?>">
        <input type="hidden" name="action" value="save_client">
        <input type="hidden" name="tab" value="clients">
        <input type="hidden" name="client_id" value="<?= $editClient !== null ? (int) $editClient['id'] : 0 ?>">
        <div class="form-grid">
            <label>請求先コード</label><input type="text" name="client_code" value="<?= $fv($editClient, 'client_code') ?>" maxlength="10" placeholder="003">
            <label>請求先名（必須）</label><input type="text" name="name" value="<?= $fv($editClient, 'name') ?>" maxlength="100" required>
            <label>敬称</label><input type="text" name="honorific" value="<?= $fv($editClient, 'honorific', '御中') ?>" maxlength="10">
            <label>郵便番号</label><input type="text" name="postal" value="<?= $fv($editClient, 'postal') ?>" maxlength="10">
            <label>住所1</label><input type="text" name="address1" value="<?= $fv($editClient, 'address1') ?>" maxlength="200">
            <label>住所2</label><input type="text" name="address2" value="<?= $fv($editClient, 'address2') ?>" maxlength="200">
            <label>TEL</label><input type="text" name="tel" value="<?= $fv($editClient, 'tel') ?>" maxlength="20">
            <label>有効</label><label><input type="checkbox" name="is_active" value="1"<?= ($editClient === null || $editClient['is_active']) ? ' checked' : '' ?>> 有効</label>
        </div>
        <p>
            <button type="submit" class="primary">保存</button>
            <?php if ($editClient !== null): ?><a href="?tab=clients">キャンセル（新規追加に戻る）</a><?php endif; ?>
        </p>
    </form>

<?php else: ?>
    <p class="muted">請求月の末日時点で有効な最新の適用開始日の単価が使われます。受託開始日をそのまま適用開始日として登録して構いません。単価は税込です。</p>
    <table class="list">
        <thead><tr><th>施設</th><th>請求先</th><th>商品コード</th><th>商品名</th><th>単位</th><th class="num">単価（税込）</th><th>適用開始日</th><th>状態</th><th></th></tr></thead>
        <tbody>
            <?php foreach ($prices as $price): ?>
                <tr>
                    <td><?= inv_h($price['facility_name'] ?? ('施設ID ' . $price['facility_id'])) ?></td>
                    <td><?= inv_h($price['client_name'] ?? '') ?></td>
                    <td><?= inv_h($price['product_code']) ?></td>
                    <td><?= inv_h($price['item_name']) ?></td>
                    <td><?= inv_h($price['unit']) ?></td>
                    <td class="num"><?= inv_yen((int) $price['unit_price']) ?></td>
                    <td><?= inv_h($price['effective_from']) ?></td>
                    <td><?= $price['is_active'] ? '有効' : '無効' ?></td>
                    <td><a href="?tab=prices&amp;edit=<?= (int) $price['id'] ?>">編集</a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <h2><?= $editPrice !== null ? '単価を編集' : '単価を追加（単価改定は新しい適用開始日で追加）' ?></h2>
    <form method="post" action="/admin/invoice_settings.php">
        <input type="hidden" name="csrf_token" value="<?= inv_h($csrfToken) ?>">
        <input type="hidden" name="action" value="save_price">
        <input type="hidden" name="tab" value="prices">
        <input type="hidden" name="price_id" value="<?= $editPrice !== null ? (int) $editPrice['id'] : 0 ?>">
        <div class="form-grid">
            <label>請求先</label>
            <select name="client_id" required>
                <?php foreach ($clients as $client): ?>
                    <option value="<?= (int) $client['id'] ?>"<?= $editPrice !== null && (int) $editPrice['client_id'] === (int) $client['id'] ? ' selected' : '' ?>><?= inv_h('(' . $client['client_code'] . ') ' . $client['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <label>施設</label>
            <select name="facility_id" id="price-facility" required>
                <option value="">選択してください</option>
                <?php foreach ($facilities as $facility): ?>
                    <option value="<?= (int) $facility['id'] ?>"<?= $editPrice !== null && (int) $editPrice['facility_id'] === (int) $facility['id'] ? ' selected' : '' ?>><?= inv_h($facility['name'] . '（受託開始 ' . ($facility['onboarding_start_date'] ?? '未設定') . '）') ?></option>
                <?php endforeach; ?>
            </select>
            <label>商品コード</label><input type="text" name="product_code" value="<?= $fv($editPrice, 'product_code', '004') ?>" maxlength="10">
            <label>商品名（必須）</label><input type="text" name="item_name" id="price-item" value="<?= $fv($editPrice, 'item_name') ?>" maxlength="200" required placeholder="CareWash洗濯代行業務委託料（施設名）">
            <label>単位</label><input type="text" name="unit" value="<?= $fv($editPrice, 'unit', '人') ?>" maxlength="10">
            <label>単価（税込・円）</label><input type="number" name="unit_price" value="<?= $fv($editPrice, 'unit_price') ?>" min="0" step="1" required>
            <label>適用開始日</label><input type="date" name="effective_from" value="<?= $fv($editPrice, 'effective_from') ?>" required>
            <label>有効</label><label><input type="checkbox" name="is_active" value="1"<?= ($editPrice === null || $editPrice['is_active']) ? ' checked' : '' ?>> 有効</label>
        </div>
        <p>
            <button type="submit" class="primary">保存</button>
            <?php if ($editPrice !== null): ?><a href="?tab=prices">キャンセル（新規追加に戻る）</a><?php endif; ?>
        </p>
    </form>
    <script>
        (function () {
            var names = <?= json_encode((object) $facilityNames, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
            var facility = document.getElementById('price-facility');
            var item = document.getElementById('price-item');
            facility.addEventListener('change', function () {
                if (item.value === '' && names[facility.value]) {
                    item.value = 'CareWash洗濯代行業務委託料（' + names[facility.value] + '）';
                }
            });
        })();
    </script>
<?php endif; ?>
</body>
</html>
