<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use TMSWP\AcfBridge\WpCliProcess;
use TMSWP\Testing\UnitTestCase;

/**
 * 今のプロセスのWP-CLIのコマンドが、管理キーの申告の重複をE101として報告するコマンドかの判定(docs/09 §7.3)。
 */
final class WpCliProcessTest extends UnitTestCase
{
    /**
     * 位置引数の先頭が`tmswp acf check`か`tmswp acf sync`のときだけ真になる。
     *
     * @param list<string> $arguments
     */
    #[DataProvider('commands')]
    public function testOnlyCheckAndSyncOfTmswpAcfReportConflicts(array $arguments, bool $expected): void
    {
        self::assertSame($expected, WpCliProcess::isConflictReportingCommand($arguments));
    }

    /** @return iterable<string, array{list<string>, bool}> */
    public static function commands(): iterable
    {
        yield 'check' => [['tmswp', 'acf', 'check'], true];
        yield 'sync' => [['tmswp', 'acf', 'sync'], true];
        yield 'check with a further positional argument' => [['tmswp', 'acf', 'check', 'extra'], true];
        yield 'eval' => [['eval', 'echo 1;'], false];
        yield 'acf without a subcommand' => [['tmswp', 'acf'], false];
        yield 'discover' => [['tmswp', 'discover'], false];
        yield 'help of check' => [['help', 'tmswp', 'acf', 'check'], false];
        yield 'another acf command' => [['acf', 'check'], false];
        yield 'no arguments' => [[], false];
    }

    /** WP-CLIでないプロセス(定数WP_CLIが無い)では偽になる。 */
    #[RunInSeparateProcess]
    public function testProcessOutsideWpCliDoesNotReportConflicts(): void
    {
        self::assertFalse(\defined('WP_CLI'));
        self::assertFalse(WpCliProcess::reportsMetaSchemaConflicts());
    }
}
