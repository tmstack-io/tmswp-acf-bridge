<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Generate;

use TMSWP\AcfBridge\Json\FieldDefinition;
use TMSWP\AcfBridge\Json\FieldGroup;
use TMSWP\AcfBridge\Json\FlexibleLayout;

/**
 * acf-jsonのcloneを、ACF Proが実行時にするのと同じ規則で展開する。
 *
 * ACF Pro(pro/fields/class-acf-field-clone.php)は、cloneのフィールドを読み込むときに参照先のフィールドを複製して
 * サブフィールドにし(get_cloned_fields()・acf_clone_field())、displayがseamlessのcloneを、フィールドの一覧の中でその
 * サブフィールドに置き換える(acf_get_fields()のフィルタ)。このクラスはその処理を再現し、次の形のフィールドの一覧を返す。
 *
 * - seamlessのcloneが展開したフィールドは、ACFが保存する名前(prefix_nameなら`{clone}_{名前}`)を名前に、元のフィールドの
 *   keyをkeyに持つ(cloneが合成するkey`{clone}_{key}`はACFに登録されないため使わない)。prefix_labelならラベルは
 *   `{cloneのラベル} {ラベル}`。名前が元の定義と違えば、元の定義の名前をrawのDEFINITION_NAMEに持つ。
 * - groupのcloneは、group型のフィールドにする。サブフィールドは、ACFのformat_value()が値の配列のキーにする元の名前
 *   (`__name`)を名前に持つ。cloneのサブフィールドのcloneは、displayによらず、入れ子のgroupになる。ACFが値を保存する
 *   メタのキー(管理キー)を、rawのMETA_KEYSに持つ。
 */
final class CloneResolver
{
    /** rawのキー: ACFに登録された元の定義の名前(acf_get_field(key)のname)。cloneが保存する名前と違うときだけ持つ */
    public const string DEFINITION_NAME = 'acf_bridge_definition_name';

    /**
     * rawのキー: ACFがcloneの保存で書くメタの[メタのキー, そのフィールドの元のkey]のlist(管理キーになる)。メタのキーを
     * 配列のキーにしないのは、数字だけの名前(`1`等)をPHPが整数のキーにするため。groupのcloneのフィールドの
     * rawには、サブフィールドとサブフィールドのcloneの保存名を置く。グループのrawには、トップレベルでサブフィールドに
     * 置き換えたseamlessのclone自身の保存名を置く(ACFはcloneの名前にも空の値を保存する)。
     */
    public const string META_KEYS = 'acf_bridge_meta_keys';

    /** @var array<string, FieldGroup> */
    private array $groups = [];

    /** @var array<string, FieldDefinition> */
    private array $fields = [];

    /**
     * @param list<FieldGroup> $groups
     * @param list<string> $errors E004(参照先が無い・参照できない値)・E005(循環)を足す
     *
     * @return list<FieldGroup>
     */
    public function resolve(array $groups, array &$errors): array
    {
        $this->index($groups);
        $resolvedGroups = [];

        foreach ($groups as $group) {
            $splicedClones = [];
            $fields = $this->fieldsOf($group->fields, $group->key, [$group->key], $errors, $splicedClones);
            $raw = $group->raw;
            unset($raw[self::META_KEYS]);

            if ($splicedClones !== []) {
                $raw[self::META_KEYS] = $splicedClones;
            }

            $resolvedGroups[] = new FieldGroup(
                key: $group->key,
                title: $group->title,
                fields: $this->definitions($fields, $group->key, [$group->key], $errors),
                location: $group->location,
                active: $group->active,
                sourceFile: $group->sourceFile,
                raw: $raw,
            );
        }

        // 同じcloneを複数の場所から読むと、同じ誤りを重ねて報告するため
        $errors = array_values(array_unique($errors));

        return $resolvedGroups;
    }

