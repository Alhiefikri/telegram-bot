<script>
document.querySelectorAll('[data-auth-form]').forEach(form => {
    form.addEventListener('submit', () => {
        form.querySelector('button[type="submit"], button:not([type])').disabled = true;
        form.querySelector('[data-auth-progress]').hidden = false;
        form.setAttribute('aria-busy', 'true');
    });
});
window.addEventListener('pageshow', () => {
    document.querySelectorAll('[data-auth-form]').forEach(form => {
        form.querySelector('button[type="submit"], button:not([type])').disabled = false;
        form.querySelector('[data-auth-progress]').hidden = true;
        form.removeAttribute('aria-busy');
    });
});
</script>
