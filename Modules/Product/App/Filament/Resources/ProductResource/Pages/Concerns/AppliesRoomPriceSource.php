<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources\ProductResource\Pages\Concerns;

use App\Services\PriceBoards\RoomPriceBoardPicker;
use App\Services\PriceTemplateService;
use Filament\Notifications\Notification;
use Modules\Category\Entities\Category;
use Modules\Product\App\Models\Product;

/**
 * Chọn GIÁ GỐC của phòng ngay trên form tạo / sửa phòng: từ BẢNG GIÁ (+ bộ giá) hoặc từ BẢNG GIÁ MẪU —
 * cùng nghiệp vụ với POST|PUT /api/admin/products (price_board_id / price_template_id).
 * Các ô chọn không lưu vào bảng products (dehydrated = false), được đọc từ $this->data.
 */
trait AppliesRoomPriceSource
{
    /** Lý do không dùng được lựa chọn hiện tại, null nếu hợp lệ / không chọn gì. */
    protected function priceSourceError(?Product $record = null): ?string
    {
        $source = $this->data['pick_price_source'] ?? 'none';

        if ($source !== 'none' && ($rowError = $this->priceRowsError())) {
            return $rowError;
        }

        if ($source === 'board' && filled($this->data['pick_price_board_id'] ?? null)) {
            $partnerId = $this->priceSourcePartnerId($record);
            $style     = (int) ($this->data['styles'] ?? $record?->styles ?? 1);
            $picker    = app(RoomPriceBoardPicker::class);
            $board     = $picker->usableBoard($partnerId, (int) $this->data['pick_price_board_id']);

            if (! $board) {
                return 'Không tìm thấy bảng giá.';
            }

            $sheet = $picker->resolveSheet($board, $style, filled($this->data['pick_price_sheet_id'] ?? null) ? (int) $this->data['pick_price_sheet_id'] : null);

            return $picker->error($board, $sheet, $style, $this->priceSourceCategoryIds($record), $record);
        }

        if ($source === 'template' && filled($this->data['pick_price_template_id'] ?? null)) {
            $partnerId = $this->priceSourcePartnerId($record);
            $style     = (int) ($this->data['styles'] ?? $record?->styles ?? 1);

            if ($style !== 1) {
                return 'Bảng giá mẫu chỉ dùng cho phòng theo khung giờ.';
            }

            $service  = app(PriceTemplateService::class);
            $template = $service->usableTemplate($partnerId, (int) $this->data['pick_price_template_id']);

            return $template ? $service->applyError($template, $partnerId, $record) : 'Không tìm thấy bảng giá mẫu.';
        }

        return null;
    }

    protected function applyPriceSource(Product $record): void
    {
        $source = $this->data['pick_price_source'] ?? 'none';

        if ($source === 'board' && filled($this->data['pick_price_board_id'] ?? null)) {
            $picker = app(RoomPriceBoardPicker::class);
            $board  = $picker->usableBoard($record->partner_id, (int) $this->data['pick_price_board_id']);
            $sheet  = $board ? $picker->resolveSheet(
                $board,
                (int) ($record->styles ?? 1),
                filled($this->data['pick_price_sheet_id'] ?? null) ? (int) $this->data['pick_price_sheet_id'] : null
            ) : null;

            if ($board && $sheet) {
                $picker->apply($board, $sheet, $record->fresh());
                Notification::make()->title('Đã lấy giá từ bảng giá "' . $board->name . '"')->success()->send();
            }
        } elseif ($source === 'template' && filled($this->data['pick_price_template_id'] ?? null)) {
            $service  = app(PriceTemplateService::class);
            $template = $service->usableTemplate($record->partner_id, (int) $this->data['pick_price_template_id']);

            if ($template) {
                $service->applyToRoom($template, $record->fresh(), true);
                Notification::make()->title('Đã thêm khung giờ + giá từ bảng giá mẫu "' . $template->name . '"')->success()->send();
            }
        }

        $this->applyPriceOverridesFromForm($record);
    }

    /** Giá người dùng đã CHỈNH riêng trong form (bảng khung giờ + giá / giá đêm) — chỉ áp cho phòng này, không đổi bảng giá / mẫu. */
    private function applyPriceOverridesFromForm(Product $record): void
    {
        if (($this->data['pick_price_source'] ?? 'none') === 'none') {
            return;
        }

        $rows = collect($this->data['pick_price_rows'] ?? [])
            ->map(fn ($row) => [
                'timeslot_id' => $row['timeslot_id'] ?? null,
                'start_time'  => $row['start_time'] ?? null,
                'end_time'    => $row['end_time'] ?? null,
                'over_night'  => isset($row['over_night']) ? (bool) $row['over_night'] : null,
                'price'       => $row['price'] ?? null,
            ])
            ->filter(fn ($row) => filled($row['timeslot_id']) || (filled($row['start_time']) && filled($row['end_time'])))
            ->values()
            ->all();

        $daily = (int) ($record->styles ?? 1) === 2 ? [
            'price'            => $this->data['pick_price_daily_price'] ?? null,
            'default_checkin'  => $this->data['pick_price_daily_checkin'] ?? null,
            'default_checkout' => $this->data['pick_price_daily_checkout'] ?? null,
        ] : null;

        // prune = true: khung người dùng đã xoá khỏi bảng không được giữ lại trong phòng.
        app(RoomPriceBoardPicker::class)->applyOverrides($record->fresh(), $rows, $daily, prune: true);
    }

    /** Kiểm tra bảng khung giờ người dùng đã chỉnh: giờ hợp lệ, khung không trùng nhau, giá không âm. */
    private function priceRowsError(): ?string
    {
        $seen = [];

        foreach ((array) ($this->data['pick_price_rows'] ?? []) as $row) {
            $start = substr((string) ($row['start_time'] ?? ''), 0, 5);
            $end   = substr((string) ($row['end_time'] ?? ''), 0, 5);

            if (! preg_match('/^\d{2}:\d{2}$/', $start) || ! preg_match('/^\d{2}:\d{2}$/', $end)) {
                return 'Mỗi khung giờ cần có giờ bắt đầu và giờ kết thúc.';
            }

            if ($start === $end) {
                return "Khung {$start} - {$end}: giờ bắt đầu và kết thúc không được trùng nhau.";
            }

            if (isset($seen["{$start}-{$end}"])) {
                return "Khung {$start} - {$end} bị trùng — mỗi khung giờ chỉ xuất hiện một lần.";
            }

            $seen["{$start}-{$end}"] = true;

            if (isset($row['price']) && $row['price'] !== '' && (float) $row['price'] < 0) {
                return "Khung {$start} - {$end}: giá không được âm.";
            }
        }

        return null;
    }

    private function priceSourceCategoryIds(?Product $record): array
    {
        $raw = $this->data['categories'] ?? null;
        $ids = is_array($raw) ? $raw : (filled($raw) ? [$raw] : []);

        if (! $ids && $record) {
            $ids = $record->categories()->pluck('categories.id')->all();
        }

        return array_map('intval', $ids);
    }

    private function priceSourcePartnerId(?Product $record): ?string
    {
        if ($record?->partner_id) {
            return (string) $record->partner_id;
        }

        $user = auth()->user();

        if ($user && ! $user->seesAllPartners() && $user->partner_id) {
            return (string) $user->partner_id;
        }

        $categoryId = $this->priceSourceCategoryIds($record)[0] ?? null;

        return $categoryId ? Category::withoutGlobalScopes()->whereKey($categoryId)->value('partner_id') : null;
    }
}
