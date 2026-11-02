<?php
namespace App\Http\Middleware;
use App\Services\Telemetry;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
class TraceRequest {
 public function handle($request, \Closure $next) {
   $id=(string)Str::uuid(); $start=microtime(true); $status=500;
   try { return Telemetry::run('HTTP '.$request->method(), function () use ($request,$next,$id,&$status) {
     $response=$next($request); $status=$response->getStatusCode();
     $response->headers->set('X-Request-ID',$id); return $response;
   }, array_filter(['traceparent'=>$request->header('traceparent')])); }
   finally { Log::info('request', ['request_id'=>$id,'status'=>$status,'latency_ms'=>round((microtime(true)-$start)*1000,2),'version'=>config('lab.version')]); }
 }
}
