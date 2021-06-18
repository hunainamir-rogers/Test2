<?php


namespace app\controllers;


use app\commands\LocalController;
use app\components\Agora;
use app\components\BaseController;
use app\components\define\ResponseMessage;
use app\components\firebase\FbLivecast;
use app\components\LiveLogic;
use app\components\redis\BroadcastAudioRedis;
use app\components\redis\BroadcastRedis;
use app\components\redis\LivecastRedis;
use app\components\redis\ModeratorsRedis;
use app\components\redis\UserRedis;
use app\components\ResponseTool;
use app\components\Rongyun;
use app\components\service\CloseRelationUser;
use app\components\service\User as Serviceuser;
use app\components\Util;
use app\models\Channel;
use app\models\ChannelCloudRecording;
use app\models\ChannelModerators;
use app\models\FollowBroadcast;
use app\models\Service;
use app\models\User;
use Firebase\FirebaseLib;
use Yii;
use yii\base\Exception;

class LivestreamController extends BaseController
{
    public function actionCreate()
    {
        $check = Service::authorization();
        if (empty($check)) {
            return $this->error("Missing a required parameter.");
        }
        $postdata = $this->parameter;
        $room_title = isset($postdata['room_title']) ? ($postdata['room_title']) : '';
        $room_type = isset($postdata['room_type']) ? intval($postdata['room_type']) : 2;
        $record_room = isset($postdata['record_room']) ? intval($postdata['record_room']) : 0;
        $email = isset($postdata['email']) ? htmlspecialchars($postdata['email']) : '';
        $event_id = isset($postdata['event_id']) ? htmlspecialchars($postdata['event_id']) : '';//event 事件创建的直播
        $scheduleTime = isset($postdata['time']) ? intval($postdata['time']) : 0; //预约时间
        $schedule_cohost = isset($postdata['schedule_cohost']) ? $postdata['schedule_cohost'] : []; //预约时新增cohost列表
        $tag = isset($postdata['tag']) ? htmlspecialchars($postdata['tag']) : ""; //选取的tag
        $channel_id = isset($postdata['channel_id']) ? $postdata['channel_id'] : ""; //选取的tag
        $description = isset($postdata['description']) ? ($postdata['description']) : ""; //详情
        $featured_user = isset($postdata['feature_cohost']) ? $postdata['feature_cohost'] : []; //featured user
        if ($room_type < 0 || !in_array($room_type, FbLivecast::ROOM_TYPE)) {
            return $this->error("Wrong room property selection.");
        }
        if (!empty($room_title) && mb_strlen($room_title, 'utf-8') > 90) {
            return $this->error("Title up to 90 bytes.");
        }
        if(!is_array($schedule_cohost)){
            return $this->error("Please check the cohost.");
        }
        if(!is_array($featured_user)){
            return $this->error("Please check the featured cohost.");
        }
        if(count($featured_user) > 1){
            return $this->error("Featured cohost only choose one.");
        }
        if ($record_room != 0) {
            if (empty($email) || !preg_match('/^\w+([-+.]\w+)*@\w+([-.]\w+)*\.\w+([-.]\w+)*$/', $email)) {
                return $this->error("Please upload email with correct format.");
            }
        }
        if (LivecastRedis::getOpenVoiceNumber($channel_id) > 10) {
            return $this->error("There can be no more than 10 cohosts.");
        }
        $user_id = $check['user_id'];
        $token = $check['token'];
        $this->checkuser($user_id, $token);

        $db_url = Yii::$app->params["firebase"]["db_url"] ?? '';
        $secretKey = Yii::$app->params["firebase"]["secretKey"] ?? '';
        if (empty($db_url) || empty($secretKey)) {
            return $this->error("Missing critical configuration, please contact the administrator.");
        }
        //不够创建语音直播间
        $user_info = UserRedis::getUserInfo($user_id);
        if (empty($user_info['guid'])) {
            return $this->error('create fail.');
        }

        //只有管理员用户可以开启直播
        if (empty($user_info["type"])) {
            return $this->error("You don't have access to it.");
        }
        //查看这个用户有没有这类直播间
        if (!empty($channel_id)) {//编辑
            $channel = Channel::find()->where(["guid" => $channel_id, "user_id" => $user_id])->one();
            if (empty($channel)) {
                return $this->error("Edit fail, not found channel");
            }
            if ($channel->type != Channel::type_status_upcoming) {
                return $this->error("This channel cannot be edited");
            }
        } else {
            $channel = Channel::find()->where(['user_id' => $user_id, 'live_type' => Channel::liveCastLive, 'type' => Channel::type_status_living])->limit(1)->orderBy('id asc')->one();
            if (!empty($channel) && !empty($scheduleTime)) {
                return $this->error("Please make an schedule live after the live end");
            }
        }
        $is_self_new_open = true;
        if (empty($channel)) {
        } else {
            $is_self_new_open = false;
        }
        $slot_number = 30;
        $room_title = empty($room_title) ? $user_info['username'] . '\'s Room' : $room_title;

        if ($channel) {
            $old_type_status = $channel->type;
        } else {
            $create_uid = $user_id;//2019067393  robot
            //是不是能重复创建新语聊房
            $cover_image = "";
            $guid = Service::create_guid();
            $channel = new Channel();
            $channel->guid = $guid;
            $channel->user_id = $create_uid;
            $channel->cover_image = Service::getCompleteUrl($cover_image);
            $channel->quick_type = 0;
            $channel->post_status = isset($user_info['broadcast_notification']) ? $user_info['broadcast_notification'] : 1;
            $channel->created_ip = Util::get_ip();
            $channel->landscape = 0;
            $channel->bg = '';
            $channel->live_type = Channel::liveCastLive;
            $orderby = -1 * (Service::microtime_float());
            $channel->orderby = $orderby;
            $channel->language = Channel::CHANNEL_DEFAULT['language'];
            $channel->country = Channel::CHANNEL_DEFAULT['country'];
            $old_type_status = $channel->type;
            $channel->live_start_time = date('Y-m-d H:i:s');
            $channel->description = 'welcome this room!';
        }
        $channel->title = $room_title;
        $channel->tags = $tag;
        $channel->post_status = $room_type;//房间私有，公有属性
        $channel->type = Channel::type_status_living;
        $channel->is_live = 'yes';
        if (!empty($scheduleTime)) {
            $channel->is_live = 'no';
            $channel->type = Channel::type_status_upcoming;
            $channel->scheduled_time = date("Y-m-d H:i:s", $scheduleTime);
        }
        $channel->video = $slot_number;
        $hand_mode = FbLivecast::HAND_MODE['anyone'];
        $channel->cohost_any = $hand_mode;//设置的上麦模式，默认任何人
        $channel->send_email = $email; ////记录是否要录音并发送email
        $channel->user_id = $user_id;//记录房主
        if (!empty($description)) {
            $channel->description = $description;//记录房主
        }
        $extra = [];
        $extra['event_id'] = $event_id;
        $extra['create_uid'] = $user_id;
        $extra['record_room'] = $record_room;
        $extra['schedule_cohost'] = $schedule_cohost;
        $extra['feature_cohost'] = $featured_user;
        if ($is_self_new_open) {
            $channel->live_current_audience = 0;//房间人数至为0
        }
        $channel->extra = json_encode($extra);

        if (!$channel->save()) {
            return $this->error("Data save fail");
        }
        $guid = $channel->guid;
        //将直播信息写进redis
        $broadcastInfo = Service::reloadBroadcast($guid, 'create');

        //预约直播
        if (!empty($scheduleTime)) {
            LiveLogic::SetUpcomingLive($guid, $user_id, $scheduleTime);
            return $this->success([], "Schedule success");
        }
        if ($old_type_status != Channel::type_status_living) {
            //写入firebase
            $firebase = new FirebaseLib($db_url, $secretKey);
            $defalut_path = FbLivecast::getOneRoomPath($guid);
            $response = $firebase->get($defalut_path . '/slots');
            if ($response != 'null') {
                //有就先清除，防止脏数据
                $firebase->delete($defalut_path);
            }
            $arr = [];
            //槽位上的人
            $arr['slots'] = [];
//            $arr['slots'][$user_id] = FbLivecast::getFbFormatUserInfo($user_info, FbLivecast::ROLE['host']);
//            $arr['slots'][$user_id]['st'] = FbLivecast::USER_STATUS['open_voice'];
            //观众
            $arr['viewers'] = [];
            //房间信息
            $arr['info'] = [
                'title' => $room_title,
                'host_id' => $user_id,
                'cohost_any' => $hand_mode,
                'start_at' => time(),//开播时间
            ];
            //管理员
            $arr['admin'] = ChannelModerators::GetAllModerators($user_id, $channel_id);
            $result = $firebase->set($defalut_path, $arr);
            if ($result == 'null') {
                return $this->error("Sorry,data initialization failed, please try again.");
            }
        }
        //add featured user
        LiveLogic::AddFeaturedUserToChannel($guid, $featured_user);
        $data = FbLivecast::Join($user_id, $guid, $broadcastInfo, $user_info);
        if ($record_room != 0) {
            if ($old_type_status != Channel::type_status_living) {
                // 开播开始语音录制逻辑
                ChannelCloudRecording::StartRec($channel->user_id, $channel->guid);
            }
        }
        LiveLogic::RemoveUpcomingLive($guid, $user_id);
        LiveLogic::SetExploreLiveList($guid, $user_id);
        $content = "You are invited to join the live room {$room_title}";
        foreach ($schedule_cohost as $cohost) {
            LiveLogic::SendStartLiveCohostInvite($guid, $cohost, $user_id, $content);
        }
        return $this->success($data);
    }

