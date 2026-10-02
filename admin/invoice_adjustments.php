<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/invoice_common.php';

$admin = require_login('admin');
$pdo = getPdo();

function adjustments_redirect(string $query = ''): void
{
    header('Location: /admin/invoice_adjustments.php' . $query);
    exit;
}

function fetch_adjustment(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM inv_adjustments WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

// 確定済み請求書の usage 行（訂正元の請求済み数量・単価）。キーは「月|施設ID」。
function issued_usage_map(PDO $pdo): array
{
    $map = [];
    $rows = $pdo->query(
        "SELECT i.billing_month, i.client_id, i.invoice_no, l.facility_id, SUM(l.quantity) AS quantity, MAX(l.unit_price) AS unit_price
         FROM inv_invoices i
         INNER JOIN inv_invoice_lines l ON l.invoice_id = i.id AND l.line_type = 'usage' AND l.facility_id IS NOT NULL
         WHERE i.status = 'issued'
         GROUP BY i.id, l.facility_id"
    )->fetchAll();
    foreach ($rows as $row) {
        $map[$row['billing_month'] . '|' . $row['facility_id']] = [
            'quantity' => (int) $row['quantity'], 'unit_price' => (int) $row['unit_price'],
            'invoice_no' => inv_format_no((int) $row['invoice_no']), 'client_id' => (int) $row['client_id'],
        ];
    }
    return $map;
}

$facilities = $pdo->query(
    "SELECT id, name FROM facilities
     WHERE onboarding_start_date IS NOT NULL AND (facility_type IS NULL OR facility_type != 'クリーニング所')
     ORDER BY onboarding_start_date, id"
)->fetchAll();
$facilityNames = [];
foreach ($facilities as $facility) {
    $facilityNames[(int) $facility['id']] = (string) $facility['name'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('error', '不正なリクエストです。画面を再読み込みしてやり直してください。');
        adjustments_redirect();
    }
    $action = (string) ($_POST['action'] ?? '');
    $adjustmentId = (int) ($_POST['adjustment_id'] ?? 0);
    $existing = $adjustmentId > 0 ? fetch_adjustment($pdo, $adjustmentId) : null;
    if ($adjustmentId > 0 && ($existing === null || $existing['applied_invoice_id'] !== null)) {
        set_flash('error', '確定済みの請求書に反映された訂正・値引きは編集・削除できません。');
        adjustments_redirect();
    }

    if ($action === 'delete' && $existing !== null) {
        $pdo->prepare('DELETE FROM inv_adjustments WHERE id = :id AND applied_invoice_id IS NULL')->execute([':id' => $adjustmentId]);
        set_flash('success', '訂正・値引きを削除しました。反映月の下書きがある場合は作り直してください。');
        adjustments_redirect();
    }

    if ($action !== 'save') {
        set_flash('error', '不明な操作です。');
        adjustments_redirect();
    }

    $errors = [];
    $type = (string) ($_POST['adj_type'] ?? '');
    $clientId = (int) ($_POST['client_id'] ?? 0);
    $applyMonth = (string) ($_POST['apply_month'] ?? '');
    $description = trim((string) ($_POST['description'] ?? ''));
    $reason = trim((string) ($_POST['reason'] ?? ''));
    $targetMonth = null;
    $facilityId = (int) ($_POST['facility_id'] ?? 0);
    $facilityId = isset($facilityNames[$facilityId]) ? $facilityId : null;

    if (!in_array($type, ['correction', 'discount'], true)) {
        $errors[] = '種別を選択してください。';
    }
    if (inv_fetch_client($pdo, $clientId) === null) {
        $errors[] = '請求先を選択してください。';
    }
    if (!inv_is_month($applyMonth)) {
        $errors[] = '反映月を入力してください。';
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM inv_invoices WHERE billing_month = :month AND client_id = :client_id AND status = 'issued'");
        $stmt->execute([':month' => $applyMonth, ':client_id' => $clientId]);
        if ((int) $stmt->fetchColumn() > 0) {
            $errors[] = inv_month_label($applyMonth) . '分は確定済みのため反映できません。翌月以降を反映月にしてください。';
        }
    }

    if ($type === 'correction') {
        $targetMonth = (string) ($_POST['target_month'] ?? '');
        $billed = inv_parse_int($_POST['billed_quantity'] ?? null);
        $correct = inv_parse_int($_POST['correct_quantity'] ?? null);
        $unitPrice = inv_parse_int($_POST['correction_unit_price'] ?? null);
        if (!inv_is_month($targetMonth)) {
            $errors[] = '訂正元の月を入力してください。';
        } elseif (inv_is_month($applyMonth) && $targetMonth >= $applyMonth) {
            $errors[] = '訂正元の月は反映月より前の月にしてください。';
        }
        if ($facilityId === null) {
            $errors[] = '訂正元の施設を選択してください。';
        }
        if ($billed === null || $billed < 0 || $correct === null || $correct < 0) {
            $errors[] = '請求済み人数・正しい人数は0以上の整数で入力してください。';
        } elseif ($billed === $correct) {
            $errors[] = '請求済み人数と正しい人数が同じです（差分がありません）。';
        }
        if ($unitPrice === null || $unitPrice <= 0) {
            $errors[] = '単価は1以上の整数で入力してください。';
        }
        if (!$errors) {
            $quantity = $correct - $billed;
            if ($description === '') {
                $description = (int) substr($targetMonth, 5, 2) . '月分人数訂正（' . $facilityNames[$facilityId] . ' ' . $billed . '名→' . $correct . '名）';
            }
        }
    } else {
        $quantity = inv_parse_int($_POST['quantity'] ?? null);
        $unitPrice = inv_parse_int($_POST['unit_price'] ?? null);
        $targetMonthRaw = (string) ($_POST['discount_target_month'] ?? '');
        $targetMonth = inv_is_month($targetMonthRaw) ? $targetMonthRaw : null;
        if ($quantity === null || $unitPrice === null) {
            $errors[] = '数量・単価は整数で入力してください。';
        } elseif ($quantity * $unitPrice >= 0) {
            $errors[] = '値引きは金額がマイナスになるように入力してください（例：数量21・単価-3325）。';
        }
        if ($description === '') {
            $errors[] = '値引きの文言（請求書の商品名欄）を入力してください。';
        }
    }
    if (mb_strlen($description) > 200) {
        $errors[] = '文言は200文字以内で入力してください。';
    }

    if ($errors) {
        set_flash('error', '保存していません。' . implode(' ／ ', $errors));
        adjustments_redirect($adjustmentId > 0 ? '?edit=' . $adjustmentId : '');
    }

    $params = [
        ':client_id' => $clientId, ':adj_type' => $type, ':apply_month' => $applyMonth, ':target_month' => $targetMonth,
        ':facility_id' => $facilityId, ':description' => $description, ':quantity' => $quantity,
        ':unit_price' => $unitPrice, ':amount' => $quantity * $unitPrice, ':reason' => $reason === '' ? null : $reason,
    ];
    if ($existing !== null) {
        $params[':id'] = $adjustmentId;
        $pdo->prepare(
            'UPDATE inv_adjustments
             SET client_id = :client_id, adj_type = :adj_type, apply_month = :apply_month, target_month = :target_month,
                 facility_id = :facility_id, description = :description, quantity = :quantity, unit_price = :unit_price,
                 amount = :amount, reason = :reason
             WHERE id = :id AND applied_invoice_id IS NULL'
        )->execute($params);
    } else {
        $pdo->prepare(
            'INSERT INTO inv_adjustments
                (client_id, adj_type, apply_month, target_month, facility_id, description, quantity, unit_price, amount, reason, created_at)
             VALUES (:client_id, :adj_type, :apply_month, :target_month, :facility_id, :description, :quantity, :unit_price, :amount, :reason, NOW())'
        )->execute($params);
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM inv_invoices WHERE billing_month = :month AND client_id = :client_id AND status = 'draft'");
    $stmt->execute([':month' => $applyMonth, ':client_id' => $clientId]);
    $draftNotice = (int) $stmt->fetchColumn() > 0 ? inv_month_label($applyMonth) . '分の下書きがあるため、請求書画面で作り直してください。' : '';
    set_flash('success', '訂正・値引きを保存しました（' . inv_yen($quantity * $unitPrice) . '円）。' . $draftNotice);
    adjustments_redirect();
}

$flash = pop_flash();
$csrfToken = csrf_token();
$clients = $pdo->query('SELECT * FROM inv_clients ORDER BY is_active DESC, client_code, id')->fetchAll();
$clientNames = [];
foreach ($clients as $client) {
    $clientNames[(int) $client['id']] = $client['name'];
}
$usageMap = issued_usage_map($pdo);
$adjustments = $pdo->query(
    'SELECT a.*, i.invoice_no, i.status AS invoice_status
     FROM inv_adjustments a
     LEFT JOIN inv_invoices i ON i.id = a.applied_invoice_id
     ORDER BY a.apply_month DESC, a.id DESC'
)->fetchAll();

$edit = null;
$editId = (int) ($_GET['edit'] ?? 0);
if ($editId > 0) {
    $edit = fetch_adjustment($pdo, $editId);
    if ($edit !== null && $edit['applied_invoice_id'] !== null) {
        $edit = null;
    }
}
// 訂正の編集時：請求済み人数は確定請求書から、無ければ文言の「21名→19名」から復元する。
$editBilled = '';
$editCorrect = '';
if ($edit !== null && $edit['adj_type'] === 'correction') {
    $key = $edit['target_month'] . '|' . $edit['facility_id'];
    if (isset($usageMap[$key])) {
        $editBilled = (string) $usageMap[$key]['quantity'];
    } elseif (preg_match('/(\d+)名→(\d+)名/u', (string) $edit['description'], $m)) {
        $editBilled = $m[1];
    }
    if ($editBilled !== '') {
        $editCorrect = (string) ((int) $editBilled + (int) $edit['quantity']);
    }
}
$type = $edit['adj_type'] ?? 'correction';
$prevMonth = (new DateTimeImmutable('first day of last month'))->format('Y-m');
$typeLabels = ['correction' => '訂正', 'discount' => '値引き'];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>訂正・値引き | 管理者</title>
    <style>
<?= inv_common_css() ?>
        .type-fields { display: contents; }
        .calc { font-weight: bold; }
    </style>
</head>
<body>
<header>
    <h1>訂正・値引き</h1>
    <nav><?= inv_admin_nav($admin, 'adjustments') ?></nav>
</header>

<?php if ($flash !== null): ?>
    <p class="message <?= inv_h($flash['type']) ?>"><?= inv_h($flash['message']) ?></p>
<?php endif; ?>

<p class="muted">過去月の人数訂正や値引きを登録すると、「反映月」の請求書の下書き作成時に差額行として追加されます。確定済みの請求書に反映された後は編集・削除できません。</p>

<h2><?= $edit !== null ? '訂正・値引きを編集' : '訂正・値引きを登録' ?></h2>
<?php if (!$clients): ?>
    <p class="notice">請求先が登録されていません。<a href="/admin/invoice_settings.php?tab=clients">請求設定</a>で登録してください。</p>
<?php else: ?>
<form method="post" action="/admin/invoice_adjustments.php" id="adj-form">
    <input type="hidden" name="csrf_token" value="<?= inv_h($csrfToken) ?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="adjustment_id" value="<?= $edit !== null ? (int) $edit['id'] : 0 ?>">
    <div class="form-grid">
        <label>種別</label>
        <div>
            <label><input type="radio" name="adj_type" value="correction"<?= $type === 'correction' ? ' checked' : '' ?>> 訂正（人数の差分）</label>
            <label><input type="radio" name="adj_type" value="discount"<?= $type === 'discount' ? ' checked' : '' ?>> 値引き</label>
        </div>
        <label>請求先</label>
        <select name="client_id">
            <?php foreach ($clients as $client): ?>
                <option value="<?= (int) $client['id'] ?>"<?= $edit !== null && (int) $edit['client_id'] === (int) $client['id'] ? ' selected' : '' ?>><?= inv_h('(' . $client['client_code'] . ') ' . $client['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <label>反映月</label>
        <input type="month" name="apply_month" value="<?= inv_h($edit['apply_month'] ?? $prevMonth) ?>" required>
        <label>施設</label>
        <select name="facility_id" id="adj-facility">
            <option value="">（指定なし）</option>
            <?php foreach ($facilities as $facility): ?>
                <option value="<?= (int) $facility['id'] ?>"<?= $edit !== null && (int) $edit['facility_id'] === (int) $facility['id'] ? ' selected' : '' ?>><?= inv_h($facility['name']) ?></option>
            <?php endforeach; ?>
        </select>

        <div class="type-fields" data-type="correction">
            <label>訂正元の月</label>
            <input type="month" name="target_month" id="adj-target" value="<?= inv_h($edit !== null && $edit['adj_type'] === 'correction' ? $edit['target_month'] : '') ?>">
            <label>請求済み人数</label>
            <div><input type="number" name="billed_quantity" id="adj-billed" value="<?= inv_h($editBilled) ?>" min="0" step="1"> 名 <span class="muted" id="adj-lookup"></span></div>
            <label>正しい人数</label>
            <div><input type="number" name="correct_quantity" id="adj-correct" value="<?= inv_h($editCorrect) ?>" min="0" step="1"> 名</div>
            <label>単価（税込）</label>
            <div><input type="number" name="correction_unit_price" id="adj-cprice" value="<?= inv_h($edit !== null && $edit['adj_type'] === 'correction' ? $edit['unit_price'] : '') ?>" step="1"> 円</div>
            <label>差額</label>
            <div class="calc" id="adj-calc">-</div>
        </div>

        <div class="type-fields" data-type="discount">
            <label>対象月（任意）</label>
            <input type="month" name="discount_target_month" value="<?= inv_h($edit !== null && $edit['adj_type'] === 'discount' ? ($edit['target_month'] ?? '') : '') ?>">
            <label>数量</label>
            <input type="number" name="quantity" id="adj-dqty" value="<?= inv_h($edit !== null && $edit['adj_type'] === 'discount' ? $edit['quantity'] : '') ?>" step="1">
            <label>単価（負の数）</label>
            <input type="number" name="unit_price" id="adj-dprice" value="<?= inv_h($edit !== null && $edit['adj_type'] === 'discount' ? $edit['unit_price'] : '') ?>" step="1" placeholder="-3325">
            <label>金額</label>
            <div class="calc" id="adj-dcalc">-</div>
        </div>

        <label>文言（請求書の商品名欄）</label>
        <input type="text" name="description" id="adj-desc" value="<?= inv_h($edit['description'] ?? '') ?>" maxlength="200" placeholder="訂正は空欄なら自動生成（例：8月分人数訂正（アルク平野長吉 21名→19名））">
        <label>社内メモ（請求書には出ません）</label>
        <textarea name="reason" rows="2"><?= inv_h($edit['reason'] ?? '') ?></textarea>
    </div>
    <p>
        <button type="submit" class="primary">保存</button>
        <?php if ($edit !== null): ?><a href="/admin/invoice_adjustments.php">キャンセル（新規登録に戻る）</a><?php endif; ?>
    </p>
</form>
<script>
    (function () {
        var usage = <?= json_encode((object) $usageMap, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
        var names = <?= json_encode((object) $facilityNames, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
        var form = document.getElementById('adj-form');
        var $ = function (id) { return document.getElementById(id); };
        var autoDesc = $('adj-desc').value === '' || /月分人数訂正（/.test($('adj-desc').value);
        $('adj-desc').addEventListener('input', function () { autoDesc = false; });
        function fmt(n) { return (n < 0 ? '-' : '') + Math.abs(n).toLocaleString('ja-JP') + '円'; }
        function int(v) { return /^-?\d+$/.test(v) ? parseInt(v, 10) : null; }
        function currentType() { return form.querySelector('input[name=adj_type]:checked').value; }
        function showType() {
            var t = currentType();
            document.querySelectorAll('.type-fields').forEach(function (el) {
                el.style.display = el.getAttribute('data-type') === t ? 'contents' : 'none';
            });
            update();
        }
        function lookup() {
            var key = $('adj-target').value + '|' + $('adj-facility').value;
            var hit = usage[key];
            if (hit) {
                $('adj-billed').value = hit.quantity;
                $('adj-cprice').value = hit.unit_price;
                $('adj-lookup').textContent = '（確定済み請求書 No.' + hit.invoice_no + ' から自動表示）';
            } else {
                $('adj-lookup').textContent = $('adj-target').value && $('adj-facility').value ? '（この月の確定請求書がシステムにありません。請求済みの人数・単価を手入力してください）' : '';
            }
            update();
        }
        function update() {
            if (currentType() === 'correction') {
                var billed = int($('adj-billed').value), correct = int($('adj-correct').value), price = int($('adj-cprice').value);
                if (billed !== null && correct !== null && price !== null) {
                    var qty = correct - billed;
                    $('adj-calc').textContent = '数量 ' + qty + ' × 単価 ' + price.toLocaleString('ja-JP') + ' = ' + fmt(qty * price);
                    var month = $('adj-target').value, facility = names[$('adj-facility').value];
                    if (autoDesc && month && facility) {
                        $('adj-desc').value = parseInt(month.slice(5), 10) + '月分人数訂正（' + facility + ' ' + billed + '名→' + correct + '名）';
                    }
                } else {
                    $('adj-calc').textContent = '-';
                }
            } else {
                var q = int($('adj-dqty').value), p = int($('adj-dprice').value);
                $('adj-dcalc').textContent = q !== null && p !== null ? fmt(q * p) : '-';
            }
        }
        form.querySelectorAll('input[name=adj_type]').forEach(function (el) { el.addEventListener('change', showType); });
        $('adj-target').addEventListener('change', lookup);
        $('adj-facility').addEventListener('change', function () { if (currentType() === 'correction') { lookup(); } });
        ['adj-billed', 'adj-correct', 'adj-cprice', 'adj-dqty', 'adj-dprice'].forEach(function (id) { $(id).addEventListener('input', update); });
        showType();
    })();
</script>
<?php endif; ?>

<h2>登録済みの訂正・値引き</h2>
<?php if (!$adjustments): ?>
    <p class="notice">登録はありません。</p>
<?php else: ?>
    <table class="list">
        <thead>
            <tr><th>状態</th><th>反映月</th><th>種別</th><th>請求先</th><th>訂正元の月</th><th>施設</th><th>文言</th><th class="num">数量</th><th class="num">単価</th><th class="num">金額</th><th>社内メモ</th><th></th></tr>
        </thead>
        <tbody>
            <?php foreach ($adjustments as $row): ?>
                <tr>
                    <td><?php if ($row['applied_invoice_id'] === null): ?>
                        <span class="status-draft">未反映</span>
                    <?php else: ?>
                        <span class="status-issued">反映済み</span><br><a href="/admin/invoice.php?id=<?= (int) $row['applied_invoice_id'] ?>">No. <?= inv_h(inv_format_no($row['invoice_no'] !== null ? (int) $row['invoice_no'] : null)) ?></a>
                    <?php endif; ?></td>
                    <td><?= inv_h(inv_month_label($row['apply_month'])) ?></td>
                    <td><?= inv_h($typeLabels[$row['adj_type']] ?? $row['adj_type']) ?></td>
                    <td><?= inv_h($clientNames[(int) $row['client_id']] ?? '') ?></td>
                    <td><?= $row['target_month'] !== null ? inv_h(inv_month_label($row['target_month'])) : '' ?></td>
                    <td><?= inv_h($facilityNames[(int) $row['facility_id']] ?? '') ?></td>
                    <td><?= inv_h($row['description']) ?></td>
                    <td class="num"><?= inv_yen((int) $row['quantity']) ?></td>
                    <td class="num"><?= inv_yen((int) $row['unit_price']) ?></td>
                    <td class="num<?= (int) $row['amount'] < 0 ? ' neg' : '' ?>"><?= inv_yen((int) $row['amount']) ?></td>
                    <td><?= nl2br(inv_h($row['reason'] ?? '')) ?></td>
                    <td>
                        <?php if ($row['applied_invoice_id'] === null): ?>
                            <a href="?edit=<?= (int) $row['id'] ?>">編集</a>
                            <form method="post" action="/admin/invoice_adjustments.php" class="inline" onsubmit="return confirm('この訂正・値引きを削除します。よろしいですか？');">
                                <input type="hidden" name="csrf_token" value="<?= inv_h($csrfToken) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="adjustment_id" value="<?= (int) $row['id'] ?>">
                                <button type="submit">削除</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</body>
</html>
