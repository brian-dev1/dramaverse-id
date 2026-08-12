-- =============================================================================
-- MySQL — pengguna dengan hak minimum
-- =============================================================================
--
-- Jalankan di server produksi:
--   mysql -u root -p < deploy/mysql-hak-minimum.sql
--
-- ## Masalah yang ditutup
--
-- `.env` saat ini memakai `DB_USERNAME=root`. Artinya aplikasi web —
-- bagian yang paling terbuka ke internet — memegang kendali penuh atas
-- seluruh server database.
--
-- Selama tidak ada celah, tidak ada bedanya. Bedanya baru muncul pada hari
-- ada celah, dan di situ perbedaannya besar sekali:
--
--   Sebagai root, satu SQL injection berarti seluruh basis data bisa dibaca,
--   diubah, dan dihapus; `FILE` memungkinkan membaca berkas di disk server
--   (termasuk `.env` dan kunci SSH) dan menulis berkas baru ke direktori web
--   — yaitu menanam cangkang PHP. Dari sana, seluruh server jatuh.
--
--   Sebagai pengguna terbatas, celah yang sama hanya menyentuh satu basis
--   data. Buruk, tapi bisa dipulihkan dari cadangan. Tidak ada `FILE`, tidak
--   ada `DROP`, tidak ada akses ke basis data lain, tidak ada jalan keluar
--   dari database menuju sistem berkas.
--
-- Ini bukan mencegah SQL injection — yang mencegahnya adalah query
-- berparameter di Eloquent, dan itu sudah terpasang. Ini membatasi seberapa
-- jauh kerusakannya kalau pencegahan itu suatu hari gagal. Dua hal yang
-- berbeda, dan keduanya diperlukan.
--
-- -----------------------------------------------------------------------------
-- GANTI DULU sebelum dijalankan
-- -----------------------------------------------------------------------------
--
-- Kata sandi di bawah adalah penampung. Buat yang acak dan panjang:
--   openssl rand -base64 32
--
SET @sandi_app = 'GANTI_DENGAN_KATA_SANDI_ACAK_PANJANG';

-- -----------------------------------------------------------------------------
-- Pengguna aplikasi
-- -----------------------------------------------------------------------------
--
-- Terikat ke `localhost`, bukan `%`. Aplikasi berjalan di mesin yang sama,
-- jadi tidak ada alasan kredensial ini bisa dipakai dari luar — dan bila
-- suatu hari bocor, ia tidak berguna bagi siapa pun yang tidak sudah berada
-- di dalam server.
CREATE USER IF NOT EXISTS 'dramaverse_app'@'localhost'
    IDENTIFIED BY 'GANTI_DENGAN_KATA_SANDI_ACAK_PANJANG';

-- Hanya empat hak, dan hanya pada satu basis data.
--
-- Yang sengaja TIDAK diberikan, beserta alasannya:
--
--   DROP, ALTER, CREATE  Perubahan skema dilakukan migrasi, dan migrasi
--                        dijalankan manual saat deploy — bukan oleh proses
--                        web yang melayani pengunjung. Lihat pengguna
--                        `dramaverse_migrasi` di bawah.
--   FILE                 Inilah yang mengubah SQL injection dari "data bocor"
--                        menjadi "server jatuh". `SELECT ... INTO OUTFILE`
--                        menulis berkas PHP ke direktori web; `LOAD_FILE()`
--                        membaca `.env` dan kunci SSH.
--   GRANT OPTION         Mencegah akun yang dibajak menaikkan haknya sendiri.
--   SUPER, PROCESS       PROCESS memperlihatkan query pengguna lain, termasuk
--                        yang mengandung kredensial.
GRANT SELECT, INSERT, UPDATE, DELETE
    ON `dramaverse`.* TO 'dramaverse_app'@'localhost';

-- -----------------------------------------------------------------------------
-- Pengguna migrasi
-- -----------------------------------------------------------------------------
--
-- Terpisah karena dipakai pada saat yang berbeda oleh proses yang berbeda.
-- `php artisan migrate` dijalankan tangan saat deploy; proses web tidak
-- pernah menjalankannya. Memberi hak DDL kepada pengguna yang melayani
-- pengunjung sepanjang hari berarti menanggung risikonya dua puluh empat jam
-- demi kebutuhan yang muncul beberapa menit sekali deploy.
--
-- Kredensialnya TIDAK masuk ke `.env`. Diberikan lewat variabel lingkungan
-- hanya saat menjalankan migrasi:
--
--   DB_USERNAME=dramaverse_migrasi DB_PASSWORD='...' php artisan migrate --force
--
CREATE USER IF NOT EXISTS 'dramaverse_migrasi'@'localhost'
    IDENTIFIED BY 'GANTI_DENGAN_KATA_SANDI_ACAK_LAIN';

GRANT SELECT, INSERT, UPDATE, DELETE,
      CREATE, ALTER, DROP, INDEX, REFERENCES
    ON `dramaverse`.* TO 'dramaverse_migrasi'@'localhost';

-- -----------------------------------------------------------------------------
-- Pengguna cadangan
-- -----------------------------------------------------------------------------
--
-- Hanya baca. mysqldump butuh LOCK TABLES; SHOW VIEW dan EVENT diperlukan
-- agar dump-nya lengkap dan bisa dipulihkan — cadangan yang tidak menyertakan
-- view baru ketahuan tidak lengkap pada saat paling buruk, yaitu saat
-- dipulihkan.
CREATE USER IF NOT EXISTS 'dramaverse_cadangan'@'localhost'
    IDENTIFIED BY 'GANTI_DENGAN_KATA_SANDI_ACAK_LAIN_LAGI';

GRANT SELECT, LOCK TABLES, SHOW VIEW, EVENT
    ON `dramaverse`.* TO 'dramaverse_cadangan'@'localhost';

FLUSH PRIVILEGES;

-- -----------------------------------------------------------------------------
-- Periksa hasilnya
-- -----------------------------------------------------------------------------
SHOW GRANTS FOR 'dramaverse_app'@'localhost';

-- -----------------------------------------------------------------------------
-- Setelah .env diperbarui dan situs terbukti jalan
-- -----------------------------------------------------------------------------
--
-- Kunci root supaya tidak bisa dihubungi dari luar mesin, dan bersihkan sisa
-- pemasangan yang selalu ditinggalkan MySQL:
--
--   DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost','127.0.0.1','::1');
--   DELETE FROM mysql.user WHERE User='';          -- pengguna anonim
--   DROP DATABASE IF EXISTS test;                  -- basis data uji bawaan
--   FLUSH PRIVILEGES;
--
-- Atau jalankan `mysql_secure_installation`, yang melakukan semuanya sambil
-- bertanya satu per satu.
--
-- JANGAN jalankan bagian ini sebelum situs terbukti berjalan dengan pengguna
-- baru. Urutan yang benar: buat pengguna, ubah .env, muat ulang, buka situs,
-- pastikan normal, baru kunci root.
