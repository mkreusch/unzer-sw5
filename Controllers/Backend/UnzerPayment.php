<?php

declare(strict_types=1);

use UnzerPayment\Components\Hydrator\ArrayHydrator\ArrayHydratorInterface;
use UnzerPayment\Services\DocumentHandler\DocumentHandlerServiceInterface;
use UnzerPayment\Services\UnzerPaymentApiLogger\UnzerPaymentApiLoggerServiceInterface;
use UnzerPayment\Subscribers\Model\OrderSubscriber;
use heidelpayPHP\Constants\CancelReasonCodes;
use heidelpayPHP\Exceptions\HeidelpayApiException;
use heidelpayPHP\Heidelpay;
use heidelpayPHP\Resources\Payment;
use heidelpayPHP\Resources\TransactionTypes\Cancellation;
use heidelpayPHP\Resources\TransactionTypes\Charge;
use heidelpayPHP\Resources\TransactionTypes\Shipment;
use Shopware\Components\CSRFWhitelistAware;
use Shopware\Models\Order\Order;
use Shopware\Models\Shop\Shop;

class Shopware_Controllers_Backend_UnzerPayment extends Shopware_Controllers_Backend_Application implements CSRFWhitelistAware
{
    private const WHITELISTED_CSRF_ACTIONS = [
        'registerWebhooks',
        'testCredentials',
    ];

    /**
     * {@inheritdoc}
     */
    protected $model = Order::class;

    /**
     * {@inheritdoc}
     */
    protected $alias = 'sOrder';

    /** @var Heidelpay */
    private $heidelpayClient;

    /** @var UnzerPaymentApiLoggerServiceInterface */
    private $logger;

    /** @var DocumentHandlerServiceInterface */
    private $documentHandlerService;

    /**
     * {@inheritdoc}
     */
    public function preDispatch(): void
    {
        $this->Front()->Plugins()->Json()->setRenderer();

        $this->logger                 = $this->container->get('heidel_payment.services.api_logger');
        $this->documentHandlerService = $this->container->get('heidel_payment.services.document_handler');
        $modelManager                 = $this->container->get('models');
        $shopId                       = $this->request->get('shopId');

        /** @var Shop $shop */
        if ($shopId) {
            $shop = $modelManager->find(Shop::class, $shopId);
        } else {
            $shop = $modelManager->getRepository(Shop::class)->getActiveDefault();
        }

        if ($shop === null) {
            throw new RuntimeException('Could not determine shop context');
        }

        try {
            $this->heidelpayClient = $this->getHeidelpayClient();
        } catch (RuntimeException $ex) {
            $this->view->assign([
                'success' => false,
                'message' => $ex->getMessage(),
            ]);

            $this->logger->getPluginLogger()->error(sprintf('Could not initialize the Heidelpay client: %s', $ex->getMessage()));
        }
    }

    public function paymentDetailsAction(): void
    {
        if (!$this->heidelpayClient) {
            return;
        }

        /** @var ArrayHydratorInterface $arrayHydrator */
        $arrayHydrator = $this->container->get('heidel_payment.array_hydrator.payment.lazy');
        $orderId       = $this->Request()->get('orderId');
        $transactionId = $this->Request()->get('transactionId');
        $paymentName   = $this->Request()->get('paymentName');

        try {
            $result                    = $this->heidelpayClient->fetchPayment($transactionId);
            $data                      = $arrayHydrator->hydrateArray($result);
            $data['isFinalizeAllowed'] = false;

            if (count($data['shipments']) < 1 && in_array($paymentName, OrderSubscriber::ALLOWED_FINALIZE_METHODS)
                && $this->documentHandlerService->isDocumentCreatedByOrderId((int) $orderId)
            ) {
                $data['isFinalizeAllowed'] = true;
            }

            $this->view->assign([
                'success' => true,
                'data'    => $data,
            ]);
        } catch (HeidelpayApiException $apiException) {
            $this->view->assign([
                'success' => false,
                'message' => $apiException->getClientMessage(),
            ]);

            $this->logger->logException(sprintf('Error while requesting payment details for order-id [%s]', $orderId), $apiException);
        }
    }

