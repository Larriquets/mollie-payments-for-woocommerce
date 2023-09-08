<?php
namespace Mollie\WooCommerce\Payment;
use Mollie\Api\Endpoints\MethodEndpoint;
use Mollie\WooCommerce\Settings\Settings;

class Mollie_SplitOrder {
    public const PAYMENT_RELEASE_DAYS = 30;
    /**
     * Return array of routing for 
     */
    public static function getOrderRouting($order) {

		$logger = wc_get_logger();
		$context = array( 'source' => 'Mollie_SplitOrder' );
		$logger->debug( 'Respuesta obtenida', $context );


        $routes = [];
        $sellers = [];
        $totals_by_vendor = [];
        $coupons_discounts_by_vendor = [];
        $shipping_by_vendor = [];
        $saleAndShippingTotal_by_vendor = []; //sales total and shipping amount by vendor
        $commission_type = 'from_vendor'; //TODO: ver default-> es vendor el que paga

        $sellers_count = 0;
        $gift_cards_totals = 0;

        if($order) {   

            //Items
            $order_id = $order->get_id();
            $items = $order->get_items();
            $releaseDate = self::getReleaseDate();
            $currency = $order->get_currency();

            //Payment method
            $payment_method = $order->get_payment_method();

            $payment_method_fee = self::getPaymentMethodFee($payment_method);
			
			// $logger->debug( '$order : '. $order , $context );
			$logger->debug( '$order_id : '. $order_id , $context );
            $logger->debug( '$releaseDate : '. print_r($releaseDate,true) , $context );
            $logger->debug( '$currency : '. print_r($currency,true) , $context );
            $logger->debug( '$payment_method : '. $payment_method , $context );
            $logger->debug( '$payment_method_fee : '. print_r($payment_method_fee,true) , $context );
			
            //Coupons           
            foreach( $order->get_coupons() as $coupon ){
                $coupon_meta = current( $coupon->get_meta_data() );

                if ( ! isset( $coupon_meta->get_data()['value'] ) ) {
                    continue;
                }

                $coupon_meta = dokan_get_coupon_metadata_from_order( $coupon_meta->get_data()['value'] );

                // Get the WC_Coupon object                
                $discount_type = $coupon->get_type(); 
                $discount_amount = $coupon->get_discount(); 
                
                if ( isset($coupon_meta['coupon_commissions_type'])) {
                    $commission_type = $coupon_meta['coupon_commissions_type'];
                }
                
                //from_admin / shared_coupon
                /*
                if( $commission_type == 'from_vendor') { 
                    $coupons_discounts_by_vendor[] = $discount_amount;
                }
                */
            }

            //Split the order items by vendor
            foreach($items as $cart_item) {
                if ( $cart_item['quantity'] ) {                
                    $seller_id = get_post_field( 'post_author', $cart_item['product_id'] );

                    if ( ! empty( $seller_id ) ) {
                        $sellers[ $seller_id ][] = $cart_item;
                    }
                }
            }
                
			
			
			
			
			
            // GET GIFTCARD TOTAL IF EXIST 16/NOV/2022
            foreach($sellers as $seller_id => $seller_items) {
                $sellers_count += 1;
            }    
			foreach ( $order->get_meta_data() as $meta ) {
				if ( $meta->key == '_ywgc_applied_gift_cards_totals' ) {
                    // $gift_cards_totals = $meta->value / $sellers_count;
                    $gift_cards_totals = $meta->value;
				} 
			}

            $logger->debug( '$gift_cards_totals : '. print_r($gift_cards_totals,true) , $context );
			
			
			
            foreach($sellers as $seller_id => $seller_items) {
                $vendor_total = 0;
                foreach($seller_items as $seller_item) {

                    if($commission_type == 'from_admin') {
                        $vendor_total+= $seller_item->get_subtotal(); //total without coupon applied, because Bluehouse pay the discount
                    } elseif($commission_type == 'from_vendor') {
                        $vendor_total+= $seller_item->get_total();
                    } else {
                        $vendor_total+= $seller_item->get_total();
                    }
                }
				
				// NEW
				// $totals_by_vendor[$seller_id] = $vendor_total;
			    // $logger->debug( '$totals_by_vendor : '. $totals_by_vendor , $context );
				$totals_by_vendor[$seller_id] = ($vendor_total - $gift_cards_totals);
				$logger->debug( '$gift_cards_totals : '. print_r($gift_cards_totals,true) , $context );
				$logger->debug( '$totals_by_vendor - GIFT : '. print_r($totals_by_vendor,true) , $context );
				
            }    


			
			
			
			

            //Split shipping - Shipping amounts by vendor
            foreach ( $order->get_shipping_methods() as $item_id => $item ) {
                //Vendor has their shipping method, so we need to route the shipping amount to their account
                $seller_id = 0;
                if($item->get_method_id() == 'dokan_vendor_shipping') {
                    foreach ( $item->get_meta_data() as $meta ) {
						
                        if ( $meta->key == 'seller_id' ) {
                            $seller_id = $meta->value;
                        } 
                    }
                    $shipping_by_vendor[$seller_id] = $item->get_total();
                }                         
            }

            //Summarize vendor total + shipping, in order to calculate payment method fee
            //Total items by vendor
            foreach($totals_by_vendor as $seller_id => $vendor_total) {
                $saleAndShippingTotal_by_vendor[$seller_id] = $vendor_total;
                if(isset($shipping_by_vendor[$seller_id])) {
                    $saleAndShippingTotal_by_vendor[$seller_id] += $shipping_by_vendor[$seller_id];
                }
            }

            //Calculate payment methods fee by vendor (sales)
            $vendors_payment_fee = self::getPaymentMethodFeeByVendor($saleAndShippingTotal_by_vendor, $payment_method_fee);

			$logger->debug( '$vendors_payment_fee : '. print_r($vendors_payment_fee,true) , $context );


            //Total items by vendor
            foreach($totals_by_vendor as $seller_id => $vendor_total) {
                $organizationId = self::getOrganizationIdByVendorId($seller_id);
                
                // organizationId_custom, solo para test
                // $organizationId_custom =  get_user_meta( $seller_id, 'mollie_organization_id', true );
                // $logger->debug( '$organizationId_custom : '. print_r($organizationId_custom,true) , $context );

                $logger->debug( '$seller_id : '. print_r($seller_id,true) , $context );

                $logger->debug( '$organizationId : '. print_r($organizationId,true) , $context );

                if( !is_null($organizationId) ) { 
                    if( $gift_cards_totals != 0) {
                        $logger->debug( '$gift_cards_totals != 0 ', $context );
                    }else{
                        $logger->debug( '$gift_cards_totals = 0 ', $context );
                    }

                    if(  $gift_cards_totals == 0 ){


                            //Apply Bluehouse fee
                            $amount = self::applyBluehouseFeeToVendor($vendor_total);
                            
                            //Apply payment method fee
                            if(array_key_exists($seller_id, $vendors_payment_fee)) {
                                $amount -= $vendors_payment_fee[$seller_id];
                            }
                            
                            //Add shipping total for vendor
                            if(array_key_exists($seller_id, $shipping_by_vendor)) {
                                $amount += $shipping_by_vendor[$seller_id];
                            }

                            $routes[] = [
                                "amount" => [                    
                                    "currency" => $currency,
                                    "value" => $this->formatCurrencyValue( $amount, $currency)
                                ],
                                "destination" => [
                                    "type" => "organization",
                                    "organizationId" => $organizationId
                                ],
                                "releaseDate" => $releaseDate         
                            ];

                            $logger->debug( 'SPLIT --------- $routes : '. print_r($routes,true) , $context );

                    }else{
                        $logger->debug( 'NOT null($gift_cards_totals) ', $context );
                    }


                }else{
                    $logger->debug( 'is_null($organizationId) ', $context );
                }
            }
        }

        $logger->debug( 'Return $routes : '. print_r($routes,true) , $context );

        return $routes;
    }

