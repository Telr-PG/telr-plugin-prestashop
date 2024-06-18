
<section>
  <p>{l s='Pay using Telr secure payments via ' mod='telr_payments'}
      {if !empty($supportedCards)}
          {foreach from=$supportedCards item=item}
              <img src="{$item}" alt="visa" style="height:25px"/>
          {/foreach}
      {/if}
  </p>
</section>
