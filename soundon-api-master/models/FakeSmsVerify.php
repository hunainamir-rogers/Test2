<?php

namespace app\models;

use Yii;

/**
 * This is the model class for table "FakeSmsVerify".
 *
 * @property int $id
 * @property string $cellphone
 * @property string $sms_code
 * @property int $response_time
 * @property int $verify_time
 * @property int $lock
 * @property string $created_at
 * @property string $updated_at
 */
class FakeSmsVerify extends \yii\db\ActiveRecord
{


    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'fake_sms_verify';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['cellphone'], 'required'],
            [['cellphone'], 'string'],
            [['cellphone'], 'unique'],
            [['response_time', 'verify_time', 'lock'], 'integer'],
            [['created_at', 'updated_at', 'sms_code'], 'safe'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'cellphone' => 'Phone',
            'sms_code' => 'Sms Code',
            'response_time' => 'Response Time',
            'verify_time' => 'Verify Time',
            'lock' => 'Lock',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
        ];
    }


    public static function EmailFakeVerifyCode($phone){
        $code = Service::creareFakePhoneCode($phone);
        if (!$code){
            return false;
        }
        return true;
    }

}
