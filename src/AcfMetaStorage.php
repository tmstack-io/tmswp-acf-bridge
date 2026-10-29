<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge;

use TMSWP\AcfBridge\Exception\AcfBridgeRuntimeException;
use TMSWP\AcfBridge\Exception\AcfNotActiveException;
use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Exception\RuntimeException;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Meta\MetaStorageInterface;
use TMSWP\Core\Meta\ObjectType;
use TMSWP\Core\Meta\WpDatabaseGuard;
use TMSWP\Core\Meta\WpMetaStorage;
use WP_Error;
use WP_Post;

/**
 * MetaBagの読み書きの保存先をACFのフィールドにするMetaStorageInterfaceの実装(docs/09 §8.1)。
 * `#[UseMetaStorage(AcfMetaStorage::class)]`のエンティティが使う。
 *
 * 読み書きはすべてWpMetaStorageと同じ意味で、メタの行を直接読み書きする。has()はメタの行の有無で判定し、ACFの
 * フィールドのdefault_valueや、register_*_metaの既定値を「ある」としない。書き込みの戻り値・データベースのエラー・
 * slash・nullの扱いもWpMetaStorageの規約(docs/04 §4.5)に従う。ACFの書き込みの関数(update_field()等)は呼ばず、
 * ACFのフィルタ(acf/update_value・acf/pre_update_metadata・acf/pre_delete_metadata等)とアクションは通らない。
 *
 * ACFのフィールドのキー(ACFが見つけるフィールドの名前と同じキー)は、ACFが値をフィールドとして読めるように、
 * update_field()と同じく参照のメタ(`_{キー}`)にフィールドのkeyを書き、値を消したときは参照のメタも消す。
 * ACFのフィルタを通さないため、扱えるのは、保存した形が値そのもので、ACFのupdate_valueが値を変えない型
 * (SUPPORTED_FIELD_TYPES)だけである。ほかの型のフィールドのキーのset()・delete()は、設定の誤りとして
 * AcfBridgeRuntimeExceptionにする。読み取りでは型を検査しない。
 *
 * どの書き込みの後も(例外になった場合も)、ACFの値のキャッシュ(get_field()が使う)からそのキーを消す。
 *
 * 全メソッドは、対象のIDが1未満ならInvalidArgumentExceptionを(ACFはpost_idの0を現在のループの投稿に置き換え、
 * WordPressのメタの関数は負のIDをabsint()で別のIDにするため)、ACFが有効でなければAcfNotActiveExceptionを投げる
 * (docs/09 §8.1・§10)。
 */
final class AcfMetaStorage implements MetaStorageInterface
{
    /** ACFが参照のメタ(フィールドのkey)を保存するメタのキーの接頭辞(投稿・ターム・ユーザー) */
    private const REFERENCE_PREFIX = '_';

    /**
     * set()・delete()で扱えるACFのフィールドの型。ACF 6.2.0と6.8.10で、型のupdate_valueが無いか、次の条件で値を
     * 変えないもの(docs/09 §8.1の表)。
     * - number・range: update_valueは文字列の','を取り除く。','を含む文字列はInvalidArgumentExceptionにする。
     * - select: update_valueは空でない配列の各要素をstrvalする。multipleのフィールドは扱わず、配列の値は
     *   InvalidArgumentExceptionにする。
     * - radio: update_valueは、save_other_choiceのときに値を整えて選択肢に足す。save_other_choiceのフィールドは扱わない。
     * - image・file: update_valueは、値をacf_idval()で添付ファイルのIDにし、acf_connect_attachment_to_post()で添付ファイルを
     *   投稿に結び付ける(post_parentが0のとき)。単一のIDのint(正の整数)だけを受け付け、投稿では同じ処理で結び付ける
     *   (connectAttachmentToPost())。
     */
    public const array SUPPORTED_FIELD_TYPES = [
        'text',
        'textarea',
        'number',
        'range',
        'email',
        'url',
        'password',
        'wysiwyg',
        'oembed',
        'true_false',
        'select',
        'radio',
        'button_group',
        'date_picker',
        'time_picker',
        'date_time_picker',
        'color_picker',
        'image',
        'file',
    ];

    private readonly WpMetaStorage $meta;

    /**
     * @param Kernel $kernel 単独の読み取りのデータベースのエラーを扱うdebugとロガーのKernel(WpMetaStorageと同じ)。Containerが
     *                       解決したインスタンスは、そのContainerのKernelを受け取る
     */
    public function __construct(Kernel $kernel)
    {
        $this->meta = new WpMetaStorage($kernel);
    }

