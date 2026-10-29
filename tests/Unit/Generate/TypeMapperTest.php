<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Tests\Unit\Generate;

use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use TMSWP\AcfBridge\AcfFieldAccess;
use TMSWP\AcfBridge\Generate\ClassImports;
use TMSWP\AcfBridge\Generate\TypeMapper;
use TMSWP\AcfBridge\Generate\TypeMapping;
use TMSWP\AcfBridge\Json\FieldDefinition;
use TMSWP\Testing\UnitTestCase;

final class TypeMapperTest extends UnitTestCase
{
    /**
     * @param array<string, mixed> $field
     */
    #[DataProvider('mappingProvider')]
    public function testItMapsDocumentedFieldRows(
        array $field,
        string $getterNativeType,
        string $getterDocType,
        string $coercionHelper,
        string $setterNativeType,
        string $setterDocType = '',
        bool $dateTimeAccessor = false,
        string $dateRawFormat = '',
        ?string $objectClass = null,
    ): void {
        $this->assertMapping(
            new TypeMapping($getterNativeType, $getterDocType, $coercionHelper, $setterNativeType, $setterDocType, $dateTimeAccessor, $dateRawFormat, $objectClass),
            (new TypeMapper())->map($this->field($field), self::imports(), self::imports()),
        );
    }

