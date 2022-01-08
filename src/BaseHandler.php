<?php
/*
 * @copyright 2019-2022 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license BSD-3-Clause
 * @version 08.01.22 17:12:22
 */

declare(strict_types = 1);
namespace dicr\exchange1c;

use SimpleXMLElement;
use Throwable;
use Yii;
use yii\base\BaseObject;
use yii\base\ErrorException;
use yii\base\Exception;
use yii\base\NotSupportedException;
use yii\helpers\FileHelper;

use function date;
use function dirname;
use function error_clear_last;
use function error_get_last;
use function file_get_contents;
use function file_put_contents;
use function is_file;
use function simplexml_load_string;
use function strtotime;

use const FILE_APPEND;

/**
 * Базовый обработчик обмена 1С.
 */
abstract class BaseHandler extends BaseObject implements Handler
{
    /** ключ данных Свойств */
    public const KEY_PROP = 'prop';

    /** ключ данных Групп */
    public const KEY_GROUP = 'group';

    /** ключ данных Товаров */
    public const KEY_PROD = 'prod';

    /** ключ данных Заказов */
    public const KEY_ORDER = 'order';

    /** ключ данных файлов */
    public const KEY_FILE = 'file';

    /** ключ данных Предложений */
    public const KEY_OFFER = 'offer';

    /**
     * AbstractExchangeHandler constructor.
     */
    public function __construct(
        protected Module $module,
        array $config = []
    ) {
        parent::__construct($config);
    }

    /**
     * @inheritDoc
     */
    public function processCatalogCheckAuth(): array|string|null
    {
        return $this->processAuth();
    }

    /**
     * @inheritDoc
     * @throws ErrorException
     */
    public function processCatalogInit(): array|string|null
    {
        return $this->processInit();
    }

    /**
     * @inheritDoc
     * @throws ErrorException
     */
    public function processCatalogFile(string $filename, string $content): array|string|null
    {
        return $this->processFile($filename, $content);
    }

    /**
     * @inheritDoc
     * @throws ErrorException
     */
    public function processCatalogImport(string $filename): array|string|null
    {
        // распаковываем zip-файлы
        $this->module->extractZipFiles();

        // открываем файл
        $filepath = $this->module->path($filename);
        if (! is_file($filepath)) {
            throw new Exception('Файл не существует: ' . $filepath);
        }

        // загружаем xml
        $xml = simplexml_load_string(file_get_contents($filepath));
        if ($xml === false) {
            throw new Exception('Ошибка разбора xml: ' . $filepath);
        }

        // импортируем Свойства
        $this->importProps($xml);

        // импортируем Группы
        $this->importGroups($xml);

        // импортируем Товары
        $this->importProducts($xml);

        // импорт предложений
        $this->importOffers($xml);

        // в случае полной выгрузки удаляем с базы удаленные товары и категории
        if ((string)$xml->Каталог['СодержитТолькоИзменения'] === 'false') {
            try {
                $this->cleanOldData($xml);
            } catch (Throwable $ex) {
                $this->module->errors($ex);
            }
        }

        // получаем статистику и ошибки
        $stat = $this->module->stat();
        $errors = $this->module->errors();

        // очищаем сессию, так как она очень большая и будет брошена 1С
        $this->module->sess(false);

        // статистика и ошибки
        return [$stat, $errors];
    }

    /**
     * @inheritDoc
     */
    public function processSaleCheckAuth(): array|string|null
    {
        return $this->processAuth();
    }

    /**
     * @inheritDoc
     * @throws ErrorException
     */
    public function processSaleInit(): array|string|null
    {
        return $this->processInit();
    }

    /**
     * @inheritDoc
     */
    public function processSaleQuery(): string|SimpleXMLElement
    {
        throw new NotSupportedException('Не реализовано');
    }

    /**
     * @inheritDoc
     */
    public function processSaleSuccess(): array|string|null
    {
        return null;
    }

    /**
     * @inheritDoc
     * @throws ErrorException
     */
    public function processSaleFile(string $filename, string $content): array|string|null
    {
        $ret = $this->processFile($filename, $content);

        // в стандартном протоколе импорт вызывается сразу при получении файла
        if ($this->module->saleImportInFile) {
            // удаляем zip из имени файла
            $matches = null;
            if (preg_match('~^(.+)\.zip$~ui', $filename, $matches)) {
                $filename = $matches[1];
            }

            // импортируем файл
            $ret = $this->processSaleImport($filename);
        }

        return $ret;
    }

