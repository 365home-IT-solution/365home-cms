<?php

namespace Tests\Feature\Minihouse;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Category\Entities\Category;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\TtlockSetting;
use Modules\Minihouse\App\Support\HomestayBridge;
use Modules\TTLock\App\Services\TTLockService;
use Tests\TestCase;

// TTLock THEO TỪNG TOÀ NHÀ — xem TtlockSetting/TTLockService::forBuilding() và 3 controller
// Ttlock*/RoomLock* ở App\Http\Controllers\Api\Admin\Minihouse. HTTP ra TTLock được fake toàn bộ.
class TtlockApiTest extends TestCase
{
    use DatabaseTransactions;

    private function makeBuilding(): Category
    {
        $name = 'Toa nha ' . uniqid();

        return Category::create([
            'name'          => $name,
            'slug'          => Str::slug($name) . '-' . uniqid(),
            'category_type' => 'product',
            'parent_id'     => null,
            'partner_id'    => HomestayBridge::PARTNER_ID,
            'status'        => true,
        ]);
    }

    private function h(?User $user = null): array
    {
        $user ??= User::role('super_admin')->first();

        // Sanctum cache người dùng đã xác thực giữa các request trong CÙNG 1 test — quên guard để mỗi request
        // thật sự đi theo token của user được truyền vào.
        app('auth')->forgetGuards();

        return ['Authorization' => 'Bearer ' . $user->createToken('t')->plainTextToken];
    }

    private function fakeTtlock(): void
    {
        Cache::flush();
        Http::fake([
            '*/oauth2/token'   => Http::response(['access_token' => 'tok', 'refresh_token' => 'ref', 'expires_in' => 7200]),
            '*/v3/lock/listKeyboardPwd*' => Http::response(['list' => [['keyboardPwdId' => 77, 'keyboardPwd' => '123456']], 'pages' => 1]),
            '*/v3/lock/list*'  => Http::response(['list' => [['lockId' => 111, 'lockAlias' => 'Cua chinh', 'lockMac' => 'AA:BB', 'electricQuantity' => 90]], 'pages' => 1]),
            '*/v3/lock/unlock' => Http::response(['errcode' => 0]),
            '*/v3/keyboardPwd/get' => Http::response(['keyboardPwd' => '123456', 'keyboardPwdId' => 77]),
            '*/v3/keyboardPwd/delete' => Http::response(['errcode' => 0]),
        ]);
    }

    private function configure(Category $building): void
    {
        $this->putJson('/api/admin/minihouse/ttlock-settings', [
            'building_id'   => $building->id,
            'client_id'     => 'cid-' . $building->id,
            'client_secret' => 'secret-' . $building->id,
            'username'      => 'user@test.local',
            'password'      => 'matkhau123',
        ], $this->h())->assertOk();
    }

    public function test_settings_are_per_building_secrets_hidden_and_password_hashed(): void
    {
        $a = $this->makeBuilding();
        $b = $this->makeBuilding();

        $this->configure($a);

        $show = $this->getJson("/api/admin/minihouse/ttlock-settings?building_id={$a->id}", $this->h())
            ->assertOk()
            ->assertJsonPath('data.is_configured', true)
            ->assertJsonPath('data.has_client_secret', true)
            ->assertJsonMissingPath('data.client_secret')
            ->assertJsonMissingPath('data.password_md5');

        $this->assertSame('cid-' . $a->id, $show->json('data.client_id'));
        $this->assertSame(md5('matkhau123'), TtlockSetting::find($a->id)->password_md5);

        // Toà B chưa cấu hình — không bị ảnh hưởng.
        $this->getJson("/api/admin/minihouse/ttlock-settings?building_id={$b->id}", $this->h())
            ->assertOk()->assertJsonPath('data.is_configured', false);

        // Bỏ field bí mật = giữ nguyên.
        $this->putJson('/api/admin/minihouse/ttlock-settings', ['building_id' => $a->id, 'username' => 'moi@test.local'], $this->h())->assertOk();
        $this->assertSame(md5('matkhau123'), TtlockSetting::find($a->id)->password_md5);
        $this->assertSame('moi@test.local', TtlockSetting::find($a->id)->username);

        // Lần đầu thiếu field bắt buộc -> 422.
        $this->putJson('/api/admin/minihouse/ttlock-settings', ['building_id' => $b->id, 'client_id' => 'x'], $this->h())->assertStatus(422);

        // Giá trị lưu ở DB là mã hoá, không phải thô.
        $raw = \DB::table('minihouse_ttlock_settings')->where('building_id', $a->id)->value('client_secret');
        $this->assertNotSame('secret-' . $a->id, $raw);

        $this->deleteJson("/api/admin/minihouse/ttlock-settings?building_id={$a->id}", [], $this->h())->assertOk();
        $this->assertNull(TtlockSetting::find($a->id));
    }

