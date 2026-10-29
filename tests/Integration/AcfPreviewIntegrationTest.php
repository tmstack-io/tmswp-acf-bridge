<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Tests\Integration;

use TMSWP\Testing\IntegrationTestCase;
use WP_Post;

/**
 * 公開済みの投稿をクラシックの形式(メタボックスのフォームを送る形式)でプレビューすると、ACFが標準の機能だけで
 * フィールドの値をautosaveへ保存し、プレビューの表示でその値を読むことを、有効化した無料版ACFで確かめる
 * (docs/09 §8.3)。acf-bridgeはプレビューに関わる処理を持たないため、ACFのこの挙動を固定する回帰テストである。
 * 下書きのプレビューとブロックエディタのプレビューは対象にしない(§8.3)。
 *
 * ACFはリビジョンを読み出すのにwp_get_post_revisions()を使い、revisionsに対応しない投稿タイプでは読み出しが
 * 親の値になる。そのため、revisionsに対応するpostに、テストの中でフィールドグループを足して確かめる。
 * 足したフィールドの定義と、テストの間にACFが足したキャッシュは、tear_downで除く(set_upの時点のエントリは残す)。
 */
final class AcfPreviewIntegrationTest extends IntegrationTestCase
{
    private const GROUP_KEY = 'group_tmswp_acf_preview';

    private const FIELD_KEY = 'field_tmswp_acf_preview_note';

    private const FIELD_NAME = 'tmswp_acf_preview_note';

    /** テストの間にエントリが増えるACFのstore。local-fieldsはフィールドのnameの別名、fieldsとvaluesはキャッシュ */
    private const ACF_STORES = ['local-fields', 'fields', 'values'];

    /** @var array<mixed> set_upの前の$_GET */
    private array $originalGet = [];

    /** @var array<mixed> set_upの前の$_POST */
    private array $originalPost = [];

    /** @var array<string, array{data: array<mixed>, aliases: array<mixed>}> storeの名前 => set_upの時点のエントリと別名 */
    private array $originalStores = [];

    /** @var array<mixed> set_upの時点のACFのプレビューの投稿IDのキャッシュ(acf()->revisions->cache) */
    private array $originalRevisionCache = [];

    public function set_up(): void
    {
        // WordPressのテストの基底のset_upが$_GET・$_POSTを空にするため、その前に控える
        $this->originalGet = $_GET;
        $this->originalPost = $_POST;
        parent::set_up();
        require_once ABSPATH . 'wp-admin/includes/post.php';

        foreach (self::ACF_STORES as $name) {
            $store = acf_get_store($name);
            $this->originalStores[$name] = ['data' => $store->data, 'aliases' => $store->aliases];
        }

        $this->originalRevisionCache = acf()->revisions->cache;

        $userId = self::factory()->user->create(['role' => 'administrator']);
        self::assertIsInt($userId);
        wp_set_current_user($userId);
        acf_add_local_field_group([
            'key' => self::GROUP_KEY,
            'title' => 'Preview',
            'fields' => [['key' => self::FIELD_KEY, 'name' => self::FIELD_NAME, 'label' => 'Note', 'type' => 'text']],
            'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]],
        ]);
    }

    /**
     * 足したフィールドグループとフィールドの定義を外し、ACFのstoreからテストの間に増えたエントリを除いて別名を
     * set_upの時点に戻す。値を書き換えたエントリは無いため、set_upの時点にあったエントリはそのまま残す。
     */
    public function tear_down(): void
    {
        acf_remove_local_field_group(self::GROUP_KEY);
        acf_remove_local_field(self::FIELD_KEY);

        foreach ($this->originalStores as $name => $original) {
            $store = acf_get_store($name);
            $store->data = array_intersect_key($store->data, $original['data']);
            $store->aliases = $original['aliases'];
        }

        acf()->revisions->cache = array_intersect_key(acf()->revisions->cache, $this->originalRevisionCache);

        parent::tear_down();
        $_GET = $this->originalGet;
        $_POST = $this->originalPost;
    }

    /** 公開済みの投稿をクラシックの形式でプレビューすると、autosaveの新規作成でも更新でもACFの値をautosaveへ保存し、親の値を変えない。 */
    public function testPreviewOfPublishedPostSavesAcfValueToTheAutosaveAndKeepsTheParentValue(): void
    {
        $postId = $this->createPublishedPostWithValue('parent');

        $this->preview($postId, 'first');
        $autosave = $this->autosave($postId);

        self::assertSame('first', get_post_meta($autosave->ID, self::FIELD_NAME, true));
        self::assertSame('parent', get_post_meta($postId, self::FIELD_NAME, true));

        // 2回目のプレビューは、既存のautosaveを更新する(wp_create_post_autosave()のwp_update_post()の経路)
        $this->preview($postId, 'second');

        self::assertSame($autosave->ID, $this->autosave($postId)->ID);
        self::assertSame('second', get_post_meta($autosave->ID, self::FIELD_NAME, true));
        self::assertSame('parent', get_post_meta($postId, self::FIELD_NAME, true));
    }

    /** プレビューのURLではget_field()がautosaveの値を返し、通常の表示では親の値を返す。 */
    public function testPreviewUrlReadsTheAutosaveValue(): void
    {
        $postId = $this->createPublishedPostWithValue('parent');

        $this->go_to($this->preview($postId, 'previewed'));

        self::assertTrue(is_preview());
        self::assertSame('previewed', get_field(self::FIELD_NAME, $postId));

        $permalink = get_permalink($postId);
        self::assertIsString($permalink);
        $this->go_to($permalink);

        self::assertFalse(is_preview());
        self::assertSame('parent', get_field(self::FIELD_NAME, $postId));
    }

    private function createPublishedPostWithValue(string $value): int
    {
        $postId = self::createPost('post');
        update_field(self::FIELD_KEY, $value, $postId);

        return $postId;
    }

    /**
     * クラシックの編集画面で公開済みの投稿の「プレビュー」ボタンを押したときの送信を再現し、post_preview()を呼んで
     * プレビューのURLを返す。
     *
     * ACFの内部に依存する項目は、ACFが保存するのに要る次の3つだけである。
     * - `wp-preview=dopreview`: ACFはリビジョンへの保存を、プレビューの送信で`post_ID`が親と一致するときだけ許す
     * - `_acf_nonce`: ACFのメタボックスが出力するnonce(action `post`)。ACFは保存の前にこれを検証する
     * - `_acf_changed=1`: ACFのJavaScriptがフィールドの変更時に立てる。これが無いと、ACFはautosaveの作成後に
     *   親のメタをautosaveへ写し、プレビューの値を親の値で上書きする
     */
    private function preview(int $postId, string $value): string
    {
        $_POST = [
            'post_ID' => (string) $postId,
            'post_type' => 'post',
            'post_title' => "Preview {$value}",
            'post_status' => 'publish',
            'wp-preview' => 'dopreview',
            '_acf_nonce' => wp_create_nonce('post'),
            '_acf_changed' => '1',
            'acf' => [self::FIELD_KEY => $value],
        ];

        $link = post_preview();
        $_POST = [];

        return $link;
    }

    private function autosave(int $postId): WP_Post
    {
        $autosave = wp_get_post_autosave($postId, get_current_user_id());
        self::assertInstanceOf(WP_Post::class, $autosave);

        return $autosave;
    }
}
