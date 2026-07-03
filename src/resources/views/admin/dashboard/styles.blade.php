<style>
    [x-cloak] { display: none !important; }

    body {
        background-color: #fff6cf;
        background-image:
            radial-gradient(circle at top left, rgba(123, 209, 10, 0.26), transparent 26%),
            radial-gradient(circle at top right, rgba(219, 63, 152, 0.18), transparent 22%),
            radial-gradient(circle at bottom center, rgba(22, 180, 202, 0.16), transparent 24%),
            repeating-linear-gradient(135deg, transparent, transparent 22px, rgba(5,5,5,0.03) 22px, rgba(5,5,5,0.03) 24px);
        min-height: 100vh;
        overflow-x: hidden;
    }

    .header-gradient { background: linear-gradient(135deg, #7BD10A 0%, #f2da3d 48%, #16b4ca 100%); }
    .desktop-header { background: linear-gradient(135deg, #ff4fa8 0%, #ff7cc3 100%); }
    .store-link-section { background: linear-gradient(135deg, #8758de 0%, #db3f98 100%); }
    .header-glow { background: radial-gradient(circle at center, rgba(255,255,255,0.85) 0, rgba(255,255,255,0.15) 42%, transparent 70%); }

    .bottom-nav { background: rgba(5, 5, 5, 0.92); backdrop-filter: blur(14px); }
    .desktop-tab-nav { display: none; }
    .mobile-bottom-nav { display: block; }
    .admin-mini-shell { width: 100%; max-width: min(430px, calc(100vw - 24px)); margin-inline: auto; }

    @media (min-width: 768px) {
        body {
            background-color: #fff4bd;
            background-image:
                radial-gradient(circle at top left, rgba(255, 79, 168, 0.18), transparent 28%),
                radial-gradient(circle at top right, rgba(123, 209, 10, 0.16), transparent 22%),
                radial-gradient(circle at bottom center, rgba(22, 180, 202, 0.12), transparent 24%);
        }
        .desktop-tab-nav { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 0.5rem; }
        .mobile-bottom-nav { display: none; }
        .admin-mini-shell { max-width: min(820px, calc(100vw - 48px)); }
    }

    .bottom-nav-active { background: #f2da3d; border-radius: 999px; color: #050505; box-shadow: 4px 4px 0px #050505; }

    .filter-pill { display: inline-flex; align-items: center; gap: 6px; padding: 9px 14px; border: 3px solid #050505; border-radius: 999px; font-family: 'Space Grotesk', sans-serif; font-weight: 800; font-size: 11px; text-transform: uppercase; cursor: pointer; transition: background-color 0.15s, color 0.15s, transform 0.15s; background: #fff; color: #050505; box-shadow: 3px 3px 0px #050505; }
    .filter-pill-active { background: #f2da3d; color: #050505; transform: translateY(-1px); }
    .filter-pill-count { display: inline-flex; align-items: center; justify-content: center; min-width: 20px; height: 20px; padding: 0 6px; border-radius: 50px; font-size: 10px; font-weight: 700; background: rgba(0,0,0,0.1); color: inherit; }
    .filter-pill-active .filter-pill-count { background: rgba(255,255,255,0.2); }

    .earnings-bar-track { height: 12px; background: #f1f5f9; border: 3px solid #050505; border-radius: 6px; overflow: hidden; }
    .earnings-bar-fill { height: 100%; background: linear-gradient(90deg, #8758de, #db3f98); border-radius: 4px; }
    @keyframes slideUp { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
    .animate-slide-up { animation: slideUp 0.4s cubic-bezier(0.22, 1, 0.36, 1) both; }
</style>
