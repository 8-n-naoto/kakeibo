{{--
    カテゴリの <option> を、収入／支出の <optgroup> に分けて出す。

    支出の行に収入カテゴリを付けると、円グラフには出るのに固定／変動の内訳からは
    静かに漏れる。サーバ側でも弾いているが、そもそも選べないほうがよい。

    グループには data-category-group を付けてあり、layouts/app.blade.php の
    共通スクリプトが「同じ行／同じフォームの種別」に合わないグループを無効化する。
    JSが動かない環境では全部選べるままだが、その場合はサーバ側で弾かれる。

    使い方:
      <select name="category_id" data-category-select>
          @include('partials.category-options', [
              'categories' => $categories,
              'selectedCategoryId' => old('category_id', $t->category_id ?? null),
          ])
      </select>

    引数:
      $categories          … Category のコレクション
      $selectedCategoryId  … 選択中の id（配列やnullが来ても落ちない）
      $categoryBlankLabel  … 先頭の空欄のラベル（既定は「未分類」）
--}}
@php
    $currentCategoryId = isset($selectedCategoryId) && is_scalar($selectedCategoryId)
        ? (string) $selectedCategoryId
        : '';
    $groupedCategories = collect($categories)->groupBy('type');
@endphp
<option value="" @selected($currentCategoryId === '')>{{ $categoryBlankLabel ?? '未分類' }}</option>
@foreach (['expense' => '支出', 'income' => '収入'] as $categoryGroupType => $categoryGroupLabel)
    @if (($groupedCategories[$categoryGroupType] ?? collect())->isNotEmpty())
        <optgroup label="{{ $categoryGroupLabel }}" data-category-group="{{ $categoryGroupType }}">
            @foreach ($groupedCategories[$categoryGroupType] as $category)
                <option value="{{ $category->id }}" @selected($currentCategoryId === (string) $category->id)>{{ $category->name }}</option>
            @endforeach
        </optgroup>
    @endif
@endforeach
