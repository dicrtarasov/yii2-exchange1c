<?php
/*
 * @copyright 2019-2021 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license MIT
 * @version 27.02.21 22:53:17
 */

declare(strict_types = 1);
namespace dicr\exchange1c;

use SimpleXMLElement;
use Throwable;
use Yii;
use yii\base\BaseObject;
use yii\base\Exception;
use yii\base\NotSupportedException;

use function date;
use function error_clear_last;
use function error_get_last;
use function file_get_contents;
use function file_put_contents;
use function is_file;
use function simplexml_load_string;
use function strpos;
use function strtotime;

use const FILE_APPEND;

/**
 * Базовый обработчик обмена 1С.
 */
abstract class BaseHandler extends BaseObject implements Handler
{
    /** @var Module */
    protected $module;

    /**
     * AbstractExchangeHandler constructor.
     *
     * @param Module $module
     * @param array $config
     */
    public function __construct(Module $module, array $config = [])
    {
        $this->module = $module;

        parent::__construct($config);
    }

    /**
     * @inheritDoc
     */
    public function processCatalogCheckAuth()
    {
        return $this->processAuth();
    }

    /**
     * @inheritDoc
     */
    public function processCatalogInit(): array
    {
        return $this->processInit();
    }

    /**
     * @inheritDoc
     */
    public function processCatalogFile(string $filename, string $content)
    {
        return $this->processFile($filename, $content);
    }

    /**
     * @inheritDoc
     */
    public function processCatalogImport(string $filename)
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

        // очищаем сессию, так как она очень большая и будет брошена 1С
        $this->module->sess(false);

