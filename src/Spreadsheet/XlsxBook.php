<?php
require_once __DIR__ . '/../Http/Http.php';
require_once __DIR__ . '/XlsxWriter.php';

/**
 * Formatted .xlsx writer for report-style workbooks (no Composer, needs PHP's zip extension).
 *
 * XlsxWriter (next to this file) writes plain "header + rows" tables for the Export/Backup feature and is
 * left untouched. XlsxBook is for sheets that need what a grading sheet needs: LIVE FORMULAS (written with
 * their calculated value cached, so the numbers also show in viewers that do not recalculate), merged cells,
 * fills, borders, number formats, hidden sheets, landscape A4 fit-to-width printing and repeating header rows.
 *
 *   $b  = new XlsxBook();
 *   $hd = $b->style(['bold' => true, 'color' => 'FFFFFF', 'fill' => '1F4E78', 'h' => 'center', 'border' => true]);
 *   $s  = $b->addSheet('1st Term');
 *   $s->width('B', 38)->height(7, 48);
 *   $s->put('A7', 'NO.', $hd);                       // text / number / null (empty styled cell)
 *   $s->formula('J8', 'IF(COUNT(K8:L8)<2,"",ROUND(AVERAGE(K8:L8),0))', 92, $cell);   // formula (no "="), cached value
 *   $s->merge('A1:R2', $title);                       // style is applied to every cell of the range
 *   $b->send('file.xlsx');
 */
final class XlsxBook
{
    /** @var XlsxBookSheet[] */
    private array $sheets = [];
    private array $fonts = [], $fills = [], $borders = [], $xfs = [], $numFmts = [];
    private array $fontKey = [], $fillKey = [], $borderKey = [], $xfKey = [], $fmtKey = [];

    public function __construct()
    {
        // Reserved defaults: font 0, fills 0/1 (none, gray125), border 0, xf 0.
        $this->font(['size' => 11]);
        $this->fills[] = '<fill><patternFill patternType="none"/></fill>';
        $this->fills[] = '<fill><patternFill patternType="gray125"/></fill>';
        $this->border(false);
        $this->xfs[] = '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>';
        $this->xfKey['default'] = 0;
    }

    public function addSheet(string $name): XlsxBookSheet
    {
        $name = trim(preg_replace('/[\[\]:*?\/\\\\]/', ' ', $name) ?? '');
        $name = mb_substr($name === '' ? 'Sheet' . (count($this->sheets) + 1) : $name, 0, 31);
        return $this->sheets[] = new XlsxBookSheet($name);
    }

    /**
     * Register a cell style and get its id.
     * Keys: bold, italic, size, color (RRGGBB), fill (RRGGBB), h (left|center|right), v (top|center|bottom), wrap, border (bool), nf (number format).
     */
    public function style(array $o): int
    {
        $key = json_encode($o);
        if (isset($this->xfKey[$key])) { return $this->xfKey[$key]; }

        $fontId   = $this->font($o);
        $fillId   = isset($o['fill']) ? $this->fill((string) $o['fill']) : 0;
        $borderId = !empty($o['border']) ? $this->border(true) : 0;
        $nf       = (string) ($o['nf'] ?? 'General');
        $numFmtId = $nf === 'General' ? 0 : ($nf === '0' ? 1 : ($nf === '0.00' ? 2 : $this->numFmt($nf)));
        $align = '';
        if (isset($o['h']) || isset($o['v']) || !empty($o['wrap'])) {
            $align = '<alignment' . (isset($o['h']) ? ' horizontal="' . $o['h'] . '"' : '') . (isset($o['v']) ? ' vertical="' . $o['v'] . '"' : '')
                   . (!empty($o['wrap']) ? ' wrapText="1"' : '') . '/>';
        }
        $xf = '<xf numFmtId="' . $numFmtId . '" fontId="' . $fontId . '" fillId="' . $fillId . '" borderId="' . $borderId . '" xfId="0"'
            . ' applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"' . ($align !== '' ? ' applyAlignment="1">' . $align . '</xf>' : '/>');
        $this->xfs[] = $xf;
        return $this->xfKey[$key] = count($this->xfs) - 1;
    }

    private function font(array $o): int
    {
        $x = '<font>' . (!empty($o['bold']) ? '<b/>' : '') . (!empty($o['italic']) ? '<i/>' : '')
           . '<sz val="' . (float) ($o['size'] ?? 11) . '"/>'
           . (isset($o['color']) ? '<color rgb="FF' . strtoupper((string) $o['color']) . '"/>' : '')
           . '<name val="Calibri"/></font>';
        if (!isset($this->fontKey[$x])) { $this->fonts[] = $x; $this->fontKey[$x] = count($this->fonts) - 1; }
        return $this->fontKey[$x];
    }

    private function fill(string $rgb): int
    {
        $x = '<fill><patternFill patternType="solid"><fgColor rgb="FF' . strtoupper($rgb) . '"/><bgColor indexed="64"/></patternFill></fill>';
        if (!isset($this->fillKey[$x])) { $this->fills[] = $x; $this->fillKey[$x] = count($this->fills) - 1; }
        return $this->fillKey[$x];
    }

