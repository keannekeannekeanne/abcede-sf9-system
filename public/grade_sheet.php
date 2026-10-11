<?php
require_once __DIR__ . '/../src/Http/Http.php';
Page::boot();
require_once __DIR__ . '/../src/Auth/Auth.php';
require_once __DIR__ . '/../src/Auth/Csrf.php';
require_once __DIR__ . '/../src/Auth/Access.php';

Auth::requireLogin();
$assignmentId = (int) ($_GET['assignment_id'] ?? 0);
$assignment   = Auth::assertOwnsAssignment($assignmentId);   // 403 page unless this teacher owns section+subject

$pdo = get_db();
$infoStmt = $pdo->prepare(
    'SELECT sec.id AS section_id, sec.grade_level, sec.section_name, sub.subject_name, sy.year_label
     FROM teacher_assignments ta
     JOIN sections sec ON sec.id = ta.section_id
     JOIN subjects sub ON sub.id = ta.subject_id
     JOIN school_years sy ON sy.id = ta.school_year_id
     WHERE ta.id = ?'
);
$infoStmt->execute([$assignmentId]);
$info = $infoStmt->fetch();
$sectionLabel = 'Grade ' . $info['grade_level'] . ' – ' . $info['section_name'];

$pageTitle  = 'Grade Sheet';
$activePage = 'my_classes';
require __DIR__ . '/includes/header.php';
?>
<div class="crumbs"><a href="my_classes.php"><?= Auth::isAdmin() ? 'View Sections' : 'My Classes' ?></a> / <a href="section.php?section_id=<?= (int) $info['section_id'] ?>"><?= htmlspecialchars($sectionLabel) ?></a> / Grade sheet</div>
<div class="page-head">
  <div>
    <h1>Grade Sheet · <?= htmlspecialchars($info['subject_name']) ?></h1>
    <div class="sub"><?= htmlspecialchars($sectionLabel) ?> · School year <?= htmlspecialchars($info['year_label']) ?></div>
  </div>
</div>

<div id="grade-sheet-root" data-assignment-id="<?= $assignmentId ?>">
  <div class="text-secondary">Loading grade sheet…</div>
</div>

<script src="https://unpkg.com/react@18.3.1/umd/react.production.min.js" crossorigin></script>
<script src="https://unpkg.com/react-dom@18.3.1/umd/react-dom.production.min.js" crossorigin></script>
<script src="https://unpkg.com/@babel/standalone@7.25.6/babel.min.js" crossorigin></script>

<script type="text/babel" data-presets="react">
const { useState, useEffect, useCallback, useRef } = React;

const STATUS_LABELS = { graded: 'Graded', nlpa: 'NLPA', transferred_out: 'Transferred Out' };
const isBlankNew = (v, hasId) => v === '' && !hasId;
const ordinal = t => t + (t === '1' ? 'st' : t === '2' ? 'nd' : 'rd');
const round0 = n => Math.round(n);

/**
 * Auto-save grade cell: no Save button anywhere on this page. A change
 * debounces briefly (so fast typing does not fire a request per keystroke),
 * then saves. The little "Saving… / Saved / Save failed" indicator is the
 * only feedback — see Priority 11 (auto-save) in the brief.
 */
function useAutoSave(assignmentId, trimester) {
  const [cellState, setCellState] = useState({}); // key -> 'saving'|'saved'|'error'
  const timers = useRef({});

  const doSave = useCallback((key, rows, onSuccess) => {
    setCellState(s => ({ ...s, [key]: 'saving' }));
    apiFetch('api/grades.php', { method: 'POST', body: { assignment_id: assignmentId, trimester, grades: rows } })
      .then(res => {
        setCellState(s => ({ ...s, [key]: 'saved' }));
        if (onSuccess) onSuccess(res);
        setTimeout(() => setCellState(s => (s[key] === 'saved' ? { ...s, [key]: null } : s)), 2500);
      })
      .catch(err => {
        const list = (err.payload && err.payload.errors) || [];
        const mine = list.find(x => rows.some(r => r.student_id === x.student_id && (r.subject_id ?? null) === (x.subject_id ?? null)));
        setCellState(s => ({ ...s, [key]: 'error' }));
        toast((mine && mine.message) || err.message, 'error');
      });
  }, [assignmentId, trimester]);

  const scheduleSave = useCallback((key, rows, delay, onSuccess) => {
    clearTimeout(timers.current[key]);
    timers.current[key] = setTimeout(() => doSave(key, rows, onSuccess), delay);
  }, [doSave]);

  return { cellState, scheduleSave, doSave };
}

function SaveBadge({ state }) {
  if (state === 'saving') return <span className="save-badge saving"><span className="spinner-border spinner-border-sm"></span>Saving…</span>;
  if (state === 'saved')  return <span className="save-badge saved"><i className="bi bi-check-circle-fill"></i>Saved</span>;
  if (state === 'error')  return <span className="save-badge error"><i className="bi bi-exclamation-circle-fill"></i>Save failed</span>;
  return null;
}

