<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Tests\Unit\Generate;

use App\Acf\GenreTermFields;
use App\Acf\ProductFields;
use App\Acf\UserFields;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use TMSWP\AcfBridge\AcfFieldAccess;
use TMSWP\AcfBridge\BridgeConfig;
use TMSWP\AcfBridge\Generate\GeneratedFile;
use TMSWP\AcfBridge\Generate\Generator;
use TMSWP\AcfBridge\Generate\GeneratorContext;
use TMSWP\AcfBridge\Generate\Report;
use TMSWP\AcfBridge\Json\AcfJsonRepository;
use TMSWP\AcfBridge\Json\FieldGroup;
use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Testing\UnitTestCase;

/**
 * Generatorの生成物を確かめる。fixtures/acf-jsonは、docs/09 §6.2の表の全行(値を持たないmessage・tab・accordionと、
 * 未知の型を含む)と、group・repeater・flexible_contentの入れ子を持ち、その生成物(fixtures/expected-app)はPHPStan
 * (level max)の解析対象である。
 */
final class GeneratorTest extends UnitTestCase
{
    private const EXPECTED_APP = __DIR__ . '/../../fixtures/expected-app/Acf';

    private const EXPECTED_IMPORTS = __DIR__ . '/../../fixtures/expected-imports';

    public function testFixtureSnapshotMatchesExpectedApp(): void
    {
        $report = $this->generateFixture();

        self::assertSame([], $report->errors());
        self::assertSame([
            "W005: unknown ACF field type 'google_map' for 'extra' was generated as mixed",
            "W005: unknown ACF field type 'google_map' for 'map_value' was generated as mixed",
            "W005: unknown ACF field type 'google_map' for 'group_value.map' was generated as mixed",
            "W005: unknown ACF field type 'google_map' for 'rows_value.map' was generated as mixed",
            "W005: unknown ACF field type 'google_map' for 'cloned_extra' was generated as mixed",
            "W005: unknown ACF field type 'google_map' for 'cloned_group.extra' was generated as mixed",
        ], $report->warnings());
        self::assertSame($this->expectedFixtureFiles(), $this->filesByPath($report->files()));
    }

    /**
     * 生成したアクセサ(getter・setter・*AsDateTime・has*・delete*)は、ACFをfield key(KEY_定数)で呼び、名前の定数を
     * ヘルパに渡さない。名前で呼ぶのはtraitの公開のエスケープハッチ(value()等)だけである。
     */
    public function testGeneratedAccessorsCallAcfByFieldKey(): void
    {
        foreach ($this->filesByPath($this->generateFixture()->files()) as $path => $contents) {
            if ($path === 'AcfManifest.php') {
                continue;
            }

            self::assertGreaterThan(0, preg_match_all('/\$this->acf\w+\(self::/', $contents), $path);
            self::assertSame(0, preg_match('/\$this->acf\w+\(self::(?!KEY_)/', $contents), $path);
            self::assertSame(0, preg_match('/\$this->(value|rawValue|update|delete|has)\(/', $contents), $path);
        }

        $product = $this->filesByPath($this->generateFixture()->files())['ProductFields.php'];
        self::assertStringContainsString('return $this->acfNumber(self::KEY_PRICE);', $product);
        self::assertStringContainsString('$this->acfUpdate(self::KEY_PRICE, $value);', $product);
        self::assertStringContainsString('return $this->acfHas(self::KEY_PRICE);', $product);
        self::assertStringContainsString('return $this->acfDelete(self::KEY_PRICE);', $product);
        self::assertStringContainsString("\$this->acfUpdateDate(self::KEY_PUBLISHED_AT, \$value, 'Y-m-d H:i:s');", $product);
        self::assertStringContainsString("return \$this->acfDateTime(self::KEY_PUBLISHED_AT, 'Y-m-d H:i:s');", $product);
    }

    /**
     * group・repeater・flexible_contentのgetterは、サブフィールドごとに値を受け取るヘルパ(`*Of()`)を呼ぶ関数を生成し、
     * 入れ子を全階層で正規化する。位置は`$path . '.名前'`で渡す。flexible_contentのレイアウトはJSONの順に並べる。
     */
    public function testNestedFieldsAreNormalizedPerSubField(): void
    {
        $product = $this->filesByPath($this->generateFixture()->files())['ProductFields.php'];

        self::assertStringContainsString(<<<'PHP'
                    return $this->acfRows(self::KEY_STORES, fn(array $row, string $path): array => [
                        'shop_name' => $this->acfStringOf($row['shop_name'] ?? null, $path . '.shop_name'),
                        'tel' => $this->acfStringOf($row['tel'] ?? null, $path . '.tel'),
                    ]);
            PHP, $product);
        self::assertStringContainsString(<<<'PHP'
                        'items' => $this->acfRowsOf($group['items'] ?? null, $path . '.items', fn(array $row, string $path): array => [
                            'label' => $this->acfStringOf($row['label'] ?? null, $path . '.label'),
                            'term' => $this->acfObjectOf($row['term'] ?? null, $path . '.term', WP_Term::class),
                        ]),
            PHP, $product);
        self::assertStringContainsString(<<<'PHP'
                    return $this->acfLayouts(self::KEY_SECTIONS, [
                        'hero' => fn(array $row, string $path): array => [
                            'acf_fc_layout' => 'hero',
                            'title' => $this->acfStringOf($row['title'] ?? null, $path . '.title'),
                            'image' => $this->acfIntOf($row['image'] ?? null, $path . '.image'),
                            'buttons' => $this->acfRowsOf($row['buttons'] ?? null, $path . '.buttons', fn(array $row, string $path): array => [
                                'link' => $this->acfLinkOf($row['link'] ?? null, $path . '.link'),
                            ]),
                        ],
                        'text_block' => fn(array $row, string $path): array => [
                            'acf_fc_layout' => 'text_block',
                            'body' => $this->acfStringOf($row['body'] ?? null, $path . '.body'),
                        ],
                        'divider' => fn(array $row, string $path): array => [
                            'acf_fc_layout' => 'divider',
                        ],
                    ]);
            PHP, $product);
        self::assertStringContainsString("'メモ' => \$this->acfStringOf(\$row['メモ'] ?? null, \$path . '.メモ'),", $product);
        self::assertStringContainsString("'map' => \$row['map'] ?? null,", $product);
        self::assertStringContainsString('return $this->acfGroup(self::KEY_EMPTY_GROUP, fn(array $group, string $path): array => []);', $product);
    }

    /**
     * seamlessのcloneがprefix_nameで保存名を変えたフィールドは、KEY_定数に元のkey、名前の定数に保存名を持ち、getter・
     * setter・*AsDateTime・has・deleteは、元のkeyの定義を保存名で読み書きするヘルパ(acfCloned*())を使う。getterは
     * 保存名で読んだ値を*Of()のヘルパで検査する。prefix_labelはPHPDocのラベルに付く。groupのcloneは、cloneのkeyで
     * acfGroup()を使う(ACFが値を元の名前の配列で返す)。fixtureのcloned・cloned_group。
     */
    public function testClonedFieldsWithSavedNamesUseTheClonedHelpers(): void
    {
        $product = $this->filesByPath($this->generateFixture()->files())['ProductFields.php'];

        self::assertStringContainsString("    /** 複製 見出し (text, group_product_types) */\n    public const CLONED_HEADLINE = 'cloned_headline';\n    public const KEY_CLONED_HEADLINE = 'field_cs_headline';\n", $product);
        self::assertStringContainsString('return $this->acfStringOf($this->acfClonedValue(self::KEY_CLONED_HEADLINE, self::CLONED_HEADLINE), self::CLONED_HEADLINE);', $product);
        self::assertStringContainsString('$this->acfClonedUpdate(self::KEY_CLONED_HEADLINE, self::CLONED_HEADLINE, $value);', $product);
        self::assertStringContainsString('return $this->acfClonedHas(self::KEY_CLONED_HEADLINE, self::CLONED_HEADLINE);', $product);
        self::assertStringContainsString('return $this->acfClonedDelete(self::KEY_CLONED_HEADLINE, self::CLONED_HEADLINE);', $product);
        self::assertStringContainsString("\$this->acfClonedUpdateDate(self::KEY_CLONED_STARTS_ON, self::CLONED_STARTS_ON, \$value, 'Ymd');", $product);
        self::assertStringContainsString("return \$this->acfClonedDateTime(self::KEY_CLONED_STARTS_ON, self::CLONED_STARTS_ON, 'Ymd');", $product);
        self::assertStringContainsString('return $this->acfObjectOf($this->acfClonedValue(self::KEY_CLONED_RELATED, self::CLONED_RELATED), self::CLONED_RELATED, WP_Post::class);', $product);
        self::assertStringContainsString('return $this->acfClonedValue(self::KEY_CLONED_EXTRA, self::CLONED_EXTRA);', $product);
        self::assertStringContainsString('return $this->acfRowsOf($this->acfClonedValue(self::KEY_CLONED_LINKS, self::CLONED_LINKS), self::CLONED_LINKS, fn(array $row, string $path): array => [', $product);
        self::assertStringContainsString("public const KEY_CLONED_GROUP = 'field_t_cloned_group';", $product);
        self::assertStringContainsString('return $this->acfGroup(self::KEY_CLONED_GROUP, fn(array $group, string $path): array => [', $product);
        self::assertStringContainsString("'headline' => \$this->acfStringOf(\$group['headline'] ?? null, \$path . '.headline'),", $product);
        self::assertStringNotContainsString('field_t_cloned_field_cs_', $product);
    }

