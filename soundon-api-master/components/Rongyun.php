<?php

namespace app\components;

use app\components\redis\UserRedis;
use app\models\Service;
use app\models\User;
use RongCloud\RongCloud;
use Yii;
use yii\db\Exception;

class Rongyun
{
    static $client;
    static $log = "rongcloud"; //日志类别
    const RcSystemSender = "sysmessage"; //发送消息者

    //自定义消息类型
    const RcEventGift = "GH:Gift";
    const RcEventLiveEvent = "GH:LiveEvent";

    public static function _getInstance()
    {
        if (self::$client instanceof RongCloud) {
            return self::$client;
        }
        $appKey = Yii::$app->params['rongCloud']['appKey'];
        $appSecret = Yii::$app->params['rongCloud']['appSecret'];
        //默认为国内接点
        $apiUrl = isset(Yii::$app->params['rongCloud']['api_url']) ? Yii::$app->params['rongCloud']['api_url'] : "http://api-sg01.ronghub.com/";
        self::$client = new RongCloud($appKey, $appSecret, $apiUrl);
        return self::$client;
    }

    public static function getRongYunToken($data = [])
    {
        if (empty($data['guid'])) {
            return false;
        }
        $post_data = [];
        $post_data['id'] = $data['guid'];
        $post_data['name'] = $data['username'];
        $post_data['portrait'] = Service::getCompleteUrl($data['avatar']);

        $token = self::register($post_data);
        if (!$token) {
            return false;
        }
        //更新用户的融云token
        if (!User::ModifyUserInfo($data['guid'], ["rong_token" => $token])) {
            return false;
        }
        return $token;
    }

    /**
     * 创建用户
     * @param $data
     * $data = [
     * 'id' => 'ujadk90had',
     * 'name' => 'Maritn',//用户名称
     * 'portrait' => 'http://7xogjk.com1.z0.glb.clouddn.com/IuDkFprSQ1493563384017406982' //用户头像
     * ];
     * @return bool
     */
    public static function register($data)
    {
        try {
            $result = self::_getInstance()->getUser()->register($data);
            if ($result['code'] != 200) {
                throw new \Exception(json_encode($result));
            }
            return $result['token'];
        } catch (\Exception $exception) {
            Yii::error("rc register error: " . $exception->getMessage() . " data: " . json_encode($data), self::$log);
            return false;
        }

    }

    /**
     * 更新用户资料
     * @param $data
     * $data = [
     * 'id' => 'ujadk90had',
     * 'name' => 'Maritn',//用户名称
     * 'portrait' => 'http://7xogjk.com1.z0.glb.clouddn.com/IuDkFprSQ1493563384017406982' //用户头像
     * ];
     * @return bool
     */
    public static function update($data)
    {
        try {
            $result = self::_getInstance()->getUser()->update($data);
            if ($result['code'] != 200) {
                throw new \Exception(json_encode($result));
            }
            return true;
        } catch (\Exception $exception) {
            Yii::error("rc update error: " . $exception->getMessage() . " data: " . json_encode($data), self::$log);
            return false;
        }
    }


    /**
     * 发送直播间消息
     * @param $targetId
     * @param $content
     * @param $objectName
     * @param string $senderId
     * @return bool
     */
    public static function ChatroomSend($targetId, $content, $objectName, $senderId = self::RcSystemSender)
    {
        try {
            $data = [
                "senderId" => $senderId,
                "targetId" => [$targetId],
                "objectName" => $objectName,
                "content" => json_encode(["content" => $content]),
            ];
            $result = self::_getInstance()->getMessage()->Chatroom()->send($data);
            if ($result["code"] != 200) {
                throw new \Exception(json_encode($result));
            }
            return true;
        } catch (\Exception $exception) {
            Yii::error("rc ChatroomSend error: " . $exception->getMessage() . " targetId: $targetId, content: $content, objectName: $objectName, senderId: $senderId", self::$log);
            return false;
        }
    }

    /**
     * 发送系统消息 https://www.rongcloud.cn/docs/server_sdk_api/message/system.html#send
     * @param string $targetId 接收方 Id
     * @param string $content 消息内容
     * @param string $objectName 消息类型, 分为两类: 内置消息类型 、自定义消息类型
     * @param string $senderId 发送人 Id
     * @param array $extra
     * @return bool
     */
    public static function SystemSend($targetId, $content, $objectName, $senderId = self::RcSystemSender, $extra = [])
    {
        try {
            $data = [
                "senderId" => $senderId,
                "targetId" => $targetId,
                "objectName" => $objectName,
                "content" => ["content" => $content],
            ];
            //push 内容, 分为两类 内置消息 Push 、自定义消息 Push
            if (isset($extra["pushContent"])) {
                $data["pushContent"] = $extra["pushContent"];
            }
            //iOS 平台为 Push 通知时附加到 payload 中，Android 客户端收到推送消息时对应字段名为 pushData
            if (isset($extra["pushData"])) {
                $data["pushData"] = $extra["pushData"];
            }
            //是否在融云服务器存储, 0: 不存储, 1: 存储, 默认: 1
            if (isset($extra["isPersisted"])) {
                $data["isPersisted"] = $extra["isPersisted"];
            }

            $result = self::_getInstance()->getMessage()->System()->send($data);
            if ($result["code"] != 200) {
                throw new \Exception(json_encode($result));
            }
            return true;
        } catch (\Exception $exception) {
            Yii::error("rc SystemSend error: " . $exception->getMessage() . " targetId: $targetId, content: $content, objectName: $objectName, senderId: $senderId, extra: " . json_encode($extra), self::$log);
            return false;
        }
    }

