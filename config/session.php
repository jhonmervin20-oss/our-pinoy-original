<?php
class Session
{
    /**
     * Start the PHP session with secure cookie params.
     * Call this once, as early as possible on every page.
     */
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();

        // Basic session fixation protection: regenerate ID periodically
        if (!isset($_SESSION['_last_regen'])) {
            $_SESSION['_last_regen'] = time();
        } elseif (time() - $_SESSION['_last_regen'] > 900) { // every 15 minutes
            session_regenerate_id(true);
            $_SESSION['_last_regen'] = time();
        }
    }

    /**
     * Log a user in: store the relevant fields from the `users` row
     * (joined with `roles`) into the session.
     *
     * @param array $user Associative array containing at least:
     *   user_id, first_name, last_name, email, role_id, role_name
     */
    public static function login(array $user): void
    {
        self::start();

        // Prevent session fixation on privilege change
        session_regenerate_id(true);

        $_SESSION['user_id']    = $user['user_id'];
        $_SESSION['first_name'] = $user['first_name'];
        $_SESSION['last_name']  = $user['last_name'];
        $_SESSION['email']      = $user['email'];
        $_SESSION['role_id']    = $user['role_id'];
        $_SESSION['role_name']  = $user['role_name'];
        $_SESSION['logged_in']  = true;
        $_SESSION['login_time'] = time();
    }

    /**
     * Log the current user out and destroy the session.
     */
    public static function logout(): void
    {
        self::start();

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
    }

    /**
     * Whether a user is currently logged in.
     */
    public static function isLoggedIn(): bool
    {
        self::start();
        return !empty($_SESSION['logged_in']) && !empty($_SESSION['user_id']);
    }

    /**
     * Get the current user's id, or null if not logged in.
     */
    public static function getUserId(): ?int
    {
        self::start();
        return $_SESSION['user_id'] ?? null;
    }

    /**
     * Get the current user's role name (e.g. 'admin', 'manager'), or null.
     */
    public static function getRole(): ?string
    {
        self::start();
        return $_SESSION['role_name'] ?? null;
    }

    /**
     * Get a full name string for the current user, or null.
     */
    public static function getFullName(): ?string
    {
        self::start();
        if (!self::isLoggedIn()) {
            return null;
        }
        return trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
    }

    /**
     * Two-letter avatar initials from the current user's first + last name
     * (e.g. "OPO ADMIN" -> "OA"), or 'U' if neither name is set.
     */
    public static function getInitials(): string
    {
        self::start();
        $first = $_SESSION['first_name'] ?? '';
        $last  = $_SESSION['last_name'] ?? '';
        $initials = mb_strtoupper(mb_substr($first, 0, 1) . mb_substr($last, 0, 1));

        return $initials !== '' ? $initials : 'U';
    }

    /**
     * Check whether the current user has one of the given role(s).
     *
     * @param string|array $roles A single role name or array of role names.
     */
    public static function hasRole($roles): bool
    {
        self::start();

        if (!self::isLoggedIn()) {
            return false;
        }

        $roles = is_array($roles) ? $roles : [$roles];

        // A session can be logged in (user_id present) but carry no role_name --
        // an older session cookie from before a login-payload change, for one.
        // Strict in_array means null matches no role, so the guard still denies;
        // without the coalesce it denied *and* emitted a warning while doing it.
        return in_array($_SESSION['role_name'] ?? null, $roles, true);
    }

    /**
     * Redirect to the login page if the user is not logged in.
     * Call at the top of any page that requires authentication.
     */
    public static function requireLogin(string $redirectTo = 'login.php'): void
    {
        self::start();

        if (!self::isLoggedIn()) {
            header('Location: ' . $redirectTo);
            exit;
        }
    }

    /**
     * Redirect (or 403) if the user does not have one of the required roles.
     * Automatically also ensures the user is logged in first.
     *
     * @param string|array $roles       Allowed role name(s), e.g. 'admin' or ['admin','manager']
     * @param string       $redirectTo  Where to send unauthorized users
     */
    public static function requireRole($roles, string $redirectTo = 'unauthorized.php'): void
    {
        self::requireLogin();

        if (!self::hasRole($roles)) {
            header('Location: ' . $redirectTo);
            exit;
        }
    }
}