<?php
declare(strict_types=1);

namespace App;

use PDO;
use InvalidArgumentException;
use Throwable;

/**
 * Inventory Management
 * 
 * Handles all inventory operations:
 * - Stock tracking per menu item (current_stock, min_stock_level)
 * - Stock adjustments with reason (audit trail)
 * - Auto-deduction from completed orders (stock_out)
 * - Low stock alerts / auto out-of-stock management
 * - Full movement history with who, what, when, why
 */
class Inventory
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
	{
    		$this->pdo = $pdo;
    		$this->ensureTables();
	}

    /**
     * Ensure inventory tables exist (backward compatible).
     */
    private function ensureTables(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS `inventory_items` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `menu_item_id` INT NOT NULL,
            `current_stock` DECIMAL(9,2) NOT NULL DEFAULT 0.00,
            `min_stock_level` DECIMAL(9,2) NOT NULL DEFAULT 0.00,
            `unit` VARCHAR(30) NOT NULL DEFAULT 'pieces',
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            INDEX `idx_inventory_menu_item` (`menu_item_id`),
            INDEX `idx_inventory_stock_level` (`current_stock`),
            INDEX `idx_inventory_min_stock` (`min_stock_level`),
            CONSTRAINT `fk_inventory_menu_item` FOREIGN KEY (`menu_item_id`) REFERENCES `menu_items`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS `inventory_movements` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `inventory_item_id` INT NOT NULL,
            `type` ENUM('stock_in', 'stock_out', 'adjustment') NOT NULL,
            `quantity` DECIMAL(9,2) NOT NULL,
            `previous_qty` DECIMAL(9,2) NOT NULL DEFAULT 0.00,
            `new_qty` DECIMAL(9,2) NOT NULL DEFAULT 0.00,
            `reference_type` VARCHAR(50) DEFAULT NULL,
            `reference_id` INT DEFAULT NULL,
            `reason` TEXT DEFAULT NULL,
            `performed_by` INT DEFAULT NULL,
            `created_at` DATETIME NOT NULL,
            INDEX `idx_im_inventory_item` (`inventory_item_id`),
            INDEX `idx_im_type` (`type`),
            INDEX `idx_im_created` (`created_at`),
            INDEX `idx_im_reference` (`reference_type`, `reference_id`),
            CONSTRAINT `fk_im_inventory_item` FOREIGN KEY (`inventory_item_id`) REFERENCES `inventory_items`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_im_user` FOREIGN KEY (`performed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

   

    /**
     * Get all inventory items with related menu item data.
     */
    public function getAll(): array
    {
        $inventoryScope = \demo_data_scope_sql('ii');
        $menuScope = \demo_data_scope_sql('mi');
        $sql = "SELECT ii.*, mi.name AS menu_item_name, mi.category, mi.price, mi.available,
                       (ii.current_stock <= ii.min_stock_level) AS is_low_stock,
                       (ii.current_stock <= 0) AS is_out_of_stock
                FROM inventory_items ii
                JOIN menu_items mi ON mi.id = ii.menu_item_id
                WHERE {$inventoryScope} AND {$menuScope}
                ORDER BY mi.category, mi.name";
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll();
    }

    /**
     * Get inventory for a specific menu item.
     */
    public function getByMenuItemId(int $menuItemId): ?array
    {
        $inventoryScope = \demo_data_scope_sql('ii');
        $menuScope = \demo_data_scope_sql('mi');
        $stmt = $this->pdo->prepare(
            "SELECT ii.*, mi.name AS menu_item_name, mi.category, mi.price, mi.available
             FROM inventory_items ii
             JOIN menu_items mi ON mi.id = ii.menu_item_id
             WHERE ii.menu_item_id = :menu_item_id AND {$inventoryScope} AND {$menuScope}
             LIMIT 1"
        );
        $stmt->execute([':menu_item_id' => $menuItemId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Get inventory by ID.
     */
    public function getById(int $id): ?array
    {
        $inventoryScope = \demo_data_scope_sql('ii');
        $menuScope = \demo_data_scope_sql('mi');
        $stmt = $this->pdo->prepare(
            "SELECT ii.*, mi.name AS menu_item_name, mi.category, mi.price, mi.available
             FROM inventory_items ii
             JOIN menu_items mi ON mi.id = ii.menu_item_id
             WHERE ii.id = :id AND {$inventoryScope} AND {$menuScope}
             LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Set minimum stock level for a menu item.
     */
    public function setMinStockLevel(int $menuItemId, float $minStockLevel): bool
    {
        $inv = $this->getByMenuItemId($menuItemId);
        if (!$inv) {
            $this->createInventoryRecord($menuItemId);
        }

        $inventoryScope = \demo_data_scope_sql('inventory_items');
        $stmt = $this->pdo->prepare(
            "UPDATE inventory_items SET min_stock_level = :min_stock_level, updated_at = :updated_at
             WHERE menu_item_id = :menu_item_id AND {$inventoryScope}"
        );
        $stmt->execute([
            ':min_stock_level' => max(0, $minStockLevel),
            ':updated_at' => date('Y-m-d H:i:s'),
            ':menu_item_id' => $menuItemId,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Set unit for a menu item's inventory.
     */
    public function setUnit(int $menuItemId, string $unit): bool
    {
        $inv = $this->getByMenuItemId($menuItemId);
        if (!$inv) {
            $this->createInventoryRecord($menuItemId);
        }

        $inventoryScope = \demo_data_scope_sql('inventory_items');
        $stmt = $this->pdo->prepare(
            "UPDATE inventory_items SET unit = :unit, updated_at = :updated_at
             WHERE menu_item_id = :menu_item_id AND {$inventoryScope}"
        );
        $stmt->execute([
            ':unit' => $unit,
            ':updated_at' => date('Y-m-d H:i:s'),
            ':menu_item_id' => $menuItemId,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Adjust stock for a menu item (increase or decrease).
     * Every adjustment requires a reason.
     * 
     * @param int $menuItemId
     * @param float $quantity  Positive = stock_in, Negative = stock_out
     * @param string $reason   Required reason for adjustment
     * @param int $performedBy User ID making the change
     * @param string $referenceType Optional reference (e.g. 'order', 'purchase', 'adjustment')
     * @param int|null $referenceId Optional reference ID
     * @return array Result with previous_qty, new_qty
     * @throws InvalidArgumentException
     */
    public function adjustStock(
        int $menuItemId,
        float $quantity,
        string $reason,
        int $performedBy,
        string $referenceType = 'adjustment',
        ?int $referenceId = null
    ): array {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Reason is required for stock adjustment');
        }

        if (!(new MenuItem($this->pdo))->getById($menuItemId)) {
            throw new InvalidArgumentException('Menu item not found');
        }

        $inv = $this->getByMenuItemId($menuItemId);
        if (!$inv) {
            $this->createInventoryRecord($menuItemId);
            $inv = $this->getByMenuItemId($menuItemId);
        }
        if (!$inv) {
            throw new InvalidArgumentException('Inventory item not found');
        }

        $inventoryItemId = (int)$inv['id'];
        $previousQty = (float)$inv['current_stock'];
        $newQty = max(0, $previousQty + $quantity);

        $type = $quantity >= 0 ? 'stock_in' : 'stock_out';

        $this->pdo->beginTransaction();
        try {
            // Update stock
            $inventoryScope = \demo_data_scope_sql('inventory_items');
            $updateStmt = $this->pdo->prepare(
                "UPDATE inventory_items SET current_stock = :new_qty, updated_at = :updated_at
                 WHERE id = :id AND {$inventoryScope}"
            );
            $updateStmt->execute([
                ':new_qty' => $newQty,
                ':updated_at' => date('Y-m-d H:i:s'),
                ':id' => $inventoryItemId,
            ]);

            // Record movement
            $this->recordMovement(
                $inventoryItemId,
                $type,
                abs($quantity),
                $previousQty,
                $newQty,
                $referenceType,
                $referenceId,
                $reason,
                $performedBy
            );

            // Chain menu availability is shared, so branch stock must not
            // change it for every other branch.
            if ($newQty <= 0) {
                $this->autoMarkUnavailable($menuItemId);
            } else {
                // Re-enable if stock was zero and now has stock
                $menuStmt = $this->pdo->prepare("SELECT available, chain_id FROM menu_items WHERE id = :id AND " . \demo_data_scope_sql('menu_items'));
                $menuStmt->execute([':id' => $menuItemId]);
                $menuItem = $menuStmt->fetch();
                if ($menuItem && $menuItem['chain_id'] === null && (int)$menuItem['available'] === 0) {
                    $this->pdo->prepare("UPDATE menu_items SET available = 1 WHERE id = :id AND " . \demo_data_scope_sql('menu_items'))
                        ->execute([':id' => $menuItemId]);
                }
            }

            $this->pdo->commit();

            return [
                'inventory_item_id' => $inventoryItemId,
                'menu_item_id' => $menuItemId,
                'previous_qty' => $previousQty,
                'new_qty' => $newQty,
                'quantity' => $quantity,
                'type' => $type,
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Auto-deduct stock when an order is completed.
     * Called from Order::markPaid() or when order status changes to 'completed'.
     * 
     * @param int $orderId The completed order
     * @param int $performedBy User ID who completed the order
     */
    public function autoDeductFromOrder(int $orderId, int $performedBy): array
    {
        $deductions = [];

        // Get all order items for this completed order
        $orderItemScope = \demo_data_scope_sql('oi');
        $menuScope = \demo_data_scope_sql('mi');
        $stmt = $this->pdo->prepare(
            "SELECT oi.menu_item_id, oi.quantity, oi.id AS order_item_id, mi.name AS item_name
             FROM order_items oi
             JOIN menu_items mi ON mi.id = oi.menu_item_id
             WHERE oi.order_id = :order_id AND {$orderItemScope} AND {$menuScope}"
        );
        $stmt->execute([':order_id' => $orderId]);
        $items = $stmt->fetchAll();

        foreach ($items as $item) {
            $menuItemId = (int)$item['menu_item_id'];
            $quantity = -1 * (float)$item['quantity']; // negative = deduction

            try {
                $result = $this->adjustStock(
                    $menuItemId,
                    $quantity,
                    "Automatic deduction from Order #{$orderId}",
                    $performedBy,
                    'order',
                    $orderId
                );
                $deductions[] = $result;
            } catch (Throwable $e) {
                // Log but continue with other items
                error_log("Inventory auto-deduction failed for menu_item_id={$menuItemId}, order_id={$orderId}: " . $e->getMessage());
            }
        }

        return $deductions;
    }

    /**
     * Auto-mark a menu item as unavailable when stock is zero.
     */
    private function autoMarkUnavailable(int $menuItemId): void
    {
        $this->pdo->prepare("UPDATE menu_items SET available = 0 WHERE id = :id AND available = 1 AND chain_id IS NULL AND " . \demo_data_scope_sql('menu_items'))
            ->execute([':id' => $menuItemId]);
    }

    /**
     * Get all items with low stock (current_stock <= min_stock_level and > 0).
     */
    public function getLowStockItems(): array
    {
        $inventoryScope = \demo_data_scope_sql('ii');
        $menuScope = \demo_data_scope_sql('mi');
        $sql = "SELECT ii.*, mi.name AS menu_item_name, mi.category, mi.price, mi.available
                FROM inventory_items ii
                JOIN menu_items mi ON mi.id = ii.menu_item_id
                WHERE ii.current_stock > 0 AND ii.current_stock <= ii.min_stock_level
                  AND {$inventoryScope} AND {$menuScope}
                ORDER BY (ii.min_stock_level - ii.current_stock) DESC";
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll();
    }

    /**
     * Get all out-of-stock items (current_stock <= 0).
     */
    public function getOutOfStockItems(): array
    {
        $inventoryScope = \demo_data_scope_sql('ii');
        $menuScope = \demo_data_scope_sql('mi');
        $sql = "SELECT ii.*, mi.name AS menu_item_name, mi.category, mi.price, mi.available
                FROM inventory_items ii
                JOIN menu_items mi ON mi.id = ii.menu_item_id
                WHERE ii.current_stock <= 0 AND {$inventoryScope} AND {$menuScope}
                ORDER BY mi.category, mi.name";
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll();
    }

    /**
     * Get all stock alerts (low stock + out of stock combined).
     */
    public function getStockAlerts(): array
    {
        return [
            'low_stock' => $this->getLowStockItems(),
            'out_of_stock' => $this->getOutOfStockItems(),
        ];
    }

    /**
     * Get full movement history for an inventory item.
     */
    public function getMovementHistory(int $inventoryItemId, int $limit = 100): array
    {
        $movementScope = \demo_data_scope_sql('im');
        $stmt = $this->pdo->prepare(
            "SELECT im.*, u.name AS performed_by_name
             FROM inventory_movements im
             LEFT JOIN users u ON u.id = im.performed_by
             WHERE im.inventory_item_id = :inventory_item_id
               AND {$movementScope}
             ORDER BY im.created_at DESC
             LIMIT :limit"
        );
        $stmt->bindValue(':inventory_item_id', $inventoryItemId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Get all recent inventory movements (global audit trail).
     */
    public function getRecentMovements(int $limit = 50): array
    {
        $movementScope = \demo_data_scope_sql('im');
        $inventoryScope = \demo_data_scope_sql('ii');
        $menuScope = \demo_data_scope_sql('mi');
        $sql = "SELECT im.*, u.name AS performed_by_name, mi.name AS menu_item_name
                FROM inventory_movements im
                JOIN inventory_items ii ON ii.id = im.inventory_item_id
                JOIN menu_items mi ON mi.id = ii.menu_item_id
                LEFT JOIN users u ON u.id = im.performed_by
                WHERE {$movementScope} AND {$inventoryScope} AND {$menuScope}
                ORDER BY im.created_at DESC
                LIMIT :limit";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Check and update all menu items' available status based on stock.
     * Returns counts of items updated.
     */
    public function syncAvailabilityFromStock(): array
    {
        $inventoryScope = \demo_data_scope_sql('ii');
        $menuScope = \demo_data_scope_sql('m');

        // Mark as unavailable (available=0) when stock <= 0
        $disableStmt = $this->pdo->query(
            "UPDATE menu_items m
             JOIN inventory_items ii ON ii.menu_item_id = m.id
             SET m.available = 0
             WHERE ii.current_stock <= 0 AND m.available = 1 AND m.chain_id IS NULL AND {$inventoryScope} AND {$menuScope}"
        );
        $disabled = $disableStmt->rowCount();

        // Re-enable items that now have stock
        $enableStmt = $this->pdo->query(
            "UPDATE menu_items m
             JOIN inventory_items ii ON ii.menu_item_id = m.id
             SET m.available = 1
             WHERE ii.current_stock > 0 AND m.available = 0 AND m.chain_id IS NULL AND {$inventoryScope} AND {$menuScope}"
        );
        $enabled = $enableStmt->rowCount();

        return [
            'disabled' => $disabled,
            'enabled' => $enabled,
        ];
    }

    /**
     * Create an inventory record for a menu item (if not exists).
     */
    private function createInventoryRecord(int $menuItemId): void
    {
        $now = date('Y-m-d H:i:s');
        $user = \get_auth_user() ?? [];
        $stmt = $this->pdo->prepare(
            "INSERT IGNORE INTO inventory_items (menu_item_id, current_stock, min_stock_level, unit, restaurant_id, created_at, updated_at)
             VALUES (:menu_item_id, 0, 10, 'pieces', :restaurant_id, :created_at, :updated_at)"
        );
        $stmt->execute([
            ':menu_item_id' => $menuItemId,
            ':restaurant_id' => $user['restaurant_id'] ?? null,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    /**
     * Record a stock movement in the audit trail.
     */
    private function recordMovement(
        int $inventoryItemId,
        string $type,
        float $quantity,
        float $previousQty,
        float $newQty,
        string $referenceType,
        ?int $referenceId,
        string $reason,
        int $performedBy
    ): int {
        $user = \get_auth_user() ?? [];
        $stmt = $this->pdo->prepare(
            "INSERT INTO inventory_movements (inventory_item_id, type, quantity, previous_qty, new_qty, reference_type, reference_id, reason, performed_by, restaurant_id, created_at)
             VALUES (:inventory_item_id, :type, :quantity, :previous_qty, :new_qty, :reference_type, :reference_id, :reason, :performed_by, :restaurant_id, :created_at)"
        );
        $stmt->execute([
            ':inventory_item_id' => $inventoryItemId,
            ':type' => $type,
            ':quantity' => $quantity,
            ':previous_qty' => $previousQty,
            ':new_qty' => $newQty,
            ':reference_type' => $referenceType,
            ':reference_id' => $referenceId,
            ':reason' => $reason,
            ':performed_by' => $performedBy,
            ':restaurant_id' => $user['restaurant_id'] ?? null,
            ':created_at' => date('Y-m-d H:i:s'),
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Get inventory statistics for dashboard.
     */
    public function getStatistics(): array
    {
        $stats = [];

        // Total unique items tracked
        $inventoryScope = \demo_data_scope_sql('inventory_items');
        $menuScope = \demo_data_scope_sql('mi');
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM inventory_items WHERE {$inventoryScope}");
        $stats['total_items'] = (int)$stmt->fetchColumn();

        // Items with low stock
        $stmt = $this->pdo->query(
            "SELECT COUNT(*) FROM inventory_items WHERE current_stock > 0 AND current_stock <= min_stock_level AND {$inventoryScope}"
        );
        $stats['low_stock_count'] = (int)$stmt->fetchColumn();

        // Out of stock items
        $stmt = $this->pdo->query(
            "SELECT COUNT(*) FROM inventory_items WHERE current_stock <= 0 AND {$inventoryScope}"
        );
        $stats['out_of_stock_count'] = (int)$stmt->fetchColumn();

        // Total stock value (based on menu price)
        $stmt = $this->pdo->query(
            "SELECT COALESCE(SUM(ii.current_stock * mi.price), 0) AS total_stock_value
             FROM inventory_items ii
             JOIN menu_items mi ON mi.id = ii.menu_item_id
             WHERE {$inventoryScope} AND {$menuScope}"
        );
        $stats['total_stock_value'] = (float)$stmt->fetchColumn();

        return $stats;
    }
}
