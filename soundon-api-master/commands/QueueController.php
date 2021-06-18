<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace app\commands;

use app\components\redis\QueueRedis;
use app\components\Response;
use app\logic\UserLogic;
use app\models\Service;
use app\models\Sms;
use app\queue\Consume;
use phpDocumentor\Reflection\DocBlock\Tags\Var_;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * This command echoes the first argument that you have entered.
 *
 * This command is provided as an example for you to learn how to create console commands.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @since 2.0
 */
class QueueController extends Controller
{
    /**
     * 队列消费者
     * @param $queueName
     */
    public function actionWork($queueName)
    {
        while (true) {
            $data = QueueRedis::lpop($queueName);
            if ($data === false) {
                break;
            }
            self::OnesignamePush($data);
        }
    }

    public static function OnesignamePush($data)
    {
        if (empty($data)) {
            return false;
        }
        $is_mul_push = $data['is_mul_push'] ?? false;
        if (!$is_mul_push) {
            $fields = $data['fields'] ?? [];
            $extra = $data['extra'] ?? [];
            $is_record = $data['is_record'] ?? true;
            $onesignal_data = $fields['data'];
            $message = $fields['contents']['en'] ?? '';
            Service::log_time('onesignal one push data :' . json_encode($data) . ' start-----');

            $app_key = Yii::$app->params["onesignal"]["rest_api_key"] ?? '';
            $fields = array_merge($fields, $extra);

            $fields = json_encode($fields);
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json; charset=utf-8',
                'Authorization: Basic ' . $app_key));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
            curl_setopt($ch, CURLOPT_HEADER, FALSE);
            curl_setopt($ch, CURLOPT_POST, TRUE);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
            $response = curl_exec($ch);
            curl_close($ch);
            $result = json_decode($response, true);


            Service::log_time('is_record :' . $is_record);
        } else {
            //批量推送
            Service::log_time('onesignal mul push start-----');
            $users = $data['users'] ?? [];//推送的人
            Service::log_time(json_encode($data));
            //批量推送类型,1给好友推送房间上槽信息
            $mul_push_type = $data['mul_push_type'] ?? -1;
            $user_id = $data['user_id'] ?? '';
            $channel_id = $data['data']['channel_id'] ?? '';
            $post_status = $data['post_status'] ?? -1;//group房间要过滤，不能给不是group的成员发送
            Service::log_time('onesignal mul push mul_push_type=' . $mul_push_type . ',user_id=' . $user_id . ',channel_id=' . $channel_id);
            if (empty($users) || !is_array($users)) {
                Service::log_time('users is null or not array');
            } else {
                $users = array_unique($users);
                Service::log_time('onesignal mul uids data :' . json_encode($users));
                try {
                    $filters = [];
                    $page_size = 97;
                    if (count($users) > $page_size) {
                        $users = array_chunk($users, $page_size);
                    } else {
                        $users = [$users];
                    }
                    foreach ($users as $one) {
                        $filters = [];
                        foreach ($one as $k => $v) {
                            $filters[] = ['operator' => 'OR'];
                            $filters[] = ["field" => "tag", "key" => "guid", "relation" => "=", "value" => $v];
                        }
                        if ($filters) {
                            array_shift($filters);
                        }
                        Service::MulOnesignalSendMessage($data['content'], $filters, $data['title'], $data['data'], $data['extra'], [], false, []);
                    }
                } catch (\Exception $e) {
                    Service::log_time('onesignal mul push error,' . json_encode($e->getMessage()));
                }
            }
        }
        Service::log_time('onesignal data : end ------ ');

        unset($data);
        unset($result);
        unset($sqs);
        unset($receipt_handle);
        unset($fields);
        unset($extra);

    }

}
