<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Tests\Integration;

use Closure;
use DateTimeImmutable;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use TMSWP\AcfBridge\AcfFieldAccess;
use TMSWP\AcfBridge\BridgeConfig;
use TMSWP\AcfBridge\Generate\GeneratedFile;
use TMSWP\AcfBridge\Generate\Generator;
use TMSWP\AcfBridge\Generate\GeneratorContext;
use TMSWP\AcfBridge\Json\FieldGroup;
use TMSWP\AcfBridge\Tests\Support\RestoresAcfStores;
use TMSWP\Testing\IntegrationTestCase;
use TMSWP\Testing\UsesTempDirectories;

/**
 * 全型のfixture(packages/acf-bridge/tests/fixtures/acf-jsonのgroup_product_typesとgroup_product)から生成した
 * アクセサを、有効化した実物のACFで確かめる(docs/09 §6.2・§6.5)。
 *
 * - ACFの管理画面と同じ経路(field keyへのupdate_field())で保存した値を、生成getterが型どおりの値で返す。
 * - 生成setterで保存した値も、生成getterが同じ値で返し、ACFもフィールドとして読む。
 * - 値の無い投稿では、生成getterが型ごとの未設定の値('' / [] / false / null)を返す。
 * - has*()は保存した値の有無を返し、delete*()は値を消す。
 *
 * 無料版のACFにある型は、fixtureからProの型(repeater・flexible_content・gallery・clone)を除いたグループで確かめる
 * (CI)。Proの型を含むグループの確認はProを与えたときだけ走らせる(グループacf-pro。docs/09 §14)。
 * fixtureのグループはACFのローカルのフィールドグループとして登録し、tear_downでACFのstoreを開始時に戻して除く。
 * 生成するクラスはfixtureのテーマ(debugのKernel)の名前空間に置くため、形の不一致は例外になる。
 */
final class AcfFieldTypesIntegrationTest extends IntegrationTestCase
{
    use RestoresAcfStores;
    use UsesTempDirectories;

    private const FIXTURE_DIR = __DIR__ . '/../fixtures/acf-json';

    /** ACF Proにだけある型 */
    private const PRO_TYPES = ['repeater', 'flexible_content', 'gallery', 'clone'];

    private int $postA = 0;

    private int $postB = 0;

    private int $term1 = 0;

    private int $term2 = 0;

    private int $user1 = 0;

    private int $user2 = 0;

    private int $image = 0;

    private int $file = 0;

    public function set_up(): void
    {
        parent::set_up();
        $this->snapshotAcfStores();

        $this->postA = self::createPost('post');
        $this->postB = self::createPost('post');
        $this->term1 = self::createTerm('ACF types first');
        $this->term2 = self::createTerm('ACF types second');
        $this->user1 = self::createUser();
        $this->user2 = self::createUser();
        $this->image = self::createAttachment('image/jpeg', 'tmswp/acf-types.jpg');
        $this->file = self::createAttachment('application/pdf', 'tmswp/acf-types.pdf');

        // oEmbedの取得は外部へ通信するため、ACFのoembedのフォーマットが使うWordPressの取得結果を固定する
        add_filter('pre_oembed_result', static fn(mixed $result, string $url): string => "<iframe src=\"{$url}\"></iframe>", 10, 2);
    }

    /**
     * ACFのstore(登録したローカルのフィールドとグループ、テストが捨てた値のキャッシュを含む)を、set_upの時点の
     * dataとaliasesに戻す。
     */
    public function tear_down(): void
    {
        // どれかが失敗しても、ほかの後始末を飛ばさない
        try {
            $this->restoreAcfStores();
        } finally {
            try {
                $this->cleanupTempDirectories();
            } finally {
                parent::tear_down();
            }
        }
    }

    /** 無料版のACFにある型(fixtureからProの型を除いたもの)。 */
    public function testFreeFieldTypesReturnTypedValues(): void
    {
        $class = $this->registerAndGenerate(self::withoutTypes($this->fixtureGroups(), self::PRO_TYPES));

        $this->assertCases($class, $this->cases(false));
        $this->assertUnsetValues($class, ['groupValue' => self::unsetGroupValue(false), 'emptyGroup' => []]);
    }