    /**
     * Returns payment method's fees for each vendor in the order
     */
    public static function getPaymentMethodFeeByVendor($totals_by_vendor, $payment_method_fee) {
        $vendors_payment_fee = [];

        if( $payment_method_fee ) {
            //Initialize
            foreach($totals_by_vendor as $seller_id => $vendor_total) {
                $vendors_payment_fee[$seller_id] = 0;
            }  

            //Fixed 
            foreach($totals_by_vendor as $seller_id => $vendor_total) {
                $vendors_payment_fee[$seller_id] += $payment_method_fee['fixed'] / count($totals_by_vendor);
            }       
            //Variable 
            if(isset($payment_method_fee['variable']) && $payment_method_fee['variable'] > 0) {
                foreach($totals_by_vendor as $seller_id => $vendor_total) {
                    $vendors_payment_fee[$seller_id] += $vendor_total * ($payment_method_fee['variable'] / 100);
                }       
            }            
        }

        return $vendors_payment_fee;
    }

    public static function getPaymentMethodFee($payment_method_id) {
        $payment_method_fee = [];
        if($payment_method_id == 'mollie_wc_gateway_ideal') {
            $payment_method_fee = [
                'fixed' => 0.3025,//0,25 + VAT
                'variable' => 0,
            ];
        } elseif ($payment_method_id == 'mollie_wc_gateway_creditcard') {
            $payment_method_fee = [
                'fixed' => 0.242, //0,2 + VAT
                'variable' => 1.8
            ];
        }

        return $payment_method_fee;
    }
    
