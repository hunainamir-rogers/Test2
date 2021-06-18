<?php

namespace app\components\service;

use app\components\firebase\FbLivecast;
use app\components\LiveLogic;
use app\components\redis\BroadcastRedis;
use app\components\redis\LiveRedis;
use app\components\redis\UserRedis;
use app\models\Channel;
use app\models\Service;
use app\components\dynamodb\DFamily;
use Yii;
use app\components\service\Follow;

/**
 * Class CloseRelationUser 获取关系近的用户，1度好友->2度好友->麦位上用户->房间观众
 * @package app\components\service
 */
class CloseRelationUser
{
    const LEN_VIEWER = 10; // 返回结果个数
    const TYPE_VIEWER_1ST = 1; //一度好友
    const TYPE_VIEWER_2ND = 2; //二度好友
    const TYPE_VIEWER_OTH = 0; //其他观众
    const PAGE_SIZE = 20;
    const GET_AUDIENCE_NUM = 2; // 取2个直播间观众
    const GET_COHOST_NUM = 2;

    private static $following1st = False; // 一度好友列表
    private static $following2nd = False; // 二度好友列表
    private static $followingAll = False; // 一度及二度好友列表

    private static $cacheFollowInRoom = False; // 缓存 一度及二度好友在房间内的数组 ['Channel ID'=>['f1','f2',...],...]

    // 获取livecast大列表
    public static function getLivecastList($mode, $user_id = '', $tag_name = '', $since_id = 0, $page_size = self::PAGE_SIZE)
    {

        //公开房间
        $result = LiveRedis::getLiveListByScore($mode, $tag_name, $since_id, $page_size);
//        if($mode == LiveLogic::LiveModelLiving && empty($result) && $since_id == 0){
//            return self::getLivecastList(LiveLogic::LiveModelUpcoming, $user_id, $tag_name, $since_id, $page_size);
//        }
        $result_data = [
            'since_id' => '',
            'list' => [],
        ];
        if ($result) {
            $end_channel_id = '';
            foreach ($result as $k => $v) {
                $mid = self::LiveStruct($v, $user_id);
                $result_data['list'][] = $mid;
            }

            $result_data['page_size'] = intval($page_size);
            $result_data['since_id'] = '';
            if (count($result) >= $page_size) {
                $result_data['since_id'] = LiveRedis::getChannelOrderScore($mode, $end_channel_id);
                $result_data['since_id'] = (string)$result_data['since_id'];
            }
        }
        return $result_data;
    }

    public static function LiveStruct($channel_id, $user_id){
        $mid = [];
        $info = BroadcastRedis::getbroadcastInfo($channel_id, ['channel_id', 'type', 'title', 'user_id', 'short_id', 'live_type', 'post_status', 'live_current_audience', 'extra', 'tags', 'scheduled_time','description']);
        $extra = isset($info["extra"]) ? $info["extra"] : "";
        if (!empty($info['channel_id'])) {
            if (empty($info['live_type']) || $info['live_type'] != Channel::liveCastLive) {
                return false;
            }
            $user_info = UserRedis::getUserInfo($info['user_id'], ['username', 'avatar_small', 'avatar']);
            $info = Service::LiveCastFormatInfo($info);
            $info['usernames'] = !empty($user_info['username']) ? $user_info['username'] : '---';//用户名，xxx,xsss,xfff,xdff,ggg
//                    $avatar[] = !empty($user_info['avatar_small']) ? $user_info['avatar_small'] : 'https://d3iis6p2dahhu3.cloudfront.net/avatar/FZRGDbaqd8kBASAT-16140497658791106835631_80.webp';
//                    $info['avatars'] = $avatar;//头像
            $mid = $info;
        }


        $close_user_arr = [];
        $close_user_guid = [];
        $close_username = '';
        $end_channel_id = $channel_id;
        if ($info["type"] == Channel::type_status_upcoming) {
            $extraArr = json_decode($extra, true);
            if (!empty($extraArr["schedule_cohost"])) {
                $close_user_guid = $extraArr["schedule_cohost"];
                $mid['slots_count'] = count($close_user_guid);
            }
            //增加通知标记
            $notify = BroadcastRedis::ExistFollower($channel_id, $user_id);
            $mid["is_notify"] = $notify ? 1 : 0;
        } else {
            $close_user_guid = self::getCloseRelationGuid($channel_id, $user_id, $info['user_id']);
            $mid['slots_count'] = intval(BroadcastRedis::countIngCohostList($channel_id));
        }
        //把主播放在第一个
        array_unshift($close_user_guid, $info["user_id"]);
        $close_user_guid =  array_unique($close_user_guid);
        foreach ($close_user_guid as $one_user_guid) {//超过10个人就不加了
            if (count($close_user_arr) >= self::LEN_VIEWER) break;
            $one_user_info = UserRedis::getUserInfo($one_user_guid, ['avatar', 'username', 'first_name', 'last_name']);
            //|| $one_user_info['avatar'] == '/default/default_avatar.jpg'
            if (empty($one_user_info['avatar'])) {
                continue;
            }
            $one_user = [];
            $one_user['guid'] = $one_user_guid;
            $one_user['username'] = $one_user_info["username"];
            $one_user['avatar'] = Service::getCompleteUrl($one_user_info['avatar']);
            $first_name = $one_user_info["first_name"] ?? '';
            $last_name = $one_user_info["last_name"] ?? '';
            $close_username .= $first_name . " " . $last_name . ',';
            $close_user_arr[] = $one_user;
        }

        $mid['audience_count'] = intval(BroadcastRedis::countAudienceLit($channel_id));
        $mid['usernames'] = trim($close_username, ',');
        $mid['description'] = $info['description'];
        $other = $mid['audience_count'] - count($close_user_arr);
        if ($other > 0 && count($close_user_arr) > 5) {
            if ($other = 1) {
                $otherMsg = " and {$other} other is here";
            } else {
                $otherMsg = " and {$other} others are here";
            }
            $mid['usernames'] .= $otherMsg;
        }
        $mid['close_user'] = $close_user_arr;
        if (!empty($mid["tags"])) {
            $mid["tags"] = explode(",", $mid["tags"]);
        } else {
            unset($mid["tags"]);
        }
        unset($mid["extra"]);
        return $mid;
    }

