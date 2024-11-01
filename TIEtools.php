<?php

/*
Plugin Name: TIEtools
Plugin URI: http://www.setupmyvps.com/tietools/
Description: Automatic post expiry, image expiry, duplicate post detection and server log deletion to keep your site clean and efficient.
Version: 1.2.2
Author: TIEro
Author URI: http://www.setupmyvps.com
License: GPL2
*/

// Register the hooks for plugin activation and deactivation.
register_activation_hook(__FILE__, 'do_TIEtools_activation');
register_deactivation_hook(__FILE__, 'do_TIEtools_deactivation');

// Add actions to define scheduled job and place settings menu on the Dashboard.
add_action('TIEtools_functions', 'do_TIEtools_all');
add_action('admin_menu', 'TIEtools_settings_page');

// On plugin activation, schedule the hourly expiry job. Set defaults if not already present.
function do_TIEtools_activation() {
	if( !wp_next_scheduled( 'TIEtools_functions' ) ) {
		wp_schedule_event( current_time ( 'timestamp' ), 'hourly', 'TIEtools_functions' ); 
	}
	add_option('TIEtools_expiry_power', 'off');
	add_option('TIEdupedeleter_powerbutton', 'off');
	add_option('TIEtools_logs_power', 'off');
	add_option('TIEtools_notify_power','off');
	add_option('TIEtools_images_power','off');
	add_option('TIEexpire_pub', 'publish');
	add_option('TIEexpire_catsradio', 'include');
	add_option('TIEtools_images_trash', 'off');
	add_option('TIEdupedeleter_status_published', 'publish');
	add_option('TIEdupedeleter_catsradio', 'include');
	add_option('TIEdupedeleter_newoldradio','MIN');
	add_option('TIEtools_logs_filename','error_log');
	add_option('TIEtools_notify_poster','off');
	add_option('TIEtools_notify_admin','off');
	add_option('TIEtools_notify_other','off');
	add_option('TIEtools_notify_expiry','off');
	add_option('TIEtools_notify_dupes','off');
}

// On plugin deactivation, remove the scheduled job. Note that the {prefix}_wti_totals view remains at present.
function do_TIEtools_deactivation() {
	// Remove scheduled expiry job
	wp_clear_scheduled_hook( 'TIEtools_functions' );
}

// Define the Settings page function for options.
function TIEtools_settings_page() {
  add_menu_page('TIEtools', 'TIEtools', 'administrator', 'TIEtools_settings', 'TIEtools_option_settings');
}

// This is the function that wp-cron runs on schedule.
function do_TIEtools_all() {
	TIEtools_postexpire();
	TIEtools_imagesexpire();
	TIEtools_dupedeleter();
	TIEtools_logremover();
}

// Post expiry master function (so it's easy to change the order or expand).
function TIEtools_postexpire() {
	$expiry_power = (get_option('TIEtools_expiry_power') == 'on') ? 'on' : 'off';
	if ($expiry_power == 'on') {
		TIEtools_expirebydays();
		TIEtools_expirebyposts();
		TIEtools_expirebyviews();
		TIEtools_expirebylikes();
	}
}

// Dupe deletion master function (so it's easy to add extra functions).
function TIEtools_dupedeleter() {
	$dupes_power = (get_option('TIEdupedeleter_powerbutton') == 'on') ? 'on' : 'off';
	if ($dupes_power == 'on') {
		TIEtools_dupesbytitle();
	}
}

// Build post status list based on the four parameters passed, returning the string for the IN statement in SQL
function TIEtools_build_status_list($TIEtools_published, $TIEtools_draft, $TIEtools_pending, $TIEtools_private) {
	$statuslist = '';
	if ($TIEtools_published == 'publish') {
		$statuslist = "'publish'";
		if ($TIEtools_draft == 'draft') {
			$statuslist .= ",'draft'";
		}
		if ($TIEtools_pending == 'pending') {
			$statuslist .= ",'pending'";
		}
		if ($TIEtools_private == 'private') {
			$statuslist .= ",'private'";
		}
	}
	elseif ($TIEtools_draft == 'draft') {
		$statuslist = "'draft'";
		if ($TIEtools_pending == 'pending') {
			$statuslist .= ",'pending'";
		}
		if ($TIEtools_private == 'private') {
			$statuslist .= ",'private'";
		}
	}
	elseif ($TIEtools_pending == 'pending') {
		$statuslist .= "'pending'";
		if ($TIEtools_private == 'private') {
			$statuslist .= ",'private'";
		}
	}
	elseif ($TIEtools_private == 'private') {
		$statuslist = "'private'";
	}
	else {
	$statuslist = '';
	}
	return $statuslist;
}	

// Expire posts by age in days. Uses the post_date field.
function TIEtools_expirebydays() { 
	global $wpdb;
	
	// Get number of days, categories to include/exclude, settings for category filter and post status.
	$numberofdays = (get_option('TIEexpire_days') != '') ? get_option('TIEexpire_days') : '0';
	$catstoinclude = (get_option('TIEexpire_catsin') != '') ? get_option('TIEexpire_catsin') : '0';
	$catstoexclude = (get_option('TIEexpire_catsout') != '') ? get_option('TIEexpire_catsout') : '0';
	$catsincludeon = (get_option('TIEexpire_catsradio') != '') ? get_option('TIEexpire_catsradio') : '' ;
	$catsindays = (get_option('TIEexpire_catsdays') != '') ? get_option('TIEexpire_catsdays') : '' ;

	// Get notification details and figure out if they are switched on.
	$notify_power = (get_option('TIEtools_notify_power') == 'on') ? 'on' : '';
	$notify_expiry = (get_option('TIEtools_notify_expiry') == 'on') ? 'on' : '' ;
	$notify_poster = (get_option('TIEtools_notify_poster') == 'on') ? 'on' : '' ;
	$notify_admin = (get_option('TIEtools_notify_admin') == 'on') ? 'on' : '' ;
	$notify_other = (get_option('TIEtools_notify_other') == 'on') ? 'on' : '' ;
	$notify_email = (get_option('TIEtools_notify_email') != '') ? get_option('TIEtools_notify_email') : '';
	
	if ($notify_power == 'on' && $notify_expiry == 'on' && ($notify_poster == 'on' || $notify_admin == 'on' || $notify_other == 'on' && $notify_email != '')) {
		$notify_is = 'on'; }
	else {
		$notify_is = 'off'; }
	
	// Get status options and build list for SQL query.
	$pub = (get_option('TIEexpire_pub') == 'publish') ? 'publish' : '';
	$dft = (get_option('TIEexpire_draft') == 'draft') ? 'draft' : '';
	$pen = (get_option('TIEexpire_pending') == 'pending') ? 'pending' : '';
	$prv = (get_option('TIEexpire_private') == 'private') ? 'private' : '';
	$expiry_statuslist = TIEtools_build_status_list($pub, $dft, $pen, $prv);
	
	// Find posts, then move them to Trash. Skip everything if days=0 (expiry off)
	if ($numberofdays > 0) {
		$dayquery = "SELECT * FROM $wpdb->posts
					 WHERE $wpdb->posts.post_status IN ($expiry_statuslist)
					 AND $wpdb->posts.post_type = 'post'
					 AND $wpdb->posts.post_date < DATE_SUB(NOW(), INTERVAL $numberofdays DAY) ";
					 
	// Check if category filter is on and, if so, check filter and apply it.
		if ($catsindays	== 'on') {
			if ($catsincludeon == 'include' && $catstoinclude != '' && $catstoinclude != '0') {
				$dayquery .= "AND $wpdb->posts.ID IN (SELECT DISTINCT object_id FROM $wpdb->term_relationships
							  WHERE $wpdb->term_relationships.term_taxonomy_id IN (" . $catstoinclude . "))";
				}
			elseif ($catsincludeon == 'exclude' && $catstoexclude != '' && $catstoexclude != '0') {	 
					$dayquery .= "AND $wpdb->posts.ID NOT IN (SELECT DISTINCT object_id FROM $wpdb->term_relationships
								  WHERE $wpdb->term_relationships.term_taxonomy_id IN (" . $catstoexclude . "))";
			}
		}
	
	// Run query and move results to Trash.
		$result = $wpdb->get_results($dayquery);
		foreach ($result as $post) {
		    setup_postdata($post);  
			$postid = $post->ID; 
			if ($notify_is == 'on') {
				$postauthorid = $post->post_author;
				$postname = $post->post_title;
				TIEtools_send_notification($postauthorid, $postname, 'expiry');
			}							
			wp_delete_post($postid);
		}
	}
}	

