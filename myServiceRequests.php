<?php
declare(strict_types=1);
require_once __DIR__ . '/authorization.php';
require_once __DIR__ . '/client_lib.php';
require_once __DIR__ . '/nexsus_shell.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$employee = require_logged_in('employee');
$me = (int)$employee['user_id'];
$csrf = csrf_token();
$today = date('Y-m-d');

const CLIENT_NAME_SQL = "CASE WHEN c.client_type='business' THEN c.business_name ELSE CONCAT_WS(' ', c.first_name, c.last_name) END";
// A request is visible to an employee ONLY when that employee has an assigned task on it.
// Being named the service-request lead alone does not grant visibility to another employee's tasks or clients.
const MINE_SQL = "EXISTS (SELECT 1 FROM tasks mt WHERE mt.service_request_id = sr.service_request_id AND mt.assigned_to = ?)";

$statusLabel = static fn(?string $s): string => $s === null || $s === '' ? 'New' : ucwords(str_replace('_', ' ', $s));

// ---------- Filters ----------
$filter = (string)($_GET['status'] ?? 'open');
if (!in_array($filter, ['open', 'pending', 'in_progress', 'overdue', 'completed', 'all'], true)) $filter = 'open';
$query = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80);

$where = [MINE_SQL];
$params = [$me];
if ($filter === 'open') $where[] = "sr.request_status IN ('pending','in_progress')";
elseif ($filter === 'overdue') $where[] = "sr.request_status IN ('pending','in_progress') AND sr.due_date < CURDATE()";
elseif ($filter !== 'all') { $where[] = 'sr.request_status = ?'; $params[] = $filter; }
if ($query !== '') {
    $like = '%' . addcslashes($query, '%_\\') . '%';
    $where[] = '(s.service_name LIKE ? OR ' . CLIENT_NAME_SQL . ' LIKE ?)';
    array_push($params, $like, $like);
}

