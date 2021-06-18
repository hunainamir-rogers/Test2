<?php
/**
 * Created by PhpStorm.
 * User: mac
 * Date: 2019/6/4
 * Time: 11:34
 */

namespace app\components\service;


use app\components\define\SystemConstant;
use app\components\redis\UserRedis;
use app\components\service\User as ServiceuUser;
use app\components\Util;
use app\components\Words;
use app\models\Block;
use app\models\Follow;
use app\models\Friends;
use app\models\Service;
use app\models\User as UserModel;
use Yii;
use yii\db\Exception;

class User
{

    /**
     * user_id 关注/取消关注 influencer_id
     *
     * @param string $user_id
     * @param string $influencer_id
     * @param string $action
     * @param array $header
     * @return array
     */
    public static function userFollowOrCancel($user_id = '', $influencer_id = '', $action = '1', $header = [])
    {
        try {
            $transaction = null;
            if (empty($header)) {
                $header = Service::authorization();
            }
            $device_type = $header['device_type'] ?? '';
            $device_id = $header['device_id'] ?? '';
            $masterDb = Util::GetMasterDb();
            if (!in_array($action, ['1', '0'])) {
                throw new Exception("Action is incorrect.");
            }
            if (empty($influencer_id)) {
                throw new Exception("Follow id required.");
            }
            if ($user_id == $influencer_id) {
                throw new Exception("You can't follow yourself.");
            }
            if (!ServiceuUser::userIsExists($influencer_id)) {
                throw new Exception("The user doesn't exist.");
            }
            //判断是不是相互关注
            $influencer_follow = ServiceuUser::getFollower($user_id);
            //判断好友人数是否达到上限
            $frinedsCount = UserRedis::countFriends($influencer_id);
            $frinedsCountBack = UserRedis::countFriends($user_id);
            if (in_array($influencer_id, $influencer_follow['list'])) {
                if ($frinedsCount >= SystemConstant::FRIENDS_MAX) {
                    throw new Exception('The user has reached the max number of friends.');
                }
                if ($frinedsCountBack >= SystemConstant::FRIENDS_MAX) {
                    throw new Exception(' You have reached your max number of friends');
                }
            }
            //判断是否加入了黑名单
            $block_list = ServiceuUser::getBlockUser($user_id);
            if (in_array($influencer_id, $block_list['list'])) {
                throw new \Exception('You have blocked this user.');
            }

            $block_back_list = ServiceuUser::getBlockUser($influencer_id);
            if (in_array($user_id, $block_back_list['list'])) {
                throw new \Exception('This user has blocked you.');
            }
            $followflag = Follow::find()->select(['id', 'is_follow', 'user_id', 'follow_id'])->where(['user_id' => $user_id, 'follow_id' => $influencer_id])->one($masterDb);
            $user_model = UserModel::find()->select(['id', 'guid', 'following'])->where(['guid' => $user_id])->one($masterDb);
            $friend_model = UserModel::find()->select(['id', 'guid', 'follower'])->where(['guid' => $influencer_id])->one($masterDb);
            if (!empty($followflag) && $action == '1') {
                if ($followflag->is_follow == 'false') {
                    $followflag->is_follow = 'true';
                    $followflag->save();
                } else {//已经关注过
                    $influencer_follow_back = ServiceuUser::getFollower($influencer_id);
                    if (in_array($user_id, $influencer_follow_back['list'])) {
                        $user_info3 = Service::userinfo($influencer_id);
                        $user_info3['relation_status'] = 5;
                        return ['code' => 200, 'data' => $user_info3, 'msg' => 'follow success'];
                    }
                }
            }

            $transaction = Yii::$app->db->beginTransaction();
            $username = ServiceuUser::getUserInfo($user_id, 'username');
            $userinfo1 = [];

            //关注某个直播
            if ($action == '1') {
                if (empty($followflag)) {
                    $follow = new Follow();
                    $follow->user_id = $user_id;
                    $follow->follow_id = $influencer_id;
                    $follow->device_type = $device_type;
                    $follow->device_id = $device_id;
                    if (!$follow->save()) {
                        Yii::info('follow/index:[follow] save to mysql faild----' . $user_id . '----' . $influencer_id, 'interface');
                        throw new \Exception("Follow failed.");
                    }
                }

                if ($user_model) {
                    $user_model->following += 1;
                    if (!$user_model->save()) {
                        throw new Exception('error.v1');
                    }
                }
                if ($friend_model) {
                    $friend_model->follower += 1;
                    if (!$friend_model->save()) {
                        throw new Exception('error.v2');
                    }
                }
                UserRedis::addFollowingFriends($user_id, $influencer_id, '', false);
                UserRedis::addFollowerFriends($influencer_id, $user_id, '', false);
                //判断是不是相互关注
                //对方也关注了你,则相互成为好友
                if (in_array($influencer_id, $influencer_follow['list'])) {
                    $unacceptlist = UserRedis::getFriendsUnacceptList($user_id);
                    if (in_array($influencer_id, $unacceptlist)) {
                        UserRedis::remFriendsUnacceptList($user_id, $influencer_id);
                    }
                    $model_back = Friends::find()->where(['user_id' => $user_id, 'friend_id' => $influencer_id])->one($masterDb);
                    //数据库里存在记录
                    if (!empty($model_back)) {
                        $model_back->status = '1';
                        if (!$model_back->save()) {
                            throw new Exception('error.v6');
                        }
                    } else {
                        //数据库里没有记录
                        $model_back = new Friends();
                        $ip = Util::get_ip();
                        $model_back->user_id = $user_id;
                        $model_back->friend_id = $influencer_id;
                        $model_back->ip = $ip;
                        $model_back->device_id = $device_id;
                        $model_back->device_type = $device_type;
                        $model_back->status = '1';
                        if (!$model_back->save()) {
                            throw new Exception('error.v4');
                        }
                    }
                    $model = Friends::find()->where(['user_id' => $influencer_id, 'friend_id' => $user_id])->one($masterDb);
                    //数据库里存在记录
                    if (!empty($model)) {
                        $model->status = '1';
                        if (!$model->save()) {
                            throw new Exception('error.v5');
                        }
                    } else {
                        //数据库里没有记录
                        $model = new Friends();
                        $ip = Util::get_ip();
                        $model->user_id = $influencer_id;
                        $model->friend_id = $user_id;
                        $model->ip = $ip;
                        $model->device_id = $device_id;
                        $model->device_type = $device_type;
                        $model->status = '1';
                        if (!$model->save()) {
                            throw new Exception('error');
                        }
                    }

                    UserRedis::userUnFollow($user_id, $influencer_id);
                    //关注了主播的用户集合
                    UserRedis::delFollower($influencer_id, $user_id);
                    UserRedis::addFriend($influencer_id, $user_id);
                    //将对方加入到我的好友
                    UserRedis::addFriend($user_id, $influencer_id);


                    $userinfo1 = Service::userinfo($influencer_id, '', $user_id, true);

                    if (isset($userinfo1['user_follow_notification']) && $userinfo1['user_follow_notification'] == '1') {

                        $message_content = $username . " is now friends with you";

                        $msg_id = Service::create_guid();
                        $create = time();
                        Service::OnesignalSendMessage($message_content, array(array("field" => "tag", "key" => "guid", "relation" => "=", "value" => $influencer_id)), '', array('type' => '204', 't_uid' => $influencer_id, 'f_uid' => $user_id, 'msg_id' => $msg_id, 'create' => $create, 'ob_id' => $user_id, 'id' => $user_id, 'msg_from' => '3'), array("large_icon" => '', "ios_attachments" => array("large_icon" => '')), true);
                    }

                } else {
                    $userinfo1 = Service::userinfo($influencer_id, '', $user_id);

                    if (isset($userinfo1['user_follow_notification']) && $userinfo1['user_follow_notification'] == '1') {

                        $message_content = $username . " is now following you";

                        $msg_id = Service::create_guid();
                        $create = time();
                        Service::OnesignalSendMessage($message_content, array(array("field" => "tag", "key" => "guid", "relation" => "=", "value" => $influencer_id)), '', array('type' => '205', 't_uid' => $influencer_id, 'f_uid' => $user_id, 'msg_id' => $msg_id, 'create' => $create, 'ob_id' => $user_id, 'id' => $user_id, 'msg_from' => '3'), array("large_icon" => '', "ios_attachments" => array("large_icon" => '')), true);
                    }

                }
                $message_content = $message_content ?? $username . " is now following you";
                Follow::FollowSystemMessage($user_id, $influencer_id, $message_content);
                $msg = 'Follow success';
            } else {
                if (empty($followflag)) {
                    $followflag = new Follow();
                    $followflag->user_id = $user_id;
                    $followflag->follow_id = $influencer_id;
                    $followflag->device_type = $device_type;
                    $followflag->device_id = $device_id;
                    $followflag->is_follow = 'false';
                    if (!$followflag->save()) {
                        throw new Exception('error');
                    }
                }
                if ($user_model && $user_model->following > 0) {
                    $user_model->following -= 1;
                    if (!$user_model->save()) {
                        throw new Exception('error');
                    }
                }
                if ($friend_model && $friend_model->follower > 0) {
                    $friend_model->follower -= 1;
                    if (!$friend_model->save()) {
                        throw new Exception('error');
                    }
                }

                $followflag->is_follow = 'false';
                if (!$followflag->save()) {
                    throw new Exception('error');
                }

                //修改数据库 移除好友
                $friend = Friends::find()->where(['user_id' => $user_id, 'friend_id' => $influencer_id])->one($masterDb);
                if (!empty($friend)) {
                    $friend->status = '-1';
                    if (!$friend->save()) {
                        throw new Exception('error.v3');
                    }
                }
                $friend_back = Friends::find()->where(['user_id' => $influencer_id, 'friend_id' => $user_id])->one($masterDb);
                if (!empty($friend_back)) {
                    $friend_back->status = '-1';
                    if (!$friend_back->save()) {
                        throw new Exception('error.v4');
                    }
                }
                UserRedis::delFollowingFriends($user_id, $influencer_id);
                UserRedis::delFollowerFriends($influencer_id, $user_id);
                $msg = 'Unfollow success';
            }
            $transaction->commit();
            //返回关注的状态
            if ($user_model) {
                UserRedis::setUserInfo($user_id, ['following' => $user_model->following]);
            }
            if ($friend_model) {
                UserRedis::setUserInfo($influencer_id, ['follower' => $friend_model->follower]);
            }

            UserRedis::delFriend($user_id);
            UserRedis::delFriend($influencer_id);
            $userinfo1 = Service::userinfo($influencer_id, '', $user_id);
            return ['code' => 200, 'data' => $userinfo1, 'msg' => $msg];

        } catch (\Exception $e) {
            if ($transaction !== null) {
                $transaction->rollBack();
            }
            return ['code' => 500, 'data' => [], 'msg' => $e->getMessage()];
        }
        return ['code' => 500, 'data' => [], 'msg' => 'Failed'];
    }