    public function has(ObjectType $type, int $objectId, string $key): bool
    {
        $this->assertCallable(__METHOD__, $type, $objectId);

        return $this->meta->has($type, $objectId, $key);
    }

    /** 保存された値(メタの先頭の行)を返す。行が無ければnull(ACFのフィールドのdefault_valueは返さない)。 */
    public function get(ObjectType $type, int $objectId, string $key): mixed
    {
        $this->assertCallable(__METHOD__, $type, $objectId);

        return $this->meta->get($type, $objectId, $key);
    }

    public function getAll(ObjectType $type, int $objectId, string $key): array
    {
        $this->assertCallable(__METHOD__, $type, $objectId);

        return $this->meta->getAll($type, $objectId, $key);
    }

    public function getMultiple(ObjectType $type, int $objectId, array $keys): array
    {
        $this->assertCallable(__METHOD__, $type, $objectId);

        return $this->meta->getMultiple($type, $objectId, $keys);
    }

    /**
     * 値をWpMetaStorageで保存する。ACFのフィールドのキーは、続けて参照のメタ(`_{キー}`)にフィールドのkeyを書く。
     *
     * @throws InvalidArgumentException nullの場合、objectを含む場合、number・rangeのフィールドに','を含む文字列の場合、
     *                                  image・fileのフィールドに正の整数(int)でない値の場合、multipleでないselectのフィールドに
     *                                  配列の場合
     * @throws AcfBridgeRuntimeException ACFのフィールドの型が、扱える型(SUPPORTED_FIELD_TYPES)でない場合
     * @throws RuntimeException データベースのエラー(フィールドを選ぶ参照のメタの読み取りと、添付ファイルの結び付けを含む)
     */
    public function set(ObjectType $type, int $objectId, string $key, mixed $value): bool
    {
        $this->assertCallable(__METHOD__, $type, $objectId);

        if ($value === null) {
            throw new InvalidArgumentException("meta '{$key}' cannot store null; use delete() to remove it");
        }

        $acfId = $this->acfId($type, $objectId);
        $field = $this->supportedAcfField($type, $objectId, $key);

        if ($field !== null && \in_array($field['type'], ['number', 'range'], true) && \is_string($value) && str_contains($value, ',')) {
            throw new InvalidArgumentException("meta '{$key}' is an ACF {$field['type']} field and cannot store a string with ','; ACF removes ',' from {$field['type']} values, so pass the number without separators");
        }

        if ($field !== null && $field['type'] === 'select' && \is_array($value)) {
            throw new InvalidArgumentException("meta '{$key}' is an ACF select field without multiple and stores a single choice; pass a string or a number, not an array (ACF converts the elements of an array with strval())");
        }

        $attachment = $field !== null && \in_array($field['type'], ['image', 'file'], true);

        if ($attachment && (!\is_int($value) || $value < 1)) {
            throw new InvalidArgumentException("meta '{$key}' is an ACF {$field['type']} field and stores a single attachment ID; pass a positive int, got " . (\is_int($value) ? (string) $value : get_debug_type($value)));
        }

        return $this->flushingAcfValue($acfId, $key, function () use ($type, $objectId, $key, $value, $field, $attachment): bool {
            $written = $this->meta->set($type, $objectId, $key, $value);

            if ($field !== null) {
                // update_field()と同じく、ACFが名前からフィールドを見つけられるように参照のメタを書く
                $this->meta->set($type, $objectId, self::REFERENCE_PREFIX . $key, $field['key']);
            }

            // ACFのimage・fileのupdate_valueと同じく、値が変わらないときも、投稿に添付ファイルを結び付ける。ACFは、ターム・
            // ユーザーのpost_id(term_{ID}等)では結び付けない
            if ($attachment && $type === ObjectType::Post) {
                self::connectAttachmentToPost($value, $objectId);
            }

            return $written;
        });
    }

