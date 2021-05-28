<?php
/*
 * @copyright 2019-2021 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license BSD-3-Clause
 * @version 28.05.21 15:07:27
 */

declare(strict_types = 1);
namespace dicr\exchange1c;

use dicr\http\PersistentCookiesBehavior;
use dicr\http\ResponseCharsetBehavior;
use SimpleXMLElement;
use Yii;
use yii\base\Component;
use yii\base\Exception;
use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;
use yii\httpclient\CurlTransport;
use yii\httpclient\Request;
use yii\web\Cookie;
use ZipArchive;

use function array_shift;
use function basename;
use function extension_loaded;
use function fclose;
use function feof;
use function fopen;
use function fread;
use function is_file;
use function is_string;
use function mb_substr;
use function preg_match;
use function preg_split;
use function simplexml_load_string;
use function sprintf;

use const CURLOPT_ENCODING;
use const CURLOPT_PASSWORD;
use const CURLOPT_USERNAME;

/**
 * Клиент обмена 1С
 *
 * @property-read \yii\httpclient\Client $httpClient
 * @link https://v8.1c.ru/tekhnologii/obmen-dannymi-i-integratsiya/standarty-i-formaty/protokol-obmena-s-saytom/
 */
class Client extends Component
{
    /** @var string адрес для обмена с сайтом */
    public $url;

    /** @var ?string логин сайта */
    public $login;

    /** @var ?string пароль */
    public $password;

    /** @var bool импорт заказов выполняется в sale/file и отсутствует отдельный sale/import */
    public $saleImportInFile = true;

    /** @var ?Cookie кука для авторизации по-умолчанию (обновляет значение с сервера) */
    public $authCookie;

    /** @var bool сервер поддерживает zip-архивы (обновляет значение с сервера) */
    public $zipEnabled = false;

    /** @var int лимит отправляемого файла (обновляет значение с сервера) */
    public $fileLimit = 10 * 1024 * 1024;

    /**
     * @inheritDoc
     * @throws InvalidConfigException
     */
    public function init(): void
    {
        parent::init();

        if (empty($this->url) || ! is_string($this->url)) {
            throw new InvalidConfigException('url');
        }
    }

    /**
     * Авторизация (catalog/checkauth).
     *
     * @return string[] дополнительные данные ответа
     * @throws Exception
     */
    public function requestCatalogCheckAuth(): array
    {
        return $this->requestCheckAuth(C1::TYPE_CATALOG);
    }

    /**
     * Инициализация параметров обмена (catalog/init).
     *
     * @return string[] дополнительные данные ответа
     * @throws Exception
     */
    public function requestCatalogInit(): array
    {
        return $this->requestInit(C1::TYPE_CATALOG);
    }

    /**
     * Отправка файла каталога (catalog/file).
     *
     * @param string $file
     * @return string[] дополнительные данные ответа
     * @throws Exception
     */
    public function requestCatalogFile(string $file): array
    {
        return $this->requestFile(C1::TYPE_CATALOG, $file);
    }

    /**
     * Импорт файла каталога (catalog/import).
     *
     * @param string $file
     * @return string[] дополнительные данные ответа
     * @throws Exception
     */
    public function requestCatalogImport(string $file): array
    {
        return $this->requestImport(C1::TYPE_CATALOG, $file);
    }

    /**
     * Инициализация параметров обмена (sale/init).
     *
     * @return string[] дополнительные данные ответа
     * @throws Exception
     */
    public function requestSaleInit(): array
    {
        return $this->requestInit(C1::TYPE_SALE);
    }

    /**
     * Проверка авторизации (sale/checkauth)
     *
     * @return string[] доп. данные ответа
     * @throws Exception
     */
    public function requestSaleCheckAuth(): array
    {
        return $this->requestCheckAuth(C1::TYPE_SALE);
    }

    /**
     * Запрос заказов с сайта (sale/query)
     *
     * @return SimpleXMLElement
     * @throws Exception
     */
    public function requestSaleQuery(): SimpleXMLElement
    {
        $content = $this->send($this->httpClient->get(
            ['', 'type' => C1::TYPE_SALE, 'mode' => 'query']
        ));

        $xml = simplexml_load_string($content);
        if (empty($xml)) {
            throw new Exception('Ошибка разбора XML ответа: ' . $content);
        }

        return $xml;
    }