    /**
     * 开播
     */
    public function actionStart()
    {
        $postdata = $this->parameter;
        $check = Service::authorization();
        if (empty($check)) {
            return $this->error("Missing a required parameter.");
        }
        $user_id = $check['user_id'];
        $token = $check['token'];
        $this->checkuser($user_id, $token);
        $channel_id = isset($postdata['channel_id']) ? trim($postdata['channel_id']) : '';
        if (empty($channel_id)) {
            return $this->error("Channel id required.");
        }
        $model = Channel::find()->where(["guid" => $channel_id])->one();
        if (empty($model)) {
            return $this->error("Not found channel.");
        }
        if ($user_id != $model->user_id) {
            return $this->error("You are not the host.");
        }
        if ($model->type != Channel::type_status_upcoming) {
            return $this->error("Room status is not correct.");
        }
        $title = $model->title;
        $hand_mode = FbLivecast::HAND_MODE['anyone'];
        $extra = json_decode($model->extra, true);
        //更新频道
        $model->type = Channel::type_status_living;
        if (!$model->save()) {
            return $this->error("Save fail");
        }
        LiveLogic::RemoveUpcomingLive($channel_id, $user_id);

        $user_info = Serviceuser::getUserInfo($user_id);
        $db_url = Yii::$app->params["firebase"]["db_url"] ?? '';
        $secretKey = Yii::$app->params["firebase"]["secretKey"] ?? '';
        //写入firebase
        $firebase = new FirebaseLib($db_url, $secretKey);
        $defalut_path = FbLivecast::getOneRoomPath($channel_id);
        $response = $firebase->get($defalut_path . '/slots');
        if ($response != 'null') {
            //有就先清除，防止脏数据
            $firebase->delete($defalut_path);
        }
        $arr = [];
        //槽位上的人
        $arr['slots'] = [];
//        $arr['slots'][$user_id] = FbLivecast::getFbFormatUserInfo($user_info, FbLivecast::ROLE['host']);
//        $arr['slots'][$user_id]['st'] = FbLivecast::USER_STATUS['open_voice'];
        //观众
        $arr['viewers'] = [];
        //房间信息
        $arr['info'] = [
            'title' => $title,
            'host_id' => $user_id,
            'cohost_any' => $hand_mode,
            'start_at' => time(),
        ];
        //管理员
        $arr['admin'] = ChannelModerators::GetAllModerators($user_id, $channel_id);;
        $result = $firebase->set($defalut_path, $arr);
        if ($result == 'null') {
            return $this->error("Sorry,data initialization failed, please try again.");
        }

        $broadcastInfo = Service::reloadBroadcast($channel_id, 'create');
        //add featured user
        if(isset($extra["feature_cohost"]) && !empty($extra["feature_cohost"])){
            LiveLogic::AddFeaturedUserToChannel($channel_id, $extra["feature_cohost"]);
        }

        $data = FbLivecast::Join($user_id, $channel_id, $broadcastInfo, $user_info);

        if (isset($extra["record_room"]) && $extra["record_room"] != 0) {
            // 开播开始语音录制逻辑
            ChannelCloudRecording::StartRec($user_id, $channel_id);
        }
        LiveLogic::SetExploreLiveList($channel_id, $user_id);
        //发送开播通知
        LiveLogic::SendStartLiveNotification($channel_id);
        return $this->success($data);
    }

    /**
     * 其他用户进入
     */
    public function actionJoin()
    {
        $postdata = $this->parameter;
        $check = Service::authorization();
        if (empty($check)) {
            return $this->error("Missing a required parameter.");
        }
        $user_id = $check['user_id'];
        $token = $check['token'];
        $channel_id = isset($postdata['channel_id']) ? trim($postdata['channel_id']) : '';

        if (empty($channel_id)) {
            return $this->error("Channel id required.");
        }

        $this->checkuser($user_id, $token);

        $user_info = Service::userInfo($user_id);
        if (empty($user_info)) {
            return $this->error("User not find.");
        }

        $broadcastInfo = Channel::getbroadcastInfo($channel_id);

        //$channel = Channel::find()->select(['id','user_id','is_live','replay_current_audience','live_current_audience','live_highest_audience','replay_highest_audience','total_viewer','total_joined_times','live_type'])->where(['guid' => $channel_id])->one();
        if (empty($broadcastInfo)) {
            return $this->error("Sorry,the room doesn't exist,please contact customer service.");
        }

        if (isset($broadcastInfo['type']) && $broadcastInfo['type'] == Channel::type_status_end) {
            return $this->error("Livestream has ended.");
        }
        if (empty($broadcastInfo['user_id'])) {
            return $this->error("Abnormal live broadcast information.");
        }

        //主播开播
        if (isset($broadcastInfo['type']) && $broadcastInfo['type'] == Channel::type_status_upcoming && $user_id == $broadcastInfo['user_id']) {
            return $this->actionStart();
        }
        //判断是否在主播的黑名单中
        $is_in_block = UserRedis::isInblockList($broadcastInfo['user_id'], $user_id);
        if ($is_in_block !== false) {
            return $this->error('Sorry,you\'ve been blocked by the host.');
        }
        //检查用户是否才被踢出去
        if (ModeratorsRedis::GetKick($channel_id, $user_id)) {
            return $this->error('You have been kicked out from the room. unable to reenter for 5 mins.');
        }
        //开始start
        $channel_id = $broadcastInfo['channel_id'];

        $data = FbLivecast::Join($user_id, $channel_id, $broadcastInfo, $user_info);
//        $data = LiveLogic::Join($user_id, $channel_id, $broadcastInfo, $user_info);
        if (empty($data)) {
            return $this->error(ResponseTool::$message, ResponseTool::$code);
        }
        return $this->success($data);

    }

