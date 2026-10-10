<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/accounting_ledger.php';

$admin = require_login('admin');
$pdo = getPdo();

/**
 * 勘定科目マスタ。科目ごとの区分（資産・負債・純資産・収益・費用）、決算書の表示区分、勘定科目内訳書の種類、既定の税区分を決める。
 * 初回は標準の科目を取り込む。実際の科目名に合わせて追加・変更する。
 */

[$book, $books] = acc_select_book($pdo);
$bookId = (int) $book['id'];
$self = '/admin/accounting_chart.php?book=' . $bookId;
acc_chart_ensure($pdo, $book);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('error', '不正なリクエストです。画面を再読み込みしてやり直してください。');
        header('Location: ' . $self);
        exit;
    }
    $action = (string) ($_POST['action'] ?? '');
    $errors = [];

    if ($action === 'save_all') {
        $stmt = $pdo->prepare('UPDATE acc_chart SET section = :s, uchiwake = :u, is_contra = :c, default_tax = :t, is_active = :a, sort_no = :o WHERE id = :id AND book_id = :b');
        foreach ((array) ($_POST['row'] ?? []) as $id => $r) {
            $category = (string) ($r['category'] ?? '');
            $section = (string) ($r['section'] ?? '');
            $tax = (string) ($r['tax'] ?? ACC_TAX_NONE);
            $uchiwake = (string) ($r['uchiwake'] ?? '');
            if (!in_array($section, ACC_SECTIONS[$category] ?? [], true)) {
                $errors[] = '表示区分「' . $section . '」は区分（' . (ACC_CATEGORY_LABELS[$category] ?? $category) . '）にありません。';
                continue;
            }
            if (!in_array($tax, ACC_TAX_CLASSES, true)) {
                $tax = ACC_TAX_NONE;
            }
            $stmt->execute([
                ':s' => $section, ':u' => in_array($uchiwake, ACC_UCHIWAKE_KINDS, true) ? $uchiwake : null, ':c' => !empty($r['contra']) ? 1 : 0,
                ':t' => $tax, ':a' => !empty($r['active']) ? 1 : 0, ':o' => (int) ($r['sort'] ?? 0), ':id' => (int) $id, ':b' => $bookId,
            ]);
        }
        set_flash($errors ? 'error' : 'success', $errors ? implode("\n", array_slice($errors, 0, 8)) : '勘定科目を保存しました。');
        header('Location: ' . $self);
        exit;
    }

    if ($action === 'add') {
        $name = acc_clean_text((string) ($_POST['name'] ?? ''));
        $head = (string) ($_POST['head'] ?? '');
        [$category, $section] = array_pad(explode('/', $head, 2), 2, '');
        $uchiwake = (string) ($_POST['uchiwake'] ?? '');
        $tax = (string) ($_POST['tax'] ?? ACC_TAX_NONE);
        if ($name === '' || mb_strlen($name) > 60) {
            $errors[] = '科目名を60文字以内で入力してください。';
        }
        if (!in_array($section, ACC_SECTIONS[$category] ?? [], true)) {
            $errors[] = '区分を選んでください。';
        }
        if ($errors) {
            set_flash('error', implode("\n", $errors));
        } else {
            $max = (int) $pdo->query('SELECT COALESCE(MAX(sort_no), 0) FROM acc_chart WHERE book_id = ' . $bookId)->fetchColumn();
            try {
                $pdo->prepare('INSERT INTO acc_chart (book_id, name, category, section, uchiwake, is_contra, default_tax, sort_no) VALUES (:b, :n, :c, :s, :u, :k, :t, :o)')
                    ->execute([':b' => $bookId, ':n' => $name, ':c' => $category, ':s' => $section, ':u' => in_array($uchiwake, ACC_UCHIWAKE_KINDS, true) ? $uchiwake : null, ':k' => !empty($_POST['contra']) ? 1 : 0, ':t' => in_array($tax, ACC_TAX_CLASSES, true) ? $tax : ACC_TAX_NONE, ':o' => $max + 10]);
                set_flash('success', '「' . $name . '」を追加しました。');
            } catch (PDOException $e) {
                set_flash('error', 'その科目名は登録済みです。');
            }
        }
        header('Location: ' . $self);
        exit;
    }
    header('Location: ' . $self);
    exit;
}

$flash = pop_flash();
$chart = array_values(acc_chart_map($pdo, $book));
usort($chart, static fn (array $a, array $b): int => [acc_section_rank($a['category'], $a['section']), (int) $a['sort_no']] <=> [acc_section_rank($b['category'], $b['section']), (int) $b['sort_no']]);

