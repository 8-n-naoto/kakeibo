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

# 認証が入ったので、スモーク用のユーザーを作ってログインしてから各画面を叩く
VERIFY_EMAIL="verify@example.com"
VERIFY_PASSWORD="verify-password"
run php artisan user:create "$VERIFY_EMAIL" --name=verify --password="$VERIFY_PASSWORD"

PORT=8123
php artisan serve --host=127.0.0.1 --port=$PORT > "$OUT_DIR/serve.log" 2>&1 &
SERVE_PID=$!
for i in $(seq 1 30); do
  curl -s -o /dev/null "http://127.0.0.1:$PORT/login" && break
  sleep 1
done

COOKIE_JAR="$OUT_DIR/cookies.txt"
rm -f "$COOKIE_JAR" 2>/dev/null || true
CSRF=$(curl -s -c "$COOKIE_JAR" "http://127.0.0.1:$PORT/login" \
  | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//; s/"$//')
LOGIN_CODE=$(curl -s -b "$COOKIE_JAR" -c "$COOKIE_JAR" -o /dev/null -w '%{http_code}' \
  --data-urlencode "_token=$CSRF" \
  --data-urlencode "email=$VERIFY_EMAIL" \
  --data-urlencode "password=$VERIFY_PASSWORD" \
  "http://127.0.0.1:$PORT/login")
{
  echo "ログイン試行: HTTP $LOGIN_CODE (302ならログイン成功)"
} >> "$LOG" 2>&1

# CSP違反レポートの受け口は、ブラウザがCSRFトークン無しでPOSTしてくる。
# 除外の設定が効いていないと 419 になり、Report-Only の確認が成り立たない。
CSP_CODE=$(curl -s -b "$COOKIE_JAR" -o /dev/null -w '%{http_code}' \
  -X POST -H 'Content-Type: application/csp-report' \
  --data '{"csp-report":{"violated-directive":"script-src"}}' \
  "http://127.0.0.1:$PORT/csp-report")
echo "CSP違反レポートの受け口: HTTP $CSP_CODE (204ならOK / 419ならCSRF除外が効いていない / 500はセッション無しの経路)" >> "$LOG"
# ここが 204 以外だと、CSPの違反レポートは1件も届かない。
# 「違反が出ないことを確かめてから強制にする」運用が成り立たなくなるので、異常として数える。
EXTRA_FAIL=0
[ "$CSP_CODE" = "204" ] || EXTRA_FAIL=$((EXTRA_FAIL+1))

# セキュリティヘッダーが付いているか（ログイン画面と404の両方）
{
  echo "--- セキュリティヘッダー (ログイン画面) ---"
  curl -s -D - -o /dev/null "http://127.0.0.1:$PORT/login" \
    | grep -iE '^(x-frame-options|x-content-type-options|referrer-policy|content-security-policy)' || echo "(1つも付いていません)"
  echo "--- セキュリティヘッダー (404) ---"
  curl -s -D - -o /dev/null "http://127.0.0.1:$PORT/no-such-page" \
    | grep -iE '^(x-frame-options|x-content-type-options)' || echo "(1つも付いていません)"
} >> "$LOG" 2>&1

PATHS="/manifest.webmanifest /sw.js /offline.html /icons/icon-192.png /dashboard /reports /reports/2026 /transactions /transactions/create /transactions/export /transactions?keyword=test&type=expense&sort=amount_desc /imports /imports/batches /receipts/upload /receipts/pending /merchant-rules /recurring /recurring/create /budgets /budgets/create /budgets/suggestions /categories /categories/create /assets /assets/create /savings-goals /savings-goals/create /investment-accounts /investment-accounts/create"
FAIL=${EXTRA_FAIL:-0}
{
  printf '%-6s %s\n' "STATUS" "PATH"
  for p in $PATHS; do
    code=$(curl -s -b "$COOKIE_JAR" -c "$COOKIE_JAR" -o "$OUT_DIR/last_body.html" -w '%{http_code}' "http://127.0.0.1:$PORT$p")
    printf '%-6s %s\n' "$code" "$p"
    # ログイン済みで叩いているので 302 は「/login へのリダイレクト＝認証が効いていない or 失敗」を意味する
    case "$code" in 200) ;; *) FAIL=$((FAIL+1)); echo "--- 応答本文(先頭60行) ---"; head -60 "$OUT_DIR/last_body.html";; esac
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
SUMMARY="$OUT_DIR/summary.txt"

{
  printf '===== 家計簿アプリ 動作確認 まとめ =====\n'
  date '+%Y-%m-%d %H:%M:%S'
  php -v 2>/dev/null | head -1
  printf '\n'

  printf -- '-- 件数 --\n'
  echo "構文エラー: $SYNTAX_NG 件"
  echo "HTTP異常  : $FAIL 件（画面 + CSP受け口）"
  # artisan test(Collision) の出力は行頭にスペースが入るので \s* を入れる
  # 「Tests:」が1行も無い ＝ テストが走っていない。失敗0件と見分けが付かないので明示する。
  if grep -qE '^\s*Tests:' "$LOG"; then
    grep -E '^\s*(Tests:|Duration:)' "$LOG" | tail -4
  else
    echo '!! 自動テストが1件も走っていません（php artisan test が実行できていない）。'
    echo '   下の「失敗したテスト」が空でも、それは合格を意味しません。'
    echo '   → vendor を dev 込みで入れ直してください: composer install'
  fi

  printf '\n-- 失敗したテスト --\n'
  # Collision は失敗を "⨯ テスト名" の形で出す（見つからなければ素の PHPUnit 形式も拾う）
  if grep -qE '^\s*(⨯|✕)' "$LOG"; then
    grep -E '^\s*(⨯|✕)' "$LOG" | head -40
  elif grep -qE '^[0-9]+\) ' "$LOG"; then
    grep -E '^[0-9]+\) ' "$LOG" | head -40
  elif grep -qE '^\s*Tests:' "$LOG"; then
    echo "(なし)"
  else
    echo "(判定不能: テストが実行されていません)"
  fi

  printf '\n-- 失敗の詳細（先頭120行） --\n'
  # 失敗の再現に必要な箇所だけ抜き出す
  awk '/FAILED|Failed asserting|Exception|Error :|^\s*at [^ ]+:[0-9]+$/ { print }' "$LOG" | head -120

  printf '\n-- HTTPスモークの結果 --\n'
  awk '/^STATUS/,/^HTTP異常件数/' "$LOG" | head -40

  printf '\n-- laravel.log の末尾20行 --\n'
  tail -20 storage/logs/laravel.log 2>/dev/null || echo "(ログなし)"
} > "$SUMMARY" 2>&1

cat "$SUMMARY" >> "$LOG"

say ""
say "完了しました。"
say "  ぜんぶ:   $OUT_DIR/report.log"
say "  要点だけ: $SUMMARY   ← まずはこちらを貼ってください"
