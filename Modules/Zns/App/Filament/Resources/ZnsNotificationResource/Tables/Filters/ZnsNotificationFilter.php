<?php

declare(strict_types=1);

namespace Modules\Zns\App\Filament\Resources\ZnsNotificationResource\Tables\Filters;

use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;

class ZnsNotificationFilter
{
    public static function filter(): array
    {
        return [
            SelectFilter::make('status')
                ->label('Trạng thái')
                ->options([
                    'pending' => 'Chờ gửi',
                    'sent' => 'Đã gửi',
                    'delivered' => 'Đã nhận',
                    'read' => 'Đã đọc',
                    'failed' => 'Thất bại',
                ]),

            SelectFilter::make('notification_type')
                ->label('Loại thông báo')
                ->options([
                    'booking_success' => 'Đặt phòng thành công',
                    'booking_reminder' => 'Nhắc nhở',
                    'booking_cancelled' => 'Hủy đơn',
                ]),

            Filter::make('failed')
                ->label('Thất bại')
                ->query(fn($query) => $query->where('status', 'failed')),

            Filter::make('needs_retry')
                ->label('Cần thử lại')
                ->query(fn($query) => $query->needsRetry()),

            Filter::make('sent_today')
                ->label('Gửi hôm nay')
                ->query(fn($query) => $query->today()),
        ];
    }
}
