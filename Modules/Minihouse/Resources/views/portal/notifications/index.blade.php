@extends('minihouse::portal.layout')

@section('title', 'Thông báo - Portal khách thuê')

@section('content')
    <h1 class="text-xl font-bold text-gray-900 mh-heading">Thông báo</h1>

    @if ($notifications->isEmpty())
        <div class="mt-4 mh-empty">Chưa có thông báo nào.</div>
    @else
        <div class="mt-4 space-y-2.5">
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
                    <a href="{{ $notification->link }}" class="mh-list-row">
                @else
                    <div class="mh-list-row">
                @endif
                    <span class="text-xl leading-none flex-shrink-0">{{ $icon }}</span>
                    <div class="flex-1 min-w-0">
                        <div class="font-semibold text-gray-900">{{ $notification->title }}</div>
                        @if ($notification->body)
                            <div class="mt-0.5 text-sm text-gray-500">{{ $notification->body }}</div>
                        @endif
                        <div class="mt-1 text-xs text-gray-400 mh-tabular">{{ $notification->created_at->format('H:i d/m/Y') }}</div>
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
