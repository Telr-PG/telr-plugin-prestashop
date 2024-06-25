
<input type="hidden" id="storeidem" value="{$storeid}">
<input type="hidden" id="currencyem" value="{$currency_code}">
<input type="hidden" id="testmodeem" value="{$test_mode}">
<input type="hidden" id="saved_cards" value='{$saved_cards}'>
<input type="hidden" id="iframemod" value='{$iframemod}'>
<input type="hidden" id="telr_payment_token" value="">

{if $iframemod == 10}
    <iframe id="telr_iframe" src="{$seamless_url}" style="width: 100%; height: {$frame_height}px; border: 0;margin-top: 20px;" sandbox="allow-forms allow-modals allow-popups-to-escape-sandbox allow-popups allow-scripts allow-top-navigation allow-same-origin" name="0E2E6E6EC4A26EACE02A8A232D9530E5-0"></iframe>
	<div id="carError"></div>
{else}
    <div id="iframemodbox">
	    <p>{l s='Pay using Telr secure payments via ' mod='telr_payments'}
		  {if !empty($supportedCards)}
			  {foreach from=$supportedCards item=item}
				  <img src="{$item}" alt="visa" style="height:25px"/>
			  {/foreach}
		  {/if}
	    </p>
	</div>
{/if}



<script>
	document.addEventListener('DOMContentLoaded', function() {
	
		var store_id = document.getElementById("storeidem").value;
		var currency = document.getElementById("currencyem").value;
		var test_mode = document.getElementById("testmodeem").value;
		var saved_cards = JSON.parse(document.getElementById("saved_cards").value);
		var iframemod = document.getElementById("iframemod").value;
		var element;
		
		if(iframemod == 10){
		
			window.telrInit = false;
			
			var telrMessage = {
				"message_id": "init_telr_config",
				"store_id": store_id,
				"currency": currency,
				"test_mode": test_mode,
				"saved_cards": saved_cards
			}
			
			if (typeof window.addEventListener != 'undefined') {
				window.addEventListener('message', function(e) {
					var message = e.data;
					 if(message != ""){
						var isJson = true;
						try {
							JSON.parse(str);
						} catch (e) {
							isJson = false;
						}
						if(isJson || (typeof message === 'object' && message !== null)){
							var telrMessage = (typeof message === 'object') ? message : JSON.parse(message);
							if(telrMessage.message_id != undefined){
								switch(telrMessage.message_id){
									case "return_telr_token": 
										var payment_token = telrMessage.payment_token;
										console.log("Telr Token Received: " + payment_token);
										$("#telr_payment_token").val(payment_token);
									break;
								}
							}
						}
					}
					
				}, false);
				
			} else if (typeof window.attachEvent != 'undefined') { // this part is for IE8
				window.attachEvent('onmessage', function(e) {
					var message = e.data;
					 if(message != ""){
						 try {
							JSON.parse(str);
						} catch (e) {
							isJson = false;
						}
						if(isJson || (typeof message === 'object' && message !== null)){
							var telrMessage = (typeof message === 'object') ? message : JSON.parse(message);
							if(telrMessage.message_id != undefined){
								switch(telrMessage.message_id){
									case "return_telr_token": 
										var payment_token = telrMessage.payment_token;
										console.log("Telr Token Received: " + payment_token);
										$("#telr_payment_token").val(payment_token);
									break;
								}
							}
						}
					}
					
				});
			}

			
			document.getElementById('telr_iframe').onload = function() {		
				var initMessage = JSON.stringify(telrMessage);
				setTimeout(function(){
					if(!window.telrInit){
						document.getElementById('telr_iframe').contentWindow.postMessage(initMessage,"*");
						window.telrInit = true;
					}
				}, 1500);
			};
		}	
	});
	
	
	

	
	document.addEventListener('DOMContentLoaded', function() {
	
		var radioButtonCount = document.querySelectorAll('input[type="radio"][name="payment-option"]').length;
		var radioButtons = document.querySelectorAll('input[type="radio"][name="payment-option"]');
		var tac = document.getElementById('conditions_to_approve[terms-and-conditions]');
		
		if(radioButtonCount == 1){
			var selectedRadioButton = document.querySelector('input[type="radio"][name="payment-option"]:checked');
			var moduleName = selectedRadioButton.getAttribute("data-module-name");
			//console.log(moduleName);
			var selectedLabel = document.querySelector('label[for="' + selectedRadioButton.id + '"]');
			selectPlaceOrderButton(moduleName);
		}else{
			radioButtons.forEach(function(radioButton) {
				radioButton.addEventListener('click', function() {	
					var moduleName = this.getAttribute("data-module-name");
					console.log(moduleName);
					var labelElement = document.querySelector('label[for="' + this.id + '"]');
					selectPlaceOrderButton(moduleName);	
				});
			});
		}
		
		
		if(tac){
			tac.addEventListener('click', function() {
				if (this.checked) {
					element.classList.remove('disabled');
					element.removeAttribute('style');
				} else {
					element.classList.add('disabled');
					element.style.property = 'color: #ddd';
				}		
			});
		}else{
			element.classList.remove('disabled');
			element.removeAttribute('style');
		}
	
	});
	
	document.addEventListener('click', function(event) {
	
		if (event.target.matches('#placeorder')) {
			
			// Define the URL and the data you want to send
			const url = document.getElementById('payment').action;
			const telr_payment_token = document.getElementById('telr_payment_token').value;
			var telr_iframe = document.getElementById('telr_iframe');
			var orderbtn = document.getElementById('placeorder');
			var iframeflowmod = document.getElementById("iframemod").value;
			
			
			if(telr_payment_token == '' && iframeflowmod == 10){
				var errorLabel = document.createElement('div');
				errorLabel.classList.add('alert', 'alert-danger');
				errorLabel.textContent = 'Please fill the complete card details';
				var parentElement = document.getElementById('carError');
				parentElement.appendChild(errorLabel);
				setTimeout(function() {
					errorLabel.remove();
				}, 5000);
			}else{
			
				orderbtn.classList.add('disabled');
				orderbtn.style.property = 'color: #ddd';
						
				// Get form data
				const data = { payment_token: telr_payment_token };
				
				// Make a POST request using fetch
				fetch(url, {
				  method: 'POST',
				  headers: {
					'Content-Type': 'application/json',
				  },
				  body: JSON.stringify(data),
				})
				  .then(response => {
					if (!response.ok) {
					  throw new Error('Network response was not ok');
					}
					return response.json();
				  })
				  .then(data => {
					if(iframeflowmod == 10){
						telr_iframe.src = data;
					}else{
						var iframeTagBox = document.createElement('iframe');
						iframeTagBox.src = data;
						iframeTagBox.style = "width: 100%; border: medium none; height: 400px;";
						iframeTagBox.sandbox = "allow-forms allow-modals allow-popups-to-escape-sandbox allow-popups allow-scripts allow-top-navigation allow-same-origin";
						
						var pElement = document.querySelector('#iframemodbox p');
						var parentElementt = pElement.parentNode;
						parentElementt.replaceChild(iframeTagBox, pElement);
					}					
				  })
				  .catch(error => {
					console.error('Error:', error);
				  });	
			}  
		}
	});
		
	function selectPlaceOrderButton(labelElement){
	
		var paymentConfirmation = document.getElementById('payment-confirmation');
		var button = paymentConfirmation.querySelector('button');
		var anchorTag = paymentConfirmation.querySelector('a');
		
		var newElement = document.createElement('a');
		newElement.className = 'btn btn-primary center-block text-white disabled';
		newElement.style = 'color: #ddd';
		newElement.textContent = 'Place order';
		newElement.id = 'placeorder';
		
		var oldElement = document.createElement('button');
		oldElement.className = 'btn btn-primary center-block disabled';
		oldElement.textContent = 'Place order';
		oldElement.disabled = 'disabled';
		oldElement.type = 'submit';
		
		if (labelElement) {
			var labelName = labelElement.trim();
			if(labelName == 'telr_payments'){
				if(button){
					button.parentNode.replaceChild(newElement, button);
					element = document.getElementById('placeorder');
				}
			}else{
				if(anchorTag){
					anchorTag.parentNode.replaceChild(oldElement, anchorTag);
				}
			}
		}
	}
			
</script>
<form action="{$action}" id="payment"></form>