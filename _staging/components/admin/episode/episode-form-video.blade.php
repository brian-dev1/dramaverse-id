<div class="admin-card">

    <div class="admin-card-header">

        <div>

            <h2>Video Cloud</h2>

            <p>

                Hubungkan episode dengan video yang telah tersimpan pada Cloud Storage.

            </p>

        </div>

    </div>

    <div class="admin-card-body">

        <div class="admin-form-grid">

            <div class="admin-form-group">

                <label>Cloud Provider</label>

                <select
                    name="cloud_provider"
                    class="admin-select"
                >

                    <option value="">Pilih Provider</option>

                    <option value="r2">Cloudflare R2</option>

                    <option value="s3">Amazon S3</option>

                    <option value="b2">Backblaze B2</option>

                    <option value="wasabi">Wasabi</option>

                    <option value="custom">Custom Storage</option>

                </select>

            </div>

            <div class="admin-form-group">

                <label>Video ID</label>

                <input
                    type="text"
                    name="video_id"
                    class="admin-input"
                    placeholder="Contoh: VID-2026-000001"
                >

            </div>

            <div class="admin-form-group">

                <label>Object Key</label>

                <input
                    type="text"
                    name="object_key"
                    class="admin-input"
                    placeholder="drama/hidden-love/episode-01.mp4"
                >

            </div>

            <div class="admin-form-group">

                <label>Folder</label>

                <input
                    type="text"
                    name="folder"
                    class="admin-input"
                    placeholder="drama/hidden-love/"
                >

            </div>

            <div class="admin-form-group">

                <label>Resolusi</label>

                <select
                    name="resolution"
                    class="admin-select"
                >

                    <option>360p</option>

                    <option>480p</option>

                    <option>720p</option>

                    <option selected>1080p</option>

                    <option>1440p</option>

                    <option>4K</option>

                </select>

            </div>

            <div class="admin-form-group">

                <label>Ukuran File</label>

                <input
                    type="text"
                    name="file_size"
                    class="admin-input"
                    placeholder="1.4 GB"
                >

            </div>

            <div class="admin-form-group">

                <label>Format Video</label>

                <select
                    name="format"
                    class="admin-select"
                >

                    <option>MP4</option>

                    <option>MKV</option>

                    <option>MOV</option>

                    <option>AVI</option>

                </select>

            </div>

            <div class="admin-form-group">

                <label>Status Sinkronisasi</label>

                <select
                    name="sync_status"
                    class="admin-select"
                >

                    <option value="connected">Connected</option>

                    <option value="pending">Pending</option>

                    <option value="failed">Failed</option>

                </select>

            </div>

        </div>

        <div class="video-cloud-action">

            <button
                type="button"
                class="btn btn-light"
            >

                <i class="ri-links-line"></i>

                Validasi Video

            </button>

            <button
                type="button"
                class="btn btn-primary"
            >

                <i class="ri-refresh-line"></i>

                Sinkronkan Metadata

            </button>

        </div>

    </div>

</div>