    public function loadPaymentTransactionAction(): void
    {
        if (!$this->heidelpayClient) {
            return;
        }

        $orderId         = $this->Request()->get('heidelpayId');
        $transactionId   = $this->Request()->get('transactionId');
        $transactionType = $this->Request()->get('transactionType');

        try {
            $response = [
                'success' => false,
                'data'    => 'no valid transaction type found',
            ];

            $payment = $this->heidelpayClient->fetchPaymentByOrderId($orderId);

            switch ($transactionType) {
                case 'charge':
                    /** @var Charge $transactionResult */
                    $transactionResult = $payment->getCharge($transactionId);

                    break;
                case 'cancellation':
                    /** @var Cancellation $transactionResult */
                    $transactionResult = $payment->getCancellation($transactionId);

                    break;
                case 'shipment':
                    /** @var Shipment $transactionResult */
                    $transactionResult = $payment->getShipment($transactionId);

                    break;
                default:
                    $this->view->assign([
                        'success' => false,
                        'data'    => 'no valid transaction type found',
                    ]);

                    return;
            }

            if ($transactionResult !== null) {
                $response = [
                    'success' => true,
                    'data'    => [
                        'type'    => $transactionType,
                        'id'      => $transactionResult->getId(),
                        'shortId' => $transactionResult->getShortId(),
                        'date'    => $transactionResult->getDate(),
                        'amount'  => $transactionResult->getAmount(),
                    ],
                ];
            }
        } catch (HeidelpayApiException $apiException) {
            $response = [
                'success' => false,
                'message' => $apiException->getClientMessage(),
            ];

            $this->logger->logException(sprintf('Error while requesting transaction details for order-id [%s]', $orderId), $apiException);
        }

        $this->view->assign($response);
    }

    public function chargeAction(): void
    {
        if (!$this->heidelpayClient) {
            return;
        }

        $paymentId = $this->request->get('paymentId');
        $amount    = floatval($this->request->get('amount'));

        if ($amount === 0) {
            return;
        }

        try {
            $result = $this->heidelpayClient->chargeAuthorization($paymentId, $amount);

            $this->updateOrderPaymentStatus($result->getPayment());

            $this->view->assign([
                'success' => true,
                'data'    => $result->expose(),
                'message' => $result->getMessage(),
            ]);
        } catch (HeidelpayApiException $apiException) {
            $this->view->assign([
                'success' => false,
                'message' => $apiException->getClientMessage(),
            ]);

            $this->logger->logException(sprintf('Error while charging payment with id [%s] with an amount of [%s]', $paymentId, $amount), $apiException);
        }
    }

    public function refundAction(): void
    {
        if (!$this->heidelpayClient) {
            return;
        }

        $paymentId = $this->request->get('paymentId');
        $amount    = floatval($this->request->get('amount'));
        $chargeId  = $this->request->get('chargeId');

        if ($amount === 0) {
            return;
        }

        try {
            $charge = $this->heidelpayClient->fetchChargeById($paymentId, $chargeId);
            $result = $charge->cancel($amount, CancelReasonCodes::REASON_CODE_CANCEL);

            $this->updateOrderPaymentStatus($result->getPayment());

            $this->view->assign([
                'success' => true,
                'data'    => $result->expose(),
                'message' => $result->getMessage(),
            ]);
        } catch (HeidelpayApiException $apiException) {
            $this->view->assign([
                'success' => false,
                'message' => $apiException->getClientMessage(),
            ]);

            $this->logger->logException(sprintf('Error while refunding the charge with id [%s] (Payment-Id: [%s]) with an amount of [%s]', $chargeId, $paymentId, $amount), $apiException);
        }
    }

