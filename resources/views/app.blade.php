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
    {{-- 表单控件基础样式：未引入 @tailwindcss/forms，preflight 会把边框和内边距清零（输入框看不出是输入框、下拉框仅 20px 高）。
         放在 base 层：覆盖 preflight，同时仍可被元素上的 utility（py-1 / text-xs 等）覆盖 --}}
    <style type="text/tailwindcss">
        @layer base {
            input:where(:not([type=checkbox]):not([type=radio]):not([type=file]):not([type=hidden])), select, textarea {
                @apply border border-slate-200 bg-white px-3 py-2 text-slate-800 placeholder:text-slate-400;
            }
            input:where(:not([type=checkbox]):not([type=radio])):focus, select:focus, textarea:focus {
                @apply border-indigo-400 outline-none ring-2 ring-indigo-100;
            }
            select { @apply pr-8; }
            input[type=checkbox] { @apply cursor-pointer accent-indigo-600; }
            button, a, label { -webkit-tap-highlight-color: transparent; }
            /* iOS Safari 聚焦字号 < 16px 的输入框会自动放大页面 */
            @media (max-width: 639px) {
                input:where(:not([type=checkbox]):not([type=radio])), select, textarea { font-size: 16px !important; }
                /* 复选框在手机上放大到 20px（min-* 不受 h-4 / w-4 utility 影响） */
                input[type=checkbox] { min-width: 1.25rem; min-height: 1.25rem; }
            }
        }
    </style>
</head>
<body class="min-h-screen bg-slate-50 font-sans text-slate-900 antialiased">
    <div id="app"></div>
    {{-- import map：把每个 ES 模块映射到带 filemtime 版本号的地址。
         app.js 自带 ?v，但它 import 的页面模块没有，部署后浏览器可能拿到「新 app.js + 旧缓存模块」，导出不匹配会整页白屏 --}}
    @php
        $jsModules = collect(\Illuminate\Support\Facades\File::allFiles(public_path('js')))
            ->filter(fn ($f) => $f->getExtension() === 'js')
            ->mapWithKeys(function ($f) {
                $url = asset('js/' . str_replace('\\', '/', $f->getRelativePathname()));
                return [$url => $url . '?v=' . $f->getMTime()];
            });
    @endphp
    <script type="importmap">{!! json_encode(['imports' => $jsModules], JSON_UNESCAPED_SLASHES) !!}</script>
    <script type="module" src="{{ asset('js/app.js') }}?v={{ filemtime(public_path('js/app.js')) }}"></script>
</body>
</html>
