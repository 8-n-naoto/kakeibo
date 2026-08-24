# 家計簿アプリ（Laravel 13 / PHP 8.3+）

レシート画像を AI（既定は Gemini API。Claude API にも切替可）で読み取って家計簿につけ、月の収支・予算・資産を管理するためのアプリです。

## 動作要件

| 項目 | バージョン |
| --- | --- |
| PHP | 8.3 〜 8.5（`mbstring`, `pdo_mysql`, `pdo_sqlite`, `fileinfo` が必要。HEIC を JPEG に変換するには `imagick`（libheif 付き）も。無くても動きますが HEIC は未変換のまま解析に回ります） |
| Laravel | 13.x |
| PHPUnit | 12.x |
| DB（本番・開発） | MySQL 8.0 以上 |
| DB（テスト） | SQLite（インメモリ。追加のセットアップ不要） |
| Composer | 2.x |

## セットアップ（VMware上の検証環境）

```bash
cd /path/to/kakeibo

# 1. 依存パッケージのインストール
composer install

# 2. .env を用意して APP_KEY を生成
cp .env.example .env
php artisan key:generate

# 3. GEMINI_API_KEY にレシート解析用のAPIキーを設定（レシート機能を使わないなら空でも可）
#    DBの設定は手順4でユーザーを作ってから書きます

# 4. DBと専用ユーザーを作ってマイグレーション＋初期カテゴリ投入
#    アプリを root で繋がない。漏れたときに他のDBまで巻き添えになります。
mysql -u root -p <<'SQL'
CREATE DATABASE kakeibo DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'kakeibo'@'127.0.0.1' IDENTIFIED BY 'ここに長いパスワード';
GRANT ALL PRIVILEGES ON kakeibo.* TO 'kakeibo'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

#    ここで決めたパスワードを .env の DB_PASSWORD に書く（DB_USERNAME は kakeibo のまま）。
#    DB_HOST は 127.0.0.1 のままにすること。localhost に変えると UNIX ソケット接続になり、
#    上の GRANT（'kakeibo'@'127.0.0.1'）では繋がりません。
php artisan migrate --seed

# 5. ログイン用のユーザーを作成（このアプリは単一ユーザー前提）
php artisan user:create you@example.com --name=あなたの名前
#    --password を付けなければ入力を求められます（画面には表示されません）
#    パスワードは 12文字以上・72バイト以内（bcrypt が72バイトで切り捨てるため。
#    日本語なら24文字までが目安）

# 6. アップロード画像を公開するためのシンボリックリンク
php artisan storage:link

# 7. 起動（動作確認用。常用は php-fpm + nginx/Apache。「セキュリティと公開範囲」を参照）
php artisan serve --host=0.0.0.0 --port=8000
```

`composer install` を実行すると `vendor/` が作られます。フロントは Tailwind CSS / Chart.js を CDN から読み込むため、`npm install` や `npm run build` は不要です。

## テストの実行

テストは SQLite のインメモリDBで動くため、MySQL の状態に影響しません。

```bash
php artisan test                       # 全テスト
php artisan test --testsuite=Unit      # ユニットテストのみ
php artisan test --testsuite=Feature   # 機能テストのみ
php artisan test --filter=BudgetTest   # クラス単位
composer test                          # 設定キャッシュを消してから全テスト
```

`php artisan test` が `could not find driver` で失敗する場合は、PHP の `pdo_sqlite` 拡張を有効にしてください。

### テスト構成

