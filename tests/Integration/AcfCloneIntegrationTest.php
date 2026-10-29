<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Tests\Integration;

use TMSWP\AcfBridge\BridgeConfig;
use TMSWP\AcfBridge\Generate\CloneResolver;
use TMSWP\AcfBridge\Generate\GeneratedFile;
use TMSWP\AcfBridge\Generate\Generator;
use TMSWP\AcfBridge\Generate\GeneratorContext;
use TMSWP\AcfBridge\Json\FieldGroup;
use TMSWP\AcfBridge\Manifest\ManagedKey;
use TMSWP\AcfBridge\Manifest\ManagedKeyCollector;
use TMSWP\AcfBridge\Tests\Support\RestoresAcfStores;
use TMSWP\Testing\IntegrationTestCase;
use TMSWP\Testing\UsesTempDirectories;

/**
 * cloneのフィールドを、実物のACF Proで確かめる(docs/09 §6.6)。ACFの管理画面と同じ経路(cloneのkeyへのupdate_field()と
 * get_field())で保存した値を、生成アクセサが読み書き・削除・存在判定でき、管理キーがACFの保存したメタのキーと一致する。
 *
 * cloneはACF Proの型のため、グループacf-proとし、ACF Proを載せた隔離環境(composer test:integration:acf-pro)だけで
 * 走らせる(docs/09 §14)。
 *
 * @group acf-pro
 */
final class AcfCloneIntegrationTest extends IntegrationTestCase
{
    use RestoresAcfStores;
    use UsesTempDirectories;

    public function set_up(): void
    {
        parent::set_up();
        $this->snapshotAcfStores();
        self::assertTrue(acf_is_pro(), 'the acf-pro group runs only with ACF Pro (composer test:integration:acf-pro)');
    }

    public function tear_down(): void
    {
        // ACFのstore(ローカルのフィールドとグループ、テストが捨てた値のキャッシュを含む)はプロセスの中で続くため、
        // set_upの時点のdataとaliasesに戻す。どれかが失敗しても、ほかの後始末を飛ばさない
        try {
            $this->restoreAcfStores();
        } finally {
            try {
                $this->cleanupTempDirectories();
            } finally {
                parent::tear_down();
            }
        }
    }

    /**
     * @param array<string, mixed> $clone ホストのcloneの設定
     * @param array<string, mixed> $getters 生成クラスのgetter => ACFで保存した値
     *
     * @dataProvider clones
     */
    public function testGeneratedAccessorsReadWriteAndDeleteTheValuesAcfSaved(string $case, array $clone, array $getters): void
    {
        $postType = 'tmswp_clone_' . $case;
        $groups = $this->register($case, $postType, $clone);
        $postId = self::createPost($postType);
        $hostKey = "field_tmswp_clone_host_{$case}";

        // ACFの管理画面と同じく、cloneのkeyへ、サブフィールドのkey => 値の配列を保存する
        $hostClone = acf_get_field($hostKey);
        self::assertIsArray($hostClone);
        update_field($hostKey, $this->valuesOf($hostClone), $postId);

        $class = $this->generate($groups, $postType);
        $fields = $this->call($class, 'of', $postId);
        self::assertIsObject($fields);

        foreach ($getters as $getter => $expected) {
            self::assertSame($expected, $this->call($fields, $getter), "{$case}: {$getter}()");
        }

        // 管理キーは、ACFが保存したメタのキー(参照の`_`で始まるものを除く)と一致する
        $managed = array_values(array_map(
            static fn(ManagedKey $key): string => $key->metaKey,
            array_filter((new ManagedKeyCollector())->collect($this->resolved($groups), $this->config()), static fn(ManagedKey $key): bool => $key->objectSubtype === $postType),
        ));
        $meta = get_post_meta($postId);
        self::assertIsArray($meta);
        $saved = array_values(array_filter(array_map('strval', array_keys($meta)), static fn(string $key): bool => !str_starts_with($key, '_')));
        sort($managed);
        sort($saved);
        self::assertSame($saved, $managed, "{$case}: managed keys");

        // 生成アクセサのsetterが書いた値を、生成アクセサとACFの経路(cloneのkeyのget_field())の両方が読む
        foreach ($getters as $getter => $expected) {
            if (\is_string($expected)) {
                $this->call($fields, 'set' . ucfirst($getter), "{$expected} updated");
                self::assertSame("{$expected} updated", $this->call($fields, $getter), "{$case}: set{$getter}()");
                // ACFはcloneの値を(入れ子のcloneも)cloneの名前で覚えるため、サブフィールドの保存の後に読み直させる
                acf_get_store('values')->reset();
                self::assertContains("{$expected} updated", self::flatten(get_field($hostKey, $postId)), "{$case}: get_field() after set{$getter}()");
            }
        }

        foreach (array_keys($getters) as $getter) {
            $suffix = ucfirst($getter);
            self::assertTrue($this->call($fields, "has{$suffix}"), "{$case}: has{$suffix}()");
            self::assertTrue($this->call($fields, "delete{$suffix}"), "{$case}: delete{$suffix}()");
            self::assertFalse($this->call($fields, "has{$suffix}"), "{$case}: has{$suffix}() after delete");
        }
    }

