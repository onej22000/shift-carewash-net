<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/invoice_common.php';

$admin = require_login('admin');
$pdo = getPdo();

const INV_ROWS_PER_PAGE = 18;

$invoice = inv_fetch_invoice($pdo, (int) ($_GET['id'] ?? 0));
if ($invoice === null) {
    http_response_code(404);
    exit('請求書が見つかりません。');
}
$lines = inv_fetch_lines($pdo, (int) $invoice['id']);

// 確定・取消済みは確定時のスナップショットのみを使う（設定を変えても過去の請求書は変わらない）。
// 下書きはプレビューとして現在の設定を使う。
if ($invoice['status'] === 'draft') {
    $issuer = inv_fetch_issuer($pdo);
    $client = inv_fetch_client($pdo, (int) $invoice['client_id']) ?? [];
    $totals = inv_calc_totals(array_sum(array_map(fn ($line) => (int) $line['amount'], $lines)));
} else {
    $issuer = json_decode((string) $invoice['issuer_snapshot'], true) ?: [];
    $client = json_decode((string) $invoice['client_snapshot'], true) ?: [];
    $totals = ['total_incl' => (int) $invoice['total_incl'], 'tax' => (int) $invoice['tax'], 'total_excl' => (int) $invoice['total_excl']];
}

// 明細行の後に【課税10.0% 税込額】【内消費税額】行を続け、固定18行ずつページに分ける。
$rows = [];
foreach ($lines as $line) {
    $rows[] = [
        'code' => (string) $line['product_code'], 'name' => (string) $line['description'],
        'quantity' => (int) $line['quantity'], 'unit' => (string) $line['unit'],
        'unit_price' => $line['unit_price'] === null ? null : (int) $line['unit_price'],
        'amount' => $line['amount'] === null ? null : (int) $line['amount'],
        'note' => '課' . $line['tax_rate'] . '%',
    ];
}
$rows[] = ['code' => '', 'name' => '【課税10.0% 税込額】', 'quantity' => null, 'unit' => '', 'unit_price' => null, 'amount' => $totals['total_incl'], 'note' => ''];
$rows[] = ['code' => '', 'name' => '【内消費税額】', 'quantity' => null, 'unit' => '', 'unit_price' => null, 'amount' => $totals['tax'], 'note' => ''];
$pages = array_chunk($rows, INV_ROWS_PER_PAGE);
$pageCount = count($pages);

