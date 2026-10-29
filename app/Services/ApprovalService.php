<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ApprovalException;
use App\Models\ApprovalRequest;
use App\Models\Partner;
use App\Models\User;
use App\Services\PriceBoards\PriceBoardV2Service;
use App\Support\ApprovalContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Modules\Product\App\Models\PriceBoard;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomTimeSlot;

/**
 * DUYỆT TRƯỚC KHI ĐĂNG của đối tác — bảng giá và phòng chỉ có hiệu lực sau khi 365home (Super Admin) duyệt.
 *
 *  - Ai bị áp dụng: tài khoản thuộc đối tác thường (không phải Super Admin, không thuộc đối tác nền tảng 365home). Super Admin và nhân viên 365home được miễn.
 *  - Đăng MỚI (bảng giá / phòng): bản ghi tạo ra ở trạng thái "pending" — bảng giá không bật được, phòng không hiện cho khách và không đặt được — cho tới khi duyệt.
 *  - SỬA bản ghi đã duyệt: bản cũ vẫn chạy nguyên; bản sửa nằm ở approval_requests.payload chờ duyệt, duyệt xong mới áp xuống.
 *      · bảng giá: cổng đặt ở PriceBoardV2Service::persist() — cửa chung của API lẫn Filament; payload = spec bảng giá.
 *      · phòng: chặn ở tầng model (Product / RoomTimeSlot::updating) nên phủ cả API lẫn Filament; payload = các cột nội dung, cột giá và giá khung giờ bị đổi.
 *    Chưa chặn (đang chạy ngay như cũ): ảnh, tiện ích, dịch vụ bổ sung, danh mục/chi nhánh của phòng, thêm/xoá khung giờ và ngày đặc biệt.
 *  - Ghi nội bộ hợp lệ (đồng bộ bảng giá đã duyệt xuống phòng, áp bản sửa đã duyệt, dữ liệu mẫu) bọc trong ApprovalContext::bypass().
 * Tắt toàn bộ bằng APPROVALS_ENABLED=false (config/approvals.php).
 */
class ApprovalService
{
    /** Cột nội dung của phòng phải duyệt khi sửa phòng đã đăng. */
    public const PRODUCT_CONTENT_FIELDS = [
        'name' => 'Tên phòng', 'slug' => 'Đường dẫn (slug)', 'short_description' => 'Mô tả ngắn', 'description' => 'Mô tả chi tiết',
        'address' => 'Địa chỉ', 'latitude' => 'Vĩ độ', 'longitude' => 'Kinh độ', 'map_url' => 'Link bản đồ', 'hotline' => 'Hotline',
        'wifi' => 'Wifi', 'home_code' => 'Mã cổng', 'bed_type' => 'Loại giường', 'room_area_sqm' => 'Diện tích (m²)', 'room_type_id' => 'Loại phòng',
        'setting_video_room' => 'Video phòng', 'thumbnail_color' => 'Màu thumbnail', 'badge' => 'Huy hiệu', 'is_trend' => 'Phòng nổi bật',
    ];

    /** Cột giá / cấu hình đặt phòng của phòng phải duyệt khi sửa trực tiếp trên phòng đã đăng. */
    public const PRODUCT_PRICE_FIELDS = [
        'price' => 'Giá', 'discount' => 'Giảm giá', 'vat' => 'VAT', 'price_unit' => 'Đơn vị giá', 'styles' => 'Kiểu đặt phòng',
        'nights' => 'Đặt qua đêm', 'default_checkin' => 'Giờ nhận phòng mặc định', 'default_checkout' => 'Giờ trả phòng mặc định',
        'full_booking_discount' => 'Giảm giá đặt trọn gói', 'full_day_price' => 'Giá cả ngày', 'bulk_discount_rules' => 'Giảm giá theo số đêm', 'room_config' => 'Cấu hình phòng',
        'deposit_1_night' => 'Cọc 1 đêm (%)', 'deposit_multi_night' => 'Cọc nhiều đêm (%)', 'deposit_min_nights' => 'Số đêm tối thiểu để cọc nhiều đêm',
    ];

