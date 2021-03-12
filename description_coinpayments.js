
jQuery(document).ready(function ($) {

    var label = document.getElementById('edd-gateway-option-coinpayments');
    var element = document.createElement("div");
    element.id = 'description';
    element.innerHTML = '<br>Pay with Bitcoin, Litecoin, or other cryptocurrencies via <a href="https://alpha.coinpayments.net/" target="_blank" style="text-decoration: underline; font-weight: bold;" title="CoinPayments.net">CoinPayments.net</a></br><br></br>';
    if (!document.getElementById("description"))
        label.appendChild(element);
});