import sys, re, os

OPEN = {
    'if':'endif', 'foreach':'endforeach', 'forelse':'endforelse', 'for':'endfor',
    'while':'endwhile', 'php':'endphp', 'unless':'endunless',
    'auth':'endauth', 'guest':'endguest', 'isset':'endisset',
    'switch':'endswitch', 'once':'endonce', 'error':'enderror', 'push':'endpush',
    'section':'endsection', 'prepend':'endprepend', 'verbatim':'endverbatim',
}
CLOSE = {v: k for k, v in OPEN.items()}

def strip_comments(src):
    return re.sub(r'\{\{--.*?--\}\}', '', src, flags=re.S)

bad = 0
for f in sys.argv[1:]:
    src = strip_comments(open(f, encoding='utf-8').read())
    stack, errs = [], []

    for m in re.finditer(r"@(\w+)([ \t]*\()?", src):
        d, paren = m.group(1), m.group(2)

        # @empty tanpa kurung = pemisah di dalam @forelse, bukan pembuka
        if d == 'empty' and not paren:
            continue
        # @php(...) sebaris tidak butuh @endphp
        if d == 'php' and paren:
            continue
        # @section('a', 'b') dua argumen = sebaris, tidak butuh @endsection
        if d == 'section' and paren:
            depth, i = 1, m.end()
            while i < len(src) and depth:
                if src[i] == '(': depth += 1
                elif src[i] == ')': depth -= 1
                i += 1
            if ',' in src[m.end():i]:
                continue

        if d in OPEN:
            stack.append(d)
        elif d in CLOSE:
            if not stack:
                errs.append(f'@{d} tanpa pembuka'); break
            top = stack.pop()
            if top != CLOSE[d]:
                errs.append(f'@{d} menutup @{top}'); break

    if stack:
        errs.append('belum ditutup: ' + ', '.join('@'+s for s in stack))

    if errs:
        bad += 1
        print(f'  FAIL {os.path.relpath(f)}')
        for e in errs:
            print('         -', e)

print(f'  diperiksa {len(sys.argv)-1} blade, bermasalah {bad}')
sys.exit(1 if bad else 0)
