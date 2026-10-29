<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Json;

use JsonException;
use TMSWP\AcfBridge\Exception\GenerationException;

/**
 * acf-jsonのディレクトリ(jsonDirs)を、ACFのLocal JSON(ACF 6.2〜6.8の`ACF_Local_JSON::scan_files()`・`get_files()`)と
 * 同じ規則で読む。
 *
 * - 各ディレクトリ直下の`*.json`を読み、トップレベルがオブジェクトで`key`を持つものだけを使う。
 * - 同じkeyのJSONは、jsonDirsの並びで先のディレクトリのものを使う(後のものはW010)。AcfBridgeは、ACFが同じファイルを
 *   使うようにacf/settings/load_jsonの並びを決める。同じディレクトリの中で同じkeyのJSONが2つ以上あるとE102にする。
 * - keyが`group_`で始まるもの、または`fields`を持つもの(keyの接頭辞を問わない)をフィールドグループとして読む。
 *   これとは別に、keyが`ui_options_page_`で始まるものはオプションページのpost_idとして読む(両方に当たるJSONは両方に
 *   使う)。`post_type_`・`taxonomy_`の定義(CPT・タクソノミーの登録)と、どちらにも当たらないJSONはW001にする。
 */
final class AcfJsonRepository
{
    /** 生成で使わない、ACFのUIで登録するCPT・タクソノミーのkeyの接頭辞 */
    private const array UNSUPPORTED_TYPE_PREFIXES = ['post_type_', 'taxonomy_'];

    private const string OPTIONS_PAGE_PREFIX = 'ui_options_page_';

    /** @param list<string> $jsonDirs acf-jsonのディレクトリ(先のディレクトリのJSONを使う) */
    public function __construct(private readonly array $jsonDirs) {}

