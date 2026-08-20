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
                        class="max-h-[calc(100vh-1.5rem)] w-full max-w-5xl overflow-y-auto bg-[#7BD10A] p-3 sm:p-5"
                    >
                        <div class="mb-4 {{ $structuredStockInputEnabled ? 'grid grid-cols-2' : 'grid grid-cols-1' }} gap-2" role="tablist" aria-label="Mode input stok">
                            @if ($structuredStockInputEnabled)
                            <button
                                type="button"
                                role="tab"
                                x-bind:aria-selected="stockInputMode === 'quick'"
                                x-bind:class="stockInputMode === 'quick' ? 'bg-[#f2da3d]' : 'bg-white'"
                                x-on:click="setStockInputMode('quick')"
                                class="min-h-11 rounded-[0.45rem] border-[3px] border-neo-ink px-3 py-2 text-xs font-black uppercase text-neo-ink shadow-[3px_3px_0_0_#050505]"
                            >
                                Input Cepat
                            </button>
                            <button
                                type="button"
                                role="tab"
                                x-bind:aria-selected="stockInputMode === 'account'"
                                x-bind:class="stockInputMode === 'account' ? 'bg-[#f2da3d]' : 'bg-white'"
                                x-on:click="setStockInputMode('account')"
                                class="min-h-11 rounded-[0.45rem] border-[3px] border-neo-ink px-3 py-2 text-xs font-black uppercase text-neo-ink shadow-[3px_3px_0_0_#050505]"
                            >
                                Input Akun
                            </button>
                            @endif
                        </div>

                        <div x-show="stockInputMode === 'quick'" x-cloak>
                            <form method="POST" x-bind:action="stockFormAction" x-on:submit="submitStockForm($event)" class="space-y-3">
                                @csrf
                                <input type="hidden" name="stock_product_id" x-bind:value="stockProductId">

                                <div class="flex flex-wrap items-center justify-between gap-2 text-xs font-extrabold uppercase text-neo-ink">
                                    <span class="max-w-[13rem] truncate" x-text="stockProductName">Produk</span>
                                    <span x-text="`${stockItemCount()} item`">0 item</span>
                                </div>

                                <div>
                                    <x-admin-dashboard.textarea
                                        id="stock-items"
                                        name="stock_items"
                                        rows="6"
                                        placeholder="email@example.com|password&#10;KEY-XXXX-1234"
                                        x-model="stockItems"
                                        x-ref="stockItems"
                                        required
                                    />

                                    @error('stock_items', 'stockAdjustment')
                                        <x-admin-dashboard.field-error>{{ $message }}</x-admin-dashboard.field-error>
                                    @enderror
                                </div>

                                <div class="grid grid-cols-2 gap-2 pt-1">
                                    <x-admin-dashboard.button type="button" variant="neutral" x-on:click="closeStockModal()" x-bind:disabled="stockFormSubmitting">
                                        Batal
                                    </x-admin-dashboard.button>
                                    <x-admin-dashboard.button type="submit" variant="pink" x-bind:disabled="stockFormSubmitting" x-bind:class="{ 'cursor-wait opacity-70': stockFormSubmitting }">
                                        <span x-text="stockFormSubmitting ? 'Menyimpan...' : '⚡ Simpan'">⚡ Simpan</span>
                                    </x-admin-dashboard.button>
                                </div>
                            </form>
                        </div>

                        @if ($structuredStockInputEnabled)
                        <div x-show="stockInputMode === 'account'" x-cloak>
                            <form method="POST" x-bind:action="stockAccountsFormAction" x-on:submit="submitAccountInput($event)" class="space-y-4">
                                @csrf
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <p class="font-display text-sm font-black uppercase text-neo-ink">Akun terstruktur</p>
                                        <p class="mt-1 max-w-2xl text-[11px] font-bold leading-5 text-neo-ink/70">Satu kartu selalu menjadi satu Product Stock Item. Password, token, dan tutorial boleh multiline tanpa menambah jumlah stok.</p>
                                    </div>
                                    <span class="rounded-[0.4rem] border-2 border-neo-ink bg-white px-2 py-1 text-[10px] font-black uppercase text-neo-ink" x-text="`${accountCards.length} / 20 akun`">1 / 20 akun</span>
                                </div>

                                <div x-show="accountInputMessage" x-text="accountInputMessage" role="alert" aria-live="assertive" class="rounded-[0.45rem] border-2 border-neo-ink bg-white px-3 py-2 text-xs font-black text-neo-ink"></div>

                                <div x-show="accountInputStage === 'edit'" class="space-y-3">
                                    <template x-for="(account, index) in accountCards" :key="account.id">
                                        <article class="rounded-[0.55rem] border-[3px] border-neo-ink bg-white p-3 shadow-[3px_3px_0_0_#050505]">
                                            <div class="flex items-start justify-between gap-3">
                                                <div>
                                                    <p class="font-display text-sm font-black uppercase text-neo-ink" x-text="`Akun #${index + 1}`">Akun #1</p>
                                                    <p class="mt-1 text-[10px] font-bold text-neo-ink/60">Wajib email + password. Pilih paling banyak satu metode akses.</p>
                                                </div>
                                                <button
                                                    type="button"
                                                    class="min-h-10 rounded-[0.35rem] border-2 border-neo-ink bg-white px-2.5 py-1.5 text-[10px] font-black uppercase text-neo-ink shadow-[2px_2px_0_0_#050505] disabled:cursor-not-allowed disabled:opacity-40"
                                                    x-on:click="removeAccountCard(index)"
                                                    x-bind:disabled="accountCards.length === 1"
                                                    x-bind:aria-label="`Hapus akun #${index + 1}`"
                                                >
                                                    Hapus
                                                </button>
                                            </div>

                                            <div class="mt-3 grid gap-3 md:grid-cols-2">
                                                <div>
                                                    <label x-bind:for="`account-email-${index}`" class="font-display text-[11px] font-extrabold uppercase tracking-wider text-neo-ink">Email</label>
                                                    <input
                                                        x-bind:id="`account-email-${index}`"
                                                        x-bind:name="`accounts[${index}][email]`"
                                                        type="email"
                                                        autocomplete="off"
                                                        maxlength="254"
                                                        x-model="account.email"
                                                        x-on:input="accountInputChanged()"
                                                        x-bind:class="accountInputErrorClass(index, 'email')"
                                                        class="mt-1.5 min-h-12 w-full rounded-neo-2xl border-[3px] border-neo-ink bg-white px-3 text-sm font-bold text-neo-ink shadow-neo-md focus:outline-none focus:ring-2 focus:ring-neo-focus focus:ring-offset-2 focus:ring-offset-neo-cream"
                                                    >
                                                    <x-admin-dashboard.field-error x-show="accountInputError(index, 'email')" x-text="accountInputError(index, 'email')"></x-admin-dashboard.field-error>
                                                </div>
                                                <div>
                                                    <label x-bind:for="`account-password-${index}`" class="font-display text-[11px] font-extrabold uppercase tracking-wider text-neo-ink">Password</label>
                                                    <textarea
                                                        x-bind:id="`account-password-${index}`"
                                                        x-bind:name="`accounts[${index}][password]`"
                                                        autocomplete="new-password"
                                                        maxlength="1024"
                                                        rows="2"
                                                        x-model="account.password"
                                                        x-on:input="accountInputChanged()"
                                                        x-bind:class="accountInputErrorClass(index, 'password')"
                                                        class="mt-1.5 min-h-20 w-full rounded-neo-2xl border-[3px] border-neo-ink bg-white px-3 py-3 font-mono text-sm font-bold text-neo-ink shadow-neo-md focus:outline-none focus:ring-2 focus:ring-neo-focus focus:ring-offset-2 focus:ring-offset-neo-cream"
                                                    ></textarea>
                                                    <x-admin-dashboard.field-error x-show="accountInputError(index, 'password')" x-text="accountInputError(index, 'password')"></x-admin-dashboard.field-error>
                                                </div>
                                            </div>

                                            <fieldset class="mt-3">
                                                <legend class="font-display text-[11px] font-extrabold uppercase tracking-wider text-neo-ink">Metode akses</legend>
                                                <div class="mt-1.5 grid gap-2 sm:grid-cols-3">
                                                    <template x-for="method in ['none', 'otp_mail_link', 'auth_token']" :key="method">
                                                        <label class="flex min-h-11 cursor-pointer items-center gap-2 rounded-[0.4rem] border-2 border-neo-ink bg-[#fff8d6] px-2.5 py-2 text-[10px] font-black uppercase text-neo-ink">
                                                            <input
                                                                type="radio"
                                                                x-bind:name="`accounts[${index}][access_method]`"
                                                                x-bind:value="method"
                                                                x-model="account.accessMethod"
                                                                x-on:change="setAccountAccessMethod(index, $event.target.value)"
                                                                class="h-4 w-4 border-2 border-neo-ink text-neo-focus focus:ring-2 focus:ring-neo-focus"
                                                            >
                                                            <span x-text="accountAccessLabel(method)">Tidak ada</span>
                                                        </label>
                                                    </template>
                                                </div>
                                                <x-admin-dashboard.field-error x-show="accountInputError(index, 'access_method')" x-text="accountInputError(index, 'access_method')"></x-admin-dashboard.field-error>
                                            </fieldset>

                                            <div x-show="account.accessMethod !== 'none'" class="mt-3">
                                                <label x-bind:for="`account-access-value-${index}`" x-text="account.accessMethod === 'otp_mail_link' ? 'OTP Mail Link (HTTPS)' : 'Auth Token'" class="font-display text-[11px] font-extrabold uppercase tracking-wider text-neo-ink">Auth Token</label>
                                                <input
                                                    x-bind:id="`account-access-value-${index}`"
                                                    x-bind:name="`accounts[${index}][access_value]`"
                                                    x-bind:type="account.accessMethod === 'otp_mail_link' ? 'url' : 'text'"
                                                    x-bind:autocomplete="account.accessMethod === 'otp_mail_link' ? 'off' : 'new-password'"
                                                    x-bind:maxlength="account.accessMethod === 'otp_mail_link' ? 2048 : 3500"
                                                    x-model="account.accessValue"
                                                    x-on:input="accountInputChanged()"
                                                    x-bind:class="accountInputErrorClass(index, 'access_value')"
                                                    class="mt-1.5 min-h-12 w-full rounded-neo-2xl border-[3px] border-neo-ink bg-white px-3 text-sm font-bold text-neo-ink shadow-neo-md focus:outline-none focus:ring-2 focus:ring-neo-focus focus:ring-offset-2 focus:ring-offset-neo-cream"
                                                >
                                                <p x-show="account.accessMethod === 'otp_mail_link' && accountAccessDomain(account)" class="mt-1 text-[10px] font-black text-neo-ink/70">Domain tujuan: <span x-text="accountAccessDomain(account)"></span></p>
                                                <x-admin-dashboard.field-error x-show="accountInputError(index, 'access_value')" x-text="accountInputError(index, 'access_value')"></x-admin-dashboard.field-error>
                                            </div>

                                            <div class="mt-3">
                                                <div class="flex items-center justify-between gap-2">
                                                    <label x-bind:for="`account-tutorial-override-${index}`" class="font-display text-[11px] font-extrabold uppercase tracking-wider text-neo-ink">Tutorial akun (opsional)</label>
                                                    <span class="text-[10px] font-bold text-neo-ink/60">Default: Product</span>
                                                </div>
                                                <textarea
                                                    x-bind:id="`account-tutorial-override-${index}`"
                                                    x-bind:name="`accounts[${index}][tutorial_override]`"
                                                    maxlength="2000"
                                                    rows="2"
                                                    placeholder="Kosongkan untuk memakai delivery note Product."
                                                    x-model="account.tutorialOverride"
                                                    x-on:input="accountInputChanged()"
                                                    x-bind:class="accountInputErrorClass(index, 'tutorial_override')"
                                                    class="mt-1.5 min-h-20 w-full rounded-neo-2xl border-[3px] border-neo-ink bg-white px-3 py-3 text-sm font-bold text-neo-ink shadow-neo-md focus:outline-none focus:ring-2 focus:ring-neo-focus focus:ring-offset-2 focus:ring-offset-neo-cream"
                                                ></textarea>
                                                <x-admin-dashboard.field-error x-show="accountInputError(index, 'tutorial_override')" x-text="accountInputError(index, 'tutorial_override')"></x-admin-dashboard.field-error>
                                            </div>
                                        </article>
                                    </template>

                                    <div class="grid gap-2 sm:grid-cols-2">
                                        <x-admin-dashboard.button type="button" variant="neutral" x-on:click="addAccountCard()" x-bind:disabled="accountCards.length >= 20" class="w-full">
                                            ➕ Tambah akun
                                        </x-admin-dashboard.button>
                                        <x-admin-dashboard.button type="button" variant="pink" x-on:click="reviewAccountInput()" x-bind:disabled="accountInputSubmitting" class="w-full">
                                            <span x-text="`Review ${accountCards.length} akun`">Review 1 akun</span>
                                        </x-admin-dashboard.button>
                                    </div>
                                </div>

                                <div x-show="accountInputStage === 'review'" class="space-y-3">
                                    <x-admin-dashboard.notice tone="neutral">
                                        <p class="font-black uppercase">Review wajib sebelum commit</p>
                                        <p class="mt-1 text-xs font-bold">Validasi server akan mengulang pemeriksaan ini. Tidak ada password atau token yang ditulis ke pesan error, audit, atau URL.</p>
                                    </x-admin-dashboard.notice>

                                    <div class="grid gap-2 sm:grid-cols-3">
                                        <x-admin-dashboard.mini-stat label="Jumlah akun" value="" tone="cyan"><span x-text="accountCards.length">1</span></x-admin-dashboard.mini-stat>
                                        <x-admin-dashboard.mini-stat label="Status" value="Lulus" tone="emerald" />
                                        <x-admin-dashboard.mini-stat label="Tutorial" value="Per akun" tone="amber" />
                                    </div>

                                    <div class="space-y-2" aria-label="Preview pengiriman pelanggan">
                                        <template x-for="(account, index) in accountCards" :key="`review-${account.id}`">
                                            <article class="rounded-[0.5rem] border-2 border-neo-ink bg-white p-3">
                                                <div class="flex flex-wrap items-center justify-between gap-2">
                                                    <p class="font-display text-xs font-black uppercase" x-text="`Account #${index + 1}`">Account #1</p>
                                                    <span class="rounded-full border-2 border-neo-ink bg-[#fff8d6] px-2 py-0.5 text-[9px] font-black uppercase" x-text="accountTutorialSource(account)">Tutorial default Product</span>
                                                </div>
                                                <dl class="mt-2 grid gap-2 text-xs sm:grid-cols-2">
                                                    <div>
                                                        <dt class="font-black uppercase text-neo-ink/60">Email</dt>
                                                        <dd class="break-all font-mono font-bold" x-text="account.email">account@example.test</dd>
                                                    </div>
                                                    <div>
                                                        <dt class="font-black uppercase text-neo-ink/60">Password</dt>
                                                        <dd class="font-bold">Siap dikirim sebagai field akun</dd>
                                                    </div>
                                                    <div class="sm:col-span-2">
                                                        <dt class="font-black uppercase text-neo-ink/60">Akses</dt>
                                                        <dd class="font-bold">
                                                            <span x-text="accountAccessLabel(account.accessMethod)">Tidak ada</span>
                                                            <span x-show="account.accessMethod === 'otp_mail_link' && accountAccessDomain(account)"> · domain <span x-text="accountAccessDomain(account)"></span></span>
                                                            <span x-show="account.accessMethod === 'auth_token'"> · token siap dikirim sebagai teks</span>
                                                        </dd>
                                                    </div>
                                                </dl>
                                            </article>
                                        </template>
                                    </div>

                                    <div class="grid grid-cols-2 gap-2 pt-1">
                                        <x-admin-dashboard.button type="button" variant="neutral" x-on:click="editAccountInput()" x-bind:disabled="accountInputSubmitting">
                                            ← Kembali edit
                                        </x-admin-dashboard.button>
                                        <x-admin-dashboard.button type="submit" variant="pink" x-bind:disabled="accountInputSubmitting || !accountInputReviewed" x-bind:class="{ 'cursor-wait opacity-70': accountInputSubmitting }">
                                            <span x-text="accountInputSubmitting ? 'Menyimpan...' : `Tambah ${accountCards.length} stok`">Tambah 1 stok</span>
                                        </x-admin-dashboard.button>
                                    </div>
                                </div>
                            </form>
                        </div>
                        @else
                            <x-admin-dashboard.notice tone="neutral" class="mb-0 text-xs">
                                Input Akun sementara dinonaktifkan sampai backfill identitas dan proof rollout selesai. Input Cepat tetap tersedia.
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
                        class="max-h-[92vh] w-full max-w-3xl overflow-y-auto bg-[#fee648] p-3 sm:p-4"
                    >
                        <div class="mb-3 flex flex-wrap items-center justify-between gap-2 text-xs font-extrabold uppercase text-neo-ink">
                            <span class="max-w-[15rem] truncate" x-text="manageStockProductName">Produk</span>
                            <span x-text="`${manageStockSummary.available} tersedia · ${manageStockSummary.reserved} dipesan · ${manageStockSummary.paid} paid`">0 tersedia · 0 dipesan · 0 paid</span>
                        </div>

                        <x-admin-dashboard.notice tone="neutral" x-show="manageStockTruncated" class="mb-2 rounded-[0.45rem] p-2 text-[11px]">
                            Ringkasan menghitung semua akun. Hanya 25 akun terbaru per status yang ditampilkan di riwayat.
                        </x-admin-dashboard.notice>

                        <div class="space-y-3">
                            <template x-for="group in manageStockGroups()" :key="group.key">
                                <section class="rounded-[0.55rem] border-2 border-neo-ink bg-white/80 p-2 shadow-[3px_3px_0_0_#050505]">
                                    <div class="flex flex-wrap items-center justify-between gap-2 border-b-2 border-dashed border-neo-ink pb-2">
                                        <div class="flex min-w-0 items-center gap-2">
                                            <span class="h-3 w-3 shrink-0 rounded-full border-2 border-neo-ink" x-bind:style="{ backgroundColor: group.color }"></span>
                                            <span class="font-display text-[11px] font-black uppercase text-neo-ink" x-text="group.label">READY / TERSEDIA</span>
                                        </div>
                                        <span class="rounded-[0.35rem] border-2 border-neo-ink bg-[#fff6cf] px-2 py-0.5 text-[9px] font-black uppercase text-neo-ink" x-text="`${group.items.length} akun`">0 akun</span>
                                    </div>
                                    <p class="mt-2 text-[10px] font-bold leading-4 text-[#475569]" x-text="group.helper"></p>

                                    <div class="mt-2 space-y-2">
                                        <template x-for="item in group.items" :key="item.id">
                                            <x-admin-dashboard.card as="article" padding="sm" shadow="xs" class="rounded-[0.45rem]">
                                                <div class="grid gap-2 sm:grid-cols-[1fr_auto]">
                                                    <div class="min-w-0">
                                                        <div class="flex flex-wrap items-center gap-1.5">
                                                            <span class="font-display text-[11px] font-black text-neo-ink" x-text="`#${item.displayIndex}`">#1</span>
                                                            <span class="rounded-[0.35rem] border-2 border-neo-ink px-1.5 py-0.5 text-[8px] font-black uppercase shadow-none" x-bind:class="{ 'bg-[#38bdf8]': item.status === 'TERSEDIA', 'bg-[#facc15]': item.status === 'DIPESAN', 'bg-[#7BD10A]': item.status === 'PAID', 'bg-[#fb7185] text-white': item.status === 'EXPIRED' || item.status === 'CANCELLED' || item.status === 'REPAIR', 'bg-[#cbd5e1]': item.status === 'RETIRED' }" x-text="item.status">TERSEDIA</span>
                                                            <span x-show="item.metadata" class="text-[9px] font-extrabold normal-case text-[#475569]" x-text="item.metadata"></span>
                                                            <span x-show="item.repair_needed" class="rounded-[0.35rem] border-2 border-neo-ink bg-white px-1 py-0.5 text-[8px] font-black uppercase">Perlu repair</span>
                                                        </div>

                                                        <div x-show="item.credential_format !== 'structured_account'" class="mt-1.5 break-all font-mono text-[10px] font-bold leading-4 text-neo-ink" x-text="item.secret"></div>
                                                        <div x-show="item.credential_format === 'structured_account' && item.credential_payload" class="mt-1.5 space-y-1 text-[10px] font-bold leading-4 text-neo-ink">
                                                            <p><span class="uppercase text-neo-ink/55">Email:</span> <code class="break-all" x-text="item.credential_payload?.email"></code></p>
                                                            <p><span class="uppercase text-neo-ink/55">Akses:</span> <span x-text="item.credential_payload?.access_method === 'otp_mail_link' ? `OTP Mail Link · ${item.access_domain || 'domain tidak tersedia'}` : (item.credential_payload?.access_method === 'auth_token' ? 'Auth Token' : 'Tidak ada')"></span></p>
                                                        </div>
                                                        <p x-show="item.credential_format === 'structured_account' && !item.credential_payload" class="mt-1.5 text-[10px] font-black text-[#b91c1c]">Payload tidak dapat dibaca. Read-only.</p>
                                                    </div>
                                                    <div class="flex flex-row gap-1.5 sm:flex-col">
                                                        <x-admin-dashboard.button type="button" variant="secondary" size="icon" class="rounded-[0.35rem]" aria-label="Ubah akun stok" x-bind:aria-expanded="item.editing" x-on:click="item.editing = ! item.editing" x-show="item.editable">✏️</x-admin-dashboard.button>
                                                        <form method="POST" x-bind:action="item.delete_url" x-show="item.editable" x-on:submit="if (! confirm('Hapus akun stok ini?')) $event.preventDefault()">
                                                            @csrf
                                                            <input type="hidden" name="_method" value="DELETE">
                                                            <x-admin-dashboard.button type="submit" variant="pink" size="icon" class="rounded-[0.35rem]" aria-label="Hapus akun stok">🗑️</x-admin-dashboard.button>
                                                        </form>
                                                        <form method="POST" x-bind:action="item.cancel_release_url" x-show="item.cancel_release_url" x-on:submit="if (! confirm('Batalkan order dan lepas stok ini?')) $event.preventDefault()">
                                                            @csrf
                                                            <x-admin-dashboard.button type="submit" variant="warning" size="icon" class="rounded-[0.35rem]" aria-label="Batalkan order dan lepas stok ini">↩</x-admin-dashboard.button>
                                                        </form>
                                                    </div>
                                                </div>

                                                <template x-if="item.editable && item.credential_format === 'structured_account'">
                                                    <form method="POST" x-bind:action="item.update_url" class="mt-3 grid gap-3 rounded-[0.45rem] border-2 border-neo-ink bg-[#fffdf0] p-2" x-show="item.editing" x-transition>
                                                        @csrf
                                                        <input type="hidden" name="_method" value="PATCH">
                                                        <input type="hidden" name="credential_format" value="structured_account">
                                                        <div class="grid gap-2 sm:grid-cols-2">
                                                            <div>
                                                                <x-admin-dashboard.field-label>Email</x-admin-dashboard.field-label>
                                                                <input name="email" type="email" maxlength="254" autocomplete="off" x-model="item.credential_payload.email" class="mt-1 w-full rounded-lg border-2 border-neo-ink px-2 py-2 text-xs font-bold">
                                                            </div>
                                                            <div>
                                                                <x-admin-dashboard.field-label>Password</x-admin-dashboard.field-label>
                                                                <textarea name="password" rows="2" maxlength="1024" autocomplete="new-password" x-model="item.credential_payload.password" class="mt-1 w-full rounded-lg border-2 border-neo-ink px-2 py-2 font-mono text-xs font-bold"></textarea>
                                                            </div>
                                                        </div>
                                                        <div>
                                                            <x-admin-dashboard.field-label>Metode akses</x-admin-dashboard.field-label>
                                                            <select name="access_method" x-model="item.credential_payload.access_method" x-on:change="item.credential_payload.access_value = ''" class="mt-1 w-full rounded-lg border-2 border-neo-ink px-2 py-2 text-xs font-bold">
                                                                <option value="none">Tidak ada</option>
                                                                <option value="otp_mail_link">OTP Mail Link</option>
                                                                <option value="auth_token">Auth Token</option>
                                                            </select>
                                                        </div>
                                                        <div x-show="item.credential_payload.access_method !== 'none'">
                                                            <x-admin-dashboard.field-label>Nilai metode akses</x-admin-dashboard.field-label>
                                                            <input name="access_value" x-bind:type="item.credential_payload.access_method === 'otp_mail_link' ? 'url' : 'text'" x-bind:maxlength="item.credential_payload.access_method === 'otp_mail_link' ? 2048 : 3500" x-model="item.credential_payload.access_value" class="mt-1 w-full rounded-lg border-2 border-neo-ink px-2 py-2 text-xs font-bold">
                                                        </div>
                                                        <div>
                                                            <x-admin-dashboard.field-label>Tutorial override</x-admin-dashboard.field-label>
                                                            <textarea name="tutorial_override" rows="2" maxlength="2000" x-model="item.credential_payload.tutorial_override" class="mt-1 w-full rounded-lg border-2 border-neo-ink px-2 py-2 text-xs font-bold"></textarea>
                                                        </div>
                                                        <div class="grid grid-cols-2 gap-2 sm:justify-end">
                                                            <x-admin-dashboard.button type="submit" variant="secondary" size="sm">Simpan</x-admin-dashboard.button>
                                                            <x-admin-dashboard.button type="button" variant="neutral" size="sm" x-on:click="item.editing = false">Batal</x-admin-dashboard.button>
                                                        </div>
                                                    </form>
                                                </template>

                                                <template x-if="item.editable && item.credential_format !== 'structured_account'">
                                                    <form method="POST" x-bind:action="item.update_url" class="mt-2 grid gap-2 sm:grid-cols-[1fr_auto]" x-show="item.editing" x-transition>
                                                        @csrf
                                                        <input type="hidden" name="_method" value="PATCH">
                                                        <x-admin-dashboard.textarea name="secret" rows="2" class="min-h-16 font-mono text-[11px]" x-model="item.secret" />
                                                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-1">
                                                            <x-admin-dashboard.button type="submit" variant="secondary" size="sm" class="self-start">Simpan</x-admin-dashboard.button>
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

                        <x-admin-dashboard.empty-state title="Belum ada akun tersedia." body="Tambahkan stok baru." x-show="manageStockItems.length === 0" class="rounded-neo-md p-4 text-sm font-bold text-neo-ink" />

                        <x-admin-dashboard.button type="button" variant="primary" class="mt-4 w-full" x-on:click="openStockModalFromManage()">
                            ➕ Tambah stok baru
                        </x-admin-dashboard.button>
                    </x-admin-dashboard.modal-shell>
                </x-admin-dashboard.modal-backdrop>
                @endif
