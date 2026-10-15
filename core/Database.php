<?php
declare(strict_types=1);

/**
 * CivicShield - PDO Database Connection Manager (Singleton Pattern)
 */
class Database
{
    private static ?Database $instance = null;
    private ?PDO $pdo = null;

    private function __construct()
    {
        $configFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
        if (!file_exists($configFile)) {
            throw new RuntimeException("Database configuration file not found at: {$configFile}");
        }

        $config = require $configFile;

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['database'],
            $config['charset']
        );

        try {
            $this->pdo = new PDO(
                $dsn,
                $config['username'],
                $config['password'],
                $config['options'] ?? []
            );
        } catch (PDOException $e) {
            // Avoid leaking credentials in the error message
            $safeMsg = "Database Connection Failed: " . $e->getMessage();
            throw new RuntimeException($safeMsg, (int)$e->getCode(), $e);
        }
    }

    // Prevent cloning and unserialization
    private function __clone() {}
    public function __wakeup()
    {
        throw new RuntimeException("Cannot unserialize Database singleton.");
    }

    /**
     * Get singleton instance of Database manager.
     */
    public static function getInstance(): Database
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get active PDO connection.
     */
    public static function getConnection(): PDO
    {
        return self::getInstance()->pdo;
    }

    /**
     * Test database connectivity.
     *
     * @return array [bool $connected, string $message]
     */
    public static function checkHealth(): array
    {
        try {
            $pdo = self::getConnection();
            $stmt = $pdo->query('SELECT 1');
            if ($stmt && $stmt->fetchColumn() === 1) {
                return [
                    'connected' => true,
                    'message'   => 'Database connection healthy.'
                ];
            }
            return [
                'connected' => false,
                'message'   => 'Database query returned unexpected result.'
            ];
        } catch (Throwable $e) {
            return [
                'connected' => false,
                'message'   => $e->getMessage()
            ];
        }
    }
}
