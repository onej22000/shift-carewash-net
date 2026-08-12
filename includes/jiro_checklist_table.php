<?php
/**
 * 集荷ドライバー出発前チェックリストのテーブル部分。$checklist（build_jiro_checklist_data()の
 * 戻り値）をスコープに用意した上でrequireすること。staff/jiro_dashboard.phpから使用する
 * （staff/dashboard.php側は件数・合計のみのサマリー表示のため、この部分テンプレートは使わない）。
 */
$renderChecklistRow = static function (array $row): void {
    ?>
    <tr>
        <td><?= htmlspecialchars($row['facility_name'], ENT_QUOTES, 'UTF-8') ?></td>
        <td>
            <?php if (!$row['has_history']): ?>
                実績なし
            <?php elseif ($row['last_pickup_bag_count'] === null): ?>
                -
            <?php else: ?>
                <?= $row['last_pickup_color'] !== null ? htmlspecialchars($row['last_pickup_color'], ENT_QUOTES, 'UTF-8') . ' ' : '' ?><?= (int) $row['last_pickup_bag_count'] ?>袋
            <?php endif; ?>
        </td>
        <td><?= $row['return_ready_total'] > 0 ? (int) $row['return_ready_total'] . '袋' : '-' ?></td>
        <td><?= (int) $row['row_total'] ?>袋</td>
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
                    <th>前回集荷袋数</th>
                    <th>返却リネン袋数（青）</th>
                    <th>合計</th>
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
                    <th>前回集荷袋数</th>
                    <th>返却リネン袋数（青）</th>
                    <th>合計</th>
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
