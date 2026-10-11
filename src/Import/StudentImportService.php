<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../Spreadsheet/SpreadsheetReader.php';
require_once __DIR__ . '/../Spreadsheet/XlsxWriter.php';
require_once __DIR__ . '/../Enrollment/EnrollmentService.php';

/**
 * Import learners from an Excel/CSV file into the EXISTING `students` table (no parallel student
 * system), optionally enrolling them in a section through the existing EnrollmentService rules.
 *
 * Two steps so nothing is written until the Admin has seen the result:
 *   analyze()  reads + validates + checks duplicates, writes NOTHING.
 *   commit()   inserts only the rows that were "new" (re-checked against the database at that moment).
 *
 * Columns are found by their HEADER TEXT, not by position, so both the school's own template and the
 * DepEd "School Form 1 (SF1) School Register" export work:
 *   LRN | Last Name | First Name | Middle Name | Sex | Birth Date
 *   LRN | NAME (Last Name, First Name, Middle Name) | Sex (M/F) | BIRTH DATE (mm/dd/yyyy)      <- SF1
 * Other columns (age, address, parents, religion...) are ignored; Age is always calculated from the birth date.
 *
 * Existing students are NEVER overwritten: a row whose LRN already exists is reported as a duplicate and skipped.
 */
final class StudentImportService
{
    public const MAX_BYTES = 5242880;         // 5 MB
    public const MAX_ROWS  = 2000;            // learner rows per file
    public const TEMPLATE_HEADERS = ['LRN', 'Last Name', 'First Name', 'Middle Name', 'Sex (M/F)', 'Birth Date (mm/dd/yyyy)'];

    // ------------------------------------------------------------------ analyze

