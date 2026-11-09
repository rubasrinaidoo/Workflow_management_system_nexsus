<?php
declare(strict_types=1);

require_once __DIR__ . '/authorization.php';
require_once __DIR__ . '/client_lib.php';
require_once __DIR__ . '/nexsus_shell.php'; // <-- rename if your shell file (the one with nexShellStart) has a different name

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$owner = require_owner();
$csrf = csrf_token();

$errors = [];
$openModal = false;
$submitted = false;
$old = [
    'client_type' => 'business', 'business_name' => '',
    'first_name' => '', 'middle_name' => '', 'last_name' => '',
    'contact_position' => '', 'email' => '', 'phone_number' => '',
    'preferred_contact_method' => 'phone'
];
// ---------- Add client (PHP re-validates everything the browser checks) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) { http_response_code(403); exit('Invalid security token. Reload this page.'); }

    if (post_string('action') === 'create') {
        $submitted = true;
        [$clean, $errors] = client_clean_input($_POST);
        $old = array_map(static fn($value) => (string)($value ?? ''), $clean);

        try {
            if (!$errors) {
                $duplicate = client_find_duplicate($conn, $clean);
                if ($duplicate) $errors[] = client_duplicate_message($duplicate);
            }
            if (!$errors) {
               client_exec($conn,
    "INSERT INTO clients (client_type, first_name, middle_name, last_name, contact_position, business_name, email, phone_number, preferred_contact_method, status)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')",
    [$clean['client_type'], $clean['first_name'], $clean['middle_name'], $clean['last_name'], $clean['contact_position'], $clean['business_name'], $clean['email'], $clean['phone_number'], $clean['preferred_contact_method']]);
                $newId = (int)$conn->insert_id;
                flash('success', 'Client added successfully.');
                redirect_to('clientProfile.php?id=' . $newId);
            }
        } catch (Throwable $exception) {
            error_log('NEXSUS client create: ' . $exception->getMessage());
            $errors[] = 'Could not save the client. Check your database and the server error log.';
        }
        $openModal = true;
    } else {
        $errors[] = 'Unknown client action.';
    }
}

// ---------- Red / green state for the form fields (decided by PHP) ----------
$fieldKeys = ['first_name', 'middle_name', 'last_name', 'contact_position', 'business_name', 'email', 'phone_number', 'preferred_contact_method'];
$generalErrors = array_diff_key($errors, array_flip($fieldKeys));   // duplicates, save failures, etc.
$state = static function (string $field) use ($submitted, $errors, $old): string {
    if (!$submitted) return '';
    if (isset($errors[$field])) return 'is-invalid';
    return trim($old[$field] ?? '') !== '' ? 'is-valid' : '';
};
$msg = static fn(string $field): string => h((string)($errors[$field] ?? ''));

// ---------- Filters, counts and directory ----------
$status = (string)($_GET['status'] ?? 'all');
if (!in_array($status, ['all', 'active', 'inactive'], true)) $status = 'all';
$query = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80);
$page = max(1, (int)filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['default' => 1]]));
$perPage = 20;

