<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\TermsAcceptance;
use App\Models\TermsVersion;
use App\Services\TermsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// Quản lý phiên bản Điều khoản + xem lịch sử khách đồng ý — CHỈ Super Admin. Phiên bản chỉ THÊM MỚI, không có API sửa/xoá. Loại Điều khoản (Homestay/MiniHouse) được ép theo đường dẫn.
class TermsController extends Controller
{
    private function superAdmin(Request $request): void
    {
        abort_unless($request->user()->isSuperAdmin(), 403, 'Chỉ Super Admin được thực hiện thao tác này.');
    }

    // Loại Điều khoản được ép theo đường dẫn: /api/admin/terms/* → Homestay, /api/admin/minihouse/terms/* → MiniHouse.
    private function forcedType(Request $request): string
    {
        return (string) ($request->route('terms_type') ?: TermsVersion::TYPE_HOMESTAY);
    }

    // GET /api/admin/terms/versions  |  /api/admin/minihouse/terms/versions
    public function versions(Request $request): JsonResponse
    {
        $this->superAdmin($request);

        $rows = TermsVersion::query()->withCount('acceptances')
            ->where('type', $this->forcedType($request))
            ->orderBy('type')->orderByDesc('effective_at')->orderByDesc('id')->get();

        return response()->json(['data' => $rows->map(fn (TermsVersion $v) => $v->toApi(false) + ['acceptances_count' => $v->acceptances_count])->values()]);
    }

    // GET /api/admin/terms/versions/{version}
    public function showVersion(Request $request, TermsVersion $version): JsonResponse
    {
        $this->superAdmin($request);
        abort_unless($version->type === $this->forcedType($request), 404);

        return response()->json(['data' => $version->toApi() + ['acceptances_count' => $version->acceptances()->count(), 'integrity_valid' => $version->contentIntegrityValid()]]);
    }

    // POST /api/admin/terms/versions — tạo phiên bản MỚI (không ghi đè bản cũ). version bỏ trống → tự tăng.
    public function storeVersion(Request $request, TermsService $terms): JsonResponse
    {
        $this->superAdmin($request);
        $data = $request->validate([
            'title'        => ['required', 'string', 'max:255'],
            'content'      => ['required', 'string', 'min:20'],
            'version'      => ['nullable', 'string', 'max:20'],
            'effective_at' => ['nullable', 'date'],
        ]);

        $version = $terms->createVersion($this->forcedType($request), $data['title'], $data['content'], $data['version'] ?? null, isset($data['effective_at']) ? \Illuminate\Support\Carbon::parse($data['effective_at']) : null, $request->user());

        return response()->json(['message' => 'Đã tạo phiên bản Điều khoản mới. Các phiên bản cũ được giữ nguyên.', 'data' => $version->toApi()], 201);
    }

    // GET /api/admin/terms/acceptances?type=&partner_id=&version_id=&search=&from=&to=&per_page=
    public function acceptances(Request $request): JsonResponse
    {
        $this->superAdmin($request);
        $f = $request->validate([
            'partner_id' => ['nullable', 'string'], 'version_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = TermsAcceptance::query()
            ->where('type', $this->forcedType($request))
            ->when($f['partner_id'] ?? null, fn ($q, $v) => $q->where('partner_id', $v))
            ->when($f['version_id'] ?? null, fn ($q, $v) => $q->where('terms_version_id', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('accepted_at', '>=', \Illuminate\Support\Carbon::parse($v)->startOfDay()))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('accepted_at', '<=', \Illuminate\Support\Carbon::parse($v)->endOfDay()))
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('full_name', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")
                ->orWhere('business_name', 'like', "%{$s}%")->orWhere('order_code', $s)->orWhere('transaction_ref', $s)))
            ->orderByDesc('accepted_at')->orderByDesc('id')
            ->paginate((int) ($f['per_page'] ?? 20));

        return response()->json([
            'data' => collect($page->items())->map(fn (TermsAcceptance $a) => $a->toApi())->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    // GET /api/admin/terms/acceptances/{acceptance}
    public function showAcceptance(Request $request, TermsAcceptance $acceptance): JsonResponse
    {
        $this->superAdmin($request);
        abort_unless($acceptance->type === $this->forcedType($request), 404);

        return response()->json(['data' => $acceptance->toApi() + ['terms_title' => $acceptance->version?->title, 'terms_content' => $acceptance->version?->content]]);
    }

    // GET /api/admin/partners/{partner}/terms-acceptances
    public function partnerAcceptances(Request $request, Partner $partner): JsonResponse
    {
        $this->superAdmin($request);

        return response()->json(['data' => TermsAcceptance::query()->where('partner_id', $partner->id)->where('type', $this->forcedType($request))->orderByDesc('accepted_at')->get()->map(fn (TermsAcceptance $a) => $a->toApi())->values()]);
    }
}
