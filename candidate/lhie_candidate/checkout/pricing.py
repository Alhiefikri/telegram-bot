from dataclasses import dataclass

from lhie_candidate.domain.money import require_integer
from .errors import CheckoutError


@dataclass(frozen=True, slots=True)
class PriceQuote:
    product_id: int
    quantity: int
    unit_price: int
    subtotal: int
    tier_minimum: int | None
    usdt_unit_price: int | None
    usdt_subtotal: int | None


@dataclass(frozen=True, slots=True)
class PricingSnapshot:
    quotes: tuple[PriceQuote, ...]
    idr_total: int
    usdt_total: int | None
    reseller: bool = False

    @classmethod
    def build(cls, items, products, tiers, *, reseller=False):
        quotes = []
        seen = set()
        for item in items:
            product_id = require_integer(item['product_id'], minimum=1)
            quantity = require_integer(item['quantity'], minimum=1)
            if product_id in seen or product_id not in products:
                raise CheckoutError('invalid_cart')
            seen.add(product_id)
            product = products[product_id]
            unit = require_integer(product['price_amount'])
            usd = product.get('usdt_price_amount')
            if usd is not None:
                require_integer(usd)
            schedule = tiers.get(product_id, ())
            if len(schedule) > 5:
                raise CheckoutError('invalid_pricing')
            previous_min, previous_idr, previous_usd = 1, unit, usd
            selected = None
            for tier in schedule:
                minimum = require_integer(tier['minimum_quantity'], minimum=2)
                price = require_integer(tier['price_amount'], minimum=1)
                dollar = tier['usdt_price_amount']
                if minimum > 20 or minimum <= previous_min or price >= previous_idr:
                    raise CheckoutError('invalid_pricing')
                if usd is None:
                    if dollar is not None:
                        raise CheckoutError('invalid_pricing')
                elif dollar is None or not 0 < require_integer(dollar) < previous_usd:
                    raise CheckoutError('invalid_pricing')
                if quantity >= minimum:
                    selected = tier
                previous_min, previous_idr, previous_usd = minimum, price, dollar
            tier_minimum = selected['minimum_quantity'] if selected else None
            effective_idr = selected['price_amount'] if selected else unit
            effective_usd = selected['usdt_price_amount'] if selected else usd
            if reseller and product.get('reseller_price_amount') is not None:
                effective_idr = require_integer(product['reseller_price_amount'], minimum=1)
                tier_minimum = None
            if reseller and usd is not None and usd > 0 and product.get('reseller_usdt_price_amount') is not None:
                effective_usd = require_integer(product['reseller_usdt_price_amount'], minimum=1)
            # Reject overflow in the base quote too, as the canonical pricing owner does.
            require_integer(unit * quantity)
            if usd is not None:
                require_integer(usd * quantity)
            quotes.append(PriceQuote(product_id, quantity, effective_idr,
                require_integer(effective_idr * quantity), tier_minimum, effective_usd,
                None if effective_usd is None else require_integer(effective_usd * quantity)))
        if not quotes:
            raise CheckoutError('empty_cart')
        quotes.sort(key=lambda quote: quote.product_id)
        total = require_integer(sum(quote.subtotal for quote in quotes))
        dollar_total = (None if any(quote.usdt_subtotal is None for quote in quotes)
                        else require_integer(sum(quote.usdt_subtotal for quote in quotes)))
        return cls(tuple(quotes), total, dollar_total, reseller)
