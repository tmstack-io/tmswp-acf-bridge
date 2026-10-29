# acf-bridgeのsync・checkの報告に対処する

`composer acf:sync`・`composer acf:check`(`vendor/bin/tms-acf`)と`wp tmswp acf sync|check`が出すエラー(E*)・警告(W*)・情報(I002)・差分を直し、`check --strict`を通す。生成の調整が要るときは、`tmswp-kernel.php`で`AcfBridge`に`BridgeConfig`を渡す。
導入の手順、生成アクセサの使い方、acf-jsonと生成物のコミットの規則はvendor/tmswp/core/rules/acf.mdにある。
例はテーマのもので、例の名前空間`App\`は、プロジェクトの名前空間(`tmswp-kernel.php`の`KernelConfig`の`appNamespace`)に読み替える。

## 手順

1. テーマ・プラグインのルートで`composer acf:check -- --strict`を実行し、出力を読む(下の「出力の読み方」)。`tms-acf`は、カレントディレクトリの`tmswp-kernel.php`を読んで構成を決める。別のディレクトリのテーマ・プラグインは`vendor/bin/tms-acf check --strict --root=<ルート>`で照合する。
2. 差分の行(`missing`・`modified`・`orphan`)だけなら、`composer acf:sync`で生成物を作り直す。
3. E*・W*は、下の「コードごとの対処」の表のとおりに直す。ACFの管理画面でフィールドグループを直したときは、保存でacf-jsonが更新されるので、続けて`composer acf:sync`を実行する。
4. `BridgeConfig`が要る対処(classMap・methodNameMap・jsonDirs・optionsPagePostIds)は、下の「完全な実装例」の形で`tmswp-kernel.php`に書き、`composer acf:sync`を実行する。
5. `composer acf:check -- --strict`が終了コード0で終わるまで1〜4を繰り返す。E101は、WordPressの環境で`wp tmswp acf check --strict`を実行して確かめる。テーマとプラグインの両方が`AcfBridge`を登録しているサイトでは、`wp tmswp acf check --strict --app=<appNamespace>`で対象のKernelを選ぶ(1つだけなら`--app=`は省ける)。
6. `acf-json/`と`app/Acf/`の変更を同じコミットに入れる。

## 出力の読み方

- 先頭の行は、`tms-acf`がE101を検査しないことの注記である(`wp tmswp acf`では出ない)。
- `E001: …`・`W009: …`・`I002: …`の行が、コードごとの報告である。行の中に、グループのkey・フィールド名・ファイル等の対象が入る。
- `check`の差分は、ファイルごとに`missing: {ファイル}`(生成されるはずのファイルが無い)・`modified: {ファイル}`(内容が違う)・`orphan: {ファイル}`(`@generated`の印があるが、もう生成されないファイル)の行で示す。`modified`の後には、今のファイルから生成される内容への統一diffの先頭40行が続く。
- `sync`は、書いたファイルを`write: {ファイル}`、消した`orphan`のファイルを`delete: {ファイル}`で示す。`sync --dry-run`は、ファイルを書かずに`check`と同じ形で差分を示す。
- 生成のE*(フィールド名・クラス名の衝突、cloneの誤り、JSONの誤り等)があると、`sync`は書き込みを始める前に止まり、ファイルを1つも書き込まない。
- 書き込みの途中でファイルの書き込み・置き換え・削除に失敗した(E102。終了コード1)ときは、それまでに書いたファイルは更新されたまま残る。原因(出力先の権限等)を直し、もう一度`composer acf:sync`を実行する。
- 終了コード(`tms-acf`と`wp tmswp acf`で同じ):
  - `sync`: 0は成功(`--dry-run`を含む)。1は生成のE*(E001〜E005・E101)か、出力先の読み書きの失敗(E102)。2は引数・構成の誤りか、acf-jsonの読み込みの失敗・オプションページのpost_idの食い違い(E102)。
  - `check`: 0は差分なし(`--strict`ではW*も無い)。1は差分あり、または`--strict`でW*あり。2はE*か、引数・構成の誤り。

## コードごとの対処

| コード | 原因 | 対処 |
| --- | --- | --- |
| E001 | 同じクラスにまとまる複数のグループに、同じ名前のフィールドがある。または、テンプレート別のクラスとその投稿タイプのクラスに、同じ名前の別のフィールドがある | ACFでどちらかのフィールド名を変える。名前は保存されるメタのキーなので、保存済みの値は移し替える |
| E002 | 生成するメソッド名・定数名が、予約名(生成クラスのtraitのメソッド(`value`・`has`・`update`・`delete`等)と`of`・`instance`)やほかのフィールドのものと衝突する(メソッド名は大文字小文字を区別しない)。フィールド名が`class`。サブフィールドの名前の重複・`acf_fc_layout`・整数の名前・`*/`を含む名前。flexible_contentのレイアウトの名前の重複・整数の名前・`*/`を含む名前。レイアウトの型の別名が衝突する。`methodNameMap`で付けた名前が、英小文字で始まる英数字(`^[a-z][A-Za-z0-9]*$`)でない | トップレベルのフィールドは、`methodNameMap`で別のメソッド名を付ける(定数の名前もその名前から作られる)。サブフィールドとレイアウトは、ACFで名前を変える |
| E003 | 違うlocationが同じクラス名になる(同じファイル名のテンプレートを含む)。クラス名にできる英数字が名前に無い。`classMap`の指定が不正(locationの解決結果が複数のクラスになるグループへの指定、1つのクラスに違う名前の指定) | locationの解決結果が1つのクラスにまとまるグループ(同じテンプレートを複数の投稿タイプに使う場合を含む)に、`classMap`でクラス名を付ける。付けられないときは、投稿タイプ・タクソノミー・menu_slug・テンプレートのファイル名を変える |
| E004 | cloneの参照先(field keyかgroup key)がacf-jsonに無い | 参照先のグループのJSONを`jsonDirs`のディレクトリに置くか、ACFでcloneの参照を直す |
| E005 | cloneが循環している | ACFで循環しているcloneの参照を外す |
| E101 | ACFで管理するキーを、`#[UseMetaStorage(AcfMetaStorage::class)]`の無いエンティティが`#[MetaSchema]`で宣言している(`wp tmswp acf`だけが出す) | 次のどれかにする。エンティティに`#[UseMetaStorage(AcfMetaStorage::class)]`を付ける(単純な値の型のフィールドに限る)。`#[MetaSchema]`の定数を消して生成アクセサで扱う。acf-jsonからそのフィールドを消して`#[MetaSchema]`だけで扱う |
| E102 | JSONを読めない・構文の誤り。トップレベルが配列のJSON。同じディレクトリに同じkeyのJSONが2つ以上ある。出力先に書き込めない、出力先のPHPファイルを読めない。同じオプションページに、`ui_options_page_*.json`と`optionsPagePostIds`が違うpost_idを指定している | JSONを直す(1ファイルに1つのグループ)。同じkeyのJSONはどちらかを消す。出力先の権限を直す。post_idの指定をどちらか1つにする |
| W001 | 生成の対象でないJSON(`post_type_*.json`・`taxonomy_*.json`、keyの無いもの、種類の分からないもの、トップレベルが文字列・数値・`null`のもの)を無視した | JSONを消す。投稿タイプ・タクソノミーは`#[AsPostType]`・`#[AsTaxonomy]`でPHPに宣言し、ACFの管理画面では登録しない |
| W002 | locationから生成するクラスを決められず、その条件をスキップした(`==`で対象を決める条件が無い、`post_type ==`の無い`post_template`、違う投稿タイプを示す条件) | locationに`post_type ==`・`taxonomy ==`・`options_page ==`等の対象を決める条件を足す |
| W003 | 個別の投稿のID(`post ==`)だけの条件は、生成するクラスを決められない | `post_type ==`か`page_template ==`の条件にする |
| W004 | 1つのANDの条件に、種類の違う対象(投稿タイプとタクソノミー等)がある。優先順位で1つを選んだ | 対象ごとにORの条件に分ける |
| W005 | 生成が扱わない型(google_map・サードパーティの型等)のフィールドを、`mixed`のアクセサで生成した | 扱う型のフィールドに変える。`--strict`は失敗するため、扱わない型のフィールドをフィールドグループに置かない |
| W006 | 対応しないlocation(`block`・`comment`・`widget`・`nav_menu`・`nav_menu_item`、未知のもの)の条件をスキップした | その条件を外す。ブロックは、フレームワークの独自ブロックの方式で作る |
| W007 | 1つのANDの条件が同時に成り立たない(同じ値に対する違う`==`、同じ値の`==`と`!=`、`!= all`) | 条件を直す |
| W009 | フィールド名がASCIIのsnake_case(`^[a-z][a-z0-9_]*$`)でないため、アクセサを生成しなかった | ACFでフィールド名を変える。変えられないときは`methodNameMap`でメソッド名を付ける |
| W010 | 同じkeyのJSONが複数のディレクトリにある。`jsonDirs`の先のディレクトリのものを使った | 使わない方のJSONを消す |
| W011 | 無効(`active: false`)のグループをスキップした | ACFで有効にする。使わないグループは消す |
| I002 | 出力先(`app/Acf/`)に、`@generated`の印の無いPHPファイルがある。消さない(`--strict`でも失敗にしない) | そのファイルを`app/Acf/`の外に移す |

