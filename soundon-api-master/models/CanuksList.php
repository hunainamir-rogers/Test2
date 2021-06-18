<?php

namespace app\models;

use Yii;

/**
 * This is the model class for table "canuks_list".
 *
 * @property int $id
 * @property string|null $name 球队名称
 * @property string|null $logo 球队图标
 * @property int|null $weight 球队排序
 * @property string|null $created_at
 * @property string|null $updated_at
 */
class CanuksList extends \yii\db\ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'canuks_list';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['weight'], 'integer'],
            [['created_at', 'updated_at'], 'safe'],
            [['name'], 'string', 'max' => 30],
            [['logo'], 'string', 'max' => 255],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'name' => 'Name',
            'logo' => 'Logo',
            'weight' => 'Weight',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
        ];
    }

    /**
     * 获取冰球球队信息
     * @param $canuksId
     * @return array|\yii\db\ActiveRecord|null
     */
    public static function GetOne($canuksId){
        $followCanuksInfo = CanuksList::find()->select("id,name,logo")->where(["id" =>$canuksId])->asArray()->one();
        if(empty($followCanuksInfo)){
            return [];
        }
        return $followCanuksInfo;
    }
}
