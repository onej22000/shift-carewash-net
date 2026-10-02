<?php
/**
 * Excel ファイル（.xlsx / .xls）の最初のワークシートを、セル値の2次元配列として読む最小限のリーダー。
 * 国税庁の源泉徴収税額表（月額表）の取込（admin/payroll_settings.php）専用。
 * 書式・数式は扱わない（数式セルはキャッシュされた計算結果を読む）。外部ライブラリは使わない。
 *
 * 戻り値: list<list<string|int|float|null>>（0始まりの行 => 0始まりの列 => 値）
 */

function spreadsheet_read_first_sheet(string $path): array
{
    $head = (string) file_get_contents($path, false, null, 0, 8);
    if (strncmp($head, "PK\x03\x04", 4) === 0) {
        return xlsx_read_first_sheet($path);
    }
    if ($head === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
        return xls_read_first_sheet($path);
    }
    throw new RuntimeException('Excelファイル（.xlsx または .xls）ではありません。');
}

// ---------------------------------------------------------------------------
// .xlsx（Office Open XML）
// ---------------------------------------------------------------------------

function xlsx_read_first_sheet(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('xlsxファイルを開けません。');
    }

    $sharedStrings = [];
    $sst = $zip->getFromName('xl/sharedStrings.xml');
    if ($sst !== false) {
        $xml = simplexml_load_string($sst);
        foreach ($xml->si as $si) {
            if (isset($si->t)) {
                $sharedStrings[] = (string) $si->t;
            } else {
                $text = '';
                foreach ($si->r as $run) {
                    $text .= (string) $run->t;
                }
                $sharedStrings[] = $text;
            }
        }
    }

    // workbook.xml の最初の sheet → workbook.xml.rels でファイル名を解決
    $sheetPath = 'xl/worksheets/sheet1.xml';
    $workbook = $zip->getFromName('xl/workbook.xml');
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($workbook !== false && $rels !== false) {
        $wb = simplexml_load_string($workbook);
        $wb->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $sheets = $wb->xpath('//m:sheets/m:sheet');
        if (!empty($sheets)) {
            $rid = (string) $sheets[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $relXml = simplexml_load_string($rels);
            foreach ($relXml->Relationship as $rel) {
                if ((string) $rel['Id'] === $rid) {
                    $target = ltrim((string) $rel['Target'], '/');
                    $sheetPath = strpos($target, 'xl/') === 0 ? $target : 'xl/' . $target;
                }
            }
        }
    }

    $sheetXml = $zip->getFromName($sheetPath);
    $zip->close();
    if ($sheetXml === false) {
        throw new RuntimeException('ワークシートが見つかりません。');
    }

    $rows = [];
    $sheet = simplexml_load_string($sheetXml);
    foreach ($sheet->sheetData->row as $row) {
        $rowIndex = (int) $row['r'] - 1;
        foreach ($row->c as $cell) {
            $ref = (string) $cell['r'];
            $col = xlsx_column_index(preg_replace('/\d+/', '', $ref));
            $type = (string) $cell['t'];
            if ($type === 's') {
                $value = $sharedStrings[(int) $cell->v] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = (string) $cell->is->t;
            } elseif ($type === 'str' || $type === 'b' || $type === 'e') {
                $value = (string) $cell->v;
            } elseif (isset($cell->v)) {
                $raw = (string) $cell->v;
                $value = preg_match('/^-?\d+$/', $raw) ? (int) $raw : (float) $raw;
            } else {
                $value = null;
            }
            $rows[$rowIndex][$col] = $value;
        }
    }

    return spreadsheet_normalize_rows($rows);
}

function xlsx_column_index(string $letters): int
{
    $index = 0;
    foreach (str_split(strtoupper($letters)) as $ch) {
        $index = $index * 26 + (ord($ch) - 64);
    }
    return $index - 1;
}

// ---------------------------------------------------------------------------
// .xls（BIFF8 / OLE2 複合ドキュメント）
// ---------------------------------------------------------------------------

