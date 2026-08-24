<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', '家計簿アプリ')</title>
    <meta name="theme-color" content="#059669">
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <link rel="icon" href="{{ asset('icons/favicon-32.png') }}" sizes="32x32" type="image/png">
    <link rel="apple-touch-icon" href="{{ asset('icons/apple-touch-icon.png') }}">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="家計簿">

    {{-- CDNのバージョンは固定する。浮動指定だと、配布元が乗っ取られた日に
         家計簿を開いた瞬間このオリジンで他人のJSが動く（ログイン画面にも入っている）。 --}}
    <script src="https://cdn.tailwindcss.com/3.4.17"></script>
    {{-- integrity は「この中身以外は実行しない」の意味。上げるときは
         https://data.jsdelivr.com/v1/packages/npm/chart.js@<版>?structure=flat の
         /dist/chart.umd.js の hash（sha256のbase64）をそのまま貼る。
         .min.js ではなく実ファイルを指すこと（.min.js は jsDelivr が生成する別物で、SRIが付けられない）。 --}}
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.js"
            integrity="sha256-KBLLiCX9xXRp6y97sFXpQpJE5ZmSBRHuR36ChJm2Mss="
            crossorigin="anonymous"></script>
    <script>
        // CDNが落ちた・integrity が合わない・CSPで弾かれた、のいずれでも Chart は未定義になる。
        // 各画面の <script> は冒頭で new Chart(...) を呼ぶので、そこで ReferenceError になると
        // 同じブロックの残り（表の操作など）まで巻き添えで止まる。
        // グラフだけ諦めて、画面の他の動作は生かす。
        if (!window.Chart) {
            window.Chart = function () {
                console.warn('Chart.js を読み込めませんでした。グラフは表示されません。');
            };
        }
    </script>
    <script>
        // カテゴリの選択肢を、同じ行（またはフォーム）の「種別」に合わせて絞る。
        // 支出の行に収入カテゴリが付くと、円グラフには出るのに固定／変動の内訳からは
        // 静かに漏れる。サーバ側でも弾いているので、ここが動かなくても保存はされない。
        document.addEventListener('DOMContentLoaded', function () {
            // 「その種別のコントロールを実際に持っている」いちばん近い祖先を探す。
            // tr で打ち切ると、種別が表の外にある画面で絞り込みが効かなくなる。
            // data-type-scope があるならそこで打ち切る（外に出ると別の行の種別を拾う）。
            function typeControl(select) {
                var scope = select.closest('[data-type-scope]');
                var stop = scope || select.form || document.body;
                var node = scope || select.parentElement;

                while (node) {
                    var control = node.querySelector('[data-type-control]');

                    if (control) { return control; }
                    if (node === stop) { return null; }

                    node = node.parentElement;
                }

                return null;
            }

            function sync(select) {
                var control = typeControl(select);
                var type = control ? control.value : null;
                var groups = select.querySelectorAll('optgroup[data-category-group]');

                if (groups.length === 0) { return; }

                // この機能を入れる前のデータには種別違いの行が普通に存在する。
                // 黙って「未分類」に戻すと、編集画面を開いただけでカテゴリが消え、
                // 次の保存で本当に消える。だから **最初に表示されていた値のまま** のときだけ、
                // そのグループを残す。ユーザーが選び直した時点で、絞り込みは普通に効く。
                var chosenGroup = select.value === select.dataset.initialCategory
                    ? (select.options[select.selectedIndex] || {}).parentElement
                    : null;

                groups.forEach(function (group) {
                    // 種別が取れないときは絞らない（絞ると全部消えて選べなくなる）
                    var match = !type || group.dataset.categoryGroup === type || group === chosenGroup;
                    group.disabled = !match;
                    group.hidden = !match;
                });

                // 無効にしたグループの中身が選ばれたままだと、見えないのに送信されて
                // サーバ側で弾かれる。ここで外しておく（初期値だけは上で守ってある）。
                var current = select.options[select.selectedIndex];

                if (current && current.parentElement && current.parentElement.disabled) {
                    select.value = '';
                }
            }

            document.querySelectorAll('select[data-category-select]').forEach(function (select) {
                select.dataset.initialCategory = select.value;

                var control = typeControl(select);

                if (control) {
                    control.addEventListener('change', function () { sync(select); });
                }

                sync(select);
            });
        });
    </script>
</head>
<body class="bg-slate-50 text-slate-800 min-h-screen">
    <nav class="bg-white border-b border-slate-200">
        <div class="max-w-5xl mx-auto px-4 py-3 flex items-center gap-4 md:justify-between">
            <a href="{{ route('dashboard') }}" class="font-bold text-lg text-slate-800 shrink-0">📒 <span class="hidden sm:inline">家計簿アプリ</span></a>
            <div class="flex gap-4 text-sm overflow-x-auto whitespace-nowrap md:flex-wrap md:whitespace-normal md:overflow-visible items-center">
                <a href="{{ route('dashboard') }}" class="hover:text-emerald-600">ダッシュボード</a>
                <a href="{{ route('transactions.index') }}" class="hover:text-emerald-600">取引一覧</a>
                <a href="{{ route('reports.annual') }}" class="hover:text-emerald-600">年間まとめ</a>
                <a href="{{ route('transactions.create') }}" class="hover:text-emerald-600">手動入力</a>
                <a href="{{ route('receipts.create') }}" class="hover:text-emerald-600">レシート読込</a>
                <a href="{{ route('receipts.pending') }}" class="hover:text-emerald-600">未登録レシート</a>
                <a href="{{ route('imports.create') }}" class="hover:text-emerald-600">CSV取込</a>
                <a href="{{ route('budgets.index') }}" class="hover:text-emerald-600">予算</a>
                <a href="{{ route('recurring.index') }}" class="hover:text-emerald-600">定期支出</a>
                <a href="{{ route('assets.index') }}" class="hover:text-emerald-600">資産推移</a>
                <a href="{{ route('savings-goals.index') }}" class="hover:text-emerald-600">貯蓄目標</a>
                <a href="{{ route('investment-accounts.index') }}" class="hover:text-emerald-600">NISA/iDeCo</a>
                <a href="{{ route('categories.index') }}" class="hover:text-emerald-600">カテゴリ</a>
                <a href="{{ route('merchant-rules.index') }}" class="hover:text-emerald-600">自動分類</a>
                @auth
                    <form method="POST" action="{{ route('logout') }}" class="inline">
                        @csrf
                        <button class="text-slate-400 hover:text-rose-600">ログアウト</button>
                    </form>
                @endauth
            </div>
        </div>
    </nav>

    <main class="max-w-5xl mx-auto px-4 py-6">
        @if (session('status'))
            <div class="mb-4 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-2 text-sm">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-4 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 px-4 py-2 text-sm">
                <ul class="list-disc list-inside">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @yield('content')
    </main>
    <script>
        // Service Worker は画面(HTML)をキャッシュしない。オフライン時の案内と静的ファイルだけ担当する。
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () {
                navigator.serviceWorker.register('{{ asset('sw.js') }}').catch(function (error) {
                    console.warn('Service Worker の登録に失敗しました', error);
                });
            });
        }
    </script>
</body>
</html>
