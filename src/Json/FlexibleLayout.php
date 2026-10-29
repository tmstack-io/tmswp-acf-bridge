<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Json;

/**
 * flexible_contentの1つのレイアウト。名前は、数字だけの名前(`'0'`等)も文字列のまま持つ(行の`acf_fc_layout`の値)。
 */
final readonly class FlexibleLayout
{
    /**
     * @param string $name レイアウトの名前
     * @param list<FieldDefinition> $subFields レイアウトのサブフィールド(JSONの順)
     */
    public function __construct(
        public string $name,
        public array $subFields,
    ) {}
}
