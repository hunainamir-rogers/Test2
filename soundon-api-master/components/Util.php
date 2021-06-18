<?php
namespace app\components;

use app\models\Channel;
use app\models\Service;
use Yii;

class Util
{

    /**
     * weibo  短链
     */
    public static function short_url($url)
    {
        $token = '2.00eZ2gPEBqdunDd0131316e5I8faWB';

        $url == trim($url);
        if (!preg_match('/^(http|https):\/\/.*/', $url)) {
            $url = 'http://' . $url;
        }

        $url = urlencode($url);

        $get = "https://api.weibo.com/2/short_url/shorten.json?access_token={$token}&url_long={$url}";

        $result = json_decode(file_get_contents($get), true);

        if (isset($result['urls'][0]['url_short'])) {
            return $result['urls'][0]['url_short'];
        }

        return '';
    }

    /**
     * 取ip
     */
    public static function get_ip()
    {
        $ip = '';
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        }
//        elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
//            $ip = $_SERVER['HTTP_X_REAL_IP'];
//        }
        elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            if(!empty($_SERVER['REMOTE_ADDR'])){
                $ip = $_SERVER['REMOTE_ADDR'];
            }else{
                if(php_sapi_name() == 'cli'){
                    $ip = "cli";
                }
            }
        }

        //HTTP_X_FORWARDED_FOR 可能有多个ip,逗号隔开
        $pos = strpos($ip, ',');
        if ($pos > 0) {
            $ip = substr($ip, 0, $pos);
        }
        return $ip;
    }

    /**
     * 检测输入中是否含有错误字符
     * @param char $string 要检查的字符串名称
     * @return TRUE or FALSE
     */
    public static function is_badword($string)
    {
        $badwords = array("\\", '&', ' ', "'", '"', '/', '*', ',', '<', '>', "\r", "\t", "\n", "#");
        foreach ($badwords as $value) {
            if (strpos($string, $value) !== FALSE) {
                return TRUE;
            }
        }
        return FALSE;
    }

    /**
     * 检查用户名是否符合规定
     *
     * @param STRING $username 要检查的用户名
     * @return    TRUE or FALSE
     */
    public static function is_username($username)
    {
        $strlen = strlen($username);
        if (Util::is_badword($username) || !preg_match("/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]+$/", $username)) {
            return false;
        }
        return true;
    }

    /**
     * 字符长度    中文一个长度，英文数字 半个
     */
    public static function str_len($str)
    {
        $i = 0;
        $n = 0;
        while ($i <= strlen($str)) {
            $temp_str = substr($str, $i, 1);
            $ascnum = ord($temp_str);//得到字符串中第$i位字符的ascii码
            //如果ASCII位高与224，
            if ($ascnum >= 224) {
                $i += 3; //实际Byte计为3
                $n++; //字串长度计1
                //如果ASCII位高与192，
            } elseif ($ascnum >= 192) {
                $i += 2; //实际Byte计为2
                $n++; //字串长度计1
                //如果是大写字母，
            } elseif ($ascnum >= 65 && $ascnum <= 90) {
                $i++; //实际的Byte数仍计1个
                $n++; //但考虑整体美观，大写字母计成一个高位字符
                //其他情况下，包括小写字母和半角标点符号，
            } else {
                $i++; //实际的Byte数计1个
                $n += 0.5; //小写字母和半角标点等与半个高位字符宽...
            }
        }
        return intval($n);
    }


    /**
     * 截取字符
     */
    public static function cut_str($str, $len = 9, $fix = '...')
    {
        if (strlen($str) <= $len) {
            return $str;
        }
        $return_str = '';
        $i = 0;
        $n = 0;
        while (($n < $len) && ($i <= strlen($str))) {
            $temp_str = substr($str, $i, 1);
            $ascnum = ord($temp_str);//得到字符串中第$i位字符的ascii码
            //如果ASCII位高与224，
            if ($ascnum >= 224) {
                $return_str .= substr($str, $i, 3); //根据UTF-8编码规范，将3个连续的字符计为单个字符
                $i += 3; //实际Byte计为3
                $n++; //字串长度计1
                //如果ASCII位高与192，
            } elseif ($ascnum >= 192) {
                $return_str .= substr($str, $i, 2); //根据UTF-8编码规范，将2个连续的字符计为单个字符
                $i += 2; //实际Byte计为2
                $n++; //字串长度计1
                //如果是大写字母，
            } elseif ($ascnum >= 65 && $ascnum <= 90) {
                $return_str .= substr($str, $i, 1);
                $i++; //实际的Byte数仍计1个
                $n++; //但考虑整体美观，大写字母计成一个高位字符
                //其他情况下，包括小写字母和半角标点符号，
            } else {
                $return_str .= substr($str, $i, 1);
                $i++; //实际的Byte数计1个
                $n += 0.5; //小写字母和半角标点等与半个高位字符宽...
            }
        }
        if (strlen($return_str) != strlen($str)) {
            $return_str .= $fix;
        }
        return $return_str;
    }

    /**
     * 发布时间显示
     */
    public static function publish_time($time)
    {
        if (strlen($time) == 13) {
            $time = intval(substr($time, 0, -3));
        }
        $now = time();
        $step = $now - $time;
        if ($step < 10) {
            return '1s';
        }
        if ($step < 60) {
            return $step . 's';
        }
        if ($step < 3600) {
            return intval($step / 60) . 'm';
        }
        if (date('Y-m-d', $now) == date('Y-m-d', $time)) {
            return date('H:i', $time);
        }
        return date('F  d H:i', $time);
    }

    /**
     * 格式化微秒时间
     */
    public static function microdate($format, $microtime)
    {
        $time = intval(substr($microtime, 0, -3));
        return date($format, $time);
    }

    /**
     * 异步调用
     * @param unknown $url
     * @param unknown $post_data
     * @param unknown $cookie
     * @return boolean
     */
    public static function triggerRequest($url, $post_data = array(), $cookie = array())
    {
        $method = "POST";  //可以通过POST或者GET传递一些参数给要触发的脚本
        $url_array = parse_url($url); //获取URL信息，以便平凑HTTP HEADER
        $port = isset($url_array['port']) ? $url_array['port'] : 80;

        $fp = fsockopen($url_array['host'], $port, $errno, $errstr, 30);
        if (!$fp) {
            return FALSE;
        }
        $getPath = $url_array['path'] . "?" . $url_array['query'];
        if (!empty($post_data)) {
            $method = "POST";
        }
        $header = $method . " " . $getPath;
        $header .= " HTTP/1.1\r\n";
        $header .= "Host: " . $url_array['host'] . "\r\n "; //HTTP 1.1 Host域不能省略
        /**//*以下头信息域可以省略
         $header .= "User-Agent: Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.13) Gecko/20080311 Firefox/2.0.0.13 \r\n";
        $header .= "Accept: text/xml,application/xml,application/xhtml+xml,text/html;q=0.9,text/plain;q=0.8,image/png,q=0.5 \r\n";
        $header .= "Accept-Language: en-us,en;q=0.5 ";
        $header .= "Accept-Encoding: gzip,deflate\r\n";
        */

        $header .= "Connection:Close\r\n";
        if (!empty($cookie)) {
            $_cookie = strval(NULL);
            foreach ($cookie as $k => $v) {
                $_cookie .= $k . "=" . $v . "; ";
            }
            $cookie_str = "Cookie: " . base64_encode($_cookie) . " \r\n";//传递Cookie
            $header .= $cookie_str;
        }
        if (!empty($post_data)) {
            $_post = strval(NULL);
            foreach ($post_data as $k => $v) {
                $_post .= $k . "=" . $v . "&";
            }
            $post_str = "Content-Type: application/x-www-form-urlencoded\r\n";//POST数据
            $post_str .= "Content-Length: " . strlen($_post) . " \r\n";//POST数据的长度
            $post_str .= $_post . "\r\n\r\n "; //传递POST数据
            $header .= $post_str;
        }
        fwrite($fp, $header);
        //echo fread($fp, 1024); //我们不关心服务器返回
        fclose($fp);
        return true;
    }

    /**
     * 身份证信息验证，测试版
     **/
    public static function CheckIdCard($vString)
    {
        $len = strlen($vString);
        if ($len != 18) {
            return false;
        }
        if ($vString == '') {
            return false;
        } else {
            // var_dump((int)$vString[3]);exit();
            $s_weight = array(7, 9, 10, 5, 8, 4, 2, 1, 6, 3, 7, 9, 10, 5, 8, 4, 2);
            $s = array();
            for ($i = 0; $i < 17; $i++) {
                $s[$i] = (int)($vString[$i]) * $s_weight[$i];
            }
            $s_sum = array_sum($s);
            $s_flag = $s_sum % 11;
            $v_arr = array('1', '0', 'X', '9', '8', '7', '6', '5', '4', '3', '2');
            if ($v_arr[$s_flag] == (string)$vString[17]) {
                // echo 1;exit();
                return true;
            } else {
                // echo 2;exit();
                return false;
            }
        }
    }

    /**
     * 原型打印
     **/
    public static function dump($a)
    {
        echo '<pre>';
        var_dump($a);
        echo '</pre>';
        exit();
    }

    /**
     * 重处理时间格式
     * @param unknown $time
     * @param string $format
     */
    function time_format($time, $format = "Y-m-d H:i")
    {
        return date_format(date_create($time), $format);
        //return date($format, strtotime($time));
    }

    //设置密码
    static function setPassword($password, $salt = false)
    {
        if ($salt) {
            return md5(md5($password) . $salt);
        }
        return md5($password);
    }

    /**
     * 随机获取
     * @return string
     */
    public static function get_salt()
    {
        $salt = range(0, 9);
        $count = count($salt);
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $salt[mt_rand(0, $count - 1)];
        }
        return $code;
    }

    /**
     * 显示距离当前时间的字符串
     * @param $time int 时间戳
     * @return string
     * @author gaojj@alltosun.com
     */
    function time_past($time)
    {
        $now = time();
        $time_past = $now - strtotime($time);
        // 如果小于1分钟（60s），则显示"刚刚"
        if ($time_past < 60) {
            return '刚刚';
        }
        $time_mapping = array(
            '分钟' => '60',
            '小时' => '24',
            '天' => '7',
            '周' => '4',
            '月' => '12',
            '年' => '100'
        );
        $time_past = floor($time_past / 60);
        foreach ($time_mapping as $k => $v) {
            if ($time_past < $v) return floor($time_past) . $k . '前';
            $time_past = $time_past / $v;
        }
        // 如果小于1小时（60*60s），则显示N分钟前
        // 如果小于24个小时（60*60*24s），则显示N小时前
        // 如果大于24个小时（60*60*24s），则显示N天前
    }

    public static function format_bonus($money, $decimals = 0, $trivia_type = Channel::trivia_type_cash)
    {
        switch ($trivia_type) {
            case Channel::trivia_type_cash:
                return "₱" . number_format($money, $decimals);
                break;
            case Channel::trivia_type_coin:
                return  number_format($money, $decimals);
                break;
            default:
                return "₱" . number_format($money, $decimals);
        }

    }

    public static function AdvanceTimeFormat($timestamp)
    {
        return "@" . date("gA", $timestamp);
    }

    /**
     * 显示动态时间
     */
    public static function time_format_feed($time_str)
    {
        $now = time();
        $now_days = date('z', $now);
        $time = strtotime($time_str);
        $time_days = date('z', $time);

        $date_post = $now_days - $time_days;
        // 当天显示
        if ($date_post == 0) {
            $time_post = $now - $time;
            if ($time_post < 60) {
                return '刚刚';
            } else if ($time_post < 3600) {
                return floor($time_post / 60) . '分钟前';
            } else if ($time_post < 21600) {
                return floor($time_post / 3600) . '小时前';
            }

            return '今天' . date('H:i', $time);
        }
        if ($date_post < 3) {
            // 三天前
            $day_map = array('1' => '昨天', '2' => '前天');
            return $day_map[$date_post] . ' ' . date('H:i', $time);
        }

        return date('m月d日 H:i', $time);
    }

    /**
     * 获取broadcast缩略图
     * @param $cover_image string 原图
     * @return mixed
     */
    public static function getExploreThumbImage($cover_image)
    {
        if(strpos($cover_image, "avatar") !== false){
            return  Service::avatar_small($cover_image);
        }
        $newImgSize = isset(Yii::$app->params["resize"]["explore_size"]) ? trim(Yii::$app->params["resize"]["explore_size"]) : "";
        return str_replace(Yii::$app->params["aws"]["url"], Yii::$app->params["aws"]["url"] . $newImgSize, $cover_image);
    }

    /**
     * video 使用akamai新的cdn地址
     * @param $videoPath
     * @param $ali bool 是否为阿里视频
     * @param $isShrink bool 是否压缩
     * @return mixed
     */
    public static function GetVideoCdn($videoPath, $ali = false, $isShrink = false)
    {
        if($isShrink){
            $shrinkCdnUri = isset(Yii::$app->params["aws"]["video_shrink_url"]) ? Yii::$app->params["aws"]["video_shrink_url"] : "";
            return  $shrinkCdnUri . $videoPath;
        }
        if($ali){
            $cdnUri = isset(Yii::$app->params["ali"]["video_cdn"]) ? Yii::$app->params["ali"]["video_cdn"] : "https://outin-113d4dfc9bfe11ea911000163e00e7a2.oss-ap-southeast-1.aliyuncs.com";
            return  $cdnUri . $videoPath;
        }
        return $videoPath;
        $cdnUri = isset(Yii::$app->params["aws"]["video_cdn"]) ? Yii::$app->params["aws"]["video_cdn"] : "https://video.kumuapi.com/";
        $oldCdnUri = isset(Yii::$app->params["aws"]["url"]) ? Yii::$app->params["aws"]["url"] : "https://cdn.kumuapi.com/";
        return str_replace($oldCdnUri, $cdnUri, $videoPath);
    }

