<?php
/**
 * Created by PhpStorm.
 * User: lilin6
 * Date: 2017/10/18
 * Time: 下午2:48
 */

namespace app\components\redis;

use app\components\LiveLogic;
use app\components\RedisClient;
use app\components\Version;

class CacheRedis {

    public static $redis_client;

    private static $_redis_pool = 'user';

    const USER_MEMBERS_HASH = 8;

    const CACHE_BROADCAST_HOT_CHANNEL_KEY = "cache:broadcast:hot:channel";
    const CACHE_GROUP_MEMBER_LIST_INFO = "cache:group:member:list:info:";
    const CACHE_BROADCAST_MODERATOR_MA = "cache:broadcast:moderator:ma:";
    const CACHE_BROADCAST_GROUP_LIMIT = "cache:broadcast:group:limit";
    const KeyVisitorInfo = 'cache:visitor:ip';
    // 访问者访问频率 Key Prefix
    const KeyVisitRate = 'cache:visit:rate:';
    // 被拉黑IP Key Prefix
    const KeyVisitBlock = 'cache:visit:blocked:';
    const KeyBlockedCountryList = 'cache:blocked:country:list';
    const KeyCampaignAsapContestGiftList = 'cache:campaign:asap:contest:gift:';

    private static function _getInstance() {
        if (self::$redis_client instanceof RedisClient) {
            return self::$redis_client;
        }
        self::$redis_client = new RedisClient(self::$_redis_pool);
        return self::$redis_client;
    }

    public static function _hashUserId($user_id) {
        return crc32($user_id) % self::USER_MEMBERS_HASH;
    }

    public static function SetCacheMyMission($user_id, $data, $time = 60)
    {
        $key = "cache:my:mission:" . $user_id;
        return self::_getInstance()->setex($key, $time, $data);
    }

    public static function GetCacheMyMission($user_id)
    {
        $key = "cache:my:mission:" . $user_id;
        return self::_getInstance()->get($key);
    }
    public static function delCacheMyMission($user_id)
    {
        $key = "cache:my:mission:" . $user_id;
        return self::_getInstance()->del($key);
    }


    public static function SetCacheHotChannel($value, $score)
    {
        $key = self::CACHE_BROADCAST_HOT_CHANNEL_KEY;
        return self::_getInstance()->zadd($key, $score, $value);
    }

    public static function getCacheHotChannel($start = 0, $stop = -1)
    {
        $key = self::CACHE_BROADCAST_HOT_CHANNEL_KEY;
        return self::_getInstance()->zrevrange($key, $start, $stop);
    }

    public static function expireCacheHotChannel($key, $expireSeconds)
    {
        return self::_getInstance()->expire($key, $expireSeconds);
    }

    /**
     * 设置cache
     * @param $key
     * @param $data
     * @param int $time
     * @return mixed
     */
    public static function SetCache($key, $data, $time = 10)
    {
        $cache = json_encode($data);
        return self::_getInstance()->setex($key, $time, $cache);
    }

    /**
     * 获取cache
     * @param $key
     * @return mixed
     */
    public static function GetCache($key)
    {
        $cacheData = self::_getInstance()->get($key);
        return json_decode($cacheData, true);
    }

    /**
     * 清除cache
     * @param $key
     * @return mixed
     */
    public static function ClearCache($key)
    {
        return self::_getInstance()->del($key);
    }

    //给群直播加锁
    public static function addGroupBroadcastLockCache($group_id) {
        $key = CacheRedis::CACHE_BROADCAST_GROUP_LIMIT . '-group_id:' . $group_id;
        return self::_getInstance()->set($key,1,['NX','EX' => 5]);
    }

    //给群直播删除锁
    public static function deleteGroupBroadcastLockCache($group_id) {
        $key = CacheRedis::CACHE_BROADCAST_GROUP_LIMIT . '-group_id:' . $group_id;
        return self::_getInstance()->del($key);
    }

    //判断群直播锁是否存在
    public static function isGroupBroadcastLockCacheExists($group_id) {
        $key = CacheRedis::CACHE_BROADCAST_GROUP_LIMIT . '-group_id:' . $group_id;
        return self::_getInstance()->exists($key);
    }

