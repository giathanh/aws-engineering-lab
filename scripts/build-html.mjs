import { Marked, Renderer } from 'marked';
import fs from 'node:fs';
import path from 'node:path';

const root = process.argv[2] ?? path.resolve(import.meta.dirname, '..');
const docs = [
  { src: 'notes/01-foundations.md', nav: '01. Lý thuyết' },
  { src: 'labs/01-foundations/README.md', nav: '01. Lab' },
  { src: 'notes/02-identity-and-access.md', nav: '02. Lý thuyết' },
  { src: 'labs/02-identity-and-access/README.md', nav: '02. Lab' },
  { src: 'notes/03-cost-management.md', nav: '03. Lý thuyết' },
  { src: 'labs/03-cost-management/README.md', nav: '03. Lab' },
  { src: 'notes/04-networking.md', nav: '04. Lý thuyết' },
  { src: 'labs/04-networking/README.md', nav: '04. Lab' },
  { src: 'notes/05-compute-and-load-balancing.md', nav: '05. Lý thuyết' },
  { src: 'labs/05-compute-and-load-balancing/README.md', nav: '05. Lab' },
  { src: 'notes/06-storage-and-content-delivery.md', nav: '06. Lý thuyết' },
  { src: 'labs/06-storage-and-content-delivery/README.md', nav: '06. Lab' },
  { src: 'notes/07-databases-and-cache.md', nav: '07. Lý thuyết' },
  { src: 'labs/07-databases-and-cache/README.md', nav: '07. Lab' },
  { src: 'notes/08-containers.md', nav: '08. Lý thuyết' },
  { src: 'labs/08-containers/README.md', nav: '08. Lab' },
  { src: 'notes/09-serverless.md', nav: '09. Lý thuyết' },
  { src: 'labs/09-serverless/README.md', nav: '09. Lab' },
  { src: 'notes/10-observability-and-application-security.md', nav: '10. Lý thuyết' },
  { src: 'labs/10-observability-and-application-security/README.md', nav: '10. Lab' },
  { src: 'notes/11-messaging-and-workflows.md', nav: '11. Lý thuyết' },
  { src: 'labs/11-messaging-and-workflows/README.md', nav: '11. Lab' },
  { src: 'notes/12-iac-and-cicd.md', nav: '12. Lý thuyết' },
  { src: 'labs/12-iac-and-cicd/README.md', nav: '12. Lab' },
  { src: 'notes/13-reliability-and-performance.md', nav: '13. Lý thuyết' },
  { src: 'labs/13-reliability-and-performance/README.md', nav: '13. Lab' },
  { src: 'notes/14-architecture-and-governance.md', nav: '14. Lý thuyết' },
  { src: 'labs/14-architecture-and-governance/README.md', nav: '14. Lab' },
];
const htmlPath = (p) => p.replace(/\.md$/, '.html');

const slug = (s) =>
  s.normalize('NFC').toLowerCase().trim()
    .replace(/<[^>]+>/g, '')
    .replace(/[^\p{L}\p{M}\p{N}\s_-]/gu, '')
    .replace(/\s/g, '-');

const esc = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

