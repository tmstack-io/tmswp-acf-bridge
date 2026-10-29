<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Tests\Unit\Mapping;

use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\AcfBridge\BridgeConfig;
use TMSWP\AcfBridge\Json\FieldGroup;
use TMSWP\AcfBridge\Mapping\GenerationTarget;
use TMSWP\AcfBridge\Mapping\LocationResolver;
use TMSWP\AcfBridge\Mapping\TargetKind;
use TMSWP\Testing\UnitTestCase;

/**
 * LocationResolverがlocationをdocs/09 §4.2のとおりに解決することを確かめる。ACFの実際の判定(どの条件の組み合わせが
 * 成り立つか)は、実物のACFの統合テスト(AcfLocationIntegrationTest)で確かめる。
 */
final class LocationResolverTest extends UnitTestCase
{
    public function testItResolvesSupportedLocationTargets(): void
    {
        $resolver = new LocationResolver();

        self::assertSame(
            [[TargetKind::Post, 'product', 'ProductFields', null]],
            $this->targetRows($resolver->resolve($this->group('post_type', 'product'), $this->config())),
        );
        self::assertSame(
            [[TargetKind::Post, 'page', 'PageFields', null]],
            $this->targetRows($resolver->resolve($this->group('page_type', 'front_page'), $this->config())),
        );
        self::assertSame(
            [[TargetKind::Term, 'genre', 'GenreTermFields', null]],
            $this->targetRows($resolver->resolve($this->group('taxonomy', 'genre'), $this->config())),
        );
        self::assertSame(
            [[TargetKind::Term, 'all', 'TermFields', null]],
            $this->targetRows($resolver->resolve($this->group('taxonomy', 'all'), $this->config())),
        );
        self::assertSame(
            [[TargetKind::User, '', 'UserFields', null]],
            $this->targetRows($resolver->resolve($this->group('user_role', 'editor'), $this->config())),
        );
        self::assertSame(
            [[TargetKind::Options, 'theme-settings', 'ThemeSettingsOptions', null]],
            $this->targetRows($resolver->resolve($this->group('options_page', 'theme-settings'), $this->config())),
        );
    }

    public function testItResolvesMultipleOrGroupsAsMultipleTargets(): void
    {
        $group = $this->andGroups([[['post_type', 'product']], [['post_type', 'news']]]);

        self::assertSame(
            [
                [TargetKind::Post, 'news', 'NewsFields', null],
                [TargetKind::Post, 'product', 'ProductFields', null],
            ],
            $this->targetRows((new LocationResolver())->resolve($group, $this->config())),
        );
    }

    public function testClassMapOverridesSingleTargetGroupClassName(): void
    {
        $config = new BridgeConfig([], '/tmp/acf', 'App\\Acf', classMap: ['group_test' => 'CustomProductFields']);

        self::assertSame(
            [[TargetKind::Post, 'product', 'CustomProductFields', null]],
            $this->targetRows((new LocationResolver())->resolve($this->group('post_type', 'product'), $config)),
        );
    }

    public function testItWarnsWhenMultipleTargetKindsRequirePriority(): void
    {
        $warnings = [];
        $group = $this->andGroups([[['post_type', 'product'], ['taxonomy', 'genre']]]);

        self::assertSame(
            [[TargetKind::Post, 'product', 'ProductFields', null]],
            $this->targetRows((new LocationResolver())->resolveWithWarnings($group, $this->config(), $warnings)),
        );
        self::assertSame(["W004: ACF field group 'group_test' has multiple target location kinds; priority was applied"], $warnings);
    }

    /**
     * 同じ種別(投稿)の条件を上書きせず、すべてを満たす1つの対象にする。post_typeがあれば、ほかの条件(page_type・
     * post_format等)が示す投稿タイプより優先する(ACFはそれらの条件を、その投稿タイプの投稿にも当てはめる)。
     *
     * @param list<array{string, string}|array{string, string, string}> $conditions
     * @param array{TargetKind, string, string, string|null} $expected
     */
    #[DataProvider('sameKindConditions')]
    public function testConditionsOfTheSameKindAreCombined(array $conditions, array $expected): void
    {
        $warnings = [];

        self::assertSame([$expected], $this->targetRows((new LocationResolver())->resolveWithWarnings($this->andGroups([$conditions]), $this->config(), $warnings)));
        self::assertSame([], $warnings);
    }

