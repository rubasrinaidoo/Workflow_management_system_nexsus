<?php
declare(strict_types=1);
if (is_file(__DIR__ . '/notification_bell.php')) {
    require_once __DIR__ . '/notification_bell.php';
}

/** ONE place for both sidebars. Change the order here and every page follows. */
function nexNavItems(bool $isOwner): array {
    if ($isOwner) {
        return [
            ['OVERVIEW', [['Dashboard','ownerDashboard.php','bi-grid-1x2']]],
            ['CLIENT OPERATIONS', [
			    ['Clients','clients.php','bi-person-vcard'],
			    ['Service Requests','serviceRequests.php','bi-kanban'],
				['Tasks','tasks.php','bi-check2-square'],
				['Documents','documents.php','bi-folder2-open'],
            ]],
            ['BUSINESS SETUP', [
                ['Services','services.php','bi-briefcase'],
                ['Employees','employee.php','bi-people'],
            ]],
            ['MONITORING', [
                ['Reports','reports.php','bi-bar-chart-line'],
                ['Contact Requests','contactRequests.php','bi-envelope-paper'],
                ['Notifications','notifications.php','bi-bell'],
            ]],
            ['SYSTEM', [
               ['Settings','settings.php','bi-gear'],
            ]],
        ];
    }
    return [
        ['MY WORK', [
            ['Dashboard','employeeDashboard.php','bi-grid-1x2'],
            ['My Tasks','myTasks.php','bi-check2-square'],
            ['Service Requests','myServiceRequests.php','bi-kanban'],
        ]],
        ['CLIENTS', [
            ['My Clients','myClients.php','bi-person-vcard'],
            ['Documents','myDocuments.php','bi-folder2-open'],
        ]],
        ['ACCOUNT', [
            ['Notifications','notifications.php','bi-bell'],
            ['Settings','mySettings.php','bi-gear'],
        ]],
    ];
}

/** Adds ?v=<file change time> so browsers always load the latest layout.css / layout.js. */
function nexAssetVersion(string $file): string {
    $path = __DIR__ . '/' . $file;
    return is_file($path) ? (string)filemtime($path) : '1';
}

/**
 * $opt (all optional, developer-written strings only):
 *   mainClass : class on <main>, e.g. 'task-page'  (default 'nex-page')
 *   bodyAttr  : extra body attributes
 *   head      : extra <link>/<script> tags for this page only
 */
function nexShellStart(array $user, string $pageTitle, string $active, string $csrf, array $opt = []): void {
    global $conn;
    $isOwner  = ($user['role'] === 'owner');
    $home     = $isOwner ? 'ownerDashboard.php' : 'employeeDashboard.php';
    $settings = $isOwner ? 'settings.php' : 'mySettings.php';
    // An employee updating a request on serviceRequests.php still highlights "Service Requests".
    if (!$isOwner && $active === 'serviceRequests.php') $active = 'myServiceRequests.php';

    $name      = h((string)$user['full_name']);
    $initial   = h(strtoupper(substr(trim((string)$user['full_name']), 0, 1)) ?: 'U');
    $title     = h($pageTitle);
    $mainClass = (string)($opt['mainClass'] ?? 'nex-page');
    $bodyAttr  = (string)($opt['bodyAttr'] ?? '');
    $head      = (string)($opt['head'] ?? '');

    echo '<!doctype html><html lang="en" data-theme="light"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#102f5a">';
    echo '<title>'.$title.' | NEXSUS Business Solutions</title>';
    // Apply the saved dark theme immediately, so the page does not flash light first.
    echo '<script>try{if(localStorage.getItem("nexsus-theme")==="dark")document.documentElement.dataset.theme="dark"}catch(e){}</script>';
    // Shared assets live here only. Make them local later and every converted page is fixed at once.
    echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.2/font/bootstrap-icons.min.css">';
    echo '<link rel="stylesheet" href="layout.css?v='.nexAssetVersion('layout.css').'">';
    echo '<script src="layout.js?v='.nexAssetVersion('layout.js').'" defer></script>'.$head;

    echo '</head><body'.($bodyAttr !== '' ? ' '.$bodyAttr : '').'><div class="app-shell"><div class="sidebar-backdrop" id="sidebarBackdrop" hidden></div>';
    echo '<aside class="sidebar" id="dashboardSidebar" aria-label="Main navigation">';
    echo '<a class="sidebar-brand" href="'.$home.'" title="NEXSUS Dashboard"><span class="sidebar-logo" aria-hidden="true">N</span><span class="sidebar-brand-copy"><strong>NEXSUS</strong><small>BUSINESS SOLUTIONS</small></span></a>';
    echo '<nav class="sidebar-nav" aria-label="'.($isOwner ? 'Owner' : 'Employee').' navigation">';
    foreach (nexNavItems($isOwner) as [$heading, $links]) {
        echo '<div class="nav-group"><p class="nav-heading">'.h($heading).'</p>';
        foreach ($links as [$label, $url, $icon]) {
            $on = ($active === $url);
            echo '<a class="nav-link'.($on ? ' is-active' : '').'" href="'.h($url).'" title="'.h($label).'"'.($on ? ' aria-current="page"' : '').'><i class="bi '.h($icon).'" aria-hidden="true"></i><span>'.h($label).'</span></a>';
        }
        echo '</div>';
    }
    echo '</nav></aside><div class="workspace"><header class="topbar">';
    echo '<div class="topbar-left"><button class="icon-button sidebar-toggle" type="button" id="sidebarToggle" aria-label="Toggle sidebar" aria-controls="dashboardSidebar" aria-expanded="true"><i class="bi bi-list" aria-hidden="true"></i></button><h1 class="topbar-title">'.$title.'</h1></div>';
    echo '<div class="topbar-right"><label class="search-box" for="navigationSearch"><i class="bi bi-search" aria-hidden="true"></i><input id="navigationSearch" type="search" placeholder="Find a page..." autocomplete="off"></label>';
    echo '<div class="theme-switch-wrap"><input class="visually-hidden" type="checkbox" id="switch-mode" role="switch" aria-label="Enable dark mode"><label class="switch-mode" for="switch-mode" title="Toggle light / dark mode"></label></div>';
    // The bell is optional: if notification_bell.php is missing the page still loads.
    if (function_exists('nexsus_notification_bell')) {
        nexsus_notification_bell($conn, (int)$user['user_id']);
    }
    echo '<details class="dropdown profile-dropdown"><summary class="owner-profile" aria-label="Profile menu"><span class="owner-avatar" aria-hidden="true">'.$initial.'</span><span class="owner-copy"><strong>'.$name.'</strong><small>'.($isOwner ? 'Business owner' : 'Employee').'</small></span><i class="bi bi-chevron-down" aria-hidden="true"></i></summary><div class="dropdown-panel profile-panel">';
    echo '<a href="'.$settings.'"><i class="bi bi-gear" aria-hidden="true"></i> Settings</a>';
    echo '<form action="logout.php" method="post" class="signout-form"><input type="hidden" name="csrf_token" value="'.h($csrf).'"><button type="submit" class="secondary-button"><i class="bi bi-box-arrow-right" aria-hidden="true"></i> Sign Out</button></form>';
    echo '</div></details></div></header><main id="pageContent" class="page-content '.h($mainClass).'">';
}

function nexShellEnd(): void {
    echo '</main><footer class="app-footer"><small>&copy; '.date('Y').' NEXSUS Business Solutions</small></footer></div></div></body></html>';
}