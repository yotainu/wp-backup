# WP pCloud Backup 実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 共用サーバー上の WordPress の全ファイルと DB を、分割 ZIP + SQL ダンプとして周期的に pCloud へ保存し、失敗をメールで知らせ、GitHub Releases から自動更新するプラグインを作る。

**Architecture:** 1回のバックアップ「ジョブ」を、作業フォルダの `state.json` に進捗を持つ「ラン」（既定20秒）の連続として進めるステップマシン（`JobRunner`）が中核。ファイル走査・ZIP 書き出し・DB ダンプ・アップロードはそれぞれ「中断・再開可能」なクラスとして独立させ、ストレージは `StorageInterface` で抽象化して pCloud 実装とローカルフォルダ実装（テスト・開発用）を差し替えられるようにする。管理画面・WP-Cron・外部 cron URL・AJAX はすべて同じ `JobRunner::tick()` を呼ぶだけの薄い入口。

**Tech Stack:** PHP 7.4+（拡張: zlib, curl, openssl）、WordPress 6.0+、外部ランタイム依存なし。開発: PHPUnit 9 + Brain\Monkey 2（WP 関数のモック）、Docker（`php:7.4-cli` / `composer:2` イメージでテスト実行）、`@wordpress/env`（統合確認）、`gh` CLI（リリース）。

**Spec:** `docs/superpowers/specs/2026-10-24-wpbackup-design.md`

**対象外:** 設計書 3.2 / 14 章の VPS 用 rclone スクリプトは本計画に含めない（プラグイン完成後に別計画）。

## Global Constraints

- PHP 7.4 以上で動くこと（`php:7.4-cli` でテストが通る）。型宣言は 7.4 で使えるものだけ（union 型・enum・readonly 不可）
- WordPress 6.0 以上。マルチサイト非対応
- ランタイムの外部ライブラリ禁止。Composer は `require-dev` のみ。配布 zip に `vendor/` を含めない
- シェルコマンド（`exec`/`shell_exec`/`system`/`proc_open`）禁止。`mysqldump`・`zip` コマンドに依存しない
- プラグインスラッグ `wp-pcloud-backup`、メインファイル `wp-pcloud-backup.php`、PHP 名前空間 `WpPcloudBackup\`、オプション名・フック名・定数の接頭辞は `wp_pcloud_backup_` / `WP_PCLOUD_BACKUP_`
- 設定定数名（設計書 6 章）: `WP_PCLOUD_BACKUP_ACCESS_TOKEN`, `WP_PCLOUD_BACKUP_NOTIFY_EMAIL`, `WP_PCLOUD_BACKUP_ROOT_FOLDER`, `WP_PCLOUD_BACKUP_SCHEDULE`, `WP_PCLOUD_BACKUP_KEEP`
- pCloud API ホストは `api.pcloud.com`（定数 `WP_PCLOUD_BACKUP_API_HOST` で上書き可）。認証は `Authorization: Bearer` ヘッダ。フォルダ・ファイルは `folderid`/`fileid` で指定し `path` パラメータは使わない。使う API は `oauth2_token`, `userinfo`, `uploadfile`, `createfolderifnotexists`, `listfolder`, `renamefolder`, `deletefolderrecursive`, `checksumfile` のみ
- 既定値（設計書 6 章）: pCloud ルート `/WPBackup`、サイトフォルダ = ホスト名、周期 `weekly`、開始時刻 `03:00`、世代 3、通知先 = 管理者メール、成功通知 OFF、分割 25MB（選択肢 10/25/50/100）、処理時間上限 20 秒、WP-Cron ON、自動更新 ON
- 既定除外: 作業フォルダ、`wp-content/cache`、`wp-content/uploads/backup*`、`wp-content/updraft`、`wp-content/ai1wm-backups`、`.git`、`node_modules`
- pCloud 上の命名: 完了フォルダ `YYYY-MM-DD_HHMMSS`（サイトのタイムゾーン）、作業中フォルダ `.incomplete-YYYY-MM-DD_HHMMSS`、ファイル `manifest.json`, `database.sql.gz`, `files.zip.001`…（3桁ゼロ埋め）
- メール件名（設計書 10 章）: `[WP pCloud Backup] 失敗: {host}` / `停止中: {host}` / `完了(警告あり): {host}` / `完了: {host}` / `更新失敗: {host}`
- トークン・鍵・Client Secret をログ・メール・画面に出さない（「他のサイトにも使う」での一度限りの表示を除く）
- UI 文言は日本語を既定とし、すべて `__()`/`esc_html__()` 等でテキストドメイン `wp-pcloud-backup` を通す
- 各タスクは「テストを書く → 失敗を確認 → 実装 → 成功を確認 → コミット」。コミットメッセージは英語の Conventional Commits 形式で、末尾に `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>` を付ける
- テスト実行コマンドは常に `bin/test.sh`（中で Docker を使う）。ホストに PHP はない

## Review Focus

仕様が暗黙に要求するが、どのタスクのテストも素では触らない入力。各行のテストを担当タスクに追加済み。

1. **日本語・スペース・長いパスを含むファイル名**が ZIP に正しく入り、`unzip` と Finder で読める（UTF-8 フラグ）→ Task 8 のテストで検証
2. **走査後に変化・消滅したファイル**（一覧のサイズと実体が違う、読めない）でジョブが止まらず、警告として記録される → Task 8（実サイズで書く）と Task 14（警告継続）
3. **主キーのないテーブル、空テーブル、BLOB・NULL・絵文字・バックスラッシュを含む行**が SQL として正しく再現できる → Task 9
4. **pCloud が HTTP 200 で `result != 0` を返す場合**（容量超過 2008、トークン無効 2000）と**ネットワーク断**の区別。前者は即失敗、後者は再試行 → Task 10 と Task 14
5. **月末起点の「毎月」と、サイトのタイムゾーンでの日付跨ぎ**で次回予定がずれない（1/31 + 1ヶ月 = 2/28、JST 03:00 は UTC 前日 18:00）→ Task 12

---

## ファイル構成

リポジトリのルート = プラグインフォルダ（配布 zip の中身は `wp-pcloud-backup/` 直下にこの構成が入る）。

```
wp-pcloud-backup.php             プラグインヘッダ、定数、オートローダー、Plugin::boot()
uninstall.php                    オプション・作業フォルダ・cron イベントの削除
update.json                      自動更新のマニフェスト（リリース時に bin/release.sh が更新）
readme.txt                       WordPress 形式の readme
README.md                        日本語: 導入手順、復元手順、セキュリティ上の注意
includes/
  Plugin.php                     フック登録と各サービスの組み立て（DI の手組み）
  Settings.php                   設定の読み書き・既定値・定数上書き・サニタイズ
  Crypto.php                     AES-256-GCM 暗号化（トークン保存用）
  BackupRoot.php                 起点フォルダの自動検出と検証（設計書 5.3）
  WorkDir.php                    作業フォルダ（ランダム名、.htaccess、index.php）
  History.php                    実行履歴（wp_options、最新20件）
  Notifier.php                   通知メールの件名・本文と送信
  Scheduler.php                  次回予定の計算、オフセット、停止検知、WP-Cron 登録
  Trigger.php                    外部 cron URL・AJAX・WP-Cron の入口
  Manifest.php                   manifest.json の生成
  Retention.php                  世代の列挙と削除
  Updater.php                    GitHub Releases からの更新チェック・検証・自動適用
  Job/JobState.php               state.json の読み書き（原子的）とロック
  Job/JobRunner.php              ステップマシン本体
  Scan/FileScanner.php           再帰走査 → filelist.jsonl（再開可能）
  Zip/PartWriter.php             バイト分割された出力先（files.zip.NNN）
  Zip/ZipWriter.php              ストリーミング ZIP64 ライタ（PartWriter に書く）
  Db/DbDumper.php                再開可能な SQL ダンプと gzip 圧縮
  Storage/StorageInterface.php   フォルダ作成・一覧・アップロード・改名・削除・チェックサム
  Storage/StorageException.php   エラーコードと retryable フラグ
  Storage/HttpTransportInterface.php  HTTP 1リクエストの抽象（テストで差し替え）
  Storage/CurlTransport.php      curl 拡張による実装（CURLFile ストリーミング）
  Storage/PcloudClient.php       pCloud API 実装（StorageInterface + oauth2_token + userinfo）
  Storage/LocalStorage.php       ローカルフォルダ実装（テスト・開発用）
  Admin/AdminPage.php            設定ページ（3タブ）、フォーム処理、診断
  Admin/views/tab-status.php
  Admin/views/tab-settings.php
  Admin/views/tab-connection.php
  Admin/assets/admin.js          「今すぐバックアップ」の進捗ポーリング、コピー
  Admin/assets/admin.css