    /**
     * ACF Proの型(repeater・flexible_content・gallery。groupとrepeaterの中を含む)。cloneはAcfCloneIntegrationTestが
     * 確かめるため、fixtureから除く。
     *
     * @group acf-pro
     */
    public function testProFieldTypesReturnTypedValues(): void
    {
        self::assertTrue(acf_is_pro(), 'the acf-pro group runs only with ACF Pro (composer test:integration:acf-pro)');
        $class = $this->registerAndGenerate(self::withoutTypes($this->fixtureGroups(), ['clone']));

        $this->assertCases($class, $this->cases(true));
        $this->assertUnsetValues($class, ['groupValue' => self::unsetGroupValue(true), 'emptyGroup' => []]);
    }

    /**
     * 値の無いgroup_valueの期待値(サブフィールドごとの未設定の値)。
     *
     * @return array<string, mixed>
     */
    private static function unsetGroupValue(bool $pro): array
    {
        $common = [
            'title' => '',
            'choice' => null,
            'amount' => null,
            'photo_id' => null,
            'enabled' => false,
            'tags' => [],
            'post' => null,
            'posts' => [],
            'photo' => null,
            'attachment' => null,
            'link' => null,
            'choice_array' => null,
            'tags_array' => [],
            'author' => null,
            'authors' => [],
            'color' => null,
            'day' => '',
            'map' => false,
            'メモ' => '',
        ];

        return $pro ? self::withProSubFields($common, ['photo_ids' => [], 'photos' => [], 'items' => [], 'empty_blocks' => []]) : $common;
    }

    /**
     * group_valueの値$freeに、Proの型のサブフィールドの値$proを、生成するshapeと同じ並び(fixtureのJSONの順)で加える。
     *
     * @param array<string, mixed> $free
     * @param array{photo_ids: mixed, photos: mixed, items: mixed, empty_blocks: mixed} $pro
     *
     * @return array<string, mixed>
     */
    private static function withProSubFields(array $free, array $pro): array
    {
        $ordered = [];

        foreach ($free as $name => $value) {
            $ordered[$name] = $value;

            if ($name === 'tags') {
                $ordered['photo_ids'] = $pro['photo_ids'];
            } elseif ($name === 'attachment') {
                $ordered['photos'] = $pro['photos'];
            }
        }

        return [...$ordered, 'items' => $pro['items'], 'empty_blocks' => $pro['empty_blocks']];
    }

    /**
     * ACFの管理画面と同じ経路(update_field())で保存した値を生成getterが返し、生成setterで保存した値も同じ値で返す。
     * has*()とdelete*()は保存した値に従う。
     *
     * @param array<string, array{string, mixed, mixed, mixed}> $cases getter => [field key, update_field()の値, setterの値, 期待値]
     */
    private function assertCases(string $class, array $cases): void
    {
        $byAcf = self::createPost('post');
        $bySetter = self::createPost('post');

        // *AsDateTimeのgetterは元のフィールドと同じ値を読むため、フィールドごとに1回だけ保存する(同じ値の再保存はfalseになる)
        foreach (array_column($cases, 1, 0) as $fieldKey => $acfValue) {
            self::assertNotFalse(update_field($fieldKey, $acfValue, $byAcf), $fieldKey);
        }

        $acfFields = self::fieldsOf($class, $byAcf);
        $setterFields = self::fieldsOf($class, $bySetter);

        // *AsDateTimeのgetterは、元のフィールドのsetterで書く
        foreach ($cases as $getter => [, , $setterValue]) {
            self::call($setterFields, 'set' . ucfirst(self::fieldGetter($getter)), $setterValue);
        }

        // ACFの値のキャッシュを捨て、保存したメタから読み直させる
        acf_get_store('values')->reset();

        foreach ($cases as $getter => [$fieldKey, , , $expected]) {
            $expected = $expected instanceof Closure ? $expected($byAcf) : $expected;
            self::assertEquals($expected, self::call($acfFields, $getter), "{$getter}() of the value ACF saved");
            self::assertEquals($expected, self::call($setterFields, $getter), "{$getter}() of the value the setter saved");
            self::assertSame(get_field($fieldKey, $byAcf, false), get_field($fieldKey, $bySetter, false), "raw value of {$fieldKey}");
        }

        foreach (array_unique(array_map(self::fieldGetter(...), array_keys($cases))) as $getter) {
            $suffix = ucfirst($getter);
            self::assertTrue(self::call($acfFields, "has{$suffix}"), "has{$suffix}()");
            self::assertTrue(self::call($acfFields, "delete{$suffix}"), "delete{$suffix}()");
            self::assertFalse(self::call($acfFields, "has{$suffix}"), "has{$suffix}() after delete");
        }
    }

