<?php

namespace app\models;

use app\components\Agora;
use app\components\Email;
use app\components\define\ResponseCode;
use app\components\redis\UserRedis;
use app\components\define\ResponseMessage;
use app\components\ResponseTool;
use app\components\define\SystemConstant;
use app\components\Rongyun;
use app\components\Words;
use Yii;
use yii\web\Response;

/**
 * This is the model class for table "user".
 *
 * @property int $id
 * @property string $guid
 * @property string|null $email
 * @property string|null $email_status
 * @property string|null $title 用户描述
 * @property string|null $username
 * @property string|null $nickname
 * @property int|null $date_of_birth 生日
 * @property int|null $type 0:普通用户 1:主播
 * @property string|null $intro
 * @property string|null $password
 * @property string|null $salt
 * @property string|null $country_code 国家代码
 * @property string|null $cellphone
 * @property string|null $cellphone_number
 * @property string|null $avatar
 * @property string $gender 0：男 1：女 2:变性人 3:其他
 * @property string|null $status
 * @property string|null $deleted
 * @property string|null $device_type
 * @property string|null $device_id
 * @property float|null $cash
 * @property int|null $coin 金币
 * @property int $point 用户point
 * @property int|null $diamond 钻石
 * @property int $live 在平台播放过多少次
 * @property string|null $is_vip
 * @property int|null $level
 * @property int $earn_diamond
 * @property int|null $cost_coin 花的coin
 * @property int|null $cost_point
 * @property int $follower 粉丝数量
 * @property int $following 我关注别人的数量
 * @property string|null $location
 * @property string|null $locationxy 经度,纬度
 * @property int $login_count
 * @property string|null $website
 * @property int $exp 经验值
 * @property int $username_change
 * @property string $created_at
 * @property string $updated_at
 * @property float|null $freeze_balance 冻结的余额
 * @property float|null $total_bonus
 * @property string|null $country 国家代码
 * @property string|null $regist_ip 注册ip
 * @property string|null $login_ip 登录ip
 * @property string|null $rong_token 融云token
 */
class User extends \yii\db\ActiveRecord
{

    //用户类型
    const RegularUserType = 0; //普通用户
    const InfluenceUserType = 1; //influence用户
    const GuestUserType = 2; //来宾用户
    const SendUserType = 3;//种子用户
    const DevsUserType = 4;//开发内部用户

    const SECRECY_GENDER = '2';//保密类别的性别
    const GUEST_EXPIRE_TIME = 1296000;//游客过期时间

    //用户直播状态
    const UserLiving = 1;  //正在直播中
    const UserNoLive = 2;  //没在直播

