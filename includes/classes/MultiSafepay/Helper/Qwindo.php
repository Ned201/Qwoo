<?php

function feeds()
{
    $params = filter_input_array(INPUT_GET, FILTER_SANITIZE_STRING);

    define ('QWINDO_KEY', get_option('multisafepay_qwindo_api_key'));
    define ('HASH_ID'   , get_option('multisafepay_qwindo_hash_id'));

    $qwindo = new Qwindo();
    $result = null;

    if ($params['identifier'] != 'shipping'){

        global $wp;
        $url = home_url( add_query_arg( array(), $wp->request ));
        if ($_SERVER['QUERY_STRING']){
            $url .= '?'. $_SERVER['QUERY_STRING'];
        }


        $header     = $qwindo->get_nginx_headers();
        $timestamp  = microtime(true);
        $auth       = explode('|', base64_decode($header['Auth']));

        $message    = $url.$auth[0] . HASH_ID;
        $token      = hash_hmac('sha512', $message, QWINDO_KEY);

        if($token !== $auth[1] || round($timestamp - $auth[0]) > 10){
            $result = '{"success": false,
                        "data": {
                            "error_code": "QW-3000",
                            "error": __("Signature error", "multisafepay")
                        }}';
        }
    }

    if (!$result) {
        switch ($params['identifier']) {

            case 'total_products':
                $result = $qwindo->total_products();
                break;

            case 'categories':
                $result = $qwindo->categories();
                break;

            case 'stores':
                $result = $qwindo->stores();
                break;

            case 'shipping':
                $result = $qwindo->shipping($params);
                break;

            case 'stock':
                if (isset($params['product_id'])) {
                    $result = $qwindo->stock($params['product_id']);
                    break;
                }

                if (isset($params['variant_id'])) {
                    $result = $qwindo->stock($params['variant_id']);
                } else {
                    $result = array('Invalid params supplied');
                }
                break;

            case 'products':
                if (isset($params['product_id'])) {
                    $result = $qwindo->productById($params['product_id']);
                    break;
                }

                if (isset($params['offset']) && isset($params['limit'])) {
                    $result = $qwindo->productByRange($params['limit'], $params['offset']);
                } else {
                    $result = array('Invalid params supplied');
                }
                break;

            default:
                $result = '{"success": false,
                            "data": {
                                "error_code": "QW-1000",
                                "error": __("Identifier not set", "multisafepay")
                            }}';
        }
    }

    $json   = $qwindo->createJSON ($result);
    die ($json);
}




class qwindo {

    function createJSON ($result){

        if (is_array ($result)) {
            $json = json_encode($result);
            $json = utf8_encode($json);
        }else{
            $json = $result;
        }


        //
        //todo: Remove when developing is done
//        echo print_r ( $result, true) . PHP_EOL;
//        echo $json . PHP_EOL;


        return (gzcompress($json));
    }

    function stores(){

        $Countries = new WC_Countries;

        $base_tax_rate = WC_Tax::get_base_tax_rates();
        $base_tax_rate  = array_shift ( $base_tax_rate );

        $store = array();
        $store['allowed_countries']     = $this->getCountries ( $Countries->get_allowed_countries());
        $store['shipping_countries']    = $this->getCountries ( $Countries->get_shipping_countries());
        $store['languages']             = array(get_locale() => '');
        $store['stock_updates']         = get_option('woocommerce_manage_stock') == 'yes'       ? true : false;
        $store['allowed_currencies']    = array(get_woocommerce_currency());
        $store['including_tax']         = get_option('woocommerce_prices_include_tax') == 'yes' ? true : false;
        $store['default_tax']           = array (
                                            'id'    =>  1,
                                            'name'  => $base_tax_rate['label'],
                                            'rate'  => $base_tax_rate['rate']
                                          );
        $store['shipping_tax']          = array (
                                            'id'    =>  1,
                                            'name'  =>  $base_tax_rate['label'],
                                            'rules' => array ( WC()->countries->get_base_country() => wc_format_decimal ($base_tax_rate['rate'], 4))
                                          );
        $store['require_shipping']      = wc_shipping_enabled() ? true : false;
        $store['base_url']              = get_home_url();
        $store['country']               = WC()->countries->get_base_country();

        $store['order_push_url']        = add_query_arg('action', 'doFastCheckout', add_query_arg('wc-api', 'MultiSafepay_Gateways', home_url('/')));
        $store['rounding_policy']       = get_option('multisafepay_rounding');
        $store['tax_calculation']       = 'total';
        $store['shipping_request_type'] = 'JSON';

        return ($store);
    }