## 完全な実装例

子テーマのacf-jsonと親テーマのacf-jsonから生成し、クラス名・メソッド名・オプションページのpost_idを指定する、子テーマの`tmswp-kernel.php`:

```php
<?php

declare(strict_types=1);

use TMSWP\AcfBridge\AcfBridge;
use TMSWP\AcfBridge\BridgeConfig;
use TMSWP\Core\Kernel\KernelConfig;

$appDir = __DIR__ . '/app';

return KernelConfig::theme(
    appDir: $appDir,
    appNamespace: 'App',
    extensions: [
        new AcfBridge(new BridgeConfig(
            // 並びの先のディレクトリを優先する。子テーマのacf-jsonを先に置く
            jsonDirs: [__DIR__ . '/acf-json', dirname(__DIR__) . '/my-parent-theme/acf-json'],
            outputDir: $appDir . '/Acf',
            namespace: 'App\Acf',
            // グループのkey => クラスの短い名前(locationの解決結果が1つのクラスにまとまるグループに限る)
            classMap: ['group_landing' => 'LandingFields'],
            // "{グループのkey}.{フィールド名}" => メソッド名(定数はNOTE・KEY_NOTEになる)
            methodNameMap: ['group_item.備考' => 'note'],
            // PHPで登録したオプションページのmenu_slug => ACFのpost_id
            optionsPagePostIds: ['theme-settings' => 'theme_settings'],
        )),
    ],
);
```