    /**
     * 上槽
     */
    public function actionJoinSlot()
    {
        $check = Service::authorization();
        if (empty($check)) {
            return $this->error("Missing a required parameter.");
        }
        $postdata = file_get_contents("php://input");
        $postdata = json_decode($postdata, true);
        if (empty($postdata)) {
            return $this->error("Post Data is empty.");
        }
        $user_id = $check['user_id'];

        $token = $check['token'];
        $channel_id = isset($postdata['channel_id']) ? htmlspecialchars($postdata['channel_id']) : '';
        if (empty($channel_id)) {
            return $this->error("Error the live id.");
        }
        $this->checkuser($user_id, $token);
        $this->CheckRequestSign($postdata);
        $info = Channel::getbroadcastInfo($channel_id);
        if (empty($info) || !isset($info['user_id'])) {
            return $this->error("Resource doesn't exist.");
        }

        //这里使用肯定就是speaker
        //$my_role = FbLivecast::checkUserRole($user_id,$channel_id,$info);
        $result = FbLivecast::JoinAudioCost($channel_id, $user_id);
        if ($result["error"]) {
            return $this->error($result["message"], $result["code"]);
        }
        return $this->success([]);
    }

    /**
     * 槽位静音与否
     */
    public function actionAudioHostMute()
    {
        $postdata = file_get_contents("php://input");
        Yii::info('livecast/audio-host-mute------' . $postdata, 'interface');
        $postdata = json_decode($postdata, true);
        $channel_id = isset($postdata['channel_id']) ? trim($postdata['channel_id']) : "";
        $mute_status = isset($postdata['mute_status']) ? (int)$postdata['mute_status'] : BroadcastAudioRedis::mute_by_self;
        $check = Service::authorization();
        if (empty($check)) {
            return $this->error("Missing a required parameter.");
        }
        if (empty($channel_id)) {
            return $this->error("Missing channel id parameter.");
        }
        $user_id = $check["user_id"];
        $mute_user_id = $user_id;
        if (!in_array($mute_status, [0, 1])) {
            return $this->error("Bad status request status code.");
        }
        $this->checkuser($user_id, $check["token"]);
        $braodcastinfo = Channel::getbroadcastInfo($channel_id, ['channel_id', 'manager', 'user_id']);
        if (empty($braodcastinfo['channel_id'])) {
            return $this->error("Live information loss.");
        }
//        if($mute_status == BroadcastAudioRedis::unmute_by_self && ChannelModerators::IsMute($channel_id, $user_id)){
//            return $this->error("You are muted by the moderator.");
//        }
        $data = [];
        $data['st'] = $mute_status == BroadcastAudioRedis::unmute_by_self ? FbLivecast::USER_STATUS['open_voice'] : FbLivecast::USER_STATUS['close_voice'];
        $result = FbLivecast::muteOprate($channel_id, $user_id, $data['st']);
        if ($result['error']) {
            return $this->error($result['message']);
        }
        return $this->success($data);
    }

    /**
     * 客户端举手调接口
     */
    public function actionApplyCohost()
    {
        $postdata = file_get_contents("php://input");
        Yii::info('livecast/apply-cohost------' . $postdata, 'interface');
        $postdata = json_decode($postdata, true);
        $channel_id = isset($postdata["channel_id"]) ? trim($postdata["channel_id"]) : "";
        $type = isset($postdata["type"]) ? trim($postdata["type"]) : 1; //1:申请加入;2主播同意；3主播拒绝；
        $op_user = isset($postdata['op_user']) ? trim($postdata['op_user']) : "";

        if (!in_array($type, [1, 2, 3])) {
            return $this->error("type is incorrect.");
        }
        $check = Service::authorization();
        if (empty($check)) {
            return $this->error("Missing a required parameter.");
        }
        $user_id = $check["user_id"];
        $this->checkuser($check['user_id'], $check['token']);

        $broadcastInfo = Channel::getbroadcastInfo($channel_id, ['id', 'channel_id', 'user_id', 'manager', 'live_type', 'post_status', 'cohost_any']);
        if (empty($broadcastInfo)) {
            return $this->error("not found livestreams");
        }
        $host_id = $broadcastInfo["user_id"];

        //查看是否有管理权限
        $role = FbLivecast::checkUserRole($check['user_id'], $channel_id, $broadcastInfo);
        if (in_array($type, [2, 3]) && !in_array($role, [FbLivecast::ROLE['host'], FbLivecast::ROLE['moderator']])) {
            return $this->error("You're not the moderators.");
        }
        //管理员跟主播id，发rtm消息
        $manager_total = [$broadcastInfo['user_id']];
        if (!empty($broadcastInfo['manager'])) {
            $manager_ids = explode(',', $broadcastInfo['manager']);
            $manager_total = array_merge($manager_total, $manager_ids);
        }
        $data = [];
        $db_url = Yii::$app->params["firebase"]["db_url"] ?? '';
        $secretKey = Yii::$app->params["firebase"]["secretKey"] ?? '';
        $defalut_path = FbLivecast::getOneRoomPath($channel_id);
        $firebase = new FirebaseLib($db_url, $secretKey);
        if ($type == 1) {
            $is_need_apply = 1;
            if (in_array($role, [FbLivecast::ROLE['host'], FbLivecast::ROLE['moderator']])) {//如果是主播或者管理员申请，直接上麦
                return $this->error("Moderator can't raise their hands.");
            } else {
                //根据slot_model看这个人需不需要申请 1所有人举手申请上 0只有moderator关注着可以举手
                $slot_model = isset($broadcastInfo['cohost_any']) ? intval($broadcastInfo['cohost_any']) : FbLivecast::HAND_MODE['anyone'];
                switch ($slot_model) {
                    case 1:
                        $is_need_apply = 1;
                        break;
                    case 0:
                        $is_moderator_follow = false;
                        foreach ($manager_total as $one) {
                            $is_follwer = \app\components\service\User::isInFollowing($one, $check['user_id']);
                            if ($is_follwer) {
                                $is_moderator_follow = true;
                                break;
                            }
                        }
                        if ($is_moderator_follow) {
                            $is_need_apply = 1;
                        } else {
                            return $this->error('Sorry, only people moderator\'s follow can raise their hand at the moment.');
                        }
                        break;
                    default:
                        return $this->error('Sorry,no such hand up mode.');
                        break;
                }
            }
            if ($is_need_apply) { //举手环节
                $path = $defalut_path . '/viewers/' . $user_id;
                $response = $firebase->get($path . '/sk');
                if ($response == 'null') {
                    return $this->error("Not find user in viewer list.");
                }
                $response = $firebase->update($path, ['st' => FbLivecast::USER_STATUS['hand']]);
                if ($response == 'null') {
                    return $this->error("Hand failed.");
                }
                BroadcastRedis::setApplyCohostList($channel_id, $user_id);
                $apply_num = BroadcastRedis::getApplyCohostNum($channel_id);
                $apply_list = $this->getCohostApplyList($channel_id);
                //往主播，管理发送最新请求连麦人数
                foreach ($manager_total as $v) {
                    Agora::JarPushSinglePointMessage($v, $channel_id, Agora::CohostApply, ['type' => 1, 'apply_list' => $apply_list, 'apply_num' => intval($apply_num), 'apply_uid' => $user_id]);
                }
            }
        } else if ($type == 2) {//同意连麦,踢出申请列表，返回新的申请列表，连麦列表，给用户发同意的rtm
            $res = BroadcastRedis::checkApplyCohostUser($channel_id, $op_user);
            if (!empty($res)) {
                //让用户上麦
                Agora::JarPushSinglePointMessage($op_user, $channel_id, Agora::CohostApplyPass, []);
                BroadcastRedis::remApplyCohostList($channel_id, $op_user);

                //让用户更新状态
                Agora::JarPushSinglePointMessage($op_user, $channel_id, Agora::CohostApply, ['type' => 2, 'msg' => "You've been drafted!"]);
            }
            $apply_num = BroadcastRedis::getApplyCohostNum($channel_id);
            $apply_list = $this->getCohostApplyList($channel_id);
            //跟主播管理更新数据
            foreach ($manager_total as $v) {
                Agora::JarPushSinglePointMessage($v, $channel_id, Agora::CohostApply, ['type' => 1, 'apply_list' => $apply_list, 'apply_num' => intval($apply_num), 'apply_uid' => $op_user]);
            }
        } else if ($type == 3) {//拒绝连麦,踢出申请列表，返回新的申请列表，连麦列表，给用户发拒绝的rtm
            BroadcastRedis::remApplyCohostList($channel_id, $op_user);
            $apply_num = BroadcastRedis::getApplyCohostNum($channel_id);
            $apply_list = $this->getCohostApplyList($channel_id);
            //跟主播管理更新数据
            foreach ($manager_total as $v) {
                Agora::JarPushSinglePointMessage($v, $channel_id, Agora::CohostApply, ['type' => 1, 'apply_list' => $apply_list, 'apply_num' => intval($apply_num), 'apply_uid' => $op_user]);
            }
            //让用户更新状态
            Agora::JarPushSinglePointMessage($op_user, $channel_id, Agora::CohostApply, ['type' => 3, 'msg' => 'The host or moderator rejected your application']);
        }


        return $this->success($data);
    }

