(() => {
  const root = document.documentElement;
  const toggle = document.querySelector('[data-theme-toggle]');
  let saved = null;
  try { saved = localStorage.getItem('lhie-store-theme'); } catch (_) {}
  const setTheme = (dark) => {
    root.dataset.theme = dark ? 'dark' : 'light';
    root.dataset.themePreference = dark ? 'dark' : 'light';
    root.style.colorScheme = dark ? 'dark' : 'light';
    if (toggle) {
      toggle.setAttribute('aria-pressed', String(dark));
      const label = dark ? 'Tema terang' : 'Tema gelap';
      toggle.textContent = dark ? '☀' : '☾';
      toggle.setAttribute('aria-label', label);
      toggle.setAttribute('title', label);
    }
  };
  setTheme(saved === 'dark');
  if (toggle) {
    toggle.hidden = false;
    toggle.addEventListener('click', () => {
      const dark = root.dataset.theme !== 'dark';
      setTheme(dark);
      try { localStorage.setItem('lhie-store-theme', dark ? 'dark' : 'light'); } catch (_) {}
    });
  }
  const account = document.querySelector('[data-customer-identity]');
  if (account && window.fetch) {
    fetch('/akun/identitas', {credentials: 'same-origin', headers: {Accept: 'application/json'}})
      .then((response) => response.ok ? response.json() : null)
      .then((identity) => {
        if (identity && identity.authenticated && typeof identity.name === 'string') {
          account.textContent = identity.name;
          account.setAttribute('aria-label', `Akun ${identity.name}`);
        }
      })
      .catch(() => {});
  }
  document.querySelectorAll('.storefront-mobile-menu').forEach((menu) => {
    menu.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && menu.open) {
        menu.open = false;
        menu.querySelector('summary').focus();
      }
    });
  });
  document.querySelectorAll('.family').forEach((family) => {
    const options = Array.from(family.querySelectorAll('.plans > article'));
    const links = Array.from(family.querySelectorAll('nav a[href^="#plan-"]'));
    if (options.length < 2 || links.length !== options.length) return;
    const picker = links[0].closest('nav');
    picker.setAttribute('role', 'radiogroup');
    picker.setAttribute('aria-orientation', 'horizontal');
    links.forEach((link) => {
      link.setAttribute('role', 'radio');
      link.setAttribute('aria-controls', link.hash.slice(1));
    });
    const bar = document.createElement('div');
    bar.className = 'family-quick-buy';
    bar.setAttribute('aria-label', 'Pembelian cepat');
    const label = document.createElement('strong');
    const action = document.createElement('a');
    action.className = 'button';
    action.textContent = 'Tinjau pembelian';
    bar.append(label, action);
    family.append(bar);
    const show = (index) => {
      options.forEach((option, i) => { option.hidden = i !== index; });
      links.forEach((link, i) => {
        link.setAttribute('aria-checked', String(i === index));
        link.tabIndex = i === index ? 0 : -1;
      });
      const option = options[index];
      const buy = option.querySelector('a[href^="/shop/checkout/"]');
      label.textContent = `${option.querySelector('h3').textContent.trim()} · ${option.querySelector('.price').textContent.trim()}`;
      bar.hidden = !buy;
      if (buy) action.href = buy.href;
    };
    links.forEach((link, i) => link.addEventListener('click', (event) => {
      event.preventDefault();
      show(i);
    }));
    links.forEach((link, i) => link.addEventListener('keydown', (event) => {
      let next;
      if (event.key === ' ') {
        event.preventDefault();
        show(i);
        return;
      }
      if (event.key === 'ArrowRight' || event.key === 'ArrowDown') next = (i + 1) % links.length;
      else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') next = (i + links.length - 1) % links.length;
      else if (event.key === 'Home') next = 0;
      else if (event.key === 'End') next = links.length - 1;
      else return;
      event.preventDefault();
      show(next);
      links[next].focus();
    }));
    const selected = links.findIndex((link) => link.hash === window.location.hash);
    const firstAvailable = options.findIndex((option) => option.querySelector('a[href^="/shop/checkout/"]'));
    show(selected >= 0 ? selected : firstAvailable < 0 ? 0 : firstAvailable);
    window.addEventListener('hashchange', () => {
      const index = links.findIndex((link) => link.hash === window.location.hash);
      if (index >= 0) show(index);
    });
  });
  document.querySelectorAll('[data-zoom-image]').forEach((image) => {
    if (typeof HTMLDialogElement === 'undefined') return;
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'zoom-button';
    button.textContent = 'Perbesar gambar';
    const dialog = document.createElement('dialog');
    dialog.className = 'image-dialog';
    const close = document.createElement('button');
    close.type = 'button';
    close.textContent = 'Tutup';
    close.className = 'button secondary';
    const enlarged = image.cloneNode();
    dialog.append(close, enlarged);
    image.parentElement.append(button, dialog);
    button.addEventListener('click', () => dialog.showModal());
    close.addEventListener('click', () => dialog.close());
  });
})();
