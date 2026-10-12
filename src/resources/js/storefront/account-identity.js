export default function initAccountIdentity() {
  const header = document.querySelector("[data-account-identity-url]");

  if (!header) {
    return;
  }

  const links = header.querySelectorAll("[data-account-link]");
  const refresh = async () => {
    try {
      const response = await window.fetch(header.dataset.accountIdentityUrl, {
        method: "GET",
        credentials: "same-origin",
        cache: "no-store",
        referrerPolicy: "no-referrer",
        headers: { Accept: "application/json" },
      });

      if (!response.ok) {
        return;
      }

      const identity = await response.json();
      const authenticated =
        identity?.authenticated === true &&
        typeof identity.name === "string" &&
        identity.name.trim() !== "" &&
        typeof identity.initial === "string" &&
        identity.initial.trim() !== "";
      const guest =
        identity?.authenticated === false &&
        identity.name === null &&
        identity.initial === null;

      if (!guest && !authenticated) {
        return;
      }

      const name = authenticated ? identity.name : "Daftar / Masuk";
      const label = authenticated ? `Buka profil ${name}` : name;

      links.forEach((link) => {
        const accountName = link.querySelector("[data-account-name]");
        accountName.textContent = name;
        accountName.classList.toggle("truncate", authenticated);
        const avatar = link.querySelector("[data-account-initial]");
        avatar.textContent = authenticated ? identity.initial : "";
        avatar.hidden = !authenticated;
        link.setAttribute("aria-label", label);
        link.setAttribute("title", label);
      });
    } catch {
      // Preserve the server-rendered state when session lookup is unavailable.
    }
  };

  refresh();
  window.addEventListener("pageshow", (event) => {
    if (event.persisted) {
      refresh();
    }
  });
}
