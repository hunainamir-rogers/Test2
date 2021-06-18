<?php

namespace app\models;

use app\components\Agora;
use app\components\Code;
use app\components\DFeed;
use app\components\dynamo\FeedChannelDynamo;
use app\components\firebase\FbLivecast;
use app\components\Livbysqs;
use app\components\LiveLogic;
use app\components\Pk;
use app\components\redis\ApiRedis;
use app\components\redis\AsapRedis;
use app\components\redis\BroadcastAudioRedis;
use app\components\redis\BroadcastRedis;
use app\components\redis\BroadcastTopRedis;
use app\components\redis\EventRedis;
use app\components\redis\FeedRedis;
use app\components\redis\GroupRedis;
use app\components\redis\HQRedis;
use app\components\redis\LivecastRedis;
use app\components\redis\LiveRedis;
use app\components\redis\ModeratorsRedis;
use app\components\redis\PkRedis;
use app\components\redis\RankRedis;
use app\components\redis\SpotlightRedis;
use app\components\redis\UserRedis;
use app\components\redis\VisitorRedis;
use app\components\redis\IdolRedis;
use app\components\redis\GameMatchRedis;
use app\components\service\Barge;
use app\components\service\Stick;
use app\components\gameconfig\Match;
use app\components\Words;
use app\elasticsearch\Es;
use app\elasticsearch\EsIndecConfig;
use app\models\IdolMatching;
use app\models\IdolOnline;
use app\models\Service;
use Firebase\FirebaseLib;
use Yii;

/**
 * This is the model class for table "channel".
 *
 * @property integer $id
 * @property string $guid
 * @property string $user_id
 * @property string $title
 * @property string $group_id
 * @property integer $type
 * @property integer $duration
 * @property string $scheduled_time
 * @property integer $like_count
 * @property string $channel_key
 * @property string $cover_image
 * @property string $description
 * @property string $agora_id
 * @property string $agora_streamname
 * @property string $agora_appid
 * @property integer $agora_code
 * @property string $agora_start_return
 * @property string $agora_end_return
 * @property string $agora_error
 * @property string $recording_start_time
 * @property string $recording_end_time
 * @property integer $orderby
 * @property string $mp4url
 * @property string $is_live
 * @property integer $post_status
 * @property integer $post_timeline
 * @property integer $live_highest_audience
 * @property integer $total_viewer
 * @property integer $total_joined_times
 * @property integer $live_current_audience
 * @property string $live_start_time
 * @property string $live_end_time
 * @property integer $replay_highest_audience
 * @property integer $replay_current_audience
 * @property string $location
 * @property double $longitude
 * @property double $latitude
 * @property string $copy_status
 * @property string $copy_end_time
 * @property string $deleted
 * @property string $created_ip
 * @property string $updated_at
 * @property string $created_at
 * @property string $hlsurl
 * @property string $send_email
 * @property integer $gifts
 * @property integer $diamonds
 * @property integer $extra
 */
class Channel extends \yii\db\ActiveRecord
{


    const trivia_type_cash = 1;
    const trivia_type_coin = 2;


    //区分fan top 列表中是否送礼物
    const fans_type_gift = 1;  //送了礼物的
    const fans_type_view = 0;  //观众,未送礼物

    //显示top fans 的个数
    const top_fan_total = 3;

    //直播状态
    const type_status_upcoming = 0;
    const type_status_living = 1;
    const type_status_end = 2;
    const type_status_offline_model = 4;

    //直播模式
    const mode_video = 1; //视频
    const mode_audio = 2; //语音
    const mode_audio_cohost = 3; //支持连麦语音
    const mode_4_seat = 4; //4 seats
    const mode_6a_seat = 61; //6 seats, 小方块
    const mode_6b_seat = 62; //6 seats, cohost在周围
    const mode_9_seat = 9; //9 seats
    const mode_6_seat = 6; //5 seats
    const mode_5_seat = 5; //4 seats
    const mode_10_seat = 10; //10 seats
    //audio 默认槽位
    const audio_slots = 8;
    const seat4_slots = 3;
    const seat6_slots = 5;
    const seat9_slots = 8;
    const seat5_slots = 4;
    const seat10_slots = 10;


    //trivia cohost设置
    const TriviaCohostNone = 1;
    const TriviaCohostFeatured = 2;
    const TriviaCohostAll = 3;

    const TriviaCohostMap = [
        self::TriviaCohostNone => "None",
        self::TriviaCohostFeatured => "Featured Only",
        self::TriviaCohostAll => "All",
    ];

    //直播类型
    const liveTypeRegular = '1';
    const liveTypeGroup = '2';
    const liveTypeTrivia = '3';
    const liveTypeEcommerce = '5';
    const liveTypeSpotlight = '6';
    const liveTypeRobotAuto = '7';//chatroom
    const liveTypePrivate = '8';// concierge
    const liveOneMatchOne = '9';//1对1 音频匹配模式
    const liveYinYuReady = '10';//音遇个人准备房间
    const liveYinYuMatch = '11';// 音遇6人匹配游戏
    const liveIdolJeepney = '15';//车房直播间jeepney
    const liveAudioStream = '16';//语聊房直播 Audio Live
    const GameMatchLive = '17';//快速匹配游戏直播间
    const liveVideoOneMatchOne = '18';//1对1 视频匹配模式

    const liveCastLive = '19';//Livecast房间


