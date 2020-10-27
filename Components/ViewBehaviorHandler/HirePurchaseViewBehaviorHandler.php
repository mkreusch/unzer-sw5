<?php

declare(strict_types=1);

namespace UnzerPayment\Components\ViewBehaviorHandler;

use Enlight_View_Default as View;
use UnzerPayment\Services\HeidelpayApiLogger\HeidelpayApiLoggerServiceInterface;
use UnzerPayment\Services\HeidelpayClient\HeidelpayClientServiceInterface;
use heidelpayPHP\Exceptions\HeidelpayApiException;
use heidelpayPHP\Resources\PaymentTypes\HirePurchaseDirectDebit;
use Smarty_Data;

class HirePurchaseViewBehaviorHandler implements ViewBehaviorHandlerInterface
{
    /** @var HeidelpayClientServiceInterface */
    private $heidelpayClient;

    /** @var HeidelpayApiLoggerServiceInterface */
    private $apiLoggerService;

    public function __construct(HeidelpayClientServiceInterface $heidelpayClientService, HeidelpayApiLoggerServiceInterface $apiLoggerService)
    {
        $this->heidelpayClient  = $heidelpayClientService;
        $this->apiLoggerService = $apiLoggerService;
    }

    public function processCheckoutFinishBehavior(View $view, string $transactionId): void
    {
        $charge = $this->getPaymentTypeTransactionId($transactionId);

        if (!$charge) {
            return;
        }

        $view->assign([
            'heidelpay' => [
                'interest'          => $charge->getTotalInterestAmount(),
                'totalWithInterest' => $charge->getTotalAmount(),
            ],
        ]);
    }

    /**
     * {@inheritdoc}
     *
     * Is not used for this payment
     */
    public function processDocumentBehavior(Smarty_Data $viewAssignments, string $paymentId, int $documentTypeId): void
    {
    }

    /**
     * {@inheritdoc}
     *
     * Is not used for this payment
     */
    public function processEmailVariablesBehavior(string $paymentId): array
    {
        return [];
    }

    private function getPaymentTypeTransactionId(string $transactionId): ?HirePurchaseDirectDebit
    {
        try {
            $paymentType = $this->heidelpayClient->getHeidelpayClient()
                ->fetchPayment($transactionId)->getChargeByIndex(0);

            if ($paymentType) {
                return $paymentType->getPayment()->getPaymentType();
            }
        } catch (HeidelpayApiException $apiException) {
            $this->apiLoggerService->logException(sprintf('Error while fetching first charge of payment with payment-id [%s]', $paymentId), $apiException);
        }

        return null;
    }
}
