<div
    x-data="homeSections()"
    x-init="init()"
    x-cloak
>
    <template x-for="section in sections" :key="section.type + '-' + section.id">
        <div>
            {{-- ============== PROMOTION LIST (Flash Sale) ============== --}}
            <template x-if="section.type === 'promotion_list'">
                <section class="py-6 bg-white">
                    <div class="max-w-7xl mx-auto px-4 sm:px-6">
                        <div style="background:linear-gradient(90deg,#dc2626,#ea580c,#f59e0b); padding:12px 16px; border-radius:12px;">
                            <div style="max-width:80rem; margin:0 auto; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                                <div style="display:flex; align-items:center; gap:10px; flex:1; min-width:0;">
                                    <div style="background:rgba(255,255,255,0.2); border-radius:50%; width:32px; height:32px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                                        <template x-if="section.icon_url">
                                            <img :src="section.icon_url" alt="" style="width:18px;height:18px;object-fit:contain;">
                                        </template>
                                        <template x-if="!section.icon_url">
                                            <svg style="width:16px;height:16px;color:#fff;" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M11.3 1.046A1 1 0 0112 2v5h4a1 1 0 01.82 1.573l-7 10A1 1 0 018 18v-5H4a1 1 0 01-.82-1.573l7-10a1 1 0 011.12-.38z" clip-rule="evenodd"/>
                                            </svg>
                                        </template>
                                    </div>
                                    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                                        <span style="background:rgba(255,255,255,0.25); color:#fff; font-size:11px; font-weight:800; padding:2px 10px; border-radius:99px; letter-spacing:.05em; text-transform:uppercase; white-space:nowrap;" x-text="section.title || 'Flash Sale'"></span>
                                        <span style="color:#fff; font-size:14px; font-weight:600;">Ưu đãi đặc biệt hôm nay!</span>
                                    </div>
                                </div>
                                <a href="{{ route('product.search') }}"
                                    style="background:#fff; color:#dc2626; font-size:13px; font-weight:700; padding:6px 18px; border-radius:99px; text-decoration:none; white-space:nowrap; flex-shrink:0; box-shadow:0 2px 8px rgba(0,0,0,0.15);">
                                    Đặt ngay →
                                </a>
                            </div>
                        </div>

                        <template x-if="section.rooms && section.rooms.length">
                            <div style="display:flex; gap:14px; overflow-x:auto; scroll-snap-type:x mandatory; -webkit-overflow-scrolling:touch; scrollbar-width:none; padding:14px 2px 4px;" class="hide-scrollbar">
                                <template x-for="room in section.rooms" :key="'promo-' + room.id">
                                    <div x-html="roomCardHtml(room)"></div>
                                </template>
                            </div>
                        </template>
                    </div>
                </section>
            </template>

            {{-- ============== SUGGESTION LIST ============== --}}
            <template x-if="section.type === 'suggestion_list'">
                <section class="py-4 bg-white">
                    <div class="max-w-7xl mx-auto px-4 sm:px-6">
                        <template x-if="!section.items || !section.items.length">
                            <div style="padding:14px 16px; background:#f9fafb; border:1px solid #e5e7eb; border-radius:12px; display:flex; align-items:center; gap:8px;">
                                <svg style="width:18px;height:18px;color:#9ca3af;flex-shrink:0;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0zM15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                                </svg>
                                <span style="font-size:13px; color:#6b7280;" x-text="section.message || 'Chưa có gợi ý phù hợp.'"></span>
                            </div>
                        </template>

                        <template x-if="section.items && section.items.length">
                            <div>
                                <h2 style="font-size:1.1rem; font-weight:800; color:#111827; margin:0 0 14px;">Gợi ý cho bạn</h2>
                                <div style="display:flex; gap:14px; overflow-x:auto; scroll-snap-type:x mandatory; -webkit-overflow-scrolling:touch; scrollbar-width:none; padding-bottom:4px;" class="hide-scrollbar">
                                    <template x-for="item in section.items" :key="'sugg-' + (item.id ?? item.slug)">
                                        <div x-html="section.suggestion_type === 'branch' ? branchCardHtml(item) : roomCardHtml(item)"></div>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>
                </section>
            </template>

            {{-- ============== ROOM LIST ============== --}}
            <template x-if="section.type === 'room_list' && section.rooms && section.rooms.length">
                <section class="py-4 bg-white">
                    <div class="max-w-7xl mx-auto px-4 sm:px-6">
                        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; gap:12px;">
                            <div>
                                <h2 style="font-size:1.1rem; font-weight:800; color:#111827; margin:0; text-transform:uppercase; letter-spacing:.02em;" x-text="section.title"></h2>
                                <p x-show="section.subtitle" style="font-size:12px; color:#9ca3af; margin:2px 0 0;" x-text="section.subtitle"></p>
                            </div>
                            <a x-show="section.show_arrow" :href="section.view_all_url || '{{ route('product.search') }}'"
                                style="font-size:13px; font-weight:600; color:var(--color-primary); text-decoration:none; display:flex; align-items:center; gap:3px; white-space:nowrap; flex-shrink:0;">
                                Xem tất cả
                                <svg style="width:14px;height:14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                </svg>
                            </a>
                        </div>

                        <div style="display:flex; gap:14px; overflow-x:auto; scroll-snap-type:x mandatory; -webkit-overflow-scrolling:touch; scrollbar-width:none; padding-bottom:4px;" class="hide-scrollbar">
                            <template x-for="room in section.rooms" :key="'room-' + room.id">
                                <div x-html="roomCardHtml(room)"></div>
                            </template>
                        </div>
                    </div>
                </section>
            </template>
        </div>
    </template>

    <style>
        .hide-scrollbar::-webkit-scrollbar { display: none; }
    </style>

    <script src="{{ asset('js/home-sections.js') }}"></script>
</div>
