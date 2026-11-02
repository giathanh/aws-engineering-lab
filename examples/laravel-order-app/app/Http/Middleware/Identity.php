<?php
namespace App\Http\Middleware;
use Firebase\JWT\JWT;
use Firebase\JWT\JWK;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
class Identity {
 public function handle($request, \Closure $next) {
   if(config('lab.auth_mode')==='local' && app()->environment(['local','testing'])) {
     $sub=$request->header('X-Lab-User','alice');
     abort_unless(in_array($sub,['alice','bob'],true),401);
   } else {
     try {
       $issuer=config('lab.issuer');
       if (!is_string($issuer) || !preg_match('~^https://cognito-idp\.[a-z0-9-]+\.amazonaws\.com/[A-Za-z0-9_-]+$~D',$issuer)) throw new \RuntimeException('issuer');
       $token=$request->bearerToken(); if(!$token) throw new \RuntimeException('token');
       $keys=Cache::remember('jwks:'.hash('sha256',$issuer),300,fn()=>Http::timeout(3)->get($issuer.'/.well-known/jwks.json')->throw()->json());
       $claims=JWT::decode($token,JWK::parseKeySet($keys,'RS256'));
       if(($claims->iss??'')!==$issuer || ($claims->token_use??'')!=='access' || ($claims->client_id??'')!==config('lab.client_id') || !isset($claims->exp,$claims->sub) || $claims->exp<=time() || !in_array(config('lab.scope'),explode(' ',$claims->scope??''),true)) throw new \RuntimeException('claims');
       $sub=$claims->sub;
     } catch (\Throwable $e) { abort(401,'Invalid access token'); }
   }
   $request->attributes->set('owner',$sub); return $next($request);
 }
}
