<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>NexusNode · 节点订阅管理平台</title>
    <script src="{{ asset('vendor/qrcode.min.js') }}"></script>
    <script src="{{ asset('vendor/tailwindcss.js') }}"></script>
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
    <script type="module" src="{{ asset('js/app.js') }}"></script>
</body>
</html>
