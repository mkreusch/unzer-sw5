<?php

declare(strict_types=1);

namespace UnzerPayment\Services\DocumentHandler;

use Doctrine\DBAL\Connection;
use Exception;
use Psr\Log\LoggerInterface;
use UnzerPayment\Components\ViewBehaviorHandler\ViewBehaviorHandlerInterface;

class DocumentHandlerService implements DocumentHandlerServiceInterface
{
    /** @var Connection */
    private $connection;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(Connection $connection, LoggerInterface $logger)
    {
        $this->connection = $connection;
        $this->logger     = $logger;
    }

    public function isDocumentCreatedByOrderId(int $orderId, int $invoiceType = ViewBehaviorHandlerInterface::DOCUMENT_TYPE_INVOICE): bool
    {
        try {
            return $this->connection->createQueryBuilder()
                    ->select('id')
                    ->from('s_order_documents')
                    ->where('orderId = :orderId')
                    ->andWhere('type = :invoiceType')
                    ->setParameter('orderId', $orderId)
                    ->setParameter('invoiceType', $invoiceType)
                    ->execute()->rowCount() > 0;
        } catch (Exception $ex) {
            $this->logger->error($ex->getMessage(), $ex->getTrace());

            return false;
        }
    }

    public function getDocumentIdByOrderId(int $orderId, int $invoiceType = ViewBehaviorHandlerInterface::DOCUMENT_TYPE_INVOICE): int
    {
        return (int) $this->connection->createQueryBuilder()
            ->select('docID')
            ->from('s_order_documents')
            ->where('orderId = :orderId')
            ->andWhere('type = :invoiceType')
            ->setParameter('orderId', $orderId)
            ->setParameter('invoiceType', $invoiceType)
            ->execute()->fetchColumn();
    }
}