    /**
     * Return vendor's amount with a payment method fee applied
     */
    public static function applyPaymentMethodFeeToVendor($vendor_total, $payment_method_fee) {    
        
        if($payment_method_fee > 0) {
            $vendor_total -= $payment_method_fee;
        }
        return $vendor_total;

    }

    /**
     * Return vendor's amount with a fee applied
     */
    public static function applyBluehouseFeeToVendor($vendor_total) {    
        $settingsHelper = $this->settingsHelper->getSettingsHelper();
        $fee = $settingsHelper->getBluehouseFee(); //eg. 10 (10%)

        if($fee) {
            $vendor_total -= $vendor_total * (1/$fee);
        }

        return $vendor_total;

    }

    /**
     * Get formatted release date for routed payments from Mollie Settings
     */
    public static function getReleaseDate() {
        // $settingsHelper = $this->settingsHelper->getSettingsHelper();
        // $releaseDays = $settingsHelper->getReleaseDays();
          //if test/live keys are in db return
          $releaseDays = get_option('mollie-payments-for-woocommerce_payment_release_days');

        // if($releaseDays && $releaseDays > 0) {
        //     $releaseDate = new DateTime();
        //     $releaseDate->add(new DateInterval("P{$releaseDays}D"));
    
        //     return $releaseDate->format('Y-m-d');
        // }

        return $releaseDays;       
    }

    /**
     * Return organization Id based on Seller ID, saved during onboarding vendors
     */
    public static function getOrganizationIdByVendorId($seller_id) {
        if( $seller_id && $seller_id != 41) { 
            $mollie_org_id =  get_user_meta( $seller_id, 'mollie_organization_id', true );

            if($mollie_org_id) {
                return $mollie_org_id;
            }                
        }
       
        return null;
    }

    /**
     * Get payment method fees from Mollie API
     */
    public static function getPaymentMethodsFees() {
        $methods_cleaned = [];
        // Is test mode enabled?
        $test_mode = mollieWooCommerceIsTestModeEnabled();
        $api = $this->getApiClient($test_mode);
        $methods = $api->methods->allActive(['include' => 'pricing']);

        foreach ( $methods as $method ) {
            $public_properties = get_object_vars( $method ); // get only the public properties of the object
            $methods_cleaned[] = $public_properties;
        }

        return $methods_cleaned;
    }
}