    /** @return iterable<string, array{list<array{string, string}|array{string, string, string}>, array{TargetKind, string, string, string|null}}> */
    public static function sameKindConditions(): iterable
    {
        yield 'post_typeとpage_templateは、その投稿タイプのテンプレートのクラス' => [
            [['post_type', 'product'], ['page_template', 'templates/feature.php']],
            [TargetKind::Post, 'product', 'FeatureTemplateFields', 'templates/feature.php'],
        ];
        yield '順を逆にしても同じ' => [
            [['page_template', 'templates/feature.php'], ['post_type', 'product']],
            [TargetKind::Post, 'product', 'FeatureTemplateFields', 'templates/feature.php'],
        ];
        yield 'post_typeとpost_template' => [
            [['post_template', 'templates/feature.php'], ['post_type', 'product']],
            [TargetKind::Post, 'product', 'FeatureTemplateFields', 'templates/feature.php'],
        ];
        yield 'post_typeとpage_typeはpost_typeのクラス' => [
            [['post_type', 'product'], ['page_type', 'top_level']],
            [TargetKind::Post, 'product', 'ProductFields', null],
        ];
        yield 'page_typeとpageとpage_parentはPageFields' => [
            [['page_type', 'child'], ['page', '10'], ['page_parent', '2']],
            [TargetKind::Post, 'page', 'PageFields', null],
        ];
        yield 'page_templateだけはpageのテンプレートのクラス' => [
            [['page_template', 'templates/page-about.php']],
            [TargetKind::Post, 'page', 'PageAboutTemplateFields', 'templates/page-about.php'],
        ];
        yield 'page_templateとpage_typeはpageのテンプレートのクラス' => [
            [['page_template', 'templates/page-about.php'], ['page_type', 'child']],
            [TargetKind::Post, 'page', 'PageAboutTemplateFields', 'templates/page-about.php'],
        ];
        yield 'page_template==defaultはPageFields' => [
            [['page_template', 'default']],
            [TargetKind::Post, 'page', 'PageFields', null],
        ];
        yield 'post_template==defaultは投稿タイプのクラス' => [
            [['post_template', 'default'], ['post_type', 'product']],
            [TargetKind::Post, 'product', 'ProductFields', null],
        ];
        yield 'page_templateとpost_templateが同じテンプレート' => [
            [['page_template', 'templates/feature.php'], ['post_template', 'templates/feature.php'], ['post_type', 'product']],
            [TargetKind::Post, 'product', 'FeatureTemplateFields', 'templates/feature.php'],
        ];
        yield 'post_type==allとほかの値はその値' => [
            [['post_type', 'all'], ['post_type', 'product']],
            [TargetKind::Post, 'product', 'ProductFields', null],
        ];
        yield '値を複数持てるpost_categoryの2つの値は成り立つ' => [
            [['post_category', 'category:news'], ['post_category', 'category:event']],
            [TargetKind::Post, 'post', 'PostFields', null],
        ];
        yield '値を複数持てるpost_taxonomyの2つの値とpost_type' => [
            [['post_type', 'product'], ['post_taxonomy', 'genre:a'], ['post_taxonomy', 'genre:b']],
            [TargetKind::Post, 'product', 'ProductFields', null],
        ];
        yield '値を複数持てるuser_roleの2つの値は成り立つ' => [
            [['user_role', 'editor'], ['user_role', 'author']],
            [TargetKind::User, '', 'UserFields', null],
        ];
        yield '違う値の==と!=は成り立つ' => [
            [['post_type', 'product'], ['post_type', 'news', '!=']],
            [TargetKind::Post, 'product', 'ProductFields', null],
        ];
        yield 'pageとpostの同じIDの違う表記(PHPの==で同じ値)は成り立つ' => [
            [['page', '1'], ['post', '01']],
            [TargetKind::Post, 'page', 'PageFields', null],
        ];
        yield 'post_typeの大文字小文字の違う==と!=は成り立つ' => [
            [['post_type', 'product'], ['post_type', 'Product', '!=']],
            [TargetKind::Post, 'product', 'ProductFields', null],
        ];
        yield '値源に入らないuser_roleの1と01の==と!=(別の権限の判定)は成り立つ' => [
            [['user_role', '1'], ['user_role', '01', '!=']],
            [TargetKind::User, '', 'UserFields', null],
        ];
        yield 'user_formの同じ値の==と!=(RESTではどちらも成り立つ)' => [
            [['user_form', 'edit'], ['user_form', 'edit', '!=']],
            [TargetKind::User, '', 'UserFields', null],
        ];
        yield '値を複数持てるpage_typeの2つの値は成り立つ' => [
            [['page_type', 'front_page'], ['page_type', 'top_level']],
            [TargetKind::Post, 'page', 'PageFields', null],
        ];
    }

