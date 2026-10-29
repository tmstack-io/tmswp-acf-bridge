<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Cli;

use Closure;
use TMSWP\AcfBridge\AcfBridge;
use TMSWP\AcfBridge\BridgeConfig;
use TMSWP\AcfBridge\Exception\AcfBridgeException;
use TMSWP\AcfBridge\Exception\AcfBridgeRuntimeException;
use TMSWP\AcfBridge\Generate\CloneResolver;
use TMSWP\AcfBridge\Json\FieldGroup;
use TMSWP\AcfBridge\Manifest\ManagedKeyCollector;
use TMSWP\Core\Exception\DuplicateMetaKeyException;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Meta\MetaSchemaRegistry;
use WP_CLI;

/**
 * `wp tmswp acf sync|check`。対象は`--app=`で選んだKernelに登録したAcfBridgeの構成で、AcfBridgeを登録したKernelが
 * 1つなら`--app=`を省略できる。生成と照合はWordPressを起動しないtms-acfと同じSyncRunnerで行い、WP-CLI版だけが、
 * 起動中のKernelの#[MetaSchema]との重複(E101)も検査する。終了コードはdocs/09 §5.1のとおり(sync: 0成功 / 1 E* または書き込みの失敗 / 2環境・設定の
 * 誤り、check: 0差分なし / 1差分あり、または --strictでW* あり / 2 E* または環境・設定の誤り)。
 */
final class AcfCommand
{
    private const SYNC_USAGE = 'Usage: wp tmswp acf sync [--app=<namespace>] [--dry-run]';

    private const CHECK_USAGE = 'Usage: wp tmswp acf check [--app=<namespace>] [--strict]';

    /**
     * Generates the ACF typed accessor classes and AcfManifest from acf-json.
     *
     * ## OPTIONS
     *
     * [--app=<namespace>]
     * : The app namespace of the kernel that registers AcfBridge. Optional when only one kernel registers it.
     *
     * [--dry-run]
     * : Show the differences without writing files.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function sync(array $args, array $assocArgs): void
    {
        if (!class_exists('WP_CLI')) {
            throw new AcfBridgeRuntimeException('WP_CLI is not available');
        }

        $outcome = $this->run($args, $assocArgs, ['app', 'dry-run'], self::SYNC_USAGE, static fn(SyncRunner $runner, BridgeConfig $config): array => $runner->sync($config, \array_key_exists('dry-run', $assocArgs)));

        foreach ($outcome['lines'] as $line) {
            WP_CLI::line($line);
        }

        if ($outcome['status'] !== 0) {
            WP_CLI::halt($outcome['status']);

            return;
        }

        WP_CLI::success($outcome['message']);
    }

    /**
     * Checks that the generated files are in sync with acf-json.
     *
     * ## OPTIONS
     *
     * [--app=<namespace>]
     * : The app namespace of the kernel that registers AcfBridge. Optional when only one kernel registers it.
     *
     * [--strict]
     * : Fail on warnings too.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function check(array $args, array $assocArgs): void
    {
        if (!class_exists('WP_CLI')) {
            throw new AcfBridgeRuntimeException('WP_CLI is not available');
        }

        $outcome = $this->run($args, $assocArgs, ['app', 'strict'], self::CHECK_USAGE, static fn(SyncRunner $runner, BridgeConfig $config): array => $runner->check($config, \array_key_exists('strict', $assocArgs)));

        foreach ($outcome['lines'] as $line) {
            WP_CLI::line($line);
        }

        if ($outcome['status'] !== 0) {
            WP_CLI::halt($outcome['status']);

            return;
        }

        WP_CLI::success($outcome['message']);
    }

    /**
     * 引数を検査して対象のKernelのAcfBridgeの構成を得て、E101の検査を加えたSyncRunnerで$operationを行う。
     *
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     * @param list<string> $allowedOptions
     * @param Closure(SyncRunner, BridgeConfig): array{lines: list<string>, status: int, message: string} $operation
     *
     * @return array{lines: list<string>, status: int, message: string}
     */
    private function run(array $args, array $assocArgs, array $allowedOptions, string $usage, Closure $operation): array
    {
        try {
            $config = $this->bridge($args, $assocArgs, $allowedOptions, $usage)->config();
        } catch (AcfBridgeException $exception) {
            return ['lines' => [$exception->getMessage()], 'status' => 2, 'message' => ''];
        }

        return $operation(new SyncRunner($this->metaSchemaConflictErrors(...)), $config);
    }

    /**
     * 引数を検査し、対象のKernelに登録したAcfBridgeを返す。
     *
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     * @param list<string> $allowedOptions
     *
     * @throws AcfBridgeRuntimeException 引数の誤り、対象のKernelが決まらない場合
     */
    private function bridge(array $args, array $assocArgs, array $allowedOptions, string $usage): AcfBridge
    {
        if ($args !== [] || array_diff(array_keys($assocArgs), $allowedOptions) !== []) {
            throw new AcfBridgeRuntimeException($usage);
        }

        $kernels = [];

        foreach (Kernel::all() as $namespace => $kernel) {
            foreach ($kernel->config()->extensions as $extension) {
                if ($extension instanceof AcfBridge) {
                    $kernels[$namespace] = $kernel;
                }
            }
        }

        $app = $assocArgs['app'] ?? null;
        $registered = implode(', ', array_keys($kernels));

        if ($app === null) {
            if (\count($kernels) !== 1) {
                throw new AcfBridgeRuntimeException($kernels === []
                    ? 'AcfBridge is not registered to any booted kernel; pass new AcfBridge() in the extensions of KernelConfig'
                    : "--app=<namespace> is required because AcfBridge is registered to more than one kernel: {$registered}");
            }

            return AcfBridge::forKernel(reset($kernels));
        }

        if (!\is_string($app) || !\array_key_exists(trim($app, '\\'), $kernels)) {
            throw new AcfBridgeRuntimeException('--app must be the app namespace of a kernel that registers AcfBridge: ' . ($registered !== '' ? $registered : '(none)'));
        }

        return AcfBridge::forKernel($kernels[trim($app, '\\')]);
    }

    /**
     * E101: ACFの管理キーと、起動中のKernelの#[MetaSchema]の宣言の重複。管理キーはcloneを解決したグループから
     * 集め、判定は実行時の申告(AcfBridge::markManagedKey())を各Kernelのレジストリの複製に行って、その例外の
     * メッセージを使う(実行時と同じ判定・同じ説明にする。複製なので起動中のレジストリは変えない)。
     *
     * @param list<FieldGroup> $groups
     *
     * @return list<string>
     */
    private function metaSchemaConflictErrors(BridgeConfig $config, array $groups): array
    {
        // cloneの解決の誤り(E004・E005)はGeneratorが報告する
        $cloneErrors = [];
        $managedKeys = (new ManagedKeyCollector())->collect((new CloneResolver())->resolve($groups, $cloneErrors), $config);
        $errors = [];

        foreach (Kernel::all() as $kernel) {
            $registry = clone $kernel->container()->get(MetaSchemaRegistry::class);

            foreach ($managedKeys as $managedKey) {
                try {
                    AcfBridge::markManagedKey($registry, $managedKey);
                } catch (DuplicateMetaKeyException $exception) {
                    $errors[] = 'E101: ' . $exception->getMessage();
                }
            }
        }

        sort($errors, SORT_STRING);

        return array_values(array_unique($errors));
    }
}
