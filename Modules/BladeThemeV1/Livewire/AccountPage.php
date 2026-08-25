<?php

namespace Modules\BladeThemeV1\Livewire;

use App\Models\Customer;
use App\Models\MembershipTier;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Component;
use Modules\Category\Entities\Category;
use Modules\Payment\Entities\Order;

class AccountPage extends Component
{
    // Thông tin user
    public bool   $isLoggedIn = false;
    public bool   $isLoading  = true;
    public string $fullname   = '';
    public string $phone      = '';
    public string $dob        = '';

    // Stats tổng (không bị ảnh hưởng bởi filter chi nhánh)
    public int $totalOrders   = 0;
    public int $paidOrders    = 0;
    public int $pendingOrders = 0;

    // CCCD status
    public ?string $cccdFrontUrl = null;
    public ?string $cccdBackUrl  = null;
    public bool    $hasCccd      = false;

    // Hạng thành viên (customers.membership_tier_id)
    public ?string $membershipTierName    = null;
    public ?string $membershipTierColor   = null;
    public ?string $membershipTierCardImg = null; // membership/{slug}.png (thẻ + huy hiệu)
    public ?string $membershipTierBgImg   = null; // membership/background/{slug}.png (texture nền)
    public string  $discountIconUrl       = '';   // membership/icons/vourcher.png
    public string  $coinIconUrl           = '';   // membership/icons/coin.png
    public string  $staffIconUrl          = '';   // membership/icons/staff.png

    // Tiến độ lên hạng
    public float   $totalSpending        = 0;
    public float   $tierProgressPercent  = 0;    // 0-100
    public ?string $nextTierName         = null;
    public ?float  $amountToNextTier     = null; // null = đã đạt hạng cao nhất

    // Đơn của trang hiện tại
    public array $orders = [];

    // Voucher của khách hàng
    public array $discountCodes = [];

    // Pagination
    public ?string $customerId = null; // UUID của customer đã xác thực
    public string $phone84     = '';   // dạng 84xxx để query
    public int $currentPage  = 1;
    public int $perPage      = 5;
    public int $totalCount   = 0;   // tổng số đơn theo filter hiện tại (cho pagination)

    // Filter chi nhánh
    public array $branches        = [];
    public ?int  $selectedBranchId = null;

    // Màu
    public string $primaryHex    = '#FBCB1C';
    public string $textOnPrimary = '#1a1e25';

    public function mount(): void
    {
        $settings = new GeneralSettings();

        if (! (bool) ($settings->auth_header['enabled'] ?? false)) {
            $this->redirect('/', navigate: true);
            return;
        }

        $this->primaryHex = $settings->site_theme['primary'] ?? '#FBCB1C';

        $hex       = ltrim($this->primaryHex, '#');
        $luminance = (0.299 * hexdec(substr($hex, 0, 2))
                    + 0.587 * hexdec(substr($hex, 2, 2))
                    + 0.114 * hexdec(substr($hex, 4, 2))) / 255;
        $this->textOnPrimary = $luminance > 0.5 ? '#1a1e25' : '#ffffff';

        $this->discountIconUrl = Storage::disk('public')->exists('membership/icons/vourcher.png')
            ? Storage::disk('public')->url('membership/icons/vourcher.png')
            : '';
        $this->coinIconUrl = Storage::disk('public')->exists('membership/icons/coin.png')
            ? Storage::disk('public')->url('membership/icons/coin.png')
            : '';
        $this->staffIconUrl = Storage::disk('public')->exists('membership/icons/staff.png')
            ? Storage::disk('public')->url('membership/icons/staff.png')
            : '';
    }

