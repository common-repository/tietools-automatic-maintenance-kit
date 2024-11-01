=== TIEtools Automatic Maintenance Kit ===
Contributors: TIEro
Donate link: http://www.setupmyvps.com/tietools/
Tags: post, expiry, expiration, expire, automatic, automated, category, categories, log, file, delete, remove, clean, duplicate post, delete, deletion, autoblog, auto blog, notify, notification, images
Requires at least: 3.0.1
Tested up to: 4.0
Stable tag: 1.2.2
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Automatic post and image expiry, duplicate post detection and server log deletion to keep your site clean and efficient.

== Description ==

Provides automated post maintenance. TIEtools is ideal for sites with a news feed or other automated posting setup that requires regular hands-off trimming (including autoblogging). 

**Post Expiry**

* Expires published, draft, pending and private posts on demand, based on age, maximum post retention, post views and post likes.
* Integrates with BAW Post Views Count and WTI Like Post.
* Sends notification emails for expired posts, if required.
* Includes or excludes user-defined list of categories.
* Leaves unwanted posts in the Trash.

**Image Expiry**

* Can expire images based on post age, without removing the parent posts.
* Expires images from published, draft, pending and private posts on demand.
* Includes or excludes user-defined list of categories.
* Deletes old images or leaves them "unattached" in the Media Library for later handling.

**Duplicate Post Deletion**

* Finds and removes duplicate posts by title.
* Keeps the oldest or newest original copy, removing all others.
* Checks in published, draft, pending and private posts on demand. 
* Includes or excludes user-defined list of categories. 
* Sends notification emails for duplicate posts, if required.
* Leaves unwanted posts in the Trash.

**Server Log Deletion**

* Cleans up server error logs.
* Checks for user-defined log filename.

All processes run automatically using wp-cron.

== Installation ==

1. Upload the plugin folder and its contents to the /wp-content/plugins directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Set your options using the TIEtools menu at the bottom of the Dashboard menu.

Alternatively, use the built-in 'Add New' option on the Plugins menu to install.

== Frequently Asked Questions ==

= Do I need the other plugins as well? =

No. This is the all-in-one version for people who want the functionality of three separate plugins in one place.

= Does it work on multisite? =

Absolutely no idea. It was never designed for that, so I'd honestly be surprised if it does.

= Is this plugin actively maintained? =

Yes, it is. I would LOVE to hear your comments, see your reviews and listen to what you'd like added or changed. You can do that here on WP.org (through the support forum) or at http://www.setupmyvps.com/tietools.

= Are the other plugins still kept up to date? =

No. Since they're all included in TIEtools, I don't bother to add new functionality to the others any more. Any reported bugs get fixed, of course.

= Are there any differences between this plugin and the others? =

There are a few differences, yes. The most obvious - apart from the revamped options page - is the addition of on/off switches for each process. 

On top of that, TIEtools offers image expiry which is not available in TIEexpire. This functionality allows you to strip images out of existing posts after a given number of days, so you can keep post text while saving space.

The email notification process is extended in TIEtools. As well as sending notification of expired posts, it can send emails for removed duplicates. This is not available in TIEdupedeleter.

There's also a more flexible error log filename option in this version which isn't in the separate plugin because that one is specifically built to run without any input or intervention.

= I use one (or more) of the other plugins. Do I have to re-enter all my settings? =

No. All the plugins use exactly the same option names wherever there is overlap. They will retain your settings when switching from the separate plugins to the all-in-one or vice versa, provided nothing weird happens.

= Will the individual plugins conflict with this one? =

They shouldn't. They use different function names but the same options and database queries, so they should coexist peacefully (and unnecessarily).

= I have a question about one of the individual plugins. =

Please refer to the appropriate plugin's list of questions:

* [TIEexpire FAQ](http://wordpress.org/plugins/tieexpire-automated-post-expiry/faq/)
* [TIEdupedeleter FAQ](http://wordpress.org/plugins/tiedupedeleter-simple-duplicate-post-deleter/faq/)
* [TIElogremover FAQ](http://wordpress.org/plugins/tielogremover/faq/)

= How does the image expiry work? =

Image expiry allows news sites to keep the text of their posts but shed all the space-consuming imagery after a user-defined number of days. The plugin does this by stripping out the HTML that shows images, the WordPress shortcodes for image captions and "unattaching" the images.

You can optionally delete the images (and their associated resized versions) as they are stripped out of the posts or you can leave them in your media library and deal with them later. Note that the automatic deletion does not work retroactively (i.e. it won't go through your media library deleting all your unattached images).

= What about unattached images that I use? =

Some themes and other addons put unattached images into the media library. For example, a blog logo that you upload to a theme's settings page usually ends up there. It is possible for the image expiry process to remove these images.

If you want to play safe, don't use the delete option in the image expiry settings. Alternatively, attach the images to a page of your choice so that they will be secure.

= Can I change the post expiry order? =

Yes, but you'll have to edit the plugin file. Look for the function called TIEtools_postexpire and move things around in there.

= Can I change the notification email? =

Yes. You'll have to edit the plugin file, though. Look for the TIEtools_send_notification function (it's at the end of the file, so it's easy to find). There's a different email for each recipient, with an additional phrase added to all three for duplicate posts, so you can customise to your heart's content.

= How often does the wp_cron job run? =

At most once per hour. You can change this in the do_TIEtools_activation function: switch the value 'hourly' to whatever suits you (and will work with wp_cron).

= Does the plugin cause major slowdowns when it runs? =

The very first time the queries run, it might. This is especially true if you have a *lot* of posts and use several of the checks. Notifications are particularly ponderous. 

In testing, it caused a delay of a few seconds in page serving the first time it ran with all three notification emails marked and around 100 expirations to do. After that, I never noticed a delay again, even with a reasonable expiry rate.

= Is there any documentation? =

You're reading it. The plugin code is also heavily commented to help you find your way. You can visit the plugin homepage at http://www.setupmyvps.com/tietools for thoughts and comments.

== Changelog ==

= 1.2.2 =

- Small text change on options page, for clarity.
- Big readme update.

= 1.2.1 =

- Removed redundant processing when expiring images: code now either detaches or deletes, not both.

= 1.2 =

- Confirmed compatibility with WordPress 3.8 changes (plugin functions were unaffected).
- Loads of small CSS changes to fix the options screen for WP3.8 back-end changes.
- Shaded option box backgrounds to make sections clearer.
- Added functionality to strip images and captions out of old posts independently from post expiry.
- Added functionality to delete images (and their dependent thumbs and so on) as they are stripped out.

= 1.1 =

- Added email notification functionality for expired and duplicate posts.
- Compatibility maintained with TIEexpire.
- On/Off switches moved to top of options page for ease of use.
- Additional "Save Changes" button added at the top of options page for quick on/off switching.

= 1.0 =
Original working release.
