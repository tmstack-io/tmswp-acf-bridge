<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge;

use TMSWP\AcfBridge\Cli\AcfCommand;
use TMSWP\AcfBridge\Exception\AcfBridgeRuntimeException;
use TMSWP\AcfBridge\Manifest\ManagedKey;
use TMSWP\Core\Exception\DuplicateMetaKeyException;
use TMSWP\Core\Kernel\ExtensionInterface;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Meta\MetaSchemaRegistry;
use WP_CLI;

/**
 * acf-bridgeの入口。`KernelConfig::theme()` / `plugin()`の`extensions`に渡し、Kernelごとに登録する。
 *
 * ```php
 * Kernel::boot(KernelConfig::theme(__DIR__ . '/app', 'App', extensions: [new AcfBridge()]));
 * ```
 *
 * 構成を渡さなければ、登録したKernelの構成から既定値を決める(BridgeConfig::forKernel())。1つのインスタンスは
 * 1つのKernelにだけ登録できる。
 */
final class AcfBridge implements ExtensionInterface
{
    /** 対応するACFの下限。acf/json/save_paths(保存先の制御)がACF 6.2で入ったため */
    public const MINIMUM_ACF_VERSION = '6.2';

    private ?Kernel $kernel = null;

    private ?BridgeConfig $config = null;

    /**
     * @param BridgeConfig|null $explicitConfig 構成。nullなら登録したKernelの構成から既定値を決める
     */
    public function __construct(private readonly ?BridgeConfig $explicitConfig = null) {}

    /**
     * 登録したKernelのboot中に1回呼ばれる。acf-jsonの読み込み・保存先のフィルタ、管理キーの申告(init 6)、
     * WP-CLIの`wp tmswp acf`を登録する。ACFが無効でも成功する(フィルタはACFが無ければ発火しない)。
     *
     * @throws AcfBridgeRuntimeException このインスタンスを登録済みの場合、構成の名前空間がKernelのアプリの名前空間の外の場合
     */
    public function register(Kernel $kernel): void
    {
        if ($this->kernel instanceof Kernel) {
            throw new AcfBridgeRuntimeException("this AcfBridge instance is already registered to the kernel '{$this->kernel->config()->appNamespace}'; create one AcfBridge per kernel");
        }

        $config = $this->configFor($kernel->config());
        $this->kernel = $kernel;
        $this->config = $config;
        $kernel->container()->set(self::class, $this);

        if ($config->registerLoadJsonDirs) {
            add_filter('acf/settings/load_json', $this->addLoadJsonDirs(...), 10, 1);
        }

        add_filter('acf/json/save_paths', $this->savePaths(...), 10, 2);
        // ACFはプラグインの読み込み時にACF_VERSIONを定義するため、すべてのプラグインを読み込んだ後に版を確かめる
        $kernel->defer('plugins_loaded', 10, static function (): void {
            if (\defined('ACF_VERSION')) {
                self::assertSupportedAcfVersion(\constant('ACF_VERSION'));
            }
        });
        $kernel->defer('init', 6, function () use ($kernel): void {
            $this->markManagedKeys($kernel->container()->get(MetaSchemaRegistry::class));
        });

        if (\defined('WP_CLI') && WP_CLI === true && class_exists('WP_CLI')) {
            WP_CLI::add_command('tmswp acf', new AcfCommand());
        }
    }

    /**
     * Kernelの構成$kernelConfigで使うacf-bridgeの構成。渡した構成、無ければBridgeConfig::forKernel()の既定。
     * register()(WordPressの実行時)とtms-acf(WordPressを起動しないsync・check)が同じこの関数で決めるため、構成の入手元と
     * 構成の誤りの例外は、どちらの経路でも同じになる。WordPressの関数は使わない。
     *
     * @throws AcfBridgeRuntimeException 構成の名前空間がKernelのアプリの名前空間の外の場合、または構成が不正な場合
     */
    public function configFor(KernelConfig $kernelConfig): BridgeConfig
    {
        $config = $this->explicitConfig ?? BridgeConfig::forKernel($kernelConfig);

        if (!str_starts_with($config->namespace . '\\', $kernelConfig->appNamespace . '\\')) {
            throw new AcfBridgeRuntimeException("ACF bridge namespace '{$config->namespace}' must be inside the app namespace '{$kernelConfig->appNamespace}' of the kernel that registers it");
        }

        return $config;
    }

