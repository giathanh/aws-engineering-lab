<?php
return ['default'=>env('CACHE_STORE','file'),'prefix'=>'orders_','stores'=>['array'=>['driver'=>'array'],'file'=>['driver'=>'file','path'=>storage_path('framework/cache/data')],'redis'=>['driver'=>'redis','connection'=>'default']]];
