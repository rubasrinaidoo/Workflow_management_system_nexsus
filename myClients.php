<?php
declare(strict_types=1);
require_once __DIR__ . '/authorization.php';
require_once __DIR__ . '/client_lib.php';
require_once __DIR__ . '/nexsus_shell.php'; // 

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$employee = require_logged_in('employee');
$me = (int)$employee['user_id'];
$csrf = csrf_token();

/*
 * HOW A CLIENT BECOMES "MINE" (set by the owner, nothing to configure here):
 *   1. The owner assigns me a SERVICE REQUEST  -> service_requests.assigned_to = me
 *   2. The owner assigns me a TASK on a request -> tasks.assigned_to = me
 * Either one links the request's client to me. Remove the assignment and the client disappears.
 * Employees never see clients they have no request or task for.
 */
const MINE_SQL = "(sr.assigned_to = ? OR EXISTS (SELECT 1 FROM tasks mt WHERE mt.service_request_id = sr.service_request_id AND mt.assigned_to = ?))";

$statusLabel = static fn(?string $s): string => $s === null || $s === '' ? 'New' : ucwords(str_replace('_', ' ', $s));

// ---------- Filters ----------
$show = ($_GET['show'] ?? 'active') === 'all' ? 'all' : 'active';
$query = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80);

$where = [MINE_SQL];
$params = [$me, $me];
if ($query !== '') {
    $like = '%' . addcslashes($query, '%_\\') . '%';
    $where[] = "(c.business_name LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ?
                 OR CONCAT_WS(' ', c.first_name, c.middle_name, c.last_name) LIKE ? OR c.email LIKE ?)";
    array_push($params, $like, $like, $like, $like, $like);
}