    /**
     * 管理员进房间请求申请列表
     */
    public function actionApplyCohostList()
    {
        $postdata = file_get_contents("php://input");
        Yii::info('livecast/apply-cohost-list------' . $postdata, 'interface');
        $postdata = json_decode($postdata, true);
        $channel_id = isset($postdata["channel_id"]) ? trim($postdata["channel_id"]) : "";
        $check = Service::authorization();
        if (empty($check)) {
            return $this->error("Missing a required parameter.");
        }
        $this->checkuser($check['user_id'], $check['token']);

        $apply_num = BroadcastRedis::getApplyCohostNum($channel_id);
        $apply_list = $this->getCohostApplyList($channel_id);
        return $this->success(['apply_list' => $apply_list, 'apply_num' => intval($apply_num)]);
    }


    public function getCohostApplyList($channel_id)
    {
        if (empty($channel_id)) {
            return false;
        }
        $apply_list = BroadcastRedis::getApplyCohostList($channel_id);
        $data = [];
        if ($apply_list) {
            foreach ($apply_list as $k => $v) {
                $userinfo = UserRedis::getUserInfo($v, ['id', 'username', 'avatar', 'guid', 'gender', 'level']);
                if (empty($userinfo['id'])) continue;
                $userinfo = FbLivecast::getFbFormatUserInfo($channel_id, $userinfo);
                $userinfo['st'] = FbLivecast::USER_STATUS['hand'];
                $data[] = $userinfo;
            }
        }
        return $data;
    }


    public function actionList()
    {
        $postdata = $this->parameter;
        $check = Service::authorization();
        if (empty($check)) {
            return $this->error("Missing a required parameter.");
        }
        $since_id = isset($postdata['since_id']) ? intval($postdata['since_id']) : 0;
        $page_size = isset($postdata['page_size']) ? trim($postdata['page_size']) : CloseRelationUser::PAGE_SIZE;
        $mode = isset($postdata['mode']) ? trim($postdata['mode']) : LiveLogic::LiveModelLiving;
        $user_id = $check['user_id'];
        $token = $check['token'];
        $this->checkuser($user_id, $token);
        $data = CloseRelationUser::getLivecastList($mode, $user_id, '', $since_id, $page_size);
        return $this->success($data, 'Success');
    }

    public function actionListWithGroup()
    {
        $postdata = file_get_contents("php://input");
        Yii::info('livecast/ListWithGroup------' . $postdata, 'interface');
        $postdata = json_decode($postdata, true);
        $check = Service::authorization();
        if (empty($check)) {
            return $this->error("Missing a required parameter.");
        }
        $since_id = isset($postdata['since_id']) ? intval($postdata['since_id']) : 0;
        $page_size = isset($postdata['page_size']) ? trim($postdata['page_size']) : CloseRelationUser::PAGE_SIZE;
        $user_id = $check['user_id'];
        $token = $check['token'];
        $this->checkuser($user_id, $token);
        $data = CloseRelationUser::getLivecastList3($user_id, '', $since_id, $page_size);
        return $this->success($data, 'Success', null, false);
    }

    /**
     * 房间修改
     */
    public function actionSetting()
    {
        $postdata = file_get_contents("php://input");
        Yii::info('livecast/setting------' . $postdata, 'interface');
        $postdata = !empty($postdata) && is_string($postdata) ? json_decode($postdata, true) : [];
        $check = Service::authorization();
        if (empty($check)) {
            return $this->error("Missing a required parameter.");
        }
        $channel_id = $postdata['channel_id'] ?? '';
        if (empty($channel_id)) {
            return $this->error("Live ID cannot be empty.");
        }
        $channel_info = Channel::getbroadcastInfo($channel_id, ['channel_id', 'title', 'description', 'manager', 'user_id', 'cohost_any']);
        if (empty($channel_info['channel_id'])) {
            return $this->error("This live does not exist.v1");
        }
        $user_id_role = FbLivecast::checkUserRole($check['user_id'], $channel_id, $channel_info);
        if (!in_array($user_id_role, [FbLivecast::ROLE['host'], FbLivecast::ROLE['moderator']])) {
            return $this->error("You are not the anchor or moderator, you have no right to operate.");
        }
        $field = isset($postdata['field']) ? trim($postdata['field']) : '';
        $content = isset($postdata['content']) ? trim($postdata['content']) : '';
        //manage有另外的修改接口，其他的字段修改都共用此接口,cohost_any上槽位的模式，默认1所有人随便上 0 关注管理员的人
        if (!in_array($field, ['title', 'description', 'cohost_any'])) {
            return $this->error("Parameter error.");
        }
        if ($field == 'cohost_any') {
            if (!in_array($content, FbLivecast::HAND_MODE)) {
                return $this->error("Wrong mode parameter value.");
            }
            $content = intval($content);
        }
        if ($field == 'description' && mb_strlen($content, 'utf-8') > 150) {
            return $this->error("This text only supports 150 bytes.");
        }
        $transaction = \Yii::$app->db->beginTransaction();
        $model = Channel::findOne(['guid' => $channel_id]);
        if (!$model) {
            return $this->error("This live does not exist.v2");
        }
        try {
            if (in_array($field, ['title', 'description', 'cohost_any'])) {//这里是直接修改数据库字段的数据
                $model->$field = $content;
                if (!$model->save()) {
                    throw new Exception("Finish error.v1");
                }
                $up_data = [];
                $up_data[$field] = $content;
                if (!BroadcastRedis::broadcastInfo($channel_id, $up_data)) {
                    throw new Exception("Finish error.v2");
                }
            }
            $transaction->commit();
        } catch (\Exception $e) {
            $transaction->rollBack();
            return $this->error($e->getMessage(), 10000);
        }
        Agora::JarPushMessage($channel_id, Agora::AUDIO_CHANGE_SETTING, ['type' => $field, 'content' => (string)$content]);
        return $this->success(['type' => $field, 'content' => $content], 'success');
    }


