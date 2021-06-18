<?php

namespace app\components\firebase;

use app\components\Agora;
use app\components\define\ResponseCode;
use app\components\define\SystemConstant;
use app\components\Livbysqs;
use app\components\LiveLogic;
use app\components\redis\BroadcastRedis;
use app\components\redis\LivecastRedis;
use app\components\redis\LiveRedis;
use app\components\redis\ModeratorsRedis;
use app\components\redis\UserRedis;
use app\components\ResponseTool;
use app\models\Channel;
use app\models\ChannelCloudRecording;
use app\models\ChannelModerators;
use app\models\Service;
use Firebase\FirebaseLib;
use Yii;

class FbLivecast
{
    const LOG = 'firebase';
    const MAX_OPEN_VOICE_NUM = 10;//最大开麦人数
    const ROOM_PAth = '/lives/';
    const ROLE = [
        'host' => 10,//房主
        'moderator' => 20,//管理员
        'speaker' => 30,//麦位上的人
        'viewers' => 40,//没在麦位上的用户
    ];

    //用户在直播间里面的状态
    const USER_STATUS = [
        'no_status' => -1,//没有状态
        'online' => 3,//在线
        'hand' => 2,//'举手'
        'open_voice' => 0,//开麦
        'close_voice' => 1,//闭麦
    ];
    //举手模式
    const HAND_MODE = [
        'anyone' => 1,//任何人
        'moderator_follower' => 0,//moderator的关注者
    ];
    //房间属性,	0:不推送 1:推给关注的人 2:所有人都推
    const ROOM_TYPE = [
        'public' => 2,
        'private' => 0,
        'social' => 1,
        'group' => 3,
    ];

    public static $_client;

    public static function _getInstance()
    {
        if (self::$_client instanceof FirebaseLib) {
            return self::$_client;
        }
        $db_url = Yii::$app->params["firebase"]["db_url"] ?? '';
        $secretKey = Yii::$app->params["firebase"]["secretKey"] ?? '';
        if (empty($db_url) || empty($secretKey)) {
            return false;
        }
        self::$_client = new FirebaseLib($db_url, $secretKey);
        return self::$_client;
    }

    //获取一个房间path
    public static function getOneRoomPath($channel_id = '')
    {
        return self::ROOM_PAth . $channel_id;
    }

    //用户信息封装
    public static function getFbFormatUserInfo($channel_id, $user_info = [], $role = self::ROLE['viewers'], $st = self::USER_STATUS['close_voice'])
    {
        $data = [
            'guid' => $user_info['guid'] ?? '',
            'uname' => $user_info['username'] ?? '',
            'avatar' => Service::getCompleteUrl($user_info['avatar']),
            'role' => $role,
            'sk' => $role . time(),
            'uid' => intval($user_info['id'] ?? 0),
            //'badge'  => '',//徽章
            'st' => $st,//上麦默认状态
            'u_type' => isset($user_info["type"]) ? (int)$user_info["type"] : 0,//是否为主播
            'featured' => 0,//是否为featured user
        ];

        if (LiveLogic::CheckFeaturedUserInChannel($channel_id, $data["guid"])) {
            $data["sk"] = 15 . time();//featured user 排在最前面
            $data['ts'] = time();
            $data['featured'] = 1;
            $data['st'] = self::USER_STATUS['open_voice'];
        }

        if ($role == self::ROLE['viewers']) {
            $data['st'] = self::USER_STATUS['no_status'];
        }
        return $data;
    }


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
        $data = [];
        if (empty($broadcastInfo['user_id']) || empty($broadcastInfo['channel_id'])) {
            $broadcastInfo = Channel::getbroadcastInfo($channel_id);
            if (empty($broadcastInfo['user_id']) || empty($broadcastInfo['channel_id'])) {
                ResponseTool::SetMessage("Not found channel or user");
                return [];
            }
        }
        //观众加入直播间一次, total_joined_times数值加1, 结束后写回DB
        BroadcastRedis::numberAdd($channel_id, 'total_joined_times', 1);
        //andrew: can we track number of views each livestream has example if user A watches a livestream and then leaves and comes back the number is 2

        if (empty($user_info)) {
            $user_info = UserRedis::getUserInfo($user_id, ['id', 'guid', 'username', 'avatar']);
        }
        if (empty($user_info['id'])) {
            ResponseTool::SetMessage("Not found user id");
            return [];
        }

        $data["channel_key"] = Agora::generateMediaChannelKey($channel_id, $user_info['id']);
        //生成信令key
        $data["chatroom_id"] = intval($broadcastInfo['id']);
        //$data["status"] = 'Join success.';
        //get anchor avatar
        $anchorInfo = [];
        if ($user_id != $broadcastInfo['user_id']) {
            $anchorInfo = UserRedis::getUserInfo($broadcastInfo["user_id"], ['id', 'guid', 'username', 'avatar', 'avatar_small']);
            if (empty($anchorInfo)) {
                ResponseTool::SetMessage("Not found host info");
                return [];
            }
        } else {
            $anchorInfo = $user_info;
        }

        $avatar = isset($anchorInfo["avatar"]) ? $anchorInfo["avatar"] : "";
        //host 信息
        $data["user_info"] = [
            'avatar' => Service::getCompleteUrl($avatar),
            'avatar_small' => Service::avatar_small($avatar),
            'guid' => $anchorInfo["guid"],
            "username" => $anchorInfo["username"] ?? '',
            'id' => (int)$anchorInfo["id"],
            'type' => 1,
        ];
        /**
         * 如果存在featured user
         */
        if ($featuredUer = LiveLogic::GetFeaturedUser($channel_id)) {
//            $onlineFeaturedUser = FbLivecast::CheckOnlineFeaturedUser($channel_id);
//            if (!empty($onlineFeaturedUser) || $featuredUer == $user_id) {
//                if(!empty($onlineFeaturedUser)){
//                    $featuredUer = $onlineFeaturedUser[0];
//                }
                $featured_info = UserRedis::getUserInfo($featuredUer, ['id', 'guid', 'username', 'avatar', 'avatar_small', 'type']);
                $featuredUserAvatar = isset($featured_info["avatar"]) ? $featured_info["avatar"] : "";
                $data["featured_info"] = [
                    'avatar' => Service::getCompleteUrl($featuredUserAvatar),
                    'avatar_small' => Service::avatar_small($featuredUserAvatar),
                    'guid' => $featured_info["guid"],
                    "username" => $featured_info["username"] ?? '',
                    'id' => (int)$featured_info["id"],
                    'type' => isset($featured_info['type']) ? (int)$featured_info['type'] : 0,
                ];
//            }
        }