// Retain a given number of most recent posts and expire all others.
function TIEtools_expirebyposts() {
	global $wpdb;

	// Get the user-defined post ceiling and category filter details.
	$numberofposts = (get_option('TIEexpire_posts') != '') ? get_option('TIEexpire_posts') : '0';
	$catstoinclude = (get_option('TIEexpire_catsin') != '') ? get_option('TIEexpire_catsin') : '0';
	$catstoexclude = (get_option('TIEexpire_catsout') != '') ? get_option('TIEexpire_catsout') : '0';
	$catsincludeon = (get_option('TIEexpire_catsradio') != '') ? get_option('TIEexpire_catsradio') : '' ;
	$catsinposts = (get_option('TIEexpire_catsposts') != '') ? get_option('TIEexpire_catsposts') : '' ;

	// Get notification details and figure out if they are switched on.
	$notify_power = (get_option('TIEtools_notify_power') == 'on') ? 'on' : '';
	$notify_expiry = (get_option('TIEtools_notify_expiry') == 'on') ? 'on' : '' ;
	$notify_poster = (get_option('TIEtools_notify_poster') == 'on') ? 'on' : '' ;
	$notify_admin = (get_option('TIEtools_notify_admin') == 'on') ? 'on' : '' ;
	$notify_other = (get_option('TIEtools_notify_other') == 'on') ? 'on' : '' ;
	$notify_email = (get_option('TIEtools_notify_email') != '') ? get_option('TIEtools_notify_email') : '';
	
	if ($notify_power == 'on' && $notify_expiry == 'on' && ($notify_poster == 'on' || $notify_admin == 'on' || $notify_other == 'on' && $notify_email != '')) {
		$notify_is = 'on'; }
	else {
		$notify_is = 'off'; }
	
	// Get status options and build list for SQL query.
	$pub = (get_option('TIEexpire_pub') == 'publish') ? 'publish' : '';
	$dft = (get_option('TIEexpire_draft') == 'draft') ? 'draft' : '';
	$pen = (get_option('TIEexpire_pending') == 'pending') ? 'pending' : '';
	$prv = (get_option('TIEexpire_private') == 'private') ? 'private' : '';
	$expiry_statuslist = TIEtools_build_status_list($pub, $dft, $pen, $prv);
	
	// Get the total number of selected posts, depending on category filters
	$countquery = "SELECT COUNT(*) FROM $wpdb->posts
				   WHERE $wpdb->posts.post_status IN ($expiry_statuslist) 
				   AND $wpdb->posts.post_type = 'post' " ;
	
	if ($catsinposts == 'on') {
		if ($catsincludeon == 'include' && $catstoinclude != '' && $catstoinclude != '0') {
			$countquery .= "AND $wpdb->posts.ID IN (SELECT DISTINCT object_id FROM $wpdb->term_relationships
							WHERE $wpdb->term_relationships.term_taxonomy_id IN (" . $catstoinclude . "))";
		}	  
		elseif ($catsincludeon == 'exclude' && $catstoexclude != '' && $catstoexclude != '0') {	 
			$countquery .= "AND $wpdb->posts.ID NOT IN (SELECT DISTINCT object_id FROM $wpdb->term_relationships
						   WHERE $wpdb->term_relationships.term_taxonomy_id IN (" . $catstoexclude . "))";								  
		}
	}
	
	$countposts = $wpdb->get_var($countquery);
	
	// Work out how many posts to remove, list in reverse order and move them to the Trash.

	if ($numberofposts > 0 && $countposts > $numberofposts) {
		$limitposts = $countposts-$numberofposts;
		$postquery = "SELECT * FROM $wpdb->posts
					  WHERE $wpdb->posts.post_status IN ($expiry_statuslist)
					  AND $wpdb->posts.post_type = 'post' ";
						  
		// Check if category filter is on and, if so, check filter and apply it.	
		if ($catsinposts	== 'on') {
			if ($catsincludeon == 'include' && $catstoinclude != '' && $catstoinclude != '0') {
				$postquery .= "AND $wpdb->posts.ID IN (SELECT DISTINCT object_id FROM $wpdb->term_relationships
							   WHERE $wpdb->term_relationships.term_taxonomy_id IN (" . $catstoinclude . "))";
			}
			elseif ($catsincludeon == 'exclude' && $catstoexclude != '' && $catstoexclude != '0') {	 
				$postquery .= "AND $wpdb->posts.ID NOT IN (SELECT DISTINCT object_id FROM $wpdb->term_relationships
							   WHERE $wpdb->term_relationships.term_taxonomy_id IN (" . $catstoexclude . "))";
			}
		}
				
	// Complete and run query, then move results to Trash.
		$postquery .= "ORDER BY $wpdb->posts.post_date ASC
					   LIMIT $limitposts" ;
		$result = $wpdb->get_results($postquery);
		foreach ($result as $post) {
			setup_postdata($post);  
			$postid = $post->ID;   
			if ($notify_is == 'on') {
				$postauthorid = $post->post_author;
				$postname = $post->post_title;
				TIEtools_send_notification($postauthorid, $postname, 'expiry');
			}	
			wp_delete_post($postid);
		}
	}	
}