    /** 値を持たない型(message・tab・accordion)のフィールドとサブフィールドは、メンバーもshapeのキーも生成しない。 */
    public function testValuelessFieldsGenerateNoMembers(): void
    {
        $product = $this->filesByPath($this->generateFixture()->files())['ProductFields.php'];

        foreach (['message_note', 'tab_basic', 'accordion_more', 'MESSAGE_NOTE', 'TAB_BASIC', 'ACCORDION_MORE', 'messageNote', 'tabBasic', 'accordionMore', "'note'"] as $name) {
            self::assertStringNotContainsString($name, $product, $name);
        }
    }

    /**
     * ヘッダは、見出しの「次回syncで」と再生成のコマンドを1つの空白で書く。集約ハッシュ(source-hash)は、AcfManifestの
     * ヘッダの1か所だけに`source-hash: sha256:...`の形で出す(docs/09 §5.2・§7.1)。
     */
    public function testHeadersFollowTheCanonicalForm(): void
    {
        $files = $this->filesByPath($this->generateFixture()->files());

        foreach ($files as $path => $contents) {
            self::assertStringContainsString(" * このファイルは自動生成。手で編集しないこと(次回syncで上書きされる)。\n * 再生成: composer acf:sync (vendor/bin/tms-acf sync)\n", $contents, $path);
            self::assertStringNotContainsString('  (', $contents, $path);
        }

        $manifest = $files['AcfManifest.php'];
        self::assertSame(1, substr_count($manifest, 'source-hash'));
        self::assertMatchesRegularExpression('/^ \* @generated by tmswp\/acf-bridge\n \* source-hash: sha256:[0-9a-f]{64}\n/m', $manifest);
        self::assertStringContainsString(" * sources:\n *   group_clone_source.json (cloneの参照元)\n *   group_genre.json (ジャンル)\n *   group_options.json (サイト設定)\n *   group_product.json (製品情報)\n *   group_product_types.json (製品の全型)\n *   group_user.json (ユーザー)\n */", $manifest);

        foreach (array_diff_key($files, ['AcfManifest.php' => true]) as $path => $contents) {
            self::assertStringNotContainsString('source-hash', $contents, $path);
        }
    }

    public function testItGeneratesDeterministicAccessorClassesAndManifest(): void
    {
        $context = $this->context(['group_product.店舗名' => 'storeName'], ['theme-settings' => 'theme_settings']);
        $groups = [
            $this->group('group_product', 'product', [
                ['key' => 'field_price', 'name' => 'price', 'label' => '価格', 'type' => 'number'],
                ['key' => 'field_store_name', 'name' => '店舗名', 'label' => '店舗名', 'type' => 'text'],
            ]),
            FieldGroup::fromArray([
                'key' => 'group_options',
                'title' => 'サイト設定',
                'location' => [[['param' => 'options_page', 'operator' => '==', 'value' => 'theme-settings']]],
                'fields' => [
                    ['key' => 'field_logo', 'name' => 'site_logo', 'label' => 'ロゴ', 'type' => 'image', 'return_format' => 'id'],
                ],
            ], 'acf-json/group_options.json'),
            FieldGroup::fromArray([
                'key' => 'group_inactive',
                'title' => '無効',
                'active' => false,
                'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]],
                'fields' => [
                    ['key' => 'field_ignored', 'name' => 'ignored', 'label' => '無視', 'type' => 'text'],
                ],
            ], 'acf-json/group_inactive.json'),
        ];

        $report = (new Generator())->generate($groups, $context);
        $secondReport = (new Generator())->generate($groups, $context);

        self::assertSame([], $report->errors());
        self::assertSame(["W011: inactive ACF field group 'group_inactive' was skipped"], $report->warnings());
        self::assertSame($this->filesByPath($report->files()), $this->filesByPath($secondReport->files()));

        $files = $this->filesByPath($report->files());
        self::assertSame(['AcfManifest.php', 'ProductFields.php', 'ThemeSettingsOptions.php'], array_keys($files));
        self::assertStringContainsString("public const STORE_NAME = '店舗名';", $files['ProductFields.php']);
        self::assertStringContainsString('public function storeName(): string', $files['ProductFields.php']);
        self::assertStringContainsString("return new self('theme_settings');", $files['ThemeSettingsOptions.php']);
        self::assertStringContainsString('source-hash: sha256:abc123', $files['AcfManifest.php']);
        self::assertStringContainsString("new ManagedKey(ObjectType::Post, 'product', 'price', 'field_price', 'group_product')", $files['AcfManifest.php']);
        self::assertStringContainsString("new ManagedKey(null, 'theme-settings', 'site_logo', 'field_logo', 'group_options')", $files['AcfManifest.php']);
        self::assertStringNotContainsString('group_inactive', $files['AcfManifest.php']);
    }

    /**
     * objectとobjectのlist(relationship・post_object・taxonomy・userで、return_format=object)のgetterは、要素のクラスを
     * 渡してacfObject()・acfObjectList()を呼ぶ(要素ごとにinstanceofを検査する)。IDのlistはacfIntList()のまま。
     */
    public function testObjectGettersPassTheElementClass(): void
    {
        $report = (new Generator())->generate([$this->group('group_links', 'product', [
            ['key' => 'field_related', 'name' => 'related', 'label' => 'Related', 'type' => 'relationship', 'return_format' => 'object'],
            ['key' => 'field_posts', 'name' => 'posts', 'label' => 'Posts', 'type' => 'post_object', 'multiple' => 1, 'return_format' => 'object'],
            ['key' => 'field_terms', 'name' => 'terms', 'label' => 'Terms', 'type' => 'taxonomy', 'field_type' => 'checkbox', 'return_format' => 'object'],
            ['key' => 'field_users', 'name' => 'users', 'label' => 'Users', 'type' => 'user', 'multiple' => 1, 'return_format' => 'object'],
            ['key' => 'field_post', 'name' => 'post', 'label' => 'Post', 'type' => 'post_object', 'return_format' => 'object'],
            ['key' => 'field_ids', 'name' => 'ids', 'label' => 'IDs', 'type' => 'relationship', 'return_format' => 'id'],
        ])], $this->context());

        self::assertSame([], $report->errors());
        $product = $this->filesByPath($report->files())['ProductFields.php'];
        self::assertStringContainsString('return $this->acfObjectList(self::KEY_RELATED, WP_Post::class);', $product);
        self::assertStringContainsString('return $this->acfObjectList(self::KEY_POSTS, WP_Post::class);', $product);
        self::assertStringContainsString('return $this->acfObjectList(self::KEY_TERMS, WP_Term::class);', $product);
        self::assertStringContainsString('return $this->acfObjectList(self::KEY_USERS, WP_User::class);', $product);
        self::assertStringContainsString('public function post(): ?WP_Post', $product);
        self::assertStringContainsString('return $this->acfObject(self::KEY_POST, WP_Post::class);', $product);
        self::assertStringContainsString('return $this->acfIntList(self::KEY_IDS);', $product);
        self::assertStringContainsString("use WP_Post;\nuse WP_Term;\nuse WP_User;\n", $product);
    }

    /**
     * cloneは参照を解決できないとE004になる(参照の解決と合成keyはv0.6.0-T4の担当)。
     */
    public function testCloneThatCannotBeResolvedIsE004(): void
    {
        $report = (new Generator())->generate([$this->group('group_clone', 'product', [
            ['key' => 'field_clone', 'name' => 'common', 'label' => '共通', 'type' => 'clone', 'clone' => ['field_missing'], 'display' => 'seamless'],
        ])], $this->context());

        self::assertSame(["E004: clone field 'common' in group 'group_clone' references missing field 'field_missing'"], $report->errors());
    }

    /**
     * 単一のテンプレートを条件にするグループは、テンプレートごとのクラス(`{Studly(ファイル名)}TemplateFields`)に出し、
     * 一般のpageのグループはPageFieldsに残す。1つのテンプレートを複数の投稿タイプが使えば、クラスはテンプレートごとに
     * 1つで、管理キーは投稿タイプごとに出す。別のテンプレートの同じ名前のフィールドは、同じ(post, page, 名前)の管理キーを
     * 1つだけ出す。
     */
    public function testTemplateGroupsGenerateOneClassPerTemplate(): void
    {
        $report = (new Generator())->generate([
            $this->locatedGroup('group_about', [[['page_template', 'templates/page-about.php']], [['post_type', 'product'], ['post_template', 'templates/page-about.php']]], [self::text('hero')]),
            $this->locatedGroup('group_contact', [[['page_template', 'templates/page-contact.php']]], [['key' => 'field_contact_hero', 'name' => 'hero', 'label' => 'hero', 'type' => 'text']]),
            $this->locatedGroup('group_page', [[['post_type', 'page']]], [self::text('subtitle')]),
        ], $this->context());
        $files = $this->filesByPath($report->files());

        self::assertSame([], $report->errors());
        self::assertSame([], $report->warnings());
        self::assertSame(['AcfManifest.php', 'PageAboutTemplateFields.php', 'PageContactTemplateFields.php', 'PageFields.php'], array_keys($files));
        self::assertStringContainsString("PageAboutTemplateFields — ACF typed accessors for post template 'templates/page-about.php'.", $files['PageAboutTemplateFields.php']);
        self::assertStringContainsString("public const KEY_HERO = 'field_hero';", $files['PageAboutTemplateFields.php']);
        self::assertStringContainsString("public const KEY_HERO = 'field_contact_hero';", $files['PageContactTemplateFields.php']);
        self::assertStringNotContainsString('HERO', $files['PageFields.php']);
        self::assertSame(3, substr_count($files['AcfManifest.php'], 'new ManagedKey('));
        self::assertStringContainsString("new ManagedKey(ObjectType::Post, 'page', 'hero', 'field_hero', 'group_about'),\n            new ManagedKey(ObjectType::Post, 'page', 'subtitle', 'field_subtitle', 'group_page'),\n            new ManagedKey(ObjectType::Post, 'product', 'hero', 'field_hero', 'group_about'),", $files['AcfManifest.php']);
    }

