<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Partner;
use App\Models\PartnerLegalDocument;
use App\Models\PartnerStatusLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PartnerLegalDocumentService
{
    public function readiness(Partner $partner): array
    {
        $documents = $partner->legalDocuments()->with('media')->get();
        $required = $documents->filter(fn (PartnerLegalDocument $document) => $document->is_required || $document->type === 'business_license');
        $problems = [];
        // Đăng ký trên website (Homestay, MiniHouse đăng ký dùng thử): bắt buộc cả An toàn an ninh và Phòng cháy chữa cháy; còn lại chỉ Giấy phép kinh doanh.
        $requiredTypes = $partner->requiresRegistrationDocuments() ? PartnerLegalDocument::REGISTRATION_REQUIRED : ['business_license'];

        foreach ($requiredTypes as $requiredType) {
            $matching = $documents->where('type', $requiredType)->whereNull('building_id');
            if ($matching->isEmpty()) {
                $problems[] = 'Thiếu '.(PartnerLegalDocument::TYPES[$requiredType] ?? $requiredType).'.';
            } elseif (! $matching->contains(fn (PartnerLegalDocument $document) => $this->isUsable($document))) {
                $problems[] = (PartnerLegalDocument::TYPES[$requiredType] ?? $requiredType).' chưa được duyệt hoặc đã hết hạn.';
            }
        }

        // Hồ sơ đăng ký hợp tác công khai (chưa có tài khoản/toà nhà): chỉ xét Giấy phép kinh doanh; giấy tờ cấp toà nhà bổ sung sau khi có toà nhà.
        $preAccount = filled($partner->onboarding_token) && ! $partner->users()->exists();
        // MiniHouse đăng ký dùng thử: PCCC/ANTT nộp ở cấp đối tác, không đòi thêm theo từng toà nhà.
        $perBuilding = $partner->isMinihouse() && ! $preAccount && ! $partner->minihouseDocumentsFlow();

        if ($perBuilding) {
            $buildingTypes = ['fire_safety', 'security_order', 'property_ownership_or_use'];
            $buildings = $partner->categories()->where('category_type', 'product')->whereNull('parent_id')->get(['id', 'name']);

            if ($buildings->isEmpty()) {
                $problems[] = 'Đối tác MiniHouse chưa được gán tòa nhà.';
            }

            foreach ($buildings as $building) {
                foreach ($buildingTypes as $requiredType) {
                    $matching = $documents->where('building_id', $building->id)->where('type', $requiredType);
                    $label = PartnerLegalDocument::TYPES[$requiredType] ?? $requiredType;
                    if ($matching->isEmpty()) {
                        $problems[] = "Tòa nhà {$building->name}: thiếu {$label}.";
                    } elseif (! $matching->contains(fn (PartnerLegalDocument $document) => $this->isUsable($document))) {
                        $problems[] = "Tòa nhà {$building->name}: {$label} chưa được duyệt hoặc đã hết hạn.";
                    }
                }
            }
        }

        foreach ($required as $document) {
            if (! $this->isUsable($document)) {
                $problems[] = (PartnerLegalDocument::TYPES[$document->type] ?? $document->name ?? $document->type)
                    .' chưa được duyệt hoặc đã hết hạn.';
            }
        }

        return [
            'ready' => $problems === [],
            'approved' => $required->filter(fn ($document) => $this->isUsable($document))->count(),
            'required' => max(count($requiredTypes) + ($perBuilding
                ? max(1, $partner->categories()->where('category_type', 'product')->whereNull('parent_id')->count()) * 3
                : 0), $required->count()),
            'problems' => array_values(array_unique($problems)),
        ];
    }

    public function isContractEligible(Partner $partner): bool
    {
        return $partner->verification_status === 'approved' && $this->readiness($partner)['ready'];
    }

    public function assertContractEligible(Partner $partner): void
    {
        $readiness = $this->readiness($partner);

        if ($partner->verification_status !== 'approved' || ! $readiness['ready']) {
            throw ValidationException::withMessages([
                'legal_documents' => $readiness['problems'] ?: ['Hồ sơ pháp lý chưa được Super Admin phê duyệt.'],
            ]);
        }
    }

    public function submit(Partner $partner): void
    {
        $documents = $partner->legalDocuments()->with('media')->get();
        if ($documents->whereIn('status', ['draft', 'changes_requested', 'rejected'])->isEmpty()) {
            throw ValidationException::withMessages(['documents' => 'Không có giấy tờ mới để gửi duyệt.']);
        }

        foreach ($documents->whereIn('status', ['draft', 'changes_requested', 'rejected']) as $document) {
            if (! $document->hasMedia('file')) {
                throw ValidationException::withMessages(['documents' => 'Mỗi giấy tờ gửi duyệt phải có tệp đính kèm.']);
            }
        }

        DB::transaction(function () use ($partner) {
            $partner->legalDocuments()->whereIn('status', ['draft', 'changes_requested', 'rejected'])->update([
                'status' => 'pending_review',
                'submitted_at' => now(),
                'review_note' => null,
                'reviewed_at' => null,
                'reviewed_by' => null,
            ]);
            if ($this->keepsPartnerStatus($partner)) {
                return;
            }
            $this->changePartnerStatus($partner, 'pending', 'Đối tác đã gửi hồ sơ pháp lý để xét duyệt.');
            $partner->update(['verification_submitted_at' => now(), 'verified_at' => null, 'verified_by' => null]);
        });
    }

    public function review(PartnerLegalDocument $document, string $status, ?string $note, User $reviewer): void
    {
        if (! $reviewer->isSuperAdmin()) {
            abort(403, 'Chỉ Super Admin được xác minh giấy tờ.');
        }
        if (! in_array($status, ['approved', 'changes_requested', 'rejected'], true)) {
            throw ValidationException::withMessages(['status' => 'Trạng thái xét duyệt không hợp lệ.']);
        }
        if ($status !== 'approved' && blank($note)) {
            throw ValidationException::withMessages(['review_note' => 'Phải nhập lý do khi yêu cầu bổ sung hoặc từ chối.']);
        }
        if (! $document->hasMedia('file')) {
            throw ValidationException::withMessages(['file' => 'Giấy tờ chưa có tệp đính kèm.']);
        }

        $document->update([
            'status' => $status,
            'review_note' => $note,
            'reviewed_at' => now(),
            'reviewed_by' => $reviewer->id,
        ]);

        // Hồ sơ đăng ký công khai: báo lý do cho đối tác qua email (họ chưa có tài khoản để xem thông báo).
        app(PartnerOnboardingService::class)->notifyDocumentReviewed($document->fresh('partner'), $status, $note);

        // Hồ sơ đăng ký hợp tác công khai: admin yêu cầu bổ sung/từ chối → mở lại cho đối tác sửa và nộp lại (gửi duyệt lần nữa).
        if ($status !== 'approved' && filled($document->partner->onboarding_token) && $document->partner->verification_status === 'pending') {
            $document->partner->update(['verification_submitted_at' => null]);
        }

        if ($status !== 'approved' && $document->partner->verification_status === 'approved' && ! $this->keepsPartnerStatus($document->partner)) {
            $this->changePartnerStatus($document->partner, 'pending', 'Giấy tờ pháp lý cần được xác minh lại.');
        }
    }

    public function approveDossier(Partner $partner, User $reviewer, ?string $note = null): void
    {
        if (! $reviewer->isSuperAdmin()) {
            abort(403, 'Chỉ Super Admin được phê duyệt hồ sơ.');
        }
        $readiness = $this->readiness($partner);
        if (! $readiness['ready']) {
            throw ValidationException::withMessages(['legal_documents' => $readiness['problems']]);
        }

        if ($partner->minihouseDocumentsFlow()) {
            // MiniHouse đăng ký dùng thử: giấy tờ đã duyệt đủ → tặng dùng thử, kích hoạt đối tác, cấp tài khoản và gửi email đăng nhập.
            if (app(PartnerOnboardingService::class)->awaitingSignupApproval($partner)) {
                app(PartnerOnboardingService::class)->approveSignup($partner, $reviewer);
            }

            return;
        }

        DB::transaction(function () use ($partner, $reviewer, $note) {
            $this->changePartnerStatus($partner, 'approved', $note ?: 'Super Admin đã xác minh toàn bộ hồ sơ pháp lý.');
            $partner->update([
                'status' => true,
                'verified_at' => now(),
                'verified_by' => $reviewer->id,
                'verification_note' => $note,
            ]);
        });

        // Hồ sơ gửi từ luồng đăng ký hợp tác công khai: giấy tờ đã được duyệt → tự tạo hợp đồng và gửi link ký cho đối tác.
        if (filled($partner->onboarding_token)) {
            app(PartnerOnboardingService::class)->sendContractAfterApproval($partner->fresh());
        }
    }

    /**
     * TỪ CHỐI cả hồ sơ (Super Admin): đặt verification_status = rejected, khoá đối tác, vô hiệu link ký hợp đồng chưa được xác nhận.
     * Không áp dụng khi hợp đồng đã có hiệu lực (dùng "Tạm dừng"/chấm dứt hợp đồng). Hồ sơ đăng ký công khai được gửi email lý do.
     */
    public function rejectDossier(Partner $partner, User $reviewer, string $reason): void
    {
        if (! $reviewer->isSuperAdmin()) {
            abort(403, 'Chỉ Super Admin được từ chối hồ sơ.');
        }
        if (blank(trim($reason))) {
            throw ValidationException::withMessages(['reason' => 'Phải nhập lý do từ chối hồ sơ.']);
        }
        if ($partner->contract_status === 'active') {
            throw ValidationException::withMessages(['status' => 'Hợp đồng đã có hiệu lực — không thể từ chối hồ sơ.']);
        }
        if ($partner->verification_status === 'rejected') {
            throw ValidationException::withMessages(['status' => 'Hồ sơ đã bị từ chối trước đó.']);
        }

        DB::transaction(function () use ($partner, $reviewer, $reason) {
            $this->changePartnerStatus($partner, 'rejected', 'Super Admin từ chối hồ sơ: ' . $reason);
            $partner->update([
                'status' => false,
                'verification_note' => $reason,
                'verified_at' => null,
                'verified_by' => $reviewer->id,
                'contract_status' => 'draft',
            ]);
            // Link ký chưa được đối tác xác nhận mất hiệu lực.
            $partner->contractVersions()->whereNull('partner_confirmed_at')->update(['signing_token' => null]);
        });

        app(PartnerOnboardingService::class)->notifyDossierRejected($partner->fresh(), $reason);
    }

    public function snapshot(Partner $partner): array
    {
        return $partner->legalDocuments()->with('media')->get()->map(fn (PartnerLegalDocument $document) => [
            'id' => $document->id,
            'type' => $document->type,
            'document_number' => $document->document_number,
            'issuer' => $document->issuer,
            'issued_at' => $document->issued_at?->toDateString(),
            'expires_at' => $document->expires_at?->toDateString(),
            'file_sha256' => ($media = $document->getFirstMedia('file')) && is_file($media->getPath())
                ? hash_file('sha256', $media->getPath()) : null,
            'approved_at' => $document->reviewed_at?->toIso8601String(),
        ])->all();
    }

    // MiniHouse ĐÃ có tài khoản: bổ sung/duyệt lại giấy tờ không đổi trạng thái đối tác (pending sẽ khoá đăng nhập của tài khoản đang dùng).
    private function keepsPartnerStatus(Partner $partner): bool
    {
        return $partner->minihouseDocumentsFlow() && $partner->users()->exists();
    }

    private function isUsable(PartnerLegalDocument $document): bool
    {
        return $document->status === 'approved' && ! $document->isExpired() && $document->hasMedia('file');
    }

    private function changePartnerStatus(Partner $partner, string $status, string $note): void
    {
        $from = $partner->verification_status;
        $partner->update(['verification_status' => $status]);
        if ($from !== $status) {
            PartnerStatusLog::create([
                'partner_id' => $partner->id,
                'from_status' => $from,
                'to_status' => $status,
                'note' => $note,
                'changed_by' => auth()->id(),
            ]);
        }
    }
}
