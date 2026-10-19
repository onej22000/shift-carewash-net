<?php
/**
 * 見積書機能（admin/quotations.php・quotation_edit.php・quotation_view.php）の共通処理。
 * テーブルは quotations（admin/sql/quotations.sql）。
 *
 * 発行元は請求書の inv_issuer とは独立した見積書専用の値（QUOTATION_ISSUER）を使い、
 * 発行時点の値を quotations.issuer_snapshot にJSONで保存する。表示・PDFはスナップショットのみを使うため、
 * 後から QUOTATION_ISSUER を変えても発行済みの見積書は変わらない。
 *
 * 金額：税抜金額 = 入居者数 × 単価（税抜）、消費税は1円未満切り捨て（請求書機能と同じ）。
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

const QUOTATION_DEFAULT_UNIT_PRICE = 4500;
const QUOTATION_TAX_RATE = 10;
const QUOTATION_HONORIFICS = ['御中', '様'];
const QUOTATION_ITEM_NAME = 'CareWash 洗濯代行サービス 月額利用料';

const QUOTATION_ISSUER = [
    'company_name' => '株式会社CareWash',
    'postal' => '320-0051',
    'address' => '栃木県宇都宮市上戸祭町3014番地3',
    'tel' => '090-6249-9612',
    'contact_title' => '関西支部長',
    'contact_name' => '西科潤一',
    'contact_kana' => 'にしなじゅんいち',
    'registration_no' => 'T8060001038718',
];

function quotation_esc($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function quotation_yen(int $amount): string
{
    return '¥' . number_format($amount);
}

function quotation_is_date(string $value): bool
{
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

/** 「2026-10-19」→「2026年10月19日」 */
function quotation_date_label(string $date): string
{
    $d = new DateTime($date);
    return $d->format('Y') . '年' . (int) $d->format('n') . '月' . (int) $d->format('j') . '日';
}

/** 発行日の1か月後（翌月に同じ日がなければ翌月末。例：1/31 → 2/28） */
function quotation_default_valid_until(string $issueDate): string
{
    $d = new DateTime($issueDate);
    $day = (int) $d->format('j');
    $nextMonth = (new DateTime($d->format('Y-m-01')))->modify('+1 month');
    $lastDay = (int) $nextMonth->format('t');
    return $nextMonth->format('Y-m-') . sprintf('%02d', min($day, $lastDay));
}

/** 税抜金額・消費税（切り捨て）・税込金額 */
function quotation_calc_amounts(int $residentCount, int $unitPrice, int $taxRate = QUOTATION_TAX_RATE): array
{
    $subtotal = $residentCount * $unitPrice;
    $tax = intdiv($subtotal * $taxRate, 100);
    return ['subtotal_excl' => $subtotal, 'tax_amount' => $tax, 'total_incl' => $subtotal + $tax];
}

/** 見積書の宛先に選べる施設（有効な介護施設）。入居者数は facilities に無いため返さない */
function quotation_fetch_facilities(PDO $pdo): array
{
    return $pdo->query(
        "SELECT id, name, address FROM facilities
         WHERE facility_type = '介護施設' AND is_active = 1
         ORDER BY name"
    )->fetchAll();
}

