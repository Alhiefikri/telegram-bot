# LhieStore Bot

This context describes the store domain language used by the Telegram bot, especially ordering, catalog access, and public sales-proof communication.

## Language

**Sales Proof Post**:
A public Telegram channel post that announces a successfully paid customer order using masked customer information.
_Avoid_: Testimonial, review

**Store Bot**:
The Telegram bot customers use to browse products and place orders.
_Avoid_: Channel

**Store Channel**:
The Telegram channel customers must join before using the Store Bot, and where sales proof and owner announcements are published.
_Avoid_: Community, group

**Community Gate**:
The access rule that blocks store flows unless the customer is currently a member of the Store Channel.
_Avoid_: One-time verification

**Paid Order Proof Event**:
The moment a public sales proof may be created: after payment is confirmed paid and the inventory sale has been recorded.
_Avoid_: Completed order event

**Available Product**:
A product that can currently be bought because it has available stock.
_Avoid_: In-stock item

**Unavailable Product**:
A product that remains visible in the catalog but cannot currently be bought because it has no available stock.
_Avoid_: Hidden product, deleted product
