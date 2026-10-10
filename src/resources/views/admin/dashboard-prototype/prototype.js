/* THROWAWAY — all interactions use fictional in-memory data. No network or storage. */
const variants = {
  A: "Classic — sidebar & overview",
  B: "Workspace — tabel & navigasi ringkas",
  C: "Command — prioritas tindakan",
};
const orders = [
  [
    "INV-1028",
    "Raka Pratama",
    "Netflix Premium · 1 bulan",
    "Rp 45.000",
    "Perlu ditangani",
    "warning",
  ],
  [
    "INV-1027",
    "Nadia Putri",
    "Canva Pro · 1 tahun",
    "Rp 30.000",
    "Selesai",
    "success",
  ],
  [
    "INV-1026",
    "Dimas Saputra",
    "Spotify Premium · 1 bulan",
    "Rp 25.000",
    "Menunggu bayar",
    "neutral",
  ],
  [
    "INV-1025",
    "Salsa Amalia",
    "Netflix Premium · 1 bulan",
    "Rp 45.000",
    "Diproses",
    "info",
  ],
  [
    "INV-1024",
    "Fajar Ramadhan",
    "Canva Pro · 1 tahun",
    "Rp 30.000",
    "Selesai",
    "success",
  ],
  [
    "INV-1023",
    "Alya Kirana",
    "YouTube Premium · 1 bulan",
    "Rp 20.000",
    "Selesai",
    "success",
  ],
];
let variant = variants[new URLSearchParams(location.search).get("variant")]
  ? new URLSearchParams(location.search).get("variant")
  : "A";
let section = "Ringkasan";
let query = "";
let filter = "Semua";
const groups = [
  ["UTAMA", ["Ringkasan"]],
  ["OPERASIONAL", ["Pesanan", "Produk", "Supplier"]],
  ["KEUANGAN", ["Saldo & laporan", "Kompensasi"]],
  ["KOMUNIKASI", ["Siaran", "Store Channel"]],
  ["INSIGHT & SISTEM", ["Funnel", "Pengaturan"]],
];
const icons = {
  Ringkasan: "◫",
  Pesanan: "▤",
  Produk: "◇",
  Supplier: "⇄",
  "Saldo & laporan": "◉",
  Kompensasi: "!",
  Siaran: "◁",
  "Store Channel": "⌁",
  Funnel: "▽",
  Pengaturan: "⚙",
};
const badge = (label, tone) => `<span class="badge ${tone}">${label}</span>`;
const button = (text, action, cls = "") =>
  `<button class="button ${cls}" data-action="${action}">${text}</button>`;
