<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Partner;
use App\Models\PartnerEscrowDeduction as Deduction;
use App\Models\PartnerEscrowEntry as Entry;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * KÝ QUỸ ĐỐI TÁC HOMESTAY — nguồn xử lý DUY NHẤT của số dư ký quỹ.
 *
 * Số dư chỉ đổi qua bút toán (post()): partners.escrow_balance là bản sao của tổng sổ, được cập nhật cùng transaction
 * với bút toán và khoá dòng đối tác, nên hai thao tác đồng thời không ghi đè nhau. Không nơi nào khác được ghi cột này.
 *
 * Ký quỹ chỉ áp dụng cho đối tác đã được Super Admin đặt mức tối thiểu (escrow_min_amount). Đối tác chưa đặt mức
 * không bị cảnh báo hay khoá bán. Trạng thái theo số dư so với mức tối thiểu (state()):
 *   not_required → chưa áp dụng · ok → đủ · grace → thiếu nhưng còn trong hạn nạp ban đầu ·
 *   low → thiếu, đang trong hạn nạp bù · suspended → tạm ngưng bán online (đơn đã đặt vẫn phục vụ).
 * Việc khoá do server quyết: salesBlockResponse() chặn khách đặt phòng mới (423 PARTNER_ESCROW_LOW), danh sách/tìm kiếm
 * công khai ẩn phòng của đối tác đang bị ngưng (Product::scopeActiveBranch() đọc suspendedPartnerIds()).
 */
class EscrowService
{
    public const STATE_NOT_REQUIRED = 'not_required';
    public const STATE_OK = 'ok';
    public const STATE_GRACE = 'grace';
    public const STATE_LOW = 'low';
    public const STATE_SUSPENDED = 'suspended';
    public const STATE_TERMINATED = 'terminated';

    public const STATES = [
        self::STATE_NOT_REQUIRED => 'Chưa áp dụng ký quỹ',
        self::STATE_OK           => 'Đủ ký quỹ',
        self::STATE_GRACE        => 'Chưa đủ — còn trong hạn nạp ban đầu',
        self::STATE_LOW          => 'Số dư thấp — cần nạp bù',
        self::STATE_SUSPENDED    => 'Tạm ngưng bán online',
        self::STATE_TERMINATED   => 'Đã chấm dứt hợp đồng — chờ hoàn ký quỹ',
    ];

    public function __construct(private AdminNotificationService $notifications) {}

    // ───────────────────────── Đọc ─────────────────────────

    /** Tổng các đề xuất trừ đang chờ (chưa sinh bút toán) — số dư chưa đổi nhưng coi như đã bị giữ. */
    public function heldAmount(Partner $partner): int
    {
        return (int) Deduction::query()->where('partner_id', $partner->id)
            ->whereIn('status', [Deduction::STATUS_PENDING, Deduction::STATUS_DISPUTED])
            ->whereNull('applied_at')->sum('amount');
    }

    /** Mức ký quỹ gợi ý theo số phòng đang bán — chỉ để Super Admin tham khảo khi đặt mức. */
    public function suggestedMinAmount(Partner $partner): int
    {
        $rooms = $partner->products()->where('is_activated', true)->count();

        return max((int) config('escrow.suggested_minimum'), $rooms * (int) config('escrow.suggested_per_room'));
    }

    public function state(Partner $partner): string
    {
        $min = (int) $partner->escrow_min_amount;
        $balance = (int) $partner->escrow_balance;

        if ($partner->escrow_terminated_at) {
            return self::STATE_TERMINATED;
        }
        if ($min <= 0) {
            return self::STATE_NOT_REQUIRED;
        }
        if ($balance >= $min) {
            return self::STATE_OK;
        }
        if ($partner->escrow_enforced_from && $partner->escrow_enforced_from->isFuture()) {
            return self::STATE_GRACE;
        }
        if ($balance <= 0 || $balance * 100 < $min * (int) config('escrow.suspend_below_percent')
            || ($partner->escrow_topup_due_at && $partner->escrow_topup_due_at->isPast())) {
            return self::STATE_SUSPENDED;
        }

        return self::STATE_LOW;
    }

