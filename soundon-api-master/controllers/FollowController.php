<?php

namespace app\controllers;

use app\components\Queue;
use app\components\redis\FeedRedis;
use app\components\redis\UserRedis;
use app\components\Words;
use app\models\Friends;
use app\models\User as UserModel;
use Yii;
use app\components\BaseController;
use app\components\Util;
use app\models\User;
use app\models\Follow;
use app\models\Service;
use yii\data\SqlDataProvider;
use yii\db\Exception;
use app\components\service\User as ServiceuUser;

class FollowController extends BaseController
{
    /**
     * 关注和取消关注 某个主播
     * @return [type] [description]
     */
    public function actionOperation()
    {
        $postdata = file_get_contents("php://input");
        Yii::info('follow/operation------' . $postdata, 'interface');
        $postdata = json_decode($postdata, true);
        if (empty($postdata)) {
            return $this->error("Post Data is empty.");
        }
        $check = Service::authorization();
        if (empty($check)) {
            return $this->error("Missing a required parameter.");
        }

        $user_id = $check['user_id'];
        $token = $check['token'];
        $device_type = $check['device_type'];
        $device_id = $check['device_id'];
        $action = isset($postdata['action']) ? trim($postdata['action']) : '1';
        $influencer_id = isset($postdata['influencer_id']) ? trim($postdata['influencer_id']) : '';
        $isSuggest = isset($postdata['isSuggest']) ? trim($postdata['isSuggest']) : '0';
        $this->checkuser($user_id, $token);
        $result = ServiceuUser::userFollowOrCancel($user_id, $influencer_id, $action, $check);
        if ($result['code'] != 200) {
            return $this->error($result['msg']);
        }
        return $this->success($result['data']);
    }

    /**
     * 关注列表 可搜索
     */
    public function actionListSearch()
    {
        $postdata = file_get_contents("php://input");
        Yii::info('follow/list------' . $postdata, 'interface');
        $postdata = json_decode($postdata, true);
        if (empty($postdata)) {
            return $this->error("Missing a required parameter.");
        }
        $query_id = isset($postdata['query_id']) ? trim($postdata['query_id']) : '';
        $keyword = isset($postdata['keyword']) ? trim($postdata['keyword']) : '';
        $action = isset($postdata['action']) ? trim($postdata['action']) : '0';//1我关注的人 0 关注我的人
        $page = isset($postdata['page']) && $postdata['page'] > 0 ? intval($postdata['page']) : 1;
        $page_size = isset($postdata['page_size']) && $postdata['page_size'] > 0 ? intval($postdata['page_size']) : 20;


        $check = Service::authorization();
        if (empty($check)) {
            return $this->error("Missing a required parameter.");
        }

        $user_id = $check['user_id'];
        $token = $check['token'];
        $since_id = ($page - 1) * $page_size;
        $pos = $since_id + $page_size - 1;
        //如果没有查询的用户则看自己的
        if (empty($query_id)) {
            $query_id = $user_id;
        }

//        if ($id === '') {
//            return $this->actionList();
//        }

//        $user = User::find()->select(['guid', 'username', 'avatar'])->where(['id' => $id])->asArray()->one();
//        if (empty($user)) {
//            return $this->success([]);
//        }

        if ($action == 1) {
            //我关注的人
            $model = Follow::find()
                ->alias("f")
                ->select(['f.follow_id'])
                ->where(['f.user_id' => $user_id, 'f.is_follow' => true])

                ->leftJoin(User::tableName(). " as u", "f.follow_id = u.guid")
                ->offset($since_id)
                ->limit($page_size)
                ->asArray();
            if(!empty($keyword)){
                $model->andWhere(["like", "u.username", $keyword]);
            }
            $count = (clone $model) -> count();
            $data = $model ->all();
            if(empty($data)){
                $result = array('total_pages' => 1, 'total' => $count, 'page_size' => $page_size, 'page' => $page, 'list' => []);
                return  $this->success($result);
            }
            $user_ids = [];
            foreach ($data as $datum){
                $user_ids[] = $datum["follow_id"];
            }
            $users_info = UserRedis::getUserInfoBatch($user_ids, ['avatar', 'guid', 'username', 'gender', 'nickname', 'type', 'title', 'frame_img', 'master_switch', 'cellphone']);
            $re_data = array();
            foreach ($users_info as $key => $value) {
                $info_data = Service::Simpleuserinfo($value);
                if (empty($info_data)) {
                    continue;
                }
                if (empty($action)) {
                    $info_data['is_follow'] = true;
                }
                $info_data['relation_status'] = 0;//不存在关系
                $info_data['relation_status'] = Service::UserRelation($value['guid'], $user_id);
                $re_data [] = $info_data;
            }

            $result = array('total_pages' => ceil($count / $page_size), 'total' => $count, 'page_size' => $page_size, 'page' => $page, 'list' => $re_data);
            return  $this->success($result);
        } else {
            //我关注的人
            $model = Follow::find()
                ->alias("f")
                ->select(['f.user_id'])
                ->where(['f.follow_id' => $user_id, 'f.is_follow' => true])
//                ->andWhere(["like", "u.username", $keyword])
                ->leftJoin(User::tableName(). " as u", "f.user_id = u.guid")
                ->offset($since_id)
                ->limit($page_size)
                ->asArray();

            if(!empty($keyword)){
                $model->andWhere(["like", "u.username", $keyword]);
            }
            $count = (clone $model) -> count();
            $data = $model ->all();
            if(empty($data)){
                $result = array('total_pages' => 1, 'total' => $count, 'page_size' => $page_size, 'page' => $page, 'list' => []);
                return  $this->success($result);
            }
            $user_ids = [];
            foreach ($data as $datum){
                $user_ids[] = $datum["user_id"];
            }
            $users_info = UserRedis::getUserInfoBatch($user_ids, ['avatar', 'guid', 'username', 'gender', 'nickname', 'type', 'title', 'frame_img', 'master_switch', 'cellphone']);
            $re_data = array();
            foreach ($users_info as $key => $value) {
                $info_data = Service::Simpleuserinfo($value);
                if (empty($info_data)) {
                    continue;
                }
                if (empty($action)) {
                    $info_data['is_follow'] = true;
                }
                $info_data['relation_status'] = 0;//不存在关系
                $info_data['relation_status'] = Service::UserRelation($value['guid'], $user_id);
                $re_data [] = $info_data;
            }

            $result = array('total_pages' => ceil($count / $page_size), 'total' => $count, 'page_size' => $page_size, 'page' => $page, 'list' => $re_data);
            return  $this->success($result);
        }

    }


