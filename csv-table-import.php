<?php
/**
 * Plugin Name: CSV Table Import
 * Plugin URI:  http://wordpress.org/plugins
 * Description: Import CSV to WordPress
 * Version:     0.1.0
 * Author:      Thorsten Ott
 * Author URI:
 * License:     GPLv2+
 * Text Domain: csvti
 * Domain Path: /languages
 */

/**
 * Copyright (c) 2014 Thorsten Ott (email : thorsten@thorsten-ott.de)
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License, version 2 or, at
 * your discretion, any later version, as published by the Free
 * Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 */

class CSV_Table_Import {

	private static $__instance = NULL;

	private $settings = array();
	private $default_settings = array();
	private $settings_texts = array();

	private $plugin_prefix = 'csvti';
	private $plugin_name = 'CSV Table Import';
	private $settings_page_name = null;
	private $dashed_name = 'csv-table-import';
	private $underscored_name = 'csv_table_import';
	private $js_version = '140314020645';
	private $css_version = '140314020645';

	public function __construct() {
		add_action( 'admin_init', array( &$this, 'register_setting' ) );
		add_action( 'admin_menu', array( &$this, 'register_settings_page' ) );

		/**
		 * Default settings that will be used for the setup. You can alter these value with a simple filter such as this
		 * add_filter( 'pluginprefix_default_settings', 'mypluginprefix_settings' );
		 * function mypluginprefix_settings( $settings ) {
		 * 		$settings['enable'] = false;
		 * 		return $settings;
		 * }
		 */
		$this->default_settings = (array) apply_filters( $this->plugin_prefix . '_default_settings', array(
			'enable'				=> 1,
			'app_id'				=> md5( $this->plugin_name ),
			'app_secret'			=> md5( $this->plugin_name . AUTH_KEY ),
			) );

		/**
		 * Define fields that will be used on the options page
		 * the array key is the field_name the array then describes the label, description and type of the field. possible values for field types are 'text' and 'yesno' for a text field or input fields or 'echo' for a simple output
		 * a filter similar to the default settings (ie pluginprefix_settings_texts) can be used to alter this values
		 */
		$this->settings_texts = (array) apply_filters( $this->plugin_prefix . '_settings_texts', array(
			'enable' => array(
				'label' => sprintf( __( 'Enable %s', $this->plugin_prefix ), $this->plugin_name ),
				'desc' => sprintf( __( 'Enable %s', $this->plugin_prefix ), $this->plugin_name ),
				'type' => 'yesno'
				),
			'app_id' => array(
				'label' => sprintf( __( 'APP Id' ), $this->plugin_name ),
				'desc' => sprintf( __( 'Application ID used for authentication', $this->plugin_prefix ), $this->plugin_name ),
				'type' => 'text'
				),
			'app_secret' => array(
				'label' => sprintf( __( 'APP Secret' ), $this->plugin_name ),
				'desc' => sprintf( __( 'Application Secret used for authentication' ), $this->plugin_name ),
				'type' => 'text'
				),
			) );

		$user_settings = get_option( $this->plugin_prefix . '_settings' );
		if ( false === $user_settings )
			$user_settings = array();

		// after getting default settings make sure to parse the arguments together with the user settings
		$this->settings = wp_parse_args( $user_settings, $this->default_settings );
	}

	public static function init() {
		self::instance()->settings_page_name = sprintf( __( '%s Settings', self::instance()->plugin_prefix ), self::instance()->plugin_name );

		if ( 1 == self::instance()->settings['enable'] ) {
			add_action( 'init', array( self::instance(), 'init_hook_enabled' ) );
		}
		self::instance()->init_hook_always();
	}

	/*
	 * Use this singleton to address methods
	 */
	public static function instance() {
		if ( self::$__instance == NULL )
			self::$__instance = new self;
		return self::$__instance;
	}

	/**
	 * Run these functions when the plugin is enabled
	 */
	public function init_hook_enabled() {
		$this->create_post_type();
		$this->add_rewrite_rules();
		add_filter( 'query_vars', array( &$this, 'add_query_vars' ), 10, 1 );
		$this->maybe_flush_rules();

		add_action( 'parse_request', array( &$this, 'handle_request' ), 1, 1 );
		add_shortcode( 'csv_table', array( &$this, 'table_shortcode' ) );
	}

