<?php
/**
 * Name: RedisClient.php
 * Description:    redis操作公共底层
 *
 */

namespace app\components;

use Yii;
use yii\db\Exception;

class RedisClient{

    private static $redis_object;
    private static $arr_obj;
    private $redis_type = '';
    private $isCluster = false;
    const POSITION_BEFORE = 'BEFORE';
    const POSITION_AFTER = 'AFTER';
    const WITHSCORES = 'WITHSCORES';
    const CONNECT_TIMEOUT = 2;

    const LUA_SCRIPT_LOCK = "return redis.call('set', KEYS[1], ARGV[1], 'nx', 'ex', ARGV[2]);";
    const LUA_SCRIPT_UNLOCK = "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del',KEYS[1]) else return 0 end";
    const LUA_SCRIPT_HGETALLBATCH = "local rtn = {}; for i,v in ipairs(KEYS) do local item = redis.call('hgetall', v);if not item then rtn[i]={} else rtn[i]=item;end; end; return rtn;";



    function __construct($redis_type) {
        $this->redis_type = $redis_type;
    }

    function __call($method, $args) {
        $redis_conf_pri = Yii::$app->params['redis_conf'];
        if (isset($redis_conf_pri[$this->redis_type])) {
            $redis_conf_pri = $redis_conf_pri[$this->redis_type];
        }else {
            throw  new Exception('redis conf empty err!');
        }
        $redis_write_methods = Yii::$app->params['redis_write_method'];
        $redis_write_methods = explode(',', $redis_write_methods);

        $thekey = $args[0];
        $onekeytype = $redis_conf_pri;
        $isCluster = isset($onekeytype["cluster"])? $onekeytype["cluster"]: false;
        $this->isCluster = $isCluster;
        $masterorslave = 'slave';
        if (in_array($method, $redis_write_methods)) {
            $masterorslave = 'master';
        }
//        if (in_array($method, ['hgetallbatch'])) {
//            $redis_conf = $onekeytype[$masterorslave][$args[1]];
//        }else {
            // hash it
            $total = count($onekeytype[$masterorslave]);
            $hashidx = rand(0, $total - 1);
//            $hashidx = crc32($thekey) % $total;
            $redis_conf = $onekeytype[$masterorslave][$hashidx];
//        }


        /////////////////// redis_conf ok, connect
        try {
            $conn = $this->_connect($redis_conf['host'], $redis_conf['port'], self::CONNECT_TIMEOUT, $isCluster);
        } catch (\RedisException $e) {
            try {
                $conn = $this->_connect($redis_conf['host'], $redis_conf['port'], self::CONNECT_TIMEOUT, $isCluster);
            } catch (RedisException $e) {
                $conn = false;
                $log = "func:connect_twice;args:" . json_encode($args) . ";redis_server:" . $redis_conf['host'] . " " . $redis_conf['port'] . ";errno:" . $e->getCode() . ";error:" . $e->getMessage();
            }
        }
        try {
            if ($conn) {
                $data = call_user_func_array(array($this, $method . "Redis"), $args);
                return $data;
            } else {
                return false;
            }
        } catch (\RedisException $e) {
            $log = "func:" . $method . ";args:" . json_encode($args) . ";redis_server:" . $redis_conf['host'] . " " . $redis_conf['port'] . ";errno:" . $e->getCode() . ";error:" . $e->getMessage();
            return false;
        }
    }

    private function _connect($host, $port, $timeout = self::CONNECT_TIMEOUT, $isCluster = false) {
        $bool = true;
        $key = $host . ":" . $port;
        if($isCluster){//cluster 连接
            if (!isset(self::$arr_obj[$key])) {
                self::$arr_obj[$key] = new \RedisCluster(NULL, [$key], $timeout, 5);
            }
            self::$redis_object = self::$arr_obj[$key];
        }else{
            if (!isset(self::$arr_obj[$key])) {
                self::$arr_obj[$key] = new \Redis;
            }
            self::$redis_object = self::$arr_obj[$key];
            if (!self::$redis_object->isConnected()) {
                $bool = self::$redis_object->connect($host, $port, $timeout);
            }
        }
        return $bool;
    }

    /**
     *
     * @abstract 删除一个key
     * @param string $key
     * @return int
     */
    private function delRedis($key) {
        if (!is_array($key)) {
            $key = func_get_args();
        }
        return self::$redis_object->del($key);
    }

    /**
     * @abstract 判断key是否已存在
     * @param string $key
     * @return int
     */
    private function existsRedis($key) {
        return self::$redis_object->exists($key);
    }

    /**
     * @abstract 设置key的有效时间，单位为秒
     * Set a key's time to live in seconds
     * @param string $key
     * @param int $seconds
     * @return boolean
     */
    private function expireRedis($key, $seconds) {
        return self::$redis_object->expire($key, $seconds);
    }