function xls_read_first_sheet(string $path): array
{
    $workbook = ole2_read_stream((string) file_get_contents($path), ['Workbook', 'Book']);
    $records = biff_split_records($workbook);

    $sst = [];
    $firstSheetOffset = null;
    foreach ($records as [$type, $data, $offset]) {
        if ($type === 0x0085 && $firstSheetOffset === null) { // BOUNDSHEET
            $firstSheetOffset = unpack('V', substr($data, 0, 4))[1];
        }
    }
    // SST は CONTINUE レコードをまたいで文字列が分割されるため、レコード境界ごと読む
    foreach ($records as $i => [$type, $data]) {
        if ($type === 0x00FC) {
            $chunks = [$data];
            for ($j = $i + 1; isset($records[$j]) && $records[$j][0] === 0x003C; $j++) {
                $chunks[] = $records[$j][1];
            }
            $sst = biff_parse_sst($chunks);
            break;
        }
    }
    if ($firstSheetOffset === null) {
        throw new RuntimeException('ワークシートが見つかりません。');
    }

    $rows = [];
    $inSheet = false;
    $pendingFormulaCell = null;
    foreach ($records as [$type, $data, $offset]) {
        if (!$inSheet) {
            if ($offset === $firstSheetOffset && $type === 0x0809) {
                $inSheet = true;
            }
            continue;
        }
        if ($type === 0x000A) { // EOF（最初のシートの終わり）
            break;
        }
        switch ($type) {
            case 0x00FD: // LABELSST
                $u = unpack('vrow/vcol/vxf/Visst', $data);
                $rows[$u['row']][$u['col']] = $sst[$u['isst']] ?? '';
                break;
            case 0x0203: // NUMBER
                $u = unpack('vrow/vcol/vxf', $data);
                $rows[$u['row']][$u['col']] = biff_number(unpack('e', substr($data, 6, 8))[1]);
                break;
            case 0x027E: // RK
                $u = unpack('vrow/vcol/vxf/Vrk', $data);
                $rows[$u['row']][$u['col']] = biff_number(biff_decode_rk($u['rk']));
                break;
            case 0x00BD: // MULRK
                $u = unpack('vrow/vcol', $data);
                $count = intdiv(strlen($data) - 6, 6);
                for ($k = 0; $k < $count; $k++) {
                    $rk = unpack('V', substr($data, 4 + $k * 6 + 2, 4))[1];
                    $rows[$u['row']][$u['col'] + $k] = biff_number(biff_decode_rk($rk));
                }
                break;
            case 0x0204: // LABEL（BIFF8 では稀）
                $u = unpack('vrow/vcol/vxf/vlen', $data);
                $flags = ord($data[8]);
                $rows[$u['row']][$u['col']] = biff_read_string_body(substr($data, 9), $u['len'], ($flags & 0x01) === 1);
                break;
            case 0x0006: // FORMULA（キャッシュされた結果）
                $u = unpack('vrow/vcol/vxf', $data);
                $result = substr($data, 6, 8);
                if (substr($result, 6, 2) === "\xFF\xFF") {
                    if (ord($result[0]) === 0) { // 文字列結果は直後の STRING レコード
                        $pendingFormulaCell = [$u['row'], $u['col']];
                    } else {
                        $rows[$u['row']][$u['col']] = null;
                    }
                } else {
                    $rows[$u['row']][$u['col']] = biff_number(unpack('e', $result)[1]);
                }
                break;
            case 0x0207: // STRING（数式の文字列結果）
                if ($pendingFormulaCell !== null) {
                    $len = unpack('v', substr($data, 0, 2))[1];
                    $flags = ord($data[2]);
                    $rows[$pendingFormulaCell[0]][$pendingFormulaCell[1]] = biff_read_string_body(substr($data, 3), $len, ($flags & 0x01) === 1);
                    $pendingFormulaCell = null;
                }
                break;
        }
    }

    return spreadsheet_normalize_rows($rows);
}

/** 整数値の数値セルは int にする（税額表の金額は整数） */
function biff_number(float $value)
{
    return floor($value) === $value && abs($value) < PHP_INT_MAX ? (int) $value : $value;
}

function biff_decode_rk(int $rk): float
{
    if ($rk & 0x02) {
        $value = (float) ($rk >> 2);
        if ($rk & 0x80000000) { // 符号付き30bit整数
            $value = (float) (($rk >> 2) - 0x40000000);
        }
    } else {
        $value = unpack('e', pack('V2', 0, $rk & 0xFFFFFFFC))[1];
    }
    return ($rk & 0x01) ? $value / 100 : $value;
}

