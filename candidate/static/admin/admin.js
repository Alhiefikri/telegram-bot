/* Vanilla lifecycle for the P22 admin shell. No framework or build step. */
(function () {
  "use strict";
  const root = document.documentElement;
  root.classList.add("js");

  let theme = "light";
  try { theme = localStorage.getItem("lhie-admin-theme") === "dark" ? "dark" : "light"; } catch (_) {}
  const applyTheme = (value) => {
    root.dataset.theme = value;
    document.querySelectorAll("[data-theme-toggle]").forEach((button) => {
      button.hidden = false;
      button.setAttribute("aria-pressed", String(value === "dark"));
      button.setAttribute("aria-label", value === "dark" ? "Ganti ke tema terang" : "Ganti ke tema gelap");
      button.title = value === "dark" ? "Tema terang" : "Tema gelap";
    });
  };
  applyTheme(theme);

  const showDialog = (dialog) => {
    if (!dialog || typeof dialog.showModal !== "function" || dialog.open) return;
    document.querySelectorAll("dialog[open]").forEach((open) => {
      if (open !== dialog) open.close();
    });
    const drawer = document.getElementById("admin-drawer");
    if (drawer && drawer.open) drawer.close();
    dialog.showModal();
    const first = dialog.querySelector("input:not([type=hidden]):not([disabled]), select:not([disabled]), textarea:not([disabled]), button:not([disabled]), a[href]");
    if (first) first.focus();
  };

  document.addEventListener("DOMContentLoaded", () => {
    applyTheme(theme);
    const themeButton = document.querySelector("[data-theme-toggle]");
    if (themeButton) {
      themeButton.addEventListener("click", () => {
        theme = root.dataset.theme === "dark" ? "light" : "dark";
        applyTheme(theme);
        try { localStorage.setItem("lhie-admin-theme", theme); } catch (_) {}
      });
    }

    const drawer = document.getElementById("admin-drawer");
    const drawerTrigger = document.querySelector("[data-admin-drawer-open]");
    const drawerClose = document.querySelector("[data-admin-drawer-close]");
    let drawerOpener = null;
    const closeDrawer = () => {
      if (drawer && drawer.open) drawer.close();
      if (drawerTrigger) drawerTrigger.setAttribute("aria-expanded", "false");
      if (drawerOpener && drawerOpener.isConnected) drawerOpener.focus();
      drawerOpener = null;
    };
    if (drawer && drawerTrigger) {
      drawerTrigger.addEventListener("click", () => {
        if (drawer.open) return;
        drawerOpener = document.activeElement;
        drawer.showModal();
        drawerTrigger.setAttribute("aria-expanded", "true");
        const first = drawer.querySelector("a[href]");
        if (first) first.focus();
      });
      drawer.addEventListener("click", (event) => {
        if (event.target === drawer) closeDrawer();
      });
      drawer.addEventListener("close", () => {
        if (drawerTrigger) drawerTrigger.setAttribute("aria-expanded", "false");
      });
      drawer.addEventListener("cancel", () => {
        if (drawerTrigger) drawerTrigger.setAttribute("aria-expanded", "false");
      });
    }
    if (drawerClose) drawerClose.addEventListener("click", closeDrawer);

    document.querySelectorAll("[data-dialog-open]").forEach((button) => {
      button.addEventListener("click", () => {
        showDialog(document.getElementById(button.dataset.dialogOpen));
      });
    });
    document.querySelectorAll("dialog.admin-dialog, dialog.admin-drawer").forEach((dialog) => {
      dialog.addEventListener("click", (event) => {
        if (event.target === dialog) dialog.close();
      });
    });
    document.querySelectorAll("[data-dialog-close]").forEach((button) => {
      button.addEventListener("click", () => {
        const dialog = button.closest("dialog");
        if (dialog) dialog.close();
      });
    });

    const dirtyForms = [];
    document.querySelectorAll("form[data-dirty-guard]").forEach((form) => {
      let dirty = false;
      const note = form.querySelector("[data-dirty-note]");
      const mark = () => {
        if (dirty) return;
        dirty = true;
        dirtyForms.push(form);
        if (note) note.hidden = false;
      };
      form.addEventListener("input", mark);
      form.addEventListener("change", mark);
      form.addEventListener("submit", () => {
        dirty = false;
        if (note) note.hidden = true;
        const index = dirtyForms.indexOf(form);
        if (index >= 0) dirtyForms.splice(index, 1);
      });
    });
    window.addEventListener("beforeunload", (event) => {
      if (dirtyForms.length === 0) return;
      event.preventDefault();
      event.returnValue = "";
    });

    const bulkForm = document.querySelector("form[data-bulk-delete]");
    const selectAll = document.querySelector("[data-select-all]");
    const countLabel = document.querySelector("[data-bulk-count]");
    const bulkSubmit = bulkForm ? bulkForm.querySelector("button[type=submit]") : null;
    const selected = () => bulkForm
      ? Array.from(document.querySelectorAll(
          "input[form='bulk-stock-delete'][name='stock_item_ids']:checked"
        ))
      : [];
    const updateBulk = () => {
      const count = selected().length;
      if (bulkSubmit) bulkSubmit.disabled = count === 0;
      if (countLabel) countLabel.textContent = count === 0 ? "Pilih stok yang dapat dihapus." : count + " stok dipilih.";
    };
    if (selectAll) {
      selectAll.addEventListener("change", () => {
        document.querySelectorAll("[data-stock-select]").forEach((box) => { box.checked = selectAll.checked; });
        updateBulk();
      });
    }
    document.querySelectorAll("[data-stock-select]").forEach((box) => box.addEventListener("change", updateBulk));
    if (bulkForm) bulkForm.addEventListener("submit", (event) => {
      if (selected().length === 0) { event.preventDefault(); updateBulk(); return; }
      if (!window.confirm("Hapus stok terpilih? Tindakan ini tidak dapat dibatalkan.")) event.preventDefault();
    });
    updateBulk();

    document.querySelectorAll("form[data-confirm]").forEach((form) => {
      form.addEventListener("submit", (event) => {
        if (!window.confirm(form.dataset.confirm)) event.preventDefault();
      });
    });

    const tierList = document.querySelector("[data-tier-list]");
    const tierTemplate = document.getElementById("tier-row-template");
    if (tierList && tierTemplate) {
      let tierCounter = 0;
      const refreshTiers = () => {
        const rows = tierList.querySelectorAll("[data-tier-row]");
        rows.forEach((row) => {
          const remove = row.querySelector("[data-tier-remove]");
          if (remove) remove.hidden = rows.length <= 1;
        });
        const add = document.querySelector("[data-tier-add]");
        if (add) add.disabled = rows.length >= 5;
      };
      tierList.querySelectorAll("[data-tier-row]").forEach((row) => {
        const match = row.querySelector("input[name^='quantity_price_tiers.']");
        const index = match ? Number(match.name.split(".")[1]) : 0;
        tierCounter = Math.max(tierCounter, index + 1);
        const remove = row.querySelector("[data-tier-remove]");
        if (remove) remove.addEventListener("click", () => { row.remove(); refreshTiers(); });
      });
      document.querySelectorAll("[data-tier-add]").forEach((button) => button.addEventListener("click", () => {
        if (tierList.querySelectorAll("[data-tier-row]").length >= 5) return;
        const fragment = tierTemplate.content.cloneNode(true);
        fragment.querySelectorAll("input").forEach((input) => {
          input.name = input.name.replace(/__INDEX__/g, String(tierCounter));
          if (input.id) input.id = input.id.replace(/__INDEX__/g, String(tierCounter));
        });
        const row = fragment.querySelector("[data-tier-row]");
        const remove = row.querySelector("[data-tier-remove]");
        if (remove) remove.addEventListener("click", () => { row.remove(); refreshTiers(); });
        tierList.appendChild(fragment);
        tierCounter += 1;
        refreshTiers();
      }));
      refreshTiers();
    }

    const accountForm = document.querySelector("[data-account-form]");
    const accountTemplate = document.getElementById("account-row-template");
    if (accountForm && accountTemplate) {
      const accountList = accountForm.querySelector("[data-account-list]");
      let accountCounter = 0;
      const refreshAccounts = () => {
        const rows = accountList.querySelectorAll("[data-account-row]");
        rows.forEach((row, index) => {
          const legend = row.querySelector("legend");
          if (legend) legend.textContent = "Akun " + (index + 1);
          const remove = row.querySelector("[data-account-remove]");
          if (remove) remove.hidden = rows.length <= 1;
        });
        const add = accountForm.querySelector("[data-account-add]");
        if (add) add.disabled = rows.length >= 20;
      };
      accountList.querySelectorAll("[data-account-row]").forEach((row) => {
        const match = row.querySelector("input[name^='accounts.']");
        const index = match ? Number(match.name.split(".")[1]) : 0;
        accountCounter = Math.max(accountCounter, index + 1);
        const remove = row.querySelector("[data-account-remove]");
        if (remove) remove.addEventListener("click", () => { row.remove(); refreshAccounts(); });
      });
      const add = accountForm.querySelector("[data-account-add]");
      if (add) add.addEventListener("click", () => {
        if (accountList.querySelectorAll("[data-account-row]").length >= 20) return;
        const fragment = accountTemplate.content.cloneNode(true);
        fragment.querySelectorAll("input, select").forEach((input) => {
          if (input.name) input.name = input.name.replace(/__INDEX__/g, String(accountCounter));
          if (input.id) input.id = input.id.replace(/__INDEX__/g, String(accountCounter));
        });
        const row = fragment.querySelector("[data-account-row]");
        const remove = row.querySelector("[data-account-remove]");
        if (remove) remove.addEventListener("click", () => { row.remove(); refreshAccounts(); });
        accountList.appendChild(fragment);
        accountCounter += 1;
        refreshAccounts();
      });
      refreshAccounts();

      const reviewButton = accountForm.querySelector("[data-account-review]");
      const reviewResult = accountForm.querySelector("[data-account-review-result]");
      if (reviewButton) reviewButton.addEventListener("click", () => {
        const csrf = accountForm.querySelector("input[name=csrfmiddlewaretoken]");
        reviewResult.replaceChildren();
        reviewResult.textContent = "Memeriksa akun…";
        const endpoint = accountForm.action.replace(/\/stok\/akun$/, "/stok/akun/review");
        fetch(endpoint, {
          method: "POST",
          headers: {"Accept": "application/json", "X-CSRFToken": csrf ? csrf.value : ""},
          body: new FormData(accountForm),
          credentials: "same-origin",
        }).then((response) => response.json().then((body) => ({response, body}))).then(({response, body}) => {
          reviewResult.replaceChildren();
          if (!response.ok) {
            const messages = body && body.errors ? Object.values(body.errors).flat() : ["Review gagal."];
            const list = document.createElement("ul");
            messages.forEach((message) => { const item = document.createElement("li"); item.textContent = message; list.appendChild(item); });
            reviewResult.appendChild(list);
            return;
          }
          const review = body.review || body;
          const summary = document.createElement("p");
          summary.textContent = review.status === "duplicate"
            ? "Ditemukan identitas duplikat. Perbaiki sebelum menyimpan."
            : "Review server selesai: semua akun siap.";
          reviewResult.appendChild(summary);
          const list = document.createElement("ul");
          (review.accounts || []).forEach((account) => {
            const item = document.createElement("li");
            item.textContent = account.account_number + ". " + account.email_masked + " · " + account.access_method + (account.duplicate_status === "duplicate" ? " · duplikat" : " · clear");
            list.appendChild(item);
          });
          reviewResult.appendChild(list);
        }).catch(() => {
          reviewResult.replaceChildren();
          reviewResult.textContent = "Review server tidak tersedia. Coba lagi.";
        });
      });
    }
  });
})();
