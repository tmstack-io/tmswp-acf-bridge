<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Tests\Unit\Json;

use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\AcfBridge\Exception\GenerationException;
use TMSWP\AcfBridge\Json\AcfJsonRepository;
use TMSWP\AcfBridge\Json\FieldGroup;
use TMSWP\AcfBridge\Json\FlexibleLayout;
use TMSWP\Testing\UnitTestCase;

/**
 * AcfJsonRepositoryが、ACFのLocal JSON(ACF 6.2〜6.8のscan_files()・get_files())と同じ規則でacf-jsonを読むことを
 * 確かめる。ACFの実行時にどのファイルが使われるかは、実物のACFの統合テスト(AcfBridgeKernelIntegrationTest)で確かめる。
 */
final class AcfJsonRepositoryTest extends UnitTestCase
{
    public function testItReadsGroupsAndOptionsPagesFromLocalJson(): void
    {
        $dir = $this->createTempDirectory('acf-json');
        $this->writeJson($dir . '/group_product.json', [
            'key' => 'group_product',
            'title' => '製品情報',
            'active' => true,
            'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'product']]],
            'fields' => [
                ['key' => 'field_price', 'name' => 'price', 'label' => '価格', 'type' => 'number'],
            ],
        ]);
        $this->writeJson($dir . '/options.json', [
            'key' => 'ui_options_page_theme',
            'menu_slug' => 'theme-settings',
            'post_id' => 'theme_settings',
        ]);

        $json = (new AcfJsonRepository([$dir]))->load();

        self::assertSame(['theme-settings' => 'theme_settings'], $json->optionsPagePostIds);
        self::assertCount(1, $json->fieldGroups);
        self::assertSame('group_product', $json->fieldGroups[0]->key);
        self::assertSame($dir . '/group_product.json', $json->fieldGroups[0]->sourceFile);
        self::assertSame([], $json->warnings);
    }

    public function testSourceHashIgnoresModifiedTimestamps(): void
    {
        $dir = $this->createTempDirectory('acf-json-hash');
        $file = $dir . '/group_product.json';
        $group = [
            'key' => 'group_product',
            'title' => '製品情報',
            'modified' => 1,
            'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'product']]],
            'fields' => [
                ['key' => 'field_price', 'name' => 'price', 'label' => '価格', 'type' => 'number', 'modified' => 1],
            ],
        ];
        $this->writeJson($file, $group);
        $hash = (new AcfJsonRepository([$dir]))->load()->sourceHash;
        $group['modified'] = 2;
        $group['fields'][0]['modified'] = 2;
        $this->writeJson($file, $group);

        self::assertSame($hash, (new AcfJsonRepository([$dir]))->load()->sourceHash);
    }

    /**
     * 同じkeyのJSONは、パスの順ではなくjsonDirsの並びで先のディレクトリ(テーマの既定では子テーマ)のものを使い、後の
     * ディレクトリのものはW010にする。オプションページも同じ。source hashは使うグループだけから求める。
     */
    public function testEarlierDirectoryOfJsonDirsWins(): void
    {
        $root = $this->createTempDirectory('acf-json-order');
        // パスの昇順では親テーマが先になる名前にする
        $parent = $root . '/a-parent';
        $child = $root . '/b-child';
        self::assertTrue(mkdir($parent));
        self::assertTrue(mkdir($child));
        $this->writeJson($child . '/z-product.json', self::group('group_product', '子テーマ'));
        $this->writeJson($parent . '/a-product.json', self::group('group_product', '親テーマ'));
        $this->writeJson($child . '/options.json', ['key' => 'ui_options_page_theme', 'menu_slug' => 'theme-settings', 'post_id' => 'child_settings']);
        $this->writeJson($parent . '/options.json', ['key' => 'ui_options_page_theme', 'menu_slug' => 'theme-settings', 'post_id' => 'parent_settings']);

        $json = (new AcfJsonRepository([$child, $parent]))->load();

        self::assertCount(1, $json->fieldGroups);
        self::assertSame('子テーマ', $json->fieldGroups[0]->title);
        self::assertSame($child . '/z-product.json', $json->fieldGroups[0]->sourceFile);
        self::assertSame(['theme-settings' => 'child_settings'], $json->optionsPagePostIds);
        self::assertSame([
            "W010: ACF JSON 'group_product' in {$parent}/a-product.json was ignored; {$child}/z-product.json in an earlier directory of jsonDirs is used",
            "W010: ACF JSON 'ui_options_page_theme' in {$parent}/options.json was ignored; {$child}/options.json in an earlier directory of jsonDirs is used",
        ], $json->warnings);

        $childOnly = $this->createTempDirectory('acf-json-child-only');
        $this->writeJson($childOnly . '/group_product.json', self::group('group_product', '子テーマ'));
        self::assertSame((new AcfJsonRepository([$childOnly]))->load()->sourceHash, $json->sourceHash);
    }