$v = fn (string $key): string => (string) ($issuer[$key] ?? '');
$c = fn (string $key): string => (string) ($client[$key] ?? '');
$issueDate = new DateTimeImmutable((string) $invoice['issue_date']);
$invoiceNo = $invoice['invoice_no'] !== null ? str_pad((string) $invoice['invoice_no'], 8, '0', STR_PAD_LEFT) : '（未採番）';
$watermark = ['draft' => '下書き（未確定）', 'void' => '取消済'][$invoice['status']] ?? '';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>請求書 No.<?= inv_h($invoiceNo) ?> <?= inv_h(inv_month_label($invoice['billing_month'])) ?>分</title>
    <style>
        @page { size: A4; margin: 10mm; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #e5e5e5; color: #000; font-family: "Hiragino Mincho ProN", "Yu Mincho", "MS Mincho", serif; font-size: 10pt; }
        .toolbar { padding: 10px 16px; background: #333; color: #fff; font-family: sans-serif; }
        .toolbar button { font-size: 1em; padding: 6px 18px; margin-right: 12px; cursor: pointer; }
        .toolbar a { color: #9cf; }
        .page { position: relative; width: 190mm; height: 277mm; margin: 10mm auto; padding: 0; background: #fff; box-shadow: 0 0 6px rgba(0,0,0,0.3); overflow: hidden; }
        .top { position: relative; height: 18mm; }
        .title { position: absolute; left: 50%; top: 2mm; transform: translateX(-50%); border: 1.5pt solid #000; padding: 1.5mm 14mm; font-size: 18pt; letter-spacing: 1.2em; text-indent: 1.2em; }
        .page-no { position: absolute; right: 0; top: 0; text-align: right; line-height: 1.6; }
        .parties { display: flex; justify-content: space-between; height: 62mm; }
        .client { width: 92mm; padding-top: 2mm; line-height: 1.6; }
        .client .name { font-size: 13pt; margin-top: 2mm; border-bottom: 0.75pt solid #000; padding-bottom: 1mm; }
        .client .name .honorific { margin-left: 4mm; }
        .issuer { width: 88mm; position: relative; line-height: 1.5; font-size: 9pt; }
        .issuer .sales-date { font-size: 10pt; margin-bottom: 2mm; }
        .issuer .company { font-size: 11pt; }
        .issuer .bank { margin-top: 1.5mm; }
        .seal { position: absolute; right: 0; top: 9mm; width: 21mm; height: 21mm; border: 0.5pt solid #999; }
        table.items { width: 100%; border-collapse: collapse; table-layout: fixed; }
        table.items th, table.items td { border: 0.75pt solid #000; padding: 0 1.5mm; }
        table.items th { font-weight: normal; background: #eee; height: 9mm; text-align: center; line-height: 1.2; }
        table.items td { height: 9mm; vertical-align: middle; }
        table.items .code { display: block; font-size: 7pt; line-height: 1.1; }
        table.items .name { display: block; line-height: 1.2; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        table.items .num { text-align: right; white-space: nowrap; }
        table.items .center { text-align: center; }
        table.summary { width: 120mm; margin: 4mm 0 0 auto; border-collapse: collapse; table-layout: fixed; }
        table.summary th, table.summary td { border: 0.75pt solid #000; height: 8mm; text-align: center; }
        table.summary th { font-weight: normal; background: #eee; }
        table.summary td { text-align: right; padding: 0 2mm; font-size: 11pt; }
        .unpriced { color: #c00; font-family: sans-serif; font-size: 8pt; }
        .note { margin-top: 3mm; font-size: 9pt; line-height: 1.5; }
        .watermark { position: absolute; top: 120mm; left: 0; width: 100%; text-align: center; font-size: 48pt; color: rgba(200, 0, 0, 0.18); transform: rotate(-20deg); pointer-events: none; font-family: sans-serif; }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .page { margin: 0; box-shadow: none; page-break-after: always; break-after: page; }
            .page:last-child { page-break-after: auto; break-after: auto; }
            table.items th, table.summary th { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>
<div class="toolbar">
    <button type="button" onclick="window.print()">印刷／PDF保存</button>
    <a href="/admin/invoice.php?id=<?= (int) $invoice['id'] ?>">← 請求書画面に戻る</a>
    <?php if ($watermark !== ''): ?>　※<?= inv_h($watermark) ?>の請求書です<?php endif; ?>
</div>

<?php foreach ($pages as $pageIndex => $pageRows): ?>
<div class="page">
    <?php if ($watermark !== ''): ?><div class="watermark"><?= inv_h($watermark) ?></div><?php endif; ?>
    <div class="top">
        <div class="title">請求書</div>
        <div class="page-no">PAGE <?= $pageIndex + 1 ?> / <?= $pageCount ?><br>No. <?= inv_h($invoiceNo) ?></div>
    </div>
    <div class="parties">
        <div class="client">
            <?php if ($c('postal') !== ''): ?><div>〒<?= inv_h($c('postal')) ?></div><?php endif; ?>
            <div><?= inv_h($c('address1')) ?></div>
            <div><?= inv_h($c('address2')) ?></div>
            <div class="name"><?= inv_h($c('name')) ?><span class="honorific"><?= inv_h($c('honorific')) ?></span></div>
            <?php if ($c('client_code') !== ''): ?><div>(<?= inv_h($c('client_code')) ?>)</div><?php endif; ?>
            <?php if ($c('tel') !== ''): ?><div>TEL <?= inv_h($c('tel')) ?></div><?php endif; ?>
        </div>
        <div class="issuer">
            <div class="sales-date">売上日　<?= inv_h($issueDate->format('Y年m月d日')) ?></div>
            <div class="seal"></div>
            <?php if ($v('postal') !== ''): ?><div>〒<?= inv_h($v('postal')) ?></div><?php endif; ?>
            <div><?= inv_h($v('address1')) ?></div>
            <?php if ($v('address2') !== ''): ?><div><?= inv_h($v('address2')) ?></div><?php endif; ?>
            <div class="company"><?= inv_h($v('company_name')) ?></div>
            <?php if ($v('tel') !== ''): ?><div>TEL <?= inv_h($v('tel')) ?></div><?php endif; ?>
            <?php if ($v('registration_no') !== ''): ?><div>登録番号 <?= inv_h($v('registration_no')) ?></div><?php endif; ?>
            <div class="bank">
                振込先：<?= inv_h($v('bank_name')) ?>　<?= inv_h($v('bank_branch')) ?><br>
                <?= inv_h($v('account_type')) ?>　<?= inv_h($v('account_no')) ?><br>
                名義：<?= inv_h($v('account_holder')) ?>
            </div>
            <?php if ($v('payment_terms') !== ''): ?><div><?= inv_h($v('payment_terms')) ?></div><?php endif; ?>
        </div>
    </div>

    <table class="items">
        <colgroup>
            <col style="width: 86mm"><col style="width: 16mm"><col style="width: 12mm"><col style="width: 24mm"><col style="width: 30mm"><col style="width: 22mm">
        </colgroup>
        <thead>
            <tr>
                <th><span class="code">商品コード</span>商品名</th><th>数量</th><th>単位</th><th>単価</th><th>金額</th><th>備考</th>
            </tr>
        </thead>
        <tbody>
            <?php for ($i = 0; $i < INV_ROWS_PER_PAGE; $i++): ?>
                <?php $row = $pageRows[$i] ?? null; ?>
                <tr>
                    <?php if ($row === null): ?>
                        <td></td><td></td><td></td><td></td><td></td><td></td>
                    <?php else: ?>
                        <td><span class="code"><?= inv_h($row['code']) ?></span><span class="name"><?= inv_h($row['name']) ?></span></td>
                        <td class="num"><?= $row['quantity'] !== null ? inv_yen($row['quantity']) : '' ?></td>
                        <td class="center"><?= inv_h($row['unit']) ?></td>
                        <td class="num"><?= $row['unit_price'] !== null ? inv_yen($row['unit_price']) : '' ?></td>
                        <td class="num"><?= $row['amount'] !== null ? inv_yen($row['amount']) : '<span class="unpriced">単価未登録</span>' ?></td>
                        <td class="center"><?= inv_h($row['note']) ?></td>
                    <?php endif; ?>
                </tr>
            <?php endfor; ?>
        </tbody>
    </table>

    <?php if ($pageIndex === $pageCount - 1): ?>
        <table class="summary">
            <tr><th>税抜額</th><th>消費税額</th><th>合計</th></tr>
            <tr><td><?= inv_yen($totals['total_excl']) ?></td><td><?= inv_yen($totals['tax']) ?></td><td><?= inv_yen($totals['total_incl']) ?></td></tr>
        </table>
        <?php if ((string) ($invoice['note'] ?? '') !== ''): ?>
            <div class="note">備考：<?= nl2br(inv_h($invoice['note'])) ?></div>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php endforeach; ?>
</body>
</html>