// Expire posts with fewer than a given number of views after a given number of days.
function TIEtools_expirebyviews() { 

	// Requires BAW Post Views Count plugin, so check it's active.
	include_once( ABSPATH . 'wp-admin/includes/plugin.php' );	
	if (is_plugin_active('baw-post-views-count/bawpv.php')) {
		global $wpdb;

		// Get the user-defined number of days and views.
		$numberofviewdays = (get_option('TIEexpire_viewdays') != '') ? get_option('TIEexpire_viewdays') : '0';
		$numberofviews = (get_option('TIEexpire_views') != '') ? get_option('TIEexpire_views') : '0';
		$catstoinclude = (get_option('TIEexpire_catsin') != '') ? get_option('TIEexpire_catsin') : '0';
		$catstoexclude = (get_option('TIEexpire_catsout') != '') ? get_option('TIEexpire_catsout') : '0';
		$catsincludeon = (get_option('TIEexpire_catsradio') != '') ? get_option('TIEexpire_catsradio') : '' ;
		$catsinviews = (get_option('TIEexpire_catsviews') != '') ? get_option('TIEexpire_catsviews') : '' ;

		// Get notification details and figure out if they are switched on.
		$notify_power = (get_option('TIEtools_notify_power') == 'on') ? 'on' : '';
		$notify_expiry = (get_option('TIEtools_notify_expiry') == 'on') ? 'on' : '' ;
		$notify_poster = (get_option('TIEtools_notify_poster') == 'on') ? 'on' : '' ;
		$notify_admin = (get_option('TIEtools_notify_admin') == 'on') ? 'on' : '' ;
		$notify_other = (get_option('TIEtools_notify_other') == 'on') ? 'on' : '' ;
		$notify_email = (get_option('TIEtools_notify_email') != '') ? get_option('TIEtools_notify_email') : '';
	
		if ($notify_power == 'on' && $notify_expiry == 'on' && ($notify_poster == 'on' || $notify_admin == 'on' || $notify_other == 'on' && $notify_email != '')) {
			$notify_is = 'on'; }
		else {
			$notify_is = 'off'; }
		
		// Get status options and build list for SQL query.
		$pub = (get_option('TIEexpire_pub') == 'publish') ? 'publish' : '';
		$dft = (get_option('TIEexpire_draft') == 'draft') ? 'draft' : '';
		$pen = (get_option('TIEexpire_pending') == 'pending') ? 'pending' : '';
		$prv = (get_option('TIEexpire_private') == 'private') ? 'private' : '';
		$expiry_statuslist = TIEtools_build_status_list($pub, $dft, $pen, $prv);		
		
		if ($numberofviewdays > 0 && $numberofviews > 0) {

		// Trash posts without enough views after given number of days.
			$postquery = "SELECT * FROM $wpdb->posts
						  JOIN $wpdb->postmeta ON $wpdb->posts.ID = $wpdb->postmeta.post_id
						  WHERE $wpdb->posts.post_status IN ($expiry_statuslist) 
						  AND $wpdb->posts.post_type = 'post'
						  AND $wpdb->posts.post_date < DATE_SUB(NOW(), INTERVAL $numberofviewdays DAY)
						  AND $wpdb->postmeta.meta_key='_count-views_all'
						  AND $wpdb->postmeta.meta_value < $numberofviews" ;
						  
		// Check if category filter is on and, if so, check filter and apply it.
			if ($catsinviews == 'on') {
				if ($catsincludeon == 'include' && $catstoinclude != '' && $catstoinclude != '0') {
					$postquery .= "AND $wpdb->posts.ID IN (SELECT DISTINCT object_id FROM $wpdb->term_relationships
								   WHERE $wpdb->term_relationships.term_taxonomy_id IN (" . $catstoinclude . "))";
				}
				elseif ($catsincludeon == 'exclude' && $catstoexclude != '' && $catstoexclude != '0') {	 
					$postquery .= "AND $wpdb->posts.ID NOT IN (SELECT DISTINCT object_id FROM $wpdb->term_relationships
								   WHERE $wpdb->term_relationships.term_taxonomy_id IN (" . $catstoexclude . "))";
				}
			}

		// Run query and move results to Trash.			  
			$result = $wpdb->get_results($postquery);
			foreach ($result as $post) {
				setup_postdata($post);  
				$postid = $post->ID;   
				if ($notify_is == 'on') {
					$postauthorid = $post->post_author;
					$postname = $post->post_title;
					TIEtools_send_notification($postauthorid, $postname, 'expiry');
				}	
				wp_delete_post($postid);
			}
		
		// Since minimum views > 0, Trash posts with no views at all after given number of days.
			$postquery = "SELECT * FROM $wpdb->posts
						  WHERE $wpdb->posts.post_status IN ($expiry_statuslist) 
						  AND $wpdb->posts.post_type = 'post'
						  AND $wpdb->posts.post_date < DATE_SUB(NOW(), INTERVAL $numberofviewdays DAY)
						  AND $wpdb->posts.ID NOT IN (SELECT DISTINCT post_id FROM $wpdb->postmeta
							 WHERE $wpdb->postmeta.meta_key='_count-views_all')" ;
							 
		// Adjust for category filters.
			if ($catsinviews == 'on') {
				if ($catsincludeon == 'include' && $catstoinclude != '' && $catstoinclude != '0') {
					$postquery .= "AND $wpdb->posts.ID IN (SELECT DISTINCT object_id FROM $wpdb->term_relationships
								   WHERE $wpdb->term_relationships.term_taxonomy_id IN (" . $catstoinclude . "))";
				}
				elseif ($catsincludeon == 'exclude' && $catstoexclude != '' && $catstoexclude != '0') {	 
					$postquery .= "AND $wpdb->posts.ID NOT IN (SELECT DISTINCT object_id FROM $wpdb->term_relationships
								   WHERE $wpdb->term_relationships.term_taxonomy_id IN (" . $catstoexclude . "))";
				}
			}

		// Run query and move results to Trash.				  
		
			$result = $wpdb->get_results($postquery);
			foreach ($result as $post) {
				setup_postdata($post);  
				$postid = $post->ID;   
				if ($notify_is == 'on') {
					$postauthorid = $post->post_author;
					$postname = $post->post_title;
					TIEtools_send_notification($postauthorid, $postname, 'expiry');
				}	
				wp_delete_post($postid);
			}		
		}
	}
}
	
function TIEtools_expirebylikes() {

	// Requires WTI Like Post plugin, so check it's active.
	include_once( ABSPATH . 'wp-admin/includes/plugin.php' );	
	if (is_plugin_active('wti-like-post/wti_like_post.php')) {
		global $wpdb;

		// Get the user-defined number of days and likes.
		$numberoflikedays = (get_option('TIEexpire_likedays') != '') ? get_option('TIEexpire_likedays') : '0';
		$numberoflikes = (get_option('TIEexpire_likes') != '') ? get_option('TIEexpire_likes') : '0';
		$catstoinclude = (get_option('TIEexpire_catsin') != '') ? get_option('TIEexpire_catsin') : '0';
		$catstoexclude = (get_option('TIEexpire_catsout') != '') ? get_option('TIEexpire_catsout') : '0';
		$catsincludeon = (get_option('TIEexpire_catsradio') != '') ? get_option('TIEexpire_catsradio') : '' ;
		$catsinlikes = (get_option('TIEexpire_catslikes') != '') ? get_option('TIEexpire_catslikes') : '' ;

		// Get notification details and figure out if they are switched on.
		$notify_power = (get_option('TIEtools_notify_power') == 'on') ? 'on' : '';
		$notify_expiry = (get_option('TIEtools_notify_expiry') == 'on') ? 'on' : '' ;
		$notify_poster = (get_option('TIEtools_notify_poster') == 'on') ? 'on' : '' ;
		$notify_admin = (get_option('TIEtools_notify_admin') == 'on') ? 'on' : '' ;
		$notify_other = (get_option('TIEtools_notify_other') == 'on') ? 'on' : '' ;
		$notify_email = (get_option('TIEtools_notify_email') != '') ? get_option('TIEtools_notify_email') : '';
	
		if ($notify_power == 'on' && $notify_expiry == 'on' && ($notify_poster == 'on' || $notify_admin == 'on' || $notify_other == 'on' && $notify_email != '')) {
			$notify_is = 'on'; }
		else {
			$notify_is = 'off'; }
		
		// Get status options and build list for SQL query.
		$pub = (get_option('TIEexpire_pub') == 'publish') ? 'publish' : '';
		$dft = (get_option('TIEexpire_draft') == 'draft') ? 'draft' : '';
		$pen = (get_option('TIEexpire_pending') == 'pending') ? 'pending' : '';
		$prv = (get_option('TIEexpire_private') == 'private') ? 'private' : '';
		$expiry_statuslist = TIEtools_build_status_list($pub, $dft, $pen, $prv);			
		
		// Check the summary view exists and create it if not. Database prefix included for multiple blogs in one DB.
		// The first time this runs, there may be a slowdown in site service.
		// The check has to run every time in case the WTI plugin is activated between occurrences.
	
		$table_name = "{$wpdb->prefix}wti_totals";
		if($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
			$sql = "CREATE VIEW {$wpdb->prefix}wti_totals ( post_id, value ) 
				    AS SELECT post_id, SUM( value ) 
					FROM {$wpdb->prefix}wti_like_post
					GROUP BY post_id" ;
			$result = $wpdb->query($sql);
		}
	
		// If the user has defined the minimum number of likes as greater than zero, check for posts 
		// with no likes registered in given number of days (i.e. effectively zero) and Trash them.
		if ($numberoflikedays > 0 && $numberoflikes > 0) {
	
		// Trash all posts with no likes at all.
			$novotesquery = "SELECT * FROM $wpdb->posts
							 WHERE $wpdb->posts.post_type = 'post'
							 AND $wpdb->posts.post_status IN ($expiry_statuslist)
							 AND $wpdb->posts.post_date < DATE_SUB(NOW(), INTERVAL $numberoflikedays DAY)
							 AND $wpdb->posts.ID NOT IN (SELECT DISTINCT post_id FROM {$wpdb->prefix}wti_totals)" ;
							 
		// Check if category filter is on and, if so, check filter and apply it.
			if ($catsinlikes == 'on') {
				if ($catsincludeon == 'include' && $catstoinclude != '' && $catstoinclude != '0') {
					$novotesquery .= "AND $wpdb->posts.ID IN (SELECT DISTINCT object_id FROM $wpdb->term_relationships
									  WHERE $wpdb->term_relationships.term_taxonomy_id IN (" . $catstoinclude . "))";
				}
				elseif ($catsincludeon == 'exclude' && $catstoexclude != '' && $catstoexclude != '0') {	 
					$novotesquery .= "AND $wpdb->posts.ID NOT IN (SELECT DISTINCT object_id FROM $wpdb->term_relationships
									  WHERE $wpdb->term_relationships.term_taxonomy_id IN (" . $catstoexclude . "))";
				}
			}

	// Run query and move results to Trash.
			$result = $wpdb->get_results($novotesquery);
			foreach ($result as $post) {
				setup_postdata($post);  
				$postid = $post->ID;   
				if ($notify_is == 'on') {
					$postauthorid = $post->post_author;
					$postname = $post->post_title;
					TIEtools_send_notification($postauthorid, $postname, 'expiry');
				}	
				wp_delete_post($postid);
			}
		}
		
		// If the user has defined the minimum number of likes as non-zero (including negatives), 
		// check for posts with too few likes and Trash them.
		if ($numberoflikedays > 0 && $numberoflikes != 0) {
		
		// Trash all posts with too few likes in given number of days.
			$negvotesquery = "SELECT * FROM $wpdb->posts
							  INNER JOIN {$wpdb->prefix}wti_totals
								ON $wpdb->posts.ID = {$wpdb->prefix}wti_totals.post_id
							  WHERE $wpdb->posts.post_type = 'post'
							  AND $wpdb->posts.post_status IN ($expiry_statuslist)
							  AND $wpdb->posts.post_date < DATE_SUB(NOW(), INTERVAL $numberoflikedays DAY)
							  AND {$wpdb->prefix}wti_totals.value < $numberoflikes" ;
							 
		// Check if category filter is on and, if so, check filter and apply it.
			if ($catsinlikes == 'on') {
				if ($catsincludeon == 'include' && $catstoinclude != '' && $catstoinclude != '0') {
					$novotesquery .= "AND $wpdb->posts.ID IN (SELECT DISTINCT object_id FROM $wpdb->term_relationships
									  WHERE $wpdb->term_relationships.term_taxonomy_id IN (" . $catstoinclude . "))";
				}
				elseif ($catsincludeon == 'exclude' && $catstoexclude != '' && $catstoexclude != '0') {	 
					$novotesquery .= "AND $wpdb->posts.ID NOT IN (SELECT DISTINCT object_id FROM $wpdb->term_relationships
									  WHERE $wpdb->term_relationships.term_taxonomy_id IN (" . $catstoexclude . "))";
				}
			}

		// Run query and move results to Trash.
			$result = $wpdb->get_results($negvotesquery);
			foreach ($result as $post) {
				setup_postdata($post);  
				$postid = $post->ID;   
				if ($notify_is == 'on') {
					$postauthorid = $post->post_author;
					$postname = $post->post_title;
					TIEtools_send_notification($postauthorid, $postname, 'expiry');
				}	
				wp_delete_post($postid);
			}
		}
	}	
}