    /**
     * @inheritDoc
     * @throws ErrorException
     */
    public function processSaleImport(string $filename): array|string|null
    {
        // распаковываем zip-файлы
        $this->module->extractZipFiles();

        $filepath = $this->module->path($filename);
        if (! is_file($filepath)) {
            throw new Exception('Файл не существует: ' . $filepath);
        }

        // загружаем xml
        $xml = simplexml_load_string(file_get_contents($filepath));
        if ($xml === false) {
            throw new Exception('Ошибка разбора xml: ' . $filepath);
        }

        // импорт заказов из XML
        $this->importOrders($xml);

        // получаем статистику и ошибки
        $stat = $this->module->stat();
        $errors = $this->module->errors();

        // очищаем сессию
        $this->module->sess(false);

        // статистика и ошибки
        return [$stat, $errors];
    }

    /**
     * Авторизация.
     */
    protected function processAuth(): array|string|null
    {
        // параметры сессии
        return [
            Yii::$app->session->name,
            Yii::$app->session->id
        ];
    }

    /**
     * Инициализация вначале обмена.
     *
     * @throws Exception
     * @throws ErrorException
     */
    protected function processInit(): array|string|null
    {
        // очищаем сессию
        $this->module->sess(false);

        // очищаем директорию обмена
        $this->module->path(false);

        // параметры обмена
        return [
            'zip=' . ($this->module->zipEnabled() ? 'yes' : 'no'),
            'file_limit=' . $this->module->uploadMaxSize()
        ];
    }

    /**
     * Обработка файла.
     *
     * @throws Exception
     * @throws ErrorException
     */
    public function processFile(string $filename, string $content): string|array|null
    {
        $filepath = $this->module->path($filename);
        FileHelper::createDirectory(dirname($filepath));

        $parts = $this->module->progress(self::KEY_FILE . ':' . $filename);

        // дописываем в конец следующую порцию файла или перезаписываем файл первой
        if (file_put_contents($filepath, $content, ! empty($parts) ? FILE_APPEND : 0) === false) {
            $err = error_get_last();
            error_clear_last();
            throw new Exception('Ошибка записи файла: ' . $filepath . ': ' . ($err['message'] ?? ''));
        }

        // сохраняем прогресс частей
        $this->module->progress(self::KEY_FILE . ':' . $filename, $parts + 1);

        return null;
    }

    /**
     * Импорт Свойств.
     *
     * @throws ProgressException
     */
    protected function importProps(SimpleXMLElement $xml): void
    {
        if (! isset($xml->Классификатор->Свойства->Свойство)) {
            return;
        }

        $progress = $this->module->progress(self::KEY_PROP);
        $pos = 0;

        foreach ($xml->Классификатор->Свойства->Свойство as $xmlProp) {
            // проверяем оставшееся время
            if ($this->module->availableTime() < 1) {
                throw new ProgressException('Импорт Свойств..., pos=' . $pos);
            }

            // пропускаем импортированные позиции в прошлом запросе
            $pos++;
            if ($pos <= $progress) {
                continue;
            }

            // импортируем свойство
            try {
                // 1С Ид
                $cid = $this->parseCid((string)$xmlProp->Ид);
                if ($cid === '') {
                    throw new Exception('Отсутствует Ид Свойства');
                }

                // Ид сайта
                $id = $this->importProp($xmlProp);
                if ($id !== null) {
                    $this->module->cache([self::KEY_PROP, $cid], $id);
                    $this->module->stat(self::KEY_PROP);
                }
            } catch (Throwable $ex) {
                $this->module->errors($ex);
            }

            // обновляем прогресс
            $this->module->progress(self::KEY_PROP, $pos);
        }
    }

    /**
     * Импорт Свойства.
     *
     * @param SimpleXMLElement $xmlProp (Классификатор->Свойства->Свойство)
     * @return int|string|array|null идентификатор свойства на сайте
     * @noinspection PhpUnusedParameterInspection
     */
    protected function importProp(SimpleXMLElement $xmlProp): int|array|string|null
    {
        return null;
    }

    /**
     * Импорт Групп.
     *
     * @throws ProgressException
     */
    protected function importGroups(SimpleXMLElement $xml): void
    {
        if (! isset($xml->Классификатор->Группы->Группа)) {
            return;
        }

        // прогресс учитывается только для корневых групп, а время проверяется при импорте дочерних
        $progress = $this->module->progress(self::KEY_GROUP);
        $pos = 0;

        foreach ($xml->Классификатор->Группы->Группа as $xmlGroup) {
            // проверяем позицию на корневых категориях (время учитываем в рекурсивной функции)
            $pos++;
            if ($pos <= $progress) {
                continue;
            }

            try {
                // импортируем рекурсивно (время, кэш и статистика в дочерних)
                $this->importGroupRecursive($xmlGroup, null);
            } catch (ProgressException $ex) {
                // сообщение о лимите времени не перехватываем, добавляем в сообщение номер позиции
                throw new ProgressException('Импорт Групп..., pos=' . $pos, 0, $ex);
            } catch (Throwable $ex) {
                $this->module->errors($ex);
            }

            // обновляем прогресс
            $this->module->progress(self::KEY_GROUP, $pos);
        }
    }