    /**
     * 入れ子の配列の葉の値。
     *
     * @return list<mixed>
     */
    private static function flatten(mixed $value): array
    {
        if (!\is_array($value)) {
            return [$value];
        }

        $values = [];

        foreach ($value as $item) {
            $values = [...$values, ...self::flatten($item)];
        }

        return $values;
    }

    /** @return iterable<string, array{string, array<string, mixed>, array<string, mixed>}> */
    public static function clones(): iterable
    {
        // cloneの参照のSOURCE・MIDDLE・Mは、register()がこのデータセットのグループ・フィールドのkeyに置き換える
        yield 'seamless' => ['a', ['clone' => ['SOURCE'], 'display' => 'seamless'], ['title' => 'v-title', 'body' => 'v-body']];
        yield 'seamless・prefix_name' => ['b', ['clone' => ['SOURCE'], 'display' => 'seamless', 'prefix_name' => 1, 'prefix_label' => 1], ['cTitle' => 'v-title', 'cBody' => 'v-body']];
        yield 'group' => ['c', ['clone' => ['SOURCE'], 'display' => 'group'], ['c' => ['title' => 'v-title', 'body' => 'v-body']]];
        yield 'group・prefix_name' => ['d', ['clone' => ['SOURCE'], 'display' => 'group', 'prefix_name' => 1], ['c' => ['title' => 'v-title', 'body' => 'v-body']]];
        yield 'cloneのclone(field keyで参照)' => ['e', ['clone' => ['M'], 'display' => 'seamless', 'prefix_name' => 1], ['cMMTitle' => 'v-title', 'cMMBody' => 'v-body']];
        yield 'cloneのclone(グループで参照)' => ['f', ['clone' => ['MIDDLE'], 'display' => 'seamless', 'prefix_name' => 1], ['cMTitle' => 'v-title', 'cMBody' => 'v-body', 'cG' => ['title' => 'v-title', 'body' => 'v-body']]];
        yield 'groupのcloneのclone' => ['g', ['clone' => ['M'], 'display' => 'group', 'prefix_name' => 1], ['c' => ['m' => ['title' => 'v-title', 'body' => 'v-body']]]];
    }