// Remove images from posts dependent upon age in days. Uses the post_date field.
function TIEtools_imagesexpire() { 
	global $wpdb;
	
	// Check image expiry is on and trash status.
	$images_expiry = (get_option('TIEtools_images_power') == 'on') ? 'on' : '';
	$images_trash = (get_option('TIEtools_images_trash') == 'on') ? 'on' : '';	
	
	if ($images_expiry == 'on') {
	
		// Get number of days, categories to include/exclude, settings for category filter and post status.
		$numberofdays = (get_option('TIEtools_images_days') != '') ? get_option('TIEtools_images_days') : '0';
		$catstoinclude = (get_option('TIEtools_images_catsin') != '') ? get_option('TIEtools_images_catsin') : '0';
		$catstoexclude = (get_option('TIEtools_images_catsout') != '') ? get_option('TIEtools_images_catsout') : '0';
		$catsincludeon = (get_option('TIEtools_images_catsradio') != '') ? get_option('TIEtools_images_catsradio') : '' ;
		$catsindays = (get_option('TIEtools_images_catsdays') != '') ? get_option('TIEtools_images_catsdays') : '' ;

		// Get status options and build list for SQL query.
		$pub = (get_option('TIEtools_images_status_published') == 'publish') ? 'publish' : '';
		$dft = (get_option('TIEtools_images_status_draft') == 'draft') ? 'draft' : '';
		$pen = (get_option('TIEtools_images_status_pending') == 'pending') ? 'pending' : '';
		$prv = (get_option('TIEtools_images_status_private') == 'private') ? 'private' : '';
		$expiry_statuslist = TIEtools_build_status_list($pub, $dft, $pen, $prv);
	
		// Find posts to process. Skip everything if days=0 (even if expiry switched on)
		if ($numberofdays > 0) {
			$imagesquery = "SELECT * FROM $wpdb->posts
							WHERE $wpdb->posts.post_type = 'attachment'
							AND $wpdb->posts.post_mime_type LIKE 'image%'
							AND $wpdb->posts.post_parent IN (
								SELECT ID FROM $wpdb->posts
								WHERE $wpdb->posts.post_status IN ($expiry_statuslist)
								AND $wpdb->posts.post_type = 'post'
								AND $wpdb->posts.post_date < DATE_SUB(NOW(), INTERVAL $numberofdays DAY)";
					 
		// Check if category filter is on and, if so, check filter and apply it.
			if ($catsindays	== 'on') {
				if ($catsincludeon == 'include' && $catstoinclude != '' && $catstoinclude != '0') {
					$imagesquery .= "AND $wpdb->posts.ID IN (SELECT DISTINCT object_id FROM $wpdb->term_relationships
									 WHERE $wpdb->term_relationships.term_taxonomy_id IN (" . $catstoinclude . ")))";
					}
				elseif ($catsincludeon == 'exclude' && $catstoexclude != '' && $catstoexclude != '0') {	 
						$imagesquery .= "AND $wpdb->posts.ID NOT IN (SELECT DISTINCT object_id FROM $wpdb->term_relationships
										 WHERE $wpdb->term_relationships.term_taxonomy_id IN (" . $catstoexclude . ")))";
				}
			}
			else {
				$imagesquery .= ")";
			}
	
		// Run query and process each post's images.
			$result = $wpdb->get_results($imagesquery);
			foreach ($result as $post) {
				setup_postdata($post);  
				$postid = $post->ID;
				$parentid = $post->post_parent;
				TIEtools_remove_images($parentid);
				if ($images_trash == 'on') {
					wp_delete_attachment($postid);
				}
				else {
					TIEtools_unattach_images($postid);
				}
			}
		}
	}
}	

// Remove all images (and their captions) from a post.
function TIEtools_remove_images($post_id) {
	global $wpdb;
	
	$contentquery = "SELECT post_content
					 FROM $wpdb->posts
					 WHERE $wpdb->posts.ID = $post_id";
	$original_content = $wpdb->get_var($contentquery);

	$captions_stripped = preg_replace('#'.preg_quote("[caption").'.*'.preg_quote("caption]").'#si', '', $original_content);
	$images_stripped = preg_replace('/<img[^>]+./','', $captions_stripped);
	$new_content = esc_sql($images_stripped);
	
	$contentquery = "UPDATE $wpdb->posts
					 SET post_content = '$new_content'
					 WHERE $wpdb->posts.ID = $post_id";
					 
	$update_content = $wpdb->query($contentquery);
}

// Set parent post to zero for an attachment post.
function TIEtools_unattach_images($post_id) {
	global $wpdb;
	
	$unquery = "UPDATE $wpdb->posts
				SET post_parent = 0
				WHERE $wpdb->posts.ID = $post_id";
	
	$unattach_images = $wpdb->query($unquery);
}

