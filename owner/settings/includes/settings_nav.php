<?php
/**
 * owner/settings/includes/settings_nav.php
 *
 * Horizontal sub-nav shown at the top of every Settings page. Tabs the
 * current role can't access are skipped entirely (not just disabled),
 * so an 'owner' never even sees Payment Gateway / Users / Roles.
 *
 * Usage — set $activeSettingsTab to the current page's key, then include:
 *
 *   $activeSettingsTab = 'general';
 *   require __DIR__ . '/includes/settings_nav.php';
 *
 * The Payroll tab's page (employee_management/settings.php) lives OUTSIDE
 * owner/settings/, so the hrefs can't all be bare filenames any more:
 *   - $settingsNavBase        prefixes the owner/settings/*.php tabs
 *   - $settingsNavPayrollHref is the Payroll tab's own href
 * Both default to what an owner/settings/*.php page needs, so those pages
 * keep including this file unchanged; only the payroll page sets them.
 */

$activeSettingsTab = $activeSettingsTab ?? '';
$settingsNavBase = $settingsNavBase ?? '';
$settingsNavPayrollHref = $settingsNavPayrollHref ?? '../../employee_management/settings.php';

/**
 * Render one tab link, or nothing at all if the current user's role isn't
 * in $allowedRoles.
 */
function settingsTabLink(string $key, string $href, string $label, string $activeTab, array $allowedRoles): string
{
    if (!Session::hasRole($allowedRoles)) {
        return '';
    }

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
<nav class="owner-tabs" aria-label="Settings">
    <?= settingsTabLink('general', $settingsNavBase . 'general.php', 'General', $activeSettingsTab, ['owner']) ?>
    <?= settingsTabLink('discounts', $settingsNavBase . 'discounts.php', 'Discounts', $activeSettingsTab, ['owner']) ?>
    <?= settingsTabLink('pricing', $settingsNavBase . 'pricing.php', 'Pricing & Costing', $activeSettingsTab, ['owner']) ?>
    <?= settingsTabLink('units', $settingsNavBase . 'units.php', 'Units & Conversions', $activeSettingsTab, ['owner']) ?>
    <?= settingsTabLink('reservation_settings', $settingsNavBase . 'reservation_settings.php', 'Reservation', $activeSettingsTab, ['owner']) ?>
    <?= settingsTabLink('purchasing_forecast', $settingsNavBase . 'purchasing_forecast.php', 'Purchasing & Forecasting', $activeSettingsTab, ['owner', 'manager']) ?>
    <?= settingsTabLink('payroll', $settingsNavPayrollHref, 'Payroll', $activeSettingsTab, ['owner']) ?>
</nav>