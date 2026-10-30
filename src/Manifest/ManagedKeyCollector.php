<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Manifest;

use TMSWP\AcfBridge\BridgeConfig;
use TMSWP\AcfBridge\Generate\CloneResolver;
use TMSWP\AcfBridge\Generate\TypeMapper;
use TMSWP\AcfBridge\Json\FieldGroup;
use TMSWP\AcfBridge\Mapping\LocationResolver;
use TMSWP\AcfBridge\Mapping\TargetKind;
use TMSWP\Core\Meta\ObjectType;

/**
 * フィールドグループからACFの管理キー(生成物AcfManifestが返すもの)を集める。`wp tmswp acf check`のE101の
 * 検査がこれを使う。
 */
final class ManagedKeyCollector
{
    public function __construct(
        private readonly LocationResolver $locations = new LocationResolver(),
        private readonly TypeMapper $types = new TypeMapper(),
    ) {}

    /**
     * 有効なグループの、locationで解決したターゲットごとのトップレベルのフィールドを返す。cloneは解決済みの
     * グループを渡す(seamlessのcloneが展開したフィールドも管理キーになる)。並びはAcfManifestの行と同じ
     * (ObjectTypeのcase名の順でoptionsは最後、次にサブタイプ、メタキー)。
     *
     * 同じ(ObjectType, サブタイプ, メタキー)は1つにする。テンプレート別のクラスと投稿タイプのクラス、別のテンプレートの
     * クラスが同じ名前のフィールドを持つと、同じ投稿のメタの同じキーになるため。残すのは、グループのkey・field keyの
     * 昇順で最初のもの。
     *
     * @param list<FieldGroup> $groups CloneResolverでcloneを解決したグループ
     *
     * @return list<ManagedKey>
     */
    public function collect(array $groups, BridgeConfig $config): array
    {
        $keys = [];

        foreach ($groups as $group) {
            if (!$group->active) {
                continue;
            }

            foreach ($this->locations->resolve($group, $config) as $target) {
                $objectType = match ($target->kind) {
                    TargetKind::Post => ObjectType::Post,
                    TargetKind::Term => ObjectType::Term,
                    TargetKind::User => ObjectType::User,
                    TargetKind::Options => null,
                };

                // トップレベルでサブフィールドに置き換えたseamlessのclone自身の保存名
                foreach ($this->cloneMetaKeys($group->raw) as [$metaKey, $fieldKey]) {
                    $keys[] = new ManagedKey($objectType, $target->subtype, $metaKey, $fieldKey, $group->key);
                }

                foreach ($group->fields as $field) {
                    if (!$this->types->storesValue($field) || $field->name === '') {
                        continue;
                    }

                    $keys[] = new ManagedKey($objectType, $target->subtype, $field->name, $field->key, $group->key);

                    // groupのcloneは、サブフィールドの値をそれぞれの保存名のメタに保存する
                    foreach ($this->cloneMetaKeys($field->raw) as [$metaKey, $fieldKey]) {
                        $keys[] = new ManagedKey($objectType, $target->subtype, $metaKey, $fieldKey, $group->key);
                    }
                }
            }
        }

        usort($keys, static fn(ManagedKey $a, ManagedKey $b): int => [self::sortKey($a), $a->groupKey, $a->fieldKey] <=> [self::sortKey($b), $b->groupKey, $b->fieldKey]);
        $unique = [];

        foreach ($keys as $key) {
            $unique[self::sortKey($key)] ??= $key;
        }

        return array_values($unique);
    }

    /**
     * ACFがcloneの保存で書くメタの[キー, 元のkey](CloneResolverがフィールド・グループのrawのMETA_KEYSに置いたもの)。
     *
     * @param array<string, mixed> $raw
     *
     * @return list<array{string, string}>
     */
    private function cloneMetaKeys(array $raw): array
    {
        $metaKeys = $raw[CloneResolver::META_KEYS] ?? [];
        $keys = [];

        foreach (\is_array($metaKeys) ? $metaKeys : [] as $pair) {
            if (\is_array($pair) && \is_string($pair[0] ?? null) && \is_string($pair[1] ?? null)) {
                $keys[] = [$pair[0], $pair[1]];
            }
        }

        return $keys;
    }

    private static function sortKey(ManagedKey $key): string
    {
        $type = $key->objectType instanceof ObjectType ? 'ObjectType::' . $key->objectType->name : 'options';

        return $type . "\0" . $key->objectSubtype . "\0" . $key->metaKey;
    }
}
