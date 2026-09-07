<?php

namespace Modules\Minihouse\App\Filament\Resources\TransactionResource\Tables;

use Filament\Notifications\Notification;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Modules\Minihouse\App\Models\Transaction;
use Modules\Minihouse\App\Support\Money;

class TransactionTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('transaction_date')->label('Ngày')->date('d/m/Y')->sortable(),
                TextColumn::make('type')->label('Loại')->badge()->formatStateUsing(fn (string $state) => $state === Transaction::TYPE_IN ? 'Thu' : 'Chi')
                    ->color(fn (string $state) => $state === Transaction::TYPE_IN ? 'success' : 'danger'),
                TextColumn::make('category')->label('Hạng mục')->formatStateUsing(fn (?string $state) => match ($state) {
                    Transaction::CATEGORY_REPAIR    => 'Sửa chữa',
                    Transaction::CATEGORY_OPERATION => 'Vận hành',
                    Transaction::CATEGORY_OTHER     => 'Khác',
                    default => '—',
                }),
                TextColumn::make('amount')->label('Số tiền')->formatStateUsing(fn ($state) => Money::format($state))->sortable(),
                TextColumn::make('building.name')->label('Toà nhà')->searchable()->sortable(),
                TextColumn::make('contract.room.code')->label('Phòng')->searchable(),
                TextColumn::make('note')->label('Ghi chú')->limit(40)->searchable(),
                TextColumn::make('invoice_payment_id')
                    ->label('Nguồn')
                    ->badge()
                    ->formatStateUsing(fn (?int $state) => $state ? 'Tự động (thanh toán hoá đơn)' : 'Nhập tay')
                    ->color(fn (?int $state) => $state ? 'info' : 'gray')
                    ->tooltip(fn (?int $state) => $state ? 'Sinh ra từ 1 lần thanh toán hoá đơn — sửa/xoá ở đúng hoá đơn đó (mục "Thanh toán"), không sửa trực tiếp ở đây.' : null),
                ImageColumn::make('receipt_image')->label('Biên lai')->circular()
                    ->toggleable(isToggledHiddenByDefault: true),
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
                        Transaction::CATEGORY_REPAIR    => 'Sửa chữa',
                        Transaction::CATEGORY_OPERATION => 'Vận hành',
                        Transaction::CATEGORY_OTHER     => 'Khác',
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
            ])
            ->actions([
                // Dòng tự động từ thanh toán hoá đơn — KHOÁ sửa/xoá trực tiếp ở đây, tránh sửa lệch
                // khỏi dữ liệu gốc (InvoicePayment). Muốn sửa/xoá thì vào đúng hoá đơn đó.
                EditAction::make()->visible(fn (Transaction $record) => blank($record->invoice_payment_id)),
                DeleteAction::make()->visible(fn (Transaction $record) => blank($record->invoice_payment_id)),
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
            ])
            ->defaultSort('transaction_date', 'desc')
            ->searchable()
            ->paginated([10, 25, 50, 100]);
    }
}
