<?php
/*
 * @copyright 2019-2021 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license MIT
 * @version 28.02.21 13:56:11
 */

/** @noinspection UsingInclusionReturnValueInspection */
declare(strict_types = 1);

$config = require(__DIR__ . '/common.php');

// дополняем web-конфиг, отсутствующий в консольной версии
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_SCHEME'] = 'https';

$config['aliases']['@web'] = 'https://localhost';
$config['aliases']['@webroot'] = '@app/web';
$config['controllerNamespace'] = 'dicr\\exchange1c';

$config['components']['log']['flushInterval'] = 1;
$config['components']['log']['targets']['console'] = [
    'class' => dicr\log\ConsoleTarget::class,
    'levels' => ['error', 'warning', 'info'],
    'categories' => ['app\\*', 'dicr\\*']
];

return $config;
