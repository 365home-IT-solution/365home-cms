@extends('bladethemev1::layouts.master')

<x-bladethemev1::seo :seoData="$seoData" />

@section('content')
    @livewire('bladethemev1::header')
    @livewire('bladethemev1::drawer-menu')

    {{-- Trang thuê xe cho khách. Chỉ có khi bật công tắc ở CMS (Thuê xe → Cài đặt thuê xe). Toàn bộ dữ liệu qua API /api/vehicle-rentals/* và
         /api/vehicle-rental-*; đặt xe / đơn / giấy tờ cần đăng nhập (token ở localStorage 'auth_token' như các trang khác).
         CSS riêng tiền tố "vr-" để không phụ thuộc bản build Tailwind. --}}
    <style>
        .vr { --vr-primary: {{ $primaryColor }}; --vr-primary-rgb: {{ $primaryColorRgb }}; --vr-on-primary: {{ $textOnPrimary }}; background:#f9fafb; padding:32px 16px 64px; color:#111827; }
        .vr *, .vr *::before, .vr *::after { box-sizing:border-box; }
        .vr-wrap { max-width:1120px; margin:0 auto; }
        .vr h1 { font-size:30px; font-weight:800; margin:0 0 6px; }
        .vr-sub { color:#6b7280; font-size:15px; margin:0 0 20px; }
        .vr-tabs { display:flex; gap:4px; border-bottom:1px solid #e5e7eb; margin-bottom:20px; overflow-x:auto; overflow-y:hidden; }
        .vr-tab { padding:12px 18px; font-size:14px; font-weight:600; color:#6b7280; border:none; background:none; border-bottom:2px solid transparent; cursor:pointer; white-space:nowrap; margin-bottom:-1px; }
        .vr-tab.on { color:var(--vr-primary); border-bottom-color:var(--vr-primary); }
        .vr-card { background:#fff; border:1px solid #e5e7eb; border-radius:16px; padding:20px; }
        .vr-row { display:flex; flex-wrap:wrap; gap:14px; align-items:flex-end; }
        .vr-field { display:flex; flex-direction:column; gap:6px; flex:1 1 200px; min-width:0; }
        .vr-field label, .vr-label { font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.03em; color:#6b7280; }
        .vr-input { width:100%; border:1px solid #d1d5db; border-radius:10px; padding:10px 12px; font-size:15px; background:#fff; color:#111827; font-family:inherit; }
        .vr-input:focus { outline:2px solid rgba(var(--vr-primary-rgb), .35); border-color:var(--vr-primary); }
        .vr-pills { display:flex; flex-wrap:wrap; gap:8px; }
        .vr-pill { border:1px solid #d1d5db; background:#fff; border-radius:999px; padding:8px 16px; font-size:14px; font-weight:600; color:#374151; cursor:pointer; }
        .vr-pill.on { background:var(--vr-primary); border-color:var(--vr-primary); color:var(--vr-on-primary); }
        .vr-btn { display:inline-flex; align-items:center; justify-content:center; gap:8px; border:none; border-radius:10px; padding:11px 20px; font-size:15px; font-weight:700; cursor:pointer; background:var(--vr-primary); color:var(--vr-on-primary); text-decoration:none; font-family:inherit; }
        .vr-btn:hover { filter:brightness(.95); }
        .vr-btn[disabled] { opacity:.55; cursor:not-allowed; }
        .vr-btn.ghost { background:#fff; color:#374151; border:1px solid #d1d5db; }
        .vr-btn.danger { background:#fff; color:#dc2626; border:1px solid #fecaca; }
        .vr-btn.sm { padding:7px 14px; font-size:13px; }
        .vr-note { font-size:13px; color:#6b7280; margin-top:12px; line-height:1.5; }
        .vr-err { background:#fef2f2; border:1px solid #fecaca; color:#b91c1c; border-radius:10px; padding:10px 14px; font-size:14px; margin-top:12px; }
        .vr-ok { background:#f0fdf4; border:1px solid #bbf7d0; color:#15803d; border-radius:10px; padding:10px 14px; font-size:14px; margin-top:12px; }
        .vr-info { background:#eff6ff; border:1px solid #bfdbfe; color:#1d4ed8; border-radius:10px; padding:10px 14px; font-size:14px; margin-top:12px; }
        .vr-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(300px, 1fr)); gap:18px; margin-top:20px; }
        .vr-vcard { background:#fff; border:1px solid #e5e7eb; border-radius:16px; overflow:hidden; display:flex; flex-direction:column; }
        .vr-vimg { aspect-ratio:16/10; background:#f3f4f6 center/cover no-repeat; display:flex; align-items:center; justify-content:center; color:#9ca3af; font-size:13px; }
        .vr-vbody { padding:16px; display:flex; flex-direction:column; gap:10px; flex:1; }
        .vr-vname { font-size:18px; font-weight:700; margin:0; }
        .vr-chips { display:flex; flex-wrap:wrap; gap:6px; }
        .vr-chip { font-size:12px; background:#f3f4f6; color:#374151; border-radius:999px; padding:3px 10px; }
        .vr-price { font-size:22px; font-weight:800; color:var(--vr-primary); }
        .vr-muted { color:#6b7280; font-size:13px; }
        .vr-badge { display:inline-block; font-size:12px; font-weight:700; border-radius:999px; padding:3px 10px; }
        .vr-badge.yellow { background:#fef3c7; color:#b45309; } .vr-badge.green { background:#dcfce7; color:#15803d; } .vr-badge.blue { background:#dbeafe; color:#1d4ed8; }
        .vr-badge.gray { background:#f3f4f6; color:#4b5563; } .vr-badge.red { background:#fee2e2; color:#b91c1c; }
        .vr-overlay { position:fixed; inset:0; background:rgba(17,24,39,.55); z-index:2100; display:flex; align-items:flex-start; justify-content:center; padding:24px 12px; overflow-y:auto; }
        .vr-modal { background:#fff; border-radius:18px; width:100%; max-width:760px; position:relative; }
        .vr-modal-body { padding:22px; }
        .vr-x { position:absolute; top:10px; right:10px; width:36px; height:36px; border-radius:50%; border:none; background:rgba(255,255,255,.9); font-size:22px; line-height:1; cursor:pointer; z-index:2; box-shadow:0 1px 4px rgba(0,0,0,.2); }
        .vr-gallery { aspect-ratio:16/9; background:#f3f4f6 center/cover no-repeat; border-radius:18px 18px 0 0; }
        .vr-thumbs { display:flex; gap:8px; padding:10px 22px 0; overflow-x:auto; }
        .vr-thumb { width:64px; height:44px; border-radius:8px; background:#f3f4f6 center/cover; border:2px solid transparent; cursor:pointer; flex-shrink:0; }
        .vr-thumb.on { border-color:var(--vr-primary); }
        .vr table { width:100%; border-collapse:collapse; font-size:13px; }
        .vr th { text-align:left; color:#6b7280; font-weight:600; padding:6px 8px; border-bottom:1px solid #e5e7eb; white-space:nowrap; }
        .vr td { padding:7px 8px; border-bottom:1px solid #f3f4f6; vertical-align:top; }
        .vr-scroll { overflow-x:auto; }
        .vr-sum { display:flex; justify-content:space-between; gap:12px; padding:5px 0; font-size:14px; }
        .vr-sum.total { border-top:1px solid #e5e7eb; margin-top:6px; padding-top:10px; font-size:17px; font-weight:800; }
        .vr-h3 { font-size:15px; font-weight:700; margin:18px 0 8px; }
        .vr-qr { width:240px; height:240px; max-width:100%; border:1px solid #e5e7eb; border-radius:12px; background:#fff; }
        .vr-order { border:1px solid #e5e7eb; border-radius:14px; padding:16px; background:#fff; display:flex; flex-direction:column; gap:8px; }
        .vr ul { list-style:disc; } .vr ol { list-style:decimal; }
        .vr-gallery.empty { aspect-ratio:auto; height:56px; }
        .vr-skel { height:260px; border-radius:16px; background:linear-gradient(90deg,#f3f4f6,#e5e7eb,#f3f4f6); background-size:200% 100%; animation:vr-sh 1.2s infinite; }
        @keyframes vr-sh { to { background-position:-200% 0; } }
        .vr-two { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
        .vr-spin { display:inline-block; width:16px; height:16px; border:2px solid currentColor; border-right-color:transparent; border-radius:50%; animation:vr-rot .7s linear infinite; }
        @keyframes vr-rot { to { transform:rotate(360deg); } }
        @media (max-width:640px) { .vr h1 { font-size:24px; } .vr-two { grid-template-columns:1fr; } .vr { padding:20px 12px 80px; } }
    </style>

    <div class="vr" x-data="vehicleRental(@js($rentalConfig))" x-init="init()" x-cloak>
        <div class="vr-wrap">
            <h1>Thuê xe tại 365 HOME</h1>
            <p class="vr-sub">Xe máy, ô tô tự lái — chọn thời gian, xem giá rõ ràng, thanh toán QR và nhận xe tại chi nhánh.</p>

            <div class="vr-tabs" role="tablist">
                <button type="button" class="vr-tab" :class="{ on: tab === 'search' }" @click="go('search')">Tìm xe</button>
                <button type="button" class="vr-tab" :class="{ on: tab === 'orders' }" @click="go('orders')">Đơn thuê xe của tôi</button>
                <button type="button" class="vr-tab" :class="{ on: tab === 'documents' }" @click="go('documents')">Giấy tờ lái xe</button>
            </div>

            {{-- ============================ TÌM XE ============================ --}}
            <div x-show="tab === 'search'">
                <form class="vr-card" @submit.prevent="search()">
                    <div class="vr-label" style="margin-bottom:8px;">Loại xe</div>
                    <div class="vr-pills" style="margin-bottom:16px;">
                        <button type="button" class="vr-pill" :class="{ on: type === '' }" @click="type = ''">Tất cả</button>
                        <button type="button" class="vr-pill" :class="{ on: type === 'motorbike' }" @click="type = 'motorbike'">Xe máy</button>
                        <button type="button" class="vr-pill" :class="{ on: type === 'car' }" @click="type = 'car'">Ô tô</button>
                    </div>
                    <div class="vr-row">
                        <div class="vr-field">
                            <label for="vr-start">Nhận xe</label>
                            <input id="vr-start" type="datetime-local" class="vr-input" x-model="start" :min="minStart()" required>
                        </div>
                        <div class="vr-field">
                            <label for="vr-end">Trả xe</label>
                            <input id="vr-end" type="datetime-local" class="vr-input" x-model="end" :min="start" required>
                        </div>
                        <button type="submit" class="vr-btn" :disabled="loading">
                            <span x-show="loading" class="vr-spin"></span>
                            <span>Tìm xe còn trống</span>
                        </button>
                    </div>
                    <div class="vr-pills" style="margin-top:14px;">
                        <span class="vr-muted" style="align-self:center;">Thuê nhanh:</span>
                        <template x-for="d in durations" :key="d.label">
                            <button type="button" class="vr-pill" style="padding:5px 12px; font-size:13px;" @click="setDuration(d.hours)" x-text="d.label"></button>
                        </template>
                    </div>
                    <p class="vr-note" x-show="start && end && !formError()">
                        Thời gian thuê: <strong x-text="durationText(start, end)"></strong> · Nhận xe tại vị trí hiện tại của xe, trả xe tại chi nhánh của 365 HOME.
                    </p>
                    <div class="vr-err" x-show="formError()" x-text="formError()"></div>
                </form>

                <div class="vr-err" x-show="error" x-text="error"></div>

                <div class="vr-grid" x-show="loading">
                    <div class="vr-skel"></div><div class="vr-skel"></div><div class="vr-skel"></div>
                </div>

                <div x-show="searched && !loading">
                    <p class="vr-note" x-show="results.length > 0" style="margin-top:20px;">
                        Có <strong x-text="results.length"></strong> xe còn trống từ <strong x-text="fmtDateTime(sStart)"></strong> đến <strong x-text="fmtDateTime(sEnd)"></strong>.
                    </p>
                    <div class="vr-card" x-show="results.length === 0 && !error" style="margin-top:20px; text-align:center; padding:40px 20px;">
                        <div style="font-size:17px; font-weight:700;">Chưa có xe phù hợp trong khung giờ này</div>
                        <p class="vr-muted" style="margin-top:6px;">Hãy thử đổi giờ nhận/trả, đổi loại xe hoặc rút ngắn thời gian thuê.</p>
                    </div>
                    <div class="vr-grid">
                        <template x-for="v in results" :key="v.id">
                            <article class="vr-vcard">
                                <div class="vr-vimg" :style="v.image ? `background-image:url('${v.image}')` : ''">
                                    <span x-show="!v.image" x-text="typeLabel(v.type)"></span>
                                </div>
                                <div class="vr-vbody">
                                    <h3 class="vr-vname" x-text="vehicleName(v)"></h3>
                                    <div class="vr-chips">
                                        <span class="vr-chip" x-text="typeLabel(v.type)"></span>
                                        <span class="vr-chip" x-show="v.seats" x-text="v.seats + ' chỗ'"></span>
                                        <span class="vr-chip" x-show="v.transmission" x-text="transmissionLabel(v.transmission)"></span>
                                        <span class="vr-chip" x-show="v.fuel" x-text="fuelLabel(v.fuel)"></span>
                                        <span class="vr-chip" x-show="v.color" x-text="v.color"></span>
                                        <span class="vr-chip" x-show="v.odometer_km != null" x-text="'Đã chạy ' + kmFmt(v.odometer_km)"></span>
                                    </div>
                                    <div class="vr-muted">Nhận xe tại: <strong x-text="pickupText(v.pickup_point)"></strong></div>
                                    <div class="vr-muted" x-show="v.branch && v.branch.name">Trả xe tại: <span x-text="v.branch.name"></span></div>
                                    <div style="margin-top:auto;">
                                        <div class="vr-price" x-text="v.quote ? money(v.quote.rental_amount) : money(v.km_quote && v.km_quote.rental_amount)"></div>
                                        <div class="vr-muted" x-text="v.quote ? kmText(v.quote) : 'Ước tính ' + (v.km_quote ? v.km_quote.estimated_km : '') + ' km · tính theo km thực tế'"></div>
                                        <div class="vr-muted" x-show="v.km_plan"><strong x-text="v.quote ? 'Hoặc thuê theo km: ' : 'Giá theo km: '"></strong><span x-text="money(v.km_plan && v.km_plan.price_per_km) + '/km' + (v.km_plan && v.km_plan.min_km ? ' (tối thiểu ' + v.km_plan.min_km + ' km)' : '')"></span></div>
                                        <div class="vr-muted" x-show="v.deposit_amount > 0">Cọc thế chấp <span x-text="money(v.deposit_amount)"></span> (thu khi nhận xe)</div>
                                    </div>
                                    <button type="button" class="vr-btn" @click="openVehicle(v)">Xem chi tiết &amp; đặt xe</button>
                                </div>
                            </article>
                        </template>
                    </div>
                </div>

                <div class="vr-card" x-show="!searched && !loading" style="margin-top:20px;">
                    <div class="vr-h3" style="margin-top:0;">Thuê xe tại 365 HOME hoạt động thế nào?</div>
                    <ol style="margin:0; padding-left:20px; line-height:1.8; color:#374151; font-size:14px;">
                        <li>Chọn loại xe và thời gian nhận/trả, hệ thống hiện các xe còn trống kèm giá trọn gói.</li>
                        <li>Đặt xe và thanh toán bằng mã QR — xe được giữ cho bạn trong <span x-text="cfg.hold_minutes"></span> phút trong lúc chờ thanh toán.</li>
                        <li>Nộp trước giấy tờ (CCCD, bằng lái) ở tab "Giấy tờ lái xe" để nhận xe nhanh hơn.</li>
                        <li>Đến chi nhánh đúng giờ, xuất trình giấy tờ và nộp cọc thế chấp (hoàn lại sau khi trả xe).</li>
                    </ol>
                </div>
            </div>

            {{-- ============================ ĐƠN CỦA TÔI ============================ --}}
            <div x-show="tab === 'orders'">
                <div class="vr-card" x-show="!authed" style="text-align:center; padding:36px 20px;">
                    <div style="font-size:17px; font-weight:700;">Đăng nhập để xem đơn thuê xe của bạn</div>
                    <p class="vr-muted" style="margin:6px 0 16px;">Đơn thuê xe gắn với tài khoản đã đặt xe.</p>
                    <a class="vr-btn" :href="loginUrl()">Đăng nhập</a>
                </div>

                <div x-show="authed">
                    <div class="vr-err" x-show="ordersError" x-text="ordersError"></div>
                    <div class="vr-ok" x-show="ordersMsg" x-text="ordersMsg"></div>
                    <div class="vr-grid" x-show="ordersLoading"><div class="vr-skel" style="height:140px"></div><div class="vr-skel" style="height:140px"></div></div>
                    <div class="vr-card" x-show="!ordersLoading && !ordersError && orders.length === 0" style="text-align:center; padding:36px 20px;">
                        <div style="font-size:17px; font-weight:700;">Bạn chưa có đơn thuê xe nào</div>
                        <button type="button" class="vr-btn" style="margin-top:14px;" @click="go('search')">Tìm xe ngay</button>
                    </div>
                    <div style="display:grid; gap:14px;">
                        <template x-for="o in orders" :key="o.code">
                            <div class="vr-order">
                                <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:center;">
                                    <strong x-text="'#' + o.code"></strong>
                                    <span class="vr-badge" :class="statusInfo(o.status).color" x-text="statusInfo(o.status).label"></span>
                                </div>
                                <div style="font-weight:700; font-size:16px;" x-text="vehicleName(o.vehicle)"></div>
                                <div class="vr-muted"><span x-text="fmtDateTime(o.start_at)"></span> → <span x-text="fmtDateTime(o.end_at)"></span> (<span x-text="durationText(o.start_at, o.end_at)"></span>)</div>
                                <div class="vr-muted">Nhận xe tại: <strong x-text="pickupText(o.pickup_point)"></strong> <a x-show="o.pickup_point && o.pickup_point.maps_url" :href="o.pickup_point && o.pickup_point.maps_url" target="_blank" rel="noopener" style="color:var(--vr-primary);">Xem bản đồ</a></div>
                                <div class="vr-muted" x-show="o.vehicle && o.vehicle.branch && o.vehicle.branch.name">Trả xe tại: <span x-text="o.vehicle.branch.name"></span></div>
                                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                                    <div><span class="vr-muted">Tổng tiền thuê</span> <strong x-text="money(o.total_amount)"></strong></div>
                                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                                        <button type="button" class="vr-btn ghost sm" @click="openOrder(o.code)">Chi tiết</button>
                                        <button type="button" class="vr-btn sm" x-show="canPay(o)" @click="resumePayment(o.code)">Thanh toán</button>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            {{-- ============================ GIẤY TỜ ============================ --}}
            <div x-show="tab === 'documents'">
                <div class="vr-card" x-show="!authed" style="text-align:center; padding:36px 20px;">
                    <div style="font-size:17px; font-weight:700;">Đăng nhập để nộp giấy tờ lái xe</div>
                    <p class="vr-muted" style="margin:6px 0 16px;">Giấy tờ gắn với tài khoản của bạn và dùng cho mọi lần thuê xe.</p>
                    <a class="vr-btn" :href="loginUrl()">Đăng nhập</a>
                </div>

                <div class="vr-card" x-show="authed">
                    <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:center;">
                        <div style="font-size:18px; font-weight:800;">Giấy tờ thuê xe</div>
                        <span class="vr-badge" :class="docStatus().color" x-text="docStatus().label"></span>
                    </div>
                    <p class="vr-note" style="margin-top:8px;">
                        Khi nhận xe, bạn cần xuất trình CCCD và giấy phép lái xe phù hợp loại xe (hạng A1/A2 cho xe máy, B1/B2 trở lên cho ô tô) còn hạn.
                        Nộp trước giấy tờ để 365 HOME duyệt sớm — đơn của bạn sẽ được giao xe nhanh hơn.
                    </p>
                    <div class="vr-err" x-show="doc && doc.status === 'rejected'">
                        Giấy tờ bị từ chối: <span x-text="doc && doc.reject_reason ? doc.reject_reason : 'Vui lòng nộp lại giấy tờ rõ nét, đúng thông tin.'"></span>
                    </div>
                    <div class="vr-info" x-show="doc && doc.status === 'pending'">Giấy tờ đang chờ nhân viên duyệt. Bạn có thể nộp lại nếu cần chỉnh sửa.</div>
                    <div class="vr-ok" x-show="doc && doc.status === 'approved'">Giấy tờ đã được duyệt. Nếu thay đổi thông tin hoặc ảnh, giấy tờ sẽ cần duyệt lại.</div>

                    <form @submit.prevent="submitDoc()" style="margin-top:16px;">
                        <div class="vr-two">
                            <div class="vr-field"><label>Họ và tên (theo CCCD)</label><input class="vr-input" x-model="docForm.full_name" maxlength="150" autocomplete="name"></div>
                            <div class="vr-field"><label>Số CCCD</label><input class="vr-input" x-model="docForm.id_number" maxlength="30" inputmode="numeric"></div>
                            <div class="vr-field"><label>Số giấy phép lái xe</label><input class="vr-input" x-model="docForm.license_number" maxlength="30"></div>
                            <div class="vr-field"><label>Hạng bằng</label>
                                <select class="vr-input" x-model="docForm.license_class">
                                    <option value="">— Chọn hạng —</option>
                                    <template x-for="c in licenseClasses" :key="c"><option :value="c" x-text="c"></option></template>
                                </select>
                            </div>
                            <div class="vr-field"><label>Ngày hết hạn bằng</label><input type="date" class="vr-input" x-model="docForm.license_expires_on"></div>
                        </div>
                        <div class="vr-h3">Ảnh giấy tờ <span class="vr-muted" style="font-weight:400;">(JPG/PNG, tối đa 8MB mỗi ảnh)</span></div>
                        <div class="vr-two">
                            <template x-for="f in imageFields" :key="f.key">
                                <div class="vr-field">
                                    <label x-text="f.label"></label>
                                    <input type="file" accept="image/*" class="vr-input" @change="docFiles[f.key] = $event.target.files[0] || null">
                                    <span class="vr-muted" x-show="doc && doc.has_images && doc.has_images[f.key] && !docFiles[f.key]">Đã có ảnh — chọn ảnh mới nếu muốn thay.</span>
                                </div>
                            </template>
                        </div>
                        <div class="vr-err" x-show="docError" x-text="docError"></div>
                        <div class="vr-ok" x-show="docMsg" x-text="docMsg"></div>
                        <button type="submit" class="vr-btn" style="margin-top:16px;" :disabled="docBusy">
                            <span x-show="docBusy" class="vr-spin"></span>
                            <span x-text="doc ? 'Nộp lại giấy tờ' : 'Gửi giấy tờ'"></span>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        {{-- ============================ MODAL XE / ĐẶT XE / THANH TOÁN ============================ --}}
        <div class="vr-overlay" x-show="modal" x-cloak @keydown.escape.window="closeModal()" @click.self="closeModal()" style="display:none;">
            <div class="vr-modal" @click.stop>
                <button type="button" class="vr-x" @click="closeModal()" aria-label="Đóng">&times;</button>

                {{-- Bước 1: chi tiết xe --}}
                <div x-show="step === 'detail' && sel">
                    <div class="vr-gallery" :class="{ empty: !gallery.length }" :style="gallery[galleryIdx] ? `background-image:url('${gallery[galleryIdx]}')` : ''"></div>
                    <div class="vr-thumbs" x-show="gallery.length > 1">
                        <template x-for="(g, i) in gallery" :key="i">
                            <div class="vr-thumb" :class="{ on: i === galleryIdx }" :style="`background-image:url('${g}')`" @click="galleryIdx = i"></div>
                        </template>
                    </div>
                    <div class="vr-modal-body" x-show="sel">
                        <h2 style="font-size:24px; font-weight:800; margin:0 0 8px;" x-text="vehicleName(sel)"></h2>
                        <div class="vr-chips">
                            <span class="vr-chip" x-text="typeLabel(sel && sel.type)"></span>
                            <span class="vr-chip" x-show="sel && sel.seats" x-text="(sel && sel.seats) + ' chỗ'"></span>
                            <span class="vr-chip" x-show="sel && sel.transmission" x-text="transmissionLabel(sel && sel.transmission)"></span>
                            <span class="vr-chip" x-show="sel && sel.fuel" x-text="fuelLabel(sel && sel.fuel)"></span>
                            <span class="vr-chip" x-show="sel && sel.color" x-text="sel && sel.color"></span>
                            <span class="vr-chip" x-show="sel && sel.plate" x-text="'Biển số ' + (sel && sel.plate)"></span>
                            <span class="vr-chip" x-show="sel && sel.odometer_km != null" x-text="'Số km hiện tại ' + kmFmt(sel && sel.odometer_km)"></span>
                        </div>
                        <p style="margin:12px 0 0; color:#374151; line-height:1.6; white-space:pre-line;" x-show="sel && sel.description" x-text="sel && sel.description"></p>
                        <div class="vr-muted" style="margin-top:10px;">Nhận xe tại vị trí hiện tại của xe: <strong x-text="pickupText(sel && sel.pickup_point)"></strong></div>
                        <div class="vr-muted" x-show="sel && sel.branch && sel.branch.name">Trả xe tại chi nhánh: <strong x-text="sel && sel.branch && sel.branch.name"></strong></div>

                        <div class="vr-h3">Thời gian thuê</div>
                        <div class="vr-row">
                            <div class="vr-field"><label>Nhận xe</label><input type="datetime-local" class="vr-input" x-model="start" :min="minStart()" @change="requote()"></div>
                            <div class="vr-field"><label>Trả xe</label><input type="datetime-local" class="vr-input" x-model="end" :min="start" @change="requote()"></div>
                        </div>
                        <div class="vr-err" x-show="formError()" x-text="formError()"></div>
                        <div class="vr-err" x-show="detailError" x-text="detailError"></div>
                        <div class="vr-err" x-show="detailAvail === false && !formError()">Xe này không còn trống trong khung giờ bạn chọn. Hãy đổi giờ hoặc chọn xe khác.</div>

                        <div class="vr-h3" x-show="sel && sel.km_plan">Cách tính tiền</div>
                        <div class="vr-pills" x-show="sel && sel.km_plan">
                            <button type="button" class="vr-pill" :class="{ on: mode === 'time' }" x-show="detailQuote" @click="mode = 'time'">Theo thời gian (giờ/ngày)</button>
                            <button type="button" class="vr-pill" :class="{ on: mode === 'km' }" @click="mode = 'km'">Theo km</button>
                        </div>
                        <div class="vr-field" style="margin-top:12px; max-width:320px;" x-show="mode === 'km' && sel && sel.km_plan">
                            <label>Số km dự kiến sẽ đi</label>
                            <input type="number" class="vr-input" min="1" max="100000" x-model.number="estKm" @change="requote()" inputmode="numeric">
                            <span class="vr-muted" x-text="'Giá ' + money(sel && sel.km_plan && sel.km_plan.price_per_km) + '/km' + (sel && sel.km_plan && sel.km_plan.min_km ? ' · tối thiểu ' + sel.km_plan.min_km + ' km' : '')"></span>
                        </div>
                        <div class="vr-card" style="margin-top:14px; background:#f9fafb;" x-show="activeQuote() && !formError()">
                            <div style="font-weight:700; margin-bottom:6px;" x-text="'Báo giá ' + durationText(start, end)"></div>
                            <template x-for="(l, i) in (activeQuote() ? activeQuote().lines : [])" :key="i">
                                <div class="vr-sum"><span x-text="l.label"></span><span x-text="money(l.amount)"></span></div>
                            </template>
                            <div class="vr-sum total"><span x-text="mode === 'km' ? 'Tạm tính (thanh toán trước)' : 'Tiền thuê'"></span><span x-text="money(activeQuote() && activeQuote().rental_amount)"></span></div>
                            <div class="vr-muted" x-show="mode !== 'km'" x-text="kmText(activeQuote())"></div>
                            <div class="vr-muted" x-show="mode === 'km'">Tiền cuối cùng chốt theo số km thực tế lúc trả xe: đi nhiều hơn thì thanh toán thêm (trừ vào cọc), đi ít hơn thì được hoàn lại phần dư.</div>
                        </div>
                        <div class="vr-info" x-show="!activeQuote() && !formError() && !detailLoading && detailAvail !== false">Chưa có gói giá phù hợp với thời lượng này. Hãy thử thời gian thuê khác.</div>

                        <div class="vr-h3" x-show="sel && sel.rate_plans && sel.rate_plans.length">Bảng giá</div>
                        <div class="vr-scroll" x-show="sel && sel.rate_plans && sel.rate_plans.length">
                            <table>
                                <thead><tr><th>Gói</th><th>Giá</th><th>Cuối tuần</th><th>Km gồm</th><th>Km vượt</th><th>Quá giờ</th></tr></thead>
                                <tbody>
                                    <template x-for="p in (sel ? sel.rate_plans || [] : [])" :key="p.id">
                                        <tr>
                                            <td x-text="p.name"></td>
                                            <td><span x-text="money(p.price)"></span><span class="vr-muted" x-text="p.unit === 'km' ? ' /km' : (p.unit === 'day' ? ' /ngày' : ' /' + p.duration_hours + ' giờ')"></span></td>
                                            <td x-text="p.weekend_price ? money(p.weekend_price) : '—'"></td>
                                            <td x-text="p.unit === 'km' ? (p.min_km ? 'Tối thiểu ' + p.min_km + ' km' : 'Theo km thực tế') : (p.included_km == null ? 'Không giới hạn' : p.included_km + ' km')"></td>
                                            <td x-text="p.unit === 'km' || p.included_km == null ? '—' : money(p.extra_km_price) + '/km'"></td>
                                            <td x-text="p.overtime_hour_price ? money(p.overtime_hour_price) + '/giờ' : '—'"></td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>

                        <div class="vr-h3">Điều kiện &amp; chính sách</div>
                        <ul style="margin:0; padding-left:20px; color:#374151; font-size:14px; line-height:1.7;">
                            <li x-show="sel && sel.deposit_amount > 0">Cọc thế chấp <strong x-text="money(sel && sel.deposit_amount)"></strong> thu trực tiếp tại chi nhánh khi nhận xe, hoàn lại sau khi kiểm tra xe lúc trả.</li>
                            <li>Xe được giao tại vị trí hiện tại của xe (theo GPS); vị trí có thể thay đổi trước giờ nhận, địa điểm chính xác luôn xem được trong đơn của bạn. Trả xe tại chi nhánh.</li>
                            <li>Xuất trình CCCD và giấy phép lái xe còn hạn khi nhận xe.</li>
                            <li x-show="sel && sel.km_plan">Thuê theo km: thanh toán trước theo số km dự kiến, tiền cuối cùng tính theo số km thực tế (đồng hồ lúc nhận và lúc trả xe).</li>
                            <li>Chưa thanh toán: xe được giữ <span x-text="cfg.hold_minutes"></span> phút, quá hạn đơn tự huỷ.</li>
                            <li x-text="cancelPolicyText()"></li>
                        </ul>

                        <div style="display:flex; gap:10px; margin-top:20px; flex-wrap:wrap;">
                            <button type="button" class="vr-btn" :disabled="!canBook()" @click="toConfirm()">Đặt xe này</button>
                            <button type="button" class="vr-btn ghost" @click="closeModal()">Đóng</button>
                        </div>
                    </div>
                </div>

                {{-- Bước 2: xác nhận --}}
                <div class="vr-modal-body" x-show="step === 'confirm' && sel" style="padding-top:40px;">
                    <h2 style="font-size:22px; font-weight:800; margin:0 0 12px;">Xác nhận đặt xe</h2>
                    <div class="vr-card" style="background:#f9fafb;">
                        <div style="font-weight:700; font-size:17px;" x-text="vehicleName(sel)"></div>
                        <div class="vr-muted" style="margin:4px 0 10px;"><span x-text="fmtDateTime(start)"></span> → <span x-text="fmtDateTime(end)"></span> (<span x-text="durationText(start, end)"></span>)</div>
                        <div class="vr-muted">Nhận xe tại: <span x-text="pickupText(sel && sel.pickup_point)"></span></div>
                        <div class="vr-muted" x-show="sel && sel.branch && sel.branch.name">Trả xe tại chi nhánh <span x-text="sel && sel.branch && sel.branch.name"></span></div>
                        <div class="vr-muted" x-show="mode === 'km'">Thuê theo km: ước tính <strong x-text="estKm"></strong> km × <span x-text="money(sel && sel.km_plan && sel.km_plan.price_per_km)"></span>/km — chốt theo km thực tế lúc trả xe.</div>
                        <div class="vr-sum total"><span>Thanh toán ngay</span><span x-text="money(activeQuote() && activeQuote().rental_amount)"></span></div>
                        <div class="vr-muted" x-show="sel && sel.deposit_amount > 0">+ Cọc thế chấp <span x-text="money(sel && sel.deposit_amount)"></span> thu tại quầy khi nhận xe.</div>
                    </div>
                    <div class="vr-info" x-show="doc === null || (doc && doc.status !== 'approved')">
                        Bạn chưa có giấy tờ được duyệt. Vẫn có thể đặt xe, nhưng nên nộp giấy tờ ở tab "Giấy tờ lái xe" trước giờ nhận để được giao xe.
                    </div>
                    <div class="vr-err" x-show="bookError" x-text="bookError"></div>
                    <div style="display:flex; gap:10px; margin-top:18px; flex-wrap:wrap;">
                        <button type="button" class="vr-btn" :disabled="bookBusy" @click="book()"><span x-show="bookBusy" class="vr-spin"></span><span>Xác nhận &amp; thanh toán</span></button>
                        <button type="button" class="vr-btn ghost" :disabled="bookBusy" @click="step = 'detail'">Quay lại</button>
                    </div>
                </div>

                {{-- Bước 3: thanh toán --}}
                <div class="vr-modal-body" x-show="step === 'pay' && booking" style="padding-top:40px; text-align:center;">
                    <h2 style="font-size:22px; font-weight:800; margin:0 0 4px;">Thanh toán đơn <span x-text="booking && ('#' + booking.code)"></span></h2>
                    <div class="vr-muted" x-text="booking && vehicleName(booking.vehicle)"></div>
                    <div class="vr-price" style="margin:10px 0;" x-text="money(booking && booking.total_amount)"></div>
                    <template x-if="booking && booking.payment && booking.payment.qr_code">
                        <img class="vr-qr" :src="qrSrc(booking.payment.qr_code)" alt="Mã QR thanh toán PayOS">
                    </template>
                    <div class="vr-err" x-show="payError" x-text="payError" style="text-align:left;"></div>
                    <p class="vr-note" x-show="booking && booking.pickup_point">Nhận xe tại: <strong x-text="booking && pickupText(booking.pickup_point)"></strong></p>
                    <p class="vr-note" x-show="booking && booking.payment && booking.payment.qr_code">Mở app ngân hàng, quét mã QR để thanh toán. Hệ thống tự cập nhật khi nhận được tiền.</p>
                    <div style="margin:10px 0; font-weight:700;" :style="remainingSec < 120 ? 'color:#dc2626' : ''" x-show="remainingSec > 0">
                        Giữ xe còn <span x-text="countdownText()"></span>
                    </div>
                    <div class="vr-err" x-show="remainingSec <= 0 && booking && booking.status === 'pending_payment'" style="text-align:left;">Đơn đã hết thời gian giữ xe. Vui lòng đặt lại.</div>
                    <div style="display:flex; gap:10px; justify-content:center; flex-wrap:wrap; margin-top:12px;">
                        <a class="vr-btn" x-show="booking && booking.payment && booking.payment.checkout_url && remainingSec > 0" :href="booking && booking.payment && booking.payment.checkout_url" target="_blank" rel="noopener">Mở trang thanh toán</a>
                        <button type="button" class="vr-btn ghost" x-show="remainingSec > 0 && !(booking && booking.payment && booking.payment.qr_code)" :disabled="payBusy" @click="refreshPayment()">Tạo lại mã QR</button>
                        <button type="button" class="vr-btn ghost" @click="closeModal(); go('orders')">Xem đơn của tôi</button>
                    </div>
                </div>

                {{-- Bước 4: xong --}}
                <div class="vr-modal-body" x-show="step === 'done'" style="padding-top:40px; text-align:center;">
                    <div style="font-size:48px;">✅</div>
                    <h2 style="font-size:22px; font-weight:800; margin:8px 0;">Thanh toán thành công</h2>
                    <p class="vr-muted">Đơn <strong x-text="booking && ('#' + booking.code)"></strong> đã được xác nhận. Hãy đến chi nhánh đúng giờ nhận xe, mang theo CCCD và giấy phép lái xe.</p>
                    <div style="display:flex; gap:10px; justify-content:center; margin-top:16px; flex-wrap:wrap;">
                        <button type="button" class="vr-btn" @click="closeModal(); go('orders')">Xem đơn của tôi</button>
                        <button type="button" class="vr-btn ghost" @click="closeModal(); go('documents')">Nộp giấy tờ</button>
                    </div>
                </div>

                {{-- Chi tiết đơn --}}
                <div class="vr-modal-body" x-show="step === 'order' && order" style="padding-top:40px;">
                    <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:center;">
                        <h2 style="font-size:22px; font-weight:800; margin:0;" x-text="order && ('Đơn #' + order.code)"></h2>
                        <span class="vr-badge" :class="order ? statusInfo(order.status).color : 'gray'" x-text="order ? statusInfo(order.status).label : ''"></span>
                    </div>
                    <div style="font-weight:700; margin-top:10px;" x-text="order && vehicleName(order.vehicle)"></div>
                    <div class="vr-muted" x-text="order && order.vehicle && order.vehicle.plate ? 'Biển số ' + order.vehicle.plate : ''"></div>
                    <div class="vr-muted" style="margin-top:6px;" x-show="order && order.pricing_mode === 'km'">Thuê theo km: ước tính <strong x-text="order && order.estimated_km"></strong> km × <span x-text="order && money(order.price_per_km)"></span>/km<span x-show="order && order.km_driven != null"> · thực tế đã đi <strong x-text="order && order.km_driven"></strong> km</span></div>
                    <div class="vr-muted" style="margin-top:6px;">Nhận xe: <strong x-text="order && fmtDateTime(order.start_at)"></strong></div>
                    <div class="vr-muted">Trả xe: <strong x-text="order && fmtDateTime(order.end_at)"></strong></div>
                    <div class="vr-muted">Nhận xe tại: <strong x-text="order && pickupText(order.pickup_point)"></strong> <a x-show="order && order.pickup_point && order.pickup_point.maps_url" :href="order && order.pickup_point && order.pickup_point.maps_url" target="_blank" rel="noopener" style="color:var(--vr-primary);">Xem bản đồ</a></div>
                    <div class="vr-muted" x-show="order && order.pickup_point && order.pickup_point.live">Vị trí xe được cập nhật theo thời gian thực cho tới lúc nhận xe.</div>
                    <div class="vr-muted" x-show="order && order.vehicle && order.vehicle.branch && order.vehicle.branch.name">Trả xe tại chi nhánh: <strong x-text="order && order.vehicle && order.vehicle.branch && order.vehicle.branch.name"></strong></div>
                    <div class="vr-muted" x-show="order && order.room_order_code">Thuê kèm đơn phòng <strong x-text="order && order.room_order_code"></strong></div>
                    <div class="vr-muted" x-show="order && order.odometer_start_km != null">Số km <span x-text="order && order.actual_start_at ? 'lúc nhận xe' : 'hiện tại của xe (chốt chính xác khi giao xe)'"></span>: <strong x-text="order && kmFmt(order.odometer_start_km)"></strong><span x-show="order && order.odometer_end_km != null"> · lúc trả: <strong x-text="order && kmFmt(order.odometer_end_km)"></strong></span></div>
                    <div class="vr-muted" x-show="order && order.actual_start_at">Đã nhận xe lúc <span x-text="order && fmtDateTime(order.actual_start_at)"></span></div>
                    <div class="vr-muted" x-show="order && order.actual_end_at">Đã trả xe lúc <span x-text="order && fmtDateTime(order.actual_end_at)"></span><span x-show="order && order.km_driven != null"> · đã đi <span x-text="order && order.km_driven"></span> km</span></div>

                    <div class="vr-card" style="margin-top:14px; background:#f9fafb;">
                        <div class="vr-sum"><span>Tiền thuê</span><span x-text="money(order && order.rental_amount)"></span></div>
                        <div class="vr-sum" x-show="order && order.discount_amount > 0"><span>Giảm giá</span><span x-text="'-' + money(order && order.discount_amount)"></span></div>
                        <template x-for="c in (order ? order.charges || [] : [])" :key="c.id">
                            <div class="vr-sum"><span x-text="c.label"></span><span x-text="money(c.amount)"></span></div>
                        </template>
                        <div class="vr-sum total"><span>Tổng cộng</span><span x-text="money(order && order.total_amount)"></span></div>
                        <div class="vr-sum" x-show="order && order.paid_amount > 0"><span>Đã thanh toán</span><span x-text="money(order && order.paid_amount)"></span></div>
                        <div class="vr-sum" x-show="order && order.extra_due_amount > 0" style="color:#b45309;"><span>Còn phải trả thêm</span><span x-text="money(order && order.extra_due_amount)"></span></div>
                        <div class="vr-sum" x-show="order && order.deposit_amount > 0"><span>Cọc thế chấp (thu tại quầy)</span><span x-text="money(order && order.deposit_amount)"></span></div>
                        <div class="vr-sum" x-show="order && order.deposit_refund_amount > 0"><span>Cọc hoàn lại</span><span x-text="money(order && order.deposit_refund_amount)"></span></div>
                        <div class="vr-sum" x-show="order && order.deposit_deducted_amount > 0"><span>Cọc bị trừ</span><span x-text="money(order && order.deposit_deducted_amount)"></span></div>
                        <div class="vr-sum" x-show="order && order.refund_amount > 0"><span>Đã hoàn tiền thuê</span><span x-text="money(order && order.refund_amount)"></span></div>
                    </div>

                    <div class="vr-info" x-show="order && order.kyc_blocker" x-text="order && order.kyc_blocker"></div>
                    <div class="vr-muted" style="margin-top:10px;" x-show="order && order.status === 'cancelled' && order.cancel_reason">Lý do huỷ: <span x-text="order && order.cancel_reason"></span></div>
                    <div class="vr-err" x-show="orderError" x-text="orderError"></div>

                    <div style="display:flex; gap:10px; margin-top:18px; flex-wrap:wrap;">
                        <button type="button" class="vr-btn" x-show="order && canPay(order)" @click="resumePayment(order.code)">Thanh toán</button>
                        <button type="button" class="vr-btn danger" x-show="order && order.can_cancel" :disabled="orderBusy" @click="cancelOrder()">Huỷ đơn</button>
                        <button type="button" class="vr-btn ghost" @click="closeModal()">Đóng</button>
                    </div>
                    <p class="vr-note" x-show="order && order.can_cancel && order.paid_at && order.refund_percent_if_cancelled_now != null">
                        Nếu huỷ ngay bây giờ, bạn được hoàn <strong x-text="order && order.refund_percent_if_cancelled_now"></strong>% tiền thuê.
                    </p>
                    <p class="vr-note" x-show="order && order.can_cancel && !order.paid_at">Đơn chưa thanh toán — huỷ không mất phí.</p>
                </div>
            </div>
        </div>
    </div>

    @livewire('bladethemev1::footer')
    @livewire('bladethemev1::contact-link')
    @livewire('bladethemev1::notification')

    <script>
        function vehicleRental(cfg) {
            const STATUS = {
                pending_payment: { label: 'Chờ thanh toán', color: 'yellow' },
                confirmed: { label: 'Đã xác nhận', color: 'blue' },
                picked_up: { label: 'Đang thuê', color: 'blue' },
                returned: { label: 'Đã trả xe', color: 'green' },
                settled: { label: 'Hoàn tất', color: 'green' },
                cancelled: { label: 'Đã huỷ', color: 'gray' },
                no_show: { label: 'Không đến nhận', color: 'red' },
            };
            const pad = (n) => String(n).padStart(2, '0');
            // Giờ địa phương dạng "YYYY-MM-DDTHH:mm" cho <input type=datetime-local>.
            const toLocalInput = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;

            return {
                cfg,
                tab: 'search', type: '', start: '', end: '',
                durations: [
                    { label: '4 giờ', hours: 4 }, { label: '8 giờ', hours: 8 }, { label: '1 ngày', hours: 24 },
                    { label: '2 ngày', hours: 48 }, { label: '3 ngày', hours: 72 }, { label: '1 tuần', hours: 168 },
                ],
                loading: false, searched: false, error: '', results: [], sStart: '', sEnd: '',
                authed: false,
                // modal
                modal: false, step: 'detail', sel: null, galleryIdx: 0, gallery: [], detailQuote: null, detailKmQuote: null, mode: 'time', estKm: 50, detailAvail: null, detailLoading: false, detailError: '',
                bookBusy: false, bookError: '', booking: null, payError: '', payBusy: false, remainingSec: 0, timer: null, poller: null,
                // đơn
                orders: [], ordersLoading: false, ordersError: '', ordersMsg: '', order: null, orderError: '', orderBusy: false,
                // giấy tờ
                doc: undefined, docForm: { full_name: '', id_number: '', license_number: '', license_class: '', license_expires_on: '' }, docFiles: {}, docBusy: false, docError: '', docMsg: '',
                licenseClasses: ['A1', 'A2', 'A3', 'A4', 'B1', 'B2', 'C', 'D', 'E', 'F'],
                imageFields: [
                    { key: 'id_front', label: 'CCCD — mặt trước' }, { key: 'id_back', label: 'CCCD — mặt sau' },
                    { key: 'license_front', label: 'Bằng lái — mặt trước' }, { key: 'license_back', label: 'Bằng lái — mặt sau' },
                    { key: 'selfie', label: 'Ảnh chân dung (selfie)' },
                ],

                init() {
                    this.refreshAuth();
                    window.addEventListener('auth-state-changed', () => { this.refreshAuth(); });
                    const p = new URLSearchParams(window.location.search);
                    const tab = p.get('tab');
                    if (['search', 'orders', 'documents'].includes(tab)) this.tab = tab;
                    this.setDefaults();
                    if (p.get('type') === 'car' || p.get('type') === 'motorbike') this.type = p.get('type');
                    if (p.get('start') && p.get('end')) { this.start = p.get('start'); this.end = p.get('end'); }
                    if (this.tab === 'orders') this.loadOrders();
                    if (this.tab === 'documents') this.loadDoc();
                    // Quay lại sau khi đăng nhập: tìm lại và mở đúng xe đang xem dở.
                    if (this.tab === 'search' && p.get('start') && p.get('end')) {
                        this.search().then(() => {
                            const id = parseInt(p.get('vehicle') || '0', 10);
                            const v = this.results.find((x) => x.id === id);
                            if (v) this.openVehicle(v);
                        });
                    }
                },

                // ---------- tiện ích ----------
                token() { try { return localStorage.getItem('auth_token') || ''; } catch (e) { return ''; } },
                refreshAuth() {
                    let user = null;
                    try { user = localStorage.getItem('auth_user'); } catch (e) {}
                    this.authed = !!(this.token() && user);
                },
                loginUrl() {
                    const q = new URLSearchParams({ tab: this.tab });
                    return '{{ route('login.page') }}?redirect=' + encodeURIComponent('/thue-xe?' + q.toString());
                },
                loginUrlForBooking() {
                    const q = new URLSearchParams({ tab: 'search', start: this.start, end: this.end });
                    if (this.type) q.set('type', this.type);
                    if (this.sel) q.set('vehicle', this.sel.id);
                    return '{{ route('login.page') }}?redirect=' + encodeURIComponent('/thue-xe?' + q.toString());
                },
                async api(method, url, body, isForm) {
                    const headers = { Accept: 'application/json' };
                    const t = this.token();
                    if (t) headers.Authorization = 'Bearer ' + t;
                    const opts = { method, headers };
                    if (body) {
                        if (isForm) opts.body = body;
                        else { headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
                    }
                    let res;
                    try { res = await fetch(url, opts); } catch (e) { throw { status: 0, message: 'Không kết nối được máy chủ. Vui lòng kiểm tra mạng và thử lại.' }; }
                    let json = null;
                    try { json = await res.json(); } catch (e) {}
                    if (res.status === 401) {
                        try { localStorage.removeItem('auth_token'); localStorage.removeItem('auth_user'); } catch (e) {}
                        this.authed = false;
                        window.dispatchEvent(new Event('auth-state-changed'));
                        throw { status: 401, message: 'Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại.' };
                    }
                    if (!res.ok) {
                        let msg = json && json.message ? json.message : 'Có lỗi xảy ra (mã ' + res.status + '). Vui lòng thử lại.';
                        if (json && json.errors) { const first = Object.values(json.errors)[0]; if (first && first[0]) msg = first[0]; }
                        if (res.status === 429) msg = 'Bạn thao tác quá nhanh, vui lòng thử lại sau ít giây.';
                        throw { status: res.status, message: msg };
                    }
                    return json;
                },
                money(n) { return (n == null || isNaN(n)) ? '—' : Number(n).toLocaleString('vi-VN') + 'đ'; },
                fmtDateTime(v) {
                    if (!v) return '—';
                    const d = new Date(v);
                    if (isNaN(d)) return '—';
                    return d.toLocaleString('vi-VN', { timeZone: 'Asia/Ho_Chi_Minh', hour: '2-digit', minute: '2-digit', day: '2-digit', month: '2-digit', year: 'numeric' });
                },
                durationText(a, b) {
                    const ms = new Date(b) - new Date(a);
                    if (!(ms > 0)) return '—';
                    const mins = Math.round(ms / 60000);
                    const d = Math.floor(mins / 1440), h = Math.floor((mins % 1440) / 60), m = mins % 60;
                    return [d ? d + ' ngày' : '', h ? h + ' giờ' : '', (m && !d) ? m + ' phút' : ''].filter(Boolean).join(' ') || '—';
                },
                kmFmt(n) { return n == null ? '—' : Number(n).toLocaleString('vi-VN') + ' km'; },
                pickupText(p) {
                    if (!p) return 'Đang cập nhật';
                    if (p.address) return p.address;
                    return p.source === 'vehicle' ? 'Vị trí hiện tại của xe' : 'Chi nhánh 365 HOME';
                },
                typeLabel(t) { return t === 'car' ? 'Ô tô' : (t === 'motorbike' ? 'Xe máy' : 'Xe'); },
                transmissionLabel(t) { return { auto: 'Số tự động', manual: 'Số sàn' }[t] || t; },
                fuelLabel(f) { return { petrol: 'Xăng', diesel: 'Dầu', electric: 'Điện', hybrid: 'Hybrid' }[f] || f; },
                vehicleName(v) {
                    if (!v) return '';
                    return [v.brand, v.model, v.year].filter(Boolean).join(' ') || ('Xe ' + (v.plate || ''));
                },
                kmText(q) {
                    if (!q) return '';
                    if (q.included_km == null) return 'Không giới hạn km';
                    return 'Gồm ' + q.included_km + ' km' + (q.extra_km_price ? ' · vượt thêm ' + this.money(q.extra_km_price) + '/km' : '');
                },
                statusInfo(s) { return STATUS[s] || { label: s, color: 'gray' }; },
                canPay(o) { return o && o.status === 'pending_payment' && o.hold_expires_at && new Date(o.hold_expires_at) > new Date(); },
                cancelPolicyText() {
                    const tiers = (this.cfg.cancellation_tiers || []).slice().sort((a, b) => b.hours - a.hours);
                    if (!tiers.length) return 'Đơn đã thanh toán không được hoàn tiền khi huỷ.';
                    const parts = tiers.map((t) => `huỷ trước giờ nhận ≥ ${t.hours} giờ hoàn ${t.percent}%`);
                    return 'Chính sách huỷ (đơn đã thanh toán): ' + parts.join('; ') + '; muộn hơn không hoàn tiền.';
                },
                qrSrc(code) { return '{{ route('vehicle-rental.qr') }}?d=' + encodeURIComponent(code); },

                // ---------- thời gian ----------
                setDefaults() {
                    const d = new Date(Date.now() + Math.max(this.cfg.min_lead_minutes, 60) * 60000);
                    d.setMinutes(Math.ceil(d.getMinutes() / 30) * 30, 0, 0);
                    this.start = toLocalInput(d);
                    this.end = toLocalInput(new Date(d.getTime() + 24 * 3600000));
                },
                // Mốc sớm nhất = bây giờ + thời gian đặt trước tối thiểu, làm tròn LÊN tới phút (bỏ giây). Ô giờ không giới hạn bước: chọn phút nào cũng được.
                minStart() {
                    const d = new Date(Date.now() + this.cfg.min_lead_minutes * 60000);
                    if (d.getSeconds() > 0 || d.getMilliseconds() > 0) d.setMinutes(d.getMinutes() + 1);
                    d.setSeconds(0, 0);
                    return toLocalInput(d);
                },
                setDuration(hours) {
                    const s = new Date(this.start);
                    if (isNaN(s)) return;
                    this.end = toLocalInput(new Date(s.getTime() + hours * 3600000));
                },
                formError() {
                    if (!this.start || !this.end) return '';
                    const s = new Date(this.start), e = new Date(this.end);
                    if (isNaN(s) || isNaN(e)) return 'Thời gian không hợp lệ.';
                    if (e <= s) return 'Giờ trả xe phải sau giờ nhận xe.';
                    if (s < new Date(Date.now() + this.cfg.min_lead_minutes * 60000 - 60000)) return 'Cần đặt xe trước giờ nhận ít nhất ' + this.cfg.min_lead_minutes + ' phút.';
                    if (s > new Date(Date.now() + this.cfg.max_advance_days * 86400000)) return 'Chỉ nhận đặt xe trước tối đa ' + this.cfg.max_advance_days + ' ngày.';
                    if ((e - s) > this.cfg.max_rental_days * 86400000) return 'Mỗi đơn thuê tối đa ' + this.cfg.max_rental_days + ' ngày.';
                    return '';
                },
                // "YYYY-MM-DDTHH:mm" giờ địa phương (server Asia/Ho_Chi_Minh) → chuỗi gửi API.
                apiTime(v) { return v.replace('T', ' ') + ':00'; },

                // ---------- tab ----------
                go(tab) {
                    this.tab = tab;
                    const u = new URL(window.location.href);
                    u.searchParams.set('tab', tab);
                    ['vehicle', 'start', 'end', 'type'].forEach((k) => u.searchParams.delete(k));
                    window.history.replaceState({}, '', u);
                    if (tab === 'orders') this.loadOrders();
                    if (tab === 'documents') this.loadDoc();
                },

                // ---------- tìm xe ----------
                async search() {
                    this.error = '';
                    const fe = this.formError();
                    if (fe) { this.error = fe; return; }
                    this.loading = true;
                    try {
                        const q = new URLSearchParams({ start_at: this.apiTime(this.start), end_at: this.apiTime(this.end) });
                        if (this.type) q.set('type', this.type);
                        const json = await this.api('GET', '/api/vehicle-rentals/vehicles?' + q.toString());
                        this.results = json.data || [];
                        this.sStart = new Date(this.start).toISOString();
                        this.sEnd = new Date(this.end).toISOString();
                        this.searched = true;
                    } catch (e) {
                        this.error = e.message;
                        this.searched = true;
                        this.results = [];
                    } finally { this.loading = false; }
                },

                // ---------- chi tiết xe ----------
                async openVehicle(v) {
                    this.sel = v; this.detailQuote = v.quote || null; this.detailKmQuote = v.km_quote || null; this.estKm = (v.km_quote && v.km_quote.estimated_km) || (v.km_plan && v.km_plan.min_km) || 50;
                    this.mode = v.quote ? 'time' : 'km'; this.detailAvail = null; this.detailError = '';
                    this.gallery = [...new Set([v.image, ...(v.photos || [])].filter(Boolean))]; this.galleryIdx = 0;
                    this.step = 'detail'; this.modal = true;
                    document.body.style.overflow = 'hidden';
                    await this.requote(true);
                },
                async requote(initial) {
                    if (!this.sel) return;
                    this.detailError = '';
                    if (this.formError()) { this.detailQuote = null; this.detailKmQuote = null; return; }
                    this.detailLoading = true;
                    try {
                        const q = new URLSearchParams({ start_at: this.apiTime(this.start), end_at: this.apiTime(this.end) });
                        if (this.mode === 'km' && this.estKm > 0) q.set('estimated_km', Math.floor(this.estKm));
                        const json = await this.api('GET', '/api/vehicle-rentals/vehicles/' + this.sel.id + '?' + q.toString());
                        const d = json.data;
                        this.sel = Object.assign({}, this.sel, d);
                        this.detailQuote = d.quote || null;
                        this.detailKmQuote = d.km_quote || null;
                        if (!d.quote && d.km_plan) this.mode = 'km';
                        if (!d.km_plan) this.mode = 'time';
                        this.detailAvail = d.available === undefined ? null : d.available;
                        if (!this.gallery.length) this.gallery = [...new Set([d.image, ...(d.photos || [])].filter(Boolean))];
                    } catch (e) {
                        this.detailError = e.message;
                    } finally { this.detailLoading = false; }
                },
                activeQuote() { return this.mode === 'km' ? this.detailKmQuote : this.detailQuote; },
                canBook() { return !this.formError() && !!this.activeQuote() && (this.mode !== 'km' || this.estKm >= 1) && this.detailAvail !== false && !this.detailLoading; },
                async toConfirm() {
                    this.bookError = '';
                    this.refreshAuth();
                    if (!this.authed) { window.location.href = this.loginUrlForBooking(); return; }
                    if (this.doc === undefined) { try { await this.loadDoc(true); } catch (e) {} }
                    this.step = 'confirm';
                },
                async book() {
                    this.bookBusy = true; this.bookError = '';
                    try {
                        const json = await this.api('POST', '/api/vehicle-rental-bookings', {
                            vehicle_id: this.sel.id, start_at: this.apiTime(this.start), end_at: this.apiTime(this.end),
                            ...(this.mode === 'km' ? { pricing: 'km', estimated_km: Math.floor(this.estKm) } : {}),
                        });
                        this.booking = json.data;
                        this.payError = json.data.payment_error || '';
                        this.step = 'pay';
                        this.startPaymentWatch();
                    } catch (e) {
                        if (e.status === 401) { window.location.href = this.loginUrlForBooking(); return; }
                        this.bookError = e.message;
                    } finally { this.bookBusy = false; }
                },

                // ---------- thanh toán ----------
                startPaymentWatch() {
                    this.stopPaymentWatch();
                    const tick = () => {
                        if (!this.booking || !this.booking.hold_expires_at) { this.remainingSec = 0; return; }
                        this.remainingSec = Math.max(0, Math.floor((new Date(this.booking.hold_expires_at) - Date.now()) / 1000));
                    };
                    tick();
                    this.timer = setInterval(tick, 1000);
                    this.poller = setInterval(async () => {
                        if (!this.booking || this.step !== 'pay') return;
                        try {
                            const json = await this.api('GET', '/api/vehicle-rental-bookings/' + this.booking.code);
                            const status = json.data.status;
                            if (status !== 'pending_payment') {
                                this.booking = Object.assign({}, this.booking, json.data);
                                this.stopPaymentWatch();
                                if (['confirmed', 'picked_up', 'returned', 'settled'].includes(status)) { this.step = 'done'; this.loadOrders(); }
                                else this.payError = 'Đơn đã bị huỷ hoặc hết hạn giữ xe. Vui lòng đặt lại.';
                            }
                        } catch (e) { /* lỗi mạng thoáng qua: thử lại ở nhịp sau */ }
                    }, 4000);
                },
                stopPaymentWatch() { clearInterval(this.timer); clearInterval(this.poller); this.timer = null; this.poller = null; },
                countdownText() { return pad(Math.floor(this.remainingSec / 60)) + ':' + pad(this.remainingSec % 60); },
                async refreshPayment() {
                    this.payBusy = true; this.payError = '';
                    try {
                        const json = await this.api('POST', '/api/vehicle-rental-bookings/' + this.booking.code + '/payment');
                        this.booking = json.data;
                        this.startPaymentWatch();
                    } catch (e) { this.payError = e.message; } finally { this.payBusy = false; }
                },
                async resumePayment(code) {
                    try {
                        const json = await this.api('POST', '/api/vehicle-rental-bookings/' + code + '/payment');
                        this.booking = json.data; this.payError = '';
                        this.step = 'pay'; this.modal = true; document.body.style.overflow = 'hidden';
                        this.startPaymentWatch();
                    } catch (e) { this.ordersError = e.message; this.orderError = e.message; }
                },
                closeModal() {
                    this.modal = false; this.stopPaymentWatch();
                    document.body.style.overflow = '';
                },

                // ---------- đơn của tôi ----------
                async loadOrders() {
                    this.refreshAuth();
                    if (!this.authed) return;
                    this.ordersLoading = true; this.ordersError = '';
                    try { this.orders = (await this.api('GET', '/api/vehicle-rental-bookings')).data || []; }
                    catch (e) { this.ordersError = e.message; }
                    finally { this.ordersLoading = false; }
                },
                async openOrder(code) {
                    this.orderError = ''; this.order = null; this.step = 'order'; this.modal = true; document.body.style.overflow = 'hidden';
                    try { this.order = (await this.api('GET', '/api/vehicle-rental-bookings/' + code)).data; }
                    catch (e) { this.orderError = e.message; this.closeModal(); this.ordersError = e.message; }
                },
                async cancelOrder() {
                    const paid = this.order.paid_at;
                    const msg = paid
                        ? `Huỷ đơn #${this.order.code}? Bạn sẽ được hoàn ${this.order.refund_percent_if_cancelled_now ?? 0}% tiền thuê theo chính sách.`
                        : `Huỷ đơn #${this.order.code}?`;
                    if (!window.confirm(msg)) return;
                    this.orderBusy = true; this.orderError = '';
                    try {
                        await this.api('POST', '/api/vehicle-rental-bookings/' + this.order.code + '/cancel', { reason: 'Khách huỷ trên website' });
                        this.ordersMsg = 'Đã huỷ đơn #' + this.order.code + '.';
                        this.closeModal();
                        await this.loadOrders();
                    } catch (e) { this.orderError = e.message; }
                    finally { this.orderBusy = false; }
                },

                // ---------- giấy tờ ----------
                docStatus() {
                    if (!this.doc) return { label: 'Chưa nộp', color: 'gray' };
                    return { pending: { label: 'Chờ duyệt', color: 'yellow' }, approved: { label: 'Đã duyệt', color: 'green' }, rejected: { label: 'Bị từ chối', color: 'red' } }[this.doc.status] || { label: this.doc.status, color: 'gray' };
                },
                async loadDoc(silent) {
                    this.refreshAuth();
                    if (!this.authed) return;
                    try {
                        this.doc = (await this.api('GET', '/api/vehicle-rental-documents')).data;
                        if (this.doc) {
                            ['full_name', 'id_number', 'license_number', 'license_class', 'license_expires_on'].forEach((k) => { this.docForm[k] = this.doc[k] || ''; });
                        }
                    } catch (e) { if (!silent) this.docError = e.message; }
                },
                async submitDoc() {
                    this.docError = ''; this.docMsg = '';
                    const fd = new FormData();
                    Object.entries(this.docForm).forEach(([k, v]) => { if (v) fd.append(k, v); });
                    let hasFile = false;
                    Object.entries(this.docFiles).forEach(([k, f]) => { if (f) { fd.append(k, f); hasFile = true; } });
                    if (!this.doc && !hasFile && !this.docForm.id_number) { this.docError = 'Vui lòng nhập thông tin và chọn ảnh giấy tờ.'; return; }
                    this.docBusy = true;
                    try {
                        this.doc = (await this.api('POST', '/api/vehicle-rental-documents', fd, true)).data;
                        this.docFiles = {};
                        document.querySelectorAll('.vr input[type=file]').forEach((i) => { i.value = ''; });
                        this.docMsg = 'Đã gửi giấy tờ. 365 HOME sẽ duyệt trong thời gian sớm nhất.';
                    } catch (e) { this.docError = e.message; }
                    finally { this.docBusy = false; }
                },
            };
        }
    </script>
@endsection
