<?php
/**
 * owner/settings/purchasing_forecast.php
 *
 * Settings > Purchasing & Forecasting -- the ONLY place values are edited.
 *
 * The Demand Forecasting page shows numbers and changes nothing; this page
 * changes things and shows no charts. Keeping them apart is worth doing for a
 * reason that fits in one line: a manager checking tomorrow's demand should not
 * be one mis-click away from changing how the system buys food.
 *
 * Nothing in the forecasting pipeline is hardcoded -- forecasting/config.py
 * reads every one of these keys. That claim is only true because this page
 * exists, so if a threshold is added to the pipeline it must be added here too.
 */

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../config/flash.php';

Session::start();

if (!Session::isLoggedIn() || !Session::hasRole(['owner', 'manager'])) {
    header('Location: ../../auth/login.php');
    exit;
}

$db = Database::getInstance()->getConnection();

/**
 * The whole screen, declared once.
 *
 *   key => [label, type, default, reason, extra]
 *
 * type: int | dec | pct | money | select | hour | bool
 * Declaring rather than hand-writing 36 fields keeps the validation, the
 * rendering reading from ONE list, so they cannot drift apart.
 */
$GROUPS = [
    'A · Automation' => [
        'auto_po_enabled'             => ['Create purchase orders for me', 'bool', '1', 'When on, the system prepares draft orders for anything running low. You still review and approve every one — nothing is sent to a supplier on its own. Turn this off and you will still see what needs ordering; you just write the orders yourself.', []],
        'auto_po_sweep_hour'          => ['What time to check stock each day', 'hour', '2', 'The nightly stock check runs at this hour, so the draft orders are ready before you start the day. Early morning is best — the forecast finishes first.', []],
        'auto_po_draft_reminder_days' => ['Remind me about unapproved orders after', 'int', '1', 'An order sitting unapproved still makes its ingredients look handled, so nothing else gets ordered for them. This reminds you before that becomes a shortage.', ['min' => 1, 'max' => 30, 'suffix' => 'days']],
    ],
    'B · Planning' => [
        'forecast_horizon_days' => ['Buy enough stock to last', 'select', '7', 'The planning horizon. Every order is sized to cover this many days: the wait for the supplier to deliver, plus the days until the next stock check. A longer horizon means fewer, larger orders and more cash tied up in stock; a shorter one means smaller, more frequent orders and less room for error if a delivery is late.', ['options' => [7 => '7 days', 14 => '14 days']]],
    ],
    'C · Warnings' => [
        'shortage_notification_horizon_days' => ['Warn me about shortages this far ahead', 'int', '3', 'How much notice you get before an ingredient is expected to run out. Longer gives you more time to react, but flags things earlier that may sort themselves out. Cannot look further ahead than the planning horizon above.', ['min' => 1, 'max' => 30, 'suffix' => 'days']],
    ],
    'D · Display' => [
        'forecast_default_horizon' => ['How far ahead the forecast page shows', 'select', '7', 'The period Demand Forecasting opens on. You can still switch between 7 and 14 days on the page itself — this only sets the starting view.', ['options' => [7 => 'Next 7 days', 14 => 'Next 14 days']]],
    ],
];

/**
 * Deliberately NOT on this page.
 *
 * These still live in `system_settings` and the pipeline still reads every one
 * of them -- forecasting/config.py's rule that no threshold is hardcoded is
 * unchanged. What changed is that they are no longer presented as choices an
 * owner should make, because they are not: each one describes how the MODEL
 * works, and moving it without understanding the model silently changes what
 * the forecast means.
 *
 *   forecast_history_days   90    the training window
 *   closure_detect_pct      20    the closure rule's threshold
 *   mix_window_weeks         8    how far back the menu mix is measured
 *   mix_direct_fit_min_qty   3.0  when a dish gets its own model
 *   review_period_days       1    tied to the sweep running daily -- changing
 *                                 this without changing the schedule makes the
 *                                 coverage window wrong
 *   business_day_cutoff_time 04:00 when a trading day ends
 *
 * To change one, edit the row in `system_settings` directly. Defaults are
 * documented in forecasting/config.py.
 *
 * The nine `trend_*` rows were removed from this page on 2026-09-01 for a
 * different reason: Trend Setter is not implemented. `forecasting/trend/` does
 * not exist and both of its tables are empty, so those fields offered control
 * over something that never runs. Stage 5 still reads `demand_adjustments`, so
 * the extension point is real -- the detector that would populate it is not.
 */
const BACKEND_ONLY_KEYS = [
    'forecast_history_days', 'closure_detect_pct',
    'mix_window_weeks', 'mix_direct_fit_min_qty', 'review_period_days',
    'business_day_cutoff_time',
];

/** Flat key => spec, for validation and defaults. */
$FIELDS = [];
foreach ($GROUPS as $fields) {
    $FIELDS += $fields;
}

