<?php

declare(strict_types=1);

namespace App\Filament\Resources\PartnerEscrowResource\Pages;

use App\Filament\Resources\PartnerEscrowResource;
use App\Models\Partner;
use App\Models\PartnerEscrowDeduction as Deduction;
use App\Models\PartnerEscrowEntry as Entry;
use App\Services\EscrowPayosService;
use App\Services\EscrowService;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Carbon;

// Chi tiết ký quỹ của 1 đối tác: tổng quan + thao tác. Số dư chỉ đổi qua EscrowService (sổ bút toán) — trang này không ghi số dư trực tiếp.
class ViewPartnerEscrow extends ViewRecord
{
    protected static string $resource = PartnerEscrowResource::class;

    public function getTitle(): string
    {
        /** @var Partner $partner */
        $partner = $this->getRecord();

        return 'Ký quỹ — ' . ($partner->legal_name ?: $partner->name);
    }

    private function escrow(): EscrowService
    {
        return app(EscrowService::class);
    }

    private static function money(?int $amount): string
    {
        return $amount === null ? '—' : number_format($amount, 0, ',', '.') . 'đ';
    }

    public function infolist(Infolist $infolist): Infolist
    {
        $summary = fn (): array => $this->escrow()->summary($this->getRecord()->fresh());

        return $infolist->schema([
            Section::make('Tổng quan')->columns(4)->schema([
                TextEntry::make('status')->label('Trạng thái')->badge()->state(fn () => $summary()['status_label'])
                    ->color(fn () => match ($summary()['status']) {
                        EscrowService::STATE_OK => 'success', EscrowService::STATE_GRACE, EscrowService::STATE_LOW => 'warning',
                        EscrowService::STATE_SUSPENDED => 'danger', default => 'gray',
                    }),
                TextEntry::make('min')->label('Mức tối thiểu')->state(fn () => self::money($summary()['min_amount'])),
                TextEntry::make('balance')->label('Số dư')->state(fn () => self::money($summary()['balance'])),
                TextEntry::make('available')->label('Khả dụng (đã trừ tạm giữ ' . self::money($summary()['held_amount']) . ')')->state(fn () => self::money($summary()['available_amount'])),
                TextEntry::make('shortfall')->label('Cần nạp thêm')->state(fn () => self::money($summary()['shortfall'])),
                TextEntry::make('debt')->label('Công nợ (trừ quá số dư)')->state(fn () => self::money($summary()['debt']))->color(fn () => $summary()['debt'] > 0 ? 'danger' : null),
                TextEntry::make('topup_due')->label('Hạn nạp bù')->state(fn () => $summary()['topup_due_at'] ? Carbon::parse($summary()['topup_due_at'])->format('d/m/Y H:i') : '—'),
                TextEntry::make('terminated')->label('Chấm dứt hợp đồng')->columnSpanFull()
                    ->visible(fn () => $summary()['terminated_at'] !== null)
                    ->state(fn () => 'Chấm dứt ' . Carbon::parse($summary()['terminated_at'])->format('d/m/Y') . ' — được hoàn từ ' . Carbon::parse($summary()['release_available_at'])->format('d/m/Y')
                        . ($summary()['release_blockers'] === [] ? '. Đủ điều kiện hoàn ký quỹ.' : '. Còn chặn: ' . implode('; ', $summary()['release_blockers']) . '.')),
                TextEntry::make('flow')->label('Luồng tiền mới')->state(fn () => $this->getRecord()->payment_flow_effective_at?->format('d/m/Y') ?? 'Chưa hiệu lực — 365home thu hộ'),
            ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        $isSuperAdmin = fn (): bool => auth()->user()?->isSuperAdmin() ?? false;

        return [
            Action::make('depositQr')
                ->label('Nạp ký quỹ (QR PayOS)')->icon('heroicon-o-qr-code')->color('success')
                ->form([Forms\Components\TextInput::make('amount')->label('Số tiền nạp (đ)')->numeric()->required()
                    ->minValue((int) config('escrow.deposit_min_amount'))->default(fn () => max((int) config('escrow.deposit_min_amount'), $this->escrow()->summary($this->getRecord()->fresh())['shortfall']))])
                ->action(function (array $data) {
                    $deposit = app(EscrowPayosService::class)->createDeposit($this->getRecord(), (int) $data['amount'], auth()->user());

                    Notification::make()->success()->persistent()
                        ->title('Đã tạo yêu cầu nạp ' . self::money((int) $deposit->amount))
                        ->body($deposit->payos_checkout_url
                            ? "Mở liên kết để quét QR thanh toán (hạn {$deposit->payos_expired_at->format('d/m/Y H:i')}): {$deposit->payos_checkout_url}"
                            : "Chưa tạo được QR PayOS — chuyển khoản theo nội dung {$deposit->transaction_code}, Super Admin sẽ ghi nhận.")
                        ->send();
                }),

            Action::make('setMin')
                ->label('Đặt mức tối thiểu')->icon('heroicon-o-adjustments-horizontal')->visible($isSuperAdmin)
                ->form([
                    Forms\Components\TextInput::make('min_amount')->label('Mức ký quỹ tối thiểu (đ) — để trống = bỏ áp dụng')->numeric()->minValue(0)
                        ->default(fn () => $this->getRecord()->escrow_min_amount)
                        ->helperText(fn () => 'Gợi ý theo số phòng đang bán: ' . self::money($this->escrow()->suggestedMinAmount($this->getRecord()))),
                    Forms\Components\DateTimePicker::make('enforce_from')->label('Bắt đầu khoá bán nếu thiếu (để trống = ' . config('escrow.initial_grace_days') . ' ngày kể từ hôm nay)')->native(false),
                ])
                ->action(function (array $data) {
                    $this->escrow()->setMinAmount($this->getRecord(), ! empty($data['min_amount']) ? (int) $data['min_amount'] : null, ! empty($data['enforce_from']) ? Carbon::parse($data['enforce_from']) : null, auth()->user());
                    Notification::make()->success()->title('Đã cập nhật mức ký quỹ.')->send();
                    $this->refreshFormData(['escrow_min_amount']);
                }),

            Action::make('manualDeposit')
                ->label('Ghi nhận nạp tay')->icon('heroicon-o-banknotes')->visible($isSuperAdmin)
                ->form([
                    Forms\Components\TextInput::make('amount')->label('Số tiền (đ)')->numeric()->required()->minValue(1),
                    Forms\Components\Textarea::make('reason')->label('Lý do / nội dung chuyển khoản')->required()->maxLength(2000),
                    Forms\Components\TextInput::make('reference')->label('Số tham chiếu giao dịch')->maxLength(255),
                    Forms\Components\FileUpload::make('evidence')->label('Chứng từ chuyển khoản (bắt buộc)')->multiple()->required()->maxFiles(5)->maxSize(10240)
                        ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])->storeFiles(false),
                ])
                ->action(function (array $data) {
                    $entry = $this->escrow()->deposit($this->getRecord(), (int) $data['amount'], ['reason' => $data['reason'], 'reference' => $data['reference'] ?? null], auth()->user());
                    foreach ((array) ($data['evidence'] ?? []) as $file) {
                        $entry->addMedia($file->getRealPath())->usingFileName($file->getClientOriginalName())->toMediaCollection('evidence');
                    }
                    $this->escrow()->notifyDeposited($this->getRecord(), (int) $data['amount']);
                    Notification::make()->success()->title('Đã ghi nhận khoản nạp.')->send();
                }),

            Action::make('proposeDeduction')
                ->label('Đề xuất trừ ký quỹ')->icon('heroicon-o-minus-circle')->color('danger')->visible($isSuperAdmin)
                ->form([
                    Forms\Components\Select::make('type')->label('Loại khoản trừ')->required()->native(false)
                        ->options(collect(Deduction::TYPES)->mapWithKeys(fn (string $type) => [$type => Entry::TYPES[$type]])->all()),
                    Forms\Components\Select::make('case_code')->label('Trường hợp theo phụ lục hợp đồng')->native(false)->options(Deduction::CASES),
                    Forms\Components\TextInput::make('amount')->label('Số tiền (đ)')->numeric()->required()->minValue(1),
                    Forms\Components\Textarea::make('reason')->label('Lý do')->required()->maxLength(2000),
                    Forms\Components\TextInput::make('order_code')->label('Mã đơn (bắt buộc với hoàn tiền thay đối tác, huỷ phòng, chargeback)')->maxLength(50),
                    Forms\Components\TextInput::make('reference')->label('Số biên bản / tham chiếu')->maxLength(255),
                    Forms\Components\Toggle::make('is_urgent')->label('Khẩn — trừ ngay, đối tác khiếu nại sau'),
                    Forms\Components\FileUpload::make('evidence')->label('Chứng từ (bắt buộc với khoản khác)')->multiple()->maxFiles(5)->maxSize(10240)
                        ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])->storeFiles(false),
                ])
                ->action(function (array $data) {
                    $needsOrder = $data['type'] === Entry::TYPE_DEDUCT_REFUND || in_array($data['case_code'] ?? null, [Deduction::CASE_HOST_CANCELLED, Deduction::CASE_CHARGEBACK], true);
                    if ($needsOrder && blank($data['order_code'] ?? null)) {
                        Notification::make()->danger()->title('Khoản trừ này phải gắn mã đơn.')->send();
                        throw new \Filament\Support\Exceptions\Halt();
                    }
                    if ($data['type'] === Entry::TYPE_DEDUCT_OTHER && empty($data['evidence'])) {
                        Notification::make()->danger()->title('Khoản trừ khác phải đính kèm chứng từ.')->send();
                        throw new \Filament\Support\Exceptions\Halt();
                    }

                    try {
                        $deduction = $this->escrow()->proposeDeduction($this->getRecord(), $data, auth()->user());
                    } catch (\DomainException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                        throw new \Filament\Support\Exceptions\Halt();
                    }
                    foreach ((array) ($data['evidence'] ?? []) as $file) {
                        $deduction->addMedia($file->getRealPath())->usingFileName($file->getClientOriginalName())->toMediaCollection('evidence');
                    }
                    Notification::make()->success()->title($deduction->is_urgent ? 'Đã trừ ký quỹ (khẩn) và thông báo đối tác.' : 'Đã tạo đề xuất trừ và thông báo đối tác.')->send();
                }),
        ];
    }
}