    /** Cột của khung giờ phòng phải duyệt khi sửa. */
    public const SLOT_FIELDS = [
        'price' => 'Giá khung giờ', 'checkin' => 'Giờ nhận', 'checkout' => 'Giờ trả', 'over_night' => 'Qua đêm',
    ];

    // ════════════════════════════════════════════════════════════════════
    // Ai bị áp dụng
    // ════════════════════════════════════════════════════════════════════

    public function enabled(): bool
    {
        return (bool) config('approvals.enabled', true);
    }

    /** Người đang thao tác (trong request web / API) là đối tác thường và đang không ở chế độ bỏ qua duyệt. */
    public function guards(): bool
    {
        if (! $this->enabled() || ApprovalContext::bypassed()) {
            return false;
        }

        return $this->requiresApproval(auth()->user());
    }

    public function requiresApproval(mixed $user): bool
    {
        if (! $user instanceof User || $user->isSuperAdmin()) {
            return false;
        }

        return filled($user->getRawOriginal('partner_id')) && ! $user->belongsToPlatformPartner();
    }

    /** Phía nền tảng (Super Admin / nhân viên đối tác nền tảng): thấy mọi yêu cầu. */
    public function isPlatformSide(mixed $user): bool
    {
        return $user instanceof User && ($user->isSuperAdmin() || $user->belongsToPlatformPartner());
    }

    public function canReview(mixed $user): bool
    {
        return $user instanceof User && $user->isSuperAdmin();
    }

    /** Yêu cầu $user được thấy: phía nền tảng thấy hết (Super Admin chọn đối tác thì chỉ đối tác đó); đối tác chỉ thấy của mình. */
    public function visibleTo(User $user): Builder
    {
        $query = ApprovalRequest::query();

        if ($this->isPlatformSide($user) && ! $user->actingPartnerId()) {
            return $query;
        }

        return $query->where('partner_id', (string) ($user->partner_id ?? '-'));
    }

    // ════════════════════════════════════════════════════════════════════
    // Gửi duyệt
    // ════════════════════════════════════════════════════════════════════

    /**
     * Mở (hoặc thay nội dung) yêu cầu đang chờ của 1 bản ghi: mỗi (bản ghi, loại) chỉ có 1 yêu cầu chờ duyệt — gửi lại thì thay bằng bản mới nhất.
     *
     * @param array<string, mixed>|null $payload
     */
    public function open(string $kind, string $subjectType, string|int $subjectId, string $partnerId, ?string $label, ?array $payload, ?User $by = null): ApprovalRequest
    {
        $subjectId = (string) $subjectId;
        $isNew     = false;

        $request = DB::transaction(function () use ($kind, $subjectType, $subjectId, $partnerId, $label, $payload, $by, &$isNew) {
            $request = ApprovalRequest::query()
                ->where('subject_type', $subjectType)->where('subject_id', $subjectId)->where('kind', $kind)
                ->where('status', ApprovalRequest::STATUS_PENDING)
                ->lockForUpdate()->first();

            if (! $request) {
                $isNew   = true;
                $request = new ApprovalRequest([
                    'partner_id' => $partnerId, 'kind' => $kind, 'subject_type' => $subjectType, 'subject_id' => $subjectId,
                    'status' => ApprovalRequest::STATUS_PENDING, 'requested_by' => $by?->id,
                ]);
            }

            $request->fill(['subject_label' => $label, 'payload' => $payload])->save();

            return $request;
        });

        if (ApprovalContext::notice($request) && request()->hasHeader('X-Livewire')) {
            // Thao tác trên trang quản trị web (Livewire): báo ngay "đã gửi duyệt" thay cho "đã lưu".
            Notification::make()->warning()->title('Đã gửi 365home duyệt')
                ->body($request->kindLabel() . ($request->subject_label ? ' "' . $request->subject_label . '"' : '') . ' sẽ có hiệu lực sau khi được duyệt.')->send();
        }

        if ($isNew) {
            $this->notifyAdmins($request);
        }

        return $request;
    }

