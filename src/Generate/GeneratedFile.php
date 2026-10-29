<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Generate;

final readonly class GeneratedFile
{
    public function __construct(
        public string $relativePath,
        public string $contents,
    ) {}
}
