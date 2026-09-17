<?php
/**
 * owner/includes/sidebar.php
 *
 * Left navigation for the Owner panel — a clean white icon+label rail,
 * always shown at full width on desktop. Below 900px it slides in as an
 * off-canvas drawer (see the hamburger toggle in header.php, which is
 * hidden by default and only shown below 900px).
 *
 * Usage — set $activePage to the current page's key, then include:
 *
 *   $activePage = 'dashboard';
 *   require_once __DIR__ . '/includes/sidebar.php';
 *
 * Pages that live one level deeper than owner/ (e.g. owner/settings/*.php)
 * must also set $ownerBase = '../'; before requiring this, so every link
 * here still resolves relative to the including page's own directory.
 */

$activePage = $activePage ?? '';
$ownerBase  = $ownerBase ?? '';

/**
 * Render one nav link, marking it active when it matches the current page.
 */
function ownerNavLink(string $key, string $href, string $icon, string $label, string $activePage): string
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
        <a href="<?= htmlspecialchars($ownerBase) ?>dashboard.php" class="owner-sidebar-brand-mark" aria-label="Our Pinoy Original — Owner panel">
            <img src="<?= htmlspecialchars($ownerBase) ?>../assets/images/logo.jpg" alt="" class="owner-sidebar-logo">
            <span class="owner-sidebar-brand-text">
            </span>
        </a>
    </div>

    <nav class="owner-nav" aria-label="Owner panel">
        <div class="owner-nav-section">Operations</div>
        <?= ownerNavLink('dashboard', $ownerBase . 'dashboard.php', 'ph-squares-four', 'Dashboard', $activePage) ?>
        <?php // Void requests is a TAB inside Orders, not its own nav entry -- a
              // void request is only ever about an order, so two sidebar items on
              // the same subject just made the reviewer navigate between them. ?>
        <?= ownerNavLink('orders', $ownerBase . '../orders/orders.php', 'ph-receipt', 'Orders', $activePage) ?>
        <?= ownerNavLink('reservations', $ownerBase . '../reservation/reservations.php', 'ph-calendar-check', 'Reservations', $activePage) ?>

        <div class="owner-nav-section">Menu &amp; kitchen</div>
        <?= ownerNavLink('menu_items', $ownerBase . 'menu_management/menu_items.php', 'ph-book-open', 'Menu items', $activePage) ?>
        <?= ownerNavLink('costing', $ownerBase . 'menu_management/costing.php', 'ph-calculator', 'Costing', $activePage) ?>

      <div class="owner-nav-section">Supply Chain</div>

<?= ownerNavLink(
    'inventory',
    $ownerBase . '../inventory/inventory.php',
    'ph-package',
    'Inventory',
    $activePage
) ?>

<?= ownerNavLink(
    'suppliers',
    $ownerBase . 'suppliers.php',
    'ph-buildings',
    'Suppliers',
    $activePage
) ?>

<?= ownerNavLink(
    'purchase_orders',
    $ownerBase . '../purchase_orders/purchase_orders.php',
    'ph-shopping-cart',
    'Purchase Orders',
    $activePage
) ?>

        <div class="owner-nav-section">Insights</div>
        <?= ownerNavLink('analytics', $ownerBase . 'analytics.php', 'ph-chart-bar', 'Analytics', $activePage) ?>
        <?= ownerNavLink('report', $ownerBase . 'report.php', 'ph-chart-line', 'Report', $activePage) ?>
        <?= ownerNavLink('demand_forecast', $ownerBase . 'demand_forecast.php', 'ph-trend-up', 'Demand forecast', $activePage) ?>
        <?= ownerNavLink('sentiment_analysis', $ownerBase . 'sentiment_analysis.php', 'ph-chat-circle-dots', 'Sentiment analysis', $activePage) ?>
        <?php /* Split from sentiment analysis: one page answers "how do customers
                 feel", the other "what may we publish". They are different jobs
                 read at different times -- sentiment weekly, moderation daily --
                 and the engine scores them as independent axes anyway. */ ?>
        <?= ownerNavLink('feedback_moderation', $ownerBase . 'feedback_moderation.php', 'ph-shield-check', 'Feedback moderation', $activePage) ?>

        <div class="owner-nav-section">System</div>
        <?= ownerNavLink('settings', $ownerBase . 'settings/general.php', 'ph-gear', 'Settings', $activePage) ?>
        </nav>

        <?php // Pinned. The nav above scrolls; this does not, so Log out is
              // reachable without scrolling to the end of a long menu. ?>
        <div class="owner-sidebar-foot">
            <a href="<?= htmlspecialchars($ownerBase) ?>../auth/logout.php" class="owner-nav-link owner-nav-logout">
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
