<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CollectionCampaign;
use App\Models\CollectionCustomerPiece;
use App\Models\CollectionRedemption;
use App\Models\Customer;
use App\Models\User;
use App\Support\CompletedOrders;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Payment\Entities\Order;
use Modules\Promotion\App\Models\Coupon;

/**
 * Mini game SƯU TẦM MẢNH GHÉP theo chi nhánh: đơn của khách đã đăng nhập trả phòng (đã trả đủ) ở chi nhánh X trong thời gian chiến dịch ⇒ nhận
 * mảnh X (không trùng); đủ pieces_required mảnh khác nhau ⇒ đổi thưởng 1 lần ra voucher cố định (dùng chung, 365home chịu, vào /coupons/mine).
 * Gọi từ Order::booted (đổi trạng thái) và lệnh scratch-cards:sync (quét bù).
 */
class CollectionService
{
    // Trả về số mảnh MỚI nhận từ đơn này (qua mọi chiến dịch đang chạy có chi nhánh của đơn).
    public function collectForOrder(Order $order): int
    {
        if (! $order->customer_id || ! $order->category_id || ! CompletedOrders::isCompleted($order)) {
            return 0;
        }

        $when  = CompletedOrders::completedAt($order);
        $new   = 0;
        $chain = self::categoryChain((int) $order->category_id);

        CollectionCampaign::where('is_active', true)->where('start_at', '<=', $when)->where('end_at', '>=', $when)
            ->whereHas('pieces', fn ($q) => $q->whereIn('branch_id', $chain))
            ->each(function (CollectionCampaign $campaign) use ($order, $when, $chain, &$new) {
                // Đơn có thể nằm ở danh mục con (vd. LUMEN thuộc toà nhà 254 Xuân Thủy): lấy mảnh khớp SÂU NHẤT, mỗi đơn chỉ 1 mảnh / chiến dịch
                $hit = $campaign->pieces()->whereIn('branch_id', $chain)->pluck('branch_id')->sortBy(fn ($id) => array_search((int) $id, $chain, true))->first();
                if ($hit === null) {
                    return;
                }

                try {
                    $piece = CollectionCustomerPiece::firstOrCreate(
                        ['campaign_id' => $campaign->id, 'customer_id' => $order->customer_id, 'branch_id' => $hit],
                        ['order_id' => $order->id, 'collected_at' => $when],
                    );
                } catch (UniqueConstraintViolationException) {
                    return;
                }

                if (! $piece->wasRecentlyCreated) {
                    return;
                }

                $new++;
                $this->notify($campaign, (string) $order->customer_id);
            });

        return $new;
    }

    // [danh mục của đơn, cha, ông…] — orders.category_id có thể là chi nhánh gốc hoặc danh mục con của nó.
    public static function categoryChain(int $categoryId): array
    {
        $chain = [];
        $id    = $categoryId;

        while ($id && ! in_array($id, $chain, true) && count($chain) < 6) {
            $chain[] = $id;
            $id      = (int) DB::table('categories')->where('id', $id)->value('parent_id');
        }

        return $chain;
    }

    // Đơn bị huỷ/hoàn tiền: thu hồi mảnh sinh từ đơn đó, trừ khi khách đã đổi thưởng chiến dịch ấy (voucher giữ nguyên).
    public function voidForOrder(Order $order): void
    {
        CollectionCustomerPiece::where('order_id', $order->id)->get()->each(function (CollectionCustomerPiece $piece) use ($order) {
            $redeemed = CollectionRedemption::where('campaign_id', $piece->campaign_id)->where('customer_id', $piece->customer_id)->exists();

            if ($redeemed) {
                Log::info('Collection: khách đã đổi thưởng, giữ mảnh dù đơn bị huỷ/hoàn tiền', ['piece_id' => $piece->id]);

                return;
            }

            $piece->delete();

            // Khách còn đơn khác đủ điều kiện ở cùng chi nhánh thì vẫn phải có mảnh (mảnh cũ gắn với đơn vừa bị thu hồi)
            Order::withoutGlobalScopes()->where('customer_id', $piece->customer_id)->where('id', '!=', $order->id)
                ->where('status', 'paid')
                ->each(fn (Order $other) => $this->collectForOrder($other));
        });
    }

