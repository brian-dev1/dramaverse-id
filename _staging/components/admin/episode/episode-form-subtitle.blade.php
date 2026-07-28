<div class="admin-card">

    <div class="admin-card-header">

        <div>

            <h2>Subtitle</h2>

            <p>

                Tambahkan subtitle untuk episode ini.

            </p>

        </div>

    </div>

    <div class="admin-card-body">

        <div class="subtitle-list">

            <div class="subtitle-item">

                <div class="admin-form-grid">

                    <div class="admin-form-group">

                        <label>Bahasa</label>

                        <select
                            name="subtitle_language[]"
                            class="admin-select"
                        >

                            <option value="">Pilih Bahasa</option>

                            <option value="id">Indonesia</option>

                            <option value="en">English</option>

                            <option value="zh">Chinese</option>

                            <option value="ko">Korean</option>

                            <option value="ja">Japanese</option>

                        </select>

                    </div>

                    <div class="admin-form-group">

                        <label>Format</label>

                        <select
                            name="subtitle_format[]"
                            class="admin-select"
                        >

                            <option>SRT</option>

                            <option>VTT</option>

                            <option>ASS</option>

                        </select>

                    </div>

                    <div class="admin-form-group">

                        <label>Subtitle Path</label>

                        <input
                            type="text"
                            name="subtitle_path[]"
                            class="admin-input"
                            placeholder="subtitle/hidden-love/episode-01-id.srt"
                        >

                    </div>

                </div>

                <div class="subtitle-action">

                    <button
                        type="button"
                        class="btn btn-danger"
                    >

                        <i class="ri-delete-bin-line"></i>

                        Hapus

                    </button>

                </div>

            </div>

        </div>

        <div class="subtitle-footer">

            <button
                type="button"
                class="btn btn-primary"
            >

                <i class="ri-add-line"></i>

                Tambah Subtitle

            </button>

        </div>

    </div>

</div>