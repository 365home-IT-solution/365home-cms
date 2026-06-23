<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources\ProductResource\Tables\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Enums\MaxWidth;
use Modules\Product\App\Models\Product;

class AssignNightsAction
{
    public static function make(): Action
    {
        return Action::make('assign_nights')
            ->label('Phòng qua đêm')
            ->icon('heroicon-o-moon')
            ->color('warning')
            ->modalHeading('Quản lý danh sách phòng qua đêm')
            ->modalDescription('Chọn các phòng sẽ xuất hiện trong danh sách "qua đêm". Phòng không được chọn sẽ bị gỡ khỏi danh sách.')
            ->modalSubmitActionLabel('Lưu')
            ->modalCancelActionLabel('Hủy')
            ->modalWidth(MaxWidth::TwoExtraLarge)
            ->fillForm(fn (): array => [
                'product_ids' => Product::where('nights', true)->pluck('id')->toArray(),
            ])
            ->form([
                Select::make('product_ids')
                    ->label('Phòng qua đêm')
                    ->options(
                        Product::where('is_activated', true)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                    )
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->placeholder('Tìm kiếm theo tên phòng...')
                    ->helperText('Giữ Ctrl (Windows) hoặc ⌘ (Mac) để chọn nhiều phòng'),
            ])
            ->action(function (array $data): void {
                $selectedIds = $data['product_ids'] ?? [];

                Product::where('nights', true)
                    ->whereNotIn('id', $selectedIds)
                    ->update(['nights' => false]);

                if (! empty($selectedIds)) {
                    Product::whereIn('id', $selectedIds)
                        ->update(['nights' => true]);
                }

                Notification::make()
                    ->title('Đã cập nhật danh sách phòng qua đêm')
                    ->body(count($selectedIds) . ' phòng được gán.')
                    ->success()
                    ->send();
            });
    }
}
