<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use TMSWP\AcfBridge\Exception\AcfBridgeRuntimeException;
use TMSWP\AcfBridge\Exception\AcfNotActiveException;
use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Kernel\Kernel;

/**
 * 生成クラス(`XxxFields`・`XxxOptions`)が使う、ACFの値の読み書きの基盤。
 *
 * 公開のエスケープハッチ(has()・value()・rawValue()・update()・delete())はフィールド名でACFを呼ぶ。生成コードが使う
 * 保護のヘルパは、フィールドのkey(生成クラスのKEY_定数)でACFを呼ぶ。keyのフィールドがACFに
 * 登録されていなければ、設定の誤りとしてdebugにかかわらずAcfBridgeRuntimeExceptionにする。
 *
 * 型強制のヘルパは、ACFが返した値を次の2つに分けて扱う。名前が`Of`で終わるヘルパは、値と、例外と
 * ログに出す位置(`stores[0].shop_name`の形)を受け取り、group・repeater・flexible_contentの入れ子のサブフィールドに使う。
 * - 未設定(null・false・''。配列の型は空の配列も): 型ごとの未設定の値('' ・[]・false・null)に正規化する。エラーにしない。
 * - 形の不一致(フィールドの型の想定と違う値): 実行時のデータ取得の失敗として扱う。
 *   生成クラスのKernel(Kernel::forClass())のdebugではAcfBridgeRuntimeExceptionを投げ、本番ではそのKernelのロガーに
 *   errorで記録して未設定の値を返す。listの型(repeater・flexible_contentの行を含む)は要素ごとに検査し、本番では
 *   不一致の要素だけを記録して除く。
 *
 * @phpstan-import-type AcfChoice from \TMSWP\AcfBridge\AcfTypes
 * @phpstan-import-type AcfColorRgba from \TMSWP\AcfBridge\AcfTypes
 * @phpstan-import-type AcfFile from \TMSWP\AcfBridge\AcfTypes
 * @phpstan-import-type AcfImage from \TMSWP\AcfBridge\AcfTypes
 * @phpstan-import-type AcfLink from \TMSWP\AcfBridge\AcfTypes
 * @phpstan-import-type AcfUserArray from \TMSWP\AcfBridge\AcfTypes
 */
trait AcfFieldAccess
{
    private function __construct(private readonly int|string $acfId) {}

    public function acfId(): int|string
    {
        return $this->acfId;
    }

    /**
     * 名前が$nameのフィールドの値が保存されているか。get_field()が読む保存先(acf_get_valid_post_id()で解決した
     * post_id)の、メタの行(optionsページはoption)の有無で判定する。フィールドのdefault_value、register_*_metaの
     * 既定値、groupが返す空の配列は「保存されている」としない。
     *
     * @throws AcfBridgeRuntimeException acf/validate_post_idのフィルタが、post_idをintでも文字列でもない値にした場合
     */
    public function has(string $name): bool
    {
        $this->assertAcfActive(__FUNCTION__);

        return $this->hasStoredValue($name);
    }

    public function value(string $name): mixed
    {
        $this->assertAcfActive(__FUNCTION__);

        return get_field($name, $this->acfId, true);
    }

    public function rawValue(string $name): mixed
    {
        $this->assertAcfActive(__FUNCTION__);

        return get_field($name, $this->acfId, false);
    }

    /**
     * update_field()で保存し、その戻り値をboolで返す。
     *
     * 値はwp_slash()してから渡す。ACFは、メタではupdate_metadata()が、optionsでは自身がwp_unslash()するため、
     * slashしないとバックスラッシュが消える(ACFが管理画面の保存で受け取る$_POSTの値も、slash済みである)。
     *
     * ACFの戻り値は、update_metadata()と同じく、同じ値で書き込まなかったとき・フィルタが中止したとき・データベースの
     * エラーのどれでもfalseになり、区別できない。書き込みの失敗を例外で知る必要がある値は、MetaBag
     * (`#[UseMetaStorage(AcfMetaStorage::class)]`)で読み書きする。
     */
    public function update(string $name, mixed $value): bool
    {
        $this->assertAcfActive(__FUNCTION__);

        return $this->updateField($name, $value);
    }

    public function delete(string $name): bool
    {
        $this->assertAcfActive(__FUNCTION__);

        return delete_field($name, $this->acfId);
    }

    /**
     * keyのフィールドの値が保存されているか(has()と同じ判定)。保存先のメタの名前は、ACFに登録されたフィールドの
     * 定義の名前(get_field()が読む名前)を使う。
     */
    protected function acfHas(string $key): bool
    {
        return $this->hasStoredValue($this->acfFieldName(__FUNCTION__, $key));
    }

    /** keyのフィールドの値を、delete_field()で消す。消したらtrue。 */
    protected function acfDelete(string $key): bool
    {
        $this->acfFieldName(__FUNCTION__, $key);

        return delete_field($key, $this->acfId);
    }

    /** keyのフィールドのフォーマット済みの値を、型を強制せずに返す(ACFの未知の型)。 */
    protected function acfMixed(string $key): mixed
    {
        $this->acfFieldName(__FUNCTION__, $key);

        return get_field($key, $this->acfId, true);
    }

    protected function acfString(string $key): string
    {
        return $this->acfStringOf(...$this->acfFormatted(__FUNCTION__, $key));
    }

    protected function acfNullableString(string $key): ?string
    {
        return $this->acfNullableStringOf(...$this->acfFormatted(__FUNCTION__, $key));
    }

    protected function acfNumber(string $key): int|float|null
    {
        return $this->acfNumberOf(...$this->acfFormatted(__FUNCTION__, $key));
    }

    protected function acfInt(string $key): ?int
    {
        return $this->acfIntOf(...$this->acfFormatted(__FUNCTION__, $key));
    }

    protected function acfBool(string $key): bool
    {
        return $this->acfBoolOf(...$this->acfFormatted(__FUNCTION__, $key));
    }

