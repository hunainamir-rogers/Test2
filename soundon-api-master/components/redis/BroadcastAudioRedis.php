<?php

namespace app\components\redis;

use app\components\RedisClient;

class BroadcastAudioRedis
{

    public static $redis_client;

    private static $_redis_pool = 'user';

    //cohost槽状态
    const SlotsLocked = 1;
    const SlotsUnLocked = 0;

    //主播对cohost的静音/视频状态
    const mute_by_host = 1;//被主播静音
    const unmute_by_host = 0;//未被主播静音
    const video_mute_by_host = 1;//被主播关闭画面
    const video_unmute_by_host = 0;//未主播关闭画面

    //用户是否开启语音
    const mute_by_self = 1; //被自己静音(语音未打开)
    const unmute_by_self = 0; //未被自己静音(打开语音)
    const video_mute_by_self = 1; //被自己静音(视频未打开)
    const video_unmute_by_self = 0; //未被自己静音(打开视频)

    //cohost 群体设置
    const cohost_any_all = 1; //所有人
    const cohost_any_friend = 0; //仅好友

    //mute 选项
    const mute_audio = "audio";
    const mute_video = "video";

    const KeyAudioSlots = "broadcast:audio:slots:"; //audio 槽位
    const KeyAudioCohostEarned = "broadcast:audio:cohost:earn:"; //cohost 获得diamond
    const SongReadyRoom =  "song.ready.room";

    private static function _getInstance()
    {
        if (self::$redis_client instanceof RedisClient) {
            return self::$redis_client;
        }
        self::$redis_client = new RedisClient(self::$_redis_pool);
        return self::$redis_client;
    }


    /**
     * 设置所有槽位
     * @param $channel_id
     * @param $data
     * @return mixed
     */
    public static function SetSlots($channel_id, $data)
    {
        $key = self::KeyAudioSlots . $channel_id;
        return self::_getInstance()->hmset($key, $data);
    }

    /**是否存在槽位信息
     * @param $channel_id
     * @return mixed
     */
    public static function isExistSlots($channel_id='')
    {
        if(empty($channel_id)){
            return false;
        }
        $key = self::KeyAudioSlots . $channel_id;
        return self::_getInstance()->exists($key);
    }

    public static function lengthSlots($channel_id='')
    {
        if(empty($channel_id)){
            return 0;
        }
        $key = self::KeyAudioSlots . $channel_id;
        return self::_getInstance()->hlen($key);
    }

    /**
     * 删除槽
     * @param $channel_id
     * @return mixed
     */
    public static function DelSlots($channel_id)
    {
        $key = self::KeyAudioSlots . $channel_id;
        return self::_getInstance()->del($key);
    }

    /**
     * 设置单个槽
     * @param $channel_id
     * @param $slots
     * @param $value
     * @return mixed
     */
    public static function SetSlotsOne($channel_id, $slots, $value)
    {
        $key = self::KeyAudioSlots . $channel_id;
        return self::_getInstance()->hset($key, $slots, $value);
    }
    public static function MSetSlotsOne($channel_id, $data=[])
    {
        if(empty($data) || !is_array($data)){
            return false;
        }
        $key = self::KeyAudioSlots . $channel_id;
        return self::_getInstance()->hmset($key, $data);
    }

    /**
     * 获取槽位数据
     * @param $channel_id
     * @param string $slots
     * @return mixed
     */
    public static function GetSlots($channel_id, $slots = "")
    {
        $key = self::KeyAudioSlots . $channel_id;
        if (!empty($slots)) {
            return self::_getInstance()->hget($key, $slots);
        }
        return self::_getInstance()->hgetall($key);
    }


    //----- audio cohost ----

    public static function IncCohostEarned($user_id, $number)
    {
        $key = self::KeyAudioCohostEarned;
        return self::_getInstance()->zincrby($key, $number, $user_id);
    }
    public static function RemCohostEarned($user_id)
    {
        $key = self::KeyAudioCohostEarned;
        return self::_getInstance()->zrem($key, $user_id);
    }
    public static function GetCohostEarned($user_id)
    {
        $key = self::KeyAudioCohostEarned;
        return self::_getInstance()->zscore($key, $user_id);
    }