    /** Bản sửa bảng giá đã duyệt → chờ duyệt. */
    public function submitBoardUpdate(PriceBoard $board, array $spec, ?User $by): ApprovalRequest
    {
        return $this->open(ApprovalRequest::KIND_PRICE_BOARD_UPDATE, ApprovalRequest::SUBJECT_PRICE_BOARD, $board->id, (string) $board->partner_id, $board->name, ['spec' => $spec, 'old' => app(PriceBoardV2Service::class)->specFromBoard($board)], $by);
    }

    /** Bảng giá vừa tạo (đang pending) → chờ duyệt. $requestedActive = bảng được tạo với trạng thái bật hay tắt (bật sau khi duyệt). */
    public function submitBoardCreate(PriceBoard $board, bool $requestedActive, ?User $by): ApprovalRequest
    {
        return $this->open(ApprovalRequest::KIND_PRICE_BOARD_CREATE, ApprovalRequest::SUBJECT_PRICE_BOARD, $board->id, (string) $board->partner_id, $board->name, ['requested_is_active' => $requestedActive], $by);
    }

    /** Phòng vừa tạo (đang pending) → chờ duyệt. */
    public function submitRoomCreate(Product $product, bool $requestedActive, ?User $by): ApprovalRequest
    {
        return $this->open(ApprovalRequest::KIND_ROOM_CREATE, ApprovalRequest::SUBJECT_PRODUCT, $product->id, (string) $product->partner_id, $product->name, ['requested_is_activated' => $requestedActive], $by);
    }

    /**
     * Gộp thay đổi cột của phòng / khung giờ vào yêu cầu "sửa phòng" đang chờ (tạo mới nếu chưa có). Mỗi cột lưu giá trị cũ ĐẦU TIÊN và giá trị mới nhất;
     * cột bị sửa về đúng giá trị gốc thì bỏ khỏi yêu cầu.
     *
     * @param array<string, array{old: mixed, new: mixed}> $productChanges
     * @param array<int|string, array<string, array{old: mixed, new: mixed}>> $slotChanges  theo id khung giờ
     */
    public function mergeRoomChanges(Product $product, array $productChanges, array $slotChanges, ?User $by): ApprovalRequest
    {
        $pending = ApprovalRequest::query()
            ->where('subject_type', ApprovalRequest::SUBJECT_PRODUCT)->where('subject_id', (string) $product->id)
            ->where('kind', ApprovalRequest::KIND_ROOM_UPDATE)->where('status', ApprovalRequest::STATUS_PENDING)->first();

        $payload = $pending?->payload ?? ['product' => [], 'timeslots' => []];

        foreach ($productChanges as $field => $change) {
            $old = $payload['product'][$field]['old'] ?? $change['old'];
            $payload['product'][$field] = ['old' => $old, 'new' => $change['new']];
        }

        foreach ($slotChanges as $slotId => $fields) {
            foreach ($fields as $field => $change) {
                $old = $payload['timeslots'][$slotId][$field]['old'] ?? $change['old'];
                $payload['timeslots'][$slotId][$field] = ['old' => $old, 'new' => $change['new']];
            }
        }

        return $this->open(ApprovalRequest::KIND_ROOM_UPDATE, ApprovalRequest::SUBJECT_PRODUCT, $product->id, (string) $product->partner_id, $product->name, $payload, $by);
    }

    // ════════════════════════════════════════════════════════════════════
    // Duyệt / từ chối / rút lại / gửi lại
    // ════════════════════════════════════════════════════════════════════

    /** @throws ApprovalException */
    public function approve(ApprovalRequest $request, User $reviewer, ?string $note = null): ApprovalRequest
    {
        if (! $this->canReview($reviewer)) {
            throw new ApprovalException('Chỉ Super Admin được duyệt.');
        }

        $request = DB::transaction(function () use ($request, $reviewer, $note) {
            $locked = ApprovalRequest::query()->lockForUpdate()->find($request->id);

            if (! $locked || ! $locked->isPending()) {
                throw new ApprovalException('Yêu cầu này đã được xử lý.');
            }

            // Ghi nội bộ của việc áp bản duyệt không bị chặn duyệt lần nữa.
            ApprovalContext::bypass(fn () => $this->apply($locked));

            $locked->forceFill([
                'status' => ApprovalRequest::STATUS_APPROVED, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'review_note' => $note,
            ])->save();

            return $locked;
        });

        $this->notifyPartner($request, true);

        return $request;
    }

