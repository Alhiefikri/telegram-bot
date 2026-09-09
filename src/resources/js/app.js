import Alpine from "alpinejs";
import adminDashboard, { salesFilter } from "./admin-dashboard";
import initStorefront from "./storefront";

window.Alpine = Alpine;
Alpine.data("adminDashboard", adminDashboard);
Alpine.data("salesFilter", salesFilter);
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
initStorefront();
