<?php
/*
Plugin Name: Twitter Feed Translator
Description: Tweet translator for the plugin Twitter Widget Pro.
Author: Ulf Hedlund
Author URI: http://ulfhedlund.se/
Plugin URI: http://ulfhedlund.se/
Version: 1.0
License: Apache
*/

// activate plugin, create default settings
function fz_tft_activate() {
	delete_option('fz_tft');	// brute force
	$options = array( 'defaultlanguage' => 'sv', 'azureid' => '-enter your azure id here-', 'secretkey' => '- enter secret key here');
	update_option('fz_tft', $options);
}

// uninstall plugin, clean up settings
function fz_tft_uninstall() {
	delete_option('fz_tft');
}

// init settings api
function fz_tft_init() {
	register_setting( 'fz_tft_options', 'fz_tft', 'fz_tft_validate' );
}

// settings menu setup
function fz_tft_menu() {
	add_options_page('Settings', __('Twitter Feed Translator ','fz_tft'), 'manage_options', __FILE__, 'fz_tft_settings');
}
// validate
function fz_tft_validate( $str ) {

	return $str;	
}
// admin settings dialog
function fz_tft_settings() {
?>
<div class='wrap'><h2><?php echo __('Twitter Feed Translator Settings','fz_tft') ?></h2>

<form method='post' action='options.php'>
<?php 
settings_fields('fz_tft_options'); 
$options = get_option('fz_tft'); 
?>
<table class="form-table">
<tr valign='top'>
	<th scope="row"><?php echo __('Language of the<br/>twitter feed', 'fz_tft') ?></th>
	<td>
	<input type="text" size="2" name="fz_tft[defaultlanguage]" value="<?php echo $options['defaultlanguage']; ?>" />
	</td>
	<td>See <a href='http://msdn.microsoft.com/en-us/library/hh456380.aspx'>this page</a> for valid codes.
	</td>
</tr>
<tr valign='top'>
	<th scope="row"><?php echo __('Azure client ID', 'fz_tft') ?></th>
	<td>
	<input type="text" size="60" name="fz_tft[azureid]" value="<?php echo $options['azureid']; ?>" />
	</td>
	<td>1) Subscribe to the Microsoft Translator API on <a href='http://go.microsoft.com/?linkid=9782667' target='_blank'>Azure Marketplace</a>. Basic subscriptions, up to 2 million characters a month, are free. Translating more than 2 million characters per month requires a payment.
	</td>
</tr>
<tr valign='top'>
	<th scope="row"><?php echo __('Azure secret key', 'fz_tft') ?></th>
	<td>
	<input type="text" size="60" name="fz_tft[secretkey]" value="<?php echo $options['secretkey']; ?>" />
	</td>
	<td>2) Register your application with Azure DataMarket, visit <a href='https://datamarket.azure.com/developer/applications/' target='_blank'>https://datamarket.azure.com/developer/applications/</a> using the LiveID credentials from step 1, and click on “Register”. In the “Register your application” dialog box, you can define your own Client ID and Name. Set the redirect URI to <?php echo get_bloginfo( 'url' ); ?><br />&nbsp;<br />
		3) copy your Client ID and Secret Key from the registration page to the fields on the left side here.
	</td>
</tr>

</table>
<input type="submit" value="<?php echo __('Save changes', 'fz_tft') ?>" />
</form>
</div>
<?php
}

// this is called from a filter initiated in the plugin Twitter Widget Pro
function fz_tweet_translator($content = null)
{
	$options = get_option('fz_tft'); 

	// default is English
	$lang = "en";	
	// check for browser setting
	$locale = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
	if(strlen($locale) == 2)
		$lang = $locale;
	// if qTranslate is installed, use chosen language
	if (function_exists('qtrans_getLanguage')) {
		$lang = qtrans_getLanguage();
	}

	if($lang != $options['defaultlanguage']) {
		$translatorObj = new fz_BingTranslator();
		$content = $translatorObj->doTranslate($options['defaultlanguage'], $lang, $content, "fz".$options['defaultlanguage'].$lang.substr( $content,0,30 ), 60*60);
	}

	return $content;

}

