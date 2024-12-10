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


/**
 * Retrieves the start date based on a specified start month.
 *
 * This function determines the start date of a period based on a given start month.
 * If the current month is greater than the start month, the start date will be the
 * first day of the start month in the current year. Otherwise, it will be the first
 * day of the start month in the previous year.
 *
 * @return string The start date in 'Y-m-d 00:00:00' format.
 */
function vital_stats_get_start_date()
{
	$start_month = get_option('vital_stats_start_month', 9); // Default to September
	$start_month_name = date('F', mktime(0, 0, 0, $start_month, 10));

	$start_month_numeric = (int) $start_month;
	$current_month_numeric = (int) date('n');
	if ($current_month_numeric > $start_month_numeric) {
		return date('Y-m-d 00:00:00', strtotime("first day of $start_month_name this year"));
	}
	return date('Y-m-d 00:00:00', strtotime("first day of $start_month_name last year"));
}

function vital_stats_get_end_date()
{
	// Always just run the stats until todays date
	return date('Y-m-d 23:59:59');
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


/**
 * Retrieves and calculates yearly sales data for WooCommerce products within a specified date range.
 *
 * This function constructs and executes an SQL query to gather sales data for WooCommerce products
 * between the start and end dates obtained from the `vital_stats_get_start_date` and `vital_stats_get_end_date` functions.
 * The sales data includes the total quantity sold and the total sales amount for each product.
 *
 * The SQL query joins the following tables:
 * - woocommerce_order_items: Contains order item details.
 * - woocommerce_order_itemmeta: Contains metadata for order items.
 * - posts: Contains order details.
 *
 * The query filters results to include only completed orders ('wc-completed') and orders within the specified date range.
 * The results are grouped by product_id and ordered by the quantity sold in descending order.
 *
 * The function logs the start and end dates when executed via WP-CLI.
 * If the query execution fails, an error message is logged.
 * The resulting sales data is stored in the 'vital_stats_yearly_sales_per_product' option.
 * Additionally, the function triggers the `vital_stats_add_yearly_sales_to_products` function to update product meta values.
 *
 * @global wpdb $wpdb WordPress database abstraction object.
 *
 * @return void
 */ {
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
