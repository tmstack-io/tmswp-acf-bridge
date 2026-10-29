<?php

declare(strict_types=1);

namespace App\FieldAccess;

use ArrayObject;
use TMSWP\AcfBridge\AcfFieldAccess;

/**
 * AcfFieldAccessを使うfixture(AcfFieldAccessTestが使う)。group()・rows()・layouts()は、ジェネレータが
 * group・repeater・flexible_contentに生成する正規化の呼び出しと同じ形で、値を受け取るヘルパを呼ぶ。
 *
 * 形の不一致の扱いはKernel::forClass()のKernelで決まり、TMSWPの名前空間はアプリの名前空間にできないため、テストは
 * この名前空間(App\FieldAccess)のKernelを起動する。
 *
 * @phpstan-import-type AcfColorRgba from \TMSWP\AcfBridge\AcfTypes
 * @phpstan-import-type AcfImage from \TMSWP\AcfBridge\AcfTypes
 */
final class AcfFieldAccessFixture
{
    use AcfFieldAccess;

    public static function make(int|string $acfId): self
    {
        return new self($acfId);
    }

    public function stringByKey(string $key): string
    {
        return $this->acfString($key);
    }

    /** @return list<array{label: string}> */
    public function rowsByKey(string $key): array
    {
        return $this->acfRows($key, fn(array $row, string $path): array => [
            'label' => $this->acfStringOf($row['label'] ?? null, $path . '.label'),
        ]);
    }

    public function updateByKey(string $key, string $value): null
    {
        $this->acfUpdate($key, $value);

        return null;
    }

    public function setDate(string $key, string $value): null
    {
        $this->acfUpdateDate($key, $value, 'Ymd');

        return null;
    }

    public function hasByKey(string $key): bool
    {
        return $this->acfHas($key);
    }

    public function deleteByKey(string $key): bool
    {
        return $this->acfDelete($key);
    }

    /** @return AcfColorRgba|null */
    public function color(mixed $value): ?array
    {
        return $this->acfColorRgbaOf($value, 'color_value');
    }

    /** @return array{title: string, photo: int|null, enabled: bool, tags: list<string>, image: AcfImage|null, items: list<array{label: string, amount: int|float|null, owner: ArrayObject<array-key, mixed>|null}>}|null */
    public function group(mixed $value): ?array
    {
        return $this->acfGroupOf($value, 'group_value', fn(array $group, string $path): array => [
            'title' => $this->acfStringOf($group['title'] ?? null, $path . '.title'),
            'photo' => $this->acfIntOf($group['photo'] ?? null, $path . '.photo'),
            'enabled' => $this->acfBoolOf($group['enabled'] ?? null, $path . '.enabled'),
            'tags' => $this->acfStringListOf($group['tags'] ?? null, $path . '.tags'),
            'image' => $this->acfImageOf($group['image'] ?? null, $path . '.image'),
            'items' => $this->rowsOf($group['items'] ?? null, $path . '.items'),
        ]);
    }

    /** @return list<array{label: string, amount: int|float|null, owner: ArrayObject<array-key, mixed>|null}> */
    public function rows(mixed $value): array
    {
        return $this->rowsOf($value, 'rows_value');
    }

    /** @return list<array{acf_fc_layout: 'hero', title: string, buttons: list<array{url: string}>}|array{acf_fc_layout: 'text_block', body: string}> */
    public function layouts(mixed $value): array
    {
        return $this->acfLayoutsOf($value, 'sections', [
            'hero' => fn(array $row, string $path): array => [
                'acf_fc_layout' => 'hero',
                'title' => $this->acfStringOf($row['title'] ?? null, $path . '.title'),
                'buttons' => $this->acfRowsOf($row['buttons'] ?? null, $path . '.buttons', fn(array $row, string $path): array => [
                    'url' => $this->acfStringOf($row['url'] ?? null, $path . '.url'),
                ]),
            ],
            'text_block' => fn(array $row, string $path): array => [
                'acf_fc_layout' => 'text_block',
                'body' => $this->acfStringOf($row['body'] ?? null, $path . '.body'),
            ],
        ]);
    }

    /** @return list<never> */
    public function emptyLayouts(mixed $value): array
    {
        return $this->acfEmptyLayoutsOf($value, 'empty_sections');
    }

    /** @return list<array{label: string, amount: int|float|null, owner: ArrayObject<array-key, mixed>|null}> */
    private function rowsOf(mixed $value, string $path): array
    {
        return $this->acfRowsOf($value, $path, fn(array $row, string $path): array => [
            'label' => $this->acfStringOf($row['label'] ?? null, $path . '.label'),
            'amount' => $this->acfNumberOf($row['amount'] ?? null, $path . '.amount'),
            'owner' => $this->acfObjectOf($row['owner'] ?? null, $path . '.owner', ArrayObject::class),
        ]);
    }
}
