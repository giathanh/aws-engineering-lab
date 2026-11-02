<?php
use Illuminate\Support\Facades\{Artisan,DB};
use App\Services\Reports;
Artisan::command('reports:dispatch',function(){Reports::dispatch();$this->info('Outbox dispatched');});
Artisan::command('reports:tick {slot}',function(){
 $slot=$this->argument('slot');if(!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D',$slot))throw new RuntimeException('Expected UTC scheduled-time');
 foreach(DB::table('orders')->distinct()->pluck('owner') as $owner) Reports::create($owner,'scheduled-'.str_replace([':', '-'],'',$slot));
 Reports::dispatch();
});
