<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Tests\Unit\Generate;

use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\AcfBridge\Generate\CloneResolver;
use TMSWP\AcfBridge\Json\FieldDefinition;
use TMSWP\AcfBridge\Json\FieldGroup;
use TMSWP\Testing\UnitTestCase;

final class CloneResolverTest extends UnitTestCase
{
    public function testItExpandsSeamlessCloneGroups(): void
    {
        $errors = [];
        $groups = (new CloneResolver())->resolve([
            $this->group('group_common', [
                ['key' => 'field_headline', 'name' => 'headline', 'label' => '見出し', 'type' => 'text'],
                ['key' => 'field_price', 'name' => 'price', 'label' => '価格', 'type' => 'number'],
            ]),
            $this->group('group_product', [
                [
                    'key' => 'field_common_clone',
                    'name' => 'common',
                    'label' => '共通',
                    'type' => 'clone',
                    'clone' => ['group_common'],
                    'display' => 'seamless',
                ],
            ]),
        ], $errors);

        self::assertSame([], $errors);
        self::assertSame(['headline', 'price'], $this->fieldNames($groups[1]));
    }

    public function testItPrefixesSeamlessCloneNamesWhenRequested(): void
    {
        $errors = [];
        $groups = (new CloneResolver())->resolve([
            $this->group('group_common', [
                ['key' => 'field_price', 'name' => 'price', 'label' => '価格', 'type' => 'number'],
            ]),
            $this->group('group_product', [
                [
                    'key' => 'field_common_clone',
                    'name' => 'common',
                    'label' => '共通',
                    'type' => 'clone',
                    'clone' => ['group_common'],
                    'display' => 'seamless',
                    'prefix_name' => 1,
                ],
            ]),
        ], $errors);

        self::assertSame([], $errors);
        self::assertSame(['common_price'], $this->fieldNames($groups[1]));
    }

    public function testItConvertsGroupDisplayCloneToGroupField(): void
    {
        $errors = [];
        $groups = (new CloneResolver())->resolve([
            $this->group('group_common', [
                ['key' => 'field_price', 'name' => 'price', 'label' => '価格', 'type' => 'number'],
            ]),
            $this->group('group_product', [
                [
                    'key' => 'field_common_clone',
                    'name' => 'common',
                    'label' => '共通',
                    'type' => 'clone',
                    'clone' => ['group_common'],
                    'display' => 'group',
                ],
            ]),
        ], $errors);

        self::assertSame([], $errors);
        self::assertSame('common', $groups[1]->fields[0]->name);
        self::assertSame('group', $groups[1]->fields[0]->type);
        self::assertSame(['price'], $this->subFieldNames($groups[1]->fields[0]));
    }

    /**
     * ACF Pro(pro/fields/class-acf-field-clone.php)のcloneの展開を再現する。トップレベルのフィールドは、名前が保存名、
     * keyが元のkey(cloneが合成するkeyは使わない)で、保存名が元の定義の名前と違えば、元の定義の名前をrawに持つ。
     * groupのcloneは、サブフィールドを元の名前(ACFが値の配列のキーにする__name)で持ち、サブフィールドの保存名を
     * 管理キーとしてrawに持つ。cloneのcloneは、acf_clone_clone_field()と同じ規則で名前を合成する。
     *
     * ACFはcloneの名前にも空の値を保存するため、トップレベルでサブフィールドに置き換えたseamlessのclone自身の保存名を、
     * グループのrawに持つ。期待値は、実物のACF Pro 6.8.8(ライセンス無し)でcloneのkeyへupdate_field()したときのメタと、
     * acf_get_fields()の結果を写したもの。
     *
     * $expectedは、トップレベルのフィールドごとの[名前, key, ラベル, 型, 元の定義の名前, サブフィールドの名前, [管理キー, 元のkey]のlist]。
     *
     * @param array<string, mixed> $clone ホストのcloneの設定
     * @param list<array{string, string, string, string, string|null, list<string>, list<array{string, string}>}> $expected
     * @param list<array{string, string}> $splicedClones サブフィールドに置き換えたseamlessのcloneの[保存名, 元のkey]
     */
    #[DataProvider('acfClones')]
    public function testItReproducesTheClonesOfAcfPro(array $clone, array $expected, array $splicedClones): void
    {
        $errors = [];
        $groups = (new CloneResolver())->resolve([
            $this->group('group_psrc', [
                ['key' => 'field_psrc_title', 'name' => 'title', 'label' => 'Title', 'type' => 'text'],
                ['key' => 'field_psrc_body', 'name' => 'body', 'label' => 'Body', 'type' => 'text'],
            ]),
            $this->group('group_pmid', [
                ['key' => 'field_pmid_m', 'name' => 'm', 'label' => 'M', 'type' => 'clone', 'clone' => ['group_psrc'], 'display' => 'seamless', 'prefix_name' => 1, 'prefix_label' => 1],
                ['key' => 'field_pmid_g', 'name' => 'g', 'label' => 'G', 'type' => 'clone', 'clone' => ['group_psrc'], 'display' => 'group', 'prefix_name' => 1],
            ]),
            $this->group('group_phost', [['key' => 'field_pc', 'name' => 'c', 'label' => 'C', 'type' => 'clone'] + $clone]),
        ], $errors);

        self::assertSame([], $errors);
        self::assertSame($expected, array_map(static fn(FieldDefinition $field): array => [
            $field->name,
            $field->key,
            $field->label,
            $field->type,
            \is_string($field->raw[CloneResolver::DEFINITION_NAME] ?? null) ? $field->raw[CloneResolver::DEFINITION_NAME] : null,
            array_map(static fn(FieldDefinition $subField): string => $subField->name, $field->subFields),
            $field->raw[CloneResolver::META_KEYS] ?? [],
        ], $groups[2]->fields));
        self::assertSame($splicedClones, $groups[2]->raw[CloneResolver::META_KEYS] ?? []);
    }

