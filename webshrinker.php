<?php
/**
 * @package WebShrinker
 * @version 2.1
 */
/*
Plugin Name: Web Shrinker Site Thumbnails
Plugin URI: https://www.webshrinker.com/
Description: Add site previews to links in your posts
Author: Web Shrinker
Version: 2.1
*/

global $wpdb;
global $webshrinker_settings;

define(WEBSHRINKER_DB_TABLE, $wpdb->prefix . 'webshrinker');
define(WEBSHRINKER_VERSION, '2.0');

if (!class_exists("simple_html_dom_node"))
	require_once("simple_html_dom.php");

function webshrinker_init() {
	$settings = webshrinker_get_db_settings();

	if ($settings['secret_key'] == '') {
		/* v1 */
		$js_url = 'https://cdn.webshrinker.com/js/thumbnails/v1/webshrinker.js';
		add_action('wp_head', 'webshrinker_js_init');
	} else {
		/* v2 */
		$js_url = 'https://cdn.webshrinker.com/js/thumbnails/v2/webshrinker.js';
	}

	wp_register_script('webshrinker', $js_url);
	wp_enqueue_script('webshrinker');
}

function webshrinker_js_init() {
	$settings = webshrinker_get_db_settings();

	$isHoverEnabled = ($settings['hover_enabled'] == 1) ? true : false;
	$hoverSelector = $settings['hover_selector'];
	$accessKey = $settings['access_key'];
	$hoverSize = $settings['hover_size'];

	if (!$isHoverEnabled)
		return;

	echo "<!-- Begin Web Shrinker thumbnail config -->\n";
	echo "<script type=\"text/javascript\">\n";
	echo "    WebShrinkerKey = '".$accessKey."';\n";
	echo "    WebShrinkerSize = '".$hoverSize."';\n";
	echo "    WebShrinkerHoverSelector = '".$hoverSelector."';\n";
	echo "</script>\n";
	echo "<!-- End Web Shrinker thumbnail config -->\n";
}

function webshrinker_init_db() {
	global $wpdb;

	$result = true;

	// First time install
	if($wpdb->get_var("SHOW TABLES LIKE '".WEBSHRINKER_DB_TABLE."'") != WEBSHRINKER_DB_TABLE) {
		$sql = "CREATE TABLE IF NOT EXISTS ".WEBSHRINKER_DB_TABLE." (access_key CHAR(36), secret_key CHAR(36), hover_size CHAR(9), hover_enabled TINYINT(1), hover_selector TEXT)";
		$result = $wpdb->query($sql);
		$result = $result && $wpdb->query("TRUNCATE ".WEBSHRINKER_DB_TABLE);
		$result = $result && $wpdb->insert(WEBSHRINKER_DB_TABLE, 
			array(
				'hover_selector' => base64_encode('a.webshrinker,a.webshrinker_post_hover'),
				'hover_size' => 'xlarge'
			),
			'%s'
		);

		add_option('webshrinker_version', WEBSHRINKER_VERSION);
	} else {
		// Upgrade path
		$old_version = get_option('webshrinker_version', '1.1');

		switch ($old_version) {
			case '1.0':
			case '1.1':
				$sql = "ALTER TABLE ".WEBSHRINKER_DB_TABLE." ADD COLUMN secret_key CHAR(36) AFTER access_key";
				$result = $wpdb->query($sql);

				if ($result)
					update_option('webshrinker_version', WEBSHRINKER_VERSION);

				break;
		}
	}

	if (!$result) {
		echo "There was an issue creating the database table used for this plugin. Please refresh this page and try again.";
		return false;
	}

	return true;
}

function webshrinker_uninstall_db() {
	global $wpdb;

	$sql = "DROP TABLE IF EXISTS " . WEBSHRINKER_DB_TABLE;
	$wpdb->query($sql);
	delete_option('webshrinker_version');
}

function webshrinker_get_db_settings($force = false) {
	global $wpdb;
	global $webshrinker_settings;

	if ($webshrinker_settings && !$force)
		return $webshrinker_settings;

	$query = "SELECT access_key, secret_key, hover_size, hover_enabled, hover_selector FROM " . WEBSHRINKER_DB_TABLE . " LIMIT 1";
	$data = $wpdb->get_results($query, ARRAY_A);
	$data = isset($data[0]) ? $data[0] : $data;

	$data['hover_selector'] = base64_decode($data['hover_selector']);

	$webshrinker_settings = $data;
	return $webshrinker_settings;
}

function webshrinker_add_pages() {
	add_options_page('Web Shrinker Thumbnails', 'Web Shrinker', 'manage_options', __FILE__, 'webshrinker_manager');
}

