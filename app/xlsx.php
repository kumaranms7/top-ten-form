<?php
/** Minimal .xlsx writer (one sheet, strings and numbers). Needs only PHP's zip extension. */
function xlsx_col(int $i): string { $s = ''; for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s; return $s; }

/** @param array<int,array<int,mixed>> $rows first row = header. Returns the file contents. */
function xlsx_build(array $rows, string $sheetName = 'Entries'): string {
    $xmlRows = '';
    foreach ($rows as $r => $cells) {
        $xmlRows .= '<row r="' . ($r + 1) . '">';
        foreach (array_values($cells) as $c => $v) {
            $ref = xlsx_col($c) . ($r + 1);
            $style = $r === 0 ? ' s="1"' : '';
            if (is_int($v) || is_float($v)) $xmlRows .= "<c r=\"$ref\"$style><v>$v</v></c>";
            else {
                $t = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)$v); // strip characters XML cannot hold
                $xmlRows .= "<c r=\"$ref\" t=\"inlineStr\"$style><is><t xml:space=\"preserve\">" . htmlspecialchars($t, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></is></c>';
            }
        }
        $xmlRows .= '</row>';
    }
    $ncols = max(1, count($rows[0] ?? [1]));
    $cols = '<cols><col min="1" max="' . $ncols . '" width="22" customWidth="1"/></cols>';
    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . htmlspecialchars(mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', ' ', $sheetName), 0, 31), ENT_XML1) . '" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
        'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF6366F1"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
        'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>' . $cols . '<sheetData>' . $xmlRows . '</sheetData></worksheet>',
    ];
    $tmp = tempnam(sys_get_temp_dir(), 'xl');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Could not create the Excel file');
    foreach ($files as $name => $xml) $zip->addFromString($name, $xml);
    $zip->close();
    $data = file_get_contents($tmp); @unlink($tmp);
    return $data;
}