    /**
     * 有効なACFの版が下限(MINIMUM_ACF_VERSION)以上かを確かめる。下限より古いACFは保存先の制御
     * (acf/json/save_paths)を持たないため、設定の誤りとして例外にする。
     *
     * @internal register()がプラグインの読み込みの後に、ACFが有効なとき(ACF_VERSIONが定義済み)だけ呼ぶ
     *
     * @throws AcfBridgeRuntimeException 版が文字列でない、または下限より古い場合
     */
    public static function assertSupportedAcfVersion(mixed $version): void
    {
        if (!\is_string($version) || version_compare($version, self::MINIMUM_ACF_VERSION, '<')) {
            throw new AcfBridgeRuntimeException('tmswp/acf-bridge requires Advanced Custom Fields (ACF) ' . self::MINIMUM_ACF_VERSION . '+; the active ACF version is ' . (\is_string($version) ? $version : get_debug_type($version)) . '. Update ACF, or deactivate it and remove AcfBridge from the extensions of KernelConfig');
        }
    }

    /**
     * Kernelに登録したAcfBridgeを返す。
     *
     * @throws AcfBridgeRuntimeException そのKernelのextensionsにAcfBridgeが無い、または未登録の場合
     */
    public static function forKernel(Kernel $kernel): self
    {
        foreach ($kernel->config()->extensions as $extension) {
            if ($extension instanceof self && $extension->kernel === $kernel) {
                return $extension;
            }
        }

        throw new AcfBridgeRuntimeException("AcfBridge is not registered to the kernel '{$kernel->config()->appNamespace}'; pass new AcfBridge() in the extensions of its KernelConfig");
    }

    /**
     * @throws AcfBridgeRuntimeException 未登録の場合
     */
    public function config(): BridgeConfig
    {
        if (!$this->config instanceof BridgeConfig) {
            throw new AcfBridgeRuntimeException('AcfBridge is not registered to a kernel; pass new AcfBridge() in the extensions of KernelConfig');
        }

        return $this->config;
    }

    /**
     * 登録したKernel。acf-bridgeのdebugによる分岐とログは、このKernelの`config()->debug`と`logger()`に従う。
     *
     * @throws AcfBridgeRuntimeException 未登録の場合
     */
    public function kernel(): Kernel
    {
        if (!$this->kernel instanceof Kernel) {
            throw new AcfBridgeRuntimeException('AcfBridge is not registered to a kernel; pass new AcfBridge() in the extensions of KernelConfig');
        }

        return $this->kernel;
    }

