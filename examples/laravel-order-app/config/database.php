<?php
return ['default'=>env('DB_CONNECTION','sqlite'),'connections'=>[
'sqlite'=>['driver'=>'sqlite','database'=>env('DB_DATABASE',database_path('database.sqlite')),'prefix'=>'','foreign_key_constraints'=>true,'busy_timeout'=>5000],
'pgsql'=>['driver'=>'pgsql','host'=>env('DB_HOST','127.0.0.1'),'port'=>env('DB_PORT',5432),'database'=>env('DB_DATABASE','orders'),'username'=>env('DB_USERNAME','orders'),'password'=>env('DB_PASSWORD'),'charset'=>'utf8','prefix'=>'','search_path'=>'public','sslmode'=>env('DB_SSLMODE','require')]],
'migrations'=>['table'=>'migrations','update_date_on_publish'=>true],
'redis'=>['client'=>'predis','default'=>['scheme'=>env('REDIS_SCHEME','tcp'),'host'=>env('REDIS_HOST','127.0.0.1'),'port'=>env('REDIS_PORT',6379),'password'=>env('REDIS_PASSWORD'),'database'=>0,'timeout'=>2,'read_write_timeout'=>2]]];
