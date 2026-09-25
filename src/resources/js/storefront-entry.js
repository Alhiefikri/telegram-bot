import Alpine from "alpinejs";
import { registerStorefrontComponents } from "./storefront/alpine-components";
import initStorefront from "./storefront";
import initTheme from "./theme";

window.Alpine = Alpine;
registerStorefrontComponents(Alpine);
initTheme();
Alpine.start();
initStorefront();
