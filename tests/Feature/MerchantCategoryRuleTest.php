<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MerchantCategoryRule;
use App\Models\ReceiptImage;
use App\Models\Transaction;
use App\Services\MerchantCategoryGuesser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 店名 → カテゴリ の学習ルール。
 */
class MerchantCategoryRuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_取引を登録すると店名とカテゴリを覚える(): void
    {
        $category = Category::factory()->create(['name' => '食費']);

        $this->post(route('transactions.store'), [
            'transaction_date' => '2026-08-01',
            'type' => 'expense',
            'category_id' => $category->id,
            'shop_name' => '中野やまだ商店',
            'amount' => 800,
        ]);

        $this->assertDatabaseHas('merchant_category_rules', [
            'pattern' => '中野やまだ商店',
            'category_id' => $category->id,
            'source' => MerchantCategoryRule::SOURCE_LEARNED,
        ]);
    }

    public function test_カテゴリを直すとルールが上書きされる(): void
    {
        $wrong = Category::factory()->create(['name' => '日用品']);
        $right = Category::factory()->create(['name' => '外食']);

        $transaction = Transaction::factory()->create([
            'shop_name' => '三丁目の定食屋',
            'category_id' => $wrong->id,
            'type' => 'expense',
            'amount' => 500,
        ]);

        $this->put(route('transactions.update', $transaction), [
            'transaction_date' => $transaction->transaction_date->format('Y-m-d'),
            'type' => 'expense',
            'category_id' => $right->id,
            'shop_name' => '三丁目の定食屋',
            'amount' => 500,
        ]);

        $this->assertSame(1, MerchantCategoryRule::count());
        $this->assertDatabaseHas('merchant_category_rules', [
            'pattern' => '三丁目の定食屋',
            'category_id' => $right->id,
        ]);
    }

    public function test_キーワード辞書と同じ答えならルールを作らない(): void
    {
        // CSV取込は200行まとめて登録できるので、辞書の推測がそのまま
        // 「学習済みルール」に昇格すると、誤爆が誰にも見られないまま固定される。
        // 辞書と同じ答えなら覚える意味も無い。
        $food = Category::factory()->create(['name' => '食費']);

        $this->post(route('transactions.store'), [
            'transaction_date' => '2026-08-01',
            'type' => 'expense',
            'category_id' => $food->id,
            'shop_name' => 'セブン-イレブン 中野店',
            'amount' => 800,
        ]);

        $this->assertSame(0, MerchantCategoryRule::count());
    }

    public function test_辞書と違うカテゴリにしたときは覚える(): void
    {
        Category::factory()->create(['name' => '食費']);
        $daily = Category::factory()->create(['name' => '日用品']);

        $this->post(route('transactions.store'), [
            'transaction_date' => '2026-08-01',
            'type' => 'expense',
            'category_id' => $daily->id,
            'shop_name' => 'セブン-イレブン 中野店',
            'amount' => 800,
        ]);

        $this->assertDatabaseHas('merchant_category_rules', [
            'pattern' => 'セブン-イレブン 中野店',
            'category_id' => $daily->id,
        ]);
    }

    public function test_カテゴリ未設定の取引は覚えない(): void
    {
        $this->post(route('transactions.store'), [
            'transaction_date' => '2026-08-01',
            'type' => 'expense',
            'shop_name' => '謎の店',
            'amount' => 800,
        ]);

        $this->assertSame(0, MerchantCategoryRule::count());
    }

    public function test_学習ルールはキーワード辞書より優先される(): void
    {
        // 「イオン」はキーワード辞書では食費だが、学習ルールで日用品に上書きする
        $food = Category::factory()->create(['name' => '食費']);
        $daily = Category::factory()->create(['name' => '日用品']);
        $categories = Category::all();

        $guesser = app(MerchantCategoryGuesser::class);

        $this->assertSame($food->id, $guesser->guess('イオンモール 中野', $categories));

        $guesser->remember('イオンモール 中野', $daily->id);

        $this->assertSame($daily->id, $guesser->guess('イオンモール 中野', $categories));
    }

    public function test_部分一致は長いパターンが優先される(): void
    {
        $a = Category::factory()->create(['name' => 'カテゴリA']);
        $b = Category::factory()->create(['name' => 'カテゴリB']);

        $guesser = app(MerchantCategoryGuesser::class);
        $guesser->remember('セブン', $a->id, MerchantCategoryRule::SOURCE_MANUAL);
        $guesser->remember('セブン-イレブン', $b->id, MerchantCategoryRule::SOURCE_MANUAL);

        $this->assertSame($b->id, $guesser->guessByRule('セブン-イレブン 新宿三丁目店'));
        $this->assertSame($a->id, $guesser->guessByRule('セブンネットショッピング'));
    }

    public function test_全角半角や大文字小文字の違いを吸収する(): void
    {
        $category = Category::factory()->create(['name' => '娯楽・趣味']);

        $guesser = app(MerchantCategoryGuesser::class);
        $guesser->remember('AMAZON.CO.JP', $category->id, MerchantCategoryRule::SOURCE_MANUAL);

        $this->assertSame($category->id, $guesser->guessByRule('amazon.co.jp'));
    }

    public function test_レシート確認画面の初期カテゴリに学習ルールが使われる(): void
    {
        $aiSuggested = Category::factory()->create(['name' => '食費']);
        $learned = Category::factory()->create(['name' => '外食']);

        app(MerchantCategoryGuesser::class)->remember('スーパーライフ', $learned->id, MerchantCategoryRule::SOURCE_MANUAL);

        $receipt = ReceiptImage::create([
            'path' => 'receipts/dummy.jpg',
            'status' => 'processed',
            'parsed_data' => [
                'transaction_date' => '2026-08-01',
                'shop_name' => 'スーパーライフ',
                'total_amount' => 3200,
                'type' => 'expense',
                // AI は「食費」を提案しているが、学習ルールの「外食」が優先されるはず
                'suggested_category' => '食費',
                'items' => [],
                'memo' => null,
            ],
        ]);

        $response = $this->get(route('receipts.confirm', $receipt));

        $response->assertOk();
        $response->assertViewHas('suggestedCategoryId', $learned->id);
        $this->assertNotSame($aiSuggested->id, $learned->id);
    }

    public function test_ルールを手動で追加できる(): void
    {
        $category = Category::factory()->create(['name' => '通信費']);

        $response = $this->post(route('merchant-rules.store'), [
            'shop_name' => 'ＵＱモバイル',
            'category_id' => $category->id,
        ]);

        $response->assertRedirect(route('merchant-rules.index'));
        $this->assertDatabaseHas('merchant_category_rules', [
            'display_name' => 'ＵＱモバイル',
            'category_id' => $category->id,
            'source' => MerchantCategoryRule::SOURCE_MANUAL,
        ]);
    }

    public function test_手動ルールは自動学習で上書きされない(): void
    {
        $manual = Category::factory()->create(['name' => '日用品']);
        $other = Category::factory()->create(['name' => '食費']);

        $guesser = app(MerchantCategoryGuesser::class);
        $guesser->remember('コンビニA', $manual->id, MerchantCategoryRule::SOURCE_MANUAL);

        Transaction::factory()->create([
            'shop_name' => 'コンビニA',
            'category_id' => $other->id,
            'type' => 'expense',
            'amount' => 300,
        ]);

        $this->assertDatabaseHas('merchant_category_rules', [
            'pattern' => 'コンビニa',
            'category_id' => $manual->id,
        ]);
    }

    public function test_ルールを削除できる(): void
    {
        $category = Category::factory()->create();
        $rule = MerchantCategoryRule::create([
            'pattern' => 'てすと',
            'display_name' => 'テスト',
            'category_id' => $category->id,
            'source' => MerchantCategoryRule::SOURCE_MANUAL,
        ]);

        $this->delete(route('merchant-rules.destroy', $rule))
            ->assertRedirect(route('merchant-rules.index'));

        $this->assertDatabaseMissing('merchant_category_rules', ['id' => $rule->id]);
    }

    public function test_ルール一覧が表示できる(): void
    {
        $category = Category::factory()->create(['name' => '食費']);
        MerchantCategoryRule::create([
            'pattern' => 'まいばすけっと',
            'display_name' => 'まいばすけっと 中野店',
            'category_id' => $category->id,
            'source' => MerchantCategoryRule::SOURCE_LEARNED,
        ]);

        $response = $this->get(route('merchant-rules.index'));

        $response->assertOk();
        $response->assertSee('まいばすけっと 中野店');
    }

    public function test_カテゴリを削除するとルールも消える(): void
    {
        $category = Category::factory()->create();
        MerchantCategoryRule::create([
            'pattern' => 'てすと',
            'category_id' => $category->id,
            'source' => MerchantCategoryRule::SOURCE_MANUAL,
        ]);

        $this->delete(route('categories.destroy', $category), [
            'expected_impact' => \App\Http\Controllers\CategoryController::impactSignature(
                \App\Http\Controllers\CategoryController::impactOf($category),
            ),
        ]);

        $this->assertSame(0, MerchantCategoryRule::count());
    }
}
