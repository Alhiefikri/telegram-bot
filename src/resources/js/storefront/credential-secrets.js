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
  const disclosures = root.querySelectorAll("[data-credential-disclosure]");
  const confirmation = root.querySelector("[data-credential-confirm]");
  let pendingDisclosure = null;

  if (!secrets.length && !copyButtons.length) {
    return;
  }

  const mask = (secret) => {
    const value = secret.querySelector("[data-secret-value]");
    const toggle = secret.querySelector("[data-secret-toggle]");
    value.hidden = true;
    value.textContent = "";
    secret.querySelector("[data-secret-mask]").hidden = false;
    toggle.textContent = toggle.dataset.show;
    toggle.setAttribute("aria-pressed", "false");
    toggle.setAttribute("aria-label", toggle.dataset.showLabel);
  };
  const maskAll = () => secrets.forEach(mask);

  const closeDisclosures = () => {
    pendingDisclosure = null;
    if (confirmation?.open) confirmation.close("cancel");
    disclosures.forEach((disclosure) => {
      disclosure.open = false;
      const toggle = disclosure.querySelector(
        "[data-credential-disclosure-toggle]"
      );
      toggle.textContent = toggle.dataset.show;
      disclosure.querySelectorAll("[data-copy-status]").forEach((status) => {
        status.textContent = "";
      });
    });
    maskAll();
  };
  confirmation?.addEventListener("close", () => {
    const disclosure = pendingDisclosure;
    pendingDisclosure = null;
    if (
      confirmation.returnValue !== "reveal" ||
      !disclosure ||
      document.hidden
    ) {
      return;
    }
    disclosure.open = true;
    const toggle = disclosure.querySelector(
      "[data-credential-disclosure-toggle]"
    );
    toggle.textContent = toggle.dataset.hide;
  });
  confirmation?.addEventListener("click", (event) => {
    if (event.target !== confirmation) return;
    const bounds = confirmation.getBoundingClientRect();
    if (
      event.clientX < bounds.left ||
      event.clientX > bounds.right ||
      event.clientY < bounds.top ||
      event.clientY > bounds.bottom
    ) {
      confirmation.close("cancel");
    }
  });
  disclosures.forEach((disclosure) => {
    const toggle = disclosure.querySelector(
      "[data-credential-disclosure-toggle]"
    );
    toggle.addEventListener("click", (event) => {
      event.preventDefault();
      if (disclosure.open) {
        closeDisclosures();
        return;
      }
      if (!confirmation) return;
      pendingDisclosure = disclosure;
      confirmation.returnValue = "cancel";
      confirmation.showModal();
    });
  });

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
      toggle.textContent = toggle.dataset.hide;
      toggle.setAttribute("aria-pressed", "true");
      toggle.setAttribute("aria-label", toggle.dataset.hideLabel);
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
          status.textContent = button.dataset.copySuccess ?? "";
        }
      } catch {
        if (status) {
          status.textContent =
            button.closest("[data-credential-copy-failed]")?.dataset
              .credentialCopyFailed ?? "";
        }
      }
    });
  });

  window.addEventListener("blur", maskAll);
  window.addEventListener("pagehide", closeDisclosures);
  window.addEventListener("pageshow", closeDisclosures);
  document.addEventListener("visibilitychange", () => {
    if (document.hidden) closeDisclosures();
    else maskAll();
  });
  document.addEventListener("freeze", closeDisclosures);
}
