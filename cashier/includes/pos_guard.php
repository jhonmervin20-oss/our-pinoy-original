<?php
/**
 * cashier/includes/pos_guard.php
 *
 * Auth guard for HTML pages in this module (currently just pos.php).
 * The JSON endpoints under cashier/api/ do the same two checks inline —
 * a redirect is meaningless to a fetch() caller, so they respond with a
 * 401 + JSON instead, which makes a shared include not worth it there.
 *
 * Requires config/env.php, config/database.php, config/session.php to
 * already be required before this file.
 */

Session::start();

if (!Session::isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit;
}
if (!Session::hasRole(['cashier', 'owner'])) {
    header('Location: ../auth/login.php');
    exit;
}
