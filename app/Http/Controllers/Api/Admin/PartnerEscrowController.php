<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\PartnerEscrowDeduction as Deduction;
use App\Models\PartnerEscrowDeposit as Deposit;
use App\Models\PartnerEscrowEntry as Entry;
use App\Services\EscrowPayosService;
use App\Services\EscrowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * KÝ QUỸ đối tác Homestay (xem App\Services\EscrowService) — nhóm /api/admin/partners/{partner}/escrow/…
 *
 * Quyền:
 *   - Xem (số dư, sổ, đề xuất trừ, yêu cầu nạp): Super Admin và mọi tài khoản của chính đối tác.
 *   - Nạp qua QR, đồng ý/khiếu nại đề xuất trừ: chủ đối tác (hoặc tài khoản có quyền sửa hồ sơ đối tác); Super Admin làm thay được.
 *   - Đặt mức ký quỹ, ghi nạp tay, đảo bút toán, hoàn ký quỹ, tạo/chốt đề xuất trừ: CHỈ Super Admin.
 * Sai nghiệp vụ (đề xuất đã chốt, hoàn quá số dư...) trả 422 kèm message.
 */
class PartnerEscrowController extends Controller
{
    private const EVIDENCE_RULES = ['file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'];

    public function __construct(private EscrowService $escrow) {}

    // GET …/escrow — mức tối thiểu, số dư, đang tạm giữ, trạng thái (not_required | ok | grace | low | suspended).
    public function show(Request $request, Partner $partner): JsonResponse
    {
        $this->authorizeView($request, $partner);

        return response()->json(['data' => $this->escrow->summary($partner)]);
    }

    // PUT …/escrow/settings — Super Admin đặt mức tối thiểu. body: min_amount (null/0 = bỏ áp dụng), enforce_from? (ngày bắt đầu khoá bán nếu thiếu).
    public function updateSettings(Request $request, Partner $partner): JsonResponse
    {
        $this->authorizeSuperAdmin($request, $partner);
        $data = $request->validate([
            'min_amount'   => ['present', 'nullable', 'integer', 'min:0', 'max:100000000000'],
            'enforce_from' => ['nullable', 'date'],
        ]);

        $this->escrow->setMinAmount($partner, $data['min_amount'] ? (int) $data['min_amount'] : null, isset($data['enforce_from']) ? \Illuminate\Support\Carbon::parse($data['enforce_from']) : null, $request->user());

        return response()->json(['message' => 'Đã cập nhật mức ký quỹ.', 'data' => $this->escrow->summary($partner->fresh())]);
    }

    // GET …/escrow/entries?type=&month=YYYY-MM&per_page= — sổ bút toán, mới nhất trước.
    public function entries(Request $request, Partner $partner): JsonResponse
    {
        $this->authorizeView($request, $partner);
        $filters = $request->validate([
            'type'  => ['nullable', Rule::in(array_keys(Entry::TYPES))],
            'month' => ['nullable', 'date_format:Y-m'],
        ]);

        $entries = Entry::query()->where('partner_id', $partner->id)->with(['creator:id,fullname', 'media'])
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($filters['month'] ?? null, fn ($q, $month) => $q->whereYear('created_at', (int) substr($month, 0, 4))->whereMonth('created_at', (int) substr($month, 5, 2)))
            ->latest('id')->paginate(min($request->integer('per_page', 20), 100));

        return response()->json($entries->through(fn (Entry $entry) => $entry->toApi()));
    }

