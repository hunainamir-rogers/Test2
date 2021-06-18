<?php
namespace app\components;

use app\components\Thumbnail;
use app\models\Image;
use app\models\Service;
use Aws\S3\S3Client;
use Imagick;
use Yii;

class ImageUtil
{

    /**
     * easy image resize function
     * @param  $file - file name to resize
     * @param  $string - The image data, as a string
     * @param  $width - new image width
     * @param  $height - new image height
     * @param  $proportional - keep image proportional, default is no
     * @param  $output - name of the new file (include path if needed)
     * @param  $delete_original - if true the original image will be deleted
     * @param  $use_linux_commands - if set to true will use "rm" to delete the image, if false will use PHP unlink
     * @param  $quality - enter 1-100 (100 is best quality) default is 100
     * @param  $grayscale - if true, image will be grayscale (default is false)
     * @return boolean|resource
     */
    public static function smart_resize_image($file,
                                              $string = null,
                                              $width = 0,
                                              $height = 0,
                                              $proportional = false,
                                              $output = 'file',
                                              $delete_original = true,
                                              $use_linux_commands = false,
                                              $quality = 100,
                                              $grayscale = false
    )
    {

        if ($height <= 0 && $width <= 0) return false;
        if ($file === null && $string === null) return false;

        # Setting defaults and meta
        $info = $file !== null ? getimagesize($file) : getimagesizefromstring($string);
        $image = '';
        $final_width = 0;
        $final_height = 0;
        list($width_old, $height_old) = $info;
        $cropHeight = $cropWidth = 0;

        # Calculating proportionality
        if ($proportional) {
            if ($width == 0) $factor = $height / $height_old;
            elseif ($height == 0) $factor = $width / $width_old;
            else                    $factor = min($width / $width_old, $height / $height_old);

            $final_width = round($width_old * $factor);
            $final_height = round($height_old * $factor);
        } else {
            $final_width = ($width <= 0) ? $width_old : $width;
            $final_height = ($height <= 0) ? $height_old : $height;
            $widthX = $width_old / $width;
            $heightX = $height_old / $height;

            $x = min($widthX, $heightX);
            $cropWidth = ($width_old - $width * $x) / 2;
            $cropHeight = ($height_old - $height * $x) / 2;
        }

        # Loading image to memory according to type
        switch ($info[2]) {
            case IMAGETYPE_JPEG:
                $file !== null ? $image = imagecreatefromjpeg($file) : $image = imagecreatefromstring($string);
                break;
            case IMAGETYPE_GIF:
                $file !== null ? $image = imagecreatefromgif($file) : $image = imagecreatefromstring($string);
                break;
            case IMAGETYPE_PNG:
                $file !== null ? $image = imagecreatefrompng($file) : $image = imagecreatefromstring($string);
                break;
            case IMAGETYPE_WEBP:
                $file !== null ? $image = imagecreatefromwebp($file) : $image = imagecreatefromstring($string);
                break;
            default:
                return false;
        }

        # Making the image grayscale, if needed
        if ($grayscale) {
            imagefilter($image, IMG_FILTER_GRAYSCALE);
        }

        # This is the resizing/resampling/transparency-preserving magic
        $image_resized = imagecreatetruecolor($final_width, $final_height);
        if (($info[2] == IMAGETYPE_GIF) || ($info[2] == IMAGETYPE_PNG)) {
            $transparency = imagecolortransparent($image);
            $palletsize = imagecolorstotal($image);

            if ($transparency >= 0 && $transparency < $palletsize) {
                $transparent_color = imagecolorsforindex($image, $transparency);
                $transparency = imagecolorallocate($image_resized, $transparent_color['red'], $transparent_color['green'], $transparent_color['blue']);
                imagefill($image_resized, 0, 0, $transparency);
                imagecolortransparent($image_resized, $transparency);
            } elseif ($info[2] == IMAGETYPE_PNG) {
                imagealphablending($image_resized, false);
                $color = imagecolorallocatealpha($image_resized, 0, 0, 0, 127);
                imagefill($image_resized, 0, 0, $color);
                imagesavealpha($image_resized, true);
            }
        }
        imagecopyresampled($image_resized, $image, 0, 0, $cropWidth, $cropHeight, $final_width, $final_height, $width_old - 2 * $cropWidth, $height_old - 2 * $cropHeight);


        # Taking care of original, if needed
        if ($delete_original) {
            if ($use_linux_commands) exec('rm ' . $file);
            else @unlink($file);
        }

        # Preparing a method of providing result
        switch (strtolower($output)) {
            case 'browser':
                $mime = image_type_to_mime_type($info[2]);
                header("Content-type: $mime");
                $output = NULL;
                break;
            case 'file':
                $output = $file;
                break;
            case 'return':
                return $image_resized;
                break;
            default:
                break;
        }

        # Writing image according to type to the output destination and image quality
        switch ($info[2]) {
            case IMAGETYPE_GIF:
                imagegif($image_resized, $output);
                break;
            case IMAGETYPE_JPEG:
                imagejpeg($image_resized, $output, $quality);
                break;
            case IMAGETYPE_WEBP:
                imagewebp($image_resized, $output, $quality);
                break;
            case IMAGETYPE_PNG:
                $quality = 9 - (int)((0.9 * $quality) / 10.0);
                imagepng($image_resized, $output, $quality);
                break;
            default:
                return false;
        }

        return true;
    }

