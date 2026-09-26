const MAX_ACCOUNT_CARDS = 20;
const ACCOUNT_INPUT_CLOSE_CONFIRMATION =
  "Tutup Input Akun? Draf akun yang belum disimpan akan hilang.";

let accountInputReviewRequestSequence = 0;

const emptyAccountCard = (createOperationKey) => ({
  id: createOperationKey(),
  email: "",
  password: "",
  accessMethod: "none",
  accessValue: "",
});

export const emptyAccountInputState = (createOperationKey) => ({
  accountCards: [emptyAccountCard(createOperationKey)],
  accountInputStage: "edit",
  accountInputReviewed: false,
  accountInputReview: null,
  accountInputReviewing: false,
  accountInputErrors: {},
  accountInputSubmitting: false,
  accountInputOperationKey: "",
  accountInputMessage: "",
  accountInputBuyerLocale: "id",
  accountInputDraftRevision: 0,
  accountInputReviewRequestId: 0,
});

const isRecord = (value) =>
  value !== null && typeof value === "object" && !Array.isArray(value);

const isValidReviewAccount = (account) =>
  isRecord(account) &&
  Number.isInteger(account.index) &&
  Number.isInteger(account.account_number) &&
  typeof account.email_masked === "string" &&
  ["none", "otp_mail_link", "auth_token"].includes(account.access_method) &&
  (account.otp_domain === null || typeof account.otp_domain === "string") &&
  ["clear", "duplicate"].includes(account.duplicate_status) &&
  Array.isArray(account.duplicate_fields) &&
  Array.isArray(account.duplicate_sources);

const isValidReview = (review, payload) =>
  isRecord(review) &&
  review.operation_key === payload.operation_key &&
  ["ready", "duplicate"].includes(review.status) &&
  review.account_count === payload.accounts.length &&
  Array.isArray(review.accounts) &&
  review.accounts.length === payload.accounts.length &&
  review.accounts.every(isValidReviewAccount) &&
  isRecord(review.receipt_preview);

const defaultFetch = (...args) => globalThis.fetch(...args);
const defaultCsrfToken = () =>
  globalThis.document?.querySelector?.('input[name="_token"]')?.value ?? "";
const defaultConfirm = (message) =>
  globalThis.window?.confirm?.(message) ?? true;