    /**
     * POST …/escrow/entries (multipart) — Super Admin ghi bút toán tay:
     *   type=deposit  : amount, reason, evidence[] (bắt buộc — chứng từ khoản chuyển khoản ngoài), reference?
     *   type=reversal : entry_id (bút toán ghi sai), reason
     *   type=withdraw : amount, reason, reference?, evidence[]? — hoàn ký quỹ khi chấm dứt, sau quyết toán
     */
    public function storeEntry(Request $request, Partner $partner): JsonResponse
    {
        $this->authorizeSuperAdmin($request, $partner);
        $type = $request->validate(['type' => ['required', Rule::in([Entry::TYPE_DEPOSIT, Entry::TYPE_REVERSAL, Entry::TYPE_WITHDRAW])]])['type'];
        $data = $request->validate([
            'amount'     => [Rule::requiredIf($type !== Entry::TYPE_REVERSAL), 'nullable', 'integer', 'min:1', 'max:100000000000'],
            'entry_id'   => [Rule::requiredIf($type === Entry::TYPE_REVERSAL), 'nullable', 'integer'],
            'reason'     => ['required', 'string', 'max:2000'],
            'reference'  => ['nullable', 'string', 'max:255'],
            'evidence'   => [Rule::requiredIf($type === Entry::TYPE_DEPOSIT), 'array', 'max:5'],
            'evidence.*' => self::EVIDENCE_RULES,
        ], ['evidence.required' => 'Vui lòng đính kèm chứng từ khoản nạp.', 'evidence.*.mimes' => 'Chứng từ phải là PDF hoặc ảnh (jpg, png, webp).', 'evidence.*.max' => 'Mỗi chứng từ tối đa 10 MB.']);

        return $this->guard(function () use ($request, $partner, $type, $data) {
            $entry = match ($type) {
                Entry::TYPE_DEPOSIT  => $this->escrow->deposit($partner, (int) $data['amount'], ['reason' => $data['reason'], 'reference' => $data['reference'] ?? null], $request->user()),
                Entry::TYPE_WITHDRAW => $this->escrow->withdraw($partner, (int) $data['amount'], $data['reason'], $data['reference'] ?? null, $request->user()),
                Entry::TYPE_REVERSAL => $this->escrow->reverse(Entry::query()->where('partner_id', $partner->id)->findOrFail($data['entry_id']), $data['reason'], $request->user()),
            };
            $this->attach($request, $entry, 'evidence');
            if ($type === Entry::TYPE_DEPOSIT) {
                $this->escrow->notifyDeposited($partner, (int) $data['amount']);
            }

            return response()->json(['message' => 'Đã ghi bút toán ký quỹ.', 'data' => ['entry' => $entry->fresh(['creator', 'media'])->toApi(), 'escrow' => $this->escrow->summary($partner->fresh())]], 201);
        });
    }

    // POST …/escrow/deposit-link — body: amount. Tạo QR PayOS của 365home để nạp; webhook xác nhận thì tự cộng số dư.
    public function depositLink(Request $request, Partner $partner, EscrowPayosService $payos): JsonResponse
    {
        $this->authorizeRespond($request, $partner);
        $data = $request->validate(['amount' => ['required', 'integer', 'min:' . (int) config('escrow.deposit_min_amount'), 'max:100000000000']],
            ['amount.min' => 'Số tiền nạp tối thiểu ' . number_format((int) config('escrow.deposit_min_amount'), 0, ',', '.') . 'đ.']);

        $deposit = $payos->createDeposit($partner, (int) $data['amount'], $request->user());

        return response()->json(['message' => $deposit->payos_checkout_url ? 'Đã tạo mã thanh toán nạp ký quỹ.' : 'Chưa tạo được mã PayOS — chuyển khoản với nội dung là mã giao dịch rồi báo 365home xác nhận.', 'data' => $deposit->toApi()], 201);
    }

    // GET …/escrow/deposits — các yêu cầu nạp qua QR (để app theo dõi trạng thái sau khi quét).
    public function deposits(Request $request, Partner $partner): JsonResponse
    {
        $this->authorizeView($request, $partner);
        $deposits = Deposit::query()->where('partner_id', $partner->id)->latest('id')->paginate(min($request->integer('per_page', 20), 100));

        return response()->json($deposits->through(fn (Deposit $deposit) => $deposit->toApi()));
    }

    // GET …/escrow/deductions?status= — đề xuất trừ, mới nhất trước.
    public function deductions(Request $request, Partner $partner): JsonResponse
    {
        $this->authorizeView($request, $partner);
        $filters = $request->validate(['status' => ['nullable', Rule::in(array_keys(Deduction::STATUSES))]]);

        $deductions = Deduction::query()->where('partner_id', $partner->id)->with('media')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->latest('id')->paginate(min($request->integer('per_page', 20), 100));

        return response()->json($deductions->through(fn (Deduction $deduction) => $deduction->toApi()));
    }