/** BIFF レコード列 [type, data, offset] に分割 */
function biff_split_records(string $stream): array
{
    $records = [];
    $pos = 0;
    $len = strlen($stream);
    while ($pos + 4 <= $len) {
        $h = unpack('vtype/vsize', substr($stream, $pos, 4));
        $records[] = [$h['type'], (string) substr($stream, $pos + 4, $h['size']), $pos];
        $pos += 4 + $h['size'];
    }
    return $records;
}

/** 圧縮（1バイト）／非圧縮（UTF-16LE）の文字列本体を UTF-8 で返す */
function biff_read_string_body(string $bytes, int $charCount, bool $utf16): string
{
    if ($utf16) {
        return mb_convert_encoding(substr($bytes, 0, $charCount * 2), 'UTF-8', 'UTF-16LE');
    }
    return mb_convert_encoding(substr($bytes, 0, $charCount), 'UTF-8', 'ISO-8859-1');
}

/**
 * SST（共有文字列表）を解析する。$chunks は SST レコードと後続の CONTINUE レコードの本体。
 * 文字列がレコード境界で分割される場合、CONTINUE の先頭1バイトが圧縮フラグになる。
 */
function biff_parse_sst(array $chunks): array
{
    $strings = [];
    $chunkIndex = 0;
    $data = $chunks[0];
    $pos = 8; // cstTotal, cstUnique
    $unique = unpack('V', substr($data, 4, 4))[1];

    $nextChunk = static function () use (&$chunkIndex, &$data, &$pos, $chunks): bool {
        $chunkIndex++;
        if (!isset($chunks[$chunkIndex])) {
            return false;
        }
        $data = $chunks[$chunkIndex];
        $pos = 0;
        return true;
    };

    for ($n = 0; $n < $unique; $n++) {
        if ($pos >= strlen($data) && !$nextChunk()) {
            break;
        }
        $charCount = unpack('v', substr($data, $pos, 2))[1];
        $flags = ord($data[$pos + 2]);
        $pos += 3;
        $richRuns = 0;
        $extSize = 0;
        if ($flags & 0x08) {
            $richRuns = unpack('v', substr($data, $pos, 2))[1];
            $pos += 2;
        }
        if ($flags & 0x04) {
            $extSize = unpack('V', substr($data, $pos, 4))[1];
            $pos += 4;
        }
        $utf16 = ($flags & 0x01) === 1;
        $text = '';
        $remaining = $charCount;
        while ($remaining > 0) {
            $bytesPerChar = $utf16 ? 2 : 1;
            $available = intdiv(strlen($data) - $pos, $bytesPerChar);
            $take = min($remaining, $available);
            $text .= biff_read_string_body(substr($data, $pos, $take * $bytesPerChar), $take, $utf16);
            $pos += $take * $bytesPerChar;
            $remaining -= $take;
            if ($remaining > 0) {
                if (!$nextChunk()) {
                    break;
                }
                $utf16 = (ord($data[0]) & 0x01) === 1; // 続きの圧縮フラグ
                $pos = 1;
            }
        }
        // リッチテキスト書式（4バイト×runs）と拡張データを読み飛ばす（CONTINUE をまたぐ場合あり）
        $skip = $richRuns * 4 + $extSize;
        while ($skip > 0) {
            $available = strlen($data) - $pos;
            if ($skip <= $available) {
                $pos += $skip;
                $skip = 0;
            } else {
                $skip -= $available;
                if (!$nextChunk()) {
                    break;
                }
            }
        }
        $strings[] = $text;
    }

    return $strings;
}

/**
 * OLE2 複合ドキュメントから指定名のストリームを取り出す（ミニストリームにも対応）。
 *
 * @param list<string> $names 候補のストリーム名（最初に見つかったもの）
 */
