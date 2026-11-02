<?php
return ['default'=>env('FILESYSTEM_DISK','local'),'disks'=>[
'local'=>['driver'=>'local','root'=>storage_path('app/private'),'throw'=>true],
's3'=>['driver'=>'s3','region'=>env('AWS_DEFAULT_REGION'),'bucket'=>env('AWS_BUCKET'),'throw'=>true]]];
