<section class="web-membership-payment">

    <div class="container">

        <div class="web-payment-wrapper">

            <div class="web-payment-left">

                <span class="web-section-subtitle">

                    Payment

                </span>

                <h2 class="web-section-title">

                    Pilih Metode Pembayaran

                </h2>

                <p>

                    Pilih metode pembayaran favoritmu untuk mengaktifkan
                    DramaVerse Premium.

                </p>

            </div>

            <div class="web-payment-methods">

                @foreach([
                    'QRIS',
                    'GoPay',
                    'OVO',
                    'DANA',
                    'ShopeePay',
                    'Bank Transfer',
                    'Virtual Account',
                    'Credit Card'
                ] as $method)

                <div class="web-payment-item">

                    <span>{{ $method }}</span>

                    <input type="radio" name="payment">

                </div>

                @endforeach

            </div>

            <button class="web-payment-button">

                Lanjutkan Pembayaran

            </button>

        </div>

    </div>

</section>