    /**
     * テンプレート別のクラスとその投稿タイプのクラスが、同じ名前の別のフィールド(field keyが違う)を持てばE001にする
     * (テンプレートを選んだ投稿に両方のグループが表示され、同じメタに保存されるため)。field keyが同じなら同じフィールド。
     */
    public function testTemplateFieldNamedLikeAFieldOfThePostTypeIsE001(): void
    {
        $page = $this->locatedGroup('group_page', [[['post_type', 'page']]], [self::text('subtitle')]);
        $conflicting = $this->locatedGroup('group_about', [[['page_template', 'templates/page-about.php']]], [['key' => 'field_about_subtitle', 'name' => 'subtitle', 'label' => 'subtitle', 'type' => 'text']]);
        $shared = $this->locatedGroup('group_about', [[['page_template', 'templates/page-about.php']]], [self::text('subtitle')]);

        self::assertSame(
            ["E001: duplicate ACF field 'subtitle' for post:page in groups group_page and group_about (template 'templates/page-about.php' of post:page); a post with the template shows both groups and ACF saves both fields to the same meta key, so rename one of them or share one field"],
            (new Generator())->generate([$conflicting, $page], $this->context())->errors(),
        );
        self::assertSame([], (new Generator())->generate([$shared, $page], $this->context())->errors());
    }

    /**
     * `post_type==all`のグループは、どの投稿タイプのテンプレートのクラスとも同じ投稿に表示されるため、同じ名前の別の
     * フィールドはE001にする。field keyが同じなら同じフィールドとして両方に出す。
     */
    public function testAllPostTypesGroupAndTemplateWithTheSameFieldNameIsE001(): void
    {
        $all = $this->locatedGroup('group_all', [[['post_type', 'all']]], [['key' => 'field_all_title', 'name' => 'title', 'label' => 'title', 'type' => 'text']]);
        $template = $this->locatedGroup('group_feature', [[['post_type', 'product'], ['post_template', 'templates/feature.php']]], [self::text('title')]);
        $shared = $this->locatedGroup('group_feature', [[['post_type', 'product'], ['post_template', 'templates/feature.php']]], [['key' => 'field_all_title', 'name' => 'title', 'label' => 'title', 'type' => 'text']]);

        self::assertSame(
            ["E001: duplicate ACF field 'title' for post:all in groups group_all and group_feature (template 'templates/feature.php' of post:product); a post with the template shows both groups and ACF saves both fields to the same meta key, so rename one of them or share one field"],
            (new Generator())->generate([$all, $template], $this->context())->errors(),
        );
        self::assertSame([], (new Generator())->generate([$all, $shared], $this->context())->errors());
    }

    /**
     * 同じテンプレートのクラスに合流するグループが、classMapで違うクラス名を求めれば、どちらも黙って捨てずにE003にする
     * (テンプレートごとに1つのクラスを作る)。classMapの無いグループとの食い違いも同じ。
     */
    public function testDifferentClassMapsForOneTargetAreE003(): void
    {
        $page = $this->locatedGroup('group_a', [[['page_template', 'templates/feature.php']]], [self::text('title')]);
        $product = $this->locatedGroup('group_b', [[['post_type', 'product'], ['post_template', 'templates/feature.php']]], [self::text('body')]);

        self::assertSame(
            ["E003: target post-template:templates/feature.php is requested as class 'FirstFields' by group 'group_a' and as class 'SecondFields' by group 'group_b'; set the same class name for both groups in BridgeConfig::\$classMap (one class is generated per target)"],
            (new Generator())->generate([$page, $product], $this->classMapContext(['group_a' => 'FirstFields', 'group_b' => 'SecondFields']))->errors(),
        );
        self::assertSame(
            ["E003: target post-template:templates/feature.php is requested as class 'FirstFields' by group 'group_a' and as class 'FeatureTemplateFields' by group 'group_b'; set the same class name for both groups in BridgeConfig::\$classMap (one class is generated per target)"],
            (new Generator())->generate([$page, $product], $this->classMapContext(['group_a' => 'FirstFields']))->errors(),
        );
        $same = (new Generator())->generate([$page, $product], $this->classMapContext(['group_a' => 'FirstFields', 'group_b' => 'FirstFields']));
        self::assertSame([], $same->errors());
        self::assertSame(['AcfManifest.php', 'FirstFields.php'], array_keys($this->filesByPath($same->files())));
    }

    /**
     * ファイル名が同じテンプレート(ディレクトリが違う)はクラス名が衝突するため、E003にする。メッセージは両方の対象と、
     * classMapで避ける方法を示す(docs/09 §4.3)。classMapで片方のクラス名を変えれば生成できる。
     */
    public function testTemplateClassNamesThatCollideAreE003(): void
    {
        $groups = [
            $this->locatedGroup('group_a', [[['page_template', 'templates/a/feature.php']]], [self::text('title')]),
            $this->locatedGroup('group_b', [[['page_template', 'templates/b/feature.php']]], [self::text('title')]),
        ];

        self::assertSame(
            ["E003: class name 'FeatureTemplateFields' is used by both post-template:templates/a/feature.php and post-template:templates/b/feature.php; set another class name for the group of one of them in BridgeConfig::\$classMap (only for a group whose location resolves to one class)"],
            (new Generator())->generate($groups, $this->context())->errors(),
        );

        $mapped = (new Generator())->generate($groups, $this->classMapContext(['group_a' => 'AFeatureTemplateFields']));
        self::assertSame([], $mapped->errors());
        self::assertSame(['AFeatureTemplateFields.php', 'AcfManifest.php', 'FeatureTemplateFields.php'], array_keys($this->filesByPath($mapped->files())));
    }

    /**
     * 1つのテンプレートを複数の投稿タイプで使うグループは、投稿タイプごとの対象が1つのテンプレートのクラスになるため、
     * 単一のターゲットのグループとしてclassMapでクラス名を変えられる(docs/09 §4.3)。
     */
    public function testClassMapRenamesTheTemplateClassOfSeveralPostTypes(): void
    {
        $group = $this->locatedGroup('group_a', [
            [['post_type', 'page'], ['post_template', 'templates/feature.php']],
            [['post_type', 'post'], ['post_template', 'templates/feature.php']],
        ], [self::text('title')]);

        $report = (new Generator())->generate([$group], $this->classMapContext(['group_a' => 'FeatureFields']));

        self::assertSame([], $report->errors());
        self::assertSame(['AcfManifest.php', 'FeatureFields.php'], array_keys($this->filesByPath($report->files())));

        // 2つの投稿タイプのクラスになるグループには、classMapを指定できない
        $twoClasses = $this->locatedGroup('group_b', [[['post_type', 'page']], [['post_type', 'post']]], [self::text('title')]);
        self::assertSame(
            ["E003: classMap for 'group_b' is only allowed for a group whose location resolves to one class"],
            (new Generator())->generate([$twoClasses], $this->classMapContext(['group_b' => 'BothFields']))->errors(),
        );
    }

    /** 違う種類のターゲットが同じクラス名になるときも、E003のメッセージはclassMapで避ける方法を示す。 */
    public function testClassNameCollisionOfDifferentKindsShowsTheClassMapResolution(): void
    {
        $report = (new Generator())->generate([
            $this->locatedGroup('group_a', [[['post_type', 'product-term']]], [self::text('title')]),
            $this->locatedGroup('group_b', [[['taxonomy', 'product']]], [self::text('body')]),
        ], $this->context());

        self::assertSame(
            ["E003: class name 'ProductTermFields' is used by both post:product-term and term:product; set another class name for the group of one of them in BridgeConfig::\$classMap (only for a group whose location resolves to one class)"],
            $report->errors(),
        );
    }