    // POST …/escrow/deductions (multipart) — Super Admin tạo đề xuất trừ. is_urgent=1: trừ ngay, đối tác khiếu nại sau.
    public function storeDeduction(Request $request, Partner $partner): JsonResponse
    {
        $this->authorizeSuperAdmin($request, $partner);
        $data = $request->validate([
            'type'       => ['required', Rule::in(Deduction::TYPES)],
            // Trường hợp được trừ theo phụ lục hợp đồng (Deduction::CASES).
            'case_code'  => ['nullable', Rule::in(array_keys(Deduction::CASES))],
            'amount'     => ['required', 'integer', 'min:1', 'max:100000000000'],
            'reason'     => ['required', 'string', 'max:2000'],
            'order_code' => ['nullable', 'string', 'max:50', Rule::requiredIf($request->input('type') === Entry::TYPE_DEDUCT_REFUND || in_array($request->input('case_code'), [Deduction::CASE_HOST_CANCELLED, Deduction::CASE_CHARGEBACK], true))],
            'reference'  => ['nullable', 'string', 'max:255'],
            'is_urgent'  => ['sometimes', 'boolean'],
            // Khoản khác bắt buộc có chứng từ.
            'evidence'   => [Rule::requiredIf($request->input('type') === Entry::TYPE_DEDUCT_OTHER), 'array', 'max:5'],
            'evidence.*' => self::EVIDENCE_RULES,
        ], ['order_code.required' => 'Khoản hoàn tiền thay đối tác phải gắn mã đơn.', 'evidence.required' => 'Khoản trừ khác phải đính kèm chứng từ.']);

        return $this->guard(function () use ($request, $partner, $data) {
            // Huỷ/không giao phòng đơn đã xác nhận: phạt tối thiểu 100% tiền đơn (hoặc chi phí chuyển khách nếu lớn hơn).
            if (($data['case_code'] ?? null) === Deduction::CASE_HOST_CANCELLED) {
                $orderAmount = (int) \Modules\Payment\Entities\Order::withoutGlobalScopes()->where('partner_id', $partner->id)->where('order_code', $data['order_code'])->value('amount');
                if ($orderAmount > 0 && (int) $data['amount'] < $orderAmount) {
                    throw new \DomainException('Phạt huỷ/không giao phòng tối thiểu bằng 100% tiền đơn (' . number_format($orderAmount, 0, ',', '.') . 'đ), hoặc chi phí chuyển khách sang chỗ khác nếu lớn hơn.');
                }
            }

            $deduction = $this->escrow->proposeDeduction($partner, $data, $request->user());
            $this->attach($request, $deduction, 'evidence');

            return response()->json(['message' => $deduction->is_urgent ? 'Đã trừ ký quỹ (khẩn) và thông báo đối tác.' : 'Đã tạo đề xuất trừ và thông báo đối tác.', 'data' => ['deduction' => $deduction->fresh('media')->toApi(), 'escrow' => $this->escrow->summary($partner->fresh())]], 201);
        });
    }

    // POST …/escrow/deductions/{deduction}/accept — đối tác đồng ý → trừ ngay.
    public function acceptDeduction(Request $request, Partner $partner, int $deduction): JsonResponse
    {
        $this->authorizeRespond($request, $partner);

        return $this->guard(fn () => response()->json(['message' => 'Đã đồng ý khoản trừ.', 'data' => [
            'deduction' => $this->escrow->acceptDeduction($this->findDeduction($partner, $deduction), $request->user())->toApi(),
            'escrow'    => $this->escrow->summary($partner->fresh()),
        ]]));
    }

    // POST …/escrow/deductions/{deduction}/dispute (multipart) — đối tác khiếu nại: reason, evidence[]?
    public function disputeDeduction(Request $request, Partner $partner, int $deduction): JsonResponse
    {
        $this->authorizeRespond($request, $partner);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000'], 'evidence' => ['sometimes', 'array', 'max:5'], 'evidence.*' => self::EVIDENCE_RULES]);

        return $this->guard(function () use ($request, $partner, $deduction, $data) {
            $record = $this->escrow->disputeDeduction($this->findDeduction($partner, $deduction), $data['reason'], $request->user());
            $this->attach($request, $record, 'dispute_evidence');

            return response()->json(['message' => 'Đã gửi khiếu nại — 365home sẽ xem xét và phản hồi.', 'data' => ['deduction' => $record->fresh('media')->toApi()]]);
        });
    }

    // POST …/escrow/deductions/{deduction}/resolve — Super Admin chốt (một chiều): resolution = keep | reduce | cancel, final_amount (khi reduce), note.
    public function resolveDeduction(Request $request, Partner $partner, int $deduction): JsonResponse
    {
        $this->authorizeSuperAdmin($request, $partner);
        $data = $request->validate([
            'resolution'   => ['required', Rule::in([Deduction::RESOLUTION_KEEP, Deduction::RESOLUTION_REDUCE, Deduction::RESOLUTION_CANCEL])],
            'final_amount' => ['nullable', 'integer', 'min:1', Rule::requiredIf($request->input('resolution') === Deduction::RESOLUTION_REDUCE)],
            'note'         => ['required', 'string', 'max:2000'],
        ], ['note.required' => 'Vui lòng ghi kết luận xử lý.']);

        return $this->guard(fn () => response()->json(['message' => 'Đã chốt đề xuất trừ.', 'data' => [
            'deduction' => $this->escrow->resolveDeduction($this->findDeduction($partner, $deduction), $data['resolution'], isset($data['final_amount']) ? (int) $data['final_amount'] : null, $data['note'], $request->user())->toApi(),
            'escrow'    => $this->escrow->summary($partner->fresh()),
        ]]));
    }

