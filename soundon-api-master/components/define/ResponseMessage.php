<?php

namespace app\components\define;

class ResponseMessage
{

    //general
    const Fail = "fail";
    const STSTEMERROR = "The system is busy, please try again later.";

    //user
    const UserIsLocked =  "Your account has been locked for violating community guidelines. For questions, email " . SystemConstant::SystemEmail;
}