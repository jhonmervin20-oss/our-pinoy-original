<?php
/**
 * config/login_throttle.php
 *
 * Failed-login rate limiting for auth/login.php and auth/forgot_password.php.
 *
 * Only FAILURES are recorded (login_attempts). A successful login deletes the
 * pair's rows, so a legitimate user who mistypes twice and then gets in leaves
 * nothing behind.
 *
 * Deliberately NOT stored in activity_logs: that table FKs user_id to users,
 * and a failed login for an address that doesn't exist has no user to point
 * at. It's also the human-facing audit trail -- filling it with guess noise
 * makes it unreadable. A lockout EVENT is still written there (see
 * logLoginLockout()) so the Admin > Activity Logs page surfaces it; the
 * per-attempt counter is not.
 *
 * Two rules run together, and the scoping matters:
 *
 *   - PAIR (email + IP): the real limit. Scoped to the pair rather than to
 *     the account on purpose -- an account-wide lock would let anyone who
 *     knows the owner's email lock the owner out of their own system on
 *     demand, which is a denial-of-service dressed as a security feature.
 *     A wrong guesser from one address never affects the real owner signing
 *     in from theirs.
 *   - IP-wide (any email): catches spraying -- one guess each against many
 *     addresses, which would never trip the pair rule.
 *
 * Nothing here sleeps. A deliberate delay holds an Apache worker thread open,
 * and XAMPP's mpm_winnt has a bounded thread pool, so a sleep-based throttle
 * is itself a DoS vector. Locked requests are refused immediately instead.
 */

/** Failures allowed for one (email, IP) pair inside LOGIN_THROTTLE_WINDOW_MINUTES. */
const LOGIN_THROTTLE_PAIR_MAX = 5;

/** Failures allowed from one IP across ALL addresses in the same window -- the anti-spray rule. */
const LOGIN_THROTTLE_IP_MAX = 20;

/** Rolling window, in minutes, that both limits above are counted over. */
const LOGIN_THROTTLE_WINDOW_MINUTES = 15;

/** Password-reset code requests allowed per (email, IP) -- mirrors the intent of the old session counter. */
const RESET_THROTTLE_MAX = 3;

/** Rolling window, in minutes, for RESET_THROTTLE_MAX. */
const RESET_THROTTLE_WINDOW_MINUTES = 10;

/** Rows older than this are pruned by pruneLoginAttempts(). Comfortably longer than either window. */
const LOGIN_ATTEMPT_RETENTION_HOURS = 24;

/**
 * A bcrypt hash of a random value nobody can submit, verified against when
 * the submitted email doesn't exist so that both paths cost the same.
 *
 * Without it, auth/login.php returns almost instantly for an unknown address
 * (it never reaches password_verify()) but pays a full bcrypt round for a
 * known one. That gap is a reliable oracle for enumerating which of the
 * accounts are real, and enumeration is what makes a brute-force campaign
 * cheap to aim. This is not a credential -- nothing verifies against it
 * successfully.
 *
 * Pinned at bcrypt cost 10 EXPLICITLY, not at PASSWORD_DEFAULT -- because on
 * this machine PASSWORD_DEFAULT is not one value. Apache serves PHP 8.2.12
 * (PASSWORD_DEFAULT = bcrypt cost 10, ~62ms) while the CLI is PHP 8.5.5
 * (PASSWORD_DEFAULT = bcrypt cost 12, ~275ms). Every stored hash in users is
 * cost 10, since every account was created through the web SAPI, and
 * auth/login.php's password_needs_rehash() keeps them there.
 *
 * Writing PASSWORD_DEFAULT here would silently produce a cost-12 dummy if the
 * constant were ever regenerated from a CLI one-liner, making a NON-EXISTENT
 * address answer ~245ms SLOWER than a real one -- the same enumeration oracle
 * as before, just inverted. Measured, not theorised: that is exactly what the
 * first version of this constant did.
 *
 * Regenerate only with an explicit cost matching the WEB server's:
 *   password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT, ['cost' => 10])
 */
const LOGIN_DUMMY_HASH = '$2y$10$GFjPYn5aFCAANFrzUDPbL..jkkbe0QYxWv4U3DuczGvCVYmAMyFaK';