    /**
     * @return array{summary:array<string,int>,context:array<string,string>,rows:array<int,array<string,mixed>>,sheet:string}
     *         plus internal 'pending' (rows eligible to import) used by commit().
     */
    public static function analyze(string $path, string $originalName): array
    {
        $book = SpreadsheetReader::read($path, $originalName);
        if (!$book['rows']) {
            throw new HttpException(422, 'The file is empty. Download the template, fill it in and upload it again.');
        }
        $rows = $book['rows'];

        [$headerRow, $cols] = self::findHeader($rows);
        $context = self::detectContext($rows, $headerRow);

        $records = [];
        $ignored = 0;
        $lrnSeen = [];
        foreach ($rows as $rn => $cells) {
            if ($rn <= $headerRow) { continue; }
            $get = static fn(string $k): string => isset($cols[$k]) ? trim((string) ($cells[$cols[$k]] ?? '')) : '';

            $rawLrn = $get('lrn');
            $name = self::splitName($get('name'), $get('last'), $get('first'), $get('middle'), isset($cols['name']));
            $sex = $get('sex');
            $birth = $get('birth');

            // Totals, legends and page footers of the official SF1 are not learners.
            $lrnDigits = preg_match('/^\d{12}$/', $rawLrn) === 1;
            $looksLikeTotal = preg_match('/^<=+|^total\b|\b(total (male|female)|combined)\b/i', $get('name') ?: $get('last')) === 1;
            $nothing = $rawLrn === '' && $name['full'] === '' && $sex === '' && $birth === '';
            // A row with no name/sex/birth date whose first cell is text (headings, legends, footers) is not a learner;
            // one whose first cell is a number IS treated as a learner row (and reported as invalid if incomplete).
            $labelOnly = $name['full'] === '' && $sex === '' && $birth === '' && !preg_match('/^\d{5,}$/', $rawLrn);
            if (($looksLikeTotal && !$lrnDigits) || $nothing || $labelOnly) {
                $ignored++;
                continue;
            }
            if (count($records) >= self::MAX_ROWS) {
                throw new HttpException(422, 'The file has more than ' . self::MAX_ROWS . ' learners. Split it into smaller files.');
            }

            $errors = [];
            $lrn = preg_replace('/\s+/', '', $rawLrn) ?? '';
            if ($lrn === '') {
                $errors['lrn'] = 'LRN is missing.';
            } elseif (!preg_match('/^\d{12}$/', $lrn)) {
                $errors['lrn'] = 'LRN must be exactly 12 digits' . (preg_match('/^\d{11}$/', $lrn) ? ' (it has 11 — if it starts with 0, format the LRN column as Text in Excel).' : '.');
            }
            if ($name['last'] === '')  { $errors['last_name'] = 'Last name is missing.'; }
            if ($name['first'] === '') { $errors['first_name'] = 'First name is missing.'; }
            if (isset($cols['name']) && $name['full'] !== '' && $name['parts'] < 2) {
                $errors['name'] = 'Name must be written as "Last Name, First Name, Middle Name".';
            }
            foreach (['last' => 'Last name', 'first' => 'First name', 'middle' => 'Middle name'] as $k => $label) {
                if (mb_strlen($name[$k]) > 100) { $errors[$k . '_name'] = $label . ' is too long (max 100 characters).'; }
            }
            $gender = self::parseSex($sex);
            if ($gender === null) {
                $errors['gender'] = $sex === '' ? 'Sex is missing (use M or F).' : 'Sex "' . self::clip($sex) . '" is not recognised (use M or F).';
            }
            $birthdate = null;
            if ($birth !== '') {
                $birthdate = self::parseDate($birth);
                if ($birthdate === null) {
                    $errors['birthdate'] = 'Birth date "' . self::clip($birth) . '" is not a valid date (use mm/dd/yyyy).';
                } elseif ($birthdate > date('Y-m-d')) {
                    $errors['birthdate'] = 'Birth date is in the future.';
                    $birthdate = null;
                }
            }

            $rec = [
                'row' => $rn, 'lrn' => $lrn !== '' ? $lrn : $rawLrn, 'last_name' => $name['last'], 'first_name' => $name['first'],
                'middle_name' => $name['middle'], 'gender' => $gender, 'birthdate' => $birthdate,
                'status' => 'new', 'messages' => [],
            ];
            if ($errors) {
                $rec['status'] = 'invalid';
                $rec['messages'] = array_values($errors);
            } elseif (isset($lrnSeen[$lrn])) {
                $rec['status'] = 'duplicate';
                $rec['messages'][] = 'Same LRN as row ' . $lrnSeen[$lrn] . ' of this file.';
            } else {
                $lrnSeen[$lrn] = $rn;
            }
            $records[] = $rec;
        }

        self::markDuplicatesInDatabase($records);

        $summary = ['total' => count($records), 'new' => 0, 'duplicate' => 0, 'possible_duplicate' => 0, 'invalid' => 0, 'ignored' => $ignored];
        foreach ($records as $r) { $summary[$r['status']]++; }

        return ['summary' => $summary, 'context' => $context, 'sheet' => $book['sheet'], 'rows' => $records];
    }

