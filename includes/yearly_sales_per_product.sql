SELECT
    order_items.order_item_id,
    order_item_meta.meta_value AS product_id,
    product_post.post_title AS product_name,
    SUM(order_item_meta_qty.meta_value) AS quantity_sold,
    ROUND(
        SUM(order_item_meta_total.meta_value),
        2
    ) AS total_sales
FROM
    WPDBPREFIX_woocommerce_order_items AS order_items
    INNER JOIN WPDBPREFIX_woocommerce_order_itemmeta AS order_item_meta ON order_items.order_item_id = order_item_meta.order_item_id
    INNER JOIN WPDBPREFIX_woocommerce_order_itemmeta AS order_item_meta_qty ON order_items.order_item_id = order_item_meta_qty.order_item_id
    INNER JOIN WPDBPREFIX_woocommerce_order_itemmeta AS order_item_meta_total ON order_items.order_item_id = order_item_meta_total.order_item_id
    INNER JOIN WPDBPREFIX_posts AS posts ON order_items.order_id = posts.ID
    INNER JOIN WPDBPREFIX_posts AS product_post ON order_item_meta.meta_value = product_post.ID
WHERE
    posts.post_type = 'shop_order'
    AND posts.post_status IN ('wc-completed')
    AND order_item_meta.meta_key = '_product_id'
    AND order_item_meta_qty.meta_key = '_qty'
    AND order_item_meta_total.meta_key = '_line_total'
    AND posts.post_date >= 'STARTDATE'
    AND posts.post_date <= 'ENDDATE'
GROUP BY
    product_id
ORDER BY
    quantity_sold DESC