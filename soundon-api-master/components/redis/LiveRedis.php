<?php


namespace app\components\redis;


use app\components\LiveLogic;
use app\components\RedisClient;

class LiveRedis
{
    public static $redis_client;

    private static $_redis_pool = 'user';

    const USER_MEMBERS_HASH = 8;
    const API_PREFIX = 'live:';

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

    /******live 列表******/
    const LIVE_LIST = 'live:list.';

    //获取缓存的列表key
    public static function getLiveListKey($mode = LiveLogic::LiveModelLiving, $tag_name = '')
    {
        return BroadcastRedis::GetExploreRedisKey($mode);
    }

    /**
     * 添加一个直播到自己的大列表并且加入相应的tag列表
     * @param string $channel_type 直播类型
     * @param string $tag_name
     * @param string $channel_id
     * @param int $order_score
     * @return bool
     */
    public static function addLiveList($channel_type = '', $tag_name = '', $channel_id = '', $order_score = 0)
    {
        self::addBigLiveList($channel_type, $channel_id, $order_score);
        self::addTagLiveList($channel_type, $tag_name, $channel_id, $order_score);
        return true;
    }

    public static function addBigLiveList($channel_type = '', $channel_id = '', $order_score = 0)
    {
        if ($channel_type && $channel_id) {
            $key = self::getLiveListKey($channel_type);
            $order_score = self::getRecoomerRoomeScore($order_score);
            return self::_getInstance()->zadd($key, $order_score, $channel_id);
        }
        return false;
    }

    public static function addTagLiveList($channel_type = '', $tag_name = '', $channel_id = '', $order_score = 0)
    {
        if ($channel_type && $channel_id) {
            $key = self::getLiveListKey($channel_type, $tag_name);
            $order_score = self::getRecoomerRoomeScore($order_score);
            return self::_getInstance()->zadd($key, $order_score, $channel_id);
        }
        return false;
    }

    public static function getChannelOrderScore($mode = '', $channel_id = '')
    {
        if ($channel_id && $mode) {
            $key = self::getLiveListKey($mode);
            return self::_getInstance()->zscore($key, $channel_id);
        }
        return 0;
    }

    /**删除一个直播间，从列表中
     * @param string $channel_type
     * @param string $tag_name
     * @param string $channel_id
     * @return bool
     */
    public static function remLiveList($channel_type = '', $tag_name = '', $channel_id = '')
    {
        self::remBigLiveList($channel_type, $channel_id);
        self::remTagLiveList($channel_type, $tag_name, $channel_id);
        return true;
    }

    public static function remBigLiveList($channel_type = '', $channel_id = '')
    {
        if ($channel_type && $channel_id) {
            $key = self::getLiveListKey($channel_type);
            return self::_getInstance()->zrem($key, $channel_id);
        }
        return false;
    }

    public static function remTagLiveList($channel_type = '', $tag_name = '', $channel_id = '')
    {
        if ($channel_type && $channel_id && $tag_name) {
            $key = self::getLiveListKey($channel_type, $tag_name);
            return self::_getInstance()->zrem($key, $channel_id);
        }
        return false;
    }

    /**
     * 修改一个直播间的tag,需要把它从老列表移动到新列表
     * @param string $channel_type
     * @param string $tag_name
     * @param string $old_tag_name
     * @param string $channel_id
     * @param int $order_score
     * @return bool
     */
    public static function updateTagLiveList($channel_type = '', $tag_name = '', $old_tag_name = '', $channel_id = '', $order_score = 0)
    {
        if ($channel_type && $channel_id) {
            self::remTagLiveList($channel_type, $old_tag_name, $channel_id);
            self::addLiveList($channel_type, $tag_name, $channel_id, $order_score);
            return true;
        }
        return false;
    }

    /**
     * 获取推荐房间的分值，加入时间戳，保证唯一性
     * @param int $order_score
     * @return int
     */
    public static function getRecoomerRoomeScore($order_score = 0)
    {
        if ($order_score > 2000000000) {
            return $order_score + time();
        }
        return $order_score;
    }


    /**
     * 获取直播到大列表或者相应的tag列表
     * @param string $mode
     * @param string $tag_name
     * @param string $since_id
     * @param int $page_size
     * @return array
     */
    public static function getLiveListByScore($mode = '', $tag_name = '', $since_id = '', $page_size = 16)
    {
        if ($mode) {
            $key = self::getLiveListKey($mode, $tag_name);
            if($mode == LiveLogic::LiveModelUpcoming){
                if(empty($since_id)){
                    $since_id = time() - 60 * 60 * 2;//2小时过期时间
                }
                return self::_getInstance()->zrangebyscore($key, $since_id, '+inf', ['limit' => [0, $page_size]]);
            }
            $since_id = $since_id <= 0 ? '+inf' : $since_id;
            $result = self::_getInstance()->zrevrangebyscore($key, $since_id, '-inf', ['limit' => [0, $page_size]]);
            if (!empty($result)) {
                return $result;
            }
        }
        return [];
    }


    /**
     * 添加一个有过期时间的字符串
     * @param string $key
     * @param string $str
     * @param int $expire_time
     */
    public static function setOneExpireTimeStr($key = '', $str = '', $expire_time = 0)
    {
        if ($key) {
            $key = self::API_PREFIX . 'str.' . $key;
            return self::_getInstance()->setex($key, $expire_time, $str);
        }
        return false;
    }

    public static function getOneExpireTimeStr($key = '')
    {
        if ($key) {
            $key = self::API_PREFIX . 'str.' . $key;
            return self::_getInstance()->get($key);
        }
        return false;
    }

    public static function delKey($key = '')
    {
        if ($key) {
            return self::_getInstance()->del($key);
        }
        return false;
    }


    /*******储存直播间family人数****start*******/
    const LIVE_FAMILY_MEMBER_NUMBER = 'broadcast:family:numbers.';

    public static function addBroadcastFamilyNumber($channel_id = 0, $numbers = 0, $param = '')
    {
        if ($channel_id > 0 && $numbers >= 0) {
            $key = self::LIVE_FAMILY_MEMBER_NUMBER . $param;
            return self::_getInstance()->hset($key, $channel_id, $numbers);
        }
        return false;
    }

    public static function getBroadcastFamilyNumber($channel_id = 0, $param = '')
    {
        if ($channel_id > 0) {
            $key = self::LIVE_FAMILY_MEMBER_NUMBER . $param;
            return self::_getInstance()->hget($key, $channel_id);
        }
        return false;
    }
    /*******储存直播间family人数*****end******/

    /**
     * 记录调用的rtc踢人记录
     */
    const RTC_KICK_HISTORY = 'rtc.kick.history:';

    public static function addRtcKickHistory($channel_id, $op_user)
    {
        $key = self::RTC_KICK_HISTORY . $channel_id;
        $time = time();
        return self::_getInstance()->zadd($key, $time, $op_user);
    }

    /*******缓存每个人Livecast大列表****start*******/
    public static function setPersonalLivecastList($user_id, $channel_list)
    {
        // $key = "cache:livecast:uid:$user_id";
        // $seconds = 300;
        // $flat = self::_getInstance()->rpush($key,$channel_list);
        // self::_getInstance()->expire($key, $seconds);
    }
    /*******缓存每个人Livecast大列表****end*******/

}