acc_render_header($admin, '勘定科目マスタ', 'chart', $bookId);
acc_render_messages($flash);
?>
<div class="yb-toolbar" style="margin-top:8px"><?php acc_render_book_selector($books, $book, '/admin/accounting_chart.php'); ?></div>
<div class="yb-panel">
    <p class="muted">仕訳に使う勘定科目の一覧です。<strong>表示区分</strong>は決算書（貸借対照表・損益計算書）のどこに出すか、<strong>内訳書</strong>は勘定科目内訳書の種類（預貯金・売掛金・借入金など）、
        <strong>評価勘定</strong>は減価償却累計額・貸倒引当金のように資産から差し引く科目です。科目名を変えたい場合は、新しい科目を追加して古い科目を「使用」から外してください（過去の仕訳の科目名は変わりません）。</p>
    <datalist id="taxes"><?php foreach (ACC_TAX_CLASSES as $t) : ?><option value="<?= acc_h($t) ?>"><?php endforeach; ?></datalist>
    <form method="post" action="<?= acc_h($self) ?>">
        <input type="hidden" name="csrf_token" value="<?= acc_h(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_all">
        <div class="scroll"><table class="list">
            <thead><tr><th>区分</th><th>表示区分</th><th>勘定科目</th><th>内訳書</th><th>評価勘定</th><th>既定の税区分</th><th>順</th><th>使用</th></tr></thead>
            <tbody>
            <?php foreach ($chart as $c) : $id = (int) $c['id']; ?>
                <tr>
                    <td><?= acc_h(ACC_CATEGORY_LABELS[$c['category']]) ?><input type="hidden" name="row[<?= $id ?>][category]" value="<?= acc_h($c['category']) ?>"></td>
                    <td><select name="row[<?= $id ?>][section]"><?php foreach (ACC_SECTIONS[$c['category']] as $s) : ?><option<?= $s === $c['section'] ? ' selected' : '' ?>><?= acc_h($s) ?></option><?php endforeach; ?></select></td>
                    <td><?= acc_h($c['name']) ?></td>
                    <td><select name="row[<?= $id ?>][uchiwake]"><option value="">―</option><?php foreach (ACC_UCHIWAKE_KINDS as $u) : ?><option<?= $u === $c['uchiwake'] ? ' selected' : '' ?>><?= acc_h($u) ?></option><?php endforeach; ?></select></td>
                    <td><input type="checkbox" name="row[<?= $id ?>][contra]" value="1"<?= (int) $c['is_contra'] === 1 ? ' checked' : '' ?>></td>
                    <td><input type="text" name="row[<?= $id ?>][tax]" list="taxes" value="<?= acc_h($c['default_tax']) ?>" size="16"></td>
                    <td><input type="number" name="row[<?= $id ?>][sort]" value="<?= (int) $c['sort_no'] ?>" style="width:70px"></td>
                    <td><input type="checkbox" name="row[<?= $id ?>][active]" value="1"<?= (int) $c['is_active'] === 1 ? ' checked' : '' ?>></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <button type="submit" class="primary">まとめて保存</button>
    </form>

    <form method="post" action="<?= acc_h($self) ?>">
        <input type="hidden" name="csrf_token" value="<?= acc_h(csrf_token()) ?>">
        <input type="hidden" name="action" value="add">
        <fieldset>
            <legend>勘定科目を追加</legend>
            <label>科目名: <input type="text" name="name" size="20" maxlength="60" required></label>
            <label>区分・表示区分: <select name="head">
                <?php foreach (ACC_SECTIONS as $cat => $secs) : foreach ($secs as $s) : ?>
                    <option value="<?= acc_h($cat . '/' . $s) ?>"><?= acc_h(ACC_CATEGORY_LABELS[$cat] . '／' . $s) ?></option>
                <?php endforeach; endforeach; ?>
            </select></label>
            <label>内訳書: <select name="uchiwake"><option value="">―</option><?php foreach (ACC_UCHIWAKE_KINDS as $u) : ?><option><?= acc_h($u) ?></option><?php endforeach; ?></select></label>
            <label>既定の税区分: <input type="text" name="tax" list="taxes" size="14" placeholder="対象外"></label>
            <label><input type="checkbox" name="contra" value="1"> 評価勘定</label>
            <button type="submit" class="primary">追加</button>
        </fieldset>
    </form>
</div>
<?php acc_render_footer(); ?>
