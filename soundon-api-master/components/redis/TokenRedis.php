<?php
/**
 * Created by PhpStorm.
 * User: lilin6
 * Date: 2017/10/18
 * Time: 下午2:48
 */

namespace app\components\redis;

use app\components\RedisClient;

class TokenRedis
{

    public static $redis_client;

    private static $_redis_pool = 'user';

    const USER_MEMBERS_HASH = 8;
    const token_pre = "token:";

    private static function _getInstance()
    {
        if (self::$redis_client instanceof RedisClient) {
            return self::$redis_client;
        }
        self::$redis_client = new RedisClient(self::$_redis_pool);
        return self::$redis_client;
    }

    public static function _hashUserId($user_id)
    {
        return crc32($user_id) % self::USER_MEMBERS_HASH;
    }

    public static function Set($user_id, $token, $expireTs = 259200)//72小时过期
    {
        return self::_getInstance()->setex(self::token_pre . $user_id, $expireTs, $token);
    }

    public static function Get($user_id)
    {
        return self::_getInstance()->get(self::token_pre . $user_id);
    }

    public static function RefreshExpireTime($user_id, $expireTs = 259200)
    {
        return self::_getInstance()->expire(self::token_pre . $user_id, $expireTs);
    }

    /**
     * 设置
     * @param $key
     * @param $data
     * @param int $time
     * @return mixed
     */
    public static function SetCache($key, $data, $time = 10)
    {
        return self::_getInstance()->setex($key, $time, $data);
    }

    /**
     * 获取
     * @param $key
     * @return mixed
     */
    public static function GetCache($key)
    {
        return self::_getInstance()->get($key);
    }

    /**
     * 清除
     * @param $key
     * @return mixed
     */
    public static function ClearCache($key)
    {
        return self::_getInstance()->del($key);
    }

    /**
     * 生成一个access token
     * @param $type
     * @param $user_id
     * @return string
     */
    public static function GetAccessTokenKey($type, $user_id){
        return "AccessToken:$type:$user_id";
    }

}