<?php
/*Plugin Name: Community Events
Plugin URI: https://ylefebvre.github.io/wordpress-plugins/community-events/
Description: A plugin used to manage events and display them in a widget
Version: 1.5.1
Author: Yannick Lefebvre
Author URI: https://ylefebvre.github.io
Copyright 2024  Yannick Lefebvre  (email : ylefebvre@gmail.com)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA*/

define( 'COMMUNITY_EVENTS_ADMIN_PAGE_NAME', 'community-events' );

require_once plugin_dir_path( __FILE__ ) . 'rssfeed.php';

//class that reperesent the complete plugin
class community_events_plugin {

	//constructor of class, PHP4 compatible construction for backward compatibility
	function __construct() {

		$this->cepluginpath = WP_CONTENT_URL.'/plugins/'.plugin_basename(dirname(__FILE__)).'/';

		load_plugin_textdomain( 'community-events', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/');

		$options = get_option('CE_PP');

		if ($options['schemaversion'] < 0.3)
			$this->ce_install();

		//add filter for WordPress 2.8 changed backend box system !
		add_filter('screen_layout_columns', array($this, 'on_screen_layout_columns'), 10, 2);
		//register callback for admin menu  setup
		add_action('admin_menu', array($this, 'on_admin_menu'));
		//register the callback been used if options of page been submitted and needs to be processed
		add_action('admin_post_save_community_events_general', array($this, 'on_save_changes_general'));
		add_action('admin_post_save_community_events_event_types', array($this, 'on_save_changes_eventtypes'));
		add_action('admin_post_save_community_events_venues', array($this, 'on_save_changes_venues'));
		add_action('admin_post_save_community_events_events', array($this, 'on_save_changes_events'));
		add_action('admin_post_save_community_events_stylesheet', array($this, 'on_save_changes_stylesheet'));

		add_action( 'wp_ajax_community_events_frontend_list', array( $this, 'ajax_frontend_event_list' ) );
		add_action( 'wp_ajax_nopriv_community_events_frontend_list', array( $this, 'ajax_frontend_event_list' ) );

		add_action( 'wp_ajax_community_events_admin_list', array( $this, 'ajax_admin_event_list' ) );
		add_action( 'wp_ajax_nopriv_community_events_admin_list', array( $this, 'ajax_admin_event_list' ) );

		add_action( 'wp_ajax_community_events_click_tracker', array( $this, 'ajax_click_tracker' ) );
		add_action( 'wp_ajax_nopriv_community_events_click_tracker', array( $this, 'ajax_click_tracker' ) );

		add_action( 'wp_ajax_community_events_approval', array( $this, 'ajax_admin_event_approval' ) );
		add_action( 'wp_ajax_nopriv_community_events_approval', array( $this, 'ajax_admin_event_approval' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'load_scripts' ) );

        if ( is_admin() && isset( $_GET['page'] ) && $_GET['page'] == 'community-events-events' ) {
            add_action( 'admin_enqueue_scripts', array( $this, 'load_scripts' ) );
        }

		add_shortcode('community-events-7day', array($this, 'ce_7day_func'));
		add_shortcode('community-events-full', array($this, 'ce_full_func'));
		add_shortcode('community-events-addevent', array($this, 'ce_addevent_func'));

		add_action('wp_head', array($this, 'ce_header'));

		add_action( 'init', array( $this, 'links_rss' ) );

		add_action('ce_daily_event', array($this, 'ce_daily_cleanup'));

		register_activation_hook(__FILE__, array($this, 'ce_install'));
		register_deactivation_hook(__FILE__, array($this, 'ce_uninstall'));

        // Load text domain for translation of admin pages and text strings
        load_plugin_textdomain( 'community-events', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

	function links_rss() {
		add_feed( 'communityeventsfeed', 'community_events_rss_feed' );
	}

	function ajax_frontend_event_list() {
		$year           = intval( $_GET['year'] );
		$outlook        = $_GET['outlook'];
		$showdate       = $_GET['showdate'];
		$maxevents      = intval( $_GET['maxevents'] );
		$moderateevents = $_GET['moderateevents'];
		$searchstring   = $_GET['searchstring'];
		$filterbyuser   = $_GET['filterbyuser'];

		$options = get_option( 'CE_PP' );

		if ( 'current' == $filterbyuser ) {
			$current_user = wp_get_current_user();
			$filterbyuser = $current_user->user_login;
		}

		if ( !empty( $filterbyuser ) ) {
			$filterbyuser = apply_filters( 'community_events_filter_user', $filterbyuser );
		}

		echo $this->eventlist( $year, $_GET['dayofyear'], $outlook, $showdate, $maxevents, $moderateevents, $searchstring, $options['fullscheduleurl'],
			$options['addeventurl'], $options['allowuserediting'], $options['displayendtimefield'], $filterbyuser );

		exit;
	}

	function ajax_admin_event_list() {
		$currentyear = intval( $_GET['currentyear'] );
		$currentday = intval( $_GET['currentday'] );
		$page = intval( $_GET['page'] );
		$moderate = $_GET['moderate'];

		if ($moderate == 'true')
			$moderate = true;
		else
			$moderate = false;

		echo $this->print_event_table($currentyear, $currentday, $page, $moderate);

		exit;
	}

	function ajax_click_tracker() {
		$event_id = intval( $_POST['id'] );
		echo "Received ID is: " . $event_id;

		global $wpdb;

		$ceeventtable = $wpdb->get_blog_prefix() . "ce_events";
		$ceeventdataquery = "select * from " . $wpdb->get_blog_prefix() . "ce_events where event_id = " . $event_id;
		$eventdata = $wpdb->get_row($ceeventdataquery, ARRAY_A);

		if ($eventdata)
		{
			$newcount = $eventdata['event_click_count'] + 1;
			$wpdb->update( $ceeventtable, array( 'event_click_count' => $newcount ), array( 'event_id' => $event_id ));
			echo "Updated row";
		}
		else
		{
			$wpdb->insert( $ceeventtable, array( 'event_id' => $event_id, 'event_click_count' => 1 ));
			echo "Inserted new row";
		}

		exit;
	}

	function ajax_admin_event_approval() {
		global $wpdb;
		$events = $_GET['eventlist'];

		foreach ($events as $event)
		{
			$id = array("event_id" => intval( $event ) );
			$published = array("event_published" => 'Y');

			$wpdb->update( $wpdb->prefix.'ce_events', $published, $id);
		}

		exit;
	}

    function load_scripts() {
        wp_enqueue_script('jquery');
        wp_enqueue_script('tiptip', plugins_url( 'tiptip/jquery.tipTip.minified.js', __FILE__ ), "jquery", "1.0rc3");
        wp_enqueue_style('tiptipstyle', plugins_url( 'tiptip/tipTip.css', __FILE__ ) );
        wp_enqueue_script('jquery-ui-datepicker');
        wp_enqueue_style('datePickerstyle', plugins_url( 'css/ui-lightness/jquery-ui-1.8.4.custom.css', __FILE__ ) );
    }

	//for WordPress 2.8 we have to tell, that we support 2 columns !
	function on_screen_layout_columns($columns, $screen) {
		if ($screen == $this->pagehooktop) {
			$columns[$this->pagehooktop] = 1;
		}
		elseif ($screen == $this->pagehookeventtypes) {
			$columns[$this->pagehookeventtypes] = 1;
		}
		elseif ($screen == $this->pagehookvenues) {
			$columns[$this->pagehookvenues] = 1;
		}
		elseif ($screen == $this->pagehookevents) {
			$columns[$this->pagehookevents] = 1;
		}
		elseif ($screen == $this->pagehookstylesheet) {
			$columns[$this->pagehookstylesheet] = 1;
		}

		return $columns;
	}

	function ce_install() {
		global $wpdb;

		$options = get_option('CE_PP');

		if ($options == false) {
			$options['fullscheduleurl'] = '';
			$options['addeventurl'] = '';
			$options['columns'] = 2;
			$options['addeventreqlogin'] = true;
			$options['addneweventmsg'] = __('Add new event', 'community-events');
			$options['eventnamelabel'] = __('Event Name', 'community-events');
			$options['eventcatlabel'] = __('Event Category', 'community-events');
			$options['eventvenuelabel'] = __('Event Venue', 'community-events');
			$options['eventdesclabel'] = __('Event Description', 'community-events');
			$options['eventaddrlabel'] = __('Event Web Address', 'community-events');
			$options['eventticketaddrlabel'] = __('Event Ticket Purchase Link', 'community-events');
			$options['eventdatelabel'] = __('Event Start Date', 'community-events');
			$options['eventtimelabel'] = __('Event Time', 'community-events');
			$options['addeventbtnlabel'] = __('Add Event', 'community-events');
			$options['eventenddatelabel'] = __('Event End Date', 'community-events');
			$options['outlook'] = true;
			$options['emailnewevent'] = true;
			$options['moderateevents'] = true;
			$options['maxevents7dayview'] = 5;
			$options['fullvieweventsperpage'] = 20;
			$options['fullviewmaxdays'] = 90;
			$options['captchaevents'] = false;
			$options['storelinksubmitter'] = false;
			$options['outlookdefault'] = false;
			$options['displaysearch'] = true;
			$options['publishfeed'] = true;
			$options['rssfeedtitle'] = __('Community Events Calendar RSS Feed', 'community_events' );
			$options['rssfeeddescription'] = __( 'This is a default description for the RSS Feed' , 'community-events' );
			$options['rssfeedtargetaddress'] = '';
			$options['allowuserediting'] = false;
			$options['updateeventbtnlabel'] = __('Update Event', 'community-events');
			$options['newvenuenamelabel'] = __('New Venue Name', 'community-events');
			$options['newvenueaddresslabel'] = __('New Venue Address', 'community-events');
			$options['newvenuecitylabel'] = __('New Venue City', 'community-events');
			$options['newvenuezipcodelabel'] = __('New Venue Zip Code', 'community-events');
			$options['newvenuephonelabel'] = __('New Venue Phone', 'community-events');
			$options['newvenueemaillabel'] = __('New Venue E-mail', 'community-events');
			$options['newvenueurllabel'] = __('New Venue URL', 'community-events');
			$options['allowuservenuesubmissions'] = false;
			$options['displayendtimefield'] = false;
			$options['eventendtimelabel'] = __('Event End Time', 'community-events');
            $options['eventorder'] = 'eventnameorder';
			$options['schemaversion'] = 1.2;

			$stylesheetlocation = plugin_dir_path( __FILE__ ) . 'stylesheettemplate.css';
			if (file_exists($stylesheetlocation))
				$options['fullstylesheet'] = file_get_contents( $stylesheetlocation );

			update_option('CE_PP',$options);

			$wpdb->ceevents = $wpdb->prefix.'ce_events';

			$result = $wpdb->query("
					CREATE TABLE IF NOT EXISTS " . $wpdb->ceevents . " (
					  event_id bigint(20) NOT NULL AUTO_INCREMENT,
					  event_name varchar(255) DEFAULT NULL,
					  event_start_date date DEFAULT NULL,
					  event_start_hour int(11) DEFAULT NULL,
					  event_start_minute int(2) unsigned zerofill DEFAULT NULL,
					  event_start_ampm varchar(2) DEFAULT NULL,
					  event_end_date date DEFAULT NULL,
					  event_description varchar(140) DEFAULT NULL,
					  event_url varchar(255) DEFAULT NULL,
					  event_ticket_url varchar(256) DEFAULT NULL,
					  event_venue int(11) DEFAULT NULL,
					  event_category int(11) DEFAULT NULL,
					  event_published varchar(1) DEFAULT NULL,
					  event_submitter VARCHAR(60) DEFAULT NULL,
					  event_click_count INT(11) DEFAULT NULL,
					  event_end_hour INT(11) DEFAULT NULL,
					  event_end_minute INT(2) unsigned zerofill DEFAULT NULL,
					  event_end_ampm VARCHAR(2) DEFAULT NULL,
					  UNIQUE KEY (event_id)
						) ") ;

			$wpdb->cecats = $wpdb->prefix.'ce_category';

			$result = $wpdb->query("
					CREATE TABLE IF NOT EXISTS " . $wpdb->cecats . " (
						event_cat_id bigint(20) NOT NULL AUTO_INCREMENT,
						event_cat_name varchar(255) DEFAULT NULL,
						PRIMARY KEY (event_cat_id)
						) ");

			$catsresult = $wpdb->query("SELECT * from " . $wpdb->cecats);

			if (!$catsresult)
				$result = $wpdb->query("
					INSERT INTO " . $wpdb->cecats . " (event_cat_name) VALUES
					('Default')");

			$wpdb->cevenues = $wpdb->prefix.'ce_venues';

			$result = $wpdb->query("
				CREATE TABLE IF NOT EXISTS " . $wpdb->cevenues . " (
					  ce_venue_id bigint(20) NOT NULL AUTO_INCREMENT,
					  ce_venue_name varchar(256) DEFAULT NULL,
					  ce_venue_address varchar(256) DEFAULT NULL,
					  ce_venue_city varchar(256) DEFAULT NULL,
					  ce_venue_zipcode varchar(256) DEFAULT NULL,
					  ce_venue_phone varchar(256) DEFAULT NULL,
					  ce_venue_email varchar(256) DEFAULT NULL,
					  ce_venue_url varchar(256) DEFAULT NULL,
					  PRIMARY KEY (ce_venue_id)
						) ");

			$venuesresult = $wpdb->query("SELECT * from " . $wpdb->cevenues);

			if (!$venuesresult)
				$result = $wpdb->query("
					INSERT INTO " . $wpdb->cevenues . " (ce_venue_name) VALUES
					('Default')");

		} else {
			if ( isset( $options['schemaversion'] ) && $options['schemaversion'] < 0.3)
			{
				$options['schemaversion'] = 0.3;
				update_option('CE_PP',$options);

				$wpdb->get_results("ALTER TABLE " . $wpdb->prefix . "ce_events ADD event_start_hour INT NULL AFTER event_date,  ADD event_start_minute INT( 2 ) UNSIGNED ZEROFILL NULL AFTER event_start_hour,  ADD event_start_ampm VARCHAR( 2 ) NULL AFTER event_start_minute,  ADD event_end_date DATE NULL AFTER event_start_ampm;");
				$wpdb->get_results("ALTER TABLE " . $wpdb->prefix . "ce_events DROP event_duration;");
				$wpdb->get_results("ALTER TABLE " . $wpdb->prefix . "ce_events CHANGE event_date event_start_date DATE NULL DEFAULT NULL");
				$wpdb->get_results("ALTER TABLE " . $wpdb->prefix . "ce_events ADD event_published VARCHAR( 1 ) NULL;");
			}

			if ( isset( $options['schemaversion'] ) && $options['schemaversion'] < 1.0 ) {
				$options['schemaversion'] = 1.0;
				update_option('CE_PP',$options);

				$wpdb->get_results("ALTER TABLE " . $wpdb->prefix . "ce_events ADD event_submitter VARCHAR(60) NULL AFTER event_published;");
			}

			if ( isset( $options['schemaversion'] ) && $options['schemaversion'] < 1.1 ) {
				$options['schemaversion'] = 1.1;
				update_option('CE_PP',$options);

				$wpdb->get_results("ALTER TABLE " . $wpdb->prefix . "ce_events ADD event_click_count INT(11) NULL AFTER event_submitter;");
			}

			if ( isset( $options['schemaversion'] ) && $options['schemaversion'] < 1.2 ) {
				$options['schemaversion'] = 1.2;
				update_option('CE_PP',$options);

				$wpdb->get_results("ALTER TABLE " . $wpdb->prefix . "ce_events ADD event_end_hour INT(11) NULL AFTER event_click_count, ADD event_end_minute INT(2) UNSIGNED ZEROFILL NULL AFTER event_end_hour, ADD event_end_ampm VARCHAR(2) NULL AFTER event_end_minute;");
			}

			if ( isset( $options['fullvieweventsperpage'] ) && empty( $options['fullvieweventsperpage'] ) )
				$options['fullvieweventsperpage'] = 20;

			if ( isset( $options['fullviewmaxdays'] ) && empty( $options['fullviewmaxdays'] ) )
				$options['fullviewmaxdays'] = 90;

			if ( isset( $options['fullviewmaxdays'] ) && empty( $options['fullstylesheet'] ) ) {
				$stylesheetlocation = plugins_url( 'stylesheettemplate.css', __FILE__ );
				if (file_exists($stylesheetlocation))
					$options['fullstylesheet'] = file_get_contents( $stylesheetlocation );
			}

			update_option('CE_PP',$options);
		}

		wp_clear_scheduled_hook('ce_daily_event');

		wp_schedule_event(current_time('timestamp'), 'daily', 'ce_daily_event');
	}

	function ce_uninstall() {
		wp_clear_scheduled_hook('ce_daily_event');
	}

	function ce_daily_cleanup() {
		global $wpdb;

		$currentday = date("z", current_time('timestamp')) + 1;
		if (date("L") == 1 && $currentday > 60)
			$currentday++;

		$currentyear = date("Y");

		$cleanupquery = "DELETE FROM " . $wpdb->prefix . "ce_events where (YEAR(event_start_date) <= " . $currentyear;
		$cleanupquery .= " and DAYOFYEAR(DATE(event_start_date)) < " . $currentday . ") ";

		$wpdb->get_results($cleanupquery);
	}

	function remove_querystring_var($url, $key) {
		$keypos = strpos($url, $key);
		if ($keypos)
		{
			$ampersandpos = strpos($url, '&', $keypos);
			$newurl = substr($url, 0, $keypos - 1);

			if ($ampersandpos)
				$newurl .= substr($url, $ampersandpos);
		}
		else
			$newurl = $url;

		return $newurl;
	}

	//extend the admin menu
	function on_admin_menu() {
		//add our own option page, you can also add it to different sections or use your own one
		$this->pagehooktop = add_menu_page( __('Community Events General Options', 'community-events'), "Community Events", 'manage_options', COMMUNITY_EVENTS_ADMIN_PAGE_NAME, array($this, 'on_show_page'), $this->cepluginpath . '/icons/Calendar-icon.png');
		$this->pagehookeventtypes = add_submenu_page( COMMUNITY_EVENTS_ADMIN_PAGE_NAME, __('Community Events - Event Types', 'community-events') , __('Event Types', 'community-events'), 'manage_options', 'community-events-event-types', array($this,'on_show_page'));
		$this->pagehookvenues = add_submenu_page( COMMUNITY_EVENTS_ADMIN_PAGE_NAME, __('Community Events - Venues', 'community-events') , __('Venues', 'community-events'), 'manage_options', 'community-events-venues', array($this,'on_show_page'));
		$this->pagehookevents = add_submenu_page( COMMUNITY_EVENTS_ADMIN_PAGE_NAME, __('Community Events - Events', 'community-events') , __('Events', 'community-events'), 'manage_options', 'community-events-events', array($this,'on_show_page'));
		$this->pagehookstylesheet = add_submenu_page( COMMUNITY_EVENTS_ADMIN_PAGE_NAME, __('Community Events - Stylesheet Editor', 'community-events') , __('Stylesheet', 'community-events'), 'manage_options', 'community-events-stylesheet', array($this,'on_show_page'));

		//register  callback gets call prior your own page gets rendered
		add_action('load-'.$this->pagehooktop, array(&$this, 'on_load_page'));
		add_action('load-'.$this->pagehookeventtypes, array(&$this, 'on_load_page'));
		add_action('load-'.$this->pagehookvenues, array(&$this, 'on_load_page'));
		add_action('load-'.$this->pagehookevents, array(&$this, 'on_load_page'));
		add_action('load-'.$this->pagehookstylesheet, array(&$this, 'on_load_page'));
	}

	//will be executed if wordpress core detects this page has to be rendered
	function on_load_page() {
		//ensure, that the needed javascripts been loaded to allow drag/drop, expand/collapse and hide/show of boxes
		wp_enqueue_script('common');
		wp_enqueue_script('wp-lists');
		wp_enqueue_script('postbox');

		//add several metaboxes now, all metaboxes registered during load page can be switched off/on at "Screen Options" automatically, nothing special to do therefore
		add_meta_box('communityevents_general_usage_meta_box', __('Usage Instructions', 'community-events'), array($this, 'general_usage_meta_box'), $this->pagehooktop, 'normal', 'high');
		add_meta_box('communityevents_general_config_meta_box', __('General Configuration', 'community-events'), array($this, 'general_config_meta_box'), $this->pagehooktop, 'normal', 'high');
		add_meta_box('communityevents_general_user_sub_meta_box', __('Event User Submission', 'community-events'), array($this, 'general_user_sub_meta_box'), $this->pagehooktop, 'normal', 'high');
		add_meta_box('communityevents_general_rssgen_meta_box', __('RSS Feed Generation', 'community-events'), array($this, 'general_user_rssgen_box'), $this->pagehooktop, 'normal', 'high');
		add_meta_box('communityevents_general_save_meta_box', __('Save', 'community-events'), array($this, 'general_save_meta_box'), $this->pagehooktop, 'normal', 'high');
		add_meta_box('communityevents_event_types_meta_box', __('Event Types Editor', 'community-events'), array($this, 'event_types_meta_box'), $this->pagehookeventtypes, 'normal', 'high');
		add_meta_box('communityevents_event_types_save_meta_box', __('Save', 'community-events'), array($this, 'event_types_save_meta_box'), $this->pagehookeventtypes, 'normal', 'high');
		add_meta_box('communityevents_venues_meta_box', __('Venues Editor', 'community-events'), array($this, 'event_venues_meta_box'), $this->pagehookvenues, 'normal', 'high');
		add_meta_box('communityevents_venues_importer_meta_box', __('Venues Importer', 'community-events'), array($this, 'event_venues_importer_meta_box'), $this->pagehookvenues, 'normal', 'high');
		add_meta_box('communityevents_venues_save_meta_box', __('Save', 'community-events'), array($this, 'event_venues_save_meta_box'), $this->pagehookvenues, 'normal', 'high');
		add_meta_box('communityevents_events_meta_box', __('Events Editor', 'community-events'), array($this, 'events_meta_box'), $this->pagehookevents, 'normal', 'high');
 		add_meta_box('communityevents_events_save_meta_box', __('Save', 'community-events'), array($this, 'events_save_meta_box'), $this->pagehookevents, 'normal', 'high');
		add_meta_box('communityevents_events_stylesheet_meta_box', __('Stylesheet Editor', 'community-events'), array($this, 'events_stylesheet_meta_box'), $this->pagehookstylesheet, 'normal', 'high');
	}

	//executed to show the plugins complete admin page
	function on_show_page() {
		//we need the global screen column value to beable to have a sidebar in WordPress 2.8
		global $screen_layout_columns;
		global $wpdb;
        $mode = '';
        $selectedcat = '';
        $selectedevent = '';
        $selectedvenue = '';

		if ($_GET['page'] == 'community-events')
		{
			$pagetitle = __('Community Events General Settings', 'community-events');
			$formvalue = 'save_community_events_general';

			if (isset( $_GET['message'] ) && $_GET['message'] == '1')
				echo '<div id="message" class="updated fade"><p><strong>' . __('Community Events Updated', 'community-events') . '</strong></div>';
		}
		elseif ($_GET['page'] == 'community-events-event-types')
		{
			$pagetitle = __('Community Events - Event Types', 'community-events');
			$formvalue = 'save_community_events_event_types';

			if ( isset( $_GET['editcat'] ) ) {
				$mode = "edit";
				$selectedcat = $wpdb->get_row("select * from " . $wpdb->prefix . "ce_category where event_cat_id = " . intval( $_GET['editcat'] ) );
			} elseif ( isset($_GET['deletecat'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'ce_delete_cat' ) ) {
				$catexist = $wpdb->get_row("SELECT * from " . $wpdb->prefix . "ce_category WHERE event_cat_id = " . intval( $_GET['deletecat'] ) );

				if ( $catexist ) {
					$wpdb->query("DELETE from " . $wpdb->prefix . "ce_category WHERE event_cat_id = " . intval( $_GET['deletecat'] ) );
					echo '<div id="message" class="updated fade"><p><strong>' . __('Category Deleted', 'community-events') . '</strong></div>';
				}
			}

			if ( isset( $_GET['message'] ) && $_GET['message'] == '1' ) {
				echo '<div id="message" class="updated fade"><p><strong>' . __('Inserted New Category', 'community-events') . '</strong></div>';
			} elseif ( isset( $_GET['message'] )&& $_GET['message'] == '2') {
				echo '<div id="message" class="updated fade"><p><strong>' . __('Category Updated', 'community-events') . '</strong></div>';
			}				
		} elseif ( $_GET['page'] == 'community-events-venues' ) {
			$pagetitle = __('Community Events - Venues', 'community-events');
			$formvalue = 'save_community_events_venues';

			if ( isset( $_GET['editvenue'] ) ) {
				$mode = "edit";
				$selectedvenue = $wpdb->get_row("select * from " . $wpdb->prefix . "ce_venues where ce_venue_id = " . intval( $_GET['editvenue'] ) );
			} elseif ( isset( $_GET['deletevenue'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'ce_delete_venue' ) ) {
				$venueexist = $wpdb->get_row( "SELECT * from " . $wpdb->prefix . "ce_venues WHERE ce_venue_id = " . intval( $_GET['deletevenue'] ) );

				if ($venueexist)
				{
					$wpdb->query( "DELETE from " . $wpdb->prefix . "ce_venues WHERE ce_venue_id = " . intval( $_GET['deletevenue'] ) );
					echo '<div id="message" class="updated fade"><p><strong>' . __('Venue Deleted', 'community-events') . '</strong></div>';
				}
			}

			if (isset($_GET['messages']))
			{
				$messagelist = explode(",", $_GET['messages']);

				foreach ( $messagelist as $message )
				{
					switch( $message ) {
						case '1':
							echo '<div id="message" class="updated fade"><p><strong>' . __('Inserted New Venue', 'community-events') . '</strong></div>';
							break;

						case '2':
							echo '<div id="message" class="updated fade"><p><strong>' . __('Venue Updated', 'community-events') . '</strong></div>';
							break;

						case '4':
							echo "<div id='message' class='updated fade'><p><strong>" . __('Invalid column count for link', 'community-events') . "</strong></p></div>";
							break;

						case '9':
							echo "<div id='message' class='updated fade'><p><strong>" . intval( $_GET['importrowscount'] ) . " " . __('row(s) found', 'link-library') . ". " . intval( $_GET['successimportcount'] ) . " " . __('link(s) imported successfully', 'community-events') . ".</strong></p></div>";
							break;
					}
				}
			}
		}
		elseif ($_GET['page'] == 'community-events-events')
		{
			$pagetitle = __('Community Events - Events', 'community-events');
			$formvalue = 'save_community_events_events';

			if ( isset( $_GET['editevent'] ) ) {
				$mode = "edit";
				$selectedevent = $wpdb->get_row("select * from " . $wpdb->prefix . "ce_events where event_id = " . intval( $_GET['editevent'] ) );
			} elseif ( isset( $_GET['deleteevent'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'ce_delete_event' ) ) {
				$eventexist = $wpdb->get_row("SELECT * from " . $wpdb->prefix . "ce_events WHERE event_id = " . intval( $_GET['deleteevent'] ) );

				if ( $eventexist ) {
					$wpdb->query("DELETE from " . $wpdb->prefix . "ce_events WHERE event_id = " . intval( $_GET['deleteevent'] ) );
					echo '<div id="message" class="updated fade"><p><strong>' . __('Event Deleted', 'community-events') . '</strong></div>';
				}
			}

			if ( isset( $_GET['message'] ) && $_GET['message'] == '1')
				echo '<div id="message" class="updated fade"><p><strong>' . __('Inserted New Event', 'community-events') . '</strong></div>';
			elseif ( isset( $_GET['message'] ) && $_GET['message'] == '2')
				echo '<div id="message" class="updated fade"><p><strong>' . __('Event Updated', 'community-events') . '</strong></div>';
		}
		elseif ($_GET['page'] == 'community-events-stylesheet')
		{
			$pagetitle = __('Community Events - Stylesheet', 'community-events');
			$formvalue = 'save_community_events_stylesheet';

			if ( isset( $_GET['message'] ) && $_GET['message'] == '1')
				echo "<div id='message' class='updated fade'><p><strong>" . __('Stylesheet updated', 'community-events') . ".</strong></p></div>";
			elseif ( isset( $_GET['message'] ) && $_GET['message'] == '2')
				echo "<div id='message' class='updated fade'><p><strong>" . __('Stylesheet reset to original state', 'community-events') . ".</strong></p></div>";
		}

		$options = get_option('CE_PP');

		//define some data can be given to each metabox during rendering
		$data['options'] = $options;
		$data['mode'] = $mode;
		$data['selectedcat'] = $selectedcat;
		$data['selectedvenue'] = $selectedvenue;
		$data['selectedevent'] = $selectedevent;
		?>
		<div id="community-events-general" class="wrap">
		<div class='icon32'><img src="<?php echo $this->cepluginpath . '/icons/Calendar-icon32.png'; ?>" /></div>
		<h2><?php echo $pagetitle; ?><span style='padding-left: 50px'><a href="https://ylefebvre.home.blog/wordpress-plugins/community-events/" target="linklibrary"><img src="<?php echo $this->cepluginpath; ?>/icons/btn_donate_LG.gif" /></a></span></h2>
		<form action="admin-post.php" method="post" id='ceform' enctype='multipart/form-data'>
			<?php wp_nonce_field('community-events-general'); ?>
			<?php wp_nonce_field('closedpostboxes', 'closedpostboxesnonce', false ); ?>
			<?php wp_nonce_field('meta-box-order', 'meta-box-order-nonce', false ); ?>
			<input type="hidden" name="action" value="<?php echo $formvalue; ?>" />

			<div id="poststuff" class="metabox-holder">
				<div id="post-body">
					<div id="post-body-content">
						<?php
							if ($_GET['page'] == 'community-events')
								do_meta_boxes($this->pagehooktop, 'normal', $data);
							elseif ($_GET['page'] == 'community-events-event-types')
								do_meta_boxes($this->pagehookeventtypes, 'normal', $data);
							elseif ($_GET['page'] == 'community-events-venues')
								do_meta_boxes($this->pagehookvenues, 'normal', $data);
							elseif ($_GET['page'] == 'community-events-events')
								do_meta_boxes($this->pagehookevents, 'normal', $data);
							elseif ($_GET['page'] == 'community-events-stylesheet')
								do_meta_boxes($this->pagehookstylesheet, 'normal', $data);
						?>
					</div>
				</div>
				<br class="clear"/>

			</div>
		</form>
		</div>
	<script type="text/javascript">
		//<![CDATA[
		jQuery(document).ready( function($) {
			// close postboxes that should be closed
			$('.if-js-closed').removeClass('if-js-closed').addClass('closed');
			// postboxes setup
			postboxes.add_postbox_toggles('<?php echo $this->pagehooktop; ?>');
		});
		//]]>
	</script>

		<?php
	}

	//executed if the post arrives initiated by pressing the submit button of form
	function on_save_changes_general() {
		//user permission check
		if ( !current_user_can('manage_options') )
			wp_die( __('Cheatin&#8217; uh?') );
		//cross check the given referer
		check_admin_referer('community-events-general');

		$options = get_option('CE_PP');

		foreach (array('fullscheduleurl', 'addeventurl', 'columns', 'addneweventmsg',
						'eventnamelabel', 'eventcatlabel', 'eventvenuelabel', 'eventdesclabel',
						'eventaddrlabel', 'eventticketaddrlabel', 'eventdatelabel', 'eventtimelabel',
						'addeventbtnlabel', 'eventenddatelabel', 'maxevents7dayview', 'fullviewmaxdays',
						'fullvieweventsperpage', 'rssfeedtitle', 'rssfeeddescription', 'rssfeedtargetaddress',
						'updateeventbtnlabel', 'newvenuenamelabel', 'newvenueaddresslabel',
						'newvenuecitylabel', 'newvenuezipcodelabel', 'newvenuephonelabel',
						'newvenueemaillabel', 'newvenueurllabel', 'eventendtimelabel', 'eventorder') as $option_name) {
				if ( isset( $_POST[$option_name] ) ) {
					$options[$option_name] = $_POST[$option_name];
				}
			}

		foreach (array('adjusttooltipposition', 'addeventreqlogin', 'outlook', 'emailnewevent', 'moderateevents', 'captchaevents', 'storelinksubmitter',
						'outlookdefault', 'displaysearch', 'publishfeed', 'allowuserediting',
					'allowuservenuesubmissions', 'displayendtimefield') as $option_name) {
			if ( isset( $_POST[$option_name] ) ) {
				$options[$option_name] = true;
			} else {
				$options[$option_name] = false;
			}
		}

		update_option('CE_PP', $options);

		//lets redirect the post request into get request (you may add additional params at the url, if you need to show save results
		wp_redirect($this->remove_querystring_var($_POST['_wp_http_referer'], 'message') . "&message=1");
	}

		//executed if the post arrives initiated by pressing the submit button of form
	function on_save_changes_eventtypes() {
		//user permission check
		if ( !current_user_can('manage_options') )
			wp_die( __('Cheatin&#8217; uh?') );
		//cross check the given referer
		check_admin_referer('community-events-general');

		global $wpdb;
		$message = '';

		if ( isset($_POST['newcat']) || isset($_POST['updatecat'])) {
			if (isset($_POST['name']))
				$newcat = array( "event_cat_name" => sanitize_text_field( $_POST['name'] ) );
			else
				$newcat = "";

			if (isset($_POST['id']))
				$id = array( "event_cat_id" => intval( $_POST['id'] ) );

			if (isset($_POST['newcat']))
			{
				$wpdb->insert( $wpdb->prefix.'ce_category', $newcat);
				$message = '1';
			}
			elseif (isset($_POST['updatecat']))
			{
				$wpdb->update( $wpdb->prefix.'ce_category', $newcat, $id);
				$message = '2';
			}
		}

		//lets redirect the post request into get request (you may add additional params at the url, if you need to show save results
		$cleanredirecturl = $this->remove_querystring_var($_POST['_wp_http_referer'], 'message');
		$cleanredirecturl = $this->remove_querystring_var($cleanredirecturl, 'editcat');

		if ($message != '')
			$cleanredirecturl .= "&message=" . $message;

		wp_redirect($cleanredirecturl);
	}

		//executed if the post arrives initiated by pressing the submit button of form
	function on_save_changes_venues() {
		//user permission check
		if ( !current_user_can('manage_options') )
			wp_die( __('Cheatin&#8217; uh?') );
		//cross check the given referer
		check_admin_referer('community-events-general');

		global $wpdb;
		$message = '';

		$row = 0;
		$successfulimport = 0;

		if (isset($_POST['importvenues']))
		{
			global $wpdb;

			$handle = fopen($_FILES['venuesfile']['tmp_name'], "r");

			if ($handle)
			{
				$skiprow = 1;

				while (($data = fgetcsv($handle, 5000, ",")) !== FALSE) {
					$row += 1;
					if ($skiprow == 1 && isset($_POST['firstrowheaders']) && $row >= 2)
						$skiprow = 0;
					elseif (!isset($_POST['firstrowheaders']))
						$skiprow = 0;

					if (!$skiprow)
					{
						if (count($data) == 7)
						{
							$existingvenuequery = "SELECT ce_venue_id FROM " . $wpdb->prefix . "ce_venues v ";
							$existingvenuequery .= "WHERE ce_venue_name = '" . $data[0] . "'";
							$existingvenue = $wpdb->get_var($existingvenuequery);

							if (!$existingvenue)
							{
								$venuetablename = $wpdb->prefix . "ce_venues";
								$newvenue = array( "ce_venue_name" => sanitize_text_field( $data[0] ),
											"ce_venue_address" => sanitize_text_field( $data[1] ),
											"ce_venue_city" => sanitize_text_field( $data[2] ),
											"ce_venue_zipcode" => sanitize_text_field( $data[3] ),
											"ce_venue_phone" => sanitize_text_field( $data[4] ),
											"ce_venue_email" => sanitize_text_field( $data[5] ),
											"ce_venue_url" => sanitize_url( $data[6] ) );

								$returncode = $wpdb->insert( $venuetablename, $newvenue );

								if ($returncode)
									$successfulimport += 1;
							}
						}
						else
						{
							$messages[] = '4';
						}
					}
				}
			}

			if (isset($_POST['firstrowheaders']))
				$row -= 1;

			$messages[] = '9';
		}
		elseif ( isset($_POST['newvenue']) || isset($_POST['updatevenue'])) {

			if (isset($_POST['ce_venue_name']))
				$newvenue = array("ce_venue_name" => sanitize_text_field( $_POST['ce_venue_name'] ),
								"ce_venue_address" => sanitize_text_field( $_POST['ce_venue_address'] ),
								"ce_venue_city" => sanitize_text_field( $_POST['ce_venue_city'] ),
								"ce_venue_zipcode" => sanitize_text_field( $_POST['ce_venue_zipcode'] ),
								"ce_venue_phone" => sanitize_text_field( $_POST['ce_venue_phone'] ),
								"ce_venue_email" => sanitize_text_field( $_POST['ce_venue_email'] ),
								"ce_venue_url" => sanitize_url( $_POST['ce_venue_url'] )
								);
			else
				$newvenue = "";

			if (isset($_POST['ce_venue_id']))
				$id = array("ce_venue_id" => intval( $_POST['ce_venue_id']) );

			if (isset($_POST['newvenue']))
			{
				$wpdb->insert( $wpdb->prefix.'ce_venues', $newvenue);
				$messages[] = '1';
			}
			elseif (isset($_POST['updatevenue']))
			{
				$wpdb->update( $wpdb->prefix.'ce_venues', $newvenue, $id);
				$messages[] = '2';
			}
		}

		//lets redirect the post request into get request (you may add additional params at the url, if you need to show save results
		$cleanredirecturl = $this->remove_querystring_var($_POST['_wp_http_referer'], 'message');
		$cleanredirecturl = $this->remove_querystring_var($cleanredirecturl, 'editvenue');
		$cleanredirecturl = $this->remove_querystring_var($cleanredirecturl, 'importrowscount');
		$cleanredirecturl = $this->remove_querystring_var($cleanredirecturl, 'successimportcount');

		$messagelist = implode(",", $messages);
		if (!empty($messages))
			$cleanredirecturl .= "&messages=" . $messagelist;

		if ($row != 0)
			$cleanredirecturl .= "&importrowscount=" . $row;

		if ($successfulimport != 0)
			$cleanredirecturl .= "&successimportcount=" . $successfulimport;

		wp_redirect($cleanredirecturl);
	}

		//executed if the post arrives initiated by pressing the submit button of form
	function on_save_changes_events() {
		//user permission check
		if ( !current_user_can('manage_options') )
			wp_die( __('Cheatin&#8217; uh?') );
		//cross check the given referer
		check_admin_referer('community-events-general');

        $options = get_option('CE_PP');

		$message = '';
		global $wpdb;

		if (isset($_POST['newevent']) || isset($_POST['updateevent']))
		{
			if (isset($_POST['event_name']) && isset($_POST['event_start_hour']) && isset($_POST['event_start_minute']) && isset($_POST['event_start_ampm']) && isset($_POST['event_start_date']) && $_POST['event_name'] != '')
			{
				global $current_user;
				$username = "";

				get_currentuserinfo();

				if ($current_user)
					$username = $current_user->user_login;

				$newevent = array("event_name" => sanitize_text_field( $_POST['event_name'] ),
								 "event_description" => sanitize_text_field( $_POST['event_description'] ),
								 "event_start_date" => sanitize_text_field( $_POST['event_start_date'] ),
								 "event_start_hour" => sanitize_text_field( $_POST['event_start_hour'] ),
								 "event_start_minute" => sanitize_text_field( $_POST['event_start_minute'] ),
								 "event_start_ampm" => sanitize_text_field( $_POST['event_start_ampm'] ),
								 "event_url" => sanitize_url( $_POST['event_url'] ),
								 "event_ticket_url" => sanitize_url( $_POST['event_ticket_url'] ),
								 "event_category" => sanitize_text_field( $_POST['event_category'] ),
								 "event_venue" => sanitize_text_field( $_POST['event_venue'] ),
								 "event_published" => 'Y',
								 "event_submitter" => $username
								 );

                if ( $options['displayendtimefield'] ) {
                    $newevent['event_end_hour'] = sanitize_text_field( $_POST['event_end_hour'] );
                    $newevent['event_end_minute'] = sanitize_text_field( $_POST['event_end_minute'] );
                    $newevent['event_end_ampm'] = sanitize_text_field( $_POST['event_end_ampm'] );
                }

				if ($_POST['event_end_date'] != '')
					$newevent['event_end_date'] = sanitize_text_field( $_POST['event_end_date'] );

				if ($newevent['event_start_date'] != "")
				{
					$newevent['event_start_date'] = str_replace('-', '/', $newevent['event_start_date']);
					$newevent['event_start_date'] = date( 'Y-m-d', strtotime( $newevent['event_start_date']) );
				}

				if ($newevent['event_end_date'] != "")
				{
					$newevent['event_end_date'] = str_replace('-', '/', $newevent['event_end_date']);
					$newevent['event_end_date'] = date( 'Y-m-d', strtotime( $newevent['event_end_date']) );
				}

				if (isset($_POST['event_id']))
					$id = array("event_id" => intval( $_POST['event_id']) );

				if (isset($_POST['newevent']))
				{
					$successcode = $wpdb->insert( $wpdb->prefix.'ce_events', $newevent);
					if ($successcode != false)
						$message = '1';
				}
				elseif (isset($_POST['updateevent']))
				{
					$successcode = $wpdb->update( $wpdb->prefix.'ce_events', $newevent, $id);

					if ($successcode != false)
						$message = '2';
				}
			}
		}


		//lets redirect the post request into get request (you may add additional params at the url, if you need to show save results
		$cleanredirecturl = $this->remove_querystring_var($_POST['_wp_http_referer'], 'message');
		$cleanredirecturl = $this->remove_querystring_var($cleanredirecturl, 'editevent');

		if ($message != '')
			$cleanredirecturl .= "&message=" . $message;

		wp_redirect($cleanredirecturl);
	}

	function on_save_changes_stylesheet() {
		//user permission check
		if ( !current_user_can('manage_options') )
			wp_die( __('Cheatin&#8217; uh?') );
		//cross check the given referer
		check_admin_referer('community-events-general');

		$message = '';
		global $wpdb;

		if ( isset( $_POST['submitstyle'] ) ) {
			$options = get_option( 'CE_PP' );

			$options['fullstylesheet'] = sanitize_text_field( $_POST['fullstylesheet'] );

			update_option('CE_PP', $options);
			$message = 1;
		} elseif ( isset( $_POST['resetstyle'] ) ) {
			$options = get_option('CE_PP');

			$stylesheetlocation = plugin_dir_path( __FILE__ ) . 'stylesheettemplate.css';
			if ( file_exists( $stylesheetlocation ) ) {
				$options['fullstylesheet'] = file_get_contents( $stylesheetlocation );
			}

			update_option('CE_PP', $options);

			$message = 2;
		}


		//lets redirect the post request into get request (you may add additional params at the url, if you need to show save results
		$cleanredirecturl = $this->remove_querystring_var($_POST['_wp_http_referer'], 'message');

		if ($message != '')
			$cleanredirecturl .= "&message=" . $message;

		wp_redirect($cleanredirecturl);
	}

	function ce_highlight_phrase($str, $phrase, $tag_open = '<strong>', $tag_close = '</strong>')
	{
		if ($str == '')
		{
			return '';
		}

		if ($phrase != '')
		{
			return preg_replace('/('.preg_quote($phrase, '/').'(?![^<]*>))/i', $tag_open."\\1".$tag_close, $str);
		}

		return $str;
	}

	function print_event_table( $currentyear, $currentday, $page, $moderate = false ) {
		global $wpdb;

        $options = get_option('CE_PP');

		$countquery = "SELECT COUNT(*) from " . $wpdb->prefix . "ce_events where ";

		if ($moderate == true)
			$countquery .= " event_published = 'N' AND ";

        $countquery .= " ((YEAR(event_start_date) = " . $currentyear . ") and DAYOFYEAR(DATE(event_start_date)) >= " . $currentday . " AND (event_end_date IS NULL or event_end_date = event_start_date)) ";

        $countquery .= " OR ((YEAR(event_start_date) = " . $currentyear;
        $countquery .= " and DAYOFYEAR(DATE(event_start_date)) <= " . $currentday . " ";
        $countquery .= " and DAYOFYEAR(DATE(event_end_date)) >= " . $currentday . ") ";

        $countquery .= "OR (YEAR(event_start_date) < " . $currentyear;
        $countquery .= " and DAYOFYEAR(DATE(event_end_date)) >= " . $currentday;
        $countquery .= " and DAYOFYEAR(YEAR(event_end_date)) >= " . $currentyear . ") ";

        $countquery .= "OR (YEAR(event_end_date) > " . $currentyear . ") ";

        $countquery .= "OR (YEAR(event_start_date) > " . $currentyear . ") ";

        $countquery .= "OR (YEAR(event_end_date) = " . $currentyear;
        $countquery .= " and YEAR(DATE(event_start_date)) < " . $currentyear;
        $countquery .= " and DAYOFYEAR(DATE(event_end_date)) >= " . $currentday . ")) ";

		$count = $wpdb->get_var($countquery);

		$start = ($page - 1) * 10;
		$eventquery = "SELECT * from " . $wpdb->prefix . "ce_events WHERE ";

		if ($moderate == true)
			$eventquery .= " event_published = 'N' AND ";

		$eventquery .= " ((YEAR(event_start_date) = " . $currentyear . ") and DAYOFYEAR(DATE(event_start_date)) >= " . $currentday . " AND (event_end_date IS NULL or event_end_date = event_start_date)) ";

		$eventquery .= " OR ((YEAR(event_start_date) = " . $currentyear;
		$eventquery .= " and DAYOFYEAR(DATE(event_start_date)) <= " . $currentday . " ";
		$eventquery .= " and DAYOFYEAR(DATE(event_end_date)) >= " . $currentday . ") ";

		$eventquery .= "OR (YEAR(event_start_date) < " . $currentyear;
		$eventquery .= " and DAYOFYEAR(DATE(event_end_date)) >= " . $currentday;
		$eventquery .= " and DAYOFYEAR(YEAR(event_end_date)) >= " . $currentyear . ") ";

		$eventquery .= "OR (YEAR(event_end_date) > " . $currentyear . ") ";

		$eventquery .= "OR (YEAR(event_start_date) > " . $currentyear . ") ";

		$eventquery .= "OR (YEAR(event_end_date) = " . $currentyear;
		$eventquery .= " and YEAR(DATE(event_start_date)) < " . $currentyear;
		$eventquery .= " and DAYOFYEAR(DATE(event_end_date)) >= " . $currentday . ")) ";

		$eventquery .= " ORDER by event_start_date, ";
        if ( $options['eventorder'] == 'eventnameorder' || empty( $options['eventorder'] ) ) {
            $eventquery .= 'event_name ';
        } else if ( $options['eventtimeorder'] ) {
            $eventquery .= 'event_start_ampm, start_hour, start_minute ';
        }

        $eventquery .= "LIMIT " . $start . ", 10";

		$events = $wpdb->get_results( $eventquery, ARRAY_A );

        $output = "<div id='ce-event-list'>\n";

		$output .= "<table class='widefat' style='clear:none;width:100%;background: #DFDFDF url(/wp-admin/images/gray-grad.png) repeat-x scroll left top;'>\n";
		$output .= "\t<thead>\n";
		$output .= "\t\t<tr>\n";
		$output .= "\t\t\t<th scope='col' id='id' class='manage-column column-id' >ID</th>\n";

		if ($moderate == true)
			$output .= "\t\t\t<th scope='col' id='approveevents' class='manage-column column-id' ></th>\n";

		$output .= "\t\t\t<th scope='col' id='name' class='manage-column column-name' style=''>" . __( 'Name', 'community-events' ) . "</th>\n";
		$output .= "\t\t\t<th scope='col' id='day' class='manage-column column-day' style='text-align: right'>" . __( 'Date(s)', 'community-events' ) . "</th>\n";
		$output .= "\t\t\t<th scope='col' style='text-align: right' id='starttime' class='manage-column column-items' style=''>" . __( 'Time', 'community_events' ) . "</th>\n";
		$output .= "\t\t\t<th scope='col' style='text-align: right' id='published' class='manage-column column-items' style=''>" . __( 'Published', 'community-events') . "</th>\n";
		$output .= "\t\t\t<th></th>\n";
		$output .= "\t\t</tr>\n";
		$output .= "\t</thead>\n";
		$output .= "\t<tbody class='list:link-cat'>\n";

		if ( $events ) {
			foreach( $events as $event ) {
				$output .= "\t\t<tr>\n";
				$output .= "\t\t\t<td class='name column-name' style='background: #FFF'>" . $event['event_id'] . "</td>\n";

				if ($moderate == true)
					$output .= "\t\t\t<td style='background: #FFF'><input type='checkbox' name='events[]' value='" . $event['event_id'] . "' /></td>\n";

				$output .= "\t\t\t<td style='background: #FFF'><a href='admin.php?page=community-events-events&amp;editevent=" . $event['event_id'] . "&pagecount=" . $page . "'><strong>" . stripslashes($event['event_name']) . "</strong></a></td>\n";
				$output .= "\t\t\t<td style='background: #FFF;text-align:right'>" . $event['event_start_date'] . ($event['event_end_date'] == NULL ? '' : ' - ' . $event['event_end_date']) . "</td>\n";
				$output .= "\t\t\t<td style='background: #FFF;text-align:right'>" . $event['event_start_hour'] . ":" . $event['event_start_minute'] . " " . $event['event_start_ampm'] . "</td>\n";
				$output .= "\t\t\t<td style='background: #FFF;text-align:right'>" . $event['event_published'] . "</td>\n";
				$output .= "\t\t\t<td style='background:#FFF'><a href='admin.php?page=community-events-events&amp;deleteevent=" . $event['event_id'] . "&pagecount=" . $page . "&_wpnonce=" . wp_create_nonce( 'ce_delete_event' ) . "'\n";
				$output .= "\t\t\tonclick=\"if ( confirm('" . esc_js(sprintf( __("You are about to delete the event '%s'\n  'Cancel' to stop, 'OK' to delete.", 'community_events'), $event['event_name'] )) . "') ) { return true;}return false;\"><img src='" . $this->cepluginpath . "/icons/delete.png' /></a></td>\n";
				$output .= "\t\t\t</tr>\n";
			}
		} else {
			$output .= "<tr><td  style='background: #FFF' colspan='" . ($moderate == true ? '7' : '6') . "'>" . __( 'No events found', 'community-events') . ($moderate == true ? __( " to moderate", 'community-events') : "") . ".</td></tr>\n";
		}

		$output .= "\t</tbody>\n";
		$output .= "</table>\n";

		if ($page > 1)
			$output .= "<div class='navleft' style='float: left; padding-top: 4px'><button id='previouspage' onClick='navigateLeft()'><img alt='" . __( 'Previous page of events', 'community-events') . "' src='" . $this->cepluginpath . "/icons/resultset_previous.png' /></button></div>\n";

		if ((($page) * 10) < $count)
			$output .= "<div class='navright' style='float: right; padding-top: 4px'><button id='nextpage' onClick='navigateRight()'><img alt='" . __( 'Next page of events', 'community-events' ) . "' src='" . $this->cepluginpath . "/icons/resultset_next.png' /></button></div>\n";

		$output .= "</div>\n";

		return $output;
	}

	function general_usage_meta_box($data) { ?>

		<table class='widefat' style='clear:none;width:100%;background-color:#F1F1F1;background-image: linear-gradient(to top, #ECECEC, #F9F9F9);background-position:initial initial;background-repeat: initial initial'>
			<thead>
			<tr>
				<td><?php _e( 'Functionality', 'community-events'); ?></td>
				<td><?php _e( 'Code to place in Wordpress page to activate', 'community-events'); ?></td>
			</tr>
			</thead>
			<tr>
				<td style='background-color: #fff'><?php _e( '7-day event view with optional outlook', 'community-events'); ?></td>
				<td style='background-color: #fff'>[community-events-7day]</td>
			</tr>
			<tr>
				<td style='background-color: #fff'><?php _e( 'Full schedule table', 'community-events' ); ?></td>
				<td style='background-color: #fff'>[community-events-full]</td>
			</tr>
			<tr>
				<td style='background-color: #fff'><?php _e( 'Event submission form', 'community-events' ); ?></td>
				<td style='background-color: #fff'>[community-events-addevent]</td>
			</tr>
		</table>

    <?php }

	function general_config_meta_box($data) {
		$options = $data['options'];
	?>

		<table>
			<tr>
				<td><?php _e('Full Schedule URL', 'community-events'); ?></td>
				<td colspan='4'><input style="width:100%" type="text" name="fullscheduleurl" <?php echo "value='" . $options['fullscheduleurl'] . "'";?>/></td>
			</tr>
			<tr>
				<td><?php _e('Event submission form URL', 'community-events'); ?></td>
				<td colspan='4'><input style="width:100%" type="text" name="addeventurl" <?php echo "value='" . $options['addeventurl'] . "'";?>/></td>
			</tr>
			<tr>
				<td><?php _e('Show outlook view in 7-day view', 'community-events'); ?></td>
				<td><input type="checkbox" id="outlook" name="outlook" <?php if ($options['outlook']) echo ' checked="checked" '; ?>/></td>
				<td style='width: 100px'></td>
				<td><?php _e('Default to outview view when displayed', 'community-events'); ?></td>
				<td><input type="checkbox" id="outlookdefault" name="outlookdefault" <?php if ($options['outlookdefault']) echo ' checked="checked" '; ?>/></td>
			</tr>
			<tr>
				<td><?php _e('Show search box in 7-day view', 'community-events'); ?></td>
				<td><input type="checkbox" id="displaysearch" name="displaysearch" <?php if ($options['displaysearch']) echo ' checked="checked" '; ?>/></td>
				<td style='width: 100px'></td>
				<td><?php _e('Display Fields to enter event end time', 'community-events'); ?></td>
				<td><input type="checkbox" id="displayendtimefield" name="displayendtimefield" <?php if ($options['displayendtimefield']) echo ' checked="checked" '; ?>/></td>
			</tr>
			<tr>
				<td><?php _e('Number of events per page in Full View', 'community-events'); ?></td>
				<td><input style="width:50px" type="text" id="fullvieweventsperpage" name="fullvieweventsperpage" <?php if ($options['fullvieweventsperpage'] != '') echo "value='" . $options['fullvieweventsperpage'] . "'"; else echo "value='20'"; ?>/></td>
				<td style='width: 100px'></td>
				<td><?php _e('Max number of events per day in 7-day view', 'community-events'); ?></td>
				<td><input style="width:50px" type="text" name="maxevents7dayview" <?php if ($options['maxevents7dayview'] != '') echo "value='" . $options['maxevents7dayview'] . "'"; else echo "value='5'"; ?>/></td>
			</tr>
			<tr>
				<td><?php _e('Max Number of days in Full View', 'community-events'); ?></td>
				<td><input style="width:50px" type="text" id="fullviewmaxdays" name="fullviewmaxdays" <?php if ($options['fullviewmaxdays'] != '') echo "value='" . $options['fullviewmaxdays'] . "'"; else echo "value='90'"; ?>/></td>
                <td style='width: 100px'></td>
                <td><?php _e('Event Display Order', 'community-events'); ?></td>
                <td><select id="eventorder" name="eventorder">
                        <option value="eventnameorder" <?php selected($options['eventorder'], 'eventnameorder'); ?>>Event Name
                        <option value="eventtimeorder" <?php selected($options['eventorder'], 'eventorder'); ?>>Event Time
                    </select></td>
			</tr>
		</table>

	<?php }

	function general_user_sub_meta_box($data) {
		$options = $data['options'];
		?>

						<table>
						<tr>
							<td style='width:200px'><?php _e('Require login to display form', 'community-events'); ?></td>
							<td style='width:75px;padding-right:20px'><input type="checkbox" id="addeventreqlogin" name="addeventreqlogin" <?php if ($options['addeventreqlogin']) echo ' checked="checked" '; ?>/></td>
							<td style='width: 20px'></td>
							<td style='width:200px'><?php _e('Send e-mail when new event is submitted', 'community-events'); ?></td>
							<td style='width:75px;padding-right:20px'><input type="checkbox" id="emailnewevent" name="emailnewevent" <?php if ($options['emailnewevent']) echo ' checked="checked" '; ?>/></td>
						</tr>
						<tr>
							<td style='width:200px'><?php _e('Wait for validation before displaying user events', 'community-events'); ?></td>
							<td style='width:75px;padding-right:20px'><input type="checkbox" id="moderateevents" name="moderateevents" <?php if ($options['moderateevents'] === true) echo ' checked="checked" '; ?>/></td>
							<td style='width: 20px'></td>
							<td style='width:200px'><?php _e('Display captcha', 'community-events'); ?></td>
							<td style='width:75px;padding-right:20px'><input type="checkbox" id="captchaevents" name="captchaevents" <?php if ($options['captchaevents']) echo ' checked="checked" '; ?>/></td>
						</tr>
						<tr>
							<td style='width:200px'><?php _e('Store submitter user name (if available)', 'community-events'); ?></td>
							<td style='width:75px;padding-right:20px'><input type="checkbox" id="storelinksubmitter" name="storelinksubmitter" <?php if ($options['storelinksubmitter'] == true) echo ' checked="checked" '; ?>/></td>
							<td style='width: 20px'></td>
							<td style='width:200px'><?php _e('Allow users to edit their events', 'community-events'); ?></td>
							<td style='width:75px;padding-right:20px'><input type="checkbox" id="allowuserediting" name="allowuserediting" <?php if ($options['allowuserediting']) echo ' checked="checked" '; ?>/></td>
						</tr>
						<tr>
							<td style='width:200px'><?php _e('Add new event label', 'community-events'); ?></td>
							<?php if ($options['addneweventmsg'] == "") $options['addneweventmsg'] = __('Add New Event', 'community-events'); ?>
							<td><input type="text" id="addneweventmsg" name="addneweventmsg" size="30" value="<?php echo $options['addneweventmsg']; ?>"/></td>
							<td style='width: 20px'></td>
							<td style='width:200px'><?php _e('Event Name Label', 'community-events'); ?></td>
							<?php if ($options['eventnamelabel'] == "") $options['eventnamelabel'] = __('Event Name', 'community-events'); ?>
							<td><input type="text" id="eventnamelabel" name="eventnamelabel" size="30" value="<?php echo $options['eventnamelabel']; ?>"/></td>
						</tr>
						<tr>
							<td style='width:200px'><?php _e('Event Category Label', 'community-events'); ?></td>
							<?php if ($options['eventcatlabel'] == "") $options['eventcatlabel'] = __('Event Category', 'community-events'); ?>
							<td><input type="text" id="eventcatlabel" name="eventcatlabel" size="30" value="<?php echo $options['eventcatlabel']; ?>"/></td>
							<td style='width: 20px'></td>
							<td style='width:200px'><?php _e('Event Venue Label', 'community-events'); ?></td>
							<?php if ($options['eventvenuelabel'] == "") $options['eventvenuelabel'] = __('Event Venue Label', 'community-events'); ?>
							<td><input type="text" id="eventvenuelabel" name="eventvenuelabel" size="30" value="<?php echo $options['eventvenuelabel']; ?>"/></td>
						</tr>
						<tr>
							<td style='width:200px'><?php _e('Event Description Label', 'community-events'); ?></td>
							<?php if ($options['eventdesclabel'] == "") $options['eventdesclabel'] = __('Event Description', 'community-events'); ?>
							<td><input type="text" id="eventdesclabel" name="eventdesclabel" size="30" value="<?php echo $options['eventdesclabel']; ?>"/></td>
							<td style='width: 20px'></td>
							<td style='width:200px'><?php _e('Event Web Address Label', 'community-events'); ?></td>
							<?php if ($options['eventaddrlabel'] == "") $options['eventaddrlabel'] = __('Event Web Address', 'community-events'); ?>
							<td><input type="text" id="eventaddrlabel" name="eventaddrlabel" size="30" value="<?php echo $options['eventaddrlabel']; ?>"/></td>
						</tr>
						<tr>
							<td style='width:200px'><?php _e('Event Ticket Purchase Link Label', 'community-events'); ?></td>
							<?php if ($options['eventticketaddrlabel'] == "") $options['eventticketaddrlabel'] = __('Event Ticket Purchase Link Label', 'community-events'); ?>
							<td><input type="text" id="eventticketaddrlabel" name="eventticketaddrlabel" size="30" value="<?php echo $options['eventticketaddrlabel']; ?>"/></td>
							<td style='width:200px'></td>
							<td style='width:200px'><?php _e('Event Start Date Label', 'community-events'); ?></td>
							<?php if ($options['eventdatelabel'] == "") $options['eventdatelabel'] = __('Event Start Date', 'community-events'); ?>
							<td><input type="text" id="eventdatelabel" name="eventdatelabel" size="30" value="<?php echo $options['eventdatelabel']; ?>"/></td>
						</tr>
						<tr>
							<td style='width:200px'><?php _e('Event End Date Label', 'community-events'); ?></td>
							<?php if ($options['eventenddatelabel'] == "") $options['eventenddatelabel'] = __('Event End Date', 'community-events'); ?>
							<td><input type="text" id="eventenddatelabel" name="eventenddatelabel" size="30" value="<?php echo $options['eventenddatelabel']; ?>"/></td>
							<td style='width:200px'></td>
							<td style='width:200px'><?php _e('Event Time Label', 'community-events'); ?></td>
							<?php if ($options['eventtimelabel'] == "") $options['eventtimelabel'] = __('Event Time', 'community-events'); ?>
							<td><input type="text" id="eventtimelabel" name="eventtimelabel" size="30" value="<?php echo $options['eventtimelabel']; ?>"/></td>
						</tr>
						<tr>
							<td style='width:200px'><?php _e('Event End Time Label', 'community-events'); ?></td>
							<?php if ($options['eventendtimelabel'] == "") $options['eventendtimelabel'] = __('Event End Time', 'community-events'); ?>
							<td><input type="text" id="eventendtimelabel" name="eventendtimelabel" size="30" value="<?php echo $options['eventendtimelabel']; ?>"/></td>
						</tr>
						<tr>
							<td style='width:200px'><?php _e('Add Event Button Label', 'community-events'); ?></td>
							<?php if ($options['addeventbtnlabel'] == "") $options['addeventbtnlabel'] = __('Add Event', 'community-events'); ?>
							<td><input type="text" id="addeventbtnlabel" name="addeventbtnlabel" size="30" value="<?php echo $options['addeventbtnlabel']; ?>"/></td>
							<td style='width:200px'></td>
							<td style='width:200px'><?php _e('Update Event Button Label', 'community-events'); ?></td>
							<?php if ($options['updateeventbtnlabel'] == "") $options['updateeventbtnlabel'] = __('Update Event', 'community-events'); ?>
							<td><input type="text" id="updateeventbtnlabel" name="updateeventbtnlabel" size="30" value="<?php echo $options['updateeventbtnlabel']; ?>"/></td>
						</tr>
						<tr>
							<td style='width:200px'><?php _e('Allow users to submit new venues', 'community-events'); ?></td>
							<td style='width:75px;padding-right:20px'><input type="checkbox" id="allowuservenuesubmissions" name="allowuservenuesubmissions" <?php if ($options['allowuservenuesubmissions'] === true) echo ' checked="checked" '; ?>/></td>
						</tr>
						<tr>
							<td style='width:200px'><?php _e('New Venue Name Label', 'community-events'); ?></td>
							<?php if ($options['newvenuenamelabel'] == "") $options['newvenuenamelabel'] = __('New Venue Name', 'community-events'); ?>
							<td><input type="text" id="newvenuenamelabel" name="newvenuenamelabel" size="30" value="<?php echo $options['newvenuenamelabel']; ?>"/></td>
							<td style='width:200px'></td>
							<td style='width:200px'><?php _e('New Venue Address Label', 'community-events'); ?></td>
							<?php if ($options['newvenueaddresslabel'] == "") $options['newvenueaddresslabel'] = __('New Venue Address', 'community-events'); ?>
							<td><input type="text" id="newvenueaddresslabel" name="newvenueaddresslabel" size="30" value="<?php echo $options['newvenueaddresslabel']; ?>"/></td>
						</tr>
						<tr>
							<td style='width:200px'><?php _e('New Venue City Label', 'community-events'); ?></td>
							<?php if ($options['newvenuecitylabel'] == "") $options['newvenuecitylabel'] = __('New Venue City', 'community-events'); ?>
							<td><input type="text" id="newvenuecitylabel" name="newvenuecitylabel" size="30" value="<?php echo $options['newvenuecitylabel']; ?>"/></td>
							<td style='width:200px'></td>
							<td style='width:200px'><?php _e('New Venue Zip Code Label', 'community-events'); ?></td>
							<?php if ($options['newvenuezipcodelabel'] == "") $options['newvenuezipcodelabel'] = __('New Venue Zip Code', 'community-events'); ?>
							<td><input type="text" id="newvenuezipcodelabel" name="newvenuezipcodelabel" size="30" value="<?php echo $options['newvenuezipcodelabel']; ?>"/></td>
						</tr>
						<tr>
							<td style='width:200px'><?php _e('New Venue Phone Label', 'community-events'); ?></td>
							<?php if ($options['newvenuephonelabel'] == "") $options['newvenuephonelabel'] = __('New Venue Phone', 'community-events'); ?>
							<td><input type="text" id="newvenuephonelabel" name="newvenuephonelabel" size="30" value="<?php echo $options['newvenuephonelabel']; ?>"/></td>
							<td style='width:200px'></td>
							<td style='width:200px'><?php _e('New Venue E-mail Label', 'community-events'); ?></td>
							<?php if ($options['newvenueemaillabel'] == "") $options['newvenueemaillabel'] = __('New Venue E-mail', 'community-events'); ?>
							<td><input type="text" id="newvenueemaillabel" name="newvenueemaillabel" size="30" value="<?php echo $options['newvenueemaillabel']; ?>"/></td>
						</tr>
						<tr>
							<td style='width:200px'><?php _e('New Venue URL Label', 'community-events'); ?></td>
							<?php if ($options['newvenueurllabel'] == "") $options['newvenueurllabel'] = __('New Venue URL', 'community-events'); ?>
							<td><input type="text" id="newvenueurllabel" name="newvenueurllabel" size="30" value="<?php echo $options['newvenueurllabel']; ?>"/></td>
						</tr>
					</table>

	<?php }

	function general_user_rssgen_box($data) {
		$options = $data['options'];
		?>

						<table>
						<tr>
							<td style='width:200px'><?php _e('Add Event RSS Feed to page header', 'community-events'); ?></td>
							<td style='width:75px;padding-right:20px'><input type="checkbox" id="publishfeed" name="publishfeed" <?php if ($options['publishfeed']) echo ' checked="checked" '; ?>/></td>
						</tr>
						<tr>
							<td style='width:200px'><?php _e('RSS Feed Title', 'community-events'); ?></td>
							<td style='width:75px;padding-right:20px'><input type="text" id="rssfeedtitle" name="rssfeedtitle" size="80" value="<?php echo $options['rssfeedtitle']; ?>"/></td>
						</tr>
						<tr>
							<td style='width:200px'><?php _e('RSS Feed Description', 'community-events'); ?></td>
							<td style='width:75px;padding-right:20px'><input type="text" id="rssfeeddescription" name="rssfeeddescription" size="80" value="<?php echo $options['rssfeeddescription']; ?>"/></td>
						</tr>
						<tr>
							<td style='width:200px'><?php _e('RSS Feed Target Address', 'community-events'); ?></td>
							<td style='width:75px;padding-right:20px'><input type="text" id="rssfeedtargetaddress" name="rssfeedtargetaddress" size="80" value="<?php echo $options['rssfeedtargetaddress']; ?>"/></td>
						</tr>
					</table>

	<?php }

	function general_save_meta_box($data) {
		$options = $data['options'];
		?>
		<div class="submitbox">
			<input type="submit" name="submit" class="button-primary" value="<?php _e('Save Settings','community-events'); ?>" />
		</div>

	<?php }

	function event_types_meta_box($data) {
		$options = $data['options'];
		$mode = $data['mode'];
		$selectedcat = $data['selectedcat'];
		global $wpdb;
		?>
			<table style='width: 100%'>
				<tr>
					<td style='vertical-align: top; width: 45%'>
						<?php if ($mode == "edit"): ?>
						<strong><?php _e('Editing Category #', 'community-events'); ?><?php echo $selectedcat->event_cat_id; ?></strong><br /><br />
						<?php endif; ?>
						Category Name<br /><br /><input style='width: 95%' type="text" name="name" <?php if ($mode == "edit") echo "value='" . stripslashes($selectedcat->event_cat_name) . "'";?>/>
						<input type="hidden" name="id" value="<?php if ($mode == "edit") echo $selectedcat->event_cat_id; ?>" />
					</td>
					<td style='width: 55%; vertical-align: top'>
						<?php $cats = $wpdb->get_results("SELECT count( e.event_id ) AS nbitems, c.event_cat_id, c.event_cat_name FROM " . $wpdb->prefix . "ce_category c LEFT JOIN " . $wpdb->prefix . "ce_events e ON e.event_category = c.event_cat_id GROUP BY c.event_cat_name");

						if ($cats): ?>
							<table class='widefat' style='clear:none;background: #DFDFDF url(/wp-admin/images/gray-grad.png) repeat-x scroll left top;'>
							<thead>
							<tr>
							<th scope='col' id='id' class='manage-column column-id' ><?php _e( 'ID', 'community-events' ); ?></th>
							<th scope='col' id='name' class='manage-column column-name' style=''><?php _e( 'Name', 'community-events' ); ?></th>
							<th scope='col' style='text-align: right' id='items' class='manage-column column-items' style=''><?php _e( 'Items', 'community-events' ); ?></th>
							<th ></th>
							</tr>
							</thead>

							<tbody id='the-list' class='list:link-cat'>

							<?php foreach($cats as $cat): ?>
							<tr>
							<td class='name column-name' style='background: #FFF'><?php echo $cat->event_cat_id; ?></td>
							<td style='background: #FFF'><a href='admin.php?page=community-events-event-types&amp;editcat=<?php echo $cat->event_cat_id; ?>'><strong><?php echo stripslashes($cat->event_cat_name); ?></strong></a></td>
							<td style='background: #FFF;text-align:right'><?php echo $cat->nbitems; ?></td>
							<?php if ($cat->nbitems == 0): ?>
							<td style='background:#FFF'><a href='admin.php?page=community-events-event-types&amp;deletecat=<?php echo $cat->event_cat_id; ?>&_wpnonce=<?php echo wp_create_nonce( 'ce_delete_cat' ); ?>'
							<?php echo "onclick=\"if ( confirm('" . esc_js(sprintf( __("You are about to delete this category '%s'\n  'Cancel' to stop, 'OK' to delete.", 'community-events' ), $cat->event_cat_name )) . "') ) { return true;}return false;\"" ?>><img src='<?php echo $this->cepluginpath; ?>/icons/delete.png' /></a></td>
							<?php else: ?>
							<td style='background: #FFF'></td>
							<?php endif; ?>
							</tr>
							<?php endforeach; ?>

							</tbody>
							</table>
						<?php endif; ?>

						<p><?php _e( 'Categories can only be deleted when they do not have any associated events', 'community-events' ); ?>.</p>
					</td>
				</tr>
			</table>

		<?php }

	function event_types_save_meta_box($data) {
		$mode = $data['mode'];
		?>
			<div class="submitbox">

			<?php if ($mode == "edit"): ?>
				<input type="submit" name="updatecat" class="button-primary" value="<?php _e('Update &raquo;','community-events'); ?>" />
			<?php else: ?>
				<input type="submit" name="newcat" class="button-primary" value="<?php _e('Insert New Category &raquo;','community-events'); ?>" />
			<?php endif; ?>

			</div>
	<?php }

	function event_venues_meta_box($data) {
		$mode = $data['mode'];
		$selectedvenue = $data['selectedvenue'];
		global $wpdb;
		?>
			<table style='width: 100%'>
			<tr>
				<td style='width: 45%; vertical-align: top;'>
					<?php if ($mode == "edit"): ?>
					<strong><?php _e( 'Editing Venue #', 'community-events');  echo $selectedvenue->ce_venue_id; ?></strong><br />
					<?php endif; ?>
					<table style='width: 100%'>
						<tr>
							<td><?php _e( 'Venue Name', 'community-events' ); ?></td>
							<td><input style="width:95%" type="text" name="ce_venue_name" <?php if ($mode == "edit") echo 'value="' . stripslashes($selectedvenue->ce_venue_name) . '"';?>/></td>
						</tr>
						<tr>
							<td><?php _e( 'Venue Address', 'community-events' ); ?></td>
							<td><input style="width:95%" type="text" name="ce_venue_address" <?php if ($mode == "edit") echo "value='" . stripslashes($selectedvenue->ce_venue_address) . "'";?>/></td>
						</tr>
						<tr>
							<td><?php _e( 'Venue City', 'community-events' ); ?></td>
							<td><input style="width:95%" type="text" name="ce_venue_city" <?php if ($mode == "edit") echo "value='" . stripslashes($selectedvenue->ce_venue_city) . "'";?>/></td>
						</tr>
						<tr>
							<td><?php _e( 'Venue Zip Code', 'community-events' ); ?></td>
							<td><input style="width:95%" type="text" name="ce_venue_zipcode" <?php if ($mode == "edit") echo "value='" . $selectedvenue->ce_venue_zipcode . "'";?>/></td>
						</tr>
						<tr>
							<td><?php _e( 'Venue Phone', 'community-events' ); ?></td>
							<td><input style="width:95%" type="text" name="ce_venue_phone" <?php if ($mode == "edit") echo "value='" . $selectedvenue->ce_venue_phone . "'";?>/></td>
						</tr>
						<tr>
							<td><?php _e( 'Venue E-mail', 'community-events' ); ?></td>
							<td><input style="width:95%" type="text" name="ce_venue_email" <?php if ($mode == "edit") echo "value='" . $selectedvenue->ce_venue_email . "'";?>/></td>
						</tr>
						<tr>
							<td><?php _e( 'Venue URL', 'community-events' ); ?></td>
							<td><input style="width:95%" type="text" name="ce_venue_url" <?php if ($mode == "edit") echo "value='" . $selectedvenue->ce_venue_url . "'";?>/></td>
						</tr>
					</table>
					<input type="hidden" name="ce_venue_id" value="<?php if ($mode == "edit") echo $selectedvenue->ce_venue_id; ?>" />
				</td>
				<td style='width:55%; vertical-align: top;'>
					<?php $venues = $wpdb->get_results("SELECT count( e.event_id ) AS nbitems, v.ce_venue_id, v.ce_venue_name FROM " . $wpdb->prefix . "ce_venues v LEFT JOIN " . $wpdb->prefix . "ce_events e ON e.event_venue = v.ce_venue_id GROUP BY v.ce_venue_id ORDER by v.ce_venue_name");

					if ($venues): ?>
						<table class='widefat' style='clear:none;width:100%;background: #DFDFDF url(/wp-admin/images/gray-grad.png) repeat-x scroll left top;'>
						<thead>
						<tr>
						<th scope='col' style='width: 50px' id='id' class='manage-column column-id' ><?php _e( 'ID', 'community-events' ); ?></th>
						<th scope='col' id='name' class='manage-column column-name' style=''><?php _e( 'Name', 'community-events' ); ?></th>
						<th scope='col' style='width: 40px;text-align: right' id='items' class='manage-column column-items' style=''><?php _e( 'Items', 'community-events' ); ?></th>
						<th style='width: 30px'></th>
						</tr>
						</thead>

						<tbody id='the-list' class='list:link-cat'>

					<?php foreach($venues as $venue): ?>
						<tr>
						<td class='name column-name' style='background: #FFF'><?php echo $venue->ce_venue_id; ?></td>
						<td style='background: #FFF'><a href='admin.php?page=community-events-venues&amp;editvenue=<?php echo $venue->ce_venue_id; ?>'><strong><?php echo stripslashes($venue->ce_venue_name); ?></strong></a></td>
						<td style='background: #FFF;text-align:right'><?php echo $venue->nbitems; ?></td>
						<?php if ($venue->nbitems == 0): ?>
						<td style='background:#FFF'><a href='admin.php?page=community-events-venues&amp;deletevenue=<?php echo $venue->ce_venue_id; ?>&_wpnonce=<?php echo wp_create_nonce( 'ce_delete_venue' ); ?>'
						<?php echo "onclick=\"if ( confirm('" . esc_js(sprintf( __("You are about to delete this venue '%s'\n  'Cancel' to stop, 'OK' to delete.", 'community-events' ), $venue->ce_venue_name )) . "') ) { return true;}return false;\"" ?>><img src='<?php echo $this->cepluginpath; ?>/icons/delete.png' /></a></td>
						<?php else: ?>
						<td style='background: #FFF'></td>
						<?php endif; ?>
						</tr>
					<?php endforeach; ?>

					</tbody>
					</table>

					<?php endif; ?>

					<p><?php _e( 'Venues can only be deleted when they do not have any associated events', 'community-events' ); ?>.</p>

				</td>
			</tr>
			</table>

	<?php }

	function event_venues_importer_meta_box($data) {
	?>
		<table>
			<tr>
				<td class='cetooltip' title='<?php _e('Allows for venues to be added in batch in Community Events. CSV file needs to follow template for column layout.', 'community-events'); ?>' style='width: 330px'><?php _e('CSV file to upload to import venues', 'community-events'); ?> (<a href="<?php echo $this->cepluginpath . 'venuesimporttemplate.csv'; ?>"><?php _e('file template', 'community-events'); ?></a>)</td>
				<td><input size="80" name="venuesfile" type="file" /></td>
				<td><input type="submit" name="importvenues" value="<?php _e('Import Venues', 'community-events'); ?>" /></td>
			</tr>
			<tr>
				<td><?php _e('First row contains column headers', 'community-events'); ?></td>
				<td><input type="checkbox" id="firstrowheaders" name="firstrowheaders" checked="checked" /></td>
			</tr>
		</table>
	<?php }

	function event_venues_save_meta_box($data) {
		$mode = $data['mode'];
		?>

		<div class="submitbox">

		<?php if ($mode == "edit"): ?>
			<input type="submit" name="updatevenue" class="button-primary" value="<?php _e('Update &raquo;','community-events'); ?>" />
		<?php else: ?>
			<input type="submit" name="newvenue" class="button-primary" value="<?php _e('Insert New Venue &raquo;','community-events'); ?>" />
		<?php endif; ?>

		</div>

	<?php }

	function events_meta_box($data) {
		$mode = $data['mode'];
		$selectedevent = $data['selectedevent'];
		$options = $data['options'];

		$currentday = date("z", current_time('timestamp')) + 1;
		if (date("L") == 1 && $currentday > 60)
			$currentday++;

		$currentyear = date("Y");
		global $wpdb;
		?>
			<script type="text/javascript">

			function disableEnterKey(e)
			{
			var key = (window.event) ? event.keyCode : e.which;
			return (key != 13);
			}

			function validatefields()
			{
				var allowsubmit = false;
				var startdate, enddate;

				if (jQuery('#datepickerstart').val() != "")
				{
					if (jQuery('#datepickerend').val() != "")
					{
						startdate = jQuery('#datepickerstart').datepicker("getDate");
						enddate = jQuery('#datepickerend').datepicker("getDate");

						if (enddate < startdate)
							alert( "<?php _e( "End date must be equal or later than start date" ); ?>");
						else
							allowsubmit = true;
					}
					else
						allowsubmit = true;
				}

				if (jQuery('#event_name').val() == "")
				{
					allowsubmit = false;
				}

				if (allowsubmit == false)
					jQuery('#<?php if ($mode == 'edit') echo 'updateevent'; else echo 'newevent'; ?>').prop('disabled', true);
				else
					jQuery('#<?php if ($mode == 'edit') echo 'updateevent'; else echo 'newevent'; ?>').prop('disabled', false);

			}

			 jQuery(document).ready(function() {
					jQuery("#datepickerstart").datepicker({minDate: '+0', dateFormat: 'mm-dd-yy', showOn: 'both', constrainInput: true, buttonImage: '<?php echo $this->cepluginpath; ?>/icons/calendar.png', onSelect: function(dateText, inst) {
						var selectedDate = new Date(inst.currentYear, inst.currentMonth, inst.currentDay);
						jQuery("#datepickerend").datepicker( "option", {minDate: selectedDate } );
						validatefields();
					} });
					jQuery("#datepickerend").datepicker({minDate: '+0', dateFormat: 'mm-dd-yy', showOn: 'both', buttonImage: '<?php echo $this->cepluginpath; ?>/icons/calendar.png', onSelect: function(dateText, inst) {
						var selectedDate = new Date(inst.currentYear, inst.currentMonth, inst.currentDay);
						jQuery("#datepickerstart").datepicker( "option", {maxDate: selectedDate } );
						validatefields();
					} });
					jQuery('#event_name').change(function() { validatefields();});
					jQuery('#datepickerstart').change(function() { validatefields();});
					jQuery('#datepickerend').change(function() { validatefields();});
			 });
			</script>

			<table style='width: 100%;'>
				<tr>
					<td style='width: 45%; vertical-align: top'>
						<input type="hidden" name="event_id" onKeyPress='return disableEnterKey(event)' value="<?php if ($mode == "edit") echo $selectedevent->event_id; ?>" on/>
						<?php if ($mode == "edit"): ?>
						<strong><?php _e( 'Editing Item #', 'community-events' ); echo $selectedevent->event_id; ?></strong>
						<?php endif; ?>

						<table>
						<tr>
						<td class='required' style='width: 30%'>Event Name</td>
						<td><input style="width:100%" type="text" id="event_name" name="event_name" onKeyPress='return disableEnterKey(event)' <?php if ($mode == "edit") echo 'value="' . stripslashes($selectedevent->event_name) . '"';?>/></td>
						</tr>
						<tr>
						<td><?php _e( 'Category', 'community-events' ); ?></td>
						<td><select style='width:100%' name="event_category">
						<?php $cats = $wpdb->get_results("SELECT * from " . $wpdb->prefix. "ce_category ORDER by event_cat_name");

							foreach ($cats as $cat)
							{
								if ( isset( $selectedevent->event_category ) && $cat->event_cat_id == $selectedevent->event_category)
										$selectedstring = "selected='selected'";
									else
										$selectedstring = "";

								echo "<option value='" . $cat->event_cat_id . "' " . $selectedstring . ">" .  stripslashes($cat->event_cat_name) . "\n";
							}
						?></select></td>
						</tr>
						<tr>
						<td><?php _e( 'Venue', 'community-events' ); ?></td>
						<td><select style='width:100%' name="event_venue">
						<?php $venues = $wpdb->get_results("SELECT * from " . $wpdb->prefix. "ce_venues ORDER by ce_venue_name");

							foreach ($venues as $venue)
							{
								if ( isset( $selectedevent ) && !empty( $selectedevent ) && $venue->ce_venue_id == $selectedevent->event_venue )
										$selectedstring = "selected='selected'";
									else
										$selectedstring = "";

								echo "<option value='" . $venue->ce_venue_id . "' " . $selectedstring . ">" .  stripslashes($venue->ce_venue_name) . "\n";
							}
						?></select></td>
						</tr>
						<tr>
							<td><?php _e( 'Description', 'community-events' ); ?></td>
							<td><input style="width:100%" type="text" name="event_description" onKeyPress='return disableEnterKey(event)' <?php if ($mode == "edit") echo "value='" . stripslashes($selectedevent->event_description) . "'";?>/></td>
						</tr>
						<tr>
							<td><?php _e( 'Event Web Address', 'community-events' ); ?></td>
							<td><input style="width:100%" type="text" name="event_url" onKeyPress='return disableEnterKey(event)' <?php if ($mode == "edit") echo "value='" . $selectedevent->event_url . "'";?>/></td>
						</tr>
						<tr>
							<td><?php _e( 'Event Ticket Purchase Web Address', 'community-events' ); ?></td>
							<td><input style="width:100%" type="text" name="event_ticket_url" onKeyPress='return disableEnterKey(event)' <?php if ($mode == "edit") echo "value='" . $selectedevent->event_ticket_url . "'";?>/></td>
						</tr>
						<tr class='required'>
							<td><?php _e( 'Start Date', 'community-events' ); ?></td>
							<td><input type="text" id="datepickerstart" name="event_start_date" size="26" onKeyPress='return disableEnterKey(event)' <?php if ($mode == "edit") echo "value='" . $selectedevent->event_start_date . "'"; else echo "value='" . date('m-d-Y', current_time('timestamp')) . "'"; ?>/></td>
						</tr>
						<tr>
						<?php if ($options['displayendtimefield'] != true): ?>
						<td><?php _e( 'Time', 'community-events' ); ?></td>
						<?php else: ?>
						<td><?php _e( 'Start Time', 'community-events' ); ?></td>
						<?php endif; ?>
						<td>
							<select name="event_start_hour" style="width: 50px">
								<?php for ($i = 1; $i <= 12; $i++)
									  {
											echo "<option value=" . $i;

											if (isset( $selectedevent ) && !empty( $selectedevent ) && $i == $selectedevent->event_start_hour) echo " selected";

											echo ">" . $i . "</option>\n";
									  }
								?>
							</select>
							:
							<select name="event_start_minute" style="width: 50px">
								<?php $minutes = array('00', '15', '30', '45');
									  foreach ($minutes as $minute)
									  {
											echo "<option value=" . $minute;

											if (isset( $selectedevent ) && !empty( $selectedevent ) && $minute == $selectedevent->event_start_minute) echo " selected";

											echo ">" . $minute . "</option>\n";
									  }
								?>
							</select>
							<select name="event_start_ampm" style="width: 50px">
								<option value="AM" <?php if (isset( $selectedevent ) && !empty( $selectedevent ) && $selectedevent->event_start_ampm == 'AM') echo "selected='selected'"; ?>><?php _e( 'AM', 'community-events' ); ?></option>
								<option value="PM" <?php if (isset( $selectedevent ) && !empty( $selectedevent ) && $selectedevent->event_start_ampm == 'PM') echo "selected='selected'"; ?>><?php _e( 'PM', 'community-events' ); ?></option>
							</select>
						</td>
						</tr>
						<?php if ($options['displayendtimefield'] == true): ?>
						<tr>
						<td><?php _e( 'End Time', 'community-events' ); ?></td>
						<td>
							<select name="event_end_hour" style="width: 50px">
								<?php for ($i = 1; $i <= 12; $i++)
									  {
											echo "<option value=" . $i;

											if ($i == $selectedevent->event_end_hour) echo " selected";

											echo ">" . $i . "</option>\n";
									  }
								?>
							</select>
							:
							<select name="event_end_minute" style="width: 50px">
								<?php $minutes = array('00', '15', '30', '45');
									  foreach ($minutes as $minute)
									  {
											echo "<option value=" . $minute;

											if ($minute == $selectedevent->event_end_minute) echo " selected";

											echo ">" . $minute . "</option>\n";
									  }
								?>
							</select>
							<select name="event_end_ampm" style="width: 50px">
								<option value="AM" <?php if ($selectedevent->event_end_ampm == 'AM') echo "selected='selected'"; ?>><?php _e( 'AM', 'community-events' ); ?></option>
								<option value="PM" <?php if ($selectedevent->event_end_ampm == 'PM') echo "selected='selected'"; ?>><?php _e( 'PM', 'community-events' ); ?></option>
							</select>
						</td>
						</tr>
						<?php endif; ?>
						<tr>
						<td><?php _e( 'End Date', 'community-events' ); ?></td>
						<td><input type="text" id="datepickerend" name="event_end_date" size="26" onKeyPress='return disableEnterKey(event)' <?php if ($mode == "edit") echo "value='" . $selectedevent->event_end_date . "'"; ?>/></td>
						</tr>
						<tr>
							<td><?php _e( 'Event Submitter', 'community-events' ); ?></td>
							<td><?php if ($mode == "edit") echo $selectedevent->event_submitter; ?></td>
						</tr>
						<tr>
							<td><?php _e( 'Event Click Count', 'community-events' ); ?></td>
							<td><?php if ($mode == "edit") echo $selectedevent->event_click_count; ?></td>
						</tr>
						<tr>
						</table>
					</td>
					<td style='width:55%; vertical-align: top'>
						<span class='button'><a href='#' onclick='normalmode()'><?php _e('Event Management', 'community-events'); ?></a></span> <span class='button'><a href='#' id='moderate' onclick='moderatemode()'><?php _e('Moderation', 'community-events'); ?></a></span> <span class='button'><a href='#' id='approveselected' onclick='approveselected()'><?php _e('Approve Selected', 'community-events'); ?></a></span><br /><br />
						<?php
						$currentday = date("z", current_time('timestamp'));
						if (date("L") == 1 && $currentday > 60)
							$currentday++;

						$currentyear = date("Y");

						$eventcountquery = "SELECT count(*) from " . $wpdb->prefix . "ce_events where YEAR(event_start_date) = " . $currentyear . " and DAYOFYEAR(DATE(event_start_date)) >= " . $currentday;
						$eventcounttotal = $wpdb->get_var($eventcountquery);

						if (isset( $_GET['pagecount'] ) && $_GET['pagecount'] != "")
							$pagecount = intval( $_GET['pagecount'] );
						else
							$pagecount = 1;

						echo $this->print_event_table($currentyear, $currentday, $pagecount, false); ?>

						<input type='hidden' name='eventpage' id='eventpage' value='<?php echo $pagecount; ?>' />
						<input type='hidden' name='moderatestatus' id='moderatestatus' value='false' />

						<script type="text/javascript">

						function navigateRight()
						{
								var el = jQuery('#eventpage');
								el.val( parseInt( el.val(), 10 ) + 1 );
								var map = {action: 'community_events_admin_list', currentyear : <?php echo $currentyear; ?>, currentday : <?php echo $currentday; ?>, page: el.val(), moderate: jQuery('#moderatestatus').val() };
								jQuery('#ce-event-list').replaceWith('<div id=\"ce-event-list\"><img src=\"<?php echo WP_PLUGIN_URL; ?>/community-events/icons/Ajax-loader.gif\" alt=\"<?php _e( 'Loading data, please wait...', 'community-events'); ?>\"></div>');
								jQuery.get('<?php echo admin_url( 'admin-ajax.php' ); ?>', map, function(data){
									jQuery('#ce-event-list').replaceWith(data);});
						}

						function navigateLeft()
						{
								var el = jQuery('#eventpage');
								el.val( parseInt( el.val(), 10 ) - 1 );
								var map = {action: 'community_events_admin_list', currentyear : <?php echo $currentyear; ?>, currentday : <?php echo $currentday; ?>, page: el.val(), moderate: jQuery('#moderatestatus').val() };
								jQuery('#ce-event-list').replaceWith('<div id=\"ce-event-list\"><img src=\"<?php echo WP_PLUGIN_URL; ?>/community-events/icons/Ajax-loader.gif\" alt=\"<?php _e( 'Loading data, please wait...', 'community-events'); ?>\"></div>');jQuery.get('<?php echo admin_url( 'admin-ajax.php' ) ?>', map, function(data){jQuery('#ce-event-list').replaceWith(data);});
						}

						function moderatemode()
						{
							jQuery('#moderatestatus').val('true');
							var map = {action: 'community_events_admin_list', currentyear : <?php echo $currentyear; ?>, currentday : <?php echo $currentday; ?>, page: 1, moderate: 'true' };
							jQuery('#ce-event-list').replaceWith('<div id=\"ce-event-list\"><img src=\"<?php echo WP_PLUGIN_URL; ?>/community-events/icons/Ajax-loader.gif\" alt=\"<?php _e( 'Loading data, please wait...', 'community-events'); ?>\"></div>');jQuery.get('<?php echo admin_url( 'admin-ajax.php' ); ?>', map, function(data){jQuery('#ce-event-list').replaceWith(data);});
							jQuery('#eventpage').val(1);
						}

						function normalmode()
						{
							jQuery('#moderatestatus').val('false');
							var map = {action: 'community_events_admin_list', currentyear : <?php echo $currentyear; ?>, currentday : <?php echo $currentday; ?>, page: 1, moderate: 'false' };
							jQuery('#ce-event-list').replaceWith('<div id=\"ce-event-list\"><img src=\"<?php echo WP_PLUGIN_URL; ?>/community-events/icons/Ajax-loader.gif\" alt=\"<?php _e( 'Loading data, please wait...', 'community-events' ); ?>\"></div>');jQuery.get('<?php echo admin_url( 'admin-ajax.php' ); ?>', map, function(data){jQuery('#ce-event-list').replaceWith(data);});
							jQuery('#eventpage').val(1);
						}

						function approveselected()
						{
							var values = new Array();
							jQuery.each(jQuery("input[name='events[]']:checked"), function() {
							  values.push(jQuery(this).val());
							  var map = { action: 'community_events_approval', eventlist: values };
							  jQuery.get('<?php echo admin_url( 'admin-ajax.php' ); ?>', map, function(data) {});
							  var map = {action: 'community_events_admin_list', currentyear : <?php echo $currentyear; ?>, currentday : <?php echo $currentday; ?>, page: 1, moderate: 'true' };									  jQuery('#ce-event-list').replaceWith('<div id=\"ce-event-list\"><img src=\"<?php echo WP_PLUGIN_URL; ?>/community-events/icons/Ajax-loader.gif\" alt=\"<?php _e( 'Loading data, please wait...', 'community-events' ); ?>\"></div>');jQuery.get('<?php echo admin_url( 'admin-ajax.php' ); ?>', map, function(data){jQuery('#ce-event-list').replaceWith(data);});
							});
						}
						</script>
					</td>
				</tr>
			</table>
		<?php }

	function events_save_meta_box($data) {
		$mode = $data['mode'];
		?>

		<div class="submitbox">

		<?php if ($mode == "edit"): ?>
			<input type="submit" name="updateevent" id="updateevent" class="button-primary" value="<?php _e('Update &raquo;','community-events'); ?>" />
		<?php else: ?>
			<input type="submit" name="newevent" id="newevent" class="button-primary" disabled='disabled' value="<?php _e('Insert New Event &raquo;','community-events'); ?>" />
		<?php endif; ?>

		</div>

	<?php }

	function events_stylesheet_meta_box($data) {
		$options = $data['options'];
	?>

		<?php _e('If the stylesheet editor is empty after upgrading, reset to the default stylesheet using the button below or copy/paste your backup stylesheet into the editor.', 'community-events'); ?><br /><br />

		<textarea name='fullstylesheet' id='fullstylesheet' style='font-family:Courier' rows="30" cols="90">
<?php echo stripslashes( sanitize_text_field( $options['fullstylesheet']) );?>
</textarea>
		<div><input type="submit" name="submitstyle" value="<?php _e('Submit','community-events'); ?>" /><input type="submit" name="resetstyle" value="<?php _e('Reset to default','community-events'); ?>" /></div>
	<?php
	}

	function events_stylesheet_save_meta_box($data) {
	?>

	<?php }

	/********************************************* Shortcode processing functions *****************************************/

	function ce_header() {
		$options = get_option('CE_PP');

		echo "<style id='CommunityEventsStyle' type='text/css'>\n";
		echo stripslashes( sanitize_text_field( $options['fullstylesheet'] ) );
		echo "</style>\n";

		if ($options['publishfeed'] == true)
		{
			$feedtitle = ($options['rssfeedtitle'] == "" ? __('Community Events Calendar Feed', 'community-events') : $options['rssfeedtitle']);
			echo '<link rel="alternate" type="application/rss+xml" title="' . esc_html(stripslashes($feedtitle)) . '" href="' . home_url() . '/feed/communityeventsfeed" />';
		}
	}

	function ce_7day_func( $atts ) {
		extract( shortcode_atts( array(
			'filter_by_user' => ''
		), $atts ) );

		$options = get_option('CE_PP');

		if ( 'current' == $filter_by_user ) {
			$current_user = wp_get_current_user();
			$filter_by_user = $current_user->user_login;
		}

		if ( !empty( $filter_by_user ) ) {
			$filter_by_user = apply_filters( 'community_events_filter_user', $filter_by_user );
		}

		return $this->ce_7day( $options['fullscheduleurl'], $options['outlook'], $options['addeventurl'], $options['maxevents7dayview'], $options['moderateevents'],
							  $options['displaysearch'], $options['outlookdefault'], $options['allowuserediting'], $options['displayendtimefield'], $filter_by_user );
	}

	function eventlist ( $year, $dayofyear, $outlook = 'true', $showdate = 'false', $maxevents = 5, $moderateevents = 'false', $searchstring = '', $fullscheduleurl = '',
						$addeventurl = '', $allowuserediting = false, $displayendtimefield = false, $filterbyuser = '' ) {

		global $wpdb;

        $options = get_option ( 'CE_PP' );

		$output = "<table class='ce-7day-innertable' id='ce-7day-innertable'>\n";

		if ($searchstring != '')
		{
			$eventquery = "SELECT *, if(char_length(event_start_minute)=1,concat('0',event_start_minute),event_start_minute) as event_start_minute_zeros, if(char_length(event_end_minute)=1,concat('0',event_end_minute),event_end_minute) as event_end_minute_zeros from ";
			$eventquery .= $wpdb->prefix . "ce_events e LEFT JOIN " . $wpdb->prefix . "ce_venues v ON e.event_venue = v.ce_venue_id LEFT JOIN " . $wpdb->prefix . "ce_category c ON e.event_category = c.event_cat_id ";
			$eventquery .= "where ((event_name like '%s')";
			$eventquery .= "    or (ce_venue_name like '%s')";
			$eventquery .= "    or (event_description like '%s')";
			$eventquery .= ")";

			if ($moderateevents == 'true')
				$eventquery .= " and event_published = 'Y' ";

			if ( !empty( $filterbyuser ) ) {
				$eventquery .= " and event_submitter = '%s' ";
			}

			$eventquery .= " order by event_start_date, ";

            if ( $options['eventorder'] == 'eventnameorder' || empty( $options['eventorder'] ) ) {
                $eventquery .= 'event_name ';
            } else if ( $options['eventtimeorder'] ) {
                $eventquery .= 'event_start_ampm, start_hour, start_minute ';
            }

			if ( empty( $filterbyuser ) ) {
				$cleanquery = $wpdb->prepare( $eventquery, '%' . $searchstring . '%', '%' . $searchstring . '%', '%' . $searchstring . '%' );
			} else {
				$cleanquery = $wpdb->prepare( $eventquery, '%' . $searchstring . '%', '%' . $searchstring . '%', '%' . $searchstring . '%', $filterbyuser );
			}

			$events = $wpdb->get_results( $cleanquery, ARRAY_A );

			$output .= '<!-- Event query: ' . $eventquery . ' -->';

			if ($events)
			{
				foreach($events as $event)
				{
					$event['event_name'] = $this->ce_highlight_phrase($event['event_name'], $searchstring, '<span class="highlight_word">', '</span>');
					$event['ce_venue_name'] = $this->ce_highlight_phrase($event['ce_venue_name'], $searchstring, '<span class="highlight_word">', '</span>');
					$event['event_description'] = $this->ce_highlight_phrase($event['event_description'], $searchstring, '<span class="highlight_word">', '</span>');

					$output .= "<tr><td><span class='cetooltip ce-event-name' title='<strong>" . _e( 'Category', 'community-events' ) . "</strong>: " . $event['event_cat_name'] . "<br />" . stripslashes($event['event_description']) . "'>";

					if ($event['event_url'] != '')
						$output .= "<a class='track_this_event' id='" . $event['event_id']. "' href='" . $event['event_url'] . "'>";

					$output .= stripslashes($event['event_name']);

					if ($event['event_url'] != '')
						$output .= "</a>";

					$output .= "</span> ";

					$output .= $event['event_start_date'] . " ";

					if ($event['event_end_date'] != NULL)
						$output .= "- " . $event['event_end_date'] . " ";

					if ($allowuserediting == true)
					{
						if (current_user_can("read"))
						{
							global $current_user;
							get_currentuserinfo();

							if ($current_user->user_login == $event['event_submitter'] || current_user_can("add_users"))
							{
								$output .= " <a href='" . $addeventurl . "?editevent=" . $event['event_id']. "'>(" . __( 'Edit', 'community-events') . ")</a>";
							}
						}
					}

					$output .= "</span> ";

					$output .= "<span class='ce-event-time'>" . $event['event_start_hour'] . ":" . $event['event_start_minute_zeros'] . " " . $event['event_start_ampm'];

					if ($displayendtimefield == true)
					{
						$output .= " - " . $event['event_end_hour'] . ":" . $event['event_end_minute_zeros'] . " " . $event['event_end_ampm'];
					}

					$output .= "</span> ";

					if ($event['ce_venue_name'] != "")
					{
						$output .= '<span class="cetooltip ce-venue-name" title="<strong>' . stripslashes($event['ce_venue_name']) . '</strong><br />' . stripslashes($event['ce_venue_address'])  . '<br />' . stripslashes($event['ce_venue_city']) . '<br />' . $event['ce_venue_zipcode'] . '<br />' . $event['ce_venue_email'] . '<br />' . $event['ce_venue_phone'] . '<br />' .  $event['ce_venue_url'] . '">';
						if ($fullscheduleurl != '')
							$output .= "<a href='" . $fullscheduleurl . "?venueset=1&amp;venue=" . $event['ce_venue_id'] . "'>\n";

						$output .= stripslashes($event['ce_venue_name']);

						if ($fullscheduleurl != '')
							$output .= "</a>";

						if ($event['ce_venue_city'] != "")
						{
							$output .= " / ";

							if ($fullscheduleurl != '')
								$output .=  "<a href='" . $fullscheduleurl . "?locationset=1&amp;location=" . $event['ce_venue_city'] . "'>\n";

							$output .= $event['ce_venue_city'];

							if ($fullscheduleurl != '')
								$output .= "</a>";
						}

						$output .= "</span></td></tr>\n";
					}
				}

				if (count($events) > $maxevents) {
					$dayofyearforcalc = $dayofyear - 1;
					$output .= "<tr><td><a href='#' onClick=\"showEvents('" . $dayofyear . "', '" . $year . "', false, true, '');return false;\">" . __( 'See all events for', 'community-events') . " " . date("l, M jS", strtotime('+ ' . $dayofyearforcalc . 'days', mktime(0,0,0,1,1,$year))) . "</a></td></tr>\n";
				}
			}
		}
		elseif ($outlook == 'false')
		{
			$eventquery = "SELECT *, if(char_length(event_start_minute)=1,concat('0',event_start_minute),event_start_minute) as event_start_minute_zeros, if(char_length(event_end_minute)=1,concat('0',event_end_minute),event_end_minute) as event_end_minute_zeros from ";
			$eventquery .= $wpdb->prefix . "ce_events e LEFT JOIN " . $wpdb->prefix . "ce_venues v ON e.event_venue = v.ce_venue_id LEFT JOIN " . $wpdb->prefix . "ce_category c ON e.event_category = c.event_cat_id ";
			$eventquery .= "where YEAR(event_start_date) = " . $year . " and DAYOFYEAR(DATE(event_start_date)) = " . $dayofyear;
			$eventquery .= " and (event_end_date IS NULL OR event_start_date = event_end_date) ";

			if ($moderateevents == 'true')
				$eventquery .= " and event_published = 'Y' ";

			if ( !empty( $filterbyuser ) ) {
				$eventquery .= " and event_submitter = '%s' ";
			}

			$eventquery .= "UNION ";

			$eventquery .= "SELECT *, if(char_length(event_start_minute)=1,concat('0',event_start_minute),event_start_minute) as event_start_minute_zeros, if(char_length(event_end_minute)=1,concat('0',event_end_minute),event_end_minute) as event_end_minute_zeros from ";
			$eventquery .= $wpdb->prefix . "ce_events e LEFT JOIN " . $wpdb->prefix . "ce_venues v ON e.event_venue = v.ce_venue_id LEFT JOIN " . $wpdb->prefix . "ce_category c ON e.event_category = c.event_cat_id ";

			$eventquery .= "WHERE ((YEAR(event_start_date) = " . $year . " and YEAR(event_end_date) = " . $year;
			$eventquery .= " and DAYOFYEAR(DATE(event_start_date)) <= " . $dayofyear . " ";
			$eventquery .= " and DAYOFYEAR(DATE(event_end_date)) >= " . $dayofyear . ") OR ";

			$eventquery .= "(YEAR(event_start_date) = " . $year . " and YEAR(event_end_date) > " . $year;
			$eventquery .= " and DAYOFYEAR(DATE(event_start_date)) <= " . $dayofyear . ") OR ";

			$eventquery .= " (YEAR(event_start_date) < " . $year;
			$eventquery .= " and DAYOFYEAR(DATE(event_end_date)) >= " . $dayofyear;
			$eventquery .= " and DAYOFYEAR(YEAR(event_end_date)) = " . $year . ") OR ";

			$eventquery .= " (YEAR(event_start_date) < " . $year;
			$eventquery .= " and DAYOFYEAR(YEAR(event_end_date)) > " . $year . ") OR ";

			$eventquery .= " (YEAR(event_end_date) > " . $year . " ";
			$eventquery .= " and YEAR(event_start_date) = " . $year . " ";
			$eventquery .= " and DAYOFYEAR(DATE(event_start_date)) <= " . $dayofyear . ") OR ";

			$eventquery .= " (YEAR(event_end_date) > " . $year . " ";
			$eventquery .= " and YEAR(event_start_date) < " . $year . ") OR ";

			$eventquery .= " (YEAR(event_end_date) = " . $year;
			$eventquery .= " and YEAR(DATE(event_start_date)) < " . $year;
			$eventquery .= " and DAYOFYEAR(DATE(event_end_date)) >= " . $dayofyear . ")) ";

			if ($moderateevents == 'true')
				$eventquery .= " and event_published = 'Y' ";

			if ( !empty( $filterbyuser ) ) {
				$eventquery .= " and event_submitter = '%s' ";
			}

			$eventquery .= " and (event_end_date IS NOT NULL) AND (event_end_date != event_start_date)";

            $eventquery .= " order by ";

            if ( $options['eventorder'] == 'eventnameorder' || empty( $options['eventorder'] ) ) {
                $eventquery .= 'event_name ';
            } else if ( $options['eventtimeorder'] ) {
                $eventquery .= 'event_start_ampm, start_hour, start_minute ';
            }

			if ( !empty( $filterbyuser ) ) {
				$cleanquery = $wpdb->prepare( $eventquery, $filterbyuser, $filterbyuser );
			} else {
				$cleanquery = $eventquery;
			}

			$events = $wpdb->get_results($cleanquery, ARRAY_A);

			$dayofyearforcalc = $dayofyear - 1;

			if ($showdate == 'true')
			{	$dayofyearforcalc = $dayofyear - 1;
				$output .= "<tr><td><span class='ce-outlook-day'>" . date("l, M jS", strtotime('+ ' . $dayofyearforcalc . 'days', mktime(0,0,0,1,1,$year))) . "</span></td></tr>";
			}

			if ($events)
			{
				if (count($events) < $maxevents)
				{
					$maxevents = count($events);
					$overflow = false;
				}
				elseif (count($events) > $maxevents)
				{
					$overflow = true;
				}

				$randomevents = array_rand($events, $maxevents);

				if (!is_array($randomevents))
					$randomevents = array($randomevents);

				foreach($randomevents as $randomevent)
				{
					$output .= "<tr><td><span class='cetooltip ce-event-name' title='<strong>" . __( 'Category', 'community-events' ) . "</strong>: " . $events[$randomevent]['event_cat_name'] . "<br />" . stripslashes($events[$randomevent]['event_description']) . "'>";

					if ($events[$randomevent]['event_url'] != '')
						$output .= "<a class='track_this_event' id='" . $events[$randomevent]['event_id'] . "' href='" . $events[$randomevent]['event_url'] . "'>";

					$output .= stripslashes($events[$randomevent]['event_name']);

					if ($events[$randomevent]['event_url'] != '')
						$output .= "</a>";

					if ($allowuserediting == true)
					{
						if (current_user_can("read"))
						{
							global $current_user;
							get_currentuserinfo();

							if ($current_user->user_login == $events[$randomevent]['event_submitter'] || current_user_can("add_users"))
							{
								$output .= " <a href='" . $addeventurl . "?editevent=" . $events[$randomevent]['event_id']. "'>(" . __( 'Edit', 'community-events' ) . ")</a>";
							}
						}
					}

					$output .= "</span> ";

					$output .= "<span class='ce-event-time'>" . $events[$randomevent]['event_start_hour'] . ":";
					$output .= $events[$randomevent]['event_start_minute_zeros'] . " " . $events[$randomevent]['event_start_ampm'];

					if ($displayendtimefield == true)
					{
						$output .= " - " . $events[$randomevent]['event_end_hour'] . ":" . $events[$randomevent]['event_end_minute_zeros'] . " " . $events[$randomevent]['event_end_ampm'];
					}

					$output .= "</span> ";

					if ($events[$randomevent]['ce_venue_name'] != "")
					{
						$output .= '<span class="cetooltip ce-venue-name" title="<strong>' . stripslashes($events[$randomevent]['ce_venue_name']) . '</strong><br />' . stripslashes($events[$randomevent]['ce_venue_address'])  . '<br />' . stripslashes($events[$randomevent]['ce_venue_city']) . '<br />' . $events[$randomevent]['ce_venue_zipcode'] . '<br />' . $events[$randomevent]['ce_venue_email'] . '<br />' . $events[$randomevent]['ce_venue_phone'] . '<br />' .  $events[$randomevent]['ce_venue_url'] . '">';

						if ($fullscheduleurl != '')
							$output .= "<a href='" . $fullscheduleurl . "?venueset=1&amp;venue=" . $events[$randomevent]['ce_venue_id'] . "'>\n";

						$output .= stripslashes($events[$randomevent]['ce_venue_name']);

						if ($fullscheduleurl != '')
							$output .= "</a>";

						if ($events[$randomevent]['ce_venue_city'] != "")
						{
							$output .= " / ";

							if ($fullscheduleurl != '')
								$output .=  "<a href='" . $fullscheduleurl . "?locationset=1&amp;location=" . stripslashes($events[$randomevent]['ce_venue_city']) . "'>\n";

							$output .= stripslashes($events[$randomevent]['ce_venue_city']);

							if ($fullscheduleurl != '')
								$output .= "</a>";
						}

						$output .= "</span>\n";
					}
				}

				if (count($events) > $maxevents)
				{
					$output .= "<tr><td>\n";

					if ($fullscheduleurl == '')
					{
						$output .= "<span class='seemore'><a href='#' onClick=\"showEvents('" . $dayofyear . "', '" . $year . "', false, true, '');return false;\">" . __( 'See more events for that day', 'community-events' ) . "</a></span>";
					}
					elseif ($fullscheduleurl != '')
					{
						$output .= "<span class='seemore'><a href='" . $fullscheduleurl . "/?eventday=" . $dayofyear . "&amp;eventyear=" . $year . "&amp;dateset=1'>" . __( 'See more events for this day', 'community-events' ) . "</a></span>";
					}

					$output .= "</td></tr>\n";
				}
			}
			else
				$output .= "\n<tr><td>" . __( 'No events for this date', 'community-events' ) . ".</td></tr>\n";

			if ($showdate == 'true')
			{
				$output .= "<tr><td>" . __( 'Select a date', 'community-events' ) . ": <input type='text' id='datepicker' name='event_start_date' size='30' /><input type='hidden' id='dayofyear' name='dayofyear' size='30' /><input type='hidden' id='year' name='year' size='30' />\n";
				$output .= "<button id='displayDate'>" . __( 'Go', 'community-events' ) . "!</button></td></tr>\n";
			}
		}
		elseif ( $outlook == 'true' )
		{
			for ( $i = 0; $i <= 6; $i++ )
			{
				$calculatedday = $dayofyear + $i;

				$eventquery = "SELECT *, if(char_length(event_start_minute)=1,concat('0',event_start_minute),event_start_minute) as event_start_minute_zeros, if(char_length(event_end_minute)=1,concat('0',event_end_minute),event_end_minute) as event_end_minute_zeros from ";
				$eventquery .= $wpdb->prefix . "ce_events e LEFT JOIN " . $wpdb->prefix . "ce_venues v ON e.event_venue = v.ce_venue_id LEFT JOIN " . $wpdb->prefix . "ce_category c ON e.event_category = c.event_cat_id ";
				$eventquery .= "where YEAR(event_start_date) = " . $year . " and DAYOFYEAR(DATE(event_start_date)) = " . $calculatedday;
				$eventquery .= " and (event_end_date IS NULL OR event_start_date = event_end_date)";

				if ($moderateevents == 'true')
					$eventquery .= " and event_published = 'Y' ";

				if ( !empty( $filterbyuser ) ) {
					$eventquery .= " and event_submitter = '%s' ";
				}

				$eventquery .= "UNION ";

				$eventquery .= "SELECT * , if(char_length(event_start_minute)=1,concat('0',event_start_minute),event_start_minute) as event_start_minute_zeros, if(char_length(event_end_minute)=1,concat('0',event_end_minute),event_end_minute) as event_end_minute_zeros from ";
				$eventquery .= $wpdb->prefix . "ce_events e LEFT JOIN " . $wpdb->prefix . "ce_venues v ON e.event_venue = v.ce_venue_id LEFT JOIN " . $wpdb->prefix . "ce_category c ON e.event_category = c.event_cat_id ";

				$eventquery .= "WHERE ((YEAR(event_start_date) = " . $year . " and YEAR(event_end_date) = " . $year;
				$eventquery .= " and DAYOFYEAR(DATE(event_start_date)) <= " . $calculatedday . " ";
				$eventquery .= " and DAYOFYEAR(DATE(event_end_date)) >= " . $calculatedday . ") OR ";

				$eventquery .= "(YEAR(event_start_date) = " . $year . " and YEAR(event_end_date) > " . $year;
				$eventquery .= " and DAYOFYEAR(DATE(event_start_date)) <= " . $calculatedday . ") OR ";

				$eventquery .= " (YEAR(event_start_date) < " . $year;
				$eventquery .= " and DAYOFYEAR(DATE(event_end_date)) >= " . $calculatedday;
				$eventquery .= " and DAYOFYEAR(YEAR(event_end_date)) = " . $year . ") OR ";

				$eventquery .= " (YEAR(event_start_date) < " . $year;
				$eventquery .= " and DAYOFYEAR(YEAR(event_end_date)) > " . $year . ") OR ";

				$eventquery .= " (YEAR(event_end_date) > " . $year . " ";
				$eventquery .= " and YEAR(event_start_date) = " . $year . " ";
				$eventquery .= " and DAYOFYEAR(DATE(event_start_date)) <= " . $calculatedday . ") OR ";

				$eventquery .= " (YEAR(event_end_date) > " . $year . " ";
				$eventquery .= " and YEAR(event_start_date) < " . $year . ") OR ";

				$eventquery .= " (YEAR(event_end_date) = " . $year;
				$eventquery .= " and YEAR(DATE(event_start_date)) < " . $year;
				$eventquery .= " and DAYOFYEAR(DATE(event_end_date)) >= " . $calculatedday . ")) ";

				if ($moderateevents == 'true')
					$eventquery .= " and event_published = 'Y' ";

				if ( !empty( $filterbyuser ) ) {
					$eventquery .= " and event_submitter = '%s' ";
				}

				$eventquery .= " and (event_end_date IS NOT NULL) AND (event_end_date != event_start_date)";

				$eventquery .= " order by ";

                if ( $options['eventorder'] == 'eventnameorder' || empty( $options['eventorder'] ) ) {
                    $eventquery .= 'event_name ';
                } else if ( $options['eventtimeorder'] ) {
                    $eventquery .= 'event_start_ampm, start_hour, start_minute ';
                }

				if ( !empty( $filterbyuser ) ) {
					$cleanquery = $wpdb->prepare( $eventquery, $filterbyuser, $filterbyuser );
				} else {
					$cleanquery = $eventquery;
				}

				$dayevents = $wpdb->get_results( $cleanquery, ARRAY_A );

				//echo "Day: " . $calculatedday . ", values are: " . print_r($dayevents);

				$output .= "\t\t<tr><td class='" . ($i % 2 == 0 ? "community-events-even" : "community-events-odd") . "'>";
				$output .= "<!-- Year " . $year . " Dayofyear " . $calculatedday . " -->\n";
				$output .= "<span class='ce-outlook-day'>" . date("l, M jS", strtotime("+" . $i . " day", current_time('timestamp')));

				if (count($dayevents) > 1 && $fullscheduleurl == '')
				{
					$output .= "<span class='seemore'><a href='#' onClick=\"showEvents('" . $calculatedday . "', '" . $year . "', false, true, '');return false;\">" . __( 'See more', 'community-events' ) . "</a></span>";
				}
				elseif (count($dayevents) > 1 && $fullscheduleurl != '')
				{
					$output .= "<span class='seemore'><a href='" . $fullscheduleurl . "/?eventday=" . $calculatedday . "&amp;eventyear=" . $year . "'>" . __( 'See more', 'community-events' ) . "</a></span>";
				}

				$output .= "</span><br />\n";

				if ($dayevents)
				{
					$randomentry = array_rand($dayevents);

					$output .= "<span class='cetooltip ce-outlook-event-name' title='<strong>" . __( 'Category', 'community-events' ) . "</strong>: " . $dayevents[$randomentry]['event_cat_name'] . "<br />" . stripslashes($dayevents[$randomentry]['event_description']) . "'>";

					if ($dayevents[$randomentry]['event_url'] != '')
						$output .= "<a class='track_this_event' id='" . $dayevents[$randomentry]['event_id'] . "' href='" . $dayevents[$randomentry]['event_url'] . "'>";

					$output .= stripslashes($dayevents[$randomentry]['event_name']);

					if ($dayevents[$randomentry]['event_url'] != '')
						$output .= "</a>";

					if ($allowuserediting == true)
					{
						if (current_user_can("read"))
						{
							global $current_user;
							get_currentuserinfo();

							if ($current_user->user_login == $dayevents[$randomentry]['event_submitter'] || current_user_can("add_users"))
							{
								$output .= " <a href='" . $addeventurl . "?editevent=" . $dayevents[$randomentry]['event_id']. "'>(" . __( 'Edit', 'community-events' ) . ")</a>";
							}
						}
					}

					$output .= "</span> ";

					$output .= "<span class='ce-event-time'>" . $dayevents[$randomentry]['event_start_hour'] . ":";
					$output .= $dayevents[$randomentry]['event_start_minute_zeros'] . " " . $dayevents[$randomentry]['event_start_ampm'];

					if ($displayendtimefield == true)
					{
						$output .= " - " . $dayevents[$randomentry]['event_end_hour'] . ":" . $dayevents[$randomentry]['event_end_minute_zeros'] . " " . $dayevents[$randomentry]['event_end_ampm'];
					}

					$output .= "</span> ";

					if ($dayevents[$randomentry]['ce_venue_name'] != "")
					{
						$output .= '<span class="cetooltip ce-venue-name" title="<strong>' . esc_html(stripslashes($dayevents[$randomentry]['ce_venue_name'])) . '</strong><br />' . stripslashes($dayevents[$randomentry]['ce_venue_address'])  . '<br />' . stripslashes($dayevents[$randomentry]['ce_venue_city']) . '<br />' . $dayevents[$randomentry]['ce_venue_zipcode'] . '<br />' . $dayevents[$randomentry]['ce_venue_email'] . '<br />' . $dayevents[$randomentry]['ce_venue_phone'] . '<br />' .  $dayevents[$randomentry]['ce_venue_url'] . '">';

						if ($fullscheduleurl != '')
							$output .= "<a href='" . $fullscheduleurl . "?venueset=1&amp;venue=" . $dayevents[$randomentry]['ce_venue_id'] . "'>\n";

						$output .= stripslashes($dayevents[$randomentry]['ce_venue_name']);

						if ($fullscheduleurl != '')
							$output .= "</a>";

						if ($dayevents[$randomentry]['ce_venue_city'] != "")
						{
							$output .= " / ";

							if ($fullscheduleurl != '')
								$output .=  "<a href='" . $fullscheduleurl . "?locationset=1&amp;location=" . stripslashes($dayevents[$randomentry]['ce_venue_city']) . "'>\n";

							$output .= stripslashes($dayevents[$randomentry]['ce_venue_city']);

							if ($fullscheduleurl != '')
								$output .= "</a>";
						}

						$output .= "</span>\n";
					}

					if ($dayevents[$randomentry]['event_ticket_url'] != "")
						$output .= "<span class='ce-ticket-link'><a id='event url' href='" . $dayevents[$randomentry]['event_ticket_url'] . "'><img title='" . __( 'Ticket Link', 'community-events' ) . "' src='" . $this->cepluginpath . "/icons/tickets.gif' /></a></span>\n";

					$output .= "</td></tr>";
				}
				else
					$output .= "<span class='ce-outlook-event-name'>" . __( 'No events', 'community-events' ) . ".</span></td></tr>\n";
			}

			$output .= "<tr><td>" . __( 'Select a date', 'community-events' ) . ": <input type='text' id='datepicker' name='event_start_date' size='30' /><input type='hidden' id='dayofyear' name='dayofyear' size='30' /><input type='hidden' id='year' name='year' size='30' />\n";
			$output .= "<button id='displayDate'>" . __( 'Go', 'community-events' ) . "!</button></td></tr>\n";
		}

		$output .= "<script type='text/javascript'>\n";
		$output .= "jQuery(document).ready(function() {\n";
		$output .= "\tjQuery('#datepicker').datepicker({minDate: '+0', dateFormat: 'mm-dd-yy', showOn: 'both', buttonImage: '" . $this->cepluginpath . "/icons/calendar.png', onSelect: function(dateText, inst) {\n";
		$output .= "var datestring = dateText.replace(/-/gi, '/');\n";
		$output .= "var incomingdate = new Date(datestring);\n";
		$output .= "var onejan = new Date(incomingdate.getFullYear(),0,1);\n";
		$output .= "jQuery('#dayofyear').val(Math.ceil((incomingdate - onejan) / 86400000) + 1);\n";
		$output .= "jQuery('#year').val(incomingdate.getFullYear());\n";
		$output .= "}   });\n";
		$output .= "\tjQuery('#displayDate').click(function() { if (jQuery('#dayofyear').val() != '') {showEvents(jQuery('#dayofyear').val(), jQuery('#year').val(), false, true, '')} else { alert('" . __( 'Select date first', 'community-events' ) . "'); };});\n";
		$output .= "});\n";
		$output .= "</script>";

		$output .= "\t</table>";

		return $output;
	}

	function ce_7day( $fullscheduleurl = '', $outlook = true, $addeventurl = '', $maxevents = 5, $moderateevents = true, $displaysearch = true, $outlookdefault = false,
					 $allowuserediting = false, $displayendtimefield = false, $filterbyuser = '' ) {
		global $wpdb;

		$currentday = date("z", current_time('timestamp')) + 1;

		if (date("L") == 1 && $currentday > 60)
			$currentday++;

		$currentyear = date("Y");

		$output = "<!-- Current day is " . $currentday . " -->\n";

		$output .= "<SCRIPT LANGUAGE=\"JavaScript\">\n";

		if ($maxevents == '') $maxevents = 5;

		$output .= "function showEvents ( _dayofyear, _year, _outlook, _showdate, _searchstring) {\n";
		$output .= "var map = {action: 'community_events_frontend_list', dayofyear : _dayofyear, year : _year, outlook: _outlook, showdate: _showdate, maxevents: " . $maxevents . ", moderateevents: '" . ($moderateevents == true ? 'true' : 'false') . "', searchstring: _searchstring, filterbyuser: '" . $filterbyuser . "'};\n";
		$output .= "\tjQuery('.ce-7day-innertable').replaceWith('<div class=\"ce-7day-innertable\"><img src=\"" . WP_PLUGIN_URL . "/community-events/icons/Ajax-loader.gif\" alt=\"" . __( 'Loading data, please wait...', 'community-events' ) . "\"></div>');jQuery.get('" . admin_url( 'admin-ajax.php' ) . "', map, function(data){jQuery('.ce-7day-innertable').replaceWith(data);";
		$output .= "\tjQuery('.cetooltip').each(function()\n";
		$output .= "\t\t{ jQuery(this).tipTip(); }\n";
		$output .= "\t);});\n\n";
		$output .= "}\n\n";

		$output .= "function truncate(n) {\n";
		$output .= "return Math[n > 0 ? 'floor' : 'ceil'](n);\n";
		$output .= "}\n";

		$output .= "jQuery(document).ready(function() {\n";
		$output .= "\tjQuery('.ce-day-link').click(function() {\n";
		$output .= "\t\tvar elementid = jQuery(this).attr('id');\n";
		$output .= "\t\telementid = '#' + elementid + '_cell';\n";
		$output .= "\t\tjQuery('.ce-daybox').each(function()\n";
		$output .= "\t\t\t{ jQuery(this).removeClass('selected');	}\n";
		$output .= "\t\t);\n";
		$output .= "\t\tjQuery(elementid).addClass('selected');\n";
		$output .= "\t});\n\n";

		$output .= "\tjQuery('.cetooltip').each(function()\n";
		$output .= "\t\t{ jQuery(this).tipTip(); }\n";
		$output .= "\t);\n";

		$output .= "jQuery('a.track_this_event').click(function() {\n";
		$output .= "jQuery.post('" . WP_PLUGIN_URL . "/community-events/tracker.php', {id:this.id});\n";
		$output .= "return true;\n";
		$output .= "});\n";

		$output .= "});\n";

		$output .= "</SCRIPT>\n\n";

		$output .= "<div class='community-events-7day'><table class='ce-7day-toptable'><tr>\n";

		if ($outlook == true)
		{
			$output .= "\t<td class='ce-daybox " . ($outlookdefault == true ? "selected" : "") . "' id='day_0_" . $currentyear . "_cell'><a href='#' class='ce-day-link' id='day_0_" . $currentyear . "' onClick=\"showEvents('" . $currentday . "', '" . $currentyear . "', true, false, '');return false;\"><strong>" . __( 'Upcoming Events', 'community-events' ) . "</strong></a></td>\n";
		}

		for ($i = 0; $i <= 6; $i++) {
			$daynumber = $currentday + $i;
			$output .= "\t<td class='ce-daybox " . ($i % 2 == 0 ? "community-events-even" : "community-events-odd") . " " . ((($outlook == false && $i == 0) || ($outlook == true && $i == 0 && $outlookdefault == false)) ? "selected" : "");
			$output .= "' id='day_" . $daynumber . "_" . $currentyear . "_cell'>\n";

			$output .= "\t\t<span class='ce-dayname'><a href='#' class='ce-day-link' id='day_" . $daynumber . "_" . $currentyear . "' onClick=\"showEvents('" . $daynumber. "', '" . $currentyear . "', false, false, '');return false;\">" . date_i18n("D", strtotime("+" . $i . " day", current_time('timestamp'))) . "<br /><span class='ce-date'>" . date("j", strtotime("+" . $i . " day", current_time('timestamp'))) . "</a></span>\n";

			$output .= "\t</td>\n";
		}

		$output .= "</tr>\n\t<tr><td class='ce-inner-table-row' colspan='" . (($outlook == true) ? 8 : 7) . "'>\n";

		$output .= $this->eventlist( $currentyear, $currentday, (($outlook == true && $outlookdefault == true)? 'true' : 'false'), 'false', $maxevents, ($moderateevents == true ? "true" : "false"), '', $fullscheduleurl, $addeventurl, $allowuserediting, $displayendtimefield, $filterbyuser );

		$output .= "\t</td></tr>\n";

		if ($displaysearch == true)
		{
			if ($outlook == true)
				$colspan = 8;
			else
				$colspan = 7;

			$output .= "<tr class='ce-search'><td colspan=" . $colspan . ">\n";
			$output .= __( 'Search Events', 'community-events') . ": <input type='text' id='ce_event_search' name='ce_event_search' size='50' />\n";
			$output .= "<button onClick=\"showEvents('" . $daynumber. "', '" . $currentyear . "', false, false, jQuery('#ce_event_search').val());return false;\" id='ce-search-button'><img src='" . $this->cepluginpath . "/icons/magnifier.png' /></button>\n";
			$output .= "</td></tr>\n";
		}

		if ($fullscheduleurl != '' || $addeventurl != '')
		{
			$output .= "<tr class='ce-full-schedule-link'>\n";

			if ($fullscheduleurl != '')
			{
				if ($outlook == true && $addeventurl != '')
					$colspan = 3;
				elseif ($outlook == true && $addeventurl == '')
					$colspan = 8;
				elseif ($outlook == false && $addeventurl != '')
					$colspan = 3;
				elseif ($outlook == false && $addeventurl == '')
					$colspan = 7;

				$output .= "<td colspan='" . $colspan . "'><a href='" . $fullscheduleurl . "'>" . __( 'Full Schedule', 'community-events' ) . "</a></td>";
			}

			if ($addeventurl != '')
			{
				if ($outlook == true && $fullscheduleurl != '')
					$colspan = 5;
				elseif ($outlook == true && $fullscheduleurl == '')
					$colspan = 8;
				elseif ($outlook == false && $fullscheduleurl != '')
					$colspan = 4;
				elseif ($outlook == false && $fullscheduleurl == '')
					$colspan = 7;

				$output .= "<td colspan='" . $colspan . "' class='ce-add-event-link'><a href='" . $addeventurl . "'>" . __( 'Submit your own event', 'community-events' ) . "</a></td>";
			}

			$output .= "</tr>\n";

		}

		$output .= "</table></div>\n";

		return $output;
	}

	function ce_full_func($atts) {

		extract(shortcode_atts(array(
			'filter_by_user' => '',
		), $atts));

		$options = get_option('CE_PP');

		if ( 'current' == $filter_by_user ) {
			$current_user = wp_get_current_user();
			$filter_by_user = $current_user->user_login;
		}

		if ( !empty( $filter_by_user ) ) {
			$filter_by_user = apply_filters( 'community_events_filter_user', $filter_by_user );
		}

		if ($options['schemaversion'] < 0.3)
			$this->ce_install();

		return $this->ce_full( $options['moderateevents'], $options['fullvieweventsperpage'], $options['fullviewmaxdays'], $options['fullscheduleurl'], $options['addeventurl'], $options['allowuserediting'], $options['displayendtimefield'], $filter_by_user );
	}

	function ce_full( $moderateevents = true, $fullvieweventsperpage = 20, $fullviewmaxdays = 90, $fullscheduleurl = '', $addeventurl = '', $allowuserediting = false, $displayendtimefield = false, $filterbyuser = '') {
		global $wpdb;

        $options = get_option( 'CE_PP' );

		if ($fullviewmaxdays == '')
			$fullviewmaxdays = 90;

		if ($fullvieweventsperpage == '')
			$fullvieweventsperpage = 20;

		$currentday = date("z", current_time('timestamp')) + 1;
		$currentyear = date("Y");
		$dayoffset = 0;

		if (date("L") == 1 && $currentday > 60)
		{
			$currentday++;
			$maxday = 366;
		}
		else
			$maxday = 365;

		if (isset($_GET['eventyear']) && isset($_GET['eventday']) && isset($_GET['dateset']))
		{
			$loopcount = 1;
			$queryday = intval( $_GET['eventday'] );
			$queryyear = intval( $_GET['eventyear'] );
			$dayoffset = abs($queryday - $currentday);
		}
		else
		{
			$loopcount = intval( $fullviewmaxdays );
			$queryday = intval( $currentday );
			$queryyear = intval( $currentyear );
		}

		if (isset($_GET['eventpage']))
		{
			$pagenumber = intval( $_GET['eventpage'] );
			if ($pagenumber < 1)
				$pagenumber = 1;
			$startingentry = ($pagenumber - 1) * intval( $fullvieweventsperpage );
		}
		else
		{
			$pagenumber = 1;
			$startingentry = 0;
		}

		$fulleventlist = array();

		$output = "<div class='community-events-full'>\n";
		$output .= "<div class='community-events-full-search'><form action='" . get_permalink() . "' method='get'>\n";
		$output .= "<div class='community-events-full-std-search'><span class='community-events-full-search-label'>Search</span>";
		$output .= "<input type='text' name='search' id='search' size=50 /> <input type='submit' value='" . __( 'Submit', 'community-events' ) . "' />\n";
		$output .= "<span class='community-events-full-search-advanced'>" . __( 'Advanced Search', 'community-events' ) . "</span></div>\n";
		$output .= "<div class='community-events-full-advanced-settings'>" . __( 'Advanced Search', 'community-events' ) . "<br />\n";
		$output .= "<table class='advanced-search'><tr><td style='width: 125px'>" . __( 'Filter by Venue', 'community-events' ) . "</td><td><input type='checkbox' id='venueset' name='venueset' /></td>\n";

		$venuelistquery = "SELECT ce_venue_name, ce_venue_id FROM " . $wpdb->prefix . "ce_venues v ORDER by ce_venue_name";
		$venuelist = $wpdb->get_results($venuelistquery, ARRAY_A);

		if ($venuelist)
		{
			$output .= "<td><select style='width: 300px' name='venue'>\n";

			foreach ($venuelist as $venue)
			{
				$output .= "<option value='" . $venue['ce_venue_id'] . "'>" . stripslashes($venue['ce_venue_name']). "</option>\n";

			}

			$output .= "</select></td>\n";
		}

		$output .= "</tr>\n";
		$output .= "<tr><td>" . __( 'Filter by Category', 'community-events' ) . "</td>\n";
		$output .= "<td><input type='checkbox' id='categoryset' name='categoryset' /></td>\n";

		$categorylistquery = "SELECT event_cat_id, event_cat_name FROM " . $wpdb->prefix . "ce_category c ORDER by event_cat_name";
		$categorylist = $wpdb->get_results($categorylistquery, ARRAY_A);

		if ($categorylist)
		{
			$output .= "<td><select style='width: 300px' name='category'>\n";

			foreach ($categorylist as $category)
			{
				$output .= "<option value='" . $category['event_cat_id'] . "'>" . stripslashes($category['event_cat_name']). "</option>\n";

			}

			$output .= "</select></td>\n";
		}

		$output .= "</tr>\n";

		$output .= "<tr><td>" . __( 'Filter by Location', 'community-events' ) . "</td>\n";
		$output .= "<td><input type='checkbox' id='locationset' name='locationset' /></td>\n";

		$locationlistquery = "SELECT distinct ce_venue_city from " . $wpdb->prefix . "ce_venues c ORDER by ce_venue_city";
		$locationlist = $wpdb->get_results($locationlistquery, ARRAY_A);

		if ($locationlist)
		{
			$output .= "<td><select style='width: 300px' name='location'>\n";

			foreach ($locationlist as $location)
			{
				$output .= "<option value='" . $location['ce_venue_city'] . "'>" . stripslashes($location['ce_venue_city']). "</option>\n";

			}

			$output .= "</select></td>\n";
		}

		$output .= "</tr>\n";

		$output .= "<tr><td>" . __( 'Filter by Date', 'community-events' ) . "</td>\n";
		$output .= "<td><input type='checkbox' id='dateset' name='dateset' /></td>\n";
		$output .= "<td><input type='text' name='date' id='date' size='20' value='" . date_i18n('m-d-Y', current_time('timestamp')) . "'/></td></tr>\n";

		$output .= "</table>\n";
		$output .= "</div>\n";

		$output .= "<input type='hidden' id='eventday' name='eventday' size='30' value='" . $currentday. "'/><input type='hidden' id='eventyear' name='eventyear' size='30' value='" . $currentyear . "' />\n";

		$output .= "</form></div>\n";

		if (isset($_GET['search']) || isset($_GET['venueset']) || isset($_GET['locationset']) || isset($_GET['dateset']))
		{
			$output .= "<div class='ce-full-search-results-header'>" . __( 'Search Results for', 'community-events' );
			if (isset($_GET['search']) && $_GET['search'] != '')
				$output .= __( 'Search String', 'community-events' ) . ": " . sanitize_text_field( $_GET['search'] );

			if (isset($_GET['venueset']) && $_GET['venue'] != '')
			{
				$output .= " " . __( 'Venue', 'community-events' ) . ": ";

				$venuenamequery = "select ce_venue_name from " . $wpdb->prefix . "ce_venues where ce_venue_id = " . intval( $_GET['venue'] );

				$venuename = $wpdb->get_var($venuenamequery);

				$output .= $venuename;
			}

			if (isset($_GET['categoryset']) && $_GET['category'] != '')
			{
				$output .= " " . __( 'Category', 'community-events' ) . ": ";

				$categorynamequery = "select event_cat_name from " . $wpdb->prefix . "ce_category where event_cat_id = " . intval( $_GET['category'] );

				$categoryname = $wpdb->get_var($categorynamequery);

				$output .= $categoryname;
			}

			if (isset($_GET['locationset']) && $_GET['location'] != '')
			{
				$output .= " " . __( 'location', 'community-events' ) . ": " . sanitize_text_field( $_GET['location'] );
			}

			$output .= "</div>";

		}

		$output .= "<div class='ce-full-search-top-links'><span><a href='" . $addeventurl . "'>" . __( 'Submit your own event', 'community-events' ) . "</a></span>\n";

		$output .= "<span style='float: right'><a href='" . $fullscheduleurl . "'>" . __( 'See full schedule', 'community-events' ) . "</a></span></div>";


		$output .= "\n\t<div class='ce-full-events-table'><table>\n";

		$eventquery = "";

		while ($loopcount > 0)
		{
			$eventquery = "(SELECT *, if(char_length(event_start_minute)=1,concat('0',event_start_minute),event_start_minute) as event_start_minute_zeros, if(char_length(event_end_minute)=1,concat('0',event_end_minute),event_end_minute) as event_end_minute_zeros, ";
			$eventquery .= "UNIX_TIMESTAMP(event_start_date) as datestamp, DAYOFYEAR(DATE(event_start_date)) as doy from " . $wpdb->prefix . "ce_events e LEFT JOIN ";
			$eventquery .= $wpdb->prefix . "ce_venues v ON e.event_venue = v.ce_venue_id LEFT JOIN " . $wpdb->prefix . "ce_category c ON e.event_category = c.event_cat_id ";
			$eventquery .= "where YEAR(event_start_date) = " . $queryyear;

			if (isset($_GET['search']) && ($_GET['search'] != ''))
			{
				$eventquery .= " and ((event_name like '%s')";
				$eventquery .= "    or (ce_venue_name like '%s')";
				$eventquery .= "    or (ce_venue_city like '%s')";
				$eventquery .= "    or (event_description like '%s'))";
			}

			if (isset($_GET['venueset']) && isset($_GET['venue']) && ($_GET['venue'] != ''))
			{
				$eventquery .= " and ce_venue_id = " . intval( $_GET['venue'] );
			}

			if (isset($_GET['categoryset']) && isset($_GET['category']) && ($_GET['category'] != ''))
			{
				$eventquery .= " and event_category = " . intval( $_GET['category'] );
			}

			if (isset($_GET['locationset']) && isset($_GET['location']) && ($_GET['location'] != ''))
			{
				$eventquery .= " and ce_venue_city = '%s'";
			}

			$eventquery .= " and DAYOFYEAR(DATE(event_start_date)) = " . $queryday . " ";
			$eventquery .= " and (event_end_date IS NULL OR event_start_date = event_end_date)";

			if ($moderateevents == true)
				$eventquery .= " and event_published = 'Y' ";

			if ( !empty( $filterbyuser ) ) {
				$eventquery .= " and event_submitter = '%s' ";
			}

			$eventquery .= ") UNION ";

			$eventquery .= "(SELECT *, if(char_length(event_start_minute)=1,concat('0',event_start_minute),event_start_minute) as event_start_minute_zeros, if(char_length(event_end_minute)=1,concat('0',event_end_minute),event_end_minute) as event_end_minute_zeros, ";
			$eventquery .= "UNIX_TIMESTAMP(event_start_date) as datestamp, DAYOFYEAR(DATE(event_start_date)) as doy from " . $wpdb->prefix . "ce_events e LEFT JOIN ";
			$eventquery .= $wpdb->prefix . "ce_venues v ON e.event_venue = v.ce_venue_id LEFT JOIN " . $wpdb->prefix . "ce_category c ON e.event_category = c.event_cat_id where ";

			if (isset($_GET['search']) && ($_GET['search'] != ''))
			{
				$eventquery .= " ((event_name like '%s')";
				$eventquery .= "    or (ce_venue_name like '%s')";
				$eventquery .= "    or (ce_venue_city like '%s')";
				$eventquery .= "    or (event_description like '%s')) and";
			}

			if (isset($_GET['venueset']) && isset($_GET['venue']) && ($_GET['venue'] != ''))
			{
				$eventquery .= " ce_venue_id = " . intval( $_GET['venue'] ) . " and ";
			}

			if (isset($_GET['categoryset']) && isset($_GET['category']) && ($_GET['category'] != ''))
			{
				$eventquery .= " event_category = " . intval( $_GET['category'] ) . " and ";
			}

			if (isset($_GET['locationset']) && isset($_GET['location']) && ($_GET['location'] != ''))
			{
				$eventquery .= " ce_venue_city = '%s' and ";
			}

			$eventquery .= "((YEAR(event_start_date) = " . $queryyear . " and YEAR(event_end_date) = " . $queryyear;
			$eventquery .= " and DAYOFYEAR(DATE(event_start_date)) <= " . $queryday . " ";
			$eventquery .= " and DAYOFYEAR(DATE(event_end_date)) >= " . $queryday . ") OR ";

			$eventquery .= "(YEAR(event_start_date) = " . $queryyear . " and YEAR(event_end_date) > " . $queryyear;
			$eventquery .= " and DAYOFYEAR(DATE(event_start_date)) <= " . $queryday . ") OR ";

			$eventquery .= " (YEAR(event_start_date) < " . $queryyear;
			$eventquery .= " and DAYOFYEAR(DATE(event_end_date)) >= " . $queryday;
			$eventquery .= " and DAYOFYEAR(YEAR(event_end_date)) = " . $queryyear . ") OR ";

			$eventquery .= " (YEAR(event_start_date) < " . $queryyear;
			$eventquery .= " and DAYOFYEAR(YEAR(event_end_date)) > " . $queryyear . ") OR ";

			$eventquery .= " (YEAR(event_end_date) > " . $queryyear . " ";
			$eventquery .= " and YEAR(event_start_date) = " . $queryyear . " ";
			$eventquery .= " and DAYOFYEAR(DATE(event_start_date)) <= " . $queryday . ") OR ";

			$eventquery .= " (YEAR(event_end_date) > " . $queryyear . " ";
			$eventquery .= " and YEAR(event_start_date) < " . $queryyear . ") OR ";

			$eventquery .= " (YEAR(event_end_date) = " . $queryyear;
			$eventquery .= " and YEAR(DATE(event_start_date)) < " . $queryyear;
			$eventquery .= " and DAYOFYEAR(DATE(event_end_date)) >= " . $queryday . ")) ";

			if ($moderateevents == true)
				$eventquery .= " and event_published = 'Y' ";

			if ( !empty( $filterbyuser ) ) {
				$eventquery .= " and event_submitter = '%s' ";
			}

			$eventquery .= " and (event_end_date IS NOT NULL) AND (event_end_date != event_start_date)";

			$eventquery .= ") order by event_start_date, ";

            if ( $options['eventorder'] == 'eventnameorder' || empty( $options['eventorder'] ) ) {
                $eventquery .= 'event_name ';
            } else if ( $options['eventtimeorder'] ) {
                $eventquery .= 'event_start_ampm, start_hour, start_minute ';
            }

			$cleanquery = $eventquery;

			if ( isset( $_GET['search'] ) && !empty( $_GET['search'] ) ) {
				if ( !isset( $_GET['location'] ) || empty( $_GET['location'] ) ) {
					if ( empty( $filterbyuser ) ) {
						$cleanquery = $wpdb->prepare( $eventquery, '%' . $_GET['search'] . '%', '%' . $_GET['search'] . '%', '%' . $_GET['search'] . '%', '%' . $_GET['search'] . '%', '%' . $_GET['search'] . '%', '%' . $_GET['search'] . '%', '%' . $_GET['search'] . '%', '%' . $_GET['search'] . '%');
					} else {
						$cleanquery = $wpdb->prepare( $eventquery, '%' . $_GET['search'] . '%', '%' . $_GET['search'] . '%', '%' . $_GET['search'] . '%', '%' . $_GET['search'] . '%', $filterbyuser, '%' . $_GET['search'] . '%', '%' . $_GET['search'] . '%', '%' . $_GET['search'] . '%', '%' . $_GET['search'] . '%', $filterbyuser );
					}
				} elseif ( isset( $_GET['location'] ) || !empty( $_GET['location'] ) ) {
					if ( empty( $filterbyuser ) ) {
						$cleanquery = $wpdb->prepare( $eventquery, '%' . $_GET['search'] . '%', '%' . $_GET['search'] . '%', '%' . $_GET['search'] . '%', '%' . $_GET['search'] . '%', $_GET['location'], '%' . $_GET['search'] . '%', '%' . $_GET['search'] . '%', '%' . $_GET['search'] . '%', '%' . $_GET['search'] . '%', $_GET['location'] );
					} else {
						$cleanquery = $wpdb->prepare( $eventquery, '%' . $_GET['search'] . '%', '%' . $_GET['search'] . '%', '%' . $_GET['search'] . '%', '%' . $_GET['search'] . '%', $_GET['location'], $filterbyuser, '%' . $_GET['search'] . '%', '%' . $_GET['search'] . '%', '%' . $_GET['search'] . '%', '%' . $_GET['search'] . '%', $_GET['location'], $filterbyuser );
					}
				}
			} elseif ( !isset( $_GET['search'] ) || empty( $_GET['search'] ) ) {
				if ( !isset( $_GET['location'] ) || empty( $_GET['location'] ) ) {
					if ( !empty( $filterbyuser ) ) {
						$cleanquery = $wpdb->prepare( $eventquery, $filterbyuser, $filterbyuser );
					}
				} elseif ( isset( $_GET['location'] ) && !empty( $_GET['location'] ) ) {
					if ( !empty( $filterbyuser ) ) {
						$cleanquery = $wpdb->prepare( $eventquery, $_GET['location'], $filterbyuser, $_GET['location'], $filterbyuser );
					} else {
						$cleanquery = $wpdb->prepare( $eventquery, $_GET['location'], $_GET['location'] );
					}
				}
			}

			$events = '';

			if ( !empty( $cleanquery ) ) {
				$events = $wpdb->get_results( $cleanquery, ARRAY_A );
			}

			$doy = 0;

			if ($loopcount == 90)
			{
				echo "<!-- " . $eventquery . " -->";
			}

			if ($events)
			{
				foreach($events as $event)
				{
					$newvalue = array_push($fulleventlist, $event);
					$fulleventlist[$newvalue - 1]['dayoffset'] = $dayoffset;
					$fulleventlist[$newvalue - 1]['queryday'] = $queryday;
					$fulleventlist[$newvalue - 1]['queryyear'] = $queryyear;
				}
			}

			$loopcount--;

			$queryday++;

			$dayoffset++;

			if ($queryday > $maxday)
			{
				$queryyear++;
				$queryday = $queryday - $maxday;
			}
		}

		if ($fulleventlist)
		{
			$preroundpages = count($fulleventlist) / $fullvieweventsperpage;
			$numberofpages = ceil( $preroundpages * 1 ) / 1;

			if ($pagenumber > $numberofpages)
			{
				$pagenumber = $numberofpages;
				$startingentry = ($pagenumber - 1) * $fullvieweventsperpage;
			}

			$fulleventlist = array_splice($fulleventlist, $startingentry, $fullvieweventsperpage);

			foreach ($fulleventlist as $fullevent)
			{
				if (isset($_GET['search']) && ($_GET['search'] != ''))
				{
					$fullevent['event_name'] = $this->ce_highlight_phrase(stripslashes($fullevent['event_name']), $_GET['search'], '<span class="highlight_word">', '</span>');
					$fullevent['ce_venue_name'] = $this->ce_highlight_phrase(stripslashes($fullevent['ce_venue_name']), $_GET['search'], '<span class="highlight_word">', '</span>');
					$fullevent['ce_venue_city'] = $this->ce_highlight_phrase(stripslashes($fullevent['ce_venue_city']), $_GET['search'], '<span class="highlight_word">', '</span>');
					$fullevent['event_description'] = $this->ce_highlight_phrase(stripslashes($fullevent['event_description']), $_GET['search'], '<span class="highlight_word">', '</span>');
				}

				if (isset($_GET['venueset']) && isset($_GET['venue']) && ($_GET['venue'] != ''))
				{
					$fullevent['ce_venue_name'] = $this->ce_highlight_phrase(stripslashes($fullevent['ce_venue_name']), $fullevent['ce_venue_name'], '<span class="highlight_word">', '</span>');
				}

				if (isset($_GET['categoryset']) && isset($_GET['category']) && ($_GET['category'] != ''))
				{
					$fullevent['event_cat_name'] = $this->ce_highlight_phrase(stripslashes($fullevent['event_cat_name']), $fullevent['event_cat_name'], '<span class="highlight_word">', '</span>');
				}

				if (isset($_GET['locationset']) && isset($_GET['location']) && ($_GET['location'] != ''))
				{
					$fullevent['ce_venue_city'] = $this->ce_highlight_phrase(stripslashes($fullevent['ce_venue_city']), $fullevent['ce_venue_city'], '<span class="highlight_word">', '</span>');
				}

				if ($doy != $fullevent['queryday'])
				{
					$daycount = 1;
					$output .= "<tr><td colspan='2' class='ce-full-dayrow'>" . date_i18n("l, F jS", strtotime("+" . $fullevent['dayoffset'] . " day", current_time('timestamp'))) . "</td></tr>";
					$output .= "<tr><td class='ce-full-dayevent'>Event</td><td class='ce-full-dayvenue'>" . __( 'Venue / Location', 'community-events' ) . "</td></tr>";
					$doy = $fullevent['queryday'];
				}

				$output .= "\t\t<tr><td colspan='2' class='ce-full-event-name " . ($daycount % 2 == 0 ? "community-events-even" : "community-events-odd" ) . "'>";

				$output .= "<span class='";

				$output .= "cetooltip ";

				$output .= "ce-full-event-label'";

				$output .= " title='<strong>" . __( 'Category', 'community-events' ) . "</strong>: " . stripslashes($fullevent['event_cat_name']) . "<br />" . stripslashes($fullevent['event_description']) . "'";

				$output .= ">";

				if ($fullevent['event_url'] != '')
					$output .= "<a class='track_this_event' id='" . $fullevent['event_id'] . "' href='" . $fullevent['event_url'] . "'>";

				$output .= stripslashes($fullevent['event_name']);

				if ($fullevent['event_url'] != '')
					$output .= "</a>";

				if ($allowuserediting == true)
				{
					if (current_user_can("read"))
					{
						global $current_user;
						get_currentuserinfo();

						if ($current_user->user_login == $fullevent['event_submitter'] || current_user_can("add_users"))
						{
							$output .= " <a href='" . $addeventurl . "?editevent=" . $fullevent['event_id']. "'>(" . __( 'Edit', 'community-events' ) . ")</a>";
						}
					}
				}

				$output .= " <span class='ce-event-time'>" . $fullevent['event_start_hour'] . ":";
				$output .= $fullevent['event_start_minute_zeros'] . " " . $fullevent['event_start_ampm'];

				if ( $displayendtimefield == true ) {
					$output .= " - " . $fullevent['event_end_hour'] . ":" . $fullevent['event_end_minute_zeros'] . " " . $fullevent['event_end_ampm'];
				}

				$output .= "</span> ";

				$output .= "</td></tr><tr>";

				if ( !empty( $fullevent['ce_venue_name'] ) ) {
					$output .= '<td colspan="2" class="ce-full-event-venue ' . ($daycount % 2 == 0 ? "community-events-even" : "community-events-odd" ) . '"><span class="cetooltip ce-venue-name" title="<strong>' . stripslashes($fullevent['ce_venue_name']) . '</strong><br />' . stripslashes($fullevent['ce_venue_address'])  . '<br />' . stripslashes($fullevent['ce_venue_city']) . '<br />' . $fullevent['ce_venue_zipcode'] . '<br />' . $fullevent['ce_venue_email'] . '<br />' . $fullevent['ce_venue_phone'] . '<br />' .  $fullevent['ce_venue_url'] . '"><a href="' . get_permalink() . '?venueset=1&amp;venue=' . $fullevent['ce_venue_id'] . '">' . stripslashes($fullevent['ce_venue_name']) . '</a> / <a href="' . get_permalink() . '?locationset=1&amp;location=' . stripslashes($fullevent['ce_venue_city']) . '">' . stripslashes($fullevent['ce_venue_city']) . '</a></span>';

					 if ( !empty( $fullevent['event_ticket_url'] ) ) {
						$output .= "<span class='ce-ticket-link'><a href='" . $fullevent['event_ticket_url'] . "'><img title='" . __( 'Ticket Link', 'community-events' ) . "' src='" . $this->cepluginpath . "/icons/tickets.gif' /></a></span>\n";
					 }

					$output .= "</td>\n";
				}

				$output .= "</tr>\n";

				$daycount++;
			}
		}

		$output .= "\t</table></div>\n";

		if ($fulleventlist && $numberofpages > 1)
		{
			$previouspagenumber = $pagenumber - 1;
			$nextpagenumber = $pagenumber + 1;
			$dotbelow = false;
			$dotabove = false;

			$parseduri = $this->remove_querystring_var($_SERVER['REQUEST_URI'], "eventpage");

			$output .= "<div class='pageselector'>";

			if ($pagenumber != 1)
			{
				$output .= "<span class='previousnextactive'>";

				$output .= "<a href='" . "http://" . $_SERVER['HTTP_HOST'];

				if (strpos($parseduri, '?'))
					$output .= $parseduri . "&eventpage=";
				else
					$output .= $parseduri . "?eventpage=";

				$output .= $previouspagenumber . "'>" . __('Previous', 'community-events') . "</a>";

				$output .= "</span>";
			}
			else
				$output .= "<span class='previousnextinactive'>" . __('Previous', 'community-events') . "</span>";

			for ($counter = 1; $counter <= $numberofpages; $counter++)
			{
				if ($counter <= 2 || $counter >= $numberofpages - 1 || ($counter <= $pagenumber + 2 && $counter >= $pagenumber - 2))
				{
					if ($counter != $pagenumber)
						$output .= "<span class='unselectedpage'>";
					else
						$output .= "<span class='selectedpage'>";


					$output .= "<a href='" . "http://" . $_SERVER['HTTP_HOST'];

					if (strpos($parseduri, '?'))
						$output .= $parseduri . "&eventpage=";
					else
						$output .= $parseduri . "?eventpage=";

					$output .= $counter . "'>" . $counter . "</a>";

					$output .= "</a></span>";
				}

				if ($counter >= 2 && $counter < $pagenumber - 2 && $dotbelow == false)
				{
					$output .= "...";
					$dotbelow = true;
				}

				if ($counter > $pagenumber + 2 && $counter < $numberofpages - 1 && $dotabove == false)
				{
					$output .= "...";
					$dotabove = true;
				}
			}

			if ($pagenumber != $numberofpages)
			{
				$output .= "<span class='previousnextactive'>";

				$output .= "<a href='" . "http://" . $_SERVER['HTTP_HOST'];

				if (strpos($parseduri, '?'))
					$output .= $parseduri . "&eventpage=";
				else
					$output .= $parseduri . "?eventpage=";

				$output .= $nextpagenumber . "'>" . __('Next', 'community-events') . "</a>";

				$output .= "</span>";
			}
			else
				$output .= "<span class='previousnextinactive'>" . __('Next', 'community-events') . "</span>";

			$output .= "</div>";
		}

		$output .= "</div>\n";

		$output .= "<SCRIPT LANGUAGE=\"JavaScript\">\n";

		$output .= "jQuery(document).ready(function() {\n";

		$output .= "\tjQuery('.cetooltip').each(function()\n";
		$output .= "\t\t{ jQuery(this).tipTip(); }\n";
		$output .= "\t);\n";

		$output .= "\tjQuery('.community-events-full-search-advanced').click(function() { jQuery('.community-events-full-advanced-settings').slideToggle('slow'); });\n";

		$output .= "\tjQuery('#date').datepicker({minDate: '+0', dateFormat: 'mm-dd-yy', showOn: 'both', buttonImage: '" . $this->cepluginpath . "/icons/calendar.png', onSelect: function(dateText, inst) {\n";
		$output .= "var datestring = dateText.replace(/-/gi, '/');\n";
		$output .= "var incomingdate = new Date(datestring);\n";
		$output .= "var onejan = new Date(incomingdate.getFullYear(),0,1);\n";
		$output .= "jQuery('#eventday').val(Math.ceil((incomingdate - onejan) / 86400000) + 1);\n";
		$output .= "jQuery('#eventyear').val(incomingdate.getFullYear());\n";
		$output .= "}   });\n";

		$output .= "jQuery('a.track_this_event').click(function() {\n";
		$output .= "jQuery.post('" . admin_url( 'admin-ajax.php' ) . "', {action:'community_events_click_tracker',id:this.id});\n";
		$output .= "return true;\n";
		$output .= "});\n";

		$output .= "});\n";

		$output .= "</SCRIPT>\n\n";

		return $output;
	}

	function ce_addevent_func($atts) {
		extract(shortcode_atts(array(
		), $atts));

		global $wpdb;

		$options = get_option('CE_PP');

		if ($options['schemaversion'] < 0.3)
			ce_install();

		$valid = false;
		$validmessage = "";
		$message = '';
		$captureddata = array();

		if ( isset( $_POST['event_name'] ) && !empty( $_POST['event_name'] ) )
		{
			if ($options['captchaevents'])
			{
				if (empty($_REQUEST['confirm_code']))
				{
					$valid = false;
					$validmessage = __('Confirm code not given', 'community-events') . ".";
				}
				else
				{
					if ( isset($_COOKIE['Captcha']) )
					{
						list($Hash, $Time) = explode('.', $_COOKIE['Captcha']);
						if ( md5("ORHFUKELFPTUEODKFJ".$_REQUEST['confirm_code'].$_SERVER['REMOTE_ADDR'].$Time) != $Hash )
						{
							$valid = false;
							$validmessage = __('Captcha code is wrong', 'community-events') . ".";
						}
						elseif( (time() - 5*60) > $Time)
						{
							$valid = false;
							$validmessage = __('Captcha code is only valid for 5 minutes', 'community-events') . ".";
						}
						else
						{
							$valid = true;
						}
					}
					else
					{
						$valid = false;
						$validmessage = __('No captcha cookie given. Make sure cookies are enabled', 'community-events') . ".";
					}
				}
			}

			if ($valid == false && $options['captchaevents'] == true)
			{
				$errormessage = "<div class='cemessage'>" . $validmessage . "</div>";
				echo $errormessage;

				$captureddata['event_name'] = sanitize_text_field( $_POST['event_name'] );
				$captureddata['event_start_date'] = sanitize_text_field( $_POST['event_start_date'] );
				$captureddata['event_start_hour'] = sanitize_text_field( $_POST['event_start_hour'] );
				$captureddata['event_start_minute'] = sanitize_text_field( $_POST['event_start_minute'] );
				$captureddata['event_start_ampm'] = sanitize_text_field( $_POST['event_start_ampm'] );
				$captureddata['event_description'] = sanitize_text_field( $_POST['event_description'] );
				$captureddata['event_url'] = sanitize_url( $_POST['event_url'] );
				$captureddata['event_ticket_url'] = sanitize_url( $_POST['event_ticket_url'] );
				$captureddata['event_venue'] = sanitize_text_field( $_POST['event_venue'] );
				$captureddata['event_category'] = sanitize_text_field( $_POST['event_category'] );
				$captureddata['new_venue_name'] = sanitize_text_field( $_POST['new_venue_name'] );
				$captureddata['new_venue_address'] = sanitize_text_field( $_POST['new_venue_address'] );
				$captureddata['new_venue_city'] = sanitize_text_field( $_POST['new_venue_city'] );
				$captureddata['new_venue_zipcode'] = sanitize_text_field( $_POST['new_venue_zipcode'] );
				$captureddata['new_venue_phone'] = sanitize_text_field( $_POST['new_venue_phone'] );
				$captureddata['new_venue_email'] = sanitize_text_field( $_POST['new_venue_email'] );
				$captureddata['new_venue_url'] = sanitize_text_field( $_POST['new_venue_url'] );
				$captureddata['event_end_hour'] = sanitize_text_field( $_POST['event_end_hour'] );
				$captureddata['event_end_minute'] = sanitize_text_field( $_POST['event_end_minute'] );
				$captureddata['event_end_ampm'] = sanitize_text_field( $_POST['event_end_ampm'] );
			}
			elseif ($valid || $options['captchaevents'] == false)
			{
				$username = '';
				if ($_POST['event_name'] != '')
				{
					if ($options['storelinksubmitter'] == true)
					{
						global $current_user;
						$username = "";

						get_currentuserinfo();

						if ($current_user)
							$username = $current_user->user_login;
					}

					if ($options['allowuservenuesubmissions'] === true && isset($_POST['new_venue_name']) && $_POST['new_venue_name'] != '')
					{
						$venuequery = "SELECT * from " . $wpdb->prefix. "ce_venues where ce_venue_name = '" . sanitize_text_field( $_POST['new_venue_name'] ) . "'";
						$venue = $wpdb->get_row($venuequery);
						if (!$venue)
						{
							$newvenue = array("ce_venue_name" => sanitize_text_field($_POST['new_venue_name']), "ce_venue_address" => sanitize_text_field($_POST['new_venue_address']), "ce_venue_city" => sanitize_text_field($_POST['new_venue_city']), "ce_venue_zipcode" => sanitize_text_field($_POST['new_venue_zipcode']), "ce_venue_phone" => sanitize_text_field($_POST['new_venue_phone']), "ce_venue_email" => sanitize_text_field($_POST['new_venue_email']), "ce_venue_url" => sanitize_text_field($_POST['new_venue_url']));
							$wpdb->insert( $wpdb->prefix.'ce_venues', $newvenue );
							$newvenue = $wpdb->get_row($venuequery);
							$venueid = $newvenue->ce_venue_id;
						}
						else
						{
							$venueid = $venue->ce_venue_id;
						}
					}
					else
						$venueid = $_POST['event_venue'];

					$newevent = array("event_name" => sanitize_text_field($_POST['event_name']), "event_start_date" => sanitize_text_field($_POST['event_start_date']), "event_start_hour" => sanitize_text_field($_POST['event_start_hour']), "event_start_minute" => sanitize_text_field($_POST['event_start_minute']), "event_start_ampm" => sanitize_text_field($_POST['event_start_ampm']),
						"event_description" => sanitize_text_field($_POST['event_description']), "event_url" => sanitize_url($_POST['event_url']), "event_ticket_url" => sanitize_url($_POST['event_ticket_url']), "event_venue" => intval( $venueid ), "event_category" => sanitize_text_field( $_POST['event_category'] ),
						"event_submitter" => sanitize_text_field( $username ), "event_end_hour" => sanitize_text_field( ( isset( $_POST['event_end_hour'] ) ? $_POST['event_end_hour'] : '' ) ), "event_end_minute" => sanitize_text_field( ( isset( $_POST['event_end_minute'] ) ? $_POST['event_end_minute'] : '' ) ), "event_end_ampm" => sanitize_text_field(( isset( $_POST['event_end_ampm'] ) ? $_POST['event_end_ampm'] : '' )));

					if (isset($_POST['submiteventnew']))
					{
						$newevent['event_submitter'] = $username;
					}
					elseif (isset($_POST['submiteventupdate']))
					{
						$newevent['event_submitter'] = sanitize_text_field( $_POST['event_submitter'] );
					}

					if ($newevent['event_start_date'] != "")
					{
						$newevent['event_start_date'] = str_replace('-', '/', $newevent['event_start_date']);
						$newevent['event_start_date'] = date( 'Y-m-d', strtotime( $newevent['event_start_date']) );
					}

					if ($_POST['event_end_date'] != '')
						$newevent['event_end_date'] = $_POST['event_end_date'];

					if ( isset( $newevent['event_end_date'] ) && $newevent['event_end_date'] != "")
					{
						$newevent['event_end_date'] = str_replace('-', '/', $newevent['event_end_date']);
						$newevent['event_end_date'] = date( 'Y-m-d', strtotime( $newevent['event_end_date']) );
					}

					if (isset($_POST['submiteventnew']) && ($options['moderateevents'] == true))
						$newevent['event_published'] = 'N';
					elseif (isset($_POST['submiteventnew']) && $options['moderateevents'] == false)
						$newevent['event_published'] = 'Y';
					elseif (isset($_POST['submiteventupdate']))
						$newevent['event_published'] = sanitize_text_field( $_POST['event_published'] );

					if (isset($_POST['submiteventnew']))
					{
						$wpdb->insert( $wpdb->prefix.'ce_events', $newevent );
					}
					elseif (isset($_POST['submiteventupdate']))
					{
						$wpdb->update( $wpdb->prefix.'ce_events', $newevent, array( 'event_id' => intval( $_POST['event_id'] ) ) );
					}

					if ($options['emailnewevent'])
					{
						$adminmail = get_option('admin_email');
						$headers = "MIME-Version: 1.0\r\n";
						$headers .= "Content-type: text/html; charset=iso-8859-1\r\n";

						$venuenamequery = "select ce_venue_name from " . $wpdb->prefix . "ce_venues where ce_venue_id = " . $venueid;
						$venuename = $wpdb->get_var($venuenamequery);

						$categorynamequery = "select event_cat_name from " . $wpdb->prefix . "ce_category where event_cat_id = " . $newevent['event_category'];
						$categoryname = $wpdb->get_var($categorynamequery);

						$message = __('A user submitted a new event to your Wordpress Community Events database.', 'community-events') . "<br /><br />";
						$message .= __('Event Name', 'community-events') . ": " . $newevent['event_name'] . "<br />";
						$message .= __('Event Category', 'community-events') . ": " . $categoryname . "<br />";
						$message .= __('Event Venue', 'community-events') . ": " . $venuename . "<br />";
						$message .= __('Event Description', 'community-events') . ": " . $newevent['event_description'] . "<br />";
						$message .= __('Event Web Address', 'community-events') . ": " . $newevent['event_url'] . "<br />";
						$message .= __('Event Ticket Purchase Link', 'community-events') . ": " . $newevent['event_ticket_url'] . "<br /><br />";
						$message .= __('Event Start Date', 'community-events') . ": " . $newevent['event_start_date'] . "<br />";
						$message .= __('Event End Date', 'community-events') . ": " . ( isset( $newevent['event_end_date'] ) ? $newevent['event_end_date'] : '' ) . "<br />";
						$message .= __('Event Start Time', 'community-events') . ": " . $newevent['event_start_hour'] . ":" . $newevent['event_start_minute'] . $newevent['event_start_ampm'] . "<br /><br />";						   $message .= __('Event End Time', 'community-events') . ": " . ( isset( $newevent['event_end_hour'] ) ? $newevent['event_end_hour'] : '' ) . ":" . ( isset( $newevent['event_end_minute'] ) ? $newevent['event_end_minute'] : '' )  . ( isset( $newevent['event_end_ampm'] ) ? $newevent['event_end_ampm'] : '' ) . "<br /><br />";

						if ( !defined('WP_ADMIN_URL') )
							define( 'WP_ADMIN_URL', get_option('siteurl') . '/wp-admin');

						$message .= "<br /><br />" . __('Message Generated by', 'community-events') . " <a href='http://yannickcorner.nayanna.biz/wordpress-plugins/community-events/'>Community Events</a> for Wordpress";

						wp_mail($adminmail, htmlspecialchars_decode(get_option('blogname'), ENT_QUOTES) . " - New event added: " . htmlspecialchars(sanitize_text_field( $_POST['event_name'] ) ), $message, $headers);
					}

						$message = "<div class='eventconfirmsubmit'>" . __( 'Thank you for your submission', 'community-events' ) . ".</div>\n";
				}
			}
		}

		return $message . $this->ce_addevent($options['columns'], $options['addeventreqlogin'], $options['addneweventmsg'], $options['eventnamelabel'], $options['eventcatlabel'],
							$options['eventvenuelabel'], $options['eventdesclabel'], $options['eventaddrlabel'], $options['eventticketaddrlabel'], $options['eventdatelabel'],
							$options['eventtimelabel'], $options['addeventbtnlabel'], $options['eventenddatelabel'], $options['captchaevents'], $captureddata,
							$options['allowuserediting'], $options['updateeventbtnlabel'], $options['newvenuenamelabel'], $options['newvenueaddresslabel'],
						$options['newvenuecitylabel'], $options['newvenuezipcodelabel'], $options['newvenuephonelabel'],
						$options['newvenueemaillabel'], $options['newvenueurllabel'], $options['allowuservenuesubmissions'], $options['displayendtimefield'], $options['eventendtimelabel']
				);
	}

	function ce_addevent($columns = 2, $addeventreqlogin = false, $addneweventmsg = "", $eventnamelabel = "", $eventcatlabel = "", $eventvenuelabel = "",
						$eventdesclabel = "", $eventaddrlabel = "", $eventticketaddrlabel = "", $eventdatelabel = "", $eventtimelabel = "", $addeventbtnlabel = "",
						$eventenddatelabel = "", $captchaevents = false, $captureddata = null, $allowuserediting = false, $updateeventbtnlabel = "", $newvenuenamelabel = '', $newvenueaddresslabel = '',
						$newvenuecitylabel = '', $newvenuezipcodelabel = '', $newvenuephonelabel = '',
						$newvenueemaillabel = '', $newvenueurllabel = '', $allowuservenuesubmissions = false, $displayendtimefield = false, $eventendtimelabel = '') {

		global $wpdb;

		if (($addeventreqlogin && current_user_can("read")) || !$addeventreqlogin)
		{
			if (isset($_GET['editevent']) && $allowuserediting == true && current_user_can("read"))
			{
				$event = $wpdb->get_row("select * from " . $wpdb->get_blog_prefix() . "ce_events where event_id = " . intval( $_GET['editevent'] ), ARRAY_A);

				if ($event)
				{
					global $current_user;
					get_currentuserinfo();

					if ($current_user->user_login == $event['event_submitter'] || current_user_can('add_users'))
					{
						$captureddata = $event;

						$mode = 'edit';
					}
				}
			}
			else
				$mode = 'new';

			$output = "<form method='post' id='ceaddevent'>\n";
			$output .= "<div class='ce-addevent'>\n";

			if ($addneweventmsg == "") $addneweventmsg = __('Add New Event', 'community-events');
			$output .= "<div id='ce-addeventtitle'>" . $addneweventmsg . "</div>\n";

			$output .= "<table class='ce-addeventtable'><tr>\n";

			if ($mode == "edit")
			{
				$output .= "<input type='hidden' name='event_id' id='event_id' value='" . $event['event_id'] . "' />\n";
				$output .= "<input type='hidden' name='event_published' id='event_published' value='" . $event['event_published'] . "' />\n";
				$output .= "<input type='hidden' name='event_submitter' id='event_submitter' value='" . $event['event_submitter'] . "' />\n";
			}

			if ($eventnamelabel == "") $eventnamelabel = __('Event Name', 'community-events');
			$output .= "<th style='width: 100px'>" . $eventnamelabel . "</th><td colspan=" . ($columns == 1 ? 1 : 3) . "><input style='width: 100%' type='text' name='event_name' id='event_name' value = '" . ( isset( $captureddata['event_name'] ) ? $captureddata['event_name'] : '' ) . "'/></td></tr>\n";

			if ($eventcatlabel == "") $eventcatlabel = __('Event Category', 'community-events');
			$output .= "<tr><th style='width: 100px'>" . $eventcatlabel . "</th><td><select style='width: 200px' name='event_category'>\n";
		$cats = $wpdb->get_results("SELECT * from " . $wpdb->prefix. "ce_category ORDER by event_cat_name");
			foreach ($cats as $cat)
			{
				if ( isset( $captureddata['event_category'] ) && $cat->event_cat_id == $captureddata['event_category'] ) {
					$selectedstring = "selected='selected'";
				} else {
					$selectedstring = "";
				}

				$output .= "<option value='" . $cat->event_cat_id . "' " . $selectedstring . ">" .  stripslashes($cat->event_cat_name) . "\n";
			}
			$output .= "</select></td>\n";

			if ($columns == 1)
				$output .= "</tr><tr>";

			if ($eventvenuelabel == "") $eventvenuelabel = __('Event Venue', 'community-events');
			$output .= "<th style='width: 100px'>" . $eventvenuelabel . "</th><td><select style='width: 200px' name='event_venue' id='event_venue'>\n";
			$venues = $wpdb->get_results("SELECT * from " . $wpdb->prefix. "ce_venues ORDER by ce_venue_name");

			if ($allowuservenuesubmissions)
			{
				$output .= "<option value=''>";
				$output .= "<option value='customuservenue'";

				if (isset( $captureddata['event_venue'] ) && $captureddata['event_venue'] == 'customuservenue')
					$output .= " selected='selected'";

				$output .= ">-- " . __( 'Create new venue', 'community-events' ) . " --\n";
			}
			foreach ($venues as $venue)
			{
				if (isset( $captureddata['event_venue'] ) && $venue->ce_venue_id == $captureddata['event_venue'])
						$selectedstring = "selected='selected'";
					else
						$selectedstring = "";

				$output .= "<option value='" . $venue->ce_venue_id . "' " . $selectedstring . ">" .  stripslashes($venue->ce_venue_name) . "\n";
			}

			$output .= "</select></td></tr>\n";

			if ($allowuservenuesubmissions)
			{
				if ($captureddata['event_venue'] != 'customuservenue')
					$hideoutput = " style='display:none'";
				else
					$hideoutput = "";

				if ($newvenuenamelabel == "") $newvenuenamelabel = __('New Venue Name', 'community-events');
				$output .= "<tr id='newvenuerow1'" . $hideoutput . "><th>" . $newvenuenamelabel . "</th><td colspan=" . ($columns == 1 ? 1 : 3) . "><input type='text' style='width: 100%' name='new_venue_name' id='new_venue_name' value='" . stripslashes($captureddata['new_venue_name']) . "'/></td></tr>\n";

				if ($newvenueaddresslabel == "") $newvenueaddresslabel = __('New Venue Address', 'community-events');
				$output .= "<tr id='newvenuerow2'" . $hideoutput . "><th>" . $newvenueaddresslabel . "</th><td colspan=" . ($columns == 1 ? 1 : 3) . "><input type='text' style='width: 100%' name='new_venue_address' id='new_venue_address' value='" . stripslashes($captureddata['new_venue_address']) . "'/></td></tr>\n";

				if ($newvenuecitylabel == "") $newvenuecitylabel = __('New Venue City', 'community-events');
				$output .= "<tr id='newvenuerow3'" . $hideoutput . "><th>" . $newvenuecitylabel . "</th><td colspan=" . ($columns == 1 ? 1 : 3) . "><input type='text' style='width: 100%' name='new_venue_city' id='new_venue_city' value='" . stripslashes($captureddata['new_venue_city']) . "'/></td></tr>\n";

				if ($newvenuezipcodelabel == "") $newvenuezipcodelabel = __('New Venue Zip Code', 'community-events');
				$output .= "<tr id='newvenuerow4'" . $hideoutput . "><th>" . $newvenuezipcodelabel . "</th><td colspan=" . ($columns == 1 ? 1 : 3) . "><input type='text' style='width: 100%' name='new_venue_zipcode' id='new_venue_zipcode' value='" . stripslashes($captureddata['new_venue_zipcode']) . "'/></td></tr>\n";

				if ($newvenuephonelabel == "") $newvenuephonelabel = __('New Venue Phone', 'community-events');
				$output .= "<tr id='newvenuerow5'" . $hideoutput . "><th>" . $newvenuephonelabel . "</th><td colspan=" . ($columns == 1 ? 1 : 3) . "><input type='text' style='width: 100%' name='new_venue_phone' id='new_venue_phone' value='" . stripslashes($captureddata['new_venue_phone']) . "'/></td></tr>\n";

				if ($newvenueemaillabel == "") $newvenueemaillabel = __('New Venue E-mail', 'community-events');
				$output .= "<tr id='newvenuerow6'" . $hideoutput . "><th>" . $newvenueemaillabel . "</th><td colspan=" . ($columns == 1 ? 1 : 3) . "><input type='text' style='width: 100%' name='new_venue_email' id='new_venue_email' value='" . stripslashes($captureddata['new_venue_email']) . "'/></td></tr>\n";

				if ($newvenueurllabel == "") $newvenueurllabel = __('New Venue URL', 'community-events');
				$output .= "<tr id='newvenuerow7'" . $hideoutput . "><th>" . $newvenueurllabel . "</th><td colspan=" . ($columns == 1 ? 1 : 3) . "><input type='text' style='width: 100%' name='new_venue_url' id='new_venue_url' value='" . stripslashes($captureddata['new_venue_url']) . "'/></td></tr>\n";
			}

			if ($eventdesclabel == "") $eventdesclabel = __('Event Description', 'community-events');
			$output .= "<tr><th>" . $eventdesclabel . "</th><td colspan=" . ($columns == 1 ? 1 : 3) . "><input type='text' style='width: 100%' name='event_description' id='event_description' value='" . stripslashes( ( isset( $captureddata['event_description'] ) ? $captureddata['event_description'] : '' )) . "'/></td></tr>\n";

			if ($eventaddrlabel == "") $eventaddrlabel = __('Event Web Address', 'community-events');
			$output .= "<tr><th>" . $eventaddrlabel . "</th><td colspan=" . ($columns == 1 ? 1 : 3) . "><input type='text' style='width: 100%' name='event_url' id='event_url' value='" . ( isset( $captureddata['event_url'] ) ? $captureddata['event_url'] : '') . "' /></td></tr>\n";

			if ($eventticketaddrlabel == "") $eventticketaddrlabel = __('Event Ticket Purchase Link', 'community-events');
			$output .= "<tr><th>" . $eventticketaddrlabel . "</th><td colspan=" . ($columns == 1 ? 1 : 3) . "><input type='text' style='width: 100%' name='event_ticket_url' id='event_ticket_url' value='" . ( isset( $captureddata['event_ticket_url'] ) ? $captureddata['event_ticket_url'] : '' ) . "'/></td></tr>\n";

			if ($eventdatelabel == "") $eventdatelabel = __('Event Start Date', 'community-events');

			$datevalue = '';
			if ( isset( $captureddata['event_start_date'] ) && empty( $captureddata['event_start_date'] ) ) {
				$datevalue = date('m-d-Y', current_time('timestamp'));
			} elseif ( isset( $captureddata['event_start_date'] ) && $captureddata['event_start_date'] ) {
				$datevalue = $captureddata['event_start_date'];
			}

			$output .= "<tr><th>" . $eventdatelabel . "</th><td><input type='text' name='event_start_date' id='datepickeraddform' value='" . $datevalue . "' /></td>\n";

			if ($columns == 1)
				$output .= "</tr><tr>";

			if ($eventtimelabel == "") $eventtimelabel = __('Event Time', 'community-events');
			$output .= "<th>" . $eventtimelabel . "</th><td>";

			$output .= "<select name='event_start_hour' style='width: 50px'>\n";
			for ($i = 1; $i <= 12; $i++)
			{
				if (isset( $captureddata['event_start_hour'] ) && $i == $captureddata['event_start_hour'])
					$selectedstring = " selected='selected'";
				else
					$selectedstring = "";

				$output .= "<option value=" . $i . $selectedstring . ">" . $i . "</option>\n";
			}
			$output .= "</select>:\n";

			$output .= "<select name='event_start_minute' style='width: 50px'>\n";

			$minutes = array('00', '15', '30', '45');
			foreach ($minutes as $minute)
			{
				if ( isset ( $captureddata['event_start_minute'] ) && $i == $captureddata['event_start_minute'] ) {
					$selectedstring = " selected='selected'";
				} else {
					$selectedstring = "";
				}

				$output .= "<option value=" . $minute . $selectedstring . ">" . $minute . "</option>\n";
			}
			$output .= "</select>\n";

			$output .= "<select name='event_start_ampm' style='width: 50px'>\n";
			$output .= "<option value='AM'>" . __( 'AM', 'community-events' ) . "</option>\n";

			if ( isset( $captureddata['event_start_ampm'] ) && "PM" == $captureddata['event_start_ampm'] )
					$selectedstring = " selected='selected'";
				else
					$selectedstring = "";

			$output .= "<option value='PM' " . $selectedstring . ">" . __( 'PM', 'community-events' ) . "</option>\n";
			$output .= "</select>\n";

			$output .= "</td></tr>\n";

			if ($eventenddatelabel == "") $eventenddatelabel = __('Event End Date', 'community-events');
			$output .= "<tr><th>" . $eventenddatelabel . "</th><td colspan='" . ($columns == 1 ? 1 : 1) . "'><input type='text' name='event_end_date' id='datepickeraddformend' value='" . ( isset( $captureddata['event_end_date'] ) ? $captureddata['event_end_date'] : '' ) . "'/></td>\n";

			if ($displayendtimefield == true)
			{
				if ($columns == 1)
				$output .= "</tr><tr>";

				if ($eventendtimelabel == "") $eventendtimelabel = __('Event End Time', 'community-events');
				$output .= "<th>" . $eventendtimelabel . "</th><td>";

				$output .= "<select name='event_end_hour' style='width: 50px'>\n";
				for ($i = 1; $i <= 12; $i++)
				{
					if ($i == $captureddata['event_end_hour'])
						$selectedstring = " selected='selected'";
					else
						$selectedstring = "";

					$output .= "<option value=" . $i . $selectedstring . ">" . $i . "</option>\n";
				}
				$output .= "</select>:\n";

				$output .= "<select name='event_end_minute' style='width: 50px'>\n";

				$minutes = array('00', '15', '30', '45');
				foreach ($minutes as $minute)
				{
					if ($i == $captureddata['event_end_minute'])
						$selectedstring = " selected='selected'";
					else
						$selectedstring = "";

					$output .= "<option value=" . $minute . $selectedstring . ">" . $minute . "</option>\n";
				}
				$output .= "</select>\n";

				$output .= "<select name='event_end_ampm' style='width: 50px'>\n";
				$output .= "<option value='AM'>" . __( 'AM', 'community-events' ) . "</option>\n";

				if ("PM" == $captureddata['event_end_ampm'])
						$selectedstring = " selected='selected'";
					else
						$selectedstring = "";

				$output .= "<option value='PM' " . $selectedstring . ">" . __( 'PM', 'community-events' ) . "</option>\n";
				$output .= "</select>\n";

				$output .= "</td>\n";

			}

			$output .= "</tr>";

			if ($captchaevents)
			{
				$output .= "<tr><td></td><td><span id='captchaimage'><img src='" . $this->cepluginpath . "captcha/easycaptcha.php' /></span></td></tr>\n";
				$output .= "<tr><th>" . __('Enter code from above image', 'community-events') . "</th><td><input type='text' name='confirm_code' /></td></tr>\n";
			}

			$output .= "</table>\n";

			if ($addeventbtnlabel == "") $addeventbtnlabel = __('Add Event', 'community-events');
			if ($updateeventbtnlabel == "") $updateeventbtnlabel = __('Update Event', 'community-events');

			if ($mode == "new")
			{
				$btnlabel = $addeventbtnlabel;
				$btnname = "submiteventnew";
			}
			elseif ($mode == "edit")
			{
				$btnlabel = $updateeventbtnlabel;
				$btnname = "submiteventupdate";
			}

			if ( isset( $captureddata['event_name'] ) && !empty( $captureddata['event_name'] ) )
				$capturedstring = '';
			else
				$capturedstring = 'disabled="disabled"';

			$output .= '<span style="border:0;" class="submit" ><input type="submit" name="' . $btnname . '" id="' . $btnname . '" ' . $capturedstring . ' value="' . $btnlabel . '" /></span>';

			$output .= "</div>\n";
			$output .= "</form>\n\n";

			$output .= "<script type='text/javascript'>\n";

			$output .= "function disableEnterKey(e)\n";
			$output .= "{\n";
			$output .= "var key = (window.event) ? event.keyCode : e.which;\n";
			$output .= "return (key != 13);\n";
			$output .= "}\n\n";

			$output .= "function validatefields()\n";
			$output .= "{\n";
			$output .= "\tvar allowsubmit = false;\n";
			$output .= "\tvar startdate, enddate;\n";
			$output .= "\tif (jQuery('#datepickeraddform').val() != '')\n";
			$output .= "\t{\n";
			$output .= "\t\tif (jQuery('#datepickeraddformend').val() != '')\n";
			$output .= "\t\t{\n";
			$output .= "\t\t\tstartdate = jQuery('#datepickeraddform').datepicker('getDate');\n";
			$output .= "\t\t\tenddate = jQuery('#datepickeraddformend').datepicker('getDate');\n\n";
			$output .= "\t\t\tif (enddate < startdate)\n";
			$output .= "\t\t\t\talert('" . __( 'End date must be equal or later than start date', 'community-events' ) . "');\n";
			$output .= "\t\t\telse\n";
			$output .= "\t\t\t\tallowsubmit = true;\n";
			$output .= "\t\t}\n";
			$output .= "\t\telse\n";
			$output .= "\t\t\tallowsubmit = true;\n";
			$output .= "\t}\n\n";

			$output .= "if (jQuery('#event_name').val() == '')\n";
			$output .= "{\n";
			$output .= "\tallowsubmit = false;\n";
			$output .= "}\n\n";

			$output .= "if (allowsubmit == false)\n";
			$output .= "\tjQuery('#" . $btnname . "').prop('disabled', true);\n";
			$output .= "else\n";
			$output .= "\tjQuery('#" . $btnname . "').prop('disabled', false);\n";
			$output .= "}\n";

			$output .= "jQuery(document).ready(function() {\n";
			$output .= "jQuery('#datepickeraddform').datepicker({minDate: '+0', dateFormat: 'mm-dd-yy', showOn: 'both', buttonImage: '" . $this->cepluginpath . "/icons/calendar.png', onSelect: function(dateText, inst) {\n";
			$output .= "var selectedDate = new Date(inst.currentYear, inst.currentMonth, inst.currentDay);\n";
			$output .= "jQuery('#datepickeraddformend').datepicker( 'option', {minDate: selectedDate } );\n";
			$output .= "validatefields();\n";
			$output .= "} });\n";
			$output .= "jQuery('#datepickeraddformend').datepicker({minDate: '+0', dateFormat: 'mm-dd-yy', showOn: 'both', buttonImage: '" . $this->cepluginpath . "/icons/calendar.png', onSelect: function(dateText, inst) {\n";
			$output .= "var selectedDate = new Date(inst.currentYear, inst.currentMonth, inst.currentDay);\n";
			$output .= "jQuery('#datepickeraddform').datepicker( 'option', {maxDate: selectedDate } );\n";
			$output .= "validatefields();\n";
			$output .= "} });\n";
			$output .= "jQuery('#event_name').change(function() { validatefields();});\n";
			$output .= "jQuery('#datepickeraddform').change(function() { validatefields();});\n";
			$output .= "jQuery('#datepickeraddformend').change(function() { validatefields();});\n";

			if ($allowuservenuesubmissions)
			{
				$output .= "jQuery('#event_venue').change(function() {\n";
				$output .= "\tdropdownvalue = jQuery('#event_venue').val();\n";
				$output .= "\tif (dropdownvalue == 'customuservenue') { jQuery('#newvenuerow1').fadeIn(); jQuery('#newvenuerow2').fadeIn(); jQuery('#newvenuerow3').fadeIn(); jQuery('#newvenuerow4').fadeIn(); jQuery('#newvenuerow5').fadeIn(); jQuery('#newvenuerow6').fadeIn(); jQuery('#newvenuerow7').fadeIn(); }\n";
				$output .= "\telse {jQuery('#newvenuerow1').fadeOut(); jQuery('#newvenuerow2').fadeOut(); jQuery('#newvenuerow3').fadeOut(); jQuery('#newvenuerow4').fadeOut(); jQuery('#newvenuerow5').fadeOut(); jQuery('#newvenuerow6').fadeOut(); jQuery('#newvenuerow7').fadeOut();}\n";
				$output .= "});\n";
			}

			$output .= "});\n";

			$output .= "</script>\n";
		}

		return $output;
	}

}

$my_community_events_plugin = new community_events_plugin();

?>
