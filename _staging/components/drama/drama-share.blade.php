@props([
    'drama',
])

<div class="web-share-card">

    <h3>

        Bagikan Drama

    </h3>

    <div class="web-share-grid">

        <a
            href="https://t.me/share/url?url={{ urlencode(request()->url()) }}"
            target="_blank"
            class="web-share-btn telegram">

            Telegram

        </a>

        <a
            href="https://wa.me/?text={{ urlencode(request()->url()) }}"
            target="_blank"
            class="web-share-btn whatsapp">

            WhatsApp

        </a>

        <button
            onclick="navigator.clipboard.writeText('{{ request()->url() }}')"
            class="web-share-btn copy">

            Copy Link

        </button>

    </div>

</div>