    public function summary(Partner $partner): array
    {
        $state = $this->state($partner);
        $balance = (int) $partner->escrow_balance;
        $min = $partner->escrow_min_amount !== null ? (int) $partner->escrow_min_amount : null;
        $held = $this->heldAmount($partner);

        return [
            'partner_id'           => $partner->id,
            'status'               => $state,
            'status_label'         => self::STATES[$state],
            'min_amount'           => $min,
            'suggested_min_amount' => $this->suggestedMinAmount($partner),
            'balance'              => $balance,
            'held_amount'          => $held,
            'available_amount'     => $balance - $held,
            // Cần nạp thêm để đủ mức tối thiểu.
            'shortfall'            => $min ? max(0, $min - $balance) : 0,
            // Số dư âm (trừ quá số dư) là công nợ của đối tác.
            'debt'                 => max(0, -$balance),
            'enforced_from'        => $partner->escrow_enforced_from?->toIso8601String(),
            'topup_due_at'         => $state === self::STATE_LOW ? $partner->escrow_topup_due_at?->toIso8601String() : null,
            'suspended_at'         => $partner->escrow_suspended_at?->toIso8601String(),
            'sales_suspended'      => in_array($state, [self::STATE_SUSPENDED, self::STATE_TERMINATED], true),
            // Miễn phí tháng đầu (đối tác mới ngoài tỉnh phải ký quỹ ngay): đến khi nào không hoa hồng, không bắt nạp ký quỹ.
            'fee_free_until'       => $partner->fee_free_until?->toIso8601String(),
            'fee_free_active'      => $partner->isInFeeFreePeriod(),
            // Chấm dứt hợp đồng: mốc chấm dứt, mốc sớm nhất được hoàn và các điều kiện còn chặn việc hoàn (rỗng = hoàn được).
            'terminated_at'        => $partner->escrow_terminated_at?->toIso8601String(),
            'release_available_at' => $partner->escrow_terminated_at?->copy()->addDays((int) config('escrow.release_hold_days', 30))->toIso8601String(),
            'release_blockers'     => $partner->escrow_terminated_at ? $this->releaseBlockers($partner) : [],
        ];
    }

    /** @return string[] id các đối tác đang bị tạm ngưng bán do ký quỹ. */
    public static function suspendedPartnerIds(): array
    {
        return Partner::query()->whereNotNull('escrow_suspended_at')->pluck('id')->all();
    }

    public static function isSalesSuspended(?string $partnerId): bool
    {
        return $partnerId !== null && Partner::query()->whereKey($partnerId)->whereNotNull('escrow_suspended_at')->exists();
    }

    /** Phản hồi chặn khách đặt phòng mới của đối tác đang bị tạm ngưng bán (null = được đặt). Đơn đã đặt không bị ảnh hưởng. */
    public static function salesBlockResponse(?string $partnerId): ?\Illuminate\Http\JsonResponse
    {
        return self::isSalesSuspended($partnerId)
            ? response()->json(['message' => 'Cơ sở này đang tạm ngưng nhận đặt phòng online. Vui lòng chọn phòng khác.', 'code' => 'PARTNER_ESCROW_LOW'], 423)
            : null;
    }

    // ───────────────────────── Mức ký quỹ ─────────────────────────

    /**
     * Super Admin đặt/đổi mức tối thiểu. Lần đầu áp dụng cho đối tác: nếu không truyền $enforceFrom thì đối tác được
     * config('escrow.initial_grace_days') ngày để nạp đủ; truyền thời điểm hiện tại/quá khứ = phải đủ ngay mới được bán.
     * $minAmount = null → bỏ áp dụng ký quỹ (số dư giữ nguyên trong sổ).
     */
    public function setMinAmount(Partner $partner, ?int $minAmount, ?\DateTimeInterface $enforceFrom, User $by): void
    {
        $firstTime = ! $partner->escrow_min_amount && $minAmount;
        $old = $partner->escrow_min_amount;

        $partner->forceFill([
            'escrow_min_amount'    => $minAmount ?: null,
            'escrow_enforced_from' => ! $minAmount ? null : ($enforceFrom ?? ($firstTime ? now()->addDays((int) config('escrow.initial_grace_days')) : $partner->escrow_enforced_from)),
        ])->saveQuietly();

        \App\Models\PartnerStatusLog::create([
            'partner_id' => $partner->id, 'from_status' => $partner->verification_status, 'to_status' => $partner->verification_status, 'changed_by' => $by->id,
            'note' => 'Mức ký quỹ tối thiểu: ' . ($old ? $this->money((int) $old) : 'chưa áp dụng') . ' → ' . ($minAmount ? $this->money($minAmount) : 'không áp dụng') . '.',
        ]);

        $this->refreshState($partner);
    }

