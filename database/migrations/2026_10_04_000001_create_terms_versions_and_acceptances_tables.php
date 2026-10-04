<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Điều khoản dịch vụ có PHIÊN BẢN (chỉ thêm bản mới, không ghi đè bản cũ) + lịch sử khách đồng ý Điều khoản khi đăng ký.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terms_versions', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40)->index();            // partner_homestay | partner_minihouse
            $table->string('version', 20);                  // 1.0, 2.0, ...
            $table->string('title');
            $table->longText('content');
            $table->char('content_hash', 64);               // sha256(content) — đối chiếu toàn vẹn
            $table->dateTime('effective_at');               // bản hiệu lực = bản mới nhất có effective_at <= hiện tại
            $table->char('created_by', 36)->nullable();
            $table->timestamps();

            $table->unique(['type', 'version']);
        });

        Schema::create('terms_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('terms_version_id')->constrained('terms_versions')->restrictOnDelete();
            $table->string('type', 40)->index();
            $table->char('partner_id', 36)->nullable()->index();
            $table->boolean('accepted')->default(true);     // trạng thái đã tick đồng ý
            $table->dateTime('accepted_at');
            // Bản chụp phiên bản tại thời điểm đồng ý (đối chiếu nhanh, kể cả khi bản ghi gốc bị tác động ngoài hệ thống)
            $table->string('terms_version_label', 20);
            $table->char('terms_content_hash', 64);
            // Thông tin khách lúc đăng ký
            $table->string('full_name')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('business_name')->nullable();
            // Gói / giá / đơn
            $table->unsignedBigInteger('plan_id')->nullable();
            $table->string('plan_name')->nullable();
            $table->unsignedInteger('periods')->nullable();
            $table->unsignedBigInteger('amount_vnd')->nullable();
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->string('order_code', 40)->nullable()->index();      // mã đơn (PayOS)
            $table->string('transaction_ref', 80)->nullable()->index(); // mã giao dịch (nội dung chuyển khoản)
            $table->string('ip', 64)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('source', 30)->default('web');   // web | api
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('accepted_at');
        });

        // Bản khởi tạo (v1.0) để đăng ký có Điều khoản ngay — Super Admin rà soát/thay bằng nội dung chính thức bằng cách TẠO PHIÊN BẢN MỚI.
        $now = now();
        foreach ([
            'partner_homestay' => ['Điều khoản hợp tác dành cho đối tác Homestay', 'Homestay'],
            'partner_minihouse' => ['Điều khoản sử dụng dịch vụ MiniHouse', 'MiniHouse'],
        ] as $type => [$title, $label]) {
            $content = self::initialContent($label, $type === 'partner_minihouse');
            DB::table('terms_versions')->insert([
                'type' => $type, 'version' => '1.0', 'title' => $title, 'content' => $content,
                'content_hash' => hash('sha256', $content), 'effective_at' => $now, 'created_by' => null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('terms_acceptances');
        Schema::dropIfExists('terms_versions');
    }

    private static function initialContent(string $label, bool $minihouse): string
    {
        $money = $minihouse
            ? "3. Gói dịch vụ và thanh toán\nPhí sử dụng theo gói đã chọn khi đăng ký (số tháng, đơn giá hiển thị tại thời điểm đăng ký). Gói bắt đầu tính từ thời điểm kích hoạt tài khoản; trường hợp được tặng dùng thử thì thời gian dùng thử được thông báo khi duyệt đăng ký."
            : "3. Hoa hồng và thanh toán\nTỷ lệ hoa hồng, kỳ đối soát và phương thức thanh toán được thỏa thuận chi tiết trong Hợp đồng hợp tác kinh doanh ký giữa hai bên.";

        return "ĐIỀU KHOẢN DỊCH VỤ {$label} — 365 HOME\n\n"
            . "1. Phạm vi áp dụng\nĐiều khoản này áp dụng cho cá nhân/tổ chức đăng ký sử dụng dịch vụ {$label} do 365 Home cung cấp. Việc đánh dấu đồng ý khi đăng ký thể hiện bạn đã đọc, hiểu và chấp nhận toàn bộ nội dung dưới đây.\n\n"
            . "2. Thông tin đăng ký\nBạn cam kết thông tin cung cấp là chính xác, đầy đủ và chịu trách nhiệm về tính trung thực của thông tin; 365 Home có quyền từ chối hoặc tạm ngưng dịch vụ nếu phát hiện thông tin sai lệch.\n\n"
            . $money . "\n\n"
            . "4. Trách nhiệm của hai bên\n365 Home cung cấp nền tảng vận hành ổn định và hỗ trợ kỹ thuật trong phạm vi dịch vụ. Bạn chịu trách nhiệm về hoạt động kinh doanh của mình, về việc tuân thủ quy định pháp luật (bao gồm khai báo lưu trú, an ninh trật tự, phòng cháy chữa cháy) và về bảo mật tài khoản được cấp.\n\n"
            . "5. Dữ liệu cá nhân\n365 Home thu thập và xử lý dữ liệu cá nhân của bạn và của khách hàng của bạn chỉ để vận hành dịch vụ, tuân thủ quy định pháp luật về bảo vệ dữ liệu cá nhân.\n\n"
            . "6. Tạm ngưng và chấm dứt\nMỗi bên có quyền chấm dứt dịch vụ theo quy định; 365 Home có quyền tạm ngưng khi bạn vi phạm điều khoản hoặc quy định pháp luật.\n\n"
            . "7. Thay đổi điều khoản\nKhi Điều khoản được cập nhật, 365 Home ban hành PHIÊN BẢN MỚI. Bạn đăng ký ở thời điểm nào thì áp dụng đúng phiên bản Điều khoản mà bạn đã đồng ý ở thời điểm đó; các phiên bản cũ được lưu trữ để đối chiếu.\n";
    }
};
