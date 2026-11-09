<?php
declare(strict_types=1);
require_once __DIR__ . '/authorization.php';
$owner = require_owner();
$csrf = csrf_token();
require_once __DIR__ . '/report_lib.php';
require_once __DIR__ . '/nexsus_shell.php';
$today = date('Y-m-d');
$taskCounts = nrOne($conn,"SELECT COUNT(*) AS total,
 COALESCE(SUM(status='pending'),0) AS pending,
 COALESCE(SUM(status='in_progress'),0) AS in_progress,
 COALESCE(SUM(status='completed'),0) AS completed,
 COALESCE(SUM(status IN ('pending','in_progress') AND due_date<CURDATE()),0) AS overdue
 FROM tasks");
$clientCounts = nrOne($conn,"SELECT COUNT(*) AS total,
 COALESCE(SUM(status='active'),0) AS active FROM clients");
$requestCounts = nrOne($conn,"SELECT COUNT(*) AS total,
 COALESCE(SUM(request_status IN ('pending','in_progress')),0) AS open_count,
 COALESCE(SUM(request_status IN ('pending','in_progress') AND due_date<CURDATE()),0) AS overdue
 FROM service_requests");
$pendingContacts = (int)(nrOne($conn,"SELECT COUNT(*) AS n FROM contact_requests WHERE status='new'")['n']??0);
$workload = nrRows($conn,"SELECT u.full_name,
 COUNT(t.task_id) AS total,
 COALESCE(SUM(t.status IN ('pending','in_progress')),0) AS open_count,
 COALESCE(SUM(t.status='completed'),0) AS completed,
 COALESCE(SUM(t.status IN ('pending','in_progress') AND t.due_date<CURDATE()),0) AS overdue
 FROM users u LEFT JOIN tasks t ON t.assigned_to=u.user_id
 WHERE u.role='employee' GROUP BY u.user_id,u.full_name ORDER BY u.full_name");
$serviceLoad = nrRows($conn,"SELECT s.service_name,COUNT(sr.service_request_id) AS requests
 FROM services s LEFT JOIN service_requests sr ON sr.service_id=s.service_id
 GROUP BY s.service_id,s.service_name ORDER BY requests DESC,s.service_name LIMIT 8");
$upcomingTasks = nrRows($conn,"SELECT t.task_id,t.task_title,t.due_date,t.priority,u.full_name AS employee_name
 FROM tasks t LEFT JOIN users u ON u.user_id=t.assigned_to
 WHERE t.status IN ('pending','in_progress') AND t.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY)
 ORDER BY t.due_date,t.task_id LIMIT 8");

$attentionTaskRows = nrRows($conn, "SELECT t.task_id,t.task_title AS work_title,t.status,t.start_date,t.due_date,
 COALESCE(u.full_name,'Unassigned') AS responsible
 FROM tasks t LEFT JOIN users u ON u.user_id=t.assigned_to
 WHERE t.status IN ('pending','in_progress') AND
   (t.due_date <= DATE_ADD(CURDATE(), INTERVAL 2 DAY)
    OR (t.status='pending' AND DATEDIFF(CURDATE(),t.start_date)>=7)
    OR (t.status='in_progress' AND DATEDIFF(CURDATE(),t.start_date)>=14))
 ORDER BY t.due_date,t.task_id LIMIT 100");
$attentionRequestRows = nrRows($conn, "SELECT sr.service_request_id,s.service_name AS work_title,
 sr.request_status AS status,sr.start_date,sr.due_date,
 COALESCE(u.full_name,'Unassigned') AS responsible
 FROM service_requests sr JOIN services s ON s.service_id=sr.service_id
 LEFT JOIN users u ON u.user_id=sr.assigned_to
 WHERE sr.request_status IN ('pending','in_progress') AND
   (sr.due_date <= DATE_ADD(CURDATE(), INTERVAL 2 DAY)
    OR (sr.request_status='pending' AND DATEDIFF(CURDATE(),sr.start_date)>=7)
    OR (sr.request_status='in_progress' AND DATEDIFF(CURDATE(),sr.start_date)>=14))
 ORDER BY sr.due_date,sr.service_request_id LIMIT 100");
$attentionQueue = [];
foreach ($attentionTaskRows as $taskRow) {
    $flag = nexUrgency((string)$taskRow['status'],(string)$taskRow['due_date'],(string)$taskRow['start_date'],$today);
    if (in_array($flag['key'], ['closed','on_track'], true)) continue;
    $attentionQueue[] = ['type'=>'Task', 'title'=>$taskRow['work_title'], 'responsible'=>$taskRow['responsible'],
        'due'=>$taskRow['due_date'],'flag'=>$flag,'url'=>'tasks.php?view='.(int)$taskRow['task_id'].'#taskDetails'];
}
foreach ($attentionRequestRows as $requestRow) {
    $flag = nexUrgency((string)$requestRow['status'],(string)$requestRow['due_date'],(string)$requestRow['start_date'],$today);
    if (in_array($flag['key'], ['closed','on_track'], true)) continue;
    $attentionQueue[] = ['type'=>'Service request', 'title'=>$requestRow['work_title'], 'responsible'=>$requestRow['responsible'],
        'due'=>$requestRow['due_date'],'flag'=>$flag,'url'=>'serviceRequests.php?view='.(int)$requestRow['service_request_id'].'#requestDetails'];
}
usort($attentionQueue, static fn(array $a,array $b):int =>
    (nexUrgencyRank($a['flag']['key']) <=> nexUrgencyRank($b['flag']['key'])) ?: strcmp((string)$a['due'],(string)$b['due']));
$attentionShown = array_slice($attentionQueue,0,6);
$dueSoonTasks = (int)(nrOne($conn,"SELECT COUNT(*) AS n FROM tasks
  WHERE status IN ('pending','in_progress') AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 2 DAY)")['n']??0);
$dueSoonRequests = (int)(nrOne($conn,"SELECT COUNT(*) AS n FROM service_requests
  WHERE request_status IN ('pending','in_progress') AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 2 DAY)")['n']??0);
$ageingTasks = (int)(nrOne($conn,"SELECT COUNT(*) AS n FROM tasks
  WHERE status IN ('pending','in_progress') AND due_date > DATE_ADD(CURDATE(),INTERVAL 2 DAY)
  AND ((status='pending' AND DATEDIFF(CURDATE(),start_date)>=7) OR
       (status='in_progress' AND DATEDIFF(CURDATE(),start_date)>=14))")['n']??0);
$ageingRequests = (int)(nrOne($conn,"SELECT COUNT(*) AS n FROM service_requests
  WHERE request_status IN ('pending','in_progress') AND due_date > DATE_ADD(CURDATE(),INTERVAL 2 DAY)
  AND ((request_status='pending' AND DATEDIFF(CURDATE(),start_date)>=7) OR
       (request_status='in_progress' AND DATEDIFF(CURDATE(),start_date)>=14))")['n']??0);
$overdueTotal = (int)$taskCounts['overdue'] + (int)$requestCounts['overdue'];
$dueSoonTotal = $dueSoonTasks + $dueSoonRequests;
$ageingTotal = $ageingTasks + $ageingRequests;
// Disjoint deadline groups: overdue, due within two days, or ageing with later due dates.
$needsAttention = $overdueTotal + $dueSoonTotal + $ageingTotal;

$monthInput = is_string($_GET['month'] ?? null) ? $_GET['month'] : date('Y-m');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',$monthInput)) $monthInput=date('Y-m');
try { $first = new DateTimeImmutable($monthInput.'-01'); }
catch (Throwable $e) { $first=new DateTimeImmutable(date('Y-m-01')); }
$next=$first->modify('first day of next month');
$end=$next->modify('-1 day');
$monthStart=$first->format('Y-m-d'); $monthEnd=$end->format('Y-m-d');
$days=(int)$end->format('j'); $offset=(int)$first->format('N')-1;
$taskDates=nrRows($conn,"SELECT t.task_id,t.task_title,t.due_date,t.status
 FROM tasks t WHERE t.due_date BETWEEN ? AND ? ORDER BY t.due_date,t.task_id",[$monthStart,$monthEnd]);
$requestDates=nrRows($conn,"SELECT sr.service_request_id,s.service_name,sr.due_date,sr.request_status
 FROM service_requests sr JOIN services s ON s.service_id=sr.service_id
 WHERE sr.due_date BETWEEN ? AND ? ORDER BY sr.due_date,sr.service_request_id",[$monthStart,$monthEnd]);
$calendar=[];
foreach($taskDates as $t) $calendar[$t['due_date']][]=['kind'=>'Task','name'=>$t['task_title'],'url'=>'tasks.php?view='.(int)$t['task_id'].'#taskDetails','done'=>$t['status']==='completed','flag'=>nexUrgency($t['status'],$t['due_date'],null,$today)['key']];
foreach($requestDates as $r) $calendar[$r['due_date']][]=['kind'=>'Request','name'=>$r['service_name'],'url'=>'serviceRequests.php?view='.(int)$r['service_request_id'].'#requestDetails','done'=>in_array($r['request_status'],['completed','cancelled'],true),'flag'=>nexUrgency($r['request_status'],$r['due_date'],null,$today)['key']];
$taskChart=[
    (int)$taskCounts['pending'] - (int)(nrOne($conn,"SELECT COUNT(*) n FROM tasks WHERE status='pending' AND due_date<CURDATE()")['n'] ?? 0),
    (int)$taskCounts['in_progress'] - (int)(nrOne($conn,"SELECT COUNT(*) n FROM tasks WHERE status='in_progress' AND due_date<CURDATE()")['n'] ?? 0),
    (int)$taskCounts['completed'],
    (int)$taskCounts['overdue'],
];

?>
<?php nexShellStart($owner, 'Owner Dashboard', 'ownerDashboard.php', $csrf, ['mainClass'=>'nr-page nr-owner-page']); ?>
  <div class="nr-head"><div><p class="nr-eyebrow">BUSINESS OVERVIEW</p><h2>Welcome back, <?= h(explode(' ',trim((string)$owner['full_name']))[0]) ?></h2><p>Live operations summary, deadlines and employee workload.</p></div>
    <a href="reports.php" class="nr-primary"><i class="bi bi-bar-chart-line"></i> Open Reports</a></div>
  <section class="nr-kpis nr-owner-kpis" aria-label="Business KPIs">
    <a href="clients.php" class="nr-kpi"><span><i class="bi bi-people"></i> Total Clients</span><strong><?= (int)$clientCounts['total'] ?></strong></a>
    <a href="serviceRequests.php" class="nr-kpi"><span><i class="bi bi-kanban"></i> Open Service Requests</span><strong><?= (int)$requestCounts['open_count'] ?></strong></a>
    <a href="tasks.php" class="nr-kpi"><span><i class="bi bi-list-task"></i> Total Tasks</span><strong><?= (int)$taskCounts['total'] ?></strong></a>
    <a href="tasks.php?status=completed" class="nr-kpi"><span><i class="bi bi-check-circle"></i> Completed Tasks</span><strong><?= (int)$taskCounts['completed'] ?></strong></a>
    <a href="tasks.php?status=in_progress" class="nr-kpi"><span><i class="bi bi-arrow-repeat"></i> In Progress Tasks</span><strong><?= (int)$taskCounts['in_progress'] ?></strong></a>
    <a href="tasks.php?status=overdue" class="nr-kpi <?= (int)$taskCounts['overdue'] > 0 ? 'nr-kpi-danger' : '' ?>"><span><i class="bi bi-exclamation-circle"></i> Overdue Tasks</span><strong><?= (int)$taskCounts['overdue'] ?></strong></a>
    <a href="serviceRequests.php?status=overdue" class="nr-kpi <?= (int)$requestCounts['overdue'] > 0 ? 'nr-kpi-danger' : '' ?>"><span><i class="bi bi-exclamation-circle"></i> Overdue Requests</span><strong><?= (int)$requestCounts['overdue'] ?></strong></a>
    <a href="#urgentWork" class="nr-kpi <?= $needsAttention > 0 ? 'nr-kpi-amber' : '' ?>"><span><i class="bi bi-flag"></i> Needs Attention</span><strong><?= $needsAttention ?></strong></a>
  </section>
  <section class="nr-panel nr-attention-panel <?= $needsAttention > 0 ? 'nr-has-attention' : '' ?>" id="urgentWork" aria-labelledby="nrUrgentHeading">
    <div class="nr-panel-head"><div><p class="nr-eyebrow">LIVE DEADLINE FLAGS</p><h3 id="nrUrgentHeading"><i class="bi bi-exclamation-octagon"></i> Work Needing Attention</h3></div>
      <a href="reports.php?report_type=deadline_monitoring">Full Deadline Report</a></div>
    <div class="nr-urgency-summary"><span><b><?= $overdueTotal ?></b> Overdue</span>
      <span><b><?= $dueSoonTotal ?></b> Due soon</span>
      <span><b><?= $ageingTotal ?></b> Ageing</span></div>
    <?php if (!$attentionShown): ?><p class="nr-muted">No urgent work.</p><?php else: ?>
    <div class="nr-attention-list">
      <?php foreach ($attentionShown as $item): $key=$item['flag']['key']; ?>
        <a class="nr-alert-card nr-alert-<?= h($key) ?>" href="<?= h($item['url']) ?>">
          <div class="nr-alert-card-title"><span class="nr-alert-kind"><?= h($item['type']) ?></span>
            <span class="nex-urgency-badge is-<?= h($key==='due_today'?'due-soon':str_replace('_','-',$key)) ?>"><?= h($item['flag']['label']) ?></span></div>
          <strong><?= h((string)$item['title']) ?></strong>
          <small><?= h((string)$item['responsible']) ?> · Due <?= h((string)$item['due']) ?></small>
        </a>
      <?php endforeach; ?>
    </div><?php if ($needsAttention > count($attentionShown)): ?><a class="nr-view-all" href="reports.php?report_type=deadline_monitoring">View all flagged work <i class="bi bi-arrow-right" aria-hidden="true"></i></a><?php endif; endif; ?>
  </section>
  <div class="nr-two-col">
    <section class="nr-panel"><div class="nr-panel-head"><h3>Task Status Overview</h3><a href="reports.php?report_type=task_progress">Detailed report</a></div>
      
      <div class="nr-chart"><canvas id="nrTaskChart" role="img" aria-label="Tasks grouped once by status and overdue flag"></canvas></div></section>
    <section class="nr-panel"><div class="nr-panel-head"><h3>Employee Workload</h3><a href="reports.php?report_type=employee_workload">Detailed report</a></div>
      
      <div class="nr-chart"><canvas id="nrWorkloadChart" role="img" aria-label="Open tasks per employee"></canvas></div></section>
  </div>
  <div class="nr-two-col">
    <section class="nr-panel"><div class="nr-panel-head"><h3>Service Activity</h3><a href="reports.php?report_type=service_performance">Service report</a></div>
      
      <div class="nr-chart"><canvas id="nrServicesChart" role="img" aria-label="Number of service requests by service"></canvas></div></section>
    <section class="nr-panel"><div class="nr-panel-head"><h3>Upcoming Tasks (Next 7 Days)</h3><a href="reports.php?report_type=deadline_monitoring">Deadline report</a></div>
      <?php if (!$upcomingTasks): ?><p class="nr-muted">No incomplete tasks due within the next seven days.</p>
      <?php else: ?><div class="nr-due-list"><?php foreach ($upcomingTasks as $t): ?>
        <a href="tasks.php?view=<?= (int)$t['task_id'] ?>#taskDetails"><strong><?= h((string)$t['task_title']) ?></strong><span><?= h((string)($t['employee_name']?:'Unassigned')) ?> · <?= h((string)$t['due_date']) ?></span></a>
      <?php endforeach; ?></div><?php endif; ?>
      <a class="nr-quicklink" href="contactRequests.php"><i class="bi bi-envelope"></i> New contact requests: <strong><?= $pendingContacts ?></strong></a>
    </section>
  </div>
  <section class="nr-panel nr-calendar-panel" id="calendar"><div class="nr-panel-head"><div><h3>Deadline Calendar</h3></div>
    <div class="nr-calendar-controls"><a aria-label="Previous month" href="ownerDashboard.php?month=<?= h($first->modify('-1 month')->format('Y-m')) ?>#calendar"><i class="bi bi-chevron-left"></i></a><a href="ownerDashboard.php#calendar">Today</a><a aria-label="Next month" href="ownerDashboard.php?month=<?= h($next->format('Y-m')) ?>#calendar"><i class="bi bi-chevron-right"></i></a></div></div>
    <div class="nr-cal-wrap"><div class="nr-calendar">
      <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $day): ?><div class="nr-weekday"><?= $day ?></div><?php endforeach; ?>
      <?php for ($blank=0;$blank<$offset;$blank++): ?><div class="nr-calendar-empty" aria-hidden="true"></div><?php endfor; ?>
      <?php for ($day=1;$day<=$days;$day++): $date=$first->format('Y-m-').sprintf('%02d',$day); $events=$calendar[$date]??[]; ?>
        <div class="nr-day <?= $date===$today?'nr-today':'' ?>"><span class="nr-day-number"><?= $day ?></span>
        <?php foreach(array_slice($events,0,3) as $event): ?><a class="nr-cal-event <?= $event['done']?'nr-done':'nr-cal-'.$event['flag'] ?>" href="<?= h($event['url']) ?>" title="<?= h($event['kind'].': '.$event['name']) ?>"><?= $event['kind']==='Task'?'T':'R' ?> · <?= h((string)$event['name']) ?></a><?php endforeach; ?>
        <?php if(count($events)>3): ?><small>+<?= count($events)-3 ?> more</small><?php endif; ?></div>
      <?php endfor; ?>
    </div></div></section>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.6.1/dist/chart.umd.min.js"></script>
<script>
(() => {
  if (typeof Chart === 'undefined') return;
  const draw = (id, type, labels, values, colors, horizontal = false) => {
    const canvas = document.getElementById(id); if (!canvas) return;
    new Chart(canvas, {
      type, data: { labels, datasets: [{ data: values, backgroundColor: colors, borderRadius: type === 'bar' ? 7 : 0, maxBarThickness: 44 }] },
      options: { responsive: true, maintainAspectRatio: false, indexAxis: horizontal ? 'y' : 'x',
        plugins: { legend: { display: type === 'doughnut', position: 'bottom' } }, scales: type === 'bar' ? (horizontal ? { x:{beginAtZero:true,ticks:{precision:0}} } : { y:{beginAtZero:true,ticks:{precision:0}} }) : {} }
    });
  };
  draw('nrTaskChart','doughnut',['Pending','In Progress','Completed','Overdue'],<?= json_encode($taskChart, JSON_HEX_TAG|JSON_HEX_AMP) ?>,['#c8a34e','#4d84b8','#2e9b7b','#cf6a62']);
  draw('nrWorkloadChart','bar',<?= json_encode(array_column($workload,'full_name'),JSON_HEX_TAG|JSON_HEX_AMP) ?>,<?= json_encode(array_map('intval',array_column($workload,'open_count'))) ?>,'#456ea5',true);
  draw('nrServicesChart','bar',<?= json_encode(array_column($serviceLoad,'service_name'),JSON_HEX_TAG|JSON_HEX_AMP) ?>,<?= json_encode(array_map('intval',array_column($serviceLoad,'requests'))) ?>,'#b79b57',true);
})();
</script>

<?php nexShellEnd(); ?>
