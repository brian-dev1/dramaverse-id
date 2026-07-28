<div class="admin-card">

    <div class="admin-card-header">

        <div>

            <h2>Publish Episode</h2>

            <p>

                Tentukan status episode sebelum disimpan.

            </p>

        </div>

    </div>

    <div class="admin-card-body">

        <div class="publish-grid">

            <label class="publish-card">

                <input
                    type="radio"
                    name="status"
                    value="draft"
                    checked
                >

                <div class="publish-icon">

                    <i class="ri-draft-line"></i>

                </div>

                <h3>Draft</h3>

                <p>

                    Episode disimpan namun belum bisa diakses pengguna.

                </p>

            </label>

            <label class="publish-card">

                <input
                    type="radio"
                    name="status"
                    value="scheduled"
                >

                <div class="publish-icon">

                    <i class="ri-calendar-schedule-line"></i>

                </div>

                <h3>Scheduled</h3>

                <p>

                    Episode akan dipublikasikan sesuai jadwal yang ditentukan.

                </p>

            </label>

            <label class="publish-card">

                <input
                    type="radio"
                    name="status"
                    value="published"
                >

                <div class="publish-icon">

                    <i class="ri-send-plane-2-line"></i>

                </div>

                <h3>Published</h3>

                <p>

                    Episode langsung tersedia untuk pengguna dan Telegram.

                </p>

            </label>

        </div>

        <div class="publish-information">

            <div class="info-item">

                <i class="ri-time-line"></i>

                <div>

                    <strong>Waktu Dibuat</strong>

                    <span>Otomatis saat data disimpan.</span>

                </div>

            </div>

            <div class="info-item">

                <i class="ri-user-line"></i>

                <div>

                    <strong>Dibuat Oleh</strong>

                    <span>Administrator yang sedang login.</span>

                </div>

            </div>

        </div>

        <div class="publish-footer">

            <a
                href="{{ route('admin.episode.index') }}"
                class="btn btn-light"
            >

                Batal

            </a>

            <button
                type="submit"
                class="btn btn-primary"
            >

                <i class="ri-save-line"></i>

                Simpan Episode

            </button>

        </div>

    </div>

</div>