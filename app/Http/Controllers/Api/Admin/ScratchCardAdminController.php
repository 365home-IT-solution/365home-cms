<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ScratchCard;
use App\Models\ScratchCardPrize;
use App\Models\ScratchCardSetting;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Quản trị thẻ cào (chỉ Super Admin) — cùng dữ liệu với mini game thẻ cào ở app khách (App\Services\ScratchCardService).
 *
 *  - GET|PUT /api/admin/games/scratch-card/settings    bật/tắt, hạn cào, đơn tối thiểu (ngày ở, tiền), thưởng đánh giá ảnh
 *  - GET|POST|PUT|DELETE /api/admin/games/scratch-card/prizes   bộ giải; xoá giải đã có người trúng ⇒ chỉ tắt (is_active = false)
 *  - GET /api/admin/games/scratch-card/stats?from=&to=  số thẻ phát/đã cào/hết hạn, lượt trúng từng giải, tổng voucher đã dùng
 *  - GET /api/admin/games/scratch-card/winners?from=&to=&per_page=  lịch sử trúng thưởng (ai, lúc nào, giải gì, từ đơn nào)
 *  - GET /api/admin/games/scratch-card/winners/export?from=&to=     cùng danh sách, tải .xlsx để nộp báo cáo khuyến mại
 */
class ScratchCardAdminController extends Controller
{
    public function showSettings(Request $request): JsonResponse
    {
        $this->authorizeSuper($request);

        return response()->json(['data' => ScratchCardSetting::current()]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $this->authorizeSuper($request);

        $data = $request->validate([
            'is_enabled'           => 'sometimes|boolean',
            'expire_days'          => 'sometimes|integer|min:1|max:365',
            'min_stay_days'        => 'sometimes|integer|min:0|max:365',
            'min_order_amount'     => 'sometimes|integer|min:0',
            'rating_bonus_enabled' => 'sometimes|boolean',
        ]);

        // Bật game mà chưa có giải dự phòng ⇒ hết ngân sách khách sẽ cào ra "may mắn lần sau" mãi, nên bắt buộc có.
        if (($data['is_enabled'] ?? false) && ! ScratchCardPrize::where('is_fallback', true)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['is_enabled' => ['Cần có 1 giải dự phòng (không giới hạn lượt) đang bật trước khi bật thẻ cào.']]);
        }

        $setting = ScratchCardSetting::current();
        $setting->update($data);

        return response()->json(['data' => $setting->fresh()]);
    }

    public function prizes(Request $request): JsonResponse
    {
        $this->authorizeSuper($request);

        return response()->json(['data' => ScratchCardPrize::orderByDesc('is_fallback')->orderBy('id')->get()]);
    }

    public function storePrize(Request $request): JsonResponse
    {
        $this->authorizeSuper($request);

        return response()->json(['data' => ScratchCardPrize::create($this->validated($request))->fresh()], 201);
    }

    public function updatePrize(Request $request, int $id): JsonResponse
    {
        $this->authorizeSuper($request);

        $prize = ScratchCardPrize::findOrFail($id);
        $prize->update($this->validated($request, $prize));

        return response()->json(['data' => $prize->fresh()]);
    }

    public function destroyPrize(Request $request, int $id): JsonResponse
    {
        $this->authorizeSuper($request);

        $prize = ScratchCardPrize::findOrFail($id);

        if ($prize->awarded_count > 0) {
            $prize->update(['is_active' => false]);

            return response()->json(['message' => 'Giải đã có người trúng nên chỉ được tắt, không xoá.', 'data' => $prize->fresh()]);
        }

        $prize->delete();

        return response()->json(['message' => 'Đã xoá giải.']);
    }

    public function stats(Request $request): JsonResponse
    {
        $this->authorizeSuper($request);
        [$from, $to] = $this->range($request);

        $cards = ScratchCard::whereBetween('created_at', [$from, $to]);

        $perPrize = ScratchCard::whereNotNull('prize_id')->whereBetween('scratched_at', [$from, $to])
            ->selectRaw('prize_id, prize_label, COUNT(*) as won')->groupBy('prize_id', 'prize_label')->orderByDesc('won')->get();

        $usedValue = \Modules\Promotion\App\Models\CouponUsage::whereIn('coupon_id', ScratchCard::whereBetween('scratched_at', [$from, $to])->whereNotNull('coupon_id')->select('coupon_id'))
            ->sum('discount_amount');

        return response()->json(['data' => [
            'from'                 => $from->toDateTimeString(),
            'to'                   => $to->toDateTimeString(),
            'issued'               => (clone $cards)->count(),
            'scratched'            => (clone $cards)->where('status', ScratchCard::STATUS_SCRATCHED)->count(),
            'available'            => (clone $cards)->where('status', ScratchCard::STATUS_AVAILABLE)->count(),
            'expired'              => (clone $cards)->where('status', ScratchCard::STATUS_EXPIRED)->count(),
            'voided'               => (clone $cards)->where('status', ScratchCard::STATUS_VOIDED)->count(),
            'prizes'               => $perPrize,
            'used_voucher_amount'  => (int) $usedValue,
        ]]);
    }

