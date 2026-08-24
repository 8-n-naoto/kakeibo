<?php

namespace Tests\Feature;

use App\Http\Controllers\TransactionController;
use App\Models\Category;
use App\Models\MerchantCategoryRule;
use App\Models\Transaction;
use App\Services\MerchantCategoryGuesser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 取引のカテゴリをまとめて変更する。
 *
 * 自動分類ルールを直しても過去の取引は直らないので、後から揃えるための機能。
 * 元に戻せない操作なので「対象がずれない」ことを重点的に確認する。
 */
class TransactionBulkUpdateTest extends TestCase
{
    use RefreshDatabase;

    private Category $food;

    private Category $daily;

    protected function setUp(): void
    {
        parent::setUp();

        $this->food = Category::factory()->create(['name' => '食費']);
        $this->daily = Category::factory()->create(['name' => '日用品']);
    }

    private function transaction(string $shop, ?Category $category = null, string $date = '2026-08-01'): Transaction
    {
        return Transaction::factory()->create([
            'shop_name' => $shop,
            'category_id' => $category?->id,
            'type' => 'expense',
            'transaction_date' => $date,
            'amount' => 1000,
        ]);
    }

    public function test_選んだ取引のカテゴリをまとめて変更できる(): void
    {
        $a = $this->transaction('イオン A', $this->food);
        $b = $this->transaction('イオン B', $this->food);
        $untouched = $this->transaction('別の店', $this->food);

        $response = $this->post(route('transactions.bulk-update'), [
            'scope' => 'selected',
            'ids' => [$a->id, $b->id],
            'bulk_category_id' => $this->daily->id,
        ]);

        $response->assertRedirect(route('transactions.index'));

        $this->assertSame($this->daily->id, $a->fresh()->category_id);
        $this->assertSame($this->daily->id, $b->fresh()->category_id);
        // 選んでいない取引は変わらない
        $this->assertSame($this->food->id, $untouched->fresh()->category_id);
    }

    public function test_未分類にもできる(): void
    {
        $target = $this->transaction('イオン', $this->food);

        $this->post(route('transactions.bulk-update'), [
            'scope' => 'selected',
            'ids' => [$target->id],
            'bulk_category_id' => TransactionController::CLEAR_CATEGORY,
        ]);

        $this->assertNull($target->fresh()->category_id);
    }

    public function test_絞り込み結果すべてを対象にできる(): void
    {
        $hit1 = $this->transaction('ヨドバシカメラ 新宿', $this->food);
        $hit2 = $this->transaction('ヨドバシカメラ 梅田', $this->food);
        $miss = $this->transaction('まいばすけっと', $this->food);

        $this->post(route('transactions.bulk-update'), [
            'scope' => 'filtered',
            'keyword' => 'ヨドバシ',
            'filtered_count' => 2,
            'bulk_category_id' => $this->daily->id,
        ]);

        $this->assertSame($this->daily->id, $hit1->fresh()->category_id);
        $this->assertSame($this->daily->id, $hit2->fresh()->category_id);
        // 絞り込みから外れた取引は変わらない
        $this->assertSame($this->food->id, $miss->fresh()->category_id);
    }

    public function test_絞り込み結果すべてでも月の条件が効く(): void
    {
        $august = $this->transaction('イオン', $this->food, '2026-08-10');
        $july = $this->transaction('イオン', $this->food, '2026-07-10');

        $this->post(route('transactions.bulk-update'), [
            'scope' => 'filtered',
            'month' => '2026-08',
            'filtered_count' => 1,
            'bulk_category_id' => $this->daily->id,
        ]);

        $this->assertSame($this->daily->id, $august->fresh()->category_id);
        $this->assertSame($this->food->id, $july->fresh()->category_id);
    }

    public function test_学習にチェックすると自動分類ルールにも反映される(): void
    {
        $target = $this->transaction('イオンモール 中野', $this->food);

        $this->post(route('transactions.bulk-update'), [
            'scope' => 'selected',
            'ids' => [$target->id],
            'bulk_category_id' => $this->daily->id,
            'learn' => '1',
        ]);

        $this->assertSame(
            $this->daily->id,
            app(MerchantCategoryGuesser::class)->guessByRule('イオンモール 中野'),
        );
    }