    // ── Xác thực token, load profile + stats + trang đầu ──────────────────────
    public function loadUserOrders(string $token): void
    {
        if (empty($token)) {
            $this->resetUserState();
            $this->isLoading = false;
            return;
        }

        $this->isLoading = true;

        // ── Bước 1: Xác thực token (nếu lỗi → force logout) ──────────────────
        try {
            $pat = PersonalAccessToken::findToken($token);
        } catch (\Throwable) {
            $this->resetUserState();
            $this->isLoading = false;
            $this->dispatch('auth-force-logout');
            return;
        }

        if (!$pat || $pat->tokenable_type !== Customer::class) {
            $this->resetUserState();
            $this->isLoading = false;
            $this->dispatch('auth-force-logout');
            return;
        }

        /** @var Customer $customer */
        $customer = $pat->tokenable;
        if (!$customer || $customer->status !== Customer::STATUS_ACTIVE) {
            $this->resetUserState();
            $this->isLoading = false;
            $this->dispatch('auth-force-logout');
            return;
        }

        // ── Bước 2: Điền thông tin customer (auth đã xác nhận) ────────────────
        $this->isLoggedIn  = true;
        $this->customerId  = $customer->id;
        $this->fullname    = $customer->fullname ?? 'Khách hàng';
        $this->dob         = $customer->date_of_birth?->format('d-m-Y') ?? '';

        $raw = $customer->phone ?? '';
        if (str_starts_with($raw, '84') && strlen($raw) >= 11) {
            $raw = '0' . substr($raw, 2);
        }
        $this->phone   = $raw;
        $this->phone84 = '84' . substr($this->phone, 1);

        $this->cccdFrontUrl = $customer->cccd_front
            ? Storage::disk('public')->url($customer->cccd_front)
            : null;
        $this->cccdBackUrl = $customer->cccd_back
            ? Storage::disk('public')->url($customer->cccd_back)
            : null;
        $this->hasCccd = !empty($customer->cccd_front) && !empty($customer->cccd_back);

        $tier                       = $customer->membershipTier;
        $this->membershipTierName  = $tier?->name;
        $this->membershipTierColor = $tier?->color;

        if ($tier?->slug) {
            $bgPath  = "membership/background/{$tier->slug}.png";
            $version = $customer->updated_at?->timestamp ?? time();

            // Ưu tiên ảnh upload qua admin (cột membership_tiers.image) — fallback về quy ước file
            // cũ membership/{slug}.png cho hạng chưa upload ảnh mới.
            if ($tier->image) {
                $this->membershipTierCardImg = Storage::disk('public')->url($tier->image) . '?v=' . $version;
            } else {
                $cardPath = "membership/{$tier->slug}.png";
                $this->membershipTierCardImg = Storage::disk('public')->exists($cardPath)
                    ? Storage::disk('public')->url($cardPath) . '?v=' . $version
                    : null;
            }

            $this->membershipTierBgImg = Storage::disk('public')->exists($bgPath)
                ? Storage::disk('public')->url($bgPath) . '?v=' . $version
                : null;
        }

        $this->totalSpending = (float) ($customer->total_spending ?? 0);

        $nextTier = MembershipTier::where('is_active', true)
            ->where('min_spending', '>', (float) ($tier?->min_spending ?? 0))
            ->orderBy('min_spending')
            ->first();

        if ($nextTier) {
            $floor    = (float) ($tier?->min_spending ?? 0);
            $ceil     = (float) $nextTier->min_spending;
            $range    = $ceil - $floor;
            $progress = $range > 0 ? (($this->totalSpending - $floor) / $range) * 100 : 100;

            $this->nextTierName        = $nextTier->name;
            $this->amountToNextTier    = max(0, $ceil - $this->totalSpending);
            $this->tierProgressPercent = max(0, min(100, $progress));
        } else {
            $this->nextTierName        = null;
            $this->amountToNextTier    = null;
            $this->tierProgressPercent = 100;
        }

        // ── Bước 3: Load đơn hàng + voucher (nếu lỗi → giữ isLoggedIn = true, dữ liệu rỗng) ──
        try {
            $this->loadDiscountCodes($customer);

            $phoneQ = $this->phoneQuery();

            // Stats tổng (không filter chi nhánh)
            $this->totalOrders   = $phoneQ->count();
            $this->paidOrders    = (clone $phoneQ)->where('status', 'paid')->count();
            $this->pendingOrders = (clone $phoneQ)->whereIn('status', ['pending', 'deposit'])->count();

            // Danh sách chi nhánh có trong đơn của user
            $branchIds = (clone $phoneQ)->whereNotNull('category_id')->pluck('category_id')->unique();
            $this->branches = Category::whereIn('id', $branchIds)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->toArray();

            // Load trang đầu (không filter chi nhánh)
            $this->selectedBranchId = null;
            $this->currentPage      = 1;
            $this->totalCount       = $this->totalOrders;
            $this->fetchPage();
        } catch (\Throwable) {
            $this->orders = [];
        } finally {
            $this->isLoading = false;
        }
    }

    // ── Load mã giảm giá của khách hàng (gán riêng qua customer_id + qua bảng coupon_customers) ──
    private function loadDiscountCodes(Customer $customer): void
    {
        $now = now();

        $personal = $customer->personalCoupons()->get();
        $assigned = $customer->coupons()->get();

        $this->discountCodes = $personal->merge($assigned)
            ->unique('id')
            ->sortByDesc(fn ($c) => $c->end_at ?? $c->created_at)
            ->map(function ($coupon) use ($now) {
                if (!$coupon->is_active) {
                    $status = ['label' => 'Đã tắt', 'class' => 'bg-gray-100 text-gray-500 border-gray-200'];
                } elseif ($coupon->start_at && $now->lt($coupon->start_at)) {
                    $status = ['label' => 'Sắp diễn ra', 'class' => 'bg-blue-50 text-blue-600 border-blue-200'];
                } elseif ($coupon->end_at && $now->gt($coupon->end_at)) {
                    $status = ['label' => 'Hết hạn', 'class' => 'bg-gray-100 text-gray-500 border-gray-200'];
                } elseif ($coupon->usage_limit && $coupon->used_count >= $coupon->usage_limit) {
                    $status = ['label' => 'Đã hết lượt', 'class' => 'bg-gray-100 text-gray-500 border-gray-200'];
                } else {
                    $status = ['label' => 'Có thể dùng', 'class' => 'bg-emerald-50 text-emerald-600 border-emerald-200'];
                }

                return [
                    'id'              => $coupon->id,
                    'code'            => $coupon->code,
                    'name'            => $coupon->name,
                    'description'     => $coupon->description,
                    'value_label'     => $coupon->type === 'percentage'
                        ? rtrim(rtrim(number_format((float) $coupon->value, 2), '0'), '.') . '%'
                        : number_format((float) $coupon->value, 0, ',', '.') . 'đ',
                    'min_order_value' => $coupon->min_order_value,
                    'end_at'          => $coupon->end_at?->format('d/m/Y'),
                    'status'          => $status,
                ];
            })
            ->values()
            ->toArray();
    }

