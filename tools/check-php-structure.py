import sys, re, glob, os

def strip(src):
    out, i, n = [], 0, len(src)
    while i < n:
        c = src[i]
        if c == '/' and i+1 < n and src[i+1] == '/':
            while i < n and src[i] != '\n': i += 1
        elif c == '#' and not src.startswith('#[', i):
            while i < n and src[i] != '\n': i += 1
        elif c == '/' and i+1 < n and src[i+1] == '*':
            i += 2
            while i+1 < n and not (src[i] == '*' and src[i+1] == '/'): i += 1
            i += 2
        elif c in "'\"":
            q = c; i += 1
            while i < n:
                if src[i] == '\\': i += 2; continue
                if src[i] == q: i += 1; break
                i += 1
        else:
            out.append(c); i += 1
    return ''.join(out)

bad = 0
files = sys.argv[1:]
for f in files:
    src = open(f, encoding='utf-8', errors='replace').read()
    errs = []
    if not src.lstrip().startswith('<?php'):
        errs.append('tidak diawali <?php')
    s = strip(src)
    pairs = {')':'(', ']':'[', '}':'{'}
    stack = []
    for ch in s:
        if ch in '([{': stack.append(ch)
        elif ch in ')]}':
            if not stack or stack.pop() != pairs[ch]:
                errs.append(f'kurung tidak seimbang di dekat "{ch}"'); break
    if stack and not any('kurung' in e for e in errs):
        errs.append(f'kurung belum ditutup: {"".join(stack)}')
    # kutip ganjil
    for q in ["'", '"']:
        cnt = len(re.findall(r'(?<!\\)'+q, src))
        if cnt % 2: errs.append(f'jumlah tanda kutip {q} ganjil ({cnt})')
    if errs:
        bad += 1
        print(f'  FAIL {os.path.relpath(f)}')
        for e in errs: print(f'         - {e}')

print(f'\n  diperiksa: {len(files)} berkas | bermasalah: {bad}')
sys.exit(1 if bad else 0)
