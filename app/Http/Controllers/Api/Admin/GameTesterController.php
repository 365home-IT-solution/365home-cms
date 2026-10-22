<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CollectionCampaign;
use App\Models\CollectionCustomerPiece;
use App\Models\CollectionRedemption;
use App\Models\Customer;
use App\Models\Referral;
use App\Models\ReferralCode;
use App\Models\ReferralSetting;
use App\Models\ScratchCard;
use App\Models\ScratchCardPrize;
use App\Models\ScratchCardSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Category\Entities\Category;
use Modules\Payment\Entities\Order;
use Modules\Payment\Entities\OrderItem;
use Modules\Product\App\Models\Product;
use Modules\Promotion\App\Models\Coupon;

/**
 * CÔNG CỤ THỬ mini game (trang /game-tester) — chỉ bật khi GAME_TESTER_ENABLED=true và chỉ Super Admin dùng được.
 * Tạo khách [DEMO], mô phỏng đơn đặt phòng đi qua đúng hook thật (Order::updated) để thử thẻ cào / mảnh ghép / giới thiệu mà không cần app.
 * Mọi dữ liệu demo gắn tên khách "[DEMO] …" nên dọn được bằng POST reset.
 */
class GameTesterController extends Controller
{
    private const DEMO_PREFIX = '[DEMO] ';

    public function context(Request $request): JsonResponse
    {
        $this->guard($request);

        return response()->json([
            'branches'  => $this->branchOptions(),
            'rooms'     => Product::withoutGlobalScopes()->where('is_activated', true)->orderBy('name')->limit(60)->get(['id', 'name']),
            'customers' => Customer::where('fullname', 'like', self::DEMO_PREFIX . '%')->orderByDesc('created_at')->get(['id', 'fullname', 'phone', 'created_at']),
        ]);
    }

    // Nạp bộ dữ liệu mẫu: bộ giải thẻ cào (có giải dự phòng), bật 3 trò, 1 chiến dịch mảnh ghép với ảnh SVG sinh sẵn. Chạy lại không tạo trùng.
    public function seed(Request $request): JsonResponse
    {
        $this->guard($request);

        if (! ScratchCardPrize::exists()) {
            foreach ([
                ['label' => 'Giảm 50.000đ cho lần đặt sau', 'type' => 'coupon', 'tier' => 'normal', 'coupon_type' => 'fixed', 'coupon_value' => 50000, 'weight' => 60],
                ['label' => 'Giảm 100.000đ cho lần đặt sau', 'type' => 'coupon', 'tier' => 'rare', 'coupon_type' => 'fixed', 'coupon_value' => 100000, 'weight' => 25, 'daily_limit' => 20],
                ['label' => 'Giảm 30% (tối đa 300.000đ)', 'type' => 'coupon', 'tier' => 'jackpot', 'coupon_type' => 'percentage', 'coupon_value' => 30, 'max_discount' => 300000, 'weight' => 5, 'total_limit' => 10],
                ['label' => 'Chúc bạn may mắn lần sau', 'type' => 'none', 'tier' => 'normal', 'weight' => 10, 'is_fallback' => true],
            ] as $prize) {
                ScratchCardPrize::create($prize);
            }
        }

        ScratchCardSetting::current()->update(['is_enabled' => true]);
        ReferralSetting::current()->update(['is_enabled' => true]);

        $campaign = CollectionCampaign::where('name', 'Hành trình 365home (demo)')->first();
        if (! $campaign) {
            // Ưu tiên danh mục con (LUMEN, PIXEL…) vì đa số đơn thật nằm ở cấp này; không chọn cha lẫn con trong cùng chiến dịch
            $branches = Category::withoutGlobalScopes()->where('category_type', 'product')->whereNotNull('parent_id')->orderBy('id')->get()->unique('parent_id')->take(4)->values();
            if ($branches->count() < 2) {
                $branches = Category::withoutGlobalScopes()->whereNull('parent_id')->where('category_type', 'product')->orderBy('id')->limit(4)->get();
            }
            if ($branches->isEmpty()) {
                return response()->json(['message' => 'Chưa có chi nhánh nào trong hệ thống để tạo mảnh ghép.'], 422);
            }

            $campaign = CollectionCampaign::create([
                'name' => 'Hành trình 365home (demo)', 'description' => 'Ở ' . min(3, $branches->count()) . ' chi nhánh khác nhau, nhận voucher 300.000đ',
                'cover_image' => $this->svg('cover', 'HÀNH TRÌNH 365HOME', '#0f766e', '#134e4a', 640, 280),
                'start_at' => now()->subDay(), 'end_at' => now()->addDays(60), 'redeem_until' => now()->addDays(90),
                'pieces_required' => min(3, $branches->count()), 'reward_label' => 'Voucher 300.000đ', 'coupon_type' => 'fixed', 'coupon_value' => 300000,
            ]);

            $palette = [['#ea580c', '#9a3412'], ['#2563eb', '#1e3a8a'], ['#9333ea', '#581c87'], ['#16a34a', '#14532d']];
            foreach ($branches as $i => $branch) {
                $campaign->pieces()->create([
                    'branch_id' => $branch->id, 'sort_order' => $i,
                    'image' => $this->svg("piece-{$i}", (string) ($i + 1), $palette[$i][0], $palette[$i][1], 240, 240, Str::limit($branch->name, 18, '…')),
                ]);
            }
        }

        return response()->json(['message' => 'Đã nạp dữ liệu mẫu.', 'campaign_id' => $campaign->id]);
    }

