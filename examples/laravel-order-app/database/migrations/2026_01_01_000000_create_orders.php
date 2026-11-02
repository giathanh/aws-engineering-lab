<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
   Schema::create('orders',function(Blueprint $t){$t->uuid('id')->primary();$t->string('owner');$t->string('product');$t->unsignedInteger('quantity');$t->timestamps();$t->index('owner');});
   Schema::create('reports',function(Blueprint $t){$t->uuid('id')->primary();$t->string('owner');$t->string('idempotency_key');$t->text('snapshot');$t->string('status')->default('pending');$t->string('object_key')->nullable();$t->timestamps();$t->unique(['owner','idempotency_key']);});
   Schema::create('outbox',function(Blueprint $t){$t->uuid('report_id')->primary();$t->text('carrier');$t->timestamps();});
   Schema::create('uploads',function(Blueprint $t){$t->uuid('id')->primary();$t->string('owner');$t->string('object_key');$t->string('content_type');$t->unsignedInteger('bytes');$t->boolean('verified')->default(false);$t->timestamps();});
   Schema::create('jobs',function(Blueprint $t){$t->bigIncrements('id');$t->string('queue')->index();$t->longText('payload');$t->unsignedTinyInteger('attempts');$t->unsignedInteger('reserved_at')->nullable();$t->unsignedInteger('available_at');$t->unsignedInteger('created_at');});
   Schema::create('failed_jobs',function(Blueprint $t){$t->id();$t->string('uuid')->unique();$t->text('connection');$t->text('queue');$t->longText('payload');$t->longText('exception');$t->timestamp('failed_at')->useCurrent();});
 }
 public function down(): void {foreach(['failed_jobs','jobs','uploads','outbox','reports','orders'] as $t) Schema::dropIfExists($t);}
};