    public static function microtime_float()
    {
        $time = explode(" ", microtime());
        $time = $time [1] . ($time [0] * 1000);
        $time2 = explode(".", $time);
        $time = $time2 [0];
        return $time;
    }

    /**
     * 将图片保存到图片资源库
     * @param $targetFile
     * @param $ad_guid
     * @param $imgname
     * @param $cloudfront
     * @param $distinction
     * @return bool
     */
    public static function saveToImage($targetFile, $user_id, $imgname, $cloudfront, $distinction, $device_type, $device_id)
    {
        $ip = Yii::$app->getRequest()->getUserIP();
        $img_info = getimagesize($targetFile);
        $size = filesize($targetFile);
        $weight = $img_info["0"];////获取图片的宽
        $height = $img_info["1"];///获取图片的高
        $type = $img_info["mime"];
        if ($type == 'image/png') {
            $type = 'png';
        }
        if ($type == 'image/jpg' || $type == 'image/jpeg') {
            $type = 'jpg';
        }
        if ($type == 'image/gif') {
            $type = 'gif';
        }
        if ($type == 'image/webp') {
            $type = 'webp';
        }
        $image = new  Image;//将图片信息保存在image表
        $bucket = Yii::$app->params["aws"]["bucket"];
        $image->guid = $user_id;
        $image->created_by = $user_id;
        $image->device_type = $device_type;
        $image->device_id = $device_id;
        $image->height = $height;
        $image->width = $weight;
        $image->ip = $ip;
        $image->size = $size;
        $image->path = '/' . $bucket . '/' . $distinction . '/' . $imgname;
        $image->type = $type;
        $image->url = $cloudfront . $distinction . '/' . $imgname;
        if ($image->save()) {
            return true;
        } else {
            Yii::error("user_id: $user_id, targetFile: $targetFile, save image fail" . json_encode($image->getErrors()));
            return false;
        }
    }