	public function handle_request( $wp ) {
		if ( !array_key_exists( 'upload_csv', $wp->query_vars ) ) {
			return;
		}

		$post_name = sanitize_title_with_dashes( urldecode( $wp->query_vars['upload_csv'] ) );

		if ( isset( $_POST['post_title'] ) ) {
			$post_title = sanitize_title( urldecode( $_POST['post_title'] ) );
		} else {
			$post_title = $post_name;
		}

		if ( isset( $_POST['post_status'] ) ) {
			$post_status = esc_attr( $_POST['post_status'] );
		} else {
			$post_status = 'publish';
		}

		if ( isset( $_POST['post_content'] ) ) {
			$post_content = wp_post_kses( $_POST['post_content'] );
		} else {
			$post_content = '[csv_table]';
		}

		$headers = $this->get_request_headers();
		if ( ! isset( $headers['X-Csv-Table-App-Id'] ) || ! isset( $headers['X-Csv-Table-App-Secret'] ) ) {
			die( json_encode( array( 'result' => false, 'error' => 'invalid request' ) ) );
		}

		if ( self::instance()->settings['app_id'] != $headers['X-Csv-Table-App-Id'] || self::instance()->settings['app_secret'] != $headers['X-Csv-Table-App-Secret'] ) {
			header('HTTP/1.0 403 Forbidden');
			exit;
		}

		$post_data = file_get_contents( 'php://input' );

		$lines = explode( "\n", $post_data );
		$fields = array_shift( $lines );
		$fields = str_getcsv( $fields );
		$data_array = array();
		foreach ( $lines as $line ) {
		    $_array = str_getcsv( $line );
		    $_mapped_array = array();
		    foreach( $fields as $key => $fieldname ) {
		    	$_mapped_array[$fieldname] = $_array[$key];
		    }
		    $data_array[] = $_mapped_array;
		}

		$post_exists = get_page_by_path( $post_name, ARRAY_A, 'csvtable' );

		$post_array = array(
			'post_title'    => $post_title,
			'post_content'  => $post_content,
			'post_status'   => $post_status,
			'post_name'		=> $post_name,
			'post_type'		=> 'csvtable',
		);

		$post_id = false;
		if ( $post_exists ) {
			$post_array['ID'] = $post_exists['ID'];
			if ( ! isset( $_POST['post_title'] ) ) {
				unset( $post_array['post_title'] );
			}
			if ( ! isset( $_POST['post_status'] ) ) {
				unset( $post_array['post_status'] );
			}
			if ( ! isset( $_POST['post_content'] ) ) {
				unset( $post_array['post_content'] );
			}
			$post_id = $post_exists['ID'];
			wp_update_post( $post_array );
		} else {
			$post_id = wp_insert_post( $post_array );
		}

		if ( $post_id ) {
			update_post_meta( $post_id, $this->plugin_prefix . '_table_rows', $data_array );
		}

		$post = get_post( $post_id );
		die( json_encode( array( 'result' => true, 'post' => $post ) ) );
		exit;
	}

	public function table_shortcode( $atts ) {
		extract( shortcode_atts( array(
			      'post_id' => NULL,
		), $atts ) );

		if ( is_null( $post_id ) ) {
			global $post;
			$post_id = $post->ID;
		} else {
			$post_id = (int) $post_id;
		}

		$table_data = get_post_meta( $post_id, $this->plugin_prefix . '_table_rows', true );
		if ( ! $table_data ) {
			return 'No Table data';
		}

		$header_set = false;
		foreach( $table_data as $row ) {
			if ( false === $header_set ) {
				$content = '<table class="csvtable"><tr>';
				foreach( array_keys( $row ) as $fname ) {
					$content .= '<th>' . esc_html( $fname ) . '</th>';
				}
				$content .= '</tr>';
				$header_set = true;
			}
			$content .= '<tr>';
			foreach( $row as $value ) {
				$content .= '<td>' . esc_html( $value ) . '</td>';
			}
			$content .= '</tr>';
		}
		$content .= '</table>';
		return $content;
	}

	public function get_request_headers() {
		$headers = array();
		foreach( $_SERVER as $key => $value ) {
			if ( substr( $key, 0, 5 ) != 'HTTP_' ) {
				continue;
			}
			$header = str_replace(' ', '-', ucwords( str_replace( '_', ' ', strtolower( substr( $key, 5 ) ) ) ) );
			$headers[$header] = $value;
		}
		return $headers;
	}

