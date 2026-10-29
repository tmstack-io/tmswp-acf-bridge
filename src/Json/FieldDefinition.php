<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Json;

final readonly class FieldDefinition
{
    /**
     * @param list<FieldDefinition> $subFields
     * @param list<FlexibleLayout> $layouts flexible_contentのレイアウト(JSONの順)
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $label,
        public string $type,
        public bool $required,
        public ?string $returnFormat,
        public bool $multiple,
        public ?string $fieldType,
        public array $subFields,
        public array $layouts,
        public array $raw,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            key: self::string($data, 'key'),
            name: self::string($data, 'name'),
            label: self::string($data, 'label'),
            type: self::string($data, 'type'),
            required: (bool) ($data['required'] ?? false),
            returnFormat: self::optionalString($data, 'return_format'),
            multiple: (bool) ($data['multiple'] ?? false),
            fieldType: self::optionalString($data, 'field_type'),
            subFields: self::fields($data['sub_fields'] ?? []),
            layouts: self::layouts($data['layouts'] ?? []),
            raw: $data,
        );
    }

    /**
     *
     * @return list<FieldDefinition>
     */
    private static function fields(mixed $rows): array
    {
        if (!\is_array($rows)) {
            return [];
        }

        $fields = [];

        foreach ($rows as $row) {
            if (\is_array($row)) {
                $fields[] = self::fromArray(self::stringKeyed($row));
            }
        }

        return $fields;
    }

    /**
     * flexible_contentのレイアウトを、JSONの順のまま読む。同じ名前のレイアウトもそのまま並べる(生成時のE002で検出する)。
     *
     * @return list<FlexibleLayout>
     */
    private static function layouts(mixed $rows): array
    {
        if (!\is_array($rows)) {
            return [];
        }

        $layouts = [];

        foreach ($rows as $layout) {
            if (!\is_array($layout)) {
                continue;
            }

            $layout = self::stringKeyed($layout);
            $layouts[] = new FlexibleLayout(self::string($layout, 'name'), self::fields($layout['sub_fields'] ?? []));
        }

        return $layouts;
    }

    /** @param array<string, mixed> $data */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? '';

        return \is_scalar($value) ? (string) $value : '';
    }

    /** @param array<string, mixed> $data */
    private static function optionalString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return \is_scalar($value) && $value !== '' ? (string) $value : null;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $data): array
    {
        $normalized = [];

        foreach ($data as $key => $value) {
            if (\is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }
}