    /**
     * 关注列表
     */
    public function actionList()
    {
        $postdata = file_get_contents("php://input");
        Yii::info('follow/list------' . $postdata, 'interface');
        $postdata = json_decode($postdata, true);
        if (empty($postdata)) {
            return $this->error("Missing a required parameter.");
        }
        $keyword = isset($postdata['keyword']) ? trim($postdata['keyword']) : '';
        $query_id = isset($postdata['query_id']) ? trim($postdata['query_id']) : '';
        $action = isset($postdata['action']) ? trim($postdata['action']) : '0';//1我关注的人 0 关注我的人
        $page = isset($postdata['page']) && $postdata['page'] > 0 ? intval($postdata['page']) : 1;
        $page_size = isset($postdata['page_size']) && $postdata['page_size'] > 0 ? intval($postdata['page_size']) : 50;

        $check = Service::authorization();
        if (empty($check)) {
            return $this->error("Missing a required parameter.");
        }

        $user_id = $check['user_id'];
        $token = $check['token'];
//        $this->checkuser($user_id, $token);
        $since_id = ($page - 1) * $page_size;
        $pos = $since_id + $page_size - 1;
        //如果没有查询的用户则看自己的
        if (empty($query_id)) {
            $query_id = $user_id;
        }
        //查询用户信息
        $userinfo = [];
        $userinfo = Service::userinfo($query_id);

        $count = $number = $follow_data_count = 0;
        //我关注的人
        if ($action == 1) {
            $follow_data = ServiceuUser::getFollowing($query_id, $since_id, $pos);

            $count = $userinfo['following_number'] ?? $follow_data['count'];
        } else {
            $follow_data = ServiceuUser::getFollower($query_id, $since_id, $pos);
            $count = $userinfo['follower_number'] ?? $follow_data['count'];
        }

        //没有用户
        if (empty($follow_data['list'])) {
            $result = array('total_pages' => ceil($count / $page_size), 'total' => $count, 'page_size' => $page_size, 'page' => $page, 'list' => [], 'number' => $count);
            return $this->success($result);
        }
        $users_info = UserRedis::getUserInfoBatch($follow_data['list'], ['avatar', 'guid', 'username', 'gender', 'nickname', 'type', 'title', 'frame_img', 'master_switch', 'cellphone']);
        $re_data = array();

        foreach ($users_info as $key => $value) {
            $info_data = Service::Simpleuserinfo($value);
            if (empty($info_data)) {
                continue;
            }
            if (empty($action)) {
                $info_data['is_follow'] = true;
            }
            $info_data['relation_status'] = 0;//不存在关系
            $info_data['relation_status'] = Service::UserRelation($value['guid'], $user_id);
            $re_data [] = $info_data;
        }
        $result = array('total_pages' => ceil($count / $page_size), 'total' => $count, 'page_size' => $page_size, 'page' => $page, 'list' => $re_data, 'number' => $count);
        return $this->success($result);
    }

