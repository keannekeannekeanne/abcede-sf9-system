<?php
require_once __DIR__ . '/../src/Http/Http.php';
Page::boot();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Auth/Auth.php';
require_once __DIR__ . '/../src/Auth/Csrf.php';
require_once __DIR__ . '/../src/Auth/Access.php';
require_once __DIR__ . '/../src/SF9/SF9DataService.php';
require_once __DIR__ . '/../src/Settings/Settings.php';

Auth::requireLogin();
$pdo = get_db();

$studentId    = (int) ($_GET['student_id'] ?? 0);
$schoolYearId = (int) ($_GET['school_year_id'] ?? 0);

// Server-side check: find the section the student is enrolled in for that year, then require
// that the logged-in teacher is its adviser or teaches a subject in it (Access::requireSection).
$enr = $pdo->prepare('SELECT section_id FROM enrollments WHERE student_id = ? AND school_year_id = ? LIMIT 1');
$enr->execute([$studentId, $schoolYearId]);
$sectionId = (int) $enr->fetchColumn();
if ($sectionId === 0) {
    throw new HttpException(404, 'That student is not enrolled for the selected school year.');
}
$secInfo = Access::requireSection($sectionId);
$canEditSf9Extras = $secInfo['is_adviser'] || $secInfo['is_admin'] || !empty($secInfo['my_assignments']);

try {
    $data = SF9DataService::buildForStudent($studentId, $schoolYearId);
} catch (RuntimeException $e) {
    throw new HttpException(404, $e->getMessage());
}

// Predefined SF9 comment choices (requirement 5.2 — a teacher may pick one
// of these OR type a custom comment; both are editable/saveable below).
// Tolerate a database that has not had migration 003 applied yet: printing the
// SF9 must never fail just because the optional comment presets are missing.
$remarkSets = [];          // 'A'..'E' => ['title' => ..., 'items' => [['code'=>'A1','text'=>...], ...]]; '' => older general comments
$commentOptions = [];
try {
    $commentOptions = $pdo->query('SELECT id, comment_text, section_code, code FROM sf9_comment_options WHERE is_active = 1 ORDER BY sort_order')->fetchAll();
    foreach ($pdo->query('SELECT code, title FROM sf9_remark_sections ORDER BY sort_order')->fetchAll() as $r) {
        $remarkSets[$r['code']] = ['title' => $r['title'], 'items' => []];
    }
} catch (PDOException $e) {
    try {   // database without migration 004: the original flat list
        $commentOptions = $pdo->query('SELECT id, comment_text FROM sf9_comment_options WHERE is_active = 1 ORDER BY sort_order')->fetchAll();
    } catch (PDOException $e2) {
        $commentOptions = [];
    }
}
foreach ($commentOptions as $opt) {
    $code = $opt['section_code'] ?? null;
    if ($code !== null && isset($remarkSets[$code])) {
        $remarkSets[$code]['items'][] = ['code' => (string) $opt['code'], 'text' => $opt['comment_text']];
    } else {
        $remarkSets['']['title'] = 'General';
        $remarkSets['']['items'][] = ['code' => '', 'text' => $opt['comment_text']];
    }
}
if (isset($remarkSets[''])) {   // keep the older general comments last
    $general = $remarkSets['']; unset($remarkSets['']); $remarkSets[''] = $general;
}

// The Section (A-E) each saved term remark was written under, and the SF9-only age override (both NULL before migration 004).
$savedRemarkSection = ['1' => '', '2' => '', '3' => ''];
try {
    $q = $pdo->prepare('SELECT trimester, remark_section FROM teacher_remarks WHERE student_id = ? AND school_year_id = ?');
    $q->execute([$studentId, $schoolYearId]);
    foreach ($q->fetchAll() as $r) { $savedRemarkSection[$r['trimester']] = (string) ($r['remark_section'] ?? ''); }
} catch (PDOException $e) { /* column not there yet */ }
$ageOverride = null;
try {
    $q = $pdo->prepare('SELECT sf9_age_override FROM enrollments WHERE student_id = ? AND school_year_id = ? LIMIT 1');
    $q->execute([$studentId, $schoolYearId]);
    $v = $q->fetchColumn();
    $ageOverride = ($v !== false && $v !== null) ? (int) $v : null;
} catch (PDOException $e) { /* column not there yet */ }

// Principal shown on the signature line (first active principal account, if
// any). Falls back to the configurable School Head default (requirement
// 5.3 — never hard-coded into the template; edit it under Admin Panel ->
// Settings) so the SF9 always has a name, not a blank line.
$principalRow = $pdo->query("SELECT first_name, last_name FROM users WHERE role = 'principal' AND is_active = 1 ORDER BY id LIMIT 1")->fetch();
$principalName = $principalRow ? trim($principalRow['first_name'] . ' ' . $principalRow['last_name']) : '';
if ($principalName === '') {
    try {
        $principalName = (string) Settings::get('school_head_name', 'YVETTE DS. SAN JUAN');
    } catch (PDOException $e) {
        $principalName = 'YVETTE DS. SAN JUAN';   // `settings` table not created yet (migration 003)
    }
}

$student = $data['student'];
$blocked = $data['final']['blocked_by_nlpa_term'];

