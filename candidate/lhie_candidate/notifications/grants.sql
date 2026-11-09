-- Explicit P25 disposable provisioning only. No startup installation or money writes.
GRANT INSERT (sent_at,unknown_at) ON public.telegram_outbound_effects TO candidate_runtime;
GRANT INSERT (telegram_message_id) ON public.telegram_outbound_effects TO candidate_runtime;
GRANT UPDATE (telegram_method) ON public.telegram_outbound_effects TO candidate_runtime;
GRANT SELECT (telegram_message_deleted_at,telegram_message_deletion_started_at,telegram_message_deletion_token)
    ON public.telegram_outbound_effects TO candidate_runtime;
GRANT UPDATE (telegram_message_deleted_at,telegram_message_deletion_started_at,telegram_message_deletion_token)
    ON public.telegram_outbound_effects TO candidate_runtime;
GRANT SELECT ON public.sales_proof_posts TO candidate_runtime;
GRANT SELECT (telegram_channel_image_path,image_path) ON public.products TO candidate_runtime;
GRANT SELECT (image_path) ON public.product_families TO candidate_runtime;
GRANT SELECT (singleton_key,sales_proof_enabled,telegram_chat_id,channel_username,last_test_success)
    ON public.store_channel_settings TO candidate_runtime;
GRANT INSERT (order_id,status,created_at,updated_at) ON public.sales_proof_posts TO candidate_runtime;
GRANT UPDATE (status,telegram_message_id,sent_at,attempts,last_error,updated_at)
    ON public.sales_proof_posts TO candidate_runtime;
GRANT USAGE, SELECT ON SEQUENCE public.sales_proof_posts_id_seq TO candidate_runtime;
GRANT SELECT ON public.telegram_broadcasts, public.telegram_broadcast_recipients,
    public.stock_notification_events, public.stock_notification_recipients,
    public.product_price_change_events, public.product_price_change_notification_recipients,
    public.order_notification_events TO candidate_runtime;
GRANT SELECT (notification_email) ON public.orders TO candidate_runtime;
GRANT SELECT (api_order_claim_id) ON public.orders TO candidate_runtime;
GRANT SELECT (admin_pending_order_notified_at,admin_paid_order_notified_at,admin_expired_order_notified_at,
    admin_pending_order_notification_chat_id,admin_pending_order_notification_message_id,
    admin_pending_order_notification_deleted_at) ON public.orders TO candidate_runtime;
GRANT UPDATE (admin_pending_order_notified_at,admin_paid_order_notified_at,admin_expired_order_notified_at,
    admin_pending_order_notification_chat_id,admin_pending_order_notification_message_id,
    admin_pending_order_notification_deleted_at) ON public.orders TO candidate_runtime;
GRANT SELECT (name) ON public.suppliers TO candidate_runtime;
GRANT SELECT (id,message) ON public.supplier_health_events TO candidate_runtime;
GRANT SELECT (id,currency,balance_micros,reserved_balance_micros,low_balance_threshold_micros,
    is_active,synced_at,sync_expires_at,balance_observed_at,created_at,sync_next_attempt_at,last_error,sync_failures)
    ON public.suppliers TO candidate_runtime;
GRANT UPDATE (low_balance_alerted_at) ON public.suppliers TO candidate_runtime;
GRANT SELECT (id,order_id,quantity,state,reserved_supplier_id,reserved_catalog_item_id,reserved_cost_micros,
    reserved_quantity,current_purchase_id,sources_snapshot,health_review_episode,created_at)
    ON public.supplier_fulfillments TO candidate_runtime;
GRANT SELECT (id,supplier_fulfillment_id,supplier_id,supplier_catalog_item_id,quantity,expected_cost_micros,
    state,health_review_episode,last_error,created_at,committed_at) ON public.supplier_purchases TO candidate_runtime;
GRANT SELECT (supplier_purchase_id,kind,evidence_source) ON public.supplier_purchase_evidence TO candidate_runtime;
GRANT SELECT (id,invoice_number,order_status) ON public.orders TO candidate_runtime;
GRANT SELECT ON public.supplier_health_scans,public.supplier_health_states,public.supplier_health_reviews
    TO candidate_runtime;
GRANT UPDATE (supplier_cursor,fulfillment_cursor) ON public.supplier_health_scans TO candidate_runtime;
GRANT INSERT (supplier_id,low_active,error_codes,balance_stale_active,error_recovered_at)
    ON public.supplier_health_states TO candidate_runtime;
GRANT UPDATE (low_active,error_codes,balance_stale_active,error_recovered_at)
    ON public.supplier_health_states TO candidate_runtime;
GRANT INSERT (supplier_fulfillment_id,active_identities) ON public.supplier_health_reviews TO candidate_runtime;
GRANT UPDATE (active_identities) ON public.supplier_health_reviews TO candidate_runtime;
GRANT SELECT (supplier_id) ON public.supplier_health_events TO candidate_runtime;
GRANT INSERT (id,supplier_id,message,created_at) ON public.supplier_health_events TO candidate_runtime;
GRANT SELECT (id,store_balance_top_up_id,customer_id,ledger_entry_id,amount,resolved_at,created_at)
    ON public.store_balance_compensations TO candidate_runtime;