    //1是一度好友，2是二度好友，0是普通在线用户
    public static function getCloseRelationGuid($channel_id, $user_id, $host_id = '', $len = 50)
    {
        return BroadcastRedis::getAudienceLitByRange($channel_id, 0, 7);
    }

    // 获取一度好友列表
    public static function getFollowing1st($user_id)
    {
        if (self::$following1st === False) {
            self::$following1st = Follow::getFollowing1st($user_id)['list'];
        }
        return self::$following1st;
    }

    // 获取二度好友列表
    public static function getFollowing2nd($user_id)
    {
        if (self::$following2nd === False) {
            self::$following2nd = Follow::getFollowing2nd($user_id)['list'];
        }
        return self::$following2nd;
    }

    // 获取一度及二度好友列表
    public static function getFollowingAll($user_id)
    {
        if (self::$followingAll === False) {
            $following1st = self::getFollowing1st($user_id);
            $following2nd = self::getFollowing2nd($user_id);
            self::$followingAll = array_unique(array_merge($following1st, $following2nd));
        }
        return self::$followingAll;
    }

    // 获取在某个房间内的一度及二度好友列表
    public static function getFollowingInRoom($user_id, $channel_id)
    {
        if (isset(self::$cacheFollowInRoom[$channel_id])) {
            return self::$cacheFollowInRoom[$channel_id];
        } else {
            $viewer = BroadcastRedis::getAudienceLitByRange($channel_id, 0, -1); //观众列表
            $following = self::getFollowingAll($user_id); //好友列表
            return array_intersect($following, $viewer); //好友在房间中列表
        }
    }

    // 获取房间列表中展示的用户信息
    public static function getUsersShowedInList($channel_id, $host_id = '', $following_in_room = [])
    {
        if (empty($channel_id)) {
            return false;
        }
        $ing_list = BroadcastRedis::getIngCohostList($channel_id, 0, -1);
        //返回房主跟麦上第二个人
        $host_cohost2_list = [$host_id, $ing_list[0] ?? ''];
        $cloumn_user1 = $cloumn_user2 = $cloumn_user3 = [];
        if ($host_cohost2_list) {
            foreach ($host_cohost2_list as $k => $v) {
                if (empty($v)) continue;
                $userinfo = UserRedis::getUserInfo($v, ['username', 'avatar', 'guid']);
                $userinfo['avatar_small'] = Service::avatar_small($userinfo['avatar']);
                $userinfo['avatar'] = Service::getCompleteUrl($userinfo['avatar']);
                $cloumn_user1[] = $userinfo;
            }
        }
        if (!empty($following_in_room)) {
            //查找麦上的二度好友
            $onmic_friend = array_intersect($following_in_room, $ing_list);
            array_unshift($onmic_friend, $host_id);
            foreach ($onmic_friend as $k => $v) {
                $userinfo = UserRedis::getUserInfo($v, ['username', 'avatar', 'guid']);
                $userinfo['avatar_small'] = Service::avatar_small($userinfo['avatar']);
                $userinfo['avatar'] = Service::getCompleteUrl($userinfo['avatar']);
                $cloumn_user2[] = $userinfo;
                if (count($cloumn_user2) >= 20) break;
            }
            //剩下的在房间的二度好友
            $left_friend = array_diff($following_in_room, $onmic_friend);
            foreach ($left_friend as $k => $v) {
                $userinfo = UserRedis::getUserInfo($v, ['username', 'avatar', 'guid']);
                $userinfo['avatar_small'] = Service::avatar_small($userinfo['avatar']);
                $userinfo['avatar'] = Service::getCompleteUrl($userinfo['avatar']);
                $cloumn_user3[] = $userinfo;
                if (count($cloumn_user3) >= 20) break;
            }
        }
        return [$cloumn_user1, $cloumn_user2, $cloumn_user3];
    }

