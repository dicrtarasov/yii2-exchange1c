<?php

/*
 * @copyright 2019-2021 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license BSD-3-Clause
 * @version 28.05.21 14:30:13
 */
declare(strict_types = 1);

use dicr\exchange1c\Client;
use yii\caching\FileCache;

/** среда разработки */
defined('YII_ENV') || define('YII_ENV', 'dev');

/** режим отладки */
defined('YII_DEBUG') || define('YII_DEBUG', true);

require_once __DIR__ . '/../config/local.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

/** @noinspection PhpUnhandledExceptionInspection */
new yii\console\Application([
    'id' => 'test',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'components' => [
        'urlManager' => [
            'hostInfo' => 'https://localhost'
        ],
        'cache' => FileCache::class,
        'log' => [
            'targets' => [
                [
                    'class' => yii\log\FileTarget::class,
                    'levels' => ['error', 'warning', 'info', 'trace']
                ]
            ]
        ],
        'client' => [
            'class' => Client::class,
            'url' => EXCHANGE_URL,
            'login' => EXCHANGE_LOGIN,
            'password' => EXCHANGE_PASSWORD
        ]
    ]
]);
