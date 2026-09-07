<?php

namespace Modules\Minihouse\App\Filament\Resources\InvoiceResource\Tables;

use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Modules\Minihouse\App\Models\Invoice;
use Modules\Minihouse\App\Support\Money;

class InvoiceTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('contract.room.code')->label('Phòng')->searchable()->sortable(),
                TextColumn::make('contract.tenant.fullname')->label('Khách thuê')->searchable(),
                TextColumn::make('month')->label('Tháng')->date('m/Y')->sortable(),
                TextColumn::make('period_start')->label('Kỳ tính tiền')->formatStateUsing(fn ($state, $record) => $record->period_start && $record->period_end
                    ? $record->period_start->format('d/m') . ' - ' . $record->period_end->format('d/m')
                    : '—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('room_price')->label('Tiền phòng')->formatStateUsing(fn ($state) => Money::format($state))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('electric_amount')->label('Tiền điện')->formatStateUsing(fn ($state) => Money::format($state))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('water_amount')->label('Tiền nước')->formatStateUsing(fn ($state) => Money::format($state))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('total_amount')->label('Tổng tiền')->formatStateUsing(fn ($state) => Money::format($state))->sortable(),
                TextColumn::make('amount_paid')->label('Đã trả')->formatStateUsing(fn ($state) => Money::format($state))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('remaining')->label('Còn lại')->state(fn (Invoice $record) => Money::format($record->remainingAmount())),
                TextColumn::make('status')->label('Trạng thái')->badge()->formatStateUsing(fn (string $state) => match ($state) {
                    Invoice::STATUS_PAID    => 'Đã thanh toán',
                    Invoice::STATUS_PARTIAL => 'Thanh toán 1 phần',
                    default                 => 'Chưa thanh toán',
                })->color(fn (string $state) => match ($state) {
                    Invoice::STATUS_PAID    => 'success',
                    Invoice::STATUS_PARTIAL => 'info',
                    default                 => 'warning',
                }),
                // Hoá đơn lập hàng loạt (InvoiceGenerationService) luôn để trống 2 cột này — không
                // tự đoán được số điện/nước thật, phải đợi nhân viên đọc đồng hồ. Cảnh báo rõ ở đây
                // để rà soát trước khi gửi hoá đơn cho khách, tránh gửi nhầm hoá đơn thiếu tiền
                // điện/nước (tổng tiền lúc đó chỉ có tiền phòng + phụ thu).
                IconColumn::make('missing_readings')
                    ->label('Thiếu chỉ số')
                    ->getStateUsing(fn (Invoice $record) => is_null($record->electric_end) || is_null($record->water_end))
                    ->boolean()
                    ->trueIcon('heroicon-o-exclamation-triangle')
                    ->falseIcon('heroicon-o-check-circle')
                    ->trueColor('warning')
                    ->falseColor('success')
                    ->tooltip(fn (Invoice $record) => (is_null($record->electric_end) || is_null($record->water_end))
                        ? 'Chưa nhập ' . implode(' và ', array_filter([
                            is_null($record->electric_end) ? 'số điện cuối kỳ' : null,
                            is_null($record->water_end) ? 'số nước cuối kỳ' : null,
                        ]))
                        : 'Đã đủ chỉ số điện/nước'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options([
                        Invoice::STATUS_UNPAID  => 'Chưa thanh toán',
                        Invoice::STATUS_PARTIAL => 'Thanh toán 1 phần',
                        Invoice::STATUS_PAID    => 'Đã thanh toán',
                    ]),
                TernaryFilter::make('missing_readings')
                    ->label('Thiếu chỉ số điện/nước')
                    ->placeholder('Tất cả')
                    ->trueLabel('Thiếu chỉ số')
                    ->falseLabel('Đã đủ chỉ số')
                    // where(fn) bọc riêng — nếu viết orWhereNull() trực tiếp nối vào query gốc sẽ
                    // "OR" với TOÀN BỘ điều kiện khác đang lọc (VD Trạng thái), không chỉ trong nội
                    // bộ điều kiện thiếu chỉ số này.
                    ->queries(
                        true: fn ($query) => $query->where(fn ($q) => $q->whereNull('electric_end')->orWhereNull('water_end')),
                        false: fn ($query) => $query->whereNotNull('electric_end')->whereNotNull('water_end'),
                    ),
                Filter::make('month')
                    ->label('Tháng hoá đơn')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')->label('Từ tháng')->displayFormat('m/Y'),
                        \Filament\Forms\Components\DatePicker::make('until')->label('Đến tháng')->displayFormat('m/Y'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('month', '>=', $date))
                            ->when($data['until'] ?? null, fn ($q, $date) => $q->whereDate('month', '<=', $date));
                    }),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
            ])
            ->defaultSort('month', 'desc')
            ->searchable()
            ->paginated([10, 25, 50, 100]);
    }
}
