@php
    $account = $this->account();
    $session = $this->session();
    $apiBlocked = $this->blockedReason(true);
    $manualBlocked = $this->blockedReason(false);
    $branches = $this->branches();
    $openApi = $this->openApiBanks();
    $cfg = $openApi[$bank['bank']] ?? null;
    $pending = $session && $session->isPending() && ! $session->applied;
    $overview = $this->overview();
    $direct = $mode === 'auto' ? app(\App\Services\Payment\Pay2SBankHubService::class)->directBankApiAvailable() : true;
    $tab = $direct ? $linkTab : 'window';
    $scopeName = collect($overview)->firstWhere('id', (string) ($this->categoryId ?? ''))['name'] ?? 'Cả đối tác (mặc định)';
    $modeLabel = fn (string $m) => match ($m) { 'auto' => 'Tự động (Pay2S)', 'manual' => 'Thủ công', default => 'Chưa cài' };
@endphp

<x-filament-panels::page>
    <div class="space-y-6" @if ($pending) wire:poll.3s="refreshSession" @endif
         x-data="{
            hub: null,
            sdkUrl: @js($this->sdkUrl()),
            load() {
                return new Promise((resolve, reject) => {
                    if (window.Pay2SBankHub) { return resolve(); }
                    const s = document.createElement('script');
                    s.src = this.sdkUrl; s.charset = 'utf-8'; s.onload = resolve; s.onerror = reject;
                    document.head.appendChild(s);
                });
            },
            async open(url, sessionId) {
                try { await this.load(); } catch (e) { window.open(url, '_blank', 'noopener'); return; }
                if (this.hub) { try { this.hub.destroy(); } catch (e) {} }
                const refresh = () => $wire.refreshSession();
                this.hub = window.Pay2SBankHub.create({ mode: 'modal', onSuccess: refresh, onExit: refresh, onExpired: refresh, onError: refresh });
                this.hub.initLink(url, sessionId);
                this.hub.open();
            },
         }"
         x-on:pay2s-open.window="open($event.detail.url, $event.detail.sessionId)">

        {{-- ── Tổng quan: mỗi chi nhánh một cách nhận tiền ── --}}
        <x-filament::section heading="Cách nhận tiền của từng chi nhánh" description="Mỗi chi nhánh chọn một trong hai: Tự động (Pay2S, đơn tự xác nhận) hoặc Thủ công (bạn tự xác nhận đã nhận tiền). Chi nhánh chưa cài riêng dùng tài khoản mặc định của đối tác. Bấm một dòng để cài đặt.">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-gray-500">
                        <tr><th class="py-2 pr-4">Phạm vi</th><th class="py-2 pr-4">Cách nhận tiền</th><th class="py-2 pr-4">Ngân hàng / Số tài khoản</th><th class="py-2"></th></tr>
                    </thead>
                    <tbody>
                        @foreach ($overview as $row)
                            <tr class="border-t border-gray-100 dark:border-gray-800 {{ (string) $row['id'] === (string) ($this->categoryId ?? '') ? 'bg-primary-50 dark:bg-white/5' : '' }}">
                                <td class="py-2 pr-4 font-medium">{{ $row['name'] }}</td>
                                <td class="py-2 pr-4">{{ $modeLabel($row['mode']) }}@if ($row['mode'] !== 'none' && ! $row['own'] && $row['id'] !== '') <span class="text-xs text-gray-500">(dùng mặc định)</span>@endif</td>
                                <td class="py-2 pr-4">{{ $row['number'] ? ($row['bank'] . ' · ' . $row['number']) : '—' }}</td>
                                <td class="py-2 text-right"><x-filament::button size="xs" color="gray" wire:click="selectScope('{{ $row['id'] }}')">Cài đặt</x-filament::button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        {{-- ── Phạm vi đang cài + chọn cách nhận tiền ── --}}
        <x-filament::section :heading="'Đang cài đặt: ' . $scopeName">
            @if ($account)
                <dl class="mb-4 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                    <div><dt class="text-gray-500">Cách nhận tiền hiện tại</dt><dd class="font-medium">{{ $account->isManual() ? 'Thủ công' : 'Tự động (' . $account->providerLabel() . ')' }}</dd></div>
                    <div><dt class="text-gray-500">Trạng thái</dt><dd class="font-medium">{{ $account->is_active ? 'Đang bật' : 'Đang tắt' }}</dd></div>
                    <div><dt class="text-gray-500">Ngân hàng</dt><dd class="font-medium">{{ $account->bank_code }}</dd></div>
                    <div><dt class="text-gray-500">Số tài khoản</dt><dd class="font-medium">{{ $account->account_number }}</dd></div>
                    <div><dt class="text-gray-500">Chủ tài khoản</dt><dd class="font-medium">{{ $account->account_holder }}</dd></div>
                    @unless ($account->isManual())
                        <div><dt class="text-gray-500">Webhook nhận tiền</dt><dd class="font-medium">{{ filled($account->webhook_token) ? 'Đã có' : 'Chưa có' }}</dd></div>
                    @endunless
                </dl>
            @else
                <p class="mb-4 text-sm text-gray-500">Phạm vi này chưa có tài khoản riêng{{ $this->categoryId ? ' — đang dùng tài khoản mặc định của đối tác (nếu có)' : '' }}.</p>
            @endif

            <p class="mb-2 text-sm font-medium">Chọn cách nhận tiền cho phạm vi này:</p>
            <div class="flex flex-wrap gap-3">
                <x-filament::button type="button" :color="$mode === 'auto' ? 'primary' : 'gray'" icon="heroicon-o-bolt" wire:click="setMode('auto')">Tự động (Pay2S, có OTP)</x-filament::button>
                <x-filament::button type="button" :color="$mode === 'manual' ? 'primary' : 'gray'" icon="heroicon-o-hand-raised" wire:click="setMode('manual')">Thủ công (tự xác nhận)</x-filament::button>
            </div>
            <p class="mt-2 text-xs text-gray-500">
                @if ($mode === 'auto')
                    Đơn được xác nhận tự động khi khách chuyển khoản. Cần liên kết ngân hàng với Pay2S (nhập thông tin ngân hàng rồi nhập OTP).
                @else
                    Khách chuyển khoản theo QR vào tài khoản của bạn; bạn tự đối chiếu và bấm xác nhận ở "Chờ xác nhận chuyển khoản". Chỉ cần thông tin ngân hàng cơ bản, không cần OTP.
                @endif
            </p>

            @if ($mode === 'auto')
                <div class="mt-4">
                    <x-filament::button color="gray" wire:click="check" wire:loading.attr="disabled" icon="heroicon-o-check-badge" :disabled="! $account || $account->isManual()">
                        Kiểm tra liên kết
                    </x-filament::button>
                </div>

                @if ($checks)
                    <ul class="mt-4 space-y-2 text-sm">
                        @foreach ($checks as $item)
                            <li class="flex gap-2">
                                <span class="font-semibold {{ $item['ok'] === true ? 'text-success-600' : ($item['ok'] === false ? 'text-danger-600' : 'text-gray-500') }}">
                                    {{ $item['ok'] === true ? '✓' : ($item['ok'] === false ? '✗' : '•') }}
                                </span>
                                <span><b>{{ $item['label'] }}:</b> {{ $item['message'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            @endif
        </x-filament::section>

        @if ($mode === 'manual')
            {{-- ── THỦ CÔNG: chỉ thông tin ngân hàng cơ bản ── --}}
            <x-filament::section heading="Thông tin ngân hàng nhận tiền (thủ công)" description="Chỉ cần ngân hàng, số tài khoản và tên chủ tài khoản.">
                @if ($manualBlocked)
                    <p class="mb-3 text-sm text-danger-600 dark:text-danger-400">{{ $manualBlocked }}</p>
                @endif

                <form wire:submit="saveManual" class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div>
                        <label class="text-sm font-medium">Ngân hàng</label>
                        <x-filament::input.wrapper :valid="! $errors->has('manual.bank_code')">
                            <x-filament::input.select wire:model="manual.bank_code">
                                <option value="">— Chọn ngân hàng —</option>
                                @foreach ($this->bankOptions() as $code => $name)
                                    <option value="{{ $code }}">{{ $name }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                        @error('manual.bank_code') <p class="mt-1 text-xs text-danger-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="text-sm font-medium">Số tài khoản</label>
                        <x-filament::input.wrapper :valid="! $errors->has('manual.account_number')"><x-filament::input type="text" wire:model="manual.account_number" autocomplete="off" /></x-filament::input.wrapper>
                        @error('manual.account_number') <p class="mt-1 text-xs text-danger-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="md:col-span-2">
                        <label class="text-sm font-medium">Tên chủ tài khoản</label>
                        <x-filament::input.wrapper :valid="! $errors->has('manual.account_holder')"><x-filament::input type="text" wire:model="manual.account_holder" autocomplete="off" /></x-filament::input.wrapper>
                        @error('manual.account_holder') <p class="mt-1 text-xs text-danger-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="md:col-span-2">
                        <x-filament::button type="submit" wire:loading.attr="disabled" icon="heroicon-o-check" :disabled="(bool) $manualBlocked">Lưu tài khoản (thủ công)</x-filament::button>
                    </div>
                </form>
            </x-filament::section>
        @else
            {{-- Hai cách liên kết tự động gom thành hai tab: đối tác chọn một (tab OTP chỉ có khi Pay2S đã bật API trực tiếp cho ứng dụng). --}}
            @if ($direct)
                <div class="flex flex-wrap gap-2">
                    <x-filament::button type="button" size="sm" :color="$tab === 'otp' ? 'primary' : 'gray'" icon="heroicon-o-device-phone-mobile" wire:click="setLinkTab('otp')">Nhập thông tin + OTP (ACB, MB Bank, BIDV)</x-filament::button>
                    <x-filament::button type="button" size="sm" :color="$tab === 'window' ? 'primary' : 'gray'" icon="heroicon-o-link" wire:click="setLinkTab('window')">Cửa sổ Pay2S (mọi ngân hàng)</x-filament::button>
                </div>
            @endif

            {{-- ── TỰ ĐỘNG 1: nhập thông tin ngân hàng + OTP ── --}}
            @if ($direct && $tab === 'otp')
            <x-filament::section heading="Liên kết ngân hàng bằng OTP (ACB, MB Bank, BIDV)" description="Nhập thông tin tài khoản, ngân hàng gửi mã OTP về số điện thoại đăng ký với ngân hàng, bạn nhập OTP ngay tại đây. Không cần mật khẩu ngân hàng.">
                @if ($apiBlocked)
                    <p class="mb-3 text-sm text-danger-600 dark:text-danger-400">{{ $apiBlocked }}</p>
                @endif

                @if (! $awaitingOtp)
                    <form wire:submit="submitBank" class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div>
                            <label class="text-sm font-medium">Ngân hàng</label>
                            <x-filament::input.wrapper>
                                <x-filament::input.select wire:model.live="bank.bank">
                                    @foreach ($openApi as $code => $item)
                                        <option value="{{ $code }}">{{ $item['label'] }}</option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </div>
                        <div>
                            <label class="text-sm font-medium">Loại tài khoản</label>
                            <x-filament::input.wrapper>
                                <x-filament::input.select wire:model.live="bank.account_type">
                                    <option value="personal">Cá nhân</option>
                                    @if ($cfg && in_array('business', $cfg['types'], true))
                                        <option value="business">Hộ kinh doanh / Doanh nghiệp</option>
                                    @endif
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </div>
                        <div>
                            <label class="text-sm font-medium">Số tài khoản</label>
                            <x-filament::input.wrapper :valid="! $errors->has('bank.account_number')"><x-filament::input type="text" wire:model="bank.account_number" autocomplete="off" /></x-filament::input.wrapper>
                            @error('bank.account_number') <p class="mt-1 text-xs text-danger-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="text-sm font-medium">Tên chủ tài khoản</label>
                            <x-filament::input.wrapper :valid="! $errors->has('bank.account_holder')"><x-filament::input type="text" wire:model="bank.account_holder" autocomplete="off" /></x-filament::input.wrapper>
                            @error('bank.account_holder') <p class="mt-1 text-xs text-danger-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="text-sm font-medium">Số điện thoại đăng ký với ngân hàng</label>
                            <x-filament::input.wrapper :valid="! $errors->has('bank.phone')"><x-filament::input type="text" wire:model="bank.phone" autocomplete="off" /></x-filament::input.wrapper>
                            @error('bank.phone') <p class="mt-1 text-xs text-danger-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="text-sm font-medium">Email</label>
                            <x-filament::input.wrapper :valid="! $errors->has('bank.email')"><x-filament::input type="email" wire:model="bank.email" autocomplete="off" /></x-filament::input.wrapper>
                            @error('bank.email') <p class="mt-1 text-xs text-danger-600">{{ $message }}</p> @enderror
                        </div>
                        @if ($cfg && $cfg['cccd'])
                            <div>
                                <label class="text-sm font-medium">CCCD / Mã số thuế</label>
                                <x-filament::input.wrapper :valid="! $errors->has('bank.cccd')"><x-filament::input type="text" wire:model="bank.cccd" autocomplete="off" /></x-filament::input.wrapper>
                                @error('bank.cccd') <p class="mt-1 text-xs text-danger-600">{{ $message }}</p> @enderror
                            </div>
                        @endif
                        @if ($cfg && $cfg['merchant'])
                            <div>
                                <label class="text-sm font-medium">Mã merchant BIDV (tuỳ chọn — để nhận số tài khoản ảo)</label>
                                <x-filament::input.wrapper><x-filament::input type="text" wire:model="bank.merchant_id" autocomplete="off" /></x-filament::input.wrapper>
                            </div>
                        @endif
                        @if ($cfg && $cfg['username_business'] && $bank['account_type'] === 'business')
                            <div>
                                <label class="text-sm font-medium">Tên đăng nhập ACB OneBiz</label>
                                <x-filament::input.wrapper :valid="! $errors->has('bank.username')"><x-filament::input type="text" wire:model="bank.username" autocomplete="off" /></x-filament::input.wrapper>
                                @error('bank.username') <p class="mt-1 text-xs text-danger-600">{{ $message }}</p> @enderror
                            </div>
                        @endif
                        <div class="md:col-span-2">
                            <x-filament::button type="submit" wire:loading.attr="disabled" icon="heroicon-o-paper-airplane" :disabled="(bool) $apiBlocked">Gửi thông tin và nhận OTP</x-filament::button>
                        </div>
                    </form>
                @else
                    <form wire:submit="confirmOtp" class="max-w-md space-y-3">
                        <p class="text-sm">Ngân hàng đã gửi mã OTP về số điện thoại <b>{{ $bank['phone'] }}</b>. Nhập mã vào ô bên dưới để hoàn tất liên kết tài khoản <b>{{ $bank['account_number'] }}</b>.</p>
                        <x-filament::input.wrapper :valid="! $errors->has('otp')"><x-filament::input type="text" inputmode="numeric" wire:model="otp" placeholder="Mã OTP" autocomplete="one-time-code" /></x-filament::input.wrapper>
                        @error('otp') <p class="text-xs text-danger-600">{{ $message }}</p> @enderror
                        <div class="flex flex-wrap gap-3">
                            <x-filament::button type="submit" wire:loading.attr="disabled" icon="heroicon-o-check">Xác nhận OTP</x-filament::button>
                            <x-filament::button type="button" color="gray" wire:click="cancelOtp">Nhập lại thông tin</x-filament::button>
                        </div>
                        <p class="text-xs text-gray-500">Chưa nhận được OTP hoặc hết hạn? Bấm "Nhập lại thông tin" rồi gửi lại để ngân hàng gửi mã mới.</p>
                    </form>
                @endif
            </x-filament::section>

            @endif

            {{-- ── TỰ ĐỘNG 2: Hosted Link (cửa sổ Pay2S) ── --}}
            @if ($tab === 'window')
            <x-filament::section :heading="'Liên kết qua cửa sổ Pay2S (ACB, MB Bank, BIDV, Vietcombank, VietinBank, Techcombank)'" description="Cửa sổ Pay2S hiện ra ngay trên trang này: nhập thông tin ngân hàng và OTP trực tiếp ở đó, 365 Home không nhận và không nhìn thấy.">
                @if ($apiBlocked)
                    <p class="mb-3 text-sm text-danger-600 dark:text-danger-400">{{ $apiBlocked }}</p>
                @endif

                <x-filament::button wire:click="startLink" wire:loading.attr="disabled" icon="heroicon-o-link" :disabled="(bool) $apiBlocked">
                    {{ $account && ! $account->isManual() ? 'Liên kết lại ngân hàng' : 'Liên kết ngân hàng' }}
                </x-filament::button>

                @if ($session)
                    <div class="mt-4 rounded-lg border border-gray-200 p-4 text-sm dark:border-gray-700">
                        @if (! $session->applied && $session->status === 'connected' && $session->error_code === 'ACCOUNT_TAKEN')
                            <p class="mb-2 font-medium text-warning-600 dark:text-warning-400">Pay2S đã liên kết ngân hàng xong nhưng chưa lưu được tài khoản vào hệ thống. {{ $session->error_message }}</p>
                            <x-filament::button type="button" icon="heroicon-o-arrow-path" wire:click="refreshSession">Hoàn tất lưu tài khoản</x-filament::button>
                        @elseif ($session->applied)
                            <p class="font-medium text-success-600 dark:text-success-400">Đã liên kết ngân hàng thành công. Hãy chuyển thử một khoản nhỏ đúng nội dung để chắc chắn đơn được xác nhận tự động.</p>
                        @elseif (in_array($session->status, \Modules\Payment\Entities\PartnerPay2sLinkSession::FAILED, true))
                            <p class="font-medium text-danger-600 dark:text-danger-400">
                                Liên kết chưa thành công ({{ $session->status }}).
                                @if ($session->error_message) {{ $session->error_message }} @endif
                                Bấm "Liên kết ngân hàng" để thử lại.
                            </p>
                        @else
                            <p class="mb-2">Đang chờ bạn hoàn tất trên cửa sổ Pay2S{{ $session->expires_at ? ' (phiên còn hiệu lực đến ' . $session->expires_at->format('H:i d/m') . ')' : '' }}. Trang này tự cập nhật khi xong.</p>
                            @if ($session->hosted_link_url)
                                <div class="flex flex-wrap gap-3">
                                    <x-filament::button type="button" icon="heroicon-o-link" x-on:click="open(@js($session->hosted_link_url), @js($session->session_id))">
                                        Mở lại cửa sổ liên kết
                                    </x-filament::button>
                                    <x-filament::button tag="a" color="gray" href="{{ $session->hosted_link_url }}" target="_blank" rel="noopener" icon="heroicon-o-arrow-top-right-on-square">
                                        Mở trong tab mới
                                    </x-filament::button>
                                </div>
                            @endif
                        @endif
                    </div>
                @endif
            </x-filament::section>

            @endif

            {{-- Pay2S chưa cấp token webhook cho tài khoản tự động: dán token tay --}}
            @if ($account && ! $account->isManual() && blank($account->webhook_token))
                <x-filament::section heading="Token webhook Pay2S" description="Tài khoản đã liên kết nhưng Pay2S chưa cấp token webhook. Dán token Pay2S sinh cho webhook 'Nhận tiền' của tài khoản này (URL: {{ \App\Support\Pay2SConfig::get('webhook_url') ?: route('webhook.pay2s') }}).">
                    <div class="mb-4">
                        <x-filament::button type="button" wire:click="provisionWebhook" wire:loading.attr="disabled" icon="heroicon-o-key">Lấy token tự động</x-filament::button>
                        <p class="mt-1 text-xs text-gray-500">Hệ thống tự tạo webhook ở Pay2S và lưu token, không cần chép tay. Nếu không được, dán token vào ô bên dưới.</p>
                    </div>
                    <form wire:submit="saveWebhookToken" class="max-w-md space-y-3">
                        <x-filament::input.wrapper :valid="! $errors->has('webhookToken')"><x-filament::input type="password" wire:model="webhookToken" autocomplete="new-password" placeholder="Token webhook" /></x-filament::input.wrapper>
                        @error('webhookToken') <p class="text-xs text-danger-600">{{ $message }}</p> @enderror
                        <x-filament::button type="submit" icon="heroicon-o-check">Lưu token</x-filament::button>
                    </form>
                </x-filament::section>
            @endif
        @endif
    </div>
</x-filament-panels::page>
