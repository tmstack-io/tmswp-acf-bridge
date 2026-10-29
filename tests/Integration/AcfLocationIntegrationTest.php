<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Tests\Integration;

use Closure;
use TMSWP\AcfBridge\BridgeConfig;
use TMSWP\AcfBridge\Json\FieldGroup;
use TMSWP\AcfBridge\Mapping\GenerationTarget;
use TMSWP\AcfBridge\Mapping\LocationResolver;
use TMSWP\AcfBridge\Mapping\TargetKind;
use TMSWP\Testing\IntegrationTestCase;
use WP_REST_Request;
use WP_User;

/**
 * LocationResolverの解決(docs/09 §4.2)の前提にした、ACFのlocationの判定を、有効化した無料版のACFで確かめる。
 * フィールドグループの表示はacf_get_field_group_visibility()(ACFが編集画面のグループを選ぶ関数)で判定する。
 * テーマのテンプレートは`theme_{post_type}_templates`フィルタで足す(fixtureのテーマにテンプレートのファイルは無い)。
 */
final class AcfLocationIntegrationTest extends IntegrationTestCase
{
    private const string TEMPLATE = 'templates/tmswp-feature.php';

    /** @var list<array{string, Closure}> */
    private array $templateFilters = [];

    public function tear_down(): void
    {
        foreach ($this->templateFilters as [$hook, $filter]) {
            remove_filter($hook, $filter);
        }

        acf_set_data('post_templates', null);
        parent::tear_down();
    }

    /**
     * `post_type==X AND page_template==T`は、投稿タイプXのテンプレートTの投稿にだけ当てはまる(pageのテンプレートTや、
     * テンプレートの無いXには当てはまらない)。LocationResolverは、Xのテンプレート別のクラスに解決する。
     */
    public function testPostTypeAndPageTemplateMatchOnlyThatPostTypeWithTheTemplate(): void
    {
        $this->addTemplate('tmswp_acf_post');
        $this->addTemplate('page');
        $location = [[$this->rule('post_type', 'tmswp_acf_post'), $this->rule('page_template', self::TEMPLATE)]];

        self::assertTrue($this->visible($location, ['post_type' => 'tmswp_acf_post', 'page_template' => self::TEMPLATE]));
        self::assertFalse($this->visible($location, ['post_type' => 'page', 'page_template' => self::TEMPLATE]));
        self::assertFalse($this->visible($location, ['post_type' => 'tmswp_acf_post', 'page_template' => 'default']));
        self::assertSame([[TargetKind::Post, 'tmswp_acf_post', 'TmswpFeatureTemplateFields', self::TEMPLATE]], $this->resolve($location));

        // post_templateも同じ判定で、同じクラスになる
        $postTemplate = [[$this->rule('post_type', 'tmswp_acf_post'), $this->rule('post_template', self::TEMPLATE)]];
        self::assertTrue($this->visible($postTemplate, ['post_type' => 'tmswp_acf_post', 'page_template' => self::TEMPLATE]));
        self::assertSame([[TargetKind::Post, 'tmswp_acf_post', 'TmswpFeatureTemplateFields', self::TEMPLATE]], $this->resolve($postTemplate));
    }

    /** `page_template==default`はpageにだけ当てはまり、LocationResolverはPageFieldsに解決する。 */
    public function testDefaultPageTemplateMatchesOnlyPages(): void
    {
        $this->addTemplate('tmswp_acf_post');
        $location = [[$this->rule('page_template', 'default')]];

        self::assertTrue($this->visible($location, ['post_type' => 'page']));
        self::assertFalse($this->visible($location, ['post_type' => 'tmswp_acf_post']));
        self::assertSame([[TargetKind::Post, 'page', 'PageFields', null]], $this->resolve($location));
    }