    //答题时显示评论
    const show_chat_display = 1;
    const show_chat_hidden = 2;
    //默认值
    const CHANNEL_DEFAULT = [
        'language' => 'English',
        'country' => 'Philippines',
        'tags' => 'Discussion',
    ];

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'channel';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['type', 'quick_type', 'duration', 'like_count', 'orderby', 'post_status', 'post_timeline', 'live_highest_audience', 'total_viewer', 'total_joined_times', 'live_current_audience', 'replay_highest_audience', 'replay_current_audience', 'gifts', 'diamonds', 'coins'], 'integer'],
            [['scheduled_time', 'live_start_time', 'live_end_time', 'updated_at', 'created_at'], 'safe'],
            [['is_live', 'deleted'], 'string'],
            [['longitude', 'latitude'], 'number'],
            [['guid', 'user_id', 'group_id'], 'string', 'max' => 64],
            [['title', 'location'], 'string', 'max' => 100],
            [['cover_image', 'description'], 'string', 'max' => 500],
            [['mp4url', 'hlsurl'], 'string', 'max' => 255],
            [['created_ip'], 'string', 'max' => 50],
            [['send_email'], 'string', 'max' => 200],
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
            'user_id' => 'User ID',
            'title' => 'Title',
            'group_id' => 'Group ID',
            'type' => 'Type',
            'quick_type' => 'Quick Type',
            'duration' => 'Duration',
            'scheduled_time' => 'Scheduled Time',
            'schedule' => 'Schedule',
            'like_count' => 'Like Count',
            'comment_count' => 'Comment Count',
            'cover_image' => 'Cover Image',
            'description' => 'Description',
            'orderby' => 'Orderby',
            'mp4url' => 'Mp4url',
            'hlsurl' => 'Hlsurl',
            'send_email' => 'Send Email',
            'gifts' => 'Gifts',
            'diamonds' => 'Diamonds',
            'coins' => 'Coins',
            'enable' => 'Enable',
            'live_type' => 'Live Type',
            'test' => 'Test',
            'landscape' => 'Landscape',
            'video' => 'Video',
            'notification_sound' => 'Notification Sound',
            'is_live' => 'Is Live',
            'post_status' => 'Post Status',
            'post_timeline' => 'Post Timeline',
            'live_highest_audience' => 'Live Highest Audience',
            'total_viewer' => 'Total Viewer',
            'total_joined_times' => 'Total Joined Times',
            'live_current_audience' => 'Live Current Audience',
            'live_start_time' => 'Live Start Time',
            'live_end_time' => 'Live End Time',
            'replay_highest_audience' => 'Replay Highest Audience',
            'replay_current_audience' => 'Replay Current Audience',
            'location' => 'Location',
            'longitude' => 'Longitude',
            'latitude' => 'Latitude',
            'deleted' => 'Deleted',
            'created_ip' => 'Created Ip',
            'updated_at' => 'Updated At',
            'created_at' => 'Created At',
            'guesthost' => 'Guesthost',
            'cohost_any' => 'Cohost Any',
            'bg' => 'Bg',
            'show_chat' => 'Show Chat',
            'rtmp' => 'Rtmp',
            'sid' => 'Sid',
            'extra' => 'extra',
        ];
    }

    //同步到es
    public function afterSave($insert, $changedAttributes)
    {
        parent::afterSave($insert, $changedAttributes); // TODO: Change the autogenerated stub
        $live_type = $this->getAttribute('live_type');
        $old_live_type = $changedAttributes['live_type'] ?? 0;
        //只记这些到es
        if ($old_live_type != 1 && !empty($live_type) && in_array($live_type, [self::liveIdolJeepney, self::liveTypePrivate, self::liveAudioStream])) {
            try {
                $data = [];
                $data = $this->getAttributes(['order_score', 'id', 'guid', 'user_id', 'title', 'type', 'live_type', 'is_live', 'diamond_number', 'cutin_diamond_number', 'feed_id', 'tags', 'language', 'country']);
                $data['country'] = empty($data['country']) ? self::CHANNEL_DEFAULT['country'] : $data['country'];
                $data['language'] = empty($data['language']) ? self::CHANNEL_DEFAULT['language'] : $data['language'];
                $data['tags'] = empty($data['tags']) ? '' : $data['tags'];
                $data['order_score'] = empty($data['order_score']) ? 0 : $data['order_score'];
                $es = new Es(['index' => EsIndecConfig::BROADCAST_INDEX]);
                if ($insert) {
                    //写入elasticsearch
                    Yii::info('insert channel es data=,' . json_encode($data), 'broadcast');
                    if (!empty($data['guid']) && !empty($data['live_type'])) {
                        $data['created_at'] = time();
                        $data['order_score'] = $data['id'];
                        $es->singleDoc($data, 'id');
                        LiveRedis::addLiveList($data['live_type'], strtolower($data['tags']), $data['guid'], $data['order_score']);
                    }
                    //提升在IdolMatching队列中的排序，用于Discovery页的Popular以及探探卡片
                    IdolMatching::changeOnlineTime($data['user_id'], time(), false);
                } else {
                    Yii::info('update channel es data=,' . json_encode($data), 'broadcast');
                    if (!empty($data['id']) && isset($data['type']) && ($data['type'] == 2 || $data['is_live'] == 'no')) {
                        //关播，直接从es 里面删除
                        $es->deleteDoc($data['id']);
                        //提升在IdolMatching队列中的排序，用于Discovery页的Popular以及探探卡片
                        IdolMatching::changeOnlineTime($data['user_id'], null, false);
                        LiveRedis::remLiveList($data['live_type'] ?? '', strtolower($data['tags']), $data['guid'] ?? '');
                    } else {
                        //修改数据
                        $data['order_score'] = $data['order_score'] <= 0 ? $data['id'] : $data['order_score'];
                        LiveRedis::updateTagLiveList($data['live_type'] ?? '', strtolower($data['tags']), strtolower($changedAttributes['tags'] ?? ''), $data['guid'] ?? '', $data['order_score']);

                        $id = $data['id'] ?? '';
                        if ($id) {
                            $is_add = false;
                            if ($live_type == self::liveAudioStream) {
                                $is_exist = $es->getDoc($id);
                                $is_add = empty($is_exist) ? true : false;
                            }
                            if (!$is_add) {
                                unset($data['id']);
                                $es->updateDoc($id, $data);
                            } else {
                                if (isset($data['type']) && ($data['type'] == 1 || $data['is_live'] == 'yes')) {
                                    $es->singleDoc($data, 'id');
                                    IdolMatching::changeOnlineTime($data['user_id'], time(), false);
                                }
                            }
                        }
                    }
                }
                //用户正在直播
                BroadcastRedis::addUserBroadcast($data['guid'], $data['user_id']);
            } catch (\Exception $e) {
                Yii::info('insert channel es fail,' . json_encode($e->getMessage()), 'broadcast');
            }
        }


    }

    /**
     * 有礼物功能的队列
     */
    public static function needGiftFunctionLiveType()
    {
        return [
            self::liveAudioStream,
        ];
    }


    /*** 从elasticsearch 查询直播列表信息
     * @param array $live_type 直播类型
     * @param array $where 查询条件
     * @param int $since_id page
     * @param string $order 排序键
     * @param int $page_size
     * @return array
     * @throws \Exception
     */
    public static function getBroadcastListFromEs($live_type = [], $where = [], $since_id = 1, $order = 'created_at', $page_size = 12)
    {
        $params = [];
        $es = new Es(['index' => EsIndecConfig::BROADCAST_INDEX]);
        $params['bool']['must'][]['match']['type'] = 1;
        if (!empty($where) && is_array($where)) {
            foreach ($where as $k => $v) {
                $params['bool']['must'][]['match'][$k] = $v;
            }
        }
        if (!empty($live_type) && is_array($live_type)) {
            $should = [];
            foreach ($live_type as $v) {
                $should['bool']['should'][]['match']['live_type'] = (int)$v;
            }
            $params['bool']['must'][] = $should;
        }
        $list = $es->setSortDesc($order)->search($params, $since_id, $page_size);
        $since_id = '';
        $data = [];
        if (!empty($list['data'])) {
            if (!empty($list['page']) && $list['page'] > 1) {
                $since_id = $list['page'];
            }
            $data = $list['data'];
        } else {
            $data = [];
        }
        return ['list' => $data, 'since_id' => $since_id];
    }

    /**根据order_score打乱一些东西
     * @param array $data
     * @return array
     */
    public static function recombinationByScore($data = [])
    {
        $rand = mt_rand(1, 2);//50%机率打乱
        if ($rand == 2) {
            $recommend_arr_top = $recommend_arr_middle = $recommend_arr_bottom = $common_arr = [];
            foreach ($data as $one) {
                $order_score = 0;
                if (!isset($one['order_score'])) {
                    continue;
                } else {
                    $order_score = $one['order_score'];
                    unset($one['order_score']);
                }
                if ($order_score > 2000000005) {
                    $recommend_arr_top[] = $one;
                } elseif ($order_score == 2000000005) {
                    $recommend_arr_middle[] = $one;
                } elseif ($order_score > 2000000000) {
                    $recommend_arr_bottom[] = $one;
                } else {
                    $common_arr[] = $one;
                }
            }
            if (!empty($common_arr)) {
                shuffle($common_arr);
            }
            if (!empty($recommend_arr_middle) and count($recommend_arr_middle) > 1) {
                shuffle($recommend_arr_middle);
            }
            return array_merge($recommend_arr_top, $recommend_arr_middle, $recommend_arr_bottom, $common_arr);
        }
        return $data;
    }


    /**
     * 获取频道总参与人数
     * @param $channel_id
     * @return int
     */
    public static function GetAllParticipants($channel_id)
    {
        $firstQuestionId = HQRedis::GetQuestionByOrder($channel_id, 1);
        if (!$firstQuestionId) {
            return false;
        }
        $answerList = HQRedis::GetAnswers($channel_id, $firstQuestionId);
        $total = 0;
        foreach ($answerList as $answer_id => $answer_word) {
            $total += HQRedis::UserAnswerCollectCount($channel_id, $firstQuestionId, $answer_id);
        }
        return $total;
    }


    /**
     * 根据channel_id 分配到不同的服务器进行录制
     * @param $channel_id
     * @param $type
     * @return string
     */
    public static function getRecQueueName($channel_id, $type)
    {
        $count = isset(\yii::$app->params["rec_server"]) ? \yii::$app->params["rec_server"] : 1; //服务器数量
//        $count = 2; //服务器数量
        $key = crc32($channel_id) % $count;
        $key = abs($key);
        $queue = '';
        switch ($type) {
            case "start":
                $queue_name = "rec-start";
                if ($key != 0) {
                    $queue_name = $queue_name . "-" . $key;
                }
                $queue = isset(Yii::$app->params["sqs"][$queue_name]) ? Yii::$app->params["sqs"][$queue_name] : Yii::$app->params["sqs"]['rec-start'];
                break;
            case "end":
                $queue_name = "rec-end";
                if ($key != 0) {
                    $queue_name = $queue_name . "-" . $key;
                }
                $queue = isset(Yii::$app->params["sqs"][$queue_name]) ? Yii::$app->params["sqs"][$queue_name] : Yii::$app->params["sqs"]['rec-end'];
                break;
        }

        return $queue;
    }

    /**
     * 关闭直播
     * @param $channel_id
     * @param $user_id
     * @param $info
     * @return array
     */
    public static function Close($channel_id, $user_id, $info)
    {
        $channel = Channel::find()->where(['guid' => $channel_id])->one();
        //先修改redis里的数据
        //BroadcastRedis::broadcastInfo($channel_id,['type'=>2,'duration'=>time()-strtotime($channel->live_start_time)]);
        $channel->live_end_time = date('Y-m-d H:i:s');
        $channel->type = '2';
        $channel->is_live = 'no';
        $channel->comment_count = isset($info['comment_count']) ? $info['comment_count'] : 0;

        $info['like_count'] = isset($info['like_count']) ? $info['like_count'] : 0;

        $startTimestamp = strtotime($channel->live_start_time);
        if ($startTimestamp == 0) {
            $startTimestamp = strtotime($channel->updated_at);
            if ($startTimestamp == 0) {
                $startTimestamp = time();
            }
        }

        $duration = time() - $startTimestamp;
        BroadcastRedis::broadcastInfo($channel_id, ['like_heart' => $info['like_count'], 'type' => 2, 'duration' => $duration]);
        $gifts = isset($info['gifts']) ? intval($info['gifts']) : 0;
        $coins = isset($info['golds']) ? intval($info['golds']) : 0;
        $diamonds = isset($info['diamonds']) ? intval($info['diamonds']) : 0;
        $bot_count = isset($info['bot_count']) ? intval($info['bot_count']) : 0;
        $rtmp = isset($info['rtmp']) ? intval($info['rtmp']) : 0;

        //将点赞数量写到数据库

        $channel->like_count = $info['like_count'];
        if (isset($info['live_highest_audience'])) {
            $channel->live_highest_audience = $info['live_highest_audience'];
        }
        if (isset($info['live_current_audience'])) {
            $channel->live_current_audience = (int)$info['live_current_audience'];
        }
        $channel->duration = $duration;
        if ($channel->live_type != self::liveAudioStream) {
            if ($coins != $channel->coins) {
                Yii::info('broadcast/leave: coins  number error------mysql:' . $channel->coins . ' redis:' . $coins . ' time:' . date('Y-m-d H:i:s') . 'channel:' . $channel_id, 'redis');
                $channel->coins = $coins;
            }
            $channel->gifts = $gifts;
            $channel->diamonds = $diamonds;
        }

        //andrew: can we track number of views each livestream has example if user A watches a livestream and then leaves and comes back the number is 2
        // /broadcast/join 接口添加的数据写会数据库
        $channel->total_joined_times = isset($info['total_joined_times']) ? $info['total_joined_times'] : 0;
        $channel->total_viewer = $total_viewer = intval($channel->total_joined_times) + $bot_count;

        if (!$channel->save()) {
            \Yii::info('closeBroadcast save fail:' . json_encode($channel->getErrors()), 'my');
            return [];
        }

        $broadcastInfo = Service::reloadBroadcast($channel_id);
        BroadcastRedis::RemoveExploreChannel($channel_id, LiveLogic::LiveModelLiving);
        BroadcastRedis::remBroadcastCoUser($user_id);
        //准备匹配语言的close 时删除用户和房间的关联
        if ($channel->live_type == self::liveYinYuReady) {
            BroadcastAudioRedis::DelUserSongReadyRoom($channel->user_id);
        }

        BroadcastRedis::deltAudienceLit($channel_id);//删除join进来的只用用户信息列表
        ModeratorsRedis::DelMuteKey($channel_id);
        // Agora::StopSignalJar($channel_id);
        self::RemoveBroadcastRedisKey($channel_id, $user_id);
        //清除槽位人数统计
        LivecastRedis::delVoiceNumber($channel_id);
        //清除槽位开麦人数统计
        LivecastRedis::delOpenVoiceNumber($channel_id);
        //清除大列表
        LiveRedis::remLiveList(LiveLogic::LiveModelLiving, '');
        LiveRedis::remLiveList(LiveLogic::LiveModelUpcoming, '');

        //清楚申请举手列表
        BroadcastRedis::delApplyCohost($channel_id);

        if (isset($broadcastInfo['send_email']) && !empty($broadcastInfo['send_email'])) {
            // 关播结束语音录制逻辑
            ChannelCloudRecording::EndRec($channel_id);
        }
        //移除直播运行时的redis key 移除firebase全部此直播间数据
        self::RemoveBroadcastRedisKey($channel_id, $user_id);
        try {
            Agora::StopSignalJar($channel_id);
        } catch (\Exception $e) {
            \Yii::info('closeBroadcast save fail:' . json_encode($e->getMessage()), 'my');
        }
        return ['user_id' => $user_id, 'host_type' => 0, 'like_count' => intval($info['like_count']), 'audience_count' => intval($total_viewer), 'duration' => intval($duration), 'channel_id' => $channel_id, 'gifts' => $gifts, 'golds' => $coins, 'is_group' => false, 'status' => 'Leave success.'];

    }

    public static function RemoveBroadcastRedisKey($channel_id, $host_id)
    {
        BroadcastAudioRedis::DelSlots($channel_id);
        BroadcastRedis::DedFollower($channel_id);
        $db_url = Yii::$app->params["firebase"]["db_url"] ?? '';
        $secretKey = Yii::$app->params["firebase"]["secretKey"] ?? '';
        if (empty($db_url) || empty($secretKey)) {
            return false;
        }
        $firebase = new FirebaseLib($db_url, $secretKey);
        $defalut_path = FbLivecast::getOneRoomPath($channel_id);
        $response = $firebase->delete($defalut_path);
        if ($response != 'null') {
            \Yii::info('Delete room data fail,path=' . $defalut_path, FbLivecast::LOG);
            return false;
        }
    }

    /**
     * 录制结束hook
     * @param $channel_id
     */
    public static function RecEndHook($channel_id)
    {
        /*Service::log_time("channel_id: {$channel_id} Rec end Hook start");
        $channel = Channel::findOne(array('guid' => $channel_id));
        if($channel->asap != 1){
            if(!empty($channel->mp4url)){
                $res = BroadcastRedis::addASAPList($channel_id, time(), BroadcastRedis::asap_replay);
                Service::log_time("channel_id: {$channel_id} , add asap replay list, res: ".json_encode($res));
            }
        }
        Service::log_time("channel_id: {$channel_id} Rec end Hook end");*/
    }


    /**
     * @param $host_id
     * @param $fans_id
     * @param $score
     * @param $channel_id
     * @return mixed
     */
    public static function IncrementFansScore($host_id, $fans_id, $score, $channel_id = "")
    {
        if ($channel_id) {
            BroadcastRedis::UpdateAudienceFansSort($channel_id, $fans_id, $score);
        }
        return BroadcastTopRedis::Add($host_id, $fans_id, $score);
    }

    public static function UpdateFansScore($host_id, $fans_id, $score)
    {
        return BroadcastTopRedis::Update($host_id, $fans_id, $score);
    }


    /**
     * 获取粉丝排行榜
     * @param $channel_id
     * @param $host_id
     * @param int $page
     * @param int $pageSize
     * @return array
     */
    public static function FansTopList($channel_id, $host_id, $page = 1, $pageSize = Channel::top_fan_total)
    {
        $fansGuids = BroadcastTopRedis::TopList($host_id, $page, $pageSize, true);
//        $userList = UserRedis::getUserInfoBatch($fansGuids);
        $data = [];
        $order = ($page - 1) * $pageSize + 1;
        foreach ($fansGuids as $value => $score) {
            $tmp = UserRedis::getUserInfo($value);
            if (empty($tmp)) {
                continue;
            }
            $u["guid"] = isset($tmp["guid"]) ? $tmp["guid"] : "";
            if (isset($tmp["status"]) && $tmp["status"] != "normal") {//用户已经被删除,移除fans排行
                BroadcastTopRedis::Zrem($host_id, $u["guid"]);
                continue;
            }
            $u["nickname"] = isset($tmp["nickname"]) ? $tmp["nickname"] : "";
            $u["avatar"] = isset($tmp["avatar"]) ? Service::avatar_small($tmp["avatar"]) : "";
            $u["order"] = $order;
            $point = isset($tmp['point']) ? $tmp['point'] : 0;
            $u["username"] = isset($tmp["username"]) ? $tmp["username"] : "";
            $u["level"] = Service::userLevel($point);
            $u["type"] = Channel::fans_type_gift;
//            $u["diamonds"] =  Util::number_format_short((float)$score, 0);
            $u["diamonds"] = (int)$score;
            $data[] = $u;
            ++$order;
            unset($tmp);
        }
        $fansTopCount = count($data);
        $viewCount = Channel::top_fan_total - $fansTopCount;
        if ($viewCount > 0) {
            $viewGuids = BroadcastRedis::ListAudienceFansSortKey($channel_id, 0, $viewCount + 2);//多取三个值以防重复
            $viewData = [];
            foreach ($viewGuids as $key => $value) {
                if (in_array($value, $fansGuids)) {//过滤掉重复的
                    continue;
                }
                if (count($data) > $pageSize) {
                    break;
                }
                $tmp = UserRedis::getUserInfo($value);
                if (empty($tmp)) {
                    continue;
                }
                $u["nickname"] = isset($tmp["nickname"]) ? $tmp["nickname"] : "";
                $u["avatar"] = isset($tmp["avatar"]) ? Service::avatar_small($tmp["avatar"]) : "";
                $u["guid"] = isset($tmp["guid"]) ? $tmp["guid"] : "";
                $u["order"] = $order;
                $u["type"] = Channel::fans_type_view;
                $point = isset($tmp['point']) ? $tmp['point'] : 0;
                $u["username"] = isset($tmp["username"]) ? $tmp["username"] : "";
                $u["level"] = Service::userLevel($point);
//                $u["diamonds"] =  Util::number_format_short((float)BroadcastTopRedis::Score($host_id, $value), 0);
                $u["diamonds"] = (int)$value;
                $viewData[] = $u;
                ++$order;
                unset($tmp);
            }
            $viewSort = [];
            foreach ($viewData as $viewDatum) {
                $viewSort[$viewDatum["diamonds"]] = $viewDatum;
            }
            krsort($viewSort);
            $data = array_merge($data, array_values($viewSort));
        }
        return $data;
    }

    /**
     * 开启jar
     * @param $user_id
     * @param $channel_id
     * @param string $arg3
     * @return bool
     */
    public static function startJar($user_id, $channel_id, $arg3 = '1')
    {
        return Agora::StartSignalJar($user_id, $channel_id, $arg3);
        $chat_service = Yii::$app->params['chat_service'];
        $account = (int)(microtime(true) * 1000);
//        $agora_token = Agora::generateSignalChannelKey($appID, $appCertificate, $account, 3600 * 24);
        $rtm_token = Agora::GenerateRtmTokenConsole((string)$account);
        try {
            //解决OpenSSL Error问题需要加第二个array参数，具体参考 http://stackoverflow.com/questions/25142227/unable-to-connect-to-wsdl
            $client = new \SoapClient($chat_service . "/chatroom?wsdl", array("stream_context" => stream_context_create(array('ssl' => array('verify_peer' => false, 'verify_peer_name' => false,)))));
            //print_r($client->getVersion());
            $client->soap_defencoding = 'utf-8';
            $client->xml_encoding = 'utf-8';
            $param = array('arg0' => $user_id, 'arg1' => $channel_id, 'arg2' => $rtm_token, 'arg3' => $arg3, 'arg4' => $account);//参数拼接xml字符串
            $client->startSignal($param);
        } catch (\SOAPFault $e) {
            return false;
        }
    }


    public static function QuizCommentJar($user_id, $channel_id, $arg3 = '2')
    {
        return Agora::StartSignalJar($user_id, $channel_id, $arg3);
        try {
            $chat_service = Yii::$app->params['chat_service'];
            $account = (int)(microtime(true) * 1000);
//        $agora_token = Agora::generateSignalChannelKey($appID, $appCertificate, $account, 3600 * 24);
            $rtm_token = Agora::GenerateQuizCommentRtmTokenConsole((string)$account);
            //解决OpenSSL Error问题需要加第二个array参数，具体参考 http://stackoverflow.com/questions/25142227/unable-to-connect-to-wsdl
            $client = new \SoapClient($chat_service . "/chatroom?wsdl", array("stream_context" => stream_context_create(array('ssl' => array('verify_peer' => false, 'verify_peer_name' => false,)))));
            //print_r($client->getVersion());
            $client->soap_defencoding = 'utf-8';
            $client->xml_encoding = 'utf-8';
            $param = array('arg0' => $user_id, 'arg1' => $channel_id, 'arg2' => $rtm_token, 'arg3' => $arg3, 'arg4' => $account);//参数拼接xml字符串
            $client->startSignal($param);
            return $param;
        } catch (\SOAPFault $e) {
            return false;
        }
    }

    /**
     * 开启离线模式
     * @param $user_id
     * @param $channel_id
     * @param $asap
     */
    public static function StartOfflineModel($user_id, $channel_id, $asap)
    {
//        $channel =  Channel::find()->where(["guid"=>$channel_id, "user_id"=>$user_id])->one();
//        if(empty($channel)){
//           return false;
//        }
//        if($channel->asap != Campaign::userTypeAsap1){
//            return false;
//        }
        $offlineChanel = UserAsapInfo::CreateOfflineLive($user_id, $channel_id, $asap);
        BroadcastRedis::SetLiveOfflineModelHash($user_id, $offlineChanel);
//        //启动jar包
        self::startJar($user_id, $offlineChanel);
//        BroadcastRedis::broadcastInfo($channel_id, ['type' => 4]);
//        $channel->type = 4;
//        $channel->save();

    }

    /**
     * 结束离线模式
     * @param $user_id
     * @param $channel_id
     */
    public static function StopOfflineModel($user_id)
    {
//        $channel_id =  BroadcastRedis::GetLiveOfflineModelHash($user_id);
//        $channel =  Channel::find()->where(["guid"=>$channel_id, "user_id"=>$user_id])->one();
//        if(empty($channel)){
//            return false;
//        }
        $channel_id = BroadcastRedis::GetLiveOfflineModelHash($user_id);
        if (empty($channel_id)) {
            return false;
        }
        BroadcastRedis::RemoveLiveOfflineModelHash($user_id);
        $channel = Channel::find()->where(["guid" => $channel_id])->one();
        if (empty($channel)) {
            return false;
        }
        $channel->type = Channel::type_status_end;
        $channel->deleted = "yes";
        if (!$channel->save()) {
            return false;
        }
        BroadcastRedis::broadcastInfo($channel_id, ["type" => Channel::type_status_end]);
        return true;
//        BroadcastRedis::broadcastInfo($channel_id, ['type' => 2]);
//        $channel->type = 2;
//        $channel->save();
    }


    /**
     * 初始化audio数据
     * @param $channel_id
     * @param null $channelModel
     * @param $slots_lock
     * @param int $mode
     * @param int $open_slots_number 开放的槽位数，比如10个只开5个，$slots_lock=0时有效
     * @return mixed
     */
    public static function InitAudioCohost($channel_id, $channelModel = null, $slots_lock, $mode = Channel::mode_audio_cohost, $open_slots_number = 0)
    {
        if (empty($channelModel)) {
            $channelModel = Channel::find()->where(["guid" => $channel_id])->one();
            if (empty($channelModel)) {
                Yii::error("InitAudioCohost channelModel not found" . json_encode($channelModel));
                return false;
            }
        }

        $cohost_any = isset($channelModel['cohost_any']) ? intval($channelModel['cohost_any']) : 1;
        $diamond_ratio = isset($channelModel['diamond_ratio']) ? intval($channelModel['diamond_ratio']) : 0;

        //初始化槽位
        if ($slots_lock == 1) { //槽位全部关闭
            $locked = BroadcastAudioRedis::SlotsLocked;
        } else {
            $locked = BroadcastAudioRedis::SlotsUnLocked;
        }
        //设定slot数量
        $totalSlot = self::getSlotsNumByCohost($mode);
        if ($open_slots_number <= 0) {
            $open_slots_number = $totalSlot;
        } else {
            $open_slots_number = $totalSlot >= $open_slots_number ? $open_slots_number : $totalSlot;
        }
        $slotsData = self::InitSlots($totalSlot, $locked, $open_slots_number);
        $res = BroadcastAudioRedis::SetSlots($channel_id, $slotsData);
        BroadcastRedis::broadcastInfo($channel_id, [
            "cohost_any" => $cohost_any,
            "diamond_ratio" => $diamond_ratio,
            "slots_version" => time(),
        ]);
        return $slotsData;
    }

    /**
     * 通过mode_audio_cohost  获取槽位数 seat xxx _slots
     * @param string $mode
     * @return int
     */
    public static function getSlotsNumByCohost($mode = '')
    {
        switch ($mode) {
            case Channel::mode_5_seat:
                $totalSlot = self::seat5_slots;
                break;
            case Channel::mode_6a_seat:
            case Channel::mode_6b_seat:
            case Channel::mode_6_seat:
                $totalSlot = self::seat6_slots;
                break;
            case Channel::mode_4_seat:
                $totalSlot = self::seat4_slots;
                break;
            case Channel::mode_9_seat:
                $totalSlot = self::seat9_slots;
                break;
            case Channel::mode_10_seat:
                $totalSlot = self::seat10_slots;
                break;
            case Channel::mode_audio_cohost:
            default:
                $totalSlot = self::audio_slots;
                break;
        }
        return $totalSlot;
    }

    /**
     * 修改槽位信息，主要live_type=17直播间使用，达到在不改变已经在槽位上的人信息的情况下，关闭一些槽位
     * @param string $channel_id 直播间guid
     * @param null $channelModel
     * @param $slots_lock
     * @param int $mode
     * @param int $open_slots_number 开放的槽位数，比如10个只开5个，$slots_lock=0时有效
     * @return
     */
    public static function opSlotInformation($channel_id, $channelModel = null, $slots_lock, $mode = Channel::mode_audio_cohost, $open_slots_number = 0)
    {
        if (empty($channel_id)) {
            return self::InitAudioCohost($channel_id, $channelModel, $slots_lock, $mode, $open_slots_number);
        }
        //获取槽位信息
        $slot_info = [];
        $slot_info = BroadcastAudioRedis::GetSlots($channel_id);
        if (empty($slot_info)) {
            return self::InitAudioCohost($channel_id, $channelModel, $slots_lock, $mode, $open_slots_number);
        }
        //查询需要关闭几个槽位
        $totalSlot = self::getSlotsNumByCohost($mode);
        $close_num = 0;
        if ($open_slots_number > 0) {
            $close_num = $totalSlot - $open_slots_number;
            $close_num = $close_num <= 0 ? 0 : $close_num;
        }
        if ($close_num >= 0) {
            $i = 0;
            krsort($slot_info);
            foreach ($slot_info as $slot_num => $slot) {
                $slot = json_decode($slot, true);
                if ($close_num > $i) {
                    if (!empty($slot['user'])) {
                        continue;
                    } else {
                        $i++;
                        if ($slot['locked'] == BroadcastAudioRedis::SlotsUnLocked) {
                            $slot['locked'] = BroadcastAudioRedis::SlotsLocked;
                            BroadcastAudioRedis::SetSlotsOne($channel_id, $slot['slot_number'], json_encode($slot));
                        }
                    }
                } else {
                    if ($slot['locked'] == BroadcastAudioRedis::SlotsLocked) {
                        $slot['locked'] = BroadcastAudioRedis::SlotsUnLocked;
                        BroadcastAudioRedis::SetSlotsOne($channel_id, $slot['slot_number'], json_encode($slot));
                    }
                }
            }
        }
    }


    /**
     * 默认的audio槽
     * @param $slotsTotal
     * @param $locked
     * @param int $open_slots_number 开放的槽位数，比如10个只开5个，$locked=0时有效
     * @return array
     */
    public static function InitSlots($slotsTotal, $locked, $open_slots_number = 0)
    {
        //$locked = 0时$open_slots_number有效
        $data = [];
        for ($i = 0; $i < $slotsTotal; ++$i) {
            $slot_number = $i + 1;
            $slots = [
                "slot_number" => (string)$slot_number,
                "locked" => $locked, //1.主播锁定了位置  0.未锁定
            ];
            if ($locked == BroadcastAudioRedis::SlotsUnLocked && $slot_number > $open_slots_number && $open_slots_number > 0) {
                $slots['locked'] = BroadcastAudioRedis::SlotsLocked;//达到只开放部分槽位功能
            }
            $data[$slot_number] = json_encode($slots);
        }
        return $data;
    }

    /**
     * 获取audio slots数据
     * @param $channel_id
     * @return array
     */
    public static function GetSlotsData($channel_id)
    {
        $slotsData = BroadcastAudioRedis::GetSlots($channel_id);
        $data = [];
        ksort($slotsData);
        $uids = [];
        foreach ($slotsData as $key => $datum) {
            $tmp = json_decode($datum, true);
            if (!is_array($tmp)) {
                continue;
            }
            $uid = $tmp['user']['uid'] ?? '';
            if (!empty($uid)) {
                if (in_array($uid, $uids)) {
                    //槽位重复，去掉
                    if (isset($tmp['user']) && isset($tmp['slot_number']) && $tmp['slot_number'] > 0) {
                        unset($tmp['user']);
                        $slotsNumber = $tmp['slot_number'];
                        BroadcastAudioRedis::SetSlotsOne($channel_id, $slotsNumber, json_encode($tmp));
                    }
                }
                $uids[] = $uid;
            }
            $data[] = $tmp;
        }

        return $data;
    }


    /**
     * 主播打开/关闭只能好友连麦的选项
     * @param $channel_id
     * @param $value
     * @return mixed
     */
    public static function AudioCohostSetting($channel_id, $value)
    {
        return BroadcastRedis::broadcastInfo($channel_id, [
            "cohost_any" => (int)$value,
        ]);
    }

    /**
     * 打开, 或者关闭一个槽
     * @param $channel_id
     * @param $slotsNumber
     * @param $locked_status
     * @return bool|mixed
     */
    public static function AudioSlotsSetting($channel_id, $slotsNumber, $locked_status)
    {
        $response = ["error" => true];
        /*$data = json_decode($value, true);
        if(empty($data)){
            return false;
        }
        $slotsNumber = $data["slots_number"];
        $locked_status = $data["locked_status"];*/
        $slotsDataStr = BroadcastAudioRedis::GetSlots($channel_id, $slotsNumber);
        $slotsData = json_decode($slotsDataStr, true);
        if (empty($slotsData)) {
            $response["message"] = "not found slot";
            return $response;
        }

        switch ($locked_status) {
            case BroadcastAudioRedis::SlotsLocked:
                if (!empty($slotsData["user"])) {//有用户在槽内不能关闭
                    $response["message"] = "No slots available to join";
                    return $response;
                }
                $slotsData["locked"] = BroadcastAudioRedis::SlotsLocked;
                break;
            case BroadcastAudioRedis::SlotsUnLocked:
                $slotsData["locked"] = BroadcastAudioRedis::SlotsUnLocked;
                break;
            default:
                break;
        }

        BroadcastAudioRedis::SetSlotsOne($channel_id, $slotsNumber, json_encode($slotsData));
        $response["error"] = false;
        $live_info = Channel::getbroadcastInfo($channel_id, ['live_type', 'game_match_id']);
        if (!empty($live_info['live_type']) && $live_info['live_type'] == self::GameMatchLive) {
            //统计槽位数，快速游戏匹配时要用
            Match::refreshSlot($channel_id, $live_info['game_match_id']);
        }
        return $response;
    }

    /**
     * 增加audio slots 版本号
     * @param $channel_id
     * @return mixed
     */
    public static function IncSlotsVersion($channel_id)
    {
        return (int)BroadcastRedis::numberAdd($channel_id, "slots_version", 1);
    }

    public static function CurrentSlotsVersion($channel_id)
    {
        return (int)self::getbroadcastInfo($channel_id, "slots_version");
    }

    public static function getMinSlotsNumber($channel_id)
    {
        $slots = BroadcastAudioRedis::GetSlots($channel_id);
        ksort($slots);
        foreach ($slots as $slots_number => $data) {
            $slot = json_decode($data, true);
            if (!$slot) {
                continue;
            }
            if (empty($slot["user"])) {
                $slot_number = isset($slot["slot_number"]) ? $slot["slot_number"] : "";
                if ($slot_number && $slot["locked"] == BroadcastAudioRedis::SlotsUnLocked) {
                    return $slot_number;
                }
            }
        }
        return "";
    }

    /**
     * 加入语音cohost
     * @param $channel_id
     * @param $user_id
     * @param string $slots_number
     * @param int $mode
     * @param int $no_auto_number $slots_number 为空时自动找槽时不能使用的槽
     * @param bool $is_can_change_location 是否能移动位置
     * @param bool $is_auto_unlock 是否自动打开锁住得槽
     * @param bool $is_host_can_join 主播能不能加入槽，默认不行，=7的直播可以
     * @return array
     */
    public static function JoinAudioCost($channel_id, $user_id, $slots_number = "", $mode = Channel::mode_audio_cohost, $is_can_change_location = false, $no_auto_number = 0, $is_auto_unlock = false, $is_host_can_join = false)
    {

        $response = ["error" => true, "code" => Code::Fail];
        if (empty($slots_number)) {
            //自动化找个空槽
            $slotsDataArr = BroadcastAudioRedis::GetSlots($channel_id);
            if (empty($slotsDataArr)) {
                $response["message"] = "No Slots Info.v1";
                $response["code"] = Code::NoSlotsAvailable;
                return $response;
            }
            ksort($slotsDataArr);
            foreach ($slotsDataArr as $slots_number => $data) {
                $slot = json_decode($data, true);
                if (!$slot) {
                    continue;
                }
                if (!isset($slot['user']) || empty($slot['user'])) {
                    $d_slot_number = isset($slot["slot_number"]) ? $slot["slot_number"] : "";
                    if ($d_slot_number && ($is_auto_unlock || $slot["locked"] == BroadcastAudioRedis::SlotsUnLocked)) {
                        if ($no_auto_number > 0 && $no_auto_number == $d_slot_number) {
                        } else {
                            $slots_number = $d_slot_number;
                            break;
                        }
                    }
                }
            }
            if (empty($slots_number)) {
                $response["message"] = "No Slots Available";
                $response["code"] = Code::NoSlotsAvailable;
                return $response;
            }
        }

        $slotsDataStr = BroadcastAudioRedis::GetSlots($channel_id, $slots_number);
        if (empty($slotsDataStr)) {
            $response["message"] = "The slot was not found!";
            return $response;
        }
        $slotsData = json_decode($slotsDataStr, true);
        if (empty($slotsDataStr)) {
            $response["message"] = "The slot was not found";
            return $response;
        }
        //加入的位置上已经存在用户
        if (!empty($slotsData["user"]['guid']) && $slotsData["user"]['guid'] != $user_id) {
            $response["message"] = "There are already users at this slots";
            return $response;
        }
        if (!$is_auto_unlock && $slotsData["locked"] == BroadcastAudioRedis::SlotsLocked) {
            $response["message"] = "Can't join a locked slot";
            return $response;
        }

        //如果用户已经在另一个槽上面
        $oldSlotNumber = self::CheckSlotForUser($channel_id, $user_id);
        if (!empty($oldSlotNumber)) {
            try {
                //针对用户开的直播，让特邀嘉宾能从其他曹直接跳到特邀嘉宾曹
                if ($is_can_change_location && $slots_number == 1 && $oldSlotNumber != $slots_number) {
                    //移除原先曹信息，加入1曹里面
                    $oldSlotInfo = BroadcastAudioRedis::GetSlots($channel_id, $oldSlotNumber);
                    if ($oldSlotInfo) {
                        $oldSlotInfo = json_decode($oldSlotInfo, true);
                        if (!empty($oldSlotInfo['user'])) {
                            $myuserinfo = [];
                            $myuserinfo = $oldSlotInfo['user'];
                            unset($oldSlotInfo['user']);
                            $update_data = [];
                            $slotsData['user'] = $myuserinfo;
                            $update_data[$oldSlotNumber] = json_encode($oldSlotInfo);
                            $update_data[$slots_number] = json_encode($slotsData);
                            if (!BroadcastAudioRedis::MSetSlotsOne($channel_id, $update_data)) {
                                $response["message"] = "Switch failed";
                                return $response;
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                Yii::info('JoinAudioCost=' . json_encode($e->getMessage()), 'broadcast');
            }
            $response["error"] = false;
            $response["message"] = "success";
            return $response;
        }

        //判断是否只有好友能加入
        $channelInfo = self::getbroadcastInfo($channel_id, ['user_id', 'cohost_any', 'live_type', 'channel_id', 'game_match_id']);
        $host_id = isset($channelInfo["user_id"]) ? $channelInfo["user_id"] : "";
        $cohost_any = isset($channelInfo["cohost_any"]) ? $channelInfo["cohost_any"] : BroadcastAudioRedis::cohost_any_all;
        if ($cohost_any == BroadcastAudioRedis::cohost_any_friend) {
            $isFriend = UserRedis::friendsScore($host_id, $user_id);
            if (!$isFriend) {
                $response["message"] = "Only Friends can join the call";
                $response["code"] = Code::OnlyFriendsJoinCall;
                return $response;
            }
        }

        if (!$is_host_can_join && $host_id == $user_id) {
            $response["message"] = "Host can't join cohost";
            return $response;
        }
        $userInfo = User::userInfo($user_id);
        if (empty($userInfo)) {
            $response["message"] = "not found user";
            return $response;
        }

        $user = [];
        $user["uid"] = isset($userInfo["id"]) ? (int)$userInfo["id"] : 0;
        $user["guid"] = isset($userInfo["guid"]) ? $userInfo["guid"] : "";
        $user["avatar"] = isset($userInfo["avatar"]) ? Service::getCompleteUrl($userInfo["avatar"]) : "";
        $user["avatar_small"] = isset($userInfo["avatar_small"]) ? Service::getCompleteUrl($userInfo["avatar_small"]) : "";
        $user["username"] = isset($userInfo["username"]) ? $userInfo["username"] : "";
        $user["intro"] = $userInfo["intro"] ?? '';
        $user["gender"] = $userInfo["gender"];
        $user['my_role'] = ChannelModerators::GetRole($channel_id, $user_id);
        $user["diamond"] = 0;
        $user["mute_by_host"] = BroadcastAudioRedis::unmute_by_host;
        $user["mute_by_self"] = BroadcastAudioRedis::unmute_by_self;
        $user["frame_img"] = isset($userInfo['frame_img']) ? Service::getCompleteUrl($userInfo['frame_img']) : '';
        if ($mode == Channel::mode_4_seat) {
            $user["video_mute_by_host"] = BroadcastAudioRedis::video_unmute_by_host;
            $user["video_mute_by_self"] = BroadcastAudioRedis::video_unmute_by_self;
            $user["avatar"] = isset($userInfo["avatar"]) ? Service::getCompleteUrl($userInfo["avatar"]) : "";
        }
        $slotsData["user"] = $user;

        //为7的，join 时 +1 score, 新加，快速加入接口使用
        if ($channelInfo['live_type'] == self::liveTypeRobotAuto) {
            $slotsData["locked"] = BroadcastAudioRedis::SlotsUnLocked;
        }
        if ($channelInfo['live_type'] == self::liveTypePrivate) {
            $slotsData["locked"] = BroadcastAudioRedis::SlotsUnLocked;
        }

        BroadcastAudioRedis::SetSlotsOne($channel_id, $slots_number, json_encode($slotsData));
        if (json_last_error()) {
            Yii::info("JoinAudioCost json_last_error: " . json_last_error(), 'broadcast');
        }
        BroadcastRedis::addBroadcastCoUser($channel_id, $user_id);

        if ($channelInfo['live_type'] == self::GameMatchLive) {
            //游戏匹配shang麦时，更新槽位数,判断是否满槽
            $game_match_id = $channelInfo['game_match_id'] ?? 0;
            if ($game_match_id > 0) {
                Match::refreshSlot($channel_id, $game_match_id);
                GameMatchRedis::AddRecentRoom($user_id, $game_match_id, $channel_id);
            }
        }
        $response["error"] = false;
        $response["message"] = "success";
        return $response;

    }

    /***是否有开放的空槽位
     * @param string $channel_id
     * @return bool
     */
    public static function isExistEmptySlot($channel_id = '')
    {
        $is_have_empty_slot = false;//是否还有空位子
        if ($channel_id) {
            $slotsDataArr = BroadcastAudioRedis::GetSlots($channel_id);
            if (!empty($slotsDataArr)) {
                foreach ($slotsDataArr as $slots_number => $data) {
                    $slot = json_decode($data, true);
                    if (!$slot) {
                        continue;
                    }
                    if (isset($slot['locked']) && $slot['locked'] == BroadcastAudioRedis::SlotsUnLocked) {
                        $is_have_empty_slot = true;
                    }
                }
            }
        }
        return $is_have_empty_slot;
    }

    /**
     * 离开语音连麦
     * @param $channel_id
     * @param $user_id
     * @param string $slots_number
     * @return array
     */
    public static function LeaveAudioCost($channel_id, $user_id, $slots_number = "")
    {
        $response = ["error" => false, "message" => ""];
        if (empty($slots_number) || $slots_number == "-1") {
            $slots_number = self::CheckSlotForUser($channel_id, $user_id);
            if (empty($slots_number)) {//没有找到槽直接返回成功
                $response["error"] = false;
                $response["message"] = "success";
                return $response;
            }
        }

        $slotsDataStr = BroadcastAudioRedis::GetSlots($channel_id, $slots_number);
        if (empty($slotsDataStr)) {
            $response["message"] = "The slot was not found!";
            return $response;
        }
        $slotsData = json_decode($slotsDataStr, true);
        if (empty($slotsDataStr)) {
            $response["message"] = "The slot was not found";
            return $response;
        }
        //位置上没有用户
        if (empty($slotsData["user"])) {
            $response["message"] = "There are no users on the slot";
            return $response;
        }
        //用户不再这个位置
        if ($slotsData["user"]["guid"] != $user_id) {
            $response["message"] = "You are not in this slots";
            return $response;
        }
        unset($slotsData["user"]);
        $broadcastInfo = self::getbroadcastInfo($channel_id, ['live_type', 'game_match_id', 'user_id']);
        //为7的，join 时 +1 score, 新加，快速加入接口使用
        if ($broadcastInfo['live_type'] == self::liveTypeRobotAuto) {
            $slotsData["locked"] = BroadcastAudioRedis::SlotsLocked;
        }
        if ($broadcastInfo['live_type'] == self::liveTypePrivate) {
            //=8离开默认锁住
            $slotsData["locked"] = BroadcastAudioRedis::SlotsLocked;
        }
        //移除对应
        BroadcastRedis::remBroadcastCoUser($user_id);

        BroadcastAudioRedis::SetSlotsOne($channel_id, $slots_number, json_encode($slotsData));
        if (json_last_error()) {
            Yii::error("LeaveAudioCost json_last_error: " . json_last_error());
        }

        if ($broadcastInfo['live_type'] == self::GameMatchLive) {//游戏匹配下麦时，更新槽位数
            $game_match_id = $broadcastInfo['game_match_id'] ?? false;
            $use_slot = 0;
            $need_slot = Channel::seat10_slots;
            if ($game_match_id) {
                GameMatchRedis::DelRecentRoom($user_id, $game_match_id);
                $use_slot = Match::refreshSlot($channel_id, $game_match_id);
                //进行匹配历史记录规则
                $match_config = Match::getConfigList('id');
                $need_slot = $match_config[$game_match_id]['slot_number'] ?? Channel::seat10_slots;
            }

            if ($user_id != $broadcastInfo['user_id'] && Channel::seat10_slots - $use_slot >= $need_slot - 2) {
                GameMatchRedis::AddGameHistory($user_id, $channel_id);
            }
        }
        Agora::JarPushMessage($channel_id, Agora::LEAVE_AUDIO_COST, ['user_id' => $user_id]);
        $response["error"] = false;
        $response["message"] = "success";
        return $response;

    }

    public static function HostMuteCohost($channel_id, $slots_number, $mute_status, $mute = BroadcastAudioRedis::mute_audio, $video = Channel::mode_audio_cohost)
    {
        $response = ["error" => true];
        $slotsDataStr = BroadcastAudioRedis::GetSlots($channel_id, $slots_number);
        $slotsData = json_decode($slotsDataStr, true);
        if (empty($slotsDataStr)) {
            $response["message"] = "The slot was not found";
            return $response;
        }
        //位置上没有用户
        if (empty($slotsData["user"])) {
            $response["message"] = "There are no users on the slot";
            return $response;
        }

        //4 seat 情况下host不能对已经disable的cohost进行操作
        if ($video == Channel::mode_4_seat) {
            if ($mute == BroadcastAudioRedis::mute_video) {
                if (isset($slotsData["user"]["video_mute_by_self"]) && $slotsData["user"]["video_mute_by_self"] == BroadcastAudioRedis::video_mute_by_self) {
                    $response["message"] = "cohost has disabled his/her video";
                    return $response;
                }
            }
            if ($mute == BroadcastAudioRedis::mute_audio) {
                if (isset($slotsData["user"]["mute_by_self"]) && $slotsData["user"]["mute_by_self"] == BroadcastAudioRedis::mute_by_self) {
                    $response["message"] = "cohost has disabled his/her audio";
                    return $response;
                }
            }
        }

        if ($mute == BroadcastAudioRedis::mute_video) {
            $slotsData["user"]["video_mute_by_host"] = (int)$mute_status;
        } else {
            $slotsData["user"]["mute_by_host"] = (int)$mute_status;
        }
        BroadcastAudioRedis::SetSlotsOne($channel_id, $slots_number, json_encode($slotsData));
        if (json_last_error()) {
            Yii::error("HostMuteCohost json_last_error: " . json_last_error());
        }
        $response["error"] = false;
        $response["message"] = "success";
        return $response;
    }

    public static function AudioMuteSelf($channel_id, $user_id, $slots_number, $mute_status, $mute = BroadcastAudioRedis::mute_audio, $video = Channel::mode_audio_cohost)
    {
        $slotsData = [];
        $response = ["error" => true];
        $slotsDataStr = BroadcastAudioRedis::GetSlots($channel_id, $slots_number);
        if (is_array($slotsDataStr)) {
            foreach ($slotsDataStr as $v) {
                $v = json_encode($v, true);
                if (isset($v['user']) && !empty($v['user']['guid']) && $v['user']['guid'] == $user_id) {
                    $slotsData = $v;
                    break;
                }
            }
        } else {
            $slotsData = json_decode($slotsDataStr, true);
        }
        if (empty($slotsDataStr) || empty($slotsData)) {
            $response["message"] = "The slot was not found";
            return $response;
        }
        //位置上没有用户
        if (empty($slotsData["user"])) {
            $response["message"] = "There are no users on the slot";
            return $response;
        }
        if ($slotsData["user"]["guid"] != $user_id) {
            $response["message"] = "You are not in this slot";
            return $response;
        }

        if ($mute == BroadcastAudioRedis::mute_video) {
            if (isset($slotsData["user"]["video_mute_by_host"]) && ($slotsData["user"]["video_mute_by_host"] == BroadcastAudioRedis::video_mute_by_host)) {
                $response["message"] = "You have been mute by the host";
                return $response;
            }
            $slotsData["user"]["video_mute_by_self"] = (int)$mute_status;
        } else {
            if (isset($slotsData["user"]["mute_by_host"]) && $slotsData["user"]["mute_by_host"] == BroadcastAudioRedis::mute_by_host) {
                $response["message"] = "You have been mute by the host";
                return $response;
            }
            $slotsData["user"]["mute_by_self"] = (int)$mute_status;
        }

        BroadcastAudioRedis::SetSlotsOne($channel_id, $slots_number, json_encode($slotsData));
        if (json_last_error()) {
            Yii::error("AudioMuteSelf json_last_error: " . json_last_error());
        }
        $response["error"] = false;
        $response["message"] = "success";
        return $response;
    }

    /**
     * 查找用户是否在一个槽上面
     * @param $channel_id
     * @param $user_id
     * @return string
     */
    public static function CheckSlotForUser($channel_id, $user_id)
    {
        $slots = BroadcastAudioRedis::GetSlots($channel_id);
        foreach ($slots as $slots_number => $data) {
            $slot = json_decode($data, true);
            if (!$slot) {
                continue;
            }
            if (!empty($slot["user"])) {
                if ($slot["user"]["guid"] == $user_id) {
                    return $slot["slot_number"];
                }
            }
        }
        return "";
    }

    /**
     * 槽位和user_id相对应的map
     * @param $channel_id
     * @return array
     */
    public static function AudioSlotUserMap($channel_id)
    {
        $data = [];
        $slots = BroadcastAudioRedis::GetSlots($channel_id);
        foreach ($slots as $slots_number => $slot) {
            $slot = json_decode($slot, true);
            if (!$slot) {
                continue;
            }
            if (!empty($slot["user"])) {
                $data[$slot["slot_number"]] = $slot["user"]["guid"];
            }
        }
        return $data;
    }

    public static function AudioCohostIncDiamond($channel_id, $cohost_guid, $slot_number, $inc)
    {
        $slotData = BroadcastAudioRedis::GetSlots($channel_id, $slot_number);
        if (empty($slotData)) {
            return false;
        }
        $slotData = json_decode($slotData, true);
        if (empty($slotData["user"])) {
            return false;
        }
        if ($slotData["user"]["guid"] != $cohost_guid) {
            return false;
        }
        $slotData["user"]["diamond"] += $inc;
        BroadcastAudioRedis::SetSlotsOne($channel_id, $slot_number, json_encode($slotData));
        return true;
    }


    public static function AudioShareDiamond($channel_id, $diamond)
    {

    }

    /**
     * 获取人数最高的直播
     * @param int $mode 直播形式
     * @return mixed
     */
    public static function GetHeightViewerLive($mode = self::mode_video)
    {
        $channelList = BroadcastRedis::GetAllLivestreamsView();
        foreach ($channelList as $channel_id) {
            $video = self::getbroadcastInfo($channel_id, "video");
            if ($video != $mode) {
                continue;
            }
            return $channel_id;
        }
        return "";
    }


    /**
     * 获取人数最高的直播列表
     * @param int $total
     * @param int $mode
     * @return array
     */
    public static function GetHeightViewerLiveList($total = 6, $mode = self::mode_video)
    {
        $data = [];
        $channelList = BroadcastRedis::GetAllLivestreamsView();
        foreach ($channelList as $channel_id) {
            $video = self::getbroadcastInfo($channel_id, "video");
            if ($video != $mode) {
                continue;
            }
            $data[] = $channel_id;
            if (count($data) >= $total) {
                break;
            }
        }
        return $data;
    }

    public static function Join($channel_id, $user_id, $check)
    {
        $response = ["error" => true, "code" => 500, "message" => "fail!", "data" => []];
        $broadcastInfo = self::getbroadcastInfo($channel_id);
        //$channel = Channel::find()->select(['id','user_id','is_live','replay_current_audience','live_current_audience','live_highest_audience','replay_highest_audience','total_viewer','total_joined_times','live_type'])->where(['guid' => $channel_id])->one();

        if (isset($broadcastInfo["video"]) && $broadcastInfo["video"] == Channel::mode_audio_cohost) {
            if (($check["device_type"] == "android" && $check["version_code"] < 530) || ($check["device_type"] == "ios" && $check["version_code"] <= 566)) {
                $response["message"] = "Please update APP to watch this livestream";
                return $response;
            }
        }

        if (!isset($broadcastInfo['user_id']) || !isset($broadcastInfo['type']) || $broadcastInfo['type'] == '2') {
            $response["message"] = "Livestream has ended.";
            return $response;
        }
        //判断是否在主播的黑名单中
        $block_list = UserRedis::blockList($broadcastInfo['user_id']);
        if (in_array($user_id, $block_list) || BroadcastRedis::getLiveBlockUserScore($channel_id, $user_id)) {
            $response["message"] = "This host has blocked you.";
            return $response;
        }

        //检查用户是否才被踢出去
        if (ModeratorsRedis::GetKick($channel_id, $user_id)) {
            $response["message"] = "You have been kicked out from the room. unable to reenter for 5 mins.";
            return $response;
        }

        //block的用户不能加入
//        if(ModeratorsRedis::IsBlock($channel_id, $user_id)){
//            return $this->error('This moderators has blocked you.');
//        }

//        记录总历史用户观看列表
//        if (!BroadcastRedis::getTotalAudience($channel_id, $user_id)) {
//            BroadcastRedis::addTotalAudience($channel_id, $user_id);
//            BroadcastRedis::numberAdd($channel_id, 'total_viewer', 1);
//        }
        //andrew: can we track number of views each livestream has example if user A watches a livestream and then leaves and comes back the number is 2

        $user_info = UserRedis::getUserInfo($user_id);
        $appID = Yii::$app->params['agora']['appID'];
        $appCertificate = Yii::$app->params['agora']['appCertificate'];
        $ts = (string)time();
        $randomInt = rand(100000000, 999999999);
        $expiredTs = 0;
        $user_list = UserRedis::FollowingFriendsList($user_id, 0, -1);
        if (in_array($broadcastInfo['user_id'], $user_list)) {
            $relation_status = 5;
        } else {
            $relation_status = 0;
        }

//        $broadcastInfo = self::getbroadcastInfo($channel_id);//获取直播间信息
//        if (empty($broadcastInfo)) {
//            return $this->error("Livestream has ended.");
//        }


        $Audience = [];

        $audienceJson = BroadcastRedis::getBroadcastAdvanceJson($channel_id);

        $audienceArr = json_decode($audienceJson, true);
        if ($audienceArr) {
            $Audience = isset($audienceArr["audience_list"]) ? $audienceArr["audience_list"] : [];
        }


        $broadcastInfo["audience_count"] = isset($broadcastInfo["audience_count"]) ? $broadcastInfo["audience_count"] : 1;//intval($broadcastInfo["audience_count"]);
        $broadcastInfo["audience_count_int"] = intval($broadcastInfo["audience_count"]);//intval($broadcastInfo["audience_count"]);
        //$broadcastInfo["audience_count"] =BroadcastRedis::countBroadcastAudience($channel_id)+1;//intval($broadcastInfo["audience_count"]);
        //当前的观众列表
        //$Advances  = Service::currentAdvance($channel_id);
        $broadcastInfo["channel_key"] = Agora::generateMediaChannelKey($channel_id, $user_info['id']);
        //生成信令key
        $broadcastInfo["signal_token"] = Agora::generateSignalChannelKey(Yii::$app->params['agora']['appID'], Yii::$app->params['agora']['appCertificate'], $user_info['guid'], 3600 * 24);
        $broadcastInfo["rtm_token"] = Agora::GenerateRtmToken($user_id);
        $broadcastInfo["relation_status"] = $relation_status;

        $broadcastInfo["chatroom_id"] = intval($broadcastInfo['id']);
        $broadcastInfo["audience"] = array_values($Audience);
        $broadcastInfo["status"] = 'Join success.';
        $broadcastInfo["video_url"] = isset($broadcastInfo["video_url"]) ? $broadcastInfo["video_url"] : '';


        $broadcastInfo["live_highest_audience"] = isset($broadcastInfo["live_highest_audience"]) ? intval($broadcastInfo["live_highest_audience"]) : 0;
        $broadcastInfo["live_end_time"] = strtotime($broadcastInfo["live_end_time"]) * 1000;
        $broadcastInfo["scheduled_time"] = isset($broadcastInfo["scheduled_time"]) ? strtotime($broadcastInfo["scheduled_time"]) * 1000 : 0;
        //get anchor avatar
        $anchorInfo = UserRedis::getUserInfo($broadcastInfo["user_id"]);
        $broadcastInfo["avatar"] = Service::avatar_small($anchorInfo["avatar"]);
        $broadcastInfo["nickname"] = $anchorInfo["nickname"];
        $broadcastInfo["username"] = $anchorInfo["username"];
        $broadcastInfo["short_id"] = intval($anchorInfo["id"]);

        $broadcastInfo["live_type"] = intval($broadcastInfo["live_type"]);

        $broadcastInfo['landscape'] = isset($broadcastInfo['landscape']) ? intval($broadcastInfo['landscape']) : 0;

        $broadcastInfo['asap'] = isset($anchorInfo['asap']) ? $anchorInfo['asap'] : '1';
        $broadcastInfo["live_start_time"] = strtotime($broadcastInfo["live_start_time"]) * 1000;
        if (Campaign::CheckAsapUser($broadcastInfo['asap'])) {
            $asap_info = AsapRedis::getAsapInfo($broadcastInfo["user_id"]);
            $vote = isset($asap_info['vote']) ? intval($asap_info['vote']) : 0;
            $broadcastInfo['red_diamond'] = isset($asap_info['red_diamond']) ? intval($asap_info['red_diamond']) : 0;
            $broadcastInfo['red_diamond'] += $vote * Words::VOTE_CONVERT;
            $broadcastInfo["duration"] = (time() - strtotime($broadcastInfo['created_at'])) * 1000;
            if ($broadcastInfo["type"] == '0') {
                $broadcastInfo["live_start_time"] = strtotime($broadcastInfo["created_at"]) * 1000;
            }

        } else {
            $broadcastInfo["duration"] = intval($broadcastInfo["duration"]) * 1000;
        }
        $broadcastInfo["golds"] = isset($broadcastInfo["golds"]) && !empty($broadcastInfo["golds"]) ? number_format($broadcastInfo["golds"], 0, '.', '') : '0';
        //ios需要的数据

        $broadcastInfo["user_info"] = ['avatar' => $broadcastInfo["avatar"], 'avatar_small' => $broadcastInfo["avatar"], 'nickname' => $anchorInfo["nickname"], 'short_id' => intval($anchorInfo["id"]), 'guid' => $anchorInfo["guid"], 'relation_status' => $relation_status, "username" => $broadcastInfo["username"], "intro" => isset($anchorInfo["intro"]) ? $anchorInfo["intro"] : ""];
        $user_like_feed = FeedRedis::getUserLikeFeed($user_id);
        $broadcastInfo['is_like'] = false;
        if (is_array($user_like_feed)) {
            if (in_array($channel_id, $user_like_feed)) {
                $broadcastInfo['is_like'] = true;
            }
        }
        if ($broadcastInfo['live_type'] == '5') {
            $broadcastInfo["product_ids"] = isset($broadcastInfo["product_ids"]) ? $broadcastInfo["product_ids"] : "";
            //$broadcastInfo["product"] = isset($broadcastInfo["product"])? json_decode($broadcastInfo["product"]): (object)[];
        }
        unset($broadcastInfo["product"]);
        $broadcastInfo["video"] = isset($broadcastInfo["video"]) ? (int)$broadcastInfo["video"] : Channel::mode_video;

        if ($broadcastInfo['live_type'] != '2') {
            $score = $broadcastInfo["audience_count"];
            if ($anchorInfo["type"] == '1') {
                if ($broadcastInfo['live_type'] == 4) {
                    $score = $broadcastInfo["audience_count"] * 100000;
                } else {
                    $score = $broadcastInfo["audience_count"] * 10000;
                }

            }
            //如果是正在进行中的直播才写入key
            if ($broadcastInfo['type'] == '1') {
                BroadcastRedis::SetAllLivestreamsView($score, $channel_id);
            }

            if ($broadcastInfo['type'] == '1' && $broadcastInfo['asap'] != '1') {
                BroadcastRedis::addASAPList($channel_id, $broadcastInfo["audience_count"], BroadcastRedis::asap_online, $broadcastInfo['asap']);
            }
        }
        //查看是否被禁言
        $broadcastInfo["mute_status"] = ChannelModerators::IsMute($channel_id, $user_id);
        unset($anchorInfo);
        Service::TaskComplete($user_id, 10);
        $broadcastInfo['is_asap_final'] = 0;//是不是asap的决赛
        if (isset($broadcastInfo['is_win']) && !empty($broadcastInfo['win_user']) && !empty($broadcastInfo['is_win'])) {
            $broadcastInfo['user_id'] = $broadcastInfo['win_user'];
            if ($broadcastInfo['landscape'] == 0 && Campaign::CheckAsapUser($broadcastInfo['asap'])) {
                $broadcastInfo['is_asap_final'] = 1;
            }
        }
        $broadcastInfo['type'] = (int)$broadcastInfo['type'];
        //增加直播间用户fans排行
        $broadcastInfo['fans_top'] = Channel::FansTopList($channel_id, $broadcastInfo["user_id"]);
        if ($broadcastInfo['video'] == Channel::mode_audio_cohost) {
            $broadcastInfo["slots"] = Channel::GetSlotsData($channel_id);
            $broadcastInfo["diamond_ratio"] = isset($broadcastInfo["diamond_ratio"]) ? (int)$broadcastInfo["diamond_ratio"] : 0;
        }
        $response = ["error" => false, "code" => 200, "message" => "success!", "data" => $broadcastInfo];
        return $response;
    }


    public static function DeleteLive($channel_id, $user_id)
    {
        $response = ["error" => true, "code" => 500, "message" => "fail!", "data" => []];
        $channel = Channel::find()->select(['id', 'guid', 'user_id', 'type', 'live_type'])->where(['guid' => $channel_id])->one();//->select(['guid','user_id'])
        //判断权限
        if ($channel->user_id != $user_id) {
            $response["message"] = "You don't have permission";
            return $response;
        }

        if ($channel->deleted == 'yes') {
            return ["error" => false, "code" => 200, "message" => "success!", "data" => []];
        }
        $old_type = $channel->type;
        $channel->deleted = 'yes';
        if ($channel->type == 1) {
            $channel->type = 2;
        }

        $channel->save();
        //删除直播信息
        BroadcastRedis::delbroadcast($channel_id);

        if ($old_type == 0 || $old_type == 1) {
            //从用户大列表里移除
            BroadcastRedis::remUserList($user_id, $channel_id);
            //从直播大列表里移除
            BroadcastRedis::remlist($channel_id);
            BroadcastRedis::remBroadcast($channel_id);
            //移除直播预告
            BroadcastRedis::remAdvance($channel_id);

            //普通人直播的集合
            BroadcastRedis::remRegularUserLive($channel_id);
        } else {
            //从历史记录里删除
            BroadcastRedis::remlist($channel_id);
            BroadcastRedis::remUserOldBroadcast($channel_id, $user_id);
            BroadcastRedis::remOldBroadcast($channel_id);
        }
        //将用户正在直播移除
        BroadcastRedis::remUserBroadcast($channel_id, $user_id);
        //移除 all-livestreams 列表
        BroadcastRedis::RemoveAllLivestreamsView($channel_id);
        if ($channel->live_type == self::liveTypePrivate) {
            //删除点单、试音 列表
            self::delPrivateOrderAndLiveAuditionList($channel_id);
        }
        User::EndLive($user_id);

        return ["error" => false, "code" => 200, "message" => "success!", "data" => []];
    }


    //删除点单、试音 列表
    public static function delPrivateOrderAndLiveAuditionList($channel_id = '')
    {
        BroadcastRedis::delPrivateLiveAuditionList($channel_id);
        BroadcastRedis::delPrivateLiveOrderList($channel_id);
    }

    /**
     * 检查直播使用使用了座位模式
     * @param $video
     * @param string $channel_id
     * @return bool
     */
    public static function CheckSeatMode($video, $channel_id = "")
    {
        if ($video == Channel::mode_5_seat || $video == Channel::mode_6_seat || $video == Channel::mode_audio_cohost || $video == Channel::mode_4_seat || $video == Channel::mode_6a_seat || $video == Channel::mode_6b_seat || $video == Channel::mode_9_seat || $video == Channel::mode_10_seat) {
            return true;
        }
        return false;
    }

    /**
     * 根据guid 获取 info
     * @param array $channel_ids
     * @return $data
     */
    public static function getInfoByIds($channel_ids = array())
    {
        if (count($channel_ids) < 1) {
            return array();
        }
        $channels = self::find()->where(['in', 'guid', $channel_ids])->orderBy('created_at DESC')->all();
        $data = array();
        $ecommerceIds = array();
        if ($channels) {
            foreach ($channels as $k => $v) {
                if ($v['live_type'] == 5) {//电商直播 redis 取值
                    $ecommerceIds[] = $v->guid;
                } else {
                    $data[] = self::formatData($v);
                }
            }
            $ecommerceLives = BroadcastRedis::getBroadcastContentBatch($ecommerceIds);
            $data = array_merge($ecommerceLives, $data);
        }
        return $data;
    }

    private static function formatData($model)
    {
        $data = [];
        if ($model) {
            $data['channel_id'] = $model->guid;
            $data['title'] = $model->title;
            $data['id'] = $model->id;
            $data['type'] = $model->type;
            $data['cover_image'] = $model->cover_image;
            $data['description'] = $model->description;
            $data['live_start_time'] = $model->live_start_time;
            $data['live_end_time'] = $model->live_end_time;
            $data['scheduled_time'] = $model->scheduled_time;
            $data['live_highest_audience'] = $model->live_highest_audience;
            $data['replay_highest_audience'] = $model->replay_highest_audience;
            $data['duration'] = $model->duration;
            $data['location'] = $model->location;
            $data['diamonds'] = $model->diamonds;
            $data['coins'] = $model->coins;
            $data['total_viewer'] = $model->total_viewer;
            $data['schedule'] = $model->schedule;
            $data['like_count'] = $model->like_count;
            if (!empty($model->hlsurl)) {
                if ($model->id > 728652) {  //2019年8月20日10:31:57 新的s3 bucket
                    $data['video_url'] = Yii::$app->params["aws"]['cloud_recording'] . $model->hlsurl;
                } else {
                    $data['video_url'] = Yii::$app->params["aws"]['url'] . $model->hlsurl;
                }

            } else {
                $data['video_url'] = $model->mp4url;
            }
            //$data['longitude']= $model->longitude;
            $data['user_id'] = $model->user_id;
            $data['audience_count'] = $model->live_current_audience;
            $data['live_highest_audience'] = $model->live_highest_audience;
            $data['post_status'] = $model->post_status;//是否推送到所有用户
            $data['post_timeline'] = $model->post_timeline;//是否推送到timeline
            $data['short_id'] = $model->id;
            //新加 2018-12-23
            $data['total_bonus'] = $model->total_bonus;
            $data['live_type'] = $model->live_type;
            if (!empty($model->group_id)) {
                $data['group_id'] = $model->group_id;
            }
            $data['sponsor'] = $model->sponsor;
            $data['channel_key'] = $model->channel_key;
            $data['test'] = $model->test;
            $data['landscape'] = $model->landscape;
            $data['video'] = $model->video;
            $data['created_at'] = $model->created_at;
            if (self::CheckSeatMode($model->video, $model->guid)) {
                $data['cohost_any'] = $model->cohost_any;
                $data['diamond_ratio'] = $model->diamond_ratio;
            }
            if (!empty($model->bg)) {
                $data['bg'] = $model->bg;
            }
        }
        return $data;
    }


    /**获取直播数据，没有从mysql 拉取
     * @param string $channel_id
     * @param string $param
     * @return array
     */
    public static function getbroadcastInfo($channel_id = '', $param = '')
    {
        if (!empty($channel_id)) {
            if (BroadcastRedis::ishavebroadcastInfo($channel_id)) {
                return BroadcastRedis::getbroadcastInfo($channel_id, $param);
            } else {
                //mysql 重载
                //垃圾请求
                if (!BroadcastRedis::isMemberInvalidChannelId($channel_id)) {
                    $data = Service::reloadBroadcast($channel_id);
                    if (empty($data)) {
                        BroadcastRedis::addInvalidChannelId($channel_id);
                    } else {
                        if ($param) {
                            if (is_array($param)) {
                                $mid = [];
                                foreach ($param as $v) {
                                    $mid[$v] = $data[$v] ?? '';
                                }
                                return $mid;
                            }
                            return $data[$param] ?? null;
                        } else {
                            return $data;
                        }
                    }
                }
            }
        }
        return [];
    }

    /**
     * 修改jar包发过来的用户信息数据
     * @param array $data
     * @param int $need_num 需要几个
     * @param bool $is_need_role 是否需要用户的权限（主播3、管理员1、游客4），=16的直播需要
     * @param array $channel_info 直播信息
     * @return array
     */
    public static function changeJarAudienceFormat($data = [], $need_num = 0, $is_need_role = false, $channel_info = [])
    {
        $return = [];
        if ($data) {
            $i = 0;
            $channel_id = $channel_info['guid'] ?? '';
            foreach ($data as $key => $one) {
                $info = $user_info = [];
                if (isset($one['guid'])) {
                    $user_info = UserRedis::getUserInfo($one['guid'], ['avatar', 'guid', 'username', 'level', 'gender', 'frame_img']);
                    if (!empty($user_info['guid'])) {
                        $user_info['avatar'] = !empty($user_info['avatar']) ? Service::getCompleteUrl($user_info['avatar']) : '';
                        $user_info['avatar_small'] = !empty($user_info['avatar']) ? Service::avatar_small($user_info['avatar']) : '';
                        $user_info['frame_img'] = !empty($user_info['frame_img']) ? Service::getCompleteUrl($user_info['frame_img']) : '';
                        $user_info['username'] = empty($user_info['username']) ? '---' : $user_info['username'];
                        $user_info['nickname'] = '';
                        $user_info['gender'] = isset($user_info['gender']) ? intval($user_info['gender']) : 2;
                        $user_info['level'] = !empty($user_info['level']) ? intval($user_info['level']) : 1;
                        //$user_info['online_status']    = isset($user_info['online_status']) && $user_info['online_status'] !== false ? intval($user_info['online_status']) : 1;
                        if (isset($one['my_role'])) {
                            $user_info['my_role'] = $one['my_role'];
                        } else {
                            $user_info['my_role'] = ChannelModerators::GetRole($channel_id, $user_info['guid'], $channel_info);
                        }
                        $return[] = $user_info;
                        if ($need_num > 0 && $i >= $need_num) {
                            break;
                        }
                        $i++;
                    }
                }
            }
        }
        return $return;
    }

    /**记录直播间对应的timeline id
     * @param string $channel_id
     * @param string $feed_id
     * @param string $user_guid
     */
    public static function rememberChannelLinkFeedid($channel_id = '', $feed_id = '', $user_guid = '')
    {
        if ($channel_id && $feed_id) {
            $model = Channel::find()->where(['guid' => $channel_id])->select(['feed_id'])->one();
            if (!$model) {
                $model = new Channel();
                $model->guid = $channel_id;
                $model->type = '2';
                $model->is_live = 'no';
                $model->user_id = $user_guid;
                $model->live_type = self::liveTypeRobotAuto;
            }
            $model->feed_id = $feed_id;
            if ($model->save()) {
                if (BroadcastRedis::ishavebroadcastInfo($channel_id)) {
                    BroadcastRedis::broadcastInfo($channel_id, ['feed_id' => $feed_id]);
                }
            }
        }
    }

    /**关播批量退款
     * @param string $channel_id
     * @param string $feed_id
     * @param string $user_guid
     */
    public static function closeReturnTicket($channel_id = '')
    {
        if (empty($channel_id)) {
            return false;
        }
        $user_list = IdolRedis::getJeepneyQueueUser($channel_id, -1);
        $diamond_model = DiamondLog::find()->where(['user_id' => $user_list, 'action_note' => ['up-jeepney', 'cutin-jeepney'], 'is_finish' => 0, 'remark' => $channel_id])->select(['user_id', 'get_diamond', 'action_note'])->orderBy('id desc')->all();

        foreach ($diamond_model as $one) {
            if (empty($one) || $one['get_diamond'] >= 0) {
                continue;
            }
            $add_diamond = abs($one['get_diamond']);
            try {
                $result = DiamondLog::adddiamond('', '', $add_diamond, $one['user_id'], "close-return", [], '', $channel_id);
            } catch (\Exception $e) {
                Yii::error('close broadcast return diamond fail,------' . $channel_id . ' ' . $e->getMessage(), DiamondLog::LOG_NAME);
                return false;
            }
        }
    }

    //直播可选用 tags 列表
    public static function tagList($label_pre = '', $is_need_all = false)
    {
        $list = Words::BROADCAST_TAGS;
        $data = [];
        if ($is_need_all) {
            $check = Service::authorization();
            if (!empty($check)) {
                if (($check['device_type'] == 'android' && $check['version_code'] >= 227) || ($check['device_type'] == 'ios' && $check['version_code'] >= 233)) {
                    $data[] = [
                        'label' => 'Joined',
                        'value' => 'joined',
                    ];
                }
            }
            $data[] = [
                'label' => 'All',
                'value' => '',
            ];
        }
        if ($list) {
            foreach ($list as $v) {
                $data[] = [
                    'label' => $label_pre . $v,
                    'value' => $v,
                ];
            }
        }
        return $data;
    }

    //直播可选用 tags 列表
    public static function languageList()
    {
        $list = Words::BROADCAST_LANGUAGE;
        if ($list) {
            foreach ($list as &$v) {
                $v['img'] = Service::getCompleteUrl($v['img']);
            }
        }
        return $list;
    }

    /**获取用户正在直播的直播间id,和类型
     * @param string $user_id
     * @param array $other_live_params 其他直播信息字段，不传默认自由live_type一个
     * @return array
     */
    public static function getUserLiveInfoById($user_id = '', $other_live_params = [])
    {
        $data = [];
        if ($user_id) {
            $channel_id = [];
            $channel_id = BroadcastRedis::getBroadcastList($user_id, 0, 1);
            if (!empty($channel_id[0])) {
                $data['channel_id'] = $channel_id[0] ?? '';
                if ($data['channel_id']) {
                    $params = [];
                    if (!empty($other_live_params) && is_array($other_live_params)) {
                        $params = $other_live_params;
                    }
                    $params[] = 'live_type';
                    $broadcastInfo = BroadcastRedis::getbroadcastInfo($data['channel_id'], $params);
                    if (isset($broadcastInfo['live_type']) && $broadcastInfo['live_type'] > 0) {
                        foreach ($params as $v) {
                            $data[$v] = empty($broadcastInfo[$v]) ? '' : $broadcastInfo[$v];
                        }
                    } else {
                        $data['channel_id'] = '';
                    }
                }

            }
        }
        return $data;
    }


    /**
     * 统计频道获得的礼物数，砖石数
     * @param string $channel_id
     * @param int $gift_num
     * @param int $coins_num
     * @param string $send_user_id 送的用户id
     * @param int $gold_pay 为了兼容老版本，默认0使用的是以前的砖石，1新的金币
     * @return array
     */
    public static function statisticsLiveGift($channel_id = '', $gift_num = 0, $coins_num = 0, $send_user_id = '', $send_per_cost = 0, $to_user_id = [], $per_cost = 0)
    {
        if (!empty($channel_id) && $gift_num > 0 && $coins_num > 0) {
            $broadcast_info = self::getbroadcastInfo($channel_id, ['id', 'channel_id', 'gifts', 'golds']);
            if (!empty($broadcast_info['channel_id']) && !empty($broadcast_info['id'])) {
                date_default_timezone_set('PRC');
                $day = date('Y-m-d');
                $channel_auto_incre_id = $broadcast_info['id'] ?? 0;
                $model = ChannelIncome::find()->where(['channel_primary_id' => $channel_auto_incre_id, 'date' => $day])->limit(1)->one();
                if (empty($model)) {
                    $model = new ChannelIncome();
                    $model->date = $day;
                    $model->channel_primary_id = $channel_auto_incre_id;
                    $model->channel_id = $broadcast_info['channel_id'];
                    $model->coins = $coins_num;
                    $model->gifts = $gift_num;
                    $result = $model->save();
                } else {
                    $result = $model->updateCounters(['coins' => $coins_num, 'gifts' => $gift_num]);
                }
                Yii::info('statisticsLiveGift=' . $channel_id . '(' . $day . '),new data gift=' . $model->gifts . ',golds=' . $model->coins . ',diamonds=0', 'broadcast');
                $up = [];
                $up = ['gifts' => (string)$model->gifts, 'golds' => $model->coins, 'diamonds' => '0'];
                if ($result) {
                    BroadcastRedis::broadcastInfo($channel_id, $up);
                    //记录用户每天送出多少到那个房间
                    RankRedis::addRoomUserCoinEveryDayUse($channel_auto_incre_id, $send_user_id, $coins_num);
                    RankRedis::addRoomUserCoinWeeklyDayUse($channel_auto_incre_id, $send_user_id, $coins_num);
                }
                $up['golds'] = number_format($up['golds'], 0, '.', '');
                $up['diamonds'] = number_format($up['diamonds'], 0, '.', '');
                try {
                    //直播间发送
                    $reg_message = array(
                        "user_id" => $send_user_id,
                        "channel_id" => $channel_id,
                        "channel_primary_id" => $channel_auto_incre_id,
                        "cost" => $coins_num,
                        'date' => date('Ymd'),
                        "per_cost" => $per_cost,
                        "send_per_cost" => $send_per_cost,
                        "to_user_id" => $to_user_id
                    );
                    $queue_name = Yii::$app->params["sqs"]['user-daily-cost'];
                    $sqs = new Livbysqs($queue_name);
                    $bool = $sqs->send($reg_message);
                    if (!$bool) {
                        Yii::info('gift/buy------user-daily-cost queue send fail!', 'interface');
                    }
                } catch (\Exception $e) {
                    Yii::info('gift/buy------user-daily-cost queue send fail!' . json_encode($e->getMessage()), 'interface');
                }
                return $up;
            }
        }
        return [];
    }

    //获取live_type=16的直播guid
    public static function getAudioLiveGuid($id = 0, $guid = '')
    {
        if (empty($id) || $id < 0) {
            return $guid;
        }
        $id = intval($id);
        return bcadd(10000000, $id);
    }

    //删除房间已占用槽位数
    public static function DelGameRoomSolt($channel_id = '', $game_id = 0)
    {
        if ($channel_id) {
            if ($game_id > 0) {
                return GameMatchRedis::DelGameRoomSolt($channel_id, $game_id);
            } else {
                //没有就拿列表循环删除
                $ids = Match::GAME_CONFIG;
                $ids = array_column($ids, 'id');
                if ($ids) {
                    foreach ($ids as $id) {
                        GameMatchRedis::DelGameRoomSolt($channel_id, $id);
                    }
                }
                return true;
            }
        }
        return false;
    }


    /**检查槽位，自动扩展
     * @param string $model
     * @param string $channel_id
     */
    public static function checkAndAutoExtend($model = '', $channel_id = '')
    {
        if ($model > 0 && $channel_id) {
            $now_slot = 0;
            $now_slot = BroadcastAudioRedis::lengthSlots($channel_id);
            if ($now_slot < $model) {
                $data = [];
                $need_add_num = $model - $now_slot;
                for ($i = 0; $i < $need_add_num; $i++) {
                    $slot_number = $i + 1 + $now_slot;
                    $slots = [
                        "slot_number" => (string)$slot_number,
                        "locked" => 0, //1.主播锁定了位置  0.未锁定
                    ];
                    $data[$slot_number] = json_encode($slots);
                }
                $res = BroadcastAudioRedis::SetSlots($channel_id, $data);
            }
        }
    }


    /**
     * 清空一个槽
     * @param $channel_id
     * @param $user_id
     * @param string $slots_number
     */
    public static function FlushSlot($channel_id, $user_id, $slots_number = "")
    {
        $result = Channel::LeaveAudioCost($channel_id, $user_id, $slots_number);
        $version = Channel::IncSlotsVersion($channel_id);
        $data = [
            "version" => $version,
            "slots" => Channel::GetSlotsData($channel_id),
        ];
        Agora::JarUpdateSlots($channel_id, $data);
    }


}