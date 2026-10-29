<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Tests\Unit\Cli;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use RuntimeException;
use TMSWP\AcfBridge\Cli\AcfCommand;
use TMSWP\AcfBridge\Tests\Support\BootsAcfBridgeKernel;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Meta\MetaSchemaRegistry;
use TMSWP\Core\Meta\ObjectType;
use TMSWP\Testing\UnitTestCase;

/**
 * `wp tmswp acf sync|check`の対象の選択(--app=)、E101、オプションページのpost_id。WP_CLIの代役クラスをテスト用の
 * 一時ファイルに書いて読み込むため、他のテストにWP_CLIクラスを残さないよう別プロセスで動かす。代役は呼び出しを
 * $GLOBALS['tmswp_test_wp_cli']に記録し、WP_CLI::halt()は終了の代わりに、終了コードをcodeに持つ例外を投げる。
 */
final class AcfCommandTest extends UnitTestCase
{
    use BootsAcfBridgeKernel;

    private const PRODUCT_GROUP = <<<'JSON'
{
    "key": "group_product",
    "title": "Product",
    "location": [[{"param": "post_type", "operator": "==", "value": "cmd_product"}]],
    "fields": [
        {"key": "field_price", "name": "price", "label": "Price", "type": "text"},
        {"key": "field_common_clone", "name": "common", "label": "Common", "type": "clone", "clone": ["group_common"], "display": "seamless"}
    ]
}
JSON;

    private const COMMON_GROUP = <<<'JSON'
{
    "key": "group_common",
    "title": "Common",
    "location": [[{"param": "post_type", "operator": "==", "value": "cmd_common"}]],
    "fields": [
        {"key": "field_cloned_note", "name": "cloned_note", "label": "Cloned note", "type": "text"}
    ]
}
JSON;

    private const OPTIONS_GROUP = <<<'JSON'
{
    "key": "group_options",
    "title": "Options",
    "location": [[{"param": "options_page", "operator": "==", "value": "theme-settings"}]],
    "fields": [
        {"key": "field_tel", "name": "tel", "label": "Tel", "type": "text"}
    ]
}
JSON;

    private const PRODUCT_POST = <<<'PHP'
<?php

declare(strict_types=1);

namespace {namespace}\Post;

use TMSWP\Core\Attribute\AsPostType;
use TMSWP\Core\Attribute\MetaSchema;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Post\PostTypeDefinition;

#[AsPostType]
final class ProductPost extends PostType
{
    #[MetaSchema(type: MetaValueType::String)]
    public const PRICE = 'price';

