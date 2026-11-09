<?php
declare(strict_types=1);
require_once __DIR__ . '/authorization.php';
require_once __DIR__ . '/nexsus_shell.php';

$owner = require_owner();               // owner only
$csrf  = csrf_token();
$user  = $owner + ['role' => 'owner'];

/* ---------- small helpers (prefixed al_ so they never clash with other files) ---------- */
function al_e($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function al_rows(mysqli $c, string $sql, array $params = []): array {
    $s = $c->prepare($sql);
    if ($params) $s->execute($params); else $s->execute();
    $rows = $s->get_result()->fetch_all(MYSQLI_ASSOC);
    $s->close();
    return $rows;
}
function al_date(string $v): string {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : '';
}
function al_badge(string $action): string {
    $a = strtolower($action);
    if (str_contains($a, 'fail') || str_contains($a, 'delete') || str_contains($a, 'remove')) return 'bad';
    if (str_contains($a, 'creat') || str_contains($a, 'unlock') || str_contains($a, 'add')) return 'good';
    if (str_contains($a, 'updat') || str_contains($a, 'chang') || str_contains($a, 'edit')) return 'warn';
    return 'info';
}

/* ---------- read filters from the URL ---------- */
$q      = substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$module = substr(trim((string)($_GET['module'] ?? '')), 0, 60);
$from   = al_date((string)($_GET['from'] ?? ''));
$to     = al_date((string)($_GET['to'] ?? ''));
$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;

/* ---------- discover which columns audit_logs has ---------- */
$error = null; $rows = []; $modules = []; $total = 0; $pages = 1;
try {
    $cols = array_column(al_rows($conn,
        'SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
        ['audit_logs']), 'c');

    if (count(array_diff(['actor_user_id', 'action_key', 'module_name'], $cols)) > 0) {
        $error = 'The audit_logs table is missing or does not have the expected columns.';
    } else {
        $timeCol = null;
        foreach (['created_at', 'logged_at', 'action_at', 'timestamp'] as $c) {
            if (in_array($c, $cols, true)) { $timeCol = $c; break; }
        }
        $timeSql   = $timeCol ? 'a.`' . $timeCol . '`' : 'NULL';
        $orderSql  = $timeCol ? 'a.`' . $timeCol . '` DESC' : 'a.`' . $cols[0] . '` DESC';
        $recordSql = in_array('record_id', $cols, true) ? 'a.record_id' : 'NULL';
        $detailSql = in_array('details', $cols, true) ? 'a.details' : 'NULL';
        $searchDetails = in_array('details', $cols, true);

        /* ---------- build WHERE with prepared-statement parameters ---------- */
        $where = []; $params = [];
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $parts = ['u.full_name LIKE ?', 'a.action_key LIKE ?', 'a.module_name LIKE ?'];
            $params = [$like, $like, $like];
            if ($searchDetails) { $parts[] = 'a.details LIKE ?'; $params[] = $like; }
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }
        if ($module !== '') { $where[] = 'a.module_name = ?'; $params[] = $module; }
        if ($timeCol && $from !== '') { $where[] = "a.`$timeCol` >= ?"; $params[] = $from . ' 00:00:00'; }
        if ($timeCol && $to !== '')   { $where[] = "a.`$timeCol` < DATE_ADD(?, INTERVAL 1 DAY)"; $params[] = $to . ' 00:00:00'; }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $joinSql  = ' FROM audit_logs a LEFT JOIN users u ON u.user_id = a.actor_user_id';

        $total = (int)(al_rows($conn, 'SELECT COUNT(*) AS n' . $joinSql . $whereSql, $params)[0]['n'] ?? 0);
        $pages = max(1, (int)ceil($total / $perPage));
        $page  = min($page, $pages);
        $offset = ($page - 1) * $perPage;

        $rows = al_rows($conn,
            "SELECT $timeSql AS at, u.full_name AS who, a.action_key, a.module_name, $recordSql AS record_id, $detailSql AS details"
            . $joinSql . $whereSql . " ORDER BY $orderSql LIMIT " . (int)$perPage . ' OFFSET ' . (int)$offset,
            $params);

        $modules = array_column(al_rows($conn, 'SELECT DISTINCT module_name FROM audit_logs WHERE module_name <> \'\' ORDER BY module_name'), 'module_name');
    }
} catch (Throwable $e) {
    error_log('NEXSUS audit log page: ' . $e->getMessage());
    $error = 'The audit log could not be read. Check the PHP error log.';
}