    /** @return iterable<string, array{array<string, mixed>, string, string, string, string, 5?: string, 6?: bool, 7?: string, 8?: string}> */
    public static function mappingProvider(): iterable
    {
        foreach (['text', 'textarea', 'email', 'url', 'password', 'wysiwyg', 'oembed'] as $type) {
            yield $type => [['type' => $type], 'string', 'string', 'acfString', 'string'];
        }

        yield 'number' => [['type' => 'number'], 'int|float|null', 'int|float|null', 'acfNumber', 'int|float|null'];
        yield 'range' => [['type' => 'range'], 'int|float|null', 'int|float|null', 'acfNumber', 'int|float|null'];
        yield 'true_false' => [['type' => 'true_false'], 'bool', 'bool', 'acfBool', 'bool'];
        yield 'select value' => [['type' => 'select', 'return_format' => 'value'], '?string', 'string|null', 'acfNullableString', '?string'];
        yield 'select array' => [['type' => 'select', 'return_format' => 'array'], '?array', 'AcfChoice|null', 'acfChoice', '?string'];
        yield 'select multiple value' => [['type' => 'select', 'multiple' => true, 'return_format' => 'value'], 'array', 'list<string>', 'acfStringList', 'array', 'list<string>'];
        yield 'select multiple array' => [['type' => 'select', 'multiple' => true, 'return_format' => 'array'], 'array', 'list<AcfChoice>', 'acfChoiceList', 'array', 'list<string>'];
        yield 'checkbox value' => [['type' => 'checkbox', 'return_format' => 'value'], 'array', 'list<string>', 'acfStringList', 'array', 'list<string>'];
        yield 'checkbox array' => [['type' => 'checkbox', 'return_format' => 'array'], 'array', 'list<AcfChoice>', 'acfChoiceList', 'array', 'list<string>'];
        yield 'radio value' => [['type' => 'radio', 'return_format' => 'value'], '?string', 'string|null', 'acfNullableString', '?string'];
        yield 'radio array' => [['type' => 'radio', 'return_format' => 'array'], '?array', 'AcfChoice|null', 'acfChoice', '?string'];
        yield 'button_group value' => [['type' => 'button_group', 'return_format' => 'value'], '?string', 'string|null', 'acfNullableString', '?string'];
        yield 'button_group array' => [['type' => 'button_group', 'return_format' => 'array'], '?array', 'AcfChoice|null', 'acfChoice', '?string'];
        yield 'image array' => [['type' => 'image', 'return_format' => 'array'], '?array', 'AcfImage|null', 'acfImage', '?int'];
        yield 'image id' => [['type' => 'image', 'return_format' => 'id'], '?int', 'int|null', 'acfInt', '?int'];
        yield 'image url' => [['type' => 'image', 'return_format' => 'url'], '?string', 'string|null', 'acfNullableString', '?int'];
        yield 'file array' => [['type' => 'file', 'return_format' => 'array'], '?array', 'AcfFile|null', 'acfFile', '?int'];
        yield 'file id' => [['type' => 'file', 'return_format' => 'id'], '?int', 'int|null', 'acfInt', '?int'];
        yield 'file url' => [['type' => 'file', 'return_format' => 'url'], '?string', 'string|null', 'acfNullableString', '?int'];
        yield 'gallery array' => [['type' => 'gallery', 'return_format' => 'array'], 'array', 'list<AcfImage>', 'acfImageList', 'array', 'list<int>'];
        yield 'gallery id' => [['type' => 'gallery', 'return_format' => 'id'], 'array', 'list<int>', 'acfIntList', 'array', 'list<int>'];
        yield 'gallery url' => [['type' => 'gallery', 'return_format' => 'url'], 'array', 'list<string>', 'acfStringList', 'array', 'list<int>'];
        yield 'link array' => [['type' => 'link', 'return_format' => 'array'], '?array', 'AcfLink|null', 'acfLink', '?array', 'AcfLink|null'];
        yield 'link url' => [['type' => 'link', 'return_format' => 'url'], '?string', 'string|null', 'acfNullableString', '?array', 'AcfLink|null'];
        yield 'post_object object' => [['type' => 'post_object', 'return_format' => 'object'], '?WP_Post', 'WP_Post|null', 'acfObject', '?int', '', false, '', 'WP_Post'];
        yield 'post_object id' => [['type' => 'post_object', 'return_format' => 'id'], '?int', 'int|null', 'acfInt', '?int'];
        yield 'post_object multiple object' => [['type' => 'post_object', 'multiple' => true, 'return_format' => 'object'], 'array', 'list<WP_Post>', 'acfObjectList', 'array', 'list<int>', false, '', 'WP_Post'];
        yield 'post_object multiple id' => [['type' => 'post_object', 'multiple' => true, 'return_format' => 'id'], 'array', 'list<int>', 'acfIntList', 'array', 'list<int>'];
        yield 'page_link single' => [['type' => 'page_link'], '?string', 'string|null', 'acfNullableString', 'int|string|null'];
        yield 'page_link multiple' => [['type' => 'page_link', 'multiple' => true], 'array', 'list<string>', 'acfStringList', 'array', 'list<int|string>'];
        yield 'relationship object' => [['type' => 'relationship', 'return_format' => 'object'], 'array', 'list<WP_Post>', 'acfObjectList', 'array', 'list<int>', false, '', 'WP_Post'];
        yield 'relationship id' => [['type' => 'relationship', 'return_format' => 'id'], 'array', 'list<int>', 'acfIntList', 'array', 'list<int>'];
        yield 'taxonomy object single' => [['type' => 'taxonomy', 'field_type' => 'select', 'return_format' => 'object'], '?WP_Term', 'WP_Term|null', 'acfObject', '?int', '', false, '', 'WP_Term'];
        yield 'taxonomy id single' => [['type' => 'taxonomy', 'field_type' => 'radio', 'return_format' => 'id'], '?int', 'int|null', 'acfInt', '?int'];
        yield 'taxonomy object multiple' => [['type' => 'taxonomy', 'field_type' => 'checkbox', 'return_format' => 'object'], 'array', 'list<WP_Term>', 'acfObjectList', 'array', 'list<int>', false, '', 'WP_Term'];
        yield 'taxonomy id multiple' => [['type' => 'taxonomy', 'field_type' => 'multi_select', 'return_format' => 'id'], 'array', 'list<int>', 'acfIntList', 'array', 'list<int>'];
        yield 'user array' => [['type' => 'user', 'return_format' => 'array'], '?array', 'AcfUserArray|null', 'acfUserArray', '?int'];
        yield 'user multiple array' => [['type' => 'user', 'multiple' => true, 'return_format' => 'array'], 'array', 'list<AcfUserArray>', 'acfUserArrayList', 'array', 'list<int>'];
        yield 'user multiple id' => [['type' => 'user', 'multiple' => true, 'return_format' => 'id'], 'array', 'list<int>', 'acfIntList', 'array', 'list<int>'];
        yield 'user object' => [['type' => 'user', 'return_format' => 'object'], '?WP_User', 'WP_User|null', 'acfObject', '?int', '', false, '', 'WP_User'];
        yield 'user id' => [['type' => 'user', 'return_format' => 'id'], '?int', 'int|null', 'acfInt', '?int'];
        yield 'user multiple object' => [['type' => 'user', 'multiple' => true, 'return_format' => 'object'], 'array', 'list<WP_User>', 'acfObjectList', 'array', 'list<int>', false, '', 'WP_User'];
        yield 'date_picker' => [['type' => 'date_picker'], 'string', 'string', 'acfString', 'string|DateTimeInterface|null', '', true, 'Ymd'];
        yield 'date_time_picker' => [['type' => 'date_time_picker'], 'string', 'string', 'acfString', 'string|DateTimeInterface|null', '', true, 'Y-m-d H:i:s'];
        yield 'time_picker' => [['type' => 'time_picker'], 'string', 'string', 'acfString', 'string|DateTimeInterface|null', '', false, 'H:i:s'];
        yield 'color string' => [['type' => 'color_picker', 'return_format' => 'string'], 'string', 'string', 'acfString', 'string'];
        yield 'color array' => [['type' => 'color_picker', 'return_format' => 'array'], '?array', 'AcfColorRgba|null', 'acfColorRgba', 'string'];
    }

