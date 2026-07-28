<section class="web-admin-latest-payment">

    <div class="web-admin-card">

        <div class="web-admin-card-header">

            <h3>Transaksi Terbaru</h3>

        </div>

        <table class="web-admin-table">

            <thead>

                <tr>

                    <th>Invoice</th>
                    <th>User</th>
                    <th>Total</th>
                    <th>Status</th>

                </tr>

            </thead>

            <tbody>

                @for($i=1;$i<=6;$i++)

                <tr>

                    <td>DRV-000{{$i}}</td>

                    <td>Drama Lovers</td>

                    <td>Rp39.000</td>

                    <td>

                        <span class="status paid">

                            Paid

                        </span>

                    </td>

                </tr>

                @endfor

            </tbody>

        </table>

    </div>

</section>