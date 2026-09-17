<?php
/**
 * config/env.php
 *
 * Loads variables from a .env file at the project root into getenv().
 * database.php already reads DB_HOST / DB_NAME / DB_USER / DB_PASS via
 * getenv(), so this just makes sure those (plus the mail settings below)
 * are populated before anything else runs.
 *
 * Include this once, before database.php / session.php, e.g. at the
 * very top of index.php and every file in /auth:
 *
 *   require_once __DIR__ . '/config/env.php';
 *
 * Also pins PHP's timezone to Asia/Manila (this is a Philippine
 * restaurant app) regardless of what php.ini's date.timezone says --
 * that was hardcoded to Europe/Berlin (a ~6-7 hour offset from real
 * local time), which meant every date()/time() call in the app --
 * "Good afternoon" vs "Good evening" greetings, "today"/countdown
 * calculations, etc. -- was silently wrong by that same margin. Fixed
 * here rather than in php.ini so it takes effect immediately (no Apache
 * restart) and doesn't touch anything else that XAMPP instance might be
 * running. The DB side was never wrong -- MySQL's own NOW() already uses
 * time_zone=SYSTEM, which reads the OS's real (correctly UTC+8) clock.
 */

date_default_timezone_set('Asia/Manila');

/*
 * Gzip every response.
 *
 * Apache here has mod_deflate unloaded entirely, so every page in this app
 * was going over the wire raw -- schedules.php alone is ~124 KB of HTML per
 * week change, and each prev/next click is a full page load. On localhost
 * that is merely wasteful; over ngrok on a phone (how this app actually gets
 * tested) it is the difference between snappy and sluggish.
 *
 * Done here rather than in httpd.conf/php.ini for the same reason the
 * timezone above is: it takes effect immediately with no Apache restart, it
 * travels with the project instead of living in one machine's XAMPP install,
 * and it touches nothing else that XAMPP instance may be serving.
 *
 * Safe globally: nothing in this codebase sets its own Content-Length header
 * (verified by grep -- that is the one thing gzip would corrupt), and PHP
 * skips compression automatically when the client does not advertise it.
 * Must run before any output; env.php is the first include on every page.
 */
if (!headers_sent() && !ini_get('zlib.output_compression')) {
    ini_set('zlib.output_compression', '1');
    ini_set('zlib.output_compression_level', '6');
}

/**
 * Absolute path to the forecasting virtualenv's Python interpreter.
 *
 * The layout differs by OS -- Windows puts it in `venv/Scripts/python.exe`,
 * POSIX in `venv/bin/python` -- and four callers had the Windows form
 * hardcoded, so the whole forecasting pipeline was unreachable on a Linux
 * host with no error that pointed at the cause.
 *
 * FORECAST_PYTHON in .env overrides everything, for a system-wide interpreter
 * or a venv kept outside the web root.
 */
function forecastPythonPath(): string
{
    $override = getenv('FORECAST_PYTHON') ?: ($_ENV['FORECAST_PYTHON'] ?? '');
    if ($override !== '') {
        return $override;
    }

    $venv = dirname(__DIR__) . '/forecast_service/venv';
    foreach ([$venv . '/Scripts/python.exe', $venv . '/bin/python3', $venv . '/bin/python'] as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    // Nothing found: return the platform's expected path anyway, so the
    // caller's own is_file() check fails with a path worth reading rather
    // than an empty string.
    return DIRECTORY_SEPARATOR === '\\'
        ? $venv . '/Scripts/python.exe'
        : $venv . '/bin/python';
}

/**
 * CA bundle for outbound HTTPS, or true to use the system store.
 *
 * Guzzle's `verify` option. This was hardcoded to XAMPP's bundled
 * `C:\xampp\php\extras\cacert.pem` in three places, which meant that on any
 * other host every outbound HTTPS call failed with cURL error 60 -- the
 * chatbot, AI insights, the menu filter and PayMongo all at once, and at
 * runtime rather than at deploy time.
 *
 * Order: an explicit CA_BUNDLE from .env, then php.ini's own curl.cainfo /
 * openssl.cafile, then the usual Linux locations, then XAMPP's. Returning
 * `true` last is not a weakening -- it tells cURL to use the system trust
 * store, which is the correct behaviour on a properly configured host.
 */
function httpCaBundle()
{
    $candidates = [
        getenv('CA_BUNDLE') ?: ($_ENV['CA_BUNDLE'] ?? ''),
        ini_get('curl.cainfo'),
        ini_get('openssl.cafile'),
        '/etc/ssl/certs/ca-certificates.crt',   // Debian, Ubuntu
        '/etc/pki/tls/certs/ca-bundle.crt',     // RHEL, Fedora, Amazon Linux
        'C:\\xampp\\php\\extras\\cacert.pem',   // this machine
    ];
    foreach ($candidates as $path) {
        if (is_string($path) && $path !== '' && is_file($path)) {
            return $path;
        }
    }
    return true;
}

$rootEnvFile = dirname(__DIR__) . '/.env';

if (class_exists(\Dotenv\Dotenv::class) && file_exists($rootEnvFile)) {
    // Preferred: vlucas/phpdotenv (composer require vlucas/phpdotenv)
    $dotenv = \Dotenv\Dotenv::createImmutable(dirname(__DIR__));
    $dotenv->safeLoad();
} elseif (file_exists($rootEnvFile)) {
    // Fallback: tiny manual parser, no dependency required
    foreach (file($rootEnvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);
        // strip matching surrounding quotes
        if (strlen($value) >= 2 && $value[0] === $value[-1] && in_array($value[0], ['"', "'"], true)) {
            $value = substr($value, 1, -1);
        }
        if (getenv($key) === false) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

/**
 * Production-safe error visibility, controlled by APP_ENV.
 *
 * This machine's php.ini has display_errors=On -- XAMPP's development
 * default, and correct for local work, but it means every uncaught PHP
 * error/warning/notice renders directly in the HTTP response: file paths,
 * query fragments, stack traces, straight to whoever is looking at the
 * page. Deploying this codebase as-is, with no explicit flip anywhere,
 * would ship that straight to production -- there was no APP_ENV concept
 * in this app at all before this. Errors are still fully captured either
 * way (log_errors stays on) -- this only controls whether they also print
 * to the response.
 *
 * Defaults to 'production' (safe) when APP_ENV is unset, so a deploy that
 * forgets to set it fails closed rather than open. The current .env is
 * explicitly pinned to 'development' below this block so this machine's
 * own behaviour does not change today.
 */
$appEnv = strtolower(trim((string)(getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? 'production'))));
if ($appEnv === 'production') {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
}
ini_set('log_errors', '1');

// expose_php=On (php.ini) adds an X-Powered-By header naming the exact PHP
// version -- minor recon information for free. Can only be suppressed at
// runtime (expose_php itself is PHP_INI_SYSTEM, not settable via ini_set),
// so this is the header, not the setting.
if (!headers_sent()) {
    header_remove('X-Powered-By');
}
