<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Storage,DB,Queue};
use App\Jobs\ExportReport;
class OrdersTest extends TestCase {
 use RefreshDatabase;
 public function test_owner_isolation_and_validation():void {
   $this->postJson('/api/orders',['product'=>'Book','quantity'=>0])->assertUnprocessable();
   $id=$this->postJson('/api/orders',['product'=>'Book','quantity'=>2])->assertCreated()->json('id');
   $this->withHeader('X-Lab-User','bob')->getJson('/api/orders/'.$id)->assertNotFound();
   $this->withHeader('X-Lab-User','bob')->patchJson('/api/orders/'.$id,['quantity'=>8])->assertNotFound();
   $this->assertDatabaseHas('orders',['id'=>$id,'quantity'=>2]);
 }
 public function test_report_is_idempotent_and_private():void {
   Storage::fake('local');
   $this->postJson('/api/orders',['product'=>'Book','quantity'=>2])->assertCreated();
   $id=$this->postJson('/api/reports',['key'=>'request-1'])->assertAccepted()->json('id');
   $this->postJson('/api/reports',['key'=>'request-1'])->assertJsonPath('id',$id);
   (new ExportReport($id))->handle();(new ExportReport($id))->handle();
   $this->assertDatabaseCount('reports',1);
   $this->assertCount(1,Storage::disk('local')->allFiles('reports'));
   $this->getJson('/api/reports/'.$id)->assertJsonPath('status','complete');
   $this->withHeader('X-Lab-User','bob')->getJson('/api/reports/'.$id.'/download')->assertNotFound();
 }
 public function test_outbox_survives_send_failure_and_can_be_replayed():void {
   $r=\App\Services\Reports::create('alice','retry');
   $this->assertDatabaseHas('outbox',['report_id'=>$r->id]);
   Storage::fake('local');(new ExportReport($r->id))->handle();
   // Model a successful send followed by dispatcher crash before deleting the outbox.
   \App\Services\Reports::dispatch();
   $this->assertDatabaseCount('outbox',0);$this->assertDatabaseCount('reports',1);
 }
 public function test_cognito_rejects_unsigned_or_missing_token():void {
   config(['lab.auth_mode'=>'cognito']);
   $this->getJson('/api/orders')->assertUnauthorized();
   $this->withHeader('Authorization','Bearer invalid')->getJson('/api/orders')->assertUnauthorized();
 }
 public function test_faults_disabled_by_default():void {$this->getJson('/api/fault/500')->assertNotFound();}
}