    const LOG_NAME = 'user';

    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'user';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['guid'], 'required'],
            [['date_of_birth', 'type', 'coin', 'point', 'diamond', 'live', 'level', 'earn_diamond', 'cost_coin', 'cost_point', 'follower', 'following', 'login_count', 'exp', 'username_change'], 'integer'],
            [['gender', 'status', 'deleted', 'device_type', 'is_vip'], 'string'],
            [['cash', 'freeze_balance', 'total_bonus'], 'number'],
            [['created_at', 'updated_at'], 'safe'],
            [['guid'], 'string', 'max' => 64],
            [['email'], 'string', 'max' => 320],
            [['email_status', 'country_code', 'first_name', 'last_name'], 'string', 'max' => 20],
            [['title', 'cellphone', 'cellphone_number', 'regist_ip', 'login_ip'], 'string', 'max' => 50],
            [['username'], 'string', 'max' => 64],
            [['nickname'], 'string', 'max' => 200],
            [['intro', 'avatar', 'location'], 'string', 'max' => 500],
            [['password', 'rong_token'], 'string', 'max' => 255],
            [['salt'], 'string', 'max' => 8],
            [['device_id', 'locationxy', 'website'], 'string', 'max' => 100],
            [['country'], 'string', 'max' => 5],
            [['guid'], 'unique'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'guid' => 'Guid',
            'email' => 'Email',
            'email_status' => 'Email Status',
            'title' => 'Title',
            'username' => 'Username',
            'nickname' => 'Nickname',
            'date_of_birth' => 'Date Of Birth',
            'type' => 'Type',
            'intro' => 'Intro',
            'password' => 'Password',
            'salt' => 'Salt',
            'country_code' => 'Country Code',
            'cellphone' => 'Cellphone',
            'cellphone_number' => 'Cellphone Number',
            'avatar' => 'Avatar',
            'gender' => 'Gender',
            'status' => 'Status',
            'deleted' => 'Deleted',
            'device_type' => 'Device Type',
            'device_id' => 'Device ID',
            'cash' => 'Cash',
            'coin' => 'Coin',
            'point' => 'Point',
            'diamond' => 'Diamond',
            'live' => 'Live',
            'is_vip' => 'Is Vip',
            'level' => 'Level',
            'earn_diamond' => 'Earn Diamond',
            'cost_coin' => 'Cost Coin',
            'cost_point' => 'Cost Point',
            'follower' => 'Follower',
            'following' => 'Following',
            'location' => 'Location',
            'locationxy' => 'Locationxy',
            'login_count' => 'Login Count',
            'website' => 'Website',
            'exp' => 'Exp',
            'username_change' => 'Username Change',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
            'freeze_balance' => 'Freeze Balance',
            'total_bonus' => 'Total Bonus',
            'country' => 'Country',
            'regist_ip' => 'Regist Ip',
            'login_ip' => 'Login Ip',
            'rong_token' => 'Rong Token',
            'first_name' => 'First Name',
            'last_name' => 'Last Name',
        ];
    }


    public static function GetDefaultAvatar()
    {
        return Yii::$app->params["default_avatar"];
    }

    /**
     * 生成密码
     * @param $guid
     * @param $password
     * @param $salt
     * @return false|string|null
     */
    public static function GeneratePassword($guid, $password, $salt)
    {
        if (empty($password)) {
            return false;
        }
        return password_hash("mix" . $guid . $password . $salt, PASSWORD_DEFAULT);
    }

    /**
     * 密码比对
     * @param $guid
     * @param $password
     * @param $salt
     * @param $passwordHash
     * @return bool true: 成功, false: 失败
     */
    public static function VerifyPassword($guid, $password, $salt, $passwordHash)
    {
        return password_verify("mix" . $guid . $password . $salt, $passwordHash);
    }

    public static function Register($email, $password, $device_type, $device_id, $ip)
    {
        if (empty($password)) {
            $password = Service::random_hash();
        }
        $salt = Service::random_hash(6);
        $user = new User;
        $guid = Service::GenerateUniqueUserGuid();//create_guid();
        $user->guid = $guid;
        $user->username = $guid;
        $user->email = $email;
        $passwordHash = self::GeneratePassword($guid, $password, $salt);//注册
        if (!$passwordHash) {
            Yii::error("user/login register GeneratePassword failed  email: {$email} ------", 'my');
            return false;
        }
        $user->salt = $salt;
        $user->password = $passwordHash;
        $user->device_type = $device_type;
        $user->device_id = $device_id;
        $user->avatar = self::GetDefaultAvatar();
        //  $user->oauth_provider = 'cellphone';
        $user->login_count = 1;
        $user->status = 'normal';//测试时设为normal  正式为unactivated
        $user->regist_ip = $ip;
        $user->login_ip = $ip;
        $response = Agora::AgoraEmailCheck(["email" => md5($email)]);
        //根据post返回的code,  200是正常普通邮箱, 201是管理员邮箱, 404是不合法邮箱. -- sam
        if(!$response){
            ResponseTool::SetMessage("Email check fail!");
            return false;
        }
        switch ($response["code"]){
            case 200:
                $user->type = 0;
                break;
            case 201:
                $user->type = 1;
                break;
            case 404:
            default:
                ResponseTool::SetMessage("Illegal registered email!");
                return false;
        }


        $token = Service::create_token($user->guid, $password);
        $transaction = Yii::$app->db->beginTransaction();
        try {
            if (Service::save_token($user, $token)) {
                if ($user->save()) {
                    $user->nickname = 'User ' . $user->id;
                    $user->username = 'user' . $user->id;
                    //生成融云token
                    $user->rong_token = Rongyun::getRongYunToken(["guid" => $guid, "username" => $user->username, "avatar" => $user->avatar]);
                    $user->save();
                    //保存到Redis
                    UserLoginLog::add_login_log($user->guid, $device_type, $device_id);
                    $transaction->commit();
                    $userinfo = Service::userinfo($user->guid, 'register', $user->guid);
                    if ($userinfo) {
                        return $userinfo;
                    } else {
                        Yii::error('user/login register failed------' . json_encode($userinfo, JSON_UNESCAPED_UNICODE), 'my');
                        return false;
                    }
                } else {
                    Yii::error('user/login DB error------' . json_encode($user->getErrors(), JSON_UNESCAPED_UNICODE), 'interface');
                    return false;
                }
            } else {
                return false;
            }
        } catch (\Exception $e) {
            $transaction->rollBack();
            Yii::error($e->getMessage());
            return false;
        }
    }

    public static function login($user, $postdata, $device_id, $device_type, $ip, $version)
    {
        $password = isset($postdata['password']) ? $postdata['password'] : '';
        if (empty($password)) {
            return false;
        }
        //用户被冻结
        if ($user->status == 'lock') {
            ResponseTool::SetErr(ResponseCode::UserIslocked, ResponseMessage::UserIsLocked);
            return false;
        }
        //密码验证
        if (!self::VerifyPassword($user->guid, $password, $user->salt, $user->password)) {
            ResponseTool::SetMessage("Password incorrect");
            return false;
        }
        $token = Service::create_token($user->guid, $device_id);

        if (Service::save_token($user, $token)) {
            $user->login_count += 1;
            $user->device_type = $device_type;
            $user->device_id = $device_id;
            $user->login_ip = $ip;
            $user->rong_token = Rongyun::getRongYunToken(["guid" => $user->guid, "username" => $user->username, "avatar" => $user->avatar]);
            if (!$user->save()) {
                Yii::info('user/login save to mysql failed------' . json_encode($user->getErrors(), JSON_UNESCAPED_UNICODE), 'my');
                return false;
            }
            UserLoginLog::add_login_log($user->guid, $device_type, $device_id);
            $userinfo = Service::userinfo($user->guid, 'login', $user->guid);
            if ($userinfo) {
                return $userinfo;
            } else {
                Yii::info('user/login reloadUser failed------' . json_encode($user->guid, JSON_UNESCAPED_UNICODE), 'my');
                return false;
            }
        } else {
            Yii::info('user/login save token failed------', 'my');
            return false;
        }
    }


    /**
     * 判断是否为测试用户
     * @param $guid
     * @return bool
     */
    public static function IsTestUser($guid)
    {
        if (UserRedis::IsTestUser($guid)) {
            return true;
        }
        return false;
    }


    public static function EmailCodeVerify($email, $code, $type = "register")
    {
        $cellphonecode = Sms::find()->select("id,verification_code,retry,created_at")->where(["cellphone" => $email, "status" => "false", "type" => $type])->orderBy("id desc")->one();
        if (empty($cellphonecode)) {
            return false;
        }
        if ($cellphonecode->retry >= 3) {
            return false;
        }
        if (trim($cellphonecode->verification_code) != trim($code)) {
            $cellphonecode->retry += 1;
            if (!$cellphonecode->save()) {
                return false;
            }
            return false;
        }
        if ((strtotime($cellphonecode->created_at) + 60 * 5) < time()) {
            return false;
        }
        $cellphonecode->status = "true";
        if (!$cellphonecode->save()) {
            return false;
        }
        return true;
    }

    public static function SendVerifyCodeInterval($cellphone, $type = "login")
    {
        $cellphonecode = Sms::find()->select("id,verification_code,retry,created_at")->where(["cellphone" => $cellphone, "type" => $type])->orderBy("id desc")->one();
        if (empty($cellphonecode)) {
            return true;
        }
        if ((strtotime($cellphonecode->created_at) + 60 * 5) > time()) {//5分钟之内不能重新发验证码
            return false;
        }
        return true;
    }

    public static function EmailSentCode($cellphone, $guid, $email, $way, $request_ip, $device_type, $device_id)
    {
        $code = str_pad(mt_rand(0, 999999), 6, "0", STR_PAD_BOTH);
        $sms = new Sms();
        $sms->user_id = $guid;
        $sms->type = $way;
        $sms->cellphone = $cellphone;
        $sms->verification_code = $code;
        $sms->request_ip = $request_ip;
        $sms->device_type = $device_type;
        $sms->device_id = $device_id;
        if (!$sms->save()) {
            return false;
        }
        Service::creareFakePhoneCode($cellphone, $code);
        if (!empty($email)) {
            $subject = "verification code";
            $sendBody = "Your verification code is: $code";
            if (!Email::send($subject, $sendBody, $email)) {
                return false;
            }
        }
        return $code;
    }

    /**
     * 检查用户名是否非法
     * @param $username
     * @return bool
     */
    public static function IllegalUsername($username)
    {
        $notAllowed = explode(",", SystemConstant::usernameNotAllowed);
//        if(in_array(strtolower($username), $notAllowed)){
//            return true;
//        }
        foreach ($notAllowed as $value) {
            if (stripos($username, $value) !== false) {
                return true;
            }

        }
        return false;
    }

    /**
     * 修改用户信息
     * @param $user_id
     * @param $data
     * @return bool
     */
    public static function ModifyUserInfo($user_id, $data)
    {
        if (empty($data) || !is_array($data)) {
            return false;
        }
        $userModel = User::find()->where(["guid" => $user_id, "status" => "normal"])->one();
        if (empty($userModel)) {
            return false;
        }

        foreach ($data as $filed => $value) {
            $userModel->$filed = $value;
        }
        if (!$userModel->save()) {
            return false;
        }
        Service::reloadUser($user_id);
        return true;
    }
}
