<?php

declare(strict_types=1);

namespace UnzerPayment\Controllers;

use Doctrine\DBAL\Connection;
use Enlight_Components_Session_Namespace;
use Enlight_Controller_Router;
use PDO;
use RuntimeException;
use Shopware\Bundle\AttributeBundle\Service\DataPersister;
use Shopware_Components_Snippet_Manager;
use Shopware_Controllers_Frontend_Payment;
use UnzerPayment\Components\Hydrator\ResourceHydrator\ResourceHydratorInterface;
use UnzerPayment\Components\PaymentHandler\Structs\PaymentDataStruct;
use UnzerPayment\Components\ResourceMapper\ResourceMapperInterface;
use UnzerPayment\Installers\PaymentMethods;
use UnzerPayment\Services\UnzerAsyncOrderBackupService;
use UnzerPayment\Services\UnzerPaymentApiLogger\UnzerPaymentApiLoggerServiceInterface;
use UnzerSDK\Exceptions\UnzerApiException;
use UnzerSDK\Resources\Basket as UnzerBasket;
use UnzerSDK\Resources\Customer as UnzerCustomer;
use UnzerSDK\Resources\Metadata as UnzerMetadata;
use UnzerSDK\Resources\Payment;
use UnzerSDK\Resources\PaymentTypes\BasePaymentType;
use UnzerSDK\Resources\Recurring;
use UnzerSDK\Unzer;
use Zend_Currency;

abstract class AbstractUnzerPaymentController extends Shopware_Controllers_Frontend_Payment
{
    public const ALREADY_RECURRING_ERROR_CODE = 'API.640.550.006';

    public const INVOICE_SNIPPET_NAMESPACE    = 'frontend/unzer_payment/behaviors/unzerPaymentInvoice/finish';
    public const PREPAYMENT_SNIPPET_NAMESPACE = 'frontend/unzer_payment/behaviors/unzerPaymentPrepayment/finish';

    /** @var BasePaymentType */
    protected $paymentType;

    /** @var PaymentDataStruct */
    protected $paymentDataStruct;

    /** @var Payment */
    protected $payment;

    /** @var Payment */
    protected $paymentResult;

    /** @var Recurring */
    protected $recurring;

    /** @var Unzer */
    protected $unzerPaymentClient;

    /** @var Enlight_Components_Session_Namespace */
    protected $session;

    /** @var DataPersister */
    protected $dataPersister;

    /** @var bool */
    protected $isAsync = false;

    /** @var bool */
    protected $isRedirectPayment = false;

    /** @var bool */
    protected $isChargeRecurring = false;

    /** @var UnzerAsyncOrderBackupService */
    protected $unzerAsyncOrderBackupService;

    /** @var Connection */
    protected $connection;

    /** @var Zend_Currency */
    protected $currency;

    /** @var Shopware_Components_Snippet_Manager */
    protected $snippetManager;

    /** @var ResourceMapperInterface */
    private $customerMapper;

    /** @var ResourceHydratorInterface */
    private $basketHydrator;

    /** @var ResourceHydratorInterface */
    private $customerHydrator;

    /** @var ResourceHydratorInterface */
    private $businessCustomerHydrator;

    /** @var ResourceHydratorInterface */
    private $metadataHydrator;

    /** @var Enlight_Controller_Router */
    private $router;

