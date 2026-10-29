<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Closure;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use TMSWP\AcfBridge\AcfBridge;
use TMSWP\AcfBridge\AcfMetaStorage;
use TMSWP\AcfBridge\BridgeConfig;
use TMSWP\AcfBridge\Exception\AcfBridgeRuntimeException;
use TMSWP\AcfBridge\Tests\Support\BootsAcfBridgeKernel;
use TMSWP\Core\Attribute\AsPostType;
use TMSWP\Core\Attribute\MetaSchema;
use TMSWP\Core\Container\Container;
use TMSWP\Core\Exception\DuplicateMetaKeyException;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Meta\MetaSchemaRegistry;
use TMSWP\Core\Meta\MetaStorageInterface;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Core\Meta\ObjectType;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Post\PostTypeDefinition;
use TMSWP\Testing\MemoryMetaStorage;
use TMSWP\Testing\UnitTestCase;

/**
 * AcfBridgeのKernelごとの登録と、管理キーの申告。
 */
final class AcfBridgeTest extends UnitTestCase
{
    use BootsAcfBridgeKernel;

    private const MANIFEST = <<<'PHP'
<?php

declare(strict_types=1);

namespace {namespace}\Acf;

use TMSWP\AcfBridge\Manifest\ManagedKey;
use TMSWP\Core\Meta\ObjectType;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Post\PostTypeDefinition;

final class AcfManifest
{
    /** @return list<ManagedKey> */
    public static function managedKeys(): array
    {
        return [
            new ManagedKey(ObjectType::Post, 'acf_unit_product', 'price', 'field_price', 'group_product'),
            new ManagedKey(ObjectType::Term, 'all', 'term_color', 'field_color', 'group_terms'),
            new ManagedKey(null, 'theme-settings', 'site_logo', 'field_logo', 'group_options'),
        ];
    }
}
PHP;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_version'] = '7.0';
        $GLOBALS['wp_actions'] = [];
    }

    protected function tearDown(): void
    {
        Kernel::reset();
        unset($GLOBALS['wp_version'], $GLOBALS['wp_actions'], $GLOBALS['tmswp_test_wp_cli'], $GLOBALS['tmswp_test_wp_cli_arguments']);
        parent::tearDown();
    }

    /**
     * KernelConfigのextensionsに渡したAcfBridgeは、bootで登録したKernelと構成を持ち、そのKernelのContainerと
     * forKernel()から引ける。debugは登録したKernelの構成に従う。
     */
    public function testRegisterBindsTheBridgeToTheRegisteringKernel(): void
    {
        $app = $this->bootAcfBridgeKernel('Bind', ['Acf/AcfManifest.php' => self::MANIFEST]);

        self::assertSame($app['kernel'], $app['bridge']->kernel());
        self::assertSame($app['namespace'] . '\Acf', $app['bridge']->config()->namespace);
        self::assertSame($app['bridge'], AcfBridge::forKernel($app['kernel']));
        self::assertSame($app['bridge'], $app['kernel']->container()->get(AcfBridge::class));
        self::assertTrue($app['bridge']->kernel()->config()->debug);
    }

    /** 1つのインスタンスを2つのKernelに登録すると、2つ目のbootが例外になる。 */
    public function testOneInstanceCannotBeRegisteredToTwoKernels(): void
    {
        $app = $this->bootAcfBridgeKernel('Shared', ['Acf/AcfManifest.php' => self::MANIFEST]);
        $otherDir = $this->createTempDirectory('acf-bridge-other') . '/app';
        self::assertTrue(mkdir($otherDir));

        $this->expectException(AcfBridgeRuntimeException::class);
        $this->expectExceptionMessage("this AcfBridge instance is already registered to the kernel '{$app['namespace']}'");

        Kernel::boot(KernelConfig::theme($otherDir, 'Example\Tests\AcfBridgeOther' . uniqid(), true, [$app['bridge']]));
    }

    /** 生成クラスの名前空間が、登録したKernelのアプリの名前空間の外なら、bootが例外になる。 */
    public function testNamespaceOutsideTheAppIsRejected(): void
    {
        $appDir = $this->createTempDirectory('acf-bridge-outside') . '/app';
        self::assertTrue(mkdir($appDir));
        $namespace = 'Example\Tests\AcfBridgeOutside' . uniqid();
        $bridge = new AcfBridge(new BridgeConfig([], $appDir . '/Acf', 'Other\Acf'));

        $this->expectException(AcfBridgeRuntimeException::class);
        $this->expectExceptionMessage("ACF bridge namespace 'Other\\Acf' must be inside the app namespace '{$namespace}'");

        Kernel::boot(KernelConfig::theme($appDir, $namespace, true, [$bridge]));
    }

    public function testForKernelRejectsKernelWithoutBridge(): void
    {
        $appDir = $this->createTempDirectory('acf-bridge-none') . '/app';
        self::assertTrue(mkdir($appDir));
        $kernel = Kernel::boot(KernelConfig::theme($appDir, 'Example\Tests\AcfBridgeNone' . uniqid(), true));

        $this->expectException(AcfBridgeRuntimeException::class);
        $this->expectExceptionMessage('AcfBridge is not registered to the kernel');

        AcfBridge::forKernel($kernel);
    }

    /**
     * acf/settings/load_jsonには存在するjsonDirsを1回ずつ加える。acf/json/save_paths(ACFが読み込んだファイルで保存先を
     * 決める)と、ACFの版の確認(plugins_loaded)も登録する。保存先の振る舞いは、実物のACFの統合テスト(AcfBridgeKernelIntegrationTest)で確かめる。
     */
    public function testAcfJsonFiltersAreRegisteredForTheJsonDirsOfTheBridge(): void
    {
        $loadJson = null;
        Filters\expectAdded('acf/settings/load_json')->once()->whenHappen(static function (Closure $callback) use (&$loadJson): void {
            $loadJson = $callback;
        });
        Filters\expectAdded('acf/json/save_paths')->once()->with(Mockery::type(Closure::class), 10, 2);
        // ACFの版の確認は、すべてのプラグインを読み込んだ後(plugins_loaded)に予約する
        Actions\expectAdded('plugins_loaded')->once()->with(Mockery::type(Closure::class), 10, 0);
        $app = $this->bootAcfBridgeKernel('Filters', ['Acf/AcfManifest.php' => self::MANIFEST]);
        $jsonDir = $app['root'] . '/acf-json';

        self::assertInstanceOf(Closure::class, $loadJson);
        self::assertSame(['/theme/acf-json', $jsonDir], $loadJson(['/theme/acf-json']));
        self::assertSame([$jsonDir], $loadJson([$jsonDir]));
    }

    /**
     * ACFは同じkeyのJSONのうちload_jsonの後のディレクトリのものを使うため、jsonDirs(先のディレクトリのものを使う)を
     * 逆順でload_jsonの末尾に置く。並びに既にあるもの(ACFの既定の子テーマのacf-json等)は末尾へ移し、ほかの
     * ディレクトリ(存在しないjsonDirsを含む)は元の順で前に残す。
     */
    public function testLoadJsonPutsTheFirstJsonDirLast(): void
    {
        $loadJson = null;
        Filters\expectAdded('acf/settings/load_json')->once()->whenHappen(static function (Closure $callback) use (&$loadJson): void {
            $loadJson = $callback;
        });
        $app = $this->bootAcfBridgeKernel('Order', ['Acf/AcfManifest.php' => self::MANIFEST], moreJsonDirs: ['parent-json', 'missing-json']);
        $child = $app['root'] . '/acf-json';
        $parent = $app['root'] . '/parent-json';
        self::assertTrue(rmdir($app['root'] . '/missing-json'));

        self::assertInstanceOf(Closure::class, $loadJson);
        self::assertSame([$parent, $child], $loadJson([]));
        self::assertSame(['/plugin/acf-json', '/other/acf-json', $parent, $child], $loadJson([$child . '/', '/plugin/acf-json', $parent, '/other/acf-json', $child]));
    }

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function acfVersionProvider(): iterable
    {
        yield '6.1.8は下限より古い' => ['6.1.8', false];
        yield '6.0.0は下限より古い' => ['6.0.0', false];
        yield '6.2は下限' => ['6.2', true];
        yield '6.2.0は下限' => ['6.2.0', true];
        yield '6.8.10は対応する' => ['6.8.10', true];
        yield '文字列でない版' => [6.2, false];
    }

    /** 有効なACFの版が6.2より古ければ、設定の誤りとして例外にする。 */
    #[DataProvider('acfVersionProvider')]
    public function testAssertSupportedAcfVersion(mixed $version, bool $supported): void
    {
        if (!$supported) {
            $this->expectException(AcfBridgeRuntimeException::class);
            $this->expectExceptionMessage('tmswp/acf-bridge requires Advanced Custom Fields (ACF) 6.2+; the active ACF version is ');
        }

        AcfBridge::assertSupportedAcfVersion($version);

        self::assertTrue($supported);
    }

    /**
     * 管理キーはAcfMetaStorageのキーとして申告し、locationの値allは全サブタイプへの申告、optionsは申告しない。
     */
    public function testMarkManagedKeysMarksPostAndAllSubtypeKeysWithAcfMetaStorage(): void
    {
        $app = $this->bootAcfBridgeKernel('Mark', ['Acf/AcfManifest.php' => self::MANIFEST]);
        $registry = new MetaSchemaRegistry(self::container());

        $app['bridge']->markManagedKeys($registry);

        self::assertTrue($registry->isExternallyManaged(ObjectType::Post, 'acf_unit_product', 'price'));
        self::assertFalse($registry->isExternallyManaged(ObjectType::Post, 'other', 'price'));
        self::assertTrue($registry->isExternallyManaged(ObjectType::Term, 'any_taxonomy', 'term_color'));
        self::assertFalse($registry->isExternallyManaged(ObjectType::Post, 'theme-settings', 'site_logo'));
    }

    /**
     * 管理キーをAcfMetaStorageでないエンティティが#[MetaSchema]で宣言済みなら、申告がDuplicateMetaKeyExceptionになり、
     * メッセージはキー・(ObjectType, サブタイプ)・宣言クラスと定数名・ACFのフィールドグループのkeyを含む。
     */
    public function testMarkManagedKeysRejectsKeyDeclaredWithAnotherStorage(): void
    {
        $app = $this->bootAcfBridgeKernel('Duplicate', ['Acf/AcfManifest.php' => self::MANIFEST]);
        $registry = new MetaSchemaRegistry(self::container());
        $registry->registerEntity(AcfUnitProductPost::class, ObjectType::Post, 'acf_unit_product');

        $this->expectException(DuplicateMetaKeyException::class);
        $this->expectExceptionMessage(
            "externally managed meta key 'price' for post:acf_unit_product is already declared by " . AcfUnitProductPost::class . '::PRICE'
            . ' with meta storage ' . MemoryMetaStorage::class . "; ACF field group 'group_product' manages it with meta storage " . AcfMetaStorage::class,
        );

        $app['bridge']->markManagedKeys($registry);
    }

    /**
     * initの発火後の本番のboot(遅延boot)でも、管理キーの申告(init 6)はKernelのキャッチアップの中で行われる。
     */
    public function testLateProductionBootMarksManagedKeys(): void
    {
        $GLOBALS['wp_actions'] = ['plugins_loaded' => 1, 'init' => 1];

        $app = $this->bootAcfBridgeKernel('LateMark', ['Acf/AcfManifest.php' => self::MANIFEST], debug: false);

        $registry = $app['kernel']->container()->get(MetaSchemaRegistry::class);
        self::assertTrue($registry->isExternallyManaged(ObjectType::Post, 'acf_unit_product', 'price'));
        self::assertTrue($registry->isExternallyManaged(ObjectType::Term, 'any_taxonomy', 'term_color'));
    }

    /** 遅延bootでも、AcfManifestが無いことはbootの例外になる(WP-CLIの外)。 */
    public function testLateProductionBootWithoutManifestFails(): void
    {
        $GLOBALS['wp_actions'] = ['plugins_loaded' => 1, 'init' => 1];

        $this->expectException(AcfBridgeRuntimeException::class);
        $this->expectExceptionMessageMatches('/^ACF manifest class .+\\\\Acf\\\\AcfManifest is not found/');

        $this->bootAcfBridgeKernel('LateMissing', debug: false);
    }

    /**
     * 遅延bootでも、管理キーをAcfMetaStorageでないエンティティが宣言していれば、申告がDuplicateMetaKeyExceptionになり、
     * MetaSchemaのWordPressへの登録(init 20)には進まない。
     */
    public function testLateProductionBootRejectsKeyDeclaredWithAnotherStorage(): void
    {
        $GLOBALS['wp_actions'] = ['plugins_loaded' => 1, 'init' => 1];
        $entity = <<<'PHP'
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

    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('acf_unit_product');
    }
}
PHP;
        Functions\expect('register_post_meta')->never();

        $this->expectException(DuplicateMetaKeyException::class);
        $this->expectExceptionMessageMatches("/^externally managed meta key 'price' for post:acf_unit_product is already declared by .+\\\\Post\\\\ProductPost::PRICE .+ACF field group 'group_product'/");

        $this->bootAcfBridgeKernel('LateDuplicate', ['Acf/AcfManifest.php' => self::MANIFEST, 'Post/ProductPost.php' => $entity], debug: false);
    }

    /** AcfManifestが無い(syncを実行していない)ことは、WP-CLIの外では設定の誤りとして例外にする。 */
    public function testMissingManifestIsAnError(): void
    {
        $app = $this->bootAcfBridgeKernel('Missing');

        $this->expectException(AcfBridgeRuntimeException::class);
        $this->expectExceptionMessage("ACF manifest class {$app['namespace']}\\Acf\\AcfManifest is not found; run wp tmswp acf sync --app={$app['namespace']}");

        $app['bridge']->markManagedKeys(new MetaSchemaRegistry(self::container()));
    }

    /**
     * WP-CLIでは、AcfManifestが無くても例外にせず、`WP_CLI::warning()`で1回警告して何も申告しない(生成物を作る
     * `wp tmswp acf sync`自体がWordPressの読み込みを経て動くため)。WP_CLIの代役クラスとWP_CLI定数を置くため、
     * 他のテストに残さないよう別プロセスで動かす。
     */
    #[RunInSeparateProcess]
    public function testMissingManifestIsOnlyWarnedInWpCli(): void
    {
        $this->loadWpCliDouble();
        \define('WP_CLI', true);
        $app = $this->bootAcfBridgeKernel('MissingCli');
        $GLOBALS['tmswp_test_wp_cli'] = [];
        $registry = new MetaSchemaRegistry(self::container());

        $app['bridge']->markManagedKeys($registry);

        $calls = $GLOBALS['tmswp_test_wp_cli'];
        self::assertIsArray($calls);
        self::assertCount(1, $calls);
        self::assertIsArray($calls[0]);
        self::assertSame('warning', $calls[0][0] ?? null);
        self::assertIsString($calls[0][1] ?? null);
        self::assertStringStartsWith("ACF manifest class {$app['namespace']}\\Acf\\AcfManifest is not found", $calls[0][1]);
        self::assertFalse($registry->isExternallyManaged(ObjectType::Post, 'acf_unit_product', 'price'));
    }

    /**
     * `wp tmswp acf check`・`sync`のプロセスでは、#[MetaSchema]と重複する管理キーを申告せず、例外にもせずに続け、
     * ほかの管理キーは申告する。重複は、コマンドがE101として報告する(docs/09 §7.3)。
     *
     * @param list<string> $arguments WP-CLIの位置引数
     */
    #[DataProvider('conflictReportingCommands')]
    #[RunInSeparateProcess]
    public function testMarkManagedKeysSkipsTheConflictingKeyInTheCommandsThatReportIt(array $arguments): void
    {
        $this->loadWpCliDoubleWithRunner($arguments);
        \define('WP_CLI', true);
        $app = $this->bootAcfBridgeKernel('ReportingCli', ['Acf/AcfManifest.php' => self::MANIFEST]);
        $registry = new MetaSchemaRegistry(self::container());
        $registry->registerEntity(AcfUnitProductPost::class, ObjectType::Post, 'acf_unit_product');

        $app['bridge']->markManagedKeys($registry);

        self::assertFalse($registry->isExternallyManaged(ObjectType::Post, 'acf_unit_product', 'price'));
        self::assertTrue($registry->isExternallyManaged(ObjectType::Term, 'category', 'term_color'));
    }

    /** @return iterable<string, array{list<string>}> */
    public static function conflictReportingCommands(): iterable
    {
        yield 'check' => [['tmswp', 'acf', 'check']];
        yield 'sync' => [['tmswp', 'acf', 'sync']];
    }

    /**
     * ほかのWP-CLIのコマンド(`wp eval`・`wp tmswp acf`の他のコマンドの形等)では、重複する管理キーの申告は、WP-CLIでない
     * リクエストと同じくDuplicateMetaKeyExceptionで止める。
     *
     * @param list<string> $arguments WP-CLIの位置引数
     */
    #[DataProvider('otherWpCliCommands')]
    #[RunInSeparateProcess]
    public function testMarkManagedKeysRejectsTheConflictingKeyInOtherWpCliCommands(array $arguments): void
    {
        $this->loadWpCliDoubleWithRunner($arguments);
        \define('WP_CLI', true);
        $app = $this->bootAcfBridgeKernel('OtherCli', ['Acf/AcfManifest.php' => self::MANIFEST]);
        $registry = new MetaSchemaRegistry(self::container());
        $registry->registerEntity(AcfUnitProductPost::class, ObjectType::Post, 'acf_unit_product');

        $this->expectException(DuplicateMetaKeyException::class);
        $this->expectExceptionMessage("externally managed meta key 'price' for post:acf_unit_product is already declared by " . AcfUnitProductPost::class . '::PRICE');

        $app['bridge']->markManagedKeys($registry);
    }

    /** @return iterable<string, array{list<string>}> */
    public static function otherWpCliCommands(): iterable
    {
        yield 'eval' => [['eval', 'echo 1;']];
        yield 'help of check' => [['help', 'tmswp', 'acf', 'check']];
        yield 'discover' => [['tmswp', 'discover']];
    }

    /** managedKeys()がManagedKey以外を返したら、黙って飛ばさずに例外にする。 */
    public function testManifestReturningNonManagedKeyIsAnError(): void
    {
        $manifest = str_replace("new ManagedKey(null, 'theme-settings', 'site_logo', 'field_logo', 'group_options'),", "'site_logo',", self::MANIFEST);
        $app = $this->bootAcfBridgeKernel('Broken', ['Acf/AcfManifest.php' => $manifest]);

        $this->expectException(AcfBridgeRuntimeException::class);
        $this->expectExceptionMessage("{$app['namespace']}\\Acf\\AcfManifest::managedKeys() must return a list of TMSWP\\AcfBridge\\Manifest\\ManagedKey, got string");

        $app['bridge']->markManagedKeys(new MetaSchemaRegistry(self::container()));
    }

    private function loadWpCliDouble(): void
    {
        $file = $this->createTempDirectory('wp-cli-double') . '/WP_CLI.php';
        $this->writeTestFile($file, <<<'PHP'
<?php

declare(strict_types=1);

final class WP_CLI
{
    public static function add_command(string $name, object $command): void
    {
    }

    public static function warning(string $message): void
    {
        $GLOBALS['tmswp_test_wp_cli'][] = ['warning', $message];
    }
}
PHP);

        require $file;
    }

    /**
     * WP_CLIの代役を読み込む。get_runner()は、本物のRunnerと同じく、位置引数$argumentsを`__get('arguments')`で出す
     * Runnerの代わりを返す。
     *
     * @param list<string> $arguments
     */
    private function loadWpCliDoubleWithRunner(array $arguments): void
    {
        $GLOBALS['tmswp_test_wp_cli_arguments'] = $arguments;
        $file = $this->createTempDirectory('wp-cli-runner-double') . '/WP_CLI.php';
        $this->writeTestFile($file, <<<'PHP'
<?php

declare(strict_types=1);

final class WP_CLI
{
    public static function add_command(string $name, object $command): void
    {
    }

    public static function warning(string $message): void
    {
        $GLOBALS['tmswp_test_wp_cli'][] = ['warning', $message];
    }

    public static function get_runner(): object
    {
        return new class {
            public function __get(string $key): mixed
            {
                return $key === 'arguments' ? $GLOBALS['tmswp_test_wp_cli_arguments'] : null;
            }
        };
    }
}
PHP);

        require $file;
    }

    private static function container(): Container
    {
        $container = new Container();
        $container->set(MetaStorageInterface::class, new MemoryMetaStorage());

        return $container;
    }
}

#[AsPostType]
final class AcfUnitProductPost extends PostType
{
    #[MetaSchema(type: MetaValueType::String)]
    public const PRICE = 'price';

    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('acf_unit_product');
    }
}
