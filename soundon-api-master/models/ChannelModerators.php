<?php

namespace app\models;

use app\components\redis\BroadcastRedis;
use app\components\redis\CacheRedis;
use app\components\redis\ModeratorsRedis;
use app\models\IdolManagement;
use Yii;

/**
 * This is the model class for table "channel_moderators".
 *
 * @property int $id
 * @property string $user_id
 * @property string $host_id
 * @property int $status
 * @property int $role 1:主播频道管理, 2:全频道超级管理员
 * @property string $channel_id 在哪个主播频道申请成为的管理员
 * @property string $created_at
 * @property string $updated_at
 */
class ChannelModerators extends \yii\db\ActiveRecord
{

    /*
     *权限范围 |ow > vp > ma > ca > ca2 | > r > vip > g > u |
     *       |   管理人员               |  普通用户           |
     */
    const role_channel_moderators = 1; //1:主播频道管理
    const role_ma_moderators = 2; //2:全频道超级管理员 mp
    const role_channel_host = 3; //主播
    const role_channel_viewer = 4; //观众

    //状态
    const status_normal = 1;
    const status_deleted = 2;

    //每个主播 管理员数 限制
    const moderators_limit = 10;

    //op
    const op_mute = "mute";
    const op_block = "block";
    const op_kick = "kick";
    const op_unmute = "unmute";
    const op_unblock = "unblock";
    const op_kick_slots = "kick_slots";

    const op_map = [
        self::op_mute,//禁音
        self::op_block,//拉黑
        self::op_kick,//踢人
        self::op_unmute,//取消禁音
        self::op_unblock,//取消拉黑
        self::op_kick_slots,//取消拉黑
    ];

