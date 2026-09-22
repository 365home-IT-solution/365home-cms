<?php

namespace Tests\Feature\Minihouse;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\ContractDocument;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\Tenant;
use Modules\Minihouse\App\Models\Zone;
use Tests\TestCase;

// Khoá lại đúng chuỗi hash trong docs/be-minihouse-contract-signing.md mục 3.2: khách ký lên hash
// bản niêm phong gốc (sealed_hash), chủ ký lên hash bản ĐÃ có chữ ký khách (final_hash sau bước 1),
// và trạng thái đi đúng draft -> awaiting_tenant -> awaiting_owner -> signed. Seed thẳng vào Cache
// theo ĐÚNG key TenantOtpService dùng (namespace 'contract_sign', tách khỏi OTP đăng nhập) thay vì
// gọi thật endpoint /otp — Zalo/SMS chưa cấu hình trong môi trường test nên không gửi được mã thật.
class ContractDocumentSigningFlowTest extends TestCase
{
    use DatabaseTransactions;

    public function test_full_sign_flow_produces_chained_hashes_and_locks_final_state(): void
    {
        Storage::fake('public');

        $zone = Zone::create(['name' => 'Z' . uniqid()]);
        $building = Building::create([
            'zone_id' => $zone->id, 'name' => 'B', 'address' => 'a',
            'owner_name' => 'Chủ Trọ', 'owner_id_card_number' => '079090001234', 'owner_address' => 'Địa chỉ chủ', 'owner_phone' => '0909000111',
        ]);
        $room = Room::create(['building_id' => $building->id, 'code' => 'R-' . uniqid(), 'price' => 3000000, 'status' => Room::STATUS_RENTED]);
        $phone = '09' . random_int(10000000, 99999999);
        $tenant = Tenant::create(['fullname' => 'Khách Thuê', 'phone' => $phone, 'id_card_number' => '079190005678']);

        $contract = Contract::create([
            'room_id' => $room->id, 'tenant_id' => $tenant->id,
            'start_date' => now()->subMonth(), 'end_date' => now()->addMonths(11),
            'monthly_price' => 3000000, 'deposit_amount' => 3000000,
            'status' => Contract::STATUS_ACTIVE,
        ]);

        $admin = User::role('super_admin')->first();
        $adminToken = $admin->createToken('t')->plainTextToken;
        $adminHeaders = ['Authorization' => 'Bearer ' . $adminToken];

        // 1) GET tự tạo draft, PATCH điền đủ field bắt buộc để gửi được.
        $this->withHeaders($adminHeaders)->getJson("/api/admin/minihouse/contracts/{$contract->id}/document")->assertOk();

        $this->withHeaders($adminHeaders)->patchJson("/api/admin/minihouse/contracts/{$contract->id}/document", [
            'sign_date' => now()->toDateString(),
            'payment_day' => 5,
            'max_occupants' => 2,
        ])->assertOk();

        // 2) send() — niêm phong, chuyển awaiting_tenant.
        $sendResponse = $this->withHeaders($adminHeaders)->postJson("/api/admin/minihouse/contracts/{$contract->id}/document/send")->assertOk();
        $sendResponse->assertJsonPath('data.status', ContractDocument::STATUS_AWAITING_TENANT);

        $doc = ContractDocument::where('contract_id', $contract->id)->firstOrFail();
        $this->assertNotNull($doc->sealed_hash);
        $this->assertSame(hash('sha256', Storage::disk('public')->get($doc->sealed_pdf_path)), $doc->sealed_hash);

        // 3) Khách ký — seed sẵn OTP vào Cache (namespace 'contract_sign', KHÁC OTP đăng nhập).
        Cache::put('minihouse_tenant_otp:contract_sign:' . $phone, [
            'code' => '123456', 'request_id' => 'otp_test', 'attempts' => 0,
            'expires_at' => now()->addMinutes(5)->timestamp, 'channel' => 'sms',
        ], now()->addMinutes(5));

        $tenantToken = $tenant->createToken('t')->plainTextToken;
        $tenantHeaders = ['Authorization' => 'Bearer ' . $tenantToken];

        $svg = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0"/></svg>');

        // Sanctum's guard instance memoizes the resolved user for this shared test container — mỗi
        // lần ĐỔI loại token (admin <-> tenant) trong CÙNG 1 test phải forgetGuards() trước, không
        // thì request sau vẫn "thấy" user đã resolve từ token trước (cùng cách PortalApiTest đang
        // làm) — 1 request thật luôn có container/guard mới nên đây chỉ là artefact của test.
        $this->app['auth']->forgetGuards();

        // Hash SAI phải bị chặn 409 trước khi thử hash đúng.
        $this->withHeaders($tenantHeaders)->postJson("/api/minihouse/portal/contracts/{$contract->id}/document/sign", [
            'signature' => $svg, 'otp_code' => '123456', 'otp_request_id' => 'otp_test',
            'document_hash' => str_repeat('0', 64), 'consent' => true, 'consent_text' => 'Đồng ý',
        ])->assertStatus(409);

        $tenantSignResponse = $this->withHeaders($tenantHeaders)->postJson("/api/minihouse/portal/contracts/{$contract->id}/document/sign", [
            'signature' => $svg, 'otp_code' => '123456', 'otp_request_id' => 'otp_test',
            'document_hash' => $doc->sealed_hash, 'consent' => true, 'consent_text' => 'Đồng ý',
        ])->assertOk();
        $tenantSignResponse->assertJsonPath('data.status', ContractDocument::STATUS_AWAITING_OWNER);

        $doc->refresh();
        $this->assertCount(1, $doc->signatures);
        $this->assertSame($doc->sealed_hash, $doc->signatures->first()->signed_document_hash);
        $hashAfterTenantSign = $doc->final_hash;
        $this->assertNotNull($hashAfterTenantSign);
        $this->assertNotSame($doc->sealed_hash, $hashAfterTenantSign);

        // 4) Chủ ký chốt — phải ký lên ĐÚNG hash bản đã có chữ ký khách (không phải sealed_hash cũ).
        $this->app['auth']->forgetGuards();

        $ownerSignResponse = $this->withHeaders($adminHeaders)->postJson("/api/admin/minihouse/contracts/{$contract->id}/document/sign", [
            'signature' => $svg, 'document_hash' => $hashAfterTenantSign, 'consent' => true, 'consent_text' => 'Đồng ý',
        ])->assertOk();
        $ownerSignResponse->assertJsonPath('data.status', ContractDocument::STATUS_SIGNED);

        $doc->refresh();
        $this->assertCount(2, $doc->signatures);
        $ownerSignature = $doc->signatures->firstWhere('party', 'owner');
        $this->assertSame($hashAfterTenantSign, $ownerSignature->signed_document_hash);
        $this->assertSame(hash('sha256', Storage::disk('public')->get($doc->final_pdf_path)), $doc->final_hash);

        // 5) Hợp đồng gốc kết thúc SAU KHI đã signed — bản ký KHÔNG được đụng vào (mục 6).
        $this->withHeaders($adminHeaders)->postJson("/api/admin/minihouse/contracts/{$contract->id}/checkout", [
            'checkout_at' => now()->toDateString(), 'deposit_refunded_amount' => 0,
        ])->assertOk();

        $doc->refresh();
        $this->assertSame(ContractDocument::STATUS_SIGNED, $doc->status);
    }

