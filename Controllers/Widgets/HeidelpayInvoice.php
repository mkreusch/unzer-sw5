<?php

use HeidelPayment\Controllers\AbstractHeidelpayPaymentController;
use heidelpayPHP\Exceptions\HeidelpayApiException;
use heidelpayPHP\Resources\PaymentTypes\Invoice as InvoicePaymentType;

class Shopware_Controllers_Widgets_HeidelpayInvoice extends AbstractHeidelpayPaymentController
{
    /** @var InvoicePaymentType */
    protected $paymentType;

    public function createPaymentAction()
    {
        $this->paymentType = new InvoicePaymentType();
        $this->paymentType->setParentResource($this->heidelpayClient);

        $heidelBasket   = $this->getHeidelpayBasket();
        $heidelCustomer = $this->getHeidelpayB2cCustomer();
        $heidelMetadata = $this->getHeidelpayMetadata();
        $returnUrl      = $this->getHeidelpayReturnUrl();

        try {
            $heidelCustomer = $this->heidelpayClient->createOrUpdateCustomer($heidelCustomer);

            $result = $this->paymentType->charge(
                $heidelBasket->getAmountTotalGross(),
                $heidelBasket->getCurrencyCode(),
                $returnUrl,
                $heidelCustomer,
                $heidelBasket->getOrderId(),
                $heidelMetadata,
                $heidelBasket
            );

            $this->getApiLogger()->logResponse('Created invoice payment', $result);

            $this->redirect($result->getPayment()->getRedirectUrl() ?: $returnUrl);
        } catch (HeidelpayApiException $apiException) {
            $this->getApiLogger()->logException('Error while creating invoice payment', $apiException);
            $this->redirect($this->getHeidelpayErrorUrl($apiException->getClientMessage()));
        }

        if (isset($result)) {
            $this->session->offsetSet('heidelPaymentId', $result->getPaymentId());
        }
    }
}