    /** @return iterable<string, array{array<string, mixed>, list<array{string, string, string, string, string|null, list<string>, list<array{string, string}>}>, list<array{string, string}>}> */
    public static function acfClones(): iterable
    {
        yield 'seamless' => [['clone' => ['group_psrc'], 'display' => 'seamless'], [
            ['title', 'field_psrc_title', 'Title', 'text', null, [], []],
            ['body', 'field_psrc_body', 'Body', 'text', null, [], []],
        ], [['c', 'field_pc']]];
        yield 'displayの無いcloneはseamless' => [['clone' => ['group_psrc']], [
            ['title', 'field_psrc_title', 'Title', 'text', null, [], []],
            ['body', 'field_psrc_body', 'Body', 'text', null, [], []],
        ], [['c', 'field_pc']]];
        yield 'seamless・prefix_name・prefix_label' => [['clone' => ['group_psrc'], 'display' => 'seamless', 'prefix_name' => 1, 'prefix_label' => 1], [
            ['c_title', 'field_psrc_title', 'C Title', 'text', 'title', [], []],
            ['c_body', 'field_psrc_body', 'C Body', 'text', 'body', [], []],
        ], [['c', 'field_pc']]];
        yield 'group' => [['clone' => ['group_psrc'], 'display' => 'group'], [
            ['c', 'field_pc', 'C', 'group', null, ['title', 'body'], [['title', 'field_psrc_title'], ['body', 'field_psrc_body']]],
        ], []];
        yield 'group・prefix_name(ラベルは変えない)' => [['clone' => ['group_psrc'], 'display' => 'group', 'prefix_name' => 1, 'prefix_label' => 1], [
            ['c', 'field_pc', 'C', 'group', null, ['title', 'body'], [['c_title', 'field_psrc_title'], ['c_body', 'field_psrc_body']]],
        ], []];
        yield 'seamless・prefix_nameで、seamless・prefix_nameのcloneをfield keyで参照' => [['clone' => ['field_pmid_m'], 'display' => 'seamless', 'prefix_name' => 1], [
            ['c_m_m_title', 'field_psrc_title', 'M Title', 'text', 'title', [], []],
            ['c_m_m_body', 'field_psrc_body', 'M Body', 'text', 'body', [], []],
        ], [['c', 'field_pc'], ['c_m', 'field_pmid_m']]];
        yield 'seamlessで、seamless・prefix_nameのcloneをfield keyで参照' => [['clone' => ['field_pmid_m'], 'display' => 'seamless'], [
            ['m_title', 'field_psrc_title', 'M Title', 'text', 'title', [], []],
            ['m_body', 'field_psrc_body', 'M Body', 'text', 'body', [], []],
        ], [['c', 'field_pc'], ['m', 'field_pmid_m']]];
        yield 'seamless・prefix_nameで、cloneを持つグループを参照' => [['clone' => ['group_pmid'], 'display' => 'seamless', 'prefix_name' => 1], [
            ['c_m_title', 'field_psrc_title', 'M Title', 'text', 'title', [], []],
            ['c_m_body', 'field_psrc_body', 'M Body', 'text', 'body', [], []],
            ['c_g', 'field_pmid_g', 'G', 'group', 'g', ['title', 'body'], [['c_g_title', 'field_psrc_title'], ['c_g_body', 'field_psrc_body']]],
        ], [['c', 'field_pc']]];
        yield 'group・prefix_nameで、seamless・prefix_nameのcloneをfield keyで参照' => [['clone' => ['field_pmid_m'], 'display' => 'group', 'prefix_name' => 1], [
            ['c', 'field_pc', 'C', 'group', null, ['m'], [['c_m', 'field_pmid_m'], ['c_m_m_title', 'field_psrc_title'], ['c_m_m_body', 'field_psrc_body']]],
        ], []];
        yield 'seamless・prefix_nameで、group・prefix_nameのcloneをfield keyで参照' => [['clone' => ['field_pmid_g'], 'display' => 'seamless', 'prefix_name' => 1], [
            ['c_g', 'field_pmid_g', 'G', 'group', 'g', ['title', 'body'], [['c_g_title', 'field_psrc_title'], ['c_g_body', 'field_psrc_body']]],
        ], [['c', 'field_pc']]];
    }

