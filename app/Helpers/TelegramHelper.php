<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramHelper
{
  public static function sendNotifySystem($message, $title = 'Thông báo', $proxy = null)
  {
    if (!$proxy) {
      $proxy = null;
    }
    // $botToken = '6583965991:AAEn_8XNyiCtPT8dWz4dqe0oYXaspjbkbQ0';
    // $chatId = '865710636';

    $botToken = '8403685523:AAEZfK3un9rtlW82634D5CagNRxWrx2-p5I';
    $chatId = '7675836140';

    $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
    $data = [
      'chat_id' => $chatId,
      'text' => (strlen($title) > 0 ? ($title . ': ') : '') . $message,
    ];

    $options = [
      'http' => [
        'method' => 'POST',
        'header' => 'Content-Type: application/x-www-form-urlencoded',
        'content' => http_build_query($data),
      ],
    ];

    if ($proxy) {
      $proxyUrl = "tcp://{$proxy['host']}:{$proxy['port']}";
      $options['http']['proxy'] = $proxyUrl;
      $options['http']['request_fulluri'] = true;

      if (!empty($proxy['user']) && !empty($proxy['password'])) {
        $auth = base64_encode("{$proxy['user']}:{$proxy['password']}");
        $options['http']['header'] .= "\r\nProxy-Authorization: Basic $auth";
      }
    }

    $context = stream_context_create($options);
    $result = file_get_contents($url, false, $context);

    return $result;
  }

  public static function sendNotifyNapTienSystem($message, $title = 'Thông báo', $proxy = null)
  {
    if (!$proxy) {
      $proxy = null;
    }
    // $botToken = '6583965991:AAEn_8XNyiCtPT8dWz4dqe0oYXaspjbkbQ0';
    // $chatId = '865710636';

    $botToken = '8463664669:AAEOGSUTpr8KU_bEPtzP2OYu_9LYFYLx0EU';
    $chatId = '7675836140';

    $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
    $data = [
      'chat_id' => $chatId,
      'text' => (strlen($title) > 0 ? ($title . ': ') : '') . $message,
    ];

    $options = [
      'http' => [
        'method' => 'POST',
        'header' => 'Content-Type: application/x-www-form-urlencoded',
        'content' => http_build_query($data),
      ],
    ];

    if ($proxy) {
      $proxyUrl = "tcp://{$proxy['host']}:{$proxy['port']}";
      $options['http']['proxy'] = $proxyUrl;
      $options['http']['request_fulluri'] = true;

      if (!empty($proxy['user']) && !empty($proxy['password'])) {
        $auth = base64_encode("{$proxy['user']}:{$proxy['password']}");
        $options['http']['header'] .= "\r\nProxy-Authorization: Basic $auth";
      }
    }

    $context = stream_context_create($options);
    $result = file_get_contents($url, false, $context);

    return $result;
  }

  public static function sendNotifyErrorSystem($message, $title = 'Thông báo', $proxy = null)
  {
    $botToken = '8580146778:AAH3kK1gyEb4xz2GCysIxe3j2lg3YaRpKjI';
    $chatId = '7675836140';

    $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
    $text = (strlen($title) > 0 ? ($title . ': ') : '') . $message;

    try {
      $response = Http::timeout(10)->post($url, [
        'chat_id' => $chatId,
        'text' => $text,
      ]);

      if (!$response->successful()) {
        Log::error('Telegram sendNotifyErrorSystem failed', [
          'status' => $response->status(),
          'body' => $response->body(),
        ]);
      }

      return $response->body();
    } catch (\Exception $e) {
      Log::error('Telegram sendNotifyErrorSystem error', [
        'error' => $e->getMessage(),
      ]);
      return false;
    }
  }
}