    // ── Chuyển trang ──────────────────────────────────────────────────────────
    public function goToPage(int $page): void
    {
        $total = (int) ceil($this->totalCount / $this->perPage);
        if ($page < 1 || $page > max(1, $total)) {
            return;
        }
        $this->currentPage = $page;
        $this->fetchPage();
    }

    // ── Lọc theo chi nhánh ──────────────────────────────────────────────────
    public function filterBranch(?int $id): void
    {
        $this->selectedBranchId = $id;
        $this->currentPage      = 1;
        $base                   = $this->baseQuery();
        $this->totalCount       = $base->count();
        $this->fetchPage();
    }

    // ── Query theo SĐT và/hoặc customer_id ───────────────────────────────────
    private function phoneQuery()
    {
        $phone      = $this->phone;
        $phone84    = $this->phone84;
        $customerId = $this->customerId;

        return Order::where(function ($q) use ($phone, $phone84, $customerId) {
            $q->where('buyer_phone', $phone)
              ->orWhere('buyer_phone', $phone84);
            if ($customerId) {
                $q->orWhere('user_id', $customerId);
            }
        });
    }

    // ── Query có thêm filter chi nhánh (dùng cho list + pagination) ──────────
    private function baseQuery()
    {
        $q = $this->phoneQuery();

        if ($this->selectedBranchId) {
            $q->where('category_id', $this->selectedBranchId);
        }

        return $q;
    }

    // ── Load đơn của trang hiện tại ──────────────────────────────────────────
    private function fetchPage(): void
    {
        $rows = $this->baseQuery()
            ->with(['items.product'])
            ->orderByDesc('created_at')
            ->offset(($this->currentPage - 1) * $this->perPage)
            ->limit($this->perPage)
            ->get();

        $this->orders = $rows->map(function ($order) {
            $item      = $order->items->first();
            $product   = $item?->product;
            $thumbnail = null;

            if ($product && $product->hasMedia('Ảnh bìa')) {
                $thumbnail = $product->getFirstMediaUrl('Ảnh bìa');
            }

            return [
                'id'            => $order->id,
                'order_code'    => $order->order_code,
                'amount'        => (int) $order->amount,
                'status'        => $order->status ?? 'pending',
                'room_name'     => $item?->name ?? 'Phòng',
                'checkin_date'  => $item?->checkin_date?->format('d/m/Y H:i'),
                'checkout_date' => $item?->checkout_date?->format('d/m/Y H:i'),
                'thumbnail'     => $thumbnail,
                'created_at'    => $order->created_at?->format('d/m/Y'),
            ];
        })->toArray();
    }

    public function totalPages(): int
    {
        return (int) ceil($this->totalCount / $this->perPage) ?: 1;
    }

    private function resetUserState(): void
    {
        $this->isLoggedIn        = false;
        $this->customerId        = null;
        $this->orders            = [];
        $this->fullname          = '';
        $this->phone             = '';
        $this->phone84           = '';
        $this->dob               = '';
        $this->cccdFrontUrl      = null;
        $this->cccdBackUrl       = null;
        $this->hasCccd           = false;
        $this->membershipTierName    = null;
        $this->membershipTierColor   = null;
        $this->membershipTierCardImg = null;
        $this->membershipTierBgImg   = null;
        $this->totalSpending         = 0;
        $this->tierProgressPercent   = 0;
        $this->nextTierName          = null;
        $this->amountToNextTier      = null;
        $this->discountCodes     = [];
        $this->totalOrders       = 0;
        $this->paidOrders        = 0;
        $this->pendingOrders     = 0;
        $this->totalCount        = 0;
        $this->currentPage       = 1;
        $this->branches          = [];
        $this->selectedBranchId  = null;
    }

    public function render(): View
    {
        return view('bladethemev1::livewire.account-page', [
            'totalPages' => $this->totalPages(),
        ]);
    }
}
