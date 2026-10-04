<?php

namespace Tests\Feature\Product;

use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Category\Entities\Category;
use Modules\Product\App\Support\ManualLockPasswordTtlockIssuer;
use Modules\TTLock\Entities\TtlockAccount;
use Tests\TestCase;

// "Cấp mã mở hàng loạt" khi TTLock từ chối tự sinh mã vì mã của đúng khung giờ đó đã sinh rồi bị xoá
// (errcode -1026 — LỖI THẬT 2026-10-04, production, khóa 2819156). HTTP ra TTLock được fake toàn bộ.
class ManualLockPasswordTtlockIssuerTest extends TestCase
{
    use DatabaseTransactions;

    private const LOCK = 555;

    private const DELETED = ['errcode' => -1026, 'errmsg' => 'Passcode with this validity period has been generated before and deleted.'];

    private User $admin;

    private int $categoryId;

    private function setUpBranch($passcodeGet): void
    {
        Http::fake([
            '*/oauth2/token'       => Http::response(['access_token' => 'tok', 'refresh_token' => 'ref', 'expires_in' => 7200]),
            '*/v3/lock/list*'      => Http::response(['list' => [['lockId' => self::LOCK, 'lockAlias' => 'Cong chinh']], 'pages' => 1]),
            '*/v3/keyboardPwd/get' => $passcodeGet,
            '*/v3/keyboardPwd/add' => Http::response(['keyboardPwdId' => 99]),
        ]);

        $this->admin = User::role('super_admin')->first();
        $this->assertNotNull($this->admin);

        $partner = Partner::create(['name' => 'Doi tac ' . uniqid(), 'status' => true]);
        $branch  = Category::create([
            'name'          => $name = 'Chi nhanh ' . uniqid(),
            'slug'          => Str::slug($name),
            'category_type' => 'product',
            'parent_id'     => null,
            'partner_id'    => $partner->id,
            'status'        => true,
        ]);

        TtlockAccount::create([
            'partner_id' => $partner->id, 'name' => 'TTLock test', 'client_id' => 'cid', 'client_secret' => 'secret',
            'username' => uniqid() . '@test.local', 'password_md5' => md5('x'), 'is_active' => true,
        ])->categories()->attach($branch->id);

        $this->categoryId = $branch->id;
    }

    private function issue(): array
    {
        return ManualLockPasswordTtlockIssuer::issue($this->admin, [
            'category_id' => $this->categoryId, 'lock_ids' => [self::LOCK], 'name' => 'Ma cong',
            'from_date' => '2026-10-04', 'to_date' => '2026-10-04', 'from_time' => '14:00', 'until_time' => '12:00',
            'per_day' => true,
        ]);
    }

    public function test_tries_the_next_hour_window_when_ttlock_says_the_code_was_deleted(): void
    {
        $this->setUpBranch(Http::sequence()
            ->push(self::DELETED)
            ->push(['keyboardPwd' => '481957', 'keyboardPwdId' => 77]));

        $result = $this->issue();

        $this->assertSame([], $result['errors']);
        $this->assertCount(1, $result['created']);
        $this->assertSame('481957#', $result['created'][0]->gate_password);
        $this->assertSame(
            [['lock_id' => self::LOCK, 'keyboard_pwd_id' => 77, 'earlier_h' => 0, 'later_h' => 1]],
            $result['created'][0]->ttlock_passcodes,
        );
    }

    public function test_falls_back_to_a_random_custom_code_when_every_window_was_deleted(): void
    {
        $this->setUpBranch(Http::response(self::DELETED));

        $result = $this->issue();

        $this->assertSame([], $result['errors']);
        $this->assertCount(1, $result['created']);
        $this->assertMatchesRegularExpression('/^\d{6}#$/', $result['created'][0]->gate_password);
        $this->assertSame(
            [['lock_id' => self::LOCK, 'keyboard_pwd_id' => 99, 'earlier_h' => 0, 'later_h' => 0]],
            $result['created'][0]->ttlock_passcodes,
        );
    }
}
