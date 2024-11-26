<?php

/*
Plugin Name:  Vital Stats
Plugin URI:   https://github.com/vitalseeds/vital-stats
Description:  Custom statistics and reports for Vital seeds woocommerce store.
Version:      2.0
Author:       tombola
Author URI:   https://github.com/tombola
License:      GPL2
License URI:  https://github.com/vitalseeds/vital-stats/blob/main/LICENSE
Text Domain:  vital-stats
Domain Path:  /languages
*/

define('VITAL_STATS_START_DATE', date('Y-01-01 00:00:00'));
define('VITAL_STATS_END_DATE', date('Y-12-31 23:59:59'));

if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

if (! wp_next_scheduled('vital_stats_cron_hook')) {
	wp_schedule_event(strtotime('midnight'), 'daily', 'vital_stats_cron_hook');
}

register_deactivation_hook(__FILE__, function () {
	wp_clear_scheduled_hook('vital_stats_cron_hook');
});

function vital_stats_yearly_sales_per_product_sql()
{
	global $wpdb;
	$start_date = VITAL_STATS_START_DATE;
	$end_date = VITAL_STATS_END_DATE;

	/**
	 * This SQL query retrieves sales data for WooCommerce products within a specified date range.
	 *
	 * The query selects the following fields:
	 * - order_item_id: The ID of the order item.
	 * - product_id: The ID of the product.
	 * - quantity_sold: The total quantity of the product sold.
	 * - total_sales: The total sales amount for the product.
	 *
	 * The query joins the following tables:
	 * - woocommerce_order_items: Contains order item details.
	 * - woocommerce_order_itemmeta: Contains metadata for order items.
	 * - posts: Contains order details.
	 *
	 * The query filters results to include only completed orders ('wc-completed') and orders within the specified date range.
	 * The results are grouped by product_id.
	 *
	 * @param string $start_date The start date for the sales data.
	 * @param string $end_date The end date for the sales data.
	 */
	$query = "
		SELECT
			order_items.order_item_id,
			order_item_meta.meta_value AS product_id,
			product_post.post_title AS product_name,
			SUM(order_item_meta_qty.meta_value) AS quantity_sold,
			ROUND(SUM(order_item_meta_total.meta_value), 2) AS total_sales
		FROM {$wpdb->prefix}woocommerce_order_items AS order_items
		INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS order_item_meta
			ON order_items.order_item_id = order_item_meta.order_item_id
		INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS order_item_meta_qty
			ON order_items.order_item_id = order_item_meta_qty.order_item_id
		INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS order_item_meta_total
			ON order_items.order_item_id = order_item_meta_total.order_item_id
		INNER JOIN {$wpdb->prefix}posts AS posts
			ON order_items.order_id = posts.ID
		INNER JOIN {$wpdb->prefix}posts AS product_post
			ON order_item_meta.meta_value = product_post.ID
		WHERE posts.post_type = 'shop_order'
			AND posts.post_status IN ('wc-completed')
			AND order_item_meta.meta_key = '_product_id'
			AND order_item_meta_qty.meta_key = '_qty'
			AND order_item_meta_total.meta_key = '_line_total'
			AND posts.post_date >= %s
			AND posts.post_date <= %s
		GROUP BY product_id
		ORDER BY quantity_sold DESC
	";

	$product_sales = $wpdb->get_results($wpdb->prepare($query, $start_date, $end_date), ARRAY_A);

	update_option('vital_stats_yearly_sales_per_product', $product_sales);
}
add_action('vital_stats_cron_hook', 'vital_stats_yearly_sales_per_product_sql');

if (defined('WP_CLI') && WP_CLI) {
	WP_CLI::add_command('vital_stats yearly_sales_per_product_sql', function () {
		WP_CLI::log('Starting the calculation of yearly sales per product...');
		vital_stats_yearly_sales_per_product_sql();
		WP_CLI::success('Yearly sales per product have been calculated and saved using SQL.');
	});
}