languages/wp-pcloud-backup.pot
tests/
  bootstrap.php                  Composer autoload + Brain\Monkey セットアップ
  Support/FakeTransport.php      リクエスト記録と応答スクリプト
  Support/TempDir.php            一時ディレクトリの生成と削除
  Unit/*Test.php
bin/test.sh                      Docker で composer install と phpunit
bin/release.sh                   バージョン更新・zip・sha256・タグ・gh release
bin/make-fixture-site.sh         wp-env 内にダミーファイルを生成
.wp-env.json
composer.json / phpunit.xml.dist / .gitignore / .distignore
```

オートローダー: `WpPcloudBackup\Zip\ZipWriter` → `includes/Zip/ZipWriter.php`（名前空間の先頭 `WpPcloudBackup\` を `includes/` に対応させる単純な PSR-4）。

---

### Task 1: リポジトリ初期化とテスト基盤

**Files:**
- Create: `.gitignore`, `.distignore`, `composer.json`, `phpunit.xml.dist`, `bin/test.sh`, `tests/bootstrap.php`, `tests/Support/TempDir.php`, `wp-pcloud-backup.php`, `includes/Plugin.php`, `tests/Unit/AutoloadTest.php`

**Interfaces:**
- Produces: オートローダー（`WpPcloudBackup\` → `includes/`）、`WP_PCLOUD_BACKUP_VERSION`、`WP_PCLOUD_BACKUP_FILE`、`WP_PCLOUD_BACKUP_DIR`、`WP_PCLOUD_BACKUP_SLUG = 'wp-pcloud-backup'` 定数、`Plugin::boot(): void`（Task 15・16・17 がフック登録を追加していく）、`tests/Support/TempDir::create(): string` と `TempDir::remove(string $dir): void`

- [ ] **Step 1: git 初期化と無視ファイル**

```bash
cd "/Users/yogawa/Library/CloudStorage/Dropbox/Blog/04_Apps/wpbackup" && git init -b main
```

`.gitignore`: `vendor/`, `node_modules/`, `.phpunit.result.cache`, `build/`, `.wp-env.override.json`, `.DS_Store`。
`.distignore`（配布 zip から除く）: `.git`, `.gitignore`, `.distignore`, `tests`, `bin`, `docs`, `vendor`, `node_modules`, `composer.json`, `composer.lock`, `phpunit.xml.dist`, `.wp-env.json`, `*.md`（`README.md` は含めるので `!README.md` を後段に）。

- [ ] **Step 2: composer.json と phpunit.xml.dist**

`composer.json`: `"require-dev": {"phpunit/phpunit": "^9.6", "brain/monkey": "^2.6"}`, `"config": {"platform": {"php": "7.4.33"}}`, `"autoload-dev": {"psr-4": {"WpPcloudBackup\\Tests\\": "tests/"}}`。ランタイム側の autoload は Composer に頼らない（自前オートローダー）。
`phpunit.xml.dist`: bootstrap `tests/bootstrap.php`、testsuite `Unit` = `tests/Unit`。

- [ ] **Step 3: bin/test.sh**

```bash
#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
PHP_IMAGE="${PHP_IMAGE:-php:7.4-cli}"
if [ ! -d vendor ]; then
  docker run --rm -v "$PWD":/app -w /app composer:2 install --no-interaction --ignore-platform-reqs
fi
docker run --rm -v "$PWD":/app -w /app "$PHP_IMAGE" vendor/bin/phpunit "$@"
```

`chmod +x bin/test.sh`。`PHP_IMAGE=php:8.2-cli bin/test.sh` でも通ることを Task 19 で確認する。

- [ ] **Step 4: tests/bootstrap.php と TempDir**

bootstrap: `require vendor/autoload.php`、`require wp-pcloud-backup.php` は WP 定数がないので読まず、代わりにオートローダーだけ定義した `includes/autoload.php` を読む（メインファイルも同じものを require する）。`ABSPATH` が未定義なら `define('ABSPATH', sys_get_temp_dir() . '/wp-abspath/')` を定義。Brain\Monkey は各テストの `setUp`/`tearDown` で `Monkey\setUp()`/`Monkey\tearDown()`。
`TempDir::create(): string` は `sys_get_temp_dir()/wppb-<random>` を作って返す。`remove()` は再帰削除。

- [ ] **Step 5: 失敗するテストを書く**

`tests/Unit/AutoloadTest.php`:
```php
public function test_autoloader_resolves_namespace_to_includes_dir(): void {
    $this->assertTrue(class_exists(\WpPcloudBackup\Plugin::class));
    $this->assertSame('wp-pcloud-backup', WP_PCLOUD_BACKUP_SLUG);
}
```

- [ ] **Step 6: 実行して失敗を確認**

Run: `bin/test.sh`
Expected: FAIL（`Plugin` クラスが見つからない）

- [ ] **Step 7: メインファイルとオートローダーと Plugin を書く**

`includes/autoload.php`: `spl_autoload_register` で接頭辞 `WpPcloudBackup\` を `__DIR__` にマップ。`WP_PCLOUD_BACKUP_SLUG` が未定義なら定義。
`wp-pcloud-backup.php`: プラグインヘッダ（Plugin Name: WP pCloud Backup、Version: 0.1.0、Requires at least: 6.0、Requires PHP: 7.4、Text Domain: wp-pcloud-backup）、`WP_PCLOUD_BACKUP_VERSION`、`WP_PCLOUD_BACKUP_FILE = __FILE__`、`WP_PCLOUD_BACKUP_DIR = __DIR__`、`require includes/autoload.php`、`add_action('plugins_loaded', [Plugin::class, 'boot'])`。
`Plugin::boot(): void` は今は空（`load_plugin_textdomain` のみ）。

- [ ] **Step 8: 実行して成功を確認**

Run: `bin/test.sh`
Expected: `OK (1 test, 2 assertions)`

- [ ] **Step 9: コミット**

```bash
git add -A && git commit -m "chore: scaffold plugin, autoloader and docker-based test runner"
```

---

### Task 2: Settings（設定の既定値・定数上書き・サニタイズ）

**Files:**
- Create: `includes/Settings.php`, `tests/Unit/SettingsTest.php`

**Interfaces:**
- Produces:
  - `Settings::KEYS`: `access_token_enc, root_folder, site_folder, backup_root, schedule, start_time, keep, notify_email, notify_on_success, excludes, part_size_mb, run_budget_sec, use_wp_cron, auto_update, cron_key, workdir_name, last_scheduled, last_success, last_stale_notice`
  - `Settings::defaults(): array`（Global Constraints の既定値。`site_folder` は `parse_url(home_url(), PHP_URL_HOST)`、`notify_email` は `get_option('admin_email')`、`excludes` は既定除外の配列、`cron_key` は空）
  - `Settings::get(string $key)`: 定数 → オプション → 既定値の優先順
  - `Settings::all(): array`
  - `Settings::update(array $input): array` サニタイズして保存し、保存後の全値を返す。ロック中のキーは無視
  - `Settings::isLocked(string $key): bool` 定数で上書きされているか
  - `Settings::accessToken(): ?string` 定数があればそれ、なければ `access_token_enc` を Task 3 の `Crypto::decrypt` で復号（Task 3 まではこのメソッドは定数のみ対応）
  - `Settings::setAccessToken(?string $plain): void`
  - オプション名は 1 つ: `wp_pcloud_backup_settings`（配列）
- 定数対応表: `WP_PCLOUD_BACKUP_ACCESS_TOKEN→access_token`, `..._NOTIFY_EMAIL→notify_email`, `..._ROOT_FOLDER→root_folder`, `..._SCHEDULE→schedule`, `..._KEEP→keep`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/SettingsTest.php`（Brain\Monkey で `get_option`/`update_option`/`home_url`/`sanitize_email`/`sanitize_text_field` をスタブ）:
```php
public function test_defaults_match_spec(): void  // schedule weekly, start_time 03:00, keep 3, part_size_mb 25, run_budget_sec 20, root_folder /WPBackup, use_wp_cron true, auto_update true, notify_on_success false
public function test_update_sanitizes_and_clamps(): void  // keep 99 → 30, keep 0 → 1, part_size_mb 64 → 25（選択肢外は既定）, schedule 'hourly' → 'weekly', start_time '25:99' → '03:00', notify_email 'a@x.jp, bad, b@x.jp' → 'a@x.jp,b@x.jp', excludes は行ごとに trim・空行除去・先頭の '/' 除去
public function test_constant_overrides_option_and_locks_key(): void  // define('WP_PCLOUD_BACKUP_KEEP', 7) → get('keep') === 7, isLocked('keep') === true, update(['keep'=>2]) しても 7
public function test_access_token_constant_wins(): void  // WP_PCLOUD_BACKUP_ACCESS_TOKEN 定義時 accessToken() がそれを返す
```
定数は `runInSeparateProcess` + `preserveGlobalState disabled` のテストで定義する。

- [ ] **Step 2: 実行して失敗を確認**

Run: `bin/test.sh --filter SettingsTest`
Expected: FAIL（クラスなし）

- [ ] **Step 3: Settings を実装**

サニタイズ規則: `schedule` は `['daily','every3days','weekly','biweekly','monthly']` のいずれか、`start_time` は `/^([01]\d|2[0-3]):[0-5]\d$/`、`keep` は 1〜30 の int、`part_size_mb` は `[10,25,50,100]`、`run_budget_sec` は 5〜120、`notify_email` は `sanitize_email` を通して有効なもののみ残しカンマ結合、`root_folder` は先頭 `/` 必須・末尾 `/` 除去、`site_folder` は `/[^A-Za-z0-9._-]/` を `_` に置換、`backup_root` は Task 4 の `BackupRoot::validate` で検証（Task 4 まではそのまま保存）。

- [ ] **Step 4: 実行して成功を確認**

Run: `bin/test.sh --filter SettingsTest`
Expected: PASS

- [ ] **Step 5: コミット**

```bash
git add -A && git commit -m "feat: settings with defaults, sanitization and wp-config constant overrides"
```

---

### Task 3: Crypto（トークンの暗号化保存）

**Files:**
- Create: `includes/Crypto.php`, `tests/Unit/CryptoTest.php`
- Modify: `includes/Settings.php`（`accessToken()`/`setAccessToken()` を Crypto 経由に）

**Interfaces:**
- Produces: `Crypto::encrypt(string $plain): string`（`base64(iv12 . tag16 . ciphertext)`、AES-256-GCM、鍵 = `hash('sha256', AUTH_KEY . SECURE_AUTH_KEY, true)`）、`Crypto::decrypt(string $blob): ?string`（改ざん・鍵違いは `null`）、`Crypto::isAvailable(): bool`（`openssl` と `aes-256-gcm` の有無）

- [ ] **Step 1: 失敗するテストを書く**

```php
public function test_roundtrip(): void                       // encrypt→decrypt が一致、同じ平文でも暗号文は毎回異なる（IV ランダム）
public function test_tampered_blob_returns_null(): void      // 1バイト変えると null
public function test_decrypt_with_other_key_returns_null(): void
```
テスト内で `AUTH_KEY`/`SECURE_AUTH_KEY` を定義（未定義なら）。

- [ ] **Step 2: 失敗を確認** — Run: `bin/test.sh --filter CryptoTest` → FAIL

- [ ] **Step 3: Crypto を実装し、Settings の `accessToken()`/`setAccessToken()` を接続**

`setAccessToken(null)` は `access_token_enc` を空にする。`accessToken()` は定数優先、復号失敗は `null`。

- [ ] **Step 4: 成功を確認** — Run: `bin/test.sh` → PASS（全体）

- [ ] **Step 5: コミット** — `git commit -am "feat: AES-256-GCM crypto for stored access token"`

---

### Task 4: BackupRoot（起点フォルダの検出と検証）

**Files:**
- Create: `includes/BackupRoot.php`, `tests/Unit/BackupRootTest.php`
- Modify: `includes/Settings.php`（`backup_root` の検証と既定値）

**Interfaces:**
- Produces: `BackupRoot::detect(string $abspath, string $siteUrl, string $homeUrl): string`（末尾スラッシュなしの絶対パス）、`BackupRoot::validate(string $candidate, string $detected, string $abspath): bool`（`detected ⊆ candidate ⊆ abspath` の意味で「detected の配下かつ abspath の祖先または同一」）、`BackupRoot::normalize(string $path): string`（`realpath` 相当の正規化、末尾スラッシュ除去）

- [ ] **Step 1: 失敗するテストを書く**

```php
public function test_same_path_when_site_equals_home(): void          // ABSPATH /var/www/html/, site https://ex.jp, home https://ex.jp → /var/www/html
public function test_goes_up_when_wp_in_subdir(): void                 // ABSPATH /var/www/html/wp/, site https://ex.jp/wp, home https://ex.jp → /var/www/html
public function test_two_levels(): void                                // site /a/b, home / → 2つ上
public function test_ignores_when_home_is_deeper_than_site(): void     // site /, home /blog → ABSPATH のまま
public function test_validate_rejects_above_detected_and_outside_abspath(): void
```

- [ ] **Step 2: 失敗を確認** — Run: `bin/test.sh --filter BackupRootTest` → FAIL

- [ ] **Step 3: 実装**

パス差分は `parse_url` の path を `trim('/')` して `/` で分割し、`home` の要素列が `site` の要素列の先頭一致であることを確認して差分段数だけ `dirname` する。Settings の `backup_root` 既定値は `detect(ABSPATH, site_url(), home_url())`、`update()` では `validate` に通らない値を無視して既定値に戻す。

- [ ] **Step 4: 成功を確認** — Run: `bin/test.sh` → PASS

- [ ] **Step 5: コミット** — `git commit -am "feat: detect and validate backup root folder"`

---

### Task 5: WorkDir と JobState

**Files:**
- Create: `includes/WorkDir.php`, `includes/Job/JobState.php`, `tests/Unit/WorkDirTest.php`, `tests/Unit/JobStateTest.php`

**Interfaces:**
- Produces:
  - `WorkDir::__construct(Settings $settings, string $uploadsBaseDir)`、`WorkDir::ensure(): string`（`<uploads>/wp-pcloud-backup-<16文字 a-z0-9>/` を作り、`.htaccess`（`Deny from all` と `<IfModule mod_authz_core.c>Require all denied</IfModule>` の両方）と空の `index.php` を置き、名前を `workdir_name` に保存。既存なら作らない）、`WorkDir::path(string $file = ''): string`、`WorkDir::isWebServerApache(): bool`（`$_SERVER['SERVER_SOFTWARE']` に `apache` or `litespeed`）、`WorkDir::freeBytes(): ?int`
  - `JobState::create(string $file, array $data): JobState`、`JobState::load(string $file): ?JobState`（なければ null、壊れた JSON も null）、`get(string $key, $default = null)`、`set(string $key, $value): void`、`merge(array $values): void`、`save(): void`（`file.tmp` に書いて `rename`）、`delete(): void`、`toArray(): array`
  - `JobState::acquireLock(string $lockFile): bool`（`fopen` + `flock(LOCK_EX|LOCK_NB)`）、`releaseLock(): void`

- [ ] **Step 1: 失敗するテストを書く**

WorkDirTest: `test_ensure_creates_random_dir_with_htaccess_and_index`（名前が `/^wp-pcloud-backup-[a-z0-9]{16}$/`、2回呼んでも同じパス）。
JobStateTest: `test_save_is_atomic_and_reloadable`（保存後に `.tmp` が残らない、load で同じ配列）、`test_load_returns_null_for_missing_or_corrupt`、`test_lock_is_exclusive`（同一プロセス内で2つ目の `fopen` ハンドルから `flock(LOCK_NB)` が失敗することを確認）。

- [ ] **Step 2: 失敗を確認** → FAIL

- [ ] **Step 3: 実装**

- [ ] **Step 4: 成功を確認** → PASS

- [ ] **Step 5: コミット** — `git commit -am "feat: protected work directory and atomic job state with locking"`

---

### Task 6: FileScanner（再開可能な走査）

**Files:**
- Create: `includes/Scan/FileScanner.php`, `tests/Unit/FileScannerTest.php`

**Interfaces:**
- Produces:
  - `FileScanner::__construct(string $root, array $excludes, string $listFile, array $state = [])`
  - `FileScanner::run(callable $timeUp): bool` — `$timeUp(): bool` が true を返すまで走査を進め、完了したら true。未完了なら false
  - `FileScanner::state(): array` — `['pending' => [相対ディレクトリの配列], 'files' => int, 'bytes' => int, 'warnings' => [string]]`
  - 出力 `listFile` は JSONL、1行 `{"p":"wp-content/uploads/a.jpg","s":12345,"m":1700000000}`。ディレクトリ自体は書かない（空ディレクトリは復元対象外、README に明記）
  - 除外判定: 相対パス（`/` 区切り）が除外エントリと一致、またはそのエントリ + `/` で始まる。ワイルドカードは末尾 `*` のみ対応（`fnmatch` ではなく `str_starts_with` 相当で実装）
  - シンボリックリンクは辿らず警告に記録。読めないディレクトリも警告

- [ ] **Step 1: 失敗するテストを書く**

TempDir にフィクスチャ（`a.txt`, `wp-content/uploads/x.jpg`, `wp-content/cache/c.txt`, `wp-content/uploads/backup-old/z.zip`, `日本語 名前.txt`）を作って:
```php
public function test_lists_all_files_except_excludes(): void       // cache と backup-old は出ない、日本語名が JSON で正しく（JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES）
public function test_resumes_from_state(): void                     // $timeUp が最初の呼び出しで true を返す → false が返り pending が非空。state を渡して再実行 → true、行数が全ファイル数と一致、重複なし
public function test_symlink_is_skipped_with_warning(): void
```

- [ ] **Step 2: 失敗を確認** → FAIL

- [ ] **Step 3: 実装**

`pending` は相対ディレクトリのスタック。1ディレクトリを `scandir` し、ファイルは即 JSONL に追記、サブディレクトリは `pending` に積む。1ディレクトリ処理が終わるごとに `$timeUp()` を見る（ディレクトリ単位で進捗を記録する、設計書 8.1 の 2）。追記は `fopen('ab')`。

- [ ] **Step 4: 成功を確認** → PASS

- [ ] **Step 5: コミット** — `git commit -am "feat: resumable file scanner writing JSONL file list"`

---

### Task 7: PartWriter（バイト分割出力）

**Files:**
- Create: `includes/Zip/PartWriter.php`, `tests/Unit/PartWriterTest.php`

**Interfaces:**
- Produces:
  - `PartWriter::__construct(string $dir, string $baseName, int $partSize, array $state = [])` — ファイル名は `{baseName}.{NNN}`（001 から）
  - `write(string $bytes): void` — パート境界で自動的に次のパートへ
  - `offset(): int` — 全体の論理オフセット（全パート合計）
  - `truncateTo(int $offset): void` — 論理オフセットまで切り戻す。後続パートは削除し、該当パートを `ftruncate`。完了済みリストから外す
  - `finish(): void` — 現在のパートを閉じて完了済みに加える
  - `completedParts(): array` — `[['index'=>1,'name'=>'files.zip.001','path'=>..., 'size'=>..., 'sha1'=>..., 'sha256'=>...], ...]`（ハッシュはパート完成時に `hash_file` で計算）
  - `markUploaded(int $index): void` / `pendingUploads(): array`（完了済みで未アップロードのもの）
  - `state(): array`（`current_index`, `current_size`, `offset`, `parts`）。再開時は現在パートを `'ab'` で開く

- [ ] **Step 1: 失敗するテストを書く**

```php
public function test_splits_exactly_at_part_size(): void      // partSize 10、25バイト書く → 001=10B, 002=10B, finish 後 003=5B、concat が元データと一致
public function test_hashes_computed_on_completion(): void    // sha1/sha256 が hash_file と一致
public function test_truncate_across_parts(): void            // 25バイト書いて truncateTo(7) → 002/003 が消え 001 が 7B、offset() === 7、以降の write が続きに入る
public function test_resume_from_state(): void                // 15バイト書いて state() を保存、新インスタンスで 10バイト追加 → 結合結果が 25バイト
```

- [ ] **Step 2: 失敗を確認** → FAIL

- [ ] **Step 3: 実装**

- [ ] **Step 4: 成功を確認** → PASS

- [ ] **Step 5: コミット** — `git commit -am "feat: byte-splitting part writer with resume and truncate"`

---

### Task 8: ZipWriter（ストリーミング ZIP64）

**Files:**
- Create: `includes/Zip/ZipWriter.php`, `tests/Unit/ZipWriterTest.php`

**Interfaces:**
- Produces:
  - `ZipWriter::__construct(PartWriter $sink, string $centralDirFile, array $state = [])` — `centralDirFile` はセントラルディレクトリのレコードを追記していくバイナリファイル
  - `addFile(string $absPath, string $relPath, int $mtime): array` — 1MB ずつ読み、戻り値 `['size'=>実際に読んだバイト数, 'compressed'=>int, 'method'=>0|8]`。読めない場合は `\RuntimeException`。**ファイルサイズは一覧の値ではなく実際に読んだ量で書く**（Review Focus 2）
  - `entryStartOffset(): ?int` — 直近の `addFile` 開始時の sink オフセット（ランナーが中断復旧の `truncateTo` に使う）
  - `finish(): void` — セントラルディレクトリを `centralDirFile` から sink へ流し、ZIP64 EOCD レコード + ZIP64 EOCD ロケータ + EOCD を書き、`sink->finish()`
  - `state(): array`（`entries`, `cd_size`）
  - `ZipWriter::STORE_EXTENSIONS = ['jpg','jpeg','png','gif','webp','avif','heic','mp4','mov','m4v','mp3','m4a','ogg','webm','zip','gz','bz2','xz','7z','rar','woff','woff2','pdf']`
- 形式の決定: 常に ZIP64 形式で書く（ローカルヘッダに ZIP64 extra、データディスクリプタはビット3使用で ZIP64 版 24 バイト、セントラルディレクトリにも ZIP64 extra、EOCD はすべて 0xFFFF/0xFFFFFFFF）。汎用フラグのビット 11（UTF-8 ファイル名）を立てる。圧縮は `deflate_init(ZLIB_ENCODING_RAW, ['level'=>6])` + `deflate_add`。CRC32 は `hash_init('crc32b')` の結果をリトルエンディアン整数に変換。DOS 日時は `$mtime` から（1980 未満は 1980-01-01）

- [ ] **Step 1: 失敗するテストを書く**

テストは PHP の `ZipArchive` で結合後の zip を開いて検証する（`php:7.4-cli` イメージに `zip` 拡張が無い場合は `bin/test.sh` で `docker-php-ext-install zip` 済みの自前イメージ `Dockerfile.test` を使うよう切り替える。これは Step 3 の中で判断して対応する）。
```php
public function test_store_and_deflate_by_extension(): void   // a.txt(1MB の繰り返し文字列) は method 8 で元より小さく、b.jpg はランダム 100KB で method 0
public function test_resulting_zip_opens_and_matches_content(): void  // 3ファイル、partSize 64KB で複数パート → concat → ZipArchive::open === true、各 entry の内容が元と一致、numFiles === 3
public function test_utf8_and_long_names(): void              // 'wp-content/uploads/2026/10/日本語 ファイル名.txt' と 200 文字のパスが ZipArchive::statIndex の name と一致（Review Focus 1）
public function test_actual_size_wins_over_declared(): void   // addFile の戻り size が実ファイルサイズ
public function test_resume_mid_archive(): void               // 2ファイル追加 → state 保存 → 新インスタンスで 1ファイル追加 → finish → 3ファイル読める
public function test_truncate_after_crash_mid_entry(): void   // addFile 中断を模擬: entryStartOffset() を取り、sink に不完全データを write、truncateTo(entryStartOffset) → addFile やり直し → zip が正常
```

- [ ] **Step 2: 失敗を確認** → FAIL

- [ ] **Step 3: 実装**

- [ ] **Step 4: 成功を確認** → PASS。加えて手動検証: テストが出力した zip を `unzip -t` と macOS の Finder（ダブルクリック）で開けることを確認し、結果を README の「検証済み環境」に書く

- [ ] **Step 5: コミット** — `git commit -am "feat: streaming ZIP64 writer with store/deflate and resume"`

---

### Task 9: DbDumper（再開可能な SQL ダンプ + gzip）

**Files:**
- Create: `includes/Db/DbDumper.php`, `tests/Unit/DbDumperTest.php`

**Interfaces:**
- Produces:
  - `DbDumper::__construct(\wpdb $wpdb, string $sqlFile, string $gzFile, array $state = [], int $batch = 1000)`
  - `dump(callable $timeUp): bool` — 全テーブル終了で true
  - `compress(callable $timeUp): bool` — `sqlFile` を 1MB ずつ `gzopen($gzFile, 'ab')` で追記（マルチメンバー gzip）。終了で true、`sqlFile` を削除
  - `state(): array` — `['tables'=>[...], 'table_index'=>int, 'offset'=>int, 'phase'=>'dump'|'compress', 'compress_offset'=>int, 'rows'=>int]`
  - 出力 SQL 先頭: `SET NAMES utf8mb4;`, `SET FOREIGN_KEY_CHECKS=0;`, `SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';`。テーブルごとに `DROP TABLE IF EXISTS \`t\`;` + `SHOW CREATE TABLE` の結果 + `INSERT INTO \`t\` (\`c1\`,...) VALUES (...),(...);`（1文あたり最大 `$batch` 行）
  - 行の取得順: 主キーがあれば `ORDER BY <pk列>`、なければ順序なし。`LIMIT $batch OFFSET $offset`
  - 値の書式: `NULL` → `NULL`、数値型（`SHOW COLUMNS` の Type が int/decimal/float/double/bigint 等）→ そのまま、BLOB/BINARY → `0x` + hex（空は `''`）、それ以外 → `'...'` で `\`, `'`, `"`, `\n`, `\r`, `\0`, `\x1a` をエスケープ
  - `wp_options`（`$wpdb->options`）は `WHERE option_name NOT LIKE '\_transient\_%' AND option_name NOT LIKE '\_site\_transient\_%'`
- テストは `\wpdb` を Brain\Monkey なしの手製スタブ（`tests/Support/FakeWpdb.php`: `get_results`/`get_row`/`get_col`/`get_var` に SQL パターンごとの応答を登録）で行う。実 MySQL での検証は Task 19

- [ ] **Step 1: 失敗するテストを書く**

```php
public function test_header_and_create_table(): void
public function test_insert_batches_and_escaping(): void       // 2500 行 → 3 文、'O\'Reilly'、"a\\b"、"line\nbreak"、絵文字 🍣、NULL、BLOB "\x00\xff" → 0x00ff（Review Focus 3）
public function test_table_without_primary_key_and_empty_table(): void  // ORDER BY なし、空テーブルは CREATE のみ
public function test_transients_excluded_from_options(): void
public function test_resume_by_table_and_offset(): void
public function test_compress_produces_valid_multi_member_gzip(): void  // gzdecode ではなく gzopen/gzread で全体を読み元 SQL と一致（複数メンバー対応）
```

- [ ] **Step 2: 失敗を確認** → FAIL

- [ ] **Step 3: 実装** — `FakeWpdb` も作る

- [ ] **Step 4: 成功を確認** → PASS

- [ ] **Step 5: コミット** — `git commit -am "feat: resumable pure-PHP database dumper with gzip stage"`

---

### Task 10: Storage 層（HTTP トランスポート、pCloud クライアント、ローカル実装）

**Files:**
- Create: `includes/Storage/StorageInterface.php`, `includes/Storage/StorageException.php`, `includes/Storage/HttpTransportInterface.php`, `includes/Storage/CurlTransport.php`, `includes/Storage/PcloudClient.php`, `includes/Storage/LocalStorage.php`, `tests/Support/FakeTransport.php`, `tests/Unit/PcloudClientTest.php`, `tests/Unit/LocalStorageTest.php`

**Interfaces:**
- Produces:
```php
interface StorageInterface {
    /** @return int folder id（パス全体を作る。途中の階層も） */
    public function ensureFolder(string $path): int;
    /** @return array<int, array{id:int,name:string,isfolder:bool,size:int,modified:int}> */
    public function listFolder(int $folderId): array;
    /** @return array{id:int,size:int,sha1:string} */
    public function uploadFile(int $folderId, string $localPath, string $name): array;
    public function renameFolder(int $folderId, string $newName): void;
    public function deleteFolder(int $folderId): void;
    public function deleteFile(int $fileId): void;
    /** @return array{sha1:string,md5:string,size:int} */
    public function checksum(int $fileId): array;
}
final class StorageException extends \RuntimeException {
    public function __construct(string $message, int $code, bool $retryable, string $category /* 'network'|'auth'|'quota'|'server'|'other' */);
    public function isRetryable(): bool; public function category(): string;
}
interface HttpTransportInterface {
    /** @param array{headers?:array<string,string>, query?:array, form?:array, upload?:array{field:string,path:string,name:string}, timeout?:int, low_speed_limit?:int, low_speed_time?:int} $options
     *  @return array{status:int, body:string}  ネットワーク失敗は StorageException(network, retryable) */
    public function request(string $method, string $url, array $options = []): array;
}
```
  - `CurlTransport`: `upload` 指定時は `CURLOPT_POSTFIELDS` に `['file' => new \CURLFile($path, 'application/octet-stream', $name)]` + `form` を混ぜる。`timeout` 既定 60、upload 時 600。`CURLOPT_LOW_SPEED_LIMIT=1024`、`CURLOPT_LOW_SPEED_TIME=30`
  - `PcloudClient::__construct(HttpTransportInterface $http, string $token, string $host = 'api.pcloud.com')`、`PcloudClient::exchangeCode(HttpTransportInterface $http, string $clientId, string $clientSecret, string $code, string $host): string`（`oauth2_token`、戻り `access_token`）、`userInfo(): array{email:string,quota:int,usedquota:int}`
  - pCloud 応答の扱い: HTTP 200 かつ `result === 0` のみ成功。`result` 1000/2000/2094/2095 → `auth`（非再試行）、2008 → `quota`（非再試行）、4000/5000/5001 → `server`（再試行）、HTTP 5xx/0 → `server`/`network`（再試行）、それ以外 → `other`（非再試行）。`uploadfile` は `nopartial=1` を付ける。`uploadFile` の戻り `sha1` は応答 metadata の `hash` ではなく後続の `checksumfile` で取る（`uploadFile` 内で呼ぶ）
  - `ensureFolder('/WPBackup/example.com')` は `createfolderifnotexists` を `folderid=0,name=WPBackup` → 返った id で `name=example.com` と階層ごとに呼ぶ
  - `LocalStorage::__construct(string $baseDir)`: フォルダ id は `baseDir/.ids.json` に `path → int` を持つ連番。`uploadFile` は `copy`、`checksum` は `hash_file`

- [ ] **Step 1: 失敗するテストを書く**

`FakeTransport`: `queue(array $response)` と `requests(): array` を持つ。
PcloudClientTest:
```php
public function test_bearer_header_and_host(): void                  // Authorization: Bearer <token>、URL が https://api.pcloud.com/userinfo
public function test_ensure_folder_creates_each_level(): void        // 2 リクエスト、2つ目の folderid が1つ目の応答の id
public function test_upload_sends_multipart_and_verifies_checksum(): void  // upload オプションに path/name、nopartial=1、その後 checksumfile が呼ばれ sha1 が返る
public function test_result_2008_is_quota_not_retryable(): void      // HTTP 200 + {"result":2008} → StorageException category quota, isRetryable false（Review Focus 4）
public function test_result_2000_is_auth_not_retryable(): void
public function test_http_500_and_network_are_retryable(): void
public function test_exchange_code_returns_token(): void
```
LocalStorageTest: `test_roundtrip_folder_upload_list_rename_delete`。

- [ ] **Step 2: 失敗を確認** → FAIL

- [ ] **Step 3: 実装**（CurlTransport は単体テストなし。Task 19 の実機確認で検証）

- [ ] **Step 4: 成功を確認** → PASS

- [ ] **Step 5: コミット** — `git commit -am "feat: storage abstraction with pCloud client, curl transport and local storage"`

---

### Task 11: Manifest と Retention

**Files:**
- Create: `includes/Manifest.php`, `includes/Retention.php`, `tests/Unit/ManifestTest.php`, `tests/Unit/RetentionTest.php`

**Interfaces:**
- Produces:
  - `Manifest::build(array $job): string` — JSON（`JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES`）。キー: `format` (=1), `plugin_version`, `created_at`(ISO 8601, サイト TZ), `site_url`, `home_url`, `wp_version`, `php_version`, `backup_root`, `abspath`, `files_count`, `files_bytes`, `parts: [{name,size,sha1,sha256}]`, `database: {file:'database.sql.gz', tables:[...], rows:int, sha1, sha256}`, `warnings: [string]`, `excludes: [string]`
  - `Retention::__construct(StorageInterface $storage, int $siteFolderId, int $incompleteTtl = 172800)`
  - `Retention::generations(): array` — 完了フォルダ（`/^\d{4}-\d{2}-\d{2}_\d{6}$/`）を名前昇順
  - `Retention::prune(int $keep, int $now): array` — 完了フォルダが `keep` を超える分を古い順に `deleteFolder`、`.incomplete-*` で `modified` が `now - ttl` より古いものも削除。削除した名前の配列を返す

- [ ] **Step 1: 失敗するテストを書く**

RetentionTest は `LocalStorage` を実ストレージとして使う（`modified` はファイルシステムの mtime を `touch` で操作）:
```php
public function test_prune_keeps_newest_n(): void                 // 5世代 keep 3 → 古い2つ削除
public function test_incomplete_folders_not_counted_and_pruned_after_ttl(): void
public function test_other_folders_untouched(): void              // 'notes' のような無関係フォルダは消さない
```
ManifestTest: `test_contains_required_keys_and_unicode_unescaped`。

- [ ] **Step 2〜5: 失敗確認 → 実装 → 成功確認 → コミット** — `git commit -am "feat: manifest builder and generation retention"`

---

### Task 12: Scheduler（次回予定・オフセット・停止検知・WP-Cron 登録）

**Files:**
- Create: `includes/Scheduler.php`, `tests/Unit/SchedulerTest.php`

**Interfaces:**
- Produces:
  - `Scheduler::periodDays(string $schedule): int` — daily 1, every3days 3, weekly 7, biweekly 14, monthly は 0（暦月扱い）
  - `Scheduler::offsetMinutes(string $siteFolder): int` — `crc32($siteFolder) % 60`
  - `Scheduler::nextRun(string $schedule, string $startTime, int $offsetMinutes, ?int $lastScheduled, int $now, \DateTimeZone $tz): int` — グリッド計算。`lastScheduled` が null なら「今日の startTime+offset」以降で最初の時刻（過ぎていれば翌日）。あれば `lastScheduled` に周期を足し、`now` を超えるまで繰り返す（遅延を蓄積させない。設計書 12 章）。monthly は `DateTime::modify('+1 month')` で、日が溢れたら月末に丸める（1/31 → 2/28）
  - `Scheduler::isStale(?int $lastSuccess, string $schedule, int $now): bool` — `lastSuccess` が null なら false（初回未実行は停止ではない）、`now - lastSuccess > 2 × 周期秒`（monthly は 62 日）
  - `Scheduler::registerCron(): void` — `cron_schedules` フィルタで `wp_pcloud_backup_minutely`(60秒) を追加、`wp_pcloud_backup_tick` イベントを未登録なら `wp_schedule_event`。`unregisterCron(): void`

- [ ] **Step 1: 失敗するテストを書く**

```php
public function test_first_run_today_or_tomorrow(): void          // now = 2026-10-25 02:00 JST, start 03:00, offset 17 → 同日 03:17 JST; now = 04:00 → 翌日 03:17
public function test_grid_advances_without_drift(): void          // weekly, lastScheduled = 10/18 03:17, now = 10/27 → 11/1 03:17（10/25 ではない）
public function test_monthly_end_of_month(): void                  // lastScheduled 1/31 03:00 → 2/28 03:00（Review Focus 5）
public function test_timezone_conversion(): void                   // JST 03:00 の返り値が UTC 18:00 前日の epoch
public function test_offset_is_stable_and_in_range(): void
public function test_is_stale(): void                              // weekly, 15日前 → true、13日前 → false、null → false
```

- [ ] **Step 2〜5: 失敗確認 → 実装 → 成功確認 → コミット** — `git commit -am "feat: schedule grid calculation, per-site offset and stale detection"`

---

### Task 13: Notifier と History

**Files:**
- Create: `includes/Notifier.php`, `includes/History.php`, `tests/Unit/NotifierTest.php`, `tests/Unit/HistoryTest.php`

**Interfaces:**
- Produces:
  - `Notifier::__construct(Settings $settings, string $host, string $adminUrl)`
  - `failure(string $step, string $error, int $attempts): bool`、`stalled(?int $lastSuccess, string $cause): bool`、`completed(array $summary, array $warnings): bool`（警告ありなら必ず送る。なしは `notify_on_success` のとき）、`updateFailed(string $reason): bool`。すべて `wp_mail` の戻り値を返す。宛先は `notify_email` をカンマ分割
  - 件名は Global Constraints のとおり。本文はプレーンテキスト、先頭行に `サイト: {host}`、末尾に管理画面 URL
  - `History::add(array $entry): void`（`wp_pcloud_backup_history` オプション、先頭に追加して 20 件に切る）、`History::all(): array`、`History::clear(): void`。entry: `{started:int, finished:int, result:'success'|'warning'|'failed'|'updated', bytes:int, files:int, remote:string, message:string}`

- [ ] **Step 1: 失敗するテストを書く**

```php
public function test_failure_subject_and_recipients(): void   // 件名 '[WP pCloud Backup] 失敗: example.com'、宛先 2 件、本文にトークンや鍵が含まれない（'Bearer' も含まれない）
public function test_completed_without_warnings_respects_setting(): void
public function test_completed_with_warnings_always_sends(): void   // 件名 '完了(警告あり)'
public function test_history_caps_at_20_newest_first(): void
```

- [ ] **Step 2〜5: 失敗確認 → 実装 → 成功確認 → コミット** — `git commit -am "feat: email notifier and run history"`

---

### Task 14: JobRunner（ステップマシン）

**Files:**
- Create: `includes/Job/JobRunner.php`, `tests/Unit/JobRunnerTest.php`

**Interfaces:**
- Consumes: Task 2〜13 のすべて
- Produces:
```php
final class JobRunner {
    public const STEPS = ['prepare','scan','dump','compress','zip','upload','finalize','cleanup'];
    public function __construct(Settings $s, WorkDir $w, StorageInterface $st, Notifier $n, History $h, \wpdb $wpdb, callable $clock = null /* fn(): int */);
    /** 期限が来ていれば startJob、ジョブがあれば run。戻り値は status() */
    public function tick(): array;
    public function startJob(string $trigger /* 'schedule'|'manual'|'url' */): bool;  // 実行中なら false
    public function run(): array;          // 1ラン。ロック取得失敗や連打ガードは何もせず status を返す
    public function status(): array;       // {running:bool, step:?string, progress:float 0..1, files_done:int, files_total:int, parts_uploaded:int, started:?int, message:string, last_error:?string}
    public function abort(string $reason): void;  // 失敗処理（リモート .incomplete 削除、通知、履歴、作業ファイル削除）
}
```
- ラン1回の規則:
  1. `JobState::acquireLock` 失敗 → 即 return。`state.last_run_started` から `run_budget_sec × 3` 秒未満で、かつ前回ランが `finished` でない → return（連打ガード）
  2. `$deadline = now + run_budget_sec`、`$timeUp = fn() => clock() >= $deadline`
  3. 現在ステップを実行。`scan`/`dump`/`compress`/`zip` は `$timeUp` を渡す。`upload` は `$timeUp` を無視して **1パートの送信を完了させてから** 戻る（設計書 8.1 の 5）
  4. ステップ内で `StorageException` or `\RuntimeException` → `state.attempts[step]++`。非再試行（`auth`/`quota`）または 5 回目 → `abort()`。それ以外は `state.last_error` に記録して return
  5. `started + 48h < now` → `abort('timeout')`
  6. `zip` ステップの詳細: `filelist.jsonl` の `state.zip_line` 行目から読み、各ファイルを `ZipWriter::addFile`。読めないファイルは `warnings` に追加して続行（Review Focus 2）。1ファイル終わるごとに `PartWriter::pendingUploads()` が非空なら `state.step = 'upload'` に切り替えて return（「1パート完成 → アップロード → 次」の交互。設計書 8.1 の 5）。一覧を最後まで処理したら `ZipWriter::finish()` して `step='upload'`（最終パート群と `database.sql.gz`、`manifest.json` の順でアップロード）、全アップロード後 `finalize`
  7. ランの開始時に `state.zip_in_progress_offset` が残っていれば、前回ランがファイル途中で死んだとみなし `PartWriter::truncateTo` で巻き戻す
  8. `upload` は pending の先頭 1 つを `uploadFile` → 戻り `sha1` とローカルの `sha1` を比較、不一致は `RuntimeException`（再試行扱い）。一致で `markUploaded` とローカル削除。pending が空になったら前のステップ（`zip` or `finalize`）へ戻る
  9. `finalize`: Manifest 生成 → アップロード → `renameFolder(incompleteId, finalName)` → `Retention::prune(keep)` → `History::add` → `Notifier::completed` → `last_success` を Settings に保存 → `cleanup`
  10. `prepare`: `WorkDir::ensure`、`state.json` 初期化、`ensureFolder(root_folder/site_folder)` → `ensureFolder(.../.incomplete-{stamp})`、`last_scheduled` 更新（trigger が schedule のとき）
- `tick()` の非ジョブ時の処理: `Scheduler::nextRun(...) <= now` なら `startJob('schedule')`。`Scheduler::isStale` かつ `last_stale_notice` が 24h 以上前なら `Notifier::stalled` して `last_stale_notice = now`

- [ ] **Step 1: 失敗するテストを書く**

`LocalStorage` + `FakeWpdb` + TempDir のフィクスチャサイト（約 50 ファイル・計 3MB、partSize は Settings 経由で最小 10MB だがテストでは `Settings` をスタブして 512KB を返す）で、`clock` を手で進める。
```php
public function test_full_job_completes_over_many_runs(): void       // run() を繰り返し（上限 500 回）、最終的に running false、リモートに YYYY-MM-DD_HHMMSS フォルダ、manifest/database.sql.gz/files.zip.NNN が揃い、.incomplete がない、History に success、結合 zip が開ける
public function test_each_run_respects_budget_except_upload(): void  // scan/zip のランは clock 進行で止まる（timeUp が呼ばれた回数 > 0）
public function test_unreadable_file_becomes_warning(): void         // 走査後に 1 ファイルを chmod 000（root では効かないので代わりに unlink）→ 完了、manifest.warnings に 1 件、History result 'warning'、Notifier::completed が呼ばれる
public function test_retryable_error_retries_then_fails_after_5(): void  // StorageInterface のデコレータで uploadFile が常に retryable 例外 → attempts 5 で abort、.incomplete 削除、Notifier::failure 1 回、作業ファイル消去（Review Focus 4）
public function test_fatal_error_aborts_immediately(): void          // quota 例外 → 1 回で abort
public function test_lock_prevents_concurrent_run(): void
public function test_rapid_fire_guard(): void                        // 同じ clock で run() 2 回目は何もしない
public function test_tick_starts_job_when_due_and_sends_stale_notice(): void
public function test_crash_mid_entry_is_truncated_on_resume(): void  // state.zip_in_progress_offset を手で残して run → PartWriter が巻き戻され最終 zip が正常
```

- [ ] **Step 2: 失敗を確認** → FAIL

- [ ] **Step 3: 実装**

- [ ] **Step 4: 成功を確認** → PASS（`bin/test.sh` 全体）

- [ ] **Step 5: コミット** — `git commit -am "feat: resumable backup job runner with retry, alternating zip/upload and finalize"`

---

### Task 15: Trigger（WP-Cron・外部 URL・AJAX）と Plugin 組み立て

**Files:**
- Create: `includes/Trigger.php`, `tests/Unit/TriggerTest.php`
- Modify: `includes/Plugin.php`

**Interfaces:**
- Produces:
  - `Plugin::boot()`: Settings/WorkDir/Storage/Notifier/History/JobRunner を組み立てる。ストレージは定数 `WP_PCLOUD_BACKUP_STORAGE_DIR` が定義されていれば `LocalStorage`（開発用）、なければ `PcloudClient(new CurlTransport, Settings::accessToken())`。トークン未設定なら `JobRunner` は「未接続」として `tick()` で何もしない（status.message = 未接続）
  - `Plugin::runner(): JobRunner` / `Plugin::settings(): Settings`（Admin から使う）
  - `Trigger::__construct(JobRunner $runner, Settings $settings)`、`Trigger::register(): void`:
    - `add_action('wp_pcloud_backup_tick', ...)`: `use_wp_cron` が true なら `runner->tick()`
    - `add_action('init', ...)`: `$_GET['wp-pcloud-backup'] === 'run'` のとき `handleUrl(string $key): void` — `cron_key` が空、または `hash_equals(cron_key, key)` 偽なら `status_header(403)` + `exit`。真なら `nocache_headers()`、`runner->tick()`、`wp_send_json(status)`
    - `add_action('wp_ajax_wp_pcloud_backup_start', ...)`/`'…_run'`/`'…_status'`: `check_ajax_referer('wp_pcloud_backup')` + `current_user_can('manage_options')`、それぞれ `startJob('manual')`→`run()`→status、`run()`→status、status
  - `Trigger::cronUrl(): string` — `add_query_arg(['wp-pcloud-backup'=>'run','key'=>cron_key], home_url('/'))`
  - `Trigger::regenerateKey(): string` — `wp_generate_password(64, false, false)` 相当（`bin2hex(random_bytes(32))`）を `cron_key` に保存
  - 有効化フック（`register_activation_hook`）: `Settings` 初期化、`cron_key` が空なら生成、`WorkDir::ensure`、`Scheduler::registerCron`。無効化フック: `Scheduler::unregisterCron`

- [ ] **Step 1: 失敗するテストを書く**

```php
public function test_url_with_wrong_key_returns_403_and_does_not_run(): void   // status_header(403) が呼ばれ、runner->tick は呼ばれない（runner はモック）
public function test_url_with_correct_key_ticks_and_returns_json(): void
public function test_empty_key_always_rejects(): void
public function test_regenerate_key_is_64_hex(): void
public function test_wp_cron_disabled_setting_skips_tick(): void
```

- [ ] **Step 2〜5: 失敗確認 → 実装 → 成功確認 → コミット** — `git commit -am "feat: wp-cron, secret URL and AJAX triggers; wire services in Plugin::boot"`

---

### Task 16: 管理画面

**Files:**
- Create: `includes/Admin/AdminPage.php`, `includes/Admin/views/tab-status.php`, `tab-settings.php`, `tab-connection.php`, `includes/Admin/assets/admin.js`, `admin.css`, `tests/Unit/AdminPageTest.php`
- Modify: `includes/Plugin.php`（`is_admin()` のとき `AdminPage::register()`）

**Interfaces:**
- Produces:
  - `AdminPage::__construct(Plugin $plugin)`、`register(): void` — `admin_menu` で `add_options_page('pCloud Backup', 'pCloud Backup', 'manage_options', 'wp-pcloud-backup', [render])`、`admin_enqueue_scripts`（このページのみ）、`admin_post_wp_pcloud_backup_*` アクション群
  - タブ `status` / `settings` / `connection`（`?page=wp-pcloud-backup&tab=`）
  - POST アクション（すべて nonce `wp_pcloud_backup_admin` + `manage_options`）:
    - `save_settings` → `Settings::update($_POST)`。ロック中の項目は `disabled` で描画し「wp-config.php の定数で設定済み」と注記
    - `save_token`（貼り付け） → `PcloudClient::userInfo()` で検証、成功なら `setAccessToken`、メールを `connection_email` に保存。失敗はエラー表示で保存しない
    - `exchange_code`（client_id, client_secret, code） → `PcloudClient::exchangeCode` → 上と同じ検証・保存。Client ID/Secret は保存しない
    - `reveal_token`（`current_password`） → `wp_check_password($pw, $user->user_pass, $user->ID)` が真なら 1 回だけ表示する画面（transient 60 秒のワンタイムトークンでリダイレクト後に表示、再読込で消える）
    - `disconnect` → `setAccessToken(null)`
    - `regenerate_key` → `Trigger::regenerateKey`
    - `detect_root` → `backup_root` を `BackupRoot::detect` の値に戻す
  - 「状態」タブ: 最終成功 / 次回予定（`Scheduler::nextRun`）/ 進捗バー（`admin.js` が 3 秒ごとに `wp_pcloud_backup_status` を叩き、実行中なら `…_run` も叩く）/ 「今すぐバックアップ」ボタン / 履歴テーブル 20 件
  - 「接続」タブの診断: PHP バージョン、`max_execution_time`、`memory_limit`、`zlib`/`curl`/`openssl` の有無、作業フォルダの書き込み可否と `.htaccess` の有無、`WorkDir::isWebServerApache()` が偽なら「nginx 環境では作業フォルダへの直接アクセスをサーバー設定で遮断してください」警告、`DISABLE_WP_CRON` の検出、ディスク空き容量、`.incomplete` ジョブの有無
  - `AdminPage::diagnostics(): array` — 上記を配列で返す（テスト対象）

- [ ] **Step 1: 失敗するテストを書く**

```php
public function test_diagnostics_reports_missing_extensions_and_disabled_cron(): void
public function test_locked_settings_are_not_overwritten_by_post(): void
public function test_reveal_token_requires_valid_password(): void   // wp_check_password false → 表示用 transient が作られない
```

- [ ] **Step 2〜4: 失敗確認 → 実装 → 成功確認**。実装後、Task 19 の wp-env で画面を目視確認する

- [ ] **Step 5: コミット** — `git commit -am "feat: admin page with status, settings and connection tabs"`

---

### Task 17: Updater（GitHub Releases からの自動更新）

**Files:**
- Create: `includes/Updater.php`, `update.json`, `tests/Unit/UpdaterTest.php`
- Modify: `includes/Plugin.php`（`Updater::register()`）、`wp-pcloud-backup.php`（`WP_PCLOUD_BACKUP_UPDATE_URL` 既定値）

**Interfaces:**
- Produces:
  - `update.json` スキーマ: `{"version":"0.1.0","download_url":"https://github.com/OWNER/wp-pcloud-backup/releases/download/v0.1.0/wp-pcloud-backup.zip","sha256_url":"…/wp-pcloud-backup.zip.sha256","requires":"6.0","requires_php":"7.4","tested":"6.8","changelog":"初版"}`。`OWNER` は実装時にプレースホルダのまま置き、Task 18 の README で「最初のリリース前に置き換える」と明記（依頼者の GitHub アカウント名が未確定のため）
  - 定数 `WP_PCLOUD_BACKUP_UPDATE_URL` 既定 `https://raw.githubusercontent.com/OWNER/wp-pcloud-backup/main/update.json`。`WP_PCLOUD_BACKUP_GITHUB_TOKEN` があれば `Authorization: token …` ヘッダを付ける（非公開リポジトリ用。download_url も `api.github.com` の asset URL に差し替えが必要になるため、公開リポジトリを前提とし README に注記）
  - `Updater::__construct(Settings $s, HttpTransportInterface $http, string $updateUrl, string $pluginFile, string $currentVersion, callable $jobRunning /* fn(): bool */)`
  - `Updater::register(): void` — フィルタ `pre_set_site_transient_update_plugins`、`plugins_api`、`auto_update_plugin`、`upgrader_pre_download`、`upgrader_source_selection`（展開後のフォルダ名を `wp-pcloud-backup` に揃える）
  - `Updater::fetchManifest(bool $force = false): ?array` — transient `wp_pcloud_backup_update_manifest` 12 時間
  - `Updater::injectUpdate(object $transient): object` — `version_compare(manifest.version, current, '>')` のとき `$transient->response['wp-pcloud-backup/wp-pcloud-backup.php'] = (object)['slug'=>..., 'plugin'=>..., 'new_version'=>..., 'package'=>download_url, 'url'=>..., 'requires'=>..., 'requires_php'=>..., 'tested'=>...]`
  - `Updater::shouldAutoUpdate(bool $default, object $item): bool` — 自分のプラグインなら `auto_update` 設定 かつ `!$jobRunning()`
  - `Updater::verifyPackage($reply, string $package, \WP_Upgrader $upgrader): string|\WP_Error` — フィルタ `upgrader_pre_download` に掛ける。`$package` が `manifest.download_url` と一致するときだけ動き、`download_url($package)` で一時ファイルに落とし、`sha256_url` の内容（先頭 64 文字の hex）と `hash_file('sha256', $tmp)` を比較。一致なら一時ファイルのパスを返す（WP はそれをそのまま使う）。不一致なら一時ファイルを削除し `Notifier::updateFailed` を送って `new \WP_Error('wp_pcloud_backup_bad_checksum', ...)` を返す。自分のパッケージでなければ `$reply` をそのまま返す