const CSS = `
:root{--bg:#fbfaf8;--fg:#1f2328;--muted:#5f6670;--line:#e3e1dc;--card:#fff;--code-bg:#f3f2ef;--accent:#0b6bcb;--accent-bg:#e8f1fb;--warn-bg:#fff7e6;--warn-line:#f0c36d}
@media (prefers-color-scheme:dark){:root{--bg:#16181c;--fg:#e6e6e3;--muted:#9aa0a6;--line:#2e3238;--card:#1d2025;--code-bg:#22262c;--accent:#6cb0ff;--accent-bg:#1b2a3d;--warn-bg:#2b2416;--warn-line:#8a6a2c}}
*{box-sizing:border-box}
html{scroll-behavior:smooth;scroll-padding-top:72px}
body{margin:0;background:var(--bg);color:var(--fg);font:16px/1.7 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif}
header.top{position:sticky;top:0;z-index:10;background:color-mix(in srgb,var(--bg) 88%,transparent);backdrop-filter:blur(8px);border-bottom:1px solid var(--line)}
header.top nav{max-width:1200px;margin:0 auto;padding:10px 24px;display:flex;gap:6px;align-items:center;flex-wrap:wrap}
header.top .brand{font-weight:650;margin-right:12px}
header.top a{color:var(--muted);text-decoration:none;padding:4px 12px;border-radius:999px;font-size:14px}
header.top a:hover{color:var(--fg);background:var(--code-bg)}
header.top a.active{color:var(--accent);background:var(--accent-bg);font-weight:600}
.layout{max-width:1200px;margin:0 auto;padding:24px;display:grid;grid-template-columns:250px minmax(0,1fr);gap:48px}
aside.toc{position:sticky;top:72px;align-self:start;max-height:calc(100vh - 96px);overflow:auto;font-size:14px}
aside.toc .label{font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin-bottom:8px}
aside.toc ol{list-style:none;margin:0;padding:0}
aside.toc li a{display:block;padding:4px 10px;border-left:2px solid var(--line);color:var(--muted);text-decoration:none;line-height:1.4}
aside.toc li a:hover{color:var(--fg)}
aside.toc li a.current{color:var(--accent);border-left-color:var(--accent)}
main{max-width:860px}
h1{font-size:2rem;line-height:1.25;margin:.2em 0 .6em}
h2{font-size:1.5rem;margin:2.2em 0 .6em;padding-bottom:.3em;border-bottom:1px solid var(--line)}
h3{font-size:1.18rem;margin:1.8em 0 .5em}
h1 a.anchor,h2 a.anchor,h3 a.anchor{color:inherit;text-decoration:none}
a{color:var(--accent)}
hr{border:0;border-top:1px solid var(--line);margin:2.5em 0}
blockquote{margin:1.2em 0;padding:.8em 1.1em;background:var(--accent-bg);border-left:4px solid var(--accent);border-radius:0 8px 8px 0}
blockquote p{margin:.3em 0}
code{font:0.88em/1.5 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;background:var(--code-bg);padding:.15em .4em;border-radius:5px}
.code{position:relative;margin:1.1em 0}
pre{margin:0;background:var(--code-bg);border:1px solid var(--line);border-radius:10px;padding:14px 16px;overflow:auto}
pre code{background:none;padding:0;font-size:.86em}
.code .lang{position:absolute;top:6px;left:12px;font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;pointer-events:none}
.code pre{padding-top:26px}
.code button{position:absolute;top:6px;right:8px;font-size:12px;border:1px solid var(--line);background:var(--card);color:var(--muted);border-radius:6px;padding:2px 8px;cursor:pointer;opacity:.7}
.code button:hover{opacity:1;color:var(--fg)}
.table-wrap{overflow-x:auto;margin:1.1em 0;border:1px solid var(--line);border-radius:10px}
table{border-collapse:collapse;width:100%;font-size:.94em}
th,td{text-align:left;vertical-align:top;padding:8px 12px;border-bottom:1px solid var(--line)}
th{background:var(--code-bg);font-weight:600}
tr:last-child td{border-bottom:0}
ul,ol{padding-left:1.5em}
li{margin:.25em 0}
li input[type=checkbox]{margin-right:.5em}
ul:has(> li > input[type=checkbox]){list-style:none;padding-left:.4em}
details{margin:1.2em 0;padding:.6em 1.1em;border:1px solid var(--warn-line);background:var(--warn-bg);border-radius:10px}
details summary{cursor:pointer;font-weight:600}
footer{color:var(--muted);font-size:13px;margin:4em 0 2em;padding-top:1em;border-top:1px solid var(--line)}
@media (max-width:900px){.layout{grid-template-columns:1fr;gap:0}aside.toc{display:none}}
@media print{header.top,aside.toc,.code button{display:none}.layout{display:block}}
`;