    private function overdueRedis($v_key, $key, $seconds) {
        return self::$redis_object->expire($key, $seconds);
    }

    /**
     * @abstract 设置key的有效时间，unix时间戳
     * Set the expiration for a key as a UNIX timestamp
     * @param string $key
     * @param int $timestamp
     * @return int
     */
    private function expireatRedis($key, $timestamp) {
        return self::$redis_object->expireat($key, $timestamp);
    }

    /**
     * Move key from the currently selected database (see SELECT) to the specified destination database.
     * When key already exists in the destination database, or it does not exist in the source database, it does
    nothing.
     * It is possible to use MOVE as a locking primitive because of this.
     * @param int $db
     * @return int
     */
    private function moveRedis($key, $db) {
        return self::$redis_object->move($key, $db);
    }


    /**
     * Remove the expiration from a key
     * @param string $key
     * @return int
     */
    private function persistRedis($key) {
        return self::$redis_object->persist($key);
    }

    /**
     * 重命名一个key
     * Renames key to newkey.
     * It returns an error when the source and destination names are the same, or when key does not exist.
     * If newkey already exists it is overwritten.
     * @param string $key
     * @param string $newkey
     * @return boolean
     */
    private function renameRedis($key, $newkey) {
        return self::$redis_object->rename($key, $newkey);
    }

    /**
     * 如果新的key还没有使用，可以用它重命名一个key
     * Rename a key, only if the new key does not exist
     * @param string $key
     * @param string $newkey
     * @return int
     */
    private function renamenxRedis($key, $newkey) {
        return self::$redis_object->renamenx($key, $newkey);
    }

    /**
     * Sort the elements in a list, set or sorted set
     *@key string
     *@sort_rule:  Options: array(key => value, ...) - optional, with the following keys and values:
     *'by' => 'some_pattern_*',
     *'limit' => array(0, 1),
     *'get' => 'some_other_pattern_*' or an array of patterns,
     *'sort' => 'asc' or 'desc',
     *'alpha' => TRUE,
     *'store' => 'external-key'
     *Return value
     *An array of values, or a number corresponding to the number of elements stored if that was used.
     */
    private function sortRedis($key, $sort_rule = '') {
        if ($sort_rule) {
            return self::$redis_object->sort($key, $sort_rule);
        } else {
            return self::$redis_object->sort($key);
        }
    }

    /**
     * @abstract 获取一个key的剩余有效时间
     * Get the time to live for a key
     * @param string $key
     * @return int
     */
    private function ttlRedis($key) {
        return self::$redis_object->ttl($key);
    }

    /**
     * @获取一个key里存储的数据类型
     * Returns the string representation of the type of the value stored at key.
     * @return string
     *Depending on the type of the data pointed by the key, this method will return the following value:
     *string: Redis::REDIS_STRING 1
     *set: Redis::REDIS_SET 2
     *list: Redis::REDIS_LIST 3
     *zset: Redis::REDIS_ZSET 4
     *hash: Redis::REDIS_HASH 5
     *other: Redis::REDIS_NOT_FOUND 0
     */
    private function typeRedis($key) {
        return self::$redis_object->type($key);
    }

    /**
     *Describes the object pointed to by a key
     *he information to retrieve (string) and the key (string). Info can be one of the following:
     *"encoding"
     *"refcount"
     *"idletime"
     *Return value
     *STRING for "encoding", LONG for "refcount" and "idletime", FALSE if the key doesn't exist.
     * @return maxed
     */
    private function objectRedis($key, $subcommand) {
        return self::$redis_object->object($subcommand, $key);
    }

    /**
     * @category string
     * @abstract 在一个key的值后面追加一个值
     * Append a value to a key
     * @param string $key
     * @param string $value
     * @return int
     */
    private function appendRedis($key, $value) {
        return self::$redis_object->append($key, $value);
    }

    /**
     * @category string
     * @abstract 获取key的值
     * Get the value of key
     * @param string $key
     * @return string
     */
    private function getRedis($key) {
        return self::$redis_object->get($key);
    }

    /**
     * @category string
     * @abstract 设置key的值
     * set key to hold the string value
     * @param string $key
     * @param string $value
     * @return always OK since SET can't fail
     */
    private function setRedis($key, $value,$ttl = false) {
        if(!empty($ttl)) {
            return self::$redis_object->set($key, $value,$ttl);
        }
        else {
            return self::$redis_object->set($key, $value);
        }
    }

    /**
     * @category string
     * @abstract key的（整数）值减1
     * Decrement the integer value of a key by one
     * @param string $key
     * @return int
     */
    private function decrRedis($key) {
        return self::$redis_object->decr($key);
    }

    private function lock($key, $random, $ttl) {
        return $this->eval($key, self::LUA_SCRIPT_LOCK, [$random, $ttl], 1);
    }

