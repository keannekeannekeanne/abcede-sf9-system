<?php
require_once __DIR__ . '/../src/Http/Http.php';
Page::boot();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Auth/Auth.php';
require_once __DIR__ . '/../src/Auth/Access.php';
require_once __DIR__ . '/../src/SF9/TempReportCardService.php';

Auth::requireLogin();

// Same rule as the SF9 and the section page: only an admin, the adviser or a teacher assigned to the section.
$section = Access::requireSection((int) ($_GET['section_id'] ?? 0));

// Which learners to print. No selection in the URL = every active learner of the section.
$selected = null;
if (!empty($_GET['sel'])) {
    $selected = array_values(array_unique(array_map('intval', (array) ($_GET['student_ids'] ?? []))));
}
$allLearners = TempReportCardService::learners($section['id'], $section['school_year_id']);
$learners    = $selected === null ? $allLearners : array_values(array_filter($allLearners, static fn(array $l): bool => in_array($l['id'], $selected, true)));
$sheets      = TempReportCardService::sheets($learners);   // 4 DIFFERENT learners per A4 sheet

$principalName = TempReportCardService::principalName();
$adviserName   = mb_strtoupper($section['adviser_name']);
$sectionName   = mb_strtoupper($section['section_name']);
$yearLabel     = $section['year_label'];
$backUrl       = 'section.php?section_id=' . (int) $section['id'];
$chosenIds     = array_column($learners, 'id');

