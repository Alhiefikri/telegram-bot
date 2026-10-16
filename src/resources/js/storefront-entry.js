import Alpine from "alpinejs";
import { registerStorefrontComponents } from "./storefront/alpine-components";
import initAccountIdentity from "./storefront/account-identity.js";
import initApiDocs from "./storefront/api-docs.js";
import initCredentialSecrets from "./storefront/credential-secrets.js";
import initSupportChooser from "./storefront/support-chooser.js";
import initStorefront from "./storefront";
import initTheme from "./theme";

window.Alpine = Alpine;
registerStorefrontComponents(Alpine);
initTheme();
Alpine.start();
initStorefront();
initAccountIdentity();
initCredentialSecrets();
initSupportChooser();
initApiDocs();
