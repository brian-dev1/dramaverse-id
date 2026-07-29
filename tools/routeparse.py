"""
Mengekstrak nama route lengkap dari berkas routes Laravel.

Menangani prefix nama bertingkat: Route::name('admin.')->group(fn => 
Route::controller(X)->name('user.')->group(fn => Route::get(...)->name('ban')))
menghasilkan 'admin.user.ban'.
"""
import re


def extract(src: str) -> set:
    names = set()
    stack = []          # tumpukan (prefix, kedalaman_kurawal)
    depth = 0
    i = 0
    n = len(src)

    # Pola prefix grup: ->name('x.') yang diikuti ->group(
    group_prefix = re.compile(r"->name\(\s*'([^']*\.)'\s*\)")
    leaf_name = re.compile(r"->name\(\s*'([^']+)'\s*\)")

    lines = src.split('\n')
    for line in lines:
        # Prefix grup pada baris ini
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
