<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // MIỄN PHÍ THÁNG ĐẦU cho đối tác Homestay mới ở ngoài các tỉnh bắt buộc ký quỹ ngay (mặc định chỉ Cần Thơ) — xem App\Services\PartnerTrialService.
    //  partners.province_code   : mã tỉnh/thành của cơ sở kinh doanh, lấy từ form đăng ký (trước đây chỉ ghép vào chuỗi địa chỉ).
    //  partners.fee_free_until  : hết thời gian miễn hoa hồng + miễn nạp ký quỹ (null = không có / không được miễn).
    //  orders.commission_waived : đơn tạo trong thời gian miễn phí — hoa hồng 0, khoản giảm do 365home phát hành do đối tác chịu.
    public function up(): void
    {
        if (! Schema::hasColumn('partners', 'province_code')) {
            Schema::table('partners', function (Blueprint $table) {
                $table->unsignedInteger('province_code')->nullable()->index()->after('address');
                $table->timestamp('fee_free_until')->nullable()->after('payment_flow_effective_at');
            });
        }

        if (! Schema::hasColumn('orders', 'commission_waived')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->boolean('commission_waived')->default(false)->after('commission_rate');
            });
        }

        $this->backfillProvince();
    }

    // Điền mã tỉnh cho đối tác cũ theo phần cuối của chuỗi địa chỉ (đối tác đăng ký qua form cũ chỉ có chuỗi). Không khớp thì để trống — Super Admin chọn tay ở hồ sơ.
    private function backfillProvince(): void
    {
        if (! Schema::hasTable('provinces') || ! Schema::hasColumn('provinces', 'code')) {
            return;
        }

        $normalize = fn (string $s) => trim(preg_replace('/^(thanh pho|tp\.?|tinh)\s+/u', '', preg_replace('/\s+/', ' ', mb_strtolower(\Illuminate\Support\Str::ascii($s)))));
        $provinces = DB::table('provinces')->whereNotNull('code')->get(['code', 'name'])->map(fn ($p) => ['code' => (int) $p->code, 'key' => $normalize((string) $p->name)])->filter(fn ($p) => $p['key'] !== '');

        DB::table('partners')->whereNull('province_code')->whereNotNull('address')->orderBy('id')->each(function ($partner) use ($provinces, $normalize) {
            $parts = array_map('trim', explode(',', (string) $partner->address));
            $last = $normalize((string) end($parts));
            $match = $provinces->firstWhere('key', $last);

            if ($match) {
                DB::table('partners')->where('id', $partner->id)->update(['province_code' => $match['code']]);
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('partners', 'province_code')) {
            Schema::table('partners', function (Blueprint $table) {
                $table->dropColumn(['province_code', 'fee_free_until']);
            });
        }
        if (Schema::hasColumn('orders', 'commission_waived')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('commission_waived');
            });
        }
    }
};
