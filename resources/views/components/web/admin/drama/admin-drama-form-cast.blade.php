<div class="admin-card">

    <div class="admin-card-header">

        <div>

            <h2>Cast & Crew</h2>

            <p>

                Tambahkan daftar pemeran utama drama.

            </p>

        </div>

        <button
            type="button"
            class="btn-add-cast"
        >

            <i class="ri-add-line"></i>

            Tambah Pemeran

        </button>

    </div>

    <div class="admin-card-body">

        <div class="cast-list">

            <div class="cast-item">

                <div class="cast-photo">

                    <i class="ri-user-3-line"></i>

                    <input
                        type="file"
                        name="cast_photo[]"
                        accept="image/*"
                    >

                </div>

                <div class="cast-information">

                    <div class="admin-form-grid">

                        <div class="admin-form-group">

                            <label>

                                Nama Aktor

                            </label>

                            <input
                                type="text"
                                name="cast_name[]"
                                class="admin-input"
                                placeholder="Contoh : Zhao Lusi"
                            >

                        </div>

                        <div class="admin-form-group">

                            <label>

                                Nama Karakter

                            </label>

                            <input
                                type="text"
                                name="character_name[]"
                                class="admin-input"
                                placeholder="Contoh : Sang Zhi"
                            >

                        </div>

                    </div>

                    <div class="admin-form-grid">

                        <div class="admin-form-group">

                            <label>

                                Peran

                            </label>

                            <select
                                name="cast_role[]"
                                class="admin-select"
                            >

                                <option>Main Cast</option>

                                <option>Supporting Cast</option>

                                <option>Guest Star</option>

                            </select>

                        </div>

                        <div class="admin-form-group">

                            <label>

                                Urutan Tampil

                            </label>

                            <input
                                type="number"
                                name="cast_order[]"
                                class="admin-input"
                                placeholder="1"
                            >

                        </div>

                    </div>

                </div>

                <button
                    type="button"
                    class="btn-delete-cast"
                >

                    <i class="ri-delete-bin-6-line"></i>

                </button>

            </div>

        </div>

    </div>

</div>