<?php
/*
 * @copyright 2019-2021 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license MIT
 * @version 28.02.21 16:02:11
 */

declare(strict_types = 1);
namespace dicr\exchange1c;

use Throwable;
use Yii;
use yii\base\ErrorException;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\di\Instance;
use yii\helpers\ArrayHelper;
use yii\helpers\FileHelper;
use ZipArchive;

use function constant;
use function count;
use function date;
use function extension_loaded;
use function glob;
use function implode;
use function in_array;
use function ini_get;
use function is_array;
use function is_scalar;
use function is_string;
use function mb_strpos;
use function preg_match;
use function rename;
use function set_time_limit;
use function sprintf;
use function strtolower;
use function time;

use const PHP_INT_MAX;

/**
 * Модуль обмена заказами.
 */
class Module extends \yii\base\Module
{
    /** @var bool вызывать sale/import при обработке sale/file (некоторые 1С не отправляют sale/import) */
    public $saleImportInFile = true;

    /** @var string путь для временных файлов */
    public $path = '@runtime/exchange1c';

    /** @var Handler */
    public $handler;

    /** @var int лимит количества ошибок в статистике */
    public $errorsLimit = 100;

    /** @inheritDoc */
    public $controllerNamespace = __NAMESPACE__;

    /**
     * @inheritDoc
     * @throws Exception
     */
    public function init(): void
    {
        parent::init();

        // путь
        $this->path = Yii::getAlias($this->path);
        if (empty($this->path)) {
            throw new InvalidConfigException('path');
        }

        FileHelper::createDirectory($this->path);

        // handler
        if (is_string($this->handler)) {
            $this->handler = Instance::ensure($this->handler, Handler::class);
        } elseif (is_array($this->handler)) {
            $this->handler = Yii::createObject($this->handler, [$this]);
        } elseif (! $this->handler instanceof Handler) {
            throw new InvalidConfigException('handler');
        }

        @set_time_limit(0);
    }

    /**
     * Проверяет доступно ли расширение zip.
     *
     * @return bool
     * @noinspection PhpMethodMayBeStaticInspection
     */
    public function zipEnabled(): bool
    {
        return extension_loaded('zip');
    }

    /**
     * Распаковывает zip-файлы во временной директории.
     */
    public function extractZipFiles(): void
    {
        foreach (glob($this->path . '/*.zip') as $file) {
            // распаковываем zip
            $zip = new ZipArchive();
            if ($zip->open($file) !== true) {
                $this->errors('Ошибка открытия zip-архива: ' . $file);
                continue;
            }

            $res = $zip->extractTo($this->path);
            $zip->close();

            if ($res !== true) {
                $this->errors('Ошибка распаковки файла: ' . $file);
            } else {
                /** @noinspection PhpUsageOfSilenceOperatorInspection */
                @rename($file, $file . date('ymdHis'));
            }
        }
    }

    /**
     * Максимальный размер файла загрузки.
     *
     * @return int
     * @throws Exception
     * @noinspection PhpMethodMayBeStaticInspection
     */
    public function uploadMaxSize(): int
    {
        static $size;

        if ($size === null) {
            $size = ini_get('upload_max_filesize');

            $matches = null;
            if (! preg_match('~^(\d+)([kmg])?$~i', $size, $matches)) {
                throw new Exception('Некорректное значение настройки upload_max_filesize: ' . $size);
            }

            $size = (int)$matches[1];

            if (isset($matches[2])) {
                $m = strtolower($matches[2]);
                if ($m === 'k') {
                    $size *= 1024;
                } elseif ($m === 'm') {
                    $size *= 1024 * 1024;
                } elseif ($m === 'g') {
                    $size *= 1024 * 1204 * 1204;
                }
            }
        }

        return $size;
    }

    /**
     * Доступное для работы время.
     *
     * @return int время, секунд
     * @noinspection PhpMethodMayBeStaticInspection
     */
    public function availableTime(): int
    {
        static $endTime;

        if ($endTime === null) {
            $endTime = 0;

            $startTime = ($_SERVER['REQUEST_TIME'] ?? 0) ?: (int)constant('YII_BEGIN_TIME');
            if (empty($startTime)) {
                Yii::error('Ошибка определения начального времени', __METHOD__);
            } else {
                $maxTime = (int)ini_get('max_execution_time');
                if (! empty($maxTime)) {
                    $endTime = $startTime + $maxTime;
                }
            }
        }

        return empty($endTime) ? PHP_INT_MAX : $endTime - time();
    }

    /**
     * Получить абсолютный путь файла/очистить директорию обмена.
     *
     * @param string|false $file
     * @return string
     * @throws Exception
     * @throws ErrorException
     */
    public function path($file): string
    {
        if ($file === false) {
            FileHelper::removeDirectory($this->path);
            FileHelper::createDirectory($this->path);

            return $this->path;
        }

        $path = FileHelper::normalizePath($this->path . '/' . $file);
        if (mb_strpos($path, $this->path) !== 0) {
            throw new Exception('Путь "' . $path . '" не находится в папке обмена "' . $this->path . '"');
        }

        return $path;
    }