function e($s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
/** Same display rules as the SF9: up to 2 decimals, trailing zeros dropped, blank when there is no grade. */
function g($v): string
{
    if ($v === null || $v === '') return '';
    if (!is_numeric($v)) return e($v);
    return e(rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.'));
}
function remark($v): string { return $v === null || $v === '' ? '' : ((float) $v >= 75 ? 'Passed' : 'Failed'); }

// Learning areas exactly as on the form: [label, key used by SF9DataService, is MAPEH component]
$AREAS = [
    ['Filipino', 'Filipino', false], ['English', 'English', false], ['Mathematics', 'Mathematics', false], ['Science', 'Science', false],
    ['Araling Panlipunan', 'Araling Panlipunan', false], ['GMRC/ Values Education', 'GMRC', false], ['EPP/TLE', 'EPP/TLE', false], ['MAPEH', 'MAPEH', false],
    ['Music and Arts', 'Music and Arts', true], ['Physical Education and Health', 'Physical Education and Health', true],
];

/** One temporary report card. $l = null prints nothing (unused slot on the last sheet). */
function card(?array $l, array $ctx, array $areas): void
{
    if ($l === null) { echo '<div class="card empty"></div>'; return; }
    ?>
    <div class="card">
      <div class="hd">
        <img src="assets/img/deped-seal.png" alt="" class="seal l">
        <div class="hd-t">
          <div class="rp">Republika ng Pilipinas</div>
          <div class="s">Kagawaran ng Edukasyon</div>
          <div class="s">Rehiyon V- Bicol</div>
          <div class="s s2">Tanggapang Pansangay ng mga Paaralan ng Naga</div>
        </div>
        <img src="assets/img/school-seal.png" alt="" class="seal r">
      </div>
      <div class="school fit">DR. DOMINGO G. ABCEDE ELEMENTARY SCHOOL</div>
      <div class="ttl">TEMPORARY LEARNER&rsquo;S PERFORMANCE REPORT</div>
      <div class="sy">S.Y. <?= e($ctx['year']) ?></div>

      <div class="info">
        <div class="f"><span>Name:</span><span class="v fit"><?= e($l['full_name']) ?></span></div>
        <div class="f"><span>Age:</span><span class="v fit"><?= e($l['age']) ?></span></div>
        <div class="f"><span>Sex:</span><span class="v fit"><?= e($l['sex']) ?></span></div>
        <div class="f"><span>LRN:</span><span class="v fit"><?= e($l['lrn']) ?></span></div>
        <div class="f"><span>Grade:</span><span class="v fit"><?= e($ctx['grade']) ?></span></div>
        <div class="f"><span>Section:</span><span class="v fit"><?= e($ctx['section']) ?></span></div>
      </div>

      <div class="dear">
        <div>Dear Parents,</div>
        <p>This Performance Report shows the ability and progress your child has made in the different learning areas as well as his/her core values.</p>
        <p>The school welcomes you should you desire to know more about your child&rsquo;s progress.</p>
      </div>

      <div class="sigs">
        <div><input type="text" class="sig" data-bind="head" value="<?= e($ctx['principal']) ?>" autocomplete="off" spellcheck="false"><div class="role">Principal</div></div>
        <div><input type="text" class="sig" data-bind="adv" value="<?= e($ctx['adviser']) ?>" autocomplete="off" spellcheck="false"><div class="role">Teacher</div></div>
      </div>

      <div class="pa">LEARNER&rsquo;S PROGRESS AND ACHIEVEMENT</div>
      <table class="g">
        <colgroup><col style="width:44mm"><col style="width:8mm"><col style="width:8mm"><col style="width:8mm"><col style="width:12mm"><col style="width:15mm"></colgroup>
        <thead>
          <tr><th rowspan="2" class="la">Learning Areas</th><th colspan="3">TERM</th><th rowspan="2">Final<br>Grade</th><th rowspan="2">Remarks</th></tr>
          <tr><th>1</th><th>2</th><th>3</th></tr>
        </thead>
        <tbody>
        <?php
        $gr = $l['grades'];
        foreach ($areas as [$label, $key, $isComp]):
            $t = fn(string $n) => $gr ? ($isComp ? ($gr['terms'][$n]['components'][$key] ?? null) : ($gr['terms'][$n]['area_grades'][$key] ?? null)) : null;
            $fin = $gr ? ($isComp ? ($gr['final']['component_grades'][$key] ?? null) : ($gr['final']['subject_grades'][$key] ?? null)) : null;
        ?>
          <tr class="<?= $isComp ? 'comp' : '' ?>"><td class="n"><?= e($label) ?></td>
            <td class="v"><?= g($t('1')) ?></td><td class="v"><?= g($t('2')) ?></td><td class="v"><?= g($t('3')) ?></td>
            <td class="v"><?= g($fin) ?></td><td class="v"><?= $isComp ? '' : e(remark($fin)) ?></td></tr>
        <?php endforeach;
        $ga = $gr ? ($gr['final']['general_average'] ?? null) : null;
        $blocked = $gr && !empty($gr['final']['blocked_by_nlpa_term']); ?>
          <tr><td class="ga" colspan="4">General Average</td>
            <td class="v"><?= g($ga) ?></td><td class="v"><?= $blocked ? 'NLPA' : e(remark($ga)) ?></td></tr>
        </tbody>
      </table>
    </div>
    <?php
}

$ctx = ['year' => $yearLabel, 'grade' => (string) $section['grade_level'], 'section' => $sectionName, 'principal' => $principalName, 'adviser' => $adviserName];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Temporary Report Cards — Grade <?= (int) $section['grade_level'] ?> <?= e($section['section_name']) ?></title>
  <link href="assets/img/abcede-logo.jpg" rel="icon">
  <style>
    /* Self-contained on purpose (like sf9_preview.php): the printout must not depend on Bootstrap or a CDN. */
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { background: #e6e9ef; color: #000; font-family: Calibri, Carlito, "Segoe UI", Arial, sans-serif; -webkit-print-color-adjust: exact; print-color-adjust: exact; }

    /* ---- on-screen toolbar (never printed) ---- */
    .toolbar { position: sticky; top: 0; z-index: 10; display: flex; flex-wrap: wrap; align-items: center; gap: 8px; justify-content: center; padding: 10px 12px; background: #fff; border-bottom: 1px solid #d8dde6; font-size: 14px; }
    .toolbar a, .toolbar button, .toolbar summary { font: inherit; padding: 6px 12px; border-radius: 8px; border: 1px solid #c9d0dc; background: #fff; color: #172033; text-decoration: none; cursor: pointer; }
    .toolbar .primary { background: #2456c9; border-color: #2456c9; color: #fff; font-weight: 600; }
    .toolbar label { display: inline-flex; align-items: center; gap: 6px; }
    .hint { max-width: 900px; margin: 10px auto 0; padding: 0 12px; font-size: 13px; color: #4a5568; text-align: center; }
    .picker { max-width: 900px; margin: 8px auto 0; padding: 10px 12px; background: #fff; border: 1px solid #d8dde6; border-radius: 8px; font-size: 13px; }
    .picker .acts { display: flex; gap: 8px; margin-bottom: 8px; }
    .picker .acts button { font: inherit; padding: 4px 10px; border-radius: 8px; border: 1px solid #c9d0dc; background: #fff; cursor: pointer; }
    .picker .acts .primary { background: #2456c9; border-color: #2456c9; color: #fff; font-weight: 600; }
    .picker .list { columns: 2 260px; column-gap: 16px; max-height: 260px; overflow: auto; }
    .picker .list label { display: block; padding: 1px 0; }
    .empty-msg { max-width: 900px; margin: 14px auto; padding: 14px; background: #fff7e0; border: 1px solid #ecd58b; border-radius: 8px; font-size: 14px; text-align: center; }

    /* ---- A4 sheet = 2 x 2 cards of 105 x 148.5 mm (A4 cut in four) ---- */
    #sheets { padding: 14px 0 30px; }
    .sheet { position: relative; width: 210mm; height: 297mm; margin: 0 auto 10px; background: #fff; box-shadow: 0 1px 8px rgba(0, 0, 0, .22); overflow: hidden;
             display: grid; grid-template-columns: 105mm 105mm; grid-template-rows: 148.5mm 148.5mm; }
    @media (max-width: 800px) { #sheets { zoom: .45; } }
    /* cut guides through the middle of the sheet */
    #sheets.guides .sheet::before { content: ""; position: absolute; left: 105mm; top: 0; bottom: 0; border-left: .2mm dashed #9aa3b2; z-index: 2; pointer-events: none; }
    #sheets.guides .sheet::after  { content: ""; position: absolute; top: 148.5mm; left: 0; right: 0; border-top: .2mm dashed #9aa3b2; z-index: 2; pointer-events: none; }

    /* ---- one card ---- */
    .card { width: 105mm; height: 148.5mm; padding: 5mm 5mm 4mm; display: flex; flex-direction: column; overflow: hidden; }
    .hd { display: grid; grid-template-columns: 15mm 1fr 15mm; align-items: center; height: 15mm; }
    .seal { height: 14mm; width: auto; }
    .seal.l { justify-self: start; } .seal.r { justify-self: end; }
    .hd-t { text-align: center; line-height: 1.15; }
    .hd-t .rp { font-weight: bold; font-size: 10.5pt; margin-bottom: .4mm; }
    .hd-t .s { font-size: 6.8pt; } .hd-t .s2 { font-size: 6.3pt; }
    .school { margin-top: 2.2mm; text-align: center; font-weight: bold; font-size: 9.2pt; white-space: nowrap; line-height: 1.2; }
    .ttl { margin-top: 1.3mm; text-align: center; font-weight: bold; font-size: 8.4pt; line-height: 1.2; }
    .sy { text-align: center; font-size: 7.2pt; line-height: 1.2; margin-top: .3mm; }

    .info { display: grid; grid-template-columns: 52fr 20fr 23fr; column-gap: 2.2mm; row-gap: 1.2mm; margin-top: 1.8mm; font-size: 7.2pt; }
    .f { display: flex; align-items: flex-end; gap: .9mm; white-space: nowrap; min-width: 0; }
    .f .v { flex: 1; min-width: 0; height: 3.8mm; line-height: 3.8mm; border-bottom: .2mm solid #000; text-align: center; font-weight: bold; font-size: 7.8pt; overflow: hidden; white-space: nowrap; }

    .dear { margin-top: 1.8mm; font-size: 6.2pt; line-height: 1.3; }
    .dear p { text-indent: 4mm; }

    .sigs { display: grid; grid-template-columns: 1fr 1fr; column-gap: 7mm; margin-top: 3mm; }
    .sig { display: block; width: 100%; height: 3.8mm; line-height: 3.8mm; border: 0; border-bottom: .2mm solid #000; border-radius: 0; background: transparent; outline: none; text-align: center; font: bold 8pt Calibri, Carlito, "Segoe UI", Arial, sans-serif; text-overflow: ellipsis; }
    .sig:hover, .sig:focus { background: #fffbe0; }
    .role { text-align: center; font-size: 7.2pt; line-height: 1.25; margin-top: .3mm; }

    .pa { margin-top: 2.6mm; text-align: center; font-weight: bold; font-size: 8pt; line-height: 1.2; }
    table.g { width: 100%; border-collapse: collapse; table-layout: fixed; margin-top: 1mm; }
    table.g th, table.g td { border: .22mm solid #000; padding: 0 .8mm; font-size: 6.6pt; line-height: 1; text-align: center; vertical-align: middle; overflow: hidden; }
    table.g thead th { height: 4.6mm; font-weight: bold; }
    table.g thead th.la { font-size: 7pt; }
    table.g tbody tr { height: 5mm; }
    table.g td.n { text-align: left; font-weight: bold; }
    table.g tr.comp td.n { font-weight: normal; padding-left: 4mm; }
    table.g td.ga { font-weight: normal; padding-left: 14mm; }

    #sheets.nogrades td.v { visibility: hidden; }   /* "Show grades" unticked: blank cells for writing by hand */

    /* ---- print: exactly one A4 per sheet, no margins ---- */
    @page { size: A4 portrait; margin: 0; }
    @media print {
      body { background: #fff; }
      .no-print { display: none !important; }
      #sheets { padding: 0; zoom: 1; }
      .sheet { margin: 0; box-shadow: none; height: 296.5mm; break-after: page; page-break-after: always; }
      .sheet:last-child { break-after: auto; page-break-after: auto; }
      .sig:hover, .sig:focus { background: transparent; }
    }
  </style>
</head>
<body>

<div class="toolbar no-print">
  <a href="<?= e($backUrl) ?>">&larr; Back to section</a>
  <details>
    <summary>Choose learners (<?= count($learners) ?> of <?= count($allLearners) ?>)</summary>
  </details>
  <label><input type="checkbox" id="showGrades" checked> Show grades</label>
  <label><input type="checkbox" id="guides" checked> Cut guides</label>
  <button class="primary" type="button" onclick="window.print()"<?= $learners ? '' : ' disabled' ?>>Print</button>
</div>

<p class="hint no-print">
  Grade <?= (int) $section['grade_level'] ?> – <?= e($section['section_name']) ?> · S.Y. <?= e($yearLabel) ?> ·
  <strong><?= count($learners) ?></strong> learner<?= count($learners) === 1 ? '' : 's' ?> on <strong><?= count($sheets) ?></strong> A4 sheet<?= count($sheets) === 1 ? '' : 's' ?>
  (4 different learners per sheet, alphabetical). Print on A4 at 100% / “Actual size”, margins “None”. Grades are the saved grades (same as the SF9); untick “Show grades” for blank cells. Click the Principal or Teacher name to type before printing.
</p>

<form class="picker no-print" method="get" action="temp_report_cards.php" id="picker" hidden>
  <input type="hidden" name="section_id" value="<?= (int) $section['id'] ?>">
  <input type="hidden" name="sel" value="1">
  <div class="acts">
    <button type="button" id="pAll">Select all</button>
    <button type="button" id="pNone">Clear</button>
    <button type="submit" class="primary">Apply</button>
  </div>
  <div class="list">
    <?php foreach ($allLearners as $l): ?>
      <label><input type="checkbox" name="student_ids[]" value="<?= (int) $l['id'] ?>"<?= in_array($l['id'], $chosenIds, true) ? ' checked' : '' ?>> <?= e($l['full_name']) ?></label>
    <?php endforeach; ?>
  </div>
</form>

<?php if (!$learners): ?>
  <div class="empty-msg no-print"><?= $allLearners ? 'No learners selected. Use “Choose learners”.' : 'This section has no active learners to print.' ?></div>
<?php endif; ?>

<div id="sheets" class="guides">
<?php foreach ($sheets as $sheet): ?>
  <section class="sheet">
    <?php for ($i = 0; $i < TempReportCardService::PER_SHEET; $i++) { card($sheet[$i] ?? null, $ctx, $AREAS); } ?>
  </section>
<?php endforeach; ?>
</div>

<script>
(function () {
  // "Choose learners" opens the picker form.
  var det = document.querySelector('.toolbar details'), picker = document.getElementById('picker');
  det.addEventListener('toggle', function () { picker.hidden = !det.open; });
  document.getElementById('pAll').addEventListener('click', function () { picker.querySelectorAll('input[type=checkbox]').forEach(function (c) { c.checked = true; }); });
  document.getElementById('pNone').addEventListener('click', function () { picker.querySelectorAll('input[type=checkbox]').forEach(function (c) { c.checked = false; }); });
  document.getElementById('showGrades').addEventListener('change', function () { document.getElementById('sheets').classList.toggle('nogrades', !this.checked); });
  document.getElementById('guides').addEventListener('change', function () { document.getElementById('sheets').classList.toggle('guides', this.checked); });

  // The Principal / Teacher names are the same on every card: editing one updates them all.
  var KEY = 'sf9.schoolHead';                       // same key as the SF9 page, so one edit serves both
  var head = document.querySelectorAll('[data-bind="head"]'), adv = document.querySelectorAll('[data-bind="adv"]'), saved = null;
  try { saved = localStorage.getItem(KEY); } catch (e) {}
  if (saved) head.forEach(function (i) { i.value = saved; });
  function sync(list, src) { list.forEach(function (i) { if (i !== src) i.value = src.value; }); }
  head.forEach(function (i) { i.addEventListener('input', function () { sync(head, i); try { localStorage.setItem(KEY, i.value); } catch (e) {} fitAll(); }); });
  adv.forEach(function (i) { i.addEventListener('input', function () { sync(adv, i); fitAll(); }); });

  // Auto-fit: a long name / section shrinks (to a readable minimum) instead of being cut off.
  var probe = document.createElement('span');
  probe.style.cssText = 'position:absolute;left:-9999px;top:0;visibility:hidden;white-space:nowrap;';
  document.body.appendChild(probe);
  function fit(el, minPt) {
    el.style.fontSize = '';
    var cs = getComputedStyle(el), base = parseFloat(cs.fontSize) * 72 / 96;
    var avail = el.clientWidth - parseFloat(cs.paddingLeft || 0) - parseFloat(cs.paddingRight || 0);
    var text = el.tagName === 'INPUT' ? el.value : el.textContent;
    if (avail <= 0 || !text) return;
    probe.style.fontFamily = cs.fontFamily; probe.style.fontWeight = cs.fontWeight; probe.textContent = text;
    var size = base;
    while (size > minPt) { probe.style.fontSize = (size * 96 / 72) + 'px'; if (probe.offsetWidth <= avail - 1) break; size -= 0.25; }
    if (size < base) el.style.fontSize = size + 'pt';
  }
  function fitAll() {
    document.querySelectorAll('.fit').forEach(function (el) { fit(el, 5); });
    document.querySelectorAll('.sig').forEach(function (el) { fit(el, 5.5); });
  }
  window.addEventListener('beforeprint', fitAll);
  window.addEventListener('load', fitAll);
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(fitAll);
  fitAll();
})();
</script>
</body>
</html>
