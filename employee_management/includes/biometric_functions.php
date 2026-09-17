<?php
/**
 * employee_management/includes/biometric_functions.php
 *
 * Raw biometric punch import — turns a flat log of timestamped punches
 * (one row per tap, not pre-paired into time-in/time-out the way the
 * existing daily-summary CSV import expects) into attendance_records
 * rows. Built on `employee_biometric_ids`/`attendance_import_batches`/
 * `attendance_import_raw`, all part of the original 24-table schema but
 * unused until now. `biometric_devices` (and `device_id` on these two
 * tables) was dropped 2026-07-29 — it only ever anchored one
 * auto-created "Main Biometric Device" row and nothing in this app talks
 * to real hardware; every punch always comes from a manually uploaded
 * CSV/Excel export, so multi-device disambiguation was unused schema.
 *
 * File format: EmployeeID, EmployeeName, Date, Time, Status (I/O) — one
 * row per punch. EmployeeID is the device's own internal ID (whatever
 * number the fingerprint was enrolled under), NOT this system's
 * employee_id or employee_number — that's exactly why
 * employee_biometric_ids (linked from the "Biometric ID" field on each
 * employee's own record in employees.php) exists: the device has no
 * idea this ID means "Rachell Anne Arugay,
 * EMP-0001" until someone tells this system that mapping once.
 * EmployeeName is the device's own on-file name, used only as a display
 * aid for unmatched IDs (so a manager can tell who to link without
 * cross-referencing) — the actual match is always by EmployeeID via
 * employee_biometric_ids, never by name (names can collide or be
 * mis-typed on the device; IDs are the reliable key).
 *
 * This file's only job is resolving which raw punches are time_in/
 * break_out/break_in/time_out for a given employee+workday — the actual
 * hours/late/night-diff math is computeAttendanceMetrics()
 * (payroll_functions.php), the exact same function manual entry and the
 * daily-summary import already use, so a record's source never changes
 * how it's computed.
 */

require_once __DIR__ . '/payroll_functions.php';
require_once __DIR__ . '/xlsx_reader.php';

/**
 * Auto-picks the attendance_records.status a resolved biometric group
 * should be saved with, since there's no manual entry step left to pick
 * one by hand: an active holiday on that date wins first (so
 * computeHolidayPay() actually triggers), then a rest day per the
 * employee's own schedule (so computeRestDayPay() triggers), then a day
 * with late_minutes > 0 (past payroll_settings.late_grace_period_minutes --
 * computeAttendanceMetrics() has already applied that grace, so any nonzero
 * value here means the grace was exceeded) is 'late', otherwise a plain
 * worked day. Label only -- $lateMinutes is display/reporting (attendance.php,
 * Performance Monitoring), never re-deducted here; the payroll run already
 * sums late_minutes regardless of status, and treats 'late' the same as
 * 'present' for pay purposes (see payroll_run_functions.php).
 */
function detectBiometricAttendanceStatus(PDO $pdo, int $employeeId, string $workday, int $lateMinutes = 0): string
{
    $isHoliday = $pdo->prepare("SELECT 1 FROM holidays WHERE holiday_date = ? AND is_active = 1");
    $isHoliday->execute([$workday]);
    if ($isHoliday->fetchColumn()) {
        return 'holiday';
    }

    $isRestDay = $pdo->prepare("SELECT is_rest_day FROM employee_schedules WHERE employee_id = ? AND schedule_date = ?");
    $isRestDay->execute([$employeeId, $workday]);
    if ((int)$isRestDay->fetchColumn() === 1) {
        return 'rest_day';
    }

    if ($lateMinutes > 0) {
        return 'late';
    }

    return 'present';
}

/** employee_biometric_ids -> employee lookup, active links only. */
function getBiometricIdMap(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT b.biometric_user_id, b.employee_id, e.employee_number, e.first_name, e.last_name
         FROM employee_biometric_ids b JOIN employees e ON e.employee_id = b.employee_id
         WHERE b.is_active = 1"
    )->fetchAll(PDO::FETCH_ASSOC);
    $map = [];
    foreach ($rows as $r) {
        $map[$r['biometric_user_id']] = $r;
    }
    return $map;
}

/**
 * Creates/updates/soft-removes an employee's biometric ID link, called
 * from employee_save.php now that "Biometric ID" lives directly on the
 * Employee form instead of its own separate management screen.
 * employee_biometric_ids' real unique key is just `biometric_user_id`
 * and covers archived rows too, so reusing an ID that's only ever
 * archived (no one *currently* holds it) reassigns that same row to the
 * new employee instead of inserting a second row that would violate the
 * key -- this also covers a real restaurant scenario, a departed
 * employee's ID being re-enrolled for a new hire. Only an ID another
 * employee *actively* holds is rejected as a conflict. A blank value
 * archives (never deletes) any existing active link so the import audit
 * trail stays intact. Returns an error string, or null on success.
 */
