<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Mapping;

use TMSWP\AcfBridge\BridgeConfig;
use TMSWP\AcfBridge\Generate\NameConverter;
use TMSWP\AcfBridge\Json\FieldGroup;

/**
 * フィールドグループのlocationを、生成クラスの対象(GenerationTarget)に解決する(docs/09 §4.2)。locationの各ANDグループを
 * 1つの対象に解決し、ORの全ANDグループの対象の和集合を返す。
 */
final class LocationResolver
{
    /**
     * 値源(ACFが画面の1つの値と比べるparamの、比べる値)ごとのparam。1つのANDグループの中で、同じ値源に`all`でない違う
     * `==`の値が2つ以上ある、同じ値の`==`と`!=`がある、`!= all`がある場合は成り立たない(W007)。値の同じ・違うは
     * compare_to_rule()と同じPHPの`==`で判定する(sameValue())。ACF 6.2.0・6.8.10の
     * `includes/locations/`の各クラス(options_pageはACF Pro 6.5.1・6.8.8の`pro/locations/`)のmatch()が、次の1つの値を
     * compare_to_rule()で比べる(`all`はどの値にも当てはまり、`!=`は結果を反転する)。
     *
     * - post_type: 画面の投稿タイプ
     * - template: 投稿のメタ`_wp_page_template`(空はdefault)。page_templateはpost_templateのmatch()に委ねる
     * - post_id: 画面の投稿のID。pageはpostのmatch()に委ねる
     * - page_parent・post_status・post_format・taxonomy・options_page: 親のID・状態・フォーマット・タクソノミー・menu_slug
     */
    private const array VALUE_SOURCES = [
        'post_type' => ['post_type'],
        'template' => ['page_template', 'post_template'],
        'post_id' => ['page', 'post'],
        'page_parent' => ['page_parent'],
        'post_status' => ['post_status'],
        'post_format' => ['post_format'],
        'taxonomy' => ['taxonomy'],
        'options_page' => ['options_page'],
    ];

    /**
     * ACFのmatch()が、operatorを調べる前にtrueを返す経路を持つparam。その経路では`==`と`!=`の両方が成り立つため、
     * `==`と`!=`の矛盾の検査から外す(安全な側。生成から除かない)。ACF 6.2.0・6.8.10の`includes/locations/`の全クラス
     * と、ACF Pro 6.5.1・6.8.8の`pro/locations/`の全クラスのmatch()を読み、operatorによらずtrueを返す経路は次だけだった
     * (operatorによらずfalseを返す経路は、`==`と`!=`の両方を成り立たなくするだけで、矛盾の検査を誤らせない)。
     *
     * - user_form: RESTの画面(`$screen['rest']`。ACFのREST APIがユーザーのフィールドグループを選ぶときに渡す)では
     *   trueを返す(6.8.10のclass-acf-location-user-form.php:49-51、6.2.0の同ファイル:40-42)
     */
    private const array OPERATOR_INDEPENDENT_PARAMS = ['user_form'];

    /** 投稿タイプを決めず、ほかの条件の投稿タイプに従うparam(post_typeがあればそれを使う) */
    private const array IMPLIED_POST_TYPES = [
        'page_type' => 'page',
        'page' => 'page',
        'page_parent' => 'page',
        'post_category' => 'post',
        'post_format' => 'post',
        'attachment' => 'attachment',
    ];

    /** 保存先を決めない(表示の絞り込みの)param */
    private const array IGNORED_PARAMS = ['current_user', 'current_user_role', 'post_status', 'post_taxonomy'];

    /** acf-bridgeが対応しないparam(W006) */
    private const array UNSUPPORTED_PARAMS = ['comment', 'widget', 'nav_menu', 'nav_menu_item', 'block'];

    /** 種別が1つのANDグループに複数あるときの優先順(W004) */
    private const array KIND_PRIORITY = [TargetKind::Post, TargetKind::Term, TargetKind::Options, TargetKind::User];

    /**
     * @return list<GenerationTarget>
     */
    public function resolve(FieldGroup $group, BridgeConfig $config): array
    {
        $warnings = [];

        return $this->resolveWithWarnings($group, $config, $warnings);
    }