- [ ] **Step 1: 失敗するテストを書く**

```php
public function test_injects_update_when_newer(): void
public function test_no_update_when_same_or_older(): void
public function test_manifest_cached_12h(): void                    // FakeTransport のリクエスト数が 1
public function test_auto_update_false_when_setting_off_or_job_running(): void
public function test_bad_sha256_returns_wp_error_and_notifies(): void  // sha256 ファイルの内容と zip のハッシュ不一致
```

- [ ] **Step 2〜5: 失敗確認 → 実装 → 成功確認 → コミット** — `git commit -am "feat: GitHub Releases updater with sha256 verification and auto-update"`

---

### Task 18: uninstall、README、readme.txt、release.sh、.pot

**Files:**
- Create: `uninstall.php`, `README.md`, `readme.txt`, `bin/release.sh`, `languages/wp-pcloud-backup.pot`
- Modify: `wp-pcloud-backup.php`（有効化時の拡張チェック）

- [ ] **Step 1: uninstall.php**

`WP_UNINSTALL_PLUGIN` 未定義なら `exit`。`wp_pcloud_backup_settings`、`wp_pcloud_backup_history`、transient、`wp_clear_scheduled_hook('wp_pcloud_backup_tick')`、作業フォルダ（`workdir_name` から）を再帰削除。pCloud 上は触らない。