    /** @return list<int> */
    protected function acfIntList(string $key): array
    {
        return $this->acfIntListOf(...$this->acfFormatted(__FUNCTION__, $key));
    }

    /** @return list<string> */
    protected function acfStringList(string $key): array
    {
        return $this->acfStringListOf(...$this->acfFormatted(__FUNCTION__, $key));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    protected function acfObject(string $key, string $class): ?object
    {
        [$value, $path] = $this->acfFormatted(__FUNCTION__, $key);

        return $this->acfObjectOf($value, $path, $class);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    protected function acfObjectList(string $key, string $class): array
    {
        [$value, $path] = $this->acfFormatted(__FUNCTION__, $key);

        return $this->acfObjectListOf($value, $path, $class);
    }

    /** @return AcfImage|null */
    protected function acfImage(string $key): ?array
    {
        return $this->acfImageOf(...$this->acfFormatted(__FUNCTION__, $key));
    }

    /** @return AcfFile|null */
    protected function acfFile(string $key): ?array
    {
        return $this->acfFileOf(...$this->acfFormatted(__FUNCTION__, $key));
    }

    /** @return list<AcfImage> */
    protected function acfImageList(string $key): array
    {
        return $this->acfImageListOf(...$this->acfFormatted(__FUNCTION__, $key));
    }

    /** @return AcfLink|null */
    protected function acfLink(string $key): ?array
    {
        return $this->acfLinkOf(...$this->acfFormatted(__FUNCTION__, $key));
    }

    /** @return AcfChoice|null */
    protected function acfChoice(string $key): ?array
    {
        return $this->acfChoiceOf(...$this->acfFormatted(__FUNCTION__, $key));
    }

    /** @return list<AcfChoice> */
    protected function acfChoiceList(string $key): array
    {
        return $this->acfChoiceListOf(...$this->acfFormatted(__FUNCTION__, $key));
    }

    /** @return AcfUserArray|null */
    protected function acfUserArray(string $key): ?array
    {
        return $this->acfUserArrayOf(...$this->acfFormatted(__FUNCTION__, $key));
    }

    /** @return list<AcfUserArray> */
    protected function acfUserArrayList(string $key): array
    {
        return $this->acfUserArrayListOf(...$this->acfFormatted(__FUNCTION__, $key));
    }

    /** @return AcfColorRgba|null */
    protected function acfColorRgba(string $key): ?array
    {
        return $this->acfColorRgbaOf(...$this->acfFormatted(__FUNCTION__, $key));
    }

    /**
     * groupの値を$shapeで正規化する(acfGroupOf())。
     *
     * @template T of array<string, mixed>
     *
     * @param Closure(array<string, mixed>, string): T $shape
     *
     * @return T|null
     */
    protected function acfGroup(string $key, Closure $shape): ?array
    {
        [$value, $path] = $this->acfFormatted(__FUNCTION__, $key);

        return $this->acfGroupOf($value, $path, $shape);
    }

    /**
     * repeaterの行を$rowで正規化する(acfRowsOf())。
     *
     * @template T of array<string, mixed>
     *
     * @param Closure(array<string, mixed>, string): T $row
     *
     * @return list<T>
     */
    protected function acfRows(string $key, Closure $row): array
    {
        [$value, $path] = $this->acfFormatted(__FUNCTION__, $key);

        return $this->acfRowsOf($value, $path, $row);
    }

    /**
     * flexible_contentの行を、レイアウトごとの$layoutsで正規化する(acfLayoutsOf())。
     *
     * @template T of array<string, mixed>
     *
     * @param array<string, Closure(array<string, mixed>, string): T> $layouts
     *
     * @return list<T>
     */
    protected function acfLayouts(string $key, array $layouts): array
    {
        [$value, $path] = $this->acfFormatted(__FUNCTION__, $key);

        return $this->acfLayoutsOf($value, $path, $layouts);
    }

    /**
     * レイアウトの無いflexible_contentの行を検査する(acfEmptyLayoutsOf())。
     *
     * @return list<never>
     */
    protected function acfEmptyLayouts(string $key): array
    {
        return $this->acfEmptyLayoutsOf(...$this->acfFormatted(__FUNCTION__, $key));
    }

    /**
     * 保存された値(raw)を、$rawFormatの書式でwp_timezone()の日時にする。未設定はnull。書式に合わない値と、範囲外の値
     * (20241332等。createFromFormat()は繰り上げて受け付ける)は形の不一致として扱う。
     *
     * date_time_pickerの書式(Y-m-d H:i:s)で、現地の時刻が夏時間の終わり等で2回来る(2つの瞬間を表す)場合は、早い方の
     * 瞬間を返す(acfUpdateDate()が保存を許すのは早い方だけのため、保存した瞬間として往復する)。
     */
    protected function acfDateTime(string $key, string $rawFormat): ?DateTimeImmutable
    {
        $path = $this->acfFieldName(__FUNCTION__, $key);

        return $this->dateTimeOf(get_field($key, $this->acfId, false), $path, $rawFormat);
    }

    /**
     * 保存された値(raw)$valueを、acfDateTime()と同じ規則で$rawFormatの書式のwp_timezone()の日時にする。
     */
    private function dateTimeOf(mixed $value, string $path, string $rawFormat): ?DateTimeImmutable
    {
        if (self::isUnset($value)) {
            return null;
        }

        $expected = "date string in the format '{$rawFormat}'";

        if (!\is_string($value)) {
            return $this->acfMismatch($path, $expected, get_debug_type($value), null);
        }

        // createFromFormat()は、NULを含む文字列をValueErrorにするため、解析の前に形の不一致として扱う
        if (str_contains($value, "\0")) {
            return $this->acfMismatch($path, $expected, 'string that contains NUL', null);
        }

        $timezone = wp_timezone();
        $date = DateTimeImmutable::createFromFormat('!' . $rawFormat, $value, $timezone);

        if (!$date instanceof DateTimeImmutable || $date->format($rawFormat) !== $value) {
            return $this->acfMismatch($path, $expected, 'string that does not match the format', null);
        }

        if ($rawFormat !== AcfLocalTime::DATE_TIME_RAW_FORMAT) {
            return $date;
        }

        $instants = AcfLocalTime::instants($value, $timezone);

        return \count($instants) > 1 ? (new DateTimeImmutable('@' . $instants[0]))->setTimezone($timezone) : $date;
    }

    /**
     * keyのフィールドへupdate()と同じ規則(wp_slash())で保存し、戻り値を捨てる。update()のfalseは「書き込まなかった」と
     * 「失敗した」を区別できないため(update()を参照)、生成クラスのsetterは結果を返さない。
     */
    protected function acfUpdate(string $key, mixed $value): void
    {
        $this->acfFieldName(__FUNCTION__, $key);
        $this->updateField($key, $value);
    }

    /**
     * DateTimeInterfaceは、wp_timezone()の日時に変換してから$rawFormatの書式の文字列にして保存する(acfDateTime()と
     * 往復する)。文字列とnullはそのまま渡す。
     *
     * ACFの保存形式は時差を持たない現地の時刻のため、夏時間の終わり等で同じ現地の時刻が2回来る間は、2つの瞬間を区別
     * できない。date_time_pickerの書式(Y-m-d H:i:s)では、現地の時刻が2つの瞬間を表すとき(AcfLocalTime::instants())、
     * 早い方の瞬間だけを保存し、遅い方の瞬間は黙って早い方を保存せずに例外にする。日付だけ(Ymd)・時刻だけ(H:i:s)の
     * 書式は瞬間を保存しないため、判定しない。
     *
     * @throws InvalidArgumentException date_time_pickerの書式で、入力が同じ現地の時刻を表す2つの瞬間の遅い方の場合
     */
    protected function acfUpdateDate(string $key, DateTimeInterface|string|null $value, string $rawFormat): void
    {
        $name = $this->acfFieldName(__FUNCTION__, $key);
        $this->updateField($key, $this->rawDate($value, $name, $rawFormat));
    }

    /**
     * acfUpdateDate()が保存する値。DateTimeInterfaceは$rawFormatの書式の現地の時刻の文字列にし、文字列とnullはそのまま
     * 返す。
     *
     * @throws InvalidArgumentException date_time_pickerの書式で、入力が同じ現地の時刻を表す2つの瞬間の遅い方の場合
     */
    private function rawDate(DateTimeInterface|string|null $value, string $name, string $rawFormat): ?string
    {
        if (!$value instanceof DateTimeInterface) {
            return $value;
        }

        $timezone = wp_timezone();
        $local = DateTimeImmutable::createFromInterface($value)->setTimezone($timezone);
        $raw = $local->format($rawFormat);

        if ($rawFormat === AcfLocalTime::DATE_TIME_RAW_FORMAT) {
            $instants = AcfLocalTime::instants($raw, $timezone);

            if (\count($instants) > 1 && $local->getTimestamp() !== $instants[0]) {
                $instant = $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

                throw new InvalidArgumentException("ACF field '{$name}' cannot store {$instant} UTC: the local time {$raw} in {$timezone->getName()} is ambiguous because it occurs twice (for example when daylight saving time ends), and ACF stores the local time without the UTC offset. Only the earlier of the two instants can be stored; pass it or a time outside the repeated period");
            }
        }

        return $raw;
    }

    /**
     * cloneが`prefix_name`で保存名を変えたフィールドの、フォーマット済みの値。ACFに登録された元の
     * keyの定義を得て、`name`を保存名$nameに差し替えてから読む(ACFのacf_get_meta_field()と同じ形)。元のkeyのまま
     * get_field()で読むと定義の名前(接頭辞の無い名前)のメタを読み、cloneが合成するkeyはACFに登録されないため、
     * どちらのkeyでも保存名の値を読めないためである。型の検査は、受け取った値に*Of()のヘルパを使う。
     *
     * @throws AcfNotActiveException ACFが有効でない場合
     * @throws AcfBridgeRuntimeException keyのフィールドがACFに登録されていない場合
     */
    protected function acfClonedValue(string $key, string $name): mixed
    {
        $field = $this->acfClonedField(__FUNCTION__, $key, $name);
        $postId = $this->validPostId();

        return acf_format_value(acf_get_value($postId, $field), $postId, $field);
    }

    /**
     * acfClonedValue()のフィールドの保存された値(raw)を、acfDateTime()と同じ規則で日時にする。
     *
     * @throws AcfNotActiveException ACFが有効でない場合
     * @throws AcfBridgeRuntimeException keyのフィールドがACFに登録されていない場合
     */
    protected function acfClonedDateTime(string $key, string $name, string $rawFormat): ?DateTimeImmutable
    {
        $field = $this->acfClonedField(__FUNCTION__, $key, $name);

        return $this->dateTimeOf(acf_get_value($this->validPostId(), $field), $name, $rawFormat);
    }

    /**
     * acfClonedValue()のフィールドへ、acfUpdate()と同じ規則(wp_slash())で保存名に保存する。
     *
     * @throws AcfNotActiveException ACFが有効でない場合
     * @throws AcfBridgeRuntimeException keyのフィールドがACFに登録されていない場合
     */
    protected function acfClonedUpdate(string $key, string $name, mixed $value): void
    {
        $this->updateClonedField($this->acfClonedField(__FUNCTION__, $key, $name), $value);
    }

    /**
     * acfClonedValue()のフィールドへ、acfUpdateDate()と同じ規則で日時を保存名に保存する。
     *
     * @throws AcfNotActiveException ACFが有効でない場合
     * @throws AcfBridgeRuntimeException keyのフィールドがACFに登録されていない場合
     * @throws InvalidArgumentException date_time_pickerの書式で、入力が同じ現地の時刻を表す2つの瞬間の遅い方の場合
     */
    protected function acfClonedUpdateDate(string $key, string $name, DateTimeInterface|string|null $value, string $rawFormat): void
    {
        $field = $this->acfClonedField(__FUNCTION__, $key, $name);
        $this->updateClonedField($field, $this->rawDate($value, $name, $rawFormat));
    }

    /**
     * acfClonedValue()のフィールドの値が、保存名$nameで保存されているか(has()と同じ判定)。
     *
     * @throws AcfNotActiveException ACFが有効でない場合
     * @throws AcfBridgeRuntimeException keyのフィールドがACFに登録されていない場合
     */
    protected function acfClonedHas(string $key, string $name): bool
    {
        $this->acfClonedField(__FUNCTION__, $key, $name);

        return $this->hasStoredValue($name);
    }

    /**
     * acfClonedValue()のフィールドの、保存名$nameの値を消す(acf_delete_value())。消したらtrue。
     *
     * @throws AcfNotActiveException ACFが有効でない場合
     * @throws AcfBridgeRuntimeException keyのフィールドがACFに登録されていない場合
     */
    protected function acfClonedDelete(string $key, string $name): bool
    {
        $field = $this->acfClonedField(__FUNCTION__, $key, $name);

        return acf_delete_value($this->validPostId(), $field);
    }

    /** 文字列・数値は文字列にする。未設定は''。 */
    protected function acfStringOf(mixed $value, string $path): string
    {
        if (self::isUnset($value)) {
            return '';
        }

        if (\is_string($value) || \is_int($value) || \is_float($value)) {
            return (string) $value;
        }

        return $this->acfMismatch($path, 'string', get_debug_type($value), '');
    }

    /** acfStringOf()の''をnullにする。 */
    protected function acfNullableStringOf(mixed $value, string $path): ?string
    {
        $string = $this->acfStringOf($value, $path);

        return $string === '' ? null : $string;
    }

    /** 整数の文字列はint、小数・指数の文字列はfloatにする。未設定はnull。 */
    protected function acfNumberOf(mixed $value, string $path): int|float|null
    {
        if (self::isUnset($value)) {
            return null;
        }

        if (\is_int($value) || \is_float($value)) {
            return $value;
        }

        if (\is_string($value) && is_numeric($value)) {
            return $value + 0;
        }

        return $this->acfMismatch($path, 'number', get_debug_type($value), null);
    }

    /** acfNumberOf()のうち整数だけを受け付ける。小数は形の不一致。 */
    protected function acfIntOf(mixed $value, string $path): ?int
    {
        $number = $this->acfNumberOf($value, $path);

        if ($number === null || \is_int($number)) {
            return $number;
        }

        return $this->acfMismatch($path, 'int', 'float', null);
    }

    /** スカラーとnullはboolにする。配列・objectは形の不一致。 */
    protected function acfBoolOf(mixed $value, string $path): bool
    {
        if (\is_scalar($value) || $value === null) {
            return (bool) $value;
        }

        return $this->acfMismatch($path, 'bool', get_debug_type($value), false);
    }

    /** @return list<int> */
    protected function acfIntListOf(mixed $value, string $path): array
    {
        return $this->acfListItemsOf($value, $path, 'int', static function (mixed $item): ?int {
            if (\is_int($item)) {
                return $item;
            }

            return \is_string($item) && ctype_digit($item) ? (int) $item : null;
        });
    }

    /** @return list<string> */
    protected function acfStringListOf(mixed $value, string $path): array
    {
        return $this->acfListItemsOf($value, $path, 'string', static fn(mixed $item): ?string => \is_string($item) || \is_int($item) || \is_float($item) ? (string) $item : null);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    protected function acfObjectOf(mixed $value, string $path, string $class): ?object
    {
        if (self::isUnset($value)) {
            return null;
        }

        return $value instanceof $class ? $value : $this->acfMismatch($path, $class, get_debug_type($value), null);
    }

    /**
     * objectのlist(relationship・post_object・taxonomy・userの複数・return_format=object)。要素ごとに$classのinstanceofを
     * 検査し、合わない要素は形の不一致として扱う(本番では記録して除く)。
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    protected function acfObjectListOf(mixed $value, string $path, string $class): array
    {
        return $this->acfListItemsOf($value, $path, $class, static fn(mixed $item): ?object => $item instanceof $class ? $item : null);
    }

    /** @return AcfImage|null */
    protected function acfImageOf(mixed $value, string $path): ?array
    {
        return $this->acfShapeOf($value, $path, 'AcfImage', $this->acfImageFromArray(...));
    }

    /** @return AcfFile|null */
    protected function acfFileOf(mixed $value, string $path): ?array
    {
        return $this->acfShapeOf($value, $path, 'AcfFile', $this->acfFileFromArray(...));
    }

    /** @return list<AcfImage> */
    protected function acfImageListOf(mixed $value, string $path): array
    {
        return $this->acfListItemsOf($value, $path, 'AcfImage', $this->arrayItem($this->acfImageFromArray(...)));
    }

    /** @return AcfLink|null */
    protected function acfLinkOf(mixed $value, string $path): ?array
    {
        return $this->acfShapeOf($value, $path, 'AcfLink', $this->acfLinkFromArray(...));
    }

    /** @return AcfChoice|null */
    protected function acfChoiceOf(mixed $value, string $path): ?array
    {
        return $this->acfShapeOf($value, $path, 'AcfChoice', $this->acfChoiceFromArray(...));
    }

    /** @return list<AcfChoice> */
    protected function acfChoiceListOf(mixed $value, string $path): array
    {
        return $this->acfListItemsOf($value, $path, 'AcfChoice', $this->arrayItem($this->acfChoiceFromArray(...)));
    }

    /** @return AcfUserArray|null */
    protected function acfUserArrayOf(mixed $value, string $path): ?array
    {
        return $this->acfShapeOf($value, $path, 'AcfUserArray', $this->acfUserArrayFromArray(...));
    }

    /** @return list<AcfUserArray> */
    protected function acfUserArrayListOf(mixed $value, string $path): array
    {
        return $this->acfListItemsOf($value, $path, 'AcfUserArray', $this->arrayItem($this->acfUserArrayFromArray(...)));
    }

    /**
     * color_picker(return_formatがarray)の値。ACFは値が無いとき(と色として読めない文字列のとき)もrgba(0,0,0,0)の配列を
     * 返すため、その配列は未設定としてnullにする(完全に透明な黒を保存した値もnullになる)。
     *
     * @return AcfColorRgba|null
     */
    protected function acfColorRgbaOf(mixed $value, string $path): ?array
    {
        $color = $this->acfShapeOf($value, $path, 'AcfColorRgba', $this->acfColorRgbaFromArray(...));

        return $color === ['red' => 0, 'green' => 0, 'blue' => 0, 'alpha' => 0.0] ? null : $color;
    }

    /**
     * groupの値(サブフィールドの名前 => フォーマット済みの値)を、$shapeでサブフィールドごとに正規化する。未設定(空の
     * 配列を含む)はnull、文字列のキーの配列でない値は形の不一致。$shapeは、サブフィールドの値と位置(`{$path}.{名前}`)を
     * 型ごとの`*Of()`のヘルパに渡す(ジェネレータが生成する)。
     *
     * @template T of array<string, mixed>
     *
     * @param Closure(array<string, mixed>, string): T $shape
     *
     * @return T|null
     */
    protected function acfGroupOf(mixed $value, string $path, Closure $shape): ?array
    {
        $group = $this->acfAssocOf($value, $path, 'array with string keys');

        return $group === null ? null : $shape($group, $path);
    }

    /**
     * repeaterの行のlistを、行ごとに$rowで正規化する。未設定は[]。listでない値は形の不一致。文字列のキーの配列でない
     * 行は形の不一致として扱い、本番では記録して除く。$rowが受け取る位置は`{$path}[{添字}]`。
     *
     * @template T of array<string, mixed>
     *
     * @param Closure(array<string, mixed>, string): T $row
     *
     * @return list<T>
     */
    protected function acfRowsOf(mixed $value, string $path, Closure $row): array
    {
        $rows = [];

        foreach ($this->acfListOf($value, $path) as $index => $element) {
            $rowPath = "{$path}[{$index}]";
            $assoc = \is_array($element) ? self::stringKeyedArray($element) : null;

            if ($assoc === null) {
                $this->acfMismatch($rowPath, 'row (array with string keys)', \is_array($element) ? 'list' : get_debug_type($element), null);
                continue;
            }

            $rows[] = $row($assoc, $rowPath);
        }

        return $rows;
    }

    /**
     * flexible_contentの行のlistを、行のacf_fc_layout(レイアウトの名前)に対応する$layoutsの関数で正規化する。
     * acf_fc_layoutが無い・$layoutsに無いレイアウトの行は形の不一致として扱い、本番では記録して除く。ほかの扱いは
     * acfRowsOf()と同じ。
     *
     * @template T of array<string, mixed>
     *
     * @param array<string, Closure(array<string, mixed>, string): T> $layouts
     *
     * @return list<T>
     */
    protected function acfLayoutsOf(mixed $value, string $path, array $layouts): array
    {
        $rows = [];
        $expected = 'layout name (' . implode(', ', array_map(static fn(string $name): string => "'{$name}'", array_keys($layouts))) . ')';

        foreach ($this->acfRowsOf($value, $path, static fn(array $row, string $rowPath): array => ['row' => $row, 'path' => $rowPath]) as $entry) {
            $layout = $entry['row']['acf_fc_layout'] ?? null;
            $normalize = \is_string($layout) ? ($layouts[$layout] ?? null) : null;

            if ($normalize === null) {
                $this->acfMismatch($entry['path'] . '.acf_fc_layout', $expected, \is_string($layout) ? 'string that is not a layout of the field' : get_debug_type($layout), null);
                continue;
            }

            $rows[] = $normalize($entry['row'], $entry['path']);
        }

        return $rows;
    }

    /**
     * レイアウトの無いflexible_contentは、正規化できる行が無い。未設定は[]、listでない値は形の不一致。行はどれも、
     * レイアウトに無い行として形の不一致にし、本番では記録して除く(acfLayoutsOf()と同じ扱い)。
     *
     * @return list<never>
     */
    protected function acfEmptyLayoutsOf(mixed $value, string $path): array
    {
        foreach ($this->acfListOf($value, $path) as $index => $element) {
            $this->acfMismatch("{$path}[{$index}]", 'no row (the flexible content field has no layouts)', get_debug_type($element), null);
        }

        return [];
    }

    /**
     * ACFが有効でなければ、呼び出したクラス(生成クラス)とメソッドの名前でAcfNotActiveExceptionを投げる。traitの中の
     * __METHOD__はtraitの名前になるため、呼び出し側は__FUNCTION__を渡し、ここでstatic::classと組み合わせる。
     */
    private function assertAcfActive(string $method): void
    {
        if (!\function_exists('get_field')) {
            throw AcfNotActiveException::forCall(static::class . '::' . $method);
        }
    }

    /**
     * keyのフィールドの、ACFに登録された定義の名前を返す。生成アクセサは、この名前を例外とログの位置と、has()の
     * 保存先のメタの名前に使う。
     *
     * keyのフィールドが見つからないと、ACFのget_field()・update_field()は、keyを名前とするダミーのフィールドで黙って
     * 別のメタを読み書きする。そのため、登録されていないことを設定の誤り(acf-jsonと生成クラスの食い違い等)として、
     * debugにかかわらず例外にする。
     *
     * @throws AcfNotActiveException ACFが有効でない場合
     * @throws AcfBridgeRuntimeException keyのフィールドがACFに登録されていない場合
     */
    private function acfFieldName(string $method, string $key): string
    {
        $this->assertAcfActive($method);
        $field = acf_is_field_key($key) ? acf_get_field($key) : false;
        $name = \is_array($field) && ($field['key'] ?? null) === $key ? ($field['name'] ?? null) : null;

        if (!\is_string($name) || $name === '') {
            throw new AcfBridgeRuntimeException("ACF field key '{$key}' used by " . static::class . "::{$method}() is not registered in ACF. Generated accessors read and write fields by their key; make sure ACF loads the acf-json that defines the field, or run wp tmswp acf sync if the field was removed");
        }

        return $name;
    }

    /**
     * keyのフィールドのフォーマット済みの値と、例外とログに出す位置(フィールドの名前)を返す。
     *
     * @return array{mixed, string}
     */
    private function acfFormatted(string $method, string $key): array
    {
        $name = $this->acfFieldName($method, $key);

        return [get_field($key, $this->acfId, true), $name];
    }

    /**
     * keyのフィールドの、ACFに登録された定義の`name`を保存名$nameに差し替えた定義(acfClonedValue()等が使う)。
     *
     * @throws AcfNotActiveException ACFが有効でない場合
     * @throws AcfBridgeRuntimeException keyのフィールドがACFに登録されていない場合
     *
     * @return array<array-key, mixed>
     */
    private function acfClonedField(string $method, string $key, string $name): array
    {
        $this->acfFieldName($method, $key);
        $field = acf_get_field($key);

        if (!\is_array($field)) {
            throw new AcfBridgeRuntimeException("ACF field key '{$key}' used by " . static::class . "::{$method}() is not registered in ACF");
        }

        $field['name'] = $name;

        return $field;
    }

    /**
     * 保存名に差し替えた定義$fieldへ、updateField()と同じくwp_slash()した値をacf_update_value()で保存する(update_field()が
     * 定義を得た後にすることと同じ)。
     *
     * @param array<array-key, mixed> $field
     */
    private function updateClonedField(array $field, mixed $value): void
    {
        acf_update_value(\is_string($value) || \is_array($value) ? wp_slash($value) : $value, $this->validPostId(), $field);
    }

    /** $selector(名前またはkey)のフィールドへ、wp_slash()した値をupdate_field()で保存する。 */
    private function updateField(string $selector, mixed $value): bool
    {
        // 文字列と配列(の中の文字列)だけをslashする。ほかの型はwp_slash()の対象外
        return update_field($selector, \is_string($value) || \is_array($value) ? wp_slash($value) : $value, $this->acfId) !== false;
    }

    /**
     * 名前が$nameのメタの行(optionsページはoption)が、get_field()が読む保存先にあるか。
     *
     * @throws AcfBridgeRuntimeException acf/validate_post_idのフィルタが、post_idをintでも文字列でもない値にした場合
     */
    private function hasStoredValue(string $name): bool
    {
        $target = acf_decode_post_id($this->validPostId());
        $type = $target['type'] ?? null;
        $id = $target['id'] ?? null;

        if ($type === 'option') {
            // ACFはoptionsの値を`{post_id}_{フィールド名}`のoptionに保存し、nullを未保存として読む
            return \is_string($id) && get_option("{$id}_{$name}", null) !== null;
        }

        if (!\is_string($type) || !\is_int($id) || $id < 1) {
            return false;
        }

        $rows = get_metadata_raw($type, $id, $name, false);

        // WpMetaStorage::has()と同じく、null・false・空の配列とスカラーの空文字は行なしとする
        return $rows !== null && $rows !== false && $rows !== '' && $rows !== [];
    }

    /**
     * get_field()と同じく、acf_get_valid_post_id()でACFのpost_idに解決する。
     *
     * @throws AcfBridgeRuntimeException acf/validate_post_idのフィルタが、post_idをintでも文字列でもない値にした場合
     */
    private function validPostId(): int|string
    {
        $postId = acf_get_valid_post_id($this->acfId);

        if (!\is_int($postId) && !\is_string($postId)) {
            throw new AcfBridgeRuntimeException('ACF resolved the post_id ' . $this->acfId . ' to ' . get_debug_type($postId) . ' (acf/validate_post_id); it must be an int or a string');
        }

        return $postId;
    }

    /** ACFが未設定に返す値(null・false・'')か。 */
    private static function isUnset(mixed $value): bool
    {
        return $value === null || $value === false || $value === '';
    }

    /**
     * 形の不一致を、実行時のデータ取得の失敗として扱う。生成クラスのKernelのdebugでは
     * 例外を投げ、本番ではそのKernelのロガーにerrorで記録して$fallbackを返す。値そのものは記録しない。
     *
     * @template T
     *
     * @param string $path フィールドの位置(フィールド名。listの要素は`name[添字]`、サブフィールドは`name.sub`)
     * @param string $expected 想定した型
     * @param string $actual 実際の値の型(値そのものではない)
     * @param T $fallback 本番で返す未設定の値
     *
     * @throws AcfBridgeRuntimeException debugの場合
     *
     * @return T
     */
    private function acfMismatch(string $path, string $expected, string $actual, mixed $fallback): mixed
    {
        $kernel = Kernel::forClass(static::class);
        $acfId = (string) $this->acfId;

        if ($kernel->config()->debug) {
            throw new AcfBridgeRuntimeException("ACF field '{$path}' of {$acfId} has an unexpected value: expected {$expected}, got {$actual} (" . static::class . ')');
        }

        $kernel->logger()->error('tmswp.acf: ACF field {field} of {acfId} has an unexpected value: expected {expected}, got {actual} ({class})', [
            'field' => $path,
            'acfId' => $acfId,
            'expected' => $expected,
            'actual' => $actual,
            'class' => static::class,
        ]);

        return $fallback;
    }

    /**
     * listの値。未設定は[]、配列でない値・文字列のキーの配列は形の不一致。
     *
     * @return list<mixed>
     */
    private function acfListOf(mixed $value, string $path): array
    {
        if (self::isUnset($value)) {
            return [];
        }

        if (!\is_array($value)) {
            return $this->acfMismatch($path, 'list', get_debug_type($value), []);
        }

        foreach (array_keys($value) as $key) {
            if (!\is_int($key)) {
                return $this->acfMismatch($path, 'list', 'array with string keys', []);
            }
        }

        // ACFが要素を除いた配列は添字が飛ぶことがあるため、詰め直す
        return array_values($value);
    }

    /**
     * 文字列のキーの配列の値。未設定(空の配列を含む)はnull、配列でない値・listは形の不一致。
     *
     * @return array<string, mixed>|null
     */
    private function acfAssocOf(mixed $value, string $path, string $expected): ?array
    {
        if (self::isUnset($value) || $value === []) {
            return null;
        }

        if (!\is_array($value)) {
            return $this->acfMismatch($path, $expected, get_debug_type($value), null);
        }

        return self::stringKeyedArray($value) ?? $this->acfMismatch($path, $expected, 'list', null);
    }

    /**
     * 連想配列の値を$shapeで検査する。未設定はnull、形が合わなければ形の不一致として扱う。
     *
     * @template T of array
     *
     * @param Closure(array<string, mixed>): (T|null) $shape
     *
     * @return T|null
     */
    private function acfShapeOf(mixed $value, string $path, string $expected, Closure $shape): ?array
    {
        $assoc = $this->acfAssocOf($value, $path, 'array with string keys');

        if ($assoc === null) {
            return null;
        }

        return $shape($assoc) ?? $this->acfMismatch($path, $expected, 'array of another shape', null);
    }

    /**
     * listの要素を$itemで検査する。$itemがnullを返した要素は形の不一致として扱い、本番では記録して除く。
     *
     * @template T
     *
     * @param Closure(mixed): (T|null) $item
     *
     * @return list<T>
     */
    private function acfListItemsOf(mixed $value, string $path, string $expected, Closure $item): array
    {
        $items = [];

        foreach ($this->acfListOf($value, $path) as $index => $element) {
            $typed = $item($element);

            if ($typed === null) {
                $this->acfMismatch("{$path}[{$index}]", $expected, \is_array($element) ? 'array of another shape' : get_debug_type($element), null);
                continue;
            }

            $items[] = $typed;
        }

        return $items;
    }

    /**
     * 連想配列の形の検査を、listの要素の検査(連想配列でない要素はnull)にする。
     *
     * @template T of array
     *
     * @param Closure(array<string, mixed>): (T|null) $shape
     *
     * @return Closure(mixed): (T|null)
     */
    private function arrayItem(Closure $shape): Closure
    {
        return static function (mixed $item) use ($shape): ?array {
            $assoc = \is_array($item) ? self::stringKeyedArray($item) : null;

            return $assoc === null ? null : $shape($assoc);
        };
    }

    /**
     * acf_get_attachment()の画像の配列。width・heightは、WordPressが画像の寸法を返せないとき(SVG等)に無い。
     *
     * @param array<string, mixed> $value
     *
     * @return AcfImage|null
     */
    private function acfImageFromArray(array $value): ?array
    {
        $file = $this->acfFileFromArray($value);
        $sizes = $this->imageSizes($value);

        if ($file === null || $sizes === null) {
            return null;
        }

        $image = $file;

        // acf_get_attachment()と同じ順(width・height・sizes)にする
        if (\array_key_exists('width', $value)) {
            $width = $this->arrayIntValue($value, 'width');

            if ($width === null) {
                return null;
            }

            $image['width'] = $width;
        }

        if (\array_key_exists('height', $value)) {
            $height = $this->arrayIntValue($value, 'height');

            if ($height === null) {
                return null;
            }

            $image['height'] = $height;
        }

        $image['sizes'] = $sizes;

        return $image;
    }

    /**
     * acf_get_attachment()の添付ファイルの配列。urlはwp_get_attachment_url()が、iconはwp_mime_type_icon()がfalseを返しうる。
     *
     * @param array<string, mixed> $value
     *
     * @return AcfFile|null
     */
    private function acfFileFromArray(array $value): ?array
    {
        $idUpper = $this->arrayIntValue($value, 'ID');
        $id = $this->arrayIntValue($value, 'id');
        $filesize = $this->arrayIntValue($value, 'filesize');
        $uploadedTo = $this->arrayIntValue($value, 'uploaded_to');
        $menuOrder = $this->arrayIntValue($value, 'menu_order');
        $title = $this->arrayStringValue($value, 'title');
        $filename = $this->arrayStringValue($value, 'filename');
        $url = $this->arrayStringOrFalseValue($value, 'url');
        $link = $this->arrayStringValue($value, 'link');
        $alt = $this->arrayStringValue($value, 'alt');
        $author = $this->arrayStringValue($value, 'author');
        $description = $this->arrayStringValue($value, 'description');
        $caption = $this->arrayStringValue($value, 'caption');
        $name = $this->arrayStringValue($value, 'name');
        $status = $this->arrayStringValue($value, 'status');
        $date = $this->arrayStringValue($value, 'date');
        $modified = $this->arrayStringValue($value, 'modified');
        $mimeType = $this->arrayStringValue($value, 'mime_type');
        $type = $this->arrayStringValue($value, 'type');
        $subtype = $this->arrayStringValue($value, 'subtype');
        $icon = $this->arrayStringOrFalseValue($value, 'icon');

        if (
            $idUpper === null
            || $id === null
            || $filesize === null
            || $uploadedTo === null
            || $menuOrder === null
            || $title === null
            || $filename === null
            || $url === null
            || $link === null
            || $alt === null
            || $author === null
            || $description === null
            || $caption === null
            || $name === null
            || $status === null
            || $date === null
            || $modified === null
            || $mimeType === null
            || $type === null
            || $subtype === null
            || $icon === null
        ) {
            return null;
        }

        return [
            'ID' => $idUpper,
            'id' => $id,
            'title' => $title,
            'filename' => $filename,
            'filesize' => $filesize,
            'url' => $url,
            'link' => $link,
            'alt' => $alt,
            'author' => $author,
            'description' => $description,
            'caption' => $caption,
            'name' => $name,
            'status' => $status,
            'uploaded_to' => $uploadedTo,
            'date' => $date,
            'modified' => $modified,
            'menu_order' => $menuOrder,
            'mime_type' => $mimeType,
            'type' => $type,
            'subtype' => $subtype,
            'icon' => $icon,
        ];
    }

    /**
     * @param array<string, mixed> $value
     *
     * @return AcfLink|null
     */
    private function acfLinkFromArray(array $value): ?array
    {
        $title = $this->arrayStringValue($value, 'title');
        $url = $this->arrayStringValue($value, 'url');
        $target = $this->arrayStringValue($value, 'target');

        if ($title === null || $url === null || $target === null) {
            return null;
        }

        return [
            'title' => $title,
            'url' => $url,
            'target' => $target,
        ];
    }

    /**
     * @param array<string, mixed> $value
     *
     * @return AcfChoice|null
     */
    private function acfChoiceFromArray(array $value): ?array
    {
        $valueItem = $this->arrayStringValue($value, 'value');
        $label = $this->arrayStringValue($value, 'label');

        if ($valueItem === null || $label === null) {
            return null;
        }

        return [
            'value' => $valueItem,
            'label' => $label,
        ];
    }

    /**
     * ACFのuserフィールド(return_format=array)の配列。user_avatarはget_avatar()が(アバターの表示を無効にしたサイト等で)
     * falseを返しうる。
     *
     * @param array<string, mixed> $value
     *
     * @return AcfUserArray|null
     */
    private function acfUserArrayFromArray(array $value): ?array
    {
        $id = $this->arrayIntValue($value, 'ID');
        $firstName = $this->arrayStringValue($value, 'user_firstname');
        $lastName = $this->arrayStringValue($value, 'user_lastname');
        $nickname = $this->arrayStringValue($value, 'nickname');
        $nicename = $this->arrayStringValue($value, 'user_nicename');
        $displayName = $this->arrayStringValue($value, 'display_name');
        $email = $this->arrayStringValue($value, 'user_email');
        $url = $this->arrayStringValue($value, 'user_url');
        $registered = $this->arrayStringValue($value, 'user_registered');
        $description = $this->arrayStringValue($value, 'user_description');
        $avatar = $this->arrayStringOrFalseValue($value, 'user_avatar');

        if (
            $id === null
            || $firstName === null
            || $lastName === null
            || $nickname === null
            || $nicename === null
            || $displayName === null
            || $email === null
            || $url === null
            || $registered === null
            || $description === null
            || $avatar === null
        ) {
            return null;
        }

        return [
            'ID' => $id,
            'user_firstname' => $firstName,
            'user_lastname' => $lastName,
            'nickname' => $nickname,
            'user_nicename' => $nicename,
            'display_name' => $displayName,
            'user_email' => $email,
            'user_url' => $url,
            'user_registered' => $registered,
            'user_description' => $description,
            'user_avatar' => $avatar,
        ];
    }

    /**
     * @param array<string, mixed> $value
     *
     * @return AcfColorRgba|null
     */
    private function acfColorRgbaFromArray(array $value): ?array
    {
        $red = $this->arrayIntValue($value, 'red');
        $green = $this->arrayIntValue($value, 'green');
        $blue = $this->arrayIntValue($value, 'blue');
        $alpha = $value['alpha'] ?? null;

        if ($red === null || $green === null || $blue === null || !\is_float($alpha)) {
            return null;
        }

        return [
            'red' => $red,
            'green' => $green,
            'blue' => $blue,
            'alpha' => $alpha,
        ];
    }

    /**
     * キーがすべて文字列の配列ならそのまま返す。そうでなければnull。
     *
     * @param array<array-key, mixed> $value
     *
     * @return array<string, mixed>|null
     */
    private static function stringKeyedArray(array $value): ?array
    {
        $typed = [];

        foreach ($value as $key => $item) {
            if (!\is_string($key)) {
                return null;
            }

            $typed[$key] = $item;
        }

        return $typed;
    }

    /** @param array<string, mixed> $value */
    private function arrayIntValue(array $value, string $key): ?int
    {
        $item = $value[$key] ?? null;

        return \is_int($item) ? $item : null;
    }

    /** @param array<string, mixed> $value */
    private function arrayStringValue(array $value, string $key): ?string
    {
        $item = $value[$key] ?? null;

        return \is_string($item) ? $item : null;
    }

    /**
     * 文字列またはfalseの値を返す。キーが無い・ほかの型ならnull。
     *
     * @param array<string, mixed> $value
     */
    private function arrayStringOrFalseValue(array $value, string $key): string|false|null
    {
        $item = $value[$key] ?? null;

        return \is_string($item) || $item === false ? $item : null;
    }

    /**
     * @param array<string, mixed> $value
     *
     * @return array<string, int|string>|null
     */
    private function imageSizes(array $value): ?array
    {
        $sizes = $value['sizes'] ?? null;

        if (!\is_array($sizes)) {
            return null;
        }

        $typed = [];

        foreach ($sizes as $key => $item) {
            if (!\is_string($key) || (!\is_int($item) && !\is_string($item))) {
                return null;
            }

            $typed[$key] = $item;
        }

        return $typed;
    }
}