// ------------------------------------------------------------------ save ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        flash_set('error', 'Your session expired. Please try again.');
        header('Location: purchasing_forecast.php');
        exit;
    }

    $errors = [];
    $values = [];

    foreach ($FIELDS as $key => [$label, $type, $default, $reason, $extra]) {
        if ($type === 'bool') {
            $values[$key] = isset($_POST[$key]) ? '1' : '0';
            continue;
        }

        $raw = trim((string)($_POST[$key] ?? ''));
        if ($raw === '') {
            // A field marked `optional` treats blank as "no limit" rather than
            // as a mistake. Opt-in per field: every other setting here still
            // has to carry a value, because a blank threshold silently turning
            // a rule off is exactly the kind of thing nobody notices.
            if (!empty($extra['optional'])) {
                $values[$key] = '';
                continue;
            }
            $errors[] = "{$label} cannot be empty.";
            continue;
        }

        if ($type === 'select') {
            if (!array_key_exists((int)$raw, $extra['options'])) {
                $errors[] = "{$label} is not one of the allowed choices.";
                continue;
            }
            $values[$key] = (string)(int)$raw;
            continue;
        }

        if (!is_numeric($raw)) {
            $errors[] = "{$label} must be a number.";
            continue;
        }

        $num = (float)$raw;
        if (isset($extra['min']) && $num < $extra['min']) {
            $errors[] = "{$label} cannot be below {$extra['min']}.";
            continue;
        }
        if (isset($extra['max']) && $num > $extra['max']) {
            $errors[] = "{$label} cannot be above {$extra['max']}.";
            continue;
        }
        $values[$key] = in_array($type, ['int', 'pct', 'hour'], true)
            ? (string)(int)$num
            : rtrim(rtrim(number_format($num, 3, '.', ''), '0'), '.');
    }

    // Cross-field rules, checked after the individual ones so the message can
    // name both values.
    if (isset($values['shortage_notification_horizon_days'], $values['forecast_horizon_days'])
        && (int)$values['shortage_notification_horizon_days'] > (int)$values['forecast_horizon_days']) {
        $errors[] = 'Shortage warning cannot look further ahead than the forecast itself.';
    }

    /* The planning horizon has to outlast the wait for a delivery. The policy
       engine sizes an order as lead-time demand + review-period demand, where
       review_period = horizon - lead_time; if a supplier's lead time meets or
       exceeds the horizon that review period clamps to zero, so the order only
       ever covers the wait itself and the shelf is empty the day it lands. This
       fails loudly at save time instead of quietly under-ordering every night. */
    if (isset($values['forecast_horizon_days'])) {
        $horizon = (int)$values['forecast_horizon_days'];
        $slowest = $db->query(
            "SELECT MAX(i.lead_time_days) FROM inventory_items i
              WHERE i.is_active = 1 AND i.preferred_supplier_id IS NOT NULL"
        )->fetchColumn();
        $slowest = (int)($slowest ?: 0);
        if ($slowest > 0 && $horizon <= $slowest) {
            $errors[] = "A {$horizon}-day horizon is not longer than your slowest supplier lead time ({$slowest} days), "
                      . "so those orders would cover the wait for delivery and nothing beyond it. "
                      . "Choose a longer horizon, or shorten that item's lead time.";
        }
    }

    if (isset($values['trend_min_delta_pct'], $values['trend_max_delta_pct'])
        && (float)$values['trend_min_delta_pct'] > (float)$values['trend_max_delta_pct']) {
        $errors[] = 'The smallest Trend Setter adjustment cannot be larger than the largest.';
    }

    if ($errors) {
        flash_set('error', implode(' ', array_slice($errors, 0, 3)));
    } else {
        try {
            $stmt = $db->prepare('UPDATE system_settings SET setting_value = ?, updated_by = ? WHERE setting_key = ?');
            foreach ($values as $key => $val) {
                $stmt->execute([$val, Session::getUserId(), $key]);
            }
            flash_set('success', 'Settings saved. The next forecast run uses them.');
        } catch (PDOException $e) {
            error_log('purchasing_forecast.php save failed: ' . $e->getMessage());
            flash_set('error', 'Something went wrong saving these settings. Please try again.');
        }
    }

    header('Location: purchasing_forecast.php');
    exit;
}

// ------------------------------------------------------------------ read ----
$stored = $db->query('SELECT setting_key, setting_value FROM system_settings')->fetchAll(PDO::FETCH_KEY_PAIR);
$val = fn(string $k) => (string)($stored[$k] ?? $FIELDS[$k][2]);

