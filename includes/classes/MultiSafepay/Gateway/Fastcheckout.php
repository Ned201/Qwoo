<?php

/**
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade the MultiSafepay plugin
 * to newer versions in the future. If you wish to customize the plugin for your
 * needs please document your changes and make backups before you update.
 *
 * @category    MultiSafepay
 * @package     Connect
 * @author      TechSupport <techsupport@multisafepay.com>
 * @copyright   Copyright (c) 2017 MultiSafepay, Inc. (http://www.multisafepay.com)
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED,
 * INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR
 * PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT
 * HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN
 * ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION
 * WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 */
class MultiSafepay_Gateway_Fastcheckout extends MultiSafepay_Gateway_Abstract
{

    public static function getCode()
    {
        return;
    }

    public static function getName()
    {
        return;
    }

    public static function getTitle()
    {
        return;
    }

    public static function getGatewayCode()
    {
        return;
    }

    public function getItemsFCO()
    {
        $items = array();
        foreach (WC()->cart->get_cart() as $values) {
            $items[] = array('name' => $values['data']->get_title(), 'qty' => $values['quantity']);
        }
        return ($items);
    }

    public function setCartFCO()
    {

        $shopping_cart = array();
        foreach (WC()->cart->get_cart() as $values) {

            $_product = $values['data'];

            $qty = absint($values['quantity']);
            $sku = $_product->get_sku();
            $id = $_product->get_id();

            $name = html_entity_decode($_product->get_title(), ENT_NOQUOTES, 'UTF-8');
            $descr = html_entity_decode(get_post($id)->post_content, ENT_NOQUOTES, 'UTF-8');

            if ($_product->get_type() == 'variation') {
                $meta = WC()->cart->get_item_data($values, true);

                if (empty($sku))
                    $sku = $_product->parent->get_sku();

                if (!empty($meta))
                    $name .= " - " . str_replace(", \n", " - ", $meta);
            }

            $product_price = $values['line_subtotal'] / $qty;
            $percentage = round($values['line_subtotal_tax'] / $values['line_subtotal'], 2);

            $json_array = array();
            $json_array['sku'] = $sku;
            $json_array['id'] = $id;

            $shopping_cart['items'][] = array(
                'name' => $name,
                'description' => $descr,
                'unit_price' => $product_price,
                'quantity' => $qty,
                'merchant_item_id' => json_encode($json_array),
                'tax_table_selector' => 'Tax-' . $percentage,
                'weight' => array('unit' => '0', 'value' => 'KG')
            );
        }


        // Add custom Woo cart fees as line items
        foreach (WC()->cart->get_fees() as $fee) {
            if ($fee->tax > 0)
                $fee_tax_percentage = round($fee->tax / $fee->amount, 2);
            else
                $fee_tax_percentage = 0;

            $json_array = array();
            $json_array['fee'] = $fee->name;

            $shopping_cart['items'][] = array(
                'name' => $fee->name,
                'description' => $fee->name,
                'unit_price' => number_format($fee->amount, 2, '.', ''),
                'quantity' => 1,
                'merchant_item_id' => json_encode($json_array),
                'tax_table_selector' => 'Tax-' . $fee_tax_percentage,
                'weight' => array('unit' => '', 'value' => 'KG')
            );
        }

        // Get discount(s)
        foreach (WC()->cart->applied_coupons as $code) {

            $unit_price = WC()->cart->coupon_discount_amounts[$code];
            $unit_price_tax = WC()->cart->coupon_discount_tax_amounts[$code];
            $percentage = round($unit_price_tax / $unit_price, 2);

            $json_array = array();
            $json_array['Coupon-code'] = $code;

            $shopping_cart['items'][] = array(
                'name' => 'Discount Code: ' . $code,
                'description' => '',
                'unit_price' => -round($unit_price, 5),
                'quantity' => 1,
                'merchant_item_id' => json_encode($json_array),
                'tax_table_selector' => 'Tax-' . ($percentage * 100),
                'weight' => array('unit' => '', 'value' => 'KG')
            );
        }

        return ($shopping_cart);
    }