    public function createCustomer(Request $request): JsonResponse
    {
        $this->guard($request);

        $data = $request->validate(['name' => 'nullable|string|max:60']);
        $name = trim($data['name'] ?? '') ?: 'Khách ' . random_int(100, 999);

        $customer = Customer::create([
            'fullname' => self::DEMO_PREFIX . $name, 'phone' => '849' . random_int(10000000, 99999999), 'status' => Customer::STATUS_ACTIVE,
        ]);

        return response()->json(['customer' => $this->customerRow($customer), 'token' => $customer->createToken('game-tester')->plainTextToken], 201);
    }

    public function customerToken(Request $request, string $id): JsonResponse
    {
        $this->guard($request);

        $customer = $this->demoCustomer($id);

        return response()->json(['customer' => $this->customerRow($customer), 'token' => $customer->createToken('game-tester')->plainTextToken]);
    }

    public function customerOrders(Request $request, string $id): JsonResponse
    {
        $this->guard($request);
        $customer = $this->demoCustomer($id);

        return response()->json(['data' => Order::withoutGlobalScopes()->where('customer_id', $customer->id)->orderByDesc('id')->get()
            ->map(fn (Order $o) => $this->orderRow($o))->values()]);
    }

    /**
     * Tạo đơn demo cho khách ở 1 chi nhánh. status = paid | deposit; complete = true thì cho trả phòng ngay (đi qua hook thật như khi lễ tân bấm trả phòng).
     */
    public function createOrder(Request $request): JsonResponse
    {
        $this->guard($request);

        $data = $request->validate([
            'customer_id' => 'required|string',
            'branch_id'   => 'required|integer',
            'room_id'     => 'nullable',
            'status'      => 'sometimes|in:paid,deposit',
            'complete'    => 'sometimes|boolean',
        ]);

        $customer = $this->demoCustomer($data['customer_id']);
        $roomId   = $data['room_id'] ?? Product::withoutGlobalScopes()->where('is_activated', true)->value('id');
        $amount   = random_int(2, 6) * 100000;

        $order = Order::withoutEvents(fn () => Order::forceCreate([
            'order_code' => 'DEMO' . random_int(100000, 999999), 'status' => $data['status'] ?? 'paid', 'order_status' => 'staying',
            'amount' => $amount, 'full_amount' => $amount, 'customer_id' => $customer->id, 'buyer_phone' => $customer->phone,
            'buyer_name' => $customer->fullname, 'category_id' => $data['branch_id'],
        ]));

        OrderItem::forceCreate([
            'order_id' => $order->id, 'product_id' => $roomId, 'name' => 'Phòng demo', 'price' => $amount, 'quantity' => 1, 'extra_fee' => 0,
            'checkin_date' => now()->subDay()->setTime(14, 0), 'checkout_date' => now()->setTime(12, 0),
        ]);

        if ($request->boolean('complete', true)) {
            $order->update(['order_status' => 'checked_out', 'checked_out_at' => now()]);
        }

        return response()->json(['data' => $this->orderRow($order->fresh())], 201);
    }

    public function completeOrder(Request $request, int $id): JsonResponse
    {
        $this->guard($request);

        $order = $this->demoOrder($id);
        $order->update(['order_status' => 'checked_out', 'checked_out_at' => now()]);

        return response()->json(['data' => $this->orderRow($order->fresh())]);
    }

    // Hoàn tiền đơn demo — kích hoạt đúng luồng thu hồi thẻ / mảnh / thưởng giới thiệu.
    public function refundOrder(Request $request, int $id): JsonResponse
    {
        $this->guard($request);

        $order = $this->demoOrder($id);
        $order->update(['status' => 'refunded']);

        return response()->json(['data' => $this->orderRow($order->fresh())]);
    }