    /**
     * Đối tác thêm/mở bán phòng làm mức gợi ý (số phòng × mức mỗi phòng) tăng lên: báo cần nạp thêm, KHÔNG khoá bán ngay.
     * Mức đang là mức mặc định theo số phòng (đúng bằng mức gợi ý của số phòng trước đó) thì tự nâng lên mức mới và cho thời hạn nạp bù
     * (như hạn nạp bù khi số dư thấp); mức do Super Admin đặt tay thì không tự đổi — chỉ báo Super Admin xem lại.
     */
    public function syncMinWithRooms(string $partnerId): void
    {
        $partner = Partner::withTrashed()->find($partnerId);
        if (! $partner || $partner->isMinihouse() || ! $partner->usesDirectPayment() || ! $partner->escrow_min_amount) {
            return;
        }

        $min = (int) $partner->escrow_min_amount;
        // Đếm cả phòng CHƯA gắn chi nhánh: phòng vừa tạo được gắn chi nhánh SAU bước lưu, nếu loại trừ thì sẽ lỡ đúng lúc thêm phòng.
        $rooms = $partner->products()->withoutGlobalScope('has_branch')->where('is_activated', true)->count();
        $suggested = max((int) config('escrow.suggested_minimum'), $rooms * (int) config('escrow.suggested_per_room'));
        if ($suggested <= $min) {
            return;
        }

        $previousDefault = max((int) config('escrow.suggested_minimum'), max(0, $rooms - 1) * (int) config('escrow.suggested_per_room'));

        if ($min !== $previousDefault) {
            $this->notifySuperAdmins('escrow_min_review', 'Nên xem lại mức ký quỹ của đối tác', ($partner->legal_name ?: $partner->name)
                . ': số phòng tăng, mức gợi ý là ' . $this->money($suggested) . ' nhưng mức đang áp dụng là ' . $this->money($min) . '.', $partner);

            return;
        }

        $partner->forceFill([
            'escrow_min_amount'    => $suggested,
            'escrow_enforced_from' => now()->addDays((int) config('escrow.topup_days')),
        ])->saveQuietly();

        $this->notifyPartner($partner, 'escrow_min_raised', 'Mức ký quỹ tăng do thêm phòng',
            'Số phòng đang bán tăng nên mức ký quỹ tối thiểu tăng từ ' . $this->money($min) . ' lên ' . $this->money($suggested)
            . '. Vui lòng nạp thêm trước ' . $partner->escrow_enforced_from->format('d/m/Y') . ' — phòng của bạn vẫn bán bình thường trong thời gian này.');

        $this->refreshState($partner);
    }

    // ───────────────────────── Chấm dứt hợp đồng ─────────────────────────

    /**
     * Hợp đồng chấm dứt (luồng tiền mới): ngưng bán ngay — đơn đã đặt vẫn phục vụ — và bắt đầu thời gian giữ ký quỹ để nhận khiếu nại
     * (config escrow.release_hold_days). Đối tác chưa chuyển sang luồng mới thì không có ký quỹ nên không áp dụng.
     */
    public function onContractTerminated(Partner $partner, User $by): void
    {
        if (! $partner->usesDirectPayment() || $partner->escrow_terminated_at) {
            return;
        }

        $partner->forceFill(['escrow_terminated_at' => now()])->saveQuietly();
        $this->refreshState($partner);

        $days = (int) config('escrow.release_hold_days', 30);
        $this->notifyPartner($partner->fresh(), 'escrow_terminated', 'Hợp đồng đã chấm dứt — ngừng nhận đơn mới',
            "Phòng của bạn ngừng nhận đơn mới; đơn đã đặt vẫn được phục vụ. Ký quỹ được giữ {$days} ngày để tiếp nhận khiếu nại và quyết toán công nợ cuối, sau đó hoàn phần còn lại về tài khoản ngân hàng đã đăng ký.");
        $this->notifySuperAdmins('escrow_terminated', 'Đối tác chấm dứt hợp đồng', ($partner->legal_name ?: $partner->name) . ": đã ngưng bán; ký quỹ {$this->money((int) $partner->escrow_balance)} được giữ {$days} ngày.", $partner);
    }

