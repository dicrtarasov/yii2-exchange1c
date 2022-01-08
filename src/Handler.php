<?php
/*
 * @copyright 2019-2022 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license BSD-3-Clause
 * @version 08.01.22 16:41:24
 */

declare(strict_types = 1);
namespace dicr\exchange1c;

use SimpleXMLElement;
use yii\base\Exception;

/**
 * Интерфейс обработчика обмена 1С.
 *
 * @link https://v8.1c.ru/tekhnologii/obmen-dannymi-i-integratsiya/standarty-i-formaty/protokol-obmena-s-saytom/
 * @link https://v8.1c.ru/tekhnologii/obmen-dannymi-i-integratsiya/realizovannye-resheniya/obmen-dannymi-s-internet-magazinom/
 */
interface Handler
{
    /**
     * Авторизация сеанса импорта каталога.
     *
     * @return string|string[]|null данные для успешного ответа (success)
     * @throws Exception ошибка (ответ failure)
     */
    public function processCatalogCheckAuth(): array|string|null;

    /**
     * Инициализация параметров импорта каталога.
     *
     * @return string|string[]|null данные для успешного ответа (success)
     * @throws Exception ошибка (ответ failure)
     */
    public function processCatalogInit(): array|string|null;

    /**
     * Загрузка файлов каталога.
     *
     * @param string $filename относительный путь айла
     * @param string $content порция данных файла
     * @return string|string[]|null данные для успешного ответа (success)
     * @throws Exception ошибка (ответ failure)
     */
    public function processCatalogFile(string $filename, string $content): array|string|null;

    /**
     * Пошаговая загрузка каталога.
     *
     * @param string $filename относительный путь файла
     * @return string|string[]|null данные для успешного ответа (success)
     * @throws Exception ошибка (ответ failure)
     */
    public function processCatalogImport(string $filename): array|string|null;

    /**
     * Авторизация обмена заказами.
     *
     * @return string|string[]|null данные для успешного ответа (success)
     * @throws Exception ошибка (ответ failure)
     */
    public function processSaleCheckAuth(): array|string|null;

    /**
     * Инициализация параметров загрузки заказов.
     *
     * @return string|string[]|null данные для успешного ответа (success)
     * @throws Exception ошибка (ответ failure)
     */
    public function processSaleInit(): array|string|null;

    /**
     * Экспорт заказов.
     *
     * @return string|SimpleXMLElement ответ XML
     * @throws Exception ошибка (ответ failure)
     */
    public function processSaleQuery(): SimpleXMLElement|string;

    /**
     * Оповещение от 1С об успешном приеме заказов.
     *
     * @return string|string[]|null данные для успешного ответа (success)
     * @throws Exception ошибка (ответ failure)
     */
    public function processSaleSuccess(): array|string|null;

    /**
     * Загрузка файлов заказа на сайт.
     *
     * @param string $filename относительный путь файла
     * @param string $content порция данных файла
     * @return string|string[]|null данные для успешного ответа (success)
     * @throws Exception ошибка (ответ failure)
     */
    public function processSaleFile(string $filename, string $content): array|string|null;

    /**
     * Импорт файла заказов.
     * Нестандартный метод - в некоторых версиях 1C импорт заказов выполняется отдельно от sale/file.
     *
     * @param string $filename относительный путь файла
     * @return string|string[]|null данные для успешного ответа (success)
     * @throws Exception ошибка (ответ failure)
     * @throws ProgressException истекло время работы (ответ progress)
     */
    public function processSaleImport(string $filename): array|string|null;
}