    /**
     * 观众 退出连麦
     */
    public function actionLeaveAudioCohost()
    {
        $postdata = file_get_contents("php://input");
        Yii::info('livecast/leave-audio-cohost------' . $postdata, 'interface');
        $postdata = json_decode($postdata, true);
        $channel_id = isset($postdata['channel_id']) ? trim($postdata['channel_id']) : "";
        $leaveRoom = isset($postdata['leaveRoom']) ? intval($postdata['leaveRoom']) : 0; //麦上用户离开直播间不需要在回到观众席
        $check = Service::authorization();
        if (empty($check)) {
            return $this->error("Missing a required parameter.");
        }
        $user_id = $check["user_id"];
        $this->checkuser($user_id, $check["token"]);
        $result = FbLivecast::LeaveAudioCost($user_id, $channel_id, "", "", false, $leaveRoom);
        if ($result['error']) {
            return $this->error($result['message'], $result['code']);
        }
        return $this->success(['user_id' => $user_id, 'channel_id' => $channel_id]);
    }

    /**
     * 管理员 让观众退出槽位
     * 主播清空一个槽, 主播block,kick之后调用的接口
     */
    public function actionHostFlushSlot()
    {
        $postdata = file_get_contents("php://input");
        Yii::info('livecast/host-flush-slot------' . $postdata, 'interface');
        $postdata = json_decode($postdata, true);
        $channel_id = isset($postdata['channel_id']) ? trim($postdata['channel_id']) : "";
        $uid = isset($postdata['uid']) ? $postdata['uid'] : "";
        if (empty($channel_id)) {
            return $this->error('Missing room number.');
        }
        if (empty($uid)) {
            return $this->error('Missing room number.');
        }
        $check = Service::authorization();
        $user_id = $check["user_id"];
        $this->checkuser($user_id, $check["token"]);
        $broadcastinfo = Channel::getbroadcastInfo($channel_id, ['user_id', 'manager']);
        if (empty($broadcastinfo['user_id'])) {
            return $this->error('The room doesn\'t exist.');
        }
        $user_id_rold_id = FbLivecast::checkUserRole($user_id, $channel_id, $broadcastinfo);
        $slot_user_role_id = '';
        if (in_array($user_id_rold_id, [FbLivecast::ROLE['host'], FbLivecast::ROLE['moderator']])) {
            //获取这个用户的权限
            $slot_user_role_id = FbLivecast::checkUserRole($uid, $channel_id, $broadcastinfo);
            //管理员和主播操作进行操作对象权限判断,是主播就不进行下面的判断
            if ($user_id_rold_id != FbLivecast::ROLE['host']) {

//                if($user_id_rold_id == $slot_user_role_id){
//                    return $this -> error("You can't leave the other moderator.");
//                }
                if ($slot_user_role_id == FbLivecast::ROLE['host']) {
                    return $this->error("You can't leave the host.");
                }
            }
        } else {
            return $this->error("You are not the host or moderator, you have no right to operate.");
        }

        $userModel = UserRedis::UserExists($uid);
        if (empty($userModel)) {
            return $this->error("Not found user");
        }
        $result = FbLivecast::LeaveAudioCost($uid, $channel_id, $slot_user_role_id, $user_id_rold_id);
        if ($result['error']) {
            return $this->error($result['message'], $result['code']);
        }

        $moderators_username = UserRedis::getUserInfo($user_id, 'username');
        $moderators_username = empty($moderators_username) ? 'moderator' : $moderators_username;
        $msg = 'You have been moved to audience by ' . $moderators_username;
        FbLivecast::changeRoleSendRtmp($uid, $msg, $channel_id, $user_id);

        return $this->success(['user_id' => $uid]);
    }

    /**
     * 结束livecast
     */
    public function actionEndRoom()
    {
        $postdata = file_get_contents("php://input");
        Yii::info('livecast/end-room------' . $postdata, 'interface');
        $postdata = json_decode($postdata, true);

        $check = Service::authorization();
        if (empty($check)) {
            return $this->error("Missing a required parameter.");
        }

        $user_id = $check['user_id'];

        $token = $check['token'];
        $channel_id = isset($postdata['channel_id']) ? trim($postdata['channel_id']) : '';
        $this->checkuser($user_id, $token);

        if (empty($channel_id)) {
            return $this->error("Channel id required.");
        }

        $info = Channel::getbroadcastInfo($channel_id);
        if (empty($info) || !isset($info['user_id'])) {
            return $this->error("Resource doesn't exist.");
        }

        $host_type = Service::userInfo($info['user_id']);
        if (empty($host_type)) {
            return $this->error("anchor info doesn't exist.");
        }

        if (!isset($info["type"]) || empty($info["type"])) {
            return $this->error("Resource status doesn't exist.");
        }

        $info['live_type'] = $info['live_type'] ?? 0;
        //(如果是主播离开 &&（不是GameMatch || 是liveAudioStream直播且非推荐）)关闭 || 1v1匹配有人退出就关播
        if ($user_id == $info['user_id']) {
            //已经结束的直播
            if ($info["type"] == 2) {
                $channel = Channel::find()->select(['id', 'group_id', 'gifts', 'diamonds', 'is_live', 'user_id', 'live_start_time', 'live_current_audience', 'replay_current_audience', 'live_highest_audience', 'total_viewer', 'live_type'])->where(['guid' => $channel_id])->one();
                $re = [
                    'user_id' => $user_id,
                    'host_type' => intval($host_type['type']),
                    'like_count' => isset($info['like_count']) ? intval($info['like_count']) : 0,
                    'audience_count' => intval($channel->total_viewer),
                    'duration' => intval($channel->duration),
                    'channel_id' => $channel_id,
                    'gifts' => isset($info['gifts']) ? intval($info['gifts']) : 0,
                    'diamonds' => isset($info['diamonds']) ? intval($info['diamonds']) : 0,
                    'is_group' => false,
                    'status' => 'Leave success.'];
                if (isset($host_type["type"]) && $host_type["type"] == '1' && isset($host_type["is_suggest"]) && $host_type["is_suggest"] = '1') {
                    $re["audience_count"] = (int)$channel->total_joined_times;
                }
                //发送让其他人离开直播间的频道消息
                Agora::JarPushMessage($channel_id, Agora::JEEPNEY_CLOSE, ['channel_id' => $channel_id, 'need_msg' => true, 'content' => 'The room owner just ended this room.']);
                return $this->success($re);
            }
            if ($info['live_type'] == Channel::liveCastLive) {
                $data = FbLivecast::Close($channel_id, $user_id, $info);
            } else {
                return $this->error("This type of live broadcast is not supported by this api.");
            }

            if (!$data) {
                return $this->error("error");
            }
            return $this->success($data);

        } else {
            return $this->error("You are not the anchor, you have no right to operate.");
        }
    }

    /**
     * 获取livecast用户信息
     */
    public function actionUserProfile()
    {
        $postdata = $this->parameter;
        $check = Service::authorization();
        if (empty($check)) {
            return $this->error("Missing a required parameter.");
        }
        $query_id = isset($postdata['query_id']) ? trim($postdata['query_id']) : '';
        $channel_id = isset($postdata['channel_id']) ? trim($postdata['channel_id']) : '';
        $user_id = $check['user_id'];

        if (empty($query_id)) {
            $query_id = $user_id;
        }
        if (!Serviceuser::userIsExists($query_id)) {
            return $this->error("User not found.");
        }
        $userinfo = UserRedis::getUserInfo($query_id, ['avatar', 'bg_image', 'guid', 'username', 'gender', 'type', 'title', 'frame_img', 'level', 'id', 'intro', 'follower', 'following', 'is_idol']);
        if (empty($userinfo)) {
            $userinfo = Service::reloadUser($query_id);
        }
        $return_info = self::formatProfileInfo($userinfo, $query_id, $user_id);
        $return_info['my_role'] = FbLivecast::checkUserRole($query_id, $channel_id);
        $data['user_info'] = $return_info;
        if ($query_id != $user_id) {
            //查找好友推荐，目前最多返回12个
            $his_follow = Serviceuser::getFollowing($query_id, 0, 12);
            //需要过滤掉自己已经关注过的人
            $my_follow = Serviceuser::getFollowing($user_id);
            array_push($my_follow['list'], $user_id);
            $follow_data = array_diff($his_follow['list'], $my_follow['list']);
            //ToDo:后续需要补上过滤掉的数量
            $friends_2nd = [];
            if (!empty($follow_data)) {
                $users_info = UserRedis::getUserInfoBatch($follow_data, ['avatar', 'bg_image', 'guid', 'username', 'gender', 'type', 'title', 'frame_img', 'level', 'id', 'intro', 'follower', 'following']);
                foreach ($users_info as $key => $value) {
                    $friends_2nd[] = self::formatProfileInfo($value, $value['guid'], $user_id);
                }
            }
            $data['friends_2nd'] = $friends_2nd;
        }

        return $this->success($data, 'Success', $check['version_code']);
    }

