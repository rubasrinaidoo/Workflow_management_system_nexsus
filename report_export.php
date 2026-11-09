<?php
declare(strict_types=1);
require_once __DIR__ . '/authorization.php';
require_once __DIR__ . '/report_lib.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$owner = require_owner();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); exit('Open Reports and choose Export CSV or Print.');
}
if (!csrf_valid()) { http_response_code(403); exit('Invalid security token. Reload Reports.'); }
$output = post_string('output');
if (!in_array($output,['csv','print'],true)) { http_response_code(400); exit('Invalid output choice.'); }
try {
    $filters = nrFilters($_POST);
    $report = nrBuild($conn, $filters);
    $conn->begin_transaction();
    nrAuditExport($conn,(int)$owner['user_id'],$output,$filters);
    $conn->commit();
} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    error_log('NEXSUS report export: '.$e->getMessage());
    http_response_code(503);
    exit('Unable to generate the report. Check the report filters and database setup.');
}
$title = nrTypes()[$filters['report_type']];
if ($filters['report_type'] === 'service_performance') {
    $printMetrics = [
        'Service requests' => (int)$report['requestSummary']['total'],
        'Pending' => (int)$report['requestSummary']['pending'],
        'In Progress' => (int)$report['requestSummary']['in_progress'],
        'Completed' => (int)$report['requestSummary']['completed'],
        'Overdue' => (int)$report['requestSummary']['overdue'],
    ];
} elseif ($filters['report_type'] === 'deadline_monitoring') {
    $printMetrics = [
        'Task records' => (int)$report['taskCount'],
        'Service requests' => (int)$report['requestCount'],
        'Overdue tasks' => (int)$report['attention']['task_overdue'],
        'Overdue requests' => (int)$report['attention']['request_overdue'],
    ];
} else {
    $printMetrics = [
        'Tasks' => (int)$report['summary']['total'],
        'Pending' => (int)$report['summary']['pending'],
        'In Progress' => (int)$report['summary']['in_progress'],
        'Completed' => (int)$report['summary']['completed'],
        'Overdue' => (int)$report['summary']['overdue'],
    ];
}
$dueRange = ($filters['date_from'] || $filters['date_to'])
    ? ' · Due: ' . ($filters['date_from'] ?: 'Any') . ' to ' . ($filters['date_to'] ?: 'Any')
    : '';
if ($output==='csv') {
    $filename = 'NEXSUS_'.preg_replace('/[^a-z0-9_]+/i','_',$filters['report_type']).'_'.date('Ymd_His').'.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    $stream=fopen('php://output','wb');
    fwrite($stream,"\xEF\xBB\xBF"); // UTF-8 BOM helps Excel open correctly.
    fputcsv($stream,array_map('nrCsvSafe',$report['columns']));
    foreach($report['rows'] as $row) fputcsv($stream,array_map('nrCsvSafe',$row));
    fclose($stream);
    exit;
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($title) ?> | NEXSUS Print</title><style>
body{font-family:"Segoe UI",Arial,sans-serif;font-weight:600;color:#152b44;margin:24px} h1{margin:0 0 9px}p{color:#5b6d7e} table{border-collapse:collapse;width:100%;font-size:11px}th,td{border:1px solid #cdd6df;padding:7px 8px;text-align:left;vertical-align:top;overflow-wrap:anywhere}th{background:#eaf0f7;font-weight:800} .report-toolbar{display:flex;gap:12px;margin:16px 0}button,a{padding:10px 15px;border:1px solid #5677a6;border-radius:6px;background:white;color:#1a497b;text-decoration:none;cursor:pointer} .meta{margin-bottom:14px}.metrics{display:flex;gap:22px;margin:14px 0}.metrics span{border:1px solid #ccc;padding:8px}.print-danger{background:#fff1f1;color:#9d2734;font-weight:800}.print-soon{background:#fff4db;color:#805107;font-weight:800}
@page{size:landscape;margin:12mm}@media print{.report-toolbar{display:none}body{margin:0}thead{display:table-header-group}tr{break-inside:avoid}}
</style></head><body>
<h1><?= h($title) ?></h1>
<p class="meta">NEXSUS Business Solutions · Generated <?= h(date('Y-m-d H:i:s')) ?><?= h($dueRange) ?></p>
<div class="metrics"><?php foreach ($printMetrics as $label => $count): ?><span><?= h($label) ?>: <strong><?= $count ?></strong></span><?php endforeach; ?></div>
<div class="report-toolbar"><button type="button" onclick="window.print()">Print / Save PDF</button><a href="reports.php">Back to Reports</a></div>
<table><thead><tr><?php foreach($report['columns'] as $c): ?><th><?= h($c) ?></th><?php endforeach; ?></tr></thead><tbody>
<?php if (!$report['rows']): ?><tr><td colspan="<?= count($report['columns']) ?>">No matching records.</td></tr><?php else: $flagIndex=array_search('Attention Flag',$report['columns'],true); foreach($report['rows'] as $r): ?>
<tr><?php foreach($r as $i=>$cell): $txt=(string)($cell??''); $cls=($i===$flagIndex&&str_starts_with($txt,'Overdue'))?'print-danger':(($i===$flagIndex&&(str_starts_with($txt,'Due ')||str_starts_with($txt,'Taking too long')))?'print-soon':''); ?><td class="<?= $cls ?>"><?= h($txt) ?></td><?php endforeach; ?></tr>
<?php endforeach; endif; ?></tbody></table>
<script>window.addEventListener('load',()=>window.print());</script>
</body></html>
