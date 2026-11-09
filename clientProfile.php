<?php
declare(strict_types=1);

require_once __DIR__ . '/authorization.php';
require_once __DIR__ . '/client_lib.php';
require_once __DIR__ . '/nexsus_shell.php'; // <-- rename if your shell file (the one with nexShellStart) has a different name

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$owner = require_owner();
$csrf = csrf_token();

$clientId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$client = $clientId ? client_one($conn, 'SELECT * FROM clients WHERE client_id = ?', [(int)$clientId]) : null;
if (!$client) {
    http_response_code(404);
    exit('Client not found. <a href="clients.php">Back to clients</a>');
}
$clientId = (int)$client['client_id'];
$profileUrl = 'clientProfile.php?id=' . $clientId;

$errors = [];
$editOpen = false;
$submitted = false;
$storedPhone = (string)($client['phone_number'] ?? '');
$old = [
    'client_type' => (string)$client['client_type'],
    'business_name' => (string)($client['business_name'] ?? ''),
    'first_name' => (string)($client['first_name'] ?? ''),
	'contact_position' => (string)($client['contact_position'] ?? ''),
    'middle_name' => (string)($client['middle_name'] ?? ''),
    'last_name' => (string)($client['last_name'] ?? ''),
    'email' => (string)($client['email'] ?? ''),
    'phone_number' => $storedPhone !== '' ? client_format_phone($storedPhone) : '',
    'preferred_contact_method' => (string)($client['preferred_contact_method'] ?? 'phone'),
];

// ---------- Actions: update details, deactivate (archive), reactivate ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) { http_response_code(403); exit('Invalid security token. Reload this page.'); }
    $action = post_string('action');

    try {
        if ($action === 'update') {
            $submitted = true;
            [$clean, $errors] = client_clean_input($_POST);
            $old = array_map(static fn($value) => (string)($value ?? ''), $clean);
            if (!$errors) {
                $duplicate = client_find_duplicate($conn, $clean, $clientId);
                if ($duplicate) $errors[] = client_duplicate_message($duplicate);
            }
            if (!$errors) {
                client_exec($conn,
                    'UPDATE clients SET client_type=?, first_name=?, middle_name=?, last_name=?, contact_position=?, business_name=?, email=?, phone_number=?, preferred_contact_method=? WHERE client_id=?',
                    [$clean['client_type'], $clean['first_name'], $clean['middle_name'], $clean['last_name'], $clean['contact_position'], $clean['business_name'], $clean['email'], $clean['phone_number'], $clean['preferred_contact_method'], $clientId]
                );
                flash('success', 'Client details updated.');
                redirect_to($profileUrl);
            }
            $editOpen = true;
        } elseif ($action === 'deactivate') {
            $open = (int)(client_one($conn,
                "SELECT COUNT(*) AS n FROM service_requests WHERE client_id = ? AND request_status IN ('pending','in_progress')",
                [$clientId])['n'] ?? 0);
            if ($open > 0) {
                $errors[] = 'This client still has ' . $open . ' open service request(s). Complete or close them before deactivating the client.';
            } else {
                client_exec($conn, "UPDATE clients SET status='inactive' WHERE client_id = ?", [$clientId]);
                flash('success', 'Client deactivated. Their records and history are kept.');
                redirect_to($profileUrl);
            }
        } elseif ($action === 'reactivate') {
            client_exec($conn, "UPDATE clients SET status='active' WHERE client_id = ?", [$clientId]);
            flash('success', 'Client reactivated.');
            redirect_to($profileUrl);
        } else {
            $errors[] = 'Unknown client action.';
        }
    } catch (Throwable $exception) {
        error_log('NEXSUS client profile: ' . $exception->getMessage());
        $errors[] = 'Could not save the change. Check your database and the server error log.';
        if ($action === 'update') $editOpen = true;
    }
}

// ---------- Red / green state for the edit form (decided by PHP) ----------
$fieldKeys = ['first_name', 'middle_name', 'last_name', 'business_name','contact_position', 'email', 'phone_number', 'preferred_contact_method'];
$generalErrors = array_diff_key($errors, array_flip($fieldKeys));   // duplicates, save failures, etc.
$state = static function (string $field) use ($submitted, $errors, $old): string {
    if (!$submitted) return '';
    if (isset($errors[$field])) return 'is-invalid';
    return trim($old[$field] ?? '') !== '' ? 'is-valid' : '';
};
$msg = static fn(string $field): string => h((string)($errors[$field] ?? ''));

