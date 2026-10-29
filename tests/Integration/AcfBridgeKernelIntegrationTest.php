<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Tests\Integration;

use ACF_Local_JSON;
use TMSWP\AcfBridge\AcfBridge;
use TMSWP\AcfBridge\AcfMetaStorage;
use TMSWP\AcfBridge\BridgeConfig;
use TMSWP\AcfBridge\Json\AcfJsonRepository;
use TMSWP\AcfBridge\Json\FieldGroup;
use TMSWP\Core\Attribute\AsPostType;
use TMSWP\Core\Attribute\MetaSchema;
use TMSWP\Core\Exception\DuplicateMetaKeyException;
use TMSWP\Core\Kernel\DiscoveryFile;
use TMSWP\Core\Kernel\DiscoveryResult;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Meta\MetaSchemaRegistry;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Core\Meta\ObjectType;
use TMSWP\Core\Meta\WpMetaStorage;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Post\PostTypeDefinition;
use TMSWP\Testing\BootsKernelBeforeInit;
use TMSWP\Testing\IntegrationTestCase;
use TMSWP\Testing\UsesTempDirectories;

/**
 * fixtureのテーマとプラグインが、それぞれKernelConfigのextensionsにAcfBridgeを登録した状態を、有効化した無料版の
 * ACFで確かめる。テーマのacf-jsonはgroup_tmswp_acf_post(tmswp_acf_postのprice・subtitle)、プラグインのacf-jsonは
 * group_tmswp_plugin_post(postのtmswp_plugin_note)を持ち、生成物(app/Acf/AcfManifest.php等)は
 * `wp tmswp acf sync --app=...`で生成してある。
 */
final class AcfBridgeKernelIntegrationTest extends IntegrationTestCase
{
    use BootsKernelBeforeInit;
    use UsesTempDirectories;

    public function tear_down(): void
    {
        $this->cleanupTempDirectories();
        parent::tear_down();
    }

    /** テーマとプラグインのKernelに別々のAcfBridgeが登録され、構成はそれぞれのacf-json・app/Acf・名前空間を向く。 */
    public function testBridgeIsRegisteredPerKernelForThemeAndPlugin(): void
    {
        $theme = AcfBridge::forKernel(self::themeKernel());
        $plugin = AcfBridge::forKernel(self::pluginKernel());

        self::assertNotSame($theme, $plugin);
        self::assertSame(self::themeKernel(), $theme->kernel());
        self::assertSame(self::pluginKernel(), $plugin->kernel());
        self::assertSame([self::themeKernel()->config()->rootDir() . '/acf-json'], $theme->config()->jsonDirs);
        self::assertSame(get_stylesheet_directory() . '/acf-json', $theme->config()->jsonDirs[0]);
        self::assertSame([\dirname((string) self::pluginKernel()->config()->pluginFile) . '/acf-json'], $plugin->config()->jsonDirs);
        self::assertSame(self::THEME_NAMESPACE . '\Acf', $theme->config()->namespace);
        self::assertSame(self::PLUGIN_NAMESPACE . '\Acf', $plugin->config()->namespace);
        self::assertSame(self::pluginKernel()->config()->appDir . '/Acf', $plugin->config()->outputDir);
    }

