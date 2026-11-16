<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;

/**
 * GET /v1/search?ver=2 — tìm kiếm TRẢ DANH SÁCH PHÒNG (trộn phòng theo giờ + theo ngày), phiên bản mới cho màn Tìm kiếm
 * mới của app. Cùng URL với GET /v1/search nhưng chọn bản bằng ?ver=2 (SearchController::index); không gửi ver thì chạy hành vi cũ.
 *
 * Khác bản cũ (không có ver):
 *  - Có "q" vẫn trả PHÒNG (không bao giờ trả card chi nhánh).
 *  - "date=YYYY-MM-DD" đứng một mình: lọc theo ngày còn trống (xem RoomDateAvailability); MiniHouse bỏ qua date.
 *  - "guests=N": chỉ phòng đủ chỗ cho N khách (cùng ngưỡng với "adults" của /v1/search).
 *  - Không có lat/lng: xếp xen kẽ phòng theo giờ / theo ngày thay vì "mới nhất lên trước" (gom hết một loại).
 *  - Các tham số thời gian cũ (time_type, time_from/to, from/to, month/year) bị bỏ qua.
 * Còn lại (province, province_id, ward_code, lat/lng/radius, category, type, tab, page, per_page) dùng chung với
 * RoomSearchService; shape mỗi phòng và meta giữ nguyên.
 */
class RoomSearchRoomsService extends RoomSearchService
{
    private ?string $date = null;

    private bool $geo = false;

    public function searchRooms(array $filters, ?Authenticatable $authUser = null): array
    {
        $validated = Validator::make($filters, [
            'date'   => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'guests' => ['nullable', 'integer', 'min:0'],
        ])->validate();

        $filters = Arr::except($filters, ['time_type', 'time_from', 'time_to', 'from', 'to', 'month', 'year', 'date', 'guests', 'view', 'adults']);

        if (! empty($validated['guests'])) {
            $filters['adults'] = (int) $validated['guests'];
        }

        $this->date = ! empty($validated['date']) ? $validated['date'] : null;
        $this->geo  = isset($filters['lat'], $filters['lng']) && $filters['lat'] !== '' && $filters['lng'] !== '';

        try {
            return parent::search($filters, $authUser);
        } finally {
            $this->date = null;
            $this->geo  = false;
        }
    }

    protected function baseQuery(bool $minihouse): Builder
    {
        $query = parent::baseQuery($minihouse);

        // MiniHouse (thuê tháng): bỏ qua date; thứ tự giữ nguyên như /v1/search.
        if ($minihouse) {
            return $query;
        }

        if ($this->date !== null) {
            $this->whereAvailableOn($query, $this->date);
        }

        // Có toạ độ: parent xếp theo distance. Không có: xen kẽ theo giờ / theo ngày (cái mới cập nhật đứng trước trong
        // từng loại) để trang đầu không bị dồn một loại.
        if (! $this->geo) {
            // wrap() để SQL thô có đúng tiền tố bảng (cms_).
            $grammar = $query->getQuery()->getGrammar();
            $col     = fn (string $c) => $grammar->wrap($query->qualifyColumn($c));
            $query->selectRaw("{$col('*')}, ROW_NUMBER() OVER (PARTITION BY {$col('styles')} ORDER BY {$col('updated_at')} DESC, {$col('id')}) AS style_rank")
                ->orderBy('style_rank')
                ->orderBy($query->qualifyColumn('styles'));
        }

        return $query;
    }

    // Theo giờ: còn ≥ 1 khung trống trong ngày; theo ngày: đêm $date còn trống; loại khác (nếu có): không lọc.
    private function whereAvailableOn(Builder $query, string $date): void
    {
        $availability = app(RoomDateAvailability::class);
        $hourly       = $availability->availableHourlyIds($date);
        $dayBlocked   = $availability->unavailableDayIds($date);
        $id           = $query->qualifyColumn('id');
        $styles       = $query->qualifyColumn('styles');

        $query->where(fn ($q) => $q
            ->where(fn ($h) => $h->where($styles, 1)->whereIn($id, $hourly))
            ->orWhere(fn ($d) => $d->where($styles, 2)->whereNotIn($id, $dayBlocked))
            ->orWhereNotIn($styles, [1, 2])
            ->orWhereNull($styles));
    }
}