    /**
     * ヘッダのsourcesは、ディレクトリだけが違う同じ名前のファイル(子テーマと親テーマのacf-json等)のグループを1行に
     * まとめず、両方を記す。生成クラスとAcfManifestのどちらも同じ。
     */
    public function testSourcesListFilesWithTheSameNameInDifferentDirectories(): void
    {
        $child = FieldGroup::fromArray(['key' => 'group_child', 'title' => '子テーマ', 'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'product']]], 'fields' => [self::text('title')]], '/child/acf-json/fields.json');
        $parent = FieldGroup::fromArray(['key' => 'group_parent', 'title' => '親テーマ', 'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'product']]], 'fields' => [self::text('body')]], '/parent/acf-json/fields.json');

        $files = $this->filesByPath((new Generator())->generate([$child, $parent], $this->context())->files());

        foreach (['ProductFields.php', 'AcfManifest.php'] as $path) {
            self::assertStringContainsString(" * sources:\n *   fields.json (子テーマ)\n *   fields.json (親テーマ)\n */", $files[$path], $path);
        }
    }

    /**
     * acf-jsonの文字列が生成物のdocコメントに説明の文として入る箇所(ヘッダのタイトルの投稿タイプ・タクソノミー・
     * menu_slug・テンプレート、sourcesのグループのタイトル、定数とgetterの説明のラベル・型・グループのkey、cloneの
     * prefix_labelの付いたラベル)に、docコメントを閉じる並びがあっても、その並びのスラッシュをエスケープして埋め込み、
     * 生成物はPHPとして解析できる。
     */
    public function testDocCommentTextCannotCloseTheComment(): void
    {
        $end = '*' . '/';
        $groups = [
            FieldGroup::fromArray([
                'key' => "group_post{$end}x",
                'title' => "タイトル {$end} echo 1;",
                'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => "pt{$end}x"]]],
                'fields' => [
                    self::commentField('field_price', 'price', 'text'),
                    self::commentField('field_map', 'map', "map{$end}x"),
                    self::commentField('field_box', 'box', 'group', ['sub_fields' => [self::commentField('field_box_title', 'title', 'text')]]),
                    self::commentField('field_cloned', 'cloned', 'clone', ['clone' => ['group_source'], 'display' => 'seamless', 'prefix_name' => 1, 'prefix_label' => 1]),
                ],
            ], 'acf-json/group_post.json'),
            FieldGroup::fromArray([
                'key' => 'group_source',
                'title' => "source {$end}",
                'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'source']]],
                'fields' => [self::commentField('field_headline', 'headline', 'text')],
            ], 'acf-json/group_source.json'),
            $this->commentGroup('group_term', [['taxonomy', "tax{$end}x"]], self::commentField('field_term_note', 'note', 'text')),
            $this->commentGroup('group_options', [['options_page', "opt{$end}x"]], self::commentField('field_logo', 'logo', 'text')),
            $this->commentGroup('group_template', [['page_template', "templates/tpl{$end}x.php"]], self::commentField('field_lead', 'lead', 'text')),
        ];

        $report = (new Generator())->generate($groups, $this->context(optionsPagePostIds: ["opt{$end}x" => 'options']));

        self::assertSame([], $report->errors());
        $files = $this->filesByPath($report->files());
        self::assertSame(['AcfManifest.php', 'OptXOptions.php', 'PtXFields.php', 'SourceFields.php', 'TaxXTermFields.php', 'XTemplateFields.php'], array_keys($files));

        foreach ($files as $path => $contents) {
            // 構文エラーならParseErrorになる。docコメントは、閉じる並びを最後にだけ持つ
            foreach (token_get_all($contents, TOKEN_PARSE) as $token) {
                if (\is_array($token) && $token[0] === T_DOC_COMMENT) {
                    self::assertSame(\strlen($token[1]) - 2, strpos($token[1], $end), "{$path}: {$token[1]}");
                }
            }
        }

        self::assertStringContainsString(" * PtXFields — ACF typed accessors for post_type 'pt*\\/x'.\n", $files['PtXFields.php']);
        self::assertStringContainsString(" *   group_post.json (タイトル *\\/ echo 1;)\n", $files['PtXFields.php']);
        self::assertStringContainsString("    /** price *\\/ label (text, group_post*\\/x) */\n", $files['PtXFields.php']);
        self::assertStringContainsString("    /** map *\\/ label (map*\\/x, group_post*\\/x) */\n", $files['PtXFields.php']);
        self::assertStringContainsString("    /** cloned *\\/ label headline *\\/ label (text, group_post*\\/x) */\n", $files['PtXFields.php']);
        self::assertStringContainsString(" * TaxXTermFields — ACF typed accessors for taxonomy 'tax*\\/x'.\n", $files['TaxXTermFields.php']);
        self::assertStringContainsString(" * OptXOptions — ACF typed accessors for options_page 'opt*\\/x'.\n", $files['OptXOptions.php']);
        self::assertStringContainsString(" * XTemplateFields — ACF typed accessors for post template 'templates/tpl*\\/x.php'.\n", $files['XTemplateFields.php']);
        self::assertStringContainsString(" *   group_source.json (source *\\/)\n", $files['AcfManifest.php']);
    }

    /**
     * group・repeater・flexible_contentのレイアウトのサブフィールドの名前と、flexible_contentのレイアウトの名前は、
     * getterのPHPDocの型(shapeのキー・レイアウトのリテラル)になり、docコメントを閉じる並びを書けないため、E002にして
     * ACFで名前を変えるよう案内する。groupの表示のcloneが展開したサブフィールドも同じ。
     */
    public function testNamesInPhpDocTypesCannotCloseTheComment(): void
    {
        $end = '*' . '/';
        $report = (new Generator())->generate([
            $this->commentGroup('group_types', [['post_type', 'product']], ...[
                ['key' => 'field_box', 'name' => 'box', 'label' => 'box', 'type' => 'group', 'sub_fields' => [self::text("a{$end}b")]],
                ['key' => 'field_rows', 'name' => 'rows', 'label' => 'rows', 'type' => 'repeater', 'sub_fields' => [self::text("r{$end}s")]],
                ['key' => 'field_blocks', 'name' => 'blocks', 'label' => 'blocks', 'type' => 'flexible_content', 'layouts' => [
                    ['key' => 'layout_bad', 'name' => "l{$end}m", 'label' => 'bad', 'sub_fields' => []],
                    ['key' => 'layout_quote', 'name' => 'quote', 'label' => 'quote', 'sub_fields' => [self::text("x{$end}y")]],
                ]],
                ['key' => 'field_cloned', 'name' => 'cloned', 'label' => 'cloned', 'type' => 'clone', 'clone' => ['group_source'], 'display' => 'group'],
            ]),
            $this->commentGroup('group_source', [['post_type', 'source']], self::text("c{$end}d")),
        ], $this->context());

        self::assertSame([
            "E002: ACF field 'box' in group 'group_types' has a sub field named 'a*/b', which contains '*/' and cannot be written in the PHPDoc type of the generated accessor; rename the sub field in ACF",
            "E002: ACF field 'rows' in group 'group_types' has a sub field named 'r*/s', which contains '*/' and cannot be written in the PHPDoc type of the generated accessor; rename the sub field in ACF",
            "E002: ACF field 'blocks' in group 'group_types' has a layout named 'l*/m', which contains '*/' and cannot be written in the PHPDoc type of the generated accessor; rename the layout in ACF",
            "E002: layout 'quote' of ACF field 'blocks' in group 'group_types' has a sub field named 'x*/y', which contains '*/' and cannot be written in the PHPDoc type of the generated accessor; rename the sub field in ACF",
            "E002: ACF field 'cloned' in group 'group_types' has a sub field named 'c*/d', which contains '*/' and cannot be written in the PHPDoc type of the generated accessor; rename the sub field in ACF",
        ], $report->errors());
    }

    /** 対象を決められないANDグループごとのW002に加えて、どのANDグループも解決できないグループにもW002を出す。 */
    public function testUnresolvedAndGroupsAreReportedOneByOne(): void
    {
        $report = (new Generator())->generate([
            $this->locatedGroup('group_partial', [[['post_type', 'product']], [['current_user', 'logged_in']]], [self::text('title')]),
            $this->locatedGroup('group_none', [[['post_template', 'templates/feature.php']]], [self::text('title')]),
        ], $this->context());

        self::assertSame([], $report->errors());
        self::assertSame([
            "W002: location AND group 2 of ACF field group 'group_partial' was skipped: it has no '==' condition that decides the target",
            "W002: location AND group 1 of ACF field group 'group_none' was skipped: 'post_template' needs a 'post_type ==' condition in the same AND group to decide the post type",
            "W002: ACF field group 'group_none' location could not be resolved",
        ], $report->warnings());
    }

    /**
     * 生成するメソッドが、AcfFieldAccessのメソッド(private・protected・publicのすべて。Reflectionで集める)と、生成クラスの
     * ファクトリ(of・instance)と衝突するフィールドはE002にする。traitのメソッドをすべて、そのメソッド名になるフィールド名で
     * 確かめる(手書きの一覧では、traitを変えたときに漏れるため)。
     */
    public function testEveryTraitMethodIsReserved(): void
    {
        $fields = [];
        $expected = [];
        $methods = [];

        foreach ((new ReflectionClass(AcfFieldAccess::class))->getMethods() as $method) {
            if (str_starts_with($method->getName(), '_')) {
                continue;
            }

            $methods[] = $method->getName();

            $fieldName = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $method->getName()));
            $fields[] = ['key' => 'field_' . $fieldName, 'name' => $fieldName, 'label' => $fieldName, 'type' => 'text'];
            $expected[] = "E002: the accessor for ACF field '{$fieldName}' in group 'group_reserved' cannot be generated: method '{$method->getName()}' collides with the method '{$method->getName()}' of the trait AcfFieldAccess (PHP method names are case-insensitive); set BridgeConfig::\$methodNameMap['group_reserved.{$fieldName}'] to another method name (the constants are named after it)";
        }

        // 公開・保護・privateのメソッドを含む
        self::assertContains('value', $methods);
        self::assertContains('acfStringOf', $methods);
        self::assertContains('hasStoredValue', $methods);

        $report = (new Generator())->generate([$this->group('group_reserved', 'product', $fields)], $this->context());

        self::assertSame($expected, $report->errors());
    }

    /**
     * @param list<array<string, mixed>> $fields
     * @param array<string, string> $methodNameMap
     */
    #[DataProvider('collidingFields')]
    public function testCollisionsAreGenerationErrors(array $fields, array $methodNameMap, string $error): void
    {
        $report = (new Generator())->generate([$this->group('group_names', 'product', $fields)], $this->context($methodNameMap));

        self::assertSame([$error], $report->errors());
    }

    /**
     * flexible_contentのレイアウトの型の別名(`{Studly(フィールド)}_{Studly(レイアウト)}`。docs/09 §6.4)を、クラスの中で
     * 一意に宣言できない場合はE002にする。同じ名前で同じshapeのレイアウトは、1つの別名を共有するためエラーにしない。
     */
    public function testLayoutTypeAliasConflictsAreGenerationErrors(): void
    {
        $flexible = static fn(string $key, string $subField): array => [
            'key' => $key,
            'name' => 'blocks',
            'label' => 'blocks',
            'type' => 'flexible_content',
            'layouts' => [['name' => 'quote', 'sub_fields' => [self::text($subField)]]],
        ];
        $rows = static fn(array $subField): array => ['key' => 'field_rows', 'name' => 'rows', 'label' => 'rows', 'type' => 'repeater', 'sub_fields' => [$subField]];

        $shared = (new Generator())->generate([$this->group('group_names', 'product', [$flexible('field_blocks', 'text'), $rows($flexible('field_row_blocks', 'text'))])], $this->context());
        self::assertSame([], $shared->errors());
        self::assertSame(1, substr_count($this->filesByPath($shared->files())['ProductFields.php'] ?? '', '@phpstan-type Blocks_Quote '));

        $report = (new Generator())->generate([$this->group('group_names', 'product', [$flexible('field_blocks', 'text'), $rows($flexible('field_row_blocks', 'body'))])], $this->context());
        self::assertSame([
            "E002: class ProductFields cannot declare the layout type aliases of flexible_content fields: the type alias 'Blocks_Quote' is declared for two different layouts (array{acf_fc_layout: 'quote', text: string} and array{acf_fc_layout: 'quote', body: string}); rename the flexible_content field or the layout in ACF",
        ], $report->errors());
    }

    /** @return iterable<string, array{list<array<string, mixed>>, array<string, string>, string}> */
    public static function collidingFields(): iterable
    {
        $resolution = static fn(string $field): string => "set BridgeConfig::\$methodNameMap['group_names.{$field}'] to another method name (the constants are named after it)";

        yield 'factory of' => [
            [self::text('of')],
            [],
            "E002: the accessor for ACF field 'of' in group 'group_names' cannot be generated: method 'of' collides with the factory method 'of' of the generated class (PHP method names are case-insensitive); " . $resolution('of'),
        ];
        yield 'factory instance' => [
            [self::text('instance')],
            [],
            "E002: the accessor for ACF field 'instance' in group 'group_names' cannot be generated: method 'instance' collides with the factory method 'instance' of the generated class (PHP method names are case-insensitive); " . $resolution('instance'),
        ];
        yield 'trait method with another case' => [
            [self::text('style')],
            ['group_names.style' => 'acfSTRING'],
            "E002: the accessor for ACF field 'style' in group 'group_names' cannot be generated: method 'acfSTRING' collides with the method 'acfString' of the trait AcfFieldAccess (PHP method names are case-insensitive); " . $resolution('style'),
        ];
        yield 'getters that differ only in case' => [
            [self::text('foo_bar'), self::text('foobar')],
            [],
            "E002: the accessor for ACF field 'foobar' in group 'group_names' cannot be generated: method 'foobar' collides with the method 'fooBar' generated for ACF field 'foo_bar' in group 'group_names'; "
            . "method 'setFoobar' collides with the method 'setFooBar' generated for ACF field 'foo_bar' in group 'group_names'; "
            . "method 'hasFoobar' collides with the method 'hasFooBar' generated for ACF field 'foo_bar' in group 'group_names'; "
            . "method 'deleteFoobar' collides with the method 'deleteFooBar' generated for ACF field 'foo_bar' in group 'group_names' (PHP method names are case-insensitive); " . $resolution('foobar'),
        ];
        yield 'getter and setter of another field' => [
            [self::text('price'), self::text('set_price')],
            [],
            "E002: the accessor for ACF field 'set_price' in group 'group_names' cannot be generated: method 'setPrice' collides with the method 'setPrice' generated for ACF field 'price' in group 'group_names' (PHP method names are case-insensitive); " . $resolution('set_price'),
        ];
        yield 'has of another field' => [
            [self::text('price'), self::text('has_price')],
            [],
            "E002: the accessor for ACF field 'has_price' in group 'group_names' cannot be generated: method 'hasPrice' collides with the method 'hasPrice' generated for ACF field 'price' in group 'group_names' (PHP method names are case-insensitive); " . $resolution('has_price'),
        ];
        yield 'date time getter of another field' => [
            [['key' => 'field_starts', 'name' => 'starts', 'label' => 'starts', 'type' => 'date_picker'], self::text('starts_as_date_time')],
            [],
            "E002: the accessor for ACF field 'starts_as_date_time' in group 'group_names' cannot be generated: method 'startsAsDateTime' collides with the method 'startsAsDateTime' generated for ACF field 'starts' in group 'group_names' (PHP method names are case-insensitive); " . $resolution('starts_as_date_time'),
        ];
        yield 'key constant of another field' => [
            [self::text('price'), self::text('key_price')],
            [],
            "E002: the accessor for ACF field 'key_price' in group 'group_names' cannot be generated: constant 'KEY_PRICE' collides with the constant 'KEY_PRICE' generated for ACF field 'price' in group 'group_names'; " . $resolution('key_price'),
        ];
        yield 'class constant' => [
            [self::text('class')],
            [],
            "E002: ACF field 'class' in group 'group_names' cannot be generated because 'CLASS' is not allowed as a PHP class constant name; " . $resolution('class'),
        ];
        yield 'class constant from methodNameMap' => [
            [self::text('css')],
            ['group_names.css' => 'class'],
            "E002: ACF field 'css' in group 'group_names' cannot be generated because 'CLASS' is not allowed as a PHP class constant name; " . $resolution('css'),
        ];
        yield 'duplicate sub fields' => [
            [['key' => 'field_rows', 'name' => 'rows', 'label' => 'rows', 'type' => 'repeater', 'sub_fields' => [self::text('title'), self::text('title')]]],
            [],
            "E002: ACF field 'rows' in group 'group_names' has more than one sub field named 'title'; rename one of them",
        ];
        yield 'decimal integer sub field name in a group' => [
            [['key' => 'field_box', 'name' => 'box', 'label' => 'box', 'type' => 'group', 'sub_fields' => [self::text('0'), self::text('zero')]]],
            [],
            "E002: ACF field 'box' in group 'group_names' has a sub field named '0', which PHP converts to the integer array key 0, so the generated accessor cannot return it as a field name; rename the sub field in ACF to a name that is not a decimal integer",
        ];
        yield 'decimal integer sub field name in a repeater' => [
            [['key' => 'field_rows', 'name' => 'rows', 'label' => 'rows', 'type' => 'repeater', 'sub_fields' => [self::text('-12')]]],
            [],
            "E002: ACF field 'rows' in group 'group_names' has a sub field named '-12', which PHP converts to the integer array key -12, so the generated accessor cannot return it as a field name; rename the sub field in ACF to a name that is not a decimal integer",
        ];
        yield 'decimal integer sub field name in a layout' => [
            [['key' => 'field_sections', 'name' => 'sections', 'label' => 'sections', 'type' => 'flexible_content', 'layouts' => [['name' => 'hero', 'sub_fields' => [self::text('7')]]]]],
            [],
            "E002: layout 'hero' of ACF field 'sections' in group 'group_names' has a sub field named '7', which PHP converts to the integer array key 7, so the generated accessor cannot return it as a field name; rename the sub field in ACF to a name that is not a decimal integer",
        ];
        yield 'decimal integer sub field name in a nested group' => [
            [['key' => 'field_rows', 'name' => 'rows', 'label' => 'rows', 'type' => 'repeater', 'sub_fields' => [['key' => 'field_inner', 'name' => 'inner', 'label' => 'inner', 'type' => 'group', 'sub_fields' => [self::text('1')]]]]],
            [],
            "E002: ACF field 'rows.inner' in group 'group_names' has a sub field named '1', which PHP converts to the integer array key 1, so the generated accessor cannot return it as a field name; rename the sub field in ACF to a name that is not a decimal integer",
        ];
        yield 'duplicate layouts' => [
            [['key' => 'field_sections', 'name' => 'sections', 'label' => 'sections', 'type' => 'flexible_content', 'layouts' => [
                ['name' => 'hero', 'sub_fields' => [self::text('title')]],
                ['name' => 'hero', 'sub_fields' => [self::text('body')]],
            ]]],
            [],
            "E002: ACF field 'sections' in group 'group_names' has more than one layout named 'hero'; rename one of them",
        ];
        yield 'decimal integer layout name' => [
            [['key' => 'field_sections', 'name' => 'sections', 'label' => 'sections', 'type' => 'flexible_content', 'layouts' => [
                ['name' => 'hero', 'sub_fields' => [self::text('title')]],
                ['name' => '0', 'sub_fields' => [self::text('body')]],
            ]]],
            [],
            "E002: ACF field 'sections' in group 'group_names' has a layout named '0', which PHP converts to the integer array key 0, so the generated accessor cannot map it to the normalizer of its rows; rename the layout in ACF to a name that is not a decimal integer",
        ];
        yield 'decimal integer layout name in a nested flexible content' => [
            [['key' => 'field_box', 'name' => 'box', 'label' => 'box', 'type' => 'group', 'sub_fields' => [
                ['key' => 'field_blocks', 'name' => 'blocks', 'label' => 'blocks', 'type' => 'flexible_content', 'layouts' => [['name' => '12', 'sub_fields' => []]]],
            ]]],
            [],
            "E002: ACF field 'box.blocks' in group 'group_names' has a layout named '12', which PHP converts to the integer array key 12, so the generated accessor cannot map it to the normalizer of its rows; rename the layout in ACF to a name that is not a decimal integer",
        ];
        yield 'acf_fc_layout sub field' => [
            [['key' => 'field_sections', 'name' => 'sections', 'label' => 'sections', 'type' => 'flexible_content', 'layouts' => [['name' => 'hero', 'sub_fields' => [self::text('acf_fc_layout')]]]]],
            [],
            "E002: layout 'hero' of ACF field 'sections' in group 'group_names' has a sub field named 'acf_fc_layout', which ACF uses for the layout name of each row; rename the sub field",
        ];
    }

    /**
     * 10進の整数の名前のトップレベルのフィールドは、配列のキーにならず、ASCII snake_caseでないためW009でアクセサを
     * 生成しない(E002にはならない)。
     */
    public function testTopLevelDecimalIntegerNamesAreSkippedWithW009(): void
    {
        $report = (new Generator())->generate([$this->group('group_names', 'product', [self::text('0'), self::text('12')])], $this->context());

        self::assertSame([], $report->errors());
        self::assertSame([
            "W009: ACF field '0' in group 'group_names' is not ASCII snake_case; accessor was skipped; set BridgeConfig::\$methodNameMap['group_names.0'] to an ASCII method name to generate it",
            "W009: ACF field '12' in group 'group_names' is not ASCII snake_case; accessor was skipped; set BridgeConfig::\$methodNameMap['group_names.12'] to an ASCII method name to generate it",
        ], $report->warnings());
    }

    /**
     * 日本語のフィールド名は、methodNameMapが無ければW009になり、アクセサも定数も生成しない(docs/09 §5.5の1。受け入れ
     * 基準「日本語等非ASCIIフィールド名がW009になり」。methodNameMapで生成できることは
     * testItGeneratesDeterministicAccessorClassesAndManifestが確かめる)。
     */
    public function testJapaneseFieldNameWithoutMethodNameMapIsSkippedWithW009(): void
    {
        $report = (new Generator())->generate([$this->group('group_shop', 'product', [
            ['key' => 'field_store_name', 'name' => '店舗名', 'label' => '店舗名', 'type' => 'text'],
            self::text('price'),
        ])], $this->context());
        $product = $this->filesByPath($report->files())['ProductFields.php'];

        self::assertSame([], $report->errors());
        self::assertSame([
            "W009: ACF field '店舗名' in group 'group_shop' is not ASCII snake_case; accessor was skipped; set BridgeConfig::\$methodNameMap['group_shop.店舗名'] to an ASCII method name to generate it",
        ], $report->warnings());
        self::assertStringNotContainsString('店舗名', $product);
        self::assertStringNotContainsString('field_store_name', $product);
        self::assertStringContainsString('public function price(): string', $product);
    }

    /**
     * 同じターゲット(post_type==product)の複数のグループは、グループのkeyの昇順で1つのクラスにマージし、マージ後に
     * 同じ名前のフィールドがあればE001にする(docs/09 §4.3。受け入れ基準「同一ターゲットへの複数グループがマージされ、
     * フィールド名重複がE001になる」)。fixtureのファイル名の順(a-spec・b-basic)はkeyの順(group_basic・group_spec)と
     * 逆にしてある。
     */
    public function testGroupsOfOneTargetAreMergedInKeyOrderAndADuplicateFieldNameIsE001(): void
    {
        $json = (new AcfJsonRepository([__DIR__ . '/../../fixtures/acf-json-duplicate-field']))->load();
        $report = (new Generator())->generate($json->fieldGroups, $this->context());
        $files = $this->filesByPath($report->files());

        self::assertSame(["E001: duplicate ACF field 'price' for post:product in groups group_basic and group_spec"], $report->errors());
        self::assertSame(['AcfManifest.php', 'ProductFields.php'], array_keys($files));
        preg_match_all("/public const KEY_[A-Z_]+ = '[a-z_]+';/", $files['ProductFields.php'], $matches);
        self::assertSame(
            ["public const KEY_PRICE = 'field_price';", "public const KEY_COLOR = 'field_color';", "public const KEY_WEIGHT = 'field_weight';"],
            $matches[0],
        );
    }

    /**
     * PHPの予約語(list・function・match等)は、PHPがメソッド名として受け付けるため、接尾辞を付けずにそのまま生成する。
     * 衝突した名前は、methodNameMapで別の名前にすれば生成でき、定数もその名前から作る。
     */
    public function testKeywordsAreMethodNamesAndMethodNameMapResolvesCollisions(): void
    {
        $report = (new Generator())->generate([$this->group('group_names', 'product', [
            self::text('list'),
            self::text('function'),
            self::text('class'),
            self::text('value'),
        ])], $this->context(['group_names.class' => 'cssClass', 'group_names.value' => 'amount']));

        self::assertSame([], $report->errors());
        $product = $this->filesByPath($report->files())['ProductFields.php'];
        self::assertStringContainsString('public function list(): string', $product);
        self::assertStringContainsString('public function function(): string', $product);
        self::assertStringContainsString("public const CSS_CLASS = 'class';", $product);
        self::assertStringContainsString('public function cssClass(): string', $product);
        self::assertStringContainsString("public const AMOUNT = 'value';", $product);
        self::assertStringContainsString('public function amount(): string', $product);
        self::assertStringNotContainsString('Field(', $product);
    }

    /**
     * classMapで生成クラスの短名を、生成コードが参照するクラス(ObjectType・DateTimeImmutable・DateTimeInterface・
     * AcfFieldAccess)と大文字小文字を区別せずに同じにしても、そのクラスはuseせずに完全修飾名で参照し、PHPで読み込める。
     * 生成物はfixtures/expected-imports/{名前空間の最後の部分}/と一致し、PHPStan(level max)とphp-cs-fixerの対象である。
     */
    #[DataProvider('classNamesLikeReferencedClasses')]
    public function testClassNamedLikeAReferencedClassIsLoadable(string $namespace, string $className, string $fullyQualified): void
    {
        $contents = $this->generateNamedClass($namespace, $className);
        $file = self::EXPECTED_IMPORTS . '/' . substr((string) strrchr($namespace, '\\'), 1) . "/{$className}.php";

        self::assertFileExists($file);
        self::assertSame($this->readFixture($file), $contents);
        self::assertStringNotContainsString("use {$fullyQualified};", $contents);
        self::assertStringContainsString('\\' . $fullyQualified, $contents);

        $lint = self::runPhp(['-l', $file]);
        self::assertSame(0, $lint['exit'], $lint['output']);
        $load = self::runPhp(['-r', 'require $argv[1]; require $argv[2]; echo class_exists($argv[3], false) ? "loaded" : "missing";', self::composerAutoloadFile(), $file, "{$namespace}\\{$className}"]);
        self::assertSame(['exit' => 0, 'output' => 'loaded'], $load);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function classNamesLikeReferencedClasses(): iterable
    {
        yield 'ObjectType' => ['App\\Imports\\ObjectTypeExact', 'ObjectType', 'TMSWP\\Core\\Meta\\ObjectType'];
        yield 'Objecttype' => ['App\\Imports\\ObjectTypeOtherCase', 'Objecttype', 'TMSWP\\Core\\Meta\\ObjectType'];
        yield 'DateTimeImmutable' => ['App\\Imports\\DateTimeImmutableName', 'DateTimeImmutable', 'DateTimeImmutable'];
        yield 'DateTimeInterface' => ['App\\Imports\\DateTimeInterfaceName', 'DateTimeInterface', 'DateTimeInterface'];
        yield 'AcfFieldAccess' => ['App\\Imports\\AcfFieldAccessName', 'AcfFieldAccess', 'TMSWP\\AcfBridge\\AcfFieldAccess'];
    }

    /**
     * classMapで生成クラスの短名を、`@phpstan-import-type`で取り込む型の別名(AcfImage)と大文字小文字を区別せずに同じに
     * しても、その別名は`as`で重ならない名前(AcfImageType)にして取り込み、生成コードもその名前を使う(PHPStanは、
     * クラスと同じ名前の別名をtypeAlias.duplicateにする)。生成物はfixtures/expected-importsと一致し、PHPStan
     * (level max)とphp-cs-fixerの対象で、PHPで読み込める。
     */
    #[DataProvider('classNamesLikeTypeAliases')]
    public function testClassNamedLikeATypeAliasImportsTheAliasUnderAnotherName(string $namespace, string $className): void
    {
        $contents = $this->generateNamedClass($namespace, $className);
        $file = self::EXPECTED_IMPORTS . '/' . substr((string) strrchr($namespace, '\\'), 1) . "/{$className}.php";

        self::assertFileExists($file);
        self::assertSame($this->readFixture($file), $contents);
        self::assertStringContainsString(" * @phpstan-import-type AcfImage from \\TMSWP\\AcfBridge\\AcfTypes as AcfImageType\n", $contents);
        self::assertStringContainsString('@return AcfImageType|null', $contents);
        self::assertStringNotContainsString('@return AcfImage|null', $contents);

        $lint = self::runPhp(['-l', $file]);
        self::assertSame(0, $lint['exit'], $lint['output']);
        $load = self::runPhp(['-r', 'require $argv[1]; require $argv[2]; echo class_exists($argv[3], false) ? "loaded" : "missing";', self::composerAutoloadFile(), $file, "{$namespace}\\{$className}"]);
        self::assertSame(['exit' => 0, 'output' => 'loaded'], $load);
    }

    /** @return iterable<string, array{string, string}> */
    public static function classNamesLikeTypeAliases(): iterable
    {
        yield 'AcfImage' => ['App\\Imports\\AcfImageExact', 'AcfImage'];
        yield 'Acfimage' => ['App\\Imports\\AcfImageOtherCase', 'Acfimage'];
    }

    /**
     * 生成クラスの短名と重なる名前でも、shapeのキー(サブフィールドの名前)とacf_fc_layoutのリテラル(レイアウトの名前)は
     * 変えず、型の参照だけを完全修飾名・別の名前にする。PHPDocのキー・リテラルが生成した正規化のキー・値と一致し、
     * 生成物はfixtures/expected-importsと一致して、PHPStan(level max)とphp-cs-fixerの対象で、PHPで読み込める。
     *
     * @param list<array<string, mixed>> $fields
     * @param list<string> $expected 生成物に含まれる、PHPDocの型と、それに対応する正規化のコード
     */
    #[DataProvider('namesLikeTheGeneratedClass')]
    public function testKeysAndLiteralsKeepNamesLikeTheGeneratedClass(string $namespace, string $className, array $fields, array $expected): void
    {
        $report = (new Generator())->generate([$this->group('group_names', 'product', $fields)], new GeneratorContext(
            config: new BridgeConfig(jsonDirs: [], outputDir: $this->createTempDirectory('acf-output'), namespace: $namespace, classMap: ['group_names' => $className]),
            optionsPagePostIds: [],
            sourceHash: 'imports',
        ));
        self::assertSame([], $report->errors());
        self::assertSame([], $report->warnings());
        $contents = $this->filesByPath($report->files())["{$className}.php"];
        $file = self::EXPECTED_IMPORTS . '/' . substr((string) strrchr($namespace, '\\'), 1) . "/{$className}.php";

        self::assertFileExists($file);
        self::assertSame($this->readFixture($file), $contents);

        foreach ($expected as $fragment) {
            self::assertStringContainsString($fragment, $contents);
        }

        $lint = self::runPhp(['-l', $file]);
        self::assertSame(0, $lint['exit'], $lint['output']);
        $load = self::runPhp(['-r', 'require $argv[1]; require $argv[2]; echo class_exists($argv[3], false) ? "loaded" : "missing";', self::composerAutoloadFile(), $file, "{$namespace}\\{$className}"]);
        self::assertSame(['exit' => 0, 'output' => 'loaded'], $load);
    }

    /** @return iterable<string, array{string, string, list<array<string, mixed>>, list<string>}> */
    public static function namesLikeTheGeneratedClass(): iterable
    {
        $image = static fn(string $name): array => ['key' => 'field_' . md5($name), 'name' => $name, 'label' => $name, 'type' => 'image'];
        $group = static fn(array $subFields): array => ['key' => 'field_box', 'name' => 'box', 'label' => 'box', 'type' => 'group', 'sub_fields' => $subFields];

        yield 'shape key like a type alias' => ['App\Imports\ShapeAliasKey', 'AcfImage', [$group([$image('AcfImage')])], [
            ' * @phpstan-import-type AcfImage from \TMSWP\AcfBridge\AcfTypes as AcfImageType',
            '@return array{AcfImage: AcfImageType|null}|null',
            "'AcfImage' => \$this->acfImageOf(\$group['AcfImage'] ?? null, \$path . '.AcfImage'),",
        ]];
        yield 'shape key like a class' => ['App\Imports\ShapeClassKey', 'ObjectType', [$group([self::text('ObjectType')])], [
            '@return array{ObjectType: string}|null',
            "'ObjectType' => \$this->acfStringOf(\$group['ObjectType'] ?? null, \$path . '.ObjectType'),",
            'return new self(\TMSWP\Core\Meta\ObjectType::Post->resolveId($post));',
        ]];
        yield 'quoted shape key containing a type alias' => ['App\Imports\ShapeQuotedKey', 'AcfImage', [$group([$image('name-AcfImage')])], [
            "@return array{'name-AcfImage': AcfImageType|null}|null",
            "'name-AcfImage' => \$this->acfImageOf(\$group['name-AcfImage'] ?? null, \$path . '.name-AcfImage'),",
        ]];
        yield 'layout name like a type alias' => ['App\Imports\LayoutLiteral', 'AcfImage', [['key' => 'field_sections', 'name' => 'sections', 'label' => 'sections', 'type' => 'flexible_content', 'layouts' => [['name' => 'AcfImage', 'sub_fields' => [$image('photo')]]]]], [
            " * @phpstan-type Sections_Acfimage array{acf_fc_layout: 'AcfImage', photo: AcfImageType|null}",
            '@return list<Sections_Acfimage>',
            "'AcfImage' => fn(array \$row, string \$path): array => [",
            "'acf_fc_layout' => 'AcfImage',",
        ]];
    }

    /**
     * 入れ子のサブフィールドのsetterは生成しないため、ほかに日付の型やlinkを使わないクラスでは、サブフィールドの
     * date_picker・date_time_picker・time_pickerのsetter用のDateTimeInterfaceと、link(return_format=url)のsetter用の
     * AcfLinkを取り込まない。生成物はfixtures/expected-importsと一致し、PHPStan(level max)とphp-cs-fixerの対象で、
     * PHPで読み込め、取り込むuseと型の別名がちょうど使うものになる。
     *
     * @param array<string, mixed> $subField
     */
    #[DataProvider('subFieldsWithSetterOnlyTypes')]
    public function testSubFieldSetterTypesAreNotImported(string $namespace, array $subField, string $docType): void
    {
        $report = (new Generator())->generate([FieldGroup::fromArray([
            'key' => 'group_box',
            'title' => 'group_box',
            'location' => [[['param' => 'options_page', 'operator' => '==', 'value' => 'box']]],
            'fields' => [['key' => 'field_box', 'name' => 'box', 'label' => 'box', 'type' => 'group', 'sub_fields' => [$subField]]],
        ], 'acf-json/group_box.json')], new GeneratorContext(
            config: new BridgeConfig(jsonDirs: [], outputDir: $this->createTempDirectory('acf-output'), namespace: $namespace),
            optionsPagePostIds: [],
            sourceHash: 'imports',
        ));
        self::assertSame([], $report->errors());
        self::assertSame([], $report->warnings());
        $contents = $this->filesByPath($report->files())['BoxOptions.php'];
        $file = self::EXPECTED_IMPORTS . '/' . substr((string) strrchr($namespace, '\\'), 1) . '/BoxOptions.php';

        self::assertFileExists($file);
        self::assertSame($this->readFixture($file), $contents);
        self::assertSame(['TMSWP\AcfBridge\AcfFieldAccess'], self::useStatements($contents));
        self::assertStringNotContainsString('@phpstan-import-type', $contents);
        self::assertStringContainsString("@return {$docType}", $contents);
        self::assertImportsAreUsed($file, $contents);

        $lint = self::runPhp(['-l', $file]);
        self::assertSame(0, $lint['exit'], $lint['output']);
        $load = self::runPhp(['-r', 'require $argv[1]; require $argv[2]; echo class_exists($argv[3], false) ? "loaded" : "missing";', self::composerAutoloadFile(), $file, "{$namespace}\\BoxOptions"]);
        self::assertSame(['exit' => 0, 'output' => 'loaded'], $load);
    }

    /** @return iterable<string, array{string, array<string, mixed>, string}> */
    public static function subFieldsWithSetterOnlyTypes(): iterable
    {
        yield 'date_picker' => ['App\Imports\NestedDatePicker', ['key' => 'field_day', 'name' => 'day', 'label' => 'day', 'type' => 'date_picker'], 'array{day: string}|null'];
        yield 'date_time_picker' => ['App\Imports\NestedDateTimePicker', ['key' => 'field_at', 'name' => 'at', 'label' => 'at', 'type' => 'date_time_picker'], 'array{at: string}|null'];
        yield 'time_picker' => ['App\Imports\NestedTimePicker', ['key' => 'field_time', 'name' => 'time', 'label' => 'time', 'type' => 'time_picker'], 'array{time: string}|null'];
        yield 'link with return_format url' => ['App\Imports\NestedLinkUrl', ['key' => 'field_url', 'name' => 'url', 'label' => 'url', 'type' => 'link', 'return_format' => 'url'], 'array{url: string|null}|null'];
    }

    /**
     * 生成物のfixture(expected-app・expected-imports)のuse文と`@phpstan-import-type`で取り込む名前は、どれもファイルの
     * ほかの場所で使われている(使わない取り込みが無い。使うのに取り込まない名前はPHPStanが検出する)。
     */
    public function testEveryImportOfTheGeneratedFixturesIsUsed(): void
    {
        $files = [];

        foreach ([self::EXPECTED_APP . '/*.php', self::EXPECTED_IMPORTS . '/*/*.php'] as $pattern) {
            $matched = glob($pattern);
            self::assertIsArray($matched);
            $files = [...$files, ...$matched];
        }

        self::assertCount(21, $files);

        foreach ($files as $file) {
            self::assertImportsAreUsed($file, $this->readFixture($file));
        }
    }

    /**
     * レイアウトの無いflexible_contentのgetterは`list<never>`を返すacfEmptyLayouts()を呼び、入れ子はacfEmptyLayoutsOf()を
     * 呼ぶ(PHPStanが型を照合できる形。fixtureのempty_sections・group_value.empty_blocks)。
     */
    public function testFlexibleContentWithoutLayoutsReturnsAnEmptyList(): void
    {
        $product = $this->filesByPath($this->generateFixture()->files())['ProductFields.php'];

        self::assertStringContainsString("     * @return list<never>\n     */\n    public function emptySections(): array\n    {\n        return \$this->acfEmptyLayouts(self::KEY_EMPTY_SECTIONS);\n", $product);
        self::assertStringContainsString("'empty_blocks' => \$this->acfEmptyLayoutsOf(\$group['empty_blocks'] ?? null, \$path . '.empty_blocks'),", $product);
        self::assertStringContainsString('empty_blocks: list<never>', $product);
    }

    /**
     * 生成したファクトリof()は、1未満のID(ACFが現在のループの投稿に置き換える0と、WordPressのメタの関数がabsint()で
     * 別のIDにする負の数)をInvalidArgumentExceptionにする。1以上のIDはACFのpost_idにする。
     *
     * @param Closure(int): (int|string) $acfIdOf IDでof()を呼び、そのacfId()を返す
     */
    #[DataProvider('factories')]
    public function testGeneratedFactoriesRejectIdsBelowOne(Closure $acfIdOf, int|string $expectedAcfId): void
    {
        foreach (['ProductFields', 'GenreTermFields', 'UserFields'] as $class) {
            require_once self::EXPECTED_APP . "/{$class}.php";
        }

        foreach ([0, -5] as $id) {
            try {
                $acfIdOf($id);
                self::fail("InvalidArgumentException was not thrown for {$id}");
            } catch (InvalidArgumentException $exception) {
                self::assertSame('meta object ID must be a positive integer', $exception->getMessage());
            }
        }

        self::assertSame($expectedAcfId, $acfIdOf(12));
    }

    /** @return iterable<string, array{Closure(int): (int|string), int|string}> */
    public static function factories(): iterable
    {
        yield 'post' => [static fn(int $id): int|string => ProductFields::of($id)->acfId(), 12];
        yield 'term' => [static fn(int $id): int|string => GenreTermFields::of($id)->acfId(), 'term_12'];
        yield 'user' => [static fn(int $id): int|string => UserFields::of($id)->acfId(), 'user_12'];
    }

    /**
     * post_type productのグループ(text・date_picker・post_object・image)から、classMapで$classNameにした生成クラスを$namespaceへ
     * 生成し、その内容を返す。
     */
    private function generateNamedClass(string $namespace, string $className): string
    {
        $report = (new Generator())->generate([$this->group('group_names', 'product', [
            self::text('title'),
            ['key' => 'field_starts', 'name' => 'starts', 'label' => 'starts', 'type' => 'date_picker'],
            ['key' => 'field_related', 'name' => 'related', 'label' => 'related', 'type' => 'post_object', 'return_format' => 'object'],
            ['key' => 'field_photo', 'name' => 'photo', 'label' => 'photo', 'type' => 'image', 'return_format' => 'array'],
        ])], new GeneratorContext(
            config: new BridgeConfig(jsonDirs: [], outputDir: $this->createTempDirectory('acf-output'), namespace: $namespace, classMap: ['group_names' => $className]),
            optionsPagePostIds: [],
            sourceHash: 'imports',
        ));

        self::assertSame([], $report->errors());
        self::assertSame([], $report->warnings());

        return $this->filesByPath($report->files())["{$className}.php"];
    }

    /**
     * 生成コードのuse文が取り込むクラスの完全修飾名(書かれた順)。
     *
     * @return list<string>
     */
    private static function useStatements(string $contents): array
    {
        preg_match_all('/^use ([A-Za-z0-9_\\\\]+);$/m', $contents, $matches);

        return $matches[1];
    }

    /** use文の短名と、`@phpstan-import-type`で取り込む名前(asの後の名前)が、取り込みの行のほかで使われている。 */
    private static function assertImportsAreUsed(string $file, string $contents): void
    {
        $names = array_map(static fn(string $class): string => substr((string) strrchr('\\' . $class, '\\'), 1), self::useStatements($contents));
        preg_match_all('/^ \* @phpstan-import-type (\w+) from \S+(?: as (\w+))?$/m', $contents, $aliases, PREG_SET_ORDER);

        foreach ($aliases as $alias) {
            $names[] = $alias[2] ?? $alias[1];
        }

        $body = (string) preg_replace(['/^use [^;]+;$/m', '/^ \* @phpstan-import-type .+$/m'], '', $contents);

        foreach ($names as $name) {
            self::assertSame(1, preg_match('/(?<![\w\\\\])' . preg_quote($name, '/') . '(?!\w)/', $body), "{$file}: {$name} is imported but not used");
        }
    }

    /**
     * このテストを実行しているPHPで$argumentsを実行し、終了コードと標準出力・標準エラーを返す。
     *
     * @param list<string> $arguments
     *
     * @return array{exit: int, output: string}
     */
    private static function runPhp(array $arguments): array
    {
        $process = proc_open([PHP_BINARY, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'output' => $output];
    }

    private function generateFixture(): Report
    {
        $json = (new AcfJsonRepository([__DIR__ . '/../../fixtures/acf-json']))->load();
        $config = new BridgeConfig(
            jsonDirs: [],
            outputDir: self::EXPECTED_APP,
            namespace: 'App\\Acf',
            methodNameMap: ['group_product.店舗名' => 'storeName'],
        );

        return (new Generator())->generate(
            $json->fieldGroups,
            new GeneratorContext(
                config: $config,
                optionsPagePostIds: $json->optionsPagePostIds,
                sourceHash: $json->sourceHash,
            ),
        );
    }

    /**
     * @param array<string, string> $methodNameMap
     * @param array<string, string> $optionsPagePostIds
     */
    private function context(array $methodNameMap = [], array $optionsPagePostIds = []): GeneratorContext
    {
        return new GeneratorContext(
            config: new BridgeConfig(jsonDirs: [], outputDir: $this->createTempDirectory('acf-output'), namespace: 'App\\Acf', methodNameMap: $methodNameMap),
            optionsPagePostIds: $optionsPagePostIds,
            sourceHash: 'abc123',
        );
    }

    /** @param array<string, string> $classMap */
    private function classMapContext(array $classMap): GeneratorContext
    {
        return new GeneratorContext(
            config: new BridgeConfig(jsonDirs: [], outputDir: $this->createTempDirectory('acf-output'), namespace: 'App\\Acf', classMap: $classMap),
            optionsPagePostIds: [],
            sourceHash: 'abc123',
        );
    }

    /** @return array<string, string> */
    private static function text(string $name): array
    {
        return ['key' => 'field_' . $name, 'name' => $name, 'label' => $name, 'type' => 'text'];
    }

    /** @return array<string, string> */
    private function expectedFixtureFiles(): array
    {
        $files = [];

        foreach (['AcfManifest', 'CloneSourceFields', 'GenreTermFields', 'ProductFields', 'ThemeSettingsOptions', 'UserFields'] as $class) {
            $files["{$class}.php"] = $this->readFixture(self::EXPECTED_APP . "/{$class}.php");
        }

        return $files;
    }

    private function readFixture(string $path): string
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            self::fail("fixture を読み込めません: {$path}");
        }

        return $contents;
    }

    /**
     * @param list<list<array{string, string}>> $andGroups ANDグループごとの`==`の条件([param, value])
     * @param list<array<string, mixed>> $fields
     */
    private function locatedGroup(string $key, array $andGroups, array $fields): FieldGroup
    {
        return FieldGroup::fromArray([
            'key' => $key,
            'title' => $key,
            'location' => array_map(
                static fn(array $conditions): array => array_map(static fn(array $condition): array => ['param' => $condition[0], 'operator' => '==', 'value' => $condition[1]], $conditions),
                $andGroups,
            ),
            'fields' => $fields,
        ], "acf-json/{$key}.json");
    }

    /**
     * ラベルにdocコメントを閉じる並びを含むフィールドの定義。
     *
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private static function commentField(string $key, string $name, string $type, array $extra = []): array
    {
        return ['key' => $key, 'name' => $name, 'label' => "{$name} *" . '/ label', 'type' => $type] + $extra;
    }

    /**
     * locationが$conditions(1つのANDグループの`==`の条件)で、フィールドが$fieldsのグループ(タイトルはkey)。
     *
     * @param list<array{string, string}> $conditions
     * @param array<string, mixed> ...$fields
     */
    private function commentGroup(string $key, array $conditions, array ...$fields): FieldGroup
    {
        return $this->locatedGroup($key, [$conditions], array_values($fields));
    }

    /** @param list<array<string, mixed>> $fields */
    private function group(string $key, string $postType, array $fields): FieldGroup
    {
        return FieldGroup::fromArray([
            'key' => $key,
            'title' => $key,
            'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => $postType]]],
            'fields' => $fields,
        ], "acf-json/{$key}.json");
    }

    /**
     * @param list<GeneratedFile> $files
     *
     * @return array<string, string>
     */
    private function filesByPath(array $files): array
    {
        $indexed = [];

        foreach ($files as $file) {
            $indexed[$file->relativePath] = $file->contents;
        }

        ksort($indexed, SORT_STRING);

        return $indexed;
    }
}
