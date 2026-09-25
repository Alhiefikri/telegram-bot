import checkoutReview from "./checkout-review.js";
import familyQuickBuy from "./family-quick-buy.js";
import mobileMenu from "./mobile-menu.js";

export function registerStorefrontComponents(Alpine) {
  Alpine.data("familyQuickBuy", familyQuickBuy);
  Alpine.data("checkoutReview", checkoutReview);
  Alpine.data("mobileMenu", mobileMenu);
}