- [ ] **Step 2: 有効化時チェック**

`register_activation_hook` で `zlib`/`curl`/`openssl` のいずれかが無い、または PHP < 7.4 なら `deactivate_plugins` して `wp_die` に日本語メッセージ（欠けている拡張名を含む）。

- [ ] **Step 3: README.md（日本語）**

節: 概要 / 対応環境 / 導入手順（設計書 13 章をそのまま、`OWNER` 置換の注意を含む）/ pCloud トークンの取得（5.1）/ `wp-config.php` の定数一覧 / 外部 cron の設定例（heteml・さくらのコントロールパネル、`curl -fsS 'https://…' > /dev/null`）/ **復元手順**（pCloud から日付フォルダをダウンロード → `cat files.zip.* > files.zip` → `unzip files.zip -d site`（Windows は `copy /b files.zip.* files.zip`）→ `manifest.json` の `backup_root` に配置 → `gunzip database.sql.gz` → phpMyAdmin か `mysql` で流し込む → `wp-config.php` の DB 接続情報と `siteurl`/`home` を確認）/ セキュリティ上の注意（トークンの使い回し、専用アカウント推奨、pCloud フォルダを共有しない、nginx）/ 自動更新とリリース手順 / 空ディレクトリは保存されない旨 / ライセンス GPL-2.0-or-later。