function upsertEmployeeBiometricId(PDO $pdo, int $employeeId, ?string $biometricUserId): ?string
{
    $biometricUserId = trim((string)$biometricUserId);

    $existingStmt = $pdo->prepare("SELECT biometric_link_id, biometric_user_id FROM employee_biometric_ids WHERE employee_id = ? AND is_active = 1");
    $existingStmt->execute([$employeeId]);
    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

    if ($biometricUserId === '') {
        if ($existing) {
            $pdo->prepare("UPDATE employee_biometric_ids SET is_active = 0 WHERE biometric_link_id = ?")->execute([$existing['biometric_link_id']]);
        }
        return null;
    }

    if (mb_strlen($biometricUserId) > 50) {
        return 'Biometric ID can be at most 50 characters.';
    }

    if ($existing && $existing['biometric_user_id'] === $biometricUserId) {
        return null;
    }

    $dupStmt = $pdo->prepare("SELECT biometric_link_id FROM employee_biometric_ids WHERE biometric_user_id = ? AND employee_id != ? AND is_active = 1");
    $dupStmt->execute([$biometricUserId, $employeeId]);
    if ($dupStmt->fetch()) {
        return 'That biometric ID is already linked to another employee.';
    }

    if ($existing) {
        $pdo->prepare("UPDATE employee_biometric_ids SET biometric_user_id = ? WHERE biometric_link_id = ?")
            ->execute([$biometricUserId, $existing['biometric_link_id']]);
        return null;
    }

    $staleStmt = $pdo->prepare("SELECT biometric_link_id FROM employee_biometric_ids WHERE biometric_user_id = ?");
    $staleStmt->execute([$biometricUserId]);
    $staleLinkId = $staleStmt->fetchColumn();
    if ($staleLinkId) {
        $pdo->prepare("UPDATE employee_biometric_ids SET employee_id = ?, is_active = 1 WHERE biometric_link_id = ?")
            ->execute([$employeeId, $staleLinkId]);
        return null;
    }

    $pdo->prepare("INSERT INTO employee_biometric_ids (employee_id, biometric_user_id, is_active) VALUES (?, ?, 1)")
        ->execute([$employeeId, $biometricUserId]);
    return null;
}

/**
 * $fromXlsx: when a genuinely date-formatted Excel cell gets read by
 * parseXlsxFile() (xlsx_reader.php), its raw value is a bare numeric day
 * serial (e.g. "46225"), not text -- strtotime() can't parse that. Only
 * attempted as a fallback, and only for xlsx-sourced rows: a bare number
 * in a CSV's Date column is a typo, not a serial (CSV has no cell
 * formatting to reinterpret from), so this fallback would misinterpret
 * one there instead of correctly rejecting it.
 */
function normalizeBiometricDate(string $raw, bool $fromXlsx = false): ?string
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    $ts = strtotime($raw);
    if ($ts !== false) {
        return date('Y-m-d', $ts);
    }
    if ($fromXlsx && is_numeric($raw)) {
        return excelSerialToDate((float)$raw);
    }
    return null;
}

/** Same xlsx-serial fallback reasoning as normalizeBiometricDate() above. */
function normalizeBiometricTime(string $raw, bool $fromXlsx = false): ?string
{
    $raw = trim($raw);
    if (preg_match('/^(\d{1,2}):(\d{2})(:(\d{2}))?$/', $raw, $m)) {
        $h = (int)$m[1];
        $i = (int)$m[2];
        $s = isset($m[4]) ? (int)$m[4] : 0;
        return ($h > 23 || $i > 59 || $s > 59) ? null : sprintf('%02d:%02d:%02d', $h, $i, $s);
    }
    if ($fromXlsx && is_numeric($raw) && (float)$raw >= 0 && (float)$raw < 1) {
        return excelSerialToTime((float)$raw) . ':00';
    }
    return null;
}

/** 'I'/'IN'/'C' (any case) -> 'I'; 'O'/'OUT' -> 'O'; anything else -> null (unrecognized, not guessed). */
function normalizeBiometricStatus(string $raw): ?string
{
    $raw = strtoupper(trim($raw));
    if (in_array($raw, ['I', 'IN', 'C', 'CHECK IN', 'CHECKIN'], true)) {
        return 'I';
    }
    if (in_array($raw, ['O', 'OUT', 'CHECK OUT', 'CHECKOUT'], true)) {
        return 'O';
    }
    return null;
}