    /**
     * Оповещение об успешном получении заказов (sale/success).
     *
     * @return string[]
     * @throws Exception
     */
    public function requestSaleSuccess(): array
    {
        $request = $this->httpClient->get(['', 'type' => C1::TYPE_SALE, 'mode' => 'success']);
        $lines = preg_split('~[\r\n\v]+~um', $this->send($request));

        $status = (string)array_shift($lines);
        if ($status !== C1::SUCCESS) {
            throw new Exception(
                'Статус: ' . $status, 0, new Exception(implode("\n", $lines))
            );
        }

        return $lines;
    }

    /**
     * Загрузка файла заказов на сайт (sale/file).
     * Может сразу выполняться импорт заказов на сайте без команды sale/import.
     *
     * @param string $file
     * @return string[]
     * @throws Exception
     */
    public function requestSaleFile(string $file): array
    {
        return $this->requestFile(C1::TYPE_SALE, $file);
    }

    /**
     * Импорт файла заказов (sale/import).
     *
     * @param string $file
     * @return string[]
     * @throws Exception
     */
    public function requestSaleImport(string $file): array
    {
        // если импорт заказов на сайте выполняется сразу при передаче файла в sale/file,
        // тогда не отправляем команду sale/import
        return $this->saleImportInFile ? [] : $this->requestImport(C1::TYPE_SALE, $file);
    }

    /** @var \yii\httpclient\Client */
    private $_httpClient;

    /**
     * HTTP-клиент.
     *
     * @return \yii\httpclient\Client
     */
    public function getHttpClient(): \yii\httpclient\Client
    {
        if ($this->_httpClient === null) {
            $this->_httpClient = new \yii\httpclient\Client([
                'transport' => CurlTransport::class,
                'requestConfig' => [
                    'options' => [
                        'userAgent' => 'Dicr 1C Exchange Client',
                        'sslVerifyPeer' => false,
                        CURLOPT_ENCODING => '', // все поддерживаемые методы сжатия
                    ]
                ],
                'as cookie' => PersistentCookiesBehavior::class,
                'as charset' => ResponseCharsetBehavior::class
            ]);
        }

        // базовый URL
        $this->_httpClient->baseUrl = $this->url;

        // логин
        $this->login = (string)$this->login;
        if ($this->login !== '') {
            $this->_httpClient->requestConfig['options'][CURLOPT_USERNAME] = $this->login;
        }

        // пароль
        $this->password = (string)$this->password;
        if ($this->password !== '') {
            $this->_httpClient->requestConfig['options'][CURLOPT_PASSWORD] = $this->password;
        }

        return $this->_httpClient;
    }

    /**
     * Отправляет GET-запрос.
     *
     * @param array $params параметры url
     * @return string ответ
     * @throws Exception
     */
    protected function get(array $params): string
    {
        return $this->send($this->httpClient->get([''] + $params));
    }

    /**
     * Отправляет POST-запрос
     *
     * @param array $params параметры url
     * @param string $content тело запроса
     * @return string ответ
     * @throws Exception
     */
    protected function post(array $params, string $content): string
    {
        return $this->send($this->httpClient->post([''] + $params, $content));
    }

    /**
     * Парсит ответ и проверяет статус.
     *
     * @param string $content ответ
     * @param bool $requireSuccess требовать строку 'success' в ответе
     * @return string[] дополнительные данные ответа
     * @throws Exception
     */
    protected function parseStatus(string $content, bool $requireSuccess = true): array
    {
        $lines = preg_split('~[\r\n\v]+~um', $content);
        if ($requireSuccess) {
            $status = array_shift($lines);
            if ($status !== C1::SUCCESS) {
                throw new Exception(
                    'Статус: ' . $status, 0, new Exception(implode("\n", $lines))
                );
            }
        }

        return $lines;
    }

    /**
     * Отправка запроса.
     *
     * @param Request $request
     * @return string текст ответа
     * @throws Exception
     */
    protected function send(Request $request): string
    {
        // добавляем куку авторизации
        if ($this->authCookie !== null) {
            $request->cookies->add($this->authCookie);
        }

        Yii::debug('Запрос: ' . mb_substr($request->toString(), 0, 1024), __METHOD__);
        $response = $request->send();

        Yii::debug('Ответ: ' . $response->toString(), __METHOD__);
        if (! $response->isOk) {
            throw new Exception('HTTP-error: ' . $response->statusCode);
        }

        return $response->content;
    }