    /**
     * ORで複数のテンプレートを条件にするグループは、それぞれのテンプレートのクラスに出す。1つのテンプレートを複数の
     * 投稿タイプが使う場合は、投稿タイプごとの対象(管理キーは投稿タイプごと)で、クラスはテンプレートごとに1つ(key)。
     */
    public function testEachTemplateOfOrGroupsIsATarget(): void
    {
        $targets = (new LocationResolver())->resolve($this->andGroups([
            [['page_template', 'templates/page-about.php']],
            [['page_template', 'templates/page-contact.php']],
            [['post_type', 'product'], ['post_template', 'templates/page-about.php']],
        ]), $this->config());

        self::assertSame([
            [TargetKind::Post, 'page', 'PageAboutTemplateFields', 'templates/page-about.php'],
            [TargetKind::Post, 'page', 'PageContactTemplateFields', 'templates/page-contact.php'],
            [TargetKind::Post, 'product', 'PageAboutTemplateFields', 'templates/page-about.php'],
        ], $this->targetRows($targets));
        self::assertSame(
            ['post-template:templates/page-about.php', 'post-template:templates/page-contact.php', 'post-template:templates/page-about.php'],
            array_map(static fn(GenerationTarget $target): string => $target->key(), $targets),
        );
    }

    public function testItWarnsForUnresolvedPostIdLocation(): void
    {
        $warnings = [];

        self::assertSame(
            [],
            (new LocationResolver())->resolveWithWarnings($this->group('post', '10'), $this->config(), $warnings),
        );
        self::assertSame(["W003: ACF field group 'group_test' uses a post ID location that cannot be resolved statically"], $warnings);
    }

    /** blockは非対応で、独自ブロックはdocs/12 §1.11(D-26)の方式で作るよう案内する。 */
    public function testItWarnsForUnsupportedLocationParams(): void
    {
        $warnings = [];

        self::assertSame([], (new LocationResolver())->resolveWithWarnings($this->group('block', 'acf/hero'), $this->config(), $warnings));
        self::assertSame([], (new LocationResolver())->resolveWithWarnings($this->group('widget', 'sidebar'), $this->config(), $warnings));
        self::assertSame([], (new LocationResolver())->resolveWithWarnings($this->group('unknown_param', 'x'), $this->config(), $warnings));
        self::assertSame([
            "W006: ACF field group 'group_test' uses unsupported location param 'block'; acf-bridge does not generate accessors for ACF Blocks, so build custom blocks as described in docs/12 §1.11 (D-26)",
            "W006: ACF field group 'group_test' uses unsupported location param 'widget'",
            "W006: ACF field group 'group_test' uses unknown location param 'unknown_param'",
        ], $warnings);
    }

