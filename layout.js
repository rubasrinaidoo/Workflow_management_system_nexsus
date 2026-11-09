
const sidebarToggle = document.getElementById('sidebarToggle');
const sidebarBackdrop = document.getElementById('sidebarBackdrop');
const modeSwitch = document.getElementById('switch-mode');
const navigationSearch = document.getElementById('navigationSearch');
const mobileView = window.matchMedia('(max-width: 920px)');

// Remember sidebar state
try {
  if (localStorage.getItem('nexsus-sidebar-collapsed') === 'yes') {
    document.body.classList.add('sidebar-collapsed');
  }
} catch (_) {}

function updateSidebar() {
  const mobileOpen = document.body.classList.contains('mobile-sidebar-open');

  if (sidebarBackdrop) {
    sidebarBackdrop.hidden = !mobileOpen;
  }

  sidebarToggle?.setAttribute(
    'aria-expanded',
    String(
      mobileView.matches
        ? mobileOpen
        : !document.body.classList.contains('sidebar-collapsed')
    )
  );
}

function closeMobileSidebar() {
  document.body.classList.remove('mobile-sidebar-open');
  updateSidebar();
}

sidebarToggle?.addEventListener('click', () => {
  if (mobileView.matches) {
    document.body.classList.toggle('mobile-sidebar-open');
  } else {
    const collapsed = document.body.classList.toggle('sidebar-collapsed');

    try {
      localStorage.setItem(
        'nexsus-sidebar-collapsed',
        collapsed ? 'yes' : 'no'
      );
    } catch (_) {}
  }

  updateSidebar();
});

sidebarBackdrop?.addEventListener('click', closeMobileSidebar);

document.addEventListener('keydown', event => {
  if (event.key === 'Escape') closeMobileSidebar();
});

mobileView.addEventListener('change', closeMobileSidebar);
updateSidebar();

// Light / dark theme
function setTheme(dark) {
  document.documentElement.dataset.theme = dark ? 'dark' : 'light';

  if (modeSwitch) {
    modeSwitch.checked = dark;
    modeSwitch.setAttribute(
      'aria-label',
      dark ? 'Disable dark mode' : 'Enable dark mode'
    );
  }
}

try {
  setTheme(localStorage.getItem('nexsus-theme') === 'dark');
} catch (_) {
  setTheme(false);
}

modeSwitch?.addEventListener('change', () => {
  setTheme(modeSwitch.checked);

  try {
    localStorage.setItem(
      'nexsus-theme',
      modeSwitch.checked ? 'dark' : 'light'
    );
  } catch (_) {}
});

// Navigation search
navigationSearch?.addEventListener('input', () => {
  const query = navigationSearch.value.trim().toLowerCase();

  document.querySelectorAll('.nav-group').forEach(group => {
    let found = false;

    group.querySelectorAll('.nav-link').forEach(link => {
      const match = link.textContent.toLowerCase().includes(query);
      link.hidden = !match;

      if (match) found = true;
    });

    group.hidden = !found;
  });
});

// Add Employee modal
const employeeModal = document.getElementById('employeeModal');
let previousFocus = null;

function showEmployeeModal() {
  if (!employeeModal) return;

  previousFocus = document.activeElement;
  employeeModal.hidden = false;
  document.body.classList.add('employee-modal-open');

  document.getElementById('openEmployeeModal')
    ?.setAttribute('aria-expanded', 'true');

  employeeModal.querySelector('.employee-modal-dialog')
    ?.focus();
}

function hideEmployeeModal() {
  if (!employeeModal) return;

  employeeModal.hidden = true;
  document.body.classList.remove('employee-modal-open');

  document.getElementById('openEmployeeModal')
    ?.setAttribute('aria-expanded', 'false');

  previousFocus?.focus();
}

document.getElementById('openEmployeeModal')
  ?.addEventListener('click', showEmployeeModal);

document.querySelectorAll('[data-close-employee]').forEach(button => {
  button.addEventListener('click', hideEmployeeModal);
});

document.addEventListener('keydown', event => {
  if (
    event.key === 'Escape' &&
    employeeModal &&
    !employeeModal.hidden
  ) {
    hideEmployeeModal();
  }
});

if (
  document.body.dataset.openAdd === 'yes' ||
  employeeModal?.dataset.autoopen === 'yes'
) {
  showEmployeeModal();
}