    public function test_recall_only_allowed_before_any_signature(): void
    {
        Storage::fake('public');

        $zone = Zone::create(['name' => 'Z' . uniqid()]);
        $building = Building::create([
            'zone_id' => $zone->id, 'name' => 'B', 'address' => 'a',
            'owner_name' => 'Chủ Trọ', 'owner_id_card_number' => '079090001234', 'owner_address' => 'Địa chỉ chủ', 'owner_phone' => '0909000111',
        ]);
        $room = Room::create(['building_id' => $building->id, 'code' => 'R-' . uniqid(), 'price' => 3000000, 'status' => Room::STATUS_RENTED]);
        $tenant = Tenant::create(['fullname' => 'Khách Thuê', 'phone' => '09' . random_int(10000000, 99999999), 'id_card_number' => '079190005678']);
        $contract = Contract::create([
            'room_id' => $room->id, 'tenant_id' => $tenant->id,
            'start_date' => now()->subMonth(), 'monthly_price' => 3000000, 'deposit_amount' => 3000000,
            'status' => Contract::STATUS_ACTIVE,
        ]);

        $admin = User::role('super_admin')->first();
        $headers = ['Authorization' => 'Bearer ' . $admin->createToken('t')->plainTextToken];

        $this->withHeaders($headers)->patchJson("/api/admin/minihouse/contracts/{$contract->id}/document", [
            'sign_date' => now()->toDateString(), 'payment_day' => 5, 'max_occupants' => 2,
        ])->assertOk();
        $this->withHeaders($headers)->postJson("/api/admin/minihouse/contracts/{$contract->id}/document/send")->assertOk();

        $this->withHeaders($headers)->postJson("/api/admin/minihouse/contracts/{$contract->id}/document/recall")->assertOk()
            ->assertJsonPath('data.status', ContractDocument::STATUS_DRAFT);

        $doc = ContractDocument::where('contract_id', $contract->id)->firstOrFail();
        $this->assertNull($doc->sealed_hash);

        // checkout khi document đang "draft" (chưa gửi lại) — cancelIfUnsigned() vẫn phải đổi cancelled.
        $this->withHeaders($headers)->postJson("/api/admin/minihouse/contracts/{$contract->id}/checkout", [
            'checkout_at' => now()->toDateString(), 'deposit_refunded_amount' => 0,
        ])->assertOk();

        $doc->refresh();
        $this->assertSame(ContractDocument::STATUS_CANCELLED, $doc->status);
    }
}
