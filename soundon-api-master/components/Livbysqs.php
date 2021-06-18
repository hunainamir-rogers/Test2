<?php
namespace app\components;

use app\components\redis\QueueRedis;
use app\models\Service;
use Aws\Sqs\Exception\SqsException;
use Exception;
use Yii;
use Aws\Sqs\SqsClient;

class Livbysqs
{
    /**
     * The name of the SQS queue
     *
     * @var string
     */
    private $name;

    /**
     * The url of the SQS queue
     *
     * @var string
     */
    private $url;

    /**
     * The array of credentials used to connect to the AWS API
     *
     * @var array
     */
    private $aws_credentials;

    /**
     * A SqsClient object from the AWS SDK, used to connect to the AWS SQS API
     *
     * @var SqsClient
     */
    private $sqs_client;

    /**
     * Constructs the wrapper using the name of the queue and the aws credentials
     *
     * @param $name
     * @param $aws_credentials
     */
    public function __construct($name)
    {
        try {
            // Setup the connection to the queue
            $region = Yii::$app->params["aws"]["region"];
            $sqsversion = Yii::$app->params["aws"]["version"];
            $awskey = Yii::$app->params["aws"]["key"];
            $secret = Yii::$app->params["aws"]["secret"];
            $this->name = $name;
            $this->aws_credentials = array(
                'region' => $region,
                'version' => $sqsversion,
                'credentials' => array(
                    'key'    => $awskey,
                    'secret' => $secret,
                )
            );
            $this->sqs_client = new SqsClient($this->aws_credentials);
            //$this->url = $this->sqs_client->getQueueUrl(array('QueueName' => $this->name))->get('QueueUrl');
        } catch (Exception $e) {
            //echo 'Error getting the queue url ' . $e->getMessage();
            self::Log('Error getting the queue url ' . $e->getMessage());
        }
    }

    public function create()
    {
        try {
            $this->sqs_client->createQueue(array(
                'QueueName' => $this->name
            ));

            return true;
        } catch (Exception $e) {
            self::Log('Error sending message to queue ' . $e->getMessage());
            //echo 'Error sending message to queue ' . $e->getMessage();
            return false;
        }
    }

    /**
     * Sends a message to SQS using a JSON output from a given Message object
     *
     * @param Message $message  A message object to be sent to the queue
     * @param Int $delaySeconds
     * @return bool  returns true if message is sent successfully, otherwise false
     */
    public function send($message,$delaySeconds = 0)
    {
        $res = QueueRedis::Lpush($this->name, $message);
        if (!$res){
            return false;
        }
//        return QueueRedis::publish($this->name);
        return true;
    }

