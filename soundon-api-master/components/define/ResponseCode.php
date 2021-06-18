<?php

namespace app\components\define;

class ResponseCode
{
    const Success = 200;
    const Fail = 500;
    const NoSlotsAvailable = 30001; //加语音直播没有槽
    const OnlyFriendsJoinCall = 30002; //只有朋友能加入语音直播
    const SignIncorrect = 401; //签名错误
    const UserIslockedByAge = 441; //账户被年龄lock
    const UserIsDeleted = 442; //账户被deleted
    const UserIslocked = 443; //账户被lock
    const NoTeam = 213; //没有
}