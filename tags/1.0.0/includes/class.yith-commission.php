<?php
/*
 * This file belongs to the YIT Framework.
 *
 * This source file is subject to the GNU GENERAL PUBLIC LICENSE (GPL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://www.gnu.org/licenses/gpl-3.0.txt
 */
if ( ! defined( 'YITH_WPV_VERSION' ) ) {
	exit( 'Direct access forbidden.' );
}

if ( ! class_exists( 'YITH_Commission' ) ) {

	/**
	 * Main class for the commission
	 *
	 * @class      YITH_Commission
	 * @package    YIThemes
	 * @since      Version 2.0.0
	 * @author     Your Inspiration Themes
	 * @category   Class
	 *
	 * @property   int $id The ID of commission
	 * @property   int $line_item_id The ID of product into the order
	 * @property   int $order_id The order ID of commission
	 * @property   int $user_id The user ID has received commission
	 * @property   int $vendor_id The ID of vendor where is sent the product
	 * @property   float $rate The rate commission to assign
	 * @property   float $amount The amount payed to $user_id
	 * @property   string $status The status of commission (one between 'pending', 'unpaid' and 'paid')
	 * @property   string $last_edit When was the last update
	 * @property   string $last_edit_gmt When was the last update
	 */
	class YITH_Commission {

		/** @public int */
		public $id = 0;

		/** @protected array Raw commission record from database */
		protected $_data = array();

		/** @protected YITH_Vendor Cache */
		protected $_vendor = null;

		/** @protected WP_User Cache */
		protected $_user = null;

		/** @protected WC_Order Cache */
		protected $_order = null;

		/** @protected WC_Product Cache */
		protected $_product = null;

		/** @protected array Cache */
		protected $_item = array();

		/** @protected array Cache */
		protected $_refunds = array();

		/** @private bool Flag to indicate the change properties status. If true, some property is changed and you will update the informations to database when php shutdown */
		private $_changed = false;

		/** @protected array Main Instance */
		protected static $_instance = array();

		/**
		 * Main plugin Instance
		 *
		 * @static
		 *
		 * @param bool|int $commission_id
		 *
		 * @return YITH_Commission Main instance
		 * @since  1.0
		 * @author Andrea Grillo <andrea.grillo@yithemes.com>
		 */
		public static function instance( $commission_id = false ) {
			if ( ! isset( self::$_instance[ $commission_id ] ) || is_null( self::$_instance[ $commission_id ] ) ) {
				self::$_instance[ $commission_id ] = new self( $commission_id );
			}

			return self::$_instance[ $commission_id ];
		}

		/**
		 * Constructor
		 *
		 * @param bool $commission_id
		 *
		 * @return YITH_Commission
		 * @since  1.0.0
		 * @access public
		 */
		public function __construct( $commission_id = false ) {
			if ( ! $commission_id ) {
				return $this;
			}

			// populate instance by data from database
			$this->_populate( intval( $commission_id ) );

			// When leaving or ending page load, store data
			add_action( 'shutdown', array( $this, 'save_data' ), 10 );

			return $this;
        }

		/**
		 * Save data function.
		 */
		public function save_data() {
			if ( ! $this->_changed || empty( $this->_data ) ) {
				return;
			}

			global $wpdb;
			$this->last_edit = current_time( 'mysql' );
			$this->last_edit_gmt = current_time( 'mysql', 1 );
			$wpdb->update( $wpdb->commissions, $this->_data, array( 'ID' => $this->id ) );
		}

		/**
		 * __set function.
		 *
		 * @param mixed $property
		 * @return bool
		 */
		public function __isset( $property ) {
			return isset( $this->_data[ $property ] );
		}

		/**
		 * __get function.
		 *
		 * @param string $property
		 * @return string
		 */
		public function __get( $property ) {
			return isset( $this->_data[ $property ] ) ? $this->_data[ $property ] : '';
		}

		/**
		 * __set function.
		 *
		 * @param mixed $property
		 * @param mixed $value
		 */
		public function __set( $property, $value ) {
			switch ( $property ) {
				case 'order_id' :
					$this->_order = null;
					$value = intval( $value );
					break;

				case 'vendor_id' :
					$this->_vendor = null;
					$value = intval( $value );
					break;

				case 'user_id' :
					$this->_user = null;
					$value = intval( $value );
					break;

				case 'line_item_id' :
					$this->_product = $this->_item = null;
					$value = intval( $value );
					break;
			}

			$this->_data[ $property ] = $value;
			$this->_changed = true;
		}

		/**
		 * Retrieve the record of a commission
		 *
		 * @param $commission_id
		 * @since 1.0
		 */
		protected function _populate( $commission_id ) {
			global $wpdb;
			$this->_data = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $wpdb->commissions WHERE ID = %d", $commission_id ), ARRAY_A );

			if ( ! empty( $this->_data ) ) {
				$this->id = $commission_id;
			}
		}

		/**
		 * Add new record to DB
		 *
		 * @param array $args
		 *
		 * @return YITH_Commission
		 */
		public function add( $args = array() ) {
			global $wpdb;

			$defaults = array(
				'line_item_id'  => 0,
				'order_id'      => 0,
				'user_id'       => 0,
				'vendor_id'     => 0,
				'rate'          => YITH_Vendors()->get_base_commission(),
				'amount'        => 0,
				'status'        => 'pending'
			);

			// set commission by vendor
			if ( ! isset( $args['rate'] ) && isset( $args['vendor_id'] ) ) {
				$vendor = yith_get_vendor( $args['vendor_id'] );
				if ( $vendor->is_valid() ) {
					$defaults['rate'] = $vendor->get_commission();
				}
			}

			$args = wp_parse_args( $args, $defaults );

			$wpdb->insert( $wpdb->commissions, (array) $args );

			return $wpdb->insert_id;
		}

		/**
		 * Remove the commission of this instance from database
		 *
		 * @since 1.0
		 */
		public function remove() {
			if ( ! $this->id ) {
				return;
			}

			global $wpdb;
			$wpdb->delete( $wpdb->commissions, array( 'ID' => $this->id ) );
		}

		/**
		 * Detect if commission ID exists
		 *
		 * @return bool
		 * @since 1.0
		 */
		public function exists() {
			return ! empty( $this->_data );
		}

		/**
		 * Get order object of this commission
		 *
		 * @return WC_Order
		 * @since 1.0
		 */
		public function get_order() {
			if ( $this->_order != null ) {
				return $this->_order;
			}

			if ( empty( $this->order_id ) ) {
				return $this->_order = false;
			}

			return $this->_order = wc_get_order( $this->order_id );
		}

		/**
		 * Get item meta of order of this commission
		 *
		 * @return array
		 * @since 1.0
		 */
		public function get_item() {
			if ( $this->_item != null ) {
				return $this->_item;
			}

			if ( empty( $this->line_item_id ) || empty( $this->order_id ) ) {
				return $this->_item = false;
			}

			// get order
			$order = $this->get_order();

			// get product object
			foreach ( $order->get_items() as $item_id => $item ) {
				if ( $this->line_item_id == $item_id ) {
					return $this->_item = $item;
				}
			}

			return $this->_item = false;
		}

		/**
		 * Get the product object of this commission
		 *
		 * @return WC_Product
		 * @since 1.0
		 */
		public function get_product() {
			if ( $this->_product != null ) {
				return $this->_product;
			}

			if ( $item = $this->get_item() ) {
				$order = $this->get_order();

				return $this->_product = $order->get_product_from_item( $item );
			}

			return $this->_product = false;
		}

		/**
		 * Get vendor object of this commission
		 *
		 * @return YITH_Vendor
		 * @since 1.0
		 */
		public function get_vendor() {
			if ( $this->_vendor != null ) {
				return $this->_vendor;
			}

			if ( empty( $this->vendor_id ) ) {
				return $this->_vendor = false;
			}

			return $this->_vendor = yith_get_vendor( $this->vendor_id );
		}

		/**
		 * Get user object of this commission
		 *
		 * @return WP_User
		 * @since 1.0
		 */
		public function get_user() {
			if ( $this->_user != null ) {
				return $this->_user;
			}

			if ( empty( $this->user_id ) ) {
				return $this->_user = false;
			}

			return $this->_user = get_user_by( 'id', $this->user_id );
		}

		/**
		 * Retrieve status
		 *
		 * @param bool|string $context
		 *
		 * @return string
		 * @since 1.0
		 */
        public function get_status( $context = false ) {
            if ( $context == 'display' ) {
                $all_status = YITH_Commissions()->get_status();
                return $all_status[ $this->status ];
            } else {
                return $this->status;
            }
        }

		/**
		 * Get the date of commission, corresponding to order date
		 *
		 * @param string $context
		 *
		 * @return string
		 * @since 1.0
		 */
		public function get_date( $context = '' ) {
			$order = $this->get_order();

			if ( 'display' == $context ) {
				return date_i18n( 'M j, Y - h:i', strtotime( $order->order_date ) );
			}

			return $order->order_date;
		}

		/**
		 * Get amount of commission
		 *
		 * @param string $context
		 * @since 1.0
		 * @return mixed
		 */
		public function get_amount( $context = '' ) {
			return 'display' == $context ? wc_price( $this->amount ) : $this->amount;
		}

		/**
		 * Get the commission rate
		 *
		 * @param string $context
		 * @since 1.0
		 * @return mixed
		 */
		public function get_rate( $context = '' ) {
			return 'display' == $context ? sprintf( '%d%%', $this->rate * 100 ) : $this->rate;
		}

		/**
		 * Retrieve the URL for viewing the commission details
		 *
		 * @param string $context
		 *
		 * @return string
		 */
		public function get_view_url( $context = '' ) {
			if ( 'admin' == $context ) {
				$url = remove_query_arg( 'view' );
				return add_query_arg( 'view', $this->id, $url );
			}
			else {
				return '';
			}
		}

        /**
		 * Change status of commissions
         *
         * WC Order Status  ->  YITH Commissions Status
         * pending          ->  pending
         * processing       ->  pending
         * on-hold          ->  unpaid
         * completed        ->  paid
         * cancelled        ->  cancelled
         * failed           ->  cancelled
         * refunded         ->  refunded
		 *
		 * @param string $new_status
         * @return bool
		 */
		public function update_status( $new_status, $note = '' ) {
			if ( ! $this->id ) {
				return false;
			}

			$old_status = $this->status;
			if ( YITH_Commissions()->is_status_changing_permitted( $new_status, $old_status ) ) {

                $this->status = $new_status;

				$all_status = YITH_Commissions()->get_status();
				$this->add_note( trim( $note . ' ' . sprintf( __( 'Commission status changed from %s to %s.', 'yith_wc_product_vendors' ), $all_status[ $old_status ], $all_status[ $new_status ] ) ) );

				// Status was changed
				do_action( 'yith_commission_status_' . $new_status, $this->id );
				do_action( 'yith_commission_status_' . $old_status . '_to_' . $new_status, $this->id );
				do_action( 'yith_commission_status_changed', $this->id, $old_status, $new_status );

				switch ( $new_status ) {

					case 'unpaid' :
						YITH_Commissions()->update_credit_to_user( $this->user_id, $this->amount );
						break;

					case 'paid' :
					case 'cancelled' :
					case 'refunded' :
                    case 'pending':
                        if( 'unpaid' == $old_status ) {
                            YITH_Commissions()->update_credit_to_user( $this->user_id, -$this->amount );
                        }
						break;
				}

                return true;
			}

            return false;
		}

		/**
		 * Change amount to the commission and user associated
		 *
		 * @param $amount
		 * @param string $note
		 *
		 * @since 1.0
		 */
		public function update_amount( $amount, $note = '' ) {

			// change amount to this commission
			$this->amount += $amount;

			// add a note
			$this->add_note( $note );

			// update credit into the user
			YITH_Commissions()->update_credit_to_user( $this->user_id, $amount );
		}

		/**
		 * Specific method to set the commission as paid
		 *
		 * @since 1.0
		 */
		public function set_paid() {
			$this->update_status( 'paid' );
		}

		/**
		 * Add a note for the commission
		 *
		 * @param string $msg
		 *
		 * @since 1.0
		 */
		public function add_note( $msg = '' ) {
			global $wpdb;

			if ( empty( $msg ) ) {
				return;
			}

			$wpdb->insert( $wpdb->commissions_notes, array(
				'commission_id' => $this->id,
				'description' => $msg,
				'note_date' => date( 'Y-m-d H:i:s' )
			) );
		}

		/**
		 * Get all notes of commission
		 *
		 * @return array
		 * @since 1.0
		 */
		public function get_notes() {
			global $wpdb;

			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $wpdb->commissions_notes WHERE commission_id = %d ORDER BY note_date DESC", $this->id ) );
		}

		/**
		 * Retrieve the refunds from the order meta
		 *
		 * @return array
		 */
		public function get_refunds() {
			if ( $this->_refunds != null ) {
				return $this->_refunds;
			}

			$order = $this->get_order();

			// retrieve refunds from order
			/** @var $refund WC_Order_Refund */
			foreach ( $order->get_refunds() as $refund ) {
				$commissions_refunded = get_post_meta( $refund->id, '_refunded_commissions', true );
				if ( isset( $commissions_refunded[ $this->id ] ) ) {
					$this->_refunds[ $refund->id ] = $commissions_refunded[ $this->id ];
				}
			}

			return (array) $this->_refunds;
		}

		/**
		 * Retrieve the total amount refunded
		 *
		 * @param string $context
		 *
		 * @return number
		 */
		public function get_refund_amount( $context = '' ) {
			$refunds = $this->get_refunds();
			$amount = array_sum( $refunds );

			return 'display' == $context ? wc_price( $amount ) : $amount;
		}
	}
}

/**
 * Main instance of plugin
 *
 * @return YITH_Commission
 * @since  1.0
 */
if ( ! function_exists( 'YITH_Commission' ) ) {
	/**
	 * @param bool $commission_id
	 *
	 * @return YITH_Commission
	 */
	function YITH_Commission( $commission_id = false ) {
        return YITH_Commission::instance( intval( $commission_id ) );
    }
}
