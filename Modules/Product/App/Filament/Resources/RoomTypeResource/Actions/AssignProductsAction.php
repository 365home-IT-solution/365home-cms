<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources\RoomTypeResource\Actions;

use Filament\Forms\Components\Select;
use Filament\Tables\Actions\Action;
use Modules\Product\App\Models\Product;

class AssignProductsAction
{
    public static function make(): Action
    {
        return Action::make('assign_products')
            ->label('Gán phòng')
            ->icon('heroicon-o-home')
            ->color('success')
            ->modalHeading(fn ($record) => 'Gán phòng cho loại: ' . $record->name)
            ->modalSubmitActionLabel('Lưu')
            ->modalWidth('lg')
            ->form([
                Select::make('product_ids')
                    ->label('Chọn phòng')
                    ->multiple()
                    ->searchable()
                    ->options(fn () => Product::orderBy('name')->pluck('name', 'id')->toArray())
                    ->placeholder('Tìm theo tên phòng...'),
            ])
            ->fillForm(fn ($record) => [
                'product_ids' => $record->products()->pluck('id')->toArray(),
            ])
            ->action(function ($record, array $data): void {
                $ids = $data['product_ids'] ?? [];

                Product::whereIn('id', $ids)
                    ->update(['room_type_id' => $record->id]);

                Product::where('room_type_id', $record->id)
                    ->whereNotIn('id', $ids)
                    ->update(['room_type_id' => null]);
            });
    }
}
