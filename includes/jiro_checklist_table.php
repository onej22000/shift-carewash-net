<?php
/**
 * 集荷ドライバー出発前チェックリストのテーブル部分。$checklist（build_jiro_checklist_data()の
 * 戻り値）をスコープに用意した上でrequireすること。staff/jiro_dashboard.phpから使用する
 * （staff/dashboard.php側は件数・合計のみのサマリー表示のため、この部分テンプレートは使わない）。
 *
 * 返却空リネン袋（回収すべき空のオレンジ袋数）と返却リネン袋数（洗濯代行が返却準備完了と
 * して登録済みの袋数）は別物。前者は施設の直近サイクルのpickup_bag_countをそのまま表示し、
 * 後者は直近サイクルの返却準備完了状況（return_ready_bag_count）に応じて数値／「作業前」／
 * 空欄を出し分ける（ドライバーの最終返却確定=return_bag_countとは別物）。
 * 集荷空リネン袋列は返却リネン袋数列と同じソース・同じロジック（値は同一）で、
 * staff/collection_entry.phpの新規集荷登録時の表示と用語を揃えるために追加した列（2026-08-14）。
 *
 * 背景色はcollection_headcount.php・collection_entry.phpのカード表と同じ関数
 * issued_bag_color_row_class()を再利用する（本来tr向けの命名だが、この表は
 * 施設名列を含む横長の表のため行全体ではなくセル単位で色を付ける）。
 */
$renderChecklistRow = static function (array $row): void {
    $issuedBagCellClass = issued_bag_color_row_class([
        'issued_bag_orange' => $row['latest_cycle_issued_bag_orange'],
        'issued_bag_yellow' => $row['latest_cycle_issued_bag_yellow'],
    ]);
    ?>
    <tr>
        <td><?= htmlspecialchars($row['facility_name'], ENT_QUOTES, 'UTF-8') ?></td>
        <td class="<?= $issuedBagCellClass ?>"><?= $row['latest_cycle_pickup_bag_count'] !== null ? (int) $row['latest_cycle_pickup_bag_count'] . '袋' : '' ?></td>
        <td class="row-return">
            <?php if ($row['latest_cycle_status'] === 'confirmed'): ?>
                <?= (int) $row['latest_cycle_return_ready_bag_count'] ?>袋
            <?php elseif ($row['latest_cycle_status'] === 'in_progress'): ?>
                作業前
            <?php endif; ?>
        </td>
        <td class="row-return">
            <?php if ($row['latest_cycle_status'] === 'confirmed'): ?>
                <?= (int) $row['latest_cycle_return_ready_bag_count'] ?>袋
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
                    <th>返却リネン袋</th>
                    <th>集荷空リネン袋</th>
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
    <?php
    $otherPickupTotal = 0;
    $otherReturnReadyTotal = 0;
    foreach ($checklist['other_rows'] as $row) {
        $otherPickupTotal += (int) ($row['latest_cycle_pickup_bag_count'] ?? 0);
        if ($row['latest_cycle_status'] === 'confirmed') {
            $otherReturnReadyTotal += (int) $row['latest_cycle_return_ready_bag_count'];
        }
    }
    ?>
    <section class="jiro-checklist-other">
        <h2>その他の返却待ち</h2>
        <table class="cycles">
            <thead>
                <tr>
                    <th>施設名</th>
                    <th>返却空リネン袋</th>
                    <th>返却リネン袋</th>
                    <th>集荷空リネン袋</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($checklist['other_rows'] as $row): ?>
                    <?php $renderChecklistRow($row); ?>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th>合計</th>
                    <td><?= $otherPickupTotal ?>袋</td>
                    <td><?= $otherReturnReadyTotal ?>袋</td>
                    <td><?= $otherReturnReadyTotal ?>袋</td>
                </tr>
            </tfoot>
        </table>
    </section>
<?php endif; ?>