    /**
     * @param list<string> $warnings 解決できないANDグループの警告(W002・W003・W006・W007)と、種別の優先順を当てた警告(W004)
     *
     * @return list<GenerationTarget>
     */
    public function resolveWithWarnings(FieldGroup $group, BridgeConfig $config, array &$warnings): array
    {
        $targets = [];

        foreach ($group->location as $index => $andGroup) {
            if (!\is_array($andGroup)) {
                continue;
            }

            $target = $this->resolveAndGroup($andGroup, $group->key, $index + 1, $warnings);

            if (!$target instanceof GenerationTarget) {
                continue;
            }

            $className = $config->classMap[$group->key] ?? $target->className;
            $targets[$target->kind->value . "\0" . $target->subtype . "\0" . ($target->template ?? '')] = new GenerationTarget($target->kind, $target->subtype, $className, $target->template);
        }

        ksort($targets, SORT_STRING);

        return array_values($targets);
    }

    /**
     * 1つのANDグループの`==`の条件を、すべて満たす1つの対象に解決する。解決できなければ、理由の警告を足してnullを返す。
     *
     * @param array<array-key, mixed> $conditions
     * @param int $number ANDグループの番号(1から。警告に出す)
     * @param list<string> $warnings
     */
    private function resolveAndGroup(array $conditions, string $groupKey, int $number, array &$warnings): ?GenerationTarget
    {
        // paramごとの`==`の値と`!=`の値(空でないもの)
        $values = [];
        $negated = [];

        foreach ($conditions as $condition) {
            if (!\is_array($condition)) {
                continue;
            }

            $operator = $this->string($condition, 'operator');
            $param = $this->string($condition, 'param');
            $value = $this->string($condition, 'value');

            if ($operator === '!=' && $value !== '') {
                $negated[$param][] = $value;
            }

            if ($operator !== '==') {
                continue;
            }

            if (\in_array($param, self::UNSUPPORTED_PARAMS, true)) {
                $warnings[] = $param === 'block'
                    ? "W006: ACF field group '{$groupKey}' uses unsupported location param 'block'; acf-bridge does not generate accessors for ACF Blocks, so build custom blocks as described in docs/12 §1.11 (D-26)"
                    : "W006: ACF field group '{$groupKey}' uses unsupported location param '{$param}'";

                return null;
            }

            if (!$this->isKnownParam($param)) {
                $warnings[] = "W006: ACF field group '{$groupKey}' uses unknown location param '{$param}'";

                return null;
            }

            if ($value !== '') {
                $values[$param][] = $value;
            }
        }

        $contradiction = $this->contradiction($values, $negated);

        if ($contradiction !== null) {
            $warnings[] = "W007: ACF field group '{$groupKey}' has an unsatisfiable location condition for {$contradiction}";

            return null;
        }

        $kinds = [];

        foreach ($values as $param => $paramValues) {
            $kind = $this->kindOf($param);

            if ($kind instanceof TargetKind) {
                $kinds[$kind->value] = $kind;
            }
        }

        if ($kinds === []) {
            if (\array_key_exists('post', $values)) {
                $warnings[] = "W003: ACF field group '{$groupKey}' uses a post ID location that cannot be resolved statically";
            } else {
                $warnings[] = "W002: location AND group {$number} of ACF field group '{$groupKey}' was skipped: it has no '==' condition that decides the target";
            }

            return null;
        }

        if (\count($kinds) > 1) {
            $warnings[] = "W004: ACF field group '{$groupKey}' has multiple target location kinds; priority was applied";
        }

        foreach (self::KIND_PRIORITY as $kind) {
            if (\array_key_exists($kind->value, $kinds)) {
                return match ($kind) {
                    TargetKind::Post => $this->postTarget($values, $groupKey, $number, $warnings),
                    TargetKind::Term => $this->termTarget($this->single($values, 'taxonomy')),
                    TargetKind::Options => new GenerationTarget(TargetKind::Options, $this->single($values, 'options_page'), $this->className($this->single($values, 'options_page'), 'Options')),
                    TargetKind::User => new GenerationTarget(TargetKind::User, '', 'UserFields'),
                };
            }
        }

        return null;
    }