    /** @return string[] điều kiện còn chặn việc hoàn ký quỹ (rỗng = hoàn được). */
    public function releaseBlockers(Partner $partner): array
    {
        if (! $partner->escrow_terminated_at) {
            return ['hợp đồng chưa chấm dứt'];
        }

        $blockers = [];
        $until = $partner->escrow_terminated_at->copy()->addDays((int) config('escrow.release_hold_days', 30));
        if ($until->isFuture()) {
            $blockers[] = 'còn trong thời gian giữ nhận khiếu nại (đến ' . $until->format('d/m/Y') . ')';
        }

        $serving = \Modules\Payment\Entities\Order::withoutGlobalScopes()->where('partner_id', $partner->id)->whereIn('status', ['paid', 'deposit'])
            ->where(fn ($q) => $q->whereNull('order_status')->orWhereNotIn('order_status', ['checked_out']))->count();
        if ($serving > 0) {
            $blockers[] = "còn {$serving} đơn đang phục vụ";
        }

        $openSettlements = \App\Models\PartnerSettlement::query()->where('partner_id', $partner->id)
            ->whereNotIn('status', \App\Models\PartnerSettlement::FINAL_STATUSES)->count();
        $unsettled = \Modules\Payment\Entities\Order::withoutGlobalScopes()->where('partner_id', $partner->id)->whereNotNull('commission_finalized_at')
            ->whereNull('settlement_id')->count();
        if ($openSettlements > 0 || $unsettled > 0) {
            $blockers[] = 'chưa quyết toán công nợ đối soát cuối (sinh bảng đối soát kỳ cuối và hoàn tất)';
        }

        // Đề xuất trừ chưa chốt không chặn hoàn: phần đó đã được tạm giữ và trừ khỏi số dư khả dụng ở withdraw().
        return $blockers;
    }

    /** Định kỳ: báo Super Admin các đối tác đã đủ điều kiện hoàn ký quỹ (mỗi đối tác một lần). @return int số đối tác được báo */
    public function notifyReleasable(): int
    {
        $count = 0;

        Partner::withTrashed()->whereNotNull('escrow_terminated_at')->where('escrow_balance', '>', 0)->get()->each(function (Partner $partner) use (&$count) {
            if ($this->releaseBlockers($partner) === [] && \Illuminate\Support\Facades\Cache::add("escrow_release_notified:{$partner->id}", 1, now()->addDays(60))) {
                $this->notifySuperAdmins('escrow_release_ready', 'Đủ điều kiện hoàn ký quỹ', ($partner->legal_name ?: $partner->name) . ': có thể hoàn ' . $this->money((int) $partner->escrow_balance) . ' về tài khoản ngân hàng đã đăng ký.', $partner);
                $count++;
            }
        });

        return $count;
    }

    // ───────────────────────── Bút toán ─────────────────────────

    /** Ghi nhận khoản nạp (webhook PayOS hoặc Super Admin ghi tay khoản chuyển khoản ngoài). */
    public function deposit(Partner $partner, int $amount, array $attributes = [], ?User $by = null): Entry
    {
        $this->assertPositive($amount);

        return $this->post($partner, Entry::TYPE_DEPOSIT, $amount, $attributes, $by);
    }