    private function unlock($key, $random) {
        return $this->eval($key, self::LUA_SCRIPT_UNLOCK, [$random], 1);
    }

    /**
     * @category string
     * @abstract key的（整数）值减 n
     * Decrement the integer value of a key by the given number
     * @param string $key
     * @param int $decrement
     * @return int
     */
    private function decrbyRedis($key, $decrement) {
        return self::$redis_object->decrby($key, $decrement);
    }

    /**
     * Returns the bit value at offset in the string value stored at key
     * @param string $key
     * @param int $offset
     */
    private function getbitRedis($key, $offset) {
        return self::$redis_object->getbit($key, $offset);
    }

    /**
     * Get a substring of the string stored at a key
     * @param string $key
     * @param int $start
     * @param int $end
     * @return string
     */
    private function getrangeRedis($key, $start, $end) {
        return self::$redis_object->getrange($key, $start, $end);
    }

    /**
     * Atomically sets key to value and returns the old value stored at key.
     * Returns an error when key exists but does not hold a string value.
     *
     * From time to time we need to get the value of the counter and reset it to zero atomically.
     * This can be done using GETSET mycounter "0".
     * @param string $key
     * @param string $value
     * @return string
     */
    private function getsetRedis($key, $value) {
        return self::$redis_object->getset($key, $value);
    }

    /**
     * Increment the integer value of a key by one
     * @param string $key
     * @return int
     */
    private function incrRedis($key) {
        return self::$redis_object->incr($key);
    }


    /**
     * Increment the integer value of a key by the given number
     * @param string $key
     * @param int $increment
     * @return int
     */
    private function incrbyRedis($key, $increment) {
        return self::$redis_object->incrby($key, $increment);
    }

    /**
     * Returns the values of all specified keys.
     * For every key that does not hold a string value or does not exist, the special value nil is returned.
     * Parameters: $key, [key ...]
     * or: array($key1, $key2...)
     * @param string $key
     * @return array
     */
    private function mgetRedis($key) {
//         $args = func_get_args();
//         if (count($args) > 1) {
//             return call_user_func_array(array(self::$redis_object, 'mget'), array($args));
//         }
        return self::$redis_object->mget($key);
    }

    private function msetRedis($values) {
        return self::$redis_object->mset($values);
    }

    /**
     *sets or clears the bit at offset in the string value stored at key
     * @link http://redis.io/commands/setbit
     * @param string $key
     * @param int $offset
     * @param int $value
     * Returns the original bit value stored at offset.
     * @return int
     */
    private function setbitRedis($key, $offset, $value) {
        return self::$redis_object->setbit($key, $offset, $value);
    }

    /**
     * Set the value and expiration of a key
     * @param string $key
     * @param int $seconds
     * @param string $value
     * @return boolean
     */
    private function setexRedis($key, $seconds, $value) {
        return self::$redis_object->setex($key, $seconds, $value);
    }

    /**
     * Set the value of a key, only if the key does not exist
     * @param string $key
     * @param string $value
     */
    private function setnxRedis($key, $value) {
        return self::$redis_object->setnx($key, $value);
    }

    /**
     * Overwrites part of the string stored at key, starting at the specified offset, for the entire length of
    value.
     * If the offset is larger than the current length of the string at key, the string is padded with zero-bytes
    to make offset fit.
     * Non-existing keys are considered as empty strings, so this command will make sure it holds a string large
    enough
     * to be able to set value at offset.
     *
     * Thanks to SETRANGE and the analogous GETRANGE commands, you can use Redis strings as a linear array with O
    (1) random access.
     * This is a very fast and efficient storage in many real world use cases.
     * @link http://redis.io/commands/setrange
     * @param string $key
     * @param int $offset
     * @param string $value
     * Returns the length of the string after it was modified by the command.
     * @return int
     */
    private function setrangeRedis($key, $offset, $value) {
        return self::$redis_object->setrange($key, $offset, $value);
    }

    /**
     * Get the length of the value stored in a key
     * @param string $key
     * @return int
     */
    private function strlenRedis($key) {
        return self::$redis_object->strlen($key);
    }

    /**
     * removes the specified fields from the hash stored at key.
     * Non-existing fields are ignored. Non-existing keys are treated as empty hashes and this command returns 0.
     * Parameters: ($key, $field1, $field2...)
     * or: ($key, array($field1,$field2...))
     * @param $key
     * @param array|string $field
     * @return int
     */
    private function hdelRedis($key, $field) {
        // if (is_array($field)) {
        //     array_unshift($field, $key);
        // } else {
        //     $field = array($key, $field);
        // }
        if (is_array($field)) {
            $field = implode(",", $field);
        }

        return self::$redis_object->hdel($key, $field);
    }

