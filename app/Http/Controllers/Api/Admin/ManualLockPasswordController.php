<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Category\Entities\Category;
use Modules\Product\App\Models\ManualLockPassword;
use Modules\Product\App\Support\ManualLockPasswordTtlockIssuer as Issuer;

// Bản API của bảng "Khóa thủ công" trong trang Khóa cổng (App\Filament\Pages\GateLockManagement) và
// nút "Cấp mã mở hàng loạt" — logic cấp mã dùng chung ManualLockPasswordTtlockIssuer, phạm vi xem
// dùng chung ManualLockPassword::scopeVisibleTo(). Route /api/admin/* không bật global scope
// 'partner' nên mọi lọc theo đối tác đều làm tay ở 2 chỗ đó.
class ManualLockPasswordController extends Controller
{
    // GET /api/admin/manual-lock-passwords
    //   ?category_id=  ?is_active=0|1  ?product_id=  ?search=  ?date=Y-m-d (đang hiệu lực ngày đó)  ?per_page=
    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);

        if (! $this->canView($user)) {
            return response()->json(['message' => 'Không có quyền xem mật khẩu khóa thủ công.'], 403);
        }

        $query = ManualLockPassword::query()
            ->visibleTo($user)
            ->with(['category:id,name,partner_id', 'category.partner:id,name', 'products:id,name'])
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->integer('category_id')))
            ->when($request->filled('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($request->filled('product_id'), fn ($q) => $q->whereHas('products', fn ($p) => $p->where('products.id', $request->input('product_id'))))
            ->when($request->filled('search'), fn ($q) => $q->where(function ($s) use ($request) {
                $term = '%' . $request->input('search') . '%';
                $s->where('name', 'like', $term)->orWhere('gate_password', 'like', $term)->orWhere('room_password', 'like', $term);
            }))
            ->when($request->filled('date'), function ($q) use ($request) {
                $day = Carbon::parse($request->input('date'));
                $q->where('valid_from', '<=', $day->copy()->endOfDay())->where('valid_until', '>=', $day->copy()->startOfDay());
            })
            ->orderByDesc('valid_from')
            ->orderByDesc('id');

        $page = $query->paginate(min($request->integer('per_page', 20), 100));
        $page->getCollection()->transform(fn (ManualLockPassword $r) => $this->toItem($r));

        return response()->json($page);
    }

    // GET /api/admin/manual-lock-passwords/ttlock-options[?category_id=]
    // Không có category_id: danh sách chi nhánh cấp được mã (có tài khoản TTLock). Có category_id:
    // thêm danh sách khóa TTLock + phòng của chi nhánh đó, và đối tác (suy ra từ chi nhánh).
    public function ttlockOptions(Request $request): JsonResponse
    {
        $user = $this->user($request);

        if (! $user->can('create', ManualLockPassword::class)) {
            return response()->json(['message' => 'Không có quyền cấp mã khóa thủ công.'], 403);
        }

        $branches = Issuer::branches($user);
        $data     = [
            'branches' => collect($branches)->map(fn ($name, $id) => ['id' => (int) $id, 'name' => $name])->values(),
            'max_days' => Issuer::MAX_DAYS,
            'defaults' => ['from_time' => '06:00', 'until_time' => '12:00', 'per_day' => true, 'room_same_as_gate' => true, 'is_active' => true],
        ];

        if ($request->filled('category_id')) {
            $categoryId = $request->integer('category_id');

            if (! array_key_exists($categoryId, $branches)) {
                return response()->json(['message' => 'Chi nhánh không hợp lệ hoặc chưa có tài khoản TTLock hoạt động.'], 422);
            }

            $partner = Category::with('partner:id,name')->find($categoryId)?->partner;

            $data['partner']  = $partner ? ['id' => $partner->id, 'name' => $partner->name] : null;
            $data['locks']    = collect(Issuer::locks($categoryId))->map(fn ($name, $id) => ['lock_id' => (int) $id, 'name' => $name])->values();
            $data['products'] = collect(Issuer::products($categoryId))->map(fn ($name, $id) => ['id' => (string) $id, 'name' => $name])->values();
        }

        return response()->json(['data' => $data]);
    }

    // POST /api/admin/manual-lock-passwords/ttlock-issue
    // { category_id, lock_ids[], product_ids[]?, name, from_date, to_date, from_time?, until_time?,
    //   per_day?, room_same_as_gate?, is_active? }
    public function ttlockIssue(Request $request): JsonResponse
    {
        $user = $this->user($request);

        if (! $user->can('create', ManualLockPassword::class)) {
            return response()->json(['message' => 'Không có quyền cấp mã khóa thủ công.'], 403);
        }

        $data = $request->validate([
            'category_id'       => 'required|integer',
            'lock_ids'          => 'required|array|min:1',
            'lock_ids.*'        => 'integer',
            'product_ids'       => 'nullable|array',
            'product_ids.*'     => 'string',
            'name'              => 'required|string|max:200',
            'from_date'         => 'required|date_format:Y-m-d',
            'to_date'           => 'required|date_format:Y-m-d|after_or_equal:from_date',
            'from_time'         => 'nullable|date_format:H:i',
            'until_time'        => 'nullable|date_format:H:i',
            'per_day'           => 'nullable|boolean',
            'room_same_as_gate' => 'nullable|boolean',
            'is_active'         => 'nullable|boolean',
        ]);

        if (Carbon::parse($data['from_date'])->diffInDays(Carbon::parse($data['to_date'])) >= Issuer::MAX_DAYS) {
            return response()->json(['message' => 'Tối đa ' . Issuer::MAX_DAYS . ' ngày mỗi lần.', 'errors' => ['to_date' => ['Tối đa ' . Issuer::MAX_DAYS . ' ngày mỗi lần.']]], 422);
        }

        $data = [
            ...$data,
            'from_time'         => $data['from_time'] ?? '06:00',
            'until_time'        => $data['until_time'] ?? '12:00',
            'per_day'           => $data['per_day'] ?? true,
            'room_same_as_gate' => $data['room_same_as_gate'] ?? true,
            'is_active'         => $data['is_active'] ?? true,
        ];

        // Mỗi ngày 1-2 lần gọi TTLock đồng bộ — 31 ngày × nhiều khóa có thể vượt 30s mặc định.
        @set_time_limit(300);

        $result = Issuer::issue($user, $data);

        if ($result['message']) {
            return response()->json(['success' => false, 'message' => $result['message']], 422);
        }

        $created = collect($result['created'])
            ->map(fn (ManualLockPassword $r) => $this->toItem($r->load(['category:id,name,partner_id', 'category.partner:id,name', 'products:id,name'])))
            ->values();

        return response()->json([
            'success'  => $result['ok'],
            'message'  => $result['ok']
                ? 'Đã cấp ' . $created->count() . ' mã' . ($result['skipped'] ? ", bỏ qua {$result['skipped']} mã đã tồn tại" : '') . '.'
                : 'Không cấp được mã nào.',
            'data'     => $created,
            'skipped'  => $result['skipped'],
            'errors'   => $result['errors'],
            'warnings' => $result['warnings'],
        ], $result['ok'] ? 201 : 422);
    }

    private function toItem(ManualLockPassword $r): array
    {
        return [
            'id'            => $r->id,
            'name'          => $r->name,
            'gate_password' => $r->gate_password,
            'room_password' => $r->room_password,
            'notes'         => $r->notes,
            'valid_from'    => $r->valid_from?->toIso8601String(),
            'valid_until'   => $r->valid_until?->toIso8601String(),
            'is_active'     => (bool) $r->is_active,
            'status'        => $r->status_label,
            'status_color'  => $r->status_color,
            'branch'        => $r->category ? ['id' => $r->category->id, 'name' => $r->category->name] : null,
            'partner'       => $r->category?->partner ? ['id' => $r->category->partner->id, 'name' => $r->category->partner->name] : null,
            'rooms'         => $r->products->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values(),
            'rooms_count'   => $r->products->count(),
        ];
    }

    // Cùng điều kiện với bảng Khóa thủ công trong trang Khóa cổng (ManualLockPasswordTableWidget).
    private function canView(User $user): bool
    {
        return $user->can('viewAny', ManualLockPassword::class) || $user->can('page_GateLockManagement');
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