    // POST …/escrow/chargebacks (multipart) — Super Admin ghi nhận khiếu nại/hoàn giao dịch (chargeback) của cổng thanh toán cho 1 đơn của đối tác: tự lập đề xuất trừ
    // (loại deduct_refund, case_code = chargeback) với số tiền mặc định = số khách đã trả. body: order_code, amount?, reference? (mã khiếu nại của cổng), reason?, is_urgent?, evidence[]?
    // PayOS không gửi tín hiệu chargeback tự động nên việc ghi nhận do Super Admin thực hiện khi nhận thông báo từ cổng/ngân hàng.
    public function storeChargeback(Request $request, Partner $partner): JsonResponse
    {
        $this->authorizeSuperAdmin($request, $partner);
        $data = $request->validate([
            'order_code' => ['required', 'string', 'max:50'],
            'amount'     => ['nullable', 'integer', 'min:1', 'max:100000000000'],
            'reference'  => ['nullable', 'string', 'max:255'],
            'reason'     => ['nullable', 'string', 'max:2000'],
            'is_urgent'  => ['sometimes', 'boolean'],
            'evidence'   => ['sometimes', 'array', 'max:5'],
            'evidence.*' => self::EVIDENCE_RULES,
        ]);

        $order = \Modules\Payment\Entities\Order::withoutGlobalScopes()->where('partner_id', $partner->id)->where('order_code', $data['order_code'])->first();
        abort_unless($order, 404, 'Không tìm thấy đơn của đối tác này.');

        return $this->guard(function () use ($request, $partner, $data, $order) {
            $deduction = $this->escrow->proposeDeduction($partner, [
                'type'       => Entry::TYPE_DEDUCT_REFUND,
                'case_code'  => Deduction::CASE_CHARGEBACK,
                'amount'     => (int) ($data['amount'] ?? app(\App\Services\OrderCommissionService::class)->paidAmount($order)),
                'reason'     => 'Chargeback đơn #' . $order->order_code . ($data['reason'] ?? null ? ': ' . $data['reason'] : '.'),
                'order_code' => $order->order_code,
                'reference'  => $data['reference'] ?? null,
                'is_urgent'  => (bool) ($data['is_urgent'] ?? false),
            ], $request->user());
            $this->attach($request, $deduction, 'evidence');

            return response()->json(['message' => 'Đã ghi nhận chargeback và lập đề xuất trừ ký quỹ.', 'data' => ['deduction' => $deduction->fresh('media')->toApi(), 'escrow' => $this->escrow->summary($partner->fresh())]], 201);
        });
    }

    private function findDeduction(Partner $partner, int $id): Deduction
    {
        return Deduction::query()->where('partner_id', $partner->id)->findOrFail($id);
    }

    private function attach(Request $request, \Spatie\MediaLibrary\HasMedia $model, string $collection): void
    {
        foreach ((array) $request->file('evidence', []) as $file) {
            $model->addMedia($file)->toMediaCollection($collection);
        }
    }

    private function guard(\Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function authorizeView(Request $request, Partner $partner): void
    {
        abort_if($partner->isSystemPartner() || $partner->isMinihouse(), 404);
        abort_unless($request->user()->isSuperAdmin() || $request->user()->partner_id === $partner->id, 403);
    }

    private function authorizeRespond(Request $request, Partner $partner): void
    {
        $this->authorizeView($request, $partner);
        $user = $request->user();
        abort_unless($user->isSuperAdmin() || $user->hasRole('partner') || $user->can('update_partner'), 403, 'Chỉ chủ đối tác được thao tác với ký quỹ.');
    }

    private function authorizeSuperAdmin(Request $request, Partner $partner): void
    {
        abort_if($partner->isSystemPartner() || $partner->isMinihouse(), 404);
        abort_unless($request->user()->isSuperAdmin(), 403, 'Chỉ Super Admin được thực hiện thao tác này.');
    }
}
