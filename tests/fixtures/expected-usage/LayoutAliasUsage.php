<?php

declare(strict_types=1);

namespace App\Usage;

use App\Acf\ProductFields;

/**
 * 生成したflexible_contentのgetter(レイアウトの型の別名のunionのlist。docs/09 §6.4)を、利用側がacf_fc_layoutで
 * 判別して使うコード。PHPStan(level max)が、別名を解決し、判別の後に各レイアウトのキーを型どおりに扱えることを確かめる。
 */
final class LayoutAliasUsage
{
    /** @return list<string> */
    public function headings(ProductFields $fields): array
    {
        $headings = [];

        foreach ($fields->sections() as $section) {
            $headings[] = match ($section['acf_fc_layout']) {
                'hero' => $section['title'] . ' (' . \count($section['buttons']) . ' buttons, image ' . ($section['image'] ?? 0) . ')',
                'text_block' => $section['body'],
                'divider' => '---',
            };
        }

        return $headings;
    }
}
