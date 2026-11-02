"""Generate reviewed IAM documents, no IAM writes. Args: foundation runtime owner/repo output-dir."""
import json,sys,pathlib,boto3
foundation,runtime,repo,directory=sys.argv[1:];dest=pathlib.Path(directory);dest.mkdir(parents=True,exist_ok=True)
cf=boto3.client('cloudformation');account=boto3.client('sts').get_caller_identity()['Account'];region=boto3.session.Session().region_name
f={x['OutputKey']:x['OutputValue'] for x in cf.describe_stacks(StackName=foundation)['Stacks'][0]['Outputs']}
s=cf.describe_stacks(StackName=runtime)['Stacks'][0];o={x['OutputKey']:x['OutputValue'] for x in s['Outputs']}
resources={x['LogicalResourceId']:x['PhysicalResourceId'] for x in cf.list_stack_resources(StackName=runtime)['StackResourceSummaries']}
exec_role=f'arn:aws:iam::{account}:role/{runtime}-cfn-execution'
def stmt(actions,resource,**kw):return dict(Effect='Allow',Action=actions,Resource=resource,**kw)
def policy(statements):return {'Version':'2012-10-17','Statement':statements}
def write(name,d):(dest/name).write_text(json.dumps(d,indent=2)+'\n')
write('deploy-trust.json',policy([{'Effect':'Allow','Principal':{'Federated':f'arn:aws:iam::{account}:oidc-provider/token.actions.githubusercontent.com'},'Action':'sts:AssumeRoleWithWebIdentity','Condition':{'StringEquals':{'token.actions.githubusercontent.com:aud':'sts.amazonaws.com','token.actions.githubusercontent.com:sub':f'repo:{repo}:environment:dev'}}}]))
write('cfn-trust.json',policy([{'Effect':'Allow','Principal':{'Service':'cloudformation.amazonaws.com'},'Action':'sts:AssumeRole'}]))
pass_roles=stmt('iam:PassRole',[f['TaskRole'],f['ExecutionRole']],Condition={'StringEquals':{'iam:PassedToService':'ecs-tasks.amazonaws.com'}})
task_arns=[f'arn:aws:ecs:{region}:{account}:task-definition/{runtime}-*']
ecs_common=[stmt(['ecs:RegisterTaskDefinition','ecs:DescribeTaskDefinition'],'*'),stmt(['ecs:DeregisterTaskDefinition','ecs:TagResource'],task_arns),pass_roles]
write('deploy-policy.json',policy([
stmt(['cloudformation:DescribeStacks','cloudformation:GetTemplate','cloudformation:DescribeStackEvents','cloudformation:ListStackResources'],[s['StackId'],f'arn:aws:cloudformation:{region}:{account}:stack/{foundation}/*']),
stmt('cloudformation:UpdateStack',s['StackId'],Condition={'StringEquals':{'cloudformation:RoleArn':exec_role}}),stmt('iam:PassRole',exec_role,Condition={'StringEquals':{'iam:PassedToService':'cloudformation.amazonaws.com'}}),
stmt('ecr:GetAuthorizationToken','*'),stmt(['ecr:BatchCheckLayerAvailability','ecr:InitiateLayerUpload','ecr:UploadLayerPart','ecr:CompleteLayerUpload','ecr:PutImage','ecr:DescribeImages'],[f'arn:aws:ecr:{region}:{account}:repository/'+f[n].split('/',1)[1] for n in ['AppRepo','NginxRepo','TelemetryRepo']]),
stmt('ecs:RunTask',task_arns,Condition={'ArnEquals':{'ecs:cluster':f['Cluster']}}),stmt('ecs:DescribeTasks',f'arn:aws:ecs:{region}:{account}:task/*'),stmt('ecs:DescribeServices',[o['ApiService'],o['WorkerService']])]+ecs_common))
schedule_role=f'arn:aws:iam::{account}:role/'+resources['ScheduleRole']
write('cfn-policy.json',policy(ecs_common+[
stmt(['ecs:UpdateService','ecs:DescribeServices','ecs:DeleteService'],[o['ApiService'],o['WorkerService']]),
stmt(['iam:GetRole','iam:GetRolePolicy','iam:PutRolePolicy','iam:DeleteRolePolicy','iam:DeleteRole'],schedule_role),stmt('iam:PassRole',schedule_role,Condition={'StringEquals':{'iam:PassedToService':'scheduler.amazonaws.com'}}),
stmt(['scheduler:GetSchedule','scheduler:UpdateSchedule','scheduler:DeleteSchedule'],f'arn:aws:scheduler:{region}:{account}:schedule/default/'+resources['Schedule']),
stmt(['elasticloadbalancing:DeleteListener','elasticloadbalancing:DeleteTargetGroup','elasticloadbalancing:DeleteLoadBalancer'],[resources[k] for k in ['HTTP','HTTPS','Targets','ALB']]),
stmt('cloudwatch:DeleteAlarms',f'arn:aws:cloudwatch:{region}:{account}:alarm:'+resources['ErrorAlarm']),
stmt(['elasticloadbalancing:Describe*','cloudwatch:DescribeAlarms'],'*')]))
print('Wrote four IAM documents; review them before create-role/put-role-policy. No IAM changes made.')