    /**
     * ACFのフィールドでないキーは、WpMetaStorageと同じく、行を削除して書き足す。ACFのフィールドは値を1つだけ持つため、
     * ACFのフィールドのキーには使えない(型にかかわらず設定の誤り)。
     *
     * @throws AcfBridgeRuntimeException キーがACFのフィールドの場合
     * @throws RuntimeException フィールドを選ぶ参照のメタの読み取り、または書き込みでのデータベースのエラー
     */
    public function setAll(ObjectType $type, int $objectId, string $key, array $values): bool
    {
        $this->assertCallable(__METHOD__, $type, $objectId);
        $acfId = $this->acfId($type, $objectId);
        $field = $this->acfField($type, $objectId, $key);

        if ($field !== null) {
            throw new AcfBridgeRuntimeException("AcfMetaStorage cannot setAll() meta '{$key}': it is the ACF field {$field['key']}, which holds a single value, so it cannot store multiple rows. Use set() instead; if the entity declares the key with #[MetaSchema(single: false)], declare it as a single value");
        }

        return $this->flushingAcfValue($acfId, $key, fn(): bool => $this->meta->setAll($type, $objectId, $key, $values));
    }

    /**
     * WpMetaStorageと同じく、行を削除したらtrue、行が無ければ何もせずfalseを返す。ACFのフィールドのキーは、値の行を
     * 削除したときだけ参照のメタも消す。
     *
     * @throws AcfBridgeRuntimeException ACFのフィールドの型が、扱える型(SUPPORTED_FIELD_TYPES)でない場合
     * @throws RuntimeException データベースのエラー(フィールドを選ぶ参照のメタの読み取りを含む)
     */
    public function delete(ObjectType $type, int $objectId, string $key): bool
    {
        $this->assertCallable(__METHOD__, $type, $objectId);
        $acfId = $this->acfId($type, $objectId);
        $field = $this->supportedAcfField($type, $objectId, $key);

        return $this->flushingAcfValue($acfId, $key, function () use ($type, $objectId, $key, $field): bool {
            $deleted = $this->meta->delete($type, $objectId, $key);

            if ($deleted && $field !== null) {
                $this->meta->delete($type, $objectId, self::REFERENCE_PREFIX . $key);
            }

            return $deleted;
        });
    }

    private function acfId(ObjectType $type, int $objectId): int|string
    {
        return match ($type) {
            ObjectType::Post => $objectId,
            ObjectType::Term => "term_{$objectId}",
            ObjectType::User => "user_{$objectId}",
        };
    }

    /**
     * キーをメタの名前とする、呼び出しの対象のACFのフィールドを返す。ACFでないキーはnull。ACFのpost_idの解決
     * (acf/validate_post_id)は通さない。
     *
     * 対象の参照のメタ(`_{キー}`。readReference())がfield keyならそのフィールドを、参照が無いか解決しなければ名前で探したフィールドを使う
     * (acf_maybe_get_field()の参照と名前による探索と同じ順)。どちらも、名前がキーと同じものだけをACFのフィールドとする。
     * field keyの形(field_...)のキーは、ACFが別の名前のフィールドに解決するため、ACFのフィールドとせず、そのキーの
     * メタとして扱う。
     *
     * @return array{key: string, ...}|null
     */
    private function acfField(ObjectType $type, int $objectId, string $key): ?array
    {
        $reference = self::readReference($type, $objectId, $key);
        $field = \is_string($reference) && acf_is_field_key($reference) ? self::namedField(acf_get_field($reference), $key) : null;

        return $field ?? self::namedField(acf_get_field($key), $key);
    }

    /**
     * 書き込み(set・delete・setAll)の前に、フィールドを選ぶための参照のメタ(`_{キー}`)の先頭の行を読む。行が無ければnull。
     * WpMetaStorageの書き込みの中の読み取りと同じく、データベースのエラーはdebugにかかわらず例外にし、失敗した読み込みで
     * WPが書いたメタのキャッシュを消す(公開のget()のように、本番で記録して「行が無い」と同じ結果にはしない。参照が
     * 無いときの名前による探索へ進み、別のフィールドで書き込むのを防ぐため)。
     *
     * @throws RuntimeException 読み取りでデータベースのエラーが起きた場合
     */
    private static function readReference(ObjectType $type, int $objectId, string $key): mixed
    {
        $metaType = $type->metaType();
        $referenceKey = self::REFERENCE_PREFIX . $key;

        return WpDatabaseGuard::forgettingFailedMetaLoads($type, static fn(callable $forgetCache): mixed => WpDatabaseGuard::read(
            static fn(): mixed => get_metadata_raw($metaType, $objectId, $referenceKey, true),
            "failed to read {$metaType} meta '{$referenceKey}' for object {$objectId} before writing '{$key}'",
            $forgetCache,
        ));
    }