    /**
     * 判断用户是不是存在
     * @param  [type] $user_guid [用户唯一id]
     * @return bool [type]            [description]
     */
    public static function userIsExists($user_guid)
    {
        if (empty($user_guid)) {
            return false;
        }
        //先看redis
        $is_have = UserRedis::UserExists($user_guid);
        if ($is_have) {
            return true;
        }
        //查询数据库
        return (bool)Service::reloadUser($user_guid);

    }

    /**
     * 关注我的人的列表
     * @param $user_id
     * @param $since_id
     * @param $pos
     * @return array
     */
    public static function getFollower($user_id, $since_id = 0, $pos = -1)
    {
        if (empty($user_id)) {
            return ['list' => [], 'count' => 0];
        } else {
            //游客没有关注，被关注数据
            if (UserRedis::getUserInfo($user_id, 'type') == UserModel::GuestUserType) {
                return ['list' => [], 'count' => 0];
            }
        }
        $count = 0;
        $list = UserRedis::FollowerFriendsList($user_id, $since_id, $pos);
        $count = UserRedis::countFollowerFriendsList($user_id);
        if (empty($list) && $count <= 0) {
            $list = [];
            //如果redis里没有数据
            $masterDb = null;//Util::GetMasterDb();
            $list_model = Follow::find()->distinct()->limit(7000)->select(['user_id', 'follow_id', 'updated_at'])->where(['follow_id' => $user_id, 'is_follow' => true])->asArray()->all($masterDb);
            if (!empty($list_model)) {
                foreach ($list_model as $item) {
                    $time = strtotime($item['updated_at']);
                    UserRedis::addFollowerFriends($user_id, $item['user_id'], $time, false);
                    $list [] = $item['user_id'];
                }
                //设置过期时间
                UserRedis::expireFollowerFriends($user_id);
                $count = count($list);
            }
        }
        return ['list' => $list, 'count' => $count];
    }