function ole2_read_stream(string $file, array $names): string
{
    $h = unpack('vminor/vmajor/vorder/vsectorShift/vminiShift', substr($file, 0x18, 10));
    $sectorSize = 1 << $h['sectorShift'];
    $miniSectorSize = 1 << $h['miniShift'];
    $numFatSectors = unpack('V', substr($file, 0x2C, 4))[1];
    $dirStart = unpack('V', substr($file, 0x30, 4))[1];
    $miniCutoff = unpack('V', substr($file, 0x38, 4))[1];
    $miniFatStart = unpack('V', substr($file, 0x3C, 4))[1];
    $difatStart = unpack('V', substr($file, 0x44, 4))[1];

    $sector = static fn (int $id): string => (string) substr($file, ($id + 1) * $sectorSize, $sectorSize);

    // DIFAT → FAT セクタ一覧
    $fatSectors = [];
    for ($i = 0; $i < 109 && count($fatSectors) < $numFatSectors; $i++) {
        $fatSectors[] = unpack('V', substr($file, 0x4C + $i * 4, 4))[1];
    }
    $next = $difatStart;
    while ($next < 0xFFFFFFFA && count($fatSectors) < $numFatSectors) {
        $block = $sector($next);
        $perBlock = intdiv($sectorSize, 4) - 1;
        for ($i = 0; $i < $perBlock && count($fatSectors) < $numFatSectors; $i++) {
            $fatSectors[] = unpack('V', substr($block, $i * 4, 4))[1];
        }
        $next = unpack('V', substr($block, $perBlock * 4, 4))[1];
    }
    $fat = [];
    foreach ($fatSectors as $fs) {
        $fat = array_merge($fat, array_values(unpack('V*', $sector($fs))));
    }

    $chain = static function (int $start) use ($fat, $sector): string {
        $out = '';
        $guard = 0;
        for ($id = $start; $id < 0xFFFFFFFA && $guard < 1000000; $id = $fat[$id] ?? 0xFFFFFFFE, $guard++) {
            $out .= $sector($id);
        }
        return $out;
    };

    // ディレクトリ
    $dir = $chain($dirStart);
    $entries = [];
    for ($off = 0; $off + 128 <= strlen($dir); $off += 128) {
        $nameLen = unpack('v', substr($dir, $off + 64, 2))[1];
        $name = $nameLen > 2 ? mb_convert_encoding(substr($dir, $off, $nameLen - 2), 'UTF-8', 'UTF-16LE') : '';
        $entries[] = [
            'name' => $name,
            'type' => ord($dir[$off + 66]),
            'start' => unpack('V', substr($dir, $off + 116, 4))[1],
            'size' => unpack('V', substr($dir, $off + 120, 4))[1],
        ];
    }

    $root = $entries[0];
    foreach ($names as $wanted) {
        foreach ($entries as $entry) {
            if ($entry['type'] !== 2 || $entry['name'] !== $wanted) {
                continue;
            }
            if ($entry['size'] >= $miniCutoff) {
                return substr($chain($entry['start']), 0, $entry['size']);
            }
            // ミニストリーム
            $miniStream = $chain($root['start']);
            $miniFat = $miniFatStart < 0xFFFFFFFA ? array_values(unpack('V*', $chain($miniFatStart))) : [];
            $out = '';
            $guard = 0;
            for ($id = $entry['start']; $id < 0xFFFFFFFA && $guard < 1000000; $id = $miniFat[$id] ?? 0xFFFFFFFE, $guard++) {
                $out .= substr($miniStream, $id * $miniSectorSize, $miniSectorSize);
            }
            return substr($out, 0, $entry['size']);
        }
    }
    throw new RuntimeException('Workbook ストリームが見つかりません（.xls ファイルが壊れている可能性があります）。');
}

/** 疎な [row][col] を、0行目から最大行まで・各行0列から最大列までの密な配列にする */
function spreadsheet_normalize_rows(array $rows): array
{
    if (empty($rows)) {
        return [];
    }
    $maxRow = max(array_keys($rows));
    $maxCol = 0;
    foreach ($rows as $cols) {
        $maxCol = max($maxCol, max(array_keys($cols)));
    }
    $out = [];
    for ($r = 0; $r <= $maxRow; $r++) {
        $line = [];
        for ($c = 0; $c <= $maxCol; $c++) {
            $line[] = $rows[$r][$c] ?? null;
        }
        $out[] = $line;
    }
    return $out;
}
