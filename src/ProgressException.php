<?php
/*
 * @copyright 2019-2021 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license MIT
 * @version 27.02.21 10:17:25
 */

declare(strict_types = 1);
namespace dicr\exchange1c;

use yii\base\Exception;

/**
 * Исключение, обозначающее окончание времени работы.
 * В 1С возвращается ответ "progress".
 */
class ProgressException extends Exception
{

}
