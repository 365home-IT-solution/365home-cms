<div
    x-data="{
        open: false,
        loading: false,
        cameraName: '',
        events: [],
        async load(cameraId, name) {
            this.open = true;
            this.loading = true;
            this.cameraName = name;
            this.events = await $wire.loadRecentEvents(cameraId);
            this.loading = false;
        },
    }"
    x-on:open-camera-events.window="load($event.detail.cameraId, $event.detail.cameraName)"
    x-show="open"
    x-cloak
    class="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/70 p-4"
>
    <div class="max-h-[85vh] w-full max-w-3xl overflow-hidden rounded-xl bg-white shadow-xl dark:bg-gray-900" x-on:click.outside="open = false">
        <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-gray-700">
            <span class="text-sm font-medium text-gray-950 dark:text-white" x-text="'Sự kiện — ' + cameraName"></span>
            <button type="button" x-on:click="open = false" class="text-gray-400 hover:text-gray-600">
                <x-heroicon-o-x-mark class="h-5 w-5" />
            </button>
        </div>
        <div class="max-h-[75vh] overflow-y-auto p-4">
            <p x-show="loading" class="text-sm text-gray-500">Đang tải sự kiện...</p>
            <p x-show="!loading && events.length === 0" class="text-sm text-gray-500">Không có sự kiện gần đây.</p>
            <div class="grid gap-3 sm:grid-cols-2">
                <template x-for="event in events" :key="event.id">
                    <article class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
                        <template x-if="event.snapshot_url">
                            <img :src="event.snapshot_url" loading="lazy" class="aspect-video w-full bg-black object-contain" alt="Camera event snapshot">
                        </template>
                        <div class="space-y-1 p-3 text-xs">
                            <p class="font-medium text-gray-950 dark:text-white" x-text="event.label || 'Sự kiện'"></p>
                            <p class="text-gray-500" x-text="new Date(event.start_time * 1000).toLocaleString('vi-VN')"></p>
                            <a x-show="event.clip_url" :href="event.clip_url" target="_blank" rel="noopener" class="inline-flex text-primary-600 hover:underline">Mở clip</a>
                        </div>
                    </article>
                </template>
            </div>
        </div>
    </div>
</div>
