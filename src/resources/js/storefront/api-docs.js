/**
 * Enhancements for the public API documentation page.
 *
 * Every explanation, endpoint table and code sample is already in the initial
 * HTML, so the page stays readable and copyable without JavaScript. This module
 * only reveals the optional controls (language focus, example language, filter,
 * copy buttons) and switches the `data-api-docs-*` attributes that the scoped
 * CSS uses to focus one language or one sample per operation.
 */

function initApiDocs() {
  const root = document.querySelector("[data-api-docs]");

  if (!root) {
    return;
  }

  revealCopyButtons(root);
  initChoice(
    root,
    "lang",
    "[data-api-docs-lang-control]",
    "[data-api-docs-lang-button]"
  );
  initChoice(
    root,
    "example",
    "[data-api-docs-example-control]",
    "[data-api-docs-example-button]"
  );
  initFilter(root);
}

function revealCopyButtons(root) {
  root.querySelectorAll("[data-api-docs-copy]").forEach((button) => {
    button.hidden = false;
  });
}

/**
 * Turns a group of `aria-pressed` buttons into a single-choice control whose
 * value is published as `data-api-docs-<name>` on the documentation root.
 *
 * @param {"lang"|"example"} name attribute suffix on the root element
 */
function initChoice(root, name, controlSelector, buttonSelector) {
  const control = root.querySelector(controlSelector);

  if (!control) {
    return;
  }

  const attribute = name === "lang" ? "apiDocsLang" : "apiDocsExample";
  const buttons = Array.from(control.querySelectorAll(buttonSelector));
  const apply = (value) => {
    if (value === "all") {
      delete root.dataset[attribute];
    } else {
      root.dataset[attribute] = value;
    }

    buttons.forEach((button) => {
      button.setAttribute(
        "aria-pressed",
        button.dataset[buttonSelectorKey(name)] === value ? "true" : "false"
      );
    });
  };

  buttons.forEach((button) => {
    button.addEventListener("click", () =>
      apply(button.dataset[buttonSelectorKey(name)])
    );
  });

  control.hidden = false;
}

function buttonSelectorKey(name) {
  return name === "lang" ? "apiDocsLangButton" : "apiDocsExampleButton";
}

function initFilter(root) {
  const input = root.querySelector("[data-api-docs-filter]");
  const empty = root.querySelector("[data-api-docs-empty]");

  if (!input) {
    return;
  }

  const items = Array.from(
    root.querySelectorAll("[data-api-docs-search-item]")
  );
  input.hidden = false;

  input.addEventListener("input", () => {
    const needle = input.value.trim().toLowerCase();
    let matches = 0;

    items.forEach((item) => {
      const haystack = (
        item.getAttribute("data-api-docs-search") || ""
      ).toLowerCase();
      const match = needle === "" || haystack.includes(needle);
      item.hidden = !match;

      if (match) {
        matches += 1;
      }
    });

    if (empty) {
      empty.hidden = matches !== 0;
    }
  });
}

export default initApiDocs;