    /**
     * テーマの既定のjsonDirsはテーマのルートのacf-jsonだけ(WordPressの関数を使わない)。子テーマを有効にしたときに
     * ACFが既定で読む`{stylesheet}/acf-json`(子テーマのacf-json)に同じkeyのJSONがあっても、ACFはAcfBridgeのjsonDirsの
     * ファイルを使い、生成(AcfJsonRepository)と同じファイルになる。子テーマのacf-jsonにだけあるグループはACFが読み込むが、
     * jsonDirsに明示しない限り生成しない(docs/09 §3.1)。ACFの既定の読み込み先は、ACFがload_jsonの設定に入れる値を
     * 子テーマのacf-jsonに見立てたディレクトリに差し替えて再現する。
     */
    public function testAcfDefaultStylesheetJsonDoesNotReplaceTheGeneratedFile(): void
    {
        $themeDir = AcfBridge::forKernel(self::themeKernel())->config()->jsonDirs[0];
        $childDir = $this->createTempDirectory('acf-child-theme') . '/acf-json';
        self::assertTrue(mkdir($childDir));
        $files = [
            $childDir . '/group_tmswp_child_shared.json' => 'child',
            $themeDir . '/group_tmswp_child_shared.json' => 'parent',
            $childDir . '/group_tmswp_child_only.json' => 'child only',
        ];
        $localJson = acf_get_instance('ACF_Local_JSON');
        self::assertInstanceOf(ACF_Local_JSON::class, $localJson);
        $acf = acf();
        self::assertIsObject($acf);
        $defaultLoadJson = $acf->get_setting('load_json');
        self::assertSame([get_stylesheet_directory() . '/acf-json'], $defaultLoadJson);

        try {
            foreach ($files as $file => $title) {
                $key = basename($file, '.json');
                $this->writeFile($file, '{"key": "' . $key . '", "title": "' . $title . '", "fields": [], "location": [], "active": true}');
            }

            acf_update_setting('load_json', [$childDir]);
            $localJson->scan_files();
            $acfFiles = acf_get_local_json_files();
            $generated = [];

            foreach ((new AcfJsonRepository(AcfBridge::forKernel(self::themeKernel())->config()->jsonDirs))->load()->fieldGroups as $group) {
                $generated[$group->key] = $group->sourceFile;
            }

            self::assertSame($themeDir . '/group_tmswp_child_shared.json', $acfFiles['group_tmswp_child_shared'] ?? null);
            self::assertSame($themeDir . '/group_tmswp_child_shared.json', $generated['group_tmswp_child_shared'] ?? null);
            self::assertSame($childDir . '/group_tmswp_child_only.json', $acfFiles['group_tmswp_child_only'] ?? null);
            self::assertArrayNotHasKey('group_tmswp_child_only', $generated);
        } finally {
            acf_update_setting('load_json', $defaultLoadJson);

            foreach (array_keys($files) as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }

            $localJson->scan_files();
        }

        self::assertArrayNotHasKey('group_tmswp_child_shared', acf_get_local_json_files());
        self::assertArrayNotHasKey('group_tmswp_child_only', acf_get_local_json_files());
    }

    /**
     * ACFが両方のacf-jsonを読み込み(acf/settings/load_json)、管理画面での保存先(acf/json/save_paths)は、そのグループを
     * 読んだKernelのacf-jsonになる。プラグインのグループがテーマのacf-jsonに書かれない。
     */
    public function testAcfLoadsAndSavesFieldGroupsInTheAcfJsonOfTheRegisteringKernel(): void
    {
        $themeDir = AcfBridge::forKernel(self::themeKernel())->config()->jsonDirs[0];
        $pluginDir = AcfBridge::forKernel(self::pluginKernel())->config()->jsonDirs[0];
        $files = acf_get_local_json_files();

        self::assertIsArray($files);
        self::assertSame($themeDir . '/group_tmswp_acf_post.json', $files['group_tmswp_acf_post'] ?? null);
        self::assertSame($pluginDir . '/group_tmswp_plugin_post.json', $files['group_tmswp_plugin_post'] ?? null);

        $default = [get_stylesheet_directory() . '/acf-json'];
        self::assertSame([$pluginDir], apply_filters('acf/json/save_paths', $default, ['key' => 'group_tmswp_plugin_post']));
        self::assertSame([$themeDir], apply_filters('acf/json/save_paths', $default, ['key' => 'group_tmswp_acf_post']));
        self::assertSame($default, apply_filters('acf/json/save_paths', $default, ['key' => 'group_tmswp_new']));

        // プラグインのacf-jsonのフィールドを、ACFが名前からfield keyへ解決できる
        $postId = self::createPost('post');
        self::assertTrue((bool) update_field('tmswp_plugin_note', 'from plugin', $postId));
        self::assertSame('from plugin', get_field('tmswp_plugin_note', $postId, false));
        self::assertSame('field_tmswp_plugin_post_note', get_post_meta($postId, '_tmswp_plugin_note', true));
    }

    /**
     * docs/12 v0.6.0「テーマとプラグインの両方でacf-bridgeを使える」。プラグインのKernelの名前空間へ生成した
     * アクセサ(TmswpFixturePlugin\Acf\PostFields)で、実物のACFの値を読み書き・削除できる。書いた値はACFの保存規約
     * (値のメタと参照のメタ)で保存され、ACFの読み出し(get_field())でも同じ値になる。
     */
    public function testPluginGeneratedAccessorReadsWritesAndDeletesThroughAcf(): void
    {
        $postId = self::createPost('post');
        $of = [self::PLUGIN_NAMESPACE . '\Acf\PostFields', 'of'];
        self::assertIsCallable($of);
        $fields = $of($postId);
        self::assertIsObject($fields);
        $get = [$fields, 'tmswpPluginNote'];
        $set = [$fields, 'setTmswpPluginNote'];
        $has = [$fields, 'hasTmswpPluginNote'];
        $delete = [$fields, 'deleteTmswpPluginNote'];
        self::assertIsCallable($get);
        self::assertIsCallable($set);
        self::assertIsCallable($has);
        self::assertIsCallable($delete);

        self::assertFalse($has());
        self::assertSame('', $get());

        $set('written by the plugin accessor');

        self::assertTrue($has());
        self::assertSame('written by the plugin accessor', $get());
        self::assertSame('written by the plugin accessor', get_field('tmswp_plugin_note', $postId, false));
        self::assertSame('written by the plugin accessor', get_post_meta($postId, 'tmswp_plugin_note', true));
        self::assertSame('field_tmswp_plugin_post_note', get_post_meta($postId, '_tmswp_plugin_note', true));

        self::assertTrue($delete());

        self::assertFalse($has());
        self::assertSame('', $get());
        self::assertFalse(metadata_exists('post', $postId, 'tmswp_plugin_note'));
        self::assertFalse(metadata_exists('post', $postId, '_tmswp_plugin_note'));
    }

