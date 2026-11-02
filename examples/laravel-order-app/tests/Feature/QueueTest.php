<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{Storage,Artisan};
class QueueTest extends TestCase {
 use DatabaseMigrations;
 public function test_database_worker_completes_enqueued_report():void {
   config(['queue.default'=>'database']);Storage::fake('local');
   $this->postJson('/api/orders',['product'=>'Async fixture','quantity'=>1])->assertCreated();
   $id=$this->postJson('/api/reports',['key'=>'async-1'])->assertAccepted()->json('id');
   $this->assertDatabaseHas('reports',['id'=>$id,'status'=>'pending']);
   $this->assertDatabaseCount('jobs',1);$this->assertDatabaseCount('outbox',0);
   Artisan::call('queue:work',['connection'=>'database','--once'=>true,'--tries'=>3]);
   $this->assertDatabaseHas('reports',['id'=>$id,'status'=>'complete']);
   $this->assertDatabaseCount('jobs',0);
   $this->getJson('/api/reports/'.$id.'/download')->assertOk()->assertJsonFragment(['product'=>'Async fixture']);
 }
}
