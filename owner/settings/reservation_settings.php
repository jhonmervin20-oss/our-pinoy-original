<?php
/**
 * owner/settings/reservation_settings.php (admin/owner only)
 *
 * Everything specific to the Reservation module lives on this one page:
 *   1. General reservation settings   (system_settings key/value rows)
 *   2. Payment gateway                (PayMongo credentials)
 *
 * All settings here are reservation-only. The cashier/POS system does not
 * read any of this — it accepts Cash and GCash only, selected manually by
 * staff, with no PayMongo API call involved.
 */

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../config/flash.php';
// For paymongoKeyIsTestMode(): the gateway form derives test-vs-live
// from the secret key itself rather than storing it independently.
require_once __DIR__ . '/../../config/paymongo.php';

Session::start();

if (!Session::isLoggedIn()) {
    header('Location: ../../auth/login.php');
    exit;
}
if (!Session::hasRole('owner')) {
    header('Location: ../../auth/login.php');
    exit;
}

$db = Database::getInstance()->getConnection();

// ISO weekday numbers (1=Mon..7=Sun) — matches PHP's date('N', ...) with
// zero conversion needed, and how operating_days is stored/read by the
// customer-facing booking wizard.
const WEEKDAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

const GENERAL_KEYS = [
    'total_capacity'                    => ['label' => 'Total seating capacity (pax)', 'type' => 'int'],
    'reservation_min_lead_hours'        => ['label' => 'Minimum lead time (hours)', 'type' => 'int'],
    'reservation_max_advance_days'      => ['label' => 'Maximum advance booking (days)', 'type' => 'int'],
    'reservation_hold_minutes'          => ['label' => 'Unpaid hold duration (minutes)', 'type' => 'int'],
    'reservation_no_show_hours'         => ['label' => 'No-show threshold (hours)', 'type' => 'int'],
    'reservation_min_guests'            => ['label' => 'Minimum pax per reservation', 'type' => 'int'],
    'reservation_max_guests'            => ['label' => 'Maximum pax per reservation', 'type' => 'int'],
    'reservation_fee_amount'            => ['label' => 'Reservation fee (₱)', 'type' => 'decimal'],
    'advance_order_min_amount'          => ['label' => 'Minimum advance order amount (₱)', 'type' => 'decimal'],
    'advance_order_deposit_percentage'  => ['label' => 'Advance order deposit (%)', 'type' => 'decimal'],
];

