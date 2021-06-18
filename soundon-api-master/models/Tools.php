<?php


namespace app\models;

/**
 * 工具类
 * Class Tools
 * @package app\models
 */
class Tools
{


    /**
     * 判断是否是合法IP
     * @param $ip string
     * @return false|int
     */
    public static function checkIp(string $ip)
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * @param $data
     * @param $page
     * @param $page_size
     * @param $count
     * @return array
     */
    public static function formatPage($data, $page, $page_size, $count): array
    {
        return ['total_pages' => ceil($count / $page_size), 'page_size' => $page_size, 'page' => $page, 'list' => $data];
    }

    /**
     * 获取分页区间
     * @param $page
     * @param $pageSize
     * @param int $limit
     * @return array
     */
    public static function pageLimit($page, $pageSize, $limit = 0): array
    {
        return $pageSize == -1 ? [$page, $pageSize] : [($page - 1) * $pageSize, $pageSize - $limit];
    }


    /**
     * 返回信息模板
     * @param string $key
     * @param array $values
     * @return string|string[]
     */
    public static function getMessage($key = '', $values = [])
    {
        if (empty($key)) return '';
        $messages = [
            'send_email_tpl' => "record video url:</p><p><a href=':val:'>:val:</a></p>"
        ];
        $message = $messages[$key] ?? '';
        if (!empty($message) && !empty($values)) {
            foreach ($values as $k => $v) {
                $message = str_replace(':val' . ($k == 0 ? '' : $k) . ':', $v, $message);
            }
        }
        return $message;
    }

    /**
     * 记录调试信息
     * @param string $info
     */
    public static function debugInfo($info = "")
    {
        \Yii::info($info, 'dev_debug');
    }

    /**
     * 判断Url地址是否是Xss地址
     * @param $url
     * @param string $scene
     * @return bool
     */
    public static function isXssUrl($url, $scene = 'image'): bool
    {
        if (empty($url)) return true;
        $url = strip_tags($url);
        $url = htmlspecialchars($url);
        $urlInfo = parse_url($url);
        switch ($scene) {
            case 'video':
                $cdnUrl = \Yii::$app->params['aws']['video_cdn'] ?? '';
                if (ManageFeedResource::videoType == 'ali') {
                    $cdnUri = isset(\Yii::$app->params["ali"]["video_cdn"]) ? \Yii::$app->params["ali"]["video_cdn"] : "https://outin-113d4dfc9bfe11ea911000163e00e7a2.oss-ap-southeast-1.aliyuncs.com";
                }
                break;
            default:
                $cdnUrl = \Yii::$app->params['aws']['url'] ?? '';
        }
        $cdnUrlInfo = parse_url($cdnUrl);
        // 验证域名主体
        $urlHost1 = $urlInfo['host'] ?? '';
        $urlHost2 = $cdnUrlInfo['host'] ?? '';
        // 验证url方案(https)
        $urlPrefix = $urlInfo['scheme'] ?? "";
        $cdnPrefix = $cdnUrlInfo['scheme'] ?? "";
        return empty($urlHost1) || empty($urlHost2) || $urlHost1 != $urlHost2 || $urlPrefix != $cdnPrefix;
    }

    /**
     * 格式输入手机号码
     * @param $phoneNumber
     * @param $state_code
     * @return array
     */
    public static function formatCellphone($phoneNumber, $state_code): array
    {
        $phoneNumber = str_replace(['+', '（', '(', ')', '-', ' ', '.', ' '], '', trim($phoneNumber));
        $state_code = ltrim($state_code, '+');
        // 将菲律宾09号码换成9
        if ($state_code == '63') {
            if (mb_substr($phoneNumber, 0, 3, 'utf-8') !== '000' && strlen($phoneNumber) == 11) {
                $phoneNumber = ltrim($phoneNumber, '0');
            }
        }
        $phone_str = '+' . $state_code . $phoneNumber;
        return [$phoneNumber, $phone_str, $state_code];
    }

    /**
     * 是否内部号码
     * @param $phoneNumber
     * @param $state_code
     * @return bool
     */
    public static function isInsideCellphone($phoneNumber, $state_code): bool
    {
        $state_code = '+' . ltrim($state_code, '+');
        return mb_substr($phoneNumber, 0, 3, 'utf-8') === '000' && in_array($state_code, ['+63', '+86']);
    }

    /**
     * 验证是否是有效手机号码
     * @param $phoneNumber
     * @param $state_code
     * @return bool
     */
    public static function checkCellphone($phoneNumber, $state_code): bool
    {
        // 内部号码无需验证
        if (self::isInsideCellphone($phoneNumber, $state_code)) {
            return true;
        }
        return preg_match('/^[1-9]\d{3,15}$/', $phoneNumber) == 1;
    }


    /**
     * 判断 用户版本是否低于检查版本
     * @param string $userVersion
     * @param string $checkVersion
     * @return false
     */
    public static function isNewVersion($userVersion = '', $checkVersion = ''): bool
    {
        return true;
    }


}