    /**
     * Импорт группы рекурсивно (с дочерними подгруппами).
     *
     * @param SimpleXMLElement $xmlGroup Группа
     * @param int|string|null $parentId ID родительской категории на сайте
     * @throws ProgressException закончилось время
     * @throws Exception ошибка импорта
     */
    protected function importGroupRecursive(SimpleXMLElement $xmlGroup, int|string|null $parentId): void
    {
        // проверяем наличие времени
        if ($this->module->availableTime() < 1) {
            throw new ProgressException('Импорт Групп...');
        }

        // 1C Ид
        $cid = $this->parseCid((string)$xmlGroup->Ид);
        if ($cid === '') {
            throw new Exception('Отсутствует Ид у Группы');
        }

        // импортируем данные группы
        $id = $this->importGroup($xmlGroup, $parentId);
        if ($id !== null) {
            // статистика и кэш
            $this->module->cache([self::KEY_GROUP, $cid], $id);
            $this->module->stat(self::KEY_GROUP);

            // импортируем подгруппы
            if (isset($xmlGroup->Группы->Группа)) {
                foreach ($xmlGroup->Группы->Группа as $xmlChild) {
                    try {
                        // рекурсивный импорт дочерней группы
                        $this->importGroupRecursive($xmlChild, $id);
                    } catch (ProgressException $ex) {
                        // пропускаем исключение времени
                        throw $ex;
                    } catch (Throwable $ex) {
                        $this->module->errors($ex);
                    }
                }
            }
        }
    }

    /**
     * Импорт данных Группы (без рекурсии дочерних !!!)
     *
     * @param int|string $parentId идентификатор на сайте родительской группы
     * @param SimpleXMLElement $xmlGroup (Классификатор->Группы->Группа)
     * @return string|int|null $parentId идентификатор группы на сайте
     * @noinspection PhpUnusedParameterInspection
     */
    protected function importGroup(SimpleXMLElement $xmlGroup, int|string $parentId): int|string|null
    {
        return null;
    }

    /**
     * Импорт Товаров.
     *
     * @throws ProgressException
     */
    protected function importProducts(SimpleXMLElement $xml): void
    {
        if (! isset($xml->Каталог->Товары->Товар)) {
            return;
        }

        // прогресс импорта
        $progress = $this->module->progress(self::KEY_PROD);
        $pos = 0;

        foreach ($xml->Каталог->Товары->Товар as $xmlProd) {
            // проверяем время
            if ($this->module->availableTime() < 1) {
                throw new ProgressException('Импорт Товаров..., pos=' . $pos);
            }

            // пропускаем импортированные в прошлых запросах
            $pos++;
            if ($pos <= $progress) {
                continue;
            }

            // импортируем товар
            try {
                // 1С Ид
                $cid = $this->parseCid((string)$xmlProd->Ид);
                if ($cid === '') {
                    throw new Exception('Отсутствует Ид Товара');
                }

                // импортируем на сайт
                $id = $this->importProduct($xmlProd);
                if ($id !== null) {
                    $this->module->cache([self::KEY_PROD, $cid], $id);
                    $this->module->stat(self::KEY_PROD);
                }
            } catch (Throwable $ex) {
                $this->module->errors($ex);
            }

            // сохраняем прогресс
            $this->module->progress(self::KEY_PROD, $pos);
        }
    }

    /**
     * Импорт товара.
     *
     * @param SimpleXMLElement $xmlProd Товары->Товар
     * @return int|string|null ID импортированного  сайт товара
     * @noinspection PhpUnusedParameterInspection
     */
    protected function importProduct(SimpleXMLElement $xmlProd): int|string|null
    {
        return null;
    }

    /**
     * Импорт Предложений.
     *
     * @throws ProgressException
     */
    protected function importOffers(SimpleXMLElement $xml): void
    {
        if (! isset($xml->ПакетПредложений->Предложения->Предложение)) {
            return;
        }

        // прогресс импорта предложений
        $progress = $this->module->progress(self::KEY_OFFER);
        $pos = 0;

        foreach ($xml->ПакетПредложений->Предложения->Предложение as $xmlOffer) {
            // проверяем остаток времени
            if ($this->module->availableTime() < 1) {
                throw new ProgressException('Импорт Предложений..., pos=' . $pos);
            }

            // пропускаем импортированные в предыдущем запросе
            $pos++;
            if ($pos <= $progress) {
                continue;
            }

            // импортируем
            try {
                $this->importOffer($xmlOffer);
                $this->module->stat(self::KEY_OFFER);
            } catch (Throwable $ex) {
                $this->module->errors($ex);
            }

            // обновляем прогресс
            $this->module->progress(self::KEY_OFFER);
        }
    }