    /**
     * Determine if a hash field exists
     * @param string $key
     * @param string $field
     * @return int
     */
    private function hexistsRedis($key, $field) {
        return self::$redis_object->hexists($key, $field);
    }

    /**
     * Get the value of a hash field
     * @param string $key
     * @param string $field
     * @return string|int
     */
    private function hgetRedis($key, $field) {
        return self::$redis_object->hget($key, $field);
    }

    /**
     * Get all the fields and values in a hash
     * @param string $key
     * @return array
     */
    private function hgetallRedis($key) {
        return $arr = self::$redis_object->hgetall($key);
    }

    private function hgetallbatchRedis($keys) {
        $res = array();
        if($this->isCluster){
            if(is_array($keys)){
                foreach ($keys as $key){
                    $res[] = self::$redis_object->hgetall($key);
                }
            }
            return $res;
        }
        $keyNum = count($keys);
        $data =  self::$redis_object->eval(self::LUA_SCRIPT_HGETALLBATCH, $keys, $keyNum);
//        Yii::info('hgetallbatchRedis------' . json_encode($data, JSON_UNESCAPED_UNICODE), 'interface');
        foreach ($data as $item) {
            $number = count($item);
            $tmp = array();
            for ($i=0; $i<$number; $i++) {
                if ($i % 2 == 0) {
                    $tmp[$item[$i]] = $item[$i+1];
                }
            }
            $res[] = $tmp;
        }
        return $res;
    }

    /**
     * Increments the number stored at field in the hash stored at key by increment.
     * If key does not exist, a new key holding a hash is created.
     * If field does not exist or holds a string that cannot be interpreted as integer, the value is set to 0
    before the operation is performed.
     * Returns the value at field after the increment operation.
     * @param string $key
     * @param string $field
     * @param int $increment
     * @return int
     */
    private function hincrbyRedis($key, $field, $increment) {
        return self::$redis_object->hincrby($key, $field, $increment);
    }

    /**
     * Get all the fields in a hash
     * @param string $key name of hash
     * @return array
     */
    private function hkeysRedis($key) {
        return self::$redis_object->hkeys($key);
    }

    /**
     * Get the number of fields in a hash
     * @param string $key
     * @return int
     */
    private function hlenRedis($key) {
        return self::$redis_object->hlen($key);
    }

    /**
     * Returns the values associated with the specified fields in the hash stored at key.
     * For every field that does not exist in the hash, a nil value is returned.
     * @param string $key
     * @param array $fields
     * @return array
     */
    private function hmgetRedis($key, array $fields) {
        return self::$redis_object->hmget($key, $fields);
    }

    /**
     * Set multiple hash fields to multiple values
     * @param string $key
     * @param array $fields (field => value)
     */
    private function hmsetRedis($key, array $fields) {
        return self::$redis_object->hmset($key, $fields);
    }

    /**
     * Set the string value of a hash field
     * @param string $key hash
     * @param string $field
     * @param string $value
     * @return int
     */
    private function hsetRedis($key, $field, $value) {
        return self::$redis_object->hset($key, $field, $value);
    }

    /**
     * Set the value of a hash field, only if the field does not exist
     * @param string $key
     * @param string $field
     * @param string $value
     * @return int
     */
    private function hsetnxRedis($key, $field, $value) {
        return self::$redis_object->hsetnx($key, $field, $value);
    }

    /**
     * Get all the values in a hash
     * @param string $key
     * @return array
     */
    private function hvalsRedis($key) {
        return self::$redis_object->hvals($key);
    }

    /**
     * Remove and get the first element in a list, or block until one is available
     * Parameters format:
     * array(key1,key2,keyN), timeout
     * @param string $key
     * @param int $timeout - time of waiting
     */
    private function blpopRedis($key, $timeout) {
        return self::$redis_object->blpop($key, $timeout);
    }

    /**
     * Remove and get the last element in a list, or block until one is available
     * Parameters format:
     * array(key1,key2,keyN), timeout
     * @param string $key
     * @param int $timeout - time of waiting
     */
    private function brpopRedis($key, $timeout) {
        return self::$redis_object->brpop($key, $timeout);
    }

    /**
     * Pop a value from a list, push it to another list and return it; or block until one is available
     * @param string $source
     * @param string $destination
     * @param int $timeout
     * @return string|boolean
     */
    private function brpoplpushRedis($source, $destination, $timeout) {
        return self::$redis_object->brpoplpush($source, $destination, $timeout);
    }

    /**
     * Returns the element at index $index in the list stored at $key.
     * The index is zero-based, so 0 means the first element, 1 the second element and so on.
     * Negative indices can be used to designate elements starting at the tail of the list.
     * Here, -1 means the last element, -2 means the penultimate and so forth.
     * When the value at key is not a list, an error is returned.
     * @param string $key
     * @param int $index
     * @return string|boolean
     */
    private function lindexRedis($key, $index) {
        return self::$redis_object->lindex($key, $index);
    }

