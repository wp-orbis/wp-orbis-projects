<?php

class Orbis_Projects_AdminProjectPostType {
	/**
	 * Post type.
	 */
	const POST_TYPE = 'orbis_project';

	/**
	 * Construct.
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;

		add_filter( 'manage_edit-' . self::POST_TYPE . '_columns' , array( $this, 'edit_columns' ) );

		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'custom_columns' ), 10, 2 );

		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );

		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_project' ), 10, 2 );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_project_sync' ), 500, 2 );
	}

	/**
	 * Edit columns.
	 */
	public function edit_columns( $columns ) {
		$columns = array(
			'cb'                      => '<input type="checkbox" />',
			'title'                   => __( 'Title', 'orbis-projects' ),
			'orbis_project_principal' => __( 'Principal', 'orbis-projects' ),
			'orbis_project_price'     => __( 'Price', 'orbis-projects' ),
			'orbis_project_time'      => __( 'Time', 'orbis-projects' ),
			'author'                  => __( 'Author', 'orbis-projects' ),
			'comments'                => __( 'Comments', 'orbis-projects' ),
			'date'                    => __( 'Date', 'orbis-projects' ),
		);

		return $columns;
	}

	/**
	 * Custom columns.
	 *
	 * @param string $column
	 */
	public function custom_columns( $column, $post_id ) {
		$orbis_project = new Orbis_Project( $post_id );

		switch ( $column ) {
			case 'orbis_project_principal' :
				if ( $orbis_project->has_principal() ) {
					printf(
						'<a href="%s">%s</a>',
						esc_attr( get_permalink( $orbis_project->get_principal_post_id() ) ),
						esc_html( $orbis_project->get_principal_name() )
					);
				}

				break;
			case 'orbis_project_price' :
				echo esc_html( orbis_price( $orbis_project->get_price() ) );

				break;
			case 'orbis_project_time' :
				echo esc_html( $orbis_project->get_available_time()->format() );

				break;
		}
	}

	/**
	 * Add meta boxes.
	 */
	public function add_meta_boxes() {
		add_meta_box(
			'orbis_project',
			__( 'Project Information', 'orbis-projects' ),
			array( $this, 'meta_box' ),
			'orbis_project',
			'normal',
			'high'
		);

		add_meta_box(
			'orbis_project_invoices',
			__( 'Project Invoices', 'orbis-projects' ),
			array( $this, 'invoices_meta_box' ),
			'orbis_project',
			'normal',
			'high'
		);
	}

	/**
	 * Meta box.
	 *
	 * @param mixed $post
	 */
	public function meta_box( $post ) {
		$this->plugin->plugin_include( 'admin/meta-box-project-details.php' );
	}

	/**
	 * Invoices meta box.
	 *
	 * @param mixed $post
	 */
	public function invoices_meta_box( $post ) {
		$this->plugin->plugin_include( 'admin/meta-box-project-invoices.php' );
	}

	/**
	 * Save project.
	 *
	 * @param int $post_id
	 * @param mixed $post
	 */
	public function save_project( $post_id, $post ) {
		// Doing autosave
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Verify nonce
		$nonce = filter_input( INPUT_POST, 'orbis_project_details_meta_box_nonce', FILTER_SANITIZE_STRING );
		if ( ! wp_verify_nonce( $nonce, 'orbis_save_project_details' ) ) {
			return;
		}

		// Check permissions
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// OK
		global $wp_locale;

		$definition = array(
			'_orbis_price'                  => array(
				'filter'  => FILTER_VALIDATE_FLOAT,
				'flags'   => FILTER_FLAG_ALLOW_THOUSAND,
				'options' => array( 'decimal' => $wp_locale->number_format['decimal_point'] ),
			),
			'_orbis_project_principal_id'   => FILTER_VALIDATE_INT,
			'_orbis_project_agreement_id'   => FILTER_VALIDATE_INT,
			'_orbis_project_is_finished'    => FILTER_VALIDATE_BOOLEAN,
			'_orbis_project_is_invoicable'  => FILTER_VALIDATE_BOOLEAN,
			'_orbis_project_invoice_number' => FILTER_SANITIZE_STRING,
		);

		if ( current_user_can( 'edit_orbis_project_administration' ) ) {
			$definition['_orbis_project_is_invoiced'] = FILTER_VALIDATE_BOOLEAN;
		}

		$data = filter_input_array( INPUT_POST, $definition );

		$data['_orbis_project_seconds_available'] = orbis_filter_time_input( INPUT_POST, '_orbis_project_seconds_available' );

		// Finished
		$is_finished_old = filter_var( get_post_meta( $post_id, '_orbis_project_is_finished', true ), FILTER_VALIDATE_BOOLEAN );
		$is_finished_new = filter_var( $data['_orbis_project_is_finished'], FILTER_VALIDATE_BOOLEAN );

		// Invoice number
		$invoice_number_old = get_post_meta( $post_id, '_orbis_project_invoice_number', true );
		$invoice_number_new = $data['_orbis_project_invoice_number'];

		$is_final_invoice = ( 1 == filter_input( INPUT_POST, '_orbis_project_is_final_invoice', FILTER_SANITIZE_STRING )) ? 1 : 0;

		if ( ! $is_final_invoice ) {
			$data['_orbis_project_invoice_number'] = get_post_meta( $post_id, '_orbis_project_invoice_number', true );
		}

		foreach ( $data as $key => $value ) {
			if ( empty( $value ) ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, $value );
			}
		}

