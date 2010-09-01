=== WP Sphinx Search ===
Contributors: prettyboymp
Donate link: http://voceconnect.com/
Tags: sphinx, search, sphinx search, advanced search
Requires at least: 3.0
Tested up to: 3.0.
Stable tag: trunk

Adds Sphinx integration to WordPress' search capability.

== Description ==

Sphinx is a GPL version 2 full-text search engine with the ability to run high speed search queries.  It can give users a more robust search experience by providing better phrase and word matching to better sort results by relevance.  This plugin integrates Sphinx's search capabilities to replace the normal search that WordPress provides.

== Installation ==

1. Configure Sphinx, see http://vocecommunications.com/blog/2010/07/extending-wordpress-search-with-sphinx-part-i/ for more details.
2. Upload the `wp-sphinx-search` folder to the `/wp-content/plugins/` directory
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Configure the options under Settings -> Sphinx Search to match your Sphinx Server.

== Screenshots ==

1.  Options page.

== Changelog ==
= 0.2.0 =
* added sorting to post query results to match order returned by sphinx
* added fallback sphinxapi.php to handle case where sphinx pecl extension isn't installed
= 0.1.0 =
* Initial Release
