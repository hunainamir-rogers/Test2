<?php
/**
 * Created by PhpStorm.
 * User: lilin6
 * Date: 2017/10/18
 * Time: 下午2:48
 */

namespace app\components\redis;

use app\components\define\RedisKeyRepo;
use Yii;
use app\components\RedisClient;

class UserRedis
{

    public static $redis_client;

    private static $_redis_pool = 'user';
    const SEED_USER_LIST = 'user:seed:list';
    const USER_MEMBERS_HASH = 8;
    const FOLLOW_EXPIRE_TIME = 1296000;// 15days

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

    public static function exists($user_id)
    {
        $key = RedisKeyRepo::KeyUserExistGuid . self::_hashUserId($user_id);
        $ret = self::_getInstance()->hget($key, $user_id);
        return empty($ret) ? false : true;
    }

    //判断是否为测试用户
    public static function IsTestUser($guid)
    {
        $key = RedisKeyRepo::UserTestId;
        return self::_getInstance()->sismember($key, $guid);
    }

    public static function setUserInfo($guid, $values)
    {
        $key = "user.id:" . $guid;
        return self::_getInstance()->hmset($key, $values);
    }

    public static function getUserInfo($guid, $field = "") {
        $key = "user.id:".$guid;
        if (empty($field)) {
            return self::_getInstance()->hgetall($key);
        }
        if(is_array($field)){
            return self::_getInstance()->hmget($key, $field);
        }
        //没有单独获取frame_img，enter_img的使用场景，所以暂时不修改hget逻辑
        return self::_getInstance()->hget($key, $field);
    }
    public static function UserExists($guid)
    {
        $key = "user.id:" . $guid;
        return self::_getInstance()->exists($key);
    }


    public static function FollowerFriendsList($user_id, $start = 0, $end = -1)
    {
        return self::_getInstance()->zrevrange('follow:allfollower.id:' . $user_id, $start, $end);
    }

    public static function countFollowerFriendsList($user_id)
    {
        return self::_getInstance()->zcard('follow:allfollower.id:' . $user_id);
    }

    //包括好友和follow的共同集合
    public static function addFollowerFriends($user_id, $follow_id, $score = '', $check_exists = true)
    {
        if (empty($score)) {
            $score = time();
        }
        $key = 'follow:allfollower.id:' . $user_id;
        if ($check_exists) {
            if (self::_getInstance()->exists($key)) {
                return self::_getInstance()->zadd($key, $score, $follow_id);
            }
        } else {
            return self::_getInstance()->zadd($key, $score, $follow_id);
        }
        return false;
    }


    public static function expireFollowerFriends($user_id)
    {
        if (!self::isSeedUser($user_id)) {
            return self::_getInstance()->expire('follow:allfollower.id:' . $user_id, self::FOLLOW_EXPIRE_TIME);
        }
        return true;
    }

    public static function isSeedUser($user_id = '')
    {
        if (!empty($user_id)) {
            return self::_getInstance()->sismember(self::SEED_USER_LIST, $user_id);
        }
        return 0;
    }


    //移除包括好友和我关注的主播的共同集合
    public static function delFollowingFriends($user_id, $follow_id)
    {
        return self::_getInstance()->zrem('follow:allfollowing.id:' . $user_id, $follow_id);
    }

    public static function delFollowerFriends($user_id, $follow_id)
    {
        return self::_getInstance()->zrem('follow:allfollower.id:' . $user_id, $follow_id);
    }

    public static function delFriend($guid)
    {
        $key = 'friends.id:' . $guid;
        return self::_getInstance()->del($key);
    }

    public static function delFollower($user_id, $follow_id)
    {
        return self::_getInstance()->zrem('follow:follower.id:' . $user_id, $follow_id);
    }

    public static function userUnFollow($user_id, $follow_id)
    {
        return self::_getInstance()->zrem('follow:user.id:' . $user_id, $follow_id);
    }

    public static function addFriend($guid, $friend_id)
    {
        $key = 'friends.id:' . $guid;
        if (self::_getInstance()->exists($key)) {
            return self::_getInstance()->zadd($key, time(), $friend_id);//zset存好友列表
        }
        return false;
    }

    /**
     * 获取推荐关注的用户
     * @param $start
     * @param $end
     * @return mixed
     */
    const USER_RECOMMEND_KEY = 'user.recommend.list';

    public static function getRecommendList($start = 0, $end = -1)
    {
        return self::_getInstance()->zrange(self::USER_RECOMMEND_KEY, $start, $end);
    }

    //我关注的人和我的好友
    public static function FollowingFriendsList($user_id, $start = 0, $end = -1)
    {
        return self::_getInstance()->zrevrange('follow:allfollowing.id:' . $user_id, $start, $end);
    }

    public static function isInFollowerFriendsList($user_id, $user_id2 = '')
    {
        return self::_getInstance()->zscore('follow:allfollower.id:' . $user_id, $user_id2);
    }

    //好友和我关注的人
    public static function countFollowingFriendsList($user_id)
    {
        return self::_getInstance()->zcard('follow:allfollowing.id:' . $user_id);
    }

