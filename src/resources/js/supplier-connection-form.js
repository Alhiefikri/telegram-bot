const amountFormat = new Intl.NumberFormat("id-ID", {
  maximumFractionDigits: 0,
});
const decimalFormat = new Intl.NumberFormat("id-ID", {
  maximumFractionDigits: 6,
});

function idrAmount(value) {
  return `Rp${amountFormat.format(value)}`;
}

function idrRate(value) {
  return `Rp${decimalFormat.format(value)}`;
}

function decimal(value) {
  return decimalFormat.format(value);
}

function outsideBand(rate, band) {
  if (!band) return false;

  return rate < Number(band.min) || rate > Number(band.max);
}

/** ceil(unit x rate) in whole rupiah, computed from integer micros like the server does. */
function unitCostIdr(unit, rateMicros) {
  const micros = Math.round(Number(unit) * rateMicros);

  return Number.isSafeInteger(micros) ? Math.ceil(micros / 1_000_000) : null;
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
 * Rounded up to the six decimals a connection stores, so the derived unit cost
 * can never be understated. It is a suggestion only: nothing is written into the
 * stored rate until the operator asks for it.
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
  sample = null
) {
  return {
    provider,
    providers,
    sample,
    saving: false,
    confirming: false,
    error: "",
    confirmationPassword: "",
    confirmationError: "",
    rateDerivation: "",
    sampleDerivation: "",
    rateWarning: "",
    calcIdrPerUsdt: "",
    calcVndPerUsdt: "",
    calcSuggestion: "",
    calcSample: "",
    calcCost: "",
    calcWarning: "",
    calcError: "",

    init() {
      this.$watch("provider", (next, previous) => {
        if (next === previous) return;
        if (
          this.providers[next].currency !== this.providers[previous].currency ||
          this.providers[next].confirm ||
          this.providers[previous].confirm
        ) {
          this.clearExchangeRate();
        }
        this.refreshRateCalculator();
      });
      this.refreshRateDerivation();
    },

    clearExchangeRate() {
      this.$refs.exchangeRate.value = "";
      this.refreshRateDerivation();
    },

    /**
     * Shows what the typed rate means: the IDR value of one supplier currency
     * unit and, when the connection has catalog rows, the derived cost of its
     * most expensive SKU. A rupiah-per-USDT figure is visibly absurd here.
     */
    refreshRateDerivation() {
      const settings = this.providers[this.provider];
      const currency = settings?.currency ?? "";
      const raw = String(this.$refs.exchangeRate?.value ?? "").trim();
      const rate = raw === "" ? Number.NaN : Number(raw);
      if (!Number.isFinite(rate) || rate <= 0) {
        this.rateDerivation = "";
        this.sampleDerivation = "";
        this.rateWarning = "";

        return;
      }
      this.rateDerivation = `1 ${currency} ≈ ${idrRate(rate)}`;
      this.rateWarning = outsideBand(rate, settings?.band)
        ? `Kurs di luar rentang wajar. Nilai rupiah untuk 1 ${currency} harus antara ${idrRate(Number(settings.band.min))} dan ${idrRate(Number(settings.band.max))}. Kalau angka ini biaya per USDT, bagi dengan jumlah ${currency} yang diterima per USDT (contoh: Rp18.000 ÷ 26.000 ${currency} = Rp0,692308). Server menolak kurs di luar rentang ini.`
        : "";
      const unit =
        this.sample && this.sample.currency === currency
          ? Number(this.sample.unit)
          : Number.NaN;
      const cost =
        Number.isFinite(unit) && unit > 0
          ? unitCostIdr(unit, Math.round(rate * 1_000_000))
          : null;
      this.sampleDerivation =
        cost === null
          ? ""
          : `SKU ${decimal(unit)} ${currency} ≈ ${idrAmount(cost)}`;
    },

    /**
     * Optional helper that only suggests a rate from the two numbers an operator
     * really knows: what one USDT of funding costs, and how much VND that one
     * USDT credits. The suggestion is never written into the stored rate by
     * itself, so a stale or mistyped deposit figure cannot be saved unnoticed.
     */
    refreshRateCalculator() {
      const inputs = [this.calcIdrPerUsdt, this.calcVndPerUsdt];
      if (inputs.some((value) => String(value ?? "").trim() === "")) {
        this.resetRateCalculator();

        return;
      }
      try {
        const rate = rateFromDeposit(...inputs);
        const currency = this.providers[this.provider]?.currency ?? "";
        const unit =
          this.sample && this.sample.currency === currency
            ? Number(this.sample.unit)
            : Number.NaN;
        const cost =
          Number.isFinite(unit) && unit > 0
            ? unitCostIdr(unit, Math.round(Number(rate) * 1_000_000))
            : null;
        this.calcSuggestion = rate;
        this.calcSample =
          cost === null ? "" : `SKU ${decimal(unit)} ${currency}`;
        this.calcCost = cost === null ? "" : idrAmount(cost);
        this.calcWarning = outsideBand(
          Number(rate),
          this.providers[this.provider]?.band
        )
          ? "Saran kurs ini di luar rentang wajar. Periksa lagi biaya per USDT dan jumlah unit yang didapat."
          : "";
        this.calcError = "";
      } catch (error) {
        this.resetRateCalculator();
        this.calcError = error.message;
      }
    },

    resetRateCalculator() {
      this.calcSuggestion = "";
      this.calcSample = "";
      this.calcCost = "";
      this.calcWarning = "";
      this.calcError = "";
    },

    applyRateSuggestion() {
      if (this.calcSuggestion === "") return;
      this.$refs.exchangeRate.value = this.calcSuggestion;
      this.refreshRateDerivation();
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
            result.errors?.exchange_rate_idr?.[0] ||
            result.errors?.currency?.[0] ||
            result.errors?.currency_confirmed?.[0] ||
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