	public function maybe_flush_rules() {
		global $wp_rewrite;
		$rewrite_rules = get_option( 'rewrite_rules' );
		foreach( $rewrite_rules as $rule => $rewrite ) {
			$rewrite_rules_array[$rule]['rewrite'] = $rewrite;
		}
		$maybe_missing = $wp_rewrite->rewrite_rules();
		$missing_rules = false;
		$rewrite_rules_array = array_reverse( $rewrite_rules_array, true );
		foreach( $maybe_missing as $rule => $rewrite ) {
			if ( ! array_key_exists( $rule, $rewrite_rules_array ) || $rewrite_rules_array[$rules] != $rewrite ) {
				$missing_rules = true;
				break;
			}
		}
		if ( true === $missing_rules ) {
			flush_rewrite_rules();
		}
	}

	public function add_query_vars( $vars ) {
		$vars[] = 'upload_csv';
		return $vars;
	}

	public function add_rewrite_rules() {
		add_rewrite_rule(
			'csvtable/upload-csv/([^/]+)/?$',
			'index.php?upload_csv=$matches[1]',
			'top'
			);
	}

	public function create_post_type() {
		$labels  =  array(
			'name' => __( 'Tables' ),
			'singular_name' => __( 'Table' ),
			'add_new' => __( 'Add New' ),
			'add_new_item' => __( 'Add New table' ),
			'edit_item' => __( 'Edit table' ),
			'new_item' => __( 'New table' ),
			'view_item' => __( 'View table' ),
			'search_items' => __( 'Search table' ),
			'not_found' =>  __( 'Nothing found' ),
			'not_found_in_trash' => __( 'Nothing found in Trash' ),
			'parent_item_colon' => ''
			);

		$args = array(
			'labels' => $labels,
			'public' => true,
			'publicly_queryable' => true,
			'show_ui' => true,
			'query_var' => true,
			'rewrite' => true,
			'capability_type' => array('edit_posts', 'edit_posts'),
			'map_meta_cap' => false,
			'hierarchical' => false,
			'menu_position' => 5,
			'has_archive' => true,
			'supports' => array( 'title', 'author', 'custom-fields', 'editor', 'language' ),
			'rewrite' => array( 'slug' => 'csvtable', 'with_front' => false )
			);

		register_post_type( 'csvtable' , $args );
	}

