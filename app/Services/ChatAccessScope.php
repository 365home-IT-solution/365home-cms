<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Modules\Payment\Entities\Order;

/**
 * Phạm vi tin nhắn khách hàng một tài khoản admin được xem — DÙNG CHUNG cho API admin
 * (Api\Admin\ChatController) và trang CMS "Tin nhắn" (Filament\Pages\CustomerChat), để 2 nơi không
 * lệch nhau.
 *
 * Gốc phân quyền là ĐƠN: super_admin (không chọn đối tác) không giới hạn (orders() trả null); tài
 * khoản khác chỉ đơn của đúng đối tác mình, thu hẹp thêm theo chi nhánh được gán
 * (User::allowedCategoryIds(), rỗng = mọi chi nhánh của đối tác). Từ đó:
 *  - hội thoại được xem: có khung chat của 1 đơn trong phạm vi, hoặc có tin hỗ trợ chung (order_id
 *    null) của khách từng đặt đơn trong phạm vi;
 *  - tin nhắn được xem: tin hỗ trợ chung + tin thuộc đơn trong phạm vi — 1 khách có thể đặt ở nhiều
 *    đối tác, không lộ nội dung trao đổi về đơn của đối tác/chi nhánh khác.
 */
class ChatAccessScope
{
    /**
     * Đơn trong phạm vi — null = không giới hạn. $requestedCategoryIds: lọc thêm về các chi nhánh này
     * (vẫn bị kẹp trong phạm vi quyền), null = không lọc thêm.
     *
     * @param  array<int>|null  $requestedCategoryIds
     */
    public function orders(User $user, ?array $requestedCategoryIds = null): ?Builder
    {
        $categoryIds = $this->categoryFilter($user, $requestedCategoryIds);

        if ($user->seesAllPartners() && $categoryIds === null) {
            return null;
        }

        $query = Order::query();

        if (! $user->seesAllPartners()) {
            empty($user->partner_id)
                ? $query->whereRaw('1 = 0')
                : $query->where('partner_id', $user->partner_id);
        }

        if ($categoryIds !== null) {
            // [] = phạm vi quyền/lọc thu hẹp về rỗng → không thấy gì, KHÁC null (không giới hạn).
            $query->whereIn('category_id', $categoryIds ?: [-1]);
        }

        return $query;
    }

    public function constrainConversations(Builder $query, ?Builder $orders): Builder
    {
        if ($orders === null) {
            return $query;
        }

        $orderIds    = (clone $orders)->select('id');
        $customerIds = (clone $orders)->whereNotNull('customer_id')->select('customer_id');

        return $query->where(function (Builder $q) use ($orderIds, $customerIds) {
            $q->whereHas('messages', fn ($m) => $m->whereIn('order_id', $orderIds))
                ->orWhere(fn (Builder $q2) => $q2
                    ->whereIn('customer_id', $customerIds)
                    ->whereHas('messages', fn ($m) => $m->whereNull('order_id')));
        });
    }

    /** Tin nhắn trong phạm vi: tin hỗ trợ chung + tin thuộc đơn trong phạm vi. */
    public function constrainMessages(Builder $query, ?Builder $orders): Builder
    {
        if ($orders === null) {
            return $query;
        }

        return $query->where(fn ($q) => $q->whereNull('order_id')->orWhereIn('order_id', (clone $orders)->select('id')));
    }

    public function findConversation(User $user, string $id, array $with = []): ?ChatConversation
    {
        return $this->constrainConversations(ChatConversation::with($with), $this->orders($user))->find($id);
    }

    public function orderInScope(?Builder $orders, ?Order $order): bool
    {
        return $order !== null && ($orders === null || (clone $orders)->whereKey($order->id)->exists());
    }

    /**
     * Đánh dấu đã đọc các tin của khách mà tài khoản này ĐƯỢC XEM (không đụng tin về đơn của đối tác khác —
     * họ vẫn phải thấy là chưa đọc), rồi đặt lại admin_unread = số tin khách còn chưa đọc của hội thoại.
     * $orderId: '__general__' = chỉ khung hỗ trợ chung, id đơn = chỉ khung của đơn đó, null = mọi tin trong phạm vi.
     *
     * @return bool true nếu có tin vừa được đánh dấu đã đọc
     */
    public function markRead(ChatConversation $conv, ?Builder $orders, ?string $orderId = null): bool
    {
        $query = ChatMessage::where('conversation_id', $conv->id)
            ->where('sender_type', 'customer')
            ->whereNull('read_at');

        $this->constrainMessages($query, $orders);

        if ($orderId === '__general__') {
            $query->whereNull('order_id');
        } elseif ($orderId !== null) {
            $query->where('order_id', $orderId);
        }

        $marked = $query->update(['read_at' => Carbon::now()]) > 0;

        $remaining = ChatMessage::where('conversation_id', $conv->id)
            ->where('sender_type', 'customer')
            ->whereNull('read_at')
            ->count();

        if ((int) $conv->admin_unread !== $remaining) {
            $conv->admin_unread = $remaining;
            $conv->save();
        }

        return $marked;
    }

    /**
     * Số tin khách chưa đọc + tin mới nhất TRONG PHẠM VI của từng hội thoại — tài khoản bị giới hạn không được
     * thấy số chưa đọc / đoạn xem trước của tin về đơn đối tác khác (admin_unread, last_message_preview trên
     * hội thoại là số liệu chung của mọi đối tác). null $orders = không giới hạn → dùng thẳng số liệu chung.
     *
     * @param  array<string>  $conversationIds
     * @return array<string, array{unread: int, preview: ?string, at: ?Carbon}>
     */
    public function summaries(array $conversationIds, ?Builder $orders): array
    {
        if ($orders === null || $conversationIds === []) {
            return [];
        }

        $unread = $this->constrainMessages(ChatMessage::query(), $orders)
            ->whereIn('conversation_id', $conversationIds)
            ->where('sender_type', 'customer')
            ->whereNull('read_at')
            ->selectRaw('conversation_id, COUNT(*) as cnt')
            ->groupBy('conversation_id')
            ->pluck('cnt', 'conversation_id');

        $latestIds = $this->constrainMessages(ChatMessage::query(), $orders)
            ->whereIn('conversation_id', $conversationIds)
            ->selectRaw('MAX(id) as id')
            ->groupBy('conversation_id')
            ->pluck('id');

        $latest = ChatMessage::whereIn('id', $latestIds)->get(['id', 'conversation_id', 'body', 'created_at'])->keyBy('conversation_id');

        $result = [];
        foreach ($conversationIds as $id) {
            $msg = $latest->get($id);
            $result[$id] = [
                'unread'  => (int) ($unread[$id] ?? 0),
                'preview' => $msg ? mb_substr((string) $msg->body, 0, 100) : null,
                'at'      => $msg?->created_at,
            ];
        }

        return $result;
    }

    /**
     * @param  array<int>|null  $requested
     * @return array<int>|null
     */
    private function categoryFilter(User $user, ?array $requested): ?array
    {
        if ($user->seesAllPartners()) {
            return $requested;
        }

        // [] = không bị gán quyền chi nhánh cụ thể → không giới hạn (cùng quy ước CouponResource/MembershipTierResource...).
        $allowed = $user->allowedCategoryIds();

        if ($requested !== null) {
            return empty($allowed) ? $requested : array_values(array_intersect($requested, $allowed));
        }

        return empty($allowed) ? null : $allowed;
    }
}
