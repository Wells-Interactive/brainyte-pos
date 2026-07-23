<?php
declare(strict_types=1);

namespace App;

use PDO;
use PDOException;
use Throwable;

/**
 * Singleton Database Connection
 * 
 * Provides a centralized PDO connection with automatic schema initialization.
 */
class Database
{
    private static ?Database $instance = null;
    private PDO $pdo;

    private const DB_HOST = DB_HOST;
    private const DB_NAME = DB_NAME;
    private const DB_USER = DB_USER;
    private const DB_PASS = DB_PASS;

    private function __construct()
    {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', self::DB_HOST, self::DB_NAME);
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        try {
            $this->pdo = new PDO($dsn, self::DB_USER, self::DB_PASS, $options);
        } catch (PDOException $e) {
            throw new \RuntimeException('Database connection failed: ' . $e->getMessage());
        }
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection(): PDO
    {
        return $this->pdo;
    }

    /**
     * Begin a database transaction.
     */
    public function beginTransaction(): void
    {
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
        }
    }

    /**
     * Commit the current transaction.
     */
    public function commit(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->commit();
        }
    }

    /**
     * Roll back the current transaction.
     */
    public function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /**
     * Execute a query with parameters and return the statement.
     */
    public function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Fetch a single row.
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->query($sql, $params);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Fetch all rows.
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll();
    }

    /**
     * Get the last inserted ID.
     */
    public function lastInsertId(): int
    {
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Check if a table exists.
     */
    public function tableExists(string $tableName): bool
    {
        try {
            $this->pdo->query("SELECT 1 FROM `{$tableName}` LIMIT 1");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Check if a column exists in a table.
     */
    public function columnExists(string $table, string $column): bool
    {
        $stmt = $this->pdo->query(
            sprintf('SHOW COLUMNS FROM `%s` LIKE %s', $table, $this->pdo->quote($column))
        );
        return (bool)$stmt->fetch();
    }

    /**
     * Add a column to a table if it doesn't exist.
     */
    public function ensureColumn(string $table, string $column, string $definition): void
    {
        if ($this->columnExists($table, $column)) {
            return;
        }
        $this->pdo->exec(sprintf('ALTER TABLE `%s` ADD COLUMN `%s` %s', $table, $column, $definition));
    }

    /**
     * Modify a column's enum values if needed.
     */
    public function ensureEnum(string $table, string $column, string $enumValues): void
    {
        $stmt = $this->pdo->query(sprintf('SHOW COLUMNS FROM `%s` LIKE %s', $table, $this->pdo->quote($column)));
        $colInfo = $stmt->fetch();
        if ($colInfo === false) {
            return;
        }

        $currentType = (string)($colInfo['Type'] ?? '');
        $neededValues = explode(',', $enumValues);
        $missing = false;
        foreach ($neededValues as $val) {
            $val = trim($val, " '");
            if (stripos($currentType, $val) === false) {
                $missing = true;
                break;
            }
        }

        if ($missing) {
            $this->pdo->exec(sprintf('ALTER TABLE `%s` MODIFY `%s` %s', $table, $column, $enumValues));
        }
    }
}