const JS = `
document.querySelectorAll('.code button').forEach(b=>b.addEventListener('click',async()=>{
  await navigator.clipboard.writeText(b.parentElement.querySelector('code').innerText);
  b.textContent='Đã copy';setTimeout(()=>b.textContent='Copy',1500);
}));
const links=[...document.querySelectorAll('aside.toc a')];
const heads=links.map(a=>document.getElementById(decodeURIComponent(a.hash.slice(1)))).filter(Boolean);
const mark=()=>{let cur=heads[0];for(const h of heads){if(h.getBoundingClientRect().top<100)cur=h}
  links.forEach(a=>a.classList.toggle('current',cur&&decodeURIComponent(a.hash.slice(1))===cur.id))};
addEventListener('scroll',mark,{passive:true});mark();
`;

for (const doc of docs) {
  const md = fs.readFileSync(path.join(root, doc.src), 'utf8');
  const toc = [];
  const used = new Map();
  const marked = new Marked({ gfm: true });
  marked.use({
    renderer: {
      heading({ tokens, depth, text }) {
        let id = slug(text);
        const n = used.get(id) ?? 0;
        used.set(id, n + 1);
        if (n) id += `-${n}`;
        const inner = this.parser.parseInline(tokens);
        if (depth === 2) toc.push({ id, label: inner.replace(/<[^>]+>/g, '') });
        return `<h${depth} id="${esc(id)}"><a class="anchor" href="#${esc(id)}">${inner}</a></h${depth}>\n`;
      },
      link({ href, title, tokens }) {
        const text = this.parser.parseInline(tokens);
        let h = href;
        if (!/^[a-z]+:/i.test(h)) {
          // Chỉ đổi sang .html với các file được build; link khác (vd: plan) giữ nguyên .md
          const [file, hash] = h.split('#');
          const target = file && path.join(path.dirname(doc.src), file);
          if (docs.some((d) => d.src === target)) h = htmlPath(file) + (hash ? `#${hash}` : '');
        }
        const ext = /^https?:/.test(h) ? ' target="_blank" rel="noopener"' : '';
        return `<a href="${esc(h)}"${title ? ` title="${esc(title)}"` : ''}${ext}>${text}</a>`;
      },
      code({ text, lang }) {
        const l = (lang || '').trim();
        return `<div class="code">${l ? `<span class="lang">${esc(l)}</span>` : ''}<button type="button">Copy</button><pre><code>${esc(text)}</code></pre></div>\n`;
      },
      table(token) {
        return `<div class="table-wrap">${Renderer.prototype.table.call(this, token)}</div>\n`;
      },
    },
  });
  // Bỏ checkbox disabled để người học tự đánh dấu khi đọc
  const body = marked.parse(md).replace(/<input disabled="" type="checkbox">/g, '<input type="checkbox">')
    .replace(/<input checked="" disabled="" type="checkbox">/g, '<input type="checkbox" checked>');
  const title = (md.match(/^# (.+)$/m) || [, doc.nav])[1];
  const out = htmlPath(doc.src);
  const rel = (target) => path.relative(path.dirname(out), htmlPath(target)) || path.basename(out);
  const nav = docs.map((d) => `<a href="${rel(d.src)}"${d === doc ? ' class="active"' : ''}>${d.nav}</a>`).join('');
  const html = `<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${esc(title)}</title>
<style>${CSS}</style>
</head>
<body>
<header class="top"><nav><span class="brand">AWS Engineering Lab</span>${nav}</nav></header>
<div class="layout">
<aside class="toc"><div class="label">Mục lục</div><ol>${toc.map((t) => `<li><a href="#${esc(t.id)}">${t.label}</a></li>`).join('')}</ol></aside>
<main>
${body}
<footer>Sinh tự động từ <code>${doc.src}</code>. Hãy sửa file Markdown rồi build lại, đừng sửa trực tiếp file HTML này.</footer>
</main>
</div>
<script>${JS}</script>
</body>
</html>
`;
  fs.writeFileSync(path.join(root, out), html);
  console.log('wrote', out, `(${toc.length} mục)`);
}