        // статистика и ошибки
        return [
            $this->module->stat(),
            $this->module->errors()
        ];
    }

    /**
     * @inheritDoc
     */
    public function processSaleCheckAuth()
    {
        return $this->processAuth();
    }

    /**
     * @inheritDoc
     */
    public function processSaleInit()
    {
        return $this->processInit();
    }

    /**
     * @inheritDoc
     */
    public function processSaleQuery()
    {
        throw new NotSupportedException('Не реализовано');
    }

    /**
     * @inheritDoc
     */
    public function processSaleSuccess()
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function processSaleFile(string $filename, string $content)
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
     */
    public function processSaleImport(string $filename)
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

        // статистика и ошибки
        return [
            $this->module->stat(),
            $this->module->errors()
        ];
    }

    /**
     * Авторизация.
     *
     * @return string|string[]|null
     */
    protected function processAuth(): array
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
     * @return string|string[]|null
     * @throws Exception
     */
    protected function processInit(): array
    {
        // очищаем сессию
        $this->module->sess(false);

        // очищаем директорию обмена
        $this->module->path(false);

        // параметры обмена
        return [
            'zip=' . $this->module->zipEnabled(),
            'file_limit=' . $this->module->uploadMaxSize()
        ];
    }

    /**
     * Обработка файла.
     *
     * @param string $filename
     * @param string $content
     * @return string|string[]|null
     * @throws Exception
     */
    public function processFile(string $filename, string $content)
    {
        $filepath = $this->module->path($filename);
        $parts = $this->module->progress($filename);

        // дописываем в конец следующую порцию файла или перезаписываем файл первой
        if (file_put_contents($filepath, $content, ! empty($parts) ? FILE_APPEND : 0) === false) {
            $err = error_get_last();
            error_clear_last();
            throw new Exception('Ошибка записи файла: ' . $filepath . ': ' . ($err['message'] ?? ''));
        }

        // сохраняем прогресс частей
        $this->module->progress($filename, $parts + 1);

        return null;
    }

    /**
     * Импорт Свойств.
     *
     * @param SimpleXMLElement $xml
     * @throws ProgressException
     */
    protected function importProps(SimpleXMLElement $xml): void
    {
        if (! isset($xml->Классификатор->Свойства->Свойство)) {
            return;
        }

        $progress = $this->module->progress('prop');
        $pos = 0;

        foreach ($xml->Классификатор->Свойства->Свойство as $xmlProp) {
            // проверяем оставшееся время
            if ($this->module->availableTime() < 1) {
                throw new ProgressException('Импорт Свойств...');
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
                    $this->module->cache(['prop', $cid], $id);
                    $this->module->stat('prop');
                }
            } catch (Throwable $ex) {
                $this->module->errors($ex);
            }

            // обновляем прогресс
            $this->module->progress('prop', $pos);
        }
    }

    /**
     * Импорт Свойства.
     *
     * @param SimpleXMLElement $xmlProp (Классификатор->Свойства->Свойство)
     * @return int|string|null идентификатор свойства на сайте
     * @noinspection PhpUnusedParameterInspection
     */
    protected function importProp(SimpleXMLElement $xmlProp)
    {
        return null;
    }

    /**
     * Импорт Групп.
     *
     * @param SimpleXMLElement $xml
     * @throws ProgressException
     */
    protected function importGroups(SimpleXMLElement $xml): void
    {
        if (! isset($xml->Классификатор->Группы->Группа)) {
            return;
        }

        // прогресс учитывается только для корневых групп, а время проверяется при импорте дочерних
        $progress = $this->module->progress('group');
        $pos = 0;

        foreach ($xml->Группы->Группа as $xmlGroup) {
            // проверяем позицию на корневых категориях (время учитываем в рекурсивной функции)
            $pos++;
            if ($pos <= $progress) {
                continue;
            }

            try {
                // импортируем рекурсивно (время, кэш и статистика в дочерних)
                $this->importGroupRecursive($xmlGroup, null);
            } catch (ProgressException $ex) {
                // сообщение о лимите времени не перехватываем
                throw $ex;
            } catch (Throwable $ex) {
                $this->module->errors($ex);
            }

            // обновляем прогресс
            $this->module->progress('group', $pos);
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
    protected function importGroupRecursive(SimpleXMLElement $xmlGroup, $parentId): void
    {
        // проверяем наличие времени
        if ($this->module->availableTime() < 1) {
            throw new ProgressException('Импорт Групп ...');
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
            $this->module->cache(['group', $cid], $id);
            $this->module->stat('group');

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
     * @param string|int $parentId идентификатор на сайте родительской группы
     * @param SimpleXMLElement $xmlGroup (Классификатор->Группы->Группа)
     * @return string|int|null $parentId идентификатор группы на сайте
     * @noinspection PhpUnusedParameterInspection
     */
    protected function importGroup(SimpleXMLElement $xmlGroup, $parentId)
    {
        return null;
    }

    /**
     * Импорт Товаров.
     *
     * @param SimpleXMLElement $xml
     * @throws ProgressException
     */
    protected function importProducts(SimpleXMLElement $xml): void
    {
        if (! isset($xml->Каталог->Товары->Товар)) {
            return;
        }

        // прогресс импорта
        $progress = $this->module->progress('prod');
        $pos = 0;

        foreach ($xml->Каталог->Товары->Товар as $xmlProd) {
            // проверяем время
            if ($this->module->availableTime() < 1) {
                throw new ProgressException('Импорт Товаров ...');
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
                    $this->module->cache(['prod', $cid], $id);
                    $this->module->stat('prod');
                }
            } catch (Throwable $ex) {
                $this->module->errors($ex);
            }

            // сохраняем прогресс
            $this->module->progress('prod', $pos);
        }
    }

    /**
     * Импорт товара.
     *
     * @param SimpleXMLElement $xmlProd Товары->Товар
     * @return int|string|null ID импортированного  сайт товара
     * @noinspection PhpUnusedParameterInspection
     */
    protected function importProduct(SimpleXMLElement $xmlProd)
    {
        return null;
    }

    /**
     * Импорт Предложений.
     *
     * @param SimpleXMLElement $xml
     * @throws ProgressException
     */
    protected function importOffers(SimpleXMLElement $xml): void
    {
        if (! isset($xml->ПакетПредложений->Предложения->Предложение)) {
            return;
        }

        // прогресс импорта предложений
        $progress = $this->module->progress('offer');
        $pos = 0;

        foreach ($xml->ПакетПредложений->Предложения->Предложение as $xmlOffer) {
            // проверяем остаток времени
            if ($this->module->availableTime() < 1) {
                throw new ProgressException('Импорт Предложений...');
            }

            // пропускаем импортированные в предыдущем запросе
            $pos++;
            if ($pos <= $progress) {
                continue;
            }

            // импортируем
            try {
                $this->importOffer($xmlOffer);
                $this->module->stat('offer');
            } catch (Throwable $ex) {
                $this->module->errors($ex);
            }

            // обновляем прогресс
            $this->module->progress('offer');
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
     * Идентификаторы импортированных за сессию данных можно получить в $this->module::stat();
     *
     * @param SimpleXMLElement $xml документ
     * @see Module::stat()
     */
    protected function cleanOldData(SimpleXMLElement $xml): void
    {
        // noop
    }

    /**
     * Импорт заказов.
     *
     * @param SimpleXMLElement $xml XML
     * @throws ProgressException
     */
    protected function importOrders(SimpleXMLElement $xml): void
    {
        if (! isset($xml->Документ)) {
            return;
        }

        // прогресс импорта
        $progress = $this->module->progress('order');
        $pos = 0;

        foreach ($xml->Документ as $xmlDoc) {
            // проверяем время
            if ($this->module->availableTime() < 1) {
                throw new ProgressException('Импорт заказов...');
            }

            // пропускаем импортированные ранее
            $pos++;
            if ($pos <= $progress) {
                continue;
            }

            // импорт
            try {
                $cid = $this->parseCid((string)$xml->Ид);
                if (empty($cid)) {
                    throw new Exception('Отсутствует Ид Документа заказа');
                }

                $id = $this->importOrder($xmlDoc);
                if ($id !== null) {
                    $this->module->cache(['order', $cid], $id);
                    $this->module->stat('order');
                }
            } catch (Throwable $ex) {
                $this->module->errors($ex);
            }

            // обновляем прогресс
            $this->module->progress('order', $pos);
        }
    }

    /**
     * Импорт заказа.
     *
     * @param SimpleXMLElement $xmlDoc Документ
     * @return string|int|null ID заказа на сайте
     * @noinspection PhpUnusedParameterInspection
     */
    protected function importOrder(SimpleXMLElement $xmlDoc)
    {
        return null;
    }

    /**
     * Парсит значение 1C Ид.
     *
     * @param string|null $cid
     * @return string|null
     */
    protected function parseCid(?string $cid): ?string
    {
        $cid = (string)$cid;

        return $cid === '' || strpos($cid, C1::ZERO_CID) === 0 ? null : $cid;
    }

    /**
     * Парсит дату-время
     *
     * @param string|null $datetime строка
     * @param string $format формат
     * @return ?string форматированная дата
     * @throws Exception ошибка в строке
     */
    protected function parseDatetime(?string $datetime, string $format = 'Y-m-d H:i:s'): ?string
    {
        $datetime = (string)$datetime;
        if ($datetime === '' || strpos($datetime, C1::ZERO_DATE) === 0) {
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
     * @param SimpleXMLElement $xml
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
     * @param SimpleXMLElement $xml
     * @param string[] $reqs id => val
     */
    protected function exportRequisites(SimpleXMLElement $xml, array $reqs): void
    {
        $xmlReqs = $xml->addChild('ЗначенияРеквизитов');

        foreach ($reqs as $name => $val) {
            if ($val !== null && $val !== '') {
                $xmlReq = $xmlReqs->addChild('ЗначениеРеквизита');
                $xmlReq->Наименование = $name;
                $xmlReq->Значение = $val;
            }
        }
    }
}
