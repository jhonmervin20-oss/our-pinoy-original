<?php
/**
 * database.php
 *
 * Handles the single PDO database connection for the
 * Restaurant Management System (restaurant_management_system_db).
 *
 * Usage:
 *   require_once 'database.php';
 *   $db = Database::getInstance()->getConnection();
 *   $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
 *   $stmt->execute([$email]);
 */

class Database
{
    // ---- Connection settings -------------------------------------------
    // Adjust these to match your local / production environment,
    // or better, load them from environment variables.
    private string $host    = 'localhost';
    private string $dbName  = 'restaurant_management_system_db';
    private string $user    = 'root';
    private string $pass    = '';
    private string $charset = 'utf8mb4';
    /** Must match the schema's collation -- see MYSQL_ATTR_INIT_COMMAND below. */
    private string $collation = 'utf8mb4_unicode_ci';

    private static ?Database $instance = null;
    private ?PDO $connection = null;

    /**
     * Private constructor -> use Database::getInstance()
     */
    private function __construct()
    {
        // Allow overriding credentials via environment variables if present
        $this->host   = getenv('DB_HOST') ?: $this->host;
        $this->dbName = getenv('DB_NAME') ?: $this->dbName;
        $this->user   = getenv('DB_USER') ?: $this->user;
        $this->pass   = getenv('DB_PASS') !== false ? getenv('DB_PASS') : $this->pass;

        $dsn = "mysql:host={$this->host};dbname={$this->dbName};charset={$this->charset}";

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,

            // Pin the CONNECTION collation to the one every table actually uses.
            // `charset=utf8mb4` in the DSN issues a bare `SET NAMES utf8mb4`, which
            // picks the charset's default collation -- utf8mb4_general_ci -- while
            // this schema is utf8mb4_unicode_ci throughout (202 columns, 66 tables;
            // the only exceptions are two JSON columns, which are utf8mb4_bin by
            // definition and never string-compared).
            //
            // A mismatch here is not cosmetic. When a general_ci value and a
            // unicode_ci column meet in a comparison at equal coercibility, MariaDB
            // refuses the operation outright with "1271 Illegal mix of collations"
            // rather than picking a winner. That surfaced as the owner dashboard
            // silently falling back to zeros on every KPI, because
            // buildOwnerDashboardData()'s PDOException handler caught it and served
            // the empty defaults.
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$this->charset} COLLATE {$this->collation}",
        ];

        try {
            $this->connection = new PDO($dsn, $this->user, $this->pass, $options);
        } catch (PDOException $e) {
            // Never leak connection details to the end user
            error_log('Database connection failed: ' . $e->getMessage());
            die('Database connection failed. Please try again later.');
        }
    }

    /**
     * Get the single shared Database instance (Singleton pattern).
     */
    public static function getInstance(): Database
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Return the underlying PDO connection.
     */
    public function getConnection(): PDO
    {
        return $this->connection;
    }

    // Prevent cloning and unserialization of the singleton
    private function __clone() {}
    public function __wakeup()
    {
        throw new Exception('Cannot unserialize a Database singleton.');
    }
}