    /**
     * Запрос .../checkauth
     *
     * @param string $type тип обмена (catalog, sale)
     * @return string[] дополнительные данные ответа
     * @throws Exception
     */
    protected function requestCheckAuth(string $type): array
    {
        $lines = $this->parseStatus($this->get(['type' => $type, 'mode' => 'checkauth']));

        $cookie = new Cookie([
            'name' => (string)array_shift($lines),
            'value' => (string)array_shift($lines)
        ]);

        if ($cookie->name === '' || $cookie->value === '') {
            throw new Exception('Не удалось получить куку авторизации');
        }

        $this->authCookie = $cookie;

        Yii::debug('Кука авторизации: ' . $cookie->name . '=' . $cookie->value, __METHOD__);

        return $lines;
    }

    /**
     * Запрос .../init
     *
     * @param string $type тип (catalog, sale)
     * @return string[] дополнительные данные ответа
     * @throws Exception
     */
    protected function requestInit(string $type): array
    {
        $lines = $this->parseStatus($this->get(['type' => $type, 'mode' => 'init']), false);

        for ($i = 0; $i < 2; $i++) {
            $line = (string)array_shift($lines);
            $matches = null;
            if (preg_match('~^zip=(yes|no)$~u', $line, $matches)) {
                $this->zipEnabled = $matches[1] === 'yes';
            } elseif (preg_match('~^file_limit=(\d+)$~u', $line, $matches)) {
                $this->fileLimit = (int)$matches[1];
            } else {
                throw new Exception('Ошибка получения параметра обмена: ' . $line);
            }
        }

        Yii::debug(sprintf('Параметры обмена: zip=%s, file_limit=%s',
            $this->zipEnabled ? 'yes' : 'no', $this->fileLimit ?? ''), __METHOD__);

        return $lines;
    }

    /**
     * Отправка файла (.../file)
     *
     * @param string $type тип обмена (catalog, sale)
     * @param string $file
     * @return string[] дополнительные данные ответа
     * @throws Exception
     */
    protected function requestFile(string $type, string $file): array
    {
        if ($file === '') {
            throw new InvalidArgumentException('file');
        }

        if (! is_file($file)) {
            throw new Exception('Файл не найден: ' . $file);
        }

        // сжимаем файл
        $file = $this->zipFile($file);

        // открываем файл
        $f = fopen($file, 'rb');
        if (! $f) {
            throw new Exception('Ошибка открытия файла: ' . $file);
        }

        $lines = [];

        // читаем по частям и отправляем
        while (! feof($f)) {
            $content = fread($f, $this->fileLimit);
            if (empty($content)) {
                throw new Exception('Ошибка чтения файла: ' . $file);
            }

            $lines = $this->parseStatus($this->post(
                ['type' => $type, 'mode' => 'file', 'filename' => basename($file)], $content
            ));
        }

        fclose($f);
        Yii::debug('Отправлен файл: ' . $file, __METHOD__);

        return $lines;
    }

    /**
     * Импорт файла .../import
     *
     * @param string $type тип обмена (catalog, sale)
     * @param string $file
     * @return string[] дополнительные данные ответа
     * @throws Exception
     */
    protected function requestImport(string $type, string $file): array
    {
        if ($file === '') {
            throw new InvalidArgumentException('file');
        }

        // повторяем команду пока статус = progress
        while (true) {
            $content = $this->get(['type' => $type, 'mode' => 'import', 'filename' => basename($file)]);
            $lines = (array)preg_split('~[\r\n\v]+~um', $content);
            $status = array_shift($lines);
            if ($status !== C1::PROGRESS) {
                break;
            }
        }

        if ($status !== C1::SUCCESS) {
            throw new Exception(
                'Статус: ' . $status, 0, new Exception(implode("\n", $lines ?? []))
            );
        }

        Yii::debug('Выполнен импорт файла: ' . $file, __METHOD__);

        return $lines;
    }

    /**
     * Сжимает файл в архив.
     *
     * @param string $file путь файла
     * @return string путь архива или путь исходного файла если zip не поддерживается
     * @throws Exception
     */
    protected function zipFile(string $file): string
    {
        if (! $this->zipEnabled || ! extension_loaded('zip')) {
            return $file;
        }

        $zipFile = $file . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception('Ошибка создания архива: ' . $zipFile);
        }

        if (! $zip->addFile($file, basename($file))) {
            throw new Exception('Ошибка добавления в архив файла: ' . $file);
        }

        if (! $zip->close()) {
            throw new Exception('Ошибка закрытия архива: ' . $zipFile);
        }

        return $zipFile;
    }
}
