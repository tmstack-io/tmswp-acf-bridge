<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Generate;

/**
 * 1つのACFのフィールドの、生成アクセサの型と読み方。
 */
final readonly class TypeMapping
{
    /**
     * @param string $getterNativeType getterのPHPの戻り型
     * @param string $getterDocType getterのPHPDocの戻り型。group・repeater・flexible_contentはサブフィールドのshapeを再帰で展開する。
     *                              型の表記の中のクラスと型の別名は、生成クラスのClassImportsで書いた名前
     * @param string $coercionHelper getterが呼ぶAcfFieldAccessのヘルパの名前(field keyで読む)。入れ子のサブフィールドは、
     *                               名前に`Of`を付けた、値を受け取るヘルパを呼ぶ(acfMixedだけは値をそのまま使う)
     * @param string $setterNativeType setterの引数のPHPの型
     * @param string $setterDocType setterの引数のPHPDocの型(PHPの型と同じなら'')
     * @param bool $dateTimeAccessor `{getter}AsDateTime()`を生成するか
     * @param string $dateRawFormat 日付・時刻の保存形式(rawの書式)。日付・時刻でなければ''
     * @param string|null $objectClass acfObject・acfObjectListに渡す要素のクラスの参照(ClassImports::ref()。短名か完全修飾名)
     */
    public function __construct(
        public string $getterNativeType,
        public string $getterDocType,
        public string $coercionHelper,
        public string $setterNativeType,
        public string $setterDocType = '',
        public bool $dateTimeAccessor = false,
        public string $dateRawFormat = '',
        public ?string $objectClass = null,
    ) {}
}
