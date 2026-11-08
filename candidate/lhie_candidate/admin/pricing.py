"""Exact admin price/tier validation; never binary floats or implicit FX."""

import re

from lhie_candidate.domain.money import MAX_UNITS

from .validation import raw


# The existing forms bound every unit amount so quantity 100 stays in bigint.
MAX_PRICE = MAX_UNITS // 100
MAX_TIERS = 5
MINIMUM_TIER_QUANTITY = 2
MAXIMUM_TIER_QUANTITY = 20
_USDT_INPUT = re.compile(r"(?:0|[1-9][0-9]*)(?:\.([0-9]{1,3}))?\Z", re.ASCII)
_TIER_KEY = re.compile(
    r"quantity_price_tiers\.(\d+)\.(minimum_quantity|price_amount|usdt_price_amount)\Z",
    re.ASCII,
)


class PricingScheduleError(ValueError):
    def __init__(self, field, message):
        self.field, self.message = field, message
        super().__init__(message)


def parse_usdt_input(value):
    """Return micro-USDT for the form's 0..3 decimal representation."""
    if value is None:
        return None
    if not isinstance(value, str):
        raise ValueError("invalid")
    value = value.strip()
    if value == "":
        return None
    if "." in value:
        whole, fraction = value.split(".", 1)
        fraction = fraction.rstrip("0")
        value = whole if fraction == "" else whole + "." + fraction
    match = _USDT_INPUT.fullmatch(value)
    if match is None:
        raise ValueError("invalid")
    whole, fraction = value.split(".", 1) if "." in value else (value, "")
    return int(whole) * 1_000_000 + int(fraction.ljust(6, "0"))


def format_usdt(micro):
    if micro is None:
        return ""
    whole, fraction = divmod(int(micro), 1_000_000)
    return f"{whole}.{fraction:06d}".rstrip("0").rstrip(".")


def _bounded_integer(value, *, minimum, maximum):
    if isinstance(value, int) and not isinstance(value, bool):
        parsed = value
    elif isinstance(value, str) and value.strip().isdigit():
        parsed = int(value.strip())
    else:
        return None
    return parsed if minimum <= parsed <= maximum else None


def parse_base_prices(data, errors, *, required_idr=True):
    idr = _bounded_integer(raw(data, "price_amount"), minimum=0, maximum=MAX_PRICE)
    if idr is None:
        errors["price_amount"] = (
            ["Harga wajib diisi."] if raw(data, "price_amount") in (None, "")
            else [f"Harga jual IDR harus berupa angka 0 sampai {MAX_PRICE}."]
        )

    usdt = None
    raw_usdt = raw(data, "usdt_price_amount")
    if isinstance(raw_usdt, str) and raw_usdt.strip() != "":
        try:
            usdt = parse_usdt_input(raw_usdt)
        except ValueError:
            errors["usdt_price_amount"] = ["Harga USDT maksimal 3 desimal."]
        else:
            if usdt is not None and usdt > MAX_PRICE:
                errors["usdt_price_amount"] = [
                    "Harga USDT melebihi batas representasi untuk quantity 100."
                ]

    reseller_idr = None
    raw_reseller = raw(data, "reseller_price_amount")
    if isinstance(raw_reseller, str) and raw_reseller.strip() != "":
        reseller_idr = _bounded_integer(raw_reseller, minimum=1, maximum=MAX_PRICE)
        if reseller_idr is None:
            errors["reseller_price_amount"] = [
                f"Harga reseller IDR harus berupa angka 1 sampai {MAX_PRICE}."
            ]

    reseller_usdt = None
    raw_reseller_usdt = raw(data, "reseller_usdt_price_amount")
    if isinstance(raw_reseller_usdt, str) and raw_reseller_usdt.strip() != "":
        try:
            reseller_usdt = parse_usdt_input(raw_reseller_usdt)
        except ValueError:
            errors["reseller_usdt_price_amount"] = [
                "Harga reseller USDT maksimal 3 desimal."
            ]
        else:
            if reseller_usdt is not None and reseller_usdt > MAX_PRICE:
                errors["reseller_usdt_price_amount"] = [
                    "Harga reseller USDT melebihi batas representasi untuk quantity 100."
                ]
            elif reseller_usdt is not None and reseller_usdt < 1:
                errors["reseller_usdt_price_amount"] = [
                    "Harga reseller USDT harus lebih besar dari 0."
                ]

    if not any(key in errors for key in (
        "price_amount", "usdt_price_amount", "reseller_price_amount",
        "reseller_usdt_price_amount",
    )):
        try:
            validate_reseller(idr, usdt, reseller_idr, reseller_usdt)
        except PricingScheduleError as error:
            errors[error.field] = [error.message]
    return idr, usdt, reseller_idr, reseller_usdt


