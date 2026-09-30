<?php

namespace Tihloh\Prefab\Users\Services;

use InvalidArgumentException;
use RuntimeException;
use Tihloh\Prefab\DatabaseInterface;
use Tihloh\Prefab\Users\DTOs\Organization;
use Tihloh\Prefab\Users\DTOs\OrganizationMembership;

final class OrganizationManager
{
    /** @var callable|null */
    private $adminAuthorizer;

    public function __construct(
        private DatabaseInterface $database,
        ?callable $adminAuthorizer = null,
    ) {
        $this->adminAuthorizer = $adminAuthorizer;
        $this->ensureSchema();
    }

    public function ensureSchema(): void
    {
        $driver = $this->database->driver();

        if ($driver === 'sqlite') {
            $this->database->statement("CREATE TABLE IF NOT EXISTS prefab_organizations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                parent_id INTEGER NULL,
                code VARCHAR(100) NULL UNIQUE,
                name VARCHAR(191) NOT NULL,
                type VARCHAR(100) NULL,
                active INTEGER NOT NULL DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (parent_id) REFERENCES prefab_organizations(id) ON DELETE SET NULL
            )");
            $this->database->statement("CREATE TABLE IF NOT EXISTS prefab_organization_users (
                organization_id INTEGER NOT NULL,
                user_id VARCHAR(191) NOT NULL,
                role VARCHAR(32) NOT NULL DEFAULT 'member',
                status VARCHAR(32) NOT NULL DEFAULT 'pending',
                is_primary INTEGER NOT NULL DEFAULT 0,
                requested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                approved_at DATETIME NULL,
                approved_by VARCHAR(191) NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (organization_id, user_id),
                FOREIGN KEY (organization_id) REFERENCES prefab_organizations(id) ON DELETE CASCADE
            )");
            return;
        }

        if ($driver === 'pgsql') {
            $this->database->statement("CREATE TABLE IF NOT EXISTS prefab_organizations (
                id BIGSERIAL PRIMARY KEY,
                parent_id BIGINT NULL REFERENCES prefab_organizations(id) ON DELETE SET NULL,
                code VARCHAR(100) NULL UNIQUE,
                name VARCHAR(191) NOT NULL,
                type VARCHAR(100) NULL,
                active SMALLINT NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
            $this->database->statement("CREATE TABLE IF NOT EXISTS prefab_organization_users (
                organization_id BIGINT NOT NULL REFERENCES prefab_organizations(id) ON DELETE CASCADE,
                user_id VARCHAR(191) NOT NULL,
                role VARCHAR(32) NOT NULL DEFAULT 'member',
                status VARCHAR(32) NOT NULL DEFAULT 'pending',
                is_primary SMALLINT NOT NULL DEFAULT 0,
                requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                approved_at TIMESTAMP NULL,
                approved_by VARCHAR(191) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (organization_id, user_id)
            )");
            return;
        }

        if ($driver === 'sqlsrv') {
            $this->database->statement("IF OBJECT_ID(N'prefab_organizations', N'U') IS NULL
                CREATE TABLE prefab_organizations (
                    id BIGINT IDENTITY(1,1) PRIMARY KEY,
                    parent_id BIGINT NULL,
                    code NVARCHAR(100) NULL UNIQUE,
                    name NVARCHAR(191) NOT NULL,
                    type NVARCHAR(100) NULL,
                    active BIT NOT NULL DEFAULT 1,
                    created_at DATETIME2 DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME2 DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_prefab_organizations_parent FOREIGN KEY (parent_id) REFERENCES prefab_organizations(id)
                )");
            $this->database->statement("IF OBJECT_ID(N'prefab_organization_users', N'U') IS NULL
                CREATE TABLE prefab_organization_users (
                    organization_id BIGINT NOT NULL,
                    user_id NVARCHAR(191) NOT NULL,
                    role NVARCHAR(32) NOT NULL DEFAULT 'member',
                    status NVARCHAR(32) NOT NULL DEFAULT 'pending',
                    is_primary BIT NOT NULL DEFAULT 0,
                    requested_at DATETIME2 DEFAULT CURRENT_TIMESTAMP,
                    approved_at DATETIME2 NULL,
                    approved_by NVARCHAR(191) NULL,
                    created_at DATETIME2 DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME2 DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT pk_prefab_organization_users PRIMARY KEY (organization_id, user_id),
                    CONSTRAINT fk_prefab_org_users_org FOREIGN KEY (organization_id) REFERENCES prefab_organizations(id) ON DELETE CASCADE
                )");
            return;
        }

        if ($driver !== 'mysql') {
            throw new RuntimeException("Unsupported organization database driver '{$driver}'.");
        }

        $this->database->statement("CREATE TABLE IF NOT EXISTS prefab_organizations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            parent_id BIGINT UNSIGNED NULL,
            code VARCHAR(100) NULL,
            name VARCHAR(191) NOT NULL,
            type VARCHAR(100) NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_prefab_organization_code (code),
            INDEX idx_prefab_organization_parent (parent_id),
            CONSTRAINT fk_prefab_organization_parent FOREIGN KEY (parent_id) REFERENCES prefab_organizations(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->database->statement("CREATE TABLE IF NOT EXISTS prefab_organization_users (
            organization_id BIGINT UNSIGNED NOT NULL,
            user_id VARCHAR(191) NOT NULL,
            role VARCHAR(32) NOT NULL DEFAULT 'member',
            status VARCHAR(32) NOT NULL DEFAULT 'pending',
            is_primary TINYINT(1) NOT NULL DEFAULT 0,
            requested_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            approved_at TIMESTAMP NULL,
            approved_by VARCHAR(191) NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (organization_id, user_id),
            INDEX idx_prefab_org_users_user (user_id),
            INDEX idx_prefab_org_users_status (organization_id, status),
            CONSTRAINT fk_prefab_org_users_org FOREIGN KEY (organization_id) REFERENCES prefab_organizations(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    /** @return list<Organization> */
    public function all(bool $includeInactive = false): array
    {
        $where = $includeInactive ? '' : ' WHERE o.active = 1';
        $rows = $this->database->select(
            "SELECT o.id,o.parent_id,o.code,o.name,o.type,o.active,
                    COUNT(CASE WHEN ou.status='active' THEN 1 END) AS members_count
             FROM prefab_organizations o
             LEFT JOIN prefab_organization_users ou ON ou.organization_id=o.id
             {$where}
             GROUP BY o.id,o.parent_id,o.code,o.name,o.type,o.active
             ORDER BY o.name"
        );
        return array_map(fn(array $row) => $this->organization($row), $rows);
    }

    public function find(int|string $id): ?Organization
    {
        $rows = $this->database->select(
            "SELECT o.id,o.parent_id,o.code,o.name,o.type,o.active,
                    COUNT(CASE WHEN ou.status='active' THEN 1 END) AS members_count
             FROM prefab_organizations o
             LEFT JOIN prefab_organization_users ou ON ou.organization_id=o.id
             WHERE o.id=:id
             GROUP BY o.id,o.parent_id,o.code,o.name,o.type,o.active",
            ['id' => $id],
        );
        return isset($rows[0]) ? $this->organization($rows[0]) : null;
    }

    public function create(array $data): Organization
    {
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Organization name is required.');
        }

        $this->database->statement(
            "INSERT INTO prefab_organizations (parent_id,code,name,type,active)
             VALUES (:parent_id,:code,:name,:type,:active)",
            [
                'parent_id' => $this->nullableId($data['parent_id'] ?? null),
                'code' => $this->nullableString($data['code'] ?? null),
                'name' => $name,
                'type' => $this->nullableString($data['type'] ?? null),
                'active' => array_key_exists('active', $data) ? (int)(bool)$data['active'] : 1,
            ],
        );

        $id = $this->database->lastInsertId();
        return $this->find($id ?: throw new RuntimeException('Organization ID unavailable.'))
            ?? throw new RuntimeException('Organization could not be reloaded.');
    }

    public function update(int|string $id, array $data): Organization
    {
        $current = $this->find($id) ?? throw new RuntimeException('Organization not found.');

        $this->database->statement(
            "UPDATE prefab_organizations
             SET parent_id=:parent_id,code=:code,name=:name,type=:type,active=:active,updated_at=CURRENT_TIMESTAMP
             WHERE id=:id",
            [
                'id' => $id,
                'parent_id' => array_key_exists('parent_id', $data) ? $this->nullableId($data['parent_id']) : $current->parentId,
                'code' => array_key_exists('code', $data) ? $this->nullableString($data['code']) : $current->code,
                'name' => array_key_exists('name', $data) ? trim((string)$data['name']) : $current->name,
                'type' => array_key_exists('type', $data) ? $this->nullableString($data['type']) : $current->type,
                'active' => array_key_exists('active', $data) ? (int)(bool)$data['active'] : (int)$current->active,
            ],
        );

        return $this->find($id) ?? throw new RuntimeException('Organization not found after update.');
    }

    /** @return list<OrganizationMembership> */
    public function membershipsForUser(int|string $userId, ?string $status = null): array
    {
        $sql = "SELECT organization_id,user_id,role,status,is_primary,approved_by,approved_at
                FROM prefab_organization_users WHERE user_id=:user_id";
        $params = ['user_id' => (string)$userId];
        if ($status !== null) {
            $this->assertStatus($status);
            $sql .= " AND status=:status";
            $params['status'] = $status;
        }
        $sql .= " ORDER BY is_primary DESC, organization_id";
        return array_map(fn(array $row) => $this->membershipDto($row), $this->database->select($sql, $params));
    }

    /** @return list<OrganizationMembership> */
    public function members(int|string $organizationId, ?string $status = 'active'): array
    {
        $sql = "SELECT organization_id,user_id,role,status,is_primary,approved_by,approved_at
                FROM prefab_organization_users WHERE organization_id=:organization_id";
        $params = ['organization_id' => $organizationId];
        if ($status !== null) {
            $this->assertStatus($status);
            $sql .= " AND status=:status";
            $params['status'] = $status;
        }
        $sql .= " ORDER BY role DESC,user_id";
        return array_map(fn(array $row) => $this->membershipDto($row), $this->database->select($sql, $params));
    }

    public function membership(int|string $organizationId, int|string $userId): ?OrganizationMembership
    {
        $rows = $this->database->select(
            "SELECT organization_id,user_id,role,status,is_primary,approved_by,approved_at
             FROM prefab_organization_users
             WHERE organization_id=:organization_id AND user_id=:user_id",
            ['organization_id' => $organizationId, 'user_id' => (string)$userId],
        );
        return isset($rows[0]) ? $this->membershipDto($rows[0]) : null;
    }

    /** @return list<string> */
    public function organizationIdsForUser(int|string $userId, string $status = 'active'): array
    {
        $this->assertStatus($status);
        $rows = $this->database->select(
            "SELECT organization_id FROM prefab_organization_users
             WHERE user_id=:user_id AND status=:status ORDER BY is_primary DESC,organization_id",
            ['user_id' => (string)$userId, 'status' => $status],
        );
        return array_map(static fn(array $row): string => (string)$row['organization_id'], $rows);
    }

    /** @return list<string> */
    public function administeredOrganizationIds(int|string $userId): array
    {
        $rows = $this->database->select(
            "SELECT organization_id FROM prefab_organization_users
             WHERE user_id=:user_id AND role='admin' AND status='active' ORDER BY organization_id",
            ['user_id' => (string)$userId],
        );
        return array_map(static fn(array $row): string => (string)$row['organization_id'], $rows);
    }

    public function isAdmin(int|string $userId, int|string $organizationId): bool
    {
        $membership = $this->membership($organizationId, $userId);
        return $membership?->isAdmin() ?? false;
    }

    public function requestJoin(int|string $userId, int|string $organizationId): OrganizationMembership
    {
        if (!$this->find($organizationId)?->active) {
            throw new RuntimeException('Organization is not active.');
        }

        $existing = $this->membership($organizationId, $userId);
        if ($existing && in_array($existing->status, ['pending', 'active'], true)) {
            return $existing;
        }

        $this->upsertMembership($organizationId, $userId, 'member', 'pending', false, null);
        return $this->membership($organizationId, $userId)
            ?? throw new RuntimeException('Organization membership could not be reloaded.');
    }

    /**
     * Withdraw a user's own pending organization join request.
     *
     * This is intentionally self-service and only removes a pending
     * membership request. Active/suspended memberships must be managed
     * through the normal organization administration flow.
     */
    public function cancelJoinRequest(
        int|string $userId,
        int|string $organizationId,
    ): bool {
        $current = $this->membership($organizationId, $userId);
        if (!$current) {
            return false;
        }

        if ($current->status !== 'pending') {
            throw new RuntimeException('Only a pending organization request can be cancelled.');
        }

        $this->database->statement(
            "DELETE FROM prefab_organization_users
             WHERE organization_id=:organization_id
               AND user_id=:user_id
               AND status='pending'",
            [
                'organization_id' => $organizationId,
                'user_id' => (string)$userId,
            ],
        );

        return true;
    }

    public function approve(
        int|string $organizationId,
        int|string $userId,
        int|string $actorId,
    ): OrganizationMembership {
        if (!$this->isAdmin($actorId, $organizationId) && !$this->mayManageAdmins($actorId, $organizationId)) {
            throw new RuntimeException('Actor cannot approve members for this organization.');
        }

        $current = $this->membership($organizationId, $userId);
        if ($current?->role === 'admin' && !$this->mayManageAdmins($actorId, $organizationId)) {
            throw new RuntimeException('Only an authorized global administrator may manage an organization administrator.');
        }
        $role = $current?->role ?? 'member';
        $this->upsertMembership($organizationId, $userId, $role, 'active', $current?->isPrimary ?? false, $actorId);

        return $this->membership($organizationId, $userId)
            ?? throw new RuntimeException('Organization membership could not be reloaded.');
    }

    public function reject(
        int|string $organizationId,
        int|string $userId,
        int|string $actorId,
    ): OrganizationMembership {
        if (!$this->isAdmin($actorId, $organizationId) && !$this->mayManageAdmins($actorId, $organizationId)) {
            throw new RuntimeException('Actor cannot reject members for this organization.');
        }
        $current = $this->membership($organizationId, $userId)
            ?? throw new RuntimeException('Organization membership not found.');
        if ($current->role === 'admin' && !$this->mayManageAdmins($actorId, $organizationId)) {
            throw new RuntimeException('Only an authorized global administrator may manage an organization administrator.');
        }
        $this->upsertMembership($organizationId, $userId, $current->role, 'rejected', false, null);
        return $this->membership($organizationId, $userId)
            ?? throw new RuntimeException('Organization membership could not be reloaded.');
    }

    public function setStatus(
        int|string $organizationId,
        int|string $userId,
        string $status,
        int|string $actorId,
    ): OrganizationMembership {
        $this->assertStatus($status);
        if (!$this->isAdmin($actorId, $organizationId) && !$this->mayManageAdmins($actorId, $organizationId)) {
            throw new RuntimeException('Actor cannot manage members for this organization.');
        }

        $current = $this->membership($organizationId, $userId)
            ?? throw new RuntimeException('Organization membership not found.');
        if ($current->role === 'admin' && !$this->mayManageAdmins($actorId, $organizationId)) {
            throw new RuntimeException('Only an authorized global administrator may manage an organization administrator.');
        }
        $this->upsertMembership(
            $organizationId,
            $userId,
            $current->role,
            $status,
            $status === 'active' ? $current->isPrimary : false,
            $status === 'active' ? $actorId : null,
        );
        return $this->membership($organizationId, $userId)
            ?? throw new RuntimeException('Organization membership could not be reloaded.');
    }

    public function setPrimary(int|string $userId, int|string $organizationId): void
    {
        $membership = $this->membership($organizationId, $userId);
        if (!$membership?->isActive()) {
            throw new RuntimeException('Primary organization must be an active membership.');
        }

        $this->database->transaction(function (DatabaseInterface $db) use ($userId, $organizationId): void {
            $db->statement(
                "UPDATE prefab_organization_users SET is_primary=0,updated_at=CURRENT_TIMESTAMP WHERE user_id=:user_id",
                ['user_id' => (string)$userId],
            );
            $db->statement(
                "UPDATE prefab_organization_users SET is_primary=1,updated_at=CURRENT_TIMESTAMP
                 WHERE user_id=:user_id AND organization_id=:organization_id",
                ['user_id' => (string)$userId, 'organization_id' => $organizationId],
            );
        });
    }

    public function setAdmin(
        int|string $organizationId,
        int|string $userId,
        bool $admin,
        int|string $actorId,
    ): OrganizationMembership {
        if (!$this->mayManageAdmins($actorId, $organizationId)) {
            throw new RuntimeException('Only an authorized global administrator may manage organization administrators.');
        }

        $current = $this->membership($organizationId, $userId);
        $this->upsertMembership(
            $organizationId,
            $userId,
            $admin ? 'admin' : 'member',
            $current?->status ?? 'active',
            $current?->isPrimary ?? false,
            $current?->approvedBy,
        );

        return $this->membership($organizationId, $userId)
            ?? throw new RuntimeException('Organization membership could not be reloaded.');
    }

    public function remove(
        int|string $organizationId,
        int|string $userId,
        int|string $actorId,
    ): void {
        $target = $this->membership($organizationId, $userId);
        if (!$target) {
            return;
        }

        if ($target->role === 'admin' && !$this->mayManageAdmins($actorId, $organizationId)) {
            throw new RuntimeException('Only an authorized global administrator may remove an organization administrator.');
        }

        if (!$this->isAdmin($actorId, $organizationId) && !$this->mayManageAdmins($actorId, $organizationId)) {
            throw new RuntimeException('Actor cannot remove members from this organization.');
        }

        $this->database->statement(
            "DELETE FROM prefab_organization_users WHERE organization_id=:organization_id AND user_id=:user_id",
            ['organization_id' => $organizationId, 'user_id' => (string)$userId],
        );
    }

    private function mayManageAdmins(int|string $actorId, int|string $organizationId): bool
    {
        if (!$this->adminAuthorizer) {
            return false;
        }
        return (bool)($this->adminAuthorizer)($actorId, $organizationId);
    }

    private function upsertMembership(
        int|string $organizationId,
        int|string $userId,
        string $role,
        string $status,
        bool $isPrimary,
        int|string|null $approvedBy,
    ): void {
        $this->assertRole($role);
        $this->assertStatus($status);
        $params = [
            'organization_id' => $organizationId,
            'user_id' => (string)$userId,
            'role' => $role,
            'status' => $status,
            'is_primary' => (int)$isPrimary,
            'approved_by' => $approvedBy === null ? null : (string)$approvedBy,
            'approved_at' => $status === 'active' ? date('Y-m-d H:i:s') : null,
        ];

        $driver = $this->database->driver();
        $sql = match ($driver) {
            'sqlite' => "INSERT INTO prefab_organization_users
                (organization_id,user_id,role,status,is_primary,approved_at,approved_by,requested_at,created_at,updated_at)
                VALUES (:organization_id,:user_id,:role,:status,:is_primary,
                    :approved_at,:approved_by,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
                ON CONFLICT(organization_id,user_id) DO UPDATE SET
                    role=excluded.role,status=excluded.status,is_primary=excluded.is_primary,
                    approved_at=CASE WHEN excluded.status='active' THEN CURRENT_TIMESTAMP ELSE NULL END,
                    approved_by=excluded.approved_by,updated_at=CURRENT_TIMESTAMP",
            'pgsql' => "INSERT INTO prefab_organization_users
                (organization_id,user_id,role,status,is_primary,approved_at,approved_by,requested_at,created_at,updated_at)
                VALUES (:organization_id,:user_id,:role,:status,:is_primary,
                    :approved_at,:approved_by,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
                ON CONFLICT(organization_id,user_id) DO UPDATE SET
                    role=EXCLUDED.role,status=EXCLUDED.status,is_primary=EXCLUDED.is_primary,
                    approved_at=CASE WHEN EXCLUDED.status='active' THEN CURRENT_TIMESTAMP ELSE NULL END,
                    approved_by=EXCLUDED.approved_by,updated_at=CURRENT_TIMESTAMP",
            'sqlsrv' => "MERGE prefab_organization_users AS target
                USING (SELECT :organization_id organization_id,:user_id user_id,:role role,:status status,:is_primary is_primary,:approved_at approved_at,:approved_by approved_by) source
                ON target.organization_id=source.organization_id AND target.user_id=source.user_id
                WHEN MATCHED THEN UPDATE SET role=source.role,status=source.status,is_primary=source.is_primary,
                    approved_at=source.approved_at,
                    approved_by=source.approved_by,updated_at=CURRENT_TIMESTAMP
                WHEN NOT MATCHED THEN INSERT
                    (organization_id,user_id,role,status,is_primary,approved_at,approved_by,requested_at,created_at,updated_at)
                    VALUES (source.organization_id,source.user_id,source.role,source.status,source.is_primary,
                    source.approved_at,source.approved_by,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP);",
            'mysql' => "INSERT INTO prefab_organization_users
                (organization_id,user_id,role,status,is_primary,approved_at,approved_by,requested_at,created_at,updated_at)
                VALUES (:organization_id,:user_id,:role,:status,:is_primary,
                    :approved_at,:approved_by,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
                ON DUPLICATE KEY UPDATE role=VALUES(role),status=VALUES(status),is_primary=VALUES(is_primary),
                    approved_at=VALUES(approved_at),
                    approved_by=VALUES(approved_by),updated_at=CURRENT_TIMESTAMP",
            default => throw new RuntimeException("Unsupported organization database driver '{$driver}'."),
        };
        $this->database->statement($sql, $params);
    }

    private function organization(array $row): Organization
    {
        return new Organization(
            $row['id'],
            (string)$row['name'],
            $row['code'] ?? null,
            $row['type'] ?? null,
            $row['parent_id'] ?? null,
            (bool)$row['active'],
            (int)($row['members_count'] ?? 0),
        );
    }

    private function membershipDto(array $row): OrganizationMembership
    {
        return new OrganizationMembership(
            $row['organization_id'],
            $row['user_id'],
            (string)$row['role'],
            (string)$row['status'],
            (bool)$row['is_primary'],
            $row['approved_by'] ?? null,
            isset($row['approved_at']) ? (string)$row['approved_at'] : null,
        );
    }

    private function assertRole(string $role): void
    {
        if (!in_array($role, ['member', 'admin'], true)) {
            throw new InvalidArgumentException("Unsupported organization role '{$role}'.");
        }
    }

    private function assertStatus(string $status): void
    {
        if (!in_array($status, ['pending', 'action_required', 'active', 'rejected', 'suspended'], true)) {
            throw new InvalidArgumentException("Unsupported organization membership status '{$status}'.");
        }
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        return $value === '' ? null : $value;
    }

    private function nullableId(mixed $value): int|string|null
    {
        return $value === null || $value === '' ? null : $value;
    }
}