    public function test_学習にチェックしなければ自動分類ルールは変わらない(): void
    {
        // 寄せ集めをまとめて「その他支出」にしたときに、良いルールを壊さないための挙動
        $guesser = app(MerchantCategoryGuesser::class);
        $guesser->remember('イオンモール 中野', $this->food->id, MerchantCategoryRule::SOURCE_MANUAL);

        $target = $this->transaction('イオンモール 中野', $this->food);

        $this->post(route('transactions.bulk-update'), [
            'scope' => 'selected',
            'ids' => [$target->id],
            'bulk_category_id' => $this->daily->id,
            'learn' => '0',
        ]);

        $this->assertSame($this->daily->id, $target->fresh()->category_id);
        $this->assertSame($this->food->id, app(MerchantCategoryGuesser::class)->guessByRule('イオンモール 中野'));
    }

    public function test_1件も選ばないとエラーになる(): void
    {
        $target = $this->transaction('イオン', $this->food);

        $response = $this->from(route('transactions.index'))->post(route('transactions.bulk-update'), [
            'scope' => 'selected',
            'bulk_category_id' => $this->daily->id,
        ]);

        $response->assertSessionHasErrors('ids');
        $this->assertSame($this->food->id, $target->fresh()->category_id);
    }

    public function test_対象が0件ならエラーで戻る(): void
    {
        $this->transaction('イオン', $this->food);

        $response = $this->from(route('transactions.index'))->post(route('transactions.bulk-update'), [
            'scope' => 'filtered',
            'keyword' => 'まったく一致しない店名',
            'filtered_count' => 0,
            'bulk_category_id' => $this->daily->id,
        ]);

        $response->assertSessionHasErrors('bulk');
    }

    public function test_存在しないカテゴリは弾く(): void
    {
        $target = $this->transaction('イオン', $this->food);

        $response = $this->from(route('transactions.index'))->post(route('transactions.bulk-update'), [
            'scope' => 'selected',
            'ids' => [$target->id],
            'bulk_category_id' => 999999,
        ]);

        $response->assertSessionHasErrors('bulk_category_id');
        $this->assertSame($this->food->id, $target->fresh()->category_id);
    }

    public function test_一覧に一括編集の操作が出る(): void
    {
        $this->transaction('イオン', $this->food);

        $response = $this->get(route('transactions.index'));

        $response->assertOk();
        $response->assertSee('選んだ取引のカテゴリをまとめて変更');
        $response->assertSee('name="ids[]"', false);
        // 行内の削除フォームとネストしないよう form 属性で紐づけている
        $response->assertSee('form="bulk-form"', false);
    }

    /**
     * かつて「未分類」がカテゴリ選択の先頭（既定値）で、絞り込みも空のまま
     * ボタンを1回押すだけで全取引のカテゴリが消せてしまった。
     */
    public function test_絞り込み結果すべてを未分類に戻すことはできない(): void
    {
        $a = $this->transaction('イオン', $this->food);
        $b = $this->transaction('セブン', $this->food);

        $response = $this->from(route('transactions.index'))->post(route('transactions.bulk-update'), [
            'scope' => 'filtered',
            'filtered_count' => 2,
            'bulk_category_id' => TransactionController::CLEAR_CATEGORY,
        ]);

        $response->assertSessionHasErrors('bulk_category_id');
        $this->assertSame($this->food->id, $a->fresh()->category_id);
        $this->assertSame($this->food->id, $b->fresh()->category_id);
    }

    public function test_カテゴリ未選択のまま送ると拒否される(): void
    {
        $target = $this->transaction('イオン', $this->food);

        $response = $this->from(route('transactions.index'))->post(route('transactions.bulk-update'), [
            'scope' => 'selected',
            'ids' => [$target->id],
            'bulk_category_id' => '',
        ]);

        $response->assertSessionHasErrors('bulk_category_id');
        $this->assertSame($this->food->id, $target->fresh()->category_id);
    }

    public function test_絞り込み結果の件数が画面の表示とずれたら拒否される(): void
    {
        // 画面を開いたあと、別タブの取込で1件増えたような状況
        $a = $this->transaction('イオン', $this->food);
        $b = $this->transaction('イオン 別店舗', $this->food);

        $response = $this->from(route('transactions.index'))->post(route('transactions.bulk-update'), [
            'scope' => 'filtered',
            'keyword' => 'イオン',
            'filtered_count' => 1,
            'bulk_category_id' => $this->daily->id,
        ]);

        $response->assertSessionHasErrors('bulk');
        $this->assertSame($this->food->id, $a->fresh()->category_id);
        $this->assertSame($this->food->id, $b->fresh()->category_id);
    }

    public function test_選んだIDのうち存在しないものが混ざっていたら拒否される(): void
    {
        $target = $this->transaction('イオン', $this->food);

        $response = $this->from(route('transactions.index'))->post(route('transactions.bulk-update'), [
            'scope' => 'selected',
            'ids' => [$target->id, 999999],
            'bulk_category_id' => $this->daily->id,
        ]);

        $response->assertSessionHasErrors('bulk');
        $this->assertSame($this->food->id, $target->fresh()->category_id);
    }

