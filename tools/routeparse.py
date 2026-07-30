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


# Isi string di-kosongkan sebelum kurung kurawal dihitung.
#
# Tanpa ini, parameter route di dalam prefix ikut terhitung sebagai kedalaman
# blok: ->prefix('drama/{drama}/asset') menambah satu '{' palsu, sehingga
# prefix nama didorong ke stack pada kedalaman yang terlalu dalam lalu
# langsung dibuang pada baris yang sama. Seluruh route di dalam grup itu
# kehilangan awalan namanya, dan pemeriksaan route mati melaporkannya sebagai
# route yang tidak terdefinisi -- padahal yang salah parsernya.
_STRINGS = re.compile(r"'[^']*'|\"[^\"]*\"")


def _braces(line: str) -> tuple:
    """(jumlah '{', jumlah '}') di luar string literal."""
    code = _STRINGS.sub("''", line)

    return code.count('{'), code.count('}')


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

        opens, closes = _braces(line)

        if pending is not None:
            stack.append((pending, depth + opens))

        depth += opens - closes

        while stack and depth < stack[-1][1]:
            stack.pop()

    return names
