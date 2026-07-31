# Dokumentasi API

Cakupannya **sengaja sempit**: hanya endpoint yang benar-benar dipanggil
frontend. Tidak ada API publik, tidak ada token bearer, tidak ada versi kedua.

Autentikasi memakai **sesi**, bukan token. Seluruh endpoint di bawah `/api/v1`
kecuali pencarian mensyaratkan sesi login yang aktif.

Awalan: `/api/v1` — nama route `api.v1.*` — rate limit: `throttle:api`.

## Publik

### `GET /api/v1/search`

Pencarian realtime untuk kotak cari.

| Parameter | Wajib | Keterangan |
|---|---|---|
| `q` | ya | Kata kunci, minimal dua huruf |

Mengembalikan daftar drama ringkas. Kosong bila tidak ada yang cocok — bukan
404.

## Butuh sesi login

Middleware: `auth`, `active`. Pengguna yang diblokir ditolak `active`.

### `POST /api/v1/player/progress`

Menyimpan posisi tontonan. Dipanggil pemutar secara berkala.

| Parameter | Keterangan |
|---|---|
| `episode_id` | Episode yang sedang ditonton |
| `progress` | Detik ke berapa |

### `GET /api/v1/player/resume/{episode}`

Posisi terakhir untuk episode itu.

### `POST /api/v1/player/completed/{episode}`

Menandai episode selesai ditonton.

### `GET /api/v1/notifications`

Notifikasi milik pengguna yang sedang masuk.

## Webhook

### `POST /telegram/webhook`

Dipanggil Telegram, bukan frontend. Dikecualikan dari CSRF dan dijaga
middleware `telegram.webhook` yang membandingkan
`X-Telegram-Bot-Api-Secret-Token` dengan `hash_equals`.

Selalu menjawab `200 {"ok":true}` — termasuk saat pemrosesannya gagal.
Jawaban selain 2xx membuat Telegram mengirim ulang update yang sama
berulang-ulang, dan sebabnya tidak akan pernah berubah.

### `POST /payment/callback/{provider}`

Dipanggil payment gateway. Tanda tangannya diverifikasi di dalam driver
masing-masing; yang tidak cocok ditolak dan **tidak diproses sama sekali**.

Idempoten: callback yang sama datang berkali-kali tidak mengaktifkan
membership dua kali.

## Kesehatan

### `GET /up`

Endpoint bawaan Laravel. Menjawab 200 bila aplikasi bisa melayani permintaan.
Dipakai monitoring luar.
