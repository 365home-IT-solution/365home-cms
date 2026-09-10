@extends('minihouse::portal.layout')

@section('title', 'Thông báo - Portal khách thuê')

@section('content')
    <h1 class="text-xl font-semibold text-gray-900">Thông báo</h1>

    @if ($notifications->isEmpty())
        <div class="mt-4 rounded-xl bg-white p-4 shadow-sm border border-gray-100 text-sm text-gray-500">
            Chưa có thông báo nào.
        </div>
    @else
        <div class="mt-4 space-y-2">
            @foreach ($notifications as $notification)
                @php
                    $icon = match ($notification->type) {
                        \Modules\Minihouse\App\Models\PortalNotification::TYPE_INVOICE_NEW => '🧾',
                        \Modules\Minihouse\App\Models\PortalNotification::TYPE_REMINDER => '⏰',
                        \Modules\Minihouse\App\Models\PortalNotification::TYPE_ANNOUNCEMENT => '📢',
                        \Modules\Minihouse\App\Models\PortalNotification::TYPE_FEEDBACK_REPLY => '💬',
                        default => '🔔',
                    };
                @endphp
                @if ($notification->link)
                    <a href="{{ $notification->link }}" class="block rounded-xl bg-white p-4 shadow-sm border border-gray-100 hover:border-gray-300 transition">
                @else
                    <div class="rounded-xl bg-white p-4 shadow-sm border border-gray-100">
                @endif
                    <div class="flex items-start gap-3">
                        <div class="text-xl leading-none">{{ $icon }}</div>
                        <div class="flex-1 min-w-0">
                            <div class="font-medium text-gray-900">{{ $notification->title }}</div>
                            @if ($notification->body)
                                <div class="mt-0.5 text-sm text-gray-500">{{ $notification->body }}</div>
                            @endif
                            <div class="mt-1 text-xs text-gray-400">{{ $notification->created_at->format('H:i d/m/Y') }}</div>
                        </div>
                    </div>
                @if ($notification->link)
                    </a>
                @else
                    </div>
                @endif
            @endforeach
        </div>

        <div class="mt-4">
            {{ $notifications->links() }}
        </div>
    @endif
@endsection
