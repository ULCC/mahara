{*
    Links must contain the following parameters:
    - query
    - mainfacetterm
    - secfacetterm
    - owner
    - portofolio
    - sort
    - license
*}
<div id="universalsearch-filter-owner">
    <input type="hidden" id="search-filter-owner-url" value="{$WWWROOT}search/elasticsearch/index.php?query={$query}&mainfacetterm={$selected}&secfacetterm={$contentfilterselected}&license={$license}&sort={$sort}{if $tagsonly}&tagsonly=true{/if}&limit={$limit}" />
    <label for="search-filter-owner">
        {if $selected eq 'Text' || $selected eq 'Media' || $selected eq 'Portfolio'}
            {str tag=owner section=search.elasticsearch}:
        {else}
            {str tag=admin section=search.elasticsearch}:
        {/if}
    </label>
    <select id="search-filter-owner">
        {foreach from=$ownerfilter item=item}
            <option value="{$item.term}" {if ($owner == $item.term)}selected{/if}>{$item.display}</option>
        {/foreach}
    </select>
</div>