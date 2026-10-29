<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Tests\Unit\Cli;

use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\AcfBridge\AcfBridge;
use TMSWP\AcfBridge\Cli\TmsAcf;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Testing\UnitTestCase;

/**
 * WordPressを起動しない`tms-acf`(docs/09 §5.1): ルートの`tmswp-kernel.php`のKernelConfigから対象を決め、Kernelを
 * bootせずに生成・照合する。構成を得られない場合は、原因を示してexit 2で終わる。
 */
final class TmsAcfTest extends UnitTestCase
{
    private const GROUP = <<<'JSON'
{
    "key": "group_common",
    "title": "Common",
    "location": [[{"param": "post_type", "operator": "==", "value": "cli_common"}]],
    "fields": [
        {"key": "field_note", "name": "note", "label": "Note", "type": "text"}
    ]
}
JSON;

    private const E101_NOTE = 'E101 (meta keys declared with #[MetaSchema]) is not checked by tms-acf because it needs the booted kernels; run wp tmswp acf check to check it';

    private const USAGE = 'Usage: tms-acf sync [--dry-run] [--root=<path>] | tms-acf check [--strict] [--root=<path>]';

    /**
     * syncはルートのacf-json(テーマの既定のjsonDirs)からapp/Acfへ生成し、checkは一致すればexit 0。どちらも、E101を
     * 検査しないことを先頭に出す。--rootの既定はカレントディレクトリ。
     */
    public function testSyncAndCheckUseTheKernelConfigOfTheRoot(): void
    {
        $root = $this->themeRoot('[new \TMSWP\AcfBridge\AcfBridge()]');
        $command = new TmsAcf();

        $sync = $command->run(['sync', '--root=' . $root], '/');
        self::assertSame(0, $sync['status'], implode("\n", $sync['lines']));
        self::assertSame([self::E101_NOTE, 'write: AcfManifest.php', 'write: CliCommonFields.php', 'Success: ACF bridge sync completed'], $sync['lines']);
        self::assertStringContainsString('namespace Example\TmsAcf\Acf;', (string) file_get_contents($root . '/app/Acf/CliCommonFields.php'));

        $check = $command->run(['check', '--strict'], $root);
        self::assertSame(0, $check['status']);
        self::assertSame(self::E101_NOTE, $check['lines'][0]);
        self::assertStringStartsWith('Success: ACF bridge is in sync. (2 files, source-hash sha256:', $check['lines'][1]);
        self::assertCount(2, $check['lines']);

        self::assertNotFalse(file_put_contents($root . '/app/Acf/AcfManifest.php', ''));
        $modified = $command->run(['check'], $root);
        self::assertSame(1, $modified['status']);
        self::assertContains('modified: AcfManifest.php', $modified['lines']);
    }

    /**
     * 構成を得られない場合(ファイルが無い、KernelConfigを返さない、AcfBridgeが無い・2つある、構成の誤り)と、
     * 引数の誤りは、原因を示してexit 2で終わる。
     *
     * @param list<string> $arguments
     */
    #[DataProvider('failures')]
    public function testFailuresExitWithTwo(?string $kernelFile, array $arguments, string $message): void
    {
        $root = (string) realpath($this->createTempDirectory('tms-acf-failure'));
        self::assertTrue(mkdir($root . '/app'));

        if ($kernelFile !== null) {
            self::assertNotFalse(file_put_contents($root . '/' . TmsAcf::KERNEL_FILE, str_replace('{root}', $root, $kernelFile)));
        }

        $outcome = (new TmsAcf())->run($arguments, $root);

        self::assertSame(['lines' => [str_replace('{root}', $root, $message)], 'status' => 2], $outcome);
    }

