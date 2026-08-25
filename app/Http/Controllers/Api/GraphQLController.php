<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\GraphQL\SearchSchema;
use GraphQL\GraphQL;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;

/**
 * Pilot GraphQL + Automatic Persisted Queries (APQ) cho endpoint tìm kiếm — xem
 * /Users/nitert/.claude/plans/crystalline-toasting-sunrise.md để biết bối cảnh.
 *
 * Giao thức giống Apollo APQ: client gửi extensions.persistedQuery.sha256Hash thay vì
 * query text đầy đủ; nếu server chưa có (cache miss) trả lỗi PersistedQueryNotFound để
 * client gửi lại kèm query text 1 lần (rồi server lưu lại theo hash cho các lần sau).
 */
class GraphQLController extends Controller
{
    private const CACHE_PREFIX = 'gql:';
    private const CACHE_TTL_DAYS = 30;

    public function handle(Request $request): JsonResponse
    {
        $hash      = $request->input('extensions.persistedQuery.sha256Hash');
        $queryText = $request->input('query');
        $variables = $request->input('variables', []);

        if ($queryText === null && $hash) {
            $queryText = Cache::get(self::CACHE_PREFIX . $hash);

            if ($queryText === null) {
                return response()->json([
                    'errors' => [['message' => 'PersistedQueryNotFound']],
                ], 200);
            }
        }

        if ($queryText === null) {
            return response()->json([
                'errors' => [['message' => 'Missing query.']],
            ], 400);
        }

        if ($hash) {
            if (hash('sha256', $queryText) !== $hash) {
                return response()->json([
                    'errors' => [['message' => 'provided sha256Hash does not match query.']],
                ], 400);
            }

            Cache::put(self::CACHE_PREFIX . $hash, $queryText, now()->addDays(self::CACHE_TTL_DAYS));
        }

        $result = GraphQL::executeQuery(SearchSchema::build(), $queryText, null, null, $variables);

        return response()->json($result->toArray());
    }
}