        //$data["live_end_time"] = strtotime($broadcastInfo["live_end_time"]) * 1000;
        $data["live_start_time"] = strtotime($broadcastInfo["live_start_time"]) * 1000;
        //获取用户是否被mute
        //$data["mute_status"] = ChannelModerators::IsMute($channel_id, $user_id);
        unset($anchorInfo);
        //$data["bg"] = isset($broadcastInfo["bg"]) ? (string)Service::getCompleteUrl($broadcastInfo["bg"]) : "";
        //房间权限
        $data['my_role'] = self::checkUserRole($user_id, $channel_id, $broadcastInfo);
        $data['send_email'] = empty($broadcastInfo['send_email']) ? '' : $broadcastInfo['send_email'];
        $data['post_status'] = empty($broadcastInfo['post_status']) ? 0 : (int)$broadcastInfo['post_status'];
        $data['channel_id'] = empty($broadcastInfo['channel_id']) ? '' : $broadcastInfo['channel_id'];
        $data['title'] = $broadcastInfo['title'] ?? '';
        $data['type'] = empty($broadcastInfo['type']) ? 1 : (int)$broadcastInfo['type'];
        $data['cover_image'] = $broadcastInfo['cover_image'] ?? '';
        $data['description'] = $broadcastInfo['description'] ?? '';
        $tags = $broadcastInfo['tags'] ?? '';
        $data['tags'] = explode(",", $tags);
        $data['cohost_any'] = empty($broadcastInfo['cohost_any']) ? self::HAND_MODE['anyone'] : (int)$broadcastInfo['cohost_any'];
        $data['short_id'] = $data["user_info"]['id'];
        $data['id'] = empty($broadcastInfo['short_id']) ? 0 : (int)$broadcastInfo['id'];
        $data['live_type'] = empty($broadcastInfo['live_type']) ? 19 : (int)$broadcastInfo['live_type'];
        $is_host_manager = in_array($data['my_role'], [self::ROLE['host'], self::ROLE['moderator']]);
        //管理员跟房主，join的时候返回onmic用户列表,返回申请队列是否有人举手
        if ($is_host_manager) {
            //返回申请队列是否有人举手
            $apply_num = (int)BroadcastRedis::getApplyCohostNum($channel_id);
            $data['is_apply_cohost'] = $apply_num > 0 ? $apply_num : 0;
        } else {
            $res_apply = BroadcastRedis::checkApplyCohostUser($channel_id, $user_id);
            $data['is_apply_cohost'] = 1;
            if (empty($res_apply) || intval($res_apply) == 0) {
                $data['is_apply_cohost'] = 0;
            }
        }
        //返回group信息
        if (empty($broadcastInfo['group_id'])) {
            $broadcastInfo['group_id'] = $broadcastInfo['group_name'] = '';
        }
        //记录到firebase
        if (!self::synJoinDataToFirebase($channel_id, $user_id, $user_info, $data['my_role'])) {
            ResponseTool::SetMessage("Data sync fail");
            return [];
        }
        //用户最近加入的房间记录
        //BroadcastRedis::addJoinList($user_id, $channel_id);
        return $data;
    }

    /**
     * 同步用户信息到firebase
     * @param string $channel_id
     * @param string $user_id
     * @param array $user_info
     * @param mixed $role
     * @return bool
     */
    public static function synJoinDataToFirebase($channel_id = '', $user_id = '', $user_info = [], $role = FbLivecast::ROLE['viewers'])
    {
        if (empty($channel_id)) {
            return false;
        }
        if (empty($user_info['guid'])) {
            $user_info = UserRedis::getUserInfo($user_id, ['id', 'guid', 'username', 'avatar', 'avatar_small']);
        }
        if (empty($user_id) && empty($user_info['guid'])) {
            return false;
        }
        $db_url = Yii::$app->params["firebase"]["db_url"] ?? '';
        $secretKey = Yii::$app->params["firebase"]["secretKey"] ?? '';
        if (empty($db_url) || empty($secretKey)) {
            return false;
        }
        $arr = $up = [];
        $response = "";
        $defalut_path = FbLivecast::getOneRoomPath($channel_id);
        $slot_path = $defalut_path . '/slots/' . $user_id;
        $viewers_path = $defalut_path . '/viewers/' . $user_id;
        $featured_path = $defalut_path . '/featured/' . $user_id;
        $firebase = new FirebaseLib($db_url, $secretKey);
        if ($role == FbLivecast::ROLE['viewers']) {
            $arr = FbLivecast::getFbFormatUserInfo($channel_id, $user_info, FbLivecast::ROLE['viewers']);
            if ($firebase->get($slot_path . '/sk') != 'null') {
                $firebase->delete($slot_path);
            }
            $response = $firebase->set($viewers_path, $arr);
        } else {
            $arr = FbLivecast::getFbFormatUserInfo($channel_id, $user_info, $role);
            if ($firebase->get($viewers_path . '/sk') != 'null') {
                $firebase->delete($viewers_path);
            }
            //如果是管理员需要更新管理列表
            $managers = self::getManagerFromFb($channel_id);
            if ($managers) {
                $up['manager'] = implode(',', $managers);
            }
            if ($role == FbLivecast::ROLE['host']) {
                $arr['st'] = FbLivecast::USER_STATUS['open_voice'];
            } else {
                //麦上有10人开麦则闭麦
                if ($arr['st'] == FbLivecast::USER_STATUS['open_voice']) {
                    $openMicUser = self::GetSlotsOpenMicUser($channel_id);
                    if (count($openMicUser) >= 10) {
                        $arr['st'] = FbLivecast::USER_STATUS['close_voice'];
                    }
                }
            }

            //featured user不写到slot
            if ($arr["featured"] != 1) {
                if ($role != FbLivecast::ROLE['host']) {
                    $response = $firebase->set($slot_path, $arr);
                }
            } else {
                //featured 写入firebase
                $firebase->set($featured_path, $arr);
                //host写入firebase
//                $host_id = BroadcastRedis::getbroadcastInfo($channel_id, "user_id");
//                $host_info = UserRedis::getUserInfo($host_id, ['id', 'guid', 'username', 'avatar']);
//                $arr = FbLivecast::getFbFormatUserInfo($channel_id, $host_info, FbLivecast::ROLE['host'], self::USER_STATUS['open_voice']);
//                $host_slot_path =  $defalut_path . '/slots/' . $host_id;
//                $firebase->set($host_slot_path, $arr);
            }
//            $onlineFeaturedUser = self::CheckOnlineFeaturedUser($channel_id);
            if ($role == FbLivecast::ROLE['host'] && LiveLogic::GetFeaturedUser($channel_id)) {//存在feature时host写入slots
//                if (!empty($onlineFeaturedUser)) {
                    $response = $firebase->set($slot_path, $arr);
//                }
            }
//            if(empty($onlineFeaturedUser)){//feature 不在线
//                //把feature放到cohost
//                $featured_user_id = LiveLogic::GetFeaturedUser($channel_id);
//                if(!empty($featured_user_id)){
//                    $featured_info = UserRedis::getUserInfo($featured_user_id, ['id', 'guid', 'username', 'avatar']);
//                    $featuredArr = FbLivecast::getFbFormatUserInfo($channel_id, $featured_info, FbLivecast::ROLE['speaker']);
//                    $featuredArr["st"] = self::USER_STATUS['close_voice'];
//                    $host_slot_path =  $defalut_path . '/slots/' . $featured_user_id;
//                    $firebase->set($host_slot_path, $featuredArr);
//                }
//            }

//            if($user_id == LiveLogic::GetFeaturedUser($channel_id)){
//                $host_slot_path =  $defalut_path . '/slots/' . $user_id;
//                $firebase->delete($host_slot_path);
//            }
        }

        if ($response == 'null') {
            \Yii::info('join room data up fail,path=' . $defalut_path . ',data=' . json_encode($arr), self::LOG);
            return false;
        }
        if ($role != FbLivecast::ROLE['viewers']) {
            //记录麦位人数
            LivecastRedis::rememberVoiceNumber($channel_id, 1);
            if ($role == FbLivecast::ROLE['host'] && $arr['st'] == FbLivecast::USER_STATUS['open_voice']) {
                LivecastRedis::rememberOpenVoiceNumber($channel_id);
            }
        }
        if ($up) {
            BroadcastRedis::broadcastInfo($channel_id, $up);
        }
        try {
            //防止用户杀掉app或者掉线后再进其他房间，出现1个人头像出现2房间问题
            $last_join_channel_id = '';
            $last_join_channel_id = LivecastRedis::getUserLatelyJoinRoom($user_id);
            if ($last_join_channel_id && $last_join_channel_id != $channel_id) {
                self::synLeaveDataToFirebase($last_join_channel_id, $user_id);
            }

            if ($role == FbLivecast::ROLE['host'] || $role == FbLivecast::ROLE['moderator']) {
                //今天这个直播间上麦没发送过推送就发送上麦推送
                self::checkIsPushJoinSlot($user_id, $channel_id);
            }
            self::sendSlotChangeInfo($channel_id);
        } catch (\Exception $e) {

        }
        //记录用户最近一次livecast进的房间
        LivecastRedis::rememberUserLatelyJoinRoom($user_id, $channel_id);
        //记录房间人数
        $viewers = BroadcastRedis::numberAdd($channel_id, 'live_current_audience', 1);
        //记录房间观众列表
        BroadcastRedis::addAudience($channel_id, $user_id);
        //更新redis直播列表分数
        self::calculationLivecastScore($channel_id, $viewers);
        //从申请列表移除，防止那种邀请上麦的如果申请，上槽后没有被移除
        BroadcastRedis::remApplyCohostList($channel_id, $user_id);

        return true;
    }

    /**
     * 一段时间发送一个800，让其短线的用户拉去接口来刷新直播间槽位信息
     * @param string $channel_id
     */
    public static function sendSlotChangeInfo($channel_id = '')
    {
        //一段时间发送一个800，让其短线的用户拉去接口来刷新直播间槽位信息
        $is_in_slot_limit = LivecastRedis::getLivecastSlotChange($channel_id);
        if (empty($is_in_slot_limit)) {
            Agora::JarPushMessage($channel_id, Agora::AudioShareDiamond, ['channel_id' => $channel_id]);
            LivecastRedis::setLivecastSlotChange($channel_id);
        }
    }


    /**
     * @param string $channel_id
     * @param int $viewers
     * @param array $broadcastInfo
     * @return bool
     */
    public static function calculationLivecastScore($channel_id = '', $viewers = 0, $broadcastInfo = [])
    {
        if (empty($channel_id) || $viewers <= 0) {
            return false;
        }
        $broadcastInfo = empty($broadcastInfo) || !is_array($broadcastInfo) ? Channel::getbroadcastInfo($channel_id, ['post_status', 'type']) : [];
        if (isset($broadcastInfo['post_status']) && $broadcastInfo['post_status'] !== false) {
            if ($broadcastInfo['post_status'] == self::ROOM_TYPE['public'] && isset($broadcastInfo['type']) && $broadcastInfo['type'] == Channel::type_status_living) {
                //更新redis直播列表分数
                $score = LiveRedis::getChannelOrderScore(Channel::liveCastLive, $channel_id);
                if ($score < 2000000000) {
                    LiveRedis::addBigLiveList(Channel::liveCastLive, $channel_id, $viewers);
                }
            } else {
                //清除大列表
                LiveRedis::remLiveList(Channel::liveCastLive, '', $channel_id);
            }
        }
        return true;
    }

    public static function synLeaveDataToFirebase($channel_id = '', $user_id = '', $role = FbLivecast::ROLE['viewers'])
    {
        if (empty($channel_id)) {
            return false;
        }
        if (empty($user_id)) {
            return false;
        }
        if (empty($role)) {
            $role = self::checkUserRole($user_id, $channel_id);
        }
        $db_url = Yii::$app->params["firebase"]["db_url"] ?? '';
        $secretKey = Yii::$app->params["firebase"]["secretKey"] ?? '';
        if (empty($db_url) || empty($secretKey)) {
            return false;
        }
        $arr = $up = [];
        $defalut_path = FbLivecast::getOneRoomPath($channel_id);
        $firebase = new FirebaseLib($db_url, $secretKey);
        $slot_path = $defalut_path . '/slots/' . $user_id;
        $viewers_path = $defalut_path . '/viewers/' . $user_id;
        $is_in_slot_info = $firebase->get($slot_path . '/st');
        $is_in_viewers_info = $firebase->get($viewers_path . '/st');
        //\Yii::info('202139user_id='.$user_id.',$channel_id='.$channel_id.',is_in_slot_info='.$is_in_slot_info.',is_in_viewers_info='.$is_in_viewers_info,'my');
        //\Yii::info('202139is_in_slot_info type='.gettype($is_in_slot_info),'my');
        $response = '';
        if ($is_in_slot_info !== 'null') {
            //在slot里面
            $response = $firebase->delete($slot_path);
        } elseif ($is_in_viewers_info !== 'null') {
            //在viewer里面
            $response = $firebase->delete($viewers_path);
        } else {
            $response = $firebase->delete($slot_path);
            $response = $firebase->delete($viewers_path);
        }
        if ($is_in_slot_info !== 'null' && $role != FbLivecast::ROLE['viewers'] && $role != FbLivecast::ROLE['speaker']) {
            //如果是管理员需要更新管理列表
            $managers = self::getManagerFromFb($channel_id);
            if ($managers) {
                $up['manager'] = implode(',', $managers);
            }
        }

        if ($response != 'null') {
            \Yii::info('leave room data up fail,path=' . $defalut_path . '$response=' . json_encode($response), self::LOG);
        }
        self::leaveOtherOprate($channel_id, $user_id, $role, $is_in_slot_info);
        return true;
    }

    //离开时一些redis操作
    public static function leaveOtherOprate($channel_id, $user_id, $role, $is_open_st)
    {
        $up = [];
        self::dealSlotStatistics($role, $channel_id, $is_open_st);
        //记录房间数
        $number = BroadcastRedis::numberAdd($channel_id, 'live_current_audience', -1);
        BroadcastRedis::remAudience($channel_id, $user_id);
        if ($number < 0) {
            //防止负数
            $up['live_current_audience'] = 0;
        }
        if ($up) {
            BroadcastRedis::broadcastInfo($channel_id, $up);
        }
        //更新redis直播列表分数
        self::calculationLivecastScore($channel_id, $number);
        //移除加入过这个房间记录，以达到 掉线离开后段没有请求到，上一房间没有移除问题
        LivecastRedis::delUserLatelyJoinRoom($user_id);
        self::sendSlotChangeInfo($channel_id);
    }

    /**
     * 下槽时的槽位相关的统计处理
     * @param $role
     * @param $channel_id
     * @param $is_open_st
     */
    public static function dealSlotStatistics($role, $channel_id, $is_open_st)
    {
        if ($role != FbLivecast::ROLE['viewers']) {
            //记录麦位人数
            LivecastRedis::rememberVoiceNumber($channel_id, -1);
        }
        if ($is_open_st !== 'null' && $is_open_st == self::USER_STATUS['open_voice']) {
            LivecastRedis::rememberOpenVoiceNumber($channel_id, -1);
        }
    }

    /**
     * 检查用户权限
     * @param string $user_id
     * @param string $channel_id
     * @param array $broadcastinfo
     * @return mixed
     */
    public static function checkUserRole($user_id = '', $channel_id = '', $broadcastinfo = [])
    {
        if (empty($user_id)) {
            return self::ROLE['viewers'];
        }
        if (empty($broadcastinfo)) {
            $broadcastinfo = Channel::getbroadcastInfo($channel_id, ['user_id', 'manager', 'extra']);
        }
        if (empty($broadcastinfo) && empty($channel_id)) {
            return self::ROLE['viewers'];
        }
        if ($user_id == $broadcastinfo['user_id']) {
            return self::ROLE['host'];
        }
        if (!empty($broadcastinfo['manager'])) {
            $manager_ids = [];
            $manager_ids = explode(',', $broadcastinfo['manager']);
            if (is_array($manager_ids) && in_array($user_id, $manager_ids)) {
                return self::ROLE['moderator'];
            }
        }
        if (ChannelModerators::CheckMaModerators($user_id)) {
            return self::ROLE['moderator'];
        }
        $moderatorList = ChannelModerators::GetAllModerators($broadcastinfo['user_id'], $channel_id);  //比较消耗性能
        if (in_array($user_id, $moderatorList)) {
            return self::ROLE['moderator'];
        }
        //检查是否为speaker
        if (FbLivecast::GetSlotsUser($channel_id, $user_id)) {
            return self::ROLE['speaker'];
        }
        //featured用户加入就为speaker
        if (LiveLogic::CheckFeaturedUserInChannel($channel_id, $user_id)) {
            return self::ROLE['moderator'];
        }

        //cohost 默认加入到speaker
        if(isset($broadcastinfo["extra"]) && !empty($broadcastinfo["extra"])){
            $extra = json_decode($broadcastinfo["extra"], true);
            $feature_cohost = $extra["schedule_cohost"] ?? [];
            if(in_array($user_id, $feature_cohost)){
                return self::ROLE['speaker'];
            }
        }
        return self::ROLE['viewers'];
    }

    //关播
    public static function Close($channel_id, $user_id, $info = [])
    {
        $channel = Channel::find()->where(['guid' => $channel_id])->one();
        //先修改redis里的数据
        $channel->live_end_time = date('Y-m-d H:i:s');
        $channel->type = '2';
        $channel->is_live = 'no';
        $channel->comment_count = isset($info['comment_count']) ? $info['comment_count'] : 0;

        $info['like_count'] = isset($info['like_count']) ? $info['like_count'] : 0;

        $startTimestamp = strtotime($channel->live_start_time);
        if ($startTimestamp == 0) {
            $startTimestamp = strtotime($channel->updated_at);
            if ($startTimestamp == 0) {
                $startTimestamp = time();
            }
        }

        $duration = time() - $startTimestamp;
        BroadcastRedis::broadcastInfo($channel_id, ['like_heart' => $info['like_count'], 'type' => 2, 'duration' => $duration]);
        $gifts = isset($info['gifts']) ? intval($info['gifts']) : 0;
        $coins = isset($info['golds']) ? intval($info['golds']) : 0;
        $diamonds = isset($info['diamonds']) ? intval($info['diamonds']) : 0;
        $bot_count = isset($info['bot_count']) ? intval($info['bot_count']) : 0;

        //将点赞数量写到数据库
        $channel->like_count = $info['like_count'];
        if (isset($info['live_highest_audience'])) {
            $channel->live_highest_audience = $info['live_highest_audience'];
        }
        $channel->duration = $duration;
        $channel->gifts = $gifts;
        $channel->diamonds = $diamonds;


        //andrew: can we track number of views each livestream has example if user A watches a livestream and then leaves and comes back the number is 2
        // /broadcast/join 接口添加的数据写会数据库
        $channel->total_joined_times = isset($info['total_joined_times']) ? $info['total_joined_times'] : 0;
        $channel->total_viewer = $total_viewer = intval($channel->total_joined_times) + $bot_count;

        if (!$channel->save()) {
            \Yii::info('closeBroadcast save fail:' . json_encode($channel->getErrors()), 'my');
            return [];
        }
        $broadcastInfo = Service::reloadBroadcast($channel_id);
        ModeratorsRedis::DelMuteKey($channel_id);
        try {
            // Agora::StopSignalJar($channel_id);
            //移除firebase全部此直播间数据
            self::RemoveBroadcastRedisKey($channel_id, $user_id);
            //清除槽位人数统计
            LivecastRedis::delVoiceNumber($channel_id);
            //清除槽位开麦人数统计
            LivecastRedis::delOpenVoiceNumber($channel_id);
            //清除大列表
            LiveRedis::remLiveList(LiveLogic::LiveModelLiving, '');
            LiveRedis::remLiveList(LiveLogic::LiveModelUpcoming, '');

            //清楚申请举手列表
            BroadcastRedis::delApplyCohost($channel_id);

            if (isset($broadcastInfo['send_email']) && !empty($broadcastInfo['send_email'])) {
                // 关播结束语音录制逻辑
                ChannelCloudRecording::EndRec($channel_id);
            }
            //发送让其他人离开直播间的频道消息
            Agora::JarPushMessage($channel_id, Agora::JEEPNEY_CLOSE, ['channel_id' => $channel_id, 'need_msg' => true, 'content' => 'The room owner just ended this room.']);
        } catch (\Exception $e) {
            \Yii::info('closeBroadcast save fail:' . json_encode($e->getMessage()), 'my');
        }
        return ['user_id' => $user_id, 'host_type' => 0, 'like_count' => intval($info['like_count']), 'audience_count' => intval($total_viewer), 'duration' => intval($duration), 'channel_id' => $channel_id, 'gifts' => $gifts, 'golds' => $coins, 'is_group' => false, 'status' => 'Leave success.'];
    }

    //移出firebase数据
    public static function RemoveBroadcastRedisKey($channel_id = '', $user_id)
    {
        if (empty($channel_id)) {
            return true;
        }
        $db_url = Yii::$app->params["firebase"]["db_url"] ?? '';
        $secretKey = Yii::$app->params["firebase"]["secretKey"] ?? '';
        if (empty($db_url) || empty($secretKey)) {
            return false;
        }
        $firebase = new FirebaseLib($db_url, $secretKey);
        $defalut_path = FbLivecast::getOneRoomPath($channel_id);
        $response = $firebase->delete($defalut_path);
        if ($response != 'null') {
            \Yii::info('Delete room data fail,path=' . $defalut_path, self::LOG);
            return false;
        }
        return true;
    }

    /**
     * 更新房间管理员
     * @param string $channel_id
     * @return bool
     */
    public static function reloadLiveCastManager($channel_id = '', $admin_info = [])
    {
        if ($channel_id) {
            $manager = empty($admin_info) || !is_array($admin_info) ? self::getManagerFromFb($channel_id) : array_values($admin_info);
            $manager_str = '';
            if ($manager) {
                $manager_str = implode(',', $manager);
            }
            return BroadcastRedis::broadcastInfo($channel_id, ['manager' => $manager_str]);
        }
        return false;
    }

    /**
     * 从firebase获取管理员
     * @param string $channel_id
     * @return array
     */
    public static function getManagerFromFb($channel_id = '')
    {
        if ($channel_id) {
            $db_url = Yii::$app->params["firebase"]["db_url"] ?? '';
            $secretKey = Yii::$app->params["firebase"]["secretKey"] ?? '';
            if (empty($db_url) || empty($secretKey)) {
                return [];
            }
            $firebase = new FirebaseLib($db_url, $secretKey);
            $defalut_path = FbLivecast::getOneRoomPath($channel_id);
            $response = $firebase->get($defalut_path . '/admin');
            if ($response == 'null') {
                \Yii::info('Delete room data fail,path=' . $defalut_path, self::LOG);
                return [];
            }
            $response = json_decode($response, true);
            if (is_array($response)) {
                return array_values($response);
            }
        }
        return [];
    }

    /**上槽
     * @param $channel_id
     * @param $user_id
     * @param mixed $role 默认普通上槽为speaker,特殊情况有其他角色，比如指定管理员
     * @return array
     */
    public static function JoinAudioCost($channel_id, $user_id, $role = self::ROLE['speaker'])
    {
        $response = ["error" => true, "code" => ResponseCode::Fail];
        //判断最大槽位数
//        if($role == self::ROLE['speaker'] ){
//            $now_slot_number = LivecastRedis::getVoiceNumber($channel_id);
//            if($now_slot_number > 30 ){
//                $response["message"] = "There are too many people on the mic. Please remove speakers from mic first.";
//                return $response;
//            }
//        }
        //用户已经存在
        $db_url = Yii::$app->params["firebase"]["db_url"] ?? '';
        $secretKey = Yii::$app->params["firebase"]["secretKey"] ?? '';
        if (empty($db_url) || empty($secretKey)) {
            $response["message"] = "Missing critical configuration, please contact the administrator.";
            return $response;
        }
        //获取用户信息
        $user_info = UserRedis::getUserInfo($user_id, ['id', 'guid', 'username', 'avatar', 'avatar_small']);
        if (empty($user_info['guid'])) {
            $response["message"] = "User not found.";
            return $response;
        }
        try {
            $firebase = new FirebaseLib($db_url, $secretKey);
            $defalut_path = FbLivecast::getOneRoomPath($channel_id);
            //一次性删除加修改,//1.从观众里面移除,2.加入到上面槽位
            $result = [];
            $result = $firebase->get($defalut_path);
            if ($result == 'null') {
                throw new \Exception("Join failed.");
            }
            $result = json_decode($result, true);
            if (empty($result) || !is_array($result)) {
                throw new \Exception("Join failed.2");
            }
            $slot_open_voice_number = 0;//开麦人数
            if (!isset($result['slots'])) {
                $result['slots'] = [];
            }
            if (in_array($role, [self::ROLE['speaker'], self::ROLE['moderator']]) && is_array($result['slots'])) {
                $slot_number = count($result['slots']);//槽位人数
                if ($slot_number > 0) {
                    $is_up_slot_redis_info = mt_rand(1, 2) == 1;
                    if ($is_up_slot_redis_info) {
                        BroadcastRedis::delIngCohostList($channel_id);
                    }
                    foreach ($result['slots'] as $v) {
                        if (isset($v['st']) && $v['st'] == self::USER_STATUS['open_voice']) {
                            $slot_open_voice_number++;
                        }
                        if ($is_up_slot_redis_info && !empty($v['guid'])) {
                            BroadcastRedis::setIngCohostList($channel_id, $v['guid']);
                        }
                    }
                }
                LivecastRedis::rememberVoiceNumber($channel_id, $slot_number, true);
                LivecastRedis::rememberOpenVoiceNumber($channel_id, $slot_open_voice_number, true);
                if ($slot_number > 30) {
                    throw new \Exception("There are too many people on the mic. Please remove speakers from mic first.");
                }
            }
//            if (isset($result['slots'][$user_id])) {
//                throw new \Exception("There are already users at this slots");
//            }
            if (isset($result['viewers'][$user_id])) {
                unset($result['viewers'][$user_id]);
            }
            $st = self::USER_STATUS['close_voice'];
            if ($slot_open_voice_number <= 10) {
                $st = self::USER_STATUS['open_voice'];
            }
            $userinfo = self::getFbFormatUserInfo($channel_id, $user_info, $role, $st);
            $result['slots'][$user_id] = $userinfo;
            if ($role == FbLivecast::ROLE['moderator']) {
                $result['admin'][$user_id] = $user_id;
            }
            $re = $firebase->update($defalut_path, $result);
            if ($re != 'null') {
                Agora::JarPushSinglePointMessage($user_id, $channel_id, Agora::LIVE_ROLE_CHANGE, ['my_role' => $userinfo['role'], 'channel_id' => $channel_id, 'st' => (int)$userinfo['st'], 'apply_num' => (int)BroadcastRedis::getApplyCohostNum($channel_id)]);
            }
            //今天这个直播间上麦没发送过推送就发送上麦推送
            self::checkIsPushJoinSlot($user_id, $channel_id);
            self::sendSlotChangeInfo($channel_id);
            BroadcastRedis::setIngCohostList($channel_id, $user_id);
        } catch (\Exception $e) {
            $response["message"] = $e->getMessage();
            return $response;
        }
        //记录麦位人数
        //LivecastRedis::rememberVoiceNumber($channel_id);
        //判断是否只有好友能加入
        $response["error"] = false;
        $response["message"] = "success";
        return $response;
    }

    /**今天这个直播间上麦没发送过推送就发送上麦推送
     * @param string $user_id
     * @param string $channel_id
     * @return bool
     */
    public static function checkIsPushJoinSlot($user_id = '', $channel_id = '')
    {
        if (!LivecastRedis::isInUserDayPushInfoInfo($user_id, $channel_id)) {
            $queue_name = Yii::$app->params["sqs"]['onesignal'] ?? '';
            if (empty($queue_name)) {
                return false;
            }
            $sqs = new Livbysqs($queue_name);
            $channel_info = BroadcastRedis::getbroadcastInfo($channel_id, ['title', 'post_status', 'group_id']);
            $post_status = $channel_info['post_status'] ?? '';
            if (empty($post_status) || ($post_status != FbLivecast::ROOM_TYPE['public'] && $post_status != FbLivecast::ROOM_TYPE['group'])) {
                return false;
            }
            $channel_title = $channel_info['title'] ?? '';
            $push_title = $username = UserRedis::getUserInfo($user_id, 'username');
            $username = empty($username) ? 'I am' : $username . ' are';
            $message = $username . ' talking about "' . $channel_title . '" on ' . SystemConstant::APPNAME . '.';
            $check = Service::authorization();
            $feed_tag_message = [
                "users" => [],
                'title' => $push_title,
                "content" => $message,
                'data' => array("channel_id" => $channel_id, 'type' => '400', 'live_type' => '19'),
                'extra' => ["large_icon" => '', "ios_attachments" => ["large_icon" => '']],
                'header' => $check,
                'is_mul_push' => true,//是不是多人同时推送
                'mul_push_type' => 1,
                "user_id" => $user_id,
                'post_status' => $post_status,
                'group_id' => $channel_info['group_id'] ?? '',
            ];
            return $sqs->send($feed_tag_message);
        }
        return false;
    }


    /**
     * 从房间移除一个人
     * @param string $user_id
     * @param string $channel_id
     * @return bool
     */
    public static function kickOneUser($user_id = '', $channel_id = '')
    {
        if ($user_id && $channel_id) {
            return self::synLeaveDataToFirebase($channel_id, $user_id, '');
        }

        return false;
    }

    /**
     * 下槽
     * @param string $user_id
     * @param string $channel_id
     * @param string $role 下槽用户角色
     * @param string $op_role 如果是被动下槽，操作这角色
     * @return array
     */
    public static function LeaveAudioCost($user_id = '', $channel_id = '', $role = '', $op_role = '', $is_must_check_on_slot = false, $liveRoom = 0)
    {
        $response = ["error" => true, "code" => ResponseCode::Fail];
        if ($user_id && $channel_id) {
            $db_url = Yii::$app->params["firebase"]["db_url"] ?? '';
            $secretKey = Yii::$app->params["firebase"]["secretKey"] ?? '';
            if (empty($db_url) || empty($secretKey)) {
                $response["message"] = "Missing critical configuration, please contact the administrator.";
                return $response;
            }
            if (empty($role)) {
                $role = self::checkUserRole($user_id, $channel_id);
            }
            //房主管理员不能下槽
            if ($role == FbLivecast::ROLE['host']) {
                $response["message"] = "Owners are not allowed to go down";
                return $response;
            }
            //if($role == FbLivecast::ROLE['moderator'] && $op_role != FbLivecast::ROLE['host'] ){
            //    $response["message"] = "Moderator are not allowed to go down";
            //    return $response;
            //ty56u}
//            if (ChannelModerators::CheckMaModerators($user_id)) {
//                $response["message"] = "Super moderator are not allowed to go down";
//                return $response;
//            }

            $firebase = new FirebaseLib($db_url, $secretKey);
            $defalut_path = FbLivecast::getOneRoomPath($channel_id);
            $result = $firebase->get($defalut_path);
            if ($result != 'null') {
                $result = json_decode($result, true);
                if (empty($result) || !is_array($result)) {
                    $response["message"] = "Failed.";
                    return $response;
                }
                $uinfo = [];
                $is_open_st = 'null';
                if (isset($result['slots'][$user_id])) {
                    $uinfo = $result['slots'][$user_id];
                    $is_open_st = $result['slots'][$user_id]['st'] ?? 'null';
                    unset($result['slots'][$user_id]);
                } else {
                    if ($is_must_check_on_slot) {
                        $response["message"] = "The user is not in the slot.";
                        return $response;
                    }
                }
                if (!$liveRoom) {//直接离开房间不需要回到观众席
                    if (empty($uinfo['guid'])) {
                        $uinfo = UserRedis::getUserInfo($user_id, ['username', 'guid', 'avatar', 'avatar_small', 'id']);
                        if (empty($uinfo['guid'])) {
                            $response["message"] = "User info not find.";
                            return $response;
                        }
                        $uinfo = self::getFbFormatUserInfo($channel_id, $uinfo, FbLivecast::ROLE['viewers']);
                    }
                    $uinfo['role'] = FbLivecast::ROLE['viewers'];
                    $uinfo['sk'] = FbLivecast::ROLE['viewers'] . time();
                    $result['viewers'][$user_id] = $uinfo;
                }

                if (isset($result['admin'][$user_id])) {
                    unset($result['admin'][$user_id]);
                }
                $result3 = $firebase->update($defalut_path, $result);
                if ($result3 != 'null') {
                    $response["error"] = false;
                    $response["message"] = "success";
                    $response['role'] = $uinfo['role'] ?? FbLivecast::ROLE['viewers'];
                    $response['st'] = isset($uinfo['st']) ? (int)$uinfo['st'] : -1;
//                    Agora::JarPushSinglePointMessage($user_id, $channel_id, Agora::LIVE_ROLE_CHANGE, ['my_role' => $response['role'], 'channel_id' => $channel_id, 'st' => $response['st'], 'apply_num' => (int)BroadcastRedis::getApplyCohostNum($channel_id)]);
                    FbLivecast::reloadLiveCastManager($channel_id);
                    self::dealSlotStatistics($role, $channel_id, $is_open_st);
                    self::sendSlotChangeInfo($channel_id);
                    BroadcastRedis::remIngCohostList($channel_id, $user_id);
                    return $response;
                }

            } else {
                $response["message"] = "Failed.3";
                return $response;
            }
        }
        $response["message"] = "Failed.";
        return $response;
    }

    /**
     * 静音关/开
     * @param string $channel_id
     * @param string $user_id
     * @param string $mute_status
     * @return bool
     */
    public static function muteOprate($channel_id = '', $user_id = '', $mute_status = '')
    {
        $response = ["error" => true, "code" => ResponseCode::Fail];
        if ($channel_id && $user_id) {
            $db_url = Yii::$app->params["firebase"]["db_url"] ?? '';
            $secretKey = Yii::$app->params["firebase"]["secretKey"] ?? '';
            if (empty($db_url) || empty($secretKey)) {
                $response["message"] = "Missing critical configuration, please contact the administrator.";
                return $response;
            }
            $firebase = new FirebaseLib($db_url, $secretKey);
            $defalut_path = FbLivecast::getOneRoomPath($channel_id);
            if ($mute_status == self::USER_STATUS['open_voice']) {
                //判断开麦人数
                if (LivecastRedis::getOpenVoiceNumber($channel_id) >= 11) {
                    $response["message"] = "There are too many users speaking right now. You can mute if you dont want to speak now.";
                    return $response;
                }
            }

            $result = $firebase->get($defalut_path . '/slots/' . $user_id . '/sk');
            if ($result == 'null') {
//                $response["message"] = "This user is not on the Mic.";
                $response["error"] = false;
                $response["message"] = "success";
                return $response;
            }
            $result = $firebase->update($defalut_path . '/slots/' . $user_id, ['st' => (int)$mute_status]);
            if ($result == 'null') {
                $response["message"] = "Switch failed.";
                return $response;
            }
            if ($mute_status == self::USER_STATUS['open_voice']) {
                LivecastRedis::rememberOpenVoiceNumber($channel_id);
            } else {
                LivecastRedis::rememberOpenVoiceNumber($channel_id, -1);
            }
        }
        $response["error"] = false;
        $response["message"] = "success";
        self::sendSlotChangeInfo($channel_id);
        return $response;
    }

    /**
     * 切换房主
     * @param string $channel_id
     * @param string $appoint_id 制定用户id，没有就按 先进admin为房主，其次speaker,再没有就关播
     * @param string $op_user_id 操作人id
     * @return array
     */
    public static function changeAuthor($channel_id = '', $op_user_id = '', $appoint_id = '', $is_leave = false)
    {
        $response = ["error" => true, "code" => ResponseCode::Fail];
        try {
            if ($channel_id) {
                $broadcastInfo = Channel::getbroadcastInfo($channel_id, ['user_id', 'manager']);
                if (empty($broadcastInfo) || !isset($broadcastInfo['user_id'])) {
                    throw new \Exception("Resource doesn't exist.");
                }
                if ($op_user_id != $broadcastInfo['user_id']) {
                    throw new \Exception("You are not host.");
                }
                $old_author_id = $broadcastInfo['user_id'];
                $db_url = Yii::$app->params["firebase"]["db_url"] ?? '';
                $secretKey = Yii::$app->params["firebase"]["secretKey"] ?? '';
                if (empty($db_url) || empty($secretKey)) {
                    throw new \Exception("Missing critical configuration, please contact the administrator.");
                }
                $channel_model = Channel::find()->where(['guid' => $channel_id, 'user_id' => $op_user_id])->one();
                if (empty($channel_model)) {
                    throw new \Exception("Resource doesn't exist.v2");
                }
                $is_close_live = false;
                //修改firebase
                $firebase = new FirebaseLib($db_url, $secretKey);
                $defalut_path = FbLivecast::getOneRoomPath($channel_id);
                $slots_info_json = $firebase->get($defalut_path);
                $slots_info = empty($slots_info_json) ? [] : json_decode($slots_info_json, true);
                if ($appoint_id) {
                    //走指定流程
                } else {
                    //自动流程
                    if ($slots_info_json == 'null') {
                        //关播
                        $is_close_live = true;
                    } else {
                        if (!empty($slots_info['slots']) && $slots_info && is_array($slots_info)) {
                            $moderator_guids = $speaker_guids = [];
                            //找最早的admin和speaker
                            foreach ($slots_info['slots'] as $one) {
                                if (isset($one['role']) && isset($one['sk']) && in_array($one['role'], [FbLivecast::ROLE['moderator'], FbLivecast::ROLE['speaker']])) {
                                    if ($one['role'] == FbLivecast::ROLE['moderator']) {
                                        $moderator_guids[$one['sk']] = $one;
                                    } else {
                                        $speaker_guids[$one['sk']] = $one;
                                    }
                                }
                            }
                            if ($moderator_guids) {
                                $min_sk = min(array_column($moderator_guids, 'sk'));
                                if ($min_sk) {
                                    $appoint_id = $moderator_guids[$min_sk]['guid'];
                                }
                            } else {
                                if ($speaker_guids) {
                                    $min_sk = min(array_column($speaker_guids, 'sk'));
                                    if ($min_sk) {
                                        $appoint_id = $speaker_guids[$min_sk]['guid'];
                                    }
                                }
                            }
                        } else {
                            $is_close_live = true;
                        }

                    }
                }
                if (empty($appoint_id)) {
                    $is_close_live = true;
                }
                \Yii::info('202139:$channel_id=' . $channel_id . ',$is_close_live=' . $is_close_live . ',$appoint_id=' . $appoint_id . ',$old_author_id=' . $old_author_id, 'my');
                if ($is_close_live) {
                    $close_return = FbLivecast::Close($channel_id, $old_author_id, Channel::getbroadcastInfo($channel_id));
                } else {
                    $channel_model->user_id = $moderator_guid = $appoint_id;

                    //查询原主播和新主播槽位信息
                    if (!isset($slots_info['slots'][$old_author_id]['st'])) {
                        throw new \Exception('The author is not on mac.');
                    }
                    $old_author_slot_st = $slots_info['slots'][$old_author_id]['st'];
                    if (!isset($slots_info['slots'][$moderator_guid]['st'])) {
                        throw new \Exception('The moderator user is not on mac.');
                    }
                    $moderator_guid_slot_st = $slots_info['slots'][$moderator_guid]['st'];

                    //修改房间信息
                    $old_moderator_guid_info = $slots_info['slots'][$moderator_guid] ?? [];
                    if (empty($old_moderator_guid_info['guid'])) {
                        $moderator_user_info = UserRedis::getUserInfo($moderator_guid, ['username', 'guid', 'avatar', 'avatar_small', 'id']);
                        $old_moderator_guid_info = self::getFbFormatUserInfo($channel_id, $moderator_user_info, FbLivecast::ROLE['host']);
                    } else {
                        $old_moderator_guid_info['role'] = FbLivecast::ROLE['host'];
                        $old_moderator_guid_info['sk'] = FbLivecast::ROLE['host'] . time();
                    }
                    $slots_info['slots'][$moderator_guid] = $old_moderator_guid_info;

                    //离开的话直接移除
                    if ($is_leave) {
                        unset($slots_info['slots'][$old_author_id]);
                    } else {
                        $old_author_guid_info = $slots_info['slots'][$old_author_id] ?? [];
                        if (empty($old_author_guid_info['guid'])) {
                            $author_guid_info = UserRedis::getUserInfo($old_author_id, ['username', 'guid', 'avatar', 'avatar_small', 'id']);
                            $old_author_guid_info = self::getFbFormatUserInfo($channel_id, $author_guid_info, FbLivecast::ROLE['moderator']);
                        } else {
                            $old_author_guid_info['role'] = FbLivecast::ROLE['moderator'];
                            $old_author_guid_info['sk'] = FbLivecast::ROLE['moderator'] . time();
                        }
                        $slots_info['slots'][$old_author_id] = $old_author_guid_info;
                    }

                    $slots_info['admin'][$old_author_id] = $old_author_id;
                    $moderator_user_info = !empty($moderator_user_info) ? $moderator_user_info : UserRedis::getUserInfo($moderator_guid, ['username', 'guid', 'avatar', 'avatar_small', 'id']);
                    $new_live_channel_title = $channel_model->title;
                    if (!empty($moderator_user_info['username'])) {
                        //移交房主权限后改房间标题
                        $new_live_channel_title = $moderator_user_info['username'] . '\'s Room';
                        $channel_model->title = $new_live_channel_title;
                    }
                    $old_live_info = $slots_info['info'] ?? [];
                    $old_live_info['host_id'] = $moderator_guid;
                    $old_live_info['title'] = $new_live_channel_title;
                    $slots_info['info'] = $old_live_info;

                    $re = $firebase->update($defalut_path, $slots_info);
                    if ($re == 'null') {
                        throw new \Exception('Data handover update failed.');
                    }
                    if ($is_leave) {
                        self::leaveOtherOprate($channel_id, $old_author_id, FbLivecast::ROLE['moderator'], $old_author_slot_st);
                    }
                    $apply_num = (int)BroadcastRedis::getApplyCohostNum($channel_id);
                    Agora::JarPushSinglePointMessage($old_author_id, $channel_id, Agora::LIVE_ROLE_CHANGE, ['my_role' => FbLivecast::ROLE['moderator'], 'channel_id' => $channel_id, 'st' => (int)$old_author_slot_st, 'apply_num' => $apply_num]);
                    Agora::JarPushSinglePointMessage($moderator_guid, $channel_id, Agora::LIVE_ROLE_CHANGE, ['my_role' => FbLivecast::ROLE['host'], 'channel_id' => $channel_id, 'st' => (int)$moderator_guid_slot_st, 'apply_num' => $apply_num]);
                    if (!$channel_model->save()) {
                        throw new \Exception('Model save fail.');
                    }
                    //刷新redis
                    Service::reloadBroadcast($channel_id);
                    //房间标题变动发送个rtmp消息
                    Agora::JarPushMessage($channel_id, Agora::AUDIO_CHANGE_SETTING, ['type' => 'title', 'content' => $new_live_channel_title]);
                    //发送新主播信息
                    $user_info = [];
                    $user_info = [
                        'avatar' => $moderator_user_info['avatar'] ?? '',
                        'guid' => $moderator_user_info["guid"] ?? '',
                        "username" => $moderator_user_info["username"] ?? '',
                        'id' => (int)$moderator_user_info["id"],
                    ];
                    $user_info['avatar_small'] = Service::avatar_small($user_info['avatar']);
                    Agora::JarPushMessage($channel_id, Agora::LIVE_HOST_INFO, ['user_info' => $user_info]);
                    $admin_info = $slots_info['admin'] ?? [];
                    self::reloadLiveCastManager($channel_id, $admin_info);
                }
                $response["error"] = false;
                $response["message"] = "success";
                return $response;
            } else {
                throw new \Exception("Missing live parameters.");
            }
        } catch (\Exception $e) {
            $response["message"] = $e->getMessage();
            return $response;
        }

    }

    /**
     * @param string $user_id 角色变更用户id
     * @param string $notice_msg 提示
     * @param string $channel_id 直播id
     * @param string $moderators_user_guid 操作的管理员id
     */
    public static function changeRoleSendRtmp($user_id = '', $notice_msg = '', $channel_id = '', $moderators_user_guid = '')
    {
        $rtmp_data = [
            'to' => $user_id,
            'content' => $notice_msg,
            "channel_id" => $channel_id,
            //"op_id"=>0,
            "moderators_op" => '',
            "type" => 99,
            "send_from" => $moderators_user_guid,
        ];
        Agora::JarPushSinglePointMessage($user_id, '', Agora::ModeratorOperate, $rtmp_data);
    }

    /**
     * 移除管理员
     * @param $channel_id
     * @param $user_id
     * @return bool|mixed
     */
    public static function RemoveModerator($channel_id, $user_id)
    {
        try {
            $defalut_path = FbLivecast::getOneRoomPath($channel_id);
            $admin_path = $defalut_path . '/admin/' . $user_id;
            return self::_getInstance()->delete($admin_path);
        } catch (\Exception $exception) {
            return false;
        }

    }

    public static function GetSlotsUser($channel_id, $user_id)
    {
        $defalut_path = FbLivecast::getOneRoomPath($channel_id);
        $slotsUserPath = $defalut_path . '/slots/' . $user_id;
        $res = self::_getInstance()->get($slotsUserPath);
        return json_decode($res, true);
    }

    /**
     * 获取频道内开麦用户,不包含主播
     * @param $channel_id
     * @return array
     */
    public static function GetSlotsOpenMicUser($channel_id)
    {
        $defalut_path = FbLivecast::getOneRoomPath($channel_id);
        $slotsUserPath = $defalut_path . '/slots/';
        $res = self::_getInstance()->get($slotsUserPath);
        $data = json_decode($res, true);
        if (empty($data)) {
            return [];
        }
        $openMicUser = [];
        foreach ($data as $datum) {
            if (isset($datum["guid"]) && isset($datum["sk"]) && $datum["sk"] == FbLivecast::USER_STATUS["open_voice"]) {
                $openMicUser[] = $datum["guid"];
            }
        }
        return $openMicUser;
    }


    /**
     * 获取在线的featured
     * @param $channel_id
     * @return array
     */
    public static function CheckOnlineFeaturedUser($channel_id)
    {
        $defalut_path = FbLivecast::getOneRoomPath($channel_id);
        $slotsUserPath = $defalut_path . '/featured/';
        $res = self::_getInstance()->get($slotsUserPath);
        $data = json_decode($res, true);
        if (empty($data)) {
            return [];
        }
        $featuredUser = [];
        foreach ($data as $datum) {
            if (isset($datum["guid"]) && isset($datum["sk"]) && $datum["sk"] == FbLivecast::USER_STATUS["open_voice"]) {
                $featuredUser[] = $datum["guid"];
            }
        }
        return $featuredUser;
    }

    /**
     * 删除featured在firebase数据并把主播踢出slots
     * @param $channel_id
     * @param $user_id
     * @param $host_id
     * @return bool|mixed
     */
    public static function FeaturedLeaveRoom($channel_id, $user_id, $host_id)
    {
        try {
            $defalut_path = FbLivecast::getOneRoomPath($channel_id);
            $featuredUserPath = $defalut_path . '/featured/' . $user_id;
            $hostSlotsPath = $defalut_path . '/slots/' . $host_id;
            if (!self::_getInstance()->delete($featuredUserPath)) {
                return false;
            }
            if (!self::_getInstance()->delete($hostSlotsPath)) {
                return false;
            }
            return true;
        } catch (\Exception $exception) {
            return false;
        }

    }
}