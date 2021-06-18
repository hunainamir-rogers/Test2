<?php

namespace app\controllers;

use app\components\Agora;
use app\components\BaseController;
use app\components\firebase\FbLivecast;
use app\components\redis\BroadcastRedis;
use app\components\redis\LiveRedis;
use app\components\redis\UserRedis;
use app\components\Rongyun;
use app\models\ChannelModerators;
use app\models\User;
use app\models\Channel;
use app\models\ModeratorsOpLog;
use app\models\Service;
use Firebase\FirebaseLib;
use Yii;
use yii\db\Query;


class ModeratorController extends BaseController
{
    /**
     * @api {post} /moderator/list
     * @apiVersion 0.0.0
     * @apiName list 获取房管列表接口
     * @apiGroup Moderator
     *
     * @apiSuccess {String} data []
     */
    public function actionList()
    {
        $check = Service::authorization();
        if (empty($check)) {
            return $this->error("Missing a required parameter.");
        }
        $postdata = $this->parameter;
        $user_id = $check['user_id'];
        $token = $check['token'];
        $this->checkuser($user_id, $token);
        $channel_id = $postdata["channel_id"] ?? "";
//        $is_idol = UserRedis::getUserInfo($user_id,'is_idol');
//        if(empty($is_idol) || $is_idol != UsersExtends::IDOL_ROLE['idol']){
//            return $this->error("Permission denied.");
//        }
        $info = BroadcastRedis::getbroadcastInfo($channel_id);
        $host_id = $info["user_id"] ?? "";
        if(empty($info) || empty($user_id)){
            return $this->error("Not found live");
        }
        $manager_arr = ChannelModerators::GetAllModerators($host_id, $channel_id);
        $data = $list = [];
        $users_info = UserRedis::getUserInfoBatch($manager_arr,['guid','username','nickname','gender','title','avatar','type']);
        foreach ($users_info as $key => $value) {
            if (!isset($value['guid'])||empty($value['guid'])){
                continue;
            }
            $info_data = [];
            $info_data = Service::Simpleuserinfo($value);
            $list [] = $info_data;
        }
        $data = [
            'list' => $list,
            'since_id' => '',
        ];
        return $this -> success($data,'Success');
    }



