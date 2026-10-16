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
