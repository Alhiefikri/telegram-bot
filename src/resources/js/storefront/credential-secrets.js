function decodeCredential(button) {
  const bytes = Uint8Array.from(
    atob(button.dataset.credentialCopy),
    (character) => character.charCodeAt(0)
  );
  return new TextDecoder("utf-8", { fatal: true, ignoreBOM: true }).decode(
    bytes
  );
}

export default function initCredentialSecrets(root = document) {
  const secrets = root.querySelectorAll("[data-credential-secret]");
  const copyButtons = root.querySelectorAll("[data-credential-copy]");

  if (!secrets.length && !copyButtons.length) {
    return;
  }

  const mask = (secret) => {
    const value = secret.querySelector("[data-secret-value]");
    const toggle = secret.querySelector("[data-secret-toggle]");
    value.hidden = true;
    value.textContent = "";
    secret.querySelector("[data-secret-mask]").hidden = false;
    toggle.textContent = "Tampilkan";
    toggle.setAttribute("aria-pressed", "false");
    toggle.setAttribute(
      "aria-label",
      `Tampilkan ${toggle.dataset.secretLabel}`
    );
  };
  const maskAll = () => secrets.forEach(mask);

  secrets.forEach((secret) => {
    const toggle = secret.querySelector("[data-secret-toggle]");
    const value = secret.querySelector("[data-secret-value]");
    mask(secret);
    toggle.hidden = false;
    toggle.addEventListener("click", () => {
      if (!value.hidden) {
        mask(secret);
        return;
      }
      if (document.hidden || !document.hasFocus()) {
        return;
      }
      value.textContent = decodeCredential(
        secret.querySelector("[data-credential-copy]")
      );
      value.hidden = false;
      secret.querySelector("[data-secret-mask]").hidden = true;
      toggle.textContent = "Sembunyikan";
      toggle.setAttribute("aria-pressed", "true");
      toggle.setAttribute(
        "aria-label",
        `Sembunyikan ${toggle.dataset.secretLabel}`
      );
    });
    secret.addEventListener("keydown", (event) => {
      if (event.key === "Escape") {
        mask(secret);
        toggle.focus();
      }
    });
    secret.addEventListener("focusout", (event) => {
      if (!secret.contains(event.relatedTarget)) {
        mask(secret);
      }
    });
  });

  copyButtons.forEach((button) => {
    const status = button
      .closest("[data-copy-group]")
      ?.querySelector("[data-copy-status]");
    button.hidden = false;
    button.addEventListener("click", async () => {
      try {
        await navigator.clipboard.writeText(decodeCredential(button));
        if (status) {
          status.textContent = button.dataset.copySuccess || "Tersalin.";
        }
      } catch {
        if (status) {
          status.textContent =
            "Tidak dapat menyalin. Tampilkan data, lalu salin teks secara manual.";
        }
      }
    });
  });

  window.addEventListener("blur", maskAll);
  window.addEventListener("pagehide", maskAll);
  window.addEventListener("pageshow", maskAll);
  document.addEventListener("visibilitychange", maskAll);
  document.addEventListener("freeze", maskAll);
}
