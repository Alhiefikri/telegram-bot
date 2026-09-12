# Taste — UI / presentation

- Prefers compact list layouts with no wasted whitespace: entries should sit close together in a tight, scannable block ("jangan dibuat space terlalu lebar"), but with a small deliberate gap so they are not fully glued together ("berdempet tapi jangan terlalu dempet") — wide, airy spacing between rows reads as a defect. Confidence: 0.7
- Expects unavailable/sold-out items to be visually and functionally unambiguous: the action button turns red and is not clickable, and the "Sold out" label is paired with a red icon (e.g. a red ✕) right next to the text. Confidence: 0.7
- Drives UI review by pasting the current rendered output (e.g. the Telegram message text as the user sees it) and asking for a redesign with the desired state described in prose, rather than pointing at code or naming components. Confidence: 0.55
- Wants visual consistency across screens: an existing presentation treatment should be reused for equivalent content elsewhere, pointed out by naming the reference treatment ("di bungkus quote sama seperti product details") rather than describing the styling from scratch. Confidence: 0.5
- Sets explicit, numeric minimums for paginated lists instead of leaving page size to the implementer (product list pages must show at least 15 items). Confidence: 0.55
