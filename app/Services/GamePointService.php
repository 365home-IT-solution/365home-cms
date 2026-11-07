<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Models\GamePointReward;
use App\Models\GamePointTransaction;
use App\Models\GamePointWallet;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Promotion\App\Models\Coupon;

/**
 * ĐIỂM GAME: khách thắng mini game (hiện có Tiến lên) được cộng điểm, đổi điểm lấy voucher trong danh mục quà (game_point_rewards).
 * Điểm chỉ cộng từ kết quả ván / admin cộng tay, KHÔNG nạp được, KHÔNG chuyển cho người khác, thua không bị trừ.
 * Voucher đổi được là mã cá nhân dùng chung (partner_id null, 365 HOME chịu, source = game_points) nằm trong GET /api/coupons/mine.
 */
class GamePointService
{
    public function balance(string $customerId): int
    {
        return (int) GamePointWallet::whereKey($customerId)->value('balance');
    }

    public function wallet(string $customerId): GamePointWallet
    {
        return GamePointWallet::find($customerId) ?? new GamePointWallet(['customer_id' => $customerId, 'balance' => 0, 'total_earned' => 0, 'total_redeemed' => 0]);
    }

    // Điểm đã nhận từ 1 nguồn trong hôm nay (để áp trần điểm / ngày).
    public function earnedToday(string $customerId, string $source): int
    {
        return (int) GamePointTransaction::where('customer_id', $customerId)->where('source', $source)
            ->where('type', GamePointTransaction::TYPE_EARN)->where('created_at', '>=', now()->startOfDay())->sum('amount');
    }

    public function earn(string $customerId, int $amount, string $source, ?string $referenceId = null, ?string $note = null): ?GamePointTransaction
    {
        if ($amount <= 0) {
            return null;
        }

        return DB::transaction(function () use ($customerId, $amount, $source, $referenceId, $note) {
            $wallet = $this->lockWallet($customerId);
            $wallet->balance      += $amount;
            $wallet->total_earned += $amount;
            $wallet->save();

            return GamePointTransaction::create([
                'customer_id' => $customerId, 'amount' => $amount, 'balance_after' => $wallet->balance,
                'type' => GamePointTransaction::TYPE_EARN, 'source' => $source, 'reference_id' => $referenceId, 'note' => $note,
            ]);
        });
    }

    /**
     * Đổi điểm lấy 1 voucher. Khoá quà (tồn kho) + ví (số dư) trong 1 transaction để bấm liên tục không đổi vượt.
     *
     * @return array{transaction: GamePointTransaction, coupon: Coupon}
     */
    public function redeem(Customer $customer, int $rewardId): array
    {
        return DB::transaction(function () use ($customer, $rewardId) {
            $reward = GamePointReward::whereKey($rewardId)->where('is_active', true)->lockForUpdate()->first();

            if (! $reward) {
                throw ValidationException::withMessages(['message' => ['Quà không tồn tại hoặc đã ngừng đổi.']]);
            }

            if ($reward->remaining() === 0) {
                throw ValidationException::withMessages(['message' => ['Quà đã hết lượt đổi.']]);
            }

            if ($reward->per_customer_limit !== null) {
                $used = GamePointTransaction::where('customer_id', $customer->id)->where('reward_id', $reward->id)
                    ->where('type', GamePointTransaction::TYPE_REDEEM)->count();
                if ($used >= $reward->per_customer_limit) {
                    throw ValidationException::withMessages(['message' => ["Bạn đã đổi quà này tối đa {$reward->per_customer_limit} lần."]]);
                }
            }

            $wallet = $this->lockWallet((string) $customer->id);
            if ($wallet->balance < $reward->points_cost) {
                throw ValidationException::withMessages(['message' => ['Bạn chưa đủ điểm để đổi quà này.']]);
            }

            $coupon = $this->createCoupon($customer, $reward);

            $wallet->balance        -= $reward->points_cost;
            $wallet->total_redeemed += $reward->points_cost;
            $wallet->save();
            $reward->increment('redeemed_count');

            $transaction = GamePointTransaction::create([
                'customer_id' => $customer->id, 'amount' => -$reward->points_cost, 'balance_after' => $wallet->balance,
                'type' => GamePointTransaction::TYPE_REDEEM, 'source' => GamePointTransaction::SOURCE_REWARD,
                'reward_id' => $reward->id, 'coupon_id' => $coupon->id, 'note' => $reward->name,
            ]);

            return ['transaction' => $transaction, 'coupon' => $coupon];
        });
    }

