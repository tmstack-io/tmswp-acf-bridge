<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Json;

final readonly class FieldGroup
{
    /**
     * @param list<FieldDefinition> $fields
     * @param array<int, mixed> $location
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public string $key,
        public string $title,
        public array $fields,
        public array $location,
        public bool $active,
        public string $sourceFile,
        public array $raw,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, string $sourceFile): self
    {
        $fields = [];
        $rawFields = $data['fields'] ?? [];

        if (\is_array($rawFields)) {
            foreach ($rawFields as $field) {
                if (\is_array($field)) {
                    $fields[] = FieldDefinition::fromArray(self::stringKeyed($field));
                }
            }
        }

        $location = $data['location'] ?? [];

        return new self(
            key: self::string($data, 'key'),
            title: self::string($data, 'title'),
            fields: $fields,
            location: \is_array($location) ? array_values($location) : [],
            active: (bool) ($data['active'] ?? true),
            sourceFile: $sourceFile,
            raw: $data,
        );
    }

    /** @param array<string, mixed> $data */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? '';

        return \is_scalar($value) ? (string) $value : '';
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
