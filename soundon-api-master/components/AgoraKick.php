<?php
/**
 * Created by PhpStorm.
 * User: XHY
 * Date: 2018/6/7
 * Time: 10:41
 */

namespace app\components;


use function AlibabaCloud\Client\json;
use app\models\Service;
use Yii;

class AgoraKick
{

    const RESTFULAPI_HOST = "https://api.agora.io";

    const NoEffect = '400';
    const NOPermission = '401';
    const Forbidden  = '403';
    const NotFound = '404';
    const Frequent = '429';
    const ServerError = '500';

    // 错误消息
    const ERROR_MESSAGE  = array(
        self::NoEffect => 'The request no effect!',
        self::NOPermission => 'The appID or Customer Certificate has wrong!',
        self::Forbidden => 'Forbidden Access!',
        self::NotFound => 'Not Found Relative resource!',
        self::Frequent => 'Too many frequencies!',
        self::ServerError => 'The Server of Request Error!'
    );

    /**
     * 获取agora的appid
     * @return mixed
     */
    public static function GetAgoraAppID()
    {
        return $appID = Yii::$app->params['agora']['appID'];
    }

    /**
     * Authorization 为 Basic authorization，生成方法请参考 RESTful API 认证
     * 参考链接:https://docs.agora.io/cn/faq/restful_authentication
     * @return string
     */
    public static function AuthorizationBasic()
    {
        $CustomerID = Yii::$app->params['agora']['CustomerID'];
        $CustomerCertificate = Yii::$app->params['agora']['CustomerCertificate'];
        return base64_encode("$CustomerID:$CustomerCertificate");
    }


    /**
     * agora cUrl公共请求方法
     * @param $url
     * @param $requestParam
     * @param $isPost
     * @return mixed
     */
    public static function cUrl($url, $requestParam, $action = 'GET')
    {
        $bear = self::AuthorizationBasic();
        if ((Yii::$app instanceof \yii\console\Application)) {
            Service::log_time("url: {$url}, requestParam: " . json_encode($requestParam, JSON_UNESCAPED_UNICODE) . ", bear: {$bear}");
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, self::RESTFULAPI_HOST . $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json;charset=utf-8',
            'Authorization: Basic ' . $bear));
        if ($action=='POST') {//post 提交
            curl_setopt($ch, CURLOPT_POST, true);
        }
        if ($action=='PUT') {//put 提交
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "put");
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        if (!empty($requestParam)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestParam));
        }
//        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        $response = curl_exec($ch);
        curl_close($ch);
        return $response;
    }

    /**
     * 获取服务端的踢人规则列表
     * @return array  code: [ 1=>success ,-1=>error ]  ,msg=>'提示消息'   ,data=>'返回数据'
     */
    public static function GetKickRule()
    {
        $appID = self::GetAgoraAppID();
        $url = "/dev/v1/kicking-rule?appid={$appID}";
        $requestParam = [];
        $response = self::cUrl($url, $requestParam,'GET');
        $res = json_decode($response, true);

        if( isset($res['statusCode']) )
        {
            return [
                'code'=>'-1',
                'msg'=> self::ERROR_MESSAGE[$res['statusCode']],
                'data'=>[]
            ] ;
        }


        return ['code'=>'1','msg'=>'success','data'=>$res['rules']];
    }

    /**
     *  创建踢人规则
     * @param $channel_id string 频道名称
     * @param $uid  int 用户 ID
     * @param $ip string 想要封禁的用户 IP 地址
     * @return array  code: [ 1=>success ,-1=>error ]  ,msg=>'提示消息'   ,data=>'返回数据id'
     */
    public static function CreateKickRule($channel_id, $uid, $ip, $time = 60)
    {
        try {
            $appID = self::GetAgoraAppID();
            $url = "/dev/v1/kicking-rule";
            $requestParam = [
                'appid' => $appID,
                'cname' => $channel_id,
                'uid' => $uid,
                'time' => intval($time),
                "privileges" => [
                    "join_channel"
                ]
            ];
            if (!empty($ip)) {
                $requestParam["ip"] = $ip;
            }

            $response = self::cUrl($url, $requestParam, 'POST');
            $res = json_decode($response, true);

            if (isset($res['statusCode'])) {
                return [
                    'code' => '-1',
                    'msg' => self::ERROR_MESSAGE[$res['statusCode']],
                    'data' => []
                ];
            }
            return ['code' => '1', 'msg' => 'success', 'data' => ['id' => $res['id']]];
        } catch (\Exception $exception) {
            return [
                'code' => '-1',
                'msg' => json_encode($exception->getMessage()),
                'data' => []
            ];
        }
    }

    /**
     * @param $rule_id 想要更新的规则的ID
     * @param $time 封人时间，单位是分钟，取值范围为 [1,1440]，默认值为 60
     * @return array code: [ 1=>success ,-1=>error ]  ,msg=>'提示消息'   ,data=>'返回数据'
     */
    public static function UpdateKickRule($rule_id,$time=60)
    {
        $appID = self::GetAgoraAppID();
        $url = "/dev/v1/kicking-rule";
        $requestParam = [
            'appid'=>$appID,
            'id'=>$rule_id,
            'time'=>$time //封人时间
        ];
        $response = self::cUrl($url, $requestParam,'PUT');
        $res = json_decode($response, true);

        if( isset($res['statusCode']) )
        {
            return [
                'code'=>'-1',
                'msg'=> self::ERROR_MESSAGE[$res['statusCode']],
                'data'=>[]
            ] ;
        }

        return ['code'=>'1','msg'=>'success','data'=>$res['result']];
    }

    /**
     * @param $rule_id 想要删除的规则 ID
     * @return array code: [ 1=>success ,-1=>error ]  ,msg=>'提示消息'   ,data=>'返回数据id'
     */
    public static function DelKickRule($rule_id){
        $appID = self::GetAgoraAppID();
        $url = "/dev/v1/kicking-rule";
        $requestParam = [
            'appid'=>$appID,
            'id'=>$rule_id
        ];

        $response = self::cUrl($url, $requestParam,'PUT');
        $res = json_decode($response, true);

        if( isset($res['statusCode']) )
        {
            return [
                'code'=>'-1',
                'msg'=> self::ERROR_MESSAGE[$res['statusCode']],
                'data'=>[]
            ] ;
        }

        return ['code'=>'1','msg'=>'success','data'=>['id'=>$res['id']]];
    }

}