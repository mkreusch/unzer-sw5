{extends file="parent:frontend/checkout/cart_footer.tpl"}

{block name='frontend_checkout_cart_footer_field_labels_total'}
    {if $sPayment.name === 'unzerPaymentInstallmentSecured'}
        {block name='frontend_checkout_cart_footer_unzer_payment_interest'}
            <li id="unzer-payment-interest" class="list--entry block-group entry--interest">
                {block name='frontend_checkout_cart_footer_unzer_payment_interest_label'}
                    <div class="entry--label block">
                        {s name="label/interest" namespace="frontend/unzer_payment/checkout/cart_footer"}{/s}
                    </div>
                {/block}
                {block name='frontend_checkout_cart_footer_unzer_payment_interest_value'}
                    <div class="entry--value block">
                        {if $unzerPayment.interest}
                            {$unzerPayment.interest|currency}{s name="Star" namespace="frontend/listing/box_article"}{/s}
                        {else}
                            {"0.00"|currency}{s name="Star" namespace="frontend/listing/box_article"}{/s}
                        {/if}
                    </div>
                {/block}
            </li>
        {/block}

        <li class="list--entry block-group entry--total {if $sPayment.name === 'unzerPaymentInstallmentSecured'}default-weight{/if}">
            {block name='frontend_checkout_cart_footer_field_labels_total_label'}
                <div class="entry--label block">
                    {s name="CartFooterLabelTotal"}{/s}
                </div>
            {/block}
            {block name='frontend_checkout_cart_footer_field_labels_total_value'}
                <div class="entry--value block is--no-star">
                    {if $sAmountWithTax && $sUserData.additional.charge_vat}{$sAmountWithTax|currency}{else}{$sAmount|currency}{/if}
                </div>
            {/block}
        </li>

        {block name='frontend_checkout_cart_footer_unzer_payment_total_interest'}
            <li id="unzer-payment-total-interest" class="list--entry block-group entry--total entry--total-with-interest">
                {block name='frontend_checkout_cart_footer_unzer_payment_total_interest_label'}
                    <div class="entry--label block">
                        {s name="label/totalInterest" namespace="frontend/unzer_payment/checkout/cart_footer"}{/s}
                    </div>
                {/block}
                {block name='frontend_checkout_cart_footer_unzer_payment_total_interest_value'}
                    <div class="entry--value block is--no-star">
                        {if $unzerPayment.totalWithInterest}
                            {$unzerPayment.totalWithInterest|currency}
                        {else}
                            {if $sAmountWithTax && $sUserData.additional.charge_vat}{$sAmountWithTax|currency}{else}{$sAmount|currency}{/if}
                        {/if}
                    </div>
                {/block}
            </li>
        {/block}
    {else}
        {$smarty.block.parent}
    {/if}
{/block}
