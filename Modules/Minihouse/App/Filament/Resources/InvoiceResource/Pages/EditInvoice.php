<?php

namespace Modules\Minihouse\App\Filament\Resources\InvoiceResource\Pages;

use Filament\Actions;
use Filament\Forms\Components\Placeholder;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\HtmlString;
use Modules\BladeThemeV1\Support\QrCodeGenerator;
use Modules\Minihouse\App\Filament\Resources\InvoiceResource;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\InvoicePayment;
use Modules\Minihouse\App\Services\InvoiceMomoService;
use Modules\Minihouse\App\Services\InvoicePayOsService;
use Modules\Minihouse\App\Services\InvoiceVnpayService;

class EditInvoice extends EditRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Tạo mã QR PayOS cho SỐ TIỀN CÒN LẠI của hoá đơn — chỉ dùng khi khách chuyển khoản, khách
            // trả tiền mặt vẫn dùng "Ghi nhận thanh toán" (mục Thanh toán bên dưới) như cũ, không bắt
            // buộc qua QR. Còn 1 mã QR CHƯA hết hạn (xem Invoice::hasActivePayOsQr) thì hiện lại đúng
            // mã đó, không tự tạo mã mới mỗi lần mở popup (tránh huỷ link cũ khách đang thao tác dở).
            Actions\Action::make('generatePayOsQr')
                ->label('Tạo mã QR thanh toán')
                ->icon('heroicon-o-qr-code')
                ->color('success')
                // isConfiguredFor($building), KHÔNG PHẢI isConfigured() suông — toà nhà nào đã chọn
                // "PayOS riêng" và điền đủ 3 field CỦA RIÊNG TOÀ ĐÓ vẫn phải hiện được nút này dù tài
                // khoản PayOS CHUNG (config/payos.php) chưa cấu hình gì cả — giống hệt cách
                // generateMomoLink/generateVnpayLink bên dưới đã làm đúng, action này trước đó bị bỏ
                // sót nên luôn báo "Chưa cấu hình PayOS" sai cho các toà dùng tài khoản riêng.
                ->visible(function () {
                    $record = $this->record;
                    $building = $record->contract_id
                        ? Contract::withoutGlobalScopes()
                            ->with(['room' => fn ($q) => $q->withoutGlobalScopes(), 'room.building' => fn ($q) => $q->withoutGlobalScopes()])
                            ->find($record->contract_id)
                            ?->room?->building
                        : null;

                    return $record->remainingAmount() > 0 && InvoicePayOsService::isConfiguredFor($building);
                })
                ->modalHeading('Mã QR thanh toán PayOS')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Đóng')
                ->form(function () {
                    $record = $this->record;

                    try {
                        if ($record->hasActivePayOsQr()) {
                            $qrImage     = QrCodeGenerator::dataUri($record->payos_qr_code, 260);
                            $amount      = $record->remainingAmount();
                            $expiredAt   = $record->payos_expired_at;
                            $checkoutUrl = $record->payos_checkout_url;
                        } else {
                            $result      = InvoicePayOsService::createQr($record);
                            $qrImage     = $result['qr_image'];
                            $amount      = $result['amount'];
                            $expiredAt   = Carbon::parse($result['expired_at']);
                            $checkoutUrl = $result['checkout_url'];
                        }
                    } catch (\Throwable $e) {
                        return [
                            Placeholder::make('error')
                                ->label('')
                                ->content('Không tạo được mã QR: ' . $e->getMessage()),
                        ];
                    }

                    // "Mở trang thanh toán" mở đúng trang PayOS (giống Home) — vẫn giữ QR hiện tại
                    // ngay trong popup thay vì bắt buộc redirect, để nhân viên đọc/gửi được mã ngay
                    // mà không rời trang. "Tải ảnh QR" dùng thuộc tính download trên chính data URI
                    // (không cần route/controller riêng) để nhân viên lưu ảnh gửi qua Zalo/SMS cho
                    // khách không có mặt tại chỗ.
                    $btnStyle = 'display:inline-block; border-radius:6px; padding:8px 16px; font-size:13px; font-weight:500; text-decoration:none; cursor:pointer; border:none;';

                    return [
                        Placeholder::make('qr')
                            ->label('')
                            ->content(new HtmlString(
                                '<div style="text-align:center">'
                                . '<img src="' . e($qrImage) . '" alt="QR PayOS" style="margin:0 auto;width:260px;height:260px" />'
                                . '<p style="margin-top:12px">Số tiền cần thanh toán: <strong>' . number_format((float) $amount, 0, ',', '.') . 'đ</strong></p>'
                                . '<p>Hết hạn lúc: ' . $expiredAt->format('H:i d/m/Y') . '</p>'
                                . '<div style="margin-top:12px; display:flex; justify-content:center; gap:8px; flex-wrap:wrap;">'
                                . '<a href="' . e($checkoutUrl) . '" target="_blank" rel="noopener" style="' . $btnStyle . ' background:rgba(var(--primary-600),1); color:#fff;">Mở trang thanh toán PayOS</a>'
                                . '<a href="' . e($qrImage) . '" download="qr-thanh-toan-hoa-don-' . $record->id . '.svg" style="' . $btnStyle . ' background:#e5e7eb; color:#374151;">Tải ảnh QR</a>'
                                . '</div>'
                                . '</div>'
                            )),
                    ];
                }),

            // Tạo link/QR MoMo cho SỐ TIỀN CÒN LẠI của hoá đơn — cùng UX generatePayOsQr ở trên,
            // KHÁC ở chỗ MoMo KHÔNG có "tài khoản chung" dự phòng (xem InvoiceMomoService) nên chỉ
            // hiện được khi TOÀ NHÀ của hoá đơn này đã chọn "MoMo" và điền đủ 3 field.
            Actions\Action::make('generateMomoLink')
                ->label('Tạo link MoMo')
                ->icon('heroicon-o-qr-code')
                ->color('danger')
                ->visible(function () {
                    $record = $this->record;
                    $building = $record->contract_id
                        ? Contract::withoutGlobalScopes()
                            ->with(['room' => fn ($q) => $q->withoutGlobalScopes(), 'room.building' => fn ($q) => $q->withoutGlobalScopes()])
                            ->find($record->contract_id)
                            ?->room?->building
                        : null;

                    return $record->remainingAmount() > 0 && InvoiceMomoService::isConfiguredFor($building);
                })
                ->modalHeading('Link/QR thanh toán MoMo')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Đóng')
                ->form(function () {
                    $record = $this->record;

                    try {
                        if ($record->hasActiveMomoLink()) {
                            $qrImage   = QrCodeGenerator::dataUri($record->momo_qr_code, 260);
                            $amount    = $record->remainingAmount();
                            $expiredAt = $record->momo_expired_at;
                            $payUrl    = $record->momo_pay_url;
                        } else {
                            $result    = InvoiceMomoService::createPaymentRequest($record);
                            $qrImage   = $result['qr_image'];
                            $amount    = $result['amount'];
                            $expiredAt = Carbon::parse($result['expired_at']);
                            $payUrl    = $result['pay_url'];
                        }
                    } catch (\Throwable $e) {
                        return [
                            Placeholder::make('error')
                                ->label('')
                                ->content('Không tạo được link MoMo: ' . $e->getMessage()),
                        ];
                    }

                    $btnStyle = 'display:inline-block; border-radius:6px; padding:8px 16px; font-size:13px; font-weight:500; text-decoration:none; cursor:pointer; border:none;';

                    return [
                        Placeholder::make('momo')
                            ->label('')
                            ->content(new HtmlString(
                                '<div style="text-align:center">'
                                . '<img src="' . e($qrImage) . '" alt="QR MoMo" style="margin:0 auto;width:260px;height:260px" />'
                                . '<p style="margin-top:12px">Số tiền cần thanh toán: <strong>' . number_format((float) $amount, 0, ',', '.') . 'đ</strong></p>'
                                . '<p>Hết hạn lúc: ' . $expiredAt->format('H:i d/m/Y') . '</p>'
                                . '<div style="margin-top:12px; display:flex; justify-content:center; gap:8px; flex-wrap:wrap;">'
                                . '<a href="' . e($payUrl) . '" target="_blank" rel="noopener" style="' . $btnStyle . ' background:#d82d8b; color:#fff;">Mở trang thanh toán MoMo</a>'
                                . '<a href="' . e($qrImage) . '" download="qr-momo-hoa-don-' . $record->id . '.svg" style="' . $btnStyle . ' background:#e5e7eb; color:#374151;">Tải ảnh QR</a>'
                                . '</div>'
                                . '</div>'
                            )),
                    ];
                }),

            // Tạo link thanh toán VNPay — cùng UX 2 action trên.
            Actions\Action::make('generateVnpayLink')
                ->label('Tạo link VNPay')
                ->icon('heroicon-o-qr-code')
                ->color('info')
                ->visible(function () {
                    $record = $this->record;
                    $building = $record->contract_id
                        ? Contract::withoutGlobalScopes()
                            ->with(['room' => fn ($q) => $q->withoutGlobalScopes(), 'room.building' => fn ($q) => $q->withoutGlobalScopes()])
                            ->find($record->contract_id)
                            ?->room?->building
                        : null;

                    return $record->remainingAmount() > 0 && InvoiceVnpayService::isConfiguredFor($building);
                })
                ->modalHeading('Link thanh toán VNPay')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Đóng')
                ->form(function () {
                    $record = $this->record;

                    try {
                        if ($record->hasActiveVnpayLink()) {
                            $qrImage   = QrCodeGenerator::dataUri($record->vnpay_payment_url, 260);
                            $amount    = $record->remainingAmount();
                            $expiredAt = $record->vnpay_expired_at;
                            $paymentUrl = $record->vnpay_payment_url;
                        } else {
                            $result     = InvoiceVnpayService::createPaymentUrl($record, request()->ip());
                            $qrImage    = $result['qr_image'];
                            $amount     = $result['amount'];
                            $expiredAt  = Carbon::parse($result['expired_at']);
                            $paymentUrl = $result['payment_url'];
                        }
                    } catch (\Throwable $e) {
                        return [
                            Placeholder::make('error')
                                ->label('')
                                ->content('Không tạo được link VNPay: ' . $e->getMessage()),
                        ];
                    }

                    $btnStyle = 'display:inline-block; border-radius:6px; padding:8px 16px; font-size:13px; font-weight:500; text-decoration:none; cursor:pointer; border:none;';

                    return [
                        Placeholder::make('vnpay')
                            ->label('')
                            ->content(new HtmlString(
                                '<div style="text-align:center">'
                                . '<img src="' . e($qrImage) . '" alt="QR VNPay" style="margin:0 auto;width:260px;height:260px" />'
                                . '<p style="margin-top:12px">Số tiền cần thanh toán: <strong>' . number_format((float) $amount, 0, ',', '.') . 'đ</strong></p>'
                                . '<p>Hết hạn lúc: ' . $expiredAt->format('H:i d/m/Y') . '</p>'
                                . '<div style="margin-top:12px; display:flex; justify-content:center; gap:8px; flex-wrap:wrap;">'
                                . '<a href="' . e($paymentUrl) . '" target="_blank" rel="noopener" style="' . $btnStyle . ' background:#005baa; color:#fff;">Mở trang thanh toán VNPay</a>'
                                . '<a href="' . e($qrImage) . '" download="qr-vnpay-hoa-don-' . $record->id . '.svg" style="' . $btnStyle . ' background:#e5e7eb; color:#374151;">Tải ảnh QR</a>'
                                . '</div>'
                                . '</div>'
                            )),
                    ];
                }),

            // In phiếu "Thông báo tiền phòng trọ" — mở PDF ở tab mới (route thường, không qua
            // Livewire, xem InvoicePrintController) — có kèm mã QR chuyển khoản đúng chủ toà nhà nếu
            // toà nhà đó đã khai báo tài khoản ngân hàng (Building::hasOwnerBankInfo).
            Actions\Action::make('printInvoice')
                ->label('In phiếu thu')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->url(fn () => URL::route('minihouse.invoices.print', ['invoice' => $this->record]))
                ->openUrlInNewTab(),

            // Chỉ "Chủ toà nhà" (quyền approve_invoice_payments) mới bấm được — xác nhận khoản tiền
            // mặt/chuyển khoản nhân viên vừa ghi nhận (mục "Thanh toán" bên dưới) là CÓ THẬT, trước
            // đó Invoice.amount_paid chưa cộng khoản này (xem InvoicePaymentObserver::resyncInvoice).
            // Không hiện cho khoản đã tự duyệt sẵn qua PayOS (webhook tự xác nhận, không cần duyệt).
            Actions\Action::make('approvePayment')
                ->label('Duyệt thanh toán')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible(fn () => (auth()->user()?->isSuperAdmin() || auth()->user()?->can('approve_invoice_payments')) && $this->pendingPayment() !== null)
                ->requiresConfirmation()
                ->modalDescription(fn () => 'Xác nhận đã thực sự nhận đủ ' . number_format((float) $this->pendingPayment()?->amount, 0, ',', '.') . 'đ cho hoá đơn này?')
                ->action(function (): void {
                    $payment = $this->pendingPayment();

                    if (! $payment) {
                        return;
                    }

                    // Nghiệp vụ CHỈ nhận ĐÚNG 1 lần thanh toán/hoá đơn (Invoice::validateSinglePayment()
                    // — áp khi TẠO mới, nhưng trước đây KHÔNG áp lại khi DUYỆT). Nếu giữa lúc dòng
                    // pending này được ghi tay và lúc bấm duyệt, hoá đơn đã có 1 dòng KHÁC được duyệt
                    // rồi (VD khách vừa trả online qua PayOS/MoMo/VNPay, webhook tự duyệt luôn) thì
                    // duyệt thêm dòng tiền mặt/chuyển khoản cũ này sẽ cộng dồn sai amount_paid (vượt
                    // quá tổng hoá đơn) và tạo thêm 1 dòng "Thu" thứ 2 sai trong sổ Thu Chi.
                    if ($this->record->payments()->where('status', InvoicePayment::STATUS_APPROVED)->exists()) {
                        Notification::make()
                            ->title('Hoá đơn này đã có 1 khoản thanh toán khác được duyệt rồi')
                            ->body('Có thể khách đã thanh toán qua cổng trực tuyến trước đó — vui lòng kiểm tra lại trước khi duyệt khoản này để tránh tính trùng tiền.')
                            ->danger()
                            ->send();

                        return;
                    }

                    $payment->update([
                        'status'      => InvoicePayment::STATUS_APPROVED,
                        'approved_at' => now(),
                        'approved_by' => auth()->id(),
                    ]);

                    $this->refreshFormData(['payments']);

                    Notification::make()
                        ->title('Đã duyệt thanh toán')
                        ->success()
                        ->send();
                }),

            Actions\DeleteAction::make(),
        ];
    }

    private function pendingPayment(): ?InvoicePayment
    {
        return $this->record->payments()->where('status', InvoicePayment::STATUS_PENDING)->first();
    }
}
