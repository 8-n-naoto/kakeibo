<?php

namespace App\Services;

use App\Models\Category;
use App\Models\MerchantCategoryRule;
use Illuminate\Support\Collection;

/**
 * 店名からカテゴリを推測する。
 *
 * 判定の優先順位は次のとおり。
 *   1. 学習済みルール(完全一致)   … 過去に同じ店名で登録したカテゴリ
 *   2. 学習済みルール(部分一致)   … 一番長いパターンを優先
 *   3. キーワード辞書             … 初回でも当たるようにした固定の辞書
 *
 * CSV取込とレシート解析の両方からこのクラスを通すことで、
 * 一度手で直した店は次から自動で正しいカテゴリになる。
 */
class MerchantCategoryGuesser
{
    /**
     * 店名からカテゴリを推測するためのキーワード辞書(学習ルールが無いときのフォールバック)。
     *
     * 英字のキーワードは**単語として**照合する（matches() を参照）。
     * 部分一致にすると `beauty` の "au" が通信費、`panasonic` の "ana" が交通費、
     * `sketch` の "etc" が交通費に化ける。
     *
     * 日本語のキーワードは単語境界が無いので部分一致のまま。代わりに
     * 長いキーワードから順に照合して、`業務スーパー` が `スーパー` に、
     * `ガスト` が `ガス` に負けないようにしている。
     */
    public const CATEGORY_KEYWORDS = [
        '食費' => [
            '業務スーパー', 'まいばすけっと', 'スーパー', 'イオン', 'ライフコーポレーション', '西友', 'マルエツ', 'サミット', 'オーケー', 'コープ',
            // コンビニ。毎日の買い物で一番よく出るのに丸ごと抜けていた
            'セブン-イレブン', 'セブンイレブン', 'ローソン', 'ファミリーマート', 'ファミマ', 'ミニストップ', 'デイリーヤマザキ', 'セイコーマート', 'ポプラ',
        ],
        '外食' => ['マクドナルド', 'スターバックス', 'サイゼリヤ', 'レストラン', 'ドトール', 'すき家', '吉野家', '松屋', 'ガスト', '居酒屋', 'カフェ', 'starbucks', 'mcdonald', 'coffee', 'cafe'],
        '日用品' => ['マツモトキヨシ', 'サンドラッグ', 'ホームセンター', 'ウエルシア', 'ドラッグ', 'ダイソー', 'ニトリ', 'カインズ'],
        '水道光熱費' => ['東京ガス', '大阪ガス', '電力', '電気', 'ガス', '水道'],
        '通信費' => ['nttコミュニケーションズ', 'ソフトバンク', 'ワイモバイル', '楽天モバイル', 'インターネット', 'ドコモ', 'docomo', 'softbank', 'au', 'uq'],
        '交通費' => ['モバイルsuica', 'タクシー', '鉄道', '交通', '高速', 'jr', 'suica', 'pasmo', 'etc', 'ana', 'jal'],
        '医療・健康' => ['クリニック', 'フィットネス', 'スポーツジム', 'ゴールドジム', 'エニタイム', '薬局', '医院', '病院', '歯科'],
        '衣服・美容' => ['ユニクロ', 'しまむら', '美容', 'ヘアー', '理容', 'gu', 'zozo'],
        '娯楽・趣味' => ['ビックカメラ', 'ヨドバシ', 'アマゾン', 'シネマ', 'ゲーム', '映画', '書店', '書房', 'amazon', 'netflix', 'spotify', 'youtube'],
        '住居費' => ['管理費', '不動産', '家賃'],
    ];

    /** 長い順に並べ替えたキーワード（[カテゴリ名, 正規化済みキーワード] の配列） */
    private static ?array $sortedKeywords = null;

    /** 1リクエスト内でルールを使い回すためのキャッシュ */
    private ?Collection $rules = null;

    /** @var list<int>|null 支出カテゴリのID（1リクエストの間だけ覚える） */
    private ?array $expenseCategoryIds = null;

    /**
     * パターン→ルール（1リクエストの間だけ覚える）。
     *
     * CSV取込は2,000行を1回のPOSTで確定でき、行ごとに remember() が走る。
     * カード明細は同じ店が何度も出てくるので、毎回 firstOrNew で引くと
     * 「読めば分かっている行」を行数ぶん SELECT することになる。
     *
     * @var array<string, MerchantCategoryRule>
     */
    private array $byPattern = [];

    /**
     * 店名の表記ゆれを吸収する(小文字化・全角半角の統一・空白の圧縮)。
     */
    public static function normalize(?string $shopName): string
    {
        if ($shopName === null) {
            return '';
        }

        $normalized = mb_strtolower(mb_convert_kana(trim($shopName), 'asKV'));

        return (string) preg_replace('/\s+/u', ' ', $normalized);
    }

    /**
     * 学習ルール → キーワード辞書 の順で推測する。
     *
     * @param  Collection<int, Category>  $categories
     */
    public function guess(?string $shopName, Collection $categories): ?int
    {
        $learned = $this->guessByRule($shopName);

        if ($learned !== null) {
            return $learned;
        }

        return self::guessByKeyword($shopName, $categories);
    }

