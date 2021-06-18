<?php
/**
 * Created by PhpStorm.
 * User: XHY
 * Date: 2018/6/7
 * Time: 10:41
 */

namespace app\components;

use app\components\agora\AccessToken;
use app\components\redis\UserRedis;
use app\models\Service;
use app\models\User;
use Yii;

class Agora
{

    const RESTFULAPI_HOST = "https://api.agora.io/v1";
    const RESTFULRTMAPI_HOST = "https://api.agora.io/dev/v2";
    const RecUid = "19";
    const ScreenShotUid = "21";
    const RtmpUid = 20;
    const RtmpVirtualUidRate = 100000000;
    const RtmPushMessageUser = "system-messager";
    //jar op
    const AudioShareDiamond = 800;
    const PkOperate = 900;
    const GiftOperate = 600; //下发礼物事件
    const NewbieGiftOperate = 602; // 新人下发礼物
    const GiftPremiumOperate = 1000; //Premium礼物下发事件
    const StickerUpdate = 1100; //sticker 改变后 的op
    const ShareUpdate = 2001; //分享更新下发通知
    const SpotlightChannelMessage = 2002; //spotlight 频道消息
    const SelectMusic = 3300; //直播音乐 改变后 的op
    const VipEntryAnimation = 3400; // vip用户入场动画
    const LeaveChannelMessage = 3500; // 离开直播间消息

    const ModeratorOperate = 510;//替人等操作的单点消息
    const CohostApply = 760; //举手连麦
    const CohostApplyPass = 761; //举手通过后通知上麦
    const IdolOrderAccept = 900;//给大神下单时发送的订单消息
    const OneMatchOne = 520;//1对1匹配
    const PRIVATE_LIVE_ORDER = 10000;//点单
    const PRIVATE_LIVE_AUDITION = 10001;//试音
    const JEEPNEY_FREE_INVITE = 3001;//车房自动邀请上车
    const ChangeLevel = 4000;//更新用戶等級
    //song 的op
    const Grab_SONG_MATCH_SUCCESS = 11000;//抢歌匹配成功，发送的单点
    const CHANNEL_APPLY_LIST = 10002;//申请上卖列表

    const AUDIENCE_LIST = 10003;//观众列表
    const JEEPNEY_LIST = 10004;//车房jeepney列表
    const JEEPNEY_FINISH_ACTION = 10005;//车房jeepney 游戏完成后的单点通知
    const JEEPNEY_CLOSE = 10006;//车房jeepney 关播消息
    const JEEPNEY_CUT_INLINE = 10007;//车房jeepney 广播插队需要的的优先卡数量
    const AUDIO_CHANGE_SETTING = 10008;//语聊房修改配置
    const LIVE_ROLE_CHANGE = 10009;//直播间用户权限变更通知，单点
    const LEAVE_AUDIO_COST = 10010;//用户下麦发送user_id
    const GAME_MATCH_ACTION = 10011;//游戏匹配的单点消息
    const GAME_MATCH_AUTO_MATCH_ACTION = 10012;//游戏匹配状态的单点消息
    const GAME_AMONG_US_GAME_CODE = 10013;//among us房间修改room code 的消息
    const GAME_MATCH_UPDATE_INFO = 10014;//游戏匹配房间切换变动消息
    const LIVE_HOST_INFO = 10015;//livecast房主信息变更
    const SEND_GEM_COMMENT = 10016;//livecast发送gem打赏评论
    const SEND_NETWORK_QUESTION_MSG = 10017;//ntc回调发送的网络异常rtm消息
    const SystemNotify = 0;
    const captureInterval = 30; //截图频率单位:秒
    //pk uri
    private static $pk_start = "/pk/start";
    private static $pk_join = "/pk/join";
    private static $pk_end = "/pk/end";
    private static $pk_exit = "/pk/exit";

    public static $jarLog = "jar";

    public static $chat_color = ["#4EE1CA", "#00C7FF", "#FF4467", "#FE9A8B"];
    public static $gift_color = "#FFE900";
    public static $chat_color_count = 4;

    //gift event type
    const RegularGiftEvent = 1;
    const PkGiftEvent = 2;
    const AudioCohostGiftEvent = 3;
    const AsapGiftEvent = 4;
    // ----- BEGIN Note: 更新于2021-01-18 设置连接超时和请求超时
    const RequestTimeOut = 2;
    const ConnectTimeOut = 2;
    // ----- END

    /**
     * 生成rtm token
     * @param $channel
     * @param $uid
     * @return string
     */
    public static function GenerateWebRtmToken($channel, $uid = 1)
    {
        if (empty($channel) || empty($uid)) {
            return "";
        }
        $appID = Yii::$app->params['agora']['appID'];
        $appCertificate = Yii::$app->params['agora']['appCertificate'];
        $ts = 0;
        $expireTimestamp = time() + 60 * 60 * 24; //过期时间
        $access = new  AccessToken();
        $builder = $access->init($appID, $appCertificate, $channel, "");
        $builder->message->salt = intval($uid);
        $builder->message->ts = $ts;
        $builder->addPrivilege(1000, $expireTimestamp);
        return $builder->build();
    }

    /**
     * 生成rtm token
     * @param $channel
     * @param $uid
     * @param $AESEncrypt
     * @return string
     */
    public static function GenerateRtmToken($channel, $uid = 1, $AESEncrypt = false)
    {
        if (empty($channel) || empty($uid)) {
            return "";
        }
        $appID = Yii::$app->params['agora']['appID'];
        $appCertificate = Yii::$app->params['agora']['appCertificate'];

        $ts = 0;
        $expireTimestamp = time() + 60 * 60 * 24; //过期时间
        $access = new  AccessToken();
        $builder = $access->init($appID, $appCertificate, $channel, "");
        $builder->message->salt = intval($uid);
        $builder->message->ts = $ts;
        $builder->addPrivilege(1000, $expireTimestamp);
        $token = $builder->build();
        if ($AESEncrypt) {
            $token = self::RtmAesEncrypt($token);
        }
        return $token;
    }

    public static function GenerateRtmTokenTrivia($appID, $appCertificate, $channel, $uid = 1, $AESEncrypt = false)
    {
        if (empty($channel) || empty($uid)) {
            return "";
        }

        $ts = 0;
        $expireTimestamp = time() + 60 * 60 * 24; //过期时间
        $access = new  AccessToken();
        $builder = $access->init($appID, $appCertificate, $channel, "");
        $builder->message->salt = intval($uid);
        $builder->message->ts = $ts;
        $builder->addPrivilege(1000, $expireTimestamp);
        $token = $builder->build();
        if ($AESEncrypt) {
            $token = self::RtmAesEncrypt($token);
        }
        return $token;
    }

    /**
     * 生成rtm token console用
     * @param $channel
     * @param int $uid
     * @return string
     */
    public static function GenerateRtmTokenConsole($channel, $uid = 1)
    {
        if (empty($channel) || empty($uid)) {
            return "";
        }

        $appID = Yii::$app->params['agora']['appID'];
        $appCertificate = Yii::$app->params['agora']['appCertificate'];
//        $appID = Yii::$app->params['agora']['rtm_appID'];
//        $appCertificate = Yii::$app->params['agora']['rtm_appCertificate'];

//        return self::generateSignalChannelKey($appID, $appCertificate, $channel, 3600 * 24);

        $ts = 0;
        $expireTimestamp = time() + 60 * 60 * 24; //过期时间
        $access = new  AccessToken();
        $builder = $access->init($appID, $appCertificate, $channel, "");
        $builder->message->salt = intval($uid);
        $builder->message->ts = $ts;
        $builder->addPrivilege(1000, $expireTimestamp);
        return $builder->build();
    }

    public static function GenerateQuizCommentRtmTokenConsole($channel, $uid = 1)
    {
        if (empty($channel) || empty($uid)) {
            return "";
        }

        $appID = Yii::$app->params['agora']['signalID1'];
        $appCertificate = Yii::$app->params['agora']['signalCertificate1'];
//        $appID = Yii::$app->params['agora']['rtm_appID'];
//        $appCertificate = Yii::$app->params['agora']['rtm_appCertificate'];

//        return self::generateSignalChannelKey($appID, $appCertificate, $channel, 3600 * 24);

        $ts = 0;
        $expireTimestamp = time() + 60 * 60 * 24; //过期时间
        $access = new  AccessToken();
        $builder = $access->init($appID, $appCertificate, $channel, "");
        $builder->message->salt = intval($uid);
        $builder->message->ts = $ts;
        $builder->addPrivilege(1000, $expireTimestamp);
        return $builder->build();
    }

