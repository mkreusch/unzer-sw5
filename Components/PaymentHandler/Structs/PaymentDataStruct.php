<?php

declare(strict_types=1);

namespace UnzerPayment\Components\PaymentHandler\Structs;

use UnzerSDK\Resources\Basket;
use UnzerSDK\Resources\Customer;
use UnzerSDK\Resources\Metadata;

class PaymentDataStruct
{
    /** @var float */
    private $amount;

    /** @var string */
    private $currency;

    /** @var string */
    private $returnUrl;

    /** @var null|Customer */
    private $customer;

    /** @var null|string */
    private $orderId;

    /** @var null|Metadata */
    private $metadata;

    /** @var null|Basket */
    private $basket;

    /** @var null|bool */
    private $card3ds;

    /** @var null|string */
    private $invoiceId;

    /** @var null|string */
    private $paymentReference;

    /** @var bool */
    private $isRecurring = false;

    /** @var array */
    private $recurringData;

    /** @var null|string */
    private $recurrenceType;

    public function __construct(float $amount, string $currency, string $returnUrl)
    {
        $this->amount    = $amount;
        $this->currency  = $currency;
        $this->returnUrl = $returnUrl;
    }

    public function getAmount(): float
    {
        return $this->amount;
    }

    public function setAmount(float $amount): self
    {
        $this->amount = $amount;

        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): self
    {
        $this->currency = $currency;

        return $this;
    }

    public function getReturnUrl(): string
    {
        return $this->returnUrl;
    }

    public function setReturnUrl(string $returnUrl): self
    {
        $this->returnUrl = $returnUrl;

        return $this;
    }

    public function getCustomer(): ?Customer
    {
        return $this->customer;
    }

    public function setCustomer(?Customer $customer): self
    {
        $this->customer = $customer;

        return $this;
    }

    public function getOrderId(): ?string
    {
        return $this->orderId;
    }

    public function setOrderId(?string $orderId): self
    {
        $this->orderId = $orderId;

        return $this;
    }

    public function getMetadata(): ?Metadata
    {
        return $this->metadata;
    }

    public function setMetadata(?Metadata $metadata): self
    {
        $this->metadata = $metadata;

        return $this;
    }

    public function getBasket(): ?Basket
    {
        return $this->basket;
    }

    public function setBasket(?Basket $basket): self
    {
        $this->basket = $basket;

        return $this;
    }

    public function getCard3ds(): ?bool
    {
        return $this->card3ds;
    }

    public function setCard3ds(?bool $card3ds): self
    {
        $this->card3ds = $card3ds;

        return $this;
    }

    public function getInvoiceId(): ?string
    {
        return $this->invoiceId;
    }

    public function setInvoiceId(?string $invoiceId): self
    {
        $this->invoiceId = $invoiceId;

        return $this;
    }

    public function getPaymentReference(): ?string
    {
        return $this->paymentReference;
    }

    public function setPaymentReference(?string $paymentReference): self
    {
        $this->paymentReference = $paymentReference;

        return $this;
    }

    public function isRecurring(): bool
    {
        return $this->isRecurring;
    }

    public function setIsRecurring(bool $isRecurring): void
    {
        $this->isRecurring = $isRecurring;
    }

    public function getRecurringData(): array
    {
        return $this->recurringData;
    }

    public function setRecurringData(array $recurringData): void
    {
        $this->recurringData = $recurringData;
    }

    public function getRecurrenceType(): ?string
    {
        return $this->recurrenceType;
    }

    public function setRecurrenceType(?string $recurrenceType): void
    {
        $this->recurrenceType = $recurrenceType;
    }

    public function fromArray(array $data): void
    {
        foreach ($data as $key => $value) {
            $setterMethod = 'set' . ucfirst($key);

            if (method_exists($this, $setterMethod)) {
                $this->$setterMethod($value);
            }
        }
    }
}
