<?php
/**
 * manager/includes/sidebar.php
 *
 * Left navigation for the Manager panel — same shell as the Owner panel
 * (owner/includes/sidebar.php), scoped to daily-operations links plus
 * manager-facing insights
 * (matches the 'manager' role description: "Manages daily operations:
 * inventory, purchase orders, reservations, menu"). Reuses owner-panel.css
 * directly so all three panels stay visually identical.
 *
 * Usage — set $activePage to the current page's key, then include:
 *
 *   $activePage = 'dashboard';
 *   require_once __DIR__ . '/includes/sidebar.php';
 *
 * Pages that live one level deeper than manager/ must also set
 * $managerBase = '../'; before requiring this, so every link here still
 * resolves relative to the including page's own directory.
 */

$activePage  = $activePage ?? '';
$managerBase = $managerBase ?? '';

/**
 * Render one nav link, marking it active when it matches the current page.
 */
function managerNavLink(string $key, string $href, string $icon, string $label, string $activePage): string
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
        <a href="<?= htmlspecialchars($managerBase) ?>dashboard.php" class="owner-sidebar-brand-mark" aria-label="Our Pinoy Original — Manager panel">
            <img src="<?= htmlspecialchars($managerBase) ?>../assets/images/logo.jpg" alt="" class="owner-sidebar-logo">
            <span class="owner-sidebar-brand-text">
            </span>
        </a>
    </div>

    <nav class="owner-nav" aria-label="Manager panel">
        <div class="owner-nav-section">Operations</div>
        <?= managerNavLink('dashboard', $managerBase . 'dashboard.php', 'ph-squares-four', 'Dashboard', $activePage) ?>
        <?php // Void requests is a TAB inside Orders, not its own nav entry -- a
              // void request is only ever about an order, so two sidebar items on
              // the same subject just made the reviewer navigate between them. ?>
        <?= managerNavLink('orders', $managerBase . '../orders/orders.php', 'ph-receipt', 'Orders', $activePage) ?>
        <?= managerNavLink('reservations', $managerBase . '../reservation/reservations.php', 'ph-calendar-check', 'Reservations', $activePage) ?>

       
        <div class="owner-nav-section">Supply chain</div>
        <?= managerNavLink('inventory', $managerBase . '../inventory/inventory.php', 'ph-package', 'Inventory', $activePage) ?>
        <?php // "Receiving orders", not "Purchase Orders": a Manager cannot see or
       // raise a draft -- purchasing is the Owner's. What a Manager comes to
       // this page for is receiving stock against an approved order, so the
       // link is named for the job rather than for the table behind it. ?>
        <?php // A truck, not a package: Inventory directly above already uses the
              // package icon, and two identical icons in adjacent rows made the
              // nav read as one section rather than two destinations. Receiving
              // is about stock ARRIVING, which the truck says on its own. ?>
        <?= managerNavLink('purchase_orders', $managerBase . '../purchase_orders/purchase_orders.php', 'ph-truck', 'Receiving Orders', $activePage) ?>

        <div class="owner-nav-section">Employee Management</div>
        <?= managerNavLink('payroll_employees', $managerBase . '../employee_management/employees.php', 'ph-users-three', 'Employees', $activePage) ?>
        <?= managerNavLink('payroll_schedules', $managerBase . '../employee_management/schedules.php', 'ph-calendar-blank', 'Schedules', $activePage) ?>
        <?= managerNavLink('payroll_attendance', $managerBase . '../employee_management/attendance.php', 'ph-clock', 'Attendance', $activePage) ?>
        <?= managerNavLink('payroll_leave', $managerBase . '../employee_management/leave.php', 'ph-airplane-takeoff', 'Leave', $activePage) ?>
        <?= managerNavLink('payroll_overtime', $managerBase . '../employee_management/overtime.php', 'ph-timer', 'Overtime', $activePage) ?>
        <?= managerNavLink('payroll_adjustments', $managerBase . '../employee_management/adjustments.php', 'ph-sliders-horizontal', 'Adjustments', $activePage) ?>
        <?= managerNavLink('payroll_runs', $managerBase . '../employee_management/runs.php', 'ph-money', 'Payroll Runs', $activePage) ?>

        <div class="owner-nav-section">Insights</div>
        <?= managerNavLink('performance_monitoring', $managerBase . '../employee_management/performance_monitoring.php', 'ph-chart-line-up', 'Performance Monitoring', $activePage) ?>

        </nav>

        <?php // Pinned. The nav above scrolls; this does not, so Log out is
              // reachable without scrolling to the end of a long menu. ?>
        <div class="owner-sidebar-foot">
            <a href="<?= htmlspecialchars($managerBase) ?>../auth/logout.php" class="owner-nav-link owner-nav-logout">
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