		// Action
		if ( 'publish' === $post->post_status && $is_finished_old !== $is_finished_new ) {
			// @see https://github.com/woothemes/woocommerce/blob/v2.1.4/includes/class-wc-order.php#L1274
			do_action( 'orbis_project_finished_update', $post_id, $is_finished_new );
		}

		if ( 'publish' === $post->post_status && $invoice_number_old !== $invoice_number_new ) {
			// @see https://github.com/woothemes/woocommerce/blob/v2.1.4/includes/class-wc-order.php#L1274
			do_action( 'orbis_project_invoice_number_update', $post_id, $invoice_number_old, $invoice_number_new );
		}
	}

	/**
	 * Sync project with Orbis tables
	 */
	function save_project_sync( $post_id, $post ) {
		// Doing autosave
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Check post type
		if ( ! ( 'orbis_project' === $post->post_type ) ) {
			return;
		}

		// Revision
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Publish
		if ( 'publish' !== $post->post_status ) {
			return;
		}

		// OK
		global $wpdb;

		// Orbis project ID
		$orbis_id       = get_post_meta( $post_id, '_orbis_project_id', true );
		$orbis_id       = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $wpdb->orbis_projects WHERE post_id = %d;", $post_id ) );

		$principal_id   = get_post_meta( $post_id, '_orbis_project_principal_id', true );
		$is_invoicable  = get_post_meta( $post_id, '_orbis_project_is_invoicable', true );
		$is_invoiced    = get_post_meta( $post_id, '_orbis_project_is_invoiced', true );
		$invoice_number = get_post_meta( $post_id, '_orbis_project_invoice_number', true );
		$is_finished    = get_post_meta( $post_id, '_orbis_project_is_finished', true );
		$seconds        = get_post_meta( $post_id, '_orbis_project_seconds_available', true );

		$data = array();
		$form = array();

		$data['name'] = $post->post_title;
		$form['name'] = '%s';

		if ( ! empty( $principal_id ) ) {
			$data['principal_id'] = $principal_id;
			$form['principal_id'] = '%d';
		}

		$data['start_date'] = get_the_time( 'Y-m-d', $post );
		$form['start_date'] = '%s';

		$data['number_seconds'] = $seconds;
		$form['number_seconds'] = '%d';

		$data['invoicable'] = $is_invoicable;
		$form['invoicable'] = '%d';

		$data['invoiced'] = $is_invoiced;
		$form['invoiced'] = '%d';

		if ( ! empty( $invoice_number ) ) {
			$data['invoice_number'] = $invoice_number;
			$form['invoice_number'] = '%s';
		}

		$data['finished'] = $is_finished;
		$form['finished'] = '%d';

		if ( empty( $orbis_id ) ) {
			$data['post_id'] = $post_id;
			$form['post_id'] = '%d';

			$result = $wpdb->insert( $wpdb->orbis_projects, $data, $form );

			if ( false !== $result ) {
				$orbis_id = $wpdb->insert_id;
			}
		} else {
			$result = $wpdb->update(
				$wpdb->orbis_projects,
				$data,
				array( 'id' => $orbis_id ),
				$form,
				array( '%d' )
			);
		}

		update_post_meta( $post_id, '_orbis_project_id', $orbis_id );
	}
}