    /**
     * 投稿の対象。投稿タイプは`post_type`の値で、無ければほかの条件(page_typeならpage等)から決める。テンプレート
     * (`page_template`・`post_template`のdefault以外)があれば、テンプレート別のクラスにする。
     *
     * @param array<string, list<string>> $values
     * @param list<string> $warnings
     */
    private function postTarget(array $values, string $groupKey, int $number, array &$warnings): ?GenerationTarget
    {
        $pageTemplate = \array_key_exists('page_template', $values) ? $this->single($values, 'page_template') : null;
        $postTemplate = \array_key_exists('post_template', $values) ? $this->single($values, 'post_template') : null;
        $postType = \array_key_exists('post_type', $values) ? $this->single($values, 'post_type') : null;

        // ACFのpage_template==defaultは、page以外の投稿タイプでは成り立たない
        if ($pageTemplate === 'default' && $postType !== null && !\in_array($postType, ['page', 'all'], true)) {
            $warnings[] = "W007: ACF field group '{$groupKey}' has an unsatisfiable location condition for 'page_template' (the default template matches only pages, but post_type is '{$postType}')";

            return null;
        }

        $template = null;

        foreach ([$pageTemplate, $postTemplate] as $value) {
            if ($value !== null && !\in_array($value, ['default', 'all'], true)) {
                $template = $value;
            }
        }

        if ($postType === null) {
            // post_templateは、page_templateの有無によらず、同じANDグループのpost_typeで投稿タイプを決める
            if ($postTemplate !== null) {
                $warnings[] = "W002: location AND group {$number} of ACF field group '{$groupKey}' was skipped: 'post_template' needs a 'post_type ==' condition in the same AND group to decide the post type";

                return null;
            }

            $implied = [];

            foreach (self::IMPLIED_POST_TYPES as $param => $impliedPostType) {
                if (\array_key_exists($param, $values)) {
                    $implied[$impliedPostType] = true;
                }
            }

            if ($pageTemplate !== null) {
                $implied['page'] = true;
            }

            $impliedTypes = array_keys($implied);

            if (\count($impliedTypes) !== 1) {
                $warnings[] = "W002: location AND group {$number} of ACF field group '{$groupKey}' was skipped: its conditions imply different post types (" . implode(', ', $impliedTypes) . ") without a 'post_type ==' condition";

                return null;
            }

            $postType = $impliedTypes[0];
        }

        if ($template !== null) {
            return new GenerationTarget(TargetKind::Post, $postType, $this->className(pathinfo($template, PATHINFO_FILENAME), 'TemplateFields'), $template);
        }

        return new GenerationTarget(TargetKind::Post, $postType, $this->className($postType, 'Fields'));
    }

    private function termTarget(string $taxonomy): GenerationTarget
    {
        return new GenerationTarget(TargetKind::Term, $taxonomy, $taxonomy === 'all' ? 'TermFields' : $this->className($taxonomy, 'TermFields'));
    }

    /**
     * `{studly($name)}{$suffix}`。studlyが空(ASCIIの英数字が無い名前)なら空にし、生成時のE003にする(docs/09 §4.3)。
     */
    private function className(string $name, string $suffix): string
    {
        $studly = NameConverter::studly($name);

        return $studly === '' ? '' : $studly . $suffix;
    }