    private function border(bool $thin): int
    {
        $side = static fn(string $n): string => $thin ? '<' . $n . ' style="thin"><color auto="1"/></' . $n . '>' : '<' . $n . '/>';
        $x = '<border>' . $side('left') . $side('right') . $side('top') . $side('bottom') . '<diagonal/></border>';
        if (!isset($this->borderKey[$x])) { $this->borders[] = $x; $this->borderKey[$x] = count($this->borders) - 1; }
        return $this->borderKey[$x];
    }

    private function numFmt(string $code): int
    {
        if (!isset($this->fmtKey[$code])) { $this->fmtKey[$code] = 164 + count($this->fmtKey); }
        return $this->fmtKey[$code];
    }

    // ------------------------------------------------------------------------------------------------------------

    public function save(string $path): void
    {
        if (!class_exists('ZipArchive')) {
            throw new HttpException(500, 'Creating Excel files needs the PHP "zip" extension (enable extension=zip in php.ini).');
        }
        if (!$this->sheets) { $this->addSheet('Sheet1'); }
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new HttpException(500, 'Could not create the Excel file on the server.');
        }
        $hdr = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $ct = $hdr . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $wbSheets = ''; $wbRels = ''; $names = '';
        foreach ($this->sheets as $i => $sh) {
            $n = $i + 1;
            $ct .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $wbSheets .= '<sheet name="' . XlsxWriter::esc($sh->name) . '" sheetId="' . $n . '"' . ($sh->isHidden() ? ' state="hidden"' : '') . ' r:id="rId' . $n . '"/>';
            $wbRels   .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
            if ($sh->printTitleRows() !== null) {
                $names .= '<definedName name="_xlnm.Print_Titles" localSheetId="' . $i . '">\'' . XlsxWriter::esc(str_replace("'", "''", $sh->name)) . '\'!' . $sh->printTitleRows() . '</definedName>';
            }
            $zip->addFromString('xl/worksheets/sheet' . $n . '.xml', $sh->xml($i === 0));
        }
        $ct .= '</Types>';
        $wbRels .= '<Relationship Id="rId' . (count($this->sheets) + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        $zip->addFromString('[Content_Types].xml', $ct);
        $zip->addFromString('_rels/.rels', $hdr . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        // fullCalcOnLoad: Excel recalculates every formula when the file is opened (cached values are only a fallback).
        $zip->addFromString('xl/workbook.xml', $hdr . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<bookViews><workbookView activeTab="0"/></bookViews><sheets>' . $wbSheets . '</sheets>'
            . ($names !== '' ? '<definedNames>' . $names . '</definedNames>' : '') . '<calcPr calcId="191029" fullCalcOnLoad="1"/></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', $hdr . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $wbRels . '</Relationships>');
        $zip->addFromString('xl/styles.xml', $this->stylesXml($hdr));
        $zip->close();
    }

    /** Stream the workbook to the browser as a download and stop. Call before any other output. */
    public function send(string $filename): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sf9b');
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

    private function stylesXml(string $hdr): string
    {
        $fmts = '';
        foreach ($this->fmtKey as $code => $id) { $fmts .= '<numFmt numFmtId="' . $id . '" formatCode="' . XlsxWriter::esc((string) $code) . '"/>'; }
        return $hdr . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . ($fmts !== '' ? '<numFmts count="' . count($this->fmtKey) . '">' . $fmts . '</numFmts>' : '')
            . '<fonts count="' . count($this->fonts) . '">' . implode('', $this->fonts) . '</fonts>'
            . '<fills count="' . count($this->fills) . '">' . implode('', $this->fills) . '</fills>'
            . '<borders count="' . count($this->borders) . '">' . implode('', $this->borders) . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="' . count($this->xfs) . '">' . implode('', $this->xfs) . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }
}

final class XlsxBookSheet
{
    public string $name;
    private array $cells = [];        // row => col(int) => [kind, value, cached, style]
    private array $widths = [];       // col(int) => width
    private array $heights = [];      // row => height
    private array $merges = [];
    private bool $hidden = false;
    private ?string $titleRows = null;
    private float $defaultHeight = 15;

    public function __construct(string $name) { $this->name = $name; }

    public function hidden(bool $h = true): self { $this->hidden = $h; return $this; }
    public function isHidden(): bool { return $this->hidden; }
    /** Repeat these rows at the top of every printed page, e.g. '$7:$8'. */
    public function repeatRows(string $rows): self { $this->titleRows = $rows; return $this; }
    public function printTitleRows(): ?string { return $this->titleRows; }
    public function defaultRowHeight(float $h): self { $this->defaultHeight = $h; return $this; }

    /** @param string|int $col column letter ('B') or 1-based index */
    public function width($col, float $w): self { $this->widths[is_int($col) ? $col : self::colIndex($col)] = $w; return $this; }
    public function height(int $row, float $h): self { $this->heights[$row] = $h; return $this; }

    /** Text, number, or null (empty styled cell). Text is always stored as text. */
    public function put(string $ref, $value, int $style = 0): self
    {
        [$c, $r] = self::parseRef($ref);
        $kind = $value === null || $value === '' ? 'e' : (is_int($value) || is_float($value) ? 'n' : 's');
        $this->cells[$r][$c] = [$kind, $value, null, $style];
        return $this;
    }

    /** Live formula (without the leading "=") plus the value it evaluates to (number, text, or '' for blank). */
    public function formula(string $ref, string $formula, $cached, int $style = 0): self
    {
        [$c, $r] = self::parseRef($ref);
        $this->cells[$r][$c] = ['f', ltrim($formula, '='), $cached, $style];
        return $this;
    }

    /** Merge a range ("A1:E1"); the style is applied to every cell of it so borders/fills show on the whole block. */
    public function merge(string $range, int $style = 0): self
    {
        [$a, $b] = explode(':', $range);
        [$c1, $r1] = self::parseRef($a); [$c2, $r2] = self::parseRef($b);
        for ($r = $r1; $r <= $r2; $r++) {
            for ($c = $c1; $c <= $c2; $c++) {
                if (!isset($this->cells[$r][$c])) { $this->cells[$r][$c] = ['e', null, null, $style]; }
                else { $this->cells[$r][$c][3] = $style; }
            }
        }
        $this->merges[] = $range;
        return $this;
    }

    public static function colName(int $col): string
    {
        $s = '';
        while ($col > 0) { $m = ($col - 1) % 26; $s = chr(65 + $m) . $s; $col = intdiv($col - 1, 26); }
        return $s;
    }

    public static function colIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split(strtoupper($letters)) as $ch) { $n = $n * 26 + (ord($ch) - 64); }
        return $n;
    }

