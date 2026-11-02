"""Bounded lab load: max 10 rps, 4 concurrent, 120s. Stops at >20% non-2xx after 20 samples."""
import concurrent.futures,json,os,sys,time,urllib.request,urllib.error,statistics
base=os.environ['BASE_URL'].rstrip('/');token=os.environ.get('ACCESS_TOKEN','')
if not token:raise SystemExit('ACCESS_TOKEN required')
path=os.environ.get('LOAD_PATH','/api/orders')
if not path.startswith('/api/'):raise SystemExit('Only lab API paths allowed')
samples=[]
def request():
    start=time.monotonic();status=0
    try:
        with urllib.request.urlopen(urllib.request.Request(base+path,headers={'Authorization':'Bearer '+token}),timeout=3) as r:status=r.status
    except urllib.error.HTTPError as e:status=e.code
    except Exception:pass
    return ((time.monotonic()-start)*1000,status)
with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
    # Explicit recovery phase returns to baseline rate. Batches bound in-flight work.
    stop=False
    for phase,seconds,rate in [('baseline',10,1),('ramp',10,3),('steady',20,5),('recovery',10,1)]:
        if stop:break
        end=time.monotonic()+seconds
        while time.monotonic()<end:
            tick=time.monotonic();results=list(pool.map(lambda _:request(),range(min(rate,4))))
            samples.extend(results)
            if len(samples)>=20 and sum(s<200 or s>=300 for _,s in samples[-20:])/20>0.2:stop=True;break
            time.sleep(max(0,1-(time.monotonic()-tick)))
        print(json.dumps({'phase':phase,'samples':len(samples),'stopped':stop}))
values=sorted(x[0] for x in samples)
print(json.dumps({'count':len(values),'p95_ms':values[min(len(values)-1,int(.95*len(values)))],'p99_ms':values[min(len(values)-1,int(.99*len(values)))],'errors':sum(s<200 or s>=300 for _,s in samples)}))
