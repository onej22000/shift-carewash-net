<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$admin = require_login('admin');
$pdo = getPdo();

$yearMonth = (string) ($_GET['month'] ?? (new DateTime())->format('Y-m'));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $yearMonth)) {
    $yearMonth = (new DateTime())->format('Y-m');
}

$errorMessage = '';
$executedCount = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errorMessage = '不正なリクエストです。再度お試しください。';
    } elseif ((string) ($_POST['action'] ?? '') === 'execute') {
        $postYearMonth = (string) ($_POST['month'] ?? '');
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $postYearMonth)) {
            $errorMessage = '対象年月の形式が正しくありません。';
        } else {
            $yearMonth = $postYearMonth;

            // プレビュー時にクライアントへ渡した一覧は信用せず、実行直前にサーバー側で同じロジックにより再計算する
            // （他の操作との競合や、確認画面表示後の状態変化があっても、常に最新の実データに基づいて補正するため）。
            $candidates = find_month_end_correction_candidates($pdo, $yearMonth);
            $breakCandidates = find_month_end_break_correction_candidates($pdo, $yearMonth);

            try {
                $pdo->beginTransaction();

                $logStmt = $pdo->prepare(
                    'INSERT INTO attendance_edit_logs (attendance_id, edited_by, action, field_name, old_value, new_value)
                     VALUES (:attendance_id, :edited_by, :action, :field_name, :old_value, :new_value)'
                );
                $updateStmt = $pdo->prepare(
                    'UPDATE attendance SET clock_in_at = :clock_in_at, work_minutes = :work_minutes WHERE id = :id'
                );

                foreach ($candidates as $candidate) {
                    $logStmt->execute([
                        ':attendance_id' => $candidate['attendance_id'],
                        ':edited_by' => $admin['id'],
                        ':action' => 'month_end_correction',
                        ':field_name' => 'clock_in_at',
                        ':old_value' => $candidate['old_clock_in_at'],
                        ':new_value' => $candidate['new_clock_in_at'],
                    ]);

                    $workMinutes = null;
                    if ($candidate['clock_out_at'] !== null) {
                        $newClockIn = new DateTime($candidate['new_clock_in_at']);
                        $clockOut = new DateTime($candidate['clock_out_at']);
                        $rawMinutes = max(0, (int) round(($clockOut->getTimestamp() - $newClockIn->getTimestamp()) / 60));
                        $workMinutes = max(0, $rawMinutes - ($candidate['total_break_minutes'] ?? 0));
                    }

                    $updateStmt->execute([
                        ':clock_in_at' => $candidate['new_clock_in_at'],
                        ':work_minutes' => $workMinutes,
                        ':id' => $candidate['attendance_id'],
                    ]);
                }

                $breakUpdateStmt = $pdo->prepare(
                    'UPDATE attendance
                        SET total_break_minutes = :total_break_minutes,
                            work_minutes = GREATEST(0, TIMESTAMPDIFF(MINUTE, clock_in_at, clock_out_at) - :break_for_work)
                      WHERE id = :id'
                );
                foreach ($breakCandidates as $candidate) {
                    $logStmt->execute([
                        ':attendance_id' => $candidate['attendance_id'],
                        ':edited_by' => $admin['id'],
                        ':action' => 'month_end_correction',
                        ':field_name' => 'total_break_minutes',
                        ':old_value' => $candidate['old_break_minutes'],
                        ':new_value' => $candidate['new_break_minutes'],
                    ]);
                    $breakUpdateStmt->execute([
                        ':total_break_minutes' => $candidate['new_break_minutes'],
                        ':break_for_work' => $candidate['new_break_minutes'],
                        ':id' => $candidate['attendance_id'],
                    ]);
                }

                $pdo->commit();
                $executedCount = count($candidates) + count($breakCandidates);
                set_flash('success', '月末チェックを実行しました（出勤時刻・店舗勤務の休憩 合計' . $executedCount . '件を補正）。');
                header('Location: /admin/month_end_check.php?month=' . urlencode($yearMonth));
                exit;
            } catch (\Throwable $e) {
                $pdo->rollBack();
                $errorMessage = '補正の実行に失敗しました。もう一度お試しください。';
            }
        }
    }
}

$flash = pop_flash();
$candidates = find_month_end_correction_candidates($pdo, $yearMonth);
$breakCandidates = find_month_end_break_correction_candidates($pdo, $yearMonth);

$prevMonth = (DateTime::createFromFormat('Y-m-d', $yearMonth . '-01'))->modify('-1 month')->format('Y-m');
$nextMonth = (DateTime::createFromFormat('Y-m-d', $yearMonth . '-01'))->modify('+1 month')->format('Y-m');

$confirmedCountStmt = $pdo->prepare('SELECT COUNT(*) FROM monthly_wages WHERE `year_month` = :year_month');
$confirmedCountStmt->execute([':year_month' => $yearMonth]);
$confirmedCount = (int) $confirmedCountStmt->fetchColumn();

