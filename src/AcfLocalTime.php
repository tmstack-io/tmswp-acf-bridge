<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge;

use DateTimeImmutable;
use DateTimeZone;

/**
 * ACFのdate_time_pickerのraw(時差を持たない現地の時刻)と瞬間の対応を求める。
 *
 * AcfFieldAccess(生成クラスのtrait)が使う。traitに定数・staticメンバーを置くと、生成クラスの同じ名前のメンバー
 * (フィールド名から作る定数・メソッド)と衝突するため、ここに置く。
 *
 * @internal AcfFieldAccessだけが使う
 */
final class AcfLocalTime
{
    /** date_time_pickerのraw(保存)の書式。日付と時刻の両方を持ち、瞬間を保存する唯一の書式 */
    public const string DATE_TIME_RAW_FORMAT = 'Y-m-d H:i:s';

    private function __construct() {}

    /**
     * 現地の時刻$wallClock(Y-m-d H:i:s)が$timezoneで表す瞬間(タイムスタンプ)を、早い順に返す。夏時間の終わり等で同じ
     * 時刻が2回来る間は2つ、ふつうは1つ、夏時間の始まり等で飛ばされる時刻は0になる。
     *
     * $timezoneの切り替わり(getTransitions())から、$wallClockの前後で使われうるUTCからのオフセットを集め、オフセット
     * ごとに求めた候補の瞬間を現地の時刻に戻して$wallClockと一致するものを数える。PHPのパーサーが2回来る時刻のどちらを
     * 選ぶかは地域によって違うため、パースした結果では判定しない。
     *
     * @return list<int>
     */
    public static function instants(string $wallClock, DateTimeZone $timezone): array
    {
        $asUtc = DateTimeImmutable::createFromFormat('!' . self::DATE_TIME_RAW_FORMAT, $wallClock, new DateTimeZone('UTC'));

        if (!$asUtc instanceof DateTimeImmutable) {
            return [];
        }

        $naive = $asUtc->getTimestamp();
        // UTCからのオフセットは±1日に収まるため、前後2日の切り替わりで、$wallClockに関係するオフセットがすべて得られる
        $transitions = $timezone->getTransitions($naive - 2 * 86400, $naive + 2 * 86400);
        $offsets = [$timezone->getOffset($asUtc)];

        foreach (\is_array($transitions) ? $transitions : [] as $transition) {
            $offsets[] = $transition['offset'];
        }

        $instants = [];

        foreach (array_unique($offsets) as $offset) {
            $candidate = $naive - $offset;

            if ((new DateTimeImmutable('@' . $candidate))->setTimezone($timezone)->format(self::DATE_TIME_RAW_FORMAT) === $wallClock) {
                $instants[] = $candidate;
            }
        }

        $instants = array_values(array_unique($instants));
        sort($instants);

        return $instants;
    }
}
