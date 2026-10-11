<?php
require_once __DIR__ . '/../Http/Http.php';

/**
 * Minimal, dependency-free spreadsheet reader (no Composer, matching the rest of this project).
 *
 *   .xls   Excel 97-2003 (BIFF8 inside an OLE2 container)  — what the DepEd "SF1" export produces
 *   .xlsx  Excel 2007+ (needs PHP's bundled ZipArchive; on by default in XAMPP)
 *   .csv   comma / semicolon / tab separated text
 *
 * The file type is detected from the file's first bytes, never from its name alone.
 *
 * read() returns ['sheet' => string, 'rows' => array<int rowNumber(1-based), array<int, string>>]
 * for the first worksheet that contains data. Every cell comes back as a trimmed string; cells
 * formatted as dates are returned as 'YYYY-MM-DD'; whole numbers have no trailing ".0".
 * Only cell VALUES are read (formulas: the cached result). Macros/links/objects are never executed.
 */
final class SpreadsheetReader
{
    public const MAX_ROWS = 5000;
    public const MAX_COLS = 80;
    private const MAX_UNZIPPED = 104857600;   // 100 MB — zip-bomb guard for .xlsx

    /** @return array{sheet:string,rows:array<int,array<int,string>>} */
    public static function read(string $path, string $originalName = ''): array
    {
        $head = (string) file_get_contents($path, false, null, 0, 8);
        $ext  = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (strncmp($head, "PK\x03\x04", 4) === 0) {
            return self::readXlsx($path);
        }
        if (strncmp($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 8) === 0) {
            return self::readXls($path);
        }
        if (in_array($ext, ['csv', 'txt'], true)) {
            return self::readCsv($path);
        }
        throw new HttpException(422, 'That file is not a readable Excel workbook. Upload an .xls, .xlsx or .csv file.');
    }

    // ---------------------------------------------------------------- CSV

