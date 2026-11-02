<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Cache,Storage,Log};
use Illuminate\Support\Str;
use App\Services\{Reports,Telemetry};
class Orders {
 private function owner(Request $r): string {return $r->attributes->get('owner');}
 public function index(Request $r) {return Telemetry::run('db.orders',fn()=>DB::table('orders')->where('owner',$this->owner($r))->orderBy('id')->get());}
 public function store(Request $r) {
   $data=$r->validate(['product'=>'required|string|max:120','quantity'=>'required|integer|min:1|max:100']);
   $id=(string)Str::uuid();DB::table('orders')->insert($data+['id'=>$id,'owner'=>$this->owner($r),'created_at'=>now(),'updated_at'=>now()]);
   return response()->json(['id'=>$id]+$data,201);
 }
 public function show(Request $r,string $id) {
   // Ownership check always hits DB. Cache failure cannot broaden authorization.
   $row=DB::table('orders')->where('id',$id)->where('owner',$this->owner($r))->first();abort_unless($row,404);
   try {return response()->json(Cache::remember('order:'.$id,30,fn()=>$row));}
   catch(\Throwable $e){Log::warning('cache_unavailable');return response()->json($row);}
 }
 public function update(Request $r,string $id) {
   $data=$r->validate(['quantity'=>'required|integer|min:1|max:100']);
   abort_unless(DB::table('orders')->where('id',$id)->where('owner',$this->owner($r))->update($data+['updated_at'=>now()]),404);
   try{Cache::forget('order:'.$id);}catch(\Throwable $e){Log::warning('cache_invalidation_failed');}
   return response()->json(['updated'=>true]);
 }
 public function report(Request $r) {
   $data=$r->validate(['key'=>'required|string|max:100|regex:/^[A-Za-z0-9_-]+$/']);
   $report=Reports::create($this->owner($r),$data['key']);
   try{Reports::dispatch();}catch(\Throwable $e){Log::warning('outbox_pending');}
   return response()->json(['id'=>$report->id,'status'=>$report->status],202);
 }
 public function reportStatus(Request $r,string $id) {
   $row=DB::table('reports')->where('id',$id)->where('owner',$this->owner($r))->first();abort_unless($row,404);
   return response()->json(['id'=>$row->id,'status'=>$row->status,'download'=>'/api/reports/'.$id.'/download']);
 }
 public function download(Request $r,string $id) {
   $row=DB::table('reports')->where('id',$id)->where('owner',$this->owner($r))->first();abort_unless($row && $row->status==='complete',404);
   return response(Storage::disk(config('lab.disk'))->get($row->object_key),200,['Content-Type'=>'application/json']);
 }
}
