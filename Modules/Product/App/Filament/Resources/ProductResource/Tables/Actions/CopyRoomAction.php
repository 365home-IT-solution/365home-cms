<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources\ProductResource\Tables\Actions;

use App\Models\User;
use App\Services\RoomCopyService;
use App\Support\ApprovalContext;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;
use Illuminate\Support\HtmlString;
use Modules\Product\App\Models\Product;

class CopyRoomAction
{
    public static function make(): Action
    {
        return Action::make('copyRoom')
            ->label('Sao chép')
            ->icon('heroicon-o-document-duplicate')
            ->color('gray')
            ->visible(fn () => self::canUse())
            ->authorize(fn () => self::canUse())
            ->modalHeading(fn (Product $record) => "Sao chép phòng — {$record->name}")
            ->modalDescription('Phòng mới nhận nguyên nội dung, ảnh, tiện ích, chi nhánh, giá và khung giờ của phòng gốc. Khoá cửa, mật mã, ngày bị chặn, đánh giá và đơn đặt không được sao chép.')
            ->modalSubmitActionLabel('Sao chép')
            ->form(fn (Product $record): array => [
                TextInput::make('quantity')
                    ->label('Số lượng phòng muốn sao chép')
                    ->numeric()->integer()
                    ->minValue(1)->maxValue(RoomCopyService::MAX_COPIES)
                    ->default(1)
                    ->required()
                    ->live(debounce: 400),
                TextInput::make('start_number')
                    ->label('Bắt đầu đánh số từ')
                    ->numeric()->integer()->minValue(0)->maxValue(999999)
                    ->placeholder((string) self::autoStartNumber($record))
                    ->helperText('Để trống để tự lấy số kế tiếp chưa có phòng nào dùng.')
                    ->live(debounce: 400),
                Placeholder::make('preview')
                    ->label('Tên phòng sẽ tạo')
                    ->content(fn (Get $get) => self::previewContent($record, $get)),
            ])
            ->action(function (Product $record, array $data): void {
                $copies = app(RoomCopyService::class)->copy(
                    $record,
                    (int) $data['quantity'],
                    filled($data['start_number'] ?? null) ? (int) $data['start_number'] : null
                );

                if (ApprovalContext::hasNotices()) {
                    return;
                }

                Notification::make()
                    ->title('Đã sao chép ' . $copies->count() . ' phòng')
                    ->body($copies->pluck('name')->implode(', '))
                    ->success()
                    ->send();
            });
    }

    private static function canUse(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isSuperAdmin();
    }

    private static function autoStartNumber(Product $record): int
    {
        $first = app(RoomCopyService::class)->previewNames($record, 1)[0];

        return preg_match('/(\d+)$/', $first, $m) ? (int) $m[1] : 1;
    }

    private static function previewContent(Product $record, Get $get): HtmlString|string
    {
        $quantity = (int) $get('quantity');

        if ($quantity < 1 || $quantity > RoomCopyService::MAX_COPIES) {
            return 'Nhập số lượng từ 1 đến ' . RoomCopyService::MAX_COPIES . '.';
        }

        $start = filled($get('start_number')) ? (int) $get('start_number') : null;
        $names = app(RoomCopyService::class)->previewNames($record, $quantity, $start);

        return new HtmlString(e(implode(', ', $names)));
    }
}