/**
 * Parses raw punch rows: EmployeeID, EmployeeName, Date, Time, Status
 * (I/O) -- ignores blank rows, collects per-row errors instead of
 * failing the whole file. $fromXlsx enables the Excel-serial fallback in
 * normalizeBiometricDate()/normalizeBiometricTime() -- pass true only
 * when $rows actually came from parseXlsxFile(), never for CSV rows.
 */
function parseBiometricPunchRows(array $rows, bool $fromXlsx = false): array
{
    $punches = [];
    $errors = [];
    foreach ($rows as $i => $row) {
        if (empty(array_filter($row, fn($c) => trim((string)$c) !== ''))) {
            continue;
        }
        $rowNum = $i + 2; // +2: 1-indexed, plus the header row
        $bioId = trim((string)($row[0] ?? ''));
        $deviceName = trim((string)($row[1] ?? ''));
        $dateRaw = trim((string)($row[2] ?? ''));
        $timeRaw = trim((string)($row[3] ?? ''));
        $statusRaw = trim((string)($row[4] ?? ''));

        if ($bioId === '') {
            $errors[] = "Row $rowNum: missing Employee ID.";
            continue;
        }
        $date = normalizeBiometricDate($dateRaw, $fromXlsx);
        if (!$date) {
            $errors[] = "Row $rowNum: invalid date \"$dateRaw\".";
            continue;
        }
        $time = normalizeBiometricTime($timeRaw, $fromXlsx);
        if ($time === null) {
            $errors[] = "Row $rowNum: invalid time \"$timeRaw\" (expected HH:MM or HH:MM:SS).";
            continue;
        }
        $status = normalizeBiometricStatus($statusRaw);
        if ($status === null) {
            $errors[] = "Row $rowNum: unrecognized status \"$statusRaw\" (expected I or O).";
            continue;
        }
        $punches[] = [
            'biometric_user_id' => $bioId,
            'device_name' => $deviceName,
            'datetime' => "$date $time",
            'status' => $status,
            'raw_line' => implode(',', $row),
        ];
    }
    return ['punches' => $punches, 'errors' => $errors];
}

/**
 * Groups raw punches by employee and workday, classifies each group's
 * punches into time_in/break_out/break_in/time_out, and resolves
 * against employee_schedules so an overnight shift's punches land on
 * one workday instead of splitting across two calendar dates.
 *
 * Classification uses BOTH punch position and the device's own I/O
 * status label, not position alone -- a 2-punch day is only accepted as
 * time-in/time-out if its statuses actually read I then O (same for a
 * 4-punch day reading I,O,I,O); a status sequence that doesn't match
 * (e.g. two consecutive "I"s, someone forgot to punch out) is flagged
 * for manual review instead of being silently treated as if it were
 * clean. Ambiguous punch counts (1, 3, 5+) are always flagged too --
 * never guessed, consistent with every other computed value in this
 * app.
 *
 * A day only gets the overnight-shift treatment if the employee has an
 * actual is_night_shift=1 schedule for it -- a punch with nothing to
 * pair it against just uses plain calendar-date grouping.
 */
