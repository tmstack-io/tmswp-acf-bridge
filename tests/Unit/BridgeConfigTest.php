<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\AcfBridge\BridgeConfig;
use TMSWP\AcfBridge\Exception\AcfBridgeRuntimeException;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Testing\UnitTestCase;

/**
 * BridgeConfigの入力の検証と、Kernelの構成からの既定値。
 */
final class BridgeConfigTest extends UnitTestCase
{
    /**
     * @return iterable<string, array{0: callable(): BridgeConfig, 1: string}>
     */
    public static function invalidConfigProvider(): iterable
    {
        yield 'relative json dir' => [static fn(): BridgeConfig => new BridgeConfig(['acf-json'], '/app/Acf', 'App\Acf'), "BridgeConfig jsonDirs must be absolute paths, got 'acf-json'"];
        yield 'relative output dir' => [static fn(): BridgeConfig => new BridgeConfig([], 'app/Acf', 'App\Acf'), "BridgeConfig outputDir must be absolute paths, got 'app/Acf'"];
        yield 'leading backslash namespace' => [static fn(): BridgeConfig => new BridgeConfig([], '/app/Acf', '\App\Acf'), "BridgeConfig namespace must be a PHP namespace without leading or trailing backslashes: '\\App\\Acf'"];
        yield 'invalid namespace' => [static fn(): BridgeConfig => new BridgeConfig([], '/app/Acf', 'App\1Acf'), 'BridgeConfig namespace must be a PHP namespace'];
        yield 'empty class map value' => [static fn(): BridgeConfig => new BridgeConfig([], '/app/Acf', 'App\Acf', classMap: ['group_x' => '']), 'BridgeConfig classMap must map non-empty strings to non-empty strings'];
        yield 'empty options page post id' => [static fn(): BridgeConfig => new BridgeConfig([], '/app/Acf', 'App\Acf', optionsPagePostIds: ['theme-settings' => '']), 'BridgeConfig optionsPagePostIds must map non-empty strings to non-empty strings'];
    }

    /**
     * @param callable(): BridgeConfig $create
     */
    #[DataProvider('invalidConfigProvider')]
    public function testInvalidInputIsRejected(callable $create, string $message): void
    {
        $this->expectException(AcfBridgeRuntimeException::class);
        $this->expectExceptionMessage($message);

        $create();
    }

    public function testValidInputIsKept(): void
    {
        $config = new BridgeConfig(['/theme/acf-json', 'C:\theme\acf-json'], '/theme/app/Acf', 'App\Acf', optionsPagePostIds: ['theme-settings' => 'theme_settings']);

        self::assertSame(['/theme/acf-json', 'C:\theme\acf-json'], $config->jsonDirs);
        self::assertSame(['theme-settings' => 'theme_settings'], $config->optionsPagePostIds);
    }

    /**
     * テーマの既定値: acf-jsonはルート(appDirの親)の`acf-json`だけ(WordPressの関数を使わない。子テーマのacf-jsonは
     * jsonDirsに明示する)、生成先はappDir/Acf、名前空間は{app}\Acf。
     */
    public function testForKernelResolvesThemeDefaultsFromTheRoot(): void
    {
        $root = (string) realpath($this->createTempDirectory('bridge-config-theme'));
        self::assertTrue(mkdir($root . '/app'));

        $config = BridgeConfig::forKernel(KernelConfig::theme($root . '/app', 'App'), optionsPagePostIds: ['theme-settings' => 'theme_settings']);

        self::assertSame([$root . '/acf-json'], $config->jsonDirs);
        self::assertSame($root . '/app/Acf', $config->outputDir);
        self::assertSame('App\Acf', $config->namespace);
        self::assertSame(['theme-settings' => 'theme_settings'], $config->optionsPagePostIds);
    }

    public function testForKernelResolvesPluginDefaults(): void
    {
        $root = (string) realpath($this->createTempDirectory('bridge-config-plugin'));
        self::assertTrue(mkdir($root . '/app'));
        self::assertNotFalse(file_put_contents($root . '/plugin.php', '<?php'));

        $config = BridgeConfig::forKernel(KernelConfig::plugin($root . '/plugin.php', $root . '/app', 'Vendor\Plugin'));

        self::assertSame([$root . '/acf-json'], $config->jsonDirs);
        self::assertSame($root . '/app/Acf', $config->outputDir);
        self::assertSame('Vendor\Plugin\Acf', $config->namespace);
    }
}