    /**
     * acf_get_field()の結果が、名前が$keyのフィールドならそれを返す。見つからない(false)・名前が違うならnull。
     *
     * @return array{key: string, ...}|null
     */
    private static function namedField(mixed $field, string $key): ?array
    {
        // 見つかったフィールドはkey(field_...)とnameとtypeを持つ
        if (!\is_array($field) || !\is_string($field['key'] ?? null) || ($field['name'] ?? null) !== $key) {
            return null;
        }

        return $field;
    }

    /**
     * ACF(6.2.0・6.8.10)のacf_connect_attachment_to_post()と同じく、acf/connect_attachment_to_postのフィルタが中止しなければ、
     * post_parentが0の添付ファイルを投稿に結び付ける。フィルタによる中止は正常な結果である。ACFの関数は
     * wp_update_post()の失敗を知らせないため、同じ処理をここで行い、データベースのエラーを例外にする。
     *
     * @throws RuntimeException 添付ファイルの更新(wp_update_post())が失敗した場合
     */
    private static function connectAttachmentToPost(int $attachmentId, int $postId): void
    {
        if (!(bool) apply_filters('acf/connect_attachment_to_post', true, $attachmentId, $postId)) {
            return;
        }

        $attachment = get_post($attachmentId);

        if (!$attachment instanceof WP_Post || $attachment->post_type !== 'attachment' || $attachment->post_parent !== 0) {
            return;
        }

        $result = wp_update_post(['ID' => $attachmentId, 'post_parent' => $postId], true);

        if ($result instanceof WP_Error) {
            $detail = $result->get_error_data();

            throw new RuntimeException("failed to connect attachment {$attachmentId} to post {$postId}: " . $result->get_error_message() . (\is_string($detail) && $detail !== '' ? " ({$detail})" : ''));
        }
    }

    /**
     * set()・delete()で扱うACFのフィールド(acfField())のkeyと型を返す。ACFでないキーはnull。
     *
     * @throws AcfBridgeRuntimeException フィールドの型が、扱える型(SUPPORTED_FIELD_TYPES)でない場合
     *
     * @return array{key: string, type: string}|null
     */
    private function supportedAcfField(ObjectType $type, int $objectId, string $key): ?array
    {
        $field = $this->acfField($type, $objectId, $key);

        if ($field === null) {
            return null;
        }

        $fieldType = \is_string($field['type'] ?? null) ? $field['type'] : get_debug_type($field['type'] ?? null);
        $unsupported = match (true) {
            !\in_array($fieldType, self::SUPPORTED_FIELD_TYPES, true) => "its type '{$fieldType}' is not supported",
            $fieldType === 'select' && (bool) ($field['multiple'] ?? false) => 'it is a select field with multiple values',
            $fieldType === 'radio' && (bool) ($field['save_other_choice'] ?? false) => 'it is a radio field with save_other_choice',
            default => null,
        };

        if ($unsupported !== null) {
            throw new AcfBridgeRuntimeException("AcfMetaStorage cannot write meta '{$key}' of the ACF field {$field['key']} (type '{$fieldType}') because {$unsupported}. AcfMetaStorage writes the meta directly without ACF's update_value filters, so it supports only these field types: " . implode(', ', self::SUPPORTED_FIELD_TYPES) . ' (select without multiple, radio without save_other_choice). Use the generated accessor (XxxFields) for this field, or remove the key from the #[MetaSchema] of the entity');
        }

        return ['key' => $field['key'], 'type' => $fieldType];
    }

    /**
     * 書き込みの後に(例外になった場合も)、ACFの値のキャッシュからそのキーを消す(get_field()が古い値を返さないように)。
     *
     * @param callable(): bool $write
     */
    private function flushingAcfValue(int|string $acfId, string $key, callable $write): bool
    {
        try {
            return $write();
        } finally {
            acf_flush_value_cache($acfId, $key);
        }
    }

    /**
     * 対象のID(1以上。ObjectType::resolveId())と、ACFが有効であることを検査する。
     *
     * @throws InvalidArgumentException IDが1未満の場合
     * @throws AcfNotActiveException ACFが有効でない場合
     */
    private function assertCallable(string $callable, ObjectType $type, int $objectId): void
    {
        $type->resolveId($objectId);
        $this->assertAcfActive($callable);
    }

    private function assertAcfActive(string $callable): void
    {
        if (!\function_exists('get_field')) {
            throw AcfNotActiveException::forCall($callable);
        }
    }
}
