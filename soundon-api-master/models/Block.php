<?php

namespace app\models;

use app\components\redis\UserRedis;
use app\components\Util;
use Yii;

/**
 * This is the model class for table "block".
 *
 * @property integer $id
 * @property string $user_id
 * @property string $block_id
 * @property string $image_url
 * @property integer $reason
 * @property string $device_type
 * @property string $device_id
 * @property string $ip
 * @property string $created_at
 * @property string $status
 */
class Block extends \yii\db\ActiveRecord
{

    const EnableStatus = "enable";
    const DisableStatus = "disable";

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'block';
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['user_id', 'block_id', 'device_type', 'device_id', 'ip'], 'required'],
            [['reason'], 'integer'],
            [['device_type'], 'string'],
            [['created_at'], 'safe'],
            [['user_id', 'block_id'], 'string', 'max' => 64],
            [['image_url'], 'string', 'max' => 500],
            [['device_id'], 'string', 'max' => 100],
            [['ip'], 'string', 'max' => 20],
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
            'block_id' => 'Block ID',
            'image_url' => 'Image Url',
            'reason' => 'Reason',
            'device_type' => 'Device Type',
            'device_id' => 'Device ID',
            'ip' => 'Ip',
            'created_at' => 'Created At',
            'status' => 'status',
        ];
    }
    public static function block($user_id, $block_id, $device_type = "", $device_id = "", $image_url = "", $option = 0)
    {
        $masterDB = Util::GetMasterDb();
        $blockflag = Block::find()->where(['user_id' => $user_id, 'block_id' => $block_id])->one($masterDB);
        if (empty($blockflag)) {
            $block = new Block();
        }
        $block->user_id = $user_id;
        $block->block_id = $block_id;
        $block->device_type = $device_type;
        $block->device_id = $device_id;
        $block->image_url = $image_url;
        $block->reason = $option;
        $block->ip = Util::get_ip();
        $block->status = Block::EnableStatus;
        if (!$block->save()) {
            return false;
        }
        //修改数据库
        $friend = Friends::find()->where(['user_id' => $user_id, 'friend_id' => $block_id])->one();
        if (!empty($friend)) {
            $friend->status = '-1';
            $friend->save();
        }
        $friend_back = Friends::find()->where(['user_id' => $block_id, 'friend_id' => $user_id])->one();
        if (!empty($friend_back)) {
            $friend_back->status = '-1';
            $friend_back->save();
        }

        //block加入redis,friend 移除redis
        UserRedis::addBlock($user_id, $block_id);
        //block之后移除好友
        UserRedis::removeFriend($user_id, $block_id);
        UserRedis::removeFriend($block_id, $user_id);


        //block之后移除关注,修改follow数据库
        $follow = Follow::find()->where(['user_id' => $user_id, 'follow_id' => $block_id])->one();
        if (!empty($follow)) {
            $follow->is_follow = 'false';
            $follow->save();
            $follow_back = Follow::find()->where(['user_id' => $block_id, 'follow_id' => $user_id])->one();
            if (!empty($follow_back)) {
                $follow_back->is_follow = 'false';
                $follow_back->save();
            }

            $user_model = User::find()->select(['id', 'guid', 'following'])->where(['guid' => $user_id])->one();
            $friend_model = User::find()->select(['id', 'guid', 'follower'])->where(['guid' => $block_id])->one();
            if ($user_model->following > 0) {
                $user_model->following -= 1;
                $user_model->save();
            }
            if ($friend_model->follower > 0) {
                $friend_model->follower -= 1;
                $friend_model->save();
            }
            UserRedis::setUserInfo($user_id, ['following' => $user_model->following]);
            UserRedis::setUserInfo($block_id, ['follower' => $friend_model->follower]);

        }

        //block之后移除关注,修改follow redis
        UserRedis::userUnFollow($user_id, $block_id);
        UserRedis::delFollower($block_id, $user_id);
        //friends和follower的共同集合
        UserRedis::delFollowerFriends($block_id, $user_id);
        UserRedis::delFollowerFriends($user_id, $block_id);
        UserRedis::delFollowingFriends($user_id, $block_id);
        UserRedis::delFollowingFriends($block_id, $user_id);
        return true;
    }

    /**
     *
     * @param $user_id
     * @param $block_id
     * @return int
     */
    public static function Unblock($user_id, $block_id){
        $relation_status = 0;
        $masterDB = Util::GetMasterDb();
        $blockflag = Block::find()->where(['user_id' => $user_id, 'block_id' => $block_id])->one($masterDB);
        if(!empty($blockflag)){
            $blockflag->status = Block::DisableStatus;
            if(!$blockflag->save()){
                return $relation_status;
            }
        }
        //修改redis
        UserRedis::remBlock($user_id, $block_id);
        $block_list2 = UserRedis::blockList($block_id);
        $follow_list = UserRedis::FollowingFriendsList($block_id);
        if (in_array($user_id,$follow_list)){
            $relation_status = 4;//他关注了我
        }
        if (in_array($block_id,$block_list2)){
            $relation_status = 3;//他把我加入了黑名单
        }
        return $relation_status;
    }
}
