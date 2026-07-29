import sys, re, glob, os

def strip(src):
    """Buang komentar dan isi string, sisakan kerangka kodenya.

    Mengembalikan (kerangka, galat). Galat berisi string yang tidak pernah
    ditutup -- dilaporkan dari sini karena hanya penelusuran inilah yang
    tahu escape sequence, sehingga '/\\' tidak salah dibaca.
    """
    out, errs, i, n = [], [], 0, len(src)
    line = 1
    while i < n:
        c = src[i]
        if c == '\n':
            line += 1; out.append(c); i += 1
        elif c == '/' and i+1 < n and src[i+1] == '/':
            while i < n and src[i] != '\n': i += 1
        elif c == '#' and not src.startswith('#[', i):
            while i < n and src[i] != '\n': i += 1
        elif c == '/' and i+1 < n and src[i+1] == '*':
            i += 2
            while i+1 < n and not (src[i] == '*' and src[i+1] == '/'):
                if src[i] == '\n': line += 1
                i += 1
            i += 2
        elif c in "'\"":
            q, opened, closed = c, line, False
            i += 1
            while i < n:
                if src[i] == '\\': i += 2; continue
                if src[i] == '\n': line += 1
                if src[i] == q: i += 1; closed = True; break
                i += 1
            if not closed:
                errs.append(f'string dibuka dengan {q} di baris {opened} tidak ditutup')
        else:
            out.append(c); i += 1
    return ''.join(out), errs

bad = 0
files = sys.argv[1:]
for f in files:
    src = open(f, encoding='utf-8', errors='replace').read()
    errs = []
    if not src.lstrip().startswith('<?php'):
        errs.append('tidak diawali <?php')
    s, quote_errs = strip(src)
    errs += quote_errs
    pairs = {')':'(', ']':'[', '}':'{'}
    stack = []
    for ch in s:
        if ch in '([{': stack.append(ch)
        elif ch in ')]}':
            if not stack or stack.pop() != pairs[ch]:
                errs.append(f'kurung tidak seimbang di dekat "{ch}"'); break
    if stack and not any('kurung' in e for e in errs):
        errs.append(f'kurung belum ditutup: {"".join(stack)}')
    # Pemeriksaan paritas tanda kutip yang dulu ada di sini sudah dibuang.
    # Ia menghitung tanda kutip pada sumber mentah, sehingga apostrof di
    # dalam komentar ("the application's name") dan escape di ujung string
    # ('/\\') dilaporkan sebagai kesalahan. Delapan berkas sah -- termasuk
    # config/app.php bawaan Laravel -- gagal karenanya. Alat yang menuduh
    # kode benar lebih berbahaya daripada tidak ada alat, karena hasilnya
    # jadi terbiasa diabaikan. Penggantinya ada di strip(), yang menelusuri
    # string sambil menghormati escape.
    if errs:
        bad += 1
        print(f'  FAIL {os.path.relpath(f)}')
        for e in errs: print(f'         - {e}')

print(f'\n  diperiksa: {len(files)} berkas | bermasalah: {bad}')
sys.exit(1 if bad else 0)