    /**
     * group・repeater・flexible_contentは、サブフィールドのgetterの型を再帰で展開したshapeを返す。値を持たないサブフィールド
     * (message等)はshapeに含めず、PHPの識別子の形でない名前のキーは引用符で囲む。flexible_contentのレイアウトの
     * acf_fc_layoutはレイアウトの名前のリテラルにし、レイアウトのshapeはJSONの順(名前の順でない)に並べる。
     */
    public function testNestedTypesExpandSubFieldShapesRecursively(): void
    {
        $mapper = new TypeMapper();

        $this->assertMapping(
            new TypeMapping('array', "list<array{shop_name: string, tel: string, 'メモ': string, photo: int|null}>", 'acfRows', 'array', 'list<array<string, mixed>>'),
            $mapper->map($this->field([
                'type' => 'repeater',
                'sub_fields' => [
                    ['key' => 'field_shop', 'name' => 'shop_name', 'label' => '店舗名', 'type' => 'text'],
                    ['key' => 'field_tel', 'name' => 'tel', 'label' => 'TEL', 'type' => 'text'],
                    ['key' => 'field_memo', 'name' => 'メモ', 'label' => 'メモ', 'type' => 'textarea'],
                    ['key' => 'field_note', 'name' => 'note', 'label' => '注意', 'type' => 'message'],
                    ['key' => 'field_photo', 'name' => 'photo', 'label' => '写真', 'type' => 'image', 'return_format' => 'id'],
                ],
            ]), self::imports(), self::imports()),
        );
        $this->assertMapping(
            new TypeMapping('?array', 'array{author: WP_User|null, rows: list<array{images: list<AcfImage>}>}|null', 'acfGroup', '?array', 'array<string, mixed>|null'),
            $mapper->map($this->field([
                'type' => 'group',
                'sub_fields' => [
                    ['key' => 'field_author', 'name' => 'author', 'label' => '著者', 'type' => 'user', 'return_format' => 'object'],
                    [
                        'key' => 'field_rows',
                        'name' => 'rows',
                        'label' => '行',
                        'type' => 'repeater',
                        'sub_fields' => [['key' => 'field_images', 'name' => 'images', 'label' => '画像', 'type' => 'gallery']],
                    ],
                ],
            ]), self::imports(), self::imports()),
        );
        $flexibleImports = self::imports();
        $this->assertMapping(
            new TypeMapping(
                'array',
                'list<TestField_Hero|TestField_Body>',
                'acfLayouts',
                'array',
                'list<array<string, mixed>>',
            ),
            $mapper->map($this->field([
                'type' => 'flexible_content',
                'layouts' => [
                    [
                        'name' => 'hero',
                        'sub_fields' => [
                            ['key' => 'field_title', 'name' => 'title', 'label' => '見出し', 'type' => 'text'],
                            ['key' => 'field_image', 'name' => 'image', 'label' => '画像', 'type' => 'image', 'return_format' => 'id'],
                        ],
                    ],
                    [
                        'name' => 'body',
                        'sub_fields' => [
                            ['key' => 'field_body', 'name' => 'body', 'label' => '本文', 'type' => 'wysiwyg'],
                        ],
                    ],
                ],
            ]), $flexibleImports, $flexibleImports),
        );
        // レイアウトのshapeは、生成クラスの型の別名`{Studly(フィールドの名前)}_{Studly(レイアウトの名前)}`として宣言する
        self::assertSame([
            " * @phpstan-type TestField_Hero array{acf_fc_layout: 'hero', title: string, image: int|null}",
            " * @phpstan-type TestField_Body array{acf_fc_layout: 'body', body: string}",
        ], $flexibleImports->localAliasLines());
    }

