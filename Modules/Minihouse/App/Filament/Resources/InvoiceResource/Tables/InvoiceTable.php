<?php

namespace Modules\Minihouse\App\Filament\Resources\InvoiceResource\Tables;

use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
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
                // Hoá đơn lập hàng loạt (InvoiceGenerationService) luôn để trống 2 chỉ số điện/nước —
                // không tự đoán được số thật, phải đợi nhân viên đọc đồng hồ. Đúng lúc CẢ 2 chỉ số
                // được điền đủ, khách mới thấy hoá đơn này trong Portal + nhận thông báo "Hoá đơn
                // mới" (xem Invoice::isReadyForTenant(), InvoiceObserver) — cột này cho nhân viên
                // biết ngay hoá đơn nào khách ĐÃ thấy được, tránh tưởng đã gửi mà thật ra khách chưa
                // hề hay biết vì còn thiếu chỉ số.
                IconColumn::make('sent_to_tenant')
                    ->label('Đã gửi khách')
                    ->getStateUsing(fn (Invoice $record) => $record->isReadyForTenant())
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-exclamation-triangle')
                    ->trueColor('success')
                    ->falseColor('warning')
                    ->tooltip(fn (Invoice $record) => $record->isReadyForTenant()
                        ? 'Đã đủ chỉ số điện/nước — khách thuê đã thấy hoá đơn này trong Portal.'
                        : 'Chưa nhập ' . implode(' và ', array_filter([
                            is_null($record->electric_end) ? 'số điện cuối kỳ' : null,
                            is_null($record->water_end) ? 'số nước cuối kỳ' : null,
                        ])) . ' — khách thuê CHƯA thấy hoá đơn này trong Portal.'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options([
                        Invoice::STATUS_UNPAID  => 'Chưa thanh toán',
                        Invoice::STATUS_PARTIAL => 'Thanh toán 1 phần',
                        Invoice::STATUS_PAID    => 'Đã thanh toán',
                    ]),
                TernaryFilter::make('sent_to_tenant')
                    ->label('Đã gửi khách')
                    ->placeholder('Tất cả')
                    ->trueLabel('Đã gửi (đủ chỉ số)')
                    ->falseLabel('Chưa gửi (thiếu chỉ số)')
                    // where(fn) bọc riêng — nếu viết orWhereNull() trực tiếp nối vào query gốc sẽ
                    // "OR" với TOÀN BỘ điều kiện khác đang lọc (VD Trạng thái), không chỉ trong nội
                    // bộ điều kiện thiếu chỉ số này.
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('electric_end')->whereNotNull('water_end'),
                        false: fn ($query) => $query->where(fn ($q) => $q->whereNull('electric_end')->orWhereNull('water_end')),
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
                // Route thường (InvoicePrintController), không phải Livewire — mở tab mới để giữ
                // nguyên danh sách đang xem, giống hệt cách nút in trên trang Edit hoạt động.
                Action::make('print')
                    ->label('In hoá đơn')
                    ->icon('heroicon-o-printer')
                    ->url(fn (Invoice $record) => route('minihouse.invoices.print', $record))
                    ->openUrlInNewTab(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                // Ghép nhiều phiếu đã chọn vào 1 file PDF duy nhất (xem
                // InvoicePrintController::bulk()) — kết hợp bộ lọc "Trạng thái = Chưa thanh toán" ở
                // trên rồi chọn hết để in hàng loạt đúng nhóm hoá đơn chưa thu.
                //
                // DÙNG action() CHỨ KHÔNG DÙNG url() — url() bị Filament tính SẴN 1 LẦN lúc render
                // bảng (lúc đó chưa chọn dòng nào → link rỗng, gây 404 khi bấm sau khi đã chọn, vì
                // href không tự cập nhật lại theo lựa chọn trên client). action() chạy qua Livewire
                // AJAX nên luôn lấy đúng $records đang chọn tại thời điểm bấm.
                BulkAction::make('printBulk')
                    ->label('In hoá đơn đã chọn')
                    ->icon('heroicon-o-printer')
                    ->action(fn (Collection $records) => redirect()->to(
                        route('minihouse.invoices.print-bulk', ['ids' => $records->pluck('id')->implode(',')])
                    ))
                    ->deselectRecordsAfterCompletion(),
                DeleteBulkAction::make(),
            ])
            ->defaultSort('month', 'desc')
            ->searchable()
            ->paginated([10, 25, 50, 100]);
    }
}
