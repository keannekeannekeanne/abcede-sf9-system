/* Shared front-end helpers: robust JSON fetch, toasts, filterable/paginated tables. */
(function () {
  'use strict';

  function ApiError(message, status, payload) {
    this.name = 'ApiError';
    this.message = message;
    this.status = status || 0;
    this.payload = payload || null;
  }
  ApiError.prototype = Object.create(Error.prototype);
  window.ApiError = ApiError;

  function csrfToken() {
    var m = document.querySelector('meta[name="csrf-token"]');
    return m ? m.content : '';
  }

  /**
   * fetch() wrapper for the JSON endpoints. Never throws a raw
   * "Unexpected token '<'": whatever the server sends back, callers get either
   * the parsed {success, message, data} envelope or an ApiError with a readable message.
   */
  window.apiFetch = async function (url, options) {
    var opts = Object.assign({ credentials: 'same-origin' }, options || {});
    var headers = Object.assign({ 'Accept': 'application/json' }, opts.headers || {});
    var method = (opts.method || 'GET').toUpperCase();
    if (opts.body && typeof opts.body !== 'string') {
      opts.body = JSON.stringify(opts.body);
      headers['Content-Type'] = 'application/json';
    }
    if (method !== 'GET') headers['X-CSRF-Token'] = csrfToken();
    opts.headers = headers;

    var res;
    try {
      res = await fetch(url, opts);
    } catch (e) {
      throw new ApiError('Cannot reach the server. Check your connection and try again.', 0);
    }

    var text = await res.text();
    var payload = null;
    try { payload = text ? JSON.parse(text) : null; } catch (e) { payload = null; }

    if (res.status === 401) {
      window.location.href = new URL('../index.php?expired=1', document.baseURI).href;
      throw new ApiError('Your session has expired. Redirecting to login…', 401, payload);
    }
    if (!payload || typeof payload !== 'object') {
      console.error('Non-JSON response from ' + url + ' (HTTP ' + res.status + '):', text.slice(0, 500));
      throw new ApiError(
        res.ok ? 'The server sent an unexpected response. Please try again.'
               : 'The server had a problem (error ' + res.status + '). Please try again.',
        res.status
      );
    }
    if (payload.success === false || !res.ok) {
      throw new ApiError(payload.message || 'The request failed.', res.status, payload);
    }
    return payload;
  };

  window.esc = function (v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  };

  window.toast = function (message, type) {
    var wrap = document.getElementById('toastWrap');
    if (!wrap) { wrap = document.createElement('div'); wrap.id = 'toastWrap'; wrap.className = 'toast-wrap'; wrap.setAttribute('role', 'status'); wrap.setAttribute('aria-live', 'polite'); document.body.appendChild(wrap); }
    var t = document.createElement('div');
    t.className = 'toast-item ' + (type === 'error' ? 'err' : 'ok');
    var i = document.createElement('i');
    i.className = 'bi ' + (type === 'error' ? 'bi-exclamation-circle-fill' : 'bi-check-circle-fill');
    var s = document.createElement('span');
    s.textContent = message;
    t.appendChild(i); t.appendChild(s);
    wrap.appendChild(t);
    setTimeout(function () { t.remove(); }, type === 'error' ? 7000 : 4000);
  };

  /**
   * Client-side search + status filter + pagination for a server-rendered table.
   * Rows need data-search (lowercase text) and optionally data-status.
   */
  window.initTable = function (cfg) {
    var body = document.querySelector(cfg.body);
    var rows = Array.prototype.slice.call(body.querySelectorAll('tr[data-row]'));
    var search = cfg.search && document.querySelector(cfg.search);
    var status = cfg.status && document.querySelector(cfg.status);
    var pager = document.querySelector(cfg.pager);
    var info = document.querySelector(cfg.info);
    var empty = document.querySelector(cfg.empty);
    var size = cfg.pageSize || 10;
    var page = 1;

    function apply() {
      var q = search ? search.value.trim().toLowerCase() : '';
      var st = status ? status.value : '';
      var hits = rows.filter(function (r) {
        return (!q || r.dataset.search.indexOf(q) !== -1) && (!st || r.dataset.status === st);
      });
      var pages = Math.max(1, Math.ceil(hits.length / size));
      if (page > pages) page = pages;
      rows.forEach(function (r) { r.hidden = true; });
      hits.slice((page - 1) * size, page * size).forEach(function (r) { r.hidden = false; });
      if (empty) empty.hidden = hits.length > 0;
      document.dispatchEvent(new Event('table:render'));
      if (info) info.textContent = hits.length ? 'Showing ' + ((page - 1) * size + 1) + '–' + Math.min(page * size, hits.length) + ' of ' + hits.length : 'No matching students';
      if (pager) {
        pager.innerHTML = '';
        if (pages > 1) {
          [['‹', page - 1, page === 1], ['Page ' + page + ' / ' + pages, null, true], ['›', page + 1, page === pages]].forEach(function (b) {
            var btn = document.createElement('button');
            btn.type = 'button'; btn.className = 'btn btn-sm btn-outline-secondary'; btn.textContent = b[0]; btn.disabled = b[2];
            if (b[1] !== null) btn.addEventListener('click', function () { page = b[1]; apply(); });
            pager.appendChild(btn);
          });
        }
      }
    }
    if (search) search.addEventListener('input', function () { page = 1; apply(); });
    if (status) status.addEventListener('change', function () { page = 1; apply(); });
    apply();
  };


  /**
   * Force Delete dialog (Admin only — the server re-checks the role on every call).
   * opts: { entity: 'section'|'student'|'account', id, name, onDone() }
   * Loads a preview of what would be removed/kept, shows the warning, and only deletes after the
   * admin ticks the confirmation box and presses "Force Delete". Normal Delete buttons are separate.
   */
  window.forceDelete = function (opts) {
    apiFetch('api/admin.php?resource=force_delete&entity=' + encodeURIComponent(opts.entity) + '&id=' + encodeURIComponent(opts.id))
      .then(function (r) { openForceDeleteModal(opts, r.data); })
      .catch(function (e) { toast(e.message, 'error'); });
  };

  function openForceDeleteModal(opts, pv) {
    var old = document.getElementById('forceDeleteModal');
    if (old) old.remove();

    var rows = pv.counts.map(function (c) {
      return '<li class="d-flex justify-content-between' + (c.count === 0 ? ' text-secondary' : ' fw-semibold') + '"><span>' + esc(c.label) + '</span><span>' + c.count + '</span></li>';
    }).join('');
    var keeps = pv.keeps.map(function (k) { return '<li>' + esc(k) + '</li>'; }).join('');

    var body;
    if (pv.blocked) {
      body = '<div class="alert alert-warning mb-0"><i class="bi bi-slash-circle me-1"></i>' + esc(pv.blocked) + '</div>';
    } else {
      body =
        '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i>' +
        (pv.has_related
          ? 'Warning: This record has existing related data. Force deleting it may also remove associated records. This action cannot be undone. Do you want to continue?'
          : 'Warning: This record has no related data, but it will be permanently deleted. This action cannot be undone. Do you want to continue?') +
        '</div>' +
        '<div class="small text-secondary mb-1">Related data</div>' +
        '<ul class="list-unstyled small border rounded p-2 mb-2">' + rows + '</ul>' +
        (keeps ? '<ul class="small text-secondary ps-3 mb-3">' + keeps + '</ul>' : '') +
        '<div class="form-check"><input class="form-check-input" type="checkbox" id="fdConfirm">' +
        '<label class="form-check-label" for="fdConfirm">I understand this permanently deletes the record and the data listed above.</label></div>';
    }

    var el = document.createElement('div');
    el.className = 'modal fade'; el.id = 'forceDeleteModal'; el.tabIndex = -1;
    el.innerHTML =
      '<div class="modal-dialog modal-dialog-centered"><div class="modal-content">' +
      '<div class="modal-header"><h2 class="modal-title h5 text-danger"><i class="bi bi-trash3-fill me-1"></i>Force Delete</h2>' +
      '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>' +
      '<div class="modal-body"><p class="mb-2">You are about to force delete: <strong>' + esc(pv.label) + '</strong></p>' + body + '</div>' +
      '<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">' + (pv.blocked ? 'Close' : 'Cancel') + '</button>' +
      (pv.blocked ? '' : '<button type="button" class="btn btn-danger" id="fdGo" disabled><i class="bi bi-trash3 me-1"></i>Force Delete</button>') +
      '</div></div></div>';
    document.body.appendChild(el);
    var modal = bootstrap.Modal.getOrCreateInstance(el);
    el.addEventListener('hidden.bs.modal', function () { modal.dispose(); el.remove(); });

    if (!pv.blocked) {
      var chk = el.querySelector('#fdConfirm'), go = el.querySelector('#fdGo');
      chk.addEventListener('change', function () { go.disabled = !chk.checked; });
      go.addEventListener('click', function () {
        go.disabled = true;
        apiFetch('api/admin.php', { method: 'POST', body: { resource: 'force_delete', entity: pv.entity, id: pv.id, confirmed: true } })
          .then(function (r) { modal.hide(); toast(r.message); if (opts.onDone) opts.onDone(r.data); })
          .catch(function (e) { go.disabled = !chk.checked; toast(e.message, 'error'); });
      });
    }
    modal.show();
  }

  /** multipart upload helper (file inputs) — same envelope handling as apiFetch, never a raw JSON parse error. */
  window.apiUpload = async function (url, formData) {
    var res;
    try {
      res = await fetch(url, { method: 'POST', body: formData, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrfToken() } });
    } catch (e) {
      throw new ApiError('Cannot reach the server. Check your connection and try again.', 0);
    }
    var text = await res.text(), payload = null;
    try { payload = text ? JSON.parse(text) : null; } catch (e) { payload = null; }
    if (res.status === 401) { window.location.href = new URL('../index.php?expired=1', document.baseURI).href; throw new ApiError('Your session has expired.', 401, payload); }
    if (!payload || typeof payload !== 'object') {
      console.error('Non-JSON response from ' + url + ' (HTTP ' + res.status + '):', text.slice(0, 500));
      throw new ApiError(res.status === 413 ? 'That file is too large for the server to accept.' : 'The server sent an unexpected response (error ' + res.status + '). Please try again.', res.status);
    }
    if (payload.success === false || !res.ok) throw new ApiError(payload.message || 'The upload failed.', res.status, payload);
    return payload;
  };

  /** Consistent colours for descriptor bands across charts. */
  window.BAND_COLORS = {
    'Emerging': '#c2413b', 'Developing': '#d9822b', 'Connecting': '#c9a227',
    'Benchmarking': '#2a86b8', 'Advancing': '#178a5b', 'Incomplete': '#b6bfcd', 'NLPA': '#6b7488'
  };
})();

