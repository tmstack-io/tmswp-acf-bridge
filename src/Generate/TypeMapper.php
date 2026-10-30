<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Generate;

use TMSWP\AcfBridge\Json\FieldDefinition;

/**
 * ACFのフィールドの型と設定から、生成アクセサの型と読み方(TypeMapping)を決める。
 *
 * 型の表記の中のクラス(WP_Post・DateTimeInterface等)はClassImports::ref()の参照で、型の別名(AcfImage等)は
 * ClassImports::alias()の名前で書く。shapeのキー(フィールドの名前)とacf_fc_layoutのリテラル(レイアウトの名前)は、
 * 生成クラスの名前にかかわらず、そのまま書く。
 */
final class TypeMapper
{
    /** 値を持たないACFのフィールドの型(アクセサもshapeのキーも生成しない) */
    private const array NO_VALUE_TYPES = ['message', 'tab', 'accordion'];

    /**
     * @param ClassImports $imports getter側の型(getterNativeType・getterDocType・objectClass)が参照するクラスと型の別名を
     *                              書く、生成クラス1つ分のClassImports
     * @param ClassImports $setterImports setter側の型(setterNativeType・setterDocType)の参照を書くClassImports。
     *                                    トップレベルのフィールドは$importsと同じもの、setterを生成しない入れ子の
     *                                    サブフィールドは生成クラスのuseに記録しないもの
     */
    public function map(FieldDefinition $field, ClassImports $imports, ClassImports $setterImports): TypeMapping
    {
        $returnFormat = $field->returnFormat ?? $this->defaultReturnFormat($field);

        return match ($field->type) {
            'text', 'textarea', 'email', 'url', 'password', 'wysiwyg', 'oembed', 'date_picker', 'date_time_picker', 'time_picker' => $this->stringType($field, $setterImports),
            'number', 'range' => new TypeMapping('int|float|null', 'int|float|null', 'acfNumber', 'int|float|null'),
            'true_false' => new TypeMapping('bool', 'bool', 'acfBool', 'bool'),
            'select', 'checkbox', 'radio', 'button_group' => $this->choiceType($field, $returnFormat, $imports),
            'image' => $this->mediaType($returnFormat, 'AcfImage', 'acfImage', $imports),
            'file' => $this->mediaType($returnFormat, 'AcfFile', 'acfFile', $imports),
            'gallery' => $this->galleryType($returnFormat, $imports),
            'link' => $this->linkType($returnFormat, $imports, $setterImports),
            'post_object' => $field->multiple ? $this->listObjectType($returnFormat, 'WP_Post', $imports) : $this->singleObjectType($returnFormat, 'WP_Post', $imports),
            'page_link' => $field->multiple
                ? new TypeMapping('array', 'list<string>', 'acfStringList', 'array', 'list<int|string>')
                : new TypeMapping('?string', 'string|null', 'acfNullableString', 'int|string|null'),
            'relationship' => $returnFormat === 'id'
                ? new TypeMapping('array', 'list<int>', 'acfIntList', 'array', 'list<int>')
                : $this->listObjectType($returnFormat, 'WP_Post', $imports),
            'taxonomy' => $this->taxonomyType($field, $returnFormat, $imports),
            'user' => $field->multiple
                ? $this->listObjectType($returnFormat, 'WP_User', $imports, 'AcfUserArray')
                : $this->singleObjectType($returnFormat, 'WP_User', $imports, 'AcfUserArray'),
            'color_picker' => $returnFormat === 'array'
                ? new TypeMapping('?array', $imports->alias('AcfColorRgba') . '|null', 'acfColorRgba', 'string')
                : new TypeMapping('string', 'string', 'acfString', 'string'),
            'group' => new TypeMapping('?array', $this->shape($field->subFields, $imports) . '|null', 'acfGroup', '?array', 'array<string, mixed>|null'),
            'repeater' => new TypeMapping('array', 'list<' . $this->shape($field->subFields, $imports) . '>', 'acfRows', 'array', 'list<array<string, mixed>>'),
            'flexible_content' => $field->layouts === []
                ? new TypeMapping('array', 'list<never>', 'acfEmptyLayouts', 'array', 'list<array<string, mixed>>')
                : new TypeMapping('array', 'list<' . $this->flexibleUnion($field, $imports) . '>', 'acfLayouts', 'array', 'list<array<string, mixed>>'),
            default => new TypeMapping('mixed', 'mixed', 'acfMixed', 'mixed'),
        };
    }

