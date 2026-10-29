<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Generate;

/**
 * フィールド名・locationの値から、生成クラスのメソッド名・定数名・クラス名の部品を作る(docs/09 §4.3・§5.5)。
 */
final class NameConverter
{
    /** snake_case → camelCase。'main_visual' → 'mainVisual' */
    public static function methodName(string $fieldName): string
    {
        return lcfirst(self::studly($fieldName));
    }

    /** 区切り([^A-Za-z0-9])で分けて各語の先頭を大文字にする。'main_visual' → 'MainVisual'。数字で始まれば'N'を前置する。 */
    public static function studly(string $name): string
    {
        $parts = preg_split('/[^A-Za-z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY);

        if ($parts === false || $parts === []) {
            return '';
        }

        $studly = implode('', array_map(static fn(string $part): string => ucfirst(strtolower($part)), $parts));

        return preg_match('/^[0-9]/', $studly) === 1 ? 'N' . $studly : $studly;
    }

    /**
     * 'main_visual' → 'MAIN_VISUAL'。ASCII snake_caseでない名前と、PHPでクラス定数に使えない名前(`class`)はnull。
     */
    public static function constName(string $fieldName): ?string
    {
        if (!self::isAccessorEligible($fieldName)) {
            return null;
        }

        $constName = strtoupper($fieldName);

        return $constName === 'CLASS' ? null : $constName;
    }

    /** アクセサを生成できるフィールド名(ASCII snake_case: `^[a-z][a-z0-9_]*$`)か。 */
    public static function isAccessorEligible(string $fieldName): bool
    {
        return preg_match('/^[a-z][a-z0-9_]*$/', $fieldName) === 1;
    }
}