    /** @throws ApprovalException */
    public function reject(ApprovalRequest $request, User $reviewer, string $reason): ApprovalRequest
    {
        if (! $this->canReview($reviewer)) {
            throw new ApprovalException('Chỉ Super Admin được từ chối.');
        }

        if (trim($reason) === '') {
            throw new ApprovalException('Vui lòng nhập lý do từ chối.');
        }

        $request = DB::transaction(function () use ($request, $reviewer, $reason) {
            $locked = ApprovalRequest::query()->lockForUpdate()->find($request->id);

            if (! $locked || ! $locked->isPending()) {
                throw new ApprovalException('Yêu cầu này đã được xử lý.');
            }

            if ($locked->isCreate()) {
                $this->setSubjectStatus($locked, 'rejected');
            }

            $locked->forceFill([
                'status' => ApprovalRequest::STATUS_REJECTED, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'review_note' => mb_substr($reason, 0, 1000),
            ])->save();

            return $locked;
        });

        $this->notifyPartner($request, false);

        return $request;
    }

    /** Đối tác (hoặc Super Admin) rút lại yêu cầu đang chờ. Bản ghi "đăng mới" quay về trạng thái bị từ chối (vẫn sửa và gửi lại được). @throws ApprovalException */
    public function cancel(ApprovalRequest $request, User $by): ApprovalRequest
    {
        $this->assertCanAct($request, $by);

        return DB::transaction(function () use ($request, $by) {
            $locked = ApprovalRequest::query()->lockForUpdate()->find($request->id);

            if (! $locked || ! $locked->isPending()) {
                throw new ApprovalException('Chỉ rút lại được yêu cầu đang chờ duyệt.');
            }

            if ($locked->isCreate()) {
                $this->setSubjectStatus($locked, 'rejected');
            }

            $locked->forceFill(['status' => ApprovalRequest::STATUS_CANCELLED, 'reviewed_by' => $by->id, 'reviewed_at' => now(), 'review_note' => 'Đã rút lại.'])->save();

            return $locked;
        });
    }

    /** Gửi lại yêu cầu bị từ chối / đã rút lại (cùng nội dung). Bản ghi "đăng mới" chuyển lại "chờ duyệt". @throws ApprovalException */
    public function resubmit(ApprovalRequest $request, User $by): ApprovalRequest
    {
        $this->assertCanAct($request, $by);

        if (! in_array($request->status, [ApprovalRequest::STATUS_REJECTED, ApprovalRequest::STATUS_CANCELLED], true)) {
            throw new ApprovalException('Chỉ gửi lại được yêu cầu bị từ chối hoặc đã rút lại.');
        }

        if (! $this->subjectExists($request)) {
            throw new ApprovalException('Bản ghi này đã bị xoá nên không gửi lại được.');
        }

        // Đã có yêu cầu mới hơn đang chờ của cùng bản ghi: gửi lại bản cũ sẽ ghi đè nội dung mới đó (open() gộp vào yêu cầu chờ), nên chặn.
        $newerPending = ApprovalRequest::query()
            ->where('subject_type', $request->subject_type)->where('subject_id', $request->subject_id)
            ->where('kind', $request->kind)->where('status', ApprovalRequest::STATUS_PENDING)->exists();

        if ($newerPending) {
            throw new ApprovalException('Bản ghi này đã có yêu cầu mới hơn đang chờ duyệt, không gửi lại yêu cầu cũ được. Hãy sửa trên bản hiện tại hoặc rút lại yêu cầu đang chờ trước.');
        }

        if ($request->isCreate()) {
            $this->setSubjectStatus($request, 'pending');
        }

        return $this->open($request->kind, $request->subject_type, $request->subject_id, $request->partner_id, $request->subject_label, $request->payload, $by);
    }