$counts = client_one($conn,
    "SELECT COUNT(*) AS total_count,
            COALESCE(SUM(sr.request_status IN ('pending','in_progress')), 0) AS open_count,
            COALESCE(SUM(sr.request_status IN ('pending','in_progress') AND sr.due_date < CURDATE()), 0) AS overdue_count,
            COALESCE(SUM(sr.request_status = 'completed'), 0) AS completed_count
     FROM service_requests sr WHERE " . MINE_SQL, [$me])
    ?? ['total_count' => 0, 'open_count' => 0, 'overdue_count' => 0, 'completed_count' => 0];

$requests = client_rows($conn,
    "SELECT sr.service_request_id, sr.due_date, sr.request_status, sr.priority, sr.assigned_to,
            s.service_name, " . CLIENT_NAME_SQL . " AS client_name,
            (SELECT COUNT(*) FROM tasks ot WHERE ot.service_request_id = sr.service_request_id
                AND ot.assigned_to = ? AND ot.status <> 'completed') AS my_open_tasks
     FROM service_requests sr
     JOIN services s ON s.service_id = sr.service_id
     JOIN clients c  ON c.client_id = sr.client_id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY (sr.request_status IN ('completed','cancelled')), sr.due_date ASC, sr.service_request_id DESC
     LIMIT 200", array_merge([$me], $params));

// ---------- Detail (only for a request that is mine) ----------
$viewId = filter_var($_GET['view'] ?? null, FILTER_VALIDATE_INT);
$selected = null; $tasks = []; $history = [];
if ($viewId) {
    $selected = client_one($conn,
        "SELECT sr.*, s.service_name, " . CLIENT_NAME_SQL . " AS client_name, c.client_id AS the_client_id,
                c.email AS client_email, c.phone_number AS client_phone, lu.full_name AS lead_name
         FROM service_requests sr
         JOIN services s ON s.service_id = sr.service_id
         JOIN clients c  ON c.client_id = sr.client_id
         LEFT JOIN users lu ON lu.user_id = sr.assigned_to
         WHERE sr.service_request_id = ? AND " . MINE_SQL, [(int)$viewId, $me]);
    if ($selected) {
       
$tasks = client_rows($conn,
    "SELECT t.task_id, t.task_title, t.status, t.priority,
            t.due_date, t.assigned_to,
            u.full_name AS employee_name
     FROM tasks t
     LEFT JOIN users u ON u.user_id = t.assigned_to
     WHERE t.service_request_id = ?
       AND t.assigned_to = ?
     ORDER BY t.due_date",
    [(int)$viewId, $me]
);

        $history = client_rows($conn,
            "SELECT h.entity_type, h.entity_id, h.previous_status, h.new_status, h.progress_note, h.changed_at,
                    u.full_name AS actor, t.task_title
             FROM workflow_history h
             LEFT JOIN users u ON u.user_id = h.changed_by
             LEFT JOIN tasks t ON h.entity_type = 'task' AND t.task_id = h.entity_id
             WHERE (h.entity_type = 'service_request' AND h.entity_id = ?)
                OR (h.entity_type = 'task' AND h.entity_id IN
                   (SELECT task_id FROM tasks WHERE service_request_id = ? AND assigned_to = ?))
             ORDER BY h.changed_at DESC LIMIT 40", [(int)$viewId, (int)$viewId, $me]);
    }
}

$tabUrl = static fn(string $status): string => 'myServiceRequests.php?' . http_build_query(array_filter(['status' => $status, 'q' => $GLOBALS['query']], static fn($v) => $v !== ''));
nexShellStart($employee,'My Service Requests','myServiceRequests.php',$csrf,['mainClass'=>'employee-page']);
?>
      <div class="employee-heading">
        <div><p class="eyebrow">MY WORK / SERVICE REQUESTS</p><h2>My Service Requests</h2><p>Service requests connected to tasks assigned to you.</p></div>
      </div>

      <section class="employee-stats employee-stats-4" aria-label="My request totals">
        <article class="stat-card"><span class="stat-icon"><i class="bi bi-kanban" aria-hidden="true"></i></span><span>Total Requests</span><strong><?= (int)$counts['total_count'] ?></strong></article>
        <article class="stat-card"><span class="stat-icon"><i class="bi bi-hourglass-split" aria-hidden="true"></i></span><span>Open</span><strong><?= (int)$counts['open_count'] ?></strong></article>
        <article class="stat-card"><span class="stat-icon gold"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i></span><span>Overdue</span><strong><?= (int)$counts['overdue_count'] ?></strong></article>
        <article class="stat-card"><span class="stat-icon green"><i class="bi bi-check2-circle" aria-hidden="true"></i></span><span>Completed</span><strong><?= (int)$counts['completed_count'] ?></strong></article>
      </section>

      <section class="employee-directory client-section" id="requestDirectory" aria-labelledby="requestsTitle">
        <div class="section-title"><div><h3 id="requestsTitle">Request List</h3><p>Open a request to see its tasks and history.</p></div><span class="employee-result-count"><?= count($requests) ?> shown</span></div>

        <form method="get" action="myServiceRequests.php#requestDirectory" class="employee-filters">
          <input type="hidden" name="status" value="<?= h($filter) ?>">
          <label class="directory-search"><i class="bi bi-search" aria-hidden="true"></i><input type="search" name="q" value="<?= h($query) ?>" maxlength="80" placeholder="Search client or service" aria-label="Search requests"></label>
          <button class="secondary-button" type="submit">Search</button>
          <?php if ($query !== ''): ?><a class="secondary-button" href="<?= h($tabUrl($filter)) ?>#requestDirectory" style="">Clear</a><?php endif; ?>
        </form>
        <nav class="employee-status-tabs" aria-label="Filter requests">
          <?php foreach (['open' => 'Open', 'pending' => 'Pending', 'in_progress' => 'In Progress', 'overdue' => 'Overdue', 'completed' => 'Completed', 'all' => 'All'] as $key => $label): ?>
            <a class="employee-status-tab <?= $filter === $key ? 'is-current' : '' ?>" href="<?= h($tabUrl($key)) ?>#requestDirectory" <?= $filter === $key ? 'aria-current="page"' : '' ?>><?= h($label) ?></a>
          <?php endforeach; ?>
        </nav>

        <div class="client-table-wrap"><table class="client-table">
          <thead><tr><th scope="col">Request</th><th scope="col">Client</th><th scope="col">Deadline</th><th scope="col">Priority</th><th scope="col">Status</th><th scope="col">My open tasks</th><th scope="col">Action</th></tr></thead>
          <tbody>
          <?php if (!$requests): ?>
            <tr><td colspan="7"><div class="empty-state"><i class="bi bi-inbox" aria-hidden="true"></i><h4>No requests found</h4><p>Try another filter, or wait until the owner assigns you a task.</p></div></td></tr>
          <?php else: foreach ($requests as $row): ?>
            <?php $late = in_array($row['request_status'], ['pending', 'in_progress'], true) && $row['due_date'] < $today; ?>
            <tr>
              <td><strong>#<?= (int)$row['service_request_id'] ?></strong><small><?= h((string)$row['service_name']) ?><?= (int)$row['assigned_to'] === $me ? ' · You lead' : '' ?></small></td>
              <td><?= h((string)$row['client_name']) ?></td>
              <td><?= h((string)$row['due_date']) ?><?php if ($late): ?> <span class="task-badge overdue">Overdue</span><?php endif; ?></td>
              <td><span class="task-badge priority-<?= h((string)$row['priority']) ?>"><?= h(ucfirst((string)$row['priority'])) ?></span></td>
              <td><span class="task-badge <?= h((string)$row['request_status']) ?>"><?= h($statusLabel((string)$row['request_status'])) ?></span></td>
              <td><?= (int)$row['my_open_tasks'] ?></td>
              <td><a class="client-view" href="myServiceRequests.php?view=<?= (int)$row['service_request_id'] ?>#requestDetails"><i class="bi bi-eye" aria-hidden="true"></i> View</a></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table></div>
      </section>

      <?php if ($viewId && !$selected): ?>
        <div class="task-alert error" role="alert">That request is not available to you.</div>
      <?php elseif ($selected): ?>
      <?php $isLead = (int)$selected['assigned_to'] === $me; $closed = in_array($selected['request_status'], ['completed', 'cancelled'], true); ?>
      <section class="employee-directory client-section" id="requestDetails" aria-labelledby="detailTitle">
        <div class="section-title">
          <div><h3 id="detailTitle">Request #<?= (int)$selected['service_request_id'] ?> — <?= h((string)$selected['service_name']) ?></h3><p><?= h((string)$selected['client_name']) ?></p></div>
          <a class="secondary-button" href="myServiceRequests.php#requestDirectory">Close</a>
        </div>
        <div class="task-facts">
          <div><small>Status</small><strong><?= h($statusLabel((string)$selected['request_status'])) ?></strong></div>
          <div><small>Deadline</small><strong><?= h((string)$selected['due_date']) ?></strong></div>
          <div><small>Priority</small><strong><?= h(ucfirst((string)$selected['priority'])) ?></strong></div>
          <div><small>Responsible employee</small><strong><?= h((string)($selected['lead_name'] ?? 'Not assigned')) ?><?= $isLead ? ' (you)' : '' ?></strong></div>
        </div>
        <dl class="profile-facts">
          <div><dt>Client contact email</dt><dd><?= $selected['client_email'] ? '<a href="mailto:' . h((string)$selected['client_email']) . '">' . h((string)$selected['client_email']) . '</a>' : '—' ?></dd></div>
          <div><dt>Client contact phone</dt><dd><?= h((string)($selected['client_phone'] ?: '—')) ?></dd></div>
        </dl>
        <div class="client-heading-actions" style="margin:16px 0">
          <a class="secondary-button" href="myDocuments.php?client_id=<?= (int)$selected['the_client_id'] ?>"><i class="bi bi-folder2-open" aria-hidden="true"></i> Client documents</a>
        </div>

        <h4 class="task-subheading">Tasks on this request</h4>
        <?php if (!$tasks): ?><p class="small-note">No tasks have been created for this request yet.</p>
        <?php else: ?>
          <div class="client-table-wrap"><table class="client-table">
            <thead><tr><th scope="col">Task</th><th scope="col">Assigned to</th><th scope="col">Deadline</th><th scope="col">Status</th></tr></thead>
            <tbody>
            <?php foreach ($tasks as $task): $mine = (int)$task['assigned_to'] === $me; ?>
              <tr>
                <td><strong><?php if ($mine): ?><a class="client-view" href="myTasks.php?view=<?= (int)$task['task_id'] ?>#myTaskDetails"><?= h((string)$task['task_title']) ?></a><?php else: ?><?= h((string)$task['task_title']) ?><?php endif; ?></strong></td>
                <td><?= $mine ? 'You' : h((string)($task['employee_name'] ?? 'Unassigned')) ?></td>
                <td><?= h((string)$task['due_date']) ?></td>
                <td><span class="task-badge <?= h((string)$task['status']) ?>"><?= h($statusLabel((string)$task['status'])) ?></span></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table></div>
        <?php endif; ?>

        <h4 class="task-subheading" style="margin-top:22px">Workflow history</h4>
        <div class="task-timeline">
          <?php if (!$history): ?><p>No workflow history recorded yet.</p>
          <?php else: foreach ($history as $event): ?>
            <p><strong><?= h($event['entity_type'] === 'task' ? 'Task: ' . (string)($event['task_title'] ?? ('#' . $event['entity_id'])) : 'Request status') ?></strong>
              — <?= h($statusLabel($event['previous_status'])) ?> → <?= h($statusLabel((string)$event['new_status'])) ?><?php if (!empty($event['progress_note'])): ?> · <?= h((string)$event['progress_note']) ?><?php endif; ?>
              <small><?= h((string)$event['changed_at'] . ' — ' . (string)($event['actor'] ?? 'System')) ?></small></p>
          <?php endforeach; endif; ?>
        </div>
      </section>
      <?php endif; ?>
<?php nexShellEnd(); ?>