| ファイル | 内容 |
| --- | --- |
| `tests/Unit/CsvImportServiceTest.php` | CSVの日付・金額パース、店名からのカテゴリ推測 |
| `tests/Unit/ReceiptValueNormalizerTest.php` | AI応答の正規化（文字列・金額・日付・品目。DB不要） |
| `tests/Unit/MonthParserTest.php` | `YYYY-MM` の解析と範囲チェック（DB不要） |
| `tests/Unit/SupportedReceiptImageTest.php` | アップロード画像のシグネチャ判定（DB不要） |
| `tests/Unit/BudgetStatusTest.php` | 予算消化率のしきい値判定 |
| `tests/Unit/CategoryNatureTest.php` | 固定費/変動費の判定 |
| `tests/Unit/SavingsGoalProgressTest.php` | 貯蓄目標の残額・進捗率 |
| `tests/Feature/TransactionCrudTest.php` | 取引のCRUDと月・カテゴリ絞り込み |
| `tests/Feature/TransactionFilterTest.php` | 取引一覧のキーワード・期間・金額・種別の絞り込みと合計表示、CSVへの条件引き継ぎ |
| `tests/Feature/TransactionBulkUpdateTest.php` | カテゴリの一括変更（対象の範囲、学習の有無、未ログイン時） |
| `tests/Feature/CategoryCrudTest.php` | カテゴリ管理 |
| `tests/Feature/BudgetTest.php` | 予算のCRUD、消化率、超過アラート |
| `tests/Feature/BudgetSuggestionTest.php` | 過去実績からの予算提案（中央値、固定費は直近、記録の無い月の扱い、丸め、選んだものだけ登録） |
| `tests/Feature/MonthlyReportTest.php` | 固定費/変動費の分離、前年同月比 |
| `tests/Feature/ImportBatchTest.php` | CSV取込1回ぶんの記録とまとめての取り消し（編集済みの行を残すこと、二度目の取り消し、未ログイン時） |
| `tests/Feature/TransactionImportTest.php` | カード明細CSVの取込（Shift_JIS／タブ区切り／前置き行／引用符内の改行／出金入金2列／和暦・2桁年・年なし日付／小数金額／重複検知／レシートとの突合／列の手動指定と記憶／取り込まないルール／二重送信と行欠落の防止） |
| `tests/Feature/MerchantCategoryRuleTest.php` | 店名→カテゴリの学習、優先順位、手動ルールの追加・削除、辞書と同じ答えは覚えないこと |
| `tests/Feature/RecurringTransactionTest.php` | 定期支出のCRUD、月次計上、二重計上の防止、月末丸め、Artisanコマンド |
| `tests/Feature/AnnualReportTest.php` | 年間サマリーの集計（月別・カテゴリ別・固定変動・エンゲル・資産増減） |
| `tests/Feature/SpendingAnomalyTest.php` | 異常支出の検知（急増・単発高額・実績不足時に通知しないこと） |
| `tests/Feature/AuthenticationTest.php` | ログイン・ログアウト、未ログイン時のリダイレクト、連続失敗のロック、IPを変えても効く回数制限、失敗のログ記録 |
| `tests/Feature/PwaAssetTest.php` | マニフェスト・アイコン・Service Worker の整合性（HTMLをキャッシュしないこと含む） |
| `tests/Feature/ReceiptImageCleanerTest.php` | 不要なレシート画像の掃除（取引つきは消さないこと、猶予期間、孤児ファイル） |
| `tests/Feature/DashboardTabTest.php` | ダッシュボードのタブ構成と、集計データが従来どおり渡ること |
| `tests/Feature/ReviewFixesTest.php` | レビューで見つかった不具合の再発防止（不正な入力、掃除の安全性、二重計上、壊れた解析結果） |
| `tests/Feature/TransactionExportTest.php` | 取引のCSV出力、数式インジェクション対策（`=` で始まる店名）、末尾バックスラッシュで列がずれないこと |
| `tests/Feature/ReceiptUploadTest.php` | レシート画像のアップロード（保存のみ）、HEIC の受付、中身に合わせた拡張子での保存 |
| `tests/Feature/ReceiptParseTest.php` | 1枚ずつの読み取り、JSON応答、失敗時の記録と再試行、読み取り済みの再読み取り、要確認レシートの並び（API はモック） |
| `tests/Feature/ReceiptBulkUploadTest.php` | 複数枚の一括アップロード、未登録レシート一覧、一括登録と二重送信の防止 |
| `tests/Feature/ReceiptItemSplitTest.php` | レシートを品目ごとに分割して登録、合計との差額の案内 |
| `tests/Feature/GeminiReceiptParserTest.php` | Gemini API 応答の解析（`Http::fake`） |
| `tests/Feature/ClaudeReceiptParserTest.php` | Claude API 応答の解析（`Http::fake`） |
| `tests/Feature/ReceiptParserDriverTest.php` | `RECEIPT_AI_DRIVER` によるAIの切替 |
| `tests/Feature/AssetSnapshotTest.php` | 資産スナップショット |
| `tests/Feature/SavingsGoalTest.php` | 貯蓄目標 |
| `tests/Feature/InvestmentAccountTest.php` | NISA/iDeCo |
| `tests/Feature/DashboardEngelCoefficientTest.php` | エンゲル係数（対象カテゴリの設定・複数選択・未設定時） |
| `tests/Feature/BackupTest.php` | バックアップと復元（全消し→復元の往復、NULLと空文字の区別、バックスラッシュ・改行・引用符などの往復、件数の突き合わせ、画像、壊れたバックアップの拒否、テーブルや列が増える前のバックアップ、世代管理） |

## レシート解析に使うAIの設定

レシート画像の解析はどちらのAIでも動きます。`.env` の `RECEIPT_AI_DRIVER` で切り替えます（既定は `gemini`）。

| 変数 | 既定値 | 説明 |
| --- | --- | --- |
| `RECEIPT_MAX_FILES_PER_UPLOAD` | `20` | 1回のアップロードで受け付ける枚数（1〜50。範囲外は丸められます） |
| `RECEIPT_AI_DRIVER` | `gemini` | `gemini` または `claude` |
| `RECEIPT_AI_DAILY_LIMIT` | `200` | 1日にAIへ投げられる枚数の上限（1〜1000）。AI呼び出しは1枚ごとに課金されるので、画面が繰り返し読み取ってしまっても請求が頭打ちになるようにしてあります |
| `GEMINI_API_KEY` | （空） | https://aistudio.google.com/apikey で発行 |
| `GEMINI_MODEL` | `gemini-3.6-flash` | 精度を上げたいなら `gemini-3.7-flash`、安く済ませたいなら `gemini-3.5-flash-lite` |
| `GEMINI_API_URL` | Interactions API のURL | 通常は変更不要。エンドポイント仕様が変わったときだけ上書き |
| `GEMINI_API_REVISION` | `2026-05-20` | 同上。空にするとヘッダーを送らない |
| `ANTHROPIC_API_KEY` / `ANTHROPIC_MODEL` | — | `RECEIPT_AI_DRIVER=claude` のときのみ使用 |

`.env` を変更したら設定キャッシュを消してください。

```bash
php artisan config:clear
# php-fpm 経由で動かしている場合は
sudo systemctl reload php-fpm
```

実装は `app/Services/ReceiptParser.php`（インターフェース）を `GeminiReceiptParser` と
`ClaudeReceiptParser` が実装し、`AppServiceProvider` が driver に応じてどちらかを注入します。
プロンプト・応答JSONの取り出し・戻り値の整形は `AbstractReceiptParser` に共通化してあるので、
別のAIを足す場合もこのクラスを継承して API 呼び出しだけ書けば済みます。

## 機能