function nav() {
  return groups
    .map(
      ([label, items]) =>
        `<div class="nav-group"><small>${label}</small>${items.map((name) => `<button class="nav-item ${section === name ? "active" : ""}" data-section="${name}" ${section === name ? 'aria-current="page"' : ""}><span class="nav-icon">${icons[name]}</span><span>${name}</span></button>`).join("")}</div>`
    )
    .join("");
}
function sidebar() {
  return `<aside class="sidebar"><a class="brand" href="?variant=${variant}"><span class="brand-mark">t.</span><span>toko<span class="brand-sub">CONTROL PANEL</span></span></a><nav aria-label="Navigasi dashboard">${nav()}</nav><div class="profile"><span class="avatar">AF</span><div><strong>Admin Fikri</strong><small>Owner · data contoh</small></div></div></aside>`;
}
function header() {
  return `<header class="topbar"><div class="heading"><button class="menu-button" aria-label="Buka navigasi" aria-expanded="false">☰</button><div><small>WORKSPACE / ${section.toUpperCase()}</small><h1>${section}</h1></div></div><div class="header-actions">${badge("● Toko aktif", "success")}${button("↗ Buka toko", "shop")}<span class="avatar">AF</span></div></header>`;
}
function intro(title, text, actions = "") {
  return `<div class="intro"><div><small>JUMAT, 9 OKTOBER · DATA SIMULASI</small><h2>${title}</h2><p>${text}</p></div><div class="intro-actions">${actions}</div></div>`;
}
function metrics() {
  return `<div class="metrics">${[
    ["Pendapatan hari ini", "Rp 1.245.000", "+18,4% dari kemarin", "↗"],
    ["Pesanan masuk", "38", "31 selesai · 7 belum selesai", "▤"],
    ["Perlu ditangani", "3", "2 fulfillment · 1 top-up", "!"],
    ["Produk tersedia", "24 / 28", "4 produk kehabisan stok", "◇"],
  ]
    .map(
      ([label, value, hint, icon]) =>
        `<article class="metric"><div><span>${label}</span><b>${icon}</b></div><strong>${value}</strong><small>${hint}</small></article>`
    )
    .join("")}</div>`;
}
function chart() {
  return `<section class="panel chart"><div class="panel-heading"><div><small>PEMBAYARAN LUNAS</small><h3>Pendapatan 7 hari</h3></div>${badge("+12,8%", "success")}</div><div class="chart-summary">Rp 7.830.000 <small>Total selama periode</small></div><div class="bars" aria-label="Pendapatan contoh Senin sampai Minggu">${[42, 64, 51, 83, 70, 95, 78].map((height, i) => `<div><span style="height:${height}%" title="${["Sen", "Sel", "Rab", "Kam", "Jum", "Sab", "Min"][i]}: pendapatan contoh"></span><small>${["Sen", "Sel", "Rab", "Kam", "Jum", "Sab", "Min"][i]}</small></div>`).join("")}</div></section>`;
}
function attention() {
  return `<section class="panel attention"><div class="panel-heading"><h3>Butuh perhatian</h3>${badge("3 masalah", "warning")}</div>${[
    [
      "2",
      "Pesanan belum terkirim",
      "Pembayaran diterima · menunggu fulfillment",
      "Pesanan",
    ],
    [
      "1",
      "Top-up perlu ditinjau",
      "Periksa bukti sebelum melakukan kompensasi",
      "Kompensasi",
    ],
    [
      "4",
      "Produk kehabisan stok",
      "Netflix, Spotify dan 2 produk lainnya",
      "Produk",
    ],
  ]
    .map(
      ([n, title, hint, target]) =>
        `<button class="attention-row" data-section="${target}"><span class="count">${n}</span><span><strong>${title}</strong><small>${hint}</small></span><b>↗</b></button>`
    )
    .join("")}</section>`;
}
function orderTable(compact = false) {
  return `<section class="panel orders"><div class="panel-heading"><div><small>AKTIVITAS TERBARU</small><h3>${compact ? "Antrean pesanan" : "Pesanan terbaru"}</h3></div>${button("Lihat semua →", "orders", "subtle")}</div><div class="table-tools"><input type="search" placeholder="Cari invoice, pelanggan, produk…" aria-label="Cari pesanan" value=""><select aria-label="Filter status"><option>Semua</option><option>Perlu ditangani</option><option>Menunggu bayar</option><option>Diproses</option><option>Selesai</option></select><span class="results"></span></div><div class="table-scroll"><table><thead><tr><th>Invoice / pelanggan</th><th>Produk</th><th>Nominal</th><th>Status</th><th></th></tr></thead><tbody></tbody></table></div><div class="table-footer">Contoh 6 transaksi · bukan data toko asli <span>Semua nominal dalam IDR</span></div></section>`;
}
function renderRows() {
  const visible = orders.filter(
    (row) =>
      row.slice(0, 5).join(" ").toLowerCase().includes(query.toLowerCase()) &&
      (filter === "Semua" || row[4] === filter)
  );
  document.querySelectorAll("tbody").forEach((body) => {
    body.innerHTML =
      visible
        .map(
          ([id, name, product, amount, status, tone]) =>
            `<tr><td><strong>${id}</strong><small>${name}</small></td><td>${product}</td><td class="amount">${amount}</td><td>${badge(status, tone)}</td><td><button class="row-detail" data-order="${id}" aria-label="Detail ${id}">↗</button></td></tr>`
        )
        .join("") ||
      '<tr><td colspan="5" class="empty">Tidak ada pesanan contoh yang cocok.</td></tr>';
  });
  document
    .querySelectorAll(".results")
    .forEach((el) => (el.textContent = `${visible.length} pesanan`));
}
function sectionView() {
  if (section === "Pesanan")
    return `${intro("Kelola pesanan", "Pembayaran, pengiriman, dan tindak lanjut dalam satu antrean.", badge("2 perlu fulfillment", "warning"))}${orderTable(true)}`;
  if (section === "Produk")
    return `${intro("Katalog & stok", "Preview susunan katalog. Tidak ada perubahan stok sungguhan.", button("+ Tambah produk", "add", "primary"))}<div class="product-grid">${[
      ["Netflix Premium", "1 bulan", "0", "Rp 45.000"],
      ["Canva Pro", "1 tahun", "18", "Rp 30.000"],
      ["Spotify Premium", "1 bulan", "6", "Rp 25.000"],
      ["YouTube Premium", "1 bulan", "12", "Rp 20.000"],
    ]
      .map(
        ([name, plan, stock, price], i) =>
          `<article class="panel product"><span class="product-symbol">${["N", "C", "S", "Y"][i]}</span>${badge(stock === "0" ? "Stok habis" : "Aktif", stock === "0" ? "warning" : "success")}<h3>${name}</h3><p>${plan}</p><div class="product-data"><strong>${price}</strong><span>${stock} tersedia</span></div>${button("Kelola stok →", "stock")}</article>`
      )
      .join("")}</div>`;
  return `${intro(section, "Preview halaman pendukung untuk membandingkan navigasi dan kepadatan layout.")}<section class="panel sample-section"><small>CONTOH INFORMASI · BUKAN KONFIGURASI ASLI</small><h3>${section === "Supplier" ? "Kesehatan koneksi supplier" : section === "Kompensasi" ? "Top-up yang perlu ditinjau" : "Ringkasan " + section}</h3><p>${section === "Kompensasi" ? "TOP-2041 · Rp 100.000 · bukti pembayaran perlu pemeriksaan manual." : "Halaman ini memakai data fiktif. Tidak ada API, akun, atau sistem produksi yang terhubung."}</p><dl><div><dt>Status simulasi</dt><dd>${badge("Perlu ditinjau", "warning")}</dd></div><div><dt>Terakhir diperbarui</dt><dd>09 Oktober 2026 · 10.42</dd></div><div><dt>Akses</dt><dd>Owner · preview saja</dd></div></dl>${button("Lihat detail contoh", "sample", "primary")}</section>`;
}
function VariantA() {
  return `<div class="layout classic">${sidebar()}<main>${header()}<div class="content">${section === "Ringkasan" ? `${intro("Gambaran toko hari ini", "Angka utama dan aktivitas terbaru, tanpa pengaturan yang memenuhi halaman.", button("Salin link toko", "copy"))}${metrics()}<div class="overview-grid">${chart()}${attention()}</div>${orderTable()}` : sectionView()}</div></main></div>`;
}
function VariantB() {
  return `<div class="workspace"><aside class="rail"><a class="brand-mark" href="?variant=B">t.</a>${["Ringkasan", "Pesanan", "Produk", "Supplier", "Pengaturan"].map((name) => `<button data-section="${name}" class="${section === name ? "active" : ""}" title="${name}" aria-label="${name}">${icons[name]}</button>`).join("")}<span class="rail-bottom">AF</span></aside><div class="workspace-shell">${header()}<nav class="workspace-tabs" aria-label="Navigasi workspace">${groups
    .flatMap(([, items]) => items)
    .map(
      (name) =>
        `<button data-section="${name}" class="${section === name ? "active" : ""}" ${section === name ? 'aria-current="page"' : ""}>${name}</button>`
    )
    .join(
      ""
    )}</nav><main class="content">${section === "Ringkasan" ? `${intro("Operasional, tanpa distraksi.", "Workspace padat untuk mengecek antrean sambil memantau kondisi toko.", button("Tinjau 3 masalah →", "review", "primary"))}${metrics()}<div class="workspace-grid"><div>${orderTable(true)}</div><aside class="workspace-aside">${attention()}<section class="panel health"><small>STATUS SISTEM</small><h3>Semua jalur terpantau</h3><p>QRIS ${badge("Siap", "success")}</p><p>Supplier ${badge("2 / 3 aktif", "warning")}</p><p>Checkout ${badge("Normal", "success")}</p><small>Observasi contoh · 2 menit lalu</small></section></aside></div>` : sectionView()}</main></div></div>`;
}
function VariantC() {
  return `<div class="layout command">${sidebar()}<main>${header()}<div class="content">${
    section === "Ringkasan"
      ? `${intro("Yang perlu kamu tangani.", "Dashboard sebagai meja kerja, bukan kumpulan angka.", button("Lihat semua pesanan →", "orders"))}<div class="command-grid"><section class="action-board"><div class="board-heading"><span class="live-dot"></span> PRIORITAS HARI INI <span>3 tindakan</span></div><article class="hero-task"><small>01 / FULFILLMENT</small><h2>Pembayaran masuk.<br>Pesanan belum terkirim.</h2><p>2 pelanggan menunggu produk. Periksa proses supplier sebelum mengirim atau mengambil tindakan manual.</p><div class="task-preview"><span>INV-1028 · Netflix Premium</span>${badge("18 menit", "warning")}</div>${button("Buka antrean pengiriman ↗", "orders", "primary")}</article><div class="secondary-task"><div><small>02 / KEUANGAN</small><h3>1 top-up perlu ditinjau</h3><p>Periksa referensi dan bukti pembayaran.</p></div>${button("Tinjau →", "review")}</div><div class="secondary-task"><div><small>03 / INVENTORI</small><h3>4 produk kehabisan stok</h3><p>Pastikan katalog siap menerima pesanan.</p></div>${button("Kelola →", "products")}</div></section><aside class="pulse"><h3>Denyut toko</h3><p>Ringkasan hari ini · contoh</p>${metrics()}<div class="mini-trend">↗ <strong>+18,4%</strong><span>Pendapatan dibanding kemarin</span></div><div class="store-shortcut"><small>LINK TOKO</small><p>t.me/contoh_toko_bot</p>${button("Salin link", "copy")}</div></aside></div><section class="activity-feed"><div class="panel-heading"><h3>Aktivitas terakhir</h3><small>DATA CONTOH</small></div>${[
          [
            "10.42",
            "Pembayaran diterima",
            "INV-1028 · Raka Pratama · Rp 45.000",
          ],
          ["10.38", "Pesanan selesai", "INV-1027 · Nadia Putri · Canva Pro"],
          [
            "10.31",
            "Pesanan baru",
            "INV-1026 · Dimas Saputra · Spotify Premium",
          ],
        ]
          .map(
            ([time, title, detail]) =>
              `<div class="activity"><time>${time}</time><span><strong>${title}</strong><small>${detail}</small></span><span class="activity-dot"></span></div>`
          )
          .join("")}</section>`
      : sectionView()
  }</div></main></div>`;
}
function render() {
  document.body.dataset.variant = variant;
  document.querySelector("#app").innerHTML = {
    A: VariantA,
    B: VariantB,
    C: VariantC,
  }[variant]();
  document.querySelector("#variant-label").textContent =
    `${variant} — ${variants[variant]}`;
  document
    .querySelectorAll("input[type=search]")
    .forEach((input) => (input.value = query));
  document
    .querySelectorAll("select")
    .forEach((select) => (select.value = filter));
  renderRows();
}
function cycle(direction) {
  const keys = Object.keys(variants);
  variant =
    keys[(keys.indexOf(variant) + direction + keys.length) % keys.length];
  const url = new URL(location.href);
  url.searchParams.set("variant", variant);
  history.replaceState(null, "", url);
  render();
}
function showDetail(title, content) {
  document.querySelector("#detail-title").textContent = title;
  document.querySelector("#detail-body").innerHTML = content;
  document.querySelector("#detail").showModal();
}
let toastTimer;
function toast(text) {
  const el = document.querySelector("#toast");
  el.textContent = text;
  el.classList.add("visible");
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => el.classList.remove("visible"), 3500);
}
document.querySelector("#previous").onclick = () => cycle(-1);
document.querySelector("#next").onclick = () => cycle(1);
document.querySelector(".close").onclick = () =>
  document.querySelector("#detail").close();