    /**
     * 获取 电商分类 cache
     * @return json
     */
    public static function getShopCategoryCache()
    {
        $key = 'cache:shop:categorys';
        $cacheData = self::_getInstance()->get($key);
        return $cacheData;
    }

    /**
     * 设置 电商分类 cache
     * @param $cache
     * @param int $time
     * @return bool
     */
    public static function setShopCategoryCache($cache, $time = 86400)
    {
        $key = 'cache:shop:categorys';
        return self::_getInstance()->setex($key, $time, $cache);
    }

    /**
     * 设置访问者访问IP信息
     * @param string $ip
     * @param string $info
     * @return mixed
     */
    public static function setVisitor(string $ip, string $info = '')
    {
        $time = 90 * 24 * 60 * 60;
        return self::_getInstance()->setex(self::KeyVisitorInfo.$ip, $time, $info);
    }

    /**
     * 获取访问者访问IP信息
     * @param string $ip
     * @return mixed
     */
    public static function getVisitor(string $ip)
    {
        return self::_getInstance()->get(self::KeyVisitorInfo.$ip);
    }

    /**
     * 设置黑名单国家列表
     * @param array $info
     * @param int $time
     * @return mixed
     */
    public static function setBlockedCountry(array $info, int $time = 120)
    {
        return self::_getInstance()->setex(self::KeyBlockedCountryList, $time, json_encode($info));
    }

    /**
     * 获取黑名单国家列表
     * @return mixed
     */
    public static function getBlockedCountry()
    {
        return [self::_getInstance()->get(self::KeyBlockedCountryList), self::_getInstance()->ttl(self::KeyBlockedCountryList)];
    }

    /**
     * explore主界面缓存
     * @param $data
     * @return mixed
     */
    public static function SetCacheMain($data, $time = 5, $version = 0, $device_type = "", $mode = LiveLogic::LiveModelAll, $user_id="")
    {

        if(Version::Check700($device_type, $version)){
            $key = BroadcastRedis::GetExploreRedisKey($mode, true);
            $key .= "700";
        }else{
            $key = BroadcastRedis::GetExploreRedisKey($mode, true);
        }
        if(!empty($user_id) && $mode == LiveLogic::LiveModelAll){
            $key .= ":".$user_id;
        }
        return self::_getInstance()->setex($key, $time, $data);
    }

    public static function GetCacheMain($version = 0, $device_type = "", $mode = LiveLogic::LiveModelAll, $user_id="")
    {
        if(Version::Check700($device_type, $version)){
            $key = BroadcastRedis::GetExploreRedisKey($mode, true);
            $key .= "700";
        }else{
            $key = BroadcastRedis::GetExploreRedisKey($mode, true);
        }
        
        if(!empty($user_id) && $mode == LiveLogic::LiveModelAll){
            $key .= ":".$user_id;
        }

        return self::_getInstance()->get($key);
    }

    /**
     * 设置访问IP时长（存在增加数量，否则重置并设置时长）
     * @param $ip
     * @param int $time
     * @param int $num
     * @return mixed
     */
    public static function setVisitRate($ip, $time = 5, $num = 1) {
        $client = self::_getInstance();
        if ($client->exists(self::KeyVisitRate.$ip)) {
            return $client->incrby(self::KeyVisitRate.$ip, $num);
        } else {
            return self::_getInstance()->setex(self::KeyVisitRate.$ip, $time, $num);
        }
    }

    /**
     * 获取访问者的访问次数(单位时间内)
     * @param $ip
     * @return mixed
     */
    public static function getVisitRate($ip)
    {
        return intval(self::_getInstance()->get(self::KeyVisitRate.$ip));
    }

    /**
     * 拉黑访问者多少秒（默认5分钟）
     * @param $ip
     * @param int $time
     * @return mixed
     */
    public static function blockVisit($ip, $time = 300)
    {
        return self::_getInstance()->setex(self::KeyVisitBlock.$ip, $time, $ip);
    }

    /**
     * 判断IP是否被拉黑
     * @param $ip
     * @return mixed
     */
    public static function checkVisitBlocked($ip)
    {
        return self::_getInstance()->exists(self::KeyVisitBlock.$ip);
    }
}