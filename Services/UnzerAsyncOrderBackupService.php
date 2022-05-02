<?php

declare(strict_types=1);

namespace UnzerPayment\Services;

use Doctrine\DBAL\Connection;
use Enlight_Components_Session_Namespace;
use Psr\Log\LoggerInterface;
use Shopware_Components_Modules;
use sOrder;
use UnzerSDK\Resources\Payment;

class UnzerAsyncOrderBackupService
{
    public const TABLE_NAME        = 's_plugin_unzer_order_ext_backup';
    public const ASYNC_BACKUP_TYPE = 'UnzerAsyncWebhook';

    /** @var Connection */
    private $connection;

    /** @var LoggerInterface */
    private $logger;

    /** @var Enlight_Components_Session_Namespace */
    private $session;

    /** @var sOrder */
    private $sOrder;

    public function __construct(
        Connection $connection,
        LoggerInterface $logger,
        Enlight_Components_Session_Namespace $session,
        Shopware_Components_Modules $modules
    ) {
        $this->connection = $connection;
        $this->logger     = $logger;
        $this->session    = $session;
        $this->sOrder     = $modules->Order();
    }

    public function insertData(array $userData, array $basketData, string $unzerOrderId, string $paymentName): void
    {
        $encodedBasket = json_encode($basketData);
        $encodedUser   = json_encode($userData);
        $sComment      = $this->session->get('sComment');
        $dispatchId    = $this->session->get('sDispatch') ?? $this->session->get('sOrderVariables')['sDispatch'];

        $queryBuilder = $this->connection->createQueryBuilder();

        $queryBuilder->insert(self::TABLE_NAME)
            ->values([
                'unzer_order_id' => ':unzerOrderId',
                'payment_name'   => ':paymentName',
                'user_data'      => ':userData',
                'basket_data'    => ':basketData',
                's_comment'      => ':sComment',
                'dispatch_id'    => ':dispatchId',
            ])->setParameters([
                'unzerOrderId' => $unzerOrderId,
                'paymentName'  => $paymentName,
                'userData'     => $encodedUser,
                'basketData'   => $encodedBasket,
                'sComment'     => $sComment,
                'dispatchId'   => $dispatchId ?? 0,
            ])->execute();
    }

    public function createOrderFromUnzerOrderId(Payment $payment): void
    {
        $transactionId = $payment->getOrderId();

        $orderId = $this->connection->createQueryBuilder()
            ->select('id')
            ->from('s_order')
            ->where('transactionID = :transactionId')
            ->setParameter('transactionId', $transactionId)
            ->execute()
            ->fetchColumn();

        if (!empty($orderId)) {
            $this->removeBackupData($transactionId);

            return;
        }

        $orderData = $this->readData($transactionId);

        if (empty($orderData)) {
            throw new \RuntimeException('NoOrderFound');
        }

        try {
            $orderNumber = $this->saveOrder($payment, $orderData);
        } catch (\Throwable $t) {
            $this->logger->error(sprintf(
                'Could not create order for unzerOrderId: %s due to %s',
                $transactionId, $t->getMessage()
            ), ['trace' => $t->getTraceAsString(), 'code' => $t->getCode()]);
        }

        if (!empty($orderNumber)) {
            $userData = json_decode($orderData['user_data'], true);

            $this->removeBackupData($transactionId);
            $this->removeBasketData((int) ($userData['additional']['user']['id']), $payment->getId());
        }
    }

    private function readData(string $unzerOrderId): array
    {
        /** @var array|false $data */
        $data = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(self::TABLE_NAME)
            ->where('unzer_order_id = :unzerOrderId')
            ->setParameter('unzerOrderId', $unzerOrderId)
            ->execute()->fetchAll();

        return $data === false ? [] : current($data);
    }

    private function saveOrder(Payment $paymentObject, array $backupData): string
    {
        if (!array_key_exists('user_data', $backupData) || !array_key_exists('basket_data', $backupData)) {
            throw new \RuntimeException('missing data to create order');
        }

        $user       = json_decode($backupData['user_data'], true);
        $basket     = json_decode($backupData['basket_data'], true);
        $dispatchId = (int) $backupData['dispatch_id'];
        $sComment   = $backupData['s_comment'];

        $this->sOrder->sUserData                = $user;
        $this->sOrder->sComment                 = $sComment;
        $this->sOrder->sBasketData              = $basket;
        $this->sOrder->sAmount                  = $basket['sAmount'];
        $this->sOrder->sAmountWithTax           = !empty($basket['AmountWithTaxNumeric']) ? $basket['AmountWithTaxNumeric'] : $basket['AmountNumeric'];
        $this->sOrder->sAmountNet               = $basket['AmountNetNumeric'];
        $this->sOrder->sShippingcosts           = $basket['sShippingcosts'];
        $this->sOrder->sShippingcostsNumeric    = $basket['sShippingcostsWithTax'];
        $this->sOrder->sShippingcostsNumericNet = $basket['sShippingcostsNet'];
        $this->sOrder->bookingId                = $paymentObject->getOrderId();
        $this->sOrder->dispatchId               = $dispatchId;
        $this->sOrder->sNet                     = empty($user['additional']['charge_vat']);
        $this->sOrder->uniqueID                 = $paymentObject->getId();
        $this->sOrder->deviceType               = self::ASYNC_BACKUP_TYPE;

        return $this->sOrder->sSaveOrder();
    }

    private function removeBackupData(string $unzerOrderId): void
    {
        try {
            $this->connection->delete(
                self::TABLE_NAME,
                ['unzer_order_id' => $unzerOrderId],
                ['unzer_order_id' => \PDO::PARAM_STR]
            );
        } catch (\Throwable $t) {
            $this->logger->error(sprintf(
                'Could not remove order backup for unzerOrderId: %s due to %s',
                $unzerOrderId, $t->getMessage()
            ), ['trace' => $t->getTraceAsString(), 'code' => $t->getCode()]);
        }
    }

    private function removeBasketData(int $customerId, string $paymentId): void
    {
        if ($customerId === 0) { //due to the int cast `null` becomes `0`
            return;
        }

        $sessionId = $this->connection->createQueryBuilder()
            ->select('sessionID')
            ->from('s_user')
            ->where('id = :customerId')
            ->setParameter('customerId', $customerId)
            ->execute()
            ->fetchColumn();

        if (empty($sessionId)) {
            return;
        }

        try {
            $this->connection->delete(
                's_order',
                ['temporaryID' => $paymentId, 'ordernumber' => '0']
            );

            $this->connection->delete(
                's_order_basket',
                ['sessionID' => current($sessionId), 'userID' => $customerId, 'lastviewport' => 'checkout'],
                ['sessionID' => \PDO::PARAM_STR, 'userID' => \PDO::PARAM_INT, 'lastviewport' => \PDO::PARAM_STR]
            );
        } catch (\Throwable $t) {
            $this->logger->error(sprintf(
                'Could not remove order data userID: %s with sessionID: %s due to %s',
                $customerId, $sessionId, $t->getMessage()
            ), ['trace' => $t->getTraceAsString(), 'code' => $t->getCode()]);
        }
    }
}
