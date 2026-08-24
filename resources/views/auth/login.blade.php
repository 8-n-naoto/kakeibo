<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ログイン | 家計簿アプリ</title>
    <meta name="theme-color" content="#059669">
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <link rel="icon" href="{{ asset('icons/favicon-32.png') }}" sizes="32x32" type="image/png">
    <link rel="apple-touch-icon" href="{{ asset('icons/apple-touch-icon.png') }}">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="家計簿">

    {{-- バージョンは固定する。ここは未ログインでも開く画面なので、
         乗っ取られた配布物が動くとパスワードをそのまま読まれる。 --}}
    <script src="https://cdn.tailwindcss.com/3.4.17"></script>
</head>
<body class="bg-slate-50 text-slate-800 min-h-screen flex items-center justify-center px-4">
    <div class="w-full max-w-sm">
        <div class="text-center mb-6">
            <div class="text-2xl font-bold">📒 家計簿アプリ</div>
            <p class="text-sm text-slate-500 mt-1">ログインしてください</p>
        </div>

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

        <form method="POST" action="{{ route('login.store') }}"
              class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
            @csrf
            <div class="mb-4">
                <label class="block text-sm text-slate-600 mb-1">メールアドレス</label>
                <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div class="mb-4">
                <label class="block text-sm text-slate-600 mb-1">パスワード</label>
                <input type="password" name="password" required autocomplete="current-password"
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <label class="flex items-center gap-2 text-sm text-slate-600 mb-4">
                <input type="checkbox" name="remember" value="1" class="rounded border-slate-300">
                ログイン状態を保持する
            </label>
            <button class="w-full bg-emerald-600 text-white text-sm px-5 py-2 rounded-lg hover:bg-emerald-700">
                ログイン
            </button>
        </form>

        <p class="text-xs text-slate-400 mt-4 text-center">
            アカウントは <code class="bg-slate-100 px-1 rounded">php artisan user:create</code> で作成します。
        </p>
    </div>
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