/* ---------------------------------------------------------------------
 * POST handling — one page, two possible actions
 * ------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        flash_set('error', 'Your session expired. Please try again.');
        header('Location: reservation_settings.php');
        exit;
    }

    $action = $_POST['form_action'] ?? '';

    // --- 1. General reservation settings -------------------------------
    if ($action === 'save_general') {
        try {
            $db->beginTransaction();
            $stmt = $db->prepare('UPDATE system_settings SET setting_value = ?, updated_by = ? WHERE setting_key = ?');
            foreach (GENERAL_KEYS as $key => $meta) {
                $raw = trim($_POST[$key] ?? '');
                if ($raw === '' || !is_numeric($raw) || $raw < 0) {
                    throw new InvalidArgumentException($meta['label'] . ' must be a valid positive number.');
                }
                $value = $meta['type'] === 'int' ? (string)(int)$raw : number_format((float)$raw, 2, '.', '');
                $stmt->execute([$value, Session::getUserId(), $key]);
            }

            if ((int)($_POST['reservation_max_guests'] ?? 0) < (int)($_POST['reservation_min_guests'] ?? 0)) {
                throw new InvalidArgumentException('Maximum pax must be greater than or equal to the minimum pax.');
            }

            $operatingDays = array_values(array_intersect(
                array_map('intval', $_POST['operating_days'] ?? []),
                array_keys(WEEKDAYS)
            ));
            if (empty($operatingDays)) {
                throw new InvalidArgumentException('Select at least one operating day.');
            }
            sort($operatingDays);
            $stmt->execute([implode(',', $operatingDays), Session::getUserId(), 'operating_days']);


            $db->commit();
            flash_set('success', 'General reservation settings saved.');
        } catch (InvalidArgumentException $e) {
            $db->rollBack();
            flash_set('error', $e->getMessage());
        } catch (PDOException $e) {
            $db->rollBack();
            flash_set('error', 'Could not save settings. Please try again.');
        }
        header('Location: reservation_settings.php#general');
        exit;
    }

    // --- 2. Time slots ---------------------------------------------------
    if ($action === 'save_time_slot') {
        $slotId    = (int)($_POST['slot_id'] ?? 0);
        $slotLabel = trim($_POST['slot_label'] ?? '');
        $startTime = trim($_POST['start_time'] ?? '');
        $endTime   = trim($_POST['end_time'] ?? '');
        $isActive  = isset($_POST['is_active']) ? 1 : 0;

        $error = null;
        if ($slotLabel === '' || mb_strlen($slotLabel) > 50) {
            $error = 'Please enter a slot label (up to 50 characters).';
        } elseif (!preg_match('/^\d{2}:\d{2}$/', $startTime) || !preg_match('/^\d{2}:\d{2}$/', $endTime)) {
            $error = 'Please enter a valid start and end time.';
        } elseif ($startTime >= $endTime) {
            $error = 'Start time must be before end time.';
        }

        if ($error) {
            flash_set('error', $error);
        } else {
            try {
                if ($slotId > 0) {
                    $stmt = $db->prepare(
                        'UPDATE time_slots SET slot_label = ?, start_time = ?, end_time = ?, is_active = ? WHERE slot_id = ?'
                    );
                    $stmt->execute([$slotLabel, $startTime, $endTime, $isActive, $slotId]);
                    flash_set('success', 'Time slot updated.');
                } else {
                    $stmt = $db->prepare(
                        'INSERT INTO time_slots (slot_label, start_time, end_time, is_active) VALUES (?, ?, ?, 1)'
                    );
                    $stmt->execute([$slotLabel, $startTime, $endTime]);
                    flash_set('success', 'Time slot added.');
                }
            } catch (PDOException $e) {
                error_log('reservation_settings.php save_time_slot failed: ' . $e->getMessage());
                flash_set('error', 'Something went wrong saving that time slot. Please try again.');
            }
        }
        header('Location: reservation_settings.php#time-slots');
        exit;
    }

    if ($action === 'toggle_time_slot') {
        $slotId = (int)($_POST['slot_id'] ?? 0);
        $db->prepare('UPDATE time_slots SET is_active = NOT is_active WHERE slot_id = ?')->execute([$slotId]);
        flash_set('success', 'Time slot status updated.');
        header('Location: reservation_settings.php#time-slots');
        exit;
    }

    // --- 3. Closed dates (one-off blackouts) --------------------------------
    if ($action === 'add_blackout') {
        $blackoutDate = trim($_POST['blackout_date'] ?? '');
        $reason       = trim($_POST['reason'] ?? '');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $blackoutDate)) {
            flash_set('error', 'Please choose a valid date.');
        } elseif (mb_strlen($reason) > 255) {
            flash_set('error', 'Reason must be 255 characters or fewer.');
        } else {
            try {
                $stmt = $db->prepare(
                    'INSERT INTO reservation_blackouts (blackout_date, reason, created_by) VALUES (?, ?, ?)'
                );
                $stmt->execute([$blackoutDate, $reason !== '' ? $reason : null, Session::getUserId()]);
                flash_set('success', 'Closed date added.');
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    flash_set('error', 'That date is already marked as closed.');
                } else {
                    error_log('reservation_settings.php add_blackout failed: ' . $e->getMessage());
                    flash_set('error', 'Something went wrong adding that closed date. Please try again.');
                }
            }
        }
        header('Location: reservation_settings.php#closed-dates');
        exit;
    }

    if ($action === 'delete_blackout') {
        $blackoutId = (int)($_POST['blackout_id'] ?? 0);
        $db->prepare('DELETE FROM reservation_blackouts WHERE blackout_id = ?')->execute([$blackoutId]);
        flash_set('success', 'Closed date removed.');
        header('Location: reservation_settings.php#closed-dates');
        exit;
    }

    // --- 4. Payment gateway -----------------------------------------------
    if ($action === 'save_gateway') {
        $gatewayId = (int)($_POST['gateway_id'] ?? 0);
        $publicKey = trim($_POST['public_key'] ?? '');
        $newSecret = trim($_POST['secret_key_new'] ?? '');

        // The key that will be in force after this save -- the new one if the
        // owner is replacing it, otherwise whatever is already stored. Test vs
        // live is read off that key's prefix; see paymongoKeyIsTestMode().
        $effectiveSecret = $newSecret !== ''
            ? $newSecret
            : (string)($db->query(
                'SELECT secret_key FROM payment_gateway_settings WHERE gateway_id = ' . (int)$gatewayId
              )->fetchColumn() ?: '');

        $publicMode = paymongoKeyIsTestMode($publicKey);
        $secretMode = paymongoKeyIsTestMode($effectiveSecret);

        $gatewayError = null;
        if ($gatewayId <= 0) {
            $gatewayError = 'No payment gateway configuration to update.';
        } elseif ($publicKey === '') {
            $gatewayError = 'Public key cannot be empty.';
        } elseif (!str_starts_with($publicKey, 'pk_')) {
            $gatewayError = 'That does not look like a PayMongo public key — it should start with pk_test_ or pk_live_.';
        } elseif ($newSecret !== '' && !str_starts_with($newSecret, 'sk_')) {
            // Pasting the public key into the secret field is the easy mistake,
            // and it fails later as an opaque 401 from PayMongo.
            $gatewayError = 'That does not look like a PayMongo secret key — it should start with sk_test_ or sk_live_.';
        } elseif ($publicMode !== null && $secretMode !== null && $publicMode !== $secretMode) {
            // The exact misconfiguration that sends payments to the test
            // dashboard while everything else looks live.
            $gatewayError = 'Your public key is ' . ($publicMode ? 'TEST' : 'LIVE')
                . ' but your secret key is ' . ($secretMode ? 'TEST' : 'LIVE')
                . '. Both must be from the same PayMongo environment, or payments will not appear where you expect them.';
        }

        if ($gatewayError !== null) {
            flash_set('error', $gatewayError);
        } else {
            $columns = ['public_key = ?', 'updated_by = ?'];
            $params  = [$publicKey, Session::getUserId()];

            if ($newSecret !== '') {
                $columns[] = 'secret_key = ?';
                $params[]  = $newSecret;
            }
            $newWebhook = trim($_POST['webhook_secret_new'] ?? '');
            if ($newWebhook !== '') {
                $columns[] = 'webhook_secret = ?';
                $params[]  = $newWebhook;
            }
            // Keep the stored flag in step with the key. It had no control on
            // this form and was never written, so it stayed on whatever it was
            // seeded with -- pasting live keys left the app running in test
            // mode, which is why live payments never showed up in PayMongo.
            if ($secretMode !== null) {
                $columns[] = 'is_test_mode = ?';
                $params[]  = $secretMode ? 1 : 0;
            }
            $params[] = $gatewayId;

            $sql  = 'UPDATE payment_gateway_settings SET ' . implode(', ', $columns) . ' WHERE gateway_id = ?';
            try {
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                flash_set(
                    'success',
                    'Payment gateway settings saved.'
                        . ($secretMode === null
                            ? ''
                            : ' Now running in ' . ($secretMode ? 'TEST' : 'LIVE') . ' mode.')
                );
            } catch (PDOException $e) {
                flash_set('error', 'Could not save settings. Please try again.');
            }
        }
        header('Location: reservation_settings.php#payment-gateway');
        exit;
    }

    // Unknown action
    flash_set('error', 'Unrecognized action.');
    header('Location: reservation_settings.php');
    exit;
}

/* ---------------------------------------------------------------------
 * Data fetch for GET render
 * ------------------------------------------------------------------- */
