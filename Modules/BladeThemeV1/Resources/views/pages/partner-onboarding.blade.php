@extends('bladethemev1::layouts.master')

<x-bladethemev1::seo :seoData="$seoData" />

@section('content')
    @livewire('bladethemev1::header')
    @livewire('bladethemev1::drawer-menu')

    @php
        $docTypes = collect(\App\Models\PartnerLegalDocument::TYPES)->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values();
        // MiniHouse ĐĂNG KÝ DÙNG THỬ có bước giấy tờ (MINIHOUSE_TRIAL_DOCUMENTS_REQUIRED): đăng ký → nộp 3 giấy tờ → gửi duyệt → duyệt xong mới tặng dùng thử.
        $mhDocs = (bool) config('partner_flow.minihouse_trial_documents_required') && ! config('partner_flow.minihouse_contract_enabled');
        $provinceOptions = \App\Models\Province::query()->whereNotNull('code')->orderBy('name')->get(['code', 'name'])->map(fn ($p) => ['code' => $p->code, 'name' => $p->name])->values();
    @endphp

    {{-- Đăng ký hợp tác (Homestay / MiniHouse) — gọi API công khai /api/public/partner-onboarding. Mã hồ sơ lưu ở localStorage để quay lại làm tiếp. --}}
    <div class="bg-gray-50 px-4 py-10 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-3xl"
            x-data="partnerOnboarding(@js($initialType), @js($docTypes), @js($provinceOptions), @js(['homestay' => app(\App\Services\TermsService::class)->required('partner_homestay'), 'minihouse' => app(\App\Services\TermsService::class)->required('partner_minihouse')]))"
            x-init="init()">

            <div class="mb-6">
                <h1 class="text-3xl font-bold text-gray-900" x-text="minihouseMode ? 'Mua gói MiniHouse' : 'Đăng ký hợp tác với 365 HOME'">Đăng ký hợp tác với 365 HOME</h1>
                <p class="text-base text-gray-600 mt-2" x-show="!minihouseMode">
                    Gửi giấy tờ pháp lý để 365 HOME xem xét. Giấy tờ được duyệt, bạn sẽ nhận hợp đồng để ký trực tuyến và tài khoản quản trị qua email.
                </p>
                <p class="text-base text-gray-600 mt-2" x-show="minihouseMode" x-cloak>
                    Chọn gói, thanh toán và dùng ngay — không cần đăng ký đối tác hay ký hợp đồng. Tài khoản quản trị được gửi qua email sau khi thanh toán.
                </p>
            </div>

            {{-- Thanh bước: vòng tròn đánh số nằm ngang, nối bằng đường kẻ (xong = xanh + dấu tích, hiện tại = viền đậm). Dùng inline style để không phụ thuộc bản build Tailwind. --}}
            <div x-show="!minihouseMode">
            <ol class="mb-6" style="display:flex;align-items:flex-start;list-style:none;margin:0 0 24px;padding:0;">
                <template x-for="(label, i) in stepLabels" :key="i">
                    <li style="flex:1 1 0;min-width:0;position:relative;text-align:center;">
                        <span x-show="i > 0" aria-hidden="true"
                            style="position:absolute;top:17px;right:50%;width:100%;height:2px;"
                            :style="{ background: i <= step ? '#16a34a' : '#e5e7eb' }"></span>
                        <span style="position:relative;z-index:1;display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:9999px;font-size:14px;font-weight:600;border:2px solid;"
                            :style="i < step ? { background: '#16a34a', borderColor: '#16a34a', color: '#fff' } : (i === step ? { background: '#fff', borderColor: '#111827', color: '#111827' } : { background: '#fff', borderColor: '#e5e7eb', color: '#9ca3af' })">
                            <svg x-show="i < step" xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                            <span x-show="i >= step" x-text="i + 1"></span>
                        </span>
                        <span class="block" style="margin-top:6px;font-size:13px;line-height:1.25;padding:0 2px;"
                            :style="i === step ? { color: '#111827', fontWeight: '600' } : (i < step ? { color: '#15803d' } : { color: '#9ca3af' })" x-text="label"></span>
                    </li>
                </template>
            </ol>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 sm:p-8">
                <p x-show="message" x-text="message" class="mb-5 rounded-lg px-4 py-3 text-sm"
                    :class="messageOk ? 'bg-green-50 border border-green-200 text-green-700' : 'bg-red-50 border border-red-200 text-red-700'"></p>

                {{-- B1. Đăng ký --}}
                <form x-show="step === 0 && !purchase" @submit.prevent="register" class="space-y-5">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Loại hình hợp tác <span class="text-red-500">*</span></label>
                        <div class="grid sm:grid-cols-2 gap-3">
                            <label class="rounded-lg border p-4 cursor-pointer" :class="reg.partner_type === 'homestay' ? 'border-gray-900 ring-1 ring-gray-900' : 'border-gray-300'">
                                <input type="radio" value="homestay" x-model="reg.partner_type" class="sr-only">
                                <div class="font-semibold text-gray-900">Homestay</div>
                                <div class="text-sm text-gray-600 mt-1">Cho thuê lưu trú ngắn ngày. Hoa hồng trên mỗi đơn theo thoả thuận.</div>
                            </label>
                            <label class="rounded-lg border p-4 cursor-pointer" :class="reg.partner_type === 'minihouse' ? 'border-gray-900 ring-1 ring-gray-900' : 'border-gray-300'">
                                <input type="radio" value="minihouse" x-model="reg.partner_type" class="sr-only">
                                <div class="font-semibold text-gray-900">MiniHouse</div>
                                <div class="text-sm text-gray-600 mt-1">Nhà trọ, căn hộ dịch vụ cho thuê dài hạn. <strong>Không thu hoa hồng</strong> — chỉ cần <strong>mua gói để dùng ngay</strong>, không cần ký hợp đồng.</div>
                            </label>
                        </div>
                        <p class="text-xs text-red-600 mt-1" x-show="errors.partner_type" x-text="err('partner_type')"></p>
                    </div>
                    <div class="grid sm:grid-cols-2 gap-5">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Họ và tên <span class="text-red-500">*</span></label>
                            <input type="text" x-model="reg.full_name" maxlength="255" required class="w-full rounded-lg border border-gray-300 px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-gray-400">
                            <p class="text-xs text-red-600 mt-1" x-show="errors.full_name" x-text="err('full_name')"></p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Số điện thoại <span class="text-red-500">*</span></label>
                            <input type="tel" x-model="reg.phone" required placeholder="0912345678" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-gray-400">
                            <p class="text-xs text-red-600 mt-1" x-show="errors.phone" x-text="err('phone')"></p>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Email <span class="text-red-500">*</span></label>
                        <input type="email" x-model="reg.email" maxlength="255" required class="w-full rounded-lg border border-gray-300 px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-gray-400">
                        <p class="text-xs text-gray-500 mt-1">Nhận link ký hợp đồng, mã OTP và tài khoản quản trị.</p>
                        <p class="text-xs text-red-600 mt-1" x-show="errors.email" x-text="err('email')"></p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Tên cơ sở kinh doanh <span class="text-red-500">*</span></label>
                        <input type="text" x-model="reg.business_name" maxlength="255" required placeholder="Homestay / nhà trọ / toà nhà" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-gray-400">
                        <p class="text-xs text-red-600 mt-1" x-show="errors.business_name" x-text="err('business_name')"></p>
                    </div>
                    {{-- Địa chỉ có cấu trúc: tỉnh/thành → phường/xã → số nhà, đường (+ căn hộ, toà nhà, mã bưu điện nếu có). Server ghép thành địa chỉ đầy đủ. --}}
                    <fieldset class="space-y-3">
                        <legend class="block text-sm font-medium text-gray-700 mb-1">Địa chỉ cơ sở kinh doanh <span class="text-red-500">*</span></legend>
                        {{-- Ô tìm kiếm gợi ý (API /api/v2/address/suggest): chọn gợi ý sẽ điền sẵn Tỉnh/Thành phố + Phường/Xã; "Tự nhập địa chỉ" để tự chọn bên dưới. --}}
                        <div class="relative" @click.outside="suggestOpen = false">
                            <input type="text" x-model="addrQuery" @input.debounce.300ms="suggest()" @focus="suggestOpen = true" autocomplete="off"
                                placeholder="Tìm địa chỉ của bạn (vd: Cần Thơ, Ninh Kiều)" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-gray-400">
                            <ul x-show="suggestOpen && (suggestions.length || addrQuery.trim())" x-cloak
                                class="absolute z-20 mt-1 w-full max-h-72 overflow-auto rounded-lg border border-gray-200 bg-white shadow-lg divide-y divide-gray-100">
                                <template x-for="(s, i) in suggestions" :key="i">
                                    <li>
                                        <button type="button" class="w-full text-left px-3 py-2.5 hover:bg-gray-50" @click="pickSuggestion(s)">
                                            <span class="block text-sm font-medium text-gray-900" x-text="s.label"></span>
                                            <span class="block text-xs text-gray-500" x-text="s.description"></span>
                                        </button>
                                    </li>
                                </template>
                                <li x-show="suggestLoading"><span class="block px-3 py-2.5 text-sm text-gray-500">Đang tìm...</span></li>
                                <li>
                                    <button type="button" class="w-full text-left px-3 py-2.5 text-sm text-gray-700 hover:bg-gray-50" @click="manualAddress()">✎ Tự nhập địa chỉ</button>
                                </li>
                            </ul>
                        </div>
                        <div class="grid sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Quốc gia/khu vực</label>
                                <input type="text" value="Việt Nam - VN" disabled class="w-full rounded-lg border border-gray-200 bg-gray-50 px-3 py-2.5 text-gray-500">
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Tỉnh/Thành phố <span class="text-red-500">*</span></label>
                                <select x-ref="provinceSelect" x-model="reg.address_province_code" @change="loadWards()" required class="w-full rounded-lg border border-gray-300 px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-gray-400">
                                    <option value="">— Chọn tỉnh/thành phố —</option>
                                    <template x-for="p in provinces" :key="p.code"><option :value="String(p.code)" x-text="p.name"></option></template>
                                </select>
                                <p class="text-xs text-red-600 mt-1" x-show="errors.address_province_code" x-text="err('address_province_code')"></p>
                            </div>
                            <div class="sm:col-span-2">
                                <label class="block text-xs text-gray-500 mb-1">Phường/Xã <span class="text-red-500">*</span></label>
                                <select x-model="reg.address_ward_code" :disabled="!reg.address_province_code || loadingWards" required class="w-full rounded-lg border border-gray-300 px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-gray-400">
                                    <option value="" x-text="!reg.address_province_code ? 'Chọn tỉnh/thành phố trước' : (loadingWards ? 'Đang tải...' : '— Chọn phường/xã —')"></option>
                                    <template x-for="w in wards" :key="w.code"><option :value="String(w.code)" x-text="w.name"></option></template>
                                </select>
                                <p class="text-xs text-red-600 mt-1" x-show="errors.address_ward_code" x-text="err('address_ward_code')"></p>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">Số nhà, tên đường/phố <span class="text-red-500">*</span></label>
                            <input type="text" x-model="reg.address_street" maxlength="255" required placeholder="Vd: 12 Lê Lợi" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-gray-400">
                            <p class="text-xs text-red-600 mt-1" x-show="errors.address_street" x-text="err('address_street')"></p>
                        </div>
                        <div class="grid sm:grid-cols-3 gap-3">
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Căn hộ, tầng (nếu có)</label>
                                <input type="text" x-model="reg.address_unit" maxlength="100" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-gray-400">
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Tên toà nhà (nếu có)</label>
                                <input type="text" x-model="reg.address_building" maxlength="150" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-gray-400">
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Mã bưu điện (nếu có)</label>
                                <input type="text" x-model="reg.postal_code" inputmode="numeric" maxlength="6" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-gray-400">
                                <p class="text-xs text-red-600 mt-1" x-show="errors.postal_code" x-text="err('postal_code')"></p>
                            </div>
                        </div>
                        <p class="text-xs text-red-600" x-show="errors.address" x-text="err('address')"></p>
                    </fieldset>
                    {{-- MiniHouse: chỉ mua gói rồi dùng — chọn gói + số tháng, không đăng ký đối tác/ký hợp đồng. --}}
                    {{-- Lần đầu được TẶNG dùng thử: không chọn gói, không thanh toán lúc đăng ký — chọn gói/thanh toán sau khi đăng nhập. --}}
                    <div x-show="minihouseMode && trialMonths > 0" x-cloak class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 space-y-1">
                        <p class="font-semibold">Tặng dùng thử <span x-text="trialMonths"></span> tháng cho lần đăng ký đầu tiên.</p>
                        <p>Bạn không cần chọn gói hay thanh toán lúc đăng ký. @if ($mhDocs) Bước tiếp theo là nộp 3 giấy tờ pháp lý (giấy phép kinh doanh, an ninh trật tự, phòng cháy chữa cháy); sau khi 365 Home duyệt giấy tờ, @else Sau khi 365 Home duyệt, @endif tài khoản và mật khẩu sẽ gửi về email; hết thời gian dùng thử, đăng nhập để chọn gói và thanh toán.</p>
                    </div>
                    <div x-show="minihouseMode && trialMonths === 0" x-cloak class="space-y-4">
                        <div class="text-sm font-medium text-gray-700">Gói dịch vụ MiniHouse <span class="text-red-500">*</span></div>
                        <p class="text-sm text-gray-500" x-show="!plans.length">Chưa có gói đang bán. Vui lòng liên hệ 365 Home.</p>
                        <template x-for="p in plans" :key="p.id">
                            <label class="block rounded-lg border p-4 cursor-pointer" :class="Number(planId) === p.id ? 'border-gray-900 ring-1 ring-gray-900' : 'border-gray-300'">
                                <input type="radio" class="sr-only" :value="p.id" x-model.number="planId">
                                <div class="flex flex-wrap items-baseline justify-between gap-2">
                                    <span class="font-semibold text-gray-900" x-text="p.name"></span>
                                    <span class="text-sm text-gray-600" x-text="vnd(p.price_vnd) + '/tháng'"></span>
                                </div>
                                <p class="text-sm text-gray-600 mt-1" x-show="p.description" x-text="p.description"></p>
                            </label>
                        </template>
                        <div x-show="selectedPlan">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Thời hạn mua <span class="text-red-500">*</span></label>
                            <select x-model.number="periods" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-gray-400">
                                <template x-for="o in (selectedPlan ? selectedPlan.periods : [])" :key="o.periods">
                                    <option :value="o.periods" x-text="o.months + ' tháng — ' + vnd(o.amount_vnd) + (o.discount_percent ? ' (giảm ' + o.discount_percent + '%)' : '')"></option>
                                </template>
                            </select>
                            <p class="text-xs text-red-600 mt-1" x-show="errors.periods || errors.plan_id" x-text="err('periods') || err('plan_id')"></p>
                            <p class="text-sm text-gray-700 mt-2">Thành tiền: <strong x-text="vnd(selectedPeriod ? selectedPeriod.amount_vnd : 0)"></strong></p>
                        </div>
                    </div>
                    <div x-show="!minihouseMode">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Ghi chú</label>
                        <textarea x-model="reg.note" rows="3" maxlength="2000" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-gray-400"></textarea>
                    </div>
                    {{-- Đồng ý Điều khoản dịch vụ (bắt buộc) — lưu lịch sử đồng ý kèm phiên bản Điều khoản đang hiển thị --}}
                    <div class="space-y-1" x-show="termsNeeded" x-cloak>
                        <label class="flex items-start gap-2 text-sm text-gray-700">
                            <input type="checkbox" x-model="reg.accept_terms" class="mt-1">
                            <span x-show="reg.partner_type !== 'minihouse'">Tôi đã đọc và đồng ý với <button type="button" class="underline font-medium text-gray-900" @click="termsOpen = true">Điều khoản dịch vụ</button><template x-if="terms"><span> (phiên bản <span x-text="terms.version"></span>)</span></template>.</span>
                            {{-- MiniHouse: câu xác nhận đầy đủ theo Điều khoản (màn rộng); bản ngắn cho màn hẹp --}}
                            <span x-show="reg.partner_type === 'minihouse'" x-cloak>
                                <span class="hidden sm:inline">Tôi đã đọc và đồng ý với <button type="button" class="underline font-medium text-gray-900" @click="termsOpen = true">Điều khoản dịch vụ MiniHouse</button><template x-if="terms"><span> (phiên bản <span x-text="terms.version"></span>)</span></template>, bao gồm: phí đã thanh toán không hoàn lại, tài khoản bị khoá khi hết hạn gói, và tôi chịu trách nhiệm về dữ liệu cá nhân của khách thuê do tôi nhập.</span>
                                <span class="sm:hidden">Tôi đã đọc và đồng ý với <button type="button" class="underline font-medium text-gray-900" @click="termsOpen = true">Điều khoản dịch vụ</button> MiniHouse.</span>
                            </span>
                        </label>
                        <p class="text-xs text-red-600" x-show="errors.accept_terms || errors.terms_version_id" x-text="err('accept_terms') || err('terms_version_id')"></p>
                    </div>
                    <div x-show="termsNeeded && termsOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @keydown.escape.window="termsOpen = false">
                        <div class="bg-white rounded-xl shadow-xl w-full max-w-2xl max-h-[85vh] flex flex-col" @click.outside="termsOpen = false">
                            <div class="flex items-start justify-between gap-4 border-b border-gray-100 px-5 py-4">
                                <div>
                                    <h3 class="font-semibold text-gray-900" x-text="terms ? terms.title : 'Điều khoản dịch vụ'"></h3>
                                    <p class="text-xs text-gray-500 mt-0.5" x-show="terms">Phiên bản <span x-text="terms && terms.version"></span></p>
                                </div>
                                <button type="button" class="text-gray-500 hover:text-gray-800 text-xl leading-none" @click="termsOpen = false">&times;</button>
                            </div>
                            <div class="px-5 py-4 overflow-y-auto text-sm text-gray-700 whitespace-pre-line" x-text="terms ? terms.content : 'Chưa tải được Điều khoản. Vui lòng thử lại.'"></div>
                            <div class="border-t border-gray-100 px-5 py-3 text-right">
                                <button type="button" class="rounded-lg bg-gray-900 text-white font-semibold px-5 py-2 hover:bg-gray-800" @click="reg.accept_terms = true; termsOpen = false">Đã đọc và đồng ý</button>
                            </div>
                        </div>
                    </div>
                    <button type="submit" :disabled="loading || (termsNeeded && !reg.accept_terms)" class="w-full rounded-lg bg-gray-900 text-white font-semibold py-3 hover:bg-gray-800 disabled:opacity-60 disabled:cursor-not-allowed" x-text="loading ? 'Đang gửi...' : (minihouseMode ? (trialMonths > 0 ? 'Đăng ký MiniHouse' : 'Mua gói & thanh toán') : 'Tiếp tục')"></button>

                    {{-- Mất mã hồ sơ (đổi trình duyệt/máy): nhập SĐT + email đã đăng ký → nhận link tiếp tục qua email --}}
                    <div class="border-t border-gray-100 pt-4 text-center text-sm">
                        <button type="button" class="text-gray-600 underline" @click="recoverOpen = !recoverOpen">Đã đăng ký trước đó? Lấy lại hồ sơ</button>
                        <div x-show="recoverOpen" x-cloak class="mt-3 grid sm:grid-cols-2 gap-3 text-left">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Số điện thoại đã đăng ký</label>
                                <input type="tel" x-model="rec.phone" class="w-full rounded-lg border border-gray-300 px-3 py-2.5">
                                <p class="text-xs text-red-600 mt-1" x-show="errors.phone" x-text="err('phone')"></p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Email đã đăng ký</label>
                                <input type="email" x-model="rec.email" class="w-full rounded-lg border border-gray-300 px-3 py-2.5">
                                <p class="text-xs text-red-600 mt-1" x-show="errors.email" x-text="err('email')"></p>
                            </div>
                            <div class="sm:col-span-2 text-center">
                                <button type="button" :disabled="loading" @click="recover()" class="rounded-lg border border-gray-900 text-gray-900 font-semibold px-5 py-2 hover:bg-gray-50 disabled:opacity-60">Gửi liên kết về email</button>
                            </div>
                        </div>
                    </div>
                </form>

                {{-- MiniHouse: thanh toán gói (QR/link PayOS) → tự kích hoạt, tài khoản đăng nhập gửi qua email. --}}
                <div x-show="purchase" x-cloak class="space-y-5 text-center">
                    <template x-if="purchase && purchase.stage === 'active'">
                        <div class="space-y-3">
                            <div class="text-5xl">✅</div>
                            <h2 class="text-xl font-bold text-gray-900">Đã kích hoạt gói MiniHouse</h2>
                            <p class="text-gray-600">Tài khoản đăng nhập đã được gửi về email <strong x-text="purchase.account.email"></strong>. Gói dùng đến <strong x-text="purchase.subscription && purchase.subscription.expires_at ? new Date(purchase.subscription.expires_at).toLocaleDateString('vi-VN') : ''"></strong>.</p>
                            <a :href="purchase.account.login_url" class="inline-block rounded-lg bg-gray-900 text-white font-semibold px-6 py-3 hover:bg-gray-800">Đăng nhập trang quản trị</a>
                        </div>
                    </template>
                    <template x-if="purchase && purchase.stage === 'trial'">
                        <div class="space-y-3">
                            <div class="text-5xl">🎉</div>
                            <h2 class="text-xl font-bold text-gray-900">Đăng ký đã được duyệt — đang dùng thử</h2>
                            <p class="text-gray-600">Tài khoản đăng nhập đã được gửi về email <strong x-text="purchase.account.email"></strong>. Dùng thử đến <strong x-text="purchase.subscription && purchase.subscription.expires_at ? new Date(purchase.subscription.expires_at).toLocaleDateString('vi-VN') : ''"></strong>; thanh toán gói trước ngày này để tiếp tục.</p>
                            <a :href="purchase.account.login_url" class="inline-block rounded-lg bg-gray-900 text-white font-semibold px-6 py-3 hover:bg-gray-800">Đăng nhập trang quản trị</a>
                        </div>
                    </template>
                    <template x-if="purchase && purchase.stage === 'pending_approval'">
                        <div class="space-y-2">
                            <div class="text-5xl">⏳</div>
                            <h2 class="text-xl font-bold text-gray-900">Đã xác nhận đăng ký — chờ 365 Home duyệt</h2>
                            <p class="text-gray-600">Sau khi được duyệt, tài khoản dùng thử và mật khẩu sẽ gửi về email của bạn. Bạn chưa cần thanh toán lúc này.</p>
                        </div>
                    </template>
                    {{-- Đăng ký dùng thử có bước giấy tờ: chờ duyệt / bị từ chối. Nộp + sửa giấy tờ dùng khối "Giấy tờ pháp lý" bên dưới. --}}
                    <template x-if="purchase && purchase.stage === 'pending_review'">
                        <div class="space-y-2">
                            <div class="text-5xl">⏳</div>
                            <h2 class="text-xl font-bold text-gray-900">Giấy tờ đang chờ 365 HOME duyệt</h2>
                            <p class="text-gray-600">Khi giấy tờ được duyệt, tài khoản dùng thử và mật khẩu sẽ được gửi về email <strong x-text="purchase.partner && purchase.partner.email"></strong>. Trang này tự cập nhật.</p>
                            <p class="text-sm text-gray-500">Cần sửa lại giấy tờ? Rút hồ sơ về bản nháp, chỉnh sửa rồi gửi duyệt lại.</p>
                            <button type="button" class="rounded-lg border border-gray-300 px-5 py-2.5 hover:bg-gray-50 disabled:opacity-60" :disabled="loading" @click="withdraw()">Rút hồ sơ để chỉnh sửa</button>
                        </div>
                    </template>
                    <template x-if="purchase && purchase.stage === 'rejected'">
                        <div class="space-y-2">
                            <div class="text-5xl">⚠️</div>
                            <h2 class="text-xl font-bold text-gray-900">Hồ sơ chưa được chấp thuận</h2>
                            <p class="text-gray-600" x-text="(purchase.dossier && purchase.dossier.note) || 'Vui lòng liên hệ 365 HOME để biết thêm chi tiết.'"></p>
                        </div>
                    </template>
                    <template x-if="purchase && purchase.stage === 'paid'">
                        <div class="space-y-2">
                            <div class="text-5xl">⏳</div>
                            <h2 class="text-xl font-bold text-gray-900">Đã nhận thanh toán</h2>
                            <p class="text-gray-600">Hệ thống đang tạo tài khoản và gửi email đăng nhập, vui lòng chờ trong giây lát...</p>
                        </div>
                    </template>
                    <template x-if="purchase && ['cancelled','expired'].includes(purchase.stage)">
                        <div class="space-y-3">
                            <h2 class="text-xl font-bold text-gray-900" x-text="purchase.stage === 'expired' ? 'Đơn thanh toán đã hết hạn' : 'Đơn thanh toán đã huỷ'"></h2>
                            <button type="button" class="rounded-lg bg-gray-900 text-white font-semibold px-6 py-3 hover:bg-gray-800" @click="resetPurchase()">Tạo đơn mới</button>
                        </div>
                    </template>
                    <template x-if="purchase && purchase.stage === 'pending_payment' && purchase.payment">
                        <div class="space-y-4">
                            <h2 class="text-xl font-bold text-gray-900">Thanh toán gói <span x-text="purchase.payment.plan ? purchase.payment.plan.name : ''"></span></h2>
                            <p class="text-gray-600"><span x-text="purchase.payment.months"></span> tháng — <strong x-text="vnd(purchase.payment.amount_vnd)"></strong></p>
                            <template x-if="purchase.payment.payment && purchase.payment.payment.account && purchase.payment.payment.account.account_number">
                                <div class="space-y-3">
                                    <img :src="vietQr(purchase.payment)" alt="QR thanh toán" class="mx-auto rounded-lg border border-gray-200" style="width:240px;max-width:100%;">
                                    <div class="text-sm text-gray-700 space-y-1">
                                        <div>Tên tài khoản: <strong x-text="purchase.payment.payment.account.account_name"></strong></div>
                                        <div>Số tài khoản: <strong x-text="purchase.payment.payment.account.account_number"></strong></div>
                                        <div>Nội dung chuyển khoản: <strong x-text="purchase.payment.transaction_code"></strong></div>
                                    </div>
                                </div>
                            </template>
                            <a x-show="purchase.payment.payment && purchase.payment.payment.checkout_url" :href="purchase.payment.payment && purchase.payment.payment.checkout_url" target="_blank" rel="noopener" class="inline-block rounded-lg bg-gray-900 text-white font-semibold px-6 py-3 hover:bg-gray-800">Mở trang thanh toán</a>
                            <p class="text-sm text-gray-500" x-show="!(purchase.payment.payment && (purchase.payment.payment.checkout_url || purchase.payment.payment.account.account_number))">Chuyển khoản với nội dung <strong x-text="purchase.payment.transaction_code"></strong>; 365 Home sẽ xác nhận và gửi tài khoản qua email.</p>
                            <p class="text-sm text-gray-500">Sau khi thanh toán, trang này tự cập nhật và tài khoản đăng nhập được gửi về email của bạn.</p>
                        </div>
                    </template>
                </div>

                {{-- B2. Giấy tờ pháp lý --}}
                <div x-show="step === 1 || mhDocsStage" x-cloak class="space-y-5">
                    <div>
                        <h2 class="text-lg font-bold text-gray-900">Giấy tờ pháp lý</h2>
                        <p class="text-sm text-gray-600 mt-1">Tệp PDF hoặc ảnh (jpg, png, webp), tối đa 10 MB. Loại giấy tờ trùng với danh mục 365 HOME dùng khi duyệt hồ sơ.</p>
                    </div>
                    {{-- Giấy tờ BẮT BUỘC khi đăng ký: khách phải gửi đủ trước khi sang bước tiếp theo. --}}
                    <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-gray-800 space-y-2">
                        <p class="font-semibold text-red-700">BẮT BUỘC gửi đủ các hồ sơ, tài liệu sau (tệp PDF hoặc ảnh) thì mới gửi duyệt được:</p>
                        <template x-for="r in (status ? status.required_documents : [])" :key="r.type">
                            <div class="flex items-center gap-2">
                                <span class="inline-flex items-center justify-center rounded-full text-white shrink-0" style="width:18px;height:18px;font-size:12px;"
                                    :style="r.uploaded ? 'background:#16a34a;' : 'background:#dc2626;'" x-text="r.uploaded ? '✓' : '!'"></span>
                                <span><strong x-text="r.label"></strong> — <span :class="r.uploaded ? 'text-green-700' : 'text-red-600'" x-text="r.uploaded ? 'đã gửi' : 'chưa gửi — bắt buộc'"></span></span>
                                <button type="button" class="ml-auto text-gray-700 underline shrink-0" x-show="!r.uploaded && editable" @click="doc.type = r.type; $refs.docFile && $refs.docFile.focus()">Chọn loại này</button>
                            </div>
                        </template>
                        <ul class="list-disc pl-5 text-gray-700" x-show="!status">
                            <li>Giấy phép kinh doanh</li><li>Giấy chứng nhận an ninh, trật tự</li><li>Hồ sơ phòng cháy chữa cháy</li>
                        </ul>
                        <p class="text-gray-600">Giấy tờ cần còn hiệu lực (nếu có ngày hết hạn). Chọn đúng <strong>Loại giấy tờ</strong> khi tải lên để 365 HOME duyệt nhanh. Hồ sơ chỉ được phê duyệt khi mọi giấy tờ bắt buộc đã được duyệt.</p>
                        <p class="text-gray-500">Giấy tờ khác (đăng ký thuế, giấy phép/công nhận cơ sở lưu trú, giấy uỷ quyền người ký...) không bắt buộc nhưng nên nộp.</p>
                        <p class="text-gray-500" x-show="!purchase && (status ? status.partner_type : reg.partner_type) === 'minihouse'">MiniHouse: giấy tờ từng toà nhà (PCCC, an ninh trật tự, sở hữu/quyền khai thác) sẽ bổ sung sau khi hồ sơ được duyệt và tạo toà nhà.</p>
                    </div>
                    <ul class="divide-y divide-gray-100 rounded-lg border border-gray-200" x-show="status && status.documents.length">
                        <template x-for="d in (status ? status.documents : [])" :key="d.id">
                            <li class="flex items-center justify-between gap-3 px-4 py-3 text-sm">
                                <div>
                                    <div class="font-medium text-gray-900" x-text="d.type_label + (d.name ? ' — ' + d.name : '')"></div>
                                    <div class="text-gray-500" x-text="[d.document_number, d.file_name, d.status_label].filter(Boolean).join(' · ')"></div>
                                    <div class="text-red-600" x-show="d.review_note" x-text="'Lý do: ' + d.review_note"></div>
                                </div>
                                <button type="button" class="text-red-600 hover:underline shrink-0" x-show="['draft','changes_requested','rejected'].includes(d.status) && editable" @click="removeDoc(d.id)">Xoá</button>
                            </li>
                        </template>
                    </ul>
                    <form @submit.prevent="uploadDoc" class="space-y-4 rounded-lg border border-dashed border-gray-300 p-4" x-show="editable">
                        <div class="grid sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Loại giấy tờ <span class="text-red-500">*</span></label>
                                <select x-model="doc.type" class="w-full rounded-lg border border-gray-300 px-3 py-2.5">
                                    <template x-for="t in allowedDocTypes()" :key="t.value"><option :value="t.value" x-text="t.label"></option></template>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Số giấy tờ</label>
                                <input type="text" x-model="doc.document_number" maxlength="100" class="w-full rounded-lg border border-gray-300 px-3 py-2.5">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Nơi cấp</label>
                                <input type="text" x-model="doc.issuer" maxlength="255" class="w-full rounded-lg border border-gray-300 px-3 py-2.5">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Ngày cấp</label>
                                <input type="date" x-model="doc.issued_at" class="w-full rounded-lg border border-gray-300 px-3 py-2.5">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Ngày hết hạn</label>
                                <input type="date" x-model="doc.expires_at" :min="doc.issued_at" class="w-full rounded-lg border border-gray-300 px-3 py-2.5">
                                <p class="text-xs text-red-600 mt-1" x-show="errors.expires_at" x-text="err('expires_at')"></p>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Tên giấy tờ <span class="text-gray-400 font-normal" x-show="doc.type !== 'other'">(không bắt buộc)</span></label>
                            <input type="text" x-model="doc.name" maxlength="255" class="w-full rounded-lg border border-gray-300 px-3 py-2.5">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Tệp <span class="text-red-500">*</span></label>
                            <input type="file" x-ref="docFile" accept=".pdf,.jpg,.jpeg,.png,.webp" class="w-full text-sm">
                            <p class="text-xs text-red-600 mt-1" x-show="errors.file || errors.type" x-text="err('file') || err('type')"></p>
                        </div>
                        <button type="submit" :disabled="loading" class="rounded-lg border border-gray-900 text-gray-900 font-semibold px-4 py-2 hover:bg-gray-50 disabled:opacity-60" x-text="loading ? 'Đang tải lên...' : 'Tải lên giấy tờ'"></button>
                    </form>
                    <div class="flex justify-end">
                        <button type="button" class="rounded-lg bg-gray-900 text-white font-semibold px-6 py-3 hover:bg-gray-800 disabled:opacity-60"
                            x-show="!purchase" :disabled="!status || !status.steps.documents_uploaded" @click="go(2)">Tiếp tục</button>
                        {{-- MiniHouse đăng ký dùng thử: không có bước thông tin hợp đồng — đủ 3 giấy tờ bắt buộc là gửi duyệt luôn. --}}
                        <button type="button" class="rounded-lg bg-gray-900 text-white font-semibold px-6 py-3 hover:bg-gray-800 disabled:opacity-60"
                            x-show="purchase" x-cloak :disabled="loading || !status || !status.steps.documents_uploaded" @click="submitDossier()" x-text="loading ? 'Đang gửi...' : 'Gửi giấy tờ chờ duyệt'"></button>
                    </div>
                </div>

                {{-- B3. Thông tin ký hợp đồng --}}
                <form x-show="step === 2" @submit.prevent="saveInfo" class="space-y-5">
                    <h2 class="text-lg font-bold text-gray-900">Thông tin ký hợp đồng</h2>
                    <div class="grid sm:grid-cols-2 gap-5">
                        <template x-for="f in infoFields" :key="f.key">
                            <div :class="f.full ? 'sm:col-span-2' : ''">
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    <span x-text="f.label"></span> <span class="text-red-500" x-show="f.required">*</span>
                                </label>
                                <input :type="f.type || 'text'" x-model="info[f.key]" :required="f.required === true" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-gray-400">
                                <p class="text-xs text-red-600 mt-1" x-show="errors[f.key]" x-text="err(f.key)"></p>
                            </div>
                        </template>
                    </div>
                    <div class="flex justify-between gap-3">
                        <button type="button" class="rounded-lg border border-gray-300 px-5 py-3" @click="go(1)">Quay lại</button>
                        <button type="submit" :disabled="loading" class="rounded-lg bg-gray-900 text-white font-semibold px-6 py-3 hover:bg-gray-800 disabled:opacity-60" x-text="loading ? 'Đang lưu...' : 'Lưu & tiếp tục'"></button>
                    </div>
                </form>

                {{-- B4. Gửi hồ sơ cho 365 HOME duyệt giấy tờ --}}
                <div x-show="step === 3" class="space-y-5 text-center">
                    <template x-if="status && ['ready_to_submit', 'documents_uploaded', 'registered'].includes(status.stage)">
                        <div class="space-y-4">
                            <div class="text-5xl">📨</div>
                            <h2 class="text-xl font-bold text-gray-900">Gửi hồ sơ cho 365 HOME</h2>
                            <p class="text-gray-600">365 HOME sẽ xem và duyệt giấy tờ pháp lý của bạn. Nếu giấy tờ hợp lệ, hợp đồng hợp tác sẽ được gửi về email để bạn ký. Sau khi gửi, hồ sơ không chỉnh sửa được trong lúc chờ duyệt.</p>
                            <div class="flex justify-center gap-3">
                                <button type="button" class="rounded-lg border border-gray-300 px-5 py-3" @click="go(2)">Sửa thông tin</button>
                                <button type="button" :disabled="loading" class="rounded-lg bg-gray-900 text-white font-semibold px-6 py-3 hover:bg-gray-800 disabled:opacity-60" @click="submitDossier()" x-text="loading ? 'Đang gửi...' : 'Gửi hồ sơ chờ duyệt'"></button>
                            </div>
                        </div>
                    </template>
                    <template x-if="status && status.stage === 'pending_review'">
                        <div class="space-y-2">
                            <div class="text-5xl">⏳</div>
                            <h2 class="text-xl font-bold text-gray-900">Hồ sơ đang chờ 365 HOME duyệt</h2>
                            <p class="text-gray-600">Khi giấy tờ được duyệt, hợp đồng hợp tác sẽ được gửi về email <strong x-text="status.partner.email"></strong> và hiện tại trang này để bạn ký.</p>
                            <p class="text-sm text-gray-500">Cần sửa lại giấy tờ hoặc thông tin? Rút hồ sơ về bản nháp, chỉnh sửa rồi gửi duyệt lại.</p>
                            <button type="button" class="rounded-lg border border-gray-300 px-5 py-2.5 hover:bg-gray-50 disabled:opacity-60" :disabled="loading" @click="withdraw()">Rút hồ sơ để chỉnh sửa</button>
                        </div>
                    </template>
                    <template x-if="status && status.stage === 'changes_requested'">
                        <div class="space-y-3 text-left">
                            <div class="text-center text-5xl">✏️</div>
                            <h2 class="text-center text-xl font-bold text-gray-900">365 HOME yêu cầu bổ sung giấy tờ</h2>
                            <ul class="divide-y divide-gray-100 rounded-lg border border-red-200 bg-red-50 text-sm">
                                <template x-for="d in status.documents.filter((x) => ['changes_requested', 'rejected'].includes(x.status))" :key="d.id">
                                    <li class="px-4 py-3"><strong x-text="d.type_label"></strong>: <span x-text="d.review_note || 'Cần bổ sung'"></span></li>
                                </template>
                            </ul>
                            <div class="text-center"><button type="button" class="rounded-lg bg-gray-900 text-white font-semibold px-6 py-3 hover:bg-gray-800" @click="go(1)">Nộp lại giấy tờ</button></div>
                        </div>
                    </template>
                    <template x-if="status && status.stage === 'approved'">
                        <div class="space-y-2">
                            <div class="text-5xl">✅</div>
                            <h2 class="text-xl font-bold text-gray-900">Giấy tờ đã được duyệt</h2>
                            <p class="text-gray-600">Hợp đồng đang được chuẩn bị. Vui lòng kiểm tra email hoặc tải lại trang sau ít phút.</p>
                        </div>
                    </template>
                    <template x-if="status && status.stage === 'rejected'">
                        <div class="space-y-2">
                            <div class="text-5xl">⚠️</div>
                            <h2 class="text-xl font-bold text-gray-900">Hồ sơ chưa được chấp thuận</h2>
                            <p class="text-gray-600" x-text="status.verification.note || 'Vui lòng liên hệ 365 HOME để biết thêm chi tiết.'"></p>
                        </div>
                    </template>
                </div>

                {{-- B5. Ký hợp đồng (chỉ sau khi 365 HOME duyệt giấy tờ) --}}
                <div x-show="step === 4" class="space-y-5">
                    <template x-if="status && status.stage === 'contract_sent'">
                        <div class="space-y-5">
                            <h2 class="text-lg font-bold text-gray-900">Ký hợp đồng hợp tác</h2>
                            <p class="text-sm text-gray-600">Giấy tờ của bạn đã được duyệt. Vui lòng đọc hợp đồng và ký xác nhận bằng mã OTP gửi về email.</p>
                            <template x-if="contract && contract.content">
                                <div class="max-h-96 overflow-y-auto rounded-lg border border-gray-200 p-4 text-sm text-gray-800" x-html="contract.content"></div>
                            </template>
                            <div class="flex flex-wrap items-end gap-3">
                                <button type="button" :disabled="loading || otpCooldown > 0" class="rounded-lg border border-gray-900 px-4 py-2.5 font-semibold disabled:opacity-60" @click="sendOtp()"
                                    x-text="otpCooldown > 0 ? ('Gửi lại mã sau ' + otpCooldown + 's') : 'Gửi mã OTP về email'"></button>
                                <p class="text-sm text-gray-600" x-show="otpSentTo" x-text="'Đã gửi mã tới ' + otpSentTo"></p>
                            </div>
                            <div class="grid sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Mã OTP <span class="text-red-500">*</span></label>
                                    <input type="text" inputmode="numeric" maxlength="6" x-model="sign.otp" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 tracking-widest">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Họ tên người ký <span class="text-red-500">*</span></label>
                                    <input type="text" maxlength="255" x-model="sign.signer_name" class="w-full rounded-lg border border-gray-300 px-3 py-2.5">
                                </div>
                            </div>
                            <label class="flex items-start gap-2 text-sm text-gray-700">
                                <input type="checkbox" x-model="sign.agree" class="mt-1">
                                <span>Tôi đã đọc và đồng ý với toàn bộ nội dung hợp đồng.</span>
                            </label>
                            <div class="flex justify-end">
                                <button type="button" :disabled="loading || !sign.agree || sign.otp.length !== 6 || !sign.signer_name" class="rounded-lg bg-gray-900 text-white font-semibold px-6 py-3 hover:bg-gray-800 disabled:opacity-60" @click="confirmSign()">Ký xác nhận</button>
                            </div>
                        </div>
                    </template>
                    <template x-if="status && status.stage === 'contract_signed'">
                        <div class="space-y-2 text-center">
                            <div class="text-5xl">📝</div>
                            <h2 class="text-xl font-bold text-gray-900">Bạn đã ký hợp đồng</h2>
                            <p class="text-gray-600">365 HOME sẽ ký xác nhận phía nền tảng, sau đó tài khoản quản trị được gửi về email của bạn.</p>
                        </div>
                    </template>
                    <template x-if="status && status.stage === 'active'">
                        <div class="space-y-3 text-center">
                            <div class="text-5xl">🎉</div>
                            <h2 class="text-xl font-bold text-gray-900">Hợp đồng đã có hiệu lực</h2>
                            <p class="text-gray-600">Tài khoản quản trị đã được gửi tới email của bạn.</p>
                            <a x-show="status.account.login_url" :href="status.account.login_url" class="inline-block rounded-lg bg-gray-900 text-white font-semibold px-6 py-3 hover:bg-gray-800">Đăng nhập trang quản trị</a>
                        </div>
                    </template>
                </div>

                <div class="mt-6 border-t border-gray-100 pt-4 text-xs text-gray-500 flex flex-wrap justify-between gap-2" x-show="token">
                    <span>Mã hồ sơ đã lưu trên thiết bị này để bạn quay lại làm tiếp.</span>
                    <button type="button" class="underline" @click="reset()">Đăng ký hồ sơ khác</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        function partnerOnboarding(initialType, docTypes, provinces, termsRequired) {
            const KEY = '365home_partner_onboarding';
            const MH_KEY = '365home_minihouse_purchase';
            const API = '/api/public/partner-onboarding';
            const store = {
                get() { try { return JSON.parse(localStorage.getItem(KEY) || '{}'); } catch (e) { return {}; } },
                set(v) { try { localStorage.setItem(KEY, JSON.stringify(v)); } catch (e) {} },
                clear() { try { localStorage.removeItem(KEY); } catch (e) {} },
            };
            const BUILDING_TYPES = ['fire_safety', 'security_order', 'property_ownership_or_use'];

            return {
                stepLabels: ['Đăng ký', 'Giấy tờ', 'Thông tin', 'Gửi duyệt', 'Ký hợp đồng'],
                recoverOpen: false, rec: { phone: '', email: '' },
                step: 0, loading: false, message: '', messageOk: false, errors: {},
                token: null, signingToken: null, status: null, contract: null, otpSentTo: '', otpCooldown: 0,
                reg: { partner_type: initialType || 'homestay', full_name: '', phone: '', email: '', business_name: '', address_province_code: '', address_ward_code: '', address_street: '', address_unit: '', address_building: '', postal_code: '', note: '', accept_terms: false, terms_version_id: null },
                terms: null, termsOpen: false, termsReq: termsRequired || { homestay: false, minihouse: true },
                get termsNeeded() { return !!this.termsReq[this.reg.partner_type === 'minihouse' ? 'minihouse' : 'homestay']; },
                provinces: provinces || [], wards: [], loadingWards: false,
                addrQuery: '', suggestions: [], suggestOpen: false, suggestLoading: false,
                plans: [], planId: null, periods: 1, purchase: null, pollTimer: null, trialMonths: {{ (int) config('partner_flow.minihouse_signup_trial_months', 0) }},
                doc: { type: 'business_license', name: '', document_number: '', issuer: '', issued_at: '', expires_at: '' },
                info: {},
                sign: { otp: '', signer_name: '', agree: false },
                infoFields: [
                    { key: 'legal_name', label: 'Tên pháp lý (công ty / hộ kinh doanh)', required: true, full: true },
                    { key: 'tax_code', label: 'Mã số thuế' },
                    { key: 'email', label: 'Email', type: 'email', required: true },
                    { key: 'address', label: 'Địa chỉ đăng ký kinh doanh', required: true, full: true },
                    { key: 'representative_name', label: 'Người đại diện ký hợp đồng', required: true },
                    { key: 'representative_position', label: 'Chức vụ người đại diện (mặc định: Chủ cơ sở)' },
                    { key: 'representative_id_number', label: 'Số CMND/CCCD', required: true },
                    { key: 'representative_id_issued_at', label: 'Ngày cấp CMND/CCCD', type: 'date', required: true },
                    { key: 'representative_id_issued_place', label: 'Nơi cấp CMND/CCCD (mặc định: Cục Cảnh sát QLHC về TTXH)' },
                    { key: 'representative_dob', label: 'Ngày sinh người đại diện', type: 'date' },
                    { key: 'business_license_date', label: 'Ngày cấp giấy phép kinh doanh', type: 'date' },
                    { key: 'business_license_issuer', label: 'Nơi cấp giấy phép kinh doanh' },
                ],

                get editable() { return !!this.status && ['registered', 'documents_uploaded', 'ready_to_submit', 'changes_requested'].includes(this.status.stage); },
                err(k) { return this.errors[k] ? this.errors[k][0] : ''; },
                flash(msg, ok = false) { this.message = msg; this.messageOk = ok; },
                allowedDocTypes() {
                    const mh = (this.status ? this.status.partner_type : this.reg.partner_type) === 'minihouse';
                    // MiniHouse đăng ký dùng thử nộp PCCC/ANTT ở cấp đối tác như Homestay → không lọc bỏ.
                    return docTypes.filter((t) => !(mh && !this.purchase && BUILDING_TYPES.includes(t.value)));
                },

                async call(method, url, body, isForm = false) {
                    this.loading = true; this.errors = {}; this.message = '';
                    try {
                        const opts = { method, headers: { Accept: 'application/json' } };
                        if (body !== undefined) {
                            if (isForm) opts.body = body;
                            else { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
                        }
                        const res = await fetch(url, opts);
                        const data = await res.json().catch(() => ({}));
                        if (res.status === 422 && data.errors) { this.errors = data.errors; this.flash(data.message || 'Vui lòng kiểm tra lại thông tin.'); return null; }
                        if (res.status === 429) { this.flash('Bạn thao tác quá nhanh, vui lòng thử lại sau ít phút.'); return null; }
                        if (!res.ok) { this.flash(data.message || 'Có lỗi xảy ra, vui lòng thử lại.'); return { _status: res.status }; }
                        return data;
                    } catch (e) {
                        this.flash('Lỗi kết nối. Vui lòng thử lại.');
                        return null;
                    } finally {
                        this.loading = false;
                    }
                },

                async init() {
                    this.loadPlans();
                    this.loadTerms();
                    this.$watch('reg.partner_type', () => { this.reg.accept_terms = false; this.loadTerms(); });
                    // Link từ email: ?ma=<mã hồ sơ> (đăng ký hợp tác) hoặc ?mh=<mã đơn> (MiniHouse) → lưu lại rồi bỏ khỏi URL.
                    const qs = new URLSearchParams(window.location.search);
                    if (qs.get('mh')) {
                        store.clear();
                        try { localStorage.setItem(MH_KEY, qs.get('mh')); } catch (e) {}
                        window.history.replaceState({}, '', window.location.pathname);
                    } else if (qs.get('ma')) {
                        try { localStorage.removeItem(MH_KEY); } catch (e) {}
                        store.set({ token: qs.get('ma') });
                        window.history.replaceState({}, '', window.location.pathname);
                    }
                    try {
                        if (localStorage.getItem(MH_KEY) && !store.get().token) {
                            this.reg.partner_type = 'minihouse';
                            this.purchase = { stage: 'pending_payment' };
                            this.pollPurchase();
                            return;
                        }
                    } catch (e) {}
                    const saved = store.get();
                                        if (!saved.token) return;
                    this.token = saved.token;
                    const ok = await this.refresh();
                    if (!ok) { this.reset(); return; }
                    this.goToStage();
                    await this.syncContract();
                },
                save() { store.set({ token: this.token }); },
                reset() { store.clear(); this.resetPurchase(); Object.assign(this, { token: null, signingToken: null, status: null, contract: null, step: 0, message: '', errors: {} }); },

                async refresh() {
                    const data = await this.call('GET', `${API}/${this.token}`);
                    if (!data || data._status) return false;
                    this.applyStatus(data.data);
                    return true;
                },
                applyStatus(s) {
                    this.status = s;
                    this.infoFields.forEach((f) => { this.info[f.key] = s.partner[f.key] ?? ''; });
                    if (!this.sign.signer_name) this.sign.signer_name = s.partner.representative_name || '';
                },
                goToStage() {
                    const st = this.status.stage;
                    const map = { registered: 1, documents_uploaded: this.status.steps.documents_uploaded ? 2 : 1, ready_to_submit: 3, pending_review: 3, changes_requested: 3, approved: 3, rejected: 3, contract_sent: 4, contract_signed: 4, active: 4 };
                    this.step = map[st] ?? 0;
                },
                // Hợp đồng chỉ có sau khi 365 HOME duyệt giấy tờ: lấy mã ký từ trạng thái hồ sơ rồi tải nội dung.
                async syncContract() {
                    const c = this.status && this.status.contract;
                    if (c && c.signing_active && c.signing_token) {
                        this.signingToken = c.signing_token;
                        const res = await fetch(`/api/partner-contracts/${this.signingToken}`, { headers: { Accept: 'application/json' } });
                        if (res.ok) this.contract = (await res.json()).data;
                    } else {
                        this.contract = null;
                    }
                },
                go(step) {
                    this.message = ''; this.errors = {}; this.step = step;
                },

                get minihouseMode() { return !!this.purchase || (!this.status && this.reg.partner_type === 'minihouse'); },
                // MiniHouse đăng ký dùng thử: đang nộp / cần bổ sung giấy tờ → hiện khối "Giấy tờ pháp lý".
                get mhDocsStage() { return !!this.purchase && ['documents', 'changes_requested'].includes(this.purchase.stage); },
                get selectedPlan() { return this.plans.find((p) => p.id === Number(this.planId)) || null; },
                get selectedPeriod() { return this.selectedPlan ? (this.selectedPlan.periods.find((o) => o.periods === Number(this.periods)) || null) : null; },
                vnd(n) { return new Intl.NumberFormat('vi-VN').format(Number(n) || 0) + 'đ'; },
                vietQr(pay) {
                    const a = pay.payment.account;
                    return `https://img.vietqr.io/image/${a.bank_bin}-${a.account_number}-compact2.png?amount=${pay.amount_vnd}&addInfo=${encodeURIComponent(pay.transaction_code)}&accountName=${encodeURIComponent(a.account_name || '')}`;
                },
                async loadPlans() {
                    try {
                        const res = await fetch('/api/public/minihouse-plans', { headers: { Accept: 'application/json' } });
                        if (res.ok) {
                            this.plans = (await res.json()).data || [];
                            if (this.plans.length && !this.planId) { this.planId = this.plans[0].id; this.periods = (this.plans[0].periods[0] || {}).periods || 1; }
                        }
                    } catch (e) {}
                },
                async buy() {
                    if (this.trialMonths === 0 && !this.selectedPlan) { this.flash('Vui lòng chọn gói dịch vụ.'); return; }
                    const body = this.trialMonths > 0 ? { ...this.reg } : { ...this.reg, plan_id: this.planId, periods: this.periods };
                    delete body.note; delete body.partner_type;
                    const data = await this.call('POST', '/api/public/minihouse-purchase', body);
                    if (!data || data._status) return;
                    this.purchase = data.data;
                    try { localStorage.setItem(MH_KEY, data.data.purchase_token); } catch (e) {}
                    if (data.data.dossier) {
                        this.token = data.data.purchase_token;
                        await this.refresh();
                        this.flash('Đã tạo hồ sơ. Tiếp theo, tải lên 3 giấy tờ pháp lý rồi gửi duyệt.', true);
                    } else {
                        this.flash('', true);
                    }
                    this.pollPurchase();
                },
                pollPurchase() {
                    clearInterval(this.pollTimer);
                    let n = 0;
                    const tick = async () => {
                        let token = null; try { token = localStorage.getItem(MH_KEY); } catch (e) {}
                        if (!token) return;
                        // Chờ duyệt giấy tờ có thể kéo dài: hỏi lại thưa hơn (30 giây/lần) so với lúc chờ thanh toán (5 giây/lần).
                        if (n++ && this.purchase && this.purchase.stage === 'pending_review' && n % 6) return;
                        try {
                            const res = await fetch(`/api/public/minihouse-purchase/${token}`, { headers: { Accept: 'application/json' } });
                            if (res.ok) { this.purchase = { ...this.purchase, ...(await res.json()).data }; await this.syncDossier(token); }
                        } catch (e) {}
                        // Đang nộp/bổ sung giấy tờ thì khách tự thao tác — không cần hỏi lại; gửi duyệt/rút hồ sơ sẽ bật lại.
                        if (this.purchase && ['active', 'cancelled', 'expired', 'documents', 'changes_requested', 'rejected'].includes(this.purchase.stage)) clearInterval(this.pollTimer);
                    };
                    this.pollTimer = setInterval(tick, 5000); tick();
                },
                // Đăng ký dùng thử có bước giấy tờ: dùng chung API hồ sơ (/api/public/partner-onboarding/{mã}) để nộp/xoá giấy tờ, gửi duyệt, rút hồ sơ.
                async syncDossier(token) {
                    if (!this.purchase.dossier) return;
                    this.token = token;
                    if (this.mhDocsStage && (!this.status || this.status.stage === 'pending_review')) await this.refresh();
                },
                resetPurchase() {
                    clearInterval(this.pollTimer); this.purchase = null;
                    try { localStorage.removeItem(MH_KEY); } catch (e) {}
                },

                async suggest() {
                    const q = this.addrQuery.trim();
                    if (!q) { this.suggestions = []; return; }
                    this.suggestLoading = true; this.suggestOpen = true;
                    try {
                        const res = await fetch(`/api/v2/address/suggest?q=${encodeURIComponent(q)}&limit=8`, { headers: { Accept: 'application/json' } });
                        this.suggestions = res.ok ? ((await res.json()).suggestions || []) : [];
                    } catch (e) { this.suggestions = []; } finally { this.suggestLoading = false; }
                },
                async pickSuggestion(s) {
                    this.reg.address_province_code = String(s.province_code);
                    await this.loadWards();
                    if (s.ward_code) this.reg.address_ward_code = String(s.ward_code);
                    this.addrQuery = s.type === 'ward' ? `${s.label}, ${s.province_name}` : s.label;
                    this.suggestOpen = false; this.suggestions = [];
                },
                manualAddress() {
                    this.suggestOpen = false; this.addrQuery = ''; this.suggestions = [];
                    this.$nextTick(() => this.$refs.provinceSelect && this.$refs.provinceSelect.focus());
                },

                async loadWards() {
                    this.reg.address_ward_code = ''; this.wards = [];
                    if (!this.reg.address_province_code) return;
                    this.loadingWards = true;
                    try {
                        const res = await fetch(`/api/v2/ward?province_code=${encodeURIComponent(this.reg.address_province_code)}`, { headers: { Accept: 'application/json' } });
                        if (res.ok) this.wards = (await res.json()).wards || [];
                    } catch (e) {} finally { this.loadingWards = false; }
                },

                async loadTerms() {
                    try {
                        const res = await fetch('/api/public/terms/' + (this.reg.partner_type === 'minihouse' ? 'minihouse' : 'homestay'), { headers: { Accept: 'application/json' } });
                        if (res.ok) { this.terms = (await res.json()).data; this.reg.terms_version_id = this.terms.id; }
                    } catch (e) {}
                },
                async register() {
                    if (this.termsNeeded && !this.reg.accept_terms) { this.errors = { accept_terms: ['Bạn cần đọc và đồng ý Điều khoản dịch vụ để đăng ký.'] }; this.flash('Vui lòng đồng ý Điều khoản dịch vụ.'); return; }
                    if (this.reg.partner_type === 'minihouse') { await this.buy(); return this.termsChanged(); }
                    const data = await this.call('POST', API, this.reg);
                    if (!data || data._status) { return this.termsChanged(); }
                    this.token = data.data.onboarding_token; this.save();
                    this.applyStatus(data.data);
                    this.step = 1; this.flash('Đã tạo hồ sơ. Tiếp theo, tải lên giấy tờ pháp lý.', true);
                },

                // Điều khoản vừa được cập nhật phiên bản mới (server từ chối bản cũ): tải lại, bỏ tick để khách đọc và đồng ý lại.
                async termsChanged() {
                    if (this.errors.terms_version_id) { this.reg.accept_terms = false; await this.loadTerms(); }
                },

                async uploadDoc() {
                    const file = this.$refs.docFile.files[0];
                    if (!file) { this.errors = { file: ['Vui lòng chọn tệp.'] }; return; }
                    const fd = new FormData();
                    Object.entries(this.doc).forEach(([k, v]) => { if (v) fd.append(k, v); });
                    fd.append('file', file);
                    const data = await this.call('POST', `${API}/${this.token}/documents`, fd, true);
                    if (!data || data._status) return;
                    this.$refs.docFile.value = '';
                    this.doc = { type: 'other', name: '', document_number: '', issuer: '', issued_at: '', expires_at: '' };
                    await this.refresh();
                    this.flash('Đã tải lên giấy tờ.', true);
                },
                async removeDoc(id) {
                    if (!confirm('Xoá giấy tờ này?')) return;
                    const data = await this.call('DELETE', `${API}/${this.token}/documents/${id}`);
                    if (data && !data._status) await this.refresh();
                },

                async saveInfo() {
                    const data = await this.call('PUT', `${API}/${this.token}/contract-info`, this.info);
                    if (!data || data._status) return;
                    this.applyStatus(data.data);
                    this.step = 3;
                },
                async sendOtp() {
                    const data = await this.call('POST', `/api/partner-contracts/${this.signingToken}/otp`);
                    if (!data || data._status) return;
                    this.otpSentTo = data.data.email;
                    this.otpCooldown = 60;
                    const timer = setInterval(() => { if (--this.otpCooldown <= 0) clearInterval(timer); }, 1000);
                },
                async confirmSign() {
                    const data = await this.call('POST', `/api/partner-contracts/${this.signingToken}/confirm`, this.sign);
                    if (!data || data._status) return;
                    await this.refresh();
                    await this.syncContract();
                    this.step = 4; this.flash('Đã ký hợp đồng.', true);
                },
                async recover() {
                    const data = await this.call('POST', `${API}/recover`, this.rec);
                    if (!data || data._status) return;
                    this.flash(data.message, true);
                },
                async withdraw() {
                    if (!confirm('Rút hồ sơ về bản nháp để chỉnh sửa? Bạn cần gửi duyệt lại sau khi sửa xong.')) return;
                    const data = await this.call('POST', `${API}/${this.token}/withdraw`);
                    if (!data || data._status) return;
                    this.applyStatus(data.data);
                    this.flash(data.message, true);
                    if (this.purchase) { this.pollPurchase(); return; }
                    this.step = 1;
                },
                async submitDossier() {
                    const data = await this.call('POST', `${API}/${this.token}/submit`);
                    if (!data || data._status) return;
                    this.applyStatus(data.data);
                    this.flash(data.message, true);
                    if (this.purchase) { this.pollPurchase(); return; }
                    this.step = 3;
                },
            };
        }
    </script>
@endsection
