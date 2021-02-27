<?php
/*
 * @copyright 2019-2021 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license MIT
 * @version 27.02.21 23:01:24
 */

declare(strict_types = 1);

use dicr\exchange1c\Client;

require_once(__DIR__ . '/local.php');

return [
    'id' => 'exchange1c',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'aliases' => [
        '@bower' => '@vendor/bower-asset',
        '@npm' => '@vendor/npm-asset'
    ],
    'language' => 'ru',
    'sourceLanguage' => 'ru',
    'timeZone' => 'Asia/Yekaterinburg',
    'components' => [
        'cache' => [
            'class' => yii\caching\FileCache::class,
        ],

        'log' => [
            'traceLevel' => 5,
            'targets' => [
                'app' => [
                    'class' => yii\log\FileTarget::class,
                    'levels' => ['error', 'warning', 'info'],
                    'except' => [
                        'yii\web\HttpException:404', 'yii\i18n\PhpMessageSource::loadMessages', 'yii\web\Session::open',
                        'yii\swiftmailer\Mailer::sendMessage'
                    ]
                ],
                'dicr' => [
                    'class' => yii\log\FileTarget::class,
                    'logFile' => '@runtime/logs/dicr.log',
                    'categories' => ['dicr\\*', 'app\\*'],
                    'levels' => ['error', 'warning', 'info', 'trace', 'profile']
                ]
            ],
        ],
        'client' => [
            'class' => Client::class,
            'url' => EXCHANGE_URL,
            'login' => EXCHANGE_LOGIN,
            'password' => EXCHANGE_PASSWORD
        ]
    ]
];