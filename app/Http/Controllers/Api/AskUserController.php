<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\AskUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;

class AskUserController extends Controller
{
    public function show(int $id): JsonResponse
    {
        $askUser = AskUser::find($id);

        if (! $askUser) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        $items = collect($askUser->items ?? [])
            ->map(fn ($item) => [
                'title'     => $item['title'] ?? null,
                'image_url' => ! empty($item['image'])
                    ? Storage::disk('public')->url($item['image'])
                    : null,
            ])
            ->values()
            ->toArray();

        return response()->json([
            'id'          => $askUser->id,
            'title'       => $askUser->title,
            'description' => $askUser->description,
            'items'       => $items,
        ]);
    }
}