    // Xoá toàn bộ khách [DEMO] cùng thẻ cào, mảnh, giới thiệu, voucher và đơn của họ (không đụng dữ liệu thật).
    public function reset(Request $request): JsonResponse
    {
        $this->guard($request);

        $ids = Customer::withTrashed()->where('fullname', 'like', self::DEMO_PREFIX . '%')->pluck('id');

        ScratchCard::whereIn('customer_id', $ids)->delete();
        CollectionCustomerPiece::whereIn('customer_id', $ids)->delete();
        CollectionRedemption::whereIn('customer_id', $ids)->delete();
        Referral::whereIn('referrer_id', $ids)->orWhereIn('referee_id', $ids)->delete();
        ReferralCode::whereIn('customer_id', $ids)->delete();
        Coupon::withoutGlobalScopes()->whereIn('customer_id', $ids)->delete();

        $orderIds = Order::withoutGlobalScopes()->whereIn('customer_id', $ids)->pluck('id');
        OrderItem::whereIn('order_id', $orderIds)->delete();
        Order::withoutEvents(fn () => Order::withoutGlobalScopes()->whereIn('id', $orderIds)->delete());

        Customer::withTrashed()->whereIn('id', $ids)->each(function (Customer $c) {
            $c->tokens()->delete();
            $c->forceDelete();
        });

        return response()->json(['message' => "Đã dọn {$ids->count()} khách demo."]);
    }

    // Chi nhánh để chọn khi tạo đơn / mảnh ghép: danh mục product ở mọi cấp, danh mục con ghi "Cha › Con".
    private function branchOptions()
    {
        $all = Category::withoutGlobalScopes()->where('category_type', 'product')->orderBy('name')->get(['id', 'name', 'slug', 'parent_id'])->keyBy('id');

        return $all->map(fn ($c) => [
            'id' => $c->id, 'slug' => $c->slug, 'parent_id' => $c->parent_id,
            'name' => $c->parent_id && $all->has($c->parent_id) ? Str::limit($all[$c->parent_id]->name, 24, '…') . ' › ' . $c->name : $c->name,
        ])->sortBy('name')->values();
    }

    private function guard(Request $request): void
    {
        abort_unless(config('app.game_tester_enabled'), 404);
        abort_unless($request->user()?->isSuperAdmin(), 403, 'Chỉ Super Admin được dùng công cụ thử.');
    }

    private function demoCustomer(string $id): Customer
    {
        return Customer::where('fullname', 'like', self::DEMO_PREFIX . '%')->findOrFail($id);
    }

    private function demoOrder(int $id): Order
    {
        $order = Order::withoutGlobalScopes()->findOrFail($id);
        abort_unless(Str::startsWith((string) $order->order_code, 'DEMO'), 403, 'Chỉ thao tác được trên đơn demo.');

        return $order;
    }

    private function customerRow(Customer $c): array
    {
        return ['id' => $c->id, 'fullname' => $c->fullname, 'phone' => $c->phone];
    }

    private function orderRow(Order $o): array
    {
        return [
            'id' => $o->id, 'order_code' => $o->order_code, 'status' => $o->status, 'order_status' => $o->order_status,
            'amount' => (int) $o->amount, 'branch_id' => $o->category_id,
            'branch_name' => Category::withoutGlobalScopes()->whereKey($o->category_id)->value('name'),
        ];
    }

    // Ảnh SVG minh hoạ lưu ở disk public (mảnh ghép / ảnh bìa demo) — hiển thị được bằng thẻ <img> như ảnh upload thật.
    private function svg(string $name, string $text, string $c1, string $c2, int $w, int $h, ?string $sub = null): string
    {
        $subtitle = $sub ? '<text x="50%" y="' . ($h - 28) . '" text-anchor="middle" font-family="Arial" font-size="18" fill="#fff" opacity=".9">' . e($sub) . '</text>' : '';
        $size     = mb_strlen($text) > 3 ? 30 : 96;
        $svg      = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="{$w}" height="{$h}" viewBox="0 0 {$w} {$h}"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="{$c1}"/><stop offset="1" stop-color="{$c2}"/></linearGradient></defs><rect width="{$w}" height="{$h}" rx="28" fill="url(#g)"/><circle cx="{$w}" cy="{$h}" r="70" fill="#fff" opacity=".12"/><text x="50%" y="52%" text-anchor="middle" font-family="Arial" font-weight="700" font-size="{$size}" fill="#fff">{$text}</text>{$subtitle}</svg>
SVG;

        Storage::disk('public')->put($path = "collections/demo/{$name}.svg", $svg);

        return $path;
    }
}
