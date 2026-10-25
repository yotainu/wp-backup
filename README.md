# WP pCloud Backup

WordPress サイトのファイルとデータベースを、定期的に pCloud へバックアップするプラグインです。共用サーバー（heteml、さくらのレンタルサーバーなど）でも最後まで走り切るよう、処理を小さなステップに分けて少しずつ進めます。

## 概要

- 対象は「サイトの公開ルート以下のすべてのファイル」と「データベースの全テーブル」です。
- ファイルは ZIP64 形式で固めて 25MB（10 / 25 / 50 / 100MB から選択）ごとに分割し、データベースは gzip 圧縮した SQL として pCloud にアップロードします。
- 周期（毎日 / 3日おき / 毎週 / 2週間おき / 毎月）と保持世代数（1〜30、既定 3）を設定でき、古い世代は自動で削除されます。
- 失敗したときだけメールで知らせます（成功時の通知は設定で ON にできます）。
- GitHub Releases から自動更新されます。
- pCloud 上の配置は次のとおりです。

```
/WPBackup/
  example.com/
    2026-10-24_030000/
      manifest.json        日時、WordPress / PHP のバージョン、起点フォルダ、各パートのハッシュなど
      database.sql.gz      データベースのダンプ
      files.zip.001        ファイル一式の ZIP を単純にバイト分割したもの
      files.zip.002
      ...
    2026-10-17_030000/
```

作成途中のフォルダは `.incomplete-2026-10-24_030000` のように先頭に `.incomplete-` が付きます。この名前のフォルダは未完了なので復元には使えません（48時間経つと自動で削除されます）。

## 対応環境

- WordPress 6.0 以上
- PHP 7.4 以上（`zlib`、`curl`、`openssl` 拡張が必要。足りない場合は有効化時にエラーで止まり、不足している項目が表示されます）
- pCloud アカウント（米国リージョンは `api.pcloud.com`、EU リージョンは `eapi.pcloud.com`）
- 想定サーバー: heteml、さくらのレンタルサーバー（どちらも共用サーバーで動作）
- 約 40GB を超える大規模サイト（VPS など）はこのプラグインの対象外です。

### 検証済み環境

作成した `files.zip`（結合後）を次のツールで読めることを確認しています。

