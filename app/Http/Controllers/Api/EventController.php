<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/events — danh sách sự kiện ĐANG BẬT (is_active=true), public, không cần auth. Quản lý
 * CRUD ở Api\Admin\EventController.
 */
class EventController extends Controller
{
    public function index(): JsonResponse
    {
        $events = Event::where('is_active', true)
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Event $event) => [
                'id'          => $event->id,
                'title'       => $event->title,
                'description' => $event->description,
                'image_url'   => $event->image_url,
            ])
            ->values();

        return response()->json(['data' => $events]);
    }
}