    // Quét bù đơn đã trả phòng gần đây mà hook bỏ sót. Trả về số mảnh mới.
    public function sync(int $lookbackDays = 3): int
    {
        $new = 0;

        if (! CollectionCampaign::where('is_active', true)->exists()) {
            return 0;
        }

        CompletedOrders::recent($lookbackDays)->whereNotNull('category_id')
            ->each(function (Order $order) use (&$new) {
                $new += $this->collectForOrder($order);
            });

        return $new;
    }

    /**
     * Đổi thưởng: đủ mảnh + còn hạn ⇒ sinh voucher. Gọi lại trả đúng kết quả cũ (already_claimed = true).
     *
     * @return array{campaign: CollectionCampaign, already_claimed: bool}
     */
    public function redeem(int $campaignId, Customer $customer): array
    {
        return DB::transaction(function () use ($campaignId, $customer) {
            $campaign = CollectionCampaign::where('is_active', true)->where('start_at', '<=', now())->lockForUpdate()->findOrFail($campaignId);

            $redeemed = CollectionRedemption::where('campaign_id', $campaign->id)->where('customer_id', $customer->id)->first();
            if ($redeemed) {
                return ['campaign' => $campaign, 'already_claimed' => true];
            }

            if ($campaign->redeem_until->isPast()) {
                throw ValidationException::withMessages(['message' => ['Đã quá hạn đổi thưởng']])->status(422);
            }

            if ($this->collectedCount($campaign, (string) $customer->id) < $campaign->pieces_required) {
                throw ValidationException::withMessages(['message' => ['Bạn chưa đủ mảnh để đổi thưởng']])->status(422);
            }

            $coupon = $this->createCoupon($campaign, $customer);
            CollectionRedemption::create(['campaign_id' => $campaign->id, 'customer_id' => $customer->id, 'coupon_id' => $coupon->id, 'redeemed_at' => now()]);

            return ['campaign' => $campaign, 'already_claimed' => false];
        });
    }

    // Chiến dịch khách xem được: đang chạy, hoặc đã kết thúc nhưng khách còn quyền đổi thưởng (đủ mảnh, chưa đổi, trong redeem_until).
    public function visibleCampaigns(?Customer $customer)
    {
        return CollectionCampaign::with('pieces.branch')->where('is_active', true)->where('start_at', '<=', now())
            ->where(fn ($q) => $q->where('end_at', '>=', now())->orWhere('redeem_until', '>=', now()))
            ->orderByDesc('start_at')->get()
            ->filter(fn (CollectionCampaign $c) => $c->end_at->isFuture() || ($customer && in_array($this->statusFor($c, (string) $customer->id), ['ready', 'redeemed'], true)))
            ->values();
    }

    public function collectedCount(CollectionCampaign $campaign, string $customerId): int
    {
        return CollectionCustomerPiece::where('campaign_id', $campaign->id)->where('customer_id', $customerId)->count();
    }

    // in_progress · ready (đủ mảnh, chưa đổi, còn hạn) · redeemed · ended (hết hạn mà chưa đủ / chưa đổi kịp)
    public function statusFor(CollectionCampaign $campaign, ?string $customerId): string
    {
        if ($customerId && CollectionRedemption::where('campaign_id', $campaign->id)->where('customer_id', $customerId)->exists()) {
            return 'redeemed';
        }

        $enough = $customerId && $this->collectedCount($campaign, $customerId) >= $campaign->pieces_required;

        if ($enough) {
            return $campaign->redeem_until->isPast() ? 'ended' : 'ready';
        }

        return $campaign->end_at->isPast() ? 'ended' : 'in_progress';
    }

