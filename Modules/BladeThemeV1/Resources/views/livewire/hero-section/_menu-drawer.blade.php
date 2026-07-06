    {{-- Backdrop --}}
    <div x-show="menuOpen"
         x-cloak
         style="display:none; position:fixed; inset:0; z-index:1999; background:rgba(0,0,0,0.45);"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         @click="menuOpen = false"></div>

    {{-- Slide-in menu drawer --}}
    <div x-show="menuOpen"
         x-cloak
         class="menu-drawer"
         style="position:fixed; top:0; right:0; bottom:0; width:min(340px,90vw); z-index:2000; background:white; box-shadow:-8px 0 40px rgba(0,0,0,0.18); overflow-y:auto;"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="translate-x-full"
         x-transition:enter-end="translate-x-0"
         x-transition:leave="transition ease-in duration-250"
         x-transition:leave-start="translate-x-0"
         x-transition:leave-end="translate-x-full">

        {{-- Topbar: logo + close --}}
        {{-- <div style="display:flex; align-items:center; justify-content:space-between; padding:16px 20px; border-bottom:1px solid #f3f4f6; flex-shrink:0;">
            <span style="font-size:16px; font-weight:700; color:#111827; letter-spacing:-.3px;">Menu</span>
            <button @click="menuOpen = false"
                    style="width:34px; height:34px; border-radius:50%; background:#f3f4f6; border:none; color:#6b7280; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:background .2s;"
                    onmouseover="this.style.background='#e5e7eb';" onmouseout="this.style.background='#f3f4f6';">
                <svg style="width:16px;height:16px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div> --}}

        {{-- Nav items --}}
        <div style="padding:8px 0; flex:1;">
            @foreach($navLinks as $link)
            <a href="{{ $link['url'] }}"
               wire:key="nav-link-{{ $link['url'] }}"
               @click="menuOpen = false"
               style="display:flex; align-items:center; gap:14px; padding:13px 20px; text-decoration:none; color:#374151; transition:all .15s; cursor:pointer;"
               onmouseover="this.style.background='#f9fafb'; this.style.color='#0f766e';"
               onmouseout="this.style.background=''; this.style.color='#374151';">
                {{-- <span style="width:34px; height:34px; border-radius:9px; background:#f3f4f6; display:flex; align-items:center; justify-content:center; flex-shrink:0;">{!! $link['icon'] !!}</span> --}}
                <span style="font-size:14px; font-weight:600; flex:1;">{{ $link['label'] }}</span>
                <svg style="width:13px;height:13px;color:#d1d5db; flex-shrink:0;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                </svg>
            </a>
            @endforeach
        </div>

        {{-- Divider + Auth --}}
        <div style="border-top:1px solid #f3f4f6; padding:12px 20px 20px; flex-shrink:0;"
             x-data="{
                authUser: null,
                init() {
                    this.load();
                    window.addEventListener('auth-state-changed', () => this.load());
                },
                load() {
                    try {
                        const t = localStorage.getItem('auth_token');
                        const u = localStorage.getItem('auth_user');
                        this.authUser = (t && u) ? JSON.parse(u) : null;
                    } catch(e) { this.authUser = null; }
                },
                openLogin() { window.dispatchEvent(new CustomEvent('open-auth-modal')); menuOpen = false; }
             }">
            <template x-if="!authUser">
                <button @click="openLogin()"
                        style="width:100%; display:flex; align-items:center; gap:12px; padding:13px 16px; background:#f9fafb; border:1.5px solid #e5e7eb; border-radius:12px; cursor:pointer; transition:all .15s; text-align:left;"
                        onmouseover="this.style.background='#f0fdfa'; this.style.borderColor='#99f6e4';"
                        onmouseout="this.style.background='#f9fafb'; this.style.borderColor='#e5e7eb';">
                    <span style="width:34px; height:34px; border-radius:9px; background:#e5e7eb; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                        <svg style="width:16px;height:16px;color:#6b7280;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                        </svg>
                    </span>
                    <span style="font-size:14px; font-weight:600; color:#374151; flex:1;">Đăng nhập</span>
                    <svg style="width:13px;height:13px;color:#d1d5db;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                </button>
            </template>
            <template x-if="authUser">
                <a href="/tai-khoan"
                   @click="menuOpen = false"
                   style="display:flex; align-items:center; gap:12px; padding:12px 16px; background:#f9fafb; border:1.5px solid #e5e7eb; border-radius:12px; text-decoration:none; cursor:pointer; transition:all .15s;"
                   onmouseover="this.style.background='#f0fdfa'; this.style.borderColor='#99f6e4';"
                   onmouseout="this.style.background='#f9fafb'; this.style.borderColor='#e5e7eb';">
                    <span style="width:34px; height:34px; border-radius:50%; background:#0f766e; display:flex; align-items:center; justify-content:center; flex-shrink:0; color:white; font-size:14px; font-weight:700;"
                          x-text="authUser.fullname ? authUser.fullname.charAt(0).toUpperCase() : '?'"></span>
                    <span style="font-size:14px; font-weight:600; color:#111827; flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" x-text="authUser.fullname"></span>
                    <svg style="width:13px;height:13px;color:#d1d5db; flex-shrink:0;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                </a>
            </template>
        </div>
    </div>
