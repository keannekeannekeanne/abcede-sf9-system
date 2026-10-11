<?php /* "Add multiple learners" form — shared by Enroll Students (teacher/admin) and the Admin Panel Students tab.
        Uses multiAdd() from assets/js/app.js. Each row goes through the same register() rules as the single form. */ ?>
<script>
/**
 * opts: { url, sectionId, onDone(), asBody(rows) -> request body }
 * Default body matches api/enrollment.php; the Admin Panel passes asBody for api/admin.php.
 */
window.openMultiAddStudents = function (opts) {
  var today = new Date().toISOString().slice(0, 10);
  multiAdd({
    title: 'Add multiple learners', noun: 'learner', startRows: 5, maxRows: 50, dupField: 'lrn',
    columns: [
      { key: 'lrn', label: 'LRN (12 digits)', required: true, maxlength: 12, cls: 'col-12 col-sm-6 col-lg-3', placeholder: '136700000001' },
      { key: 'last_name', label: 'Last name', required: true, maxlength: 100, cls: 'col-12 col-sm-6 col-lg-3' },
      { key: 'first_name', label: 'First name', required: true, maxlength: 100, cls: 'col-12 col-sm-6 col-lg-3' },
      { key: 'middle_name', label: 'Middle name', maxlength: 100, cls: 'col-12 col-sm-6 col-lg-3' },
      { key: 'gender', label: 'Sex', required: true, type: 'select', options: [['', 'Select…'], ['Male', 'Male'], ['Female', 'Female']], cls: 'col-6 col-lg-3' },
      { key: 'birthdate', label: 'Birthdate', type: 'date', cls: 'col-6 col-lg-3' }
    ],
    validate: function (v) {
      var e = {};
      if (!/^\d{12}$/.test(v.lrn)) e.lrn = 'LRN must be exactly 12 digits.';
      if (!v.last_name) e.last_name = 'Last name is required.';
      if (!v.first_name) e.first_name = 'First name is required.';
      if (v.gender !== 'Male' && v.gender !== 'Female') e.gender = 'Select Male or Female.';
      if (v.birthdate && v.birthdate > today) e.birthdate = 'Enter a valid birthdate.';
      return e;
    },
    dupKey: function (v) { return v.lrn; },
    submit: function (rows) {
      var body = opts.asBody ? opts.asBody(rows) : { action: 'register_many', section_id: opts.sectionId, students: rows };
      return apiFetch(opts.url, { method: 'POST', body: body }).then(function (res) { if (opts.onAdded && res.data.added) opts.onAdded(res.data.added); return res; });
    },
    onDone: opts.onDone
  });
};
</script>
