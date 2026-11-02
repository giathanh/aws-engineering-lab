<?php
namespace App\Services;
use Illuminate\Support\Facades\{Cache,DB};
class ResilienceProbe {
 public static function run(string $mode,bool $resilient):array {
   $attempts=0;$start=microtime(true);
   if($resilient && Cache::get('probe:open'))return ['status'=>503,'reason'=>'circuit-open','attempts'=>0];
   $lock=$resilient?Cache::lock('probe:bulkhead',10):null;
   if($lock && !$lock->get())return ['status'=>429,'reason'=>'backpressure','attempts'=>0];
   try {
     for($i=0;$i<($resilient?2:1);$i++){
       $attempts++;
       try {
         Telemetry::run('dependency.sql',function() use($mode,$resilient){DB::transaction(function() use($mode,$resilient){
           DB::statement("SET LOCAL statement_timeout = '".($resilient?'200ms':'2000ms')."'");
           if($mode==='slow')DB::select('select pg_sleep(1)');else DB::select('select 1');
         });});
         Cache::forget('probe:failures');return ['status'=>200,'attempts'=>$attempts,'ms'=>round((microtime(true)-$start)*1000)];
       }catch(\Illuminate\Database\QueryException $e){if($resilient && $i===0)usleep(random_int(10000,50000));}
     }
     if($resilient){Cache::add('probe:failures',0,30);if(Cache::increment('probe:failures')>=3)Cache::put('probe:open',true,15);}
     return ['status'=>503,'reason'=>'dependency-timeout','attempts'=>$attempts,'ms'=>round((microtime(true)-$start)*1000)];
   }finally{$lock?->release();}
 }
}