function quotation_fetch(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT q.*, e.name AS created_by_name
         FROM quotations q LEFT JOIN employees e ON e.id = q.created_by
         WHERE q.id = :id'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function quotation_issuer(array $quotation): array
{
    return (json_decode((string) $quotation['issuer_snapshot'], true) ?: []) + QUOTATION_ISSUER;
}

/**
 * 入力値を検証して保存用の値にする。エラーがあれば ['errors' => [...]]。
 * $post は quotation_edit.php のフォーム値。
 */
function quotation_parse_input(PDO $pdo, array $post): array
{
    $errors = [];
    $facilityId = (int) ($post['facility_id'] ?? 0);
    if ($facilityId > 0) {
        $stmt = $pdo->prepare("SELECT id FROM facilities WHERE id = :id AND facility_type = '介護施設'");
        $stmt->execute([':id' => $facilityId]);
        if ($stmt->fetch() === false) {
            $errors[] = '選択した施設が見つかりません。';
        }
    }

    $recipientName = trim((string) ($post['recipient_name'] ?? ''));
    $honorific = (string) ($post['recipient_honorific'] ?? '');
    $address = trim((string) ($post['recipient_address'] ?? ''));
    $contact = trim((string) ($post['contact_person'] ?? ''));
    $residentRaw = trim((string) ($post['resident_count'] ?? ''));
    $priceRaw = trim((string) ($post['unit_price'] ?? ''));
    $issueDate = (string) ($post['issue_date'] ?? '');
    $validUntil = (string) ($post['valid_until'] ?? '');
    $note = trim((string) ($post['note'] ?? ''));

    if ($recipientName === '' || mb_strlen($recipientName) > 100) {
        $errors[] = '宛先（施設名・会社名）を100文字以内で入力してください。';
    }
    if (!in_array($honorific, QUOTATION_HONORIFICS, true)) {
        $errors[] = '敬称を選択してください。';
    }
    if (mb_strlen($address) > 255) {
        $errors[] = '住所は255文字以内で入力してください。';
    }
    if (mb_strlen($contact) > 100) {
        $errors[] = '担当者名は100文字以内で入力してください。';
    }
    if (!preg_match('/^\d{1,4}$/', $residentRaw) || (int) $residentRaw < 1) {
        $errors[] = '入居者数を1〜9999の整数で入力してください。';
    }
    if (!preg_match('/^\d{1,6}$/', $priceRaw)) {
        $errors[] = '単価を0〜999999の整数で入力してください。';
    }
    if (!quotation_is_date($issueDate)) {
        $errors[] = '発行日を正しく入力してください。';
    }
    if (!quotation_is_date($validUntil)) {
        $errors[] = '有効期限を正しく入力してください。';
    } elseif (quotation_is_date($issueDate) && $validUntil < $issueDate) {
        $errors[] = '有効期限は発行日以降の日付にしてください。';
    }
    if (mb_strlen($note) > 2000) {
        $errors[] = '備考は2000文字以内で入力してください。';
    }

    if ($errors !== []) {
        return ['errors' => $errors];
    }

    $residentCount = (int) $residentRaw;
    $unitPrice = (int) $priceRaw;
    return [
        'facility_id' => $facilityId > 0 ? $facilityId : null,
        'recipient_name' => $recipientName,
        'recipient_honorific' => $honorific,
        'recipient_address' => $address === '' ? null : $address,
        'contact_person' => $contact === '' ? null : $contact,
        'resident_count' => $residentCount,
        'unit_price' => $unitPrice,
        'tax_rate' => QUOTATION_TAX_RATE,
        'issue_date' => $issueDate,
        'valid_until' => $validUntil,
        'note' => $note === '' ? null : $note,
    ] + quotation_calc_amounts($residentCount, $unitPrice);
}

/** 見積番号を採番して保存し、新しいIDを返す（発行日ごとの連番 Q-YYYYMMDD-001） */
function quotation_insert(PDO $pdo, array $values, int $createdBy): int
{
    $prefix = 'Q-' . str_replace('-', '', $values['issue_date']) . '-';
    for ($attempt = 0; ; $attempt++) {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'SELECT quote_no FROM quotations WHERE issue_date = :d ORDER BY quote_no DESC LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([':d' => $values['issue_date']]);
            $last = $stmt->fetchColumn();
            $seq = $last === false ? 1 : (int) substr((string) $last, strlen($prefix)) + 1;

            $insert = $pdo->prepare(
                'INSERT INTO quotations
                   (quote_no, facility_id, recipient_name, recipient_honorific, recipient_address, contact_person,
                    resident_count, unit_price, subtotal_excl, tax_rate, tax_amount, total_incl,
                    issue_date, valid_until, note, issuer_snapshot, created_by)
                 VALUES
                   (:quote_no, :facility_id, :recipient_name, :recipient_honorific, :recipient_address, :contact_person,
                    :resident_count, :unit_price, :subtotal_excl, :tax_rate, :tax_amount, :total_incl,
                    :issue_date, :valid_until, :note, :issuer_snapshot, :created_by)'
            );
            $insert->execute([
                ':quote_no' => $prefix . sprintf('%03d', $seq),
                ':facility_id' => $values['facility_id'],
                ':recipient_name' => $values['recipient_name'],
                ':recipient_honorific' => $values['recipient_honorific'],
                ':recipient_address' => $values['recipient_address'],
                ':contact_person' => $values['contact_person'],
                ':resident_count' => $values['resident_count'],
                ':unit_price' => $values['unit_price'],
                ':subtotal_excl' => $values['subtotal_excl'],
                ':tax_rate' => $values['tax_rate'],
                ':tax_amount' => $values['tax_amount'],
                ':total_incl' => $values['total_incl'],
                ':issue_date' => $values['issue_date'],
                ':valid_until' => $values['valid_until'],
                ':note' => $values['note'],
                ':issuer_snapshot' => json_encode(QUOTATION_ISSUER, JSON_UNESCAPED_UNICODE),
                ':created_by' => $createdBy,
            ]);
            $id = (int) $pdo->lastInsertId();
            $pdo->commit();
            return $id;
        } catch (PDOException $e) {
            $pdo->rollBack();
            // 同時発行で番号が重複した場合だけ採番からやり直す
            if ($attempt < 3 && $e->getCode() === '23000' && strpos($e->getMessage(), 'uq_quote_no') !== false) {
                continue;
            }
            throw $e;
        }
    }
}

