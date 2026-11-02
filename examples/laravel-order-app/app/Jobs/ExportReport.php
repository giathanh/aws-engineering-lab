<?php
namespace App\Jobs;
use App\Services\Telemetry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
class ExportReport implements ShouldQueue {
 use Queueable;
 public int $timeout=60;
 // SQS redrive owns the poison-message limit. With a DB queue use --tries=3 locally.
 public function backoff(): array {return [10,30,60];}
 public function __construct(public string $reportId,public array $carrier=[]) {}
 public function handle(): void {
   Telemetry::run('report.export',function(){
     DB::transaction(function(){
       $r=DB::table('reports')->where('id',$this->reportId)->lockForUpdate()->first();
       if(!$r || $r->status==='complete') return;
       $key='reports/'.$r->id.'.json';
       Telemetry::run('storage.put',fn()=>Storage::disk(config('lab.disk'))->put($key,$r->snapshot));
       // If the process dies here, a retry writes identical bytes to the same key.
       DB::table('reports')->where('id',$r->id)->update(['status'=>'complete','object_key'=>$key,'updated_at'=>now()]);
     });
   },$this->carrier);
 }
}