### 入力
- 手動入力（日付・種別・カテゴリ・店名・メモ・金額）
- レシート画像のアップロード → AI（Gemini / Claude）で読み取り → 確認画面で修正して登録
  - **アップロードと読み取りは別のリクエストに分けています。** アップロードは保存するだけなので何枚でも待たされず、読み取りは「未登録レシート」画面で1枚ずつ順番に実行して進み具合が見えます
    - 以前は1リクエストの中で枚数分のAI呼び出しを行っていたため、`枚数 × 最大60秒` が php-fpm / httpd のタイムアウトを超えると 502/504 で途中終了していました
    - 途中で画面を閉じても、残りは次に開いたときに続きから読み取れます
    - 読み取りに失敗したレシートは画面下部に残り、1枚ずつ「読み取り直す」か、パネルの「失敗した分も含めて再試行する」でまとめて再試行できます（一時的なAPIエラーからの復帰用）。恒久的に読めない写真は、その場から単票の確認画面へ行って手入力できます
    - 同じレシートを別タブや二重送信で同時にAIへ送らないようロックしています（**AIの課金が二重に発生するのを防ぐため**）。読み取り済みのものは自動では読み直しませんが、**「AIで読み直す」ボタンから人が明示的に頼めば読み直せます**（AIが「JSONとしては妥当だが中身の無い」結果を返したときに、二度と読ませられない状態にならないように）
    - **目で確かめたほうがよいレシートを先頭に出します。** 合計金額または日付が読めなかったもの、品目の合計が総額と1割以上ずれているもの（品目の読み落とし）に黄色い印を付けます
    - **金額も店名も取れなかった応答は失敗として扱います。** 成功として保存すると「読み取り済みだが中身が空」で固まってしまうためです
    - 大きな写真は保存時に**長辺2000pxへ縮小**します（Imagick がある環境のみ）。iPhoneの写真はそのままだと5〜10MBあり、Claude の受け取り上限を超えて大きいレシートが毎回失敗するほか、VMのディスクも食います。ついでに EXIF（撮影場所を含む）も落とします
    - 読み取り中にログインが切れた場合は、残りを無駄打ちせずその場で止めて知らせます
  - **一度に最大20枚**まで選べます（`.env` の `RECEIPT_MAX_FILES_PER_UPLOAD` で変更可）。「未登録レシート」画面に並ぶので、内容を直してチェックしたものだけをまとめて登録できます（週1〜月1のまとめ処理向け）
    - 枚数を増やすときは php.ini の `post_max_size` / `upload_max_filesize` を確認してください（読み取り側のタイムアウトはもう関係ありません）
    - 合計サイズが `post_max_size` を超えると PHP がリクエストの中身ごと捨てるため、素のままだと「419 Page Expired」の白い画面になります。`bootstrap/app.php` でこれを検出して「ファイルの合計サイズが大きすぎます」に差し替えています
  - 読み取りに失敗した画像は「未登録レシート」画面の下部にエラー内容とともに残ります
  - 読み取れた品目が複数あるときは「品目ごとに分ける」を選べます。1枚のレシートを品目単位の取引に分割して、食費と日用品を別カテゴリで計上できます（品目名はメモ欄に入ります）
  - 対応形式は JPG / PNG / WebP / GIF / **HEIC・HEIF・AVIF**（iPhone 標準の写真を含む）。HEIC / AVIF は保存時に JPEG へ変換します（`Imagick` が入っていない環境では変換せずそのまま解析に回します）
    - 判定は Laravel の `mimes` ルールではなく `app/Rules/SupportedReceiptImage.php` で行っています。`mimes` は「中身から推定した MIME タイプ」で見るため、HEIC を知らない libmagic の環境では正しい写真まで弾かれてしまうためです。代わりに「拡張子」と「ファイル先頭のシグネチャ」の両方を確認します
    - 保存時の拡張子はシグネチャから決めます。端末側でリネームされた「中身は PNG なのに名前は .jpg」をそのまま保存すると、解析時に `image/jpeg` と申告して PNG を送ることになり API に弾かれるためです
  - 解析結果は `receipt_images.parsed_data` に保存されるため、確認画面をリロード・ブラウザバックしても内容が消えません（API を再度呼ぶ必要がありません）
  - 一度登録したレシートからは再登録できません（ブラウザバックの再送信による二重計上を防止）。金額を直すときは取引一覧から編集します
- クレジットカード明細CSVの取込（Shift_JIS / UTF-8 自動判定、カンマ／タブ／セミコロンの自動判定、列の自動検出、店名からのカテゴリ推測、重複候補の自動チェック解除）
  - ヘッダー行はファイル先頭10行から探します（「○○カードご利用代金明細」のような前置き行があるCSVが多いため）
  - 出金列と入金列が分かれている銀行CSV（`お支払金額` / `お預り金額`）にも対応します
  - 日付は `2026/01/05` `2026年1月5日` `20260105` `26/01/05` `令和8年1月5日` `2026/01/05 12:34` `2026/01/05(月)` を読みます。年が書かれていない `12/25` はファイル内の他の行から年を補い、補ったことを画面に出します
  - 金額は `1,234` `¥1,234` `1234.00` `-1234` `△1,234` `(1,234)` を読みます。**読めない値は「読み取れませんでした」と赤字にして、もっともらしい数字をでっち上げません**（以前は数字だけを拾っていたため `1234.00` が 123,400 に、`2026-08-01` が −20,260,801 の「収入」になっていました）。`1.234` のようにヨーロッパ式の桁区切りとも小数とも読める値も、当てずっぽうで決めずに赤字にします
  - 区切り文字は「同じ列数の行がいちばん多く並ぶもの」で決めます（出現回数で決めると、摘要欄のカンマでタブ区切りが誤判定されます）
  - ヘッダー行は「日付と金額の両方が見つかる行」を優先します。見つからないときは候補行に点数を付けて選びます（`合計金額,52340` のような前置き行に負けないように）
  - 同じファイルの中に内容がまったく同じ行があれば「◯行目と同じ内容」と出しますが、**落としません**（同じ日に同じ店で2回買うのは普通にあるため）
  - **レシートから登録済みの支払いを候補として知らせます。** 同じ買い物を「その日にレシートで登録」→「月末にカード明細で取込」の2経路で入れると支出が二重になりますが、店名の書き方が違う（レシート「スーパーライフ 中野店」／CSV「ﾗｲﾌ ﾅｶﾉ」）ので通常の重複判定には引っかかりません。「金額が完全一致・3日以内・レシート由来」の取引があれば候補として並べ、既定でチェックを外します（禁止ではないので、違うと判断したら付け直せます）
  - プレビューに「読み取れた合計」を出します。明細の請求額と見比べて、列の誤検出や行の抜けに気づけるようにするためです
  - 一度に取り込めるのは2,000行までです
  - **列の読み取りがおかしいときは、プレビュー画面で列の対応を手で指定して読み直せます**（再アップロード不要）。日付・店名・出金・入金の列とヘッダー行を選ぶだけです。登録まで進むと、その指定を**同じ並びのCSV用に覚えます**（同じカード会社の明細は毎月同じ形で来るので、2回目からは自動で当たります）
  - **取り込みたくない店を覚えられます。** プレビューの「今後取り込まない」にチェックすると、次回から既定でチェックが外れます（口座振替のカード引き落とし行や、定期支出として自動計上済みの家賃など）。**行を落とすのではなく、既定のチェックを外すだけ**です。黙って消すと、ルールを作ったことを忘れたころに「なぜか支出が少ない」という気づけない事故になります。登録した店は取込画面の下に並び、いつでも解除できます
  - **取込1回ぶんを記録し、`/imports/batches` からまとめて取り消せます。** 誤取込に気づいたとき、200件を手で消すしか復旧手段が無いと実質やり直せないためです。取り込んだあとに手で直した行は、取り消しの巻き添えでは消しません
  - 確定時に、**解析した行数と送信された行数が違えば登録しません**（PHP の `max_input_vars` 既定値1000だと約166行で後半が黙って切り捨てられるため）。同じプレビューからの二度目の送信も受け付けません