    function total_products(){

        $result = array();
        $productCount = wp_count_posts( 'product' );
        if ($productCount){
            $result = array('total'   => $productCount->publish);
        }

        return($result);
    }

    function productById($product_id=0){

        $result = array();
        $product = WC()->product_factory->get_product($product_id);
        if ($product){
            $result = $this->get_product_details($product);
        }
        return($result);
    }

    function productByRange($limit=25, $offset=0){

        $result = array();
        $args    = array(
            'post_type'             => 'product',
            'post_status'           => 'publish',
            'ignore_sticky_posts'   => 1,
            'posts_per_page'        => $limit,
            'offset'                => $offset,
            'tax_query'=> array(
                array(
                    'taxonomy' => 'product_type',
                    'field'    => 'slug',
                    'terms'    => array ('variable', 'simple'),
                )
            )
        );

        $products = new WP_Query($args);

        while ($products->have_posts()) {

            $products->the_post();

            $id        = get_the_ID();
            $_product  = WC()->product_factory->get_product($id);

            $result[] = $this->get_product_details($_product);

        }
        return ($result);
    }

    function stock($product_id = 0){

        $result = array();
        $product = WC()->product_factory->get_product($product_id);
        if ($product) {
            $result = array(
                'product_id'   => $product->get_id(),
                'stock'        => (int)$product->get_stock_quantity());
        }
        return ($result);
    }

    function categories(){

        $args = array(
            'taxonomy'      => 'product_cat',
            'orderby'       => 'name',
            'order'         => 'ASC',
            'hierarchical'  => true,
            'hide_empty'    => false,
        );

        $categories = get_categories ($args);
        $result = $this->get_cat_tree(0,$categories);

        return ($result);
    }

    function get_cat_tree($parent,$categories) {
        $result = array();
        foreach($categories as $category){

            if ($parent != $category->parent) {
                continue;
            }

            $result[$category->term_id]['id']     = $category->term_id;
            $result[$category->term_id]['title']  = array(get_locale() => $category->name);

            $result[$category->term_id]['active'] = true;
            $result[$category->term_id]['hidden'] = false;
            $result[$category->term_id]['anchor'] = false;
            $result[$category->term_id]['cashback'] = (int)0;

            $category->children = $this->get_cat_tree($category->term_id, $categories);
            if ( $category->children ){
                $result[$category->term_id]['children'] = $category->children;
            }
        }
        return $result;
    }

    function shipping($params=array()) {

        header('Content-type: application/json');
        $JsonResponse = json_decode( file_get_contents("php://input"));

        if ( $JsonResponse){
            $shipping_methods = $this->get_specific_shipping_methods($JsonResponse);
        }else{
            $shipping_methods = $this->get_global_shipping_methods($params);
        }

        return $shipping_methods;
    }

    private function get_specific_shipping_methods($JsonResponse) {

        global $woocommerce;

        // Empty cart
        $woocommerce->cart->empty_cart();

        // Fill cart with items from json response
        $_items = $JsonResponse->shopping_cart->items;

        foreach ($_items as $_item){
            $woocommerce->cart->add_to_cart($_item->merchant_item_id, $_item->quantity);
        }

        $active_methods = $this->doShipping();
        return $active_methods;
    }

    private function doShipping () {

        $active_methods = array();

        $packages = WC()->shipping->get_packages();

        foreach ( $packages as $i => $package ) {

            foreach ($package['rates'] as $shipping_method) {

                if (count ($shipping_method->taxes) >0 ){
                    $tax_amount = $shipping_method->taxes[1];
                    $rate = $tax_amount / ($shipping_method->cost /100);
                }else{
                    $rate = 0;
                }

                list ($name, $id) = explode (':', $shipping_method->id);
                $active_methods[] = array(  'id'        => $id,
                    'type'      => $name,
                    'provider'  => $shipping_method->id,
                    'name'      => $shipping_method->label,
                    'price'     => $shipping_method->cost,
                    'tax'       => array ('name' => $name,
                        'id'   => $id,
                        'rate' => wc_format_decimal ($rate, 4))
                );
            }
        }

        return ($active_methods);
    }

