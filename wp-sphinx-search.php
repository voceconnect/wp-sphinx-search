<?php
/*
Plugin Name: WP Sphinx Search
Version: 0.2.0
Plugin URI: http://vocecommunications.com/
Description: Adds Sphinx Search Handling to WordPress
Author: Michael Pretty
Author URI: http://voceconnect.com/
*/

if(!class_exists('SphinxClient')) {
	//sphinx pecl extension insn't installed, use php class
	require_once(dirname(__FILE__).'/sphinxapi.php');
}

class WP_Sphinx_Search {

	private $last_error;
	private $last_warning;

	/**
	 * Returns the options for sphinx
	 *
	 * @return array
	 */
	private function get_options() {
		$defaults = array (
			'server' => '127.0.0.1',
			'port' => '9312',
			'index' => "*",
			'timeout' => 15
		);

		$options = get_option('sphinx_options', false);
		if(!is_array($options)) {
			$options = array();
		}
		return wp_parse_args($options, $defaults);
	}

	/**
	 * Updates the options with the given options array
	 *
	 * @param array $options
	 */
	private function update_options($options = array()) {
		update_option('sphinx_options', $options);
	}

	/**
	 * Initialization function, registers needed hooks.
	 * Runs on 'init'
	 *
	 */
	public function initialize() {
		add_action('admin_menu', array($this, 'admin_add_menu_items'));
		register_uninstall_hook(__FILE__, array($this, 'uninstall'));
		if(class_exists('SphinxClient')) {
			add_action('parse_query', array($this, 'parse_query'), 10, 1);
			add_filter('found_posts', array($this, 'search_filter_found_posts'), 10, 2);
			add_filter('the_posts', array($this, 'search_filter_posts_order'), 10, 2);
		}
	}

	/**
	 * Checks query to see if it is a search, and if so, kicks off the
	 * Sphinx search
	 *
	 * @param WP_Query $wp_query
	 */
	public function parse_query(&$wp_query) {
		if($wp_query->is_search) {
			if(class_exists('SphinxClient')) {
				switch($wp_query->get('sort')) {
					case 'date':
						$wp_query->query_vars['orderby'] = 'date';
						$wp_query->query_vars['order'] = 'DESC';
						break;
					case 'title':
						$wp_query->query_vars['orderby'] = 'title';
						$wp_query->query_vars['order'] = 'ASC';
						break;
					default:
						$wp_query->query_vars['sort'] = 'match'; //setting this so sort link will be hilighted
				}
				$results = $this->search_posts($wp_query->query_vars);
				if($results) {
					$matching_ids = array();
					if(intval($results['total']) > 0 ) {
						foreach($results['matches'] as $result) {
							$matching_ids[] = intval($result['attrs']['post_id']);
						}
					} else {
						$matching_ids[] = -1;
					}
					//clear the search query var so posts aren't filtered based on the search
					$wp_query->query_vars['sphinx_search_term'] = $wp_query->query_vars['s'];
					unset($wp_query->query_vars['s']);
					if(isset($wp_query->query_vars['paged'])) {
						//set our own copy of paged so that wordpress doesn't try to page a query already limiting posts
						$wp_query->query_vars['sphinx_paged'] = $wp_query->query_vars['paged'];
						unset($wp_query->query_vars['paged']);
					}
					$wp_query->query_vars['post__in'] = $matching_ids;
					$wp_query->query_vars['sphinx_num_matches'] = intval($results['total']);
				}
			}
		}
	}

	/**
	 * Runs a search against sphinx
	 *
	 * @param array $args
	 * @return array Sphinx result set
	 */
	public function search_posts($args) {
		$options = $this->get_options();
		$defaults = array(
			'search_using' => 'any',
			'sort' => 'match',
			'paged' => 1,
			'posts_per_page' => 0,
			'showposts' => 0
		);
		$args = wp_parse_args($args, $defaults);
		$sphinx = new SphinxClient();
		$sphinx->setServer($options['server'], $options['port']);

		$search = $args['s'];
		switch($args['search_using']) {
			case 'all':
				$sphinx->setMatchMode(SPH_MATCH_ALL);
				break;
			case 'exact':
				$sphinx->setMatchMode(SPH_MATCH_PHRASE);
				break;
			default:
				$sphinx->setMatchMode(SPH_MATCH_ANY);
		}

		switch($args['sort']) {
			case 'date':
				$sphinx->setSortMode(SPH_SORT_ATTR_DESC, 'date_added');
				break;
			case 'title':
				$sphinx->setSortMode(SPH_SORT_ATTR_ASC, 'title');
				break;
			default:
				$sphinx->setSortMode(SPH_SORT_RELEVANCE);
		}

		$page = isset($args['paged']) && (intval($args['paged']) > 0) ? intval($args['paged']) : 1;
		$per_page = max(array($args['posts_per_page'], $args['showposts']));
		if($per_page < 1) {
			$per_page = get_option('posts_per_page');
		}

		$sphinx->setLimits(($page - 1) * $per_page, $per_page);
		$sphinx->setMaxQueryTime(intval($options['timeout']));
		$result = $sphinx->query($search, $options['index']);
		$this->last_error = $sphinx->getLastError();
		$this->last_warning = $sphinx->getLastWarning();
		return $result;
	}

	private function test_settings() {
		$result = $this->search_posts(array('s'=>'test search', 'posts_per_page' => 1));
		if(!$result) {
			return $this->last_error;
		}
		return false;
	}

