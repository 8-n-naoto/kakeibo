#!/usr/bin/env bash
# ============================================================
# 家計簿アプリ 動作確認スクリプト（Linux VM 上で実行してください）
#   使い方:  cd /共有マウント先/kakeibo && bash verify.sh
#   結果:    _verify/report.log にすべて出力されます
#   環境変数: FORCE_INSTALL=1 で composer install を強制実行
# ============================================================
set -u
cd "$(dirname "$0")" || exit 1

OUT_DIR="_verify"
mkdir -p "$OUT_DIR"
LOG="$OUT_DIR/report.log"
: > "$LOG"

say()  { echo "$*" | tee -a "$LOG"; }
sec()  { printf '\n\n===== %s =====\n' "$*" >> "$LOG"; echo ">>> $*"; }
run()  { echo "\$ $*" >> "$LOG"; "$@" >> "$LOG" 2>&1; local rc=$?; echo "[exit=$rc]" >> "$LOG"; return $rc; }

say "家計簿アプリ 動作確認: $(date '+%Y-%m-%d %H:%M:%S')"

# ---------- 0. 環境情報 ----------
sec "0. 環境情報"
run uname -a
run php -v
run php -m
if command -v composer >/dev/null 2>&1; then COMPOSER="composer"
elif [ -f composer.phar ]; then COMPOSER="php composer.phar"
else COMPOSER=""; fi
echo "composer: ${COMPOSER:-NOT FOUND}" >> "$LOG"
[ -n "$COMPOSER" ] && run $COMPOSER --version
run git log --oneline -3
run git status --short

# ---------- 1. 依存インストール ----------
sec "1. composer install"
if [ -z "$COMPOSER" ]; then
  echo "!! composer が見つかりません。インストールしてから再実行してください。" >> "$LOG"
elif [ ! -d vendor ] || [ "${FORCE_INSTALL:-0}" = "1" ]; then
  run $COMPOSER install --no-interaction --prefer-dist --no-progress
else
  echo "vendor/ が既に存在するためスキップ (FORCE_INSTALL=1 で強制実行)" >> "$LOG"
fi

# ---------- 2. .env / APP_KEY ----------
sec "2. .env と APP_KEY"
if [ ! -f .env ]; then
  cp .env.example .env
  echo ".env を .env.example から作成しました" >> "$LOG"
else
  echo ".env は既に存在します（そのまま使用）" >> "$LOG"
fi
if ! grep -qE '^APP_KEY=base64:' .env; then
  run php artisan key:generate --force
else
  echo "APP_KEY は設定済み" >> "$LOG"
fi

# ---------- 3. PHP 構文チェック ----------
sec "3. PHP 構文チェック (php -l)"
SYNTAX_NG=0
while IFS= read -r f; do
  if ! out=$(php -l "$f" 2>&1); then
    echo "NG: $out" >> "$LOG"; SYNTAX_NG=$((SYNTAX_NG+1))
  fi
done < <(find app bootstrap config database routes tests -name '*.php' -type f)
echo "構文エラー件数: $SYNTAX_NG" >> "$LOG"

# ---------- 4. Blade コンパイルチェック ----------
sec "4. Blade テンプレートのコンパイル"
run php artisan config:clear
run php artisan view:clear
run php artisan view:cache

# ---------- 5. ルート一覧 ----------
sec "5. ルート一覧"
run php artisan route:list

# ---------- 6. 自動テスト ----------
sec "6. php artisan test (SQLiteインメモリ)"
run php artisan test

# ---------- 7. MySQL マイグレーション状態 ----------
sec "7. MySQL マイグレーション状態"
run php artisan migrate:status

# ---------- 8. HTTPスモークテスト（使い捨てSQLite DB） ----------
sec "8. HTTPスモークテスト"
SMOKE_DB="$(pwd)/$OUT_DIR/smoke.sqlite"
rm -f "$SMOKE_DB" 2>/dev/null || true
: > "$SMOKE_DB"
export DB_CONNECTION=sqlite DB_DATABASE="$SMOKE_DB" SESSION_DRIVER=file CACHE_STORE=file QUEUE_CONNECTION=sync
run php artisan config:clear
run php artisan migrate --force
run php artisan db:seed --force

PORT=8123
php artisan serve --host=127.0.0.1 --port=$PORT > "$OUT_DIR/serve.log" 2>&1 &
SERVE_PID=$!
for i in $(seq 1 30); do
  curl -s -o /dev/null "http://127.0.0.1:$PORT/dashboard" && break
  sleep 1
done

PATHS="/dashboard /transactions /transactions/create /transactions/export /imports /receipts/upload /budgets /budgets/create /categories /categories/create /assets /assets/create /savings-goals /savings-goals/create /investment-accounts /investment-accounts/create"
FAIL=0
{
  printf '%-6s %s\n' "STATUS" "PATH"
  for p in $PATHS; do
    code=$(curl -s -o "$OUT_DIR/last_body.html" -w '%{http_code}' "http://127.0.0.1:$PORT$p")
    printf '%-6s %s\n' "$code" "$p"
    case "$code" in 200|302) ;; *) FAIL=$((FAIL+1)); echo "--- 応答本文(先頭60行) ---"; head -60 "$OUT_DIR/last_body.html";; esac
  done
  echo "HTTP異常件数: $FAIL"
} >> "$LOG" 2>&1

kill $SERVE_PID 2>/dev/null
wait $SERVE_PID 2>/dev/null
echo "--- serve.log ---" >> "$LOG"; tail -40 "$OUT_DIR/serve.log" >> "$LOG" 2>&1
unset DB_CONNECTION DB_DATABASE SESSION_DRIVER CACHE_STORE QUEUE_CONNECTION
php artisan config:clear >/dev/null 2>&1

# ---------- 9. エラーログ ----------
sec "9. laravel.log の末尾"
run tail -80 storage/logs/laravel.log

# ---------- まとめ ----------
{
  printf '\n\n===== まとめ =====\n'
  echo "構文エラー: $SYNTAX_NG 件"
  echo "HTTP異常  : $FAIL 件"
  grep -E '^(OK|FAILURES|ERRORS|Tests:)' "$LOG" | tail -5
} >> "$LOG"

say ""
say "完了しました。結果は $OUT_DIR/report.log を見てください。"