    /**
     * 参照元・中間・ホストのグループを、データセットごとに違うkeyで、ACFのローカルのグループとして登録し、生成に渡す
     * グループを返す(ACFはローカルのフィールドの定義と値をプロセスの中で覚えるため、データセットの間でkeyを共有しない)。
     *
     * @param array<string, mixed> $clone
     *
     * @return list<FieldGroup>
     */
    private function register(string $case, string $postType, array $clone): array
    {
        $location = static fn(string $value): array => [[['param' => 'post_type', 'operator' => '==', 'value' => $value]]];
        $source = "group_tmswp_clone_src_{$case}";
        $refs = ['SOURCE' => $source, 'MIDDLE' => "group_tmswp_clone_mid_{$case}", 'M' => "field_tmswp_clone_mid_{$case}_m"];
        $cloneRefs = $clone['clone'] ?? null;
        self::assertIsArray($cloneRefs);
        $clone['clone'] = array_map(static fn(mixed $ref): string => \is_string($ref) ? $refs[$ref] : '', $cloneRefs);
        $definitions = [
            ['key' => $source, 'title' => 'source', 'location' => $location('tmswp_clone_source'), 'fields' => [
                ['key' => "field_tmswp_clone_src_{$case}_title", 'name' => 'title', 'label' => 'Title', 'type' => 'text'],
                ['key' => "field_tmswp_clone_src_{$case}_body", 'name' => 'body', 'label' => 'Body', 'type' => 'text'],
            ]],
            ['key' => $refs['MIDDLE'], 'title' => 'middle', 'location' => $location('tmswp_clone_middle'), 'fields' => [
                ['key' => $refs['M'], 'name' => 'm', 'label' => 'M', 'type' => 'clone', 'clone' => [$source], 'display' => 'seamless', 'prefix_name' => 1, 'prefix_label' => 1],
                ['key' => "field_tmswp_clone_mid_{$case}_g", 'name' => 'g', 'label' => 'G', 'type' => 'clone', 'clone' => [$source], 'display' => 'group', 'prefix_name' => 1],
            ]],
            ['key' => "group_tmswp_clone_host_{$case}", 'title' => 'host', 'location' => $location($postType), 'fields' => [
                ['key' => "field_tmswp_clone_host_{$case}", 'name' => 'c', 'label' => 'C', 'type' => 'clone'] + $clone,
            ]],
        ];
        $groups = [];

        foreach ($definitions as $definition) {
            self::assertTrue(acf_add_local_field_group($definition));
            $groups[] = FieldGroup::fromArray($definition, $definition['key'] . '.json');
        }

        return $groups;
    }

    /**
     * cloneのサブフィールド(ACFが読み込んだもの)のkey => 値。cloneのサブフィールドのcloneは、その中の値の配列。値は
     * `v-{元の名前}`。
     *
     * @param array<array-key, mixed> $clone
     *
     * @return array<string, mixed>
     */
    private function valuesOf(array $clone): array
    {
        $values = [];
        $subFields = $clone['sub_fields'] ?? [];
        self::assertIsArray($subFields);

        foreach ($subFields as $subField) {
            self::assertIsArray($subField);
            self::assertIsString($subField['key'] ?? null);
            self::assertIsString($subField['__name'] ?? null);
            $values[$subField['key']] = ($subField['type'] ?? null) === 'clone' ? $this->valuesOf($subField) : "v-{$subField['__name']}";
        }

        return $values;
    }

    /**
     * $groupsから、$postTypeのクラスを生成して読み込み、そのクラス名を返す。
     *
     * @param list<FieldGroup> $groups
     */
    private function generate(array $groups, string $postType): string
    {
        $namespace = self::THEME_NAMESPACE . '\\AcfCloneProbe' . uniqid();
        $outputDir = $this->createTempDirectory('acf-clone');
        $report = (new Generator())->generate($groups, new GeneratorContext(
            config: new BridgeConfig(jsonDirs: [], outputDir: $outputDir, namespace: $namespace),
            optionsPagePostIds: [],
            sourceHash: 'clone',
        ));
        self::assertSame([], $report->errors());
        $className = str_replace(' ', '', ucwords(str_replace('_', ' ', $postType))) . 'Fields';
        $files = array_values(array_filter($report->files(), static fn(GeneratedFile $file): bool => $file->relativePath === "{$className}.php"));
        self::assertCount(1, $files);
        self::assertNotFalse(file_put_contents("{$outputDir}/{$className}.php", $files[0]->contents));
        require "{$outputDir}/{$className}.php";

        return "{$namespace}\\{$className}";
    }

    /**
     * @param list<FieldGroup> $groups
     *
     * @return list<FieldGroup>
     */
    private function resolved(array $groups): array
    {
        $errors = [];
        $resolved = (new CloneResolver())->resolve($groups, $errors);
        self::assertSame([], $errors);

        return $resolved;
    }

    private function config(): BridgeConfig
    {
        return new BridgeConfig(jsonDirs: [], outputDir: '/tmp/acf-clone', namespace: self::THEME_NAMESPACE . '\\AcfClone');
    }

    /** 生成クラス(PHPStanの解析対象外)のメソッドを呼ぶ。 */
    private function call(object|string $target, string $method, mixed ...$arguments): mixed
    {
        $callable = [$target, $method];
        self::assertIsCallable($callable);

        return $callable(...$arguments);
    }
}
