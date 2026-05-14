<?php

namespace Modules\BladeThemeV1\Services\Post;

use Illuminate\Database\Eloquent\Collection;
use Modules\Category\Entities\Category;
use Modules\Post\Entities\Post;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class PostService
{
    /**
     * Lấy danh sách bài viết dựa trên các tiêu chí tìm kiếm.
     *
     * @param string|null $search
     * @param string|null $selectedCategory
     * @param string|null $sortBy
     * @param int|null $perPage
     * @param string $excludedCategory
     * @return LengthAwarePaginator
     */
    public function fetchPosts(?string $search = null, ?string $selectedCategory = null, ?string $sortBy = null, ?int $perPage = 15, string $excludedCategory = 'Trang'): LengthAwarePaginator
    {
        $query = $this->baseQuery();

        $query = $this->applySearchFilter($query, $search);
        $query = $this->applyCategoryFilter($query, $selectedCategory);
        $query = $this->applySort($query, $sortBy);
        $query = $this->applyExcludeCategory($query, $excludedCategory);

        return $query->paginate($perPage);
    }

    /**
     * Lấy danh sách bài viết mới nhất.
     *
     * @param int $limit
     * @return Collection
     */
    public function newPost(int $limit = 5): Collection
    {
        $query = $this->baseQuery();

        $query = $this->applyExcludeCategory($query, 'uncategories');

        return $query->limit($limit)->get();
    }

    /**
     * Lấy danh sách bài viết theo grid với các cấu hình tùy chỉnh.
     *
     * @param array $config
     * @return LengthAwarePaginator
     */
    public function fetchPostsGrid(array $config): LengthAwarePaginator
    {
        $query = $this->baseQuery();

        $query = $this->applyGridFilters($query, $config);

        return $query->latest()->paginate($config['per_page'] ?? 8);
    }

    /**
     * Lấy danh sách bài viết để hiển thị trong slide với các cấu hình tùy chỉnh.
     *
     * @param array $config Mảng cấu hình chứa các tham số như limit_post, posts, category
     * @return Collection
     */
    public function postSlide(array $config = []): Collection
    {
        $query = $this->baseQuery();

        $limit = $config['limit_post'] ?? 8;

        $query = $this->applyExcludeCategory($query, 'uncategories');

        $query = $this->applyGridFilters($query, $config);

        return $query->latest()->limit($limit)->get();
    }

    /**
     * Tạo query cơ bản cho Post với các quan hệ cần thiết.
     *
     * @return Builder
     */
    protected function baseQuery(): Builder
    {
        return Post::with(['tags', 'categories', 'media'])
            ->where('status', '=', 'published')->where(function($query) {
                $query->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            })
            ->whereHas('categories', function($q) {
                $q->where('status', 1);
            });
    }

    /**
     * Áp dụng bộ lọc tìm kiếm dựa trên tiêu đề.
     *
     * @param Builder $query
     * @param string|null $search
     * @return Builder
     */
    protected function applySearchFilter(Builder $query, ?string $search): Builder
    {
        if (!empty($search)) {
            $query->where('title', 'like', '%' . $search . '%');
        }

        return $query;
    }

    /**
     * Áp dụng bộ lọc category nếu có.
     *
     * @param Builder $query
     * @param string|null $selectedCategory
     * @return Builder
     */
    protected function applyCategoryFilter(Builder $query, ?string $selectedCategory): Builder
    {
        if (!empty($selectedCategory)) {
            $query->whereHas('categories', function ($q) use ($selectedCategory) {
                $q->where('categories.name', $selectedCategory);
            });
        }

        return $query;
    }

    /**
     * Áp dụng sắp xếp cho danh sách bài viết.
     *
     * @param Builder $query
     * @param string|null $sortBy
     * @return Builder
     */
    protected function applySort(Builder $query, ?string $sortBy): Builder
    {
        return match ($sortBy) {
            'A-Z' => $query->orderBy('title', 'asc'),
            'Z-A' => $query->orderBy('title', 'desc'),
            'newest' => $query->latest(),
            default => $query->latest(),
        };
    }


    /**
     * Loại bỏ các bài viết thuộc category không mong muốn.
     *
     * @param Builder $query
     * @param string $excludedCategory
     * @return Builder
     */
    protected function applyExcludeCategory(Builder $query, string $excludedCategory): Builder
    {
        return $query->whereDoesntHave('categories', function ($q) use ($excludedCategory) {
            $q->where('categories.name', $excludedCategory);
        });
    }

/**
     * Hiển thị danh mục với tìm kiếm và bộ lọc tùy chọn.
     *
     * @param string|null $searchCate
     * @return Collection
     */
    public function getCategories(?string $searchCate = null)
    {

        $query = Category::whereNull('parent_id')
            ->with(['children.children.children.children.children.children' => function ($query) {
                $query->whereHas('posts', function ($q) {
                    $q->whereDoesntHave('categories', function ($subQ) {
                        $subQ->where('categories.name', 'uncategories');
                    });
                });
            }])
            ->where('category_type', 'post')
            ->where('status', 1);

        if ($searchCate) {
            $query = $this->applySearchCateFilter($query, $searchCate);
        }

        $categories = $query->orderBy('created_at', 'desc')->get();

        if ($searchCate) {
            $matchingCategory = $this->findFirstMatchingCategory($categories, $searchCate);
            if ($matchingCategory) {
                $matchingCategory->is_matched = true;
            }
        }

        return $categories;
    }

    protected function findFirstMatchingCategory($categories, $searchTerm)
    {
        foreach ($categories as $category) {
            // Kiểm tra danh mục hiện tại (cấp cha)
            if (stripos($category->name, $searchTerm) !== false) {
                return $category;
            }

            // Kiểm tra đệ quy cho các cấp con (children)
            $foundInChildren = $this->searchInChildren($category->children, $searchTerm);
            if ($foundInChildren) {
                return $foundInChildren;
            }
        }

        return null;
    }

    protected function searchInChildren($children, $searchTerm)
    {
        foreach ($children as $child) {
            // Check the current level category
            if (stripos($child->name, $searchTerm) !== false) {
                return $child;
            }

            // Recursively check in deeper levels (children of children)
            $foundInGrandChildren = $this->searchInChildren($child->children, $searchTerm);
            if ($foundInGrandChildren) {
                return $foundInGrandChildren;
            }
        }

        return null;
    }

    protected function applySearchCateFilter(Builder $query, string $searchCate)
    {
        return $query->where(function ($query) use ($searchCate) {
            // Search in parent category
            $query->where('name', 'like', '%' . $searchCate . '%')
                ->where('category_type', 'post')
                ->orWhere(function ($q) use ($searchCate) {
                    $this->applyChildrenSearch($q, $searchCate);
                });
        });
    }

    protected function applyChildrenSearch($query, $searchCate, $level = 1)
    {
        $maxDepth = 7;

        if ($level > $maxDepth) {
            return;
        }

        $query->orWhereHas("children" . str_repeat('.children', $level - 1), function ($q) use ($searchCate) {
            $q->where('name', 'like', '%' . $searchCate . '%')
                ->where('category_type', 'post');
        });

        $this->applyChildrenSearch($query, $searchCate, $level + 1);
    }

    /**
     * Áp dụng các bộ lọc cho grid posts.
     *
     * @param Builder $query
     * @param array $config
     * @return Builder
     */
    protected function applyGridFilters(Builder $query, array $config): Builder
    {
        // Áp dụng lọc theo category
        if (!empty($config['category'])) {
            $categoryIds = explode(',', $config['category']);
            if (!empty($categoryIds)) {
                $query->whereHas('categories', function ($q) use ($categoryIds) {
                    $q->whereIn('categories.id', $categoryIds);
                });
            }
        }

        // Áp dụng lọc theo posts specific nếu không có category filter
        if (!empty($config['posts']) && empty($config['category'])) {
            $postIds = explode(',', $config['posts']);
            if (!empty($postIds)) {
                $query->whereIn('id', $postIds);
            }
        }

        return $query;
    }

}