    /**
     * レイアウトの型の別名は、クラスの中で一意でなければならない。同じ名前の別の型と、生成クラス・参照するクラスと
     * 大文字小文字を区別せずに重なる名前は、宣言できないこととして記録する(生成時のE002)。
     */
    public function testLayoutAliasesThatCannotBeDeclaredAreRecorded(): void
    {
        $imports = new ClassImports('Wp_PostFields');
        $mapper = new TypeMapper();
        $flexible = fn(string $name, string $layout, string $subField): FieldDefinition => $this->field([
            'name' => $name,
            'type' => 'flexible_content',
            'layouts' => [['name' => $layout, 'sub_fields' => [['key' => 'field_' . $subField, 'name' => $subField, 'label' => $subField, 'type' => 'text']]]],
        ]);

        $mapper->map($flexible('blocks', 'quote', 'text'), $imports, $imports);
        $mapper->map($flexible('blocks', 'quote', 'text'), $imports, $imports);
        self::assertSame([], $imports->localAliasConflicts());

        $mapper->map($flexible('blocks', 'quote', 'body'), $imports, $imports);
        $mapper->map($flexible('wp', 'post', 'text'), $imports, $imports);
        self::assertSame([
            "the type alias 'Blocks_Quote' is declared for two different layouts (array{acf_fc_layout: 'quote', text: string} and array{acf_fc_layout: 'quote', body: string})",
            "the type alias 'Wp_Post' collides with 'WP_Post'",
        ], $imports->localAliasConflicts());
    }

    /**
     * 空の名前のレイアウトのshapeにも、判別のキー`acf_fc_layout: ''`を書く(生成する正規化の関数は`'acf_fc_layout' => ''`を
     * 返すため。レイアウトでないgroup・repeaterのshapeには書かない)。
     */
    public function testEmptyLayoutNameKeepsTheDiscriminator(): void
    {
        $imports = self::imports();
        $mapping = (new TypeMapper())->map($this->field([
            'type' => 'flexible_content',
            'layouts' => [
                ['name' => 'quote', 'sub_fields' => [['key' => 'field_text', 'name' => 'text', 'label' => '本文', 'type' => 'text']]],
                ['name' => '', 'sub_fields' => [['key' => 'field_caption', 'name' => 'caption', 'label' => '説明', 'type' => 'text']]],
            ],
        ]), $imports, $imports);

        self::assertSame('list<TestField_Quote|TestField_>', $mapping->getterDocType);
        self::assertSame([
            " * @phpstan-type TestField_Quote array{acf_fc_layout: 'quote', text: string}",
            " * @phpstan-type TestField_ array{acf_fc_layout: '', caption: string}",
        ], $imports->localAliasLines());
    }

