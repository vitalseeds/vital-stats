<?php
// CLI COMMANDS

WP_CLI::add_command('vital_stats yearly_sales_per_product_sql', function () {
    WP_CLI::log('Starting the calculation of yearly sales per product...');
    vital_stats_yearly_sales_per_product_sql();
    WP_CLI::success('Yearly sales per product have been calculated and saved using SQL.');
});

WP_CLI::add_command('vital_stats show', function () {
    $product_sales = get_option('vital_stats_yearly_sales_per_product', []);

    if (empty($product_sales)) {
        WP_CLI::warning('No sales data found.');
        return;
    }

    $table = new \cli\Table();
    $table->setHeaders(['Product ID', 'Product Title', 'Quantity Sold', 'Total Sales']);

    foreach ($product_sales as $sale) {
        $table->addRow([$sale['product_id'], $sale['product_name'], $sale['quantity_sold'], $sale['total_sales']]);
    }

    $table->display();
    WP_CLI::success('Yearly sales per product displayed.');
});

WP_CLI::add_command('vital_stats update_yearly_sales', function ($args) {
    list($post_id, $yearly_sales) = $args;

    if (!is_numeric($post_id) || !is_numeric($yearly_sales)) {
        WP_CLI::error('Both post_id and yearly_sales must be numeric.');
        return;
    }

    update_post_meta($post_id, 'yearly_sales', $yearly_sales);

    if (get_post_meta($post_id, 'yearly_sales', true) == $yearly_sales) {
        WP_CLI::success("Yearly sales meta value for post ID $post_id updated to $yearly_sales.");
    } else {
        WP_CLI::error('Failed to update yearly sales meta value.');
    }
});

WP_CLI::add_command('vital_stats get_yearly_sales', function ($args) {
    list($post_id) = $args;

    if (!is_numeric($post_id)) {
        WP_CLI::error('post_id must be numeric.');
        return;
    }
    $yearly_sales = get_post_meta($post_id, 'yearly_sales', true);

    if ($yearly_sales === '') {
        WP_CLI::warning("No yearly sales data found for post ID $post_id.");
    } else {
        WP_CLI::success("Yearly sales for post ID $post_id: $yearly_sales.");
    }
});