if (defined('WP_CLI') && WP_CLI) {
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
}

// ADMIN MENU

function vital_stats_admin_menu()
{
	add_menu_page(
		'Vital Stats',
		'Vital Stats',
		'manage_options',
		'vital-stats',
		'vital_stats_admin_page',
		'dashicons-chart-bar',
		6
	);
}
add_action('admin_menu', 'vital_stats_admin_menu');

// ADMIN PAGE
function vital_stats_admin_page()
{
	if (isset($_POST['vital_stats_run'])) {
		vital_stats_yearly_sales_per_product_sql();
		echo '<div class="notice notice-success is-dismissible"><p>Yearly sales per product have been calculated and saved.</p></div>';
	}

	$product_sales = get_option('vital_stats_yearly_sales_per_product', []);

	echo '<div class="wrap">
		<h1>Vital Stats</h1>
		<p>Start Date: ' . esc_html(date('d/m/Y', strtotime(VITAL_STATS_START_DATE))) . '</p>
		<p>End Date: ' . esc_html(date('d/m/Y', strtotime(VITAL_STATS_END_DATE))) . '</p>
		<form method="post">
			<input type="hidden" name="vital_stats_run" value="1">
			' . wp_nonce_field('vital_stats_run_action', 'vital_stats_run_nonce') . '
			<p><input type="submit" class="button button-primary" value="Run Yearly Sales Calculation"></p>
		</form>';

	if (empty($product_sales)) {
		echo '<p>No sales data found.</p>';
	} else {
		$sort_order = isset($_GET['sort_order']) && $_GET['sort_order'] === 'asc' ? 'desc' : 'asc';
		$sorted_sales = $product_sales;

		if (isset($_GET['sort_by'])) {
			if ($_GET['sort_by'] === 'quantity_sold') {
				usort($sorted_sales, function ($a, $b) use ($sort_order) {
					if ($sort_order === 'asc') {
						return $a['quantity_sold'] <=> $b['quantity_sold'];
					} else {
						return $b['quantity_sold'] <=> $a['quantity_sold'];
					}
				});
			} elseif ($_GET['sort_by'] === 'total_sales') {
				usort($sorted_sales, function ($a, $b) use ($sort_order) {
					if ($sort_order === 'asc') {
						return $a['total_sales'] <=> $b['total_sales'];
					} else {
						return $b['total_sales'] <=> $a['total_sales'];
					}
				});
			}
		}

		echo '<table class="widefat">
			<thead>
				<tr>
					<th>Product ID</th>
					<th>Product Name</th>
					<th><a href="?page=vital-stats&sort_by=quantity_sold&sort_order=' . $sort_order . '">Quantity Sold</a></th>
					<th><a href="?page=vital-stats&sort_by=total_sales&sort_order=' . $sort_order . '">Total Sales</a></th>
				</tr>
			</thead>
			<tbody>';

		foreach ($sorted_sales as $sale) {
			$row_class = $sale['quantity_sold'] > 1000 ? 'style="background-color: #cce5ff;"' : '';
			$quantity_sold = $sale['quantity_sold'];
			$color = 'white';

			if ($quantity_sold > 1000) {
				$color = '#ffcccc'; // Pastel Red
			} elseif ($quantity_sold > 500) {
				$color = '#ffcc99'; // Pastel Orange
			} elseif ($quantity_sold > 250) {
				$color = '#ffffcc'; // Pastel Yellow
			} elseif ($quantity_sold > 125) {
				$color = '#ccffcc'; // Pastel Light Green
			}

			$row_class = 'style="background-color: ' . $color . ';"';
			echo '<tr>
				<td>' . esc_html($sale['product_id']) . '</td>
				<td>' . esc_html($sale['product_name']) . '</td>
				<td ' . $row_class . '>' . esc_html($sale['quantity_sold']) . '</td>
				<td>£' . esc_html($sale['total_sales']) . '</td>
			</tr>';
		}

		echo '</tbody></table>';
	}

	echo '</div>';
}