    public function finalizeAction(): void
    {
        if (!$this->heidelpayClient) {
            return;
        }

        $orderId   = $this->request->get('orderId');
        $paymentId = $this->request->get('paymentId');

        $invoiceDocumentId = $this->documentHandlerService->getDocumentIdByOrderId((int) $orderId);

        if (!$invoiceDocumentId) {
            $this->view->assign([
                'success' => false,
                'message' => 'Could not find any invoice for this order.',
            ]);

            return;
        }

        try {
            $result = $this->heidelpayClient->ship($paymentId, (string) $invoiceDocumentId);

            $this->updateOrderPaymentStatus($result->getPayment());

            $this->view->assign([
                'success' => true,
                'data'    => $result->expose(),
                'message' => $result->getMessage(),
            ]);
        } catch (HeidelpayApiException $apiException) {
            $this->view->assign([
                'success' => false,
                'message' => $apiException->getClientMessage(),
            ]);

            $this->logger->logException(sprintf('Error while sending shipping notification for the payment-id [%s]', $paymentId), $apiException);
        }
    }

    public function registerWebhooksAction(): void
    {
        if (!$this->heidelpayClient) {
            return;
        }

        $success = false;
        $message = '';
        $url     = $this->container->get('router')->assemble([
            'controller' => 'Heidelpay',
            'action'     => 'executeWebhook',
            'module'     => 'frontend',
        ]);

        try {
            $this->heidelpayClient->deleteAllWebhooks();
            $this->heidelpayClient->createWebhook($url, 'all');

            $this->logger->getPluginLogger()->alert(sprintf('All webhooks have been successfully registered to the following URL: %s', $url));

            $success = true;
        } catch (HeidelpayApiException $apiException) {
            $message = $apiException->getMerchantMessage();

            $this->logger->logException(sprintf('Error while registering the webhooks to [%s]', $url), $apiException);
        } catch (RuntimeException $genericException) {
            $message = $genericException->getMessage();

            $this->logger->getPluginLogger()->error(sprintf('Error while registering the webhooks to [%s]: %s', $url, $message));
        }

        $this->view->assign(compact('success', 'message'));
    }

    public function testCredentialsAction(): void
    {
        if (!$this->heidelpayClient) {
            return;
        }

        $success = false;
        $message = '';

        try {
            $configService = $this->container->get('heidel_payment.services.config_reader');
            $publicKey     = (string) $configService->get('public_key');
            $result        = $this->heidelpayClient->fetchKeypair();

            if ($result->getPublicKey() !== $publicKey) {
                $message = sprintf('The given key %s is unknown or invalid.', $publicKey);

                $this->logger->getPluginLogger()->error(sprintf('API Credentials test failed: The given key %s is unknown or invalid.', $publicKey));
            } else {
                $success = true;

                $this->logger->getPluginLogger()->alert('API Credentials test succeeded.');
            }
        } catch (HeidelpayApiException $apiException) {
            $message = $apiException->getMerchantMessage();

            $this->logger->getPluginLogger()->error(sprintf('API Credentials test failed: %s', $message));
        } catch (RuntimeException $genericException) {
            $message = $genericException->getMessage();

            $this->logger->getPluginLogger()->error(sprintf('API Credentials test failed: %s', $message));
        }

        $this->view->assign(compact('success', 'message'));
    }

    /**
     * {@inheritdoc}
     */
    public function getWhitelistedCSRFActions(): array
    {
        return self::WHITELISTED_CSRF_ACTIONS;
    }

    private function getHeidelpayClient(): Heidelpay
    {
        $locale        = $this->container->get('locale')->toString();
        $configService = $this->container->get('heidel_payment.services.config_reader');

        $privateKey = (string) $configService->get('private_key');

        $heidelpayClient = new Heidelpay($privateKey, $locale);
        $heidelpayClient->setDebugMode($configService->get('transaction_mode') === 'test');
        $heidelpayClient->setDebugHandler($this->logger);

        return $heidelpayClient;
    }

    private function updateOrderPaymentStatus(Payment $payment = null): void
    {
        if (!$payment || !((bool) $this->container->get('heidel_payment.services.config_reader')->get('automatic_payment_status'))) {
            return;
        }

        $this->container->get('heidel_payment.services.order_status')->updatePaymentStatusByPayment($payment);
    }
}