    /**
     * 设置聊天室的属性 https://docs.rongcloud.cn/v4/views/im/noui/guide/chatroom/manage/key/set/serverapi.html
     * @param $chatroomId
     * @param $userId
     * @param $key
     * @param $value
     * @param array $extra
     * @return bool
     */
    public static function ChatroomEntry($chatroomId, $key, $value, $userId = self::RcSystemSender, $extra = [])
    {
        try {
            $data = [
                "id" => $chatroomId,
                "userId" => $userId,
                "key" => $key,
                "value" => $value,
            ];
            //用户退出聊天室后，是否删除此 Key 值。为 true 时删除此 Key 值，为 false 时用户退出后不删除此 Key，默认为 false
            if (isset($extra["autoDelete"])) {
                $data["autoDelete"] = $extra["autoDelete"];
            }
            //通知消息类型，设置属性后是否发送通知消息，如需要发送则设置为 RC:chrmKVNotiMsg 或其他自定义消息，为空或不传时不向聊天室发送通知消息，默认为不发送。
            if (isset($extra["objectName"])) {
                $data["objectName"] = $extra["objectName"];
            }
            //通知消息内容，JSON 结构，当 objectName 为 RC:chrmKVNotiMsg 时，content 必须包含 type、key、value 属性，详细查看 RC:chrmKVNotiMsg 结构说明。
            //#返回结果
            if (isset($extra["content"])) {
                $data["content"] = $extra["content"];
            }
            $result = self::_getInstance()->getChatroom()->Entry()->set($data);
            if ($result["code"] != 200) {
                throw new \Exception(json_encode($result));
            }
            return true;
        } catch (\Exception $exception) {
            Yii::error("rc ChatroomEntry error: " . $exception->getMessage() . " chatroomId: $chatroomId, userId: $userId, key: $key, value: $value, extra: " . json_encode($extra), self::$log);
            return false;
        }
    }


    /***
     * 获取发送系统消息的对象,id不要改，否则前端im处会有多个系统消息栏目
     */
    public static function getSysMsgSendUserData()
    {
        return [
            'id' => self::RcSystemSender,
            'name' => 'System',
            'username' => 'System',
            'avatar' => '',
            'portrait' => '',
            'extra' => '',
        ];
    }

    /**
     * 刷新用户在融云里面的用户信息
     * @param string $user_id
     * @param array $user_info
     * @return bool
     */
    public static function reflushUserInfo($user_id = '', $user_info = [])
    {
        if ($user_id && !empty($user_info)) {
            $post_data = [];
            $post_data['id'] = $user_id;
            if (isset($user_info['username'])) {
                $post_data['name'] = $user_info['username'];
            }
            if (isset($user_info['avatar'])) {
                $post_data['portraitUri'] = Service::getCompleteUrl($user_info['avatar']);
            }
            return self::update($post_data);
        }
        return false;
    }

    /**发送房间邀请消息
     * @param string $from
     * @param string $to
     * @param string $msg_content
     * @param string $type 1(share)
     *
     */
    public static function sendInviteMsg($from = '', $to = '', $msg_content = '', $type = '1')
    {
        if ($from && $to && $msg_content) {
            $post_data = [];
            $post_data['fromUserId'] = $from;
            $post_data['toUserId'] = $to;
            $post_data['isIncludeSender'] = 1;
            $post_data['objectName'] = 'CA:CalaMsg';

            $my_user_info = UserRedis::getUserInfo($from, ['avatar', 'username']);
            if (empty($my_user_info)) {
                $my_user_info = Service::userinfo($from);
            }
            $extra = [];
            $content = [
                "content" => json_encode($msg_content),
                'senderUserInfo' => $extra,
                "type" => (string)$type,
            ];
            if (!empty($my_user_info)) {
                $content['user'] = [
                    'id' => $from,
                    'name' => $my_user_info['username'] ?? '',
                    'portrait' => isset($my_user_info['avatar']) ? Service::avatar_small($my_user_info['avatar']) : '',
                    'extra' => '',
                ];
            } else {
                return false;
            }
            $post_data['content'] = json_encode($content);
            $post_data['contentAvailable'] = 1;
            if ($from == self::RcSystemSender) {
                return self::_getInstance()->getMessage()->System()->send($post_data);
            } else {
                return self::_getInstance()->getMessage()->Person()->send($post_data);
            }

        }
    }

    /***发送容云cmd消息
     * @param string $from
     * @param string $to
     * @param string $msg_content
     * @param int $data_type 数据类别,10011是游戏匹配的数据，520 是1v1匹配数据
     * @param string $cmd_name
     */
    public static function sendCmdMessage($from = '', $to = '', $msg_content = '', $data_type = 0, $cmd_name = 'CmdMsg')
    {
        if ($from && $to && $msg_content) {
            $post_data = [];
            $post_data['fromUserId'] = $from;
            $post_data['toUserId'] = $to;
            $post_data['objectName'] = 'RC:CmdMsg';
            $data = [
                'body' => $msg_content,
                'op' => $data_type,
            ];
            $content = [
                "data" => json_encode($data),
                'name' => $cmd_name,
            ];
            $post_data['content'] = json_encode($content);
            if ($from == self::RcSystemSender) {
                return self::_getInstance()->getMessage()->System()->send($post_data);
            } else {
                return self::_getInstance()->getMessage()->Person()->send($post_data);
            }
        }
    }

}