<?php
// ============================================================================
// 休憩時間の修正（日々の打刻の total_break_minutes を手で直す画面）
//
// 置き場所: /admin/break_edit.php
// URL:      https://shift.carewash.net/admin/break_edit.php?month=2026-08
//
// 月末チェック（month_end_check.php）は「足りない休憩を足す」だけで、
// 多すぎる休憩を減らしません。打刻を長時間に取り違えた状態で月末チェックを
// 実行すると、あとから打刻を直しても休憩だけが残ってしまいます。
// この画面はその休憩を手で直すためのものです。
//
// 更新内容は月末チェックと同じく attendance_edit_logs に記録します。
// ============================================================================

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$admin = require_login('admin');
$pdo = getPdo();

// ─── テーブルの列名を実データから判定する（環境差で落ちないように） ───────────
$attendanceColumns = $pdo->query('SHOW COLUMNS FROM attendance')->fetchAll(PDO::FETCH_COLUMN);

function pick_column(array $columns, array $candidates): ?string
{
    foreach ($candidates as $c) {
        if (in_array($c, $columns, true)) { return $c; }
    }
    return null;
}

$colEmployee = pick_column($attendanceColumns, ['employee_id', 'staff_id', 'user_id']);
$colDate     = pick_column($attendanceColumns, ['work_date', 'shift_date', 'date']);
$colCategory = pick_column($attendanceColumns, ['category', 'punch_category', 'work_category', 'shift_category']);

$setupError = '';
foreach (['clock_in_at', 'clock_out_at', 'total_break_minutes', 'work_minutes'] as $required) {
    if (!in_array($required, $attendanceColumns, true)) {
        $setupError = 'attendance テーブルに ' . $required . ' 列が見つかりません。';
    }
}
if ($colEmployee === null) {
    $setupError = 'attendance テーブルに従業員を示す列（employee_id 等）が見つかりません。';
}

// 従業員名を引くテーブルを探す
$employeeTable = null;
$employeeNameColumn = null;
if ($setupError === '') {
    foreach (['employees', 'staff', 'users'] as $t) {
        $found = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->fetch();
        if ($found) {
            $ecols = $pdo->query('SHOW COLUMNS FROM `' . $t . '`')->fetchAll(PDO::FETCH_COLUMN);
            $nameCol = pick_column($ecols, ['name', 'employee_name', 'full_name', 'display_name']);
            if ($nameCol !== null && in_array('id', $ecols, true)) {
                $employeeTable = $t;
                $employeeNameColumn = $nameCol;
                break;
            }
        }
    }
}

// ─── 対象年月 ───────────────────────────────────────────────────────────────
$yearMonth = (string) ($_GET['month'] ?? (new DateTime())->format('Y-m'));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $yearMonth)) {
    $yearMonth = (new DateTime())->format('Y-m');
}
$onlyWithBreak = isset($_GET['only_break']) && $_GET['only_break'] === '1';

$errorMessage = '';

