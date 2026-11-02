"""Create an EC2 artifact without .env, dev dependencies, credentials or local DB."""
import pathlib,shutil,subprocess,tarfile,tempfile,sys
root=pathlib.Path(__file__).resolve().parents[1]
output=pathlib.Path(sys.argv[1]).resolve()
if not (root/'web/dist/index.html').exists():raise SystemExit('Build web first: cd web && npm ci && npm run build')
with tempfile.TemporaryDirectory(prefix='order-release-') as temp:
    dst=pathlib.Path(temp)/'app'
    shutil.copytree(root,dst,ignore=shutil.ignore_patterns('.env','vendor','node_modules','dist','*.sqlite*','.phpunit.cache','.git'))
    subprocess.run(['composer','install','--no-dev','--no-interaction','--prefer-dist','--working-dir='+str(dst)],check=True)
    shutil.copytree(root/'web/dist',dst/'public',dirs_exist_ok=True)
    for p in (dst/'storage').rglob('*'):
        if p.is_file() and p.name!='.gitkeep':p.unlink()
    with tarfile.open(output,'w:gz') as tar:
        for p in dst.iterdir():tar.add(p,arcname=p.name)
print('Created application artifact')
