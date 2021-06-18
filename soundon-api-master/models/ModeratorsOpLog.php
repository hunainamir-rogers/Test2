<?php

namespace app\models;
use Yii;
/**
 * This is the model class for table "channel_moderators_op_log".
 *
 * @property int $id
 * @property string $moderators_user
 * @property string $effect_user
 * @property string $channel_id
 * @property string $host_user
 * @property string $op
 * @property string $created_at
 * @property string $updated_at
 */
class ModeratorsOpLog extends \yii\db\ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'channel_moderators_op_log';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['op'], 'string'],
            [['created_at', 'updated_at'], 'safe'],
            [['moderators_user', 'effect_user', 'host_user'], 'string', 'max' => 64],
            [['channel_id'], 'string', 'max' => 32],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'moderators_user' => 'Moderators User',
            'effect_user' => 'Effect User',
            'channel_id' => 'Channel ID',
            'host_user' => 'Host User',
            'op' => 'Op',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
        ];
    }


    /**
     * 添加日志
     * @param $moderators_user
     * @param $effect_user
     * @param $op
     * @param $channel_id
     * @param $host_user
     * @return bool/int
     */
    public static function Add($moderators_user, $effect_user, $channel_id, $host_user, $op)
    {
        $model = new ModeratorsOpLog();
        $model->moderators_user = $moderators_user;
        $model->effect_user = $effect_user;
        $model->channel_id = $channel_id;
        $model->host_user = $host_user;
        $model->op = $op;
        if (!$model->save()) {
            Yii::info('ModeratorsOpLog='.json_encode($model->getErrors()),'broadcast');
            return false;
        }
        return $model->id;
    }

    /**
     * 查找一条日志记录
     * @param $log_id
     * @return mixed
     */
    public static function One($log_id)
    {
        $model = ModeratorsOpLog::findOne($log_id);
        if (empty($model)) {
            return false;
        }
        return $model;
    }
}
