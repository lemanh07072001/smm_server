<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Redis;

class RedisHelper
{
  const REDIS_WEB = 'web_redis';
  const REDIS_ORDER_WEB = 'order_web_redis';
  const REDIS_CODE_TRANSACTIONS = 'code_transactions_redis';
  const REDIS_ACTIVITY_LOGS = 'activity_logs_redis';

  public static function getAllKey()
  {
    return Redis::connection()->command('KEYS', ['*']);
  }

  public static function lpush($key, $value)
  {
    $value = (array)$value;
    return Redis::lpush($key, ...$value);
  }
  public static function lpush_one($key, $value)
  {
    return Redis::lpush($key, $value);
  }
  public static function rpop($key)
  {
    return Redis::rpop($key);
  }
  public static function set($key, $value)
  {
    return Redis::set($key, $value);
  }

  public static function get($key, $connection = null)
  {
    $redis = $connection ? Redis::connection($connection) : Redis::connection();
    return $redis->get($key);
  }

  public static function del($key, $connection = null)
  {
    $redis = $connection ? Redis::connection($connection) : Redis::connection();
    return $redis->del($key);
  }

  public static function hset($key, $field, $value)
  {
    return Redis::hset($key, $field, $value);
  }
  public static function HINCRBY($key, $field, $increment)
  {
    return Redis::hincrby($key, $field, $increment);
  }
  public static function hget($key, $field)
  {
    return Redis::hget($key, $field);
  }
  public static function hdel($key, $field)
  {
    return Redis::hdel($key, $field);
  }
  public static function hgetall($key)
  {
    return Redis::hgetall($key);
  }
  public static function hlen($key)
  {
    return Redis::hlen($key);
  }
  public static function hexists($key, $field)
  {
    return Redis::hexists($key, $field);
  }
  public static function exists($key)
  {
    return Redis::exists($key);
  }

  public static function flushall()
  {
    return Redis::flushall();
  }

  public static function incr($key)
  {
    return Redis::incr($key);
  }
  public static function decr($key)
  {
    return Redis::decr($key);
  }

  public static function lrange($key, $start, $stop)
  {
    return Redis::lrange($key, $start, $stop);
  }
  public static function llen($key)
  {
    return Redis::llen($key);
  }

  /**
   * Lấy tất cả dữ liệu từ Redis
   * 
   * @param string|null $connection Tên connection (null = default, 'all' = tất cả connections)
   * @return array Dữ liệu Redis
   */
  public static function getAllData($connection = null)
  {
    $result = [];

    // Nếu không chỉ định connection, dùng default
    if ($connection === null) {
      $connections = ['default'];
    } 
    // Nếu là 'all', lấy tất cả connections
    elseif ($connection === 'all') {
      $connections = ['default', self::REDIS_WEB, self::REDIS_ORDER_WEB, self::REDIS_CODE_TRANSACTIONS];
    } 
    // Nếu chỉ định connection cụ thể
    else {
      $connections = [$connection];
    }

    foreach ($connections as $connName) {
      try {
        $redis = Redis::connection($connName);
        $keys = $redis->keys('*');
        
        $data = [];
        foreach ($keys as $key) {
          $type = $redis->type($key);
          
          switch ($type) {
            case 1: // String
              $data[$key] = [
                'type' => 'string',
                'value' => $redis->get($key),
                'ttl' => $redis->ttl($key)
              ];
              break;
              
            case 2: // Set
              $data[$key] = [
                'type' => 'set',
                'value' => $redis->smembers($key),
                'count' => $redis->scard($key),
                'ttl' => $redis->ttl($key)
              ];
              break;
              
            case 3: // List
              $length = $redis->llen($key);
              $data[$key] = [
                'type' => 'list',
                'value' => $redis->lrange($key, 0, -1),
                'count' => $length,
                'ttl' => $redis->ttl($key)
              ];
              break;
              
            case 4: // ZSet (Sorted Set)
              $data[$key] = [
                'type' => 'zset',
                'value' => $redis->zrange($key, 0, -1, 'WITHSCORES'),
                'count' => $redis->zcard($key),
                'ttl' => $redis->ttl($key)
              ];
              break;
              
            case 5: // Hash
              $data[$key] = [
                'type' => 'hash',
                'value' => $redis->hgetall($key),
                'count' => $redis->hlen($key),
                'ttl' => $redis->ttl($key)
              ];
              break;
              
            default:
              $data[$key] = [
                'type' => 'unknown',
                'value' => null,
                'ttl' => $redis->ttl($key)
              ];
          }
        }
        
        $result[$connName] = [
          'connection' => $connName,
          'total_keys' => count($keys),
          'data' => $data
        ];
        
      } catch (\Exception $e) {
        $result[$connName] = [
          'connection' => $connName,
          'error' => $e->getMessage()
        ];
      }
    }

    return $result;
  }

  /**
   * Lấy tất cả keys từ một connection cụ thể
   * 
   * @param string|null $connection Tên connection (null = default)
   * @return array Danh sách keys
   */
  public static function getAllKeys($connection = null)
  {
    $redis = $connection ? Redis::connection($connection) : Redis::connection();
    return $redis->keys('*');
  }

  /**
   * Lấy tất cả transaction_code từ code_transactions_redis
   * 
   * @param bool $withDetails Nếu true, trả về thông tin chi tiết (id, created_at, etc.)
   * @return array Danh sách transaction_code hoặc array chi tiết
   */
  public static function getAllTransactionCodes($withDetails = false)
  {
    $redis = Redis::connection(self::REDIS_CODE_TRANSACTIONS);
    $keys = $redis->keys('*');
    
    $transactionCodes = [];
    $details = [];
    
    foreach ($keys as $key) {
      try {
        $value = $redis->get($key);
        if ($value) {
          $data = json_decode($value, true);
          
          if ($data && isset($data['transaction_code'])) {
            if ($withDetails) {
              $details[] = [
                'key' => $key,
                'id' => $data['id'] ?? null,
                'transaction_code' => $data['transaction_code'],
                'created_at' => $data['created_at'] ?? null,
                'updated_at' => $data['updated_at'] ?? null,
              ];
            } else {
              $transactionCodes[] = $data['transaction_code'];
            }
          }
        }
      } catch (\Exception $e) {
        // Bỏ qua key lỗi, tiếp tục với key tiếp theo
        continue;
      }
    }
    
    return $withDetails ? $details : $transactionCodes;
  }
}
