<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', '家計簿アプリ')</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
</head>
<body class="bg-slate-50 text-slate-800 min-h-screen">
    <nav class="bg-white border-b border-slate-200">
        <div class="max-w-5xl mx-auto px-4 py-3 flex items-center justify-between">
            <a href="{{ route('dashboard') }}" class="font-bold text-lg text-slate-800">📒 家計簿アプリ</a>
            <div class="flex gap-4 text-sm flex-wrap">
                <a href="{{ route('dashboard') }}" class="hover:text-emerald-600">ダッシュボード</a>
                <a href="{{ route('transactions.index') }}" class="hover:text-emerald-600">取引一覧</a>
                <a href="{{ route('transactions.create') }}" class="hover:text-emerald-600">手動入力</a>
                <a href="{{ route('receipts.create') }}" class="hover:text-emerald-600">レシート読込</a>
                <a href="{{ route('imports.create') }}" class="hover:text-emerald-600">CSV取込</a>
                <a href="{{ route('budgets.index') }}" class="hover:text-emerald-600">予算</a>
                <a href="{{ route('assets.index') }}" class="hover:text-emerald-600">資産推移</a>
                <a href="{{ route('savings-goals.index') }}" class="hover:text-emerald-600">貯蓄目標</a>
                <a href="{{ route('investment-accounts.index') }}" class="hover:text-emerald-600">NISA/iDeCo</a>
                <a href="{{ route('categories.index') }}" class="hover:text-emerald-600">カテゴリ</a>
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
</body>
</html>