    /**
     * `{getter}AsDateTime()`を生成する型(date_picker・date_time_picker)か。map()のTypeMapping::$dateTimeAccessorと同じ値で、
     * 型を組み立てずに(生成クラスのuseに記録せずに)決める。
     */
    public function hasDateTimeAccessor(FieldDefinition $field): bool
    {
        return \in_array($field->type, ['date_picker', 'date_time_picker'], true);
    }

    public function storesValue(FieldDefinition $field): bool
    {
        return !\in_array($field->type, self::NO_VALUE_TYPES, true);
    }

    /**
     * サブフィールドのうち値を持つもの(group・repeater・flexible_contentのレイアウトのshapeのキーになるもの)。
     *
     * @param list<FieldDefinition> $fields
     *
     * @return list<FieldDefinition>
     */
    public function valueFields(array $fields): array
    {
        return array_values(array_filter($fields, $this->storesValue(...)));
    }

    /**
     * array shapeのキーの表記。PHPの識別子の形の名前はそのまま、ほかの名前(日本語のフィールド名等)は引用符で囲む。
     */
    public static function shapeKey(string $name): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1) {
            return $name;
        }

        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $name) . "'";
    }

    private function stringType(FieldDefinition $field, ClassImports $setterImports): TypeMapping
    {
        $dateSetter = static fn(): string => 'string|' . $setterImports->ref('DateTimeInterface') . '|null';

        return match ($field->type) {
            'date_picker' => new TypeMapping('string', 'string', 'acfString', $dateSetter(), '', $this->hasDateTimeAccessor($field), 'Ymd'),
            'date_time_picker' => new TypeMapping('string', 'string', 'acfString', $dateSetter(), '', $this->hasDateTimeAccessor($field), 'Y-m-d H:i:s'),
            'time_picker' => new TypeMapping('string', 'string', 'acfString', $dateSetter(), '', $this->hasDateTimeAccessor($field), 'H:i:s'),
            default => new TypeMapping('string', 'string', 'acfString', 'string'),
        };
    }

    private function choiceType(FieldDefinition $field, string $returnFormat, ClassImports $imports): TypeMapping
    {
        $isMultiple = $field->multiple || $field->type === 'checkbox';

        if ($isMultiple) {
            return $returnFormat === 'array'
                ? new TypeMapping('array', 'list<' . $imports->alias('AcfChoice') . '>', 'acfChoiceList', 'array', 'list<string>')
                : new TypeMapping('array', 'list<string>', 'acfStringList', 'array', 'list<string>');
        }

        return $returnFormat === 'array'
            ? new TypeMapping('?array', $imports->alias('AcfChoice') . '|null', 'acfChoice', '?string')
            : new TypeMapping('?string', 'string|null', 'acfNullableString', '?string');
    }

    /** @param string $alias return_format=arrayの型の別名(AcfTypesの名前) */
    private function mediaType(string $returnFormat, string $alias, string $arrayHelper, ClassImports $imports): TypeMapping
    {
        return match ($returnFormat) {
            'id' => new TypeMapping('?int', 'int|null', 'acfInt', '?int'),
            'url' => new TypeMapping('?string', 'string|null', 'acfNullableString', '?int'),
            default => new TypeMapping('?array', $imports->alias($alias) . '|null', $arrayHelper, '?int'),
        };
    }

    private function galleryType(string $returnFormat, ClassImports $imports): TypeMapping
    {
        return match ($returnFormat) {
            'id' => new TypeMapping('array', 'list<int>', 'acfIntList', 'array', 'list<int>'),
            'url' => new TypeMapping('array', 'list<string>', 'acfStringList', 'array', 'list<int>'),
            default => new TypeMapping('array', 'list<' . $imports->alias('AcfImage') . '>', 'acfImageList', 'array', 'list<int>'),
        };
    }

    private function linkType(string $returnFormat, ClassImports $imports, ClassImports $setterImports): TypeMapping
    {
        $setterDoc = $setterImports->alias('AcfLink') . '|null';

        return $returnFormat === 'url'
            ? new TypeMapping('?string', 'string|null', 'acfNullableString', '?array', $setterDoc)
            : new TypeMapping('?array', $imports->alias('AcfLink') . '|null', 'acfLink', '?array', $setterDoc);
    }

    /**
     * @param string $class 要素のクラスの短名(ClassImports::ref()に渡す)
     * @param string $arrayAlias return_format=arrayの型の別名(AcfTypesの名前)。無ければ''
     */
    private function singleObjectType(string $returnFormat, string $class, ClassImports $imports, string $arrayAlias = ''): TypeMapping
    {
        if ($returnFormat === 'id') {
            return new TypeMapping('?int', 'int|null', 'acfInt', '?int');
        }

        if ($returnFormat === 'array' && $arrayAlias !== '') {
            return new TypeMapping('?array', $imports->alias($arrayAlias) . '|null', 'acfUserArray', '?int');
        }

        $objectClass = $imports->ref($class);

        return new TypeMapping("?{$objectClass}", "{$objectClass}|null", 'acfObject', '?int', objectClass: $objectClass);
    }

    /**
     * @param string $class 要素のクラスの短名(ClassImports::ref()に渡す)
     * @param string $arrayAlias return_format=arrayの型の別名(AcfTypesの名前)。無ければ''
     */
    private function listObjectType(string $returnFormat, string $class, ClassImports $imports, string $arrayAlias = ''): TypeMapping
    {
        if ($returnFormat === 'id') {
            return new TypeMapping('array', 'list<int>', 'acfIntList', 'array', 'list<int>');
        }

        if ($returnFormat === 'array' && $arrayAlias !== '') {
            return new TypeMapping('array', 'list<' . $imports->alias($arrayAlias) . '>', 'acfUserArrayList', 'array', 'list<int>');
        }

        $objectClass = $imports->ref($class);

        return new TypeMapping('array', "list<{$objectClass}>", 'acfObjectList', 'array', 'list<int>', objectClass: $objectClass);
    }

    private function taxonomyType(FieldDefinition $field, string $returnFormat, ClassImports $imports): TypeMapping
    {
        $isMultiple = \in_array($field->fieldType, ['checkbox', 'multi_select'], true);

        if ($isMultiple) {
            return $returnFormat === 'object'
                ? $this->listObjectType($returnFormat, 'WP_Term', $imports)
                : new TypeMapping('array', 'list<int>', 'acfIntList', 'array', 'list<int>');
        }

        return $returnFormat === 'object'
            ? $this->singleObjectType($returnFormat, 'WP_Term', $imports)
            : new TypeMapping('?int', 'int|null', 'acfInt', '?int');
    }

    /**
     * サブフィールドのshape(`array{名前: getterの型, ...}`)。値を持たないサブフィールドは含めない。キーとacf_fc_layoutの
     * リテラルはそのまま書く。サブフィールドのsetterは生成しないため、そのsetter側の型の参照は生成クラスのuseに記録しない。
     *
     * @param list<FieldDefinition> $fields
     * @param string|null $layout flexible_contentのレイアウトの名前(空の名前もそのリテラルにする)。nullはレイアウトでない
     */
    private function shape(array $fields, ClassImports $imports, ?string $layout = null): string
    {
        $parts = $layout === null ? [] : ["acf_fc_layout: '" . str_replace(['\\', "'"], ['\\\\', "\\'"], $layout) . "'"];

        foreach ($this->valueFields($fields) as $subField) {
            $parts[] = self::shapeKey($subField->name) . ': ' . $this->map($subField, $imports, ClassImports::discarding())->getterDocType;
        }

        return 'array{' . implode(', ', $parts) . '}';
    }

    /**
     * レイアウトごとのshape(acf_fc_layoutはレイアウトの名前のリテラル)を、生成クラスの型の別名
     * `{Studly(フィールドの名前)}_{Studly(レイアウトの名前)}`(`@phpstan-type`)にしたunion。レイアウトは1つ以上。
     */
    private function flexibleUnion(FieldDefinition $field, ClassImports $imports): string
    {
        $aliases = [];

        foreach ($field->layouts as $layout) {
            $aliases[] = $imports->localAlias(NameConverter::studly($field->name) . '_' . NameConverter::studly($layout->name), $this->shape($layout->subFields, $imports, $layout->name));
        }

        return implode('|', $aliases);
    }

    private function defaultReturnFormat(FieldDefinition $field): string
    {
        return match ($field->type) {
            'image', 'file', 'link', 'user' => 'array',
            'post_object', 'relationship' => 'object',
            'taxonomy' => 'id',
            'color_picker', 'select', 'checkbox', 'radio', 'button_group' => 'value',
            default => '',
        };
    }
}