    /** @throws ApprovalException */
    private function assertCanAct(ApprovalRequest $request, User $by): void
    {
        $own = filled($by->getRawOriginal('partner_id')) && (string) $by->getRawOriginal('partner_id') === (string) $request->partner_id;

        if (! $own && ! $this->canReview($by)) {
            throw new ApprovalException('Bạn không có quyền với yêu cầu này.');
        }
    }

    // ════════════════════════════════════════════════════════════════════
    // Áp bản duyệt
    // ════════════════════════════════════════════════════════════════════

    /** @throws ApprovalException */
    private function apply(ApprovalRequest $request): void
    {
        match ($request->kind) {
            ApprovalRequest::KIND_PRICE_BOARD_CREATE => $this->applyBoardCreate($request),
            ApprovalRequest::KIND_PRICE_BOARD_UPDATE => $this->applyBoardUpdate($request),
            ApprovalRequest::KIND_ROOM_CREATE        => $this->applyRoomCreate($request),
            ApprovalRequest::KIND_ROOM_UPDATE        => $this->applyRoomUpdate($request),
            default                                  => throw new ApprovalException('Loại yêu cầu không hợp lệ.'),
        };
    }

    private function board(ApprovalRequest $request): PriceBoard
    {
        $board = PriceBoard::withoutGlobalScopes()->find($request->subject_id);

        if (! $board) {
            throw new ApprovalException('Bảng giá không còn tồn tại.');
        }

        return $board;
    }

    private function product(ApprovalRequest $request): Product
    {
        $product = Product::withoutGlobalScopes()->find($request->subject_id);

        if (! $product) {
            throw new ApprovalException('Phòng không còn tồn tại.');
        }

        return $product;
    }

    private function applyBoardCreate(ApprovalRequest $request): void
    {
        $board = $this->board($request);
        $board->forceFill(['approval_status' => 'approved'])->save();

        if (empty($request->payload['requested_is_active'])) {
            return;
        }

        // Bảng được tạo ở trạng thái bật: bật thật sau khi duyệt, qua đúng kiểm tra trùng ngày như khi sửa bảng.
        $v2   = app(PriceBoardV2Service::class);
        $spec = $v2->specFromBoard($board->fresh());
        $spec['is_active'] = true;
        $this->assertBoardSpecApplicable($v2, $spec, $board);
        $v2->persist($spec, $board->fresh());
    }

    private function applyBoardUpdate(ApprovalRequest $request): void
    {
        $board = $this->board($request);
        $spec  = (array) ($request->payload['spec'] ?? []);

        if ($spec === []) {
            throw new ApprovalException('Yêu cầu không có nội dung bảng giá để áp dụng.');
        }

        $v2 = app(PriceBoardV2Service::class);
        $this->assertBoardSpecApplicable($v2, $spec, $board);
        $v2->persist($spec, $board);
    }

    /** Bản sửa nằm chờ có thể không còn hợp lệ (phòng bị xoá, bảng khác chiếm ngày...) — báo rõ cho người duyệt thay vì lỗi 500. */
    private function assertBoardSpecApplicable(PriceBoardV2Service $v2, array $spec, PriceBoard $board): void
    {
        $eval = $v2->evaluate($spec, $board);

        if ($eval['errors']) {
            throw new ApprovalException('Bản sửa không còn hợp lệ: ' . (string) collect($eval['errors'])->flatten()->first());
        }

        if ($eval['conflicts']) {
            $first = $eval['conflicts'][0];
            throw new ApprovalException('Không duyệt được: có phòng đang nằm trong bảng "' . $first['board']['name'] . '" đang bật và trùng ngày. Hãy từ chối hoặc nhờ đối tác đổi ngày.');
        }
    }

    private function applyRoomCreate(ApprovalRequest $request): void
    {
        $product = $this->product($request);

        $product->forceFill([
            'approval_status' => 'approved',
            'is_activated'    => (bool) ($request->payload['requested_is_activated'] ?? true),
        ])->save();
    }