    /** Hoàn ký quỹ cho đối tác khi chấm dứt hợp đồng (sau quyết toán) — không vượt số dư còn lại sau khi trừ phần tạm giữ. */
    public function withdraw(Partner $partner, int $amount, string $reason, ?string $reference, User $by): Entry
    {
        $this->assertPositive($amount);

        // Chỉ hoàn khi chấm dứt hợp đồng và đã hết điều kiện chặn: ngưng bán, hết đơn đang phục vụ, hết thời gian giữ nhận khiếu nại, đã quyết toán công nợ cuối.
        if ($blockers = $this->releaseBlockers($partner->fresh())) {
            throw new \DomainException('Chưa hoàn được ký quỹ: ' . implode('; ', $blockers) . '.');
        }

        return DB::transaction(function () use ($partner, $amount, $reason, $reference, $by) {
            $locked = $this->lock($partner);
            if ($amount > (int) $locked->escrow_balance - $this->heldAmount($locked)) {
                throw new \DomainException('Số tiền hoàn vượt số dư ký quỹ khả dụng (đã trừ phần đang tạm giữ).');
            }

            return $this->post($partner, Entry::TYPE_WITHDRAW, -$amount, ['reason' => $reason, 'reference' => $reference], $by);
        });
    }

    /** Đảo một bút toán ghi sai: sinh bút toán ngược dấu, bút toán gốc giữ nguyên. Mỗi bút toán chỉ đảo một lần. */
    public function reverse(Entry $entry, string $reason, User $by): Entry
    {
        if ($entry->type === Entry::TYPE_REVERSAL) {
            throw new \DomainException('Không đảo một bút toán đảo — hãy ghi lại bút toán đúng.');
        }
        if ($entry->reversedBy()->exists()) {
            throw new \DomainException('Bút toán này đã được đảo.');
        }

        return $this->post($entry->partner, Entry::TYPE_REVERSAL, -$entry->amount, [
            'reason' => $reason, 'order_code' => $entry->order_code, 'reference' => $entry->reference,
            'deduction_id' => $entry->deduction_id, 'reverses_entry_id' => $entry->id,
        ], $by);
    }

    /** Trừ hoa hồng kỳ đối soát quá hạn — hệ thống tự trừ, không qua bước đề xuất (số liệu đã đối soát). */
    public function deductOverdueCommission(Partner $partner, int $amount, string $reference, ?string $reason = null): Entry
    {
        $this->assertPositive($amount);

        return $this->post($partner, Entry::TYPE_DEDUCT_COMMISSION, -$amount, ['reason' => $reason ?: 'Hoa hồng kỳ đối soát quá hạn chưa nộp.', 'reference' => $reference]);
    }

    // ───────────────────────── Đề xuất trừ ─────────────────────────

    /** @param array{type: string, amount: int, reason: string, order_code?: ?string, reference?: ?string, is_urgent?: bool} $data */
    public function proposeDeduction(Partner $partner, array $data, User $by): Deduction
    {
        $this->assertPositive((int) $data['amount']);

        $deduction = DB::transaction(function () use ($partner, $data, $by) {
            $deduction = Deduction::create([
                'partner_id' => $partner->id,
                'type'       => $data['type'],
                'case_code'  => $data['case_code'] ?? null,
                'amount'     => (int) $data['amount'],
                'reason'     => $data['reason'],
                'order_code' => $data['order_code'] ?? null,
                'reference'  => $data['reference'] ?? null,
                'is_urgent'  => (bool) ($data['is_urgent'] ?? false),
                'status'     => Deduction::STATUS_PENDING,
                'respond_by' => now()->addDays((int) config('escrow.deduction_response_days')),
                'created_by' => $by->id,
            ]);

            // Khẩn: trừ ngay, đối tác vẫn khiếu nại được trong hạn phản hồi.
            if ($deduction->is_urgent) {
                $this->apply($deduction, (int) $deduction->amount, $by);
            }

            return $deduction;
        });

        $this->notifyPartner($partner, $deduction->is_urgent ? 'escrow_deducted' : 'escrow_deduction_proposed',
            $deduction->is_urgent ? 'Ký quỹ đã bị trừ (khẩn)' : 'Có đề xuất trừ ký quỹ',
            (Entry::TYPES[$deduction->type] ?? 'Trừ ký quỹ') . ': ' . $this->money((int) $deduction->amount) . '. ' . $deduction->reason
                . ' Bạn có thể ' . ($deduction->is_urgent ? '' : 'đồng ý hoặc ') . 'khiếu nại trước ' . $deduction->respond_by->format('d/m/Y H:i') . '.',
            ['deduction_id' => $deduction->id]);

        return $deduction->fresh();
    }

