<?php


namespace app\controllers;


use app\components\BaseController;
use app\models\Service;
use app\models\Tag;

class TagController extends BaseController
{

    public function actionGetList()
    {
        $check = Service::authorization();
        if (empty($check)) {
            return $this->error("Missing a required parameter.");
        }
        $rows = Tag::find()->select("id,logo,name")->where(["status" => "enable"])->orderBy("weight desc")->asArray()->all();
        $data = [];
        foreach ($rows as $row) {
            $data[] = [
                "tag_id" => $row["id"],
                "logo" => $row["logo"],
                "tag_name" => $row["name"],
            ];
        }
        return $this->success($data);
    }
}