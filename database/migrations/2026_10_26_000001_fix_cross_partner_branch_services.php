<?php

use App\Services\BranchServiceSync;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Dịch vụ đi kèm gắn chéo đối tác (dịch vụ của đối tác A nằm trong additional_service_branches
    // của chi nhánh đối tác B) → khách đặt phòng đối tác B thấy dịch vụ của đối tác A. Nguồn gốc:
    //  - dịch vụ cũ backfill về đối tác nền tảng "365Home" (2026_07_10_100014), sau đó nền tảng chuyển
    //    sang đối tác mới và "365Home" bị xoá mềm — dịch vụ vẫn mang partner_id cũ nhưng là dịch vụ
    //    thật của các chi nhánh nền tảng → CHUYỂN chủ sang đối tác nền tảng đang hoạt động;
    //  - super admin chọn nhầm chi nhánh đối tác khác ở form dịch vụ (nay đã chặn) → GỠ liên kết.
    // Sau đó đồng bộ lại phòng của các chi nhánh bị ảnh hưởng (room_additional_service_assigns).
    public function up(): void
    {
        $links = DB::table('additional_service_branches')
            ->join('additional_services', 'additional_services.id', '=', 'additional_service_branches.additional_service_id')
            ->join('categories', 'categories.id', '=', 'additional_service_branches.category_id')
            ->where(fn ($q) => $q->whereColumn('additional_services.partner_id', '!=', 'categories.partner_id')
                ->orWhereNull('additional_services.partner_id')
                ->orWhereNull('categories.partner_id'))
            ->get([
                'additional_service_branches.id',
                'additional_service_branches.additional_service_id as service_id',
                'additional_service_branches.category_id as branch_id',
                'additional_services.partner_id as service_partner_id',
                'categories.partner_id as branch_partner_id',
            ]);

        if ($links->isEmpty()) {
            return;
        }

        $partners = DB::table('partners')
            ->whereIn('id', $links->pluck('service_partner_id')->merge($links->pluck('branch_partner_id'))->filter()->unique())
            ->get(['id', 'is_platform_partner', 'deleted_at'])
            ->keyBy('id');

        $isActivePlatform = fn (?string $id) => $id && isset($partners[$id])
            && $partners[$id]->is_platform_partner && $partners[$id]->deleted_at === null;

        // Dịch vụ của đối tác nền tảng ĐÃ XOÁ, mọi liên kết chéo cùng trỏ về 1 đối tác nền tảng đang
        // hoạt động → chuyển chủ dịch vụ sang đối tác đó (giữ nguyên liên kết).
        $adopted = [];
        foreach ($links->groupBy('service_id') as $serviceId => $serviceLinks) {
            $owner = $partners[$serviceLinks->first()->service_partner_id] ?? null;
            if (! $owner || ! $owner->is_platform_partner || $owner->deleted_at === null) {
                continue;
            }

            $targets = $serviceLinks->pluck('branch_partner_id')->unique();
            if ($targets->count() === 1 && $isActivePlatform($targets->first())) {
                DB::table('additional_services')->where('id', $serviceId)->update([
                    'partner_id' => $targets->first(),
                    'updated_at' => now(),
                ]);
                $adopted[$serviceId] = true;
            }
        }

        $removeIds = $links->reject(fn ($l) => isset($adopted[$l->service_id]))->pluck('id');
        if ($removeIds->isNotEmpty()) {
            DB::table('additional_service_branches')->whereIn('id', $removeIds)->delete();
        }

        app(BranchServiceSync::class)->syncBranches($links->pluck('branch_id')->unique()->all());
    }

    public function down(): void
    {
        // Không hoàn tác — liên kết chéo đối tác là dữ liệu sai.
    }
};
