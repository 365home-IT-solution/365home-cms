<?php

namespace Modules\Minihouse\App\Filament\Resources\AssetTypeResource\Tables;

use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;

class AssetTypeTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // Mobile (< md): CHỈ hiện đúng 1 dòng gọn tự vẽ (xem file blade) — cùng cơ chế đã áp
                // dụng cho toàn bộ bảng khác trong module (xem TenantTable::table() để biết đầy đủ lý
                // do dùng ViewColumn thay vì Tables\Columns\Layout\*).
                ViewColumn::make('mobile_card')
                    ->label('')
                    ->view('minihouse::filament.tables.asset-type-mobile-row')
                    ->hiddenFrom('md')
                    ->extraCellAttributes(['style' => 'max-width: 190px; width: 100%; overflow: hidden;']),

                TextColumn::make('name')->label('Tên loại tài sản')->searchable()->sortable()->visibleFrom('md'),
                TextColumn::make('created_at')->label('Ngày tạo')->dateTime('d/m/Y')->sortable()->visibleFrom('md'),
            ])
            ->actions([
                // Ẩn chữ nhãn, chỉ giữ icon, RIÊNG dưới 768px — xem _mobile-action-styles.blade.php.
                EditAction::make()->extraAttributes(['class' => 'mh-row-action']),
                DeleteAction::make()->extraAttributes(['class' => 'mh-row-action']),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
            ])
            ->header(fn () => view('minihouse::filament.tables._mobile-action-styles'))
            ->defaultSort('name')
            ->searchable()
            ->paginated([10, 25, 50, 100]);
    }
}
