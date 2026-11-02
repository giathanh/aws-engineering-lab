<?php
namespace Tests\Feature;
use Tests\TestCase;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache,Http};
class CognitoTest extends TestCase {
 use RefreshDatabase;
 private function token(array $overrides=[]):string {
   $key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
   openssl_pkey_export($key,$private);$rsa=openssl_pkey_get_details($key)['rsa'];
   $b64=fn($s)=>rtrim(strtr(base64_encode($s),'+/','-_'),'=');
   Cache::flush();Http::fake(['*'=>Http::response(['keys'=>[['kty'=>'RSA','kid'=>'test','alg'=>'RS256','use'=>'sig','n'=>$b64($rsa['n']),'e'=>$b64($rsa['e'])]]])]);
   config(['lab.auth_mode'=>'cognito','lab.issuer'=>'https://cognito-idp.ap-southeast-1.amazonaws.com/test_pool','lab.client_id'=>'fixture-client','lab.scope'=>'orders/api']);
   return JWT::encode(array_merge(['iss'=>config('lab.issuer'),'sub'=>'alice','client_id'=>'fixture-client','token_use'=>'access','scope'=>'orders/api','exp'=>time()+300,'iat'=>time()-10],$overrides),$private,'RS256','test');
 }
 public function test_signed_access_token_accepted():void {$token=$this->token();$this->withHeader('Authorization','Bearer '.$token)->getJson('/api/orders')->assertOk();}
 public function test_invalid_claims_rejected():void {
   foreach([['iss'=>'https://wrong.example'],['exp'=>time()-60],['client_id'=>'other'],['token_use'=>'id'],['scope'=>'openid']] as $claims){
     $token=$this->token($claims);$this->withHeader('Authorization','Bearer '.$token)->getJson('/api/orders')->assertUnauthorized();
   }
 }
}
