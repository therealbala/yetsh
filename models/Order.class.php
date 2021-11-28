<?php

namespace App\Models;

use App\Core\Model;
use App\Core\Database;

class Order extends Model
{
    /**
     * Table name based on the Model
     *
     * @var string
     */
    public static $tableName = 'premium_order';

    public function newSubscription($paymentGateway, $gatewaySubscriptionId) {
        // connect db
        $db = Database::getDatabase();

        // insert subscription
        return $db->query('INSERT INTO payment_subscription '
                        . '(user_id, user_level_pricing_id, payment_gateway, '
                        . 'gateway_subscription_id, date_added) '
                        . 'VALUES (:user_id, :user_level_pricing_id, :payment_gateway, '
                        . ':gateway_subscription_id, NOW())', array(
                    'user_id' => (int) $this->user_id,
                    'user_level_pricing_id' => $this->user_level_pricing_id,
                    'payment_gateway' => $paymentGateway,
                    'gateway_subscription_id' => $gatewaySubscriptionId,
        ));
    }

    public function cancelSubscription($paymentGateway, $gatewaySubscriptionId) {
        // connect db
        $db = Database::getDatabase();

        // cancel active subscription
        return $db->query('UPDATE payment_subscription '
                        . 'SET sub_status = "cancelled" '
                        . 'WHERE paypal_subscription_id = :paypal_subscription_id '
                        . 'AND payment_gateway = :payment_gateway '
                        . 'AND user_id = :user_id '
                        . 'LIMIT 1', array(
                    'paypal_subscription_id' => $gatewaySubscriptionId,
                    'payment_gateway' => $paymentGateway,
                    'user_id' => (int) $this->user_id,
        ));
    }

}
