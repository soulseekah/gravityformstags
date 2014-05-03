<?php
	class GFFormTags_List_Table extends WP_List_Table {

		public function ajax_user_can() {
			return current_user_can( 'gravityforms_edit_forms' );
		}
		
		public function get_columns() {
			$columns = array(
				'cb'          => '<input type="checkbox" />',
				'name'        => __( 'Name', GFFormTags::$_translation_domain ),
				'forms'		  => __( 'Forms', GFFormTags::$_translation_domain )
			);
			return $columns;
		}

		public function get_sortable_columns() {
			return array( 'name', 'forms' );
		}

		public function has_items() {
			return count( $this->items ) > 0;
		}

		public function set_tags( $tags ) {
			$this->items = $tags;
		}

		public function get_bulk_actions() {
			return array( 'delete' => __( 'Delete' ) );
		}

		public function column_cb( $tag ) {
			return '<input type="checkbox" name="action_tags[]" value="' . $tag->id . '" id="cb-select-' . $tag->id . '" />';
		}

		public function column_name( $tag ) {
			$edit_link = add_query_arg( array( 'page' => 'gf_form_tags', 'action' => 'edit', 'tag_ID' => $tag->id ), 'admin.php' );
			$delete_link = add_query_arg( array( 'page' => 'gf_form_tags', 'action' => 'delete', 'tag_ID' => $tag->id ), 'admin.php' );

			$out = '<strong><a class="row-title" href="' . $edit_link . '" title="' . esc_attr( sprintf( __( 'Edit &#8220;%s&#8221;' ), $tag->tag ) ) . '">' . esc_html( $tag->tag ) . '</a></strong><br />';

			$actions = array();
			if ( GFCommon::current_user_can_any( 'gravityforms_edit_forms' ) ) {
				$actions['edit'] = '<a href="' . $edit_link . '">' . __( 'Edit' ) . '</a>';
				$actions['delete'] = '<a class="delete-tag" href="' . wp_nonce_url( $delete_link, 'delete-form-tag_' . $tag->id ) . '">' . __( 'Delete' ) . '</a>';
				$actions['view'] = '<a href="' . add_query_arg( array( 'page' => 'gf_edit_forms', 'tag' => $tag->id ), 'admin.php' ) . '">' . __( 'View' ) . '</a>';
			}

			$out .= $this->row_actions( $actions );

			return $out;
		}

		public function column_forms( $tag ) {
			$count = number_format_i18n( $tag->count );
			$url = add_query_arg( array( 'page' => 'gf_edit_forms', 'tag' => $tag->id ), 'admin.php' );
			return '<a href="' . esc_url( $url ) . '">' . $count . '</a>';
		}

		public function column_default( $tag ) {
		}
	}
