"""Authenticated business smoke test. Token is read from env, never argv/output."""
import json, os, time, uuid, urllib.request
base=os.environ['BASE_URL'].rstrip('/')
headers={'Content-Type':'application/json'}
if os.environ.get('ACCESS_TOKEN'): headers['Authorization']='Bearer '+os.environ['ACCESS_TOKEN']
elif os.environ.get('LOCAL_SMOKE')=='1': headers['X-Lab-User']='alice'
else: raise SystemExit('Set ACCESS_TOKEN (or LOCAL_SMOKE=1 only for local)')
def call(path, data=None):
    r=urllib.request.Request(base+'/api'+path, data=None if data is None else json.dumps(data).encode(),headers=headers)
    with urllib.request.urlopen(r,timeout=10) as f: return json.load(f)
order=call('/orders',{'product':'smoke-fixture','quantity':1})
assert call('/orders/'+order['id'])['id']==order['id']
key=str(uuid.uuid4());report=call('/reports',{'key':key})
assert call('/reports',{'key':key})['id']==report['id']
for _ in range(30):
    if call('/reports/'+report['id'])['status']=='complete': break
    time.sleep(2)
else: raise SystemExit('Report did not complete within 60 seconds')
assert any(x['id']==order['id'] for x in call('/reports/'+report['id']+'/download'))
print('PASS: create/read/report/idempotency/download')