    /** cloneの参照は、`group_`で始まる値をグループ、`field_`で始まる値をフィールドとして扱い、ほかの値はE004にする。 */
    public function testReferencesThatAreNeitherGroupsNorFieldsAreE004(): void
    {
        $errors = [];

        (new CloneResolver())->resolve([
            $this->group('custom_group', [['key' => 'field_price', 'name' => 'price', 'label' => '価格', 'type' => 'number']]),
            $this->group('group_product', [['key' => 'field_custom_clone', 'name' => 'custom', 'label' => 'Custom', 'type' => 'clone', 'clone' => ['custom_group']]]),
        ], $errors);

        self::assertSame(["E004: clone field 'custom' in group 'group_product' references 'custom_group', which is neither a field group key (group_) nor a field key (field_)"], $errors);
    }

    public function testItReportsMissingCloneReferences(): void
    {
        $errors = [];

        (new CloneResolver())->resolve([
            $this->group('group_product', [
                [
                    'key' => 'field_missing_clone',
                    'name' => 'missing',
                    'label' => '不足',
                    'type' => 'clone',
                    'clone' => ['field_missing'],
                    'display' => 'seamless',
                ],
            ]),
        ], $errors);

        self::assertSame(["E004: clone field 'missing' in group 'group_product' references missing field 'field_missing'"], $errors);
    }

    public function testItReportsCircularCloneReferences(): void
    {
        $errors = [];

        (new CloneResolver())->resolve([
            $this->group('group_product', [
                [
                    'key' => 'field_a',
                    'name' => 'a',
                    'label' => 'A',
                    'type' => 'clone',
                    'clone' => ['field_b'],
                    'display' => 'seamless',
                ],
                [
                    'key' => 'field_b',
                    'name' => 'b',
                    'label' => 'B',
                    'type' => 'clone',
                    'clone' => ['field_a'],
                    'display' => 'seamless',
                ],
            ]),
        ], $errors);

        // field_aを読むとfield_bの中でfield_aに戻り、field_bを読むとfield_aの中でfield_bに戻るため、循環の両端を1回ずつ報告する
        self::assertSame([
            "E005: clone field 'b' in group 'group_product' has a circular reference",
            "E005: clone field 'a' in group 'group_product' has a circular reference",
        ], $errors);
    }

    /**
     * 参照の値が`group_`で始まり、acf-jsonにそのkeyのグループが無ければE004にする(docs/09 §6.6の1。受け入れ基準
     * 「E004(参照先なし)」のグループの参照)。
     */
    public function testCloneReferencingAMissingGroupIsE004(): void
    {
        $errors = [];

        $groups = (new CloneResolver())->resolve([
            $this->group('group_product', [
                ['key' => 'field_price', 'name' => 'price', 'label' => '価格', 'type' => 'number'],
                [
                    'key' => 'field_common_clone',
                    'name' => 'common',
                    'label' => '共通',
                    'type' => 'clone',
                    'clone' => ['group_missing'],
                    'display' => 'seamless',
                ],
            ]),
        ], $errors);

        self::assertSame(["E004: clone field 'common' in group 'group_product' references missing group 'group_missing'"], $errors);
        // 参照を解決できないcloneは何も展開しない
        self::assertSame(['price'], $this->fieldNames($groups[0]));
    }

    /**
     * cloneが自分のいるグループを参照すれば、グループの読み込みが自分に戻るためE005にする(docs/09 §6.6の6。受け入れ
     * 基準「E005(循環)」のグループの自己参照)。
     */
    public function testCloneReferencingItsOwnGroupIsE005(): void
    {
        $errors = [];

        (new CloneResolver())->resolve([
            $this->group('group_product', [
                ['key' => 'field_price', 'name' => 'price', 'label' => '価格', 'type' => 'number'],
                [
                    'key' => 'field_self_clone',
                    'name' => 'self_clone',
                    'label' => '自分',
                    'type' => 'clone',
                    'clone' => ['group_product'],
                    'display' => 'seamless',
                ],
            ]),
        ], $errors);

        self::assertSame(["E005: clone field 'self_clone' in group 'group_product' has a circular reference"], $errors);
    }

    /**
     * @param list<array<string, mixed>> $fields
     */
    private function group(string $key, array $fields): FieldGroup
    {
        return FieldGroup::fromArray([
            'key' => $key,
            'title' => $key,
            'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'product']]],
            'fields' => $fields,
        ], $key . '.json');
    }

    /** @return list<string> */
    private function fieldNames(FieldGroup $group): array
    {
        return array_map(static fn($field): string => $field->name, $group->fields);
    }

    /** @return list<string> */
    private function subFieldNames(FieldDefinition $field): array
    {
        return array_map(static fn($subField): string => $subField->name, $field->subFields);
    }
}
