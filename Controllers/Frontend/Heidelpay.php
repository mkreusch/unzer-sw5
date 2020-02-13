<?php

declare(strict_types=1);

use HeidelPayment\Installers\Attributes;
use HeidelPayment\Services\Heidelpay\Webhooks\Handlers\WebhookHandlerInterface;
use HeidelPayment\Services\Heidelpay\Webhooks\Struct\WebhookStruct;
use HeidelPayment\Services\Heidelpay\Webhooks\WebhookSecurityException;
use HeidelPayment\Services\HeidelpayApiLoggerServiceInterface;
use heidelpayPHP\Exceptions\HeidelpayApiException;
use heidelpayPHP\Resources\Payment;
use Shopware\Components\CSRFWhitelistAware;

class Shopware_Controllers_Frontend_Heidelpay extends Shopware_Controllers_Frontend_Payment implements CSRFWhitelistAware
{
    private const WHITELISTED_CSRF_ACTIONS = [
        'executeWebhook',
    ];

    public function completePaymentAction()
    {
        $session   = $this->container->get('session');
        $paymentId = (string) $session->offsetGet('heidelPaymentId');

        if (!$paymentId) {
            $this->getApiLogger()->getPluginLogger()->error(sprintf('There is no payment-id [%s]', $paymentId));

            $this->redirect([
                'controller' => 'checkout',
                'action'     => 'confirm',
            ]);

            return;
        }

        $paymentStateFactory = $this->container->get('heidel_payment.services.payment_status_factory');

        try {
            $heidelpayClient = $this->container->get('heidel_payment.services.api_client')->getHeidelpayClient();

            $paymentObject = $heidelpayClient->fetchPayment($paymentId);
        } catch (HeidelpayApiException $apiException) {
            $this->getApiLogger()->logException(sprintf('Error while receiving payment details on finish page for payment-id [%s]', $paymentId), $apiException);

            $this->redirect([
                'controller' => 'checkout',
                'action'     => 'confirm',
            ]);

            return;
        } catch (RuntimeException $ex) {
            $this->getApiLogger()->getPluginLogger()->error(
                sprintf('Error while receiving payment details on finish page for payment-id [%s]', $paymentId),
                $ex->getTrace
            );

            $this->redirect(
                [
                    'controller' => 'checkout',
                    'action'     => 'confirm',
                ]
            );
        }

        $errorMessage = $this->container->get('heidel_payment.services.payment_validator')
            ->validatePaymentObject($paymentObject, $this->getPaymentShortName());

        if (!empty($errorMessage)) {
            $this->redirectToErrorPage($errorMessage);

            return;
        }

        $basketSignatureHeidelpay = $paymentObject->getMetadata()->getMetadata('basketSignature');
        $this->loadBasketFromSignature($basketSignatureHeidelpay);

        $currentOrderNumber = $this->saveOrder($paymentObject->getId(), $paymentObject->getId(), $paymentStateFactory->getPaymentStatusId($paymentObject));

        if ($currentOrderNumber) {
            $orderId = $this->getModelManager()->getDBALQueryBuilder()
                ->select('id')
                ->from('s_order')
                ->where('ordernumber = :currentOrderNumber')
                ->setParameter('currentOrderNumber', (string) $currentOrderNumber)
                ->execute()->fetchColumn();

            if ($orderId) {
                $this->container->get('shopware_attribute.data_persister')
                    ->persist([Attributes::HEIDEL_ATTRIBUTE_TRANSACTION_ID => $paymentObject->getOrderId()], 's_order_attributes', $orderId);
            }
        }

        // Done, redirect to the finish page
        $this->redirect([
            'module'     => 'frontend',
            'controller' => 'checkout',
            'action'     => 'finish',
        ]);
    }

    public function executeWebhookAction()
    {
        $webhookStruct = new WebhookStruct($this->request->getRawBody());

        $webhookHandlerFactory  = $this->container->get('heidel_payment.webhooks.factory');
        $heidelpayClientService = $this->container->get('heidel_payment.services.api_client');
        $handlers               = $webhookHandlerFactory->getWebhookHandlers($webhookStruct->getEvent());

        /** @var WebhookHandlerInterface $webhookHandler */
        foreach ($handlers as $webhookHandler) {
            if ($webhookStruct->getPublicKey() !== $heidelpayClientService->getPublicKey()) {
                throw new WebhookSecurityException();
            }

            $webhookHandler->execute($webhookStruct);
        }

        $this->Front()->Plugins()->ViewRenderer()->setNoRender();
        $this->Response()->setHttpResponseCode(200);
    }

    public function getCustomerDataAction()
    {
        $this->Front()->Plugins()->Json()->setRenderer();

        $session                  = $this->container->get('session');
        $userData                 = $session->offsetGet('sOrderVariables')['sUserData'];
        $customerHydrationService = $this->container->get('heidel_payment.resource_hydrator.business_customer');

        if (!empty($userData)) {
            $heidelpayCustomer = $customerHydrationService->hydrateOrFetch($userData);
        }

        $this->view->assign([
            'success'  => isset($heidelpayCustomer),
            'customer' => $heidelpayCustomer->expose(),
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function getWhitelistedCSRFActions(): array
    {
        return self::WHITELISTED_CSRF_ACTIONS;
    }

    protected function getApiLogger(): HeidelpayApiLoggerServiceInterface
    {
        return $this->container->get('heidel_payment.services.api_logger');
    }

    private function getPaymentObject(string $paymentId): ?Payment
    {
        try {
            $heidelpayClient = $this->container->get('heidel_payment.services.api_client')->getHeidelpayClient();

            $paymentObject = $heidelpayClient->fetchPayment($paymentId);
        } catch (HeidelpayApiException | RuntimeException $exception) {
            $this->getApiLogger()->logException(sprintf('Error while receiving payment details on finish page for payment-id [%s]', $paymentId), $exception);
        }

        return $paymentObject ?: null;
    }

    private function redirectToErrorPage(string $message)
    {
        $this->redirect([
            'controller'       => 'checkout',
            'action'           => 'shippingPayment',
            'heidelpayMessage' => base64_encode($message),
        ]);
    }

    private function getMessageFromPaymentTransaction(Payment $payment): string
    {
        // Check the result message of the transaction to find out what went wrong.
        $transaction = $payment->getAuthorization();

        if ($transaction instanceof Authorization) {
            return $transaction->getMessage()->getCustomer();
        }

        $transaction = $payment->getChargeByIndex(0);

        return $transaction->getMessage()->getCustomer();
    }
}
