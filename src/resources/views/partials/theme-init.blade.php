<script data-theme-init>
    (() => {
        const storageKey = 'lhie-store-theme';
        const storedTheme = (() => {
            try {
                const value = window.localStorage.getItem(storageKey);
                return value === 'light' || value === 'dark' ? value : 'system';
            } catch {
                return 'system';
            }
        })();
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        const resolvedTheme = storedTheme === 'system'
            ? (prefersDark ? 'dark' : 'light')
            : storedTheme;
        const root = document.documentElement;

        root.dataset.theme = resolvedTheme;
        root.dataset.themePreference = storedTheme;
        root.style.colorScheme = resolvedTheme;
    })();
</script>
