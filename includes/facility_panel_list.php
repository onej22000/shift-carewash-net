<?php
/**
 * 施設パネル一覧（集荷曜日グループ化・施設番号順・デフォルト展開・レスポンシブグリッド）の
 * 描画パーツ。staff/collection_headcount.php・staff/collection_entry.phpの介護施設向け
 * 未返却サイクル一覧で共通利用する。$facilityPanelGroups（group_open_cycles_into_facility_panels()
 * の戻り値）・$renderCycleCard（1サイクル分のカードを描画するcallable、array $cycle を受け取る）・
 * $emptyMessage（対象0件の場合の文言）をスコープに用意した上でrequireすること。
 *
 * .facility-group/.facility-panels/.facility-panel等のCSSは各呼び出し元ページの<style>で
 * 個別に定義する（mobile-ui.cssのような共有CSSファイルではなく、cycle-card等の既存スタイルと
 * 同じくページごとに持つ、このコードベースの既存の流儀に合わせている）。
 */
if (empty($facilityPanelGroups)): ?>
    <p class="notice"><?= htmlspecialchars($emptyMessage, ENT_QUOTES, 'UTF-8') ?></p>
<?php else: ?>
    <?php foreach ($facilityPanelGroups as $groupLabel => $facilityPanels): ?>
        <div class="facility-group">
            <h3 class="facility-group-title"><?= htmlspecialchars($groupLabel, ENT_QUOTES, 'UTF-8') ?></h3>
            <div class="facility-panels">
                <?php foreach ($facilityPanels as $panel): ?>
                    <details class="facility-panel" open>
                        <summary class="facility-panel-summary">
                            <span class="facility-panel-name"><?= htmlspecialchars($panel['facility_name'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="facility-panel-count"><?= count($panel['cycles']) ?>件</span>
                        </summary>
                        <div class="cycle-cards facility-panel-cycles">
                            <?php foreach ($panel['cycles'] as $cycle): ?>
                                <?php $renderCycleCard($cycle); ?>
                            <?php endforeach; ?>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
