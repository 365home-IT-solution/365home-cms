<?php

declare(strict_types=1);

namespace Modules\Payment\App\Filament\Resources\OrderResource\Tables\Actions;

use Filament\Actions\MountableAction;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Modules\Invoice\App\Models\Invoice;
use Modules\Payment\Entities\Order;

// Điểm khởi tạo DUY NHẤT cho 1 Invoice (xem InvoiceResource::canCreate() = false) — snapshot ngay
// tại thời điểm bấm nút, KHÔNG tham chiếu sống tới Order/OrderItem sau đó. Hoá đơn tạo ra ở đây
// luôn ở trạng thái Invoice::STATUS_DRAFT — CHƯA gọi MISA, CHƯA có giá trị pháp lý (xem
// Modules\Invoice\App\Services\MisaInvoiceClient và view pdf/invoice-draft.blade.php).
//
// Dùng CHUNG 1 định nghĩa cho cả nút trên danh sách đơn hàng (Tables\Actions\Action, xem make())
// VÀ nút ở trang chi tiết đơn hàng (Actions\Action, xem makeForHeader()) — 2 class action này khác
// namespace nhưng cùng kế thừa Filament\Actions\MountableAction nên dùng chung được toàn bộ
// label/icon/form/action qua configure(), tránh chép lại 2 lần cùng 1 logic nghiệp vụ.
class IssueInvoiceDraftAction
{
    public static function make(): \Filament\Tables\Actions\Action
    {
        return static::configure(\Filament\Tables\Actions\Action::make('issueInvoiceDraft'));
    }

    public static function makeForHeader(): \Filament\Actions\Action
    {
        return static::configure(\Filament\Actions\Action::make('issueInvoiceDraft'));
    }

    private static function configure(MountableAction $action): MountableAction
    {
        return $action
            ->label('Xuất hoá đơn (nháp)')
            ->icon('heroicon-o-document-text')
            ->color('gray')
            ->visible(fn (Order $record) => in_array($record->status, ['paid', 'deposit']))
            ->modalHeading(fn (Order $record) => 'Xuất hoá đơn (nháp) — Đơn ' . $record->order_code)
            ->modalDescription(
                'Chỉ tạo BẢN NHÁP nội bộ, chưa ký số/phát hành qua MISA meInvoice. '
                . 'Cần cấu hình kết nối MISA (Cấu hình web > Hoá đơn điện tử) mới phát hành được hoá đơn có giá trị pháp lý.'
            )
            ->modalWidth('lg')
            ->fillForm(fn (Order $record) => [
                'buyer_type' => Invoice::BUYER_TYPE_INDIVIDUAL,
                'buyer_name' => $record->buyer_name,
                'buyer_email' => $record->buyer_email,
                'buyer_phone' => $record->buyer_phone,
                'buyer_address' => $record->buyer_address,
            ])
            ->form([
                Section::make('Thông tin người mua')
                    ->schema([
                        Select::make('buyer_type')
                            ->label('Loại người mua')
                            ->options([
                                Invoice::BUYER_TYPE_INDIVIDUAL => 'Khách lẻ',
                                Invoice::BUYER_TYPE_COMPANY    => 'Công ty (cần MST)',
                            ])
                            ->live()
                            ->required(),
                        TextInput::make('buyer_name')
                            ->label('Tên người mua / đơn vị')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('buyer_tax_code')
                            ->label('Mã số thuế')
                            ->maxLength(20)
                            ->required(fn ($get) => $get('buyer_type') === Invoice::BUYER_TYPE_COMPANY)
                            ->visible(fn ($get) => $get('buyer_type') === Invoice::BUYER_TYPE_COMPANY),
                        TextInput::make('buyer_email')
                            ->label('Email nhận hoá đơn')
                            ->email()
                            ->maxLength(255),
                        TextInput::make('buyer_phone')
                            ->label('Điện thoại')
                            ->maxLength(20),
                        TextInput::make('buyer_address')
                            ->label('Địa chỉ')
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ])
            ->action(function (Order $record, array $data): void {
                $record->loadMissing('items');

                $subtotal = 0;
                $vatTotal = 0;
                $lines = [];

                foreach ($record->items as $index => $item) {
                    $quantity = (float) ($item->quantity ?: 1);
                    $unitPrice = (int) $item->price;
                    $vatRate = (float) ($item->vat ?? 0);
                    $amount = (int) round($unitPrice * $quantity);
                    $vatAmount = (int) round($amount * $vatRate / 100);

                    $subtotal += $amount;
                    $vatTotal += $vatAmount;

                    $lines[] = [
                        'order_item_id' => $item->id,
                        'description'   => $item->name ?: 'Dịch vụ thuê phòng',
                        'unit'          => $item->slot_label ? 'Giờ' : 'Lần',
                        'quantity'      => $quantity,
                        'unit_price'    => $unitPrice,
                        'vat_rate'      => $vatRate,
                        'amount'        => $amount,
                        'sort_order'    => $index,
                    ];
                }

                $invoice = Invoice::create([
                    'order_id'       => $record->id,
                    'partner_id'     => $record->partner_id,
                    'created_by'     => auth()->id(),
                    'status'         => Invoice::STATUS_DRAFT,
                    'buyer_type'     => $data['buyer_type'],
                    'buyer_name'     => $data['buyer_name'],
                    'buyer_tax_code' => $data['buyer_tax_code'] ?? null,
                    'buyer_email'    => $data['buyer_email'] ?? null,
                    'buyer_phone'    => $data['buyer_phone'] ?? null,
                    'buyer_address'  => $data['buyer_address'] ?? null,
                    'subtotal_amount' => $subtotal,
                    'vat_amount'      => $vatTotal,
                    'total_amount'    => $subtotal + $vatTotal,
                ]);

                foreach ($lines as $line) {
                    $invoice->lines()->create($line);
                }

                Notification::make()
                    ->title('Đã tạo hoá đơn bản nháp')
                    ->body('Vào menu "Hoá đơn điện tử" để tải PDF nháp. Đây CHƯA phải hoá đơn hợp lệ — cần cấu hình kết nối MISA để phát hành chính thức.')
                    ->success()
                    ->send();
            });
    }
}