    public function setCheckoutOptionsFCO()
    {

        $checkout_options = array();
        $checkout_options['no_shipping_method'] = false;
        $checkout_options['use_shipping_notification'] = true;
        $checkout_options['tax_tables']['alternate'] = array();
        $checkout_options['tax_tables']['default'] = array('shipping_taxed' => 'true', 'rate' => '0.21');

        foreach (WC()->cart->get_cart() as $values) {
            $percentage = round($values['line_subtotal_tax'] / $values['line_subtotal'], 2);
            array_push($checkout_options['tax_tables']['alternate'], array('name' => 'Tax-' . $percentage, 'rules' => array(array('rate' => $percentage))));
        }

        /* Get CartFee tax */
        foreach (WC()->cart->get_fees() as $fee) {
            if ($fee->tax > 0)
                $fee_tax_percentage = round($fee->tax / $fee->amount, 2);
            else
                $fee_tax_percentage = 0;

            array_push($checkout_options['tax_tables']['alternate'], array('name' => 'Tax-' . $fee_tax_percentage, 'rules' => array(array('rate' => $fee_tax_percentage / 100))));
        }

        /* Get discount(s) tax    */
        if (WC()->cart->get_cart_discount_total()) {
            array_push($checkout_options['tax_tables']['alternate'], array('name' => 'Tax-0', 'rules' => array(array('rate' => '0.00'))));
        }

        return ($checkout_options);
    }

    public function get_shipping_methods_xml()
    {
        $data = filter_input_array(INPUT_GET, FILTER_SANITIZE_STRING);

        $shipping_methods = $this->get_shipping_methods($data);

        $outxml = '<?xml version="1.0" encoding="UTF-8"?>';
        $outxml .= '<shipping-info>';
        foreach ($shipping_methods as $rate) {

            $id     = explode (':', $rate->id);
            $price  = number_format($rate->cost, '2', '.', '');

            $outxml .= '<shipping>';
            $outxml .= '<shipping-name>' . htmlentities($rate->label) . '</shipping-name>';
            $outxml .= '<shipping-cost currency="' . get_woocommerce_currency() . '">' . $price . '</shipping-cost>';
            $outxml .= '<shipping-id>' . $id[1] . '</shipping-id>';
            $outxml .= '</shipping>';
        }
        $outxml .= '</shipping-info>';

        return $outxml;
    }

    public function get_shipping_methods($data)
    {
        $shipping_packages = $this->get_shipping_packages($data);

        WC()->shipping->calculate_shipping($shipping_packages);

        return ( WC()->shipping->packages[0]['rates']);
    }

    private function get_shipping_packages($data=null)
    {
        $packages = array();
        $package['contents']                 = WC()->cart->cart_contents;            // Items in the package
        $package['destination']['city']      = WC()->customer->get_shipping_city();
        $package['applied_coupons']          = WC()->session->applied_coupon;
        $package['destination']['state']     = WC()->customer->get_shipping_state();
        $package['destination']['address']   = WC()->customer->get_shipping_address();
        $package['destination']['address_2'] = WC()->customer->get_shipping_address_2();
        $package['destination']['country']   = WC()->customer->get_shipping_country();
        $package['destination']['postcode']  = WC()->customer->get_shipping_postcode();

        $package['contents_cost'] = 0;                                    // Cost of items in the package, set below
        foreach (WC()->cart->get_cart() as $item)
            if ($item['data']->needs_shipping())
                if (isset($item['line_total']))
                    $package['contents_cost'] += $item['line_total'];

        if ($data){
            $package['destination']['country']   = $data['countrycode'];
            $package['destination']['postcode']  = $data['zipcode'];
            $package['contents_cost']            = $data['amount'];;
        }

        array_push($packages, $package);

        return apply_filters('woocommerce_cart_shipping_packages', $packages);
    }