    /**
     * aws s3 上传文件
     * @param  [type] $key       [aws的目录]
     * @param  [type] $file_path [本地文件目录]
     * @return [type]            [array]
     */
    public static function sendAws($key, $file_path)
    {
        $region = Yii::$app->params["aws"]["region"];
        $version = Yii::$app->params["aws"]["version"];
        $bucket = Yii::$app->params["aws"]["bucket"];
        $awskey = Yii::$app->params["aws"]["key"];
        $secret = Yii::$app->params["aws"]["secret"];
        if (isset($_REQUEST['region'])) {
            if ($_REQUEST['region'] == 'us') {
                $region = Yii::$app->params["aws"]["tran-region"];
                $bucket = Yii::$app->params["aws"]["tran-bucket"];
            }
        }

        try {
            $client = S3Client::factory(array(
                'region' => $region,
                'version' => $version,
                'credentials' => array(
                    'key' => $awskey,
                    'secret' => $secret,
                ),
                //'scheme' => 'http',
                'acl' => 'public-read'

            ));
            $result = $client->putObject(array(
                'Bucket' => $bucket,
                'Key' => $key,
                'SourceFile' => $file_path,
                'ACL' => 'public-read'
            ));

        } catch (\Exception $exception) {
            Yii::info('sendAws error: ------' . $region . ',$bucket=' . $bucket . ', result ,' . json_encode($exception->getMessage(), JSON_UNESCAPED_UNICODE),'my');
            return false;
        }
        return $result;
    }
    public static function S3Client()
    {
        $region = Yii::$app->params["aws"]["region"];
        $version = Yii::$app->params["aws"]["version"];
        $awskey = Yii::$app->params["aws"]["key"];
        $secret = Yii::$app->params["aws"]["secret"];
        $client = S3Client::factory ( array (
            'region' => $region,
            'version' => $version,
            'credentials' => array(
                'key' => $awskey,
                'secret'  => $secret,
            ),
            'acl' => 'public-read'//
        ));
        return $client;
    }

    /**
     * [changeImageSuffix description]
     * 修改土拍你路径并且上传到S3
     * @param  [type] $file_url  [图片地址]
     * @param string $to_suffix [修改的后缀]
     * @param arr $allow_up_suffix [允许被修改的后缀]
     * @return [type]            [description]
     * @throws \ImagickException
     */
    static function changeImageSuffix($file_url,$to_suffix='jpg',$allow_up_suffix=['webp'],$distinction='share'){
        $pathinfo = pathinfo($file_url);
        if(empty($pathinfo['extension']) || !in_array($pathinfo['extension'],$allow_up_suffix)){
            return null;
        }

        $upload_dir = \Yii::$app->params['upload_dir'];//上传文件的存放路径

        $webpnewimg = $pathinfo['filename'] . ".".$to_suffix;
        $webpFile = $upload_dir . '/' . $webpnewimg;

        $im = new Imagick($file_url);
        $im->setFormat($to_suffix);
        $webpres = $im->writeImage($webpFile);

        if ($webpres) {
            $newimg = $webpnewimg;
            $image_file = $webpFile;
            $type = $to_suffix;
        } else {
            Yii::error("file_url: $file_url, to_suffix: $to_suffix, webp convert fail, result:" . json_encode($webpres));
            return null;
        }
        $result_img = self::sendAws($distinction . '/' . $newimg, $image_file);

        if (isset($result_img['@metadata']['statusCode']) && $result_img['@metadata']['statusCode'] == 200) {
            $url = \Yii::$app->params["aws"]["url"];
            return $url . $distinction . '/' . $newimg;
        }
        return null;
    }

