<div class="admin-card">

    <div class="admin-card-header">

        <div>

            <h2>Telegram Distribution</h2>

            <p>

                Atur bagaimana episode akan dikirim ke Telegram.

            </p>

        </div>

    </div>

    <div class="admin-card-body">

        <div class="admin-form-grid">

            <div class="admin-form-group">

                <label>Channel Telegram</label>

                <select
                    name="telegram_channel"
                    class="admin-select"
                >

                    <option value="">Pilih Channel</option>

                    <option>@dramaverse_id</option>

                    <option>@dramaverse_premium</option>

                    <option>@dramaverse_vip</option>

                </select>

            </div>

            <div class="admin-form-group">

                <label>Mode Publish</label>

                <select
                    name="publish_mode"
                    class="admin-select"
                >

                    <option value="instant">Publish Sekarang</option>

                    <option value="schedule">Jadwalkan</option>

                    <option value="draft">Simpan Draft</option>

                </select>

            </div>

            <div class="admin-form-group">

                <label>Jadwal Publish</label>

                <input
                    type="datetime-local"
                    name="schedule_at"
                    class="admin-input"
                >

            </div>

            <div class="admin-form-group">

                <label>Caption Telegram</label>

                <textarea
                    name="telegram_caption"
                    rows="6"
                    class="admin-textarea"
                    placeholder="Tulis caption Telegram..."
                ></textarea>

            </div>

        </div>

        <hr class="admin-divider">

        <div class="telegram-option-list">

            <label class="telegram-option">

                <div>

                    <h4>Auto Publish</h4>

                    <p>

                        Kirim episode secara otomatis ke Telegram.

                    </p>

                </div>

                <input
                    type="checkbox"
                    name="auto_publish"
                    checked
                >

            </label>

            <label class="telegram-option">

                <div>

                    <h4>Pin Message</h4>

                    <p>

                        Pin postingan setelah berhasil dipublikasikan.

                    </p>

                </div>

                <input
                    type="checkbox"
                    name="pin_message"
                >

            </label>

            <label class="telegram-option">

                <div>

                    <h4>Notifikasi Member</h4>

                    <p>

                        Mengirim pemberitahuan kepada pelanggan.

                    </p>

                </div>

                <input
                    type="checkbox"
                    name="notify_member"
                    checked
                >

            </label>

            <label class="telegram-option">

                <div>

                    <h4>Aktifkan Preview</h4>

                    <p>

                        Tampilkan preview media pada pesan Telegram.

                    </p>

                </div>

                <input
                    type="checkbox"
                    name="show_preview"
                    checked
                >

            </label>

        </div>

        <div class="telegram-status">

            <div class="status-card success">

                <i class="ri-checkbox-circle-line"></i>

                <div>

                    <strong>Status Bot</strong>

                    <span>Bot siap digunakan.</span>

                </div>

            </div>

            <div class="status-card">

                <i class="ri-information-line"></i>

                <div>

                    <strong>Status Publish</strong>

                    <span>Belum dipublikasikan.</span>

                </div>

            </div>

        </div>

    </div>

</div>