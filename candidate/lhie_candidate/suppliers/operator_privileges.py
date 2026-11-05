"""Verify a separately provisioned supplier operator connection; never grant privileges."""

from django.db import connections


INSERT = {
    'suppliers': set('name provider api_key currency currency_confirmed exchange_rate_idr is_active low_balance_threshold_micros version created_at updated_at'.split()),
    'supplier_catalog_items': set('supplier_id external_id name description currency unit_price_micros stock orderable requires_input price_tiers bulk_discounts synced_at created_at updated_at'.split()),
    'product_supplier_sources': set('product_id supplier_catalog_item_id priority is_active version created_at updated_at'.split()),
    'supplier_price_policies': set('product_id mode markup_basis_points profit_idr minimum_profit_idr rounding_idr version created_at updated_at'.split()),
    'supplier_currency_rates': set('currency exchange_rate_idr version created_at updated_at'.split()),
    'products': set('name description price_amount currency stock reserved_stock is_active created_at updated_at'.split()),
    'audit_logs': set('user_id action subject_type subject_id new_values created_at'.split()),
    'inventory_movements': set('product_id type quantity stock_before available_before available_after became_unavailable notes created_at'.split()),
}
UPDATE = {
    'suppliers': set('name provider api_key currency currency_confirmed exchange_rate_idr is_active low_balance_threshold_micros version balance_micros balance_observed_at sync_token sync_attempted_at sync_lease_expires_at synced_at sync_expires_at sync_failures sync_next_attempt_at last_error updated_at'.split()),
    'supplier_catalog_items': set('name description currency unit_price_micros stock orderable requires_input price_tiers bulk_discounts synced_at updated_at'.split()),
    'product_supplier_sources': set('priority is_active version updated_at'.split()),
    'supplier_price_policies': set('mode markup_basis_points profit_idr minimum_profit_idr rounding_idr version updated_at'.split()),
    'supplier_currency_rates': set('exchange_rate_idr version updated_at'.split()),
    'products': set('supplier_available_stock supplier_available_until price_amount updated_at'.split()),
}
DELETE = {'product_supplier_sources', 'supplier_catalog_items', 'suppliers'}
SELECT_TABLES = {'products', 'suppliers', 'supplier_catalog_items', 'product_supplier_sources',
                 'supplier_price_policies', 'supplier_currency_rates', 'product_quantity_price_tiers'}
SELECT_COLUMNS = {
    'users': {'id', 'role'},
    'product_stock_items': set('id product_id supplier_purchase_id order_item_id retired_at'.split()),
    'order_items': {'id', 'product_id'},
    'supplier_fulfillments': set('id order_item_id sources_snapshot reserved_supplier_id reserved_catalog_item_id reserved_quantity'.split()),
    'supplier_account_units': {'supplier_id'},
    'supplier_webhook_events': {'supplier_id'},
    'supplier_deposits': {'supplier_id', 'state'},
    'supplier_purchases': {'supplier_id', 'supplier_catalog_item_id', 'supplier_fulfillment_id', 'state', 'committed_at'},
}
SEQUENCES = {'suppliers_id_seq', 'supplier_catalog_items_id_seq', 'product_supplier_sources_id_seq',
             'supplier_price_policies_id_seq', 'products_id_seq', 'audit_logs_id_seq', 'inventory_movements_id_seq'}


class OperatorPrivilegeError(RuntimeError):
    pass


