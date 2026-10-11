<?php
// Admins, and the ADVISER of a section (for that section only): import learners from an .xls / .xlsx / .csv file
// into the existing `students` table.
//
//   POST multipart  action=preview  file=<workbook>  [section_id]   -> validates + duplicate check, writes NOTHING
//   POST json       action=commit   token  [section_id] [allow_possible_duplicates] -> inserts the checked rows
//
// The preview is kept server-side in the session (never trusted from the browser), so what gets imported is
// exactly what the user was shown, re-checked against the database at the moment of writing.
require_once __DIR__ . '/../../src/Http/Http.php';
Json::boot();

require_once __DIR__ . '/../../src/Auth/Auth.php';
require_once __DIR__ . '/../../src/Auth/Csrf.php';
require_once __DIR__ . '/../../src/Import/StudentImportService.php';

Auth::requireLogin();
Json::requireMethod('POST');

/**
 * Admins may import anywhere. Anyone else must be the ADVISER of the section the learners go into
 * (a subject teacher, or an adviser of a different section, is refused). Checked on the server for both steps.
 */
function import_assert_may_import(int $sectionId): void
{
    if (Auth::isAdmin()) { return; }
    if ($sectionId <= 0) {
        throw new HttpException(403, 'Choose one of your own sections to import learners into.');
    }
    $st = get_db()->prepare('SELECT adviser_id FROM sections WHERE id = ? LIMIT 1');
    $st->execute([$sectionId]);
    $adviser = $st->fetchColumn();
    if ($adviser === false) { throw new HttpException(404, 'The selected section no longer exists.'); }
    if ((int) $adviser !== Auth::userId()) {
        throw new HttpException(403, 'Only the adviser of this section can import learners into it.');
    }
}

const PREVIEW_TTL = 1800;                    // a preview is valid for 30 minutes

/** Section the learners should also be enrolled in (or null). Active school year only, like the normal enrollment. */
function import_target_section(int $sectionId): ?array
{
    if ($sectionId <= 0) { return null; }
    $st = get_db()->prepare(
        'SELECT sec.id, sec.grade_level, sec.section_name, sec.school_year_id, sy.year_label, sy.is_active AS year_active
         FROM sections sec JOIN school_years sy ON sy.id = sec.school_year_id WHERE sec.id = ? LIMIT 1'
    );
    $st->execute([$sectionId]);
    $row = $st->fetch();
    if (!$row) { throw new HttpException(404, 'The selected section no longer exists.'); }
    if (!$row['year_active']) { throw new HttpException(409, 'Learners can only be enrolled in a section of the active school year.'); }
    return ['id' => (int) $row['id'], 'grade_level' => (int) $row['grade_level'], 'section_name' => $row['section_name'],
            'school_year_id' => (int) $row['school_year_id'], 'year_label' => $row['year_label'], 'year_active' => true];
}

$isMultipart = stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data') === 0;
$body = $isMultipart ? $_POST : Json::body();
Csrf::validateOrFail(Csrf::fromRequest($body));
$action = (string) ($body['action'] ?? '');

if ($action === 'preview') {
    import_assert_may_import((int) ($body['section_id'] ?? 0));
    $f = $_FILES['file'] ?? null;
    if (!$f || !is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new HttpException(422, 'Choose an Excel file (.xls, .xlsx) or CSV to upload.');
    }
    if (in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        throw new HttpException(413, 'That file is too large (limit 5 MB).');
    }
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        throw new HttpException(422, 'The upload did not complete. Please try again.');
    }
    if ($f['size'] > StudentImportService::MAX_BYTES) {
        throw new HttpException(413, 'That file is too large (limit 5 MB).');
    }
    $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['xls', 'xlsx', 'csv'], true)) {
        throw new HttpException(422, 'Only .xls, .xlsx and .csv files can be imported.');
    }

    $result = StudentImportService::analyze($f['tmp_name'], (string) $f['name']);   // the temp upload is deleted by PHP; nothing is stored

    $token = bin2hex(random_bytes(16));
    $_SESSION['student_import'] = [
        'token' => $token, 'created' => time(), 'user' => Auth::userId(),
        'records' => $result['rows'],
    ];

    // Suggest the section the file was made for (SF1 heading: Grade Level / Section) when it exists this school year.
    $suggest = null;
    if (Auth::isAdmin() && !empty($result['context']['grade_level']) && !empty($result['context']['section'])) {
        $st = get_db()->prepare(
            'SELECT sec.id FROM sections sec JOIN school_years sy ON sy.id = sec.school_year_id
             WHERE sy.is_active = 1 AND sec.grade_level = ? AND UPPER(sec.section_name) = UPPER(?) LIMIT 1'
        );
        $st->execute([$result['context']['grade_level'], $result['context']['section']]);
        $suggest = ($id = $st->fetchColumn()) !== false ? (int) $id : null;
    }

    $rowsOut = array_map(static fn($r) => [
        'row' => $r['row'], 'lrn' => $r['lrn'], 'name' => trim($r['last_name'] . ', ' . $r['first_name'] . ' ' . $r['middle_name'], ' ,'),
        'sex' => $r['gender'], 'birthdate' => $r['birthdate'], 'status' => $r['status'], 'messages' => $r['messages'],
    ], $result['rows']);

    Json::ok([
        'token' => $token, 'summary' => $result['summary'], 'context' => $result['context'],
        'suggested_section_id' => $suggest, 'sheet' => $result['sheet'], 'rows' => $rowsOut,
    ], 'File checked. Nothing has been imported yet.');
}

if ($action === 'commit') {
    $pending = $_SESSION['student_import'] ?? null;
    $token = (string) ($body['token'] ?? '');
    if (!$pending || !hash_equals((string) $pending['token'], $token) || (int) $pending['user'] !== Auth::userId()) {
        throw new HttpException(409, 'That preview is no longer available. Upload the file again.');
    }
    if (time() - (int) $pending['created'] > PREVIEW_TTL) {
        unset($_SESSION['student_import']);
        throw new HttpException(409, 'That preview expired. Upload the file again.');
    }
    import_assert_may_import((int) ($body['section_id'] ?? 0));
    $section = import_target_section((int) ($body['section_id'] ?? 0));   // validate first, so a bad section does not burn the preview
    unset($_SESSION['student_import']);                         // one import per preview — a double click cannot import twice

    $result = StudentImportService::commit($pending['records'], (bool) ($body['allow_possible_duplicates'] ?? false), $section);
    Json::ok($result, $result['imported'] . ' learner(s) imported.');
}

throw new HttpException(422, 'Unknown import action.');
