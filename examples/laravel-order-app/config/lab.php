<?php
return ['auth_mode'=>env('AUTH_MODE','cognito'),'issuer'=>env('COGNITO_ISSUER'),'client_id'=>env('COGNITO_CLIENT_ID'),'scope'=>env('COGNITO_SCOPE','orders/api'),'cognito_domain'=>env('COGNITO_DOMAIN'),'region'=>env('AWS_DEFAULT_REGION','ap-southeast-1'),'faults'=>(bool)env('LAB_FAULTS',false),'disk'=>env('FILESYSTEM_DISK','local'),'version'=>env('APP_VERSION','local')];
