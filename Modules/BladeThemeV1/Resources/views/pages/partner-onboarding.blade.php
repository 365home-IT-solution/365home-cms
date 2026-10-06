@extends('bladethemev1::layouts.master')

<x-bladethemev1::seo :seoData="$seoData" />

@section('content')
    @livewire('bladethemev1::header')
    @livewire('bladethemev1::drawer-menu')

    @php
        // Loại giấy tờ HIỂN THỊ trên trang đăng ký (config partner_flow.registration_selectable_documents); loại bắt buộc luôn hiển thị.
        $selectableDocs = array_merge((array) config('partner_flow.registration_selectable_documents', []), \App\Models\PartnerLegalDocument::registrationRequiredFor(\App\Models\Partner::TYPE_HOMESTAY), \App\Models\PartnerLegalDocument::registrationRequiredFor(\App\Models\Partner::TYPE_MINIHOUSE));
        $docTypes = collect(\App\Models\PartnerLegalDocument::TYPES)->only($selectableDocs)->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values();
        // MiniHouse ĐĂNG KÝ DÙNG THỬ có bước giấy tờ (MINIHOUSE_TRIAL_DOCUMENTS_REQUIRED): đăng ký → nộp giấy tờ bắt buộc → gửi duyệt → duyệt xong mới tặng dùng thử.
        $mhDocs = (bool) config('partner_flow.minihouse_trial_documents_required') && ! config('partner_flow.minihouse_contract_enabled');
        // Giấy tờ BẮT BUỘC khi đăng ký theo loại đối tác (bật/tắt ở config partner_flow.registration_required_documents).
        $requiredDocLabels = collect([\App\Models\Partner::TYPE_HOMESTAY, \App\Models\Partner::TYPE_MINIHOUSE])->mapWithKeys(fn ($type) => [
            $type => array_map(fn ($doc) => \App\Models\PartnerLegalDocument::TYPES[$doc] ?? $doc, \App\Models\PartnerLegalDocument::registrationRequiredFor($type)),
        ]);
        $provinceOptions = \App\Models\Province::query()->whereNotNull('code')->orderBy('name')->get(['code', 'name'])->map(fn ($p) => ['code' => $p->code, 'name' => $p->name])->values();
    @endphp

    {{-- Đăng ký hợp tác (Homestay / MiniHouse) — gọi API công khai /api/public/partner-onboarding. Mã hồ sơ lưu ở localStorage để quay lại làm tiếp. --}}
    <div class="bg-gray-50 px-4 py-10 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-3xl"
            x-data="partnerOnboarding(@js($initialType), @js($docTypes), @js($provinceOptions), @js(['homestay' => app(\App\Services\TermsService::class)->required('partner_homestay'), 'minihouse' => app(\App\Services\TermsService::class)->required('partner_minihouse')]), @js(\App\Support\LegalDocumentFields::schema()))"
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
                    {{-- Địa chỉ có cấu trúc: số nhà, đường → tỉnh/thành → phường/xã (+ căn hộ, toà nhà, mã bưu điện nếu có). Server ghép thành địa chỉ đầy đủ. --}}
                    <fieldset class="space-y-3">
                        <legend class="block text-sm font-medium text-gray-700 mb-1">Địa chỉ cơ sở kinh doanh <span class="text-red-500">*</span></legend>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">Số nhà, tên đường/phố <span class="text-red-500">*</span></label>
                            <input type="text" x-model="reg.address_street" maxlength="255" required placeholder="Vd: 12 Lê Lợi" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-gray-400">
                            <p class="text-xs text-red-600 mt-1" x-show="errors.address_street" x-text="err('address_street')"></p>
                        </div>
                        <div class="grid sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Tỉnh/Thành phố <span class="text-red-500">*</span></label>
                                <select x-model="reg.address_province_code" @change="loadWards()" required class="w-full rounded-lg border border-gray-300 px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-gray-400">
                                    <option value="">— Chọn tỉnh/thành phố —</option>
                                    <template x-for="p in provinces" :key="p.code"><option :value="String(p.code)" x-text="p.name"></option></template>
                                </select>
                                <p class="text-xs text-red-600 mt-1" x-show="errors.address_province_code" x-text="err('address_province_code')"></p>
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Phường/Xã <span class="text-red-500">*</span></label>
                                <select x-model="reg.address_ward_code" :disabled="!reg.address_province_code || loadingWards" required class="w-full rounded-lg border border-gray-300 px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-gray-400">
                                    <option value="" x-text="!reg.address_province_code ? 'Chọn tỉnh/thành phố trước' : (loadingWards ? 'Đang tải...' : '— Chọn phường/xã —')"></option>
                                    <template x-for="w in wards" :key="w.code"><option :value="String(w.code)" x-text="w.name"></option></template>
                                </select>
                                <p class="text-xs text-red-600 mt-1" x-show="errors.address_ward_code" x-text="err('address_ward_code')"></p>
                            </div>
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
                        <p>Bạn không cần chọn gói hay thanh toán lúc đăng ký. @if ($mhDocs) Bước tiếp theo là nộp giấy tờ pháp lý bắt buộc ({{ implode(', ', $requiredDocLabels[\App\Models\Partner::TYPE_MINIHOUSE]) }}); sau khi 365 Home duyệt giấy tờ, @else Sau khi 365 Home duyệt, @endif tài khoản và mật khẩu sẽ gửi về email; hết thời gian dùng thử, đăng nhập để chọn gói và thanh toán.</p>
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
                        <p class="text-sm text-gray-600 mt-1">Chọn tệp PDF hoặc ảnh (jpg, png, webp), tối đa 10 MB cho từng giấy tờ. Hệ thống tự đọc tệp và điền sẵn thông tin để bạn kiểm tra trước khi tải lên.</p>
                        <p class="text-sm text-gray-500 mt-1" x-show="!purchase && (status ? status.partner_type : reg.partner_type) === 'minihouse'">MiniHouse: giấy tờ từng toà nhà (PCCC, an ninh trật tự, sở hữu/quyền khai thác) sẽ bổ sung sau khi hồ sơ được duyệt và tạo toà nhà.</p>
                    </div>
                    {{-- MỖI LOẠI GIẤY TỜ MỘT THẺ có sẵn ô chọn tệp (bắt buộc xếp trước): chọn tệp là TỰ QUÉT để điền các ô riêng của loại đó
                         (dkkd_* / antt_* / pccc_* — App\Support\LegalDocumentFields), khách kiểm tra rồi bấm tải lên. Không cần chọn loại hay bấm quét. --}}
                    <template x-for="slot in docSlots()" :key="slot.type">
                        <div class="pod-card" :class="slot.uploaded ? 'is-done' : (slot.required ? 'is-required' : '')">
                            <div class="pod-head">
                                <span class="pod-icon" x-text="slot.uploaded ? '✓' : (slot.required ? '!' : '+')"></span>
                                <div class="pod-title">
                                    <h3 x-text="slot.label"></h3>
                                    <span class="pod-badge" x-text="slot.required ? 'Bắt buộc' : 'Không bắt buộc'"></span>
                                </div>
                                <span class="pod-state" :class="!slot.uploaded && docPending(slot.type) ? 'is-pending' : ''" x-text="slot.uploaded ? 'Đã gửi' : (docPending(slot.type) ? 'Đã chọn tệp' : 'Chưa gửi')"></span>
                            </div>
                            <template x-for="d in slot.docs" :key="d.id">
                                <div class="pod-file" :class="d.status === 'rejected' || d.status === 'changes_requested' ? 'is-problem' : ''">
                                    <div class="pod-file-info">
                                        <div class="pod-file-name" x-text="d.file_name || 'Tệp đã nộp'"></div>
                                        <div class="pod-file-meta" x-text="[d.document_number, d.status_label].filter(Boolean).join(' · ')"></div>
                                        <div class="pod-error" x-show="d.review_note" x-text="'Lý do: ' + d.review_note"></div>
                                    </div>
                                    <button type="button" class="pod-link-danger" x-show="['draft','changes_requested','rejected'].includes(d.status) && editable" @click="removeDoc(d.id)">Xoá</button>
                                </div>
                            </template>
                            <form @submit.prevent class="pod-form" x-show="editable && !slot.uploaded">
                                {{-- CCCD: chỉ nhận ẢNH và BẮT BUỘC đọc được mã QR trên thẻ (server kiểm tra lại khi nộp). --}}
                                <p class="pod-hint" x-show="slot.type === 'citizen_id'">Chụp rõ <strong>mặt thẻ có mã QR</strong> của người đại diện: chụp thẳng, đủ sáng, lấy trọn cả thẻ. Hệ thống phải đọc được mã QR thì mới nhận.</p>
                                {{-- Ô chọn tệp tự dựng (input thật ẩn bên trong label) thay cho nút mặc định của trình duyệt. --}}
                                <label class="pod-picker" :class="{ 'is-busy': docs[slot.type].scanning, 'has-file': docs[slot.type].fileName }">
                                    <input type="file" class="pod-picker-input" :accept="slot.type === 'citizen_id' ? '.jpg,.jpeg,.png,.webp' : '.pdf,.jpg,.jpeg,.png,.webp'" :disabled="docs[slot.type].scanning" @change="pickDocFile(slot.type, $event)">
                                    <span class="pod-picker-btn" x-text="docs[slot.type].fileName ? 'Đổi tệp' : 'Chọn tệp'"></span>
                                    <span class="pod-picker-text">
                                        <span class="pod-picker-name" x-text="docs[slot.type].fileName || 'Chưa chọn tệp'"></span>
                                        <span class="pod-picker-sub" x-show="!docs[slot.type].scanning" x-text="slot.type === 'citizen_id' ? 'Ảnh JPG, PNG, WEBP · tối đa 10 MB' : 'PDF hoặc ảnh JPG, PNG, WEBP · tối đa 10 MB'"></span>
                                        <span class="pod-picker-sub is-busy" x-show="docs[slot.type].scanning"><span class="pod-spinner"></span><span x-text="slot.type === 'citizen_id' ? 'Đang đọc mã QR trên CCCD...' : 'Đang đọc giấy tờ để điền sẵn thông tin...'"></span></span>
                                    </span>
                                </label>
                                <p class="pod-error" x-show="docErrorType === slot.type && (errors.file || errors.type)" x-text="err('file') || err('type')"></p>
                                <div class="pod-fields" x-show="docs[slot.type].fileName && !docs[slot.type].scanning">
                                    <ul class="pod-warnings" x-show="docs[slot.type].warnings.length">
                                        <template x-for="(w, i) in docs[slot.type].warnings" :key="i"><li x-text="w"></li></template>
                                    </ul>
                                    <p class="pod-note" x-show="slot.fields.length" x-text="slot.type === 'citizen_id' ? 'Thông tin lấy từ mã QR trên thẻ (không sửa được). Bạn chỉ cần nhập thêm Nơi cấp.' : 'Kiểm tra và sửa lại thông tin bên dưới nếu hệ thống đọc chưa đúng.'"></p>
                                    <div class="pod-grid">
                                        <template x-for="f in slot.fields" :key="f.key">
                                            <div :class="f.input === 'textarea' || f.key.endsWith('_address') ? 'pod-wide' : ''">
                                                <label class="pod-label" x-text="f.label"></label>
                                                <template x-if="f.input === 'textarea'">
                                                    <textarea x-model="docs[slot.type].values[f.key]" rows="2" maxlength="2000" class="pod-input"></textarea>
                                                </template>
                                                <template x-if="f.input !== 'textarea'">
                                                    <input :type="f.input" x-model="docs[slot.type].values[f.key]" maxlength="500" :readonly="qrLocked(slot.type, f.key)"
                                                        class="pod-input" :class="qrLocked(slot.type, f.key) ? 'is-locked' : ''">
                                                </template>
                                                <p class="pod-note" x-show="f.key === 'pccc_document_number'" x-text="fireStage(docs[slot.type].values.pccc_document_number) || 'TD-PCCC = thẩm duyệt (chưa hoạt động); NT / BB / GXN-PCCC = đã nghiệm thu (chuẩn bị hoạt động).'"></p>
                                                <p class="pod-error" x-show="errors[f.key]" x-text="err(f.key)"></p>
                                            </div>
                                        </template>
                                    </div>
                                    {{-- Không có nút tải lên riêng từng giấy tờ: mọi tệp đã chọn được tải lên cùng lúc khi bấm nút ở cuối bước này. --}}
                                    <p class="pod-note pod-note-pending" x-text="'Giấy tờ này sẽ được tải lên khi bạn bấm “' + (purchase ? 'Gửi giấy tờ chờ duyệt' : 'Tiếp tục') + '” ở cuối trang.'"></p>
                                </div>
                            </form>
                        </div>
                    </template>
                    {{-- Giấy tờ thuộc loại không còn hiển thị (hồ sơ cũ đã nộp trước đó): vẫn liệt kê để khách xem/xoá. --}}
                    <ul class="divide-y divide-gray-100 rounded-lg border border-gray-200" x-show="otherDocs().length">
                        <template x-for="d in otherDocs()" :key="d.id">
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
                    <div class="flex justify-end">
                        <button type="button" class="rounded-lg bg-gray-900 text-white font-semibold px-6 py-3 hover:bg-gray-800 disabled:opacity-60"
                            x-show="!purchase" :disabled="loading || docsUploading || !docsReady" @click="continueDocs()" x-text="docsUploading ? 'Đang tải giấy tờ lên...' : 'Tiếp tục'"></button>
                        {{-- MiniHouse đăng ký dùng thử: không có bước thông tin hợp đồng — đủ giấy tờ bắt buộc là gửi duyệt luôn (tải các tệp đã chọn lên rồi gửi duyệt trong một lần bấm). --}}
                        <button type="button" class="rounded-lg bg-gray-900 text-white font-semibold px-6 py-3 hover:bg-gray-800 disabled:opacity-60"
                            x-show="purchase" x-cloak :disabled="loading || docsUploading || !docsReady" @click="submitDocs()" x-text="docsUploading ? 'Đang tải giấy tờ lên...' : (loading ? 'Đang gửi...' : 'Gửi giấy tờ chờ duyệt')"></button>
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

                {{-- Gửi hồ sơ / chờ 365 HOME duyệt. Luồng cũ = bước 4 (trước khi ký); luồng "đối tác ký trước" = bước 5 (sau khi ký). --}}
                <div x-show="step === reviewStep" class="space-y-5 text-center">
                    <template x-if="status && ['ready_to_submit', 'documents_uploaded', 'registered'].includes(status.stage)">
                        <div class="space-y-4">
                            <div class="text-5xl">📨</div>
                            <h2 class="text-xl font-bold text-gray-900">Gửi hồ sơ cho 365 HOME</h2>
                            <p class="text-gray-600" x-text="signFirst ? 'Bạn đã ký hợp đồng. Gửi hồ sơ để 365 HOME duyệt giấy tờ và ký xác nhận hợp đồng. Sau khi gửi, hồ sơ không chỉnh sửa được trong lúc chờ duyệt.' : '365 HOME sẽ xem và duyệt giấy tờ pháp lý của bạn. Nếu giấy tờ hợp lệ, hợp đồng hợp tác sẽ được gửi về email để bạn ký. Sau khi gửi, hồ sơ không chỉnh sửa được trong lúc chờ duyệt.'"></p>
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
                            <p class="text-gray-600" x-show="!signFirst">Khi giấy tờ được duyệt, hợp đồng hợp tác sẽ được gửi về email <strong x-text="status.partner.email"></strong> và hiện tại trang này để bạn ký.</p>
                            <p class="text-gray-600" x-show="signFirst">Bạn đã ký hợp đồng. Khi giấy tờ được duyệt, 365 HOME ký xác nhận hợp đồng và gửi tài khoản quản trị về email <strong x-text="status.partner.email"></strong>. Hợp đồng chỉ có hiệu lực sau khi 365 HOME ký.</p>
                            <p class="text-sm text-gray-500" x-text="signFirst ? 'Cần sửa lại giấy tờ hoặc thông tin? Rút hồ sơ về bản nháp, chỉnh sửa rồi gửi duyệt lại. Nếu sửa thông tin ký hợp đồng, bạn sẽ cần ký lại bản hợp đồng mới.' : 'Cần sửa lại giấy tờ hoặc thông tin? Rút hồ sơ về bản nháp, chỉnh sửa rồi gửi duyệt lại.'"></p>
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

                {{-- Ký hợp đồng. Luồng cũ = bước 5 (sau khi 365 HOME duyệt giấy tờ); luồng "đối tác ký trước" = bước 4 (ngay sau khi điền thông tin). --}}
                <div x-show="step === signStep" class="space-y-5">
                    <template x-if="status && status.stage === 'contract_sent'">
                        <div class="space-y-5">
                            <h2 class="text-lg font-bold text-gray-900">Ký hợp đồng hợp tác</h2>
                            <p class="text-sm text-gray-600" x-show="!signFirst || status.steps.documents_approved">Giấy tờ của bạn đã được duyệt. Vui lòng đọc hợp đồng và ký xác nhận bằng mã OTP gửi về email.</p>
                            <p class="text-sm text-gray-600" x-show="signFirst && !status.steps.documents_approved">Vui lòng đọc hợp đồng và ký xác nhận bằng mã OTP gửi về email. Sau khi bạn ký, hồ sơ được gửi cho 365 HOME duyệt giấy tờ. <strong>Hợp đồng chỉ có hiệu lực sau khi 365 HOME ký xác nhận.</strong></p>
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
                            <div class="flex justify-between gap-3">
                                <button type="button" class="rounded-lg border border-gray-300 px-5 py-3" x-show="signFirst && editable" @click="go(2)">Sửa thông tin</button>
                                <span x-show="!(signFirst && editable)"></span>
                                <button type="button" :disabled="loading || !sign.agree || sign.otp.length !== 6 || !sign.signer_name" class="rounded-lg bg-gray-900 text-white font-semibold px-6 py-3 hover:bg-gray-800 disabled:opacity-60" @click="confirmSign()">Ký xác nhận</button>
                            </div>
                        </div>
                    </template>
                </div>

                {{-- Đã ký xong / hợp đồng có hiệu lực: luôn ở bước cuối (thứ 5) của cả hai luồng. --}}
                <div x-show="step === 4" class="space-y-5">
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

    {{-- Kiểu riêng cho các thẻ giấy tờ (pod-*): viết thẳng ở đây để không phụ thuộc việc build lại CSS của theme. --}}
    <style>
        .pod-card { background:#fff; border:1px solid #e5e7eb; border-radius:16px; padding:20px; box-shadow:0 1px 2px rgba(16,24,40,.04); transition:border-color .15s, box-shadow .15s; }
        .pod-card.is-required { border-color:#fecaca; }
        .pod-card.is-done { border-color:#bbf7d0; background:#fcfffd; }
        .pod-head { display:flex; align-items:center; gap:12px; }
        .pod-icon { flex:none; width:32px; height:32px; border-radius:999px; display:inline-flex; align-items:center; justify-content:center; font-size:15px; font-weight:700; line-height:1; background:#f3f4f6; color:#6b7280; }
        .pod-card.is-required .pod-icon { background:#fee2e2; color:#dc2626; }
        .pod-card.is-done .pod-icon { background:#dcfce7; color:#15803d; }
        .pod-title { flex:1 1 auto; min-width:0; display:flex; flex-wrap:wrap; align-items:center; gap:6px 10px; }
        .pod-title h3 { margin:0; font-size:16px; font-weight:600; color:#111827; line-height:1.35; }
        .pod-badge { font-size:12px; font-weight:500; line-height:1; padding:5px 10px; border-radius:999px; background:#f3f4f6; color:#4b5563; white-space:nowrap; }
        .pod-card.is-required .pod-badge { background:#fef2f2; color:#b91c1c; }
        .pod-card.is-done .pod-badge { background:#f0fdf4; color:#15803d; }
        .pod-state { margin-left:auto; flex:none; font-size:13px; font-weight:500; padding:5px 12px; border-radius:999px; background:#f3f4f6; color:#6b7280; white-space:nowrap; }
        .pod-card.is-done .pod-state { background:#dcfce7; color:#15803d; }
        .pod-form { margin-top:16px; }
        .pod-form > * + * { margin-top:12px; }
        .pod-hint { margin:0; font-size:14px; line-height:1.5; color:#1e3a8a; background:#eff6ff; border:1px solid #dbeafe; border-radius:12px; padding:10px 14px; }
        .pod-picker { display:flex; align-items:center; gap:14px; padding:12px; border:1.5px dashed #d1d5db; border-radius:14px; background:#f9fafb; cursor:pointer; transition:border-color .15s, background .15s; }
        .pod-picker:hover { border-color:#9ca3af; background:#f3f4f6; }
        .pod-picker:focus-within { border-color:#111827; box-shadow:0 0 0 3px rgba(17,24,39,.08); }
        .pod-picker.has-file { border-style:solid; border-color:#e5e7eb; background:#fff; }
        .pod-picker.is-busy { cursor:progress; opacity:.85; }
        .pod-picker-input { position:absolute; width:1px; height:1px; opacity:0; overflow:hidden; clip:rect(0 0 0 0); }
        .pod-picker-btn { flex:none; background:#111827; color:#fff; font-size:14px; font-weight:600; padding:10px 18px; border-radius:10px; white-space:nowrap; transition:background .15s; }
        .pod-picker:hover .pod-picker-btn { background:#1f2937; }
        .pod-picker.has-file .pod-picker-btn { background:#fff; color:#111827; box-shadow:inset 0 0 0 1px #d1d5db; }
        .pod-picker-text { min-width:0; display:flex; flex-direction:column; gap:2px; }
        .pod-picker-name { font-size:14px; font-weight:500; color:#111827; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .pod-picker:not(.has-file) .pod-picker-name { color:#6b7280; font-weight:400; }
        .pod-picker-sub { font-size:12px; color:#6b7280; display:flex; align-items:center; gap:8px; }
        .pod-picker-sub.is-busy { color:#1d4ed8; }
        .pod-spinner { width:12px; height:12px; border-radius:999px; border:2px solid #bfdbfe; border-top-color:#1d4ed8; animation:pod-spin .7s linear infinite; }
        @keyframes pod-spin { to { transform:rotate(360deg); } }
        .pod-fields { padding-top:4px; }
        .pod-fields > * + * { margin-top:12px; }
        .pod-grid { display:grid; grid-template-columns:1fr; gap:14px 16px; }
        @media (min-width:640px) { .pod-grid { grid-template-columns:1fr 1fr; } .pod-wide { grid-column:1 / -1; } }
        .pod-label { display:block; font-size:13px; font-weight:500; color:#374151; margin-bottom:6px; }
        .pod-input { width:100%; border:1px solid #d1d5db; border-radius:10px; padding:10px 12px; font-size:15px; color:#111827; background:#fff; transition:border-color .15s, box-shadow .15s; }
        .pod-input:focus { outline:none; border-color:#111827; box-shadow:0 0 0 3px rgba(17,24,39,.08); }
        .pod-input.is-locked { background:#f9fafb; border-color:#e5e7eb; color:#374151; cursor:default; }
        .pod-input.is-locked:focus { border-color:#e5e7eb; box-shadow:none; }
        .pod-note { margin:0; font-size:12px; color:#6b7280; line-height:1.5; }
        .pod-error { margin:6px 0 0; font-size:13px; color:#dc2626; line-height:1.45; }
        .pod-warnings { margin:0; padding:10px 14px 10px 30px; list-style:disc; font-size:12px; line-height:1.5; color:#92400e; background:#fffbeb; border:1px solid #fde68a; border-radius:12px; }
        .pod-state.is-pending { background:#eff6ff; color:#1d4ed8; }
        .pod-note-pending { color:#1d4ed8; }
        .pod-file { margin-top:14px; display:flex; align-items:center; justify-content:space-between; gap:12px; padding:12px 14px; border-radius:12px; background:#f0fdf4; border:1px solid #dcfce7; }
        .pod-file.is-problem { background:#fef2f2; border-color:#fecaca; }
        .pod-file-info { min-width:0; }
        .pod-file-name { font-size:14px; font-weight:500; color:#111827; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .pod-file-meta { font-size:12px; color:#4b5563; margin-top:2px; }
        .pod-link-danger { flex:none; background:none; border:0; padding:6px 10px; border-radius:8px; font-size:13px; font-weight:500; color:#dc2626; cursor:pointer; }
        .pod-link-danger:hover { background:#fee2e2; }
        @media (max-width:480px) { .pod-card { padding:16px; } .pod-picker { flex-direction:column; align-items:stretch; text-align:center; } .pod-picker-btn { text-align:center; } .pod-picker-sub { justify-content:center; } }
    </style>

    <script>
        function partnerOnboarding(initialType, docTypes, provinces, termsRequired, docForms) {
            const KEY = '365home_partner_onboarding';
            const MH_KEY = '365home_minihouse_purchase';
            const API = '/api/public/partner-onboarding';
            const store = {
                get() { try { return JSON.parse(localStorage.getItem(KEY) || '{}'); } catch (e) { return {}; } },
                set(v) { try { localStorage.setItem(KEY, JSON.stringify(v)); } catch (e) {} },
                clear() { try { localStorage.removeItem(KEY); } catch (e) {} },
            };
            const BUILDING_TYPES = ['fire_safety', 'security_order', 'property_ownership_or_use'];
            const docFiles = {}, docInputs = {};

            return {
                // Thứ tự ký (config partner_flow.partner_signs_before_review; hồ sơ đã tải thì theo status.flow): true = ký hợp đồng TRƯỚC rồi mới gửi duyệt.
                signFirstDefault: @js((bool) config('partner_flow.partner_signs_before_review')),
                get signFirst() { return this.status && this.status.flow ? !!this.status.flow.sign_before_review : this.signFirstDefault; },
                get stepLabels() { return this.signFirst ? ['Đăng ký', 'Giấy tờ', 'Thông tin', 'Ký hợp đồng', 'Chờ duyệt'] : ['Đăng ký', 'Giấy tờ', 'Thông tin', 'Gửi duyệt', 'Ký hợp đồng']; },
                get signStep() { return this.signFirst ? 3 : 4; },
                get reviewStep() { return this.signFirst ? 4 : 3; },
                recoverOpen: false, rec: { phone: '', email: '' },
                step: 0, loading: false, message: '', messageOk: false, errors: {},
                token: null, signingToken: null, status: null, contract: null, otpSentTo: '', otpCooldown: 0,
                reg: { partner_type: initialType || 'homestay', full_name: '', phone: '', email: '', business_name: '', address_province_code: '', address_ward_code: '', address_street: '', address_unit: '', address_building: '', postal_code: '', note: '', accept_terms: false, terms_version_id: null },
                terms: null, termsOpen: false, termsReq: termsRequired || { homestay: false, minihouse: true },
                get termsNeeded() { return !!this.termsReq[this.reg.partner_type === 'minihouse' ? 'minihouse' : 'homestay']; },
                provinces: provinces || [], wards: [], loadingWards: false,
                plans: [], planId: null, periods: 1, purchase: null, pollTimer: null, trialMonths: {{ (int) config('partner_flow.minihouse_signup_trial_months', 0) }},
                // Mỗi loại giấy tờ một form riêng (thẻ): giá trị các ô, tên tệp đã chọn, cảnh báo quét. Tệp thật giữ ngoài state (docFiles) vì Alpine bọc proxy.
                docs: Object.fromEntries(docTypes.map((t) => [t.value, { values: {}, fileName: '', warnings: [], scanning: false }])),
                docErrorType: null, docsUploading: false,
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
                // Các thẻ giấy tờ: loại bắt buộc (theo hồ sơ) xếp trước, rồi loại tuỳ chọn được hiển thị. "uploaded" = đã có tệp chưa bị từ chối.
                docSlots() {
                    if (!this.status) return [];
                    const mh = this.status.partner_type === 'minihouse';
                    const required = (this.status.required_documents || []).map((r) => r.type);
                    return docTypes
                        // MiniHouse đăng ký kiểu hợp đồng: giấy tờ cấp toà nhà bổ sung sau; đăng ký dùng thử nộp ở cấp đối tác → không lọc bỏ.
                        .filter((t) => required.includes(t.value) || !(mh && !this.purchase && BUILDING_TYPES.includes(t.value)))
                        .map((t) => {
                            const docs = this.status.documents.filter((d) => d.type === t.value);
                            return { type: t.value, label: t.label, required: required.includes(t.value), docs, uploaded: docs.some((d) => d.status !== 'rejected'),
                                fields: ((docForms || []).find((f) => f.type === t.value) || { fields: [] }).fields };
                        })
                        .sort((a, b) => Number(b.required) - Number(a.required));
                },
                otherDocs() {
                    const shown = docTypes.map((t) => t.value);
                    return this.status ? this.status.documents.filter((d) => !shown.includes(d.type)) : [];
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
                    const r = this.reviewStep, g = this.signStep;
                    // Ký trước: contract_signed / active hiển thị ở bước "Chờ duyệt"; đủ thông tin mà chưa có hợp đồng → về bước Thông tin để tạo hợp đồng.
                    const map = this.signFirst
                        ? { registered: 1, documents_uploaded: this.status.steps.documents_uploaded ? 2 : 1, ready_to_submit: this.status.steps.contract_signed ? r : 2, pending_review: r, changes_requested: r, approved: r, rejected: r, contract_sent: g, contract_signed: r, active: r }
                        : { registered: 1, documents_uploaded: this.status.steps.documents_uploaded ? 2 : 1, ready_to_submit: r, pending_review: r, changes_requested: r, approved: r, rejected: r, contract_sent: g, contract_signed: g, active: g };
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
                        this.flash('Đã tạo hồ sơ. Tiếp theo, tải lên giấy tờ pháp lý bắt buộc rồi gửi duyệt.', true);
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

                // PCCC: tình trạng suy ra từ số văn bản (cùng quy tắc với server).
                fireStage(number) {
                    const t = String(number || '').toUpperCase().split(/[^A-Z0-9]+/);
                    if (t.some((x) => ['NT', 'BB', 'GXN'].includes(x))) return 'Đã nghiệm thu: Chuẩn bị hoạt động';
                    return t.includes('TD') ? 'Thẩm duyệt: Chưa hoạt động' : '';
                },
                // CCCD: các ô lấy từ mã QR bị khoá (server cũng ghi đè bằng dữ liệu QR); chỉ "Nơi cấp" tự nhập.
                qrLocked(type, key) { return type === 'citizen_id' && key !== 'cccd_issuer'; },
                // Chọn tệp cho một thẻ → TỰ QUÉT và điền gợi ý vào các ô của loại đó (không lưu gì cho tới khi bấm nút cuối bước: "Tiếp tục" hoặc "Gửi giấy tờ chờ duyệt").
                // Quét lỗi/không đọc được thì khách vẫn tự nhập và tải lên bình thường.
                async pickDocFile(type, event) {
                    const file = event.target.files[0], d = this.docs[type];
                    docInputs[type] = event.target; docFiles[type] = file || null;
                    d.values = {}; d.warnings = []; d.fileName = file ? file.name : '';
                    if (!file) return;
                    this.docErrorType = type; d.scanning = true;
                    const fd = new FormData();
                    fd.append('type', type); fd.append('file', file);
                    const data = await this.call('POST', `${API}/${this.token}/documents/scan`, fd, true);
                    d.scanning = false;
                    // CCCD không đọc được mã QR: bỏ tệp vừa chọn (lỗi hiện dưới ô tệp) để khách chụp lại — không cho nhập tay.
                    if ((!data || data._status) && type === 'citizen_id' && docFiles[type] === file) {
                        docFiles[type] = null; d.fileName = ''; event.target.value = '';
                    }
                    if (!data || data._status || docFiles[type] !== file) return;
                    Object.entries(data.data.fields || {}).forEach(([k, v]) => { if (v) d.values[k] = v; });
                    d.warnings = data.data.warnings || [];
                    this.flash(data.message, (data.data.found || 0) > 0);
                },

                // Giấy tờ đã chọn tệp (và quét xong) nhưng CHƯA tải lên — sẽ được tải khi bấm nút cuối bước.
                docPending(type) { const d = this.docs[type]; return !!d && !!d.fileName && !d.scanning; },
                // Đủ điều kiện bấm nút cuối bước: mọi giấy tờ bắt buộc đã gửi hoặc đã chọn tệp, và không còn tệp nào đang quét.
                get docsReady() {
                    const slots = this.docSlots();
                    return slots.length > 0 && slots.every((s) => !s.required || s.uploaded || this.docPending(s.type)) && !slots.some((s) => this.docs[s.type].scanning);
                },
                // Tải lên MỘT giấy tờ đã chọn. Trả true nếu thành công; lỗi thì hiện ngay dưới thẻ của giấy tờ đó.
                async uploadDoc(type) {
                    const file = docFiles[type], d = this.docs[type];
                    this.docErrorType = type;
                    if (!file) { this.errors = { file: ['Vui lòng chọn tệp.'] }; return false; }
                    const fd = new FormData();
                    fd.append('type', type);
                    Object.entries(d.values).forEach(([k, v]) => { if (v) fd.append(k, v); });
                    fd.append('file', file);
                    const data = await this.call('POST', `${API}/${this.token}/documents`, fd, true);
                    if (!data || data._status) return false;
                    if (docInputs[type]) docInputs[type].value = '';
                    docFiles[type] = null;
                    this.docs[type] = { values: {}, fileName: '', warnings: [], scanning: false };
                    return true;
                },
                // Tải lên LẦN LƯỢT mọi giấy tờ đã chọn tệp. Một giấy tờ lỗi thì dừng lại ở đó (các giấy tờ trước đã lưu), giữ nguyên thông báo lỗi của nó.
                async uploadPendingDocs() {
                    this.docsUploading = true;
                    let done = 0, ok = true;
                    try {
                        for (const s of this.docSlots()) {
                            if (s.uploaded || !this.docPending(s.type)) continue;
                            if (!(await this.uploadDoc(s.type))) { ok = false; break; }
                            done++;
                        }
                        if (done) {
                            const errors = this.errors, message = this.message, messageOk = this.messageOk;
                            await this.refresh();
                            if (!ok) { this.errors = errors; this.message = message; this.messageOk = messageOk; }
                        }
                    } finally { this.docsUploading = false; }
                    return ok;
                },
                // Homestay: tải các giấy tờ đã chọn lên rồi sang bước Thông tin.
                async continueDocs() {
                    if (!(await this.uploadPendingDocs())) return;
                    if (this.status && this.status.steps.documents_uploaded) this.go(2);
                },
                // MiniHouse đăng ký dùng thử: tải các giấy tờ đã chọn lên rồi gửi duyệt luôn.
                async submitDocs() {
                    if (!(await this.uploadPendingDocs())) return;
                    await this.submitDossier();
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
                    if (!this.signFirst) { this.step = this.reviewStep; return; }
                    await this.prepareContract();
                },
                // Ký trước: tạo hợp đồng điều khoản chuẩn (đã có bản đang chờ ký/đã ký thì giữ nguyên) rồi sang bước ký; đã ký rồi thì sang bước gửi duyệt.
                async prepareContract() {
                    const data = await this.call('POST', `${API}/${this.token}/contract`);
                    if (!data || data._status) return;
                    this.applyStatus(data.data);
                    await this.syncContract();
                    this.step = this.status.stage === 'contract_sent' ? this.signStep : this.reviewStep;
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
                    this.goToStage();
                    this.flash(this.signFirst && this.status.stage === 'pending_review' ? 'Đã ký hợp đồng và gửi hồ sơ cho 365 HOME duyệt.' : 'Đã ký hợp đồng.', true);
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
                    this.step = this.reviewStep;
                },
            };
        }
    </script>
@endsection
