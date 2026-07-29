"""
Memastikan setiap kelas CSS yang dipakai Blade punya aturannya.

Berkas yang membawa <style> sendiri (halaman cetak, halaman berdiri
sendiri) diperiksa terhadap gaya di dalamnya, bukan terhadap bundel utama.
"""
import re, sys, glob, os

def classes_in(src):
    found = set()
    for m in re.finditer(r'class="([^"]+)"', src):
        for token in m.group(1).split():
            if re.fullmatch(r'[a-zA-Z][a-zA-Z0-9_-]*', token):
                found.add(token)
    return found

def rules_in(css):
    return set(re.findall(r'\.([a-zA-Z][a-zA-Z0-9_-]*)', css))

shared = ''
for f in glob.glob('resources/css/**/*.css', recursive=True):
    shared += open(f, encoding='utf-8').read()

shared_rules = rules_in(shared)

missing = {}
total = set()

for f in glob.glob('resources/views/**/*.blade.php', recursive=True):
    src = open(f, encoding='utf-8').read()

    # Gaya lokal berkas ini, bila ada
    local = ''.join(re.findall(r'<style[^>]*>(.*?)</style>', src, re.S))
    available = shared_rules | rules_in(local)

    used = classes_in(src)
    total |= used

    for c in used - available:
        missing.setdefault(c, []).append(os.path.relpath(f))

if missing:
    print(f'  {len(missing)} kelas tanpa aturan CSS:')
    for c, files in sorted(missing.items()):
        print(f'    .{c} <- {", ".join(files)}')
    sys.exit(1)

print(f'  semua {len(total)} kelas punya aturan CSS')