    /**
     * Insert an element before or after another element in a list
     * @param string $key
     * @param bool $after
     * @param string $pivot
     * @param string $value
     * @return int
     */
    private function linsertRedis($key, $after = true, $pivot, $value) {
        if ($after) {
            $position = self::POSITION_AFTER;
        } else {
            $position = self::POSITION_BEFORE;
        }

        return self::$redis_object->linsert($key, $position, $pivot, $value);
    }

    /**
     * Get the length of a list
     * @param string $key
     * @return int
     */
    private function llenRedis($key) {
        return self::$redis_object->llen($key);
    }

    /**
     * Remove and get the first element in a list
     * @param string $key
     * @return string|boolean
     */
    private function lpopRedis($key) {
        return self::$redis_object->lpop($key);
    }

    /**
     * Inserts value at the head of the list stored at key.
     * If key does not exist, it is created as empty list before performing the push operation.
     * When key holds a value that is not a list, an error is returned.
     * @param string $key
     * @param string $value
     * @return int
     */
    private function lpushRedis($key, $value) {
        return self::$redis_object->lpush($key, $value);
    }

    /**
     * Inserts value at the head of the list stored at key, only if key already exists and holds a list.
     * In contrary to LPush, no operation will be performed when key does not yet exist.
     * @param string $key
     * @param string $value
     * @return int
     */
    private function lpushxRedis($key, $value) {
        return self::$redis_object->lpushx($key, $value);
    }

    /**
     * Returns the specified elements of the list stored at key.
     * The offsets $start and $stop are zero-based indexes, with 0 being the first element of the list (the head
    of the list),
     * 1 being the next element and so on.
     * These offsets can also be negative numbers indicating offsets starting at the end of the list.
     * For example, -1 is the last element of the list, -2 the penultimate, and so on.
     * @param string $key
     * @param int $start
     * @param int $stop
     * @return array
     */
    private function lrangeRedis($key, $start, $stop) {
        return self::$redis_object->lrange($key, $start, $stop);
    }

    /**
     * removes all value
     */
    private function lremRedis($key, $value, $count = 0) {
        if ($count == 0) {
            return self::$redis_object->lrem($key, $value);
        } else {
            return self::$redis_object->lrem($key, $value, $count);
        }
    }

    /**
     * Removes the first count occurrences of elements equal to value from the list stored at key.
     * The count argument influences the operation in the following ways:
     * count > 0: Remove elements equal to value moving from head to tail.
     * count < 0: Remove elements equal to value moving from tail to head.
     * count = 0: Remove all elements equal to value.
     * For example, LREM list -2 "hello" will remove the last two occurrences of "hello" in the list stored at
    list.
     * @param string $key
     * @param int $count
     * @param string $value
     * @return int
     */
    private function lremoveRedis($key, $value, $count) {
        return self::$redis_object->lremove($key, $value, $count);
    }

    /**
     * Sets the list element at index to value.
     * For more information on the index argument, see LINDEX.
     * An error is returned for out of range indexes.
     * @param $key
     * @param $index
     * @param $value
     * @return boolean
     */
    private function lsetRedis($key, $index, $value) {
        return self::$redis_object->lset($key, $index, $value);
    }

    /**
     *get list element
     *@param $key
     *@param $value
     *@return string
     */
    private function lgetRedis($key, $index) {
        return self::$redis_object->lget($key, $index);
    }

    /**
     * Trim a list to the specified range
     * @link http://redis.io/commands/ltrim
     * @param string $key
     * @param int $start
     * @param int $stop
     * @return boolean
     */
    private function ltrimRedis($key, $start, $stop) {
        return self::$redis_object->ltrim($key, $start, $stop);
    }

    /**
     * Removes and returns the last element of the list stored at key.
     * @param string $key
     * @return string|boolean
     */
    private function rpopRedis($key) {
        return self::$redis_object->rpop($key);
    }

    /**
     * Atomically returns and removes the last element (tail) of the list stored at source,
     * and pushes the element at the first element (head) of the list stored at destination.
     * If source does not exist, the value nil is returned and no operation is performed.
     * @param string $source
     * @param string $destination
     * @return string
     */
    private function rpoplpushRedis($source, $destination) {
        return self::$redis_object->rpoplpush($source, $destination);
    }

    /**
     * Inserts value at the tail of the list stored at key.
     * If key does not exist, it is created as empty list before performing the push operation.
     * When key holds a value that is not a list, an error is returned.
     * key, array(value,value,...)
     * @param string $key
     * @param string|array $value
     * @return int|boolean
     */
    private function rpushRedis($key, $value) {
        return self::$redis_object->rpush($key, $value);
    }

