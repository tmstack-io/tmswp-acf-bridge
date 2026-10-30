<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Cli;

use TMSWP\AcfBridge\AcfBridge;
use TMSWP\AcfBridge\BridgeConfig;
use TMSWP\Core\Exception\TmswpExceptionInterface;
use TMSWP\Core\Kernel\KernelConfig;

/**
 * WordPressを起動しない`vendor/bin/tms-acf <sync|check> [--strict] [--dry-run] [--root=<ルート>]`。
 *
 * テーマ・プラグインのルート(--rootの既定はカレントディレクトリ)の`tmswp-kernel.php`が返すKernelConfigから、extensionsの
 * AcfBridgeの構成を、WordPressの実行時と同じAcfBridge::configFor()で決め、WP-CLI版と同じSyncRunnerで生成・照合する。
 * Kernelはbootしない。#[MetaSchema]との重複(E101)は起動中のKernelが要るため検査せず、そのことを出力する(WP-CLIの
 * `wp tmswp acf check`が検査する)。終了コードはWP-CLI版と同じ意味。
 */
final class TmsAcf
{
    /** テーマ・プラグインのルートの、KernelConfigを返すファイル。WordPressの実行時とtms-acfが、同じこのファイルから構成を読む */
    public const string KERNEL_FILE = 'tmswp-kernel.php';

    private const string USAGE = 'Usage: tms-acf sync [--dry-run] [--root=<path>] | tms-acf check [--strict] [--root=<path>]';

    /**
     * @param list<string> $arguments コマンドの引数(スクリプトの名前を除く)
     * @param string $workingDirectory --rootの既定
     *
     * @return array{lines: list<string>, status: int}
     */
    public function run(array $arguments, string $workingDirectory): array
    {
        $command = $arguments[0] ?? '';
        $options = \array_slice($arguments, 1);
        $allowed = match ($command) {
            'sync' => ['--dry-run'],
            'check' => ['--strict'],
            default => null,
        };

        if ($allowed === null) {
            return ['lines' => [self::USAGE], 'status' => 2];
        }

        $root = $workingDirectory;
        $flags = [];

        foreach ($options as $option) {
            if (str_starts_with($option, '--root=')) {
                $root = substr($option, \strlen('--root='));
            } elseif (\in_array($option, $allowed, true)) {
                $flags[$option] = true;
            } else {
                return ['lines' => [self::USAGE], 'status' => 2];
            }
        }

        try {
            $config = $this->bridgeConfig($root);
        } catch (TmswpExceptionInterface $exception) {
            return ['lines' => [$exception->getMessage()], 'status' => 2];
        }

        if (\is_string($config)) {
            return ['lines' => [$config], 'status' => 2];
        }

        $runner = new SyncRunner();
        $outcome = $command === 'sync'
            ? $runner->sync($config, \array_key_exists('--dry-run', $flags))
            : $runner->check($config, \array_key_exists('--strict', $flags));
        $lines = ['E101 (meta keys declared with #[MetaSchema]) is not checked by tms-acf because it needs the booted kernels; run wp tmswp acf check to check it', ...$outcome['lines']];

        if ($outcome['status'] === 0) {
            $lines[] = 'Success: ' . $outcome['message'];
        }

        return ['lines' => $lines, 'status' => $outcome['status']];
    }

    /**
     * ルートの`tmswp-kernel.php`を、functions.php・プラグインの本体と同じKernelConfig::load()で読み、そのKernelConfigに
     * 登録したAcfBridgeの構成を返す。extensionsにAcfBridgeが無い場合は、原因のメッセージを返す(同じ拡張の2つ目は
     * KernelConfigが例外にするため、AcfBridgeは多くても1つ)。
     *
     * @throws TmswpExceptionInterface ファイルが無い・KernelConfigを返さない場合(KernelConfig::load()のKernelException)と、
     *                                 KernelConfig・BridgeConfigの構成の誤り
     */
    private function bridgeConfig(string $root): BridgeConfig|string
    {
        $file = rtrim($root, '/\\') . '/' . self::KERNEL_FILE;
        $kernelConfig = KernelConfig::load($file);

        foreach ($kernelConfig->extensions as $extension) {
            if ($extension instanceof AcfBridge) {
                return $extension->configFor($kernelConfig);
            }
        }

        return "tms-acf: the KernelConfig in {$file} has no " . AcfBridge::class . ' in its extensions';
    }
}
