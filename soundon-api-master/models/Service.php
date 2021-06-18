<?php

namespace app\models;

use app\components\Agora;
use app\components\dynamodb\DOnesignalMessage;
use app\components\firebase\FbLivecast;
use app\components\Livbysqs;
use app\components\redis\BroadcastRedis;
use app\components\redis\TokenRedis;
use app\components\redis\UserRedis;
use app\components\Util;
use Aws\S3\S3Client;
use Yii;

class Service
{

    public static function authorization()
    {
        $headers = Yii::$app->request->headers;
        $device_type = $headers->get('Device-Type');
        $device_id = $headers->get('Device-Id');
        $version_code = $headers->get('Version-ResponseCode');
        $token = $headers->get('Token');
        $user_id = $headers->get('UserId');
        if (empty($device_type) || empty($device_id) || empty($version_code)) {
            return false;
        } else {

            return ['device_type' => $device_type, 'device_id' => $device_id, 'version_code' => $version_code, 'token' => $token, 'user_id' => $user_id];
        }
    }

    /**
     * 生成一个不存在的用户GUID
     * @param $num
     * @return string
     */
    public static function GenerateUniqueUserGuid($num = 0)
    {
        $guid = self::str_rand(1);
        $userDb = User::find()->select("id")->where(["guid" => $guid])->one();
        if (!empty($userDb) && $num < 20) {
            return self::GenerateUniqueUserGuid(++$num);
        }
        return $guid;
    }

    /**
     * 生成随机字符串
     * @param string $type 类型
     * @param int $length 生成随机字符串的长度
     * @param string $char 组成随机字符串的字符串
     * @return string $string 生成的随机字符串
     */
    static function str_rand($type = null, $length = 16, $char = '123456789abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ')
    {
        if (!is_int($length) || $length < 0) {
            return false;
        }
        $string = '';
        if (empty($type)) {

            for ($i = $length; $i > 0; $i--) {
                $string .= $char[mt_rand(0, strlen($char) - 1)];
            }
        } else {
            $count = 0;
            while ($count < 20) {
                $string = '';
                for ($i = $length; $i > 0; $i--) {
                    $string .= $char[mt_rand(0, strlen($char) - 1)];
                }
                if (!UserRedis::exists($string)) {
                    break;
                } else {
                    Yii::info('Generate a guid repeat guid1:' . $string, 'interface');
                    $count++;
                }
            }
        }
        return $string;
    }

    public static function create_token($username, $password)
    {
        $time = round(microtime(true) * 1000);
        $token = md5($username . $password . $time . self::random_hash(8));

        if (self::get_token_flag($token)) {
            self::create_token($username, $password);
        }

        return $token;
    }

    /**
     * 通过token获取uid
     * @param  [type] $token [token]
     * @return [type]        [uid]
     */
    public static function get_token_flag($token)
    {
        $usertoken = UserToken::find()->where(['token' => $token])->one();
        return !empty($usertoken) ? true : false;
    }

    /**
     * 随机产生A-Z, a-z, 0-9的字符串
     * @param integer $length [length]
     * @return [type]          [code]
     */
    public static function random_hash($length = 8)
    {
        $salt = array_merge(range('A', 'Z'), range('a', 'z'), range(0, 9));
        $count = count($salt);
        $hash = '';
        for ($i = 0; $i < $length; $i++) {
            $hash .= $salt[mt_rand(0, $count - 1)];
        }
        return $hash;
    }

