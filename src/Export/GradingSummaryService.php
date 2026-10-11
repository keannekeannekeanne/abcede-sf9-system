<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../Calculations/GradeCalculator.php';
require_once __DIR__ . '/../Spreadsheet/XlsxBook.php';

/**
 * "Grading Summary" workbook for one section: the school's three-term grading sheet as an Excel file.
 *
 *   Input | 1st Term | 2nd Term | 3rd Term | Final Summary | Helper (hidden)
 *
 * READ-ONLY: only SELECT statements; nothing is changed in the database.
 *
 * The learner is ONE "NAME" column ("LAST, FIRST MIDDLE") - there are no separate last/first/middle columns.
 * Grades are the values as saved. MAPEH, Subject/General Average, Rank, Descriptor, Award, Final Grade and the
 * failing-grades table are written as LIVE EXCEL FORMULAS (they recalculate if someone edits a grade in the
 * file) and their current values are cached in the file. Those cached values come from GradeCalculator, the
 * same rules the SF9 uses, so the file and the SF9 print-out agree.
 *
 * Layout (term sheets):  A NO. | B NAME | C GENDER | D Filipino | E English | F Mathematics | G Science |
 *   H Araling Panlipunan | I EPP/TLE | J MAPEH (formula) | K Music & Arts | L PE & Health | M GMRC |
 *   N Subject Average | O General Average | P Rank | Q Descriptors | R Academic Excellence Award
 */
final class GradingSummaryService
{
    /** Subject name (as in the `subjects` table) => term-sheet column. MAPEH (J) is calculated, never stored. */
    private const SUBJECT_COLS = [
        'Filipino' => 'D', 'English' => 'E', 'Mathematics' => 'F', 'Science' => 'G', 'Araling Panlipunan' => 'H',
        'EPP/TLE' => 'I', 'Music and Arts' => 'K', 'Physical Education and Health' => 'L', 'GMRC' => 'M',
    ];
    /** The 8 learning areas -> term-sheet column (MAPEH = J). Order = Final Summary order. */
    private const AREAS = [
        'Filipino' => 'D', 'English' => 'E', 'Mathematics' => 'F', 'Science' => 'G', 'Araling Panlipunan' => 'H',
        'EPP/TLE' => 'I', 'MAPEH' => 'J', 'GMRC' => 'M',
    ];
    private const AREA_HEADERS = [
        'Filipino' => 'FILIPINO', 'English' => 'ENGLISH', 'Mathematics' => 'MATHEMATICS', 'Science' => 'SCIENCE',
        'Araling Panlipunan' => 'ARALING PANLIPUNAN', 'EPP/TLE' => 'EPP/TLE', 'MAPEH' => 'MAPEH', 'GMRC' => 'GMRC',
    ];
    private const TERMS = ['1' => ['1st Term', 'FIRST'], '2' => ['2nd Term', 'SECOND'], '3' => ['3rd Term', 'THIRD']];
    private const NO_MIDDLE = ['-', '–', '—', 'N/A', 'NA', 'NONE'];
    private const PASSING = 75;

    // =================================================================================================================
    // 1. Read the data
    // =================================================================================================================