    /**
     * Aes 加密
     * @param $token
     * @param string $rtmAESKey
     * @return false|string
     */
    public static function RtmAesEncrypt($token, $rtmAESKey = "")
    {
        if (empty($rtmAESKey)) {
            $rtmAESKey = isset(Yii::$app->params["agora"]["rtm_aes_key"]) ? Yii::$app->params["agora"]["rtm_aes_key"] : "test";
        }
        return openssl_encrypt($token, 'AES-128-ECB', $rtmAESKey, 0);
    }

    //生成信令token
    public static function generateSignalChannelKey($appid, $appcertificate, $account, $validTimeInSeconds, $AESEncrypt = false)
    {
        $SDK_VERSION = '1';
        $expiredTime = time() + $validTimeInSeconds;
        $token_items = array();
        array_push($token_items, $SDK_VERSION);
        array_push($token_items, $appid);
        array_push($token_items, $expiredTime);
        array_push($token_items, md5($account . $appid . $appcertificate . $expiredTime));
        $token = join(":", $token_items);
        if ($AESEncrypt) {
            $token = self::RtmAesEncrypt($token);
        }
        return $token;
    }
    ////////////////////////////////////
    //agora DynamicKey
    //version 005
    public static function generateMediaChannelKey($channelName, $uid, $AESEncrypt = false)
    {
        $appID = Yii::$app->params['agora']['appID'];
        $appCertificate = Yii::$app->params['agora']['appCertificate'];
        $ts = (string)time();
        $randomInt = rand(100000000, 999999999);
        $expiredTs = 0;
        global $MEDIA_CHANNEL_SERVICE;//没有强制要求
        $token = self::generateDynamicKey($appID, $appCertificate, $channelName, $ts, $randomInt, $uid, $expiredTs, $MEDIA_CHANNEL_SERVICE = 1, array());
        if ($AESEncrypt) {
            $token = self::RtmAesEncrypt($token);
        }
        return $token;
    }

    public static function generateDynamicKey($appID, $appCertificate, $channelName, $ts, $randomInt, $uid, $expiredTs, $serviceType, $extra)
    {
        $version = "005";
        $signature = self::generateSignature($serviceType, $appID, $appCertificate, $channelName, $uid, $ts, $randomInt, $expiredTs, $extra);
        $content = self::packContent($serviceType, $signature, hex2bin($appID), $ts, $randomInt, $expiredTs, $extra);
        // echo bin2hex($content);
        return $version . base64_encode($content);
    }

    static function generateSignature($serviceType, $appID, $appCertificate, $channelName, $uid, $ts, $salt, $expiredTs, $extra)
    {
        $rawAppID = hex2bin($appID);
        $rawAppCertificate = hex2bin($appCertificate);

        $buffer = pack("S", $serviceType);
        $buffer .= pack("S", strlen($rawAppID)) . $rawAppID;
        $buffer .= pack("I", $ts);
        $buffer .= pack("I", $salt);
        $buffer .= pack("S", strlen($channelName)) . $channelName;
        $buffer .= pack("I", $uid);
        $buffer .= pack("I", $expiredTs);
        $buffer .= pack("S", count($extra));
        foreach ($extra as $key => $value) {
            $buffer .= pack("S", $key);
            $buffer .= pack("S", strlen($value)) . $value;
        }
        return strtoupper(hash_hmac('sha1', $buffer, $rawAppCertificate));
    }

    static function packString($value)
    {
        return pack("S", strlen($value)) . $value;
    }

    static function packContent($serviceType, $signature, $appID, $ts, $salt, $expiredTs, $extra)
    {
        $buffer = pack("S", $serviceType);
        $buffer .= self::packString($signature);
        $buffer .= self::packString($appID);
        $buffer .= pack("I", $ts);
        $buffer .= pack("I", $salt);
        $buffer .= pack("I", $expiredTs);
        $buffer .= pack("S", count($extra));
        foreach ($extra as $key => $value) {
            $buffer .= pack("S", $key);
            $buffer .= self::packString($value);
        }
        return $buffer;
    }

    /**
     * 获取 aws region 对应的值
     * @param $region
     * @return mixed
     */
    public static function AWSRegionMapping($region)
    {
        $regionMap = [
            "US-EAST-1" => 0,
            "US-EAST-2" => 1,
            "US-WEST-1" => 2,
            "US-WEST-2" => 3,
            "EU-WEST-1" => 4,
            "EU-WEST-2" => 5,
            "EU-WEST-3" => 6,
            "EU-CENTRAL-1" => 7,
            "AP-SOUTHEAST-1" => 8,
            "AP-SOUTHEAST-2" => 9,
            "AP-NORTHEAST-1" => 10,
            "AP-NORTHEAST-2" => 11,
            "SA-EAST-1" => 12,
            "CA-CENTRAL-1" => 13,
            "AP-SOUTH-1" => 14,
            "CN-NORTH-1" => 15,
            "CN-NORTHWEST-1" => 16,
            "US-GOV-WEST-1" => 17,
        ];
        return isset($regionMap[strtoupper($region)]) ? $regionMap[strtoupper($region)] : false;
    }

    /**
     * 获取agora的appid
     * @return mixed
     */
    public static function GetAgoraAppID()
    {
        return $appID = Yii::$app->params['agora']['appID'];
    }

    /**
     * 获取aws存储位置配置
     * @return array
     */
    public static function GetStorageConfig()
    {
        $region = Yii::$app->params['agora']['cloud_recording']["region"];
        $bucket = Yii::$app->params['agora']['cloud_recording']["bucket"];
        $AwsAccessKey = Yii::$app->params['agora']['cloud_recording']["key"];
        $AwsSecretKy = Yii::$app->params['agora']['cloud_recording']["secret"];

        return $storageConfig = [
            "vendor" => 1,
            "region" => self::AWSRegionMapping($region),
            "bucket" => $bucket,
            "accessKey" => $AwsAccessKey,
            "secretKey" => $AwsSecretKy,
        ];
    }

    /**
     * Authorization 为 Basic authorization，生成方法请参考 RESTful API 认证
     * 参考链接:https://docs.agora.io/cn/faq/restful_authentication
     * @return string
     */
    public static function AuthorizationBasic()
    {
        $CustomerID = Yii::$app->params['agora']['CustomerID'];
        $CustomerCertificate = Yii::$app->params['agora']['CustomerCertificate'];
        return base64_encode("$CustomerID:$CustomerCertificate");
    }

    /**
     * agora cUrl公共请求方法
     * @param $url
     * @param $requestParam
     * @param $isPost
     * @param $action
     * @return mixed
     */
    public static function cUrl($url, $requestParam, $isPost = 1, $action = "POST")
    {
        $bear = self::AuthorizationBasic();
        if ((Yii::$app instanceof \yii\console\Application)) {
            //Service::log_time("url: {$url}, requestParam: " . json_encode($requestParam, JSON_UNESCAPED_UNICODE) . ", bear: {$bear}");
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, self::RESTFULAPI_HOST . $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json;charset=utf-8',
            'Authorization: Basic ' . $bear));
        if ($isPost) {//post 提交
            if ($action == "POST") {
                curl_setopt($ch, CURLOPT_POST, true);
            }
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $action);
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        if (!empty($requestParam)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestParam));
        }
//        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        $response = curl_exec($ch);
        curl_close($ch);
        if ((Yii::$app instanceof \yii\console\Application)) {
            Service::log_time("url: {$url}, requestParam: " . json_encode($requestParam, JSON_UNESCAPED_UNICODE) . ", bear: {$bear}" . "response: {$response}");
        }
        return $response;
    }

    public static function RtmCurl($url, $requestParam, $isPost = 1, $action = "POST", $trace_id = "")
    {
        $bear = self::AuthorizationBasic();
        if ((Yii::$app instanceof \yii\console\Application)) {
            //Service::log_time("url: {$url}, requestParam: " . json_encode($requestParam, JSON_UNESCAPED_UNICODE) . ", bear: {$bear}");
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, self::RESTFULRTMAPI_HOST . $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json;charset=utf-8', 'X-Request-ID: ' . $trace_id,
            'Authorization: Basic ' . $bear));
        if ($isPost) {//post 提交
            if ($action == "POST") {
                curl_setopt($ch, CURLOPT_POST, true);
            }
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $action);
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        if (!empty($requestParam)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestParam));
        }