    private function get_global_shipping_methods($params=array()) {

        $active_methods = array();
        $delivery_zones = WC_Shipping_Zones::get_zones();
        foreach ((array) $delivery_zones as $key => $the_zone ) {
            $id 		= $the_zone['id'];
            $name 		= $the_zone['zone_name'];

            $methods  = $the_zone['shipping_methods'];

            foreach ($methods as $method){
                $provider = $method->id;
                $type = $method->method_title;

                $settings = $method->instance_settings;

                // Check if there is a minimal amount to activate this shipping method (Free shipping)
                if ( isset ($settings['min_amount'])){
                    $amount = isset ($params['amount']) ? $params['amount']/100 : 0;
                    if ($amount >= $settings['min_amount']){
                        $cost = 0;
                    }else{
                        // Skip shipping method
                        continue;
                    }
                }else{
                    $cost = $settings['cost'];
                }

                $allowed_areas = array();
                $locations 	= $the_zone['zone_locations'];

                foreach ( (array) $locations as $location){

                    if ( $location->type == 'country' ){
                        array_push ( $allowed_areas, $location->code);
                    }
                }

                if (isset ($params['countries'])){
                    if (is_array($params['countries'])){
                        $country  = reset ($params['countries']);
                    }else{
                        $country  = $params['countries'];
                    }
                }else{
                    $country = '';
                }
                if ( in_array ($country, $allowed_areas) ) {
                    $active_methods[] = array(  'id'        	=> $id,
                        'type'      	=> $type,
                        'provider'  	=> $provider,
                        'name'      	=> $name,
                        'price'     	=> $cost,
                        'allowed_areas' => $allowed_areas
                    );
                }
            }
        }

        return ($active_methods);
    }

    private function getCountries ($data = array()){
        $countries = array();
        foreach ( $data as $iso => $name){
            array_push($countries, $iso);
        }
        return ($countries);
    }

    private function get_product_details($product){

        $_product = array();

        if ( in_array ( $product->get_type(), array ('simple', 'variable'))){

            $_product['cashback']                  = (int)0;
            $_product['options']                   = null;
            $_product['unique_identifier']         = false;

            $_product['sku_number']                = (string)( $product->get_sku() ?: $product->get_id());

            $_product['product_id']                = $product->get_id();
            $_product['product_name']              = $product->get_name();
            $_product['product_url']               = $product->is_visible() ? $product->get_permalink() : '';

            $_product['stock']                     = (int)$product->get_stock_quantity();
            $_product['created']                   = wc_format_datetime ($product->get_date_created(), 'Y-m-d H:i:s' );
            $_product['updated']                   = wc_format_datetime ($product->get_date_modified(), 'Y-m-d H:i:s' );
            $_product['downloadable']              = $product->is_downloadable();
            $_product['package_dimensions']        = array_filter($product->get_dimensions(false)) ? implode( 'x', $product->get_dimensions(false)) : NULL;
            $_product['dimension_unit']            = array_filter($product->get_dimensions(false)) ? get_option('woocommerce_dimension_unit') : NULL;

            $_product['weight']                    = $product->get_weight() ? $product->get_weight() : 0;
            $_product['weight_unit']               = $product->get_weight() ? get_option('woocommerce_weight_unit') : 'kg';

            $_product['short_product_description'] = $this->getDescription ($product->get_short_description(), $product->get_name());
            $_product['long_product_description']  = $this->getDescription ($product->get_description(),       $product->get_name());

            $_product['from_price']                = $this->formatPrice ($this->getFromPrice($product));
            $_product['retail_price']              = $this->formatPrice ($product->get_regular_price());
            $_product['sale_price']                = $product->is_on_sale() ? $this->formatPrice($product->get_sale_price()) : NULL;

            // Variations do not (always) has a retail/sales price, just a from_price.
            // Use this until Sales_price and Retail_price issue is fixed in QTP-295
            // Start Workaround
            $_product['retail_price']               = $_product['retail_price'] ?: $_product['from_price'];
            $_product['sale_price']                 = $_product['sale_price']   ?: $_product['retail_price'];
            // End Workaround

            $_product['category_ids']              = $product->get_category_ids();
            $_product['tax']                       = $this->getTaxrates($product);
            $_product['metadata']                  = $this->getMetadata($product);
            $_product['product_image_urls']        = $this->getImages($product);
            $_product['attributes']                = $product->has_attributes() ?  $this->getAttributes($product) : NULL;

            $_product['variants']                  = $this->getVariations($product);

            // remove all indexen with a value NULL
            $_product = array_filter($_product, function($value) { return $value !== NULL; });
        }

        return ( $_product);
    }

