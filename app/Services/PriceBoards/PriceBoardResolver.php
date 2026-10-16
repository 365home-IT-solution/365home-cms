<?php

declare(strict_types=1);

namespace App\Services\PriceBoards;

use Illuminate\Support\Collection;
use Modules\Product\App\Models\PriceBoard;
use Modules\Product\App\Models\Product;

/**
 * BỘ TÍNH THUẦN của bảng giá v2 — KHÔNG ghi DB. Từ "spec" của bảng (phạm vi + các bộ giá) cho ra:
 *  - phòng nào nằm trong phạm vi,
 *  - mỗi phòng nhận bộ giá nào (gán đích danh → bộ mặc định cùng loại phòng → không khớp),
 *  - lỗi vi phạm ràng buộc (mục 3.2 tài liệu v2).
 * Dùng chung cho lưu thật, xem trước (preview), danh sách phòng (/rooms) và summary.
 *
 * Spec (mảng):
 *  partner_id, scope (rooms|branches|partner), branch_ids[], product_ids[] (cấp bảng), category_id (v1),
 *  sheets[]: { name, pricing_mode, room_style, is_default, product_ids[] }.
 */
class PriceBoardResolver
{
    /**
     * @param array<string,mixed> $spec
     * @return array{
     *   products: Collection<string, Product>,
     *   assignments: array<string, array{sheet: int, matched_by: string}>,
     *   unmatched: list<string>,
     *   per_sheet: array<int,int>,
     *   errors: array<string, list<string>>
     * }
     */
    public function resolve(array $spec): array
    {
        $errors   = [];
        $sheets   = array_values($spec['sheets'] ?? []);
        $partner  = (string) ($spec['partner_id'] ?? '');
        $scope    = $spec['scope'] ?? PriceBoard::SCOPE_ROOMS;

        // ── 1) Phòng nằm trong phạm vi ───────────────────────────────────────────
        $explicitIds = collect($spec['product_ids'] ?? []);
        foreach ($sheets as $sheet) {
            $explicitIds = $explicitIds->merge($sheet['product_ids'] ?? []);
        }
        $explicitIds = $explicitIds->filter()->map(fn ($v) => (string) $v)->unique()->values();

        if ($scope === PriceBoard::SCOPE_ROOMS) {
            $products = $explicitIds->isEmpty()
                ? collect()
                : Product::query()->where('partner_id', $partner)->whereIn('id', $explicitIds)->get()->keyBy('id');

            $missing = $explicitIds->diff($products->keys());
            if ($missing->isNotEmpty()) {
                $errors['product_ids'][] = 'Phòng không thuộc đối tác: ' . $missing->implode(', ') . '.';
            }

            // Bảng v1 vẫn khoá theo 1 chi nhánh (branch_id): phòng phải nằm trong chi nhánh đó.
            if (! empty($spec['category_id']) && $products->isNotEmpty()) {
                $inBranch = Product::query()
                    ->whereIn('id', $products->keys())
                    ->whereHas('categories', fn ($q) => $q->whereIn('categories.id', PriceBoard::branchCategoryIds((int) $spec['category_id'])))
                    ->pluck('id');

                $outside = $products->keys()->diff($inBranch);
                if ($outside->isNotEmpty()) {
                    $errors['product_ids'][] = 'Phòng không thuộc chi nhánh đã chọn: ' . $outside->implode(', ') . '.';
                }
            }
        } else {
            $query = Product::query()->where('partner_id', $partner);

            if ($scope === PriceBoard::SCOPE_BRANCHES) {
                $categoryIds = collect($spec['branch_ids'] ?? [])
                    ->flatMap(fn ($id) => PriceBoard::branchCategoryIds((int) $id))
                    ->unique()
                    ->values()
                    ->all();

                $query->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $categoryIds ?: [0]));
            }

            $products = $query->get()->keyBy('id');

            // Phòng gán đích danh phải nằm trong phạm vi bảng.
            $outside = $explicitIds->diff($products->keys());
            if ($outside->isNotEmpty()) {
                $errors['product_ids'][] = 'Phòng không nằm trong phạm vi của bảng: ' . $outside->implode(', ') . '.';
            }
        }

        // ── 2) Ràng buộc bộ giá ──────────────────────────────────────────────────
        $explicit = []; // product_id => sheet index
        $defaults = []; // style (1|2|'any') => sheet index

        foreach ($sheets as $i => $sheet) {
            $mode  = $sheet['pricing_mode'] ?? PriceBoard::MODE_OVERRIDE;
            $style = $sheet['room_style'] ?? null;

            if (! empty($sheet['is_default'])) {
                $key = $style === null ? 'any' : (int) $style;

                if (isset($defaults[$key])) {
                    $errors["sheets.{$i}.is_default"][] = $key === 'any'
                        ? 'Chỉ được có một bộ giá mặc định áp cho mọi loại phòng.'
                        : 'Mỗi loại phòng chỉ có tối đa một bộ giá mặc định.';
                } else {
                    $defaults[$key] = $i;
                }
            }

            foreach ($sheet['product_ids'] ?? [] as $productId) {
                $productId = (string) $productId;

                if (isset($explicit[$productId])) {
                    $errors["sheets.{$i}.product_ids"][] = "Phòng {$productId} đã nằm trong bộ giá khác của bảng.";
                    continue;
                }

                $explicit[$productId] = $i;

                $product = $products->get($productId);
                if ($product && $mode === PriceBoard::MODE_OVERRIDE && $style !== null && (int) $product->styles !== (int) $style) {
                    $errors["sheets.{$i}.product_ids"][] = "Phòng \"{$product->name}\" không cùng loại với bộ giá (theo "
                        . ((int) $style === 1 ? 'giờ' : 'ngày') . ').';
                }
            }
        }

        // ── 3) Phòng nào nhận bộ nào ─────────────────────────────────────────────
        $assignments = [];
        $unmatched   = [];
        $perSheet    = [];

        foreach ($products as $productId => $product) {
            $style = (int) ($product->styles ?? 1);

            if (isset($explicit[$productId])) {
                $assignments[$productId] = ['sheet' => $explicit[$productId], 'matched_by' => 'product'];
            } elseif (isset($defaults[$style])) {
                $assignments[$productId] = ['sheet' => $defaults[$style], 'matched_by' => 'default'];
            } elseif (isset($defaults['any'])) {
                $assignments[$productId] = ['sheet' => $defaults['any'], 'matched_by' => 'default'];
            } else {
                $unmatched[] = (string) $productId;
                continue;
            }

            $index = $assignments[$productId]['sheet'];
            $perSheet[$index] = ($perSheet[$index] ?? 0) + 1;
        }

        return [
            'products'    => $products,
            'assignments' => $assignments,
            'unmatched'   => $unmatched,
            'per_sheet'   => $perSheet,
            'errors'      => $errors,
        ];
    }
}
