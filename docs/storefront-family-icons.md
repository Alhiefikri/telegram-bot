# Default web catalog family icons

The family filter reads `product_families.sidebar_icon_path`. The icon migration
fills empty slots for the listed families without replacing Admin uploads.
Change an icon in the Admin Dashboard's Product Family form. These icons do
not change Telegram keyboards or the cover images shown on product cards.

The WebP files in `src/public/storefront/family-icons/` use:

- Brand marks from [SVGL, pinned source](https://github.com/pheralb/svgl/tree/90f6de7026c5e75a9014dbf1f422792bd40b1802/static/library):
  Adobe, Apple Music, Canva, Claude, Gemini, Gmail, Lovable, Microsoft,
  OpenAI (ChatGPT and GPT API), Prime Video, and Spotify. GPT + Claude API
  combines the OpenAI and Claude marks.
- The [official CapCut website icon](https://sf16-web-tos-buz.capcutstatic.com/obj/capcut-web-buz-sg/common/images/lv_web-2.ico).
- Cropped family covers already used by Lhie Store: Duolingo, Muse AI,
  and China Models API. The last is a store bundle, not a single application.

The marks identify the listed apps and remain their owners' trademarks.