    /**
     * 学習済みルールから推測する。完全一致 → 部分一致(長いパターン優先) の順。
     */
    public function guessByRule(?string $shopName): ?int
    {
        $normalized = self::normalize($shopName);

        if ($normalized === '') {
            return null;
        }

        $rules = $this->rules();

        $exact = $rules->first(fn (MerchantCategoryRule $rule) => $rule->pattern === $normalized);

        if ($exact) {
            return $exact->category_id;
        }

        $partial = $rules
            ->filter(fn (MerchantCategoryRule $rule) => $rule->pattern !== '' && str_contains($normalized, $rule->pattern))
            ->sortByDesc(fn (MerchantCategoryRule $rule) => mb_strlen($rule->pattern))
            ->first();

        return $partial?->category_id;
    }

    /**
     * キーワード辞書から推測する。DBを使わないので単体テストしやすい。
     *
     * @param  Collection<int, Category>  $categories
     */
    public static function guessByKeyword(?string $shopName, Collection $categories): ?int
    {
        $haystack = self::normalize($shopName);

        if ($haystack === '') {
            return null;
        }

        foreach (self::sortedKeywords() as [$categoryName, $keyword]) {
            if (! self::matches($haystack, $keyword)) {
                continue;
            }

            $id = $categories->firstWhere('name', $categoryName)?->id;

            // そのカテゴリが存在しない（名前を変えた・消した）場合は、
            // そこで打ち切らずに次のキーワードを試す
            if ($id !== null) {
                return $id;
            }
        }

        return null;
    }

    /**
     * キーワードを長い順に並べたもの。長いほうを先に見ないと
     * `業務スーパー` が `スーパー` に、`ガスト` が `ガス` に食われる。
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private static function sortedKeywords(): array
    {
        if (self::$sortedKeywords !== null) {
            return self::$sortedKeywords;
        }

        $flat = [];

        foreach (self::CATEGORY_KEYWORDS as $categoryName => $keywords) {
            foreach ($keywords as $keyword) {
                $flat[] = [$categoryName, self::normalize($keyword)];
            }
        }

        usort($flat, static fn (array $a, array $b): int => mb_strlen($b[1]) <=> mb_strlen($a[1]));

        return self::$sortedKeywords = $flat;
    }

    /**
     * キーワードが店名に含まれるか。
     *
     * 英数字だけのキーワードは単語として照合する。部分一致にすると
     * `beauty salon` が通信費(au)、`panasonic` が交通費(ana)、
     * `sketch book cafe` が交通費(etc) になってしまう。
     */
    private static function matches(string $haystack, string $keyword): bool
    {
        if ($keyword === '') {
            return false;
        }

        if (preg_match('/^[a-z0-9]+$/', $keyword) === 1) {
            return preg_match('/(?<![a-z0-9])'.preg_quote($keyword, '/').'(?![a-z0-9])/u', $haystack) === 1;
        }

        return str_contains($haystack, $keyword);
    }

    /**
     * 「この店名はこのカテゴリ」を覚える。すでにあれば上書きする。
     */
    public function remember(?string $shopName, ?int $categoryId, string $source = MerchantCategoryRule::SOURCE_LEARNED): ?MerchantCategoryRule
    {
        $normalized = self::normalize($shopName);

        if ($normalized === '' || $categoryId === null) {
            return null;
        }

        $rule = $this->byPattern[$normalized]
            ??= MerchantCategoryRule::firstOrNew(['pattern' => $normalized]);

        // 手動登録したルールを自動学習で書き換えない
        if ($rule->exists && $rule->source === MerchantCategoryRule::SOURCE_MANUAL && $source !== MerchantCategoryRule::SOURCE_MANUAL) {
            $rule->increment('hit_count');
            // カテゴリは変わっていないので、読み込み済みの一覧はそのままでよい

            return $rule;
        }

        $rule->fill([
            'display_name' => trim((string) $shopName),
            'category_id' => $categoryId,
            'source' => $source,
            'hit_count' => ($rule->hit_count ?? 0) + 1,
        ])->save();

        $this->syncCachedRule($rule);

        return $rule;
    }

    /**
     * 読み込み済みのルール一覧を、いま保存した1件ぶんだけ更新する。
     *
     * ここで丸ごと捨てると、CSV取込やまとめて編集のように「1件保存するたびに覚える」
     * 経路で、**1取引につきルール全件の再SELECT**が走る。数千件で効いてくる。
     */
    private function syncCachedRule(MerchantCategoryRule $rule): void
    {
        if ($this->rules === null) {
            return;
        }

        $this->rules = $this->rules
            ->reject(fn (MerchantCategoryRule $cached) => $cached->getKey() === $rule->getKey())
            ->values();

        // 推測に使うのは支出カテゴリのルールだけ（rules() と同じ条件）
        if ($this->isExpenseCategory((int) $rule->category_id)) {
            $this->rules->push($rule);
        }
    }

    private function isExpenseCategory(int $categoryId): bool
    {
        $this->expenseCategoryIds ??= Category::expense()->pluck('id')->map(fn ($id) => (int) $id)->all();

        return in_array($categoryId, $this->expenseCategoryIds, true);
    }

    /**
     * 推測に使う学習ルール。
     *
     * カテゴリが収入のルールは除く。学習ルールは支出の推測にしか使わないのに、
     * ここを絞らないと支出行に収入カテゴリが付いた状態でプレビューが緑になり、
     * 確定で全行が「種別が違う」で弾かれる。
     * （カテゴリの種別は後から変えられるので、過去に作られたルールが該当しうる）
     *
     * @return Collection<int, MerchantCategoryRule>
     */
    private function rules(): Collection
    {
        return $this->rules ??= MerchantCategoryRule::query()
            ->whereHas('category', fn ($query) => $query->where('type', 'expense'))
            ->get();
    }
}
