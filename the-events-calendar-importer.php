<?php
/**
 * Plugin Name: The Events Calendar Importer
 * Plugin URI: https://github.com/maxvelikodnev/the_event_calendar_importer
 * Description: WordPress plugin to import posts into the event calendar. Used
 * for one-time data import (excluding verification of existing records)
 * Version: 1.0.0 Author: Max Velikodnev Author URI:
 * https://github.com/maxvelikodnev/ License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * ----------------------------------------------------------------------
 * Copyright (C) 2019  Max Velikodnev  (Email: maxvelikodnev@gmail.com)
 * ----------------------------------------------------------------------
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 * ----------------------------------------------------------------------
 */
error_reporting( E_ALL );
ini_set( 'display_errors', 'On' );
$import_filename = "import.xlsx";


/***********************************************************************/

require_once( plugin_dir_path( __FILE__ ) . 'vendor/autoload.php' );

use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;


add_action( 'admin_menu', 'register_the_event_calendar_importer' );

function register_the_event_calendar_importer() {
	add_menu_page( 'The Events Calendar Importer', 'The Events Calendar Importer', 'edit_others_posts', 'eventa_calend_importer', 'the_event_calendar_importer', 'dashicons-album', 6 );
}

function the_event_calendar_importer() {
	?>

    <div class="wrap">
        <h2><?php echo get_admin_page_title() ?></h2>

		<?php
		global $import_filename;

		if ( isset( $_POST['action'] ) ) {

			$post_author = get_current_user_id();

			$sFile   = plugin_dir_path( __FILE__ ) . $import_filename;
			$oReader = new Xlsx();

			$oSpreadsheet = $oReader->load( $sFile );

			$oCells = $oSpreadsheet->getActiveSheet()->getCellCollection();


			$gmt_offset = get_option( 'gmt_offset' );
			$timezone   = get_option( 'timezone_string' );
			if ( ! strlen( $timezone ) ) {
				$timezone = "UTC" . ( ( $gmt_offset >= 0 ) ? "+" . $gmt_offset : $gmt_offset );
			}
			$timestamp_offset = intval( get_option( 'gmt_offset' ) ) * 3600;

			date_default_timezone_set( $timezone );
			$counter = 0;
			echo "<pre>";
			for ( $iRow = 1; $iRow <= $oCells->getHighestRow(); $iRow ++ ) {
				$title     = wp_strip_all_tags( $oCells->get( 'A' . $iRow ) );
				$post_data = [
					'post_title'   => $title,
					'post_content' => $oCells->get( 'C' . $iRow ) . " " . $oCells->get( 'D' . $iRow ),
					'post_status'  => 'publish',
					'post_author'  => $post_author,
					'post_date'    => $oCells->get( 'B' . $iRow ),
					'post_type'    => "tribe_events",
					//			        'post_category' => array( 8,39 )
				];

				// Insert a record into the database
				$post_id = wp_insert_post( $post_data );

				//	Meta tags
				if ( preg_match( "|(\d{1,2} ([a-zA-Z]+) (\d{4}))|sei", $title, $mas ) ) {
					$timestamp = strtotime( $mas[0] );
				} else {
					$timestamp = strtotime( $oCells->get( 'B' . $iRow ) );
				}
				$duration = 86400;

				add_post_meta( $post_id, '_EventShowMapLink', 1, TRUE );
				add_post_meta( $post_id, '_EventShowMap', 1, TRUE );
				add_post_meta( $post_id, '_EventStartDate', date( "Y-m-d H:i:s", $timestamp + $timestamp_offset ), TRUE );
				add_post_meta( $post_id, '_EventEndDate', date( "Y-m-d H:i:s", $timestamp + $timestamp_offset + $duration ), TRUE );
				add_post_meta( $post_id, '_EventStartDateUTC', gmdate( "Y-m-d H:i:s", $timestamp + $timestamp_offset ), TRUE );
				add_post_meta( $post_id, '_EventEndDateUTC', gmdate( "Y-m-d H:i:s", $timestamp + $timestamp_offset + $duration ), TRUE );
				add_post_meta( $post_id, '_EventDuration', $duration, TRUE );
				add_post_meta( $post_id, '_EventCurrencySymbol', "", TRUE );
				add_post_meta( $post_id, '_EventCurrencyPosition', "prefix", TRUE );
				add_post_meta( $post_id, '_EventCost', "", TRUE );
				add_post_meta( $post_id, '_EventURL', "", TRUE );
				add_post_meta( $post_id, '_EventTimezone', $timezone, TRUE );
				add_post_meta( $post_id, '_EventTimezoneAbbr', $timezone, TRUE );

				$counter ++;

				echo wp_strip_all_tags( $oCells->get( 'A' . $iRow ) ) . " - <b>Ok</b>\n";
				flush();
			}
			echo "Imported " . $counter . " record(s)\n";
			echo "</pre>";
		}
		?>

        <form action="" method="POST">
			<?php
			global $import_filename;

			if ( file_exists( plugin_dir_path( __FILE__ ) . $import_filename ) ) {

				settings_fields( "import" );     // скрытые защитные поля
				submit_button( 'Import now' );
			} else {
				echo "Import is not available. The import.xlsx file in the plugin folder is missing.";
			}
			?>
        </form>
    </div>

	<?php
}

?>