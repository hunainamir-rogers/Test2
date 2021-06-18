<?php

namespace app\models;

use Yii;

/**
 * This is the model class for table "friends".
 *
 * @property integer $id
 * @property string $user_id
 * @property string $friend_id
 * @property string $last_seen
 * @property string $stories_privacy
 * @property integer $mute_chat_time
 * @property string $status
 * @property string $ip
 * @property string $device_type
 * @property string $device_id
 * @property string $created_at
 * @property string $updated_at
 */
class Friends extends \yii\db\ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'friends';
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['user_id', 'friend_id'], 'required'],
            [['last_seen', 'stories_privacy', 'status', 'device_type'], 'string'],
            [['mute_chat_time'], 'integer'],
            [['created_at', 'updated_at'], 'safe'],
            [['user_id', 'friend_id'], 'string', 'max' => 64],
            [['ip'], 'string', 'max' => 50],
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
            'friend_id' => 'Friend ID',
            'last_seen' => 'Last Seen',
            'stories_privacy' => 'Stories Privacy',
            'mute_chat_time' => 'Mute Chat Time',
            'status' => 'Status',
            'ip' => 'Ip',
            'device_type' => 'Device Type',
            'device_id' => 'Device ID',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
        ];
    }
}
