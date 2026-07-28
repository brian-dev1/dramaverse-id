<div class="admin-card">

    <div class="admin-card-header">

        <div>

            <h2>Informasi Dasar</h2>

            <p>
                Informasi utama mengenai drama yang akan ditambahkan.
            </p>

        </div>

    </div>

    <div class="admin-card-body">

        <div class="admin-form-grid">

            <div class="admin-form-group">

                <label for="title">

                    Judul Drama

                    <span>*</span>

                </label>

                <input
                    type="text"
                    id="title"
                    name="title"
                    placeholder="Contoh : Hidden Love"
                    class="admin-input"
                >

            </div>

            <div class="admin-form-group">

                <label for="original_title">

                    Judul Asli

                </label>

                <input
                    type="text"
                    id="original_title"
                    name="original_title"
                    placeholder="偷偷藏不住"
                    class="admin-input"
                >

            </div>

        </div>

        <div class="admin-form-grid">

            <div class="admin-form-group">

                <label for="slug">

                    Slug Internal

                </label>

                <input
                    type="text"
                    id="slug"
                    name="slug"
                    placeholder="hidden-love"
                    class="admin-input"
                >

            </div>

            <div class="admin-form-group">

                <label for="type">

                    Tipe Drama

                </label>

                <select
                    id="type"
                    name="type"
                    class="admin-select"
                >

                    <option value="">Pilih</option>

                    <option>Series</option>

                    <option>Movie</option>

                    <option>Special</option>

                </select>

            </div>

        </div>

        <div class="admin-form-grid">

            <div class="admin-form-group">

                <label for="status">

                    Status

                </label>

                <select
                    id="status"
                    name="status"
                    class="admin-select"
                >

                    <option>Completed</option>

                    <option>Ongoing</option>

                    <option>Coming Soon</option>

                </select>

            </div>

            <div class="admin-form-group">

                <label for="release_year">

                    Tahun Rilis

                </label>

                <input
                    type="number"
                    id="release_year"
                    name="release_year"
                    placeholder="2026"
                    class="admin-input"
                >

            </div>

        </div>

        <div class="admin-form-grid">

            <div class="admin-form-group">

                <label for="episode">

                    Jumlah Episode

                </label>

                <input
                    type="number"
                    id="episode"
                    name="episode"
                    placeholder="36"
                    class="admin-input"
                >

            </div>

            <div class="admin-form-group">

                <label for="duration">

                    Durasi

                </label>

                <input
                    type="text"
                    id="duration"
                    name="duration"
                    placeholder="45 Menit"
                    class="admin-input"
                >

            </div>

        </div>

        <div class="admin-form-group">

            <label for="synopsis">

                Sinopsis

            </label>

            <textarea
                id="synopsis"
                name="synopsis"
                rows="8"
                class="admin-textarea"
                placeholder="Masukkan sinopsis drama..."
            ></textarea>

        </div>

    </div>

</div>