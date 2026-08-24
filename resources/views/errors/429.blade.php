<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>しばらく待ってください | 家計簿アプリ</title>
    {{-- CDNのバージョンは固定する（README「セキュリティと公開範囲」） --}}
    <script src="https://cdn.tailwindcss.com/3.4.17"></script>
</head>
<body class="bg-slate-50 text-slate-800 min-h-screen flex items-center justify-center px-4">
    <div class="w-full max-w-sm text-center">
        <div class="text-2xl font-bold mb-2">📒 家計簿アプリ</div>
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
            <p class="text-sm text-slate-700">
                短い時間に試行が多すぎました。<br>
                1分ほど待ってから、もう一度お試しください。
            </p>
            @if (! empty($exception) && method_exists($exception, 'getHeaders') && ! empty($exception->getHeaders()['Retry-After']))
                <p class="text-xs text-slate-500 mt-3">
                    あと約 {{ (int) $exception->getHeaders()['Retry-After'] }} 秒
                </p>
            @endif
            <a href="{{ url('/login') }}" class="inline-block mt-4 text-sm text-emerald-700 hover:underline">
                ログイン画面に戻る
            </a>
        </div>
        <p class="text-xs text-slate-400 mt-4">
            心当たりが無いのにこの画面が出る場合は、<code>storage/logs/laravel.log</code> を確認してください。
        </p>
    </div>
</body>
</html>
