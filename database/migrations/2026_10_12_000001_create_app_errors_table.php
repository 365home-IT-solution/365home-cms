<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // LỖI APP TỰ BÁO (POST /api/app-error-reports): mỗi dòng là MỘT lỗi (gộp theo fingerprint), báo lại thì cộng dồn số lần chứ không thêm dòng — tách khỏi bảng
    // `notifications` để không làm loãng thông báo nghiệp vụ của admin. Xem App\Http\Controllers\Api\AppErrorReportController.
    public function up(): void
    {
        if (Schema::hasTable('app_errors')) {
            return;
        }

        Schema::create('app_errors', function (Blueprint $table) {
            $table->id();
            // sha1 của khoá gộp — api: method + endpoint + status; loại khác: kind + area + message
            $table->char('fingerprint', 40)->unique();
            $table->string('kind', 20)->index();
            // Mục nghiệp vụ để nhóm/lọc (orders, payments, rooms...) — BE tự suy từ endpoint/màn hình, xem App\Models\AppError::moduleFor()
            $table->string('module', 30)->default('other')->index();
            $table->string('area', 50)->nullable();
            $table->string('method', 10)->nullable();
            $table->text('endpoint')->nullable();
            $table->integer('status')->nullable();
            // Các cột dưới đây là của LẦN BÁO GẦN NHẤT
            $table->text('route')->nullable();
            $table->text('url')->nullable();
            $table->text('message')->nullable();
            $table->text('detail')->nullable();
            $table->text('reporter')->nullable();
            $table->json('app')->nullable();
            $table->unsignedBigInteger('occurrences')->default(0);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('resolved_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_errors');
    }
};
