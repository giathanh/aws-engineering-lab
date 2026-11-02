"""Version-specific outputs: duplicates overwrite identical content; old events cannot replace new results."""
import io, hashlib, json, warnings
from urllib.parse import unquote_plus
import boto3
from PIL import Image, ImageOps
Image.MAX_IMAGE_PIXELS = 20_000_000
warnings.simplefilter('error', Image.DecompressionBombWarning)
MAX_BYTES = 5 * 1024 * 1024

def resize(data):
    if len(data)>MAX_BYTES: raise ValueError('input too large')
    with Image.open(io.BytesIO(data)) as source:
        if source.format not in ('JPEG','PNG'): raise ValueError('unsupported image')
        source.load()
        image=ImageOps.exif_transpose(source).convert('RGB')
        image.thumbnail((512,512))
        out=io.BytesIO();image.save(out,format='JPEG',quality=85)
        return out.getvalue(),image.size

def process_record(record,s3):
    bucket=record['s3']['bucket']['name'];obj=record['s3']['object'];key=unquote_plus(obj['key'])
    if not key.startswith('uploads/'): return None
    version=obj.get('versionId')
    if not version or version=='null': raise ValueError('versioned input bucket required')
    args=dict(Bucket=bucket,Key=key,VersionId=version)
    head=s3.head_object(**args)
    if head['ContentLength']>MAX_BYTES: raise ValueError('input too large')
    source=s3.get_object(**args)['Body'].read(MAX_BYTES+1)
    data,(w,h)=resize(source)
    identity=hashlib.sha256((key+'\0'+version).encode()).hexdigest()
    output='processed/'+identity+'.jpg'
    s3.put_object(Bucket=bucket,Key=output,Body=data,ContentType='image/jpeg',Metadata={'width':str(w),'height':str(h),'source-version':version})
    print(json.dumps({'result':'processed','width':w,'height':h}))
    return output

def handler(event,context):
    s3=boto3.client('s3')
    return {'outputs':[process_record(r,s3) for r in event.get('Records',[])]}

def sqs_handler(event,context):
    # Standard queue. Each body is an S3 event JSON, not a Laravel serialized job.
    failures=[];s3=boto3.client('s3')
    for message in event['Records']:
        try:
            for record in json.loads(message['body'])['Records']: process_record(record,s3)
        except Exception:
            failures.append({'itemIdentifier':message['messageId']})
    return {'batchItemFailures':failures}