    /** getter$getterのフィールドのgetter(`*AsDateTime`は元のフィールドのもの)。 */
    private static function fieldGetter(string $getter): string
    {
        return preg_replace('/AsDateTime$/', '', $getter) ?? $getter;
    }

    /**
     * 値の無い投稿では、生成クラスのすべてのgetter(*AsDateTimeを含む)が、戻り値の型の未設定の値を返す(docs/09 §6.2)。
     * nullを許す型はnull、それ以外はstringが''、boolがfalse、arrayが[]。mixed(未知の型)はACFの値のまま。
     * 値を持つサブフィールドのあるgroupは、ACFがサブフィールドの未設定の値の配列を返すため、各サブフィールドの未設定の
     * 値の配列になる(docs/09 §6.5)。そのgroupのgetterは$groupsに期待値を渡す。
     *
     * @param class-string $class
     * @param array<string, array<string, mixed>> $groups groupのgetter => 期待値
     */
    private function assertUnsetValues(string $class, array $groups): void
    {
        $fields = self::fieldsOf($class, self::createPost('post'));
        $checked = 0;
        $trait = new ReflectionClass(AcfFieldAccess::class);

        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            // traitのエスケープハッチ(value()等)とファクトリを除く、生成したgetterだけを見る
            if ($method->isStatic() || $trait->hasMethod($method->getName()) || preg_match('/^(set|has|delete)[A-Z]/', $method->getName()) === 1) {
                continue;
            }

            $type = $method->getReturnType();
            $expected = $groups[$method->getName()] ?? match (true) {
                // 未知の型(W005。google_map)はACFの値をそのまま返す。google_mapの値が無いときのACFの値はfalse
                $type instanceof ReflectionNamedType && $type->getName() === 'mixed' => false,
                $type === null || $type->allowsNull() => null,
                $type instanceof ReflectionNamedType && $type->getName() === 'string' => '',
                $type instanceof ReflectionNamedType && $type->getName() === 'bool' => false,
                $type instanceof ReflectionNamedType && $type->getName() === 'array' => [],
                default => self::fail("{$method->getName()}() has an unexpected return type " . (string) $type),
            };
            self::assertSame($expected, self::call($fields, $method->getName()), "{$method->getName()}() without a value");
            ++$checked;
        }

