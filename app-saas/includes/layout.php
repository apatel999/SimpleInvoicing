<?php

function nav_icon($name)
{
    $icons = array(
        'dashboard' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4.5 10.5 12 4l7.5 6.5V20a1 1 0 0 1-1 1h-5v-6h-3v6h-5a1 1 0 0 1-1-1v-9.5Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>',
        'customers' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="8" r="3.2" stroke="currentColor" stroke-width="1.6"/><path d="M5 19.2c1.1-3.1 3.6-4.7 7-4.7s5.9 1.6 7 4.7" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>',
        'invoices' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M7 3.8h7.2L19 8.6V20a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4.8a1 1 0 0 1 1-1Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M14 3.8V8.6h5M9 12.2h6M9 15.7h6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>',
        'payments' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="3.5" y="6.5" width="17" height="11.5" rx="1.6" stroke="currentColor" stroke-width="1.6"/><path d="M3.5 10.2h17M7 15h3.2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>',
        'email_templates' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="3.5" y="6" width="17" height="12.5" rx="1.6" stroke="currentColor" stroke-width="1.6"/><path d="m4.2 8 7.8 5.4L19.8 8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        'settings' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.6"/><path d="M12 3.6v2.1M12 18.3v2.1M4.9 7.1l1.5 1.5M17.6 15.4l1.5 1.5M3.6 12h2.1M18.3 12h2.1M4.9 16.9l1.5-1.5M17.6 8.6l1.5-1.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>',
        'tenants' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4.5 19.5V8.8L12 4.5l7.5 4.3v10.7" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M9 19.5v-6h6v6" stroke="currentColor" stroke-width="1.6"/></svg>',
        'account' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="8" r="3.2" stroke="currentColor" stroke-width="1.6"/><path d="M5 19.2c1.1-3.1 3.6-4.7 7-4.7s5.9 1.6 7 4.7" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>',
    );
    return isset($icons[$name]) ? $icons[$name] : '';
}