	/**
	 * Filters the found posts to reflect the number and order returned by sphinx
	 *
	 * @param int $found_posts
	 * @param WP_Query $wp_query
	 */
	public function search_filter_found_posts($found_posts, &$wp_query = null) {
		if(!is_null($wp_query)) {
			if(isset($wp_query->query_vars['sphinx_num_matches'])) {
				$found_posts = intval($wp_query->query_vars['sphinx_num_matches']);
			}
			if(isset($wp_query->query_vars['sphinx_search_term'])) {
				$wp_query->query_vars['s'] = $wp_query->query_vars['sphinx_search_term'];
			}
			if(isset($wp_query->query_vars['sphinx_paged'])) {
				$wp_query->query_vars['paged'] = $wp_query->query_vars['sphinx_paged'];
			}
		}

		return $found_posts;
	}

	public function search_filter_posts_order($posts, $wp_query = null) {
		if( !is_null($wp_query) && isset($wp_query->query_vars['post__in']) && isset($wp_query->query_vars['sphinx_num_matches']) ) {
			$sphinx_id_order = $wp_query->query_vars['post__in'];
			$reordered_posts = array();
			foreach ($sphinx_id_order as $sphinx_post_id) {
				foreach ($posts as $post) {
					if ($post->ID == $sphinx_post_id) {
						$reordered_posts[] = $post;
						break;
					}
				}
			}
			return $reordered_posts;
		}
		return $posts;
	}

	/**
	 * Adds options page to manage sphinx settings
	 *
	 */
	public function admin_add_menu_items() {
		add_options_page(__('Sphinx Search', 'sphinx-search'), __('Sphinx Search', 'sphinx-search'), 'manage_options', 'sphinx-search', array($this, 'admin_options_page'));
	}

	public function admin_options_page() {
		$options = $this->get_options();
		$error = false;
		$updated = false;
		if(isset($_REQUEST['submit']) && wp_verify_nonce($_REQUEST['sphinx_nonce'], 'save_sphinx_options')) {
			if(isset($_REQUEST['sphinx_server'])) {
				$options['server'] = $_REQUEST['sphinx_server'];
			}
			if(isset($_REQUEST['sphinx_port'])) {
				$options['port'] = $_REQUEST['sphinx_port'];
			}
			if(isset($_REQUEST['sphinx_server'])) {
				$options['index'] = $_REQUEST['sphinx_index'];
			}
			if(isset($_REQUEST['sphinx_timeout'])) {
				$options['timeout'] = (intval($_REQUEST['sphinx_timeout']) > 0) ? intval($_REQUEST['sphinx_timeout']) : 15;
			}
			$this->update_options($options);
			$updated = true;
			$error = $this->test_settings();
		}
		?>
		<div class="wrap">
			<h2><?php _e('Sphinx Search', 'sphinx-search'); ?></h2>
			<?php if($updated) : ?>
				<div class="updated settings-error" id="setting-error-settings_updated"><p><strong><?php _e('Settings saved.')?></strong></p></div>
			<?php endif; ?>
			<?php if($error) : ?>
				<div class="updated settings-error" id="setting-error-settings_updated"><p><strong><?php printf( __('Sphinx Error: %s'), esc_html($error));?></strong></p></div>
			<?php endif; ?>
			<form method="post" action="options-general.php?page=sphinx-search">
				<table class="form-table">
					<tr valign="top">
						<th scope="row">
							<label for="sphinx_server"> <?php _e('Sphinx Server', 'sphinx-search') ?></label>
						</th>
						<td>
							<input name="sphinx_server" id="sphinx_server" value="<?php echo esc_attr($options['server']); ?>" class="regular-text" type="text">
							<span class="description"><?php _e('The path to your Sphinx Server (127.0.0.1 or localhost is default).', 'shinx-search')?></span>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">
							<label for="sphinx_port"> <?php _e('Sphinx Port', 'sphinx-search') ?></label>
						</th>
						<td>
							<input name="sphinx_port" id="sphinx_port" value="<?php echo esc_attr($options['port']); ?>" class="regular-text" type="text">
							<span class="description"><?php _e('The port your Sphinx Server is listening on (9312 is default).', 'shinx-search')?></span>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">
							<label for="sphinx_index"> <?php _e('Sphinx Index', 'sphinx-search') ?></label>
						</th>
						<td>
							<input name="sphinx_index" id="sphinx_index" value="<?php echo esc_attr($options['index']); ?>" class="regular-text" type="text">
							<span class="description"><?php _e('The index to search against (\'*\' is the default and searches against all indexes).', 'shinx-search')?></span>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">
							<label for="sphinx_timeout"> <?php _e('Sphinx Timeout', 'sphinx-search') ?></label>
						</th>
						<td>
							<input name="sphinx_timeout" id="sphinx_timeout" value="<?php echo esc_attr($options['timeout']); ?>" class="regular-text" type="text">
							<span class="description"><?php _e('The number of seconds to wait for the Sphinx server to respond before giving up on the request (15 is the default).', 'shinx-search')?></span>
						</td>
					</tr>
				</table>
				<p><?php _e('For more information about any of these options, please consult the <a href="http://www.sphinxsearch.com/docs/current.html">Sphinx Reference Manual</a>.', 'sphinx-search')?></p>
				<p class="submit">
					<?php wp_nonce_field('save_sphinx_options', 'sphinx_nonce');?>
					<input type="submit" name="submit" value="<?php _e('Save Changes', 'sphinx-search') ?>" />
				</p>
			</form>
		</div>
		<?php
	}

	public function uninstall() {
		if(defined('WP_UNINSTALL_PLUGIN') && WP_UNINSTALL_PLUGIN) {
			delete_option('sphinx_options');
		}
	}

}
add_action('init', array(new WP_Sphinx_Search(), 'initialize'));
