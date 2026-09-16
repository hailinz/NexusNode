<?php

namespace App\Http\Controllers;

use App\Services\ImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 批量导入 API（/api/v1/imports/nodes）
 */
class ImportController extends Controller
{
    public function __construct(private readonly ImportService $importService)
    {
    }

    /**
     * 导入节点：多行文本或 base64 订阅内容（前端负责读取文件文本后提交 content）
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate(['content' => ['required', 'string', 'max:2000000']]);

        if (trim($request->input('content')) === '') {
            return response()->json(['message' => '导入内容为空'], 422);
        }

        $stats = $this->importService->importText($request->input('content'));

        return response()->json([
            'message' => "导入完成：新增 {$stats['created']} 个，重复跳过 {$stats['skipped']} 个，失败 {$stats['failed']} 个",
            'stats' => $stats,
        ]);
    }
}
