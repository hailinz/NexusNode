<?php

namespace App\Services;

/**
 * 宽松 base64 解码：兼容 URL-safe 字符与缺失的 padding
 */
class Base64Helper
{
    public static function decodeFlexible(string $value): string|false
    {
        $value = strtr(trim($value), ['-' => '+', '_' => '/']);
        $pad = strlen($value) % 4;
        if ($pad === 2) {
            $value .= '==';
        } elseif ($pad === 3) {
            $value .= '=';
        } elseif ($pad === 1) {
            return false;
        }

        return base64_decode($value, true);
    }
}