$activePage        = 'settings';
$activeSettingsTab = 'purchasing_forecast';
$pageTitle         = 'Purchasing & Forecasting';
$ownerBase         = '../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Purchasing &amp; Forecasting | Owner Panel | OPO! Our Pinoy Original</title>
<link rel="icon" type="image/x-icon" href="../../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
    /* Matches the other settings screens: owner-form-grid / owner-form-group,
       label above, hint below. This page briefly used a bespoke three-column
       key/input/reason table, which read as a different product. */
    .pf-section-note{font-size:0.82rem;color:var(--op-ink-soft);line-height:1.6;
        margin:0 0 14px;max-width:820px;}
    .pf-actions{display:flex;gap:10px;justify-content:flex-end;align-items:center;
        flex-wrap:wrap;margin-top:24px;}
</style>
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
                <h2 class="owner-card-title">Purchasing &amp; Forecasting</h2>
                <p class="owner-card-subtitle">
                    Everything the forecast and the automatic purchase order run on. 
                </p>

                <form method="POST" action="purchasing_forecast.php">
                    <?= csrf_field() ?>

                    <?php foreach ($GROUPS as $groupTitle => $fields): ?>
                        <div class="owner-form-section">
                            <h3 class="owner-form-section-title"><?= htmlspecialchars($groupTitle) ?></h3>

                            <?php
                                // Booleans get a full-width checkbox row like every other
                                // settings screen; the rest sit in the standard grid.
                                $checkboxes = array_filter($fields, fn($f) => $f[1] === 'bool');
                                $inputs     = array_filter($fields, fn($f) => $f[1] !== 'bool');
                            ?>

                            <?php foreach ($checkboxes as $key => [$label, $type, $default, $reason, $extra]): ?>
                                <div class="owner-form-group">
                                    <label class="owner-checkbox-row" for="<?= $key ?>">
                                        <input type="checkbox" id="<?= $key ?>" name="<?= $key ?>" value="1" class="owner-checkbox-input" <?= $val($key) === '1' ? 'checked' : '' ?>>
                                        <span class="owner-checkbox-box" aria-hidden="true"><i class="ph ph-check"></i></span>
                                        <span class="owner-checkbox-text">
                                            <span class="owner-checkbox-label"><?= htmlspecialchars($label) ?></span>
                                        </span>
                                    </label>
                                </div>
                            <?php endforeach; ?>

                            <div class="owner-form-grid">
                                <?php foreach ($inputs as $key => [$label, $type, $default, $reason, $extra]): ?>
                                    <div class="owner-form-group">
                                        <label for="<?= $key ?>">
                                            <?= htmlspecialchars($label) ?><?php if ($type === 'pct'): ?> (%)<?php endif; ?>
                                            <?php if (!empty($extra['suffix'])): ?> (<?= htmlspecialchars($extra['suffix']) ?>)<?php endif; ?>
                                            <?php if ($type === 'money'): ?> (&#8369;)<?php endif; ?>
                                        </label>

                                        <?php if ($type === 'select'): ?>
                                            <select name="<?= $key ?>" id="<?= $key ?>" class="owner-select" required>
                                                <?php foreach ($extra['options'] as $ov => $ol): ?>
                                                    <option value="<?= (int)$ov ?>" <?= (int)$val($key) === (int)$ov ? 'selected' : '' ?>><?= htmlspecialchars($ol) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php elseif ($type === 'hour'): ?>
                                            <select name="<?= $key ?>" id="<?= $key ?>" class="owner-select" required>
                                                <?php for ($h = 0; $h < 24; $h++): ?>
                                                    <option value="<?= $h ?>" <?= (int)$val($key) === $h ? 'selected' : '' ?>><?= date('g:i A', mktime($h, 0, 0)) ?></option>
                                                <?php endfor; ?>
                                            </select>
                                        <?php else: ?>
                                            <input type="number" id="<?= $key ?>" name="<?= $key ?>" class="owner-input"
                                                   value="<?= htmlspecialchars($val($key)) ?>"
                                                   step="<?= $type === 'dec' ? '0.01' : '1' ?>"
                                                   <?= isset($extra['min']) ? 'min="' . htmlspecialchars((string)$extra['min']) . '"' : '' ?>
                                                   <?= isset($extra['max']) ? 'max="' . htmlspecialchars((string)$extra['max']) . '"' : '' ?>
                                                   <?php // An optional field must be submittable empty -- `required` here
                                                         // would have the browser block the form and never say why. ?>
                                                   <?= empty($extra['optional']) ? 'required' : 'placeholder="No minimum"' ?>>
                                        <?php endif; ?>

                                    </div>
                                <?php endforeach; ?>
                            </div>

                        </div>
                    <?php endforeach; ?>

                    <div class="pf-actions">
                        <button type="submit" class="owner-btn owner-btn-primary">
                            <i class="ph ph-check" aria-hidden="true"></i> Save settings
                        </button>
                    </div>
                </form>

            </div>
        </main>
    </div>
</div>


</body>
</html>
