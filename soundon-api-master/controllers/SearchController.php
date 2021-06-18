<?php


namespace app\controllers;


use app\components\BaseController;
use app\components\service\CloseRelationUser;
use app\models\Channel;
use app\models\Service;
use app\models\User;

class SearchController extends BaseController
{

    public function actionLive()
    {
        $postdata = $this->parameter;
        $check = Service::authorization();
        if (empty($check)) {
            return $this->error("Missing a required parameter.");
        }
        $this->checkuser($check["user_id"], $check["token"]);
        $keyword = $postdata["keyword"] ?? "";
        $since_id = $postdata["since_id"] ?? "1";
        $page_size = $postdata["page_size"] ?? 10;
        $rows = Channel::find()->select(['id','guid','title','type','user_id'])
            ->where(["like", "title", $keyword])
            ->andWhere(["type" => 1])
            ->limit($page_size)
            ->andWhere([">", 'id', $since_id])
            ->asArray()->all();
        $return = ['list' => [], 'page_size' => $page_size, 'since_id' => $since_id];
        if(empty($rows)){
            return  $this->success($return);
        }
        $data = [];
        foreach ($rows as $row){
            $channel_id = $row["guid"];
            $user_id = $row["user_id"];
            $tmp = CloseRelationUser::LiveStruct($channel_id, $user_id);
            $since_id = $row["id"];
            $data[] = $tmp;
        }
        $since_id = (count($rows) < $page_size) ? "": $since_id;
        $return["list"] = $data;
        $return["since_id"] = $since_id;
        return  $this->success($return);
    }

    public function actionUser(){
        $postdata = $this->parameter;
        $check = Service::authorization();
        if (empty($check)) {
            return $this->error("Missing a required parameter.");
        }
        $this->checkuser($check["user_id"], $check["token"]);
        $keyword = $postdata["keyword"] ?? "";
        $since_id = $postdata["since_id"] ?? "1";
        $page_size = 20;
        $rows = User::find()->select(['id','guid','username','avatar','first_name','last_name'])
            ->where(["like", "username", $keyword])
            ->andWhere([">", 'id', $since_id])
            ->limit($page_size)
            ->asArray()->all();
        $return = ['list' => [], 'page_size' => $page_size, 'since_id' => $since_id];
        if(empty($rows)){
            return  $this->success($return);
        }
        $data = [];
        foreach ($rows as $row){
            $tmp = [
                "id" => $row['id'],
                "username" => $row['username'],
                "first_name" => $row['first_name'],
                "last_name" => $row['last_name'],
                "avatar" => Service::getCompleteUrl($row['avatar']),
                "guid" => $row['guid'],
                "relation_status" => Service::UserRelation($row['guid'], $check["user_id"]),
            ];
            $since_id = "".$row["id"];
            $data[] = $tmp;
        }
        $since_id = (count($rows) < $page_size) ? "": $since_id;
        $return["list"] = $data;
        $return["since_id"] = $since_id;
        return  $this->success($return);
    }
}