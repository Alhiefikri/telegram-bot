# Customer USDT funding melalui receiver eksklusif dan signer eksternal

Status: **Selected for design; activation unqualified**, 2026-10-04. Keputusan agent berdasarkan delegasi Owner untuk memilih pendekatan [#539](https://github.com/Alhiefikri/telegram-bot/issues/539), bukan sign-off legal/custody.

Pilih verified operator-controlled public receiver yang terikat permanen ke satu Customer, terpisah dari checkout; private key/seed dan signing tetap di luar aplikasi. Jalur desain pertama BSC memakai seam verifikasi existing, dengan provenance token/bridge tetap harus qualified. Akun Binance biasa, shared receiver dengan nominal unik, dan asumsi custody provider berdasarkan API tidak memenuhi evidence H1. Binance Pay funding tetap menunggu qualification sendiri sesuai pilihan produk H0-v1.

Keputusan ini menukar kemudahan shared address dengan atribusi Customer yang lebih kuat, tetapi menambah kewajiban provisioning, treasury backing 1:1, gas dan signing external return. Aplikasi watch-only tidak menjadikan dana Customer non-custodial. [Decision/evidence register H1](../balance-h1-custody-decision.md) merinci bukti missing; real funding/spend tetap no-go, #539 tetap open, dan ledger scaffolding bisa berjalan sesuai gates asli. Tidak menggantikan H0-v1 atau mengaktifkan perubahan runtime.

Kontrak registry-nya diimplementasikan oleh U4 [#549](https://github.com/Alhiefikri/telegram-bot/issues/549): `usdt_receiver_address_claims` mengikat `(chain_id, normalized_address)` permanen ke satu channel (`checkout` atau `customer_deposit`), `customer_usdt_deposit_receivers` membind alamat publik ke tepat satu Customer yang tidak pernah direassign, dan provisioning hanya lewat adapter approved di container tag `usdt-deposit-receiver-provisioners` yang sengaja kosong. Kapasitas/kustodi/izin tetap belum dibuktikan, jadi allocation fail closed dan status activation tetap unqualified. Detail: [balance-u4-receiver-ownership.md](../balance-u4-receiver-ownership.md).

## Technical/qualification separation (Owner-approved, 2026-10-06)

For U5 #550 the Owner approved implementing the actual watch-only BSC rail against U4's existing verified, permanently Customer-bound public receivers, while retaining H1 as a separate qualification/activation gate. Automatic external address provisioning is not required for this selected path: an already verified binding is capacity for that Customer; missing capacity fails closed rather than invoking an unapproved provider or substituting a shared receiver.

`BscUsdtTopUpRail` performs real RPC receipt/finality inspection and persists/reuses a local immutable invoice reference. There is no remote invoice create or remote creation-unknown state to recover on this path. New creation is restricted to disposable `local`/`testing` environments, with readiness explicitly reporting `fundingAuthorized = false`. This technical implementation is not qualification of receiver inventory, legal/provider terms, token provenance, treasury/gas or production backing. Independent actual staging evidence is still missing; #539, original #550 qualification acceptance and downstream activation are not declared complete.

