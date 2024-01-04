<?php
/**
 * Plugin Name: Xero Integration
 * Description: Integrates Xero OAuth for pre-populating HubSpot forms.
 * Version: 1.0
 * Author: Marjon Ramos
 * Author URI: https://www.upwork.com/freelancers/marjonramos
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
function xero_integration_add_options_page() {
    add_options_page(
        'Xero Integration Settings', // Page title
        'Xero Integration',          // Menu title
        'manage_options',            // Capability
        'xero-integration',          // Menu slug
        'xero_integration_settings_page' // Function to display the settings page
    );
}
add_action('admin_menu', 'xero_integration_add_options_page');

// Function to add a settings link to the plugin action links
function xero_integration_add_settings_link($links) {
    $settings_link = '<a href="options-general.php?page=xero-integration">' . __('Settings') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}

$plugin = plugin_basename(__FILE__);
add_filter("plugin_action_links_$plugin", 'xero_integration_add_settings_link');


function xero_integration_settings_page() {
    ?>
    <div class="wrap">
        <h1>Xero Integration Settings</h1>

        <form method="post" action="options.php">
            <?php
            settings_fields('xero-integration-settings-group');
            do_settings_sections('xero-integration-settings-group');
            ?>
            <table class="form-table">
               <!-- Client ID -->
                <tr valign="top">
                    <th scope="row">Client ID:</th>
                    <td>
                        <input type="text" name="xero_client_id" value="<?php echo esc_attr(get_option('xero_client_id')); ?>" />
                        <p class="description">Enter the Client ID provided by Xero. This is used to identify your application to the Xero API.</p>
                    </td>
                </tr>

                <!-- Client Secret -->
                <tr valign="top">
                    <th scope="row">Client Secret:</th>
                    <td>
                        <input type="text" name="xero_client_secret" value="<?php echo esc_attr(get_option('xero_client_secret')); ?>" />
                        <p class="description">Your application's Client Secret from Xero. It's important for the security of your application and should not be shared.</p>
                    </td>
                </tr>

                <!-- Redirect URI -->
                <tr valign="top">
                    <th scope="row">Redirect URI:</th>
                    <td>
                        <input type="text" name="xero_redirect_uri" value="<?php echo esc_attr(get_option('xero_redirect_uri')); ?>" />
                        <p class="description">The URI where users will be redirected after authenticating with Xero. This must match the URI configured in your Xero app settings.</p>
                    </td>
                </tr>

                <!-- Xero User Signup Page URL or Slug -->
                <tr valign="top">
                    <th scope="row">Xero User Signup Page URL:</th>
                    <td>
                        <input type="text" name="xero_user_signup_slug" value="<?php echo esc_attr(get_option('xero_user_signup_slug')); ?>" />
                        <p class="description">Enter the full URL of your Xero user signup page. This page is where users start the OAuth process.</p>
                    </td>
                </tr>

                <!-- Redirect URL after OAuth -->
                <tr valign="top">
                    <th scope="row">Redirect URL after OAuth:</th>
                    <td>
                        <input type="text" name="xero_oauth_redirect_url" value="<?php echo esc_attr(get_option('xero_oauth_redirect_url')); ?>" />
                        <p class="description">Specify the URL to which users should be redirected after completing the OAuth process with Xero. This typically includes a query string for passing user information.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

function xero_integration_register_settings() {
    register_setting('xero-integration-settings-group', 'xero_client_id');
    register_setting('xero-integration-settings-group', 'xero_client_secret');
    register_setting('xero-integration-settings-group', 'xero_redirect_uri');
    register_setting('xero-integration-settings-group', 'xero_user_signup_slug');
    register_setting('xero-integration-settings-group', 'xero_oauth_redirect_url');
}

add_action('admin_init', 'xero_integration_register_settings');

define('XERO_CLIENT_ID', get_option('xero_client_id'));
define('XERO_CLIENT_SECRET', get_option('xero_client_secret'));
define('XERO_REDIRECT_URI', get_option('xero_redirect_uri'));

// Action for triggering OAuth redirect on specific page
add_action('template_redirect', 'auto_trigger_xero_oauth');

// Action for handling OAuth callback
add_action('template_redirect', 'xero_oauth_callback');

function extract_slug_from_url($url) {
    // Parse the URL to get path parts
    $path = parse_url($url, PHP_URL_PATH);
    // Trim leading and trailing slashes and return the last part
    $path_trimmed = trim($path, '/');
    $path_parts = explode('/', $path_trimmed);
    return end($path_parts);
}

// Shortcode for displaying the form
add_shortcode('xero_hubspot_form', 'xero_hubspot_form_shortcode');

function auto_trigger_xero_oauth() {
    $signup_url = get_option('xero_user_signup_slug', 'default-signup_slug'); // Replace 'default-slug' with a default value if necessary
    $signup_slug = extract_slug_from_url($signup_url);
    if (is_page($signup_slug)) { // Replace with the actual slug or ID of your landing page
        $state = wp_generate_password(12, false); // Generate a unique state string
        // Store $state in user session or database to validate later
        $auth_url = "https://login.xero.com/identity/connect/authorize?response_type=code&client_id=" . XERO_CLIENT_ID . "&redirect_uri=" . urlencode(XERO_REDIRECT_URI) . "&scope=openid profile email&state=" . $state;
        wp_redirect($auth_url);
        exit;
    }
}

function xero_oauth_callback() {
    if (isset($_GET['code']) && isset($_GET['state'])) {
        // Verify the state parameter
        // Retrieve and validate the state from user session or database

        // Exchange the code for an access token
        $token_url = 'https://identity.xero.com/connect/token';
        $response = wp_remote_post($token_url, [
            'body' => [
                'grant_type' => 'authorization_code',
                'code' => $_GET['code'],
                'redirect_uri' => XERO_REDIRECT_URI,
                'client_id' => XERO_CLIENT_ID,
                'client_secret' => XERO_CLIENT_SECRET,
            ],
        ]);

        if (is_wp_error($response)) {
            // Handle error
            return;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body);
        
        $id_token = $data->id_token;
        $parts = explode(".", $id_token);
        $payload = $parts[1];
        $decoded_payload = base64_decode($payload);
        $user_details = json_decode($decoded_payload);

        // Redirect to the form page with user details
        $query = http_build_query([
            'email' => $user_details->email,
            'firstname' => $user_details->given_name,
            'lastname' => $user_details->family_name,
            'xero_user_id_' => $user_details->xero_userid
        ]);

        $redirect_url = get_option('xero_oauth_redirect_url', 'https://default-redirect.com/');
        wp_redirect($redirect_url . '?' . $query);
        exit;
        // Use $data->access_token and $data->id_token as needed
        // Redirect to a page with the HubSpot form or handle the data as needed
    }
}


