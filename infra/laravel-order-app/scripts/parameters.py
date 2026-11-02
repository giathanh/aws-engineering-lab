"""Build nonsecret parameter files from foundation stack outputs. Run from repo root."""
import argparse,json,os,base64
import boto3
p=argparse.ArgumentParser();p.add_argument('foundation');p.add_argument('--mode',choices=['ecs','ec2'],default='ecs');a=p.parse_args()
cf=boto3.client('cloudformation');o={x['OutputKey']:x['OutputValue'] for x in cf.describe_stacks(StackName=a.foundation)['Stacks'][0]['Outputs']}
def required(k):
    v=os.environ.get(k)
    if not v:raise SystemExit('Missing '+k)
    return v
common={'VpcId':o['VPC'],'PublicSubnets':o['PublicA']+','+o['PublicB'],'PrivateSubnets':o['PrivateA']+','+o['PrivateB'],'AlbSG':o['AlbSG'],'AppSG':o['AppSG'],'CertificateArn':required('CERTIFICATE_ARN')}
if a.mode=='ecs':
    params=common|{k:o[k] for k in ['DBHost','DBSecret','CacheHost','CacheKey','AppKey','Bucket','TaskRole','ExecutionRole','Cluster','Logs','Issuer','CognitoDomain']}
    params.update(QueueURL=o['Queue'],ClientId=o['Client'],AppOrigin=required('APP_ORIGIN'),AppImage=required('APP_IMAGE'),NginxImage=required('NGINX_IMAGE'),TelemetryImage=required('TELEMETRY_IMAGE'),SchedulerDLQ=o['SchedulerDLQArn'],DesiredCount='0',ScheduleState='DISABLED')
else:
    env={'APP_ENV':'production','AUTH_MODE':'cognito','APP_DEBUG':'false','APP_URL':required('APP_ORIGIN'),'DB_CONNECTION':'pgsql','DB_HOST':o['DBHost'],'DB_DATABASE':'orders','DB_USERNAME':'orders','DB_SSLMODE':'require','CACHE_STORE':'redis','SESSION_DRIVER':'redis','SESSION_SECURE_COOKIE':'true','REDIS_SCHEME':'tls','REDIS_HOST':o['CacheHost'],'FILESYSTEM_DISK':'s3','AWS_BUCKET':o['Bucket'],'AWS_DEFAULT_REGION':boto3.session.Session().region_name,'QUEUE_CONNECTION':'sqs','SQS_PREFIX':o['Queue'].rsplit('/',1)[0],'SQS_QUEUE':o['Queue'].rsplit('/',1)[1],'COGNITO_ISSUER':o['Issuer'],'COGNITO_CLIENT_ID':o['Client'],'COGNITO_DOMAIN':o['CognitoDomain'],'COGNITO_SCOPE':'orders/api','OTEL_PHP_AUTOLOAD_ENABLED':'true','OTEL_TRACES_EXPORTER':'none','OTEL_METRICS_EXPORTER':'none','OTEL_LOGS_EXPORTER':'none'}
    cfg={'env':env,'secrets':{'APP_KEY':{'arn':o['AppKey']},'DB_PASSWORD':{'arn':o['DBSecret'],'key':'password'},'REDIS_PASSWORD':{'arn':o['CacheKey']}}}
    params=common|{'AmiId':required('AMI_ID'),'InstanceProfile':o['InstanceProfile'],'ArtifactBucket':o['Artifacts'],'ArtifactKey':required('ARTIFACT_KEY'),'RuntimeConfigBase64':base64.b64encode(json.dumps(cfg).encode()).decode(),'DesiredCount':'1'}
print(json.dumps([{'ParameterKey':k,'ParameterValue':str(v)} for k,v in params.items()],indent=2))