document.addEventListener("keydown", (event) => {
  if (
    event.target.closest("input, textarea, select, [contenteditable]") ||
    document.querySelector("dialog[open]")
  )
    return;
  if (event.key === "ArrowLeft" || event.key === "ArrowRight") {
    event.preventDefault();
    cycle(event.key === "ArrowRight" ? 1 : -1);
  }
});
document.addEventListener("input", (event) => {
  if (event.target.matches("input[type=search]")) {
    query = event.target.value;
    renderRows();
  }
});
document.addEventListener("change", (event) => {
  if (event.target.matches("select")) {
    filter = event.target.value;
    renderRows();
  }
});
document.addEventListener("click", async (event) => {
  const navButton = event.target.closest("[data-section]");
  if (navButton) {
    section = navButton.dataset.section;
    query = "";
    filter = "Semua";
    render();
    window.scrollTo(0, 0);
    return;
  }
  const menu = event.target.closest(".menu-button");
  if (menu) {
    const open = document.body.classList.toggle("menu-open");
    menu.setAttribute("aria-expanded", String(open));
    return;
  }
  const row = event.target.closest("[data-order]");
  if (row) {
    const item = orders.find((order) => order[0] === row.dataset.order);
    showDetail(
      item[0],
      `<p>${item[1]} · ${item[2]}</p><dl><div><dt>Nominal</dt><dd>${item[3]}</dd></div><div><dt>Status pembayaran</dt><dd>${item[4] === "Menunggu bayar" ? "Menunggu pembayaran" : "Lunas"}</dd></div><div><dt>Status pengiriman</dt><dd>${item[4]}</dd></div></dl><p class="simulation-note">Data contoh. Tidak ada tombol yang mengirim produk atau mengubah transaksi asli.</p>`
    );
    return;
  }
  const action = event.target.closest("[data-action]")?.dataset.action;
  if (["orders", "review", "products"].includes(action)) {
    section = { orders: "Pesanan", review: "Kompensasi", products: "Produk" }[
      action
    ];
    render();
    window.scrollTo(0, 0);
  } else if (action === "copy") {
    try {
      await navigator.clipboard.writeText("https://t.me/contoh_toko_bot");
      toast("Link toko contoh tersalin — bukan toko asli.");
    } catch {
      showDetail(
        "Link toko contoh",
        "<p>https://t.me/contoh_toko_bot</p><p>Salin manual. Ini bukan link toko produksi.</p>"
      );
    }
  } else if (action)
    showDetail(
      {
        shop: "Preview toko",
        add: "Tambah produk — simulasi",
        stock: "Kelola stok — simulasi",
        sample: "Detail contoh",
      }[action],
      "<p>Interaksi ini hanya menunjukkan posisi dan alur antarmuka. Tidak ada koneksi backend, penyimpanan, atau perubahan pada dashboard asli.</p>"
    );
  document.body.classList.remove("menu-open");
});
window.addEventListener("popstate", () => {
  variant = new URLSearchParams(location.search).get("variant") || "A";
  if (!variants[variant]) variant = "A";
  render();
});
render();