    /** @param array<int,array<string,mixed>> $records */
    private static function markDuplicatesInDatabase(array &$records): void
    {
        $pdo = get_db();
        $lrns = [];
        $lasts = [];
        foreach ($records as $r) {
            if ($r['status'] !== 'new') { continue; }
            $lrns[$r['lrn']] = true;
            $lasts[mb_strtoupper($r['last_name'])] = true;
        }
        if (!$lrns) { return; }

        $byLrn = [];
        foreach (array_chunk(array_keys($lrns), 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $st = $pdo->prepare("SELECT lrn, last_name, first_name, middle_name FROM students WHERE lrn IN ($in)");
            $st->execute($chunk);
            foreach ($st->fetchAll() as $s) { $byLrn[$s['lrn']] = $s; }
        }

        $byName = [];
        foreach (array_chunk(array_keys($lasts), 400) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $st = $pdo->prepare("SELECT lrn, last_name, first_name, middle_name, birthdate FROM students WHERE UPPER(last_name) IN ($in)");
            $st->execute($chunk);
            foreach ($st->fetchAll() as $s) { $byName[self::nameKey($s['last_name'], $s['first_name'])][] = $s; }
        }

        foreach ($records as &$r) {
            if ($r['status'] !== 'new') { continue; }
            if (isset($byLrn[$r['lrn']])) {
                $e = $byLrn[$r['lrn']];
                $same = self::nameKey($e['last_name'], $e['first_name']) === self::nameKey($r['last_name'], $r['first_name']);
                $r['status'] = 'duplicate';
                $r['messages'][] = $same
                    ? 'Already in the system: ' . self::display($e) . ' (same LRN).'
                    : 'LRN already belongs to a different learner: ' . self::display($e) . '. Check the LRN.';
                continue;
            }
            foreach ($byName[self::nameKey($r['last_name'], $r['first_name'])] ?? [] as $e) {
                $mid1 = self::norm($r['middle_name']); $mid2 = self::norm((string) $e['middle_name']);
                if ($mid1 !== '' && $mid2 !== '' && $mid1 !== $mid2 && substr($mid1, 0, 1) !== substr($mid2, 0, 1)) { continue; }
                $b1 = $r['birthdate']; $b2 = $e['birthdate'];
                if ($b1 !== null && $b2 !== null && $b1 !== $b2) { continue; }
                $r['status'] = 'possible_duplicate';
                $r['messages'][] = 'Possible duplicate of ' . self::display($e) . ' (LRN ' . $e['lrn'] . '): same name' . (($b1 !== null && $b1 === $b2) ? ' and birth date' : '') . ' but a different LRN.';
                break;
            }
        }
        unset($r);
    }

    // ------------------------------------------------------------------ commit

    /**
     * @param array<int,array<string,mixed>> $records rows from analyze() (only status new / possible_duplicate are considered)
     * @param array|null $section  section (id, school_year_id, year_active, grade_level, year_label) to enroll new learners in
     * @return array{imported:int,duplicates:int,invalid:int,enrolled:int,enroll_failures:array<int,string>,skipped:int,rows:array}
     */
    public static function commit(array $records, bool $allowPossibleDuplicates, ?array $section): array
    {
        $pdo = get_db();
        $imported = []; $dupes = 0; $invalid = 0; $skippedPossible = 0;
        $rowsOut = [];

        $pdo->beginTransaction();
        try {
            $find = $pdo->prepare('SELECT id FROM students WHERE lrn = ? LIMIT 1');
            $ins  = $pdo->prepare('INSERT INTO students (lrn, last_name, first_name, middle_name, gender, birthdate) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($records as $r) {
                $out = ['row' => $r['row'], 'lrn' => $r['lrn'], 'name' => self::display($r), 'result' => $r['status'], 'messages' => $r['messages']];
                if ($r['status'] === 'invalid') { $invalid++; }
                elseif ($r['status'] === 'duplicate') { $dupes++; }
                elseif ($r['status'] === 'possible_duplicate' && !$allowPossibleDuplicates) { $dupes++; $skippedPossible++; }
                else {
                    // Re-check at the moment of writing: someone may have added this LRN since the preview.
                    $find->execute([$r['lrn']]);
                    if ($find->fetchColumn() !== false) {
                        $dupes++; $out['result'] = 'duplicate'; $out['messages'] = ['Already in the system (added since the preview).'];
                    } else {
                        try {
                            $ins->execute([$r['lrn'], $r['last_name'], $r['first_name'], $r['middle_name'] !== '' ? $r['middle_name'] : null, $r['gender'], $r['birthdate']]);
                            $imported[] = ['id' => (int) $pdo->lastInsertId(), 'row' => $r['row'], 'name' => self::display($r)];
                            $out['result'] = 'imported';
                        } catch (PDOException $e) {
                            if ((int) ($e->errorInfo[1] ?? 0) === 1062) { $dupes++; $out['result'] = 'duplicate'; $out['messages'] = ['Already in the system.']; }
                            else { throw $e; }
                        }
                    }
                }
                $rowsOut[] = $out;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }

        // Enrollment uses the existing rules (active year only, one section per year, roster numbers...).
        $enrolled = 0; $failures = [];
        if ($section !== null) {
            foreach ($imported as $i) {
                try {
                    EnrollmentService::enroll($section, $i['id']);
                    $enrolled++;
                } catch (HttpException $e) {
                    $failures[] = 'Row ' . $i['row'] . ' (' . $i['name'] . '): ' . $e->getMessage();
                }
            }
        }

        return [
            'imported' => count($imported), 'duplicates' => $dupes, 'invalid' => $invalid,
            'skipped' => $dupes + $invalid, 'enrolled' => $enrolled, 'enroll_failures' => $failures, 'rows' => $rowsOut,
        ];
    }

    // ------------------------------------------------------------------ template

    public static function buildTemplate(): XlsxWriter
    {
        $x = new XlsxWriter();
        $s = $x->addSheet('Students', [
            [self::TEMPLATE_HEADERS[0], 18], [self::TEMPLATE_HEADERS[1], 22], [self::TEMPLATE_HEADERS[2], 26],
            [self::TEMPLATE_HEADERS[3], 20], [self::TEMPLATE_HEADERS[4], 11], [self::TEMPLATE_HEADERS[5], 24],
        ]);
        $s->row(['114499220002', 'ACEBRON', 'LAMARCUS', 'BURCE', 'M', XlsxWriter::date('2017-09-28')]);
        $s->row(['114499220014', 'ALLAM', 'HARMONY', 'BIGATA', 'F', XlsxWriter::date('2017-03-23')]);

        $h = $x->addSheet('Instructions', [['Column', 26], ['What to enter', 80]]);
        $h->wrap(1);
        $h->row(['LRN (required)', 'Learner Reference Number — exactly 12 digits. Format the column as Text so a leading 0 is kept.']);
        $h->row(['Last Name (required)', 'Family name, e.g. DELA CRUZ.']);
        $h->row(['First Name (required)', 'Given name(s), e.g. JUAN MIGUEL.']);
        $h->row(['Middle Name', 'Optional. Leave blank if none.']);
        $h->row(['Sex (M/F) (required)', 'M or F (Male / Female also accepted).']);
        $h->row(['Birth Date', 'Optional but recommended (Age on the SF9 is calculated from it). Use mm/dd/yyyy, e.g. 09/28/2017.']);
        $h->row(['', '']);
        $h->row(['Replace the two sample rows', 'Delete the sample rows on the Students sheet, then paste your own learners starting at row 2.']);
        $h->row(['SF1 files', 'The DepEd School Form 1 (SF1) .xls exported from LIS can be uploaded as-is — you do not need this template for it.']);
        $h->row(['Existing learners', 'A learner whose LRN is already in the system is skipped, never overwritten.']);
        return $x;
    }

    // ------------------------------------------------------------------ header / context detection

    /** @return array{0:int,1:array<string,int>} header row number and column map */
    private static function findHeader(array $rows): array
    {
        $seen = 0;
        foreach ($rows as $rn => $cells) {
            if (++$seen > 40) { break; }
            $map = [];
            foreach ($cells as $ci => $text) {
                $k = self::headerKey($text);
                if ($k !== null && !isset($map[$k])) { $map[$k] = $ci; }
            }
            $hasName = isset($map['name']) || (isset($map['last']) && isset($map['first']));
            if (isset($map['lrn']) && $hasName) {
                $missing = [];
                if (!isset($map['sex'])) { $missing[] = 'Sex'; }
                if ($missing) {
                    throw new HttpException(422, 'Required column missing: ' . implode(', ', $missing) . '. Expected columns: ' . implode(', ', self::TEMPLATE_HEADERS) . '.', ['errors' => ['columns' => 'Missing: ' . implode(', ', $missing)]]);
                }
                return [$rn, $map];
            }
        }
        throw new HttpException(422,
            'Could not find the column headings. The first rows must contain "LRN", the learner name (either "Last Name" + "First Name" columns, or one "Name" column) and "Sex". ' .
            'Expected columns: ' . implode(', ', self::TEMPLATE_HEADERS) . '. Download the template to see the format.',
            ['errors' => ['columns' => 'Headings not found.']]);
    }

    private static function headerKey(string $text): ?string
    {
        $t = preg_replace('/[^a-z0-9]+/', '', mb_strtolower($text)) ?? '';
        if ($t === '') { return null; }
        if ($t === 'lrn' || str_starts_with($t, 'learnerreference')) { return 'lrn'; }
        if (in_array($t, ['lastname', 'surname', 'familyname'], true)) { return 'last'; }
        if (in_array($t, ['firstname', 'givenname', 'givenname'], true)) { return 'first'; }
        if (in_array($t, ['middlename', 'middleinitial', 'mi'], true)) { return 'middle'; }
        if ($t === 'name' || $t === 'learnername' || $t === 'fullname' || (str_starts_with($t, 'name') && str_contains($t, 'last') && str_contains($t, 'first'))) { return 'name'; }
        if ($t === 'sex' || $t === 'gender' || str_starts_with($t, 'sexmf') || str_starts_with($t, 'sexmale')) { return 'sex'; }
        if (in_array($t, ['birthdate', 'birthday', 'dateofbirth', 'dob', 'bdate'], true) || str_starts_with($t, 'birthdate')) { return 'birth'; }
        return null;
    }

    /** Grade level / section / school year written in the heading of an SF1 (used to suggest a section). */
    private static function detectContext(array $rows, int $headerRow): array
    {
        $ctx = [];
        $labels = ['gradelevel' => 'grade', 'section' => 'section', 'schoolyear' => 'school_year'];
        foreach ($rows as $rn => $cells) {
            if ($rn >= $headerRow) { break; }
            foreach ($cells as $ci => $text) {
                $k = preg_replace('/[^a-z]+/', '', mb_strtolower($text)) ?? '';
                if (isset($labels[$k]) && !isset($ctx[$labels[$k]])) {
                    for ($j = $ci + 1; $j < count($cells); $j++) {
                        if (trim($cells[$j]) !== '') { $ctx[$labels[$k]] = trim($cells[$j]); break; }
                    }
                }
            }
        }
        if (isset($ctx['grade']) && preg_match('/(\d+)/', $ctx['grade'], $m)) { $ctx['grade_level'] = (int) $m[1]; }
        return $ctx;
    }

    // ------------------------------------------------------------------ field parsing

    /** @return array{last:string,first:string,middle:string,full:string,parts:int} */
    private static function splitName(string $combined, string $last, string $first, string $middle, bool $useCombined): array
    {
        $clean = static function (string $s): string {
            $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
            return in_array($s, ['-', '.', '--', 'N/A', 'n/a', 'NONE', 'none'], true) ? '' : $s;
        };
        $parts = 0;
        if ($useCombined && $combined !== '') {
            $bits = array_values(array_filter(array_map($clean, explode(',', $combined)), static fn($b) => $b !== ''));
            $parts = count($bits);
            $last = $bits[0] ?? '';
            $first = $bits[1] ?? '';
            $middle = implode(' ', array_slice($bits, 2));
        } else {
            $last = $clean($last); $first = $clean($first); $middle = $clean($middle);
            $parts = ($last !== '' ? 1 : 0) + ($first !== '' ? 1 : 0);
        }
        return ['last' => $last, 'first' => $first, 'middle' => $middle, 'full' => trim($last . $first . $middle . ($useCombined ? $combined : '')), 'parts' => $parts];
    }

    private static function parseSex(string $v): ?string
    {
        $t = mb_strtolower(trim($v));
        if (in_array($t, ['m', 'male', 'boy', 'lalaki'], true)) { return 'Male'; }
        if (in_array($t, ['f', 'female', 'girl', 'babae'], true)) { return 'Female'; }
        return null;
    }

    /** yyyy-mm-dd, mm/dd/yyyy, mm-dd-yyyy (the SF1 way), dd/mm/yyyy when the first number is > 12, "Sep 28, 2017". */
    private static function parseDate(string $v): ?string
    {
        $v = trim($v);
        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})/', $v, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/', $v, $m)) {
            [$a, $b, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            if ($a > 12 && $b <= 12) { [$d, $mo] = [$a, $b]; } else { [$mo, $d] = [$a, $b]; }
        } elseif (($ts = strtotime($v)) !== false && preg_match('/[a-z]{3}/i', $v)) {
            return date('Y-m-d', $ts);
        } else {
            return null;
        }
        return checkdate($mo, $d, $y) && $y >= 1900 ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    private static function norm(string $s): string
    {
        $s = mb_strtoupper(trim($s));
        $s = preg_replace('/[^\p{L}\p{N}]+/u', '', $s) ?? '';
        return strtr($s, ['Ñ' => 'N']);
    }

    private static function nameKey(string $last, string $first): string
    {
        return self::norm($last) . '|' . self::norm($first);
    }

    private static function display(array $r): string
    {
        return trim(($r['last_name'] ?? '') . ', ' . ($r['first_name'] ?? '') . ' ' . ($r['middle_name'] ?? ''), ' ,');
    }

    private static function clip(string $s): string
    {
        return mb_strlen($s) > 30 ? mb_substr($s, 0, 30) . '…' : $s;
    }
}