function GradeInput({ value, disabled, invalid, onChange, onBlurCommit }) {
  return (
    <input type="number" min="0" max="100" step="0.01"
           className={`form-control form-control-sm ${invalid ? 'is-invalid' : ''}`}
           value={value} disabled={disabled}
           onChange={e => onChange(e.target.value)}
           onBlur={onBlurCommit} />
  );
}

function GradeSheet({ assignmentId }) {
  const [trimester, setTrimester] = useState('1');
  const [students, setStudents] = useState([]);
  const [loading, setLoading] = useState(true);
  const [message, setMessage] = useState(null);
  const { cellState, scheduleSave, doSave } = useAutoSave(assignmentId, trimester);

  const load = useCallback(() => {
    setLoading(true);
    apiFetch(`api/grades.php?assignment_id=${assignmentId}&trimester=${trimester}`)
      .then(res => {
        setStudents(res.data.students.map(s => ({ ...s, grade_value: s.grade_value ?? '', status: s.status ?? 'graded' })));
      })
      .catch(err => setMessage({ type: 'danger', text: err.message }))
      .finally(() => setLoading(false));
  }, [assignmentId, trimester]);

  useEffect(() => { load(); }, [load]);

  function updateSimple(studentId, field, value) {
    setStudents(prev => prev.map(s => s.student_id === studentId ? { ...s, [field]: value } : s));
  }
  function commitSimple(s) {
    if (s.is_locked) return;
    if (s.status === 'graded' && isBlankNew(s.grade_value, !!s.grade_id)) return; // never save an empty new cell as 0
    if (s.status === 'graded') {
      const v = parseFloat(s.grade_value);
      if (s.grade_value === '' || isNaN(v) || v < 0 || v > 100) return; // invalid input simply isn't saved yet
    }
    const key = `s${s.student_id}`;
    scheduleSave(key, [{ student_id: s.student_id, grade_value: s.status === 'graded' ? s.grade_value : null, status: s.status }], 600);
  }
  function changeStatus(s, status) {
    updateSimple(s.student_id, 'status', status);
    const key = `s${s.student_id}`;
    doSave(key, [{ student_id: s.student_id, grade_value: status === 'graded' ? s.grade_value : null, status }]);
  }

  const disabledInput = students.length === 0;

  return (
    <div className="surface">
      <div className="surface-head">
        <div className="d-flex flex-wrap align-items-center gap-2">
          {['1', '2', '3'].map(t => (
            <button key={t} className={`btn btn-sm ${trimester === t ? 'btn-primary' : 'btn-outline-primary'}`} onClick={() => setTrimester(t)}>
              {ordinal(t)} Term
            </button>
          ))}
        </div>
        <span className="small text-secondary"><i className="bi bi-cloud-check me-1"></i>Grades save automatically as you type</span>
      </div>

      {message && <div className={`alert alert-${message.type} m-3 mb-0`} role="alert">{message.text}</div>}

      {loading ? (
        <div className="empty-state">Loading…</div>
      ) : students.length === 0 ? (
        <div className="empty-state"><i className="bi bi-people"></i><strong>No active students enrolled</strong>Enroll students from the section page first.</div>
      ) : (
        <div className="table-responsive">
          <table className="table table-modern align-middle">
            <thead>
              <tr><th style={{ width: 56 }}>No.</th><th>Student Name</th><th style={{ minWidth: 150 }}>Status</th><th style={{ minWidth: 170 }}>Grade</th></tr>
            </thead>
            <tbody>
              {students.map(s => {
                const key = `s${s.student_id}`;
                return (
                  <tr key={s.student_id}>
                    <td>{s.roster_no ?? ''}</td>
                    <td>
                      {s.last_name}, {s.first_name} {s.middle_name || ''}
                      {s.is_locked ? <span className="pill ms-2">Locked</span> : null}
                    </td>
                    <td>
                      <select className="form-select form-select-sm" value={s.status} disabled={!!s.is_locked}
                              onChange={e => changeStatus(s, e.target.value)}>
                        {Object.entries(STATUS_LABELS).map(([val, label]) => <option key={val} value={val}>{label}</option>)}
                      </select>
                    </td>
                    <td>
                      <div className="d-flex align-items-center gap-2">
                        <GradeInput value={s.grade_value} disabled={s.status !== 'graded' || !!s.is_locked}
                          onChange={v => updateSimple(s.student_id, 'grade_value', v)}
                          onBlurCommit={() => commitSimple(s)} />
                        <SaveBadge state={cellState[key]} />
                      </div>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}

const rootEl = document.getElementById('grade-sheet-root');
ReactDOM.createRoot(rootEl).render(<GradeSheet assignmentId={parseInt(rootEl.dataset.assignmentId, 10)} />);
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
