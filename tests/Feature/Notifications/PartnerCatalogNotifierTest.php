<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Services\PartnerCatalogNotifier;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\BladeThemeV1\App\Models\AdditionService;
use Tests\TestCase;

// Đối tác tạo phòng / chi nhánh / dịch vụ → Super Admin nhận thông báo (App\Services\PartnerCatalogNotifier).
class PartnerCatalogNotifierTest extends TestCase
{
    use DatabaseTransactions;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();

        $this->superAdmin = User::role(config('filament-shield.super_admin.name'))->firstOrFail();
    }

    private function partnerUser(): User
    {
        $user = User::query()
            ->whereNotNull('partner_id')
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', config('filament-shield.super_admin.name')))
            ->get()
            ->first(fn (User $u) => ! $u->belongsToPlatformPartner());

        if (! $user) {
            $this->markTestSkipped('Không có tài khoản đối tác thường trong DB.');
        }

        return $user;
    }

    private function catalogNotifications()
    {
        return DB::table('notifications')
            ->where('notifiable_id', $this->superAdmin->id)
            ->where('data', 'like', '%' . PartnerCatalogNotifier::TYPE . '%');
    }

    public function test_partner_creating_service_notifies_super_admin(): void
    {
        $user   = $this->partnerUser();
        $before = $this->catalogNotifications()->count();

        $this->actingAs($user);
        AdditionService::create(['partner_id' => $user->partner_id, 'name' => 'Test notify svc', 'price' => 10000, 'is_active' => true]);
        app(PartnerCatalogNotifier::class)->flush();

        $this->assertSame($before + 1, $this->catalogNotifications()->count());
        $this->assertStringContainsString('Test notify svc', (string) $this->catalogNotifications()->latest()->value('data'));
    }

    public function test_bulk_creation_is_grouped_into_one_notification(): void
    {
        $user   = $this->partnerUser();
        $before = $this->catalogNotifications()->count();

        $this->actingAs($user);
        foreach (['A', 'B', 'C'] as $name) {
            AdditionService::create(['partner_id' => $user->partner_id, 'name' => 'Bulk ' . $name, 'price' => 10000, 'is_active' => true]);
        }
        app(PartnerCatalogNotifier::class)->flush();

        $this->assertSame($before + 1, $this->catalogNotifications()->count());
        $data = json_decode((string) $this->catalogNotifications()->latest()->value('data'), true);
        $this->assertStringContainsString('3 dịch vụ đi kèm', $data['body']);
    }

    public function test_super_admin_creating_does_not_notify(): void
    {
        $partnerId = DB::table('partners')->value('id');
        $before    = $this->catalogNotifications()->count();

        $this->actingAs($this->superAdmin);
        AdditionService::create(['partner_id' => $partnerId, 'name' => 'By super admin', 'price' => 10000, 'is_active' => true]);
        app(PartnerCatalogNotifier::class)->flush();

        $this->assertSame($before, $this->catalogNotifications()->count());
    }
}
