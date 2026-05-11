<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Class GeneralSettings
 *
 * Handles the general settings of the application.
 *
 * @package App\Settings
 */
class GeneralSettings extends Settings
{
    /** @var string $brand_name The name of the brand. */
    public string $brand_name;

    /** @var ?string $brand_logo The logo of the brand. */
    public ?string $brand_logo;

    /** @var ?string $brand_logo_light_version The light version of the brand logo. */
    public ?string $brand_logo_light_version;

    /** @var string $brand_logoHeight The height of the brand logo. */
    public string $brand_logoHeight;

    /** @var bool $site_active Indicates if the site is active. */
    public bool $site_active;

    /** @var ?string $site_favicon The favicon of the site. */
    public ?string $site_favicon;

    /** @var array<string, string> $site_theme The theme settings of the site. */
    public array $site_theme;
    public ?array $color_product = null;
    public ?array $popup = null; // Lưu POPUP

    /**
     * Script injection properties
     *
     * @var ?string $header_scripts Scripts to inject into the header.
     * @var ?string $body_scripts_top Scripts to inject at the top of the body.
     * @var ?string $body_scripts_bottom Scripts to inject at the bottom of the body.
     * @var ?string $footer_scripts Scripts to inject into the footer.
     */
    public ?string $header_scripts = '';
    public ?string $body_scripts_top = '';
    public ?string $body_scripts_bottom = '';
    public ?string $footer_scripts = '';

    /**
     * Google Analytics Settings
     *
     * @var ?string $google_analytics_id The Google Analytics ID.
     * @var bool $google_analytics_enabled Indicates if Google Analytics is enabled.
     * @var ?string $google_tag_manager_id The Google Tag Manager ID.
     * @var bool $google_tag_manager_enabled Indicates if Google Tag Manager is enabled.
     * @var ?string $google_site_verification The Google Site Verification value.
     */
    public ?string $google_analytics_id;
    public bool $google_analytics_enabled = false;
    public ?string $google_tag_manager_id;
    public bool $google_tag_manager_enabled = false;
    public ?string $google_site_verification;

    /**
     * SEO properties
     *
     * @var ?string $title The SEO title of the site.
     * @var ?string $description The SEO description of the site.
     * @var ?string $keywords The SEO keywords of the site.
     * @var ?string $canonical The canonical URL for the site.
     * @var ?string $robots The robots meta tag content.
     * @var ?string $og_type The OpenGraph object type.
     * @var ?string $og_url The OpenGraph URL.
     * @var ?string $og_title The OpenGraph title.
     * @var ?string $og_description The OpenGraph description.
     * @var ?string $og_image The OpenGraph image.
     * @var ?string $og_locale The OpenGraph locale.
     * @var ?string $twitter_card The Twitter card type.
     * @var ?string $twitter_url The Twitter URL.
     * @var ?string $twitter_title The Twitter title.
     * @var ?string $twitter_description The Twitter description.
     * @var ?string $twitter_image The Twitter image.
     * @var ?string $twitter_site The Twitter site username.
     * @var ?string $twitter_creator The Twitter creator username.
     * @var ?string $author The author of the content.
     * @var ?string $article_published_time The article's published time.
     * @var ?string $article_modified_time The article's modified time.
     */
    public ?string $title;
    public ?string $description;
    public ?string $keywords;
    public ?string $canonical;
    public ?string $robots;
    public ?string $og_type;
    public ?string $og_url;
    public ?string $og_title;
    public ?string $og_description;
    public ?string $og_image;
    public ?string $og_locale;
    public ?string $twitter_card;
    public ?string $twitter_url;
    public ?string $twitter_title;
    public ?string $twitter_description;
    public ?string $twitter_image;
    public ?string $twitter_site;
    public ?string $twitter_creator;
    public ?string $author;
    public ?string $article_published_time;
    public ?string $article_modified_time;

    /**
     * Defines the group name for the settings.
     *
     * @return string The group name.
     */
    public static function group(): string
    {
        return 'general';
    }
}