    public function _actionOperate()
    {
        $postdata = $this->parameter;
        $channel_id = isset($postdata["channel_id"]) ? trim($postdata["channel_id"]) : "";
        $user_id = isset($postdata["user_id"]) ? trim($postdata["user_id"]) : "";
        $moderators_op = isset($postdata["moderators_op"]) ? trim($postdata["moderators_op"]) : "";

        if (!in_array($moderators_op, ChannelModerators::op_map)) {
            return $this->error("Illegal operation!");
        }
        if (empty($channel_id) || empty($user_id)) {
            return $this->error("parameter incorrect.");
        }
        $check = Service::authorization();
        if (empty($check)) {
            return $this->error("Missing a required parameter.");
        }
        $moderators_user = $check["user_id"];
        $token = $check['token'];
        $this->checkuser($moderators_user, $token);
        $broadcastInfo = Channel::getbroadcastInfo($channel_id,['channel_id','user_id','manager','live_type']);
        if (empty($channel_id)) {
            return $this->error("not found livestreams");
        }
        $host_id = $broadcastInfo["user_id"];
        //查看是否有管理权限
        if (!ChannelModerators::CheckModerators($host_id, $moderators_user,$broadcastInfo)) {
            return $this->error("You're not the moderators.");
        }
        //查看对方是否是管理员
        if (ChannelModerators::CheckModerators($host_id, $user_id ,$broadcastInfo)) {
            if($moderators_op == ChannelModerators::op_kick){
                return $this->error("Cannot kick out a moderator");
            }
            if($moderators_op == ChannelModerators::op_mute){
                return $this->error("Cannot mute a moderator");
            }
            return $this->error("you cannot {$moderators_op} a moderator");
        }
        $log_id = "";
        $moderators_username = UserRedis::getUserInfo($moderators_user,'username');
        $moderators_username = empty($moderators_username) ? 'moderator' : $moderators_username;
        $rtmp_data_type = -1;
        $notice_msg = '';
        switch ($moderators_op) {
            case ChannelModerators::op_mute:
                $log_id = ChannelModerators::Mute($channel_id, $moderators_user, $user_id, $host_id);
                $notice_msg = 'You have been muted by '.$moderators_username.'.';
                $rtmp_data_type = 5;
                break;
            case ChannelModerators::op_unmute:
                $log_id = ChannelModerators::UnMute($channel_id, $moderators_user, $user_id, $host_id);
                $notice_msg = 'You have been unmuted by '.$moderators_username.'.';
                $rtmp_data_type = 5;
                break;
            case ChannelModerators::op_kick:
                $log_id = ChannelModerators::Kick($channel_id, $moderators_user, $user_id, $host_id);
                Channel::FlushSlot($channel_id, $user_id);
                //观众列表移除
                BroadcastRedis::remAudience($channel_id,$user_id);
                $rtmp_data_type = 4;
                $notice_msg = 'You have been kicked out by '.$moderators_username.', unable to reenter for 5 mins.';
                break;
            case ChannelModerators::op_block:
                $device_type = $check['device_type'];
                $device_id = $check['device_id'];
                $log_id = ChannelModerators::Block($channel_id, $moderators_user, $user_id, $host_id, $device_type, $device_id);
                $rtmp_data_type = 3;
                $notice_msg = 'You have been blocked by '.$moderators_username.'.';
                break;
            case ChannelModerators::op_unblock:
                $log_id = ChannelModerators::UnBlock($channel_id, $moderators_user, $user_id, $host_id);
                $rtmp_data_type = 99;
                $notice_msg = 'You have been unblocked by '.$moderators_username.'.';
                break;
            default:
                return $this->error("unknown operate");
                break;
        }

        if($rtmp_data_type > 0){
            $rtmp_data = [
                'to'=>$user_id,
                'content'=>$notice_msg,
                "channel_id"=>$channel_id,
                "op_id"=>$log_id,
                "moderators_op"=>$moderators_op,
                "type"=>$rtmp_data_type,
                "send_from"=>$moderators_user,
                "live_type" => empty($broadcastInfo['live_type']) ? 0 : intval($broadcastInfo['live_type']),
            ];
            Agora::JarPushSinglePointMessage($user_id,'',Agora::ModeratorOperate,$rtmp_data);
            $rongyun_data = [
                'content'=>$notice_msg,
                "moderators_op" => $moderators_op,
                'to'=>$user_id,
                "type"=>$rtmp_data_type,
                "channel_id"=>$channel_id,
            ];
            Rongyun::sendCmdMessage(Rongyun::RcSystemSender,$user_id,$rongyun_data,Agora::ModeratorOperate);
        }

        //kick rtc   && ChannelModerators::CheckMaModerators($moderators_user)
        if($moderators_op == ChannelModerators::op_kick  || $moderators_op == ChannelModerators::op_block ){
           $re =  Agora::RtcKick($channel_id, $user_id);
           \Yii::info('rtc close result='.json_encode($re).',uid='.$user_id,'my');
        }

        return $this->success(["op_id" => $log_id, "user_id" => $user_id]);
    }

