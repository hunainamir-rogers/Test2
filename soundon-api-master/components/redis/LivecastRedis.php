<?php


namespace app\components\redis;


use app\components\RedisClient;

class LivecastRedis
{
    public static $redis_client;
    private static $_redis_pool = 'user';

    private static function _getInstance()
    {
        if (self::$redis_client instanceof RedisClient) {
            return self::$redis_client;
        }
        self::$redis_client = new RedisClient(self::$_redis_pool);
        return self::$redis_client;
    }

    //记录一个livecast直播间在麦位人数
    const LIVE_REMEMBER_SLOT_NUMBER = 'livecast:voice:number';

    public static function rememberVoiceNumber($channel_id = '', $number = 1, $is_use_set = false)
    {
        if ($is_use_set) {
            $result = self::_getInstance()->hset(self::LIVE_REMEMBER_SLOT_NUMBER, $channel_id, $number);
            $result = $number;
        } else {
            $result = self::_getInstance()->hincrby(self::LIVE_REMEMBER_SLOT_NUMBER, $channel_id, $number);
        }
        if ($result < 0 && !$is_use_set) {
            self::_getInstance()->hset(self::LIVE_REMEMBER_SLOT_NUMBER, $channel_id, 0);
            $result = 0;
        }
        return $result;
    }

    public static function getVoiceNumber($channel_id = '')
    {
        $result = self::_getInstance()->hget(self::LIVE_REMEMBER_SLOT_NUMBER, $channel_id);
        return (int)$result;
    }

    public static function delVoiceNumber($channel_id = '')
    {
        return self::_getInstance()->hdel(self::LIVE_REMEMBER_SLOT_NUMBER, $channel_id);
    }

    //开麦人数
    const LIVE_REMEMBER_OPEN_VOICE_NUMBER = 'livecast:openvoice:number';

    public static function rememberOpenVoiceNumber($channel_id = '', $number = 1, $is_use_set = false)
    {
        if ($is_use_set) {
            $result = self::_getInstance()->hset(self::LIVE_REMEMBER_OPEN_VOICE_NUMBER, $channel_id, $number);
            $result = $number;
        } else {
            $result = self::_getInstance()->hincrby(self::LIVE_REMEMBER_OPEN_VOICE_NUMBER, $channel_id, $number);
        }
        if ($result < 0 && !$is_use_set) {
            self::_getInstance()->hset(self::LIVE_REMEMBER_OPEN_VOICE_NUMBER, $channel_id, 0);
            $result = 0;
        }
        return $result;
    }

    public static function getOpenVoiceNumber($channel_id = '')
    {
        $result = self::_getInstance()->hget(self::LIVE_REMEMBER_OPEN_VOICE_NUMBER, $channel_id);
        return (int)$result;
    }

    public static function delOpenVoiceNumber($channel_id = '')
    {
        return self::_getInstance()->hdel(self::LIVE_REMEMBER_OPEN_VOICE_NUMBER, $channel_id);
    }


    /********记录用户最近一次livecast进的房间**********/
    const REMEMBER_LATELY_JOIN = 'livecast:join:lately:';

    public static function rememberUserLatelyJoinRoom($user_id = '', $channel_id = '')
    {
        if ($user_id && $channel_id) {
            return self::_getInstance()->setex(self::REMEMBER_LATELY_JOIN . $user_id, 2592000, $channel_id);
        }
        return false;
    }

    public static function getUserLatelyJoinRoom($user_id = '')
    {
        if ($user_id) {
            return self::_getInstance()->get(self::REMEMBER_LATELY_JOIN . $user_id);
        }
        return '';
    }

    public static function delUserLatelyJoinRoom($user_id = '')
    {
        if ($user_id) {
            return self::_getInstance()->del(self::REMEMBER_LATELY_JOIN . $user_id);
        }
        return '';
    }
    /********记录用户最近一次livecast进的房间*end*********/


    /********记录直播间用户上麦被推送记录*start*********/
    const REMEMBER_DAY_PUSH_INFO = 'livecast:daypush:';

    public static function getDayPushInfoKey($channel_id)
    {
        return self::REMEMBER_DAY_PUSH_INFO . $channel_id . '.' . date('Ymd');
    }

    public static function addDayPushInfoInfo($user_id = '', $channel_id = '')
    {
        $key = self::getDayPushInfoKey($channel_id);
        $is_exist = self::_getInstance()->exists($key);
        self::_getInstance()->hset($key, $user_id, time());
        if (!$is_exist) {
            self::_getInstance()->expire($key, 86400);
        }
        return true;
    }

    public static function isInDayPushInfoInfo($user_id = '', $channel_id = '')
    {
        $key = self::getDayPushInfoKey($channel_id);
        return self::_getInstance()->hexists($key, $user_id);
    }
    /********记录直播间用户上麦被推送记录*end*********/

    /********记录一个用户在没在一个直播间上过麦记录*start**（此用于一个直播间发送后就可以不用再发送）*******/
    const REMEMBER_USER_LIVE_DAY_PUSH_INFO = 'livecast:user:daypush:';

    public static function getUserDayPushInfoKey($user_id = '')
    {
        return self::REMEMBER_DAY_PUSH_INFO . $user_id . '.' . date('Ymd');
    }

    public static function addUserDayPushInfoInfo($user_id = '', $channel_id = '')
    {
        $key = self::getUserDayPushInfoKey($user_id);
        $is_exist = self::_getInstance()->exists($key);
        self::_getInstance()->hset($key, $channel_id, time());
        if (!$is_exist) {
            self::_getInstance()->expire($key, 86400);
        }
        return true;
    }

    public static function isInUserDayPushInfoInfo($user_id = '', $channel_id = '')
    {
        $key = self::getUserDayPushInfoKey($user_id);
        return self::_getInstance()->hexists($key, $channel_id);
    }
    /********记录一个用户的好友直播间上麦每日被推送记录*end*********/

    /**一段时间发送一个800，让其短线的用户拉去接口来刷新直播间槽位信息*s**/
    const REMEMBER_LIVECAST_SLOT_CHANGE_RTM = 'livecast:slot:change:';

    public static function setLivecastSlotChange($channel_id = '')
    {
        $key = self::REMEMBER_LIVECAST_SLOT_CHANGE_RTM . $channel_id;
        return self::_getInstance()->setex($key, 20, $channel_id);
    }

    public static function getLivecastSlotChange($channel_id = '')
    {
        if (!empty($channel_id)) {
            $key = self::REMEMBER_LIVECAST_SLOT_CHANGE_RTM . $channel_id;
            return self::_getInstance()->get($key);
        }
        return false;
    }
    /**一段时间发送一个800，让其短线的用户拉去接口来刷新直播间槽位信息*e**/


}