    private static function readCsv(string $path): array
    {
        $raw = (string) file_get_contents($path);
        if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
            $raw = substr($raw, 3);
        }
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }
        $firstLine = strtok($raw, "\n") ?: '';
        $delims = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
        arsort($delims);
        $delim = (string) array_key_first($delims);

        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $raw);
        rewind($fh);
        $rows = [];
        $n = 0;
        while (($line = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
            $n++;
            if ($n > self::MAX_ROWS) {
                throw new HttpException(422, 'The file has more than ' . self::MAX_ROWS . ' rows. Split it into smaller files.');
            }
            $rows[$n] = array_map(static fn($v) => trim((string) $v), array_slice($line, 0, self::MAX_COLS));
        }
        fclose($fh);
        return ['sheet' => 'CSV', 'rows' => self::dense($rows)];
    }

    // ---------------------------------------------------------------- XLSX

    private static function readXlsx(string $path): array
    {
        if (!class_exists('ZipArchive')) {
            throw new HttpException(500, 'Reading .xlsx files needs the PHP "zip" extension (enable extension=zip in php.ini), or upload the file as .xls / .csv.');
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new HttpException(422, 'The uploaded file is damaged or is not a valid Excel workbook.');
        }
        try {
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $total += (int) ($zip->statIndex($i)['size'] ?? 0);
                if ($total > self::MAX_UNZIPPED) {
                    throw new HttpException(422, 'The workbook is too large to import.');
                }
            }
            $wb = self::zipXml($zip, 'xl/workbook.xml');
            if ($wb === null) {
                throw new HttpException(422, 'That file is not an Excel workbook (xl/workbook.xml is missing).');
            }
            $relNs = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

            $targets = [];
            if ($rels = self::zipXml($zip, 'xl/_rels/workbook.xml.rels')) {
                foreach ($rels->Relationship ?? [] as $r) {
                    $targets[(string) $r['Id']] = (string) $r['Target'];
                }
            }
            $date1904 = isset($wb->workbookPr) && in_array((string) $wb->workbookPr['date1904'], ['1', 'true'], true);

            // shared strings
            $shared = [];
            if ($ss = self::zipXml($zip, 'xl/sharedStrings.xml')) {
                foreach ($ss->si ?? [] as $si) {
                    if (isset($si->t)) {
                        $shared[] = (string) $si->t;
                    } else {
                        $t = '';
                        foreach ($si->r ?? [] as $run) { $t .= (string) $run->t; }
                        $shared[] = $t;
                    }
                }
            }

            // which cell styles are dates
            $xfDate = [];
            if ($st = self::zipXml($zip, 'xl/styles.xml')) {
                $codes = [];
                if (isset($st->numFmts)) {
                    foreach ($st->numFmts->numFmt ?? [] as $nf) { $codes[(int) $nf['numFmtId']] = (string) $nf['formatCode']; }
                }
                if (isset($st->cellXfs)) {
                    foreach ($st->cellXfs->xf ?? [] as $i => $xf) {
                        $id = (int) $xf['numFmtId'];
                        $xfDate[] = self::isDateFormat($id, $codes[$id] ?? null);
                    }
                }
            }

            $sheets = [];
            foreach ($wb->sheets->sheet ?? [] as $sh) {
                $rid = (string) $sh->attributes($relNs)['id'];
                $target = $targets[$rid] ?? null;
                if ($target === null) { continue; }
                $file = $target[0] === '/' ? ltrim($target, '/') : 'xl/' . $target;
                $sheets[] = ['name' => (string) $sh['name'], 'file' => $file, 'hidden' => (string) $sh['state'] !== '' && (string) $sh['state'] !== 'visible'];
            }
            usort($sheets, static fn($a, $b) => (int) $a['hidden'] <=> (int) $b['hidden']);   // visible sheets first (stable enough)

            foreach ($sheets as $sh) {
                $x = self::zipXml($zip, $sh['file']);
                if ($x === null) { continue; }
                $rows = [];
                $n = 0;
                if (!isset($x->sheetData)) { continue; }
                foreach ($x->sheetData->row ?? [] as $row) {
                    $rn = (int) $row['r'] ?: ($n + 1);
                    $n = $rn;
                    if ($rn > self::MAX_ROWS) {
                        throw new HttpException(422, 'The sheet has more than ' . self::MAX_ROWS . ' rows. Split it into smaller files.');
                    }
                    $colIdx = -1;
                    foreach ($row->c ?? [] as $c) {
                        $ref = (string) $c['r'];
                        $colIdx = $ref !== '' ? self::colIndex($ref) : $colIdx + 1;
                        if ($colIdx >= self::MAX_COLS) { continue; }
                        $type = (string) $c['t'];
                        $val = '';
                        if ($type === 's') {
                            $val = $shared[(int) $c->v] ?? '';
                        } elseif ($type === 'inlineStr') {
                            $is = $c->is ?? null;
                            if ($is !== null) {
                                if (isset($is->t)) { $val = (string) $is->t; }
                                else { foreach ($is->r ?? [] as $run) { $val .= (string) $run->t; } }
                            }
                        } elseif ($type === 'str') {
                            $val = (string) $c->v;
                        } elseif ($type === 'b') {
                            $val = (string) $c->v === '1' ? 'TRUE' : 'FALSE';
                        } elseif ($type === 'e') {
                            $val = '';
                        } elseif (isset($c->v) && (string) $c->v !== '') {
                            $num = (float) (string) $c->v;
                            $val = !empty($xfDate[(int) $c['s']]) ? self::serialToDate($num, $date1904) : self::numToString($num);
                        }
                        $val = trim($val);
                        if ($val !== '') { $rows[$rn][$colIdx] = $val; }
                    }
                }
                if ($rows) {
                    return ['sheet' => $sh['name'], 'rows' => self::dense($rows)];
                }
            }
            return ['sheet' => '', 'rows' => []];
        } finally {
            $zip->close();
        }
    }

    private static function zipXml(ZipArchive $zip, string $name): ?SimpleXMLElement
    {
        $xml = $zip->getFromName($name);
        if ($xml === false) { return null; }
        $prev = libxml_use_internal_errors(true);
        $obj = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $obj ?: null;
    }

    private static function colIndex(string $ref): int
    {
        $n = 0;
        for ($i = 0, $l = strlen($ref); $i < $l; $i++) {
            $ch = ord($ref[$i]);
            if ($ch < 65 || $ch > 90) { if ($ch >= 97 && $ch <= 122) { $ch -= 32; } else { break; } }
            $n = $n * 26 + ($ch - 64);
        }
        return max(0, $n - 1);
    }

    // ---------------------------------------------------------------- XLS (BIFF8)

    private static function readXls(string $path): array
    {
        $size = filesize($path);
        if ($size === false || $size > 20 * 1024 * 1024) {
            throw new HttpException(422, 'The workbook is too large to import.');
        }
        $stream = self::oleStream((string) file_get_contents($path), ['workbook', 'book']);
        return self::parseBiff($stream);
    }

    /** Extract one named stream from an OLE2 compound file. */
    private static function oleStream(string $d, array $namesLower): string
    {
        $bad = new HttpException(422, 'The uploaded .xls file is damaged or unsupported. Open it in Excel and save it as .xlsx, then upload again.');
        if (strlen($d) < 1024) { throw $bad; }
        $u16 = static fn(int $o): int => unpack('v', substr($d, $o, 2))[1];
        $u32 = static fn(int $o): int => unpack('V', substr($d, $o, 4))[1];

        $ss = 1 << $u16(30);
        $mss = 1 << $u16(32);
        if (($ss !== 512 && $ss !== 4096) || $mss < 16 || $mss > 512) { throw $bad; }
        $dirStart = $u32(48); $cutoff = $u32(56); $miniFatStart = $u32(60); $difStart = $u32(68); $numDif = $u32(72);
        $sect = static fn(int $id): string => substr($d, ($id + 1) * $ss, $ss);

        $difat = [];
        for ($i = 0; $i < 109; $i++) {
            $v = $u32(76 + 4 * $i);
            if ($v < 0xFFFFFFFC) { $difat[] = $v; }
        }
        $id = $difStart;
        while ($numDif-- > 0 && $id < 0xFFFFFFFC) {
            $s = $sect($id);
            $per = intdiv($ss, 4) - 1;
            for ($i = 0; $i < $per; $i++) {
                $v = unpack('V', substr($s, 4 * $i, 4))[1] ?? 0xFFFFFFFF;
                if ($v < 0xFFFFFFFC) { $difat[] = $v; }
            }
            $id = unpack('V', substr($s, $per * 4, 4))[1] ?? 0xFFFFFFFE;
        }
        $fat = [];
        foreach ($difat as $fs) {
            $s = $sect($fs);
            if ($s === '') { continue; }
            foreach (unpack('V*', $s) as $v) { $fat[] = $v; }
        }
        $chain = static function (int $start, array $table): array {
            $out = []; $id = $start; $n = 0;
            while ($id < 0xFFFFFFFA && $n++ < 2000000) { $out[] = $id; $id = $table[$id] ?? 0xFFFFFFFE; }
            return $out;
        };
        $readChain = static fn(int $start): string => implode('', array_map($sect, $chain($start, $fat)));

        $dir = $readChain($dirStart);
        $root = null; $target = null;
        for ($o = 0; $o + 128 <= strlen($dir); $o += 128) {
            $nl = unpack('v', substr($dir, $o + 64, 2))[1];
            $name = mb_strtolower((string) mb_convert_encoding(substr($dir, $o, max(0, $nl - 2)), 'UTF-8', 'UTF-16LE'));
            $type = ord($dir[$o + 66]);
            $entry = ['start' => unpack('V', substr($dir, $o + 116, 4))[1], 'size' => unpack('V', substr($dir, $o + 120, 4))[1]];
            if ($type === 5) { $root = $entry; }
            if ($type === 2 && $target === null && in_array($name, $namesLower, true)) { $target = $entry; }
        }
        if ($target === null) {
            throw new HttpException(422, 'That file does not contain an Excel workbook. Open it in Excel and save it as .xlsx, then upload again.');
        }
        if ($target['size'] >= $cutoff) {
            return substr($readChain($target['start']), 0, $target['size']);
        }
        if ($root === null) { throw $bad; }
        $mini = substr($readChain($root['start']), 0, $root['size']);
        $miniFat = ($m = $readChain($miniFatStart)) !== '' ? array_values(unpack('V*', $m)) : [];
        $out = '';
        foreach ($chain($target['start'], $miniFat) as $mid) { $out .= substr($mini, $mid * $mss, $mss); }
        return substr($out, 0, $target['size']);
    }

    private static function parseBiff(string $s): array
    {
        $len = strlen($s);
        $pos = 0;
        $sheets = []; $sst = []; $fmtCodes = []; $xfFmt = []; $date1904 = false; $first = true;

        while ($pos + 4 <= $len) {
            $id = unpack('v', substr($s, $pos, 2))[1];
            $sz = unpack('v', substr($s, $pos + 2, 2))[1];
            $data = substr($s, $pos + 4, $sz);
            $pos += 4 + $sz;

            if ($id === 0x0809) {                                   // BOF
                if ($first) {
                    $ver = unpack('v', substr($data, 0, 2))[1] ?? 0;
                    if ($ver !== 0x0600) {
                        throw new HttpException(422, 'This is an Excel 5.0/95 file, which is not supported. Open it in Excel and save it as .xlsx, then upload again.');
                    }
                    $first = false;
                }
            } elseif ($id === 0x002F) {                              // FILEPASS
                throw new HttpException(422, 'This workbook is password-protected. Remove the password in Excel, then upload it again.');
            } elseif ($id === 0x0022) {                              // DATEMODE
                $date1904 = (unpack('v', substr($data, 0, 2))[1] ?? 0) === 1;
            } elseif ($id === 0x0085) {                              // BOUNDSHEET
                $off = unpack('V', substr($data, 0, 4))[1];
                $vis = ord($data[4] ?? "\0"); $typ = ord($data[5] ?? "\0");
                $nameLen = ord($data[6] ?? "\0"); $flg = ord($data[7] ?? "\0");
                $raw = substr($data, 8, $nameLen * (($flg & 1) ? 2 : 1));
                $name = ($flg & 1) ? (string) mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE') : (string) mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
                if ($typ === 0) { $sheets[] = ['name' => $name, 'offset' => $off, 'hidden' => $vis !== 0]; }
            } elseif ($id === 0x00FC) {                              // SST (+ CONTINUE records)
                $chunks = [$data];
                while ($pos + 4 <= $len && unpack('v', substr($s, $pos, 2))[1] === 0x003C) {
                    $csz = unpack('v', substr($s, $pos + 2, 2))[1];
                    $chunks[] = substr($s, $pos + 4, $csz);
                    $pos += 4 + $csz;
                }
                $sst = self::parseSst($chunks);
            } elseif ($id === 0x041E) {                              // FORMAT
                $idx = unpack('v', substr($data, 0, 2))[1];
                $fmtCodes[$idx] = self::readUnicode($data, 2);
            } elseif ($id === 0x00E0) {                              // XF
                $xfFmt[] = unpack('v', substr($data, 2, 2))[1] ?? 0;
            } elseif ($id === 0x000A) {                              // EOF of the globals substream
                break;
            }
        }

        $xfDate = [];
        foreach ($xfFmt as $i => $fid) { $xfDate[$i] = self::isDateFormat($fid, $fmtCodes[$fid] ?? null); }

        usort($sheets, static fn($a, $b) => (int) $a['hidden'] <=> (int) $b['hidden']);
        foreach ($sheets as $sh) {
            $rows = self::parseSheet($s, $sh['offset'], $sst, $xfDate, $date1904);
            if ($rows) { return ['sheet' => $sh['name'], 'rows' => self::dense($rows)]; }
        }
        return ['sheet' => '', 'rows' => []];
    }

    /** @param string[] $chunks SST record data followed by its CONTINUE records */
    private static function parseSst(array $chunks): array
    {
        $ci = 0; $o = 8;                                              // skip total + unique counts
        $unique = unpack('V', substr($chunks[0], 4, 4))[1] ?? 0;
        $out = [];
        $ensure = static function () use (&$ci, &$o, $chunks): bool {
            while (isset($chunks[$ci]) && $o >= strlen($chunks[$ci])) { $ci++; $o = 0; }
            return isset($chunks[$ci]);
        };
        $skip = static function (int $n) use (&$ci, &$o, $chunks): void {
            while ($n > 0 && isset($chunks[$ci])) {
                $avail = strlen($chunks[$ci]) - $o;
                $take = min($n, $avail);
                $o += $take; $n -= $take;
                if ($o >= strlen($chunks[$ci])) { $ci++; $o = 0; }
            }
        };
        for ($i = 0; $i < $unique; $i++) {
            if (!$ensure()) { break; }
            $c = $chunks[$ci];
            if ($o + 3 > strlen($c)) { break; }
            $cch = unpack('v', substr($c, $o, 2))[1];
            $flags = ord($c[$o + 2]);
            $o += 3;
            $runs = 0; $ext = 0;
            if ($flags & 0x08) { $runs = unpack('v', substr($chunks[$ci], $o, 2))[1]; $o += 2; }
            if ($flags & 0x04) { $ext = unpack('V', substr($chunks[$ci], $o, 4))[1]; $o += 4; }
            $wide = (bool) ($flags & 1);
            $remaining = $cch; $str = '';
            while ($remaining > 0) {
                if (!isset($chunks[$ci])) { break; }
                if ($o >= strlen($chunks[$ci])) {                     // string continues in the next CONTINUE record
                    $ci++; $o = 0;
                    if (!isset($chunks[$ci])) { break; }
                    $wide = (bool) (ord($chunks[$ci][0]) & 1);
                    $o = 1;
                }
                $bpc = $wide ? 2 : 1;
                $take = min($remaining, intdiv(strlen($chunks[$ci]) - $o, $bpc));
                if ($take <= 0) { $ci++; $o = 0; continue; }
                $piece = substr($chunks[$ci], $o, $take * $bpc);
                $str .= $wide ? (string) mb_convert_encoding($piece, 'UTF-8', 'UTF-16LE') : (string) mb_convert_encoding($piece, 'UTF-8', 'ISO-8859-1');
                $o += $take * $bpc;
                $remaining -= $take;
            }
            $skip($runs * 4 + $ext);
            $out[] = $str;
        }
        return $out;
    }

    /** Unicode string with a 16-bit character count at $off: [u16 cch][u8 flags][chars]. */
    private static function readUnicode(string $data, int $off): string
    {
        if (strlen($data) < $off + 3) { return ''; }
        $cch = unpack('v', substr($data, $off, 2))[1];
        $flags = ord($data[$off + 2]);
        $p = $off + 3;
        if ($flags & 0x08) { $p += 2; }
        if ($flags & 0x04) { $p += 4; }
        if ($flags & 1) { return (string) mb_convert_encoding(substr($data, $p, $cch * 2), 'UTF-8', 'UTF-16LE'); }
        return (string) mb_convert_encoding(substr($data, $p, $cch), 'UTF-8', 'ISO-8859-1');
    }

    /** @return array<int,array<int,string>> rowNumber(1-based) => [col => value] */
    private static function parseSheet(string $s, int $offset, array $sst, array $xfDate, bool $date1904): array
    {
        $len = strlen($s);
        $pos = $offset;
        $depth = 0;
        $rows = [];
        $pendingFormula = null;
        $put = static function (int $r, int $c, string $v) use (&$rows): void {
            $v = trim($v);
            if ($v === '' || $c >= self::MAX_COLS) { return; }
            if ($r >= self::MAX_ROWS) { throw new HttpException(422, 'The sheet has more than ' . self::MAX_ROWS . ' rows. Split it into smaller files.'); }
            $rows[$r + 1][$c] = $v;
        };
        $num = static function (float $v, int $xf) use ($xfDate, $date1904): string {
            return !empty($xfDate[$xf]) ? self::serialToDate($v, $date1904) : self::numToString($v);
        };

        while ($pos + 4 <= $len) {
            $id = unpack('v', substr($s, $pos, 2))[1];
            $sz = unpack('v', substr($s, $pos + 2, 2))[1];
            $d = substr($s, $pos + 4, $sz);
            $pos += 4 + $sz;

            switch ($id) {
                case 0x0809: $depth++; break;
                case 0x000A: $depth--; if ($depth <= 0) { return $rows; } break;
                case 0x00FD: {                                          // LABELSST
                    $r = unpack('v', substr($d, 0, 2))[1]; $c = unpack('v', substr($d, 2, 2))[1]; $i = unpack('V', substr($d, 6, 4))[1];
                    $put($r, $c, $sst[$i] ?? '');
                    break;
                }
                case 0x0204: {                                          // LABEL
                    $r = unpack('v', substr($d, 0, 2))[1]; $c = unpack('v', substr($d, 2, 2))[1];
                    $put($r, $c, self::readUnicode($d, 6));
                    break;
                }
                case 0x0203: {                                          // NUMBER
                    $r = unpack('v', substr($d, 0, 2))[1]; $c = unpack('v', substr($d, 2, 2))[1]; $xf = unpack('v', substr($d, 4, 2))[1];
                    $put($r, $c, $num(unpack('e', substr($d, 6, 8))[1], $xf));
                    break;
                }
                case 0x027E: {                                          // RK
                    $r = unpack('v', substr($d, 0, 2))[1]; $c = unpack('v', substr($d, 2, 2))[1]; $xf = unpack('v', substr($d, 4, 2))[1];
                    $put($r, $c, $num(self::rk(unpack('V', substr($d, 6, 4))[1]), $xf));
                    break;
                }
                case 0x00BD: {                                          // MULRK
                    $r = unpack('v', substr($d, 0, 2))[1]; $c = unpack('v', substr($d, 2, 2))[1];
                    $n = intdiv(strlen($d) - 6, 6);
                    for ($k = 0; $k < $n; $k++) {
                        $xf = unpack('v', substr($d, 4 + 6 * $k, 2))[1];
                        $put($r, $c + $k, $num(self::rk(unpack('V', substr($d, 6 + 6 * $k, 4))[1]), $xf));
                    }
                    break;
                }
                case 0x0006: {                                          // FORMULA (cached result)
                    $r = unpack('v', substr($d, 0, 2))[1]; $c = unpack('v', substr($d, 2, 2))[1]; $xf = unpack('v', substr($d, 4, 2))[1];
                    $res = substr($d, 6, 8);
                    if (strlen($res) === 8 && substr($res, 6, 2) === "\xFF\xFF") {
                        $t = ord($res[0]);
                        if ($t === 0) { $pendingFormula = [$r, $c]; }      // text result follows in a STRING record
                        elseif ($t === 1) { $put($r, $c, ord($res[2]) ? 'TRUE' : 'FALSE'); }
                    } elseif (strlen($res) === 8) {
                        $put($r, $c, $num(unpack('e', $res)[1], $xf));
                    }
                    break;
                }
                case 0x0207: {                                          // STRING (text result of the previous FORMULA)
                    if ($pendingFormula) { $put($pendingFormula[0], $pendingFormula[1], self::readUnicode($d, 0)); $pendingFormula = null; }
                    break;
                }
                case 0x0205: {                                          // BOOLERR
                    $r = unpack('v', substr($d, 0, 2))[1]; $c = unpack('v', substr($d, 2, 2))[1];
                    if (ord($d[7] ?? "\1") === 0) { $put($r, $c, ord($d[6]) ? 'TRUE' : 'FALSE'); }
                    break;
                }
            }
        }
        return $rows;
    }

    private static function rk(int $rk): float
    {
        if ($rk & 2) {
            $i = $rk >> 2;
            $v = (float) (($i & 0x20000000) ? $i - 0x40000000 : $i);
        } else {
            $v = unpack('e', pack('VV', 0, $rk & 0xFFFFFFFC))[1];
        }
        return ($rk & 1) ? $v / 100 : $v;
    }

    // ---------------------------------------------------------------- shared helpers

    /** Fill gaps so every row is a dense 0..maxCol list of strings. */
    private static function dense(array $rows): array
    {
        $maxCol = 0;
        foreach ($rows as $r) { if ($r) { $maxCol = max($maxCol, max(array_keys($r))); } }
        $out = [];
        ksort($rows);
        foreach ($rows as $rn => $r) {
            $line = array_fill(0, $maxCol + 1, '');
            foreach ($r as $c => $v) { $line[$c] = (string) $v; }
            $out[$rn] = $line;
        }
        return $out;
    }

    private static function numToString(float $v): string
    {
        if (is_nan($v) || is_infinite($v)) { return ''; }
        if ($v == floor($v) && abs($v) < 1e15) { return (string) (int) $v; }
        return rtrim(rtrim(sprintf('%.12F', $v), '0'), '.');
    }

    private static function serialToDate(float $serial, bool $date1904): string
    {
        if ($serial < 0 || $serial > 2958465) { return self::numToString($serial); }
        $days = (int) floor($serial);
        if ($days === 0) { return sprintf('%02d:%02d', floor(($serial - $days) * 24), round((($serial - $days) * 24 - floor(($serial - $days) * 24)) * 60)); }
        $base = new DateTimeImmutable($date1904 ? '1904-01-01' : '1899-12-30');
        return $base->modify('+' . $days . ' days')->format('Y-m-d');
    }

    private static function isDateFormat(int $id, ?string $code): bool
    {
        if (($id >= 14 && $id <= 22) || ($id >= 27 && $id <= 36) || ($id >= 45 && $id <= 47) || ($id >= 50 && $id <= 58)) {
            return true;
        }
        if ($code === null || $code === '') { return false; }
        $c = preg_replace('/"[^"]*"|\\\\.|\[[^\]]*\]/', '', $code) ?? '';
        return (bool) preg_match('/[ymdhs]/i', $c) && !preg_match('/^(general|@)$/i', trim($c));
    }
}
