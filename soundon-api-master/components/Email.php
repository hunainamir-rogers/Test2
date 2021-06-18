<?php

namespace app\components;

use Yii;
use app\models\UserChargeFailLog;

/**
 * 发生错误是邮件通知
 */
class Email
{
    // 邮件发送者和接收者

    static private $aws_sender = ['no-reply@calamansi.ph' => 'no-reply@calamansi.ph'];

    /**
     * 邮件发送
     * @param $title
     * @param $msg
     * @param $recipients
     * @param array $cc_emails
     * @param string $template
     * @return bool|\yii\mail\MessageInterface
     */
    public static function send($title, $msg, $recipients, $cc_emails = [], $template = 'VerifyCode')
    {
        try {
            $message = Yii::$app->mailer->compose($template, [
                'title' => $title,
                'message' => $msg,
            ]);
            $message = $message->setFrom(self::$aws_sender)
                ->setSubject($title)
                ->setTo($recipients);
            if ($cc_emails) {
                $message = $message->setCc($cc_emails);
            }
            $message = $message->send();
            \Yii::info('email send error:' . $message, 'my');
            return $message;
        } catch (\Exception $e) {
            \Yii::info('email send error:' . $e->getMessage(), 'my');
        }
        return false;
    }
}