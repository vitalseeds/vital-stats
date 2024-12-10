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

if (defined('WP_CLI') && WP_CLI) {
	require_once __DIR__ . '/includes/cli.php';
}

// define('VITAL_STATS_START_DATE', date('Y-m-d 00:00:00', strtotime('first day of September last year')));
// define('VITAL_STATS_END_DATE', date('Y-m-d 23:59:59', strtotime('last day of August this year')));
function vital_stats_get_start_date()
{
	$start_month = get_option('vital_stats_start_month', 9); // Default to September
	$start_month_name = date('F', mktime(0, 0, 0, $start_month, 10));
	return date('Y-m-d 00:00:00', strtotime("first day of $start_month_name last year"));
	// return date('Y-m-d 00:00:00', strtotime("first day of $start_month last year"));
}

function vital_stats_get_end_date()
{
	$end_month = get_option('vital_stats_start_month', 9) - 1; // Default to September
	$end_month_name = date('F', mktime(0, 0, 0, $end_month, 10));
	return date('Y-m-d 23:59:59', strtotime("last day of $end_month_name this year"));
}

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
	$start_date = vital_stats_get_start_date();
	$end_date = vital_stats_get_end_date();

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
			ROUND(SUM(CASE
				WHEN order_item_meta_total.meta_value = 0 AND order_item_meta_woosb_price.meta_value IS NOT NULL
				THEN order_item_meta_woosb_price.meta_value
				ELSE order_item_meta_total.meta_value
			END), 2) AS total_sales
		FROM {$wpdb->prefix}woocommerce_order_items AS order_items
		INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS order_item_meta
			ON order_items.order_item_id = order_item_meta.order_item_id
		INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS order_item_meta_qty
			ON order_items.order_item_id = order_item_meta_qty.order_item_id
		INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS order_item_meta_total
			ON order_items.order_item_id = order_item_meta_total.order_item_id
		LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS order_item_meta_woosb_price
			ON order_items.order_item_id = order_item_meta_woosb_price.order_item_id
			AND order_item_meta_woosb_price.meta_key = '_woosb_price'
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

	if ($wpdb->last_error) {
		$error = 'Failed to calculate yearly sales meta values: ' . $wpdb->last_error;
		error_log($error);
		exit;
	}

	update_option('vital_stats_yearly_sales_per_product', $product_sales);

	vital_stats_add_yearly_sales_to_products();
}
add_action('vital_stats_cron_hook', 'vital_stats_yearly_sales_per_product_sql');


/**
 * Updates the yearly sales meta value for products in the WordPress database.
 *
 * This function retrieves the yearly sales data for each product from the 'vital_stats_yearly_sales_per_product' option,
 * constructs a SQL query to update the 'yearly_sales' meta value for each product, and executes the query.
 *
 * The SQL query uses a CASE statement to match each product ID with its corresponding total sales value and updates
 * the 'yearly_sales' meta value in the 'wp_postmeta' table.
 *
 * @global wpdb $wpdb WordPress database abstraction object.
 */
function vital_stats_add_yearly_sales_to_products()
{
	$product_sales = get_option('vital_stats_yearly_sales_per_product', []);

	global $wpdb;
	$cases = array_column($product_sales, 'quantity_sold', 'product_id');

	if (defined('WP_CLI') && WP_CLI) {
		WP_CLI::log('Deleting current yearly_sales values...');
	}
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s",
			'yearly_sales'
		)
	);

	if (defined('WP_CLI') && WP_CLI) {
		WP_CLI::log('Updating yearly_sales values per product...');
	}

	foreach ($cases as $post_id => $yearly_sales) {
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
				VALUES (%d, 'yearly_sales', %d)",
				$post_id,
				$yearly_sales,
			)
		);
	}


	if ($wpdb->last_error) {
		$error = 'Failed to update yearly sales meta values: ' . $wpdb->last_error;
		if (defined('WP_CLI') && WP_CLI) {
			WP_CLI::error($error);
		} else {
			add_action('admin_notices', function () use ($error) {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($error) . '</p></div>';
			});
		}
		error_log($error);
	} else {
		$success = 'Per product \'yearly_sales\' meta values updated successfully.';
		if (defined('WP_CLI') && WP_CLI) {
			WP_CLI::success($success);
		} else {
			add_action('admin_notices', function () use ($success) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($success) . '</p></div>';
			});
		}
		error_log($success);
	}
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

