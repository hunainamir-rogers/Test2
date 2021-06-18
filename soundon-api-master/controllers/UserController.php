<?php

namespace app\controllers;

use app\components\Agora;
use app\components\BaseController;
use app\components\ImageUtil;
use app\components\redis\ApiRedis;
use app\components\redis\CacheRedis;
use app\components\redis\TokenRedis;
use app\components\redis\UserRedis;
use app\components\define\ResponseCode;
use app\components\define\ResponseMessage;
use app\components\ResponseTool;
use app\components\Rongyun;
use app\components\define\SystemConstant;
use app\components\service\User as Serviceuser;
use app\components\Util;
use app\components\Words;
use app\models\CanuksList;
use app\models\Channel;
use app\models\ChannelModerators;
use app\models\Service;
use app\models\User;
use app\models\UserLoginLog;
use app\models\UserSendAppLog;
use app\models\UsersExtends;
use app\models\UserToken;
use Yii;

class UserController extends BaseController
{
    const cellphoneRule = '/^[1-9][0-9]{5,15}$/';

    public function actionLogin()
    {
        $check = Service::authorization();
        if (empty($check)) {
            return $this->error("Missing a required parameter.");
        }
        $device_type = $check['device_type'];
        $device_id = $check['device_id'];
        $version_code = $check['version_code'];
        $ip = Util::get_ip();
        $postdata = $this->parameter;
        $verification_code = isset($postdata['verification_code']) ? $postdata['verification_code'] : '';
        $email = isset($postdata['email']) ? $postdata['email'] : '';
        $password = isset($postdata['password']) ? $postdata['password'] : '';
        if (empty($email)) {
            return $this->error("Email is require.");
        }


        $user = User::find()->where(["status" => "normal"])->andWhere(['email' => $email])->one();
        //登录
        if (!empty($user)) {
            $loginInfo = User::login($user, $postdata, $device_id, $device_type, $ip, $version_code);
            if (!$loginInfo) {
                return $this->ErrResponse();
            }
            $this->success($loginInfo);
        }
        //注册
        if (!isset($verification_code) || empty($verification_code)) {
            return $this->error("Missing a required parameter. Verification code required.");
        }
        //验证码效验
        if (!Util::FakeVerifyCode($verification_code)) {
            if (!User::EmailCodeVerify($email, $verification_code)) {
                return $this->error('Verification code is incorrect.');
            }
        }

        Service::verifyFakePhoneCode($email, $verification_code);//置空
        $registerInfo = User::Register($email, $password, $device_type, $device_id, $ip);
        if (!$registerInfo) {
            return $this->ErrResponse();
        }
        $this->success($registerInfo);
    }


