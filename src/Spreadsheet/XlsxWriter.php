<?php
require_once __DIR__ . '/../Http/Http.php';

/**
 * Small .xlsx writer (no Composer). Produces a real Excel 2007+ workbook with styled headers,
 * column widths, frozen header rows, filters and optional title/notes rows.
 *
 *   $x = new XlsxWriter();
 *   $s = $x->addSheet('Students', [['LRN', 18], ['Name', 30]]);   // header label + column width
 *   $s->title('Student list');                                     // optional big title row(s) above the header
 *   $s->row(['114499220002', 'ACEBRON, LAMARCUS']);
 *   $s->row([XlsxWriter::date('2017-09-28'), XlsxWriter::int(9)]);
 *   $x->send('students.xlsx');   // or $x->save($path)
 *
 * Text is always written as text (an LRN like 012345678901 keeps its leading zero). PHP's ZipArchive
 * extension is required (enabled by default in XAMPP).
 */
final class XlsxWriter
{
    /** @var XlsxSheet[] */
    private array $sheets = [];

    public function addSheet(string $name, array $columns): XlsxSheet
    {
        $name = trim(preg_replace('/[\[\]:*?\/\\\\]/', ' ', $name) ?? '');
        $name = mb_substr($name === '' ? 'Sheet' . (count($this->sheets) + 1) : $name, 0, 31);
        $base = $name; $n = 2;
        while (in_array(mb_strtolower($name), array_map(static fn($s) => mb_strtolower($s->name), $this->sheets), true)) {
            $name = mb_substr($base, 0, 28) . ' ' . $n++;
        }
        return $this->sheets[] = new XlsxSheet($name, $columns);
    }

    /** A date cell from 'YYYY-MM-DD' (shown as mm/dd/yyyy). Invalid/empty -> empty cell. */
    public static function date(?string $iso): array
    {
        if ($iso === null || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $iso, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return ['t' => 'e'];
        }
        $days = (int) ((gmmktime(0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1]) - gmmktime(0, 0, 0, 12, 30, 1899)) / 86400);
        return ['t' => 'd', 'v' => $days];
    }

    public static function int($v): array
    {
        return ($v === null || $v === '') ? ['t' => 'e'] : ['t' => 'n', 'v' => (int) $v];
    }

    public static function num($v, int $decimals = 2): array
    {
        return ($v === null || $v === '') ? ['t' => 'e'] : ['t' => 'f', 'v' => round((float) $v, $decimals)];
    }

    public function save(string $path): void
    {
        if (!class_exists('ZipArchive')) {
            throw new HttpException(500, 'Creating Excel files needs the PHP "zip" extension (enable extension=zip in php.ini).');
        }
        if (!$this->sheets) { $this->addSheet('Sheet1', [['', 10]]); }
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new HttpException(500, 'Could not create the Excel file on the server.');
        }
        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $wbSheets = ''; $wbRels = '';
        foreach ($this->sheets as $i => $sh) {
            $n = $i + 1;
            $ct .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $wbSheets .= '<sheet name="' . self::esc($sh->name) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
            $wbRels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
            $zip->addFromString('xl/worksheets/sheet' . $n . '.xml', $sh->xml($i === 0));
        }
        $ct .= '</Types>';
        $sid = count($this->sheets) + 1;
        $wbRels .= '<Relationship Id="rId' . $sid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        $zip->addFromString('[Content_Types].xml', $ct);
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView activeTab="0"/></bookViews><sheets>' . $wbSheets . '</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $wbRels . '</Relationships>');
        $zip->addFromString('xl/styles.xml', self::styles());
        $zip->close();
    }

    /** Stream the workbook to the browser as a download and stop. Call before any other output. */
    public function send(string $filename): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sf9x');
        try {
            $this->save($tmp);
            while (ob_get_level() > 0) { ob_end_clean(); }
            $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: 'export.xlsx';
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $safe . '"');
            header('Content-Length: ' . filesize($tmp));
            header('Cache-Control: private, no-store');
            header('X-Content-Type-Options: nosniff');
            readfile($tmp);
        } finally {
            @unlink($tmp);
        }
        exit;
    }

    public static function esc(string $s): string
    {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? '';
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    // Style indexes (cellXfs): 0 plain | 1 header | 2 text+border | 3 title | 4 date+border | 5 integer+border (centred)
    //                          6 decimal+border (centred) | 7 bold label | 8 wrapped text+border | 9 note (grey italic)
    private static function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="mm/dd/yyyy"/></numFmts>'
            . '<fonts count="5">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="14"/><color rgb="FF172033"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
            . '<font><i/><sz val="10"/><color rgb="FF5F6B7F"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF2456C9"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left style="thin"><color rgb="FFC9D0DC"/></left><right style="thin"><color rgb="FFC9D0DC"/></right><top style="thin"><color rgb="FFC9D0DC"/></top><bottom style="thin"><color rgb="FFC9D0DC"/></bottom><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="10">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="49" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment vertical="top"/></xf>'
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="top"/></xf>'
            . '<xf numFmtId="1" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="top"/></xf>'
            . '<xf numFmtId="2" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="top"/></xf>'
            . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="49" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="4" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }
}

