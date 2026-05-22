<?php

return [

    'admin' => [

        'app-baoshu' => [
            'name' => '参与报数',
            'icon' => 'bi bi-journal-check',
            'left' => [
                'app-baoshu-content' => [
                    'name' => '报数管理',
                    'icon' => 'bi bi-journal-check',
                    'link' => [
                        [
                            'name' => '报数记录',
                            'icon' => 'bi bi-list-ul',
                            'uri'  => 'baoshu/home/index',
                        ],
                        [
                            'name' => '历史记录',
                            'icon' => 'bi bi-clock-history',
                            'uri'  => 'baoshu/home/history',
                        ],
                    ],
                ],
            ],
        ],

    ],

];
