<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Generate;

use TMSWP\Core\Exception\LogicException;

/**
 * 1つの生成クラスがuseするクラス・`@phpstan-import-type`で取り込む型の別名と、生成コードでの参照の書き方。
 *
 * 生成クラスの短名と、大文字小文字を区別せずに同じ短名のクラスはuseせず、完全修飾名(先頭に`\`)で参照する。PHPの
 * クラス名は大文字小文字を区別しないため、useするとクラスの宣言と名前が衝突して読み込みがfatalになるためである。
 * 同じく生成クラスの短名と重なる型の別名(AcfImage等)は、`as`で重ならない名前にして取り込む(PHPStanは、クラスと
 * 同じ名前の別名をtypeAlias.duplicateにする)。
 *
 * 型の表記は、TypeMapperとGeneratorが組み立てるときにref()・alias()を通して書く。shapeのキーやリテラルと区別できない
 * ため、組み立てた後の文字列は置き換えない。
 *
 * @internal
 */
final class ClassImports
{
    /** 生成コードが参照するクラス(短名 => 完全修飾名) */
    private const array CLASSES = [
        'AcfFieldAccess' => 'TMSWP\\AcfBridge\\AcfFieldAccess',
        'DateTimeImmutable' => 'DateTimeImmutable',
        'DateTimeInterface' => 'DateTimeInterface',
        'ObjectType' => 'TMSWP\\Core\\Meta\\ObjectType',
        'WP_Post' => 'WP_Post',
        'WP_Term' => 'WP_Term',
        'WP_User' => 'WP_User',
    ];

    /** AcfTypesの型の別名 */
    private const array TYPE_ALIASES = ['AcfChoice', 'AcfColorRgba', 'AcfFile', 'AcfImage', 'AcfLink', 'AcfUserArray'];

    /** @var array<string, true> useするクラスの短名 */
    private array $used = [];

    /** @var array<string, string> 取り込む型の別名 => 生成コードで使う名前 */
    private array $aliases = [];

    /** @var array<string, string> 生成クラスが宣言する型の別名(`@phpstan-type`) => 型。宣言した順 */
    private array $localAliases = [];

    /** @var list<string> 宣言できなかった型の別名の説明(生成時のE002) */
    private array $localAliasConflicts = [];

    public function __construct(private readonly string $className) {}

    /**
     * 生成クラスのuseに記録しない、捨てるためのClassImports。出力しない型(入れ子のサブフィールドのsetter側の型、衝突の
     * 検査の前に決める型)を組み立てるときに使う。
     */
    public static function discarding(): self
    {
        return new self('');
    }

    /**
     * 短名$shortNameのクラスの参照。生成クラスの短名と衝突しなければuseに加えて短名を、衝突すれば完全修飾名を返す。
     *
     * @throws LogicException $shortNameが生成コードの参照するクラスでない場合(ジェネレータの誤り)
     */
    public function ref(string $shortName): string
    {
        $class = self::CLASSES[$shortName] ?? throw new LogicException("'{$shortName}' is not a class that generated code refers to");

        if (strcasecmp($shortName, $this->className) === 0) {
            return '\\' . $class;
        }

        $this->used[$shortName] = true;

        return $shortName;
    }

    /**
     * 型の別名$aliasを取り込み、生成コードで使う名前を返す。生成クラスの短名と大文字小文字を区別せずに重なれば、`Type`を
     * 付けて、生成クラスの短名・ほかの別名・参照するクラスの短名のどれとも重ならない名前にする。
     *
     * @throws LogicException $aliasがAcfTypesの型の別名でない場合(ジェネレータの誤り)
     */
    public function alias(string $alias): string
    {
        if (!\in_array($alias, self::TYPE_ALIASES, true)) {
            throw new LogicException("'{$alias}' is not a type alias of AcfTypes");
        }

        if (!\array_key_exists($alias, $this->aliases)) {
            $name = $alias;

            while ($this->isTaken($name, $alias)) {
                $name .= 'Type';
            }

            $this->aliases[$alias] = $name;
        }

        return $this->aliases[$alias];
    }

    /**
     * 生成クラスが`@phpstan-type`で宣言する型の別名$nameを$typeとして加え、$nameを返す(flexible_contentのレイアウトの
     * shape)。同じ名前を違う型で加える場合と、名前が生成クラスの短名・参照するクラス・取り込む型の別名と
     * 大文字小文字を区別せずに重なる場合は、宣言できないことを記録する(localAliasConflicts())。
     */
    public function localAlias(string $name, string $type): string
    {
        $registered = $this->localAliases[$name] ?? null;

        if ($registered === null) {
            foreach ([$this->className, ...self::TYPE_ALIASES, ...array_keys(self::CLASSES)] as $other) {
                if ($other !== '' && strcasecmp($name, $other) === 0) {
                    $this->localAliasConflicts[] = "the type alias '{$name}' collides with '{$other}'";
                }
            }

            $this->localAliases[$name] = $type;
        } elseif ($registered !== $type) {
            $this->localAliasConflicts[] = "the type alias '{$name}' is declared for two different layouts ({$registered} and {$type})";
        }

        return $name;
    }

    /**
     * 宣言できなかった型の別名の説明(重複を除く)。
     *
     * @return list<string>
     */
    public function localAliasConflicts(): array
    {
        return array_values(array_unique($this->localAliasConflicts));
    }

    /**
     * `@phpstan-type`の行(docblockの中の行。宣言した順)。
     *
     * @return list<string>
     */
    public function localAliasLines(): array
    {
        $lines = [];

        foreach ($this->localAliases as $name => $type) {
            $lines[] = " * @phpstan-type {$name} {$type}";
        }

        return $lines;
    }

    /**
     * `@phpstan-import-type`の行(docblockの中の行。別名の名前の順)。別名を変えたものは`as`で取り込む。
     *
     * @return list<string>
     */
    public function typeImportLines(): array
    {
        $aliases = $this->aliases;
        ksort($aliases, SORT_STRING);
        $lines = [];

        foreach ($aliases as $alias => $name) {
            $lines[] = " * @phpstan-import-type {$alias} from \\TMSWP\\AcfBridge\\AcfTypes" . ($name === $alias ? '' : " as {$name}");
        }

        return $lines;
    }

    /** 型の別名$aliasに$nameを使えないか(生成クラスの短名か、$alias以外の別名・参照するクラスの短名と重なる)。 */
    private function isTaken(string $name, string $alias): bool
    {
        if (strcasecmp($name, $this->className) === 0) {
            return true;
        }

        foreach ([...self::TYPE_ALIASES, ...array_keys(self::CLASSES)] as $other) {
            if ($other !== $alias && strcasecmp($name, $other) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * use文。php-cs-fixerのordered_imports(alpha)と同じく、名前空間の区切りを空白とみなして大文字小文字を区別せずに並べる。
     *
     * @return list<string>
     */
    public function useLines(): array
    {
        $classes = array_values(array_intersect_key(self::CLASSES, $this->used));
        usort($classes, static fn(string $a, string $b): int => strcasecmp(str_replace('\\', ' ', $a), str_replace('\\', ' ', $b)));

        return array_map(static fn(string $class): string => "use {$class};", $classes);
    }
}
