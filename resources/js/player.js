document.addEventListener("DOMContentLoaded", () => {

    const video = document.querySelector(".web-video-player");

    if (!video) return;

    const nextUrl = document.body.dataset.nextEpisode;

    if (!nextUrl) return;

    video.addEventListener("ended", () => {

        const overlay = document.createElement("div");

        overlay.className = "player-next-overlay";

        overlay.innerHTML = `
            <div class="player-next-box">

                <h2>Episode Selesai</h2>

                <p>Episode berikutnya akan diputar dalam</p>

                <div id="countdown">5</div>

                <div class="player-next-action">

                    <button id="cancelNext">
                        Batal
                    </button>

                    <a href="${nextUrl}">
                        Tonton Sekarang
                    </a>

                </div>

            </div>
        `;

        document.body.appendChild(overlay);

        let countdown = 5;

        const timer = setInterval(() => {

            countdown--;

            document.getElementById("countdown").textContent = countdown;

            if (countdown <= 0) {

                clearInterval(timer);

                window.location.href = nextUrl;

            }

        },1000);

        document
            .getElementById("cancelNext")
            .onclick = () => {

                clearInterval(timer);

                overlay.remove();

            };

    });

});