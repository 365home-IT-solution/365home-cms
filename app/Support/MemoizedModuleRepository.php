<?php

namespace App\Support;

use Nwidart\Modules\Laravel\LaravelFileRepository;

// nwidart/laravel-modules (10.x) KHÔNG nhớ kết quả scan(): mỗi lần gọi module_path() /
// Module::find() / Module::allEnabled() là glob lại toàn bộ Modules/*/module.json, đọc + parse
// JSON từng file rồi dựng lại ~40 object Module. Riêng boot() của ~30 ModuleServiceProvider đã gọi
// module_path() 3-4 lần mỗi provider → hơn 100 lần quét đĩa cho MỌI request (kể cả /robots.txt
// hay trang 404), chiếm phần lớn thời gian phản hồi của server (SEO audit flag "slow page load
// speed"). Danh sách module không đổi trong vòng đời 1 request HTTP nên quét 1 lần là đủ.
//
// Chỉ nhớ khi phục vụ HTTP: lệnh artisan (module:make, module:delete...) tạo/xoá module giữa
// chừng rồi đọc lại danh sách trong cùng process, cần scan() thật như cũ.
class MemoizedModuleRepository extends LaravelFileRepository
{
    protected ?array $scanned = null;

    public function scan()
    {
        if ($this->app->runningInConsole()) {
            return parent::scan();
        }

        return $this->scanned ??= parent::scan();
    }

    public function addLocation($path)
    {
        $this->scanned = null;

        return parent::addLocation($path);
    }
}
