<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Json;

/**
 * acf-jsonを1回読んだ結果(AcfJsonRepository::load())。生成・check・E101の検査は、この値だけを使う。
 */
final readonly class AcfJsonSource
{
    /**
     * @param list<FieldGroup> $fieldGroups ACFが使うフィールドグループ(グループのkeyの昇順)
     * @param array<string, string> $optionsPagePostIds オプションページのmenu_slug => post_id(post_idの無いページは含めない)
     * @param list<string> $warnings 読み込みの警告(W001・W010)
     * @param string $sourceHash $fieldGroupsの正規化したJSONのsha256(ドリフトの検知に使う)
     */
    public function __construct(
        public array $fieldGroups,
        public array $optionsPagePostIds,
        public array $warnings,
        public string $sourceHash,
    ) {}
}
