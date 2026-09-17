<?php
/**
 * owner/settings/units.php
 *
 * Two independent sections: units of measure, and the directional
 * conversion factors between them. Both UNIQUE constraints (unit_code,
 * and the (from_unit_id, to_unit_id) pair) are already enforced by the
 * database — this page just catches the resulting duplicate-key error
 * and shows a friendly message instead of a raw exception.
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

const UNIT_TYPES = ['weight', 'volume', 'count'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        flash_set('error', 'Your session expired. Please try again.');
        header('Location: units.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create_unit' || $action === 'update_unit') {
        $unitCode = trim($_POST['unit_code'] ?? '');
        $unitName = trim($_POST['unit_name'] ?? '');
        $unitType = $_POST['unit_type'] ?? '';
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($unitCode === '' || mb_strlen($unitCode) > 10) {
            flash_set('error', 'Please enter a unit code (up to 10 characters).');
        } elseif ($unitName === '' || mb_strlen($unitName) > 50) {
            flash_set('error', 'Please enter a unit name (up to 50 characters).');
        } elseif (!in_array($unitType, UNIT_TYPES, true)) {
            flash_set('error', 'Please choose a valid unit type.');
        } else {
            try {
                if ($action === 'create_unit') {
                    $stmt = $db->prepare(
                        'INSERT INTO unit_of_measures (unit_code, unit_name, unit_type, is_active) VALUES (?, ?, ?, 1)'
                    );
                    $stmt->execute([$unitCode, $unitName, $unitType]);
                    flash_set('success', 'Unit added.');
                } else {
                    $unitId = (int)($_POST['unit_id'] ?? 0);
                    $stmt = $db->prepare(
                        'UPDATE unit_of_measures SET unit_code = ?, unit_name = ?, unit_type = ?, is_active = ? WHERE unit_id = ?'
                    );
                    $stmt->execute([$unitCode, $unitName, $unitType, $isActive, $unitId]);
                    flash_set('success', 'Unit updated.');
                }
            } catch (PDOException $e) {
                if ((int)$e->getCode() === 23000) {
                    flash_set('error', 'That unit code is already in use.');
                } else {
                    error_log('units.php unit save failed: ' . $e->getMessage());
                    flash_set('error', 'Something went wrong saving that unit. Please try again.');
                }
            }
        }
    } elseif ($action === 'toggle_unit_active') {
        $unitId = (int)($_POST['unit_id'] ?? 0);
        $db->prepare('UPDATE unit_of_measures SET is_active = NOT is_active WHERE unit_id = ?')->execute([$unitId]);
        flash_set('success', 'Unit status updated.');
    } elseif ($action === 'create_conversion' || $action === 'update_conversion') {
        $fromUnitId = (int)($_POST['from_unit_id'] ?? 0);
        $toUnitId   = (int)($_POST['to_unit_id'] ?? 0);
        $factor     = $_POST['conversion_factor'] ?? '';

        if ($fromUnitId <= 0 || $toUnitId <= 0) {
            flash_set('error', 'Please choose both units.');
        } elseif ($fromUnitId === $toUnitId) {
            flash_set('error', 'From and To units must be different.');
        } elseif (!is_numeric($factor) || (float)$factor <= 0) {
            flash_set('error', 'Please enter a conversion factor greater than 0.');
        } else {
            $unitCodes = [];
            foreach ($db->query('SELECT unit_id, unit_code FROM unit_of_measures') as $u) {
                $unitCodes[(int)$u['unit_id']] = $u['unit_code'];
            }
            $fromCode = $unitCodes[$fromUnitId] ?? '?';
            $toCode   = $unitCodes[$toUnitId] ?? '?';

            try {
                if ($action === 'create_conversion') {
                    $stmt = $db->prepare(
                        'INSERT INTO unit_conversions (from_unit_id, to_unit_id, conversion_factor) VALUES (?, ?, ?)'
                    );
                    $stmt->execute([$fromUnitId, $toUnitId, $factor]);
                    flash_set('success', 'Conversion added.');
                } else {
                    $conversionId = (int)($_POST['conversion_id'] ?? 0);
                    $stmt = $db->prepare(
                        'UPDATE unit_conversions SET from_unit_id = ?, to_unit_id = ?, conversion_factor = ? WHERE conversion_id = ?'
                    );
                    $stmt->execute([$fromUnitId, $toUnitId, $factor, $conversionId]);
                    flash_set('success', 'Conversion updated.');
                }
            } catch (PDOException $e) {
                if ((int)$e->getCode() === 23000) {
                    flash_set('error', "A conversion from {$fromCode} to {$toCode} already exists.");
                } else {
                    error_log('units.php conversion save failed: ' . $e->getMessage());
                    flash_set('error', 'Something went wrong saving that conversion. Please try again.');
                }
            }
        }
    }

    header('Location: units.php');
    exit;
}

$units = $db->query('SELECT unit_id, unit_code, unit_name, unit_type, is_active FROM unit_of_measures ORDER BY unit_type, unit_code')->fetchAll();

$conversions = $db->query(
    'SELECT c.conversion_id, c.from_unit_id, c.to_unit_id, c.conversion_factor,
            fu.unit_code AS from_code, tu.unit_code AS to_code
     FROM unit_conversions c
     JOIN unit_of_measures fu ON fu.unit_id = c.from_unit_id
     JOIN unit_of_measures tu ON tu.unit_id = c.to_unit_id
     ORDER BY fu.unit_code, tu.unit_code'
)->fetchAll();

$editingUnitId       = isset($_GET['edit_unit_id']) ? (int)$_GET['edit_unit_id'] : null;
$editingConversionId = isset($_GET['edit_conversion_id']) ? (int)$_GET['edit_conversion_id'] : null;

$editingConversion = null;
if ($editingConversionId) {
    foreach ($conversions as $c) {
        if ((int)$c['conversion_id'] === $editingConversionId) {
            $editingConversion = $c;
            break;
        }
    }
}

/**
 * Render <option> tags for a unit dropdown. Inactive units are hidden
 * unless their unit_id is in $alwaysIncludeIds (used so an edit form
 * still shows a conversion's currently-chosen units even if one of them
 * has since been deactivated).
 */