function webshrinker_manager() {
	global $wpdb;

	$settings = webshrinker_get_db_settings();

	// Did the manager save changes to this page
	if (count($_POST) > 0) {
		$success = true;
		$error = "";

		foreach ($_POST as $key => $value) {
			if (substr($key, 0, 3) == "ws_") {
				// Scrub the key name
				$key = preg_replace("/[^a-zA-Z0-9_]+/", "", substr($key, 3));

				if ($key == "hover_enabled") {
					// Make sure there is an access key to use
					if ((strlen($settings['access_key']) < 3 || strlen($settings['secret_key']) < 3) && $value == "1") {
						$error = "Before you can enable this plugin you must specify your access and secret key below.";
						$success = false;
						continue;
					}
				} else if ($key == "access_key") {
					$value = trim($value);
				} else if ($key == "secret_key") {
					$value = trim($value);
				} else if ($key == "hover_selector") {
					$value = implode(",", array_filter(explode("\r\n", $value)));
					$value = base64_encode($value);
				}

				// Save this value
				$success = $success & ($wpdb->query($wpdb->prepare("UPDATE ".WEBSHRINKER_DB_TABLE." SET ".$key." = %s", $value)) !== false);
			}
		}

		if (!$success) {
			echo "<div style='color: #d8000c; background-color: #ffbaba; border: 3px solid red; padding: 5px; margin-top: 5px;'>An error occurred while updating the settings in the database. ".$error."</div>\n";
		} else {
			echo "<div style='color: #4f8a10; background-color: #dff2bf; border: 3px solid green; padding: 5px; margin-top: 5px;'>Your changes have been saved.</div>\n";
		}
	}

	// re-read the latest DB values
	$settings = webshrinker_get_db_settings(true);

	$isHoverEnabled = ($settings['hover_enabled'] == 1) ? true : false;
	$hoverSelector = $settings['hover_selector'];
	$accessKey = $settings['access_key'];
	$secretKey = $settings['secret_key'];
	$hoverSize = $settings['hover_size'];

	echo "<script type=\"text/javascript\">\n";
	echo "  function ws_set_sample(width, height) {\n";
	echo "    document.getElementById('wsSample').style.display = 'block';\n";
	echo "    document.getElementById('wsSample').style.width = width + 'px';\n";
	echo "    document.getElementById('wsSample').style.height = height + 'px';\n";
	echo "  }\n";
	echo "</script>\n";

	echo "<div class='wrap'>\n";

	echo "<h2>Web Shrinker Thumbnails</h2>\n";
	echo "<br />\n";
	echo "<form name='webshrinker_enable_disable' action='".$_SERVER["REQUEST_URI"]."' method='post'>\n";
	echo "<div style='text-decoration: underline;'>Main Options</div>\n";
	echo "<div>Hover Link Thumbnails: <span style='font-weight: bold;'>".($isHoverEnabled ? 'Enabled' : 'Disabled')."</span></div>\n";
	echo "<input type='hidden' name='ws_hover_enabled' value='".($isHoverEnabled ? '0' : '1')."' />\n";
	echo "<div class='submit'><input type='submit' value='".($isHoverEnabled ? 'Disable' : 'Enable')." It' /></div>\n";
	echo "</form>\n";

	echo "<form name='webshrinker_manager' action='".$_SERVER["REQUEST_URI"]."' method='post'>\n";
	echo "<br />\n";
	echo "<div style='text-decoration: underline;'>Hover CSS Selectors</div>\n";
	echo "<p>This is an advanced feature, generally you won't need to change this setting but it is here if you do.  You can specify additional CSS selectors that will trigger hover thumbnail popups. For instance, you could add a CSS selector that would enable hover thumbnails for links in a blog roll section.</p>\n";
	echo "<div style='margin-left: 15px;'><pre>Any link with the class 'webshrinker' (default):</pre><code>a.webshrinker</code></div>\n";
	echo "<div style='margin-left: 15px;'><pre>Links in your posts (default):</pre><code>a.webshrinker_post_hover</code></div>\n";
	echo "<br />\n";
	echo "<textarea name='ws_hover_selector' cols='40' rows='4' style='width: 70%; font-size: 12px;' class='code'>\n";
	echo implode("\r\n", explode(",", $hoverSelector));
	echo "</textarea>\n";
	echo "<div><span style='font-weight: bold;'>Note:</span> Enter one CSS selector per line.</div>\n";
	echo "<div class='submit'><input type='submit' value='Save Changes' /></div>\n";
	echo "<br />\n";
	echo "<p>The access and secret keys can be found after logging into your Web Shrinker Dashboard at <a href='https://www.webshrinker.com/' target='_blank'>https://www.webshrinker.com</a>.<br /><b>Make sure the access and secret key are using the Thumbnails API v2 service.</b></p>\n";
	echo "<div style='text-decoration: underline;'>Access Key</div>\n";
	echo "<input name='ws_access_key' type='text' value='".$accessKey."' />\n";
	echo "<br />\n";
	echo "<br />\n";
	echo "<div style='text-decoration: underline;'>Secret Key</div>\n";
	echo "<input name='ws_secret_key' type='text' value='".$secretKey."' />\n";
	echo "<div class='submit'><input type='submit' value='Save Changes' /></div>\n";
	echo "<br />\n";
	echo "<div style='text-decoration: underline;'>Thumbnail Image Size</div>\n";
	echo "<div style='width: 35%; float: left;'>\n";
	echo "<div><input name='ws_hover_size' id='RequestMicroSize' value='micro' onchange='ws_set_sample(75,56);' type='radio' ".($hoverSize=='micro'?"checked=''":"")."><label for='RequestMicroSize'>Micro (75 x 56)</label></div>\n";
	echo "<div><input name='ws_hover_size' id='RequestTinySize' value='tiny' onchange='ws_set_sample(90,68);' type='radio' ".($hoverSize=='tiny'?"checked=''":"")."><label for='RequestTinySize'>Tiny (90 x 68)</label></div>\n";
	echo "<div><input name='ws_hover_size' id='RequestVerySmallSize' value='verysmall' onchange='ws_set_sample(100,75);' type='radio' ".($hoverSize=='verysmall'?"checked=''":"")."><label for='RequestVerySmallSize'>Very Small (100 x 75)</label></div>\n";
	echo "<div><input name='ws_hover_size' id='RequestSmallSize' value='small' onchange='ws_set_sample(120,90);' type='radio' ".($hoverSize=='small'?"checked=''":"")."><label for='RequestSmallSize'>Small (120 x 90)</label></div>\n";
	echo "<div><input name='ws_hover_size' id='RequestLargeSize' value='large' onchange='ws_set_sample(200,150);' type='radio' ".($hoverSize=='large'?"checked=''":"")."><label for='RequestLargeSize'>Large (200 x 150)</label></div>\n";
	echo "<div><input name='ws_hover_size' id='RequestXLargeSize' value='xlarge' onchange='ws_set_sample(320,240);' type='radio' ".($hoverSize=='xlarge'?"checked=''":"")."><label for='RequestXLargeSize'>Extra Large (320 x 240)</label></div>\n";
	echo "</div>\n";
	echo "<div id='wsSample' style='float: left; background-color: lightgrey; border: 2px solid black; width: 0; height: 0; display: none;'><center>Sample</center></div>\n";
	echo "<div style='clear: both;' class='submit'><input type='submit' value='Save Changes' /></div>\n";
	echo "</form>\n";

	echo "</div>\n";

	echo "<script type=\"text/javascript\">\n";
	echo "  var elements = document.getElementsByName('ws_hover_size');\n";
	echo "  for (var i = 0; i < elements.length; i++) {\n";
	echo "    if (elements[i].checked) {\n";
	echo "      elements[i].onchange();\n";
	echo "    }\n";
	echo "  }\n";
	echo "</script>\n";
}

