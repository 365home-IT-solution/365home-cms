<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\PartnerLegalDocument;
use App\Services\PartnerLegalDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PartnerLegalDocumentController extends Controller
{
    public function __construct(private readonly PartnerLegalDocumentService $service) {}

    public function index(Request $request, Partner $partner): JsonResponse
    {
        $this->authorizePartner($request, $partner);
        $documents = $partner->legalDocuments()->with(['reviewer:id,fullname', 'building:id,name', 'media'])->latest()->get();

        return response()->json([
            'data' => $documents->map(fn ($document) => $this->format($document)),
            'verification' => $this->verification($partner),
            'document_types' => PartnerLegalDocument::TYPES,
        ]);
    }

    public function show(Request $request, Partner $partner, PartnerLegalDocument $document): JsonResponse
    {
        $this->authorizeDocument($request, $partner, $document);

        return response()->json(['data' => $this->format($document->load(['reviewer:id,fullname', 'media']))]);
    }

    public function store(Request $request, Partner $partner): JsonResponse
    {
        $this->authorizePartner($request, $partner);
        $data = $this->validateDocument($request);
        $this->validateBuildingScope($partner, $data);
        $isSuperAdmin = $request->user()->isSuperAdmin();

        $document = DB::transaction(function () use ($request, $partner, $data, $isSuperAdmin) {
            $document = $partner->legalDocuments()->create([
                ...collect($data)->except('file', 'is_required')->all(),
                'is_required' => $data['type'] === 'business_license'
                    ? true
                    : ($isSuperAdmin ? (bool) ($data['is_required'] ?? false) : false),
                'status' => 'draft',
                'created_by' => $request->user()->id,
            ]);
            $document->addMediaFromRequest('file')->toMediaCollection('file');

            return $document;
        });

        return response()->json(['message' => 'Đã thêm giấy tờ.', 'data' => $this->format($document->load('media'))], 201);
    }

    public function update(Request $request, Partner $partner, PartnerLegalDocument $document): JsonResponse
    {
        $this->authorizeDocument($request, $partner, $document);
        if (in_array($document->status, ['pending_review', 'approved'], true)) {
            return response()->json(['message' => 'Không thể sửa giấy tờ đang chờ duyệt hoặc đã duyệt. Hãy tạo bản giấy tờ mới.'], 409);
        }

        $data = $this->validateDocument($request, false);
        $this->validateBuildingScope($partner, $data, $document);
        if (isset($data['is_required']) && ! $request->user()->isSuperAdmin()) {
            unset($data['is_required']);
        }
        if (($data['type'] ?? $document->type) === 'business_license') {
            $data['is_required'] = true;
        }

        DB::transaction(function () use ($request, $document, $data) {
            $document->update([
                ...collect($data)->except('file')->all(),
                'status' => 'draft',
                'review_note' => null,
                'reviewed_at' => null,
                'reviewed_by' => null,
            ]);
            if ($request->hasFile('file')) {
                $document->addMediaFromRequest('file')->toMediaCollection('file');
            }
        });

        return response()->json(['message' => 'Đã cập nhật giấy tờ.', 'data' => $this->format($document->fresh(['media']))]);
    }

    public function destroy(Request $request, Partner $partner, PartnerLegalDocument $document): JsonResponse
    {
        $this->authorizeDocument($request, $partner, $document);
        if (! in_array($document->status, ['draft', 'changes_requested', 'rejected'], true)) {
            return response()->json(['message' => 'Chỉ được xóa giấy tờ nháp, bị từ chối hoặc cần bổ sung.'], 409);
        }
        $document->delete();

        return response()->json(['message' => 'Đã xóa giấy tờ.']);
    }

    public function submit(Request $request, Partner $partner): JsonResponse
    {
        $this->authorizePartner($request, $partner);
        $this->service->submit($partner);

        return response()->json(['message' => 'Đã gửi hồ sơ pháp lý để Super Admin xét duyệt.', 'verification' => $this->verification($partner->fresh())]);
    }

    public function review(Request $request, Partner $partner, PartnerLegalDocument $document): JsonResponse
    {
        $this->authorizeDocument($request, $partner, $document);
        $this->requireSuperAdmin($request);
        $data = $request->validate([
            'status' => ['required', Rule::in(['approved', 'changes_requested', 'rejected'])],
            'review_note' => ['nullable', 'string', 'max:2000'],
            'is_required' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('is_required', $data) && $document->type !== 'business_license') {
            $document->update(['is_required' => $data['is_required']]);
        }
        $this->service->review($document, $data['status'], $data['review_note'] ?? null, $request->user());

        return response()->json(['message' => 'Đã cập nhật kết quả xét duyệt.', 'data' => $this->format($document->fresh(['reviewer', 'media']))]);
    }

    public function approveDossier(Request $request, Partner $partner): JsonResponse
    {
        $this->authorizePartner($request, $partner);
        $this->requireSuperAdmin($request);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);
        $this->service->approveDossier($partner, $request->user(), $data['note'] ?? null);

        return response()->json(['message' => 'Đã phê duyệt hồ sơ pháp lý.', 'verification' => $this->verification($partner->fresh())]);
    }

    public function download(Request $request, Partner $partner, PartnerLegalDocument $document): BinaryFileResponse
    {
        $this->authorizeDocument($request, $partner, $document);
        $media = $document->getFirstMedia('file');
        abort_unless($media && is_file($media->getPath()), 404);

        return response()->download($media->getPath(), $media->file_name, ['Content-Type' => $media->mime_type]);
    }

    private function validateDocument(Request $request, bool $fileRequired = true): array
    {
        return $request->validate([
            'type' => [$fileRequired ? 'required' : 'sometimes', Rule::in(array_keys(PartnerLegalDocument::TYPES))],
            'building_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'name' => ['nullable', 'string', 'max:255'],
            'document_number' => ['nullable', 'string', 'max:100'],
            'issuer' => ['nullable', 'string', 'max:255'],
            'issued_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:issued_at'],
            'is_required' => ['sometimes', 'boolean'],
            'file' => [$fileRequired ? 'required' : 'sometimes', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ]);
    }

    private function validateBuildingScope(Partner $partner, array $data, ?PartnerLegalDocument $document = null): void
    {
        $type = $data['type'] ?? $document?->type;
        $buildingId = array_key_exists('building_id', $data) ? $data['building_id'] : $document?->building_id;
        $buildingTypes = ['fire_safety', 'security_order', 'property_ownership_or_use'];

        if ($partner->isMinihouse() && in_array($type, $buildingTypes, true)) {
            abort_if(blank($buildingId), 422, 'Giấy tờ PCCC, ANTT và quyền khai thác phải chọn tòa nhà.');
            abort_unless($partner->categories()->whereKey($buildingId)->where('category_type', 'product')->whereNull('parent_id')->exists(), 422, 'Tòa nhà không thuộc đối tác MiniHouse này.');
        } else {
            abort_if(filled($buildingId), 422, 'Loại giấy tờ này được quản lý ở cấp đối tác, không gắn với tòa nhà.');
        }
    }

    private function authorizePartner(Request $request, Partner $partner): void
    {
        $user = $request->user();
        abort_unless($user->isSuperAdmin() || $user->partner_id === $partner->id, 403, 'Bạn không có quyền truy cập hồ sơ đối tác này.');
    }

    private function authorizeDocument(Request $request, Partner $partner, PartnerLegalDocument $document): void
    {
        $this->authorizePartner($request, $partner);
        abort_unless($document->partner_id === $partner->id, 404);
    }

    private function requireSuperAdmin(Request $request): void
    {
        abort_unless($request->user()->isSuperAdmin(), 403, 'Chỉ Super Admin được xét duyệt hồ sơ.');
    }

    private function verification(Partner $partner): array
    {
        return [
            'status' => $partner->verification_status,
            'submitted_at' => $partner->verification_submitted_at?->toIso8601String(),
            'verified_at' => $partner->verified_at?->toIso8601String(),
            'readiness' => $this->service->readiness($partner),
            'can_create_contract' => $this->service->isContractEligible($partner),
        ];
    }

    private function format(PartnerLegalDocument $document): array
    {
        $media = $document->getFirstMedia('file');

        return [
            'id' => $document->id,
            'partner_id' => $document->partner_id,
            'building_id' => $document->building_id,
            'building' => $document->building ? ['id' => $document->building->id, 'name' => $document->building->name] : null,
            'type' => $document->type,
            'type_label' => PartnerLegalDocument::TYPES[$document->type] ?? $document->type,
            'name' => $document->name,
            'document_number' => $document->document_number,
            'issuer' => $document->issuer,
            'issued_at' => $document->issued_at?->toDateString(),
            'expires_at' => $document->expires_at?->toDateString(),
            'is_expired' => $document->isExpired(),
            'is_required' => $document->is_required,
            'status' => $document->status,
            'status_label' => PartnerLegalDocument::STATUSES[$document->status] ?? $document->status,
            'review_note' => $document->review_note,
            'submitted_at' => $document->submitted_at?->toIso8601String(),
            'reviewed_at' => $document->reviewed_at?->toIso8601String(),
            'reviewed_by' => $document->reviewer ? ['id' => $document->reviewer->id, 'name' => $document->reviewer->fullname] : null,
            'file' => $media ? [
                'name' => $media->file_name,
                'mime_type' => $media->mime_type,
                'size' => $media->size,
                // Route Homestay/MiniHouse bị partner.type chặn chéo (404) — link tải phải theo đúng
                // namespace của loại đối tác sở hữu giấy tờ.
                'download_url' => route($document->partner?->isMinihouse()
                    ? 'api.admin.minihouse.partners.legal-documents.download'
                    : 'api.admin.partners.legal-documents.download', [$document->partner_id, $document->id]),
            ] : null,
            'created_at' => $document->created_at?->toIso8601String(),
            'updated_at' => $document->updated_at?->toIso8601String(),
        ];
    }
}
