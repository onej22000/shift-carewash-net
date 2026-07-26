<?php
// シフト購読用 iCalendar (.ics) 配信エンドポイント。
// ログイン不要（カレンダーアプリ側は認証できないため）で、トークン一致のみを認可手段とする。
// そのため詳細なエラー内容は返さず、不一致時は一律404とする（存在確認できないようにするため）。

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

function ics_escape_text(string $value): string
{
    $value = str_replace('\\', '\\\\', $value);
    $value = str_replace(',', '\\,', $value);
    $value = str_replace(';', '\\;', $value);
    $value = preg_replace('/\r\n|\r|\n/', '\\n', $value);

    return $value;
}

// RFC5545の行折り返し（75オクテット、継続行は先頭に半角スペース）。
// マルチバイトUTF-8文字の途中で分割しないよう、継続バイト(10xxxxxx)の位置は避ける。
function ics_fold_line(string $line): string
{
    $limit = 75;
    $totalLen = strlen($line);
    if ($totalLen <= $limit) {
        return $line;
    }

    $chunks = [];
    $pos = 0;
    $isFirst = true;
    while ($pos < $totalLen) {
        $chunkLimit = $isFirst ? $limit : ($limit - 1);
        $end = min($pos + $chunkLimit, $totalLen);
        while ($end < $totalLen && $end > $pos && (ord($line[$end]) & 0xC0) === 0x80) {
            $end--;
        }
        $chunks[] = substr($line, $pos, $end - $pos);
        $pos = $end;
        $isFirst = false;
    }

    return implode("\r\n ", $chunks);
}

function ics_property(string $name, string $value): string
{
    return ics_fold_line($name . ':' . $value) . "\r\n";
}

// shiftsテーブルには施設との直接の紐付けが無いため、備考(note)に施設名が含まれていれば
// 施設マスタと突き合わせて拾う（「分かれば」の範囲でのベストエフォート）。
// 名前が長い施設から先に照合し、短い施設名が別施設名の部分文字列になっているケースでの誤マッチを避ける。
function find_facility_in_note(?string $note, array $facilitiesSortedByNameLengthDesc): ?array
{
    if ($note === null || $note === '') {
        return null;
    }

    foreach ($facilitiesSortedByNameLengthDesc as $facility) {
        if (mb_strpos($note, $facility['name']) !== false) {
            return $facility;
        }
    }

    return null;
}

$token = (string) ($_GET['token'] ?? '');

if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
    http_response_code(404);
    exit;
}

$pdo = getPdo();

$employeeStmt = $pdo->prepare(
    "SELECT id, name FROM employees WHERE calendar_token = :token AND status = 'active' LIMIT 1"
);
$employeeStmt->execute([':token' => $token]);
$employee = $employeeStmt->fetch();

if ($employee === false) {
    http_response_code(404);
    exit;
}

$tz = new DateTimeZone('Asia/Tokyo');
$utc = new DateTimeZone('UTC');
$today = new DateTime('today', $tz);
$rangeStart = (clone $today)->modify('-1 month')->format('Y-m-d');
$rangeEnd = (clone $today)->modify('+3 months')->format('Y-m-d');

$shiftsStmt = $pdo->prepare(
    'SELECT id, work_date, start_time, end_time, note, categories, updated_at
     FROM shifts
     WHERE employee_id = :employee_id AND work_date BETWEEN :start_date AND :end_date
     ORDER BY work_date, start_time'
);
$shiftsStmt->execute([
    ':employee_id' => $employee['id'],
    ':start_date' => $rangeStart,
    ':end_date' => $rangeEnd,
]);
$shifts = $shiftsStmt->fetchAll();

$facilitiesStmt = $pdo->query(
    "SELECT name, address FROM facilities
     WHERE is_active = 1 AND address IS NOT NULL AND address <> ''
     ORDER BY CHAR_LENGTH(name) DESC"
);
$facilities = $facilitiesStmt->fetchAll();

$nowUtc = (new DateTime('now', $utc))->format('Ymd\THis\Z');

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="shift.ics"');
// トークンURL単位で内容が異なるため、経路上のキャッシュ（サーバのエッジキャッシュ等）に
// 保持・共有されないようにする。
header('Cache-Control: private, no-store, must-revalidate');
header('Pragma: no-cache');

echo ics_property('BEGIN', 'VCALENDAR');
echo ics_property('VERSION', '2.0');
echo ics_property('PRODID', '-//shift.carewash.net//Shift Calendar//JA');
echo ics_property('CALSCALE', 'GREGORIAN');
echo ics_property('METHOD', 'PUBLISH');
echo ics_property('X-WR-CALNAME', ics_escape_text($employee['name'] . 'さんのシフト'));
echo ics_property('X-WR-TIMEZONE', 'Asia/Tokyo');
echo ics_property('X-PUBLISHED-TTL', 'PT4H');
echo ics_property('REFRESH-INTERVAL;VALUE=DURATION', 'PT4H');

foreach ($shifts as $shift) {
    $categories = array_values(array_intersect(SHIFT_CATEGORIES, categories_from_value($shift['categories'])));
    $categoryLabel = $categories === [] ? 'シフト' : implode('・', $categories);

    $facilityMatch = find_facility_in_note($shift['note'], $facilities);
    $summary = $categoryLabel . ($facilityMatch !== null ? ' ' . $facilityMatch['name'] : '');

    $startLocal = new DateTime($shift['work_date'] . ' ' . $shift['start_time'], $tz);
    $endLocal = new DateTime($shift['work_date'] . ' ' . $shift['end_time'], $tz);
    if ($endLocal <= $startLocal) {
        $endLocal->modify('+1 day'); // 日をまたぐシフト
    }

    $dtStart = (clone $startLocal)->setTimezone($utc)->format('Ymd\THis\Z');
    $dtEnd = (clone $endLocal)->setTimezone($utc)->format('Ymd\THis\Z');
    $lastModified = (new DateTime($shift['updated_at'], $tz))->setTimezone($utc)->format('Ymd\THis\Z');

    echo ics_property('BEGIN', 'VEVENT');
    // シフトIDを基にした固定UID。同じシフトなら常に同じUIDになるため、
    // 内容が変わってもカレンダーアプリ側で「更新」として扱われ、重複イベントにならない。
    echo ics_property('UID', 'shift-' . $shift['id'] . '@shift.carewash.net');
    echo ics_property('DTSTAMP', $nowUtc);
    echo ics_property('DTSTART', $dtStart);
    echo ics_property('DTEND', $dtEnd);
    echo ics_property('LAST-MODIFIED', $lastModified);
    echo ics_property('SUMMARY', ics_escape_text($summary));
    if ($facilityMatch !== null) {
        echo ics_property('LOCATION', ics_escape_text($facilityMatch['address']));
    }
    echo ics_property('END', 'VEVENT');
}

echo ics_property('END', 'VCALENDAR');