    /**
     * 値を複数持てるparam(post_category・user_role・page_type)は、同じparamの違う値が1つのANDグループで同時に
     * 成り立つ。LocationResolverはW007にしない。
     */
    public function testMultiValueParamsCanBeSatisfiedTogether(): void
    {
        $news = wp_insert_term('TMSWP news', 'category', ['slug' => 'tmswp-news']);
        $event = wp_insert_term('TMSWP event', 'category', ['slug' => 'tmswp-event']);
        self::assertIsArray($news);
        self::assertIsArray($event);
        $postId = self::createPost('post');
        wp_set_post_categories($postId, [$news['term_id'], $event['term_id']]);
        $categories = [[$this->rule('post_category', 'category:tmswp-news'), $this->rule('post_category', 'category:tmswp-event')]];

        self::assertTrue($this->visible($categories, ['post_id' => $postId, 'post_type' => 'post']));
        self::assertSame([[TargetKind::Post, 'post', 'PostFields', null]], $this->resolve($categories));

        $userId = self::factory()->user->create(['role' => 'editor']);
        self::assertIsInt($userId);
        $user = get_userdata($userId);
        self::assertInstanceOf(WP_User::class, $user);
        $user->add_role('author');
        $roles = [[$this->rule('user_role', 'editor'), $this->rule('user_role', 'author')]];

        self::assertTrue($this->visible($roles, ['user_id' => $userId]));
        self::assertSame([[TargetKind::User, '', 'UserFields', null]], $this->resolve($roles));

        $pageId = self::createPost('page');
        update_option('page_on_front', $pageId);
        $pageTypes = [[$this->rule('page_type', 'front_page'), $this->rule('page_type', 'top_level')]];

        self::assertTrue($this->visible($pageTypes, ['post_id' => $pageId, 'post_type' => 'page']));
        self::assertSame([[TargetKind::Post, 'page', 'PageFields', null]], $this->resolve($pageTypes));
    }

    /**
     * 値を1つしか持てないparam(post_type)の違う値は、どの投稿にも当てはまらず、LocationResolverはW007にする。`all`は
     * どの値にも当てはまるため、ほかの値と同時に成り立つ。
     */
    public function testSingleValueParamsCannotHoldTwoValues(): void
    {
        $conflicting = [[$this->rule('post_type', 'post'), $this->rule('post_type', 'page')]];

        self::assertFalse($this->visible($conflicting, ['post_type' => 'post']));
        self::assertFalse($this->visible($conflicting, ['post_type' => 'page']));
        $warnings = [];
        self::assertSame([], (new LocationResolver())->resolveWithWarnings($this->group($conflicting), new BridgeConfig([], '/tmp/acf', 'App\Acf'), $warnings));
        self::assertSame(["W007: ACF field group 'group_tmswp_location' has an unsatisfiable location condition for 'post_type'"], $warnings);

        $withAll = [[$this->rule('post_type', 'all'), $this->rule('post_type', 'tmswp_acf_post')]];
        self::assertTrue($this->visible($withAll, ['post_type' => 'tmswp_acf_post']));
        self::assertSame([[TargetKind::Post, 'tmswp_acf_post', 'TmswpAcfPostFields', null]], $this->resolve($withAll));
    }

    /**
     * 同じ値源(post_type、テンプレートのメタ、投稿のID)の明白な矛盾は、ACFではどの画面にも当てはまらない。
     * LocationResolverはW007にして、そのANDグループを生成から除く。
     */
    public function testContradictionsOfTheSameValueSourceNeverMatch(): void
    {
        $this->addTemplate('tmswp_acf_post');
        $first = self::createPost('page');
        $second = self::createPost('page');
        $cases = [
            "'post_type'; '==' and '!=' compare the same value 'tmswp_acf_post'" => [
                [[$this->rule('post_type', 'tmswp_acf_post'), $this->rule('post_type', 'tmswp_acf_post', '!=')]],
                [['post_type' => 'tmswp_acf_post'], ['post_type' => 'tmswp_acf_post', 'page_template' => self::TEMPLATE]],
            ],
            "'page_template' and 'post_template'; '==' and '!=' compare the same value '" . self::TEMPLATE . "'" => [
                [[$this->rule('post_type', 'tmswp_acf_post'), $this->rule('page_template', self::TEMPLATE), $this->rule('post_template', self::TEMPLATE, '!=')]],
                [['post_type' => 'tmswp_acf_post', 'page_template' => self::TEMPLATE], ['post_type' => 'tmswp_acf_post', 'page_template' => 'default']],
            ],
            "'page' and 'post'" => [
                [[$this->rule('page', (string) $first), $this->rule('post', (string) $second)]],
                [['post_id' => $first, 'post_type' => 'page'], ['post_id' => $second, 'post_type' => 'page']],
            ],
        ];

        foreach ($cases as $reason => [$location, $screens]) {
            foreach ($screens as $screen) {
                self::assertFalse($this->visible($location, $screen), $reason);
            }

            $warnings = [];
            self::assertSame([], (new LocationResolver())->resolveWithWarnings($this->group($location), new BridgeConfig([], '/tmp/acf', 'App\Acf'), $warnings));
            self::assertSame(["W007: ACF field group 'group_tmswp_location' has an unsatisfiable location condition for {$reason}"], $warnings);
        }
    }

