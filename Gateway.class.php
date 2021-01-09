<?php
/**
 * Gateway implementation for CoinGate (https://developer.coingate.com)
 *
 * @author      Lee Garner <lee@leegarner.com>
 * @copyright   Copyright (c) 2020 Lee Garner <lee@leegarner.com>
 * @package     shop
 * @version     v0.0.1
 * @license     http://opensource.org/licenses/gpl-2.0.php
 *              GNU Public License v2 or later
 * @filesource
 */
namespace Shop\Gateways\coingate;
use Shop\Currency;
use Shop\Config;
use Shop\Customer;
use Shop\Models\OrderState;
use LGLib\NameParser;


/**
 * Class for CoinGate crypto currency processing gateway.
 * @package shop
 */
class Gateway extends \Shop\Gateway
{
    /** Gateway version.
     * @const string */
    protected const VERSION = '0.0.1';

    /** Gateway ID.
     * @var string */
    protected $gw_name = 'coingate';

    /** Gateway provide. Company name, etc.
     * @var string */
    protected $gw_provider = 'CoinGate';

    /** Gateway service description.
     * @var string */
    protected $gw_desc = 'CoinGate Crypto Currency';

    /** Internal API client to facilitate reuse.
     * @var object */
    private $_api_client = NULL;


    /**
     * Constructor.
     * Set gateway-specific items and call the parent constructor.
     *
     * @param   array   $A      Array of fields from the DB
     */
    public function __construct($A=array())
    {
        $supported_currency = array(
            'USD', 'EUR',
        );

        // Set up the config field definitions.
        $this->cfgFields = array(
            'prod' => array(
                'auth_token'   => 'password',
            ),
            'test' => array(
                'auth_token'   => 'password',
            ),
            'global' => array(
                'test_mode' => 'checkbox',
                'rcv_currency' => 'select',
                'callback_token' => 'TEMPTOKENHERE',
            ),
        );
        // Set config defaults
        $this->config = array(
            'global' => array(
                'test_mode'     => '1',
                'rcv_currency'  => 'BTC',
            ),
        );

        // Set the only service supported
        $this->services = array('checkout' => 1);

        // Call the parent constructor to initialize the common variables.
        parent::__construct($A);

        // If the configured currency is not one of the supported ones,
        // this gateway cannot be used, so disable it.
        if (!in_array($this->currency_code, $supported_currency)) {
            $this->enabled = 0;
        }
    }


    /**
     * Make the API classes available. May be needed for reports.
     *
     * @return  object  $this
     */
    public function loadSDK()
    {
        require_once __DIR__ . '/vendor/autoload.php';
        return $this;
    }


    protected function getConfigOptions($name, $env='global')
    {
        if (isset($this->config[$env][$name])) {
            $selected = $this->config[$env][$name];
        } else {
            $selected = '';
        }
        switch ($name) {
        case 'rcv_currency':
            $opts = array(
                array('name'=>'Bitcoin', 'value'=>'BTC', 'selected'=>($selected=='BTC')),
                array('name'=>'Lightcoin', 'value'=>'LTC', 'selected'=>($selected=='LTC')),
                array('name'=>'US Dollar', 'value'=>'USD', 'selected'=>($selected=='USD')),
                array('name'=>'Euro', 'value'=>'EUR', 'selected'=>($selected=='EUR')),
            );
        }
        return $opts;
    }


    /**
     * Get the main gateway url.
     * This is used to tell the buyer where they can log in to check their
     * purchase. For PayPal this is the same as the production action URL.
     *
     * @return  string      Gateway's home page
     */
    public function getMainUrl()
    {
        return '';
    }


    /**
     * Create an order at Coingate.
     *
     * @see     self::creatInvoice()
     * @see     self::confirmOrder()
     * @param   object  $Order  Order object
     * @return  object      Coingate order object
     */
    private function _createOrder($Order)
    {
        global $LANG_SHOP;

        $cust_info = $this->getCustomer($Order);
        if ($cust_info === false ) {
            SHOP_log("Error retrieving customer for order " . $Order->getOrderId());
            return false;
        }

        $params = array(
            'order_id' => $Order->getOrderId(),
            'title' => $LANG_SHOP['order'] . ' ' . $Order->getOrderId(),
            'price_amount' => $Order->getBalanceDue(),
            'price_currency' => $Order->getCurrency()->getCode(),
            'receive_currency' => $this->getConfig('rcv_currency'),
            'callback_url' => $this->getWebhookUrl(),
            'cancel_url' => $Order->cancelUrl(),
            'success_url' => SHOP_URL . '/index.php?thanks',
            'token' => $Order->getToken(),
        );
        $this->_getApiClient();
        $gwOrder = \CoinGate\Merchant\Order::create($params);
        return $gwOrder;
    }


    /**
     * Create a new customer at Coingate.
     *
     * @param   object  $Order  Order object
     * @return  array|false     Array of subcriber details, false on error
     */
    private function createCustomer($Order)
    {
        $cust_id = $Order->getUid();
        $Customer = $Order->getBillto();
        if (empty($Order->getBuyerEmail())) {
            $email = DB_getItem($_TABLES['users'], 'email', "uid = {$Order->getUid()}");
            $Order->setBuyerEmail($email);
        }
        $this->_getApiClient();
        $params = array(
            'email' => $Order->getBuyerEmail(),
            'subscriber_id' => $Order->getUid(),
            'first_name' => NameParser::F($Customer->getName()),
            'last_name' => NameParser::L($Customer->getName()),
            'organization_name' => $Customer->getCompany(),
            'address' => $Customer->getAddress1(),
            'secondary_address' => $Customer->getAddress2(),
            'city' => $Customer->getCity(),
            'postal_code' => $Customer->getPostal(),
            'country' => $Customer->getCountry(),
        );
        $this->_getApiClient();
        $result = \CoinGate\CoinGate::request(
            '/billing/subscribers',
            'POST',
            $params
        );
        if (
            is_array($result) &&
            $result['subscriber_id'] &&
            $result['subscriber_id'] == $cust_id
        ) {
            // Save the coingate custermer ID in the reference table
            $Customer->setGatewayId($this->gw_name, $result['subscriber_id']);
            return $result;
        } else {
            return false;
        }
    }


