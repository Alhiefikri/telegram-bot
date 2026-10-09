const FOCUSABLE_SELECTOR = [
  "a[href]",
  "button:not([disabled])",
  'input:not([disabled]):not([type="hidden"])',
  "select:not([disabled])",
  "textarea:not([disabled])",
  '[tabindex]:not([tabindex="-1"])',
].join(",");

export default {
  modalFocusableElements(dialog) {
    return Array.from(
      dialog?.querySelectorAll(FOCUSABLE_SELECTOR) ?? []
    ).filter(
      (element) =>
        element.getClientRects().length > 0 &&
        element.getAttribute("aria-hidden") !== "true"
    );
  },

  trapModalFocus(event) {
    const focusable = this.modalFocusableElements(event.currentTarget);
    const first = focusable[0];
    const last = focusable.at(-1);
    if (!first || !last) {
      event.preventDefault();
      event.currentTarget.focus();
      return;
    }
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (
      !event.shiftKey &&
      (document.activeElement === last ||
        !focusable.includes(document.activeElement))
    ) {
      event.preventDefault();
      first.focus();
    }
  },

  keepModalFocus(event, modalId) {
    const dialog = document.getElementById(modalId);
    if (
      !dialog ||
      dialog.getClientRects().length === 0 ||
      dialog.contains(event.target)
    )
      return;
    (this.modalFocusableElements(dialog)[0] ?? dialog).focus();
  },
};
