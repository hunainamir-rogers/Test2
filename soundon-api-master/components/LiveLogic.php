<?php

namespace app\components;

use app\components\dynamodb\DFamily;
use app\components\gameconfig\Match;
use app\components\redis\AsapRedis;
use app\components\redis\BroadcastAudioRedis;
use app\components\redis\BroadcastRedis;
use app\components\redis\BroadcastTopRedis;
use app\components\redis\CacheRedis;
use app\components\redis\FeedRedis;
use app\components\redis\IdolRedis;
use app\components\redis\SpotlightRedis;
use app\components\redis\UserRedis;
use app\models\Campaign;
use app\models\Channel;
use app\models\ChannelModerators;
use app\models\Featured;
use app\models\IdolManagement;
use app\models\Service;
use app\models\User;
use Yii;

class LiveLogic
{
    const LiveModelLiving = "living";
    const LiveModelUpcoming = "upcoming";

    const notify_type_follow = 1;
    const notify_type_live = 2;
    const notify_type_schedule = 4;

    const HotChannelExpireTime = 300;

    /**
     * 加入直播间
     * @param $user_id
     * @param $channel_id
     * @param $broadcastInfo
     * @param array $user_info $user_id 的用户新信息
     * @return bool
     */
    public static function Join($user_id, $channel_id, $broadcastInfo = [], $user_info = [])
    {
        if (empty($broadcastInfo['user_id']) || empty($broadcastInfo['channel_id'])) {
            $broadcastInfo = Channel::getbroadcastInfo($channel_id);
            if (empty($broadcastInfo['user_id']) || empty($broadcastInfo['channel_id'])) {
                return [];
            }
        }
        //观众加入直播间一次, total_joined_times数值加1, 结束后写回DB
        BroadcastRedis::numberAdd($channel_id, 'total_joined_times', 1);
        //andrew: can we track number of views each livestream has example if user A watches a livestream and then leaves and comes back the number is 2

        if (empty($user_info)) {
            $user_info = Service::userinfo($user_id);
        }
        $broadcastInfo["my_enter_img"] = $user_info["enter_img"] ?? '';
        $relation_status = 0;
        $relation_status = Service::UserRelation($broadcastInfo['user_id'], $user_id);

        $Audience = [];

        $broadcastInfo["audience_count"] = isset($broadcastInfo["audience_count"]) ? (int)$broadcastInfo["audience_count"] : 1;//intval($broadcastInfo["audience_count"]);
        $broadcastInfo["audience_count_int"] = isset($broadcastInfo["audience_count"]) ? intval($broadcastInfo["audience_count"]) : $broadcastInfo["audience_count"];
        //当前的观众列表
        //$Advances  = Service::currentAdvance($channel_id);
        $broadcastInfo["channel_key"] = Agora::generateMediaChannelKey($channel_id, $user_info['id']);
//        Yii::info('QuickJoin user id='.$user_id.'----'.$user_info['id'].',,channel_key='.$broadcastInfo["channel_key"],'my');
        //生成信令key
        $broadcastInfo["rtm_token"] = Agora::GenerateRtmToken($user_id);
        $broadcastInfo["relation_status"] = $relation_status;

        $broadcastInfo["chatroom_id"] = intval($broadcastInfo['id']);

        $broadcastInfo["status"] = 'Join success.';

        $broadcastInfo["live_highest_audience"] = isset($broadcastInfo["live_highest_audience"]) ? intval($broadcastInfo["live_highest_audience"]) : 0;
        $broadcastInfo["live_end_time"] = strtotime($broadcastInfo["live_end_time"]) * 1000;
        $broadcastInfo["scheduled_time"] = isset($broadcastInfo["scheduled_time"]) ? strtotime($broadcastInfo["scheduled_time"]) * 1000 : 0;
        //get anchor avatar
        $anchorInfo = [];
        if ($user_id != $broadcastInfo['user_id']) {
            $anchorInfo = Service::userInfo($broadcastInfo["user_id"]);
            if (empty($anchorInfo)) {
                return [];
            }
        } else {
            $anchorInfo = $user_info;
        }

        $avatar = isset($anchorInfo["avatar"]) ? $anchorInfo["avatar"] : "";
        $broadcastInfo["avatar"] = Service::getCompleteUrl($avatar);
        $broadcastInfo["nickname"] = $anchorInfo["nickname"];
        $broadcastInfo["username"] = $anchorInfo["username"];
        $broadcastInfo["short_id"] = intval($anchorInfo["id"]);
        $broadcastInfo["live_type"] = intval($broadcastInfo["live_type"]);
        $broadcastInfo["live_start_time"] = strtotime($broadcastInfo["live_start_time"]) * 1000;
        $broadcastInfo["duration"] = intval($broadcastInfo["duration"]) * 1000;
        //host 信息
        $broadcastInfo["user_info"] = [
            'avatar' => Service::getCompleteUrl($avatar),
            'avatar_small' => Service::avatar_small($avatar),
            'nickname' => $anchorInfo["nickname"],
            'guid' => $anchorInfo["guid"],
            'relation_status' => $relation_status,
            "username" => $broadcastInfo["username"],
            "intro" => isset($anchorInfo["intro"]) ? $anchorInfo["intro"] : "",
            "id" => $broadcastInfo["short_id"],
            "frame_img" => $anchorInfo["frame_img"] ?? '',
            "enter_img" => $anchorInfo["enter_img"] ?? '',
        ];
        $broadcastInfo["video"] = isset($broadcastInfo["video"]) ? (int)$broadcastInfo["video"] : Channel::mode_6_seat;
        //查看是否被禁言
        $broadcastInfo["mute_status"] = ChannelModerators::IsMute($channel_id, $user_id);
        unset($anchorInfo);
        $broadcastInfo['thumb_cover'] = isset($broadcastInfo['cover_image']) ? Util::getExploreThumbImage($broadcastInfo['cover_image']) : "";
        $broadcastInfo["bg"] = isset($broadcastInfo["bg"]) ? (string)Service::getCompleteUrl($broadcastInfo["bg"]) : "";
        $broadcastInfo["diamonds"] = isset($broadcastInfo["diamonds"]) ? (int)$broadcastInfo["diamonds"] : 0;
        $broadcastInfo["golds"] = isset($broadcastInfo["golds"]) && !empty($broadcastInfo["golds"]) ? number_format($broadcastInfo["golds"], 0, '.', '') : '0';

        $broadcastInfo["share_count"] = isset($broadcastInfo["share_count"]) ? (int)$broadcastInfo["share_count"] : 0;
        $broadcastInfo["short_desc"] = isset($broadcastInfo["short_desc"]) ? $broadcastInfo["short_desc"] : '';
        if (Channel::CheckSeatMode($broadcastInfo['video'], $channel_id)) {
            $broadcastInfo["slots"] = Channel::GetSlotsData($channel_id);
            if (empty($broadcastInfo["slots"])) {
                $broadcastInfo["slots"] = Channel::InitAudioCohost($channel_id, $broadcastInfo, 1, $broadcastInfo['video']);
            }
        }
        //只有这种需要join 时看观众列表
        if ($broadcastInfo["live_type"] == Channel::liveIdolJeepney) {
            if (isset($broadcastInfo['extra']) && !empty($broadcastInfo['extra']) && is_string($broadcastInfo['extra'])) {
                $extra = [];
                $extra = json_decode($broadcastInfo['extra'], true);
                if ($extra && is_array($extra)) {
                    $broadcastInfo = array_merge($broadcastInfo, $extra);
                }
            }

            $audienceJson = BroadcastRedis::getBroadcastAdvanceJson($channel_id);
            $audienceArr = json_decode($audienceJson, true);
            if ($audienceArr) {
                $Audience = isset($audienceArr["audience_list"]) ? Channel::changeJarAudienceFormat($audienceArr["audience_list"], 2) : [];
            }
            $Audience = array_values($Audience);
        }
        $broadcastInfo["audience"] = $Audience;
        //房间权限
        $broadcastInfo['my_role'] = ChannelModerators::GetRole($channel_id, $user_id, $broadcastInfo);

        //join的时候判断背景是否过期
        if (isset($broadcastInfo['theme_expire']) && $broadcastInfo['theme_expire'] != '0' && (intval($broadcastInfo['theme_expire']) < time())) {
            $broadcastInfo['bg'] = Service::getCompleteUrl('/default/ic_live_black_bg.png');
        }
        if ($broadcastInfo["live_type"] == Channel::liveAudioStream) {
            $broadcastInfo['slot_model'] = isset($broadcastInfo['slot_model']) ? (int)$broadcastInfo['slot_model'] : 1;
            $is_host_manager = in_array($broadcastInfo['my_role'], [ChannelModerators::role_channel_moderators, ChannelModerators::role_channel_host]);
            //管理员跟房主，join的时候返回onmic用户列表,返回申请队列是否有人举手
            if ($is_host_manager) {
                if (isset($broadcastInfo['onmic_user']) && !empty($broadcastInfo['onmic_user'])) {
                    $onmic_user = explode(',', $broadcastInfo['onmic_user']);
                    $return_onmic = [];
                    foreach ($onmic_user as $_v) {
                        if (empty($_v)) continue;
                        $userinfo = UserRedis::getUserInfo($_v, ['guid', 'avatar', 'username']);
                        if (empty($userinfo['guid'])) {
                            //$userinfo = User::reloadUser($_v);
                            //if(empty($userinfo)){
                            continue;
                            //}
                        }
                        $avatar = !empty($userinfo['avatar']) ? $userinfo['avatar'] : '';
                        $return_onmic[] = [
                            'guid' => $_v,
                            'username' => $userinfo['username'] ?? '',
                            'avatar_small' => Service::avatar_small($avatar),
                        ];
                    }
                    $broadcastInfo['onmic_user'] = $return_onmic;

                } else {
                    $broadcastInfo['onmic_user'] = [];
                }
                //返回申请队列是否有人举手
                $apply_num = (int)BroadcastRedis::getApplyCohostNum($channel_id);

                $broadcastInfo['is_apply_cohost'] = $apply_num > 0 ? $apply_num : 0;
            } else {
                unset($broadcastInfo['onmic_user']);
            }
            //普通观众，返回有没有申请过上麦
            if ($broadcastInfo['my_role'] == ChannelModerators::role_channel_viewer) {
                $res_apply = BroadcastRedis::checkApplyCohostUser($channel_id, $user_id);
                $broadcastInfo['is_apply_cohost'] = 1;
                if (empty($res_apply) || intval($res_apply) == 0) {
                    $broadcastInfo['is_apply_cohost'] = 0;
                }
            }
        }
        //用户最近加入的房间记录
        BroadcastRedis::addJoinList($user_id, $channel_id);
        return $broadcastInfo;
    }