GRANT SELECT (id,customer_id,chat_id,locale,amount,currency,status,merchant_ref,provider,provider_reference,
    provider_total_payment,provider_payment_method,provider_fee,paid_at,settled_at)
    ON public.store_balance_top_ups TO candidate_runtime;
GRANT SELECT (expires_at,provider_expires_at,payment_proof_file_id,payment_proof_submitted_at,
    payment_proof_forwarded_at,payment_proof_admin_chat_id) ON public.store_balance_top_ups TO candidate_runtime;
GRANT SELECT (provider_create_last_error,provider_create_state,provider_qris_url,provider_qris_payload)
    ON public.store_balance_top_ups TO candidate_runtime;
GRANT UPDATE (payment_proof_file_id,payment_proof_submitted_at,payment_proof_forwarded_at,
    payment_proof_admin_chat_id,updated_at) ON public.store_balance_top_ups TO candidate_runtime;
GRANT SELECT (supplier_order_id,actual_cost_micros,observed_quantity,quantity,committed_at)
    ON public.supplier_purchases TO candidate_runtime;
GRANT SELECT (payment_proof_file_id,payment_proof_admin_chat_id,payment_proof_submitted_at,
    payment_proof_forwarded_at) ON public.payments TO candidate_runtime;
GRANT UPDATE (payment_proof_forwarded_at) ON public.payments TO candidate_runtime;
GRANT INSERT (broadcast_id,customer_id,telegram_id,locale,created_at,updated_at)
    ON public.telegram_broadcast_recipients TO candidate_runtime;
GRANT INSERT (broadcast_key,source,message,photo_file_id,reply_markup,status,created_at,updated_at)
    ON public.telegram_broadcasts TO candidate_runtime;
GRANT USAGE,SELECT ON SEQUENCE public.telegram_broadcasts_id_seq TO candidate_runtime;
GRANT USAGE, SELECT ON SEQUENCE public.telegram_broadcast_recipients_id_seq,
    public.order_notification_events_id_seq TO candidate_runtime;
GRANT UPDATE (recipient_count,sent_count,failed_count,pending_count,terminal_failed_count,status,
    completed_at,updated_at) ON public.telegram_broadcasts TO candidate_runtime;
GRANT UPDATE (sent_at,failed_at,terminal_failed_at,retry_at,last_error,attempts,updated_at)
    ON public.telegram_broadcast_recipients TO candidate_runtime;
GRANT UPDATE (sent_at,failed_at,terminal_failed_at,last_error,attempts,updated_at)
    ON public.stock_notification_recipients TO candidate_runtime;
GRANT UPDATE (stock_notification_sent_at) ON public.inventory_movements TO candidate_runtime;
GRANT INSERT (inventory_movement_id,kind,created_at,updated_at)
    ON public.stock_notification_events TO candidate_runtime;
GRANT INSERT (stock_notification_event_id,customer_id,recipient_type,telegram_id,locale,text,reply_markup,photo_path,created_at,updated_at)
    ON public.stock_notification_recipients TO candidate_runtime;
GRANT UPDATE (tier_promotion_snapshotted_at,promoted_tier_minimum,promoted_tier_price_amount,promoted_tier_usdt_price_amount,
    recipients_snapshotted_at,updated_at)
    ON public.stock_notification_events TO candidate_runtime;
GRANT USAGE,SELECT ON SEQUENCE public.stock_notification_events_id_seq,public.stock_notification_recipients_id_seq TO candidate_runtime;
GRANT UPDATE (sent_at,failed_at,last_error,attempts,updated_at)
    ON public.product_price_change_notification_recipients TO candidate_runtime;
GRANT INSERT (event_key,product_id,product_name,product_icon,product_custom_emoji_id,
    old_price_amount,new_price_amount,old_usdt_price_amount,new_usdt_price_amount,created_at,updated_at)
    ON public.product_price_change_events TO candidate_runtime;
GRANT INSERT (event_id,customer_id,telegram_id,locale,created_at,updated_at)
    ON public.product_price_change_notification_recipients TO candidate_runtime;
GRANT UPDATE (store_channel_sent_at,store_channel_message_id,store_channel_failed_at,
    store_channel_last_error,store_channel_attempts,updated_at)
    ON public.product_price_change_events TO candidate_runtime;
GRANT USAGE,SELECT ON SEQUENCE public.product_price_change_events_id_seq,
    public.product_price_change_notification_recipients_id_seq TO candidate_runtime;
GRANT INSERT (order_id,event_type,recipient_email,status,attempts,next_attempt_at,created_at,updated_at)
    ON public.order_notification_events TO candidate_runtime;
GRANT UPDATE (recipient_email,status,attempts,delivery_started_at,next_attempt_at,sent_at,failed_at,last_error,updated_at)
    ON public.order_notification_events TO candidate_runtime;
