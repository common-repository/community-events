<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

global $my_community_events_plugin;

function community_events_rss_feed() {
	require_once( plugin_dir_path( __FILE__ ) . "rss.genesis.php" );

	global $wpdb;

	$rss = new rssGenesis();

	$options = get_option('CE_PP');

	$feedtitle = ($options['rssfeedtitle'] == "" ? "Community Events Calendar Feed" : $options['rssfeedtitle']);
	$feeddescription = ($options['rssfeeddescription'] == "" ? "Community Events Generated Feed Description" : $options['rssfeeddescription']);

	// CHANNEL
	$rss->setChannel (
		$feedtitle, // Title
		home_url () . '/feed/communityeventsfeed', // Link
		$feeddescription, // Description
		null, // Language
		null, // Copyright
		null, // Managing Editor
		null, // WebMaster
		null, // Rating
		"auto", // PubDate
		"auto", // Last Build Date
		"Community Events Calendar", // Category
		null, // Docs
		null, // Time to Live
		null, // Skip Days
		null // Skip Hours
	);

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

	$queryday = $currentday;
	$queryyear = $currentyear;

	$eventquery = "(SELECT *, DATE_FORMAT(event_start_date, '%m/%d/%y') as formatted_start_date, DATE_FORMAT(event_end_date, '%m/%d/%y') as formatted_end_date, if(char_length(event_start_minute)=1,concat('0',event_start_minute),event_start_minute) as event_start_minute_zeros, ";
	$eventquery .= "UNIX_TIMESTAMP(event_start_date) as datestamp, DAYOFYEAR(DATE(event_start_date)) as doy from " . $wpdb->prefix . "ce_events e LEFT JOIN ";
	$eventquery .= $wpdb->prefix . "ce_venues v ON e.event_venue = v.ce_venue_id LEFT JOIN " . $wpdb->prefix . "ce_category c ON e.event_category = c.event_cat_id ";
	$eventquery .= "where YEAR(event_start_date) = " . $queryyear;
	$eventquery .= " and DAYOFYEAR(DATE(event_start_date)) >= " . $queryday . " ";
	$eventquery .= " and event_end_date IS NULL ";

	if ($options['moderateevents'] == true)
		$eventquery .= " and event_published = 'Y' ";

	$eventquery .= ") UNION ";

	$eventquery .= "(SELECT *, DATE_FORMAT(event_start_date, '%m/%d/%y') as formatted_start_date, DATE_FORMAT(event_end_date, '%m/%d/%y') as formatted_end_date, if(char_length(event_start_minute)=1,concat('0',event_start_minute),event_start_minute) as event_start_minute_zeros, ";
	$eventquery .= "UNIX_TIMESTAMP(event_start_date) as datestamp, DAYOFYEAR(DATE(event_start_date)) as doy from " . $wpdb->prefix . "ce_events e LEFT JOIN ";
	$eventquery .= $wpdb->prefix . "ce_venues v ON e.event_venue = v.ce_venue_id LEFT JOIN " . $wpdb->prefix . "ce_category c ON e.event_category = c.event_cat_id where ";

	$eventquery .= "((YEAR(event_start_date) = " . $queryyear;
	$eventquery .= " and DAYOFYEAR(DATE(event_start_date)) <= " . $queryday . " ";
	$eventquery .= " and DAYOFYEAR(DATE(event_end_date)) >= " . $queryday . ") ";

	$eventquery .= "OR (YEAR(event_start_date) < " . $queryyear;
	$eventquery .= " and DAYOFYEAR(DATE(event_end_date)) >= " . $queryday;
	$eventquery .= " and DAYOFYEAR(YEAR(event_end_date)) >= " . $queryyear . ") ";

	$eventquery .= "OR (YEAR(event_end_date) > " . $queryyear . " ";
	$eventquery .= " and DAYOFYEAR(DATE(event_start_date)) <= " . $queryday . ") ";

	$eventquery .= "OR (YEAR(event_end_date) = " . $queryyear;
	$eventquery .= " and YEAR(DATE(event_start_date)) < " . $queryyear;
	$eventquery .= " and DAYOFYEAR(DATE(event_end_date)) >= " . $queryday . ")) ";

	if ($options['moderateevents'] == true)
		$eventquery .= " and event_published = 'Y' ";

	$eventquery .= ") order by event_start_date, event_name";
	$events = $wpdb->get_results($eventquery, ARRAY_A);

	if ($events)
	{
		foreach ($events as $event)
		{
			$event_name_string = esc_html($event['event_name']) . ": " . $event['formatted_start_date'];

			if ($event['formatted_end_date'] != '')
				$event_name_string .= " - " . $event['formatted_end_date'];

			$event_name_string .= " at " . esc_html($event['ce_venue_name']) . ", " . $event['event_start_hour'] . ":" . str_pad($event['event_start_minute'], 2, "0", STR_PAD_LEFT) . " " . $event['event_start_ampm'];

			// ITEM
			$rss->addItem (
				esc_html($event_name_string), // Title
				$options['rssfeedtargetaddress'], // Link
				"", // Description
				$event['event_start_date'], //Publication Date
				esc_html($event['event_cat_name']) // Category
			);

		}
	}

	header( 'Content-Type: '. feed_content_type('rss-http') . '; charset=' . get_option('blog_charset') );
	print( $rss->getFeed() );
}

?>