    /**
     * 我关注的用户
     * @param $user_id
     * @param int $since_id
     * @param int $pos
     * @return array
     */
    public static function getFollowing($user_id, $since_id = 0, $pos = -1)
    {
        if (empty($user_id)) {
            return ['list' => [], 'count' => 0];
        } else {
            //游客没有关注，被关注数据
            if (UserRedis::getUserInfo($user_id, 'type') == UserModel::GuestUserType) {
                return ['list' => [], 'count' => 0];
            }
        }
        $count = 0;
        $list = UserRedis::FollowingFriendsList($user_id, $since_id, $pos);
        $count = UserRedis::countFollowingFriendsList($user_id);
        if (empty($list) && $count <= 0) {
            $list = [];
            //如果redis里没有数据
            $masterDb = null;//Util::GetMasterDb();
            $list_model = Follow::find()->limit(7000)->distinct()->select(['user_id', 'follow_id', 'is_follow', 'updated_at'])->where(['user_id' => $user_id, 'is_follow' => true])->asArray()->all($masterDb);
            if (!empty($list_model)) {
                foreach ($list_model as $item) {
                    $time = strtotime($item['updated_at']);
                    UserRedis::addFollowingFriends($user_id, $item['follow_id'], $time, false);
                    $list [] = $item['follow_id'];
                }
                $count = count($list);
                //设置过期时间
                UserRedis::expireFollowingFriends($user_id);
            }
        }
        return ['list' => $list, 'count' => $count];
    }

