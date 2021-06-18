<?php
/**
 * Created by PhpStorm.
 * User: lilin6
 * Date: 2017/10/18
 * Time: 下午2:48
 */

namespace app\components\redis;

use app\components\RedisClient;

class ApiRedis
{

    public static $redis_client;

    private static $_redis_pool = 'user';

    const USER_MEMBERS_HASH = 8;
    const API_PREFIX = 'api:';

    private static function _getInstance()
    {
        if (self::$redis_client instanceof RedisClient) {
            return self::$redis_client;
        }
        self::$redis_client = new RedisClient(self::$_redis_pool);
        return self::$redis_client;
    }

    public static function GetApiData($key)
    {
        $key = self::API_PREFIX.$key;
        return self::_getInstance()->get($key);
    }
    public static function SetApiData($key,$data,$exprire = 600)
    {
        $key = self::API_PREFIX.$key;
        if(is_array($data)){
            $data = json_encode($data);
        }
        return self::_getInstance()->setex($key,$exprire,$data);
    }


}