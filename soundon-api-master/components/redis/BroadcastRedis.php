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
use Yii;

class BroadcastRedis
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

    //设置直播详情
    public static function broadcastInfo($channel_id, $data, $expire = 0)
    {
        $key = 'broadcast:info:id:' . $channel_id;
        $result = self::_getInstance()->hmset($key, $data);
        if ($expire > 0) {
            self::_getInstance()->expire($key, $expire);
        }
        return $result;
    }

    //获取直播信息
    public static function getbroadcastInfo($channel_id, $flied = "")
    {
        if (is_array($channel_id)) {
            return false;
        }
        $key = 'broadcast:info:id:' . $channel_id;

        if (!empty($flied)) {
            if (is_array($flied)) {
                return self::_getInstance()->hmget($key, $flied);
            }
            return self::_getInstance()->hget($key, $flied);
        }
        return self::_getInstance()->hgetall($key);
    }

    /**
     * 增加值
     * diamonds
     * @param $channel_id
     * @param $data
     * @return mixed
     */
    public static function numberAdd($channel_id, $field, $number)
    {
        $key = 'broadcast:info:id:' . $channel_id;
        return self::_getInstance()->hincrby($key, $field, $number);
    }

    public static function getApplyCohostNum($channel_id)
    {
        $key = "cohost.apply.list:" . $channel_id;
        return self::_getInstance()->zcard($key);
    }


    public static function checkApplyCohostUser($channel_id, $user_id)
    {
        if (empty($channel_id) || empty($user_id)) {
            return 0;
        }
        $key = "cohost.apply.list:" . $channel_id;
        return self::_getInstance()->zscore($key, $user_id);
    }

    //删除直播间的观众
    public static function remAudience($channel_id, $user_id)
    {
        $key = 'broadcast:audience:list.id:' . $channel_id;
        //TODO 按观众的等级排序
        return self::_getInstance()->zrem($key, $user_id);
    }

    //获取直播间加入黑名单的人,管理员使用，非主播，主播十个人拉黑
    public static function getLiveBlockUserScore($channel_id, $user_id)
    {
        $key = 'broadcast:block.list.id:' . $channel_id;
        return self::_getInstance()->zscore($key, $user_id);
    }

    //增加直播间的观众
    public static function addAudience($channel_id, $user_id)
    {
        $key = 'broadcast:audience:list.id:' . $channel_id;
        //TODO 按观众的等级排序
        $scord = time() . rand(1000, 9999);
        return self::_getInstance()->zadd($key, $scord, $user_id);
    }

    //获取直播间用户json
    public static function getBroadcastAdvanceJson($channel_id)
    {
        $key = 'broadcast:audience:str:' . $channel_id;
        return self::_getInstance()->get($key);
    }

    /**
     * 用户最近加入的房间记录
     */
    const JOIN_ROOM_LIST = 'join.room.list:';

    public static function addJoinList($user_id, $channel_id)
    {
        if (!empty($user_id) && !empty($channel_id)) {
            $score = time();
            $key = self::JOIN_ROOM_LIST . $user_id;
            self::_getInstance()->zadd($key, $score, $channel_id); //加队列
            if (mt_rand(0, 2) == 1) {
                self::_getInstance()->expire($key, 604800);  //续1周时间
                $num = self::_getInstance()->zcard($key);
                if ($num > 100) {
                    self::_getInstance()->zremrangebyrank($key, 0, -101);  //删除新100条数据之前的旧数据
                }
            }
            return true;
        }
        return false;
    }

    public static function ishavebroadcastInfo($channel_id)
    {
        $key = 'broadcast:info:id:' . $channel_id;
        return self::_getInstance()->exists($key);
    }


    /**无效的channel id ,数据库没查询到********start*************/

    const INVALID_CHANNEL_ID = 'broadcast:channel:invalid';

    public static function addInvalidChannelId($channel_id)
    {
        return self::_getInstance()->sadd(self::INVALID_CHANNEL_ID, $channel_id);
    }

    public static function isMemberInvalidChannelId($channel_id)
    {
        return self::_getInstance()->sismember(self::INVALID_CHANNEL_ID, $channel_id);
    }

    public static function rmInvalidChannelId($channel_id)
    {
        return self::_getInstance()->srem(self::INVALID_CHANNEL_ID, $channel_id);
    }
    /**********end*************/

    /***
     * 连麦中的列表
     */
    public static function setIngCohostList($channel_id, $user_id)
    {
        $key = "cohost.ing.list:" . $channel_id;
        $time = time() . rand(1000, 9999);
        return self::_getInstance()->zadd($key, $time, $user_id);
    }

    public static function getIngCohostList($channel_id, $start = 0, $stop = -1)
    {
        $key = "cohost.ing.list:" . $channel_id;
        return self::_getInstance()->zrevrange($key, $start, $stop);
    }

    public static function remIngCohostList($channel_id, $user_id)
    {
        $key = "cohost.ing.list:" . $channel_id;
        return self::_getInstance()->zrem($key, $user_id);
    }

    public static function delIngCohostList($channel_id)
    {
        $key = "cohost.ing.list:" . $channel_id;
        return self::_getInstance()->del($key);
    }
    public static function ScoreCohostList($channel_id, $user_id)
    {
        $key = "cohost.ing.list:" . $channel_id;
        return self::_getInstance()->zscore($key, $user_id);
    }

    public static function countIngCohostList($channel_id)
    {
        if (empty($channel_id)) {
            return 0;
        }
        $key = 'cohost.ing.list:' . $channel_id;
        return self::_getInstance()->zcard($key);
    }

    /***
     * 连麦申请的列表
     */
    public static function setApplyCohostList($channel_id, $user_id)
    {
        $key = "cohost.apply.list:" . $channel_id;
        $time = time() . rand(1000, 9999);
        return self::_getInstance()->zadd($key, $time, $user_id);
    }

    public static function getApplyCohostList($channel_id, $start = 0, $stop = 20)
    {
        $key = "cohost.apply.list:" . $channel_id;
        return self::_getInstance()->zrevrange($key, $start, $stop);
    }

    public static function remApplyCohostList($channel_id, $user_id)
    {
        $key = "cohost.apply.list:" . $channel_id;
        return self::_getInstance()->zrem($key, $user_id);
    }

    public static function delApplyCohost($channel_id)
    {
        $key = "cohost.apply.list:" . $channel_id;
        return self::_getInstance()->del($key);
    }
    public static function ExistApplyCohost($channel_id, $user_id)
    {
        $key = "cohost.apply.list:" . $channel_id;
        return self::_getInstance()->zscore($key, $user_id);
    }

    public static function countAudienceLit($channel_id)
    {
        if (empty($channel_id)) {
            return 0;
        }
        $key = 'broadcast:audience:list.id:' . $channel_id;
        //TODO 按观众的等级排序
        return self::_getInstance()->zcard($key);
    }

    public static function remLiveBlockUser($channel_id, $user_id)
    {
        $key = 'broadcast:block.list.id:' . $channel_id;
        return self::_getInstance()->zrem($key, $user_id);
    }

    public static function addLiveBlockUser($channel_id, $user_id, $score = 0)
    {
        $key = 'broadcast:block.list.id:' . $channel_id;
        if (empty($score)) {
            $score = time();
        }
        return self::_getInstance()->zadd($key, $score, $user_id);
    }

    //获取直播间黑名单的人
    public static function getLiveBlockUserList($channel_id, $max_time = '+inf', $count = 10)
    {
        if ($channel_id) {
            $key = 'broadcast:block.list.id:' . $channel_id;
            return self::_getInstance()->zrevrangebyscore($key, $max_time, '-inf', ['limit' => [0, $count]]);
        }
        return [];
    }

    //正在直播列表
    public static function getBroadcastList($user_id = '', $since_id = 0, $pos = -1)
    {
        if (empty($user_id)) {
            $key = 'broadcast:list.id:';
        } else {
            $key = 'broadcast:user:list.id:' . $user_id;
        }

        return self::_getInstance()->zrevrange($key, $since_id, $pos);
    }

    public static function GetExploreChannel($mode = LiveLogic::LiveModelLiving, $page = 1, $pageSize = 100, $sort = false, $withscores = false)
    {
        if ($page <= 0) {
            $page = 1;
        }
        $start = ($page - 1) * $pageSize;
        $stop = $start + ($pageSize - 1);
        $key = self::GetExploreRedisKey($mode);
        if ($sort) {
            return self::_getInstance()->zrange($key, $start, $stop, $withscores);
        }
        return self::_getInstance()->ZREVRANGE($key, $start, $stop, $withscores);
    }

    public static function GetExploreRedisKey($mode = LiveLogic::LiveModelLiving, $cacheKey = false)
    {
        $key = "explore:livestream:" . $mode;
        if ($cacheKey) {
            $key = "cache:" . $key;
        }
        return $key;
    }

    public static function SetExploreChannel($score, $chanel_id, $mode = LiveLogic::LiveModelLiving)
    {
        $key = self::GetExploreRedisKey($mode);
        return self::_getInstance()->zadd($key, $score, $chanel_id);
    }


    public static function RemoveExploreChannel($chanel_id, $mode = LiveLogic::LiveModelLiving)
    {
        $key = self::GetExploreRedisKey($mode);
        return self::_getInstance()->zrem($key, $chanel_id);
    }

    const ROBOT_BROADCAST_USER_LIST = 'broadcast:usercolist';

    public static function addBroadcastCoUser($channel_id = '', $user_id = '')
    {
        if (!empty($channel_id) && !empty($user_id)) {
            return self::_getInstance()->hset(self::ROBOT_BROADCAST_USER_LIST, $user_id, $channel_id);
        }
        return false;
    }

    //获取快速进入用户对应的直播间id
    public static function getBroadcastCoUser($user_id = '')
    {
        if ($user_id) {
            return self::_getInstance()->hget(self::ROBOT_BROADCAST_USER_LIST, $user_id);
        }
        return '';
    }

    public static function remBroadcastCoUser($user_id = '')
    {
        if ($user_id) {
            return self::_getInstance()->hdel(self::ROBOT_BROADCAST_USER_LIST, $user_id);
        }
        return '';
    }


    public static function deltAudienceLit($channel_id)
    {
        $key = 'broadcast:audience:list.id:' . $channel_id;
        return self::_getInstance()->del($key);
    }


    public static function getAudienceLitByRange($channel_id, $start = 0, $stop = 100)
    {
        $key = 'broadcast:audience:list.id:' . $channel_id;
        //TODO 按观众的等级排序
        return self::_getInstance()->zrange($key, $start, $stop);
    }

    public static function addFollower($channel_id, $user_id)
    {
        $key = 'broadcast:notify:list.id:' . $channel_id;
        $scord = time() . rand(1000, 9999);
        return self::_getInstance()->zadd($key, $scord, $user_id);
    }

    //移除直播间的观众
    public static function remFollower($channel_id, $user_id)
    {
        $key = 'broadcast:notify:list.id:' . $channel_id;
        return self::_getInstance()->zrem($key, $user_id);
    }
    public static function DedFollower($channel_id)
    {
        $key = 'broadcast:notify:list.id:' . $channel_id;
        return self::_getInstance()->del($key);
    }
    public static function getFollower($channel_id)
    {
        $key = 'broadcast:notify:list.id:' . $channel_id;
        return self::_getInstance()->zrange($key, 0, -1);
    }
    public static function ExistFollower($channel_id, $user_id)
    {
        $key = 'broadcast:notify:list.id:' . $channel_id;
        return self::_getInstance()->zscore($key, $user_id);
    }

    public static function AddFeaturedUser($channel_id, $user_id)
    {
        $key = 'broadcast:featured:user:' . $channel_id;
        return self::_getInstance()->sadd($key, $user_id);
    }
    public static function getFeaturedUser($channel_id, $user_id)
    {
        $key = 'broadcast:featured:user:' . $channel_id;
        return self::_getInstance()->sismember($key, $user_id);
    }

    /**
     * 返回所有的featured 用户
     * @param $channel_id
     * @return mixed
     */
    public static function getFeaturedUserList($channel_id)
    {
        $key = 'broadcast:featured:user:' . $channel_id;
        return self::_getInstance()->smembers($key);
    }

    public static function ScheduleNotifyMark($channel_id){
        $key = 'broadcast:schedule:notify:' . $channel_id;
        return self::_getInstance()->setex($key, 7200, time());
    }
    public static function CheckScheduleNotifyMark($channel_id){
        $key = 'broadcast:schedule:notify:' . $channel_id;
        return self::_getInstance()->get($key);
    }

}