    /**
     * 新增预约直播
     * @param $channel_id
     * @param $user_id
     * @param $schedule_time
     * @return mixed
     */
    public static function SetUpcomingLive($channel_id, $user_id, $schedule_time)
    {
        return BroadcastRedis::SetExploreChannel($schedule_time, $channel_id, LiveLogic::LiveModelUpcoming);
    }

    public static function RemoveUpcomingLive($channel_id, $user_id)
    {
        return BroadcastRedis::RemoveExploreChannel($channel_id, LiveLogic::LiveModelUpcoming);
    }

    /**
     * 新增正在直播
     * @param $channel_id
     * @param $user_id
     * @return mixed
     */
    public static function SetExploreLiveList($channel_id, $user_id)
    {
        $currentTime = time();
        return BroadcastRedis::SetExploreChannel($currentTime, $channel_id, LiveLogic::LiveModelLiving);
    }

    public static function SendStartLiveNotification($channel_id)
    {
        $queuename = "schedule-notification";
        $sqs = new Livbysqs($queuename);
        return $sqs->send(["channel_id" => $channel_id]);

    }

    /**
     * 发送直播邀请通知
     * @param $channel_id
     * @param $user_id
     * @param $host_id
     * @param $content
     * @return bool
     */
    public static function SendStartLiveCohostInvite($channel_id, $user_id, $host_id, $content)
    {
        $pushData = self::SystemMessageStruct(LiveLogic::notify_type_live, $host_id, $user_id, $content, $channel_id);
        if (empty($pushData)) {
            return false;
        }
        Service::OnesignalNotification($content, array(array("field" => "tag", "key" => "guid", "relation" => "=", "value" => $user_id)), '', $pushData);
        return Agora::JarPushSinglePointMessage($user_id, $channel_id, Agora::SystemNotify, $pushData, true);
    }