    public function test_絞り込み結果すべてには件数の申告が要る(): void
    {
        $target = $this->transaction('イオン', $this->food);

        $response = $this->from(route('transactions.index'))->post(route('transactions.bulk-update'), [
            'scope' => 'filtered',
            'bulk_category_id' => $this->daily->id,
        ]);

        $response->assertSessionHasErrors('filtered_count');
        $this->assertSame($this->food->id, $target->fresh()->category_id);
    }

    public function test_選べる件数には上限がある(): void
    {
        $target = $this->transaction('イオン', $this->food);

        $response = $this->from(route('transactions.index'))->post(route('transactions.bulk-update'), [
            'scope' => 'selected',
            'ids' => range(1, TransactionController::BULK_MAX + 1),
            'bulk_category_id' => $this->daily->id,
        ]);

        $response->assertSessionHasErrors('ids');
        $this->assertSame($this->food->id, $target->fresh()->category_id);
    }

    public function test_一覧に件数の申告と未分類の注意書きが出る(): void
    {
        $this->transaction('イオン', $this->food);

        $response = $this->get(route('transactions.index'));

        $response->assertOk();
        $response->assertSee('name="filtered_count"', false);
        $response->assertSee('選んでください');
        $response->assertSee('未分類に戻す（チェックした取引のみ）');
    }

    public function test_種別の違うカテゴリはまとめて付けられない(): void
    {
        // 支出に収入カテゴリが付くと、月次も年次も集計が壊れる
        $salary = Category::factory()->create(['name' => '給与', 'type' => 'income']);
        $target = $this->transaction('イオン', $this->food);

        $response = $this->from(route('transactions.index'))->post(route('transactions.bulk-update'), [
            'scope' => 'selected',
            'ids' => [$target->id],
            'bulk_category_id' => $salary->id,
        ]);

        $response->assertSessionHasErrors('bulk');
        $this->assertSame($this->food->id, $target->fresh()->category_id);
    }

    public function test_種別が揃っていればまとめて変更できる(): void
    {
        $salary = Category::factory()->create(['name' => '給与', 'type' => 'income']);
        $bonus = Category::factory()->create(['name' => '賞与', 'type' => 'income']);
        $income = Transaction::factory()->create([
            'shop_name' => '勤務先',
            'category_id' => $salary->id,
            'type' => 'income',
            'transaction_date' => '2026-08-01',
            'amount' => 300000,
        ]);

        $this->post(route('transactions.bulk-update'), [
            'scope' => 'selected',
            'ids' => [$income->id],
            'bulk_category_id' => $bonus->id,
        ]);

        $this->assertSame($bonus->id, $income->fresh()->category_id);
    }

    public function test_カテゴリ未選択のエラーは重複して出ない(): void
    {
        $target = $this->transaction('イオン', $this->food);

        $this->from(route('transactions.index'))->post(route('transactions.bulk-update'), [
            'scope' => 'selected',
            'ids' => [$target->id],
            'bulk_category_id' => '',
        ]);

        // required とクロージャの両方が同じ文を出すと、赤枠に同じ行が2つ並ぶ
        $this->assertCount(1, session('errors')->getBag('default')->get('bulk_category_id'));
    }

    public function test_配列で送られても一覧が壊れない(): void
    {
        // 弾いた入力は old() に残る。配列のまま (string) に渡すと警告→500 になり、
        // 一括編集を弾いたつもりが一覧そのものを開けなくしてしまう。
        $target = $this->transaction('イオン', $this->food);

        $this->from(route('transactions.index'))->post(route('transactions.bulk-update'), [
            'scope' => 'selected',
            'ids' => [[$target->id]],
            'bulk_category_id' => [$this->daily->id],
        ])->assertRedirect(route('transactions.index'));

        $this->get(route('transactions.index'))->assertOk();
        $this->assertSame($this->food->id, $target->fresh()->category_id);
    }

    public function test_未ログインでは一括編集できない(): void
    {
        $target = $this->transaction('イオン', $this->food);

        \Illuminate\Support\Facades\Auth::logout();

        $this->post(route('transactions.bulk-update'), [
            'scope' => 'selected',
            'ids' => [$target->id],
            'bulk_category_id' => $this->daily->id,
        ])->assertRedirect('/login');

        $this->assertSame($this->food->id, $target->fresh()->category_id);
    }
}