    /** 同じディレクトリの中の同じkeyのJSONは、ACFがファイル名で一方を選ぶため、設定の誤り(E102)にする。 */
    public function testSameKeyInTheSameDirectoryIsAnError(): void
    {
        $dir = $this->createTempDirectory('acf-json-same-dir');
        $this->writeJson($dir . '/group_product.json', self::group('group_product', '製品'));
        $this->writeJson($dir . '/product-copy.json', self::group('group_product', '製品の写し'));

        $this->expectException(GenerationException::class);
        $this->expectExceptionMessage("E102: ACF JSON files {$dir}/group_product.json and {$dir}/product-copy.json in the same directory have the same key 'group_product'");

        (new AcfJsonRepository([$dir]))->load();
    }

    /**
     * トップレベルが配列(list)のJSON(複数のグループを1つのファイルに入れた形)は、ACFが読まないため、生成と実行時が
     * 食い違わないよう設定の誤り(E102)にする。空の配列も同じ。
     */
    #[DataProvider('arrayFormatJson')]
    public function testArrayFormatJsonIsAnError(string $contents): void
    {
        $dir = $this->createTempDirectory('acf-json-list');
        $this->writeFile($dir . '/groups.json', $contents);

        $this->expectException(GenerationException::class);
        $this->expectExceptionMessage("E102: ACF JSON file {$dir}/groups.json has an array at the top level; ACF reads only a single object");

        (new AcfJsonRepository([$dir]))->load();
    }

    /** @return iterable<string, array{string}> */
    public static function arrayFormatJson(): iterable
    {
        yield 'グループのlist' => [json_encode([self::group('group_a', 'A'), self::group('group_b', 'B')], JSON_THROW_ON_ERROR)];
        yield '空の配列' => ["\n  []"];
    }

    /** JSONの構文の誤りはE102にする。 */
    public function testInvalidJsonIsAnError(): void
    {
        $dir = $this->createTempDirectory('acf-json-invalid');
        $this->writeFile($dir . '/group_broken.json', '{"key": "group_broken",');

        $this->expectException(GenerationException::class);
        $this->expectExceptionMessage("E102: invalid ACF JSON file {$dir}/group_broken.json");

        (new AcfJsonRepository([$dir]))->load();
    }

    /**
     * ACFが読まない形のうち、トップレベルが非配列のJSONと、keyの無い(文字列でない・空の)オブジェクトは、黙って捨てず
     * W001にする。
     */
    public function testJsonThatAcfIgnoresIsW001(): void
    {
        $dir = $this->createTempDirectory('acf-json-ignored');
        $this->writeFile($dir . '/a-string.json', '"group_text"');
        $this->writeFile($dir . '/b-number.json', '42');
        $this->writeFile($dir . '/c-null.json', 'null');
        $this->writeFile($dir . '/d-no-key.json', '{"title": "no key", "fields": []}');
        $this->writeFile($dir . '/e-int-key.json', '{"key": 1, "fields": []}');
        $this->writeFile($dir . '/f-empty-object.json', '{}');

        $json = (new AcfJsonRepository([$dir]))->load();

        self::assertSame([], $json->fieldGroups);
        self::assertSame([
            "W001: ACF JSON file {$dir}/a-string.json was ignored because its top level is string, not an object",
            "W001: ACF JSON file {$dir}/b-number.json was ignored because its top level is int, not an object",
            "W001: ACF JSON file {$dir}/c-null.json was ignored because its top level is null, not an object",
            "W001: ACF JSON file {$dir}/d-no-key.json was ignored because it has no string key",
            "W001: ACF JSON file {$dir}/e-int-key.json was ignored because it has no string key",
            "W001: ACF JSON file {$dir}/f-empty-object.json was ignored because it has no string key",
        ], $json->warnings);
    }

