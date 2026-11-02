<?php
use Illuminate\Support\Facades\{Route,DB};
use App\Http\Controllers\{Orders,Uploads};
Route::get('/config',fn()=>['mode'=>config('lab.auth_mode'),'issuer'=>config('lab.issuer'),'clientId'=>config('lab.client_id'),'scope'=>config('lab.scope'),'domain'=>config('lab.cognito_domain')]);
Route::get('/ready',function(){DB::select('select 1');return ['ready'=>true,'version'=>config('lab.version')];});
Route::middleware('identity')->group(function(){
 Route::get('/probe',function(Illuminate\Http\Request $r){abort_unless(config('lab.faults') && config('database.default')==='pgsql',404);$result=App\Services\ResilienceProbe::run($r->query('mode','ok'),$r->boolean('resilient'));return response()->json($result,$result['status'])->header('Retry-After','15');});
 Route::get('/orders',[Orders::class,'index']);Route::post('/orders',[Orders::class,'store']);
 Route::get('/orders/{id}',[Orders::class,'show']);Route::patch('/orders/{id}',[Orders::class,'update']);
 Route::post('/reports',[Orders::class,'report']);Route::get('/reports/{id}',[Orders::class,'reportStatus']);Route::get('/reports/{id}/download',[Orders::class,'download']);
 Route::post('/uploads',[Uploads::class,'sign']);Route::post('/uploads/{id}/verify',[Uploads::class,'verify']);
 Route::get('/session',function(Illuminate\Http\Request $r){$r->session()->put('visits',$r->session()->get('visits',0)+1);return ['visits'=>$r->session()->get('visits'),'instance'=>gethostname()];})->middleware([Illuminate\Cookie\Middleware\EncryptCookies::class,Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,Illuminate\Session\Middleware\StartSession::class]);
 Route::get('/fault/{kind}',function(string $kind){abort_unless(config('lab.faults'),404);if($kind==='500') abort(500,'Lab fault');if($kind==='slow'){abort_unless(config('database.default')==='pgsql',409);DB::select('select pg_sleep(2)');}return ['fault'=>$kind];});
});
