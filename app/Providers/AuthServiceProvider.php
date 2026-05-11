<?php

namespace App\Providers;

use App\Models\User;
use App\Policies\RolePolicy;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Modules\Category\Entities\Category;
use Modules\Category\Entities\CategoryPolicy;
use Modules\Comment\Entities\Comment;
use Modules\Comment\Entities\CommentPolicy;
use Modules\Page\Entities\ComponentPolicy;
use Modules\Footer\Entities\Footer;
use Modules\Footer\Entities\FooterPolicy;
use Modules\Form\Entities\EmailSetting;
use Modules\Form\Entities\EmailSettingPolicy;
use Modules\Form\Entities\Form;
use Modules\Form\Entities\FormNotification;
use Modules\Form\Entities\FormNotificationPolicy;
use Modules\Form\Entities\FormPolicy;
use Modules\Form\Entities\FormSubmission;
use Modules\Form\Entities\FormSubmissionPolicy;
use Modules\Header\Entities\Header;
use Modules\Header\Entities\HeaderPolicy;
use Modules\LiveChat\Entities\ContactLink;
use Modules\LiveChat\Entities\ContactLinkPolicy;
use Modules\Menu\Entities\Menu;
use Modules\Menu\Entities\MenuPolicy;
use Modules\Page\Entities\Component;
use Modules\Page\Entities\Page;
use Modules\Page\Entities\PagePolicy;
use Modules\Post\Entities\Post;
use Modules\Post\Entities\PostPolicy;
use Modules\Process\Entities\Process;
use Modules\Process\Entities\ProcessPolicy;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Policies\ProductPolicy;
use Modules\SettingCompany\Entities\Branch;
use Modules\SettingCompany\Entities\BranchPolicy;
use Modules\SettingCompany\Entities\Business;
use Modules\SettingCompany\Entities\BusinessPolicy;
use Modules\Tag\Entities\TagPolicy;
use Spatie\Permission\Models\Role;
use Spatie\Tags\Tag;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Role::class => RolePolicy::class,
        Category::class => CategoryPolicy::class,
        Comment::class => CommentPolicy::class,
        Component::class => ComponentPolicy::class,
        Form::class => FormPolicy::class,
        EmailSetting::class => EmailSettingPolicy::class,
        FormNotification::class => FormNotificationPolicy::class,
        FormSubmission::class => FormSubmissionPolicy::class,
        Menu::class => MenuPolicy::class,
        Page::class => PagePolicy::class,
        Post::class => PostPolicy::class,
        Process::class => ProcessPolicy::class,
        Product::class => ProductPolicy::class,
        Branch::class => BranchPolicy::class,
        Business::class => BusinessPolicy::class,
        Tag::class => TagPolicy::class,
        User::class => UserPolicy::class
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();
    }
}
