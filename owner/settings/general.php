<?php
/**
 * owner/settings/general.php
 *
 * Editable form over the site-wide system_settings rows that AREN'T
 * reservation-specific (those live on reservation_settings.php instead —
 * total_capacity, reservation_min_lead_hours, reservation_min_guests,
 * reservation_fee_amount, advance_order_min_amount,
 * advance_order_deposit_percentage). UPDATE only — this page never
 * inserts or removes a setting_key.
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

// Which HTML control each setting_key renders as, keyed server-side so the
// form can't be tricked into treating a value as a different type than
// intended just because the DB row's own content changed.
const FIELD_TYPES = [
    'restaurant_name' => 'text',
    'restaurant_address' => 'text',
    'restaurant_phone' => 'tel',
    'restaurant_email' => 'email',
    'restaurant_tin'  => 'text',
    'opening_time'    => 'time',
    'closing_time'    => 'time',
    'currency'         => 'text',
];

// Only for keys where the auto-generated ucfirst(str_replace('_', ' ', $key))
// label would read oddly (e.g. "Restaurant tin").
const LABEL_OVERRIDES = [
    'restaurant_tin' => 'TIN number',
    'restaurant_phone' => 'Contact phone number',
    'restaurant_email' => 'Contact email',
];

/**
 * Validate + move the uploaded GCash QR into owner/assets/uploads/payment/,
 * returning the web-relative path to store in system_settings.gcash_qr_path
 * (e.g. "assets/uploads/payment/gcash_qr_xxx.png"), or null if no file came
 * through. Mirrors handleMenuImageUpload() in menu_functions.php -- same
 * directory convention, same 2MB/type limits -- rather than inventing a
 * second upload style for one field.
 */
function handleGcashQrUpload(): ?string
{
    if (empty($_FILES['gcash_qr']['name'])) {
        return null;
    }

    $file = $_FILES['gcash_qr'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('QR image upload failed. Please try again.');
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        throw new RuntimeException('QR image must be 2MB or smaller.');
    }

    // mime_content_type sniffs the CONTENT, so renaming a .php to .png does not
    // get it past this -- the extension is then taken from our own map, never
    // from the uploaded filename.
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $mime    = mime_content_type($file['tmp_name']);
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('QR image must be a JPG, PNG, or WEBP file.');
    }

    $destDir = __DIR__ . '/../assets/uploads/payment/';
    if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
        throw new RuntimeException('Could not create the upload folder. Please try again.');
    }

    $filename = uniqid('gcash_qr_', true) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], $destDir . $filename)) {
        throw new RuntimeException('Could not save the QR image. Please try again.');
    }

    return 'assets/uploads/payment/' . $filename;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        flash_set('error', 'Your session expired. Please try again.');
    } else {
        $submitted = $_POST['setting'] ?? [];
        $clean     = [];
        $error     = null;

        foreach (FIELD_TYPES as $key => $type) {
            $value = trim((string)($submitted[$key] ?? ''));

            if ($type === 'number') {
                if ($value === '' || !ctype_digit($value)) {
                    $error = 'Please enter a whole number for every numeric field.';
                    break;
                }
            } elseif ($type === 'email') {
                if ($value === '' || !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $error = 'Please enter a valid contact email address.';
                    break;
                }
            } elseif ($type === 'tel') {
                if ($value === '' || !preg_match('/^[0-9+\-\s()]{7,20}$/', $value)) {
                    $error = 'Please enter a valid contact phone number.';
                    break;
                }
            } elseif ($type === 'time') {
                if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $value)) {
                    $error = 'Please enter a valid time for opening and closing time.';
                    break;
                }
                if (strlen($value) === 5) {
                    $value .= ':00';
                }
            } else {
                if ($value === '') {
                    $error = 'No field can be left blank.';
                    break;
                }
            }

            $clean[$key] = $value;
        }

        if ($error) {
            flash_set('error', $error);
        } else {
            try {
                // Outside the transaction on purpose: the file is already on disk
                // by the time the UPDATE runs, and a failed upload must abort
                // before anything is written rather than roll back a moved file.
                // Remove is its own submit button, so it can only arrive when the
                // owner clicked it. It wins outright: uploading AND removing in one
                // request would otherwise save the file the owner just asked to drop.
                $removeQr = isset($_POST['remove_gcash_qr']);
                $newQr    = $removeQr ? null : handleGcashQrUpload();

                $db->beginTransaction();
                $stmt = $db->prepare(
                    'UPDATE system_settings SET setting_value = ?, updated_by = ? WHERE setting_key = ?'
                );
                foreach ($clean as $key => $value) {
                    $stmt->execute([$value, Session::getUserId(), $key]);
                }

                if ($newQr !== null || $removeQr) {
                    $current = $db->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
                    $current->execute(['gcash_qr_path']);
                    $oldQr = (string)$current->fetchColumn();

                    $stmt->execute([$newQr ?? '', Session::getUserId(), 'gcash_qr_path']);

                    // Delete the superseded file only after the row points
                    // elsewhere, and only inside our own upload folder.
                    if ($oldQr !== '' && $oldQr !== $newQr && str_starts_with($oldQr, 'assets/uploads/payment/')) {
                        $oldPath = __DIR__ . '/../' . $oldQr;
                        if (is_file($oldPath)) {
                            @unlink($oldPath);
                        }
                    }
                }

                $db->commit();
                flash_set('success', 'General settings saved.');
            } catch (RuntimeException $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                flash_set('error', $e->getMessage());
            } catch (PDOException $e) {
                $db->rollBack();
                error_log('general.php save failed: ' . $e->getMessage());
                flash_set('error', 'Something went wrong saving these settings. Please try again.');
            }
        }
    }

    header('Location: general.php');
    exit;
}