    public static function getHostUser($channel_id, $host_id = '', $close_user_guid = [])
    {
        if (empty($channel_id)) {
            return false;
        }
        $ing_list = BroadcastRedis::getIngCohostList($channel_id, 0, -1);
        //返回房主跟麦上第二个人
        $host_cohost2_list = [$host_id, $ing_list[0] ?? ''];
        $cloumn_user1 = $cloumn_user2 = $cloumn_user3 = [];
        if ($host_cohost2_list) {
            foreach ($host_cohost2_list as $k => $v) {
                if (empty($v)) continue;
                $userinfo = UserRedis::getUserInfo($v, ['username', 'avatar', 'guid']);
                $userinfo['avatar_small'] = Service::avatar_small($userinfo['avatar']);
                $userinfo['avatar'] = Service::getCompleteUrl($userinfo['avatar']);
                $cloumn_user1[] = $userinfo;
            }
        }
        $close_user_guid = array_keys($close_user_guid);
        if (!empty($close_user_guid)) {
            //查找麦上的二度好友
            $onmic_friend = array_intersect($close_user_guid, $ing_list);
            array_unshift($onmic_friend, $host_id);
            foreach ($onmic_friend as $k => $v) {
                $userinfo = UserRedis::getUserInfo($v, ['username', 'avatar', 'guid']);
                $userinfo['avatar_small'] = Service::avatar_small($userinfo['avatar']);
                $userinfo['avatar'] = Service::getCompleteUrl($userinfo['avatar']);
                $cloumn_user2[] = $userinfo;
                if (count($cloumn_user2) >= 20) break;
            }
            //剩下的在房间的二度好友
            $left_friend = array_diff($close_user_guid, $onmic_friend);
            foreach ($left_friend as $k => $v) {
                $userinfo = UserRedis::getUserInfo($v, ['username', 'avatar', 'guid']);
                $userinfo['avatar_small'] = Service::avatar_small($userinfo['avatar']);
                $userinfo['avatar'] = Service::getCompleteUrl($userinfo['avatar']);
                $cloumn_user3[] = $userinfo;
                if (count($cloumn_user3) >= 20) break;
            }
        }
        return [$cloumn_user1, $cloumn_user2, $cloumn_user3];
    }

    // 分页获取用户的Livecast频道列表
    public static function getPersonalLivecastList($user_id = '', $tag_name = '', $since_id = 0, $page_size = self::PAGE_SIZE)
    {
        $list = self::getPersonalLivecastListAll($user_id, $tag_name);

        $offset = $page_size * $since_id;
        $data['list'] = array_slice($list, $offset, $page_size);
        $data['total'] = count($list);
        return $data;
    }

    // 获取用户的Livecast频道列表
    public static function getPersonalLivecastListAll($user_id = '', $tag_name = '')
    {
        //私有房间
        $private_arr = Channel::find()->where(['user_id' => $user_id, 'type' => Channel::type_status_living, 'live_type' => Channel::liveCastLive, 'post_status' => FbLivecast::ROOM_TYPE['private']])->asArray()->select(['guid'])->all();
        if (!empty($private_arr)) {
            $private_arr = array_column($private_arr, 'guid');
        }

        //GROUP房间
        $group_arr = DFamily::getOneUserGroupLiveList($user_id);

        //公开房间
        $public_arr_all = LiveRedis::getLiveListByScore(Channel::liveCastLive, $tag_name, 0, 100); //最多取出100个房间
        $public_arr = [];
        foreach ($public_arr_all as $channel) {
            $data = self::filterChannelWithFollowing($channel, $user_id);
            if (!empty($data)) {
                $public_arr[$data['cid']] = $data['score'];
            }
        }
        arsort($public_arr);
        $public_arr = array_keys($public_arr);

        return array_unique(array_merge($private_arr, $group_arr, $public_arr));
    }

    // 过滤频道：1.推荐房间；2.存在用户的好友；3.在线用户大于5；
    public static function filterChannelWithFollowing($channel_id, $user_id)
    {
        $viewer = BroadcastRedis::getAudienceLitByRange($channel_id, 0, -1); //观众列表
        $following = self::getFollowingAll($user_id); //好友列表
        $followingInRoom = array_intersect($following, $viewer); //好友在房间中列表

        $countviewer = count($viewer); //观众数
        $countFollowingInRoom = count($followingInRoom); //好友在房间中数
        $orderScore = LiveRedis::getChannelOrderScore(Channel::liveCastLive, $channel_id);

        if ($orderScore >= 2000000000) {
            $socre = $orderScore;
        } elseif ($countFollowingInRoom >= 0) {
            $socre = $countFollowingInRoom * 100000;
        } else {
            $socre = $countviewer;
        }
        if ($socre >= 5) {
            $data['cid'] = $channel_id;
            $data['score'] = $socre;
            self::$cacheFollowInRoom[$channel_id] = $followingInRoom;
            return $data;
        } else {
            $channel_user_id = Channel::getbroadcastInfo($channel_id, 'user_id');
            if ($channel_user_id == $user_id) {
                $data['cid'] = $channel_id;
                $data['score'] = $socre;
                self::$cacheFollowInRoom[$channel_id] = $followingInRoom;
                return $data;
            }
            return [];
        }
    }
}