$stats = client_one($conn, "SELECT COUNT(*) AS total_count,
        COALESCE(SUM(status='active'),0) AS active_count,
        COALESCE(SUM(status='inactive'),0) AS inactive_count FROM clients")
    ?? ['total_count' => 0, 'active_count' => 0, 'inactive_count' => 0];

$where = ['1=1'];
$params = [];
if ($status !== 'all') { $where[] = 'status = ?'; $params[] = $status; }
if ($query !== '') {
    $like = '%' . addcslashes($query, '%_\\') . '%';
    $where[] = "(business_name LIKE ? OR first_name LIKE ? OR last_name LIKE ?
                 OR CONCAT_WS(' ', first_name, middle_name, last_name) LIKE ? OR email LIKE ?)";
    array_push($params, $like, $like, $like, $like, $like);
	
}
$whereSql = implode(' AND ', $where);
$total = (int)(client_one($conn, "SELECT COUNT(*) AS n FROM clients WHERE $whereSql", $params)['n'] ?? 0);
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;
$clients = client_rows($conn,
    "SELECT client_id, client_type, business_name, first_name, middle_name, last_name, email, phone_number, preferred_contact_method, status
     FROM clients WHERE $whereSql
     ORDER BY (status = 'inactive'), COALESCE(NULLIF(business_name, ''), CONCAT_WS(' ', first_name, last_name)) ASC, client_id DESC
     LIMIT $perPage OFFSET $offset", $params);

$listUrl = static function (array $extra = []) use ($query, $status): string {
    $args = array_filter(['q' => $query, 'status' => $status !== 'all' ? $status : '', 'page' => ''], static fn($v) => $v !== '');
    foreach ($extra as $key => $value) {
        if ($value === '' || $value === null) unset($args[$key]); else $args[$key] = $value;
    }
    return 'clients.php' . ($args ? '?' . http_build_query($args) : '') . '#clientDirectory';
};
$message = get_flash();

// Sidebar, top bar, theme switch and footer all come from the shared NEXSUS shell.
nexShellStart($owner, 'Client Management', 'clients.php', $csrf, ['mainClass' => 'employee-page']);
?>
      <div class="employee-heading">
        <div>
          <p class="eyebrow">CLIENT OPERATIONS / CLIENTS</p>
          <h2>Client Management</h2>
          <p>Keep every client's details in one place, with their requests, tasks and history linked.</p>
        </div>
        <button class="primary-button" type="button" data-open-client-modal aria-haspopup="dialog" aria-controls="clientModal"><i class="bi bi-plus-lg" aria-hidden="true"></i> Add Client</button>
      </div>

      <?php if ($message): ?><div class="task-alert success" role="status"><?= h((string)$message['message']) ?></div><?php endif; ?>
      <?php if ($errors && !$openModal): ?><div class="task-alert error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

      <section class="employee-stats" aria-label="Client totals">
        <article class="stat-card"><span class="stat-icon"><i class="bi bi-person-vcard" aria-hidden="true"></i></span><span>Total Clients</span><strong><?= (int)$stats['total_count'] ?></strong></article>
        <article class="stat-card"><span class="stat-icon green"><i class="bi bi-person-check" aria-hidden="true"></i></span><span>Active</span><strong><?= (int)$stats['active_count'] ?></strong></article>
        <article class="stat-card"><span class="stat-icon gold"><i class="bi bi-archive" aria-hidden="true"></i></span><span>Inactive</span><strong><?= (int)$stats['inactive_count'] ?></strong></article>
      </section>

      <section class="employee-directory" id="clientDirectory" aria-labelledby="directoryTitle">
        <div class="section-title">
          <div><h3 id="directoryTitle">Client Directory</h3><p>Search by business name, contact person or email.</p></div>
          <span class="employee-result-count"><?= $total ?> client<?= $total === 1 ? '' : 's' ?> found</span>
        </div>

        <form method="get" action="clients.php#clientDirectory" class="employee-filters">
          <label class="directory-search"><i class="bi bi-search" aria-hidden="true"></i><input type="search" name="q" value="<?= h($query) ?>" maxlength="80" placeholder="Search client name or email" aria-label="Search clients"></label>
          <?php if ($status !== 'all'): ?><input type="hidden" name="status" value="<?= h($status) ?>"><?php endif; ?>
          <button class="secondary-button" type="submit"><i class="bi bi-search" aria-hidden="true"></i> Search</button>
          <?php if ($query !== ''): ?><a class="secondary-button" href="<?= h($listUrl(['q' => ''])) ?>">Clear</a><?php endif; ?>
        </form>

        <nav class="employee-status-tabs" aria-label="Filter clients by status">
          <?php foreach (['all' => 'All', 'active' => 'Active', 'inactive' => 'Inactive'] as $key => $label): ?>
            <a class="employee-status-tab <?= $status === $key ? 'is-current' : '' ?>" href="<?= h($listUrl(['status' => $key === 'all' ? '' : $key])) ?>" <?= $status === $key ? 'aria-current="page"' : '' ?>><?= $label ?></a>
          <?php endforeach; ?>
        </nav>

        <div class="client-table-wrap">
          <table class="client-table">
            <thead><tr><th scope="col">Business name</th><th scope="col">Contact person</th><th scope="col">Email</th><th scope="col">Phone</th><th scope="col">Status</th><th scope="col">Action</th></tr></thead>
            <tbody>
            <?php if (!$clients): ?>
              <tr><td colspan="6"><div class="empty-state"><i class="bi bi-person-vcard" aria-hidden="true"></i><h4>No clients found</h4><p><?= ($query !== '' || $status !== 'all') ? 'Try a different search or filter.' : 'Add your first client to get started.' ?></p></div></td></tr>
            <?php else: foreach ($clients as $client): ?>
              <tr>
                <td><?php if ($client['client_type'] === 'business'): ?><strong><?= h((string)$client['business_name']) ?></strong><small>Business</small><?php else: ?><strong>—</strong><small>Individual client</small><?php endif; ?></td>
                <td><?= h(client_contact_person($client)) ?></td>
                <td><?= $client['email'] ? '<a href="mailto:' . h((string)$client['email']) . '">' . h((string)$client['email']) . '</a>' : '—' ?></td>
                <td><?= h(client_format_phone($client['phone_number'])) ?><?php $prefers = client_contact_methods()[$client['preferred_contact_method'] ?? ''] ?? ''; if ($prefers !== ''): ?><small>Prefers: <?= h($prefers) ?></small><?php endif; ?></td>
                <td><span class="status-badge status-<?= h((string)$client['status']) ?>"><?= h(ucfirst((string)$client['status'])) ?></span></td>
                <td><a class="client-view" href="clientProfile.php?id=<?= (int)$client['client_id'] ?>"><i class="bi bi-eye" aria-hidden="true"></i> View</a></td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>

        <?php if ($pages > 1): ?>
        <div class="client-pagination">
          <span>Page <?= $page ?> of <?= $pages ?></span>
          <span class="client-pagination-links">
            <?php if ($page > 1): ?><a class="secondary-button" href="<?= h($listUrl(['page' => $page - 1 > 1 ? $page - 1 : ''])) ?>">Previous</a><?php endif; ?>
            <?php if ($page < $pages): ?><a class="secondary-button" href="<?= h($listUrl(['page' => $page + 1])) ?>">Next</a><?php endif; ?>
          </span>
        </div>
        <?php endif; ?>
      </section>

<!-- Add Client modal: hidden until "Add Client" is clicked. -->
<div class="employee-modal" id="clientModal" role="dialog" aria-modal="true" aria-labelledby="newClientTitle" data-autoopen="<?= $openModal ? '1' : '0' ?>" hidden>
  <div class="employee-modal-backdrop" data-close-client-modal></div>
  <div class="employee-modal-dialog">
    <div class="modal-heading">
      <div>
        <p class="eyebrow">CLIENTS</p>
        <h2 id="newClientTitle">Add Client</h2>
        <p>Create the client record first, then add service requests for them.</p>
      </div>
      <button class="modal-close" type="button" aria-label="Close" data-close-client-modal><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    </div>

    <div class="cf-modal-body">
      <?php if ($openModal && $errors): ?>
        <div class="task-alert error" role="alert">
          <strong>Please fix the highlighted fields.</strong>
          <?php if ($generalErrors): ?><ul><?php foreach ($generalErrors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul><?php endif; ?>
        </div>
      <?php endif; ?>
      <div class="task-alert error" id="clientFormNotice" role="alert" hidden>Please fix the highlighted fields before continuing.</div>

      <form method="post" action="clients.php" class="cf-form" id="clientForm" autocomplete="off" novalidate>
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="create">

        <div class="cf-field">
          <span class="cf-label">Client type <em>*</em></span>
          <div class="cf-row">
            <label class="cf-choice"><input type="radio" name="client_type" value="business" <?= $old['client_type'] === 'business' ? 'checked' : '' ?>> <span>Business</span></label>
            <label class="cf-choice"><input type="radio" name="client_type" value="individual" <?= $old['client_type'] === 'individual' ? 'checked' : '' ?>> <span>Individual</span></label>
          </div>
        </div>

        <div class="cf-field" id="businessField">
          <label class="cf-label" for="businessName">Business name <em>*</em></label>
          <input class="cf-input <?= $state('business_name') ?>" id="businessName" name="business_name" type="text" maxlength="150" value="<?= h($old['business_name']) ?>" placeholder="e.g. ABC Traders (Pty) Ltd">
          <small class="cf-msg"><?= $msg('business_name') ?></small>
        </div>

        <div class="cf-field" id="positionField" <?= $old['client_type'] === 'business' ? '' : 'hidden' ?>>
          <label class="cf-label" for="contactPosition">Contact role / position (optional)</label>
          <input class="cf-input <?= $state('contact_position') ?>" id="contactPosition" name="contact_position" type="text" maxlength="100" value="<?= h($old['contact_position']) ?>" placeholder="e.g. Finance Manager, Owner or HR Manager" <?= $old['client_type'] === 'business' ? '' : 'disabled' ?>>
          <small class="cf-msg"><?= $msg('contact_position') ?></small>
        </div>

        <div class="cf-row">
          <div class="cf-field">
            <label class="cf-label" for="firstName"><span id="firstLabel">Contact first name</span> <em>*</em></label>
            <input class="cf-input <?= $state('first_name') ?>" id="firstName" name="first_name" type="text" maxlength="80" value="<?= h($old['first_name']) ?>" placeholder="Letters only">
            <small class="cf-msg"><?= $msg('first_name') ?></small>
          </div>
          <div class="cf-field">
            <label class="cf-label" for="middleName"><span id="middleLabel">Contact middle name</span></label>
            <input class="cf-input <?= $state('middle_name') ?>" id="middleName" name="middle_name" type="text" maxlength="80" value="<?= h($old['middle_name']) ?>" placeholder="Optional">
            <small class="cf-msg"><?= $msg('middle_name') ?></small>
          </div>
        </div>

        <div class="cf-row">
          <div class="cf-field">
            <label class="cf-label" for="lastName"><span id="lastLabel">Contact last name</span> <em>*</em></label>
            <input class="cf-input <?= $state('last_name') ?>" id="lastName" name="last_name" type="text" maxlength="80" value="<?= h($old['last_name']) ?>" placeholder="Letters only">
            <small class="cf-msg"><?= $msg('last_name') ?></small>
          </div>
          <div class="cf-field">
            <label class="cf-label" for="clientPhone">Contact number <em>*</em></label>
            <input class="cf-input <?= $state('phone_number') ?>" id="clientPhone" name="phone_number" type="tel" inputmode="tel" maxlength="20" value="<?= h($old['phone_number']) ?>" placeholder="082 123 4567">
            <small class="cf-msg"><?= $msg('phone_number') ?></small>
          </div>
        </div>

        <div class="cf-row">
          <div class="cf-field">
            <label class="cf-label" for="clientEmail">Email address <em id="emailStar" <?= $old['preferred_contact_method'] === 'email' ? '' : 'hidden' ?>>*</em></label>
            <input class="cf-input <?= $state('email') ?>" id="clientEmail" name="email" type="email" maxlength="254" value="<?= h($old['email']) ?>" placeholder="name@example.com">
            <small class="cf-msg"><?= $msg('email') ?></small>
          </div>
          <div class="cf-field">
            <label class="cf-label" for="preferredMethod">Preferred contact method <em>*</em></label>
            <select class="cf-input <?= $state('preferred_contact_method') ?>" id="preferredMethod" name="preferred_contact_method">
              <?php foreach (client_contact_methods() as $value => $label): ?>
                <option value="<?= h($value) ?>" <?= $old['preferred_contact_method'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
            <small class="cf-msg"><?= $msg('preferred_contact_method') ?></small>
          </div>
        </div>

        <p class="cf-note">Do not store tax numbers or ID numbers on the client record.</p>
      </form>
    </div>

    <!-- Footer sits outside the scrolling area so the buttons always have space below them. -->
    <div class="cf-footer">
      <button type="button" class="task-btn outline" data-close-client-modal>Cancel</button>
      <button class="task-btn primary" type="submit" form="clientForm"><i class="bi bi-check2" aria-hidden="true"></i> Add Client</button>
    </div>
  </div>
</div>

<script>
(function () {
  var modal = document.getElementById('clientModal');
  var form = document.getElementById('clientForm');
  if (!modal || !form) return;
  var notice = document.getElementById('clientFormNotice');
  var lastTrigger = null;

  /* ---------- Open / close the modal ---------- */
  function openModal(trigger) {
    lastTrigger = trigger || null;
    modal.hidden = false;
    document.body.classList.add('employee-modal-open');
    var first = form.querySelector('.cf-field:not([hidden]) input.cf-input');
    if (first) first.focus();
  }
  function closeModal() {
    modal.hidden = true;
    document.body.classList.remove('employee-modal-open');
    if (lastTrigger) lastTrigger.focus();
  }
  document.querySelectorAll('[data-open-client-modal]').forEach(function (b) { b.addEventListener('click', function () { openModal(b); }); });
  modal.querySelectorAll('[data-close-client-modal]').forEach(function (b) { b.addEventListener('click', closeModal); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) closeModal(); });

  /* ---------- Rules (the SAME rules PHP enforces in client_lib.php) ---------- */
  var NAME = /^\p{L}+(?:[ '’-]\p{L}+)*$/u;
  var BUSINESS = /^[\p{L}\p{N}][\p{L}\p{N} &.,'’()\/+-]*$/u;
  var EMAIL = /^[^\s@]+@[^\s@]+\.[A-Za-z]{2,}$/;
  var MOBILE = /^\+27(?:[67][0-9]|8[1-5])[0-9]{7}$/;

  // Returns the number as +27821234567, or null when it is not a believable real number.
  function normalizePhone(raw) {
    if (!/^\+?[0-9 ()\-]+$/.test(raw)) return null;
    var plus = raw.charAt(0) === '+';
    var d = raw.replace(/\D/g, '');
    if (!plus && d.indexOf('00') === 0) { d = d.slice(2); plus = true; }
    if (plus) {
      if (!/^[1-9][0-9]{7,14}$/.test(d)) return null;
      if (d.indexOf('27') === 0 && !/^27[1-8][0-9]{8}$/.test(d)) return null;
    } else {
      if (!/^0[1-8][0-9]{8}$/.test(d)) return null;
      d = '27' + d.slice(1);
    }
    if (/(\d)\1{6,}/.test(d)) return null;
    if ('01234567890123456789'.indexOf(d.slice(-9)) !== -1) return null;
    return '+' + d;
  }
  function isBusiness() { return form.querySelector('input[name="client_type"]:checked').value === 'business'; }
  function method() { return form.elements['preferred_contact_method'].value; }
  function nameRule(label, required) {
    return function (v) {
      if (!v) return required ? label + ' is required.' : '';
      if (required && (v.length < 2 || v.length > 80)) return label + ' must be 2–80 characters.';
      if (v.length > 80) return label + ' must be 80 characters or fewer.';
      if (!NAME.test(v)) return label + ' may only contain letters (spaces, hyphens and apostrophes between words are fine).';
      return '';
    };
  }
  // Listed in the order the boxes appear, so the first problem is the one that gets focus.
  var rules = {
    business_name: function (v) {
      if (!isBusiness()) return '';
      if (v.length < 2 || v.length > 150) return 'Business name must be 2–150 characters.';
      if (!BUSINESS.test(v) || !/\p{L}/u.test(v)) return 'Business name may only use letters, numbers, spaces and & . , \' ( ) / + - and must contain a letter.';
      return '';
    },
    first_name: nameRule('First name', true),
    middle_name: nameRule('Middle name', false),
    last_name: nameRule('Last name', true),
    contact_position: function (v) {
      if (!isBusiness() || !v) return '';
      if (v.length > 100) return 'Contact role must be 100 characters or fewer.';
      if (!/^\p{L}[\p{L}\p{N} &.,'’()\/-]*$/u.test(v)) {
        return 'Contact role may contain letters, numbers, spaces and & . , apostrophes ( ) / - only.';
      }
      return '';
    },
	
    phone_number: function (v) {
      if (!v) return 'Contact number is required.';
      var n = normalizePhone(v);
      if (!n) return 'Enter a real phone number, for example 082 123 4567 or +27 82 123 4567.';
      if ((method() === 'whatsapp' || method() === 'sms') && n.indexOf('+27') === 0 && !MOBILE.test(n)) {
        return 'WhatsApp and SMS need a mobile number (starting 06, 07 or 08).';
      }
      return '';
    },
    email: function (v) {
      if (!v) return method() === 'email' ? 'Email address is required when email is the preferred way to contact the client.' : '';
      return (v.length > 254 || !EMAIL.test(v)) ? 'Enter a valid email address, for example name@example.com.' : '';
    },
    preferred_contact_method: function (v) {
      return v ? '' : 'Choose how the client prefers to be contacted.';
    }
  };

  /* ---------- Green / red feedback ---------- */
  function apply(input) {
    var value = input.value.replace(/\s+/g, ' ').trim();
    var message = rules[input.name](value);
    var out = input.closest('.cf-field').querySelector('.cf-msg');
    input.classList.toggle('is-invalid', !!message);
    input.classList.toggle('is-valid', !message && value !== '');
    input.setAttribute('aria-invalid', message ? 'true' : 'false');
    if (out) out.textContent = message;
    return message;
  }
  Object.keys(rules).forEach(function (name) {
    var input = form.elements[name];
    if (!input) return;
    input.addEventListener('blur', function () { input.dataset.touched = '1'; apply(input); });
    input.addEventListener('input', function () {
      if (name === 'phone_number') input.value = input.value.replace(/[^0-9+ ()\-]/g, ''); // block letters while typing
      if (input.dataset.touched === '1' || input.classList.contains('is-invalid')) apply(input);
    });
  });

  /* ---------- Preferred method changes what email / phone need ---------- */
  var emailStar = document.getElementById('emailStar');
  form.elements['preferred_contact_method'].addEventListener('change', function () {
    emailStar.hidden = method() !== 'email';
    apply(form.elements['preferred_contact_method']);
    ['email', 'phone_number'].forEach(function (name) {
      var f = form.elements[name];
      if (f.dataset.touched === '1' || f.classList.contains('is-invalid')) apply(f);
    });
  });

  /* ---------- Business vs individual ---------- */
  var businessField = document.getElementById('businessField');
  function syncType() {
    var business = isBusiness();
    businessField.hidden = !business;
    var positionField = document.getElementById('positionField');
    var positionInput = form.elements['contact_position'];
    positionField.hidden = !business;
    positionInput.disabled = !business;
    if (!business) apply(positionInput);
    document.getElementById('firstLabel').textContent = business ? 'Contact first name' : 'First name';
    document.getElementById('middleLabel').textContent = business ? 'Contact middle name' : 'Middle name';
    document.getElementById('lastLabel').textContent = business ? 'Contact last name' : 'Last name';
    if (!business) apply(form.elements['business_name']);
  }
  form.querySelectorAll('input[name="client_type"]').forEach(function (r) { r.addEventListener('change', syncType); });
  syncType();

  /* ---------- Stop the form and warn before moving on ---------- */
  form.addEventListener('submit', function (e) {
    var firstBad = null;
    Object.keys(rules).forEach(function (name) {
      var input = form.elements[name];
      if (input && apply(input) && !firstBad) firstBad = input;
    });
    notice.hidden = !firstBad;
    if (firstBad) { e.preventDefault(); firstBad.focus(); }
  });

  if (modal.dataset.autoopen === '1') openModal(null);
})();
</script>
<?php nexShellEnd(); ?>