    public function testUnknownTypesBecomeMixed(): void
    {
        $mapping = (new TypeMapper())->map($this->field(['type' => 'google_map']), self::imports(), self::imports());

        self::assertSame('mixed', $mapping->getterNativeType);
        self::assertSame('mixed', $mapping->getterDocType);
        self::assertSame('acfMixed', $mapping->coercionHelper);
    }

    /**
     * TypeMappingのヘルパの名前は、AcfFieldAccessのfield keyで読むヘルパと、入れ子のサブフィールドに使う値を受け取る
     * ヘルパ(名前に`Of`を付けたもの。acfMixedは値をそのまま使うため無い)の両方に実在する。
     *
     * @param array<string, mixed> $field
     */
    #[DataProvider('mappingProvider')]
    public function testCoercionHelpersExistInTheTrait(array $field): void
    {
        $helper = (new TypeMapper())->map($this->field($field), self::imports(), self::imports())->coercionHelper;
        $trait = new ReflectionClass(AcfFieldAccess::class);

        self::assertTrue($trait->hasMethod($helper), $helper);
        self::assertTrue($trait->hasMethod($helper . 'Of'), $helper . 'Of');
    }

    /**
     * ClassImportsの生成クラスの短名がクラスや型の別名と重なると、型の参照だけが完全修飾名・別の名前になり、shapeのキー
     * (フィールドの名前)とacf_fc_layoutのリテラル(レイアウトの名前)は変わらない。
     */
    public function testOnlyTypeReferencesFollowTheGeneratedClassName(): void
    {
        $mapper = new TypeMapper();
        $imageGroup = $this->field([
            'type' => 'group',
            'sub_fields' => [
                ['key' => 'field_a', 'name' => 'AcfImage', 'label' => 'a', 'type' => 'image'],
                ['key' => 'field_b', 'name' => 'name-AcfImage', 'label' => 'b', 'type' => 'image'],
                ['key' => 'field_c', 'name' => 'WP_Post', 'label' => 'c', 'type' => 'post_object', 'return_format' => 'object'],
            ],
        ]);
        $layouts = $this->field(['type' => 'flexible_content', 'layouts' => [['name' => 'AcfImage', 'sub_fields' => [['key' => 'field_d', 'name' => 'AcfImage', 'label' => 'd', 'type' => 'image']]]]]);

        self::assertSame("array{AcfImage: AcfImageType|null, 'name-AcfImage': AcfImageType|null, WP_Post: WP_Post|null}|null", $mapper->map($imageGroup, new ClassImports('AcfImage'), new ClassImports('AcfImage'))->getterDocType);
        self::assertSame("array{AcfImage: AcfImage|null, 'name-AcfImage': AcfImage|null, WP_Post: WP_Post|null}|null", $mapper->map($imageGroup, self::imports(), self::imports())->getterDocType);
        $clashing = new ClassImports('Acfimage');
        self::assertSame('list<TestField_Acfimage>', $mapper->map($layouts, $clashing, $clashing)->getterDocType);
        self::assertSame([" * @phpstan-type TestField_Acfimage array{acf_fc_layout: 'AcfImage', AcfImage: AcfImageType|null}"], $clashing->localAliasLines());
        $plain = self::imports();
        self::assertSame('list<TestField_Acfimage>', $mapper->map($layouts, $plain, $plain)->getterDocType);
        self::assertSame([" * @phpstan-type TestField_Acfimage array{acf_fc_layout: 'AcfImage', AcfImage: AcfImage|null}"], $plain->localAliasLines());

        $date = $mapper->map($this->field(['type' => 'date_picker', 'name' => 'DateTimeInterface']), new ClassImports('DateTimeInterface'), new ClassImports('DateTimeInterface'));
        self::assertSame('string|\\DateTimeInterface|null', $date->setterNativeType);

        $object = $mapper->map($this->field(['type' => 'taxonomy', 'field_type' => 'select', 'return_format' => 'object']), new ClassImports('Wp_term'), new ClassImports('Wp_term'));
        self::assertSame('?\\WP_Term', $object->getterNativeType);
        self::assertSame('\\WP_Term|null', $object->getterDocType);
        self::assertSame('\\WP_Term', $object->objectClass);

        $link = $mapper->map($this->field(['type' => 'link', 'return_format' => 'url']), new ClassImports('AcfLink'), new ClassImports('AcfLink'));
        self::assertSame('AcfLinkType|null', $link->setterDocType);
    }