    /** @return array{0:int,1:int} [col, row] */
    private static function parseRef(string $ref): array
    {
        if (!preg_match('/^([A-Za-z]+)(\d+)$/', $ref, $m)) { throw new InvalidArgumentException('Bad cell reference: ' . $ref); }
        return [self::colIndex($m[1]), (int) $m[2]];
    }

    private static function num($v): string
    {
        return rtrim(rtrim(sprintf('%.10F', (float) $v), '0'), '.') ?: '0';
    }

    public function xml(bool $active): string
    {
        $maxR = $this->cells ? max(array_keys($this->cells)) : 1;
        $maxC = 1;
        foreach ($this->cells as $row) { $maxC = max($maxC, max(array_keys($row))); }

        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
           . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
           . '<dimension ref="A1:' . self::colName($maxC) . $maxR . '"/>'
           . '<sheetViews><sheetView showGridLines="0"' . ($active ? ' tabSelected="1"' : '') . ' workbookViewId="0"/></sheetViews>'
           . '<sheetFormatPr defaultRowHeight="' . $this->defaultHeight . '"/>';
        if ($this->widths) {
            ksort($this->widths);
            $x .= '<cols>';
            foreach ($this->widths as $c => $w) { $x .= '<col min="' . $c . '" max="' . $c . '" width="' . $w . '" customWidth="1"/>'; }
            $x .= '</cols>';
        }
        $x .= '<sheetData>';
        $rows = array_unique(array_merge(array_keys($this->cells), array_keys($this->heights)));
        sort($rows);
        foreach ($rows as $r) {
            $x .= '<row r="' . $r . '"' . (isset($this->heights[$r]) ? ' ht="' . $this->heights[$r] . '" customHeight="1"' : '') . '>';
            $cols = $this->cells[$r] ?? [];
            ksort($cols);
            foreach ($cols as $c => [$kind, $v, $cached, $s]) {
                $ref = self::colName($c) . $r;
                $sa  = $s ? ' s="' . $s . '"' : '';
                switch ($kind) {
                    case 's':
                        $x .= '<c r="' . $ref . '"' . $sa . ' t="inlineStr"><is><t xml:space="preserve">' . XlsxWriter::esc((string) $v) . '</t></is></c>';
                        break;
                    case 'n':
                        $x .= '<c r="' . $ref . '"' . $sa . '><v>' . self::num($v) . '</v></c>';
                        break;
                    case 'f':
                        $f = XlsxWriter::esc((string) $v);
                        if (is_int($cached) || is_float($cached)) {
                            $x .= '<c r="' . $ref . '"' . $sa . '><f>' . $f . '</f><v>' . self::num($cached) . '</v></c>';
                        } else {
                            $x .= '<c r="' . $ref . '"' . $sa . ' t="str"><f>' . $f . '</f><v>' . XlsxWriter::esc((string) ($cached ?? '')) . '</v></c>';
                        }
                        break;
                    default:
                        $x .= '<c r="' . $ref . '"' . $sa . '/>';
                }
            }
            $x .= '</row>';
        }
        $x .= '</sheetData>';
        if ($this->merges) {
            $x .= '<mergeCells count="' . count($this->merges) . '">';
            foreach ($this->merges as $m) { $x .= '<mergeCell ref="' . $m . '"/>'; }
            $x .= '</mergeCells>';
        }
        // Landscape A4, all columns on one page wide, as many pages tall as needed.
        $x .= '<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>'
            . '<pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="0"/></worksheet>';
        return $x;
    }
}