    /**
     * Append a value to a list, only if the list exists
     * @param string $key
     * @param string $value
     * @return int
     */
    private function rpushxRedis($key, $value) {
        return self::$redis_object->rpushx($key, $value);
    }

    /** Add a member to a set
     * @param string $key
     * @param string $value
     * @return boolean
     */
    private function saddRedis($key, $value) {
        if(is_array($value)){
            return self::$redis_object->sadd($key, ...$value);
        }
        return self::$redis_object->sadd($key, $value);
    }

    /**
     * Get the number of members in a set
     * @param string $key
     * @return int
     */
    private function scardRedis($key) {
        return self::$redis_object->scard($key);
    }

    /**
     * Returns the members of the set resulting from the difference between the first set and all the successive
    sets.
     * For example:
     * key1 = {a,b,c,d}
     * key2 = {c}
     * key3 = {a,c,e}
     * SDIFF key1 key2 key3 = {b,d}
     * Keys that do not exist are considered to be empty sets.
     *
     * Parameters: key1, key2, key3...
     * @param string|array $key
     * @return array
     */
    private function sdiffRedis($key = array()) {
        if (!is_array($key)) {
            $key = func_get_args();
        }
        if (self::$redis_object && count($key) > 0) {
            return self::$redis_object->sdiff($key);
        } else {
            return false;
        }
    }

    /**
     * Returns the members of the set resulting from the intersection of all the given sets.
     * For example:
     * key1 = {a,b,c,d}
     * key2 = {c}
     * key3 = {a,c,e}
     * SINTER key1 key2 key3 = {c}
     * Parameters: key [key ...]
     * or: array(key, key, ...)
     * @param string|array $key
     * @return array
     */
    private function sinterRedis($key = '') {
        if (!is_array($key)) {
            $key = func_get_args();
        }
        if (self::$redis_object && count($key) > 1) {
            return self::$redis_object->sinter($key);
        } else {
            return false;
        }
    }

    /**
     *  确定一个给定的值是否是一个集合的成员
     * Returns if value is a member of the set.
     * @param string $key
     * @param string $value
     * @return boolean
     */
    private function sismemberRedis($key, $value) {
        return self::$redis_object->sismember($key, $value);
    }

    /**
     * 获取集合里面的所有成员.
     * @param string $key
     * @return array
     */
    private function smembersRedis($key) {
        return self::$redis_object->smembers($key);
    }

    /**
     * Move member from the set at source to the set at destination.
     * This operation is atomic.
     * In every given moment the element will appear to be a member of source or destination for other clients.
     * If the source set does not exist or does not contain the specified element, no operation is performed and 0
    is returned.
     * Otherwise, the element is removed from the source set and added to the destination set.
     * When the specified element already exists in the destination set, it is only removed from the source set.
     * @param string $source
     * @param string $destination
     * @param string $member
     * @return int
     */
    private function smoveRedis($source, $destination, $member) {
        return self::$redis_object->smove($source, $destination, $member);
    }

    /**
     * Remove and return a random member from a set
     * @param string $key
     * @return string the removed element
     */
    private function spopRedis($key) {
        return self::$redis_object->spop($key);
    }

    /**
     * Get a random member from a set
     * @param string $key
     * @return string
     */
    private function srandmemberRedis($key,$number) {
        return self::$redis_object->srandmember($key,$number);
    }

    /**
     * Remove member from the set. If 'value' is not a member of this set, no operation is performed.
     * An error is returned when the value stored at key is not a set.
     * @param string $key
     * @param string $value
     * @return boolean
     */
    private function sremRedis($key, $value) {
        return self::$redis_object->srem($key, $value);
    }

    /**
     * Returns the members of the set resulting from the union of all the given sets.
     * For example:
     * key1 = {a,b,c,d}
     * key2 = {c}
     * key3 = {a,c,e}
     * SUNION key1 key2 key3 = {a,b,c,d,e}
     * Parameters: key [key...]
     * @param string|array $key
     * @return array
     */
    private function sunionRedis($key) {
        if (!is_array($key)) {
            $key = func_get_args();
        }
        return self::$redis_object->sunion($key);
    }

    /**
     * Add a member to a sorted set, or update its score if it already exists
     * @param string $key
     * @param int $score
     * @param string $member
     * @return int
     */
    private function zaddRedis($key, $score, $member) {
        $args = func_get_args();
        if (count($args) > 3) {
            return call_user_func_array(array(self::$redis_object, 'zadd'), $args);
        }

        return self::$redis_object->zadd($key, $score, $member);
    }

    /**
     * Get the number of members in a sorted set
     * @param string $key
     * @return int
     */
    private function zcardRedis($key) {
        return self::$redis_object->zcard($key);
    }