    /**
     * 保存用户完唱歌匹配时创建房间的记录
     * @param $user_id
     * @param $channel_id
     * @return mixed
     */
    public static function SetUserSongReadyRoom($user_id,$channel_id)
    {
        if($user_id && $channel_id){
            $key = self::SongReadyRoom;
            return self::_getInstance()->hset($key, $user_id, $channel_id);
        }
        return false;
    }
    public static function GetUserSongReadyRoom($user_id='')
    {
        if($user_id){
            $key = self::SongReadyRoom;
            return self::_getInstance()->hget($key, $user_id);
        }
        return false;
    }
    public static function DelUserSongReadyRoom($user_id='')
    {
        if($user_id){
            $key = "song.ready.room";
            return self::_getInstance()->hdel($key, $user_id);
        }
        return false;
    }


    /***************唱歌匹配队友，队列redis***********************/
    const SONG_MATCH_ROBOT_LIST = 'song:match:robot:list';//匹配时的机器人list列表
    public static function addRobotSongMatchList($user_ids){
        if(!empty($user_ids)){
            return self::_getInstance()->lpush(self::SONG_MATCH_ROBOT_LIST, $user_ids);
        }
       return false;
    }
    //获取机器人个数
    public static function getRobotFromSongMatchList($num = 1){
        $return = [];
        if($num > 0){
            for ($i = 0; $i < $num ;$i++){
                $uid = '';
                $uid = self::_getInstance()->rpop(self::SONG_MATCH_ROBOT_LIST);
                if($uid){
                    $return[] =  $uid;
                    //记录使用的机器人，在唱歌结束后需要重新写入机器人列表
                    self::addSongMatchUsedRobots($uid);
                }
            }
        }
        return $return;
    }

    const SONG_MATCH_USED_ROBOTS = 'song:match:used:robot';//匹配时使用的机器人set
    public static function addSongMatchUsedRobots($uid=''){
        return self::_getInstance()->sadd(self::SONG_MATCH_USED_ROBOTS, $uid);
    }

    const SONG_MATCH_LIST_PEOPLE = 'song:matchpeople:';//唱歌用户匹配列表
    public static function addPeoPleSongMatchList($room_type,$sid='',$user_id){
        if(!empty($room_type) && $sid && $user_id){
            $key = self::SONG_MATCH_LIST_PEOPLE.$room_type.':'.$sid;
            return self::_getInstance()->lpush($key, $user_id);
        }
        return false;
    }
    //获取长度
    public static function getPeoPleSongMatchListCount($room_type,$sid=''){
        if($room_type && $sid){
            $key = self::SONG_MATCH_LIST_PEOPLE.$room_type.':'.$sid;
            return self::_getInstance()->llen($key);
        }
        return 0;
    }
    //在尾部获取多少个用户
    public static function getSomeDataFromPeoPleSongMatchList($room_type,$sid='',$num = 5){
        $return = [];
        if($room_type && $sid){
            $key = self::SONG_MATCH_LIST_PEOPLE.$room_type.':'.$sid;
            for ($i = 0; $i < $num ;$i++){
                $uid = '';
                $uid = self::_getInstance()->rpop($key);
                if($uid){
                    $return[] =  $uid;
                }
            }
        }
        return $return;
    }


    /*****************记录唱歌直播间的歌单，进程等进度**************************/
    const GRAP_SONG_LIST_DATA = 'song:songdata:';
    public static function addGrapSongData($channel_id='',$data= []){
        if($channel_id && $data && is_array($data)){
            $key = self::SONG_MATCH_USED_ROBOTS.$channel_id;
            //6h
            $toggle = false;
            if(!self::_getInstance()->exists($key)){
                $toggle = true;
            }
             $result = self::_getInstance()->hmset($key,$data);
            if($toggle){
                self::_getInstance()->expire($key,21600);
            }
             return $result;
        }
        return false;
    }
    //获取生成的歌单数据
    public static function getGrapSongData($channel_id='',$params= ''){
         if($channel_id && $params){
             $key = self::SONG_MATCH_USED_ROBOTS.$channel_id;
             if(is_array($params)){
                  return self::_getInstance()->hmget($key,$params);
             }
             return self::_getInstance()->hget($key,$params);
         }
         return '';
    }


}