function groupBiometricPunches(PDO $pdo, array $punches): array
{
    // Dedupe exact (biometric_user_id, datetime) repeats -- a common
    // quirk of raw device exports (the same tap logged twice).
    $seen = [];
    $deduped = [];
    foreach ($punches as $p) {
        $key = $p['biometric_user_id'] . '|' . $p['datetime'];
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $deduped[] = $p;
    }

    $idMap = getBiometricIdMap($pdo);

    $byUser = [];
    foreach ($deduped as $p) {
        $byUser[$p['biometric_user_id']][] = $p;
    }

    $groups = [];
    foreach ($byUser as $biometricUserId => $userPunches) {
        usort($userPunches, fn($a, $b) => strcmp($a['datetime'], $b['datetime']));
        $matched = $idMap[$biometricUserId] ?? null;
        $deviceName = $userPunches[0]['device_name'] ?? '';

        $nightShiftDates = [];
        if ($matched) {
            $schedStmt = $pdo->prepare(
                "SELECT es.schedule_date FROM employee_schedules es
                 JOIN shift_templates st ON st.shift_id = es.shift_id
                 WHERE es.employee_id = ? AND st.is_night_shift = 1"
            );
            $schedStmt->execute([$matched['employee_id']]);
            foreach ($schedStmt->fetchAll(PDO::FETCH_COLUMN) as $d) {
                $nightShiftDates[$d] = true;
            }
        }

        $byWorkday = [];
        foreach ($userPunches as $p) {
            $dt = new DateTime($p['datetime']);
            $calendarDate = $dt->format('Y-m-d');
            $priorDate = (clone $dt)->modify('-1 day')->format('Y-m-d');

            // An early-morning punch that belongs to the PRIOR date's
            // overnight shift, if that prior date was actually scheduled
            // as a night shift.
            $workday = ($dt->format('H:i:s') < '12:00:00' && isset($nightShiftDates[$priorDate]))
                ? $priorDate
                : $calendarDate;

            $byWorkday[$workday][] = $p;
        }

        foreach ($byWorkday as $workday => $dayPunches) {
            usort($dayPunches, fn($a, $b) => strcmp($a['datetime'], $b['datetime']));
            $count = count($dayPunches);
            $statuses = array_column($dayPunches, 'status');

            $timeIn = $timeOut = $breakOut = $breakIn = null;
            $issue = null;

            if ($count === 1) {
                $dayPunches[0]['punch_type'] = 'unknown';
                $issue = $statuses[0] === 'O'
                    ? 'Only one punch this day (a time out with no matching time in). Needs manual review.'
                    : 'Only one punch this day — could be a missing time out. Needs manual review.';
            } elseif ($count === 2 && $statuses === ['I', 'O']) {
                $dayPunches[0]['punch_type'] = 'time_in';
                $dayPunches[1]['punch_type'] = 'time_out';
                $timeIn = $dayPunches[0]['datetime'];
                $timeOut = $dayPunches[1]['datetime'];
            } elseif ($count === 4 && $statuses === ['I', 'O', 'I', 'O']) {
                $dayPunches[0]['punch_type'] = 'time_in';
                $dayPunches[1]['punch_type'] = 'break_out';
                $dayPunches[2]['punch_type'] = 'break_in';
                $dayPunches[3]['punch_type'] = 'time_out';
                $timeIn = $dayPunches[0]['datetime'];
                $breakOut = $dayPunches[1]['datetime'];
                $breakIn = $dayPunches[2]['datetime'];
                $timeOut = $dayPunches[3]['datetime'];
            } else {
                foreach ($dayPunches as &$dp) {
                    $dp['punch_type'] = 'unknown';
                }
                unset($dp);
                $issue = ($count === 2 || $count === 4)
                    ? "$count punches this day, but the In/Out sequence (" . implode(',', $statuses) . ") doesn't look right — needs manual review."
                    : "$count punches this day — can't tell which are breaks. Needs manual review.";
            }

            if (!$matched) {
                $issue = "No employee linked to device ID \"$biometricUserId\"" . ($deviceName !== '' ? " (reported on the device as \"$deviceName\")" : '') . ". Set this employee's Biometric ID on their Employee record and re-import.";
            }

            $groups[] = [
                'biometric_user_id' => $biometricUserId,
                'device_name' => $deviceName,
                'employee_id' => $matched['employee_id'] ?? null,
                'employee_number' => $matched['employee_number'] ?? null,
                'employee_name' => $matched ? trim($matched['first_name'] . ' ' . $matched['last_name']) : null,
                'workday' => $workday,
                'punches' => $dayPunches,
                'time_in' => $timeIn,
                'time_out' => $timeOut,
                'break_out' => $breakOut,
                'break_in' => $breakIn,
                'issue' => $issue,
                'ok' => $issue === null,
            ];
        }
    }

    usort($groups, function ($a, $b) {
        return [$a['employee_name'] ?? $a['biometric_user_id'], $a['workday']]
            <=> [$b['employee_name'] ?? $b['biometric_user_id'], $b['workday']];
    });

    return $groups;
}

