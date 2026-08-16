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
    ];

    public function get(string $key, string $locale): string
    {
        $translations = self::TRANSLATIONS[$key] ?? self::TRANSLATIONS['translation.missing'];

        return $translations[$locale] ?? $translations['en'];
    }
}