    /**
     * 値を1つしか持てないparamの、`all`でない違う値は成り立たない(W007)。テンプレートは、page_templateとpost_templateの
     * 間でも同じ。page_template==defaultはpageにだけ成り立つ。
     *
     * @param list<array{string, string}|array{string, string, string}> $conditions
     */
    #[DataProvider('unsatisfiableConditions')]
    public function testItWarnsForUnsatisfiableConditions(array $conditions, string $param): void
    {
        $warnings = [];

        self::assertSame([], (new LocationResolver())->resolveWithWarnings($this->andGroups([$conditions]), $this->config(), $warnings));
        self::assertCount(1, $warnings);
        self::assertStringStartsWith("W007: ACF field group 'group_test' has an unsatisfiable location condition for {$param}", $warnings[0]);
    }

    /** @return iterable<string, array{list<array{string, string}|array{string, string, string}>, string}> */
    public static function unsatisfiableConditions(): iterable
    {
        yield 'post_type' => [[['post_type', 'product'], ['post_type', 'news']], "'post_type'"];
        yield 'page_template' => [[['page_template', 'a.php'], ['page_template', 'b.php']], "'page_template'"];
        yield 'post_template' => [[['post_type', 'product'], ['post_template', 'a.php'], ['post_template', 'b.php']], "'post_template'"];
        yield 'page' => [[['page', '1'], ['page', '2']], "'page'"];
        yield 'page_parent' => [[['page_parent', '1'], ['page_parent', '2']], "'page_parent'"];
        yield 'post' => [[['post', '1'], ['post', '2']], "'post'"];
        yield 'post_status' => [[['post_type', 'post'], ['post_status', 'publish'], ['post_status', 'draft']], "'post_status'"];
        yield 'post_format' => [[['post_format', 'video'], ['post_format', 'audio']], "'post_format'"];
        yield 'taxonomy' => [[['taxonomy', 'genre'], ['taxonomy', 'category']], "'taxonomy'"];
        yield 'options_page' => [[['options_page', 'a'], ['options_page', 'b']], "'options_page'"];
        yield 'page_templateとpost_template' => [[['post_type', 'product'], ['page_template', 'a.php'], ['post_template', 'b.php']], "'page_template' and 'post_template'"];
        yield 'page以外のpage_template==default' => [[['post_type', 'product'], ['page_template', 'default']], "'page_template'"];
        yield '同じ値の==と!=' => [[['post_type', 'product'], ['post_type', 'product', '!=']], "'post_type'; '==' and '!=' compare the same value 'product'"];
        yield 'page_template==Xとpost_template!=X(同じメタ)' => [[['post_type', 'product'], ['page_template', 'a.php'], ['post_template', 'a.php', '!=']], "'page_template' and 'post_template'; '==' and '!=' compare the same value 'a.php'"];
        yield 'pageとpostの違うID(同じ投稿のID)' => [[['page', '1'], ['post', '2']], "'page' and 'post'"];
        yield 'pageとpostの同じIDの違う表記の==と!=' => [[['page', '1'], ['post', '01', '!=']], "'page' and 'post'; '==' and '!=' compare the same value '01'"];
        yield 'post_typeの大文字小文字の違う==' => [[['post_type', 'product'], ['post_type', 'Product']], "'post_type'"];
        yield 'テンプレートの表記の違う==' => [[['post_type', 'product'], ['page_template', 'templates/Feature.php'], ['post_template', 'templates/feature.php']], "'page_template' and 'post_template'"];
        yield '!= all' => [[['post_type', 'product'], ['post_type', 'all', '!=']], "'post_type'; '!= all' never matches"];
        yield 'user_roleの同じ値の==と!=' => [[['user_role', 'editor'], ['user_role', 'editor', '!=']], "'user_role'; '==' and '!=' compare the same value 'editor'"];
        yield '値を複数持てるparamの同じ値の==と!=' => [[['post_category', 'category:news'], ['post_category', 'category:news', '!=']], "'post_category'; '==' and '!=' compare the same value 'category:news'"];
    }