- 自動分類ルール（店名 → カテゴリ）：取引を登録・更新するたびに「この店名はこのカテゴリ」を学習し、次回のCSV取込・レシート解析で最優先に適用します。`/merchant-rules` で確認・追加・修正・削除できます
  - 優先順位はレシートとCSVで少し違います
    - レシート: 学習ルール（完全一致 → 部分一致で長いパターン優先） → AI の提案 → キーワード辞書
    - CSV取込: 学習ルール → キーワード辞書（CSVにはAIの提案が無いため）
  - 画面から手で登録したルールは、自動学習で勝手に書き換わりません
  - **キーワード辞書と同じ答えになる場合は、学習ルールを作りません。** CSV取込は200行まとめて登録できるので、辞書の推測がそのまま「学習済みルール」に昇格すると、誤った推測が誰にも見られないまま以後すべての推測より優先されてしまうためです。辞書と違うカテゴリにしたときだけ覚えます
  - 英字のキーワードは**単語として**照合します（部分一致だと `BEAUTY SALON` が通信費（au）、`PANASONIC` が交通費（ana）、`SKETCH BOOK` が交通費（etc）に化けます）。日本語のキーワードは長いものから順に照合します（`業務スーパー` が `スーパー` に、`ガスト` が `ガス` に負けないように）

- 定期支出（家賃・保険・サブスクなど）：毎月の計上日・有効期間つきで登録しておき、「定期支出」画面のボタンで当月分をまとめて計上。すでに計上済みのものはスキップするので二重計上になりません
  - ダッシュボードに「未計上の定期支出が N 件あります」と出ます
  - `php artisan recurring:post --month=2026-08` でも同じ処理ができます。cron で `schedule:run` を回している環境では毎月1日 03:00 に自動実行されます

- 使われていないレシート画像の掃除：家計簿に登録されていない画像を「未登録レシート」画面のボタンでまとめて削除できます。**取引が紐づいている画像には touch しません**（削除の直前にもう一度確認します）
  - 対象は4種類：読み取りに失敗したもの / 読み取り済みで未登録のもの / **読み取り待ちのまま放置されたもの** / DBに行が無い孤児ファイル。いずれも既定30日より前のものだけ
  - 「読み取り待ちのまま放置」は画面で別に数えて注意書きを出します（先に読み取れば残せるため）
  - `php artisan receipts:prune --dry-run` で内訳だけ確認、`php artisan receipts:prune --days=90` で猶予日数を変更できます（1以上）
  - cron で `schedule:run` を回している環境では毎週日曜 04:00 に自動実行されます

- PWA 対応：スマホで開いて「ホーム画面に追加」すると、ブラウザのUIなしで単体アプリのように起動します（`public/manifest.webmanifest`）。長押しのショートカットから「レシートを読み込む」「未登録のレシート」「手動で入力する」に直接飛べます
  - Service Worker（`public/sw.js`）は **画面（HTML）を一切キャッシュしません**。CSRFトークン入りのページを配ると次の送信が 419 で落ちるためです。担当はオフライン時の案内（`public/offline.html`）とアイコンだけです
  - キャッシュを作り直したいときは `public/sw.js` の `CACHE_VERSION` を上げてください

### 集計・管理
- 月次ダッシュボード：「今月」「資産」「振り返り」の3タブ構成。予算アラート・未計上の定期支出・気になる支出はタブの外に常時表示します
  - 今月：収入・支出・収支、エンゲル係数、月別収支の推移、カテゴリ別円グラフ、最近の取引
  - 資産：総資産の推移、貯蓄目標、NISA/iDeCo
  - 振り返り：固定費／変動費、前年同月比
  - データは1リクエストで全部返して表示だけ切り替えます（切替が即座に終わるのと、テストが素直に書けるのを優先しました）。選んだタブは端末側に記憶されます
  - エンゲル係数の分子に数えるカテゴリはカテゴリ管理画面のチェックで決めます（初期値は「食費」のみ。家計調査の定義に合わせるなら「外食」も含めてください）
- 予算管理：支出全体／カテゴリ別、毎月のデフォルト予算と月指定予算、消化率80%で「要注意」・100%超で「予算超過」アラート（予算0円のカテゴリで支出があるときも「予算超過」として扱います。0%＝順調と表示すると、予算が無いときより気づけなくなるため）
- 過去実績からの予算提案（`/budgets/suggestions`）：直近◯ヶ月（既定6ヶ月）の実績から、カテゴリごとの予算の目安を出します
  - **変動費は中央値**を使います。平均だと、1回の家電購入や旅行で予算が跳ね上がってしまうため
  - **固定費は直近の実績**を使います。家賃や通信費は上がったら上がったままなので、中央値だと古い安い金額に引きずられるため
  - 支出全体は「月ごとの合計の中央値」。カテゴリ別の提案を足すと、たまたま同じ月に重ならない支出まで合算されて過大になります
  - 実績のある月が3ヶ月に満たないときは提案しません（当てずっぽうを「提案」と言い換えないため）
  - 提案は自動では登録しません。数字を直したうえで、チェックしたものだけを「毎月のデフォルト予算」または「その月だけの予算」として登録します