// ---------- Linked records ----------
$requests = client_rows($conn,
    "SELECT sr.service_request_id, sr.due_date, sr.request_status, s.service_name,
            COUNT(t.task_id) AS task_total, COALESCE(SUM(t.status <> 'completed'), 0) AS task_open
     FROM service_requests sr
     JOIN services s ON s.service_id = sr.service_id
     LEFT JOIN tasks t ON t.service_request_id = sr.service_request_id
     WHERE sr.client_id = ?
     GROUP BY sr.service_request_id, sr.due_date, sr.request_status, s.service_name
     ORDER BY (sr.request_status IN ('pending','in_progress')) DESC, sr.due_date ASC, sr.service_request_id DESC
     LIMIT 100", [$clientId]);

$tasks = client_rows($conn,
    "SELECT t.task_id, t.task_title, t.status, t.priority, t.due_date, t.service_request_id,
            u.full_name AS employee_name, s.service_name
     FROM tasks t
     JOIN service_requests sr ON sr.service_request_id = t.service_request_id
     JOIN services s ON s.service_id = sr.service_id
     LEFT JOIN users u ON u.user_id = t.assigned_to
     WHERE sr.client_id = ?
     ORDER BY (t.status = 'completed'), t.due_date ASC, t.task_id DESC
     LIMIT 100", [$clientId]);

$history = client_rows($conn,
    "SELECT h.entity_type, h.entity_id, h.previous_status, h.new_status, h.progress_note, h.changed_at,
            u.full_name AS changed_by_name, t.task_title
     FROM workflow_history h
     LEFT JOIN users u ON u.user_id = h.changed_by
     LEFT JOIN tasks t ON h.entity_type = 'task' AND t.task_id = h.entity_id
     WHERE (h.entity_type = 'task' AND h.entity_id IN (
              SELECT t2.task_id FROM tasks t2
              JOIN service_requests s2 ON s2.service_request_id = t2.service_request_id
              WHERE s2.client_id = ?))
        OR (h.entity_type = 'service_request' AND h.entity_id IN (
              SELECT service_request_id FROM service_requests WHERE client_id = ?))
     ORDER BY h.changed_at DESC LIMIT 50", [$clientId, $clientId]);

// Documents: the table is read defensively so this page never breaks if it is renamed.
$documents = [];
$documentsAvailable = true;
try {
    $documents = client_rows($conn, 'SELECT * FROM documents WHERE client_id = ? ORDER BY 1 DESC LIMIT 10', [$clientId]);
} catch (Throwable $exception) {
    $documentsAvailable = false;
}

// ---------- Summary numbers ----------
$today = date('Y-m-d');
$activeRequests = count(array_filter($requests, static fn($r) => in_array($r['request_status'], ['pending', 'in_progress'], true)));
$openTasks = array_values(array_filter($tasks, static fn($t) => $t['status'] !== 'completed'));
$overdueTasks = count(array_filter($openTasks, static fn($t) => $t['due_date'] < $today));
$nextDeadline = $openTasks ? min(array_column($openTasks, 'due_date')) : null;
$team = array_values(array_unique(array_filter(array_column($openTasks, 'employee_name'))));
$isActive = $client['status'] === 'active';
$isBusiness = $client['client_type'] === 'business';
$preferredLabel = client_contact_methods()[(string)($client['preferred_contact_method'] ?? '')] ?? '—';
$message = get_flash();
$statusLabel = static fn(?string $s): string => $s === null || $s === '' ? 'New' : ucwords(str_replace('_', ' ', $s));

// Sidebar, top bar, notification bell, theme switch and footer come from the shared NEXSUS shell.
nexShellStart($owner, 'Client Profile', 'clients.php', $csrf, ['mainClass' => 'employee-page']);
?>
      <div class="employee-heading">
        <div>
          <p class="eyebrow"><a class="text-button" href="clients.php"><i class="bi bi-arrow-left" aria-hidden="true"></i> CLIENTS</a></p>
          <h2><?= h(client_display_name($client)) ?></h2>
          <div class="client-badges">
            <span class="status-badge status-<?= h((string)$client['status']) ?>"><?= h(ucfirst((string)$client['status'])) ?></span>
            <span class="status-badge status-inactive"><?= $isBusiness ? 'Business' : 'Individual' ?></span>
          </div>
        </div>
        <div class="client-heading-actions">
          <?php if ($isActive): ?><a class="primary-button" href="serviceRequests.php?mode=add&amp;client_id=<?= $clientId ?>#requestForm"><i class="bi bi-plus-lg" aria-hidden="true"></i> New Service Request</a><?php endif; ?>
          <a class="secondary-button" href="documents.php?client_id=<?= $clientId ?>"><i class="bi bi-folder2-open" aria-hidden="true"></i> Documents</a>
        </div>
      </div>

      <?php if ($message): ?><div class="task-alert success" role="status"><?= h((string)$message['message']) ?></div><?php endif; ?>
      <?php if ($errors && !$editOpen): ?><div class="task-alert error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
      <?php if (!$isActive): ?><div class="task-alert info" role="status">This client is inactive. Their records and history are kept. Reactivate them before creating new service requests.</div><?php endif; ?>

      <nav class="employee-status-tabs" aria-label="Profile sections">
        <a class="employee-status-tab" href="#overview">Overview</a>
        <a class="employee-status-tab" href="#services">Services</a>
        <a class="employee-status-tab" href="#tasks">Tasks &amp; Deadlines</a>
        <a class="employee-status-tab" href="#documents">Documents</a>
        <a class="employee-status-tab" href="#history">Workflow History</a>
      </nav>

      <!-- OVERVIEW -->
      <section class="employee-directory client-section" id="overview" aria-labelledby="overviewTitle">
        <div class="section-title"><div><h3 id="overviewTitle">Client Information</h3><p>Contact details and a snapshot of current work.</p></div></div>
        <div class="task-facts">
          <div><small>Active requests</small><strong><?= $activeRequests ?></strong></div>
          <div><small>Open tasks</small><strong><?= count($openTasks) ?></strong></div>
          <div><small>Overdue tasks</small><strong><?= $overdueTasks ?></strong></div>
          <div><small>Next deadline</small><strong><?= h($nextDeadline ?? 'None') ?></strong></div>
        </div>
        <dl class="profile-facts">
          <?php if ($isBusiness): ?><div><dt>Business name</dt><dd><?= h((string)$client['business_name']) ?></dd></div><?php endif; ?>
          <div><dt><?= $isBusiness ? 'Contact person' : 'Name' ?></dt><dd><?= h(client_contact_person($client)) ?></dd></div>
          <?php if ($isBusiness): ?><div><dt>Contact role / position</dt><dd><?= h((string)($client['contact_position'] ?: 'Not specified')) ?></dd></div><?php endif; ?>
          <div><dt>Email</dt><dd><?= $client['email'] ? '<a href="mailto:' . h((string)$client['email']) . '">' . h((string)$client['email']) . '</a>' : '—' ?></dd></div>
          <div><dt>Phone</dt><dd><?= h(client_format_phone($client['phone_number'] ?? null)) ?></dd></div>
          <div><dt>Preferred contact method</dt><dd><?= h($preferredLabel) ?></dd></div>
          <div><dt>Client since</dt><dd><?= h((string)$client['created_at']) ?></dd></div>
          <div><dt>Last updated</dt><dd><?= h((string)$client['updated_at']) ?></dd></div>
        </dl>
        <div class="detail-tasks">
          <h4>Team working on this client</h4>
          <?php if ($team): ?><div class="client-team"><?php foreach ($team as $member): ?><span><i class="bi bi-person" aria-hidden="true"></i> <?= h((string)$member) ?></span><?php endforeach; ?></div>
          <?php else: ?><p class="small-note">No employee has an open task for this client yet.</p><?php endif; ?>
        </div>

        <details class="task-edit" <?= $editOpen ? 'open' : '' ?>>
          <summary><i class="bi bi-pencil-square" aria-hidden="true"></i> Edit client details</summary>
          <?php if ($editOpen && $errors): ?>
            <div class="task-alert error" role="alert" style="margin-top:14px">
              <strong>Please fix the highlighted fields.</strong>
              <?php if ($generalErrors): ?><ul><?php foreach ($generalErrors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul><?php endif; ?>
            </div>
          <?php endif; ?>
          <div class="task-alert error" id="clientEditNotice" role="alert" hidden>Please fix the highlighted fields before saving.</div>

          <form method="post" action="<?= h($profileUrl) ?>#overview" class="cf-form" id="clientEditForm" autocomplete="off" novalidate>
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="update">

            <div class="cf-row">
              <div class="cf-field">
                <label class="cf-label" for="editType">Client type <em>*</em></label>
                <select class="cf-input" id="editType" name="client_type">
                  <option value="business" <?= $old['client_type'] === 'business' ? 'selected' : '' ?>>Business</option>
                  <option value="individual" <?= $old['client_type'] === 'individual' ? 'selected' : '' ?>>Individual</option>
                </select>
              </div>
              <div class="cf-field" id="editBusinessField">
                <label class="cf-label" for="editBusiness">Business name <em>*</em></label>
                <input class="cf-input <?= $state('business_name') ?>" id="editBusiness" name="business_name" type="text" maxlength="150" value="<?= h($old['business_name']) ?>" placeholder="e.g. ABC Traders (Pty) Ltd">
                <small class="cf-msg"><?= $msg('business_name') ?></small>
              </div>
            </div>

            <div class="cf-field" id="editPositionField" <?= $old['client_type'] === 'business' ? '' : 'hidden' ?>>
              <label class="cf-label" for="editPosition">Contact role / position (optional)</label>
              <input class="cf-input <?= $state('contact_position') ?>" id="editPosition" name="contact_position" type="text" maxlength="100" value="<?= h($old['contact_position']) ?>" placeholder="e.g. Finance Manager, Owner or HR Manager" <?= $old['client_type'] === 'business' ? '' : 'disabled' ?>>
              <small class="cf-msg"><?= $msg('contact_position') ?></small>
            </div>

            <div class="cf-row">
              <div class="cf-field">
                <label class="cf-label" for="editFirst"><span id="editFirstLabel">Contact first name</span> <em>*</em></label>
                <input class="cf-input <?= $state('first_name') ?>" id="editFirst" name="first_name" type="text" maxlength="80" value="<?= h($old['first_name']) ?>" placeholder="Letters only">
                <small class="cf-msg"><?= $msg('first_name') ?></small>
              </div>
              <div class="cf-field">
                <label class="cf-label" for="editMiddle"><span id="editMiddleLabel">Contact middle name</span></label>
                <input class="cf-input <?= $state('middle_name') ?>" id="editMiddle" name="middle_name" type="text" maxlength="80" value="<?= h($old['middle_name']) ?>" placeholder="Optional">
                <small class="cf-msg"><?= $msg('middle_name') ?></small>
              </div>
            </div>

            <div class="cf-row">
              <div class="cf-field">
                <label class="cf-label" for="editLast"><span id="editLastLabel">Contact last name</span> <em>*</em></label>
                <input class="cf-input <?= $state('last_name') ?>" id="editLast" name="last_name" type="text" maxlength="80" value="<?= h($old['last_name']) ?>" placeholder="Letters only">
                <small class="cf-msg"><?= $msg('last_name') ?></small>
              </div>
              <div class="cf-field">
                <label class="cf-label" for="editPhone">Contact number <em>*</em></label>
                <input class="cf-input <?= $state('phone_number') ?>" id="editPhone" name="phone_number" type="tel" inputmode="tel" maxlength="20" value="<?= h($old['phone_number']) ?>" placeholder="082 123 4567">
                <small class="cf-msg"><?= $msg('phone_number') ?></small>
              </div>
            </div>

            <div class="cf-row">
              <div class="cf-field">
                <label class="cf-label" for="editEmail">Email address <em id="editEmailStar" <?= $old['preferred_contact_method'] === 'email' ? '' : 'hidden' ?>>*</em></label>
                <input class="cf-input <?= $state('email') ?>" id="editEmail" name="email" type="email" maxlength="254" value="<?= h($old['email']) ?>" placeholder="name@example.com">
                <small class="cf-msg"><?= $msg('email') ?></small>
              </div>
              <div class="cf-field">
                <label class="cf-label" for="editMethod">Preferred contact method <em>*</em></label>
                <select class="cf-input <?= $state('preferred_contact_method') ?>" id="editMethod" name="preferred_contact_method">
                  <?php foreach (client_contact_methods() as $value => $label): ?>
                    <option value="<?= h($value) ?>" <?= $old['preferred_contact_method'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                  <?php endforeach; ?>
                </select>
                <small class="cf-msg"><?= $msg('preferred_contact_method') ?></small>
              </div>
            </div>

            <p class="cf-note">Do not store tax numbers or ID numbers on the client record.</p>
            <div class="cf-actions">
              <button class="task-btn primary" type="submit"><i class="bi bi-check2" aria-hidden="true"></i> Save Changes</button>
            </div>
          </form>
        </details>

        <div class="account-controls">
          <h4>Client status</h4>
          <p class="small-note"><?= $isActive
              ? 'Deactivating archives the client without deleting anything: requests, tasks, documents and history stay available. A client with open service requests cannot be deactivated.'
              : 'This client is archived. Reactivate to create new service requests for them.' ?></p>
          <form method="post" action="<?= h($profileUrl) ?>#overview" class="status-controls" data-confirm="<?= $isActive ? 'Deactivate this client? Their records and history are kept.' : 'Reactivate this client?' ?>">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="<?= $isActive ? 'deactivate' : 'reactivate' ?>">
            <?php if ($isActive): ?><button class="danger-button" type="submit"><i class="bi bi-archive" aria-hidden="true"></i> Deactivate client</button>
            <?php else: ?><button class="success-button" type="submit"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Reactivate client</button><?php endif; ?>
          </form>
        </div>
      </section>

      <!-- SERVICES -->
      <section class="employee-directory client-section" id="services" aria-labelledby="servicesTitle">
        <div class="section-title"><div><h3 id="servicesTitle">Service Requests</h3><p>Everything this client has asked NEXSUS to do.</p></div><span class="employee-result-count"><?= count($requests) ?> request(s)</span></div>
        <?php if (!$requests): ?>
          <p class="small-note">No service requests yet for this client.</p>
        <?php else: ?>
          <div class="client-table-wrap"><table class="client-table">
            <thead><tr><th scope="col">Request</th><th scope="col">Service</th><th scope="col">Status</th><th scope="col">Deadline</th><th scope="col">Tasks (open / total)</th></tr></thead>
            <tbody>
            <?php foreach ($requests as $request): ?>
              <tr>
                <td><strong><a class="client-view" href="serviceRequests.php?view=<?= (int)$request['service_request_id'] ?>#requestDetails">#<?= (int)$request['service_request_id'] ?></a></strong></td>
                <td><?= h((string)$request['service_name']) ?></td>
                <td><span class="task-badge <?= h((string)$request['request_status']) ?>"><?= h($statusLabel((string)$request['request_status'])) ?></span></td>
                <td><?= h((string)$request['due_date']) ?></td>
                <td><?= (int)$request['task_open'] ?> / <?= (int)$request['task_total'] ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table></div>
        <?php endif; ?>
      </section>

      <!-- TASKS & DEADLINES -->
      <section class="employee-directory client-section" id="tasks" aria-labelledby="tasksTitle">
        <div class="section-title"><div><h3 id="tasksTitle">Tasks &amp; Deadlines</h3><p>Work linked to this client's requests, open tasks first.</p></div><span class="employee-result-count"><?= count($tasks) ?> task(s)</span></div>
        <?php if (!$tasks): ?>
          <p class="small-note">No tasks yet. Tasks are created from <a class="text-button" href="tasks.php">Task Management</a> against one of this client's service requests.</p>
        <?php else: ?>
          <div class="client-table-wrap"><table class="client-table">
            <thead><tr><th scope="col">Task</th><th scope="col">Assigned to</th><th scope="col">Deadline</th><th scope="col">Priority</th><th scope="col">Status</th></tr></thead>
            <tbody>
            <?php foreach ($tasks as $task): ?>
              <?php $overdue = $task['status'] !== 'completed' && $task['due_date'] < $today; ?>
              <tr>
                <td><strong><a class="client-view" href="tasks.php?view=<?= (int)$task['task_id'] ?>#taskDetails"><?= h((string)$task['task_title']) ?></a></strong><small>Request #<?= (int)$task['service_request_id'] ?> · <?= h((string)$task['service_name']) ?></small></td>
                <td><?= h((string)($task['employee_name'] ?? 'Unassigned')) ?></td>
                <td><?= h((string)$task['due_date']) ?><?php if ($overdue): ?> <span class="task-badge overdue">Overdue</span><?php endif; ?></td>
                <td><span class="task-badge priority-<?= h((string)$task['priority']) ?>"><?= h(ucfirst((string)$task['priority'])) ?></span></td>
                <td><span class="task-badge <?= h((string)$task['status']) ?>"><?= h($statusLabel((string)$task['status'])) ?></span></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table></div>
        <?php endif; ?>
      </section>

      <!-- DOCUMENTS -->
      <section class="employee-directory client-section" id="documents" aria-labelledby="documentsTitle">
        <div class="section-title"><div><h3 id="documentsTitle">Documents</h3><p>Files uploaded for this client.</p></div><a class="secondary-button" href="documents.php?client_id=<?= $clientId ?>"><i class="bi bi-upload" aria-hidden="true"></i> Upload / manage</a></div>
        <?php if (!$documentsAvailable): ?>
          <p class="small-note">Open the Documents page to see this client's files.</p>
        <?php elseif (!$documents): ?>
          <p class="small-note">No documents uploaded for this client yet.</p>
        <?php else: ?>
          <div class="mini-tasks">
          <?php foreach ($documents as $document): ?>
            <div class="mini-task">
              <strong><?= h((string)($document['title'] ?? $document['original_name'] ?? $document['original_filename'] ?? 'Document')) ?></strong>
              <small><?= h(implode(' · ', array_filter([(string)($document['category'] ?? ''), (string)($document['status'] ?? ''), (string)($document['uploaded_at'] ?? '')]))) ?></small>
            </div>
          <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <!-- WORKFLOW HISTORY -->
      <section class="employee-directory client-section" id="history" aria-labelledby="historyTitle">
        <div class="section-title"><div><h3 id="historyTitle">Workflow History</h3><p>Status changes across this client's requests and tasks, newest first.</p></div></div>
        <div class="task-timeline">
          <?php if (!$history): ?>
            <p>No workflow history recorded yet for this client.</p>
          <?php else: foreach ($history as $event): ?>
            <p>
              <strong><?= h($event['entity_type'] === 'task' ? 'Task: ' . (string)($event['task_title'] ?? ('#' . $event['entity_id'])) : 'Service request #' . (int)$event['entity_id']) ?></strong>
              — <?= h($statusLabel($event['previous_status'])) ?> → <?= h($statusLabel((string)$event['new_status'])) ?>
              <?php if (!empty($event['progress_note'])): ?> · <?= h((string)$event['progress_note']) ?><?php endif; ?>
              <small><?= h((string)$event['changed_at'] . ' — ' . (string)($event['changed_by_name'] ?? 'System')) ?></small>
            </p>
          <?php endforeach; endif; ?>
        </div>
      </section>

<script>
(function () {
  // Confirm before changing the client's status.
  document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (e) { if (!window.confirm(form.dataset.confirm)) e.preventDefault(); });
  });

  var form = document.getElementById('clientEditForm');
  if (!form) return;
  var notice = document.getElementById('clientEditNotice');

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
  function isBusiness() { return form.elements['client_type'].value === 'business'; }
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
  var emailStar = document.getElementById('editEmailStar');
  form.elements['preferred_contact_method'].addEventListener('change', function () {
    emailStar.hidden = method() !== 'email';
    apply(form.elements['preferred_contact_method']);
    ['email', 'phone_number'].forEach(function (name) {
      var f = form.elements[name];
      if (f.dataset.touched === '1' || f.classList.contains('is-invalid')) apply(f);
    });
  });

  /* ---------- Business vs individual ---------- */
  var businessField = document.getElementById('editBusinessField');
  function syncType() {
    var business = isBusiness();
    businessField.hidden = !business;
    var positionField = document.getElementById('editPositionField');
    var positionInput = form.elements['contact_position'];
    positionField.hidden = !business;
    positionInput.disabled = !business;
    if (!business) apply(positionInput);
    document.getElementById('editFirstLabel').textContent = business ? 'Contact first name' : 'First name';
    document.getElementById('editMiddleLabel').textContent = business ? 'Contact middle name' : 'Middle name';
    document.getElementById('editLastLabel').textContent = business ? 'Contact last name' : 'Last name';
    if (!business) apply(form.elements['business_name']);
  }
  form.elements['client_type'].addEventListener('change', syncType);
  syncType();

  /* ---------- Stop the form and warn before saving ---------- */
  form.addEventListener('submit', function (e) {
    var firstBad = null;
    Object.keys(rules).forEach(function (name) {
      var input = form.elements[name];
      if (input && apply(input) && !firstBad) firstBad = input;
    });
    notice.hidden = !firstBad;
    if (firstBad) { e.preventDefault(); firstBad.focus(); }
  });
})();
</script>
<?php nexShellEnd(); ?>
