<?php


namespace app\controllers;


use app\components\BaseController;
use app\components\define\ResponseMessage;
use app\models\CanuksFollowUser;
use app\models\CanuksList;
use app\models\Service;
use app\models\UsersExtends;

class CanuksController extends BaseController
{

    /**
     * 获取冰球球队列表
     */
    public function actionGetList()
    {
        $rows = CanuksList::find()->select("id,name,logo")->orderBy("weight desc")->asArray()->all();
        $data = [];
        foreach ($rows as $row) {
            $tmp = [];
            $tmp["id"] = $row["id"];
            $tmp["name"] = $row["name"];
            $tmp["logo"] = $row["logo"];
            $data[] = $tmp;
        }
        $this->success($data);
    }

}