    public function winners(Request $request): JsonResponse
    {
        $this->authorizeSuper($request);
        [$from, $to] = $this->range($request);

        $page = $this->winnersQuery($from, $to)->paginate(min(100, max(1, (int) $request->input('per_page', 20))));

        return response()->json([
            'data' => $page->getCollection()->map(fn (ScratchCard $c) => $this->winnerRow($c))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function exportWinners(Request $request): StreamedResponse
    {
        $this->authorizeSuper($request);
        [$from, $to] = $this->range($request);

        $sheet = ($spreadsheet = new Spreadsheet())->getActiveSheet();
        $sheet->setTitle('Trung thuong');
        $sheet->fromArray(['STT', 'Thời gian', 'Khách hàng', 'SĐT', 'Mã đơn', 'Nguồn thẻ', 'Giải', 'Mã voucher', 'Giá trị', 'Đã dùng', 'Trạng thái thẻ'], null, 'A1');

        $row = 2;
        $this->winnersQuery($from, $to)->chunk(500, function ($cards) use ($sheet, &$row) {
            foreach ($cards as $card) {
                $r = $this->winnerRow($card);
                $sheet->fromArray([$row - 1, $r['scratched_at'], $r['customer_name'], $r['customer_phone'], $r['order_code'], $r['source'], $r['prize_label'], $r['coupon_code'], $r['coupon_value'], $r['coupon_used'] ? 'Có' : 'Chưa', $r['card_status']], null, 'A' . $row++);
            }
        });

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, 'danh-sach-trung-the-cao-' . $from->format('Ymd') . '-' . $to->format('Ymd') . '.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function winnersQuery(Carbon $from, Carbon $to)
    {
        return ScratchCard::whereNotNull('scratched_at')->whereBetween('scratched_at', [$from, $to])
            ->with(['customer', 'order', 'coupon'])->orderByDesc('scratched_at')->orderBy('id');
    }

    private function winnerRow(ScratchCard $c): array
    {
        return [
            'id'             => $c->id,
            'scratched_at'   => $c->scratched_at?->toDateTimeString(),
            'customer_id'    => $c->customer_id,
            'customer_name'  => $c->customer?->fullname,
            'customer_phone' => $c->customer?->phone,
            'order_code'     => $c->order?->order_code,
            'source'         => $c->source,
            'card_status'    => $c->status,
            'prize_label'    => $c->prize_label,
            'prize_tier'     => $c->prize_tier,
            'coupon_code'    => $c->coupon?->code,
            'coupon_value'   => $c->coupon ? (float) $c->coupon->value : null,
            'coupon_used'    => $c->coupon ? (int) $c->coupon->used_count > 0 : false,
        ];
    }

    private function validated(Request $request, ?ScratchCardPrize $prize = null): array
    {
        $required = $prize ? 'sometimes' : 'required';

        $data = $request->validate([
            'label'           => "{$required}|string|max:255",
            'type'            => ["{$required}", Rule::in(['coupon', 'none'])],
            'tier'            => ['sometimes', Rule::in(ScratchCardPrize::TIERS)],
            'coupon_type'     => ['nullable', Rule::in(['fixed', 'percentage'])],
            'coupon_value'    => 'nullable|numeric|min:0',
            'min_order_value' => 'nullable|numeric|min:0',
            'max_discount'    => 'nullable|numeric|min:0',
            'validity_days'   => 'sometimes|integer|min:1|max:365',
            'weight'          => 'sometimes|integer|min:1',
            'daily_limit'     => 'nullable|integer|min:1',
            'total_limit'     => 'nullable|integer|min:1',
            'is_fallback'     => 'sometimes|boolean',
            'is_active'       => 'sometimes|boolean',
        ]);

        $type = $data['type'] ?? $prize?->type;
        if ($type === 'coupon' && (empty($data['coupon_value'] ?? $prize?->coupon_value) || empty($data['coupon_type'] ?? $prize?->coupon_type))) {
            throw ValidationException::withMessages(['coupon_value' => ['Giải voucher cần loại (coupon_type) và giá trị (coupon_value).']]);
        }

        $ctype = $data['coupon_type'] ?? $prize?->coupon_type;
        $cval  = $data['coupon_value'] ?? $prize?->coupon_value;
        if ($type === 'coupon' && $ctype === 'percentage' && (float) $cval > 100) {
            throw ValidationException::withMessages(['coupon_value' => ['Giảm theo phần trăm không được quá 100.']]);
        }

        // Giải dự phòng không giới hạn lượt — nếu không thì "hết ngân sách" vẫn bị bí.
        if ($data['is_fallback'] ?? $prize?->is_fallback) {
            $data['daily_limit'] = $data['total_limit'] = null;
        }

        return $data;
    }

    private function range(Request $request): array
    {
        $request->validate(['from' => 'sometimes|nullable|date', 'to' => 'sometimes|nullable|date']);

        return [
            $request->filled('from') ? Carbon::parse($request->input('from'))->startOfDay() : now()->subDays(30)->startOfDay(),
            $request->filled('to') ? Carbon::parse($request->input('to'))->endOfDay() : now()->endOfDay(),
        ];
    }

    private function authorizeSuper(Request $request): void
    {
        abort_unless($request->user()->isSuperAdmin(), 403, 'Chỉ Super Admin được thực hiện thao tác này.');
    }
}