    /**
     * Retrieve an existing subscriber from Coingate by CG reference ID
     * Calls createCustomer() to create a new customer record if not found.
     *
     * @param   object  $Order      Order object
     * @return  array       Customer info array, or false on error.
     */
    private function getCustomer($Order)
    {
        $cust_id = $Order->getUid();
        $Customer = Customer::getInstance($cust_id);
        $gw_id = $Customer->getGatewayId($this->gw_name);
        $this->_getApiClient();
        if ($gw_id) {
            $cust_info = \CoinGate\CoinGate::request('/billing/subscribers/' . $gw_id, 'GET');
        }
        if (
            is_array($cust_info) &&
            isset($cust_info['subscriber_id']) &&
            $cust_info['subscriber_id'] == $cust_id
        ) {
            return $cust_info;
        } else {
            return $this->createCustomer($Order);
        }
    }


    /**
     * Get the form action URL.
     * This function may be overridden by the child class.
     * The default is to simply return the configured URL
     *
     * This is public so that if it is not declared by the child class,
     * it can be called during IPN processing.
     *
     * @return  string      URL to payment processor
     */
    public function getActionUrl()
    {
        return Config::get('url') . '/confirm.php';
    }


    /**
     * Get the form variables for the purchase button.
     *
     * @uses    Gateway::Supports()
     * @uses    _encButton()
     * @uses    getActionUrl()
     * @param   object  $Cart   Shopping cart object
     * @return  string      HTML for purchase button
     */
    public function gatewayVars($Cart)
    {
        if (!$this->Supports('checkout')) {
            return '';
        }
    
        $vars = array(
            'order_id' => $Cart->getOrderID(),
        );
        $gw_vars = array();
        foreach ($vars as $name=>$val) {
            $gw_vars[] = '<input type="hidden" name="' . $name .
                '" value="' . $val . '" />';
        }
        return implode("\n", $gw_vars);
    }


    /**
     * Get the values to show in the "Thank You" message when a customer
     * returns to our site.
     *
     * @uses    getMainUrl()
     * @uses    Gateway::getDscp()
     * @return  array       Array of name=>value pairs
     */
    public function thanksVars()
    {
        $R = array(
            'gateway_url'   => self::getMainUrl(),
            'gateway_name'  => self::getDscp(),
        );
        return $R;
    }


    /**
     * Get the variables to display with the IPN log.
     * This gateway does not have any particular log values of interest.
     *
     * @param  array   $data       Array of original IPN data
     * @return array               Name=>Value array of data for display
     */
    public function ipnlogVars($data)
    {
        return array();
    }


    /**
     * Get the form method to use with the final checkout button.
     * Use GET to work with confirm.php.
     *
     * @return  string  Form method
     */
    public function getMethod()
    {
        return 'get';
    }


    /**
     * Get the API client object.
     *
     * @return  object      SquareClient object
     */
    private function _getApiClient()
    {
        static $done = false;
        if (!$done) {
            $this->loadSDK();
            \CoinGate\CoinGate::config(array(
                'environment'               => $this->getConfig('test_mode') ? 'sandbox' : 'live',
                'auth_token'                => $this->getConfig('auth_token'),
                'curlopt_ssl_verifypeer'    => false,    // default is false
            ) );
            $done = true;
        }
    }


    /**
     * Get additional javascript to be attached to the checkout button.
     * Coingate does not need this since it redirects through confirm.php.
     *
     * @param   object  $cart   Shopping cart object
     * @return  string  Javascript commands.
     */
    public function getCheckoutJS($cart)
    {
        return '';
    }


    public function findOrder($id)
    {
        $this->_getApiClient();
        try {
            $order = \CoinGate\Merchant\Order::find($id);
            if (!$order) {
                $order = NULL;
            }
        } catch (Exception $e) {
            SHOP_log(__CLASS__.'::'.__FUNCTION__. $e->getMessage());
            $order = NULL;
        }
        return $order;
    }


    /**
     * Confirm the order and create an invoice on Coingate.
     *
     * @param   object  $Order  Shop Order object
     * @return  string      Redirect URL
     */
    public function confirmOrder($Order)
    {
        global $LANG_SHOP;

        $redirect = '';
        if (!$Order->isNew()) {
            $gwOrder = $this->_createOrder($Order);
            SHOP_log("order created: " . print_r($gwOrder,true), SHOP_LOG_DEBUG);
            if (
                is_object($gwOrder) &&
                $gwOrder->token == $Order->getToken()
            ) {
                $redirect = $gwOrder->payment_url;
            } else {
                COM_setMsg("There was an error processing your order");
            }
        }
        return $redirect;
    }


    /**
     * Check that a valid config has been set for the environment.
     *
     * @return  boolean     True if valid, False if not
     */
    public function hasValidConfig()
    {
        return !empty($this->getConfig('auth_token'));
    }

}
