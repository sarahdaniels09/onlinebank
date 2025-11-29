<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class VerifyEnvatoLicense
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        $statusPath = storage_path('app/license_status.json');

        // If status file exists and was checked within last 7 days, skip
        if(file_exists($statusPath)){
            $s = json_decode(file_get_contents($statusPath), true);
            if(is_array($s) && !empty($s['checked_at'])){
                $checked = strtotime($s['checked_at']);
                if($checked !== false && (time() - $checked) < 60*60*24*7){
                    return $next($request);
                }
            }
        }

        $purchase = env('ENVATO_PURCHASE_CODE');
        $server = env('LICENSE_SERVER_URL');
        $result = ['checked_at' => date('c')];

        if(!$purchase || !$server){
            $result['status'] = 'skipped';
            $result['message'] = 'ENVATO_PURCHASE_CODE or LICENSE_SERVER_URL not set';
            file_put_contents($statusPath, json_encode($result, JSON_PRETTY_PRINT));
            return $next($request);
        }

        // try contacting verification server
        try{
            // use simple cURL
            if(!function_exists('curl_init')){
                $result['status'] = 'error';
                $result['message'] = 'cURL not available';
                file_put_contents($statusPath, json_encode($result, JSON_PRETTY_PRINT));
                return $next($request);
            }
            $payload = json_encode(['purchase_code' => $purchase]);
            $ch = curl_init($server);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json','Accept: application/json']);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $res = curl_exec($ch);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if($res === false){
                $result['status'] = 'error';
                $result['message'] = 'cURL error: ' . $err;
            }else{
                $data = json_decode($res, true);
                if($http === 200 && is_array($data) && !empty($data['valid'])){
                    $result['status'] = 'valid';
                    $result['data'] = $data;
                }else{
                    $result['status'] = 'invalid';
                    $result['message'] = is_array($data) && isset($data['message']) ? $data['message'] : 'invalid response';
                    $result['raw'] = $data;
                }
            }
        }catch(\Exception $e){
            $result['status'] = 'error';
            $result['message'] = $e->getMessage();
        }

        // persist status
        try{ file_put_contents($statusPath, json_encode($result, JSON_PRETTY_PRINT)); }catch(\Exception $e){ Log::warning('Could not write license status: ' . $e->getMessage()); }

        return $next($request);
    }
}
