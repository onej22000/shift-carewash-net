<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$admin = require_login('admin');
$pdo = getPdo();

$vehicleAlerts = calc_vehicle_alerts($pdo, (new DateTime())->format('Y-m-d'));
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理者ダッシュボード | シフト管理</title>
    <style>
        body { font-family: sans-serif; margin: 16px; color: #222; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        h1 { font-size: 1.3em; margin: 0; }
        .greeting { font-size: 1.1em; margin-bottom: 24px; }
        .nav-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }
        .nav-card { display: block; border: 1px solid #ccc; border-radius: 8px; padding: 16px; text-decoration: none; color: #222; background: #fff; transition: box-shadow 0.15s, border-color 0.15s; }
        .nav-card:hover { border-color: #0b5ed7; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .nav-card h2 { font-size: 1.05em; margin: 0 0 8px; color: #0b5ed7; }
        .nav-card p { margin: 0; font-size: 0.9em; color: #555; }
        .badge { display: inline-block; font-size: 0.75em; padding: 2px 6px; border-radius: 4px; background: #fff3cd; color: #856404; margin-left: 6px; }
        .vehicle-alert-banner { padding: 12px 16px; background: #fdecea; border: 2px solid #b3261e; border-radius: 6px; color: #7a1913; margin-bottom: 16px; }
        .vehicle-alert-banner h2 { margin: 0 0 8px; font-size: 1.05em; color: #b3261e; }
        .vehicle-alert-banner ul { margin: 0; padding-left: 20px; }
        .vehicle-alert-banner li { margin-bottom: 4px; }
    </style>
</head>
<body>
<header>
    <h1>管理者ダッシュボード</h1>
    <nav>ログイン中: <?= htmlspecialchars($admin['name'], ENT_QUOTES, 'UTF-8') ?>さん（管理者） | <a href="/admin/logout.php">ログアウト</a></nav>
</header>

<?php if (!empty($vehicleAlerts)): ?>
    <div class="vehicle-alert-banner">
        <h2>⚠ 車両の期限・交換時期に関する警告</h2>
        <ul>
            <?php foreach ($vehicleAlerts as $alert): ?>
                <li><?= htmlspecialchars($alert['vehicle_label'], ENT_QUOTES, 'UTF-8') ?>：<?= htmlspecialchars($alert['label'], ENT_QUOTES, 'UTF-8') ?>（<?= htmlspecialchars($alert['detail'], ENT_QUOTES, 'UTF-8') ?>）</li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<p class="greeting">こんにちは、<?= htmlspecialchars($admin['name'], ENT_QUOTES, 'UTF-8') ?>さん</p>

<section class="nav-cards">
    <a class="nav-card" href="/admin/shifts.php">
        <h2>シフト表作成</h2>
        <p>週・月単位のカレンダーでシフトを登録・編集します。</p>
    </a>
    <a class="nav-card" href="/admin/employees.php">
        <h2>従業員管理</h2>
        <p>従業員の登録・時給設定・招待コード発行を行います。</p>
    </a>
    <a class="nav-card" href="/admin/wages.php">
        <h2>賃金確認</h2>
        <p>日次・月中の集計確認と、月次の確定処理を行います。</p>
    </a>
    <a class="nav-card" href="/admin/facilities.php">
        <h2>施設管理</h2>
        <p>取引先施設の登録・無効化を行います。</p>
    </a>
    <a class="nav-card" href="/admin/work_status.php">
        <h2>作業状況・残数確認</h2>
        <p>施設ごとの工程別累計・滞留・残数を確認します。</p>
    </a>
    <a class="nav-card" href="/admin/work_speed.php">
        <h2>作業速度分析</h2>
        <p>洗濯・乾燥・畳みの1人あたり所要時間を従業員別・施設別に確認します。</p>
    </a>
    <a class="nav-card" href="/admin/attendance_edit_logs.php">
        <h2>打刻修正履歴</h2>
        <p>従業員自身による出退勤・休憩打刻の修正履歴を確認します。</p>
    </a>
    <a class="nav-card" href="/admin/shift_edit_logs.php">
        <h2>シフト編集履歴</h2>
        <p>従業員自身によるシフトの新規登録・変更・削除の履歴を確認します。</p>
    </a>
    <a class="nav-card" href="/admin/attendance_monthly.php">
        <h2>月間打刻実績</h2>
        <p>従業員×日付のグリッドで、シフト予定と実際の打刻実績を並べて確認します。</p>
    </a>
    <a class="nav-card" href="/admin/work_stage_records.php">
        <h2>作業実績の管理</h2>
        <p>集荷・洗濯・乾燥・畳みの個別記録を一覧・登録・修正・削除します。</p>
    </a>
    <a class="nav-card" href="/admin/work_stage_record_edit_logs.php">
        <h2>作業実績修正履歴</h2>
        <p>管理者による作業実績の追加・修正・削除の履歴を確認します。</p>
    </a>
    <a class="nav-card" href="/admin/collection_records.php">
        <h2>集荷・配送記録簿</h2>
        <p>施設・対象月ごとの集荷〜到着〜発送〜返却の記録を一覧・登録・修正・削除・PDF出力します。</p>
    </a>
    <a class="nav-card" href="/admin/collection_cycle_edit_logs.php">
        <h2>集荷・配送記録修正履歴</h2>
        <p>従業員・管理者による集荷・配送記録の追加・修正・削除履歴を確認します。</p>
    </a>
    <a class="nav-card" href="/admin/collection_headcount.php">
        <h2>集荷人数の確認</h2>
        <p>到着済みリネン袋の中身（人数）を確認・登録し、既存の確認記録を修正・削除します。</p>
    </a>
    <a class="nav-card" href="/admin/consumable_stock.php">
        <h2>消耗品在庫管理</h2>
        <p>リネン袋（オレンジ・黄・青）・洗濯ネットの在庫を登録・修正・取り消しします。</p>
    </a>
    <a class="nav-card" href="/admin/vehicles.php">
        <h2>車両マスタ管理</h2>
        <p>ナンバープレート・号車名の登録・編集・無効化を行います。</p>
    </a>
    <a class="nav-card" href="/admin/vehicle_check_list.php">
        <h2>集荷前車両等チェック記録</h2>
        <p>全従業員分の集荷前車両点検記録を一覧・登録・修正・削除します。</p>
    </a>
    <a class="nav-card" href="/admin/vehicle_maintenance_list.php">
        <h2>車両管理記録</h2>
        <p>車検・保険・オイル・タイヤ交換の記録を車両ごとに登録・修正・削除します。</p>
    </a>
    <a class="nav-card" href="/admin/vehicle_alert_settings.php">
        <h2>車両アラート設定</h2>
        <p>車検・保険期限、オイル・タイヤ交換の警告を出す日数を設定します。</p>
    </a>
</section>
</body>
</html>
