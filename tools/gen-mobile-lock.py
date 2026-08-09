import re, os
base='resources/css'
app=open(base+'/app.css').read()
imports=re.findall(r'@import\s+"([^"]+)"',app)
files=[i for i in imports if 'admin' not in i and 'mobile-lock' not in i]
out=[]
for f in files:
    p=os.path.join(base,f.lstrip('./'))
    if not os.path.exists(p): continue
    s=open(p).read()
    for m in re.finditer(r'@media([^{]*)\{',s):
        cond=m.group(1)
        if 'min-width' in cond: continue
        mw=re.search(r'max-width\s*:\s*(\d+)',cond)
        if not mw: continue
        bp=int(mw.group(1))
        if bp>900: continue
        i=m.end(); depth=1
        while i<len(s) and depth:
            if s[i]=='{': depth+=1
            elif s[i]=='}': depth-=1
            i+=1
        body=s[m.end():i-1]
        out.append((bp,f,body))
out.sort(key=lambda x:-x[0])
res=['/* DIHASILKAN OTOMATIS oleh tools/gen-mobile-lock.py — JANGAN DIEDIT MANUAL.\n   Semua blok @media mobile (<=900px) dari CSS sisi web dipentaskan ulang\n   tanpa media query, supaya desktop tampil persis seperti ponsel. */\n']
for bp,f,body in out:
    res.append('/* ==== %s @ <=%dpx ==== */'%(f,bp))
    res.append(body.strip()+'\n')
open(base+'/web/home/mobile-lock-generated.css','w').write('\n'.join(res))
print('blok:',len(out),'ukuran:',os.path.getsize(base+'/web/home/mobile-lock-generated.css'))
