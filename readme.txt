=== Community Events ===
Contributors: jackdewey
Donate link: https://ylefebvre.github.io/wordpress-plugins/community-events/
Tags: events, list, AJAX, calendar, community
Requires at least: 3.0
Tested up to: 6.5.5
Stable tag: 1.5.1

The purpose of this plugin is to allow users to create a schedule of upcoming events and display events for the next 7 days in an AJAX-driven box or displaying a full list of upcoming events.

== Description ==

The purpose of this plugin is to allow users to create a schedule of upcoming events and display events for the next 7 days in an AJAX-driven box or displaying a full list of upcoming events.

You can try it out in a temporary copy of WordPress [here](https://demo.tastewp.com/community-events).

== Installation ==

1. Download the plugin and unzip it.
1. Upload the community-events folder to the /wp-content/plugins/ directory of your web site.
1. Activate the plugin in the Wordpress Admin.
1. Using the Configuration Panel for the plugin, create events, venues and categories.
1. To see the 7-day schedule box, in the Wordpress Admin, create a new page containing the following code: [community-events-7day]
1. To see the full schedule, in the Wordpress Admin area, create a new page containing the following code: [community-events-full]
1. To see a link for the full schedule in the 7-day box, set the address of the full schedule page in the Community Events settings.

== Changelog ==

= 1.5.1 =
* Fixed potential security issue

= 1.5 =
* Fixed potential security issues

= 1.4.9 =
* Fixes for potential security issues

= 1.4.8 =
* Fixed potential security issues

= 1.4.7 =
* Fix for default stylesheet
* Fixed PHP notices

= 1.4.6 =
* Updated for PHP 7.0 compatibility

= 1.4.5 =
* Fixed problem with category type deletion

= 1.4.4 =
* Changed date display functions to use date_i18n and show proper localized date text

= 1.4.3 =
* Fixed issues with database table creation affecting new plugin users

= 1.4.2 =
* Fix to RSS Feed generator

= 1.4.1 =
* Removed all traces of wp-load.php
* Changed RSS feed URL from wp-content/plugins/community-events/rssfeed.php to /feed/communityeventsfeed (need to save Permalinks to enable)

= 1.4 =
* Add new feature to filter events by user name
* Fix SQL injection vulnerabilities

= 1.3.5 =
* Modified script loading code only to be process on events page under admin section

= 1.3.4 =
* Corrected PHP warnings and problem with calendar in admin interface

= 1.3.3 =
* Adds option order events by name or start time

= 1.3.2 =
* Fixed issue with viewing event in next year in back-end
* Fixed calendar widget not appearing in advanced search

= 1.3.1 =
* Fixed problem with Add Event button never becoming enabled.

= 1.3 =
* Added internationalization support

= 1.2.9 =
* Updated version of datepicker script to fix javascript errors with current WordPress versions.

= 1.2.8 =
* Fixed to query to display 7-day calendar to avoid events from following year to display

= 1.2.7 =
* Removed debugging code

= 1.2.6 =
* Fixes to query to display full calendar. Problems were showing up when approaching the end of the year

= 1.2.5 =
* Correct problem with special characters with RSS feed

= 1.2.4 =
* Fixed problem with hidden meta boxes with save controls with WordPress 3.3

= 1.2.3 =
* Fixed security exploit in link tracking code

= 1.2.2 =
* Changed name of CSS classes for item coloring from even and odd to community-events-even and community-events-off to avoid conflicts with other themes and templates

= 1.2.1 =
* Fixed code for e-mail notification to display new venue names when user submitted

= 1.2 =
* Added option to allow users to submit new venues by selecting last entry in venue list
* Added option to display and accept event end time
* Replaced calls to wp_specialchars with esc_html

= 1.1.2 =
* Fixed problem where moderation setting could not be unchecked

= 1.1.1 =
* Corrected problem with moderation mode not displaying links to be moderated in admin

= 1.1 =
* Added edit capability for users to edit events that they submitted
* Added counter for number of times an event link is clicked
* Split Full Schedule event listing onto two rows
* Other minor bug corrections

= 1.0.4 =
* Fixed problems with events not showing up if they were assigned an end date that is the same as the start date

= 1.0.3 =
* Added option to generate RSS feed for upcoming events. Outputs events for the day.
* Fixed problems with display of multi-day events in 7-day Outlook View
* Fixed problem with display of multi-day event in admin section
* Fixed problem with events in next year showing up every day until day of event

= 1.0.2 =
* Improvements to better handle single quote in event names, descriptions and venue names

= 1.0.1 =
* Added option to specify if outlook view should be default when it's displayed
* Added option to determine if search box should be shown in 7-day outlook view

= 1.0 =
- Added venue importer
- Added Captcha option
- Sends mail to administrator when new events are submitted

= 0.6 =
- Enhanced layout of Full Schedule Event View
- Added tooltips to events and venues in full schedule view
- Added links to see more events for the day when viewing 7-day outlook or individual day in outlook box
- Fixed Full Schedule View to show events that span multiple days
- Added Stylesheet Editor
- Adding pagination mechanism

= 0.5 =
* Fixed: Time Display
* Added: Events that span multiple days are now correctly displayed on all days
* Enhanced Full schedule table styling

= 0.4 =
* Fixed errors when clicking on dates in schedule
* Changed calendar plugin to use jQuery date picker
* Re-arranged back-end code to provide more structure and make admin sections hideable
* Added search capability
* Adding paging mechanism when viewing events in admin

= 0.3 =
* Removed duration field on events
* Change data entry for event time into hours and minutes
* Added end date field (but not currently using it to display events)
* Added option to put new events in moderation queue upon user submission
* Added moderation mechanism on admin page to view only events awaiting moderation and approve them
* Changed layout of full schedule and add event links

= 0.2.2 =
* Fixed some image styling to avoid problems with plugins assigning border to images.
* Fixed: unable to add events in version 0.2 through user form or back-end admin

= 0.2.1 =
* Added missing icons and javascript plugin (TipTip)

= 0.2 =
* Added calendar button next to date field in back-end to bring up calendar
* Added paging buttons in event section of admin to navigate events
* Limited calendar only to allow selections past current day
* Added tooltips in calendar view when mouse hovers over events to display more venue information and event information
* Made Day Links in 7-day view one link as opposed to two
* Added new outlook section to 7-day view to show one item per day
* Added calendar to upcoming events section to be able to choose other dates
* Added button to buy tickets when link is available
* Added shortcode to display form for visitors to submit events
* Added options in admin page to control display of new event form
* Plugin sends e-mail when new events are submitted
* Added scheduled task to perform daily cleanup of expired events in the database

= 0.1 =
* First checkin: Still a work-in-progress

== Frequently Asked Questions ==

There are no FAQs at this time.

== Screenshots ==

1. 7-day Outlook Event Calendar
2. Full Listing Event Calendar
3. User event submission form
