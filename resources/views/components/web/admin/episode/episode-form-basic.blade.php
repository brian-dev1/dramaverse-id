<div class="admin-card">

    <div class="admin-card-header">

        <div>

            <h2>Informasi Episode</h2>

            <p>

                Informasi dasar mengenai episode yang akan ditambahkan.

            </p>

        </div>

    </div>

    <div class="admin-card-body">

        <div class="admin-form-grid">

            <div class="admin-form-group">

                <label>Drama</label>

                <select
                    name="drama_id"
                    class="admin-select"
                >

                    <option value="">Pilih Drama</option>

                </select>

            </div>

            <div class="admin-form-group">

                <label>Nomor Episode</label>

                <input
                    type="number"
                    name="episode_number"
                    class="admin-input"
                    min="1"
                    placeholder="Contoh: 1"
                >

            </div>

            <div class="admin-form-group">

                <label>Judul Episode</label>

                <input
                    type="text"
                    name="title"
                    class="admin-input"
                    placeholder="Masukkan judul episode"
                >

            </div>

            <div class="admin-form-group">

                <label>Durasi</label>

                <input
                    type="text"
                    name="duration"
                    class="admin-input"
                    placeholder="Contoh: 45 Menit"
                >

            </div>

        </div>

        <div class="admin-form-group">

            <label>Deskripsi Episode</label>

            <textarea
                name="description"
                rows="5"
                class="admin-textarea"
                placeholder="Masukkan deskripsi singkat episode..."
            ></textarea>

        </div>

    </div>

</div>