-- Explicit P25 disposable provisioning only. No startup installation or money writes.
GRANT INSERT (sent_at,unknown_at) ON public.telegram_outbound_effects TO candidate_runtime;
GRANT SELECT ON public.telegram_broadcasts, public.telegram_broadcast_recipients,
    public.stock_notification_events, public.stock_notification_recipients,
    public.product_price_change_events, public.product_price_change_notification_recipients,
    public.order_notification_events TO candidate_runtime;
GRANT SELECT (notification_email) ON public.orders TO candidate_runtime;
GRANT SELECT (api_order_claim_id) ON public.orders TO candidate_runtime;
GRANT SELECT (id,telegram_id,first_name,username,locale) ON public.customers TO candidate_runtime;
GRANT SELECT (payment_proof_file_id,payment_proof_admin_chat_id,payment_proof_submitted_at,
    payment_proof_forwarded_at) ON public.payments TO candidate_runtime;
GRANT UPDATE (payment_proof_forwarded_at) ON public.payments TO candidate_runtime;
GRANT INSERT (broadcast_id,customer_id,telegram_id,locale,created_at,updated_at)
    ON public.telegram_broadcast_recipients TO candidate_runtime;
GRANT USAGE, SELECT ON SEQUENCE public.telegram_broadcast_recipients_id_seq,
    public.order_notification_events_id_seq TO candidate_runtime;
GRANT UPDATE (recipient_count,sent_count,failed_count,pending_count,terminal_failed_count,status,
    completed_at,updated_at) ON public.telegram_broadcasts TO candidate_runtime;
GRANT UPDATE (sent_at,failed_at,terminal_failed_at,retry_at,last_error,attempts,updated_at)
    ON public.telegram_broadcast_recipients TO candidate_runtime;
GRANT UPDATE (sent_at,failed_at,terminal_failed_at,last_error,attempts,updated_at)
    ON public.stock_notification_recipients TO candidate_runtime;
GRANT UPDATE (sent_at,failed_at,last_error,attempts,updated_at)
    ON public.product_price_change_notification_recipients TO candidate_runtime;
GRANT INSERT (order_id,event_type,recipient_email,status,attempts,next_attempt_at,created_at,updated_at)
    ON public.order_notification_events TO candidate_runtime;
GRANT UPDATE (status,attempts,delivery_started_at,next_attempt_at,sent_at,failed_at,last_error,updated_at)
    ON public.order_notification_events TO candidate_runtime;
GRANT SELECT ON public.products, public.product_families, public.product_quantity_price_tiers,
    public.inventory_movements, public.store_channel_settings, public.telegram_ui_icons,
    public.product_waitlist_subscriptions, public.product_waitlist_notifications,
    public.product_waitlist_events, public.sales_proof_posts,
    public.store_balance_top_ups, public.store_balance_compensations,
    public.balance_adjustment_requests, public.supplier_health_events,
    public.supplier_health_states, public.supplier_health_reviews, public.supplier_health_scans,
    public.suppliers, public.supplier_fulfillments, public.supplier_purchases,
    public.supplier_account_units, public.supplier_catalog_items,
    public.supplier_manual_handoffs, public.audit_logs TO candidate_runtime;
GRANT SELECT (id,email,name,password,role) ON public.users TO candidate_runtime;
GRANT SELECT (id,is_active,deleted_at) ON public.categories TO candidate_runtime;
GRANT SELECT (product_id,supplier_purchase_id,order_item_id,retired_at)
    ON public.product_stock_items TO candidate_runtime;
GRANT SELECT (base_amount_micro,payment_id) ON public.payment_bsc_details TO candidate_runtime;
GRANT INSERT (broadcast_key,source,message,photo_file_id,reply_markup,status,created_at,updated_at)
    ON public.telegram_broadcasts TO candidate_runtime;
GRANT INSERT (inventory_movement_id,kind,created_at,updated_at)
    ON public.stock_notification_events TO candidate_runtime;
GRANT INSERT (stock_notification_event_id,customer_id,recipient_type,telegram_id,locale,
    text,reply_markup,photo_path,created_at,updated_at)
    ON public.stock_notification_recipients TO candidate_runtime;
GRANT INSERT (event_id,customer_id,telegram_id,locale,created_at,updated_at)
    ON public.product_price_change_notification_recipients TO candidate_runtime;
