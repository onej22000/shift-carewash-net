<?php
/**
 * 集荷ドライバー出発前チェックリストのテーブル部分。$checklist（build_jiro_checklist_data()の
 * 戻り値）をスコープに用意した上でrequireすること。staff/jiro_dashboard.phpから使用する
 * （staff/dashboard.php側は件数・合計のみのサマリー表示のため、この部分テンプレートは使わない）。
 *
 * 返却空リネン袋（回収すべき空のオレンジ袋数）と返却リネン袋（青）数（洗濯代行が返却準備完了と
 * して登録済みの袋数）は別物。前者は施設の直近サイクルのpickup_bag_countをそのまま表示し、
 * 後者は直近サイクルの返却確定状況（return_bag_count）に応じて数値／「作業前」／空欄を出し分ける。
 */
$renderChecklistRow = static function (array $row): void {
    ?>
    <tr>
        <td><?= htmlspecialchars($row['facility_name'], ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= $row['latest_cycle_pickup_bag_count'] !== null ? (int) $row['latest_cycle_pickup_bag_count'] . '袋' : '' ?></td>
        <td>
            <?php if ($row['latest_cycle_status'] === 'confirmed'): ?>
                <?= (int) $row['latest_cycle_return_bag_count'] ?>袋
            <?php elseif ($row['latest_cycle_status'] === 'in_progress'): ?>
                作業前
            <?php endif; ?>
        </td>
    </tr>
    <?php
};
$scheduleDateLabel = $scheduleDateLabel ?? '本日';
?>
<section class="jiro-checklist-today">
    <h2><?= htmlspecialchars($scheduleDateLabel, ENT_QUOTES, 'UTF-8') ?>の集荷予定（<?= htmlspecialchars($checklist['target_date'], ENT_QUOTES, 'UTF-8') ?>）<?= $checklist['today_schedule_label'] !== null ? '（' . htmlspecialchars($checklist['today_schedule_label'], ENT_QUOTES, 'UTF-8') . 'コース）' : '' ?></h2>
    <?php if ($checklist['today_schedule_label'] === null): ?>
        <p class="notice"><?= htmlspecialchars($scheduleDateLabel, ENT_QUOTES, 'UTF-8') ?>は集荷予定日ではありません。</p>
    <?php elseif (empty($checklist['today_rows'])): ?>
        <p class="notice">なし</p>
    <?php else: ?>
        <table class="cycles">
            <thead>
                <tr>
                    <th>施設名</th>
                    <th>返却空リネン袋</th>
                    <th>返却リネン袋（青）数</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($checklist['today_rows'] as $row): ?>
                    <?php $renderChecklistRow($row); ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<?php if (!empty($checklist['other_rows'])): ?>
    <section class="jiro-checklist-other">
        <h2>その他の返却待ち</h2>
        <table class="cycles">
            <thead>
                <tr>
                    <th>施設名</th>
                    <th>返却空リネン袋</th>
                    <th>返却リネン袋（青）数</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($checklist['other_rows'] as $row): ?>
                    <?php $renderChecklistRow($row); ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
<?php endif; ?>

<section class="jiro-checklist-totals">
    <table class="cycles">
        <tbody>
            <tr>
                <th>合計</th>
                <td>オレンジ <?= (int) $checklist['totals']['orange'] ?>袋 ／ 黄 <?= (int) $checklist['totals']['yellow'] ?>袋</td>
                <td>青 <?= (int) $checklist['totals']['blue'] ?>袋</td>
                <td><?= (int) $checklist['totals']['total'] ?>袋</td>
            </tr>
        </tbody>
    </table>
</section>