function renderUnitOptions(array $allUnits, int $selectedId, array $alwaysIncludeIds = []): string
{
    $html = '<option value="">Choose a unit&hellip;</option>';
    foreach ($allUnits as $u) {
        $uid = (int)$u['unit_id'];
        if (!$u['is_active'] && !in_array($uid, $alwaysIncludeIds, true)) {
            continue;
        }
        $label = $u['unit_code'] . ' — ' . $u['unit_name'] . (!$u['is_active'] ? ' (inactive)' : '');
        $sel   = $uid === $selectedId ? ' selected' : '';
        $html .= '<option value="' . $uid . '"' . $sel . '>' . htmlspecialchars($label) . '</option>';
    }
    return $html;
}

$activePage        = 'settings';
$activeSettingsTab = 'units';
$pageTitle         = 'Units & conversions';
$ownerBase         = '../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Units & conversions | Owner Panel | OPO! Our Pinoy Original</title>
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

            <?php if ($editingUnitId !== null): ?>
                <?php
                $editingUnit = null;
                foreach ($units as $u) {
                    if ((int)$u['unit_id'] === $editingUnitId) { $editingUnit = $u; break; }
                }
                ?>
                <?php if ($editingUnit): ?>
                    <form method="POST" action="units.php" id="editUnitForm">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_unit">
                        <input type="hidden" name="unit_id" value="<?= (int)$editingUnit['unit_id'] ?>">
                    </form>
                <?php endif; ?>
            <?php endif; ?>

            <div class="owner-card">
                <h2 class="owner-card-title">Units of measure</h2>
                <div class="owner-table-wrap">
                    <table class="owner-table">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Name</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$units): ?>
                                <tr><td colspan="5" class="owner-table-empty">No units yet.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($units as $unit): ?>
                                <?php if ($editingUnitId === (int)$unit['unit_id']): ?>
                                    <tr class="owner-inline-edit-row">
                                        <td><input type="text" name="unit_code" form="editUnitForm" class="owner-input" value="<?= htmlspecialchars($unit['unit_code']) ?>" maxlength="10" required></td>
                                        <td><input type="text" name="unit_name" form="editUnitForm" class="owner-input" value="<?= htmlspecialchars($unit['unit_name']) ?>" maxlength="50" required></td>
                                        <td>
                                            <select name="unit_type" form="editUnitForm" class="owner-select">
                                                <?php foreach (UNIT_TYPES as $type): ?>
                                                    <option value="<?= $type ?>" <?= $unit['unit_type'] === $type ? 'selected' : '' ?>><?= ucfirst($type) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <label class="owner-toggle">
                                                <input type="checkbox" name="is_active" form="editUnitForm" <?= $unit['is_active'] ? 'checked' : '' ?>>
                                                <span class="owner-toggle-track"></span>
                                            </label>
                                        </td>
                                        <td class="owner-table-actions">
                                            <button type="submit" form="editUnitForm" class="owner-btn owner-btn-primary owner-btn-sm"><i class="ph ph-check" aria-hidden="true"></i></button>
                                            <a href="units.php" class="owner-btn owner-btn-secondary owner-btn-sm"><i class="ph ph-x" aria-hidden="true"></i></a>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <tr>
                                        <td><?= htmlspecialchars($unit['unit_code']) ?></td>
                                        <td><?= htmlspecialchars($unit['unit_name']) ?></td>
                                        <td><?= ucfirst(htmlspecialchars($unit['unit_type'])) ?></td>
                                        <td>
                                            <form method="POST" action="units.php">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="toggle_unit_active">
                                                <input type="hidden" name="unit_id" value="<?= (int)$unit['unit_id'] ?>">
                                                <label class="owner-toggle" aria-label="Toggle active">
                                                    <input type="checkbox" <?= $unit['is_active'] ? 'checked' : '' ?> onchange="this.form.submit()">
                                                    <span class="owner-toggle-track"></span>
                                                </label>
                                            </form>
                                        </td>
                                        <td class="owner-table-actions">
                                            <a href="units.php?edit_unit_id=<?= (int)$unit['unit_id'] ?>" class="owner-btn owner-btn-secondary owner-btn-icon owner-btn-sm" aria-label="Edit">
                                                <i class="ph ph-pencil-simple" aria-hidden="true"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <form method="POST" action="units.php" style="margin-top:18px;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create_unit">
                    <div class="owner-form-grid">
                        <div class="owner-form-group">
                            <label for="new_unit_code">Unit code</label>
                            <input type="text" id="new_unit_code" name="unit_code" class="owner-input" maxlength="10" placeholder="kg" required>
                        </div>
                        <div class="owner-form-group">
                            <label for="new_unit_name">Unit name</label>
                            <input type="text" id="new_unit_name" name="unit_name" class="owner-input" maxlength="50" placeholder="Kilogram" required>
                        </div>
                        <div class="owner-form-group">
                            <label for="new_unit_type">Type</label>
                            <select id="new_unit_type" name="unit_type" class="owner-select">
                                <?php foreach (UNIT_TYPES as $type): ?>
                                    <option value="<?= $type ?>"><?= ucfirst($type) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div style="margin-top:14px;">
                        <button type="submit" class="owner-btn owner-btn-primary owner-btn-sm">
                            <i class="ph ph-plus" aria-hidden="true"></i> Add unit
                        </button>
                    </div>
                </form>
            </div>

            <div class="owner-card">
                <h2 class="owner-card-title">Conversions</h2>

                <?php if ($editingConversion): ?>
                    <form method="POST" action="units.php" id="editConversionForm">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_conversion">
                        <input type="hidden" name="conversion_id" value="<?= (int)$editingConversion['conversion_id'] ?>">
                    </form>
                <?php endif; ?>

                <div class="owner-table-wrap">
                    <table class="owner-table">
                        <thead>
                            <tr>
                                <th>From</th>
                                <th>To</th>
                                <th>Factor</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$conversions): ?>
                                <tr><td colspan="4" class="owner-table-empty">No conversions yet.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($conversions as $conv): ?>
                                <?php if ($editingConversionId === (int)$conv['conversion_id']): ?>
                                    <tr class="owner-inline-edit-row">
                                        <td>
                                            <select name="from_unit_id" form="editConversionForm" class="owner-select">
                                                <?= renderUnitOptions($units, (int)$conv['from_unit_id'], [(int)$conv['from_unit_id'], (int)$conv['to_unit_id']]) ?>
                                            </select>
                                        </td>
                                        <td>
                                            <select name="to_unit_id" form="editConversionForm" class="owner-select">
                                                <?= renderUnitOptions($units, (int)$conv['to_unit_id'], [(int)$conv['from_unit_id'], (int)$conv['to_unit_id']]) ?>
                                            </select>
                                        </td>
                                        <td><input type="number" name="conversion_factor" form="editConversionForm" class="owner-input" step="0.000001" min="0.000001" value="<?= htmlspecialchars($conv['conversion_factor']) ?>" required></td>
                                        <td class="owner-table-actions">
                                            <button type="submit" form="editConversionForm" class="owner-btn owner-btn-primary owner-btn-sm"><i class="ph ph-check" aria-hidden="true"></i></button>
                                            <a href="units.php" class="owner-btn owner-btn-secondary owner-btn-sm"><i class="ph ph-x" aria-hidden="true"></i></a>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <tr>
                                        <td><?= htmlspecialchars($conv['from_code']) ?></td>
                                        <td><?= htmlspecialchars($conv['to_code']) ?></td>
                                        <td><?= htmlspecialchars(rtrim(rtrim((string)$conv['conversion_factor'], '0'), '.')) ?></td>
                                        <td class="owner-table-actions">
                                            <a href="units.php?edit_conversion_id=<?= (int)$conv['conversion_id'] ?>" class="owner-btn owner-btn-secondary owner-btn-icon owner-btn-sm" aria-label="Edit">
                                                <i class="ph ph-pencil-simple" aria-hidden="true"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <form method="POST" action="units.php" style="margin-top:18px;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create_conversion">
                    <div class="owner-form-grid">
                        <div class="owner-form-group">
                            <label for="new_from_unit">From unit</label>
                            <select id="new_from_unit" name="from_unit_id" class="owner-select" required>
                                <?= renderUnitOptions($units, 0) ?>
                            </select>
                        </div>
                        <div class="owner-form-group">
                            <label for="new_to_unit">To unit</label>
                            <select id="new_to_unit" name="to_unit_id" class="owner-select" required>
                                <?= renderUnitOptions($units, 0) ?>
                            </select>
                        </div>
                        <div class="owner-form-group">
                            <label for="new_conversion_factor">Conversion factor</label>
                            <input type="number" id="new_conversion_factor" name="conversion_factor" class="owner-input" step="0.000001" min="0.000001" required>
                        </div>
                    </div>
                    <div style="margin-top:14px;">
                        <button type="submit" class="owner-btn owner-btn-primary owner-btn-sm">
                            <i class="ph ph-plus" aria-hidden="true"></i> Add conversion
                        </button>
                    </div>
                </form>
            </div>
        </main>

    </div>

</div>

</body>
</html>
