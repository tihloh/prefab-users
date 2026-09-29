<?php

namespace Tihloh\Prefab\Users\DTOs;

final class OrganizationMembership
{
    public function __construct(
        public readonly int|string $organizationId,
        public readonly int|string $userId,
        public readonly string $role = 'member',
        public readonly string $status = 'pending',
        public readonly bool $isPrimary = false,
        public readonly int|string|null $approvedBy = null,
        public readonly ?string $approvedAt = null,
    ) {}

    public function isAdmin(): bool
    {
        return $this->role === 'admin' && $this->status === 'active';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function toArray(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'user_id' => $this->userId,
            'role' => $this->role,
            'status' => $this->status,
            'is_primary' => $this->isPrimary,
            'approved_by' => $this->approvedBy,
            'approved_at' => $this->approvedAt,
        ];
    }
}