    /**
     * Импорт предложения (остатки и цены).
     *
     * @param SimpleXMLElement $xmlOffer Предложения->Предложение
     */
    protected function importOffer(SimpleXMLElement $xmlOffer): void
    {
        // noop
    }

    /**
     * Удаление старых данных (свойств, групп и товаров) с сайта.
     * Вызывается когда документ СодержитТолькоИзменения="false".
     * Идентификаторы импортированных за сессию данных можно получить в $this->module->stat();
     *
     * @see Module::stat()
     */
    protected function cleanOldData(SimpleXMLElement $xml): void
    {
        // noop
    }

    /**
     * Импорт заказов.
     *
     * @throws ProgressException
     */
    protected function importOrders(SimpleXMLElement $xml): void
    {
        if (! isset($xml->Документ)) {
            return;
        }

        // прогресс импорта
        $progress = $this->module->progress(self::KEY_ORDER);
        $pos = 0;

        foreach ($xml->Документ as $xmlDoc) {
            // проверяем время
            if ($this->module->availableTime() < 1) {
                throw new ProgressException('Импорт заказов..., pos=' . $pos);
            }

            // пропускаем импортированные ранее
            $pos++;
            if ($pos <= $progress) {
                continue;
            }

            // импорт
            try {
                $cid = $this->parseCid((string)$xmlDoc->Ид);
                if (empty($cid)) {
                    throw new Exception('Отсутствует Ид Документа заказа');
                }

                $id = $this->importOrder($xmlDoc);
                if ($id !== null) {
                    $this->module->cache([self::KEY_ORDER, $cid], $id);
                    $this->module->stat(self::KEY_ORDER);
                }
            } catch (Throwable $ex) {
                $this->module->errors($ex);
            }

            // обновляем прогресс
            $this->module->progress(self::KEY_ORDER, $pos);
        }
    }

    /**
     * Импорт заказа.
     *
     * @return string|int|null ID заказа на сайте
     * @noinspection PhpUnusedParameterInspection
     */
    protected function importOrder(SimpleXMLElement $xmlDoc): int|string|null
    {
        return null;
    }

    /**
     * Парсит значение 1C Ид.
     */
    protected function parseCid(?string $cid): ?string
    {
        $cid = (string)$cid;

        return $cid === '' || str_starts_with($cid, C1::ZERO_CID) ? null : $cid;
    }

    /**
     * Парсит дату-время
     *
     * @return ?string форматированная дата
     * @throws Exception ошибка в строке
     */
    protected function parseDatetime(?string $datetime, string $format = 'Y-m-d H:i:s'): ?string
    {
        $datetime = (string)$datetime;
        if ($datetime === '' || str_starts_with($datetime, C1::ZERO_DATE)) {
            return null;
        }

        $time = strtotime($datetime);
        if ($time === false || $time < 0) {
            throw new Exception('Некорректная дата: ' . $datetime);
        }

        return date($format, $time);
    }

    /**
     * Парсит ЗначенияРеквизитов из XML.
     *
     * @return string[] name => val
     */
    protected function parseRequisites(SimpleXMLElement $xml): array
    {
        $reqs = [];

        if (isset($xml->ЗначенияРеквизитов)) {
            $xml = $xml->ЗначенияРеквизитов;
        }

        if (isset($xml->ЗначениеРеквизита)) {
            foreach ($xml->ЗначениеРеквизита as $xmlReq) {
                $reqs[(string)$xmlReq->Наименование] = (string)$xmlReq->Значение;
            }
        }

        return $reqs;
    }

    /**
     * Добавляет ЗначенияРеквизитов в XML.
     *
     * @param string[] $reqs id => val
     */
    protected function exportRequisites(SimpleXMLElement $xml, array $reqs): void
    {
        /** @var SimpleXMLElement $xmlReqs */
        $xmlReqs = $xml->addChild('ЗначенияРеквизитов');

        foreach ($reqs as $name => $val) {
            if ($val !== null && $val !== '') {
                /** @var SimpleXMLElement $xmlReq */
                $xmlReq = $xmlReqs->addChild('ЗначениеРеквизита');
                $xmlReq->Наименование = $name;
                $xmlReq->Значение = $val;
            }
        }
    }
}
