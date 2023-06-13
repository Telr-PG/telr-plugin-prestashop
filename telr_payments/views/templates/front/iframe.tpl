{extends "$layout"}
{block name="content"}
  <section>
    <iframe src="{$src}" style="width: 100%; border: medium none; height: 600px;" sandbox="allow-forms allow-modals allow-popups-to-escape-sandbox allow-popups allow-scripts allow-top-navigation allow-same-origin"></iframe>
  </section>
{/block}
