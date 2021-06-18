<?php


namespace app\components;

use app\components\define\ResponseCode;
use app\components\define\ResponseMessage;
use Yii;
use yii\db\Exception;

class ResponseTool
{
    static $code = ResponseCode::Fail;
    static $message = ResponseMessage::STSTEMERROR;
    static $data = null;

    public static function SetCode($code)
    {
        self::$code = $code;
    }

    public static function SetMessage($message)
    {
        self::$message = $message;
    }

    public static function SetErr($code, $message, $data = null)
    {
        self::$code = $code;
        self::$message = $message;
        if (empty($data)) {
            self::$data = $data;
        }
    }
}