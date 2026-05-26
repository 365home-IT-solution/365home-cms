<?php

namespace Modules\BladeThemeV1\Livewire;

use App\Models\User;
use App\Settings\GeneralSettings;
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

    // Đơn của trang hiện tại
    public array $orders = [];

    // Pagination
    public ?int $userId      = null; // lưu sau khi verify token, signed bởi Livewire
    public string $phone84   = '';   // dạng 84xxx để query
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

        if (!$pat || $pat->tokenable_type !== User::class) {
            $this->resetUserState();
            $this->isLoading = false;
            $this->dispatch('auth-force-logout');
            return;
        }

        $user = $pat->tokenable;
        if (!$user) {
            $this->resetUserState();
            $this->isLoading = false;
            $this->dispatch('auth-force-logout');
            return;
        }

        // ── Bước 2: Điền thông tin user (auth đã xác nhận) ────────────────
        $this->isLoggedIn = true;
        $this->userId     = (int) $user->id;
        $this->fullname   = $user->fullname ?? 'Khách hàng';
        $this->dob        = $user->date_of_birth?->format('d-m-Y') ?? '';

        $raw = $user->phone ?? '';
        if (str_starts_with($raw, '84') && \strlen($raw) >= 11) {
            $raw = '0' . substr($raw, 2);
        }
        $this->phone   = $raw;
        $this->phone84 = '84' . substr($this->phone, 1);

        // ── Bước 3: Load đơn hàng (nếu lỗi → giữ isLoggedIn = true, orders rỗng) ──
        try {
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

    // ── Query chỉ theo số điện thoại (dùng cho stats tổng) ───────────────────
    private function phoneQuery()
    {
        $phone   = $this->phone;
        $phone84 = $this->phone84;

        return Order::where(function ($q) use ($phone, $phone84) {
            $q->where('buyer_phone', $phone)
              ->orWhere('buyer_phone', $phone84);
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
        $this->isLoggedIn       = false;
        $this->userId           = null;
        $this->orders           = [];
        $this->fullname         = '';
        $this->phone            = '';
        $this->phone84          = '';
        $this->dob              = '';
        $this->totalOrders      = 0;
        $this->paidOrders       = 0;
        $this->pendingOrders    = 0;
        $this->totalCount       = 0;
        $this->currentPage      = 1;
        $this->branches         = [];
        $this->selectedBranchId = null;
    }

    public function render(): View
    {
        return view('bladethemev1::livewire.account-page', [
            'totalPages' => $this->totalPages(),
        ]);
    }
}