    /**
     * ACFのcompare_to_rule()はPHPの`==`で比べるため、同じ投稿のIDの違う表記(`1`と`01`)は同じ値で、`page==ID AND
     * post==0ID`は成り立ち、`page==ID AND post!=0ID`は成り立たない。数値でない値の大文字小文字の違いは違う値である。
     * LocationResolverは、同じ比べ方で判定する。
     */
    public function testValuesAreComparedLikeAcf(): void
    {
        $pageId = self::createPost('page');
        $screen = ['post_id' => $pageId, 'post_type' => 'page'];
        $sameId = [[$this->rule('page', (string) $pageId), $this->rule('post', '0' . $pageId)]];
        $notSameId = [[$this->rule('page', (string) $pageId), $this->rule('post', '0' . $pageId, '!=')]];

        self::assertTrue($this->visible($sameId, $screen));
        self::assertSame([[TargetKind::Post, 'page', 'PageFields', null]], $this->resolve($sameId));
        self::assertFalse($this->visible($notSameId, $screen));
        $warnings = [];
        self::assertSame([], (new LocationResolver())->resolveWithWarnings($this->group($notSameId), new BridgeConfig([], '/tmp/acf', 'App\Acf'), $warnings));
        self::assertSame(["W007: ACF field group 'group_tmswp_location' has an unsatisfiable location condition for 'page' and 'post'; '==' and '!=' compare the same value '0{$pageId}'"], $warnings);

        $otherCase = [[$this->rule('post_type', 'tmswp_acf_post'), $this->rule('post_type', 'TMSWP_ACF_POST', '!=')]];
        self::assertTrue($this->visible($otherCase, ['post_type' => 'tmswp_acf_post']));
        self::assertSame([[TargetKind::Post, 'tmswp_acf_post', 'TmswpAcfPostFields', null]], $this->resolve($otherCase));
    }

    /**
     * ACFのuser_roleは、ruleの値ごとにuser_can()を呼ぶ。WordPressは数値の`1`を`level_1`、`01`を`level_01`の権限として
     * 調べ、標準のeditorはlevel_1を持ちlevel_01を持たないため、`user_role==1 AND user_role!=01`はeditorで成り立つ。
     * LocationResolverは、値源に入らないparamを完全に同じ文字列だけで照合し、この組み合わせを除かない。同じ文字列の
     * `user_role==editor AND user_role!=editor`は、どのユーザーでも成り立たず、W007になる。
     */
    public function testUserRoleValuesAreComparedAsRawStrings(): void
    {
        // user_can()に数値の権限を渡すと、WordPressはユーザーレベルの非推奨の通知を出す
        $this->setExpectedDeprecated('has_cap');
        $editorId = self::factory()->user->create(['role' => 'editor']);
        self::assertIsInt($editorId);
        $levels = [[$this->rule('user_role', '1'), $this->rule('user_role', '01', '!=')]];

        self::assertTrue($this->visible($levels, ['user_id' => $editorId]));
        self::assertSame([[TargetKind::User, '', 'UserFields', null]], $this->resolve($levels));

        $same = [[$this->rule('user_role', 'editor'), $this->rule('user_role', 'editor', '!=')]];
        self::assertFalse($this->visible($same, ['user_id' => $editorId]));
        $warnings = [];
        self::assertSame([], (new LocationResolver())->resolveWithWarnings($this->group($same), new BridgeConfig([], '/tmp/acf', 'App\Acf'), $warnings));
        self::assertSame(["W007: ACF field group 'group_tmswp_location' has an unsatisfiable location condition for 'user_role'; '==' and '!=' compare the same value 'editor'"], $warnings);
    }