/**
 * Computes a biometric-imported attendance day's overtime minutes -- minutes
 * actually worked AFTER the employee's scheduled time-out for that workday,
 * net of any late arrival. Biometric import is the only path that writes
 * attendance_records rows now that manual entry was removed 2026-09-14.
 * This is only ever a candidate figure stored in
 * attendance_records.overtime_minutes: payroll pays the LESSER of this
 * candidate and whatever a manager separately pre-authorized for that
 * employee/date in `overtime_authorizations`
 * (employee_management/overtime.php) -- see "FIXED 2026-09-06" and
 * "FIXED 2026-09-13" below for why both sides of the shift matter to the
 * candidate figure itself.
 *
 * Returns 0 (never negative, never guessed) when:
 *   - the workday has no employee_schedules row,
 *   - the schedule is a rest day (rest-day OT is governed by a separate
 *     multiplier, handled through the rest-day-worked hours bucket in
 *     payroll_run_functions.php instead of this candidate figure),
 *   - time_in/time_out are missing or time_out is not after time_in, or
 *   - the net minutes (see below) work out to zero or negative.
 *
 * FIXED 2026-09-06: this used to compare the employee's TOTAL worked span
 * (time_out - time_in - break) against the shift's total standard span
 * (scheduled_out - scheduled_in - break) and credit the difference as OT.
 * That manufactured phantom overtime two different ways, neither of which
 * involved working a single minute past the scheduled shift: clocking in
 * early inflated the worked span with nothing to show for it at the end of
 * the shift, and a shorter-than-default real break (break_out/break_in
 * punches present but tighter than payroll_settings.default_break_minutes)
 * did the same, since only the "standard" side of the old comparison ever
 * used that default. A live example: an employee scheduled 4:10 PM-1:00 AM
 * who punched in at 4:00 PM and out at 1:00 AM -- never staying a minute
 * past shift end -- was credited 10 minutes of OT. Comparing actual
 * time-out against scheduled time-out directly fixed both failure modes.
 *
 * FIXED 2026-09-13: comparing only the time-out side, on its own, opened the
 * mirror-image problem -- a LATE arrival that shifts the whole shift later
 * by roughly the same amount got credited as OT too, even though no extra
 * work happened. Example: scheduled 8:00 AM-5:00 PM, punched in at 8:03 AM
 * and out at 5:03 PM -- a completely normal 8-hour shift, just 3 minutes
 * late start and end -- was credited 3 minutes of OT, on the SAME 3 minutes
 * already charged as a Late Deduction. Net out how late the employee
 * started before crediting anything past the scheduled time-out: an
 * employee who starts late only earns OT for time worked beyond what it
 * took to make up their own late start, not for the late start itself
 * reappearing at the other end of the shift.
 *
 * IMPORTANT (the reason this is safe to auto-fill): the returned minutes
 * are stored in attendance_records.overtime_minutes, a candidate figure
 * only. Payroll (payroll_run_functions.php) separately looks up whether a
 * manager authorized any OT for this employee/date at all and pays
 * min(this candidate, the authorized minutes) -- with no authorization on
 * file, the paid amount is zero regardless of what this function returns.
 * So auto-filling this figure changes NO payslip amounts unless/until a
 * manager separately authorizes OT for that date.
 *
 * Midnight handling reuses combineDateTime()'s same date-anchor semantics
 * (payroll_functions.php): the scheduled time-out rolls to the next
 * calendar day when it falls at/before the scheduled time-in (e.g. a
 * 22:00–06:00 night shift), so a shift crossing midnight computes
 * correctly without special-casing here.
 */
function computeBiometricOvertimeMinutes(
    PDO $pdo,
    int $employeeId,
    string $workday,
    ?string $timeIn,
    ?string $timeOut
): int {
    if (!$timeIn || !$timeOut) {
        return 0;
    }

    $schedStmt = $pdo->prepare(
        "SELECT scheduled_time_in, scheduled_time_out, is_rest_day
         FROM employee_schedules
         WHERE employee_id = ? AND schedule_date = ?"
    );
    $schedStmt->execute([$employeeId, $workday]);
    $schedule = $schedStmt->fetch(PDO::FETCH_ASSOC);
    if (!$schedule || (int)$schedule['is_rest_day'] === 1) {
        return 0;
    }

    $scheduledIn = DateTime::createFromFormat('Y-m-d H:i', $workday . ' ' . substr($schedule['scheduled_time_in'], 0, 5));
    $scheduledOut = DateTime::createFromFormat('Y-m-d H:i', $workday . ' ' . substr($schedule['scheduled_time_out'], 0, 5));
    if (!$scheduledIn || !$scheduledOut) {
        return 0;
    }
    if ($scheduledOut <= $scheduledIn) {
        $scheduledOut->modify('+1 day'); // night shift crossing midnight
    }

    $actualIn = DateTime::createFromFormat('Y-m-d H:i', date('Y-m-d H:i', strtotime($timeIn)));
    $actualOut = DateTime::createFromFormat('Y-m-d H:i', date('Y-m-d H:i', strtotime($timeOut)));
    if (!$actualIn || !$actualOut || $actualIn >= $actualOut) {
        return 0;
    }

    $lateArrivalSeconds = max(0, $actualIn->getTimestamp() - $scheduledIn->getTimestamp());
    $otSeconds = ($actualOut->getTimestamp() - $scheduledOut->getTimestamp()) - $lateArrivalSeconds;
    return $otSeconds > 0 ? (int)round($otSeconds / 60) : 0;
}
