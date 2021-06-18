<?php

namespace app\controllers;

use app\components\Queue;
use app\components\redis\UserRedis;
use app\models\Block;
use app\models\Follow;
use app\models\Friends;
use Yii;
use app\components\BaseController;
use app\components\Util;
use app\models\User;
use app\models\Service;
use yii\data\SqlDataProvider;

class BlockController extends BaseController
{
    /**
     * 加入黑名单和取消黑名单
     * @return [type] [description]
     */
    public function actionOperate()
    {
        $postdata = file_get_contents("php://input");
        Yii::info('block/index------' . $postdata, 'interface');
        $postdata = json_decode($postdata, true);
        if (empty($postdata)) {
            return $this->error("Missing a required parameter.");
        }

        $check = Service::authorization();
        if (empty($check)) {
            return $this->error("Missing a required parameter.");
        }
        $user_id = $check['user_id'];
        $token = $check['token'];
        $device_type = $check['device_type'];
        $device_id = $check['device_id'];

        $this->checkuser($user_id, $token, $check['version_code']);

        $action = isset($postdata['action']) ? $postdata['action'] : 1;
        $block_id = isset($postdata['block_id']) ? trim($postdata['block_id']) : '';
        $image_url = isset($postdata['image_url']) ? trim($postdata['image_url']) : '';
        $option = isset($postdata['option']) ? trim($postdata['option']) : 0;

        if (!in_array($action, [1, 0])) {
            return $this->error('Action is incorrect.');
        }
        if (empty($block_id)) {
            return $this->error('Block id required.');
        }
        if (!empty($image_url)) {
            $images = json_decode($image_url);
            if (!is_array($images)) {
                return $this->error('Parameter type error');
            }
        }

        $blocker = UserRedis::getUserInfo($block_id, 'status');

        if ($blocker != 'normal') {
            return $this->error("Block user doesn't exist.");
        }
        if ($block_id == $user_id) {
            return $this->error("You can't block yourself.");
        }

        $masterDB = Util::GetMasterDb();
        if ($action == 1) {
            $block = Block::find()->select(['id'])->where(['user_id' => $user_id, 'block_id' => $block_id])->one($masterDB);
            if(empty($block)){
                $block = new Block();
            }
            if($block->status == Block::EnableStatus){
                return $this->success('Block success');
            }
            $block->user_id = $user_id;
            $block->block_id = $block_id;
            $block->device_type = $device_type;
            $block->device_id = $device_id;
            $block->image_url = $image_url;
            $block->reason = $option;
            $block->ip = Util::get_ip();
            $block->status = Block::EnableStatus;
            if ($block->save()) {
                //修改数据库
                $friend = Friends::find()->where(['user_id' => $user_id, 'friend_id' => $block_id])->one();
                if (!empty($friend)) {
                    $friend->status = '-1';
                    $friend->save();
                }
                $friend_back = Friends::find()->where(['user_id' => $block_id, 'friend_id' => $user_id])->one();
                if (!empty($friend_back)) {
                    $friend_back->status = '-1';
                    $friend_back->save();
                }

                //block加入redis,friend 移除redis
                UserRedis::addBlock($user_id, $block_id);
                //block之后移除好友
                UserRedis::removeFriend($user_id, $block_id);
                UserRedis::removeFriend($block_id, $user_id);


                //block之后移除关注,修改follow数据库
                $follow = Follow::find()->where(['user_id' => $user_id, 'follow_id' => $block_id])->one();
                if (!empty($follow)) {
                    $follow->is_follow = 'false';
                    $follow->save();
                    $follow_back = Follow::find()->where(['user_id' => $block_id, 'follow_id' => $user_id])->one();
                    if (!empty($follow_back)) {
                        $follow_back->is_follow = 'false';
                        $follow_back->save();
                    }

                    $user_model = User::find()->select(['id', 'guid', 'following'])->where(['guid' => $user_id])->one();
                    $friend_model = User::find()->select(['id', 'guid', 'follower'])->where(['guid' => $block_id])->one();
                    if ($user_model->following > 0) {
                        $user_model->following -= 1;
                        $user_model->save();
                    }
                    if ($friend_model->follower > 0) {
                        $friend_model->follower -= 1;
                        $friend_model->save();
                    }
                    UserRedis::setUserInfo($user_id, ['following' => $user_model->following]);
                    UserRedis::setUserInfo($block_id, ['follower' => $friend_model->follower]);

                }

                //block之后移除关注,修改follow redis
                UserRedis::userUnFollow($user_id, $block_id);
                UserRedis::delFollower($block_id, $user_id);
                //friends和follower的共同集合
                UserRedis::delFollowerFriends($block_id, $user_id);

                UserRedis::delFollowingFriends($user_id, $block_id);


                return $this->success(['relation_status' => 2], 'Block success');
            } else {
                return $this->error('Block failed.');
            }
        } else {
            //更新数据非物理删除
            $blockflag = Block::find()->where(['user_id' => $user_id, 'block_id' => $block_id])->one($masterDB);
            if(!empty($blockflag)){
                $blockflag->status = Block::DisableStatus;
                if(!$blockflag->save()){
                    return $this->error("Block fail!");
                }
            }
            //修改redis
            UserRedis::remBlock($user_id, $block_id);
            $block_list2 = UserRedis::blockList($block_id);
            $follow_list = UserRedis::FollowingFriendsList($block_id);
            $relation_status = 0;
            if (in_array($user_id, $follow_list)) {
                $relation_status = 4;//他关注了我
            }
            if (in_array($block_id, $block_list2)) {
                $relation_status = 3;//他把我加入了黑名单
            }
            return $this->success(['relation_status' => $relation_status], 'Success');
        }
    }

    /**
     * 黑名单列表
     */
    public function actionBlocklist()
    {
        $postdata = file_get_contents("php://input");
        Yii::info('block/blocklist------' . $postdata, 'interface');
        $postdata = json_decode($postdata, true);
        $check = Service::authorization();
        if (empty($check)) {
            return $this->error("Missing a required parameter.");
        }
        $user_id = $check['user_id'];
        $token = $check['token'];
        $page = isset($postdata['page']) && $postdata['page'] > 0 ? intval($postdata['page']) : 1;
        $this->checkuser($user_id, $token, $check['version_code']);
        $page_size = isset($postdata['page_size']) && $postdata['page_size'] > 0 ? intval($postdata['page_size']) : 20;

        $since_id = ($page - 1) * $page_size;
        $pos = $since_id + $page_size - 1;

//        $block_all = \app\components\service\User::getBlockUser($user_id, $since_id, $pos);

        $model = Block::find()->select(["id","user_id", "block_id", "status"])
            ->where(["user_id" => $user_id]);
        $count = (clone $model)->count("id");
        $rows = $model->offset($since_id)
            ->limit($page_size)
            ->all();
        $list = [];
        foreach ($rows as $row){
            $list[] = $row["block_id"];
        }
        $data = [];

        if (!empty($list)) {
            foreach ($list as $value) {
                $tmp = Service::userinfo($value, null, $user_id);
                $data[] = $tmp;
            }
//            $count = $block_all['count'];
            $result = array('total_pages' => ceil($count / $page_size), 'total' => (int)$count, 'page_size' => $page_size, 'page' => $page, 'list' => $data);
            return $this->success($result);


        } else {
            $result = array('total_pages' => 0,'total' => 0, 'page_size' => $page_size, 'page' => $page, 'list' => $data);
            return $this->success($result);
        }
    }

}
