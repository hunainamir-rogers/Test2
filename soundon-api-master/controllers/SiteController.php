<?php

namespace app\controllers;

use app\components\Words;
use Yii;
use app\components\BaseController;

class SiteController extends BaseController
{
    public $defaultName;
    public $defaultMessage;

    public function actionIndex()
    {
       $this->success([]);
    }

    public function actionError()
    {
        if (($exception = Yii::$app->getErrorHandler()->exception) === null) {
            // action has been invoked not from error handler, but by direct route, so we display '404 Not Found'
            $exception = new HttpException(404, Yii::t('yii', 'Page not found.'));
        }
        if (isset($exception->statusCode)) {
            if ($exception->statusCode == 404) {
                return $this->error('API Not Found.', 404);
            }
            return $this->error(Words::STSTEMERROR . " code: 31", $exception->statusCode);
        } elseif ($exception->getCode()) {
            return $this->error(Words::STSTEMERROR . " code: 33", $exception->getCode());
        }

        if ($exception instanceof HttpException) {
            $code = $exception->statusCode;
        } else {
            $code = $exception->getCode();
        }
        if ($exception instanceof Exception) {
            $name = $exception->getName();
        } else {
            $name = $this->defaultName ?: Yii::t('yii', 'Error');
        }
        if ($code) {
            $name .= " (#$code)";
        }

        if ($exception instanceof UserException) {
//            $message = $exception->getMessage();
            $message = Words::STSTEMERROR . " code: 52";
        } else {
            $message = $this->defaultMessage ?: Yii::t('yii', 'An internal server error occurred.');
        }
        return $this->error($message);
    }
}
