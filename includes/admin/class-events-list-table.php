<?php
/**
 * Events List Table - WordPress standard table implementation
 *
 * @package SESSYPress
 */

namespace SESSYPress;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class Events_List_Table
 *
 * Extends WP_List_Table for proper WordPress admin table display.
 */
class Events_List_Table extends \WP_List_Table {

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => __( 'Event', 'sessypress' ),
				'plural'   => __( 'Events', 'sessypress' ),
				'ajax'     => true,
			)
		);
	}

	/**
	 * Get table columns
	 *
	 * @return array Column headers.
	 */
	public function get_columns() {
		return array(
			'cb'           => '<input type="checkbox" />',
			'timestamp'    => __( 'Timestamp', 'sessypress' ),
			'event_type'   => __( 'Event Type', 'sessypress' ),
			'event_source' => __( 'Event Source', 'sessypress' ),
			'recipient'    => __( 'Recipient', 'sessypress' ),
			'subject'      => __( 'Subject', 'sessypress' ),
			'details'      => __( 'Details', 'sessypress' ),
		);
	}

	/**
	 * Get sortable columns
	 *
	 * @return array Sortable columns.
	 */
	public function get_sortable_columns() {
		return array(
			'timestamp'    => array( 'timestamp', true ),
			'event_type'   => array( 'event_type', false ),
			'event_source' => array( 'event_source', false ),
			'recipient'    => array( 'recipient', false ),
		);
	}

	/**
	 * Column default rendering
	 *
	 * @param array  $item        Row data.
	 * @param string $column_name Column name.
	 * @return string Column output.
	 */
	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'timestamp':
				return esc_html( gmdate( 'Y-m-d H:i:s', strtotime( $item['timestamp'] ) ) );
			case 'recipient':
				return '<code>' . esc_html( $item['recipient'] ) . '</code>';
			case 'subject':
				return esc_html( $item['subject'] ? $item['subject'] : '—' );
			default:
				return esc_html( $item[ $column_name ] ?? '—' );
		}
	}

	/**
	 * Render checkbox column
	 *
	 * @param array $item Row data.
	 * @return string Checkbox HTML.
	 */
	public function column_cb( $item ) {
		return sprintf(
			'<input type="checkbox" name="event_id[]" value="%s" />',
			esc_attr( $item['id'] )
		);
	}

	/**
	 * Render event_type column with badge
	 *
	 * @param array $item Row data.
	 * @return string Badge HTML.
	 */
	public function column_event_type( $item ) {
		$badge_class = $this->get_event_type_class( $item['event_type'] );
		return sprintf(
			'<span class="sessypress-badge sessypress-badge-%s">%s</span>',
			esc_attr( $badge_class ),
			esc_html( $item['event_type'] )
		);
	}

	/**
	 * Render event_source column with badge
	 *
	 * @param array $item Row data.
	 * @return string Badge HTML.
	 */
	public function column_event_source( $item ) {
		$source_class = $this->get_source_class( $item['event_source'] );
		$source_label = $this->get_source_label( $item['event_source'] );
		return sprintf(
			'<span class="sessypress-badge sessypress-badge-source-%s">%s</span>',
			esc_attr( $source_class ),
			esc_html( $source_label )
		);
	}

	/**
	 * Render details column with actions
	 *
	 * @param array $item Row data.
	 * @return string Actions HTML.
	 */
	public function column_details( $item ) {
		$actions = array(
			'view' => sprintf(
				'<a href="#" class="sessypress-view-details" data-event-id="%s">%s</a>',
				esc_attr( $item['id'] ),
				__( 'View Details', 'sessypress' )
			),
		);

		return $this->row_actions( $actions );
	}

	/**
	 * Get bulk actions
	 *
	 * @return array Bulk actions.
	 */
	public function get_bulk_actions() {
		return array(
			'delete' => __( 'Delete', 'sessypress' ),
			'export' => __( 'Export to CSV', 'sessypress' ),
		);
	}

	/**
	 * Process bulk actions
	 */
	public function process_bulk_action() {
		if ( 'delete' === $this->current_action() ) {
			// Verify nonce.
			if ( ! isset( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ), 'bulk-' . $this->_args['plural'] ) ) {
				wp_die( esc_html__( 'Security check failed.', 'sessypress' ) );
			}

			$event_ids = isset( $_REQUEST['event_id'] ) ? array_map( 'absint', (array) $_REQUEST['event_id'] ) : array();

			if ( ! empty( $event_ids ) ) {
				global $wpdb;
				$table        = $wpdb->prefix . 'ses_email_events';
				$ids_in       = implode( ',', $event_ids );
				$deleted      = $wpdb->query( "DELETE FROM $table WHERE id IN ($ids_in)" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

				if ( $deleted ) {
					add_settings_error(
						'sessypress_messages',
						'sessypress_message',
						sprintf(
							/* translators: %d: number of events deleted */
							_n( '%d event deleted.', '%d events deleted.', $deleted, 'sessypress' ),
							$deleted
						),
						'success'
					);
				}
			}
		}
	}

	/**
	 * Prepare items for display
	 */
	public function prepare_items() {
		global $wpdb;

		$this->process_bulk_action();

		$columns  = $this->get_columns();
		$hidden   = array();
		$sortable = $this->get_sortable_columns();

		$this->_column_headers = array( $columns, $hidden, $sortable );

		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$offset       = ( $current_page - 1 ) * $per_page;

		$table = $wpdb->prefix . 'ses_email_events';

		// Get filters from URL.
		$event_type   = isset( $_GET['event_type'] ) ? sanitize_text_field( wp_unslash( $_GET['event_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$event_source = isset( $_GET['event_source'] ) ? sanitize_text_field( wp_unslash( $_GET['event_source'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$recipient    = isset( $_GET['recipient'] ) ? sanitize_email( wp_unslash( $_GET['recipient'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$date_from    = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : gmdate( 'Y-m-d', strtotime( '-30 days' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$date_to      = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : gmdate( 'Y-m-d' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Build WHERE clause.
		$where_clauses = array( '1=1' );
		$where_values  = array();

		if ( ! empty( $event_type ) ) {
			$where_clauses[] = 'event_type = %s';
			$where_values[]  = $event_type;
		}

		if ( ! empty( $event_source ) ) {
			$where_clauses[] = 'event_source = %s';
			$where_values[]  = $event_source;
		}

		if ( ! empty( $recipient ) ) {
			$where_clauses[] = 'recipient LIKE %s';
			$where_values[]  = '%' . $wpdb->esc_like( $recipient ) . '%';
		}

		$where_clauses[] = 'DATE(timestamp) BETWEEN %s AND %s';
		$where_values[]  = $date_from;
		$where_values[]  = $date_to;

		$where_sql = implode( ' AND ', $where_clauses );

		// Get sorting.
		$orderby = isset( $_GET['orderby'] ) ? sanitize_sql_orderby( wp_unslash( $_GET['orderby'] ) ) : 'timestamp'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order   = isset( $_GET['order'] ) && 'asc' === strtolower( $_GET['order'] ) ? 'ASC' : 'DESC'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Get total items.
		$total_items = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM $table WHERE $where_sql",
				$where_values
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// Get items.
		$this->items = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM $table WHERE $where_sql ORDER BY $orderby $order LIMIT %d OFFSET %d",
				array_merge( $where_values, array( $per_page, $offset ) )
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// Set pagination.
		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => ceil( $total_items / $per_page ),
			)
		);
	}

	/**
	 * Display when no items found
	 */
	public function no_items() {
		esc_html_e( 'No events found.', 'sessypress' );
	}

	/**
	 * Get event type CSS class
	 *
	 * @param string $event_type Event type.
	 * @return string CSS class.
	 */
	private function get_event_type_class( $event_type ) {
		$type = strtolower( $event_type );

		$classes = array(
			'send'             => 'send',
			'delivery'         => 'success',
			'reject'           => 'warning',
			'bounce'           => 'error',
			'complaint'        => 'error',
			'open'             => 'success',
			'click'            => 'success',
			'deliverydelay'    => 'warning',
			'renderingfailure' => 'error',
			'subscription'     => 'info',
		);

		return $classes[ $type ] ?? 'info';
	}

	/**
	 * Get event source CSS class
	 *
	 * @param string $event_source Event source.
	 * @return string CSS class.
	 */
	private function get_source_class( $event_source ) {
		$classes = array(
			'sns_notification' => 'sns',
			'event_publishing' => 'event',
			'manual'           => 'manual',
		);

		return $classes[ $event_source ] ?? 'info';
	}

	/**
	 * Get event source label
	 *
	 * @param string $event_source Event source.
	 * @return string Human-readable label.
	 */
	private function get_source_label( $event_source ) {
		$labels = array(
			'sns_notification' => __( 'SNS', 'sessypress' ),
			'event_publishing' => __( 'Event Pub', 'sessypress' ),
			'manual'           => __( 'Manual', 'sessypress' ),
		);

		return $labels[ $event_source ] ?? $event_source;
	}

	/**
	 * Extra tablenav (filters)
	 *
	 * @param string $which Top or bottom.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		$event_type   = isset( $_GET['event_type'] ) ? sanitize_text_field( wp_unslash( $_GET['event_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$event_source = isset( $_GET['event_source'] ) ? sanitize_text_field( wp_unslash( $_GET['event_source'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="alignleft actions">
			<select name="event_type">
				<option value=""><?php esc_html_e( 'All Event Types', 'sessypress' ); ?></option>
				<option value="Send" <?php selected( $event_type, 'Send' ); ?>><?php esc_html_e( 'Send', 'sessypress' ); ?></option>
				<option value="Delivery" <?php selected( $event_type, 'Delivery' ); ?>><?php esc_html_e( 'Delivery', 'sessypress' ); ?></option>
				<option value="Reject" <?php selected( $event_type, 'Reject' ); ?>><?php esc_html_e( 'Reject', 'sessypress' ); ?></option>
				<option value="Bounce" <?php selected( $event_type, 'Bounce' ); ?>><?php esc_html_e( 'Bounce', 'sessypress' ); ?></option>
				<option value="Complaint" <?php selected( $event_type, 'Complaint' ); ?>><?php esc_html_e( 'Complaint', 'sessypress' ); ?></option>
				<option value="Open" <?php selected( $event_type, 'Open' ); ?>><?php esc_html_e( 'Open', 'sessypress' ); ?></option>
				<option value="Click" <?php selected( $event_type, 'Click' ); ?>><?php esc_html_e( 'Click', 'sessypress' ); ?></option>
			</select>

			<select name="event_source">
				<option value=""><?php esc_html_e( 'All Sources', 'sessypress' ); ?></option>
				<option value="sns_notification" <?php selected( $event_source, 'sns_notification' ); ?>><?php esc_html_e( 'SNS Notification', 'sessypress' ); ?></option>
				<option value="event_publishing" <?php selected( $event_source, 'event_publishing' ); ?>><?php esc_html_e( 'Event Publishing', 'sessypress' ); ?></option>
				<option value="manual" <?php selected( $event_source, 'manual' ); ?>><?php esc_html_e( 'Manual Tracking', 'sessypress' ); ?></option>
			</select>

			<?php submit_button( __( 'Filter', 'sessypress' ), '', 'filter_action', false ); ?>
		</div>
		<?php
	}
}
