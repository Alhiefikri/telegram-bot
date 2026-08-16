<?php

namespace App\Services\Telegram;

final class TelegramTranslationCatalog
{
    /**
     * @var array<string, array{en: string, id?: string, ms?: string}>
     */
    private const TRANSLATIONS = [
        'translation.missing' => [
            'en' => 'Translation unavailable.',
        ],
        'language.picker.prompt' => [
            'id' => '🌐 Pilih bahasa',
            'en' => '🌐 Choose your language',
            'ms' => '🌐 Pilih bahasa',
        ],
        'welcome.default_name' => [
            'id' => 'Kak',
            'en' => 'there',
            'ms' => 'anda',
        ],
        'welcome.greeting.morning' => [
            'id' => 'Selamat pagi',
            'en' => 'Good morning',
            'ms' => 'Selamat pagi',
        ],
        'welcome.greeting.afternoon' => [
            'id' => 'Selamat siang',
            'en' => 'Good afternoon',
            'ms' => 'Selamat tengah hari',
        ],
        'welcome.greeting.evening' => [
            'id' => 'Selamat sore',
            'en' => 'Good evening',
            'ms' => 'Selamat petang',
        ],
        'welcome.greeting.night' => [
            'id' => 'Selamat malam',
            'en' => 'Good evening',
            'ms' => 'Selamat malam',
        ],
        'welcome.caption' => [
            'id' => ":greeting, :first_name — selamat datang di LHIE STORE\n━━━━━━━━━━━━━━\nTempat belanja produk digital yang simpel, cepat, dan langsung dari Telegram.\n\n🛍️ Lihat produk\n💳 Bayar via QRIS / transfer\n📦 Cek pesanan kapan aja\n⚡ Akun dikirim otomatis setelah pembayaran beres\n\nMau mulai dari mana nih?",
            'en' => ":greeting, :first_name — Welcome to LHIE STORE\n━━━━━━━━━━━━━━\nA simple, fast way to shop for digital products directly from Telegram.\n\n🛍️ Browse products\n💳 Pay by QRIS / transfer\n📦 Check your orders anytime\n⚡ Accounts are delivered automatically after payment is confirmed\n\nWhere would you like to start?",
            'ms' => ":greeting, :first_name — selamat datang ke LHIE STORE\n━━━━━━━━━━━━━━\nCara mudah dan pantas untuk membeli produk digital terus dari Telegram.\n\n🛍️ Lihat produk\n💳 Bayar melalui QRIS / pindahan bank\n📦 Semak pesanan bila-bila masa\n⚡ Akaun dihantar secara automatik selepas bayaran disahkan\n\nAnda mahu mula dari mana?",
        ],
        'welcome.action.products' => [
            'id' => '🛍️ Lihat Produk',
            'en' => '🛍️ Browse Products',
            'ms' => '🛍️ Lihat Produk',
        ],
        'welcome.action.orders' => [
            'id' => '📦 Cek Pesanan',
            'en' => '📦 Check Orders',
            'ms' => '📦 Semak Pesanan',
        ],
        'menu.title' => [
            'id' => "☰ Menu utama\nMau ngapain dulu? Pilih tombol di bawah biar sat-set.",
            'en' => "☰ Main menu\nWhat would you like to do?",
            'ms' => "☰ Menu utama\nApa yang anda mahu lakukan? Pilih butang di bawah.",
        ],
        'menu.maintenance' => [
            'id' => '⏸️ Checkout sedang maintenance. Produk dan keranjang tetap bisa diakses; Order baru sementara ditahan.',
            'en' => '⏸️ Checkout is under maintenance. Products and the cart remain available; new Orders are temporarily paused.',
            'ms' => '⏸️ Checkout sedang diselenggara. Produk dan troli masih boleh diakses; pesanan baharu ditangguhkan buat sementara.',
        ],
        'maintenance.admin_note' => [
            'id' => "\n\nCatatan admin:\n:note",
            'en' => "\n\nAdmin note:\n:note",
            'ms' => "\n\nNota admin:\n:note",
        ],
        'menu.products' => [
            'id' => '🛍️ Lihat Produk',
            'en' => '🛍️ Browse Products',
            'ms' => '🛍️ Lihat Produk',
        ],
        'menu.orders' => [
            'id' => '📦 Pesanan',
            'en' => '📦 Orders',
            'ms' => '📦 Pesanan',
        ],
        'menu.support' => [
            'id' => '💬 Support',
            'en' => '💬 Support',
            'ms' => '💬 Sokongan',
        ],
        'menu.language' => [
            'id' => '🌐 Bahasa',
            'en' => '🌐 Language',
            'ms' => '🌐 Bahasa',
        ],
        'menu.placeholder' => [
            'id' => 'Pilih tombol atau ketik /produk',
            'en' => 'Choose a button or type /products',
            'ms' => 'Pilih butang atau taip /produk',
        ],
        'support.message' => [
            'id' => "💬 Support\nAda kendala order, pembayaran, atau login akun?\n\nKirim detail masalah kamu di sini. Kalau soal pesanan, sertakan invoice biar lebih cepat dicek.",
            'en' => "💬 Support\nNeed help with an order, payment, or account login?\n\nSend the details here. Include the invoice number for order-related help.",
            'ms' => "💬 Sokongan\nAda masalah dengan pesanan, bayaran atau log masuk akaun?\n\nHantar butiran masalah anda di sini. Jika berkaitan pesanan, sertakan nombor invois supaya kami boleh semak dengan lebih cepat.",
        ],
        'support.contact' => [
            'id' => '💬 Hubungi Admin',
            'en' => '💬 Chat with Admin',
            'ms' => '💬 Hubungi Admin',
        ],
        'account.title' => [
            'id' => '👤 Akun Saya',
            'en' => '👤 My Account',
            'ms' => '👤 Akaun Saya',
        ],
        'account.name' => [
            'id' => 'Nama: ',
            'en' => 'Name: ',
            'ms' => 'Nama: ',
        ],
        'account.username' => [
            'id' => 'Username: ',
            'en' => 'Username: ',
            'ms' => 'Nama pengguna: ',
        ],
        'account.total_orders' => [
            'id' => 'Total pesanan: ',
            'en' => 'Total orders: ',
            'ms' => 'Jumlah pesanan: ',
        ],
        'account.latest_order' => [
            'id' => 'Pesanan terakhir: ',
            'en' => 'Latest order: ',
            'ms' => 'Pesanan terakhir: ',
        ],
        'account.follow_up' => [
            'id' => 'Mau cek pesanan kamu?',
            'en' => 'Want to check your orders?',
            'ms' => 'Mahu semak pesanan anda?',
        ],
        'account.orders_button' => [
            'id' => '📦 Lihat Pesanan',
            'en' => '📦 View Orders',
            'ms' => '📦 Semak Pesanan',
        ],
        'status.payment.pending' => [
            'id' => 'Menunggu pembayaran',
            'en' => 'Awaiting payment',
            'ms' => 'Menunggu bayaran',
        ],
        'status.payment.paid' => [
            'id' => 'Dibayar',
            'en' => 'Paid',
            'ms' => 'Dibayar',
        ],
        'status.payment.failed' => [
            'id' => 'Gagal',
            'en' => 'Failed',
            'ms' => 'Gagal',
        ],
        'status.payment.expired' => [
            'id' => 'Kedaluwarsa',
            'en' => 'Expired',
            'ms' => 'Tamat tempoh',
        ],
        'status.payment.refunded' => [
            'id' => 'Dikembalikan',
            'en' => 'Refunded',
            'ms' => 'Dipulangkan',
        ],
        'community_gate.join_button' => [
            'id' => '📢 Gabung kanal',
            'en' => '📢 Join Channel',
            'ms' => '📢 Sertai Saluran',
        ],
        'community_gate.verify_button' => [
            'id' => '✅ Saya sudah bergabung',
            'en' => '✅ I Have Joined',
            'ms' => '✅ Saya Sudah Sertai',
        ],
        'community_gate.not_joined' => [
            'id' => 'Kamu belum terdeteksi join channel.',
            'en' => 'You have not joined the channel yet.',
            'ms' => 'Anda belum dikesan menyertai saluran.',
        ],
        'community_gate.message' => [
            'id' => "🔒 Gabung kanal dulu\n━━━━━━━━━━━━━━\nAgar bisa lanjut belanja dan dapat info stok terbaru, kamu perlu bergabung dengan kanal Store Channel dulu.\n\nKalau sudah bergabung tapi status kamu belum bisa dicek, tekan tombol “Saya sudah bergabung” untuk coba lagi. Jika Telegram sedang gangguan, coba lagi nanti.",
            'en' => "🔒 Join the channel first\n━━━━━━━━━━━━━━\nBefore you can continue shopping, please join the Store Channel. Then press “I Have Joined” to verify.",
            'ms' => "🔒 Sertai saluran dahulu\n━━━━━━━━━━━━━━\nUntuk terus membeli-belah dan menerima maklumat stok terkini, sila sertai Store Channel dahulu.\n\nJika sudah sertai tetapi status anda belum dapat disemak, tekan butang “Saya Sudah Sertai” untuk cuba lagi. Jika Telegram sedang mengalami gangguan, cuba lagi nanti.",
        ],
        'privacy.message' => [
            'id' => "🔒 Lanjut di chat pribadi\nDemi keamanan, detail order, pembayaran, dan data akun tidak dikirim ke group.\n\nBuka chat pribadi dengan bot untuk lanjut.",
            'en' => "🔒 Continue in a private chat\nFor security, order, payment, and account details are not sent to groups.\n\nOpen a private chat with the bot to continue.",
            'ms' => "🔒 Teruskan di chat peribadi\nDemi keselamatan, butiran pesanan, bayaran dan data akaun tidak dihantar ke kumpulan.\n\nBuka chat peribadi dengan bot untuk teruskan.",
        ],
        'privacy.callback' => [
            'id' => 'Demi keamanan, lanjutkan di chat pribadi dengan bot.',
            'en' => 'For security, continue in a private chat with the bot.',
            'ms' => 'Demi keselamatan, teruskan di chat peribadi dengan bot.',
        ],
        'callback.community_gate_required' => [
            'id' => 'Gabung Store Channel dulu supaya bisa lanjut belanja.',
            'en' => 'Join the Store Channel first to continue shopping.',
            'ms' => 'Sertai Store Channel dahulu untuk terus membeli-belah.',
        ],
        'callback.unknown_action' => [
            'id' => 'Aksi ini belum dikenali. Coba pilih menu lain.',
            'en' => 'This action is not recognized. Please choose another option.',
            'ms' => 'Tindakan ini tidak dikenali. Sila pilih menu lain.',
        ],
        'catalog.uncategorized' => [
            'id' => '📂 Lainnya',
            'en' => '📂 Other',
            'ms' => '📂 Lain-lain',
        ],
        'catalog.empty' => [
            'id' => "🛍️ Produk masih kosong nih\nBelum ada produk yang bisa ditampilkan sekarang.\n\nCek lagi nanti ya.",
            'en' => "🛍️ No products yet\nThere are no products to show right now.\n\nPlease check again later.",
            'ms' => "🛍️ Tiada produk lagi\nBelum ada produk untuk dipaparkan sekarang.\n\nSila semak lagi nanti.",
        ],
        'catalog.header' => [
            'id' => "🛍️ Lihat Produk\n━━━━━━━━━━━━━━\nPilih kategori yang kamu mau.\n\n✨ Produk tersedia dan kosong tetap kelihatan jelas di sini.",
            'en' => "🛍️ Browse Products\n━━━━━━━━━━━━━━\nChoose a category.\n\n✨ Available and sold-out products are clearly shown here.",
            'ms' => "🛍️ Lihat Produk\n━━━━━━━━━━━━━━\nPilih kategori yang anda mahu.\n\n✨ Produk yang tersedia dan habis stok kekal dipaparkan dengan jelas di sini.",
        ],
        'catalog.check_status' => [
            'id' => '🔄 Cek Status Checkout',
            'en' => '🔄 Check Checkout Status',
            'ms' => '🔄 Semak Status Checkout',
        ],
        'catalog.back_categories' => [
            'id' => '⬅️ Balik ke Kategori',
            'en' => '⬅️ Back to Categories',
            'ms' => '⬅️ Kembali ke Kategori',
        ],
        'catalog.price' => [
            'id' => '💰 Harga: Rp',
            'en' => '💰 Price: Rp',
            'ms' => '💰 Harga: Rp',
        ],
        'catalog.status.available' => [
            'id' => '✅ Status: Tersedia',
            'en' => '✅ Status: Available',
            'ms' => '✅ Status: Tersedia',
        ],
        'catalog.status.sold_out' => [
            'id' => '❌ Status: Stok kosong',
            'en' => '❌ Status: Sold out',
            'ms' => '❌ Status: Stok habis',
        ],
        'catalog.stock' => [
            'id' => '📦 Stok: ',
            'en' => '📦 Stock: ',
            'ms' => '📦 Stok: ',
        ],
        'catalog.sold' => [
            'id' => '🛒 Terjual: ',
            'en' => '🛒 Sold: ',
            'ms' => '🛒 Terjual: ',
        ],
        'catalog.details' => [
            'id' => '📝 Detail produk',
            'en' => '📝 Product details',
            'ms' => '📝 Butiran produk',
        ],
        'catalog.quantity.back_details' => [
            'id' => '⬅️ Detail Produk',
            'en' => '⬅️ Product Details',
            'ms' => '⬅️ Butiran Produk',
        ],
        'catalog.category.back' => [
            'id' => '⬅️ Pilih Kategori',
            'en' => '⬅️ Choose Category',
            'ms' => '⬅️ Pilih Kategori',
        ],
        'catalog.payment_method' => [
            'id' => 'Pilih cara bayar di bawah.',
            'en' => 'Choose a payment method below.',
            'ms' => 'Pilih kaedah bayaran di bawah.',
        ],
        'catalog.payment.qris_button' => [
            'id' => '💳 QRIS · Rp:amount',
            'en' => '💳 QRIS · Rp:amount',
            'ms' => '💳 QRIS · Rp:amount',
        ],
        'catalog.payment.binance_button' => [
            'id' => '💎 Binance Pay · :amount USDT',
            'en' => '💎 Binance Pay · :amount USDT',
            'ms' => '💎 Binance Pay · :amount USDT',
        ],
        'catalog.payment.qris_total' => [
            'id' => '💳 QRIS: Rp:amount',
            'en' => '💳 QRIS: Rp:amount',
            'ms' => '💳 QRIS: Rp:amount',
        ],
        'catalog.payment.binance_total' => [
            'id' => '💎 Binance Pay: :amount USDT',
            'en' => '💎 Binance Pay: :amount USDT',
            'ms' => '💎 Binance Pay: :amount USDT',
        ],
        'catalog.quantity.binance_total' => [
            'id' => ':amount USDT',
            'en' => ':amount USDT',
            'ms' => ':amount USDT',
        ],
        'catalog.quantity.qris_total' => [
            'id' => 'Rp:amount',
            'en' => 'Rp:amount',
            'ms' => 'Rp:amount',
        ],
        'catalog.after_quantity' => [
            'id' => 'Setelah pilih jumlah, checkout langsung dibuat.',
            'en' => 'After you choose the quantity, checkout is created immediately.',
            'ms' => 'Selepas pilih kuantiti, checkout akan dibuat serta-merta.',
        ],
        'catalog.sold_out_guidance' => [
            'id' => 'Stoknya lagi habis. Cek produk lain atau tunggu restock berikutnya.',
            'en' => 'This product is sold out. Check another product or wait for a restock.',
            'ms' => 'Stok produk ini habis. Lihat produk lain atau tunggu stok baharu.',
        ],
        'catalog.quantity.title' => [
            'id' => '🔢 Pilih jumlah',
            'en' => '🔢 Choose quantity',
            'ms' => '🔢 Pilih kuantiti',
        ],
        'catalog.quantity.product' => [
            'id' => '📦 Produk: ',
            'en' => '📦 Product: ',
            'ms' => '📦 Produk: ',
        ],
        'catalog.quantity.available_stock' => [
            'id' => '✅ Stok tersedia: ',
            'en' => '✅ Available stock: ',
            'ms' => '✅ Stok tersedia: ',
        ],
        'catalog.quantity.per_item' => [
            'id' => ' per item',
            'en' => ' / item',
            'ms' => ' / unit',
        ],
        'catalog.quantity.direct_prompt' => [
            'id' => 'Tekan jumlah yang kamu mau. Checkout langsung dibuat tanpa langkah tambahan.',
            'en' => 'Tap the quantity you want. Checkout will be created immediately with no extra step.',
            'ms' => 'Tekan kuantiti yang anda mahu. Checkout akan dibuat serta-merta tanpa langkah tambahan.',
        ],
        'catalog.quantity.prompt' => [
            'id' => 'Mau ambil berapa item?',
            'en' => 'How many items would you like?',
            'ms' => 'Berapa unit yang anda mahu?',
        ],
        'catalog.category.title' => [
            'id' => '🛍️ Daftar Produk',
            'en' => '🛍️ Product List',
            'ms' => '🛍️ Senarai Produk',
        ],
        'catalog.category.label' => [
            'id' => 'Kategori: ',
            'en' => 'Category: ',
            'ms' => 'Kategori: ',
        ],
        'catalog.category.choose_product' => [
            'id' => 'Pilih produk untuk melihat detail.',
            'en' => 'Choose a product to view its details.',
            'ms' => 'Pilih produk untuk melihat butirannya.',
        ],
        'catalog.category.empty' => [
            'id' => "📂 Belum ada produk di kategori ini\nCoba cek kategori lain dulu ya.",
            'en' => "📂 No products in this category\nPlease check another category.",
            'ms' => "📂 Tiada produk dalam kategori ini\nSila semak kategori lain.",
        ],
        'catalog.category.unavailable' => [
            'id' => 'Kategori ini sudah tidak tersedia. Coba pilih kategori lain.',
            'en' => 'This category is no longer available. Please choose another category.',
            'ms' => 'Kategori ini tidak lagi tersedia. Sila pilih kategori lain.',
        ],
        'catalog.description_missing' => [
            'id' => 'Deskripsi produk belum ditambahkan. Tapi tenang, produknya tetap bisa dicek dari info harga dan stok.',
            'en' => 'No product description has been added yet. You can still check the price and stock information.',
            'ms' => 'Penerangan produk belum ditambahkan. Anda masih boleh menyemak harga dan maklumat stok.',
        ],
        'catalog.product_unavailable' => [
            'id' => 'Produk ini belum bisa dibeli sekarang. Coba pilih produk lain.',
            'en' => 'This product cannot be purchased right now. Please choose another product.',
            'ms' => 'Produk ini tidak boleh dibeli sekarang. Sila pilih produk lain.',
        ],
        'catalog.balance_soon' => [
            'id' => 'Fitur saldo segera hadir.',
            'en' => 'Balance is coming soon.',
            'ms' => 'Ciri baki akan tersedia tidak lama lagi.',
        ],
        'search.prompt' => [
            'id' => "Mau cari produk apa?\n\nKetik seperti ini:\n/cari netflix\n\nBisa pakai nama produk, kategori, atau kata kunci lain.",
            'en' => "What product are you looking for?\n\nType it like this:\n/search netflix\n\nYou can use a product name, category, or other keyword.",
            'ms' => "Apakah produk yang anda cari?\n\nTaip seperti ini:\n/cari netflix\n\nAnda boleh gunakan nama produk, kategori atau kata kunci lain.",
        ],
        'search.no_results' => [
            'id' => 'Produk dengan kata kunci ":keyword" belum ketemu.'."\n\n".'Coba kata kunci lain, atau buka /produk untuk lihat semua produk.',
            'en' => 'No products matched ":keyword".'."\n\n".'Try another keyword or open /products to browse all products.',
            'ms' => 'Produk dengan kata kunci ":keyword" belum ditemui.'."\n\n".'Cuba kata kunci lain atau buka /produk untuk melihat semua produk.',
        ],
        'search.title' => [
            'id' => '🔎 Hasil pencarian: ',
            'en' => '🔎 Search results: ',
            'ms' => '🔎 Hasil carian: ',
        ],
        'search.more' => [
            'id' => 'Masih ada hasil lain. Coba pakai kata kunci yang lebih spesifik biar lebih cepat ketemu.',
            'en' => 'There are more results. Try a more specific keyword.',
            'ms' => 'Masih ada hasil lain. Cuba kata kunci yang lebih khusus.',
        ],
        'search.browse' => [
            'id' => '🛍️ Lihat Produk',
            'en' => '🛍️ Browse Products',
            'ms' => '🛍️ Lihat Produk',
        ],
        'cart.quantity_invalid' => [
            'id' => 'Angkanya belum pas. Pilih jumlah sesuai stok yang tersedia.',
            'en' => 'That quantity is not available. Please choose a quantity within the available stock.',
            'ms' => 'Kuantiti itu tidak tersedia. Sila pilih kuantiti dalam had stok yang ada.',
        ],
        'cart.stock_conflict' => [
            'id' => 'Produk belum bisa masuk keranjang. Stoknya berubah atau sudah tidak tersedia.',
            'en' => 'This product could not be added to your cart because stock changed or is no longer available.',
            'ms' => 'Produk ini tidak dapat dimasukkan ke dalam troli kerana stok berubah atau produk tidak lagi tersedia.',
        ],
        'cart.added_maintenance' => [
            'id' => "🛒 Masuk keranjang\n📦 :product\n🔢 Jumlah: :quantity\n💰 Subtotal: Rp:subtotal\n\nCheckout sedang maintenance. Cek status untuk melihat kapan Order baru bisa dibuat.",
            'en' => "🛒 Added to cart\n📦 :product\n🔢 Quantity: :quantity\n💰 Subtotal: Rp:subtotal\n\nCheckout is under maintenance. Check the status to see when new Orders can be created.",
            'ms' => "🛒 Ditambahkan ke troli\n📦 :product\n🔢 Kuantiti: :quantity\n💰 Subjumlah: Rp:subtotal\n\nCheckout sedang diselenggara. Semak status untuk melihat bila pesanan baharu boleh dibuat.",
        ],
        'cart.added' => [
            'id' => "🛒 Masuk keranjang\n📦 :product\n🔢 Jumlah: :quantity\n💰 Subtotal: Rp:subtotal\n\nSudah cocok? Lanjutkan checkout di bawah.",
            'en' => "🛒 Added to cart\n📦 :product\n🔢 Quantity: :quantity\n💰 Subtotal: Rp:subtotal\n\nLooks right? Continue to checkout below.",
            'ms' => "🛒 Ditambahkan ke troli\n📦 :product\n🔢 Kuantiti: :quantity\n💰 Subjumlah: Rp:subtotal\n\nSemuanya sudah sesuai? Teruskan checkout di bawah.",
        ],
        'cart.status_button' => [
            'id' => '🔄 Cek Status Checkout',
            'en' => '🔄 Check Checkout Status',
            'ms' => '🔄 Semak Status Checkout',
        ],
        'cart.checkout_button' => [
            'id' => '💳 Lanjut Checkout',
            'en' => '💳 Continue to Checkout',
            'ms' => '💳 Teruskan Checkout',
        ],
        'cart.cancelled' => [
            'id' => "🛒 Keranjang aktif sudah dibatalkan.\n\nKalau mau pilih produk lagi, buka /produk ya.",
            'en' => "🛒 Your active cart has been cancelled.\n\nOpen /products to browse again.",
            'ms' => "🛒 Troli aktif telah dibatalkan.\n\nBuka /produk untuk melihat produk lagi.",
        ],
        'cart.product_unavailable' => [
            'id' => 'Produk ini belum bisa dibeli sekarang. Coba pilih produk lain.',
            'en' => 'This product cannot be purchased right now. Please choose another product.',
            'ms' => 'Produk ini tidak boleh dibeli sekarang. Sila pilih produk lain.',
        ],
        'cart.binance_unavailable_product' => [
            'id' => 'Binance Pay sedang tidak tersedia untuk produk ini. Pilih QRIS untuk melanjutkan.',
            'en' => 'Binance Pay is not available for this product. Choose QRIS to continue.',
            'ms' => 'Binance Pay tidak tersedia untuk produk ini. Pilih QRIS untuk meneruskan.',
        ],
        'cart.product_or_quantity_unavailable' => [
            'id' => 'Produk atau jumlah ini sudah tidak tersedia. Coba pilih produk lain.',
            'en' => 'This product or quantity is no longer available. Please choose another product.',
            'ms' => 'Produk atau kuantiti ini tidak lagi tersedia. Sila pilih produk lain.',
        ],
        'cart.binance_unavailable_cart' => [
            'id' => 'Binance Pay sedang tidak tersedia untuk keranjang ini. Pilih QRIS untuk melanjutkan.',
            'en' => 'Binance Pay is not available for this cart. Choose QRIS to continue.',
            'ms' => 'Binance Pay tidak tersedia untuk troli ini. Pilih QRIS untuk meneruskan.',
        ],
        'checkout.maintenance' => [
            'id' => '⏸️ Checkout sedang dalam maintenance. Kamu tetap bisa melihat produk, stok, dan mengelola keranjang, tetapi belum dapat membuat pesanan baru. Pesanan yang sudah dibuat tetap diproses. Silakan cek status kembali beberapa saat lagi.',
            'en' => '⏸️ Checkout is under maintenance. You can still browse products, view stock, and manage your cart, but new Orders cannot be created right now. Existing Orders continue to be processed. Check status again in a little while.',
            'ms' => '⏸️ Checkout sedang diselenggara. Anda masih boleh melihat produk, stok dan mengurus troli, tetapi pesanan baharu belum boleh dibuat. Pesanan sedia ada terus diproses. Semak status sekali lagi sebentar lagi.',
        ],
        'checkout.status_button' => [
            'id' => '🔄 Cek Status Checkout',
            'en' => '🔄 Check Checkout Status',
            'ms' => '🔄 Semak Status Checkout',
        ],
        'checkout.status_again' => [
            'id' => '🔄 Cek Status Lagi',
            'en' => '🔄 Check Status Again',
            'ms' => '🔄 Semak Status Lagi',
        ],
        'checkout.reopened' => [
            'id' => '✅ Checkout sudah dibuka kembali. Kamu bisa membuat Order baru sekarang.',
            'en' => '✅ Checkout is open again. You can create a new Order now.',
            'ms' => '✅ Checkout telah dibuka semula. Anda boleh membuat pesanan baharu sekarang.',
        ],
        'checkout.browse_button' => [
            'id' => '🛍️ Lihat Produk',
            'en' => '🛍️ Browse Products',
            'ms' => '🛍️ Lihat Produk',
        ],
        'checkout.continue_button' => [
            'id' => '💳 Lanjut Checkout',
            'en' => '💳 Continue to Checkout',
            'ms' => '💳 Teruskan Checkout',
        ],
        'checkout.orders_button' => [
            'id' => '📦 Cek Pesanan',
            'en' => '📦 Check Orders',
            'ms' => '📦 Semak Pesanan',
        ],
        'checkout.invalid_cart' => [
            'id' => "Checkout belum berhasil.\nKeranjang kosong, stok berubah, atau produknya sudah tidak tersedia.\n\nCoba buka /produk lagi dan pilih produk ulang.",
            'en' => "Checkout could not be completed.\nYour cart is empty, stock changed, or the product is no longer available.\n\nOpen /products and choose the product again.",
            'ms' => "Checkout tidak dapat diselesaikan.\nTroli kosong, stok berubah atau produk tidak lagi tersedia.\n\nBuka /produk dan pilih produk sekali lagi.",
        ],
        'checkout.processing' => [
            'id' => 'Checkout sedang diproses. Tunggu sebentar.',
            'en' => 'Checkout is being processed. Please wait a moment.',
            'ms' => 'Checkout sedang diproses. Sila tunggu sebentar.',
        ],
        'checkout.payment_method' => [
            'id' => 'Pilih metode pembayaran di bawah.',
            'en' => 'Choose a payment method below.',
            'ms' => 'Pilih kaedah bayaran di bawah.',
        ],
        'payment.qris_cross_border' => [
            'id' => "🌏 QRIS Antarnegara saat ini mendukung koneksi dengan:\n🇮🇩 Indonesia · 🇹🇭 Thailand · 🇲🇾 Malaysia\n🇸🇬 Singapura · 🇯🇵 Jepang · 🇰🇷 Korea Selatan\n🇨🇳 Tiongkok\n📱 Ketersediaan tetap bergantung pada aplikasi pembayaran/PJP dan merchant yang berpartisipasi.",
            'en' => "🌏 QRIS Cross-Border currently supports connections with:\n🇮🇩 Indonesia · 🇹🇭 Thailand · 🇲🇾 Malaysia\n🇸🇬 Singapore · 🇯🇵 Japan · 🇰🇷 South Korea\n🇨🇳 China\n📱 Availability still depends on the payment app/provider and participating merchant.",
            'ms' => "🌏 QRIS Rentas Sempadan kini menyokong sambungan dengan:\n🇮🇩 Indonesia · 🇹🇭 Thailand · 🇲🇾 Malaysia\n🇸🇬 Singapura · 🇯🇵 Jepun · 🇰🇷 Korea Selatan\n🇨🇳 China\n📱 Ketersediaan masih bergantung pada aplikasi/penyedia pembayaran dan peniaga yang mengambil bahagian.",
        ],
        'checkout.payment.qris' => [
            'id' => '💳 QRIS',
            'en' => '💳 QRIS',
            'ms' => '💳 QRIS',
        ],
        'checkout.payment.binance' => [
            'id' => '💎 USDT · Binance Pay (:amount USDT)',
            'en' => '💎 USDT · Binance Pay (:amount USDT)',
            'ms' => '💎 USDT · Binance Pay (:amount USDT)',
        ],
        'payment.action.website' => [
            'id' => '💳 Bayar via Website',
            'en' => '💳 Pay via Website',
            'ms' => '💳 Bayar melalui Laman Web',
        ],
        'payment.action.check' => [
            'id' => '✅ Cek Pembayaran',
            'en' => '✅ Check Payment',
            'ms' => '✅ Semak Bayaran',
        ],
        'payment.action.cancel' => [
            'id' => '❌ Batalkan Order',
            'en' => '❌ Cancel Order',
            'ms' => '❌ Batalkan Pesanan',
        ],
        'payment.cancel.unavailable' => [
            'id' => 'Pesanan sudah tidak bisa dibatalkan.',
            'en' => 'This order can no longer be cancelled.',
            'ms' => 'Pesanan ini tidak lagi boleh dibatalkan.',
        ],
        'payment.cancel.not_found' => [
            'id' => "Pesanan tidak ditemukan atau sudah tidak bisa dibatalkan.\n\nCek status terbaru lewat /status.",
            'en' => "Order not found or can no longer be cancelled.\n\nCheck the latest status with /status.",
            'ms' => "Pesanan tidak ditemui atau tidak lagi boleh dibatalkan.\n\nSemak status terkini melalui /status.",
        ],
        'payment.cancel.verification_unavailable' => [
            'id' => 'Status pembayaran belum dapat diverifikasi.',
            'en' => 'Payment status cannot be verified yet.',
            'ms' => 'Status bayaran belum dapat disahkan.',
        ],
        'payment.cancel.verification_unavailable_detail' => [
            'id' => 'Status pembayaran belum dapat diverifikasi. Pesanan tidak dibatalkan; coba lagi sebentar.',
            'en' => 'Payment status cannot be verified yet. Your order was not cancelled; please try again shortly.',
            'ms' => 'Status bayaran belum dapat disahkan. Pesanan anda tidak dibatalkan; sila cuba lagi sebentar lagi.',
        ],
        'payment.cancel.paid' => [
            'id' => 'Pembayaran sudah diterima.',
            'en' => 'Payment has already been received.',
            'ms' => 'Bayaran telah diterima.',
        ],
        'payment.cancel.paid_detail' => [
            'id' => "Pembayaran sudah diterima, jadi pesanan tidak bisa dibatalkan.\n\nCek status terbaru lewat /status.",
            'en' => "Payment has already been received, so this order cannot be cancelled.\n\nCheck the latest status with /status.",
            'ms' => "Bayaran telah diterima, jadi pesanan ini tidak boleh dibatalkan.\n\nSemak status terkini melalui /status.",
        ],
        'payment.cancel.processed' => [
            'id' => 'Pesanan sudah tidak bisa dibatalkan.',
            'en' => 'This order can no longer be cancelled.',
            'ms' => 'Pesanan ini tidak lagi boleh dibatalkan.',
        ],
        'payment.cancel.processed_detail' => [
            'id' => "Pesanan sudah diproses atau tidak bisa dibatalkan lagi.\n\nCek status terbaru lewat /status.",
            'en' => "This order has been processed or can no longer be cancelled.\n\nCheck the latest status with /status.",
            'ms' => "Pesanan ini telah diproses atau tidak lagi boleh dibatalkan.\n\nSemak status terkini melalui /status.",
        ],
        'payment.cancel.success' => [
            'id' => 'Order dibatalkan dan stok sudah dilepas.',
            'en' => 'Order cancelled and stock released.',
            'ms' => 'Pesanan dibatalkan dan stok telah dilepaskan.',
        ],
        'payment.cancel.success_detail' => [
            'id' => "❌ Pesanan dibatalkan\nInvoice :invoice dibatalkan dan stok sudah dilepas kembali.\n\nKalau mau pesan lagi, buka /produk ya.",
            'en' => "❌ Order cancelled\nInvoice :invoice was cancelled and the stock was released.\n\nOpen /products if you would like to order again.",
            'ms' => "❌ Pesanan dibatalkan\nInvois :invoice dibatalkan dan stok telah dilepaskan semula.\n\nJika mahu membuat pesanan lagi, buka /produk.",
        ],
        'payment.action.paid' => [
            'id' => '✅ Saya Sudah Bayar',
            'en' => "✅ I've Paid",
            'ms' => '✅ Saya Sudah Bayar',
        ],
        'payment.action.change_order_id' => [
            'id' => '🧾 Ganti Order ID',
            'en' => '🧾 Change Order ID',
            'ms' => '🧾 Tukar ID Pesanan',
        ],
        'payment.action.contact_admin' => [
            'id' => 'Hubungi Admin',
            'en' => 'Chat with Admin',
            'ms' => 'Hubungi Admin',
        ],
        'payment.method.manual' => [
            'id' => 'QR Manual',
            'en' => 'Manual QR',
            'ms' => 'QR Manual',
        ],
        'payment.checkout_created' => [
            'id' => '✅ Checkout berhasil dibuat',
            'en' => '✅ Checkout created',
            'ms' => '✅ Checkout berjaya dibuat',
        ],
        'payment.method.bank_transfer' => [
            'id' => 'Transfer bank',
            'en' => 'Bank transfer',
            'ms' => 'Pindahan bank',
        ],
        'payment.method.unknown' => [
            'id' => 'Tidak diketahui',
            'en' => 'Unknown',
            'ms' => 'Tidak diketahui',
        ],
        'payment.invoice' => [
            'id' => '🧾 Invoice: :invoice',
            'en' => '🧾 Invoice: :invoice',
            'ms' => '🧾 Invois: :invoice',
        ],
        'payment.total' => [
            'id' => '💰 Total: :amount',
            'en' => '💰 Total: :amount',
            'ms' => '💰 Jumlah: :amount',
        ],
        'payment.pay_via' => [
            'id' => '💳 Bayar via: :method',
            'en' => '💳 Pay via: :method',
            'ms' => '💳 Bayar melalui: :method',
        ],
        'payment.order' => [
            'id' => '📦 Pesanan: :items',
            'en' => '📦 Order: :items',
            'ms' => '📦 Pesanan: :items',
        ],
        'payment.deadline' => [
            'id' => '⏳ Batas bayar:',
            'en' => '⏳ Payment deadline:',
            'ms' => '⏳ Tarikh akhir bayaran:',
        ],
        'payment.manual.scan_qr' => [
            'id' => '📲 Scan QR manual di atas.',
            'en' => '📲 Scan the manual QR above.',
            'ms' => '📲 Imbas QR manual di atas.',
        ],
        'payment.manual.before_pay' => [
            'id' => 'Sebelum bayar, pastikan:',
            'en' => 'Before paying, make sure:',
            'ms' => 'Sebelum membuat bayaran, pastikan:',
        ],
        'payment.manual.qr_name' => [
            'id' => '• Nama QR: :name',
            'en' => '• QR name: :name',
            'ms' => '• Nama QR: :name',
        ],
        'payment.manual.exact_amount' => [
            'id' => '• Nominal wajib sama persis: :amount',
            'en' => '• Amount must match exactly: :amount',
            'ms' => '• Jumlah mesti sama tepat: :amount',
        ],
        'payment.manual.deadline_guidance' => [
            'id' => 'Kalau belum bayar/kirim bukti sampai batas waktu ini, order otomatis expired dan stok kembali.',
            'en' => 'If payment proof is not sent by this deadline, the order expires automatically and stock is released.',
            'ms' => 'Jika bayaran atau bukti bayaran belum dihantar sebelum tarikh ini, pesanan tamat tempoh secara automatik dan stok dilepaskan.',
        ],
        'payment.manual.proof_prompt' => [
            'id' => 'Setelah bayar, kirim screenshot bukti pembayaran ke bot ini sebagai FOTO dengan caption:',
            'en' => 'After paying, send a payment screenshot to this bot as a PHOTO with this caption:',
            'ms' => 'Selepas membayar, hantar tangkapan skrin pembayaran ke bot ini sebagai FOTO dengan kapsyen:',
        ],
        'payment.manual.help' => [
            'id' => 'Kalau kesusahan, chat admin @:username.',
            'en' => 'Need help? Chat admin @:username.',
            'ms' => 'Perlukan bantuan? Hubungi admin @:username.',
        ],
        'payment.pakasir.fallback.title' => [
            'id' => '⚠️ Checkout QRIS dialihkan',
            'en' => '⚠️ QRIS checkout redirected',
            'ms' => '⚠️ Checkout QRIS dialihkan',
        ],
        'payment.pakasir.fallback.explanation' => [
            'id' => 'QRIS langsung belum bisa dibuat otomatis. Order tetap pending dan stok tetap dipegang.',
            'en' => 'The direct QRIS code could not be created automatically. The order is still awaiting payment and the stock remains reserved.',
            'ms' => 'QRIS terus tidak dapat dibuat secara automatik. Pesanan masih menunggu bayaran dan stok masih dipegang.',
        ],
        'payment.pakasir.fallback.continue' => [
            'id' => 'Gunakan QR/link website di bawah untuk lanjut bayar.',
            'en' => 'Use the QR code/payment link below to continue payment.',
            'ms' => 'Gunakan QR atau pautan laman web di bawah untuk meneruskan bayaran.',
        ],
        'payment.products' => [
            'id' => '📦 Produk: :items',
            'en' => '📦 Products: :items',
            'ms' => '📦 Produk: :items',
        ],
        'payment.quantity' => [
            'id' => '🔢 Jumlah: :quantity',
            'en' => '🔢 Quantity: :quantity',
            'ms' => '🔢 Kuantiti: :quantity',
        ],
        'payment.pakasir.exact_amount' => [
            'id' => '⚠️ Bayar dengan nominal yang SAMA PERSIS ya.',
            'en' => '⚠️ Pay the EXACT amount shown.',
            'ms' => '⚠️ Bayar jumlah yang TEPAT seperti dipaparkan.',
        ],
        'payment.pakasir.order_active_until' => [
            'id' => '⏳ Order aktif sampai: :date',
            'en' => '⏳ Order active until: :date',
            'ms' => '⏳ Pesanan aktif sehingga: :date',
        ],
        'payment.pakasir.scan_hosted' => [
            'id' => '📲 Scan QRIS di atas, atau tap tombol bayar untuk buka halaman pembayaran.',
            'en' => '📲 Scan the QRIS code above, or tap the payment button to open the payment page.',
            'ms' => '📲 Imbas kod QRIS di atas, atau tekan butang bayaran untuk membuka halaman bayaran.',
        ],
        'payment.auto_delivery' => [
            'id' => '✅ Setelah pembayaran berhasil, sistem akan verifikasi otomatis dan produk langsung dikirim ke chat ini.',
            'en' => '✅ After payment succeeds, it will be verified automatically and the product will be sent directly to this chat.',
            'ms' => '✅ Selepas bayaran berjaya, bayaran akan disahkan secara automatik dan produk dihantar terus ke chat ini.',
        ],
        'payment.check_if_missing' => [
            'id' => 'Kalau belum ada pesan otomatis setelah bayar, tap tombol Cek Pembayaran.',
            'en' => 'If no automatic message arrives after payment, tap the Check Payment button.',
            'ms' => 'Jika tiada mesej automatik selepas bayaran, tekan butang Semak Bayaran.',
        ],
        'payment.product_total' => [
            'id' => '💰 Total produk: :amount',
            'en' => '💰 Product total: :amount',
            'ms' => '💰 Jumlah produk: :amount',
        ],
        'payment.qris_fee' => [
            'id' => '🏷️ Biaya QRIS: :fee',
            'en' => '🏷️ QRIS fee: :fee',
            'ms' => '🏷️ Yuran QRIS: :fee',
        ],
        'payment.qris_fee_unavailable' => [
            'id' => 'Belum tersedia',
            'en' => 'Not available yet',
            'ms' => 'Belum tersedia',
        ],
        'payment.qris_total' => [
            'id' => '💳 Total bayar QRIS: :amount',
            'en' => '💳 Total QRIS payment: :amount',
            'ms' => '💳 Jumlah bayaran QRIS: :amount',
        ],
        'payment.qris_fee_customer' => [
            'id' => 'ℹ️ Biaya QRIS dibayar pelanggan dan sudah termasuk dalam total di atas.',
            'en' => 'ℹ️ The QRIS fee is paid by the customer and is included in the total above.',
            'ms' => 'ℹ️ Yuran QRIS dibayar oleh pelanggan dan termasuk dalam jumlah di atas.',
        ],
        'payment.qris_fee_store' => [
            'id' => 'ℹ️ Biaya QRIS ditanggung toko; pelanggan hanya membayar total produk.',
            'en' => 'ℹ️ The QRIS fee is covered by the store; the customer pays only the product total.',
            'ms' => 'ℹ️ Yuran QRIS ditanggung oleh kedai; pelanggan hanya membayar jumlah produk.',
        ],
        'payment.qris_deadline' => [
            'id' => '⏳ Batas bayar QRIS: :date',
            'en' => '⏳ QRIS payment deadline: :date',
            'ms' => '⏳ Tarikh akhir bayaran QRIS: :date',
        ],
        'payment.qris_deadline_guidance' => [
            'id' => 'Lewat batas ini order otomatis expired dan stok dilepas. Jangan bayar QR lama; checkout ulang kalau masih mau beli.',
            'en' => 'After this deadline, the order expires automatically and the stock is released. Do not pay with an old QR code; check out again if you still want to buy.',
            'ms' => 'Selepas tarikh ini, pesanan tamat tempoh secara automatik dan stok dilepaskan. Jangan bayar menggunakan QR lama; buat checkout baharu jika masih mahu membeli.',
        ],
        'payment.pakasir.scan_native' => [
            'id' => '📲 Scan QRIS di atas langsung dari Telegram. Tidak perlu buka browser kalau QR bisa discan.',
            'en' => '📲 Scan the QRIS code above directly from Telegram. You do not need to open a browser if the QR code can be scanned.',
            'ms' => '📲 Imbas kod QRIS di atas terus dari Telegram. Anda tidak perlu membuka pelayar jika QR boleh diimbas.',
        ],
        'payment.pakasir.website_fallback' => [
            'id' => '🌐 Kalau QR tidak bisa discan, tap tombol Bayar via Website sebagai fallback.',
            'en' => '🌐 If the QR code cannot be scanned, tap the Pay via Website button as a fallback.',
            'ms' => '🌐 Jika QR tidak boleh diimbas, tekan butang Bayar melalui Laman Web sebagai pilihan sandaran.',
        ],
        'payment.klikqris.checkout_created' => [
            'id' => '✅ Checkout KlikQRIS berhasil dibuat',
            'en' => '✅ KlikQRIS checkout created',
            'ms' => '✅ Checkout KlikQRIS berjaya dibuat',
        ],
        'payment.klikqris.exact_amount' => [
            'id' => '⚠️ Jangan membulatkan atau mengubah satu digit pun.',
            'en' => '⚠️ Do not round or change any digit.',
            'ms' => '⚠️ Jangan bundarkan atau ubah mana-mana digit.',
        ],
        'payment.klikqris.scan' => [
            'id' => 'Scan QRIS ini langsung dari Telegram. Bayar hanya dari gambar QR di atas.',
            'en' => 'Scan this QRIS directly in Telegram. Pay only from the QR image above.',
            'ms' => 'Imbas QRIS ini terus dari Telegram. Bayar hanya melalui imej QR di atas.',
        ],
        'payment.klikqris.provider_deadline' => [
            'id' => '⏳ Batas pembayaran: :date',
            'en' => '⏳ Payment deadline: :date',
            'ms' => '⏳ Tarikh akhir bayaran: :date',
        ],
        'payment.klikqris.validity' => [
            'id' => 'QRIS ini berlaku 15 menit. Setelah batas waktu, buat QRIS baru dan jangan bayar QR ini.',
            'en' => 'This QRIS is valid for 15 minutes. After the deadline, create a new QRIS instead of paying this one.',
            'ms' => 'QRIS ini sah selama 15 minit. Selepas tarikh akhir, buat QRIS baharu dan jangan bayar QR ini.',
        ],
        'payment.klikqris.retry.expired_title' => [
            'id' => '⏳ QRIS kedaluwarsa',
            'en' => '⏳ QRIS expired',
            'ms' => '⏳ QRIS tamat tempoh',
        ],
        'payment.klikqris.retry.expired_body' => [
            'id' => 'QRIS ini sudah tidak bisa dibayar. Jangan bayar QR ini. Buat pesanan baru kalau masih ingin membeli.',
            'en' => 'This QRIS can no longer be paid. Do not pay it. Create a new order if you still want to buy.',
            'ms' => 'QRIS ini tidak lagi boleh dibayar. Jangan bayar QR ini. Buat pesanan baharu jika masih mahu membeli.',
        ],
        'payment.klikqris.retry.creating_lead' => [
            'id' => '⏳ Sedang menyiapkan QRIS',
            'en' => '⏳ Preparing your QRIS',
            'ms' => '⏳ QRIS anda sedang disediakan',
        ],
        'payment.klikqris.retry.recovery_pending_lead' => [
            'id' => '⏳ Sedang memulihkan QRIS',
            'en' => '⏳ Restoring your QRIS',
            'ms' => '⏳ QRIS anda sedang dipulihkan',
        ],
        'payment.klikqris.retry.total_pending_lead' => [
            'id' => '⏳ Menunggu nominal final',
            'en' => '⏳ Waiting for the final amount',
            'ms' => '⏳ Menunggu jumlah akhir',
        ],
        'payment.klikqris.retry.invalid_lead' => [
            'id' => '⚠️ QRIS tidak bisa disiapkan',
            'en' => '⚠️ QRIS could not be prepared',
            'ms' => '⚠️ QRIS tidak dapat disediakan',
        ],
        'payment.klikqris.retry.creating_body' => [
            'id' => 'QRIS sedang disiapkan. Jangan membuat checkout baru; buka invoice ini lagi sebentar atau tap Cek Pembayaran.',
            'en' => 'Your QRIS is still being prepared. Do not create another checkout; open this invoice again shortly or tap Check Payment.',
            'ms' => 'QRIS anda masih sedang disediakan. Jangan buat checkout baharu; buka invois ini sebentar lagi atau tekan Semak Bayaran.',
        ],
        'payment.klikqris.retry.recovery_pending_body' => [
            'id' => 'QRIS belum siap pada invoice yang sama dan belum ada instruksi bayar baru. Tap Cek Pembayaran sebentar lagi atau buka invoice ini lagi.',
            'en' => 'Your QRIS is not ready on the same invoice yet, and no new payment instruction is available. Tap Check Payment shortly or open this invoice again.',
            'ms' => 'QRIS anda belum sedia pada invois yang sama dan tiada arahan bayaran baharu. Tekan Semak Bayaran sebentar lagi atau buka invois ini semula.',
        ],
        'payment.klikqris.retry.total_pending_body' => [
            'id' => 'Nominal final pembayaran belum tersedia. Jangan bayar total produk. Buka invoice ini lagi sebentar.',
            'en' => 'The final payable amount is not available yet. Do not pay the product subtotal. Open this invoice again shortly.',
            'ms' => 'Jumlah akhir bayaran belum tersedia. Jangan bayar jumlah produk sahaja. Buka invois ini sebentar lagi.',
        ],
        'payment.klikqris.retry.invalid_body' => [
            'id' => 'QRIS ini belum bisa digunakan. Jangan bayar. Hubungi bantuan dengan nomor invoice ini untuk menyelesaikan pesanan.',
            'en' => 'This QRIS cannot be used. Do not pay. Contact support with your invoice number to resolve this order.',
            'ms' => 'QRIS ini tidak boleh digunakan. Jangan bayar. Hubungi sokongan dengan nombor invois anda untuk menyelesaikan pesanan.',
        ],
        'payment.klikqris.retry.ready_lead' => [
            'id' => 'QRIS siap',
            'en' => 'QRIS is ready',
            'ms' => 'QRIS sudah sedia',
        ],
        'payment.klikqris.retry.ready_body' => [
            'id' => 'QRIS kamu siap dibayar.',
            'en' => 'Your QRIS is ready to pay.',
            'ms' => 'QRIS anda sudah sedia untuk dibayar.',
        ],
        'payment.binance.amount_unique' => [
            'id' => "💎 Checkout Binance Pay\n━━━━━━━━━━━━━━\n\n🎫 Pesanan: :invoice\n📦 :items\n\n💵 Nominal yang dikirim (tap untuk salin):\n:amount USDT\n\n⚠️ Penting: Kirim tepat :amount USDT, termasuk seluruh angka desimal. Jangan membulatkan atau mengubah nominal.\n\n📥 :receiver_label (tap untuk salin):\n:receiver\n\n━━━━━━━━━━━━━━\nCara bayar:\n1. Buka Binance → Pay → Kirim, lalu scan QR code di atas atau gunakan :receiver_instruction.\n2. Selesaikan transfer dengan nominal persis seperti yang tertera.\n3. Tunggu pembayaran diperiksa otomatis.\n\nProduk akan dikirim otomatis jika transfer cocok. Jangan kirim screenshot selama order ini masih aktif.",
            'en' => "💎 Binance Pay checkout\n━━━━━━━━━━━━━━\n\n🎫 Order: :invoice\n📦 :items\n\n💵 Amount to send (tap to copy):\n:amount USDT\n\n⚠️ Important: Send exactly :amount USDT, including every decimal digit. Do not round or change the amount.\n\n📥 :receiver_label (tap to copy):\n:receiver\n\n━━━━━━━━━━━━━━\nHow to pay:\n1. Open Binance → Pay → Send, then scan the QR code above or use the :receiver_instruction.\n2. Complete the transfer using the exact amount shown.\n3. Wait while the payment is checked automatically.\n\nYour product will be delivered automatically when the transfer matches. Do not send a screenshot while this order is active.",
            'ms' => "💎 Checkout Binance Pay\n━━━━━━━━━━━━━━\n\n🎫 Pesanan: :invoice\n📦 :items\n\n💵 Jumlah untuk dihantar (tekan untuk salin):\n:amount USDT\n\n⚠️ Penting: Hantar tepat :amount USDT, termasuk semua digit perpuluhan. Jangan bundarkan atau ubah jumlah.\n\n📥 :receiver_label (tekan untuk salin):\n:receiver\n\n━━━━━━━━━━━━━━\nCara bayar:\n1. Buka Binance → Pay → Hantar, kemudian imbas kod QR di atas atau gunakan :receiver_instruction.\n2. Lengkapkan pindahan menggunakan jumlah tepat yang dipaparkan.\n3. Tunggu bayaran disemak secara automatik.\n\nProduk akan dihantar secara automatik apabila pindahan sepadan. Jangan hantar tangkapan skrin selagi pesanan ini masih aktif.",
        ],
        'payment.binance.order_id' => [
            'id' => "💎 Checkout Binance Pay\n━━━━━━━━━━━━━━\n\n🎫 Pesanan: :invoice\n📦 :items\n\n💵 Nominal yang dikirim (tap untuk salin):\n:amount USDT\n\n⚠️ Penting: Kirim tepat :amount USDT, termasuk seluruh angka desimal. Jangan membulatkan atau mengubah nominal.\n\n📥 :receiver_label (tap untuk salin):\n:receiver\n\n━━━━━━━━━━━━━━\nCara bayar:\n1. Buka Binance → Pay → Kirim, lalu scan QR code di atas atau gunakan :receiver_instruction.\n2. Selesaikan transfer dengan nominal persis seperti yang tertera.\n3. Tap tombol Saya Sudah Bayar.\n4. Buka Binance → Pay → pilih transfer kamu → Order Details, lalu salin dan kirim Order ID ke chat ini.\n\nVerifikasi dimulai otomatis setelah Order ID diterima.",
            'en' => "💎 Binance Pay checkout\n━━━━━━━━━━━━━━\n\n🎫 Order: :invoice\n📦 :items\n\n💵 Amount to send (tap to copy):\n:amount USDT\n\n⚠️ Important: Send exactly :amount USDT, including every decimal digit. Do not round or change the amount.\n\n📥 :receiver_label (tap to copy):\n:receiver\n\n━━━━━━━━━━━━━━\nHow to pay:\n1. Open Binance → Pay → Send, then scan the QR code above or use the :receiver_instruction.\n2. Complete the transfer using the exact amount shown.\n3. Tap the I've Paid button.\n4. Copy the Order ID from Binance → Pay → your transfer → Order Details and send it in this chat.\n\nVerification starts automatically after your Order ID is received.",
            'ms' => "💎 Checkout Binance Pay\n━━━━━━━━━━━━━━\n\n🎫 Pesanan: :invoice\n📦 :items\n\n💵 Jumlah untuk dihantar (tekan untuk salin):\n:amount USDT\n\n⚠️ Penting: Hantar tepat :amount USDT, termasuk semua digit perpuluhan. Jangan bundarkan atau ubah jumlah.\n\n📥 :receiver_label (tekan untuk salin):\n:receiver\n\n━━━━━━━━━━━━━━\nCara bayar:\n1. Buka Binance → Pay → Hantar, kemudian imbas kod QR di atas atau gunakan :receiver_instruction.\n2. Lengkapkan pindahan menggunakan jumlah tepat yang dipaparkan.\n3. Tekan butang Saya Sudah Bayar.\n4. Buka Binance → Pay → pilih pindahan anda → Order Details, kemudian salin dan hantar ID Pesanan ke chat ini.\n\nPengesahan bermula secara automatik selepas ID Pesanan diterima.",
        ],
        'payment.binance.manual' => [
            'id' => "💎 Checkout Binance Pay\n━━━━━━━━━━━━━━\n\n🎫 Pesanan: :invoice\n📦 :items\n\n💵 Nominal yang dikirim:\n:amount USDT\n\n⚠️ Penting: Kirim tepat :amount USDT, termasuk seluruh angka desimal. Jangan membulatkan atau mengubah nominal.\n\n📥 :receiver_label:\n:receiver\n\n━━━━━━━━━━━━━━\nCara bayar:\n1. Scan QR code atau masukkan :receiver_instruction di Binance Pay.\n2. Selesaikan transfer dengan nominal persis seperti yang tertera.\n3. Kirim screenshot pembayaran sebagai foto dengan caption /payment.\n\nPembayaran dikonfirmasi setelah screenshot dan nominal diperiksa.",
            'en' => "💎 Binance Pay checkout\n━━━━━━━━━━━━━━\n\n🎫 Order: :invoice\n📦 :items\n\n💵 Amount to send:\n:amount USDT\n\n⚠️ Important: Send exactly :amount USDT, including every decimal digit. Do not round or change the amount.\n\n📥 :receiver_label:\n:receiver\n\n━━━━━━━━━━━━━━\nHow to pay:\n1. Scan the QR code or enter the :receiver_instruction in Binance Pay.\n2. Complete the transfer using the exact amount shown.\n3. Send the payment screenshot as a photo with the caption /payment.\n\nPayment is confirmed after the screenshot and exact amount are reviewed.",
            'ms' => "💎 Checkout Binance Pay\n━━━━━━━━━━━━━━\n\n🎫 Pesanan: :invoice\n📦 :items\n\n💵 Jumlah untuk dihantar:\n:amount USDT\n\n⚠️ Penting: Hantar tepat :amount USDT, termasuk semua digit perpuluhan. Jangan bundarkan atau ubah jumlah.\n\n📥 :receiver_label:\n:receiver\n\n━━━━━━━━━━━━━━\nCara bayar:\n1. Imbas kod QR atau masukkan :receiver_instruction dalam Binance Pay.\n2. Lengkapkan pindahan menggunakan jumlah tepat yang dipaparkan.\n3. Hantar tangkapan skrin pembayaran sebagai foto dengan kapsyen /payment.\n\nBayaran disahkan selepas tangkapan skrin dan jumlah tepat disemak.",
        ],
        'payment.proof.command_prompt' => [
            'id' => 'Kirim foto bukti pembayaran dengan /payment INVOICE di caption.',
            'en' => 'Send a photo with /payment INVOICE in its caption.',
            'ms' => 'Hantar foto bukti bayaran dengan /payment INVOIS dalam kapsyen.',
        ],
        'payment.proof.photo_received' => [
            'id' => '📸 Screenshot bukti sudah diterima. Sekarang kirim /payment untuk meneruskannya ke admin.',
            'en' => '📸 Your payment screenshot has been received. Now send /payment to forward it to the admin.',
            'ms' => '📸 Tangkapan skrin pembayaran telah diterima. Sekarang hantar /payment untuk meneruskannya kepada admin.',
        ],
        'payment.proof.no_pending' => [
            'id' => "Belum ada pesanan manual yang menunggu pembayaran.\n\nCheckout dulu lewat /produk, lalu kirim screenshot bukti transfer sebagai FOTO dengan caption:\n\n/payment",
            'en' => "No manual order is awaiting payment.\n\nCheck out first using /products, then send a payment screenshot as a PHOTO with this caption:\n\n/payment",
            'ms' => "Tiada pesanan manual yang sedang menunggu bayaran.\n\nBuat checkout dahulu melalui /produk, kemudian hantar tangkapan skrin bukti pindahan sebagai FOTO dengan kapsyen:\n\n/payment",
        ],
        'payment.proof.invoice_not_found' => [
            'id' => "Invoice tidak ditemukan atau bukan pesanan manual milik kamu.\n\nCoba cek lagi di /status atau /riwayat.",
            'en' => "The invoice was not found or is not your manual order.\n\nCheck again using /status or /history.",
            'ms' => "Invois tidak ditemui atau bukan pesanan manual anda.\n\nSemak semula melalui /status atau /riwayat.",
        ],
        'payment.proof.photo_required' => [
            'id' => "Bukti pembayaran harus dikirim sebagai FOTO.\n\nPilih salah satu cara:\n1. Upload screenshot dan isi caption dengan /payment\n2. Jika screenshot sudah terkirim, balas foto itu dengan /payment\n\nKalau punya beberapa order, pakai:\n/payment :invoice",
            'en' => "Payment proof must be sent as a PHOTO.\n\nUse either method:\n1. Upload the screenshot with /payment as its caption\n2. If the screenshot was already sent, reply to that photo with /payment\n\nIf you have multiple orders, use:\n/payment :invoice",
            'ms' => "Bukti bayaran mesti dihantar sebagai FOTO.\n\nPilih salah satu cara:\n1. Muat naik tangkapan skrin dengan /payment sebagai kapsyen\n2. Jika tangkapan skrin sudah dihantar, balas foto itu dengan /payment\n\nJika anda mempunyai beberapa pesanan, gunakan:\n/payment :invoice",
        ],
        'payment.proof.admin_unavailable' => [
            'id' => "📸 Bukti pembayaran sudah diterima.\n\nVerifikasi otomatis belum bisa diteruskan sekarang. Silakan hubungi admin langsung untuk konfirmasi manual.",
            'en' => "📸 Payment proof received.\n\nAutomatic verification could not be forwarded right now. Please contact the admin directly for manual confirmation.",
            'ms' => "📸 Bukti bayaran telah diterima.\n\nPengesahan automatik tidak dapat diteruskan sekarang. Sila hubungi admin untuk pengesahan manual.",
        ],
        'payment.proof.duplicate' => [
            'id' => '📸 Bukti pembayaran sudah diterima dan sedang menunggu verifikasi admin, atau order sudah tidak menunggu pembayaran.',
            'en' => '📸 Payment proof has already been received and is awaiting admin verification, or this order is no longer awaiting payment.',
            'ms' => '📸 Bukti bayaran telah diterima dan sedang menunggu pengesahan admin, atau pesanan ini tidak lagi menunggu bayaran.',
        ],
        'payment.proof.binance_order_id_required' => [
            'id' => 'Pembayaran Binance ini memakai verifikasi Order ID. Klik tombol “Saya Sudah Bayar” pada instruksi checkout lalu kirim Binance Order ID. Screenshot hanya dipakai sebagai fallback setelah order expired.',
            'en' => "This Binance payment requires Order ID verification. Tap “I've Paid” in the checkout instructions and send the Binance Order ID. A screenshot is only used as a fallback after the order expires.",
            'ms' => 'Bayaran Binance ini memerlukan pengesahan ID Pesanan. Tekan “Saya Sudah Bayar” dalam arahan checkout kemudian hantar ID Pesanan Binance. Tangkapan skrin hanya digunakan sebagai sandaran selepas pesanan tamat tempoh.',
        ],
        'payment.proof.binance_amount_unique' => [
            'id' => 'Pembayaran Binance ini memakai nominal unik dan sedang diverifikasi otomatis. Pastikan nominal yang dikirim sama persis dengan instruksi checkout. Screenshot tidak diperlukan selama order masih aktif; sistem akan mengirim produk jika transfer cocok.',
            'en' => 'This Binance payment uses a unique amount and is being verified automatically. Make sure the amount you send exactly matches the checkout instructions. A screenshot is not needed while the order is active; the product will be sent when the transfer matches.',
            'ms' => 'Bayaran Binance ini menggunakan jumlah unik dan sedang disahkan secara automatik. Pastikan jumlah yang dihantar sama tepat dengan arahan checkout. Tangkapan skrin tidak diperlukan selagi pesanan aktif; produk akan dihantar jika pindahan sepadan.',
        ],
        'payment.proof.deadline_passed' => [
            'id' => 'Batas pembayaran sudah lewat. Tunggu order diproses sebagai expired, lalu kirim screenshot dengan caption /payment :invoice untuk review admin.',
            'en' => 'The payment deadline has passed. Wait for the order to expire, then send a screenshot with the caption /payment :invoice for admin review.',
            'ms' => 'Tarikh akhir bayaran telah berlalu. Tunggu pesanan tamat tempoh, kemudian hantar tangkapan skrin dengan kapsyen /payment :invoice untuk semakan admin.',
        ],
        'payment.proof.late_forwarded' => [
            'id' => "📸 Bukti pembayaran terlambat sudah diterima\nOrder sudah expired dan stok tidak dikunci ulang.\nTunggu pengecekan manual; kamu tidak perlu kirim ulang.",
            'en' => "📸 Late payment proof received\nThe order has expired and stock was not reserved again.\nWait for manual review; you do not need to send it again.",
            'ms' => "📸 Bukti bayaran lewat telah diterima\nPesanan telah tamat tempoh dan stok tidak dikunci semula.\nTunggu semakan manual; anda tidak perlu menghantarnya semula.",
        ],
        'payment.proof.late_forwarding' => [
            'id' => "📸 Bukti pembayaran terlambat sudah masuk\nOrder sudah expired dan stok tidak dikunci ulang.\nBukti akan diproses untuk pengecekan manual; kamu tidak perlu kirim ulang.",
            'en' => "📸 Late payment proof received\nThe order has expired and stock was not reserved again.\nThe proof will be processed for manual review; you do not need to send it again.",
            'ms' => "📸 Bukti bayaran lewat telah diterima\nPesanan telah tamat tempoh dan stok tidak dikunci semula.\nBukti akan diproses untuk semakan manual; anda tidak perlu menghantarnya semula.",
        ],
        'payment.proof.forwarded' => [
            'id' => "📸 Bukti pembayaran sudah masuk\nBukti sudah diteruskan untuk dicek.\n\nSekarang tinggal tunggu ya. Kalau pembayaran sesuai, produk akan dikirim otomatis.",
            'en' => "📸 Payment proof received\nIt was sent for review.\n\nPlease wait while it is checked. If the payment matches, the product will be sent automatically.",
            'ms' => "📸 Bukti bayaran telah diterima\nBukti telah dihantar untuk semakan.\n\nSila tunggu semakan. Jika bayaran sepadan, produk akan dihantar secara automatik.",
        ],
        'payment.proof.forwarding' => [
            'id' => "📸 Bukti pembayaran sudah masuk\nBukti sedang diteruskan untuk diverifikasi. Kamu tidak perlu kirim ulang.\n\nKalau pembayaran sesuai, produk akan dikirim otomatis.",
            'en' => "📸 Payment proof received\nIt is being sent for review. You do not need to send it again.\n\nIf the payment matches, the product will be sent automatically.",
            'ms' => "📸 Bukti bayaran telah diterima\nBukti sedang dihantar untuk pengesahan. Anda tidak perlu menghantarnya semula.\n\nJika bayaran sepadan, produk akan dihantar secara automatik.",
        ],
        'payment.manual.rejected' => [
            'id' => "❌ Pembayaran belum bisa diterima\n━━━━━━━━━━━━━━\n🧾 Invoice: :invoice\n📌 Status: Ditolak admin\n\nKemungkinan penyebab:\n• Nominal pembayaran belum sama persis\n• Nama QR tujuan bukan LHIE STORE\n• Bukti pembayaran kurang jelas\n• Pembayaran belum masuk saat dicek\n\nPesanan ini sudah dibatalkan, jadi stok tidak tertahan.\n\n:contact",
            'en' => "❌ Payment could not be accepted\n━━━━━━━━━━━━━━\n🧾 Invoice: :invoice\n📌 Status: Rejected by admin\n\nPossible reasons:\n• The payment amount did not match exactly\n• The destination QR name was not LHIE STORE\n• The payment proof was unclear\n• The payment had not arrived when it was checked\n\nThis order has been cancelled, so the stock is no longer reserved.\n\n:contact",
            'ms' => "❌ Bayaran tidak dapat diterima\n━━━━━━━━━━━━━━\n🧾 Invois: :invoice\n📌 Status: Ditolak oleh admin\n\nKemungkinan sebab:\n• Jumlah bayaran tidak sama tepat\n• Nama QR penerima bukan LHIE STORE\n• Bukti bayaran tidak jelas\n• Bayaran belum diterima semasa disemak\n\nPesanan ini telah dibatalkan, jadi stok tidak lagi dipegang.\n\n:contact",
        ],
        'payment.manual.rejected_contact' => [
            'id' => 'Kalau kamu merasa sudah transfer dengan benar, kirim ulang bukti yang lebih jelas atau hubungi :contact agar bisa dicek manual.',
            'en' => 'If you believe the transfer was correct, resend a clearer payment proof or contact :contact for a manual check.',
            'ms' => 'Jika anda percaya pindahan sudah betul, hantar semula bukti yang lebih jelas atau hubungi :contact untuk semakan manual.',
        ],
        'payment.error.order_not_found' => [
            'id' => 'Pesanan tidak ditemukan.',
            'en' => 'Order not found.',
            'ms' => 'Pesanan tidak ditemui.',
        ],
        'payment.binance.check_requested' => [
            'id' => 'Permintaan cek diterima. Pembayaran sedang diperiksa.',
            'en' => 'Check requested. Your payment is being verified.',
            'ms' => 'Permintaan semakan diterima. Bayaran anda sedang disahkan.',
        ],
        'payment.binance.checking' => [
            'id' => "🔎 Sedang mengecek pembayaran\n━━━━━━━━━━━━━━\n🧾 Invoice: :invoice\n\nSistem sedang memeriksa nominal persis dan penerima Binance. Kamu akan diberi kabar jika pembayaran cocok.",
            'en' => "🔎 Checking your payment\n━━━━━━━━━━━━━━\n🧾 Invoice: :invoice\n\nWe are checking the exact amount and Binance receiver. You will be notified if the payment matches.",
            'ms' => "🔎 Sedang menyemak bayaran\n━━━━━━━━━━━━━━\n🧾 Invois: :invoice\n\nSistem sedang menyemak jumlah tepat dan penerima Binance. Anda akan dimaklumkan jika bayaran sepadan.",
        ],
        'payment.binance.max_order_ids' => [
            'id' => 'Batas pengiriman Order ID sudah tercapai (maksimal 3 ID). Hubungi admin dengan invoice :invoice.',
            'en' => 'You have reached the maximum of 3 Order IDs. Please contact the admin with invoice :invoice.',
            'ms' => 'Had penghantaran ID Pesanan telah dicapai (maksimum 3 ID). Hubungi admin dengan invois :invoice.',
        ],
        'payment.binance.order_id_prompt' => [
            'id' => "🧾 Kirim Binance Order ID\n━━━━━━━━━━━━━━\nBalas chat ini dengan Order ID dari bukti transfer kamu (10–128 karakter ASCII, tanpa spasi). ID ini wajib supaya pembayaran bisa diverifikasi otomatis.\n\nCara menemukannya:\nBuka aplikasi Binance → Pay → pilih transfer kamu → Order Details → salin Order ID.",
            'en' => 'Please send the Binance Pay Order ID for this payment. Open the completed payment in Binance Pay, view Order Details, and copy the Order ID exactly. Do not send your Binance Pay ID or a screenshot here.',
            'ms' => 'Sila hantar ID Pesanan Binance Pay untuk bayaran ini. Buka bayaran yang telah selesai dalam Binance Pay, lihat Order Details dan salin ID Pesanan tepat seperti yang dipaparkan. Jangan hantar Binance Pay ID atau tangkapan skrin di sini.',
        ],
        'payment.binance.order_id_invalid' => [
            'id' => 'Kirim Binance Order ID persis seperti di Order Details (10–128 karakter ASCII, tanpa spasi). Coba kirim ulang, atau ketik /batal untuk berhenti.',
            'en' => 'Please send the Binance Order ID exactly as shown in Order Details (10–128 ASCII characters, no spaces). Try again, or type /batal to stop.',
            'ms' => 'Hantar ID Pesanan Binance tepat seperti dalam Order Details (10–128 aksara ASCII, tanpa ruang). Cuba lagi, atau taip /batal untuk berhenti.',
        ],
        'payment.binance.too_many_attempts' => [
            'id' => 'Terlalu banyak percobaan. Tunggu beberapa menit lalu coba lagi.',
            'en' => 'Too many attempts. Please wait a few minutes and try again.',
            'ms' => 'Terlalu banyak percubaan. Tunggu beberapa minit kemudian cuba lagi.',
        ],
        'payment.binance.deadline_late' => [
            'id' => '⏳ Batas pembayaran sudah lewat. Order :invoice akan ditangani sebagai pembayaran terlambat. Kirim screenshot dengan caption /payment :invoice agar admin bisa mengeceknya.',
            'en' => '⏳ The payment deadline has passed. Order :invoice will be handled as a late payment. Send a screenshot with the caption /payment :invoice so the admin can review it.',
            'ms' => '⏳ Tarikh akhir bayaran telah berlalu. Pesanan :invoice akan dikendalikan sebagai bayaran lewat. Hantar tangkapan skrin dengan kapsyen /payment :invoice supaya admin boleh menyemaknya.',
        ],
        'payment.expired.binance_title' => [
            'id' => '⏳ Verifikasi Binance belum selesai',
            'en' => '⏳ Binance verification did not finish',
            'ms' => '⏳ Pengesahan Binance belum selesai',
        ],
        'payment.expired.binance_order_id' => [
            'id' => '💵 Order ID sudah diterima, tetapi transaksi belum bisa dikonfirmasi otomatis.',
            'en' => '💵 Your Order ID was received, but the transaction could not be confirmed automatically.',
            'ms' => '💵 ID Pesanan telah diterima, tetapi transaksi tidak dapat disahkan secara automatik.',
        ],
        'payment.expired.binance_prompt' => [
            'id' => 'Kalau kamu sudah membayar, kirim screenshot bukti transfer sebagai foto dengan caption:',
            'en' => 'If you already paid, send your transfer screenshot as a photo with this caption:',
            'ms' => 'Jika anda sudah membayar, hantar tangkapan skrin bukti pindahan sebagai foto dengan kapsyen:',
        ],
        'payment.expired.binance_review' => [
            'id' => 'Admin akan mengecek pembayaran terlambat secara manual. Stok tidak dikunci ulang otomatis.',
            'en' => 'The admin will review the late payment manually. Stock is not reserved again automatically.',
            'ms' => 'Admin akan menyemak bayaran lewat secara manual. Stok tidak dipegang semula secara automatik.',
        ],
        'payment.expired.title' => [
            'id' => '⏳ Order kedaluwarsa',
            'en' => '⏳ Order expired',
            'ms' => '⏳ Pesanan tamat tempoh',
        ],
        'payment.expired.cancelled' => [
            'id' => 'Pembayaran tidak selesai sampai batas waktu, jadi order dibatalkan otomatis.',
            'en' => 'Payment was not completed before the deadline, so this order was cancelled automatically.',
            'ms' => 'Bayaran tidak selesai sebelum tarikh akhir, jadi pesanan ini dibatalkan secara automatik.',
        ],
        'payment.expired.reorder' => [
            'id' => 'Tenang, kamu masih bisa order ulang kapan aja.',
            'en' => 'You can place a new order anytime.',
            'ms' => 'Jangan risau, anda boleh membuat pesanan baharu pada bila-bila masa.',
        ],
        'payment.action.new_qris' => [
            'id' => '🔄 Buat QRIS Baru',
            'en' => '🔄 Create New QRIS',
            'ms' => '🔄 Buat QRIS Baharu',
        ],
        'payment.action.reorder' => [
            'id' => '🛍️ Order Ulang',
            'en' => '🛍️ Order Again',
            'ms' => '🛍️ Buat Pesanan Lagi',
        ],
        'payment.binance.grace_expired' => [
            'id' => 'Verifikasi otomatis untuk order :invoice sudah berakhir. Jika pembayaran sudah dikirim, kirim screenshot dengan caption /payment :invoice.',
            'en' => 'Automatic verification for order :invoice has ended. If you already paid, send a screenshot with the caption /payment :invoice.',
            'ms' => 'Pengesahan automatik untuk pesanan :invoice telah berakhir. Jika anda sudah membayar, hantar tangkapan skrin dengan kapsyen /payment :invoice.',
        ],
        'payment.binance.manual_fallback' => [
            'id' => 'Verifikasi otomatis tidak tersedia untuk order :invoice. Kirim screenshot pembayaran sebagai foto dengan caption /payment :invoice.',
            'en' => 'Automatic verification is not available for order :invoice. Send a payment screenshot as a photo with the caption /payment :invoice.',
            'ms' => 'Pengesahan automatik tidak tersedia untuk pesanan :invoice. Hantar tangkapan skrin bayaran sebagai foto dengan kapsyen /payment :invoice.',
        ],
        'payment.binance.confirmed' => [
            'id' => "✅ Pembayaran terverifikasi!\n━━━━━━━━━━━━━━\n🧾 Invoice: :invoice\n💵 :amount USDT diterima.\n\nProduk kamu sedang dikirim ke chat ini sekarang. Terima kasih! 🎉",
            'en' => "✅ Payment confirmed!\n━━━━━━━━━━━━━━\n🧾 Invoice: :invoice\n💵 :amount USDT received.\n\nYour product is being delivered to this chat right now. Thank you! 🎉",
            'ms' => "✅ Bayaran disahkan!\n━━━━━━━━━━━━━━\n🧾 Invois: :invoice\n💵 :amount USDT diterima.\n\nProduk anda sedang dihantar ke chat ini sekarang. Terima kasih! 🎉",
        ],
        'payment.binance.already_paid' => [
            'id' => 'Pesanan :invoice sudah lunas. Cek /status kalau produk belum sampai.',
            'en' => 'Order :invoice is already paid. Check /status if the product has not arrived yet.',
            'ms' => 'Pesanan :invoice sudah dibayar. Semak /status jika produk belum sampai.',
        ],
        'payment.binance.not_pending' => [
            'id' => 'Pesanan :invoice sudah tidak menunggu pembayaran. Kalau kamu terlanjur transfer, hubungi admin dengan invoice ini.',
            'en' => 'Order :invoice is no longer awaiting payment. If you already sent a transfer, please contact the admin with this invoice.',
            'ms' => 'Pesanan :invoice tidak lagi menunggu bayaran. Jika anda sudah membuat pindahan, hubungi admin dengan invois ini.',
        ],
        'payment.binance.amount_unique_unavailable' => [
            'id' => 'Verifikasi otomatis tidak tersedia untuk order :invoice. Kirim screenshot pembayaran sebagai foto dengan caption /payment :invoice agar admin bisa mengeceknya.',
            'en' => 'Automatic verification is not available for order :invoice. Send a payment screenshot as a photo with the caption /payment :invoice so the admin can review it.',
            'ms' => 'Pengesahan automatik tidak tersedia untuk pesanan :invoice. Hantar tangkapan skrin bayaran sebagai foto dengan kapsyen /payment :invoice supaya admin boleh menyemaknya.',
        ],
        'payment.binance.order_id_unavailable' => [
            'id' => 'Verifikasi Order ID belum tersedia untuk order :invoice. Kirim screenshot pembayaran sebagai foto dengan caption /payment :invoice agar admin bisa mengeceknya.',
            'en' => 'Order ID verification is not available for order :invoice. Send a payment screenshot as a photo with the caption /payment :invoice so the admin can review it.',
            'ms' => 'Pengesahan ID Pesanan belum tersedia untuk pesanan :invoice. Hantar tangkapan skrin bayaran sebagai foto dengan kapsyen /payment :invoice supaya admin boleh menyemaknya.',
        ],
        'payment.binance.verification_ended' => [
            'id' => 'Verifikasi otomatis untuk order :invoice sudah berakhir. Jika pembayaran sudah dikirim, kirim screenshot dengan caption /payment :invoice.',
            'en' => 'Automatic verification for order :invoice has ended. If you already paid, send a screenshot with the caption /payment :invoice.',
            'ms' => 'Pengesahan automatik untuk pesanan :invoice telah berakhir. Jika anda sudah membayar, hantar tangkapan skrin dengan kapsyen /payment :invoice.',
        ],
        'payment.binance.not_found_amount' => [
            'id' => "🔎 Pembayaran belum ditemukan\n━━━━━━━━━━━━━━\n🧾 Invoice: :invoice\n\nTransfer dengan nominal persis :amount USDT belum terlihat di riwayat Binance. Setelah transfer selesai, tekan tombol Saya Sudah Bayar untuk mengecek lagi.",
            'en' => "🔎 Payment not found yet\n━━━━━━━━━━━━━━\n🧾 Invoice: :invoice\n\nA transfer for the exact amount of :amount USDT is not visible in Binance history yet. After completing the transfer, tap I've Paid to check again.",
            'ms' => "🔎 Bayaran belum ditemui\n━━━━━━━━━━━━━━\n🧾 Invois: :invoice\n\nPindahan dengan jumlah tepat :amount USDT belum kelihatan dalam sejarah Binance. Selepas pindahan selesai, tekan Saya Sudah Bayar untuk menyemak semula.",
        ],
        'payment.binance.not_found_order_id_final' => [
            'id' => '⏳ Order ID sudah diterima, tetapi pembayaran belum terlihat. Kirim screenshot pembayaran dengan caption /payment :invoice agar bisa dicek manual.',
            'en' => '⏳ Order ID received, but the payment is not visible yet. Send a payment screenshot with the caption /payment :invoice for a manual review.',
            'ms' => '⏳ ID Pesanan telah diterima, tetapi bayaran belum kelihatan. Hantar tangkapan skrin bayaran dengan kapsyen /payment :invoice untuk semakan manual.',
        ],
        'payment.binance.not_found_order_id' => [
            'id' => "🔎 Order ID sudah diterima\n━━━━━━━━━━━━━━\n🧾 Invoice: :invoice\n\nPembayaran belum terlihat. Tunggu hasil verifikasi; kamu tidak perlu mengirim ulang ID yang sama.",
            'en' => "🔎 Order ID received\n━━━━━━━━━━━━━━\n🧾 Invoice: :invoice\n\nThe payment is not visible yet. Please wait for the verification result; you do not need to resend the same ID.",
            'ms' => "🔎 ID Pesanan telah diterima\n━━━━━━━━━━━━━━\n🧾 Invois: :invoice\n\nBayaran belum kelihatan. Tunggu hasil pengesahan; anda tidak perlu menghantar semula ID yang sama.",
        ],
        'payment.binance.manual_review' => [
            'id' => "⚠️ Pembayaran kamu butuh pengecekan manual\n━━━━━━━━━━━━━━\n🧾 Invoice: :invoice\n\nDetail pembayaran belum sepenuhnya cocok dengan pesanan ini, jadi konfirmasi membutuhkan waktu lebih lama. Kamu tidak perlu melakukan apa-apa lagi.",
            'en' => "⚠️ Your payment needs a manual check\n━━━━━━━━━━━━━━\n🧾 Invoice: :invoice\n\nThe payment details do not fully match this order, so confirmation will take longer. No further action is needed from you.",
            'ms' => "⚠️ Bayaran anda memerlukan semakan manual\n━━━━━━━━━━━━━━\n🧾 Invois: :invoice\n\nButiran bayaran tidak sepadan sepenuhnya dengan pesanan ini, jadi pengesahan mengambil masa lebih lama. Anda tidak perlu melakukan apa-apa lagi.",
        ],
        'payment.binance.not_configured' => [
            'id' => 'Verifikasi otomatis sedang tidak tersedia. Kirim screenshot pembayaran dengan caption /payment sebagai cadangan.',
            'en' => 'Automatic verification is currently unavailable. As a backup, please send your payment screenshot with the caption /payment.',
            'ms' => 'Pengesahan automatik tidak tersedia buat masa ini. Sebagai sandaran, hantar tangkapan skrin bayaran dengan kapsyen /payment.',
        ],
        'payment.binance.incomplete_amount' => [
            'id' => 'Riwayat Binance belum selesai diperiksa. Tekan Saya Sudah Bayar lagi setelah beberapa saat.',
            'en' => "Binance history could not be checked completely yet. Tap I've Paid again in a moment.",
            'ms' => 'Sejarah Binance belum selesai disemak. Tekan Saya Sudah Bayar sekali lagi sebentar lagi.',
        ],
        'payment.binance.incomplete_order_id' => [
            'id' => 'Order ID kamu sudah diterima. Sistem sedang memeriksa pembayaran dengan aman dan akan memberi kabar setelah hasilnya tersedia.',
            'en' => 'Your Order ID was received. We are checking your payment securely and will update you when the result is available.',
            'ms' => 'ID Pesanan anda telah diterima. Kami sedang menyemak bayaran dengan selamat dan akan memaklumkan anda apabila hasil tersedia.',
        ],
        'payment.binance.retry_amount' => [
            'id' => 'Verifikasi belum selesai. Tekan Saya Sudah Bayar lagi setelah beberapa saat.',
            'en' => "The payment check could not finish. Tap I've Paid again in a moment.",
            'ms' => 'Pengesahan belum selesai. Tekan Saya Sudah Bayar sekali lagi sebentar lagi.',
        ],
        'payment.binance.retry_order_id' => [
            'id' => 'Order ID kamu sudah diterima. Sistem sedang memeriksa pembayaran dengan aman dan akan memberi kabar setelah hasilnya tersedia.',
            'en' => 'Your Order ID was received. We are checking your payment securely and will update you when the result is available.',
            'ms' => 'ID Pesanan anda telah diterima. Kami sedang menyemak bayaran dengan selamat dan akan memaklumkan anda apabila hasil tersedia.',
        ],
        'payment.binance.retry_final' => [
            'id' => 'Verifikasi otomatis belum berhasil diselesaikan. Kirim screenshot pembayaran dengan caption /payment :invoice sebagai cadangan.',
            'en' => 'Automatic verification could not be completed. Send a payment screenshot with the caption /payment :invoice as a backup.',
            'ms' => 'Pengesahan automatik tidak dapat diselesaikan. Hantar tangkapan skrin bayaran dengan kapsyen /payment :invoice sebagai sandaran.',
        ],
        'payment.binance.provider_error' => [
            'id' => 'Verifikasi Binance tidak dapat dilanjutkan saat ini. Kirim screenshot pembayaran dengan caption /payment sebagai cadangan.',
            'en' => 'Automatic Binance verification could not continue. Please send your payment screenshot with the caption /payment as a backup.',
            'ms' => 'Pengesahan Binance automatik tidak dapat diteruskan sekarang. Hantar tangkapan skrin bayaran dengan kapsyen /payment sebagai sandaran.',
        ],
        'payment.binance.receiver_pay_id' => [
            'id' => 'Pay ID',
            'en' => 'Pay ID',
            'ms' => 'ID Pay',
        ],
        'payment.binance.receiver_identifier' => [
            'id' => 'receiver identifier',
            'en' => 'receiver identifier',
            'ms' => 'pengenal penerima',
        ],
        'payment.pakasir.unavailable_fallback' => [
            'id' => "Pembayaran otomatis sedang tidak tersedia, jadi checkout ini dialihkan ke QR manual.\n\nIkuti instruksi QR di bawah untuk selesaikan pembayaran kamu.",
            'en' => "Automatic payment is currently unavailable, so this checkout has been redirected to a manual QR payment.\n\nFollow the QR instructions below to complete your payment.",
            'ms' => "Bayaran automatik tidak tersedia sekarang, jadi checkout ini dialihkan ke QR manual.\n\nIkut arahan QR di bawah untuk melengkapkan bayaran.",
        ],
    ];

    public function get(string $key, string $locale): string
    {
        $translations = self::TRANSLATIONS[$key] ?? self::TRANSLATIONS['translation.missing'];

        return $translations[$locale] ?? $translations['en'];
    }

    /**
     * @param  array<string, scalar|null>  $replace
     */
    public function translate(string $key, string $locale, array $replace = []): string
    {
        $translation = $this->get($key, $locale);

        $replacements = [];
        foreach ($replace as $name => $value) {
            $replacements[':'.$name] = (string) $value;
        }

        return strtr($translation, $replacements);
    }
}