    /**
     * post_templateは、page_templateがあっても、同じANDグループの`post_type ==`が無ければW002にする(page_template==allや
     * 同じテンプレートを並べても、pageだけに決めない)。
     *
     * @param list<array{string, string}> $conditions
     */
    #[DataProvider('postTemplatesWithoutPostType')]
    public function testPostTemplateWithoutPostTypeIsW002EvenWithPageTemplate(array $conditions): void
    {
        $warnings = [];

        self::assertSame([], (new LocationResolver())->resolveWithWarnings($this->andGroups([$conditions]), $this->config(), $warnings));
        self::assertSame(["W002: location AND group 1 of ACF field group 'group_test' was skipped: 'post_template' needs a 'post_type ==' condition in the same AND group to decide the post type"], $warnings);
    }

    /** @return iterable<string, array{list<array{string, string}>}> */
    public static function postTemplatesWithoutPostType(): iterable
    {
        yield 'page_template==all' => [[['page_template', 'all'], ['post_template', 'templates/feature.php']]];
        yield '同じテンプレート' => [[['page_template', 'templates/feature.php'], ['post_template', 'templates/feature.php']]];
        yield 'post_template==default' => [[['page_template', 'default'], ['post_template', 'default']]];
    }

    /**
     * `==`で対象を決められないANDグループは、ANDグループごとにW002にする。post_templateは同じANDグループの
     * `post_type ==`で投稿タイプを決め、無ければW002。ほかの条件が違う投稿タイプを示すときもW002にする。
     */
    public function testEachUnresolvedAndGroupIsW002(): void
    {
        $warnings = [];
        $group = $this->andGroups([
            [['post_type', 'product']],
            [['current_user', 'logged_in']],
            [['post_template', 'templates/feature.php']],
            [['page_type', 'child'], ['post_format', 'video']],
        ]);

        self::assertSame([[TargetKind::Post, 'product', 'ProductFields', null]], $this->targetRows((new LocationResolver())->resolveWithWarnings($group, $this->config(), $warnings)));
        self::assertSame([
            "W002: location AND group 2 of ACF field group 'group_test' was skipped: it has no '==' condition that decides the target",
            "W002: location AND group 3 of ACF field group 'group_test' was skipped: 'post_template' needs a 'post_type ==' condition in the same AND group to decide the post type",
            "W002: location AND group 4 of ACF field group 'group_test' was skipped: its conditions imply different post types (page, post) without a 'post_type ==' condition",
        ], $warnings);
    }

    /** ASCIIの英数字の無い名前は、クラス名を空にする(生成時のE003)。 */
    public function testNamesWithoutAsciiLettersHaveNoClassName(): void
    {
        $targets = (new LocationResolver())->resolve($this->andGroups([[['page_template', 'templates/特集.php']], [['post_type', '製品']]]), $this->config());

        self::assertSame([
            [TargetKind::Post, 'page', '', 'templates/特集.php'],
            [TargetKind::Post, '製品', '', null],
        ], $this->targetRows($targets));
    }

    private function config(): BridgeConfig
    {
        return new BridgeConfig([], '/tmp/acf', 'App\\Acf');
    }

    private function group(string $param, string $value): FieldGroup
    {
        return $this->andGroups([[[$param, $value]]]);
    }

    /**
     * ANDグループごとの条件([param, value]か[param, value, operator]。operatorの既定は`==`)を持つグループ。
     *
     * @param list<list<array{string, string}|array{string, string, string}>> $andGroups
     */
    private function andGroups(array $andGroups): FieldGroup
    {
        return FieldGroup::fromArray([
            'key' => 'group_test',
            'title' => 'テスト',
            'location' => array_map(
                static fn(array $conditions): array => array_map(static fn(array $condition): array => ['param' => $condition[0], 'operator' => $condition[2] ?? '==', 'value' => $condition[1]], $conditions),
                $andGroups,
            ),
            'fields' => [],
        ], 'group_test.json');
    }

    /**
     * @param list<GenerationTarget> $targets
     *
     * @return list<array{TargetKind, string, string, string|null}>
     */
    private function targetRows(array $targets): array
    {
        $rows = [];

        foreach ($targets as $target) {
            $rows[] = [$target->kind, $target->subtype, $target->className, $target->template];
        }

        return $rows;
    }
}
