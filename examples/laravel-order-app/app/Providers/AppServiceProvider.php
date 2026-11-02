<?php
namespace App\Providers;
use Illuminate\Support\ServiceProvider;
class AppServiceProvider extends ServiceProvider {
 public function register(): void {}
 public function boot(): void {
   if (config('lab.auth_mode') === 'local' && !app()->environment(['local', 'testing'])) {
     throw new \RuntimeException('Local authentication is forbidden outside local/testing');
   }
 }
}
