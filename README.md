# 家計簿アプリ（Laravel 13 / PHP 8.3+）

レシート画像を AI（既定は Gemini API。Claude API にも切替可）で読み取って家計簿につけ、月の収支・予算・資産を管理するためのアプリです。

## 動作要件

| 項目 | バージョン |
| --- | --- |
| PHP | 8.3 〜 8.5（`mbstring`, `pdo_mysql`, `pdo_sqlite`, `fileinfo`, `gd` が必要） |
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

# 3. .env の DB_DATABASE / DB_USERNAME / DB_PASSWORD を環境に合わせて編集
#    GEMINI_API_KEY にレシート解析用のAPIキーを設定（レシート機能を使わないなら空でも可）

# 4. DBを作成してマイグレーション＋初期カテゴリ投入
mysql -u root -p -e "CREATE DATABASE kakeibo DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
php artisan migrate --seed

# 5. アップロード画像を公開するためのシンボリックリンク
php artisan storage:link

# 6. 起動（VM外のホストから見る場合は --host=0.0.0.0）
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
| `tests/Unit/BudgetStatusTest.php` | 予算消化率のしきい値判定 |
| `tests/Unit/CategoryNatureTest.php` | 固定費/変動費の判定 |
| `tests/Unit/SavingsGoalProgressTest.php` | 貯蓄目標の残額・進捗率 |
| `tests/Feature/TransactionCrudTest.php` | 取引のCRUDと月・カテゴリ絞り込み |
| `tests/Feature/CategoryCrudTest.php` | カテゴリ管理 |
| `tests/Feature/BudgetTest.php` | 予算のCRUD、消化率、超過アラート |
| `tests/Feature/MonthlyReportTest.php` | 固定費/変動費の分離、前年同月比 |
| `tests/Feature/TransactionImportTest.php` | カード明細CSVの取込（Shift_JIS・重複検知含む） |
| `tests/Feature/TransactionExportTest.php` | 取引のCSV出力 |
| `tests/Feature/ReceiptUploadTest.php` | レシート画像のアップロードと登録（API はモック） |
| `tests/Feature/GeminiReceiptParserTest.php` | Gemini API 応答の解析（`Http::fake`） |
| `tests/Feature/ClaudeReceiptParserTest.php` | Claude API 応答の解析（`Http::fake`） |
| `tests/Feature/ReceiptParserDriverTest.php` | `RECEIPT_AI_DRIVER` によるAIの切替 |
| `tests/Feature/AssetSnapshotTest.php` | 資産スナップショット |
| `tests/Feature/SavingsGoalTest.php` | 貯蓄目標 |
| `tests/Feature/InvestmentAccountTest.php` | NISA/iDeCo |
| `tests/Feature/DashboardEngelCoefficientTest.php` | エンゲル係数 |

## レシート解析に使うAIの設定

レシート画像の解析はどちらのAIでも動きます。`.env` の `RECEIPT_AI_DRIVER` で切り替えます（既定は `gemini`）。

| 変数 | 既定値 | 説明 |
| --- | --- | --- |
| `RECEIPT_AI_DRIVER` | `gemini` | `gemini` または `claude` |
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
- レシート画像のアップロード → AI（Gemini / Claude）で解析 → 確認画面で修正して登録
- クレジットカード明細CSVの取込（Shift_JIS / UTF-8 自動判定、列の自動検出、店名からのカテゴリ推測、重複候補の自動チェック解除）

### 集計・管理
- 月次ダッシュボード：収入・支出・収支、エンゲル係数、カテゴリ別円グラフ、直近12ヶ月の推移
- 予算管理：支出全体／カテゴリ別、毎月のデフォルト予算と月指定予算、消化率80%で「要注意」・100%超で「予算超過」アラート
- 固定費／変動費の分離と固定費率の表示（カテゴリ管理画面で分類を変更可能）
- 前年同月比（収入・支出・カテゴリ別の差額と増減率）
- 資産スナップショット（現金・NISA・iDeCo・その他投資）と総資産推移
- 貯蓄目標（進捗率・月あたり必要貯蓄額）
- NISA/iDeCo の年間投資枠の消化率・含み損益
- 取引のCSV出力（Excel で開ける UTF-8 BOM 付き）

## 主要なディレクトリ

```
app/Http/Controllers/   画面ごとのコントローラ
app/Models/             Eloquent モデル
app/Services/           BudgetService（予算集計）/ MonthlyReportService（固定変動・前年比）
                        CsvImportService（明細CSV解析）/ TransactionCsvExporter（CSV出力）
                        ReceiptParser（インターフェース）/ AbstractReceiptParser（共通処理）
                        GeminiReceiptParser・ClaudeReceiptParser（レシート画像の解析）
database/migrations/    テーブル定義
database/seeders/       初期カテゴリ（固定費/変動費の初期分類つき）
resources/views/        Blade テンプレート
tests/                  PHPUnit テスト
```

## 運用メモ

- 確認は週1〜月1回を想定。レシートをまとめて撮って読み込ませ、CSV明細を取り込み、ダッシュボードで予算と前年同月比を確認する流れです。
- 認証機能は入れていません（単一ユーザー前提）。LAN 外に公開する場合は必ず認証を追加してください。
- 予算は「月を空欄で登録＝毎月適用されるデフォルト予算」、「月を指定して登録＝その月だけの予算（デフォルトより優先）」です。
