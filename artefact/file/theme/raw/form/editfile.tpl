  <tr id="{$prefix}_edit_row"{if !$fileinfo} class="hidden editrow"{/if}>
  	<td></td>
    <td colspan="5" class="fileedittablewrap">
      <table class="fileedittable">
        <tbody>
          <tr>
            <th colspan="2" id="{$prefix}_edit_heading" class="fl">
            {if $fileinfo}{if $fileinfo->artefacttype == 'folder'}{str tag=editfolder section=artefact.file}{else}{str tag=editfile section=artefact.file}{/if}{/if}
            </th>
          </tr>
          <tr class="required">
            <th><label for="{$prefix}_edit_title">{str tag=name}</label> <span class="requiredmarker">*</span></th>
            <td><input type="text" class="text" name="{$prefix}_edit_title" id="{$prefix}_edit_title" value="{$fileinfo->title}" size="40" /></td>
          </tr>
          {if $fileinfo->artefacttype != 'profileicon'}
          <tr>
            <th><label for="{$prefix}_edit_description">{str tag=description}</label></th>
            <td><input type="text" class="text" name="{$prefix}_edit_description" id="{$prefix}_edit_description" value="{$fileinfo->description}" size="40" /></td>
          </tr>
          {/if}
          <tr class="tags">
            <th><label for="{$prefix}_edit_tags">{str tag=tags}</label></th>
            <td>
              <input name="{$prefix}_edit_tags" size="40" id="{$prefix}_edit_tags" value="{foreach from=$fileinfo->tags item=tag name=tags}{if !$.foreach.tags.first}, {/if}{$tag}{/foreach}" />
              <span>{contextualhelp plugintype='artefact' pluginname='file' section='tags'}</span>
            </td>
          </tr>
          <tr>
            <td></td>
            <td class="description">{str tag=tagsdescprofile}</td>
          </tr>
{if $groupinfo}
          <tr>
            <th><strong>{str tag=Permissions}</strong></th>
            <td>
              <table class="editpermissions">
                <tbody>
                  <tr>
                    <th>{str tag=Role section=group}</th>
  {foreach from=$groupinfo.perm item=permname}
                    <th>{$permname}</th>
  {/foreach}
                  </tr>
  {foreach from=$groupinfo.roles item=role key=r}
                  <tr>
                    <td>{$role->display}</td>
    {foreach from=$groupinfo.perm item=whocares key=permid}
      {if $fileinfo}
                    <td><label for="{$prefix}_permission_{$r}_{$permid}">{str tag=changerolepermissions section=group arg1=$permid arg2=$r}</label>
                        <input type="checkbox" class="permission" id="{$prefix}_permission_{$r}_{$permid}" name="{$prefix}_permission:{$r}:{$permid}"{if $fileinfo->permissions.$r.$permid} checked{/if}{if $r == 'admin'} disabled{/if} /></td>
      {else}
                    <td><label for="{$prefix}_permission_{$r}_{$permid}">{str tag=changerolepermissions section=group arg1=$permid arg2=$r}</label>
                        <input type="checkbox" class="permission" id="{$prefix}_permission_{$r}_{$permid}" name="{$prefix}_permission:{$r}:{$permid}" {if $r == 'admin'} checked disabled{/if}/></td>
      {/if}
    {/foreach}
                  </tr>
  {/foreach}
                </tbody>
              </table>
            </td>
          </tr>
{/if}
          {license_form_files($prefix, 'edit')}
          <tr>
            <th><label for="{$prefix}_edit_allowcomments">{str tag=allowcomments section=artefact.comment}</label></th>
            <td><input type="checkbox" name="{$prefix}_edit_allowcomments" id="{$prefix}_edit_allowcomments" {if $fileinfo->allowcomments}checked {/if}/></td>
          </tr>
          <tr>
          	<td></td>
            <td>
              <input type="submit" class="submit" name="{$prefix}_update[{$fileinfo->id}]" id="{$prefix}_edit_artefact" value="{str tag=savechanges section=artefact.file}" />
              <input type="submit" class="cancel" name="{$prefix}_canceledit" id="{$prefix}_edit_cancel" value="{str tag=cancel}" />
            </td>
          </tr>
          <tr><td colspan="2" id="{$prefix}_edit_messages"></td></tr>
        </tbody>
      </table>
    </td>
  </tr>
