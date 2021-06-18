<?php

namespace app\models;

use Yii;

/**
 * This is the model class for table "tag".
 *
 * @property int $id
 * @property int|null $parent_id
 * @property string $name
 * @property string|null $is_system
 * @property string|null $promote
 * @property string|null $status
 * @property int|null $weight 排序
 * @property string $created_at
 * @property string $updated_at
 * @property string $logo
 */
class Tag extends \yii\db\ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'tag';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['parent_id', 'weight'], 'integer'],
            [['name'], 'required'],
            [['is_system', 'promote', 'status'], 'string'],
            [['created_at', 'updated_at'], 'safe'],
            [['name'], 'string', 'max' => 180],
            [['name'], 'unique'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'parent_id' => 'Parent ID',
            'logo' => 'logo',
            'name' => 'Name',
            'is_system' => 'Is System',
            'promote' => 'Promote',
            'status' => 'Status',
            'weight' => 'Weight',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
        ];
    }
}