// Search for duplicate posts and move them to the Trash.
function TIEtools_dupesbytitle() {
		global $wpdb;
		
		// Get the parameters for the query from the options settings.
		$catstoinclude = (get_option('TIEdupedeleter_catsin') != '') ? get_option('TIEdupedeleter_catsin') : '0';
		$catstoexclude = (get_option('TIEdupedeleter_catsout') != '') ? get_option('TIEdupedeleter_catsout') : '0';
		$catsoption = get_option('TIEdupedeleter_catsradio');
		$oldnewradio = get_option('TIEdupedeleter_newoldradio');
		
		// Get notification details and figure out if they are switched on.
		$notify_power = (get_option('TIEtools_notify_power') == 'on') ? 'on' : '';
		$notify_dupes = (get_option('TIEtools_notify_dupes') == 'on') ? 'on' : '' ;
		$notify_poster = (get_option('TIEtools_notify_poster') == 'on') ? 'on' : '' ;
		$notify_admin = (get_option('TIEtools_notify_admin') == 'on') ? 'on' : '' ;
		$notify_other = (get_option('TIEtools_notify_other') == 'on') ? 'on' : '' ;
		$notify_email = (get_option('TIEtools_notify_email') != '') ? get_option('TIEtools_notify_email') : '';
	
		if ($notify_power == 'on' && $notify_dupes == 'on' && ($notify_poster == 'on' || $notify_admin == 'on' || $notify_other == 'on' && $notify_email != '')) {
			$notify_is = 'on'; }
		else {
			$notify_is = 'off'; }
	
		// Get status options and build list for SQL query.
		$pub = (get_option('TIEdupedeleter_status_published') == 'publish') ? 'publish' : '' ;
		$dft = (get_option('TIEdupedeleter_status_draft') == 'draft') ? 'draft' : '' ;
		$pen = (get_option('TIEdupedeleter_status_pending') == 'pending') ? 'pending' : '' ;
		$prv = (get_option('TIEdupedeleter_status_private') == 'private') ? 'private' : '' ;
		$dupes_statuslist = TIEtools_build_status_list($pub, $dft, $pen, $prv);
		
		// Build query to find duplicate posts by title.
		$dupequery = "SELECT dupeposts.* FROM $wpdb->posts AS dupeposts
					  INNER JOIN (SELECT $wpdb->posts.post_title, $oldnewradio( $wpdb->posts.ID ) AS keepthisone
						FROM $wpdb->posts
						WHERE $wpdb->posts.post_type = 'post'
						AND $wpdb->posts.post_status IN ($dupes_statuslist) ";
						
		// Check for category filter to apply.
		if ($catsoption == 'include' && $catstoinclude != '' && $catstoinclude != '0') {
			$dupequery .= "AND $wpdb->posts.ID IN (SELECT DISTINCT object_id FROM $wpdb->term_relationships
						  WHERE $wpdb->term_relationships.term_taxonomy_id IN (" . $catstoinclude . ")) ";
		}
		elseif ($catsoption == 'exclude' && $catstoexclude != '' && $catstoexclude != '0') {	 
			$dupequery .= "AND $wpdb->posts.ID NOT IN (SELECT DISTINCT object_id FROM $wpdb->term_relationships
						  WHERE $wpdb->term_relationships.term_taxonomy_id IN (" . $catstoexclude . ")) ";
		}

		// Continue query construction.
		$dupequery .= "  GROUP BY post_title
					     HAVING COUNT( * ) >1 ) AS compareposts 
					   ON ( compareposts.post_title = dupeposts.post_title
					   AND compareposts.keepthisone <> dupeposts.ID )
					   WHERE dupeposts.post_type = 'post'
					   AND dupeposts.post_status IN ($dupes_statuslist)";

		// Check for category filter to apply.
		if ($catsoption == 'include' && $catstoinclude != '' && $catstoinclude != '0') {
			$dupequery .= "AND dupeposts.ID IN (SELECT DISTINCT object_id FROM $wpdb->term_relationships
						  WHERE $wpdb->term_relationships.term_taxonomy_id IN (" . $catstoinclude . "))";
		}
		elseif ($catsoption == 'exclude' && $catstoexclude != '' && $catstoexclude != '0') {	 
			$dupequery .= "AND dupeposts.ID NOT IN (SELECT DISTINCT object_id FROM $wpdb->term_relationships
						  WHERE $wpdb->term_relationships.term_taxonomy_id IN (" . $catstoexclude . "))";
		}			
		
		// Run query and move results to Trash.
		$result = $wpdb->get_results($dupequery);
		foreach ($result as $post) {
			setup_postdata($post);  
			$postid = $post->ID;   
			if ($notify_is == 'on') {
				$postauthorid = $post->post_author;
				$postname = $post->post_title;
				TIEtools_send_notification($postauthorid, $postname, 'dupes');
			}	
			wp_delete_post($postid);
		}
}

function TIEtools_logremover() {
	$logs_power = (get_option('TIEtools_logs_power') == 'on') ? 'on' : 'off';
	$log_filename = (get_option('TIEtools_logs_filename') != '') ? get_option('TIEtools_logs_filename') : '';

	// If the log remover is switched on and filename is not blank, remove logs.
	if ($logs_power == 'on' && $log_filename != '') {
		unlink(ABSPATH . $log_filename);
		unlink(ABSPATH . 'wp-admin/' . $log_filename);
		unlink(ABSPATH . 'wp-content/' . $log_filename);
	}
}
	
