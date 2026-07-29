"""
Mengekstrak nama route lengkap dari berkas routes Laravel.

Menangani dua hal yang tidak bisa dibaca regex sederhana:

1. Prefix nama bertingkat — Route::name('admin.')->group(fn =>
   Route::name('user.')->group(fn => Route::get(...)->name('ban')))
   menghasilkan 'admin.user.ban'.

2. Rantai method yang membentang beberapa baris, misalnya
   Route::controller(X)
       ->prefix('episode')->name('episode.')
       ->middleware('...')
       ->group(function () {
"""
import re


def _join_chains(src: str) -> str:
    """Menyatukan ->method() yang ditulis di baris berikutnya."""
    return re.sub(r'\n\s*->', '->', src)


def extract(src: str) -> set:
    src = _join_chains(src)

    names = set()
    stack = []          # (prefix, kedalaman kurawal saat didorong)
    depth = 0

    group_prefix = re.compile(r"->name\(\s*'([^']*\.)'\s*\)")
    leaf_name = re.compile(r"->name\(\s*'([^']+)'\s*\)")

    for line in src.split('\n'):
        pending = None

        if '->group(' in line:
            m = group_prefix.search(line)
            if m:
                pending = m.group(1)
        else:
            m = leaf_name.search(line)
            if m and not m.group(1).endswith('.'):
                names.add(''.join(p for p, _ in stack) + m.group(1))

        opens = line.count('{')
        closes = line.count('}')

        if pending is not None:
            stack.append((pending, depth + opens))

        depth += opens - closes

        while stack and depth < stack[-1][1]:
            stack.pop()

    return names