// Converts a number into a short version, eg: 1000 -> 1k
// Based on: http://stackoverflow.com/a/4371114
    public static function number_format_short($n, $precision = 2)
    {
        if ($n <= 999) {
            // 0 - 900
            $n_format = number_format($n, $precision);
            $suffix = '';
        } else if ($n <= 999990) {
            // 0.9k-850k
            $n_format = number_format($n / 1000, $precision);
            $suffix = 'K';
        } else if ($n < 999999990) {
            // 0.9m-850m
            $n_format = number_format($n / 1000000, $precision);
            $suffix = 'M';
        } else if ($n < 999999999990) {
            // 0.9b-850b
            $n_format = number_format($n / 1000000000, $precision);
            $suffix = 'B';
        } else {
            // 0.9t+
            $n_format = number_format($n / 1000000000000, $precision);
            $suffix = 'T';
        }
        // Remove unecessary zeroes after decimal. "1.0" -> "1"; "1.00" -> "1"
        // Intentionally does not affect partials, eg "1.50" -> "1.50"
        if ($precision > 0) {
            $dotzero = '.' . str_repeat('0', $precision);
            $n_format = str_replace($dotzero, '', $n_format);
        }
        return $n_format . $suffix;
    }

    /**
     * 获取一个订单id
     * @return string
     */
    public static function GenerateOrderId()
    {
        return MD5(time() . rand());
    }

    /**
     * im encry sign
     * @param $data
     * @return string
     */
    public static function EncrySign($data)
    {
        ksort($data);
        $str = "";
        foreach ($data as $key => $val) {
            $str .= $key . "=" . $val . "&";
        }
        $str = strtolower($str);
        $str = substr($str, 0, -1);
        return md5(urlencode($str));
    }

    /**
     * api 签名
     * @param $data
     * @return string
     */
    public static function EncrySignApi($data)
    {
        $skey = "s_key=B428575158365BBFE4248CD0D32B51F6";
        ksort($data);
        $str = "";
        foreach ($data as $key => $val) {
            $str .= $key . "=" . $val . "&";
        }
//        $str = strtolower($str);
//        $str = substr($str, 0, -1);
        $str .= $skey;
        return md5($str);
    }
    public static function EncryRequestSignApi($data)
    {
        $skey = "s_key=rZvuiJ7LzhACdX36bljgWL0Aw1Px0idm";
        ksort($data);
        $str = "";
        foreach ($data as $key => $val) {
            $str .= $key . "=" . $val . "&";
        }
//        $str = strtolower($str);
//        $str = substr($str, 0, -1);
        $str .= $skey;
        return md5($str);
    }

    /**
     * 获取13位的微秒时间
     * @return int
     */
    public static function GetMicrotime(){
        return intval(microtime(true) * 1000);
    }

    /**
     * 根据时间戳获取 小时:分钟:秒数
     * @param $timestamp
     * @return string
     */
    public static function His($timestamp)
    {
        try {
            $timestamp = intval($timestamp);
            if ($timestamp <= 0) {
                return "00:00:00";
            }
            $hour = $timestamp / 3600;
            $hour = str_pad(floor($hour), 2, 0, STR_PAD_LEFT);
            $min = ($timestamp % 3600 / 60);
            $min = str_pad(floor($min), 2, 0, STR_PAD_LEFT);
            $sec = (($timestamp % 3600) % 60);
            $sec = str_pad(floor($sec), 2, 0, STR_PAD_LEFT);

            return "$hour:$min:$sec";
        } catch (\Exception $exception) {
            return "00:00:00";
        }

    }

    /**
     * 获取当天时间
     * @param int $time
     * @return false|string
     */
    public static function CurrentDayTimeStr($time = 0){
        if(empty($time)){
            $time = time();
        }
        $time = $time + 8 * 60 * 60;
        $dayTime = date("Y-m-d", $time);
        return $dayTime;
    }

    public static function GetMasterDb(){
        return Yii::$app->master_db;
    }

    public static function isCli()
    {
        if (php_sapi_name() == "cli") {
            return true;
        }
        return false;
    }

    /**
     * 万能验证码, 测试用
     * @param string $verifyCode
     */
    public static function FakeVerifyCode($verifyCode){
        if(empty($verifyCode)){
            return false;
        }
        //验证码效验
        $static_code = Yii::$app->params["static_code"] ?? "888888";//测试用
        if($verifyCode == $static_code){
            return true;
        }
        return false;
    }

    /**
     * 外部头像
     * @param $avatar
     * @return bool
     */
    public static function FakeAvatar($avatar){
        if(strpos($avatar, "https://images.generated.photos/") !== false){
            return $avatar;
        }
        return false;
    }
}

?>
