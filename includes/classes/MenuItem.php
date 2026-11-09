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
        $conditions = [\restaurant_menu_scope_sql('menu_items')];

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
            'SELECT id, name, description, price, category, available
             FROM menu_items
             WHERE id = :id AND ' . \restaurant_menu_scope_sql('menu_items') . '
             LIMIT 1'
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

        $user = \get_auth_user() ?? [];
        $restaurantId = (int)($user['restaurant_id'] ?? 0);
        $chainId = null;
        if ($restaurantId > 0) {
            $chainStmt = $this->pdo->prepare('SELECT chain_id FROM restaurants WHERE id = :id LIMIT 1');
            $chainStmt->execute([':id' => $restaurantId]);
            $chainValue = $chainStmt->fetchColumn();
            $chainId = $chainValue === false || $chainValue === null ? null : (int)$chainValue;
        }
        if (($user['role'] ?? '') !== 'super_admin' && $restaurantId <= 0) {
            throw new InvalidArgumentException('The account is not assigned to a restaurant.');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO menu_items (name, description, price, category, available, restaurant_id, chain_id, demo_owner_id, created_at)
             VALUES (:name, :description, :price, :category, :available, :restaurant_id, :chain_id, :demo_owner_id, :created_at)'
        );
        $stmt->execute([
            ':name' => $name,
            ':description' => $description,
            ':price' => $price,
            ':category' => $category,
            ':available' => $available,
            ':restaurant_id' => $restaurantId ?: null,
            ':chain_id' => $chainId,
            ':demo_owner_id' => !empty($user['is_demo']) ? (int)$user['id'] : null,
            ':created_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Update a menu item's price.
     */
    public function updatePrice(int $id, float $price): bool
    {
        $stmt = $this->pdo->prepare('UPDATE menu_items SET price = :price WHERE id = :id AND ' . \restaurant_menu_scope_sql('menu_items'));
        $stmt->execute([':price' => $price, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Update a menu item's availability.
     */
    public function setAvailability(int $id, int $available): bool
    {
        $stmt = $this->pdo->prepare('UPDATE menu_items SET available = :available WHERE id = :id AND ' . \restaurant_menu_scope_sql('menu_items'));
        $stmt->execute([':available' => $available, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete a menu item.
     */
    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM menu_items WHERE id = :id AND ' . \demo_data_scope_sql('menu_items'));
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