//        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        // ----- BEGIN Note: 更新于2021-01-18 设置连接超时和请求超时时长
        // 连接时等待的秒数
        if (self::ConnectTimeOut > 0) {
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::ConnectTimeOut);
        }
        // 最大执行时间
        if (self::RequestTimeOut > 0) {
            curl_setopt($ch, CURLOPT_TIMEOUT, self::RequestTimeOut);
        }
        // ----- END
        $response = curl_exec($ch);
        if (!curl_errno($ch)) {
            $info = curl_getinfo($ch);
            $http_code = $info["http_code"];
            if ($http_code != 200) {
                Yii::error("RTM Restful Fail, http_code: {$http_code}, url: {$url}, Took: {$info['total_time']}, requestParam: " . json_encode($requestParam, JSON_UNESCAPED_UNICODE) . ", response: {$response}, X-Request-ID: $trace_id");
                if ((Yii::$app instanceof \yii\console\Application)) {
                    Service::log_time("RTM Restful Fail, http_code: {$http_code}, url: {$url}, Took: {$info['total_time']}, requestParam: " . json_encode($requestParam, JSON_UNESCAPED_UNICODE) . ", response: {$response}, X-Request-ID: $trace_id");
                }
            }
        } else {
            Yii::error("RTM Restful Fail, url: {$url}, requestParam: " . json_encode($requestParam, JSON_UNESCAPED_UNICODE) . ", response: {$response}, X-Request-ID: $trace_id curl_no:" . curl_errno($ch) . " curl_error:" . curl_error($ch));
            if ((Yii::$app instanceof \yii\console\Application)) {
                Service::log_time("RTM Restful Fail, url: {$url}, requestParam: " . json_encode($requestParam, JSON_UNESCAPED_UNICODE) . ", response: {$response}, X-Request-ID: $trace_id curl_no:" . curl_errno($ch) . " curl_error:" . curl_error($ch));
            }
            curl_close($ch);
            return false;
        }
        curl_close($ch);
        if ((Yii::$app instanceof \yii\console\Application)) {
            Service::log_time("url: {$url}, Took: {$info['total_time']}, requestParam: " . json_encode($requestParam, JSON_UNESCAPED_UNICODE) . ", response: {$response}, X-Request-ID: $trace_id");
        }
        return $response;
    }

    /**
     * 获取云端录制资源
     * @param $channel_id
     * @param $uid
     * @return bool/string
     */
    public static function GetCloudRecresourceID($channel_id, $uid)
    {
        $appID = self::GetAgoraAppID();
        $url = "/apps/{$appID}/cloud_recording/acquire";
        $requestParam = [
            "cname" => $channel_id,
            "uid" => $uid,
//            "cloud_recording" => "acquire",
            "clientRequest" => (object)[],
        ];
        $response = self::cUrl($url, $requestParam);
        $res = json_decode($response, true);
        if (isset($res["code"])) {
            return false;
        }
        if (!isset($res["resourceId"])) {
            return false;
        }
        return $res["resourceId"];

    }

    /**
     * 设置视频布局
     * @param int $canvasWidth
     * @param int $canvasHeight
     * @return array
     */
    public static function SetVideoLayout($canvasWidth = 480, $canvasHeight = 640)
    {
        /**
         *  float viewWidth = 0.3f;
         * float viewHEdge = 0.025f;
         * float viewHeight = viewWidth * (canvasWidth/canvasHeight) + 0.1;
         * float viewVEdge = viewHEdge * (canvasWidth/canvasHeight);
         *
         * for (size_t i=1; i<m_peers.size(); i++) {
         *
         * regionList[i].uid = m_peers[i];
         *
         *
         * regionList[i].x = 1- viewWidth - viewHEdge;
         * regionList[i].y = i * (viewHeight + viewVEdge) - 0.22;
         * regionList[i].width = viewWidth;
         * regionList[i].height = viewHeight;
         * regionList[i].zOrder = 0;
         * regionList[i].alpha = static_cast<double>(i + 1);
         * regionList[i].renderMode = 0;
         * }
         */
        $rightEdge = 0.15;
        if ($canvasWidth > $canvasHeight) {
            return self::SetLandscapeLayout($canvasWidth, $canvasHeight);
        }
        $ratio = $canvasHeight / $canvasWidth;
        if (1.7 < $ratio && $ratio < 1.8) { //16:9的尺寸特设
            $rightEdge = round($rightEdge / 2, 2);
        }
        $viewWidth = 0.2;
        $viewHEdge = 0.025;
        $viewHeight = round($viewWidth * ($canvasWidth / $canvasHeight) + 0.1, 2);
        $viewVEdge = round($viewHEdge * ($canvasWidth / $canvasHeight), 2);
        $regionList = [];

        $hostLayout = [
            "x_axis" => 0.0,
            "y_axis" => 0.0,
            "width" => 1.0,
            "height" => 1.0,
        ];
        $regionList[] = $hostLayout;
        for ($i = 1; $i < 4; $i++) {//240 * 320
            $tmp = [];
            $tmp["x_axis"] = round(1 - $viewWidth - $viewHEdge - $rightEdge, 2);
            $tmp["y_axis"] = round($i * ($viewHeight + $viewVEdge) - 0.1, 2);
            if ($tmp["y_axis"] > 1) {
                break;
            }
            $tmp["width"] = $viewWidth;
            $tmp["height"] = $viewHeight;

            if ($tmp["y_axis"] + $tmp["height"] > 1) {
                continue;
            }
            if ($tmp["x_axis"] + $tmp["width"] > 1) {
                continue;
            }
            $regionList[] = $tmp;
//            $regionList[$i]["x_axis"] = round(1 - $viewWidth - $viewHEdge, 1);
//            $regionList[$i]["y_axis"] = round($i * ($viewHeight + $viewVEdge) - 0.22, 1);
//            $regionList[$i]["width"] = $viewWidth;
//            $regionList[$i]["height"] = $viewHeight;
//            $regionList[$i]["render_mode"] = 0;  //画面显示模式
        }
        return $regionList;
    }

    /**
     * 横屏
     * @param int $canvasWidth
     * @param int $canvasHeight
     * @return array
     */
    public static function SetLandscapeLayout($canvasWidth = 640, $canvasHeight = 480)
    {
        $viewWidth = 0.3;
        $viewHEdge = 0.025;
        $viewHeight = round($viewWidth * ($canvasHeight / $canvasWidth) + 0.1, 1);
        $viewVEdge = round($viewHEdge * ($canvasHeight / $canvasWidth), 1);
        $regionList = [];

        $hostLayout = [
            "x_axis" => 0.0,
            "y_axis" => 0.0,
            "width" => 1.0,
            "height" => 1.0,
        ];
        $regionList[] = $hostLayout;
        for ($i = 1; $i < 4; $i++) {
            $tmp = [];
            $tmp["x_axis"] = round(1 - $viewWidth - $viewHEdge, 1);
            $tmp["y_axis"] = round($i * ($viewHeight + $viewVEdge) - 0.22, 1);
            if ($tmp["y_axis"] > 1) {
                break;
            }
            $tmp["width"] = $viewWidth;
            $tmp["height"] = $viewHeight;
            if ($tmp["y_axis"] + $tmp["height"] > 1) {
                continue;
            }
            if ($tmp["x_axis"] + $tmp["width"] > 1) {
                continue;
            }
            $regionList[] = $tmp;
        }
        return $regionList;
    }

    /**
     * 设置pk layout
     * @param int $canvasWidth
     * @param int $canvasHeight
     * @return array
     */
    public static function SetPkVideoLayout($canvasWidth = 480, $canvasHeight = 640)
    {
        $viewWidth = 0.3;
        $viewHEdge = 0.025;
        $viewHeight = round($viewWidth * ($canvasWidth / $canvasHeight) + 0.1, 1);
        $viewVEdge = round($viewHEdge * ($canvasWidth / $canvasHeight), 1);
        $regionList = [];

        $hostLayout = [
            "x_axis" => 0.001,
            "y_axis" => 0.001,
            "width" => 0.5,
            "height" => 0.999,
        ];
        $joinPkChannelLayout = [
            "x_axis" => 0.5,
            "y_axis" => 0.001,
            "width" => 0.5,
            "height" => 0.999,
        ];
        $regionList[] = $hostLayout;
        $regionList[] = $joinPkChannelLayout;
        return $regionList;
    }

    public static function StartCloudRec($channel_id, $uid, $rec_config = [], $video = Channel::mode_video)
    {
        $appID = self::GetAgoraAppID();
        $resourceId = Agora::GetCloudRecresourceID($channel_id, $uid);
        $url = "/apps/{$appID}/cloud_recording/resourceid/{$resourceId}/mode/mix/start";
        $requestParam = [];
        $clientRequest = [];
        $recordingConfig = [];
        $recordingConfig["channelType"] = 1;
        // 录制流中途断开多少秒，将停止录制
//        $recordingConfig["maxIdleTime"] = 30;
        $recordingConfig["maxIdleTime"] = 300;

        $clientRequest["token"] = Agora::generateMediaChannelKey($channel_id, $uid);
        //存放位置
        $clientRequest["storageConfig"] = self::GetStorageConfig();

        $height = isset($rec_config["height"]) ? $rec_config["height"] : 640;
        $bitrate = isset($rec_config["bitrate"]) ? $rec_config["bitrate"] : 1000;
        $fps = isset($rec_config["fps"]) ? $rec_config["fps"] : 30;
        $width = isset($rec_config["width"]) ? $rec_config["width"] : 480;
        $is_game = isset($rec_config["is_game"]) ? (int)$rec_config["is_game"] : 0;
        //视频转码的详细设置
        $transcodingConfig = [
            "height" => $height,
            "bitrate" => $bitrate,
            "fps" => $fps,
            "width" => $width,
//            "mixedVideoLayout" => 3, //设置视频合流布局
            "backgroundColor" => "#000000", //背景颜色
//            "mixedVideoLayout" =>0,
//            "maxResolutionUid" =>0,`
//            'layoutConfig' => self::SetVideoLayout($width, $height),

        ];
        if ($video == Channel::mode_4_seat || $video == Channel::mode_6a_seat || $video == Channel::mode_6b_seat || $video == Channel::mode_9_seat || $video == Channel::mode_1v1_seat) {
            $transcodingConfig["height"] = 640;
            $transcodingConfig["width"] = 480;
            $transcodingConfig["mixedVideoLayout"] = 1;//预设布局
            $transcodingConfig['bitrate'] = 1000;
        } else {
            $transcodingConfig["mixedVideoLayout"] = 3;//设置视频合流布局
            $transcodingConfig["layoutConfig"] = self::SetVideoLayout($width, $height);
        }


        $recordingConfig["transcodingConfig"] = $transcodingConfig;
        $clientRequest["recordingConfig"] = $recordingConfig;
        //合流布局画面设置
//        $clientRequest["layoutConfig"] = self::SetVideoLayout();

        $requestParam["cname"] = $channel_id;
        $requestParam["uid"] = $uid;
        $requestParam["clientRequest"] = $clientRequest;
        var_dump("start rec video: {$video} request param: " . json_encode($requestParam));
        $response = self::cUrl($url, $requestParam);
        //游戏直播截屏
        if ($is_game == 1) {
            self::RecScreenshots($channel_id, Agora::ScreenShotUid, $rec_config, $video);
        }
        /*
         * {"resourceId":"S8CRETGz0EoSDiyMGc0g_f497RG22z8L2u9Ylkj0O51kMnqChA0FeeNESp43DI8mMKQUVvOdn_9PGlY4RxGgflIlsck3TSeu7GMmymfaPSvvwjYDM6z28gmpSeicv-oXxVASQe8CRJPxM1pE7zfytoB7wpyINdGr82Vp4JtX
            vahrh1n8VHixwFU0qZmiIRHhHQ_0OIykJafk4iqK82F9c9QR7meLpuuc4M0bERorUlqgwkbyHU781ZkYXst_feXQP-SDqBnaggmxIfGAKAMzzw","sid":"","code":7}
         */
        /*
         * {
                "resourceId": "S8CRETGz0EoSDiyMGc0g_f497RG22z8L2u9Ylkj0O51kMnqChA0FeeNESp43DI8m0KlVTHTsX-h8wORPDbBu5UVjJIzQUm-Rc18oJHKj6tkqEP8KP59f1XFZv4Uod4RoUM90RfkPS3-XzHGet34Vyzb2BYrrbEOk9kvKukCMOd0TiQfdyp_xzlLdLP1M6_S2GB7uhzihnzU-1UeiOmdG7eeQjIh2w63DSDqp2EyOunqzNC64xuupz-jk8oeapOloSf94F8Q69xP5JyUZiwLscg",
                "sid": "6d70a0bce548035ddea0c0a82313642a"
            }
         */
        return ["response" => $response, "request" => $requestParam];

    }

    public static function RecScreenshots($channel_id, $uid, $rec_config = [], $video = Channel::mode_video)
    {
        $appID = self::GetAgoraAppID();
        $resourceId = Agora::GetCloudRecresourceID($channel_id, $uid);
        $url = "/apps/{$appID}/cloud_recording/resourceid/{$resourceId}/mode/individual/start";
        $requestParam = [];
        $clientRequest = [];
        $recordingConfig = [];
        $recordingConfig["channelType"] = 1;
        $recordingConfig["maxIdleTime"] = 30;

        $clientRequest["token"] = Agora::generateMediaChannelKey($channel_id, $uid);
        //存放位置
        $storageConfig = self::GetStorageConfig();
        $storageConfig["fileNamePrefix"] = ["gamingscreenshots", $channel_id];
        $clientRequest["storageConfig"] = $storageConfig;
        $recordingConfig["channelType"] = 1;
        $recordingConfig["subscribeUidGroup"] = 0;
        $clientRequest["recordingConfig"] = $recordingConfig;
        $clientRequest["snapshotConfig"] = [
            "captureInterval" => Agora::captureInterval,//截图间隔
            "fileType" => ["jpg"],
        ];
        $requestParam["cname"] = $channel_id;
        $requestParam["uid"] = $uid;
        $requestParam["clientRequest"] = $clientRequest;
        Service::log_time("start rec screenshots image, resourceId: $resourceId request param: " . json_encode($requestParam));
        $response = self::cUrl($url, $requestParam);
        /*
         * {"resourceId":"S8CRETGz0EoSDiyMGc0g_f497RG22z8L2u9Ylkj0O51kMnqChA0FeeNESp43DI8mMKQUVvOdn_9PGlY4RxGgflIlsck3TSeu7GMmymfaPSvvwjYDM6z28gmpSeicv-oXxVASQe8CRJPxM1pE7zfytoB7wpyINdGr82Vp4JtX
            vahrh1n8VHixwFU0qZmiIRHhHQ_0OIykJafk4iqK82F9c9QR7meLpuuc4M0bERorUlqgwkbyHU781ZkYXst_feXQP-SDqBnaggmxIfGAKAMzzw","sid":"","code":7}
         */
        /*
         * {
                "resourceId": "S8CRETGz0EoSDiyMGc0g_f497RG22z8L2u9Ylkj0O51kMnqChA0FeeNESp43DI8m0KlVTHTsX-h8wORPDbBu5UVjJIzQUm-Rc18oJHKj6tkqEP8KP59f1XFZv4Uod4RoUM90RfkPS3-XzHGet34Vyzb2BYrrbEOk9kvKukCMOd0TiQfdyp_xzlLdLP1M6_S2GB7uhzihnzU-1UeiOmdG7eeQjIh2w63DSDqp2EyOunqzNC64xuupz-jk8oeapOloSf94F8Q69xP5JyUZiwLscg",
                "sid": "6d70a0bce548035ddea0c0a82313642a"
            }
         */
        return $response;

    }

    public static function QueryCloudRec($resourceId, $sid)
    {
        $appID = self::GetAgoraAppID();
//        $resourceId = Agora::GetCloudRecresourceID($channel_id, $uid);
        $url = "/apps/{$appID}/cloud_recording/resourceid/{$resourceId}/sid/{$sid}/mode/mix/query";
        var_dump($url);
        $response = self::cUrl($url, [], 0);
        return $response;
    }

    public static function StopCloudRec($channel_id, $uid, $sid, $resourceId)
    {
        $appID = self::GetAgoraAppID();
//        $resourceId = Agora::GetCloudRecresourceID($channel_id, $uid);
        $url = "/apps/{$appID}/cloud_recording/resourceid/{$resourceId}/sid/{$sid}/mode/mix/stop";
        $requestParam["cname"] = $channel_id;
        $requestParam["uid"] = $uid;
        $requestParam["clientRequest"] = (object)[];
        var_dump($url, json_encode($requestParam));
        $response = self::cUrl($url, $requestParam);
        return $response;
    }

    /**
     * 请求agora更新云录制视频布局
     * @param $channel_id
     * @param $uid
     * @param $sid
     * @param $resourceId
     * @param $transcodingConfig
     * @return mixed
     */
    public static function updateLayoutCloudRec($channel_id, $uid, $sid, $resourceId, $transcodingConfig)
    {
        $appID = self::GetAgoraAppID();
        $url = "/apps/{$appID}/cloud_recording/resourceid/{$resourceId}/sid/{$sid}/mode/mix/updateLayout";

        $requestParam["cname"] = $channel_id;
        $requestParam["uid"] = $uid;
        $requestParam["clientRequest"] = $transcodingConfig;
        $response = self::cUrl($url, $requestParam);
        return $response;
    }

    /**
     * 给已经录制的频道设置布局
     * @param $channel_id
     * @param string $layout
     * @return bool
     */
    public static function SetRecLayout($channel_id, $layout = ChannelCloudRecording::recRegularLayout)
    {
        $model = ChannelCloudRecording::find()->where(["channel_id" => $channel_id])->one();
        if (empty($model)) {
            return false;
        }
        $uid = self::RecUid;
        $sid = $model->sid;
        $resourceId = $model->resourceId;
        $transcodingConfig = [
//            "height" => 640,
//            "bitrate" => 1000,
//            "fps" => 30,
//            "width" => 480,
//            "backgroundColor" => "#000000", //背景颜色
            "mixedVideoLayout" => 3, //设置视频合流布局
            'layoutConfig' => self::SetVideoLayout(),

        ];
        switch ($layout) {
            case ChannelCloudRecording::recRegularLayout:
                $transcodingConfig["layoutConfig"] = self::SetVideoLayout();
                break;
            case ChannelCloudRecording::recPkLayout:
                $transcodingConfig["layoutConfig"] = self::SetPkVideoLayout();
                break;
            default:
                break;
        }
        return self::updateLayoutCloudRec($channel_id, $uid, $sid, $resourceId, $transcodingConfig);
    }


    // --------agora 云录制测试 start--------------
    public function actionTestStart($channel_id, $uid)
    {
        $res = Agora::StartCloudRec($channel_id, $uid);
        var_dump($res);
    }

    public function actionTestToken($channel_id, $uid)
    {
        $token = Agora::generateMediaChannelKey($channel_id, $uid);
        var_dump("token:" . $token);
    }

    public function actionTestQuery($channel_id, $uid)
    {
        $res = Agora::QueryCloudRec($channel_id, $uid);
        var_dump($res);
    }

    public function actionTestStop($channel_id, $uid)
    {
        $RecodingModel = ChannelCloudRecording::find()->where(["channel_id" => $channel_id])->one();
        if (!$RecodingModel) {
            return false;
        }
        $sid = $RecodingModel->sid;
        $res = Agora::StopCloudRec($channel_id, $uid, $sid);
        var_dump($res);
    }
    // --------agora 云录制测试 end--------------


    // --------java jar rtm信令 start--------------

    /**
     * 使用jar往频道推送消息
     * @param $channel_id
     * @param $op
     * @param $pushData
     * @return bool
     */
    public static function JarPushMessage($channel_id, $op, $pushData)
    {
        $data = [
            "op" => $op,
            "client_message_id" => (microtime(true) * 1000) . Service::random_hash(7),
            "body" => $pushData,
        ];
        $trace_id = isset($pushData["gift_trace_id"]) ? $pushData["gift_trace_id"] : Service::create_guid();
        Yii::info("JarPushMessage request param,trace_id: $trace_id, channel_id: {$channel_id}, data: " . json_encode($data, JSON_UNESCAPED_UNICODE), self::$jarLog);
//        if($op != self::GiftOperate){ //礼物的仍然走jar包
        return Agora::RtmChannelMessage($channel_id, $op, $data, $trace_id);
//        }
        $param = [
            "channel" => $channel_id,
            "data" => json_encode($data),
        ];
        try {
            $chat_service = Yii::$app->params['chat_service'];
            $action = "/rtm/sendMsg";
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $chat_service . $action);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json; charset=utf-8'));
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 5000);//设置5s超时
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
            curl_setopt($ch, CURLOPT_HEADER, FALSE);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($param));
            $response = curl_exec($ch);
            curl_close($ch);
            return true;
        } catch (\Exception $e) {
            if (Yii::$app instanceof \yii\console\Application) {
                Service::log_time("jar sendMsg error: channel_id: $channel_id, op: $op, pushData: " . json_encode($pushData) . " error message:" . json_encode($e->getMessage()));
            }
            Yii::error("jar sendMsg error: channel_id: $channel_id, op: $op, pushData: " . json_encode($pushData) . " error message:" . json_encode($e->getMessage()));
            return false;
        }
    }

    public static function GetJarChannels($chat_service, $param = [])
    {
        try {
            $action = "/rtm/list";
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $chat_service . $action);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json; charset=utf-8'));
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 5000);//设置5s超时
            curl_setopt($ch, CURLOPT_POST, 0);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
            curl_setopt($ch, CURLOPT_HEADER, FALSE);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
            if (!empty($param)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($param));
            }
            $response = curl_exec($ch);
            curl_close($ch);
            return $response;
        } catch (\Exception $e) {
            Yii::error("GetJarChannels error message:" . json_encode($e->getMessage()), Agora::$jarLog);
            return "";
        }
    }

    /**
     * 使用rtm推送频道消息
     * @param $channel_id
     * @param $op
     * @param $pushData
     * @param $trace_id
     * @return bool
     */
    public static function RtmChannelMessage($channel_id, $op, $pushData, $trace_id = "")
    {
        $appid = self::GetAgoraAppID();
        $systemUser = self::RtmPushMessageUser;
        $request = [
            "channel_name" => $channel_id,
            "payload" => json_encode($pushData),
        ];
        $url = "/project/$appid/rtm/users/$systemUser/channel_messages";
        $response = self::RtmCurl($url, $request, $trace_id, "POST", $trace_id);
        $result = json_decode($response, true);
//        if(empty($result)){//重试一次
//            $response = self::RtmCurl($url, $request);
//            $result = json_decode($response, true);
//        }
        if (empty($result)) {
            Yii::error("RTM Restful Fail trace_id: $trace_id, jar sendMsg error: channel_id: $channel_id, op: $op, pushData: " . json_encode($pushData) . " error message: $response");
            return false;
        }
        if (isset($result["result"]) && $result["result"] == "success") {
            return true;
        }
        Yii::error("RTM Restful Fail trace_id: $trace_id, jar sendMsg error: channel_id: $channel_id, op: $op, pushData: " . json_encode($pushData) . " error message: $response");
        return false;
    }

    public static function StartSignalJar($user_id, $channel_id, $arg3 = '1', $action = "/rtm/startSignal")
    {
        try {
            $chat_service = Yii::$app->params['chat_service'];
//            $account = (int)(microtime(true) * 1000);
            $account = $user_id . "_admin" . rand(0, 99);
//        $agora_token = Agora::generateSignalChannelKey($appID, $appCertificate, $account, 3600 * 24);
            if ($action == "/rtm/restartSignal") {//重启的时候从redis重新获取account
                $n_xdg = BroadcastRedis::getbroadcastInfo($channel_id, "n_xdg");
                if (!empty($n_xdg)) {
                    $account = $n_xdg;
                }
            }
            if ($arg3 == '2') {
                $rtm_token = Agora::GenerateQuizCommentRtmTokenConsole((string)$account);
            } else {
                $rtm_token = Agora::GenerateRtmTokenConsole((string)$account);
            }
//            $action = "/rtm/startSignal";
            $param = [
                "account" => $user_id,
                "channel" => $channel_id,
                "token" => $rtm_token,
                "type" => $arg3,
                "signalAccount" => $account,
            ];
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $chat_service . $action);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json; charset=utf-8'));
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 2000);//设置5s超时
            curl_setopt($ch, CURLOPT_TIMEOUT, 2);//设置cURL允许执行的最长秒数
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
            curl_setopt($ch, CURLOPT_HEADER, FALSE);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($param));
            $response = curl_exec($ch);
            $http_code = "null";
            $total_time = "null";
            if (!curl_errno($ch)) {
                $info = curl_getinfo($ch);
                $http_code = $info["http_code"];
                $total_time = $info['total_time'];
            }
            Yii::info("start/restart jar request param, channel_id: {$channel_id}, action: {$action} jar_http: $http_code, Took: $total_time  param: " . json_encode($param, JSON_UNESCAPED_UNICODE) . " response: {$response}", self::$jarLog);
            curl_close($ch);
            if ($http_code != 200) {
                return false;
            }
            $data = json_decode($response, true);
            if (empty($data)) {
                return false;
            }
            if (isset($data["code"]) && $data["code"] != 0) {
                return false;
            }
            $name = isset($data["data"]["name"]) ? $data["data"]["name"] : "";
            $updateDataToRedis = [];
            if (!empty($name)) {
                $updateDataToRedis["api-jar"] = $name;
            }
            $updateDataToRedis["n_xdg"] = $account; //jar 登录用户
            BroadcastRedis::broadcastInfo($channel_id, $updateDataToRedis);
            return $param;
        } catch (\Exception $exception) {
            return false;
        }
    }

    public static function RestartSignalJar($user_id, $channel_id, $arg3 = '1')
    {
        $action = "/rtm/restartSignal";
        return Agora::StartSignalJar($user_id, $channel_id, $arg3, $action);
    }

    public static function StopSignalJar($channel_id)
    {
        try {
            $chat_service = Yii::$app->params['chat_service'];
//            $account = (int)(microtime(true) * 1000);
//            $rtm_token = Agora::GenerateQuizCommentRtmTokenConsole((string)$account);
            $action = "/rtm/stopSignal";
            $param = [
                "channel" => $channel_id,
            ];
            Yii::info("stop jar request param, channel_id: {$channel_id}, action: {$action} param: " . json_encode($param, JSON_UNESCAPED_UNICODE), self::$jarLog);
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $chat_service . $action);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json; charset=utf-8'));
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 5000);//设置5s超时
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
            curl_setopt($ch, CURLOPT_HEADER, FALSE);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($param));
            $response = curl_exec($ch);
            curl_close($ch);
            return true;
        } catch (\Exception $exception) {
            return false;
        }
    }

    public static function StopHQSignalJar($channel_id)
    {
        try {
            $chat_service = Yii::$app->params['chat_service'];
//            $account = (int)(microtime(true) * 1000);
//            $rtm_token = Agora::GenerateQuizCommentRtmTokenConsole((string)$account);
            $action = "/rtm/stopHQSignal";
            $param = [
                "channel" => $channel_id,
            ];
            Yii::info("stop jar request param, channel_id: {$channel_id}, action: {$action} param: " . json_encode($param, JSON_UNESCAPED_UNICODE), self::$jarLog);
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $chat_service . $action);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json; charset=utf-8'));
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 5000);//设置5s超时
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
            curl_setopt($ch, CURLOPT_HEADER, FALSE);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($param));
            $response = curl_exec($ch);
            curl_close($ch);
            return true;
        } catch (\Exception $exception) {
            return false;
        }
    }

    // --------java jar rtm信令 end----------------

    //------------java pk jar start----------------------