    /**
     * ACFのuser_formは、RESTの画面ではoperatorによらずtrueを返すため、`user_form==edit AND user_form!=edit`はRESTで
     * 成り立ち、ACFのREST API(GET /wp/v2/users/<id>)がそのグループのフィールドを返す。LocationResolverは、この組み合わせを
     * 矛盾として除かず、UserFieldsに解決する。
     */
    public function testUserFormMatchesInRestRegardlessOfTheOperator(): void
    {
        $location = [[$this->rule('user_form', 'edit'), $this->rule('user_form', 'edit', '!=')]];
        $userId = self::factory()->user->create(['role' => 'administrator']);
        self::assertIsInt($userId);

        self::assertTrue($this->visible($location, ['user_id' => $userId, 'rest' => true]));
        self::assertFalse($this->visible($location, ['user_id' => $userId, 'user_form' => 'edit']));
        self::assertSame([[TargetKind::User, '', 'UserFields', null]], $this->resolve($location));

        self::assertTrue(acf_add_local_field_group([
            'key' => 'group_tmswp_location_rest',
            'title' => 'REST',
            'fields' => [['key' => 'field_tmswp_location_rest_note', 'name' => 'tmswp_location_rest_note', 'label' => 'note', 'type' => 'text']],
            'location' => $location,
            'show_in_rest' => 1,
        ]));

        try {
            update_user_meta($userId, 'tmswp_location_rest_note', 'from rest');
            update_user_meta($userId, '_tmswp_location_rest_note', 'field_tmswp_location_rest_note');
            wp_set_current_user($userId);
            $request = new WP_REST_Request('GET', "/wp/v2/users/{$userId}");
            $request->set_param('context', 'edit');
            $data = rest_do_request($request)->get_data();

            self::assertIsArray($data);
            self::assertIsArray($data['acf'] ?? null);
            self::assertSame('from rest', $data['acf']['tmswp_location_rest_note'] ?? null);
        } finally {
            wp_set_current_user(0);
            acf_remove_local_field('field_tmswp_location_rest_note');
            acf_remove_local_field_group('group_tmswp_location_rest');
        }
    }

    /**
     * `page_template==all AND post_template==T`(post_typeが無い)は、ACFではpageだけでなく、テンプレートTを持つほかの投稿
     * タイプにも当てはまる。LocationResolverは、pageだけに決めずW002にする。
     */
    public function testPostTemplateWithoutPostTypeMatchesEveryPostTypeWithTheTemplate(): void
    {
        $this->addTemplate('tmswp_acf_post');
        $this->addTemplate('page');
        $location = [[$this->rule('page_template', 'all'), $this->rule('post_template', self::TEMPLATE)]];

        self::assertTrue($this->visible($location, ['post_type' => 'tmswp_acf_post', 'page_template' => self::TEMPLATE]));
        self::assertTrue($this->visible($location, ['post_type' => 'page', 'page_template' => self::TEMPLATE]));
        $warnings = [];
        self::assertSame([], (new LocationResolver())->resolveWithWarnings($this->group($location), new BridgeConfig([], '/tmp/acf', 'App\Acf'), $warnings));
        self::assertSame(["W002: location AND group 1 of ACF field group 'group_tmswp_location' was skipped: 'post_template' needs a 'post_type ==' condition in the same AND group to decide the post type"], $warnings);
    }

