{block name="frontend_checkout_confirm_heidelpay_frames_invoice_guaranteed"}
    <div class="heidelpay--invoice-wrapper heidelpayUI form"
         data-heidelpay-invoice-guaranteed="true"
         data-heidelpayCreatePaymentUrl="{url controller=HeidelpayInvoiceGuaranteed action=createPayment module=widgets}"
         data-heidelpayIsB2bWithoutVat="{$sUserData.billingaddress.company && !$sUserData.billingaddress.vatid}"
         data-heidelpayCustomerDataUrl="{url controller=HeidelpayCustomerData action=getCustomerData module=widgets}">

        {if !$sUserData.billingaddress.company || $sUserData.billingaddress.vatid}
            {block name="frontend_checkout_confirm_heidelpay_frames_invoice_guaranteed_birthday_label"}
                <label for="heidelpayBirthday" class="is--block">
                    {s name="label/birthday" namespace="frontend/heidelpay/frames/invoice"}{/s}
                </label>
            {/block}

            {block name="frontend_checkout_confirm_heidelpay_frames_invoice_guaranteed_birthday"}
                <input type="text"
                       id="heidelpayBirthday"
                       placeholder="{s name="placeholder/birthday" namespace="frontend/heidelpay/frames/invoice"}{/s}"
                       {if $sUserData.additional.user.birthday !== ''}value="{$sUserData.additional.user.birthday}"{/if}
                       data-datepicker="true"
                       data-allowInput="true"
                       required="required"/>
            {/block}
        {/if}

        {block name="frontend_checkout_confirm_heidelpay_frames_invoice_guaranteed_b2b_fields"}
            <div id="heidelpay--invoice-guaranteed-container" class="heidelpayUI form">
            </div>
        {/block}
    </div>
{/block}