    //ma 列表cache时间
    const MaCacheExpire = 3600;

    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'channel_moderators';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['user_id'], 'required'],
            [['status', 'role'], 'integer'],
            [['created_at', 'updated_at'], 'safe'],
            [['user_id', 'host_id'], 'string', 'max' => 64],
            [['channel_id'], 'string', 'max' => 50],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'user_id' => 'User ID',
            'host_id' => 'Host ID',
            'status' => 'Status',
            'role' => 'Role',
            'channel_id' => 'Channel ID',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
        ];
    }

    /**
     * 添加频道管理员
     * @param $host_id
     * @param $user_id
     * @param string $channel_id
     * @param int $role
     * @return bool
     */
    public static function Add($host_id, $user_id, $channel_id = "", $role = ChannelModerators::role_channel_moderators)
    {
        $model = ChannelModerators::find()->where(["host_id" => $host_id, "user_id" => $user_id])->one();
        if (!empty($model)) { //已经添加过
            if ($model->status == ChannelModerators::status_normal) {
                return true;
            }
            $model->status = ChannelModerators::status_normal;
            if (!$model->save()) {
                return false;
            }
            return true;
        }

        $model = new ChannelModerators;
        $model->host_id = $host_id;
        $model->user_id = $user_id;
        $model->channel_id = $channel_id;
        $model->role = $role;
        $model->status = ChannelModerators::status_normal;
        if (!$model->save()) {
            return false;
        }
        return true;
    }

    /**
     * 获取主播拥有的管理员个数
     * @param $host_id
     * @return int|string
     */
    public static function Count($host_id)
    {
        return ChannelModerators::find()->select("id")->where(["host_id" => $host_id, "status" => ChannelModerators::status_normal])->count("id");
    }

    /**
     * 移除频道管理员
     * @param $host_id
     * @param $user_id
     * @return bool
     */
    public static function Remove($host_id, $user_id)
    {
        $model = ChannelModerators::find()->where(["host_id" => $host_id, "user_id" => $user_id])->one();
        if (empty($model)) {
            return true;
        }
        $model->status = ChannelModerators::status_deleted;
        if (!$model->save()) {
            return false;
        }
        return true;
    }

    /**
     * 获取主播的管理员列表
     * @param $host_id
     * @param $channel_id
     * @return array
     */
    public static function Lst($host_id, $channel_id)
    {
        $ids = [];
        if(empty($channel_id)){
            return $ids;
        }
        $rows = ChannelModerators::find()
            ->select("user_id")
            ->where(["channel_id" => $channel_id, "status" => ChannelModerators::status_normal])
            ->asArray()
            ->all();

        foreach ($rows as $row) {
            $ids[] = $row["user_id"];
        }
        return $ids;
    }

    /**
     * 全频道管理人员
     * @return array
     */
    public static function MaList()
    {
        $rows = ChannelModerators::find()
            ->select("user_id")
            ->where(["role" => ChannelModerators::role_ma_moderators, "status" => ChannelModerators::status_normal])
            ->asArray()
            ->all();
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = $row["user_id"];
        }
        return $ids;
    }

    /**
     * 检查用户是否是全频道管理员
     * @param $moderators_id
     * @return bool
     */
    public static function CheckMaModerators($moderators_id)
    {
        $MaList = self::GetMaList();
        if (empty($MaList) || !is_array($MaList)) {
            return false;
        }
        if(in_array($moderators_id, $MaList)){
            return true;
        }
        return false;
    }


    /**
     * 检查用户是否是主播的管理员
     * @param $host_id
     * @param $moderators_id
     * @param array $channel_info 直播间信息
     * @return bool
     */
    public static function CheckChannelModerators($host_id, $moderators_id, $channel_info = [])
    {
        if (!empty($channel_info['manager'])) {
            $manager_ids = [];
            $manager_ids = explode(',', $channel_info['manager']);
            if (is_array($manager_ids) && in_array($moderators_id, $manager_ids)) {
                return true;
            }
            return false;
        } else {
            return false;
        }

    }

    /**
     * 判断是否有管理权限
     * @param $host_id
     * @param $moderators_id
     * @param array $broadcastInfo 直播间信息
     * @return bool
     */
    public static function CheckModerators($host_id, $moderators_id, $broadcastInfo = [])
    {
        if ($host_id == $moderators_id) {
            return true;
        }
        if (!ChannelModerators::CheckChannelModerators($host_id, $moderators_id, $broadcastInfo)) {
            if (!ChannelModerators::CheckMaModerators($moderators_id)) {
                return false;
            }
        }
        return true;
    }


    /**
     * 检查用户在直播间的身份
     * @param $channel_id
     * @param $user_id
     * @return int
     */
    public static function GetRole($channel_id, $user_id)
    {
        $role = self::role_channel_viewer;
        $channelInfo = BroadcastRedis::getbroadcastInfo($channel_id);
        if (empty($channelInfo)) {
            return $role;
        }
        if(empty($channelInfo["user_id"])){
            return $role;
        }
        $host_id = $channelInfo["user_id"];
        if ($user_id == $host_id) {
            return self::role_channel_host;
        }
        if (self::CheckChannelModerators($host_id, $user_id)) {
            return self::role_channel_moderators;
        }
        if (self::CheckMaModerators($user_id)) {
            return self::role_ma_moderators;
        }
        return $role;
    }

    /**
     * 获取用户是否被mute
     * @param $channel_id
     * @param $user_id
     * @return int 0:没有被mute, 1:已经被mute
     */
    public static function IsMute($channel_id, $user_id)
    {
        $status = 0;
        $muteTimestamp = ModeratorsRedis::GetMuteTime($channel_id, $user_id);
        if (!empty($muteTimestamp)) {
            $status = 1;
        }
        return $status;
    }

    /**
     * 禁言用户
     * @param $channel_id
     * @param $moderators_user
     * @param $user_id
     * @param $host_id
     * @return bool
     */
    public static function Mute($channel_id, $moderators_user, $user_id, $host_id)
    {
        $log_id = ModeratorsOpLog::Add($moderators_user, $user_id, $channel_id, $host_id, self::op_mute);
        if (!$log_id) {
            return false;
        }
        if (!ModeratorsRedis::SetMuteTime($channel_id, $user_id)) {
            return false;
        }
        return $log_id;
    }

    /**
     * 取消禁言
     * @param $channel_id
     * @param $moderators_user
     * @param $user_id
     * @param $host_id
     * @return bool
     */
    public static function UnMute($channel_id, $moderators_user, $user_id, $host_id)
    {
        $log_id = ModeratorsOpLog::Add($moderators_user, $user_id, $channel_id, $host_id, self::op_unmute);
        if (!$log_id) {
            return false;
        }
        if (!ModeratorsRedis::RemoveMuteTime($channel_id, $user_id)) {
            return false;
        }
        return $log_id;
    }

    /**
     * 把用户从直播间踢出
     * @param $channel_id
     * @param $moderators_user
     * @param $user_id
     * @param $host_id
     * @return bool
     */
    public static function Kick($channel_id, $moderators_user, $user_id, $host_id)
    {
        $log_id = ModeratorsOpLog::Add($moderators_user, $user_id, $channel_id, $host_id, self::op_kick);
        if (!$log_id) {
            return false;
        }
        //设置五分钟不能加入直播
        //- User will go back to Explore and can't go back to the same LS for 5 minutes
        if (!ModeratorsRedis::SetKick($channel_id, $user_id, $moderators_user)) {
            return false;
        }
        return $log_id;
    }

    /**
     * 直播间block用户
     * @param $channel_id
     * @param $moderators_user
     * @param $user_id
     * @param $host_id
     * @param $device_type
     * @param $device_id
     * @param int $role_id 角色id
     * @return bool
     */
    public static function Block($channel_id, $moderators_user, $user_id, $host_id, $device_type, $device_id)
    {
        $log_id = ModeratorsOpLog::Add($moderators_user, $user_id, $channel_id, $host_id, self::op_block);
        if (!$log_id) {
            return false;
        }
        $result = true;
        if ($moderators_user == $host_id) {
            //放入主播的个人黑名单
            $result = Block::block($host_id, $user_id, $device_type, $device_id, "", 0);
        }
        //直接放入直播间关联的黑名单
//        $has_block = BroadcastRedis::getLiveBlockUserScore($channel_id, $user_id);
//        if ($has_block === false) {
            BroadcastRedis::addLiveBlockUser($channel_id, $user_id);
//        }
        if (!$result) {
            return false;
        }
        return $log_id;
    }

    /**
     * unblock
     * @param $channel_id
     * @param $moderators_user
     * @param $user_id
     * @param $host_id
     * @return bool
     */
    public static function UnBlock($channel_id, $moderators_user, $user_id, $host_id)
    {
        $log_id = ModeratorsOpLog::Add($moderators_user, $user_id, $channel_id, $host_id, self::op_unblock);
        if (!$log_id) {
            return false;
        }
        $result = true;
        if ($user_id == $host_id) {
            Block::Unblock($moderators_user, $user_id);
        }
        $has_block = BroadcastRedis::getLiveBlockUserScore($channel_id, $user_id);
        if ($has_block !== false) {
            BroadcastRedis::remLiveBlockUser($channel_id, $user_id);
        }
        return $log_id;
    }

    /**
     * 获取所有管理员
     * @param $host_id
     * @param $channel_id
     * @return array
     */
    public static function GetAllModerators($host_id, $channel_id = "")
    {
        $channelModerators = self::Lst($host_id, $channel_id);
        $maModerators = self::MaList();
        return array_merge($maModerators, $channelModerators);
    }
    /**
     * 获取ma列表
     * @return array
     */
    public static function GetMaList(){
        $list =  CacheRedis::GetCache(CacheRedis::CACHE_BROADCAST_MODERATOR_MA);
        if(!empty($list)){
            return $list;
        }

        $data = [];
        $rows = ChannelModerators::find()
            ->select("id,user_id")
            ->where(["status" => ChannelModerators::status_normal, "role" => ChannelModerators::role_ma_moderators])
            ->asArray()
            ->all();
        foreach ($rows as $row){
            $data[] = $row["user_id"];
        }
        CacheRedis::SetCache(CacheRedis::CACHE_BROADCAST_MODERATOR_MA, $data, self::MaCacheExpire);
        return $data;
    }

    public static function KickSlots($channel_id, $moderators_user, $user_id, $host_id)
    {
        $log_id = ModeratorsOpLog::Add($moderators_user, $user_id, $channel_id, $host_id, self::op_kick_slots);
        if (!$log_id) {
            return false;
        }
        return $log_id;
    }
}