- 異常支出の検知：予算を決めていないカテゴリでも「直近6ヶ月の平均の1.5倍以上かつ差額3,000円以上」の急増と、「同カテゴリの平均単価の3倍以上かつ1万円以上」の単発支出をダッシュボードで知らせます（実績が少ないうちは通知しません）
- 固定費／変動費の分離と固定費率の表示（カテゴリ管理画面で分類を変更可能）
- 前年同月比（収入・支出・カテゴリ別の差額と増減率）
- 資産スナップショット（現金・NISA・iDeCo・その他投資）と総資産推移
- 貯蓄目標（進捗率・月あたり必要貯蓄額）
- NISA/iDeCo の年間投資枠の消化率・含み損益
- 取引のカテゴリを一括変更：チェックした取引、または絞り込み結果すべてをまとめて別カテゴリに移せます。自動分類ルールを直したあと、過去分を揃えるための機能です
  - 「この店名を次回から自動でこのカテゴリにする」にチェックしたときだけ、自動分類ルールにも反映します（寄せ集めをまとめて移したときに良いルールを壊さないため）
  - 元に戻せないので、実行前に対象件数を確認するダイアログを出します

- 取引一覧の検索・絞り込み：店名／メモのキーワード、月、期間（開始〜終了）、カテゴリ、種別、金額レンジ、並び順。絞り込み結果の件数・収入・支出・収支をヘッダーに表示
- 年間サマリー（`/reports`）：年間の収入・支出・収支と前年比、月別の棒グラフ、カテゴリ別の年間額・月平均・構成比・前年差、固定費率、年間エンゲル係数、その年の総資産の増減。いちばん貯まった月／厳しかった月も出ます
- 取引のCSV出力（Excel で開ける UTF-8 BOM 付き）。一覧で絞り込んだ条件をそのまま引き継いで出力
- 家計データのバックアップと復元（`php artisan kakeibo:backup` / `kakeibo:restore`）。テーブルごとのCSVと `manifest.json` を1つの zip にまとめます。毎週日曜 04:30 に自動取得（8世代まで保持）

## 主要なディレクトリ

```
app/Http/Controllers/   画面ごとのコントローラ
app/Models/             Eloquent モデル
app/Services/           BudgetService（予算集計）/ MonthlyReportService（固定変動・前年比）
                        CsvImportService（明細CSV解析）/ TransactionCsvExporter（CSV出力）
                        ReceiptParser（インターフェース）/ AbstractReceiptParser（共通処理）
                        GeminiReceiptParser・ClaudeReceiptParser（レシート画像の解析）
                        ReceiptImageStore（画像の保存とHEIC/AVIF→JPEG変換）
                        TransactionFilter（一覧とCSV出力で共通の絞り込み条件）
                        MerchantCategoryGuesser（店名→カテゴリの学習ルールとキーワード辞書）
                        RecurringTransactionService（定期支出の月次計上）
                        AnnualReportService（年間サマリーの集計）
                        SpendingAnomalyService（いつもより多い支出の検知）
                        ReceiptImageCleaner（使われていないレシート画像の掃除）
app/Observers/          TransactionObserver（取引の保存時に店名→カテゴリを学習）
app/Http/Middleware/    SecurityHeaders（全応答のセキュリティヘッダーとCSP）
app/Rules/              SupportedReceiptImage（アップロード画像のシグネチャ判定）
app/Support/            MonthParser（YYYY-MM の解析）
                        ReceiptValueNormalizer（AI応答の型と表記の正規化）
                        DomainLimits（金額・日付の範囲とカテゴリの種別スコープ）
config/security.php     CSPの強制切替とHSTS
database/migrations/    テーブル定義
database/seeders/       初期カテゴリ（固定費/変動費の初期分類つき）
resources/views/        Blade テンプレート
resources/views/errors/ 429（試行回数の上限に達したときの画面）
resources/views/partials/  category-options（カテゴリの選択肢を収入／支出で分けて出す）
tests/                  PHPUnit テスト
```

## 日本語表示

バリデーションのメッセージと項目名は `lang/ja/` に置いています（`validation.php` / `auth.php` / `pagination.php`）。
このファイルが無いと Laravel 標準の英語メッセージが出るため、画面だけ英語になります。

**`.env` に `APP_LOCALE=ja` が必要です。** これが無いと `lang/ja/` は読まれず、
「The 名前 field is required.」のような英語表示に戻ります（既存の `.env` を使い回している場合は追加してください）。

```bash
grep APP_LOCALE .env || echo 'APP_LOCALE=ja' >> .env
php artisan config:clear
```

項目名（「金額」「日付」など）は `lang/ja/validation.php` の `attributes` に集約しています。
コントローラ側でインラインに書かず、新しい項目を足したときはここに追加してください。

## テストと認証

アプリのほぼ全ルートが `auth` の内側にあるため、`tests/TestCase.php` が既定でログイン済みの状態を作ります
（DBに保存しないダミーユーザーを `actingAs` するだけなので、users テーブルが無いテストでも動きます）。
認証そのものを検証するテストは、クラスに `protected bool $authenticateByDefault = false;` を書いて無効化します。

## 動作確認（VM 上）

```bash
cd /共有マウント先/kakeibo
bash verify.sh
```

- `_verify/report.log` … 全出力
- `_verify/summary.txt` … **要点だけ**（件数・失敗したテスト名・失敗の詳細・HTTPスモークの結果・laravel.log の末尾）

不具合を相談するときは、まず `summary.txt` を貼れば足ります。
`FORCE_INSTALL=1 bash verify.sh` で `composer install` を強制実行できます。

## 更新を反映するとき

新しいマイグレーションが増えているので、コードを差し替えたら VM 上で次を実行してください（`sudo` は付けないこと）。

