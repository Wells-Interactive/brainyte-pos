<?php
declare(strict_types=1);

namespace App;

use PDO;

/**
 * Table Management
 * 
 * Handles restaurant table CRUD operations and status management.
 */
class TableManager
{
    private PDO $pdo;

    public const ALLOWED_STATUSES = ['available', 'occupied', 'reserved', 'closed'];
    public const TOTAL_TABLES = 20;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Get all tables with their current status.
     */
    public function getAll(): array
    {
        try {
            $rows = $this->pdo->query('SELECT id, name, status FROM restaurant_tables ORDER BY id')->fetchAll();
            $tableMap = [];
            foreach ($rows as $table) {
                $tableMap[(int)$table['id']] = $table;
            }

            $tables = [];
            for ($i = 1; $i <= self::TOTAL_TABLES; $i++) {
                if (isset($tableMap[$i])) {
                    $tables[] = $tableMap[$i];
                } else {
                    $tables[] = ['id' => $i, 'name' => "Table {$i}", 'status' => 'available'];
                }
            }

            return $tables;
        } catch (\Throwable $e) {
            // Return default tables
            $tables = [];
            for ($i = 1; $i <= self::TOTAL_TABLES; $i++) {
                $tables[] = ['id' => $i, 'name' => "Table {$i}", 'status' => 'available'];
            }
            return $tables;
        }
    }

    /**
     * Get a single table by ID.
     */
    public function getById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, status FROM restaurant_tables WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $table = $stmt->fetch();
        return $table ?: null;
    }

    /**
     * Update table status.
     */
    public function updateStatus(int $id, string $status): bool
    {
        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            throw new \InvalidArgumentException("Invalid table status: {$status}");
        }

        $stmt = $this->pdo->prepare('UPDATE restaurant_tables SET status = :status WHERE id = :id');
        $stmt->execute([':status' => $status, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Ensure a table exists, creating it if necessary.
     */
    public function ensureExists(int $id, string $name = ''): void
    {
        $table = $this->getById($id);
        if (!$table) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO restaurant_tables (id, name, status, created_at) 
                 VALUES (:id, :name, :status, :created_at)'
            );
            $stmt->execute([
                ':id' => $id,
                ':name' => $name ?: "Table {$id}",
                ':status' => 'available',
                ':created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * Create a new table.
     */
    public function create(string $name, string $status = 'available'): int
    {
        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            $status = 'available';
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO restaurant_tables (name, status, created_at) VALUES (:name, :status, :created_at)'
        );
        $stmt->execute([
            ':name' => $name,
            ':status' => $status,
            ':created_at' => date('Y-m-d H:i:s'),
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Get all available (free) tables.
     */
    public function getAvailable(): array
    {
        return array_values(array_filter($this->getAll(), function ($table) {
            return $table['status'] === 'available';
        }));
    }

    /**
     * Get all occupied tables.
     */
    public function getOccupied(): array
    {
        return array_values(array_filter($this->getAll(), function ($table) {
            return $table['status'] === 'occupied';
        }));
    }
}