    /** @return iterable<string, array{?string, list<string>, string}> */
    public static function failures(): iterable
    {
        $kernel = static fn(string $extensions, string $namespace = 'Example\TmsAcf'): string => "<?php\n\nreturn \\TMSWP\\Core\\Kernel\\KernelConfig::theme('{root}/app', '{$namespace}', extensions: {$extensions});\n";

        yield 'no kernel file' => [null, ['check'], 'kernel config file not found or not readable: {root}/tmswp-kernel.php; put a file that returns KernelConfig::theme() or KernelConfig::plugin() there'];
        yield 'not a KernelConfig' => ["<?php\n\nreturn ['app' => 'Example'];\n", ['check'], 'kernel config file {root}/tmswp-kernel.php must return ' . KernelConfig::class . ', got array'];
        yield 'another object' => ["<?php\n\nreturn new \\stdClass();\n", ['check'], 'kernel config file {root}/tmswp-kernel.php must return ' . KernelConfig::class . ', got stdClass'];
        yield 'no AcfBridge' => [$kernel('[]'), ['check'], 'tms-acf: the KernelConfig in {root}/tmswp-kernel.php has no ' . AcfBridge::class . ' in its extensions'];
        yield 'two AcfBridges' => [$kernel('[new \TMSWP\AcfBridge\AcfBridge(), new \TMSWP\AcfBridge\AcfBridge()]'), ['sync'], 'extension ' . AcfBridge::class . ' is registered more than once in the same kernel'];
        yield 'namespace outside the app' => [
            $kernel("[new \\TMSWP\\AcfBridge\\AcfBridge(new \\TMSWP\\AcfBridge\\BridgeConfig(['{root}/acf-json'], '{root}/app/Acf', 'Other\\\\Acf'))]"),
            ['check'],
            "ACF bridge namespace 'Other\\Acf' must be inside the app namespace 'Example\\TmsAcf' of the kernel that registers it",
        ];
        yield 'no command' => [null, [], self::USAGE];
        yield 'unknown command' => [null, ['generate'], self::USAGE];
        yield 'option of the other command' => [null, ['check', '--dry-run'], self::USAGE];
        yield 'option of WP-CLI' => [null, ['sync', '--app=Example'], self::USAGE];
    }

    /** `sync --dry-run`は差分を表示し、outputDirを作らず、ファイルを書かない。 */
    public function testDryRunDoesNotWrite(): void
    {
        $root = $this->themeRoot('[new \TMSWP\AcfBridge\AcfBridge()]');

        $outcome = (new TmsAcf())->run(['sync', '--dry-run'], $root);

        self::assertSame(['lines' => [self::E101_NOTE, 'missing: AcfManifest.php', 'missing: CliCommonFields.php', 'Success: ACF bridge sync dry-run completed'], 'status' => 0], $outcome);
        self::assertDirectoryDoesNotExist($root . '/app/Acf');
    }

    /** bin/tms-acfは、TmsAcfの行を標準出力に書き、終了コードで終わる。 */
    public function testBinaryPrintsLinesAndExitsWithStatus(): void
    {
        $root = $this->themeRoot('[new \TMSWP\AcfBridge\AcfBridge()]');
        $binary = \dirname(__DIR__, 3) . '/bin/tms-acf';

        [$output, $status] = $this->runBinary([PHP_BINARY, $binary, 'check', '--root=' . $root]);
        self::assertSame(1, $status);
        self::assertSame(self::E101_NOTE . "\nmissing: AcfManifest.php\nmissing: CliCommonFields.php\nRun: composer acf:sync (vendor/bin/tms-acf sync or wp tmswp acf sync)\n", $output);

        [$output, $status] = $this->runBinary([PHP_BINARY, $binary, 'check', '--root=' . $root . '/app']);
        self::assertSame(2, $status);
        self::assertSame("kernel config file not found or not readable: {$root}/app/tmswp-kernel.php; put a file that returns KernelConfig::theme() or KernelConfig::plugin() there\n", $output);
    }

    /** acf-jsonにGROUPを置き、extensionsが$extensionsの`tmswp-kernel.php`(テーマ形態)を置いたルート。 */
    private function themeRoot(string $extensions): string
    {
        $root = (string) realpath($this->createTempDirectory('tms-acf'));
        self::assertTrue(mkdir($root . '/app'));
        self::assertTrue(mkdir($root . '/acf-json'));
        self::assertNotFalse(file_put_contents($root . '/acf-json/group_common.json', self::GROUP));
        self::assertNotFalse(file_put_contents(
            $root . '/' . TmsAcf::KERNEL_FILE,
            "<?php\n\ndeclare(strict_types=1);\n\nreturn \\TMSWP\\Core\\Kernel\\KernelConfig::theme(__DIR__ . '/app', 'Example\\TmsAcf', extensions: {$extensions});\n",
        ));

        return $root;
    }

    /**
     * @param list<string> $command
     *
     * @return array{string, int} 標準出力と終了コード
     */
    private function runBinary(array $command): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        self::assertSame('', $errors);

        return [$output, $status];
    }
}
