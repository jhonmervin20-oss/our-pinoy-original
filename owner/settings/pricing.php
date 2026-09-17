<?php
/**
 * owner/settings/pricing.php
 *
 * Restaurant-wide Pricing / Packaging Fee policy. Unlike tax_settings
 * (multiple rows, one active), pricing_settings is always exactly one row
 * (setting_id = 1), edited in place -- there's no "effective date" history
 * concept here since price changes are already tracked per-item in
 * price_history.
 */

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../config/flash.php';

Session::start();

if (!Session::isLoggedIn()) {
    header('Location: ../../auth/login.php');
    exit;
}
if (!Session::hasRole(['owner'])) {
    header('Location: ../../auth/login.php');
    exit;
}

$db = Database::getInstance()->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        flash_set('error', 'Your session expired. Please try again.');
        header('Location: pricing.php');
        exit;
    }

    $pricingMethod           = $_POST['pricing_method'] ?? '';
    $defaultMarkup           = $_POST['default_markup_percentage'] ?? '';
    $defaultMargin           = $_POST['default_margin_percentage'] ?? '';
    $targetFoodCost          = $_POST['target_food_cost_percentage'] ?? '';
    $packagingFeePolicy      = $_POST['packaging_fee_policy'] ?? '';

    $error = null;
    if (!in_array($pricingMethod, ['markup', 'margin'], true)) {
        $error = 'Please choose a valid pricing method.';
    } elseif (!is_numeric($defaultMarkup) || (float)$defaultMarkup < 0) {
        $error = 'Default markup % must be 0 or more.';
    } elseif (!is_numeric($defaultMargin) || (float)$defaultMargin < 0 || (float)$defaultMargin >= 100) {
        $error = 'Default margin % must be between 0 and 99.99.';
    } elseif (!is_numeric($targetFoodCost) || (float)$targetFoodCost <= 0 || (float)$targetFoodCost >= 100) {
        $error = 'Target food cost % must be between 0 and 100.';
    } elseif (!in_array($packagingFeePolicy, ['included', 'separate', 'none'], true)) {
        $error = 'Please choose a valid packaging fee policy.';
    }

    if ($error) {
        flash_set('error', $error);
    } else {
        try {
            $db->prepare(
                'UPDATE pricing_settings SET
                    pricing_method = ?, default_markup_percentage = ?, default_margin_percentage = ?,
                    target_food_cost_percentage = ?,
                    packaging_fee_policy = ?, updated_by = ?
                 WHERE setting_id = 1'
            )->execute([
                $pricingMethod, round((float)$defaultMarkup, 2), round((float)$defaultMargin, 2),
                round((float)$targetFoodCost, 2),
                $packagingFeePolicy,
                Session::getUserId(),
            ]);

            flash_set('success', 'Pricing settings saved.');
        } catch (PDOException $e) {
            error_log('pricing.php save failed: ' . $e->getMessage());
            flash_set('error', 'Something went wrong saving pricing settings. Please try again.');
        }
    }

    header('Location: pricing.php');
    exit;
}

// rounding_increment is deliberately NOT exposed on this form and is never
// written by the save above -- the restaurant prices to the whole peso, so it
// stays pinned at the column's own DEFAULT 1.00. The column is still read by
// computeSuggestedPrice() and recipe_builder.php, so it is kept rather than
// dropped; the only change is that nobody can move it off 1.00 from the UI.
$settings = $db->query('SELECT * FROM pricing_settings WHERE setting_id = 1')->fetch();
if (!$settings) {
    // Defensive fallback -- the row is seeded on creation, but don't fatal if it's ever missing.
    $settings = [
        'pricing_method' => 'markup', 'default_markup_percentage' => 100, 'default_margin_percentage' => 50,
        'target_food_cost_percentage' => 30.00,
        'rounding_increment' => 1.00, 'packaging_fee_policy' => 'separate',
    ];
}

$activePage        = 'settings';
$activeSettingsTab = 'pricing';
$pageTitle         = 'Pricing settings';
$ownerBase         = '../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pricing settings | Owner Panel | OPO! Our Pinoy Original</title>
<link rel="icon" type="image/x-icon" href="../../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/owner-panel.css?v=<?= filemtime(__DIR__ . '/../assets/css/owner-panel.css') ?>">
</head>
<body>

