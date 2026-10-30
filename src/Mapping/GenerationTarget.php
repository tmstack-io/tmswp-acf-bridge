<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Mapping;

/**
 * フィールドグループのlocationの1つのANDグループを解決した、生成クラスの対象。
 */
final readonly class GenerationTarget
{
    /**
     * @param string $subtype 投稿タイプ・タクソノミー(`all`を含む)・オプションページのmenu_slug。ユーザーは空
     * @param string $className 生成クラスの短名(名前空間なし)
     * @param string|null $template 投稿のテンプレートのファイル(`page_template`・`post_template`の値。defaultを除く)。
     *                              テンプレート別のクラスのときだけ持つ
     */
    public function __construct(
        public TargetKind $kind,
        public string $subtype,
        public string $className,
        public ?string $template = null,
    ) {}

    /**
     * 生成クラスを分けるキー。テンプレート別のクラスは、投稿タイプによらずテンプレートごとに1つにする。
     */
    public function key(): string
    {
        return $this->template !== null ? 'post-template:' . $this->template : $this->kind->value . ':' . $this->subtype;
    }
}