    public static function isInFollowingFriendsList($user_id, $user_id2 = '')
    {
        return self::_getInstance()->zscore('follow:allfollowing.id:' . $user_id, $user_id2);
    }

    //加入到未接受的好友请求列表
    public static function addFriendsUnacceptList($friend_id, $user_id)
    {
        return self::_getInstance()->sadd('friends.unaccept.id:' . $friend_id, $user_id);
    }

    //从未接受的好友请求列表里移除
    public static function remFriendsUnacceptList($user_id, $friend_id)
    {
        return self::_getInstance()->srem('friends.unaccept.id:' . $user_id, $friend_id);
    }

    //获取未接受的好友请求列表
    public static function getFriendsUnacceptList($user_id)
    {
        return self::_getInstance()->smembers('friends.unaccept.id:' . $user_id);
    }

    //包括好友和我关注的主播的共同集合
    public static function addFollowingFriends($user_id, $follow_id, $score = '', $check_exists = true)
    {
        if (empty($score)) {
            $score = time();
        }
        $key = 'follow:allfollowing.id:' . $user_id;
        if ($check_exists) {
            if (self::_getInstance()->exists($key)) {
                return self::_getInstance()->zadd($key, $score, $follow_id);
            }
        } else {
            return self::_getInstance()->zadd($key, $score, $follow_id);
        }
        return false;
    }

    public static function expireFollowingFriends($user_id)
    {
        if (!self::isSeedUser($user_id)) {
            return self::_getInstance()->expire('follow:allfollowing.id:' . $user_id, self::FOLLOW_EXPIRE_TIME);
        }
        return true;
    }

    public static function countFriends($user_id)
    {
        $key = "friends.id:" . $user_id;
        return self::_getInstance()->zcard($key);
    }

    public static function countblockList($user_id)
    {
        return self::_getInstance()->zcard('block.id:' . $user_id);

    }

    public static function addBlock($user_id, $block_id)
    {
        return self::_getInstance()->zadd('block.id:' . $user_id, time(), $block_id);
    }

    public static function isInblockList($user_id, $user_id2 = '')
    {
        return self::_getInstance()->zscore('block.id:' . $user_id, $user_id2);
    }

    /**
     * block 过期时间
     * @param $user_id
     * @return mixed
     */
    public static function expireBlock($user_id)
    {
        if (!self::isSeedUser($user_id)) {
            return self::_getInstance()->expire('block.id:' . $user_id, self::FOLLOW_EXPIRE_TIME);
        }
        return true;
    }

    public static function remBlock($user_id, $block_id)
    {
        return self::_getInstance()->zrem('block.id:' . $user_id, $block_id);
    }

    public static function blockList($user_id, $page = 0, $page_size = 0)
    {
        if ($page == 0 && $page_size == -1) {
            return self::_getInstance()->zrange('block.id:' . $user_id, 0, -1);
        } else {
            return self::_getInstance()->zrange('block.id:' . $user_id, ($page - 1) * $page_size, ($page - 1) * $page_size + $page_size);
        }
    }

    public static function getUserInfoBatch($guids, $params = [])
    {
        $ret = array();
        if (!empty($guids) && is_array($guids)) {
            foreach ($guids as $id) {
                if (empty($id)) {
                    continue;
                }
                $guid_keys = 'user.id:' . $id;
                if (empty($params)) {
                    $ret[] = self::_getInstance()->hgetall($guid_keys);
                } else {
                    $ret[] = self::_getInstance()->hmget($guid_keys, $params);
                }
            }
        }
        return $ret;
    }

    public static function friendsList($user_id, $start = 0, $end = -1)
    {
        $key = "friends.id:" . $user_id;
        return self::_getInstance()->zrevrange($key, $start, $end);

    }

    public static function friendsListByScore($user_id, $max, $min = 100000000, $limit = 20)
    {
        if ($user_id) {
            $key = "friends.id:" . $user_id;
            return self::_getInstance()->zrevrangebyscore($key, $max, $min, ['limit' => [0, $limit]]);
        }
        return [];
    }

    public static function friendsScore($user_id, $friends_id)
    {
        return self::_getInstance()->zscore("friends.id:" . $user_id, $friends_id);
    }

    /**
     * 好友 过期时间
     * @param $user_id
     * @return mixed
     */
    public static function expireFriends($user_id)
    {
        return self::_getInstance()->expire('friends.id:' . $user_id, self::FOLLOW_EXPIRE_TIME);
    }

    public static function removeFriend($guid, $friend_id)
    {
        $key = 'friends.id:' . $guid;
        return self::_getInstance()->zrem($key, $friend_id);
    }

    /**
     * 增加好友
     * @param $user_id
     * @param $friend_id
     * @param string $score
     * @return mixed
     */
    public static function addFriends($user_id, $friend_id, $score = '')
    {
        if (empty($score)) {
            $score = time();
        }
        $key = "friends.id:" . $user_id;
        return self::_getInstance()->zadd($key, $score, $friend_id);
    }

    //获取version信息
    public static function getVersion($type) {
        $key = 'version.save.'.$type;
        return self::_getInstance()->hgetall($key);
    }
}