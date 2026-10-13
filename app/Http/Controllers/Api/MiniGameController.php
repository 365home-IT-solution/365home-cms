<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MiniGame;
use App\Models\MiniGameCard;
use App\Models\MiniGameSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// Mini game trên app, chỉ cho khách đã đăng nhập. Bộ bài được trộn và giữ ở BE theo TỪNG KHÁCH (không theo đơn, khách này
// trộn không ảnh hưởng khách kia): app chỉ biết số lá, khách chọn 1 vị trí thì BE mới trả nội dung lá đó — nên không xem
// trước được và lá đã lật không lặp lại cho tới khi trộn lại.
// Khách có thể đánh dấu lá đã rút trúng: lá đã đánh dấu không còn được chia ở các lần trộn sau, bỏ đánh dấu thì quay lại bộ bài.
class MiniGameController extends Controller
{
    /**
     * GET /api/v1/mini-games
     * Danh sách mini game đang bật.
     */
    public function index(): JsonResponse
    {
        $games = MiniGame::where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (MiniGame $game) => [
                'code'        => $game->code,
                'name'        => $game->name,
                'description' => $game->description,
                'thumbnail'   => $game->thumbnail ? Storage::disk('public')->url($game->thumbnail) : null,
            ])
            ->values();

        return response()->json(['data' => $games]);
    }

    /**
     * GET /api/mini-games/{code}
     * Ván hiện tại của khách (các lá đã lật + số lá còn lại) — chưa có ván thì tự trộn ván đầu.
     */
    public function show(Request $request, string $code): JsonResponse
    {
        $game = $this->game($code);
        if ($game instanceof JsonResponse) {
            return $game;
        }

        $session = $this->sessionQuery($game, $request)->first();

        return $session
            ? response()->json(['data' => $this->state($game, $session)])
            : $this->newRound($game, $request);
    }

    /**
     * POST /api/mini-games/{code}/shuffle
     * Trộn lại bộ bài: bỏ ván cũ, tất cả lá úp lại (trừ các lá đã đánh dấu).
     */
    public function shuffle(Request $request, string $code): JsonResponse
    {
        $game = $this->game($code);

        return $game instanceof JsonResponse ? $game : $this->newRound($game, $request);
    }

    /**
     * POST /api/mini-games/{code}/pick
     * Body: position (vị trí lá khách chọn, 0 .. total_cards - 1)
     */
    public function pick(Request $request, string $code): JsonResponse
    {
        $request->validate(['position' => 'required|integer|min:0']);

        $game = $this->game($code);
        if ($game instanceof JsonResponse) {
            return $game;
        }

        $position = (int) $request->position;

        return DB::transaction(function () use ($game, $request, $position) {
            // Khoá dòng để 2 lần chạm liên tiếp không lật trùng 1 lá
            $session = $this->sessionQuery($game, $request)->lockForUpdate()->first();

            if (! $session) {
                return response()->json(['success' => false, 'message' => 'Chưa trộn bài. Vui lòng trộn bài trước khi chọn.'], 422);
            }

            if (! array_key_exists($position, $session->deck)) {
                return response()->json(['success' => false, 'message' => 'Vị trí lá bài không hợp lệ.'], 422);
            }

            if (in_array($position, $session->drawn, true)) {
                return response()->json(['success' => false, 'message' => 'Lá bài này đã được lật.'], 422);
            }

            $card = MiniGameCard::find($session->deck[$position]);

            if (! $card) {
                return response()->json(['success' => false, 'message' => 'Lá bài không còn tồn tại. Vui lòng trộn lại.'], 422);
            }

            $session->update(['drawn' => [...$session->drawn, $position]]);

            return response()->json([
                'data' => [
                    'position'  => $position,
                    'card'      => $this->card($card, $session->marked),
                    'remaining' => count($session->deck) - count($session->drawn),
                ],
            ]);
        });
    }

    /**
     * POST /api/mini-games/{code}/mark
     * Body: card_id — đánh dấu lá đã rút trúng, lá này sẽ không được chia ở các lần trộn sau.
     */
    public function mark(Request $request, string $code): JsonResponse
    {
        $request->validate(['card_id' => 'required|integer']);

        return $this->changeMarked($request, $code, true);
    }

    /**
     * POST /api/mini-games/{code}/unmark
     * Body: card_id (tuỳ chọn) — bỏ đánh dấu 1 lá; không gửi card_id thì bỏ đánh dấu tất cả (reset).
     */
    public function unmark(Request $request, string $code): JsonResponse
    {
        $request->validate(['card_id' => 'nullable|integer']);

        return $this->changeMarked($request, $code, false);
    }

    // =========================================================
    // PRIVATE
    // =========================================================

    private function game(string $code): MiniGame|JsonResponse
    {
        return MiniGame::where('code', $code)->where('is_active', true)->first()
            ?? response()->json(['message' => 'Không tìm thấy mini game.'], 404);
    }

    // Ván của khách đang đăng nhập với game này — route đã qua customer.active nên user() luôn là Customer.
    private function sessionQuery(MiniGame $game, Request $request)
    {
        return MiniGameSession::where('mini_game_id', $game->id)->where('customer_id', $request->user()->id);
    }

    private function changeMarked(Request $request, string $code, bool $mark): JsonResponse
    {
        $game = $this->game($code);
        if ($game instanceof JsonResponse) {
            return $game;
        }

        $cardId = $request->filled('card_id') ? (int) $request->card_id : null;

        if ($cardId !== null && ! $game->cards()->whereKey($cardId)->exists()) {
            return response()->json(['success' => false, 'message' => 'Lá bài không thuộc mini game này.'], 422);
        }

        return DB::transaction(function () use ($game, $request, $cardId, $mark) {
            $session = $this->sessionQuery($game, $request)->lockForUpdate()->first();

            if (! $session) {
                return response()->json(['success' => false, 'message' => 'Chưa trộn bài. Vui lòng trộn bài trước khi chọn.'], 422);
            }

            $marked = match (true) {
                $mark            => array_values(array_unique([...$session->marked, $cardId])),
                $cardId !== null => array_values(array_diff($session->marked, [$cardId])),
                default          => [],
            };

            $session->update(['marked' => $marked]);

            return response()->json(['data' => $this->state($game, $session)]);
        });
    }

    private function newRound(MiniGame $game, Request $request): JsonResponse
    {
        $session = MiniGameSession::firstOrNew(['mini_game_id' => $game->id, 'customer_id' => $request->user()->id]);
        $session->marked ??= [];

        $cardIds = $game->cards()->where('is_active', true)->pluck('id');

        if ($cardIds->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'Mini game chưa có lá bài nào.'], 422);
        }

        $deck = $cardIds->diff($session->marked)->shuffle()->values()->all();

        if (! $deck) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn đã đánh dấu hết các lá bài. Hãy bỏ đánh dấu để chơi lại.',
            ], 422);
        }

        $session->deck          = $deck;
        $session->drawn         = [];
        $session->shuffle_count = (int) $session->shuffle_count + 1;
        $session->save();

        return response()->json(['data' => $this->state($game, $session)]);
    }

    private function state(MiniGame $game, MiniGameSession $session): array
    {
        $deck   = $session->deck;
        $drawn  = $session->drawn;
        $marked = $session->marked;

        $cards = MiniGameCard::whereIn('id', [...array_map(fn (int $position) => $deck[$position], $drawn), ...$marked])
            ->get()
            ->keyBy('id');

        return [
            'game'          => $game->code,
            'shuffle_count' => $session->shuffle_count,
            'total_cards'   => count($deck),
            'remaining'     => count($deck) - count($drawn),
            'drawn'         => array_map(fn (int $position) => [
                'position' => $position,
                'card'     => ($card = $cards->get($deck[$position])) ? $this->card($card, $marked) : null,
            ], $drawn),
            // Các lá đã đánh dấu, theo thứ tự đánh dấu — giữ nguyên khi trộn lại
            'marked'        => array_values(array_filter(array_map(
                fn (int $cardId) => ($card = $cards->get($cardId)) ? $this->card($card, $marked) : null,
                $marked,
            ))),
        ];
    }

    private function card(MiniGameCard $card, array $marked): array
    {
        return [
            'id'        => $card->id,
            'title'     => $card->title,
            'subtitle'  => $card->subtitle,
            'image_url' => $card->image_url,
            'is_marked' => in_array($card->id, $marked, true),
        ];
    }
}