$stmt = $db->prepare(
    'SELECT setting_id, setting_key, setting_value, description FROM system_settings
     WHERE setting_key IN (' . implode(',', array_fill(0, count(FIELD_TYPES), '?')) . ')
     ORDER BY setting_id'
);
$stmt->execute(array_keys(FIELD_TYPES));
$settings = $stmt->fetchAll();

$qrStmt = $db->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
$qrStmt->execute(['gcash_qr_path']);
$gcashQrPath = (string)$qrStmt->fetchColumn();

$activePage         = 'settings';
$activeSettingsTab  = 'general';
$pageTitle          = 'General settings';
$ownerBase          = '../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>General settings | Owner Panel | OPO! Our Pinoy Original</title>
<link rel="icon" type="image/x-icon" href="../../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/owner-panel.css?v=<?= filemtime(__DIR__ . '/../assets/css/owner-panel.css') ?>">
<style>
    .gen-qr-row{display:flex;gap:20px;align-items:flex-start;flex-wrap:wrap;}
    .gen-qr-preview{
        flex:0 0 auto;width:150px;height:150px;border-radius:var(--op-radius-sm);
        border:1px solid var(--op-border);background:var(--op-canvas);
        display:flex;align-items:center;justify-content:center;overflow:hidden;
    }
    .gen-qr-preview img{width:100%;height:100%;object-fit:contain;}
    .gen-qr-empty{display:flex;flex-direction:column;align-items:center;gap:6px;
        color:var(--op-ink-faint);font-size:0.75rem;}
    .gen-qr-empty i{font-size:1.6rem;}
    .gen-qr-controls{flex:1 1 260px;min-width:240px;}
    .gen-qr-note{font-size:0.8rem;color:var(--op-ink-soft);margin-bottom:12px;max-width:420px;}
    /* Visually hidden rather than display:none -- a display:none input cannot be
       focused, which would break keyboard access to the file picker. */
    .gen-qr-file{position:absolute;width:1px;height:1px;opacity:0;pointer-events:none;}
    .gen-qr-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
    .gen-qr-actions label{cursor:pointer;margin:0;}
    .gen-qr-file:focus-visible + .gen-qr-actions label{outline:2px solid var(--op-gold);outline-offset:2px;}
    .gen-qr-remove{color:var(--op-danger);border-color:rgba(207,90,68,0.35);}
    .gen-qr-remove:hover{background:var(--op-danger-soft);border-color:var(--op-danger);}
    .gen-qr-filename{margin-top:10px;font-size:0.76rem;color:var(--op-ink-faint);}
    .gen-qr-filename.is-picked{color:var(--op-success);font-weight:600;}
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

            <div class="owner-card">
                <h2 class="owner-card-title">General settings</h2>
                <p class="owner-card-subtitle">Core details used across the site — receipts, the booking site, and operating hours.</p>

                <form method="POST" action="general.php" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <div class="owner-form-grid">
                        <?php foreach ($settings as $row): ?>
                            <?php
                            $key   = $row['setting_key'];
                            $type  = FIELD_TYPES[$key] ?? 'text';
                            $value = $row['setting_value'] ?? '';
                            $inputValue = $type === 'time' ? substr($value, 0, 5) : $value;
                            $label = LABEL_OVERRIDES[$key] ?? ucfirst(str_replace('_', ' ', $key));
                            ?>
                            <div class="owner-form-group">
                                <label for="setting_<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></label>
                                <input
                                    type="<?= $type ?>"
                                    id="setting_<?= htmlspecialchars($key) ?>"
                                    name="setting[<?= htmlspecialchars($key) ?>]"
                                    value="<?= htmlspecialchars($inputValue) ?>"
                                    class="owner-input"
                                    <?= $type === 'number' ? 'min="0"' : '' ?>
                                    required
                                >
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="owner-form-section" style="margin-top:26px;">
                        <div class="owner-form-section-title">GCash QR</div>
                    </div>
                    <?php $hasQr = $gcashQrPath !== '' && is_file(__DIR__ . '/../' . $gcashQrPath); ?>
                    <div class="gen-qr-row">
                        <div class="gen-qr-preview">
                            <?php if ($hasQr): ?>
                                <img src="<?= htmlspecialchars('../' . $gcashQrPath) ?>?v=<?= filemtime(__DIR__ . '/../' . $gcashQrPath) ?>" alt="Current GCash QR">
                            <?php else: ?>
                                <span class="gen-qr-empty"><i class="ph ph-qr-code" aria-hidden="true"></i>No QR yet</span>
                            <?php endif; ?>
                        </div>
                        <div class="gen-qr-controls">
                            <p class="gen-qr-note">Shown on the cashier's screen for the customer to scan when GCash is the payment method.</p>

                            <?php // A <label for> opens the file picker natively, so the styled
                                  // trigger still works with JavaScript disabled; the filename
                                  // readout below is the only part that needs JS. ?>
                            <input type="file" id="gcash_qr" name="gcash_qr" class="gen-qr-file"
                                   accept="image/png,image/jpeg,image/webp">
                            <div class="gen-qr-actions">
                                <label for="gcash_qr" class="owner-btn owner-btn-secondary owner-btn-sm">
                                    <i class="ph ph-upload-simple" aria-hidden="true"></i>
                                    <?= $hasQr ? 'Replace image' : 'Choose image' ?>
                                </label>
                                <?php if ($hasQr): ?>
                                    <button type="submit" name="remove_gcash_qr" value="1"
                                            class="owner-btn owner-btn-secondary owner-btn-sm gen-qr-remove"
                                            onclick="return confirm('Remove the GCash QR? The cashier screen will stop showing it.');">
                                        <i class="ph ph-trash" aria-hidden="true"></i> Remove
                                    </button>
                                <?php endif; ?>
                            </div>
                            <p class="gen-qr-filename" id="gcashQrFileName">JPG, PNG or WEBP &middot; up to 2MB</p>
                        </div>
                    </div>

                    <div style="margin-top:20px;">
                        <button type="submit" class="owner-btn owner-btn-primary">
                            <i class="ph ph-check" aria-hidden="true"></i> Save changes
                        </button>
                    </div>
                </form>
            </div>
        </main>

    </div>

</div>

<script>
// Filename feedback only -- the picker itself is driven by the <label for>, so
// this failing leaves the upload fully usable.
(function () {
    var input = document.getElementById('gcash_qr');
    var out   = document.getElementById('gcashQrFileName');
    if (!input || !out) return;
    var idle = out.textContent;
    input.addEventListener('change', function () {
        if (input.files && input.files.length) {
            out.textContent = 'Selected: ' + input.files[0].name + ' \u2014 click Save changes to apply';
            out.classList.add('is-picked');
        } else {
            out.textContent = idle;
            out.classList.remove('is-picked');
        }
    });
})();
</script>
</body>
</html>
