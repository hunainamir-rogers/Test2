<?php

namespace app\models;

use app\components\Agora;
use app\components\Livbysqs;
use app\components\redis\BroadcastRedis;
use app\components\redis\UserRedis;
use Yii;

/**
 * This is the model class for table "channel_cloud_recording".
 *
 * @property string $channel_id
 * @property string $user_id
 * @property int $uid
 * @property string $sid
 * @property string $fileList
 * @property string $resourceId
 * @property string $start_response 开始录制时api平台返回数据
 * @property string $stop_response 结束录制时api平台返回数据
 * @property string $created_at
 * @property string $updated_at
 * @property string $recordingConfig
 */
class ChannelCloudRecording extends \yii\db\ActiveRecord
{


    //录制模式
    const recRegularLayout = "regular";
    const recPkLayout = "pk";

    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'channel_cloud_recording';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['channel_id'], 'required'],
            [['uid'], 'integer'],
            [['start_response', 'stop_response'], 'string'],
            [['created_at', 'updated_at'], 'safe'],
            [['channel_id', 'user_id'], 'string', 'max' => 64],
            [['sid'], 'string', 'max' => 100],
            [['fileList'], 'string', 'max' => 255],
            [['channel_id'], 'unique'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'channel_id' => 'Channel ID',
            'user_id' => 'User ID',
            'uid' => 'Uid',
            'sid' => 'Sid',
            'fileList' => 'File List',
            'start_response' => 'Start Response',
            'stop_response' => 'Stop Response',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
            'resourceId' => 'resourceId',
            'recordingConfig' => 'recordingConfig',
        ];
    }

    /**
     * 开始云录制
     * @param $user_id
     * @param $channel_id
     * @param $video
     * @param $rec_config
     * @return bool
     */
    public static function StartRec($user_id, $channel_id)
    {
        try {
            Service::log_time("[StartRec]Channel {$channel_id}, user_id: $user_id, start cloud recording");
            $result = Agora::StartCloudRecAudio($channel_id, Agora::RecUid);
            $response = $result["response"];
            $request = $result["request"];
            if (!$response) {
                Service::log_time("[StartRec]Exception: Channel {$channel_id}, StartCloudRec fail");
                return false;
            }
            $data = json_decode($response, true);
            if (!$data) {
                Service::log_time("[StartRec]Exception: Channel {$channel_id} agora api start record json umarshal error, response: {$response}");
                return false;
            }
            if (empty($data["sid"])) {
                Service::log_time("[StartRec]Exception: Channel {$channel_id} agora api start record sid empty, response: {$response}");
                return false;
            }
            $host_uid = UserRedis::getUserInfo($user_id, "id"); //主播uid
            $RecodingModel = new ChannelCloudRecording();
            $RecodingModel->channel_id = $channel_id;
            $RecodingModel->uid = (int)$host_uid;
            $RecodingModel->resourceId = $data["resourceId"];
            $RecodingModel->user_id = $user_id;
            $RecodingModel->start_response = $response;
            $RecodingModel->sid = $data["sid"];
            $RecodingModel->recordingConfig = json_encode($request); //记录录制请求参数数据

            if (!$RecodingModel->save()) {
                Service::log_time("[startRec]Exception: Channel {$channel_id} ChannelCloudRecording save fail, response: {$response}, error message:" . json_encode($RecodingModel->getErrors()));
                return false;
            }
            Service::log_time("[StartRec]Channel {$channel_id}, user_id: $user_id, cloud recording success");
            return true;
        } catch (\Exception $exception) {
            Service::log_time("[startRec] Channel {$channel_id}, user_id: $user_id throw Exception:" . json_encode($exception->getMessage()));
            return false;
        }
    }

    public static function EndRec($channel_id)
    {
        try {
            Service::log_time("[EndRec]Channel {$channel_id}, end cloud recording");
            $channel = Channel::findOne(array('guid' => $channel_id));
            if (empty($channel)) {
                Service::log_time("[EndRec] Channel {$channel_id} does not exist \n");
                return false;
            }
            // if (!empty($channel->mp4url)) {
            //     Service::log_time("[EndRec] Channel {$channel_id} mp4url exist url: {$channel->mp4url} \n");
            //     return false;
            // }
            $RecodingModel = ChannelCloudRecording::find()->where(["channel_id" => $channel_id])->orderBy('id DESC')->one();
            if (!$RecodingModel) {
                Service::log_time("[EndRec] Channel {$channel_id}, not found RecodingModel ");
                return false;
            }
            if (!empty($RecodingModel->mp4)) {
                Service::log_time("[EndRec] Channel {$channel_id} mp3url exist url: {$RecodingModel->mp4} \n");
                return false;
            }
            if (empty($RecodingModel->sid)) {
                Service::log_time("[EndRec] Channel {$channel_id}, sid cant empty RecodingModel:" . json_encode($RecodingModel->attributes));
                return false;
            }
            $sid = $RecodingModel->sid;
            $resourceId = $RecodingModel->resourceId;

            //stop获取filelist会失败, 录制期间query下录制状态
            $queryResponse = Agora::QueryCloudRec($resourceId, $sid);
            $queryResult = json_decode($queryResponse, true);

            $RecResult = Agora::StopCloudRec($channel_id, Agora::RecUid, $sid, $resourceId);
            if (!$RecResult) {
                Service::log_time("[EndRec]Channel {$channel_id}, StopCloudRec fail");
                return false;
            }
            $stopResult = json_decode($RecResult, true);
            if (!$stopResult) {
                Service::log_time("[EndRec]Channel {$channel_id},stopResult json umarshal fail, error message:{$RecResult}, Recoding info:" . json_encode($RecodingModel->attributes));
                return false;
            }
            $videoPath = "";
            if (!empty($stopResult["serverResponse"]["fileList"])) {
                $videoPath = $stopResult["serverResponse"]["fileList"];
            } else {
                $videoPath = $RecodingModel->sid . "_" . $RecodingModel->channel_id . ".m3u8";
                Service::log_time("[EndRec]Channel {$channel_id}, video path: {$videoPath} sid.chanel_id.m3u8 generation, Recoding info:" . json_encode($RecodingModel->attributes));
            }
            $RecodingModel->stop_response = $RecResult;
            $RecodingModel->fileList = $videoPath;
            if (!$RecodingModel->save()) {
                Service::log_time("[EndRec]Channel {$channel_id}, RecodingModel save fail, error message" . json_encode($RecodingModel->getErrors()) . " RecodingModel:" . json_encode($RecodingModel->attributes));
            }
            if (empty($videoPath)) {//录制地址为空
                if (empty($queryResult["serverResponse"]["fileList"])) {
                    Service::log_time("[EndRec]Channel {$channel_id}, videoPath is empty query info: " . $queryResponse);
                    return false;
                }
                $videoPath = $queryResult["serverResponse"]["fileList"];
            }
            $info = BroadcastRedis::getbroadcastInfo($channel_id);
            if (empty($info)) {
                Service::log_time("[EndRec]Channel {$channel_id}, not found info");
                return false;
            }
            $s3_path = Yii::$app->params["aws"]["cloud_recording"]; //s3 访问地址

            Service::log_time("Channel {$channel_id} agora api response: {$RecResult}");
            $videoUri = $s3_path . $videoPath;
            $channel->mp4url = $videoUri;
            $channel->hlsurl = $videoPath;
            if (!$channel->save()) {
                Service::log_time("[EndRec] Channel {$channel_id} save error:" . json_encode($channel->getErrors()));
                return false;
            }
            return true;
        } catch (\Exception $exception) {
            Service::log_time("[EndRec] Channel {$channel_id} throw Exception:" . json_encode($exception->getMessage()));
            return false;
        }
    }
}