$generalRows = [];
$stmt = $db->prepare('SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN (' .
    implode(',', array_fill(0, count(GENERAL_KEYS), '?')) . ')');
$stmt->execute(array_keys(GENERAL_KEYS));
foreach ($stmt->fetchAll() as $row) {
    $generalRows[$row['setting_key']] = $row['setting_value'];
}

$extraSettingsStmt = $db->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('operating_days')");
$extraSettingsStmt->execute();
$extraSettings = [];
foreach ($extraSettingsStmt->fetchAll() as $row) {
    $extraSettings[$row['setting_key']] = $row['setting_value'];
}
$currentOperatingDays = array_filter(array_map('intval', explode(',', $extraSettings['operating_days'] ?? '1,2,3,4,5,6,7')));

$blackoutDates = $db->query('SELECT blackout_id, blackout_date, reason FROM reservation_blackouts ORDER BY blackout_date')->fetchAll();

$timeSlots = $db->query('SELECT slot_id, slot_label, start_time, end_time, is_active FROM time_slots ORDER BY start_time')->fetchAll();

$editingSlotId = isset($_GET['edit_slot_id']) ? (int)$_GET['edit_slot_id'] : null;
$editingSlot   = null;
if ($editingSlotId) {
    foreach ($timeSlots as $s) {
        if ((int)$s['slot_id'] === $editingSlotId) {
            $editingSlot = $s;
            break;
        }
    }
}

