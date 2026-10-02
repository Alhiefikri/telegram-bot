import Alpine from "alpinejs";
import adminDashboard, { salesFilter } from "./admin-dashboard";
import supplierPricingCalculator from "./admin/suppliers/pricing-calculator";
import supplierSellFlow from "./admin/suppliers/sell-flow";
import initTheme from "./theme";

window.Alpine = Alpine;
initTheme();
Alpine.data("adminDashboard", adminDashboard);
Alpine.data("salesFilter", salesFilter);
Alpine.data("supplierSellFlow", supplierSellFlow);
Alpine.data("supplierPricingCalculator", supplierPricingCalculator);
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
