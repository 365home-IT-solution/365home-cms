        {{-- Pill thu gọn --}}
        <div x-show="!formOpen"
             style="background:#fff; border-bottom:1px solid #f0f0f0; box-shadow:0 2px 12px rgba(0,0,0,.07); padding:9px 64px; position:relative; display:flex; align-items:center;">

            {{-- Logo — về trang chủ --}}
            <a href="{{ url('/') }}"
               style="position:absolute; left:16px; top:50%; transform:translateY(-50%); width:42px; height:42px; border-radius:12px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                <img src="{{ asset('/storage/'.$logo) }}" alt="Logo" style="max-width:100%; max-height:100%; object-fit:contain;">
            </a>

            <button @click="formOpen = true"
                    style="flex:1; display:flex; align-items:center; gap:0; border:1.5px solid #e5e7eb; border-radius:99px; background:#fff; box-shadow:0 1px 6px rgba(0,0,0,.08); cursor:pointer; overflow:hidden; max-width:52rem; margin:0 auto; transition:box-shadow .2s, border-color .2s;"
                    onmouseover="this.style.boxShadow='0 4px 14px rgba(0,0,0,.12)'; this.style.borderColor='#d1d5db';"
                    onmouseout="this.style.boxShadow='0 1px 6px rgba(0,0,0,.08)'; this.style.borderColor='#e5e7eb';">

                <span style="flex:1; display:flex; flex-direction:column; align-items:flex-start; padding:9px 16px; min-width:0;">
                    <span style="font-size:9px; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:.05em; line-height:1;">Địa điểm</span>
                    <span style="font-size:12px; font-weight:600; color:#111827; margin-top:2px; overflow:hidden; white-space:nowrap; text-overflow:ellipsis; max-width:100%;">
                        {{ $selectedLocation ? collect($locations)->firstWhere('slug', $selectedLocation)['name'] ?? 'Chọn địa điểm' : 'Tìm kiếm địa điểm' }}
                    </span>
                </span>

                <span style="width:1px; height:28px; background:#e5e7eb; flex-shrink:0;"></span>

                <span style="flex:1; display:flex; flex-direction:column; align-items:flex-start; padding:9px 16px; min-width:0;">
                    <span style="font-size:9px; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:.05em; line-height:1;">Thời gian</span>
                    <span style="font-size:12px; font-weight:600; color:#111827; margin-top:2px; overflow:hidden; white-space:nowrap; text-overflow:ellipsis;">
                        {{ $checkIn ? $checkIn . ($checkOut ? ' → ' . $checkOut : '') : 'Thêm ngày' }}
                    </span>
                </span>

                <span style="width:1px; height:28px; background:#e5e7eb; flex-shrink:0;"></span>

                <span style="flex:1; display:flex; flex-direction:column; align-items:flex-start; padding:9px 16px; min-width:0;">
                    <span style="font-size:9px; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:.05em; line-height:1;">Loại đặt</span>
                    <span style="font-size:12px; font-weight:600; color:#111827; margin-top:2px;">
                        @if ($selectedBuoi === '1') Theo giờ
                        @elseif ($selectedBuoi === '2') Theo ngày
                        @else Tất cả @endif
                    </span>
                </span>

                <span style="flex-shrink:0; padding:5px 8px 5px 0;">
                    <span style="display:flex; align-items:center; justify-content:center; width:34px; height:34px; border-radius:50%; background:#0f766e;">
                        <svg style="width:15px;height:15px;color:#fff;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                    </span>
                </span>
            </button>

            {{-- Hamburger --}}
            <button @click.stop="menuOpen = !menuOpen"
                    style="position:absolute; right:16px; top:50%; transform:translateY(-50%); width:42px; height:42px; border-radius:12px; background:white; border:1.5px solid #e5e7eb; display:flex; align-items:center; justify-content:center; cursor:pointer; box-shadow:0 1px 6px rgba(0,0,0,.08); transition:all .2s; flex-shrink:0;"
                    onmouseover="this.style.background='#f0fdfa'; this.style.borderColor='#99f6e4';"
                    onmouseout="this.style.background='white'; this.style.borderColor='#e5e7eb';">
                <svg style="width:18px;height:18px;color:#374151;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
            </button>
        </div>
