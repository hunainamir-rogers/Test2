<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace app\commands;

use app\components\firebase\FbLivecast;
use app\components\LiveLogic;
use app\components\redis\BroadcastRedis;
use app\components\redis\QueueRedis;
use app\components\redis\UserRedis;
use app\components\ResponseTool;
use app\components\Util;
use app\models\Channel;
use app\models\ChannelModerators;
use app\models\Service;
use app\models\User;
use app\models\UserLoginLog;
use Firebase\FirebaseLib;
use Yii;
use yii\console\Controller;

/**
 * This command echoes the first argument that you have entered.
 *
 * This command is provided as an example for you to learn how to create console commands.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @since 2.0
 */
class InitController extends Controller
{

    /**
     * 生成直播列表测试数据
     * @param int $type
     * @param int $totalUser
     */
    public function actionGeneralChannel($room_title, $type = 1, $description = "")
    {
        $arr = $this->actionGenerateUser(1, "", 1);
        if (empty($arr)) {
            Service::log_time("User generate fail");
            return false;
        }
        $user_id = $arr[0];
        //是不是能重复创建新语聊房
        $cover_image = "";
        $guid = Service::create_guid();
        $channel = new Channel();
        $channel->guid = $guid;
        $channel->user_id = $user_id;
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
        $channel->live_start_time = date('Y-m-d H:i:s');
        $channel->description = $description;
        $channel->title = $room_title;
        $channel->type = Channel::type_status_living;
        $channel->is_live = 'yes';
        $channel->type = $type;
        $channel->tags = "NBA";
        if ($type == 0) {
            $channel->is_live = 'no';
            $channel->type = Channel::type_status_upcoming;
            $channel->scheduled_time = date("Y-m-d H:i:s", time() + 60 * 60 * 24);
            $extra['schedule_cohost'] = $this->actionGenerateUser(6);
            $channel->extra = json_encode($extra);
        }

        if (!$channel->save()) {
            Service::log_time("channel generate fail");
            return false;
        }

        Service::reloadBroadcast($guid, 'create');
        if ($type == 0) {
            LiveLogic::SetUpcomingLive($guid, $user_id, time() + 60 * 60 * 24);
            Service::log_time("success");
            return true;
        }
        LiveLogic::SetExploreLiveList($guid, $user_id);

        $slotsUserArr = $this->actionGenerateUser(6);
        //写入firebase
        $db_url = Yii::$app->params["firebase"]["db_url"] ?? '';
        $secretKey = Yii::$app->params["firebase"]["secretKey"] ?? '';
        $firebase = new FirebaseLib($db_url, $secretKey);
        $defalut_path = FbLivecast::getOneRoomPath($guid);
        $response = $firebase->get($defalut_path . '/slots');
        if ($response != 'null') {
            //有就先清除，防止脏数据
            $firebase->delete($defalut_path);
        }
        $user_info = UserRedis::getUserInfo($user_id);
        $arr = [];
        //槽位上的人
        $arr['slots'] = [];
//        $arr['slots'][$user_id] = FbLivecast::getFbFormatUserInfo($user_info, FbLivecast::ROLE['host']);
//        $arr['slots'][$user_id]['st'] = FbLivecast::USER_STATUS['open_voice'];
        //观众
        $arr['viewers'] = [];
        //房间信息
        $arr['info'] = [
            'title' => $room_title,
            'host_id' => $user_id,
        ];
        //管理员
        $arr['admin'] = ChannelModerators::GetAllModerators($user_id, $guid);
        $result = $firebase->set($defalut_path, $arr);
        if ($result == 'null') {
            Service::log_time("host firebase generate fail");
        }
        foreach ($slotsUserArr as $item) {
            $result = FbLivecast::JoinAudioCost($guid, $item);
            Service::log_time("channel_id: $guid, user_id: $item, join slots: ".json_encode($result));
        }
        $viewerUserArr = $this->actionGenerateUser(rand(4, 10));
        foreach ($viewerUserArr as $viewer){
            $viewerInfo  = Service::userInfo($viewer);
            if (!FbLivecast::synJoinDataToFirebase($guid, $viewer, $viewerInfo, FbLivecast::ROLE['viewers'])) {
                Service::log_time("Add viewer fail");
            }
        }
    }

    public function actionGenerateUser($totalUser = 6, $api_key = "Cph30qkLrdJDkjW-THCeyA", $type = 0)
    {
        $res = self::GetAvatarList($api_key);
        shuffle($res);
        $data = [];
        foreach ($res as $avatar) {
            $uuid = Service::create_guid();
            $user = new User();
            $user->guid = $uuid;
            $user->status = "normal";
            $user->avatar = $avatar;
            $user->regist_ip = "";
            $user->login_ip = "";
            $user->type = $type;
            $user->username = self::random_user(rand(6, 10));
            $user->first_name = self::random_user(rand(4, 8));
            $user->last_name = self::random_user(rand(4, 8));
            $user->email = md5($avatar);
            if (!$user->save()) {
                Service::log_time('user save fail code: 3' . json_encode($user->getErrors()));
                break;
            }
            $userinfo = Service::userinfo($user->guid, 'register', $user->guid);
            if (!$userinfo) {
                Service::log_time('user/login register failed------' . json_encode($userinfo, JSON_UNESCAPED_UNICODE), 'my');
            }
            $data[] = $uuid;
        }
        return $data;
    }

    /**
     * https://generated.photos/faces#
     * @param $api_key
     * @param int $page
     * @param int $page_size
     * @return bool|array
     */
    public static function GetAvatarList($api_key, $page = 1, $page_size = 30)
    {
        $reg = '/<img[\s\S]+?src=[\'\"](.+?)[\'\"][\s\S\>]?/';
        $body = file_get_contents("https://generated.photos/faces#");
        preg_match_all($reg, $body, $arr);
        return $arr[1];
    }

    static function random_user($len = 8)
    {
        $user = '';
        $lchar = 0;
        $char = 0;
        for ($i = 0; $i < $len; $i++) {
            while ($char == $lchar) {
                $char = rand(48, 109);
                if ($char > 57) $char += 7;
                if ($char > 90) $char += 6;
            }
            $user .= chr($char);
            $lchar = $char;
        }
        return $user;

    }
}