    function create_qwindo_order($qwindo) {
        global $woocommerce;

        $customer = $qwindo['customer'];
        $delivery = $qwindo['delivery'];
        $items    = $qwindo['shopping_cart']['items'];

        // Get the first (and only) element of the shipping array
        $shipping = reset ($qwindo['order_adjustment']['shipping']);

        $billing_address = array(
            'first_name' => $customer['first_name'],
            'last_name'  => $customer['last_name'],
            'company'    => '',
            'email'      => $customer['email'],
            'phone'      => $customer['phone1'],
            'address_1'  => $customer['address1'],
            'address_2'  => $customer['house_number'],
            'city'       => $customer['city'],
            'state'      => '',
            'postcode'   => $customer['zip_code'],
            'country'    => $customer['country']
        );

        $shipping_address = array(
            'first_name' => $delivery['first_name'],
            'last_name'  => $delivery['last_name'],
            'company'    => '',
            'email'      => $delivery['email'],
            'phone'      => $delivery['phone1'],
            'address_1'  => $delivery['address1'],
            'address_2'  => $delivery['house_number'],
            'city'       => $delivery['city'],
            'state'      => '',
            'postcode'   => $delivery['zip_code'],
            'country'    => $delivery['country']
        );
        $order = wc_create_order();

        // Compatiblity Woocommerce 2.x and 3.x
        $orderID     = (method_exists($order,'get_id'))     ? $order->get_id()      : $order->id;

        if (!$orderID)
            return false;


        $order->set_address($billing_address,  'billing');
        $order->set_address($shipping_address, 'shipping');

        // Add shipping method
        foreach ($woocommerce->shipping->load_shipping_methods() as $shipping_method) {
            if ( strpos($shipping['provider'], $shipping_method->id) !== false ){

                // Add shipping costs
                $shipping_taxes = WC_Tax::calc_shipping_tax($shipping['cost'], WC_Tax::get_shipping_tax_rates());

                $rate = new WC_Shipping_Rate(   $shipping_method->id,
                    isset($shipping['name']) ? $shipping['name'] : '',
                    isset($shipping['cost']) ? floatval($shipping['cost']) : 0,
                    $shipping_taxes,
                    $shipping_method->id);

                $item = new WC_Order_Item_Shipping();
                $item->set_props(array('method_title' => $rate->label, 'method_id' => $rate->id, 'total' => wc_format_decimal($rate->cost), 'taxes' => $rate->taxes, 'meta_data' => $rate->get_meta_data()));
                $order->add_item($item);
                break;
            }

        }

        // Add payment method
        $gateways = new WC_Payment_Gateways();
        $all_gateways = $gateways->get_available_payment_gateways();

        // Set default
        $selected_gateway = 'MultiSafepay';
        foreach ($all_gateways as $gateway) {
            if ($gateway->id == strtolower ( 'multisafepay_' . $qwindo['payment_details']['type'])) {
                $selected_gateway = $gateway;
                break;
            }
        }
        $order->set_payment_method($selected_gateway);

        // Add items
        foreach ($items as $item){
            $product_id = $item['merchant_item_id'];

            if ($product_id){
                $product = wc_get_product($product_id);
                $order->add_product($product, $item['quantity'], array ('variation_id' => $product->product_id));
            }
        }
        $order->calculate_taxes();
        $order->calculate_totals();


        foreach ($order->get_items('tax') as $key => $value) {
            $data = wc_get_order_item_meta($key, 'tax_amount');
            wc_update_order_item_meta($key, 'tax_amount', $data);
        }


        if ($qwindo['status'] == 'completed'){
            $order->add_order_note(sprintf(__('Multisafepay payment status %s', 'multisafepay'), $qwindo['status']));
            $order->payment_complete();
        }else{
            $order->update_status($qwindo['status'], 'Imported order', TRUE);
        }
        return true;
    }
}
