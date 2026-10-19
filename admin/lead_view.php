<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/lead_common.php';

$admin = require_login('admin');
$pdo = getPdo();
$adminId = (int) $admin['id'];

$leadId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$lead = lead_fetch($pdo, $leadId);
if ($lead === null) {
    set_flash('error', '営業先が見つかりません。');
    header('Location: /admin/leads.php');
    exit;
}

function lead_view_redirect(int $leadId): void
{
    header('Location: /admin/lead_view.php?id=' . $leadId);
    exit;
}

/** 手入力メモ（kind='memo'）だけを返す。自動記録は修正・削除させない */
function lead_view_fetch_memo(PDO $pdo, int $leadId, int $noteId): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM lead_notes WHERE id = :id AND lead_id = :lead_id AND kind = 'memo'");
    $stmt->execute([':id' => $noteId, ':lead_id' => $leadId]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('error', '不正なリクエストです。画面を再読み込みしてやり直してください。');
        lead_view_redirect($leadId);
    }

    if ($action === 'status') {
        $newStatus = (string) ($_POST['status'] ?? '');
        if (!in_array($newStatus, LEAD_STATUSES, true)) {
            set_flash('error', 'ステータスを選択してください。');
        } elseif ($newStatus === $lead['status']) {
            set_flash('error', 'ステータスは変わっていません。');
        } else {
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE leads SET status = :status WHERE id = :id')->execute([':status' => $newStatus, ':id' => $leadId]);
            lead_add_note($pdo, $leadId, 'status', $lead['status'] . ' → ' . $newStatus, $adminId);
            $pdo->commit();
            set_flash('success', 'ステータスを「' . $newStatus . '」に変更しました。');
        }
    } elseif ($action === 'add_memo') {
        $content = trim((string) ($_POST['content'] ?? ''));
        if ($content === '' || mb_strlen($content) > 5000) {
            set_flash('error', 'メモを5000文字以内で入力してください。');
        } else {
            lead_add_note($pdo, $leadId, 'memo', $content, $adminId);
            set_flash('success', 'メモを追加しました。');
        }
    } elseif ($action === 'edit_memo') {
        $note = lead_view_fetch_memo($pdo, $leadId, (int) ($_POST['note_id'] ?? 0));
        $content = trim((string) ($_POST['content'] ?? ''));
        if ($note === null) {
            set_flash('error', '修正できるメモが見つかりません（自動記録は修正できません）。');
        } elseif ($content === '' || mb_strlen($content) > 5000) {
            set_flash('error', 'メモを5000文字以内で入力してください。');
        } elseif ($content !== $note['content']) {
            $pdo->prepare('UPDATE lead_notes SET content = :content, updated_by = :by, updated_at = NOW() WHERE id = :id')
                ->execute([':content' => $content, ':by' => $adminId, ':id' => $note['id']]);
            lead_touch($pdo, $leadId);
            set_flash('success', 'メモを修正しました。');
        }
    } elseif ($action === 'delete_memo') {
        $note = lead_view_fetch_memo($pdo, $leadId, (int) ($_POST['note_id'] ?? 0));
        if ($note === null) {
            set_flash('error', '削除できるメモが見つかりません（自動記録は削除できません）。');
        } else {
            $pdo->prepare('DELETE FROM lead_notes WHERE id = :id')->execute([':id' => $note['id']]);
            lead_touch($pdo, $leadId);
            set_flash('success', 'メモを削除しました。');
        }
    } elseif ($action === 'update_info') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $address = trim((string) ($_POST['address'] ?? ''));
        $contact = trim((string) ($_POST['contact_person'] ?? ''));
        $facilityId = (int) ($_POST['facility_id'] ?? 0);
        $facilityOk = true;
        if ($facilityId > 0) {
            $stmt = $pdo->prepare("SELECT id FROM facilities WHERE id = :id AND facility_type = '介護施設'");
            $stmt->execute([':id' => $facilityId]);
            $facilityOk = $stmt->fetch() !== false;
        }
        if ($name === '' || mb_strlen($name) > 100 || mb_strlen($address) > 255 || mb_strlen($contact) > 100 || !$facilityOk) {
            set_flash('error', '施設名（100文字以内）・住所（255文字以内）・担当者名（100文字以内）・施設の紐付けを正しく入力してください。');
        } else {
            $pdo->prepare(
                'UPDATE leads SET name = :name, address = :address, contact_person = :contact,
                        name_key = :name_key, address_key = :address_key, facility_id = :facility_id
                 WHERE id = :id'
            )->execute([
                ':name' => $name,
                ':address' => $address === '' ? null : $address,
                ':contact' => $contact === '' ? null : $contact,
                ':name_key' => lead_normalize_key($name),
                ':address_key' => lead_normalize_key($address),
                ':facility_id' => $facilityId > 0 ? $facilityId : null,
                ':id' => $leadId,
            ]);
            set_flash('success', '基本情報を更新しました。');
        }
    } elseif ($action === 'delete_lead') {
        // 見積書は残し、quotations.lead_id は外部キー（ON DELETE SET NULL）で外れる。時系列は CASCADE で消える
        $pdo->prepare('DELETE FROM leads WHERE id = :id')->execute([':id' => $leadId]);
        set_flash('success', '営業先「' . $lead['name'] . '」を削除しました（紐づいていた見積書は残っています）。');
        header('Location: /admin/leads.php');
        exit;
    }
    lead_view_redirect($leadId);
}

