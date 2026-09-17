<?php
/**
 * cashier/includes/sidebar.php
 *
 * Left navigation for the POS terminal, mirroring owner/includes/sidebar.php's
 * structure (brand mark, nav links, logout) and CSS mechanics (off-canvas
 * mobile drawer below 900px via #posSidebar/#posSidebarBackdrop, toggled by
 * pos.php's own hamburger button). Deliberately short today -- cashier/ only
 * has one real screen (the POS terminal itself) -- but built the same
 * extensible way so more items can be added later without restructuring.
 *
 * Requires $restaurantName to already be set by the including page, and
 * $activePage ('pos'|'remittance'|'reservations'|'orders'|'profile') to
 * drive which nav link is highlighted -- defaults to 'pos' if the
 * including page doesn't set it.
 *
 * There's no topbar on this page (removed to keep the terminal as compact
 * as possible), so the cashier's identity -- previously shown in that
 * header -- lives here instead, pinned to the bottom of the sidebar as an
 * initials avatar + name, right above Log out (mirrors owner/includes/
 * header.php's .owner-avatar initials treatment, just stacked vertically
 * to fit the sidebar's width instead of the topbar's horizontal layout).
 */
$activePage = $activePage ?? 'pos';
?>
<aside class="pos-sidebar" id="posSidebar">
    <div class="pos-sidebar-brand">
        <a href="pos.php" class="pos-sidebar-brand-mark" aria-label="<?= htmlspecialchars($restaurantName) ?> POS">
            <img src="../assets/images/logo.jpg" alt="" class="pos-sidebar-logo">
        </a>
    </div>

    <nav class="pos-nav" aria-label="POS terminal">
        <a href="pos.php" class="pos-nav-link<?= $activePage === 'pos' ? ' is-active' : '' ?>" <?= $activePage === 'pos' ? 'aria-current="page"' : '' ?>>
            <i class="ph ph-storefront" aria-hidden="true"></i><span>POS</span>
        </a>
        <a href="remittance_report.php" class="pos-nav-link<?= $activePage === 'remittance' ? ' is-active' : '' ?>" <?= $activePage === 'remittance' ? 'aria-current="page"' : '' ?>>
            <i class="ph ph-cash-register" aria-hidden="true"></i><span>Cash Balance</span>
        </a>
        <a href="reservations.php" class="pos-nav-link<?= $activePage === 'reservations' ? ' is-active' : '' ?>" <?= $activePage === 'reservations' ? 'aria-current="page"' : '' ?>>
            <i class="ph ph-calendar-check" aria-hidden="true"></i><span>Reservations</span>
        </a>
        <a href="orders.php" class="pos-nav-link<?= $activePage === 'orders' ? ' is-active' : '' ?>" <?= $activePage === 'orders' ? 'aria-current="page"' : '' ?>>
            <i class="ph ph-receipt" aria-hidden="true"></i><span>Orders</span>
        </a>
    </nav>

    <div class="pos-sidebar-footer">
        
        <a href="profile.php" class="pos-sidebar-profile" aria-label="My Profile">
            <span class="pos-sidebar-avatar"><?= htmlspecialchars(Session::getInitials()) ?></span>
            <span class="pos-sidebar-profile-name"><?= htmlspecialchars(Session::getFullName() ?: 'Cashier') ?></span>
        </a>
        <a href="../auth/logout.php" class="pos-nav-link pos-nav-logout">
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

<div class="pos-sidebar-backdrop" id="posSidebarBackdrop"></div>
