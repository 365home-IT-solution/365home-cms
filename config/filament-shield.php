<?php

return [
    'shield_resource' => [
        'should_register_navigation' => true,
        'slug' => 'shield/roles',
        'navigation_sort' => -1,
        'navigation_badge' => true,
        'navigation_group' => true,
        'is_globally_searchable' => false,
        'is_scoped_to_tenant' => true,
        'cluster' => null,

        // Nhiều Resource CỐ Ý dùng chung 1 model (vd RoomHousekeepingResource và
        // RoomAmenityAssignResource cùng dùng Modules\Product\App\Models\Product — xem 2 file đó,
        // mỗi cái đã tự đặt $modelLabel/getModelLabel() riêng để phân biệt đúng trên trang phân
        // quyền). Bật 'show_model_path' hiện thêm 1 dòng phụ đề = tên model ĐẦY ĐỦ NAMESPACE dưới
        // MỖI mục — 2 mục dùng chung model sẽ hiện y hệt nhau ở dòng phụ đề này, trông như bị
        // trùng/gộp permission dù thực ra mỗi mục vẫn có bộ quyền RIÊNG (khoá theo tên class
        // Resource, không phải theo model — xem FilamentShield::getDefaultPermissionIdentifier()).
        // Tắt hẳn dòng phụ đề này vì không có tác dụng thật (không phân quyền theo model), chỉ gây
        // hiểu nhầm — tiêu đề mục (đã lấy đúng theo $modelLabel riêng từng Resource) đã đủ phân
        // biệt rõ ràng.
        'show_model_path' => false,
    ],

    'auth_provider_model' => [
        'fqcn' => 'App\\Models\\User',
    ],

    'super_admin' => [
        'enabled' => true,
        'name' => 'super_admin',
        'define_via_gate' => true,
        'intercept_gate' => 'before', // after
    ],

    'panel_user' => [
        'enabled' => false,
        'name' => 'panel_user',
    ],

    'permission_prefixes' => [
        'resource' => [
            'view',
            'view_any',
            'create',
            'update',
            'restore',
            'restore_any',
            'replicate',
            'reorder',
            'delete',
            'delete_any',
            'force_delete',
            'force_delete_any',
        ],

        'page' => 'page',
        'widget' => 'widget',
    ],

    'entities' => [
        'pages' => true,
        'widgets' => true,
        'resources' => true,
        // Bật để các permission tự tạo tay (vd view_customer_voucher_usage, view_audit_logs,
        // access_log_viewer — xem AuditLogPermissionSeeder/RolesAndPermissionsSeeder) hiện ra thành
        // checkbox chọn được ở tab "Custom" trên trang Roles & Permissions, thay vì chỉ gán được
        // qua code/tinker.
        'custom_permissions' => true,
    ],

    'generator' => [
        'option' => 'policies_and_permissions',
        'policy_directory' => 'Policies',
        'policy_namespace' => 'Policies',
    ],

    'exclude' => [
        'enabled' => true,

        'pages' => [],

        'widgets' => [
            'AccountWidget',
            'FilamentInfoWidget',
        ],

        'resources' => [],
    ],

    'discovery' => [
        'discover_all_resources' => false,
        'discover_all_widgets' => false,
        'discover_all_pages' => false,
    ],

    'register_role_policy' => [
        'enabled' => true,
    ],

];