export default function accountInputWorkflow({
  createOperationKey,
  fetchRequest = defaultFetch,
  getCsrfToken = defaultCsrfToken,
  confirm = defaultConfirm,
} = {}) {
  return {
    ...emptyAccountInputState(createOperationKey),

    accountInputChanged() {
      this.accountInputDraftRevision += 1;
      this.accountInputStage = "edit";
      this.accountInputReviewed = false;
      this.accountInputReview = null;
      this.accountInputReviewing = false;
      this.accountInputErrors = {};
      this.accountInputMessage = "";
    },

    accountInputDirty() {
      return (
        this.accountCards.length > 1 ||
        this.accountInputReviewed ||
        this.accountCards.some(
          (card) =>
            [card.email, card.password, card.accessValue].some(
              (value) => String(value ?? "") !== ""
            ) || card.accessMethod !== "none"
        )
      );
    },

    confirmAccountInputClose() {
      return (
        !this.accountInputDirty() || confirm(ACCOUNT_INPUT_CLOSE_CONFIRMATION)
      );
    },

    accountInputError(index, field) {
      const candidates = [
        `accounts.${index}.${field}`,
        `${index}.${field}`,
        `account.${index}.${field}`,
      ];

      for (const key of candidates) {
        const value = this.accountInputErrors?.[key];
        if (Array.isArray(value) && value.length > 0) {
          return value[0];
        }
        if (typeof value === "string" && value !== "") {
          return value;
        }
      }

      return "";
    },

    accountInputErrorClass(index, field) {
      return this.accountInputError(index, field)
        ? "border-[#ef4444] ring-2 ring-[#ef4444]/30"
        : "";
    },

    accountInputFirstError() {
      const key = Object.keys(this.accountInputErrors ?? {})[0] ?? "";
      const match = key.match(
        /(?:accounts\.)?(\d+)\.(email|password|access_method|access_value)/
      );

      return match ? { index: Number(match[1]), field: match[2] } : null;
    },

    focusAccountInputError() {
      const error = this.accountInputFirstError();
      if (!error) {
        return;
      }

      this.$nextTick(() =>
        globalThis.document
          ?.getElementById?.(
            `account-${error.field.replaceAll("_", "-")}-${error.index}`
          )
          ?.focus()
      );
    },

    addAccountCard() {
      if (this.accountCards.length >= MAX_ACCOUNT_CARDS) {
        return;
      }

      this.accountCards.push(emptyAccountCard(createOperationKey));
      this.accountInputChanged();
      this.$nextTick(() =>
        globalThis.document
          ?.getElementById?.(`account-email-${this.accountCards.length - 1}`)
          ?.focus()
      );
    },

    removeAccountCard(index) {
      if (this.accountCards.length <= 1) {
        return;
      }

      this.accountCards.splice(index, 1);
      this.accountInputChanged();
      this.$nextTick(() =>
        globalThis.document
          ?.getElementById?.(
            `account-email-${Math.min(index, this.accountCards.length - 1)}`
          )
          ?.focus()
      );
    },

    setAccountAccessMethod(index, method) {
      const card = this.accountCards[index];
      if (!card) {
        return;
      }

      card.accessMethod = method;
      card.accessValue = "";
      this.accountInputChanged();
    },

    accountAccessLabel(method) {
      return (
        {
          none: "Tidak ada",
          otp_mail_link: "OTP Mail Link",
          auth_token: "Auth Token",
        }[method] ?? "Tidak ada"
      );
    },

    accountAccessDomain(card) {
      if (card?.accessMethod !== "otp_mail_link" || !card.accessValue) {
        return "";
      }

      try {
        const url = new URL(card.accessValue);
        return url.protocol === "https:" && !url.username && !url.password
          ? url.hostname
          : "";
      } catch {
        return "";
      }
    },

    accountInputReviewAccounts() {
      const accounts = this.accountInputReview?.accounts;
      return Array.isArray(accounts) ? accounts : [];
    },

    accountInputReviewDuplicateSummary(account) {
      const fields = {
        email: "email",
        access_value: "Auth Token",
      };
      const sources = {
        submission: "pengajuan ini",
        registry: "Credential Identity registry",
      };
      const duplicateFields = (
        Array.isArray(account?.duplicate_fields) ? account.duplicate_fields : []
      ).map((field) => fields[field] ?? field);
      const duplicateSources = (
        Array.isArray(account?.duplicate_sources)
          ? account.duplicate_sources
          : []
      ).map((source) => sources[source] ?? source);

      if (duplicateSources.length === 0) {
        return "Tidak ada konflik identitas";
      }

      return `Duplikat ${duplicateFields.join(" + ")} · ${duplicateSources.join(" + ")}`;
    },

    accountInputCanCommit() {
      return (
        this.accountInputReviewed &&
        this.accountInputStage === "review" &&
        this.accountInputReview?.status === "ready"
      );
    },

    accountInputValidationErrors() {
      const errors = {};
      const add = (index, field, message) => {
        const key = `accounts.${index}.${field}`;
        errors[key] ??= [];
        errors[key].push(message);
      };
      const cards = Array.isArray(this.accountCards) ? this.accountCards : [];

      if (
        cards.length < 1 ||
        cards.length > MAX_ACCOUNT_CARDS ||
        cards.length !== this.accountCards?.length
      ) {
        errors.accounts = [
          `Input Akun harus berisi 1 sampai ${MAX_ACCOUNT_CARDS} akun.`,
        ];
      }

      cards.forEach((rawCard, index) => {
        const card = isRecord(rawCard) ? rawCard : {};
        const email = String(card.email ?? "");
        const password = String(card.password ?? "");
        const method = String(card.accessMethod ?? "");
        const accessValue = String(card.accessValue ?? "");
        if (email.trim() === "") {
          add(index, "email", "Email wajib diisi.");
        } else if (email.length > 254) {
          add(index, "email", "Email maksimal 254 karakter.");
        } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim())) {
          add(index, "email", "Email tidak valid.");
        }

        if (password.trim() === "") {
          add(index, "password", "Password wajib diisi.");
        } else if (password.length > 1024) {
          add(index, "password", "Password maksimal 1024 karakter.");
        }

        if (!["none", "otp_mail_link", "auth_token"].includes(method)) {
          add(index, "access_method", "Metode akses tidak valid.");
        } else if (method === "none") {
          if (accessValue.trim() !== "") {
            add(
              index,
              "access_value",
              "Nilai metode akses harus kosong saat memilih Tidak ada."
            );
          }
        } else if (accessValue.trim() === "") {
          add(index, "access_value", "Nilai metode akses wajib diisi.");
        } else if (method === "otp_mail_link") {
          try {
            const url = new URL(accessValue);
            if (
              url.protocol !== "https:" ||
              !url.hostname ||
              url.username ||
              url.password
            ) {
              add(
                index,
                "access_value",
                "OTP Mail Link harus berupa URL HTTPS yang aman."
              );
            }
          } catch {
            add(
              index,
              "access_value",
              "OTP Mail Link harus berupa URL HTTPS yang aman."
            );
          }
          if (accessValue.length > 2048) {
            add(index, "access_value", "OTP Mail Link maksimal 2048 karakter.");
          }
        } else if (accessValue.length > 3500) {
          add(index, "access_value", "Auth Token maksimal 3500 karakter.");
        }
      });

      return errors;
    },

    async reviewAccountInput() {
      const errors = this.accountInputValidationErrors();
      this.accountInputErrors = errors;
      this.accountInputMessage = "";
      this.accountInputReview = null;

      if (Object.keys(errors).length > 0) {
        this.accountInputStage = "edit";
        this.accountInputReviewed = false;
        this.focusAccountInputError();
        return false;
      }

      const submittedRevision = this.accountInputDraftRevision;
      const requestId = ++accountInputReviewRequestSequence;
      this.accountInputReviewRequestId = requestId;
      this.accountInputReviewing = true;
      const payload = this.accountInputPayload();
      this.accountInputOperationKey = payload.operation_key;
      const csrf = getCsrfToken();

      try {
        const response = await fetchRequest(
          this.stockAccountsReviewAction ||
            `${this.stockAccountsFormAction}/review`,
          {
            method: "POST",
            credentials: "same-origin",
            headers: {
              Accept: "application/json",
              "Content-Type": "application/json",
              "X-CSRF-TOKEN": csrf,
            },
            body: JSON.stringify(payload),
          }
        );
        const body = await response.json().catch(() => ({}));

        if (
          submittedRevision !== this.accountInputDraftRevision ||
          requestId !== this.accountInputReviewRequestId
        ) {
          return false;
        }

        if (!response.ok) {
          this.accountInputErrors = body.errors ?? {};
          this.accountInputMessage =
            body.message ?? "Review Input Akun tidak dapat dilakukan.";
          this.accountInputStage = "edit";
          this.accountInputReviewed = false;
          this.focusAccountInputError();
          return false;
        }

        const review = body.review ?? body;
        if (!isValidReview(review, payload)) {
          this.accountInputMessage =
            "Review server tidak mengembalikan hasil yang valid.";
          this.accountInputStage = "edit";
          this.accountInputReviewed = false;
          return false;
        }

        this.accountInputReview = review;
        this.accountInputStage = "review";
        this.accountInputReviewed = true;
        this.accountInputMessage = body.message ?? "Review server selesai.";
        return review.status === "ready";
      } catch {
        if (
          submittedRevision !== this.accountInputDraftRevision ||
          requestId !== this.accountInputReviewRequestId
        ) {
          return false;
        }

        this.accountInputMessage =
          "Koneksi gagal. Draf akun tetap tersimpan di halaman ini.";
        this.accountInputStage = "edit";
        this.accountInputReviewed = false;
        return false;
      } finally {
        if (requestId === this.accountInputReviewRequestId) {
          this.accountInputReviewing = false;
        }
      }
    },

    editAccountInput() {
      this.accountInputStage = "edit";
      this.accountInputReviewed = false;
      this.accountInputReview = null;
      this.accountInputMessage = "";
      this.$nextTick(() =>
        globalThis.document?.getElementById?.("account-email-0")?.focus()
      );
    },

    accountInputPayload() {
      return {
        operation_key: this.accountInputOperationKey || createOperationKey(),
        buyer_locale: this.accountInputBuyerLocale,
        accounts: this.accountCards.map((card) => ({
          email: card.email,
          password: card.password,
          access_method: card.accessMethod,
          access_value: card.accessMethod === "none" ? null : card.accessValue,
        })),
      };
    },

    async submitAccountInput(event) {
      event.preventDefault();

      if (this.accountInputSubmitting) {
        return;
      }

      if (!this.accountInputCanCommit()) {
        await this.reviewAccountInput();
        return;
      }

      const submittedRevision = this.accountInputDraftRevision;

      this.accountInputSubmitting = true;
      this.accountInputErrors = {};
      this.accountInputMessage = "";
      const payload = this.accountInputPayload();
      this.accountInputOperationKey = payload.operation_key;
      const csrf = getCsrfToken();

      try {
        const response = await fetchRequest(this.stockAccountsFormAction, {
          method: "POST",
          credentials: "same-origin",
          headers: {
            Accept: "application/json",
            "Content-Type": "application/json",
            "X-CSRF-TOKEN": csrf,
          },
          body: JSON.stringify(payload),
        });
        const body = await response.json().catch(() => ({}));

        if (submittedRevision !== this.accountInputDraftRevision) {
          return;
        }

        if (!response.ok) {
          this.accountInputErrors = body.errors ?? {};
          this.accountInputMessage =
            body.message ?? "Input Akun tidak dapat disimpan.";
          this.accountInputStage = "edit";
          this.accountInputReviewed = false;
          this.accountInputSubmitting = false;
          this.focusAccountInputError();
          return;
        }

        const count = Number(body.item_count ?? this.accountCards.length);
        this.accountInputSubmitting = false;
        this.accountInputMessage = `Stok bertambah ${count} akun.`;
        this.closeStockModal(true);
        globalThis.window.location.assign(
          body.redirect_url ?? this.stockReturnUrl
        );
      } catch {
        if (submittedRevision !== this.accountInputDraftRevision) {
          return;
        }
        this.accountInputMessage =
          "Koneksi gagal. Draf akun tetap tersimpan di halaman ini.";
        this.accountInputSubmitting = false;
      }
    },
  };
}