	/**
	 * Run these functions all the time
	 */
	public function init_hook_always() {
		/**
		 * If a css file for this plugin exists in ./css/wp-cron-control.css make sure it's included
		 */
		if ( file_exists( dirname( __FILE__ ) . "/css/" . $this->underscored_name . ".css" ) )
			wp_enqueue_style( $this->dashed_name, plugins_url( "css/" . $this->underscored_name . ".css", __FILE__ ), $deps = array(), $this->css_version );
		/**
		 * If a js file for this plugin exists in ./js/wp-cron-control.css make sure it's included
		 */
		if ( file_exists( dirname( __FILE__ ) . "/js/" . $this->underscored_name . ".js" ) )
			wp_enqueue_script( $this->dashed_name, plugins_url( "js/" . $this->underscored_name . ".js", __FILE__ ), array(), $this->js_version, true );

		/**
		 * Locale setup
		 */
		$locale = apply_filters( 'plugin_locale', get_locale(), $this->plugin_prefix );
		load_textdomain( $this->plugin_prefix, WP_LANG_DIR . '/' . $this->plugin_prefix . '/' . $this->plugin_prefix . '-' . $locale . '.mo' );
		load_plugin_textdomain( $this->plugin_prefix, false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

	}

	public function register_settings_page() {
		add_options_page( $this->settings_page_name, $this->plugin_name, 'manage_options', $this->dashed_name, array( &$this, 'settings_page' ) );
	}

	public function register_setting() {
		register_setting( $this->plugin_prefix . '_settings', $this->plugin_prefix . '_settings', array( &$this, 'validate_settings') );
	}

	public function validate_settings( $settings ) {
		// reset to defaults
		if ( !empty( $_POST[ $this->dashed_name . '-defaults'] ) ) {
			$settings = $this->default_settings;
			$_REQUEST['_wp_http_referer'] = add_query_arg( 'defaults', 'true', $_REQUEST['_wp_http_referer'] );

		// or do some custom validations
		} else {

		}
		return $settings;
	}

	public function settings_page() {
		if ( !current_user_can( 'manage_options' ) )  {
			wp_die( __( 'You do not permission to access this page', $this->plugin_prefix ) );
		}
		?>
		<div class="wrap">
			<?php if ( function_exists('screen_icon') ) screen_icon(); ?>
			<h2><?php echo $this->settings_page_name; ?></h2>

			<form method="post" action="options.php">

				<?php settings_fields( $this->plugin_prefix . '_settings' ); ?>

				<table class="form-table">
					<?php foreach( $this->settings as $setting => $value): ?>
						<?php if ( ! isset( $this->settings_texts[$setting] ) ) { continue; } ?>
						<?php do_action( $this->plugin_prefix . '_pre_setting', $setting, $value ); ?>
						<tr valign="top">
							<th scope="row">
								<label for="<?php echo $this->dashed_name . '-' . $setting; ?>">
									<?php if ( isset( $this->settings_texts[$setting]['label'] ) ) {
										echo $this->settings_texts[$setting]['label'];
									} else {
										echo $setting;
									} ?>
								</label>
							</th>
							<td>
								<?php
						/**
						 * Implement various handlers for the different types of fields. This could be easily extended to allow for drop-down boxes, textareas and more
						 */
						?>
						<?php switch( $this->settings_texts[$setting]['type'] ):
						case 'yesno': ?>
						<select name="<?php echo $this->plugin_prefix; ?>_settings[<?php echo $setting; ?>]" id="<?php echo $this->dashed_name . '-' . $setting; ?>" class="postform">
							<?php
							$yesno = array( 0 => __( 'No', $this->plugin_prefix ), 1 => __( 'Yes', $this->plugin_prefix ) );
							foreach ( $yesno as $val => $txt ) {
								echo '<option value="' . esc_attr( $val ) . '"' . selected( $value, $val, false ) . '>' . esc_html( $txt ) . "&nbsp;</option>\n";
							}
							?>
						</select><br />
						<?php break;
						case 'text': ?>
						<div><input type="text" name="<?php echo $this->plugin_prefix; ?>_settings[<?php echo $setting; ?>]" id="<?php echo $this->dashed_name . '-' . $setting; ?>" class="postform" value="<?php echo esc_attr( $value ); ?>" /></div>
						<?php break;
						case 'echo': ?>
						<div><span id="<?php echo $this->dashed_name . '-' . $setting; ?>" class="postform"><?php echo esc_attr( $value ); ?></span></div>
						<?php break;
						default: ?>
						<?php echo $this->settings_texts[$setting]['type']; ?>
						<?php break;
						endswitch; ?>
						<?php if ( !empty( $this->settings_texts[$setting]['desc'] ) ) { echo $this->settings_texts[$setting]['desc']; } ?>
					</td>
				</tr>
				<?php do_action( $this->plugin_prefix . '_post_setting', $setting, $value ); ?>
			<?php endforeach; ?>
			<?php if ( 1 == self::instance()->settings['enable'] ): ?>
				<tr>
					<td colspan="3">
						<p>The script has been enabled</p>
					</td>
				</tr>
			<?php endif; ?>
		</table>

		<p class="submit">
			<?php
			if ( function_exists( 'submit_button' ) ) {
				submit_button( null, 'primary', $this->dashed_name . '-submit', false );
				echo ' ';
				submit_button( __( 'Reset to Defaults', $this->plugin_prefix ), '', $this->dashed_name . '-defaults', false );
			} else {
				echo '<input type="submit" name="' . $this->dashed_name . '-submit" class="button-primary" value="' . __( 'Save Changes', $this->plugin_prefix ) . '" />' . "\n";
				echo '<input type="submit" name="' . $this->dashed_name . '-defaults" id="' . $this->dashed_name . '-defaults" class="button-primary" value="' . __( 'Reset to Defaults', $this->plugin_prefix ) . '" />' . "\n";
			}
			?>
		</p>

	</form>
</div>

<?php
}
}

// if we loaded wp-config then ABSPATH is defined and we know the script was not called directly to issue a cli call
if ( defined('ABSPATH') ) {
	CSV_Table_Import::init();
} else {
	// otherwise parse the arguments and call the cron.
	if ( !empty( $argv ) && $argv[0] == basename( __FILE__ ) || $argv[0] == __FILE__ ) {
		if ( isset( $argv[1] ) ) {
			echo "You could do something here";
		} else {
			echo "Usage: php " . __FILE__ . " <param1>\n";
			echo "Example: php " . __FILE__ . " superduperparameter\n";
			exit;
		}
	}
}

