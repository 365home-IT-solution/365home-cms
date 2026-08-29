<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources\ProductResource\Tables\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Enums\MaxWidth;
use Modules\AuditLog\Services\AuditLogger;
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

                // ::where()->update() hàng loạt KHÔNG bắn Eloquent event nên ProductObserver không
                // thấy được — chụp trước/sau rồi tự ghi 1 dòng log tóm tắt phòng nào được thêm/bỏ
                // khỏi danh sách "qua đêm".
                $oldIds = Product::where('nights', true)->pluck('id')->map(fn ($id) => (string) $id)->all();

                Product::where('nights', true)
                    ->whereNotIn('id', $selectedIds)
                    ->update(['nights' => false]);

                if (! empty($selectedIds)) {
                    Product::whereIn('id', $selectedIds)
                        ->update(['nights' => true]);
                }

                $newIds  = array_map('strval', $selectedIds);
                $added   = array_diff($newIds, $oldIds);
                $removed = array_diff($oldIds, $newIds);

                if (! empty($added) || ! empty($removed)) {
                    $affectedIds = array_merge($added, $removed);
                    $names       = Product::whereIn('id', $affectedIds)->pluck('name', 'id');
                    $anchor      = Product::find($affectedIds[array_key_first($affectedIds)]);

                    if ($anchor) {
                        AuditLogger::log(
                            action: 'update',
                            module: 'Product',
                            record: $anchor,
                            old: $removed ? ['phong_qua_dem_da_bo' => collect($removed)->map(fn ($id) => $names[$id] ?? $id)->implode(', ')] : [],
                            new: $added ? ['phong_qua_dem_da_them' => collect($added)->map(fn ($id) => $names[$id] ?? $id)->implode(', ')] : [],
                            label: 'Cập nhật danh sách phòng qua đêm',
                        );
                    }
                }

                Notification::make()
                    ->title('Đã cập nhật danh sách phòng qua đêm')
                    ->body(count($selectedIds) . ' phòng được gán.')
                    ->success()
                    ->send();
            });
    }
}
