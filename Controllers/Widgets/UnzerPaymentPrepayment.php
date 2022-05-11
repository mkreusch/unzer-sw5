<?php

declare(strict_types=1);

use UnzerPayment\Components\PaymentHandler\Traits\CanCharge;
use UnzerPayment\Components\PaymentHandler\Traits\OrderComment;
use UnzerPayment\Controllers\AbstractUnzerPaymentController;
use UnzerSDK\Exceptions\UnzerApiException;
use UnzerSDK\Resources\PaymentTypes\Prepayment;

class Shopware_Controllers_Widgets_UnzerPaymentPrepayment extends AbstractUnzerPaymentController
{
    use CanCharge;
    use OrderComment;
    public const SNIPPET_NAMESPACE = 'frontend/unzer_payment/behaviors/unzerPaymentPrepayment/finish';

    public function createPaymentAction(): void
    {
        try {
            parent::pay();
            $this->paymentType = $this->unzerPaymentClient->createPaymentType(new Prepayment());
            $redirectUrl       = $this->charge($this->paymentDataStruct->getReturnUrl());
            $this->setOrderComment(self::SNIPPET_NAMESPACE);
        } catch (UnzerApiException $apiException) {
            $this->getApiLogger()->logException('Error while creating prepayment payment', $apiException);
            $redirectUrl = $this->getUnzerPaymentErrorUrl($apiException->getClientMessage());
        } catch (RuntimeException $runtimeException) {
            $redirectUrl = $this->getUnzerPaymentErrorUrlFromSnippet('communicationError');
        } finally {
            $this->view->assign('redirectUrl', $redirectUrl);
        }
    }
}