    /**
     * livecast 直播间管理员操作
     */
    public function actionOperate()
    {
        $postdata = file_get_contents("php://input");
        Yii::info('moderator/livecast-operate------' . $postdata, 'interface');
        $postdata = json_decode($postdata, true);
        if (empty($postdata)) {
            return $this->error("Post Data is empty.");
        }
        $channel_id = isset($postdata["channel_id"]) ? trim($postdata["channel_id"]) : "";
        $user_id = isset($postdata["user_id"]) ? trim($postdata["user_id"]) : "";
        $moderators_op = isset($postdata["moderators_op"]) ? trim($postdata["moderators_op"]) : "";
        $other_op = isset($postdata["other_op"]) ? trim($postdata["other_op"]) : "";//其他操作，report举报

        if (!in_array($moderators_op, ChannelModerators::op_map)) {
            return $this->error("Illegal operation!");
        }
        if (empty($channel_id) || empty($user_id)) {
            return $this->error("parameter incorrect.");
        }
        $check = Service::authorization();
        if (empty($check)) {
            return $this->error("Missing a required parameter.");
        }
        $moderators_user = $check["user_id"];
        $token = $check['token'];
        $this->checkuser($moderators_user, $token);
        $broadcastInfo = Channel::getbroadcastInfo($channel_id,['channel_id','user_id','manager','live_type']);
        if (empty($channel_id)) {
            return $this->error("not found livestreams");
        }
        $host_id = $broadcastInfo["user_id"];
        if(empty($broadcastInfo['live_type']) ||   $broadcastInfo['live_type'] != Channel::liveCastLive){
            return $this->error("This live type is not supported by this api.");
        }
        //查看是否有管理权限
        $moderators_user_role = FbLivecast::checkUserRole($moderators_user,$channel_id,$broadcastInfo);
        if (!in_array($moderators_user_role,[FbLivecast::ROLE['host'],FbLivecast::ROLE['moderator']])) {
            return $this->error("You're not the moderators.");
        }
        //查看对方是否是管理员
        $user_id_role = FbLivecast::checkUserRole($user_id,$channel_id,$broadcastInfo);
        if (in_array($user_id_role,[FbLivecast::ROLE['host'],FbLivecast::ROLE['moderator']])) {
            if( ChannelModerators::CheckMaModerators($user_id)){
                return $this->error("Cannot {$moderators_op} a super moderator");
            }
            //if($moderators_op != ChannelModerators::op_mute && $moderators_op != ChannelModerators::op_unmute){
            //    return $this->error("you cannot {$moderators_op} a moderator");
            //}
            if($user_id_role == FbLivecast::ROLE['host']){
                return $this->error("you cannot {$moderators_op} the host");
            }
        }
        $log_id = "";
        $moderators_username = UserRedis::getUserInfo($moderators_user,'username');
        $moderators_username = empty($moderators_username) ? 'moderator' : $moderators_username;
        $rtmp_data_type = -1;
        $notice_msg = '';
        switch ($moderators_op) {
            case ChannelModerators::op_mute:
                $result = FbLivecast::muteOprate($channel_id,$user_id,FbLivecast::USER_STATUS['close_voice']);
                if($result['error']){
                    return $this->error($result['message']);
                }
                $log_id = ChannelModerators::Mute($channel_id, $moderators_user, $user_id, $host_id);
                $notice_msg = 'You have been muted by '.$moderators_username.', you can unmute yourself by clicking the mic button.';
                $rtmp_data_type = 5;

                break;
            case ChannelModerators::op_unmute:
                $result = FbLivecast::muteOprate($channel_id,$user_id,FbLivecast::USER_STATUS['open_voice']);
                if($result['error']){
                    return $this->error($result['message']);
                }
                $log_id = ChannelModerators::UnMute($channel_id, $moderators_user, $user_id, $host_id);
                $notice_msg = 'You have been unmuted by '.$moderators_username.', you can mute yourself by clicking the mic button.';
                $rtmp_data_type = 5;
                break;
            case ChannelModerators::op_kick:
                $log_id = ChannelModerators::Kick($channel_id, $moderators_user, $user_id, $host_id);
                //观众列表移除
                FbLivecast::kickOneUser($user_id,$channel_id);
                BroadcastRedis::remAudience($channel_id,$user_id);
                $notice_msg = 'You have been kicked by '.$moderators_username.'.';
                $rtmp_data_type = 4;
                break;
            case ChannelModerators::op_block:
                if($moderators_user != $host_id){
                    return  $this->error("Only host can block");
                }
                $device_type = $check['device_type'];
                $device_id = $check['device_id'];
                $log_id = ChannelModerators::Block($channel_id, $moderators_user, $user_id, $host_id, $device_type, $device_id);
                //FbLivecast::kickOneUser($user_id,$channel_id);
                $notice_msg = 'You have been blocked by '.$moderators_username.'.';
                $rtmp_data_type = 3;
                break;
            case ChannelModerators::op_unblock:
                if($moderators_user != $host_id){
                    return  $this->error("Only host can unblock");
                }
                $log_id = ChannelModerators::UnBlock($channel_id, $moderators_user, $user_id, $host_id);
                $rtmp_data_type = 99;
                $notice_msg = 'You have been unblocked by '.$moderators_username.'.';
                break;
            case ChannelModerators::op_kick_slots:
                $log_id = ChannelModerators::KickSlots($channel_id, $moderators_user, $user_id, $host_id);
                $rtmp_data_type = 105;
                $notice_msg = "You've been turned into an audience by ".$moderators_username.'.';
                $result = FbLivecast::LeaveAudioCost($user_id, $channel_id, "", "", false, 0);
                if ($result['error']) {
                    return $this->error($result['message'], $result['code']);
                }
                break;
            default:
                return $this->error("unknown operate");
                break;
        }
        if($rtmp_data_type > 0){
            $rtmp_data = [
                'to'=>$user_id,
                'content'=>$notice_msg,
                "channel_id"=>$channel_id,
               // "op_id"=>$log_id,
                "moderators_op" => $moderators_op,
                "type" => $rtmp_data_type,
                "send_from"=>$moderators_user,
                "live_type" => empty($broadcastInfo['live_type']) ? 0 : intval($broadcastInfo['live_type']),
            ];
            Agora::JarPushSinglePointMessage($user_id,'',Agora::ModeratorOperate,$rtmp_data);
            $rongyun_data = [
                'content'=>$notice_msg,
                "moderators_op" => $moderators_op,
                'to'=>$user_id,
                "type"=>$rtmp_data_type,
                "channel_id"=>$channel_id,
            ];
            Rongyun::sendCmdMessage(Rongyun::RcSystemSender,$user_id,$rongyun_data,Agora::ModeratorOperate);
        }
        if($moderators_op == ChannelModerators::op_kick || $moderators_op == ChannelModerators::op_block){
            Agora::RtcKick($channel_id, $user_id);
        }

        return $this->success(["op_id" => $log_id, "user_id" => $user_id]);
    }