    public function test_locks_list_unlock_and_passcode_use_the_buildings_own_account(): void
    {
        $this->fakeTtlock();
        $a = $this->makeBuilding();
        $b = $this->makeBuilding();
        $this->configure($a);

        $this->postJson('/api/admin/minihouse/ttlock-settings/test', ['building_id' => $a->id], $this->h())
            ->assertOk()->assertJsonPath('success', true);

        $this->getJson("/api/admin/minihouse/ttlock/locks?building_id={$a->id}", $this->h())
            ->assertOk()
            ->assertJsonPath('data.0.lock_id', 111)
            ->assertJsonPath('data.0.building_id', $a->id);

        $this->postJson('/api/admin/minihouse/ttlock/locks/unlock', ['building_id' => $a->id, 'lock_id' => 111], $this->h())
            ->assertOk()->assertJsonPath('success', true);

        $this->postJson('/api/admin/minihouse/ttlock/passcodes', [
            'building_id' => $a->id, 'lock_id' => 111, 'name' => 'Ma khach', 'start_date' => now()->getTimestampMs(),
        ], $this->h())->assertStatus(201)->assertJsonPath('data.code', '123456');

        $this->getJson("/api/admin/minihouse/ttlock/passcodes?building_id={$a->id}&lock_id=111", $this->h())
            ->assertOk()->assertJsonPath('data.0.keyboardPwdId', 77);

        $this->deleteJson('/api/admin/minihouse/ttlock/passcodes', ['building_id' => $a->id, 'lock_id' => 111, 'keyboard_pwd_id' => 77], $this->h())
            ->assertOk();

        // Toà B chưa cấu hình TTLock: không dùng được tài khoản của toà A.
        $this->postJson('/api/admin/minihouse/ttlock/locks/unlock', ['building_id' => $b->id, 'lock_id' => 111], $this->h())
            ->assertStatus(422);
        $this->assertNull(TTLockService::forBuilding($b->id));
        $this->assertNotNull(TTLockService::forBuilding($a->id));
    }

    public function test_user_cannot_touch_ttlock_of_a_building_outside_permission(): void
    {
        $this->fakeTtlock();
        $allowed = $this->makeBuilding();
        $other   = $this->makeBuilding();
        $this->configure($other);

        $manager = User::role('super_admin')->first()->replicate();
        $manager->email    = 'mh-ttlock-' . uniqid() . '@test.local';
        $manager->password = bcrypt('secret');
        $manager->save();
        $manager->syncRoles([]);
        $manager->givePermissionTo(['page_ttlock_locks', 'page_manage_ttlock_settings']);
        $manager->minihouseBuildings()->attach($allowed->id);

        $this->postJson('/api/admin/minihouse/ttlock/locks/unlock', ['building_id' => $other->id, 'lock_id' => 111], $this->h($manager))
            ->assertStatus(403);
        $this->getJson("/api/admin/minihouse/ttlock-settings?building_id={$other->id}", $this->h($manager))
            ->assertStatus(422);

        // Không có quyền page_ttlock_locks -> 403.
        $noPerm = $manager->replicate();
        $noPerm->email = 'mh-ttlock-np-' . uniqid() . '@test.local';
        $noPerm->password = bcrypt('secret');
        $noPerm->save();
        $noPerm->syncRoles([]);
        $noPerm->minihouseBuildings()->attach($allowed->id);

        $this->getJson('/api/admin/minihouse/ttlock/locks', $this->h($noPerm))->assertStatus(403);
    }

    public function test_assign_lock_to_room_and_unlock_room(): void
    {
        $this->fakeTtlock();
        $building = $this->makeBuilding();
        $this->configure($building);

        $room = Room::create(['building_id' => $building->id, 'code' => 'K' . random_int(100, 999), 'status' => Room::STATUS_EMPTY]);

        // Mở khi chưa gán khoá -> 422.
        $this->postJson("/api/admin/minihouse/rooms/{$room->id}/unlock", [], $this->h())->assertStatus(422);

        // Gán khoá KHÔNG thuộc tài khoản của toà -> 422.
        $this->putJson("/api/admin/minihouse/rooms/{$room->id}/lock", ['lock_id' => 999], $this->h())->assertStatus(422);

        $this->putJson("/api/admin/minihouse/rooms/{$room->id}/lock", ['lock_id' => 111], $this->h())
            ->assertOk()->assertJsonPath('data.lock_id', 111);

        $this->getJson("/api/admin/minihouse/rooms/{$room->id}/lock", $this->h())
            ->assertOk()->assertJsonPath('data.lock_id', 111);

        $this->postJson("/api/admin/minihouse/rooms/{$room->id}/unlock", [], $this->h())
            ->assertOk()->assertJsonPath('success', true);
    }
    public function test_filament_pages_render_and_are_scoped_to_configured_buildings(): void
    {
        $this->fakeTtlock();
        $admin = User::role('super_admin')->first();
        $building = $this->makeBuilding();
        $this->configure($building);
        $room = Room::create(['building_id' => $building->id, 'code' => 'F' . random_int(100, 999), 'status' => Room::STATUS_EMPTY]);
        app('auth')->forgetGuards();
        auth()->shouldUse('web');
        $this->actingAs($admin, 'web');

        $base = '/minihouse/admin';
        $this->get("{$base}/manage-ttlock-settings")->assertOk()->assertSee('Cấu hình TTLock');
        $this->get("{$base}/ttlock/dashboard")->assertOk()->assertSee('Cua chinh');
        $this->get("{$base}/ttlock/locks/{$building->id}/111")->assertOk();
        foreach (['issue-passcode', 'issue-card', 'issue-fingerprint'] as $page) {
            $this->get("{$base}/ttlock/{$page}")->assertOk();
        }
        $this->get("{$base}/rooms")->assertOk();
    }
}