- [ ] **Step 4: readme.txt**（WordPress 形式、英語、Stable tag はバージョンに同期）

- [ ] **Step 5: bin/release.sh**

引数 `X.Y.Z`。処理: 作業ツリーがクリーンか確認 → `wp-pcloud-backup.php` の `Version:` と `WP_PCLOUD_BACKUP_VERSION`、`readme.txt` の `Stable tag`、`update.json` の `version`/`download_url`/`sha256_url` を書き換え → `build/wp-pcloud-backup/` に `.distignore` を除いてコピー（`rsync -a --exclude-from=.distignore`）→ `build/wp-pcloud-backup.zip` を作成 → `shasum -a 256 … | cut -d' ' -f1 > build/wp-pcloud-backup.zip.sha256` → `git commit -am "chore: release vX.Y.Z"` → `git tag vX.Y.Z` → `git push --follow-tags` → `gh release create vX.Y.Z build/wp-pcloud-backup.zip build/wp-pcloud-backup.zip.sha256 --title vX.Y.Z --notes-file -`（CHANGELOG 相当は引数 or stdin）。`update.json` の更新は zip 作成**前**に行う（zip の中の update.json も新バージョンを指す）。

- [ ] **Step 6: .pot 生成**

Node の `@wordpress/i18n` ではなく `npx wp-env run cli wp i18n make-pot . languages/wp-pcloud-backup.pot` を Task 19 の環境で実行（ここではコマンドを README の開発者向け節に書くだけ）。