final class XlsxSheet
{
    public string $name;
    private array $columns;
    private array $pre = [];      // rows above the header: [style, text]
    private array $rows = [];
    private array $wrapCols = [];

    public function __construct(string $name, array $columns)
    {
        $this->name = $name;
        $this->columns = $columns;
    }

    /** Big title row above the table. */
    public function title(string $text): self { $this->pre[] = [3, $text]; return $this; }
    /** Small grey note row above the table. */
    public function note(string $text): self { $this->pre[] = [9, $text]; return $this; }
    /** Bold "label: value" pair row above the table. */
    public function label(string $label, string $value): self { $this->pre[] = [7, $label . ' ' . $value]; return $this; }
    public function gap(): self { $this->pre[] = [0, '']; return $this; }
    /** Wrap text in these 0-based columns. */
    public function wrap(int ...$cols): self { $this->wrapCols = array_merge($this->wrapCols, $cols); return $this; }

    /** @param array<int,mixed> $cells string | int | float | XlsxWriter::date()/int()/num() arrays */
    public function row(array $cells): self { $this->rows[] = $cells; return $this; }

    private static function ref(int $col, int $row): string
    {
        $s = ''; $c = $col + 1;
        while ($c > 0) { $m = ($c - 1) % 26; $s = chr(65 + $m) . $s; $c = intdiv($c - 1, 26); }
        return $s . $row;
    }

    public function xml(bool $active): string
    {
        $ncols = max(1, count($this->columns));
        $headerRow = count($this->pre) + 1;
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        $lastRow = $headerRow + count($this->rows);
        $x .= '<dimension ref="A1:' . self::ref($ncols - 1, max(1, $lastRow)) . '"/>';
        $x .= '<sheetViews><sheetView workbookViewId="0"' . ($active ? ' tabSelected="1"' : '') . '>'
            . '<pane ySplit="' . $headerRow . '" topLeftCell="A' . ($headerRow + 1) . '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
        $x .= '<sheetFormatPr defaultRowHeight="15"/><cols>';
        foreach ($this->columns as $i => $col) {
            $x .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (float) ($col[1] ?? 14) . '" customWidth="1"/>';
        }
        $x .= '</cols><sheetData>';

        $r = 1;
        foreach ($this->pre as [$style, $text]) {
            $x .= '<row r="' . $r . '">' . ($text === '' ? '' : '<c r="A' . $r . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . XlsxWriter::esc($text) . '</t></is></c>') . '</row>';
            $r++;
        }
        $x .= '<row r="' . $r . '" ht="30" customHeight="1">';
        foreach ($this->columns as $i => $col) {
            $x .= '<c r="' . self::ref($i, $r) . '" s="1" t="inlineStr"><is><t xml:space="preserve">' . XlsxWriter::esc((string) $col[0]) . '</t></is></c>';
        }
        $x .= '</row>';
        $r++;

        foreach ($this->rows as $cells) {
            $x .= '<row r="' . $r . '">';
            foreach (array_values($cells) as $i => $v) {
                if ($i >= $ncols) { break; }
                $ref = self::ref($i, $r);
                $wrap = in_array($i, $this->wrapCols, true);
                if (is_array($v)) {
                    switch ($v['t']) {
                        case 'd': $x .= '<c r="' . $ref . '" s="4"><v>' . (int) $v['v'] . '</v></c>'; break;
                        case 'n': $x .= '<c r="' . $ref . '" s="5"><v>' . (int) $v['v'] . '</v></c>'; break;
                        case 'f': $x .= '<c r="' . $ref . '" s="6"><v>' . rtrim(rtrim(sprintf('%.6F', $v['v']), '0'), '.') . '</v></c>'; break;
                        default:  $x .= '<c r="' . $ref . '" s="2"/>';
                    }
                } elseif ($v === null || $v === '') {
                    $x .= '<c r="' . $ref . '" s="' . ($wrap ? 8 : 2) . '"/>';
                } elseif (is_int($v) || is_float($v)) {
                    $x .= '<c r="' . $ref . '" s="5"><v>' . $v . '</v></c>';
                } else {
                    $x .= '<c r="' . $ref . '" s="' . ($wrap ? 8 : 2) . '" t="inlineStr"><is><t xml:space="preserve">' . XlsxWriter::esc((string) $v) . '</t></is></c>';
                }
            }
            $x .= '</row>';
            $r++;
        }
        $x .= '</sheetData>';
        if ($this->rows) {
            $x .= '<autoFilter ref="A' . $headerRow . ':' . self::ref($ncols - 1, $lastRow) . '"/>';
        }
        $x .= '<pageMargins left="0.5" right="0.5" top="0.75" bottom="0.75" header="0.3" footer="0.3"/>'
            . '<pageSetup orientation="landscape" fitToHeight="0"/></worksheet>';
        return $x;
    }
}
