<?php
/**
 * owner/settings/tax.php
 *
 * Only one tax_settings row may be is_active = 1 at a time. Activating a
 * row deactivates every other row in the same transaction, so there's
 * never a moment with zero or multiple active rates.
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
        header('Location: tax.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'update_active_tax') {
        $taxName = trim($_POST['tax_name'] ?? '');
        $taxRate = $_POST['tax_rate'] ?? '';
        $taxType = $_POST['tax_type'] ?? '';
        $enabled = isset($_POST['is_active']) ? 1 : 0;

        $error = null;
        if ($taxName === '' || mb_strlen($taxName) > 50) {
            $error = 'Please enter a tax name (up to 50 characters).';
        } elseif (!is_numeric($taxRate) || (float)$taxRate < 0 || (float)$taxRate > 100) {
            $error = 'Tax rate must be a number between 0 and 100.';
        } elseif (!in_array($taxType, ['inclusive', 'exclusive'], true)) {
            $error = 'Please choose a valid tax type.';
        }

        if ($error) {
            flash_set('error', $error);
        } else {
            try {
                $db->beginTransaction();

                // The row this card edits: whichever one is currently active,
                // or (if none is) the most recent one, so the very first save
                // on an empty table still has something sensible to update.
                $targetId = $db->query(
                    'SELECT tax_setting_id FROM tax_settings
                     ORDER BY is_active DESC, effective_date DESC, tax_setting_id DESC
                     LIMIT 1'
                )->fetchColumn();

                if ($enabled) {
                    // Only one row may be active at a time.
                    $db->exec('UPDATE tax_settings SET is_active = 0 WHERE is_active = 1');
                }

                if ($targetId) {
                    $stmt = $db->prepare(
                        'UPDATE tax_settings SET tax_name = ?, tax_rate = ?, tax_type = ?, is_active = ? WHERE tax_setting_id = ?'
                    );
                    $stmt->execute([$taxName, round((float)$taxRate, 2), $taxType, $enabled, $targetId]);
                } else {
                    $stmt = $db->prepare(
                        'INSERT INTO tax_settings (tax_name, tax_rate, tax_type, is_active, effective_date) VALUES (?, ?, ?, ?, CURDATE())'
                    );
                    $stmt->execute([$taxName, round((float)$taxRate, 2), $taxType, $enabled]);
                }

                $db->commit();
                flash_set('success', 'Tax settings saved.');
            } catch (PDOException $e) {
                $db->rollBack();
                error_log('tax.php update_active_tax failed: ' . $e->getMessage());
                flash_set('error', 'Something went wrong saving tax settings. Please try again.');
            }
        }
    }

    header('Location: tax.php');
    exit;
}

// The row the quick-edit card at the top shows: whichever one is active,
// or the most recent one if none is (e.g. a brand new, never-configured
// install) — kept in sync with the same lookup the save handler uses above.
$currentTax = $db->query(
    'SELECT * FROM tax_settings ORDER BY is_active DESC, effective_date DESC, tax_setting_id DESC LIMIT 1'
)->fetch();

$activePage        = 'settings';
$activeSettingsTab = 'tax';
$pageTitle         = 'Tax settings';
$ownerBase         = '../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Tax settings | Owner Panel | OPO! Our Pinoy Original</title>
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
                <h2 class="owner-card-title">Tax settings</h2>
                <p class="owner-card-subtitle">Configure how tax is calculated and labeled.</p>

                <form method="POST" action="tax.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_active_tax">

                    <div class="owner-toggle-row">
                        <div>
                            <div class="owner-toggle-row-label">Enable tax</div>
                            <div class="owner-toggle-row-desc">Apply tax to orders at checkout.</div>
                        </div>
                        <label class="owner-toggle" aria-label="Enable tax">
                            <input type="checkbox" name="is_active" <?= ($currentTax && $currentTax['is_active']) ? 'checked' : '' ?>>
                            <span class="owner-toggle-track"></span>
                        </label>
                    </div>

                    <div class="owner-form-grid">
                        <div class="owner-form-group">
                            <label for="quick_tax_name">Tax name</label>
                            <input type="text" id="quick_tax_name" name="tax_name" class="owner-input" maxlength="50" value="<?= htmlspecialchars($currentTax['tax_name'] ?? 'VAT') ?>" required>
                        </div>
                        <div class="owner-form-group">
                            <label for="quick_tax_rate">Tax rate (%)</label>
                            <input type="number" id="quick_tax_rate" name="tax_rate" class="owner-input" min="0" max="100" step="0.01" value="<?= htmlspecialchars($currentTax['tax_rate'] ?? '') ?>" required>
                        </div>
                    </div>

                    <div class="owner-form-group" style="margin-top:16px;">
                        <label>Tax type</label>
                        <div class="owner-segmented">
                            <label class="owner-segmented-option">
                                <input type="radio" name="tax_type" value="inclusive" <?= (!$currentTax || $currentTax['tax_type'] === 'inclusive') ? 'checked' : '' ?>>
                                Inclusive (in price)
                            </label>
                            <label class="owner-segmented-option">
                                <input type="radio" name="tax_type" value="exclusive" <?= ($currentTax && $currentTax['tax_type'] === 'exclusive') ? 'checked' : '' ?>>
                                Exclusive (added on top)
                            </label>
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
