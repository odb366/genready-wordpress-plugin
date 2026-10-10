=== GenReady – AI Search & Agent Readiness ===
Contributors: genready
Tags: llms.txt, robots.txt, schema, ai search, ai crawlers
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

See how AI search engines and agents read your site, and fix robots.txt, llms.txt and JSON-LD for them. Needs a free GenReady account.

== Description ==

GenReady shows how ready your site is to be found, read and cited by AI search engines (such as ChatGPT and Perplexity) and used by AI agents.

The plugin connects your site to your GenReady account. It shows your AI readiness score inside WordPress. It also shows "How AI sees your homepage": a screenshot and the text that AI crawlers read.

Every fix has a preview, an Apply button and an Undo button. Nothing changes on your site until you click Apply.

= Free with a GenReady account =

* Your AI readiness score, with the Content, Crawlability and Agents scores.
* "How AI sees your homepage": a screenshot next to the text AI crawlers read.
* A list of findings that tells you what to fix.
* A new scan on request. Each scan uses 1 API credit from your plan.

= With GenReady Pro and Agency =

The GenReady service generates the fixes. The plugin previews, applies, serves and undoes them.

* robots.txt: rules for AI crawlers and a Content-Signal line. GenReady checks each change with two robots.txt parsers. It refuses a change that alters access for a crawler that is not an AI crawler.
* llms.txt: a summary of your site and your best pages for AI agents, built from your WordPress pages and posts.
* JSON-LD: structured data for one post, one page or your home page. GenReady adds only the missing properties. It never overwrites a value from your theme or SEO plugin.

= For agencies =

One GenReady account covers all your client sites. The Agency plan includes 10 sites. Each site has its own connection, so a problem on one site does not reach your other sites.

= Works with =

Yoast SEO, Rank Math and All in One SEO. The plugin detects them and avoids duplicate llms.txt files and duplicate structured data. It also clears the page cache of the common cache plugins after each change.

== External services ==

This plugin connects to the GenReady service at https://genready.ai. GenReady analyses your site and generates the fixes. The plugin needs this service to work.

The plugin sends nothing until an administrator clicks "Connect to GenReady" or "Use an API key". After that, it sends data only when an administrator uses the plugin page. It never sends data about your visitors. It adds no JavaScript, no cookies and no requests to GenReady to your public pages.

What the plugin sends, and when:

* Your site address (the host name). When you connect, and with every request to GenReady.
* The plugin version and the WordPress version, in the User-Agent header. When you connect (the OAuth client document) and with every request.
* Your admin email address. Only when you tick "Send my admin email to fill in the sign-in form", and only on the Connect click. GenReady uses it to pre-fill the sign-in form.
* The text of your current robots.txt. When you preview the robots.txt fix.
* The text of your current llms.txt, and the titles, addresses and types of your public pages and posts. When you preview the llms.txt fix.
* The title, the author display name, the dates and the type of one post or page. When you preview the JSON-LD fix for it.
* The path and a hash of a file you applied. When you click "Check the live file". GenReady then fetches that public file from your site.
* The API key you paste. When you use an API key instead of Connect. The plugin stores it encrypted.
* Nothing more when you verify the site or start a scan. GenReady then fetches your public home page and robots.txt from the internet, like a crawler.

The plugin stores your connection tokens encrypted in your WordPress database. When you disconnect, it sends a revoke request to GenReady and deletes the tokens.

Read the terms of service at https://genready.ai/terms and the privacy policy at https://genready.ai/privacy.

== Installation ==

1. Install the plugin from Plugins, Add New, or upload the folder to `/wp-content/plugins/`.
2. Activate it.
3. Go to Settings, GenReady and click "Connect to GenReady". Sign in or create a free account.
4. The plugin adds a verification tag to your home page and starts the first scan.

If GenReady cannot reach your site, use "Use an API key". Create a key for one site on genready.ai and paste it.

== Frequently Asked Questions ==

= Does it change what Google may crawl? =

No. GenReady checks every robots.txt change with two parsers. It refuses the change when it alters access for a crawler that is not an AI crawler.

= What happens if I deactivate the plugin? =

Nothing is deleted. Fixes that the plugin serves stop, because the plugin is off. A robots.txt or llms.txt file that the plugin wrote to your site root stays, because it holds your own rules plus the AI rules that GenReady checked. Delete the plugin to restore your original files.

= What happens if I delete the plugin? =

The plugin removes its options, post meta and transients. It restores your original robots.txt or llms.txt only when nobody edited the file after the plugin wrote it. If you edited the file, the plugin leaves it as it is. If you are connected, it also revokes the connection at GenReady.

= What happens if my plan ends? =

Fixes you applied stay, and the plugin keeps serving them. They stop updating. Undo still works without a connection.

= Does it work with Yoast SEO, Rank Math and All in One SEO? =

Yes. For JSON-LD, the plugin adds missing properties to the graph of your SEO plugin and does not overwrite what the plugin sets. For llms.txt, it detects an SEO plugin that already makes the file and tells you how to avoid a conflict.

= Do I need a paid plan? =

No. The score, the homepage view and the findings are free. The three fixes need GenReady Pro or Agency.

= My security plugin blocks the REST API. =

Use an API key. GenReady reads the OAuth client document from your site's REST API, so Connect fails when a security plugin blocks it. An API key does not need that document.

= I use Kinsta. =

Clear the Kinsta cache in MyKinsta after you apply a fix. Kinsta has no public purge API, so the plugin cannot do it for you.

= Where is the source code? =

The source code link is added before release.

== Changelog ==

= 1.0.0 =
* First release: connection to GenReady, score and homepage view, and the robots.txt, llms.txt and JSON-LD fixes.
