"""Run as root on the fixture. Input contains env plus secret ARN map; never prints values."""
import json, sys, os, boto3
config=json.load(open(sys.argv[1]));env=config['env'];sm=boto3.client('secretsmanager',region_name=env['AWS_DEFAULT_REGION'])
for name,spec in config['secrets'].items():
    value=sm.get_secret_value(SecretId=spec['arn'])['SecretString']
    env[name]=json.loads(value)[spec['key']] if spec.get('key') else value
os.umask(0o077)
with open('.env','w') as f:
    for key,value in env.items():
        if '\n' in str(value) or '\r' in str(value):raise ValueError('multiline env unsupported')
        f.write(key+'='+json.dumps(str(value))+'\n')