```bash
cd /var/www/apps/kakeibo
php artisan migrate                        # 2026_04_01_000001〜000010 を適用
#   000006 は transactions に一意制約を張ります。すでに二重計上された取引があると
#   マイグレーション内で「2件目以降の定期支出との紐付け」を外します（行は消しません）
php artisan user:create you@example.com    # 認証を入れたので初回のみ必要
php artisan config:clear                   # config/security.php を足したので必須
php artisan view:clear
php artisan route:clear
php artisan test               # 検証環境で流す場合
```

`.env` で調整できるキー（すべて `.env.example` に載っています。省略時は下の既定値）:

| キー | 既定 | 説明 |
| --- | --- | --- |
| `RECEIPT_AI_DAILY_LIMIT` | `200` | 1日にAIへ投げられるレシート枚数の上限 |
| `SESSION_SAME_SITE` | `lax` | 多層防御の1枚目。本命はCSRFトークンです。`none` は `Secure` 必須なので、http 運用では Cookie ごと拒否されてログインできなくなります |
| `CSP_ENFORCE` | `false` | CSPを強制する。切り替え手順は「セキュリティと公開範囲」 |
| `HSTS_ENABLED` / `HSTS_MAX_AGE` | `false` / `31536000` | https にしてから |

追加されたテーブル・カラム:

| 対象 | 内容 |
| --- | --- |
| `receipt_images.parsed_data` | AI の解析結果（確認画面のリロード対策） |
| `categories.counts_as_food` | エンゲル係数の対象カテゴリ（既存の「食費」は自動でON） |
| `merchant_category_rules` | 店名 → カテゴリ の自動分類ルール |
| `transactions` の一意制約 | 同じ定期支出を同じ日に二度計上できないようにする |
| `recurring_transactions` | 定期支出（家賃・サブスクなど） |
| `transactions.recurring_transaction_id` | どの定期支出から計上されたか（二重計上の判定に使う） |
| `import_batches` | CSV取込1回ぶんの記録（まとめて取り消すために使う） |
| `transactions.import_batch_id` | どのCSV取込で入ったか |
| `import_profiles` | CSVの列の対応を、カード会社ごとに覚えたもの |
| `import_ignore_rules` | CSVから「取り込まない」店名のルール |

## セキュリティと公開範囲

このアプリは **家庭内LANの1台（VM）で、単一ユーザーが使う**前提です。インターネットに直接出す想定はしていません。
それでも「置いてあるデータ」はレシート画像・全取引・資産残高・AIのAPIキーなので、最低限これだけは守ってください。

### 必ず守ること

| 項目 | なぜ |
| --- | --- |
| `APP_DEBUG=false`（本番） | true だと例外画面に **`.env` の中身（APIキー・DBパスワード）がそのまま出ます**。`.env.example` は false です。 |
| ドキュメントルートは `public/` に向ける | ここを間違えると `http://<VMのIP>/.env` がそのままダウンロードできます。**これだけは必ず確認してください**（下の「公開前チェック」）。 |
| DBはアプリ専用ユーザーで繋ぐ | `root` で繋いでいると、漏れたときに他のDBまで巻き添えになります。 |
| 8000 / 3306 をLANの外に開けない | VMのファイアウォールで塞ぐ。MySQL は `bind-address=127.0.0.1` のままにする。 |
| `composer.lock` をコミットする | 無いと環境ごとに違うバージョンが入り、`composer audit` の結果が意味を持ちません。 |

### 常用は php-fpm + nginx/Apache

`php artisan serve` は **動作確認用**です。常用しないでください。

- シングルスレッドです。レシートをAIに読ませている間（1枚あたり数秒〜数十秒）、**他のリクエストが全部止まります**。
- TLS に対応していません。
- Laravel 自身が本番用ではないと明示しています。

`verify.sh` の HTTPスモークテストも `artisan serve` を使いますが、あれは使い捨てのSQLiteに対して数十秒動かすだけなので問題ありません。

httpd の構築手順そのものはこのREADMEの範囲外ですが、最低限これだけは押さえてください。

- **ドキュメントルートは `/var/www/apps/kakeibo/public`**（プロジェクト直下ではない）
- `storage/` と `bootstrap/cache/` を php-fpm の実行ユーザーが書けるようにする
- `.env` は 600 でアプリの実行ユーザーだけが読めるようにする

このあとの「公開前チェック」は、httpd が立っている前提の確認です。

> `config/database.php` の既定値は `env('DB_USERNAME', 'root')` のままです。`.env` に `DB_USERNAME` の行が**無い**環境では root で繋ぎにいくので、行があることを確認してください。

### 入れてあるレスポンスヘッダー

`app/Http/Middleware/SecurityHeaders.php` が、すべての応答に付けます（グローバルの一番外側に置いてあるので、404 や 419 の画面にも付きます）。

| ヘッダー | 値 |
| --- | --- |
| `X-Content-Type-Options` | `nosniff` |
| `X-Frame-Options` | `DENY` |
| `Referrer-Policy` | `same-origin` |
| `Cross-Origin-Opener-Policy` | `same-origin`（https のときだけ効きます） |
| `Permissions-Policy` | `camera=(self), microphone=(), geolocation=(), payment=(), usb=()` |
| `Content-Security-Policy(-Report-Only)` | HTMLの応答にだけ。既定は Report-Only |
| `Strict-Transport-Security` | https かつ `HSTS_ENABLED=true` のときだけ |

**`/storage/` のレシート画像には、このミドルウェアは効きません。** Webサーバーが直接返すためです。ここで効かせたいのは `nosniff` の1つだけです（アップロードされたファイルを別のMIMEとして解釈させない）。

Apache:

```apache
# mod_headers が要ります。無いまま Header を書くと httpd が起動しません
# （Debian/Ubuntu は sudo a2enmod headers）
<IfModule mod_headers.c>
    <Location /storage>
        Header always set X-Content-Type-Options "nosniff"
    </Location>
</IfModule>
```

nginx:

```nginx
location /storage/ {
    add_header X-Content-Type-Options "nosniff" always;
}
```