function webshrinker_hook_post_links($content) {
	$settings = webshrinker_get_db_settings();
	$isHoverEnabled = ($settings['hover_enabled'] == 1) ? true : false;

	if (!$isHoverEnabled)
		return $content;

	$access_key = $settings['access_key'];
	$secret_key = $settings['secret_key'];
	$image_size = $settings['hover_size'];

	$selectors = $settings['hover_selector'];
	$selectors = explode(",", $selectors);

	// Add our class to links that already have a class
	$content = preg_replace('/(<a.*?\s*class\s*=\s*["|\'].*?)(["|\'].*?>)/i', '$1 webshrinker_post_hover $2', $content);
	// Add our class to links that don't already have a class
	$content = preg_replace('/(<a.*?)>(?<! class)/i', '$1 class="webshrinker_post_hover">', $content);

	$html = str_get_html($content);

	if (!$html)
		return $content;

	/* Add the data-ws attribute to the elements matching the CSS selectors so that the JavaScript can find them */
	foreach ($selectors as $selector) {
		foreach ($html->find($selector) as $node) {
			$target = base64_encode($node->href);

			$apiUrl = "https://api.webshrinker.com/";
			$apiRequest = "thumbnails/v2/{$target}/info?size={$image_size}&key={$access_key}";
			$apiRequest .= "&hash=" . md5(sprintf("%s:%s", $secret_key, $apiRequest));

			$node->{"data-ws-info"} = $apiUrl . $apiRequest;
		}
	}

	return $html;
}

add_action('wp_enqueue_scripts', 'webshrinker_init');
add_action('admin_menu', 'webshrinker_add_pages');

register_activation_hook(__FILE__, 'webshrinker_init_db');
register_uninstall_hook(__FILE__, 'webshrinker_uninstall_db');

add_filter('the_content', 'webshrinker_hook_post_links');
