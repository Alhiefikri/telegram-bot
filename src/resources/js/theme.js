const THEME_STORAGE_KEY = "lhie-store-theme";
const VALID_THEMES = new Set(["light", "dark"]);

function readStoredTheme() {
  try {
    const value = window.localStorage.getItem(THEME_STORAGE_KEY);
    return VALID_THEMES.has(value) ? value : "light";
  } catch {
    return "light";
  }
}

export function applyTheme(preference = readStoredTheme()) {
  const resolvedTheme = VALID_THEMES.has(preference) ? preference : "light";
  const root = document.documentElement;

  root.dataset.theme = resolvedTheme;
  root.dataset.themePreference = preference;
  root.style.colorScheme = resolvedTheme;

  return resolvedTheme;
}

function writeStoredTheme(theme) {
  try {
    window.localStorage.setItem(THEME_STORAGE_KEY, theme);
  } catch {
    // Private browsing and blocked storage should not disable the toggle.
  }
}

function updateToggle(toggle, resolvedTheme) {
  const isDark = resolvedTheme === "dark";
  const nextLabel = isDark ? "Aktifkan mode terang" : "Aktifkan mode gelap";

  toggle.setAttribute("aria-label", nextLabel);
  toggle.setAttribute("title", nextLabel);
  toggle.setAttribute("aria-pressed", String(isDark));

  const label = toggle.querySelector("[data-theme-label]");
  if (label) {
    label.textContent = isDark ? "Mode terang" : "Mode gelap";
  }
}

export default function initTheme() {
  const toggles = Array.from(document.querySelectorAll("[data-theme-toggle]"));
  let preference = readStoredTheme();
  let resolvedTheme = applyTheme(preference);

  const syncToggles = () => {
    toggles.forEach((toggle) => updateToggle(toggle, resolvedTheme));
  };

  const setTheme = (nextPreference) => {
    preference = nextPreference;
    writeStoredTheme(preference);
    resolvedTheme = applyTheme(preference);
    syncToggles();
  };

  toggles.forEach((toggle) => {
    toggle.addEventListener("click", () => {
      setTheme(resolvedTheme === "dark" ? "light" : "dark");
    });
    updateToggle(toggle, resolvedTheme);
  });

  window.addEventListener("storage", (event) => {
    if (event.key !== THEME_STORAGE_KEY) {
      return;
    }

    preference = VALID_THEMES.has(event.newValue) ? event.newValue : "light";
    resolvedTheme = applyTheme(preference);
    syncToggles();
  });
}
