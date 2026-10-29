<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Tests\Support;

use Psr\Log\NullLogger;
use TMSWP\AcfBridge\AcfBridge;
use TMSWP\AcfBridge\BridgeConfig;
use TMSWP\Core\Kernel\Discovery;
use TMSWP\Core\Kernel\DiscoveryFile;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Log\LogChannel;

/**
 * ユニットテストで、一時ディレクトリのアプリにAcfBridgeを登録したKernel(debug、テーマ形態)をbootする。
 * 使うテストはUnitTestCaseを継承し、setUpで`$GLOBALS['wp_version']`(7.0以上)と`$GLOBALS['wp_actions']`を置き、
 * tearDownでKernel::reset()を呼ぶ。
 *
 * アプリの構成: {root}/app(appDir)、{root}/acf-json(jsonDirsの先頭)、{root}/app/Acf(outputDir)、名前空間{appNamespace}\Acf。
 */
trait BootsAcfBridgeKernel
{
    /**
     * @param array<string, string> $appFiles appDirからの相対パス => 内容(PHPのクラス等)。{namespace}はアプリの名前空間に置き換える
     * @param array<string, string> $jsonFiles acf-jsonのファイル名 => 内容
     * @param array<string, string> $optionsPagePostIds
     * @param bool $debug falseなら本番のboot(探索結果ファイルを先に作る。遅延bootのログは捨てる)
     * @param list<string> $moreJsonDirs {root}/acf-jsonの後に並べるjsonDirs({root}からの相対パス。ディレクトリを作る)
     *
     * @return array{kernel: Kernel, bridge: AcfBridge, root: string, namespace: string}
     */
    private function bootAcfBridgeKernel(string $suffix, array $appFiles = [], array $jsonFiles = [], array $optionsPagePostIds = [], bool $debug = true, array $moreJsonDirs = []): array
    {
        $root = $this->createTempDirectory('acf-bridge-' . strtolower($suffix));
        $namespace = 'Example\Tests\AcfBridge' . $suffix . uniqid();

        self::assertTrue(mkdir($root . '/app'));
        self::assertTrue(mkdir($root . '/acf-json'));

        foreach ($moreJsonDirs as $dir) {
            self::assertTrue(mkdir($root . '/' . $dir));
        }

        foreach ($appFiles as $path => $contents) {
            $this->writeTestFile($root . '/app/' . $path, str_replace('{namespace}', $namespace, $contents));
        }

        foreach ($jsonFiles as $name => $contents) {
            $this->writeTestFile($root . '/acf-json/' . $name, $contents);
        }

        spl_autoload_register(static function (string $class) use ($namespace, $root): void {
            $prefix = $namespace . '\\';

            if (str_starts_with($class, $prefix) && is_file($file = $root . '/app/' . str_replace('\\', '/', substr($class, \strlen($prefix))) . '.php')) {
                require $file;
            }
        });

        $bridge = new AcfBridge(new BridgeConfig(
            jsonDirs: [$root . '/acf-json', ...array_map(static fn(string $dir): string => $root . '/' . $dir, $moreJsonDirs)],
            outputDir: $root . '/app/Acf',
            namespace: $namespace . '\Acf',
            optionsPagePostIds: $optionsPagePostIds,
        ));
        $config = KernelConfig::theme($root . '/app', $namespace, $debug, [$bridge], static fn(): array => ['default' => new LogChannel(new NullLogger())]);

        if (!$debug) {
            (new DiscoveryFile($config))->write((new Discovery($config, new DiscoveryFile($config)))->scan());
        }

        $kernel = Kernel::boot($config);

        return ['kernel' => $kernel, 'bridge' => $bridge, 'root' => $root, 'namespace' => $namespace];
    }

    private function writeTestFile(string $path, string $contents): void
    {
        $dir = \dirname($path);

        if (!is_dir($dir)) {
            self::assertTrue(mkdir($dir, 0777, true));
        }

        self::assertNotFalse(file_put_contents($path, $contents));
    }
}
