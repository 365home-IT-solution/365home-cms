@php
    $sub = $this->getSubscription();
    $payment = $this->getPayment();
    $plans = $this->getPlans();
    $state = $sub?->state();
    $daysLeft = $sub?->daysLeft();
    $locked = in_array($state, ['expired', 'cancelled'], true);
    $soon = ! $locked && $daysLeft !== null && $daysLeft <= 7;
    $badge = match ($state) { 'active' => 'success', 'trial' => 'info', 'expired' => 'danger', default => 'gray' };

    $isPending = $payment && $payment->status === \App\Models\SubscriptionPayment::STATUS_PENDING;
    $isPaid = $payment && $payment->status === \App\Models\SubscriptionPayment::STATUS_PAID;
    $qr = $isPending && filled($payment->payos_qr_code) ? \Modules\BladeThemeV1\Support\QrCodeGenerator::dataUri((string) $payment->payos_qr_code, 240, 1) : null;
    $bank = $isPending ? (\Modules\Minihouse\App\Support\VietnameseBanks::shortName($payment->payos_bank_bin) ?: null) : null;
@endphp

<x-filament-panels::page>
    <style>
        .sp { display: grid; gap: 16px; width: 100%; }
        .sp-line { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 8px 16px; }
        .sp-grid { display: grid; gap: 16px; grid-template-columns: 1fr; align-items: stretch; }
        .sp-muted { color: rgb(var(--gray-500)); font-size: 13px; }
        .dark .sp-muted { color: rgb(var(--gray-400)); }
        .sp-title { font-size: 18px; font-weight: 700; }
        .sp-box { border: 1px solid rgba(115,115,115,.35); border-radius: 12px; padding: 18px; display: flex; flex-direction: column; gap: 14px; background: transparent; }
        .sp-box.cur { border-color: rgb(var(--primary-600)); box-shadow: 0 0 0 1px rgb(var(--primary-600)); }
        .sp-head { display: grid; gap: 4px; padding-bottom: 14px; border-bottom: 1px solid rgba(115,115,115,.25); }
        .sp-price { font-size: 22px; font-weight: 700; line-height: 1.2; }
        .sp-feat { margin: 0; padding: 0; list-style: none; display: grid; gap: 8px; align-content: start; font-size: 13px; flex: 1; }
        .sp-btnrow { margin-top: auto; }
        .sp-feat li { display: flex; gap: 6px; align-items: flex-start; line-height: 1.35; }
        .sp-feat li::before { content: "✓"; color: rgb(var(--success-600)); font-weight: 700; flex: none; }
        .sp-periods { display: grid; gap: 10px; grid-template-columns: repeat(2, minmax(0, 1fr)); margin-bottom: 14px; }
        .sp-period { display: flex; flex-direction: column; align-items: center; gap: 2px; padding: 10px 6px; border: 1px solid rgba(115,115,115,.35); border-radius: 10px; background: transparent; cursor: pointer; font-size: 14px; }
        .sp-period.on { border-color: rgb(var(--primary-600)); box-shadow: 0 0 0 1px rgb(var(--primary-600)); color: rgb(var(--primary-600)); }
        .dark .sp-period.on { color: rgb(var(--primary-400)); }
        @media (min-width: 640px) { .sp-periods { grid-template-columns: repeat(5, minmax(0, 1fr)); } }
        .sp-seg { display: inline-flex; gap: 6px; flex-wrap: wrap; }
        .sp-kv { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 7px 0; border-bottom: 1px solid rgb(var(--gray-100)); font-size: 14px; }
        .dark .sp-kv { border-color: rgba(255,255,255,.07); }
        .sp-kv:last-child { border-bottom: 0; }
        .sp-kv span { color: rgb(var(--gray-500)); flex: none; }
        .dark .sp-kv span { color: rgb(var(--gray-400)); }
        .sp-kv strong { text-align: right; word-break: break-all; display: inline-flex; gap: 8px; align-items: center; justify-content: flex-end; }
        .sp-copy { border: 0; background: none; color: rgb(var(--primary-600)); font-size: 12px; font-weight: 600; cursor: pointer; padding: 0; }
        .dark .sp-copy { color: rgb(var(--primary-400)); }
        .sp-pay { display: grid; gap: 16px; grid-template-columns: 1fr; align-items: start; }
        .sp-qr { width: 100%; max-width: 220px; aspect-ratio: 1; object-fit: contain; background: #fff; border: 1px solid rgb(var(--gray-200)); border-radius: 10px; padding: 6px; margin: 0 auto; display: block; }
        @media (min-width: 640px) { .sp-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } .sp-pay { grid-template-columns: 220px 1fr; } }
        @media (min-width: 1100px) { .sp-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
    </style>

    {{-- Popup hết hạn --}}
    @if ($locked)
        <x-filament::modal id="subscription-expired" width="md" :close-button="false" :close-by-clicking-away="false" :close-by-escaping="false" alignment="center" icon="heroicon-o-exclamation-triangle" icon-color="danger">
            <x-slot name="heading">Gói dịch vụ đã {{ $state === 'cancelled' ? 'bị khoá' : 'hết hạn' }}</x-slot>
            <x-slot name="description">
                @if ($state === 'cancelled')
                    Tài khoản đã bị khoá. Vui lòng liên hệ công ty hoặc đăng ký gói để tiếp tục sử dụng.
                @else
                    Gói của bạn đã hết hạn{{ $sub?->expires_at ? ' từ ' . $sub->expires_at->format('d/m/Y') : '' }}. Vui lòng đăng ký/gia hạn và thanh toán để tiếp tục sử dụng. Trong thời gian này bạn chỉ vào được trang Gói dịch vụ.
                @endif
            </x-slot>
            <x-slot name="footerActions">
                <x-filament::button x-on:click="close(); $nextTick(() => document.getElementById('subscription-plans')?.scrollIntoView({ behavior: 'smooth' }))">Đăng ký / Gia hạn ngay</x-filament::button>
            </x-slot>
        </x-filament::modal>
        <div x-data x-init="$nextTick(() => $dispatch('open-modal', { id: 'subscription-expired' }))"></div>
    @endif

    {{-- Cửa sổ thanh toán: chỉ hiện sau khi bấm Gia hạn / Chọn gói --}}
    <x-filament::modal id="subscription-payment" width="2xl" :close-by-clicking-away="false" x-on:modal-closed.window="if ($event.detail.id === 'subscription-payment') $wire.set('paymentId', null)">
        <x-slot name="heading">{{ $payment ? ($isPaid ? 'Thanh toán thành công' : 'Thanh toán ' . $payment->plan?->name . ' · ' . $payment->months . ' tháng') : 'Thanh toán' }}</x-slot>
        <x-slot name="description">{{ $isPending ? 'Mã giao dịch ' . $payment->transaction_code : '' }}</x-slot>

        @if ($payment)
            <div x-data="{ copied: null, copy(t, k) { if (navigator.clipboard) navigator.clipboard.writeText(t); this.copied = k; setTimeout(() => this.copied = null, 1500); } }" @if ($isPending) wire:poll.5s @endif>
                @if ($isPaid)
                    <p style="font-size:14px">Gói <strong>{{ $payment->plan?->name }}</strong> đã được gia hạn đến <strong>{{ $payment->extends_to?->format('d/m/Y') }}</strong>.</p>
                    <div style="margin-top:14px"><x-filament::button wire:click="closePayment">Đóng</x-filament::button></div>
                @elseif ($isPending)
                    <div class="sp-pay">
                        @if ($qr)
                            <div><img class="sp-qr" src="{{ $qr }}" alt="Mã QR" loading="lazy"><p class="sp-muted" style="text-align:center;margin-top:6px">Quét bằng app ngân hàng</p></div>
                        @endif
                        <div @if (! $qr) style="grid-column: 1 / -1" @endif>
                            <div class="sp-price">{{ $payment->amount_vnd > 0 ? number_format($payment->amount_vnd) . 'đ' : 'Công ty sẽ báo số tiền' }}</div>
                            <div style="margin-top:8px">
                                @if ($payment->payos_account_number)
                                    <div class="sp-kv"><span>Ngân hàng</span><strong>{{ $bank ?: 'BIN ' . $payment->payos_bank_bin }}</strong></div>
                                    <div class="sp-kv"><span>Số tài khoản</span><strong>{{ $payment->payos_account_number }}<button type="button" class="sp-copy" x-on:click="copy('{{ $payment->payos_account_number }}', 'acc')" x-text="copied === 'acc' ? 'Đã chép' : 'Chép'"></button></strong></div>
                                    <div class="sp-kv"><span>Chủ tài khoản</span><strong>{{ $payment->payos_account_name }}</strong></div>
                                @endif
                                <div class="sp-kv"><span>Nội dung CK</span><strong>{{ $payment->transaction_code }}<button type="button" class="sp-copy" x-on:click="copy('{{ $payment->transaction_code }}', 'code')" x-text="copied === 'code' ? 'Đã chép' : 'Chép'"></button></strong></div>
                            </div>

                            @if ($payment->source === 'request')
                                <p class="sp-muted" style="margin-top:10px">Yêu cầu đã gửi tới công ty. Công ty sẽ liên hệ báo số tiền; khi chuyển khoản ghi nội dung <strong>{{ $payment->transaction_code }}</strong>. Gói được kích hoạt khi công ty xác nhận đã nhận tiền.</p>
                            @elseif ($payment->payos_account_number)
                                <p class="sp-muted" style="margin-top:10px">Chuyển đúng số tiền và nội dung. Gói tự gia hạn sau khi nhận tiền.</p>
                            @else
                                <p class="sp-muted" style="margin-top:10px">Chưa có link thanh toán tự động — vui lòng liên hệ công ty kèm mã giao dịch.</p>
                            @endif

                            <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:12px">
                                @if ($payment->payos_checkout_url)
                                    <x-filament::button tag="a" :href="$payment->payos_checkout_url" target="_blank" size="sm">Mở trang thanh toán</x-filament::button>
                                @endif
                                <x-filament::button color="gray" size="sm" wire:click="cancelPending" wire:confirm="Huỷ giao dịch này?">Huỷ giao dịch</x-filament::button>
                            </div>
                        </div>
                    </div>
                @else
                    <p style="font-size:14px">Giao dịch đã đóng ({{ \App\Models\SubscriptionPayment::STATUSES[$payment->status] ?? $payment->status }}).</p>
                    <div style="margin-top:14px"><x-filament::button wire:click="closePayment">Đóng</x-filament::button></div>
                @endif
            </div>
        @endif
    </x-filament::modal>

    <div class="sp">
        {{-- Gói hiện tại --}}
        @if ($sub)
            <x-filament::section>
                <div class="sp-line">
                    <div>
                        <div class="sp-line" style="justify-content:flex-start;gap:10px">
                            <span class="sp-title">{{ $sub->plan?->name }}</span>
                            <x-filament::badge :color="$badge">{{ \App\Models\PartnerSubscription::STATES[$state] }}</x-filament::badge>
                        </div>
                        <div class="sp-muted" style="margin-top:4px">
                            @if ($sub->expires_at)
                                Hết hạn {{ $sub->expires_at->format('d/m/Y') }}
                                @if ($daysLeft !== null && $daysLeft >= 0) · còn {{ $daysLeft }} ngày @endif
                            @else
                                Không giới hạn thời gian
                            @endif
                        </div>
                    </div>
                </div>

                @if ($locked)
                    <p style="margin-top:12px;font-size:14px;color:rgb(var(--danger-600))">Gói đã {{ $state === 'cancelled' ? 'bị khoá' : 'hết hạn' }} — các tính năng đang bị khoá. Chọn gói bên dưới để tiếp tục.</p>
                @elseif ($soon)
                    <p style="margin-top:12px;font-size:14px;color:rgb(var(--warning-600))">Gói sắp hết hạn — gia hạn sớm để không bị gián đoạn.</p>
                @endif
            </x-filament::section>
        @endif

        {{-- Chọn gói --}}
        <x-filament::section id="subscription-plans">
            <x-slot name="heading">Gia hạn / mua gói</x-slot>

            @php $firstPlan = $plans->first(); @endphp
            <div class="sp-periods">
                @foreach (config('subscription.period_options', [1, 3, 6, 9, 12]) as $n)
                    @php $pct = $firstPlan ? $firstPlan->discountPercent($n) : 0; @endphp
                    <button type="button" class="sp-period {{ $this->periods === $n ? 'on' : '' }}" wire:click="$set('periods', {{ $n }})">
                        <strong>{{ $n }} tháng</strong>
                        <span class="sp-muted">{{ $pct > 0 ? 'Giảm ' . $pct . '%' : ' ' }}</span>
                    </button>
                @endforeach
            </div>

            <div class="sp-grid">
                @forelse ($plans as $plan)
                    @php
                        $isCurrent = $sub && $sub->plan_id === $plan->id;
                        $payable = $plan->price_vnd > 0;
                        $total = $plan->amountFor($this->periods);
                        $original = $plan->price_vnd * $this->periods;
                        $pct = $plan->discountPercent($this->periods);
                    @endphp
                    <div class="sp-box {{ $isCurrent ? 'cur' : '' }}">
                        <div class="sp-head">
                            <div class="sp-line" style="gap:8px">
                                <strong style="font-size:16px">{{ $plan->name }}</strong>
                                @if ($isCurrent)<x-filament::badge color="primary" size="sm">Đang dùng</x-filament::badge>@endif
                            </div>
                            @if ($payable)
                                {{-- Tổng tiền = giá/tháng × số tháng − ưu đãi của kỳ — khớp đúng số tiền tạo ở bước thanh toán --}}
                                <div class="sp-price">{{ number_format($total) }}đ @if ($pct > 0)<s class="sp-muted" style="font-weight:400;font-size:14px">{{ number_format($original) }}đ</s>@endif</div>
                                <div class="sp-muted">{{ $plan->period_months * $this->periods }} tháng · {{ number_format((int) round($total / max(1, $plan->period_months * $this->periods))) }}đ/tháng @if ($pct > 0)· giảm {{ $pct }}% @endif</div>
                                @if ($plan->trial_months > 0)<div class="sp-muted" style="color:rgb(var(--success-600))">Miễn phí {{ $plan->trial_months }} tháng đầu</div>@endif
                            @else
                                <div class="sp-price" style="font-size:16px">Liên hệ báo giá</div>
                                <div class="sp-muted">Công ty sẽ báo số tiền</div>
                            @endif
                        </div>

                        <ul class="sp-feat">
                            <li><strong>Toàn bộ chức năng quản trị</strong></li>
                            <li><strong>Không giới hạn toà nhà và phòng</strong></li>
                        </ul>
                        @if (! empty($plan->support_info))
                            <ul class="sp-feat">@foreach ((array) $plan->support_info as $line)<li>{{ $line }}</li>@endforeach</ul>
                        @endif

                        <div class="sp-btnrow">
                            <x-filament::button class="w-full" wire:click="pay({{ $plan->id }})" wire:loading.attr="disabled" wire:target="pay({{ $plan->id }})">
                                @if ($payable) {{ $isCurrent ? 'Gia hạn' : 'Mua gói' }} @else {{ $isCurrent ? 'Gửi yêu cầu gia hạn' : 'Gửi yêu cầu đăng ký' }} @endif
                            </x-filament::button>
                        </div>
                    </div>
                @empty
                    <p class="sp-muted">Chưa có gói nào để lựa chọn.</p>
                @endforelse
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
