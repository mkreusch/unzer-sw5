<?php

declare(strict_types=1);

namespace UnzerPayment\Subscribers\Model;

use DateTimeImmutable;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Events;
use UnzerPayment\Components\ViewBehaviorHandler\ViewBehaviorHandlerInterface;
use UnzerPayment\Installers\PaymentMethods;
use UnzerPayment\Services\ConfigReader\ConfigReaderServiceInterface;
use UnzerPayment\Services\DependencyProvider\DependencyProviderServiceInterface;
use UnzerPayment\Services\UnzerPaymentApiLogger\UnzerPaymentApiLoggerServiceInterface;
use UnzerPayment\Services\OrderStatus\OrderStatusService;
use UnzerPayment\Services\OrderStatus\OrderStatusServiceInterface;
use heidelpayPHP\Exceptions\HeidelpayApiException;
use heidelpayPHP\Heidelpay;
use heidelpayPHP\Resources\TransactionTypes\Shipment;
use Shopware\Models\Order\Document\Document;
use Shopware\Models\Order\Order;

class OrderSubscriber implements EventSubscriber
{
    public const ALLOWED_FINALIZE_METHODS = [
        PaymentMethods::PAYMENT_NAME_INVOICE_FACTORING,
        PaymentMethods::PAYMENT_NAME_INVOICE_GUARANTEED,
    ];

    /** @var DependencyProviderServiceInterface */
    private $dependencyProvider;

    /** @var ConfigReaderServiceInterface */
    private $configReader;

    /** @var UnzerPaymentApiLoggerServiceInterface */
    private $apiLogger;

    /** @var Heidelpay */
    private $unzerPaymentClient;

    /** @var EntityManager */
    private $entityManager;

    /**
     * Since this class requires both (ApiService and ConfigService) which have a dependency it's required
     * to use the dependency provider to avoid an Symfony\Component\DependencyInjection\Exception\ServiceCircularReferenceException
     * while initializing this subscriber.
     */
    public function __construct(DependencyProviderServiceInterface $dependencyProvider)
    {
        $this->dependencyProvider = $dependencyProvider;
    }

    /**
     * {@inheritdoc}
     */
    public function getSubscribedEvents(): array
    {
        return [
            Events::postUpdate,
        ];
    }

    public function postUpdate(LifecycleEventArgs $args): void
    {
        if (!$args->getEntity() instanceof Order) {
            return;
        }

        /** @var ConfigReaderServiceInterface $configReader */
        $this->configReader = $this->dependencyProvider->get('unzer_payment.services.config_reader');

        /** @var Order $order */
        $order = $args->getEntity();

        if (!$this->isShipmentAllowed($order)) {
            return;
        }

        /** @var null|Document $invoiceDocument */
        $invoiceDocument = $order->getDocuments()->filter(static function (Document $entry) {
            return (int) $entry->getType() === ViewBehaviorHandlerInterface::DOCUMENT_TYPE_INVOICE;
        })->last();

        if (!$invoiceDocument) {
            return;
        }

        /** @var UnzerPaymentApiLoggerServiceInterface $apiLogger */
        $this->apiLogger       = $this->dependencyProvider->get('unzer_payment.services.api_logger');
        $this->unzerPaymentClient = new Heidelpay($this->configReader->get('private_key'), $order->getShop()->getLocale()->getLocale());
        $this->entityManager   = $args->getEntityManager();

        $unzerPaymentShipment = $this->shipOrder(
            $order,
            (string) $invoiceDocument->getDocumentId()
        );

        if (!$unzerPaymentShipment) {
            $this->apiLogger->getPluginLogger()->error(sprintf('Unable to set new payment status due to error in shipping update for order [%s] with payment-id [%s] and invoice-id [%s]', $order->getNumber(), $order->getTemporaryId(), $invoiceDocument->getDocumentId()));

            return;
        }

        $this->updateOrderPaymentStatus($unzerPaymentShipment);
    }

    private function isShipmentAllowed(Order $order): bool
    {
        $orderStatusForShipping = $this->configReader->get('shipping_status', $order->getShop()->getId());

        if (empty($orderStatusForShipping)) {
            return false;
        }

        if ($order->getAttribute()->getUnzerPaymentShippingDate() !== null
            || $order->getOrderStatus()->getId() !== $orderStatusForShipping
            || !in_array($order->getPayment()->getName(), self::ALLOWED_FINALIZE_METHODS, false)) {
            return false;
        }

        return true;
    }

    private function shipOrder(Order $order, string $documentId): ?Shipment
    {
        try {
            $shipResult = $this->unzerPaymentClient->ship($order->getTemporaryId(), $documentId);

            $orderAttributes = $order->getAttribute();
            $orderAttributes->setUnzerPaymentShippingDate(new DateTimeImmutable());

            $this->entityManager->flush($orderAttributes);
        } catch (HeidelpayApiException $apiException) {
            $this->apiLogger->logException(sprintf('Unable to send shipping notification for order [%s] with payment-id [%s] and invoice-id [%s]', $order->getNumber(), $order->getTemporaryId(), $documentId), $apiException);
        }

        return $shipResult ?: null;
    }

    private function updateOrderPaymentStatus(Shipment $unzerPaymentShipment): void
    {
        /** @var OrderStatusServiceInterface $orderStatusService */
        $orderStatusService = $this->dependencyProvider->get('unzer_payment.services.order_status');
        $unzerPayment      = $unzerPaymentShipment->getPayment();

        if (!$unzerPayment || !((bool) $this->configReader->get('automatic_payment_status'))) {
            return;
        }

        $orderStatusService->updatePaymentStatusByPayment($unzerPayment);
    }
}