/* ===================================================================================================
   Multi-select Delete / Force Delete and multi Add (shared helpers).
   Everything here calls the same endpoints/rules as the one-by-one actions; the server re-checks the
   Admin role and every rule on each record.
   =================================================================================================== */
(function () {
  'use strict';

  function plural(n, w) { return n + ' ' + w + (n === 1 ? '' : 's'); }

  function makeModal(id, size, html) {
    var old = document.getElementById(id); if (old) old.remove();
    var el = document.createElement('div');
    el.className = 'modal fade'; el.id = id; el.tabIndex = -1;
    el.innerHTML = '<div class="modal-dialog modal-dialog-centered modal-dialog-scrollable' + (size ? ' ' + size : '') + '"><div class="modal-content">' + html + '</div></div>';
    document.body.appendChild(el);
    var modal = bootstrap.Modal.getOrCreateInstance(el);
    el.addEventListener('hidden.bs.modal', function () { modal.dispose(); el.remove(); });
    return { el: el, modal: modal };
  }

  function nameList(items, max) {
    max = max || 8;
    var shown = items.slice(0, max).map(function (i) { return '<li>' + esc(i.label) + '</li>'; }).join('');
    var more = items.length > max ? '<li class="text-secondary">…and ' + (items.length - max) + ' more</li>' : '';
    return '<ul class="small ps-3 mb-0 bulk-names">' + shown + more + '</ul>';
  }

  /**
   * Checkbox selection for a list. Checkboxes: <input type="checkbox" class="bulk-check" value="ID" data-label="Name">.
   * cfg: { container, master: [selectors of select-all boxes], bar: selector, actions: [{key,label,icon,cls,run(items)}] }
   * "Visible" = not inside a [hidden] element, so filters/pagination never delete something you can't see.
   * Returns { items(), clear(), refresh() }.
   */
  window.bulkSelect = function (cfg) {
    var root = typeof cfg.container === 'string' ? document.querySelector(cfg.container) : cfg.container;
    var bar = typeof cfg.bar === 'string' ? document.querySelector(cfg.bar) : cfg.bar;
    var btns = {};

    function checks() { return Array.prototype.slice.call(root.querySelectorAll('input.bulk-check')); }
    function visible(c) { return !c.closest('[hidden]'); }
    function items() {
      return checks().filter(function (c) { return c.checked && visible(c); })
        .map(function (c) { return { id: parseInt(c.value, 10), label: c.dataset.label || c.value }; });
    }
    function masters() {
      var out = [];
      (cfg.master || []).forEach(function (sel) { Array.prototype.forEach.call(root.querySelectorAll(sel), function (m) { out.push(m); }); });
      return out;
    }
    function scoped(m) { return m.dataset.scope ? Array.prototype.slice.call(root.querySelectorAll(m.dataset.scope + ' input.bulk-check')) : checks(); }
    function refresh() {
      checks().forEach(function (c) { if (c.checked && !visible(c)) c.checked = false; });   // never keep hidden rows selected
      var sel = items().length;
      masters().forEach(function (m) {
        var vis = scoped(m).filter(visible), on = vis.filter(function (c) { return c.checked; }).length;
        m.checked = vis.length > 0 && on === vis.length; m.indeterminate = on > 0 && on < vis.length; m.disabled = vis.length === 0;
      });
      if (bar) {
        bar.querySelector('.bulk-count').textContent = sel ? plural(sel, 'record') + ' selected' : 'None selected';
        cfg.actions.forEach(function (a) { btns[a.key].disabled = sel === 0; btns[a.key].querySelector('.n').textContent = sel ? ' (' + sel + ')' : ''; });
        bar.querySelector('[data-bulk-clear]').hidden = sel === 0;
      }
    }
    function clear() { checks().forEach(function (c) { c.checked = false; }); refresh(); }

    if (bar) {
      bar.classList.add('bulk-bar');
      bar.innerHTML = '<span class="bulk-count text-secondary small" aria-live="polite">None selected</span><div class="bulk-actions"></div>';
      var holder = bar.querySelector('.bulk-actions');
      cfg.actions.forEach(function (a) {
        var b = document.createElement('button');
        b.type = 'button'; b.className = 'btn btn-sm ' + (a.cls || 'btn-outline-danger'); b.disabled = true;
        b.innerHTML = '<i class="bi ' + (a.icon || 'bi-trash') + ' me-1"></i>' + esc(a.label) + '<span class="n"></span>';
        b.addEventListener('click', function () {
          var it = items();
          if (!it.length) { toast('Select at least one record first.', 'error'); return; }
          a.run(it);
        });
        btns[a.key] = b; holder.appendChild(b);
      });
      var cl = document.createElement('button');
      cl.type = 'button'; cl.className = 'btn btn-sm btn-link'; cl.textContent = 'Clear'; cl.hidden = true; cl.setAttribute('data-bulk-clear', '');
      cl.addEventListener('click', clear); holder.appendChild(cl);
    }
    root.addEventListener('change', function (ev) {
      var t = ev.target;
      if (t.classList && t.classList.contains('bulk-check')) { refresh(); return; }
      if (t.matches && (cfg.master || []).some(function (s) { return t.matches(s); })) {
        // a select-all box may cover only part of the list (data-scope = selector of the rows it owns)
        scoped(t).forEach(function (c) { if (visible(c)) c.checked = t.checked; });
        refresh();
      }
    });
    refresh();
    return { items: items, clear: clear, refresh: refresh };
  };

  /** Results dialog: what worked and what didn't, per record. */
  window.showBatchResults = function (title, okItems, failItems, onClose) {
    var html =
      '<div class="modal-header"><h2 class="modal-title h5">' + esc(title) + '</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>' +
      '<div class="modal-body">' +
      (okItems.length ? '<div class="alert alert-success py-2"><i class="bi bi-check-circle-fill me-1"></i><strong>' + okItems.length + ' succeeded</strong>' + nameList(okItems, 12) + '</div>' : '') +
      (failItems.length ? '<div class="alert alert-danger py-2 mb-0"><i class="bi bi-exclamation-circle-fill me-1"></i><strong>' + failItems.length + ' could not be processed</strong>' +
        '<ul class="small ps-3 mb-0">' + failItems.map(function (f) { return '<li><strong>' + esc(f.label) + '</strong> — ' + esc(f.message) + '</li>'; }).join('') + '</ul></div>' : '') +
      '</div><div class="modal-footer"><button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button></div>';
    var m = makeModal('batchResultModal', '', html);
    if (onClose) m.el.addEventListener('hidden.bs.modal', onClose);
    m.modal.show();
  };

  /**
   * Multi-select Delete (the normal, safe delete — it still refuses records that have related data).
   * opts: { noun, items:[{id,label}], resource, idKey, onDone() }
   */
  window.bulkDelete = function (opts) {
    var n = opts.items.length;
    var html =
      '<div class="modal-header"><h2 class="modal-title h5"><i class="bi bi-trash me-1"></i>Delete ' + plural(n, opts.noun) + '?</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>' +
      '<div class="modal-body"><p class="mb-2">You are about to delete <strong>' + plural(n, opts.noun) + '</strong>. This cannot be undone.</p>' + nameList(opts.items, 10) +
      '<p class="small text-secondary mt-3 mb-0"><i class="bi bi-info-circle me-1"></i>Records that still have related data are skipped and listed afterwards. Use Force Delete for those.</p></div>' +
      '<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>' +
      '<button type="button" class="btn btn-danger" id="bdGo"><i class="bi bi-trash me-1"></i>Delete ' + n + '</button></div>';
    var m = makeModal('bulkDeleteModal', '', html);
    var go = m.el.querySelector('#bdGo');
    go.addEventListener('click', function () {
      go.disabled = true;
      var body = { resource: opts.resource, action: 'delete_many' }; body[opts.idKey] = opts.items.map(function (i) { return i.id; });
      apiFetch('api/admin.php', { method: 'POST', body: body })
        .then(function (r) {
          m.modal.hide();
          var done = function () { if (opts.onDone) opts.onDone(r.data.deleted); };
          if (r.data.failed.length) showBatchResults('Delete results', r.data.deleted, r.data.failed, done);
          else { toast(r.message); done(); }
        })
        .catch(function (e) { go.disabled = false; toast(e.message, 'error'); });
    });
    m.modal.show();
  };

  /**
   * Multi-select Force Delete (destructive). Needs: the tick box AND typing DELETE.
   * opts: { entity:'section'|'student'|'account', items:[{id,label}], onDone() }
   */
  window.bulkForceDelete = function (opts) {
    var ids = opts.items.map(function (i) { return i.id; });
    apiFetch('api/admin.php?resource=force_delete&entity=' + encodeURIComponent(opts.entity) + '&ids=' + ids.join(','))
      .then(function (r) { openBulkForce(opts, r.data); })
      .catch(function (e) { toast(e.message, 'error'); });
  };

  function openBulkForce(opts, pv) {
    var ok = pv.items.filter(function (i) { return !i.blocked; });
    var skipped = pv.items.filter(function (i) { return i.blocked; });
    var body;
    if (!ok.length) {
      body = '<div class="alert alert-warning mb-2"><i class="bi bi-slash-circle me-1"></i>None of the selected records can be force deleted.</div>';
    } else {
      body =
        '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i>' +
        '<strong>Force Delete is permanent.</strong> ' + (pv.has_related ? 'The selected records have related data that will also be removed. ' : '') + 'This cannot be undone.</div>' +
        '<p class="mb-1">Records that will be force deleted: <strong>' + ok.length + '</strong></p>' + nameList(ok, 8) +
        (pv.has_related ? '<div class="small text-secondary mt-3 mb-1">Related data in total</div><ul class="list-unstyled small border rounded p-2 mb-2">' +
          pv.totals.map(function (t) { return '<li class="d-flex justify-content-between' + (t.count ? ' fw-semibold' : ' text-secondary') + '"><span>' + esc(t.label) + '</span><span>' + t.count + '</span></li>'; }).join('') + '</ul>' : '') +
        (pv.keeps.length ? '<ul class="small text-secondary ps-3 mb-2">' + pv.keeps.map(function (k) { return '<li>' + esc(k) + '</li>'; }).join('') + '</ul>' : '') +
        '<div class="form-check mt-3"><input class="form-check-input" type="checkbox" id="bfConfirm"><label class="form-check-label" for="bfConfirm">I understand this permanently deletes ' + plural(ok.length, 'record') + ' and the data listed above.</label></div>' +
        '<label class="form-label small mt-3 mb-1" for="bfType">Type <strong>DELETE</strong> to confirm</label><input class="form-control" id="bfType" autocomplete="off" placeholder="DELETE">';
    }
    if (skipped.length) {
      body += '<div class="alert alert-warning mt-3 mb-0 py-2"><strong>' + skipped.length + ' will be skipped:</strong><ul class="small ps-3 mb-0">' +
        skipped.map(function (s) { return '<li><strong>' + esc(s.label) + '</strong> — ' + esc(s.blocked) + '</li>'; }).join('') + '</ul></div>';
    }
    var html =
      '<div class="modal-header"><h2 class="modal-title h5 text-danger"><i class="bi bi-trash3-fill me-1"></i>Force Delete ' + plural(ok.length, 'record') + '</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>' +
      '<div class="modal-body">' + body + '</div>' +
      '<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">' + (ok.length ? 'Cancel' : 'Close') + '</button>' +
      (ok.length ? '<button type="button" class="btn btn-danger" id="bfGo" disabled><i class="bi bi-trash3 me-1"></i>Force Delete ' + ok.length + '</button>' : '') + '</div>';
    var m = makeModal('bulkForceModal', '', html);
    if (ok.length) {
      var chk = m.el.querySelector('#bfConfirm'), typed = m.el.querySelector('#bfType'), go = m.el.querySelector('#bfGo');
      function gate() { go.disabled = !(chk.checked && typed.value.trim() === 'DELETE'); }
      chk.addEventListener('change', gate); typed.addEventListener('input', gate);
      go.addEventListener('click', function () {
        go.disabled = true;
        apiFetch('api/admin.php', { method: 'POST', body: { resource: 'force_delete', entity: opts.entity, ids: ok.map(function (i) { return i.id; }), confirmed: true } })
          .then(function (r) {
            m.modal.hide();
            var failed = r.data.failed.concat(skipped.map(function (s) { return { label: s.label, message: s.blocked }; }));
            var done = function () { if (opts.onDone) opts.onDone(r.data.deleted); };
            if (failed.length) showBatchResults('Force Delete results', r.data.deleted, failed, done);
            else { toast(r.message); done(); }
          })
          .catch(function (e) { gate(); toast(e.message, 'error'); });
      });
    }
    m.modal.show();
  }

  /**
   * Add several records in one go. cfg:
   *  title, noun, columns:[{key,label,type:'text'|'select'|'date'|'password',options:[[value,label]],cls,required,maxlength,placeholder}],
   *  startRows (default 5), maxRows (default 50), validate(row)->{key:msg}, dupKey(row)->string|'' (duplicate inside the list),
   *  submit(rows)->Promise<apiFetch envelope with data.results[{ok,message,errors}]>, onDone().
   * Rows that pass validation are sent; added rows lock, failed rows stay open with the reason so they can be fixed and re-sent.
   */
  window.multiAdd = function (cfg) {
    var maxRows = cfg.maxRows || 50, uid = 0, addedAny = false;
    var head = '<div class="modal-header"><h2 class="modal-title h5"><i class="bi bi-people me-1"></i>' + esc(cfg.title) + '</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>';
    var foot = '<div class="modal-footer justify-content-between"><button type="button" class="btn btn-outline-primary btn-sm" id="maAddRow"><i class="bi bi-plus-lg me-1"></i>Add row</button>' +
      '<div class="d-flex gap-2"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" id="maClose">Cancel</button><button type="button" class="btn btn-primary" id="maSave"></button></div></div>';
    var m = makeModal('multiAddModal', 'modal-xl', head + '<div class="modal-body"><div id="maSummary" class="alert" hidden></div><div id="maRows"></div></div>' + foot);
    var rowsEl = m.el.querySelector('#maRows'), sum = m.el.querySelector('#maSummary'), save = m.el.querySelector('#maSave');

    function fieldHtml(c, id) {
      var req = c.required ? ' required' : '';
      var input;
      if (c.type === 'select') {
        input = '<select class="form-select form-select-sm" data-k="' + c.key + '" id="' + id + '">' + c.options.map(function (o) { return '<option value="' + esc(o[0]) + '">' + esc(o[1]) + '</option>'; }).join('') + '</select>';
      } else {
        input = '<input class="form-control form-control-sm" data-k="' + c.key + '" id="' + id + '" type="' + (c.type || 'text') + '"' + (c.maxlength ? ' maxlength="' + c.maxlength + '"' : '') +
          (c.placeholder ? ' placeholder="' + esc(c.placeholder) + '"' : '') + ' autocomplete="off"' + req + '>';
      }
      return '<div class="' + (c.cls || 'col-6 col-lg') + '"><label class="form-label small mb-1" for="' + id + '">' + esc(c.label) + (c.required ? '' : ' <span class="text-secondary fw-normal">(optional)</span>') + '</label>' + input + '<div class="invalid-feedback"></div></div>';
    }
    function addRow() {
      if (rowsEl.children.length >= maxRows) { toast('You can add up to ' + maxRows + ' at a time.', 'error'); return; }
      var n = ++uid, r = document.createElement('div');
      r.className = 'bulk-row';
      r.innerHTML = '<div class="d-flex justify-content-between align-items-center mb-1"><span class="small fw-semibold text-secondary">#<span class="rn"></span></span><span class="status"></span>' +
        '<button type="button" class="btn btn-sm btn-link text-danger p-0" data-rm aria-label="Remove row"><i class="bi bi-x-lg"></i></button></div>' +
        '<div class="row g-2">' + cfg.columns.map(function (c) { return fieldHtml(c, 'ma' + n + c.key); }).join('') + '</div><div class="row-msg small text-danger mt-1" hidden></div>';
      r.querySelector('[data-rm]').addEventListener('click', function () { r.remove(); renum(); });
      rowsEl.appendChild(r); renum();
      return r;
    }
    function renum() {
      Array.prototype.forEach.call(rowsEl.children, function (r, i) { r.querySelector('.rn').textContent = i + 1; });
      var n = pending().length; save.textContent = n ? 'Add ' + plural(n, cfg.noun) : 'Add'; save.disabled = n === 0;
    }
    function pending() { return Array.prototype.filter.call(rowsEl.children, function (r) { return !r.classList.contains('done') && !isBlank(r); }); }
    function values(r) { var v = {}; r.querySelectorAll('[data-k]').forEach(function (i) { v[i.dataset.k] = i.value.trim(); }); return v; }
    function isBlank(r) {
      return cfg.columns.every(function (c) {
        var i = r.querySelector('[data-k="' + c.key + '"]');
        return c.type === 'select' ? i.selectedIndex === 0 : i.value.trim() === '';
      });
    }
    function mark(r, errs, msg) {
      r.querySelectorAll('.is-invalid').forEach(function (i) { i.classList.remove('is-invalid'); });
      Object.keys(errs || {}).forEach(function (k) {
        var i = r.querySelector('[data-k="' + k + '"]'); if (!i) return;
        i.classList.add('is-invalid'); i.parentNode.querySelector('.invalid-feedback').textContent = errs[k];
      });
      var el = r.querySelector('.row-msg'); el.textContent = msg || ''; el.hidden = !msg;
      r.classList.toggle('failed', !!msg);
      r.querySelector('.status').innerHTML = msg ? '<span class="pill pill-bad">Not added</span>' : '';
    }
    function lock(r, text) {
      r.classList.add('done'); r.classList.remove('failed');
      r.querySelectorAll('input,select').forEach(function (i) { i.disabled = true; });
      r.querySelector('.row-msg').hidden = true;
      r.querySelector('.status').innerHTML = '<span class="pill pill-ok"><i class="bi bi-check-lg"></i>' + esc(text || 'Added') + '</span>';
      r.querySelector('[data-rm]').hidden = true;
    }
    function banner(type, html) { sum.className = 'alert alert-' + type; sum.innerHTML = html; sum.hidden = false; }

    save.addEventListener('click', function () {
      var rows = pending(), seen = {}, send = [], bad = 0;
      if (!rows.length) { toast('Enter at least one record first.', 'error'); return; }
      rows.forEach(function (r) {
        var v = values(r), errs = cfg.validate ? (cfg.validate(v) || {}) : {}, msg = '';
        var dk = cfg.dupKey ? cfg.dupKey(v) : '';
        if (dk) { if (seen[dk]) { errs[cfg.dupField || cfg.columns[0].key] = 'Duplicate in this list.'; } else seen[dk] = true; }
        if (Object.keys(errs).length) { msg = Object.keys(errs).map(function (k) { return errs[k]; }).join(' '); bad++; mark(r, errs, msg); }
        else { mark(r, {}, ''); send.push({ r: r, v: v }); }
      });
      if (!send.length) { banner('danger', 'Fix the highlighted rows, then try again. Nothing was saved.'); return; }
      save.disabled = true;
      cfg.submit(send.map(function (s) { return s.v; }))
        .then(function (res) {
          var added = 0, failed = bad;
          res.data.results.forEach(function (x, i) {
            var r = send[i].r;
            if (x.ok) { added++; lock(r, x.message === 'Registered and enrolled.' ? 'Added' : x.message); }
            else { failed++; mark(r, x.errors && !Array.isArray(x.errors) ? x.errors : {}, x.message); }
          });
          if (added) addedAny = true;
          banner(failed ? (added ? 'warning' : 'danger') : 'success',
            '<strong>' + plural(added, cfg.noun) + ' added' + (failed ? ', ' + failed + ' not added' : '') + '.</strong>' + (failed ? ' Failed rows stay open below so you can correct and re-send them.' : ''));
          m.el.querySelector('#maClose').textContent = 'Close';
          m.el.querySelector('.modal-body').scrollTop = 0;
          renum();
        })
        .catch(function (e) { banner('danger', esc(e.message)); renum(); });
    });
    rowsEl.addEventListener('input', renum);
    m.el.querySelector('#maAddRow').addEventListener('click', function () { var r = addRow(); if (r) { var f = r.querySelector('input,select'); if (f) f.focus(); } });
    m.el.addEventListener('hidden.bs.modal', function () { if (addedAny && cfg.onDone) cfg.onDone(); });
    for (var i = 0; i < (cfg.startRows || 5); i++) addRow();
    m.modal.show();
  };

  /** initTable hook: lets a list re-check its selection after search / filter / page changes. */
  window.afterTableRender = function (fn) { document.addEventListener('table:render', fn); };
})();