    /**
     * 复制随机数生成
     * @param int $length
     * @return string
     */
    public static function random_str($length = 64)
    {
        // 密码字符集，可任意添加你需要的字符
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()-_ []{}<>~`+=,.;:/?|';
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            // 这里提供两种字符获取方式
            // 第一种是使用 substr 截取$chars中的任意一位字符；
            // 第二种是取字符数组 $chars 的任意元素
            $password .= substr($chars, mt_rand(0, strlen($chars) - 1), 1);
//            $password .= $chars[mt_rand(0, strlen($chars) - 1)];
        }
        return $password;
    }

    /**
     * 保存token
     * @param  [type] $user  [uid]
     * @param  [type] $token [token]
     * @return [type]        [userinfo or errorinfo]
     */
    public static function save_token($user_id, $token)
    {
        $usertoken = UserToken::find()->where(['user_id' => $user_id])->one();
        if (empty($usertoken)) {
            $usertoken = new UserToken();
            $usertoken->user_id = $user_id;
        }
        $usertoken->token = $token;

        //token写到redis
        if (!TokenRedis::Set($user_id, $token)) {
            return false;
        }
        if ($usertoken->save()) {
            return true;
        }
        Yii::info('save token error: info------' . json_encode($usertoken->errors), 'callback');
        return false;
    }

    /**
     * 查看用户的个人信息
     * @param $guid
     * @param null $type
     * @param null $user_id
     * @return array
     */
    public static function userinfo($guid, $type = null, $user_id = null)
    {
        if ($guid == $user_id) {
            self::reloadUser($guid);
        }
        $user = UserRedis::getUserInfo($guid);
        $tmp = [];
        if (empty($user)) {
            return [];
        }
        $tmp['id'] = (int)$user['id'];
        $tmp['guid'] = $user['guid'];
        $tmp['username'] = $user['username'];
        $tmp['first_name'] = $user['first_name'];
        $tmp['last_name'] = $user['last_name'];
        $tmp['full_name'] = $tmp['first_name'] . " " . $tmp['last_name']; //全名
        $tmp['avatar'] = self::getCompleteUrl($user['avatar']);
        $tmp['bg_image'] = isset($user['bg_image']) ? self::getCompleteUrl($user['bg_image']) : "";
        $tmp['intro'] = $user['intro'] ?? "";
        $tmp['status'] = $user['status'] ?? "";
        $tmp['avatar_small'] = self::avatar_small($tmp['avatar']);
        $tmp['type'] = isset($user['type']) ? (int)$user['type'] : 0;
        $count_follower = UserRedis::countFollowerFriendsList($guid);
        $count_following = UserRedis::countFollowingFriendsList($guid);
        $tmp['follower_number'] = intval($count_follower);
        $tmp['following_number'] = intval($count_following);
        $follow_canuks_json = $user['follow_canuks'] ?? "";
        if (!empty($follow_canuks_json)) {
            $tmp['follow_canuks'] = json_decode($follow_canuks_json, true);
        } else {
            $tmp['follow_canuks'] = (object)[];//缺失值
        }
        $tmp['audio_url'] = isset($user['audio_url']) ? self::getCompleteUrl($user['audio_url']) : "";
        $tmp['duration'] = isset($user['duration']) ? (int)$user['duration'] : 0;
        if ($guid != $user_id) {//非本人查看消息
            //查询用户之间得关系
            $tmp['relation_status'] = Service::UserRelation($guid, $user_id);
            return $tmp;
        }
        $tmp['relation_status'] = 1;//用户本人固定返回1
        $tmp['email'] = $user["email"];
        if (!empty($type) && $guid == $user_id) {
            $tmp['token'] = UserToken::Get($guid);//token返回
            $tmp['rong_token'] = $user["rong_token"];
            $tmp['rtm_token_encrypt'] = Agora::GenerateRtmToken($user_id, 1, true);
        }
        $tmp['date_of_birth'] = isset($user['date_of_birth']) ? intval($user['date_of_birth']) : 0;
        if (isset($user['email_status']) && $user['email_status'] == 'valid') {
            $tmp['email_status'] = true;
        } else {
            $tmp['email_status'] = false;
        }
        $tmp['user_follow_notification'] = isset($user['user_follow_notification']) ? $user['user_follow_notification'] : '1';
        $tmp['master_switch'] = isset($user['master_switch']) ? $user['master_switch'] : '1';
        return $tmp;
    }

    /**获取小头像
     * @param $url
     * @param string $size
     * @return string
     */
    public static function avatar_small($url, $size = '80')
    {
        if(Util::FakeAvatar($url)){
            return $url;
        }
        if (empty($url)) {
            return User::GetDefaultAvatar();
        }
        $path_parts = pathinfo($url);
        if (empty($path_parts['extension'])) {
            return $url;
        }
        return $path_parts['dirname'] . '/' . $path_parts['filename'] . '_' . $size . '.' . $path_parts['extension'];
    }

    /**
     * @param $guid string
     * @param $user_id string 调用者的guid
     * @return int  0无关系 1好友 2.将该用户拉黑 3.被拉黑 4被关注 5主动关注   10已经请求了好友等待接受
     */
    public static function UserRelation($guid, $user_id)
    {
        $friend_list = UserRedis::friendsList($user_id);
        $block_list1 = UserRedis::blockList($user_id);
        $block_list2 = UserRedis::blockList($guid);
        $follow_list = UserRedis::FollowingFriendsList($guid);
        $follow_list_back = UserRedis::FollowingFriendsList($user_id);
        $relation_status = 0;//不存在关系
        if (in_array($user_id, $follow_list)) {
            $relation_status = 4;//他关注了我
        }
        if (in_array($guid, $follow_list_back)) {
            if ($relation_status == 4) {
                $relation_status = 1;
            } else {
                $relation_status = 5;//我关注了他
            }

        }
        if (in_array($guid, $friend_list)) {
            $relation_status = 1; //是好友
        }
        if (in_array($guid, $block_list1)) {
            $relation_status = 2;//我把他加入了黑名单
        }
        if (in_array($guid, $block_list2)) {
            $relation_status = 3;//他把我加入了黑名单
        }
        return $relation_status;
    }

    public static function verifyFakePhoneCode($cellphone = '', $code = '')
    {
        if ($cellphone == '' || $code == '') {
            return false;
        }
        $model = FakeSmsVerify::find()->where(['=', 'cellphone', $cellphone])->andWhere(['>=', 'response_time', time() - 5 * 60])->andWhere(['=', 'lock', 0])->one();
        if (isset($model)) {
            // 清空验证码
            $model->sms_code = '';
            $model->verify_time = time();
            return $model->save();
        }
        return false;
    }

    /*
 * 验证手机是否是否 fake phone
 * return boolean
 */
    public static function creareFakePhoneCode($cellphone = '', $sms_code = "")
    {
        if (empty($cellphone)) {
            return false;
        }
        if (empty($sms_code)) {
            $sms_code = rand(111111, 999999);
        }
        $model = FakeSmsVerify::find()->where(['=', 'cellphone', $cellphone])->one();
        if (!empty($model)) {
            // 验证码
            $model->sms_code = (string)$sms_code;
            $model->response_time = time();
            $model->lock = 0;
            if ($model->save()) {
                return $sms_code;
            }
            return false;
        } else {
            $model = new FakeSmsVerify();
            $model->cellphone = $cellphone;
            $model->sms_code = (string)$sms_code;
            $model->response_time = time();
            $model->lock = 0;
            if ($model->save()) {
                return $sms_code;
            }
        }
        return false;
    }

    /**
     * 将mysql user表数据同步到redis
     * @param $guid
     * @param null $value
     * @return mixed
     */
    static function reloadUser($guid, $value = null)
    {
        if (!empty($value) && is_array($value) && UserRedis::exists($guid)) { //单独设置redis user的值
            if (!UserRedis::setUserInfo($guid, $value)) {
                return false;
            }
        }
        $masterDB = Util::GetMasterDb();
        $model = User::find()
            ->alias("u")
            ->select([
                'id',
                'guid',
                'type',
                'email',
                'username',
                'avatar',
                'intro',
                'status',
                'rong_token',
                'first_name',
                'last_name',
                'bg_image',
                'master_switch',
                'user_follow_notification',
            ])
            ->where("status = 'normal'")
            ->andWhere(["guid" => $guid])
            ->asArray()
            ->one($masterDB);
        //guid不存在
        if (empty($model)) {
            return false;
        }
        $data = [
            'id' => $model['id'],
            'guid' => $model['guid'],
            'type' => (int)$model['type'],
            'username' => $model['username'],
            'intro' => $model['intro'],
            'first_name' => $model['first_name'],
            'last_name' => $model['last_name'],
            'avatar' => $model['avatar'],
            'email' => $model['email'],
            'status' => $model['status'],
            'rong_token' => $model['rong_token'],
            'bg_image' => $model['bg_image'],
            'master_switch' => $model['master_switch'],
            'user_follow_notification' => $model['user_follow_notification'],
        ];
        //获取扩展信息
        $userExtendsInfo = UsersExtends::find()->select(['id', 'speech_intro as audio_url', 'duration', 'follow_canuks'])->where(["guid" => $guid])->asArray()->one($masterDB);
        if (!empty($userExtendsInfo)) {
            $data["audio_url"] = $userExtendsInfo['audio_url'] ?? '';
            $data["duration"] = $userExtendsInfo['duration'] ?? '';//speech_intro时长
            //关注冰球球队数据
            $canuksId = $userExtendsInfo["follow_canuks"] ?? "";
            $followCanuksInfo = CanuksList::GetOne($canuksId);
            $data["follow_canuks"] = json_encode($followCanuksInfo);
        }
        if (!UserRedis::setUserInfo($guid, $data)) {
            return false;
        }
        //刷新token过期时间
        TokenRedis::RefreshExpireTime($guid);
        return $data;
    }

    /**
     * [getCompleteUrl description]
     * 获取完整url
     * @param $url string 相对地址
     * @return [type] [description]
     */
    public static function getCompleteUrl($url)
    {
        if(Util::FakeAvatar($url)){
            return $url;
        }
        if (!empty($url)) {
            $url = str_replace(' ', '%20', $url);
            $now_domain_url = Yii::$app->params['aws']['url'];
            if (strpos($url, $now_domain_url) === FALSE) {
                return rtrim($now_domain_url, '/') . $url;
            }
            // $old_url[] = 'http://phtt.s3-ap-southeast-1.amazonaws.com';
            // $old_url[] = 'https://phtt.s3-ap-southeast-1.amazonaws.com';
            // $url = str_replace($old_url,['',''],$url);
            return $url;
        }
        return '';
    }


    /**
     * 生成guid
     * @return [type] [guid]
     */
    public static function create_guid()
    {
        if (function_exists('com_create_guid')) {
            $guid = trim(com_create_guid(), '{}');
            return str_replace('-', '', $guid);
        } else {
            mt_srand((double)microtime() * 10000);//optional for php 4.2.0 and up.
            $charid = strtoupper(md5(uniqid(rand(), true)));
            $hyphen = chr(45);// "-"
            $hyphen = '';// "-"
            //chr(123)// "{"
            $uuid = substr($charid, 0, 8) . $hyphen
                . substr($charid, 8, 4) . $hyphen
                . substr($charid, 12, 4) . $hyphen
                . substr($charid, 16, 4) . $hyphen
                . substr($charid, 20, 12);
            //.chr(125);// "}"
            return $uuid;
        }
    }


    public static function microtime_float()
    {
        $time = explode(" ", microtime());
        $time = $time [1] . ($time [0] * 1000);
        $time2 = explode(".", $time);
        $time = $time2 [0];
        return $time;
    }

    /**
     * 记录日志
     * @param $message
     */
    public static function log_time($message)
    {
        if (php_sapi_name() == "cli") {
            $now = \DateTime::createFromFormat('U.u', number_format(microtime(true), 6, '.', ''));
            echo $now->format("Y-m-d H:i:s.u") . "\t";
            print_r($message);
            echo "\n";
        } else {
            if (is_array($message)) {
                $message = json_encode($message);
            }
            Yii::info($message, 'jar');
        }
    }

    /**
     * reload 直播
     * @param $channel_id
     * @param string $type
     * @param null $model
     * @return array
     */
    static function reloadBroadcast($channel_id, $type = '', $model = null)
    {

        if (empty($model)) {
            $masterDB = Util::GetMasterDb();
            $model = Channel::find()->select(['id', 'quick_type', 'guid', 'title', 'type', 'cover_image', 'description', 'live_start_time', 'live_end_time',
                'scheduled_time', 'live_highest_audience', 'replay_highest_audience', 'duration', 'location', 'like_count', 'mp4url', 'hlsurl', 'user_id', 'live_current_audience',
                'live_highest_audience', 'post_status', 'post_timeline', 'diamonds', 'coins', 'gifts', 'total_viewer', 'total_joined_times', 'live_type', 'landscape', 'schedule',
                'video', 'created_at', 'group_id', 'cohost_any', 'bg', "show_chat", 'diamond_number', 'cutin_diamond_number', 'live_url', 'extra', 'feed_id', 'tags', 'order_score', 'country',
                'language', 'short_desc', 'send_email'])
                ->where(['guid' => $channel_id])->one($masterDB);
        }

        $data = [];
        if ($model) {
            $data['channel_id'] = $model->guid;
            $data['title'] = $model->title;
            $data['id'] = $model->id;
            $data['type'] = $model->type;
            $data['quick_type'] = $model->quick_type;
            $data['cover_image'] = $model->cover_image;
            $data['description'] = $model->description;
            $data['live_start_time'] = $model->live_start_time;
            $data['live_end_time'] = $model->live_end_time;
            $data['scheduled_time'] = $model->scheduled_time;
            $data['live_highest_audience'] = $model->live_highest_audience;
            $data['replay_highest_audience'] = $model->replay_highest_audience;
            $data['duration'] = $model->duration;
            $data['location'] = $model->location;
            $data['diamonds'] = (int)$model->diamonds;
            $data['golds'] = (int)$model->coins;
            $data['gifts'] = (int)$model->gifts;
            $data['total_viewer'] = $model->total_viewer;
            $data['total_joined_times'] = $model->total_joined_times;
            $data['schedule'] = $model->schedule;
            if ($type) {
                $data['like_count'] = $model->like_count;
            }

            $data['video_url'] = $model->mp4url;

            //$data['longitude']= $model->longitude;
            $data['user_id'] = $model->user_id;
            if ($type) {
                $data['audience_count'] = $model->live_current_audience;
            }

            $data['live_highest_audience'] = $model->live_highest_audience;
            $data['post_status'] = $model->post_status;//是否推送到所有用户
            $data['post_timeline'] = $model->post_timeline;//是否推送到timeline
            $data['short_id'] = $model->id;
            $data['country'] = $model->country;
            $data['language'] = $model->language;
            $data['short_desc'] = empty($model->short_desc) ? '' : $model->short_desc;

            //新加 2018-12-23

            $data['live_type'] = $model->live_type;
            if (!empty($model->group_id)) {
                $data['group_id'] = $model->group_id;
            }

            $data['tags'] = !empty($model->tags) ? $model->tags : '';

            $data['landscape'] = $model->landscape;
            $data['video'] = $model->video;
            $data['created_at'] = $model->created_at;
            if ($model->live_type == Channel::liveCastLive || Channel::CheckSeatMode($model->video, $model->guid)) {
                $data['cohost_any'] = $model->cohost_any;
            }
            if (!empty($model->bg)) {
                $data['bg'] = $model->bg;
            }
            if (!empty($model->live_url)) {
                $data['live_url'] = $model->live_url;
            }
            if (!empty($model->diamond_number)) {
                $data['diamond_number'] = (string)$model->diamond_number;
            }
            if (!empty($model->cutin_diamond_number)) {
                $data['cutin_diamond_number'] = (string)$model->cutin_diamond_number;
            }
            if (!empty($model->extra)) {
                $data["extra"] = $model->extra;
            }
            $data['feed_id'] = $model['feed_id'] ?? '';
            $expire = 0;
            if ($model->type == 2) {
                $expire = 1296000;//结束的保留15天redis 数据
            }
            if ($model->live_type == Channel::liveCastLive) {
                if ($type) {
                    $manager = FbLivecast::getManagerFromFb($channel_id);
                    $data['manager'] = !empty($manager) ? implode(',', $manager) : '';
                }
                $data['send_email'] = $model->send_email;
            }
            $order_score = $model['order_score'] ?? 0;
            $data['order_score'] = intval($order_score);

            $re = BroadcastRedis::broadcastInfo($channel_id, $data, $expire);
            //Yii::info('broadcast/reload:------like_count:' . $model->like_count . '---------' . $data['channel_id'], 'interface');
            if (!$re) {
                Yii::info('broadcast/create: write Broadcast info fail ------' . json_encode($channel_id, JSON_UNESCAPED_UNICODE), 'interface');
            }

        }
        return $data;
    }

    /**
     * [OnesignalSendMessage description]
     * @param [type]  $message   [description]
     * @param array $filters [description]
     * @param string $title [description]
     * @param array $data [description]
     * @param array $extra [description]
     * @param boolean $is_record [是否记录到dynamodb]
     * @param boolean $is_mul_push [是否走批量推送]
     * @return bool|string
     * //类别,like=201 ;comment=202; post_timeline =203;friends=204;following = 205; from_app=300, 全部=0,302抢单列表，301 cash success; 400 livecast邀请
     */
    public static function OnesignalSendMessage($message, $filters = array(), $title = '', $data = array(), $extra = array(), $is_record = false, $is_mul_push = false)
    {
        $app_id = Yii::$app->params["onesignal"]["api_id"] ?? '';
//        $app_key = Yii::$app->params["onesignal"]["rest_api_key"] ?? '';
        $content = array(
            "en" => $message
        );

        $heading = array(
            "en" => $title
        );

        $fields = array(
            'app_id' => $app_id,
            'data' => $data,
            'contents' => $content,
            'headings' => $heading
        );

        if (!empty($filters)) {
            $fields["filters"] = $filters;
        }
        $extra["ios_badgeType"] = "Increase";
        $extra['ios_badgeCount'] = 1;
        $extra['ttl'] = 2419200;

        $queue_name = Yii::$app->params["sqs"]['onesignal'];
        $sqs = new Livbysqs($queue_name);
        $feed_tag_message = array(
            'fields' => $fields,
            'extra' => $extra,
            'is_record' => $is_record,
            'is_mul_push' => $is_mul_push,
        );
        $bool = $sqs->send($feed_tag_message);
        if (!$bool) {
            Yii::info('sqs/onesignal------onesignal queue send fail!', 'interface');
        }
        return true;
    }

    public static function Simpleuserinfo($user)
    {
        $tmp = [];
        if (!empty($user)) {
            if (!isset($user['guid']) || empty($user['guid'])) {
                return $tmp;
            }
            $tmp['avatar'] = !empty($user['avatar']) ? self::getCompleteUrl($user['avatar']) : self::getCompleteUrl(User::GetDefaultAvatar());
            $tmp['avatar_small'] = self::avatar_small($tmp['avatar']);
            $tmp['guid'] = $user['guid'];
            $tmp['username'] = $user['username'] ?? '';
            $tmp['nickname'] = isset($user['nickname']) ? $user['nickname'] : '';
            $tmp['first_name'] = $user['first_name'] ?? '';
            $tmp['last_name'] = $user['last_name'] ?? '';
            $tmp['gender'] = isset($user['gender']) ? intval($user['gender']) : 0;
            $tmp['type'] = isset($user['type']) ? intval($user['type']) : 0;
            $tmp['intro'] = isset($user['intro']) ? $user['intro'] : '';
        }
        return $tmp;
    }

    ////各种缩略图的缩略尺寸
    public static function getThumbnailSize($type = 'avatar')
    {
        $size = 480;
        switch ($type) {
            case 'avatar':
                $size = 80;
                break;
            case 'chat':
            case 'comment':
            case 'bg_image':
            case 'cover':
            case 'feed':
                $size = 480;
                break;
            case 'report':
                $size = 0;
                break;
            default:
                # code...
                break;
        }
        return $size;
    }

    //livecast 19类型直播列表
    public static function LiveCastFormatInfo($array)
    {
        $array['short_id'] = isset($array['short_id']) ? intval($array['short_id']) : 0;
        //$array['audience_count'] = isset($array['audience_count']) ? intval($array['audience_count']) : 0;
        $array['live_type'] = isset($array['live_type']) ? intval($array['live_type']) : 1;
        $array['type'] = isset($array['type']) ? intval($array['type']) : Channel::type_status_living;
        $array['post_status'] = isset($array['post_status']) ? intval($array['post_status']) : 2;
        $array['scheduled_time'] = !empty($array['scheduled_time']) ? strtotime($array['scheduled_time']) : 0;
        $array['live_current_audience'] = isset($array['live_current_audience']) ? intval($array['live_current_audience']) : 1;
        return $array;
    }

    /**
     * [MulOnesignalSendMessage description]
     * 批量发布onesignal ,和批量记录dy
     * @param [type]  $message   [description]
     * @param array $filters [description]
     * @param string $title [description]
     * @param array $data [description]
     * @param array $extra [description]
     * @param array $dy_data [description]
     * @param boolean $is_record [是否记录到dynamodb]
     * 在feed 发布队列里面又使用
     * @param array $push_users  推送的用户guid 数组
     * @return bool|string
     */
    public static function MulOnesignalSendMessage($message, $filters = array(), $title = '', $data = array(), $extra = array(), $dy_data = [], $is_record = false,$push_users=[])
    {
        $response = '';
        self::log_time('push users result='.json_encode($push_users));
        if($filters) {
            $app_id  = Yii::$app->params["onesignal"]["api_id"] ?? '';
            $app_key = Yii::$app->params["onesignal"]["rest_api_key"] ?? '';
            $content = [
                "en" => $message
            ];

            $heading = [
                "en" => $title
            ];

            $fields = [
                'app_id' => $app_id,
                'data' => $data,
                'contents' => $content,
                'headings' => $heading
            ];

            if (!empty($filters)) {
                $fields["filters"] = $filters;
            }
            $extra["ios_badgeType"]  =  "Increase";
            $extra['ios_badgeCount'] =  1;
            $extra['ttl'] =  2419200;
            $fields = array_merge($fields, $extra);

            $fields = json_encode($fields);
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json; charset=utf-8',
                'Authorization: Basic ' . $app_key));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
            curl_setopt($ch, CURLOPT_HEADER, FALSE);
            curl_setopt($ch, CURLOPT_POST, TRUE);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
            curl_setopt($ch, CURLOPT_TIMEOUT, 4);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT , 2);

            $response = curl_exec($ch);

            curl_close($ch);
            self::log_time('MulOnesignalSendMessage result='.$response);
        }else{
            self::log_time('MulOnesignalSendMessage : No push user objects');
        }
        return $response;

    }

    public static function OnesignalNotification($message, $filters = array(), $title = '', $data = array(), $extra = array())
    {
        $app_id = Yii::$app->params["onesignal"]["api_id"];
        $app_key = Yii::$app->params["onesignal"]["rest_api_key"];
        $content = array(
            "en" => $message
        );

        $heading = array(
            "en" => $title
        );

        $fields = array(
            'app_id' => $app_id,
//            'include_player_ids' => $player_ids,
            'data' => $data,
            'contents' => $content,
            'headings' => $heading
        );
        /*
         * array(
                array("field" => "tag", "key" => "level", "relation" => "=", "value" => "10"),
                array("operator" => "OR"),
                array("field" => "amount_spent", "relation" => "=", "value" => "0")
           ),
        */
        if (!empty($filters)) {
            $fields["filters"] = $filters;
        }
        $fields = array_merge($fields, $extra);

        $fields = json_encode($fields);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json; charset=utf-8',
            'Authorization: Basic ' . $app_key));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);

        $response = curl_exec($ch);
        curl_close($ch);
        $result = json_decode($response, true);
        Service::log_time("onesiganal push message fields:". $fields);
        if (isset($result["errors"])) {
            return $result["errors"];
        } else {
            return $response;
        }
    }
}




