# tmswp/acf-bridge

Advanced Custom Fields(ACF)をTMStack WordPress Frameworkで使うための拡張パッケージです。ACFのフィールドグループの定義(`acf-json/`)から型付きアクセサのクラスを生成し、PHPStan(level max)で検査できる形でACFの値を読み書きします。単純な値を`MetaBag`のままACFの画面で編集できるようにする`AcfMetaStorage`も提供します。

## 動作環境

- PHP 8.3〜8.5、WordPress 7.0以上
- tmswp/core `^0.10`(composer.jsonの`require`)
- ACF 6.2以上(無料版・Pro)。ACFはWordPressのプラグインとして入れます(Composerの依存にはしません)。repeater・flexible_content・gallery・clone・オプションページはACF Proの機能です。

## 提供するもの

| 名前 | 役割 |
|---|---|
| `AcfBridge` | `KernelConfig`の`extensions`に渡す拡張。acf-jsonの読み込み先・保存先をACFに設定し、ACFで管理するメタのキーをKernelに申告します |
| `vendor/bin/tms-acf sync\|check` | WordPressを起動せずに、acf-jsonから`app/Acf/`のクラスを生成・照合します |
| `wp tmswp acf sync\|check` | `tms-acf`と同じ生成・照合を、WP-CLIで行います。`#[MetaSchema]`との重複(E101)も検査します |
| 生成アクセサ(`app/Acf/*Fields.php`・`*Options.php`) | フィールドごとのgetter・setter・`has*()`・`delete*()`を持つクラス |
| `AcfMetaStorage` | `#[UseMetaStorage(AcfMetaStorage::class)]`を付けたエンティティの`MetaBag`の保存先を、ACFのフィールドの保存先にします |

## 導入

1. `composer require tmswp/acf-bridge`を実行します。
2. テーマ・プラグインのルートの`tmswp-kernel.php`で、`extensions`に`AcfBridge`を渡します。テーマとプラグインの両方で使うときは、それぞれに渡します。

```php
use TMSWP\AcfBridge\AcfBridge;
use TMSWP\Core\Kernel\KernelConfig;

return KernelConfig::theme(appDir: __DIR__ . '/app', appNamespace: 'App', extensions: [new AcfBridge()]);
```

3. composer.jsonの`scripts`に次を足します。

```json
{
    "scripts": {
        "acf:check": "tms-acf check",
        "acf:sync": "tms-acf sync"
    }
}
```

既定では、ルートの`acf-json/`を読み、`app/Acf/`に名前空間`{appNamespace}\Acf`でクラスを生成します。読み込むディレクトリ・クラス名・メソッド名・オプションページのpost_idを変えるときは、`new AcfBridge(new BridgeConfig(...))`で構成を渡します(`vendor/tmswp/acf-bridge/rules/sync-check.md`の「完全な実装例」)。

## 生成と照合

| コマンド | 動作 |
|---|---|
| `composer acf:sync`(`tms-acf sync [--dry-run] [--root=<ルート>]`) | acf-jsonから`app/Acf/`のクラスを生成し、使われなくなった生成物を消します。`--dry-run`は書き込まずに差分を示します |
| `composer acf:check -- --strict`(`tms-acf check [--strict] [--root=<ルート>]`) | 生成した内容と`app/Acf/`を比べ、差分があれば終了コード1で終わります。`--strict`では警告(W*)でも1になります |
| `wp tmswp acf sync\|check [--app=<appNamespace>]` | 上と同じ生成・照合に、E101の検査を加えます。対象のKernelは`--app=`で選びます |

`tms-acf`は、ルートの`tmswp-kernel.php`を読んで構成を決めます。WordPressとデータベースが無いCIでも動きます。

## 生成物

| location | 生成クラス |
|---|---|
| 投稿タイプ(`post_type == item`) | `ItemFields` |
| テンプレート(`page_template == templates/page-about.php`) | `PageAboutTemplateFields` |
| タクソノミー(`taxonomy == genre`。`all`は`TermFields`) | `GenreTermFields` |
| ユーザー | `UserFields` |
| オプションページ(`options_page == theme-settings`) | `ThemeSettingsOptions` |

ほかに、ACFで管理するメタのキーの一覧`AcfManifest.php`を生成します。acf-bridgeは実行時にacf-jsonを読まず、この生成物を使います。`acf-json/`の変更と`app/Acf/`の生成物は同じコミットに入れ、CIで`composer acf:check -- --strict`を実行し、生成物をデプロイに含めてください(本番へ上げるものと手順は`vendor/tmswp/core/rules/deploy.md`)。

## ACFが無効なとき

- Kernelのbootと、ACFで管理するキーの申告は、ACFが無効でも行われます。
- 生成アクセサの値の読み書きと`AcfMetaStorage`は、`AcfNotActiveException`を投げます。
- 有効なACFの版が6.2より古いときは、`plugins_loaded`で`AcfBridgeRuntimeException`になります。

## レシピ

- ACFでフィールドを管理する(導入・使い分け・テンプレートでの読み方・flexible_content・`value()`・禁止事項): `vendor/tmswp/core/rules/acf.md`
- sync・checkの報告(E*・W*・I002・差分)への対処と`BridgeConfig`: `vendor/tmswp/acf-bridge/rules/sync-check.md`

設計の正本は、TMSWPのmonorepo([tmstack-io/tmswp](https://github.com/tmstack-io/tmswp))の[docs/09-acf-bridge.md](https://github.com/tmstack-io/tmswp/blob/v0.10.0/docs/09-acf-bridge.md)です。

## ライセンス

GPL-2.0-or-later(`LICENSE`)