function TIEtools_option_settings() {

	// Get all the user-defined options for all tool and set defaults if they don't exist.
	// Post expiry options first.
	$expiry_poweron = (get_option('TIEtools_expiry_power') == 'on') ? 'checked' : '';
	$expiry_poweroff = (get_option('TIEtools_expiry_power') != 'on') ? 'checked' : '';
	$expiry_power_class = (get_option('TIEtools_expiry_power') == 'on') ? 'onoptibox' : 'offoptibox';
	$expiry_status_published = (get_option('TIEexpire_pub') == 'publish') ? 'checked' : '';
	$expiry_status_draft = (get_option('TIEexpire_draft') == 'draft') ? 'checked' : '';
	$expiry_status_pending = (get_option('TIEexpire_pending') == 'pending') ? 'checked' : '';
	$expiry_status_private = (get_option('TIEexpire_private') == 'private') ? 'checked' : '';
	$expiry_catstoinclude = (get_option('TIEexpire_catsin') != '') ? get_option('TIEexpire_catsin') : '0';
	$expiry_catstoexclude = (get_option('TIEexpire_catsout') != '') ? get_option('TIEexpire_catsout') : '0';
	$expiry_catsincludeon = (get_option('TIEexpire_catsradio') == 'include') ? 'checked' : '';
	$expiry_catsexcludeon = (get_option('TIEexpire_catsradio') == 'exclude') ? 'checked' : '';
	$expiry_catsindays = (get_option('TIEexpire_catsdays') == 'on') ? 'checked' : '';
	$expiry_catsinposts = (get_option('TIEexpire_catsposts') == 'on') ? 'checked' : '';
	$expiry_catsinviews = (get_option('TIEexpire_catsviews') == 'on') ? 'checked' : '';
	$expiry_catsinlikes = (get_option('TIEexpire_catslikes') == 'on') ? 'checked' : '';
    $expiry_numberofdays = (get_option('TIEexpire_days') != '') ? get_option('TIEexpire_days') : '0';
	$expiry_numberofposts = (get_option('TIEexpire_posts') != '') ? get_option('TIEexpire_posts') : '0';
	$expiry_numberofviewdays = (get_option('TIEexpire_viewdays') != '') ? get_option('TIEexpire_viewdays') : '0';
	$expiry_numberofviews = (get_option('TIEexpire_views') != '') ? get_option('TIEexpire_views') : '0';
	$expiry_numberoflikedays = (get_option('TIEexpire_likedays') != '') ? get_option('TIEexpire_likedays') : '0';
	$expiry_numberoflikes = (get_option('TIEexpire_likes') != '') ? get_option('TIEexpire_likes') : '0';
	
	// Now the dupe deleter options.
	$dupes_poweron = (get_option('TIEdupedeleter_powerbutton') == 'on') ? 'checked' : '';
	$dupes_poweroff = (get_option('TIEdupedeleter_powerbutton') != 'on') ? 'checked' : '';
	$dupes_power_class = (get_option('TIEdupedeleter_powerbutton') == 'on') ? 'onoptibox' : 'offoptibox';
	$dupes_status_published = (get_option('TIEdupedeleter_status_published') == 'publish') ? 'checked' : '';
	$dupes_status_draft = (get_option('TIEdupedeleter_status_draft') == 'draft') ? 'checked' : '';
	$dupes_status_pending = (get_option('TIEdupedeleter_status_pending') == 'pending') ? 'checked' : '';
	$dupes_status_private = (get_option('TIEdupedeleter_status_private') == 'private') ? 'checked' : '';
	$dupes_catstoinclude = (get_option('TIEdupedeleter_catsin') != '') ? get_option('TIEdupedeleter_catsin') : '0';
	$dupes_catstoexclude = (get_option('TIEdupedeleter_catsout') != '') ? get_option('TIEdupedeleter_catsout') : '0';
	$dupes_catsincludeon = (get_option('TIEdupedeleter_catsradio') == 'include') ? 'checked' : '';
	$dupes_catsexcludeon = (get_option('TIEdupedeleter_catsradio') == 'exclude') ? 'checked' : '';
	$dupes_newbutton = (get_option('TIEdupedeleter_newoldradio') == 'MAX') ? 'checked' : '';
	$dupes_oldbutton = (get_option('TIEdupedeleter_newoldradio') == 'MIN') ? 'checked' : '';

	// Now the log remover options.
	$logs_poweron = (get_option('TIEtools_logs_power') == 'on') ? 'checked' : '';
	$logs_poweroff = (get_option('TIEtools_logs_power') != 'on') ? 'checked' : '';
	$logs_power_class = (get_option('TIEtools_logs_power') == 'on') ? 'onoptibox' : 'offoptibox';
	$logs_filename = (get_option('TIEtools_logs_filename') != '') ? get_option('TIEtools_logs_filename') : '';
	
	// Now the notification options.
	$notify_poweron = (get_option('TIEtools_notify_power') == 'on') ? 'checked' : '';
	$notify_poweroff = (get_option('TIEtools_notify_power') != 'on') ? 'checked' : '';
	$notify_power_class = (get_option('TIEtools_notify_power') == 'on') ? 'onoptibox' : 'offoptibox';	
	$notify_poster = (get_option('TIEtools_notify_poster') == 'on') ? 'checked' : '' ;
	$notify_admin = (get_option('TIEtools_notify_admin') == 'on') ? 'checked' : '' ;
	$notify_other = (get_option('TIEtools_notify_other') == 'on') ? 'checked' : '' ;
	$notify_email = (get_option('TIEtools_notify_email') != '') ? get_option('TIEtools_notify_email') : '';
	$notify_expiry = (get_option('TIEtools_notify_expiry') == 'on') ? 'checked' : '' ;
	$notify_dupes = (get_option('TIEtools_notify_dupes') == 'on') ? 'checked' : '' ;

	// Now the image removal options.
	$images_poweron = (get_option('TIEtools_images_power') == 'on') ? 'checked' : '';
	$images_poweroff = (get_option('TIEtools_images_power') != 'on') ? 'checked' : '';
	$images_power_class = (get_option('TIEtools_images_power') == 'on') ? 'onoptibox' : 'offoptibox';
	$images_numberofdays = (get_option('TIEtools_images_days') != '') ? get_option('TIEtools_images_days') : '0';
	$images_status_published = (get_option('TIEtools_images_status_published') == 'publish') ? 'checked' : '';
	$images_status_draft = (get_option('TIEtools_images_status_draft') == 'draft') ? 'checked' : '';
	$images_status_pending = (get_option('TIEtools_images_status_pending') == 'pending') ? 'checked' : '';
	$images_status_private = (get_option('TIEtools_images_status_private') == 'private') ? 'checked' : '';
	$images_catstoinclude = (get_option('TIEtools_images_catsin') != '') ? get_option('TIEtools_images_catsin') : '0';
	$images_catstoexclude = (get_option('TIEtools_images_catsout') != '') ? get_option('TIEtools_images_catsout') : '0';
	$images_catsincludeon = (get_option('TIEtools_images_catsradio') == 'include') ? 'checked' : '';
	$images_catsexcludeon = (get_option('TIEtools_images_catsradio') == 'exclude') ? 'checked' : '';
	$images_trash = (get_option('TIEtools_images_trash') == 'on') ? 'checked' : '';
	$images_catsindays = (get_option('TIEtools_images_catsdays') == 'on') ? 'checked' : '';
	
	// Draw the page title and donation section.
	$plugname = '</pre><div class="wrap">
				 <link href="' . plugins_url( 'TIEstyle.css' , __FILE__ ) . '" type="text/css" rel="stylesheet" />
				 <h2><img src="' . plugins_url( 'tietools.png' , __FILE__ ) . '" border=0 alt="TIEtools" style="vertical-align:middle"> TIEtools Automatic Maintenance Settings</h2>
				 <form action="options.php" method="post" name="options">' . wp_nonce_field('update-options') . '
				 <p>&nbsp;&nbsp;If TIEtools is useful for your site maintenance, please help by taking a moment to <a href="http://wordpress.org/plugins/tietools-automatic-maintenance-kit" target="_blank">rate or review it</a>. Thanks!&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<input type="submit" name="submit" id="submit" class="button button-primary" value="Save Changes">
				 ';
				 
	$topline = '<p>
				<div id="' . $expiry_power_class . '">
				<div class="onoffoptibox_title">Post Expiry</div>
				<p><label><input type="radio" name="TIEtools_expiry_power" id="on" value="on"' . $expiry_poweron . ' />&nbsp;On</label>
				&nbsp;&nbsp;&nbsp;&nbsp;<label><input type="radio" name="TIEtools_expiry_power" id="off" value="off"' . $expiry_poweroff . ' />&nbsp;Off</label>
				</div>
				<div id="' . $images_power_class . '">
				<div class="onoffoptibox_title">Image Expiry</div>
				<p><label><input type="radio" name="TIEtools_images_power" id="on" value="on"' . $images_poweron . ' />&nbsp;On</label>
				&nbsp;&nbsp;&nbsp;&nbsp;<label><input type="radio" name="TIEtools_images_power" id="off" value="off"' . $images_poweroff . ' />&nbsp;Off</label>
				</div>
				<div id="' . $dupes_power_class . '">
				<div class="onoffoptibox_title">Dupe Checks</div>
				<p><label><input type="radio" name="TIEdupedeleter_powerbutton" id="on" value="on"' . $dupes_poweron . ' />&nbsp;On</label>
				&nbsp;&nbsp;&nbsp;&nbsp;<label><input type="radio" name="TIEdupedeleter_powerbutton" id="off" value="off"' . $dupes_poweroff . ' />&nbsp;Off</label>
				</div>
				<div id="' . $notify_power_class . '">
				<div class="onoffoptibox_title">Notifications</div>
				<p><label><input type="radio" name="TIEtools_notify_power" id="on" value="on"' . $notify_poweron . ' />&nbsp;On</label>
				&nbsp;&nbsp;&nbsp;&nbsp;<label><input type="radio" name="TIEtools_notify_power" id="off" value="off"' . $notify_poweroff . ' />&nbsp;Off</label>
				</div>
				<div id="' . $logs_power_class . '">
				<div class="onoffoptibox_title">Log Deletion</div>
				<p><label><input type="radio" name="TIEtools_logs_power" id="on" value="on"' . $logs_poweron . ' />&nbsp;On</label>
				&nbsp;&nbsp;&nbsp;&nbsp;<label><input type="radio" name="TIEtools_logs_power" id="off" value="off"' . $logs_poweroff . ' />&nbsp;Off</label>				
				</div>
				<div style="clear:both">' ;
				
	// Build main part of the page, section by section.
	// Post expiry first.
	$exphtml = '<div id="oneoptibox">
				<p class="boxtitle">Post Expiry Settings
				<p><img src="' . plugins_url( 'info.png' , __FILE__ ) . '" border=0 alt="info" style="vertical-align:baseline">These options determine which posts are expired. Processed left to right, cumulative. Set zeroes to switch off a check. <a href="http://wordpress.org/plugins/tieexpire-automated-post-expiry/faq/" target="_blank">FAQ</a>
				<div id="fouroptibox">
				<p><label class="biglabel">Older than</label>
				<p><input class="biglabel" type="number" name="TIEexpire_days" min="0" value="' . $expiry_numberofdays . '" /> 
				<p class="biglabel">days old
				<p><label><input type="checkbox" name="TIEexpire_catsdays" value="on"' . $expiry_catsindays . ' />&nbsp;Use category filters</label>
				</div>
				<div id="fouroptibox">
				<p><label class="biglabel">Keep newest</label>
				<p><input class="biglabel" type="number" name="TIEexpire_posts" min="0" value="' . $expiry_numberofposts . '" />
				<p class="biglabel">posts
				<p><label><input type="checkbox" name="TIEexpire_catsposts" value="on"' . $expiry_catsinposts . ' />&nbsp;Use category filters</label>
				</div>';
				
	// Check whether BAW Post Views Count is installed by detecting the bawpv.php file and, if so, show the options.
	if (is_plugin_active('baw-post-views-count/bawpv.php')) {

		$exphtml .='<div id="fouroptibox">
					<p><label class="biglabel">Older than</label>
					<p><input class="biglabel" type="number" name="TIEexpire_viewdays" min="0" value="' . $expiry_numberofviewdays . '" />
					<p class="biglabel">days old with
					<p><input class="biglabel" type="number" name="TIEexpire_views" min="0" value="' . $expiry_numberofviews . '" />
					<p><label class="biglabel">minimum views</label>
					<p><label><input type="checkbox" name="TIEexpire_catsviews" value="on" ' . $expiry_catsinviews . '/>&nbsp;Use category filters</label>
					</div>';
	}
	else {
		$exphtml .='<div id="fouroptibox">
					<p class="biglabel">Post views<p class="biglabel">plugin not active
					<p><a href="http://wordpress.org/plugins/baw-post-views-count/" target="_blank">link</a>
					</div>';
	}
	
	// Check whether WTI Like Post is installed by detecting the wti_like_post.php file and, if so, show the options.
	if (is_plugin_active('wti-like-post/wti_like_post.php')) {

		$exphtml .='<div id="fouroptibox">
					<p><label class="biglabel">Older than</label>
					<p><input class="biglabel" type="number" name="TIEexpire_likedays" min="0" value="' . $expiry_numberoflikedays . '" />
					<p class="biglabel">days old with
					<p><input class="biglabel" type="number" name="TIEexpire_likes" value="' . $expiry_numberoflikes . '" />
					<p><label class="biglabel">minimum likes</label>
					<p><label><input type="checkbox" name="TIEexpire_catslikes" value="on" ' . $expiry_catsinlikes . '/>&nbsp;Use category filters</label>
					</div>';
	}				
	else {
		$exphtml .='<div id="fouroptibox">
					<p class="biglabel">Post likes<p class="biglabel">plugin not active
					<p><a href="http://wordpress.org/plugins/wti-like-post/" target="_blank">link</a>
					</div>';
	}
	
	$exphtml .='<div id="twooptibox">
				<strong>Post Status Options</strong>
				<p><label><input type="checkbox" name="TIEexpire_pub" value="publish" ' . $expiry_status_published . ' />&nbsp;Published</label>
				<br><label><input type="checkbox" name="TIEexpire_draft" value="draft" ' . $expiry_status_draft . ' />&nbsp;Draft</label>
				<br><label><input type="checkbox" name="TIEexpire_pending" value="pending" ' . $expiry_status_pending . ' />&nbsp;Pending</label>
				<br><label><input type="checkbox" name="TIEexpire_private" value="private" ' . $expiry_status_private . ' />&nbsp;Private</label>
				<p><img src="' . plugins_url( 'info.png' , __FILE__ ) . '" border=0 alt="info" style="vertical-align:baseline">Status filters are applied in all expiry checks.
				</div>
				<div id="twooptibox">
				<strong>Category Options</strong>
				<p><label><input type="radio" name="TIEexpire_catsradio" id="include" value="include"' . $expiry_catsincludeon . ' />&nbsp;Include these categories: </label>
				&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<input type="text" name="TIEexpire_catsin" size=10 value="' . $expiry_catstoinclude . '" />
				<br><label><input type="radio" name="TIEexpire_catsradio" id="exclude" value="exclude"' . $expiry_catsexcludeon . ' />&nbsp;Exclude these categories: </label>
				&nbsp;&nbsp;&nbsp;&nbsp;<input type="text" name="TIEexpire_catsout" size=10 value="' . $expiry_catstoexclude . '" />
				<p><img src="' . plugins_url( 'info.png' , __FILE__ ) . '" border=0 alt="info" style="vertical-align:baseline">Enter category numbers as a comma-separated list
				<br>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;(e.g. 1,4,2,17).
				</div>
				<div style="clear:both"></div>
				</div>';

	// Image expiry next.
	
	$imghtml = '<div id="oneoptibox">
				<p class="boxtitle">Image Expiry Settings
				<p><img src="' . plugins_url( 'info.png' , __FILE__ ) . '" border=0 alt="info" style="vertical-align:baseline">These options determine when images are removed from posts and whether unattached images are deleted.</a>
				<div id="twooptibox">
				<p><center><label class="biglabel">Remove images from posts</label>
				<p class="biglabel">over&nbsp;<input class="biglabel" type="number" name="TIEtools_images_days" min="0" value="' . $images_numberofdays . '" />&nbsp;days old</center>
				<p><label><input type="checkbox" name="TIEtools_images_catsdays" value="on"' . $images_catsindays . ' />&nbsp;Use category filters</label>
				<p><label><input type="checkbox" name="TIEtools_images_trash" value="on"' . $images_trash . ' />&nbsp;Delete expired images</label>
				<p><img src="' . plugins_url( 'info.png' , __FILE__ ) . '" border=0 alt="info" style="vertical-align:baseline">Warning: deleting expired images removes them
				<br>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;immediately - use with care!
				</div>
				<div id="twooptibox">
				<strong>Post Status Options</strong>
				<p><label><input type="checkbox" name="TIEtools_images_status_published" value="publish"' . $images_status_published . ' />&nbsp;Published</label>
				&nbsp;&nbsp;&nbsp;&nbsp;<label><input type="checkbox" name="TIEtools_images_status_draft" value="draft"' . $images_status_draft . ' />&nbsp;Draft</label>
				&nbsp;&nbsp;&nbsp;&nbsp;<label><input type="checkbox" name="TIEtools_images_status_pending" value="pending"' . $images_status_pending . ' />&nbsp;Pending</label>
				&nbsp;&nbsp;&nbsp;&nbsp;<label><input type="checkbox" name="TIEtools_images_status_private" value="private"' . $images_status_private . ' />&nbsp;Private</label>
				<p><strong>Category Options</strong>
				<p><label><input type="radio" name="TIEtools_images_catsradio" id="include" value="include"' . $images_catsincludeon . ' />&nbsp;Include these categories: </label>
				&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<input type="text" name="TIEtools_images_catsin" size=10 value="' . $images_catstoinclude . '" />
				<br><label><input type="radio" name="TIEtools_images_catsradio" id="exclude" value="exclude"' . $images_catsexcludeon . ' />&nbsp;Exclude these categories: </label>
				&nbsp;&nbsp;&nbsp;&nbsp;<input type="text" name="TIEtools_images_catsout" size=10 value="' . $images_catstoexclude . '" />
				<p><img src="' . plugins_url( 'info.png' , __FILE__ ) . '" border=0 alt="info" style="vertical-align:baseline">Enter category numbers as a comma-separated list
				<br>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;(e.g. 1,4,2,17).
				</div>
				<div style="clear:both"></div>
				</div>';
				
				
	// Duplicate post deletion next.
	$dupehtml= '<div id="oneoptibox">
				<p class="boxtitle">Duplicate Post Settings
				<p><img src="' . plugins_url( 'info.png' , __FILE__ ) . '" border=0 alt="info" style="vertical-align:baseline">These options determine which posts are checked for duplicate titles. <a href="http://wordpress.org/plugins/tiedupedeleter-simple-duplicate-post-deleter/faq/" target="_blank">FAQ</a>
				<div id="twooptibox">
				<strong>Post Options</strong>
				<p><label><input type="radio" name="TIEdupedeleter_newoldradio" id="MIN" value="MIN"' . $dupes_oldbutton . ' />&nbsp;Keep oldest copy</label>
				<br><label><input type="radio" name="TIEdupedeleter_newoldradio" id="MAX" value="MAX"' . $dupes_newbutton . ' />&nbsp;Keep newest copy</label>
				<p><label><input type="checkbox" name="TIEdupedeleter_status_published" value="publish"' . $dupes_status_published . ' />&nbsp;Published</label>
				&nbsp;&nbsp;&nbsp;&nbsp;<label><input type="checkbox" name="TIEdupedeleter_status_draft" value="draft"' . $dupes_status_draft . ' />&nbsp;Draft</label>
				&nbsp;&nbsp;&nbsp;&nbsp;<label><input type="checkbox" name="TIEdupedeleter_status_pending" value="pending"' . $dupes_status_pending . ' />&nbsp;Pending</label>
				&nbsp;&nbsp;&nbsp;&nbsp;<label><input type="checkbox" name="TIEdupedeleter_status_private" value="private"' . $dupes_status_private . ' />&nbsp;Private</label>
				<p><img src="' . plugins_url( 'info.png' , __FILE__ ) . '" border=0 alt="info" style="vertical-align:baseline">Pages, attachments and custom types are not checked.
				</div>
				<div id="twooptibox">
				<strong>Category Options</strong>
				<p><label><input type="radio" name="TIEdupedeleter_catsradio" id="include" value="include"' . $dupes_catsincludeon . ' />&nbsp;Include these categories: </label>
				&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<input type="text" name="TIEdupedeleter_catsin" size=10 value="' . $dupes_catstoinclude . '" />
				<br><label><input type="radio" name="TIEdupedeleter_catsradio" id="exclude" value="exclude"' . $dupes_catsexcludeon . ' />&nbsp;Exclude these categories: </label>
				&nbsp;&nbsp;&nbsp;&nbsp;<input type="text" name="TIEdupedeleter_catsout" size=10 value="' . $dupes_catstoexclude . '" />
				<p><img src="' . plugins_url( 'info.png' , __FILE__ ) . '" border=0 alt="info" style="vertical-align:baseline">Enter category numbers as a comma-separated list
				<br>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;(e.g. 1,4,2,17).
				</div>
				<div style="clear:both"></div>
				</div>';
	
	$notihtml ='<div id="oneoptibox">
				<table border=0><tr><td width=40%>
				<p class="boxtitle">Notification Settings
				<p><img src="' . plugins_url( 'info.png' , __FILE__ ) . '" border=0 alt="info" style="vertical-align:baseline">Email for site admin is on the General Settings page.
				</td><td width=40%>
				<p class="boxtitle">Log File Settings
				<p><img src="' . plugins_url( 'info.png' , __FILE__ ) . '" border=0 alt="info" style="vertical-align:baseline">This option determines which files are removed. <a href="http://wordpress.org/plugins/tielogremover/faq/" target="_blank">FAQ</a>
				</td></tr></table>
				<div id="twooptibox">
				<p>Notify when&nbsp;&nbsp;&nbsp;&nbsp;<label><input type="checkbox" name="TIEtools_notify_expiry" value="on" ' . $notify_expiry . ' />&nbsp;Post expired</label>
				&nbsp;&nbsp;&nbsp;&nbsp;<label><input type="checkbox" name="TIEtools_notify_dupes" value="on" ' . $notify_dupes . ' />&nbsp;Duplicate removed</label>
				<p><strong>Send Emails To</strong>
				<p><label><input type="checkbox" name="TIEtools_notify_poster" value="on"' . $notify_poster . ' />&nbsp;Author</label>
				&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<label><input type="checkbox" name="TIEtools_notify_other" value="on"' . $notify_other . ' />&nbsp;Someone else (enter email)</label>
				<br><label><input type="checkbox" name="TIEtools_notify_admin" value="on"' . $notify_admin . ' />&nbsp;Site admin</label>
				&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<label><input type="text" name="TIEtools_notify_email" size=25 value="' . $notify_email . '" />&nbsp;</label>
				</div>';
				
	// And finally the log file remover.				
	$loghtml = '<div id="twooptibox">
				<strong>Error Log Filename</strong>
				<p><input type="text" name="TIEtools_logs_filename" size=20 value="' . $logs_filename . '" />
				<p><img src="' . plugins_url( 'info.png' , __FILE__ ) . '" border=0 alt="info" style="vertical-align:baseline">Files are deleted in WP root, wp-admin and wp-content.
				</div>
				<div style="clear:both"></div>';
	
	$endhtml = '</div>
				<input type="hidden" name="action" value="update" />
				<input type="hidden" name="page_options" value="
				TIEexpire_days, TIEexpire_posts, TIEexpire_viewdays, 
				TIEexpire_views, TIEexpire_likedays, TIEexpire_likes, 
				TIEexpire_catsin, TIEexpire_catsout, TIEexpire_catsdays, 
				TIEexpire_catsposts, TIEexpire_catsviews, TIEexpire_catslikes, 
				TIEexpire_catsradio, TIEexpire_pub, TIEexpire_draft, 
				TIEexpire_pending, TIEexpire_private, TIEtools_expiry_power,
				TIEdupedeleter_status_published, TIEdupedeleter_status_draft, TIEdupedeleter_status_pending,
				TIEdupedeleter_status_private, TIEdupedeleter_catsin, TIEdupedeleter_catsout, 
				TIEdupedeleter_catsradio, TIEdupedeleter_powerbutton, TIEdupedeleter_newoldradio,
				TIEtools_logs_power, TIEtools_logs_filename, TIEtools_notify_power,
				TIEtools_notify_poster, TIEtools_notify_admin, TIEtools_notify_other,
				TIEtools_notify_email, TIEtools_notify_expiry, TIEtools_notify_dupes,
				TIEtools_images_power, TIEtools_images_days, TIEtools_images_status_published,
				TIEtools_images_status_draft, TIEtools_images_status_pending, TIEtools_images_status_private, 
				TIEtools_images_catsin, TIEtools_images_catsout, TIEtools_images_catsradio,
				TIEtools_images_trash, TIEtools_images_catsdays
				" />
				<p class="submit"><input type="submit" name="submit" id="submit" class="button button-primary" value="Save Changes"></form></div>
				<div style="clear:both">
				<pre>';
	
	// Display the topline and page HTML. The IF part shows the "Settings saved" line when appropriate.
	echo $plugname;
	if( isset($_GET['settings-updated']) ) {
		echo '<p style="max-width:60%;background-color:#FFFFE0;border-color:#e6db55;border-style:solid;border-width:1px;padding:3px;line-height:200%;">All settings saved.' ;
	}
	echo $topline;
	echo $exphtml;
	echo $imghtml;
	echo $dupehtml;
	echo $notihtml;
	echo $loghtml;
	echo $endhtml;
}