/**
 * 見積書本体のHTML（画面表示とPDFで共用。mPDFが解釈できるテーブル＋インラインCSSのみで組む）。
 */
function quotation_document_html(array $q): string
{
    $issuer = quotation_issuer($q);
    $cell = 'border:0.3mm solid #555; padding:2.2mm 3mm;';
    $head = $cell . ' background:#e9eef5; text-align:center;';
    ob_start();
    ?>
<div style="font-size:10pt; color:#111;">
    <div style="text-align:center; font-size:22pt; letter-spacing:6mm; padding-bottom:2mm; border-bottom:0.6mm double #333; margin-bottom:6mm;">御見積書</div>

    <table style="width:100%; border-collapse:collapse; margin-bottom:6mm;">
        <tr>
            <td style="width:58%; vertical-align:top; padding:0;">
                <div style="font-size:15pt; border-bottom:0.4mm solid #333; padding-bottom:1.5mm;"><?= quotation_esc($q['recipient_name']) ?>&nbsp;&nbsp;<?= quotation_esc($q['recipient_honorific']) ?></div>
                <?php if ((string) $q['recipient_address'] !== ''): ?>
                    <div style="margin-top:1.5mm;"><?= quotation_esc($q['recipient_address']) ?></div>
                <?php endif; ?>
                <?php if ((string) $q['contact_person'] !== ''): ?>
                    <div style="margin-top:1.5mm;">ご担当：<?= quotation_esc($q['contact_person']) ?> 様</div>
                <?php endif; ?>
                <div style="margin-top:6mm;">下記のとおりお見積り申し上げます。</div>
                <table style="border-collapse:collapse; margin-top:3mm; width:100%;">
                    <tr>
                        <td style="<?= $head ?> width:32%; font-size:11pt;">御見積金額</td>
                        <td style="<?= $cell ?> text-align:right; font-size:16pt;"><?= quotation_esc(quotation_yen((int) $q['total_incl'])) ?> <span style="font-size:9pt;">（税込・月額）</span></td>
                    </tr>
                </table>
            </td>
            <td style="width:42%; vertical-align:top; padding:0 0 0 6mm;">
                <table style="border-collapse:collapse; width:100%; margin-bottom:5mm;">
                    <tr><td style="<?= $head ?> width:40%;">見積番号</td><td style="<?= $cell ?>"><?= quotation_esc($q['quote_no']) ?></td></tr>
                    <tr><td style="<?= $head ?>">発行日</td><td style="<?= $cell ?>"><?= quotation_esc(quotation_date_label((string) $q['issue_date'])) ?></td></tr>
                    <tr><td style="<?= $head ?>">有効期限</td><td style="<?= $cell ?>"><?= quotation_esc(quotation_date_label((string) $q['valid_until'])) ?></td></tr>
                </table>
                <div style="font-size:12pt;"><?= quotation_esc($issuer['company_name']) ?></div>
                <div style="margin-top:1mm;">〒<?= quotation_esc($issuer['postal']) ?></div>
                <div><?= quotation_esc($issuer['address']) ?></div>
                <div>TEL <?= quotation_esc($issuer['tel']) ?></div>
                <div>登録番号 <?= quotation_esc($issuer['registration_no']) ?></div>
                <div style="margin-top:1mm;">担当：<?= quotation_esc($issuer['contact_title']) ?> <?= quotation_esc($issuer['contact_name']) ?></div>
            </td>
        </tr>
    </table>

    <table style="width:100%; border-collapse:collapse;">
        <tr>
            <td style="<?= $head ?> width:46%;">品名</td>
            <td style="<?= $head ?> width:14%;">入居者数</td>
            <td style="<?= $head ?> width:18%;">単価（税抜）</td>
            <td style="<?= $head ?> width:22%;">金額（税抜）</td>
        </tr>
        <tr>
            <td style="<?= $cell ?>"><?= quotation_esc(QUOTATION_ITEM_NAME) ?></td>
            <td style="<?= $cell ?> text-align:right;"><?= number_format((int) $q['resident_count']) ?> 名</td>
            <td style="<?= $cell ?> text-align:right;"><?= quotation_esc(quotation_yen((int) $q['unit_price'])) ?></td>
            <td style="<?= $cell ?> text-align:right;"><?= quotation_esc(quotation_yen((int) $q['subtotal_excl'])) ?></td>
        </tr>
        <tr>
            <td colspan="2" style="border:0; padding:0;"></td>
            <td style="<?= $head ?>">小計（税抜）</td>
            <td style="<?= $cell ?> text-align:right;"><?= quotation_esc(quotation_yen((int) $q['subtotal_excl'])) ?></td>
        </tr>
        <tr>
            <td colspan="2" style="border:0; padding:0;"></td>
            <td style="<?= $head ?>">消費税（<?= (int) $q['tax_rate'] ?>%）</td>
            <td style="<?= $cell ?> text-align:right;"><?= quotation_esc(quotation_yen((int) $q['tax_amount'])) ?></td>
        </tr>
        <tr>
            <td colspan="2" style="border:0; padding:0;"></td>
            <td style="<?= $head ?>">合計（税込）</td>
            <td style="<?= $cell ?> text-align:right; font-size:12pt;"><?= quotation_esc(quotation_yen((int) $q['total_incl'])) ?></td>
        </tr>
    </table>
    <div style="margin-top:2mm; font-size:8.5pt; color:#444;">※金額は1か月あたりの利用料です。消費税は1円未満を切り捨てています。</div>

    <div style="margin-top:7mm; border:0.3mm solid #555; padding:3mm; min-height:30mm;">
        <div style="font-size:9pt; color:#444; margin-bottom:1.5mm;">備考</div>
        <div><?= nl2br(quotation_esc($q['note'] ?? '')) ?></div>
    </div>
</div>
    <?php
    return (string) ob_get_clean();
}

/** 見積書PDF（A4縦）をダウンロードとして出力する（呼び出し元には戻らない想定） */
function quotation_output_pdf(array $q): void
{
    require_once __DIR__ . '/../vendor/autoload.php';

    $defaultConfigVars = (new \Mpdf\Config\ConfigVariables())->getDefaults();
    $fontDirs = $defaultConfigVars['fontDir'];
    $defaultFontVars = (new \Mpdf\Config\FontVariables())->getDefaults();
    $fontData = $defaultFontVars['fontdata'];

    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4',
        'margin_left' => 16,
        'margin_right' => 16,
        'margin_top' => 18,
        'margin_bottom' => 16,
        'margin_header' => 0,
        'margin_footer' => 0,
        'fontDir' => array_merge($fontDirs, [__DIR__ . '/fonts']),
        'fontdata' => $fontData + [
            'ipag' => ['R' => 'ipag.ttf'],
        ],
        'default_font' => 'ipag',
    ]);
    $mpdf->SetTitle('御見積書 ' . $q['quote_no']);
    $mpdf->WriteHTML(quotation_document_html($q));
    $mpdf->Output('見積書_' . $q['quote_no'] . '.pdf', \Mpdf\Output\Destination::DOWNLOAD);
    exit;
}