    private function EncrySign($data)
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
     * @api {post} /follow/friend-list  用户好友列表
     * @apiVersion 0.0.0
     * @apiName friend-list 数据
     * @apiGroup Follow
     * @apiParam {string} user_id 用户id
     * @apiParam {int} page_size 默认18
     * @apiParam {string} since_id 分页数据
     *
     * @apiSuccess {Array} data
     */
    public function actionFriendList()
    {
        $postdata = file_get_contents("php://input");
        if (empty($postdata) || !is_string($postdata)) {
            return $this->error("Missing a required parameter.v1");
        } else {
            $postdata = json_decode($postdata, true);
        }
        $check = Service::authorization();
        if (empty($check)) {
            return $this->error("Missing a required parameter.");
        }
        $user_id = $check['user_id'];
        $token = $check['token'];
        $query_id = $postdata['query_id'] ?? '';
        $since_id = $postdata['since_id'] ?? '';
        $page_size = $postdata['page_size'] ?? 20;
        $page_size = $page_size < 1 ? 20 : $page_size;
        if (empty($query_id)) {
            $query_id = $user_id;
        }
        $data = [];
        if (empty($since_id)) {
            $data = \app\components\service\User::getFriends($query_id, 0, $page_size);
            $data = $data['list'] ?? [];
        } else {
            $data = UserRedis::friendsListByScore($query_id, $since_id, 100000000, $page_size);
        }
        $re_data = array();
        $since_id = '';
        if ($data) {

            $users_info = UserRedis::getUserInfoBatch($data, ['avatar', 'guid', 'username', 'gender', 'nickname', 'type', 'title']);
            foreach ($users_info as $key => $value) {
                if (!isset($value['guid'])) {
                    continue;
                }
                $info_data = Service::Simpleuserinfo($value);
                if (empty($action)) {
                    $info_data['is_follow'] = true;
                }
                $info_data['relation_status'] = 1;//不存在关系
                $re_data [] = $info_data;
                $since_id = $value['guid'];
            }
            if (count($data) >= $page_size) {
                $since_id = UserRedis::friendsScore($query_id, $since_id);
            } else {
                $since_id = '';
            }
        }
        return $this->success(['list' => $re_data, 'page_size' => $page_size, 'since_id' => $since_id]);
    }

    /**
     * 关注列表
     */
    public function actionRecommendList()
    {
        $postdata = file_get_contents("php://input");
        Yii::info('follow/recommend-list------' . $postdata, 'interface');
        $check = Service::authorization();
        if (empty($check)) {
            return $this->error("Missing a required parameter.");
        }

        $user_id = $check['user_id'];
        $all_recommend = UserRedis::getRecommendList();
        $return_data = [];
        if (!empty($all_recommend)) {
            $my_following = ServiceuUser::getFollowing($user_id);
            $my_not_follow = array_diff($all_recommend, $my_following['list']);
            if (!empty($my_not_follow)) {
                foreach ($my_not_follow as $v) {
                    $one_info = ServiceuUser::getUserInfo($v, ['guid', 'avatar', 'username']);
                    if (empty($one_info['guid'])) continue;
                    $one['guid'] = $one_info['guid'];
                    $one['username'] = $one_info['username'];
                    $one['avatar'] = Service::getCompleteUrl($one_info['avatar']);
                    $return_data[] = $one;
                }
            }
        }
        return $this->success($return_data);
    }



}
