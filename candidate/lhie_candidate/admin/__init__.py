"""P22 admin shell and catalog/stock workspace.

The module is intentionally framework-thin: HTML pages delegate to the
catalog, pricing and stock services below the same transaction boundaries used
by the canonical PHP owners. Django admin and framework auth are not installed.
"""
