<?php
/**
 * 営業先一覧（admin/leads.php・lead_view.php）の共通処理。テーブルは leads・lead_notes（admin/sql/leads.sql）。
 *
 * 見積書の発行時に quotation_insert() から lead_link_quotation() が同じトランザクション内で呼ばれ、
 * 宛先に対応する営業先を作成または再利用して quotations.lead_id をセットする。
 *  - 施設を選んだ宛先：同じ facility_id の営業先 → facility_id 未設定で施設名・住所（正規化後）が同じ営業先 → 新規
 *  - 手入力の宛先：施設名・住所（正規化後）が同じ営業先 → 新規
 *  - 新規はステータス「提案済み」。既存の営業先のステータスは変えない（成約・失注も巻き戻さない）
 * lead_notes は時系列：memo（手入力、修正・削除可）、status・quotation（自動記録、修正・削除不可）。
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

const LEAD_STATUSES = ['提案済み', '検討中', '成約', '失注'];
const LEAD_NOTE_KIND_LABELS = ['memo' => 'メモ', 'status' => 'ステータス変更', 'quotation' => '見積書発行'];

function lead_esc($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** 重複判定用の正規化（NFKCで全角英数・半角カナを統一し、空白をすべて除く） */
function lead_normalize_key(?string $value): string
{
    $value = Normalizer::normalize((string) $value, Normalizer::FORM_KC);
    return (string) preg_replace('/\s+/u', '', (string) $value);
}

function lead_fetch(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT l.*, f.name AS facility_name
         FROM leads l LEFT JOIN facilities f ON f.id = l.facility_id
         WHERE l.id = :id'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function lead_add_note(PDO $pdo, int $leadId, string $kind, string $content, int $createdBy): void
{
    $pdo->prepare('INSERT INTO lead_notes (lead_id, kind, content, created_by) VALUES (:lead_id, :kind, :content, :created_by)')
        ->execute([':lead_id' => $leadId, ':kind' => $kind, ':content' => $content, ':created_by' => $createdBy]);
    lead_touch($pdo, $leadId);
}

/** 一覧の「最終更新」を進める（メモ追加など leads 自体の列が変わらない操作用） */
function lead_touch(PDO $pdo, int $leadId): void
{
    $pdo->prepare('UPDATE leads SET updated_at = NOW() WHERE id = :id')->execute([':id' => $leadId]);
}

/** 宛先に対応する既存の営業先を探す（行ロック付き。呼び出し側でトランザクションを開始しておくこと） */
function lead_find_for_recipient(PDO $pdo, ?int $facilityId, string $nameKey, string $addressKey): ?array
{
    if ($facilityId !== null) {
        $stmt = $pdo->prepare('SELECT * FROM leads WHERE facility_id = :fid ORDER BY id LIMIT 1 FOR UPDATE');
        $stmt->execute([':fid' => $facilityId]);
        $row = $stmt->fetch();
        if ($row !== false) {
            return $row;
        }
        $stmt = $pdo->prepare(
            'SELECT * FROM leads WHERE facility_id IS NULL AND name_key = :n AND address_key = :a ORDER BY id LIMIT 1 FOR UPDATE'
        );
    } else {
        $stmt = $pdo->prepare('SELECT * FROM leads WHERE name_key = :n AND address_key = :a ORDER BY id LIMIT 1 FOR UPDATE');
    }
    $stmt->execute([':n' => $nameKey, ':a' => $addressKey]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/**
 * 発行した見積書を営業先に紐づける（quotation_insert() のトランザクション内で呼ぶ）。
 * $leadId を指定した場合（営業先の詳細画面から作成した見積書）は、その営業先に紐づける。
 * 戻り値は紐づけた営業先のID。
 */
function lead_link_quotation(PDO $pdo, int $quotationId, string $quoteNo, array $values, int $createdBy, ?int $leadId = null): int
{
    $lead = null;
    if ($leadId !== null) {
        $stmt = $pdo->prepare('SELECT * FROM leads WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $leadId]);
        $lead = $stmt->fetch() ?: null;
    }

    $nameKey = lead_normalize_key($values['recipient_name']);
    $addressKey = lead_normalize_key($values['recipient_address']);
    if ($lead === null) {
        $lead = lead_find_for_recipient($pdo, $values['facility_id'], $nameKey, $addressKey);
    }

    if ($lead === null) {
        $pdo->prepare(
            "INSERT INTO leads (name, address, contact_person, name_key, address_key, facility_id, status, created_by)
             VALUES (:name, :address, :contact, :name_key, :address_key, :facility_id, '提案済み', :created_by)"
        )->execute([
            ':name' => $values['recipient_name'],
            ':address' => $values['recipient_address'],
            ':contact' => $values['contact_person'],
            ':name_key' => $nameKey,
            ':address_key' => $addressKey,
            ':facility_id' => $values['facility_id'],
            ':created_by' => $createdBy,
        ]);
        $id = (int) $pdo->lastInsertId();
    } else {
        // ステータスは変えない。空欄の住所・担当者・施設の紐付けだけ見積書の値で補う
        // （重複判定キー name_key/address_key は作成時のまま。変えると以後の同じ宛先が別の営業先になる）
        $id = (int) $lead['id'];
        $pdo->prepare(
            'UPDATE leads SET
                address = COALESCE(NULLIF(address, \'\'), :address),
                contact_person = COALESCE(NULLIF(contact_person, \'\'), :contact),
                facility_id = COALESCE(facility_id, :facility_id)
             WHERE id = :id'
        )->execute([
            ':address' => $values['recipient_address'],
            ':contact' => $values['contact_person'],
            ':facility_id' => $values['facility_id'],
            ':id' => $id,
        ]);
    }

    $pdo->prepare('UPDATE quotations SET lead_id = :lead_id WHERE id = :id')->execute([':lead_id' => $id, ':id' => $quotationId]);
    lead_add_note($pdo, $id, 'quotation', $quoteNo . ' を発行（税込 ¥' . number_format((int) $values['total_incl']) . '・月額）', $createdBy);
    return $id;
}
