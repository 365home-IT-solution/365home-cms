@extends('bladethemev1::layouts.master')

<x-bladethemev1::seo :seoData="$seoData" />

@section('content')
    @livewire('bladethemev1::header')
    @livewire('bladethemev1::drawer-menu')

    @php
        $docTypes = collect(\App\Models\PartnerLegalDocument::TYPES)->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values();
    @endphp

    {{-- Đăng ký hợp tác (Homestay / MiniHouse) — gọi API công khai /api/public/partner-onboarding. Mã hồ sơ lưu ở localStorage để quay lại làm tiếp. --}}
    <div class="bg-gray-50 px-4 py-10 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-3xl"
            x-data="partnerOnboarding(@js($initialType), @js($docTypes))"
            x-init="init()">

            <div class="mb-6">
                <h1 class="text-3xl font-bold text-gray-900">Đăng ký hợp tác với 365 HOME</h1>
                <p class="text-base text-gray-600 mt-2">
                    Gửi giấy tờ pháp lý để 365 HOME xem xét. Giấy tờ được duyệt, bạn sẽ nhận hợp đồng để ký trực tuyến và tài khoản quản trị qua email.
                </p>
            </div>

            {{-- Thanh bước: vòng tròn đánh số nằm ngang, nối bằng đường kẻ (xong = xanh + dấu tích, hiện tại = viền đậm). Dùng inline style để không phụ thuộc bản build Tailwind. --}}
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

            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 sm:p-8">
                <p x-show="message" x-text="message" class="mb-5 rounded-lg px-4 py-3 text-sm"
                    :class="messageOk ? 'bg-green-50 border border-green-200 text-green-700' : 'bg-red-50 border border-red-200 text-red-700'"></p>

                {{-- B1. Đăng ký --}}
                <form x-show="step === 0" @submit.prevent="register" class="space-y-5">
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
                                <div class="text-sm text-gray-600 mt-1">Nhà trọ, căn hộ dịch vụ cho thuê dài hạn. <strong>Không thu hoa hồng</strong>, gói 199.000đ/tháng, miễn phí 6 tháng đầu.</div>
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
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Địa chỉ <span class="text-red-500">*</span></label>
                        <input type="text" x-model="reg.address" maxlength="500" required class="w-full rounded-lg border border-gray-300 px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-gray-400">
                        <p class="text-xs text-red-600 mt-1" x-show="errors.address" x-text="err('address')"></p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Ghi chú</label>
                        <textarea x-model="reg.note" rows="3" maxlength="2000" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-gray-400"></textarea>
                    </div>
                    <button type="submit" :disabled="loading" class="w-full rounded-lg bg-gray-900 text-white font-semibold py-3 hover:bg-gray-800 disabled:opacity-60" x-text="loading ? 'Đang gửi...' : 'Tiếp tục'"></button>

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

                {{-- B2. Giấy tờ pháp lý --}}
                <div x-show="step === 1" class="space-y-5">
                    <div>
                        <h2 class="text-lg font-bold text-gray-900">Giấy tờ pháp lý</h2>
                        <p class="text-sm text-gray-600 mt-1">Tệp PDF hoặc ảnh (jpg, png, webp), tối đa 10 MB. Loại giấy tờ trùng với danh mục 365 HOME dùng khi duyệt hồ sơ.</p>
                    </div>
                    {{-- Yêu cầu hồ sơ — khớp điều kiện "Phê duyệt chính thức" ở trang admin: bắt buộc Giấy phép kinh doanh còn hạn; MiniHouse bổ sung giấy tờ toà nhà sau. --}}
                    <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-700 space-y-1.5">
                        <div class="flex items-center gap-2">
                            <span class="inline-flex items-center justify-center rounded-full text-white" style="width:18px;height:18px;font-size:12px;"
                                :style="status && status.steps.documents_uploaded ? 'background:#16a34a;' : 'background:#9ca3af;'" x-text="status && status.steps.documents_uploaded ? '✓' : '!'"></span>
                            <span><strong>Giấy phép kinh doanh</strong> — <span class="text-red-600">bắt buộc</span>, phải còn hạn (nếu có ngày hết hạn).</span>
                        </div>
                        <p class="text-gray-500">Giấy tờ khác (đăng ký thuế, giấy phép/công nhận cơ sở lưu trú, giấy uỷ quyền người ký...) không bắt buộc nhưng nên nộp để duyệt nhanh hơn.</p>
                        <p class="text-gray-500" x-show="(status ? status.partner_type : reg.partner_type) === 'minihouse'">MiniHouse: giấy tờ từng toà nhà (PCCC, an ninh trật tự, sở hữu/quyền khai thác) sẽ bổ sung trong trang quản trị sau khi hợp đồng có hiệu lực và bạn tạo toà nhà.</p>
                        <p class="text-gray-500">Hồ sơ chỉ được 365 HOME phê duyệt khi mọi giấy tờ bắt buộc đã được duyệt và còn hạn.</p>
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
                            :disabled="!status || !status.steps.documents_uploaded" @click="go(2)">Tiếp tục</button>
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
        function partnerOnboarding(initialType, docTypes) {
            const KEY = '365home_partner_onboarding';
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
                reg: { partner_type: initialType || 'homestay', full_name: '', phone: '', email: '', business_name: '', address: '', note: '' },
                doc: { type: 'business_license', name: '', document_number: '', issuer: '', issued_at: '', expires_at: '' },
                info: {},
                sign: { otp: '', signer_name: '', agree: false },
                infoFields: [
                    { key: 'legal_name', label: 'Tên pháp lý (công ty / hộ kinh doanh)', required: true, full: true },
                    { key: 'tax_code', label: 'Mã số thuế' },
                    { key: 'email', label: 'Email', type: 'email', required: true },
                    { key: 'address', label: 'Địa chỉ đăng ký kinh doanh', required: true, full: true },
                    { key: 'representative_name', label: 'Người đại diện ký hợp đồng', required: true },
                    { key: 'representative_id_number', label: 'Số CMND/CCCD', required: true },
                    { key: 'representative_dob', label: 'Ngày sinh người đại diện', type: 'date' },
                    { key: 'business_license_date', label: 'Ngày cấp giấy phép kinh doanh', type: 'date' },
                    { key: 'business_license_issuer', label: 'Nơi cấp giấy phép kinh doanh' },
                    { key: 'bank_name', label: 'Ngân hàng' },
                    { key: 'bank_branch', label: 'Chi nhánh' },
                    { key: 'bank_account_number', label: 'Số tài khoản' },
                    { key: 'bank_account_holder', label: 'Chủ tài khoản' },
                ],

                get editable() { return !!this.status && ['registered', 'documents_uploaded', 'ready_to_submit', 'changes_requested'].includes(this.status.stage); },
                err(k) { return this.errors[k] ? this.errors[k][0] : ''; },
                flash(msg, ok = false) { this.message = msg; this.messageOk = ok; },
                allowedDocTypes() {
                    const mh = (this.status ? this.status.partner_type : this.reg.partner_type) === 'minihouse';
                    return docTypes.filter((t) => !(mh && BUILDING_TYPES.includes(t.value)));
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
                    // Link khôi phục từ email: ?ma=<mã hồ sơ mới> → lưu lại rồi bỏ khỏi URL.
                    const qs = new URLSearchParams(window.location.search);
                    if (qs.get('ma')) {
                        store.set({ token: qs.get('ma') });
                        window.history.replaceState({}, '', window.location.pathname);
                    }
                    const saved = store.get();
                                        if (!saved.token) return;
                    this.token = saved.token;
                    const ok = await this.refresh();
                    if (!ok) { this.reset(); return; }
                    this.goToStage();
                    await this.syncContract();
                },
                save() { store.set({ token: this.token }); },
                reset() { store.clear(); Object.assign(this, { token: null, signingToken: null, status: null, contract: null, step: 0, message: '', errors: {} }); },

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

                async register() {
                    const data = await this.call('POST', API, this.reg);
                    if (!data || data._status) return;
                    this.token = data.data.onboarding_token; this.save();
                    this.applyStatus(data.data);
                    this.step = 1; this.flash('Đã tạo hồ sơ. Tiếp theo, tải lên giấy tờ pháp lý.', true);
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
                    this.step = 1;
                },
                async submitDossier() {
                    const data = await this.call('POST', `${API}/${this.token}/submit`);
                    if (!data || data._status) return;
                    this.applyStatus(data.data);
                    this.flash(data.message, true);
                    this.step = 3;
                },
            };
        }
    </script>
@endsection
