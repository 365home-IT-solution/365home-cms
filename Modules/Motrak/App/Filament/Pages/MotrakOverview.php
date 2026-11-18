<?php

declare(strict_types=1);

namespace Modules\Motrak\App\Filament\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Modules\Motrak\App\Filament\Resources\MotrakVehicleResource;
use Modules\Motrak\App\Models\MotrakSyncLog;
use Modules\Motrak\App\Models\MotrakVehicle;
use Modules\Motrak\App\Services\MotrakStatusService;
use Modules\Motrak\App\Services\MotrakSyncService;
use Modules\Motrak\App\Services\MotrakVehicleQuery;
use Modules\Motrak\App\Support\MotrakAccess;
use Modules\Motrak\App\Support\MotrakTransformer;

// Tổng quan GPS: số liệu, trạng thái kết nối, bản đồ toàn bộ xe (tự làm mới 30 giây từ DB 365home — KHÔNG gọi Motrak từ trình duyệt).
class MotrakOverview extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-map-pin';

    protected static ?string $navigationGroup = 'Quản lý';

    protected static ?string $navigationLabel = 'Bản đồ GPS';

    protected static ?string $title = 'Tổng quan GPS';

    protected static ?string $slug = 'gps-overview';

    protected static ?int $navigationSort = 6;

    protected static string $view = 'motrak::pages.overview';

    /** Bộ lọc bản đồ: all | moving | stopped | stale | disconnected | no_data */
    public string $filter = 'all';

    public string $search = '';

    /** @var array<int, array<string, mixed>> */
    public array $markers = [];

    public static function canAccess(): bool
    {
        return MotrakAccess::canView(auth()->user());
    }

    public function mount(): void
    {
        $this->loadMarkers();
    }

    public function updatedFilter(): void
    {
        $this->loadMarkers();
    }

    public function updatedSearch(): void
    {
        $this->loadMarkers();
    }

    /** Đọc lại vị trí từ DB (gọi bởi wire:poll 30 giây và khi đổi bộ lọc). */
    public function loadMarkers(): void
    {
        $f = ['q' => $this->search ?: null];
        match ($this->filter) {
            'moving', 'stopped' => $f['moving'] = $this->filter,
            'stale', 'disconnected', 'no_data' => $f['state'] = $this->filter,
            default => null,
        };

        $this->markers = MotrakVehicleQuery::apply(MotrakVehicle::query(), $f)->orderBy('plate')->limit(1000)->get()
            ->map(fn (MotrakVehicle $v) => MotrakTransformer::marker($v, MotrakVehicleResource::getUrl('view', ['record' => $v->id])))
            ->filter()->values()->all();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncNow')->label('Đồng bộ ngay')->icon('heroicon-o-arrow-path')
                ->visible(fn () => MotrakAccess::canConfigure(auth()->user()))
                ->requiresConfirmation()
                ->modalDescription('Gọi API Motrak ngay bây giờ để cập nhật vị trí tất cả phương tiện.')
                ->action(function () {
                    $log = app(MotrakSyncService::class)->sync(MotrakSyncLog::TYPE_MANUAL, (string) auth()->id());
                    $this->loadMarkers();
                    $this->notifySync($log);
                }),
        ];
    }

    private function notifySync(MotrakSyncLog $log): void
    {
        $n = Notification::make();
        match ($log->status) {
            MotrakSyncLog::STATUS_SUCCESS => $n->success()->title('Đồng bộ thành công')->body("{$log->success_count}/{$log->total_vehicles} xe, {$log->new_locations} điểm mới."),
            MotrakSyncLog::STATUS_PARTIAL => $n->warning()->title('Đồng bộ một phần')->body("{$log->success_count}/{$log->total_vehicles} xe; {$log->failed_count} lỗi. {$log->error_message}"),
            MotrakSyncLog::STATUS_SKIPPED => $n->warning()->title('Bỏ qua')->body((string) $log->error_message),
            default => $n->danger()->title('Đồng bộ thất bại')->body(trim(($log->http_status ? "HTTP {$log->http_status}. " : '') . $log->error_message))->persistent(),
        };
        $n->send();
    }

    protected function getViewData(): array
    {
        $count = fn (array $f) => MotrakVehicleQuery::apply(MotrakVehicle::query(), $f)->count();

        return [
            'connection' => app(MotrakStatusService::class)->status(),
            'stats'      => [
                ['label' => 'Tổng phương tiện', 'value' => MotrakVehicle::query()->count(), 'color' => 'gray'],
                ['label' => 'Đang di chuyển', 'value' => $count(['moving' => 'moving']), 'color' => 'success'],
                ['label' => 'Đang dừng', 'value' => $count(['moving' => 'stopped']), 'color' => 'info'],
                ['label' => 'Dữ liệu quá cũ', 'value' => $count(['state' => 'stale']), 'color' => 'warning'],
                ['label' => 'Không có dữ liệu', 'value' => $count(['state' => 'no_data']), 'color' => 'gray'],
                ['label' => 'Đã ghép xe cho thuê', 'value' => $count(['linked' => 'yes']), 'color' => 'primary'],
            ],
            'filters'    => [
                'all' => 'Tất cả', 'moving' => 'Đang di chuyển', 'stopped' => 'Đang dừng', 'stale' => 'Dữ liệu quá cũ',
                'disconnected' => 'Mất kết nối (isConnected = false)', 'no_data' => 'Không có dữ liệu',
            ],
            'settingsUrl' => MotrakAccess::canConfigure(auth()->user()) ? MotrakSettingsPage::getUrl() : null,
        ];
    }
}