    /** @param list<FieldGroup> $groups */
    private function index(array $groups): void
    {
        $this->groups = [];
        $this->fields = [];

        foreach ($groups as $group) {
            $this->groups[$group->key] = $group;

            foreach ($group->fields as $field) {
                $this->indexField($field);
            }
        }
    }

    private function indexField(FieldDefinition $field): void
    {
        if ($field->key !== '') {
            $this->fields[$field->key] = $field;
        }

        foreach ($field->subFields as $subField) {
            $this->indexField($subField);
        }

        foreach ($field->layouts as $layout) {
            foreach ($layout->subFields as $layoutField) {
                $this->indexField($layoutField);
            }
        }
    }

    /**
     * フィールドの一覧を読み込み、seamlessのcloneをそのサブフィールドに置き換える(ACFのacf_get_fields()のフィルタ。
     * 置き換えたサブフィールドにseamlessのcloneがあれば、それも置き換える)。
     *
     * @param list<FieldDefinition> $definitions
     * @param list<string> $stack 循環の検出に使う、読み込み中のcloneのkeyとグループのkey
     * @param list<string> $errors
     * @param list<array{string, string}>|null $splicedClones 置き換えたseamlessのcloneの[保存名, 元のkey]を足す(トップレベルの
     *                                                        一覧だけ。サブフィールドの保存名は行等の接頭辞が付くため集めない)
     *
     * @return list<ClonedField>
     */
    private function fieldsOf(array $definitions, string $groupKey, array $stack, array &$errors, ?array &$splicedClones = null): array
    {
        $fields = [];

        foreach ($definitions as $definition) {
            $fields[] = $this->load($definition, $groupKey, $stack, $errors);
        }

        $i = 0;

        while ($i < \count($fields)) {
            $field = $fields[$i];

            if (!$field->isClone() || $field->display() !== 'seamless' || $field->subFields === null) {
                ++$i;
                continue;
            }

            if ($splicedClones !== null) {
                $splicedClones[] = [$field->name, $field->originalKey ?? $field->key];
            }

            array_splice($fields, $i, 1, $field->subFields);
        }

        return $fields;
    }

    /**
     * 1つのフィールドを読み込む(ACFのacf_get_field())。cloneはサブフィールドを読み込む(load_field())。
     *
     * @param list<string> $stack
     * @param list<string> $errors
     */
    private function load(FieldDefinition $definition, string $groupKey, array $stack, array &$errors): ClonedField
    {
        $field = new ClonedField($definition, $definition->key, $definition->name, $definition->name, $definition->label);

        if ($field->isClone()) {
            $field->subFields = $this->clonedFields($field, $groupKey, $stack, $errors);
        }

        return $field;
    }

    /**
     * cloneの参照先のフィールドを読み込み、cloneの設定で複製する(ACFのget_cloned_fields())。参照の値は、`group_`で
     * 始まればグループ(ACFのacf_is_field_group_key())、`field_`で始まればフィールド(acf_is_field_key())として読む。
     *
     * @param list<string> $stack
     * @param list<string> $errors
     *
     * @return list<ClonedField>
     */
    private function clonedFields(ClonedField $clone, string $groupKey, array $stack, array &$errors): array
    {
        $refs = $this->cloneRefs($clone->definition, $groupKey, $errors);
        $stack = [...$stack, $clone->definition->key];
        $fields = [];

        foreach ($refs as $ref) {
            if (\in_array($ref, $stack, true)) {
                $errors[] = "E005: clone field '{$clone->definition->name}' in group '{$groupKey}' has a circular reference";
                continue;
            }

            if (str_starts_with($ref, 'group_')) {
                $group = $this->groups[$ref] ?? null;

                if (!$group instanceof FieldGroup) {
                    $errors[] = "E004: clone field '{$clone->definition->name}' in group '{$groupKey}' references missing group '{$ref}'";
                    continue;
                }

                $fields = [...$fields, ...$this->fieldsOf($group->fields, $groupKey, [...$stack, $ref], $errors)];
                continue;
            }

            if (str_starts_with($ref, 'field_')) {
                $definition = $this->fields[$ref] ?? null;

                if (!$definition instanceof FieldDefinition) {
                    $errors[] = "E004: clone field '{$clone->definition->name}' in group '{$groupKey}' references missing field '{$ref}'";
                    continue;
                }

                $fields[] = $this->load($definition, $groupKey, $stack, $errors);
                continue;
            }

            $errors[] = "E004: clone field '{$clone->definition->name}' in group '{$groupKey}' references '{$ref}', which is neither a field group key (group_) nor a field key (field_)";
        }

        return array_map(fn(ClonedField $field): ClonedField => $this->cloneField($field, $clone), $fields);
    }

