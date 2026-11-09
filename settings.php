<?php
declare(strict_types=1);

/**
 * NEXSUS Settings  (simple version)
 *
 * WHAT THIS PAGE DOES
 *   Everyone signed in : sees their profile, a Sign Out button and their recent Audit Log activity.
 *   Owner only         : sees "Backup & Recovery" with a "Back up now" button.
 *
 * HOW THE BACKUP WORKS (plain and simple)
 *   1. The owner presses "Back up now".            (NOTHING runs automatically - opening the page does nothing)
 *   2. PHP reads every table in the NEXSUS database and writes it into ONE .sql file.
 *   3. The file name carries the date and time:    nexsus_backup_2026-11-04_14-30-00.sql
 *   4. The file is saved in a normal folder:       C:\xampp\nexsus_backups   (outside htdocs = not reachable from a browser)
 *   5. The action is written to the audit_logs table so it shows in the Audit Log.
 *
 * WHAT YOU NEED
 *   - NO extra database, NO extra tables, NO SQL to run, NO PHP extensions to install.
 *   - Files used that already exist in the project: config.php, authorization.php, layout.css, layout.js, logout.php
 *   - Put this file in the same folder as login.php / ownerDashboard.php.
 *
 * RESTORE
 *   phpMyAdmin > click your database > Import > choose the .sql file > Go.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/authorization.php';

date_default_timezone_set('Africa/Johannesburg');   // so backup names and times use South African time

// Where backups are saved. Default: C:\xampp\nexsus_backups  (two folders above this file's folder).
// To change it, add  const BACKUP_DIR = 'D:/my_backups';  to config.php.
if (!defined('BACKUP_DIR')) define('BACKUP_DIR', dirname(__DIR__, 2) . '/nexsus_backups');

// ============================================================================
// SECTION 1 - small helper functions
// ============================================================================

/** Make text safe to print inside HTML. */
function st_h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/** 1536 -> "1.5 KB" */
function st_bytes($n): string {
    $n = (float)$n;
    foreach (['B', 'KB', 'MB', 'GB'] as $u) {
        if ($n < 1024 || $u === 'GB') return ($u === 'B' ? (string)(int)$n : number_format($n, 1)) . ' ' . $u;
        $n /= 1024;
    }
    return '';
}

/** Date text from the database -> "04 Nov 2026, 14:30:00" */
function st_fmt($d): string { return $d ? date('d M Y, H:i:s', (int)strtotime((string)$d)) : '-'; }

/** Put backticks around a table/column name so odd names cannot break the SQL. */
function st_q(string $id): string { return '`' . str_replace('`', '``', $id) . '`'; }

/** Run a SELECT with safe parameters and return all rows. */
function st_rows(mysqli $c, string $sql, array $params = []): array {
    $s = $c->prepare($sql);
    if ($params) $s->execute($params); else $s->execute();
    $rows = $s->get_result()->fetch_all(MYSQLI_ASSOC);
    $s->close();
    return $rows;
}

