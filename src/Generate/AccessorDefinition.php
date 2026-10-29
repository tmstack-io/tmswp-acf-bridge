<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Generate;

use TMSWP\AcfBridge\Json\FieldDefinition;

/**
 * 1つのトップレベルのフィールドから生成するメンバーの名前と型(docs/09 §5.3)。
 */
final readonly class AccessorDefinition
{
    public function __construct(
        public string $groupKey,
        public string $groupTitle,
        public string $sourceFile,
        public FieldDefinition $field,
        public string $constName,
        public string $keyConstName,
        public string $methodName,
        public string $setterName,
        public string $hasName,
        public string $deleteName,
        public ?string $dateTimeMethodName,
        public TypeMapping $mapping,
    ) {}
}