    /**
     * @param array $section from Access::requireSection() (id, grade_level, section_name, school_year_id, year_label, adviser_name)
     * @return array data for build()
     */
    public static function collect(array $section, string $principalName): array
    {
        $pdo      = get_db();
        $sectionId = (int) $section['id'];
        $yearId    = (int) $section['school_year_id'];

        $mt = $pdo->prepare('SELECT u.first_name, u.last_name FROM sections sec LEFT JOIN users u ON u.id = sec.master_teacher_id WHERE sec.id = ?');
        $mt->execute([$sectionId]);
        $m = $mt->fetch() ?: [];

        $bands = $pdo->query('SELECT min_score, max_score, descriptor FROM descriptor_bands ORDER BY min_score')->fetchAll();

        // Active learners only (the same roster the class charts, ranking and report cards use); boys first, then girls, A-Z.
        $st = $pdo->prepare(
            "SELECT s.id, s.last_name, s.first_name, s.middle_name, s.gender
             FROM enrollments e JOIN students s ON s.id = e.student_id
             WHERE e.section_id = ? AND e.school_year_id = ? AND e.status = 'active'
             ORDER BY FIELD(s.gender, 'Male', 'Female'), s.last_name, s.first_name, s.middle_name, s.id"
        );
        $st->execute([$sectionId, $yearId]);
        $learners = [];
        foreach ($st->fetchAll() as $r) {
            $learners[(int) $r['id']] = ['name' => self::displayName($r), 'gender' => (string) $r['gender'], 'raw' => ['1' => [], '2' => [], '3' => []]];
        }

        if ($learners) {
            $in = implode(',', array_fill(0, count($learners), '?'));
            $g  = $pdo->prepare(
                "SELECT g.student_id, sub.subject_name, g.trimester, g.grade_value, g.status
                 FROM grades g JOIN subjects sub ON sub.id = g.subject_id
                 WHERE g.school_year_id = ? AND g.student_id IN ($in)"
            );
            $g->execute(array_merge([$yearId], array_keys($learners)));
            foreach ($g->fetchAll() as $r) {
                $learners[(int) $r['student_id']]['raw'][$r['trimester']][$r['subject_name']] = [
                    'v' => $r['grade_value'] !== null ? (float) $r['grade_value'] : null,
                    's' => (string) $r['status'],
                ];
            }
        }

        return [
            'grade_level'    => (int) $section['grade_level'],
            'section_name'   => (string) $section['section_name'],
            'year_label'     => (string) $section['year_label'],
            'adviser'        => mb_strtoupper(trim((string) ($section['adviser_name'] ?? ''))),
            'master_teacher' => mb_strtoupper(trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? ''))),
            'principal'      => $principalName,
            'bands'          => $bands,
            'learners'       => array_values($learners),
        ];
    }

    /** "LAST, FIRST MIDDLE" in capitals, extra spaces removed; a middle name of "-" (none) is left out. */
    public static function displayName(array $s): string
    {
        $clean = static fn($v): string => trim((string) preg_replace('/\s+/u', ' ', (string) $v));
        $last = $clean($s['last_name'] ?? ''); $first = $clean($s['first_name'] ?? ''); $mid = $clean($s['middle_name'] ?? '');
        if (in_array(mb_strtoupper($mid), self::NO_MIDDLE, true)) { $mid = ''; }
        return mb_strtoupper(trim($last . ', ' . $first . ($mid !== '' ? ' ' . $mid : ''), ' ,'));
    }

    public static function filename(array $data, ?DateTimeImmutable $now = null): string
    {
        $now = $now ?? new DateTimeImmutable('now');
        return 'Grading-Summary_Grade' . $data['grade_level'] . '-' . $data['section_name'] . '_' . $data['year_label'] . '_' . $now->format('Ymd-His') . '.xlsx';
    }

    // =================================================================================================================
    // 2. Calculate (cached values for the formulas) - rules come from GradeCalculator, same as the SF9
    // =================================================================================================================

    /**
     * @return array{cells:array,terms:array,final:array} per learner index:
     *   cells[i][term][subject] => number|'NLPA'|'T/O'|null
     *   terms[term][i] => [mapeh, areas, avg, ga, rank, descriptor, award]   final[i] => [finals, ga, rank, descriptor, award, nlpa]
     */
    public static function compute(array $data): array
    {
        $bands = $data['bands'];
        $cells = []; $terms = ['1' => [], '2' => [], '3' => []];

        foreach ($data['learners'] as $i => $l) {
            foreach (['1', '2', '3'] as $t) {
                $row = [];
                foreach (array_keys(self::SUBJECT_COLS) as $sub) {
                    $g = $l['raw'][$t][$sub] ?? null;
                    if ($g === null) { $row[$sub] = null; }
                    elseif ($g['s'] === 'nlpa') { $row[$sub] = 'NLPA'; }
                    elseif ($g['v'] !== null) { $row[$sub] = $g['v']; }
                    elseif ($g['s'] === 'transferred_out') { $row[$sub] = 'T/O'; }
                    else { $row[$sub] = null; }
                }
                $cells[$i][$t] = $row;
                $num = static fn($v): ?float => (is_int($v) || is_float($v)) ? (float) $v : null;

                $areas = [];
                foreach (self::AREAS as $area => $col) {
                    $areas[$area] = $area === 'MAPEH'
                        ? GradeCalculator::mapeh($num($row['Music and Arts']), $num($row['Physical Education and Health']))
                        : $num($row[$area]);
                }
                $nlpa = in_array('NLPA', $row, true);
                $avg  = GradeCalculator::subjectAverage($areas);
                $ga   = GradeCalculator::generalAverage($avg);
                $terms[$t][$i] = ['areas' => $areas, 'avg' => $avg, 'ga' => $ga, 'nlpa' => $nlpa, 'rank' => null, 'descriptor' => '', 'award' => ''];
            }
        }
        foreach (['1', '2', '3'] as $t) {
            $all = array_values(array_filter(array_column($terms[$t], 'ga'), static fn($v) => $v !== null));
            foreach ($terms[$t] as $i => &$r) {
                $r['rank']       = GradeCalculator::rank($r['ga'], $all);
                $r['descriptor'] = $r['nlpa'] ? 'NLPA' : (string) (GradeCalculator::descriptor($r['ga'], $bands) ?? '');
                $r['award']      = $r['ga'] !== null && GradeCalculator::academicExcellenceAward($r['ga'], $r['areas']) ? '✔' : '';
            }
            unset($r);
        }

        $final = [];
        foreach ($data['learners'] as $i => $l) {
            $finals = []; $nlpa = false;
            foreach (self::AREAS as $area => $col) {
                $finals[$area] = GradeCalculator::finalSubjectGrade([
                    1 => $terms['1'][$i]['areas'][$area], 2 => $terms['2'][$i]['areas'][$area], 3 => $terms['3'][$i]['areas'][$area],
                ]);
            }
            foreach (['1', '2', '3'] as $t) { $nlpa = $nlpa || $terms[$t][$i]['nlpa']; }
            $ga = GradeCalculator::finalGeneralAverage($finals);
            $final[$i] = ['finals' => $finals, 'ga' => $ga, 'nlpa' => $nlpa, 'rank' => null, 'descriptor' => '', 'award' => ''];
        }
        $all = array_values(array_filter(array_column($final, 'ga'), static fn($v) => $v !== null));
        foreach ($final as $i => &$r) {
            $r['rank']       = GradeCalculator::rank($r['ga'], $all);
            $r['descriptor'] = $r['nlpa'] ? 'NLPA' : (string) (GradeCalculator::descriptor($r['ga'], $bands) ?? '');
            $r['award']      = $r['ga'] !== null && GradeCalculator::academicExcellenceAward($r['ga'], $r['finals']) ? '✔' : '';
        }
        unset($r);

        return ['cells' => $cells, 'terms' => $terms, 'final' => $final];
    }

    /** Value of the MAPEH cell: 'NLPA' when either component is NLPA, else the rounded average (or '' while incomplete). */
    private static function mapehCell(array $raw, array $res)
    {
        if (($raw['Music and Arts'] ?? null) === 'NLPA' || ($raw['Physical Education and Health'] ?? null) === 'NLPA') { return 'NLPA'; }
        return $res['areas']['MAPEH'] ?? '';
    }

    // =================================================================================================================
    // 3. Build the workbook
    // =================================================================================================================

    public static function build(array $data): XlsxBook
    {
        $calc = self::compute($data);
        $n    = count($data['learners']);
        $first = 8;                              // first learner row on Input and the term sheets
        $last  = $first + max($n, 1) - 1;        // last learner row
        $b = new XlsxBook();

        // ---- styles (colours/fonts follow the school's Excel grading sheet) ----
        $S = [
            'title'   => $b->style(['bold' => true, 'color' => 'FFFFFF', 'fill' => '1F4E78', 'h' => 'center', 'v' => 'center']),
            'sub'     => $b->style(['bold' => true, 'fill' => 'B4C7E7', 'h' => 'center', 'v' => 'center']),
            'head'    => $b->style(['bold' => true, 'color' => 'FFFFFF', 'fill' => '1F4E78', 'h' => 'center', 'v' => 'center', 'wrap' => true, 'border' => true]),
            'lbl'     => $b->style(['bold' => true, 'v' => 'center']),
            'lblR'    => $b->style(['bold' => true, 'h' => 'right', 'v' => 'center']),
            'val'     => $b->style(['h' => 'center', 'v' => 'center']),
            'valB'    => $b->style(['bold' => true, 'v' => 'center']),
            'no'      => $b->style(['h' => 'center', 'v' => 'center', 'border' => true]),
            'name'    => $b->style(['v' => 'center', 'border' => true]),
            'cell'    => $b->style(['h' => 'center', 'v' => 'center', 'border' => true]),
            'avg'     => $b->style(['h' => 'center', 'v' => 'center', 'border' => true, 'nf' => '0.00']),
            'gaY'     => $b->style(['h' => 'center', 'v' => 'center', 'border' => true, 'fill' => 'FFD966', 'nf' => '0']),
            'yellow'  => $b->style(['h' => 'center', 'v' => 'center', 'border' => true, 'fill' => 'FFD966']),
            'green'   => $b->style(['h' => 'center', 'v' => 'center', 'border' => true, 'fill' => 'C6E0B4']),
            'blue'    => $b->style(['h' => 'center', 'v' => 'center', 'border' => true, 'fill' => 'B4C7E7']),
            'final'   => $b->style(['h' => 'center', 'v' => 'center', 'border' => true, 'fill' => 'D9EAF7']),
            'clsLbl'  => $b->style(['bold' => true, 'h' => 'center', 'v' => 'center', 'border' => true]),
            'clsAvg'  => $b->style(['bold' => true, 'h' => 'center', 'v' => 'center', 'border' => true, 'nf' => '0.00']),
            'fHead'   => $b->style(['bold' => true, 'fill' => 'B4C7E7', 'h' => 'center', 'v' => 'center', 'wrap' => true, 'border' => true]),
            'fCell'   => $b->style(['bold' => true, 'h' => 'center', 'v' => 'center', 'wrap' => true, 'border' => true]),
            'sig'     => $b->style(['h' => 'center', 'v' => 'center']),
            'sigName' => $b->style(['bold' => true, 'h' => 'center', 'v' => 'center']),
            'plain'   => $b->style(['h' => 'center']),
        ];

        // ---- Input -------------------------------------------------------------------------------------------------
        $in = $b->addSheet('Input');
        $in->width('A', 7)->width('B', 44)->width('C', 14)->width('D', 16)->width('E', 16);
        $in->merge('A1:E1', $S['title'])->put('A1', 'THREE-TERM GRADING SHEET — LEARNER INFORMATION', $S['title']);
        foreach ([[2, 'GRADE & SECTION:', $data['grade_level'] . '-' . mb_strtoupper($data['section_name'])], [3, 'ADVISER:', $data['adviser']],
                  [4, 'MASTER TEACHER:', $data['master_teacher']], [5, 'SCHOOL YEAR:', $data['year_label']]] as [$r, $label, $value]) {
            $in->merge("A$r:B$r", $S['lblR'])->put("A$r", $label, $S['lblR']);
            $in->merge("C$r:E$r", $S['val'])->put("C$r", $value, $S['val']);
        }
        $in->height(7, 24);
        foreach (['A' => 'NO.', 'B' => 'NAME', 'C' => 'GENDER'] as $c => $h) { $in->put($c . '7', $h, $S['head']); }
        foreach ($data['learners'] as $i => $l) {
            $r = $first + $i;
            $in->put("A$r", $i + 1, $S['no'])->put("B$r", $l['name'], $S['name'])->put("C$r", $l['gender'], $S['name'])->height($r, 19.5);
        }

        // ---- the three term sheets ---------------------------------------------------------------------------------
        $heads = ['NO.', 'NAME', 'GENDER', 'FILIPINO', 'ENGLISH', 'MATHEMATICS', 'SCIENCE', 'ARALING PANLIPUNAN', 'EPP/TLE', 'MAPEH',
                  'MUSIC & ARTS', 'PE & HEALTH', 'GMRC', 'SUBJECT AVERAGE', 'GENERAL AVERAGE', 'RANK', 'DESCRIPTORS', 'ACADEMIC EXCELLENCE AWARD'];
        $widths = ['A' => 6, 'B' => 40, 'C' => 11, 'D' => 11, 'E' => 11, 'F' => 15, 'G' => 11, 'H' => 16, 'I' => 11, 'J' => 11, 'K' => 12,
                   'L' => 12, 'M' => 11, 'N' => 12, 'O' => 12, 'P' => 8, 'Q' => 15, 'R' => 22];
        $helperEnd = 1 + count($data['bands']);

        foreach (self::TERMS as $t => [$sheetName, $ordinal]) {
            $s = $b->addSheet($sheetName)->repeatRows('$7:$7');
            foreach ($widths as $c => $w) { $s->width($c, $w); }
            for ($r = 1; $r <= 6; $r++) { $s->height($r, 13.5); }
            $s->height(7, 48);

            $s->merge('A1:R2', $S['title'])->put('A1', $ordinal . ' TERM SUMMARY GRADING SHEET (GRADES 4–6)', $S['title']);
            $s->merge('A3:R3', $S['sub'])->formula('A3', 'Input!C5', $data['year_label'], $S['sub']);
            $s->merge('A5:B5', $S['lbl'])->put('A5', 'GRADE & SECTION:', $S['lbl']);
            $s->merge('C5:G5', $S['valB'])->formula('C5', 'Input!C2', $data['grade_level'] . '-' . mb_strtoupper($data['section_name']), $S['valB']);
            $s->merge('K5:L5', $S['lbl'])->put('K5', 'ADVISER:', $S['lbl']);
            $s->merge('M5:R5', $S['valB'])->formula('M5', 'Input!C3', $data['adviser'], $S['valB']);
            foreach ($heads as $k => $h) { $s->put(XlsxBookSheet::colName($k + 1) . '7', $h, $S['head']); }

            foreach ($data['learners'] as $i => $l) {
                $r = $first + $i; $ir = $first + $i;           // learner i is on row $first+$i of Input and of every term sheet
                $res = $calc['terms'][$t][$i]; $raw = $calc['cells'][$i][$t];
                $s->height($r, 19.5);
                $s->formula("A$r", "IF(Input!A$ir=\"\",\"\",Input!A$ir)", $i + 1, $S['no']);
                $s->formula("B$r", "IF(Input!B$ir=\"\",\"\",Input!B$ir)", $l['name'], $S['name']);
                $s->formula("C$r", "IF(Input!C$ir=\"\",\"\",Input!C$ir)", $l['gender'], $S['name']);
                foreach (self::SUBJECT_COLS as $sub => $col) { $s->put($col . $r, $raw[$sub], $S['cell']); }       // grades as saved (input cells)
                $s->formula("J$r", "IF(COUNTIF(K$r:L$r,\"NLPA\")>0,\"NLPA\",IF(COUNT(K$r:L$r)<2,\"\",ROUND(AVERAGE(K$r:L$r),0)))", self::mapehCell($raw, $res), $S['cell']);
                $s->formula("N$r", "IF(COUNT(D$r:I$r,J$r,M$r)<8,\"\",AVERAGE(D$r:I$r,J$r,M$r))", $res['avg'] ?? '', $S['avg']);
                $s->formula("O$r", "IF(N$r=\"\",\"\",ROUND(N$r,0))", $res['ga'] ?? '', $S['gaY']);
                $s->formula("P$r", "IF(O$r=\"\",\"\",1+COUNTIF(\$O\$$first:\$O\$$last,\">\"&O$r))", $res['rank'] ?? '', $S['yellow']);
                $s->formula("Q$r", "IF(COUNTIF(D$r:M$r,\"NLPA\")>0,\"NLPA\",IFERROR(IF(O$r=\"\",\"\",VLOOKUP(O$r,Helper!\$A\$2:\$D\$$helperEnd,4,TRUE)),\"\"))", $res['descriptor'], $S['yellow']);
                $s->formula("R$r", "IF(O$r=\"\",\"\",IF(AND(O$r>=90,MIN(D$r:J$r,M$r)>=80),\"✔\",\"\"))", $res['award'], $S['blue']);
            }

            // Class average row
            $ca = $last + 1;
            $s->height($ca, 19.5);
            $s->merge("A$ca:C$ca", $S['clsLbl'])->put("A$ca", 'CLASS AVERAGE', $S['clsLbl']);
            $cols = ['D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O'];
            foreach ($cols as $col) {
                $vals = [];
                foreach ($data['learners'] as $i => $l) {
                    $res = $calc['terms'][$t][$i]; $raw = $calc['cells'][$i][$t];
                    $v = match ($col) {
                        'J' => $res['areas']['MAPEH'], 'N' => $res['avg'], 'O' => $res['ga'],
                        default => array_search($col, self::SUBJECT_COLS, true) !== false ? $raw[array_search($col, self::SUBJECT_COLS, true)] : null,
                    };
                    if (is_int($v) || is_float($v)) { $vals[] = (float) $v; }
                }
                $s->formula($col . $ca, "IF(COUNT($col$first:$col$last)=0,\"\",AVERAGE($col$first:$col$last))", $vals ? array_sum($vals) / count($vals) : '', $S['clsAvg']);
            }
            foreach (['P', 'Q', 'R'] as $col) { $s->put($col . $ca, null, $S['cell']); }

            // Learners with failing grades per subject
            $fr = $ca + 2;
            $s->merge("A$fr:M$fr", $S['title'])->put("A$fr", 'NUMBER OF LEARNERS WITH FAILING GRADES PER SUBJECT', $S['title']);
            $fh = $fr + 1;
            $s->height($fh, 33);
            $s->merge("A$fh:C$fh", $S['fHead'])->put("A$fh", 'GROUP', $S['fHead']);
            $failCols = ['D' => 'FILIPINO', 'E' => 'ENGLISH', 'F' => 'MATHEMATICS', 'G' => 'SCIENCE', 'H' => 'ARALING PANLIPUNAN', 'I' => 'EPP/TLE',
                         'J' => 'MAPEH', 'K' => 'MUSIC & ARTS', 'L' => 'PE & HEALTH', 'M' => 'GMRC'];
            foreach ($failCols as $col => $label) { $s->put($col . $fh, $label, $S['fHead']); }
            $fm = $fh + 1; $ff = $fh + 2; $ft = $fh + 3;
            $counts = ['Male' => [], 'Female' => []];
            foreach ($failCols as $col => $label) {
                foreach (['Male', 'Female'] as $g) { $counts[$g][$col] = 0; }
                foreach ($data['learners'] as $i => $l) {
                    $res = $calc['terms'][$t][$i]; $raw = $calc['cells'][$i][$t];
                    $v = $col === 'J' ? $res['areas']['MAPEH'] : $raw[array_search($col, self::SUBJECT_COLS, true)];
                    if ((is_int($v) || is_float($v)) && $v < self::PASSING && isset($counts[$l['gender']])) { $counts[$l['gender']][$col]++; }
                }
            }
            foreach ([['MALE', 'Male', $fm], ['FEMALE', 'Female', $ff]] as [$label, $g, $r]) {
                $s->merge("A$r:C$r", $S['fCell'])->put("A$r", $label, $S['fCell']);
                foreach ($failCols as $col => $_) {
                    $s->formula($col . $r, "COUNTIFS(\$C\$$first:\$C\$$last,\"$g\",$col\$$first:$col\$$last,\"<" . self::PASSING . "\")", $counts[$g][$col], $S['fCell']);
                }
            }
            $s->merge("A$ft:C$ft", $S['fCell'])->put("A$ft", 'TOTAL', $S['fCell']);
            foreach ($failCols as $col => $_) { $s->formula($col . $ft, "SUM($col$fm:$col$ff)", $counts['Male'][$col] + $counts['Female'][$col], $S['fCell']); }

            // Signatures
            $sg = $ft + 3;
            $s->merge("A$sg:C$sg", $S['sig'])->put("A$sg", 'Submitted by:', $S['sig']);
            $s->merge("F$sg:J$sg", $S['sig'])->put("F$sg", 'Checked by:', $S['sig']);
            $s->merge("N$sg:R$sg", $S['sig'])->put("N$sg", 'Monitored by:', $S['sig']);
            $nm = $sg + 2; $tt = $nm + 1;
            $s->merge("A$nm:C$nm", $S['sigName'])->formula("A$nm", 'Input!C3', $data['adviser'], $S['sigName']);
            $s->merge("F$nm:J$nm", $S['sigName'])->formula("F$nm", 'Input!C4', $data['master_teacher'], $S['sigName']);
            $s->merge("N$nm:R$nm", $S['sigName'])->put("N$nm", $data['principal'], $S['sigName']);
            $s->merge("A$tt:C$tt", $S['sig'])->put("A$tt", 'Teacher', $S['sig']);
            $s->merge("F$tt:J$tt", $S['sig'])->put("F$tt", 'Master Teacher', $S['sig']);
            $s->merge("N$tt:R$tt", $S['sig'])->put("N$tt", 'Principal', $S['sig']);
        }

        // ---- Final Summary -----------------------------------------------------------------------------------------
        $f = $b->addSheet('Final Summary')->repeatRows('$7:$8');
        $fFirst = 9; $fLast = $fFirst + max($n, 1) - 1;
        $f->width('A', 6)->width('B', 40);
        for ($c = 3; $c <= 34; $c++) { $f->width($c, 9); }                  // C..AH : 8 areas x 4 columns
        $f->width('AI', 12)->width('AJ', 8)->width('AK', 19.4)->width('AL', 23);
        for ($r = 1; $r <= 6; $r++) { $f->height($r, 13.5); }
        $f->height(7, 33.75)->height(8, 33.75);
        $f->merge('A1:AL2', $S['title'])->put('A1', 'FINAL CONSOLIDATED THREE-TERM GRADING SHEET', $S['title']);
        $f->merge('A3:AL3', $S['sub'])->formula('A3', 'Input!C5', $data['year_label'], $S['sub']);
        $f->merge('A5:B5', $S['lbl'])->put('A5', 'GRADE & SECTION:', $S['lbl']);
        $f->merge('C5:H5', $S['valB'])->formula('C5', 'Input!C2', $data['grade_level'] . '-' . mb_strtoupper($data['section_name']), $S['valB']);
        $f->merge('I5:J5', $S['lbl'])->put('I5', 'ADVISER:', $S['lbl']);
        $f->merge('K5:P5', $S['valB'])->formula('K5', 'Input!C3', $data['adviser'], $S['valB']);
        $f->merge('A7:A8', $S['head'])->put('A7', 'NO.', $S['head']);
        $f->merge('B7:B8', $S['head'])->put('B7', 'NAME', $S['head']);
        $areaStart = [];                                                      // area => first column index
        $c = 3;
        foreach (self::AREAS as $area => $col) {
            $areaStart[$area] = $c;
            $a = XlsxBookSheet::colName($c); $z = XlsxBookSheet::colName($c + 3);
            $f->merge("$a" . '7:' . "$z" . '7', $S['head'])->put($a . '7', self::AREA_HEADERS[$area], $S['head']);
            foreach (['1st Term', '2nd Term', '3rd Term', 'Final Grade'] as $k => $lab) { $f->put(XlsxBookSheet::colName($c + $k) . '8', $lab, $S['head']); }
            $c += 4;
        }
        foreach (['AI' => 'GENERAL AVERAGE', 'AJ' => 'RANK', 'AK' => 'DESCRIPTORS', 'AL' => 'ACADEMIC EXCELLENCE AWARD'] as $col => $lab) {
            $f->merge($col . '7:' . $col . '8', $S['head'])->put($col . '7', $lab, $S['head']);
        }
        foreach ($data['learners'] as $i => $l) {
            $r = $fFirst + $i; $ir = $first + $i;
            $res = $calc['final'][$i];
            $f->height($r, 19.5);
            $f->formula("A$r", "IF(Input!A$ir=\"\",\"\",Input!A$ir)", $i + 1, $S['no']);
            $f->formula("B$r", "IF(Input!B$ir=\"\",\"\",Input!B$ir)", $l['name'], $S['name']);
            $finalRefs = [];
            foreach (self::AREAS as $area => $termCol) {
                $c0 = $areaStart[$area];
                $cols3 = [XlsxBookSheet::colName($c0), XlsxBookSheet::colName($c0 + 1), XlsxBookSheet::colName($c0 + 2)];
                $fin = XlsxBookSheet::colName($c0 + 3);
                foreach (['1', '2', '3'] as $k => $t) {
                    $sheet = self::TERMS[$t][0];
                    $raw = $calc['cells'][$i][$t];
                    $cached = $area === 'MAPEH' ? self::mapehCell($raw, $calc['terms'][$t][$i]) : ($raw[$area] ?? '');
                    $f->formula($cols3[$k] . $r, "IF('$sheet'!$termCol$ir=\"\",\"\",'$sheet'!$termCol$ir)", $cached, $S['cell']);
                }
                $f->formula($fin . $r, "IF(COUNT({$cols3[0]}$r:{$cols3[2]}$r)<3,\"\",ROUND(AVERAGE({$cols3[0]}$r:{$cols3[2]}$r),0))", $res['finals'][$area] ?? '', $S['final']);
                $finalRefs[] = $fin . $r;
            }
            $refs = implode(',', $finalRefs);
            $f->formula("AI$r", "IF(COUNT($refs)<8,\"\",ROUND(AVERAGE($refs),0))", $res['ga'] ?? '', $S['yellow']);
            $f->formula("AJ$r", "IF(AI$r=\"\",\"\",1+COUNTIF(\$AI\$$fFirst:\$AI\$$fLast,\">\"&AI$r))", $res['rank'] ?? '', $S['green']);
            $f->formula("AK$r", "IF(COUNTIF(C$r:AH$r,\"NLPA\")>0,\"NLPA\",IFERROR(IF(AI$r=\"\",\"\",VLOOKUP(AI$r,Helper!\$A\$2:\$D\$$helperEnd,4,TRUE)),\"\"))", $res['descriptor'], $S['green']);
            $f->formula("AL$r", "IF(AI$r=\"\",\"\",IF(AND(AI$r>=90,MIN($refs)>=80),\"✔\",\"\"))", $res['award'], $S['blue']);
        }

        // ---- Helper (hidden): descriptor bands, straight from the system's descriptor_bands table ---------------------
        $h = $b->addSheet('Helper')->hidden();
        $h->width('A', 8)->width('B', 4)->width('C', 8)->width('D', 16);
        $h->merge('A1:D1', $S['lbl'])->put('A1', 'Descriptors', $S['lbl']);
        foreach (array_values($data['bands']) as $k => $band) {
            $r = $k + 2;
            $h->put("A$r", (int) $band['min_score'], $S['plain'])->put("B$r", '-', $S['plain'])->put("C$r", (int) $band['max_score'], $S['plain'])->put("D$r", (string) $band['descriptor'], $S['plain']);
        }

        return $b;
    }
}
