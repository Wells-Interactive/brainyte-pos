<?php
declare(strict_types=1);

namespace App;

/**
 * Centralized Authorization & Permission System
 *
 * Defines all permissions and role-to-permission mappings in one place.
 * All API endpoints and UI pages should use require_permission() or has_permission()
 * instead of scattered role checks.
 *
 * Usage:
 *   require_permission('orders.create');      // checks authenticated user
 *   require_permission(['orders.create', 'orders.edit']);  // any of these
 *   has_permission('reports.view');           // returns bool
 */
class Permission
{
    /**
     * All available permissions in the system.
     * These are the fine-grained capabilities that can be assigned to roles.
     *
     * Naming convention: <resource>.<action>
     */
    public const PERMISSIONS = [
        'orders.create',
        'orders.view',
        'orders.edit',
        'orders.cancel',
        'orders.complete',
        'orders.assign',
        'orders.update_status',
        'payments.record',
        'payments.view',
        'payments.refund',
        'payments.void',
        'reports.view',
        'reports.export',
        'users.manage',
        'users.view',
        'users.create',
        'users.edit',
        'users.delete',
        'users.roles',
        'menu.manage',
        'menu.create',
        'menu.edit',
        'menu.delete',
        'settings.manage',
        'settings.view',
        'settings.edit',
        'tables.manage',
        'tables.view',
        'tables.edit',
        'notifications.send',
        'notifications.view',
        'audit.view',
        'system.config',
        'system.maintenance',
        'print.manage',
        'print.view',
        'inventory.manage',
        'inventory.view',
    ];

    /**
     * Role-to-permission mapping.
     * Each role is assigned a set of permissions.
     * Higher roles inherit all permissions from lower roles.
     *
     * Role hierarchy (high to low):
     *   owner > admin > manager > supervisor > waiter/kitchen/bar
     */
    private const ROLE_PERMISSIONS = [
        'owner' => [
            // Owners have access to EVERYTHING
            '*',
        ],
        'admin' => [
            // Admins have almost all permissions
            'orders.create', 'orders.view', 'orders.edit', 'orders.cancel', 'orders.complete', 'orders.assign', 'orders.update_status',
            'payments.record', 'payments.view', 'payments.refund', 'payments.void',
            'reports.view', 'reports.export',
            'users.manage', 'users.view', 'users.create', 'users.edit', 'users.delete', 'users.roles',
            'menu.manage', 'menu.create', 'menu.edit', 'menu.delete',
            'settings.manage', 'settings.view', 'settings.edit',
            'tables.manage', 'tables.view', 'tables.edit',
            'notifications.send', 'notifications.view',
            'audit.view',
            'system.config',
            'print.manage', 'print.view',
            'inventory.manage', 'inventory.view',
        ],
        'manager' => [
            'orders.create', 'orders.view', 'orders.edit', 'orders.cancel', 'orders.complete', 'orders.update_status',
            'payments.record', 'payments.view', 'payments.refund',
            'reports.view', 'reports.export',
            'users.view',
            'menu.manage', 'menu.create', 'menu.edit', 'menu.delete',
            'settings.view',
            'tables.manage', 'tables.view', 'tables.edit',
            'notifications.view', 'notifications.send',
            'print.view',
            'inventory.view',
        ],
        'supervisor' => [
            'orders.view', 'orders.edit', 'orders.cancel', 'orders.complete', 'orders.update_status',
            'payments.view',
            'reports.view',
            'users.view',
            'menu.view',
            'settings.view',
            'tables.view',
            'notifications.view',
            'print.view',
            'inventory.view',
        ],
        'waiter' => [
            'orders.create', 'orders.view', 'orders.edit', 'orders.complete',
            'payments.record', 'payments.view',
            'menu.view',
            'tables.view',
            'notifications.view',
            'print.view',
        ],
        'kitchen' => [
            'orders.view',
            'orders.update_status',
            'menu.view',
            'tables.view',
            'notifications.view',
            'print.view',
        ],
        'bar' => [
            'orders.view',
            'orders.update_status',
            'menu.view',
            'tables.view',
            'notifications.view',
            'print.view',
        ],
    ];

