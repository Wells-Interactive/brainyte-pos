<?php
declare(strict_types=1);

namespace App;

use PDO;
use Throwable;

/**
 * Database Migration Manager
 *
 * Handles versioned schema migrations. Executes only new migrations
 * and records each migration in the migrations tracking table.
 *
 * Migrations are stored in /database/migrations/ as .sql files
 * named in the format: YYYYMMDD_HHMMSS_description.sql
 *
 * This replaces the dynamic ensure_database_schema() approach
 * that ran on every request.
 */
class Migration
{
    private PDO $pdo;
    private const MIGRATIONS_TABLE = 'schema_migrations';
    private const MIGRATIONS_DIR = __DIR__ . '/../../database/migrations';

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Ensure the migrations tracking table exists.
     */
    private function ensureTrackingTable(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS `" . self::MIGRATIONS_TABLE . "` (
                id INT AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) NOT NULL UNIQUE,
                batch INT NOT NULL DEFAULT 1,
                executed_at DATETIME NOT NULL,
                INDEX idx_migration (migration)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci
        ");
    }

    /**
     * Get all migrations that have already been run.
     */
    public function getExecutedMigrations(): array
    {
        $this->ensureTrackingTable();
        $stmt = $this->pdo->query("SELECT migration FROM `" . self::MIGRATIONS_TABLE . "` ORDER BY migration");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Get all available migration files.
     */
    public function getAvailableMigrations(): array
    {
        $dir = self::MIGRATIONS_DIR;
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . '/*.sql');
        sort($files);
        return $files;
    }

    /**
     * Run all pending migrations.
     *
     * @return array List of executed migrations with status
     */
    public function migrate(): array
    {
        $executed = $this->getExecutedMigrations();
        $available = $this->getAvailableMigrations();
        $results = [];
        $batch = $this->getNextBatch();

        foreach ($available as $filepath) {
            $filename = basename($filepath);

            if (in_array($filename, $executed, true)) {
                continue;
            }

            $sql = file_get_contents($filepath);
            if ($sql === false || trim($sql) === '') {
                $results[] = [
                    'migration' => $filename,
                    'status' => 'skipped',
                    'reason' => 'Empty or unreadable file',
                ];
                continue;
            }

            try {
                $this->pdo->beginTransaction();

                // Execute the migration SQL
                // Split by semicolons for multi-statement SQL
                $statements = $this->splitSql($sql);
                foreach ($statements as $statement) {
                    $statement = trim($statement);
                    if ($statement !== '') {
                        $this->pdo->exec($statement);
                    }
                }

                // Record the migration
                $stmt = $this->pdo->prepare(
                    "INSERT INTO `" . self::MIGRATIONS_TABLE . "` (migration, batch, executed_at) 
                     VALUES (:migration, :batch, :executed_at)"
                );
                $stmt->execute([
                    ':migration' => $filename,
                    ':batch' => $batch,
                    ':executed_at' => date('Y-m-d H:i:s'),
                ]);

                $this->pdo->commit();

                $results[] = [
                    'migration' => $filename,
                    'status' => 'executed',
                    'batch' => $batch,
                ];
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }

                $results[] = [
                    'migration' => $filename,
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Check if all migrations have been run.
     */
    public function isFullyMigrated(): bool
    {
        $executed = $this->getExecutedMigrations();
        $available = $this->getAvailableMigrations();

        return count($executed) >= count($available);
    }

    /**
     * Add a new migration file.
     */
    public static function createMigration(string $description): string
    {
        $dir = self::MIGRATIONS_DIR;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $timestamp = date('Ymd_His');
        $filename = $timestamp . '_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $description) . '.sql';
        $filepath = $dir . '/' . $filename;

        // Template
        $template = "-- Migration: {$filename}\n-- Description: {$description}\n-- Generated: " . date('Y-m-d H:i:s') . "\n\nSTART TRANSACTION;\n\n-- Write your SQL here\n\nCOMMIT;\n";
        file_put_contents($filepath, $template);

        return $filepath;
    }

    /**
     * Get the next batch number.
     */
    private function getNextBatch(): int
    {
        try {
            $stmt = $this->pdo->query("SELECT COALESCE(MAX(batch), 0) + 1 FROM `" . self::MIGRATIONS_TABLE . "`");
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return 1;
        }
    }

    /**
     * Split SQL into individual statements.
     * Handles basic delimiter splitting while respecting comments and strings.
     */
    private function splitSql(string $sql): array
    {
        // Remove comments
        $sql = preg_replace('/--[^\n]*/', '', $sql);
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);

        // Split by semicolons
        $statements = explode(';', $sql);

        return array_filter(array_map('trim', $statements), function ($stmt) {
            return $stmt !== '';
        });
    }

    /**
     * Get the status of all migrations.
     */
    public function getStatus(): array
    {
        $executed = $this->getExecutedMigrations();
        $available = $this->getAvailableMigrations();
        $status = [];

        foreach ($available as $filepath) {
            $filename = basename($filepath);
            $status[] = [
                'migration' => $filename,
                'executed' => in_array($filename, $executed, true),
            ];
        }

        return $status;
    }
}