/** List of column names in a table. */
function st_cols(mysqli $c, string $table): array {
    $rows = st_rows($c, 'SELECT COLUMN_NAME AS col_name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$table]);
    return array_column($rows, 'col_name');
}

/** First column name from $candidates that really exists. */
function st_pick(array $have, array $candidates): ?string {
    foreach ($candidates as $c) if (in_array($c, $have, true)) return $c;
    return null;
}

/**
 * Write one line into the audit_logs table (same 5 columns the other modules use).
 * Auditing must never crash the page, so any error is only written to the PHP error log.
 */
function st_audit(mysqli $c, int $actor, string $action, int $recordId, string $details): void {
    try {
        $details = function_exists('mb_substr') ? mb_substr($details, 0, 480, 'UTF-8') : substr($details, 0, 480);
        $module = 'settings';
        $s = $c->prepare('INSERT INTO audit_logs (actor_user_id, action_key, module_name, record_id, details) VALUES (?, ?, ?, ?, ?)');
        $s->bind_param('issis', $actor, $action, $module, $recordId, $details);
        $s->execute();
        $s->close();
    } catch (Throwable $e) {
        error_log('NEXSUS settings audit: ' . $e->getMessage());
    }
}

// ============================================================================
// SECTION 2 - backup functions
// ============================================================================

/** Open the backup folder (create it the first time) and return its path. */
function st_backup_dir(): string {
    $d = rtrim(str_replace('\\', '/', BACKUP_DIR), '/');
    if (!is_dir($d) && !@mkdir($d, 0770, true) && !is_dir($d)) throw new RuntimeException('Cannot create the backup folder: ' . $d);
    if (!is_writable($d)) throw new RuntimeException('The backup folder is not writable: ' . $d);
    // Extra protection: if the folder is ever placed inside htdocs, Apache will refuse to serve it.
    if (!is_file($d . '/.htaccess')) @file_put_contents($d . '/.htaccess', "Require all denied\nDeny from all\n");
    return $d;
}

/** A backup file name must look exactly like nexsus_backup_YYYY-MM-DD_HH-MM-SS.sql (blocks path tricks like ../). */
function st_valid_name(string $name): bool {
    return (bool)preg_match('/^nexsus_backup_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.sql$/', $name);
}

/** All backup files in the folder, newest first. */
function st_backup_list(string $dir): array {
    $list = [];
    foreach (glob($dir . '/nexsus_backup_*.sql') ?: [] as $f) {
        $name = basename($f);
        if (!st_valid_name($name)) continue;
        $list[] = ['name' => $name, 'size' => (int)filesize($f), 'time' => (int)filemtime($f)];
    }
    usort($list, fn($a, $b) => strcmp($b['name'], $a['name']));   // names start with the date, so this sorts newest first
    return $list;
}

/** A separate connection to the live database, used only for the backup. */
function st_main(): mysqli {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $c = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
    $c->set_charset('utf8mb4');
    $c->query("SET time_zone = '+02:00'");
    return $c;
}

/**
 * Write the whole database into a .sql file.
 * Every table is found automatically, so tables added later by other modules are included too.
 * Returns ['tables' => how many tables, 'rows' => how many records].
 */
function st_dump(mysqli $c, string $path, string $label, string $when): array {
    $out = fopen($path, 'wb');
    if (!$out) throw new RuntimeException('Cannot create the backup file.');

    fwrite($out, "-- NEXSUS Business Solutions - database backup\n-- Label: $label\n-- Created: $when (Africa/Johannesburg)\n\n");
    // No CREATE DATABASE / USE lines on purpose: importing restores into whichever database you select in phpMyAdmin.
    fwrite($out, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

    // Split the list into real tables and views.
    $tables = []; $views = [];
    $res = $c->query('SHOW FULL TABLES');
    while ($r = $res->fetch_row()) {
        if (strtoupper((string)$r[1]) === 'VIEW') $views[] = (string)$r[0]; else $tables[] = (string)$r[0];
    }
    $res->free();

    // One-time codes and reset tokens are short-lived secrets: keep the table structure, skip the rows.
    $structureOnly = ['otp_verifications', 'password_resets'];
    $totalRows = 0;

    foreach ($tables as $t) {
        $qt = st_q($t);

        // 1) the table structure
        $res = $c->query('SHOW CREATE TABLE ' . $qt); $create = $res->fetch_row(); $res->free();
        fwrite($out, "-- ----------------------------\n-- Table $t\n-- ----------------------------\nDROP TABLE IF EXISTS $qt;\n" . $create[1] . ";\n\n");

        // 2) which columns to save (MySQL-generated columns cannot be inserted, so they are skipped)
        $cols = []; $binary = [];
        $info = st_rows($c, 'SELECT COLUMN_NAME AS col_name, DATA_TYPE AS data_type, EXTRA AS extra_info FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$t]);
        foreach ($info as $col) {
            $extra = strtoupper((string)$col['extra_info']);
            if (str_contains($extra, 'GENERATED') && !str_contains($extra, 'DEFAULT_GENERATED')) continue;
            if (in_array(strtolower((string)$col['data_type']), ['tinyblob', 'blob', 'mediumblob', 'longblob', 'binary', 'varbinary', 'bit'], true)) $binary[count($cols)] = true;
            $cols[] = (string)$col['col_name'];
        }

        // 3) the data, written in batches of 200 rows so big tables do not use much memory
        if ($cols && !in_array($t, $structureOnly, true)) {
            $colList = implode(',', array_map('st_q', $cols));
            $res = $c->query("SELECT $colList FROM $qt", MYSQLI_USE_RESULT);
            $batch = [];
            while ($row = $res->fetch_row()) {
                $vals = [];
                foreach ($row as $i => $v) {
                    if ($v === null)              $vals[] = 'NULL';
                    elseif (isset($binary[$i]))   $vals[] = ((string)$v === '') ? "''" : '0x' . bin2hex((string)$v);
                    else                          $vals[] = "'" . $c->real_escape_string((string)$v) . "'";
                }
                $batch[] = '(' . implode(',', $vals) . ')';
                $totalRows++;
                if (count($batch) >= 200) {
                    fwrite($out, "INSERT INTO $qt ($colList) VALUES\n" . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }
            $res->free();
            if ($batch) fwrite($out, "INSERT INTO $qt ($colList) VALUES\n" . implode(",\n", $batch) . ";\n");
            fwrite($out, "\n");
        }
    }

    foreach ($views as $v) {
        $res = $c->query('SHOW CREATE VIEW ' . st_q($v)); $create = $res->fetch_row(); $res->free();
        fwrite($out, 'DROP VIEW IF EXISTS ' . st_q($v) . ";\n" . $create[1] . ";\n\n");
    }

    fwrite($out, "SET FOREIGN_KEY_CHECKS=1;\nSET UNIQUE_CHECKS=1;\n-- End of backup $label\n");
    fclose($out);
    return ['tables' => count($tables), 'rows' => $totalRows];
}

/**
 * Create ONE backup file now. Returns [success?, message, file name].
 * The file is first written as ".tmp" and renamed only when finished,
 * so a half-finished backup can never be mistaken for a good one.
 */
function st_backup_now(string $dir): array {
    @set_time_limit(0);
    @ignore_user_abort(true);

    $when  = date('Y-m-d H:i:s');
    $label = 'nexsus_backup_' . date('Y-m-d_H-i-s');       // the date/time stamp is part of the file name
    $name  = $label . '.sql';
    $final = $dir . '/' . $name;
    $tmp   = $final . '.tmp';
    $c = null;

    try {
        $c = st_main();
        // Freeze the database at this moment so every table in the file belongs to the same point in time.
        $c->query('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        $info = st_dump($c, $tmp, $label, $when);
        $c->commit();
        $c->close();

        if (!rename($tmp, $final)) throw new RuntimeException('Could not save the finished backup file.');

        $msg = "Backup $name created: {$info['tables']} tables, {$info['rows']} records, " . st_bytes(filesize($final)) . '.';
        return [true, $msg, $name];
    } catch (Throwable $e) {
        error_log('NEXSUS backup failed: ' . $e->getMessage());
        @unlink($tmp);                                      // never leave a broken file behind
        try { if ($c) $c->close(); } catch (Throwable $x) {}
        return [false, 'The backup failed. Check the PHP error log for details.', ''];
    }
}

// ============================================================================
// SECTION 3 - page logic
// ============================================================================

$user    = require_logged_in();                 // must be signed in (owner or employee)
$userId  = (int)$user['user_id'];
$isOwner = $user['role'] === 'owner';
$csrf    = csrf_token();

$profile = st_rows($conn, 'SELECT * FROM users WHERE user_id = ? LIMIT 1', [$userId])[0] ?? [];
unset($profile['password_hash']);                // never send the password hash to the page

// Open the backup folder (owner only).
$bkDir = null; $bkError = null;
if ($isOwner) {
    try { $bkDir = st_backup_dir(); }
    catch (Throwable $e) {
        error_log('NEXSUS backup folder: ' . $e->getMessage());
        $bkError = 'The backup folder could not be opened or written (' . BACKUP_DIR . '). Make sure it exists and the web server may write to it.';
    }
}

// ---- Download a backup file (owner only, GET) ------------------------------
if (isset($_GET['dl'])) {
    $name = (string)$_GET['dl'];
    if (!$isOwner || !$bkDir)  { http_response_code(403); exit('Backups are available to the owner only.'); }
    if (!st_valid_name($name)) { http_response_code(400); exit('Bad request.'); }
    $file = $bkDir . '/' . $name;
    if (!is_file($file))       { http_response_code(404); exit('Backup not found.'); }

    st_audit($conn, $userId, 'backup.downloaded', $userId, "Downloaded backup $name");
    session_write_close();
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
}

// ---- Button presses (POST) -------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) { http_response_code(403); exit('Invalid security token. Reload the page.'); }
    if (!$isOwner || !$bkDir) { http_response_code(403); exit('Backups are available to the owner only.'); }
    $action = post_string('action');

    if ($action === 'run_now') {
        [$ok, $msg, $name] = st_backup_now($bkDir);
        if ($ok) st_audit($conn, $userId, 'backup.created', $userId, $msg);
        else     st_audit($conn, $userId, 'backup.failed', $userId, 'Manual backup failed.');
        flash($ok ? 'success' : 'error', $msg);

    } elseif ($action === 'delete_backup') {
        $name = post_string('file');
        if (st_valid_name($name) && is_file($bkDir . '/' . $name) && @unlink($bkDir . '/' . $name)) {
            st_audit($conn, $userId, 'backup.deleted', $userId, "Deleted backup $name");
            flash('success', 'Backup deleted.');
        } else {
            flash('error', 'Backup not found.');
        }
    }
    redirect_to('settings.php#backup');
}

// ---- Data for the page -----------------------------------------------------
$notice  = get_flash();
$backups = $bkDir ? st_backup_list($bkDir) : [];
$totalBytes = array_sum(array_column($backups, 'size'));

// Audit log panel: the owner sees everyone's latest 10 actions, an employee sees only their own latest 8.
$auditRows = []; $auditNote = null;
try {
    $ac = st_cols($conn, 'audit_logs');
    if (count(array_diff(['actor_user_id', 'action_key', 'module_name'], $ac)) === 0) {
        $time   = st_pick($ac, ['created_at', 'logged_at', 'action_at', 'timestamp']);
        $detail = in_array('details', $ac, true) ? 'a.details' : 'NULL';
        $sql = 'SELECT ' . ($time ? 'a.' . st_q($time) : 'NULL') . " AS at, u.full_name AS who, a.action_key, a.module_name, $detail AS details FROM audit_logs a LEFT JOIN users u ON u.user_id = a.actor_user_id";
        $params = [];
        if (!$isOwner) { $sql .= ' WHERE a.actor_user_id = ?'; $params[] = $userId; }
        $sql .= ' ORDER BY ' . ($time ? 'a.' . st_q($time) : 'a.' . st_q($ac[0])) . ' DESC LIMIT ' . ($isOwner ? 10 : 8);
        $auditRows = st_rows($conn, $sql, $params);
    } else {
        $auditNote = 'The audit log table is not set up yet (it belongs to the Audit Log module).';
    }
} catch (Throwable $e) {
    error_log('NEXSUS settings audit read: ' . $e->getMessage());
    $auditNote = 'The audit log could not be read.';
}

$fullName = (string)($profile['full_name'] ?? $user['full_name']);
$initials = strtoupper(substr(trim($fullName), 0, 1) . (str_contains(trim($fullName), ' ') ? substr(strrchr(trim($fullName), ' '), 1, 1) : ''));
$dash     = $isOwner ? 'ownerDashboard.php' : 'employeeDashboard.php';   // the logo goes to the right dashboard
?>
<!doctype html>
<html lang="en" data-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#102f5a">
  <title>Settings | NEXSUS Business Solutions</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.2/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="layout.css">
  <script src="layout.js" defer></script>
  <style>
    .set-page{padding:28px clamp(18px,3vw,38px) 50px;max-width:1180px}
    .set-card{background:var(--surface);border:1px solid var(--line);border-radius:15px;padding:22px;margin-bottom:22px;box-shadow:0 2px 10px rgba(16,47,90,.035)}
    .set-card h3{margin:0 0 4px;font-size:18px;font-weight:800;color:var(--text)}
    .set-sub{margin:0 0 16px;color:var(--muted);font-size:12px;font-weight:600}
    .set-top{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap}
    .set-id{display:flex;align-items:center;gap:16px}
    .set-avatar{display:grid;place-items:center;width:68px;height:68px;border-radius:50%;color:#fff;font-size:22px;font-weight:800;background:linear-gradient(145deg,#286eaa,#143c6c);box-shadow:0 0 0 3px var(--surface),0 0 0 4px var(--line)}
    .set-id h3{margin:0}
    .set-pill{display:inline-block;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:800;background:var(--gold-soft);color:#8c6b28}
    .set-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px;margin-top:18px}
    .set-kv{border:1px solid var(--line);border-radius:12px;padding:12px 14px;background:var(--surface-soft)}
    .set-kv span{display:block;color:var(--muted);font-size:11px;font-weight:800;letter-spacing:.05em;text-transform:uppercase}
    .set-kv strong{display:block;margin-top:3px;font-size:14px;color:var(--text);word-break:break-word}
    .set-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:40px;padding:8px 15px;border-radius:10px;border:1px solid var(--line);background:var(--surface);color:var(--text);font-size:13px;font-weight:800;cursor:pointer;text-decoration:none}
    .set-btn:hover{background:var(--surface-soft);border-color:var(--gold)}
    .set-btn.primary{background:var(--blue);border-color:var(--blue);color:#fff}.set-btn.primary:hover{background:#164b7e}
    .set-btn.danger{color:#a13a3a}.set-btn.small{min-height:32px;padding:4px 10px;font-size:12px}
    .set-btn:disabled{opacity:.6;cursor:wait}
    .set-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
    .set-wrap{overflow-x:auto}
    .set-table{width:100%;border-collapse:collapse;font-size:13px}
    .set-table th,.set-table td{padding:10px 10px;border-bottom:1px solid var(--line);text-align:left;vertical-align:middle;color:var(--text)}
    .set-table th{color:var(--muted);font-size:11px;letter-spacing:.06em;text-transform:uppercase}
    .set-note{color:var(--muted);font-size:12px;font-weight:600;line-height:1.6}
    .set-note code{background:var(--surface-soft);padding:1px 6px;border-radius:6px}
    details.set-more{margin-top:12px;border-top:1px solid var(--line);padding-top:12px}
    details.set-more summary{cursor:pointer;font-weight:800;font-size:13px;color:var(--blue)}
    .signout-form{margin:0}
    .notice.success,.notice.error{margin-bottom:20px}
  </style>
</head>
<body>
<div class="app-shell">
  <div class="sidebar-backdrop" id="sidebarBackdrop" hidden></div>
  <aside class="sidebar" id="dashboardSidebar" aria-label="Main navigation">
    <a class="sidebar-brand" href="<?= st_h($dash) ?>" title="NEXSUS Dashboard"><span class="sidebar-logo" aria-hidden="true">N</span><span class="sidebar-brand-copy"><strong>NEXSUS</strong><small>BUSINESS SOLUTIONS</small></span></a>
    <nav class="sidebar-nav" aria-label="<?= $isOwner ? 'Owner' : 'Employee' ?> navigation">
<?php /* Copy of the existing owner / employee menus. Update here if the main menu changes. */ ?>
<?php if ($isOwner): ?>
      <div class="nav-group"><p class="nav-heading">OVERVIEW</p><a class="nav-link" href="ownerDashboard.php" title="Dashboard"><i class="bi bi-grid-1x2" aria-hidden="true"></i><span>Dashboard</span></a></div>
      <div class="nav-group"><p class="nav-heading">DAILY OPERATIONS</p>
        <a class="nav-link" href="serviceRequests.php" title="Service Requests"><i class="bi bi-kanban" aria-hidden="true"></i><span>Service Requests</span></a>
        <a class="nav-link" href="tasks.php" title="Tasks"><i class="bi bi-check2-square" aria-hidden="true"></i><span>Tasks</span></a>
        <a class="nav-link" href="clients.php" title="Clients"><i class="bi bi-person-vcard" aria-hidden="true"></i><span>Clients</span></a></div>
      <div class="nav-group"><p class="nav-heading">MANAGEMENT</p>
        <a class="nav-link" href="services.php" title="Services"><i class="bi bi-briefcase" aria-hidden="true"></i><span>Services</span></a>
        <a class="nav-link" href="documents.php" title="Documents"><i class="bi bi-folder2-open" aria-hidden="true"></i><span>Documents</span></a>
        <a class="nav-link" href="employee.php" title="Employees"><i class="bi bi-people" aria-hidden="true"></i><span>Employees</span></a></div>
      <div class="nav-group"><p class="nav-heading">MONITORING</p>
        <a class="nav-link" href="reports.php" title="Reports"><i class="bi bi-bar-chart-line" aria-hidden="true"></i><span>Reports</span></a>
        <a class="nav-link" href="contactRequests.php" title="Contact Requests"><i class="bi bi-envelope-paper" aria-hidden="true"></i><span>Contact Requests</span></a>
        <a class="nav-link" href="notifications.php" title="Notifications"><i class="bi bi-bell" aria-hidden="true"></i><span>Notifications</span></a></div>
      <div class="nav-group"><p class="nav-heading">SYSTEM</p>
        <a class="nav-link" href="auditLog.php" title="Audit Log"><i class="bi bi-shield-check" aria-hidden="true"></i><span>Audit Log</span></a>
        <a class="nav-link is-active" href="settings.php" aria-current="page" title="Settings"><i class="bi bi-gear" aria-hidden="true"></i><span>Settings</span></a></div>
<?php else: ?>
      <div class="nav-group"><p class="nav-heading">MY WORKSPACE</p>
        <a class="nav-link" href="employeeDashboard.php" title="Dashboard"><i class="bi bi-grid-1x2" aria-hidden="true"></i><span>Dashboard</span></a>
        <a class="nav-link" href="myTasks.php" title="My Tasks"><i class="bi bi-check2-square" aria-hidden="true"></i><span>My Tasks</span></a></div>
      <div class="nav-group"><p class="nav-heading">ACCOUNT</p>
        <a class="nav-link is-active" href="settings.php" aria-current="page" title="Settings"><i class="bi bi-gear" aria-hidden="true"></i><span>Settings</span></a></div>
<?php endif; ?>
    </nav>
  </aside>

  <div class="workspace">
    <header class="topbar">
      <div class="topbar-left">
        <button class="icon-button sidebar-toggle" type="button" id="sidebarToggle" aria-label="Toggle sidebar" aria-controls="dashboardSidebar" aria-expanded="true"><i class="bi bi-list" aria-hidden="true"></i></button>
        <h1 class="topbar-title">Settings</h1>
      </div>
      <div class="topbar-right">
        <label class="search-box" for="navigationSearch"><i class="bi bi-search" aria-hidden="true"></i><input id="navigationSearch" type="search" placeholder="Find a page..." autocomplete="off"></label>
        <div class="theme-switch-wrap"><input class="visually-hidden" type="checkbox" id="switch-mode" role="switch" aria-label="Enable dark mode"><label class="switch-mode" for="switch-mode" title="Toggle light / dark mode"></label></div>
        <details class="dropdown notification-dropdown"><summary class="icon-button" aria-label="Notifications" title="Notifications"><i class="bi bi-bell" aria-hidden="true"></i></summary><div class="dropdown-panel" role="group" aria-label="Notifications panel"><strong>Notifications</strong><p>Notifications will appear here when that module is connected.</p></div></details>
        <details class="dropdown profile-dropdown"><summary class="owner-profile" aria-label="Profile menu"><span class="owner-avatar" aria-hidden="true"><?= st_h(substr($initials, 0, 2)) ?></span><span class="owner-copy"><strong><?= st_h($fullName) ?></strong><small><?= $isOwner ? 'Business owner' : 'Employee' ?></small></span><i class="bi bi-chevron-down" aria-hidden="true"></i></summary>
          <div class="dropdown-panel profile-panel"><a href="settings.php"><i class="bi bi-gear" aria-hidden="true"></i> Settings</a>
            <form action="logout.php" method="post" class="signout-form"><input type="hidden" name="csrf_token" value="<?= st_h($csrf) ?>"><button class="secondary-button" type="submit"><i class="bi bi-box-arrow-right"></i> Sign Out</button></form></div></details>
      </div>
    </header>

    <main id="pageContent" class="page-content set-page">
      <div class="employee-heading"><div><p class="eyebrow">SYSTEM / SETTINGS</p><h2>Settings</h2><p>Your profile, sign-in and <?= $isOwner ? 'system backups.' : 'recent activity.' ?></p></div></div>
      <?php if ($notice): ?><div class="notice <?= $notice['type'] === 'success' ? 'success' : 'error' ?>" role="status"><?= st_h($notice['message']) ?></div><?php endif; ?>

      <!-- PROFILE + SIGN OUT: visible to everyone -->
      <section class="set-card" aria-labelledby="profileTitle">
        <div class="set-top">
          <div class="set-id"><span class="set-avatar" aria-hidden="true"><?= st_h(substr($initials, 0, 2)) ?></span>
            <div><h3 id="profileTitle"><?= st_h($fullName) ?></h3><span class="set-pill"><?= $isOwner ? 'Business owner' : 'Employee' ?></span></div></div>
          <form action="logout.php" method="post" class="signout-form"><input type="hidden" name="csrf_token" value="<?= st_h($csrf) ?>"><button class="set-btn primary" type="submit"><i class="bi bi-box-arrow-right" aria-hidden="true"></i> Sign Out</button></form>
        </div>
        <div class="set-grid">
          <div class="set-kv"><span>Username</span><strong><?= st_h($profile['username'] ?? '') ?></strong></div>
          <div class="set-kv"><span>Email</span><strong><?= st_h($profile['email'] ?? '') ?></strong></div>
          <div class="set-kv"><span>Account status</span><strong><?= st_h(ucfirst((string)($profile['account_status'] ?? 'active'))) ?></strong></div>
<?php if (!empty($profile['phone_number'])): ?>
          <div class="set-kv"><span>Phone</span><strong><?= st_h($profile['phone_number']) ?></strong></div>
<?php endif; ?>
          <div class="set-kv"><span>Last sign-in</span><strong><?= st_h(st_fmt($profile['last_login_at'] ?? null)) ?></strong></div>
          <div class="set-kv"><span>Member since</span><strong><?= st_h(st_fmt($profile['created_at'] ?? null)) ?></strong></div>
          <div class="set-kv"><span>Privacy notice accepted</span><strong><?= st_h(st_fmt($profile['privacy_accepted_at'] ?? null)) ?></strong></div>
        </div>
      </section>

      <!-- AUDIT LOG PANEL: owner = everyone's latest actions, employee = own actions only -->
      <section class="set-card" aria-labelledby="auditTitle">
        <div class="set-top"><div><h3 id="auditTitle">Audit Log</h3><p class="set-sub"><?= $isOwner ? 'The latest actions across NEXSUS, including every backup action.' : 'Your latest recorded actions.' ?></p></div>
          <?php if ($isOwner): ?><a class="set-btn" href="auditLog.php"><i class="bi bi-shield-check" aria-hidden="true"></i> Open full Audit Log</a><?php endif; ?></div>
        <?php if ($auditNote): ?><p class="set-note"><?= st_h($auditNote) ?></p>
        <?php elseif (!$auditRows): ?><p class="set-note">No actions recorded yet.</p>
        <?php else: ?>
        <div class="set-wrap"><table class="set-table"><thead><tr><th>When</th><?php if ($isOwner): ?><th>Who</th><?php endif; ?><th>Action</th><th>Module</th><th>Details</th></tr></thead><tbody>
          <?php foreach ($auditRows as $a): ?><tr><td><?= st_h(st_fmt($a['at'])) ?></td><?php if ($isOwner): ?><td><?= st_h($a['who'] ?? 'System') ?></td><?php endif; ?><td><?= st_h($a['action_key']) ?></td><td><?= st_h($a['module_name']) ?></td><td><?= st_h($a['details']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
      </section>

<?php if ($isOwner): ?>
      <!-- BACKUP & RECOVERY: owner only. Nothing here runs by itself - only the button creates a backup. -->
      <section class="set-card" id="backup" aria-labelledby="backupTitle">
        <div class="set-top"><div><h3 id="backupTitle"><i class="bi bi-database-lock" aria-hidden="true"></i> Backup &amp; Recovery</h3>
          <p class="set-sub">Each backup is one .sql file with the date and time in its name. Owner only.</p></div></div>

<?php if ($bkError): ?>
        <div class="notice error"><?= st_h($bkError) ?></div>
<?php else: ?>
        <div class="set-grid" style="margin-top:0">
          <div class="set-kv"><span>Last backup</span><strong><?= $backups ? st_h(date('d M Y, H:i:s', $backups[0]['time'])) : 'None yet' ?></strong></div>
          <div class="set-kv"><span>Backups stored</span><strong><?= count($backups) ?> (<?= st_h(st_bytes($totalBytes)) ?>)</strong></div>
          <div class="set-kv"><span>Saved in folder</span><strong><?= st_h(BACKUP_DIR) ?></strong></div>
        </div>

        <div class="set-actions" style="margin-top:18px">
          <form method="post" action="settings.php#backup" id="runForm"><input type="hidden" name="csrf_token" value="<?= st_h($csrf) ?>"><input type="hidden" name="action" value="run_now"><button class="set-btn primary" type="submit" id="runBtn"><i class="bi bi-cloud-arrow-up" aria-hidden="true"></i> Back up now</button></form>
          <span class="set-note">Saves every table and record in the database with today's date and time.</span>
        </div>

        <h3 style="margin-top:26px">Backup history</h3>
<?php if (!$backups): ?>
        <p class="set-note">No backups yet. Press <strong>Back up now</strong>.</p>
<?php else: ?>
        <div class="set-wrap"><table class="set-table"><thead><tr><th>Date &amp; time stamp</th><th>File name</th><th>Size</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($backups as $b): ?>
          <tr><td><strong><?= st_h(date('d M Y, H:i:s', $b['time'])) ?></strong></td>
            <td><?= st_h($b['name']) ?></td>
            <td><?= st_h(st_bytes($b['size'])) ?></td>
            <td><div class="set-actions">
              <a class="set-btn small" href="settings.php?dl=<?= urlencode($b['name']) ?>"><i class="bi bi-download" aria-hidden="true"></i> Download</a>
              <form method="post" action="settings.php#backup" data-confirm="Delete this backup permanently?"><input type="hidden" name="csrf_token" value="<?= st_h($csrf) ?>"><input type="hidden" name="action" value="delete_backup"><input type="hidden" name="file" value="<?= st_h($b['name']) ?>"><button class="set-btn small danger" type="submit" aria-label="Delete backup"><i class="bi bi-trash" aria-hidden="true"></i></button></form>
            </div></td></tr>
<?php endforeach; ?>
        </tbody></table></div>
<?php endif; ?>

        <details class="set-more"><summary>How to restore a backup</summary>
          <p class="set-note">Open phpMyAdmin, click your NEXSUS database, choose <strong>Import</strong>, select the backup <code>.sql</code> file and press <strong>Go</strong>. Uploaded files (the <code>nexsus_private</code> folder) and the encryption key <code>employee_key.php</code> are not inside the .sql file - copy those folders separately and keep a private copy of the key.</p></details>
<?php endif; ?>
      </section>
<?php endif; ?>
    </main>
  </div>
</div>
<script>
(function () {
  // Stop double-clicks while a backup is running.
  var run = document.getElementById('runForm');
  if (run) run.addEventListener('submit', function () {
    var b = document.getElementById('runBtn'); b.disabled = true; b.textContent = 'Backing up... please wait';
  });
  // Ask "are you sure?" before deleting a backup.
  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) { if (!window.confirm(f.dataset.confirm)) e.preventDefault(); });
  });
})();
</script>
</body>
</html>