class fz_BingTranslator {
    /*
     * Get the access token from Azure.
     *
     * @param string $grantType    Grant type.
     * @param string $scopeUrl     Application Scope URL.
     * @param string $clientID     Application client ID.
     * @param string $clientSecret Application client ID.
     * @param string $authUrl      Oauth Url.
     *
     * @return string.
     */
    function getTokens($grantType, $scopeUrl, $clientID, $clientSecret, $authUrl){
        try {
            $paramArr = array (
                 'grant_type'    => $grantType,
                 'scope'         => $scopeUrl,
                 'client_id'     => $clientID,
                 'client_secret' => $clientSecret
            );

        	$args = array(
			    'timeout' => 15,
			    'redirection' => 5,
			    'httpversion' => '1.0',
			    'user-agent' => 'WordPress/' . floatval(get_bloginfo('version')) . '; ' . get_bloginfo( 'url' ),
			    'blocking' => true,
			    'headers' => array(),
			    'cookies' => array(),
			    'body' => $paramArr,
			    'compress' => false,
			    'decompress' => true,
			    'sslverify' => false,
			    'stream' => false,
			    'filename' => null 
			);

			$response = wp_remote_post($authUrl, $args);
			if ( is_wp_error( $response ) || $response['response']['code'] != 200) {
				return null;
			} 
            //Decode the returned JSON string.
            $objResponse = json_decode($response['body']);
            if (! $objResponse || json_last_error()) {
                return null;
            }
            return $objResponse->access_token;
        } catch (Exception $e) {
            echo "Exception-".$e->getMessage();
        }
    }

    function doTranslate($fromLang, $toLang, $sourceText, $cacheKey, $cacheTime)
	{
	    /*
	     * Translate a string to another language
	     *
	     * @param string $fromLang 	   'sv', 'en', 'no' ...  
	     * @param string $toLang       'sv', 'en', 'no' ...  
	     * @param string $sourceText   string to translate
	     * @param string $cacheKey	   unique key to identify this string
	     * @param string $cacheTime    Time in seconds to cache the result, to avoid too many bing requests
	     *
	     * @return string.
	     */
		try {
			// first, check transient cache for a cached translation
			$content = get_transient($cacheKey);
			if($content !== false) {
				return $content;
			}				

			// nothing cached, send to the Azure translation API

			// To obtain client ID and secret key, see http://msdn.microsoft.com/en-us/library/hh454950.aspx

			$options = get_option('fz_tft'); 
		    // Azure client ID 
		    $clientID       = $options['azureid'];
		    // Azure secret key 
		    $clientSecret = $options['secretkey'];
		    // OAuth URL.
		    $authUrl      = "https://datamarket.accesscontrol.windows.net/v2/OAuth2-13/";
		    // Application scope URL
		    $scopeUrl     = "http://api.microsofttranslator.com";
		    // Application grant type
		    $grantType    = "client_credentials";

		    // get the access token
		    $accessToken  = $this->getTokens($grantType, $scopeUrl, $clientID, $clientSecret, $authUrl);
		    if(! $accessToken)
		    	return "Error Flynn said: ".$sourceText;

        	$args = array(
			    'timeout' => 15,
			    'redirection' => 5,
			    'httpversion' => '1.0',
			    'user-agent' => 'WordPress/' . floatval(get_bloginfo('version')) . '; ' . get_bloginfo( 'url' ),
			    'blocking' => true,
			    'headers' => array("Authorization" => "Bearer ".$accessToken, "Content-Type" => "text/xml"),
			    'cookies' => array(),
			    'body' => null,
			    'compress' => false,
			    'decompress' => true,
			    'sslverify' => false,
			    'stream' => false,
			    'filename' => null 
			);

		    $params = "text=".urlencode($sourceText)."&to=".$toLang."&from=".$fromLang;
    		$translateUrl = "http://api.microsofttranslator.com/v2/Http.svc/Translate?$params";

			$response = wp_remote_get($translateUrl, $args);
			if(is_wp_error($response)) 
				return "Error Flynn said: ".$sourceText;	// could handle errors better than this 
															// but we will try again on next request. 
															// (may cause a lot of API requests on a popular site ...)
	
			$content = wp_remote_retrieve_body($response);
			set_transient($cacheKey, $content, $cacheTime);		// cache for nn secs
	
			return $content;

		} catch (Exception $e) {
    		echo "Exception: " . $e->getMessage() . PHP_EOL;
		}	
	}
}

// tell wordpress that we are here
register_activation_hook(__FILE__, 'fz_tft_activate');
register_uninstall_hook(__FILE__, 'fz_tft_uninstall');
add_action('admin_init', 'fz_tft_init');
add_action('admin_menu', 'fz_tft_menu');

// add a filter for Twitter Widget Pro
add_filter('widget_twitter_content', 'fz_tweet_translator');
	