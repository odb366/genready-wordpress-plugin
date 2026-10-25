=== GenReady – AI Search & Agent Readiness ===
Contributors: genready
Tags: llms.txt, robots.txt, schema, ai search, ai crawlers
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

See how AI search engines and agents read your pages. Free score, no account needed. Fixes for robots.txt, llms.txt and JSON-LD.

== Description ==

GenReady shows how ready your site is to be found, read and cited by AI search engines (such as ChatGPT and Perplexity) and used by AI agents.

The plugin shows your AI readiness score inside WordPress. It also shows "How AI sees this page": a screenshot and the text that AI crawlers read.

Every fix has a preview, an Apply button and an Undo button. Nothing changes on your site until you click Apply.

= Free, no account needed =

* The score of any public page on your site, with the Content, Crawlability and Agents scores.
* "How AI sees this page": a screenshot next to the text AI crawlers read.
* A list of findings that tells you what to fix.
* 3 scans a day for each site.

= Free with a GenReady account =

* One click in the plugin makes the account and saves your site and your results.
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

This plugin uses the GenReady service at https://genready.ai. GenReady scans your pages and generates the fixes. The plugin needs this service to work.

The plugin sends nothing until an administrator clicks a button on the GenReady page. It never sends data about your visitors. It adds no JavaScript, no cookies and no requests to GenReady to your public pages.

What the plugin sends, and when:

* "Check AI readiness" and "Scan again" without an account: your site address, the address of the page, the plugin version and the WordPress version. GenReady then loads that public page from the internet, like a crawler, and keeps the result. The plugin keeps a random key for each result, so it can show the result to you. Without an account, the plugin can show a result for 30 days.
* "Create my free account": the email address in the field, your site address, the keys of your earlier results, a random one-time code, the plugin version and the WordPress version. GenReady then asks your site for that code at /wp-json/genready/v1/proof/ to confirm that the site is yours. The plugin answers each code once, within 2 minutes. GenReady creates a free account for the email, adds your site, moves your earlier results into the account and emails a link to set a password. If nobody sets the password within 30 days, GenReady deletes the account.
* "Connect to GenReady", and every request after you connect: your site address, and for a scan the address of the page. The plugin version and the WordPress version go in the User-Agent header.
* Your admin email address: only when you tick "Send my admin email to fill in the sign-in form", and only on the Connect click. GenReady uses it to fill in the sign-in form.
* The text of your current robots.txt: when you preview the robots.txt fix.
* The text of your current llms.txt, and the titles, addresses and types of your public pages and posts: when you preview the llms.txt fix.
* The title, the author display name, the dates and the type of one post or page: when you preview the JSON-LD fix for it.
* The path and a hash of a file you applied: when you click "Check the live file". GenReady then fetches that public file from your site.
* The API key you paste: when you use an API key instead of Connect.
* When you verify the site or start a scan, GenReady fetches your public pages and robots.txt from the internet, like a crawler.

The plugin stores your keys and tokens encrypted in your WordPress database. When you disconnect an OAuth connection, the plugin sends a revoke request to GenReady and deletes the tokens.

Read the terms of service at https://genready.ai/terms and the privacy policy at https://genready.ai/privacy.

== Installation ==

1. Install the plugin from Plugins, Add New, or upload the folder to `/wp-content/plugins/`.
2. Activate it.
3. Go to Settings, GenReady and click "Check AI readiness".
4. To save your site and track your score, click "Create my free account".

Agencies and existing users click "Already have a GenReady account? Connect".

If GenReady cannot reach your site, use "Use an API key". Create a key for one site on genready.ai and paste it.

== Frequently Asked Questions ==

= Do I need a GenReady account? =

No. "Check AI readiness" shows the score of any public page on your site, 3 times a day. A free account saves your site and your results. The fixes need GenReady Pro or Agency.

= What happens when I create an account in the plugin? =

GenReady creates a free account for the email, adds this site and connects the plugin. The plugin then adds a GenReady verification tag to your home page. GenReady emails you a link to set your password. You need the password, or Google sign-in with the same address, to sign in on genready.ai, for example to upgrade. If nobody sets the password within 30 days, GenReady deletes the account.

= I already have a GenReady account. =

Click "Already have a GenReady account? Connect" and sign in.

= Why could GenReady not confirm that the site is mine? =

GenReady reads a one-time code from your site's REST API. A security plugin that blocks the REST API stops this. So does a WordPress install in a subfolder, or a server that answers one request at a time. Click "Connect to GenReady" instead. If a security plugin blocks the REST API, Connect fails too: then use an API key.

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

No. The score, "How AI sees this page" and the findings are free. The three fixes need GenReady Pro or Agency.

= My security plugin blocks the REST API. =

Use an API key. GenReady reads the OAuth client document from your site's REST API, so Connect fails when a security plugin blocks it. An API key does not need that document.

= I use Kinsta. =

Clear the Kinsta cache in MyKinsta after you apply a fix. Kinsta has no public purge API, so the plugin cannot do it for you.

= Where is the source code? =

The source code is on GitHub: https://github.com/odb366/genready-wordpress-plugin

== Changelog ==

= 1.1.0 =
* The fixes are on the Overview, under the results. Each fix says what it does and lists the problems from your scan that it solves.
* "What AI reads" shows the text with its headings, lists and links. "Show the raw text" shows the exact text.
* The screenshot shows as soon as GenReady makes it, without a reload.
* A scan shows a spinner and the seconds since it started.
* The button is now "Check AI readiness", and the "Page to analyze" field is wider.
* After the daily limit, a button opens the free account form.
* The Connection tab is now the Account tab.
* The plugin includes Parsedown 1.8.0 (MIT license) to format the text.

= 1.0.0 =
* First release: the score of any public page without an account, a free account in one click, connection to GenReady, and the robots.txt, llms.txt and JSON-LD fixes.