    /**
     * Check if a user has a specific permission.
     *
     * @param array $user     Authenticated user array (must have 'role' key)
     * @param string $permission The permission to check (e.g. 'orders.create')
     * @return bool
     */
    public static function hasPermission(array $user, string $permission): bool
    {
        $role = $user['role'] ?? '';

        if (!isset(self::ROLE_PERMISSIONS[$role])) {
            return false;
        }

        $perms = self::ROLE_PERMISSIONS[$role];

        // Owner wildcard
        if (in_array('*', $perms, true)) {
            return true;
        }

        return in_array($permission, $perms, true);
    }

    /**
     * Check if user has any of the given permissions.
     *
     * @param array $user
     * @param string|string[] $permissions One or more permissions to check
     * @return bool
     */
    public static function hasAnyPermission(array $user, array|string $permissions): bool
    {
        if (is_string($permissions)) {
            $permissions = [$permissions];
        }

        foreach ($permissions as $perm) {
            if (self::hasPermission($user, $perm)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if user has ALL of the given permissions.
     *
     * @param array $user
     * @param string[] $permissions List of permissions to check
     * @return bool
     */
    public static function hasAllPermissions(array $user, array $permissions): bool
    {
        foreach ($permissions as $perm) {
            if (!self::hasPermission($user, $perm)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Require a specific permission. Sends 403 and exits if not allowed.
     *
     * @param string $permission The required permission
     * @param array|null $user   Optional pre-fetched user (calls get_auth_user() if null)
     * @return array The authenticated user
     */
    public static function require(string $permission, ?array $user = null): array
    {
        if ($user === null) {
            $user = require_auth();
        } else {
            // Still verify user is authenticated
            if (empty($user['role'])) {
                http_response_code(401);
                echo json_encode(['success' => false, 'data' => null, 'error' => 'Authentication required', 'meta' => null]);
                exit;
            }
        }

        if (!self::hasPermission($user, $permission)) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'data' => null,
                'error' => 'Forbidden: insufficient permissions',
                'meta' => ['required_permission' => $permission, 'user_role' => $user['role']],
            ]);
            exit;
        }

        return $user;
    }

    /**
     * Require any of the given permissions.
     *
     * @param string|string[] $permissions
     * @param array|null $user
     * @return array
     */
    public static function requireAny(array|string $permissions, ?array $user = null): array
    {
        if ($user === null) {
            $user = require_auth();
        } else {
            if (empty($user['role'])) {
                http_response_code(401);
                echo json_encode(['success' => false, 'data' => null, 'error' => 'Authentication required', 'meta' => null]);
                exit;
            }
        }

        if (is_string($permissions)) {
            $permissions = [$permissions];
        }

        if (!self::hasAnyPermission($user, $permissions)) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'data' => null,
                'error' => 'Forbidden: insufficient permissions',
                'meta' => ['required_permissions' => $permissions, 'user_role' => $user['role']],
            ]);
            exit;
        }

        return $user;
    }

    /**
     * Get all permissions for a specific role.
     *
     * @param string $role
     * @return string[]
     */
    public static function getRolePermissions(string $role): array
    {
        if ($role === 'owner') {
            return self::PERMISSIONS; // Owner has everything
        }

        return self::ROLE_PERMISSIONS[$role] ?? [];
    }

    /**
     * Check if a role exists in the system.
     */
    public static function isValidRole(string $role): bool
    {
        return isset(self::ROLE_PERMISSIONS[$role]);
    }

    /**
     * Get all available roles.
     */
    public static function getAllRoles(): array
    {
        return array_keys(self::ROLE_PERMISSIONS);
    }

    /**
     * Get all available permissions list.
     */
    public static function getAllPermissions(): array
    {
        return self::PERMISSIONS;
    }

    /**
     * Check if a permission string is valid.
     */
    public static function isValidPermission(string $permission): bool
    {
        return in_array($permission, self::PERMISSIONS, true);
    }
}

