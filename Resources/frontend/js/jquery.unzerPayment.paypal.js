;(function ($, window) {
    'use strict';

    $.plugin('unzerPaymentPayPal', {
        defaults: {
            unzerPaymentCreatePaymentUrl: '',
            radioButtonSelector: 'input:radio[name="paypalSelection"]',
            selectedRadioButtonSelector: 'input:radio[name="paypalSelection"]:checked',
            radioButtonNewSelector: '#new',
            typeIdProviderSelector: '#typeIdProvider'
        },

        unzerPaymentPlugin: null,

        init: function () {
            this.unzerPaymentPlugin = $('*[data-unzer-payment-base="true"]').data('plugin_unzerPaymentBase');

            this.applyDataAttributes();
            this.registerEvents();

            if ($(this.opts.radioButtonSelector).length > 1) {
                $(this.opts.radioButtonNewSelector).prop('checked', true);
            }

            this.unzerPaymentPlugin.setSubmitButtonActive(true);
            $.publish('plugin/unzer/paypal/init', this);
        },

        registerEvents: function () {
            $.subscribe('plugin/unzer/onSubmitCheckoutForm/before', $.proxy(this.createResource, this));
            $.subscribe('plugin/unzer/onSubmitCheckoutForm/after', $.proxy(this.submitPayment, this));
        },

        createResource: function () {
            $.publish('plugin/unzer/paypal/createResource/before', this);

            $(this.unzerPaymentPlugin.opts.checkoutFormSelector).attr('action', this.opts.unzerPaymentCreatePaymentUrl);

            if (!$(this.opts.radioButtonNewSelector).is(':checked')) {
                $(this.opts.typeIdProviderSelector).attr('value', $(this.opts.selectedRadioButtonSelector).attr('id'));
            }

            $.publish('plugin/unzer/paypal/createResource/after', this);
        },

        submitPayment: function () {
            this.unzerPaymentPlugin.isAsyncPayment = false;
            $(this.unzerPaymentPlugin.opts.checkoutFormSelector).submit();
        },

        onError: function (error) {
            $.publish('plugin/unzer/paypal/createResourceError', this, error);

            this.unzerPaymentPlugin.redirectToErrorPage(this.unzerPaymentPlugin.getMessageFromError(error));
        }
    });

    window.StateManager.addPlugin('*[data-unzer-payment-paypal="true"]', 'unzerPaymentPayPal');
})(jQuery, window);
