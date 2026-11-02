<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
class DatabaseSeeder extends Seeder {
 public function run(): void { DB::table('orders')->updateOrInsert(['id'=>'00000000-0000-4000-8000-000000000001'],['owner'=>'alice','product'=>'Lab notebook','quantity'=>1,'created_at'=>now(),'updated_at'=>now()]); }
}
