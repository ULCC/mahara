/**
 * Automatically populates the WYSIWYG box on the site pages screen
 * with the content of the appropriate page
 * @source: http://gitorious.org/mahara/mahara
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL version 3 or later
 * @copyright  For copyright information on Mahara, please see the README file distributed with this software.
 *
 */

var oldPageContent = '';
var oldPageName = 'home';
var changedCheckbox = false;
var checkOldContent = false;

function updateWYSIWYGText() {
    if (((checkOldContent && oldPageContent != tinyMCE.activeEditor.getContent()) || changedCheckbox) && !confirm(get_string('discardpageedits', 'admin'))) {
        $('editsitepage_pagename').value = oldPageName;
        return;
    }
    if (!tinyMCE.Env.ie) {
        // Disable changed content check for IE (see below)
        checkOldContent = true;
    }
    sendjsonrequest(
        config['wwwroot'] + 'admin/site/editchangecontent.json.php',
        {'contentname' : $('editsitepage_pagename').value,
         'institution' : $('editsitepage_pageinstitution').value
        },
        'POST',
        function(data) {
            if (!data.error) {
                tinyMCE.activeEditor.setContent(data.content);
                oldPageContent = tinyMCE.activeEditor.getContent();
                oldPageName = $('editsitepage_pagename').value;
                if ($('editsitepage_pageusedefault')) {
                    $('editsitepage_pageusedefault').checked = (data.pageusedefault) ? true : false;
                    updateSiteDefault(false);
                }
            }
        }
    );
}

function updateSiteDefault(changed) {
    changedCheckbox = (changed) ? true : false;
    var editor = getFirstElementByTagAndClassName('td', null, $('editsitepage_pagetext_container'));
    editor.style.padding = '0px';
    if ($('editsitepage_pageusedefault').checked == true) {
        tinyMCE.activeEditor.getBody().setAttribute('contenteditable', false);
        $('changecheckboxdiv').style.display = 'block';
        $('changecheckboxdiv').style.zIndex = '1';
        $('changecheckboxdiv').style.position = 'absolute';
        $('changecheckboxdiv').style.width = editor.offsetWidth + 'px';
        $('changecheckboxdiv').style.height = editor.offsetHeight + 'px';
        $('changecheckboxdiv').style.top = elementPosition(editor).y + 'px';
        $('changecheckboxdiv').style.left = elementPosition(editor).x + 'px';
    }
    else {
        tinyMCE.activeEditor.getBody().setAttribute('contenteditable', true);
        $('changecheckboxdiv').style.display = 'none';
        $('changecheckboxdiv').style.width = '1px';
        $('changecheckboxdiv').style.height = '1px';
    }
}

function connectElements() {
    connect('editsitepage_pagename', 'onchange', updateWYSIWYGText);
    connect('editsitepage_pageinstitution', 'onchange', updateWYSIWYGText);
    if ($('editsitepage_pageusedefault')) {
        connect('editsitepage_pageusedefault', 'onchange', updateSiteDefault);
    }
    // create hidden div to place over tinymce to 'show' when it is disabled from editing
    appendChildNodes($('editsitepage'), DIV({'id':'changecheckboxdiv','style':'display:none;background-color: rgba(200,200,200,0.5)'}, ''));
}

function contentSaved(form, data) {
    connectElements();
    changedCheckbox = false;
    if (!tinyMCE.Env.ie) {
        // Disabling changed content check for IE; Need to work out
        // why the getBody() call in getContent fails to return the
        // body element.
        oldPageContent = tinyMCE.activeEditor.getContent();
    }
    formSuccess(form, data);
}

addLoadEvent(function() {
    connectElements();
    // need to wait until tinyMCE editor is loaded before updating editor's text
    var checkExists = setInterval(function() {
        if (tinyMCE.activeEditor != "null") {
            updateWYSIWYGText();
            clearInterval(checkExists);
        }
    }, 500);
});
