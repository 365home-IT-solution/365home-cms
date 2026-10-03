<?php

declare(strict_types=1);

namespace App\Console\Commands\MediaBackup;

use App\Console\Commands\BackupOrganizedMedia;

class BackupRoomMedia extends BackupOrganizedMedia
{
    protected $signature = 'media:backup-phong
        {--dest= : Thư mục đích (mặc định: env MEDIA_BACKUP_PATH, không có thì ../media_365home cạnh thư mục dự án)}
        {--dry-run : Chỉ thống kê, không chép file}';

    protected $description = 'Backup ảnh phòng ra thư mục phong/';

    protected ?string $group = 'phong';
}