    /**
     * Returns the number of elements in the sorted set at key with a score between min and max.
     * The min and max arguments have the same semantic as described for ZRANGEBYSCORE.
     * @param string $key
     * @param string|int $min
     * @param string|int $max
     * @return int
     */
    private function zcountRedis($key, $min, $max) {
        return self::$redis_object->zcount($key, $min, $max);
    }

    /**
     * Increment the score of a member in a sorted set
     * @param string $key
     * @param number $increment
     * @param string $member
     * @return number
     */
    private function zincrbyRedis($key, $increment, $member) {
        return self::$redis_object->zincrby($key, $increment, $member);
    }

    /**
     * @param string $key
     * @param int $start
     * @param int $stop
     * @param bool $withscores
     * @return array
     */
    private function zrangeRedis($key, $start, $stop, $withscores = false) {
        if ($withscores) {
            return self::$redis_object->zrange($key, $start, $stop, self::WITHSCORES);
        } else {
            return self::$redis_object->zrange($key, $start, $stop);
        }
    }

    /**
     * Return a range of members in a sorted set, by score
     * @link http://redis.io/commands/zrangebyscore
     * @param string $key
     * @param string|number $min
     * @param string|number $max
     * @param array $args| $args=array('withscore'=>,'limit'=>array($offset,$count))
     *
     * @return array
     */
    private function zrangebyscoreRedis($key, $min, $max, array $args = null) {
        if ($args) {
            return self::$redis_object->zrangebyscore($key, $min, $max, $args);
        } else {
            return self::$redis_object->zrangebyscore($key, $min, $max);
        }
    }

    /**
     * Returns the rank of member in the sorted set stored at key, with the scores ordered from low to high.
     * The rank (or index) is 0-based, which means that the member with the lowest score has rank 0.
     * Use ZREVRANK to get the rank of an element with the scores ordered from high to low.
     * @param string $key
     * @param string $member
     * @return int|boolean
     */
    private function zrankRedis($key, $member) {
        return self::$redis_object->zrank($key, $member);
    }

    /**
     * Remove a member from a sorted set
     * @param string $key
     * @param string $member
     * @return int
     */
    private function zremRedis($key, $member) {
        return self::$redis_object->zrem($key, $member);
    }

    /**
     * Removes all elements in the sorted set stored at key with rank between start and stop.
     * Both start and stop are 0-based indexes with 0 being the element with the lowest score.
     * These indexes can be negative numbers, where they indicate offsets starting at the element with the highest
    score.
     * For example: -1 is the element with the highest score, -2 the element with the second highest score and so
    forth.
     * @param string $key
     * @param int $start
     * @param int $stop
     * Returns the number of elements removed.
     * @return int
     */
    private function zremrangebyrankRedis($key, $start, $stop) {
        return self::$redis_object->zremrangebyrank($key, $start, $stop);
    }

    /**
     * Remove all members in a sorted set within the given scores
     * @param string $key
     * @param string|number $min
     * @param string|number $max
     * @return int
     */
    private function zremrangebyscoreRedis($key, $min, $max) {
        return self::$redis_object->zremrangebyscore($key, $min, $max);
    }

    /**
     * Returns the specified range of elements in the sorted set stored at key.
     * The elements are considered to be ordered from the highest to the lowest score.
     * Descending lexicographical order is used for elements with equal score.
     * @param string $key
     * @param int $start
     * @param int $stop
     * @param bool $withscores
     * @return array
     */
    private function zrevrangeRedis($key, $start, $stop, $withscores = false) {
        if ($withscores) {
            return self::$redis_object->zrevrange($key, $start, $stop, self::WITHSCORES);
        } else {
            return self::$redis_object->zrevrange($key, $start, $stop);
        }
    }

    /**
     * Returns all the elements in the sorted set at key with a score between max and min
     * (including elements with score equal to max or min).
     * In contrary to the default ordering of sorted sets, for this command
     * the elements are considered to be ordered from high to low scores.
     * The elements having the same score are returned in reverse lexicographical order.
     * @param string $key
     * @param number $max
     * @param number $min
     * @param array $args| $args=array('withscore'=>,'limit'=>array($offset,$count))
     * @return array
     */
    private function zrevrangebyscoreRedis($key, $max, $min, array $args = null) {
        if ($args) {
            return self::$redis_object->zrevrangebyscore($key, $max, $min, $args);
        } else {
            return self::$redis_object->zrevrangebyscore($key, $max, $min);
        }
    }

    /**
     * Returns the rank of member in the sorted set stored at key, with the scores ordered from high to low.
     * The rank (or index) is 0-based, which means that the member with the highest score has rank 0.
     * Use ZRANK to get the rank of an element with the scores ordered from low to high.
     * @param string $key
     * @param string $member
     * @return int|boolean
     */
    private function zrevrankRedis($key, $member) {
        return self::$redis_object->zrevrank($key, $member);
    }

