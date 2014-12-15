{if $enablecomments}
  <a id="add_feedback_link" class="feedback" href="">{str tag=placefeedback section=artefact.comment}</a>
{/if}
{if $LOGGEDIN}
  <a id="objection_link" class="objection" href="">{str tag=reportobjectionablematerial}</a>
{/if}
<a id="print_link" class="print" href="" onclick="window.print(); return false;">{str tag=print section=view}</a>
{if $LOGGEDIN}
  <a id="toggle_watchlist_link" class="watchlist" href="">
  {if $artefact}
    {if $viewbeingwatched}{str tag=removefromwatchlistartefact section=view arg1=$view->get('title')}{else}{str tag=addtowatchlistartefact section=view arg1=$view->get('title')}{/if}
  {else}
    {if $viewbeingwatched}{str tag=removefromwatchlist section=view}{else}{str tag=addtowatchlist section=view}{/if}
  {/if}
  </a>
  {contextualhelp plugintype='core' pluginname='view' section='viewmenu'}
{/if}

