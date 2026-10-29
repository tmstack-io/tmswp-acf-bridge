<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge;

/**
 * 生成クラスの戻り値の型(@phpstan-type)の定義。形はACFが返す配列(acf_get_attachment()・userフィールド等)に合わせる。
 * - AcfFile・AcfImageのurlはwp_get_attachment_url()が、iconはwp_mime_type_icon()がfalseを返しうる。
 * - AcfImageのwidth・heightは、WordPressが画像の寸法を返せないとき(SVG等)に無い。sizesは空の配列になりうる。
 * - AcfUserArrayのuser_avatarは、get_avatar()が(アバターの表示を無効にしたサイト等で)falseを返しうる。
 *
 * @phpstan-type AcfImage array{ID: int, id: int, title: string, filename: string, filesize: int, url: string|false, link: string, alt: string, author: string, description: string, caption: string, name: string, status: string, uploaded_to: int, date: string, modified: string, menu_order: int, mime_type: string, type: string, subtype: string, icon: string|false, width?: int, height?: int, sizes: array<string, int|string>}
 * @phpstan-type AcfFile array{ID: int, id: int, title: string, filename: string, filesize: int, url: string|false, link: string, alt: string, author: string, description: string, caption: string, name: string, status: string, uploaded_to: int, date: string, modified: string, menu_order: int, mime_type: string, type: string, subtype: string, icon: string|false}
 * @phpstan-type AcfLink array{title: string, url: string, target: string}
 * @phpstan-type AcfChoice array{value: string, label: string}
 * @phpstan-type AcfUserArray array{ID: int, user_firstname: string, user_lastname: string, nickname: string, user_nicename: string, display_name: string, user_email: string, user_url: string, user_registered: string, user_description: string, user_avatar: string|false}
 * @phpstan-type AcfColorRgba array{red: int, green: int, blue: int, alpha: float}
 */
final class AcfTypes
{
    private function __construct() {}
}
