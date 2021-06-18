<?php

namespace app\models;

use app\components\Agora;
use app\components\LiveLogic;
use app\components\Words;
use Yii;

/**
 * This is the model class for table "follow".
 *
 * @property integer $id
 * @property string $user_id
 * @property string $follow_id
 * @property integer $contribute_point
 * @property string $is_follow
 * @property string $device_type
 * @property string $device_id
 * @property string $follow_each_other
 * @property string $created_at
 * @property string $updated_at
 */
class Follow extends \yii\db\ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'follow';
    }

    // public static function getDb()
    // {
    //     return \Yii::$app->feed_db;
    // }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['user_id', 'follow_id'], 'required'],
            [['contribute_point'], 'integer'],
            [['is_follow', 'device_type', 'follow_each_other'], 'string'],
            [['created_at', 'updated_at'], 'safe'],
            [['user_id', 'follow_id'], 'string', 'max' => 64],
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
            'follow_id' => 'Follow ID',
            'contribute_point' => 'Contribute Point',
            'is_follow' => 'Is Follow',
            'device_type' => 'Device Type',
            'device_id' => 'Device ID',
            'follow_each_other' => 'Follow Each Other',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
        ];
    }

    public function afterSave($insert, $changedAttributes)
    {
        if (false) {//如果是 新增 则计数
            $data = $this->getAttributes();
//            if ($data && $data['is_follow'] != 'false'){
//                $task_id_arr = TaskLog::getTaskId('type','follow');
//                $task_id = $task_id_arr[0];
//                $list = Words::DAILY_TASK;
//                if(!empty($task_id)){
//                    $task_like = TaskLog::findOne(['user_id'=>$data['user_id'],'task_id'=>intval($task_id),'date'=>date('Y-m-d')]);
//                    if($task_like){//有任务进度
//                        if($list[intval($task_id)-1]['num'] > $task_like->complete_num){//任务进度新增
//                            $task_like->complete_num += 1;
//                            if($task_like->complete_num == $list[intval($task_id)-1]['num']){ //新增后完成
//                                $task_like->status = '2';
//                            }
//                        }else{
//                            $task_like->status = '2';
//                        }
//                    }else{//没有开始今日任务，新建
//                        $task_like = new TaskLog();
//                        $task_like->task_id = $task_id;
//                        $task_like->user_id = $data['user_id'];
//                        $task_like->date = date('Y-m-d');
//                        $task_like->complete_num = 1;
//                        if($list[intval($task_id)-1]['num'] == 1){ //如果1次就算完成
//                            $task_like->status = 2;
//                        }
//                    }
//                    $task_like->save();
//                }
//            }
        }
    }

    /**
     * follow 单点消息
     * @param $user_id
     * @param $inf_id
     * @param $content
     * @return bool
     */
    public static function FollowSystemMessage($user_id, $inf_id, $content){
        Service::log_time("user_id: $user_id, influence_id: $inf_id, content: " . $content);
        $pushData = LiveLogic::SystemMessageStruct(LiveLogic::notify_type_follow, $user_id, $inf_id, $content);
        if(empty($pushData)){
            return false;
        }
        Service::OnesignalNotification($content, array(array("field" => "tag", "key" => "guid", "relation" => "=", "value" => $inf_id)), '',$pushData);
        return Agora::JarPushSinglePointMessage($inf_id, "", Agora::SystemNotify, $pushData, true);
    }
}
