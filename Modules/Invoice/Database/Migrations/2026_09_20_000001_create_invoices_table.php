<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SƯỜN (scaffold) cho tính năng xuất hoá đơn điện tử — CHƯA gọi bất kỳ API nhà cung cấp nào.
// 1 dòng ở bảng này LUÔN bắt đầu ở status=draft và CHỈ được xem là hoá đơn hợp lệ về pháp lý khi
// (ở giai đoạn sau, chưa làm) MisaInvoiceClient thực sự tạo+ký+phát hành thành công và ghi lại
// invoice_number/lookup_code do MISA trả về. Bản PDF tải từ trạng thái draft luôn có watermark
// "BẢN NHÁP" — xem Modules/Invoice/resources/views/pdf/invoice-draft.blade.php.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            // partners.id là UUID (không phải bigint) — xem database/migrations/2026_07_10_100000_create_partners_table.php.
            $table->uuid('partner_id')->nullable();
            $table->foreign('partner_id')->references('id')->on('partners')->nullOnDelete();
            // users.id cũng là UUID, giống partners.id.
            $table->uuid('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->string('status')->default('draft'); // draft|issued|cancelled|error
            $table->string('provider')->default('misa');

            // Thông tin ký hiệu/mẫu số — chỉ có giá trị THẬT sau khi đăng ký với MISA, để trống ở
            // giai đoạn sườn này (xem app/Settings/InvoiceSettings.php).
            $table->string('invoice_template_code')->nullable(); // Mẫu số
            $table->string('invoice_series')->nullable();        // Ký hiệu
            $table->string('invoice_number')->nullable();        // Số hoá đơn — MISA cấp sau khi phát hành
            $table->string('lookup_code')->nullable();           // Mã tra cứu hoá đơn
            $table->timestamp('issued_at')->nullable();

            $table->string('buyer_type')->default('individual'); // individual|company
            $table->string('buyer_name')->nullable();
            $table->string('buyer_tax_code')->nullable();
            $table->string('buyer_address')->nullable();
            $table->string('buyer_email')->nullable();
            $table->string('buyer_phone')->nullable();

            $table->unsignedBigInteger('subtotal_amount')->default(0);
            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->unsignedBigInteger('vat_amount')->default(0);
            $table->unsignedBigInteger('total_amount')->default(0);

            $table->text('note')->nullable();

            // Lưu nguyên văn request/response khi thực sự gọi API MISA (giai đoạn sau) — phục vụ
            // tra soát khi có tranh chấp/lỗi, KHÔNG dùng để hiển thị lại cho khách.
            $table->json('raw_request')->nullable();
            $table->json('raw_response')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