    /**
     * フィールドグループの判定はACF(6.2〜6.8)のget_files()と同じにする。keyが`group_`で始まるもの(fieldsが無くても)と、
     * keyの接頭辞を問わずfieldsを持つもの(値がfalse等でもnullでなければ)がグループになる。ui_options_pageのpost_idは
     * グループとは別に読み、両方に当たるJSONは両方に使う。post_type_・taxonomy_の定義はfieldsがあってもW001にし、
     * どれにも当たらないJSONもW001にする。
     */
    public function testFieldGroupsAreDetectedLikeAcf(): void
    {
        $dir = $this->createTempDirectory('acf-json-kinds');
        $this->writeJson($dir . '/a.json', ['key' => 'group_without_fields', 'title' => 'A']);
        $this->writeJson($dir . '/b.json', self::group('custom_group', 'B'));
        $this->writeJson($dir . '/c.json', self::group('post_type_product', 'C'));
        $this->writeJson($dir . '/d.json', ['key' => 'post_type_event', 'post_type' => 'event']);
        $this->writeJson($dir . '/e.json', self::group('taxonomy_genre', 'E'));
        $this->writeJson($dir . '/f.json', ['key' => 'taxonomy_tag', 'taxonomy' => 'tag']);
        $this->writeJson($dir . '/g.json', [...self::group('ui_options_page_theme', 'G'), 'menu_slug' => 'theme-settings', 'post_id' => 'theme_settings']);
        $this->writeJson($dir . '/h.json', ['key' => 'ui_options_page_footer', 'menu_slug' => 'footer', 'post_id' => 'footer_settings']);
        $this->writeJson($dir . '/i.json', ['key' => 'something_else', 'title' => 'I']);
        $this->writeJson($dir . '/j.json', ['key' => 'custom_false_fields', 'title' => 'J', 'fields' => false]);
        $this->writeJson($dir . '/k.json', ['key' => 'custom_null_fields', 'title' => 'K', 'fields' => null]);

        $json = (new AcfJsonRepository([$dir]))->load();

        self::assertSame(
            ['custom_false_fields', 'custom_group', 'group_without_fields', 'post_type_product', 'taxonomy_genre', 'ui_options_page_theme'],
            array_map(static fn(FieldGroup $group): string => $group->key, $json->fieldGroups),
        );
        self::assertSame(['footer' => 'footer_settings', 'theme-settings' => 'theme_settings'], $json->optionsPagePostIds);
        self::assertSame([
            "W001: the ACF post type or taxonomy definition 'post_type_product' in {$dir}/c.json was ignored; its fields are read as a field group, as ACF does",
            "W001: unsupported ACF JSON 'post_type_event' in {$dir}/d.json was ignored",
            "W001: the ACF post type or taxonomy definition 'taxonomy_genre' in {$dir}/e.json was ignored; its fields are read as a field group, as ACF does",
            "W001: unsupported ACF JSON 'taxonomy_tag' in {$dir}/f.json was ignored",
            "W001: unsupported ACF JSON 'something_else' in {$dir}/i.json was ignored",
            "W001: unsupported ACF JSON 'custom_null_fields' in {$dir}/k.json was ignored",
        ], $json->warnings);
    }

    /**
     * source hashは、flexible_contentのフィールドのレイアウトの順(生成コードの順になる)とレイアウトのkeyを変えると
     * 変わる。modified(layoutsの直下を含む)だけを変えたとき、フィールドのキーの順を変えたとき、flexible_contentで
     * ない`layouts`というキー(groupのmetadata.layouts・selectのchoices.layouts・textのlayouts)のキーの順だけを変えた
     * ときは変わらない。
     */
    public function testSourceHashReflectsTheOrderOfLayouts(): void
    {
        $base = $this->layoutHash();

        self::assertNotSame($base, $this->layoutHash(alphaFirst: true));
        self::assertNotSame($base, $this->layoutHash(alphaKey: 'layout_beta'));
        self::assertSame($base, $this->layoutHash(modified: 2));
        self::assertSame($base, $this->layoutHash(reverseFieldKeys: true));
        self::assertSame($base, $this->layoutHash(reverseOtherLayouts: true));
    }