    /**
     * 上传图片
     * @param $name
     * @param $file_size
     * @param $file_tmp
     * @param $upload_dir
     * @param $cloudfront
     * @param $user_id
     * @param $device_type
     * @param $device_id
     * @param $distinction
     * @param null $small_size 需要缩小的大小 null为不需要缩小
     * @param int $size 允许上传的上线 10M
     * @param null $tag
     * @param array $allow_type
     * @param bool $is_change_webp  上传的文件需不需要转webp
     * @param bool $is_need_thum    需不需要上传的图片加缩略图
     * @return bool|string
     * @throws \ImagickException
     */
    static function uploadImg($name, $file_size, $file_tmp, $upload_dir, $cloudfront, $user_id, $device_type, $device_id, $distinction, $small_size = null, $size = 1073741824, $tag = null,$allow_type=[],$is_change_webp = false,$is_need_thum = true)
    {
        $type = strtolower(substr($name, strrpos($name, '.') + 1));//得到文件类型，并且都转化成小写
        // Yii::info('$type:'.$type,'my');
        $allow_type = !empty($allow_type) ? $allow_type : array('jpg', 'jpeg', 'gif', 'png', 'mp4', 'mp3', 'caf', 'amr', 'webp','log'); //定义允许上传的类型
        //$array = '';
        $array = [];
        $thum_size = 200;//修改長圖片度
        $upload_dir = rtrim($upload_dir, '/');
        if ($file_size <= $size) {
            if (in_array($type, $allow_type)) {

                $newimg1 = $user_id . '-' . self::microtime_float() . rand();
                $newimg = $newimg1 . '.' . $type;
                $image_file = $upload_dir . '/' . $newimg;

                if (!move_uploaded_file($file_tmp, $image_file)) {
                    Yii::error("user_id: $user_id, name: $name,file_tmp: $file_tmp move file fail");
                    return false;
                }
                //转成webp
                if ($is_change_webp && in_array($type, ['jpg', 'jpeg', 'png', "webp"])) {
                    //先上传jpg到s3
                    $webpnewimg1 = $user_id . '-' . self::microtime_float() . rand();

                    $res = self::UploadJpg($webpnewimg1, $upload_dir, $distinction, $image_file, $type);
                    Yii::info("image: {$webpnewimg1}, image_file: {$image_file},distinction: {$distinction},  generate jpg result: ".json_encode($res));

                    $webpnewimg = $webpnewimg1 . ".webp";
                    $webpFile = $upload_dir . '/' . $webpnewimg;

                    $im = new Imagick($image_file);
                    $im->setFormat('webp');
                    $im->setImageAlphaChannel(imagick::ALPHACHANNEL_ACTIVATE);
                    try{
                        $webpres = $im->writeImage($webpFile);
                    }catch (\Exception $e){
                        $webpres = false;
                        return false;
                    }
                    if ($webpres) {
                        $newimg1 = $webpnewimg1;
                        $newimg = $webpnewimg;
                        $image_file = $webpFile;
                        $type = "webp";
                    } else {
                        Yii::error("user_id: $user_id, name: $name, webp convert fail, result:" . json_encode($webpres));
                    }
                }

                //生成缩略图
                if(empty($small_size) && $distinction!='video' && $is_need_thum) {
                    //image_file 原图位置
                    $array['thum_img_url'] = self::makeThumb($image_file,$thum_size,$upload_dir,$newimg1,$distinction,$tag,$user_id,$cloudfront,$device_type,$device_id,$type);
                }
                if ($tag == null && in_array($type, ['jpg', 'jpeg', 'png', "webp"]) ) {
                    self::saveToImage($image_file, $user_id, $newimg, $cloudfront, $distinction, $device_type, $device_id);
                }
                $result_img = self::sendAws($distinction . '/' . $newimg, $image_file);//上传到S3
                if (isset($result_img['@metadata']['statusCode']) && $result_img['@metadata']['statusCode'] == 200) {
                    if (!empty($small_size)) {
                        $newimg_s = $newimg1 . '_' . $small_size . '.' . $type;

                        $resizedFile = $upload_dir . '/' . $newimg_s;
                        $resizedFile2 = $upload_dir . '/' . $newimg1 . '_' . $small_size;
                        $generateSmallResult = self::smart_resize_image($image_file, null, $small_size, $small_size, false, $resizedFile, false, false, 100);
                        if(!$generateSmallResult){
                            Yii::error("generate small image fail, user_id: $user_id, small image: $resizedFile");
                        }

                        //如果上传的是头像,将小图处理为圆形
                        if ($distinction == 'avatar') {
                            $imagick = new \Imagick($resizedFile);
                            $imagick->setImageBackgroundColor("#FFFFFF");
                            //将图片处理成圆角
                            //$imagick->roundCorners($imagick->getImageWidth() / 2, $imagick->getImageHeight() / 2);
                            $imagick->resizeimage(80, 80, \Imagick::FILTER_LANCZOS, 1.0, true);
                            $imagick->setImageFormat('webp');
                            $imagick->writeImage($resizedFile2 . '.webp');
                        }
                        if ($distinction != 'feed') {
                            $re = self::sendAws($distinction . '/' . $newimg_s, $resizedFile);
                            if (!$re) {
                                return false;
                            }
                        }
                    }
                    $array['img_url'] =  "/" . $distinction . '/' . $newimg;
                    //$array = $cloudfront . $distinction . '/' . $newimg;
                }

            } else {
                Yii::error("user_id: $user_id, not allow upload image type, type: $type");
                return false;
            }
        }
        // var_dump($array);exit;
        // Yii::info('$array:'.json_encode($array),'my');
        return $array;
    }

