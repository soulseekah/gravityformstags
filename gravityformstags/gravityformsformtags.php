<?php
	/*
		Plugin Name: Gravity Forms Form Tags
		Description: Adds tagging functionality to Gravity Forms forms.
		Author: Gennady Kovshenin
		Author URI: http://codeseekah.com
		Version: 0.1
	*/

	if ( !class_exists( 'GFForms' ) || !defined( 'WPINC' ) )
		return;

	class GFFormTags {
		private static $tags_table = 'gf_form_tags';
		private static $relation_table = 'gf_form_tag_relationships';

		private static $schema = <<<EOT
-- The tags table
CREATE TABLE %s (
	id int(11) unsigned NOT NULL AUTO_INCREMENT,
	tag varchar(128) UNIQUE NOT NULL,
	count int(11) unsigned DEFAULT 0,
	PRIMARY KEY (id),
	INDEX (tag)
) %s;
~
-- The relationships table
CREATE TABLE %s (
	id int(11) unsigned NOT NULL AUTO_INCREMENT,
	form_id mediumint(8) unsigned NOT NULL,
	tag_id int(11) unsigned NOT NULL,
	FOREIGN KEY (form_id) REFERENCES %s(id)
		ON DELETE CASCADE,
	FOREIGN KEY (tag_id) REFERENCES %s(id)
		ON DELETE CASCADE,
	PRIMARY KEY (id)
) %s;
EOT;
		private static $schema_version = 3;

		public static $_translation_domain = 'gfformtags';

		public static function admin_init() {
			if ( version_compare( self::$schema_version, get_site_option( 'gf_tags_schema_version' ), '>' ) )
				self::upgrade_db();

			add_action( 'gform_before_delete_form', array( __CLASS__, 'relations_cleanup' ) );
			add_filter( 'gform_form_settings', array( __CLASS__, 'render_form_settings' ), null, 2 );
			add_filter( 'gform_pre_form_settings_save', array( __CLASS__, 'save_form_settings' ) );
			add_action( 'wp_ajax_gf_form_tags_search', array( __CLASS__, 'suggest_tags' ) );
			add_action( 'current_screen', array( __CLASS__, 'filter_form_list' ) );
			add_filter( 'gform_form_apply_button', array( __CLASS__, 'form_list_columns' ) );
			add_action( 'current_screen', array( __CLASS__, 'tags_menu_action' ) );
		}

		public static function init() {
			if ( is_admin() ) {
				add_action( 'admin_init', array( __CLASS__, 'admin_init' ) );
				add_filter( 'gform_addon_navigation', array( __CLASS__, 'add_tags_menu' ) );
			}
		}

		public static function add_tags_menu( $menu_items ) {
			$menu_items[] = array(
				'name' => 'gf_form_tags',
				'label' => __( 'Tags', self::$_translation_domain ),
				'callback' => array( __CLASS__, 'tags_menu' ),
				'permission' => 'gravityforms_edit_forms'
			);
		    return $menu_items;
		}

		public static function tags_menu() {
			require dirname( __FILE__ ) . '/table.php';

			$action = empty( $_REQUEST['action'] ) ? '' : $_REQUEST['action'];

			$messages = array(
				1 => __( 'This tag already exists.', self::$_translation_domain ),
				2 => __( 'Successfully added the tag.', self::$_translation_domain ),
				3 => __( 'Successfully deleted the tag.', self::$_translation_domain ),
				4 => __( 'Successfully deleted the tags.', self::$_translation_domain ),
				5 => __( 'Successfully updated the tag.', self::$_translation_domain ),
				6 => __( 'No commas in tag, please.', self::$_translation_domain )
			);

			switch ( $action ):
				case 'edit':
					$tag = empty( $_GET['tag_ID'] ) ? '' : self::get_tag( $_GET['tag_ID'] ); 

					if ( !empty( $_GET['message'] ) && isset( $messages[$_GET['message']] ) )
						echo '<div id="message" class="error"><p><strong>' . esc_html( $messages[$_GET['message']] ) . '<strong></p></div>';

					?>
						<div class="wrap"><h2><?php echo __( 'Edit Form Tag' ); ?></h2>
							<form method="POST">
								<?php wp_nonce_field( 'edit-form-tag_' . $tag->id ); ?>
								<table class="form-table">
									<tr class="form-field form-required">
										<th scope="row" valign="top"><label for="name"><?php esc_html_e( 'Name', self::$_translation_domain ); ?></label></th>
										<td><input name="tag" id="tag" type="text" value="<?php esc_attr_e( $tag->tag ) ; ?>"size="40" aria-required="true" />
										<p class="description"><?php esc_html_e( 'The name of the form tag.', self::$_translation_domain ); ?></p></td>
									</tr>
								</table>
								<?php submit_button( __('Update') ); ?>
							</form>
						</div>
					<?php
					break;
				default:
					$table = new GFFormTags_List_Table( array(
						'singular' => __( 'Tag', self::$_translation_domain ),
						'plural' => __( 'Tags', self::$_translation_domain ),
						'screen' => 'gf_form_tags'
					) );
					
					if ( !empty( $_GET['s'] ) ) $table->set_tags( self::get_all_tags_like( $_GET['s'] ) );
					else $table->set_tags( self::get_all_tags() );
					
					?>

						<div class="wrap">
							<h2><?php esc_html_e( 'Form Tags', self::$_translation_domain );
								if ( !empty( $_REQUEST['s'] ) )
									printf( '<span class="subtitle">' . __( 'Search results for &#8220;%s&#8221;' ) . '</span>', esc_html( wp_unslash( $_REQUEST['s'] ) ) );
							?></h2>

							<?php
								if ( !empty( $_GET['message'] ) && isset( $messages[$_GET['message']] ) ) {
									if( $_GET['message'] == 1 || $_GET['message'] == 6 ) echo '<div id="message" class="error"><p><strong>' . esc_html( $messages[$_GET['message']] ) . '<strong></p></div>';
									else echo '<div id="message" class="updated"><p><strong>' . esc_html( $messages[$_GET['message']] ) . '<strong></p></div>';
								}
							?>
								<form class="search-form" action="" method="GET">
									<input type="hidden" name="page" value="gf_form_tags" />
									<p class="search-box">
										<label class="screen-reader-text" for="searchtags"><?php esc_html_e( 'Search Form Tags', self::$_translation_domain ); ?>:</label>
										<input type="search" id="searchtags" name="s" value="<?php _admin_search_query(); ?>" />
										<?php submit_button( __( 'Search Tags' ), 'button', false, false, array( 'id' => 'search-submit' ) ); ?>
									</p>
								</form>

								<br class="clear" />

								<div id="col-container">
									<div id="col-right"><div class="col-wrap"><form method="POST"><?php $table->display(); ?></form></div></div>
									<div id="col-left">
										<div class="col-wrap"><div class="form-wrap">
											<h3><?php esc_html_e( 'Add New Form Tag', self::$_translation_domain ); ?></h3>
											<form action="<?php echo add_query_arg( 'action', 'add' ); ?>" method="POST">
												<?php wp_nonce_field( 'add-form-tag' ); ?>
												<div class="form-field form-required">
													<label for="tag"><?php esc_html_e( 'Form Tag Name', self::$_translation_domain ); ?></label>
													<input name="tag" id="tag" type="text" value="" size="40" aria-required="true" />
													<p><?php esc_html_e( 'The name of the form tag.', self::$_translation_domain ); ?></p>
												</div>
												<?php submit_button( esc_html__( 'Add Form Tag', self::$_translation_domain ) ); ?>
											</form>
										</div></div>
									</div>
								</div>
							</div>
							<script type="text/javascript">
								jQuery( '#the-list' ).on( 'click', 'a.delete-tag', function( e ) {
									if ( confirm( <?php echo json_encode( __( "You are about to permanently delete the selected items.\n'Cancel' to stop, 'OK' to delete.", self::$_translation_domain ) ); ?> ) )
										return true;
									e.preventDefault()
									return false;
								} );
							</script>
						<?php
					break;
			endswitch;
		}


		/**
		 * Process the tag edit request.
		 */
		public static function tags_menu_action( $current_screen ) {
			if ( 'forms_page_gf_form_tags' != $current_screen->id ) return;

			$action = empty( $_REQUEST['action'] ) ? '' : $_REQUEST['action'];

			switch ( $action ):
				case 'add':
					if ( !GFCommon::current_user_can_any( 'gravityforms_edit_forms' ) || !check_admin_referer( 'add-form-tag' ) ) {
						wp_redirect( get_admin_url() );
						exit;
					}

					if ( self::get_tag( self::get_tag_id( $_POST['tag'] ) ) ) { // Already exists
						wp_redirect( add_query_arg( array( 'page' => 'gf_form_tags', 'message' => 1 ), 'admin.php' ) );
						exit;
					}

					if ( empty( $_POST['tag'] ) ) {
						wp_redirect( add_query_arg( array( 'page' => 'gf_form_tags' ), 'admin.php' ) );
						exit;
					}

					if ( strpos( $_POST['tag'], ',' ) ) {
						wp_redirect( add_query_arg( array( 'page' => 'gf_form_tags', 'message' => 6 ), 'admin.php' ) );
						exit;
					}
					
					self::add_tag( $_POST['tag'] );
					
					wp_redirect( add_query_arg( array( 'page' => 'gf_form_tags', 'message' => 2 ), 'admin.php' ) );
					exit;
				case 'delete':
					if ( !GFCommon::current_user_can_any( 'gravityforms_edit_forms' ) ) {
						wp_redirect( get_admin_url() );
						exit;
					}

					/* Bulk delete */
					if ( !empty( $_POST['action_tags'] ) && is_array( $_POST['action_tags'] ) ) {
						if ( !check_admin_referer( 'bulk-tags' ) ) {
							wp_redirect( get_admin_url() );
							exit;
						}

						foreach ( $_POST['action_tags'] as $tag ) self::delete_tag( $tag );
						wp_redirect( add_query_arg( array( 'page' => 'gf_form_tags', 'message' => 4 ), 'admin.php' ) );
						exit;
					}

					$tag = empty( $_GET['tag_ID'] ) ? null : self::get_tag( $_GET['tag_ID'] ); 
					if ( !$tag || !check_admin_referer( 'delete-form-tag_' . $tag->id ) ) {
						wp_redirect( add_query_arg( array( 'page' => 'gf_form_tags' ), 'admin.php' ) );
						exit;
					}

					self::delete_tag( $tag->id );

					wp_redirect( add_query_arg( array( 'page' => 'gf_form_tags', 'message' => 3 ), 'admin.php' ) );
					exit;
				case 'edit':
					if ( empty( $_POST['tag'] ) || empty( $_GET['tag_ID'] ) || !is_numeric( $_GET['tag_ID'] ) )
						return $current_screen;

					if ( !GFCommon::current_user_can_any( 'gravityforms_edit_forms' ) ) {
						wp_redirect( get_admin_url() );
						exit;
					}

					$tag = self::get_tag( $_GET['tag_ID'] );

					if ( !check_admin_referer( 'edit-form-tag_' . $tag->id ) ) {
						wp_redirect( add_query_arg( array( 'page' => 'gf_form_tags' ), 'admin.php' ) );
						exit;
					}

					if ( !$tag || $tag->tag == $_POST['tag'] ) {
						wp_redirect( add_query_arg( array( 'page' => 'gf_form_tags' ), 'admin.php' ) );
						exit;
					}

					if ( strpos( $_POST['tag'], ',' ) ) {
						wp_redirect( add_query_arg( 'message' , 6 ) );
						exit;
					}
					
					if ( !self::rename_tag( $tag->id, $_POST['tag'] ) ) {
						wp_redirect( add_query_arg( 'message', 1 ) );
						exit;
					}

					wp_redirect( add_query_arg( array( 'page' => 'gf_form_tags', 'message' => 5 ), 'admin.php' ) );
					exit;
			endswitch;
			return $current_screen;
		}

		/**
		 * Rewrites the Gravity Forms get_forms query to
		 * filter out anything that we don't need to display.
		 * Hopefully some day Gravity Forms decides to provide
		 * more hooks to us developers and make our life easier...
		 *
		 * Also hijacks the translation string to display a better
		 * message when no forms found for a specific tag. Thanks
		 * Gravity Forms, you're a pleasure to work with...
		 */
		public static function filter_form_list( $current_screen ) {
			if ( 'toplevel_page_gf_edit_forms' != $current_screen->id )
				return;

			if ( empty( $_GET['tag'] ) || !is_numeric( $_GET['tag'] ) )
				return;

			add_filter( 'query', function( $query ) {
				global $wpdb;
				
				if ( strpos( $query, 'SELECT f.id, f.title, f.date_created, f.is_active, 0 as lead_count, 0 view_count' ) !== 0 )
					return $query;

				$query = str_replace( 'WHERE', sprintf( 'JOIN %s tr ON f.id = tr.form_id WHERE', $wpdb->get_blog_prefix() . self::$relation_table ), $query  );
				if ( strpos( $query, 'ORDER BY' ) !== false ) $query = str_replace( 'ORDER BY', $wpdb->prepare( 'AND tr.tag_id = %s ORDER BY', $_GET['tag'] ), $query );
				else $query .= $wpdb->prepare( ' AND tr.tag_id = %s ORDER BY', $_GET['tag'] );

				return $query;
			} );

			add_filter( 'gform_form_apply_button', function( $return ) {
				add_filter( 'gettext', array( __CLASS__, 'filter_form_list_strings' ), null, 3 );
				return $return;
			} );
		}

		/**
		 * Rewrites some strings.
		 */
		public static function filter_form_list_strings( $translations, $text, $domain ) {
			if ( $domain != 'gravityforms' || $text != "You don't have any forms. Let's go %screate one%s!" )
				return $translations;

			remove_filter( 'gettext', array( __CLASS__, 'filter_form_list_strings' ), null, 3 );

			/* There's a sprintf that we need to hide */
			return sprintf( __( 'No forms match the current tag. <a href="%s">View all forms</a>.', self::$_translation_domain ),
				remove_query_arg( 'tag' ) ) . '<span class="hidden">%s%s</span>';
		}

		/**
		 * Since Gravity forms doesn't provide us with a way to alter
		 * columns in its form List (thanks for extending and using
		 * WP_List_Table, guyse), we're going to be injecting some
		 * inline JavaScript with our tags in hope for a brigher future...
		 */
		public static function form_list_columns( $return ) {
			/* The filter is applied twice, we only need to inject once... */
			remove_filter( 'gform_form_apply_button', array( __CLASS__, 'form_list_columns' ) );

			global $wpdb;

			$forms = $wpdb->get_results( sprintf( 'SELECT form_id AS id, GROUP_CONCAT( t.tag ) AS tags, GROUP_CONCAT( t.id ) AS tag_ids FROM %s tr join %s t on t.id = tr.tag_id GROUP BY tr.form_id;',
				$wpdb->get_blog_prefix() . self::$relation_table,
				$wpdb->get_blog_prefix() . self::$tags_table
			) );

			$tags = array();
			foreach ( $forms as $form )
				$tags[$form->id] = array_combine( explode( ',', $form->tag_ids ), explode( ',', $form->tags ) );

			?>
				<script type="text/javascript">
					jQuery( document ).ready( function() {
						var forms = <?php echo json_encode( $tags ); ?>;

						var column = jQuery( '<th scope="col">' );
						column.text( <?php echo json_encode( __( 'Tags', self::$_translation_domain ) ); ?> );
						jQuery( '#forms_form table thead tr' ).append( column );	
						jQuery( '#forms_form table tfoot tr' ).append( column.clone() );

						jQuery( '#forms_form td.column-id' ).each( function() {
							var id = jQuery( this ).text();
							var cell = jQuery( '<td class="column-tag">' );
							if ( typeof forms[id] != 'undefined' ) {
								var tagn = 0;
								jQuery.each( forms[id], function( k, v ) {
									var link = jQuery( '<a>' );
									link.attr( 'href', <?php echo json_encode( add_query_arg( 'page', 'gf_edit_forms', 'admin.php' ) ); ?> + '&tag=' + k );
									link.text( v );
									cell.append( link );
								} )
								cell.find( 'a' ).each( function() {
									jQuery( this ).not( ':last-child' ).after( '<span>, </span>' );
								} );
							} else cell.text( '—' );
							jQuery( this ).parents( 'tr' ).append( cell );
						} );
					} );
				</script>
			<?php
			return $return;
		}


		/**
		 * Runs if a database upgrade is required when changing schemas.
		 */
		private static function upgrade_db() {
			global $wpdb;

			$charset_collate = '';

			if ( !empty( $wpdb->charset ) )
				$charset_collate = "DEFAULT CHARACTER SET $wpdb->charset";
			if ( !empty( $wpdb->collate ) )
				$charset_collate .= " COLLATE $wpdb->collate";

			/**
			 * Each current version will increment upwards. We will reset
			 * the version upon release and clean these upgrades up since
			 * they're irrelevant outside of the development process.
			 * TODO: cleanup after release
			 */
			switch ( get_site_option( 'gf_tags_schema_version' ) ):
				case 2:
					/* Added cascades on our foreign keys */
					$wpdb->query( sprintf( 'ALTER TABLE %s DROP FOREIGN KEY wp_gf_form_tag_relationships_ibfk_1;', $wpdb->get_blog_prefix() . self::$relation_table ) );
					$wpdb->query( sprintf( 'ALTER TABLE %s DROP FOREIGN KEY wp_gf_form_tag_relationships_ibfk_2;', $wpdb->get_blog_prefix() . self::$relation_table ) );
					$wpdb->query( sprintf( 'ALTER TABLE %s ADD FOREIGN KEY (tag_id) REFERENCES %s(id) ON DELETE CASCADE;',
						$wpdb->get_blog_prefix() . self::$relation_table,
						$wpdb->get_blog_prefix() . self::$tags_table ) );
					$wpdb->query( sprintf( 'ALTER TABLE %s ADD FOREIGN KEY (form_id) REFERENCES %s(id) ON DELETE CASCADE;',
						$wpdb->get_blog_prefix() . self::$relation_table,
						$wpdb->get_blog_prefix() . 'rg_form' ) );
					update_site_option( 'gf_tags_schema_version', 3 );
					break;
				case false: /* Nothing exists yet... */
					$wpdb->query( sprintf( 'DROP TABLE IF EXISTS %s;', $wpdb->get_blog_prefix() . self::$relation_table ) );
					$wpdb->query( sprintf( 'DROP TABLE IF EXISTS %s;', $wpdb->get_blog_prefix() . self::$tags_table ) );
					self::$schema = sprintf( self::$schema,
						$wpdb->get_blog_prefix() . self::$tags_table,
						$charset_collate,
						$wpdb->get_blog_prefix() . self::$relation_table,
						$wpdb->get_blog_prefix() . 'rg_form',
						$wpdb->get_blog_prefix() . self::$tags_table,
						$charset_collate
					);
					self::$schema = explode( '~', self::$schema );
					foreach ( self::$schema as $schema )
						$wpdb->query( $schema );
					update_site_option( 'gf_tags_schema_version', self::$schema_version );
					break;
				default:
					break;
			endswitch;
			add_action( 'admin_notices', array( __CLASS__, 'notice_db_upgraded' ) );
		}

		public static function notice_db_upgraded() {
			printf( '<div class="updated"><p>%s</p></div>', esc_html( __( 'Gravity Forms form tags database updated', self::$_translation_domain ) ) );
		} 

		/**
		 * Clean things up when deleting a form.
		 */
		public static function relations_cleanup( $form_id ) {
			global $wpdb;

			$tags = self::get_tags( $form_id );

			// Remove all relations on form; data should be 'raw'
			$wpdb->delete( $wpdb->get_blog_prefix() . self::$relation_table, array( 'form_id' => $form_id ) );
	
			// Lower counts on each tag that was on form
			foreach ( $tags as $tag ) 
				self::decrement_count( self::get_tag_id( $tag->tag ) );
		}

		public static function render_form_settings( $settings, $form ) {

			wp_enqueue_script( 'suggest' );
			
			ob_start();

			$tooltip = sprintf( '<h6>%s</h6>%s', __( 'Form Tags', self::$_translation_domain ), __( 'A comma-separated list of tags for this form. Keep it short, sweet and simple.', self::$_translation_domain ) );

			?>
				<tr>
					<th>Tags <a href='#' onclick='return false;' class='gf_tooltip tooltip tooltip_form_description' title="<?php echo esc_attr( $tooltip ); ?>"><i class='fa fa-question-circle'></i></a></th>
					<td>
						<input type="text" value="" name="gf_form_tag" id="gf_form_tag" autocomplete="off" class="fieldwidth-4">
						<input type="button" class="gf_form_tags_add button" value="<?php echo esc_attr( __( 'Add', self::$_translation_domain ) ); ?>">
						<div class="tagchecklist">
						</div>
					</td>
				</tr>

				<script type="text/javascript">
					jQuery( window ).ready( function() {
						var tags = <?php echo json_encode( self::get_tags( $form['id'] ) ); ?>;

						jQuery( tags ).each( function( i, e ) {
							add_tag( e );
						} );

						function add_tag( tag ) {
							var span = jQuery( '<span />').text( tag ).prepend( '<a class="ntdelbutton">X</a>&nbsp;' );
							var input = jQuery( '<input />').attr( {'type':'hidden','name':'gf_form_tags[]'} ).val( tag );

							jQuery( '.tagchecklist' ).append( span );
							jQuery( '.tagchecklist' ).append( input );

							tags.push( tag );

							jQuery( '.tagchecklist .ntdelbutton' ).bind( 'click', function( e ) {
								delete_tag( this );
							} );
						}

						function delete_tag( tag ) {
							jQuery( tag ).parent().next().remove();
							jQuery( tag ).parent().remove();
						}

						jQuery( '.tagchecklist .ntdelbutton' ).bind( 'click', function( e ) {
							delete_tag( this );
						} );

						jQuery( 'input.gf_form_tags_add' ).bind( 'click', function( e ) {
							var inputTags = jQuery( '#gf_form_tag' ).val().split( ',' );

							jQuery( '#gf_form_tag' ).val( '' );

							if ( inputTags == '' ) return;

							inputTags.forEach( function( tag ) {
								tag = tag.trim();

								tags.forEach ( function( o ) { if ( o == tag ) { tag = ''; return } } );

								if ( tag == '' ) return;
							
								add_tag( tag );
							} );
						} );

						jQuery( '#gf_form_tag' ).suggest( ajaxurl + '?action=gf_form_tags_search', { delay: 500, minchars: 2, multiple: true, multipleSep: ', ' } );

						jQuery( '#gf_form_tag' ).keypress(function(e) {
							if(e.which == 13) {
								jQuery( 'input.gf_form_tags_add' ).click();
							}
						});	
					} );
				</script>

			<?php


			$settings['Form Basics']['gf_form_tags'] = ob_get_clean();
			return $settings;
		}

		public static function save_form_settings( $form ) {
			if ( !isset( $_POST['gf_form_tags'] ) )
				return $form;

			$tags = is_array( $_POST['gf_form_tags'] ) ? $_POST['gf_form_tags'] : array();
			$existing_tags = array_flip( self::get_tags( $form['id'] ) );
			foreach ( $tags as $tag ) {
				$tag = substr( $tag, 0, 128 );
				if ( isset( $existing_tags[$tag] ) ) unset( $existing_tags[$tag] );
				self::bind_tag( $form['id'], $tag );
			}
			foreach ( $existing_tags as $tag => $i ) {
				self::unbind_tag( $form['id'], $tag );
			}
			return $form;
		}

		/**
		 * AJAX tag suggest system.
		 */
		public static function suggest_tags() {
			if ( !isset( $_GET['q'] ) || strlen( $_GET['q'] ) < 2 )
				return;

			global $wpdb;

			$sql = $wpdb->prepare( sprintf( 'SELECT DISTINCT tag FROM %s WHERE tag LIKE %%s;', $wpdb->get_blog_prefix() . self::$tags_table ), '%' . like_escape( $_GET['q'] ) . '%' );
			printf( implode( "\n", $wpdb->get_col( $sql ) ) );
			exit;
		}

		/**
		 * Adds a tag to a form, creating one if it's new and returning the id
		 */
		public static function add_tag( $tag ) {
			global $wpdb;

			$tag_id = self::get_tag_id( $tag );

			if ( is_null( $tag_id ) )
				$wpdb->insert( $wpdb->get_blog_prefix() . self::$tags_table, array( 'tag' => $tag ) );

			return self::get_tag_id( $tag );
		}

		/**
		 * Rename the given tag
		 */
		public static function rename_tag( $tag_id, $tag ) {
			global $wpdb;

			if( self::get_tag( self::get_tag_id( $tag ) ) ) return false;

			$wpdb->update( $wpdb->get_blog_prefix() . self::$tags_table , array( 'tag' => $tag ), array( 'id' => $tag_id ) );

			return self::get_tag( $tag_id );
		}

		/**
		 * Get a single tag by id
		 */
		public static function get_tag( $id ) {
			global $wpdb;

			return $wpdb->get_row( $wpdb->prepare( sprintf('SELECT * FROM %s WHERE id = %%d;', $wpdb->get_blog_prefix() . self::$tags_table ), $id ) );
		}

		/**
		 * Gets a tag id from name if exists
		 */
		public static function get_tag_id( $tag ) {
			global $wpdb;
			
			return $wpdb->get_var( $wpdb->prepare( sprintf('SELECT id FROM %s WHERE tag = %%s;', $wpdb->get_blog_prefix() . self::$tags_table ), $tag ) );
		}

		/**
		 * There should be a bind_tag function, to add it to a form
		 */
		public static function bind_tag( $form_id, $tag ) {
			global $wpdb;
			
			$tag_id = self::get_tag_id( $tag ); // Grab the preexisting tag_id

			if ( is_null( $tag_id ) ) // Or add it if it doesnt exist
				$tag_id = self::add_tag( $tag );

			$relation_id = $wpdb->get_var(
				$wpdb->prepare(
					sprintf( 'SELECT id FROM %s WHERE form_id = %%s AND tag_id = %%s;', $wpdb->get_blog_prefix() . self::$relation_table ),
					$form_id, $tag_id
				)
			);
			if ( !is_null( $relation_id ) ) return true; // Already exists
			if ( !$wpdb->insert( $wpdb->get_blog_prefix() . self::$relation_table, array( 'form_id' => $form_id, 'tag_id' => $tag_id ) ) ) return false;
			if ( !self::increment_count( $tag_id ) ) return false;
			return true;
		}

		/**
		 * Unbinds a tag from a form, does not delete it.
		 */
		public static function unbind_tag( $form_id, $tag ) {
			global $wpdb;
			
			$tag_id = self::get_tag_id( $tag ); // Grab the preexisting tag_id

			if ( is_null( $tag_id ) ) return true; // Already unbound

			// Check to see if the relationship already exists 
			$relation_id = $wpdb->get_var(
				 $wpdb->prepare(
					 sprintf( 'SELECT id FROM %s WHERE form_id = %%s AND tag_id = %%s;', $wpdb->get_blog_prefix() . self::$relation_table ),
					 $form_id, $tag_id
				 )
			 );

			if ( is_null( $relation_id ) ) return true;
			if ( !$wpdb->delete( $wpdb->get_blog_prefix() . self::$relation_table, array( 'form_id' => $form_id, 'tag_id'  => $tag_id ) ) ) return false;
			if ( !self::decrement_count( $tag_id ) ) return false;
			return true;
		}

		/**
		 * Raises the count on a tag by 1
		 */
		private static function increment_count( $tag_id ) {
			global $wpdb;
			$wpdb->query( $wpdb->prepare(sprintf('UPDATE %s SET count = count + 1 WHERE id = %%s;', $wpdb->get_blog_prefix() . self::$tags_table ), $tag_id ) );
		}

		/**
		 * Lowers the count on a tag by 1
		 */
		private static function decrement_count( $tag_id ) {
			global $wpdb;
			$wpdb->query($wpdb->prepare( sprintf( 'UPDATE %s SET count = count - 1 WHERE id = %%s;', $wpdb->get_blog_prefix() . self::$tags_table ), $tag_id ) );
		}

		/**
		 * Deletes a tag and all form relationships for it.
		 */
		public static function delete_tag( $tag_id ) {
			global $wpdb;

			/* Remove all relationships first */
			$wpdb->delete( $wpdb->get_blog_prefix() . self::$relation_table, array( 'tag_id'  => $tag_id ) );
			
			/* Then remove actual tag */
			return $wpdb->delete( $wpdb->get_blog_prefix() . self::$tags_table, array( 'id' => $tag_id ) );
		}

		/**
		 * Gets all tags for the form.
		 */
		public static function get_tags( $form_id ) {
			global $wpdb;

			return $wpdb->get_col( 
				$wpdb->prepare(
					sprintf( 'SELECT tag FROM %s INNER JOIN %s ON %s = %s WHERE %s = %%d;',
						$wpdb->get_blog_prefix() . self::$tags_table,
						$wpdb->get_blog_prefix() . self::$relation_table,
						$wpdb->get_blog_prefix() . self::$tags_table . '.id',
						$wpdb->get_blog_prefix() . self::$relation_table . '.tag_id',
						$wpdb->get_blog_prefix() . self::$relation_table . '.form_id'
					),
					$form_id
				)
			);
		}

		/**
		 * Gets all forms with tag(s)
		 */
		public static function get_forms_tagged( $tag ) {
			global $wpdb;

			$tag_id = self::get_tag_id( $tag );
			
			// Get all the ids and return the array
			if ( !is_null( $tag_id ) )
				return $wpdb->get_results( $wpdb->prepare( sprintf( 'SELECT form_id FROM %s WHERE tag_id = %%s;', $wpdb->get_blog_prefix() . self::$relation_table ), $tag_id ) );

			return array();
		}

		/**
		 * Gets all tags.
		 */
		public static function get_all_tags() {
			global $wpdb;
			return $wpdb->get_results( sprintf( 'SELECT * FROM %s;', $wpdb->get_blog_prefix() . self::$tags_table ) );
		}

		/**
		 * Gets all tags matching a query.
		 */
		public static function get_all_tags_like( $search ) {
			global $wpdb;
			return $wpdb->get_results( $wpdb->prepare( sprintf( 'SELECT * FROM %s WHERE tag LIKE %%s;', $wpdb->get_blog_prefix() . self::$tags_table ), '%' . like_escape( $search ) . '%' ) );
		}
	}

	add_action( 'init', array( 'GFFormTags', 'init' ) );
