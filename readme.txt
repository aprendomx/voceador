=== Voceador – Autopublicación de notas en redes sociales ===
Contributors: aprendomx
Tags: social media, autoposting, facebook, news, publishing
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically announces every new post on your Facebook Pages: the featured image, a caption from a template and a comment with the link.

== Description ==

Voceador announces on social media every post that a news site publishes for the first time. It is built for newsrooms that run several sites and need each one to post to its own Pages without anyone remembering to do it by hand.

When a post is published, Voceador queues one job per connected channel and, for each Facebook Page:

1. Publishes the featured image as a photo with a caption rendered from a template.
2. After a configurable delay, leaves a comment from the Page itself with the link to the post.

**What is included in this version**

* Connect one or more Facebook Pages with Facebook Login (OAuth), or with a Page access token via WP-CLI.
* Connect your Pages from the WordPress dashboard with a "Connect with Facebook" button; no tokens to copy by hand.
* A daily health check that pauses a channel and shows a notice when its token stops working.
* Caption and comment templates with placeholders: `{title}`, `{excerpt}`, `{permalink}`, `{shortlink}`, `{site_name}`, `{author}`, `{category}`, `{categories}`, `{date}` and `{social_message}` with a configurable fallback chain.
* Routing rules per channel by post type, plus per-post overrides through post meta.
* A queue that uses Action Scheduler when another plugin provides it and falls back to WP-Cron, with delays, retries and exponential backoff.
* Error handling per class: temporary errors are retried, rate limits are rescheduled, and authentication or permission errors pause the channel instead of retrying.
* An activity log in its own table that never records tokens or secrets.
* Access tokens and app credentials encrypted at rest with libsodium.
* WP-CLI commands: `wp voceador channels add-facebook`, `channels list`, `channels delete`, `channels check`, `publish`, `retry` and `status`.
* Works on single sites and on Multisite, with per-site configuration.

**On the roadmap**

A full settings screen for rules, templates and scheduling, an editor panel for the newsroom, Instagram support with image processing and a link-in-bio page, an onboarding wizard and email notifications.

**Requirements**

A Meta app and a Facebook Page you administer. Only professional Instagram accounts (Business or Creator) will be supported when Instagram support lands, because Meta does not allow publishing to personal accounts through its API.

The plugin interface and documentation are written in Spanish.

== External services ==

This plugin connects to Meta's Graph API (graph.facebook.com and, in future versions, graph.instagram.com) in order to publish content to the Facebook Pages and Instagram accounts that the site administrator explicitly connects.

* What is sent: the caption and comment text, the featured image (or its public URL) and the access token of the connected channel.
* When: when a post that matches the configured rules is published, when an administrator triggers a manual publish or retry, and during the periodic token health check.
* Nothing is sent until an administrator connects a channel.

This service is provided by Meta Platforms, Inc.: [Platform Terms](https://developers.facebook.com/terms/) and [Privacy Policy](https://www.facebook.com/privacy/policy/).

The plugin sends no data to the plugin author, contains no analytics or telemetry, and makes no other external requests.

== Installation ==

1. Upload the `voceador` folder to `/wp-content/plugins/`, or install the ZIP from Plugins → Add New.
2. Activate the plugin.
3. Create a Meta app with the Facebook Login product and register the redirect URI shown on Voceador → Connections as a valid OAuth redirect URI.
4. Enter the App ID and App Secret on Voceador → Connections.
5. Click "Connect with Facebook", sign in and choose the Pages you want to connect.
6. Publish a post with a featured image, or run `wp voceador publish <post_id>` to test it.

A setup wizard will simplify this flow in a future version. Advanced users can still connect a Page manually with a Page access token: `wp voceador channels add-facebook --page-id=<id> --token-file=- --alias="My Page"`.

== Frequently Asked Questions ==

= Do I need my own Meta app? =

Yes. Each site connects through a Meta app that you create, or one shared across your network of sites. No App Review is needed while the person connecting the Page is an administrator, developer or tester of the app.

= Where are my access tokens stored? =

Encrypted with libsodium in your own database. The encryption key is derived from `VOCEADOR_ENCRYPTION_KEY` if you define it in `wp-config.php`, otherwise from your site's authentication salts. Rotating those salts invalidates the stored tokens and you will have to reconnect the channels.

= Does it publish posts that were already published before? =

No. Voceador marks the first publication of each post and ignores later transitions, unless you enable republishing in the settings.

= What happens if a publication fails? =

Temporary errors and rate limits are retried automatically with backoff. Authentication and permission errors pause the channel and record a notice, because retrying them would not help. The comment is a separate step: if the photo is published and the comment fails, only the comment is retried.

= Does it work with Instagram? =

Not yet. Instagram support is on the roadmap and will require a professional (Business or Creator) account.

== Changelog ==

= 0.2.0 =
* Connect Facebook Pages with Facebook Login (OAuth) from the WordPress dashboard, no tokens to copy by hand; manual Page access tokens via WP-CLI are still supported.
* App ID and App Secret are stored encrypted with libsodium.
* A daily health check calls Facebook's `debug_token`, pauses a channel and shows an admin notice when its token stops working or is missing permissions.
* New WP-CLI command `wp voceador channels check [<id>] [--all]` to inspect (or, with `--all`, run the same check the daily cron runs) a channel's token health.
* `wp voceador status` now reports whether the Meta app is configured and its OAuth redirect URI.

= 0.1.0 =
* First development release: Facebook Pages with a manual access token, queue with retries, templates, routing rules, activity log and WP-CLI commands.
