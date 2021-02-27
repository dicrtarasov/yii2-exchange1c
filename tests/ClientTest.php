<?php
/*
 * @copyright 2019-2021 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license MIT
 * @version 27.02.21 22:55:06
 */

declare(strict_types = 1);
namespace dicr\tests;

use dicr\exchange1c\Client;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\base\Exception;
use yii\base\InvalidConfigException;

/**
 * Class TariffRequestTest
 */
class ClientTest extends TestCase
{
    /**
     * Модуль.
     *
     * @return Client
     * @throws InvalidConfigException
     */
    private static function client(): Client
    {
        return Yii::$app->get('client');
    }

    /**
     * @throws Exception
     * @noinspection PhpUnitMissingTargetForTestInspection
     */
    public function testCatalog(): void
    {
        $client = self::client();

        $data = $client->requestCatalogCheckAuth();
        self::assertIsArray($data);
    }
}
