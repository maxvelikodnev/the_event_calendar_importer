<?php
/**
 * Plugin Name: The Events Calendar Importer
 * Plugin URI: https://github.com/maxvelikodnev/the_event_calendar_importer
 * Description: WordPress plugin to import posts into the event calendar. Used
 * for one-time data import (excluding verification of existing records)
 * Version: 1.0.2 Author: Max Velikodnev Author URI:
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
@set_time_limit( 0 );
@ini_set( 'upload_max_size', '20M' );
@ini_set( 'post_max_size', '20M' );
@ini_set( 'display_errors', 'On' );
$import_filename = "import.xlsx";

/***********************************************************************/

require_once( plugin_dir_path( __FILE__ ) . 'vendor/autoload.php' );
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;
use KubAT\PhpSimple\HtmlDomParser;


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

			echo "<h2>Getting started...</h2>\n";
			flush();

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
				$err     = 0;
				$title   = wp_strip_all_tags( $oCells->get( 'A' . $iRow ) );
				$title   = preg_replace( '/\t/', '', $title );
				$content = $oCells->get( 'C' . $iRow ) . " " . $oCells->get( 'D' . $iRow );

				echo "<b>Add Event:</b> " . $title . "\n";
				flush();

				//Image processing
				$imgs = [];
				$dom  = HtmlDomParser::str_get_html( $content );
				foreach ( $dom->find( 'img' ) as $element ) {
					$imgs[] = $element->src;
				}

				$imgs       = array_unique( $imgs );
				$uploadPath = wp_get_upload_dir();
				foreach ( $imgs as $k => $url ) {
					$url      = preg_replace( "|http://wacl.info/|is", "https://wacl.wildpress.dev/", $url );
					$parseUrl = parse_url( $url );

					if ( isset( $parseUrl['scheme'] ) && isset( $parseUrl['host'] ) ) {

						echo "<b>Trying to download an image:</b> " . $url;
						flush();

						$tmp = the_event_calendar_importer_download( $url );

						if ( is_wp_error( $tmp ) ) {
							$err ++;
							$error = $tmp->get_error_messages();
							echo the_event_calendar_importer_msg( " (" . $error[0] . ")", 'err' );
							flush();
						} else {
							echo the_event_calendar_importer_msg( " (Ok)" );
						}
						echo PHP_EOL;

						if ( ! $err ) {
							$file_array = [
								'name'     => basename( $url ),
								'tmp_name' => $tmp,
								'error'    => 0,
								'size'     => filesize( $tmp ),
							];

							$attachment_id = media_handle_sideload( $file_array, 0 );

							//If an error occurs
							if ( is_wp_error( $attachment_id ) ) {
								@unlink( $file_array['tmp_name'] );
								$err ++;
								$error = $attachment_id->get_error_messages();
								echo "An error has occurred:" . $error[0] . "\n";
								flush();
							}

							@unlink( $tmp );

							//Get the image url by id and replace the original url to new in the content
							$img_url = wp_get_attachment_image_src( $attachment_id, 'full' );
							$content = preg_replace( "|".$url."|is", $img_url[0], $content );
						}
					}

				}


				$post_data = [
					'post_title'   => $title,
					'post_content' => $content,
					'post_status'  => 'publish',
					'post_author'  => $post_author,
					'post_date'    => $oCells->get( 'B' . $iRow )->getValue(),
					'post_type'    => "tribe_events",
					//			        'post_category' => array( 8,39 )
				];

				// Insert a record into the database
				$post_id = wp_insert_post( $post_data );

				//	Meta tags
				if ( preg_match( "|(\d{1,2} ([a-zA-Z]+) (\d{4}))|si", $title, $mas ) ) {
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

				if ( $err ) {
					echo the_event_calendar_importer_msg( "An event has been added, but images that could not be downloaded use the original url.\n", 'err' );
				} else {
					echo the_event_calendar_importer_msg( "Event added\n" );
				}
				echo "<hr/>\n";

				flush();
			}
			echo "<h2>Imported <b>" . $counter . "</b> record(s)</h2>\n";
			echo "</pre>";
		}
		?>

        <form action="" method="POST">
			<?php
			global $import_filename;

			if ( file_exists( plugin_dir_path( __FILE__ ) . $import_filename ) ) {

				settings_fields( "import" );
				submit_button( 'Import now' );
			} else {
				echo "Import is not available. The import.xlsx file in the plugin folder is missing.";
			}
			?>
        </form>
    </div>

	<?php
}

function the_event_calendar_importer_msg( $message, $type = "ok" ) {
	$style = "";
	switch ( $type ) {
		case "err":
			$style = "color: red;";
			break;
		default:
			$style = "color: green;";
	}

	return '<span style="' . $style . '">' . $message . '</span>';
}
/*
 * Function to download files from a remote server using basic auth
 * */
function the_event_calendar_importer_download( $url ) {
	$url_filename = basename( parse_url( $url, PHP_URL_PATH ) );
	$tmpfname     = wp_tempnam( $url_filename );
	$dest_file    = @fopen( $tmpfname, "w" );

	$resource = curl_init();
	curl_setopt( $resource, CURLOPT_URL, $url );
	curl_setopt( $resource, CURLOPT_FILE, $dest_file );
	curl_setopt( $resource, CURLOPT_HEADER, 0 );
	curl_setopt( $resource, CURLOPT_USERPWD, "login:password" );
	curl_setopt( $resource, CURLOPT_TIMEOUT, 30 );
	curl_exec( $resource );

	$headers = curl_getinfo( $resource );

	if ( isset( $headers['http_code'] ) && $headers['http_code'] == 404 ) {
		return new WP_Error( '404', 'File not found' );
	}
	if ( curl_exec( $resource ) === FALSE ) {
		return new WP_Error( 'Err', curl_error( $resource ) );
	}

	curl_close( $resource );
	fclose( $dest_file );

	return $tmpfname;
}

?>