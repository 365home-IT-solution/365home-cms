<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Pages;

use App\Models\Partner;
use App\Models\PartnerSubscription;
use App\Models\SubscriptionPayment as Pay;
use App\Models\SubscriptionPlan;
use App\Services\SubscriptionService;
use App\Support\SubscriptionGate;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Validation\ValidationException;

// Trang "Gói dịch vụ" của ĐỐI TÁC MiniHouse: xem gói/hạn, chọn kỳ (1/3/6/9/12 tháng) và thanh toán (QR/link PayOS), bật tự gia hạn.
// Luôn truy cập được kể cả khi gói hết hạn (các trang khác bị khoá).
class MinihouseMySubscription extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationGroup = 'Quản lý';

    protected static ?string $navigationLabel = 'Gói dịch vụ';

    protected static ?string $title = 'Gói dịch vụ';

    protected static ?string $slug = 'my-subscription';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'minihouse::filament.pages.my-subscription';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && $user->partner_id && ! $user->isSuperAdmin() && SubscriptionGate::stateFor($user)['sub'] !== null;
    }

    public function getPartner(): ?Partner
    {
        return Partner::query()->find(auth()->user()?->partner_id);
    }

    public function getSubscription(): ?PartnerSubscription
    {
        return $this->getPartner()?->subscription()->with('plan')->first();
    }

    /** @return \Illuminate\Support\Collection<int, SubscriptionPlan> */
    public function getPlans()
    {
        return SubscriptionPlan::query()->where('is_active', true)->where('partner_type', Partner::TYPE_MINIHOUSE)->orderBy('sort_order')->get();
    }

    // Giao dịch đối tác VỪA tạo bằng nút "Gia hạn" (hiện trong cửa sổ thanh toán). Không tự hiện lại khi tải trang.
    public ?int $paymentId = null;

    // Số tháng thanh toán trước (1, 3, 6, 9, 12 — xem config subscription.period_options).
    public int $periods = 1;

    public function getPayment(): ?Pay
    {
        return $this->paymentId
            ? Pay::query()->with('plan')->where('partner_id', auth()->user()?->partner_id)->find($this->paymentId)
            : null;
    }

    public function pay(int $planId): void
    {
        try {
            $payment = app(SubscriptionService::class)->createCheckout(
                $this->getPartner(),
                SubscriptionPlan::findOrFail($planId),
                in_array($this->periods, config('subscription.period_options'), true) ? $this->periods : 1
            );
        } catch (ValidationException $e) {
            Notification::make()->title('Không thực hiện được')->body(collect($e->errors())->flatten()->first())->danger()->send();

            return;
        }

        $this->paymentId = $payment->id;
        $this->dispatch('open-modal', id: 'subscription-payment');
    }

    public function cancelPending(): void
    {
        if (($payment = $this->getPayment()) && $payment->status === Pay::STATUS_PENDING) {
            app(SubscriptionService::class)->cancelPayment($payment);
        }

        $this->closePayment();
    }

    public function closePayment(): void
    {
        $this->paymentId = null;
        $this->dispatch('close-modal', id: 'subscription-payment');
    }

    public function toggleAutoRenew(): void
    {
        if ($sub = $this->getSubscription()) {
            $sub->update(['auto_renew' => ! $sub->auto_renew]);
            Notification::make()->title($sub->auto_renew ? 'Đã bật tự động gia hạn' : 'Đã tắt tự động gia hạn')->success()->send();
        }
    }
}