/**
 * The requesting client's IP.
 *
 * X-Forwarded-For is honored ONLY when the connection itself came from
 * loopback. That is precisely the reverse-proxy case this app actually runs
 * behind: when the site is exposed through an ngrok tunnel for live phone
 * testing, ngrok terminates the connection and forwards to Apache locally, so
 * REMOTE_ADDR is ::1 for every remote visitor and the real client IP survives
 * only in the header. Measured, not assumed -- 6,443 consecutive requests with
 * a mobile user-agent were logged by Apache as ::1.
 *
 * Trusting the header unconditionally would make every limit here bypassable
 * by simply sending a different X-Forwarded-For each request. Trusting it
 * never would collapse all tunnel traffic into one bucket, so a single
 * attacker could lock out every legitimate user at once. Loopback-only is the
 * narrow rule that avoids both.
 */
function client_ip(): string
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';

    if (in_array($remote, ['127.0.0.1', '::1'], true)) {
        $forwarded = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')[0]);
        if ($forwarded !== '' && filter_var($forwarded, FILTER_VALIDATE_IP)) {
            return $forwarded;
        }
    }

    return $remote !== '' ? $remote : 'unknown';
}

/** Addresses are compared case-insensitively so "Owner@x.com" and "owner@x.com" share one counter. */
function normalizeThrottleEmail(string $email): string
{
    return mb_strtolower(trim($email));
}

/**
 * Current throttle state for this (email, IP).
 *
 * Returns ['locked' => bool, 'retry_after' => int (seconds), 'scope' => 'pair'|'ip'|null].
 * retry_after is measured from the OLDEST failure still inside the window --
 * i.e. the moment that failure ages out is the moment one slot frees up, so a
 * locked-out user's wait shrinks in real time rather than resetting on every
 * further attempt. (Counting from the newest attempt instead would let a bot
 * hold a legitimate user locked out indefinitely by continuing to guess.)
 */
function loginThrottleState(PDO $db, string $email, string $ip): array
{
    $email  = normalizeThrottleEmail($email);
    $window = LOGIN_THROTTLE_WINDOW_MINUTES;

    $stmt = $db->prepare(
        "SELECT COUNT(*) AS fails, UNIX_TIMESTAMP(MIN(attempted_at)) AS oldest
         FROM   login_attempts
         WHERE  email = ? AND ip_address = ? AND attempt_type = 'login'
           AND  attempted_at > (NOW() - INTERVAL {$window} MINUTE)"
    );
    $stmt->execute([$email, $ip]);
    $pair = $stmt->fetch(PDO::FETCH_ASSOC);

    if ((int)$pair['fails'] >= LOGIN_THROTTLE_PAIR_MAX) {
        return [
            'locked'      => true,
            'retry_after' => throttleRetryAfter((int)$pair['oldest'], $window),
            'scope'       => 'pair',
        ];
    }

    $stmt = $db->prepare(
        "SELECT COUNT(*) AS fails, UNIX_TIMESTAMP(MIN(attempted_at)) AS oldest
         FROM   login_attempts
         WHERE  ip_address = ? AND attempt_type = 'login'
           AND  attempted_at > (NOW() - INTERVAL {$window} MINUTE)"
    );
    $stmt->execute([$ip]);
    $byIp = $stmt->fetch(PDO::FETCH_ASSOC);

    if ((int)$byIp['fails'] >= LOGIN_THROTTLE_IP_MAX) {
        return [
            'locked'      => true,
            'retry_after' => throttleRetryAfter((int)$byIp['oldest'], $window),
            'scope'       => 'ip',
        ];
    }

    return ['locked' => false, 'retry_after' => 0, 'scope' => null];
}

/** Seconds until $oldestUnixTs falls out of a $windowMinutes window; at least 1 so callers never render "0 minutes". */
function throttleRetryAfter(int $oldestUnixTs, int $windowMinutes): int
{
    return max(1, ($oldestUnixTs + $windowMinutes * 60) - time());
}

/** Human-facing wait, deliberately coarse -- an exact countdown tells a bot precisely when to resume. */
function formatRetryAfter(int $seconds): string
{
    $minutes = (int)ceil($seconds / 60);
    return $minutes <= 1 ? 'about a minute' : "about {$minutes} minutes";
}

function recordFailedLogin(PDO $db, string $email, string $ip): void
{
    $stmt = $db->prepare("INSERT INTO login_attempts (email, ip_address, attempt_type) VALUES (?, ?, 'login')");
    $stmt->execute([normalizeThrottleEmail($email), $ip]);
}

/**
 * Called on successful login -- clears only this pair's LOGIN rows. Reset
 * rows are left alone deliberately: signing in successfully shouldn't hand
 * someone a fresh allowance of password-reset emails to send at an address.
 * The IP-wide history is untouched too, so a spray in progress still counts.
 */
function clearLoginAttempts(PDO $db, string $email, string $ip): void
{
    $stmt = $db->prepare("DELETE FROM login_attempts WHERE email = ? AND ip_address = ? AND attempt_type = 'login'");
    $stmt->execute([normalizeThrottleEmail($email), $ip]);
}

