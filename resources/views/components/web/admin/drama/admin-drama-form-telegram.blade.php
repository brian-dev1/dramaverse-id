<div class="admin-card">

    <div class="admin-card-header">

        <div>

            <h2>Telegram</h2>

            <p>

                Atur bagaimana drama akan dipublikasikan ke Telegram.

            </p>

        </div>

    </div>

    <div class="admin-card-body">

        <div class="admin-form-grid">

            <div class="admin-form-group">

                <label>

                    Channel Telegram

                </label>

                <select
                    name="telegram_channel"
                    class="admin-select"
                >

                    <option value="">Pilih Channel</option>

                    <option>@dramaverse_id</option>

                    <option>@dramaverse_vip</option>

                    <option>@dramaverse_backup</option>

                </select>

            </div>

            <div class="admin-form-group">

                <label>

                    Jadwal Publish

                </label>

                <input
                    type="datetime-local"
                    name="publish_at"
                    class="admin-input"
                >

            </div>

        </div>

        <div class="telegram-setting-list">

            <label class="telegram-setting">

                <div>

                    <h4>Auto Publish</h4>

                    <p>

                        Drama akan otomatis dipost ke Telegram.

                    </p>

                </div>

                <input
                    type="checkbox"
                    name="auto_publish"
                    checked
                >

            </label>

            <label class="telegram-setting">

                <div>

                    <h4>Notifikasi Member</h4>

                    <p>

                        Mengirim pemberitahuan ke subscriber.

                    </p>

                </div>

                <input
                    type="checkbox"
                    name="notify_member"
                    checked
                >

            </label>

            <label class="telegram-setting">

                <div>

                    <h4>Pin Message</h4>

                    <p>

                        Otomatis pin postingan setelah terkirim.

                    </p>

                </div>

                <input
                    type="checkbox"
                    name="pin_message"
                >

            </label>

            <label class="telegram-setting">

                <div>

                    <h4>Publish Langsung</h4>

                    <p>

                        Lewati status draft dan langsung dipublikasikan.

                    </p>

                </div>

                <input
                    type="checkbox"
                    name="instant_publish"
                >

            </label>

        </div>

    </div>

</div>