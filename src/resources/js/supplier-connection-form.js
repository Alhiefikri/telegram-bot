const amountFormat = new Intl.NumberFormat("id-ID", {
  maximumFractionDigits: 0,
});
const decimalFormat = new Intl.NumberFormat("id-ID", {
  maximumFractionDigits: 6,
});

function decimal(value) {
  return decimalFormat.format(value);
}

/** Accepts "24000", "24.000" (thousands) or "2,87" (decimal comma); null unless positive. */
function positiveDecimal(value) {
  const text = String(value ?? "").replace(/\s/g, "");
  const thousands = /^\d{1,3}(?:\.\d{3})+$/;
  if (!thousands.test(text) && !/^\d+(?:[.,]\d+)?$/.test(text)) return null;
  const number = Number(
    thousands.test(text) ? text.replace(/\./g, "") : text.replace(",", ".")
  );

  return Number.isFinite(number) && number > 0 ? number : null;
}

/**
 * IDR per one unit of the supplier currency, from the two numbers an operator
 * actually knows: what one USDT of funding costs in rupiah, and how much of the
 * supplier currency that one USDT credits. `IDR per USDT / units per USDT`.
 *
 * Rounded up to the six decimals a rate stores, so the derived unit cost can
 * never be understated. It is a suggestion only: nothing is written into the
 * global rate until the operator asks for it.
 */
export function rateFromDeposit(idrPerUsdt, unitsPerUsdt) {
  const idr = positiveDecimal(idrPerUsdt);
  const units = positiveDecimal(unitsPerUsdt);
  if (idr === null || units === null) {
    throw new Error(
      "Isi biaya rupiah per USDT dan jumlah unit yang didapat dengan angka lebih dari nol."
    );
  }
  const micros = Math.ceil((idr * 1_000_000) / units);
  if (!Number.isSafeInteger(micros) || micros <= 0) {
    throw new Error("Angka terlalu besar. Periksa kembali isian kalkulator.");
  }

  return String(micros / 1_000_000);
}

export default function supplierConnectionForm(
  provider,
  providers,
  confirmationUrl,
  sample = null,
  rates = {},
  selectedCurrency = null,
  initialName = null
) {
  return {
    provider,
    providers,
    sample,
    rates,
    name: initialName ?? providers[provider]?.label ?? "",
    accountCurrency: selectedCurrency ?? providers[provider]?.currency ?? "USD",
    saving: false,
    confirming: false,
    error: "",
    confirmationPassword: "",
    confirmationError: "",

    init() {
      this.$watch("provider", (next, previous) => {
        if (next === previous) return;
        if (
          !this.name ||
          this.name === (this.providers[previous]?.label ?? "")
        ) {
          this.name = this.providers[next]?.label ?? this.name;
        }
        this.accountCurrency = this.providers[next]?.currency ?? "USD";
      });
    },

    get defaultName() {
      return this.providers[this.provider]?.label ?? "";
    },

    resetName() {
      this.name = this.defaultName;
      this.$refs?.nameInput?.focus();
    },

    get currency() {
      const settings = this.providers[this.provider];

      return settings?.confirm
        ? this.accountCurrency
        : (settings?.currency ?? "USD");
    },

    get rateText() {
      const rate = this.rates[this.currency];
      return rate ? `Rp${decimal(Number(rate))}` : "belum diatur";
    },

    get sampleText() {
      if (!this.sample || this.sample.currency !== this.currency) return "";
      const unit = Number(this.sample.unit);
      const rawCost = this.sample.cost_idr;
      const cost = Number(rawCost);
      if (
        !Number.isFinite(unit) ||
        unit <= 0 ||
        rawCost === null ||
        rawCost === undefined ||
        !Number.isFinite(cost)
      ) {
        return "";
      }

      return `SKU ${decimal(unit)} ${this.sample.currency} ≈ Rp${amountFormat.format(cost)}`;
    },

    async save() {
      const form = this.$refs.connectionForm;
      if (this.saving || this.confirming || !form.reportValidity()) return;
      this.saving = true;
      this.error = "";
      try {
        const response = await fetch(form.action, {
          method: "POST",
          credentials: "same-origin",
          headers: { Accept: "application/json" },
          body: new FormData(form),
        });
        const result = await response.json().catch(() => ({}));
        if (response.status === 428) {
          this.confirmationPassword = "";
          this.confirmationError = "";
          this.$refs.passwordDialog.showModal();
          this.$nextTick(() => this.$refs.confirmationPassword.focus());
        } else if (response.ok && result.redirect_url) {
          window.location.assign(result.redirect_url);
        } else {
          this.error =
            result.errors?.name?.[0] ||
            result.errors?.currency?.[0] ||
            result.errors?.currency_confirmed?.[0] ||
            result.errors?.api_key?.[0] ||
            result.message ||
            "Supplier belum tersimpan. Periksa isian lalu coba lagi.";
        }
      } catch {
        this.error =
          "Koneksi terputus. Isianmu tetap ada. Periksa daftar supplier sebelum mencoba menyimpan lagi.";
      } finally {
        this.saving = false;
      }
    },

    async confirmPassword() {
      if (this.confirming) return;
      if (!this.confirmationPassword) {
        this.confirmationError = "Isi password admin terlebih dahulu.";
        this.$refs.confirmationPassword.focus();

        return;
      }
      this.confirming = true;
      this.confirmationError = "";
      let confirmed = false;
      try {
        const response = await fetch(confirmationUrl, {
          method: "POST",
          credentials: "same-origin",
          headers: { Accept: "application/json" },
          body: new URLSearchParams({
            _token:
              this.$refs.connectionForm.querySelector('[name="_token"]').value,
            password: this.confirmationPassword,
          }),
        });
        const result = await response.json().catch(() => ({}));
        if (response.ok && result.confirmed === true) {
          confirmed = true;
          this.$refs.passwordDialog.close();
        } else {
          this.confirmationError =
            result.errors?.password?.[0] ||
            (response.status === 429
              ? "Terlalu banyak percobaan. Tunggu sebentar lalu coba lagi."
              : "Password belum terkonfirmasi. Coba lagi.");
        }
      } catch {
        this.confirmationError =
          "Koneksi terputus. Coba konfirmasi lagi; isian supplier tetap ada.";
      } finally {
        this.confirmationPassword = "";
        this.confirming = false;
      }
      if (confirmed) await this.save();
    },

    cancelConfirmation() {
      if (this.confirming) return;
      this.confirmationPassword = "";
      this.confirmationError = "";
      this.$refs.passwordDialog.close();
      this.$refs.saveButton.focus();
    },
  };
}
