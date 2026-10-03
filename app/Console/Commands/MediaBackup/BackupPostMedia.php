<?php

declare(strict_types=1);

namespace App\Console\Commands\MediaBackup;

use App\Console\Commands\BackupOrganizedMedia;

class BackupPostMedia extends BackupOrganizedMedia
{
    protected $signature = 'media:backup-bai-viet
        {--dest= : Thư mục đích (mặc định: env MEDIA_BACKUP_PATH, không có thì ../media_365home cạnh thư mục dự án)}
        {--dry-run : Chỉ thống kê, không chép file}';

    protected $description = 'Backup ảnh bài viết (ảnh chính + ảnh trong nội dung) ra thư mục bai-viet/';

    protected ?string $group = 'bai-viet';
}