    private function getVariations ($product){

        if ( $product->has_child() ===  false) {
            return null;
        }

        $children = $product->get_visible_children();

        $_variants   = array();
        foreach ($children as $child) {

            $_variant = array();

            $product_variation = new WC_Product_Variation($child);

            $_variant['product_id']        = $product_variation->get_id();
            $_variant['unique_identifier'] = false;

            $_variant['product_image_urls'] = $this->getImages($product_variation);

            if ( sizeof($_variant['product_image_urls']) == 0 ) {
                // If variant doesn't have images, use the images from the parent product
                $_variant['product_image_urls'] = $this->getImages ($product);
            }

            $_variant['stock']             = (int)$product_variation->get_stock_quantity();
            $_variant['weight']            = $product_variation->has_weight() ? $product_variation->get_weight() : 0;
            $_variant['weight_unit']       = $product_variation->has_weight() ? get_option('woocommerce_weight_unit') : 'kg';

            $_variant['sku_number']        = (string) ($product_variation->get_sku() ?: $product_variation->get_id());

            $_variant['retail_price']      = $this->formatPrice($product_variation->get_regular_price());
            $_variant['sale_price']        = $product_variation->is_on_sale() ? $this->formatPrice($product_variation->get_sale_price()) : NULL;

            // Variations do not (always) has a retail/sales price, just a from_price.
            // Use this until Sales_price and Retail_price issue is fixed in QTP-295
            // Start Workaround
            $_variant['retail_price']               = $_variant['retail_price'] ?: $_variant['from_price'];
            $_variant['sale_price']                 = $_variant['sale_price']   ?: $_variant['retail_price'];
            // End Workaround


            $_variant['attributes']        = $this->formatAttribute (wc_get_formatted_variation ($product_variation, true));

            $_variant = array_filter($_variant, function($value) { return $value !== NULL; });

            array_push ($_variants, $_variant);
        }

        return ($_variants);
    }

    private function getFromPrice($product){
        return $product->has_child() ? $product->get_variation_price( 'min', true ) : NULL;
    }

    private function getDescription ($desc, $fallBack) {

        $description = $desc ?: $fallBack;
        $description = strip_tags($description);
        $result = array(get_locale() => $description);

        return ($result);
    }

    private function getTaxrates ($product) {
        $rules = array();

        $countries = WC()->countries->get_allowed_countries();
        foreach ($countries as $country_code => $country_desc) {
            $tax = WC_Tax::find_rates(array ('tax_class' => $product->get_tax_class(), 'country' => $country_code));
            $tax = array_shift ($tax);
            $rules[$country_code] = isset ($tax['rate']) ? $tax['rate'] : 0;
        }

        $result = array('id'    => $product->get_tax_class() ?: 0,
            'name'  => $tax['label'],
            'rules' => $rules);

        return $result;
    }

    private function getMetadata ($product){

        $result = null;

        $tags = explode(', ', strip_tags(wc_get_product_tag_list ( $product->get_id() ) ));

        foreach ($tags as $tag) {
            if ($tag) {
                $title[get_locale()]        = $tag;
                $keyword[get_locale()]      = $tag;
                $description[get_locale()]  = $tag;

                $result = array( 'title' => $title, 'keyword'=> $keyword, 'description' => $description);
                break;
            }
        }
        return ($result);
    }

    private function formatAttribute ($input){

        $result = array();
        $attributes = explode (', ', $input);

        foreach ($attributes as $attribute){
            list ($label, $value) = explode (': ', $attribute);
            $result[$label] = array(get_locale() => array('label' => $label, 'value' => $value));
        }

        return $result;
    }

    private function getAttributes ($product){

        $result = array();

        $attributes = $product->get_attributes();

        foreach($attributes as $attr=>$attr_deets){

            $attribute_label = wc_attribute_label($attr);
            if ( isset( $attributes[ $attr ] ) || isset( $attributes[ 'pa_' . $attr ] ) ) {
                $attribute = isset( $attributes[ $attr ] ) ? $attributes[ $attr ] : $attributes[ 'pa_' . $attr ];

                if ( $attribute['is_taxonomy'] ) {
                    $value = implode( ' | ', wc_get_product_terms( $product->get_id(), $attribute['name'], array( 'fields' => 'names' ) ) );
                } else {
                    $value = $attribute['value'];
                }
                $result[$attribute_label] = array(get_locale() => array('label' => $attribute_label, 'value' => $value));
            }
        }

        return $result;
    }

