<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/accounting_ledger.php';

$admin = require_login('admin');
$pdo = getPdo();

/**
 * 期首残高・基本情報。
 * 弥生会計からシフトシステムへ切り替えるとき、記帳を始める事業年度の「期首の貸借対照表」（前期末の残高試算表）を登録する。
 * 以降の年度の期首残高は、前年度の期末残高から自動で引き継ぐ（損益は繰越利益剰余金へ）。
 */

[$book, $books] = acc_select_book($pdo);
$bookId = (int) $book['id'];
$self = '/admin/accounting_opening.php?book=' . $bookId;
$chart = acc_chart_map($pdo, $book);
$settings = acc_book_settings($pdo, $bookId);

const OPENING_PROFILE_FIELDS = [
    'corporate' => [
        'legal_name' => '法人名', 'address' => '本店所在地', 'representative' => '代表者名', 'corp_no' => '法人番号（13桁）', 'capital' => '資本金の額（円）',
        'established' => '設立年月日', 'tax_office' => '所轄税務署', 'business' => '事業の種類', 'phone' => '電話番号',
    ],
    'personal' => [
        'name' => '氏名', 'address' => '住所（納税地）', 'trade_name' => '屋号', 'business' => '事業の種類', 'phone' => '電話番号', 'tax_office' => '所轄税務署',
    ],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('error', '不正なリクエストです。画面を再読み込みしてやり直してください。');
        header('Location: ' . $self);
        exit;
    }
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_settings') {
        $fy = (int) ($_POST['opening_fy'] ?? 0);
        $profile = $settings['profile'];
        foreach (array_keys(OPENING_PROFILE_FIELDS[$book['kind']] ?? OPENING_PROFILE_FIELDS['corporate']) as $k) {
            $profile[$k] = acc_clean_text((string) ($_POST['profile'][$k] ?? ''));
        }
        if ($fy < 2000 || $fy > 2100) {
            set_flash('error', '記帳開始年度を選んでください。');
        } else {
            acc_book_settings_save($pdo, $bookId, $fy, $profile);
            set_flash('success', '保存しました。');
        }
        header('Location: ' . $self);
        exit;
    }

    if ($action === 'save_opening') {
        // 貼り付け欄: 1行=「科目 [Tab/カンマ] 補助科目 [Tab/カンマ] 借方 [Tab/カンマ] 貸方」。補助科目・貸方は省略できる
        $lines = preg_split('/\R/u', (string) ($_POST['bulk'] ?? '')) ?: [];
        $rows = [];
        $errors = [];
        foreach ($lines as $n => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $cols = preg_split(str_contains($line, "\t") ? '/\t/u' : '/,/u', $line) ?: [];
            $cols = array_map('trim', $cols);
            if (count($cols) < 2) {
                $errors[] = ($n + 1) . '行目: 「科目、補助科目、借方、貸方」の形で入力してください。';
                continue;
            }
            // 補助科目が無い場合は 科目,借方,貸方 の順とみなす
            if (count($cols) === 2) {
                $cols = [$cols[0], '', $cols[1], ''];
            } elseif (count($cols) === 3) {
                $cols = preg_match('/\A[\d,，]+\z/u', $cols[1]) === 1 ? [$cols[0], '', $cols[1], $cols[2]] : [$cols[0], $cols[1], $cols[2], ''];
            }
            [$account, $sub, $debit, $credit] = array_pad($cols, 4, '');
            $num = static fn (string $v): int => (int) str_replace([',', '，', ' ', '△', '円'], '', $v);
            $debit = $num($debit);
            $credit = $num($credit);
            $account = acc_clean_text($account);
            if (!isset($chart[$account])) {
                $errors[] = ($n + 1) . '行目: 「' . $account . '」は勘定科目マスタにありません。先にマスタへ追加してください。';
                continue;
            }
            if (in_array($chart[$account]['category'], ['revenue', 'expense'], true)) {
                $errors[] = ($n + 1) . '行目: 「' . $account . '」は損益の科目です。期首残高には貸借の科目だけ入れます（前期までの損益は繰越利益剰余金／元入金に含めます）。';
                continue;
            }
            $amount = $debit - $credit;
            if ($amount === 0) {
                continue;
            }
            $key = $account . "\x1f" . acc_clean_text($sub);
            $rows[$key] = ($rows[$key] ?? 0) + $amount;
        }
        $sum = array_sum($rows);
        if ($errors === [] && $sum !== 0) {
            $errors[] = '借方と貸方の合計が一致しません（差額 ' . number_format($sum) . '円）。';
        }
        if ($errors) {
            set_flash('error', implode("\n", array_slice($errors, 0, 12)));
        } else {
            $pdo->beginTransaction();
            try {
                $pdo->prepare('DELETE FROM acc_opening WHERE book_id = :b')->execute([':b' => $bookId]);
                $insert = $pdo->prepare('INSERT INTO acc_opening (book_id, account, sub, amount) VALUES (:b, :a, :s, :m)');
                foreach ($rows as $k => $amount) {
                    [$account, $sub] = explode("\x1f", $k, 2);
                    $insert->execute([':b' => $bookId, ':a' => $account, ':s' => $sub, ':m' => $amount]);
                }
                $pdo->commit();
                set_flash('success', count($rows) . '行の期首残高を保存しました（以前の期首残高は置き換えました）。');
            } catch (Throwable $e) {
                $pdo->rollBack();
                error_log('accounting opening save failed: ' . $e->getMessage());
                set_flash('error', '保存に失敗しました。');
            }
        }
        header('Location: ' . $self);
        exit;
    }
    header('Location: ' . $self);
    exit;
}