    /**
     * 入れ子のサブフィールドのsetterは生成しないため、サブフィールドのsetter側の型(日付のDateTimeInterface、linkの
     * AcfLink)は生成クラスのuseと型の別名に記録しない。getter側の型(shapeの中の型)だけを記録する。
     */
    public function testSubFieldSetterTypesAreNotImported(): void
    {
        $mapper = new TypeMapper();
        $group = $this->field([
            'type' => 'group',
            'sub_fields' => [
                ['key' => 'field_day', 'name' => 'day', 'label' => 'day', 'type' => 'date_picker'],
                ['key' => 'field_at', 'name' => 'at', 'label' => 'at', 'type' => 'date_time_picker'],
                ['key' => 'field_time', 'name' => 'time', 'label' => 'time', 'type' => 'time_picker'],
                ['key' => 'field_url', 'name' => 'url', 'label' => 'url', 'type' => 'link', 'return_format' => 'url'],
            ],
        ]);
        $imports = self::imports();

        self::assertSame('array{day: string, at: string, time: string, url: string|null}|null', $mapper->map($group, $imports, $imports)->getterDocType);
        self::assertSame([], $imports->useLines());
        self::assertSame([], $imports->typeImportLines());

        $linkGroup = $this->field(['type' => 'group', 'sub_fields' => [['key' => 'field_link', 'name' => 'link', 'label' => 'link', 'type' => 'link']]]);
        $mapper->map($linkGroup, $imports, $imports);
        self::assertSame([' * @phpstan-import-type AcfLink from \\TMSWP\\AcfBridge\\AcfTypes'], $imports->typeImportLines());
    }

    /** 衝突しない生成クラスの名前のClassImports。 */
    private static function imports(): ClassImports
    {
        return new ClassImports('ProductFields');
    }

    /** @param array<string, mixed> $overrides */
    private function field(array $overrides): FieldDefinition
    {
        return FieldDefinition::fromArray(array_merge([
            'key' => 'field_test',
            'name' => 'test_field',
            'label' => 'テスト',
            'type' => 'text',
        ], $overrides));
    }

    private function assertMapping(TypeMapping $expected, TypeMapping $actual): void
    {
        self::assertSame($expected->getterNativeType, $actual->getterNativeType);
        self::assertSame($expected->getterDocType, $actual->getterDocType);
        self::assertSame($expected->coercionHelper, $actual->coercionHelper);
        self::assertSame($expected->setterNativeType, $actual->setterNativeType);
        self::assertSame($expected->setterDocType, $actual->setterDocType);
        self::assertSame($expected->dateTimeAccessor, $actual->dateTimeAccessor);
        self::assertSame($expected->dateRawFormat, $actual->dateRawFormat);
        self::assertSame($expected->objectClass, $actual->objectClass);
    }
}
