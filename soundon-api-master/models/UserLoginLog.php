<?php

namespace app\models;

use app\components\Util;
use Yii;

/**
 * This is the model class for table "user_login_log".
 *
 * @property string $id
 * @property string $user_id
 * @property string $login_ip
 * @property string $device_type
 * @property string $device_id
 * @property string $ua
 * @property string $created_at
 */
class UserLoginLog extends \yii\db\ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'user_login_log';
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['user_id'], 'required'],
            [['device_type'], 'string'],
            [['created_at'], 'safe'],
            [['user_id'], 'string', 'max' => 64],
            [['login_ip'], 'string', 'max' => 50],
            [['device_id'], 'string', 'max' => 500],
            [['ua'], 'string', 'max' => 200],
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'user_id' => 'User ID',
            'login_ip' => 'Login Ip',
            'device_type' => 'Device Type',
            'device_id' => 'Device ID',
            'ua' => 'Ua',
            'created_at' => 'Created At',
        ];
    }

    /**
     * 用户登录日志
     * @param $guid
     * @param string $device_type
     * @param null $device_id
     * @return bool
     */
    public static function add_login_log($guid, $device_type = 'ios', $device_id = null)
    {
        $log = new UserLoginLog();
        $log->user_id = $guid;
        $log->device_type = $device_type;
        $log->device_id = $device_id;
        $log->login_ip = Util::get_ip();
        return $log->save();
    }
}
