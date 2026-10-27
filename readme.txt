=== Lite Loader for Tin Canny ===
Tags: learndash, tin canny, xapi, performance, ispring
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Makes the resume bookmarks that iSpring, Articulate and Captivate modules save through Tin Canny load only the plugins they need.

== Description ==

When a learner has an iSpring, Articulate Storyline or Adobe Captivate module open, the module saves its resume bookmark (xAPI "state") to Tin Canny every few seconds. Each of those saves is a full WordPress request: every active plugin and the site theme are loaded, even though Tin Canny only needs LearnDash to answer it.

On a membership or community LMS (BuddyBoss, WooCommerce, Elementor, SEO and analytics plugins...) that can mean one to two seconds of CPU per save. With a class of learners online at the same time, these saves alone can take most of the server's capacity and make the whole site slow.

This plugin loads only Tin Canny, LearnDash and your object cache plugin for those state requests, and a lightweight default theme instead of the site theme. In real use this made each save about three times faster.

= What it changes, and what it does not =

* Only requests to `/ucTinCan/.../activities/state` that carry a `stateId` are affected. Tin Canny answers these inside its own state handler and exits, without recording statements or marking anything complete.
* xAPI statements (`/ucTinCan/.../statements`), which can complete lessons and trigger LearnDash, BuddyBoss, Zapier, certificate or other completion hooks, still load the full site.
* Every page, wp-admin, AJAX, REST, cron and WP-CLI request is untouched.
* Nothing is written to the database: the trimmed plugin list exists only in memory for that one request, and the plugin blocks any attempt to save it.

= How it works =

WordPress reads the list of active plugins before it loads any regular plugin, so a regular plugin cannot prevent others from loading. Must-use plugins load earlier. On activation this plugin copies a small loader into `wp-content/mu-plugins/`; on deactivation it removes it. The loader stays idle unless this plugin, Tin Canny and LearnDash are all active.

== Installation ==

1. Test on a staging copy of your site first.
2. Install and activate the plugin. It installs its loader in `wp-content/mu-plugins/`.
3. Go to Tools > Tin Canny Lite Loader and check that the loader status is "Installed and up to date".
4. Open a module as a learner, move through a few slides, reload the page and check that it resumes where you left off.
5. In the browser's developer tools (Network tab), select an `activities/state` request: its response headers include `X-Lite-Loader`.

== Frequently Asked Questions ==

= Does it change my site's theme? =

No. Your theme stays active for every visitor and every page. The lightweight theme is used only, in memory, during state requests, which never display anything. It only needs to be installed.

= A plugin I use hooks into Tin Canny's state handling. =

Add it under Tools > Tin Canny Lite Loader > Plugins to load. Filters such as `tincanny_process_state` or `uo_tincanny_by_request_url` only run if the plugin that adds them is loaded.

= How do I turn it off quickly? =

Deactivate the plugin, or untick "Lite mode" in its settings. Without wp-admin access, add `define( 'LLFTC_DISABLE', true );` to wp-config.php, or delete `wp-content/mu-plugins/lite-loader-for-tin-canny-loader.php`.

= Does it work on multisite? =

It has not been tested on multisite. Network-activated plugins are not filtered.

== Changelog ==

= 1.0.0 =
* First release.
