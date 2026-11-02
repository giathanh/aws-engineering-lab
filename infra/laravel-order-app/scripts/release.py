"""Explicit deploy operation: migration -> CFN update -> business smoke -> rollback on failure.
Run only against the lab stack. It does not create foundation resources.
"""
import json,os,subprocess,sys
from pathlib import Path
import boto3
cf=boto3.client('cloudformation');ecs=boto3.client('ecs');stack=os.environ['RUNTIME_STACK']
s=cf.describe_stacks(StackName=stack)['Stacks'][0]
previous_body=cf.get_template(StackName=stack,TemplateStage='Original')['TemplateBody']
if not isinstance(previous_body,str):previous_body=json.dumps(previous_body)
previous=[{'ParameterKey':p['ParameterKey'],'ParameterValue':p['ParameterValue']} for p in s['Parameters']]
params={p['ParameterKey']:p['ParameterValue'] for p in previous}
outputs={p['OutputKey']:p['OutputValue'] for p in s['Outputs']}
new=params|{k:os.environ[e] for k,e in [('AppImage','APP_IMAGE'),('NginxImage','NGINX_IMAGE'),('TelemetryImage','TELEMETRY_IMAGE')]}
for k in ['AppImage','NginxImage','TelemetryImage']:
    if '@sha256:' not in new[k]:raise SystemExit('Image must be digest-pinned: '+k)
# Register a temporary worker task revision to run migration BEFORE changing services.
td=ecs.describe_task_definition(taskDefinition=outputs['WorkerTask'])['taskDefinition']
allowed={'family','taskRoleArn','executionRoleArn','networkMode','containerDefinitions','volumes','placementConstraints','requiresCompatibilities','cpu','memory','runtimePlatform','ephemeralStorage'}
td={k:v for k,v in td.items() if k in allowed}
for c in td['containerDefinitions']:
    if c['name']=='app':c['image']=new['AppImage'];c['command']=['php','artisan','migrate','--force']
    elif c['name']=='telemetry':c['image']=new['TelemetryImage']
revision=ecs.register_task_definition(**td)['taskDefinition']['taskDefinitionArn']
try:
    result=ecs.run_task(cluster=params['Cluster'],taskDefinition=revision,launchType='FARGATE',networkConfiguration={'awsvpcConfiguration':{'subnets':params['PrivateSubnets'].split(','),'securityGroups':[params['AppSG']],'assignPublicIp':'DISABLED'}})
    if result.get('failures') or not result.get('tasks'):raise RuntimeError('Migration task could not start')
    task=result['tasks'][0]['taskArn'];ecs.get_waiter('tasks_stopped').wait(cluster=params['Cluster'],tasks=[task],WaiterConfig={'Delay':10,'MaxAttempts':90})
    task_info=ecs.describe_tasks(cluster=params['Cluster'],tasks=[task])['tasks'][0]
    app=next(c for c in task_info['containers'] if c['name']=='app')
    if app.get('exitCode')!=0:raise RuntimeError('Migration failed; inspect worker logs')
finally:ecs.deregister_task_definition(taskDefinition=revision)
new['DesiredCount']='2'
body=Path('infra/laravel-order-app/runtime.yaml').read_text()
def update(values,template=body):
    kwargs=dict(StackName=stack,TemplateBody=template,Parameters=values,Capabilities=['CAPABILITY_IAM'])
    if os.environ.get('CFN_EXECUTION_ROLE'):kwargs['RoleARN']=os.environ['CFN_EXECUTION_ROLE']
    try:cf.update_stack(**kwargs)
    except cf.exceptions.ClientError as e:
        if 'No updates are to be performed' in str(e):return
        raise
    cf.get_waiter('stack_update_complete').wait(StackName=stack,WaiterConfig={'Delay':15,'MaxAttempts':100})
try:
    update([{'ParameterKey':k,'ParameterValue':v} for k,v in new.items()])
    ecs.get_waiter('services_stable').wait(cluster=params['Cluster'],services=[outputs['ApiService'],outputs['WorkerService']],WaiterConfig={'Delay':15,'MaxAttempts':60})
    subprocess.run([sys.executable,'examples/laravel-order-app/scripts/smoke.py'],check=True,env=os.environ|{'BASE_URL':params['AppOrigin']})
except Exception:
    status=cf.describe_stacks(StackName=stack)['Stacks'][0]['StackStatus']
    if status in ('UPDATE_COMPLETE','UPDATE_ROLLBACK_COMPLETE'):
        update(previous,previous_body)
        ecs.get_waiter('services_stable').wait(cluster=params['Cluster'],services=[outputs['ApiService'],outputs['WorkerService']])
        if int(params['DesiredCount'])>0:
            subprocess.run([sys.executable,'examples/laravel-order-app/scripts/smoke.py'],check=True,env=os.environ|{'BASE_URL':params['AppOrigin']})
        print('Previous template/parameters restored. Schema changes remain; inspect compatibility.',file=sys.stderr)
    else:print('Manual recovery required; stack status: '+status,file=sys.stderr)
    raise
print('Release passed business smoke test')