    /**
     * 加入黑名单的用户
     * @param $user_id
     * @param int $start
     * @param int $end
     * @return array
     */
    public static function getBlockUser($user_id, $start = 0, $end = -1)
    {
        if (empty($user_id)) {
            return ['list' => [], 'count' => 0];
        } else {
            //游客没有关注，被关注数据
            if (UserRedis::getUserInfo($user_id, 'type') == UserModel::GuestUserType) {
                return ['list' => [], 'count' => 0];
            }
        }
        $list = UserRedis::blockList($user_id, $start, $end);
        $count = UserRedis::countblockList($user_id);
        Service::log_time("user_id: $user_id, count: $count block list: " . json_encode($list));
        if (empty($list) && $count <= 0) {
            $list = [];
            //如果redis里没有数据
            $masterDb = Util::GetMasterDb();
            $list_model = Block::find()->distinct()->select(['user_id', 'block_id'])->where(['user_id' => $user_id, "status" => Block::EnableStatus])->asArray()->all($masterDb);
            if (!empty($list_model)) {
                foreach ($list_model as $item) {
                    UserRedis::addBlock($user_id, $item['block_id']);
                    $list[] = $item['block_id'];
                }
                //设置过期时间
                UserRedis::expireBlock($user_id);
                $count = count($list);
            }
        }
        return ['list' => $list, 'count' => $count];

    }

    /**
     * [getUserInfo description]
     * 获取用户信息，redis没有重新从mysql查询
     * @param  [type] $user_guid [description]
     * @param string $key [description]
     * @return array|mixed [type]            [description]
     */
    public static function getUserInfo($user_guid, $key = '')
    {
        $user = [];
        if ($user_guid) {
            $user = UserRedis::getUserInfo($user_guid);
            if (empty($user)) {
                $user = Service::reloadUser($user_guid);
            }
            if (!empty($user)) {
                if (!empty($key)) {
                    $user = UserRedis::getUserInfo($user_guid, $key);
                }
            }
        }
        return $user;
    }

    /**
     * 获取好友
     * @param $user_id
     * @param int $start
     * @param int $end
     * @return array
     */
    public static function getFriends($user_id, $start = 0, $end = -1)
    {
        if (empty($user_id)) {
            return ['list' => [], 'count' => 0];
        } else {
            //游客没有关注，被关注数据
            if (UserRedis::getUserInfo($user_id, 'type') == UserModel::GuestUserType) {
                return ['list' => [], 'count' => 0];
            }
        }
        $list = UserRedis::friendsList($user_id, $start, $end);
        $count = 0;
        $count = UserRedis::countFriends($user_id);
        if (empty($list) && $count <= 0) {
            $list = [];
            //如果redis里没有数据
            $masterDb = Util::GetMasterDb();
            $list_model = Friends::find()->distinct()->select(['user_id', 'friend_id'])->where(['user_id' => $user_id])->asArray()->all($masterDb);
            if (!empty($list_model)) {
                foreach ($list_model as $item) {
                    UserRedis::addFriends($user_id, $item['friend_id']);
                    $list [] = $item['friend_id'];
                }
                //设置过期时间
                UserRedis::expireFriends($user_id);
                $count = count($list);
            }
        }
        return ['list' => $list, 'count' => $count];

    }

    /**用户$user_id2 在没在$user_id的following 列表里面
     * @param string $user_id
     * @param string $user_id2
     * @return bool
     */
    public static function isInFollowing($user_id = '', $user_id2 = '')
    {
        if (empty($user_id) || empty($user_id2)) {
            return false;
        }
        $is_in = false;
        $count = 0;
        $count = UserRedis::countFollowingFriendsList($user_id);
        if ($count <= 0) {
            $list = [];
            //如果redis里没有数据
            $masterDb = null;//Util::GetMasterDb();
            $list_model = Follow::find()->limit(7000)->distinct()->select(['user_id', 'follow_id', 'is_follow', 'updated_at'])->where(['user_id' => $user_id, 'is_follow' => true])->asArray()->all($masterDb);
            if (!empty($list_model)) {
                foreach ($list_model as $item) {
                    $time = strtotime($item['updated_at']);
                    UserRedis::addFollowingFriends($user_id, $item['follow_id'], $time, false);
                    $list [] = $item['follow_id'];
                    $is_in = ($item['follow_id'] == $user_id2) ? true : false;
                    if ($is_in) {
                        break;
                    }
                }
                //设置过期时间
                UserRedis::expireFollowingFriends($user_id);
            }
        } else {
            if (mt_rand(0, 6) == 1) {
                UserRedis::expireFollowingFriends($user_id);
            }
            return (bool)UserRedis::isInFollowingFriendsList($user_id, $user_id2);
        }
        return $is_in;
    }
}