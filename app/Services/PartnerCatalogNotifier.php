<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Partner;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\BladeThemeV1\App\Models\AdditionService;
use Modules\Category\App\Filament\Resources\CategoryResource;
use Modules\Category\Entities\Category;
use Modules\Product\App\Filament\Resources\AdditionServiceResource;
use Modules\Product\App\Filament\Resources\ProductResource;
use Modules\Product\App\Models\Product;

// Đối tác tự tạo dữ liệu kinh doanh (phòng, chi nhánh, dịch vụ đi kèm) → báo Super Admin (chuông + push FCM + socket, qua
// AdminNotificationService) để 365home nắm được ngay. Gọi từ observer 'created' của từng model (xem EventServiceProvider).
//  - Chỉ khi NGƯỜI THAO TÁC là tài khoản đối tác thường (cùng điều kiện ApprovalService::requiresApproval()): super_admin /
//    nhân viên đối tác nền tảng tự tạo thì không báo.
//  - Phòng mới đã vào luồng duyệt (approval_status=pending) thì ApprovalService đã báo "Có yêu cầu duyệt mới" → không báo trùng.
//  - Gom trong 1 request, gửi khi request kết thúc: lúc đó phòng đã gắn chi nhánh (Filament/API sync categories SAU khi tạo),
//    và tạo hàng loạt (dữ liệu mẫu, nhân bản phòng...) chỉ ra 1 thông báo tóm tắt cho mỗi đối tác thay vì mỗi bản ghi 1 push.
class PartnerCatalogNotifier
{
    public const TYPE = 'partner_catalog_created';

    private const LABELS = [
        'room'    => 'phòng',
        'branch'  => 'chi nhánh',
        'service' => 'dịch vụ đi kèm',
    ];

    /** @var array<string, list<array{kind: string, model: Model}>> partner_id => bản ghi vừa tạo */
    private array $pending = [];

    private bool $registered = false;

    public function __construct(
        private AdminNotificationService $notifications,
        private ApprovalService $approvals,
    ) {}

    public function created(Model $model): void
    {
        $kind = $this->kindOf($model);
        $partnerId = (string) $model->getAttribute('partner_id');

        if ($kind === null || $partnerId === '' || ! $this->approvals->requiresApproval(auth()->user())) {
            return;
        }

        $this->pending[$partnerId][] = ['kind' => $kind, 'model' => $model];

        // Lệnh artisan / hàng đợi: không có "kết thúc request" → gửi ngay.
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            $this->flush();

            return;
        }

        if (! $this->registered) {
            $this->registered = true;
            app()->terminating(fn () => $this->flush());
        }
    }

    public function flush(): void
    {
        $pending = $this->pending;
        $this->pending = [];

        if ($pending === []) {
            return;
        }

        $superAdmins = User::role(config('filament-shield.super_admin.name'))->get();

        foreach ($pending as $partnerId => $items) {
            // Phòng đã gửi duyệt thì ApprovalService đã báo; bản ghi bị xoá ngay trong request thì bỏ.
            $items = array_values(array_filter($items, fn (array $item) => $item['model']->exists
                && ! ($item['kind'] === 'room' && $item['model']->getAttribute('approval_status') === 'pending')));

            if ($items === []) {
                continue;
            }

            try {
                $this->send($superAdmins, Partner::withoutGlobalScopes()->find($partnerId), $items);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /** @param list<array{kind: string, model: Model}> $items */
    private function send($superAdmins, ?Partner $partner, array $items): void
    {
        if (! $partner) {
            return;
        }

        $partnerName = $partner->legal_name ?: $partner->name;
        $data = ['type' => self::TYPE, 'partner_id' => $partner->id, 'partner_type' => $partner->partner_type];

        if (count($items) === 1) {
            ['kind' => $kind, 'model' => $model] = $items[0];
            $url = $partner->isMinihouse() ? null : $this->editUrl($kind, $model);

            $this->notifications->notify(
                $superAdmins,
                'Đối tác vừa tạo ' . self::LABELS[$kind],
                $partnerName . ' vừa tạo ' . self::LABELS[$kind] . ' "' . $this->labelOf($kind, $model) . '"' . $this->branchSuffix($kind, $model) . '.',
                $data + ['subject_type' => $kind, 'subject_id' => $model->getKey(), 'url' => $url],
                'heroicon-o-building-storefront',
                'info',
                $url,
            );

            return;
        }

        $counts = collect($items)->countBy('kind')
            ->map(fn (int $count, string $kind) => $count . ' ' . self::LABELS[$kind])
            ->implode(', ');

        $this->notifications->notify(
            $superAdmins,
            'Đối tác vừa tạo dữ liệu mới',
            $partnerName . ' vừa tạo ' . $counts . '.',
            $data,
            'heroicon-o-building-storefront',
            'info',
        );
    }

    private function kindOf(Model $model): ?string
    {
        return match (true) {
            $model instanceof Product        => 'room',
            $model instanceof AdditionService => 'service',
            // Chỉ chi nhánh (category gốc của phòng) — khu vực con / danh mục bài viết... không báo.
            $model instanceof Category       => $model->category_type === 'product' && $model->parent_id === null ? 'branch' : null,
            default                          => null,
        };
    }

    private function labelOf(string $kind, Model $model): string
    {
        return (string) ($model->getAttribute('name') ?: '#' . $model->getKey());
    }

    private function branchSuffix(string $kind, Model $model): string
    {
        if ($kind !== 'room') {
            return '';
        }

        // Phòng có thể gắn vào khu vực con thay vì chi nhánh gốc → lấy tên chi nhánh cha.
        $category = $model->categories()->withoutGlobalScopes()->first(['categories.id', 'categories.name', 'categories.parent_id']);
        $branch = $category?->parent_id
            ? Category::withoutGlobalScopes()->whereKey($category->parent_id)->value('name')
            : $category?->name;

        return $branch ? ' ở chi nhánh ' . $branch : '';
    }

    private function editUrl(string $kind, Model $model): ?string
    {
        try {
            return match ($kind) {
                'room'    => ProductResource::getUrl('edit', ['record' => $model->getKey()]),
                'branch'  => CategoryResource::getUrl('edit', ['record' => $model->getKey()]),
                'service' => AdditionServiceResource::getUrl('edit', ['record' => $model->getKey()]),
            };
        } catch (\Throwable) {
            return null;
        }
    }
}