    /**
     * ACFのacf_clone_field()。元のkey・名前を一度だけ退避し、seamlessならkeyとラベル(prefix_label)を、prefix_nameなら
     * 名前を変える(`_name`を変えるのはseamlessのときだけ)。複製するのがcloneなら、そのサブフィールドにも当てる。
     */
    private function cloneField(ClonedField $field, ClonedField $clone): ClonedField
    {
        $field = clone $field;
        $field->originalKey ??= $field->key;
        $field->originalName ??= $field->nameBase;
        $seamless = $clone->display() === 'seamless';

        if ($seamless) {
            $field->key = $clone->key . '_' . $field->key;

            if ($clone->prefixLabel()) {
                $field->label = $clone->label . ' ' . $field->label;
            }
        }

        if ($clone->prefixName()) {
            $field->name = $clone->name . '_' . $field->nameBase;

            if ($seamless) {
                $field->nameBase = $clone->nameBase . '_' . $field->nameBase;
            }
        }

        return $field->isClone() ? $this->cloneCloneField($field, $clone) : $field;
    }

    /**
     * ACFのacf_clone_clone_field()。複製したcloneがprefix_nameなら、外のcloneの名前をそのcloneの`_name`に変えてから、
     * サブフィールドを外のcloneの設定で複製する。
     */
    private function cloneCloneField(ClonedField $field, ClonedField $clone): ClonedField
    {
        if ($field->prefixName()) {
            $clone = clone $clone;
            $clone->name = $field->nameBase;
            $clone->nameBase = $field->nameBase;
        }

        if ($field->subFields === null || $field->subFields === []) {
            return $field;
        }

        $field->subFields = array_map(fn(ClonedField $subField): ClonedField => $this->cloneField($subField, $clone), $field->subFields);

        return $field;
    }

    /**
     * 展開したフィールドを、生成に使う定義にする。groupのcloneはgroup型にし、ほかのフィールドのサブフィールド
     * (group・repeater・flexible_content)も同じ規則で展開する。
     *
     * @param list<ClonedField> $fields
     * @param list<string> $stack
     * @param list<string> $errors
     *
     * @return list<FieldDefinition>
     */
    private function definitions(array $fields, string $groupKey, array $stack, array &$errors): array
    {
        $definitions = [];

        foreach ($fields as $field) {
            $definitions[] = $this->definition($field, $field->name, $groupKey, $stack, $errors);
        }

        return $definitions;
    }

    /**
     * @param string $name 定義の名前(トップレベルとgroup等のサブフィールドは保存する名前、groupのcloneのサブフィールドは
     *                     ACFのformat_value()が値の配列のキーにする元の名前)
     * @param list<string> $stack
     * @param list<string> $errors
     */
    private function definition(ClonedField $field, string $name, string $groupKey, array $stack, array &$errors): FieldDefinition
    {
        $definition = $field->definition;
        $raw = $definition->raw;
        $definitionName = $field->originalName ?? $definition->name;

        if ($definitionName !== $name) {
            $raw[self::DEFINITION_NAME] = $definitionName;
        }

        if ($field->isClone()) {
            $raw[self::META_KEYS] = $this->metaKeys($field);
            $subFields = [];

            foreach ($field->subFields ?? [] as $subField) {
                $subFields[] = $this->definition($subField, $subField->originalName ?? $subField->nameBase, $groupKey, $stack, $errors);
            }

            return $this->fieldWith($field, $name, 'group', $subFields, [], $raw);
        }

        $layouts = [];

        foreach ($definition->layouts as $layout) {
            $layouts[] = new FlexibleLayout($layout->name, $this->subDefinitions($layout->subFields, $groupKey, [...$stack, $definition->key, $layout->name], $errors));
        }

        return $this->fieldWith($field, $name, $definition->type, $this->subDefinitions($definition->subFields, $groupKey, [...$stack, $definition->key], $errors), $layouts, $raw);
    }