    public function actionVerify()
    {
        $postdata = file_get_contents("php://input");
        Yii::info('moderator/verify------' . $postdata, 'interface');
        $postdata = json_decode($postdata, true);
        if (empty($postdata)) {
            return $this->error("Post Data is empty.");
        }
        $op_id = isset($postdata["op_id"]) ? trim($postdata["op_id"]) : "";
        $moderators_op = isset($postdata["moderators_op"]) ? trim($postdata["moderators_op"]) : "";
        if (empty($op_id)) {
            return $this->error("parameter incorrect.");
        }
        $check = Service::authorization();
        if (empty($check)) {
            return $this->error("Missing a required parameter.");
        }
        $user_id = $check["user_id"];
        $token = $check['token'];
        $this->checkuser($user_id, $token);

        $LogInfo = ModeratorsOpLog::One($op_id);
        if (!$LogInfo) {
            return $this->error("not found record.");
        }
        $op = $LogInfo->op;
        $effect_user = $LogInfo->effect_user;
        if ($moderators_op != $op) {
            return $this->error("moderators_op parameter incorrect.");
        }
        if ($user_id != $effect_user) {
            return $this->error("user not found record.");
        }
        return $this->success([]);
    }



    /**
     * @api {post} /moderator/get-uid-info  通过uid获取用户信息
     * @apiVersion 0.0.0
     * @apiName get-uid-info 数据
     * @apiGroup livecast
     * @apiParam {string} uid
     *
     * @apiSuccess {String} data []
     */
    public function actionGetUidInfo()
    {
        $postdata = file_get_contents("php://input");
        Yii ::info('moderator/get-uid-info ------' . $postdata, 'interface');
        $postdata = json_decode($postdata, true);
        $check = Service ::authorization();
        if (empty($check)) {
            return $this -> error("Missing a required parameter.");
        }
        $uids = isset($postdata['uid']) ? $postdata['uid'] : [];
        $user_id = $check['user_id'];
        $token = $check['token'];
        $this -> checkuser($user_id, $token);
        $list = [];
        if(!empty($uids) && is_array($uids)){
            $data = User::find()->select(['guid','avatar','username','id'])->where(['id'=>$uids])->asArray()->all();
            if(empty($data)){
                return $this -> error("User not found.");
            }
            foreach ($data as $one) {
                $re_data = [];
                $re_data['guid'] = empty($one['guid']) ?  '' : $one['guid'];
                $avatar = $one['avatar'] ?? '/default/default_avatar.png';
                $re_data['avatar'] = empty($one['guid']) ? '' : Service::getCompleteUrl($avatar);
                $re_data['username'] = empty($one['username']) ?  '' : $one['username'];
                $re_data['id'] = empty($one['id']) ?  0 : (int)$one['id'];
                $list[] = $re_data;
            }
        }

        return $this -> success($list,'Success',null,false);
    }

    /**
     * @api {post} /moderator/kick-rtc  把人踢出rtc
     * @apiVersion 0.0.0
     * @apiName kick-rtc 数据
     * @apiGroup livecast
     * @apiParam {string} op_user
     * @apiParam {string} channel_id
     *
     * @apiSuccess {String} data []
     */
    public function actionKickRtc()
    {
        $postdata = file_get_contents("php://input");
        Yii ::info('moderator/kick-rtc------' . $postdata, 'interface');
        $postdata = json_decode($postdata, true);
        $check = Service ::authorization();
        if (empty($check)) {
            return $this -> error("Missing a required parameter.");
        }
        $op_user = isset($postdata['op_user']) ? trim($postdata['op_user']) : '';
        $channel_id = isset($postdata['channel_id']) ? trim($postdata['channel_id']) : "";
        $user_id = $check['user_id'];
        $token = $check['token'];
        $this -> checkuser($user_id, $token);
        $this->CheckRequestSign($postdata);
        $broadcastInfo = Channel::getbroadcastInfo($channel_id,['channel_id','user_id','manager','live_type']);
        if (empty($channel_id)) {
            return $this->error("not found livestreams");
        }
        $host_id = $broadcastInfo["user_id"];
        if (!ChannelModerators::CheckModerators($host_id, $user_id ,$broadcastInfo)) {
            return $this -> error('You don\'t have the right to operate');
        }
        $re =  Agora::RtcKick($channel_id, $op_user);
        if(!isset($re['code']) || $re['code'] != '1'){
            return $this -> error($re['msg']);
        }
        \Yii::info('rtc close result='.json_encode($re).',uid='.$op_user,'my');
        LiveRedis::addRtcKickHistory($channel_id, $op_user);
        return $this -> success('Success');
    }