`public/storage` はシンボリックリンクです。ここで 403 になる場合、原因は `<Location>` ではなく `<Directory>` 側です（`Options FollowSymLinks` は `<Location>` の中では効きません）。

```apache
<Directory "/var/www/apps/kakeibo/public">
    Options FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
```

### CSP を強制に切り替える手順

CSP は既定で **Report-Only**（違反はブラウザのコンソールに出るだけで、画面は壊れません）。強制に切り替えるのは、壊れないことを目で確かめてからにしてください。

1. `php artisan config:clear`（`config/security.php` は後から足した設定ファイルなので、古い `config:cache` が残っていると設定が丸ごと効きません）
2. ブラウザの開発者ツールを開いたまま、**ログイン画面 / ダッシュボード（各タブ）/ 年間まとめ / レシート読み取り / 未登録レシート / CSV取込のプレビュー** を一通り開く
   - ログイン画面を忘れないこと。ここが壊れると、CSPを戻すためのログインができなくなります
3. コンソールに CSP 違反が出ないことを確認する（違反は `POST /csp-report` にも送られ、`storage/logs/laravel.log` に「CSP違反レポート」として残ります）
4. 違反が無ければ `.env` に `CSP_ENFORCE=true` を書いて `php artisan config:clear`

違反が出た場合は、`SecurityHeaders::contentSecurityPolicy()` に足りないディレクティブを追加してから切り替えます。外部の**スクリプト**を増やしたときは `SecurityHeaders::SCRIPT_ORIGINS` に足します。

外部のCSSやフォントを足す場合は `SCRIPT_ORIGINS` では足りません。`style-src` / `font-src` は外部オリジンを1つも許していないので、`contentSecurityPolicy()` のほうを直してください。`SecurityHeadersTest` は「そのオリジンがCSPのどこかに書いてあるか」しか見ないので、**テストが通ることは十分条件ではありません**。

### 外部から読み込んでいるスクリプト

Tailwind CSS と Chart.js を CDN から読み込んでいます（`npm run build` を不要にするため）。**バージョンは必ず固定してください。**

| 読み込み元 | 固定 | SRI |
| --- | --- | --- |
| `https://cdn.tailwindcss.com/3.4.17` | あり | **なし**（Play CDN はハッシュを配布していないため。実機で取る作業を別途） |
| `https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.js` | あり | `sha256-KBLLiCX9xXRp6y97sFXpQpJE5ZmSBRHuR36ChJm2Mss=` |

浮動指定（`chart.js@4` のような書き方）に戻さないでください。配布元のアカウントが乗っ取られた日に、**家計簿を開いた瞬間このアプリのオリジンで他人のJSが動きます**。ログイン画面にも Tailwind が入っているので、パスワードもCSRFトークンも読まれます。

Chart.js を上げるときは、`https://data.jsdelivr.com/v1/packages/npm/chart.js@<版>?structure=flat` の `/dist/chart.umd.js` の `hash` を `integrity="sha256-..."` にそのまま貼ります。`.min.js` ではなく実ファイルを指すこと（`.min.js` は jsDelivr がその場で生成する別物で、jsDelivr 自身が動的生成ファイルに SRI を使うなと書いています）。

### CSVエクスポートを Excel で開くとき

店名とメモは**カード会社のCSVやAIの応答から来る値**で、アプリが作ったものではありません。`=` `+` `-` `@` タブ・改行で始まる値は表計算ソフトが数式として実行してしまうため、エクスポート時に先頭へ `'` を付けています。

- Excel でCSVをダブルクリックして開いた場合、この `'` はセルに表示されません。**「データ → テキストまたはCSVから」で取り込んだ場合と、LibreOffice Calc のテキストインポートでは、`'` がそのまま文字として入ります。** 数式が勝手に走るよりはましなので、この形にしています。
- 書き出したCSVをこのアプリに取り込み直したときは、取込側でこの `'` を外します（往復するたびに増えないように）。
- CSVのエスケープ文字は使っていません（RFC4180）。既定の `\` のままだと、「半額シール\」のように末尾がバックスラッシュの店名で以降の列と行を飲み込んで静かに壊れます。

### 依存パッケージの脆弱性チェック

月に1回、開発機かVMで実行してください。

```bash
composer audit           # 既知の脆弱性を照会
composer outdated --direct   # 直接依存の更新有無
```

更新したら `composer.lock` をコミットし、`php artisan test` を通してから反映します。

### LANの外に出すときのチェックリスト

いまの構成のままインターネットに出さないでください。出す必要が生じたときは、最低限これを全部やってから。

- [ ] リバースプロキシで TLS 終端する（Let's Encrypt など）。`artisan serve` の直公開は不可
- [ ] `.env` の `APP_URL` を `https://...` にする
- [ ] `.env` に `SESSION_SECURE_COOKIE=true`（Cookie が http に付いていかないように）
- [ ] `.env` に `HSTS_ENABLED=true`。`HSTS_MAX_AGE` は最初は短く（例 `300`）して、問題なければ伸ばす
- [ ] プロキシの背後に入るので `bootstrap/app.php` の `withMiddleware()` 内で `$middleware->trustProxies(at: '...')` を設定する
  - しないと `$request->secure()` が false のままになり、**HSTS が送られず、`url()` / `route()` / リダイレクトが `http://` のまま**になります（混在コンテンツでブロックされます）
  - Secure Cookie はこれとは独立で、`SESSION_SECURE_COOKIE=true` だけで効きます
- [ ] `CSP_ENFORCE=true`
- [ ] ログイン失敗のロックで足りるか見直す。可能なら IP 制限や VPN を前段に置く
  - いまの挙動は3段構え。①「同じ**メールアドレス＋IP**からの失敗が5回で、最初の失敗から60秒ロック」（`LoginController`）②「同じ**メールアドレス**に対して10回/分。IPを変えても効く」③「同じ**IP**から20回/分」（②③は `AppServiceProvider::configureLoginRateLimiter()`）
  - 失敗は `storage/logs/laravel.log` に `warning` で残ります。単一ユーザーなので、ここに失敗が並んでいたら異常です
