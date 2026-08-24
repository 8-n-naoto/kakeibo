@extends('layouts.app')

@section('title', '未登録のレシート | 家計簿アプリ')

@section('content')
    <div class="flex items-center justify-between mb-2 flex-wrap gap-2">
        <h1 class="text-xl font-bold">未登録のレシート</h1>
        <a href="{{ route('receipts.create') }}" class="text-sm text-emerald-700 hover:underline">＋ レシートを追加で読み込む</a>
    </div>
    <p class="text-sm text-slate-500 mb-6">
        解析が終わっていて、まだ家計簿に登録していないレシートです。内容を確認・修正し、登録するものにチェックを入れてまとめて登録します。
    </p>

    @php
        $retryable = $unparsed->pluck('id')->concat($failedReceipts->pluck('id'))->values();
    @endphp

    @if ($unparsed->isNotEmpty() || $failedReceipts->isNotEmpty())
        <div id="parse-panel"
             class="mb-6 rounded-xl border border-sky-200 bg-sky-50 p-4"
             data-auto-parse="{{ $autoStart && $unparsed->isNotEmpty() ? '1' : '0' }}">
            <div class="text-sm font-semibold text-sky-900 mb-1">レシートの読み取り</div>
            <p class="text-xs text-sky-800 mb-3">
                @if ($unparsed->isNotEmpty())
                    <span id="parse-remaining">{{ $unparsed->count() }}</span> 枚がまだ読み取れていません。
                    1枚ずつ順番にAIへ送ります（1枚あたり数秒）。途中でこの画面を閉じても、残りは次に開いたときに続けられます。
                @else
                    読み取り待ちのレシートはありません。
                @endif
                @if ($failedTotal > 0)
                    <span class="block mt-1">読み取りに失敗したものが {{ $failedTotal }} 枚あります。一時的なAPIエラーなら再試行で復帰します。</span>
                @endif
                {{-- AI呼び出しは1枚ごとに課金されるので、使った枚数を見えるところに出しておく --}}
                <span class="block mt-1 text-sky-700" id="parse-quota">
                    今日のAI読み取り: {{ $aiUsedToday }} / {{ $aiDailyLimit }} 枚
                    @if ($aiUsedToday >= $aiDailyLimit)
                        <span class="text-rose-700">（上限に達しています。明日以降か、RECEIPT_AI_DAILY_LIMIT の見直しを）</span>
                    @endif
                </span>
            </p>

            <div class="w-full h-2 bg-sky-100 rounded-full overflow-hidden mb-3">
                <div id="parse-progress" class="h-full bg-sky-600 transition-all" style="width: 0%"></div>
            </div>

            <div id="parse-log" class="text-xs text-sky-900 space-y-0.5 mb-3"></div>

            <div class="flex flex-wrap gap-2">
                @if ($unparsed->isNotEmpty())
                    <button type="button" class="parse-start bg-sky-700 text-white text-sm px-5 py-2 rounded-lg hover:bg-sky-800 disabled:opacity-50"
                            data-target="unparsed">
                        読み取りを開始する（{{ $unparsed->count() }}枚）
                    </button>
                @endif
                @if ($failedReceipts->isNotEmpty())
                    <button type="button" class="parse-start bg-white border border-sky-300 text-sky-800 text-sm px-5 py-2 rounded-lg hover:bg-sky-100 disabled:opacity-50"
                            data-target="retryable">
                        失敗した分も含めて再試行する（{{ $retryable->count() }}枚）
                    </button>
                @endif
            </div>

            <noscript>
                <div class="mt-3 space-y-2">
                    <p class="text-xs text-sky-800">JavaScript が無効なときは、1枚ずつボタンで読み取ってください。</p>
                    @foreach ($unparsed as $receipt)
                        <form method="POST" action="{{ route('receipts.parse', $receipt) }}" class="inline">
                            @csrf
                            <button class="text-xs bg-white border border-sky-300 rounded px-3 py-1 hover:bg-sky-100">
                                #{{ $receipt->id }} を読み取る
                            </button>
                        </form>
                    @endforeach
                </div>
            </noscript>
        </div>

        <script>
            (function () {
                var panel = document.getElementById('parse-panel');
                if (!panel) { return; }

                // 自動開始はクエリ文字列で伝わってくる。読み込んだ時点で URL から消しておく。
                // これを残したままだと、読み取り後の location.reload() で自動開始が
                // 何度でも再点火し、AI呼び出し（＝課金）が止まらなくなる。
                var autoStart = panel.dataset.autoParse === '1';
                var stripped = false;

                if (window.history && window.history.replaceState) {
                    try {
                        var url = new URL(window.location.href);
                        url.searchParams.delete('autostart');
                        window.history.replaceState({}, '', url.pathname + url.search + url.hash);
                        stripped = true;
                    } catch (urlError) {
                        stripped = false;
                    }
                }

                // 剥がせなかった環境で自動開始すると、reload のたびに再点火してしまう。
                // 手動のボタンは残るので、機能が失われるわけではない。
                if (!stripped) { autoStart = false; }
                panel.dataset.autoParse = '0';

                var targets = {
                    unparsed: @json($unparsed->pluck('id')->values()),
                    retryable: @json($retryable),
                };

                var buttons = document.querySelectorAll('.parse-start');
                var remaining = document.getElementById('parse-remaining');
                var progress = document.getElementById('parse-progress');
                var log = document.getElementById('parse-log');
                var token = document.querySelector('meta[name="csrf-token"]');
                var running = false;

                function note(text, ok) {
                    var line = document.createElement('div');
                    line.textContent = text;
                    if (!ok) { line.className = 'text-rose-700'; }
                    log.appendChild(line);
                }

                function parseOne(id) {
                    return fetch('{{ url('/receipts') }}/' + id + '/parse', {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': token ? token.content : '',
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        credentials: 'same-origin',
                    }).then(function (response) {
                        return response.json().catch(function () {
                            return {};
                        }).then(function (body) {
                            return { status: response.status, body: body || {} };
                        });
                    }).catch(function () {
                        // ネットワーク断。0 は「HTTPまで到達しなかった」の目印にする
                        return { status: 0, body: {} };
                    });
                }

                // 続けても意味がないので、ここで打ち切る。
                // 5xx と 429 も含める。500 が返ると status が pending のまま残ることがあり、
                // 打ち切らずに回し続けると同じ画像を何度もAIに投げてしまう（課金が止まらない）。
                function isFatal(status) {
                    return status === 401 || status === 403 || status === 419
                        || status === 429 || status >= 500;
                }

                function fatalMessage(status, body) {
                    if (status === 429) {
                        return (body && body.reason === 'daily_limit')
                            ? '今日のAI読み取り上限に達しました。ここで打ち切ります。'
                            : '短時間に読み取りすぎたので、少し待ってからやり直してください。';
                    }
                    if (status >= 500) {
                        return 'サーバ側でエラーが起きました。安全のためここで打ち切ります。';
                    }
                    return 'ログインの有効期限が切れました。ログインし直してから、もう一度開始してください。';
                }

                // 立て続けに失敗するときは、原因が直るまで回しても課金が増えるだけ
                var MAX_CONSECUTIVE_FAILURES = 3;

                async function run(button) {
                    if (running) { return; }

                    var ids = targets[button.dataset.target] || [];
                    if (ids.length === 0) { return; }

                    running = true;
                    buttons.forEach(function (b) { b.disabled = true; });
                    var label = button.textContent;
                    button.textContent = '読み取り中…';

                    var done = 0;
                    var failures = 0;
                    var streak = 0;
                    var aborted = false;

                    for (var i = 0; i < ids.length; i++) {
                        var result = await parseOne(ids[i]);

                        if (isFatal(result.status)) {
                            aborted = true;
                            note(fatalMessage(result.status, result.body), false);
                            break;
                        }

                        done++;
                        if (remaining) { remaining.textContent = String(Math.max(ids.length - done, 0)); }
                        if (progress) { progress.style.width = Math.round((done / ids.length) * 100) + '%'; }

                        if (result.status !== 200 || result.body.ok !== true) {
                            failures++;
                            streak++;
                            note('#' + ids[i] + ' ' + (result.body.message || (result.status === 0 ? '通信に失敗しました' : 'HTTP ' + result.status)), false);

                            if (streak >= MAX_CONSECUTIVE_FAILURES) {
                                aborted = true;
                                note(streak + '枚続けて失敗したので打ち切りました。原因を直してからやり直してください。', false);
                                break;
                            }
                        } else {
                            streak = 0;
                        }
                    }

                    if (aborted) {
                        // 失敗した分は failed になっているので、読み直せば対象から外れる。
                        // reload までボタンは押させない（押されると同じ分をまたAIに投げる）。
                        // autostart は剥がしてあるので、読み直しても自動開始はしない。
                        button.textContent = '画面を更新します…';
                        note('画面を更新します…', false);
                        setTimeout(function () { location.reload(); }, 3000);
                        return;
                    }

                    button.textContent = failures > 0
                        ? '完了（' + failures + '枚は失敗）。画面を更新します…'
                        : '完了。画面を更新します…';

                    setTimeout(function () { location.reload(); }, failures > 0 ? 2500 : 600);
                }

                buttons.forEach(function (button) {
                    button.addEventListener('click', function () { run(button); });
                });

                if (autoStart) {
                    var auto = document.querySelector('.parse-start[data-target="unparsed"]');
                    if (auto) { run(auto); }
                }
            })();
        </script>
    @endif

    @if ($rows->isEmpty() && $unparsed->isEmpty() && $failedReceipts->isEmpty())
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-8 text-center text-sm text-slate-500">
            未登録のレシートはありません。
        </div>
    @elseif ($rows->isNotEmpty())
        @php
            // 入力エラーで戻ってきたときに、外したチェックが復活しないようにする
            $hasOldRows = old('rows') !== null;
        @endphp

        <form method="POST" action="{{ route('receipts.pending.store') }}">
            @csrf

            <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
                <label class="text-sm text-slate-600 flex items-center gap-2">
                    <input type="checkbox" id="toggle-all" checked class="rounded border-slate-300">
                    すべて選択 / 解除
                </label>
                <span class="text-sm text-slate-500">{{ $rows->count() }} 件</span>
            </div>

            <div class="space-y-4">
                @foreach ($rows as $row)
                    @php
                        $receipt = $row['receipt'];
                        $parsed = $row['parsed'];
                        $suggestedCategoryId = $row['suggested_category_id'];
                        $rowWarnings = $row['warnings'] ?? [];
                        $rowKey = 'rows.'.$receipt->id;

                        $rowHasError = $errors->hasAny([
                            $rowKey.'.transaction_date', $rowKey.'.amount', $rowKey.'.category_id',
                            $rowKey.'.shop_name', $rowKey.'.memo', $rowKey.'.type',
                        ]);
                    @endphp

                    <div data-type-scope
                         class="bg-white rounded-xl shadow-sm border p-4 {{ $rowHasError ? 'border-rose-300 ring-2 ring-rose-200' : ($rowWarnings !== [] ? 'border-amber-300' : 'border-slate-200') }}">
                        @if ($receipt->error_message)
                            <div class="mb-3 rounded-lg bg-rose-50 border border-rose-200 px-3 py-2 text-xs text-rose-800">
                                <span class="font-semibold">前回の読み直しに失敗しました。</span>
                                下に出ているのは前回までの読み取り結果です：{{ \Illuminate\Support\Str::limit($receipt->error_message, 120) }}
                            </div>
                        @endif
                        @if ($rowWarnings !== [])
                            <div class="mb-3 rounded-lg bg-amber-50 border border-amber-200 px-3 py-2 text-xs text-amber-800">
                                <span class="font-semibold">目で確かめてください：</span>
                                {{ implode('、', $rowWarnings) }}
                            </div>
                        @endif
                        <div class="grid grid-cols-1 md:grid-cols-[180px_1fr] gap-4">
                            <div>
                                <label class="flex items-center gap-2 text-sm text-slate-600 mb-2">
                                    <input type="checkbox" name="rows[{{ $receipt->id }}][selected]" value="1"
                                           @checked($hasOldRows ? old($rowKey.'.selected') : true)
                                           class="row-select rounded border-slate-300">
                                    登録する
                                </label>
                                <a href="{{ asset('storage/'.$receipt->path) }}" target="_blank" rel="noopener">
                                    <img src="{{ asset('storage/'.$receipt->path) }}" alt="レシート画像"
                                         class="w-full rounded-lg border border-slate-200">
                                </a>
                                <a href="{{ route('receipts.confirm', $receipt) }}"
                                   class="mt-2 inline-block text-xs text-slate-500 hover:underline">1枚ずつ確認する</a>
                                <button type="submit" form="reparse-{{ $receipt->id }}"
                                        class="mt-1 block text-xs text-slate-500 hover:underline">AIで読み直す</button>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-xs text-slate-500 mb-1">日付</label>
                                    <input type="date" name="rows[{{ $receipt->id }}][transaction_date]"
                                           value="{{ old($rowKey.'.transaction_date', $parsed['transaction_date'] ?? now()->format('Y-m-d')) }}"
                                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
                                    @error($rowKey.'.transaction_date')
                                        <p class="text-xs text-rose-600 mt-1">{{ $message }}</p>
                                    @enderror
                                </div>
                                <div>
                                    <label class="block text-xs text-slate-500 mb-1">金額(円)</label>
                                    <input type="number" min="0" name="rows[{{ $receipt->id }}][amount]"
                                           value="{{ old($rowKey.'.amount', $parsed['total_amount'] ?? '') }}"
                                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
                                    @error($rowKey.'.amount')
                                        <p class="text-xs text-rose-600 mt-1">{{ $message }}</p>
                                    @enderror
                                </div>
                                <div>
                                    <label class="block text-xs text-slate-500 mb-1">種別</label>
                                    <select name="rows[{{ $receipt->id }}][type]" data-type-control
                                            class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
                                        <option value="expense" @selected(old($rowKey.'.type', $parsed['type'] ?? 'expense') === 'expense')>支出</option>
                                        <option value="income" @selected(old($rowKey.'.type', $parsed['type'] ?? 'expense') === 'income')>収入</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs text-slate-500 mb-1">カテゴリ</label>
                                    <select name="rows[{{ $receipt->id }}][category_id]" data-category-select
                                            class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
                                        @include('partials.category-options', [
                                            'categories' => $categories,
                                            'selectedCategoryId' => old($rowKey.'.category_id', $suggestedCategoryId),
                                        ])
                                    </select>
                                    @error($rowKey.'.category_id')
                                        <p class="text-xs text-rose-600 mt-1">{{ $message }}</p>
                                    @enderror
                                </div>
                                <div>
                                    <label class="block text-xs text-slate-500 mb-1">店名</label>
                                    <input type="text" name="rows[{{ $receipt->id }}][shop_name]"
                                           value="{{ old($rowKey.'.shop_name', $parsed['shop_name'] ?? '') }}"
                                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
                                </div>
                                <div>
                                    <label class="block text-xs text-slate-500 mb-1">メモ</label>
                                    <input type="text" name="rows[{{ $receipt->id }}][memo]"
                                           value="{{ old($rowKey.'.memo', $parsed['memo'] ?? '') }}"
                                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
                                </div>

                                @if (! empty($parsed['items']) && is_array($parsed['items']))
                                    <div class="sm:col-span-2 text-xs text-slate-500">
                                        <span class="font-semibold">読み取れた品目:</span>
                                        {{ collect($parsed['items'])
                                            ->filter(fn ($i) => is_array($i))
                                            ->map(fn ($i) => ($i['name'] ?? '不明').' ¥'.number_format((int) ($i['amount'] ?? 0)))
                                            ->implode(' / ') }}
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <button class="mt-6 bg-emerald-600 text-white text-sm px-6 py-2.5 rounded-lg hover:bg-emerald-700">
                チェックしたレシートをまとめて登録する
            </button>
        </form>

        {{-- 「AIで読み直す」用のフォーム。上のフォームの中に置くと入れ子になってしまうのでここに出す --}}
        @foreach ($rows as $row)
            <form method="POST" action="{{ route('receipts.parse', $row['receipt']) }}"
                  id="reparse-{{ $row['receipt']->id }}" class="hidden"
                  onsubmit="return confirm('AIに読み直させます。この画面で編集した内容は失われます。よろしいですか？');">
                @csrf
                <input type="hidden" name="retry" value="1">
            </form>
        @endforeach

        <script>
            document.getElementById('toggle-all')?.addEventListener('change', function () {
                document.querySelectorAll('.row-select').forEach(function (checkbox) {
                    checkbox.checked = this.checked;
                }, this);
            });
        </script>
    @endif

    @php
        $cleanupTotal = $cleanup['failed'] + $cleanup['abandoned'] + $cleanup['awaiting'] + $cleanup['orphan_files'];
    @endphp

    @if ($cleanupTotal > 0)
        <div class="mt-10 bg-white rounded-xl shadow-sm border border-slate-200 p-4">
            <div class="text-sm font-semibold text-slate-600 mb-1">使われていないレシート画像</div>
            <p class="text-xs text-slate-500 mb-3">
                家計簿に登録されていない画像が <span class="font-semibold">{{ $cleanupTotal }}</span> 件あります。
                <span class="block mt-1">
                    読み取り失敗 {{ $cleanup['failed'] }} / 読み取り済みで未登録 {{ $cleanup['abandoned'] }}
                    / DBに無いファイル {{ $cleanup['orphan_files'] }}
                    @if ($cleanup['awaiting'] > 0)
                        / <span class="font-semibold">読み取り待ちのまま放置 {{ $cleanup['awaiting'] }}</span>
                    @endif
                    （いずれも{{ $retentionDays }}日より前のもの）
                </span>
                @if ($cleanup['awaiting'] > 0)
                    <span class="block mt-1 text-amber-700">
                        「読み取り待ちのまま放置」は、上のパネルで読み取れば残せます。先に読み取ってから片付けてください。
                    </span>
                @endif
                合計 {{ $cleanup['bytes'] >= 1048576 ? round($cleanup['bytes'] / 1048576, 1).'MB' : round($cleanup['bytes'] / 1024).'KB' }}。
                <span class="block mt-1">取引が紐づいている画像は消えません。</span>
            </p>
            <form method="POST" action="{{ route('receipts.cleanup') }}"
                  onsubmit="return confirm('使われていないレシート画像を削除します。取引に紐づいた画像は残ります。よろしいですか?');">
                @csrf
                <button class="bg-slate-700 text-white text-sm px-4 py-2 rounded-lg hover:bg-slate-800">まとめて片付ける</button>
            </form>
        </div>
    @endif

    @if ($failedReceipts->isNotEmpty())
        <div class="mt-10">
            <h2 class="text-sm font-semibold text-slate-600 mb-2">
                読み取りに失敗したレシート
                @if ($failedTotal > $failedReceipts->count())
                    <span class="font-normal text-slate-400">（{{ $failedTotal }} 件のうち新しい {{ $failedReceipts->count() }} 件）</span>
                @endif
            </h2>
            <div class="bg-white rounded-xl shadow-sm border border-rose-200 divide-y divide-slate-100">
                @foreach ($failedReceipts as $failed)
                    <div class="px-4 py-3 text-xs text-slate-600 flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <a href="{{ asset('storage/'.$failed->path) }}" target="_blank" rel="noopener"
                               class="block truncate underline hover:text-slate-900">{{ $failed->path }}</a>
                            <span class="text-rose-600 block mt-0.5">{{ Str::limit($failed->error_message, 160) }}</span>
                        </div>
                        <div class="shrink-0 flex items-center gap-2">
                            <a href="{{ route('receipts.confirm', $failed) }}"
                               class="text-slate-500 hover:text-emerald-600 underline">手入力で登録</a>
                            <form method="POST" action="{{ route('receipts.parse', $failed) }}">
                                @csrf
                                <button class="bg-white border border-slate-300 rounded px-3 py-1 hover:bg-slate-100">
                                    読み取り直す
                                </button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
            <p class="text-xs text-slate-400 mt-2">
                一時的なエラーなら「読み取り直す」で復帰します。写真が不鮮明な場合は撮り直すか、手動入力で登録してください。
            </p>
        </div>
    @endif
@endsection
