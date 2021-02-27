<?php
/*
 * @copyright 2019-2021 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license MIT
 * @version 27.02.21 21:35:45
 */

declare(strict_types = 1);
namespace dicr\exchange1c;

/**
 * Статусы ответов
 */
interface C1
{
    /** @var string обмен каталога */
    public const TYPE_CATALOG = 'catalog';

    /** @var string обмен заказами */
    public const TYPE_SALE = 'sale';

    /** @var string ответ об успешной завершении обработки */
    public const SUCCESS = 'success';

    /** @var string ответ об ошибке обработки */
    public const FAILURE = 'failure';

    /** @var string ответ в случае частичного выполнения */
    public const PROGRESS = 'progress';

    /** @var string пустое значение 1C */
    public const ZERO_CID = '00000000-0000-0000-0000-000000000000';

    /** @var string пустая дата */
    public const ZERO_DATE = '0001-01-01';
}