    /**
     * 发送验证码
     */
    public function actionSendVerifyCode()
    {
        $postdata = $this->parameter;
        $email = $postdata['email'] = trim($postdata['email'] ?? "");
        $type = $postdata['type'] = trim($postdata['type'] ?? "register");
        if (empty($email)) {
            return $this->error("Email is required.");
        }
        $check = Service::authorization();
        if (empty($check)) {
            return $this->error("Missing a required parameter.");
        }
        $device_type = $check['device_type'];
        $device_id = $check['device_id'];

        if (!in_array($device_type, ['android', 'ios'])) {
            return $this->error("Missing a required parameter. Device type is incorrect.");
        }
        if (!in_array($type, ['register', 'login', 'set_password'])) {
            return $this->error("Do you want to register or log in.");
        }
        //邮箱格式验证
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->error('Incorrect email format');
        }
        $request_ip = Util::get_ip();
        //5分钟可以发一次
//        if(!User::SendVerifyCodeInterval($email)){
//            return $this->error("You have sent validation code, Please try again later");
//        }
        $user = User::find()->select(['id', 'guid', 'status', 'email'])->where(['and', "status in ('normal','lock','unactivated')", "cellphone=:email"], [':email' => $email])->one();
        if (!empty($user) && $user->status != 'normal') {
            return $this->error('Your account has been locked for violating community guidelines. For questions, email ' . SystemConstant::SystemEmail);
        }
        switch ($type) {
            case "set_password":
                break;
            case "register":
                if (!empty($user)) {
                    return $this->error('You have already registered. Please log in');
                }
                break;
        }
        //发送邮箱验证码
        if (User::EmailSentCode($email, "", $email, $type, $request_ip, $device_type, $device_id)) {
            return $this->success(["request_id" => "xxxxxx"], "A verify code has been sent to your email");
        }
        return $this->error(ResponseMessage::STSTEMERROR);
    }


    /**
     * 修改个人信息
     * @return [type] [description]
     */
    public function actionProfilesetting()
    {
        $postdata = $this->parameter;
        $check = Service::authorization();
        if (empty($check)) {
            return $this->error("Missing a required parameter.");
        }
        $user_id = $check['user_id'];
        $token = $check['token'];
        $device_type = $check['device_type'];
        $this->checkuser($user_id, $token, $check['version_code']);

        $old_user = User::find()->where(['and', "status = 'normal'", "guid=:guid"], [':guid' => $user_id])->one();
        if (empty($old_user)) {
            return $this->error('User status is abnormal.');
        }
        $data = [];
        $user_extends_up_data = [];
        $rongYunUpdate = [];
        if (isset($postdata['username'])) {//&& $old_user->username_change == 0
            $username = trim($postdata['username']);
            if ($username != $old_user->username) {
                $username = str_replace(PHP_EOL, '', $username);
//                if (!preg_match("/^[a-z0-9\._]+$/", $username)) {
//                    return $this->error("Only lowercase letters, numbers, _ and . are allowed");
//                }
                $username_limit = strlen($username);
                if ($username_limit < 4 || $username_limit > 30) {
                    return $this->error('Username should be between 4 and 24 characters.');
                }

                if (User::IllegalUsername($username)) {
                    return $this->error('Username is not allowed. Please choose another username');
                }
                $is_set = User::find()->select(['id', 'guid'])->where(['and', "status = 'normal'", "username=:username"], [':username' => $username])->one();
                if (!empty($is_set) && $is_set->guid != $user_id) {
                    return $this->error('Username is already taken.');
                }
                $old_user->username = $username;
                $data['username'] = $username;
                $rongYunUpdate["username"] = $username;
            }
        }
        if (isset($postdata['gender'])) {
            if (!in_array($postdata['gender'], [0, 1, 2, 3])) {
                return $this->error('Gender error');
            }
            $old_user->gender = trim($postdata['gender']);
            $data['gender'] = $postdata['gender'];
        }
        if (isset($postdata['password'])) {//修改密码
            $pwdLen = strlen($postdata['password']);
            if ($pwdLen < 6 || $pwdLen > 20) {
                $this->error("Please check the password length, between 6 and 20 characters");
            }
            $salt = Service::random_hash(6);
            $old_user->salt = $salt;
            $old_user->password = User::GeneratePassword($user_id, $postdata['password'], $salt);
        }
        if (isset($postdata['bg_image'])) {
            $old_user->bg_image = $postdata['bg_image'];
            $data['bg_image'] = $postdata['bg_image'];
        }
        if (isset($postdata['first_name'])) {
            $old_user->first_name = $postdata['first_name'];
            $data['first_name'] = $postdata['first_name'];
        }

        if (isset($postdata['last_name'])) {
            $old_user->last_name = $postdata['last_name'];
            $data['last_name'] = $postdata['last_name'];
        }

        if (isset($postdata['intro'])) {
            $intro = trim($postdata['intro']);
            $old_user->intro = $intro;
            $data['intro'] = $intro;
        }
        if (isset($postdata['date_of_birth'])) {
            $birthday = trim($postdata['date_of_birth']);
            $old_user->date_of_birth = $birthday;
            $data['date_of_birth'] = $birthday;
        }

        if (isset($postdata['avatar'])) {
            $image_file = isset($postdata['avatar']) ? trim($postdata['avatar']) : '';
            //参数校验
            $old_user->avatar = $image_file;
            $rongYunUpdate["avatar"] = Service::getCompleteUrl($image_file);
        }

        //总开关
        $master_switch = isset($postdata['master_switch']) ? (string)$postdata['master_switch'] : '';
        //修改推送消息的开关
        $arr_notification = ['user_follow_notification'];
        foreach ($arr_notification as $key => $value) {
            if ($master_switch === '' || !in_array($master_switch, ['1', '0'])) {
                if (isset($postdata[$value]) && in_array($postdata[$value], ['1', '0'])) {
                    $old_user->$value = (string)$postdata[$value];
                    $data[$value] = (string)$postdata[$value];
                }
            } else {
                $old_user->$value = $master_switch;
                $data[$value] = $master_switch;
                $old_user->master_switch = $master_switch;
                $data['master_switch'] = $master_switch;
            }

        }

        //更新关注球队
        if (isset($postdata['follow_canuks'])) {
            if (!is_array($postdata['follow_canuks'])) {
                return $this->error("parameter error.");
            }
            $canuks_id = array_pop($postdata['follow_canuks']);//只取一个值
            $rows = CanuksList::find()->where(["id" => $canuks_id])->asArray()->one();
            if (empty($rows)) {
                $this->error("Please select the correct canuks");
            }
            $user_extends_up_data['follow_canuks'] = $canuks_id;
        }

        //修改语音
        if (isset($postdata['audio_url'])) {
            if (!empty($postdata['audio_url'])) {
                $user_extends_up_data['speech_intro'] = $postdata['audio_url'];
                $user_extends_up_data['duration'] = $postdata['duration'] ?? 0;
            } else {
                $user_extends_up_data['speech_intro'] = '';
                $user_extends_up_data['duration'] = 0;
            }
        }
        //保存扩展表信息
        if (!empty($user_extends_up_data)) {
            if (!UsersExtends::RememberUserExtendsInfo($user_id, $user_extends_up_data)) {
                return $this->error("update fail");
            }
        }
        if (!$old_user->save()) {
            Yii::error("user/Profilesetting old_user update fail ----- " . json_encode($old_user->getErrors()), 'interface');
            $errors = $old_user->firstErrors;
            $error = array_shift($errors);
            $error = isset($error) ? $error : 'Save failed. Please try again';
            return $this->error($error);
        }
        //同步融云信息
        if (!empty($rongYunUpdate)) {
            Rongyun::reflushUserInfo($user_id, $rongYunUpdate);
        }
        //修改Redis里的数据
        $data = Service::userinfo($user_id, "update", $user_id);
        return $this->success($data, 'Information modification success', $check['version_code']);
    }

    /**
     * 找回密码
     */
    public function actionForgotPassword()
    {
        $postdata = $this->parameter;
        $email = $postdata['email'] ?? "";
        $password = $postdata['password'] ?? "";
        $access_token = $postdata['access_token'] ?? "";
        $type = $postdata['type'] ?? "set_password";
        if (empty($email)) {
            return $this->error("Email is require.");
        }
        if (empty($access_token)) {
            return $this->error("Access token is require.");
        }
        $user = User::find()->where(["status" => "normal"])->andWhere(['email' => $email])->one();
        if (empty($user)) {
            return $this->error("Not found user!");
        }
        $user_id = $user->guid;
        //验证码效验
        $key = TokenRedis::GetAccessTokenKey($type, $user_id);
        $redisToken = TokenRedis::GetCache($key);
        if (!$redisToken) {
            return $this->error("Access token has expired.");
        }
        if ($redisToken != $access_token) {
            return $this->error("Access token is not correct");
        }
        TokenRedis::ClearCache($key);
        $salt = Service::random_hash(6);
        $passwordHash = User::GeneratePassword($user_id, $password, $salt);//注册
        if (!$passwordHash) {
            Yii::error("user/login register GeneratePassword failed  email: {$email} ------", 'my');
            return $this->error(ResponseMessage::STSTEMERROR);
        }
        $user->salt = $salt;
        $user->password = $passwordHash;
        if (!$user->save()) {
            return $this->error(ResponseMessage::STSTEMERROR);
        }
        return $this->success([]);
    }

    public function actionAccessToken()
    {
        $postdata = $this->parameter;
        $email = $postdata['email'] ?? "";
        $verification_code = $postdata['verification_code'] ?? "";
        $type = $postdata['type'] ?? "set_password";
        if (empty($email)) {
            return $this->error("Email is require.");
        }
        if (empty($type)) {
            return $this->error("Type is require.");
        }
        if (empty($verification_code)) {
            return $this->error("Verification code is require.");
        }
        $user = User::find()->where(["status" => "normal"])->andWhere(['email' => $email])->one();
        if (empty($user)) {
            return $this->error("Not found user!");
        }
        if ($user->status != "normal") {
            return $this->error("The user status is incorrect!");
        }
        $user_id = $user->guid;
        //验证码效验
        if (!Util::FakeVerifyCode($verification_code)) {
            if (!User::EmailCodeVerify($email, $verification_code, $type)) {
                return $this->error('Verification code is incorrect.');
            }
        }
        //生成一个有时效性的access token
        $accessToken = Service::random_str(64);
        $key = TokenRedis::GetAccessTokenKey($type, $user_id);
        if (!TokenRedis::SetCache($key, $accessToken, 60 * 60 * 15)) {
            return $this->error(ResponseMessage::STSTEMERROR);
        }
        return $this->success(["access_token" => $accessToken], "Please reset your password within 15 minutes");
    }

    /**
     * 修改密码
     */
    public function actionChangePassword()
    {
        $postdata = $this->parameter;
        $check = Service::authorization();
        if (empty($check)) {
            return $this->error("Missing a required parameter.");
        }
        $user_id = $check['user_id'];
        $token = $check['token'];
        $this->checkuser($user_id, $token, $check['version_code']);

        $old_user = User::find()->where(['and', "status = 'normal'", "guid=:guid"], [':guid' => $user_id])->one();
        if (empty($old_user)) {
            return $this->error('User status is abnormal.');
        }
        $oldPassword = $postdata['old_password'] ?? "";
        $password = $postdata['password'] ?? "";
        if (empty($oldPassword) || empty($password)) {
            return $this->error("Missing a required parameter.");
        }
        $pwdLen = strlen($postdata['password']);
        if ($pwdLen < 6 || $pwdLen > 20) {
            return $this->error("Please check the password length, between 6 and 20 characters");
        }

        //验证旧密码
        if (!User::VerifyPassword($user_id, $oldPassword, $old_user->salt, $old_user->password)) {
            return $this->error("The old password is incorrect");
        }

        $salt = Service::random_hash(6);
        $old_user->salt = $salt;
        $old_user->password = User::GeneratePassword($user_id, $postdata['password'], $salt);
        if (!$old_user->save()) {
            return $this->error("Save fail.");
        }
        return $this->success([]);
    }

    /**
     * 获取用户部分信息
     * 现在赶回guid 头像 用户名
     */
    public function actionGetSimpleUserinfo()
    {
        $check = Service::authorization();
        if (empty($check)) {
            return $this->error("Missing a required parameter.");
        }
        $postdata = $this->parameter;
        $user_ids = $postdata['user_ids'] ?? [];
        if (empty($user_ids) || !is_array($user_ids)) {
            return $this->error('Lack of necessary user ids.');
        }
        $this->checkuser($check['user_id'], $check['token'], $check['version_code']);
        $key = $check['user_id'] . ':' . md5(implode(',', $user_ids));
        $str = ApiRedis::GetApiData($key);
        $data = [];
        if ($str !== false) {
            $data = json_decode($str, true);
            if (empty($data)) {
                $data = [];
            }
        } else {
            foreach ($user_ids as $user_id) {
                if ($user_id == Rongyun::RcSystemSender) {
                    $userinfo = Rongyun::getSysMsgSendUserData();
                } else {
                    $userinfo = UserRedis::getUserInfo($user_id, ['guid', 'avatar', 'username', 'intro']);
                    if (empty($userinfo['guid'])) {
                        $userinfo = Service::userinfo($user_id);
                    }
                }
                $mid = [];
                $mid['avatar'] = Service::getCompleteUrl($userinfo['avatar'] ?? '');
                $mid['avatar_small'] = Service::avatar_small($mid['avatar']);
                $mid['guid'] = !empty($userinfo['guid']) ? $userinfo['guid'] : '';
                $mid['username'] = !empty($userinfo['username']) ? $userinfo['username'] : '';
                $mid['intro'] = !empty($userinfo['intro']) ? $userinfo['intro'] : '';
                $data[] = $mid;
            }
            ApiRedis::SetApiData($key, $data, 180);
        }
        return $this->success($data, 'success', $check['version_code']);
    }

    /**
     * 刷新融云token
     */
    public function actionChangerongtoken()
    {
        $check = Service::authorization();
        if (empty($check)) {
            return $this->error("Missing a required parameter.");
        }
        $this->checkuser($check['user_id'], $check['token'], $check['version_code']);
        $user = Service::userinfo($check['user_id']);

        if (empty($user)) {
            return $this->error('Your user account not find. For questions, email ' . SystemConstant::SystemEmail);
        }
        if (empty($user['guid']) || empty($user['avatar']) || empty($user['username'])) {
            return $this->error('Lack of necessary user information.');
        }
        $token = Rongyun::getRongYunToken(['guid' => $user['guid'], 'avatar' => $user['avatar'], 'username' => $user['username']]);
        if ($token) {
            return $this->success(['rong_token' => $token], 'success', $check['version_code']);
        } else {
            return $this->error('Flush fail.');
        }
    }

    /**
     * 用户上传资源
     */
    public function actionUploadmedia()
    {
        $postdata = file_get_contents("php://input");
        \Yii::info('user/uploadmedia------' . $postdata, 'interface');
        $check = Service::authorization();
        if (empty($check)) {
            return $this->error("Missing a required parameter.");
        }
        $user_id = $check['user_id'];
        $token = $check['token'];
        $device_type = $check['device_type'];
        $device_id = $check['device_id'];
        $version_code = $check['version_code'];
        $type = isset($_POST['type']) ? $_POST['type'] : '';
        $channel_id = isset($_POST['channel_id']) ? $_POST['channel_id'] : 'other';
        if (empty($type)) {
            return $this->error("Type required.");
        }
        if (!in_array($type, ['idol_matching', 'video', 'avatar', 'feed', 'chat', 'group_avatar', 'broadcast', 'report', 'comment', 'bg_image', 'voice', 'audio', 'skill', 'barrage', 'applog'])) {
            return $this->error("Type error.");
        }
        if (empty($user_id)) {
            $user_id = Service::random_hash(8);
        }
        $this->checkuser($user_id, $token, $version_code);
        $upload_dir = \Yii::$app->params['upload_dir'] ?? '';//上传文件的存放路径
        $url = \Yii::$app->params["aws"]["url"] ?? '';
        if (empty($upload_dir) || empty($url)) {
            return $this->error("Missing configuration parameters,please email " . SystemConstant::SystemEmail . ".");
        }
        if ($type == 'barrage') {
            if ($channel_id == 'other' || empty($channel_id)) {
                return $this->error("Wrong broadcast room parameter request.");
            }
            //验证用户权限
            $broadcastInfo = Channel::getbroadcastInfo($channel_id, ['channel_id', 'user_id', 'manager', 'live_type']);
            if (empty($broadcastInfo['user_id'])) {
                return $this->error("Not found the livestreams.");
            }
            $host_id = $broadcastInfo["user_id"];
            if (!ChannelModerators::CheckModerators($host_id, $user_id, $broadcastInfo)) {
                return $this->error("You're not the live moderators.");
            }
        }
        $array = $list = [];

        // Yii::info('$_FILES:'.json_encode($_FILES),'my');
        //上传的是视频
        $ca_max_upload = ceil(SystemConstant::Max_Upload_Size / 1000000);
        if ($type == 'video') {
            $video = isset($_FILES['video_file']) ? $_FILES['video_file'] : [];
            if (empty($video)) {
                return $this->error("Please select a video file.");
            }
            $video_name = $video['name'];
            $video_size = $video['size'];
            if ($video_size > SystemConstant::Max_Upload_Size) {
                return $this->error('Sorry,Please upload files below ' . $ca_max_upload . 'M.');
            }
            $video_tmp = $video['tmp_name'];
            $ress = ImageUtil::uploadImg($video_name, $video_size, $video_tmp, $upload_dir, $url, $user_id, $device_type, $device_id, 'video', null, 2000000000, 'vedio');
            $array['video'] = $ress['img_url'] ?? '';
            if (empty($array['video'])) {
                return $this->error('video upload fail.');
            }
            if (isset($_FILES['cover_image'])) {

                $file = $_FILES['cover_image'];
                $file_name = $file['name'];
                $file_size = $file['size'];
                $file_tmp = $file['tmp_name'];
                $array['cover_image'] = ImageUtil::uploadImg($file_name, $file_size, $file_tmp, $upload_dir, $url, $user_id, $device_type, $device_id, 'cover', 0);
            }
            $list[] = $array;
        }
        //语音
        //audio 给用个人介绍使用,voice feed使用
        if ($type == 'audio' || $type == 'voice') {
            $file = $_FILES['audio'] ?? [];
            if (empty($file)) {
                return $this->error("Please select a video file.");
            }
            $file_name = $file['name'];
            $file_size = $file['size'];
            if ($file_size > SystemConstant::Max_Upload_Size) {
                return $this->error('Sorry,Please upload files below ' . $ca_max_upload . 'M.');
            }
            $file_tmp = $file['tmp_name'];
            $ress = ImageUtil::uploadImg($file_name, $file_size, $file_tmp, $upload_dir, $url, $user_id, $device_type, $device_id, $type, 0, 1073741824, null, ['amr', 'mp3'], false, false);
            $list[]['audio'] = $ress['img_url'] ?? '';
        }
        //app 日志文件
        if ($type == 'applog') {
            $buket = $type . '/' . $user_id;
            $file = $_FILES['log'] ?? [];
            if (empty($file)) {
                return $this->error("Please select a log file.");
            }
            $file_name = $file['name'];
            $file_size = $file['size'];

            $file_tmp = $file['tmp_name'];
            $ress = ImageUtil::uploadImg($file_name, $file_size, $file_tmp, $upload_dir, $url, $user_id, $device_type, $device_id, $buket, 0, 1073741824, null, ['log'], false, false);
            $list[]['log'] = $ress['img_url'] ?? '';
            if (!empty($ress['img_url'])) {
                //记录到数据库
                UserSendAppLog::rememberOne($check, $ress['img_url']);
            }
        }
        $size = 0;
        //上传的是图片
        $buket = $type;
        if ($type == 'avatar') {
            $size = Service::getThumbnailSize($type);
            $buket = 'avatar';
        }
        if ($type == 'chat') {
            $buket = date('Y') . '/' . date('n');
        }
        if ($type == 'barrage') {
            $buket = $type . '/' . $channel_id;
        }

        if (in_array($type, ['feed', 'avatar', 'chat', 'group_avatar', 'broadcast', 'report', 'comment', 'bg_image', 'idol_matching', 'skill', 'barrage'])) {
            if (!isset($_FILES['image_file']) || empty($_FILES['image_file'])) {
                return $this->error('please file selected');
            }
            $file = $_FILES['image_file'] ?? [];
            $name = $file['name'];
            ksort($name);
            \Yii::info('user/uploadmedia: ---ksort---' . $user_id . json_encode($name, JSON_UNESCAPED_UNICODE), 'interface');
            if (empty($file)) {
                return $this->error('No file selected');
            }


            foreach ($file['size'] as $value) {
                if ($value > SystemConstant::Max_Upload_Size) {
                    return $this->error('Sorry,Please upload files below ' . $ca_max_upload . 'M.');
                    break;
                }
            }

            foreach ($name as $k => $item) {
                $array = [];
                if (($file['size'][$k] <= 0)) {
                    continue;
                }
                $up_res = ImageUtil::uploadImg($item, $file['size'][$k], $file['tmp_name'][$k], $upload_dir, $url, $user_id, $device_type, $device_id, $buket, $size);
                $array['image'] = $up_res['img_url'] ?? '';
                $thum_img_url = $up_res['thum_img_url'] ?? '';
                if ($buket != 'avatar' && !empty($thum_img_url)) {
                    $array['image_small'] = $thum_img_url;
                } else {
                    $array['image_small'] = $up_res['img_url'] ?? '';
                }
                //$array['image_small'] = $up_res;
                if (!$array['image']) {
                    return $this->error('Image format is incorrect');
                }
                $list [] = $array;
            }
        }
        return $this->success($list, 'Success', $check['version_code']);
    }

    /**
     * 修改个人头像
     * @return [type] [description]
     */
    public function actionUpdateavatar()
    {
        $postdata = $this->parameter;
        $check = Service::authorization();
        if (empty($check)) {
            return $this->error("Missing a required parameter.");
        }
        $user_id = $check['user_id'];
        $token = $check['token'];
        $image_file = isset($postdata['avatar_url']) ? trim($postdata['avatar_url']) : '';
        //参数校验
        if (empty($image_file)) {
            return $this->error('Avatar required');
        }
        $this->checkuser($user_id, $token, $check['version_code']);
        $user = User::find()->select(['id', 'guid'])->where(["status" => "normal", "guid" => $user_id])->one();
        $user->avatar = $image_file;
        if (!$user->save()) {
            return $this->error('Avatar modification failed.');
        }
        Rongyun::reflushUserInfo($user_id, ['avatar' => $user->avatar]);
        //修改Redis
        Service::reloadUser($user->guid, ['avatar' => Service::getCompleteUrl($user->avatar)]);
        return $this->success($image_file, 'Success', $check['version_code']);
    }

    /**
     * 获取用户信息
     * @return [type] [description]
     */
    public function actionPeek()
    {
        $postdata = $this->parameter;
        $check = Service::authorization();
        if (empty($check)) {
            return $this->error("Missing a required parameter.");
        }
        $query_id = isset($postdata['query_id']) ? trim($postdata['query_id']) : '';
        $user_id = $check['user_id'];
        $token = $check['token'];
        $this->checkuser($user_id, $token, $check['version_code']);
        //windows来的用户
        $pos = strripos($query_id, "_");
        if ($pos !== false) {
            $query_id = substr($query_id, $pos + 1);
        }

        if (empty($query_id)) {
            $query_id = $user_id;
        }
        if (!Serviceuser::userIsExists($query_id)) {
            return $this->error("User not found.");
        }
        $userinfo = Service::userinfo($query_id, 'peek', $user_id);
        //返头部token,不要返redis里面最新的
        $userinfo['token'] = $token;
        $userinfo['type'] = intval($userinfo['type'] ?? User::RegularUserType);

        if ($query_id == $user_id && UserRedis::UserExists($query_id)) {
            //记录用户最近一次获取自己的信息时间戳，来当最近上线时间
            UserRedis::setUserInfo($query_id, ['recent_activity_time' => time()]);
        }

        try {
            //获取正在直播的信息
            $live_info = [];
            $live_info = Channel::getUserLiveInfoById($query_id, ['tags', 'title']);
            if (!empty($live_info)) {
                if (isset($live_info['title'])) {
                    $live_info['room_title'] = $live_info['title'];
                    unset($live_info['title']);
                }
                $live_info['tags'] = empty($live_info['tags']) ? 'I am chatting' : $live_info['tags'];
                $userinfo = array_merge($userinfo, $live_info);
            }

        } catch (\Exception $e) {
        }

        return $this->success($userinfo, 'Get user information successfully', $check['version_code']);
    }

    /**
     * 版本检查
     */
    public function actionVersionCheck()
    {
        $link = Yii::$app->params['update_link'] ?? "";
        $check = Service::authorization();
        if (empty($check)) {
            return $this->error("Missing a required parameter.");
        }
        $current_version = UserRedis::getUserInfo($check['user_id'], 'current_version');
        if (!empty($current_version)) {
            if ($current_version < $check['version_code']) {
                UserRedis::setUserInfo($check['user_id'], ['current_version' => $check['version_code']]);
            }
        } else {
            UserRedis::setUserInfo($check['user_id'], ['current_version' => $check['version_code']]);
        }
        $version_info = UserRedis::getVersion(strtolower($check['device_type']));
        if (!empty($version_info)) {
            if ($check['version_code'] < $version_info['version']) {
                //0强制更新
                if ($version_info['is_force'] == 0) {
                    return $this->success(['type' => 0, 'content' => 'We continuously update the app for general and stability improvements.', 'title' => 'An update for app is available.', 'link' => $version_info['url']], 'New version available.Must update your app');
                }
                return $this->success(['type' => 1, 'content' => 'We continuously update the app for general and stability improvements.', 'title' => 'An update for app is available.', 'link' => $version_info['url']], 'New version available. Please update your app');
            }
        }
        return $this->success(['type' => 2, 'content' => 'Success', 'title' => 'Update Available', 'link' => $link], 'Success');
    }

    /**
     * 退出登录
     * @return [type] [description]
     */
    public function actionLogout()
    {
        $check = Service::authorization();
        if (empty($check)) {
            return $this->error("Missing a required parameter.");
        }
        $user_id = $check['user_id'];
        $token = $check['token'];
        if (empty($token)) {
            return $this->error("Token required.");
        }
        $this->checkuser($user_id, $token);
        $user = User::find()->select(['id', 'guid'])->where(['guid' => $user_id])->one();
        if (empty($user)) {
            return $this->success(null, "You have been safely logged out.");
        }
        $usertoken = UserToken::find()->where(['token' => $token])->one();
        if (!empty($usertoken)) {
            $usertoken->token = null;
            $usertoken->save();
        }
        TokenRedis::Set($user->guid, "");
        return $this->success(null, 'You have been safely logged out.', $check['version_code']);
    }

    /**
     * 邮箱验证
     */
    public function actionEmailValidate()
    {
        $postdata = $this->parameter;
        $check = Service::authorization();
        if (empty($check)) {
            return $this->error("Missing a required parameter.");
        }
        $device_type = $check['device_type'];
        $device_id = $check['device_id'];

        $email = $postdata["email"] ?? "";
        if (empty($email)) {
            return $this->error("Email is required.");
        }
        $response = Agora::AgoraEmailCheck(["email" => ($email)]);
        //根据post返回的code,  200是正常普通邮箱, 201是管理员邮箱, 404是不合法邮箱. -- sam
        if (!$response) {
            return $this->error("Email check fail.");
        }
        $data = $response["data"];

        if(isset($data["success"]) && !$data["success"]){
            return $this->error("Verify fail.");
        }
        $uuid = $data["uuid"] ?? Service::create_guid();

        $user = User::find()->where(["guid" => ($uuid)])->one();
        if(empty($user)){
            $user = User::find()->where(["email" => ($email)])->one();
        }
        $token = Service::create_token($uuid, "password");
        if(!empty($user)){
            $uuid = $user->guid;
        }
        if (!Service::save_token($uuid, $token)) {
            return $this->error(ResponseMessage::STSTEMERROR);
        }
        if (!empty($user)) {
            UserLoginLog::add_login_log($user->guid, $device_type, $device_id);
            $userinfo = Service::userinfo($user->guid, 'register', $user->guid);
            if (!$userinfo) {
                return $this->error("Email error.");
            }
            return $this->success($userinfo);
        }
        $ip = Util::get_ip();
        $user = new User();
        $user->guid = $uuid;
        $user->status = "normal";
        $user->avatar = User::GetDefaultAvatar();
        $user->regist_ip = $ip;
        $user->login_ip = $ip;
        switch ($response["code"]) {
            case 200:
                $user->type = 0;
                break;
            case 201:
                $user->type = 1;
                break;
            case 404:
            default:
                return $this->error("Illegal registered email!");
        }
        $user->email = ($email);
        if (!$user->save()) {
            Yii::error('user save fail code: 3' . json_encode($user->getErrors()));
            return $this->error("Data save fail! code: 3");
        }
//        $user->username = 'user' . $user->id;
//        $user->username_change = 0;
//        if (!$user->save()) {
//            Yii::error('user save fail code: 6'. json_encode($user->getErrors()));
//            return $this->error("Data save fail! code: 6");
//        }
        //保存到Redis
        UserLoginLog::add_login_log($user->guid, $device_type, $device_id);
        $userinfo = Service::userinfo($user->guid, 'register', $user->guid);
        if (!$userinfo) {
            Yii::error('user/login register failed------' . json_encode($userinfo, JSON_UNESCAPED_UNICODE), 'my');
            return $this->error("Data save fail! code: 9");
        }
        $this->success($userinfo);
    }

    /**
     * 获取username选项
     */
    public function actionUsernameOption()
    {
        $postdata = $this->parameter;
        $check = Service::authorization();
        if (empty($check)) {
            return $this->error("Missing a required parameter.");
        }
        $first_name = $postdata['first_name'] ?? "";
        $last_name = $postdata['last_name'] ?? "";
        if (empty($first_name) || empty($last_name)) {
            return $this->error("Firstname or Lastname required");
        }
        $username = substr($first_name, 0, 10) . substr($last_name, 0, 3);
        $data = [];
        for ($i = 0; $i < 3; $i++) {
            $data[] = $username . rand(10, 99);
        }
        return $this->success($data);
    }

}
