<?php

declare(strict_types=1);

namespace App\Console\Commands\MediaBackup;

use App\Console\Commands\BackupOrganizedMedia;

class BackupOtherMedia extends BackupOrganizedMedia
{
    protected $signature = 'media:backup-khac
        {--dest= : Thư mục đích (mặc định: env MEDIA_BACKUP_PATH, không có thì Windows: ~/Downloads/365home, Linux: ~/www/media.365home.vn/365home)}
        {--private-dest= : Thư mục riêng cho cccd, doi-tac và báo cáo (Linux mặc định: ~/backup/365home-rieng-tu)}
        {--dry-run : Chỉ thống kê, không chép file}';

    protected $description = 'Backup ảnh banner, tag, khuyến mãi, tiện ích, tỉnh thành... ra thư mục khac/';

    protected ?string $group = 'khac';
}
