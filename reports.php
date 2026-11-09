<?php
declare(strict_types=1);
require_once __DIR__ . '/authorization.php';
$owner = require_owner();
$csrf = csrf_token();
require_once __DIR__ . '/report_lib.php';
require_once __DIR__ . '/nexsus_shell.php';
$employees=nrRows($conn,"SELECT user_id,full_name FROM users WHERE role='employee' ORDER BY full_name");
$services=nrRows($conn,"SELECT service_id,service_name,category FROM services ORDER BY category,service_name");
$error='';
try {
    $filters=nrFilters($_GET);
    $report=nrBuild($conn,$filters);
} catch (InvalidArgumentException $e) {
    $error=$e->getMessage();
    $filters=nrFilters([]);
    $report=nrBuild($conn,$filters);
}

?>
<?php nexShellStart($owner, 'Reports', 'reports.php', $csrf, ['mainClass'=>'nr-page nr-reports']); ?>
  <div class="nr-head"><div><p class="nr-eyebrow">MONITORING / REPORTS</p><h2>Reports &amp; Analytics</h2><p>Filter live work records, review performance, print and export CSV.</p></div>
    <a class="nr-secondary" href="ownerDashboard.php"><i class="bi bi-arrow-left"></i> Owner Dashboard</a></div>
  <?php if ($error): ?><p class="nr-error" role="alert"><?= h($error) ?></p><?php endif; ?>
  <section class="nr-panel"><div class="nr-panel-head"><h3>Build your report</h3></div>
    <form method="get" action="reports.php" class="nr-filters">
      <label>Report type<select name="report_type" required>
        <?php foreach(nrTypes() as $type=>$label): ?><option value="<?= h($type) ?>" <?= $filters['report_type']===$type?'selected':'' ?>><?= h($label) ?></option><?php endforeach; ?>
      </select></label>
      <label>Employee<select name="employee_id"><option value="">All employees</option>
      <?php foreach($employees as $e): ?><option value="<?= (int)$e['user_id'] ?>" <?= $filters['employee_id']===(int)$e['user_id']?'selected':'' ?>><?= h((string)$e['full_name']) ?></option><?php endforeach; ?>
      </select></label>
      <label>Service<select name="service_id"><option value="">All services</option>
      <?php foreach($services as $s): ?><option value="<?= (int)$s['service_id'] ?>" <?= $filters['service_id']===(int)$s['service_id']?'selected':'' ?>><?= h($s['category'].' · '.$s['service_name']) ?></option><?php endforeach; ?>
      </select></label>
      <label>Due from<input name="date_from" type="date" value="<?= h($filters['date_from']) ?>"></label>
      <label>Due to<input name="date_to" type="date" value="<?= h($filters['date_to']) ?>"></label>
      <div class="nr-filter-actions"><button class="nr-primary" type="submit"><i class="bi bi-funnel"></i> Apply Filters</button><a class="nr-secondary" href="reports.php">Reset</a></div>
    </form>
  </section>
  <section class="nr-kpis nr-report-kpis" aria-label="Filtered report KPIs">
    <?php if ($filters['report_type'] === 'service_performance'): ?>
      <article class="nr-kpi"><span><i class="bi bi-kanban"></i> Service Requests</span><strong><?= (int)$report['requestSummary']['total'] ?></strong></article>
      <article class="nr-kpi"><span><i class="bi bi-hourglass-split"></i> Pending</span><strong><?= (int)$report['requestSummary']['pending'] ?></strong></article>
      <article class="nr-kpi"><span><i class="bi bi-arrow-repeat"></i> In Progress</span><strong><?= (int)$report['requestSummary']['in_progress'] ?></strong></article>
      <article class="nr-kpi"><span><i class="bi bi-check-circle"></i> Completed</span><strong><?= (int)$report['requestSummary']['completed'] ?></strong></article>
      <article class="nr-kpi <?= (int)$report['requestSummary']['overdue'] > 0 ? 'nr-kpi-danger' : '' ?>"><span><i class="bi bi-exclamation-circle"></i> Overdue Requests</span><strong><?= (int)$report['requestSummary']['overdue'] ?></strong></article>
      <article class="nr-kpi"><span><i class="bi bi-x-circle"></i> Cancelled</span><strong><?= (int)$report['requestSummary']['cancelled'] ?></strong></article>
    <?php elseif ($filters['report_type'] === 'deadline_monitoring'): ?>
      <article class="nr-kpi"><span><i class="bi bi-list-task"></i> Task Records</span><strong><?= (int)$report['taskCount'] ?></strong></article>
      <article class="nr-kpi"><span><i class="bi bi-kanban"></i> Service Requests</span><strong><?= (int)$report['requestCount'] ?></strong></article>
      <article class="nr-kpi <?= (int)$report['attention']['task_overdue'] > 0 ? 'nr-kpi-danger' : '' ?>"><span><i class="bi bi-exclamation-circle"></i> Overdue Tasks</span><strong><?= (int)$report['attention']['task_overdue'] ?></strong></article>
      <article class="nr-kpi <?= (int)$report['attention']['request_overdue'] > 0 ? 'nr-kpi-danger' : '' ?>"><span><i class="bi bi-exclamation-circle"></i> Overdue Requests</span><strong><?= (int)$report['attention']['request_overdue'] ?></strong></article>
      <article class="nr-kpi"><span><i class="bi bi-calendar2-check"></i> Due Soon</span><strong><?= (int)($report['attention']['task_due_today'] + $report['attention']['task_due_soon'] + $report['attention']['request_due_today'] + $report['attention']['request_due_soon']) ?></strong></article>
      <article class="nr-kpi"><span><i class="bi bi-clock-history"></i> Ageing Work</span><strong><?= (int)($report['attention']['task_ageing'] + $report['attention']['request_ageing']) ?></strong></article>
    <?php else: ?>
      <article class="nr-kpi"><span><i class="bi bi-list-task"></i> Total Tasks</span><strong><?= (int)$report['summary']['total'] ?></strong></article>
      <article class="nr-kpi"><span><i class="bi bi-hourglass-split"></i> Pending</span><strong><?= (int)$report['summary']['pending'] ?></strong></article>
      <article class="nr-kpi"><span><i class="bi bi-arrow-repeat"></i> In Progress</span><strong><?= (int)$report['summary']['in_progress'] ?></strong></article>
      <article class="nr-kpi"><span><i class="bi bi-check-circle"></i> Completed</span><strong><?= (int)$report['summary']['completed'] ?></strong></article>
      <article class="nr-kpi <?= (int)$report['summary']['overdue'] > 0 ? 'nr-kpi-danger' : '' ?>"><span><i class="bi bi-exclamation-circle"></i> Overdue Tasks</span><strong><?= (int)$report['summary']['overdue'] ?></strong></article>
    <?php endif; ?>
  </section>
  <section class="nr-panel"><div class="nr-panel-head"><div><h3><?= h(nrTypes()[$filters['report_type']]) ?></h3></div>
    <div class="nr-actions"><form method="post" action="report_export.php" class="nr-export-form">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <?php foreach($filters as $key=>$value): ?><input type="hidden" name="<?= h($key) ?>" value="<?= h((string)$value) ?>"><?php endforeach; ?>
      <button type="submit" name="output" value="csv" class="nr-secondary"><i class="bi bi-filetype-csv"></i> Export CSV</button>
      <button type="submit" name="output" value="print" class="nr-secondary"><i class="bi bi-printer"></i> Print / Save PDF</button>
    </form></div></div>
    <?php if(!$report['chartLabels']): ?><p class="nr-muted">There are no matching records to chart.</p><?php else: ?>
      <div class="nr-chart nr-chart-report"><canvas id="nrReportChart" role="img" aria-label="Chart of selected report"></canvas></div>
    <?php endif; ?>
    <div class="nr-table-wrap"><table class="nr-table"><thead><tr>
      <?php foreach($report['columns'] as $column): ?><th scope="col"><?= h($column) ?></th><?php endforeach; ?>
    </tr></thead><tbody>
      <?php if (!$report['rows']): ?><tr><td colspan="<?= count($report['columns']) ?>">No records match the selected filters.</td></tr><?php else: ?>
      <?php $flagIndex=array_search('Attention Flag',$report['columns'],true); foreach($report['rows'] as $r):
        $flagText=$flagIndex!==false?(string)($r[$flagIndex]??''):'';
        $flagClass=str_starts_with($flagText,'Overdue')?'overdue':(str_starts_with($flagText,'Due')?'due-soon':(str_starts_with($flagText,'Taking')?'ageing':'')); ?>
        <tr class="<?= $flagClass!==''?'nr-row-'.$flagClass:'' ?>">
          <?php foreach($r as $i=>$cell): ?><td>
            <?php if ($i===$flagIndex && $flagClass!==''): ?><span class="nex-urgency-badge is-<?= h($flagClass) ?>"><?= h((string)($cell??'')) ?></span>
            <?php else: ?><?= h((string)($cell??'')) ?><?php endif; ?>
          </td><?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      <?php endif; ?>
    </tbody></table></div>
  </section>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.6.1/dist/chart.umd.min.js"></script>
<script>
(() => {
  const ctx=document.getElementById('nrReportChart');
  if (!ctx || typeof Chart==='undefined') return;
  const isTask=<?= json_encode($filters['report_type']==='task_progress') ?>;
  const labels=<?= json_encode($report['chartLabels'],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  const numbers=<?= json_encode($report['chartValues'],JSON_HEX_TAG|JSON_HEX_AMP) ?>;
  new Chart(ctx,{type:isTask?'doughnut':'bar',data:{labels,datasets:[{label:'Records',data:numbers,
    backgroundColor:isTask?['#c8a34e','#4d84b8','#2e9b7b','#cf6a62']:'#527ca9',borderRadius:isTask?0:6}]},
    options:{responsive:true,maintainAspectRatio:false,indexAxis:isTask?'x':'y',
      plugins:{legend:{display:isTask}},scales:isTask?{}:{x:{beginAtZero:true,ticks:{precision:0}}}}});
})();
</script>

<?php nexShellEnd(); ?>
