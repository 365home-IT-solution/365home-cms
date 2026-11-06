<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

// Phục vụ các trang thử mini game. Quyền vào trang do middleware game.tester kiểm (bật ở CMS + phiên Super Admin).
class GameTesterPageController extends Controller
{
    private const ADMIN_TOKEN_NAME = 'game-tester-admin';

    private const ADMIN_TOKEN_HOURS = 8;

    // /game-tester — cấp sẵn token admin ngắn hạn cho Super Admin đang đăng nhập CMS (trang gọi /api/admin/* bằng Bearer), không cần đăng nhập lại trong trang.
    public function hub(Request $request): Response
    {
        /** @var User $user */
        $user = auth('web')->user();

        // Dọn token cũ của trang thử: hết hạn thì xoá, còn hạn giữ tối đa 5 cái (mỗi tab / mỗi lần tải trang tạo 1 token)
        $tokens = $user->tokens()->where('name', self::ADMIN_TOKEN_NAME);
        (clone $tokens)->where('expires_at', '<', now())->delete();
        $keep = (clone $tokens)->latest('id')->limit(4)->pluck('id');
        (clone $tokens)->whereNotIn('id', $keep)->delete();

        $boot = [
            'adminToken' => $user->createToken(self::ADMIN_TOKEN_NAME, ['*'], now()->addHours(self::ADMIN_TOKEN_HOURS))->plainTextToken,
            'adminName'  => $user->name ?: $user->email,
            'expiresAt'  => now()->addHours(self::ADMIN_TOKEN_HOURS)->toIso8601String(),
        ];

        return $this->html('game-tester.html', '<script>window.GT_BOOT = ' . json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . ';</script>');
    }

    // /tien-len-tester — trong trang vẫn đăng nhập tài khoản KHÁCH để chơi (API game dùng token khách).
    public function tienLen(): Response
    {
        return $this->html('tien-len.html');
    }

    private function html(string $file, string $boot = ''): Response
    {
        $html = file_get_contents(resource_path('dev/' . $file));
        if ($boot !== '') {
            $html = str_replace('<!--GT_BOOT-->', $boot, $html);
        }

        return response($html)->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