// Names printed on the signature lines. Both are EDITABLE on screen before printing.
$adviserName = mb_strtoupper(trim(($student['adviser_first'] ?? '') . ' ' . ($student['adviser_last'] ?? '')));
$principalName = mb_strtoupper($principalName);

function e($s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function mm(float $x): string { return number_format($x, 2, '.', ''); }
function g($v): string
{
    if ($v === null || $v === '') return '';
    if (!is_numeric($v)) return e($v);
    return e(rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.'));
}
function remark($v): string { return $v === null || $v === '' ? '' : ((float) $v >= 75 ? 'Passed' : 'Failed'); }
function calcAge(?string $birthdate): string
{
    if (!$birthdate) return '';
    return (string) (new DateTime($birthdate))->diff(new DateTime())->y;
}

// ---- Layout helpers: every element is placed in millimetres on a 148 x 210 mm (A5) sheet ----
function centered(float $cy, string $html, string $cls = '', float $cx = 74.0): void
{ echo '<div class="a cx ' . $cls . '" style="left:' . mm($cx) . 'mm;top:' . mm($cy - 2.5) . 'mm">' . $html . '</div>'; }
function label(float $left, float $cy, string $html, string $cls = ''): void
{ echo '<div class="a fl ' . $cls . '" style="left:' . mm($left) . 'mm;top:' . mm($cy - 2.5) . 'mm">' . $html . '</div>'; }
function line(float $left, float $w, float $cy, string $text, string $cls = ''): void
{ echo '<div class="a fv ' . $cls . '" style="left:' . mm($left) . 'mm;width:' . mm($w) . 'mm;top:' . mm($cy - 2.5) . 'mm">' . e($text) . '</div>'; }
function fill(float $left, float $w, float $cy, string $value = '', string $bind = '', string $cls = ''): void
{
    echo '<input type="text" class="a fill ' . $cls . '"' . ($bind !== '' ? ' data-bind="' . $bind . '"' : '')
       . ' value="' . e($value) . '" autocomplete="off" spellcheck="false" style="left:' . mm($left) . 'mm;width:' . mm($w) . 'mm;top:' . mm($cy - 2.5) . 'mm">';
}

$AREAS = [   // order and wording follow the printed form
    ['Filipino', 'Filipino', 'area'], ['English', 'English', 'area'], ['Mathematics', 'Mathematics', 'area'],
    ['Science', 'Science', 'area'], ['Araling Panlipunan', 'Araling Panlipunan', 'area'],
    ['GMRC', 'GMRC/ Values Education', 'area'], ['EPP/TLE', 'EPP/TLE', 'area'], ['MAPEH', 'MAPEH', 'area'],
    ['Music and Arts', 'Music and Arts', 'comp'], ['Physical Education and Health', 'Physical Education and Health', 'comp'],
];
// Printed exactly as on the school's form (edit here if the form changes).
$SCALE = [['90-100', 'Advancing', 'Passed'], ['80-89', 'Benchmarking', 'Passed'], ['75-84', 'Connecting', 'Passed'],
          ['65-74', 'Developing', 'Failed'], ['0-64', 'Emerging', 'Failed']];

$attendanceByMonth = [];
foreach ($data['attendance'] as $row) { $attendanceByMonth[$row['month']] = $row; }
$months = ['Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec', 'Jan', 'Feb', 'Mar', 'Apr'];

$computedAge = calcAge($student['birthdate']);
$shownAge    = $ageOverride !== null ? (string) $ageOverride : $computedAge;
$fullName = mb_strtoupper($student['last_name'] . ', ' . $student['first_name'] . ($student['middle_name'] ? ' ' . $student['middle_name'] : ''));
$backUrl  = 'student_grades.php?section_id=' . (int) $student['section_id'] . '&student_id=' . (int) $student['id'];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>SF9 — <?= e($student['last_name'] . ', ' . $student['first_name']) ?></title>
  <link href="assets/img/abcede-logo.jpg" rel="icon">
  <style>
    /* Self-contained on purpose: the printout must not depend on Bootstrap or a CDN. */
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { background: #e6e9ef; color: #000; font-family: Arial, "Liberation Sans", Helvetica, sans-serif; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .cal { font-family: Calibri, Carlito, "Segoe UI", Arial, sans-serif; }

    /* ---- on-screen toolbar (never printed) ---- */
    .toolbar { position: sticky; top: 0; z-index: 10; display: flex; flex-wrap: wrap; align-items: center; gap: 8px; justify-content: center; padding: 10px 12px; background: #fff; border-bottom: 1px solid #d8dde6; font-size: 14px; }
    .toolbar a, .toolbar button, .toolbar select { font: inherit; padding: 6px 12px; border-radius: 8px; border: 1px solid #c9d0dc; background: #fff; color: #172033; text-decoration: none; cursor: pointer; }
    .toolbar .primary { background: #2456c9; border-color: #2456c9; color: #fff; font-weight: 600; }
    .hint { max-width: 900px; margin: 10px auto 0; padding: 0 12px; font-size: 13px; color: #4a5568; text-align: center; }
    .info { max-width: 900px; margin: 8px auto 0; padding: 8px 12px; font-size: 13px; background: #fff; border: 1px solid #d8dde6; border-radius: 8px; }
    .info.warn { background: #fff7e0; border-color: #ecd58b; }

    #sheets { padding: 14px 0 30px; }
    .paper { width: 148mm; height: 210mm; margin: 0 auto 10px; }
    .a4 .paper { width: 210mm; height: 297mm; display: flex; justify-content: center; align-items: flex-start; }
    .page { position: relative; width: 148mm; height: 210mm; background: #fff; overflow: hidden; box-shadow: 0 1px 8px rgba(0, 0, 0, .22); }
    @media (max-width: 640px) { #sheets { zoom: .62; } }

    /* ---- positioned building blocks ---- */
    .a { position: absolute; }
    .cx { transform: translateX(-50%); white-space: nowrap; line-height: 5mm; height: 5mm; text-align: center; }
    .fl { white-space: nowrap; line-height: 5mm; height: 5mm; font-size: 10pt; }
    .fv, .fill { line-height: 5mm; height: 5mm; border-bottom: .2mm solid #000; text-align: center; font-weight: bold; font-size: 12.5pt; white-space: nowrap; overflow: hidden; }
    .fv { font-family: Calibri, Carlito, "Segoe UI", Arial, sans-serif; }
    .fv.lt { text-align: left; padding-left: 1.6mm; }
    .fill { font-family: Calibri, Carlito, "Segoe UI", Arial, sans-serif; border-top: 0; border-left: 0; border-right: 0; background: transparent; outline: none; border-radius: 0; padding: 0; }
    .fill { text-overflow: ellipsis; }
    .fill:hover, .fill:focus { background: #fffbe0; }
    .fv.wrap2 { white-space: normal; line-height: 2.4mm; word-break: break-word; }   /* very long value: two small lines instead of overflowing */
    .fit-warn { display: none; position: absolute; right: 1mm; bottom: .3mm; font-size: 6.5pt; color: #b3261e; background: #fff; padding: 0 1mm; border-radius: 1mm; }
    .rs.toolong .fit-warn { display: block; }
    .rs.toolong .tx { outline: .3mm solid #b3261e; outline-offset: -.3mm; }
    .rp { position: absolute; right: 1mm; top: 0; display: flex; gap: 1mm; }
    .rp select { font-size: 7pt; max-width: 36mm; }
    .rp select.rp-preset { max-width: 56mm; }
    .b { font-weight: bold; } .t13 { font-size: 13pt; } .t135 { font-size: 13.5pt; } .t98 { font-size: 9.8pt; } .t132 { font-size: 13.2pt; } .t117 { font-size: 11.7pt; } .t112 { font-size: 11.2pt; } .t83 { font-size: 8.3pt; } .t82 { font-size: 8.2pt; } .t8 { font-size: 8pt; } .t145 { font-size: 14.5pt; } .t136 { font-size: 13.6pt; } .t79 { font-size: 7.9pt; } .t113 { font-size: 11.3pt; } .t111 { font-size: 11.1pt; } .t75 { font-size: 7.5pt; } .t9 { font-size: 9pt; } .t10 { font-size: 10pt; } .t105 { font-size: 10.5pt; }
    .sig { font-size: 12pt; } .sig2 { font-size: 13.5pt; } .sig3 { font-size: 11.5pt; } .sig2, .sig3 { padding-top: 1.2mm; line-height: 4mm; }

    /* ---- grades table (front) ---- */
    table.g { position: absolute; left: 7.9mm; top: 98.6mm; width: 133mm; border-collapse: collapse; table-layout: fixed; }
    table.g th, table.g td { border: .25mm solid #000; padding: 0 1mm; font-size: 10pt; line-height: 1.1; height: 6.2mm; text-align: center; vertical-align: middle; overflow: hidden; }
    table.g thead th { font-size: 10pt; height: 6.1mm; }
    table.g td.n { text-align: left; font-weight: bold; font-size: 10pt; }
    table.g tr.comp td.n { font-weight: normal; font-size: 10pt; padding-left: 8mm; }
    table.g td.ga { font-weight: normal; font-size: 10.5pt; padding-left: 50mm; }

    /* ---- attendance (back) ---- */
    table.att { position: absolute; left: 9.25mm; top: 20.3mm; width: 129.5mm; border-collapse: collapse; table-layout: fixed; }
    table.att th, table.att td { border: .25mm solid #000; text-align: center; padding: 0; font-size: 9pt; }
    table.att thead th { height: 6mm; font-weight: bold; }
    table.att thead th.m { font-weight: normal; font-size: 9.5pt; }
    table.att tbody td { height: 7.3mm; }
    table.att td.rl { font-weight: bold; font-size: 7pt; line-height: 1.25; text-align: center; }
    .att-cell:hover, .att-cell:focus { background: #fffbe0; outline: none; }
    .rbox { position: absolute; left: 9.4mm; top: 57.2mm; width: 130.4mm; height: 44.7mm; border: .8mm solid #000; display: flex; flex-direction: column; }
    .rs { flex: 1; position: relative; border-bottom: .8mm solid #000; min-height: 0; }
    .rs:last-child { border-bottom: 0; }
    .rs .tl { position: absolute; left: .6mm; top: 0; font-size: 10pt; }
    .rs .tx { position: absolute; left: 0; right: 0; top: 5mm; bottom: 0; padding: 0 2mm; font-size: 8pt; line-height: 1.25; outline: none; overflow: hidden; }
    .rs .tx:focus { background: #fffbe0; }
    .ul { position: absolute; border-top: .2mm solid #000; height: 0; }

    /* ---- page setup ---- */
    @page { size: 148mm 210mm; margin: 0; }
    @media print {
      body { background: #fff; }
      .no-print { display: none !important; }
      #sheets { padding: 0; zoom: 1; }
      .paper { margin: 0; break-after: page; page-break-after: always; height: 209.5mm; overflow: hidden; }
      .a4 .paper { height: 296.5mm; }
      .paper:last-child { break-after: auto; page-break-after: auto; }
      .page { box-shadow: none; }
      .fill:hover, .fill:focus, .rs .tx:focus { background: transparent; }
      .att-cell:hover, .att-cell:focus { background: transparent; }
      .rs.toolong .tx { outline: 0; }
    }
  </style>
  <style id="pageStyle"></style>
</head>
<body data-csrf="<?= e(Csrf::token()) ?>" data-student="<?= (int) $studentId ?>" data-year="<?= (int) $schoolYearId ?>" data-section="<?= (int) $sectionId ?>">

<div class="toolbar no-print">
  <a href="<?= e($backUrl) ?>">&larr; Back to grades</a>
  <label>Paper
    <select id="paper">
      <option value="a5">A5 (148 × 210 mm — as the form)</option>
      <option value="a4">A4 (form centered on the sheet)</option>
    </select>
  </label>
  <?php if ($canEditSf9Extras): ?>
  <button type="button" id="btnSaveAttendance">Save Attendance</button>
  <button type="button" id="btnSaveComments">Save Comments</button>
  <button type="button" id="btnSaveAge" title="Use the number in the Age field on this SF9 only">Save Age</button>
  <button type="button" id="btnResetAge" title="Go back to the age calculated from the birth date">Reset Age</button>
  <span id="sf9SaveStatus" class="small" style="color:#2e7d32"></span>
  <?php endif; ?>
  <button class="primary" type="button" onclick="window.print()">Print SF9</button>
</div>
<p class="hint no-print">Click the school head / adviser names, the transfer lines or a teacher's remark to type before printing.
  <?php if ($canEditSf9Extras): ?>The <strong>Age</strong> is calculated from the birth date (<?= e($computedAge !== '' ? $computedAge : 'no birth date on file') ?>); type another number and press <em>Save Age</em> to use it on this SF9 only.<?php endif; ?> Print double-sided (front = page 1, back = page 2).</p>
<?php if ($blocked): ?>
<div class="info warn no-print">Term <?= e($blocked) ?> is marked <strong>NLPA</strong>, so the Final Grade and General Average are left blank.</div>
<?php endif; ?>
<div class="info no-print">
  General average — Term 1: <strong><?= g($data['terms']['1']['general_average']) ?: '—' ?></strong> ·
  Term 2: <strong><?= g($data['terms']['2']['general_average']) ?: '—' ?></strong> ·
  Term 3: <strong><?= g($data['terms']['3']['general_average']) ?: '—' ?></strong> ·
  Rank: <strong><?= g($data['final']['rank']) ?: '—' ?></strong> ·
  Academic Excellence: <strong><?= $data['final']['award'] ? 'Yes' : 'No' ?></strong>
  <span style="color:#6b7488"> (not part of the printed form)</span>
</div>

<div id="sheets">

<!-- ===================== FRONT ===================== -->
<div class="paper"><section class="page front">
  <img class="a" src="assets/img/deped-seal.png" alt="" style="left:13.3mm;top:5mm;height:22mm">
  <img class="a" src="assets/img/school-seal.png" alt="" style="left:108.9mm;top:5mm;height:22mm">

  <?php
  centered(10.5, 'Republika ng Pilipinas', 'cal b t145', 74);
  centered(15.5, 'Kagawaran ng Edukasyon', 'cal t98', 74);
  centered(18.9, 'Rehiyon V- Bicol', 'cal t98', 74);
  centered(22.7, 'Tanggapang Pansangay ng mga Paaralan ng Naga', 'cal t9', 74);
  centered(32.2, 'DR. DOMINGO G. ABCEDE ELEMENTARY SCHOOL', 'b t136', 74);
  centered(39.6, 'LEARNER&rsquo;S PERFORMANCE REPORT', 'b t117', 74);
  centered(44.7, 'S.Y. ' . e($student['year_label']), 't10', 74.8);

  label(12.9, 49.0, 'Name:');  line(24.7, 48.2, 49.0, $fullName, 'lt');
  label(74.6, 49.0, 'Age:');
  if ($canEditSf9Extras) { fill(85.7, 14.8, 49.0, $shownAge, '', 'ageFill'); }
  else { line(85.7, 14.8, 49.0, $shownAge); }
  label(103.6, 49.0, 'Sex:');  line(113.3, 14.4, 49.0, mb_strtoupper((string) $student['gender']));
  label(12.9, 56.0, 'LRN:');   line(24.7, 48.2, 56.0, (string) $student['lrn'], 'lt');
  label(74.6, 56.0, 'Grade:'); line(87.2, 14.5, 56.0, (string) $student['grade_level']);
  label(103.6, 56.0, 'Section:'); line(118.3, 14.8, 56.0, mb_strtoupper((string) $student['section_name']));

  label(12.0, 62.0, 'Dear Parents,', 't82');
  label(23.5, 66.6, 'This Performance Report shows the ability and progress your child has made in the', 't82');
  label(12.0, 70.4, 'different learning areas as well as his/her core values.', 't82');
  label(23.5, 75.3, 'The school welcomes you should you desire to know more about your child&rsquo;s progress.', 't82');

  fill(12.9, 50.2, 83.2, $principalName, 'head', 'sig');
  centered(88.4, 'Principal', 't105', 38.0);
  fill(90.0, 49.8, 83.2, $adviserName, 'adv', 'sig');
  centered(88.4, 'Teacher', 't105', 114.9);

  centered(95.0, 'LEARNER&rsquo;S PROGRESS AND ACHIEVEMENT', 'b t112', 74);
  ?>

  <table class="g">
    <colgroup><col style="width:64.1mm"><col style="width:12.2mm"><col style="width:10.9mm"><col style="width:10.7mm"><col style="width:14.5mm"><col style="width:20.6mm"></colgroup>
    <thead>
      <tr><th rowspan="2">Learning Areas</th><th colspan="3">TERM</th><th rowspan="2">Final<br>Grade</th><th rowspan="2">Remarks</th></tr>
      <tr><th>1</th><th>2</th><th>3</th></tr>
    </thead>
    <tbody>
    <?php foreach ($AREAS as [$key, $text, $kind]):
        $isComp = $kind === 'comp';
        $t = fn(string $n) => $isComp ? ($data['terms'][$n]['components'][$key] ?? null) : ($data['terms'][$n]['area_grades'][$key] ?? null);
        $fin = $isComp ? ($data['final']['component_grades'][$key] ?? null) : ($data['final']['subject_grades'][$key] ?? null);
    ?>
      <tr class="<?= $isComp ? 'comp' : '' ?>">
        <td class="n"><?= e($text) ?></td>
        <td><?= g($t('1')) ?></td><td><?= g($t('2')) ?></td><td><?= g($t('3')) ?></td>
        <td><?= g($fin) ?></td>
        <td><?= $isComp ? '' : e(remark($fin)) ?></td>
      </tr>
    <?php endforeach; ?>
      <tr>
        <td class="ga" colspan="4">General Average</td>
        <td><?= g($data['final']['general_average']) ?></td>
        <td><?= $blocked ? 'NLPA' : e(remark($data['final']['general_average'])) ?></td>
      </tr>
    </tbody>
  </table>

  <?php
  label(8.0, 183.4, 'PERFORMANCE DESCRIPTORS', 'b t83');
  centered(187.6, 'GRADING SCALE', 'b t83', 43.2);
  centered(187.6, 'DESCRIPTION', 'b t83', 84.4);
  centered(187.6, 'REMARKS', 'b t83', 119.2);
  foreach ($SCALE as $i => [$range, $desc, $rem]) {
      $cy = 192.3 + $i * 3.85;
      centered($cy, e($range), 't79', 43.4);
      centered($cy, e($desc), 't79', 84.8);
      centered($cy, e($rem), 't79', 119.1);
  }
  ?>
</section></div>

<!-- ===================== BACK ===================== -->
<div class="paper"><section class="page back">
  <?php centered(15.6, 'ATTENDANCE RECORD', 'b t113', 78.2); ?>
  <table class="att">
    <colgroup><col style="width:20.2mm"><?php foreach ($months as $m): ?><col style="width:8.8mm"><?php endforeach; ?><col style="width:12.5mm"></colgroup>
    <thead><tr><th class="m">Month</th><?php foreach ($months as $m): ?><th><?= $m ?></th><?php endforeach; ?><th>Total</th></tr></thead>
    <tbody>
    <?php foreach ([['No. of Class<br>Days', 'days_of_class', '1.05'], ['No. of Days<br>Present', 'days_present', '1.75'], ['No. of Days<br>Absent', 'days_absent', '1.75']] as [$lbl, $field, $lh]):
        $total = 0; $any = false; ?>
      <tr>
        <td class="rl" style="line-height:<?= $lh ?>"><?= $lbl ?></td>
        <?php foreach ($months as $m):
            $v = $attendanceByMonth[$m][$field] ?? null;
            if ($v !== null && $v !== '') { $total += (int) $v; $any = true; } ?>
          <?php if ($canEditSf9Extras): ?>
            <td class="att-cell" contenteditable="true" spellcheck="false" inputmode="numeric" data-month="<?= e($m) ?>" data-field="<?= e($field) ?>"><?= g($v) ?></td>
          <?php else: ?>
            <td><?= g($v) ?></td>
          <?php endif; ?>
        <?php endforeach; ?>
        <td class="att-total" data-field="<?= e($field) ?>"><?= $any ? $total : '' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($canEditSf9Extras): ?><p class="no-print" style="position:absolute;left:9.25mm;top:49.0mm;font-size:8pt;color:#6b7488">Click a cell to edit, then Save Attendance above.</p><?php endif; ?>

  <?php centered(52.6, 'TEACHER&rsquo;S COMMENTS / REMARKS', 'b t111', 73.6); ?>
  <div class="rbox">
    <?php foreach (['1', '2', '3'] as $t): ?>
      <div class="rs">
        <span class="tl">Term <?= $t ?></span>
        <?php if ($canEditSf9Extras && $remarkSets): ?>
          <div class="rp no-print">
            <select class="remark-section" data-term="<?= $t ?>" aria-label="Remark section for Term <?= $t ?>">
              <option value="">— Section —</option>
              <?php foreach ($remarkSets as $code => $set): ?>
                <option value="<?= e($code === '' ? '_general' : $code) ?>"<?= $savedRemarkSection[$t] === (string) $code && $code !== '' ? ' selected' : '' ?>><?= $code === '' ? 'General' : 'Section ' . e($code) . ' — ' . e($set['title']) ?></option>
              <?php endforeach; ?>
            </select>
            <select class="remark-preset rp-preset" data-term="<?= $t ?>" aria-label="Predefined remark for Term <?= $t ?>"><option value="">— Choose a remark —</option></select>
          </div>
        <?php endif; ?>
        <div class="tx" contenteditable="true" spellcheck="false" data-term="<?= $t ?>"><?= e($data['remarks'][$t] ?? '') ?></div>
        <span class="fit-warn no-print">Too long for this box — shorten it</span>
      </div>
    <?php endforeach; ?>
  </div>

  <?php
  centered(107.1, 'PARENT&rsquo;S / GUARDIAN&rsquo;S SIGNATURE', 'b t112', 73.3);
  foreach ([1 => [114.3, 118.4], 2 => [122.75, 125.3], 3 => [128.95, 131.7]] as $n => [$cy, $uy]) {
      label(32.5, $cy, 'Term ' . $n, 'b t105');
      echo '<div class="ul" style="left:50mm;width:61.9mm;top:' . mm($uy) . 'mm"></div>';
  }

  centered(135.3, 'CERTIFICATE OF TRANSFER', 'b t98', 76.3);
  label(10.3, 139.4, 'This is to certify that the above-named learner has satisfactorily completed the requirements', 'b t83');
  label(10.3, 142.3, 'for the grade level indicated.', 'b t83');
  label(10.3, 149.8, 'Admitted to Grade:', 'b t83');              fill(40.3, 48.5, 147.3, '', '', 'plain');
  label(10.3, 154.5, 'Eligible for Admission to Grade:', 'b t83'); fill(58.5, 46.2, 152.3, '', '', 'plain');
  label(10.3, 159.6, 'Approved:', 'b t83');

  fill(11.3, 50.6, 165.4, $principalName, 'head', 'sig2');
  centered(172.6, 'School Head', 'b t83', 37.4);
  fill(82.4, 53.5, 160.0, $adviserName, 'adv', 'sig2');
  centered(166.0, 'Adviser', 'b t83', 112.4);

  centered(179.5, 'CANCELLATION OF ELIGIBILITY TO TRANSFER', 'b t98', 70.1);
  label(12.5, 188.8, 'Admitted in:', 'b t83');  fill(31.2, 37.1, 187.3, '', '', 'plain');
  label(81.1, 189.2, 'Date:', 'b t83');         fill(93.1, 36.9, 187.5, '', '', 'plain');
  fill(13.2, 37.1, 198.0, $principalName, 'head', 'sig3');
  centered(204.4, 'School Head', 'b t83', 29.8);
  ?>
</section></div>

</div>

<script>
(function () {
  // The school head / adviser names appear on both sides: editing one updates them all.
  var KEY = 'sf9.schoolHead';
  var head = document.querySelectorAll('[data-bind="head"]');
  var adv = document.querySelectorAll('[data-bind="adv"]');
  var saved = null;
  try { saved = localStorage.getItem(KEY); } catch (e) {}
  if (saved) head.forEach(function (i) { i.value = saved; });   // remembered school head wins over the default

  function sync(list, source) { list.forEach(function (i) { if (i !== source) i.value = source.value; }); }
  head.forEach(function (i) {
    i.addEventListener('input', function () {
      sync(head, i);
      try { localStorage.setItem(KEY, i.value); } catch (e) {}
    });
  });
  adv.forEach(function (i) { i.addEventListener('input', function () { sync(adv, i); }); });

  var sheets = document.getElementById('sheets'), sel = document.getElementById('paper'), st = document.getElementById('pageStyle');
  function setPaper(v) {
    sheets.classList.toggle('a4', v === 'a4');
    st.textContent = v === 'a4' ? '@page { size: A4 portrait; margin: 0 }' : '@page { size: 148mm 210mm; margin: 0 }';
  }
  sel.addEventListener('change', function () { setPaper(sel.value); });
  setPaper(sel.value);

})();
</script>

<script>
(function () {
  // ---------------- Auto-fit: keep long text inside its field ----------------
  // Single-line fields shrink (down to a readable minimum), very long values then wrap onto two small lines;
  // the remark boxes shrink until their text fits. Short text keeps the form's normal size.
  var probe = document.createElement('span');
  probe.style.cssText = 'position:absolute;left:-9999px;top:0;visibility:hidden;white-space:nowrap;';
  document.body.appendChild(probe);
  function pxOf(pt) { return pt * 96 / 72; }
  function basePt(el) {
    var b = el.getAttribute('data-base-pt');
    if (!b) { b = parseFloat(window.getComputedStyle(el).fontSize) * 72 / 96; el.setAttribute('data-base-pt', String(b)); }
    return parseFloat(b);
  }
  function fitLine(el, minPt) {
    el.style.fontSize = ''; el.classList.remove('wrap2');
    var cs = window.getComputedStyle(el), avail = el.clientWidth - parseFloat(cs.paddingLeft || 0) - parseFloat(cs.paddingRight || 0);
    if (avail <= 0) return;
    var text = el.tagName === 'INPUT' ? el.value : el.textContent;
    if (!text) return;
    probe.style.fontFamily = cs.fontFamily; probe.style.fontWeight = cs.fontWeight; probe.style.fontStyle = cs.fontStyle;
    probe.textContent = text;
    var size = basePt(el);
    while (size > minPt) {
      probe.style.fontSize = pxOf(size) + 'px';
      if (probe.offsetWidth <= avail - 1) break;
      size -= 0.25;
    }
    probe.style.fontSize = pxOf(size) + 'px';
    var fits = probe.offsetWidth <= avail - 1;
    if (size < basePt(el)) el.style.fontSize = size + 'pt';
    if (!fits && el.tagName !== 'INPUT') {            // still too wide: wrap onto two small lines (the box is 5 mm high)
      var w = 6.6;
      el.classList.add('wrap2'); el.style.fontSize = w + 'pt';
      while (el.scrollHeight > el.clientHeight + 1 && w > 5.25) { w -= 0.25; el.style.fontSize = w + 'pt'; }
      el.title = text;                                 // full value on hover if it is still clipped
    }
  }
  function fitBox(box) {
    box.style.fontSize = '';
    var size = basePt(box), MIN = 6;
    while (box.scrollHeight > box.clientHeight + 1 && size > MIN) { size -= 0.25; box.style.fontSize = size + 'pt'; }
    var over = box.scrollHeight > box.clientHeight + 1;
    var rs = box.parentNode; if (rs && rs.classList) rs.classList.toggle('toolong', over);
    return over;
  }
  function fitAll() {
    var i, list = document.querySelectorAll('.fv');
    for (i = 0; i < list.length; i++) fitLine(list[i], 7.5);
    list = document.querySelectorAll('.fill');
    for (i = 0; i < list.length; i++) fitLine(list[i], 6.5);
    list = document.querySelectorAll('.tx');
    for (i = 0; i < list.length; i++) fitBox(list[i]);
  }
  window.sf9FitAll = fitAll;
  document.addEventListener('input', function (ev) {
    var t = ev.target;
    if (t.classList.contains('fill')) fitLine(t, 6.5);
    else if (t.classList.contains('tx')) fitBox(t);
  });
  window.addEventListener('beforeprint', fitAll);
  window.addEventListener('resize', fitAll);
  window.addEventListener('load', fitAll);
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(fitAll);
  fitAll();
  var paperSel = document.getElementById('paper');
  if (paperSel) paperSel.addEventListener('change', function () { setTimeout(fitAll, 0); });
})();
</script>

<?php if ($canEditSf9Extras): ?>
<script>
(function () {
  // Attendance (requirement 5.1) and Teacher's Comments/Remarks (5.2) are
  // configurable here, not hard-coded: edits are saved back to the
  // database via small JSON endpoints. This page is intentionally
  // dependency-free, so plain fetch() is used instead of the app shell's
  // apiFetch() helper.
  var pageBody = document.body;
  var CSRF = pageBody.dataset.csrf, STUDENT = parseInt(pageBody.dataset.student, 10),
      YEAR = parseInt(pageBody.dataset.year, 10), SECTION = parseInt(pageBody.dataset.section, 10);
  var status = document.getElementById('sf9SaveStatus');

  function say(msg, isError) {
    if (!status) return;
    status.textContent = msg;
    status.style.color = isError ? '#b3261e' : '#2e7d32';
    if (!isError) setTimeout(function () { if (status.textContent === msg) status.textContent = ''; }, 3000);
  }

  function post(url, body) {
    return fetch(url, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': CSRF },
      body: JSON.stringify(body),
    }).then(function (res) {
      return res.json().catch(function () { return null; }).then(function (payload) {
        if (!res.ok || !payload || payload.success === false) {
          throw new Error((payload && payload.message) || 'Save failed (error ' + res.status + ').');
        }
        return payload;
      });
    });
  }

  // ---- Attendance ----
  var attBtn = document.getElementById('btnSaveAttendance');
  if (attBtn) {
    attBtn.addEventListener('click', function () {
      var months = ['Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec', 'Jan', 'Feb', 'Mar', 'Apr'];
      var byMonth = {};
      months.forEach(function (m) { byMonth[m] = { month: m, days_of_class: null, days_present: null, days_absent: null }; });
      document.querySelectorAll('.att-cell').forEach(function (td) {
        var v = td.textContent.trim();
        byMonth[td.dataset.month][td.dataset.field] = v === '' ? null : v;
      });
      attBtn.disabled = true;
      post('api/sf9_attendance.php', { student_id: STUDENT, school_year_id: YEAR, section_id: SECTION, rows: Object.values(byMonth) })
        .then(function () {
          say('Attendance saved.');
          // Refresh the on-screen totals without a full reload.
          ['days_of_class', 'days_present', 'days_absent'].forEach(function (field) {
            var total = 0, any = false;
            document.querySelectorAll('.att-cell[data-field="' + field + '"]').forEach(function (td) {
              var v = td.textContent.trim();
              if (v !== '') { total += parseInt(v, 10) || 0; any = true; }
            });
            var totalCell = document.querySelector('.att-total[data-field="' + field + '"]');
            if (totalCell) totalCell.textContent = any ? total : '';
          });
        })
        .catch(function (e) { say(e.message, true); })
        .finally(function () { attBtn.disabled = false; });
    });
  }

  // ---- Comments / Remarks, by Section A-E ----
  var REMARK_SETS = <?= json_encode($remarkSets, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  function setKey(v) { return v === '_general' ? '' : v; }
  function fillPresets(term, sectionValue) {
    var presetSel = document.querySelector('.remark-preset[data-term="' + term + '"]');
    if (!presetSel) return;
    var set = sectionValue ? REMARK_SETS[setKey(sectionValue)] : null;
    presetSel.innerHTML = '';
    var first = document.createElement('option'); first.value = ''; first.textContent = set ? '— Choose a remark —' : '— Pick a Section first —';
    presetSel.appendChild(first);
    if (set) set.items.forEach(function (it) {
      var o = document.createElement('option'); o.value = it.text; o.textContent = (it.code ? it.code + ' · ' : '') + it.text; presetSel.appendChild(o);
    });
  }
  function sectionOfText(text) {            // which Section a ready-made remark belongs to (or null for free text)
    var found = null;
    Object.keys(REMARK_SETS).forEach(function (k) { REMARK_SETS[k].items.forEach(function (it) { if (it.text === text) found = k; }); });
    return found;
  }
  document.querySelectorAll('.remark-section').forEach(function (secSel) {
    var term = secSel.dataset.term;
    fillPresets(term, secSel.value);
    secSel.addEventListener('change', function () {
      var box = document.querySelector('.tx[data-term="' + term + '"]');
      // A ready-made remark from another Section must not stay under this one.
      var own = box ? sectionOfText(box.textContent.trim()) : null;
      if (own !== null && own !== setKey(secSel.value)) { box.textContent = ''; if (window.sf9FitAll) window.sf9FitAll(); }
      fillPresets(term, secSel.value);
    });
  });
  document.querySelectorAll('.remark-preset').forEach(function (sel) {
    sel.addEventListener('change', function () {
      if (!sel.value) return;
      var box = document.querySelector('.tx[data-term="' + sel.dataset.term + '"]');
      if (box) { box.textContent = sel.value; if (window.sf9FitAll) window.sf9FitAll(); }
      sel.value = '';
    });
  });
  var cBtn = document.getElementById('btnSaveComments');
  if (cBtn) {
    cBtn.addEventListener('click', function () {
      var terms = [], tooLong = false;
      document.querySelectorAll('.tx[data-term]').forEach(function (box) {
        var secSel = document.querySelector('.remark-section[data-term="' + box.dataset.term + '"]');
        var v = secSel ? secSel.value : '';
        terms.push({ trimester: box.dataset.term, remarks: box.textContent.trim(), section: v === '_general' ? '' : v });
        if (box.parentNode.classList.contains('toolong')) tooLong = true;
      });
      if (tooLong && !window.confirm('Some remarks are too long to print fully in their box. Save anyway?')) return;
      cBtn.disabled = true;
      post('api/sf9_remarks.php', { student_id: STUDENT, school_year_id: YEAR, section_id: SECTION, terms: terms })
        .then(function () { say('Comments saved.'); })
        .catch(function (e) { say(e.message, true); })
        .finally(function () { cBtn.disabled = false; });
    });
  }

  // ---- Age (SF9-only override) ----
  var ageInput = document.querySelector('.ageFill'), ageBtn = document.getElementById('btnSaveAge'), ageReset = document.getElementById('btnResetAge');
  var COMPUTED_AGE = <?= json_encode($computedAge) ?>;
  if (ageInput) {
    ageInput.setAttribute('inputmode', 'numeric'); ageInput.setAttribute('maxlength', '2'); ageInput.title = 'Age shown on this SF9 (calculated from the birth date unless you save another value)';
    ageInput.addEventListener('input', function () { ageInput.value = ageInput.value.replace(/[^0-9]/g, ''); });
  }
  function saveAge(value, okReset) {
    return post('api/sf9_age.php', { student_id: STUDENT, school_year_id: YEAR, section_id: SECTION, age: value })
      .then(function (r) { say(r.message); if (okReset && ageInput) ageInput.value = COMPUTED_AGE; })
      .catch(function (e) { say(e.message, true); });
  }
  if (ageBtn && ageInput) ageBtn.addEventListener('click', function () {
    var v = ageInput.value.trim();
    if (v === '' || v === COMPUTED_AGE) { saveAge(null, true); return; }      // same as the calculated age (or blank) = no override
    ageBtn.disabled = true; saveAge(v, false).then(function () { ageBtn.disabled = false; });
  });
  if (ageReset && ageInput) ageReset.addEventListener('click', function () { ageReset.disabled = true; saveAge(null, true).then(function () { ageReset.disabled = false; }); });
})();
</script>
<?php endif; ?>
<?php if (($_GET['print'] ?? '') === '1'): ?><script>window.addEventListener('load', function () { window.print(); });</script><?php endif; ?>
</body>
</html>
