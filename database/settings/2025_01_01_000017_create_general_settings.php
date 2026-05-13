<?php

use Illuminate\Support\Facades\DB;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (DB::table('settings')->where('group', 'general')->where('name', 'brand_name')->exists()) {
            return;
        }

        $this->migrator->add('general.brand_name', 'Goldenbee');
        $this->migrator->add('general.brand_logo', '/images/logo-goldenbee.png');
        $this->migrator->add('general.brand_logo_light_version', '/images/logo-goldenbee-light-version.png');
        $this->migrator->add('general.brand_logoHeight', '30');
        $this->migrator->add('general.site_active', true);
        $this->migrator->add('general.site_favicon', 'images/logo-goldenbee.png');

        $this->migrator->add('general.site_theme', [
            "primary"     => "#FBCB1C",
            "secondary"   => "#3be5e8",
            "Secondary"   => "#3be5e8",
            "gray"        => "#485173",
            "background"  => "#222831",
            "bg_dark"     => "#1a1e25",
            "text_dark"   => "#212529",
            "red_9c"      => "#9c273d",
            "border_gray" => "#343a40",
            "tick_green"  => "#56f956",
            "tick_yellow" => "#ffff00",
            "tick_gray"   => "#808080",
            "success"     => "#1DCB8A",
            "danger"      => "#ff5467",
            "info"        => "#6E6DD7",
            "warning"     => "#f5de8d",
        ]);

        $this->migrator->add('general.title', '');
        $this->migrator->add('general.description', '');
        $this->migrator->add('general.keywords', '');
        $this->migrator->add('general.canonical', '');
        $this->migrator->add('general.robots', 'noindex, nofollow');
        $this->migrator->add('general.og_type', 'website');
        $this->migrator->add('general.og_url', '');
        $this->migrator->add('general.og_title', '');
        $this->migrator->add('general.og_description', '');
        $this->migrator->add('general.og_image', '');
        $this->migrator->add('general.og_locale', 'vi_VN');
        $this->migrator->add('general.twitter_card', 'summary_large_image');
        $this->migrator->add('general.twitter_url', '');
        $this->migrator->add('general.twitter_title', '');
        $this->migrator->add('general.twitter_description', '');
        $this->migrator->add('general.twitter_image', '');
        $this->migrator->add('general.twitter_site', '');
        $this->migrator->add('general.twitter_creator', '');
        $this->migrator->add('general.author', '');
        $this->migrator->add('general.article_published_time', null);
        $this->migrator->add('general.article_modified_time', null);
        $this->migrator->add('general.color_product', null);
        $this->migrator->add('general.popup', null);
    }
};
