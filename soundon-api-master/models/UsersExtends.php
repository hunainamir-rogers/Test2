<?php

namespace app\models;

use app\components\Util;
use Yii;

/**
 * This is the model class for table "users_extends".
 *
 * @property int $id
 * @property string|null $guid 用户guid
 * @property string|null $speech_intro 语音介绍地址
 * @property int $duration 语音介绍时长
 * @property int $is_test 是不是测试用户,0不是
 * @property string|null $follow_canuks 关注的冰球球队id,逗号隔开
 */
class UsersExtends extends \yii\db\ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'users_extends';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['duration', 'is_test'], 'integer'],
            [['guid'], 'string', 'max' => 64],
            [['speech_intro'], 'string', 'max' => 200],
            [['follow_canuks'], 'string', 'max' => 255],
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
            'speech_intro' => 'Speech Intro',
            'duration' => 'Duration',
            'is_test' => 'Is Test',
            'follow_canuks' => 'Follow Canuks',
        ];
    }

    /**向用户扩展表插入数据
     * @param string $user_guid
     * @param array $params example: ['idfa'=>'cxcxcxcxcx','skills_count'=>3]
     * @return bool
     */
    public static function RememberUserExtendsInfo($user_guid = '', $params = [])
    {
        if ($user_guid && $params) {
            try {
                $masterDb = Util::GetMasterDb();
                $model = self::find()->where(['guid' => $user_guid])->one($masterDb);
                if (empty($model)) {
                    $model = new UsersExtends();
                    $model->guid = $user_guid;
                }
                foreach ($params as $k => $v) {
                    $model->$k = $v;
                }
                if ($model->save()) {
                    return true;
                }
            } catch (\Exception $E) {
                Yii::error("user_id $user_guid extends info update fail!".$E->getMessage());
                return false;
            }
        }
        return false;
    }
}
