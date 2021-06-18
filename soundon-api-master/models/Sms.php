<?php

namespace app\models;

use Yii;

/**
 * This is the model class for table "sms".
 *
 * @property integer $id
 * @property string $user_id
 * @property string $cellphone
 * @property string $verification_code
 * @property string $content
 * @property string $request_ip
 * @property string $type
 * @property string $device_type
 * @property string $device_id
 * @property string $status
 * @property string $created_at
 * @property string $updated_at
 */
class Sms extends \yii\db\ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'sms';
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['content', 'type', 'device_type', 'status'], 'string'],
            [['created_at', 'updated_at'], 'safe'],
            [['user_id'], 'string', 'max' => 64],
            [['cellphone', 'verification_code', 'request_ip'], 'string', 'max' => 50],
            [['device_id'], 'string', 'max' => 100],
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
            'cellphone' => 'Cellphone',
            'verification_code' => 'Verification ResponseCode',
            'content' => 'Content',
            'request_ip' => 'Request Ip',
            'type' => 'Type',
            'device_type' => 'Device Type',
            'device_id' => 'Device ID',
            'status' => 'Status',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
        ];
    }

    public static function Test(){

    }
}
