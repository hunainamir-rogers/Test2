<?php

namespace app\models;

use Yii;

/**
 * This is the model class for table "follow_broadcast".
 *
 * @property integer $id
 * @property string $user_id
 * @property string $channel_id
 * @property string $is_notify
 * @property string $device_type
 * @property string $device_id
 * @property string $created_at
 * @property string $updated_at
 */
class FollowBroadcast extends \yii\db\ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'follow_broadcast';
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['user_id', 'channel_id'], 'required'],
            [['is_notify', 'device_type'], 'string'],
            [['created_at', 'updated_at'], 'safe'],
            [['user_id', 'channel_id'], 'string', 'max' => 64],
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
            'channel_id' => 'Channel ID',
            'is_notify' => 'Is Notify',
            'device_type' => 'Device Type',
            'device_id' => 'Device ID',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
        ];
    }
}