    private function formatPrice ($price, $decimals=5){

        $price = $price ?  wc_format_decimal ($price, $decimals) : NULL;

        return $price;
    }

    private function getImages ($product){
        $images = array();
        $attachment_id_main       = array_filter(array ($product->get_image_id()));
        $attachment_ids_secundair = array_filter($product->get_gallery_image_ids());

        $attachment_ids = array_merge ($attachment_id_main, $attachment_ids_secundair);

        foreach ($attachment_ids as $index => $attachment_id) {
            $_images = wp_get_attachment_url($attachment_id);
            if ($_images) {
                array_push($images, array('url' => $_images,
                    'main' => $index ? false : true));
            }
        }
        return ($images);
    }


    public static function msp_sync_on_product_update( $post_id, $post, $endpoint = '/api/product/data' ) {

        if ( get_post_type( $post_id ) == 'product' ) {

            $product = new Qwindo();
            $result = $product->productById($post_id);

            $json = gzuncompress ($product->createJSON ($result));
            $msp = new MultiSafepay_Client();
            $msp->api_url = self::get_qwindo_API() . $endpoint . '?id='. $post_id;
            $msp->auth    = self::getAuthorization($msp->api_url, $json, '');

            if (get_post_status( $post_id ) == 'publish'){
                $msp->qwindo->put($json, $endpoint);
            }else{
                $msp->qwindo->delete($json, $endpoint);
            }
        }
    }

    public static function msp_sync_product_stock( $post_id ) {
        self::msp_sync_stock($post_id->id, $post_id->stock);
    }

    public static function msp_sync_variation_stock( $post_id ) {
        self::msp_sync_stock($post_id->variation_id, $post_id->stock, '/api/stock/variant');
    }

    public static function msp_sync_stock( $id, $stock, $endpoint = '/api/stock/product' ) {
        $json = json_encode(array('product_id' => $id,
                                  'stock'      => $stock));

        $msp = new MultiSafepay_Client();
        $msp->api_url = self::get_qwindo_API() . $endpoint;
        $msp->auth    = self::getAuthorization($msp->api_url, $json, '');
        $msp->qwindo->put($json, $endpoint);
    }

    public static function msp_sync_store($id, $endpoint = '/api/shop/data') {
        $store = new Qwindo();
        $result = $store->stores();
        $json = gzuncompress ($store->createJSON ($result));
        $msp = new MultiSafepay_Client();
        $msp->api_url = self::get_qwindo_API() . $endpoint;
        $msp->auth    = self::getAuthorization($msp->api_url, $json, '');
        $msp->qwindo->put($json, $endpoint);
    }

    public static function msp_sync_category($id, $id2, $id3, $endpoint = '/api/categories/data') {

        $cat = new Qwindo();
        $result = $cat->categories();
        $json = gzuncompress ($cat->createJSON ($result));

        $msp = new MultiSafepay_Client();
        $msp->api_url = self::get_qwindo_API() . $endpoint;
        $msp->auth    = self::getAuthorization($msp->api_url, $json, '');
        $msp->qwindo->put($json, $endpoint);
    }

    public static function get_qwindo_API() {

        return get_option('multisafepay_testmode') == 'yes' ? 'https://test.fastcheckout.com' : 'https://live.fastcheckout.com';
    }

    public function get_nginx_headers($function_name='getallheaders'){

        $all_headers=array();

        if(function_exists($function_name)){
            $all_headers=$function_name();
        }else{
            foreach($_SERVER as $name => $value){

                if(substr($name,0,5)=='HTTP_'){

                    $name=substr($name,5);
                    $name=str_replace('_',' ',$name);
                    $name=strtolower($name);
                    $name=ucwords($name);
                    $name=str_replace(' ', '-', $name);

                    $all_headers[$name] = $value;
                }
                elseif($function_name=='apache_request_headers'){

                    $all_headers[$name] = $value;
                }
            }
        }
        return $all_headers;
    }

    public static function getAuthorization($url, $data, $observer=''){
        $qwindo_key = get_option('multisafepay_qwindo_api_key');
        $hash_id    = get_option('multisafepay_qwindo_hash_id');
        if ( $qwindo_key && $hash_id ){
            $timestamp = microtime(true);
            $token = hash_hmac('sha512', $url . $timestamp . $data, $qwindo_key);
            $auth = base64_encode(sprintf('%s:%s:%s', $hash_id, $timestamp, $token));
            return $auth;
        }
        return null;
    }
}
