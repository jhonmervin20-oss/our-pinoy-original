<?php
/**
 * employee_management/includes/xlsx_reader.php
 *
 * Minimal read-only .xlsx parser -- an .xlsx file is a zip archive of XML
 * parts, so this uses PHP's built-in ZipArchive + SimpleXML rather than
 * pulling in a full library (PhpSpreadsheet requires the gd/zip PHP
 * extensions and a Composer install this environment didn't have; zip
 * alone -- already needed here -- was enabled instead). Only reads the
 * first worksheet, which is all the attendance import needs.
 *
 * Excel stores dates/times as numeric "serial" values (days since
 * 1899-12-30, with time-of-day as the fractional part) whenever a cell is
 * genuinely date/time-formatted, but stores plain typed text as a string
 * otherwise -- callers get the raw cell string here and are responsible
 * for deciding, per column, whether a numeric-looking value should be
 * reinterpreted via excelSerialToDate()/excelSerialToTime().
 */

/** Converts a column letter (A, B, ..., Z, AA, AB, ...) to a 0-based index. */
function xlsxColumnLetterToIndex(string $letters): int
{
    $index = 0;
    foreach (str_split(strtoupper($letters)) as $char) {
        $index = $index * 26 + (ord($char) - ord('A') + 1);
    }
    return $index - 1;
}

/** Excel's day-serial (e.g. 46225) -> 'Y-m-d'. Epoch is 1899-12-30 (accounts for Excel's fictitious 1900 leap day). */
function excelSerialToDate(float $serial): string
{
    $epoch = new DateTime('1899-12-30');
    $epoch->modify('+' . (int)floor($serial) . ' days');
    return $epoch->format('Y-m-d');
}

/** Excel's fraction-of-a-day (e.g. 0.354166 for 08:30) -> 'H:i'. */
function excelSerialToTime(float $serial): string
{
    $fraction = $serial - floor($serial);
    $totalMinutes = (int)round($fraction * 24 * 60);
    $hours = intdiv($totalMinutes, 60) % 24;
    $minutes = $totalMinutes % 60;
    return sprintf('%02d:%02d', $hours, $minutes);
}

/**
 * Parses the first worksheet of an .xlsx file into an array of rows, each
 * row a 0-indexed array of cell string values (blank cells become '').
 * Rows are padded/truncated to a consistent width isn't done here -- each
 * row is only as wide as its last non-empty cell, matching how XLSX
 * sparsely encodes cells; callers should use ($row[$i] ?? '') when reading
 * a specific column.
 *
 * @throws RuntimeException if the file isn't a readable, well-formed .xlsx
 */
function parseXlsxFile(string $filePath): array
{
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        throw new RuntimeException('Could not open this file as an Excel (.xlsx) workbook.');
    }

    $sharedStrings = [];
    $sharedStringsXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedStringsXml !== false) {
        $sst = @simplexml_load_string($sharedStringsXml);
        if ($sst !== false) {
            foreach ($sst->si as $si) {
                if (isset($si->t)) {
                    $sharedStrings[] = (string)$si->t;
                } else {
                    // Rich text runs (<r><t>...</t></r>) -- concatenate all runs' text.
                    $text = '';
                    foreach ($si->r as $run) {
                        $text .= (string)$run->t;
                    }
                    $sharedStrings[] = $text;
                }
            }
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();

    if ($sheetXml === false) {
        throw new RuntimeException('This Excel file has no readable worksheet.');
    }

    $sheet = @simplexml_load_string($sheetXml);
    if ($sheet === false) {
        throw new RuntimeException('This Excel file\'s worksheet is not valid XML.');
    }

    $rows = [];
    foreach ($sheet->sheetData->row as $rowXml) {
        $row = [];
        foreach ($rowXml->c as $cellXml) {
            $ref = (string)$cellXml['r'];
            preg_match('/^([A-Z]+)/', $ref, $m);
            $colIndex = $m ? xlsxColumnLetterToIndex($m[1]) : count($row);

            $type = (string)$cellXml['t'];
            if ($type === 's') {
                $idx = (int)$cellXml->v;
                $value = $sharedStrings[$idx] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = (string)$cellXml->is->t;
            } else {
                $value = (string)$cellXml->v;
            }

            $row[$colIndex] = $value;
        }

        if (!empty($row)) {
            $maxIndex = max(array_keys($row));
            $normalized = [];
            for ($i = 0; $i <= $maxIndex; $i++) {
                $normalized[$i] = $row[$i] ?? '';
            }
            $rows[] = $normalized;
        } else {
            $rows[] = [];
        }
    }

    return $rows;
}