    private function applyRoomUpdate(ApprovalRequest $request): void
    {
        $product = $this->product($request);
        $sync    = app(PriceBoardSyncService::class);
        $before  = $sync->snapshotPricing($product);

        $productChanges = (array) ($request->payload['product'] ?? []);
        $slotChanges    = (array) ($request->payload['timeslots'] ?? []);

        if ($productChanges !== []) {
            $product->forceFill(collect($productChanges)->map(fn (array $c) => $c['new'])->all())->save();
        }

        foreach ($slotChanges as $slotId => $fields) {
            $slot = RoomTimeSlot::query()->where('room_id', $product->id)->find($slotId);

            if ($slot) {
                $slot->forceFill(collect($fields)->map(fn (array $c) => $c['new'])->all())->save();
            }
        }

        // Giữ "Bảng giá mặc định" (baseline) khớp giá vừa duyệt — nếu không job price-boards:sync-due ban đêm sẽ ghi đè về giá cũ.
        $priceTouched = array_intersect(array_keys($productChanges), array_keys(self::PRODUCT_PRICE_FIELDS)) !== []
            || collect($slotChanges)->contains(fn ($fields) => array_intersect(array_keys((array) $fields), array_keys(self::SLOT_FIELDS)) !== []);

        if ($priceTouched) {
            $sync->afterManualPriceEdit($product->fresh(), $before);
        }
    }

    private function subjectExists(ApprovalRequest $request): bool
    {
        return $request->subject_type === ApprovalRequest::SUBJECT_PRICE_BOARD
            ? PriceBoard::withoutGlobalScopes()->whereKey($request->subject_id)->exists()
            : Product::withoutGlobalScopes()->whereKey($request->subject_id)->exists();
    }

    /** Bảng giá / phòng bị xoá: các yêu cầu đang chờ của nó không còn gì để duyệt nên tự đóng (cancelled), tránh treo mãi trong danh sách chờ. */
    public function closeForDeletedSubject(string $subjectType, string|int $subjectId): void
    {
        ApprovalRequest::query()
            ->where('subject_type', $subjectType)->where('subject_id', (string) $subjectId)->where('status', ApprovalRequest::STATUS_PENDING)
            ->update(['status' => ApprovalRequest::STATUS_CANCELLED, 'reviewed_at' => now(), 'review_note' => 'Bản ghi đã bị xoá.', 'updated_at' => now()]);
    }

    /** Đặt approval_status cho bản ghi gốc của yêu cầu "đăng mới". */
    private function setSubjectStatus(ApprovalRequest $request, string $status): void
    {
        $subject = $request->subject_type === ApprovalRequest::SUBJECT_PRICE_BOARD
            ? PriceBoard::withoutGlobalScopes()->find($request->subject_id)
            : Product::withoutGlobalScopes()->find($request->subject_id);

        $subject?->forceFill(['approval_status' => $status])->save();
    }

    // ════════════════════════════════════════════════════════════════════
    // Thông báo
    // ════════════════════════════════════════════════════════════════════

    private function partnerOf(ApprovalRequest $request): ?Partner
    {
        return Partner::query()->find($request->partner_id);
    }

    private function partnerName(?Partner $partner): string
    {
        return $partner ? ($partner->legal_name ?: $partner->name) : 'Đối tác';
    }

    /** Báo Super Admin có yêu cầu mới. */
    private function notifyAdmins(ApprovalRequest $request): void
    {
        $partner = $this->partnerOf($request);

        if (! $partner) {
            return;
        }

        app(EscrowService::class)->notifySuperAdmins(
            'approval_requested',
            'Có yêu cầu duyệt mới',
            $this->partnerName($partner) . ' gửi duyệt: ' . $request->kindLabel() . ($request->subject_label ? ' "' . $request->subject_label . '"' : '') . '.',
            $partner,
            ['approval_id' => $request->id, 'approval_kind' => $request->kind],
        );
    }