/** Password-reset code requests are counted separately from failed logins -- see resetRequestThrottleState(). */
function recordResetRequest(PDO $db, string $email, string $ip): void
{
    $stmt = $db->prepare("INSERT INTO login_attempts (email, ip_address, attempt_type) VALUES (?, ?, 'reset')");
    $stmt->execute([normalizeThrottleEmail($email), $ip]);
}

/**
 * Separate, tighter limit for password-reset code requests. Replaces the old
 * $_SESSION['reset_requests'] counter in auth/forgot_password.php, which a
 * caller could clear at will just by discarding the session cookie (no cookie
 * -> new session -> count of 0 on every request).
 *
 * Counted over attempt_type = 'reset' ONLY, and kept strictly apart from the
 * login counter. Sharing one bucket would mean a user who legitimately
 * requested 3 reset codes had silently burned 3 of their 5 login attempts and
 * could lock themselves out of the login form by using the recovery flow the
 * lockout would send them to.
 */
function resetRequestThrottleState(PDO $db, string $email, string $ip): array
{
    $email  = normalizeThrottleEmail($email);
    $window = RESET_THROTTLE_WINDOW_MINUTES;

    $stmt = $db->prepare(
        "SELECT COUNT(*) AS fails, UNIX_TIMESTAMP(MIN(attempted_at)) AS oldest
         FROM   login_attempts
         WHERE  email = ? AND ip_address = ? AND attempt_type = 'reset'
           AND  attempted_at > (NOW() - INTERVAL {$window} MINUTE)"
    );
    $stmt->execute([$email, $ip]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ((int)$row['fails'] >= RESET_THROTTLE_MAX) {
        return ['locked' => true, 'retry_after' => throttleRetryAfter((int)$row['oldest'], $window)];
    }

    return ['locked' => false, 'retry_after' => 0];
}

/**
 * Call immediately after recordFailedLogin(). Writes the audit row only on
 * the failure that actually crosses a threshold, not on every subsequent
 * blocked attempt -- otherwise a bot that keeps hammering a locked pair would
 * flood activity_logs with duplicates of the same event.
 */
function noteLockoutIfJustTripped(PDO $db, string $email, string $ip): void
{
    $state = loginThrottleState($db, $email, $ip);
    if (!$state['locked']) {
        return;
    }

    $window = LOGIN_THROTTLE_WINDOW_MINUTES;
    $limit  = $state['scope'] === 'ip' ? LOGIN_THROTTLE_IP_MAX : LOGIN_THROTTLE_PAIR_MAX;

    // "Just tripped" == the count is sitting exactly on the limit. One further
    // failure can't land while locked (login.php refuses before recording), so
    // this is only ever true on the crossing attempt itself.
    if ($state['scope'] === 'ip') {
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM login_attempts
             WHERE ip_address = ? AND attempt_type = 'login'
               AND attempted_at > (NOW() - INTERVAL {$window} MINUTE)"
        );
        $stmt->execute([$ip]);
    } else {
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM login_attempts
             WHERE email = ? AND ip_address = ? AND attempt_type = 'login'
               AND attempted_at > (NOW() - INTERVAL {$window} MINUTE)"
        );
        $stmt->execute([normalizeThrottleEmail($email), $ip]);
    }

    if ((int)$stmt->fetchColumn() === $limit) {
        logLoginLockout($db, $email, $state['scope']);
    }
}

/**
 * One activity_logs row the first time a lock trips, so the existing
 * Admin > Activity Logs page surfaces it with no new UI. Best-effort: a
 * failure to log must never block or leak out of the auth flow, so this
 * swallows its own exception.
 */
function logLoginLockout(PDO $db, string $email, string $scope): void
{
    try {
        $stmt = $db->prepare(
            "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
             VALUES (NULL, 'security', 'login_locked', ?, ?)"
        );
        $stmt->execute([
            $scope === 'ip'
                ? 'Too many failed logins from this IP address across multiple accounts; temporarily blocked.'
                : 'Too many failed logins for ' . normalizeThrottleEmail($email) . ' from this IP address; temporarily blocked.',
            mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
    } catch (PDOException $e) {
        error_log('logLoginLockout failed: ' . $e->getMessage());
    }
}

/** Housekeeping -- called from cron/run_sweeps.php. Returns rows removed. */
function pruneLoginAttempts(PDO $db): int
{
    $hours = LOGIN_ATTEMPT_RETENTION_HOURS;
    return (int)$db->exec("DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL {$hours} HOUR)");
}