    /**
     * 生成物AcfManifestの管理キーを、外部管理のキー(ストレージはAcfMetaStorage)として申告する。ACFのlocationの値
     * `all`のキーは全サブタイプへの申告にする。optionsのキーはメタではないため申告しない。ACFが無効でも申告する
     * (申告は生成物だけで決まり、ACFの実行時に依存しない)。
     *
     * AcfManifestが無い(`wp tmswp acf sync`を実行していない)ことは設定の誤りとして例外にする。ただしWP-CLIでは、
     * 生成物を作る`wp tmswp acf sync`を実行できるよう、警告を出して申告しない。
     *
     * 管理キーを、AcfMetaStorageでないエンティティが#[MetaSchema]で宣言済みなら、DuplicateMetaKeyExceptionで止める。
     * ただし`wp tmswp acf check`・`sync`のプロセスでは、そのキーを申告せずに続ける。この2つのコマンドは、重複をE101・
     * exit 2(syncは書き込まずにexit 1)で報告するのが役目で、起動の時点で止めると、AcfManifestがすでにそのキーを持つ
     * (生成物をコミットした後に#[MetaSchema]を足した)ときに、コマンドに届かず報告できないためである。
     * ほかのWP-CLIのコマンドと、WP-CLIでないリクエストは止める。
     *
     * @internal Kernelのinit 6から呼ぶ。テストは任意のレジストリに申告させるために呼ぶ
     *
     * @throws AcfBridgeRuntimeException AcfManifestが無い(WP-CLIを除く)、またはmanagedKeys()がManagedKeyのリストを返さない場合
     * @throws DuplicateMetaKeyException 管理キーを、AcfMetaStorageでないエンティティが#[MetaSchema]で宣言済みの場合
     *                                   (`wp tmswp acf check`・`sync`のプロセスを除く)
     */
    public function markManagedKeys(MetaSchemaRegistry $registry): void
    {
        foreach ($this->managedKeys() as $key) {
            try {
                self::markManagedKey($registry, $key);
            } catch (DuplicateMetaKeyException $exception) {
                if (!WpCliProcess::reportsMetaSchemaConflicts()) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * 管理キー1つを申告する。実行時の申告(markManagedKeys())と`wp tmswp acf check`のE101の検査が共有する。
     *
     * @internal
     *
     * @throws DuplicateMetaKeyException 管理キーを、AcfMetaStorageでないエンティティが#[MetaSchema]で宣言済みの場合
     */
    public static function markManagedKey(MetaSchemaRegistry $registry, ManagedKey $key): void
    {
        if ($key->objectType === null) {
            return;
        }

        $origin = "ACF field group '{$key->groupKey}'";

        if ($key->objectSubtype === 'all') {
            $registry->markExternallyManagedInAllSubtypes($key->objectType, $key->metaKey, AcfMetaStorage::class, $origin);

            return;
        }

        $registry->markExternallyManaged($key->objectType, $key->objectSubtype, $key->metaKey, AcfMetaStorage::class, $origin);
    }

    /**
     * @throws AcfBridgeRuntimeException
     *
     * @return list<ManagedKey>
     */
    private function managedKeys(): array
    {
        $manifestClass = $this->config()->namespace . '\AcfManifest';

        if (!class_exists($manifestClass)) {
            $message = "ACF manifest class {$manifestClass} is not found; run wp tmswp acf sync --app={$this->kernel()->config()->appNamespace} and deploy the generated files";

            if (\defined('WP_CLI') && WP_CLI === true && class_exists('WP_CLI')) {
                WP_CLI::warning($message);

                return [];
            }

            throw new AcfBridgeRuntimeException($message);
        }

        $managedKeys = \is_callable([$manifestClass, 'managedKeys']) ? $manifestClass::managedKeys() : null;

        if (!\is_array($managedKeys) || !array_is_list($managedKeys)) {
            throw new AcfBridgeRuntimeException("{$manifestClass}::managedKeys() must return a list of " . ManagedKey::class . '; run wp tmswp acf sync to regenerate it');
        }

        $keys = [];

        foreach ($managedKeys as $key) {
            if (!$key instanceof ManagedKey) {
                throw new AcfBridgeRuntimeException("{$manifestClass}::managedKeys() must return a list of " . ManagedKey::class . ', got ' . get_debug_type($key) . '; run wp tmswp acf sync to regenerate it');
            }

            $keys[] = $key;
        }

        return $keys;
    }

    /**
     * acf/settings/load_jsonの末尾に、jsonDirsのうち存在するディレクトリをjsonDirsの逆順(jsonDirsの先頭が最後)で置く。
     * 並びに既にあるもの(ACFの既定の`{stylesheet}/acf-json`等)は末尾へ移す。ACFは同じkeyのJSONのうちload_jsonの後の
     * ディレクトリのものを使うため、生成(jsonDirsの先のディレクトリのものを使う。AcfJsonRepository)と同じファイルを使う。
     *
     * @param array<mixed> $paths
     *
     * @return array<mixed>
     */
    private function addLoadJsonDirs(array $paths): array
    {
        $dirs = [];

        foreach ($this->config()->jsonDirs as $dir) {
            $dir = rtrim($dir, '/\\');

            if (is_dir($dir) && !\in_array($dir, $dirs, true)) {
                $dirs[] = $dir;
            }
        }

        $others = array_filter($paths, static fn(mixed $path): bool => !\is_string($path) || !\in_array(rtrim($path, '/\\'), $dirs, true));

        return [...array_values($others), ...array_reverse($dirs)];
    }

    /**
     * acf/json/save_paths(ACF 6.2+)で、ACFが読み込んだJSONファイル(ACFが使っているもの)がこの構成のjsonDirsに
     * あるグループ等の保存先を、そのディレクトリだけにする。プラグインや親テーマのacf-jsonから読み込んだグループを、
     * 管理画面で編集したときにACFの既定(子テーマのacf-json)へ書かず、読み込んだacf-jsonへ書き戻すためである。
     * ACFが別のKernelのacf-jsonから読み込んだもの、読み込んでいないもの(新しく作ったグループ等)は、保存先を変えない
     * (別のKernelのファイルを上書きしない)。
     *
     * @param array<mixed> $paths
     * @param array<mixed> $post
     *
     * @return array<mixed>
     */
    private function savePaths(array $paths, array $post): array
    {
        $key = $post['key'] ?? null;

        if (!\is_string($key) || $key === '') {
            return $paths;
        }

        $postType = acf_determine_internal_post_type($key);
        $loadedFile = \is_string($postType) ? (acf_get_local_json_files($postType)[$key] ?? null) : null;

        if (!\is_string($loadedFile)) {
            return $paths;
        }

        $loadedDir = \dirname($loadedFile);

        foreach ($this->config()->jsonDirs as $dir) {
            if (rtrim($dir, '/\\') === $loadedDir) {
                return [$loadedDir];
            }
        }

        return $paths;
    }
}
