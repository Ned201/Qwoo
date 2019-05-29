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
class MultiSafepay_Client
{

    public $orders;
    public $qwindo;
    public $issuers;
    public $transactions;
    public $gateways;
    protected $api_key;
    public $api_url;
    public $api_endpoint;
    public $request;
    public $response;
    public $debug;
    public $auth;

    public function __construct()
    {
        $this->orders = new MultiSafepay_ObjectOrders($this);
        $this->issuers = new MultiSafepay_ObjectIssuers($this);
        $this->gateways = new MultiSafepay_ObjectGateways($this);
        $this->qwindo   = new MultiSafepay_ObjectQwindo($this);
    }

    public function getRequest()
    {
        return $this->request;
    }

    public function getResponse()
    {
        return $this->response;
    }

    public function setApiUrl($test)
    {
        if ($test) {
            $url = 'https://testapi.multisafepay.com/v1/json/';
        } else {
            $url = 'https://api.multisafepay.com/v1/json/';
        }
        $this->api_url = trim($url);
    }

    public function setDebug($debug)
    {
        $this->debug = trim($debug);
    }

    public function setApiKey($api_key)
    {
        $this->api_key = trim($api_key);
    }

    /*
     * Parses and sets customer address
     */

    public function parseCustomerAddress($street_address)
    {
        list($address, $apartment) = $this->parseAddress($street_address);
        return array($address, $apartment);
    }

    /**
     * Parses and sets delivery address
     */
    public function parseDeliveryAddress($street_address)
    {
        list($address, $apartment) = $this->parseAddress($street_address);
        $this->delivery['address1'] = $address;
        $this->delivery['housenumber'] = $apartment;
    }

    /*
     * Parses and splits up an address in street and housenumber
     */

    private function parseAddress($adress, $seperaatAddition = false)
    {
        $street = '';
        $number = '';
        $numberAddition = '';

        $results = array();
        $pattern_adress = "/^(.*)\s(\d+)(.*)/";

        preg_match($pattern_adress, trim($adress), $results);
        if (count($results) == 0) {
            $street = trim($adress);
        } else {
            $street = trim((isset($results[1])) ? $results[1] : '');
            $number = trim((isset($results[2])) ? $results[2] : '');
            $numberAddition = trim((isset($results[3])) ? $results[3] : '');
        }

        if ($seperaatAddition === true) {
            $pattern_addition = '/^([\s|-]*)(.*)/';
            $replacement_addition = '$2';
            $numberAddition = trim(preg_replace($pattern_addition, $replacement_addition, $numberAddition));
        } else {
            $number .= $numberAddition;
            $numberAddition = '';
        }

        return array($street, $number, $numberAddition);
    }

    private function rstrpos($haystack, $needle, $offset = null)
    {
        $size = strlen($haystack);

        if (is_null($offset)) {
            $offset = $size;
        }

        $pos = strpos(strrev($haystack), strrev($needle), $size - $offset);

        if ($pos === false) {
            return false;
        }

        return $size - $pos - strlen($needle);
    }

    public function processAPIRequest($http_method, $endpoint, $http_body = null)
    {

        $isQwindo = in_array ($endpoint, array ('/api/product/data',
                                                '/api/stock/product',
                                                '/api/stock/variant',
                                                '/api/categories/data',
                                                '/api/shop/data'));

        if ($isQwindo) {
            $url = $this->api_url;
            $headers = array(
                "Content-Type: application/json",
                "Accept: application/json",
                "Auth: " . $this->auth,
            );
        } else {
            if (empty($this->api_key)) {
                throw new Exception(__('Please configure your MultiSafepay API Key.', 'multisafepay'));
            }
            $url = $this->api_url . $endpoint;
            $headers = array(
                "Accept: application/json",
                "api_key:" . $this->api_key,
            );
        }

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $http_method);

        if ($http_body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $http_body);
        }

        $body = curl_exec($ch);
        if (curl_errno($ch)) {
            echo 'Error:' . curl_error($ch);
        }

        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($isQwindo) {
            if ($httpcode == "401") {
                throw new Exception("Qwindo authorization failed, please check your Qwindo key and Hash ID. Data not updated at Qwindo.");
            }
        }

        if ($this->debug) {
            $this->request = $http_body;
            $this->response = $body;
        }

        if (curl_errno($ch)) {
            $str = __('Unable to communicate with the MultiSafepay payment server', 'multisafepay') . '('
                    . curl_errno($ch) . '): ' . curl_error($ch) . '.';
            throw new Exception($str);
        }
        curl_close($ch);
        return $body;
    }
}
