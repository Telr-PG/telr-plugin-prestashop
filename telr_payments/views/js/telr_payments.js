document.addEventListener('DOMContentLoaded', function () {

    var paymentData = {};
    var applePayButtonId = 'telr_applepay_btn';
    var paymentOptionId;

    document.querySelectorAll('div [data-module-name="telr_payments_applepay"]')[0].closest('div').style.display = 'block';

    const initialiseApplePayButton = function () {
        const paymentConfirmation = document.querySelector('#payment-confirmation .ps-shown-by-js');

        if (paymentConfirmation) {
            const existingSubmitButton = paymentConfirmation.querySelector('button[type="submit"]');
            if (existingSubmitButton) {
                existingSubmitButton.id = 'place_order_btn';
            }
            const applePayButton = document.createElement('a');
            applePayButton.className = 'apple-pay-button ' + window.applepaydata.apple_pay_btn_class + ' hidden';
            applePayButton.id = applePayButtonId;
            paymentConfirmation.appendChild(applePayButton);
        }
    }

    const displayApplePayButton = function () {
        const paymentOptions = document.querySelectorAll("input[name='payment-option']");
        const placeOrderBtn = document.querySelector("#place_order_btn");
        const applePayBtn = document.querySelector('#'+applePayButtonId);

        if (paymentOptions) {
            if(paymentOptions.length > 1){
                const selectedPaymentOption = document.querySelector('input[name="payment-option"]:checked');
                if (selectedPaymentOption && selectedPaymentOption.dataset.moduleName === 'telr_payments_applepay') {
                    placeOrderBtn.classList.add('hidden');
                    applePayBtn.classList.remove('hidden');
                    paymentOptionId = selectedPaymentOption.id;
				}
			} else if (paymentOptions.length === 1 && paymentOptions[0].dataset.moduleName === 'telr_payments_applepay') {
                placeOrderBtn.classList.add('hidden');
                applePayBtn.classList.remove('hidden');
                paymentOptionId = paymentOptions[0].id;
			}
            paymentOptions.forEach(function (input) {
                input.addEventListener('click', function () {
                    if(this.dataset.moduleName == 'telr_payments_applepay'){
                        placeOrderBtn.classList.add('hidden');
                        applePayBtn.classList.remove('hidden');
                        paymentOptionId = this.id;
                    }else{
                        placeOrderBtn.classList.remove('hidden');
                        applePayBtn.classList.add('hidden');
                    }
                });
            });
        }
    }

    const sendPaymentToken = function (paymentToken) {
        return new Promise(function (resolve, reject) {
            paymentData = paymentToken;					
            resolve(true);
        }).catch(function (validationErr) {
            jQuery(".telr_applePay_error").text('Unable to process Apple Pay payment. Please reload the page and try again. Error Code: E003');
            setTimeout(function(){
                jQuery(".telr_applePay_error").text('');
            }, 5000);
                session.abort();
	    });
    }
    const getApplePayRequestPayload = function (){
        return {
            currencyCode: window.applepaydata.currency_code,
            countryCode: window.applepaydata.country_code,
            merchantCapabilities: window.applepaydata.merchant_capabilities,
            supportedNetworks: window.applepaydata.supported_networks,
            total: {
                label: window.location.host,
                amount: window.applepaydata.cart_total,
                type: 'final'
            }
        }
	}
    
    const performAppleUrlValidation = function (valURL, callback) {
        jQuery.ajax({
            type: 'POST',
            url: window.applepaydata.ajax_url + '&ajax=1&action=appleSessionValidation',
            data: { url: valURL, },
            success: function (outcome) {
                var data = JSON.parse(outcome);
                callback(data);
            }
        });
	}

    const sendPaymentToTelr = function (paymentData) {
        const submitForm = document.getElementById('payment-'+paymentOptionId+'-form');
        if (submitForm) {
            const inputField = document.createElement('input');
            inputField.type = 'hidden';
            inputField.name = 'applepaydata';
            inputField.value = JSON.stringify(paymentData);
            submitForm.appendChild(inputField);
        }
        document.querySelector("#place_order_btn").click();
	}

    const handleApplePayEvents = function (session) {
        session.onvalidatemerchant = function (event) {
            performAppleUrlValidation(event.validationURL, function (merchantSession) {
                session.completeMerchantValidation(merchantSession);
            });
        };

        session.onpaymentmethodselected = function (event) {	
            var newTotal = {
                type: 'final',
                label: window.location.host,
                amount: window.applepaydata.cart_total,
            };
            var newLineItems = [
                {
                    type: 'final',
                    label: 'Subtotal',
                    amount: window.applepaydata.cart_subtotal
                },
                {
                    type: 'final',
                    label: 'Shipping - ' + window.applepaydata.shipping_name,
                    amount: window.applepaydata.shipping_amt
                }
            ];
            session.completePaymentMethodSelection(newTotal, newLineItems);
        };

        session.onpaymentauthorized = function (event) {			
            var promise = sendPaymentToken(event.payment.token);
            promise.then(function (success) {
                var status;
                if (success) {
                    sendPaymentToTelr(paymentData);
                    session.completePayment();
                } else {
                    status = ApplePaySession.STATUS_FAILURE;
                    session.completePayment(status);
                }    
            }).catch(function (validationErr) {
                jQuery(".telr_applePay_error").text('Unable to process Apple Pay payment. Please reload the page and try again. Error Code: E002');
                setTimeout(function(){
                    jQuery(".telr_applePay_error").text('');
                }, 5000);
                session.abort();
            });
        }
        session.oncancel = function (event) {};
    }

    const applePayClickHandler = function () {
        var applePaySession = new ApplePaySession(3, getApplePayRequestPayload());
        handleApplePayEvents(applePaySession);
        applePaySession.begin();
	}
	
    if (window.ApplePaySession) {
        var canMakePayments = ApplePaySession.canMakePayments(window.applepaydata.apple_pay_merchant_id);
            if ( canMakePayments ) {
                setTimeout( function() {
                    document.querySelectorAll('div [data-module-name="telr_payments_applepay"]')[0].closest('div').style.display = 'block';
                    initialiseApplePayButton();
                    displayApplePayButton();
                    document.querySelector('#' + applePayButtonId).removeEventListener('click', applePayClickHandler);
                    document.querySelector('#' + applePayButtonId).addEventListener('click', applePayClickHandler);
                }, 500 );
            }
    }	
	
setTimeout(function () {
    initialiseApplePayButton();
    displayApplePayButton();
}, 500);
});