$notesStmt = $pdo->prepare(
    'SELECT n.*, c.name AS created_by_name, u.name AS updated_by_name
     FROM lead_notes n
     LEFT JOIN employees c ON c.id = n.created_by
     LEFT JOIN employees u ON u.id = n.updated_by
     WHERE n.lead_id = :id
     ORDER BY n.created_at DESC, n.id DESC'
);
$notesStmt->execute([':id' => $leadId]);
$notes = $notesStmt->fetchAll();

$quotesStmt = $pdo->prepare(
    'SELECT id, quote_no, resident_count, has_odor_mini_option, total_incl, issue_date, valid_until
     FROM quotations WHERE lead_id = :id ORDER BY issue_date DESC, quote_no DESC'
);
$quotesStmt->execute([':id' => $leadId]);
$quotes = $quotesStmt->fetchAll();

$facilities = $pdo->query("SELECT id, name FROM facilities WHERE facility_type = '介護施設' ORDER BY name")->fetchAll();
$editNoteId = (int) ($_GET['edit_note'] ?? 0);
$flash = pop_flash();
$csrfToken = csrf_token();
$today = (new DateTime('today'))->format('Y-m-d');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= lead_esc($lead['name']) ?> | 営業先 | 管理者</title>
    <style>
        body { font-family: sans-serif; margin: 16px; color: #222; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 8px; }
        h1 { font-size: 1.3em; margin: 0; }
        h2 { font-size: 1.05em; margin: 0 0 10px; }
        .message { padding: 8px 12px; border-radius: 4px; margin-bottom: 12px; }
        .message.success { background: #e6f4ea; color: #1e7e34; }
        .message.error { background: #fdecea; color: #b3261e; }
        section { border: 1px solid #ccc; border-radius: 6px; padding: 14px; margin-bottom: 16px; max-width: 900px; }
        .status-badge { display: inline-block; font-size: 0.9em; padding: 2px 10px; border-radius: 10px; vertical-align: middle; }
        .status-提案済み { background: #e8eef7; color: #2c4a75; }
        .status-検討中 { background: #fff3cd; color: #856404; }
        .status-成約 { background: #e6f4ea; color: #1e7e34; }
        .status-失注 { background: #eee; color: #777; }
        .form-row { margin-bottom: 8px; display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
        .form-row > label { width: 120px; }
        .form-row input[type="text"] { width: 340px; max-width: 100%; }
        textarea { width: 100%; max-width: 640px; height: 80px; }
        table.list { border-collapse: collapse; width: 100%; }
        table.list th, table.list td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; white-space: nowrap; }
        table.list th { background: #f5f5f5; }
        table.list td.num { text-align: right; }
        .timeline { list-style: none; margin: 0; padding: 0; }
        .timeline li { border-left: 3px solid #ccc; padding: 6px 0 8px 12px; margin-bottom: 6px; }
        .timeline li.kind-memo { border-left-color: #0b5ed7; }
        .timeline li.kind-status { border-left-color: #d89b00; }
        .timeline li.kind-quotation { border-left-color: #1e8e4e; }
        .timeline .head { font-size: 0.85em; color: #666; }
        .timeline .kind { display: inline-block; font-size: 0.8em; padding: 0 7px; border-radius: 8px; background: #eee; margin-right: 6px; }
        .timeline .body { margin-top: 3px; white-space: pre-wrap; }
        .inline-form { display: inline; }
        button.danger { border: 1px solid #b3261e; color: #b3261e; background: #fff; border-radius: 4px; cursor: pointer; }
        a.button { display: inline-block; padding: 6px 12px; border-radius: 4px; background: #0b5ed7; color: #fff; text-decoration: none; }
        .muted { color: #666; font-size: 0.9em; }
    </style>
</head>
<body>
<header>
    <h1><?= lead_esc($lead['name']) ?> <span class="status-badge status-<?= lead_esc($lead['status']) ?>"><?= lead_esc($lead['status']) ?></span></h1>
    <nav>ログイン中: <?= lead_esc($admin['name']) ?>さん（管理者） | <a href="/admin/leads.php">営業先一覧</a> | <a href="/admin/dashboard.php">ダッシュボード</a> | <a href="/admin/logout.php">ログアウト</a></nav>
</header>

<?php if ($flash !== null): ?>
    <p class="message <?= lead_esc($flash['type']) ?>"><?= lead_esc($flash['message']) ?></p>
<?php endif; ?>

<section>
    <h2>ステータス</h2>
    <form method="post" action="/admin/lead_view.php">
        <input type="hidden" name="csrf_token" value="<?= lead_esc($csrfToken) ?>">
        <input type="hidden" name="id" value="<?= $leadId ?>">
        <input type="hidden" name="action" value="status">
        <select name="status">
            <?php foreach (LEAD_STATUSES as $s): ?>
                <option value="<?= lead_esc($s) ?>"<?= $lead['status'] === $s ? ' selected' : '' ?>><?= lead_esc($s) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit">変更する</button>
        <span class="muted">見積書を追加で発行してもステータスは自動では変わりません。</span>
    </form>
</section>

<section>
    <h2>メモを追加</h2>
    <form method="post" action="/admin/lead_view.php">
        <input type="hidden" name="csrf_token" value="<?= lead_esc($csrfToken) ?>">
        <input type="hidden" name="id" value="<?= $leadId ?>">
        <input type="hidden" name="action" value="add_memo">
        <textarea name="content" maxlength="5000" required placeholder="商談の内容・所感など（日時と記入者は自動で記録されます）"></textarea>
        <div><button type="submit">追加する</button></div>
    </form>
</section>

<section>
    <h2>時系列（新しい順）</h2>
    <?php if ($notes === []): ?>
        <p class="muted">まだ記録はありません。</p>
    <?php else: ?>
        <ul class="timeline">
        <?php foreach ($notes as $n): ?>
            <li class="kind-<?= lead_esc($n['kind']) ?>">
                <div class="head">
                    <span class="kind"><?= lead_esc(LEAD_NOTE_KIND_LABELS[$n['kind']] ?? $n['kind']) ?></span>
                    <?= lead_esc(substr((string) $n['created_at'], 0, 16)) ?>　<?= lead_esc($n['created_by_name'] ?? '') ?>
                    <?php if ($n['updated_at'] !== null): ?>
                        （<?= lead_esc(substr((string) $n['updated_at'], 0, 16)) ?> <?= lead_esc($n['updated_by_name'] ?? '') ?> が修正）
                    <?php endif; ?>
                    <?php if ($n['kind'] === 'memo' && (int) $n['id'] !== $editNoteId): ?>
                        | <a href="/admin/lead_view.php?id=<?= $leadId ?>&amp;edit_note=<?= (int) $n['id'] ?>#note-<?= (int) $n['id'] ?>">修正</a>
                        | <form class="inline-form" method="post" action="/admin/lead_view.php" onsubmit="return confirm('このメモを削除します。よろしいですか？');">
                            <input type="hidden" name="csrf_token" value="<?= lead_esc($csrfToken) ?>">
                            <input type="hidden" name="id" value="<?= $leadId ?>">
                            <input type="hidden" name="action" value="delete_memo">
                            <input type="hidden" name="note_id" value="<?= (int) $n['id'] ?>">
                            <button type="submit" class="danger">削除</button>
                        </form>
                    <?php endif; ?>
                </div>
                <?php if ($n['kind'] === 'memo' && (int) $n['id'] === $editNoteId): ?>
                    <form id="note-<?= (int) $n['id'] ?>" method="post" action="/admin/lead_view.php">
                        <input type="hidden" name="csrf_token" value="<?= lead_esc($csrfToken) ?>">
                        <input type="hidden" name="id" value="<?= $leadId ?>">
                        <input type="hidden" name="action" value="edit_memo">
                        <input type="hidden" name="note_id" value="<?= (int) $n['id'] ?>">
                        <textarea name="content" maxlength="5000" required><?= lead_esc($n['content']) ?></textarea>
                        <div><button type="submit">保存する</button> <a href="/admin/lead_view.php?id=<?= $leadId ?>">キャンセル</a></div>
                    </form>
                <?php else: ?>
                    <div class="body"><?= lead_esc($n['content']) ?></div>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<section>
    <h2>見積書（<?= count($quotes) ?>件）</h2>
    <p><a class="button" href="/admin/quotation_edit.php?lead_id=<?= $leadId ?>">＋ この営業先に見積書を作成</a></p>
    <?php if ($quotes !== []): ?>
        <table class="list">
            <thead><tr><th>見積番号</th><th>入居者数</th><th>金額（税込・月額）</th><th>発行日</th><th>有効期限</th></tr></thead>
            <tbody>
            <?php foreach ($quotes as $q): ?>
                <tr>
                    <td><a href="/admin/quotation_view.php?id=<?= (int) $q['id'] ?>"><?= lead_esc($q['quote_no']) ?></a></td>
                    <td class="num"><?= number_format((int) $q['resident_count']) ?> 名</td>
                    <td class="num">¥<?= number_format((int) $q['total_incl']) ?><?= (int) $q['has_odor_mini_option'] === 1 ? '（汚物mini込）' : '' ?></td>
                    <td><?= lead_esc($q['issue_date']) ?></td>
                    <td><?= lead_esc($q['valid_until']) ?><?= $q['valid_until'] < $today ? '（期限切れ）' : '' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<section>
    <h2>基本情報</h2>
    <form method="post" action="/admin/lead_view.php">
        <input type="hidden" name="csrf_token" value="<?= lead_esc($csrfToken) ?>">
        <input type="hidden" name="id" value="<?= $leadId ?>">
        <input type="hidden" name="action" value="update_info">
        <div class="form-row"><label for="name">施設名・会社名</label><input type="text" id="name" name="name" maxlength="100" required value="<?= lead_esc($lead['name']) ?>"></div>
        <div class="form-row"><label for="address">住所</label><input type="text" id="address" name="address" maxlength="255" value="<?= lead_esc($lead['address'] ?? '') ?>"></div>
        <div class="form-row"><label for="contact_person">担当者名</label><input type="text" id="contact_person" name="contact_person" maxlength="100" value="<?= lead_esc($lead['contact_person'] ?? '') ?>"></div>
        <div class="form-row">
            <label for="facility_id">施設との紐付け</label>
            <select id="facility_id" name="facility_id">
                <option value="">（なし）</option>
                <?php foreach ($facilities as $f): ?>
                    <option value="<?= (int) $f['id'] ?>"<?= (string) $lead['facility_id'] === (string) $f['id'] ? ' selected' : '' ?>><?= lead_esc($f['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <span class="muted">成約後に施設管理へ登録した場合など</span>
        </div>
        <button type="submit">保存する</button>
        <p class="muted">登録：<?= lead_esc(substr((string) $lead['created_at'], 0, 16)) ?>　最終更新：<?= lead_esc(substr((string) $lead['updated_at'], 0, 16)) ?></p>
    </form>
    <form method="post" action="/admin/lead_view.php" onsubmit="return confirm('営業先「<?= lead_esc($lead['name']) ?>」とメモ・時系列を削除します。紐づく見積書は残ります。よろしいですか？');">
        <input type="hidden" name="csrf_token" value="<?= lead_esc($csrfToken) ?>">
        <input type="hidden" name="id" value="<?= $leadId ?>">
        <input type="hidden" name="action" value="delete_lead">
        <button type="submit" class="danger">この営業先を削除</button>
    </form>
</section>
</body>
</html>
