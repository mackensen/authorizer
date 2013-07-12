<?php
/*
Plugin Name: CAS Admission
Plugin URI: http://hawaii.edu/coe/dcdc/
Description: CAS Admission restricts access to students enrolled in university courses, using CAS for authentication and a whitelist of users with permission to access the site.
Version: 0.1
Author: Paul Ryan
Author URI: http://www.linkedin.com/in/paulrryan/
License: GPL2
*/

/*
Copyright 2013  Paul Ryan  (email: prar@hawaii.edu)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License, version 2, as 
published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

/*
Portions forked from Restricted Site Access plugin: http://wordpress.org/plugins/restricted-site-access/
Portions forked from wpCAS plugin:  http://wordpress.org/extend/plugins/cas-authentication/
*/


// Add phpCAS library if it's not included.
// @see https://wiki.jasig.org/display/CASC/phpCAS+installation+guide
if ( ! defined( 'PHPCAS_VERSION' ) ) {
	include_once dirname(__FILE__) . '/assets/inc/CAS-1.3.2/CAS.php';
}


if ( !class_exists( 'WP_Plugin_CAS_Admission' ) ) {
	/**
	 * Define class for plugin: CAS Admission.
	 *
	 * @category Authentication
	 * @package  CAS_Admission
	 * @author   Paul Ryan <prar@hawaii.edu>
	 * @license  http://www.gnu.org/licenses/gpl-2.0.html GPL2
	 * @link     http://hawaii.edu/coe/dcdc/wordpress/cas_admission/doc/
	 */
	class WP_Plugin_CAS_Admission {
		
		/**
		 * Constructor.
		 */
		public function __construct() {
			// Register filters.

			// Custom wp authentication routine using CAS
			add_filter( 'authenticate', array( $this, 'cas_authenticate' ), 1, 3 );

			// Removing this bypasses Wordpress authentication (so if CAS auth fails,
			// no one can log in); with it enabled, it will run if CAS auth fails.
			//remove_filter('authenticate', 'wp_authenticate_username_password', 20, 3);

			// Create settings link on Plugins page
			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_settings_link' ) );

			// Modify login page to help users use CAS to log in
			if ( strpos( $_SERVER['REQUEST_URI'], 'wp-login.php' ) !== false ) {
				add_filter( 'lostpassword_url', array( $this, 'custom_lostpassword_url' ) );
				add_filter( 'gettext', array( $this, 'custom_login_form_labels' ), 20, 3 );
			}

			// If we have a custom login error, add the filter to show it.
			$error = get_option( 'cas_settings_misc_login_error' );
			if ( $error && strlen( $error ) > 0 ) {
				add_filter( 'login_errors', array( $this, 'show_misc_login_error' ) );
			}

			// Register actions.

			// Create menu item in Settings
			add_action( 'admin_menu', array( $this, 'add_plugin_page' ) );

			// Create options page
			add_action( 'admin_init', array( $this, 'page_init' ) );

			// Enqueue javascript and css only on the plugin's options page and the dashboard (for the widget)
			add_action( 'load-settings_page_cas_admission', array( $this, 'load_options_page' ) );
			add_action( 'admin_head-index.php', array( $this, 'load_options_page' ) );

			// Add custom css and js to wp-login.php
			add_action( 'login_head', array( $this, 'load_login_css_and_js' ) );

			// Verify current user has access to page they are visiting
			add_action( 'parse_request', array( $this, 'restrict_access' ), 1 );

			// ajax course verification check
			add_action( 'wp_ajax_cas_course_check', array( $this, 'ajax_cas_course_check' ) );

			// ajax save options from dashboard widget
			add_action( 'wp_ajax_save_admission_dashboard_widget', array( $this, 'ajax_save_admission_dashboard_widget' ) );

			// Add dashboard widget so instructors can add/edit sakai courses with access.
			// Hint: For Multisite Network Admin Dashboard use wp_network_dashboard_setup instead of wp_dashboard_setup.
			add_action( 'wp_dashboard_setup', array( $this, 'add_dashboard_widgets' ) );

			// If we have a custom admin message, add the action to show it.
			$notice = get_option( 'cas_settings_misc_admin_notice' );
			if ( $notice && strlen( $notice ) > 0 ) {
				add_action( 'admin_notices', array( $this, 'show_misc_admin_notice' ) );
			}

		} // END __construct()


		/**
		 * Plugin activation hook.
		 * Will also activate the plugin for all sites/blogs if this is a "Network enable."
		 *
		 * @return void
		 */
		public function activate() {
			global $wpdb;

			// If we're in a multisite environment, run the plugin activation for each site when network enabling
			if ( function_exists( 'is_multisite' ) && is_multisite() ) {
				if ( isset($_GET['networkwide'] ) && ( $_GET['networkwide'] == 1 ) ) {
					$old_blog = $wpdb->blogid;
					// Get all blog ids
					$blogids = $wpdb->get_col( $wpdb->prepare( "SELECT blog_id FROM $wpdb->blogs" ) );
					foreach ( $blogids as $blog_id ) {
						switch_to_blog( $blog_id );
						self::_single_blog_activate();
					}
					switch_to_blog( $old_blog );
					return;
				}
			}

			// Activet the plugin for the current site
			self::_single_blog_activate();
		}

		/**
		 * Plugin activation.
		 *
		 * @return void
		 */
		private static function _single_blog_activate() {
			global $wp_roles;

			// Set meaningful defaults (but if values already exist in db, use those).
			$cas_settings = get_option( 'cas_settings' );
			if ( $cas_settings === FALSE ) {
				$cas_settings = array();
			}
			if ( !array_key_exists( 'ldap_host', $cas_settings ) ) {
				$cas_settings['ldap_host'] = '';
			}
			if ( !array_key_exists( 'ldap_search_base', $cas_settings ) ) {
				$cas_settings['ldap_search_base'] = '';
			}
			if ( !array_key_exists( 'ldap_user', $cas_settings ) ) {
				$cas_settings['ldap_user'] = '';
			}
			if ( !array_key_exists( 'ldap_password', $cas_settings ) ) {
				$cas_settings['ldap_password'] = '';
			}
			if ( !array_key_exists( 'ldap_type', $cas_settings ) ) {
				$cas_settings['ldap_type'] = 'openldap';
			}
			if ( !array_key_exists( 'ldap_tls', $cas_settings ) ) {
				$cas_settings['ldap_tls'] = '1';
			}
			if ( !array_key_exists( 'sakai_base_url', $cas_settings ) ) {
				$cas_settings['sakai_base_url'] = '';
			}
			if ( !array_key_exists( 'access_restriction', $cas_settings ) ) {
				$cas_settings['access_restriction'] = 'everyone';
			}
			if ( !array_key_exists( 'access_courses', $cas_settings ) ) {
				$cas_settings['access_courses'] = '';
			}
			if ( !array_key_exists( 'access_redirect', $cas_settings ) ) {
				$cas_settings['access_redirect'] = 'login';
			}
			if ( !array_key_exists( 'access_redirect_to_url', $cas_settings ) ) {
				$cas_settings['access_redirect_to_url'] = '';
			}
			if ( !array_key_exists( 'access_redirect_to_message', $cas_settings ) ) {
				$cas_settings['access_redirect_to_message'] = '<p>Access to this site is restricted.</p>';
			}
			if ( !array_key_exists( 'access_redirect_to_page', $cas_settings ) ) {
				$cas_settings['access_redirect_to_page'] = '';
			}
			if ( !array_key_exists( 'misc_lostpassword_url', $cas_settings ) ) {
				$cas_settings['misc_lostpassword_url'] = '';
			}
			if ( !array_key_exists( 'access_default_role', $cas_settings ) ) {
				// Set default role to 'student' if that role exists, 'subscriber' otherwise.
				$all_roles = $wp_roles->roles;
				$editable_roles = apply_filters( 'editable_roles', $all_roles );
				if ( array_key_exists( 'student', $editable_roles ) ) {
					$cas_settings['access_default_role'] = 'student';
				} else if ( array_key_exists( 'subscriber', $editable_roles ) ) {
					$cas_settings['access_default_role'] = 'subscriber';
				} else {
					$cas_settings['access_default_role'] = 'subscriber';
				}
			}
			update_option( 'cas_settings', $cas_settings );
		} // END activate()


		/**
		 * Plugin deactivation.
		 *
		 * @return void
		 */
		public function deactivate() {
			// Do nothing.
		} // END deactivate()


		/**
		 * Plugin uninstallation.
		 *
		 * @return void
		 */
		public function uninstall() {
			// Delete options in database.
			if ( get_option( 'cas_settings' ) ) {
				delete_option( 'cas_settings' );
			}
			if ( get_option( 'cas_settings_misc_admin_notice' ) ) {
				delete_option( 'cas_settings_misc_admin_notice' );
			}

		} // END deactivate()


		/**
		 ****************************
		 * Custom filters and actions
		 ****************************
		 */

		/**
		 * Overwrite the URL for the lost password link on the login form.
		 * If we're authenticating against LDAP, standard WordPress password resets
		 * won't work.
		 */
		function custom_lostpassword_url( $lostpassword_url ) {
			$cas_settings = get_option( 'cas_settings' );
			if (
				array_key_exists( 'misc_lostpassword_url', $cas_settings ) &&
				filter_var( $cas_settings['misc_lostpassword_url'], FILTER_VALIDATE_URL ) &&
				array_key_exists( 'access_restriction', $cas_settings ) &&
				$cas_settings['access_restriction'] !== 'everyone' &&
				$cas_settings['access_restriction'] !== 'user'
			) {
				$lostpassword_url = $cas_settings['misc_lostpassword_url'];
			}
			return $lostpassword_url;
		}

		/**
		 * Overwrite the username and password labels on the login form.
		 */
		function custom_login_form_labels( $translated_text, $text, $domain ) {
			$cas_settings = get_option( 'cas_settings' );

			if ( $translated_text === 'Username' ) {
				$translated_text = 'Username';
			}

			if ( $translated_text === 'Password' ) {
				$translated_text = 'Password';
			}

			return $translated_text;
		}

		/**
		 * Show custom admin notice.
		 * Filter: admin_notice
		 */
		function show_misc_admin_notice() {
			$notice = get_option( 'cas_settings_misc_admin_notice' );
			delete_option( 'cas_settings_misc_admin_notice' );

			if ( $notice && strlen( $notice ) > 0 ) {
				?>
				<div class="error">
					<p><?php _e( $notice ); ?></p>
				</div>
				<?php
			}
		}

		/**
		 * Add custom error message to login screen.
		 * Filter: login_errors
		 */
		function show_misc_login_error( $errors ) {
			$error = get_option( 'cas_settings_misc_login_error' );
			delete_option( 'cas_settings_misc_login_error' );

			//$errors .= '    ' . $error . "<br />\n";
			$errors = '    ' . $error . "<br />\n";
			return $errors;
		}

		/**
		 ****************************
		 * LDAP Authentication
		 ****************************
		 */


		/**
		 * Authenticate using LDAP credentials.
		 *
		 * @param WP_User $user     user to authenticate
		 * @param string  $username optional username to authenticate.
		 * @param string  $password optional password to authenticate.
		 *
		 * @return WP_User or WP_Error
		 */
		public function cas_authenticate( $user, $username, $password ) {
			// Pass through if already authenticated.
			if ( is_a( $user, 'WP_User' ) ) {
				return $user;
			}

			// Custom UH code: remove @hawaii.edu if it exists in the username
			$username = str_replace( '@hawaii.edu', '', $username );

			// Fail with error message if username or password is blank.
			if ( empty( $username ) ) {
				return new WP_Error( 'empty_username', 'Username cannot be blank.' );
			}
			if ( empty( $password ) ) {
				return new WP_Error( 'empty_password', 'You must provide a password.' );
			}

			// Authenticate against LDAP using options provided in plugin settings.
			$result = false;
			$ldap_user = array(
				'dn' => '0',
				'first' => 'nobody',
				'last' => '',
				'email' => '',
			);

			$cas_settings = get_option( 'cas_settings' );

			// If we're restricting access to only WP users, don't check against ldap;
			// Instead, pass through to default WP authentication.
			if ( $cas_settings['access_restriction'] === 'user' ) {
				return new WP_Error( 'no_ldap', 'Only authenticate against local WP install (not LDAP).' );
			}

			switch ( $cas_settings['ldap_type'] ) {
			case 'custom_uh': // University of Hawai'i
				$ldap = ldap_connect( $cas_settings['ldap_host'] );
				ldap_set_option( $ldap, LDAP_OPT_PROTOCOL_VERSION, 3 );
				if ( $cas_settings['ldap_tls'] == 1 ) {
					ldap_start_tls( $ldap );
				}
				$result = ldap_bind( $ldap, $cas_settings['ldap_user'], $this->decrypt( base64_decode( $cas_settings['ldap_password'] ) ) );
				if ( !$result ) {
					return new WP_Error( 'ldap_error', 'Could not authenticate.' );
				}
				// UH has an odd system; people cn's are their uhuuid's (8 digit
				// numbers), not their uids (unique email address usernames).
				// So here we need to do an extra search by uid to get a uhuuid,
				// and then attempt to authenticate with uhuuid and password.
				$ldap_search = ldap_search(
					$ldap,
					$cas_settings['ldap_search_base'],
					"(uid=$username)",
					array(
						'givenName',
						'sn',
						'mail',
						'uhUuid',
					)
				);
				$ldap_entries = ldap_get_entries( $ldap, $ldap_search );

				// If we didn't find any users in ldap, exit with error (rely on default wordpress authentication)
				if ( $ldap_entries['count'] < 1 ) {
					return new WP_Error( 'no_ldap', 'No LDAP user found.' );
				}

				for ( $i = 0; $i < $ldap_entries['count']; $i++ ) {
					$ldap_user['dn'] = $ldap_entries[$i]['dn'];
					$ldap_user['first'] = $ldap_entries[$i]['givenname'][0];
					$ldap_user['last'] = $ldap_entries[$i]['sn'][0];
					$ldap_user['email'] = $ldap_entries[$i]['mail'][0];
				}

				$result = ldap_bind( $ldap, $ldap_user['dn'], $password );
				if ( !$result ) {
					// We have a real ldap user, but an invalid password, so we shouldn't
					// pass through to wp authentication after failing ldap. Instead,
					// remove the WordPress authenticate function, and return an error.
					remove_filter( 'authenticate', 'wp_authenticate_username_password', 20, 3 );
					return new WP_Error( 'ldap_error', "<strong>ERROR</strong>: The password you entered for the username <strong>$username</strong> is incorrect." );
				}

				break;
			case 'ad': // Active Directory
				/**
				@todo: incomplete authentication (via active directory)
				*/
				return new WP_Error( 'adldap_error', 'Incomplete authentication routine.' );
				// try {
				// 	$adldap = new adLDAP(
				// 		array(
				// 			'base_dn' => $cas_settings['ldap_search_base'],
				// 			'domain_controllers' => array($cas_settings['ldap_host']),
				// 			'admin_username' => $cas_settings['ldap_user'],
				// 			'account_suffix' => '', // suffix should already be included in the admin_username
				// 			'admin_password' => $this->decrypt( base64_decode( $cas_settings['ldap_password'] ) ),
				// 			'use_tls' => $cas_settings['ldap_tls'] == 1,
				// 		)
				// 	);
				// 	$result = $adldap->authenticate( $username, $password );
				// 	if ( !$result ) {
				// 		//do_action( 'wp_login_failed', $username );
				// 		return new WP_Error( 'adldap_error', 'Could not authenticate against Active Directory.' );
				// 	}
				// } catch (adLDAPException $e) {;
				// 	//do_action( 'wp_login_failed', $username );
				// 	return new WP_Error( 'adldap_error', $e );
				// }
				break;
			case 'openldap': // OpenLDAP
				/**
				@todo: incomplete authentication (via openldap)
				*/
				return new WP_Error( 'openldap_error', 'Incomplete authentication routine.' );
				// $ldap = ldap_connect( $cas_settings['ldap_host'] );
				// ldap_set_option( $ldap, LDAP_OPT_PROTOCOL_VERSION, 3 );
				// if ( $cas_settings['ldap_tls'] == 1 ) {
				// 	ldap_start_tls( $ldap );
				// }
				// $result = ldap_bind( $ldap, $cas_settings['ldap_user'], $cas_settings['ldap_password'] );
				break;
			default:
				//do_action( 'wp_login_failed', $username );
				return new WP_Error( 'missing_ldap_type', 'An administrator must choose an LDAP type to authenticate against an LDAP server (Error: Missing ldap_type specification).' );
				break;
			}

			// Successfully authenticated now, so create/update the WordPress user.
			$user = get_user_by( 'login', $username );
			if ( ! ( $user && strcasecmp( $user->user_login, $username ) ) ) {
				// User doesn't exist in WordPress, so add it.
				$result = wp_insert_user(
					array(
						'user_login' => $username,
						'user_pass' => wp_generate_password(), // random password
						'first_name' => $ldap_user['first'],
						'last_name' => $ldap_user['last'],
						'user_email' => $ldap_user['email'],
						'user_registered' => date( 'Y-m-d H:i:s' ),
						'role' => $cas_settings['access_default_role'],
					)
				);

				// Check to see if there's an error because another user has the ldap
				// user's email. If so, log user in as that WordPress user.
				if ( is_wp_error( $result ) && array_key_exists( 'existing_user_email', $result->errors ) ) {
					$result = get_user_by( 'email', $ldap_user['email'] );
				}

				// Check to see if there's an error because the user exists, but
				// isn't added to this site (can occur in multisite installs).
				if ( is_wp_error( $result ) && array_key_exists( 'existing_user_login', $result->errors ) ) {
					global $current_blog;
					$result = add_user_to_blog( $current_blog->blog_id, $user->ID, $cas_settings['access_default_role'] );
					if ( !is_wp_error( $result ) ) {
						$result = $user->ID;
					}
				}

				// Fail with message if error.
				if ( is_wp_error( $result ) ) {
					return $result;
				}

				// Authenticate as new user (or as old user with same email address as ldap)
				$user = new WP_User( $result );
			}

			// Reset cached access so plugin checks against sakai to make sure this newly-logged in user still has access (if restricting access by sakai course)
			update_user_meta( $user->ID, 'has_access', false );

			// Make sure (if we're restricting access by courses) that the current user is enrolled in an allowed course
			$logged_in_but_no_access = (
				$cas_settings['access_restriction'] == 'course' &&
				! $this->is_current_user_enrolled( $user->ID )
			);
			if ( $logged_in_but_no_access ) {
				$error = 'Sorry ' . $username . ', it seems you don\'t have access to ' . get_bloginfo( 'name' ) . '. If this is a mistake, please contact your instructor and have them add you to their Sakai/Laulima course.';
				update_option( 'cas_settings_misc_login_error', $error );
				wp_logout();
				wp_redirect( wp_login_url(), 302 );
				exit;
			}

			return $user;
		}


		/**
		 ****************************
		 * Access Restriction
		 ****************************
		 */


		/**
		 * Restrict access to WordPress site based on settings (everyone, university, course).
		 * Hook: parse_request http://codex.wordpress.org/Plugin_API/Action_Reference/parse_request
		 *
		 * @param array $wp WordPress object.
		 *
		 * @return void
		 */
		public function restrict_access( $wp ) {
			remove_action( 'parse_request', array( $this, 'restrict_access' ), 1 );	// only need it the first time

			$cas_settings = get_option( 'cas_settings' );

			$has_access = (
				( defined( 'WP_INSTALLING' ) && isset( $_GET['key'] ) ) || // Always allow access if WordPress is installing
				( is_admin() ) || // Always allow access to admins
				( $cas_settings['access_restriction'] == 'everyone' ) || // Allow access if option is set to 'everyone'
				( $cas_settings['access_restriction'] == 'university' && $this->is_user_logged_in_and_blog_user() ) || // Allow access to logged in users if option is set to 'university' community
				( $cas_settings['access_restriction'] == 'user' && $this->is_user_logged_in_and_blog_user() ) || // Allow access to logged in users if option is set to WP users (note: when this is set, don't allow ldap log in elsewhere)
				( $cas_settings['access_restriction'] == 'course' && get_user_meta( get_current_user_id(), 'has_access', true ) ) || // Allow access to users enrolled in sakai course if option is set to 'course' members only (check cached result first)
				( $cas_settings['access_restriction'] == 'course' && $this->is_current_user_enrolled() ) // Allow access to users enrolled in sakai course if option is set to 'course' members only (check against sakai if no cached value is present)
			);
			$is_restricted = !$has_access;

			// Fringe case: User successfully logged in, but they don't have access
			// to an allowed course. Flag these users, and redirect them to their
			// profile page with a message (so we don't get into a redirect loop on
			// the wp-login.php page).
			$logged_in_but_no_access = false;
			if ( $this->is_user_logged_in_and_blog_user() && !$has_access && $cas_settings['access_restriction'] == 'course' ) {
				$logged_in_but_no_access = true;
			}

			/**
			 * Developers can use the `cas_admission_has_access` filter
			 * to override restricted access on certain pages. Note that the
			 * restriction checks happens before WordPress executes any queries, so
			 * use the global `$wp` variable to investigate what the visitor is
			 * trying to load.
			 *
			 * For example, to unblock an RSS feed, place the following PHP code in
			 * the theme's functions.php file or in a simple plug-in:
			 *
			 *   function my_rsa_feed_access_override( $has_access ) {
			 *     global $wp;
			 *     // check query variables to see if this is the feed
			 *     if ( ! empty( $wp->query_vars['feed'] ) )
			 *       $has_access = true;
			 *     return $has_access;
			 *   }
			 *   add_filter( 'cas_admission_has_access', 'my_rsa_feed_access_override' );
			 */
			if ( apply_filters( 'cas_admission_has_access', $has_access, $wp ) === true ) {
				// We've determined that the current user has access, so simply return to grant access.
				return;
			}

			// We've determined that the current user doesn't have access, so we deal with them now.

			if ( $logged_in_but_no_access ) {
				$error = 'Sorry, it seems you don\'t have access to ' . get_bloginfo( 'name' ) . '. If this is a mistake, please contact your instructor and have them add you to their Sakai/Laulima course.';
				update_option( 'cas_settings_misc_login_error', $error );
				wp_logout();
				wp_redirect( wp_login_url(), 302 );
				exit;
			}

			switch ( $cas_settings['access_redirect'] ) :
			case 'url':
				wp_redirect( $cas_settings['access_redirect_to_url'], 302 );
				exit;
			case 'message':
				wp_die( $cas_settings['access_redirect_to_message'], get_bloginfo( 'name' ) . ' - Site Access Restricted' );
				break;
			case 'page':
				$page_id = get_post_field( 'ID', $cas_settings['access_redirect_to_page'] );
				if ( is_wp_error( $page_id ) ) {
					wp_die( '<p>Access to this site is restricted.</p>', get_bloginfo( 'name' ) . ' - Site Access Restricted' );
				}
				unset( $wp->query_vars );
				$wp->query_vars['page_id'] = $page_id;
				return;
			case 'login':
			default:
				$current_path = empty( $_SERVER['REQUEST_URI'] ) ? home_url() : $_SERVER['REQUEST_URI'];
				wp_redirect( wp_login_url( $current_path ), 302 );
				exit;
			endswitch;

			// Sanity check: we should never get here
			wp_die( '<p>Access denied.</p>', 'Site Access Restricted' );
		}

		/**
		 * Determine if current user is enrolled in one of the allowed sakai courses.
		 *
		 * @returns BOOL true if the currently logged in user is enrolled in one of the sakai courses listed in the plugin options.
		 */
		function is_current_user_enrolled() {
			$cas_settings = get_option( 'cas_settings' );

			// Sanity check: only evaluate if access restriction is set to 'course' (not 'everyone' or 'university')
			if ( $cas_settings['access_restriction'] == 'everyone' || $cas_settings['access_restriction'] == 'university' ) {
				return true;
			}

			$current_user = wp_get_current_user();
			$has_access = false;

			// See if the current user is in the whitelist of users with access
			foreach ( $cas_settings['access_users_enrolled'] as $enrolled_user ) {
				if ( $enrolled_user['username'] === current_user->user_login ) {
					$has_access = true;
					break;
				}
			}

			// Store the result in user meta so we don't have to keep checking on every page load
			update_user_meta( $current_user, 'has_access', $has_access );

			return $has_access;
		}



		/**
		 ****************************
		 * Options page
		 ****************************
		 */


		/**
		 * Add a link to this plugin's settings page from the WordPress Plugins page.
		 * Called from "plugin_action_links" filter in __construct() above.
		 *
		 * @param array $links array of links in the admin sidebar
		 *
		 * @return array of links to show in the admin sidebar.
		 */
		public function plugin_settings_link( $links ) {
			$settings_link = '<a href="options-general.php?page=cas_admission">Settings</a>';
			array_unshift( $links, $settings_link );
			return $links;
		} // END plugin_settings_link()


		/**
		 * Create the options page under Dashboard > Settings
		 * Run on action hook: admin_menu
		 */
		public function add_plugin_page() {
			// @see http://codex.wordpress.org/Function_Reference/add_options_page
			add_options_page(
				'CAS Admission', // Page title
				'CAS Admission', // Menu title
				'manage_options', // Capability
				'cas_admission', // Menu slug
				array( $this, 'create_admin_page' ) // function
			);
		}


		/**
		 * Output the HTML for the options page
		 */
		public function create_admin_page() {
			?>
			<div class="wrap">
				<?php screen_icon(); ?>
				<h2>CAS Admission Settings</h2>
				<form method="post" action="options.php" autocomplete="off">
					<?php
						// This prints out all hidden settings fields
						// @see http://codex.wordpress.org/Function_Reference/settings_fields
						settings_fields( 'cas_settings_group' );
						// This prints out all the sections
						// @see http://codex.wordpress.org/Function_Reference/do_settings_sections
						do_settings_sections( 'cas_admission' );
					?>
					<?php submit_button(); ?>
				</form>
			</div>
			<?php
		}


		/**
		 * Load external resources on this plugin's options page.
		 * Run on action hook: load-settings_page_cas_admission
		 */
		public function load_options_page() {
			wp_enqueue_script(
				'cas_admission',
				plugins_url( 'assets/js/cas_admission.js', __FILE__ ),
				array( 'jquery-effects-shake' ), '5.0', true
			);

			wp_register_style( 'cas_admission-css', plugins_url( 'assets/css/cas_admission.css', __FILE__ ) );
			wp_enqueue_style( 'cas_admission-css' );

			add_action( 'admin_notices', array( $this, 'admin_notices' ) ); // Add any notices to the top of the options page.
			add_action( 'admin_head', array( $this, 'admin_head' ) ); // Add help documentation to the options page.
		}


		/**
		 * Load external resources on the wp-login.php page.
		 * Run on action hook: login_head
		 */
		function load_login_css_and_js() {
			$cas_settings = get_option( 'cas_settings' );

			if ( $cas_settings['ldap_type'] === 'custom_uh' ):
				?>
				<link rel="stylesheet" type="text/css" href="<?php print plugins_url( 'assets/css/cas_admission-login.css', __FILE__ ); ?>" />
				<script type="text/javascript" src="<?php print plugins_url( 'assets/js/cas_admission-login.js', __FILE__ ); ?>"></script>
				<?php
			endif;
		}


		/**
		 * Add notices to the top of the options page.
		 * Run on action hook chain: load-settings_page_cas_admission > admin_notices
		 @todo: add warning messages.
		 */
		public function admin_notices() {
			// Check for invalid settings combinations and show a warning message, e.g.:
			// if (sakai base url inaccessible) {
			//   print "<div class='updated settings-error'><p>Can't reach Sakai.</p></div>";
			// }
		}


		/**
		 * Add help documentation to the options page.
		 * Run on action hook chain: load-settings_page_cas_admission > admin_head
		 @todo: add documentation.
		 */
		public function admin_head() {
			$screen = get_current_screen();
			
			// Add help tab for LDAP Settings
			$help_cas_settings_ldap_content = '
				<p><strong>LDAP Host</strong>: Enter the URL of the LDAP server you authenticate against.</p>
				<p><strong>LDAP Search Base</strong>: Enter the LDAP string that represents the search base, e.g., ou=people,dc=yourcompany,dc=com</p>
				<p><strong>LDAP Directory User</strong>: Enter the name of the LDAP user that has permissions to browse the directory.</p>
				<p><strong>LDAP Directory User Password</strong>: Enter the password for the LDAP user that has permission to browse the directory.</p>
				<p><strong>LDAP Installation type</strong>: Select whether your LDAP server is running an Active Directory-compatible LDAP instance, or an OpenLDAP-compatible instance.</p>
				<p><strong>Secure Connection (TLS)</strong>: Select whether all communication with the LDAP server should be performed over a TLS-secured connection.</p>
			';

			$screen->add_help_tab(
				array(
					'id' => 'help_cas_settings_ldap',
					'title' => 'LDAP Settings',
					'content' => $help_cas_settings_ldap_content,
				)
			);

			// Add help tab for Sakai Settings

			// Add help tab for Access Settings      
		}


		/**
		 * Validate Sakai Site ID entry on demand (AJAX)
		 */
		public function ajax_cas_course_check() {
			if ( empty( $_POST['sakai_site_id'] ) || empty( $_POST['sakai_base_url'] ) ) {
				die('&nbsp;');
			}

			$request_url = trailingslashit( $_POST['sakai_base_url'] ) . 'site/' . $_POST['sakai_site_id'] . '.json';
			$sakai_session_id = get_user_meta( get_current_user_id(), 'sakai_session_id', true );
			$course_details = $this->call_api(
				'get',
				$request_url,
				array(
					'sakai.session' => $sakai_session_id,
				)
			);
			if ( isset( $course_details ) ) {
				if ( strpos( 'HTTP Status 403', $course_details ) !== false ) {
					// couldn't get sakai info because not logged in
					die( '[Unknown course]' );
				} else {
					$course_details = json_decode( $course_details );
					if ( isset( $course_details ) && property_exists( $course_details, 'entityTitle' ) ) {
						die( $course_details->entityTitle ); // success
					} else {
						die( '[Unknown course]' ); // success
					}
				}
			} else {
				die( '1' );
			}
		}


		/**
		 * Create sections and options
		 * Run on action hook: admin_init
		 */
		public function page_init() {
			// Create one setting that holds all the options (array)
			// @see http://codex.wordpress.org/Function_Reference/register_setting
			register_setting(
				'cas_settings_group', // Option group
				'cas_settings', // Option name
				array( $this, 'sanitize_cas_settings' ) // Sanitize callback
			);

			// @see http://codex.wordpress.org/Function_Reference/add_settings_section
			add_settings_section(
				'cas_settings_access', // HTML element ID
				'Access Settings', // HTML element Title
				array( $this, 'print_section_info_access' ), // Callback (echos section content)
				'cas_admission' // Page this section is shown on (slug)
			);

			// @see http://codex.wordpress.org/Function_Reference/add_settings_field
			add_settings_field(
				'cas_settings_access_default_role', // HTML element ID
				'Default role for new CAS users', // HTML element Title
				array( $this, 'print_select_cas_access_default_role' ), // Callback (echos form element)
				'cas_admission', // Page this setting is shown on (slug)
				'cas_settings_access' // Section this setting is shown on
			);
			add_settings_field(
				'cas_settings_access_restriction', // HTML element ID
				'Which people can access the site?', // HTML element Title
				array( $this, 'print_radio_cas_access_restriction' ), // Callback (echos form element)
				'cas_admission', // Page this setting is shown on (slug)
				'cas_settings_access' // Section this setting is shown on
			);
/**
@todo: remove refs to access_courses var and add refs to users_pending/enrolled/blocked
*/
			// add_settings_field(
			// 	'cas_settings_access_courses', // HTML element ID
			// 	'Course Site IDs with access (one per line)', // HTML element Title
			// 	array( $this, 'print_combo_cas_access_courses' ), // Callback (echos form element)
			// 	'cas_admission', // Page this setting is shown on (slug)
			// 	'cas_settings_access' // Section this setting is shown on
			// );
			add_settings_field(
				'cas_settings_access_users_pending', // HTML element ID
				'Pending CAS Users', // HTML element Title
				array( $this, 'print_select_cas_access_users_pending' ), // Callback (echos form element)
				'cas_admission', // Page this setting is shown on (slug)
				'cas_settings_access' // Section this setting is shown on
			);
			add_settings_field(
				'cas_settings_access_users_enrolled', // HTML element ID
				'Approved CAS Users', // HTML element Title
				array( $this, 'print_select_cas_access_users_enrolled' ), // Callback (echos form element)
				'cas_admission', // Page this setting is shown on (slug)
				'cas_settings_access' // Section this setting is shown on
			);
			add_settings_field(
				'cas_settings_access_users_blocked', // HTML element ID
				'Blocked CAS Users', // HTML element Title
				array( $this, 'print_select_cas_access_users_blocked' ), // Callback (echos form element)
				'cas_admission', // Page this setting is shown on (slug)
				'cas_settings_access' // Section this setting is shown on
			);
/**
END TODO
*/
			add_settings_field(
				'cas_settings_access_redirect', // HTML element ID
				'What happens to people without access?', // HTML element Title
				array( $this, 'print_radio_cas_access_redirect' ), // Callback (echos form element)
				'cas_admission', // Page this setting is shown on (slug)
				'cas_settings_access' // Section this setting is shown on
			);
			add_settings_field(
				'cas_settings_access_redirect_to_url', // HTML element ID
				'Redirect to URL', // HTML element Title
				array( $this, 'print_text_cas_access_redirect_to_url' ), // Callback (echos form element)
				'cas_admission', // Page this setting is shown on (slug)
				'cas_settings_access' // Section this setting is shown on
			);
			add_settings_field(
				'cas_settings_access_redirect_to_page', // HTML element ID
				'Redirect to restricted notice page', // HTML element Title
				array( $this, 'print_select_cas_access_redirect_to_page' ), // Callback (echos form element)
				'cas_admission', // Page this setting is shown on (slug)
				'cas_settings_access' // Section this setting is shown on
			);
			add_settings_field(
				'cas_settings_access_redirect_to_message', // HTML element ID
				'Restriction message', // HTML element Title
				array( $this, 'print_wysiwyg_cas_access_redirect_to_message' ), // Callback (echos form element)
				'cas_admission', // Page this setting is shown on (slug)
				'cas_settings_access' // Section this setting is shown on
			);

			// @see http://codex.wordpress.org/Function_Reference/add_settings_section
			add_settings_section(
				'cas_settings_cas', // HTML element ID
				'CAS Settings', // HTML element Title
				array( $this, 'print_section_info_cas' ), // Callback (echos section content)
				'cas_admission' // Page this section is shown on (slug)
			);

			// @see http://codex.wordpress.org/Function_Reference/add_settings_field
			add_settings_field(
				'cas_settings_cas_host', // HTML element ID
				'CAS server hostname', // HTML element Title
				array( $this, 'print_text_cas_host' ), // Callback (echos form element)
				'cas_admission', // Page this setting is shown on (slug)
				'cas_settings_cas' // Section this setting is shown on
			);
			add_settings_field(
				'cas_settings_cas_port', // HTML element ID
				'CAS server port', // HTML element Title
				array( $this, 'print_text_cas_port' ), // Callback (echos form element)
				'cas_admission', // Page this setting is shown on (slug)
				'cas_settings_cas' // Section this setting is shown on
			);
			add_settings_field(
				'cas_settings_cas_path', // HTML element ID
				'CAS server path', // HTML element Title
				array( $this, 'print_text_cas_path' ), // Callback (echos form element)
				'cas_admission', // Page this setting is shown on (slug)
				'cas_settings_cas' // Section this setting is shown on
			);

			// @see http://codex.wordpress.org/Function_Reference/add_settings_section
			add_settings_section(
				'cas_settings_misc', // HTML element ID
				'Advanced Settings', // HTML element Title
				array( $this, 'print_section_info_misc' ), // Callback (echos section content)
				'cas_admission' // Page this section is shown on (slug)
			);

			add_settings_field(
				'cas_settings_misc_lostpassword_url', // HTML element ID
				'Custom LDAP Lost Password URL', // HTML element Title
				array( $this, 'print_text_cas_misc_lostpassword_url' ), // Callback (echos form element)
				'cas_admission', // Page this setting is shown on (slug)
				'cas_settings_misc' // Section this setting is shown on
			);
		}


		/**
		 * Settings sanitizer callback
		 @todo: add sanitizer filters for the different options fields.
		 */
		function sanitize_cas_settings( $cas_settings ) {
			// Sanitize LDAP Host setting
			if ( filter_var( $cas_settings['cas_host'], FILTER_SANITIZE_URL ) === FALSE ) {
				$cas_settings['cas_host'] = '';
			}
			// Default to "Everyone" access restriction
			if ( !in_array( $cas_settings['access_restriction'], array( 'everyone', 'university', 'course', 'user' ) ) ) {
				$cas_settings['access_restriction'] = 'everyone';
			}

			// Sanitize ABC setting (template)
			// if ( false ) {
			// 	$cas_settings['somesetting'] = '';
			// }

			return $cas_settings;
		}


		/**
		 * Settings print callbacks
		 */
		function print_section_info_cas() {
			print 'Enter your LDAP server settings below:';
		}
		function print_text_cas_host( $args = '' ) {
			$cas_settings = get_option( 'cas_settings' );
			?><input type="text" id="cas_settings_cas_host" name="cas_settings[cas_host]" value="<?= $cas_settings['cas_host']; ?>" /><?php
		}
		function print_text_cas_port( $args = '' ) {
			$cas_settings = get_option( 'cas_settings' );
			?><input type="text" id="cas_settings_cas_port" name="cas_settings[cas_port]" value="<?= $cas_settings['cas_port']; ?>" style="width:225px;" /><?php
		}
		function print_text_cas_path( $args = '' ) {
			$cas_settings = get_option( 'cas_settings' );
			?><input type="text" id="cas_settings_cas_path" name="cas_settings[cas_path]" value="<?= $cas_settings['cas_path']; ?>" style="width:275px;" /><?php
		}

		function print_section_info_access() {
			print 'Choose how you want to restrict access to this site below:';
		}
		function print_radio_cas_access_restriction( $args = '' ) {
			$cas_settings = get_option( 'cas_settings' );
			?><input type="radio" id="radio_cas_settings_access_restriction_everyone" name="cas_settings[access_restriction]" value="everyone"<?php checked( 'everyone' == $cas_settings['access_restriction'] ); ?> /> Everyone<br />
				<input type="radio" id="radio_cas_settings_access_restriction_university" name="cas_settings[access_restriction]" value="university"<?php checked( 'university' == $cas_settings['access_restriction'] ); ?> /> Only the university community (All LDAP and WP users)<br />
				<input type="radio" id="radio_cas_settings_access_restriction_course" name="cas_settings[access_restriction]" value="course"<?php checked( 'course' == $cas_settings['access_restriction'] ); ?> /> Only students enrolled in specific courses (LDAP/Sakai)<br />
				<input type="radio" id="radio_cas_settings_access_restriction_user" name="cas_settings[access_restriction]" value="user"<?php checked( 'user' == $cas_settings['access_restriction'] ); ?> /> Only WP users in this site<br /><?php
		}
		function print_combo_cas_access_courses( $args = '' ) {
			$cas_settings = get_option( 'cas_settings' );
			?><ul id="list_cas_settings_access_courses" style="margin:0;">
				<?php if ( array_key_exists( 'access_courses', $cas_settings ) && is_array( $cas_settings['access_courses'] ) ) : ?>
					<?php foreach ( $cas_settings['access_courses'] as $key => $course_id ): ?>
						<?php if (empty($course_id)) continue; ?>
						<li>
							<input type="text" id="cas_settings_access_courses_<?= $key; ?>" name="cas_settings[access_courses][]" value="<?= esc_attr( $course_id ); ?>" readonly="true" style="width: 275px;" />
							<input type="button" class="button" id="remove_course_<?= $key; ?>" onclick="cas_remove_course(this);" value="&minus;" />
							<?php if ( strlen( $cas_settings['sakai_base_url'] ) ): ?>
								<label for="cas_settings_access_courses_<?= $key; ?>"><span class="description"></span></label>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				<?php endif; ?>
			</ul>
			<div id="new_cas_settings_access_courses">
				<input type="text" name="newcourse" id="newcourse" placeholder="7017b553-3d21-46ac-ad5c-9a6c335b9a24" style="width: 275px;" />
				<input class="button" type="button" id="addcourse" onclick="cas_add_course(jQuery('#newcourse').val());" value="+" /><br />
				<label for="newcourse"><span class="description">Enter a Site ID for a course with access</span></label>
			</div>
			<?php
		}
		function print_radio_cas_access_redirect( $args = '' ) {
			$cas_settings = get_option( 'cas_settings' );
			?><input type="radio" id="radio_cas_settings_access_redirect_to_login" name="cas_settings[access_redirect]" value="login"<?php checked( 'login' == $cas_settings['access_redirect'] ); ?> /> Send them to the WordPress login screen<br />
				<input type="radio" id="radio_cas_settings_access_redirect_to_url" name="cas_settings[access_redirect]" value="url"<?php checked( 'url' == $cas_settings['access_redirect'] ); ?> /> Redirect them to a specific URL<br />
				<input type="radio" id="radio_cas_settings_access_redirect_to_page" name="cas_settings[access_redirect]" value="page"<?php checked( 'page' == $cas_settings['access_redirect'] ); ?> /> Show them a specific WordPress page<br />
				<input type="radio" id="radio_cas_settings_access_redirect_to_message" name="cas_settings[access_redirect]" value="message"<?php checked( 'message' == $cas_settings['access_redirect'] ); ?> /> Show them a simple message<?php
		}
		function print_text_cas_access_redirect_to_url( $args = '' ) {
			$cas_settings = get_option( 'cas_settings' );
			?><input type="text" id="cas_settings_access_redirect_to_url" name="cas_settings[access_redirect_to_url]" value="<?= $cas_settings['access_redirect_to_url']; ?>" placeholder="http://www.example.com/" /><?php
		}
		function print_wysiwyg_cas_access_redirect_to_message( $args = '' ) {
			$cas_settings = get_option( 'cas_settings' );
			wp_editor(
				$cas_settings['access_redirect_to_message'],
				'cas_settings_access_redirect_to_message',
				array(
					'media_buttons' => false,
					'textarea_name' => 'cas_settings[access_redirect_to_message]',
					'textarea_rows' => 5,
					'tinymce' => false,
				)
			);
		}
		function print_select_cas_access_redirect_to_page( $args = '' ) {
			$cas_settings = get_option( 'cas_settings' );
			wp_dropdown_pages(
				array( 
					'selected' => $cas_settings['access_redirect_to_page'],
					'show_option_none' => 'Select a page',
					'name' => 'cas_settings[access_redirect_to_page]',
					'id' => 'cas_settings_access_redirect_to_page',
				)
			);
		}
		function print_select_cas_access_default_role( $args = '' ) {
			$cas_settings = get_option( 'cas_settings' );
			?><select id="cas_settings_access_default_role" name="cas_settings[access_default_role]">
				<?php wp_dropdown_roles( $cas_settings['access_default_role'] ); ?>
			</select><?php
		}

		function print_section_info_misc() {
			print 'You may optionally specify some advanced settings below:';
		}
		function print_text_cas_misc_lostpassword_url() {
			$cas_settings = get_option( 'cas_settings' );
			?><input type="text" id="cas_settings_misc_lostpassword_url" name="cas_settings[misc_lostpassword_url]" value="<?= $cas_settings['misc_lostpassword_url']; ?>" placeholder="http://www.example.com/" /><?php
		}


		/**
		 ****************************
		 * Dashboard widget
		 ****************************
		 */
		function add_dashboard_widgets() {
			// Only users who can edit can see the admissions dashboard widget
			if ( current_user_can( 'edit_post' ) ) {
				// Add dashboard widget for adding/editing users with access
				wp_add_dashboard_widget( 'admission_dashboard_widget', 'Course Admission Settings', array( $this, 'add_admission_dashboard_widget' ) );
			}
		}

		function add_admission_dashboard_widget() {
			$cas_settings = get_option( 'cas_settings' );
			?>
			<div class="inside">
				<form method="post" id="cas_settings_access_form" action="">
					<p><?php $this->print_section_info_access(); ?></p>
					<div><?php $this->print_radio_cas_access_restriction(); ?></div>
					<br class="clear" />
					<div><?php $this->print_combo_cas_access_courses(); ?></div>
					<br class="clear" />
					<p class="submit">
						<span class="save-action">
							<input type="button" name="button_save_cas_settings_access" id="button_save_cas_settings_access" class="button-primary" value="Save" onclick="save_cas_settings_access(this);" style="float: right;" />
							<span class="spinner"></span>
						</span>
						<?php wp_nonce_field( 'save_cas_settings_access', 'nonce_save_cas_settings_access' ); ?>
						<input type="hidden" id="cas_settings_sakai_base_url" name="cas_settings[sakai_base_url]" value="<?php print $cas_settings['sakai_base_url']; ?>" />
					</p>
					<br class="clear" />
				</form>
			</div>
			<?php
		}

		function ajax_save_admission_dashboard_widget() {
			// Make sure posted variables exist.
			if ( empty( $_POST['access_restriction'] ) || empty( $_POST['access_courses'] ) || empty( $_POST['nonce_save_cas_settings_access'] ) ) {
				die('');
			}

			// Nonce check.
			if ( ! wp_verify_nonce( $_POST['nonce_save_cas_settings_access'], 'save_cas_settings_access' ) ) {
				die('');
			}

			// If invalid input, set access restriction to only WP users.
			if ( ! in_array( $_POST['access_restriction'], array( 'everyone', 'university', 'course', 'user' ) ) ) {
				$_POST['access_restriction'] = 'user';
			}

			$cas_settings = get_option( 'cas_settings' );

			$cas_settings['access_restriction'] = stripslashes( $_POST['access_restriction'] );
			$cas_settings['access_courses'] = $_POST['access_courses'];
			$cas_settings['ldap_password'] = $this->decrypt( base64_decode( $cas_settings['ldap_password'] ) );

			// Only users who can edit can see the Sakai dashboard widget
			if ( current_user_can( 'edit_post' ) ) {
				update_option( 'cas_settings', $cas_settings );
			}
		}


		/**
		 ****************************
		 * Helper functions
		 ****************************
		 */


		/**
		 * Basic encryption using a public (not secret!) key. Used for general
		 * database obfuscation of passwords.
		 */
		private static $key = '8QxnrvjdtweisvCBKEY!+0';
		function encrypt( $text ) {
			return mcrypt_encrypt( MCRYPT_RIJNDAEL_256, self::$key, $text, MCRYPT_MODE_ECB, 'abcdefghijklmnopqrstuvwxyz012345' );
		}
		function decrypt( $secret ) {
			$str = '';
			return rtrim( mcrypt_decrypt( MCRYPT_RIJNDAEL_256, self::$key, $secret, MCRYPT_MODE_ECB, 'abcdefghijklmnopqrstuvwxyz012345' ), "\0$str" );
		}

		/**
		 * In a multisite environment, returns true if the current user is logged
		 * in and a user of the current blog. In single site mode, simply returns
		 * true if the current user is logged in.
		 */
		function is_user_logged_in_and_blog_user() {
			$is_user_logged_in_and_blog_user = false;
			if ( is_multisite() ) {
				$is_user_logged_in_and_blog_user = is_user_logged_in() && is_user_member_of_blog( get_current_user_id() );
			} else {
				$is_user_logged_in_and_blog_user = is_user_logged_in();
			}
			return $is_user_logged_in_and_blog_user;
		}

	} // END class WP_Plugin_CAS_Admission
}


// Installation and uninstallation hooks.
register_activation_hook( __FILE__, array( 'WP_Plugin_CAS_Admission', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WP_Plugin_CAS_Admission', 'deactivate' ) );
register_uninstall_hook( __FILE__, array( 'WP_Plugin_CAS_Admission', 'uninstall' ) );


// Instantiate the plugin class.
$wp_plugin_cas_admission = new WP_Plugin_CAS_Admission();