//    {
//    "players":[
//    {
//    "channel":"pk",
//    "host":"zhangsan", uid
//    "name":"zhoujieluan",
//    "token":"1134233"
//    }
//    ],
//    "pk_id":"",
//    "content":""
//    }
    public static function JoinPk($pk_id, $players, $content = "")
    {
        $param = self::PkJarParam($pk_id, $players, $content);
        $action = self::$pk_join;
        return self::PostPkJarApi($action, $param);
    }

    public static function StartPk($pk_id, $players, $content = "")
    {
        $param = self::PkJarParam($pk_id, $players, $content);
        $action = self::$pk_start;
        return self::PostPkJarApi($action, $param);
    }

    public static function EndPk($pk_id, $players, $content = "")
    {
        $param = self::PkJarParam($pk_id, $players, $content);
        $action = self::$pk_end;
        return self::PostPkJarApi($action, $param);
    }

    public static function ExitPk($pk_id, $players, $content = "")
    {
        $param = self::PkJarParam($pk_id, $players, $content);
        $action = self::$pk_exit;
        return self::PostPkJarApi($action, $param);
    }

    private static function PkJarParam($pk_id, $players, $content = "")
    {
        $param = [];
        foreach ($players as $player) {
            $player["name"] = $player["host"] . "_admin02" . rand(0, 9);
            $player["token"] = Agora::GenerateRtmTokenConsole((string)$player["name"]);
            $param["players"][] = $player;
        }
        $param["pk_id"] = $pk_id;
        $param["content"] = $content;
        return $param;
    }

    public static function PostPkJarApi($action, $param)
    {
        $url = isset(Yii::$app->params["pk_jar"]) ? Yii::$app->params["pk_jar"] : "http://localhost:8099";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url . $action);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json; charset=utf-8'));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 5000);//设置5s超时
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($param));
        $response = curl_exec($ch);
        curl_close($ch);
        Yii::info("PostPkJarApi request action: $action, param :" . json_encode($param) . " response: " . json_encode($response), self::$jarLog);
        return $response;
    }

    //------------java pk jar end------------------------


    public static function GiftEvent($channel_id, $user_id, $gift_id, $repeat, $gift_type, $giftInfo = [], $userInfo = NULL, $host_user = NULL, $evnetType = self::RegularGiftEvent, $extraData = [])
    {
        $gift_trace_id = isset($extraData['gift_trace_id']) ? $extraData['gift_trace_id'] : "";//gift_trace_id
        if (empty($giftInfo)) {
            $giftInfo = GiftRedis::getGiftInfo($gift_id, $gift_type);
            if (empty($giftInfo)) {
                return false;
            }
        }
        if (empty($userInfo)) {
            $userInfo = User::find()->where(['guid' => $user_id])->one();
            if (empty($userInfo)) {
                return false;
            }
        }
        self::GiftRegularEvent($channel_id, $user_id, $gift_id, $repeat, $gift_type, $giftInfo, $userInfo, $host_user, $evnetType, $gift_trace_id);
        //send premium gift event
//        $premium = $giftInfo["premium"]? $giftInfo["premium"]: Gift::premium_no;
//        if($premium == Gift::premium_yes){
        //self::GiftPremiumEvent($channel_id, $user_id, $gift_id, $repeat, $gift_type, $giftInfo, $userInfo, $host_user, $evnetType, $gift_trace_id);
//        }
    }

    public static function GiftRegularEvent($channel_id, $user_id, $gift_id, $repeat, $gift_type, $giftInfo = [], $userInfo = NULL, $host_user = NULL, $evnetType = self::RegularGiftEvent, $gift_trace_id = "")
    {
        $pushData = [];
        $op = self::GiftOperate;
        if (empty($giftInfo)) {
            $giftInfo = GiftRedis::getGiftInfo($gift_id, $gift_type);
            if (empty($giftInfo)) {
                return false;
            }
        }
        if (empty($userInfo)) {
            $userInfo = User::find()->where(['guid' => $user_id])->one();
            if (empty($userInfo)) {
                return false;
            }
        }
        // 发送新礼物op为602
        if (isset($giftInfo['newbie_gift']) && $giftInfo['newbie_gift'] == 1) {
            $op = self::NewbieGiftOperate;
        }
        $cost_coin = isset($giftInfo['coin']) ? (int)$giftInfo['coin'] : 0;
        $diamond = isset($giftInfo["diamond"]) ? $giftInfo["diamond"] : 0;
        $index = $userInfo->id % self::$chat_color_count;
        $pushData["font_color"] = self::$chat_color[$index];
        $pushData["rgb"] = self::$gift_color;
        $pushData["gift_type"] = $gift_id;
        $pushData["image"] = isset($giftInfo["image"]) ? $giftInfo["image"] : "";
        $pushData["name"] = isset($giftInfo["name"]) ? $giftInfo["name"] : "";
        $pushData["repeat"] = $repeat;
        $pushData["receive_diamond"] = $repeat * $diamond;
//        $pushData["diamonds"] = 0;
//        $pushData["red_diamond"] = 0;
//        $pushData["vote"] = 0;
        $pushData["type"] = isset($giftInfo["type"]) ? (int)$giftInfo["type"] : 1;
        switch ($evnetType) {
            case self::PkGiftEvent:
                $pushData["content"] = "sent {$pushData["name"]} x{$repeat} to $host_user->username";
                break;
            default:
                $pushData["content"] = "sent {$pushData["name"]} x{$repeat}";
                break;
        }


        //用户数据
        $pushData["nickname"] = isset($userInfo->nickname) ? $userInfo->nickname : "name";
        $pushData["username"] = isset($userInfo->username) ? $userInfo->username : "";
        //获取用户point
        $point = $userInfo->point ? $userInfo->point : 0;

        $pushData["avatar"] = isset($userInfo->avatar) ? Service::avatar_small($userInfo->avatar) : "";
        $pushData["user_id"] = $user_id;
        $pushData["guid"] = $user_id;
        $pushData["level"] = Service::userLevel($point);
        $pushData["channel_id"] = $channel_id;
        $pushData["coins"] = $cost_coin * $repeat;
        $pushData["gift_trace_id"] = $gift_trace_id;
        // ----- BEGIN Note: 更新于2021-01-21 发送礼物增加字段vip_badge
        // 获取用户会员等级
        $vip_level = UserRedis::getUserInfo($user_id, 'vip_level');
        $vip_res = [];
        if ($vip_level)
            $vip_res = VipResource::getVipResource($vip_level, '/Agora/GiftRegularEvent');

        $pushData["vip_badge"] = ($vip_res && isset($vip_res["badge"])) ? $vip_res["badge"] : "";
        // ----- END
        $premium = $giftInfo["premium"] ? $giftInfo["premium"] : Gift::premium_no;
        if ($premium == Gift::premium_yes) {
            $pushData["force_play_to_the_end"] = 1;
        }
        return self::JarPushMessage($channel_id, $op, $pushData);
    }

    /**
     * Premium 全局礼物
     * @param $channel_id
     * @param $user_id
     * @param $gift_id
     * @param $repeat
     * @param $gift_type
     * @param array $giftInfo
     * @param null $userInfo
     * @param null $host_user
     * @param int $evnetType
     * @param string $gift_trace_id
     * @return bool
     */
    public static function GiftPremiumEvent($channel_id, $user_id, $gift_id, $repeat, $gift_type, $giftInfo = [], $userInfo = NULL, $host_user = NULL, $evnetType = self::RegularGiftEvent, $gift_trace_id = "")
    {
        $pushData = [];
        $op = self::GiftPremiumOperate;
        if (empty($giftInfo)) {
            $giftInfo = GiftRedis::getGiftInfo($gift_id, $gift_type);
            if (empty($giftInfo)) {
                return false;
            }
        }
        if (empty($userInfo)) {
            $userInfo = User::find()->where(['guid' => $user_id])->one();
            if (empty($userInfo)) {
                return false;
            }
        }
        if (empty($host_user) || empty($host_user->username)) {
            return false;
        }
        $cost_coin = isset($giftInfo['coin']) ? (int)$giftInfo['coin'] : 0;
        $diamond = isset($giftInfo["diamond"]) ? $giftInfo["diamond"] : 0;
        $index = $userInfo->id % self::$chat_color_count;
        $pushData["font_color"] = self::$chat_color[$index];
        $pushData["rgb"] = self::$gift_color;
        $pushData["gift_type"] = $gift_id;
        $pushData["image"] = isset($giftInfo["image"]) ? $giftInfo["image"] : "";
        $pushData["name"] = isset($giftInfo["name"]) ? $giftInfo["name"] : "";
        $pushData["repeat"] = $repeat;
//        $pushData["receive_diamond"] = $repeat * $diamond;
        $pushData["type"] = 5;
        //用户数据
        $pushData["nickname"] = isset($userInfo->nickname) ? $userInfo->nickname : "name";
        $pushData["username"] = isset($userInfo->username) ? $userInfo->username : "";
        //获取用户point
        $point = $userInfo->point - $userInfo->cost_point;
        $pushData["avatar"] = isset($userInfo->avatar) ? Service::avatar_small($userInfo->avatar) : "";
        $pushData["user_id"] = $user_id;
        $pushData["guid"] = $user_id;
        $pushData["level"] = Service::userLevel($point);
        $pushData["channel_id"] = $channel_id;
        $pushData["coins"] = $cost_coin * $repeat;

        $pushData["content"] = "{$host_user->username} gave {$pushData["name"]} to {$pushData["username"]}";
        $pushData["gift_trace_id"] = $gift_trace_id;

        $channelList = BroadcastRedis::GetAllLivestreamsView(1, 2000);
        foreach ($channelList as $value) {
            //当前频道不处理
            if ($value == $channel_id) {
                continue;
            }
            self::JarPushMessage($channel_id, $op, $pushData);
        }
    }

    /**
     * 礼物动画事件改为jar发送, 版本判断
     * @param $device_type
     * @param $version
     * @return bool
     */
    public static function JarGiftVersion($device_type, $version)
    {
        if ($device_type == "android" && $version >= 590) {
            return true;
        }
        if ($device_type == "ios" && $version > 618) {
            return true;
        }
        if ($device_type == "web") {
            return true;
        }
        return false;
    }

    /**
     * 更新audio直播座位信息
     * @param $channel_id
     * @param $pushData
     */
    public static function JarUpdateSlots($channel_id, $pushData)
    {
        Agora::JarPushMessage($channel_id, Agora::AudioShareDiamond, $pushData);
    }

    /**
     * RTMP 推流
     * @param $channel_id
     * @param $user_id
     * @param $uid
     * @return bool
     */
    public static function StartRtmp($channel_id, $user_id, $uid = self::RtmpUid)
    {
        $appID = self::GetAgoraAppID();
        $uri = "/projects/{$appID}/cloud-player/players";
        $param = [];
        $param["uid"] = self::EncodeRtmpUid($uid);
        $param["channelName"] = $channel_id;
        $param["token"] = self::generateMediaChannelKey($param["channelName"], $param["uid"]);
        $param["idleTimeout"] = 300;
        $param["streamUrl"] = self::GetStreamUrl($channel_id, $user_id);
        $palyer["player"] = $param;
        $response = self::cUrl($uri, $palyer);
        $data = json_decode($response, true);
        Yii::info("start rtmp channel_id: {$channel_id}, user_id: {$user_id}, response: {$response}", self::$jarLog);
        if (empty($data)) {
            return false;
        }
        if (!empty($data["player"]["id"])) {
            BroadcastRedis::broadcastInfo($channel_id, ["rtmp_player_id" => $data["player"]["id"]]);
            return $data["player"]["id"];
        }
        return false;
    }

    public static function EncodeRtmpUid($uid = self::RtmpUid)
    {
//        return self::RtmpUid;
        $uid = (int)$uid;
        return self::RtmpVirtualUidRate + $uid;
    }

    public static function DecodeRtmpUid($virtualUid)
    {
//        return $virtualUid;
        if ($virtualUid < self::RtmpVirtualUidRate) {
            return $virtualUid;
        }
        return $virtualUid - self::RtmpVirtualUidRate;
    }

    public static function GetStreamUrl($channel_id, $user_id)
    {
        $rtmp_url = isset(Yii::$app->params["rtmp_url"]) ? Yii::$app->params["rtmp_url"] : "rtmp://dev-rtmp.kumuapi.com:1935/live/";
        return $rtmp_url . $user_id;
    }

    public static function ListRtmpChannel($channel_id = "", $pageToken = "")
    {
        $appId = self::GetAgoraAppID();
        $uri = "/projects/{$appId}/cloud-player/players?";
        $query = $uri . "filter={filter}&pageSize={pageSize}&pageToken={pageToken}";
        $filter = [];
        if (!empty($channel_id)) {
            $filter["filter"] = "channelName eq {$channel_id}";
        }
        if (!empty($pageToken)) {
            $filter["pageToken"] = $pageToken;
        }
        $filter["pageSize"] = 200;
        $uri .= http_build_query($filter);
        if (php_sapi_name() == "cli") {
            Service::log_time("ListRtmpChannel query: " . $uri);
        }
        $response = self::cUrl($uri, [], false);
        return $response;

    }

    public static function StopRtmp($player_id = "")
    {
        try {
            $appId = self::GetAgoraAppID();
            $uri = "/projects/{$appId}/cloud-player/players/{$player_id}";
            $response = self::cUrl($uri, [], 1, "DELETE");
            Yii::info("StopRtmp info: player_id: $player_id response message:" . json_encode($response), self::$jarLog);
            return $response;
        } catch (\Exception $exception) {
            Yii::error("StopRtmp error: player_id: $player_id error message:" . json_encode($exception->getMessage()), self::$jarLog);
            return false;
        }
    }

    public static function StopRtmpChannel($channel_id)
    {
        try {
            $res = Agora::ListRtmpChannel($channel_id);
            $data = json_decode($res, true);
            $player = [];
            foreach ($data["players"] as $datum) {
                $player[$datum["id"]] = $datum["channelName"];
            }
            Yii::info("StopRtmp info: channel_id: {$channel_id}, channel list player:" . json_encode($player) . " data: $res", self::$jarLog);
            foreach ($player as $player_id => $agoraChannel) {
                if ($channel_id == $agoraChannel) {
                    Agora::StopRtmp($player_id);
                    continue;
                }
            }
            return true;
        } catch (\Exception $exception) {
            Yii::error("StopRtmpChannel error: channel_id: $channel_id error message:" . json_encode($exception->getMessage()), self::$jarLog);
            return false;
        }
    }

    /**
     * 频道内下发分享消息
     * @param $channel_id
     * @param $pushData
     * @return bool
     */
    public static function SendShareMessage($channel_id, $pushData)
    {
        $op = self::ShareUpdate;
        return self::JarPushMessage($channel_id, $op, $pushData);
    }

    /**
     * @param $channel_id
     * @param $user_id
     * @param $time
     * @return bool
     */
    public static function RtcKick($channel_id, $user_id, $time = 0)
    {
        $uid = UserRedis::getUserInfo($user_id, "id");
        if (empty($uid)) {
            $userModel = User::find()->select("id")->where(["guid" => $user_id])->one();
            if (empty($userModel)) {
                return false;
            }
            $uid = $userModel->id;
        }
        AgoraKick::CreateKickRule($channel_id, $uid, "", $time);
    }

    /**发送单点消息
     * @param $user_id
     * @param $channel_id
     * @param $op
     * @param $pushData
     * @param $enable_historical_messaging bool 是否保存历史消息
     * @return bool
     */
    public static function JarPushSinglePointMessage($user_id = '', $channel_id = '', $op, $pushData, $enable_historical_messaging = false)
    {
        $data = [
            "guid" => $user_id,
            "op" => $op,
            "client_message_id" => (microtime(true) * 1000) . Service::random_hash(7),
            "body" => $pushData,
        ];
        //Yii::info("JarPushMessage request param, channel_id: {$channel_id}, data: ".json_encode($data, JSON_UNESCAPED_UNICODE), self::$jarLog);
        $param = [
            "guid" => $user_id,
            "data" => json_encode($data),
        ];
        return self::RtmChannelPeerMessage($user_id, $op, $data, "", $enable_historical_messaging);
        //return self::PushMessage($channel_id, $op, $pushData,$param,'/peerRtm/sendMsg');
    }

    /**
     * 使用rtm推送点对点消息,使用 agora api接口,不走jar包
     * @param string $user_id 发送者
     * @param string $destination_id 接受者id
     * @param $op
     * @param $pushData
     * @param $enable_offline_messaging bool 是否保持历史消息
     * @return bool
     */
    public static function RtmChannelPeerMessage($destination_id = '', $op = '', $pushData = [], $user_id = '', $enable_offline_messaging = false)
    {
        $appid = self::GetAgoraAppID();
        $request = [
            "destination" => $destination_id,
            "enable_offline_messaging" => $enable_offline_messaging,
            "payload" => json_encode($pushData),
        ];
        $user_id = empty($user_id) ? self::RtmPushMessageUser : $user_id;
        $url = '/project/' . $appid . '/rtm/users/' . $user_id . '/peer_messages';
        $response = self::RtmCurl($url, $request);
        $result = json_decode($response, true);
        if (empty($result)) {//重试一次
            $response = self::RtmCurl($url, $request);
            $result = json_decode($response, true);
        }
        if (empty($result)) {
            Yii::error("jar sendMsg error: destination_id: $destination_id, user_id: $user_id, op: $op, pushData: " . json_encode($pushData) . " error message: $response", self::$jarLog);
            return false;
        }
        Yii::info("jar sendMsg info: destination_id: $destination_id, op: $op, pushData: " . json_encode($pushData) . " result: " . json_encode($result), self::$jarLog);
        if (isset($result["result"]) && $result["result"] == "success") {
            return true;
        }
        Yii::error("jar sendMsg error: destination_id: $destination_id,user_id: $user_id, op: $op, pushData: " . json_encode($pushData) . " error message: $response", self::$jarLog);
        return false;
    }

    public static function StartCloudRecAudio($channel_id, $uid)
    {
        $appID = self::GetAgoraAppID();
        $resourceId = Agora::GetCloudRecresourceID($channel_id, $uid);
        $url = "/apps/{$appID}/cloud_recording/resourceid/{$resourceId}/mode/mix/start";

        $requestParam = [
            'uid' => $uid,
            'cname' => $channel_id,
            'clientRequest' => [
                'token' => Agora::generateMediaChannelKey($channel_id, $uid),
                'recordingConfig' => [
                    'maxIdleTime' => 3600,
                    'streamTypes' => 0,
                    'channelType' => 1,
                ],
                'storageConfig' => self::GetStorageConfig(),

            ]
        ];

        Service::log_time("start rec video: {$channel_id} request param: " . json_encode($requestParam));
        $response = self::cUrl($url, $requestParam);

        return ["response" => $response, "request" => $requestParam];
    }

    /**
     * 通过email检查用户身份
     * @param $PostFields
     * @return array|bool
     */
    public static function AgoraEmailCheck($PostFields)
    {
        $email = $PostFields["email"] ?? "";
        //test email
//        if(preg_match('/@gmail\.com/', $email)){
//            return ["code" => 201, "data" => []];
//        }
//        if(preg_match('/@qq\.com/', $email)){
//            return ["code" => 200, "data" => []];
//        }

//        $url = 'https://agora-email-check.herokuapp.com/validate_email';
        $url = 'https://staging-soundon-email-check.sportsnet.ca/validate_email';
        $curl = curl_init();

        $param = [];
        $param["email"] = ($email);
        $param["role"] = " https://agora-email-check.herokuapp.com/validate_email/";
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $param,
        ));
        $response = curl_exec($curl);
        if (curl_errno($curl)) {
            Service::log_time("[agora-email-check]check fail, url: {$url}, requestParam: " . json_encode($PostFields, JSON_UNESCAPED_UNICODE) . ", response: {$response}, curl_no:" . curl_errno($curl) . " curl_error:" . curl_error($curl));
            curl_close($curl);
            return false;
        }
        $info = curl_getinfo($curl);
        $http_code = $info["http_code"];
        Service::log_time("[agora-email-check]http_code: {$http_code}, url: {$url}, Took: {$info['total_time']}, requestParam: " . json_encode($PostFields, JSON_UNESCAPED_UNICODE) . ", response: {$response}");
        curl_close($curl);
        $res = json_decode($response, true);
        return ["code" => $http_code, "data" => $res];
    }


}