    #[MetaSchema(type: MetaValueType::String)]
    public const CLONED_NOTE = 'cloned_note';

    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('cmd_product');
    }
}
PHP;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_version'] = '7.0';
        $GLOBALS['wp_actions'] = [];
        $GLOBALS['tmswp_test_wp_cli'] = [];
    }

    protected function tearDown(): void
    {
        Kernel::reset();
        unset($GLOBALS['wp_version'], $GLOBALS['wp_actions'], $GLOBALS['tmswp_test_wp_cli']);
        parent::tearDown();
    }

    /**
     * AcfBridgeを登録したKernelが2つあれば --app= が必須で、--app= で選んだKernelの構成だけを生成・照合する。
     */
    #[RunInSeparateProcess]
    public function testSyncAndCheckTargetTheKernelSelectedByApp(): void
    {
        $this->loadWpCliDouble();
        $alpha = $this->bootAcfBridgeKernel('Alpha', [], ['group_common.json' => self::COMMON_GROUP]);
        $beta = $this->bootAcfBridgeKernel('Beta', [], ['group_common.json' => self::COMMON_GROUP]);
        $command = new AcfCommand();

        self::assertSame(2, $this->halted(static fn() => $command->check([], [])));
        self::assertSame(['line', "--app=<namespace> is required because AcfBridge is registered to more than one kernel: {$alpha['namespace']}, {$beta['namespace']}"], $this->calls()[0]);

        self::assertSame(2, $this->halted(static fn() => $command->check([], ['app' => 'Unknown\App'])));

        $command->sync([], ['app' => $alpha['namespace']]);
        self::assertSame(['success', 'ACF bridge sync completed'], $this->lastCall());
        self::assertFileExists($alpha['root'] . '/app/Acf/AcfManifest.php');
        self::assertFileExists($alpha['root'] . '/app/Acf/CmdCommonFields.php');
        self::assertDirectoryDoesNotExist($beta['root'] . '/app/Acf');

        $command->check([], ['app' => '\\' . $alpha['namespace'], 'strict' => true]);
        [$method, $message] = $this->lastCall();
        self::assertSame('success', $method);
        self::assertIsString($message);
        self::assertStringStartsWith('ACF bridge is in sync. (2 files, source-hash sha256:', $message);

        self::assertSame(1, $this->halted(static fn() => $command->check([], ['app' => $beta['namespace']])));
        self::assertContains(['line', 'missing: AcfManifest.php'], $this->calls());
    }

    /**
     * checkは、#[MetaSchema]で宣言済み(AcfMetaStorageでない)のキーを、seamlessのcloneが展開したキーも含めてE101に
     * し、exit 2で終わる。メッセージは実行時の申告と同じで、宣言クラスと定数名・ACFのフィールドグループのkeyを含む。
     * syncはE101があればファイルを書かずにexit 1で終わる。
     */
    #[RunInSeparateProcess]
    public function testE101ReportsDeclaredKeysIncludingSeamlessCloneKeys(): void
    {
        $this->loadWpCliDouble();
        $app = $this->bootAcfBridgeKernel('Conflict', ['Post/ProductPost.php' => self::PRODUCT_POST], [
            'group_product.json' => self::PRODUCT_GROUP,
            'group_common.json' => self::COMMON_GROUP,
        ]);
        $entity = $app['namespace'] . '\Post\ProductPost';
        $command = new AcfCommand();

        self::assertSame(2, $this->halted(static fn() => $command->check([], [])));
        $lines = array_column($this->calls(), 1);
        self::assertContains(
            "E101: externally managed meta key 'cloned_note' for post:cmd_product is already declared by {$entity}::CLONED_NOTE with meta storage TMSWP\\Core\\Meta\\WpMetaStorage;"
            . " ACF field group 'group_product' manages it with meta storage TMSWP\\AcfBridge\\AcfMetaStorage."
            . " Add #[UseMetaStorage(\\TMSWP\\AcfBridge\\AcfMetaStorage::class)] to {$entity}, remove the #[MetaSchema] constant CLONED_NOTE, or remove the key from ACF field group 'group_product'",
            $lines,
        );
        self::assertCount(2, array_filter($lines, static fn(mixed $line): bool => \is_string($line) && str_starts_with($line, 'E101: ')));

        $GLOBALS['tmswp_test_wp_cli'] = [];
        self::assertSame(1, $this->halted(static fn() => $command->sync([], [])));
        self::assertDirectoryDoesNotExist($app['root'] . '/app/Acf');
    }

    /**
     * checkのE101は、--app=で選んだKernelだけでなく起動中の全Kernelの宣言と照合する(選んだKernelには無い、別のKernelの
     * 宣言との衝突も検出する)。照合はレジストリの複製に申告するため、checkの前後で各Kernelの実際のレジストリは変わらない。
     */
    #[RunInSeparateProcess]
    public function testE101ChecksAllKernelsWithoutChangingTheirRegistries(): void
    {
        $this->loadWpCliDouble();
        $selected = $this->bootAcfBridgeKernel('Selected', [], [
            'group_product.json' => self::PRODUCT_GROUP,
            'group_common.json' => self::COMMON_GROUP,
        ]);
        $other = $this->bootAcfBridgeKernel('Other', ['Post/ProductPost.php' => self::PRODUCT_POST]);
        $command = new AcfCommand();
        $selectedRegistry = $selected['kernel']->container()->get(MetaSchemaRegistry::class);
        $otherRegistry = $other['kernel']->container()->get(MetaSchemaRegistry::class);
        $before = [
            $selectedRegistry->isExternallyManaged(ObjectType::Post, 'cmd_product', 'price'),
            $otherRegistry->isExternallyManaged(ObjectType::Post, 'cmd_product', 'price'),
            $selectedRegistry->isExternallyManaged(ObjectType::Post, 'cmd_common', 'cloned_note'),
        ];

        self::assertSame(2, $this->halted(static fn() => $command->check([], ['app' => $selected['namespace']])));

        $lines = array_column($this->calls(), 1);
        self::assertContains(
            "E101: externally managed meta key 'price' for post:cmd_product is already declared by {$other['namespace']}\\Post\\ProductPost::PRICE with meta storage TMSWP\\Core\\Meta\\WpMetaStorage;"
            . " ACF field group 'group_product' manages it with meta storage TMSWP\\AcfBridge\\AcfMetaStorage."
            . " Add #[UseMetaStorage(\\TMSWP\\AcfBridge\\AcfMetaStorage::class)] to {$other['namespace']}\\Post\\ProductPost, remove the #[MetaSchema] constant PRICE, or remove the key from ACF field group 'group_product'",
            $lines,
        );
        self::assertSame([false, false, false], $before);
        self::assertSame($before, [
            $selectedRegistry->isExternallyManaged(ObjectType::Post, 'cmd_product', 'price'),
            $otherRegistry->isExternallyManaged(ObjectType::Post, 'cmd_product', 'price'),
            $selectedRegistry->isExternallyManaged(ObjectType::Post, 'cmd_common', 'cloned_note'),
        ]);
    }

    /**
     * オプションページのpost_idはacf-jsonのui_options_pageとBridgeConfigのoptionsPagePostIdsだけで決め(ACFの実行時に
     * 問い合わせない)、両方が違う値を指定したらexit 2にする。
     */
    #[RunInSeparateProcess]
    public function testOptionsPagePostIdComesFromJsonOrConfig(): void
    {
        $this->loadWpCliDouble();
        $configured = $this->bootAcfBridgeKernel('Options', [], ['group_options.json' => self::OPTIONS_GROUP], ['theme-settings' => 'theme_settings']);
        $conflicting = $this->bootAcfBridgeKernel('OptionsConflict', [], [
            'group_options.json' => self::OPTIONS_GROUP,
            'ui_options_page_theme.json' => '{"key": "ui_options_page_theme", "menu_slug": "theme-settings", "post_id": "theme_json"}',
        ], ['theme-settings' => 'theme_settings']);
        $command = new AcfCommand();

        $command->sync([], ['app' => $configured['namespace']]);
        self::assertStringContainsString("'theme_settings'", (string) file_get_contents($configured['root'] . '/app/Acf/ThemeSettingsOptions.php'));

        self::assertSame(2, $this->halted(static fn() => $command->sync([], ['app' => $conflicting['namespace']])));
        self::assertContains(['line', "E102: options page 'theme-settings' has post_id 'theme_json' in acf-json and 'theme_settings' in BridgeConfig optionsPagePostIds"], $this->calls());
    }

    /**
     * acf-jsonの読み込みのE102(トップレベルが配列のJSON)は、syncもcheckもexit 2で止め、ファイルを書かない。読み込みの
     * 警告(W001)は生成の警告と一緒に出し、check --strictはexit 1にする。
     */
    #[RunInSeparateProcess]
    public function testReadErrorsAndWarningsOfAcfJsonAreReported(): void
    {
        $this->loadWpCliDouble();
        $listed = $this->bootAcfBridgeKernel('Listed', [], ['groups.json' => '[' . self::COMMON_GROUP . ']']);
        $ignored = $this->bootAcfBridgeKernel('Ignored', [], ['group_common.json' => self::COMMON_GROUP, 'note.json' => '"note"']);
        $command = new AcfCommand();

        self::assertSame(2, $this->halted(static fn() => $command->sync([], ['app' => $listed['namespace']])));
        self::assertSame(2, $this->halted(static fn() => $command->check([], ['app' => $listed['namespace']])));
        [$method, $message] = $this->calls()[0];
        self::assertSame('line', $method);
        self::assertIsString($message);
        self::assertStringStartsWith("E102: ACF JSON file {$listed['root']}/acf-json/groups.json has an array at the top level;", $message);
        self::assertDirectoryDoesNotExist($listed['root'] . '/app/Acf');

        $command->sync([], ['app' => $ignored['namespace']]);
        self::assertContains(['line', "W001: ACF JSON file {$ignored['root']}/acf-json/note.json was ignored because its top level is string, not an object"], $this->calls());
        self::assertSame(1, $this->halted(static fn() => $command->check([], ['app' => $ignored['namespace'], 'strict' => true])));
    }

    /**
     * @param callable(): void $run
     *
     * @return int WP_CLI::halt()の終了コード
     */
    private function halted(callable $run): int
    {
        try {
            $run();
        } catch (RuntimeException $exception) {
            self::assertSame('halt', $exception->getMessage());

            return $exception->getCode();
        }

        self::fail('WP_CLI::halt() was not called');
    }

    /** @return list<array{0: string, 1: mixed}> */
    private function calls(): array
    {
        $recorded = $GLOBALS['tmswp_test_wp_cli'];
        self::assertIsArray($recorded);
        $calls = [];

        foreach ($recorded as $call) {
            self::assertIsArray($call);
            self::assertIsString($call[0] ?? null);
            $calls[] = [$call[0], $call[1] ?? null];
        }

        return $calls;
    }

    /** @return array{0: string, 1: mixed} */
    private function lastCall(): array
    {
        $calls = $this->calls();
        self::assertNotSame([], $calls);

        return $calls[array_key_last($calls)];
    }

    private function loadWpCliDouble(): void
    {
        $file = $this->createTempDirectory('wp-cli-double') . '/WP_CLI.php';
        $this->writeTestFile($file, <<<'PHP'
<?php

declare(strict_types=1);

final class WP_CLI
{
    public static function line(string $message): void
    {
        $GLOBALS['tmswp_test_wp_cli'][] = ['line', $message];
    }

    public static function success(string $message): void
    {
        $GLOBALS['tmswp_test_wp_cli'][] = ['success', $message];
    }

    public static function warning(string $message): void
    {
        $GLOBALS['tmswp_test_wp_cli'][] = ['warning', $message];
    }

    public static function halt(int $status): never
    {
        $GLOBALS['tmswp_test_wp_cli'][] = ['halt', $status];

        throw new \RuntimeException('halt', $status);
    }
}
PHP);

        require $file;
    }
}