    /**
     * 全ディレクトリのJSONを1回ずつ読み、フィールドグループ・オプションページのpost_id・警告・source hashを返す。
     *
     * @throws GenerationException E102: ディレクトリ・ファイルを読めない、JSONの構文の誤り、トップレベルが配列(list)の
     *                             JSON、同じディレクトリの中の同じkeyのJSON
     */
    public function load(): AcfJsonSource
    {
        $groups = [];
        $hashItems = [];
        $postIds = [];
        $warnings = [];
        // keyごとに使うファイル
        $used = [];

        foreach ($this->directories() as $dir) {
            // このディレクトリのkeyごとのファイル
            $inDirectory = [];

            foreach ($this->jsonFiles($dir) as $file) {
                $read = $this->read($file, $warnings);

                if ($read === null) {
                    continue;
                }

                [$key, $item] = $read;

                if (\array_key_exists($key, $inDirectory)) {
                    throw new GenerationException("E102: ACF JSON files {$inDirectory[$key]} and {$file} in the same directory have the same key '{$key}'; ACF uses only one of them, chosen by the file name, so remove or rename the key of one of them");
                }

                $inDirectory[$key] = $file;

                if (\array_key_exists($key, $used)) {
                    $warnings[] = "W010: ACF JSON '{$key}' in {$file} was ignored; {$used[$key]} in an earlier directory of jsonDirs is used";
                    continue;
                }

                $used[$key] = $file;

                $isGroup = $this->isFieldGroup($key, $item);
                $isOptionsPage = str_starts_with($key, self::OPTIONS_PAGE_PREFIX);

                if ($isGroup) {
                    $groups[] = FieldGroup::fromArray($item, $file);
                    $hashItems[] = ['key' => $key, 'item' => $this->normalize($item)];
                }

                if ($isOptionsPage) {
                    $menuSlug = $this->string($item, 'menu_slug');
                    $postId = $this->string($item, 'post_id');

                    if ($menuSlug !== '' && $postId !== '') {
                        $postIds[$menuSlug] = $postId;
                    }
                }

                if ($this->isUnsupportedType($key)) {
                    $warnings[] = $isGroup
                        ? "W001: the ACF post type or taxonomy definition '{$key}' in {$file} was ignored; its fields are read as a field group, as ACF does"
                        : "W001: unsupported ACF JSON '{$key}' in {$file} was ignored";
                } elseif (!$isGroup && !$isOptionsPage) {
                    $warnings[] = "W001: unsupported ACF JSON '{$key}' in {$file} was ignored";
                }
            }
        }

        usort($groups, static fn(FieldGroup $a, FieldGroup $b): int => $a->key <=> $b->key);
        usort($hashItems, static fn(array $a, array $b): int => $a['key'] <=> $b['key']);
        ksort($postIds, SORT_STRING);

        return new AcfJsonSource(
            $groups,
            $postIds,
            $warnings,
            hash('sha256', json_encode(array_column($hashItems, 'item'), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        );
    }

    /**
     * 存在するjsonDirs(末尾の区切りを除き、重複を除く。並びは保つ)。
     *
     * @return list<string>
     */
    private function directories(): array
    {
        $dirs = [];

        foreach ($this->jsonDirs as $dir) {
            $dir = rtrim($dir, '/\\');

            if (is_dir($dir) && !\in_array($dir, $dirs, true)) {
                $dirs[] = $dir;
            }
        }

        return $dirs;
    }

    /**
     * ディレクトリ直下の`*.json`のファイル(名前の昇順)。
     *
     * @throws GenerationException E102: ディレクトリを読めない
     *
     * @return list<string>
     */
    private function jsonFiles(string $dir): array
    {
        $matches = glob($dir . '/*.json');

        if ($matches === false) {
            throw new GenerationException("E102: failed to list ACF JSON files in {$dir}");
        }

        $files = array_values(array_filter($matches, is_file(...)));
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * JSONファイルを読み、トップレベルのオブジェクトのkeyとオブジェクトを返す。ACFが読まない形のうち、トップレベルが
     * 非配列のものと`key`の無いもの(空の文字列・文字列でないものを含む)はW001を$warningsに足してnullを返す。
     *
     * @param list<string> $warnings
     *
     * @throws GenerationException E102: ファイルを読めない、JSONの構文の誤り、トップレベルが配列(list)
     *
     * @return array{string, array<string, mixed>}|null
     */
    private function read(string $file, array &$warnings): ?array
    {
        $contents = file_get_contents($file);

        if ($contents === false) {
            throw new GenerationException("E102: failed to read ACF JSON file {$file}");
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new GenerationException("E102: invalid ACF JSON file {$file}: {$exception->getMessage()}", previous: $exception);
        }

        if (!\is_array($decoded)) {
            $warnings[] = "W001: ACF JSON file {$file} was ignored because its top level is " . get_debug_type($decoded) . ', not an object';

            return null;
        }

        // json_decode()は`[...]`と`{...}`をどちらもPHPの配列にするため、トップレベルの記号で区別する
        if (str_starts_with(ltrim($contents, " \t\n\r"), '[')) {
            throw new GenerationException("E102: ACF JSON file {$file} has an array at the top level; ACF reads only a single object (one field group, post type, taxonomy or options page) per file, so split it into one file per item");
        }

        $item = $this->stringKeyed($decoded);
        $key = $item['key'] ?? null;

        if (!\is_string($key) || $key === '') {
            $warnings[] = "W001: ACF JSON file {$file} was ignored because it has no string key";

            return null;
        }

        return [$key, $item];
    }

    /**
     * ACFのLocal JSONがフィールドグループとして読むか。ACF(6.2〜6.8)の`get_files('acf-field-group')`は、keyが`group_`で
     * 始まるものに加え、keyの接頭辞がほかの種別に当たるものも含めて、`fields`を持つ(`isset()`)ものをグループにする。
     *
     * @param array<string, mixed> $item
     */
    private function isFieldGroup(string $key, array $item): bool
    {
        return str_starts_with($key, 'group_') || isset($item['fields']);
    }

    /** ACFのUIで登録するCPT・タクソノミーの定義のkeyか(生成では使わない)。 */
    private function isUnsupportedType(string $key): bool
    {
        foreach (self::UNSUPPORTED_TYPE_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * source hashのための正規化。`modified`(ACFが保存のたびに変える時刻)を全階層で除き、オブジェクトのキーを並べる。
     * `type`が`flexible_content`のフィールドの定義の`layouts`だけは、順に意味があるため並べ替えない(orderedLayouts())。
     * それ以外の`layouts`というキー(groupのmetadata・selectのchoices等)は、通常のオブジェクトとして扱う。
     *
     * @param array<string, mixed> $item
     *
     * @return array<string, mixed>
     */
    private function normalize(array $item): array
    {
        unset($item['modified']);

        foreach ($item as $key => $value) {
            if (\is_array($value)) {
                $item[$key] = $this->normalizeMixed($value);
            }
        }

        ksort($item, SORT_STRING);

        return $item;
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     */
    private function normalizeMixed(array $value): array
    {
        unset($value['modified']);
        $isFlexibleContent = ($value['type'] ?? null) === 'flexible_content';

        foreach ($value as $key => $item) {
            if (\is_array($item)) {
                $value[$key] = $isFlexibleContent && $key === 'layouts' ? $this->orderedLayouts($item) : $this->normalizeMixed($item);
            }
        }

        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    /**
     * flexible_contentのフィールドの定義の`layouts`(ACFはレイアウトのkeyをキーにしたオブジェクトで保存する)を、JSONの
     * 順を保つ`[キー, レイアウト]`のlistにする。レイアウトの順は生成コードの順になるため、並べ替えずにハッシュに反映する。
     * `modified`は、ほかのオブジェクトと同じく除く。
     *
     * @param array<array-key, mixed> $layouts
     *
     * @return list<array{array-key, mixed}>
     */
    private function orderedLayouts(array $layouts): array
    {
        $ordered = [];

        unset($layouts['modified']);

        foreach ($layouts as $key => $layout) {
            $ordered[] = [$key, \is_array($layout) ? $this->normalizeMixed($layout) : $layout];
        }

        return $ordered;
    }

    /** @param array<string, mixed> $data */
    private function string(array $data, string $key): string
    {
        $value = $data[$key] ?? '';

        return \is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function stringKeyed(array $data): array
    {
        $normalized = [];

        foreach ($data as $key => $value) {
            if (\is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }
}