    /**
     * {@inheritdoc}
     */
    public function preDispatch(): void
    {
        $this->Front()->Plugins()->Json()->setRenderer();

        try {
            $this->unzerPaymentClient = $this->container->get('unzer_payment.services.api_client')
                ->getUnzerPaymentClient();
        } catch (RuntimeException $ex) {
            $this->handleCommunicationError();

            return;
        }

        $this->customerMapper               = $this->container->get('unzer_payment.mapper.resource');
        $this->customerHydrator             = $this->container->get('unzer_payment.resource_hydrator.private_customer');
        $this->businessCustomerHydrator     = $this->container->get('unzer_payment.resource_hydrator.business_customer');
        $this->basketHydrator               = $this->container->get('unzer_payment.resource_hydrator.basket');
        $this->metadataHydrator             = $this->container->get('unzer_payment.resource_hydrator.metadata');
        $this->connection                   = $this->container->get('dbal_connection');
        $this->unzerAsyncOrderBackupService = $this->container->get(UnzerAsyncOrderBackupService::class);

        $this->router         = $this->front->Router();
        $this->session        = $this->container->get('session');
        $this->snippetManager = $this->container->get('snippets');
        $this->currency       = $this->container->get('currency');

        $paymentTypeId = $this->request->get('resource') !== null ? $this->request->get('resource')['id'] : $this->request->get('typeId');

        if (isset($paymentTypeId) && !empty($paymentTypeId)) {
            try {
                $this->paymentType = $this->unzerPaymentClient->fetchPaymentType($paymentTypeId);
            } catch (UnzerApiException $apiException) {
                $this->getApiLogger()->logException(sprintf('Error while fetching payment type by id [%s]', $paymentTypeId), $apiException);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function postDispatch(): void
    {
        if (!$this->isAsync && !$this->isChargeRecurring) {
            $this->redirect($this->view->getAssign('redirectUrl'));
        }
    }

    public function pay(): void
    {
        $unzerPaymentBasket      = $this->getUnzerPaymentBasket();
        $unzerPaymentCustomer    = $this->getUnzerPaymentCustomer();
        $this->paymentDataStruct = new PaymentDataStruct(
            $this->getAmount(),
            $unzerPaymentBasket->getCurrencyCode(),
            $this->getUnzerPaymentReturnUrl()
        );

        $this->paymentDataStruct->fromArray([
            'customer'    => $unzerPaymentCustomer,
            'metadata'    => $this->getUnzerPaymentMetadata(),
            'basket'      => $unzerPaymentBasket,
            'orderId'     => $unzerPaymentBasket->getOrderId(),
            'card3ds'     => true,
            'isRecurring' => $unzerPaymentBasket->getSpecialParams()['isAbo'] ?: false,
        ]);
        $user = $this->getUser() ?? [];

        if ($this->Request()->has('sComment')) {
            $this->session->offsetSet('sComment', $this->Request()->get('sComment'));
        }

        if ($this->isRedirectPayment) {
            $this->unzerAsyncOrderBackupService->insertData(
                $user,
                $this->getBasket(),
                $unzerPaymentBasket->getOrderId(),
                $this->getPaymentShortName(),
                $this->container->get('shop')
            );
        }
    }

    public function recurring(): void
    {
        $this->isChargeRecurring = true;
        $this->dataPersister     = $this->container->get('shopware_attribute.data_persister');
        $recurringDataHydrator   = $this->container->get('unzer_payment.array_hydrator.recurring_data');
        $this->request->setParam('typeId', 'notNull');

        $recurringData = $recurringDataHydrator->hydrateRecurringData(
            (float) $this->getBasket()['AmountWithTaxNumeric'],
            (int) $this->request->getParam('orderId', 0)
        );

        if (empty($recurringData)
            || !$recurringData['order']
            || !$recurringData['aboId']
            || !$recurringData['basketAmount']
            || !$recurringData['transactionId']
            || $recurringData['basketAmount'] === 0.0
        ) {
            $this->getApiLogger()->getPluginLogger()->error('Recurring activation failed since at least one of the following values is empty:' . json_encode($recurringData));
            $this->view->assign('success', false);

            return;
        }

        $payment = $this->getPaymentByTransactionId($recurringData['transactionId']);

        if (!$payment) {
            $this->getApiLogger()->getPluginLogger()->error('The payment could not be found');
            $this->view->assign('success', false);

            return;
        }

        $this->paymentType = $this->getPaymentTypeByPaymentTypeId($payment->getPaymentType()->getId());

        if (!$this->paymentType) {
            $this->getApiLogger()->getPluginLogger()->error('The payment type could not be created');
            $this->view->assign('success', false);

            return;
        }

        $unzerPaymentBasket      = $this->getRecurringBasket($recurringData['order']);
        $this->paymentDataStruct = new PaymentDataStruct($this->getAmount(), $recurringData['order']['currency'], $this->getChargeRecurringUrl());

        $this->paymentDataStruct->fromArray([
            'basket'           => $unzerPaymentBasket,
            'customer'         => $payment->getCustomer(),
            'orderId'          => $unzerPaymentBasket->getOrderId(),
            'metaData'         => $payment->getMetadata(),
            'paymentReference' => $recurringData['transactionId'],
            'recurringData'    => [
                'swAboId' => (int) $recurringData['aboId'],
            ],
        ]);
    }

    protected function getUnzerPaymentCustomer(): ?UnzerCustomer
    {
        $user           = $this->getUser();
        $additionalData = $this->request->get('additional') ?: [];
        $customerId     = array_key_exists('customerId', $additionalData) ? $additionalData['customerId'] : null;

        try {
            if (!empty($customerId)) {
                $unzerPaymentCustomer = $this->customerMapper->mapMissingFields(
                    $this->unzerPaymentClient->fetchCustomerByExtCustomerId($customerId),
                    $this->getCustomerByUser($user, $additionalData)
                );
            } else {
                $unzerPaymentCustomer = $this->getCustomerByUser($user, $additionalData);
            }

            return $this->unzerPaymentClient->createOrUpdateCustomer($unzerPaymentCustomer);
        } catch (UnzerApiException $apiException) {
            $this->getApiLogger()->logException($apiException->getMessage(), $apiException);
            $this->view->assign('redirectUrl', $this->getUnzerPaymentErrorUrlFromSnippet('communicationError'));

            return null;
        }
    }

    protected function getCustomerByUser(array $user, array $additionalData): UnzerCustomer
    {
        if ($additionalData && array_key_exists('birthday', $additionalData)) {
            $user['additional']['user']['birthday'] = $additionalData['birthday'];
        }

        if (!empty($user['billingaddress']['company']) && in_array($this->getPaymentShortName(), PaymentMethods::IS_B2B_ALLOWED)) {
            return $this->businessCustomerHydrator->hydrateOrFetch($user, $this->unzerPaymentClient);
        }

        return $this->customerHydrator->hydrateOrFetch($user, $this->unzerPaymentClient);
    }

    protected function getUnzerPaymentBasket(): UnzerBasket
    {
        $basket = array_merge($this->getBasket(), [
            'sDispatch' => $this->session->get('sOrderVariables')['sDispatch'],
            'taxFree'   => $this->session->get('taxFree'),
        ]);

        return $this->basketHydrator->hydrateOrFetch($basket, $this->unzerPaymentClient);
    }

    protected function getUnzerPaymentMetadata(): UnzerMetadata
    {
        $metadata = [
            'basketSignature' => $this->persistBasket(),
            'pluginVersion'   => $this->container->get('kernel')->getPlugins()['UnzerPayment']->getVersion(),
            'shopwareVersion' => $this->container->hasParameter('shopware.release.version') ? $this->container->getParameter('shopware.release.version') : 'unknown',
        ];

        return $this->metadataHydrator->hydrateOrFetch($metadata, $this->unzerPaymentClient);
    }

    protected function getUnzerPaymentReturnUrl(): string
    {
        return $this->router->assemble([
            'controller' => 'UnzerPayment',
            'action'     => 'completePayment',
            'module'     => 'frontend',
        ]);
    }

    protected function getChargeRecurringUrl(): string
    {
        return $this->router->assemble([
            'module'     => 'frontend',
            'controller' => 'UnzerPaymentProxy',
            'action'     => 'recurring',
        ]) ?: '';
    }

    protected function getInitialRecurringUrl(): string
    {
        return $this->router->assemble([
            'module'     => 'frontend',
            'controller' => 'UnzerPaymentProxy',
            'action'     => 'initialRecurring',
        ]) ?: '';
    }

    protected function getUnzerPaymentErrorUrl(string $message = ''): string
    {
        return $this->router->assemble([
            'controller'          => 'checkout',
            'action'              => 'shippingPayment',
            'module'              => 'frontend',
            'unzerPaymentMessage' => urlencode($message),
        ]);
    }

    protected function getUnzerPaymentErrorUrlFromSnippet(string $snippetName, string $namespace = 'frontend/unzer_payment/checkout/confirm'): string
    {
        $snippet = $this->snippetManager->getNamespace($namespace)->get($snippetName);

        return $this->getUnzerPaymentErrorUrl($snippet);
    }

    protected function getApiLogger(): UnzerPaymentApiLoggerServiceInterface
    {
        return $this->container->get('unzer_payment.services.api_logger');
    }

    protected function handleCommunicationError(): void
    {
        $errorUrl = $this->getUnzerPaymentErrorUrlFromSnippet('communicationError');

        if ($this->isAsync) {
            $this->view->assign(
                [
                    'success'     => false,
                    'redirectUrl' => $errorUrl,
                ]
            );

            return;
        }

        $this->redirect($errorUrl);
    }

    protected function getOrderDataById(int $orderId): array
    {
        return $this->getModelManager()->getDBALQueryBuilder()
            ->select('*')
            ->from('s_order')
            ->where('id = :orderId')
            ->setParameter('orderId', $orderId)
            ->execute()->fetchAll(PDO::FETCH_ASSOC);
    }

    protected function getAboByOrderId(int $orderId): array
    {
        return $this->getModelManager()->getDBALQueryBuilder()
            ->select('*')
            ->from('s_plugin_swag_abo_commerce_orders')
            ->where('last_order_id = :orderId')
            ->setParameter('orderId', $orderId)
            ->execute()->fetchAll(PDO::FETCH_ASSOC);
    }

    protected function getPaymentByTransactionId(string $transactionId): ?Payment
    {
        if (!$transactionId) {
            return null;
        }

        try {
            $payment = $this->unzerPaymentClient->fetchPaymentByOrderId($transactionId);
        } catch (UnzerApiException $unzerPaymentApiException) {
            $this->getApiLogger()->logException($unzerPaymentApiException->getMessage(), $unzerPaymentApiException);
        }

        return $payment ?: null;
    }

    protected function getPaymentTypeByPaymentTypeId(string $paymentTypeId): ?BasePaymentType
    {
        try {
            $paymentType = $this->unzerPaymentClient->fetchPaymentType($paymentTypeId);
            $paymentType->setParentResource($this->unzerPaymentClient);
        } catch (UnzerApiException $unzerPaymentApiException) {
            $this->getApiLogger()->logException($unzerPaymentApiException->getMessage(), $unzerPaymentApiException);
        }

        return $paymentType ?: null;
    }

    protected function getRecurringBasket(array $order): ?UnzerBasket
    {
        $sOrderVariables                             = $this->session->offsetGet('sOrderVariables');
        $sOrderVariables['sBasket']['sCurrencyName'] = $order['currency'];

        if (empty($sOrderVariables['sBasket']['AmountWithTaxNumeric'])) {
            $sOrderVariables['sBasket']['AmountWithTaxNumeric'] = $sOrderVariables['sBasket']['AmountNumeric'];
        }

        if (empty($sOrderVariables['sBasket']['sAmountWithTax']) && !empty($sOrderVariables['sAmountWithTax'])) {
            $sOrderVariables['sBasket']['sAmountWithTax'] = $sOrderVariables['sAmountWithTax'];
        }

        $this->session->offsetSet('sOrderVariables', $sOrderVariables);

        $unzerPaymentBasket = $this->getUnzerPaymentBasket();
        $amountTotalGross   = (float) $sOrderVariables['sBasket']['AmountWithTaxNumeric'];
        $amountTotalNet     = (float) $sOrderVariables['sBasket']['sAmount'];
        $unzerPaymentBasket->setAmountTotalGross($amountTotalGross);
        $unzerPaymentBasket->setAmountTotalVat($amountTotalGross - $amountTotalNet);

        return $unzerPaymentBasket;
    }
}
