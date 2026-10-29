<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Generate;

use TMSWP\AcfBridge\BridgeConfig;

final readonly class GeneratorContext
{
    /**
     * @param array<string, string> $optionsPagePostIds オプションページのmenu_slug => ACFのpost_id(acf-jsonの
     *                                                  ui_options_pageとBridgeConfig::$optionsPagePostIdsから作る)
     */
    public function __construct(
        public BridgeConfig $config,
        public array $optionsPagePostIds,
        public string $sourceHash = '',
    ) {}
}