    static function formatProfileInfo($userinfo = [], $query_id, $user_id)
    {
        if (empty($userinfo)) {
            return [];
        }
        $tmp = [];
        $tmp['guid'] = $userinfo['guid'];
        $tmp['username'] = $userinfo['username'] ?? '';
        $tmp['type'] = isset($userinfo['type']) ? intval($userinfo['type']) : 0;
        $tmp['level'] = isset($userinfo['level']) ? intval($userinfo['level']) : 1;
        $tmp['avatar'] = !empty($userinfo['avatar']) ? Service::getCompleteUrl($userinfo['avatar']) : Service::getCompleteUrl('/default/default_avatar.png');
        $tmp['avatar_small'] = isset($tmp['avatar']) ? Service::avatar_small($tmp['avatar']) : "";
        $tmp['gender'] = isset($userinfo['gender']) ? intval($userinfo['gender']) : 2;
        $tmp['id'] = intval($userinfo['id']);
        $tmp['intro'] = !empty($userinfo['intro']) ? $userinfo['intro'] : "Hi~";
        $tmp['follower_number'] = isset($userinfo['follower']) ? intval($userinfo['follower']) : 0;
        $tmp['following_number'] = isset($userinfo['following']) ? intval($userinfo['following']) : 0;
        $tmp['relation_status'] = Service::UserRelation($query_id, $user_id);
        return $tmp;
    }

    /**
     * 邀请用户到livecast
     */
    public function actionInviteFollowers()
    {
        $postdata = $this->parameter;
        $check = Service::authorization();
        if (empty($check)) {
            return $this->error("Missing a required parameter.");
        }
        $channel_id = isset($postdata['channel_id']) ? trim($postdata['channel_id']) : "";
        $push_user = isset($postdata['push_user']) ? $postdata['push_user'] : [];
        $user_id = $check['user_id'];

        $broadcastInfo = Channel::getbroadcastInfo($channel_id, ['id', 'channel_id', 'user_id', 'title', 'audience_count', 'cover_image', 'live_type', 'type', 'created_at']);
        if (empty($broadcastInfo)) {
            return $this->error("not found livestreams");
        }

        $host_userinfo = UserRedis::getUserInfo($broadcastInfo['user_id'], ['username', 'avatar']);
        $send_userinfo = UserRedis::getUserInfo($user_id, ['username', 'avatar']);

        if (!empty($push_user)) {//发推送,im
            $filters = [];
            foreach ($push_user as $user) {
                $msg_content = [];
                array_push($filters, array("field" => "tag", "key" => "guid", "relation" => "=", "value" => $user), array('operator' => 'OR'));
                //im
                $msg_content['create_time'] = strtotime($broadcastInfo['created_at']) * 1000;
                $msg_content['live_type'] = $broadcastInfo['live_type'];
                $msg_content['chat_id'] = $broadcastInfo['id'];
                $msg_content['image'] = $broadcastInfo["cover_image"];
                $msg_content['feed_id'] = $broadcastInfo['channel_id'];
                $msg_content['type'] = $broadcastInfo['type'];
                $msg_content['title'] = $broadcastInfo['title'];
                $msg_content['audience'][] = [
                    "avatar" => Service::getCompleteUrl($host_userinfo['avatar']),
                    "avatar_small" => Service::avatar_small($host_userinfo['avatar']),
                    "username" => $host_userinfo['username'],
                    "guid" => $user_id,
                ];
                Rongyun::sendInviteMsg($user_id, $user, $msg_content);
            }
            array_pop($filters);
            if (!empty($filters)) {
                $title = $send_userinfo['username'] . " invited you to join the livecat " . $broadcastInfo['title'];
                $push_title = $send_userinfo['username'];
                $host_avatar = Service::getCompleteUrl($send_userinfo['avatar']);
                $odata = array("big_picture" => $broadcastInfo["cover_image"], "large_icon" => $host_avatar, "ios_attachments" => array("large_icon" => $host_avatar, "big_picture" => $broadcastInfo["cover_image"]), "ttl" => 1200);
                Service::OnesignalSendMessage($title, $filters, $push_title, array("channel_id" => $channel_id, 'type' => '400', 'live_type' => '19'), $odata, false);
            }
        }
        return $this->success("Invite success");
    }

    /**
     * 房间初始化获取房间信息
     */
    public function actionIni()
    {
        $check = Service::authorization();
        if (empty($check)) {
            return $this->error("Missing a required parameter.");
        }
        $postdata = file_get_contents("php://input");
        Yii::info('livecast/ini------' . $postdata, 'interface');
        $postdata = json_decode($postdata, true);
        $channel_id = isset($postdata['channel_id']) ? trim($postdata['channel_id']) : "";
        if (empty($channel_id)) {
            return $this->error('Parameter error.');
        }
        $user_id = $check['user_id'];
        $token = $check['token'];
        $this->checkuser($user_id, $token);
        $db_url = Yii::$app->params["firebase"]["db_url"] ?? '';
        $secretKey = Yii::$app->params["firebase"]["secretKey"] ?? '';
        if (empty($db_url) || empty($secretKey)) {
            return $this->error("Missing critical configuration, please contact the administrator.");
        }
        $firebase = new FirebaseLib($db_url, $secretKey);
        $defalut_path = FbLivecast::getOneRoomPath($channel_id);
        $slots_info_json = $firebase->get($defalut_path);
        $slots_info = empty($slots_info_json) ? [] : json_decode($slots_info_json, true);
        $data = [];
        if (!empty($slots_info) && is_array($slots_info)) {
            $data['slots'] = empty($slots_info['slots']) ? [] : $slots_info['slots'];
            $data['viewers'] = empty($slots_info['viewers']) ? [] : $slots_info['viewers'];
        }
        return $this->success($data, 'success', null, false);
    }

