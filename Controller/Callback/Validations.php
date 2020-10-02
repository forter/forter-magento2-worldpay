<?php
namespace Forter\Forter\Controller\Callback;

use Magento\Framework\App\Action\HttpPostActionInterface as HttpPostActionInterface;

/**
 * Class Validations
 * @package Forter\Forter\Controller\Api
 */
class Validations extends \Magento\Framework\App\Action\Action implements HttpPostActionInterface
{
    const XML_FORTER_DECISION_ENABLED = "forter/advanced_settings/enabled_decision_controller";
    const XML_FORTER_HOLD_ORDER = "forter/advanced_settings/enabled_hold_order";
    const XML_FORTER_EXTENSION_ENABLED = "forter/settings/enabled";
    const XML_FORTER_SECRET_KEY = "forter/settings/secret_key";
    const XML_FORTER_SITE_ID = "forter/settings/site_id";
    const FORTER_RESPONSE_DECLINE = 'decline';
    const FORTER_RESPONSE_PENDING = 'resending';
    const FORTER_RESPONSE_APPROVE = 'approve';
    const FORTER_RESPONSE_NOT_REVIEWED = 'not reviewed';
    const FORTER_RESPONSE_PENDING_APPROVE = 'pending';

    /**
     * @var Config
     */
    protected $forterConfig;

    /**
     * @var \Magento\Framework\View\Result\PageFactory
     */
    protected $_pageFactory;

    /**
     * @var \Magento\Sales\Api\OrderRepositoryInterface
     */
    protected $orderRepository;

    /**
     * @var
     */
    protected $logger;

    /**
     * @var DateTime
     */
    protected $dateTime;

    /**
     * @var ForterQueueFactory
     */
    protected $queue;

    /**
     * @var CustomerSession
     */
    protected $customerSession;

    /**
     * @var Decline
     */
    protected $decline;

    /**
     * @var
     */
    protected $scopeConfig;

    /**
     * @var
     */
    protected $jsonResultFactory;

    /**
     * @var \Magento\Framework\UrlInterface
     */
    protected $url;

