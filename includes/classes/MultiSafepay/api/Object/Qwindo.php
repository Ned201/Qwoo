<?php
/**
 *  MultiSafepay Payment Module
 *
 *  @author    MultiSafepay <techsupport@MultiSafepay.com>
 *  @copyright Copyright (c) 2013 MultiSafepay (http://www.multisafepay.com)
 *  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

class MultiSafepay_ObjectQwindo extends MultiSafepay_ObjectCore
{

    public $success;
    public $data;

    public function delete($body, $endpoint = '')
    {
        $result = parent::delete(json_encode($body), $endpoint);
        $this->success = $result->success;
        return $result;
    }

    public function put($body, $endpoint = '')
    {
        $result = parent::put($body, $endpoint);
//        $this->success = $result->success;
        return $result;
    }

    public function post($body, $endpoint = '')
    {
        $result = parent::post($body, $endpoint);
        $this->success = $result->success;
        $this->data = $result->data;
        return $this->data;
    }

    public function get($endpoint, $id, $body = array(), $query_string = false)
    {
        $result = parent::get(json_decode($body), $endpoint);

        $this->success = $result->success;
        $this->data = $result->data;
        return $this->data;
    }
}