- [ ] **Step 7: コミット** — `git add -A && git commit -m "docs: README with restore guide, uninstall, release script"`

---

### Task 19: wp-env 統合確認と実機確認

**Files:**
- Create: `.wp-env.json`, `bin/make-fixture-site.sh`, `Dockerfile.test`（Task 8 で必要になっていれば既にある）
- Modify: `README.md`（検証結果）

- [ ] **Step 1: .wp-env.json**

```json
{ "core": null, "phpVersion": "7.4", "plugins": ["."], "port": 8888,
  "config": { "WP_DEBUG": true, "WP_PCLOUD_BACKUP_STORAGE_DIR": "/var/www/html/wp-content/wppb-remote",
              "WP_PCLOUD_BACKUP_ACCESS_TOKEN": "dev-dummy-token" } }
```
`WP_PCLOUD_BACKUP_STORAGE_DIR` 定義時は `LocalStorage` を使うので、トークン検証（`userInfo`）も `LocalStorage` 用にスキップされること（Task 15 の `Plugin::boot` で判定）。

- [ ] **Step 2: 起動とフィクスチャ**

```bash
npx @wordpress/env@latest start
bin/make-fixture-site.sh 100   # wp-content/uploads/fixture/ に合計 100MB のランダムファイル（1KB〜8MB）を生成
```
`bin/make-fixture-site.sh` は `npx wp-env run cli bash -c '...'` で `head -c` から生成する。

