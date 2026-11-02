<?php
return ['default'=>env('QUEUE_CONNECTION','database'),'connections'=>[
'sync'=>['driver'=>'sync'],
'database'=>['driver'=>'database','table'=>'jobs','queue'=>'default','retry_after'=>180,'after_commit'=>true],
'sqs'=>['driver'=>'sqs','prefix'=>env('SQS_PREFIX'),'queue'=>env('SQS_QUEUE'),'region'=>env('AWS_DEFAULT_REGION'),'after_commit'=>true]],
'failed'=>['driver'=>'database-uuids','database'=>env('DB_CONNECTION','sqlite'),'table'=>'failed_jobs']];