    public static function ScheduleLiveNotify($channel_id, $user_id, $host_id, $content)
    {
        $pushData = self::SystemMessageStruct(LiveLogic::notify_type_schedule, $host_id, $user_id, $content, $channel_id);
        if (empty($pushData)) {
            return false;
        }
        Service::OnesignalNotification($content, array(array("field" => "tag", "key" => "guid", "relation" => "=", "value" => $user_id)), '', $pushData);
        return Agora::JarPushSinglePointMessage($user_id, $channel_id, Agora::SystemNotify, $pushData, true);
    }

    /**
     * @param $type int 1:follow, 2: 直播开播邀请
     * @param $op_user string 操作用户
     * @param $user_id string 收消息用户
     * @param $content
     * @param string $channel_id
     * @return array|bool
     */
    public static function SystemMessageStruct($type, $op_user, $user_id, $content, $channel_id = "")
    {
        $userInfo = Service::userinfo($op_user, null, $user_id);
        if (empty($userInfo)) {
            return false;
        }
        $data = [
            "msg_id" => time() . $type . Service::random_hash(6),
            "type" => $type,
            "username" => $userInfo['username'] ?? "",
            "user_id" => $op_user,
            "avatar_small" => $userInfo['avatar_small'] ?? "",
            "content" => $content,
            "timestamp" => time(),
            "target_id" => $channel_id,
        ];
        if ($type != 2) {
            $data["status"] = $userInfo["relation_status"] ?? 0;
        }
        return $data;
    }

    /**
     * 开播前选择featured user
     * @param $channel_id
     * @param $user
     * @return bool|int
     */
    public static function AddFeaturedUserToChannel($channel_id, $user)
    {
        if(empty($channel_id)){
            return false;
        }

        if(empty($user)){
            return 0;
        }
        return BroadcastRedis::AddFeaturedUser($channel_id, $user);
    }

    /**
     * 检查用户是否在featured user列表里面
     * @param $channel_id
     * @param $user_id
     * @return bool|int
     */
    public static function CheckFeaturedUserInChannel($channel_id, $user_id)
    {
        if(empty($channel_id)){
            return false;
        }

        if(empty($user_id)){
            return false;
        }
        return BroadcastRedis::getFeaturedUser($channel_id, $user_id);
    }

    /**
     * 获取一个featured user
     * @param $channel_id
     * @return bool|mixed
     */
    public static function GetFeaturedUser($channel_id)
    {
        if(empty($channel_id)){
            return false;
        }

        $list = BroadcastRedis::getFeaturedUserList($channel_id);
        if (empty($list)){
            return false;
        }
        return $list[0];

    }


}