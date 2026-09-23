<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>NexusNode · 节点订阅管理平台</title>
    {{-- filemtime 版本参数：文件更新后 URL 随之变化，避免 CF/浏览器缓存旧 JS --}}
<script src="{{ asset('vendor/qrcode.min.js') }}?v={{ filemtime(public_path('vendor/qrcode.min.js')) }}"></script>
<script src="{{ asset('vendor/tailwindcss.js') }}?v={{ filemtime(public_path('vendor/tailwindcss.js')) }}"></script>
<script src="{{ asset('vendor/sortable/Sortable.min.js') }}?v={{ filemtime(public_path('vendor/sortable/Sortable.min.js')) }}"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['system-ui', '-apple-system', 'Segoe UI', 'PingFang SC', 'Microsoft YaHei', 'sans-serif'],
                        mono: ['ui-monospace', 'SFMono-Regular', 'Consolas', 'monospace'],
                    },
                },
            },
        };
    </script>
    <style>
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 8px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
    </style>
</head>
<body class="min-h-screen bg-slate-50 font-sans text-slate-900 antialiased">
    <div id="app"></div>
    <script type="module" src="{{ asset('js/app.js') }}?v={{ filemtime(public_path('js/app.js')) }}"></script>
</body>
</html>
