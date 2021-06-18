<?php
/**
 * Created by PhpStorm.
 * User: lilin6
 * Date: 2017/10/18
 * Time: 下午2:48
 */

namespace app\components\redis;

use app\components\RedisClient;

class ModeratorsRedis
{

    public static $redis_client;

    private static $_redis_pool = 'user';

    const USER_MEMBERS_HASH = 8;

    //key
    const key_mute = "moderators:mute:"; // moderators:mute:<channel_id>
    const key_kick = "moderators:kick:"; // moderators:mute:<channel_id>:<user_id>
    const key_block = "moderators:block:"; // moderators:block:<channel_id>

    //kick seconds
    const kick_seconds = 300; //User will go back to Explore and can't go back to the same LS for 5 minutes

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


    /**
     * 获取用户被mute的时间
     * @param $channel_id
     * @param $user_id
     * @return mixed
     */
    public static function GetMuteTime($channel_id, $user_id)
    {
        $key = self::key_mute . $channel_id;
        return self::_getInstance()->zscore($key, $user_id);
    }

    /**
     * 禁言用户
     * @param $channel_id
     * @param $user_id
     * @param int $score
     * @return mixed
     */
    public static function SetMuteTime($channel_id, $user_id, $score = 0)
    {
        $key = self::key_mute . $channel_id;
        if (!$score) {
            $score = time();
        }
        return self::_getInstance()->zadd($key, $score, $user_id);
    }

    /**
     * 取消禁言
     * @param $channel_id
     * @param $user_id
     * @return mixed
     */
    public static function RemoveMuteTime($channel_id, $user_id)
    {
        $key = self::key_mute . $channel_id;
        return self::_getInstance()->zrem($key, $user_id);
    }

    /**
     * 直播结束删除mute key
     * @param $channel_id
     * @return mixed
     */
    public static function DelMuteKey($channel_id)
    {
        $key = self::key_mute . $channel_id;
        return self::_getInstance()->del($key);
    }

    /**
     * 提出直播间用户记录
     * @param $channel_id
     * @param $user_id
     * @param $moderators_user
     * @param int $seconds
     * @return mixed
     */
    public static function SetKick($channel_id, $user_id, $moderators_user, $seconds = 0)
    {
        $key = self::key_kick . $channel_id . ":" . $user_id;
        if (!$seconds) {
            $seconds = self::kick_seconds;
        }
        return self::_getInstance()->setex($key, $seconds, $moderators_user);
    }

    public static function GetKick($channel_id, $user_id)
    {
        $key = self::key_kick . $channel_id . ":" . $user_id;
        return self::_getInstance()->get($key);
    }

    /**
     * 当前直播间block的用户
     * @param $channel_id
     * @param $user_id
     * @return mixed
     */
    public static function Block($channel_id, $user_id){
        $key = self::key_block . $channel_id ;
        return self::_getInstance()->sadd($key, $user_id);
    }
    public static function IsBlock($channel_id, $user_id){
        $key = self::key_block . $channel_id ;
        return self::_getInstance()->SISMEMBER($key, $user_id);
    }
    public static function DelBlock($channel_id){
        $key = self::key_block . $channel_id ;
        return self::_getInstance()->del($key);
    }

}