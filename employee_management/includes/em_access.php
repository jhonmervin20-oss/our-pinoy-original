<?php
/**
 * employee_management/includes/em_access.php
 *
 * Who may do what in this module, and which panel chrome to render for them.
 *
 * The split, after Positions/Employees/Schedules/Holidays moved to the Admin:
 *
 *   area                  admin        manager
 *   --------------------  -----------  -------------------------------------
 *   Departments/Positions  full         no access at all
 *   Employees + shifts     full         view the list only
 *   Schedules              view only    view the grid only
 *   Holidays               full         no access at all
 *   Attendance             no access    full (manual entry, import, OT)
 *   Leave/                 no access    full
 *   Adjustments/Runs/
 *   Performance monitoring
 *
 * Attendance deliberately stayed with the Manager: payroll runs read
 * attendance_records, and moving its write side to a role that has no
 * Attendance page would have left nothing able to record a punch.
 *
 * Every page and endpoint gates on the helpers below rather than repeating a
 * hasRole() array, so the table above has exactly one place to change.
 */

/** Departments & Positions -- Admin only. */
function emCanManagePositions(): bool
{
    return Session::hasRole(['admin']);
}

/** Creating/editing/archiving an employee record -- Admin only. */
function emCanManageEmployees(): bool
{
    return Session::hasRole(['admin']);
}

/** Seeing the employee list -- Manager reads it, Admin maintains it. */
function emCanViewEmployees(): bool
{
    return Session::hasRole(['manager', 'admin']);
}

/**
 * Shifts, and each employee's work schedule (shift + work days) -- Admin only.
 * The Schedules grid itself is generated from those, never edited directly.
 */
function emCanManageSchedules(): bool
{
    return Session::hasRole(['admin']);
}

/** The holiday calendar (holidays.php + its save/toggle endpoints) -- Admin only. */
function emCanManageHolidays(): bool
{
    return Session::hasRole(['admin']);
}

/** Seeing the schedule grid -- Manager reads it, Admin maintains it. */
function emCanViewSchedules(): bool
{
    return Session::hasRole(['manager', 'admin']);
}

/* -- Panel chrome -----------------------------------------------------------
   These pages used to hardcode the Manager sidebar/header. Now that an Admin
   can open some of them, the chrome has to follow the viewer: rendering the
   Manager nav to an Admin would show them Orders, Inventory, Payroll Runs and
   the rest, every one of which their own guard refuses. Same conditional
   include pattern purchase_orders/ already uses for its owner/manager split,
   pulled into a helper so the four pages don't each repeat it. ---------- */

/** 'admin' or 'manager' -- whose panel the current user belongs to. */
function emPanel(): string
{
    return Session::hasRole(['admin']) ? 'admin' : 'manager';
}

/** Panel name for the <title> tag. */
function emPanelLabel(): string
{
    return emPanel() === 'admin' ? 'Admin' : 'Manager';
}

/**
 * Render the viewer's sidebar. $activePage must already be set by the caller;
 * both panels use the same nav-key vocabulary (payroll_employees,
 * payroll_departments_positions, payroll_schedules) so highlighting works
 * either way.
 */
function emRenderSidebar(): void
{
    // require_once, matching what these pages used before -- and the chrome
    // files declare functions (adminNavLink/managerNavLink), so a plain
    // require would fatal on a redeclare if anything included them twice.
    $activePage = $GLOBALS['activePage'] ?? '';
    if (emPanel() === 'admin') {
        $adminBase = '../admin/';
        require_once __DIR__ . '/../../admin/includes/sidebar.php';
        return;
    }
    $managerBase = '../manager/';
    require_once __DIR__ . '/../../manager/includes/sidebar.php';
}

/** Render the viewer's top bar. $pageTitle must already be set by the caller. */
function emRenderHeader(): void
{
    $pageTitle = $GLOBALS['pageTitle'] ?? '';
    if (emPanel() === 'admin') {
        $adminBase = '../admin/';
        require_once __DIR__ . '/../../admin/includes/header.php';
        return;
    }
    $managerBase = '../manager/';
    require_once __DIR__ . '/../../manager/includes/header.php';
}

/**
 * Bounce someone who reached a write endpoint they don't own. Back to the
 * page itself, not to login: they are signed in and may well be allowed to
 * look at it -- they just can't change it.
 */
function emDenyWrite(string $redirect, string $message = 'You do not have permission to make that change.'): void
{
    flash_set('error', $message);
    header('Location: ' . $redirect);
    exit;
}