        self::assertGreaterThan(0, $checked);
    }

    /**
     * getter => [field key, update_field()の値, setterの値, 期待値(Closureなら投稿のIDから求める)]。$proなら、Proの型
     * (repeater・flexible_content・gallery。groupの中を含む)を加える。
     *
     * @return array<string, array{string, mixed, mixed, mixed}>
     */
    private function cases(bool $pro): array
    {
        $link = ['title' => 'Example', 'url' => 'https://example.org/', 'target' => '_blank'];
        $map = ['address' => 'Tokyo', 'lat' => 35.68, 'lng' => 139.76, 'zoom' => 14];
        $red = ['value' => 'red', 'label' => 'Red'];
        $blue = ['value' => 'blue', 'label' => 'Blue'];
        $posts = [get_post($this->postA), get_post($this->postB)];
        $terms = [get_term($this->term1), get_term($this->term2)];
        $users = [get_user_by('id', $this->user1), get_user_by('id', $this->user2)];
        $imageUrl = wp_get_attachment_url($this->image);
        $fileUrl = wp_get_attachment_url($this->file);
        $formatted = static fn(string $fieldKey): Closure => static fn(int $postId): mixed => get_field($fieldKey, $postId);
        $group = [
            'field_t_group_title' => 'G',
            'field_t_group_choice' => 'blue',
            'field_t_group_amount' => '3.5',
            'field_t_group_photo_id' => $this->image,
            'field_t_group_enabled' => 1,
            'field_t_group_tags' => ['red'],
            'field_t_group_post' => $this->postA,
            'field_t_group_posts' => [$this->postB],
            'field_t_group_photo' => $this->image,
            'field_t_group_attachment' => $this->file,
            'field_t_group_link' => $link,
            'field_t_group_choice_array' => 'red',
            'field_t_group_tags_array' => ['blue'],
            'field_t_group_author' => $this->user1,
            'field_t_group_authors' => [$this->user1, $this->user2],
            // alphaだけが0でない色は、未設定にしない(rgba(0,0,0,0)だけを未設定にする)
            'field_t_group_color' => 'rgba(0,0,0,0.5)',
            'field_t_group_day' => '20261027',
            'field_t_group_map' => $map,
            'field_t_group_メモ' => 'メモ',
        ];
        $groupValue = fn(int $postId): array => [
            'title' => 'G',
            'choice' => 'blue',
            'amount' => 3.5,
            'photo_id' => $this->image,
            'enabled' => true,
            'tags' => ['red'],
            'post' => $posts[0],
            'posts' => [$posts[1]],
            'photo' => self::subValue('field_t_group_value', $postId, 'photo'),
            'attachment' => self::subValue('field_t_group_value', $postId, 'attachment'),
            'link' => $link,
            'choice_array' => $red,
            'tags_array' => [$blue],
            'author' => self::subValue('field_t_group_value', $postId, 'author'),
            'authors' => self::subValue('field_t_group_value', $postId, 'authors'),
            'color' => ['red' => 0, 'green' => 0, 'blue' => 0, 'alpha' => 0.5],
            'day' => '27/10/2026',
            'map' => $map,
            'メモ' => 'メモ',
        ];

        if ($pro) {
            $group += [
                'field_t_group_photo_ids' => [$this->image],
                'field_t_group_photos' => [$this->image],
                'field_t_group_items' => [['field_t_group_items_label' => 'L', 'field_t_group_items_term' => $this->term1]],
            ];
            $freeGroupValue = $groupValue;
            $groupValue = fn(int $postId): array => self::withProSubFields($freeGroupValue($postId), [
                'photo_ids' => [$this->image],
                'photos' => self::subValue('field_t_group_value', $postId, 'photos'),
                'items' => [['label' => 'L', 'term' => $terms[0]]],
                'empty_blocks' => [],
            ]);
        }

        $cases = [
            // group_product
            'price' => ['field_price', '1500', 1500, 1500],
            'mainVisual' => ['field_visual', $this->image, $this->image, $formatted('field_visual')],
            'publishedAt' => ['field_published', '2026-10-26 09:30:00', '2026-10-26 09:30:00', '26/10/2026 9:30 am'],
            'publishedAtAsDateTime' => ['field_published', '2026-10-26 09:30:00', '2026-10-26 09:30:00', new DateTimeImmutable('2026-10-26 09:30:00', wp_timezone())],
            'storeName' => ['field_store_name', '本店', '本店', '本店'],
            // group_product_types
            'textValue' => ['field_t_text_value', 'テキスト', 'テキスト', 'テキスト'],
            'textareaValue' => ['field_t_textarea_value', "1行目\n2行目", "1行目\n2行目", "1行目\n2行目"],
            'emailValue' => ['field_t_email_value', 'info@example.org', 'info@example.org', 'info@example.org'],
            'urlValue' => ['field_t_url_value', 'https://example.org/page', 'https://example.org/page', 'https://example.org/page'],
            'passwordValue' => ['field_t_password_value', 'secret', 'secret', 'secret'],
            'wysiwygValue' => ['field_t_wysiwyg_value', 'Hello', 'Hello', "<p>Hello</p>\n"],
            'oembedValue' => ['field_t_oembed_value', 'https://example.org/video', 'https://example.org/video', '<iframe src="https://example.org/video"></iframe>'],
            'rangeValue' => ['field_t_range_value', '7', 7, 7],
            'trueFalseValue' => ['field_t_true_false_value', 1, true, true],
            'selectValue' => ['field_t_select_value', 'blue', 'blue', 'blue'],
            'selectLabel' => ['field_t_select_label', 'blue', 'blue', 'Blue'],
            'selectArray' => ['field_t_select_array', 'blue', 'blue', $blue],
            'selectMultipleValue' => ['field_t_select_multiple_value', ['red', 'blue'], ['red', 'blue'], ['red', 'blue']],
            'selectMultipleLabel' => ['field_t_select_multiple_label', ['red', 'blue'], ['red', 'blue'], ['Red', 'Blue']],
            'selectMultipleArray' => ['field_t_select_multiple_array', ['red', 'blue'], ['red', 'blue'], [$red, $blue]],
            'checkboxValue' => ['field_t_checkbox_value', ['red', 'blue'], ['red', 'blue'], ['red', 'blue']],
            'checkboxLabel' => ['field_t_checkbox_label', ['red', 'blue'], ['red', 'blue'], ['Red', 'Blue']],
            'checkboxArray' => ['field_t_checkbox_array', ['red', 'blue'], ['red', 'blue'], [$red, $blue]],
            'radioValue' => ['field_t_radio_value', 'blue', 'blue', 'blue'],
            'radioLabel' => ['field_t_radio_label', 'blue', 'blue', 'Blue'],
            'radioArray' => ['field_t_radio_array', 'blue', 'blue', $blue],
            'buttonGroupValue' => ['field_t_button_group_value', 'blue', 'blue', 'blue'],
            'buttonGroupLabel' => ['field_t_button_group_label', 'blue', 'blue', 'Blue'],
            'buttonGroupArray' => ['field_t_button_group_array', 'blue', 'blue', $blue],
            'imageId' => ['field_t_image_id', $this->image, $this->image, $this->image],
            'imageUrl' => ['field_t_image_url', $this->image, $this->image, $imageUrl],
            'fileArray' => ['field_t_file_array', $this->file, $this->file, $formatted('field_t_file_array')],
            'fileId' => ['field_t_file_id', $this->file, $this->file, $this->file],
            'fileUrl' => ['field_t_file_url', $this->file, $this->file, $fileUrl],
            'linkArray' => ['field_t_link_array', $link, $link, $link],
            'linkUrl' => ['field_t_link_url', $link, $link, 'https://example.org/'],
            'postObjectObject' => ['field_t_post_object_object', $this->postA, $this->postA, $posts[0]],
            'postObjectId' => ['field_t_post_object_id', $this->postA, $this->postA, $this->postA],
            'postObjectMultipleObject' => ['field_t_post_object_multiple_object', [$this->postA, $this->postB], [$this->postA, $this->postB], $posts],
            'postObjectMultipleId' => ['field_t_post_object_multiple_id', [$this->postA, $this->postB], [$this->postA, $this->postB], [$this->postA, $this->postB]],
            'pageLinkSingle' => ['field_t_page_link_single', $this->postA, $this->postA, get_permalink($this->postA)],
            'pageLinkMultiple' => ['field_t_page_link_multiple', [$this->postA, $this->postB], [$this->postA, $this->postB], [get_permalink($this->postA), get_permalink($this->postB)]],
            'relationshipObject' => ['field_t_relationship_object', [$this->postA, $this->postB], [$this->postA, $this->postB], $posts],
            'relationshipId' => ['field_t_relationship_id', [$this->postA, $this->postB], [$this->postA, $this->postB], [$this->postA, $this->postB]],
            'taxonomySelectObject' => ['field_t_taxonomy_select_object', $this->term1, $this->term1, $terms[0]],
            'taxonomySelectId' => ['field_t_taxonomy_select_id', $this->term1, $this->term1, $this->term1],
            'taxonomyRadioObject' => ['field_t_taxonomy_radio_object', $this->term1, $this->term1, $terms[0]],
            'taxonomyRadioId' => ['field_t_taxonomy_radio_id', $this->term1, $this->term1, $this->term1],
            'taxonomyCheckboxObject' => ['field_t_taxonomy_checkbox_object', [$this->term1, $this->term2], [$this->term1, $this->term2], $terms],
            'taxonomyCheckboxId' => ['field_t_taxonomy_checkbox_id', [$this->term1, $this->term2], [$this->term1, $this->term2], [$this->term1, $this->term2]],
            'taxonomyMultiSelectObject' => ['field_t_taxonomy_multi_select_object', [$this->term1, $this->term2], [$this->term1, $this->term2], $terms],
            'taxonomyMultiSelectId' => ['field_t_taxonomy_multi_select_id', [$this->term1, $this->term2], [$this->term1, $this->term2], [$this->term1, $this->term2]],
            'userArray' => ['field_t_user_array', $this->user1, $this->user1, $formatted('field_t_user_array')],
            'userObject' => ['field_t_user_object', $this->user1, $this->user1, $users[0]],
            'userId' => ['field_t_user_id', $this->user1, $this->user1, $this->user1],
            'userMultipleArray' => ['field_t_user_multiple_array', [$this->user1, $this->user2], [$this->user1, $this->user2], $formatted('field_t_user_multiple_array')],
            'userMultipleObject' => ['field_t_user_multiple_object', [$this->user1, $this->user2], [$this->user1, $this->user2], $users],
            'userMultipleId' => ['field_t_user_multiple_id', [$this->user1, $this->user2], [$this->user1, $this->user2], [$this->user1, $this->user2]],
            'datePickerValue' => ['field_t_date_picker_value', '20261026', '20261026', '2026/10/26'],
            'datePickerValueAsDateTime' => ['field_t_date_picker_value', '20261026', new DateTimeImmutable('2026-10-26 15:00:00', wp_timezone()), new DateTimeImmutable('2026-10-26 00:00:00', wp_timezone())],
            'timePickerValue' => ['field_t_time_picker_value', '13:45:00', '13:45:00', '13:45'],
            'colorString' => ['field_t_color_string', '#ff0000', '#ff0000', '#ff0000'],
            'colorArray' => ['field_t_color_array', 'rgba(255,0,0,0.5)', 'rgba(255,0,0,0.5)', ['red' => 255, 'green' => 0, 'blue' => 0, 'alpha' => 0.5]],
            'mapValue' => ['field_t_map_value', $map, $map, $map],
            'groupValue' => ['field_t_group_value', $group, $group, $groupValue],
        ];

        return $pro ? [...$cases, ...$this->proCases($formatted)] : $cases;
    }

    /**
     * Proの型(repeater・flexible_content・gallery)のトップレベルのフィールドのケース。cases()と同じ形。
     *
     * @param Closure(string): (Closure(int): mixed) $formatted field keyから、ACFがフォーマットした値を求める関数を作る
     *
     * @return array<string, array{string, mixed, mixed, mixed}>
     */
    private function proCases(Closure $formatted): array
    {
        $link = ['title' => 'Example', 'url' => 'https://example.org/', 'target' => '_blank'];
        $row = [
            'field_t_rows_title' => 'R',
            'field_t_rows_amount' => '2',
            'field_t_rows_tags' => ['blue'],
            'field_t_rows_photo_ids' => [$this->image],
            'field_t_rows_detail' => ['field_t_rows_detail_summary' => 'S', 'field_t_rows_detail_user' => $this->user1],
            'field_t_rows_blocks' => [['acf_fc_layout' => 'quote', 'field_t_rows_quote_text' => 'Q']],
        ];
        $sections = [
            ['acf_fc_layout' => 'hero', 'field_t_hero_title' => 'H', 'field_t_hero_image' => $this->image, 'field_t_hero_buttons' => [['field_t_hero_buttons_link' => $link]]],
            ['acf_fc_layout' => 'text_block', 'field_t_text_block_body' => 'B'],
            ['acf_fc_layout' => 'divider'],
        ];

        return [
            'stores' => ['field_stores', [['field_shop' => '本店', 'field_tel' => '03-0000-0000']], [['field_shop' => '本店', 'field_tel' => '03-0000-0000']], [['shop_name' => '本店', 'tel' => '03-0000-0000']]],
            'galleryArray' => ['field_t_gallery_array', [$this->image], [$this->image], $formatted('field_t_gallery_array')],
            'galleryId' => ['field_t_gallery_id', [$this->image], [$this->image], [$this->image]],
            'galleryUrl' => ['field_t_gallery_url', [$this->image], [$this->image], [wp_get_attachment_url($this->image)]],
            // 値を渡さなかったサブフィールドは、サブフィールドの型の未設定の値になる
            'rowsValue' => ['field_t_rows_value', [$row], [$row], [[
                'title' => 'R',
                'choice' => null,
                'amount' => 2,
                'photo_id' => null,
                'enabled' => false,
                'tags' => ['blue'],
                'photo_ids' => [$this->image],
                'post' => null,
                'posts' => [],
                'photo' => null,
                'attachment' => null,
                'photos' => [],
                'link' => null,
                'choice_array' => null,
                'tags_array' => [],
                'author' => null,
                'authors' => [],
                'color' => null,
                'day' => '',
                'map' => false,
                'メモ' => '',
                'detail' => ['summary' => 'S', 'user' => get_user_by('id', $this->user1)],
                'blocks' => [['acf_fc_layout' => 'quote', 'text' => 'Q']],
            ]]],
            'sections' => ['field_t_sections', $sections, $sections, [
                ['acf_fc_layout' => 'hero', 'title' => 'H', 'image' => $this->image, 'buttons' => [['link' => $link]]],
                ['acf_fc_layout' => 'text_block', 'body' => "<p>B</p>\n"],
                ['acf_fc_layout' => 'divider'],
            ]],
        ];
    }

    /** groupのフィールド$fieldKeyの、ACFがフォーマットした値のサブフィールド$name。 */
    private static function subValue(string $fieldKey, int $postId, string $name): mixed
    {
        $group = get_field($fieldKey, $postId);
        self::assertIsArray($group);
        self::assertArrayHasKey($name, $group);

        return $group[$name];
    }

    /**
     * fixtureのgroup_productとgroup_product_typesのJSON。locationはどちらもpost_type product。
     *
     * @return list<array<string, mixed>>
     */
    private function fixtureGroups(): array
    {
        $groups = [];

        foreach (['group_product', 'group_product_types'] as $name) {
            $json = file_get_contents(self::FIXTURE_DIR . "/{$name}.json");
            self::assertIsString($json);
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            $group = [];

            foreach ($decoded as $key => $value) {
                if (!\is_string($key)) {
                    self::fail("{$name}.json must be a JSON object with string keys");
                }

                $group[$key] = $value;
            }

            $groups[] = $group;
        }

        return $groups;
    }

    /**
     * $groupsから、型が$typesのフィールドを全階層(サブフィールドとflexible_contentのレイアウトの中)で除いたもの。
     *
     * @param list<array<string, mixed>> $groups
     * @param list<string> $types
     *
     * @return list<array<string, mixed>>
     */
    private static function withoutTypes(array $groups, array $types): array
    {
        $strip = static function (mixed $fields) use (&$strip, $types): array {
            self::assertIsArray($fields);
            $kept = [];

            foreach ($fields as $field) {
                self::assertIsArray($field);

                if (\in_array($field['type'] ?? null, $types, true)) {
                    continue;
                }

                if (\array_key_exists('sub_fields', $field)) {
                    $field['sub_fields'] = $strip($field['sub_fields']);
                }

                if (\is_array($field['layouts'] ?? null)) {
                    $field['layouts'] = array_map(static function (mixed $layout) use ($strip): array {
                        self::assertIsArray($layout);

                        return ['sub_fields' => $strip($layout['sub_fields'] ?? [])] + $layout;
                    }, $field['layouts']);
                }

                $kept[] = $field;
            }

            return $kept;
        };

        return array_map(static fn(array $group): array => ['fields' => $strip($group['fields'] ?? null)] + $group, $groups);
    }

    /**
     * $groupsをACFのローカルのフィールドグループとして登録し、Generatorで生成したpost_type productのクラスを読み込んで、
     * そのクラス名を返す。groupのgroup_productの日本語の名前のフィールドは、methodNameMapでstoreName()にする。
     *
     * @param list<array<string, mixed>> $groups
     *
     * @return class-string
     */
    private function registerAndGenerate(array $groups): string
    {
        $fieldGroups = [];

        foreach ($groups as $group) {
            self::assertIsString($group['key'] ?? null);
            self::assertTrue(acf_add_local_field_group($group));
            $fieldGroups[] = FieldGroup::fromArray($group, $group['key'] . '.json');
        }

        $namespace = self::THEME_NAMESPACE . '\\AcfTypesProbe' . uniqid();
        $outputDir = $this->createTempDirectory('acf-types');
        $report = (new Generator())->generate($fieldGroups, new GeneratorContext(
            config: new BridgeConfig(jsonDirs: [], outputDir: $outputDir, namespace: $namespace, methodNameMap: ['group_product.店舗名' => 'storeName']),
            optionsPagePostIds: [],
            sourceHash: 'types',
        ));
        self::assertSame([], $report->errors());
        $files = array_values(array_filter($report->files(), static fn(GeneratedFile $file): bool => $file->relativePath === 'ProductFields.php'));
        self::assertCount(1, $files);
        self::assertNotFalse(file_put_contents("{$outputDir}/ProductFields.php", $files[0]->contents));
        require "{$outputDir}/ProductFields.php";
        $class = "{$namespace}\\ProductFields";

        if (!class_exists($class, false)) {
            self::fail("the generated class {$class} was not declared");
        }

        return $class;
    }

    /** 生成クラス$classのof($postId)を呼び、そのインスタンスを返す(生成クラスはPHPStanの解析対象外)。 */
    private static function fieldsOf(string $class, int $postId): object
    {
        $fields = self::call($class, 'of', $postId);
        self::assertIsObject($fields);

        return $fields;
    }

    /** 生成クラス(PHPStanの解析対象外)のメソッドを呼ぶ。 */
    private static function call(object|string $target, string $method, mixed ...$arguments): mixed
    {
        $callable = [$target, $method];
        self::assertIsCallable($callable);

        return $callable(...$arguments);
    }

    private static function createTerm(string $name): int
    {
        $term = wp_insert_term($name, 'category');
        self::assertIsArray($term);

        return (int) $term['term_id'];
    }

    private static function createUser(): int
    {
        $userId = self::factory()->user->create();
        self::assertIsInt($userId);

        return $userId;
    }

    /** アップロードのディレクトリの中のファイル$relativePath(実体は無い)を指す添付ファイルを作り、そのIDを返す。 */
    private static function createAttachment(string $mimeType, string $relativePath): int
    {
        $attachmentId = wp_insert_attachment(['post_mime_type' => $mimeType, 'post_title' => basename($relativePath), 'post_status' => 'inherit'], $relativePath);
        self::assertIsInt($attachmentId);
        self::assertGreaterThan(0, $attachmentId);

        return $attachmentId;
    }
}