- `BridgeConfig`を渡さないときの構成は、`jsonDirs`がルートの`acf-json`だけ、`outputDir`が`{appDir}/Acf`、`namespace`が`{appNamespace}\Acf`である。1つでも変えるときは、`BridgeConfig`の全体を渡す。
- `jsonDirs`・`outputDir`は絶対パスにする。存在しない`jsonDirs`のディレクトリは無視される。`namespace`は`appNamespace`か、その配下にする。誤りは`AcfBridgeRuntimeException`になり、`tms-acf`は終了コード2で終わる。
- 子テーマのacf-jsonは、既定では生成の対象にならない。子テーマのグループを生成するときは、`jsonDirs`に子テーマのacf-jsonを先にして並べる。ACFも、同じkeyでは`jsonDirs`の先のディレクトリのJSONを使う。
- `optionsPagePostIds`は、acf-jsonの`ui_options_page_*.json`に無いオプションページ(`acf_add_options_page()`でPHPから登録したもの)のpost_idに使う。どちらにも無いページの生成クラスは`option`を読む。
- このファイルは、WordPressを起動しない`tms-acf`も読む。WordPressの関数(`get_template_directory()`等)を呼ばず、パスは`__DIR__`から決める。
- `BridgeConfig`の`registerLoadJsonDirs`(既定`true`)は、`jsonDirs`をACFの読み込み先(`acf/settings/load_json`)に加えるかである。`false`にするのは、ACFの読み込み先の並びを自分のフィルタで決めるときだけで、そのときは`jsonDirs`の先のディレクトリを並びの後に置く(ACFは同じkeyのJSONのうち並びの後のものを使うため)。

## してはいけないこと

- 生成物(`app/Acf/`)を手で直して`check`を通さない。直すのはacf-json・`BridgeConfig`・`#[MetaSchema]`の宣言で、生成物は`composer acf:sync`で作り直す。
- W*を残したまま`--strict`を外さない。W*は、生成されないフィールドやグループがあることを示す。
- `jsonDirs`の外のディレクトリ(ACFが読むほかのacf-json)に、同じkeyのJSONを置かない。テーマとプラグインのacf-jsonにも同じkeyのJSONを置かない。ACFが使うファイルと生成に使うファイルが食い違う。
- `registerLoadJsonDirs`が`true`のまま、`jsonDirs`の並びを変える`acf/settings/load_json`・`acf/json/load_paths`のフィルタを書かない。理由は上と同じ。
- `tms-acf check`が通ったことで、E101の検査が済んだとみなさない。E101は`wp tmswp acf`(`sync`・`check`)だけが検査し、`tms-acf`は検査しない。

## 確認方法

1. `composer acf:check -- --strict`が終了コード0で終わり、`Success: ACF bridge is in sync.`で始まる行(続けて生成物の数とacf-jsonのハッシュ)を出すこと。
2. WordPressの環境で`wp tmswp acf check --strict`が通ること。
3. `composer qa`が通ること。

## 発展

- acf-bridgeの導入・生成アクセサの使い方・テンプレートでの読み方・acf-jsonと生成物のコミットの規則: vendor/tmswp/core/rules/acf.md
- `#[MetaSchema]`と`AcfMetaStorage`(E101の対処): vendor/tmswp/core/rules/meta.mdの「ACFのフィールドグループで管理するキー」
- 設計の正本(locationの解決表・型の対応表・コード表): TMSWPのmonorepoのdocs/09-acf-bridge.md
