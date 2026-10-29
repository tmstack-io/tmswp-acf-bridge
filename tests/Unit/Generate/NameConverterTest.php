<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Tests\Unit\Generate;

use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\AcfBridge\Generate\NameConverter;
use TMSWP\Testing\UnitTestCase;

/**
 * クラス名に使うstudly()の変換(docs/09 §4.3)。ASCIIの英数字以外で区切り、各語を小文字にしてから先頭を大文字にして連結する。
 */
final class NameConverterTest extends UnitTestCase
{
    #[DataProvider('studlyNames')]
    public function testStudly(string $name, string $expected): void
    {
        self::assertSame($expected, NameConverter::studly($name));
    }

    /** @return iterable<string, array{string, string}> */
    public static function studlyNames(): iterable
    {
        yield 'ハイフン' => ['product-item', 'ProductItem'];
        yield 'アンダースコア' => ['theme_settings', 'ThemeSettings'];
        yield '数字で始まる' => ['4koma', 'N4koma'];
        // 大文字を含む語も、小文字にしてから先頭だけを大文字にする(menu_slug・テンプレートのファイル名で起こる)
        yield '大文字を含む語' => ['myCPT', 'Mycpt'];
        yield '大文字だけの語' => ['FAQ-page', 'FaqPage'];
        yield 'ASCIIの英数字が無い' => ['店舗', ''];
    }
}
