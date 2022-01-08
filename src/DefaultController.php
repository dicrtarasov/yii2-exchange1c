<?php
/*
 * @copyright 2019-2022 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license BSD-3-Clause
 * @version 08.01.22 16:57:11
 */

declare(strict_types = 1);
namespace dicr\exchange1c;

use Exception;
use SimpleXMLElement;
use Throwable;
use Yii;
use yii\web\BadRequestHttpException;
use yii\web\Controller;
use yii\web\HttpException;
use yii\web\Response;

use function array_merge;
use function implode;

use const YII_DEBUG;

/**
 * DefaultController.
 *
 * @property-read Module $module
 */
class DefaultController extends Controller
{
    /**
     * @inheritDoc
     * Отключаем валидацию POST, так как 1С не использует CSRF
     */
    public $enableCsrfValidation = false;

    /**
     * Обработка запросов от 1С
     *
     * @throws Exception
     */
    public function actionIndex(): Response
    {
        $request = $this->request;
        $type = (string)$request->get('type', '');
        $mode = (string)$request->get('mode', '');

        try {
            return match ($type) {
                'catalog' => match ($mode) {
                    'checkauth' => $this->success($this->module->handler->processCatalogCheckAuth()),
                    'init' => $this->success($this->module->handler->processCatalogInit()),
                    'file' => $this->success($this->module->handler->processCatalogFile(
                        $request->get('filename'), $request->getRawBody()
                    )),
                    'import' => $this->success($this->module->handler->processCatalogImport(
                        $request->get('filename')
                    )),
                    default => throw new BadRequestHttpException('mode: ' . $mode),
                },
                'sale' => match ($mode) {
                    'checkauth' => $this->success($this->module->handler->processSaleCheckAuth()),
                    'init' => $this->success($this->module->handler->processSaleInit()),
                    'query' => $this->xml($this->module->handler->processSaleQuery()),
                    'success' => $this->success($this->module->handler->processSaleSuccess()),
                    'file' => $this->success($this->module->handler->processSaleFile(
                        $request->get('filename'), $request->getRawBody()
                    )),
                    'import' => $this->success($this->module->handler->processSaleImport(
                        $request->get('filename')
                    )),
                    default => throw new BadRequestHttpException('mode: ' . $mode),
                },
                default => throw new BadRequestHttpException('type: ' . $type),
            };
        } catch (HttpException $ex) {
            throw $ex;
        } catch (ProgressException $ex) {
            return $this->progress($ex->getMessage());
        } catch (Throwable $ex) {
            Yii::error($ex, __METHOD__);

            return $this->fail(YII_DEBUG ? (string)$ex : $ex->getMessage());
        }
    }

    /**
     * Ответ Success
     *
     * @param string[]|string|null $data
     */
    private function success(array|string|null $data): Response
    {
        return $this->text(array_merge([C1::SUCCESS], (array)($data ?: [])));
    }

    /**
     * Ответ Failure.
     *
     * @param string[]|string|null $data
     */
    private function fail(array|string|null $data): Response
    {
        return $this->text(array_merge([C1::FAILURE], (array)($data ?: [])));
    }

    /**
     * Ответ Progress.
     *
     * @param string[]|string|null $data
     */
    private function progress(array|string|null $data): Response
    {
        return $this->text(array_merge([C1::PROGRESS], (array)($data ?: [])));
    }

    /**
     * Форматирует ответ как текст.
     *
     * @param string[]|string $content
     */
    private function text(array|string $content): Response
    {
        $content = implode("\n", (array)($content ?: []));
        Yii::debug($content, __METHOD__);

        $res = $this->response;
        $res->format = Response::FORMAT_RAW;
        $res->headers->set('Content-Type', 'text/plain; charset=UTF-8');
        $res->content = $content . "\n";

        return $res;
    }

    /**
     * Форматирование ответа как XML.
     */
    private function xml(SimpleXMLElement|string $xml): Response
    {
        $res = $this->response;
        $res->format = Response::FORMAT_RAW;
        $res->headers->set('Content-Type', 'application/xml; charset=UTF-8');
        $res->content = $xml instanceof SimpleXMLElement ? $xml->asXML() : (string)$xml;

        return $res;
    }
}