// Placeholders are filled in the order they appear: lead_requests (?), my_open_tasks (?), then WHERE.
$clients = client_rows($conn,
    "SELECT c.client_id, c.client_type, c.business_name, c.first_name, c.middle_name, c.last_name, c.email, c.phone_number, c.preferred_contact_method, c.status,
            COUNT(DISTINCT CASE WHEN sr.request_status IN ('pending','in_progress') THEN sr.service_request_id END) AS active_requests,
            COUNT(DISTINCT CASE WHEN sr.assigned_to = ? THEN sr.service_request_id END) AS lead_requests,
            MIN(CASE WHEN sr.request_status IN ('pending','in_progress') THEN sr.due_date END) AS next_due,
            (SELECT COUNT(*) FROM tasks ot JOIN service_requests osr ON osr.service_request_id = ot.service_request_id
              WHERE osr.client_id = c.client_id AND ot.assigned_to = ? AND ot.status <> 'completed') AS my_open_tasks
     FROM service_requests sr
     JOIN clients c ON c.client_id = sr.client_id
     WHERE " . implode(' AND ', $where) . "
     GROUP BY c.client_id, c.client_type, c.business_name, c.first_name, c.middle_name, c.last_name, c.email, c.phone_number, c.preferred_contact_method, c.status
     " . ($show === 'active' ? 'HAVING active_requests > 0' : '') . "
     ORDER BY COALESCE(NULLIF(c.business_name, ''), CONCAT_WS(' ', c.first_name, c.last_name)) ASC, c.client_id DESC
     LIMIT 200", array_merge([$me, $me], $params));

$totalClients = (int)(client_one($conn,
    "SELECT COUNT(DISTINCT sr.client_id) AS n FROM service_requests sr WHERE " . MINE_SQL, [$me, $me])['n'] ?? 0);
$activeClients = (int)(client_one($conn,
    "SELECT COUNT(DISTINCT sr.client_id) AS n FROM service_requests sr
     WHERE sr.request_status IN ('pending','in_progress') AND " . MINE_SQL, [$me, $me])['n'] ?? 0);

// ---------- Detail (only for a client I work for) ----------
$viewId = filter_var($_GET['view'] ?? null, FILTER_VALIDATE_INT);
$selected = null; $myRequests = []; $myTasks = [];
if ($viewId) {
    $selected = client_one($conn,
        "SELECT c.* FROM clients c
         WHERE c.client_id = ? AND EXISTS (SELECT 1 FROM service_requests sr WHERE sr.client_id = c.client_id AND " . MINE_SQL . ")",
        [(int)$viewId, $me, $me]);
    if ($selected) {
        $myRequests = client_rows($conn,
            "SELECT sr.service_request_id, sr.due_date, sr.request_status, sr.priority, sr.assigned_to, s.service_name
             FROM service_requests sr JOIN services s ON s.service_id = sr.service_id
             WHERE sr.client_id = ? AND " . MINE_SQL . "
             ORDER BY (sr.request_status IN ('completed','cancelled')), sr.due_date, sr.service_request_id DESC",
            [(int)$viewId, $me, $me]);
        $myTasks = client_rows($conn,
            "SELECT t.task_id, t.task_title, t.status, t.priority, t.due_date, t.service_request_id
             FROM tasks t JOIN service_requests sr ON sr.service_request_id = t.service_request_id
             WHERE sr.client_id = ? AND t.assigned_to = ?
             ORDER BY (t.status = 'completed'), t.due_date, t.task_id DESC", [(int)$viewId, $me]);
    }
}

$showUrl = static fn(string $mode): string => 'myClients.php?' . http_build_query(
    array_filter(['show' => $mode === 'all' ? 'all' : '', 'q' => $query], static fn($v) => $v !== ''));

// Sidebar, top bar, notification bell, theme switch and footer come from the shared NEXSUS shell.
nexShellStart($employee, 'My Clients', 'myClients.php', $csrf, ['mainClass' => 'employee-page']);
?>
      <div class="employee-heading">
        <div><p class="eyebrow">CLIENTS</p><h2>My Clients</h2><p>Clients linked to you through a service request or task the owner assigned. Only the owner can add or edit client records.</p></div>
      </div>

      <section class="employee-stats" aria-label="My client totals">
        <article class="stat-card"><span class="stat-icon"><i class="bi bi-person-vcard" aria-hidden="true"></i></span><span>All my clients</span><strong><?= $totalClients ?></strong></article>
        <article class="stat-card"><span class="stat-icon green"><i class="bi bi-person-check" aria-hidden="true"></i></span><span>With active work</span><strong><?= $activeClients ?></strong></article>
        <article class="stat-card"><span class="stat-icon gold"><i class="bi bi-check2-square" aria-hidden="true"></i></span><span>Shown below</span><strong><?= count($clients) ?></strong></article>
      </section>

      <section class="employee-directory client-section" id="clientDirectory" aria-labelledby="directoryTitle">
        <div class="section-title"><div><h3 id="directoryTitle">Client List</h3><p>Search by business name, contact person or email.</p></div></div>
        <form method="get" action="myClients.php#clientDirectory" class="employee-filters">
          <?php if ($show === 'all'): ?><input type="hidden" name="show" value="all"><?php endif; ?>
          <label class="directory-search"><i class="bi bi-search" aria-hidden="true"></i><input type="search" name="q" value="<?= h($query) ?>" maxlength="80" placeholder="Search client name or email" aria-label="Search clients"></label>
          <button class="secondary-button" type="submit">Search</button>
          <?php if ($query !== ''): ?><a class="secondary-button" href="<?= h($showUrl($show)) ?>">Clear</a><?php endif; ?>
        </form>
        <nav class="employee-status-tabs" aria-label="Filter clients">
          <a class="employee-status-tab <?= $show === 'active' ? 'is-current' : '' ?>" href="<?= h($showUrl('active')) ?>#clientDirectory">With active work</a>
          <a class="employee-status-tab <?= $show === 'all' ? 'is-current' : '' ?>" href="<?= h($showUrl('all')) ?>#clientDirectory">All my clients</a>
        </nav>

        <div class="client-table-wrap"><table class="client-table">
          <thead><tr><th scope="col">Client</th><th scope="col">Contact person</th><th scope="col">Email</th><th scope="col">Phone</th><th scope="col">Active requests</th><th scope="col">My open tasks</th><th scope="col">Next deadline</th><th scope="col">Action</th></tr></thead>
          <tbody>
          <?php if (!$clients): ?>
            <tr><td colspan="8"><div class="empty-state"><i class="bi bi-person-vcard" aria-hidden="true"></i><h4>No clients found</h4><p>Clients appear here when the owner assigns you a service request or a task for them.</p></div></td></tr>
          <?php else: foreach ($clients as $client): ?>
            <tr>
              <td><strong><?= h(client_display_name($client)) ?></strong><small><?= $client['client_type'] === 'business' ? 'Business' : 'Individual' ?><?= $client['status'] === 'inactive' ? ' · Inactive' : '' ?> · <?= (int)$client['lead_requests'] > 0 ? 'You lead ' . (int)$client['lead_requests'] . ' request(s)' : 'Via your tasks' ?></small></td>
              <td><?= h(client_contact_person($client)) ?></td>
              <td><?= $client['email'] ? '<a href="mailto:' . h((string)$client['email']) . '">' . h((string)$client['email']) . '</a>' : '—' ?></td>
              <td><?= h(client_format_phone($client['phone_number'])) ?><?php $prefers = client_contact_methods()[$client['preferred_contact_method'] ?? ''] ?? ''; if ($prefers !== ''): ?><small>Prefers: <?= h($prefers) ?></small><?php endif; ?></td>
              <td><?= (int)$client['active_requests'] ?></td>
              <td><?= (int)$client['my_open_tasks'] ?></td>
              <td><?= h((string)($client['next_due'] ?? '—')) ?></td>
              <td><a class="client-view" href="myClients.php?view=<?= (int)$client['client_id'] ?>#clientDetails"><i class="bi bi-eye" aria-hidden="true"></i> View</a></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table></div>
      </section>

      <?php if ($viewId && !$selected): ?>
        <div class="task-alert error" role="alert">That client is not available to you.</div>
      <?php elseif ($selected): ?>
      <section class="employee-directory client-section" id="clientDetails" aria-labelledby="detailTitle">
        <div class="section-title">
          <div><h3 id="detailTitle"><?= h(client_display_name($selected)) ?></h3><p><?= $selected['client_type'] === 'business' ? 'Business client' : 'Individual client' ?></p></div>
          <a class="secondary-button" href="myClients.php#clientDirectory">Close</a>
        </div>
        <dl class="profile-facts">
          <?php if ($selected['client_type'] === 'business'): ?><div><dt>Business name</dt><dd><?= h((string)$selected['business_name']) ?></dd></div><?php endif; ?>
          <div><dt>Contact person</dt><dd><?= h(client_contact_person($selected)) ?></dd></div>
          <div><dt>Email</dt><dd><?= $selected['email'] ? '<a href="mailto:' . h((string)$selected['email']) . '">' . h((string)$selected['email']) . '</a>' : '—' ?></dd></div>
          <div><dt>Phone</dt><dd><?= h(client_format_phone($selected['phone_number'])) ?></dd></div>
          <div><dt>Preferred contact method</dt><dd><?= h(client_contact_methods()[(string)($selected['preferred_contact_method'] ?? '')] ?? '—') ?></dd></div>
          <div><dt>Status</dt><dd><?= h(ucfirst((string)$selected['status'])) ?></dd></div>
        </dl>
        <div class="client-heading-actions" style="margin:16px 0">
          <a class="secondary-button" href="myDocuments.php?client_id=<?= (int)$selected['client_id'] ?>"><i class="bi bi-folder2-open" aria-hidden="true"></i> Client documents</a>
        </div>

        <h4 class="task-subheading">My requests for this client</h4>
        <?php if (!$myRequests): ?><p class="small-note">None.</p>
        <?php else: ?><div class="mini-tasks">
          <?php foreach ($myRequests as $request): ?>
            <div class="mini-task">
              <strong><a class="client-view" href="myServiceRequests.php?view=<?= (int)$request['service_request_id'] ?>#requestDetails">#<?= (int)$request['service_request_id'] ?> · <?= h((string)$request['service_name']) ?></a></strong>
              <small>Due <?= h((string)$request['due_date']) ?><?= (int)$request['assigned_to'] === $me ? ' · You lead this request' : ' · You have a task on it' ?></small>
              <span class="task-badge <?= h((string)$request['request_status']) ?>"><?= h($statusLabel((string)$request['request_status'])) ?></span>
            </div>
          <?php endforeach; ?></div><?php endif; ?>

        <h4 class="task-subheading" style="margin-top:22px">My tasks for this client</h4>
        <?php if (!$myTasks): ?><p class="small-note">You have no tasks for this client.</p>
        <?php else: ?><div class="mini-tasks">
          <?php foreach ($myTasks as $task): ?>
            <div class="mini-task">
              <strong><a class="client-view" href="myTasks.php?view=<?= (int)$task['task_id'] ?>#myTaskDetails"><?= h((string)$task['task_title']) ?></a></strong>
              <small>Request #<?= (int)$task['service_request_id'] ?> · Due <?= h((string)$task['due_date']) ?> · <?= h(ucfirst((string)$task['priority'])) ?> priority</small>
              <span class="task-badge <?= h((string)$task['status']) ?>"><?= h($statusLabel((string)$task['status'])) ?></span>
            </div>
          <?php endforeach; ?></div><?php endif; ?>
      </section>
      <?php endif; ?>
<?php nexShellEnd(); ?>