    /**
     * テンプレートを選んだpageには、pageのグループとテンプレートのグループが両方表示され、ACFは同じ名前のフィールドを
     * どちらも同じ投稿のメタ(その名前)に保存する。そのためGeneratorは、PageFieldsとテンプレート別のクラスの同じ名前の
     * 別のフィールドをE001にする(docs/09 §4.3)。
     */
    public function testPageAndTemplateGroupsShareTheMetaOfThePost(): void
    {
        $this->addTemplate('page');
        $page = [[$this->rule('post_type', 'page')]];
        $template = [[$this->rule('page_template', self::TEMPLATE)]];
        $screen = ['post_type' => 'page', 'page_template' => self::TEMPLATE];

        self::assertTrue($this->visible($page, $screen));
        self::assertTrue($this->visible($template, $screen));

        $groups = ['group_tmswp_location_page' => [$page, 'field_tmswp_location_page_subtitle'], 'group_tmswp_location_template' => [$template, 'field_tmswp_location_template_subtitle']];

        try {
            foreach ($groups as $key => [$location, $fieldKey]) {
                $this->registerLocalGroup($key, $location, $fieldKey);
            }

            $pageId = self::createPost('page');
            update_post_meta($pageId, '_wp_page_template', self::TEMPLATE);

            self::assertNotFalse(update_field('field_tmswp_location_template_subtitle', 'from template', $pageId));
            self::assertNotFalse(update_field('field_tmswp_location_page_subtitle', 'from page', $pageId));
            self::assertSame('from page', get_post_meta($pageId, 'tmswp_location_subtitle', true));
            self::assertSame('field_tmswp_location_page_subtitle', get_post_meta($pageId, '_tmswp_location_subtitle', true));
        } finally {
            // ACFのローカルのグループはプロセスの中で続くため、ほかのテストのpageに表示されないよう外す
            foreach ($groups as $key => [$location, $fieldKey]) {
                acf_remove_local_field($fieldKey);
                acf_remove_local_field_group($key);
            }
        }
    }

    /** 投稿タイプ$postTypeのテーマのテンプレートに、TEMPLATEを足す(ACFのテンプレートのキャッシュも消す)。 */
    private function addTemplate(string $postType): void
    {
        $filter = static fn(array $templates): array => [...$templates, self::TEMPLATE => 'TMSWP feature'];
        add_filter("theme_{$postType}_templates", $filter);
        $this->templateFilters[] = ["theme_{$postType}_templates", $filter];
        acf_set_data('post_templates', null);
    }

    /** @return array{param: string, operator: string, value: string} */
    private function rule(string $param, string $value, string $operator = '=='): array
    {
        return ['param' => $param, 'operator' => $operator, 'value' => $value];
    }

    /**
     * locationが$locationのフィールドグループが、ACFの画面$screenで表示されるか。
     *
     * @param list<list<array{param: string, operator: string, value: string}>> $location
     * @param array<string, mixed> $screen
     */
    private function visible(array $location, array $screen): bool
    {
        return acf_get_field_group_visibility(acf_validate_field_group(['key' => 'group_tmswp_location', 'title' => 'location', 'location' => $location]), $screen);
    }

    /**
     * @param list<list<array{param: string, operator: string, value: string}>> $location
     *
     * @return list<array{TargetKind, string, string, string|null}>
     */
    private function resolve(array $location): array
    {
        return array_map(
            static fn(GenerationTarget $target): array => [$target->kind, $target->subtype, $target->className, $target->template],
            (new LocationResolver())->resolve($this->group($location), new BridgeConfig([], '/tmp/acf', 'App\Acf')),
        );
    }

    /** @param list<list<array{param: string, operator: string, value: string}>> $location */
    private function group(array $location): FieldGroup
    {
        return FieldGroup::fromArray(['key' => 'group_tmswp_location', 'title' => 'location', 'location' => $location, 'fields' => []], 'group_tmswp_location.json');
    }

    /**
     * ACFのローカルのフィールドグループ(textのフィールドtmswp_location_subtitle、field keyは$fieldKey)を登録する。データベース
     * には書かない。
     *
     * @param list<list<array{param: string, operator: string, value: string}>> $location
     */
    private function registerLocalGroup(string $key, array $location, string $fieldKey): void
    {
        self::assertTrue(acf_add_local_field_group([
            'key' => $key,
            'title' => $key,
            'fields' => [['key' => $fieldKey, 'name' => 'tmswp_location_subtitle', 'label' => 'subtitle', 'type' => 'text']],
            'location' => $location,
        ]));
    }
}