| ツール | 結果 |
|---|---|
| Info-ZIP `unzip -t` | 確認済み |
| Python `zipfile` | 確認済み |
| bsdtar（ファイルとして読む場合 / ストリームで読む場合の両方） | 確認済み |
| macOS `ditto` | 確認済み |
| macOS アーカイブユーティリティ（Finder でダブルクリック） | 確認済み |
| PHP `ZipArchive`（`CHECKCONS` 付き） | 確認済み |
| Windows エクスプローラー | **未確認**（ZIP64 形式のため。開けない場合は [7-Zip](https://7-zip.org/) を使ってください） |

## 導入手順

多数のサイトに入れるときの標準的な流れです。

1. 最初の1サイトで「pCloud トークンの取得」の手順を行い、アクセストークンを控えておきます。
2. 各サイトの `wp-config.php` に `WP_PCLOUD_BACKUP_ACCESS_TOKEN` と `WP_PCLOUD_BACKUP_NOTIFY_EMAIL` を書きます（SSH が使える heteml ではこれが最速です。さくらは FTP またはファイルマネージャで編集します。管理画面の「接続」タブから貼り付けても構いません）。
3. プラグインの zip をアップロードして有効化します（WP-CLI が使えるなら `wp plugin install <zip の URL> --activate`）。以後の更新は自動です。
4. 周期や世代数を変えたいサイトだけ、管理画面（設定 → pCloud Backup）で変更します。
5. 外部 cron を使うサイト（さくらなど）は「WP-Cron で自動実行する」を OFF にして、外部 cron 用 URL をコントロールパネルに 5 分ごとで登録します（後述）。
6. 「今すぐバックアップ」で1回手動実行し、pCloud にファイルが届くこと、最後まで完走することを確認します。
7. まず 2〜3 サイトで数週間運用し、安定したら残りのサイトへ展開します。

### リリース前に必ず `OWNER` を置き換える

リポジトリ内の `OWNER` は GitHub アカウント名の仮置きです。**最初のリリースの前に**、次の2か所を自分のアカウント名に置き換えてください。残っていると、自動更新が存在しない URL を見に行きます。

- `update.json` 内の `OWNER`（`download_url`、`sha256_url`、`homepage`）
- `wp-pcloud-backup.php` の `WP_PCLOUD_BACKUP_UPDATE_URL` の既定値（`https://raw.githubusercontent.com/OWNER/wp-pcloud-backup/main/update.json`）

`bin/release.sh` は `git remote` の URL から所有者を読み取って `update.json` を書き換えますが、`wp-pcloud-backup.php` の既定値は置き換えません。こちらは最初に手で直してコミットしておいてください。

## pCloud トークンの取得

トークンの取得は最初の1回だけです。pCloud のアクセストークンは期限がないので、同じトークンを全サイトで使い回せます。

1. pCloud の [My Apps](https://docs.pcloud.com/my_apps/) でアプリを1つ登録し、Client ID と Client Secret を控えます。redirect URI は登録しません。
2. 最初のサイトで、管理画面の「接続」タブの「認可コードからトークンを取得する」に Client ID と Client Secret を入力し、「認可ページを開く」を押します。pCloud の認可画面が新しいタブで開くので「許可」を押すと、画面に認可コードが表示されます。
3. そのコードをプラグインに貼り付けて「接続」を押します。プラグインがアクセストークンに交換し、暗号化して保存します（Client Secret はこの交換にだけ使い、保存しません）。
4. 接続後、「接続」タブの「アクセストークンを表示する」で現在のパスワードを入力して「トークンを表示」を押すと、トークンを一度だけ表示します。これをコピーして控えます。

2サイト目以降は、トークンを `wp-config.php` の定数に書くか、「接続」タブの「アクセストークンを貼り付け」欄に入れて保存します。保存時にトークンの有効性を確認し、接続先アカウントのメールアドレスを表示します。

## `wp-config.php` の定数一覧

`wp-config.php` に次の定数を書くと、管理画面の設定より優先され、画面上は「定数で設定済み」と表示されてロックされます。「`/* That's all, stop editing! */`」の行より前に書いてください。

```php
define( 'WP_PCLOUD_BACKUP_ACCESS_TOKEN', 'ここにアクセストークン' );
define( 'WP_PCLOUD_BACKUP_NOTIFY_EMAIL', 'admin@example.com' );
define( 'WP_PCLOUD_BACKUP_ROOT_FOLDER', '/WPBackup' );
define( 'WP_PCLOUD_BACKUP_SCHEDULE', 'weekly' );
define( 'WP_PCLOUD_BACKUP_KEEP', 3 );
```

| 定数 | 内容 |
|---|---|
| `WP_PCLOUD_BACKUP_ACCESS_TOKEN` | pCloud のアクセストークン |
| `WP_PCLOUD_BACKUP_NOTIFY_EMAIL` | 通知メールの宛先（カンマ区切りで複数可） |
| `WP_PCLOUD_BACKUP_ROOT_FOLDER` | pCloud 上の保存先ルートフォルダ（既定 `/WPBackup`） |
| `WP_PCLOUD_BACKUP_SCHEDULE` | 周期。`daily`（毎日）、`every3days`（3日おき）、`weekly`（毎週）、`biweekly`（2週間おき）、`monthly`（毎月）のいずれか |
| `WP_PCLOUD_BACKUP_KEEP` | 保持する世代数（1〜30） |

### 開発者・上級者向けの定数

通常は設定不要です。

```php
// EU リージョンの pCloud アカウントを使う場合
define( 'WP_PCLOUD_BACKUP_API_HOST', 'eapi.pcloud.com' );
```

| 定数 | 内容 |
|---|---|
| `WP_PCLOUD_BACKUP_API_HOST` | pCloud の API ホスト。既定は `api.pcloud.com`、EU アカウントは `eapi.pcloud.com` |
| `WP_PCLOUD_BACKUP_STORAGE_DIR` | 開発モード。pCloud の代わりに、このサーバー上のフォルダへ保存します（本番では使わないでください） |
| `WP_PCLOUD_BACKUP_UPDATE_URL` | 自動更新の `update.json` の URL。既定は `https://raw.githubusercontent.com/OWNER/wp-pcloud-backup/main/update.json` |
| `WP_PCLOUD_BACKUP_GITHUB_TOKEN` | GitHub リポジトリを非公開にした場合の読み取りトークン |

## 外部 cron の設定例

WP-Cron はサイトにアクセスがないと動きません。アクセスが少ないサイトや、確実に定時で動かしたいサイトでは、サーバーの cron から URL を呼びます。

1. 管理画面の「設定」タブで「WP-Cron で自動実行する」を OFF にします。
2. 「接続」タブの「外部 cron の URL」をコピーします。形式は次のとおりです（`key` は 64 文字の英数字です）。

   ```
   https://example.com/?wp-pcloud-backup=run&key=<64桁の16進文字列>
   ```

   この URL でできるのは「バックアップの処理を1回進める」ことだけです。データの取得や設定変更はできません。鍵は「接続」タブの「URL を再生成」で再発行できます。
3. サーバーの cron に **5 分ごと** で登録します。コマンドの例:

   ```
   curl -fsS 'https://example.com/?wp-pcloud-backup=run&key=<64桁の16進文字列>' > /dev/null
   ```

   `curl` が使えないときは `wget -q -O /dev/null 'URL'` でも構いません。URL は必ずシングルクォートで囲んでください（`&` がシェルに解釈されるのを防ぐため）。

### heteml

コントロールパネル → cron 設定 から、実行間隔を 5 分ごとにして、上の `curl` または `wget` のコマンドを登録します。PHP ファイルを実行する方式も選べます。

### さくらのレンタルサーバー

サーバーコントロールパネル → スクリプト設定 → CRON 設定 から、5 分ごとのスケジュールでコマンド（`curl` / `wget`）または PHP ファイルを登録します。

## 復元手順

**これはバックアップが必要になったとき（サイトが壊れた、消えた）に使う手順です。落ち着いて、上から順に行ってください。** 作業は自分のパソコンで行い、最後にサーバーへアップロードします。

### 0. 準備

- 復元に使う日付フォルダ（例: `2026-10-24_030000`）を決めます。`.incomplete-` で始まるフォルダは未完了なので使わないでください。
- pCloud のウェブ画面かアプリで、そのフォルダの中身をすべて（`manifest.json`、`database.sql.gz`、`files.zip.001`、`files.zip.002` …）パソコンの空のフォルダにダウンロードします。
- `manifest.json` をテキストエディタで開き、`backup_root`（ファイルを戻す先のサーバー上のパス）を控えておきます。

### 1. ファイルを復元する

1. 分割された ZIP を1つに結合します。ダウンロードしたフォルダでターミナルを開いて実行します。

   - macOS / Linux:

     ```
     cat files.zip.* > files.zip
     ```

   - Windows（PowerShell）:

     ```
     cmd /c copy /b files.zip.* files.zip
     ```

   `files.zip.001` から順に結合される必要があります。`.001`、`.002` … と連番になっていることを確認してください（`.010` 以降があっても、数字の順に並びます）。

2. （任意）ダウンロードが壊れていないか確認します。`manifest.json` の `parts` に各パートの `sha256` が書いてあります。

   ```
   shasum -a 256 files.zip.001
   ```

   の出力が、`parts` の `files.zip.001` の `sha256` と一致すれば正常です（Linux では `sha256sum`、Windows の PowerShell では `Get-FileHash -Algorithm SHA256 files.zip.001`）。

3. 展開します。

   ```
   unzip files.zip -d restored
   ```

   macOS では `files.zip` を Finder でダブルクリックしても展開できます。Windows でエクスプローラーから開けないときは 7-Zip を使ってください。

4. 展開してできた `restored` フォルダの**中身**を、サーバーの `manifest.json` の `backup_root` に記録されているフォルダへアップロードします（FTP、ファイルマネージャ、`rsync` など）。ZIP の最上位は `backup_root` にそのまま対応しているので、`restored` フォルダ自体ではなく、その中身を置いてください。`manifest.json` には `abspath`（バックアップ時の WordPress の設置パス）も記録されています。WordPress 本体をサブフォルダに置いているサイトでは、`backup_root` がその一つ上のフォルダになります。

### 2. データベースを復元する

1. `database.sql.gz` を展開します。

   ```
   gunzip database.sql.gz
   ```

   `database.sql` ができます（元のファイルを残したいときは `gunzip -k`）。

2. 次のどちらかで流し込みます。

   - phpMyAdmin: 対象のデータベースを選び、「インポート」で `database.sql` を指定して実行します。大きくて受け付けられないときは、次の `mysql` コマンドを使ってください。
   - コマンドライン:

     ```
     mysql -u ユーザー名 -p データベース名 < database.sql
     ```

   ダンプは先頭に `SET FOREIGN_KEY_CHECKS=0` があり、各テーブルは `DROP TABLE IF EXISTS` から始まります。**同名のテーブルは削除されて作り直される**ので、既存のデータがある環境に流し込むと上書きされます。

### 3. 復元後の確認

- `wp-config.php` のデータベース接続情報（`DB_NAME`、`DB_USER`、`DB_PASSWORD`、`DB_HOST`）が、復元先のデータベースと合っているか確認します。サーバーを引っ越した場合は書き換えが必要です。
- ドメインが変わった場合は、`wp_options` テーブルの `siteurl` と `home` を新しい URL に直します（phpMyAdmin で編集するか、`wp-config.php` に `define( 'WP_HOME', '…' ); define( 'WP_SITEURL', '…' );` を一時的に書きます）。
- サイトの表示、管理画面へのログイン、画像の表示を確認します。

### 注意

- **空のディレクトリは保存されません。** 復元後に存在しないディレクトリが原因でエラーが出るプラグインがあれば、同名の空ディレクトリを作ってください。
- 既定で除外されるもの: このプラグインの作業フォルダ、`wp-content/cache`、`wp-content/uploads/backup*`、`wp-content/updraft`、`wp-content/ai1wm-backups`、`.git`、`node_modules`。
- 除外パスの書き方: `/` を含まない名前（`.git`、`node_modules`、`cache*` など）は**どの階層でも**一致します。`/` を含むパス（`wp-content/cache` など）は、バックアップの起点フォルダから見た位置にだけ一致します。

## セキュリティ上の注意

- **トークンは全サイトで同じです。** 1サイトの管理者権限が奪われると、そのトークンで pCloud アカウント全体への書き込みと削除ができてしまいます（pCloud の API にはフォルダ単位の権限がありません）。**バックアップ専用の pCloud アカウントを作って使うことを強く推奨します。**
- トークンが漏れた疑いがあるときは、pCloud の My Apps で登録したアプリを削除してください。そのアプリから発行したトークンがすべて無効になります。その後、新しくアプリを登録して全サイトのトークンを入れ替えます。
- **データベースのダンプにはパスワードのハッシュなどが含まれます。** pCloud 上のバックアップフォルダを他人に共有しないでください。
- トークンは `AUTH_KEY` と `SECURE_AUTH_KEY` から作った鍵で暗号化して保存され、管理画面や通知メールには表示されません。
- 作業フォルダ（`wp-content/uploads/wp-pcloud-backup-<ランダム16文字>/`）には `.htaccess` と空の `index.php` を置いて外部から見えないようにしています。**nginx は `.htaccess` を読まない**ので、nginx のサーバーでは `wp-content/uploads/wp-pcloud-backup-*/` へのアクセスを、サーバー設定で自分で拒否してください。
- 外部 cron 用 URL の鍵が漏れた場合は、「接続」タブの「URL を再生成」で再発行し、cron の URL を更新してください。

## 自動更新とリリース手順

### 自動更新

プラグインは 12 時間ごとに、GitHub 上の `update.json` を確認します。新しいバージョンがあれば WordPress のプラグイン一覧に更新が表示され、設定の「自動更新」が ON（既定）なら自動で適用されます。ダウンロードした zip は、インストール前に `.sha256` ファイルと照合し、一致しなければ更新を中止して通知メールを送ります。バックアップの実行中は更新を見送ります。

### リリース手順（開発者向け）

準備:

1. GitHub に `wp-pcloud-backup` リポジトリを作り、`origin` として登録します。
2. 「リリース前に必ず `OWNER` を置き換える」の2か所を直してコミットします。
3. `gh auth login` を済ませます。

リリース:

```
bin/release.sh 0.1.1 notes.md
```

- 第1引数はバージョン（`X.Y.Z`）、第2引数は更新内容のメモファイルです（省略すると直前のコミット履歴から自動生成します）。
- `--dry-run` を付けると、ファイルの書き換えと zip の作成までを行い、コミット・タグ・push・GitHub リリースは行いません。
- 作業ツリーがクリーンでないとき、`gh` にログインしていないとき、`origin` が未設定または `OWNER` のままのときは、何も変更せずに中止します。
- スクリプトは `wp-pcloud-backup.php` の `Version:` と `WP_PCLOUD_BACKUP_VERSION`、`readme.txt` の `Stable tag`、`update.json` の `version` / `download_url` / `sha256_url` を書き換え、`build/wp-pcloud-backup.zip`（最上位フォルダ名は `wp-pcloud-backup/`）と `build/wp-pcloud-backup.zip.sha256` を作ってから、コミット、タグ、push、`gh release create` を行います。

## 開発

テストは Docker で実行します（ホストに PHP は不要です）。

```
bin/test.sh                      # PHP 7.4（既定）
PHP_VERSION=8.2 bin/test.sh      # PHP のバージョンを変える
bin/test.sh --filter PluginTest  # 一部だけ実行
```

実際の WordPress で動作を確認するには [wp-env](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/) を使います。

```
npx @wordpress/env start
```

翻訳テンプレート（`.pot`）の生成:

```
npx wp-env run cli wp i18n make-pot . languages/wp-pcloud-backup.pot --domain=wp-pcloud-backup
```

## アンインストール

プラグインを削除すると、このサイトの設定、実行履歴、一時データ、cron の予約、作業フォルダが削除されます。**pCloud 上のバックアップには一切触れません。** 不要なら pCloud 側で手動で削除してください。

## ライセンス

GPL-2.0-or-later