    // Shape trả cho app (khách chưa đăng nhập: customerId = null ⇒ mọi mảnh collected = false).
    public function campaignPayload(CollectionCampaign $campaign, ?string $customerId): array
    {
        $campaign->loadMissing('pieces.branch');

        $mine = $customerId
            ? CollectionCustomerPiece::where('campaign_id', $campaign->id)->where('customer_id', $customerId)->with('order')->get()->keyBy('branch_id')
            : collect();

        $redemption = $customerId ? CollectionRedemption::with('coupon')->where('campaign_id', $campaign->id)->where('customer_id', $customerId)->first() : null;

        return [
            'id'              => $campaign->id,
            'name'            => $campaign->name,
            'description'     => $campaign->description,
            'cover_url'       => self::imageUrl($campaign->cover_image),
            'start_at'        => $campaign->start_at->toDateTimeString(),
            'end_at'          => $campaign->end_at->toDateTimeString(),
            'redeem_until'    => $campaign->redeem_until->toDateTimeString(),
            'pieces_required' => $campaign->pieces_required,
            'collected_count' => $mine->count(),
            'status'          => $this->statusFor($campaign, $customerId),
            'reward'          => ['label' => $campaign->reward_label, 'coupon' => ScratchCardService::couponPayload($redemption?->coupon)],
            'pieces'          => $campaign->pieces->map(fn ($p) => [
                'branch_id'    => $p->branch_id,
                'branch_slug'  => $p->branch?->slug,
                'branch_name'  => $p->branch?->name,
                'image_url'    => self::imageUrl($p->image),
                'collected'    => $mine->has($p->branch_id),
                'collected_at' => $mine->get($p->branch_id)?->collected_at?->toDateTimeString(),
                'order_code'   => $mine->get($p->branch_id)?->order?->order_code,
            ])->values(),
        ];
    }

    public static function imageUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return Str::startsWith($path, ['http://', 'https://']) ? $path : Storage::disk('public')->url($path);
    }

    private function notify(CollectionCampaign $campaign, string $customerId): void
    {
        $customer = Customer::find($customerId);
        if (! $customer) {
            return;
        }

        $full = $this->collectedCount($campaign, $customerId) >= $campaign->pieces_required;

        try {
            app(NotificationFcmService::class)->sendToCustomer(
                $customer,
                $full ? 'Bạn đã đủ mảnh ghép!' : 'Bạn nhận được mảnh ghép mới',
                $full ? "Bạn đã đủ mảnh của \"{$campaign->name}\". Đổi thưởng ngay!" : "Thêm một mảnh cho \"{$campaign->name}\". Xem tiến độ sưu tầm của bạn.",
                'collection_piece', [], '/games/collections/' . $campaign->id,
            );
        } catch (\Throwable $e) {
            Log::warning('Collection: gửi thông báo thất bại', ['campaign_id' => $campaign->id, 'error' => $e->getMessage()]);
        }
    }

    private function createCoupon(CollectionCampaign $campaign, Customer $customer): Coupon
    {
        do {
            $code = 'CL' . strtoupper(Str::random(6));
        } while (Coupon::withoutGlobalScopes()->where('code', $code)->exists());

        return Coupon::create([
            'partner_id'      => null,
            'funded_by'       => Coupon::FUNDED_PLATFORM,
            'source'          => 'collection',
            'code'            => $code,
            'name'            => $campaign->reward_label,
            'description'     => 'Voucher từ sưu tầm mảnh ghép: ' . $campaign->name,
            'type'            => $campaign->coupon_type,
            'value'           => $campaign->coupon_value,
            'apply_type'      => 'all_rooms',
            'min_order_value' => $campaign->min_order_value,
            'max_discount'    => $campaign->max_discount,
            'usage_limit'     => 1,
            'used_count'      => 0,
            'start_at'        => now(),
            'end_at'          => now()->addDays($campaign->validity_days)->endOfDay(),
            'is_active'       => true,
            'is_exclusive'    => true,
            'customer_id'     => $customer->id,
            'created_by'      => User::role(config('filament-shield.super_admin.name'))->value('id'),
        ]);
    }
}