<div class="owner-shell">

    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <div class="owner-main">

        <?php require_once __DIR__ . '/../includes/header.php'; ?>

        <main class="owner-content">
            <?= flash_render() ?>
            <?php require __DIR__ . '/includes/settings_nav.php'; ?>

            <div class="owner-card">
                <h2 class="owner-card-title">Pricing settings</h2>
                <p class="owner-card-subtitle">Defaults used by the Pricing calculator on each menu item, plus Packaging Fee policy applied at checkout.</p>

                <form method="POST" action="pricing.php">
                    <?= csrf_field() ?>

                    <div class="owner-form-section">
                        <h3 class="owner-form-section-title">Default pricing method</h3>
                        <div class="owner-form-group">
                            <div class="owner-segmented">
                                <label class="owner-segmented-option">
                                    <input type="radio" name="pricing_method" value="markup" <?= $settings['pricing_method'] === 'markup' ? 'checked' : '' ?>>
                                    Markup %
                                </label>
                                <label class="owner-segmented-option">
                                    <input type="radio" name="pricing_method" value="margin" <?= $settings['pricing_method'] === 'margin' ? 'checked' : '' ?>>
                                    Margin %
                                </label>
                            </div>
                        </div>
                        <div class="owner-form-grid" style="margin-top:14px;">
                            <div class="owner-form-group">
                                <label for="default_markup_percentage">Default markup %</label>
                                <input type="number" id="default_markup_percentage" name="default_markup_percentage" class="owner-input" min="0" step="0.01" value="<?= htmlspecialchars($settings['default_markup_percentage']) ?>" required>
                                <span class="owner-form-hint">Selling price = Cost &times; (1 + Markup%/100).</span>
                            </div>
                            <div class="owner-form-group">
                                <label for="default_margin_percentage">Default margin %</label>
                                <input type="number" id="default_margin_percentage" name="default_margin_percentage" class="owner-input" min="0" max="99.99" step="0.01" value="<?= htmlspecialchars($settings['default_margin_percentage']) ?>" required>
                                <span class="owner-form-hint">Selling price = Cost &divide; (1 - Margin%/100).</span>
                            </div>
                        </div>
                    </div>

                    <div class="owner-form-section">
                        <h3 class="owner-form-section-title">Food cost target</h3>
                        <div class="owner-form-grid">
                            <div class="owner-form-group">
                                <label for="target_food_cost_percentage">Target food cost %</label>
                                <input type="number" id="target_food_cost_percentage" name="target_food_cost_percentage" class="owner-input" min="0.01" max="99.99" step="0.01" value="<?= htmlspecialchars($settings['target_food_cost_percentage']) ?>" required>
                                <span class="owner-form-hint">Sets the "excellent" cutoff for the Food Cost %/Margin % color bands on Menu items and Costing (industry-typical is 28&ndash;35%).</span>
                            </div>
                        </div>
                    </div>

                    <div class="owner-form-section">
                        <h3 class="owner-form-section-title">Packaging fee policy</h3>
                        <div class="owner-form-group">
                            <div class="owner-segmented">
                                <label class="owner-segmented-option">
                                    <input type="radio" name="packaging_fee_policy" value="included" <?= $settings['packaging_fee_policy'] === 'included' ? 'checked' : '' ?>>
                                    Included in selling price
                                </label>
                                <label class="owner-segmented-option">
                                    <input type="radio" name="packaging_fee_policy" value="separate" <?= $settings['packaging_fee_policy'] === 'separate' ? 'checked' : '' ?>>
                                    Charge separate fee
                                </label>
                                <label class="owner-segmented-option">
                                    <input type="radio" name="packaging_fee_policy" value="none" <?= $settings['packaging_fee_policy'] === 'none' ? 'checked' : '' ?>>
                                    No packaging charge
                                </label>
                            </div>
                            <span class="owner-form-hint">"Charge separate fee" adds packaging cost as its own line on takeout orders (e.g. Food &#8369;180 + Packaging fee &#8369;15 = &#8369;195). "No packaging charge" absorbs it as a business cost.</span>
                        </div>
                    </div>

                    <div style="margin-top:20px; text-align:right;">
                        <button type="submit" class="owner-btn owner-btn-primary">
                            <i class="ph ph-check" aria-hidden="true"></i> Save changes
                        </button>
                    </div>
                </form>
            </div>
        </main>

    </div>

</div>

</body>
</html>