    public function acceptDeduction(Deduction $deduction, ?User $by): Deduction
    {
        if ($deduction->status !== Deduction::STATUS_PENDING || $deduction->applied_at) {
            throw new \DomainException('Đề xuất này không còn chờ phản hồi.');
        }

        $this->apply($deduction, (int) $deduction->amount, $by);
        $this->notifyPartner($deduction->partner, 'escrow_deducted', 'Ký quỹ đã bị trừ', (Entry::TYPES[$deduction->type] ?? 'Trừ ký quỹ') . ': ' . $this->money((int) $deduction->amount) . '. Số dư mới: ' . $this->money((int) $deduction->partner->fresh()->escrow_balance) . '.', ['deduction_id' => $deduction->id]);

        return $deduction->fresh();
    }

    public function disputeDeduction(Deduction $deduction, string $reason, User $by): Deduction
    {
        if (! $deduction->canRespond()) {
            throw new \DomainException('Đề xuất này không còn khiếu nại được (đã chốt, đã khiếu nại hoặc hết hạn phản hồi).');
        }

        $deduction->update(['status' => Deduction::STATUS_DISPUTED, 'dispute_reason' => $reason, 'disputed_at' => now(), 'disputed_by' => $by->id]);

        $partner = $deduction->partner;
        $this->notifySuperAdmins('escrow_deduction_disputed', 'Đối tác khiếu nại đề xuất trừ ký quỹ',
            ($partner->legal_name ?: $partner->name) . ' khiếu nại khoản ' . $this->money((int) $deduction->amount) . ': ' . $reason, $partner, ['deduction_id' => $deduction->id]);

        return $deduction->fresh();
    }

    /**
     * Super Admin chốt — MỘT CHIỀU: 'keep' trừ đủ, 'reduce' trừ $finalAmount (nhỏ hơn số đề xuất), 'cancel' không trừ.
     * Áp dụng cho đề xuất đang bị khiếu nại; đề xuất còn chờ (chưa khiếu nại) chỉ được huỷ.
     */
    public function resolveDeduction(Deduction $deduction, string $resolution, ?int $finalAmount, ?string $note, User $by): Deduction
    {
        $pending = $deduction->status === Deduction::STATUS_PENDING;
        if (! $pending && $deduction->status !== Deduction::STATUS_DISPUTED) {
            throw new \DomainException('Đề xuất này đã được chốt.');
        }
        if ($pending && $resolution !== Deduction::RESOLUTION_CANCEL) {
            throw new \DomainException('Đề xuất đang chờ đối tác phản hồi — chỉ có thể huỷ.');
        }

        $final = match ($resolution) {
            Deduction::RESOLUTION_KEEP   => (int) $deduction->amount,
            Deduction::RESOLUTION_CANCEL => 0,
            Deduction::RESOLUTION_REDUCE => (int) $finalAmount,
        };
        if ($resolution === Deduction::RESOLUTION_REDUCE && ($final <= 0 || $final >= (int) $deduction->amount)) {
            throw new \DomainException('Số tiền sau khi giảm phải lớn hơn 0 và nhỏ hơn số đề xuất.');
        }

        DB::transaction(function () use ($deduction, $resolution, $final, $note, $by) {
            $alreadyApplied = $deduction->applied_at !== null;

            if ($alreadyApplied && $final < (int) $deduction->amount) {
                // Đề xuất khẩn đã trừ đủ từ trước: trả lại phần chênh bằng bút toán đảo.
                $original = $deduction->entries()->where('type', $deduction->type)->oldest('id')->first();
                $this->post($deduction->partner, Entry::TYPE_REVERSAL, (int) $deduction->amount - $final, [
                    'reason'            => 'Chốt khiếu nại đề xuất trừ #' . $deduction->id . ($final > 0 ? ' (giảm còn ' . $this->money($final) . ')' : ' (huỷ)') . ($note ? ': ' . $note : ''),
                    'order_code'        => $deduction->order_code, 'reference' => $deduction->reference, 'deduction_id' => $deduction->id,
                    'reverses_entry_id' => $final === 0 ? $original?->id : null,
                ], $by);
            } elseif (! $alreadyApplied && $final > 0) {
                $this->apply($deduction, $final, $by);
            }

            $deduction->update([
                'status'          => $final > 0 ? Deduction::STATUS_APPLIED : Deduction::STATUS_CANCELLED,
                'final_amount'    => $final,
                'resolution'      => $resolution,
                'resolution_note' => $note,
                'resolved_at'     => now(),
                'resolved_by'     => $by->id,
            ]);
        });

        $partner = $deduction->partner->fresh();
        $this->notifyPartner($partner, $final > 0 ? 'escrow_deducted' : 'escrow_deduction_cancelled',
            $final > 0 ? 'Đã chốt khoản trừ ký quỹ' : 'Đề xuất trừ ký quỹ đã được huỷ',
            ($final > 0 ? 'Số tiền trừ: ' . $this->money($final) . '. ' : '') . ($note ? $note . ' ' : '') . 'Số dư ký quỹ: ' . $this->money((int) $partner->escrow_balance) . '.',
            ['deduction_id' => $deduction->id]);

        return $deduction->fresh();
    }

