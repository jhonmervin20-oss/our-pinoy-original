<?php
/**
 * inventory/includes/inventory_nav.php
 *
 * Sub-nav shown at the top of every inventory page, modeled directly on
 * owner/settings/includes/settings_nav.php (one sidebar entry, several
 * physical pages, tabs styled with the existing .owner-tabs classes).
 *
 * Usage — set $activeInvTab to the current page's key, then include:
 *
 *   $activeInvTab = 'transactions';
 *   require __DIR__ . '/includes/inventory_nav.php';
 */

$activeInvTab = $activeInvTab ?? '';

function invTabLink(string $key, string $href, string $label, string $activeTab): string
{
    $isActive = $key === $activeTab;
    $class    = 'owner-tabs-link' . ($isActive ? ' is-active' : '');

    return sprintf(
        '<a href="%s" class="%s"%s><span>%s</span></a>',
        htmlspecialchars($href),
        $class,
        $isActive ? ' aria-current="page"' : '',
        htmlspecialchars($label)
    );
}
?>
<?php /* Spacing comes from .owner-tabs, not an inline override -- this one was
         pinned at 20px and so ignored the class, leaving the module tabs sitting
         in more space than the Items/Batches tabs directly below them. */ ?>
<nav class="owner-tabs" aria-label="Inventory">
    <?= invTabLink('items', 'inventory.php', 'Items & Batches', $activeInvTab) ?>
    <?= invTabLink('transactions', 'inventory_transactions.php', 'Transactions', $activeInvTab) ?>
    <?= invTabLink('adjustments', 'inventory_adjustments.php', 'Adjustments', $activeInvTab) ?>
    <?= invTabLink('wastage', 'inventory_wastage.php', 'Wastage', $activeInvTab) ?>
</nav>
