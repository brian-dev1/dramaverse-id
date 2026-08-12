#!/usr/bin/env bash
#
# =============================================================================
# Firewall — dracinverse.cloud
# =============================================================================
#
# Lapis terluar yang masih berada di dalam VPS. Apa pun yang ditolak di sini
# ditolak oleh kernel, sebelum nginx dibangunkan dan jauh sebelum PHP menyala.
#
# ## Yang bisa dan tidak bisa dilakukan lapis ini
#
# Firewall menutup pintu yang tidak seharusnya terbuka, dan membatasi berapa
# cepat satu sumber boleh mengetuk. Itu efektif melawan pemindai port, brute
# force SSH, dan banjir dari sedikit sumber.
#
# Ia TIDAK menghentikan DDoS volumetrik. Kalau saluran masuk VPS 1 Gbps
# dibanjiri 5 Gbps, paketnya sudah tersumbat di hulu — di jaringan Rumahweb —
# sebelum kernel VPS sempat memutuskan apa pun. Aturan sebanyak apa pun di
# dalam VPS tidak bisa menolak paket yang tidak pernah sampai. Untuk itu
# penyaringnya harus di luar: Cloudflare, atau layanan mitigasi Rumahweb.
#
# Jalankan sebagai root:
#   bash deploy/firewall.sh
#
set -euo pipefail

# -----------------------------------------------------------------------------
# PERINGATAN — baca sebelum menjalankan
# -----------------------------------------------------------------------------
#
# Skrip ini menyalakan firewall dengan kebijakan tolak-semua untuk lalu lintas
# masuk. Bila SSH Anda berjalan di port selain 22, UBAH `PORT_SSH` di bawah
# SEBELUM menjalankan — kalau tidak, koneksi Anda terputus begitu firewall
# aktif dan satu-satunya jalan masuk kembali adalah konsol VPS lewat panel
# Rumahweb.
#
PORT_SSH=22

echo ">> Memasang ufw"
apt-get install -y ufw

# -----------------------------------------------------------------------------
# Kebijakan dasar
# -----------------------------------------------------------------------------
#
# Tolak semua yang masuk, izinkan semua yang keluar. Daftar putih, bukan daftar
# hitam: layanan yang tanpa sengaja terpasang dan mendengarkan di port acak
# — Redis, MySQL, panel debug — tidak otomatis terbuka ke internet hanya
# karena tidak ada aturan yang melarangnya.
#
# Ini menutup salah satu cara paling umum server dibobol: bukan lewat celah di
# aplikasi, tapi lewat layanan pendukung yang tidak sengaja terekspos. Redis
# tanpa kata sandi di port 6379 yang terbuka adalah akses tulis penuh ke
# server, dan pemindai internet menemukannya dalam hitungan jam.
ufw --force reset
ufw default deny incoming
ufw default allow outgoing

# -----------------------------------------------------------------------------
# Port yang dibuka
# -----------------------------------------------------------------------------
#
# SSH dibatasi dengan `limit`, bukan `allow`. Bedanya: `limit` memblokir IP
# yang membuka lebih dari 6 koneksi dalam 30 detik. Itu praktis mematikan
# brute force SSH di lapis kernel, dan berjalan bahkan bila fail2ban sedang
# mati.
ufw limit "${PORT_SSH}/tcp" comment 'SSH dengan batas laju'
ufw allow 80/tcp   comment 'HTTP'
ufw allow 443/tcp  comment 'HTTPS'