function TIEtools_send_notification($the_post_author_ID, $the_post_title, $section) {
	$notify_poster = (get_option('TIEtools_notify_poster') == 'on') ? 'on' : '' ;
	$notify_admin = (get_option('TIEtools_notify_admin') == 'on') ? 'on' : '' ;
	$notify_other = (get_option('TIEtools_notify_other') == 'on') ? 'on' : '' ;
	$notify_email = (get_option('TIEtools_notify_email') != '') ? get_option('TIEtools_notify_email') : '';

	// Set some email parameters.
	$postauthor = get_userdata($the_post_author_ID)->user_nicename;
	$emailsubject = 'Post expired';

	// The email text is held in $notifytext and is different for each addressee.
	
	// Email to post author
	if ($notify_poster == 'on') {
		$sendemailto = get_userdata($the_post_author_ID)->user_email;
		$notifytext =  "Hello " . $postauthor . ",\n\nThis is an automated message from " . get_option('blogname') . " to inform you that the post titled " . $the_post_title . " has expired. Please contact the site admin at " . get_bloginfo('admin_email') . " if you believe there has been a mistake.\n\n";
		if ($section == 'dupes') {
			$notifytext .= "The post was expired because it was detected as a duplicate.\n\n";
		}
		$notifytext .= "Message generated by TIEexpire for " . get_option('siteurl');
		wp_mail($sendemailto, $emailsubject, $notifytext);
	}

	// Email to site admin
	if ($notify_admin == 'on') {
		$sendemailto = get_bloginfo('admin_email');
		$notifytext =  "Hello Admin,\n\nThis is an automated message from " . get_option('blogname') . " to inform you that the post titled " . $the_post_title . " by " . $postauthor . " has expired.\n\n";
		if ($section == 'dupes') {
			$notifytext .= "The post was expired because it was detected as a duplicate.\n\n";
		}
		$notifytext .= "Message generated by TIEexpire for " . get_option('siteurl');
		wp_mail($sendemailto, $emailsubject, $notifytext);
	}

	// Email to whoever else
	if ($notify_other == 'on' && notify_email != '') {
		$sendemailto = $notify_email;
		$notifytext =  "Hello,\n\nThis is an automated message from " . get_option('blogname') . " to inform you that the post titled " . $the_post_title . " by " . $postauthor . " has expired. Please contact admin if that's a mistake.\n\n";
		if ($section == 'dupes') {
			$notifytext .= "The post was expired because it was detected as a duplicate.\n\n";
		}
		$notifytext .= "Message generated by TIEexpire for " . get_option('siteurl');
		wp_mail($sendemailto, $emailsubject, $notifytext);
	}
}