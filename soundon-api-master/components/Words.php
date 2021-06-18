<?php
/**
 * Created by PhpStorm.
 * User: XHY
 * Date: 2018/6/6
 * Time: 10:29
 */

namespace app\components;

class Words
{
    const NOLIVE = "TBD";
    const UNKNOWN = "unknown error!";
    const POSTEMPTYDATA = "Post Data is empty!";
    const MISSREQUIRED = "Missing a required parameter.";
    const PARAMERROR = "parameter error!";
    const STSTEMERROR = "The system is busy, please try again later.";
    const PERMISSIONDENIED = "Out ka na ";
    const RESOURCESNOTEXIST = "The visiting department of resources exists!";

    const EXISTLINGCHANNEL = "There was already a live broadcast!";

    //user
    const INVITECODEMISS = "The invitation code does not exist.";
    const INVITECODEERROR = "The invitation code is incorrect.";
    const INVITECODEDUPLICATION = "The invitation code has been filled in.";
    const INVITEMYSELF = "Don't invite yourself!";
    const USERNOTEXIST = "User does not exist";
    const NOTAVAILABLECARRIER = "Kumu is not available for your carrier";
    const SmsCountryMaxLimit = "You have reached your SMS limit. You can retry in 24 hrs or contact us at support@kumu.ph";
    const UserLockedByIp = "Your IP address has been locked for violating community guidelines. For questions, email support@kumu.ph";

    //live
    const NOT_LIVESTREAM = "The current livestream does not exist or has ended";
    const LIVEEMPAT = "There is no channel being broadcast.";
    const CHANNELNOLIVE = "There is no channel being broadcast。";
    const CHANNELMISS = "Channel id required.";

    const ANSWERTIMEOUT = "The answer timeout!";
    const ANSWERDUPLICATION = "Please do not repeat the submission!";

    const LIFEAREADYUSE = "The live stream has already used health values.";
    const LIFENOTENOUGH = "underlife!";
    const LIFELAST = "The last question cannot use rice!";//最后一题不能使用生命
    const STATISTICSRUNTIME = "It is not the statistical time!";//未到统计时间
    const LIFTNOPERMISSION = "No permission to use rice";//没有使用生命的权限,因为上一题并没有答错

    //rank
    const NOHISTORYTOP = "There is no historical ranking.";

    //friend
    const FREINDSHASBEENADD = "It's been added";//已经发送过申请
    const FRIENDSEACH = "We're already friends";//互相是好友了
    const INVITEWORD = "invite word"; //邀请文字
    const FRIENDSNOTFOUND = "He's not your friend"; //他不是你的好友
    const FRIENDSNOTAPPLY = "They didn't apply to add you as a friend"; //对方没有申请添加你为好友

    //block
    const  BLOCKHASYOU = "You're blacklisted"; //你被加入了黑名单

    //win
    const WINVERIFYFAIL = "permission denied! Please check the token!";

    const JAR_ERROR = "HQ jar start error!";

    const VersionCheck = "Please update your app to latest version"; //版本升级提示语

    const    LEVEL = [2500, 5000, 10000, 20000, 40000, 80000, 160000, 320000, 640000, 1920000, 5760000, 17280000, 51840000, 155520000, 466560000, 1399680000, 4199040000, 12597120000, 37791360000];

}