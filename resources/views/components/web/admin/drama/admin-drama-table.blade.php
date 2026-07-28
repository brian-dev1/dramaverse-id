<section class="web-admin-drama-table">

    <table class="web-admin-table">

        <thead>

            <tr>

                <th>Poster</th>

                <th>Judul</th>

                <th>Negara</th>

                <th>Genre</th>

                <th>Episode</th>

                <th>Status</th>

                <th>Aksi</th>

            </tr>

        </thead>

        <tbody>

            @for($i=1;$i<=10;$i++)

            <tr>

                <td>

                    <img
                        src="https://placehold.co/60x90"
                        alt=""
                    >

                </td>

                <td>

                    Hidden Love

                </td>

                <td>

                    China

                </td>

                <td>

                    Romance

                </td>

                <td>

                    25

                </td>

                <td>

                    <span class="status active">

                        Completed

                    </span>

                </td>

                <td>

                    <button>👁</button>

                    <button>✏</button>

                    <button>🗑</button>

                </td>

            </tr>

            @endfor

        </tbody>

    </table>

</section>