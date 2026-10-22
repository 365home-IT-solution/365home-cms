<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // MINI GAME SƯU TẦM MẢNH GHÉP: đơn đã trả phòng ở chi nhánh X trong thời gian chiến dịch ⇒ khách nhận mảnh X; đủ pieces_required mảnh khác
    // nhau ⇒ bấm đổi thưởng nhận voucher cố định (không may rủi). Xem App\Services\CollectionService.
    public function up(): void
    {
        if (! Schema::hasTable('collection_campaigns')) {
            Schema::create('collection_campaigns', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->text('description')->nullable();
                $table->string('cover_image')->nullable();
                $table->timestamp('start_at');
                $table->timestamp('end_at');
                $table->timestamp('redeem_until');
                $table->unsignedSmallInteger('pieces_required');
                $table->string('reward_label');
                $table->string('coupon_type', 12)->default('fixed'); // fixed | percentage
                $table->decimal('coupon_value', 12, 2);
                $table->decimal('min_order_value', 12, 2)->nullable();
                $table->decimal('max_discount', 12, 2)->nullable();
                $table->unsignedSmallInteger('validity_days')->default(30);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('collection_pieces')) {
            Schema::create('collection_pieces', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('campaign_id');
                $table->unsignedBigInteger('branch_id'); // categories.id của chi nhánh gốc (cùng nguồn orders.category_id)
                $table->string('image')->nullable();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();

                $table->unique(['campaign_id', 'branch_id']);
            });
        }

        if (! Schema::hasTable('collection_customer_pieces')) {
            Schema::create('collection_customer_pieces', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('campaign_id');
                $table->char('customer_id', 36);
                $table->unsignedBigInteger('branch_id');
                $table->unsignedBigInteger('order_id')->nullable();
                $table->timestamp('collected_at');
                $table->timestamps();

                // Ở lại cùng chi nhánh lần nữa không được thêm mảnh
                $table->unique(['campaign_id', 'customer_id', 'branch_id'], 'collection_customer_piece_unique');
                $table->index('order_id');
            });
        }

        if (! Schema::hasTable('collection_redemptions')) {
            Schema::create('collection_redemptions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('campaign_id');
                $table->char('customer_id', 36);
                $table->unsignedBigInteger('coupon_id')->nullable();
                $table->timestamp('redeemed_at');
                $table->timestamps();

                // Mỗi khách đổi thưởng một lần / chiến dịch
                $table->unique(['campaign_id', 'customer_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_redemptions');
        Schema::dropIfExists('collection_customer_pieces');
        Schema::dropIfExists('collection_pieces');
        Schema::dropIfExists('collection_campaigns');
    }
};