// ─── 更新処理 ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $setupError === '') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errorMessage = '不正なリクエストです。再度お試しください。';
    } elseif ((string) ($_POST['action'] ?? '') === 'save') {
        $postYearMonth = (string) ($_POST['month'] ?? '');
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $postYearMonth)) {
            $errorMessage = '対象年月の形式が正しくありません。';
        } else {
            $yearMonth = $postYearMonth;
            $submitted = $_POST['break_minutes'] ?? [];

            if (!is_array($submitted) || $submitted === []) {
                $errorMessage = '更新対象がありませんでした。';
            } else {
                try {
                    $pdo->beginTransaction();

                    // 現在値を取得（送られてきたidのみ・整数化して安全に）
                    $ids = [];
                    foreach (array_keys($submitted) as $rawId) {
                        $id = (int) $rawId;
                        if ($id > 0) { $ids[$id] = true; }
                    }
                    $ids = array_keys($ids);

                    $current = [];
                    if ($ids !== []) {
                        $in = implode(',', array_fill(0, count($ids), '?'));
                        $curStmt = $pdo->prepare(
                            'SELECT id, total_break_minutes, clock_in_at, clock_out_at FROM attendance WHERE id IN (' . $in . ')'
                        );
                        $curStmt->execute($ids);
                        foreach ($curStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                            $current[(int) $row['id']] = $row;
                        }
                    }

                    $logStmt = $pdo->prepare(
                        'INSERT INTO attendance_edit_logs (attendance_id, edited_by, action, field_name, old_value, new_value)
                         VALUES (:attendance_id, :edited_by, :action, :field_name, :old_value, :new_value)'
                    );
                    // 退勤済み: 実働を計算し直す
                    $updateDone = $pdo->prepare(
                        'UPDATE attendance
                            SET total_break_minutes = :total_break_minutes,
                                work_minutes = GREATEST(0, TIMESTAMPDIFF(MINUTE, clock_in_at, clock_out_at) - :break_for_work)
                          WHERE id = :id'
                    );
                    // 勤務中（退勤なし）: 実働は NULL のまま
                    $updateOpen = $pdo->prepare(
                        'UPDATE attendance SET total_break_minutes = :total_break_minutes, work_minutes = NULL WHERE id = :id'
                    );

                    $changed = 0;
                    foreach ($ids as $id) {
                        if (!isset($current[$id])) { continue; }

                        $newValue = (int) $submitted[(string) $id];
                        if ($newValue < 0)    { $newValue = 0; }
                        if ($newValue > 1440) { $newValue = 1440; }

                        $oldValue = (int) $current[$id]['total_break_minutes'];
                        if ($newValue === $oldValue) { continue; }

                        $logStmt->execute([
                            ':attendance_id' => $id,
                            ':edited_by'     => $admin['id'],
                            ':action'        => 'manual_break_correction',
                            ':field_name'    => 'total_break_minutes',
                            ':old_value'     => (string) $oldValue,
                            ':new_value'     => (string) $newValue,
                        ]);

                        if ($current[$id]['clock_out_at'] !== null && $current[$id]['clock_out_at'] !== '') {
                            $updateDone->execute([
                                ':total_break_minutes' => $newValue,
                                ':break_for_work'      => $newValue,
                                ':id'                  => $id,
                            ]);
                        } else {
                            $updateOpen->execute([
                                ':total_break_minutes' => $newValue,
                                ':id'                  => $id,
                            ]);
                        }
                        $changed++;
                    }

                    $pdo->commit();
                    set_flash('success', $changed > 0
                        ? '休憩時間を' . $changed . '件更新しました。'
                        : '変更された休憩時間はありませんでした。');
                    header('Location: /admin/break_edit.php?month=' . urlencode($yearMonth)
                        . ($onlyWithBreak ? '&only_break=1' : ''));
                    exit;
                } catch (\Throwable $e) {
                    if ($pdo->inTransaction()) { $pdo->rollBack(); }
                    $errorMessage = '更新に失敗しました。もう一度お試しください。';
                }
            }
        }
    }
}

$flash = pop_flash();

