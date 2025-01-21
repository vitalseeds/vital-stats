SELECT
    p.ID AS product_id,
    p.post_title AS product_name,
    SUM(oim_qty.meta_value) AS quantity_sold,
    ROUND(
        SUM(oim_price.meta_value),
        2
    ) AS total_sales
FROM
    WPDBPREFIX_woocommerce_order_items oi
    JOIN WPDBPREFIX_woocommerce_order_itemmeta oim_product_id ON oi.order_item_id = oim_product_id.order_item_id
    AND oim_product_id.meta_key = '_product_id'
    JOIN WPDBPREFIX_posts p ON p.ID = oim_product_id.meta_value
    JOIN WPDBPREFIX_woocommerce_order_itemmeta oim_qty ON oi.order_item_id = oim_qty.order_item_id
    JOIN WPDBPREFIX_woocommerce_order_itemmeta oim_price ON oi.order_item_id = oim_price.order_item_id
    JOIN WPDBPREFIX_posts o ON oi.order_id = o.ID
    LEFT JOIN WPDBPREFIX_woocommerce_order_itemmeta oim_woosb ON oi.order_item_id = oim_woosb.order_item_id
    AND oim_woosb.meta_key = 'woosb_ids'
WHERE
    p.post_type = 'product'
    AND oim_woosb.meta_value IS NULL
    AND oim_qty.meta_key = '_qty'
    AND oim_price.meta_key = '_line_total' -- _line_total includes discount, _line_subtotal does not
    AND o.post_type = 'shop_order'
    AND o.post_date BETWEEN 'STARTDATE' -- eg '2024-11-01 00:00:00'
    AND 'ENDDATE'
GROUP BY
    p.ID,
    p.post_title
ORDER BY
    quantity_sold DESC;