# WP pCloud Backup

WordPress サイトのファイルとデータベースを、定期的に pCloud へバックアップするプラグインです。共用サーバー（heteml、さくらのレンタルサーバーなど）でも最後まで走り切るよう、処理を小さなステップに分けて少しずつ進めます。

## 目次

- [概要](#概要)
- [対応環境](#対応環境)
- [導入手順](#導入手順)
- [pCloud トークンの取得](#pcloud-トークンの取得)
- [`wp-config.php` の定数一覧](#wp-configphp-の定数一覧)
- [外部 cron の設定例](#外部-cron-の設定例)
- [停止の通知](#停止の通知)
- [**復元手順**](#復元手順)（バックアップから戻すとき）
- [セキュリティ上の注意](#セキュリティ上の注意)
- [自動更新とリリース手順](#自動更新とリリース手順)
- [開発](#開発)

## 概要

- 対象は「サイトの公開ルート以下のすべてのファイル」と「データベースの全テーブル」です。
- ファイルは ZIP64 形式で固めて 25MB（10 / 25 / 50 / 100MB から選択）ごとに分割し、データベースは gzip 圧縮した SQL として pCloud にアップロードします。
- 周期（毎日 / 3日おき / 毎週 / 2週間おき / 毎月）と保持世代数（1〜30、既定 3）を設定でき、古い世代は自動で削除されます。
- 失敗したときだけメールで知らせます（成功時の通知は設定で ON にできます）。バックアップが止まっているときも知らせます（[停止の通知](#停止の通知)）。
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
- 64 ビット版の PHP 7.4 以上（`zlib`、`curl`、`openssl` 拡張が必要。足りない場合は有効化時にエラーで止まり、不足している項目が表示されます）
- pCloud アカウント（米国リージョンは `api.pcloud.com`、EU リージョンは `eapi.pcloud.com`）
- 想定サーバー: heteml、さくらのレンタルサーバー（どちらも共用サーバーで動作）
- 約 40GB を超える大規模サイト（VPS など）はこのプラグインの対象外です。40GB は、外部 cron を 1 分ごとに呼び出せて、pCloud への上り速度が 1MB/s 程度以上ある場合の目安です（[処理の速さの目安](#処理の速さの目安)）。

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

#### 実際の WordPress での動作確認（wp-env、2026-10-25）

[wp-env](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/)（`@wordpress/env` 12.0.0、WordPress 7.1.3、PHP 7.4.33、Apache）上で、開発モード（`WP_PCLOUD_BACKUP_STORAGE_DIR` でローカルフォルダに保存）を使って次を確認しました。ユニットテストは PHP 7.4 と 8.2 の両方で通過しています。

| 確認項目 | 結果 |
|---|---|
| 外部 cron URL だけで最後まで完走（約 100MB のフィクスチャ、サイト全体 6,690 ファイル / 254MB、7 パート） | 確認済み（約 30 秒） |
| 管理画面の「今すぐバックアップ」（AJAX）で進捗バーが進み、完走する | 確認済み（約 35 秒） |
| 保存先に `manifest.json`、`database.sql.gz`、`files.zip.001…` が揃い、`.incomplete-` が残らない。各パートの sha256 が manifest と一致 | 確認済み |
| 復元リハーサル: パートを番号順に結合 → `unzip -t` → 展開して元の `uploads` と `diff -r` が一致 | 確認済み（差分なし） |
| 復元リハーサル: バックアップ前に開発環境へ目印の投稿（`WPPB-SENTINEL-<時刻>`）とオプション（`wppb_sentinel`）を作り、`database.sql.gz` を別の WordPress（wp-env の tests 環境）に `wp db import` すると、目印の投稿とオプションが現れ、`wp_options` の `siteurl` / `home` が開発環境の値（`http://localhost:8888`）になる | 確認済み |
| 中断・再開: 実行中に WordPress のコンテナを `docker kill`（ZIP 作成中とアップロード中の 2 回）→ 起動し直して呼び出しを続けると、続きから完走し、アーカイブも正常（約 3GB のフィクスチャ） | 確認済み（中断のたびに約 60 秒〔1 回の実行時間 × 3〕待ってから再開） |
| 外部 cron URL: 鍵が違う / 鍵なし → HTTP 403、正しい鍵 → HTTP 200 + JSON | 確認済み |
| 約 3GB のフィクスチャ（サイト全体 10,086 ファイル / 3.07GiB、120 パート）。WP-Cron を OFF にし、外部 cron URL だけで実行 | 確認済み（8 回の呼び出しで完走。1 回あたり約 22 秒・約 15 パート、合計約 2 分 40 秒。保存先が同じマシンのフォルダなので、ネットワークの待ち時間は含まない） |
| 保持世代数（3）を超えた古い世代の削除 | 確認済み |
| `WP_DEBUG_LOG` 有効時に、このプラグインによる notice / warning が出ない | 確認済み |
| pCloud への実際のアップロード（`CurlTransport`） | **未確認**（下の「開発」の「pCloud 実機での確認手順」を参照） |


## 導入手順

多数のサイトに入れるときの標準的な流れです。

1. 最初の1サイトで「pCloud トークンの取得」の手順を行い、アクセストークンを控えておきます。
2. 各サイトの `wp-config.php` に `WP_PCLOUD_BACKUP_ACCESS_TOKEN` と `WP_PCLOUD_BACKUP_NOTIFY_EMAIL` を書きます（SSH が使える heteml ではこれが最速です。さくらは FTP またはファイルマネージャで編集します。管理画面の「接続」タブから貼り付けても構いません）。
3. プラグインの zip をアップロードして有効化します（WP-CLI が使えるなら `wp plugin install <zip の URL> --activate`）。以後の更新は自動です。
4. 周期や世代数を変えたいサイトだけ、管理画面（設定 → pCloud Backup）で変更します。
5. 外部 cron を使うサイト（さくらなど）は「WP-Cron で自動実行する」を OFF にして、外部 cron 用 URL をコントロールパネルに 1 分ごと（選べる最短の間隔）で登録します（後述）。
6. 「今すぐバックアップ」で1回手動実行し、pCloud にファイルが届くこと、最後まで完走することを確認します。
7. まず 2〜3 サイトで数週間運用し、安定したら残りのサイトへ展開します。

### 配布元リポジトリの URL

配布元は GitHub リポジトリ `yotainu/wp-backup`（リポジトリ名はプラグインのスラッグ `wp-pcloud-backup` と異なります）。別のアカウントやリポジトリで配布する場合は、次の2か所の URL を書き換えてください。残っていると、自動更新が別の URL を見に行きます。

- `update.json` の `download_url`、`sha256_url`、`homepage`
- `wp-pcloud-backup.php` の `WP_PCLOUD_BACKUP_UPDATE_URL` の既定値（`https://raw.githubusercontent.com/yotainu/wp-backup/main/update.json`）

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
| `WP_PCLOUD_BACKUP_UPDATE_URL` | 自動更新の `update.json` の URL。既定は `https://raw.githubusercontent.com/yotainu/wp-backup/main/update.json` |
| `WP_PCLOUD_BACKUP_GITHUB_TOKEN` | GitHub リポジトリを非公開にした場合の読み取りトークン |
| `WP_PCLOUD_BACKUP_LARGE_FILE_MB` | この大きさ（MB）以上のファイルを、完了メールの警告に載せます。既定 500。`0` で警告しません（[注意](#注意)の「大きなファイル」） |

## 外部 cron の設定例

WP-Cron はサイトにアクセスがないと動きません。アクセスが少ないサイトや、確実に定時で動かしたいサイトでは、サーバーの cron から URL を呼びます。

1. 管理画面の「設定」タブで「WP-Cron で自動実行する」を OFF にします。
2. 「接続」タブの「外部 cron の URL」をコピーします。形式は次のとおりです（`key` は 64 文字の英数字です）。

   ```
   https://example.com/?wp-pcloud-backup=run&key=<64桁の16進文字列>
   ```

   この URL でできるのは「バックアップの処理を1回進める」ことだけです。データの取得や設定変更はできません。鍵は「接続」タブの「URL を再生成」で再発行できます。
3. サーバーの cron に **1 分ごと**（サーバーで選べる最短の間隔）で登録します。バックアップが動いていないときの呼び出しは、予定時刻かどうかを確かめるだけなのですぐ終わります。コマンドの例:

   ```
   curl -fsS 'https://example.com/?wp-pcloud-backup=run&key=<64桁の16進文字列>' > /dev/null
   ```

   `curl` が使えないときは `wget -q -O /dev/null 'URL'` でも構いません。URL は必ずシングルクォートで囲んでください（`&` がシェルに解釈されるのを防ぐため）。

### heteml

コントロールパネル → cron 設定 から、実行間隔を 1 分ごと（選べない場合は選べる最短の間隔）にして、上の `curl` または `wget` のコマンドを登録します。PHP ファイルを実行する方式も選べます。

### さくらのレンタルサーバー

サーバーコントロールパネル → スクリプト設定 → CRON 設定 から、1 分ごと（選べない場合は選べる最短の間隔）のスケジュールでコマンド（`curl` / `wget`）または PHP ファイルを登録します。

### 処理の速さの目安

1 回の呼び出し（WP-Cron の 1 回、または外部 cron URL の 1 回）では、設定の「1 回の実行時間」（既定 20 秒）のあいだ、ZIP の作成と pCloud へのアップロードを交互に続けます。アップロード中のパートは時間切れでも途中で止めないので、1 回の呼び出しでは**少なくとも 1 パート（既定 25MB）を必ず送り**、所要時間は最大で「実行時間 + パート 1 つの送信時間」になります（上り 1MB/s なら約 20 秒 + 25 秒）。Web サーバーの応答タイムアウト（60〜120 秒程度）に収まるよう、実行時間は 20〜30 秒程度にしてください。

1 回で送れる量はおおむね「実行時間 × 上り速度」で、上りが遅くても 1 パートは送ります。バックアップは開始から 48 時間で打ち切られる（失敗扱い）ので、終わるサイズの上限の目安は次のとおりです（パート 25MB、実行時間 20 秒）。

| 呼び出し間隔 | 上り 1MB/s 程度（1 回 ≒ 1 パート） | 上り 5MB/s 程度（1 回 ≒ 4〜5 パート） |
|---|---|---|
| 1 分ごと | 約 1.5GB/時 → 40GB で約 27 時間、48 時間で約 70GB | 約 7GB/時 → 40GB で約 6 時間 |
| 5 分ごと | 約 0.3GB/時 → 48 時間で約 14GB | 約 1.5GB/時 → 48 時間で約 70GB |

5 分ごとにしか呼び出せないサーバーで上りが遅い場合、サイトの大きさの上限は約 14GB です。それより大きいサイトは、分割サイズを大きくする（50MB なら上限も約 2 倍）か、1 分ごとに呼び出せる方法（アクセスの多いサイトでの WP-Cron など）を使ってください。

参考: wp-env で保存先を同じマシンのフォルダにした計測（ネットワークの待ち時間なし）では、約 3GB・120 パートのサイトが 8 回の呼び出し（1 回あたり約 22 秒）で完走しました。

## 停止の通知

最後に成功したバックアップが**周期の 2 倍以上前**になると（まだ一度も成功していないサイトでは、有効化した時刻から数えます）、「停止中」のメールを送ります。メールは 24 時間に 1 通までです。

この確認は、WP-Cron の実行時、外部 cron 用 URL の呼び出し時、管理画面を開いたとき（1 時間に 1 回まで）に行われます。どれも起きないサイト（WP-Cron を OFF にして外部 cron も止まっている、管理画面も開かない）では、メールは届きません。

メールの「考えられる原因」には、当てはまるものが「 / 」でつながって表示されます。

| 表示 | 意味と対処 |
|---|---|
| トークンを復号できません（AUTH_KEY/SECURE_AUTH_KEY が変更された可能性） | `wp-config.php` の鍵が変わりました。「接続」タブでトークンを入れ直してください（すぐに通知されます。周期の 2 倍を待ちません） |
| pCloud に接続されていません | トークンが未設定です。「接続」タブで接続してください |
| DISABLE_WP_CRON が定義されているため… | 「WP-Cron で自動実行する」が ON なのに、`wp-config.php` で WP-Cron が止められています。外部 cron を設定してください |
| …外部 cron 用 URL が一度も呼ばれていません／… %s 以降呼ばれていません | 「WP-Cron で自動実行する」が OFF なのに、外部 cron 用 URL が呼ばれていない（または 1 日以上呼ばれていない）状態です。サーバーの cron 設定と URL を確認してください |
| バックアップが周期の2倍以上成功していません | 上のどれにも当てはまらない場合です。管理画面の実行履歴とエラーを確認してください |

実行が時間制限やメモリ不足で強制終了した場合は、次の呼び出しで「前回の実行が途中で終了しました（…処理中: ファイル名）」として記録されます。強制終了の前に少しでも処理が進んでいれば数え直しになり、何も進まないまま 5 回続くと、そのバックアップは失敗扱いになり、失敗のメールが届きます。

## 復元手順

**これはバックアップが必要になったとき（サイトが壊れた、消えた）に使う手順です。落ち着いて、上から順に行ってください。** 作業は自分のパソコンで行い、最後にサーバーへアップロードします。

### 0. 準備

- 復元に使う日付フォルダ（例: `2026-10-24_030000`）を決めます。`.incomplete-` で始まるフォルダは未完了なので使わないでください。
- pCloud のウェブ画面かアプリで、そのフォルダの中身をすべて（`manifest.json`、`database.sql.gz`、`files.zip.001`、`files.zip.002` …）パソコンの空のフォルダにダウンロードします。
- `manifest.json` をテキストエディタで開き、`backup_root`（ファイルを戻す先のサーバー上のパス）を控えておきます。

### 1. ファイルを復元する

1. 分割された ZIP を1つに結合します。ダウンロードしたフォルダでターミナルを開いて実行します。**パートは必ず番号の順（`.001`、`.002` …）に並べて結合してください。** 順番が違うと ZIP が壊れます。`files.zip` という名前のファイルが先にあると失敗するので、あれば削除またはリネームしてから行います。

   - macOS / Linux（番号順を保証する、推奨の方法）:

     ```
     ls files.zip.* | sort -t. -k3,3n | xargs cat > files.zip
     ```

     パートが 1000 個以上あると、`files.zip.1000` のように桁が増えます。すると単純な `cat files.zip.*` は `.1000` を `.101` より前に並べてしまい、壊れた ZIP になります。1000 個以上になるのは、パートサイズが 10MB のサイトで合計 10GB 以上、25MB のサイトで 25GB 以上のときです。上のコマンドは番号を数値として並べるので、どちらの場合でも安全です。パートが 999 個以下のときに限り、次の簡単な書き方でも同じ結果になります。

     ```
     cat files.zip.* > files.zip
     ```

   - Windows（PowerShell。ダウンロードしたフォルダで開く）:

     ```
     $out=[IO.File]::Create("$PWD\files.zip"); Get-ChildItem files.zip.[0-9]* | Sort-Object {[int]($_.Extension.TrimStart('.'))} | ForEach-Object { $i=[IO.File]::OpenRead($_.FullName); $i.CopyTo($out); $i.Close() }; $out.Close()
     ```

     拡張子の数字を数値として並べて、1つの `files.zip` に書き出します。事前に `files.zip` が存在していると上書きされるので、別のファイルがあれば退避してください。（`copy /b files.zip.* files.zip` は並び順が保証されず、出力ファイル自身を巻き込むことがあるため使わないでください。）

2. （任意）ダウンロードが壊れていないか確認します。`manifest.json` の `parts` に各パートの `sha256` が書いてあります。

   - macOS: `shasum -a 256 files.zip.001`
   - Linux: `sha256sum files.zip.001`
   - Windows（PowerShell）: `Get-FileHash -Algorithm SHA256 files.zip.001`

   出力が、`parts` の `files.zip.001` の `sha256` と一致すれば正常です（他のパートも同様に確認できます）。

3. 展開します。

   - macOS / Linux:

     ```
     unzip files.zip -d restored
     ```

     macOS では `files.zip` を Finder でダブルクリックしても展開できます。

   - Windows 10 以降（PowerShell またはコマンドプロンプト）:

     ```
     mkdir restored
     tar -xf files.zip -C restored
     ```

     `tar`（bsdtar）は Windows 10 以降に標準で入っています。代わりに [7-Zip](https://7-zip.org/) で `files.zip` を右クリック → 7-Zip → 展開、でも構いません。エクスプローラーのダブルクリックは ZIP64 形式で開けないことがあるので、この方法は避けてください。

4. 展開してできた `restored` フォルダの**中身**を、サーバーの `manifest.json` の `backup_root` に記録されているフォルダへアップロードします（FTP、ファイルマネージャ、`rsync` など）。ZIP の最上位は `backup_root` にそのまま対応しているので、`restored` フォルダ自体ではなく、その中身を置いてください。`manifest.json` には `abspath`（バックアップ時の WordPress の設置パス）も記録されています。WordPress 本体をサブフォルダに置いているサイトでは、`backup_root` がその一つ上のフォルダになります。

### 2. データベースを復元する

1. `database.sql.gz` を展開します。

   ```
   gunzip database.sql.gz
   ```

   `database.sql` ができます（元のファイルを残したいときは `gunzip -k`）。

   Windows では [7-Zip](https://7-zip.org/) で `database.sql.gz` を右クリック → 7-Zip → 展開、とすると `database.sql` ができます（`tar` は単独の `.gz` ファイルをうまく展開できないので、7-Zip を使ってください）。phpMyAdmin の「インポート」は `.gz` のまま受け付けることもありますが、展開してから流し込むほうが確実です。

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
- **大きなファイル**: 1 つのファイルは 1 回の実行の中で ZIP に書き込まれます（途中で分けて次の回に続けることはしません）。目安として、1 回の実行で「実行時間 × ディスクの読み込み速度」を超える大きさのファイルは、サーバーの時間制限で強制終了されるおそれがあります。同じファイルで何も進まないまま 5 回続けて強制終了すると、そのバックアップは失敗になります。500MB 以上のファイル（`WP_PCLOUD_BACKUP_LARGE_FILE_MB` で変更可）は完了メールの警告に名前が載るので、不要なら除外パスに加えてください。また、1 つのファイルの書き込み中はパートを送れないため、作業フォルダにはそのファイルの大きさ程度の分割パートが一時的にたまります。サーバーの空き容量に注意してください。
- **データベース**: 各テーブルは主キーの順に読み進めます（主キーのないテーブルだけは件数の位置で読みます）。1 つの `INSERT` 文は最大 1MiB ほどで区切り、`text`／`blob`／`json` の列があるテーブルは 1 回に 200 行ずつ読みます。復元先の MySQL の `max_allowed_packet` は 1MiB の既定値で足ります（1MiB を超える 1 行そのものは、そのまま 1 文になります）。
- 既定で除外されるもの: このプラグインの作業フォルダ、`wp-content/cache`、`wp-content/uploads/backup*`、`wp-content/updraft`、`wp-content/ai1wm-backups`、`.git`、`node_modules`。
- 除外パスの書き方: `/` を含まない名前（`.git`、`node_modules`、`cache*` など）は**どの階層でも**一致します。`/` を含むパス（`wp-content/cache` など）は、バックアップの起点フォルダから見た位置にだけ一致します。

## セキュリティ上の注意

- **トークンは全サイトで同じです。** 1サイトの管理者権限が奪われると、そのトークンで pCloud アカウント全体への書き込みと削除ができてしまいます（pCloud の API にはフォルダ単位の権限がありません）。**バックアップ専用の pCloud アカウントを作って使うことを強く推奨します。**
- トークンが漏れた疑いがあるときは、pCloud の My Apps で登録したアプリを削除してください。そのアプリから発行したトークンがすべて無効になります。その後、新しくアプリを登録して全サイトのトークンを入れ替えます。
- **データベースのダンプにはパスワードのハッシュなどが含まれます。** pCloud 上のバックアップフォルダを他人に共有しないでください。
- トークンは `AUTH_KEY` と `SECURE_AUTH_KEY` から作った鍵で暗号化して保存され、管理画面や通知メールには表示されません。
- **`AUTH_KEY` / `SECURE_AUTH_KEY` を変更（ローテーション）すると、保存済みのトークンを復号できなくなります。** 鍵を変えたサイトでは、管理画面の「接続」タブでトークンを入れ直してください（`wp-config.php` の定数でトークンを設定しているサイトは影響を受けません）。
- 作業フォルダ（`wp-content/uploads/wp-pcloud-backup-<ランダム16文字>/`）には `.htaccess` と空の `index.php` を置いて外部から見えないようにしています。**nginx は `.htaccess` を読まない**ので、nginx のサーバーでは `wp-content/uploads/wp-pcloud-backup-*/` へのアクセスを、サーバー設定で自分で拒否してください。
- 外部 cron 用 URL の鍵が漏れた場合は、「接続」タブの「URL を再生成」で再発行し、cron の URL を更新してください。

## 自動更新とリリース手順

### 自動更新

プラグインは 12 時間ごとに、GitHub 上の `update.json` を確認します。新しいバージョンがあれば WordPress のプラグイン一覧に更新が表示され、設定の「自動更新」が ON（既定）なら自動で適用されます。ダウンロードした zip は、インストール前に `.sha256` ファイルと照合し、一致しなければ更新を中止して通知メールを送ります。バックアップの実行中は更新を見送ります。

### リリース手順（開発者向け）

準備:

1. GitHub に `wp-pcloud-backup` リポジトリを作り、`origin` として登録します。
2. 「配布元リポジトリの URL」の2か所が自分のリポジトリを指しているか確認します（既定は `yotainu/wp-backup`）。
3. `gh auth login` を済ませます。

リリース:

```
bin/release.sh 0.1.1 ../notes-0.1.1.md
```

- 第1引数はバージョン（`X.Y.Z`）、第2引数は更新内容のメモファイルです（省略すると直前のタグからのコミット履歴で自動生成します）。**メモファイルはリポジトリの外に置く（または `.gitignore` に入れる）**ようにしてください。リポジトリ内の未追跡ファイルがあると「作業ツリーがクリーンでない」として中止されます。
- `--dry-run` を付けると、ファイルの書き換えと zip の作成までを行い、コミット・タグ・push・GitHub リリースは行いません。確認後は `git checkout -- . && rm -r build` で元に戻します。
- `main` ブランチ以外からは中止します（意図して別ブランチから出すときだけ `--allow-branch` を付けます）。
- 次のときは、何も変更せずに中止します: 作業ツリーがクリーンでない / バージョンの形式が `X.Y.Z` でない / タグがローカルまたは GitHub にすでにある / `gh` にログインしていない / `origin` が未設定または GitHub の URL でない / `OWNER` プレースホルダが残っている（`--dry-run` のときは最後の2つを許容します）。
- スクリプトは `wp-pcloud-backup.php` の `Version:` と `WP_PCLOUD_BACKUP_VERSION`、`readme.txt` の `Stable tag`、`update.json` の `version` / `download_url` / `sha256_url` / `changelog` を書き換え、`build/wp-pcloud-backup.zip`（最上位フォルダ名は `wp-pcloud-backup/`）と `build/wp-pcloud-backup.zip.sha256` を作ります。

公開は次の順に行います。存在しない zip を指す `update.json` を先に公開しないため、`update.json` を含むブランチの push を最後にしています。

1. `git commit`（`chore: release vX.Y.Z`）
2. `git tag -a vX.Y.Z`
3. `git push origin refs/tags/vX.Y.Z`（タグだけを push）
4. `gh release create vX.Y.Z …`（zip と sha256 を添付）
5. `git push origin HEAD`（ここで初めて `update.json` が公開され、各サイトの自動更新が始まる）

途中で失敗したときは、スクリプトが手順ごとの復旧コマンドを表示します。目安は次のとおりです。

| 失敗した手順 | 状態 | 復旧 |
|---|---|---|
| 2 または 3 | まだ何も公開されていない | `git tag -d vX.Y.Z && git reset --soft HEAD~1` で戻し、原因を直して再実行 |
| 4 | タグだけ push 済み | `gh release create vX.Y.Z build/wp-pcloud-backup.zip build/wp-pcloud-backup.zip.sha256` をやり直す。やめるなら `git push origin :refs/tags/vX.Y.Z && git tag -d vX.Y.Z && git reset --soft HEAD~1` |
| 5 | リリースは存在する | `git push origin HEAD` をやり直す |

## 開発

テストは Docker で実行します（ホストに PHP は不要です）。

```
bin/test.sh                      # PHP 7.4（既定）
PHP_VERSION=8.2 bin/test.sh      # PHP のバージョンを変える
bin/test.sh --filter PluginTest  # 一部だけ実行
```

実際の WordPress で動作を確認するには [wp-env](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/) を使います（Docker と Node.js が必要です）。

```
npx @wordpress/env@12.0.0 start     # http://localhost:8888 （管理者 admin / password）
npx @wordpress/env@12.0.0 stop
```

`.wp-env.json` は開発モード（`WP_PCLOUD_BACKUP_STORAGE_DIR`）を有効にしてあり、バックアップは pCloud ではなくコンテナ内の `wp-content/wppb-remote/WPBackup/localhost/` に保存されます（開発モードでは保存先フォルダ自体はバックアップ対象から自動で除外されます）。`wp-content` はホストの `~/.wp-env/<ハッシュ>/WordPress/wp-content/` にあるので、保存されたパートはホスト側から直接読めます。`WP_DEBUG_LOG` も有効で、notice などは `wp-content/debug.log` に出ます。

以下のコマンドの `npx @wordpress/env@12.0.0` は、インストール済みなら `wp-env` と読み替えてください（`npx wp-env` は別パッケージなので使えません）。

テスト用のファイルを作る（`wp-content/uploads/fixture/` を、合計約 N MB のランダムなファイル〔1KB〜8MB、日本語名・空白入りの名前・入れ子のフォルダを含む〕で作り直します）:

```
bin/make-fixture-site.sh 100
```

ブラウザを使わずにバックアップを最後まで進める:

```
npx @wordpress/env@12.0.0 run cli wp eval '\WpPcloudBackup\Plugin::instance()->runner()->startJob("manual");'
URL=$(npx @wordpress/env@12.0.0 run cli wp eval 'echo \WpPcloudBackup\Plugin::instance()->trigger()->cronUrl();' 2>/dev/null | tail -1)
while curl -fsS "$URL" | grep -o '"running":true,"step":"[a-z]*","progress":[0-9.]*'; do sleep 2; done
npx @wordpress/env@12.0.0 run cli wp option get wp_pcloud_backup_history --format=json
```

wp-env でも WP-Cron は動きます（サイトへのアクセスがあると、コンテナ内から `wp-cron.php` が呼ばれます）。アクセスがなければ動かないので、「接続」タブの「次回の定期チェック」が過去のままになることがあります。呼び出し回数などを正確に測るときは、「設定」タブで「WP-Cron で自動実行する」を OFF にしてから外部 cron URL だけで進めてください（WP-Cron と外部 cron URL が同時に来ても、ロックで片方だけが処理を進めます）。手で 1 回進めるには `wp cron event run wp_pcloud_backup_tick` を実行します。

復元リハーサル（ファイル）。コンテナの `unzip`（BusyBox）は ZIP64 を読めないので、ホストの `unzip` を使います:

```
cd ~/.wp-env/<ハッシュ>/WordPress/wp-content/wppb-remote/WPBackup/localhost/<日付>/
ls files.zip.* | sort -t. -k3,3n | xargs cat > /tmp/files.zip
unzip -tq /tmp/files.zip && unzip -q /tmp/files.zip -d /tmp/restored
diff -r /tmp/restored/wp-content/uploads/fixture ~/.wp-env/<ハッシュ>/WordPress/wp-content/uploads/fixture && echo FILES_OK
```

復元リハーサル（データベース）。tests 環境（http://localhost:8889）は同じテーブル構成の新しい WordPress なので、テーブル数を比べるだけでは復元できたことの証明になりません。バックアップの**前に**開発環境へ目印を作り、流し込んだ tests 環境に目印が現れることを確かめます。終わったら `npx @wordpress/env@12.0.0 clean tests` で tests 環境を初期化します。

```
# 1. バックアップ前に、開発環境へ目印を作る
SENT="WPPB-SENTINEL-$(date +%s)"
npx @wordpress/env@12.0.0 run cli wp post create --post_title="$SENT" --post_status=publish
npx @wordpress/env@12.0.0 run cli wp option update wppb_sentinel "$SENT-option"

# 2. バックアップを完走させたあと、その世代の database.sql.gz を tests 環境に流し込む
#    （このリポジトリの wp-env プロジェクト名で絞り込み、別の wp-env の tests-cli を選ばないようにする）
PROJECT=$(basename "$(npx @wordpress/env@12.0.0 status --json 2>/dev/null | python3 -c 'import json,sys; print(json.load(sys.stdin)["installPath"])')")
docker cp database.sql.gz "$(docker ps -q --filter label=com.docker.compose.project=$PROJECT --filter label=com.docker.compose.service=tests-cli)":/tmp/database.sql.gz
npx @wordpress/env@12.0.0 run tests-cli bash -c 'gunzip -c /tmp/database.sql.gz | wp db import -'

# 3. 目印の投稿とオプションがあり、siteurl / home が開発環境の値（http://localhost:8888）であること
npx @wordpress/env@12.0.0 run tests-cli wp post list --title="$SENT" --field=post_title
npx @wordpress/env@12.0.0 run tests-cli wp option get wppb_sentinel
npx @wordpress/env@12.0.0 run tests-cli wp db query "SELECT option_name, option_value FROM wp_options WHERE option_name IN ('siteurl','home')"
```

`siteurl` は `wp option get` ではなく SQL で読みます。tests 環境の `wp-config.php` が `WP_SITEURL` / `WP_HOME` を `http://localhost:8889` に固定しているので、`wp option get siteurl` はデータベースの中身にかかわらず 8889 を返します。

### pCloud 実機での確認手順

本物の pCloud への送信（`CurlTransport` と `PcloudClient`）は、トークンがないと確認できません。次の手順で行います。

1. リポジトリ直下に `.wp-env.override.json` を作ります（`.gitignore` 済みなので、トークンがコミットされることはありません）。`config` は `.wp-env.json` とマージされ、定数を消すことはできない（`null` はエラーになる）ので、`WP_PCLOUD_BACKUP_STORAGE_DIR` を空文字にして開発モードを外し、本物のトークンを入れます。空文字のときは pCloud に保存します。

   ```json
   {
     "config": {
       "WP_PCLOUD_BACKUP_STORAGE_DIR": "",
       "WP_PCLOUD_BACKUP_ACCESS_TOKEN": "ここに本物のアクセストークン"
     }
   }
   ```

   EU リージョンのアカウントは `"WP_PCLOUD_BACKUP_API_HOST": "eapi.pcloud.com"` も加えます。

2. `npx @wordpress/env@12.0.0 start --update` で設定を反映し、`npx @wordpress/env@12.0.0 run cli wp eval 'echo get_class(\WpPcloudBackup\Plugin::instance()->storage());'` が `WpPcloudBackup\Storage\PcloudClient` を表示することを確認します（開発モードのときは `LocalStorage`）。
3. 管理画面の「接続」タブが「接続済み」になり、「ローカルフォルダ（開発モード）」の表記が消えていることを確認します（定数で設定したトークンはこの画面では検証されないので、トークンの誤りは次のバックアップで初めてわかります）。
4. `bin/make-fixture-site.sh 100` のあと、「状態」タブの「今すぐバックアップ」を押して完走させます。
5. pCloud のウェブ画面で `/WPBackup/localhost/<日付>/` に `manifest.json`、`database.sql.gz`、`files.zip.001…` があり、`.incomplete-` が残っていないことを確認します。
6. `files.zip.001` と `manifest.json` をダウンロードし、`files.zip.001` の SHA-1 が manifest の値と一致することを確認します。manifest の `parts` の各要素には `name`、`size`、`sha1`、`sha256` があります（pCloud 側の照合は SHA-1、復元手順の確認は SHA-256 を使います）。

   ```
   shasum files.zip.001                                                        # SHA-1
   jq -r '.parts[] | select(.name=="files.zip.001") | .sha1' manifest.json     # jq がある場合
   grep -A2 '"files.zip.001"' manifest.json                                    # jq がない場合（"sha1" の行を見る）
   ```
7. 終わったら `.wp-env.override.json` を削除し、`npx @wordpress/env@12.0.0 start --update` で開発モードに戻します。

翻訳テンプレート（`.pot`）の生成（コンテナ内のプラグインのフォルダ名はリポジトリのフォルダ名と同じです。`wp plugin list` で確認できます）:

```
npx @wordpress/env@12.0.0 run cli wp i18n make-pot \
  /var/www/html/wp-content/plugins/<フォルダ名> \
  /var/www/html/wp-content/plugins/<フォルダ名>/languages/wp-pcloud-backup.pot \
  --domain=wp-pcloud-backup --slug=wp-pcloud-backup --exclude=vendor,tests,docs,build,node_modules,bin
```

## アンインストール

プラグインを削除すると、このサイトの設定、実行履歴、一時データ、cron の予約、作業フォルダが削除されます。**pCloud 上のバックアップには一切触れません。** 不要なら pCloud 側で手動で削除してください。

## ライセンス

GPL-2.0-or-later