$flash = pop_flash();
$opening = acc_opening_balances($pdo, $bookId);
ksort($opening);
$bulk = '';
$debitTotal = 0;
$creditTotal = 0;
foreach ($opening as $k => $amount) {
    [$account, $sub] = explode("\x1f", $k, 2);
    $bulk .= $account . "\t" . $sub . "\t" . ($amount > 0 ? $amount : '') . "\t" . ($amount < 0 ? -$amount : '') . "\n";
    $amount > 0 ? $debitTotal += $amount : $creditTotal -= $amount;
}
$fields = OPENING_PROFILE_FIELDS[$book['kind']] ?? OPENING_PROFILE_FIELDS['corporate'];
$yearNow = acc_current_fiscal_year($book);

acc_render_header($admin, '期首残高・基本情報', 'opening', $bookId);
acc_render_messages($flash);
?>
<div class="yb-toolbar" style="margin-top:8px"><?php acc_render_book_selector($books, $book, '/admin/accounting_opening.php'); ?></div>
<div class="yb-panel">
    <h3>記帳開始年度と基本情報</h3>
    <form method="post" action="<?= acc_h($self) ?>">
        <input type="hidden" name="csrf_token" value="<?= acc_h(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_settings">
        <p><label>この帳簿で記帳を始める事業年度:
            <select name="opening_fy">
                <option value="">選んでください</option>
                <?php for ($y = $yearNow + 1; $y >= $yearNow - 6; $y--) : ?>
                    <option value="<?= $y ?>"<?= $settings['opening_fy'] === $y ? ' selected' : '' ?>><?= $y ?>年<?= (int) $book['fiscal_start_month'] ?>月開始の事業年度</option>
                <?php endfor; ?>
            </select></label>
            <span class="muted">（期首残高は、この事業年度の期首のものを登録します）</span></p>
        <table class="list">
            <?php foreach ($fields as $k => $label) : ?>
                <tr><th style="text-align:left"><?= acc_h($label) ?></th><td><input type="text" name="profile[<?= acc_h($k) ?>]" value="<?= acc_h($settings['profile'][$k] ?? '') ?>" size="44" maxlength="200"></td></tr>
            <?php endforeach; ?>
        </table>
        <p class="muted">マイナンバー・個人番号は保存しません。申告書の出力時に手で書き込んでください。</p>
        <button type="submit" class="primary">保存</button>
    </form>
</div>

<div class="yb-panel">
    <h3>期首残高（<?= $settings['opening_fy'] === null ? '記帳開始年度が未設定' : (int) $settings['opening_fy'] . '年度の期首' ?>）</h3>
    <p class="muted">前期末の貸借対照表（弥生会計の「残高試算表」や前期の決算書）の残高を入れます。1行に「科目、補助科目、借方、貸方」をタブまたはカンマで区切って入力します
        （補助科目が無ければ「科目、借方、貸方」でも構いません）。前期までの利益の累計は<?= $book['kind'] === 'personal' ? '元入金' : '繰越利益剰余金' ?>の貸方に含めます。
        この欄の内容で、以前の期首残高をすべて置き換えます。</p>
    <form method="post" action="<?= acc_h($self) ?>">
        <input type="hidden" name="csrf_token" value="<?= acc_h(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_opening">
        <textarea name="bulk" rows="18" cols="90" style="font-family:monospace" placeholder="普通預金&#9;○○銀行&#9;1234567&#10;売掛金&#9;株式会社CareWash&#9;665000&#10;資本金&#9;&#9;&#9;1000000"><?= acc_h($bulk) ?></textarea>
        <p>現在の登録: 借方計 <strong><?= number_format($debitTotal) ?></strong> ／ 貸方計 <strong><?= number_format($creditTotal) ?></strong>
            <?= $debitTotal === $creditTotal ? '<span class="badge badge-ok">一致</span>' : '<span class="badge badge-diff">差額 ' . number_format($debitTotal - $creditTotal) . '</span>' ?></p>
        <button type="submit" class="primary">期首残高を保存</button>
    </form>
</div>
<?php acc_render_footer(); ?>
