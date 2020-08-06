<?php

namespace Forter\Forter\Observer\OrderValidation;

use Forter\Forter\Model\AbstractApi;
use Forter\Forter\Model\ActionsHandler\Decline;
use Forter\Forter\Model\Config;
use Forter\Forter\Model\RequestBuilder\Order;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Quote\Model\Quote\Item;
use Forter\Forter\Model\RequestBuilder\BasicInfo;

/**
 * Class PaymentPlaceStart
 * @package Forter\Forter\Observer\OrderValidation
 */
class PaymentPlaceStart implements ObserverInterface
{
    /**
     *
     */
    const VALIDATION_API_ENDPOINT = 'https://api.forter-secure.com/v2/orders/';
    /**
     * @var Decline
     */
    private $decline;
    /**
     * @var CheckoutSession
     */
    private $checkoutSession;
    /**
     * @var Item
     */
    private $modelCartItem;
    /**
     * @var AbstractApi
     */
    private $abstractApi;
    /**
     * @var ManagerInterface
     */
    private $messageManager;
    /**
     * @var Config
     */
    private $config;
    /**
     * @var Order
     */
    private $requestBuilderOrder;
    /**
     * @var BasicInfo
     */
    private $basicInfo;
    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * PaymentPlaceStart constructor.
     * @param Decline $decline
     * @param ManagerInterface $messageManager
     * @param CheckoutSession $checkoutSession
     * @param AbstractApi $abstractApi
     * @param Config $config
     * @param Order $requestBuilderOrder
     * @param Item $modelCartItem
     */
    public function __construct(
        \Magento\Framework\ObjectManagerInterface $objectManager,
        Decline $decline,
        ManagerInterface $messageManager,
        CheckoutSession $checkoutSession,
        AbstractApi $abstractApi,
        Config $config,
        Order $requestBuilderOrder,
        Item $modelCartItem,
        BasicInfo $basicInfo
    ) {
        $this->decline = $decline;
        $this->checkoutSession = $checkoutSession;
        $this->modelCartItem = $modelCartItem;
        $this->abstractApi = $abstractApi;
        $this->messageManager = $messageManager;
        $this->config = $config;
        $this->requestBuilderOrder = $requestBuilderOrder;
        $this->basicInfo = $basicInfo;
        $this->objectManager = $objectManager;
    }

    /**
     * @param \Magento\Framework\Event\Observer $observer
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        if (!$this->config->isEnabled() || $this->config->getIsPost()) {
            return;
        }

        try {
            $order = $observer->getEvent()->getPayment()->getOrder();

            $data = $this->requestBuilderOrder->buildTransaction($order, 'BEFORE_PAYMENT_ACTION');

            //collect forter payment details and save into db
            $headers = getallheaders();
            $mockBuilder = new ForterMock();
            $mockObject = $mockBuilder->buildOrderMock();
            $orderIp = $mockObject->getRemoteIp();
            $connectionInformation = $this->basicInfo->getConnectionInformation($orderIp, $headers);
            $order->setForterClientDetails(json_encode($connectionInformation));
            $order->save();

            //save forter record
            if ($this->config->getIsCron()) {
                $queue = $this->objectManager->create('Forter\Forter\Model\Queue');
                $queue->setEntityType('pre_sync_order');
                $queue->setSyncFlag(0);
                $queue->save();
            }

            $url = self::VALIDATION_API_ENDPOINT . $order->getIncrementId();
            $response = $this->abstractApi->sendApiRequest($url, json_encode($data));

            $order->setForterResponse($response);

            $response = json_decode($response);

            if ($response->status != 'success' || !isset($response->action)) {
                $order->setForterStatus('error');
                return;
            }

            $order->setForterStatus($response->action);

            if ($response->action != 'decline') {
                return;
            }
        } catch (\Exception $e) {
            $this->abstractApi->reportToForterOnCatch($e);
        }

        $this->decline->handlePreTransactionDescision();
    }
}