    /**
     * 添加直播管理员
     */
    public function actionAdd()
    {
        $postdata = file_get_contents("php://input");
        Yii::info('moderator/add------' . $postdata, 'interface');
        $postdata = json_decode($postdata, true);
        if (empty($postdata)) {
            return $this->error("Post Data is empty.");
        }
        $channel_id = isset($postdata["channel_id"]) ? trim($postdata["channel_id"]) : "";
//        $host_id = isset($postdata["host_id"])? trim($postdata["host_id"]): "";
        $user_id = isset($postdata["user_id"]) ? trim($postdata["user_id"]) : "";
        if (empty($user_id)) {
            return $this->error("parameter incorrect.");
        }
        $check = Service::authorization();
        if (empty($check)) {
            return $this->error("Missing a required parameter.");
        }
        $host_id = $check["user_id"];
        $this->checkuser($host_id, $check["token"]);
        //判断是否还能添加管理员
        $limit = ChannelModerators::moderators_limit;
        if (ChannelModerators::Count($host_id) >= $limit) {
            return $this->error("Maximum of {$limit} moderators. Edit moderators in your Profile");
        }
        if (ChannelModerators::CheckMaModerators($user_id)) {
            return $this->error("Already an moderators");
        }
        $result = ChannelModerators::Add($host_id, $user_id, $channel_id);
        if (!$result) {
            return $this->error("System error, please try again later.");
        }
        //管理员上槽
        $result = FbLivecast::JoinAudioCost($channel_id, $user_id, FbLivecast::ROLE['moderator']);
        if ($result["error"]) {
            return $this->error($result["message"], $result["code"]);
        }
        $rtmp_data = [
            'to'=>$user_id,
            'content'=>"You are now a Moderator.",
            "channel_id"=>$channel_id,
            "type" => 6,
        ];
        Service::reloadBroadcast($channel_id, "reload");
        Agora::JarPushSinglePointMessage($user_id,'',Agora::ModeratorOperate,$rtmp_data);
        return $this->success([]);

    }

    /**
     * 移除频道内管理员
     */
    public function actionRemove()
    {
        $postdata = file_get_contents("php://input");
        Yii::info('moderator/remove------' . $postdata, 'interface');
        $postdata = json_decode($postdata, true);
        if (empty($postdata)) {
            return $this->error("Post Data is empty.");
        }
//        $host_id = isset($postdata["host_id"])? trim($postdata["host_id"]): "";
        $user_id = isset($postdata["user_id"]) ? trim($postdata["user_id"]) : "";
        $channel_id = isset($postdata["channel_id"]) ? trim($postdata["channel_id"]) : "";
        if (empty($user_id)) {
            return $this->error("parameter incorrect.");
        }
        $check = Service::authorization();
        if (empty($check)) {
            return $this->error("Missing a required parameter.");
        }
        $host_id = $check["user_id"];
        $this->checkuser($host_id, $check["token"]);
        if (ChannelModerators::CheckMaModerators($user_id)) {
            return $this->error("You cannot remove a all live moderators.");
        }
        //移除firebase 管理员
        FbLivecast::RemoveModerator($channel_id, $user_id);
//        $result = FbLivecast::LeaveAudioCost($user_id, $channel_id);
//        if ($result["error"]) {
//            return $this->error($result["message"], $result["code"]);
//        }
        $result = ChannelModerators::Remove($host_id, $user_id);
        if (!$result) {
            return $this->error("System error, please try again later.");
        }
        $rtmp_data = [
            'to'=>$user_id,
            'content'=>"You have been removed as moderator",
            "channel_id"=>$channel_id,
            "type" => 7,
        ];
        Service::reloadBroadcast($channel_id, "reload");
        Agora::JarPushSinglePointMessage($user_id,'',Agora::ModeratorOperate,$rtmp_data);
        return $this->success([]);
    }


}