// Employee status confirmation
document.querySelectorAll('[data-confirm-status]').forEach(button => {
  button.closest('form')?.addEventListener('submit', event => {
    const action = button.dataset.confirmStatus === 'deactivate'
      ? 'Deactivate'
      : 'Reactivate';

    if (!window.confirm(
      `${action} this employee account? Existing login sessions will end.`
    )) {
      event.preventDefault();
    }
  });
});

// Employee success toast
const employeeToast = document.getElementById('employeeToast');

if (employeeToast) {
  if (window.bootstrap?.Toast) {
    window.bootstrap.Toast
      .getOrCreateInstance(employeeToast, { delay: 6500 })
      .show();
  } else {
    employeeToast.classList.add('show');

    setTimeout(() => {
      employeeToast.classList.remove('show');
    }, 6500);
  }
}

// Name validation
const namePattern =
  /^\p{L}[\p{L}\p{M}]*(?:[ '\u2019-]\p{L}[\p{L}\p{M}]*)*$/u;

function checkField(field, shake = false) {
  if (
    !field ||
    field.readOnly ||
    field.type === 'hidden'
  ) return;

  if (['first_name', 'last_name', 'full_name'].includes(field.name)) {
    field.setCustomValidity('');

    if (field.value.trim()) {
      if (!namePattern.test(field.value.trim())) {
        field.setCustomValidity(
          'Only letters, spaces, hyphens and apostrophes are allowed.'
        );
      } else if (
        field.name === 'full_name' &&
        !field.value.trim().includes(' ')
      ) {
        field.setCustomValidity('Enter first and last name.');
      }
    }
  }

  const invalid = !field.checkValidity();

  field.classList.toggle('is-invalid', invalid);
  field.setAttribute('aria-invalid', String(invalid));

  const parent = field.closest('label');
  let feedback = parent?.querySelector('.field-feedback');

  if (!invalid) {
    feedback?.remove();
    return;
  }

  if (!feedback && parent) {
    feedback = document.createElement('small');
    feedback.className = 'field-feedback';
    parent.append(feedback);
  }

  if (feedback) {
    feedback.textContent =
      field.dataset.error || field.validationMessage;
  }

  if (shake) {
    field.classList.remove('animation-shake');
    void field.offsetWidth;
    field.classList.add('animation-shake');
  }
}

// Password requirements: 12–64 characters
function passwordChecks(password) {
  return {
    length:
      password.length >= 12 &&
      password.length <= 64 &&
      /^[!-~]*$/.test(password),

    lowercase: /[a-z]/.test(password),
    uppercase: /[A-Z]/.test(password),
    number: /[0-9]/.test(password),
    symbol: /[^A-Za-z0-9\s]/.test(password)
  };
}

// Temporary password and confirmation
document.querySelectorAll('[data-password-form]').forEach(form => {
  const password = form.querySelector('[name="temporary_password"]');
  const confirmPassword = form.querySelector('[name="confirm_password"]');

  if (!password || !confirmPassword) return;

  const strengthBar = form.querySelector('[data-strength-bar]');
  const strengthLabel = form.querySelector('[data-password-label]');

  function updateStrength() {
    const checks = passwordChecks(password.value);
    const points = Object.values(checks).filter(Boolean).length;

    const level = !password.value
      ? ''
      : points < 3
        ? 'weak'
        : points < 5
          ? 'fair'
          : 'strong';

    if (strengthBar) {
      strengthBar.style.width = ((points / 5) * 100) + '%';
      strengthBar.dataset.level = level;
    }

    if (strengthLabel) {
      strengthLabel.textContent = !password.value
        ? 'Not entered'
        : points < 3
          ? 'Weak'
          : points < 5
            ? 'Almost there'
            : 'All rules met';
    }

    form.querySelectorAll('[data-password-rule]').forEach(item => {
      const met = checks[item.dataset.passwordRule] === true;
      item.classList.toggle('is-met', met);
    });

    return points === 5;
  }

  password.addEventListener('input', () => {
    password.setCustomValidity('');
    updateStrength();
  });

  confirmPassword.addEventListener('input', () => {
    confirmPassword.setCustomValidity('');
  });

  form.addEventListener('submit', event => {
    password.setCustomValidity(
      updateStrength()
        ? ''
        : 'Use 12–64 characters with lowercase, uppercase, a number and a symbol (no spaces).'
    );

    confirmPassword.setCustomValidity(
      password.value === confirmPassword.value
        ? ''
        : 'Passwords do not match.'
    );

    const inputs = [
      ...form.querySelectorAll('input:not([type="hidden"])')
    ];

    inputs.forEach(input => {
      checkField(input, !input.checkValidity());
    });

    const invalid = inputs.find(input => !input.checkValidity());

    if (invalid) {
      event.preventDefault();
      invalid.focus();
    }
  });

  updateStrength();
});

// Validate employee fields while typing
document.querySelectorAll(
  '.add-employee-form input, .detail-form input'
).forEach(field => {
  if (field.readOnly || field.type === 'hidden') return;

  field.addEventListener('blur', () => {
    checkField(field, true);
  });

  field.addEventListener('input', () => {
    if (field.classList.contains('is-invalid')) {
      checkField(field);
    }
  });
});

// Show / hide BOTH temporary and confirm password
document.querySelectorAll('[data-show-password]').forEach(button => {
  button.addEventListener('click', () => {
    const input = button
      .closest('.password-input-wrap')
      ?.querySelector('input');

    if (!input) return;

    const visible = input.type === 'password';
    input.type = visible ? 'text' : 'password';

    const fieldName = input.name === 'confirm_password'
      ? 'confirm password'
      : 'temporary password';

    button.setAttribute(
      'aria-label',
      (visible ? 'Hide ' : 'Show ') + fieldName
    );

    button.setAttribute('aria-pressed', String(visible));

    const icon = button.querySelector('.bi');

    if (icon) {
      icon.className = visible
        ? 'bi bi-eye-slash'
        : 'bi bi-eye';
    }
  });
});

// Check username availability
document.querySelectorAll('[data-username-check]').forEach(field => {
  const form = field.closest('form');
  const feedback = field.closest('label')
    ?.querySelector('.username-feedback');

  if (!form || !feedback) return;

  let timer = null;
  let serial = 0;

  const check = async () => {
    const username = field.value.trim();
    const thisSerial = ++serial;

    feedback.classList.remove('available', 'taken');

    if (!username) {
      feedback.textContent = '';
      return;
    }

    if (!/^[A-Za-z][A-Za-z0-9._-]{7,49}$/.test(username)) {
      feedback.textContent =
        'Use 8–50 characters, beginning with a letter.';
      feedback.classList.add('taken');
      return;
    }

    feedback.textContent = 'Checking username…';

    const data = new URLSearchParams({
      action: 'check_username',
      username,
      csrf_token:
        form.querySelector('[name="csrf_token"]')?.value || '',
      employee_id:
        form.querySelector('[name="employee_id"]')?.value || '0'
    });

    try {
      const response = await fetch('employee.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: data
      });

      if (!response.ok) throw new Error('Unavailable');

      const result = await response.json();

      if (
        thisSerial !== serial ||
        field.value.trim() !== username
      ) return;

      feedback.textContent =
        result.message || 'Cannot check username.';

      feedback.classList.toggle(
        'available',
        result.valid && result.available
      );

      feedback.classList.toggle(
        'taken',
        !result.valid || !result.available
      );

    } catch (_) {
      if (thisSerial !== serial) return;
      feedback.textContent =
        'Could not check now. PHP will verify when saving.';
    }
  };

  field.addEventListener('input', () => {
    ++serial;
    clearTimeout(timer);

    feedback.classList.remove('available', 'taken');

    feedback.textContent = field.value
      ? 'Checking format…'
      : '';

    timer = setTimeout(check, 400);
  });

  field.addEventListener('blur', () => {
    clearTimeout(timer);
    check();
  });
});

// Optional employee profile picture preview
document.querySelectorAll('[data-photo-picker]').forEach(picker => {
  const field = picker.querySelector(
    'input[type="file"][name="employee_photo"]'
  );

  const img = picker.querySelector('[data-photo-preview]');
  const placeholder = picker.querySelector('[data-photo-placeholder]');
  const note = picker.querySelector('[data-photo-note]');

  if (!field || !img || !placeholder) return;

  const existingSrc = img.getAttribute('src');
  let objectUrl = null;

  const restorePreview = () => {
    if (objectUrl) {
      URL.revokeObjectURL(objectUrl);
      objectUrl = null;
    }

    if (existingSrc) {
      img.src = existingSrc;
      img.hidden = false;
      placeholder.hidden = true;
    } else {
      img.removeAttribute('src');
      img.hidden = true;
      placeholder.hidden = false;
    }

    if (note) {
      note.classList.remove('is-error');
      note.textContent =
        'Optional — JPG, PNG or WebP · maximum 2 MB';
    }
  };

  field.addEventListener('change', () => {
    const file = field.files?.[0];

    if (!file) {
      restorePreview();
      return;
    }

    const supported = [
      'image/jpeg',
      'image/png',
      'image/webp'
    ].includes(file.type);

    if (
      !supported ||
      file.size === 0 ||
      file.size > 2 * 1024 * 1024
    ) {
      field.value = '';
      restorePreview();

      if (note) {
        note.classList.add('is-error');
        note.textContent =
          'Choose a JPG, PNG or WebP under 2 MB.';
      }

      return;
    }

    if (objectUrl) URL.revokeObjectURL(objectUrl);

    objectUrl = URL.createObjectURL(file);
    img.src = objectUrl;
    img.hidden = false;
    placeholder.hidden = true;

    if (note) {
      note.classList.remove('is-error');
      note.textContent = file.name;
    }
  });
});

/* ================= DOCUMENT MANAGEMENT ONLY =================
   Add once at the END of your existing shared layout.js.
   PHP performs all security and database operations. */
(() => {
  const root = document.getElementById('docRows');
  if (!root) return; // Prevent running on Dashboard, Employees, Tasks, etc.
  const $ = id => document.getElementById(id);
  const data = window.NEXSUS_DOCUMENTS || {};
  const clients = data.clients || [];
  const requests = data.requests || [];
  const tasks = data.tasks || [];
  const docs = data.documents || [];
  const history = data.history || [];
  const maxFiles = 5, maxTotal = 30 * 1024 * 1024;
  const limits = {pdf:10, xlsx:10, docx:10, jpg:5, jpeg:5, png:5, csv:2, txt:2};
  let files = [];

  const ext = name => (name.split('.').pop() || '').toLowerCase();
  const bytes = n => n < 1024 ? `${n} bytes` : n < 1048576 ? `${(n/1024).toFixed(1)} KB` : `${(n/1048576).toFixed(2)} MB`;
  const clientName = id => clients.find(c => String(c.id) === String(id))?.name || '—';
  const requestName = id => requests.find(r => String(r.id) === String(id))?.title || 'General document';
  const setError = message => { $('docUploadError').textContent = message; $('docUploadError').hidden = !message; };
  const addOption = (select, label, value) => select.add(new Option(label, String(value)));

  clients.forEach(c => {
    addOption($('docClient'), c.name, c.id);
    addOption($('docFilterClient'), c.name, c.id);
  });
  requests.forEach(r => addOption($('docFilterRequest'), r.title, r.id));
  [...new Set(docs.map(d => d.category))].sort().forEach(c => addOption($('docFilterCategory'), c, c));
  [...new Set(docs.map(d => d.extension))].sort().forEach(e => addOption($('docFilterType'), e.toUpperCase(), e));
  function updateRequests() {
    const previous = $('docRequest').value;
    $('docRequest').replaceChildren(new Option('General client document', ''));
    requests.filter(r => String(r.client_id) === $('docClient').value)
      .forEach(r => addOption($('docRequest'), r.title, r.id));
    if ([...$('docRequest').options].some(o => o.value === previous)) $('docRequest').value = previous;
  }
  function updateTasks() {
    const previous = $('docTask').value;
    $('docTask').replaceChildren(new Option('No related task', ''));
    tasks.filter(t => String(t.client_id) === $('docClient').value &&
      (!$('docRequest').value || String(t.service_request_id) === $('docRequest').value))
      .forEach(t => addOption($('docTask'), t.title, t.id));
    if ([...$('docTask').options].some(o => o.value === previous)) $('docTask').value = previous;
  }
  $('docClient').addEventListener('change', () => { updateRequests(); updateTasks(); });
  $('docRequest').addEventListener('change', updateTasks);
  // Context-aware selection: links from clients.php or serviceRequests.php.
  // PHP MUST independently verify these IDs; browser query strings are untrusted.
  const params = new URLSearchParams(location.search);
  const requested = requests.find(r => String(r.id) === params.get('service_request_id'));
  const suggestedClient = requested?.client_id || params.get('client_id');
  if (clients.some(c => String(c.id) === String(suggestedClient))) $('docClient').value = String(suggestedClient);
  updateRequests();
  if (requested && String(requested.client_id) === $('docClient').value) $('docRequest').value = String(requested.id);
  updateTasks();
  const requestedTask = params.get('task_id');
  if ([...$('docTask').options].some(o => o.value === requestedTask)) $('docTask').value = requestedTask;

  function render() {
    const query = $('docSearch').value.trim().toLowerCase();
    const status = $('docFilterStatus').value;
    const visible = docs.filter(d => {
      const searchText = [d.title, d.original_filename, clientName(d.client_id), d.description].join(' ').toLowerCase();
      return searchText.includes(query)
        && (!$('docFilterClient').value || String(d.client_id) === $('docFilterClient').value)
        && (!$('docFilterRequest').value || String(d.service_request_id) === $('docFilterRequest').value)
        && (!$('docFilterCategory').value || d.category === $('docFilterCategory').value)
        && (!$('docFilterType').value || d.extension === $('docFilterType').value)
        && (status === 'all' || (status === 'active' ? d.status !== 'archived' : d.status === status));
    });
    visible.sort((a,b) => $('docSort').value === 'az' ? a.title.localeCompare(b.title) :
      $('docSort').value === 'old' ? String(a.uploaded_at).localeCompare(String(b.uploaded_at)) :
      String(b.uploaded_at).localeCompare(String(a.uploaded_at)));
    root.replaceChildren();
    visible.forEach(d => {
      const tr = document.createElement('tr');
      const cell = value => { const td = tr.insertCell(); td.textContent = String(value ?? '—'); return td; };
      const filename = cell(d.title);
      const original = document.createElement('small'); original.textContent = d.original_filename;
      filename.append(original);
      cell(clientName(d.client_id)); cell(requestName(d.service_request_id)); cell(d.category);
      const statusCell = tr.insertCell(); const badge = document.createElement('span');
      badge.className = `doc-status ${d.status}`; badge.textContent = d.status; statusCell.append(badge);
      cell(bytes(Number(d.file_size_bytes))); cell(`${d.uploaded_at} · ${d.uploaded_by_name || ''}`);
      const actions = tr.insertCell(); const wrap = document.createElement('div'); wrap.className = 'doc-row-actions';
      const button = (label, action) => {
        const b = document.createElement('button'); b.type = 'button'; b.textContent = label;
        b.dataset.action = action; b.dataset.id = d.id; wrap.append(b);
      };
      if (['pdf','jpg','jpeg','png'].includes(d.extension)) button('Preview','preview');
      const link = document.createElement('a'); link.textContent = 'Download';
      link.href = `documents-file.php?id=${encodeURIComponent(d.id)}&mode=download`; wrap.append(link);
      button('History','history');
      if (d.status !== 'archived') { button('Edit','edit'); button('Replace','replace'); button('Archive','archive'); }
      actions.append(wrap); root.append(tr);
    });
    $('docCount').textContent = `${visible.length} document(s)`;
    $('docEmpty').hidden = visible.length > 0;
    const active = docs.filter(d => d.status !== 'archived');
    $('docTotal').textContent = active.length;
    $('docClientCount').textContent = new Set(active.map(d => d.client_id)).size;
    const cutoff = Date.now() - 30 * 86400000;
    $('docRecent').textContent = active.filter(d => {
      const when = Date.parse(String(d.uploaded_at).replace(' ', 'T'));
      return Number.isFinite(when) && when >= cutoff;
    }).length;
  }
  ['docSearch','docFilterClient','docFilterRequest','docFilterCategory','docFilterType','docFilterStatus','docSort']
    .forEach(id => $(id).addEventListener('input', render));
  $('docClearFilters').addEventListener('click', () => {
    $('docSearch').value = '';
    ['docFilterClient','docFilterRequest','docFilterCategory','docFilterType'].forEach(id => $(id).value = '');
    $('docFilterStatus').value = 'active'; $('docSort').value = 'new'; render();
  });
  render();

  const open = id => $(id).showModal();
  document.querySelectorAll('[data-doc-close]').forEach(b => b.addEventListener('click', () => b.closest('dialog').close()));
  $('docOpenUpload').addEventListener('click', () => open('docUploadDialog'));
  $('docOpenHistory').addEventListener('click', () => showHistory());
  function showHistory(documentId = null) {
    const list = $('docHistoryList'); list.replaceChildren();
    history.filter(v => documentId === null || String(v.document_id) === String(documentId))
      .sort((a,b) => String(b.uploaded_at).localeCompare(String(a.uploaded_at)))
      .forEach(v => {
        const li = document.createElement('li');
        const left = document.createElement('span');
        left.textContent = `Version ${v.version_number} · ${v.original_filename}`;
        const small = document.createElement('small');
        small.textContent = ` ${v.uploaded_by_name || ''} · ${v.uploaded_at}`;
        left.append(document.createElement('br'), small); li.append(left); list.append(li);
      });
    if (!list.children.length) { const li = document.createElement('li'); li.textContent = 'No upload history found.'; list.append(li); }
    open('docHistoryDialog');
  }
  root.addEventListener('click', event => {
    const btn = event.target.closest('button[data-action]'); if (!btn) return;
    const d = docs.find(x => String(x.id) === btn.dataset.id); if (!d) return;
    if (btn.dataset.action === 'history') return showHistory(d.id);
    if (btn.dataset.action === 'edit') {
      $('docEditId').value = d.id; $('docEditName').value = d.title;
      $('docEditCategory').value = d.category;
      $('docEditStatus').value = d.status; $('docEditDescription').value = d.description || '';
      return open('docEditDialog');
    }
    if (btn.dataset.action === 'replace') {
      $('docReplaceId').value = d.id; $('docReplacement').value = '';
      $('docReplacementInfo').textContent = ''; return open('docReplaceDialog');
    }
    if (btn.dataset.action === 'archive') {
      if (confirm(`Archive "${d.title}"? This keeps its version history.`)) {
        $('docArchiveId').value = d.id; $('docArchiveForm').requestSubmit();
      }
      return;
    }
    if (btn.dataset.action === 'preview') {
      $('docPreviewTitle').textContent = d.title;
      const preview = $('docPreviewBody'); preview.replaceChildren();
      const url = `documents-file.php?id=${encodeURIComponent(d.id)}&mode=preview`;
      const media = d.extension === 'pdf' ? document.createElement('iframe') : document.createElement('img');
      media.src = url; media.title = d.title; if (media.tagName === 'IMG') media.alt = d.title;
      preview.append(media); return open('docPreviewDialog');
    }
  });
  $('docPreviewDialog').addEventListener('close', () => $('docPreviewBody').replaceChildren());

  // Files: browser-only validation for UX; PHP MUST independently validate the same limits.
  const validate = f => {
    const extension = ext(f.name);
    if (!(extension in limits)) return `${f.name}: format is not supported.`;
    if (!f.size || f.size > limits[extension]*1048576) return `${f.name}: invalid file size (maximum ${limits[extension]} MiB).`;
    return '';
  };
  function drawFiles() {
    const list = $('docFileList'); list.replaceChildren();
    $('docFileCount').textContent = `(${files.length}/5)`;
    $('docFileTotal').textContent = bytes(files.reduce((n,f) => n+f.size, 0));
    files.forEach((f,i) => {
      const li = document.createElement('li'); const name = document.createElement('span');
      name.textContent = `${f.name} · ${ext(f.name).toUpperCase()} · ${bytes(f.size)}`;
      const rm = document.createElement('button'); rm.type = 'button'; rm.textContent = '×';
      rm.setAttribute('aria-label', `Remove ${f.name}`);
      rm.addEventListener('click', () => { files.splice(i,1); drawFiles(); });
      li.append(name,rm); list.append(li);
    });
    const transfer = new DataTransfer(); files.forEach(f => transfer.items.add(f));
    $('docFiles').files = transfer.files;
  }
  function addFiles(incoming) {
    setError(''); const additions = [...incoming];
    if (files.length + additions.length > maxFiles) return setError('You may upload a maximum of 5 files together.');
    for (const f of additions) { const err = validate(f); if (err) return setError(err); }
    if ([...files,...additions].reduce((n,f)=>n+f.size,0)>maxTotal) return setError('Combined files exceed 30 MiB.');
    files.push(...additions); drawFiles();
  }
  $('docBrowse').addEventListener('click', () => $('docFiles').click());
  $('docFiles').addEventListener('change', e => { const incoming = [...e.target.files]; addFiles(incoming); });
  const zone = $('docDropzone');
  ['dragenter','dragover'].forEach(type => zone.addEventListener(type, e => {e.preventDefault();zone.classList.add('is-dragging');}));
  ['dragleave','drop'].forEach(type => zone.addEventListener(type, e => {e.preventDefault();zone.classList.remove('is-dragging');}));
  zone.addEventListener('drop', e => addFiles(e.dataTransfer.files));
  $('docUploadForm').addEventListener('submit', e => {
    if (!files.length) { e.preventDefault();setError('Choose at least one document.');return; }
    if (!$('docClient').value || !$('docCategory').value) {e.preventDefault();setError('Select a client and category.');}
    // PHP TODO: backend validates CSRF and access; then inserts document and version records.
  });
  $('docReplacement').addEventListener('change', e => {
    const f = e.target.files[0]; $('docReplacementInfo').textContent = f ? `${f.name} · ${bytes(f.size)}` : '';
    if (f) $('docReplacement').setCustomValidity(validate(f)); else $('docReplacement').setCustomValidity('');
  });
  $('docReplaceForm').addEventListener('submit', e => {
    const f = $('docReplacement').files[0]; if (!f || validate(f)) { e.preventDefault();$('docReplacement').reportValidity(); }
    // PHP TODO: re-verify the file and insert a new version; never overwrite old versions.
  });
})();
/* NEXSUS Service Requests - append ONCE to existing layout.js.
   The existing sidebar, theme, Employee modal and password code stay intact.
   JavaScript only improves UX; PHP checks everything again before saving. */
(() => {
  const form = document.getElementById('nexRequestForm');
  if (!form) return; // Do not run on other dashboard pages.
  const category = form.querySelector('[data-nex-category]');
  const service = form.querySelector('[data-nex-service]');
  const due = form.querySelector('[name="due_date"]');
  const summaryFields = ['client_id','category','service_id','assigned_to','due_date','priority'];

  function filterServices() {
    if (!category || !service) return;
    const chosen = category.value;
    for (const option of service.options) {
      if (!option.value) continue;
      const valid = option.dataset.category === chosen;
      option.hidden = !valid;
      option.disabled = !valid;
    }
    if (service.selectedOptions[0]?.disabled) service.value = '';
  }
  function selectedText(name) {
    const input = form.elements.namedItem(name);
    if (!input) return 'Not selected';
    if (input.tagName === 'SELECT') {
      if (!input.value) return 'Not selected';
      return input.selectedOptions[0]?.textContent?.trim() || 'Not selected';
    }
    return input.value || 'Not selected';
  }
  function updateSummary() {
    for (const name of summaryFields) {
      const target = form.querySelector(`[data-summary-value="${name}"]`);
      if (target) target.textContent = selectedText(name);
    }
  }
  category?.addEventListener('change', () => {
    if (service) service.value = '';
    filterServices();
    updateSummary();
  });
  form.addEventListener('input', updateSummary);
  form.addEventListener('change', updateSummary);
  due?.addEventListener('change', () => {
    due.setCustomValidity('');
    if (due.value && due.min && due.value < due.min) {
      due.setCustomValidity('Deadline cannot be earlier than today.');
    }
  });
  filterServices();
  updateSummary();
})();
/* Documents page only; paste once at end of the existing layout.js. */
(() => {
  const form = document.querySelector('[data-nxs-document-form]');
  if (!form) return;
  const client = form.querySelector('[name="client_id"]');
  const request = form.querySelector('[name="service_request_id"]');
  const task = form.querySelector('[name="task_id"]');
  const file = form.querySelector('[name="document"]');
  const filename = form.querySelector('[data-nxs-filename]');
  const drop = form.querySelector('[data-nxs-file-drop]');
  const sync = (changed) => {
    if (!client || !request || !task || request.tagName !== 'SELECT') return;
    if (changed === 'task' && task.selectedOptions[0]?.dataset.client) {
      client.value = task.selectedOptions[0].dataset.client;
      request.value = task.selectedOptions[0].dataset.request || '';
    }
    if (changed === 'request' && request.selectedOptions[0]?.dataset.client) {
      client.value = request.selectedOptions[0].dataset.client;
    }
    for (const option of request.options) {
      if (!option.value) continue;
      const allowed = !client.value || option.dataset.client === client.value;
      option.hidden = !allowed;
      option.disabled = !allowed;
      if (!allowed && option.selected) request.value = '';
    }
    for (const option of task.options) {
      if (!option.value) continue;
      const allowed = (!client.value || option.dataset.client === client.value)
        && (!request.value || option.dataset.request === request.value);
      option.hidden = !allowed;
      option.disabled = !allowed;
      if (!allowed && option.selected) task.value = '';
    }
  };
  client?.addEventListener('change', () => { request.value = ''; task.value = ''; sync('client'); });
  request?.addEventListener('change', () => { task.value = ''; sync('request'); });
  task?.addEventListener('change', () => sync('task'));
  file?.addEventListener('change', () => {
    if (filename) filename.textContent = file.files?.[0]
      ? `${file.files[0].name} (${(file.files[0].size / (1024 * 1024)).toFixed(2)} MB)`
      : 'No file selected';
  });
  if (drop && file) {
    ['dragenter', 'dragover'].forEach((type) => drop.addEventListener(type, (event) => {
      event.preventDefault();
      drop.classList.add('is-dragging');
    }));
    ['dragleave', 'drop'].forEach((type) => drop.addEventListener(type, (event) => {
      event.preventDefault();
      drop.classList.remove('is-dragging');
    }));
    drop.addEventListener('drop', (event) => {
      const files = event.dataTransfer?.files;
      if (!files?.length) return;
      try {
        file.files = files;
        file.dispatchEvent(new Event('change', { bubbles: true }));
      } catch (_) {
        if (filename) filename.textContent = 'Use Choose File to select the dropped document.';
      }
    });
  }
  sync();
})();


/* Document upload modal; no Bootstrap JavaScript needed.
   REPLACES the block that starts with "Document upload modal" at the END of layout.js. */
(() => {
  const dialog = document.querySelector('[data-docs-dialog]');
  if (!dialog) return;

  const open = () => {
    if (typeof dialog.showModal === 'function') {
      if (!dialog.open) dialog.showModal();
    } else {
      dialog.setAttribute('open', '');
    }
  };
  const close = () => {
    if (typeof dialog.close === 'function') dialog.close();
    else dialog.removeAttribute('open');
  };

  document.querySelectorAll('[data-open-docs-dialog]').forEach(b => b.addEventListener('click', open));
  dialog.querySelectorAll('[data-close-docs-dialog]').forEach(b => b.addEventListener('click', close));

  // Close only when the dark backdrop itself is clicked.
  // (The old coordinate check also closed the dialog on keyboard "clicks", which report x=0,y=0.)
  dialog.addEventListener('click', event => { if (event.target === dialog) close(); });

  // Block files over 10 MB before they are sent (PHP still checks again).
  const fileInput = dialog.querySelector('input[type="file"][name="document"]');
  fileInput?.addEventListener('change', () => {
    const file = fileInput.files?.[0];
    if (file && file.size > 10 * 1024 * 1024) {
      window.alert('This file is larger than 10 MB. Please choose a smaller file.');
      fileInput.value = '';
      fileInput.dispatchEvent(new Event('change', { bubbles: true })); // resets the file-name label
    }
  });

  // One confirmation per archive / restore form.
  document.querySelectorAll('form[data-docs-confirm]').forEach(form => {
    form.addEventListener('submit', event => {
      if (!window.confirm(form.getAttribute('data-docs-confirm') || 'Continue?')) event.preventDefault();
    });
  });

  if (dialog.dataset.autoopen === '1') open();
})();
// Owner-only Remove Duplicate action; PHP enforces permissions and task count.
document.querySelectorAll('[data-nex-remove-request]').forEach(form => {
  form.addEventListener('submit', event => {
    const id = form.dataset.requestId || '';
    if (!window.confirm(`Remove service request #${id} from active lists?\n\nThis will mark it Cancelled and preserve its history. It will NOT permanently delete the database record.`)) {
      event.preventDefault();
    }
  });
});


/* Service Requests: real popup, matching Add Client's modal behaviour.
   Always close without saving; PHP keeps all permission, duplicate and validation checks. */
(() => {
  const dialog = document.getElementById('requestModal');
  if (!dialog) return;
  const openers = document.querySelectorAll('[data-open-request-dialog]');
  let previousFocus = null;
  function openDialog() {
    if (dialog.open) return;
    previousFocus = document.activeElement;
    dialog.showModal();
    dialog.querySelector('[name="client_id"]')?.focus({preventScroll: true});
  }
  function closeDialog() {
    if (dialog.open) dialog.close();
  }
  openers.forEach(link => link.addEventListener('click', event => {
    // An Edit screen has preloaded record values: reload before creating a new request.
    if (dialog.dataset.editing === '1') return;
    event.preventDefault();
    openDialog();
  }));
  dialog.querySelectorAll('[data-close-request-dialog]').forEach(button => {
    button.addEventListener('click', closeDialog);
  });
  dialog.addEventListener('click', event => {
    if (event.target === dialog) closeDialog();
  });
  dialog.addEventListener('close', () => previousFocus?.focus?.());
  if (dialog.dataset.autoopen === '1') openDialog();
})();

/* Close the shared profile dropdown using the X button */

document.addEventListener('click', function (event) {
    const closeButton = event.target.closest(
        '[data-close-profile-menu]'
    );

    if (!closeButton) return;

    const profileMenu = closeButton.closest(
        'details.profile-dropdown'
    );

    if (!profileMenu) return;

    profileMenu.open = false;

    const profileTrigger = profileMenu.querySelector('summary');

    if (profileTrigger) {
        profileTrigger.focus();
    }
});
