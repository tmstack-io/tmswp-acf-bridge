<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Generate;

use TMSWP\AcfBridge\Json\FieldDefinition;

/**
 * CloneResolverが、ACF Proのcloneの処理(acf_clone_field()等)を再現するときの、ACFのフィールドの配列に当たる値。
 * プロパティはACFの配列のキーに対応する(key・name・_name・label・__key・__name・__label・sub_fields)。
 *
 * @internal CloneResolverだけが使う
 */
final class ClonedField
{
    /**
     * @param FieldDefinition $definition acf-jsonの定義(cloneの設定・型・サブフィールドを読む)
     * @param list<ClonedField>|null $subFields cloneのサブフィールド(cloneでなければnull)
     */
    public function __construct(
        public readonly FieldDefinition $definition,
        public string $key,
        public string $name,
        public string $nameBase,
        public string $label,
        public ?string $originalKey = null,
        public ?string $originalName = null,
        public ?array $subFields = null,
    ) {}

    public function isClone(): bool
    {
        return $this->definition->type === 'clone';
    }

    /** cloneのdisplay(ACFの既定はseamless) */
    public function display(): string
    {
        $display = $this->definition->raw['display'] ?? null;

        return \is_string($display) && $display !== '' ? $display : 'seamless';
    }

    public function prefixName(): bool
    {
        return self::flag($this->definition->raw['prefix_name'] ?? null);
    }

    public function prefixLabel(): bool
    {
        return self::flag($this->definition->raw['prefix_label'] ?? null);
    }

    /** ACFの真偽の設定(1・'1'・true)か。 */
    private static function flag(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }
}
