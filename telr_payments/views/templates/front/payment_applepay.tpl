<script>
    window.applepaydata = {
        apple_pay_btn_class: '{$apple_pay_btn_class nofilter}',
        apple_pay_merchant_id: '{$apple_pay_merchant_id nofilter}',
        country_code: '{$country_code nofilter}',
        supported_networks: {$supported_networks nofilter},
        merchant_capabilities: {$merchant_capabilities nofilter},
        currency_code: '{$currency_code nofilter}',
        cart_total: {$cart_total},
        cart_subtotal: {$cart_subtotal},
        shipping_amt: {$shipping_amt},
        shipping_name: '{$shipping_name nofilter}',
        ajax_url:'{$ajax_url nofilter}'
    };
</script>

{if isset($telr_applepay_assets)}
    <link rel="stylesheet" href="{$telr_applepay_assets.css}" type="text/css">
    <script src="{$telr_applepay_assets.js}" type="text/javascript"></script>
{/if}

<input type="hidden" id="applepayprocessdata" value="">