    /** Đề xuất hết hạn phản hồi mà đối tác không khiếu nại = đồng ý → tự trừ. Chạy định kỳ (escrow:process). */
    public function applyExpiredDeductions(): int
    {
        $count = 0;
        Deduction::query()->where('status', Deduction::STATUS_PENDING)->whereNull('applied_at')->where('respond_by', '<=', now())
            ->get()->each(function (Deduction $deduction) use (&$count) {
                try {
                    $this->acceptDeduction($deduction, null);
                    $count++;
                } catch (\Throwable $e) {
                    report($e);
                }
            });

        return $count;
    }

    // ───────────────────────── Trạng thái & khoá bán ─────────────────────────

    /**
     * Tính lại trạng thái theo số dư và lưu mốc: hạn nạp bù (đặt một lần khi số dư bắt đầu thấp, xoá khi đủ lại) và
     * thời điểm tạm ngưng bán. Gọi sau mỗi bút toán, khi đổi mức ký quỹ, và định kỳ (hạn nạp trôi qua không có sự kiện nào).
     */
    public function refreshState(Partner $partner): string
    {
        $partner->refresh();
        $before = $partner->escrow_suspended_at !== null;
        $state = $this->state($partner);

        if ($state === self::STATE_LOW && $partner->escrow_topup_due_at === null) {
            $partner->forceFill(['escrow_topup_due_at' => now()->addDays((int) config('escrow.topup_days'))])->saveQuietly();
            $this->notifyPartner($partner, 'escrow_low', 'Số dư ký quỹ thấp', 'Số dư ký quỹ ' . $this->money((int) $partner->escrow_balance) . ' thấp hơn mức tối thiểu ' . $this->money((int) $partner->escrow_min_amount)
                . '. Vui lòng nạp bù trước ' . $partner->escrow_topup_due_at->format('d/m/Y') . ' để không bị tạm ngưng bán.');
        }

        $suspended = in_array($state, [self::STATE_SUSPENDED, self::STATE_TERMINATED], true);
        $partner->forceFill([
            'escrow_topup_due_at' => in_array($state, [self::STATE_OK, self::STATE_NOT_REQUIRED, self::STATE_GRACE], true) ? null : $partner->escrow_topup_due_at,
            'escrow_suspended_at' => $suspended ? ($partner->escrow_suspended_at ?? now()) : null,
        ])->saveQuietly();

        if ($state === self::STATE_SUSPENDED && ! $before) {
            $this->notifyPartner($partner, 'escrow_suspended', 'Tạm ngưng bán online do ký quỹ', 'Số dư ký quỹ ' . $this->money((int) $partner->escrow_balance) . ' không đủ mức tối thiểu ' . $this->money((int) $partner->escrow_min_amount)
                . '. Phòng của bạn tạm ẩn và không nhận đơn mới; đơn đã đặt vẫn phục vụ. Nạp đủ ký quỹ để mở bán lại.');
            $this->notifySuperAdmins('escrow_suspended', 'Đối tác bị tạm ngưng bán do ký quỹ', ($partner->legal_name ?: $partner->name) . ': số dư ' . $this->money((int) $partner->escrow_balance) . ' / mức tối thiểu ' . $this->money((int) $partner->escrow_min_amount) . '.', $partner);
        } elseif (! $suspended && $before && $state !== self::STATE_TERMINATED) {
            $this->notifyPartner($partner, 'escrow_resumed', 'Đã mở bán lại', 'Ký quỹ đã đủ điều kiện — phòng của bạn nhận đơn mới trở lại.');
        }

        return $state;
    }