    //先上传jpg到s3
    static function UploadJpg($newimg1, $upload_dir, $distinction, $image_file, $ext){
        if($ext == "jpg"){
            $res = self::sendAws($distinction . '/' . $newimg1. ".jpg", $image_file);//上传到S3
            Yii::info("jpgImg: $newimg1, upload to s3 result".json_encode($res));
            return $res;
        }

        $jpgImg = $newimg1 . ".jpg";
        $dest = $upload_dir."/".$jpgImg;
        if(copy($image_file, $dest)){
//            Yii::info("jpgImg: $jpgImg");
            return self::sendAws($distinction . '/' . $jpgImg, $upload_dir . "/" . $jpgImg);//上传到S3
        }else{
            //system("cp $image_file $dest", $retval);
            Yii::error("image_file: {$image_file} upload fail!");
            return false;
        }
    }

    static function resizeImage($im, $maxwidth, $maxheight, $name, $filetype)
    {
        $pic_width = imagesx($im);
        $pic_height = imagesy($im);
        $resizewidth_tag = false;
        $resizeheight_tag = false;
        if (($maxwidth && $pic_width > $maxwidth) || ($maxheight && $pic_height > $maxheight)) {
            if ($maxwidth && $pic_width > $maxwidth) {
                $widthratio = $maxwidth / $pic_width;
                $resizewidth_tag = true;
            }

            if ($maxheight && $pic_height > $maxheight) {
                $heightratio = $maxheight / $pic_height;
                $resizeheight_tag = true;
            }

            if ($resizewidth_tag && $resizeheight_tag) {
                if ($widthratio < $heightratio)
                    $ratio = $widthratio;
                else
                    $ratio = $heightratio;
            }

            if ($resizewidth_tag && !$resizeheight_tag)
                $ratio = $widthratio;
            if ($resizeheight_tag && !$resizewidth_tag)
                $ratio = $heightratio;

            $newwidth = $pic_width * $ratio;
            $newheight = $pic_height * $ratio;

            if (function_exists("imagecopyresampled")) {
                $newim = imagecreatetruecolor($newwidth, $newheight);
                imagecopyresampled($newim, $im, 0, 0, 0, 0, $newwidth, $newheight, $pic_width, $pic_height);
            } else {
                $newim = imagecreate($newwidth, $newheight);
                imagecopyresized($newim, $im, 0, 0, 0, 0, $newwidth, $newheight, $pic_width, $pic_height);
            }

            $name = $name . $filetype;
            imagejpeg($newim, $name);
            imagedestroy($newim);
        } else {
            $name = $name . $filetype;
            imagejpeg($im, $name);
        }
    }



    /**
     * 生成缩略图并上传aws
     */
    public static function makeThumb($image_file,$thum_size,$upload_dir,$newimg1,$distinction,$tag,$user_id,$cloudfront,$device_type,$device_id,$type){
        if($type == 'gif'){
            return $cloudfront . $distinction . '/' . $newimg1. '.gif';
        }
        $thum_img = new Thumbnail();
        $thum_img->open($image_file);
        $thum_img->resize_to($thum_size, $thum_size, 'scale');
        $thum_dir = $upload_dir."/thumbs/";
        if(!is_dir($thum_dir)) mkdir($thum_dir,0777,true);
        //暂时有问题gif压缩，应按大小。。。
        if($type == 'gif'){
            $thum_name = $newimg1 . '_' . $thum_size . '.gif';
        }else{
            $thum_name = $newimg1 . '_' . $thum_size . '.webp';
        }
        $thum_file = $thum_dir.$thum_name;
        $thum_img->save_to($thum_file);
        $res_thum = self::sendAws($distinction . '/' . $thum_name, $thum_file); //上传到S3
        if ($tag == null) {
            self::saveToImage($thum_file, $user_id, $thum_name, $cloudfront, $distinction, $device_type, $device_id);
        }
        if (isset($res_thum['@metadata']['statusCode']) && $res_thum['@metadata']['statusCode'] == 200) {
            return $cloudfront . $distinction . '/' . $thum_name;
        }
    }

