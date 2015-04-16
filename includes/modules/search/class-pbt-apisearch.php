<?php

/**
 * Searches the API for resources, returns results to an import interface
 *
 * @package PressBooks_Textbook
 * @author Brad Payne <brad@bradpayne.ca>
 * @license   GPL-2.0+
 * 
 * @copyright 2014 Brad Payne
 */

namespace PBT\Search;

use PBT\Import;

require PBT_PLUGIN_DIR . '/includes/modules/import/class-pbt-pbimport.php';

/**
 * Description of class-pb-apisearch
 *
 * @author bpayne
 */
class ApiSearch {

	/**
	 * API version number
	 * 
	 * @var type 
	 */
	private static $version = 'v1';

	/**
	 * User defined search terms
	 * 
	 * @var type 
	 */
	private static $search_terms = '';

	/**
	 * 
	 */
	public function __construct() {
		
	}

	/**
	 * 
	 * @return type
	 */
	static function formSubmit() {

		// evaluate POST DATA
		if ( false == static::isFormSubmission() || false == current_user_can( 'edit_posts' ) ) {
			return;
		}

		$redirect_url = get_bloginfo( 'url' ) . '/wp-admin/admin.php?page=api_search_import';
		$current_import = get_option( 'pbt_current_import' );

		// determine stage of import, revoke if necessary
		if ( isset( $_GET['revoke'] ) && 1 == $_GET['revoke'] && check_admin_referer( 'pbt-revoke-import' ) ) {
			self::revokeCurrentImport();
			\PressBooks\Redirect\location( $redirect_url );
		}

		// do import if that's where we're at
		if ( $_GET['import'] && isset( $_POST['chapters'] ) && is_array( $_POST['chapters'] ) && is_array( $current_import ) && check_admin_referer( 'pbt-import' ) ) {

			$keys = array_keys( $_POST['chapters'] );
			$books = array();

			// Comes in as:
			/** Array (    
			  [103] => Array(
			    [import] => 1
			    [book] => 6
			    [license] =>
			    [author] => bpayne
			    [type] => chapter
			    )
			  )
			 */
			foreach ( $keys as $id ) {
				if ( ! Import\PBImport::flaggedForImport( $id ) ) continue;

				// set the post_id and type
				$chapter[$id]['type'] = $_POST['chapters'][$id]['type'];
				$chapter[$id]['license'] = $_POST['chapters'][$id]['license'];
				$chapter[$id]['author'] = $_POST['chapters'][$id]['author'];

				// add it to the blog_id to which it belongs
				$books[$_POST['chapters'][$id]['book']][$id] = $chapter[$id];
			}
			// Modified as:
			/** Array(
			  [103] => Array (
			    [6] => Array(
			      [type] => chapter
			      [license] => cc-by
			      [author] => Brad Payne
			      )
			    )
			  )
			 */
			// import
			$importer = new Import\PBImport();
			$ok = $importer->import( $books );

			$msg = "Tried to import a post from this PressBooks instance and ";
			$msg .= ( $ok ) ? 'succeeded :)' : 'failed :(';

			if ( $ok ) {
				// Success! Redirect to organize page
				$success_url = get_bloginfo( 'url' ) . '/wp-admin/admin.php?page=pressbooks';
				self::log( $msg, $books );
				\PressBooks\Redirect\location( $success_url );
			}

			// redirect to organize if import is succesful	
		} elseif ( $_GET['import'] && $_POST['search_api'] && check_admin_referer( 'pbt-import' ) ) {

			$endpoint = network_home_url() . 'api/' . self::$version . '/';

			// filter post values
			$search = filter_input( INPUT_POST, 'search_api', FILTER_SANITIZE_STRING );

			// explode on space, using preg_split to deal with one or more spaces in between words
			$search = preg_split( "/[\s]+/", $search, 5 );

			// convert to csv
			self::$search_terms = implode( ',', $search );

			// check the cache 
			$books = get_transient( 'pbt-public-books' );

			// get the response
			if ( false === $books ) {
				$books = self::getPublicBooks( $endpoint );
			}

			if ( is_array( $books ) ) {
				$chapters = self::getPublicChapters( $books, $endpoint, self::$search_terms );
			}

			// set chapters in options table, only if there are results
			if ( ! empty( $chapters ) ) {
				update_option( 'pbt_current_import', $chapters );
				delete_option( 'pbt_terms_not_found' );
			} else {
				update_option( 'pbt_terms_not_found', self::$search_terms );
			}
		}
		// redirect back to import page
		\PressBooks\Redirect\location( $redirect_url );
	}

