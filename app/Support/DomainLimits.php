<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

/**
 * 家計簿として「ありえる値」の範囲を1か所に集める。
 *
 * 画面ごとにバラバラに書くと必ずどこかが抜ける。実際、抜けた結果として次の2つが起きていた。
 *
 *  1. **集計から消える取引**。`transaction_date` が `['required','date']` だけだと
 *     `1899-01-05` が通る。一方で年間まとめ・ダッシュボード・予算は2000年以降しか見ない。
 *     カード会社CSVの日付が元号誤読で 1899 になっていると、取込プレビューは緑のまま通り、
 *     取引一覧とCSVエクスポートには出るのに、どの集計にも現れない。
 *     「一覧の合計と年間まとめが合わない」という、原因の追えない壊れ方をする。
 *  2. **1行で取込全体が落ちる**。`amount` に上限が無いと、CSVの金額欄の桁化けで
 *     合計が int を溢れ、`import_batches.total_amount` の範囲外になって取込が丸ごと500になる。
 *
 * 種別（income/expense）のスコープもここに置く。`exists:categories,id` だけだと
 * 支出の行に収入カテゴリを付けられてしまい、円グラフに「給与」が出るのに
 * 固定／変動の内訳からは静かに漏れる。
 */
final class DomainLimits
{
    /**
     * 1件あたりの金額の上限（円）。10億。
     *
     * 家計簿の1取引としては明らかに過大だが、桁化けを止めるのが目的なので広めに取る。
     * `import_batches.total_amount` は bigint なので、この上限なら2000行合計でも溢れない。
     */
    public const MAX_AMOUNT = 1_000_000_000;

    /** 扱う日付の下限。集計側（MonthParser::MIN_YEAR）と必ず揃える。 */
    public static function minDate(): string
    {
        return MonthParser::MIN_YEAR.'-01-01';
    }

    /**
     * 扱う日付の上限。
     *
     * 「来年の年末まで」。定期支出の予定やクレジットの翌月確定ぶんを入れられる程度の余裕。
     */
    public static function maxDate(): string
    {
        return ((int) Carbon::now()->format('Y') + 1).'-12-31';
    }

    /** 日付が集計に載る範囲かどうか */
    public static function inDateRange(?string $date): bool
    {
        if ($date === null || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date)) {
            return false;
        }

        return $date >= self::minDate() && $date <= self::maxDate();
    }

    /** 金額が扱える範囲かどうか */
    public static function inAmountRange(int $amount): bool
    {
        return $amount >= -self::MAX_AMOUNT && $amount <= self::MAX_AMOUNT;
    }

    /**
     * 金額のバリデーションルール。
     *
     * @return list<string>
     */
    public static function amountRules(int $min = 0): array
    {
        return ['integer', 'min:'.$min, 'max:'.self::MAX_AMOUNT];
    }

    /**
     * 日付のバリデーションルール。
     *
     * @return list<string>
     */
    public static function dateRules(): array
    {
        return ['date', 'after_or_equal:'.self::minDate(), 'before_or_equal:'.self::maxDate()];
    }

    /**
     * カテゴリの存在チェック。種別が分かっているときは、その種別に絞る。
     *
     * $type が income/expense のどちらでもないときは絞らない。
     * （そのときは type 側のルールがエラーを出すので、カテゴリまで赤くすると
     * 「カテゴリも間違っている」と誤解させてしまう）
     *
     * `exists` ではなく `in` にしてある。CSV取込の確定は 2,000 行を1回のPOSTで
     * 受けるので、`exists` だと**行数ぶんの SELECT** が飛ぶ。ID一覧は
     * 1リクエストにつき1回だけ読んで使い回す。
     */
    public static function categoryRule(mixed $type = null): In
    {
        return Rule::in(app(CategoryIdIndex::class)->ids(is_string($type) ? $type : null));
    }
}
