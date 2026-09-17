<?php
/**
 * cashier/api/menu_catalog.php
 *
 * GET -> the current menu catalog (same shape getMenuCatalog() embeds into
 * pos.php's initial HTML, including dine_in_stock/takeout_stock/status)
 * as JSON. pos.js calls this right after a successful checkout to refresh
 * the stock badges in place -- otherwise MENU_CATALOG is a one-time
 * snapshot from page load and never reflects inventory a sale just
 * deducted. fetch()-only endpoint, so it uses the inline 401-JSON auth
 * pattern other cashier/api/*.php endpoints use rather than pos_guard.php's
 * redirect (see pos_guard.php's own doc comment).
 */

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../includes/pos_functions.php';

header('Content-Type: application/json');

Session::start();

if (!Session::isLoggedIn() || !Session::hasRole(['cashier', 'owner'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Your session has expired. Please log in again.']);
    exit;
}

$db = Database::getInstance()->getConnection();

echo json_encode(getMenuCatalog($db));
