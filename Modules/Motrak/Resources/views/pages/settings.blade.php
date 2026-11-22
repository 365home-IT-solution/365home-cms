<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Trạng thái kết nối</x-slot>

        <div class="flex flex-wrap items-center gap-3">
            <x-filament::badge :color="$connection['color']" size="lg">{{ $connection['label'] }}</x-filament::badge>
            @if ($connection['http_status'])
                <span class="text-sm text-gray-500 dark:text-gray-400">HTTP {{ $connection['http_status'] }}</span>
            @endif
        </div>

        <dl class="mt-4 grid grid-cols-1 gap-x-6 gap-y-2 text-sm md:grid-cols-2">
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Kiểm tra gần nhất</dt>
                <dd>{{ $connection['checked_at'] ? \Carbon\Carbon::parse($connection['checked_at'])->format('H:i:s d/m/Y') : 'Chưa có' }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Số phương tiện nhận được (lần thành công gần nhất)</dt>
                <dd>{{ $connection['vehicle_count'] ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Token</dt>
                <dd>
                    @if ($tokenInfo['state'] === 'saved')
                        Đã lưu (••••{{ $tokenInfo['hint'] }})
                        @if ($tokenInfo['updated_at']) · cập nhật {{ \Carbon\Carbon::parse($tokenInfo['updated_at'])->format('H:i d/m/Y') }} @endif
                        @if (! empty($tokenInfo['expires_at'])) · hết hạn {{ \Carbon\Carbon::parse($tokenInfo['expires_at'])->format('H:i d/m/Y') }} (tự lấy lại bằng tài khoản đăng nhập) @endif
                    @elseif ($tokenInfo['state'] === 'unreadable')
                        Đã lưu nhưng không đọc được — cần nhập lại
                    @else
                        Chưa có
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Đồng bộ thành công gần nhất</dt>
                <dd>{{ $connection['last_success_at'] ? \Carbon\Carbon::parse($connection['last_success_at'])->format('H:i:s d/m/Y') : '—' }}</dd>
            </div>
        </dl>

        @if ($connection['message'])
            <p class="mt-4 rounded-lg bg-gray-50 p-3 text-sm dark:bg-white/5">{{ $connection['message'] }}</p>
        @endif

        <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
            Token chỉ lưu dạng mã hoá, không hiện lại, không ghi vào log. Token sai hoặc hết hạn không làm CMS lỗi và không xoá dữ liệu GPS đã đồng bộ —
            chỉ cần nhập Token mới rồi lưu, hệ thống tự kiểm tra lại ngay.
        </p>
    </x-filament::section>

    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <div>
            <x-filament::button type="submit" icon="heroicon-o-check">Lưu cấu hình</x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