GRANT INSERT (product_id,email,email_hash,status,confirmation_expires_at,created_at,updated_at)
    ON public.product_waitlist_subscriptions TO candidate_runtime;
GRANT INSERT (inventory_movement_id,product_id,available_stock,status,created_at,updated_at)
    ON public.product_waitlist_events TO candidate_runtime;
GRANT INSERT (product_waitlist_event_id,subscription_id,status,created_at,updated_at)
    ON public.product_waitlist_notifications TO candidate_runtime;
GRANT INSERT (order_id,status,attempts,created_at,updated_at) ON public.sales_proof_posts TO candidate_runtime;
GRANT INSERT (id,supplier_id,message,created_at) ON public.supplier_health_events TO candidate_runtime;
GRANT INSERT (supplier_id,low_active,error_codes,balance_stale_active,error_recovered_at)
    ON public.supplier_health_states TO candidate_runtime;
GRANT INSERT (supplier_fulfillment_id,active_identities) ON public.supplier_health_reviews TO candidate_runtime;
GRANT UPDATE (recipients_materialized_at) ON public.telegram_broadcasts TO candidate_runtime;
GRANT UPDATE (recipients_snapshotted_at,tier_promotion_snapshotted_at,promoted_tier_minimum,
    promoted_tier_price_amount,promoted_tier_usdt_price_amount,updated_at)
    ON public.stock_notification_events TO candidate_runtime;
GRANT UPDATE (stock_notification_sent_at) ON public.inventory_movements TO candidate_runtime;
GRANT UPDATE (recipients_materialized_at,store_channel_sent_at,store_channel_message_id,
    store_channel_failed_at,store_channel_last_error,store_channel_attempts,updated_at)
    ON public.product_price_change_events TO candidate_runtime;
GRANT UPDATE (email,status,confirmation_expires_at,confirmation_sent_at,confirmation_delivery_started_at,
    confirmation_attempts,confirmation_last_error,confirmed_at,unsubscribed_at,notified_at,updated_at)
    ON public.product_waitlist_subscriptions TO candidate_runtime;
GRANT UPDATE (materialization_cursor,status,materialized_at,skipped_at,updated_at)
    ON public.product_waitlist_events TO candidate_runtime;
GRANT UPDATE (status,sent_at,delivery_started_at,failed_at,next_attempt_at,attempts,last_error,updated_at)
    ON public.product_waitlist_notifications TO candidate_runtime;
GRANT UPDATE (status,attempts,telegram_message_id,last_error,sent_at,updated_at)
    ON public.sales_proof_posts TO candidate_runtime;
GRANT UPDATE (telegram_method,sequence,supersedes_effect_id) ON public.telegram_outbound_effects TO candidate_runtime;
GRANT UPDATE (recipient_email) ON public.order_notification_events TO candidate_runtime;
GRANT UPDATE (admin_pending_order_notified_at,admin_paid_order_notified_at,admin_expired_order_notified_at,
    admin_pending_order_notification_chat_id,admin_pending_order_notification_message_id,updated_at)
    ON public.orders TO candidate_runtime;
GRANT UPDATE (payment_proof_admin_chat_id) ON public.payments TO candidate_runtime;
GRANT UPDATE (payment_proof_forwarded_at,updated_at) ON public.store_balance_top_ups TO candidate_runtime;
GRANT UPDATE (low_active,error_codes,balance_stale_active,error_recovered_at) ON public.supplier_health_states TO candidate_runtime;
GRANT UPDATE (active_identities) ON public.supplier_health_reviews TO candidate_runtime;
GRANT UPDATE (supplier_cursor,fulfillment_cursor) ON public.supplier_health_scans TO candidate_runtime;
GRANT UPDATE (low_balance_alerted_at) ON public.suppliers TO candidate_runtime;
GRANT USAGE, SELECT ON SEQUENCE public.telegram_broadcasts_id_seq, public.stock_notification_events_id_seq,
    public.stock_notification_recipients_id_seq, public.product_price_change_notification_recipients_id_seq,
    public.product_waitlist_subscriptions_id_seq, public.product_waitlist_events_id_seq,
    public.product_waitlist_notifications_id_seq, public.sales_proof_posts_id_seq TO candidate_runtime;
GRANT UPDATE (updated_at) ON public.users TO candidate_runtime;