    /**
     * テーマとプラグインのacf-jsonに同じkeyのJSONがあるとき、保存先は、ACFが実際に読み込んだファイルのディレクトリに
     * なり、もう一方のKernelのファイルを上書きしない。load_jsonの並びは、後にAcfBridgeを登録したテーマのKernelの
     * jsonDirsが後になるため、ACFはテーマのものを読み込む。
     */
    public function testSaveDirectoryFollowsTheFileAcfLoadedWhenTwoKernelsShareAKey(): void
    {
        $themeDir = AcfBridge::forKernel(self::themeKernel())->config()->jsonDirs[0];
        $pluginDir = AcfBridge::forKernel(self::pluginKernel())->config()->jsonDirs[0];
        $localJson = acf_get_instance('ACF_Local_JSON');
        self::assertInstanceOf(ACF_Local_JSON::class, $localJson);
        $files = [$themeDir . '/group_tmswp_shared.json', $pluginDir . '/group_tmswp_shared.json'];
        $group = ['key' => 'group_tmswp_shared', 'title' => 'Shared'];

        try {
            foreach ($files as $file) {
                self::assertNotFalse(file_put_contents($file, '{"key": "group_tmswp_shared", "title": "Shared", "fields": [], "location": [], "active": true}'));
            }

            $localJson->scan_files();

            self::assertSame($themeDir . '/group_tmswp_shared.json', acf_get_local_json_files()['group_tmswp_shared'] ?? null);
            self::assertSame([$themeDir], $localJson->get_save_paths('group_tmswp_shared', $group));
            self::assertSame([$themeDir], apply_filters('acf/json/save_paths', [$pluginDir], $group));
        } finally {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }

            $localJson->scan_files();
        }

