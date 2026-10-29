<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge;

use WP_CLI;

/**
 * 今のプロセスで実行しているWP-CLIのコマンドの判定。WP-CLIへの依存をこのクラスに閉じ込める。
 *
 * WP-CLIは、WordPressを読み込む前に、コマンドラインの位置引数(`wp tmswp acf check --strict`なら
 * `['tmswp', 'acf', 'check']`)を解析して`WP_CLI::get_runner()->arguments`に置く。そのため、Kernelのinit 6の
 * 時点では、どのコマンドを実行するかが決まっている。
 *
 * @internal AcfBridgeの管理キーの申告(docs/09 §7.3)だけが使う
 */
final class WpCliProcess
{
    /** 管理キーの申告と#[MetaSchema]の重複を、E101として報告するコマンド(docs/09 §5.1・§7.3) */
    private const array CONFLICT_REPORTING_COMMANDS = [
        ['tmswp', 'acf', 'check'],
        ['tmswp', 'acf', 'sync'],
    ];

    /**
     * 今のプロセスが、WP-CLIで`wp tmswp acf check`か`wp tmswp acf sync`を実行しているか。WP-CLIでないプロセスと、
     * ほかのWP-CLIのコマンド(`wp eval`等)は偽になる。
     */
    public static function reportsMetaSchemaConflicts(): bool
    {
        if (!(\defined('WP_CLI') && WP_CLI === true && class_exists('WP_CLI'))) {
            return false;
        }

        // Runnerは位置引数を、公開のgetterの__get('arguments')で読み取り専用のプロパティとして出す
        $runner = WP_CLI::get_runner();

        if (!\is_object($runner) || !method_exists($runner, '__get')) {
            return false;
        }

        $arguments = $runner->__get('arguments');

        return \is_array($arguments) && self::isConflictReportingCommand(array_values(array_filter($arguments, is_string(...))));
    }

    /**
     * WP-CLIの位置引数$argumentsが、申告の重複をE101として報告するコマンドか。先頭の3つ(`tmswp acf check`か
     * `tmswp acf sync`)で決める。
     *
     * @param list<string> $arguments WP-CLIの位置引数(グローバルのオプションと`--`で始まる引数を除いたもの)
     */
    public static function isConflictReportingCommand(array $arguments): bool
    {
        return \in_array(\array_slice($arguments, 0, 3), self::CONFLICT_REPORTING_COMMANDS, true);
    }
}