    /**
     * group・repeater・flexible_contentのサブフィールド。ACFはサブフィールドもacf_get_fields()で読むため、seamlessの
     * cloneを置き換え、値の配列のキーは`_name`(seamlessのprefix_nameの名前)になる。
     *
     * @param list<FieldDefinition> $definitions
     * @param list<string> $stack
     * @param list<string> $errors
     *
     * @return list<FieldDefinition>
     */
    private function subDefinitions(array $definitions, string $groupKey, array $stack, array &$errors): array
    {
        $resolved = [];

        foreach ($this->fieldsOf($definitions, $groupKey, $stack, $errors) as $field) {
            $resolved[] = $this->definition($field, $field->nameBase, $groupKey, $stack, $errors);
        }

        return $resolved;
    }

    /**
     * groupのcloneのサブフィールドの値を保存するメタの[キー, 元のkey]。ACFのload_value()と同じく、cloneの名前が`_name`と
     * 違えば、その差(接頭辞)をサブフィールドの名前に付け(prepare_field_for_db())、サブフィールドのcloneにも同じ規則を
     * 当てる。
     *
     * @return list<array{string, string}>
     */
    private function metaKeys(ClonedField $clone): array
    {
        $prefix = '';

        if ($clone->name !== $clone->nameBase && str_ends_with($clone->name, $clone->nameBase)) {
            $prefix = substr($clone->name, 0, \strlen($clone->name) - \strlen($clone->nameBase));
        }

        $keys = [];

        foreach ($clone->subFields ?? [] as $subField) {
            if (!$subField->isClone()) {
                $keys[] = [$prefix . $subField->name, $subField->originalKey ?? $subField->key];
                continue;
            }

            $subField = clone $subField;
            $subField->name = $prefix . $subField->name;
            // ACFはサブフィールドのcloneの名前にも、空の値を保存する
            $keys = [...$keys, [$subField->name, $subField->originalKey ?? $subField->key], ...$this->metaKeys($subField)];
        }

        return $keys;
    }

    /**
     * @param list<string> $errors
     *
     * @return list<string>
     */
    private function cloneRefs(FieldDefinition $field, string $groupKey, array &$errors): array
    {
        $refs = $field->raw['clone'] ?? null;

        if (!\is_array($refs)) {
            $errors[] = "E004: clone field '{$field->name}' in group '{$groupKey}' has no clone references";

            return [];
        }

        $strings = [];

        foreach ($refs as $ref) {
            if (!\is_string($ref) || $ref === '') {
                $errors[] = "E004: clone field '{$field->name}' in group '{$groupKey}' has an invalid clone reference";
                continue;
            }

            $strings[] = $ref;
        }

        return $strings;
    }

    /**
     * @param list<FieldDefinition> $subFields
     * @param list<FlexibleLayout> $layouts
     * @param array<string, mixed> $raw
     */
    private function fieldWith(ClonedField $field, string $name, string $type, array $subFields, array $layouts, array $raw): FieldDefinition
    {
        $definition = $field->definition;

        return new FieldDefinition(
            key: $field->originalKey ?? $definition->key,
            name: $name,
            label: $field->label,
            type: $type,
            required: $definition->required,
            returnFormat: $definition->returnFormat,
            multiple: $definition->multiple,
            fieldType: $definition->fieldType,
            subFields: $subFields,
            layouts: $layouts,
            raw: $raw,
        );
    }
}