// ─── 一覧の取得 ─────────────────────────────────────────────────────────────
$rows = [];
if ($setupError === '') {
    $monthStart = $yearMonth . '-01';
    $monthEnd   = (new DateTime($monthStart))->modify('+1 month')->format('Y-m-d');

    $dateExpr = $colDate !== null ? ('a.`' . $colDate . '`') : 'DATE(a.clock_in_at)';
    $nameExpr = $employeeTable !== null ? ('e.`' . $employeeNameColumn . '`') : ("CONCAT('ID:', a.`" . $colEmployee . "`)");
    $catExpr  = $colCategory !== null ? ('a.`' . $colCategory . '`') : "''";

    $sql = 'SELECT a.id,
                   ' . $nameExpr . ' AS employee_name,
                   ' . $dateExpr . ' AS work_date,
                   ' . $catExpr  . ' AS category,
                   a.clock_in_at,
                   a.clock_out_at,
                   a.total_break_minutes,
                   a.work_minutes,
                   TIMESTAMPDIFF(MINUTE, a.clock_in_at, a.clock_out_at) AS span_minutes
              FROM attendance a';
    if ($employeeTable !== null) {
        $sql .= ' LEFT JOIN `' . $employeeTable . '` e ON e.id = a.`' . $colEmployee . '`';
    }
    $sql .= ' WHERE ' . $dateExpr . ' >= :start AND ' . $dateExpr . ' < :end';
    if ($onlyWithBreak) {
        $sql .= ' AND a.total_break_minutes > 0';
    }
    $sql .= ' ORDER BY ' . $dateExpr . ' ASC, employee_name ASC, a.clock_in_at ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':start' => $monthStart, ':end' => $monthEnd]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$prevMonth = (DateTime::createFromFormat('Y-m-d', $yearMonth . '-01'))->modify('-1 month')->format('Y-m');
$nextMonth = (DateTime::createFromFormat('Y-m-d', $yearMonth . '-01'))->modify('+1 month')->format('Y-m');

$confirmedCount = 0;
try {
    $s = $pdo->prepare('SELECT COUNT(*) FROM monthly_wages WHERE `year_month` = :ym');
    $s->execute([':ym' => $yearMonth]);
    $confirmedCount = (int) $s->fetchColumn();
} catch (\Throwable $e) {
    $confirmedCount = 0;
}

$csrfToken = csrf_token();

function h(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function minutes_label(?int $m): string
{
    if ($m === null) { return '—'; }
    return intdiv($m, 60) . '時間' . str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT) . '分';
}

$weekdayJa = ['日', '月', '火', '水', '木', '金', '土'];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>休憩時間の修正 | 管理者</title>
    <style>
        body { font-family: sans-serif; margin: 16px; color: #222; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 8px; }
        h1 { font-size: 1.3em; margin: 0; }
        h2 { font-size: 1.05em; margin: 20px 0 8px; }
        .message { padding: 8px 12px; border-radius: 4px; margin-bottom: 12px; }
        .message.success { background: #e6f4ea; color: #1e7e34; }
        .message.error { background: #fdecea; color: #b3261e; }
        .notice { padding: 8px 12px; background: #fff3cd; color: #856404; border-radius: 4px; margin-bottom: 12px; line-height: 1.7; }
        .month-nav { margin-bottom: 12px; }
        .month-nav a { margin-right: 12px; }
        .month-nav form { display: inline-block; margin-left: 8px; }
        table.rows { border-collapse: collapse; width: 100%; margin-bottom: 16px; }
        table.rows th, table.rows td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; font-size: 0.93em; }
        table.rows th { background: #f5f5f5; position: sticky; top: 0; }
        table.rows tr.has-break { background: #fffaf0; }
        table.rows tr.dirty { background: #e6f4ea; }
        td.num { text-align: right; white-space: nowrap; }
        input.break { width: 72px; text-align: right; padding: 4px 6px; }
        .zero-btn { margin-left: 6px; padding: 2px 8px; font-size: 0.85em; cursor: pointer; }
        .badge { display: inline-block; padding: 1px 7px; border-radius: 10px; font-size: 0.8em; color: #fff; background: #6b7280; }
        .badge.store { background: #2563eb; }
        .badge.pickup { background: #d97706; }
        .badge.laundry { background: #059669; }
        .save-bar { position: sticky; bottom: 0; background: #fff; border-top: 1px solid #ddd; padding: 12px 0; }
        .save-bar button { font-size: 1.05em; padding: 10px 24px; border-radius: 6px; border: none; color: #fff; background: #1e7e34; cursor: pointer; }
        .filter { margin-bottom: 12px; font-size: 0.93em; }
    </style>
</head>
<body>
<header>
    <h1>休憩時間の修正</h1>
    <nav>ログイン中: <?= h($admin['name']) ?>さん（管理者）
        | <a href="/admin/attendance_monthly.php?month=<?= h($yearMonth) ?>">月間打刻実績</a>
        | <a href="/admin/month_end_check.php?month=<?= h($yearMonth) ?>">月末チェック</a>
        | <a href="/admin/dashboard.php">ダッシュボード</a>
        | <a href="/admin/logout.php">ログアウト</a></nav>
</header>

<?php if ($flash !== null): ?>
    <p class="message <?= h($flash['type']) ?>"><?= h($flash['message']) ?></p>
<?php endif; ?>

<?php if ($errorMessage !== ''): ?>
    <p class="message error"><?= h($errorMessage) ?></p>
<?php endif; ?>

<?php if ($setupError !== ''): ?>
    <p class="message error"><?= h($setupError) ?></p>
    </body></html>
    <?php exit; ?>
<?php endif; ?>

<p class="notice">
    日々の打刻に記録されている休憩時間（分）を直接書き換えます。<br>
    月末チェックは「法定時間に足りない休憩を足す」動きしかしないため、打刻を長い時間に取り違えた状態で実行すると、あとから出退勤を直しても休憩だけが残ります。その休憩をここで直してください。<br>
    保存すると実働時間（work_minutes）も計算し直され、変更内容は打刻修正履歴（attendance_edit_logs）に記録されます。
</p>

<?php if ($confirmedCount > 0): ?>
    <p class="notice">この月は<?= $confirmedCount ?>名分の賃金が確定済みです。休憩を変更した場合、対象者は admin/wages.php で再確定が必要になることがあります。</p>
<?php endif; ?>

<div class="month-nav">
    <a href="?month=<?= h($prevMonth) ?><?= $onlyWithBreak ? '&only_break=1' : '' ?>">← 前月</a>
    <strong><?= h($yearMonth) ?></strong>
    <a href="?month=<?= h($nextMonth) ?><?= $onlyWithBreak ? '&only_break=1' : '' ?>">次月 →</a>
    <form method="get" action="/admin/break_edit.php">
        <input type="month" name="month" value="<?= h($yearMonth) ?>">
        <?php if ($onlyWithBreak): ?><input type="hidden" name="only_break" value="1"><?php endif; ?>
        <button type="submit">表示</button>
    </form>
</div>

<div class="filter">
    <?php if ($onlyWithBreak): ?>
        <a href="?month=<?= h($yearMonth) ?>">すべての打刻を表示する</a>（いまは休憩が入っている打刻だけ）
    <?php else: ?>
        <a href="?month=<?= h($yearMonth) ?>&only_break=1">休憩が入っている打刻だけ表示する</a>
    <?php endif; ?>
</div>

<?php if (empty($rows)): ?>
    <p class="notice">この月の打刻はありません。</p>
<?php else: ?>
<form method="post" action="/admin/break_edit.php" id="break-form">
    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="month" value="<?= h($yearMonth) ?>">

    <table class="rows">
        <thead>
        <tr>
            <th>日付</th>
            <th>従業員</th>
            <th>区分</th>
            <th>出勤〜退勤</th>
            <th class="num">拘束</th>
            <th>休憩（分）</th>
            <th class="num">実働（保存後）</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <?php
                $id      = (int) $r['id'];
                $break   = (int) $r['total_break_minutes'];
                $span    = $r['span_minutes'] !== null ? (int) $r['span_minutes'] : null;
                $isOpen  = ($r['clock_out_at'] === null || $r['clock_out_at'] === '');
                $ts      = strtotime((string) $r['work_date']);
                $dateLbl = date('n/j', $ts) . '（' . $weekdayJa[(int) date('w', $ts)] . '）';
                $cat     = (string) $r['category'];
                $catCls  = $cat === '店舗' ? 'store' : ($cat === '集荷' ? 'pickup' : ($cat === '洗濯代行' ? 'laundry' : ''));
            ?>
            <tr class="<?= $break > 0 ? 'has-break' : '' ?>" data-span="<?= $span === null ? '' : $span ?>">
                <td><?= h($dateLbl) ?></td>
                <td><?= h($r['employee_name']) ?></td>
                <td><?php if ($cat !== ''): ?><span class="badge <?= $catCls ?>"><?= h($cat) ?></span><?php endif; ?></td>
                <td>
                    <?= h(date('H:i', strtotime((string) $r['clock_in_at']))) ?>
                    〜
                    <?= $isOpen ? '（勤務中）' : h(date('H:i', strtotime((string) $r['clock_out_at']))) ?>
                </td>
                <td class="num"><?= $span === null ? '—' : h(minutes_label($span)) ?></td>
                <td>
                    <input class="break" type="number" min="0" max="1440" step="1"
                           name="break_minutes[<?= $id ?>]" value="<?= $break ?>"
                           data-original="<?= $break ?>">
                    <button type="button" class="zero-btn">0にする</button>
                </td>
                <td class="num result"><?= $isOpen ? '—' : h(minutes_label(max(0, ($span ?? 0) - $break))) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="save-bar">
        <button type="submit" onclick="return confirm('休憩時間の変更を保存します。よろしいですか？');">変更を保存する</button>
        <span id="dirty-count" style="margin-left:12px; color:#1e7e34;"></span>
    </div>
</form>

<script>
(function () {
    var form = document.getElementById('break-form');
    var counter = document.getElementById('dirty-count');

    function label(mins) {
        if (mins === null) { return '—'; }
        var h = Math.floor(mins / 60), m = mins % 60;
        return h + '時間' + (m < 10 ? '0' + m : m) + '分';
    }

    function refresh() {
        var dirty = 0;
        form.querySelectorAll('input.break').forEach(function (input) {
            var row = input.closest('tr');
            var span = row.dataset.span === '' ? null : parseInt(row.dataset.span, 10);
            var val = parseInt(input.value, 10);
            if (isNaN(val) || val < 0) { val = 0; }

            row.querySelector('td.result').textContent = span === null ? '—' : label(Math.max(0, span - val));

            var isDirty = String(val) !== input.dataset.original;
            row.classList.toggle('dirty', isDirty);
            if (isDirty) { dirty++; }
        });
        counter.textContent = dirty > 0 ? ('変更中: ' + dirty + '件') : '';
    }

    form.addEventListener('input', function (e) {
        if (e.target.classList.contains('break')) { refresh(); }
    });

    form.querySelectorAll('.zero-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            btn.parentElement.querySelector('input.break').value = 0;
            refresh();
        });
    });

    refresh();
})();
</script>
<?php endif; ?>
</body>
</html>
