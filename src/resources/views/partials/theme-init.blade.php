<script data-theme-init>
    (() => {
        const storageKey = 'lhie-store-theme';
        const storedTheme = (() => {
            try {
                const value = window.localStorage.getItem(storageKey);
                return value === 'light' || value === 'dark' ? value : 'light';
            } catch {
                return 'light';
            }
        })();
        const resolvedTheme = storedTheme;
        const root = document.documentElement;

        root.dataset.theme = resolvedTheme;
        root.dataset.themePreference = storedTheme;
        root.style.colorScheme = resolvedTheme;
    })();
</script>