$gateway = $db->query('SELECT * FROM payment_gateway_settings ORDER BY gateway_id DESC LIMIT 1')->fetch();

/**
 * Render the write-only control for one stored secret.
 *
 * There is deliberately no "reveal" control. The full secret is never sent to
 * the browser, so an eye toggle could only ever swap one mask for another --
 * it looked like it would show the key and then didn't. The standard treatment
 * for a write-only credential (Stripe, PayMongo's own dashboard, GitHub) is a
 * permanent last-4 fingerprint you can check the stored key against, plus a
 * way to overwrite it. That's what this renders.
 */
function renderSecretControl(string $name, ?string $value): void
{
    $isSet = ($value !== null && $value !== '');
    ?>
    <div data-secret-field>
        <?php if ($isSet): ?>
            <div class="owner-secret-field" data-secret-display>
                <code title="Only the last 4 characters of the saved key are ever shown."><?= htmlspecialchars(str_repeat('•', 8) . substr($value, -4)) ?></code>
                <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" data-replace-toggle>Replace</button>
            </div>
        <?php endif; ?>
        <div class="owner-secret-field" data-secret-edit <?= $isSet ? 'hidden' : '' ?>>
            <input
                type="password"
                name="<?= htmlspecialchars($name) ?>_new"
                class="owner-input"
                placeholder="<?= $isSet ? 'Enter the replacement key' : 'Not set yet — paste the key here' ?>"
                autocomplete="off"
            >
            <?php if ($isSet): ?>
                <button type="button" class="owner-btn owner-btn-secondary owner-btn-sm" data-replace-cancel>Cancel</button>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

$activePage         = 'settings';
$activeSettingsTab  = 'reservation_settings';
$pageTitle          = 'Reservation settings';
$ownerBase          = '../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reservation settings | Owner Panel | OPO! Our Pinoy Original</title>
<link rel="icon" type="image/x-icon" href="../../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/owner-panel.css?v=<?= filemtime(__DIR__ . '/../assets/css/owner-panel.css') ?>">
<style>
</style>
</head>
<body>

<div class="owner-shell">

    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <div class="owner-main">

        <?php require_once __DIR__ . '/../includes/header.php'; ?>

        <main class="owner-content">
            <?= flash_render() ?>
            <?php require __DIR__ . '/includes/settings_nav.php'; ?>

            <!-- ============ 1. GENERAL ============ -->
            <div class="owner-card" id="general" style="margin-bottom:24px;">
                <h2 class="owner-card-title">Reservation settings</h2>
                <p class="owner-card-subtitle">Limits, fees, and operating days the online booking wizard enforces on every reservation.</p>
                <form method="POST" action="reservation_settings.php#general">
                    <?= csrf_field() ?>
                    <input type="hidden" name="form_action" value="save_general">

                    <div class="owner-form-grid">
                        <?php foreach (GENERAL_KEYS as $key => $meta): ?>
                            <div class="owner-form-group">
                                <label for="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($meta['label']) ?></label>
                                <input
                                    type="number"
                                    step="<?= $meta['type'] === 'int' ? '1' : '0.01' ?>"
                                    min="0"
                                    id="<?= htmlspecialchars($key) ?>"
                                    name="<?= htmlspecialchars($key) ?>"
                                    class="owner-input"
                                    value="<?= htmlspecialchars($generalRows[$key] ?? '') ?>"
                                    required
                                >
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php /* The "Allow customers to place an advance food order during
                             booking" checkbox was removed: advance food ordering is a
                             core feature of this system, not an option, so it is always
                             on. The two settings that shape it -- minimum order amount
                             and deposit percentage -- remain configurable above. */ ?>

                    <div class="owner-form-group">
                        <label>Operating days</label>
                        <div style="display:flex;gap:14px;flex-wrap:wrap;">
                            <?php foreach (WEEKDAYS as $num => $label): ?>
                                <label class="owner-checkbox-row owner-checkbox-inline">
                                    <input type="checkbox" name="operating_days[]" value="<?= $num ?>" class="owner-checkbox-input" <?= in_array($num, $currentOperatingDays, true) ? 'checked' : '' ?>>
                                    <span class="owner-checkbox-box" aria-hidden="true"><i class="ph ph-check"></i></span>
                                    <span class="owner-checkbox-label"><?= htmlspecialchars($label) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div style="margin-top:20px;">
                        <button type="submit" class="owner-btn owner-btn-primary">
                            <i class="ph ph-check" aria-hidden="true"></i> Save changes
                        </button>
                    </div>
                </form>
            </div>

            <!-- ============ 2. TIME SLOTS ============ -->
            <div class="owner-card" id="time-slots" style="margin-bottom:24px;">
                <h2 class="owner-card-title">Time slots</h2>
                <p class="owner-card-subtitle">The bookable windows customers choose from. Inactive slots disappear from the booking calendar.</p>
                <?php if ($editingSlot): ?>
                    <form method="POST" action="reservation_settings.php#time-slots" id="editSlotForm">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form_action" value="save_time_slot">
                        <input type="hidden" name="slot_id" value="<?= (int)$editingSlot['slot_id'] ?>">
                    </form>
                <?php endif; ?>

                <div class="owner-table-wrap">
                    <table class="owner-table">
                        <thead>
                            <tr>
                                <th>Label</th>
                                <th>Start</th>
                                <th>End</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$timeSlots): ?>
                                <tr><td colspan="5" class="owner-table-empty">No time slots yet.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($timeSlots as $slot): ?>
                                <?php if ($editingSlotId === (int)$slot['slot_id']): ?>
                                    <tr class="owner-inline-edit-row">
                                        <td><input type="text" name="slot_label" form="editSlotForm" class="owner-input" value="<?= htmlspecialchars($slot['slot_label']) ?>" maxlength="50" required></td>
                                        <td><input type="time" name="start_time" form="editSlotForm" class="owner-input" value="<?= htmlspecialchars(substr($slot['start_time'], 0, 5)) ?>" required></td>
                                        <td><input type="time" name="end_time" form="editSlotForm" class="owner-input" value="<?= htmlspecialchars(substr($slot['end_time'], 0, 5)) ?>" required></td>
                                        <td>
                                            <label class="owner-toggle">
                                                <input type="checkbox" name="is_active" form="editSlotForm" <?= $slot['is_active'] ? 'checked' : '' ?>>
                                                <span class="owner-toggle-track"></span>
                                            </label>
                                        </td>
                                        <td class="owner-table-actions">
                                            <button type="submit" form="editSlotForm" class="owner-btn owner-btn-primary owner-btn-sm"><i class="ph ph-check" aria-hidden="true"></i></button>
                                            <a href="reservation_settings.php#time-slots" class="owner-btn owner-btn-secondary owner-btn-sm"><i class="ph ph-x" aria-hidden="true"></i></a>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <tr>
                                        <td><?= htmlspecialchars($slot['slot_label']) ?></td>
                                        <td><?= htmlspecialchars(substr($slot['start_time'], 0, 5)) ?></td>
                                        <td><?= htmlspecialchars(substr($slot['end_time'], 0, 5)) ?></td>
                                        <td>
                                            <form method="POST" action="reservation_settings.php#time-slots">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="form_action" value="toggle_time_slot">
                                                <input type="hidden" name="slot_id" value="<?= (int)$slot['slot_id'] ?>">
                                                <label class="owner-toggle" aria-label="Toggle active">
                                                    <input type="checkbox" <?= $slot['is_active'] ? 'checked' : '' ?> onchange="this.form.submit()">
                                                    <span class="owner-toggle-track"></span>
                                                </label>
                                            </form>
                                        </td>
                                        <td class="owner-table-actions">
                                            <a href="reservation_settings.php?edit_slot_id=<?= (int)$slot['slot_id'] ?>#time-slots" class="owner-btn owner-btn-secondary owner-btn-icon owner-btn-sm" aria-label="Edit">
                                                <i class="ph ph-pencil-simple" aria-hidden="true"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <form method="POST" action="reservation_settings.php#time-slots" style="margin-top:18px;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="form_action" value="save_time_slot">
                    <div class="owner-form-grid">
                        <div class="owner-form-group">
                            <label for="new_slot_label">Label</label>
                            <input type="text" id="new_slot_label" name="slot_label" class="owner-input" maxlength="50" placeholder="9:00 PM - 10:00 PM" required>
                        </div>
                        <div class="owner-form-group">
                            <label for="new_slot_start">Start time</label>
                            <input type="time" id="new_slot_start" name="start_time" class="owner-input" required>
                        </div>
                        <div class="owner-form-group">
                            <label for="new_slot_end">End time</label>
                            <input type="time" id="new_slot_end" name="end_time" class="owner-input" required>
                        </div>
                    </div>
                    <div style="margin-top:14px;">
                        <button type="submit" class="owner-btn owner-btn-primary owner-btn-sm">
                            <i class="ph ph-plus" aria-hidden="true"></i> Add time slot
                        </button>
                    </div>
                </form>
            </div>

            <!-- ============ 3. CLOSED DATES ============ -->
            <div class="owner-card" id="closed-dates" style="margin-bottom:24px;">
                <h2 class="owner-card-title">Closed dates</h2>
                <p class="owner-card-subtitle">One-off closures such as holidays or private events — separate from the weekly operating days above.</p>
                <div class="owner-table-wrap">
                    <table class="owner-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Reason</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$blackoutDates): ?>
                                <tr><td colspan="3" class="owner-table-empty">No closed dates yet.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($blackoutDates as $b): ?>
                                <tr>
                                    <td><?= htmlspecialchars(date('M j, Y', strtotime($b['blackout_date']))) ?></td>
                                    <td><?= $b['reason'] !== null && $b['reason'] !== '' ? htmlspecialchars($b['reason']) : '&mdash;' ?></td>
                                    <td class="owner-table-actions">
                                        <form method="POST" action="reservation_settings.php#closed-dates"
                                              data-confirm="Remove this closed date? Customers will be able to book it again.">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="form_action" value="delete_blackout">
                                            <input type="hidden" name="blackout_id" value="<?= (int)$b['blackout_id'] ?>">
                                            <button type="submit" class="owner-btn owner-btn-danger owner-btn-icon owner-btn-sm" aria-label="Remove">
                                                <i class="ph ph-trash" aria-hidden="true"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <form method="POST" action="reservation_settings.php#closed-dates" style="margin-top:18px;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="form_action" value="add_blackout">
                    <div class="owner-form-grid">
                        <div class="owner-form-group">
                            <label for="new_blackout_date">Date</label>
                            <input type="date" id="new_blackout_date" name="blackout_date" class="owner-input" required>
                        </div>
                        <div class="owner-form-group owner-form-group-full">
                            <label for="new_blackout_reason">Reason <span class="owner-form-optional">(optional)</span></label>
                            <input type="text" id="new_blackout_reason" name="reason" class="owner-input" maxlength="255" placeholder="e.g. Christmas Day">
                        </div>
                    </div>
                    <div style="margin-top:14px;">
                        <button type="submit" class="owner-btn owner-btn-primary owner-btn-sm">
                            <i class="ph ph-plus" aria-hidden="true"></i> Add closed date
                        </button>
                    </div>
                </form>
            </div>

            <!-- ============ 4. PAYMENT GATEWAY ============ -->
            <div class="owner-card" id="payment-gateway">
                <h2 class="owner-card-title">Payment gateway</h2>
                <p class="owner-card-subtitle">PayMongo keys used only for online reservation fees and advance order deposits. The cashier's POS records Cash and GCash manually and never calls this gateway. Saved secrets are write-only &mdash; only their last 4 characters are ever shown. Test or live mode is taken from the keys themselves, so both keys must come from the same PayMongo environment.</p>
                <?php if (!$gateway): ?>
                    <div class="owner-alert owner-alert-error">
                        <i class="ph ph-warning-circle" aria-hidden="true"></i>
                        <span>No payment gateway configuration exists yet.</span>
                    </div>
                <?php else: ?>
                    <?php
                        // Test vs live is read off the keys themselves (never from
                        // the stored flag), so the mismatch check below cannot
                        // disagree with which environment a payment actually hits.
                        $gwSecretMode = paymongoKeyIsTestMode((string)($gateway['secret_key'] ?? ''));
                        $gwPublicMode = paymongoKeyIsTestMode((string)($gateway['public_key'] ?? ''));
                        $gwMismatch   = $gwSecretMode !== null && $gwPublicMode !== null && $gwSecretMode !== $gwPublicMode;
                    ?>

                    <?php if ($gwMismatch): ?>
                        <div class="owner-alert owner-alert-error" style="margin-bottom:16px;">
                            <i class="ph ph-warning-circle" aria-hidden="true"></i>
                            <span>
                                <strong>Your keys are from different environments.</strong>
                                The public key is <?= $gwPublicMode ? 'TEST' : 'LIVE' ?> but the secret key is
                                <?= $gwSecretMode ? 'TEST' : 'LIVE' ?>. Checkout is created with the <em>secret</em> key, so
                                payments are going to your <?= $gwSecretMode ? 'Test' : 'Live' ?> dashboard regardless of the
                                public key. Replace both with a matching pair.
                            </span>
                        </div>
                    <?php endif; ?>
                    <form method="POST" action="reservation_settings.php#payment-gateway">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form_action" value="save_gateway">
                        <input type="hidden" name="gateway_id" value="<?= (int)$gateway['gateway_id'] ?>">

                        <div class="owner-form-grid">
                            <div class="owner-form-group">
                                <label for="public_key">Public key</label>
                                <input type="text" id="public_key" name="public_key" class="owner-input" value="<?= htmlspecialchars($gateway['public_key'] ?? '') ?>" autocomplete="off" required>
                            </div>
                            <div class="owner-form-group">
                                <label>Secret key</label>
                                <?php renderSecretControl('secret_key', $gateway['secret_key']) ?>
                            </div>
                            <div class="owner-form-group">
                                <label>Webhook secret</label>
                                <?php renderSecretControl('webhook_secret', $gateway['webhook_secret']) ?>
                            </div>
                        </div>

                        <div style="margin-top:20px;">
                            <button type="submit" class="owner-btn owner-btn-primary">
                                <i class="ph ph-check" aria-hidden="true"></i> Save changes
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>

        </main>

    </div>

</div>

<script src="../assets/js/confirm-modal.js?v=<?= filemtime(__DIR__ . '/../assets/js/confirm-modal.js') ?>"></script>
<script>
document.querySelectorAll('[data-secret-field]').forEach((field) => {
    const display    = field.querySelector('[data-secret-display]');
    const edit       = field.querySelector('[data-secret-edit]');
    const replaceBtn = field.querySelector('[data-replace-toggle]');
    const cancelBtn  = field.querySelector('[data-replace-cancel]');

    replaceBtn?.addEventListener('click', () => {
        display.hidden = true;
        edit.hidden = false;
        edit.querySelector('input').focus();
    });

    cancelBtn?.addEventListener('click', () => {
        edit.hidden = true;
        display.hidden = false;
        edit.querySelector('input').value = '';
    });
});
</script>
</body>
</html>