    /**
     * testSourceHashReflectsTheOrderOfLayouts()のグループ(flexible_contentのsectionsと、layoutsというキーを持つほかの
     * 設定)を書き、そのsource hashを返す。
     */
    private function layoutHash(bool $alphaFirst = false, string $alphaKey = 'layout_alpha', int $modified = 1, bool $reverseFieldKeys = false, bool $reverseOtherLayouts = false): string
    {
        $zeta = ['key' => 'layout_zeta', 'name' => 'zeta', 'sub_fields' => [], 'modified' => $modified];
        $alpha = ['key' => $alphaKey, 'name' => 'alpha', 'sub_fields' => []];
        $layouts = $alphaFirst ? [$alphaKey => $alpha, 'layout_zeta' => $zeta] : ['layout_zeta' => $zeta, $alphaKey => $alpha];
        $flexible = [
            'key' => 'field_sections',
            'name' => 'sections',
            'label' => 'S',
            'type' => 'flexible_content',
            'layouts' => [...$layouts, 'modified' => $modified],
        ];
        $other = static fn(array $object): array => $reverseOtherLayouts ? array_reverse($object, true) : $object;
        $dir = $this->createTempDirectory('acf-json-layout-hash');
        $this->writeJson($dir . '/group_page.json', [
            'key' => 'group_page',
            'title' => 'P',
            'modified' => $modified,
            'metadata' => ['layouts' => $other(['wide' => 'Wide', 'narrow' => 'Narrow'])],
            'fields' => [
                $reverseFieldKeys ? array_reverse($flexible, true) : $flexible,
                ['key' => 'field_style', 'name' => 'style', 'label' => 'Style', 'type' => 'select', 'choices' => ['layouts' => $other(['grid' => 'Grid', 'list' => 'List'])]],
                ['key' => 'field_note', 'name' => 'note', 'label' => 'Note', 'type' => 'text', 'layouts' => $other(['first' => 1, 'second' => 2])],
            ],
        ]);

        return (new AcfJsonRepository([$dir]))->load()->sourceHash;
    }

    /**
     * flexible_contentのレイアウトは、名前の順でなくJSONの順に並べ、数字だけの名前も文字列のまま持つ。同じ名前の
     * レイアウトもそのまま並べる(生成時のE002で検出する。GeneratorTest)。
     */
    public function testFlexibleLayoutsKeepTheJsonOrder(): void
    {
        $dir = $this->createTempDirectory('acf-json-layouts');
        $this->writeJson($dir . '/group_page.json', [
            'key' => 'group_page',
            'title' => 'ページ',
            'fields' => [[
                'key' => 'field_sections',
                'name' => 'sections',
                'label' => 'セクション',
                'type' => 'flexible_content',
                'layouts' => [
                    'layout_c' => ['key' => 'layout_c', 'name' => 'text_block', 'sub_fields' => [['key' => 'field_body', 'name' => 'body', 'label' => '本文', 'type' => 'text']]],
                    'layout_a' => ['key' => 'layout_a', 'name' => 'hero', 'sub_fields' => []],
                    'layout_b' => ['key' => 'layout_b', 'name' => '0', 'sub_fields' => []],
                    'layout_d' => ['key' => 'layout_d', 'name' => 'hero', 'sub_fields' => []],
                ],
            ]],
        ]);

        $layouts = (new AcfJsonRepository([$dir]))->load()->fieldGroups[0]->fields[0]->layouts;

        self::assertSame(['text_block', 'hero', '0', 'hero'], array_map(static fn(FlexibleLayout $layout): string => $layout->name, $layouts));
        self::assertSame('body', $layouts[0]->subFields[0]->name);
    }

    /** @return array<string, mixed> */
    private static function group(string $key, string $title): array
    {
        return [
            'key' => $key,
            'title' => $title,
            'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'product']]],
            'fields' => [],
        ];
    }

    /** @param array<string, mixed> $data */
    private function writeJson(string $path, array $data): void
    {
        $this->writeFile($path, json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function writeFile(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) === false) {
            self::fail("fixtureを書き込めません: {$path}");
        }
    }
}
