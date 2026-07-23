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

    /**
     * Fallback menu items used when database menu is empty.
     */
    public const FALLBACK_ITEMS = [
        ['id' => 101, 'name' => 'Star Lager', 'price' => 700.00, 'category' => 'beer'],
        ['id' => 102, 'name' => 'Hero Lager', 'price' => 650.00, 'category' => 'beer'],
        ['id' => 103, 'name' => 'Gulder', 'price' => 750.00, 'category' => 'beer'],
        ['id' => 104, 'name' => 'Amstel Malta', 'price' => 450.00, 'category' => 'malt'],
        ['id' => 105, 'name' => 'Guinness Malta', 'price' => 500.00, 'category' => 'malt'],
        ['id' => 106, 'name' => 'Coca-Cola', 'price' => 350.00, 'category' => 'soft-drinks'],
        ['id' => 107, 'name' => 'Sprite', 'price' => 350.00, 'category' => 'soft-drinks'],
        ['id' => 108, 'name' => 'Eva', 'price' => 200.00, 'category' => 'water'],
        ['id' => 109, 'name' => 'Aquafina', 'price' => 220.00, 'category' => 'water'],
        ['id' => 110, 'name' => 'Fearless', 'price' => 900.00, 'category' => 'energy-drinks'],
        ['id' => 111, 'name' => 'Predator', 'price' => 950.00, 'category' => 'energy-drinks'],
        ['id' => 112, 'name' => 'Five Alive', 'price' => 600.00, 'category' => 'juice'],
        ['id' => 113, 'name' => 'Chi Exotic', 'price' => 650.00, 'category' => 'juice'],
        ['id' => 114, 'name' => 'Jameson', 'price' => 9500.00, 'category' => 'spirits'],
        ['id' => 115, 'name' => 'Black Label', 'price' => 9000.00, 'category' => 'spirits'],
        ['id' => 116, 'name' => 'Smirnoff Ice', 'price' => 950.00, 'category' => 'ready-to-drink'],
        ['id' => 117, 'name' => 'Bacardi Breezer', 'price' => 1000.00, 'category' => 'ready-to-drink'],
        ['id' => 201, 'name' => 'Jollof Rice', 'price' => 2400.00, 'category' => 'rice'],
        ['id' => 202, 'name' => 'Fried Rice', 'price' => 2500.00, 'category' => 'rice'],
        ['id' => 203, 'name' => 'White Rice', 'price' => 2200.00, 'category' => 'rice'],
        ['id' => 204, 'name' => 'Coconut Rice', 'price' => 2600.00, 'category' => 'rice'],
        ['id' => 301, 'name' => 'Goat Meat Pepper Soup', 'price' => 3200.00, 'category' => 'pepper-soup'],
        ['id' => 302, 'name' => 'Cow Tail Pepper Soup', 'price' => 3400.00, 'category' => 'pepper-soup'],
        ['id' => 303, 'name' => 'Catfish Pepper Soup', 'price' => 3600.00, 'category' => 'pepper-soup'],
        ['id' => 304, 'name' => 'Chicken Pepper Soup', 'price' => 3000.00, 'category' => 'pepper-soup'],
        ['id' => 305, 'name' => 'Assorted Meat Pepper Soup', 'price' => 3500.00, 'category' => 'pepper-soup'],
        ['id' => 401, 'name' => 'Catfish Grill', 'price' => 4200.00, 'category' => 'grills'],
        ['id' => 402, 'name' => 'Ram Meat', 'price' => 4800.00, 'category' => 'grills'],
        ['id' => 403, 'name' => 'Suya', 'price' => 2800.00, 'category' => 'grills'],
        ['id' => 404, 'name' => 'Chicken Grill', 'price' => 3200.00, 'category' => 'grills'],
        ['id' => 405, 'name' => 'Turkey Grill', 'price' => 3600.00, 'category' => 'grills'],
        ['id' => 406, 'name' => 'Gizzard', 'price' => 2200.00, 'category' => 'grills'],
        ['id' => 501, 'name' => 'Egusi Soup', 'price' => 3000.00, 'category' => 'soups'],
        ['id' => 502, 'name' => 'Ogbono Soup', 'price' => 3000.00, 'category' => 'soups'],
        ['id' => 503, 'name' => 'Vegetable Soup', 'price' => 2600.00, 'category' => 'soups'],
        ['id' => 504, 'name' => 'Okra Soup', 'price' => 2800.00, 'category' => 'soups'],
        ['id' => 505, 'name' => 'Oha Soup', 'price' => 3100.00, 'category' => 'soups'],
        ['id' => 601, 'name' => 'Pounded Yam', 'price' => 1800.00, 'category' => 'swallow'],
        ['id' => 602, 'name' => 'Eba', 'price' => 1600.00, 'category' => 'swallow'],
        ['id' => 603, 'name' => 'Semovita', 'price' => 1700.00, 'category' => 'swallow'],
        ['id' => 604, 'name' => 'Amala', 'price' => 1800.00, 'category' => 'swallow'],
        ['id' => 701, 'name' => 'Plantain', 'price' => 1200.00, 'category' => 'extras'],
        ['id' => 702, 'name' => 'Moi Moi', 'price' => 1400.00, 'category' => 'extras'],
        ['id' => 703, 'name' => 'Coleslaw', 'price' => 800.00, 'category' => 'extras'],
        ['id' => 704, 'name' => 'Salad', 'price' => 900.00, 'category' => 'extras'],
        ['id' => 705, 'name' => 'French Fries', 'price' => 1000.00, 'category' => 'extras'],
        ['id' => 706, 'name' => 'Marlboro Red', 'price' => 1500.00, 'category' => 'cigarettes'],
        ['id' => 707, 'name' => 'Royal Classic', 'price' => 1400.00, 'category' => 'cigarettes'],
    ];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Get menu items, optionally filtered by category.
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

        // Fallback to static items if DB has no results
        if (empty($items)) {
            $items = self::FALLBACK_ITEMS;
            if ($category !== null) {
                $items = array_values(array_filter($items, function (array $item) use ($category): bool {
                    return ($item['category'] ?? '') === $category;
                }));
            }
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
     * Get a single menu item by ID.
     */
    public function getById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, description, price, category, available FROM menu_items WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $item = $stmt->fetch();

        if (!$item) {
            // Check fallback
            foreach (self::FALLBACK_ITEMS as $fallback) {
                if ($fallback['id'] === $id) {
                    return $fallback;
                }
            }
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
     * Ensure a fallback menu item exists in the database.
     */
    public function ensureFallbackItem(int $menuItemId): ?array
    {
        if (!isset(self::FALLBACK_ITEMS[$menuItemId])) {
            // Search by id in fallback array
            $fallback = null;
            foreach (self::FALLBACK_ITEMS as $item) {
                if ($item['id'] === $menuItemId) {
                    $fallback = $item;
                    break;
                }
            }
            if (!$fallback) {
                return null;
            }
        } else {
            $fallback = self::FALLBACK_ITEMS[$menuItemId];
        }

        try {
            $stmt = $this->pdo->prepare(
                'INSERT IGNORE INTO menu_items (id, name, description, price, category, available, created_at)
                 VALUES (:id, :name, :description, :price, :category, 1, :created_at)'
            );
            $stmt->execute([
                ':id' => $fallback['id'],
                ':name' => $fallback['name'],
                ':description' => '',
                ':price' => $fallback['price'],
                ':category' => $fallback['category'],
                ':created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Ignore insert failures
        }

        return $this->getById($menuItemId);
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
