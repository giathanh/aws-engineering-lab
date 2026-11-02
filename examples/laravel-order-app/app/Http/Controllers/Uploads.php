<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Aws\S3\S3Client;
class Uploads {
 private function s3(): S3Client {return new S3Client(['version'=>'latest','region'=>config('lab.region')]);}
 public function sign(Request $r) {
   abort_unless(config('lab.disk')==='s3',409,'Configure S3 for direct uploads');
   $data=$r->validate(['content_type'=>'required|in:image/png,image/jpeg','bytes'=>'required|integer|min:1|max:5242880']);
   $id=(string)Str::uuid();$key='uploads/'.$id;
   DB::table('uploads')->insert(['id'=>$id,'owner'=>$r->attributes->get('owner'),'object_key'=>$key]+$data+['created_at'=>now(),'updated_at'=>now()]);
   $c=$this->s3()->getCommand('PutObject',['Bucket'=>config('filesystems.disks.s3.bucket'),'Key'=>$key,'ContentType'=>$data['content_type']]);
   return ['id'=>$id,'url'=>(string)$this->s3()->createPresignedRequest($c,'+2 minutes')->getUri(),'headers'=>['Content-Type'=>$data['content_type']]];
 }
 public function verify(Request $r,string $id) {
   $row=DB::table('uploads')->where('id',$id)->where('owner',$r->attributes->get('owner'))->first();abort_unless($row,404);
   $s3=$this->s3();$args=['Bucket'=>config('filesystems.disks.s3.bucket'),'Key'=>$row->object_key];
   $h=$s3->headObject($args);
   abort_unless($h['ContentLength']===$row->bytes && $h['ContentLength']<=5242880 && $h['ContentType']===$row->content_type,422,'Size/type mismatch; keep quarantined');
   $o=$s3->getObject($args+['IfMatch'=>$h['ETag']]);$bytes=(string)$o['Body'];
   $info=@getimagesizefromstring($bytes);
   abort_unless($info && $info['mime']===$row->content_type && $info[0]*$info[1]<=20000000,422,'Invalid image');
   // Original remains quarantined; only the Lambda's version-specific processed output is publishable.
   DB::table('uploads')->where('id',$id)->update(['verified'=>true,'updated_at'=>now()]);
   return ['verified'=>true,'width'=>$info[0],'height'=>$info[1]];
 }
}
