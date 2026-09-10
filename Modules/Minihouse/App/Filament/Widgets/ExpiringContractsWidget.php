<?php

namespace Modules\Minihouse\App\Filament\Widgets;

use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Modules\Minihouse\App\Filament\Resources\ContractResource;
use Modules\Minihouse\App\Models\Contract;

// "Cảnh báo hợp đồng" (mục 2 trong docs) — đưa thẳng lên Dashboard thay vì chỉ nằm im trong bộ lọc
// "Sắp hết hạn" của trang Hợp đồng (phải tự vào xem mới thấy) — giờ đăng nhập vào là thấy ngay.
class ExpiringContractsWidget extends TableWidget
{
    protected static ?string $heading = 'Cảnh báo hợp đồng sắp hết hạn (30 ngày)';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Contract::query()
                    ->where('status', Contract::STATUS_ACTIVE)
                    ->whereNotNull('end_date')
                    ->whereBetween('end_date', [now()->startOfDay(), now()->addDays(30)->endOfDay()])
                    ->orderBy('end_date')
            )
            ->columns([
                Tables\Columns\TextColumn::make('room.code')->label('Phòng'),
                Tables\Columns\TextColumn::make('tenant.fullname')->label('Khách thuê'),
                Tables\Columns\TextColumn::make('end_date')->label('Ngày hết hạn')->date('d/m/Y'),
                Tables\Columns\TextColumn::make('end_date')
                    ->label('Còn lại')
                    ->state(fn (Contract $record) => now()->diffInDays($record->end_date, false) . ' ngày')
                    ->badge()
                    ->color(fn (Contract $record) => now()->diffInDays($record->end_date, false) <= 7 ? 'danger' : 'warning'),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label('Xem')
                    ->icon('heroicon-o-eye')
                    ->url(fn (Contract $record) => ContractResource::getUrl('edit', ['record' => $record])),
            ])
            ->paginated([5, 10, 25])
            ->emptyStateHeading('Không có hợp đồng nào sắp hết hạn');
    }
}