# -----------------------------------------------------------------------------
# Yang sengaja TIDAK dibuka
# -----------------------------------------------------------------------------
#
# MySQL (3306) dan Redis (6379) diakses aplikasi lewat 127.0.0.1 di mesin yang
# sama, jadi keduanya tidak perlu — dan tidak boleh — bisa dihubungi dari
# luar. Pastikan juga keduanya memang hanya mendengarkan di localhost:
#
#   ss -tlnp | grep -E '3306|6379'
#
# Yang benar terlihat sebagai `127.0.0.1:3306`. Bila muncul `0.0.0.0:3306`,
# layanannya mendengarkan ke seluruh dunia dan hanya firewall yang
# menahannya — perbaiki di konfigurasi layanannya, jangan bergantung pada
# satu lapis saja:
#   MySQL  : bind-address = 127.0.0.1   (/etc/mysql/mysql.conf.d/mysqld.cnf)
#   Redis  : bind 127.0.0.1 ::1         (/etc/redis/redis.conf)

echo ">> Menyalakan ufw"
ufw --force enable
ufw status verbose

# -----------------------------------------------------------------------------
# Perisai SYN flood di kernel
# -----------------------------------------------------------------------------
#
# SYN flood mengirim permintaan pembukaan koneksi tanpa pernah
# menyelesaikannya. Setiap satu menyisakan koneksi setengah terbuka yang
# menempati slot sampai kedaluwarsa, dan antrean itu habis jauh lebih cepat
# daripada bandwidth. Serangan ini murah sekali karena paketnya kecil dan
# alamat pengirimnya bisa dipalsukan.
#
# `syncookies` menjawabnya tanpa menyimpan apa pun: server mengirim balasan
# yang mengandung sidik jari terenkripsi, lalu melupakan koneksinya. Klien
# sungguhan mengembalikan sidik jari itu dan koneksinya dibangun; pengirim
# palsu tidak pernah membalas, dan tidak ada memori yang tersita.
echo ">> Menulis /etc/sysctl.d/99-dramaverse-keamanan.conf"
cat > /etc/sysctl.d/99-dramaverse-keamanan.conf <<'EOF'
# --- Perisai SYN flood ---
net.ipv4.tcp_syncookies = 1
net.ipv4.tcp_max_syn_backlog = 4096
net.ipv4.tcp_synack_retries = 2

# --- Antrean koneksi lebih besar ---
# Lonjakan sah (mis. broadcast Telegram ke banyak pengguna sekaligus) tidak
# boleh terlihat seperti serangan hanya karena antreannya kekecilan.
net.core.somaxconn = 4096
net.core.netdev_max_backlog = 4096

# --- Abaikan broadcast ICMP ---
# Menutup serangan pantulan Smurf: penyerang mengirim ping ke alamat broadcast
# dengan alamat pengirim dipalsukan menjadi alamat korban, dan seluruh jaringan
# membalas ke korban sekaligus.
net.ipv4.icmp_echo_ignore_broadcasts = 1
net.ipv4.icmp_ignore_bogus_error_responses = 1

# --- Tolak paket dengan alamat pengirim palsu ---
net.ipv4.conf.all.rp_filter = 1
net.ipv4.conf.default.rp_filter = 1

# --- Jangan terima source routing maupun pengalihan ICMP ---
# Keduanya membiarkan pihak lain menentukan jalur paket, dan tidak satu pun
# dibutuhkan server web biasa.
net.ipv4.conf.all.accept_source_route = 0
net.ipv4.conf.all.accept_redirects = 0
net.ipv4.conf.all.send_redirects = 0
net.ipv6.conf.all.accept_redirects = 0

# --- Percepat pembersihan koneksi menggantung ---
# Koneksi yang ditinggalkan dalam keadaan FIN-WAIT menempati slot. Serangan
# koneksi lambat memanfaatkan justru ini.
net.ipv4.tcp_fin_timeout = 20
net.ipv4.tcp_keepalive_time = 300

# --- Catat paket aneh ---
net.ipv4.conf.all.log_martians = 1
EOF

sysctl --system

echo
echo "Selesai. Periksa:"
echo "  ufw status verbose"
echo "  sysctl net.ipv4.tcp_syncookies    # harus = 1"
echo "  ss -tlnp | grep -E '3306|6379'    # harus 127.0.0.1, bukan 0.0.0.0"