    /**
     * Устанавливает/возвращает/очищает параметры в сессии.
     *
     * @param array|false|null $sess параметры сессии для установки, null для получения или false для очистки
     * @return array текущие параметры сессии
     */
    public function sess($sess = null): array
    {
        if ($sess === null) {
            $sess = Yii::$app->session->get(__CLASS__, []);
        } else {
            if (empty($sess)) {
                $sess = [];
            }

            Yii::$app->session->set(__CLASS__, $sess);
        }

        return $sess;
    }

    /**
     * Получить/изменить/очистить статистику в сессии.
     *
     * @param string|false|null $key string увеличивает счетчик, false сбрасывает статистику, null - возвращает строку
     * @return int|string новое значение счетчика или строка статистики
     */
    public function stat($key = null)
    {
        // получаем сессию
        $sess = $this->sess();
        if (empty($sess['stat'])) {
            $sess['stat'] = [];
        }

        // при null возвращаем строку статистики
        if ($key === null) {
            // формируем строковое значение
            $ret = [];

            foreach ($sess['stat'] as $i => $val) {
                if (is_scalar($val)) {
                    $ret[] = sprintf('%s=%s', $i, $val);
                } elseif (is_array($val)) {
                    $vals = [];
                    foreach ($val as $k => $v) {
                        $vals[] = sprintf('%s=%d', $k, $v);
                    }

                    $ret[] = sprintf('%s[%s]', $i, implode(',', $vals));
                }
            }

            return implode(',', $ret);
        }

        // сброс статистики
        if ($key !== false) {
            $key = (string)$key;

            // получаем следующее значение
            $ret = (int)($sess['stat'][$key] ?? 0);

            // обновляем значение статистики
            $ret++;
            $sess['stat'][$key] = $ret;
        } else {
            $sess['stat'] = [];
            $ret = '';
        }

        // сохраняем сессию
        $this->sess($sess);

        return $ret;
    }

    /**
     * Добавить/получить/очистить ошибки, хранящиеся в сессии.
     *
     * @param Throwable|string|false|null $error
     * @return string текущая ошибка или все ошибки
     */
    public function errors($error = null): string
    {
        $sess = $this->sess();
        if (empty($sess['errors'])) {
            $sess['errors'] = [];
        }

        // получить ошибки
        if ($error === null) {
            return implode("\n", $sess['errors']);
        }

        if ($error !== false) {
            // конвертируем исключения в строку
            $errStr = $error instanceof Throwable ? $error->getMessage() : (string)$error;

            // добавляем, исключая повтора
            if (! in_array($errStr, $sess['errors'], true)) {
                // ограничиваем до адекватных размеров
                if (empty($this->errorsLimit) || count($sess['errors']) < $this->errorsLimit) {
                    $sess['errors'][] = $errStr;
                }

                Yii::error($error, __METHOD__);
            }

            $ret = $errStr;
        } else {
            $sess['errors'] = '';
            $ret = '';
        }

        // сохраняем сессию
        $this->sess($sess);

        return $ret;
    }

    /**
     * Установить/получить/очистить сессионный кэш.
     *
     * @param array|false $key ключ или false для очистки
     * @param mixed $val значение для установки или null для получения
     * @return mixed текущее значение
     */
    public function cache($key, $val = null)
    {
        // получаем сессию
        $sess = $this->sess();
        if (empty($sess['cache'])) {
            $sess['cache'] = [];
        }

        if ($key === false) {
            // очистка кэша
            $sess['cache'] = [];
            $this->sess($sess);

            return null;
        }

        if ($val !== null) {
            // установка кэша
            ArrayHelper::setValue($sess['cache'], $key, $val);
            $this->sess($sess);

            return $val;
        }

        // получаем значение
        try {
            $val = ArrayHelper::getValue($sess['cache'], $key);
        } catch (Throwable $ex) {
            Yii::error($ex, __METHOD__);
        }

        return $val;
    }

    /**
     * Возвращает/устанавливает позицию прогресса в сессии.
     *
     * @param string|false|null $key ключ процесса
     * @param int|null $pos новая позиция
     * @return int|array
     */
    public function progress($key, ?int $pos = null)
    {
        // получаем сессию
        $sess = $this->sess();
        if (empty($sess['progress'])) {
            $sess['progress'] = [];
        }

        if ($key === null) {
            return $sess['progress'];
        }

        if ($key === false) {
            $sess['progress'] = [];
            $this->sess($sess);

            return [];
        }

        $key = (string)$key;

        if ($pos !== null) {
            $sess['progress'][$key] = $pos;
            $this->sess($sess);

            return $pos;
        }

        return (int)($sess['progress'][$key] ?? 0);
    }
}