    /** Báo đối tác kết quả duyệt / từ chối. */
    private function notifyPartner(ApprovalRequest $request, bool $approved): void
    {
        $partner = $this->partnerOf($request);

        if (! $partner) {
            return;
        }

        $what = $request->kindLabel() . ($request->subject_label ? ' "' . $request->subject_label . '"' : '');

        app(PartnerNotifier::class)->send(
            $partner,
            $approved ? 'approval_approved' : 'approval_rejected',
            $approved ? '365home đã duyệt' : '365home từ chối duyệt',
            $approved ? "{$what} đã được duyệt và có hiệu lực." : "{$what} bị từ chối. Lý do: {$request->review_note}",
            ['approval_id' => $request->id, 'approval_kind' => $request->kind],
            $approved ? 'success' : 'danger',
            false,
        );
    }

    // ════════════════════════════════════════════════════════════════════
    // Trình bày
    // ════════════════════════════════════════════════════════════════════

    /**
     * Danh sách thay đổi để người duyệt đối chiếu: [{field, label, old, new}].
     *
     * @return list<array{field: string, label: string, old: mixed, new: mixed}>
     */
    public function changesFor(ApprovalRequest $request): array
    {
        return match ($request->kind) {
            ApprovalRequest::KIND_ROOM_UPDATE        => $this->roomChanges($request),
            ApprovalRequest::KIND_PRICE_BOARD_UPDATE => $this->boardChanges($request),
            default                                  => [],
        };
    }

    /** @return list<array{field: string, label: string, old: mixed, new: mixed}> */
    private function roomChanges(ApprovalRequest $request): array
    {
        $rows   = [];
        $labels = self::PRODUCT_CONTENT_FIELDS + self::PRODUCT_PRICE_FIELDS;

        foreach ((array) ($request->payload['product'] ?? []) as $field => $c) {
            $rows[] = ['field' => $field, 'label' => $labels[$field] ?? $field, 'old' => $c['old'] ?? null, 'new' => $c['new'] ?? null];
        }

        foreach ((array) ($request->payload['timeslots'] ?? []) as $slotId => $fields) {
            $slot  = RoomTimeSlot::query()->with('timeSlot')->find($slotId);
            $label = $slot?->timeSlot?->label ?? ('#' . $slotId);

            foreach ((array) $fields as $field => $c) {
                $rows[] = ['field' => "timeslot.{$slotId}.{$field}", 'label' => 'Khung giờ ' . $label . ' — ' . (self::SLOT_FIELDS[$field] ?? $field), 'old' => $c['old'] ?? null, 'new' => $c['new'] ?? null];
            }
        }

        return $rows;
    }

