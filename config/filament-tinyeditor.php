<?php

return [
    'version' => [
        'tiny' => '7.3.0',
        'language' => [
            // https://cdn.jsdelivr.net/npm/tinymce-i18n@latest/
            'version' => '24.7.29',
            'package' => 'langs7',
        ],
        'licence_key' => env('TINY_LICENSE_KEY', 'no-api-key'),
    ],
    'provider' => 'cloud', // cloud|vendor
    // 'direction' => 'rtl',
    /**
     * change darkMode: 'auto'|'force'|'class'|'media'|false|'custom'
     */
    'darkMode' => 'auto',

    /** cutsom */
    'skins' => [
        // oxide, oxide-dark, tinymce-5, tinymce-5-dark
        'ui' => 'oxide',

        // dark, default, document, tinymce-5, tinymce-5-dark, writer
        'content' => 'default'
    ],
    'profiles' => [
        'default' => [
            'plugins' => 'accordion autoresize codesample directionality advlist link image lists preview pagebreak searchreplace wordcount code fullscreen insertdatetime media table emoticons',
            'toolbar' => 'undo redo removeformat | fontfamily fontsize fontsizeinput font_size_formats styles | bold italic underline | rtl ltr | alignjustify alignleft aligncenter alignright | numlist bullist outdent indent | forecolor backcolor | blockquote table toc hr | image link media codesample emoticons | wordcount fullscreen',
            'upload_directory' => null,
        ],

        'simple' => [
            'plugins' => 'autoresize directionality emoticons link wordcount',
            'toolbar' => 'removeformat | bold italic | rtl ltr | numlist bullist | link emoticons',
            'upload_directory' => null,
        ],

        'minimal' => [
            'plugins' => 'link wordcount',
            'toolbar' => 'bold italic link numlist bullist',
            'upload_directory' => null,
        ],

        'full' => [
            'plugins' => 'accordion autoresize codesample directionality advlist autolink link image lists charmap preview anchor pagebreak searchreplace wordcount visualblocks visualchars code fullscreen insertdatetime media table emoticons help',
            'toolbar' => 'undo redo removeformat | fontfamily fontsize fontsizeinput font_size_formats styles | bold italic underline | rtl ltr | alignjustify alignright aligncenter alignleft | numlist bullist outdent indent accordion | forecolor backcolor | blockquote table toc hr | image link anchor media codesample emoticons | visualblocks print preview wordcount fullscreen help',
            'upload_directory' => null,
        ],

        'custom-full' => [
            'plugins' => 'accordion autoresize codesample directionality advlist autolink link image lists charmap preview anchor pagebreak searchreplace wordcount visualblocks visualchars code fullscreen insertdatetime media table emoticons help formatpainter nonbreaking lists advtable typography inlinecss tableofcontents',

            'toolbar' => 'undo redo removeformat | fontsizeinput font_size_formats styles | bold table forecolor backcolor preview | cut copy paste | formatselect fontsize | underline strikethrough subscript superscript | alignjustify alignleft aligncenter alignright | rtl ltr | lineheight | numlist bullist outdent indent | link anchor | accordion | blockquote hr pagebreak nonbreaking | charmap emoticons codesample | image media | visualblocks code fullscreen | searchreplace wordcount print help',

            'toolbar_mode' => 'wrap',
            'toolbar_sticky' => true,
            'toolbar_sticky_offset' => 0,

            'image_advtab' => true,
            'image_caption' => true,
            'image_title' => true,
            'automatic_uploads' => true,

            'contextmenu' => 'link image table',

            'style_formats' => [
                [
                    'title' => 'Headings',
                    'items' => [
                        ['title' => 'Heading 1', 'format' => 'h1'],
                        ['title' => 'Heading 2', 'format' => 'h2'],
                        ['title' => 'Heading 3', 'format' => 'h3'],
                        ['title' => 'Heading 4', 'format' => 'h4'],
                        ['title' => 'Heading 5', 'format' => 'h5'],
                        ['title' => 'Heading 6', 'format' => 'h6']
                    ]
                ],
                [
                    'title' => 'Inline',
                    'items' => [
                        ['title' => 'Bold', 'format' => 'bold'],
                        ['title' => 'Italic', 'format' => 'italic'],
                        ['title' => 'Underline', 'format' => 'underline'],
                        ['title' => 'Strikethrough', 'format' => 'strikethrough'],
                        ['title' => 'Superscript', 'format' => 'superscript'],
                        ['title' => 'Subscript', 'format' => 'subscript'],
                        ['title' => 'Code', 'format' => 'code']
                    ]
                ],
                [
                    'title' => 'Blocks',
                    'items' => [
                        ['title' => 'Paragraph', 'format' => 'p'],
                        ['title' => 'Blockquote', 'format' => 'blockquote'],
                        ['title' => 'Div', 'format' => 'div'],
                        ['title' => 'Pre', 'format' => 'pre']
                    ]
                ]
            ],

            'upload_directory' => null,
        ],
    ],

    /**
     * this option will load optional language file based on you app locale
     * example:
     * languages => [
     *      'fa' => 'https://cdn.jsdelivr.net/npm/tinymce-i18n@24.7.29/langs7/fa.min.js',
     *      'es' => 'https://cdn.jsdelivr.net/npm/tinymce-i18n@24.7.29/langs7/es.min.js',
     *      'ja' => asset('assets/ja.min.js')
     * ]
     */
    'languages' => [],

    'extra' => [
        'toolbar' => [
            'fontsize' => '10px 12px 13px 14px 16px 18px 20px',
            'fontfamily' => 'Tahoma=tahoma,arial,helvetica,sans-serif;',
        ]
    ]
];
