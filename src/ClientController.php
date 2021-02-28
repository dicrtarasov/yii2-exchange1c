<?php
/*
 * @copyright 2019-2021 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license MIT
 * @version 28.02.21 13:04:30
 */

declare(strict_types = 1);
namespace dicr\exchange1c;

use Yii;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\console\Controller;

use function basename;
use function implode;

/**
 * Консольный клиент exchange1c.
 */
class ClientController extends Controller
{
    /**
     * Отправка файла каталога.
     *
     * @param string $file путь файла
     * @throws Exception
     */
    public function actionCatalogFile(string $file): void
    {
        if ($file === '') {
            throw new Exception('Пустой файл');
        }

        $client = self::client();

        Yii::info('catalog/checkauth', __METHOD__);
        $data = $client->requestCatalogCheckAuth();
        Yii::info("success\n" . implode("\n", $data), __METHOD__);

        Yii::info('catalog/init', __METHOD__);
        $data = $client->requestCatalogInit();
        Yii::info("success\n" . implode("\n", $data), __METHOD__);

        Yii::info('catalog/file: ' . $file, __METHOD__);
        $data = $client->requestCatalogFile($file);
        Yii::info("success\n" . implode("\n", $data), __METHOD__);

        Yii::info('catalog/import: ' . basename($file), __METHOD__);
        $data = $client->requestCatalogImport('import.xml');
        Yii::info("success\n" . implode("\n", $data), __METHOD__);

        Yii::info('Done', __METHOD__);
    }

    /**
     * Получает заказы с сайта.
     *
     * @throws Exception
     */
    public function actionSaleQuery(): void
    {
        $client = self::client();

        Yii::info('sale/checkauth', __METHOD__);
        $data = $client->requestSaleCheckAuth();
        Yii::info("success\n" . implode("\n", $data), __METHOD__);

        Yii::info('sale/query', __METHOD__);
        $xml = $client->requestSaleQuery();
        Yii::info('success', __METHOD__);

        Yii::info('sale/success', __METHOD__);
        $data = $client->requestSaleSuccess();
        Yii::info("success\n" . implode("\n", $data), __METHOD__);

        Yii::info('Done', __METHOD__);

        echo $xml->asXML();
    }

    /**
     * Загрузка заказов на сайт.
     *
     * @param string $file путь файла заказов.
     * @throws Exception
     */
    public function actionSaleFile(string $file): void
    {
        if ($file === '') {
            throw new Exception('Пустой файл');
        }

        $client = self::client();

        Yii::info('sale/checkauth', __METHOD__);
        $data = $client->requestSaleCheckAuth();
        Yii::info("success\n" . implode("\n", $data), __METHOD__);

        Yii::info('sale/init', __METHOD__);
        $data = $client->requestSaleInit();
        Yii::info("success\n" . implode("\n", $data), __METHOD__);

        Yii::info('sale/file: ' . $file, __METHOD__);
        $data = $client->requestSaleFile($file);
        Yii::info("success\n" . implode("\n", $data), __METHOD__);

        Yii::info('sale/import: ' . basename($file), __METHOD__);
        $data = $client->requestSaleImport($file);
        Yii::info("success\n" . implode("\n", $data), __METHOD__);

        Yii::info('Done', __METHOD__);
    }

    /**
     * Exchange Client.
     *
     * @return Client
     * @throws InvalidConfigException
     */
    private static function client(): Client
    {
        return Yii::$app->get('client');
    }
}