- [ ] **Step 3: PHP 8.2 でもユニットテストが通る**

Run: `PHP_IMAGE=php:8.2-cli bin/test.sh` → PASS

- [ ] **Step 4: 管理画面からの手動バックアップ**

ブラウザで `http://localhost:8888/wp-admin/options-general.php?page=wp-pcloud-backup` を開き、「今すぐバックアップ」。進捗バーが進み、完了後に `wp-content/wppb-remote/WPBackup/localhost/<日付>/` に `manifest.json`、`database.sql.gz`、`files.zip.001…` が揃うことを確認。`.incomplete` が残っていないこと。

- [ ] **Step 5: 復元リハーサル**

```bash
cat files.zip.* > files.zip && unzip -q files.zip -d restored && diff -r restored/wp-content/uploads/fixture <wp-env の uploads/fixture>
gunzip -c database.sql.gz | npx wp-env run cli wp db import -   # 別 DB 名を使うか、tests 環境（run tests-cli）に流し込んで wp db tables の数が一致
```
Expected: diff 出力なし、テーブル数一致。

- [ ] **Step 6: 中断・再開**

バックアップ中に `npx wp-env run cli wp option get wp_pcloud_backup_settings` … ではなく、`docker kill` で PHP コンテナ（`wp-env` の `wordpress` サービス）を再起動してからページを開き直し、ジョブが続きから完走すること。