    /**
     * 离开直播间
     */
    public function actionLeave()
    {
        $postdata = $this->parameter;
        $check = Service::authorization();
        if (empty($check)) {
            return $this->error("Missing a required parameter.");
        }
        $user_id = $check['user_id'];
        $token = $check['token'];
        $this->checkuser($user_id, $token);

        $channel_id = isset($postdata['channel_id']) ? trim($postdata['channel_id']) : '';
        $to_channel_id = isset($postdata['to_channel_id']) ? trim($postdata['to_channel_id']) : '';
        if (empty($channel_id)) {
            return $this->error("Channel id required.");
        }

        $info = Channel::getbroadcastInfo($channel_id);
        if (empty($info) || !isset($info['user_id'])) {
            return $this->error("Resource doesn't exist.");
        }

        $host_type = Service::userInfo($info['user_id']);
        if (empty($host_type)) {
            return $this->error("anchor info doesn't exist.");
        }

        if (!isset($info["type"]) || empty($info["type"])) {
            return $this->error("Resource status doesn't exist.");
        }
        $user_type = isset($host_type['type']) ? (string)$host_type['type'] : '0';
        $user_tier = isset($host_type['tier']) ? $host_type['tier'] : '';

        $info['live_type'] = $info['live_type'] ?? 0;
        if ($user_id == $info['user_id']) {

            if ($info['live_type'] == Channel::liveAudioStream) {
                //发送让其他人离开直播间的频道消息
                Agora::JarPushMessage($channel_id, Agora::JEEPNEY_CLOSE, ['channel_id' => $channel_id, 'need_msg' => true, 'content' => 'The room owner just ended this room.']);
            }
            //已经结束的直播
            if ($info["type"] == 2) {
                $channel = Channel::find()->select(['id', 'group_id', 'gifts', 'diamonds', 'is_live', 'user_id', 'live_start_time', 'live_current_audience', 'replay_current_audience', 'live_highest_audience', 'total_viewer', 'live_type'])->where(['guid' => $channel_id])->one();
                $re = [
                    'user_id' => $user_id,
                    'host_type' => intval($host_type['type']),
                    'like_count' => isset($info['like_count']) ? intval($info['like_count']) : 0,
                    'audience_count' => intval($channel->total_viewer),
                    'duration' => intval($channel->duration),
                    'channel_id' => $channel_id,
                    'gifts' => isset($info['gifts']) ? intval($info['gifts']) : 0,
                    'diamonds' => isset($info['diamonds']) ? intval($info['diamonds']) : 0,
                    'is_group' => false,
                    'status' => 'Leave success.'];
                if (isset($host_type["type"]) && $host_type["type"] == '1' && isset($host_type["is_suggest"]) && $host_type["is_suggest"] = '1') {
                    $re["audience_count"] = (int)$channel->total_joined_times;
                }
                return $this->success($re);
            }
            $data = Channel::Close($channel_id, $user_id, $info);
            if (!$data) {
                return $this->error("error");
            }
            return $this->success($data);
        }

        if ($info['live_type'] == Channel::liveCastLive) {
            $my_role = FbLivecast::checkUserRole($user_id, $channel_id, $info);
            if ($info['user_id'] == $user_id) {
                //自动移交主播权限或者关播
                //FbLivecast::changeAuthor($channel_id, $user_id, '', true);
            } else {
                FbLivecast::synLeaveDataToFirebase($channel_id, $user_id, $my_role);
            }
        }

        //featured 离开房间
        if(LiveLogic::CheckFeaturedUserInChannel($channel_id, $user_id)){
            //FbLivecast::FeaturedLeaveRoom($channel_id, $user_id, $info['user_id']);
        }
        if (BroadcastRedis::ExistApplyCohost($channel_id, $user_id)) {
            BroadcastRedis::remApplyCohostList($channel_id, $user_id);//移除举手列表
            //往主播，管理发送最新请求连麦人数
            $manager_total = [$info['user_id']];
            if (!empty($info['manager'])) {
                $manager_ids = explode(',', $info['manager']);
                $manager_total = array_merge($manager_total, $manager_ids);
            }
            $apply_num = BroadcastRedis::getApplyCohostNum($channel_id);
            $apply_list = $this->getCohostApplyList($channel_id);
            foreach ($manager_total as $v) {
                Agora::JarPushSinglePointMessage($v, $channel_id, Agora::CohostApply, ['type' => 1, 'apply_list' => $apply_list, 'apply_num' => intval($apply_num), 'apply_uid' => $user_id]);
            }
        }
        $gifts = isset($info['gifts']) ? intval($info['gifts']) : 0;
        $diamonds = isset($info['diamonds']) ? intval($info['diamonds']) : 0;
        $like_heart = isset($info['like_count']) ? intval($info['like_count']) : 0;
        $bot_count = isset($info['bot_count']) ? intval($info['bot_count']) : 0;
        if (!isset($info['duration']) || empty($info['duration'])) {
            $time = time() - strtotime($info['live_start_time']);
        } else {
            $time = $info['duration'];
        }
        //$time = isset($info['duration']) ? $info['duration'] : time() - strtotime($info['live_start_time']);
        if (isset($info['like_heart'])) {
            $like_heart = $info['like_heart'];
        }
        $audience_count = intval(BroadcastRedis::getbroadcastInfo($channel_id, 'total_joined_times')) + $bot_count;
        $data = [
            'user_id' => $user_id,
            'host_type' => intval($host_type['type']),
            'like_count' => intval($like_heart),
            'audience_count' => $audience_count,
            'duration' => intval($time),
            'channel_id' => $channel_id,
            'gifts' => $gifts,
            'diamonds' => $diamonds,
            'user_type' => $user_type,
            'user_tier' => $user_tier,
            'status' => 'Leave success.'
        ];
        return $this->success($data);

    }

    /**
     * 邀请上麦通知
     */
    public function actionInviteSlot()
    {
        $postdata = $this->parameter;
        $check = Service::authorization();
        if (empty($check)) {
            return $this->error("Missing a required parameter.");
        }
        $user_id = $check['user_id'];
        $token = $check['token'];
        $this->checkuser($user_id, $token);

        $channel_id = isset($postdata['channel_id']) ? trim($postdata['channel_id']) : '';
        $op_user = isset($postdata['op_user']) ? trim($postdata['op_user']) : '';
        if (BroadcastRedis::ScoreCohostList($channel_id, $op_user)) {
            return $this->error("The user is already at the seat");
        }

        $res = Agora::JarPushSinglePointMessage($op_user, $channel_id, Agora::CohostApply, ['type' => 9, "msg" => "You've been drafted!"]);
        if (empty($res)) {
            return $this->error(ResponseMessage::STSTEMERROR);
        }
        return $this->success([]);
    }

    /**
     * 查看直播间用户信息
     */
    public function actionViewerInfo()
    {
        $postdata = $this->parameter;
        $check = Service::authorization();
        if (empty($check)) {
            return $this->error("Missing a required parameter.");
        }
        $query_id = isset($postdata['query_id']) ? trim($postdata['query_id']) : '';
        $channel_id = isset($postdata['channel_id']) ? trim($postdata['channel_id']) : '';
        $user_id = $check['user_id'];
        $token = $check['token'];
        $this->checkuser($user_id, $token);
        if (empty($query_id)) {
            return $this->error("User id required.");
        }
//        $this->checkuser($user_id, $token, $check['version_code']);
        if (empty($query_id)) {
            $query_id = $user_id;
        }
        if (!UserRedis::UserExists($query_id)) {
            // Yii::info('user/viewer-info------' . $user_id, 'my');
            return $this->error("User not found.");
        }
        $userinfo = Service::userinfo($query_id, '', $user_id);
        if (empty($userinfo)) {
            return $this->error("User not found.");
        }
        $broadcastInfo = Channel::getbroadcastInfo($channel_id);
        if (empty($broadcastInfo)) {
            return $this->error("Not found live");
        }
        $count_follower = UserRedis::countFollowerFriendsList($query_id);
        $count_following = UserRedis::countFollowingFriendsList($query_id);
        $data = [
            'id' => $userinfo['id'],
            'guid' => $userinfo['guid'],
            'username' => $userinfo['username'],
            'avatar_small' => $userinfo['avatar_small'],
            'avatar' => $userinfo['avatar'],
            'relation_status' => $userinfo['relation_status'],
            'intro' => $userinfo['intro'] ?? '',
            'level' => isset($userinfo['level']) ? intval($userinfo['level']) : 1,
            'type' => isset($userinfo['type']) ? intval($userinfo['type']) : 0,
            'follower_number' => intval($count_follower),
            'following_number' => intval($count_following),
        ];
        //获取被点击用户在直播间内的身份
//        $queryRole = ChannelModerators::GetRole($channel_id, $query_id);
        $queryRole = FbLivecast::checkUserRole($query_id, $channel_id, $broadcastInfo);
        if ($query_id == $user_id) {
            $myRole = $queryRole;
        } else {
            //获取我直播间内的身份
//            $myRole = ChannelModerators::GetRole($channel_id, $user_id);
            $myRole = FbLivecast::checkUserRole($user_id, $channel_id, $broadcastInfo);
        }
        //获取在直播间是否被禁言
        $muteStatus = ChannelModerators::IsMute($channel_id, $query_id);
        $data["query_role"] = $queryRole;
        $data["my_role"] = $myRole;
        $data["mute_status"] = $muteStatus;
        $data["channel_id"] = $channel_id;

        return $this->success($data, 'Get user information successfully', $check['version_code']);
    }

