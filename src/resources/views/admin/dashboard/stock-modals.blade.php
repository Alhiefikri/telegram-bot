                @if ($canManageProducts)
                {{-- ════════════════════════════════════════════
                     STOCK INTAKE + MANAGEMENT
                     ════════════════════════════════════════════ --}}
                <x-admin-dashboard.modal-backdrop show="stockModalOpen" close="closeStockModal()">
                    <x-admin-dashboard.modal-shell
                        id="stock-modal"
                        show="stockModalOpen"
                        title="Tambah stok"
                        description="Input Cepat tetap untuk stok legacy. Input Akun membuat satu akun = satu stok."
                        size="2xl"
                    >
                        @if ($structuredStockInputEnabled)
                        <div class="mb-4 grid grid-cols-1 gap-2 sm:grid-cols-2" role="tablist" aria-label="Mode input stok">
                            <button
                                id="stock-input-quick-tab"
                                type="button"
                                role="tab"
                                x-bind:aria-selected="stockInputMode === 'quick'"
                                aria-controls="stock-input-quick-panel"
                                x-bind:tabindex="stockInputMode === 'quick' ? '0' : '-1'"
                                x-bind:class="stockInputMode === 'quick' ? 'bg-admin-accent-soft text-admin-accent-strong' : 'bg-admin-surface text-admin-ink'"
                                data-stock-input-mode="quick"
                                x-on:click="setStockInputMode('quick')"
                                x-on:keydown="handleStockInputTabKeydown($event)"
                                class="min-h-11 rounded-lg border border-admin-line px-3 py-2 text-sm font-medium transition-colors focus:outline-none focus:ring-2 focus:ring-admin-focus focus:ring-offset-2 focus:ring-offset-admin-paper"
                            >
                                Input Cepat
                            </button>
                            <button
                                id="stock-input-account-tab"
                                type="button"
                                role="tab"
                                x-bind:aria-selected="stockInputMode === 'account'"
                                aria-controls="stock-input-account-panel"
                                x-bind:tabindex="stockInputMode === 'account' ? '0' : '-1'"
                                x-bind:class="stockInputMode === 'account' ? 'bg-admin-accent-soft text-admin-accent-strong' : 'bg-admin-surface text-admin-ink'"
                                data-stock-input-mode="account"
                                x-on:click="setStockInputMode('account')"
                                x-on:keydown="handleStockInputTabKeydown($event)"
                                class="min-h-11 rounded-lg border border-admin-line px-3 py-2 text-sm font-medium transition-colors focus:outline-none focus:ring-2 focus:ring-admin-focus focus:ring-offset-2 focus:ring-offset-admin-paper"
                            >
                                Input Akun
                            </button>
                        </div>
                        @endif

                        <div
                            @if ($structuredStockInputEnabled)
                                id="stock-input-quick-panel"
                                role="tabpanel"
                                aria-labelledby="stock-input-quick-tab"
                                tabindex="0"
                            @endif
                            x-show="stockInputMode === 'quick'"
                            x-cloak
                        >
                            <form method="POST" x-bind:action="stockFormAction" x-on:submit="submitStockForm($event)" class="space-y-3">
                                @csrf
                                <input type="hidden" name="stock_product_id" x-bind:value="stockProductId">
                                <input type="hidden" name="operation_key" x-bind:value="stockOperationKey">

                                <div class="flex flex-wrap items-center justify-between gap-2 text-sm text-admin-ink">
                                    <span class="max-w-[13rem] truncate font-medium" x-text="stockProductName">Produk</span>
                                    <span class="text-admin-muted" x-text="`${stockItemCount()} item`">0 item</span>
                                </div>

                                <div class="rounded-lg border border-admin-line bg-admin-surface-muted p-3">
                                    <x-admin-dashboard.field-label for="stock-format">Format setiap baris</x-admin-dashboard.field-label>
                                    <x-admin-dashboard.select id="stock-format" name="stock_format" x-model="stockInputFormat" class="mt-1.5">
                                        <option value="legacy">Teks biasa / legacy</option>
                                        <option value="email_password_mfa_secret">email | password | MFA secret</option>
                                        <option value="email_password_access_token" x-bind:disabled="!structuredStockInputEnabled">email | password | akses token</option>
                                        <option value="email_password_reader_mail_link" x-bind:disabled="!structuredStockInputEnabled">email | password | akses token | link reader mail</option>
                                    </x-admin-dashboard.select>
                                    <p class="mt-1.5 text-xs leading-5 text-admin-muted">
                                        Pilihan ini berlaku untuk semua baris yang diinput. Setiap baris harus berisi satu credential.
                                    </p>
                                    <p x-show="stockInputFormat === 'email_password_access_token'" class="mt-1 text-xs leading-5 text-admin-muted">
                                        Untuk akses token, semua isi setelah <code>email|password|</code> dipertahankan sebagai satu nilai, termasuk bagian yang memakai tanda <code>|</code>.
                                    </p>
                                    <p x-show="stockInputFormat === 'email_password_reader_mail_link'" class="mt-1 text-xs leading-5 text-admin-muted">
                                        Format ini menerima bentuk ringkas <code>email|password|token|link</code>, ekspor tujuh field <code>email|password|email|password|token|UUID|link</code>, atau varian ekspor yang menambahkan email mailbox setelah UUID.
                                    </p>
                                    <p x-show="stockInputFormat === 'email_password_access_token' && !structuredStockInputEnabled || stockInputFormat === 'email_password_reader_mail_link' && !structuredStockInputEnabled" class="mt-1.5 text-xs font-medium text-neo-danger-text">
                                        Format akses token menunggu pemeriksaan kesiapan identitas.
                                    </p>
                                </div>

                                <div>
                                    <x-admin-dashboard.textarea
                                        id="stock-items"
                                        name="stock_items"
                                        rows="6"
                                        x-bind:placeholder="stockInputFormat === 'email_password_mfa_secret' ? 'email@example.com|password|MFA-SECRET' + String.fromCharCode(10) + 'akun2@example.com|password2|MFA-SECRET-2' : (stockInputFormat === 'email_password_reader_mail_link' ? 'email@example.com|password|ACCESS-TOKEN|https://mail.example.com/reader/abc' + String.fromCharCode(10) + 'akun2@example.com|password2|ACCESS-TOKEN-2|https://mail.example.com/reader/def' : (stockInputFormat === 'email_password_access_token' ? 'email@example.com|password|ACCESS-TOKEN|CLIENT-ID|recovery@example.com' + String.fromCharCode(10) + 'akun2@example.com|password2|ACCESS-TOKEN-2' : 'email@example.com|password' + String.fromCharCode(10) + 'KEY-XXXX-1234'))"
                                        x-model="stockItems"
                                        x-ref="stockItems"
                                        required
                                    />
                                    <p class="mt-1.5 text-xs leading-5 text-admin-muted">Tidak ada batas total karakter pada Input Cepat. Tempel satu baris untuk setiap stok; server tetap memvalidasi format dan link tiap baris.</p>

                                    @error('stock_items', 'stockAdjustment')
                                        <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error>
                                    @enderror
                                </div>

                                <div class="grid grid-cols-1 gap-2 pt-1 sm:grid-cols-2">
                                    <x-admin-dashboard.button type="button" variant="neutral" x-on:click="closeStockModal()" x-bind:disabled="stockFormSubmitting">
                                        Batal
                                    </x-admin-dashboard.button>
                                    <x-admin-dashboard.button type="submit" variant="primary" x-bind:disabled="stockFormSubmitting" x-bind:class="{ 'cursor-wait opacity-70': stockFormSubmitting }">
                                        <span x-text="stockFormSubmitting ? 'Menyimpan...' : 'Simpan'">Simpan</span>
                                    </x-admin-dashboard.button>
                                </div>
                            </form>
                        </div>

                        @if ($structuredStockInputEnabled)
                        <div id="stock-input-account-panel" role="tabpanel" aria-labelledby="stock-input-account-tab" tabindex="0" x-show="stockInputMode === 'account'" x-cloak>
                            <form method="POST" x-bind:action="stockAccountsFormAction" x-on:submit="submitAccountInput($event)" class="space-y-4">
                                @csrf
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <p class="text-sm font-semibold text-admin-ink">Akun terstruktur</p>
                                        <p class="mt-1 max-w-2xl text-xs leading-5 text-admin-muted">Satu kartu selalu menjadi satu Product Stock Item. Password, token, dan tutorial boleh multiline tanpa menambah jumlah stok.</p>
                                    </div>
                                    <x-admin-dashboard.badge tone="purple"><span x-text="`${accountCards.length} / 20 akun`">1 / 20 akun</span></x-admin-dashboard.badge>
                                </div>

                                <x-admin-dashboard.notice tone="warning" x-show="accountInputMessage" x-text="accountInputMessage" role="alert" aria-live="assertive" />

                                <div x-show="accountInputStage === 'edit'" class="space-y-3">
                                    <template x-for="(account, index) in accountCards" :key="account.id">
                                        <article class="rounded-lg border border-admin-line bg-admin-surface p-3">
                                            <div class="flex items-start justify-between gap-3">
                                                <div>
                                                    <p class="text-sm font-semibold text-admin-ink" x-text="`Akun #${index + 1}`">Akun #1</p>
                                                    <p class="mt-1 text-xs text-admin-muted">Wajib email + password. Pilih paling banyak satu metode akses.</p>
                                                </div>
                                                <x-admin-dashboard.button
                                                    type="button"
                                                    variant="neutral"
                                                    size="sm"
                                                    class="shrink-0"
                                                    x-on:click="removeAccountCard(index)"
                                                    x-bind:disabled="accountCards.length === 1"
                                                    x-bind:aria-label="`Hapus akun #${index + 1}`"
                                                >
                                                    Hapus
                                                </x-admin-dashboard.button>
                                            </div>

                                            <div class="mt-3 grid gap-3 md:grid-cols-2">
                                                <div>
                                                    <label x-bind:for="`account-email-${index}`" class="text-sm font-medium text-admin-ink">Email</label>
                                                    <x-admin-dashboard.input
                                                        x-bind:id="`account-email-${index}`"
                                                        x-bind:name="`accounts[${index}][email]`"
                                                        type="email"
                                                        autocomplete="off"
                                                        maxlength="254"
                                                        x-model="account.email"
                                                        x-on:input="accountInputChanged()"
                                                        x-bind:class="accountInputErrorClass(index, 'email')"
                                                        x-bind:aria-invalid="accountInputError(index, 'email') ? 'true' : 'false'"
                                                        x-bind:aria-describedby="accountInputError(index, 'email') ? 'account-email-error-' + index : null"
                                                        class="mt-1.5"
                                                    />
                                                    <x-admin-dashboard.field-error x-show="accountInputError(index, 'email')" x-bind:id="'account-email-error-' + index" x-text="accountInputError(index, 'email')" role="alert" aria-live="polite"></x-admin-dashboard.field-error>
                                                </div>
                                                <div>
                                                    <label x-bind:for="`account-password-${index}`" class="text-sm font-medium text-admin-ink">Password</label>
                                                    <x-admin-dashboard.textarea
                                                        x-bind:id="`account-password-${index}`"
                                                        x-bind:name="`accounts[${index}][password]`"
                                                        autocomplete="new-password"
                                                        maxlength="1024"
                                                        rows="2"
                                                        x-model="account.password"
                                                        x-on:input="accountInputChanged()"
                                                        x-bind:class="accountInputErrorClass(index, 'password')"
                                                        x-bind:aria-invalid="accountInputError(index, 'password') ? 'true' : 'false'"
                                                        x-bind:aria-describedby="accountInputError(index, 'password') ? 'account-password-error-' + index : null"
                                                        class="mt-1.5 min-h-20 font-mono"
                                                    ></x-admin-dashboard.textarea>
                                                    <x-admin-dashboard.field-error x-show="accountInputError(index, 'password')" x-bind:id="'account-password-error-' + index" x-text="accountInputError(index, 'password')" role="alert" aria-live="polite"></x-admin-dashboard.field-error>
                                                </div>
                                            </div>

                                            <fieldset class="mt-3" x-bind:aria-invalid="accountInputError(index, 'access_method') ? 'true' : 'false'" x-bind:aria-describedby="accountInputError(index, 'access_method') ? 'account-access-method-error-' + index : null">
                                                <legend class="text-sm font-medium text-admin-ink">Metode akses</legend>
                                                <div class="mt-1.5 grid gap-2 sm:grid-cols-3">
                                                    <template x-for="method in ['none', 'otp_mail_link', 'auth_token']" :key="method">
                                                        <label class="flex min-h-11 cursor-pointer items-center gap-2 rounded-lg border border-admin-line bg-admin-surface-muted px-2.5 py-2 text-sm text-admin-ink">
                                                            <input
                                                                type="radio"
                                                                x-bind:name="`accounts[${index}][access_method]`"
                                                                x-bind:value="method"
                                                                x-model="account.accessMethod"
                                                                x-on:change="setAccountAccessMethod(index, $event.target.value)"
                                                                class="h-4 w-4 border border-admin-line accent-admin-accent focus:ring-2 focus:ring-admin-focus"
                                                            >
                                                            <span x-text="accountAccessLabel(method)">Tidak ada</span>
                                                        </label>
                                                    </template>
                                                </div>
                                                <x-admin-dashboard.field-error x-show="accountInputError(index, 'access_method')" x-bind:id="'account-access-method-error-' + index" x-text="accountInputError(index, 'access_method')" role="alert" aria-live="polite"></x-admin-dashboard.field-error>
                                            </fieldset>

                                            <div x-show="account.accessMethod !== 'none'" class="mt-3">
                                                <label x-bind:for="`account-access-value-${index}`" x-text="account.accessMethod === 'otp_mail_link' ? 'OTP Mail Link (HTTPS)' : 'Auth Token'" class="text-sm font-medium text-admin-ink">Auth Token</label>
                                                <x-admin-dashboard.input
                                                    x-bind:id="`account-access-value-${index}`"
                                                    x-bind:name="`accounts[${index}][access_value]`"
                                                    x-bind:type="account.accessMethod === 'otp_mail_link' ? 'url' : 'text'"
                                                    x-bind:autocomplete="account.accessMethod === 'otp_mail_link' ? 'off' : 'new-password'"
                                                    x-bind:maxlength="account.accessMethod === 'otp_mail_link' ? 2048 : 3500"
                                                    x-model="account.accessValue"
                                                    x-on:input="accountInputChanged()"
                                                    x-bind:class="accountInputErrorClass(index, 'access_value')"
                                                    x-bind:aria-invalid="accountInputError(index, 'access_value') ? 'true' : 'false'"
                                                    x-bind:aria-describedby="accountInputError(index, 'access_value') ? 'account-access-value-error-' + index : null"
                                                    class="mt-1.5"
                                                />
                                                <p x-show="account.accessMethod === 'otp_mail_link' && accountAccessDomain(account)" class="mt-1 text-xs text-admin-muted">Domain tujuan: <span x-text="accountAccessDomain(account)"></span>. Server akan mengonfirmasi domain final saat Review.</p>
                                                <x-admin-dashboard.field-error x-show="accountInputError(index, 'access_value')" x-bind:id="'account-access-value-error-' + index" x-text="accountInputError(index, 'access_value')" role="alert" aria-live="polite"></x-admin-dashboard.field-error>
                                            </div>

                                        </article>
                                    </template>

                                    <div class="grid gap-2 sm:grid-cols-2">
                                        <div>
                                            <x-admin-dashboard.field-label for="account-input-buyer-locale">Preview bahasa pembeli</x-admin-dashboard.field-label>
                                            <x-admin-dashboard.select id="account-input-buyer-locale" x-model="accountInputBuyerLocale" x-on:change="accountInputChanged()" class="mt-1">
                                                <option value="id">Indonesia</option>
                                                <option value="en">English</option>
                                                <option value="ms">Bahasa Melayu</option>
                                            </x-admin-dashboard.select>
                                        </div>
                                        <x-admin-dashboard.notice tone="neutral" class="mb-0 self-end p-2 text-xs">
                                            Panduan delivery dan usage guide memakai pengaturan Product, sekali per receipt.
                                        </x-admin-dashboard.notice>
                                    </div>

                                    <div class="grid gap-2 sm:grid-cols-2">
                                        <x-admin-dashboard.button type="button" variant="neutral" x-on:click="addAccountCard()" x-bind:disabled="accountCards.length >= 20" class="w-full">
                                            Tambah akun
                                        </x-admin-dashboard.button>
                                        <x-admin-dashboard.button type="button" variant="primary" x-on:click="reviewAccountInput()" x-bind:disabled="accountInputSubmitting || accountInputReviewing" class="w-full">
                                            <span x-text="accountInputReviewing ? 'Memeriksa server...' : `Review ${accountCards.length} akun`">Review 1 akun</span>
                                        </x-admin-dashboard.button>
                                    </div>
                                </div>

                                <div x-show="accountInputStage === 'review'" class="space-y-3">
                                    <x-admin-dashboard.notice tone="neutral">
                                        <p class="font-semibold text-admin-ink">Review wajib sebelum commit</p>
                                        <p class="mt-1 text-sm">Validasi server akan mengulang pemeriksaan ini. Tidak ada password atau token yang ditulis ke pesan error, audit, atau URL.</p>
                                    </x-admin-dashboard.notice>

                                    <div class="grid gap-2 sm:grid-cols-3">
                                        <x-admin-dashboard.mini-stat label="Jumlah akun" value="" tone="cyan"><span x-text="accountInputReview?.account_count ?? accountCards.length">1</span></x-admin-dashboard.mini-stat>
                                        <x-admin-dashboard.mini-stat label="Status" value="" tone="emerald">
                                            <span x-text="accountInputReview?.status === 'ready' ? 'Server lulus' : 'Ada duplikat'">Server lulus</span>
                                        </x-admin-dashboard.mini-stat>
                                        <x-admin-dashboard.mini-stat label="Preview" value="" tone="amber"><span x-text="accountInputReview?.receipt_preview?.buyer_locale?.toUpperCase()">ID</span></x-admin-dashboard.mini-stat>
                                    </div>

                                    <x-admin-dashboard.notice tone="warning" x-show="accountInputReview?.status === 'duplicate'">
                                        Review server menemukan konflik identitas. Edit akun yang ditandai sebelum commit.
                                    </x-admin-dashboard.notice>

                                    <section class="space-y-2" aria-label="Preview private buyer receipt">
                                        <x-admin-dashboard.notice tone="neutral">
                                            <p class="font-semibold text-admin-ink">Preview private buyer photo-caption</p>
                                            <p class="mt-1 text-sm">Placeholder Order/payment dan nilai akun dimasking. Ini bukan Sales Proof Store Channel.</p>
                                        </x-admin-dashboard.notice>
                                        <article class="max-w-xl overflow-hidden rounded-lg border border-admin-line bg-admin-surface">
                                            <div class="border-b border-admin-line bg-admin-surface-muted px-3 py-2 text-xs font-medium text-admin-muted">Photo caption · private buyer</div>
                                            <div class="space-y-1 p-3 text-sm leading-6 text-admin-ink">
                                                <p x-text="accountInputReview?.receipt_preview?.preview?.title">✅ Pembayaran diterima</p>
                                                <p>━━━━━━━━━━━━━━</p>
                                                <p x-text="accountInputReview?.receipt_preview?.preview?.order_id">Order ID: [ORDER ID]</p>
                                                <p x-text="accountInputReview?.receipt_preview?.preview?.payment_method">Metode pembayaran: [PAYMENT METHOD]</p>
                                                <p x-text="accountInputReview?.receipt_preview?.preview?.paid_at">Waktu dibayar: [PAID DATE]</p>
                                                <p x-text="accountInputReview?.receipt_preview?.preview?.amount_paid">Total dibayar: [TOTAL]</p>
                                                <p x-text="accountInputReview?.receipt_preview?.preview?.product_detail">Detail produk</p>
                                                <template x-for="account in accountInputReviewAccounts()" :key="`preview-${account.index}`">
                                                    <div class="border-t border-dashed border-admin-line pt-1">
                                                        <p x-text="`Account #${account.account_number}`">Account #1</p>
                                                        <p>Email: <code x-text="account.email_masked">a•••@example.test</code></p>
                                                        <p>Password: <code>••••••••</code></p>
                                                        <p x-show="account.access_method === 'auth_token'">Auth Token: <code>••••••••</code></p>
                                                        <p x-show="account.access_method === 'otp_mail_link'">OTP Mail Link: domain <span x-text="account.otp_domain"></span></p>
                                                    </div>
                                                </template>
                                                <p x-show="accountInputReview?.receipt_preview?.preview?.delivery_note_occurrences">Product note · sekali</p>
                                                <p x-show="accountInputReview?.receipt_preview?.preview?.usage_guide_occurrences">Usage guide · sekali</p>
                                            </div>
                                        </article>
                                        <div class="grid gap-2 sm:grid-cols-3">
                                            <x-admin-dashboard.mini-stat label="Caption" value="" tone="cyan"><span x-text="`${accountInputReview?.receipt_preview?.photo_caption_estimate ?? 0} / ${accountInputReview?.receipt_preview?.photo_caption_limit ?? 1024}`">0 / 1024</span></x-admin-dashboard.mini-stat>
                                            <x-admin-dashboard.mini-stat label="Lanjutan" value="" tone="amber"><span x-text="accountInputReview?.receipt_preview?.continuation_count_estimate ?? 0">0</span></x-admin-dashboard.mini-stat>
                                            <x-admin-dashboard.mini-stat label="Guide / note" value="Sekali" tone="emerald" />
                                        </div>
                                        <x-admin-dashboard.notice tone="warning" x-show="(accountInputReview?.receipt_preview?.warning_codes ?? []).length > 0">
                                            Caption melewati batas Telegram dan akan dipecah ke pesan lanjutan.
                                        </x-admin-dashboard.notice>
                                    </section>

                                    <div class="space-y-2" aria-label="Review konflik identitas">
                                        <template x-for="account in accountInputReviewAccounts()" :key="`review-${account.index}`">
                                            <article class="rounded-lg border border-admin-line bg-admin-surface p-3">
                                                <div class="flex flex-wrap items-center justify-between gap-2">
                                                    <p class="text-sm font-semibold text-admin-ink" x-text="`Account #${account.account_number}`">Account #1</p>
                                                    <span class="inline-flex items-center gap-1.5 rounded-full border border-admin-line px-2.5 py-0.5 text-xs font-medium" x-bind:class="account.duplicate_status === 'duplicate' ? 'bg-neo-danger-soft text-neo-danger-strong' : 'bg-neo-success-soft text-neo-success-text'" x-text="account.duplicate_status === 'duplicate' ? 'Perlu edit' : 'Siap'">Siap</span>
                                                </div>
                                                <p class="mt-2 text-sm" x-bind:class="account.duplicate_status === 'duplicate' ? 'text-neo-danger-text' : 'text-neo-success-text'" x-text="account.duplicate_status === 'duplicate' ? accountInputReviewDuplicateSummary(account) : 'Tidak ada konflik identitas'">Tidak ada konflik identitas</p>
                                            </article>
                                        </template>
                                    </div>

                                    <div class="grid grid-cols-1 gap-2 pt-1 sm:grid-cols-2">
                                        <x-admin-dashboard.button type="button" variant="neutral" x-on:click="editAccountInput()" x-bind:disabled="accountInputSubmitting">
                                            ← Kembali edit
                                        </x-admin-dashboard.button>
                                        <x-admin-dashboard.button type="submit" variant="primary" x-bind:disabled="accountInputSubmitting || accountInputReviewing || !accountInputCanCommit()" x-bind:class="{ 'cursor-wait opacity-70': accountInputSubmitting || accountInputReviewing }">
                                            <span x-text="accountInputSubmitting ? 'Menyimpan...' : `Tambah ${accountCards.length} stok`">Tambah 1 stok</span>
                                        </x-admin-dashboard.button>
                                    </div>
                                </div>
                            </form>
                        </div>
                        @else
                            <x-admin-dashboard.notice tone="neutral" class="mb-0 text-xs">
                                Input Akun belum tersedia karena pemeriksaan kesiapan identitas belum selesai. Input Cepat tetap tersedia.
                            </x-admin-dashboard.notice>
                        @endif
                    </x-admin-dashboard.modal-shell>
                </x-admin-dashboard.modal-backdrop>

                <x-admin-dashboard.modal-backdrop show="manageStockModalOpen" close="closeManageStockModal()">
                    <x-admin-dashboard.modal-shell
                        id="manage-stock-modal"
                        show="manageStockModalOpen"
                        title="Kelola stok"
                        description="Ready dikelompokkan paling atas, paid, retired, dan repair bersifat read-only."
                        size="2xl"
                    >
                        <div class="mb-3 flex flex-wrap items-center justify-between gap-2 text-sm text-admin-ink">
                            <span class="max-w-[15rem] truncate font-medium" x-text="manageStockProductName">Produk</span>
                            <span class="text-admin-muted" x-text="`${manageStockSummary.available} tersedia · ${manageStockSummary.reserved} dipesan · ${manageStockSummary.paid} paid`">0 tersedia · 0 dipesan · 0 paid</span>
                        </div>

                        <x-admin-dashboard.notice tone="warning" x-show="manageStockSupplierCapacity === 0 && manageStockSupplierCapacityReasons.length > 0" class="mb-3 text-sm">
                            <p class="font-semibold text-admin-ink">Kapasitas supplier aman: 0 unit</p>
                            <ul class="mt-1 list-disc space-y-1 pl-4">
                                <template x-for="reason in manageStockSupplierCapacityReasons" :key="reason">
                                    <li x-text="reason"></li>
                                </template>
                            </ul>
                        </x-admin-dashboard.notice>

                        <x-admin-dashboard.notice tone="neutral" x-show="manageStockTruncated" class="mb-2 text-xs">
                            Ringkasan menghitung semua akun. Hanya 25 akun terbaru per status yang ditampilkan di riwayat.
                        </x-admin-dashboard.notice>

                        <x-admin-dashboard.notice tone="danger" x-show="manageStockErrorMessages().length > 0" role="alert" aria-live="assertive" class="mb-3">
                            <p class="font-semibold text-admin-ink">Data stok belum disimpan.</p>
                            <ul class="mt-1 list-disc space-y-1 pl-4 text-sm">
                                <template x-for="message in manageStockErrorMessages()" :key="message">
                                    <li x-text="message"></li>
                                </template>
                            </ul>
                        </x-admin-dashboard.notice>

                        <x-admin-dashboard.notice tone="neutral" x-show="manageStockLoading" role="status" aria-live="polite" class="mb-3">
                            Memuat data stok produk…
                        </x-admin-dashboard.notice>

                        <div x-show="manageStockLoadError" role="alert" aria-live="assertive" class="mb-3 grid gap-3 rounded-lg border border-admin-line bg-neo-danger-pale p-3 text-sm text-neo-danger-text">
                            <p x-text="manageStockLoadError">Data stok produk tidak dapat dimuat.</p>
                            <x-admin-dashboard.button type="button" variant="secondary" size="sm" class="w-full" x-on:click="retryManageStockLoad()" x-bind:disabled="manageStockLoading">
                                Coba muat ulang
                            </x-admin-dashboard.button>
                        </div>

                        <p x-show="manageStockDeleteMessage" x-text="manageStockDeleteMessage" role="status" class="mb-3 text-sm font-medium text-admin-ink"></p>
                        <p x-show="manageStockDeleteError" x-text="manageStockDeleteError" role="alert" class="mb-3 text-sm font-medium text-neo-danger-text"></p>
                        <div x-show="!manageStockLoading && !manageStockLoadError && manageStockSelectableItems().length" class="sticky top-0 z-10 mb-3 flex flex-wrap items-center gap-3 rounded-lg border border-admin-line bg-admin-surface p-3">
                            <label class="flex min-h-11 items-center gap-2 text-sm font-medium text-admin-ink">
                                <input type="checkbox" class="h-5 w-5 rounded border-admin-line accent-admin-accent" x-bind:disabled="manageStockDeleting" x-bind:checked="manageStockSelectableItems().length > 0 && manageStockSelectedIds.length === manageStockSelectableItems().length" x-on:change="toggleAllManageStockSelection()">
                                Pilih semua yang tampil
                            </label>
                            <x-admin-dashboard.button type="button" variant="destructive" size="sm" x-bind:disabled="manageStockDeleting || manageStockSelectedIds.length === 0" x-on:click="deleteSelectedManageStock()">
                                <span x-text="manageStockDeleting ? 'Menghapus…' : `Hapus terpilih (${manageStockSelectedIds.length})`">Hapus terpilih (0)</span>
                            </x-admin-dashboard.button>
                            <p class="w-full text-xs text-admin-muted">Hanya akun tersedia yang bisa dipilih. Setelah dihapus, daftar berikutnya dimuat otomatis.</p>
                        </div>

                        <div x-show="!manageStockLoading && !manageStockLoadError" class="space-y-3">
                            <template x-for="group in manageStockGroups()" :key="group.key">
                                <section class="rounded-lg border border-admin-line bg-admin-surface p-2">
                                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-dashed border-admin-line pb-2">
                                        <div class="flex min-w-0 items-center gap-2">
                                            <span class="h-3 w-3 shrink-0 rounded-full border border-admin-line" x-bind:style="{ backgroundColor: group.color }"></span>
                                            <span class="text-sm font-semibold text-admin-ink" x-text="group.label">READY / TERSEDIA</span>
                                        </div>
                                        <x-admin-dashboard.badge><span x-text="`${group.items.length} akun`">0 akun</span></x-admin-dashboard.badge>
                                    </div>
                                    <p class="mt-2 text-xs leading-5 text-admin-muted" x-text="group.helper"></p>

                                    <div class="mt-2 space-y-2">
                                        <template x-for="item in group.items" :key="item.id">
                                            <x-admin-dashboard.card as="article" padding="sm" shadow="none">
                                                <div class="grid gap-2 sm:grid-cols-[1fr_auto]">
                                                    <div class="min-w-0">
                                                        <div class="flex flex-wrap items-center gap-1.5">
                                                            <input type="checkbox" x-show="item.editable && item.delete_url" class="h-4 w-4 rounded border-admin-line accent-admin-accent" x-bind:aria-label="`Pilih akun stok #${item.displayIndex}`" x-bind:checked="manageStockSelectedIds.includes(item.id)" x-bind:disabled="manageStockDeleting" x-on:change="toggleManageStockSelection(item.id)">
                                                            <span class="text-xs font-medium text-admin-muted" x-text="`#${item.displayIndex}`">#1</span>
                                                            <span class="inline-flex items-center rounded-full border border-admin-line px-2 py-0.5 text-xs font-medium" x-bind:class="{ 'bg-neo-info-soft text-neo-info-text': item.status === 'TERSEDIA', 'bg-neo-warning-soft text-neo-warning-text': item.status === 'DIPESAN', 'bg-neo-success-soft text-neo-success-text': item.status === 'PAID', 'bg-neo-danger-soft text-neo-danger-strong': item.status === 'EXPIRED' || item.status === 'CANCELLED' || item.status === 'REPAIR', 'bg-admin-surface-muted text-admin-muted': item.status === 'RETIRED' }" x-text="item.status">TERSEDIA</span>
                                                            <span x-show="item.metadata" class="text-xs text-admin-muted" x-text="item.metadata"></span>
                                                            <span x-show="item.repair_needed" class="inline-flex items-center rounded-full border border-admin-line px-2 py-0.5 text-xs font-medium text-neo-warning-text">Perlu repair</span>
                                                        </div>

                                                        <div x-show="item.credential_format !== 'structured_account'" tabindex="0" x-bind:aria-label="`Kredensial akun stok #${item.displayIndex}`" class="stock-credential mt-1.5 select-text break-all font-mono text-xs leading-5 text-admin-ink focus:ring-2 focus:ring-admin-focus" x-text="item.secret"></div>
                                                        <template x-if="item.credential_format === 'structured_account' && item.credential_payload">
                                                            <x-admin-dashboard.textarea readonly autocomplete="off" rows="5" x-bind:aria-label="`Kredensial akun stok #${item.displayIndex}`" class="stock-credential mt-1.5 select-text font-mono text-xs" x-bind:value="stockCredentialText(item)"></x-admin-dashboard.textarea>
                                                        </template>
                                                        <p x-show="item.copyMessage" x-text="item.copyMessage" role="status" aria-live="polite" class="mt-2 text-xs text-admin-ink"></p>
                                                        <p x-show="item.credential_format === 'structured_account' && !item.credential_payload" class="mt-1.5 text-xs font-medium text-neo-danger-text">Payload tidak dapat dibaca. Read-only.</p>
                                                    </div>
                                                    <div class="flex flex-row gap-1.5 sm:flex-col">
                                                        <x-admin-dashboard.button type="button" variant="secondary" size="sm" x-show="stockCredentialText(item)" x-bind:disabled="item.copying" x-on:click="copyStockCredential(item, $el.closest('article'))">
                                                            <span x-text="item.copying ? 'Menyalin…' : 'Salin kredensial'">Salin kredensial</span>
                                                        </x-admin-dashboard.button>
                                                        <x-admin-dashboard.button type="button" variant="secondary" size="icon" aria-label="Ubah akun stok" x-bind:aria-expanded="item.editing" x-bind:disabled="manageStockDeleting" x-on:click="item.editing = ! item.editing" x-show="item.editable">✏️</x-admin-dashboard.button>
                                                        <form method="POST" x-bind:action="item.delete_url" x-show="item.editable" x-on:submit="if (manageStockDeleting || ! confirm('Hapus akun stok ini?')) $event.preventDefault()">
                                                            @csrf
                                                            <input type="hidden" name="_method" value="DELETE">
                                                            <x-admin-dashboard.button type="submit" variant="destructive" size="icon" x-bind:disabled="manageStockDeleting" aria-label="Hapus akun stok">🗑️</x-admin-dashboard.button>
                                                        </form>
                                                        <form method="POST" x-bind:action="item.cancel_release_url" x-show="item.cancel_release_url" x-on:submit="if (manageStockDeleting || ! confirm('Batalkan order dan lepas stok ini?')) $event.preventDefault()">
                                                            @csrf
                                                            <x-admin-dashboard.button type="submit" variant="warning" size="icon" x-bind:disabled="manageStockDeleting" aria-label="Batalkan order dan lepas stok ini">↩</x-admin-dashboard.button>
                                                        </form>
                                                    </div>
                                                </div>

                                                <template x-if="item.editable && item.credential_format === 'structured_account'">
                                                    <form method="POST" x-bind:id="'stock-item-editor-' + item.id" x-bind:action="item.update_url" class="mt-3 grid gap-3 rounded-lg border border-admin-line bg-admin-surface-muted p-2" x-show="item.editing" x-transition>
                                                        @csrf
                                                        <input type="hidden" name="_method" value="PATCH">
                                                        <input type="hidden" name="credential_format" value="structured_account">
                                                        <div class="grid gap-2 sm:grid-cols-2">
                                                            <div>
                                                                <x-admin-dashboard.field-label x-bind:for="'stock-item-' + item.id + '-email'">Email</x-admin-dashboard.field-label>
                                                                <x-admin-dashboard.input x-bind:id="'stock-item-' + item.id + '-email'" name="email" type="email" maxlength="254" autocomplete="off" x-model="item.credential_payload.email" x-bind:aria-invalid="manageStockError('email') ? 'true' : 'false'" x-bind:aria-describedby="manageStockError('email') ? 'stock-item-' + item.id + '-email-error' : null" class="mt-1" />
                                                                <p x-show="manageStockError('email')" x-bind:id="'stock-item-' + item.id + '-email-error'" x-text="manageStockError('email')" role="alert" class="mt-1 text-xs font-medium text-neo-danger-text"></p>
                                                            </div>
                                                            <div>
                                                                <x-admin-dashboard.field-label x-bind:for="'stock-item-' + item.id + '-password'">Password</x-admin-dashboard.field-label>
                                                                <x-admin-dashboard.textarea x-bind:id="'stock-item-' + item.id + '-password'" name="password" rows="2" maxlength="1024" autocomplete="new-password" x-model="item.credential_payload.password" x-bind:aria-invalid="manageStockError('password') ? 'true' : 'false'" x-bind:aria-describedby="manageStockError('password') ? 'stock-item-' + item.id + '-password-error' : null" class="mt-1 min-h-20 font-mono"></x-admin-dashboard.textarea>
                                                                <p x-show="manageStockError('password')" x-bind:id="'stock-item-' + item.id + '-password-error'" x-text="manageStockError('password')" role="alert" class="mt-1 text-xs font-medium text-neo-danger-text"></p>
                                                            </div>
                                                        </div>
                                                        <div>
                                                            <x-admin-dashboard.field-label x-bind:for="'stock-item-' + item.id + '-access-method'">Metode akses</x-admin-dashboard.field-label>
                                                            <x-admin-dashboard.select x-bind:id="'stock-item-' + item.id + '-access-method'" name="access_method" x-model="item.credential_payload.access_method" x-on:change="item.credential_payload.access_value = ''" x-bind:aria-invalid="manageStockError('access_method') ? 'true' : 'false'" x-bind:aria-describedby="manageStockError('access_method') ? 'stock-item-' + item.id + '-access-method-error' : null" class="mt-1">
                                                                <option value="none">Tidak ada</option>
                                                                <option value="otp_mail_link">OTP Mail Link</option>
                                                                <option value="auth_token">Auth Token</option>
                                                            </x-admin-dashboard.select>
                                                            <p x-show="manageStockError('access_method')" x-bind:id="'stock-item-' + item.id + '-access-method-error'" x-text="manageStockError('access_method')" role="alert" class="mt-1 text-xs font-medium text-neo-danger-text"></p>
                                                        </div>
                                                        <div x-show="item.credential_payload.access_method !== 'none'">
                                                            <x-admin-dashboard.field-label x-bind:for="'stock-item-' + item.id + '-access-value'">Nilai metode akses</x-admin-dashboard.field-label>
                                                            <x-admin-dashboard.input x-bind:id="'stock-item-' + item.id + '-access-value'" name="access_value" x-bind:type="item.credential_payload.access_method === 'otp_mail_link' ? 'url' : 'text'" x-bind:maxlength="item.credential_payload.access_method === 'otp_mail_link' ? 2048 : 3500" x-model="item.credential_payload.access_value" x-bind:aria-invalid="manageStockError('access_value') ? 'true' : 'false'" x-bind:aria-describedby="manageStockError('access_value') ? 'stock-item-' + item.id + '-access-value-error' : null" class="mt-1" />
                                                            <p x-show="manageStockError('access_value')" x-bind:id="'stock-item-' + item.id + '-access-value-error'" x-text="manageStockError('access_value')" role="alert" class="mt-1 text-xs font-medium text-neo-danger-text"></p>
                                                        </div>
                                                        <div class="grid grid-cols-2 gap-2 sm:justify-end">
                                                            <x-admin-dashboard.button type="submit" variant="secondary" size="sm" x-bind:disabled="manageStockDeleting">Simpan</x-admin-dashboard.button>
                                                            <x-admin-dashboard.button type="button" variant="neutral" size="sm" x-on:click="item.editing = false">Batal</x-admin-dashboard.button>
                                                        </div>
                                                    </form>
                                                </template>

                                                <template x-if="item.editable && item.credential_format !== 'structured_account'">
                                                    <form method="POST" x-bind:action="item.update_url" class="mt-2 grid gap-2 sm:grid-cols-[1fr_auto]" x-show="item.editing" x-transition>
                                                        @csrf
                                                        <input type="hidden" name="_method" value="PATCH">
                                                        <x-admin-dashboard.textarea name="secret" rows="2" class="min-h-16 font-mono text-xs" x-model="item.secret" />
                                                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-1">
                                                            <x-admin-dashboard.button type="submit" variant="secondary" size="sm" class="self-start" x-bind:disabled="manageStockDeleting">Simpan</x-admin-dashboard.button>
                                                            <x-admin-dashboard.button type="button" variant="neutral" size="sm" class="self-start" x-on:click="item.editing = false">Batal</x-admin-dashboard.button>
                                                        </div>
                                                    </form>
                                                </template>
                                            </x-admin-dashboard.card>
                                        </template>
                                    </div>
                                </section>
                            </template>
                        </div>

                        <x-admin-dashboard.empty-state title="Belum ada akun tersedia." body="Tambahkan stok baru." x-show="!manageStockLoading && !manageStockLoadError && manageStockItems.length === 0" />

                        <x-admin-dashboard.button type="button" variant="primary" class="mt-4 w-full" x-show="!manageStockLoading && !manageStockLoadError" x-bind:disabled="!manageStockAddPayload || manageStockDeleting" x-on:click="openStockModalFromManage()">
                            Tambah stok baru
                        </x-admin-dashboard.button>
                    </x-admin-dashboard.modal-shell>
                </x-admin-dashboard.modal-backdrop>
                @endif