    /** @return list<array{field: string, label: string, old: mixed, new: mixed}> */
    private function boardChanges(ApprovalRequest $request): array
    {
        $board = PriceBoard::withoutGlobalScopes()->find($request->subject_id);
        $new   = (array) ($request->payload['spec'] ?? []);

        if (! $board || $new === []) {
            return [];
        }

        $old    = (array) ($request->payload['old'] ?? app(PriceBoardV2Service::class)->specFromBoard($board));
        $fields = [
            'name' => 'Tên bảng giá', 'note' => 'Ghi chú', 'scope' => 'Phạm vi', 'is_active' => 'Kích hoạt', 'start_date' => 'Ngày bắt đầu',
            'end_date' => 'Ngày kết thúc', 'priority' => 'Độ ưu tiên', 'category_id' => 'Chi nhánh', 'branch_ids' => 'Các chi nhánh',
            'product_ids' => 'Các phòng', 'days_of_week' => 'Các thứ trong tuần',
        ];

        $rows = [];

        foreach ($fields as $key => $label) {
            $a = $old[$key] ?? null;
            $b = $new[$key] ?? null;

            if (json_encode($a) !== json_encode($b)) {
                $rows[] = ['field' => $key, 'label' => $label, 'old' => $a, 'new' => $b];
            }
        }

        // Bộ giá: so sánh nội dung (bỏ id) — chỉ báo có đổi + tên các bộ, chi tiết xem ở trang bảng giá.
        $strip = fn (array $sheets) => array_map(function (array $s) {
            unset($s['id']);

            return $s;
        }, $sheets);

        $oldSheets = $strip((array) ($old['sheets'] ?? []));
        $newSheets = $strip((array) ($new['sheets'] ?? []));

        if (json_encode($oldSheets) !== json_encode($newSheets)) {
            $names = fn (array $sheets) => implode(', ', array_map(fn ($s) => (string) ($s['name'] ?? 'Bộ giá'), $sheets)) . ' (' . count($sheets) . ' bộ)';
            $rows[] = ['field' => 'sheets', 'label' => 'Bộ giá (giá, khung giờ, phòng áp dụng)', 'old' => $names($oldSheets), 'new' => $names($newSheets) . ' — nội dung giá có thay đổi'];
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    public function present(ApprovalRequest $request, bool $detail = false): array
    {
        $request->loadMissing(['partner:id,name,legal_name', 'requester:id,fullname', 'reviewer:id,fullname']);

        $out = [
            'id'            => $request->id,
            'kind'          => $request->kind,
            'kind_label'    => $request->kindLabel(),
            'status'        => $request->status,
            'status_label'  => $request->statusLabel(),
            'subject_type'  => $request->subject_type,
            'subject_id'    => $request->subject_id,
            'subject_label' => $request->subject_label,
            'partner'       => $request->partner ? ['id' => $request->partner->id, 'name' => $request->partner->legal_name ?: $request->partner->name] : null,
            'requested_by'  => $request->requester ? ['id' => $request->requester->id, 'name' => $request->requester->fullname] : null,
            'requested_at'  => $request->created_at?->toDateTimeString(),
            'reviewed_by'   => $request->reviewer ? ['id' => $request->reviewer->id, 'name' => $request->reviewer->fullname] : null,
            'reviewed_at'   => $request->reviewed_at?->toDateTimeString(),
            'review_note'   => $request->review_note,
        ];

        if ($detail) {
            $out['changes'] = $this->changesFor($request);

            if ($request->kind === ApprovalRequest::KIND_PRICE_BOARD_CREATE || $request->kind === ApprovalRequest::KIND_ROOM_CREATE) {
                $out['requested_active'] = (bool) ($request->payload['requested_is_active'] ?? $request->payload['requested_is_activated'] ?? false);
            }
        }

        return $out;
    }

    /** @var array<string, array<string, bool>> */
    private static array $pendingFlags = [];

    /** Bản ghi có yêu cầu SỬA đang chờ duyệt không — nạp 1 lần cho cả loại (số yêu cầu chờ nhỏ) để bảng Filament không bị N+1. */
    public function hasPendingUpdate(string $subjectType, string|int $id): bool
    {
        self::$pendingFlags[$subjectType] ??= ApprovalRequest::query()
            ->where('subject_type', $subjectType)
            ->whereIn('kind', [ApprovalRequest::KIND_PRICE_BOARD_UPDATE, ApprovalRequest::KIND_ROOM_UPDATE])
            ->where('status', ApprovalRequest::STATUS_PENDING)
            ->pluck('subject_id')->mapWithKeys(fn ($v) => [(string) $v => true])->all();

        return isset(self::$pendingFlags[$subjectType][(string) $id]);
    }

    /**
     * Các bản ghi (bảng giá / phòng) đang có yêu cầu SỬA chờ duyệt — để danh sách hiện "có thay đổi chờ duyệt".
     *
     * @param  list<int|string>  $ids
     * @return array<string, int> subject_id => id yêu cầu
     */
    public function pendingUpdateMap(string $subjectType, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $kind = $subjectType === ApprovalRequest::SUBJECT_PRICE_BOARD ? ApprovalRequest::KIND_PRICE_BOARD_UPDATE : ApprovalRequest::KIND_ROOM_UPDATE;

        return ApprovalRequest::query()
            ->where('subject_type', $subjectType)->where('kind', $kind)->where('status', ApprovalRequest::STATUS_PENDING)
            ->whereIn('subject_id', array_map('strval', $ids))
            ->pluck('id', 'subject_id')->map(fn ($v) => (int) $v)->all();
    }
}
