{include file="header.tpl"}
            {$settingsformtag|safe}
            <table id="profileicons" class="hidden tablerenderer fullwidth">
                <thead>
                    <tr>
                        <th class="profileiconcell">{str tag="image"}</th>
                        <th>{str tag="imagetitle" section=artefact.file}</th>
                        <th class="defaultcell">{str tag="Default" section=artefact.file}</th>
                        <th class="deletecell">{str tag="delete"}</th>
                    </tr>
                </thead>
                <tbody>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="4" class="right"><input id="settings_default" type="submit" class="submit" name="default" value="{str tag="setdefault" section=artefact.file}"> <input id="settings_delete" type="submit" class="cancel" name="delete" value="{str tag="deleteselectedicons" section=artefact.file}"></td>
                    </tr>
                </tfoot>
            </table>
            <input type="hidden" name="pieform_settings" value="">
            <input type="hidden" name="sesskey" value="{$USER->get('sesskey')}">
            </form>

            <h3>{str tag="uploadprofileicon" section="artefact.file"}</h3>
            <p>{str tag="profileiconsiconsizenotice" section="artefact.file" args=$imagemaxdimensions}</p>

            {$uploadform|safe}
{include file="footer.tpl"}
