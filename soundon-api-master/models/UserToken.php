<?php

namespace app\models;

use app\components\redis\TokenRedis;
use Yii;

/**
 * This is the model class for table "user_token".
 *
 * @property integer $id
 * @property string $user_id
 * @property string $token
 * @property string $updated_at
 * @property string $created_at
 */
class UserToken extends \yii\db\ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'user_token';
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['updated_at', 'created_at'], 'safe'],
            [['user_id'], 'string', 'max' => 64],
            [['token'], 'string', 'max' => 50],
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
            'token' => 'Token',
            'updated_at' => 'Updated At',
            'created_at' => 'Created At',
        ];
    }

    public static function Get($user_id){
        $token = TokenRedis::get($user_id);
        if (!empty($token)){
            return $token;
        }
        return false;
        $usertoken = UserToken::find()->where(['user_id' => $user_id])->one();
        if (empty($usertoken)) {
            return false;
        }
        if (empty($usertoken->token)){
            return false;
        }
        //reload
        TokenRedis::Set($user_id, $usertoken->token);
        return $usertoken->token;

    }
}