function render_app($pageTitle, $content, $currentPage)
{
    $user = current_user();
    $platform = is_platform();
    $company = $platform ? 'InvoiceHost' : setting('company_name', 'InvoiceHost');
    $flashOk = flash('success');
    $flashErr = flash('error');
    $sub = $platform ? null : current_subscription();
    $navFooter = array();
    if ($platform) {
        $navGroups = array(
            array(
                'tenants' => array('Tenants', url('tenants')),
                'account' => array('Your account', url('account')),
            ),
        );
    } else {
        $navGroups = array(
            array(
                'dashboard' => array('Dashboard', url('dashboard')),
                'customers' => array('Customers', url('customers')),
            ),
            array(
                'invoices' => array('Invoices', url('invoices')),
                'payments' => array('Payments', url('payments')),
            ),
        );
        $navFooter = array(
            'email_templates' => array('Email templates', url('email_templates')),
            'settings' => array('Settings', url('settings')),
        );
    }
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo e($pageTitle); ?> · <?php echo e($company); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="<?php echo e(invoice_ui_font_href()); ?>" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo e(asset('css/app.css')); ?>">
</head>
<body<?php echo !empty($GLOBALS['page_class']) ? ' class="' . e($GLOBALS['page_class']) . '"' : ''; ?>>
<script>try{if(localStorage.getItem('ih-nav-collapsed')==='1')document.body.classList.add('nav-collapsed');}catch(e){}</script>
<div class="app">
    <header class="app-header">
        <button type="button" class="menu-toggle" id="menu-toggle" aria-label="Open menu" aria-expanded="false" aria-controls="sidebar">
            <svg class="icon-menu" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
            <svg class="icon-close" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
        </button>
        <a class="header-brand" href="<?php echo e(url($platform ? 'tenants' : 'dashboard')); ?>">
            <span class="brand-mark">IH</span>
            <span class="header-brand-text"><?php echo e($platform ? 'InvoiceHost' : $company); ?></span>
        </a>
        <?php if (!$platform): ?>
        <form class="header-search" method="get" action="<?php echo e(url('invoices')); ?>">
            <input type="hidden" name="page" value="invoices">
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="11" cy="11" r="6.5" stroke="currentColor" stroke-width="1.8"/><path d="m16 16 4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
            <input type="search" name="q" placeholder="Search invoices" value="<?php echo $currentPage === 'invoices' ? e(query('q')) : ''; ?>">
        </form>
        <?php else: ?>
        <div class="header-search" style="opacity:.7">Platform admin</div>
        <?php endif; ?>
        <div class="header-user">
            <span class="header-avatar"><?php echo e(strtoupper(substr($user ? $user['name'] : 'U', 0, 1))); ?></span>
            <a class="header-logout" href="<?php echo e(url('logout')); ?>">Log out</a>
        </div>
    </header>
    <div class="nav-backdrop" id="nav-backdrop"></div>
    <aside class="sidebar" id="sidebar">
        <nav class="nav">
            <?php foreach ($navGroups as $group): ?>
                <div class="nav-group">
                    <?php foreach ($group as $key => $item): ?>
                        <a class="nav-link<?php echo $currentPage === $key ? ' is-active' : ''; ?>" href="<?php echo e($item[1]); ?>" title="<?php echo e($item[0]); ?>">
                            <span class="nav-icon"><?php echo nav_icon($key); ?></span>
                            <span class="nav-label"><?php echo e($item[0]); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </nav>
        <?php if ($navFooter): ?>
        <nav class="nav-end">
            <?php foreach ($navFooter as $key => $item): ?>
                <a class="nav-link<?php echo $currentPage === $key ? ' is-active' : ''; ?>" href="<?php echo e($item[1]); ?>" title="<?php echo e($item[0]); ?>">
                    <span class="nav-icon"><?php echo nav_icon($key); ?></span>
                    <span class="nav-label"><?php echo e($item[0]); ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <?php endif; ?>
        <a class="sidebar-logout" href="<?php echo e(url('logout')); ?>">Log out</a>
        <button type="button" class="sidebar-collapse" id="sidebar-collapse" aria-label="Collapse menu" title="Collapse menu">
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M11 6.5 5.5 12 11 17.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M17.5 6.5 12 12 17.5 17.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </button>
    </aside>
    <div class="main">
        <header class="topbar<?php echo !empty($GLOBALS['hide_page_title']) ? ' topbar-compact' : ''; ?>">
            <?php if (!empty($GLOBALS['breadcrumbs'])): ?>
                <nav class="crumbs">
                    <?php foreach ($GLOBALS['breadcrumbs'] as $i => $crumb): ?>
                        <?php if ($i > 0): ?><span class="crumbs-sep">/</span><?php endif; ?>
                        <?php if (!empty($crumb[1])): ?>
                            <a href="<?php echo e($crumb[1]); ?>"><?php echo e($crumb[0]); ?></a>
                        <?php else: ?>
                            <span><?php echo e($crumb[0]); ?></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </nav>
            <?php endif; ?>
            <?php if (empty($GLOBALS['hide_page_title'])): ?>
                <h1><?php echo e($pageTitle); ?></h1>
            <?php endif; ?>
        </header>
        <div class="content">
            <?php if ($flashOk): ?>
                <div class="alert alert-ok"><?php echo e($flashOk); ?></div>
            <?php endif; ?>
            <?php if ($flashErr): ?>
                <div class="alert alert-err"><?php echo e($flashErr); ?></div>
            <?php endif; ?>
            <?php if ($sub && empty($sub['is_usable'])): ?>
                <div class="alert alert-err">Subscription is not active<?php echo !empty($sub['ends_at']) ? ' (ended ' . e($sub['ends_at']) . ')' : ''; ?>. You can view data, but new invoices and customers are blocked until it is renewed.</div>
            <?php elseif ($sub && $sub['status'] === 'trial' && !empty($sub['ends_at'])): ?>
                <div class="alert alert-ok">Trial · <?php echo e($sub['plan_name']); ?> through <?php echo e($sub['ends_at']); ?>.</div>
            <?php endif; ?>
            <?php echo $content; ?>
        </div>
    </div>
</div>
<div class="modal-overlay" id="confirm-modal" hidden>
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="confirm-modal-title" aria-describedby="confirm-modal-text">
        <h2 id="confirm-modal-title">Are you sure?</h2>
        <p id="confirm-modal-text"></p>
        <div class="modal-actions">
            <button type="button" class="btn btn-ghost" id="confirm-modal-cancel">Cancel</button>
            <button type="button" class="btn btn-danger" id="confirm-modal-ok">Delete</button>
        </div>
    </div>
</div>
<script src="<?php echo e(asset('js/app.js')); ?>"></script>
</body>
</html>
    <?php
}

function render_auth($pageTitle, $content)
{
    $company = 'InvoiceHost';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo e($pageTitle); ?> · <?php echo e($company); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="<?php echo e(invoice_ui_font_href()); ?>" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo e(asset('css/app.css')); ?>">
</head>
<body class="auth-body">
    <?php echo $content; ?>
</body>
</html>
    <?php
}
