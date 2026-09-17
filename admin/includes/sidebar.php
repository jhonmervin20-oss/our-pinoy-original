<?php
/**
 * admin/includes/sidebar.php
 *
 * Left navigation for the Admin panel — same shell as the Owner panel
 * (owner/includes/sidebar.php), just a different set of links. Reuses
 * owner-panel.css directly so the two panels stay visually identical.
 *
 * Usage — set $activePage to the current page's key, then include:
 *
 *   $activePage = 'dashboard';
 *   require_once __DIR__ . '/includes/sidebar.php';
 *
 * Pages that live one level deeper than admin/ must also set
 * $adminBase = '../'; before requiring this, so every link here still
 * resolves relative to the including page's own directory.
 */

$activePage = $activePage ?? '';
$adminBase  = $adminBase ?? '';

/**
 * Render one nav link, marking it active when it matches the current page.
 */
function adminNavLink(string $key, string $href, string $icon, string $label, string $activePage): string
{
    $isActive = $key === $activePage;
    $class    = 'owner-nav-link' . ($isActive ? ' is-active' : '');

    return sprintf(
        '<a href="%s" class="%s"%s><i class="ph %s" aria-hidden="true"></i><span>%s</span></a>',
        htmlspecialchars($href),
        $class,
        $isActive ? ' aria-current="page"' : '',
        htmlspecialchars($icon),
        htmlspecialchars($label)
    );
}
?>
<aside class="owner-sidebar" id="ownerSidebar">
    <div class="owner-sidebar-brand">
        <a href="<?= htmlspecialchars($adminBase) ?>dashboard.php" class="owner-sidebar-brand-mark" aria-label="Our Pinoy Original — Admin panel">
            <img src="<?= htmlspecialchars($adminBase) ?>../assets/images/logo.jpg" alt="" class="owner-sidebar-logo">
            <span class="owner-sidebar-brand-text">
            </span>
        </a>
    </div>

    <nav class="owner-nav" aria-label="Admin panel">
        <div class="owner-nav-section">Administration</div>
        <?= adminNavLink('dashboard', $adminBase . 'dashboard.php', 'ph-squares-four', 'Dashboard', $activePage) ?>
        <?= adminNavLink('users', $adminBase . 'users.php', 'ph-users', 'Users', $activePage) ?>
        <?= adminNavLink('activity_logs', $adminBase . 'activity_logs.php', 'ph-clock-counter-clockwise', 'Activity logs', $activePage) ?>

        <?php // Employee records, org structure, shift scheduling and the holiday calendar moved here from
              // the Manager panel. Same nav-key vocabulary the manager sidebar uses
              // (payroll_*), so employee_management/'s pages highlight correctly
              // whichever panel is rendering them -- see em_access.php. ?>
        <div class="owner-nav-section">Employee Management</div>
        <?= adminNavLink('payroll_employees', $adminBase . '../employee_management/employees.php', 'ph-users-three', 'Employees', $activePage) ?>
        <?= adminNavLink('payroll_departments_positions', $adminBase . '../employee_management/departments_positions.php', 'ph-identification-badge', 'Departments & Positions', $activePage) ?>
        <?= adminNavLink('payroll_schedules', $adminBase . '../employee_management/schedules.php', 'ph-calendar-blank', 'Schedules', $activePage) ?>
        <?= adminNavLink('payroll_holidays', $adminBase . '../employee_management/holidays.php', 'ph-flag-banner', 'Holidays', $activePage) ?>

        <?php /* <div class="owner-nav-section">Insights</div>
        <?= adminNavLink('feedback_moderation', $adminBase . 'feedback_moderation.php', 'ph-shield-check', 'Feedback moderation', $activePage) ?> */ ?>

        </nav>

        <?php // Pinned. The nav above scrolls; this does not, so Log out is
              // reachable without scrolling to the end of a long menu. ?>
        <div class="owner-sidebar-foot">
            <a href="<?= htmlspecialchars($adminBase) ?>../auth/logout.php" class="owner-nav-link owner-nav-logout">
                <i class="ph ph-sign-out" aria-hidden="true"></i><span>Log out</span>
            </a>
        </div>
</aside>
<script>
/* Keep the nav showing where you are.
   The nav is its own scrolling box now, and a scroll box always starts at the
   top on a fresh page. Click Settings at the bottom of a long menu and the new
   page renders with the nav scrolled back up, so the item you just chose is out
   of sight below -- it reads as the menu jumping on you.
   This scrolls the active link into view, and only when it is genuinely out of
   view, so a short menu never moves. scrollTop is set directly rather than via
   scrollIntoView(), which would also scroll the page itself. */
(function () {
    var nav = document.querySelector('.owner-nav, .pos-nav');
    if (!nav) return;
    var active = nav.querySelector('.is-active');
    if (!active) return;

    var navBox = nav.getBoundingClientRect();
    var box = active.getBoundingClientRect();
    if (box.top >= navBox.top && box.bottom <= navBox.bottom) return;   // already visible

    // Centre it, clamped to the scrollable range.
    var target = active.offsetTop - (nav.clientHeight / 2) + (active.offsetHeight / 2);
    nav.scrollTop = Math.max(0, Math.min(target, nav.scrollHeight - nav.clientHeight));
})();
</script>

<div class="owner-sidebar-backdrop" id="ownerSidebarBackdrop"></div>
