<?php

namespace Modules\Component\App\Enums;

enum FieldInputType: string
{
    case TEXT = 'text';
    case NUMBER = 'number';
    case TOGGLE = 'toggle';
    case RADIO = 'radio';
    case GROUP_CONTACT = 'group_contact';
    case TEXTAREA = 'textarea';
    case DOMAIN_SELECTION = 'domain_selection';
    case PRICING_GROUP_SELECTION = 'pricing_group_selection';
    case PRICING_CATEGORY_SELECTION = 'pricing_category_selection';
    case FORM_SELECTION = 'form_selection';
    case PRODUCT_SELECTION = 'product_selection';
    case POST_SELECTION = 'post_selection';
    case POST_SELECTION_ONE = 'post_selection_one';
    case CATEGORY_SELECTION_PRODUCT = 'category_selection_product';
    case CATEGORY_SELECTION_POST = 'category_selection_post';
    case CATEGORY_SELECTION_PROCESS_DESIGN = 'category_selection_process_design';
    case IMAGE = "media";
    case IMAGES = "medias";
    case COLOR_PICKER = 'colorpicker';
    case SELECT = 'select';
    case KEY_VALUES = 'key_values';
    case PROCESS_REPEATER = 'process_repeater';
    case BUILDER = 'builder';
    case SERVICES = 'services';
    case SCORE_MAIN_BOARD_SELECTION = 'score_main_board_selection';
    case CONTENT_MEDIA = 'content_media';
    case SOCIAL_MEDIA = 'social_media';
    case SIDEBAR = 'sidebar';
    case HEADING_PAGE = 'heading_page';
    case IMAGE_OR_VIDEO = 'video/image';
    case CONTENT_EDITOR = 'content_editor';

    case CONTENT_COMPONENT = 'content_component';
    case BUSINESS_BRANCHES = 'business_branches';
    case STATS = 'stats';
    case ICONS = 'icons';
    case RANGE = 'range';
    case TEMPLATE_WEBSITE_SELECTION = "template_website_selection";
    case TEMPLATE_WEBSITE_CATEGORY_SELECTION = "template_website_category_selection";
    case TESTIMONIAL = "testimonial";
    case EFFECTIVENESS_PROOF = 'effectiveness_proof';
    case VIDEO_LIST = 'video_list';
    case FAQ = 'faq';
    case CHART_COMPONENT = 'chart_component';
    case ABOUT_COMPONENT = 'about_component';
    case BUSSINESS_INTRODUCTION = 'bussiness_introduction';
    case PERFORMANCE_RESULTS = 'performance-results';
    case VISION_SECTION = 'vision_section';
    case OVERVIEW = 'overview';
    case BRANCH = 'branch';
    case SERVICE_ICON = 'service_icon';
    case REPEATER = 'repeater';

    // Localhome
    case DESTINATION = 'destination'; // điểm đến
    case AREA = 'area'; // khu vực

    case CHOOSE_ROOM = 'choose_room'; // chọn phòng
}
