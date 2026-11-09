<?php
declare(strict_types=1);
require_once __DIR__ . '/authorization.php';
require_once __DIR__ . '/nexsus_shell.php';

// Only a fully authenticated employee may enter this page.
$employee = require_logged_in('employee');
$employeeId = (int)$employee['user_id'];
$today = date('Y-m-d');
$csrf = csrf_token();

// "Assigned" work is derived from the shared `tasks` table: the owner assigns tasks
// to this employee, and those tasks link to service requests and clients.
const CLIENT_NAME_SQL = "CASE WHEN c.client_type='business' THEN c.business_name
                              ELSE CONCAT_WS(' ', c.first_name, c.last_name) END";

function employee_rows(mysqli $conn, string $sql, int $employeeId): array {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $employeeId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// Task counts for this employee only.
$counts = employee_rows($conn,
    "SELECT COALESCE(SUM(status IN ('pending','in_progress')), 0) AS open_count,
            COALESCE(SUM(status <> 'completed' AND due_date < CURDATE()), 0) AS overdue_count
     FROM tasks WHERE assigned_to = ?", $employeeId)[0] ?? ['open_count' => 0, 'overdue_count' => 0];

// Active service requests and clients this employee has tasks on.
$reach = employee_rows($conn,
    "SELECT COUNT(DISTINCT sr.service_request_id) AS request_count,
            COUNT(DISTINCT sr.client_id)          AS client_count
     FROM tasks t
     JOIN service_requests sr ON sr.service_request_id = t.service_request_id
     WHERE t.assigned_to = ? AND sr.request_status IN ('pending','in_progress')", $employeeId)[0]
    ?? ['request_count' => 0, 'client_count' => 0];

// My next open tasks.
$tasks = employee_rows($conn,
    "SELECT t.task_id, t.task_title, t.status, t.priority, t.due_date,
            s.service_name, " . CLIENT_NAME_SQL . " AS client_name
     FROM tasks t
     JOIN service_requests sr ON sr.service_request_id = t.service_request_id
     JOIN services s          ON s.service_id = sr.service_id
     JOIN clients c           ON c.client_id = sr.client_id
     WHERE t.assigned_to = ? AND t.status IN ('pending','in_progress')
     ORDER BY t.due_date ASC, t.task_id DESC
     LIMIT 5", $employeeId);

// Service requests I am working on.
$requests = employee_rows($conn,
    "SELECT sr.service_request_id, sr.due_date, sr.request_status, s.service_name,
            " . CLIENT_NAME_SQL . " AS client_name,
            SUM(t.status <> 'completed') AS my_open_tasks
     FROM tasks t
     JOIN service_requests sr ON sr.service_request_id = t.service_request_id
     JOIN services s          ON s.service_id = sr.service_id
     JOIN clients c           ON c.client_id = sr.client_id
     WHERE t.assigned_to = ? AND sr.request_status IN ('pending','in_progress')
     GROUP BY sr.service_request_id, sr.due_date, sr.request_status, s.service_name,
              c.client_id, c.client_type, c.business_name, c.first_name, c.last_name
     ORDER BY sr.due_date ASC, sr.service_request_id DESC
     LIMIT 5", $employeeId);

// Clients I am working for.
$clients = employee_rows($conn,
    "SELECT c.client_id, c.client_type, " . CLIENT_NAME_SQL . " AS client_name,
            COUNT(DISTINCT sr.service_request_id) AS request_total,
            SUM(t.status <> 'completed') AS my_open_tasks
     FROM tasks t
     JOIN service_requests sr ON sr.service_request_id = t.service_request_id
     JOIN clients c           ON c.client_id = sr.client_id
     WHERE t.assigned_to = ? AND sr.request_status IN ('pending','in_progress')
     GROUP BY c.client_id, c.client_type, c.business_name, c.first_name, c.last_name
     ORDER BY client_name ASC
     LIMIT 6", $employeeId);

$firstName = explode(' ', trim((string)$employee['full_name']))[0] ?: 'Employee';

// The shared shell prints the sidebar, header, notifications and opens <main>.
nexShellStart($employee, 'Employee Dashboard', 'employeeDashboard.php', $csrf, ['mainClass' => 'employee-page']);
?>
      <div class="employee-heading">
        <div>
          <p class="eyebrow">MY WORK / OVERVIEW</p>
          <h2>Welcome back, <?= h($firstName) ?>!</h2>
          <p>Your assigned tasks, deadlines and clients at a glance.</p>
        </div>
        <a class="secondary-button" href="mySettings.php"><i class="bi bi-person-gear" aria-hidden="true"></i> My Settings</a>
      </div>

      <section class="employee-stats employee-stats-4" aria-label="My work summary">
        <article class="stat-card"><span class="stat-icon"><i class="bi bi-check2-square" aria-hidden="true"></i></span><span>Open Tasks</span><strong><?= (int)$counts['open_count'] ?></strong></article>
        <article class="stat-card"><span class="stat-icon gold"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i></span><span>Overdue</span><strong><?= (int)$counts['overdue_count'] ?></strong></article>
        <article class="stat-card"><span class="stat-icon"><i class="bi bi-kanban" aria-hidden="true"></i></span><span>Active Requests</span><strong><?= (int)$reach['request_count'] ?></strong></article>
        <article class="stat-card"><span class="stat-icon green"><i class="bi bi-person-vcard" aria-hidden="true"></i></span><span>My Clients</span><strong><?= (int)$reach['client_count'] ?></strong></article>
      </section>

      <div class="employee-dash-grid">
        <!-- My Tasks -->
        <section class="employee-directory" aria-labelledby="tasksTitle">
          <div class="section-title">
            <div><h3 id="tasksTitle">My Tasks</h3><p>Your next open tasks, earliest deadline first.</p></div>
            <a class="text-button" href="myTasks.php">View all</a>
          </div>
          <?php if (!$tasks): ?>
            <div class="empty-state"><i class="bi bi-inbox" aria-hidden="true"></i><h4>No open tasks</h4><p>When the owner assigns you a task, it will appear here.</p></div>
          <?php else: ?>
            <div class="task-list task-list-single">
            <?php foreach ($tasks as $task): ?>
              <?php $overdue = $task['due_date'] < $today; ?>
              <article class="task-item">
                <div class="task-item-head">
                  <div><h4><?= h((string)$task['task_title']) ?></h4><small><?= h($task['client_name'] . ' · ' . $task['service_name']) ?></small></div>
                  <span class="task-badge <?= h((string)$task['status']) ?>"><?= h(ucwords(str_replace('_', ' ', (string)$task['status']))) ?></span>
                </div>
                <div class="task-item-meta">
                  <span><i class="bi bi-calendar" aria-hidden="true"></i> Due <?= h((string)$task['due_date']) ?></span>
                  <span class="task-badge priority-<?= h((string)$task['priority']) ?>"><?= h(ucfirst((string)$task['priority'])) ?> priority</span>
                  <?php if ($overdue): ?><span class="task-badge overdue">Overdue</span><?php endif; ?>
                  <a class="text-button" href="myTasks.php?view=<?= (int)$task['task_id'] ?>#myTaskDetails">View task</a>
                </div>
              </article>
            <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>

        <div class="employee-dash-stack">
          <!-- Service Requests -->
          <section class="employee-directory" aria-labelledby="requestsTitle">
            <div class="section-title">
              <div><h3 id="requestsTitle">Service Requests</h3><p>Requests you have tasks on.</p></div>
              <a class="text-button" href="myServiceRequests.php">View all</a>
            </div>
            <?php if (!$requests): ?>
              <p class="small-note">No active service requests yet.</p>
            <?php else: ?>
              <div class="mini-tasks">
              <?php foreach ($requests as $request): ?>
                <div class="mini-task">
                  <strong><a href="myServiceRequests.php?view=<?= (int)$request['service_request_id'] ?>#requestDetails">#<?= (int)$request['service_request_id'] ?> · <?= h((string)$request['service_name']) ?></a></strong>
                  <small><?= h((string)$request['client_name']) ?> · Due <?= h((string)$request['due_date']) ?> · <?= (int)$request['my_open_tasks'] ?> of my tasks open</small>
                  <span class="task-badge <?= h((string)$request['request_status']) ?>"><?= h(ucwords(str_replace('_', ' ', (string)$request['request_status']))) ?></span>
                </div>
              <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </section>

          <!-- My Clients -->
          <section class="employee-directory" aria-labelledby="clientsTitle">
            <div class="section-title">
              <div><h3 id="clientsTitle">My Clients</h3><p>Clients you are currently working for.</p></div>
              <a class="text-button" href="myClients.php">View all</a>
            </div>
            <?php if (!$clients): ?>
              <p class="small-note">No assigned clients yet.</p>
            <?php else: ?>
              <div class="mini-tasks">
              <?php foreach ($clients as $client): ?>
                <div class="mini-task">
                  <strong><?= h((string)$client['client_name']) ?></strong>
                  <small><?= $client['client_type'] === 'business' ? 'Business' : 'Individual' ?> · <?= (int)$client['request_total'] ?> active request(s) · <?= (int)$client['my_open_tasks'] ?> of my tasks open</small>
                </div>
              <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </section>

          <!-- Documents -->
          <section class="employee-directory" aria-labelledby="docsTitle">
            <div class="section-title">
              <div><h3 id="docsTitle">My Documents</h3><p>Upload and find documents for your clients.</p></div>
            </div>
            <a class="secondary-button" href="myDocuments.php" style="margin-top:14px"><i class="bi bi-folder2-open" aria-hidden="true"></i> Open My Documents</a>
          </section>
        </div>
      </div>
    
<?php nexShellEnd(); ?>