def validate_reseller(base_idr, base_usdt, reseller_idr, reseller_usdt):
    if reseller_idr is not None and reseller_idr <= 0:
        raise PricingScheduleError(
            "reseller_price_amount", "Harga reseller IDR harus lebih besar dari 0."
        )
    if reseller_usdt is None:
        return
    if reseller_usdt <= 0:
        raise PricingScheduleError(
            "reseller_usdt_price_amount", "Harga reseller USDT harus lebih besar dari 0."
        )
    if base_usdt is None or base_usdt <= 0:
        raise PricingScheduleError(
            "reseller_usdt_price_amount",
            "Harga reseller USDT hanya berlaku jika produk memiliki harga USDT dasar di atas 0.",
        )


def normalize_tier_rows(data, errors):
    """Read indexed tier fields; an entirely blank row is ignored."""
    rows = {}
    for key, value in (data.items() if hasattr(data, "items") else data):
        match = _TIER_KEY.fullmatch(key)
        if match is None:
            continue
        rows.setdefault(int(match.group(1)), {})[match.group(2)] = value
    if len(rows) > MAX_TIERS:
        errors["quantity_price_tiers"] = [
            f"Jadwal harga maksimal berisi {MAX_TIERS} tingkat."
        ]
        return []
    tiers = []
    for index in sorted(rows):
        row = rows[index]
        if not any(str(row.get(field, "")).strip() for field in (
            "minimum_quantity", "price_amount", "usdt_price_amount",
        )):
            continue
        prefix = f"quantity_price_tiers.{index}"
        minimum = _bounded_integer(row.get("minimum_quantity"),
                                   minimum=MINIMUM_TIER_QUANTITY,
                                   maximum=MAXIMUM_TIER_QUANTITY)
        if minimum is None:
            errors[prefix + ".minimum_quantity"] = [
                "Minimum quantity harus berupa angka 2 sampai 20."
            ]
        price = _bounded_integer(row.get("price_amount"), minimum=1, maximum=MAX_PRICE)
        if price is None:
            errors[prefix + ".price_amount"] = [
                f"Harga IDR tingkat harus berupa angka 1 sampai {MAX_PRICE}."
            ]
        usdt = None
        raw_usdt = row.get("usdt_price_amount")
        if isinstance(raw_usdt, str) and raw_usdt.strip() != "":
            try:
                usdt = parse_usdt_input(raw_usdt)
            except ValueError:
                errors[prefix + ".usdt_price_amount"] = [
                    "Harga USDT tingkat maksimal 3 desimal."
                ]
            else:
                if usdt is not None and usdt > MAX_PRICE:
                    errors[prefix + ".usdt_price_amount"] = [
                        "Harga USDT tingkat melebihi batas representasi untuk quantity 100."
                    ]
        tiers.append({"minimum_quantity": minimum, "price_amount": price,
                      "usdt_price_amount": usdt, "index": index})
    return tiers


def validate_schedule(base_idr, base_usdt, tiers):
    """PHP ProductQuantityPricing schedule rules; tier list is ordered by index."""
    if len(tiers) > MAX_TIERS:
        raise PricingScheduleError(
            "quantity_price_tiers", f"Jadwal harga maksimal berisi {MAX_TIERS} tingkat."
        )
    previous_minimum = None
    previous_idr = base_idr
    previous_usdt = base_usdt
    for tier in tiers:
        minimum = tier["minimum_quantity"]
        price = tier["price_amount"]
        usdt = tier["usdt_price_amount"]
        if (minimum is None or minimum < MINIMUM_TIER_QUANTITY
                or minimum > MAXIMUM_TIER_QUANTITY
                or (previous_minimum is not None and minimum <= previous_minimum)):
            raise PricingScheduleError(
                "quantity_price_tiers",
                "Minimum quantity harus unik dan berurutan naik antara 2 dan 20.",
            )
        if price is None or price <= 0 or price >= previous_idr:
            raise PricingScheduleError(
                "quantity_price_tiers",
                "Harga IDR setiap tingkat harus positif dan turun dari harga sebelumnya.",
            )
        if base_usdt is None:
            if usdt is not None:
                raise PricingScheduleError(
                    "quantity_price_tiers",
                    "Harga USDT tingkat harus dikosongkan jika produk tidak memiliki harga USDT dasar.",
                )
        elif usdt is None or usdt <= 0 or usdt >= previous_usdt:
            raise PricingScheduleError(
                "quantity_price_tiers",
                "Harga USDT setiap tingkat wajib lengkap, positif, dan turun dari harga sebelumnya.",
            )
        previous_minimum, previous_idr, previous_usdt = minimum, price, usdt
