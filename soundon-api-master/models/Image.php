<?php

namespace app\models;

use Yii;

/**
 * This is the model class for table "image".
 *
 * @property integer $id
 * @property string $guid
 * @property string $path
 * @property string $url
 * @property string $type
 * @property integer $width
 * @property integer $height
 * @property integer $size
 * @property string $ip
 * @property string $device_type
 * @property string $device_id
 * @property string $created_by
 * @property string $created_at
 */
class Image extends \yii\db\ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'image';
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['guid', 'path', 'url', 'type', 'width', 'height', 'size', 'ip', 'device_type', 'created_by'], 'required'],
            [['type', 'device_type'], 'string'],
            [['width', 'height', 'size'], 'integer'],
            [['created_at'], 'safe'],
            [['guid', 'created_by'], 'string', 'max' => 64],
            [['path'], 'string', 'max' => 250],
            [['url', 'device_id'], 'string', 'max' => 500],
            [['ip'], 'string', 'max' => 50],
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'guid' => 'Guid',
            'path' => 'Path',
            'url' => 'Url',
            'type' => 'Type',
            'width' => 'Width',
            'height' => 'Height',
            'size' => 'Size',
            'ip' => 'Ip',
            'device_type' => 'Device Type',
            'device_id' => 'Device ID',
            'created_by' => 'Created By',
            'created_at' => 'Created At',
        ];
    }
}
