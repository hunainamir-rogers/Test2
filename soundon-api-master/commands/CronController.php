<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace app\commands;

use app\components\LiveLogic;
use app\components\redis\BroadcastRedis;
use app\components\redis\QueueRedis;
use app\models\Channel;
use app\models\Service;
use Yii;
use yii\console\Controller;

/**
 * This command echoes the first argument that you have entered.
 *
 * This command is provided as an example for you to learn how to create console commands.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @since 2.0
 */
class CronController extends Controller
{
    public function actionNotify()
    {
        $queuename = "schedule-notification";
        while (true) {
            $data = QueueRedis::lpop($queuename);
            if ($data === false) {
                break;
            }
            $channel_id = $data["channel_id"] ?? "";
            $info = BroadcastRedis::getbroadcastInfo($channel_id);
            if (empty($info)) {
                Service::log_time("Not found channel id");
                continue;
            }
            $title = $info["title"] ?? "";
            $list = BroadcastRedis::getFollower($channel_id);
            foreach ($list as $user_id) {
                Service::OnesignalNotification('The live stream you subscribed to is live - ' . $title, array(array("field" => "tag", "key" => "guid", "relation" => "=", "value" => $user_id)), '', array("channel_id" => $channel_id, 'type' => 101));
            }
        }
    }

    /**
     * 开播提醒
     */
    public function actionScheduleNotify()
    {
        $cmd = "ps -ef | grep 'cron/schedule-notify' | grep -v grep";
        exec($cmd, $output);
        if(count($output) > 2){
//            var_dump($output);
            Service::log_time("There's another one in the works");
            exit();
        }
        //提前五分钟提醒
        $notifyTime = date("Y-m-d H:i:s", time() + 60 * 5); //5分钟
        $rows = Channel::find()->where(["type" => Channel::type_status_upcoming])
            ->andWhere(["<","scheduled_time", $notifyTime])
            ->andWhere([">","scheduled_time",  date("Y-m-d H:i:s", time())])
            ->asArray()
            ->all();
        foreach ($rows as $row){
            $channel_id = $row["guid"];
            $user_id = $row["user_id"];
            $host_id = $row["user_id"];
            $title = $row["title"];
            $scheduled_time = $row["scheduled_time"];
            $content = "A live stream you've scheduled is ready";
            if(BroadcastRedis::CheckScheduleNotifyMark($channel_id)){
                continue;
            }
            $res = LiveLogic::ScheduleLiveNotify($channel_id, $user_id, $host_id, $content);
            BroadcastRedis::ScheduleNotifyMark($channel_id);
            Service::log_time("scheduled live: $channel_id, title: $title, scheduled_time: $scheduled_time push schedule notify: ".json_encode($res));
        }
    }

    public static function actionClearDirtyData(){

        $rows = Channel::find()->where(["type" => Channel::type_status_end])
            ->asArray()
            ->all();

        foreach ($rows as $row){
            $channel_id = $row["guid"];
        }
    }

}
