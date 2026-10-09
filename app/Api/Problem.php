<?php

namespace App\Api;

use Illuminate\Http\JsonResponse;

/**
 * Fehlerantworten nach RFC 9457 („Problem Details for HTTP APIs“), Content-Type application/problem+json.
 */
final class Problem
{
    public static function antwort(int $status, string $titel, ?string $detail = null, string $typ = 'about:blank', array $mehr = []): JsonResponse
    {
        return new JsonResponse(array_filter([
            'type' => $typ === 'about:blank' ? $typ : url('/api/v1/probleme/'.$typ),
            'title' => $titel,
            'status' => $status,
            'detail' => $detail,
            ...$mehr,
        ], fn ($w) => $w !== null), $status, ['Content-Type' => 'application/problem+json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