$csrfToken = csrf_token();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>月末チェック | 管理者</title>
    <style>
        body { font-family: sans-serif; margin: 16px; color: #222; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        h1 { font-size: 1.3em; margin: 0; }
        .message { padding: 8px 12px; border-radius: 4px; margin-bottom: 12px; }
        .message.success { background: #e6f4ea; color: #1e7e34; }
        .message.error { background: #fdecea; color: #b3261e; }
        .notice { padding: 8px 12px; background: #fff3cd; color: #856404; border-radius: 4px; margin-bottom: 12px; }
        .month-nav { margin-bottom: 12px; }
        .month-nav a { margin-right: 12px; }
        .month-nav form { display: inline-block; margin-left: 8px; }
        table.candidates { border-collapse: collapse; width: 100%; margin-bottom: 16px; }
        table.candidates th, table.candidates td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        table.candidates th { background: #f5f5f5; }
        .old-time { color: #b3261e; text-decoration: line-through; }
        .new-time { color: #1e7e34; font-weight: bold; }
        .exec-form button { font-size: 1.05em; padding: 10px 24px; border-radius: 6px; border: none; color: #fff; background: #b3261e; cursor: pointer; }
    </style>
</head>
<body>
<header>
    <h1>月末チェック（店舗区分の出勤時刻・法定休憩補正）</h1>
    <nav>ログイン中: <?= htmlspecialchars($admin['name'], ENT_QUOTES, 'UTF-8') ?>さん（管理者） | <a href="/admin/attendance_monthly.php?month=<?= htmlspecialchars($yearMonth, ENT_QUOTES, 'UTF-8') ?>">月間打刻実績に戻る</a> | <a href="/admin/dashboard.php">ダッシュボード</a> | <a href="/admin/logout.php">ログアウト</a></nav>
</header>

<?php if ($flash !== null): ?>
    <p class="message <?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<?php if ($errorMessage !== ''): ?>
    <p class="message error"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<p class="notice">
    対象は打刻区分「店舗」のシフトの出勤打刻のみです（「集荷」「洗濯代行」は対象外・変更されません）。<br>
    休憩時間は「店舗」の勤務だけを通算し、法定時間に不足する分を店舗打刻へ補正します。「集荷」「洗濯代行」は勤務時間も通算せず、本人の休憩入力をそのまま採用して変更しません。<br>
    シフトの予定出勤時刻の5分より前に打刻していた場合のみ「予定出勤時刻の5分前」に補正します。5分前〜予定時刻の間はそのまま、遅刻は対象外です。<br>
    実行すると、補正内容はすべて打刻修正履歴（attendance_edit_logs）に記録されます。
</p>

<?php if ($confirmedCount > 0): ?>
    <p class="notice">この月は<?= $confirmedCount ?>名分の賃金が確定済みです。補正を実行した場合、対象者はadmin/wages.phpで再確定が必要になることがあります。</p>
<?php endif; ?>

<div class="month-nav">
    <a href="?month=<?= htmlspecialchars($prevMonth, ENT_QUOTES, 'UTF-8') ?>">← 前月</a>
    <strong><?= htmlspecialchars($yearMonth, ENT_QUOTES, 'UTF-8') ?></strong>
    <a href="?month=<?= htmlspecialchars($nextMonth, ENT_QUOTES, 'UTF-8') ?>">次月 →</a>
    <form method="get" action="/admin/month_end_check.php">
        <input type="month" name="month" value="<?= htmlspecialchars($yearMonth, ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit">表示</button>
    </form>
</div>

<h2>出勤時刻の補正対象（<?= htmlspecialchars($yearMonth, ENT_QUOTES, 'UTF-8') ?>）</h2>

<?php if (empty($candidates)): ?>
    <p class="notice">補正が必要な打刻はありません。</p>
<?php else: ?>
    <table class="candidates">
        <thead>
            <tr>
                <th>従業員</th>
                <th>日付</th>
                <th>予定出勤時刻</th>
                <th>元の出勤打刻</th>
                <th>補正後の出勤打刻</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($candidates as $candidate): ?>
                <tr>
                    <td><?= htmlspecialchars($candidate['employee_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($candidate['work_date'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars(substr($candidate['shift_start_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="old-time"><?= htmlspecialchars(substr($candidate['old_clock_in_at'], 11, 5), ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="new-time"><?= htmlspecialchars(substr($candidate['new_clock_in_at'], 11, 5), ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

<?php endif; ?>

<h2>店舗勤務の休憩補正対象</h2>
<?php if (empty($breakCandidates)): ?>
    <p class="notice">休憩時間の補正はありません。</p>
<?php else: ?>
    <table class="candidates">
        <thead>
            <tr><th>従業員</th><th>日付</th><th>入力済み休憩</th><th>補正後の休憩</th></tr>
        </thead>
        <tbody>
            <?php foreach ($breakCandidates as $candidate): ?>
                <tr>
                    <td><?= htmlspecialchars($candidate['employee_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($candidate['work_date'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="old-time"><?= (int) $candidate['old_break_minutes'] ?>分</td>
                    <td class="new-time"><?= (int) $candidate['new_break_minutes'] ?>分</td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php if (!empty($candidates) || !empty($breakCandidates)): ?>
    <form method="post" action="/admin/month_end_check.php" class="exec-form"
          onsubmit="return confirm('出勤時刻<?= count($candidates) ?>件、店舗勤務の休憩<?= count($breakCandidates) ?>件を補正します。よろしいですか？');">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="execute">
        <input type="hidden" name="month" value="<?= htmlspecialchars($yearMonth, ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit">この内容で実行する</button>
    </form>
<?php endif; ?>
</body>
</html>