    /**
     * 1つのANDグループの中の、静的に判別できる矛盾。値源(VALUE_SOURCES)ごとに、`all`でない違う`==`の値、同じ値の
     * `==`と`!=`、`!= all`(どの値にも当てはまる`all`の否定)を調べる。値源に入らない(値を複数持てる)paramは、同じ
     * paramの、完全に同じ文字列の`==`と`!=`だけを調べる(同じruleの値なら同じ述語になり、`!=`がそれを反転する)。
     * ただし、OPERATOR_INDEPENDENT_PARAMSのparamは`!=`が`==`の結果の反転にならない経路を持つため、`==`と`!=`の照合
     * から外す。値源の値の同じ・違うは、compare_to_rule()と同じsameValue()で判定する。矛盾があれば、警告に出すparamと
     * 理由を返す。
     *
     * @param array<string, list<string>> $values paramごとの`==`の値
     * @param array<string, list<string>> $negated paramごとの`!=`の値
     */
    private function contradiction(array $values, array $negated): ?string
    {
        $sourced = [];

        foreach (self::VALUE_SOURCES as $params) {
            $present = array_values(array_filter($params, static fn(string $param): bool => \array_key_exists($param, $values) || \array_key_exists($param, $negated)));

            if ($present === []) {
                continue;
            }

            $sourced = [...$sourced, ...$params];
            $names = "'" . implode("' and '", $present) . "'";
            $equal = array_values(array_filter(array_merge(...array_map(static fn(string $param): array => $values[$param] ?? [], $params)), static fn(string $value): bool => $value !== 'all'));
            $notEqual = array_merge(...array_map(static fn(string $param): array => $negated[$param] ?? [], $params));

            foreach ($equal as $value) {
                foreach ($equal as $other) {
                    if (!$this->sameValue($value, $other)) {
                        return $names;
                    }
                }
            }

            if (\in_array('all', $notEqual, true)) {
                return "{$names}; '!= all' never matches";
            }

            foreach ($notEqual as $value) {
                if ($this->containsSameValue($equal, $value)) {
                    return "{$names}; '==' and '!=' compare the same value '{$value}'";
                }
            }
        }

        foreach ($negated as $param => $notEqual) {
            if (\in_array($param, $sourced, true) || \in_array($param, self::OPERATOR_INDEPENDENT_PARAMS, true)) {
                continue;
            }

            foreach ($notEqual as $value) {
                // ruleの値ごとに前処理をして比べるparam(user_roleのuser_can()等)は、PHPの==で同じ値でも別の述語になりうる
                if (\in_array($value, $values[$param] ?? [], true)) {
                    return "'{$param}'; '==' and '!=' compare the same value '{$value}'";
                }
            }
        }

        return null;
    }

    /**
     * locationの2つの値が、ACFのcompare_to_rule()(画面の値とruleの値をPHPの`==`で比べる)で同じ値として扱われるか。
     * PHP 8の`==`は、数値の文字列どうしを数値として比べ(`'1'`と`'01'`は同じ)、それ以外の文字列どうしを文字列として
     * 比べる(`'Product'`と`'product'`は違う)。文字列の`<=>`は`==`と同じ比べ方をする。
     */
    private function sameValue(string $a, string $b): bool
    {
        return ($a <=> $b) === 0;
    }

    /** @param list<string> $values */
    private function containsSameValue(array $values, string $value): bool
    {
        foreach ($values as $candidate) {
            if ($this->sameValue($candidate, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * W007の検査を通ったparamの値。`all`でない値があればそれ、無ければ`all`。
     *
     * @param array<string, list<string>> $values
     */
    private function single(array $values, string $param): string
    {
        $paramValues = $values[$param] ?? [];

        foreach ($paramValues as $value) {
            if ($value !== 'all') {
                return $value;
            }
        }

        return $paramValues === [] ? '' : 'all';
    }

    private function kindOf(string $param): ?TargetKind
    {
        return match ($param) {
            'post_type', 'post_template', 'page_template', 'page_type', 'page', 'page_parent', 'post_category', 'post_format', 'attachment' => TargetKind::Post,
            'taxonomy' => TargetKind::Term,
            'options_page' => TargetKind::Options,
            'user_form', 'user_role' => TargetKind::User,
            default => null,
        };
    }

    private function isKnownParam(string $param): bool
    {
        return $this->kindOf($param) instanceof TargetKind || $param === 'post' || \in_array($param, self::IGNORED_PARAMS, true);
    }

    /** @param array<array-key, mixed> $data */
    private function string(array $data, string $key): string
    {
        $value = $data[$key] ?? '';

        return \is_scalar($value) ? (string) $value : '';
    }
}