    /**
     * Validations constructor.
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Framework\Stdlib\DateTime\DateTime $dateTime
     * @param \Forter\Forter\Model\Config $forterConfig
     * @param \Forter\Forter\Model\QueueFactory $queue
     * @param \Magento\Framework\UrlInterface $url
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Magento\Customer\Model\Session $customerSession
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Magento\Framework\View\Result\PageFactory $pageFactory
     * @param \Magento\Sales\Api\OrderRepositoryInterface $orderRepository
     * @param \Magento\Framework\Controller\Result\JsonFactory $jsonResultFactory
     */
    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Forter\Forter\Model\ActionsHandler\Decline $decline,
        \Magento\Framework\Stdlib\DateTime\DateTime $dateTime,
        \Forter\Forter\Model\Config $forterConfig,
        \Forter\Forter\Model\QueueFactory $queue,
        \Magento\Framework\UrlInterface $url,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Framework\App\Action\Context $context,
        \Magento\Framework\View\Result\PageFactory $pageFactory,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        \Magento\Framework\Controller\Result\JsonFactory $jsonResultFactory
    ) {
        $this->url = $url;
        $this->queue = $queue;
        $this->logger = $logger;
        $this->decline = $decline;
        $this->dateTime = $dateTime;
        $this->scopeConfig = $scopeConfig;
        $this->_pageFactory = $pageFactory;
        $this->forterConfig = $forterConfig;
        $this->orderRepository = $orderRepository;
        $this->customerSession = $customerSession;
        $this->jsonResultFactory = $jsonResultFactory;
        return parent::__construct($context);
    }

    /**
     * @return \Magento\Framework\App\ResponseInterface|\Magento\Framework\Controller\ResultInterface|\Magento\Framework\View\Result\Page
     */
    public function execute()
    {
        //module enable check
        $moduleEnabled = $this->scopeConfig->getValue(self::XML_FORTER_EXTENSION_ENABLED);
        $controllerEnabled = $this->scopeConfig->getValue(self::XML_FORTER_DECISION_ENABLED);
        if ($moduleEnabled == 0 || $controllerEnabled == 0) {
            return null;
        }
        $request = $this->getRequest();
        $method = $request->getMethod();
        if ($method == "POST") {
            $success = true;
            $reason = null;
            try {
                // validate call from forter
                $requestParams = $request->getParams();
                $bodyRawParams = json_decode($request->getContent(), true);
                $params = array_merge($requestParams, $bodyRawParams);

                $siteId = $request->getHeader("X-Forter-SiteID");
                $key = $request->getHeader("X-Forter-Token");
                $hash = $request->getHeader("X-Forter-Signature");
                //to be developed post param handler - optional
//            $postData = isset($GLOBALS['HTTP_RAW_POST_DATA']) ? $GLOBALS['HTTP_RAW_POST_DATA'] : file_get_contents("php://input");
                $postData = "";
                $paramAmount =  sizeof($params);
                $counter = 1;
                foreach ($params as $param => $value) {
                    if ($paramAmount == $counter) {
                        $postData .= $param . "=" . $value;
                    } else {
                        $postData .= $param . "=" . $value . "&";
                    }
                    $counter++;
                }

                if ($hash != $this->calculateHash($siteId, $key, $postData)) {
//                throw new \Exception("Forter: Invalid call");
                }

                if ($siteId != $this->getSiteId()) {
//                throw new \Exception("Forter: Invalid call");
                }

//            $jsonRequest = json_decode($postData);
                $jsonRequest = $params;

                if (is_null($jsonRequest)) {
//                throw new \Exception("Forter: Invalid call");
                }

                // load order
                $orderId = $request->getParam('order_id');
                $order = $this->getOrder($orderId);

                // validate order
                if (!$order->getId()) {
//                throw new \Exception("Forter: Unknown order_id {$orderId}");
                }

                if (!$order->getForterSent()) {
//                throw new \Exception("Forter: Order was never sent to Forter [id={$orderId}]");
                }

                if (!$order->getForterStatus()) {
//                throw new \Exception("Forter: Order status does not allow action.[id={$orderId}, status={$order->getForterStatus()}");
                }

                // handle action
                $this->handlePostDecisionCallback($jsonRequest['action'], $order);
            } catch (Exception $e) {
                $this->logger->critical('Error message', ['exception' => $e]);

                $success = false;
                $reason = $e->getMessage();
            }

            // build response
            $response = array_filter(["action" => ($success ? "success" : "failure"), 'reason' => $reason]);

            $result = $this->jsonResultFactory->create();
            $result->setData($response);

            return $result;
        } else {
            $norouteUrl = $this->url->getUrl('noroute');
            $this->getResponse()->setRedirect($norouteUrl);
        }
    }

    /**
     * Return order entity by id
     * @param $id
     * @return \Magento\Sales\Api\Data\OrderInterface
     */
    public function getOrder($id)
    {
        return $this->orderRepository->get($id);
    }

    /**
     * @param $siteId
     * @param $token
     * @param $body
     * @return string
     */
    public function calculateHash($siteId, $token, $body)
    {
        $secert = $this->getSecretKey();
        return hash('sha256', $secert . $token . $siteId . $body);
    }

    /**
     * @param null $storeId
     * @return mixed
     */
    public function getSecretKey($storeId = null)
    {
        $secretKey = $this->scopeConfig->getValue(self::XML_FORTER_SECRET_KEY);

        return $secretKey;
    }

    /**
     * @param null $storeId
     * @return mixed
     */
    public function getSiteId($storeId = null)
    {
        $siteId = $this->scopeConfig->getValue(self::XML_FORTER_SITE_ID);

        return $siteId;
    }

    /**
     * @param $forterDecision
     * @param $order
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function handlePostDecisionCallback($forterDecision, $order)
    {
        $holdEnabled = $this->scopeConfig->getValue(self::XML_FORTER_HOLD_ORDER);
        if ($forterDecision == "decline") {
            $this->handleDecline($order);
        } elseif ($forterDecision == 'approve') {
            $this->handleApprove($order);
        } elseif ($forterDecision == "not reviewed") {
            $this->handleNotReviewed($order);
        } elseif ($forterDecision == "pending" && $holdEnabled == 1) {
            $order->hold()->save();
        } else {
            throw new \Exception("Forter: Unsupported action from Forter");
        }
    }

    /**
     * @param $order
     */
    public function handleDecline($order)
    {
        $result = $this->forterConfig->getDeclinePost();
        if ($result == '1') {
            $this->customerSession->setForterMessage($this->forterConfig->getPostThanksMsg());
            if ($order->canHold()) {
                $order->setCanSendNewEmailFlag(false);
                $this->decline->holdOrder($order);
                $this->setMessageToQueue($order, 'decline');
            }
        } elseif ($result == '2') {
            $order->setCanSendNewEmailFlag(false);
            $this->decline->markOrderPaymentReview($order);
        }
    }

    /**
     * @param $order
     */
    public function handleApprove($order)
    {
        $result = $this->forterConfig->getApprovePost();
        if ($result == '1') {
            $this->setMessageToQueue($order, 'approve');
        }
    }

    /**
     * @param $order
     */
    public function handleNotReviewed($order)
    {
        $result = $this->forterConfig->getNotReviewPost();
        if ($result == '1') {
            $this->setMessageToQueue($order, 'approve');
        }
    }

    /**
     * @param $order
     * @param $type
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function setMessageToQueue($order, $type)
    {
        $storeId = $order->getStore()->getId();
        $currentTime = $this->dateTime->gmtDate();
        $this->forterConfig->log('Increment ID:' . $order->getIncrementId());
        $this->queue->create()
            ->setStoreId($storeId)
            ->setEntityType('order')
            ->setIncrementId($order->getIncrementId()) //TODO need to make this field a text in the table not int
            ->setEntityBody($type)
            ->setSyncDate($currentTime)
            ->save();
    }
}