/* link builder that keeps the current filters */
function al_link(array $extra): string {
    global $q, $module, $from, $to;
    $base = ['q' => $q, 'module' => $module, 'from' => $from, 'to' => $to];
    return 'auditLog.php?' . http_build_query(array_filter($extra + $base, fn($v) => $v !== '' && $v !== null));
}

/* ---------- page CSS (inline, uses the shared NEXSUS colour variables) ---------- */
$pageHead = <<<'HTML'
<style>
.audit-page{padding:28px clamp(18px,3vw,38px) 50px}
.al-heading{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:20px}
.al-eyebrow{margin:0 0 6px;color:var(--blue);font-size:11px;font-weight:800;letter-spacing:.12em}
.al-heading h2{margin:0;font-size:clamp(24px,2.4vw,31px);font-weight:800;color:var(--text)}
.al-heading p:not(.al-eyebrow){margin:6px 0 0;color:var(--muted);font-size:13px}
.al-head-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.al-count{background:var(--surface);border:1px solid var(--line);color:var(--muted);padding:7px 13px;border-radius:999px;font-size:12px;font-weight:800;white-space:nowrap}
.al-panel{background:var(--surface);border:1px solid var(--line);border-radius:15px;padding:22px;box-shadow:0 3px 12px rgba(16,47,90,.035)}
.al-filters{display:grid;grid-template-columns:minmax(180px,1.6fr) minmax(130px,1fr) minmax(130px,.8fr) minmax(130px,.8fr) auto auto;gap:10px;align-items:end;padding:16px;background:var(--surface-soft);border:1px solid var(--line);border-radius:12px;margin-bottom:18px}
.al-filters label{display:grid;gap:6px;min-width:0;font-size:12px;font-weight:800;color:var(--text)}
.al-filters input,.al-filters select{width:100%;min-width:0;min-height:42px;padding:9px 11px;border:1px solid var(--line);border-radius:9px;background:var(--surface);color:var(--text);font:600 13px var(--font)}
.al-filters input:focus-visible,.al-filters select:focus-visible{outline:2px solid var(--gold);outline-offset:1px}
.al-btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:42px;padding:9px 15px;border-radius:9px;border:1px solid var(--line);background:var(--surface);color:var(--text);font:800 13px var(--font);text-decoration:none;cursor:pointer;white-space:nowrap}
.al-btn:hover{border-color:var(--gold)}
.al-btn.primary{background:var(--blue);border-color:var(--blue);color:#fff}
.al-btn.primary:hover{background:var(--navy)}
.al-btn.disabled{opacity:.45;pointer-events:none}
.al-wrap{overflow-x:auto;border:1px solid var(--line);border-radius:12px}
.al-table{width:100%;min-width:860px;border-collapse:collapse;font-size:12.5px;text-align:left}
.al-table thead{background:var(--surface-soft);color:var(--muted)}
.al-table th{padding:13px 12px;font-size:11px;letter-spacing:.05em;text-transform:uppercase;white-space:nowrap}
.al-table td{padding:13px 12px;border-top:1px solid var(--line);vertical-align:top;color:var(--text)}
.al-table tbody tr:hover{background:var(--surface-soft)}
.al-when{white-space:nowrap}
.al-when small{display:block;color:var(--muted);font-size:11px;margin-top:2px}
.al-details{max-width:380px;overflow-wrap:anywhere;color:var(--muted);font-weight:600}
.al-module{display:inline-block;padding:3px 9px;border-radius:7px;background:var(--surface-soft);border:1px solid var(--line);font-size:11px;font-weight:800;text-transform:capitalize}
.al-badge{display:inline-block;padding:4px 9px;border-radius:99px;font-size:11px;font-weight:800;white-space:nowrap}
.al-badge.info{background:#e8f1fd;color:#265e9d}
.al-badge.good{background:#dff6e8;color:#176d46}
.al-badge.warn{background:#fff0d4;color:#855f1a}
.al-badge.bad{background:#feecec;color:#a53131}
:root[data-theme="dark"] .al-badge.info{background:#1d4163;color:#c8e4ff}
:root[data-theme="dark"] .al-badge.good{background:#19442d;color:#b7ebc8}
:root[data-theme="dark"] .al-badge.warn{background:#564123;color:#ffe5b0}
:root[data-theme="dark"] .al-badge.bad{background:#5d2937;color:#ffe3e7}
.al-empty{text-align:center;padding:42px 16px;color:var(--muted)}
.al-empty i{font-size:28px}
.al-empty h4{margin:8px 0 4px;color:var(--text)}
.al-alert{padding:13px 15px;border-radius:10px;background:#fff0ef;border:1px solid #efc3c0;color:#8c3430;font-size:13px;font-weight:700}
.al-pager{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-top:16px;color:var(--muted);font-size:12px;font-weight:700}
.al-pager-links{display:flex;gap:8px}
@media(max-width:1100px){.al-filters{grid-template-columns:1fr 1fr}.al-filters label:first-child{grid-column:1/-1}}
@media(max-width:580px){.audit-page{padding:17px 12px 35px}.al-panel{padding:14px}.al-filters{grid-template-columns:1fr}}
</style>
HTML;

nexShellStart($user, 'Audit Log', 'settings.php', $csrf, ['mainClass' => 'audit-page', 'head' => $pageHead]);
?>
<div class="al-heading">
    <div>
        <p class="al-eyebrow">SYSTEM / AUDIT LOG</p>
        <h2>Audit Log</h2>
        <p>A record of who did what in NEXSUS, including backup actions. Newest entries first.</p>
    </div>
    <div class="al-head-actions">
        <span class="al-count"><?= number_format($total) ?> entr<?= $total === 1 ? 'y' : 'ies' ?></span>
        <a class="al-btn" href="settings.php"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Settings</a>
    </div>
</div>

<section class="al-panel" aria-label="Audit log entries">
<?php if ($error): ?>
    <div class="al-alert" role="alert"><?= al_e($error) ?></div>
<?php else: ?>
    <form class="al-filters" method="get" action="auditLog.php" id="alFilters">
        <label><span>Search</span><input type="search" name="q" value="<?= al_e($q) ?>" placeholder="Person, action, module or details" maxlength="100"></label>
        <label><span>Module</span>
            <select name="module" data-autosubmit>
                <option value="">All modules</option>
                <?php foreach ($modules as $m): ?>
                    <option value="<?= al_e($m) ?>" <?= $module === $m ? 'selected' : '' ?>><?= al_e(ucfirst((string)$m)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label><span>From</span><input type="date" name="from" value="<?= al_e($from) ?>" data-autosubmit></label>
        <label><span>To</span><input type="date" name="to" value="<?= al_e($to) ?>" data-autosubmit></label>
        <button type="submit" class="al-btn primary"><i class="bi bi-funnel" aria-hidden="true"></i> Apply</button>
        <a class="al-btn" href="auditLog.php">Clear</a>
    </form>

<?php if (!$rows): ?>
    <div class="al-empty"><i class="bi bi-shield-check" aria-hidden="true"></i><h4>No entries found</h4><p>Try clearing the filters.</p></div>
<?php else: ?>
    <div class="al-wrap">
        <table class="al-table">
            <thead><tr><th scope="col">When</th><th scope="col">Who</th><th scope="col">Action</th><th scope="col">Module</th><th scope="col">Record</th><th scope="col">Details</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): $ts = $r['at'] ? strtotime((string)$r['at']) : false; ?>
                <tr>
                    <td class="al-when"><?= $ts ? al_e(date('d M Y', $ts)) . '<small>' . al_e(date('H:i:s', $ts)) . '</small>' : '-' ?></td>
                    <td><?= al_e($r['who'] ?? 'System') ?></td>
                    <td><span class="al-badge <?= al_badge((string)$r['action_key']) ?>"><?= al_e($r['action_key']) ?></span></td>
                    <td><span class="al-module"><?= al_e($r['module_name']) ?></span></td>
                    <td><?= $r['record_id'] !== null && $r['record_id'] !== '' ? '#' . al_e($r['record_id']) : '-' ?></td>
                    <td class="al-details"><?= al_e($r['details'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="al-pager">
        <span>Page <?= $page ?> of <?= $pages ?> &middot; showing <?= count($rows) ?> of <?= number_format($total) ?></span>
        <span class="al-pager-links">
            <a class="al-btn <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= al_e(al_link(['page' => max(1, $page - 1)])) ?>"><i class="bi bi-chevron-left" aria-hidden="true"></i> Previous</a>
            <a class="al-btn <?= $page >= $pages ? 'disabled' : '' ?>" href="<?= al_e(al_link(['page' => min($pages, $page + 1)])) ?>">Next <i class="bi bi-chevron-right" aria-hidden="true"></i></a>
        </span>
    </div>
<?php endif; ?>
<?php endif; ?>
</section>

<script>
// Small convenience: submit the filter form as soon as a dropdown or date changes.
document.querySelectorAll('#alFilters [data-autosubmit]').forEach(function (el) {
    el.addEventListener('change', function () { el.form.submit(); });
});
</script>
<?php nexShellEnd(); ?>