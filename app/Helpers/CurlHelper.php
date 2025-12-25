<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Http;

class CurlHelper
{
    /**
     * Execute cURL request.
     *
     * @param array $options
     * @return string
     */
    public function curl(array $options): string
    {
        $url = $options['url'] ?? '';
        
        if (empty($url)) {
            return '';
        }

        try {
            $response = Http::timeout(30)->get($url);
            
            if ($response->successful()) {
                return $response->body();
            }
            
            return '';
        } catch (\Exception $e) {
            \Log::error('CurlHelper Error', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            
            return '';
        }
    }
}