    /**
     * 关注和取消关注 某个直播
     * @return [type] [description]
     */
    public function actionNotify()
    {
        $postdata = $this->parameter;
        $check = Service::authorization();
        if (empty($check)) {
            return $this->error("Missing a required parameter.");
        }

        $user_id = $check['user_id'];
        $token = $check['token'];
        $device_type = $check['device_type'];
        $device_id = $check['device_id'];
        $action = isset($postdata['action']) ? trim($postdata['action']) : '1';
        $channel_id = isset($postdata['channel_id']) ? $postdata['channel_id'] : "";

        $this->checkuser($user_id, $token);

        if (!in_array($action, ['1', '0'])) {
            return $this->error("Action is incorrect.");
        }
        if (empty($channel_id)) {
            return $this->error("Channel id required.");
        }
        //只能关注还没有开播的直播
        $channel = Channel::find()->select(['guid', 'user_id'])->where(['guid' => $channel_id, 'type' => '0', 'deleted' => 'no'])->one();
        if (empty($channel)) {
            return $this->error("Livestream has ended.");
        }
        $block_back_list = UserRedis::blockList($channel['user_id']);
        if (in_array($user_id, $block_back_list)) {
            return $this->error('This user has blocked you.');
        }
//        if ($channel['user_id'] == $user_id) {
//            return $this->error('User cannot be notified of his own broadcast.');
//        }
        $followflag = FollowBroadcast::find()->where(['user_id' => $user_id, 'channel_id' => $channel_id])->one();
        //关注某个直播
        if ($action == '1') {
            BroadcastRedis::addFollower($channel_id, $user_id);
            if (!empty($followflag)) {
                if ($followflag->is_notify == 'false') {
                    $followflag->is_notify = 'true';
                    $followflag->save();
                }
                $msg = 'Success';
            } else {
                $follow = new FollowBroadcast();
                $follow->user_id = $user_id;
                $follow->channel_id = $channel_id;
                $follow->device_type = $device_type;
                $follow->device_id = $device_id;
                if ($follow->save()) {
                    $msg = 'Success';
                } else {
                    Yii::info('follow/index:[follow] save to mysql faild----' . $user_id . '----' . $channel_id, 'interface');
                    return $this->error("Followering faild.");
                }
            }
        } else {
            if (empty($followflag)) {
                $follow = new FollowBroadcast();
                $follow->user_id = $user_id;
                $follow->channel_id = $channel_id;
                $follow->device_type = $device_type;
                $follow->device_id = $device_id;
                $follow->is_notify = 'false';
                $follow->save();
            } else {
                $followflag->is_notify = 'false';
                $followflag->save();
            }
            BroadcastRedis::remFollower($channel_id, $user_id);
            $msg = 'Success';
        }


        return $this->success([], $msg);
    }

    //查看某个broadcast的信息
    public function actionInfo()
    {
        $postdata = file_get_contents("php://input");
        Yii::info('broadcast/info------' . $postdata, 'interface');
        $postdata = json_decode($postdata, true);
        if (empty($postdata)) {
            return $this->error("Post Data is empty.");
        }

        $check = Service::authorization();
        if (empty($check)) {
            return $this->error("Missing a required parameter.");
        }

        $user_id = $check['user_id'];
        $token = $check['token'];
        $this->checkuser($user_id, $token);
        $channel_id = isset($postdata['channel_id']) ? trim($postdata['channel_id']) : '';

        if (empty($channel_id)) {
            return $this->error("Channel id required.");
        }
        $array = Channel::getbroadcastInfo($channel_id);
        if (empty($array)) {
            return $this->error("Not found data");
        }
        if ($array['user_id'] != $user_id) {
            return $this->error("You can't look at it");
        }
        $data = [];
        $data['user_id'] = $array['user_id'];
        $data['channel_id'] = $array['channel_id'];
        $data['type'] = (int)$array['type'];
        $data['title'] = $array['title'];
        $data['description'] = $array['description'] ?? "";
        $extra = $array['extra'] ?? "";
        $data["tag"] = isset($array["tags"]) ? $array["tags"] : "";
        $data["email"] = isset($array["send_email"]) ? $array["send_email"] : "";
        $data["time"] = isset($array["scheduled_time"]) ? strtotime($array["scheduled_time"]) : 0;
        if (!empty($extra)) {
            if ($extraArr = json_decode($extra, true)) {
                $data["record_room"] = isset($extraArr["record_room"]) ? (int)$extraArr["record_room"] : 1;
                $schedule_cohost = isset($extraArr["schedule_cohost"]) ? $extraArr["schedule_cohost"] : [];
                $close_user_arr = [];
                foreach ($schedule_cohost as $one_user_guid) {
//                    if (count($close_user_arr) >= self::LEN_VIEWER) break;
                    $one_user_info = UserRedis::getUserInfo($one_user_guid, ['avatar', 'username', 'first_name', 'last_name']);
                    if (empty($one_user_info['avatar'])) {
                        continue;
                    }
                    $one_user = [];
                    $one_user['guid'] = $one_user_guid;
                    $one_user['username'] = $one_user_info["username"];
                    $one_user['avatar'] = Service::getCompleteUrl($one_user_info['avatar']);
                    $close_user_arr[] = $one_user;
                }
                $data["schedule_cohost"] = $close_user_arr;

                $featured_cohost = isset($extraArr["feature_cohost"]) ? $extraArr["feature_cohost"] : [];
                $featured_user_arr = [];
                foreach ($featured_cohost as $one_user_guid) {
//                    if (count($close_user_arr) >= self::LEN_VIEWER) break;
                    $one_user_info = UserRedis::getUserInfo($one_user_guid, ['avatar', 'username', 'first_name', 'last_name']);
                    if (empty($one_user_info['avatar'])) {
                        continue;
                    }
                    $one_user = [];
                    $one_user['guid'] = $one_user_guid;
                    $one_user['username'] = $one_user_info["username"];
                    $one_user['avatar'] = Service::getCompleteUrl($one_user_info['avatar']);
                    $featured_user_arr[] = $one_user;
                }
                $data["feature_cohost"] = $featured_user_arr;

            }
        }
        return $this->success($data);
    }

    /**
     * 直播状态查询
     */
    public function actionStatus()
    {
        $postdata = $this->parameter;
        $check = Service::authorization();
        if (empty($check)) {
            return $this->error("Missing a required parameter.");
        }

        $user_id = $check['user_id'];
        $token = $check['token'];
        $this->checkuser($user_id, $token);
        $channel_id = isset($postdata['channel_id']) ? trim($postdata['channel_id']) : '';
        if (empty($channel_id)) {
            return $this->error("Feed id required.");
        }
        $array = BroadcastRedis::getbroadcastInfo($channel_id);
        if (empty($array) || !isset($array['type'])) {
            return $this->error('Livestream not available');
        }
        if ($array['type'] == '2' && empty($array['video_url'])) {
            return $this->error('Livestream replay not available');
        }
        return $this->success(['type' => intval($array['type']), 'live_type' => isset($array['live_type']) ? intval($array['live_type']) : 1]);

    }
}