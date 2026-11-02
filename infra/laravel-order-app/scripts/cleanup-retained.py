"""Delete only retained S3/ECR from a saved CFN inventory, after explicit --apply."""
import argparse,json,boto3
p=argparse.ArgumentParser();p.add_argument('inventory');p.add_argument('--apply',action='store_true');a=p.parse_args()
items=json.load(open(a.inventory))['StackResourceSummaries'];s3=boto3.client('s3');ecr=boto3.client('ecr')
for r in items:
    kind=r['ResourceType'];name=r.get('PhysicalResourceId')
    if not name or kind not in ['AWS::S3::Bucket','AWS::ECR::Repository']:continue
    print(('DELETE' if a.apply else 'Would delete'),kind,name)
    if not a.apply:continue
    if kind=='AWS::S3::Bucket':
        # Disable image notifications on buckets retained after Lambda stack deletion.
        s3.put_bucket_notification_configuration(Bucket=name,NotificationConfiguration={})
        for page in s3.get_paginator('list_object_versions').paginate(Bucket=name):
            objects=[{'Key':x['Key'],'VersionId':x['VersionId']} for k in ['Versions','DeleteMarkers'] for x in page.get(k,[])]
            for start in range(0,len(objects),1000):
                result=s3.delete_objects(Bucket=name,Delete={'Objects':objects[start:start+1000],'Quiet':True})
                if result.get('Errors'):raise RuntimeError('Object deletion errors; keep inventory and investigate')
        s3.delete_bucket(Bucket=name)
    else:ecr.delete_repository(repositoryName=name,force=True)
