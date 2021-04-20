<?php
/**
 * This file contains the IPN processor for Coingate.
 *
 * @author      Lee Garner <lee@leegarner.com>
 * @copyright   Copyright (c) 2020 Lee Garner
 * @package     shop
 * @version     v1.3.0
 * @since       v1.3.0
 * @license     http://opensource.org/licenses/gpl-2.0.php 
 *              GNU Public License v2 or later
 * @filesource
 */
namespace Shop\Gateways\coingate;
use Shop\Config;
use Shop\Order;
use Shop\Payment;
use Shop\Models\OrderState;


/**
 * Coingate IPN Processor.
 * @package shop
 */
class Webhook extends \Shop\Webhook
{
    /**
     * Constructor.
     * Most of the variables for this IPN come from the transaction,
     * which is retrieved in Verify().
     *
     * @param   array   $A  Array of IPN data
     */
    public function __construct($blob='')
    {
        $this->setSource('coingate');

        // Load the payload into the blob property for later use in Verify().
        $this->setData($_POST);
        $this->blob = json_encode($_POST);
        $this->setHeaders(NULL);
        $this->setTimestamp();
        $this->GW = \Shop\Gateway::getInstance($this->getSource());
    }


    /**
     * Verify that the message is valid and can be processed.
     * Checks key elements of the order and its status.
     *
     * @return  boolean     True if valid, False if not
     */
    public function Verify()
    {
        $gw_orderid = SHOP_getVar($this->getData(), 'id');
        $this->setEvent(SHOP_getVar($this->getData(), 'status'));
        $this->setOrderID(SHOP_getVar($this->getData(), 'order_id'));
        $this->setID('cg_' . $this->getEvent() . '_' . $gw_orderid);
        if (!$this->isUniqueTxnId()) {
            return false;
        }

        $this->Order = Order::getInstance($this->getOrderId());
        $token = SHOP_getVar($this->getData(), 'token');
        if ($token != $this->Order->getToken()) {
            SHOP_log(
                "Coingate Webhook, token $token does not match token of order " .
                $this->Order->getOrderId()
            );
            return false;
        }
        $gworder = $this->GW->findOrder($gw_orderid);
        if (!$gworder || $gworder->status != $this->getEvent()) {
            SHOP_log("Coingate order status does not match webhook status");
            return false;
        }
        return true;
    }


    /**
     * Process the transaction.
     * Verifies that the transaction is valid, then records the purchase and
     * notifies the buyer and administrator
     *
     * @uses    self::Verify()
     */
    public function Dispatch()
    {
        $retval = true;

        $LogID = $this->logIPN();
        switch ($this->getEvent()) {
        case 'pending':
            $this->Order->setStatus(OrderState::PENDING)->Save(false);
            break;
        case 'paid':
            $this->setPayment(SHOP_getVar($this->getData(), 'price_amount', 'float'));
            SHOP_log("Received {$this->getPayment()} gross payment", SHOP_LOG_DEBUG);
            if ($this->Order->getBalanceDue() > $this->getPayment()) {
                SHOP_log("Insufficient Funds Received from Coingate for order " . $this->Order->getOrderID());
                return true;    // not an error requiring resent webhook
            }
            $Pmt = Payment::getByReference($this->getID());
            if ($Pmt->getPmtID() == 0) {
                $Pmt->setRefID($this->getID())
                    ->setAmount($this->getPayment())
                    ->setGateway($this->getSource())
                    ->setMethod($this->getSource())
                    ->setComment('Webhook ' . $this->getID())
                    ->setOrderID($this->getOrderID())
                    ->Save();
            }
            $retval = $this->handlePurchase();
            break;
        case 'invalid':
        case 'expired':
        case 'canceled':
            // Order was marked invalid by the buyer or expired.
            // Set the status and return "false" to prevent further processing.
            echo "Here";die;
            $this->Order->setStatus(OrderState::CANCELED)->Save(false);
            break;
        default:
            break;
        }
        return true;
    }

}