- [ ] レシート画像は `/storage/` から**認証なしで**配信されています。パスは推測しにくいですが、URLを知っていれば誰でも取れます。外に出すなら配信も認証の内側に入れるか、プロキシ側で塞いでください

### 公開前チェック（VM上で1回だけ）

**httpd（php-fpm 経由の本番用サーバー）に対して**実行してください。`artisan serve` は必ず `public/` を見るので、そちらに投げても確認になりません。

```bash
VM=192.168.1.50    # ← VMのIPかホスト名に置き換える

# ドキュメントルートが public/ を向いているか。
# 3つとも 404 か 403 でなければ「高」相当の問題です（APIキーとDBパスワードが落とせます）
for path in /.env /composer.json /storage/logs/laravel.log; do
  printf '%s\t%s\n' "$(curl -s -o /dev/null -w '%{http_code}' "http://$VM$path")" "$path"
done

# ヘッダーが付いているか
curl -sI "http://$VM/login" | grep -iE 'x-frame-options|x-content-type-options|content-security-policy'
```

`/storage/logs/laravel.log` が 404 なのは「ドキュメントルートがプロジェクト直下ではない」ことの確認です。ドキュメントルートが正しければ `/storage` は `public/storage`（＝レシート画像）に解決されるので、この結果は `storage/logs` が守られていることの証明にはなりません。ログを本当に守るのはドキュメントルートの設定そのものです。

`bash verify.sh` が見ているのは**ヘッダーの有無だけ**です（`_verify/report.log` の「セキュリティヘッダー」の箇所）。ドキュメントルートの確認は上の curl でしかできません。

## 運用メモ

- 確認は週1〜月1回を想定。レシートをまとめて撮って読み込ませ、CSV明細を取り込み、ダッシュボードで予算と前年同月比を確認する流れです。
- 認証は単一ユーザー前提のログインのみです。ログイン画面（`/login`）以外のすべての画面・操作は `auth` ミドルウェアの内側にあります。
  - ユーザーの作成・パスワード変更は `php artisan user:create you@example.com`（同じメールなら上書き）。パスワードは 12文字以上・72バイト以内。
  - ログインの連続失敗は、同じメールアドレス＋IPで5回に達すると、**最初の失敗から60秒**のあいだロックされます。さらに `/login` には名前付きの制限 `throttle:login`（`AppServiceProvider::configureLoginRateLimiter()`）を掛けてあります。
  - **ログインの失敗は `storage/logs/laravel.log` に `warning` で残ります。** 単一ユーザーなので、ここに失敗が並んでいたら異常です。
  - 上限に達したときは `resources/views/errors/429.blade.php`（日本語）が出ます。
- 予算は「月を空欄で登録＝毎月適用されるデフォルト予算」、「月を指定して登録＝その月だけの予算（デフォルトより優先）」です。
- **カテゴリを削除すると、そのカテゴリの予算と自動分類ルールも一緒に消えます**（取り消せません）。取引と定期支出は消えず「未分類」になります。
  カテゴリ一覧に、削除したときに巻き添えになる件数（取引・定期支出・予算・ルール／うち手動登録）を出しているので、押す前に確認してください。
  **予算と「手で登録した」自動分類ルール**の件数が画面の表示と食い違っていると、削除は実行されずに「読み直してください」と出ます（取引・定期支出は未分類になるだけ、自動学習ルールは次の取込で覚え直せるので、増えていても削除できます）。
  種別（収入／支出）は、使われているカテゴリでは変更できません（変えると、そのカテゴリを指すもの全部が「種別違い」になるため）。

### バックアップと復元

家計簿は積み上げた年数がそのまま価値になるのに、実体は VM の MySQL と `storage/app/public/receipts` にしかありません。VM が壊れたら全部消えます。

```bash
cd /var/www/apps/kakeibo

# 取る（既定は storage/app/backups、8世代まで保持）
php artisan kakeibo:backup
php artisan kakeibo:backup --path=/mnt/share --with-images --keep=12

# 戻す（いま入っている家計データはすべて置き換わります）
php artisan kakeibo:restore /mnt/share/kakeibo-backup-20260830-043000.zip
```

- 中身はテーブルごとの CSV と `manifest.json`。**復元コマンドが動かなくなっても人が読めて手で戻せる**ようにするための形式です。
  - CSV は RFC4180 準拠（エスケープ文字を使わない書き方）です。PHP の `fputcsv` 既定のエスケープ（`\`）を使うと、`半額シール\` のように**末尾がバックスラッシュの値で以降の列と行を丸ごと飲み込んで静かに壊れる**ため、明示的に無効にしています。
  - `\N` は「NULL」を表します（空文字と区別するため）。実データが `\N` だった場合はバックスラッシュを1つ足して逃がします。
  - 復元時は `manifest.json` に記録した件数と実際に入った件数を突き合わせ、合わなければ**何も消さずに中止**します（消してから気づくのが一番まずいので）。
- **バックアップを取ったあとに増えたテーブルや、NULL許容の列は、無くても復元できます。** ここを厳しくすると、テーブルや列を1つ足しただけで過去のバックアップがすべて復元不能になります（実際に一度そうなりました）。逆に、manifest に載っているのに CSV が無い場合は壊れているとみなして止めます。
- `users` テーブルは含めません。パスワードハッシュを共有フォルダに置きたくないためです。復元後に `php artisan user:create you@example.com` でアカウントを作り直してください。
- 画像は `--with-images` を付けたときだけ含めます（容量が大きいので、週次の自動取得では付けていません）。画像を含めない場合、レシート画像は失われますが取引データは残ります。
- スケジュール実行は cron で `php artisan schedule:run` を毎分回している場合のみ有効です。動いていないときは cron を確認してください。
- 置き場所は既定で `storage/app/backups`、つまり**壊れる想定の VM 自身の上**です。共有フォルダや外付けを `--path=` で指定するか、別マシンへコピーする運用にしてください。
- **一度は空のDBに対して復元を通しておいてください。**「取ったつもりで戻せない」のが一番まずい状態です。
