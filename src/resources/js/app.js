import Alpine from "alpinejs";
import adminDashboard, { salesFilter, salesChart } from "./admin-dashboard";
import initTheme from "./theme";
import supplierConnectionForm from "./supplier-connection-form";
import supplierCurrencyRates from "./supplier-currency-rates";
import dashboardNavigation from "./admin-dashboard/navigation";
import modalFocus from "./admin-dashboard/modal-focus";
import productWorkspace, { workspaceStock } from "./product-workspace.js";
import productCatalog from "./admin-dashboard/product-catalog.js";
import "../css/product-workspace.css";

window.Alpine = Alpine;
initTheme();
Alpine.data("adminDashboard", adminDashboard);
Alpine.data("productWorkspace", productWorkspace);
Alpine.data("workspaceStock", workspaceStock);
Alpine.data("productCatalog", productCatalog);
Alpine.data("dashboardNavigation", () => ({
  ...modalFocus,
  ...dashboardNavigation(),
  init() {
    this.initNavigation();
  },
  destroy() {
    this.destroyNavigation();
  },
}));
Alpine.data("salesFilter", salesFilter);
Alpine.data("salesChart", salesChart);
Alpine.data("supplierConnectionForm", supplierConnectionForm);
Alpine.data("supplierCurrencyRates", supplierCurrencyRates);
Alpine.data("passwordReveal", () => ({
  showPassword: false,
  get buttonText() {
    return this.showPassword ? "Sembunyikan" : "Lihat";
  },
  get buttonLabel() {
    return this.showPassword ? "Sembunyikan password" : "Lihat password";
  },
  toggle() {
    this.showPassword = !this.showPassword;
    this.syncPasswordFieldType();
  },
  syncPasswordFieldType() {
    if (this.$refs.password) {
      this.$refs.password.type = this.showPassword ? "text" : "password";
    }
  },
}));
Alpine.start();
