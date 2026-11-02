<?php
namespace App\Services;
use App\Jobs\ExportReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
class Reports {
 public static function create(string $owner,string $key): object {
   return DB::transaction(function() use($owner,$key) {
     $id=(string)Str::uuid();
     // Unique owner/key is the arbiter even if two requests arrive together.
     DB::table('reports')->insertOrIgnore(['id'=>$id,'owner'=>$owner,'idempotency_key'=>$key,'snapshot'=>json_encode(DB::table('orders')->where('owner',$owner)->orderBy('id')->get()),'status'=>'pending','created_at'=>now(),'updated_at'=>now()]);
     $report=DB::table('reports')->where(compact('owner'))->where('idempotency_key',$key)->first();
     if($report->id===$id) DB::table('outbox')->insert(['report_id'=>$id,'carrier'=>json_encode(Telemetry::carrier()),'created_at'=>now(),'updated_at'=>now()]);
     return $report;
   });
 }
 public static function dispatch(): void {
   DB::table('outbox')->orderBy('created_at')->limit(100)->get()->each(function($row){
     ExportReport::dispatch($row->report_id,json_decode($row->carrier,true));
     // Crash between send and delete duplicates a job; ExportReport is idempotent.
     DB::table('outbox')->where('report_id',$row->report_id)->delete();
   });
 }
}
