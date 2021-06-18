<?php
/**
 * Created by PhpStorm.
 * User: lilin6
 * Date: 2017/10/18
 * Time: 下午2:48
 */

namespace app\components\redis;

use app\components\RedisClient;

class QueueRedis
{

    public static $redis_client;

    private static $_redis_pool = 'user';
    const queuePreKey = "queue:";

    private static function _getInstance()
    {
        if (self::$redis_client instanceof RedisClient) {
            return self::$redis_client;
        }
        self::$redis_client = new RedisClient(self::$_redis_pool);
        return self::$redis_client;
    }

    /**
     * 数据塞队列里
     * @param $queueName
     * @param $data
     * @return mixed
     */
    public static function Lpush($queueName, $data){
        $key = self::queuePreKey . $queueName;
        return self::_getInstance()->lpush($key, json_encode($data));
    }

    /**
     * 从队列取数据
     * @param $queueName
     * @return mixed
     */
    public static function lpop($queueName){
        $key = self::queuePreKey . $queueName;
        $data = self::_getInstance()->lpop($key);
        if(empty($data)){
            return  false;
        }
        return json_decode($data, true);
    }

    public static function publish($queueName){
        $key = self::queuePreKey . $queueName;
        return self::_getInstance()->publish($key, "ok");
    }
    public static function sub($callback){
        return self::_getInstance()->psubscribe(self::queuePreKey . "*", $callback);
    }

}