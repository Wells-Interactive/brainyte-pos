<?php
declare(strict_types=1);

namespace App;

use PDO;
use InvalidArgumentException;

/**
 * Menu Item Management
 * 
 * Handles CRUD operations for menu items.
 * Uses prepared statements throughout for security.
 */
class MenuItem
{
    private PDO $pdo;

    public const ALLOWED_CATEGORIES = [
        'beer', 'malt', 'soft-drinks', 'water', 'energy-drinks', 'juice',
        'spirits', 'ready-to-drink', 'rice', 'pepper-soup', 'grills',
        'soups', 'swallow', 'extras', 'cigarettes',
    ];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Get menu items, optionally filtered by category.
     * The database is the single source of truth.
     */
    public function getAll(?string $category = null, bool $onlyAvailable = true): array
    {
        $sql = 'SELECT id, name, description, price, category, available FROM menu_items';
        $params = [];
        $conditions = [];

        if ($onlyAvailable) {
            $conditions[] = 'available = 1';
        }

        if ($category !== null && in_array($category, self::ALLOWED_CATEGORIES, true)) {
            $conditions[] = 'category = :category';
            $params[':category'] = $category;
        }

        if (!empty($conditions)) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY category, name';

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $items = $stmt->fetchAll();
        } catch (\Throwable $e) {
            $items = [];
        }

        return array_map(function ($item) {
            return [
                'id' => (int)($item['id'] ?? 0),
                'name' => $item['name'] ?? '',
                'description' => $item['description'] ?? '',
                'price' => (float)($item['price'] ?? 0),
                'category' => $item['category'] ?? '',
                'available' => (int)($item['available'] ?? 1),
            ];
        }, $items);
    }

    /**
     * Get a single menu item by ID from the database (authoritative source).
     */
    public function getById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, description, price, category, available FROM menu_items WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $item = $stmt->fetch();

        if (!$item) {
            return null;
        }

        return [
            'id' => (int)$item['id'],
            'name' => $item['name'],
            'description' => $item['description'],
            'price' => (float)$item['price'],
            'category' => $item['category'],
            'available' => (int)$item['available'],
        ];
    }

    /**
     * Create a new menu item.
     */
    public function create(string $name, string $description, float $price, string $category, int $available = 1): int
    {
        if (!in_array($category, self::ALLOWED_CATEGORIES, true)) {
            throw new InvalidArgumentException("Invalid category: {$category}");
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO menu_items (name, description, price, category, available, created_at) 
             VALUES (:name, :description, :price, :category, :available, :created_at)'
        );
        $stmt->execute([
            ':name' => $name,
            ':description' => $description,
            ':price' => $price,
            ':category' => $category,
            ':available' => $available,
            ':created_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->pdo->lastInsertId();
    }

    /**
     * Update a menu item's price.
     */
    public function updatePrice(int $id, float $price): bool
    {
        $stmt = $this->pdo->prepare('UPDATE menu_items SET price = :price WHERE id = :id');
        $stmt->execute([':price' => $price, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Update a menu item's availability.
     */
    public function setAvailability(int $id, int $available): bool
    {
        $stmt = $this->pdo->prepare('UPDATE menu_items SET available = :available WHERE id = :id');
        $stmt->execute([':available' => $available, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete a menu item.
     */
    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM menu_items WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Route an item to kitchen or bar based on category.
     */
    public static function getRouting(string $category): string
    {
        $foodCategories = ['rice', 'pepper-soup', 'grills', 'soups', 'swallow', 'extras'];
        return in_array($category, $foodCategories, true) ? 'kitchen' : 'bar';
    }
}
