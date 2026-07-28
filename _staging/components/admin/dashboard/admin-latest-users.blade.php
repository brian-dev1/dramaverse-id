<section class="web-admin-latest-users">

    <div class="web-admin-card">

        <div class="web-admin-card-header">

            <h3>User Terbaru</h3>

            <a href="#">Lihat Semua</a>

        </div>

        <table class="web-admin-table">

            <thead>

                <tr>
                    <th>Nama</th>
                    <th>Telegram</th>
                    <th>Membership</th>
                    <th>Status</th>
                </tr>

            </thead>

            <tbody>

                @for($i=1;$i<=6;$i++)

                <tr>

                    <td>Drama Lovers {{$i}}</td>

                    <td>@dramaverse{{$i}}</td>

                    <td>Premium</td>

                    <td>

                        <span class="status active">

                            Active

                        </span>

                    </td>

                </tr>

                @endfor

            </tbody>

        </table>

    </div>

</section>