    /**
     * 获取queue列表
     * @param $prefix
     * @param int $count
     * @param string $nextToken
     * @return bool|mixed
     */
    public function ListQueues($prefix, $count = 1000, $nextToken = "")
    {
        try {
            return $this->sqs_client->listQueues([
                'MaxResults' => $count,
                'NextToken' => $nextToken,
                'QueueNamePrefix' => $prefix,
            ])->toArray();
        } catch (Exception $e) {
            self::Log('Error ListQueues: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Receives a message from the queue and puts it into a Message object
     *
     * @return bool|Message  Message object built from the queue, or false if there is a problem receiving message
     */
    public function receive()
    {
        try {
            return QueueRedis::lpop($this->name);
        } catch (Exception $e) {
            self::Log('Error receiving message from queue ' . $e->getMessage());
            //echo 'Error receiving message from queue ' . $e->getMessage();
            return false;
        }
    }

    /**
     * Deletes a message from the queue
     *
     * @param Message $message
     * @return bool  returns true if successful, false otherwise
     */
    public function delete($receipt_handle)
    {
        try {
            // Delete the message
            $url = $this->sqs_client->getQueueUrl(array('QueueName' => $this->name))->get('QueueUrl');
            $this->sqs_client->deleteMessage(array(
                'QueueUrl' => $url,
                'ReceiptHandle' => $receipt_handle
            ));

            return true;
        } catch (Exception $e) {
            //echo 'Error deleting message from queue ' . $e->getMessage();
            self::Log('Error deleting message from queue  ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Releases a message back to the queue, making it visible again
     *
     * @param Message $message
     * @return bool  returns true if successful, false otherwise
     */
    public function release($receipt_handle)
    {
        try {
            // Set the visibility timeout to 0 to make the message visible in the queue again straight away
            $this->sqs_client->changeMessageVisibility(array(
                'QueueUrl' => $this->url,
                'ReceiptHandle' => $receipt_handle,
                'VisibilityTimeout' => 0
            ));

            return true;
        } catch (Exception $e) {
           // echo 'Error releasing job back to queue ' . $e->getMessage();
            self::Log('Error releasing job back to queue  ' . $e->getMessage());
            return false;
        }
    }



    /**
     * 获取sqs的详细信息
     * https://docs.aws.amazon.com/aws-sdk-php/v3/api/api-sqs-2012-11-05.html#deletequeue
     * @param $attr
     * @return bool
     * array(11) {
    ["QueueArn"]=>
    string(56) "arn:aws:sqs:ap-southeast-1:577224445833:dev-comment-back"
    ["ApproximateNumberOfMessages"]=>
    string(3) "218"
    ["ApproximateNumberOfMessagesNotVisible"]=>
    string(1) "0"
    ["ApproximateNumberOfMessagesDelayed"]=>
    string(1) "0"
    ["CreatedTimestamp"]=>
    string(10) "1539930563"
    ["LastModifiedTimestamp"]=>
    string(10) "1541055901"
    ["VisibilityTimeout"]=>
    string(2) "30"
    ["MaximumMessageSize"]=>
    string(6) "262144"
    ["MessageRetentionPeriod"]=>
    string(6) "345600"
    ["DelaySeconds"]=>
    string(1) "0"
    ["ReceiveMessageWaitTimeSeconds"]=>
    string(1) "0"
    }
     */
    public function GetQueueAttributes($attr = ["All"]){
        try {
            $url = $this->sqs_client->getQueueUrl(['QueueName' => $this->name])->get('QueueUrl');
            $attr = $this->sqs_client->GetQueueAttributes(
                [
                    'QueueUrl' => $url,
                    'AttributeNames' => $attr
                ])->toArray();
            return $attr["Attributes"];
        } catch (SqsException $e) {
            if(php_sapi_name() == "cli"){
                Service::log_time('Error sending message to queue ' . $e->getMessage());
            }
            self::Log('Error GetQueueAttributes message to queue ' . $e->getMessage());
            //Service::log_time('Error GetQueueAttributes message to queue ' . $e->getMessage());
            return false;
        }
    }

    public static function Log($message){
        \Yii::error($message);
    }

    public function DeleteSqsQueue()
    {
        try {
            $url = $this->sqs_client->getQueueUrl(['QueueName' => $this->name])->get('QueueUrl');
            $this->sqs_client->deleteQueue(array(
                'QueueUrl' => $url
            ));
            return true;
        } catch (SqsException $e) {
            if(php_sapi_name() == "cli"){
                Service::log_time('Error sending message to queue ' . $e->getMessage());
            }
            self::Log('Error DeleteSqsQueue message to queue ' . $e->getMessage());
            return false;
        }
    }


    public function Exist(){
        try {
            $this->sqs_client->getQueueUrl(['QueueName' => $this->name]);
            return true;
        } catch (SqsException $e) {
            if(php_sapi_name() == "cli"){
                Service::log_time('Error sending message to queue ('.$this->name.') :' . $e->getAwsErrorCode().','.$e->getAwsErrorMessage());
            }

            self::Log('Error Exist sqs to queue ('.$this->name.') :' . $e->getAwsErrorCode().','.$e->getAwsErrorMessage());
            return false;
        }
    }



}
