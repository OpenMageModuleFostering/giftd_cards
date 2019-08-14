<?php

require_once('GiftdClient.php');

class Giftd_Cards_Model_Observer
{
    /**
     * @var GiftdClient $client
     */
    protected $client = false;

    public function init($force=false)
    {
        if($this->client && !$force)
            return true;

        $api_key = Mage::getStoreConfig('giftd_cards/api_settings/api_key',Mage::app()->getStore());
        $user_id = Mage::getStoreConfig('giftd_cards/api_settings/user_id',Mage::app()->getStore());

        if(strlen($api_key) > 0 && strlen($user_id) > 0)
        {
            $this->client = new GiftdClient($user_id, $api_key);
            return true;
        }

        return false;
    }

    public function getStoreInfo()
    {
        $schema = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off') ? 'https' : 'http';

        $host = (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : $_SERVER['SERVER_NAME']);
        if (count($hostParts = explode(":", $host)) > 1) {
            $port = $hostParts[1];
            if ($port == 80 || $port == 443) {
                $host = $hostParts[0];
            }
        }
        $url = "$schema://$host";

        $userId = Mage::getSingleton('admin/session')->getUser()->getId();
        $user = Mage::getModel('admin/user')->load($userId);

        $name = trim($user->getData('firstname').' '.$user->getData('lastname'));

        $result = array(
            'email' => $user->getData('email'),
            'url' => $url,
            'magento_version' => Mage::getVersion()
        );
        if ($name) {
            $result['name'] = $name;
        }
        return $result;
    }

    public  function updateApiKey(Varien_Event_Observer $observer)
    {
        //kk: do check if api_key or user_id values has changed
        if(self::init())
        {
            $config = new Mage_Core_Model_Config();

            $this->client->query('magento/install', $this->getStoreInfo());
            Mage::log('Sent store data to Giftd');

            $response = $this->client->query('partner/get');
            if($response['type'] == 'data') {
                $config->saveConfig('giftd_cards/api_settings/partner_token', $response['data']['code'], 'default', 0);
                $config->saveConfig('giftd_cards/api_settings/partner_token_prefix', $response['data']['token_prefix'], 'default', 0);
            }
        }
    }

    public function showMinimumSubtotalError($limit)
    {

        switch (Mage::app()->getLocale()->getLocaleCode()) {
            case 'ru_RU':
                $message = "Для использования этой подарочной карты общая сумма должна быть не менее %s";
                break;
            case 'de_DE':
                $message = "Um diese Geschenkkarte nutzen die Zwischensumme sollte mindestens %s";
                break;
            default:
                $message = "To use this gift card the subtotal should be at least %s";
                break;
        }
        $formattedLimit = Mage::helper('core')->currency($limit, true, false);

        Mage::getSingleton('checkout/session')->addError(sprintf($message, $formattedLimit));
    }

    public function checkoutCoupon(Varien_Event_Observer $observer)
    {
        if(self::init())
        {
            $quote = Mage::helper('checkout/cart')->getQuote();
            if ($coupon_code = $quote->getCouponCode())
            {
                if($card = self::getGiftdCard($coupon_code))
                {
                    $subtotal = $quote->getSubtotal();
                    $order_id = $observer->getEvent()->getOrder()->getId();
                    $visitor = Mage::getSingleton('core/session')->getVisitorData();

                    try
                    {
                        $this->client->charge($coupon_code, $card->amount_available, $subtotal, $visitor['visitor_id'].'_'.$visitor['quote_id'].'_'.$order_id);
                        Mage::log('coupon charged - code:'.$coupon_code.' amount:'.$card->amount_available.' subtotal:'.$subtotal.' id:'.$visitor['visitor_id'].'_'.$visitor['quote_id'].'_'.$order_id);

                    }
                    catch(Exception $e)
                    {
                        //KK: how should we handle such situations?
                        Mage::logException($e);
                    }

                }
            }
        }
    }


    public function processCoupon(Varien_Event_Observer $observer)
    {
        if($_REQUEST['remove'] == 1)
            return;

        $coupon_code = trim($_REQUEST['coupon_code']);
        $subTotal = Mage::helper('checkout/cart')->getQuote()->getSubtotal();

        $existedCoupon = Mage::getModel('salesrule/coupon')->load($coupon_code, 'code');
        $rule = $existedCoupon->getRuleId() ? Mage::getModel('salesrule/rule')->load($existedCoupon->getRuleId()) : false;
        if($rule)
        {
            if(strpos($rule->getData('name'), 'Giftd') === 0 && self::init())
            {
                if($card = self::getGiftdCard($coupon_code))
                {
                    if($card->min_amount_total > $subTotal)
                        $this->showMinimumSubtotalError($card->min_amount_total);
                }
            }
            return;
        }

        if(self::init())
        {
            if($card = self::getGiftdCard($coupon_code))
            {
                $coupon_value = $card->amount_available;
                if ($card->min_amount_total > $subTotal)
                {
                    $this->showMinimumSubtotalError($card->min_amount_total);
                }
                self::generateRule("Giftd card", $coupon_code, $coupon_value, $card->min_amount_total);
            }
        }
    }

    public function getGiftdCard($the_coupon_code)
    {
        $the_coupon_code = trim($the_coupon_code);
        $prefix = trim(Mage::getStoreConfig('giftd_cards/api_settings/partner_token_prefix',Mage::app()->getStore()));
        if($this->client && strlen($the_coupon_code) > 0 && (!$prefix || strpos($the_coupon_code, $prefix) === 0))
        {
            $card = $this->client->checkByToken($the_coupon_code);
            if($card && $card->token_status == Giftd_Card::TOKEN_STATUS_OK)
            {
                return $card;
            }
        }

        return false;
    }

    public function generateRule($name, $coupon_code, $discount, $min_amount)
    {
        if ($name != null && $coupon_code != null)
        {
            $data = array(
              'product_ids' => null,
              'name' => $name,
              'description' => null,
              'is_active' => 1,
              'website_ids' => array(1),
              'customer_group_ids' => array(0,1,2,4,5),
              'coupon_type' => 2,
              'coupon_code' => $coupon_code,
              'uses_per_coupon' => 1,
              'uses_per_customer' => 1,
              'from_date' => null,
              'to_date' => null,
              'sort_order' => null,
              'is_rss' => 0,
              'conditions' => array(
                  "1" => array(
                      "type" => "salesrule/rule_condition_combine",
                      "aggregator" => "all",
                      "value" => "1",
                      "new_child" => null
                  ),
                  "1--1" => array(
                      "type" => "salesrule/rule_condition_address",
                      "attribute" => "base_subtotal",
                      "operator" => ">=",
                      "value" => $min_amount
                  )
              ),
              'simple_action' => 'cart_fixed',
              'discount_amount' => $discount,
              'discount_qty' => 0,
              'discount_step' => null,
              'apply_to_shipping' => 0,
              'simple_free_shipping' => 0,
              'stop_rules_processing' => 0,
              'store_labels' => array($name)
            );

            $model = Mage::getModel('salesrule/rule');
            $validateResult = $model->validateData(new Varien_Object($data));

            if ($validateResult == true)
            {
                try {
                    $model->loadPost($data);
                    $model->save();
                } catch (Exception $e) {
                    Mage::log($e->getMessage());
                }
            }
        }
    }
}