def verify_operator_privileges(*, using, role):
    """Fail closed unless the alias is a directly connected, narrowly granted role.

    Provision operator_grants.sql separately, substituting the role name when needed.
    This check is for operator-only startup, not the normal runtime connection.
    """
    db = connections[using]
    if db.vendor != 'postgresql' or not isinstance(role, str) or not role:
        raise OperatorPrivilegeError('a PostgreSQL operator alias and role are required')
    with db.cursor() as cursor:
        cursor.execute('''SELECT current_user, session_user, r.rolsuper, r.rolbypassrls,
            has_schema_privilege(current_user, 'public', 'USAGE'),
            has_schema_privilege(current_user, 'public', 'CREATE'),
            EXISTS (SELECT 1 FROM pg_catalog.pg_roles m WHERE m.oid <> r.oid
                    AND pg_has_role(current_user, m.oid, 'MEMBER'))
            FROM pg_catalog.pg_roles r WHERE r.rolname = current_user''')
        identity = cursor.fetchone()
        if not identity or identity != (role, role, False, False, True, False, False):
            raise OperatorPrivilegeError('unsafe operator role identity or schema privileges')
        cursor.execute('''SELECT c.relname, c.relkind,
            has_table_privilege(current_user,c.oid,'INSERT'),
            has_table_privilege(current_user,c.oid,'UPDATE'),
            has_table_privilege(current_user,c.oid,'DELETE'),
            has_table_privilege(current_user,c.oid,'TRUNCATE,TRIGGER,REFERENCES'),
            c.relowner = (SELECT oid FROM pg_catalog.pg_roles WHERE rolname=current_user),
            has_table_privilege(current_user,c.oid,'INSERT WITH GRANT OPTION,UPDATE WITH GRANT OPTION,DELETE WITH GRANT OPTION,SELECT WITH GRANT OPTION'),
            has_table_privilege(current_user,c.oid,'SELECT')
            FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
            WHERE n.nspname='public' AND c.relkind IN ('r','p')''')
        tables = cursor.fetchall()
        seen_tables = {row[0] for row in tables}
        if not (SELECT_TABLES | set(SELECT_COLUMNS) | set(INSERT) | set(UPDATE) | DELETE) <= seen_tables:
            raise OperatorPrivilegeError('operator schema is incomplete')
        for table, _, insert, update, delete, other, owner, grantable, select in tables:
            if insert or update or other or owner or grantable or (delete != (table in DELETE)):
                raise OperatorPrivilegeError('unsafe operator table privileges: ' + table)
            if select != (table in SELECT_TABLES):
                raise OperatorPrivilegeError('unsafe or missing operator SELECT: ' + table)
        cursor.execute('''SELECT c.relname, a.attname,
            has_column_privilege(current_user,c.oid,a.attnum,'SELECT'),
            has_column_privilege(current_user,c.oid,a.attnum,'INSERT'),
            has_column_privilege(current_user,c.oid,a.attnum,'UPDATE'),
            has_column_privilege(current_user,c.oid,a.attnum,'INSERT WITH GRANT OPTION,UPDATE WITH GRANT OPTION,SELECT WITH GRANT OPTION')
            FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
            JOIN pg_catalog.pg_attribute a ON a.attrelid=c.oid
            WHERE n.nspname='public' AND c.relkind IN ('r','p') AND a.attnum>0 AND NOT a.attisdropped''')
        observed = set()
        for table, column, select, insert, update, grantable in cursor.fetchall():
            observed.add((table, column))
            if (grantable or insert != (column in INSERT.get(table, ()))
                    or update != (column in UPDATE.get(table, ()))
                    or select != (table in SELECT_TABLES or column in SELECT_COLUMNS.get(table, ()))):
                raise OperatorPrivilegeError('unsafe or missing operator column privileges: ' + table + '.' + column)
        expected = {(table, column) for mapping in (INSERT, UPDATE, SELECT_COLUMNS)
                    for table, columns in mapping.items() for column in columns}
        if not expected <= observed:
            raise OperatorPrivilegeError('operator schema columns are incomplete')
        cursor.execute('''SELECT c.relname,
            has_sequence_privilege(current_user,c.oid,'USAGE'),
            has_sequence_privilege(current_user,c.oid,'UPDATE'),
            has_sequence_privilege(current_user,c.oid,'USAGE WITH GRANT OPTION,UPDATE WITH GRANT OPTION'),
            c.relowner = (SELECT oid FROM pg_catalog.pg_roles WHERE rolname=current_user)
            FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
            WHERE n.nspname='public' AND c.relkind='S' ''')
        sequences = cursor.fetchall()
        if not SEQUENCES <= {row[0] for row in sequences}:
            raise OperatorPrivilegeError('operator sequences are missing')
        for sequence, usage, update, grantable, owner in sequences:
            if usage != (sequence in SEQUENCES) or update or grantable or owner:
                raise OperatorPrivilegeError('unsafe or missing operator sequence privileges: ' + sequence)
    return True
