<?php

declare(strict_types=1);

namespace App\Filament\Resources\PartnerSettlementResource\Pages;

use App\Filament\Resources\PartnerSettlementResource;
use App\Models\PartnerSettlement as Settlement;
use App\Services\SettlementService;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewPartnerSettlement extends ViewRecord
{
    protected static string $resource = PartnerSettlementResource::class;

    public function getTitle(): string
    {
        /** @var Settlement $settlement */
        $settlement = $this->getRecord();

        return "Đối soát {$settlement->code}";
    }

    private function service(): SettlementService
    {
        return app(SettlementService::class);
    }

    private function settlement(): Settlement
    {
        return $this->getRecord()->fresh();
    }

    private static function money(int $amount): string
    {
        return ($amount < 0 ? '−' : '') . number_format(abs($amount), 0, ',', '.') . 'đ';
    }

    private function isSuperAdmin(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    private function isOwner(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->isSuperAdmin() || ($user?->partner_id === $this->getRecord()->partner_id && ($user->hasRole('partner') || $user->can('update_partner'))));
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Bảng đối soát')->columns(4)->schema([
                TextEntry::make('partner.name')->label('Đối tác'),
                TextEntry::make('period')->label('Kỳ')->state(fn () => $this->getRecord()->period_start->format('d/m/Y') . ' – ' . $this->getRecord()->period_end->format('d/m/Y')),
                TextEntry::make('status')->label('Trạng thái')->badge()->formatStateUsing(fn (string $state) => Settlement::STATUSES[$state] ?? $state),
                TextEntry::make('due_at')->label('Hạn nộp')->dateTime('d/m/Y')->placeholder('—'),
                TextEntry::make('revenue_total')->label('Doanh thu các đơn')->state(fn () => self::money($this->settlement()->revenue_total)),
                TextEntry::make('commission_total')->label('Hoa hồng')->state(fn () => self::money($this->settlement()->commission_total)),
                TextEntry::make('subsidy_total')->label('365home bù (khuyến mãi 365home chịu)')->state(fn () => self::money($this->settlement()->subsidy_total)),
                TextEntry::make('platform_collected_total')->label('365home đã thu hộ')->state(fn () => self::money($this->settlement()->platform_collected_total)),
                TextEntry::make('net')->label('Kết quả')->columnSpanFull()->weight('bold')
                    ->state(fn () => match ($this->settlement()->direction()) {
                        'partner_pays' => 'Đối tác nộp 365home ' . self::money($this->settlement()->net_amount),
                        'platform_pays' => '365home chi cho đối tác ' . self::money(-$this->settlement()->net_amount),
                        default => 'Hai bên không còn khoản phải nộp hay chi',
                    })
                    ->helperText('Công thức: hoa hồng − 365home bù − tiền 365home đã thu hộ. Hoá đơn GTGT xuất cho phần hoa hồng; ký quỹ không phải doanh thu.'),
            ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('send')
                ->label('Gửi cho đối tác')->icon('heroicon-o-paper-airplane')->requiresConfirmation()
                ->modalDescription('Gửi bảng đối soát cho đối tác (kèm QR nộp nếu đối tác phải nộp). Sau khi gửi không sửa số liệu trực tiếp được nữa.')
                ->visible(fn () => $this->isSuperAdmin() && $this->settlement()->status === Settlement::STATUS_DRAFT)
                ->action(function () {
                    $this->guard(fn () => $this->service()->send($this->settlement(), auth()->user()), 'Đã gửi bảng đối soát cho đối tác.');
                }),

            Action::make('paymentLink')
                ->label('Lấy QR nộp hoa hồng')->icon('heroicon-o-qr-code')->color('success')
                ->visible(fn () => $this->isOwner() && $this->settlement()->net_amount > 0 && in_array($this->settlement()->status, [Settlement::STATUS_SENT, Settlement::STATUS_DISPUTED], true))
                ->action(function () {
                    try {
                        $settlement = $this->service()->paymentLink($this->settlement());
                        Notification::make()->success()->persistent()->title('Nộp ' . self::money($settlement->net_amount) . " — nội dung chuyển khoản: {$settlement->code}")
                            ->body($settlement->payos_checkout_url
                                ? "Mở liên kết để quét QR (hạn {$settlement->payos_expired_at?->format('d/m/Y H:i')}): {$settlement->payos_checkout_url}"
                                : 'Chưa tạo được QR PayOS — chuyển khoản theo nội dung trên, Super Admin sẽ ghi nhận.')
                            ->send();
                    } catch (\DomainException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),

            Action::make('dispute')
                ->label('Khiếu nại đơn')->icon('heroicon-o-exclamation-triangle')->color('warning')
                ->visible(fn () => $this->isOwner() && in_array($this->settlement()->status, [Settlement::STATUS_SENT, Settlement::STATUS_DISPUTED], true))
                ->form([
                    Forms\Components\Select::make('order_code')->label('Đơn khiếu nại')->required()->searchable()->native(false)
                        ->options(fn () => $this->getRecord()->orders()->pluck('order_code', 'order_code')),
                    Forms\Components\Textarea::make('reason')->label('Lý do')->required()->maxLength(2000),
                    Forms\Components\FileUpload::make('evidence')->label('Chứng từ')->multiple()->maxFiles(5)->maxSize(10240)
                        ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])->storeFiles(false),
                ])
                ->action(function (array $data) {
                    try {
                        $dispute = $this->service()->disputeOrder($this->settlement(), $data['order_code'], $data['reason'], auth()->user());
                        foreach ((array) ($data['evidence'] ?? []) as $file) {
                            $dispute->addMedia($file->getRealPath())->usingFileName($file->getClientOriginalName())->toMediaCollection('evidence');
                        }
                        Notification::make()->success()->title('Đã gửi khiếu nại.')->send();
                    } catch (\DomainException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),

            Action::make('invoicePdf')
                ->label('Bảng kê hoá đơn GTGT')->icon('heroicon-o-document-arrow-down')->color('gray')
                ->visible(fn () => $this->settlement()->status !== Settlement::STATUS_DRAFT && $this->settlement()->commission_total > 0)
                ->action(fn () => response()->streamDownload(fn () => print ($this->service()->invoicePdf($this->settlement())), 'bang-ke-hoa-don-' . $this->settlement()->code . '.pdf')),

            Action::make('markInvoiced')
                ->label(fn () => $this->settlement()->invoice_no ? 'Sửa số hoá đơn' : 'Ghi nhận đã xuất hoá đơn')->icon('heroicon-o-receipt-percent')->color('gray')
                ->visible(fn () => $this->isSuperAdmin() && $this->settlement()->status !== Settlement::STATUS_DRAFT && $this->settlement()->commission_total > 0)
                ->form([Forms\Components\TextInput::make('invoice_no')->label('Số hoá đơn')->required()->maxLength(50)->default(fn () => $this->settlement()->invoice_no),
                    Forms\Components\DatePicker::make('issued_at')->label('Ngày xuất')->native(false)->default(now())])
                ->action(function (array $data) {
                    $this->guard(fn () => $this->service()->markInvoiced($this->settlement(), $data['invoice_no'], auth()->user(), \Illuminate\Support\Carbon::parse($data['issued_at'])), 'Đã ghi nhận hoá đơn.');
                }),

            Action::make('markPaid')
                ->label('Ghi nhận đối tác đã nộp')->icon('heroicon-o-check-circle')->color('success')
                ->visible(fn () => $this->isSuperAdmin() && $this->settlement()->net_amount > 0 && in_array($this->settlement()->status, [Settlement::STATUS_SENT, Settlement::STATUS_DISPUTED], true))
                ->form([Forms\Components\TextInput::make('reference')->label('Số tham chiếu chuyển khoản')->required()->maxLength(255)])
                ->action(function (array $data) {
                    $this->service()->markPaid($this->settlement(), $data['reference'])
                        ? Notification::make()->success()->title('Đã ghi nhận đối tác đã nộp.')->send()
                        : Notification::make()->warning()->title('Bảng đối soát này không còn khoản đối tác phải nộp.')->send();
                }),

            Action::make('paidOut')
                ->label('Xác nhận 365home đã chi')->icon('heroicon-o-banknotes')->color('success')
                ->visible(fn () => $this->isSuperAdmin() && $this->settlement()->net_amount < 0 && $this->settlement()->status === Settlement::STATUS_SENT)
                ->form([Forms\Components\TextInput::make('reference')->label('Số tham chiếu chuyển khoản chi')->required()->maxLength(255)])
                ->action(function (array $data) {
                    $this->guard(fn () => $this->service()->markPaidOut($this->settlement(), $data['reference'], auth()->user()), 'Đã ghi nhận 365home đã chi.');
                }),
        ];
    }

    private function guard(\Closure $action, string $success): void
    {
        try {
            $action();
            Notification::make()->success()->title($success)->send();
        } catch (\DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }
}
