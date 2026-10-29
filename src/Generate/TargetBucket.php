<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Generate;

use TMSWP\AcfBridge\Json\FieldGroup;
use TMSWP\AcfBridge\Mapping\GenerationTarget;

final class TargetBucket
{
    /** @var list<FieldGroup> */
    private array $groups = [];

    public function __construct(public readonly GenerationTarget $target) {}

    public function addGroup(FieldGroup $group): void
    {
        $this->groups[] = $group;
    }

    /** @return list<FieldGroup> */
    public function groups(): array
    {
        return $this->groups;
    }
}