    static function webToWebp($weburl, $upload_dir ,$cloudfront, $user_id , $distinction, $thum_size = 200, $size = 1073741824, $tag = null,$bucket = '')
    {
        $type = 'jpg'; //得到文件类型，并且都转化成小写
        $allow_type = array('jpg', 'jpeg', 'gif', 'png', 'mp4', 'mp3', 'caf', 'amr', 'webp'); //定义允许上传的类型
        $array = [];
        //reqweqwe
        $upload_dir = rtrim($upload_dir, '/');
        //if ($file_size <= $size) {
        if (in_array($type, $allow_type)) {

            $newimg1 = $user_id . '-' . self::microtime_float() . rand();
            $newimg = $newimg1 . '.' . $type;
            //$image_file = $upload_dir . '/' . $newimg;
            $image_file = $weburl;
            //转成webp
            if (in_array($type, ['jpg', 'jpeg', 'png'])) {
                $webpnewimg1 = $user_id . '-' . self::microtime_float() . rand();
                //$res = self::UploadJpg($webpnewimg1, $upload_dir, $distinction, $image_file, $type);

                $webpnewimg = $webpnewimg1 . ".webp";
                $webpFile = $upload_dir . '/' . $webpnewimg;

                $im = new Imagick($image_file);
                $im->setFormat('webp');
                $webpres = $im->writeImage($webpFile);
                if ($webpres) {
                    $newimg1 = $webpnewimg1;
                    $newimg = $webpnewimg;
                    $image_file = $webpFile;
                    $type = "webp";
                } else {
                    Yii::error("user_id: $user_id, name: $newimg1, webp convert fail, result:" . json_encode($webpres));
                }
            }
            //生成缩略图
            if (!empty($thum_size)) {
                $array['thum_img_url'] = self::makeThumb($image_file, $thum_size, $upload_dir, $newimg1, $distinction, $tag, $user_id, $cloudfront, 'ios', '1', $type,$bucket);
            }

            if ($tag == null) {
                self::saveToImage($image_file, $user_id, $newimg, $cloudfront, $distinction, 'ios', '1');
            }
            $result_img = self::sendAws($distinction . '/' . $newimg, $image_file,$bucket); //上传到S3
            if (isset($result_img['@metadata']['statusCode']) && $result_img['@metadata']['statusCode'] == 200) {
                $array['img_url'] = $cloudfront . $distinction . '/' . $newimg;
            }
        } else {
            Yii::error("user_id: $user_id, not allow upload image type, type: $type");
            return false;
        }
        //}
        return $array;
    }

    /**获取一个后缀的图片地址
     * @param string $url 源地址
     * @param string $suffix 后缀
     * @return string
     * @throws \ImagickException
     */
    static function getOneImageSuffixUrl($url = '',$suffix='jpg'){
        if(!empty($url)){
            $path = parse_url($url,PHP_URL_PATH);
            $bucket = Yii::$app->params["aws"]["bucket"];
            if(!empty($path)){
                $path = str_replace(['.webp','.png','.jpeg'],'.'.$suffix,$path);
                $object = false;
                try{
                    $object = self::S3Client()->doesObjectExist($bucket,ltrim($path,'/'));
                }catch (\Exception $e){
                }
                if(!$object){
                    //没有就生成
                    $pathinfo = pathinfo($path);
                    $basename = $pathinfo['basename'] ?? '';
                    $upload_dir = \Yii::$app->params['upload_dir'];//上传文件的存放路径
                    $upload_dir = rtrim($upload_dir, '/');
                    $webpFile = $upload_dir . '/' . $basename;
                    $im = new Imagick($url);
                    $im->setFormat($suffix);
                    $webpres = $im->writeImage($webpFile);
                    if($webpres){
                        $result = self::sendAws(ltrim($path,'/'), $webpFile);
                        if($result){
                            return Service::getCompleteUrl($path);
                        }
                    }
                }else{
                    return Service::getCompleteUrl($path);
                }
            }
        }
        return $url;
    }


}

?>