        self::assertArrayNotHasKey('group_tmswp_shared', acf_get_local_json_files());
    }

    /**
     * 実物のACFは、同じkeyのJSONのうち、AcfBridgeのjsonDirsの先のディレクトリ(子テーマに当たる)のものを使い、生成
     * (AcfJsonRepository)と同じファイルになる。AcfBridgeのフィルタより前にload_jsonにあるディレクトリには勝ち、後に動く
     * フィルタが加えたディレクトリには負ける(docs/09 §3.1)。
     */
    public function testAcfUsesTheFileOfTheFirstJsonDirLikeTheGenerator(): void
    {
        $root = $this->createTempDirectory('acf-load-order');
        $dirs = ['child' => $root . '/child-json', 'parent' => $root . '/parent-json', 'earlier' => $root . '/earlier-json', 'later' => $root . '/later-json'];

        foreach ($dirs as $name => $dir) {
            self::assertTrue(mkdir($dir));
            $this->writeFile($dir . '/group_tmswp_load_order.json', '{"key": "group_tmswp_load_order", "title": "' . $name . '", "fields": [], "location": [], "active": true}');
        }

        add_filter('acf/settings/load_json', static fn(array $paths): array => [...$paths, $dirs['earlier']]);
        $this->bootBridgeKernel([$dirs['child'], $dirs['parent']]);
        $localJson = acf_get_instance('ACF_Local_JSON');
        self::assertInstanceOf(ACF_Local_JSON::class, $localJson);
        $later = static fn(array $paths): array => [...$paths, $dirs['later']];

        try {
            self::assertSame([$dirs['earlier'], $dirs['parent'], $dirs['child']], \array_slice($localJson->get_load_paths(), -3));
            $localJson->scan_files();
            self::assertSame($dirs['child'] . '/group_tmswp_load_order.json', acf_get_local_json_files()['group_tmswp_load_order'] ?? null);
            self::assertSame(
                $dirs['child'] . '/group_tmswp_load_order.json',
                (new AcfJsonRepository([$dirs['child'], $dirs['parent']]))->load()->fieldGroups[0]->sourceFile,
            );

            add_filter('acf/settings/load_json', $later, 20);
            $localJson->scan_files();
            self::assertSame($dirs['later'] . '/group_tmswp_load_order.json', acf_get_local_json_files()['group_tmswp_load_order'] ?? null);
        } finally {
            remove_filter('acf/settings/load_json', $later, 20);

            foreach ($dirs as $dir) {
                if (is_file($dir . '/group_tmswp_load_order.json')) {
                    unlink($dir . '/group_tmswp_load_order.json');
                }
            }

            $localJson->scan_files();
        }

        self::assertArrayNotHasKey('group_tmswp_load_order', acf_get_local_json_files());
    }

    /**
     * 実物のACFがフィールドグループとして読むJSON(keyがgroup_で始まるもの、keyの接頭辞を問わずfieldsを持つもの)と、
     * 生成(AcfJsonRepository)がグループとして読むJSONが一致する(docs/09 §3.2)。
     */
    public function testAcfAndTheGeneratorReadTheSameJsonAsFieldGroups(): void
    {
        $dir = $this->createTempDirectory('acf-kinds') . '/acf-json';
        self::assertTrue(mkdir($dir));
        $fields = '"fields": [{"key": "field_tmswp_kinds_%1$s", "name": "tmswp_kinds_%1$s", "label": "%1$s", "type": "text"}]';
        $files = [
            'group_tmswp_kinds_plain' => '',
            'post_type_tmswp_kinds_fields' => \sprintf($fields, 'post_type'),
            'post_type_tmswp_kinds_bare' => '"post_type": "tmswp_kinds"',
            'taxonomy_tmswp_kinds_fields' => \sprintf($fields, 'taxonomy'),
            'taxonomy_tmswp_kinds_bare' => '"taxonomy": "tmswp_kinds"',
            'ui_options_page_tmswp_kinds_fields' => \sprintf($fields, 'options') . ', "menu_slug": "tmswp-kinds", "post_id": "tmswp_kinds"',
            'ui_options_page_tmswp_kinds_bare' => '"menu_slug": "tmswp-kinds-bare", "post_id": "tmswp_kinds_bare"',
            'custom_tmswp_kinds_fields' => \sprintf($fields, 'custom'),
            'custom_tmswp_kinds_bare' => '"title": "bare"',
        ];

        foreach ($files as $key => $body) {
            $this->writeFile("{$dir}/{$key}.json", '{"key": "' . $key . '", "title": "' . $key . '"' . ($body === '' ? '' : ', ' . $body) . '}');
        }

        $this->bootBridgeKernel([$dir]);
        $localJson = acf_get_instance('ACF_Local_JSON');
        self::assertInstanceOf(ACF_Local_JSON::class, $localJson);

        try {
            $localJson->scan_files();
            $acfGroups = array_values(array_filter(array_keys(acf_get_local_json_files()), static fn(string $key): bool => str_contains($key, 'tmswp_kinds')));
            sort($acfGroups);
            $json = (new AcfJsonRepository([$dir]))->load();

            self::assertSame([
                'custom_tmswp_kinds_fields',
                'group_tmswp_kinds_plain',
                'post_type_tmswp_kinds_fields',
                'taxonomy_tmswp_kinds_fields',
                'ui_options_page_tmswp_kinds_fields',
            ], $acfGroups);
            self::assertSame($acfGroups, array_map(static fn(FieldGroup $group): string => $group->key, $json->fieldGroups));
            self::assertSame(['tmswp-kinds' => 'tmswp_kinds', 'tmswp-kinds-bare' => 'tmswp_kinds_bare'], $json->optionsPagePostIds);
        } finally {
            foreach (array_keys($files) as $key) {
                unlink("{$dir}/{$key}.json");
            }

            $localJson->scan_files();
        }
    }

    /**
     * 管理キーは、そのグループを持つKernelのレジストリにだけ申告される(init 6)。宣言の無い管理キーはregister_*_meta
     * されない。
     */
    public function testManagedKeysAreMarkedInTheRegistryOfTheRegisteringKernel(): void
    {
        $themeRegistry = self::themeKernel()->container()->get(MetaSchemaRegistry::class);
        $pluginRegistry = self::pluginKernel()->container()->get(MetaSchemaRegistry::class);

        self::assertTrue($themeRegistry->isExternallyManaged(ObjectType::Post, 'tmswp_acf_post', 'subtitle'));
        self::assertFalse($pluginRegistry->isExternallyManaged(ObjectType::Post, 'tmswp_acf_post', 'subtitle'));
        self::assertTrue($pluginRegistry->isExternallyManaged(ObjectType::Post, 'post', 'tmswp_plugin_note'));
        self::assertFalse($themeRegistry->isExternallyManaged(ObjectType::Post, 'post', 'tmswp_plugin_note'));
        self::assertFalse(registered_meta_key_exists('post', 'subtitle', 'tmswp_acf_post'));
        self::assertFalse(registered_meta_key_exists('post', 'tmswp_plugin_note', 'post'));
    }

    /**
     * ACFのフィールドグループで管理するキー(price)を、#[MetaSchema]と#[UseMetaStorage(AcfMetaStorage::class)]で
     * 宣言したエンティティ(AcfStoredPost)は、申告と衝突せずに起動し、そのキーは宣言どおりregister_post_metaされる。
     * MetaBagの読み書きはACFを通る。
     */
    public function testAcfManagedKeyCanBeDeclaredWithMetaSchemaAndAcfMetaStorage(): void
    {
        $postClass = self::fixtureClass(self::THEME_NAMESPACE . '\Acf\AcfStoredPost', PostType::class);
        $registry = self::themeKernel()->container()->get(MetaSchemaRegistry::class);

        self::assertTrue($registry->isExternallyManaged(ObjectType::Post, 'tmswp_acf_post', 'price'));
        self::assertTrue($registry->schemasFor($postClass)->has('price'));
        self::assertTrue(registered_meta_key_exists('post', 'price', 'tmswp_acf_post'));

        $postId = self::createPost('tmswp_acf_post');
        self::assertTrue($postClass::meta($postId)->set('price', '1500'));
        self::assertSame('1500', get_field('price', $postId, false));
        self::assertSame('field_tmswp_acf_post_price', get_post_meta($postId, '_price', true));
    }

    /**
     * 同じ管理キーをAcfMetaStorageでないエンティティが宣言していれば、申告がDuplicateMetaKeyExceptionになる。
     * メッセージは、衝突したキー・(ObjectType, サブタイプ)・宣言クラスと定数名・ACFのフィールドグループのkeyを含む。
     */
    public function testAcfManagedKeyDeclaredWithAnotherStorageIsRejected(): void
    {
        $registry = new MetaSchemaRegistry(self::themeKernel()->container());
        $registry->registerEntity(IntegrationWpStoredAcfPost::class, ObjectType::Post, 'tmswp_acf_post');

        try {
            AcfBridge::forKernel(self::themeKernel())->markManagedKeys($registry);
            self::fail('DuplicateMetaKeyException was not thrown');
        } catch (DuplicateMetaKeyException $exception) {
            self::assertSame(
                "externally managed meta key 'price' for post:tmswp_acf_post is already declared by " . IntegrationWpStoredAcfPost::class . '::PRICE'
                . ' with meta storage ' . WpMetaStorage::class . "; ACF field group 'group_tmswp_acf_post' manages it with meta storage " . AcfMetaStorage::class
                . '. Add #[UseMetaStorage(\\' . AcfMetaStorage::class . '::class)] to ' . IntegrationWpStoredAcfPost::class
                . ", remove the #[MetaSchema] constant PRICE, or remove the key from ACF field group 'group_tmswp_acf_post'",
                $exception->getMessage(),
            );
        }
    }

    /**
     * jsonDirsが$jsonDirsのAcfBridgeを登録した、本番相当のプラグイン形態のKernel(コンポーネントは無い)をinitより前に
     * bootする。initは発火しない(管理キーの申告は予約だけで、AcfManifestを要しない)。
     *
     * @param list<string> $jsonDirs
     */
    private function bootBridgeKernel(array $jsonDirs): void
    {
        $root = $this->createTempDirectory('acf-load-order-kernel');
        self::assertTrue(mkdir($root . '/app'));
        $this->writeFile($root . '/plugin.php', "<?php\n");
        $namespace = 'TmswpAcfLoadOrder' . uniqid();
        $config = KernelConfig::plugin(
            $root . '/plugin.php',
            $root . '/app',
            $namespace,
            false,
            [new AcfBridge(new BridgeConfig(jsonDirs: $jsonDirs, outputDir: $root . '/app/Acf', namespace: $namespace . '\\Acf'))],
        );
        (new DiscoveryFile($config))->write(new DiscoveryResult([], []));
        $this->bootBeforeInit($config);
    }
}

#[AsPostType]
final class IntegrationWpStoredAcfPost extends PostType
{
    #[MetaSchema(type: MetaValueType::String)]
    public const PRICE = 'price';

    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('tmswp_acf_post');
    }
}
