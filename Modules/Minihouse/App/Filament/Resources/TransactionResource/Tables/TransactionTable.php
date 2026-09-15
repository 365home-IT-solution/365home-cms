<?php

namespace Modules\Minihouse\App\Filament\Resources\TransactionResource\Tables;

use Filament\Notifications\Notification;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ExportBulkAction;
use Filament\Tables\Actions\ForceDeleteAction;
use Filament\Tables\Actions\ForceDeleteBulkAction;
use Filament\Tables\Actions\RestoreAction;
use Filament\Tables\Actions\RestoreBulkAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Modules\Minihouse\App\Filament\Exports\TransactionExporter;
use Modules\Minihouse\App\Models\Transaction;
use Modules\Minihouse\App\Support\Money;

class TransactionTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // Mobile (< md): CHỈ hiện đúng 1 dòng gọn tự vẽ (xem file blade) — Thu/Chi + hạng
                // mục bên trái, toà nhà/phòng bên phải, đủ để lướt nhanh không cần cuộn ngang. DÙNG
                // ViewColumn (Filament\Tables\Columns\ViewColumn) — KHÔNG PHẢI Tables\Columns\
                // Layout\View — vì Layout\* là loại component KHÁC hẳn (Table::hasColumnsLayout()
                // bật true), đổi HẲN cách bảng render ẢNH HƯỞNG CẢ DESKTOP dù chỉ định nghĩa 1 cột
                // layout (đã thử và gặp lỗi này ở TenantTable, phải revert). ViewColumn vẫn là Column
                // bình thường nên bảng luôn ở đúng chế độ <table> cổ điển, hiddenFrom/visibleFrom
                // hoạt động đúng như các cột khác bên dưới.
                ViewColumn::make('mobile_card')
                    ->label('')
                    ->view('minihouse::filament.tables.transaction-mobile-row')
                    ->hiddenFrom('md')
                    // Style nội tuyến ép co cột (xem giải thích đầy đủ ở TenantTable::table()) —
                    // class Tailwind (max-w-0/w-full) không có tác dụng vì chưa từng build vào CSS.
                    ->extraCellAttributes(['style' => 'max-width: 190px; width: 100%; overflow: hidden;']),

                TextColumn::make('transaction_date')->label('Ngày')->date('d/m/Y')->sortable()->visibleFrom('md'),
                TextColumn::make('type')->label('Loại')->badge()->formatStateUsing(fn (string $state) => $state === Transaction::TYPE_IN ? 'Thu' : 'Chi')
                    ->color(fn (string $state) => $state === Transaction::TYPE_IN ? 'success' : 'danger')->visibleFrom('md'),
                TextColumn::make('category')->label('Hạng mục')->formatStateUsing(fn (?string $state) => match ($state) {
                    Transaction::CATEGORY_REPAIR         => 'Sửa chữa',
                    Transaction::CATEGORY_OPERATION      => 'Vận hành',
                    Transaction::CATEGORY_DEPOSIT_REFUND => 'Hoàn cọc',
                    Transaction::CATEGORY_OTHER          => 'Khác',
                    default => '—',
                })->visibleFrom('md'),
                TextColumn::make('amount')->label('Số tiền')->formatStateUsing(fn ($state) => Money::format($state))->sortable()->visibleFrom('md'),
                TextColumn::make('building.name')->label('Toà nhà')->searchable()->sortable()->visibleFrom('md'),
                TextColumn::make('contract.room.code')->label('Phòng')->searchable()->visibleFrom('md'),
                TextColumn::make('note')->label('Ghi chú')->limit(40)->searchable()->visibleFrom('md'),
                TextColumn::make('invoice_payment_id')
                    ->label('Nguồn')
                    ->badge()
                    ->formatStateUsing(fn (?int $state) => $state ? 'Tự động (thanh toán hoá đơn)' : 'Nhập tay')
                    ->color(fn (?int $state) => $state ? 'info' : 'gray')
                    ->tooltip(fn (?int $state) => $state ? 'Sinh ra từ 1 lần thanh toán hoá đơn — sửa/xoá ở đúng hoá đơn đó (mục "Thanh toán"), không sửa trực tiếp ở đây.' : null)
                    ->visibleFrom('md'),
                ImageColumn::make('receipt_image')->label('Biên lai')->circular()
                    ->toggleable(isToggledHiddenByDefault: true)->visibleFrom('md'),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Loại')
                    ->options([
                        Transaction::TYPE_IN  => 'Thu',
                        Transaction::TYPE_OUT => 'Chi',
                    ]),
                SelectFilter::make('category')
                    ->label('Hạng mục')
                    ->options([
                        Transaction::CATEGORY_REPAIR         => 'Sửa chữa',
                        Transaction::CATEGORY_OPERATION      => 'Vận hành',
                        Transaction::CATEGORY_DEPOSIT_REFUND => 'Hoàn cọc',
                        Transaction::CATEGORY_OTHER          => 'Khác',
                    ]),
                Filter::make('transaction_date')
                    ->label('Khoảng ngày')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')->label('Từ ngày'),
                        \Filament\Forms\Components\DatePicker::make('until')->label('Đến ngày'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('transaction_date', '>=', $date))
                            ->when($data['until'] ?? null, fn ($q, $date) => $q->whereDate('transaction_date', '<=', $date));
                    }),
                TrashedFilter::make(),
            ])
            ->actions([
                // Dòng tự động từ thanh toán hoá đơn — KHOÁ sửa/xoá trực tiếp ở đây, tránh sửa lệch
                // khỏi dữ liệu gốc (InvoicePayment). Muốn sửa/xoá thì vào đúng hoá đơn đó.
                // Ẩn chữ nhãn, chỉ giữ icon, RIÊNG dưới 768px — xem _mobile-action-styles.blade.php.
                EditAction::make()->visible(fn (Transaction $record) => blank($record->invoice_payment_id))->extraAttributes(['class' => 'mh-row-action']),
                DeleteAction::make()->visible(fn (Transaction $record) => blank($record->invoice_payment_id))->extraAttributes(['class' => 'mh-row-action']),
                RestoreAction::make()->extraAttributes(['class' => 'mh-row-action']),
                // Cùng lý do ẩn DeleteAction ở trên — dòng tự động sinh từ thanh toán hoá đơn không
                // nên xoá vĩnh viễn trực tiếp ở đây.
                ForceDeleteAction::make()->visible(fn (Transaction $record) => blank($record->invoice_payment_id))->extraAttributes(['class' => 'mh-row-action']),
            ])
            ->bulkActions([
                // Chặn xoá hàng loạt dòng tự động sinh từ thanh toán hoá đơn — row-level DeleteAction
                // đã ẩn cho các dòng này (dòng 73-74), nhưng DeleteBulkAction mặc định KHÔNG tự theo
                // ->visible() của row action, chọn "tất cả" vẫn xoá được thẳng nếu không tự lọc ở đây.
                DeleteBulkAction::make()
                    ->action(function (Collection $records) {
                        $locked = $records->filter(fn (Transaction $record) => filled($record->invoice_payment_id));

                        $records->reject(fn (Transaction $record) => filled($record->invoice_payment_id))
                            ->each->delete();

                        if ($locked->isNotEmpty()) {
                            Notification::make()
                                ->title('Bỏ qua ' . $locked->count() . ' dòng tự động')
                                ->body('Dòng tự động sinh từ thanh toán hoá đơn không thể xoá ở đây — xoá đúng lần thanh toán ở hoá đơn tương ứng.')
                                ->warning()
                                ->send();
                        }
                    })
                    ->deselectRecordsAfterCompletion(),
                RestoreBulkAction::make(),
                ForceDeleteBulkAction::make()
                    ->action(function (Collection $records) {
                        $locked = $records->filter(fn (Transaction $record) => filled($record->invoice_payment_id));

                        $records->reject(fn (Transaction $record) => filled($record->invoice_payment_id))
                            ->each->forceDelete();

                        if ($locked->isNotEmpty()) {
                            Notification::make()
                                ->title('Bỏ qua ' . $locked->count() . ' dòng tự động')
                                ->body('Dòng tự động sinh từ thanh toán hoá đơn không thể xoá vĩnh viễn ở đây — xoá đúng lần thanh toán ở hoá đơn tương ứng.')
                                ->warning()
                                ->send();
                        }
                    })
                    ->deselectRecordsAfterCompletion(),
                ExportBulkAction::make()->label('Xuất Excel (đã chọn)')->exporter(TransactionExporter::class),
            ])
            ->header(fn () => view('minihouse::filament.tables._mobile-action-styles'))
            ->defaultSort('transaction_date', 'desc')
            ->searchable()
            ->paginated([10, 25, 50, 100]);
    }
}
