<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Manifest;

use TMSWP\Core\Meta\ObjectType;

final readonly class ManagedKey
{
    public function __construct(
        public ?ObjectType $objectType,
        public string $objectSubtype,
        public string $metaKey,
        public string $fieldKey,
        public string $groupKey,
    ) {}
}