    /** Định kỳ: cập nhật trạng thái mọi đối tác đang áp dụng ký quỹ (hết hạn nạp ban đầu / hạn nạp bù). */
    public function refreshAll(): int
    {
        $partners = Partner::query()->whereNotNull('escrow_min_amount')->orWhereNotNull('escrow_suspended_at')->get();
        $partners->each(fn (Partner $partner) => $this->refreshState($partner));

        return $partners->count();
    }

    // ───────────────────────── Nội bộ ─────────────────────────

    private function apply(Deduction $deduction, int $amount, ?User $by): void
    {
        DB::transaction(function () use ($deduction, $amount, $by) {
            $this->post($deduction->partner, $deduction->type, -$amount, [
                'reason' => $deduction->reason, 'order_code' => $deduction->order_code, 'reference' => $deduction->reference,
                'deduction_id' => $deduction->id, 'is_urgent' => $deduction->is_urgent,
            ], $by);

            $deduction->update(['status' => Deduction::STATUS_APPLIED, 'final_amount' => $amount, 'applied_at' => now()]);
        });
    }

    private function post(Partner $partner, string $type, int $amount, array $attributes = [], ?User $by = null): Entry
    {
        $entry = DB::transaction(function () use ($partner, $type, $amount, $attributes, $by) {
            $locked = $this->lock($partner);
            $balance = (int) $locked->escrow_balance + $amount;

            $entry = Entry::create([...$attributes, 'partner_id' => $locked->id, 'type' => $type, 'amount' => $amount, 'balance_after' => $balance, 'created_by' => $by?->id]);
            $locked->forceFill(['escrow_balance' => $balance])->saveQuietly();

            return $entry;
        });

        $this->refreshState($partner);

        return $entry;
    }

    private function lock(Partner $partner): Partner
    {
        return Partner::withTrashed()->whereKey($partner->getKey())->lockForUpdate()->firstOrFail();
    }

    private function assertPositive(int $amount): void
    {
        if ($amount <= 0) {
            throw new \DomainException('Số tiền phải lớn hơn 0.');
        }
    }

    private function money(int $amount): string
    {
        return number_format($amount, 0, ',', '.') . 'đ';
    }

    private function notifyPartner(Partner $partner, string $type, string $title, string $body, array $data = []): void
    {
        app(PartnerNotifier::class)->send($partner, $type, $title, $body, $data, $type === 'escrow_resumed' || $type === 'escrow_deposited' ? 'success' : 'warning');
    }

    public function notifySuperAdmins(string $type, string $title, string $body, Partner $partner, array $data = []): void
    {
        try {
            $this->notifications->notify(User::role(config('filament-shield.super_admin.name'))->get(), $title, $body, ['type' => $type, 'partner_id' => $partner->id, 'partner_type' => $partner->partner_type, ...$data], 'heroicon-o-banknotes', 'warning');
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function notifyDeposited(Partner $partner, int $amount): void
    {
        $partner->refresh();
        $this->notifyPartner($partner, 'escrow_deposited', 'Đã nhận tiền nạp ký quỹ', 'Đã cộng ' . $this->money($amount) . ' vào ký quỹ. Số dư mới: ' . $this->money((int) $partner->escrow_balance) . '.');
    }
}