// SETTINGS PAGE
function vital_stats_settings_page()
{
	if (isset($_POST['vital_stats_save_settings'])) {
		check_admin_referer('vital_stats_save_settings_action', 'vital_stats_save_settings_nonce');

		$start_month = intval($_POST['vital_stats_start_month']);

		update_option('vital_stats_start_month', $start_month);

		echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
	}

	$start_month = get_option('vital_stats_start_month', 9); // Default to September

	echo '<div class="wrap">
		<h1>Vital Stats Settings</h1>
		<p>Use the form below to set the start month for the sales data.</p>
		<p>This month defines the start of the range within which the sales data will be collected and analyzed.</p>
		<form method="post">
			' . wp_nonce_field('vital_stats_save_settings_action', 'vital_stats_save_settings_nonce') . '
			<table class="form-table">
				<tr valign="top">
					<th scope="row">Start Month</th>
					<td>
						<select name="vital_stats_start_month" required>
							<option value="1" ' . selected($start_month, 1, false) . '>January</option>
							<option value="2" ' . selected($start_month, 2, false) . '>February</option>
							<option value="3" ' . selected($start_month, 3, false) . '>March</option>
							<option value="4" ' . selected($start_month, 4, false) . '>April</option>
							<option value="5" ' . selected($start_month, 5, false) . '>May</option>
							<option value="6" ' . selected($start_month, 6, false) . '>June</option>
							<option value="7" ' . selected($start_month, 7, false) . '>July</option>
							<option value="8" ' . selected($start_month, 8, false) . '>August</option>
							<option value="9" ' . selected($start_month, 9, false) . '>September</option>
							<option value="10" ' . selected($start_month, 10, false) . '>October</option>
							<option value="11" ' . selected($start_month, 11, false) . '>November</option>
							<option value="12" ' . selected($start_month, 12, false) . '>December</option>
						</select>
					</td>
				</tr>
			</table>
			<p><input type="submit" class="button button-primary" name="vital_stats_save_settings" value="Save Settings"></p>
		</form>
	</div>';
}

function vital_stats_admin_menu_settings()
{
	add_options_page(
		'Vital Stats Settings',
		'Vital Stats',
		'manage_options',
		'vital-stats-settings',
		'vital_stats_settings_page'
	);
}
add_action('admin_menu', 'vital_stats_admin_menu_settings');

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

		<p>The sales data is updated once a day at midnight and cached for faster access, and to avoid adding burden to the database.</p>
		<p>If you need to update the data immediately, you can run the calculation manually using the button below. It may take a few seconds.</p>

		<p>Start Date: ' . esc_html(date('d/m/Y', strtotime(vital_stats_get_start_date()))) . '</p>
		<p>End Date: ' . esc_html(date('d/m/Y', strtotime(vital_stats_get_end_date()))) . '</p>

		<p><a href="options-general.php?page=vital-stats-settings">Date settings</a>.</p>
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

/**
 * Add custom sorting options (asc/desc)
 */
add_filter('woocommerce_get_catalog_ordering_args', 'custom_woocommerce_get_catalog_ordering_args');
function custom_woocommerce_get_catalog_ordering_args($args)
{
	$orderby_value = isset($_GET['orderby']) ? wc_clean($_GET['orderby']) : apply_filters('woocommerce_default_catalog_orderby', get_option('woocommerce_default_catalog_orderby'));
	if ('yearly_popularity' == $orderby_value) {
		$args['orderby'] = 'meta_value_num';
		$args['meta_key'] = 'yearly_sales';
		$args['order'] = 'desc';
	}
	return $args;
}

add_filter('woocommerce_default_catalog_orderby_options', 'custom_woocommerce_catalog_orderby');
add_filter('woocommerce_catalog_orderby', 'custom_woocommerce_catalog_orderby');
function custom_woocommerce_catalog_orderby($sortby)
{
	$sortby = array_merge(array('yearly_popularity' => 'Popularity'), $sortby);
	return $sortby;
}