	/**
	 * Uses v1/api to get an array of public books in the same PB instance
	 * 
	 * @param string $endpoint API url
	 * @return array of books
	 * [2] => Array(
	    [title] => Brad can has book
	    [author] => Brad Payne
	    [license] => cc-by-sa
	  )
	  [5] => Array(
	    [title] => Help, I'm a Book!
	    [author] => Frank Zappa
	    [license] => cc-by-nc-sa
	  )
	 */
	static function getPublicBooks( $endpoint ) {
		$books = array();
		$current_book = get_current_blog_id();

		// build the url, get list of public books
		$public_books = wp_remote_get( $endpoint . 'books/' );
		if ( is_wp_error( $public_books ) ) {
			error_log( '\PBT\Search\getPublicBooks error: ' . $$public_books->get_error_message() );
			\PressBooks\Redirect\location( get_bloginfo( 'url' ) . '/wp-admin/admin.php?page=api_search_import' );
		}

		$public_books_array = json_decode( $public_books['body'], true );

		// something goes wrong at the API level/response
		if ( 0 == $public_books_array['success'] ) {
			return;
		}

		// a valid response
		if ( false !== ( $public_books_array ) ) {
			foreach ( $public_books_array['data'] as $id => $val ) {
				$books[$id] = array(
				    'title' => $public_books_array['data'][$id]['book_meta']['pb_title'],
				    'author' => $public_books_array['data'][$id]['book_meta']['pb_author'],
				    'license' => $public_books_array['data'][$id]['book_meta']['pb_book_license']
				);
			}
		}

		// don't return results from the book where the search is happening 
		if ( isset( $books[$current_book] ) ) {
			unset( $books[$current_book] );
		}

		// cache public books
		set_transient( 'pbt-public-books', $books, 86400 );

		return $books;
	}

	/**
	 * Gets a list of books that are set to display publically
	 * 
	 * 
	 * @param type $books
	 * @param type $endpoint
	 * @param type $search
	 * @return array $chapters from the search results
	 */
	static function getPublicChapters( $books, $endpoint, $search ) {
		$chapters = array();
		$blog_ids = array_keys( $books );

		// iterate through books, search for string match in chapter titles
		foreach ( $blog_ids as $id ) {
			$request = $endpoint . 'books/' . $id . '/?titles=' . $search;
			$response = wp_remote_get( $request );
			$body = json_decode( $response['body'], true );
			if ( ! empty( $body ) && 1 == $body['success'] ) {
				$chapters[$id] = $books[$id];
				$chapters[$id]['chapters'] = $body['data'];
			}
		}

		return $chapters;
	}

	/**
	 * Simple check to see if the form submission is valid
	 * 
	 * @return boolean
	 */
	static function isFormSubmission() {

		if ( 'api_search_import' != @$_REQUEST['page'] ) {
			return false;
		}

		if ( ! empty( $_POST ) ) {
			return true;
		}

		if ( count( $_GET ) > 1 ) {
			return true;
		}

		return false;
	}

	/**
	 * Simple revoke of an import (user hits the 'cancel' button)
	 * 
	 * @return type
	 */
	static function revokeCurrentImport() {

		\PressBooks\Book::deleteBookObjectCache();
		return delete_option( 'pbt_current_import' );
	}
	
	/**
	 * Log for the import functionality, for tracking bugs 
	 * 
	 * @param type $message
	 * @param array $more_info
	 */
	static function log( $message, array $more_info ) {
		$subject = '[ PBT Search and Import Log ]';
		// send to superadmin
		$admin_email = get_site_option( 'admin_email' );
		$from = 'From: no-reply@' . get_blog_details()->domain; 
		$logs_email = array(
		    $admin_email,
		);
		
		

		$time = strftime( '%c' );
		$info = array(
		    'time' => $time,
		    'site_url' => site_url(),
		);
		
		$msg = print_r( array_merge( $info, $more_info ), true ) . $message;
		
		// Write to error log
		error_log( $subject . "\n" . $msg );

		// Email logs
		foreach ( $logs_email as $email ) {
			error_log( $time . ' - ' . $msg, 1, $email, $from );
		}
	}

}
