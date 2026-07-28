<section class="web-contact-faq">

    <div class="container">

        <div class="web-section-header">

            <span class="web-section-subtitle">

                FAQ

            </span>

            <h2 class="web-section-title">

                Pertanyaan Umum

            </h2>

        </div>

        <div class="web-contact-faq-list">

            @foreach([
                ['Bagaimana cara membeli Premium?','Pilih paket membership kemudian selesaikan proses pembayaran.'],
                ['Bagaimana cara menonton drama?','Cari drama di website lalu tekan tombol Tonton di Telegram.'],
                ['Apakah akun saya tersinkron?','Ya, history dan membership akan tersinkron dengan akun Telegram Anda.'],
                ['Bagaimana jika pembayaran gagal?','Silakan hubungi tim support melalui Telegram atau email resmi.']
            ] as $faq)

            <div class="web-contact-faq-item">

                <h3>

                    {{ $faq[0] }}

                </h3>

                <p>

                    {{ $faq[1] }}

                </p>

            </div>

            @endforeach

        </div>

    </div>

</section>