    /**
     * Get the score associated with the given member in a sorted set
     * @param string $key
     * @param string $member
     * @return string
     */
    private function zscoreRedis($key, $member) {
        return self::$redis_object->zscore($key, $member);
    }

    /**
     * Flushes all previously queued commands in a transaction and restores the connection state to normal.
     * If WATCH was used, DISCARD unwatches all keys.
     */
    private function discardRedis() {
        return self::$redis_object->discard();
    }

    /**
     * Executes all previously queued commands in a transaction and restores the connection state to normal.
     * When using WATCH, EXEC will execute commands only if the watched keys were not modified, allowing for a
    check-and-set mechanism.
     */
    private function execRedis() {
        return self::$redis_object->exec();
    }

    /** Mark the start of a transaction block */
    private function multiRedis() {
        return self::$redis_object->multi();
    }

    /** Forget about all watched keys */
    private function unwatchRedis() {
        return self::$redis_object->unwatch();
    }

    /**
     * Marks the given keys to be watched for conditional execution of a transaction
     * each argument is a key:
     * watch('key1', 'key2', 'key3', ...)
     */
    private function watchRedis() {
        return self::$redis_object->watch($args);
    }

    /** Close the connection */
    private function quitRedis() {
        self::$redis_object->close();
    }

    /** Ping the server */
    private function pingRedis() {
        return self::$redis_object->ping();
    }

    private function bgrewriteaofRedis() {
        return self::$redis_object->bgrewriteaof();
    }

    /** Asynchronously save the dataset to disk */
    private function bgsaveRedis() {
        return self::$redis_object->bgsave();
    }

    /**
     * Resets the statistics reported by Redis using the INFO command.
     * These are the counters that are reset:
     * Keyspace hits
     * Keyspace misses
     * Number of commands processed
     * Number of connections received
     * Number of expired keys
     */
    private function configResetstatRedis() {
        return self::$redis_object->config_resetstat();
    }

    /**
     * Return the number of keys in the selected database
     * @return int
     */
    private function dbsizeRedis() {
        return self::$redis_object->dbsize();
    }

    /** Get information and statistics about the server */
    private function infoRedis() {
        return self::$redis_object->info();
    }

    /** Remove all keys from the current database */
    private function flushdbRedis() {
        return self::$redis_object->flushdb();
    }

    /** Remove all keys from all databases */
    private function flushallRedis() {
        return self::$redis_object->flushall();
    }

    /** Get debugging information about a key */
    private function debugSegfaultRedis() {
        return self::$redis_object->debug_segfault();
    }

    /**
     * Get the UNIX time stamp of the last successful save to disk Ping the server
     * @return int
     */
    private function lastsaveRedis() {
        return self::$redis_object->lastsave();
    }

    /**
     * Listen for all requests received by the server in real time
     * @return maxed
     */
    private function monitorRedis() {
        return self::$redis_object->monitor();
    }

    /** Synchronously save the dataset to disk
     * @return maxed
     */
    private function saveRedis() {
        return self::$redis_object->save();
    }

    /**
     * Synchronously save the dataset to disk and then shut down the server
     * @return maxed
     */
    private function shutdownRedis() {
        return self::$redis_object->shutdown();
    }

    /**
     * Internal command used for replication
     * @return maxed
     */
    private function syncRedis() {
        return self::$redis_object->sync();
    }

    private function evalRedis($key, $script, $args = array(), $numKeys = 0) {
        array_unshift($args, $key);
        return self::$redis_object->eval($script, $args, $numKeys);
    }

    private function evalShaRedis($key, $scriptSha, $args = array(), $numKeys = 0) {
        array_unshift($args, $key);
        return self::$redis_object->evalSha($scriptSha, $args, $numKeys);
    }

    private function scriptRedis($key, $command, $script) {
        return self::$redis_object->script($command, $script);
    }

    /**
     *  有序并集
     * @param $args
     * @return mixed
     */
    private function zUnionRedis($args) {
        if (!is_array($args)) {
            $args = func_get_args();
        }
        return self::$redis_object->zUnion($args[0], $args[1], null, "MAX");
    }

    /**
     * 有序交集
     * @param $args
     * @return mixed
     */
    private function zInterRedis($args) {
        if (!is_array($args)) {
            $args = func_get_args();
        }
        return self::$redis_object->zInter($args[0], $args[1], null, "MAX");
    }

    private function hscanRedis($key, $iterator, $pattern = "", $count = 0) {
        return self::$redis_object->hScan($key, $iterator, $pattern, $count);
    }

    private function publishRedis($channel, $message) {
        return self::$redis_object->publish($channel, $message);
    }
    private function psubscribeRedis($patterns, $callback) {
        var_dump($patterns, $callback);exit;
        return self::$redis_object->psubscribe($patterns, $callback);
    }

}
?>