- [ ] **Step 7: 外部 URL**

`curl -i 'http://localhost:8888/?wp-pcloud-backup=run&key=WRONG'` → 403。正しい鍵（接続タブから）→ 200 + JSON。

- [ ] **Step 8: 3GB フィクスチャ（任意だが推奨）**

`bin/make-fixture-site.sh 3000` で再実行し、完走と所要時間を README に記録。

- [ ] **Step 9: pCloud 実機確認（依頼者のトークンが必要）**

`.wp-env.override.json` で `WP_PCLOUD_BACKUP_STORAGE_DIR` を外し、`WP_PCLOUD_BACKUP_ACCESS_TOKEN` に本物のトークンを入れて（このファイルは `.gitignore` 済み）Step 4 を再実行。pCloud の Web で `/WPBackup/localhost/<日付>/` を確認し、`files.zip.001` をダウンロードして sha1 が manifest と一致すること。ここで `CurlTransport` が初めて実通信で検証される。

- [ ] **Step 10: 検証結果を README に記録してコミット** — `git commit -am "test: wp-env integration setup and verification notes"`

---

## 自己レビュー結果

- **仕様カバレッジ**: 設計書 5.1（トークン取得）→ Task 16、5.3 → Task 4、6 章 → Task 2、7 章 → Task 7/8/9/11、8.1〜8.4 → Task 6〜10・14、9 章 → Task 3/5/15/16/17、10 章 → Task 13、11 章 → Task 16、12 章 → 各タスク、12.1 → Task 17、13 章 → Task 18 README、16 章 → Task 19。14 章（VPS スクリプト）は本計画の対象外と明記
- **型の一貫性**: `StorageInterface` のメソッド名（`ensureFolder/listFolder/uploadFile/renameFolder/deleteFolder/deleteFile/checksum`）を Task 10・11・14 で統一。`PartWriter::pendingUploads()/markUploaded()` を Task 7・14 で統一。`Settings::accessToken()/setAccessToken()` を Task 2・3・16 で統一
- **Review Focus**: 5 項目すべてに担当タスクのテストを割り当てた（Task 8, 8+14, 9, 10+14, 12）
- **未確定事項（実装前に依頼者へ確認）**: GitHub のアカウント名（`update.json` の `OWNER`）。確認できるまでプレースホルダで進める