    // Admin cộng (+) / trừ (−) tay, có ghi chú bắt buộc. Trừ quá số dư ⇒ lỗi.
    public function adjust(string $customerId, int $amount, string $note, ?User $admin = null): GamePointTransaction
    {
        if ($amount === 0) {
            throw ValidationException::withMessages(['amount' => ['Số điểm phải khác 0.']]);
        }

        return DB::transaction(function () use ($customerId, $amount, $note, $admin) {
            $wallet = $this->lockWallet($customerId);
            if ($wallet->balance + $amount < 0) {
                throw ValidationException::withMessages(['amount' => ["Khách chỉ còn {$wallet->balance} điểm, không trừ được {$amount}."]]);
            }

            $wallet->balance += $amount;
            if ($amount > 0) {
                $wallet->total_earned += $amount;
            }
            $wallet->save();

            return GamePointTransaction::create([
                'customer_id' => $customerId, 'amount' => $amount, 'balance_after' => $wallet->balance,
                'type' => GamePointTransaction::TYPE_ADJUST, 'source' => GamePointTransaction::SOURCE_ADMIN,
                'note' => $note, 'created_by' => $admin?->id,
            ]);
        });
    }

    public static function rewardPayload(GamePointReward $r, ?int $balance = null): array
    {
        return [
            'id'                 => $r->id,
            'name'               => $r->name,
            'description'        => $r->description,
            'points_cost'        => $r->points_cost,
            'coupon_type'        => $r->coupon_type,
            'coupon_value'       => $r->coupon_value,
            'min_order_value'    => $r->min_order_value,
            'max_discount'       => $r->max_discount,
            'validity_days'      => $r->validity_days,
            'remaining'          => $r->remaining(),
            'per_customer_limit' => $r->per_customer_limit,
            'can_redeem'         => $balance === null ? null : ($balance >= $r->points_cost && $r->remaining() !== 0),
        ];
    }

    public static function transactionPayload(GamePointTransaction $t): array
    {
        return [
            'id'            => $t->id,
            'type'          => $t->type,
            'type_label'    => GamePointTransaction::TYPE_LABELS[$t->type] ?? $t->type,
            'source'        => $t->source,
            'amount'        => $t->amount,
            'balance_after' => $t->balance_after,
            'note'          => $t->note,
            // reference_id: ván với bot = id ván; ván phòng = "id_ván:ghế" (mỗi người 1 dòng) ⇒ luôn trả id ván
            'match_id'      => $t->source === GamePointTransaction::SOURCE_TIEN_LEN && $t->reference_id ? explode(':', $t->reference_id)[0] : null,
            'coupon'        => ScratchCardService::couponPayload($t->coupon),
            'created_at'    => $t->created_at?->toDateTimeString(),
        ];
    }

    private function lockWallet(string $customerId): GamePointWallet
    {
        GamePointWallet::firstOrCreate(['customer_id' => $customerId], ['balance' => 0, 'total_earned' => 0, 'total_redeemed' => 0]);

        return GamePointWallet::whereKey($customerId)->lockForUpdate()->firstOrFail();
    }

    private function createCoupon(Customer $customer, GamePointReward $reward): Coupon
    {
        do {
            $code = 'GP' . strtoupper(Str::random(6));
        } while (Coupon::withoutGlobalScopes()->where('code', $code)->exists());

        $coupon = Coupon::create([
            'partner_id'      => null,
            'funded_by'       => Coupon::FUNDED_PLATFORM,
            'source'          => 'game_points',
            'code'            => $code,
            'name'            => $reward->name,
            'description'     => $reward->description ?: 'Voucher đổi từ điểm game',
            'type'            => $reward->coupon_type,
            'value'           => $reward->coupon_value,
            'apply_type'      => 'all_rooms',
            'min_order_value' => $reward->min_order_value,
            'max_discount'    => $reward->max_discount,
            'usage_limit'     => 1,
            'used_count'      => 0,
            'start_at'        => now(),
            'end_at'          => now()->addDays($reward->validity_days)->endOfDay(),
            'is_active'       => true,
            'is_exclusive'    => true,
            'customer_id'     => $customer->id,
            'created_by'      => User::role(config('filament-shield.super_admin.name'))->value('id'),
        ]);

        \App\Support\VoucherScope::restrict($coupon, \App\Support\VoucherScope::MINIGAME);

        return $coupon;
    }
}
