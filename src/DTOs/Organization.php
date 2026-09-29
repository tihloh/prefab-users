<?php

namespace Tihloh\Prefab\Users\DTOs;

final class Organization
{
    public function __construct(
        public readonly int|string $id,
        public readonly string $name,
        public readonly ?string $code = null,
        public readonly ?string $type = null,
        public readonly int|string|null $parentId = null,
        public readonly bool $active = true,
        public readonly int $membersCount = 0,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'type' => $this->type,
            'parent_id' => $this->parentId,
            'active' => $this->active,
            'members_count' => $this->membersCount,
        ];
    }
}
