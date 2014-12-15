/**
 * General javascript routines for Mahara
 * @source: http://gitorious.org/mahara/mahara
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL version 3 or later
 * @copyright  For copyright information on Mahara, please see the README file distributed with this software.
 *
 */

// Expects strings array
function get_string(s) {
    var args = flattenArguments(arguments).slice(1);
    if (typeof(strings) == 'undefined' || typeof(strings[s]) == 'undefined') {
        return '[[[' + s + ((args.length > 0) ? ('(' + args.join(',') + ')') : '') + ']]]';
    }
    var str = strings[s];
    if (typeof(str) == 'object') {
        var index = 0;
        if (args.length > 0 && typeof(plural) == 'function') {
            index = plural(parseInt(args[0], 10));
            if (typeof(index) == 'boolean') {
                index = index ? 1 : 0;
            }
        }
        if (typeof(str[index]) != 'string') {
            return '[[[' + s + ((args.length > 0) ? ('(' + args.join(',') + ')') : '') + ']]]';
        }
        str = str[index];
    }
    var i = 0;
    return str.replace(/%((%)|s)/g, function (m) { return m[2] || args[i++]; });
}

// Expects an image/css path to fetch url for (requires config.theme[] to be
// set)
function get_themeurl(s) {
    // log('get_themeurl(' + s + ')');
    if (!config || !config.theme || !config.theme[s]) {
        logError('Location of ' + s + ' is unknown, ensure config.theme is set correctly');
    }

    return config.theme[s];
}

var save_orig_data = true;
var orig_caller;
var orig_arguments;
var real_sesskey = '';

function globalErrorHandler(data) {
    if (data.returnCode == 1) {
        // Logged out!
        show_login_form('ajaxlogin');
    }
    else {
        displayMessage(data.message, 'error');
    }
}

function show_login_form(submit) {
    if($('ajax-login-form') == null) {
        var loginForm = DIV({id: 'ajax-login-form'});
        loginForm.innerHTML = '<h2>' + get_string('login') + '</h2><a href="/">&laquo; ' + get_string('home') + '<\/a><div id="loginmessage">' + get_string('sessiontimedout') + '</div><form class="pieform" name="login" method="post" action="" id="login" onsubmit="' + submit + '(this, 42); return false;"><table cellspacing="0" border="0" class="maharatable"><tbody><tr id="login_login_username_header" class="required text"><th><label for="login_login_username">' + get_string('username') + ':<\/label><\/th><\/tr><tr id="login_login_username_container"><td><input type="text" class="required text autofocus" id="login_login_username" name="login_username" value=""><\/td><\/tr><tr><td class="description"> <\/td><\/tr><tr id="login_login_password_header" class="required password"><th><label for="login_login_password">' + get_string('password') + ':<\/label><\/th><\/tr><tr id="login_login_password_container"><td><input type="password" class="required password" id="login_login_password" name="login_password" value=""><\/td><\/tr><tr><td class="description"> <\/td><\/tr><tr id="login_submit_container"><td><input type="submit" class="submit" id="login_submit" name="submit" value="' + get_string('login') + '"><\/td><\/tr><\/tbody><\/table><div id="homepage"><\/div><input type="hidden" name="sesskey" value=""><input type="hidden" name="pieform_login" value=""><\/form><script type="text\/javascript">var login_btn = null;addLoadEvent(function() {    connect($(\'login_submit\'), \'onclick\', function() { login_btn = \'login_submit\'; });});connect(\'login\', \'onsubmit\', function() { formStartProcessing(\'login\', login_btn); });<\/script>';
        appendChildNodes(document.body, DIV({id: 'overlay'}));
        appendChildNodes(document.body, loginForm);
        $('login_login_username').focus();
    }
    else {
        $('loginmessage').innerHTML = get_string('loginfailed');
        $('login_login_username').focus();
    }
}

function ajaxlogin(form, crap) {
    save_orig_data = false;
    sendjsonrequest(
        config.wwwroot + 'minilogin.php',
        {'login_username': form.elements['login_username'].value, 'login_password': form.elements['login_password'].value, 'pieform_login': ''},
        'POST',
        function(data) {
            removeElement('ajax-login-form');
            removeElement('overlay');
            config.sesskey = data.message;
            sendjsonrequest.apply(orig_caller, orig_arguments);
        },
        function() {},
        true
    );
    save_orig_data = true;
}

// Form related functions
var oldValue = null;
function formStartProcessing(form, btn) {
    processingStart();
    var button = $(btn);
    if (button) {
        oldValue = button.value;
        button.value = get_string('processing') + ' ...';

        // we add a hidden input field so the "disabled" button still gets to
        // pass its value through
        var node = INPUT({
            'type': 'hidden',
            'value': button.value,
            'name': button.name
        });
        insertSiblingNodesAfter(button, node);

        button.disabled = "disabled";
        button.blur();
    }
}
function formStopProcessing(form, btn) {
    processingStop();
}
function formError(form, data) {
    var errMsg = DIV({'id': 'messages'}, makeMessage(data.message, 'error'));
    swapDOM('messages', errMsg);
    scrollTo(0, 0);
}
function formSuccess(form, data) {
    var yayMsg = DIV({'id': 'messages'}, makeMessage(data.message, 'ok'));
    swapDOM('messages', yayMsg);
    scrollTo(0, 0);
}

function formGlobalError(form, data) {
    globalErrorHandler(data);
}
// End form related functions

// Message related functions
function makeMessage(message, type) {
    return DIV({'class': type}, message);
}

/* Appends a status message to the end of elemid */
function displayMessage(message, type, hideprevmsg) {
    // ensure we have type 'ok', 'error', or 'info' (the default)
    if (!type || (type != 'ok' && type != 'error')) {
        type = 'info';
    }

    var oldmessage = getFirstElementByTagAndClassName('div', null, 'messages');
    var message = makeMessage(message, type);
    appendChildNodes('messages', message);

    if (typeof hideprevmsg === 'undefined' || hideprevmsg == true) {
        if (oldmessage) {
            fade(oldmessage, {afterFinish: partial(removeElement, oldmessage)});
        }
    }
}

/* Display a nice little loading notification */
function processingStart(msg) {
    if (!msg) {
        msg = get_string('loading');
    }

    replaceChildNodes(
        $('loading-box'),
        DIV(msg)
    );
    showElement('loading-box');
}

/* Hide the loading notification */
function processingStop() {
    hideElement('loading-box');
}
// End message related functions

// Function to post a data object to a json script.
function sendjsonrequest(script, data, rtype, successcallback, errorcallback, quiet, anon) {
    //log('sendjsonrequest(script=', script, ', data=', data, ', rtype=', rtype, ', success=', successcallback, ', error=', errorcallback, ', quiet=', quiet, ')');
    donothing = function () { return; };
    if (typeof(successcallback) != 'function') {
        successcallback = donothing;
    }
    if (typeof(errorcallback) != 'function') {
        errorcallback = donothing;
    }
    processingStart();
    if (!anon) {
        data.sesskey = config.sesskey;
    }

    rtype = rtype.toLowerCase();

    var xhrOptions = { 'method': rtype };

    switch (rtype) {
        case 'post':
            xhrOptions.headers = { 'Content-type': 'application/x-www-form-urlencoded' };
            xhrOptions.sendContent = MochiKit.Base.queryString(data);
            break;
        default:
            xhrOptions.queryString = data;
            break;
    }

    if (save_orig_data) {
        orig_caller = this;
        orig_arguments = arguments;
    }

    document.documentElement.style.cursor = 'wait';

    if (typeof(fakewwwroot) == 'string') {
        if (script.substring(0, 4) == 'http') {
            script = fakewwwroot + script.substring(config.wwwroot.length);
        }
        else {
            script = fakewwwroot + script;
        }
    }

    var d = doXHR(script, xhrOptions);

    d.addCallbacks(function (result) {
        document.documentElement.style.cursor = '';
        var data = evalJSONRequest(result);
        var errtype = false;
        if (!data.error) { 
            errtype = 'ok';
        }
        else if (data.error == 'local') {
            errtype = 'error';
        }
        else {
            logWarning('invoking globalErrorHandler(', data, this, arguments, ')');
            // Trying something ninja. The call failed, but in the event that the global error
            // handler can recover, maybe it can be called
            globalErrorHandler(data);
        }
        if (errtype) {
            if (typeof(data.message) == 'string') {
                if (!quiet) {
                    displayMessage(data.message, errtype);
                }
                try { successcallback(data); } catch (e) { logError('sendjsonrequest() callback failed: ', e, data); }
            }
            else if (data.message && typeof(data.message) == 'object') {
                if (data.message.message && typeof(data.message.message == 'string') && !quiet) {
                    displayMessage(data.message.message, errtype);
                }
                try { successcallback(data.message); } catch (e) { logError('sendjsonrequest() callback failed: ', e, data); }
            }
            else {
                try { successcallback(data); } catch (e) { logError('sendjsonrequest() callback failed: ', e, data); }
            }
            processingStop();
        }
        else {
            processingStop();
        }
    },
    function (e) {
        document.documentElement.style.cursor = '';
        if (e instanceof MochiKit.Async.XMLHttpRequestError) {
            log(e);
        }
        else {
            displayMessage(get_string('unknownerror'), 'error');
        }
        errorcallback();
        processingStop();
    });
}

// Rename a file by appending numbers
function newfilename(oldname, fileexistsfunc) {
    var dotpos = oldname.indexOf('.');
    if (dotpos == -1) {
        var begin = oldname;
        var end = '';
    }
    else {
        var begin = oldname.substring(0, dotpos);
        var end = oldname.substring(dotpos, oldname.length);
    }
    var i = 1;
    var newname = begin + i + end;
    while (fileexistsfunc(newname)) {
        i++;
        newname = begin + i + end;
    }
    return newname;
}

// Return the filename part of a full path
function basename(path) {
    if (path.indexOf('/') > -1) { 
        var separator = '/';
    }
    else {
        var separator = '\\';
    }
    return path.substring(path.lastIndexOf(separator)+1, path.length);
}


// Autofocus the first element with a class of 'autofocus' on page load (@todo: move this to pieforms.js)
// Also, connect input elements with the 'emptyonfocus' class to work properly
addLoadEvent(function() {
    var element = getFirstElementByTagAndClassName(null, 'autofocus', document.body);

    if ( element && typeof(element.focus) == 'function' ) {
        element.focus();
    }

    forEach(getElementsByTagAndClassName('input', 'emptyonfocus'), function(elem) {
        elem.emptyonfocusSignal = connect(elem, 'onfocus', function(e) { elem.value = ''; e.stop(); if (elem.emptyonfocusSignal) { disconnect(elem.emptyonfocusSignal); } if (elem.emptyonfocusSignalForm) { disconnect(elem.emptyonfocusSignalForm); }  });
        if (elem.form) {
            elem.emptyonfocusSignalForm = connect(elem.form, 'onsubmit', function(e) { elem.value = ''; if (elem.emptyonfocusSignal) { disconnect(elem.emptyonfocusSignal); } if (elem.emptyonfocusSignalForm) { disconnect(elem.emptyonfocusSignalForm); } });
        }
    });
});

// Contextual Help
contextualHelpCache       = new Object();
contextualHelpSelected    = null;
contextualHelpContainer   = null;
contextualHelpDeferrable  = null;
contextualHelpOpened      = false;
contextualHelpLink        = null;
badIE = false;

function contextualHelpIcon(formName, helpName, pluginType, pluginName, page, section) {
    var link = A(
        {'href': null},
        IMG({'alt': get_string('Help'), 'src': get_themeurl('images/help.png')})
    );
    connect(link, 'onclick', function (e) {
        e.stop();
        contextualHelp(formName, helpName, pluginType, pluginName, page, section, link);
    });

    return SPAN({'class':'help'}, link);
}

function contextualHelp(formName, helpName, pluginType, pluginName, page, section, ref) {
    var key;
    var target = $(formName + '_' + helpName + '_container');
    var url = config.wwwroot + 'json/help.php';
    var url_params = {
        'plugintype': pluginType,
        'pluginname': pluginName
    };

    contextualHelpLink = ref;

    // deduce the key
    if (page) {
        key = pluginType + '/' + pluginName + '/' + page;
        url_params.page = page;
    }
    else if (section) {
        key = pluginType + '/' + pluginName + '/' + section;
        url_params.section = section;
    } 
    else {
        key = pluginType + '/' + pluginName + '/' + formName + '/' + helpName;
        url_params.form = formName;
        url_params.element = helpName;
    }

    // close existing contextual help
    if (contextualHelpSelected) {
        removeElement(contextualHelpContainer);

        contextualHelpContainer = null;
        if (key == contextualHelpSelected) {
            // we're closing an already open one by clicking on the ? again
            contextualHelpSelected = null;
            contextualHelpOpened = false;
            return;
        } else {
            // we're closing a DIFFERENT one that's already open (we want to
            // continue and open the new one)
            contextualHelpSelected = null;
            contextualHelpOpened = false;
        }
    }

    // create and display the container
    contextualHelpContainer = DIV({
            'style': 'position: absolute;',
            'class': 'contextualHelp hidden',
            'role' : 'dialog'
        },
        IMG({'src': config.theme['images/loading.gif']})
    );
    var parent = ref.parentNode;
    var inserted = false;
    var illegalParents = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'legend'];
    while (parent != null) {
        if (illegalParents.indexOf(parent.nodeName.toLowerCase()) >= 0) {
            insertSiblingNodesAfter(parent, contextualHelpContainer);
            inserted = true;
            break;
        }
        parent = parent.parentNode;
    }
    if (!inserted) {
        insertSiblingNodesAfter(ref.parentNode, contextualHelpContainer);
    }

    var position = contextualHelpPosition(ref, contextualHelpContainer);

    // Once it has been positioned, make it visible
    setElementPosition(contextualHelpContainer, position);
    removeElementClass(contextualHelpContainer, 'hidden');

    contextualHelpSelected = key;

    // load the content
    if (contextualHelpCache[key]) {
        buildContextualHelpBox(contextualHelpCache[key]);
        callLater(0, function() { contextualHelpOpened = true; });
        ensureHelpIsOnScreen(contextualHelpContainer, position);
    }
    else {
        if (contextualHelpDeferrable && contextualHelpDeferrable.cancel) {
            contextualHelpDeferrable.cancel();
        }
        badIE = true;
        sendjsonrequest(url, url_params, 'GET', function (data) {
            if (data.error) {
                contextualHelpCache[key] = data.message;
                replaceChildNodes(contextualHelpContainer, data.message);
            }
            else {
                contextualHelpCache[key] = data.content;
                buildContextualHelpBox(contextualHelpCache[key]);
            }
            contextualHelpOpened = true;
            ensureHelpIsOnScreen(contextualHelpContainer, position);
            processingStop();
        },
        function (error) {
            contextualHelpCache[key] = get_string('couldnotgethelp');
            buildContextualHelpBox(contextualHelpCache[key]);
            processingStop();
            contextualHelpOpened = true;
        },
        true, true);
    }
}

/*
 * Builds the contents of the box with the currently open contextual help in
 * it, including the close button and an overlay div to prevent clicking on the
 * help closing the box
 */
function buildContextualHelpBox(content) {
    var result = '<div class="fr">';
    result += '<a href="" class="help-dismiss" onclick="return false;"><img src="' + config.theme['images/btn_close.png'] + '" alt="' + get_string('closehelp') + '"></a>';
    result += '</div>';
    result += '<div id="helpstop">';
    result += content;
    result += '</div>';
    contextualHelpContainer.innerHTML = result;

    connect('helpstop', 'onclick', function(e) { if (e.target().nodeName != "A") { e.stop(); } });
    getFirstElementByTagAndClassName(null, 'help-dismiss', contextualHelpContainer).focus();
}

/*
 * Positions the box so that it's next to the link that was activated
 */
function contextualHelpPosition(ref, contextualHelpContainer) {
    $j(contextualHelpContainer).css('visibility', 'hidden').removeClass('hidden');

    var position = $j(ref).position();
    var offset = $j(ref).offset();
    var containerwidth = $j(contextualHelpContainer).outerWidth(true);

    // Adjust the position. The element is moved towards the centre of the
    // screen, based on which quadrant of the screen the help icon is in
    var screenwidth = $j(window).width();
    if (offset.left + containerwidth < screenwidth) {
        // Left of the screen - there's enough room for it
        position.left += 15;
    }
    else if (offset.left - containerwidth < 0) {
        var oldoffset = $j(contextualHelpContainer).offset();
        var oldposition = $j(contextualHelpContainer).position();

        if (containerwidth >= screenwidth) {
            // Very small screen, resize the help box to fit
            var margin = containerwidth - $j(contextualHelpContainer).width();
            $j(contextualHelpContainer).css('width', screenwidth - margin);
            position.left = oldposition.left - oldoffset.left;
        }
        else {
            // Otherwise center it
            position.left = (screenwidth / 2) - (containerwidth / 2) - oldoffset.left + oldposition.left;
        }
    }
    else {
        position.left -= containerwidth;
    }
    position.top -= 10;

    $j(contextualHelpContainer).css('visibility', 'visible');

    return {x: position.left, y: position.top};
}

/*
 * Ensures that the contextual help box given is fully visible on screen. This
 * will adjust the position of the help vertically if the help has opened right
 * next to the bottom or top of the viewport
 */
function ensureHelpIsOnScreen(container, position) {
    var screenheight = $j(window).height();
    var containerheight = $j(container).height();
    if (position.y + containerheight > screenheight + $j('html').scrollTop()) {
        position.y -= containerheight - 18;
        $j(container).css('top', position.y);
    }
}

/* Only works in non-ie at the moment. Using 'document' as the element
   makes IE detect the event, but then makes it so you need to click on
   the help twice before it opens. */
connect(document, 'onclick', function(e) {
    if (contextualHelpOpened && !badIE) {
        removeElement(contextualHelpContainer);
        contextualHelpContainer = null;
        contextualHelpSelected = null;
        contextualHelpOpened = false;
        if (contextualHelpLink) {
            contextualHelpLink.focus();
            contextualHelpLink = null;
        }
    }
    badIE = false;
});

// Cookie related functions
/* this function gets the cookie, if it exists */
function getCookie(name) {
    var start = document.cookie.indexOf( name + "=" );
    var len = start + name.length + 1;

    if (
        (!start) &&
        (name != document.cookie.substring(0, name.length))
    ) {
        return null;
    }

    if (start == -1) {
        return null;
    }

    var end = document.cookie.indexOf( ";", len );

    if (end == -1) {
        end = document.cookie.length;
    }

    return unescape(document.cookie.substring( len, end ));
}

function clearCookie( name ) {
    setCookie(name, '', -1);
}

// expires is in seconds
function setCookie( name, value, expires, path, domain, secure ) 
{
    // set time, it's in milliseconds
    var today = new Date();
    today.setTime( today.getTime() );

    /*
    if the expires variable is set, make the correct 
    expires time, the current script below will set 
    it for x number of days, to make it for hours, 
    delete * 24, for minutes, delete * 60 * 24
    */
    if (expires) {
        expires = expires * 1000;
    }

    var expires_date = new Date( today.getTime() + (expires) );

    document.cookie = name + "=" + escape( value ) +
    ( ( expires ) ? ";expires=" + expires_date.toGMTString() : "" ) + 
    ( ( path ) ? ";path=" + path : "" ) + 
    ( ( domain ) ? ";domain=" + domain : "" ) +
    ( ( secure ) ? ";secure" : "" );
}
// End cookie related functions

function toggleChecked(c) {
    var e = getElementsByTagAndClassName(null, c);
    if (e) {
        for (cb in e) {
        if (e[cb].checked == true) {
                e[cb].checked = '';
            } 
            else {
                e[cb].checked = 'checked';
            }
        }
    }
    return false;

}

function expandDownToViewport(element, width) {
    var viewport = getViewportDimensions();
    var position = getElementPosition(element);
    var newheight = new Dimensions(width, viewport.h - position.y - 2);

    if ($('footer')) {
        newheight.h -= getElementDimensions('footer').h + 40;
    }

    setElementDimensions(element, newheight);
}

function countKeys(x) {
    n = 0;
    for ( i in x ) n++;
    return n;
}

function keepElementInViewport(element) {
    var pixels = getViewportPosition().y + getViewportDimensions().h 
        - getElementPosition(element).y - getElementDimensions(element).h;
    if (pixels < 0) {
        window.scrollBy(0,-pixels);
    }
}

// this function takes an existing input element and augments it
function augment_tags_control(elem, returnContainer) {
    elem = getElement(elem);

    var tagContainer = DIV();
    // setElementDimensions(tagContainer, {'w': getElementDimensions(elem).w});
    var showLink = A({'href':''},get_string('showtags'));
    appendChildNodes(tagContainer, showLink);

    connect(showLink, 'onclick', function (e) {
        e.stop();
        replaceChildNodes(tagContainer, get_string('loading') + ' ', IMG({'src':get_themeurl('images/loading.gif')}));
        sendjsonrequest(config.wwwroot + 'json/taglist.php', {}, 'GET', function (data) {
            replaceChildNodes(tagContainer);
            if (data.length == 0) {
                appendChildNodes(tagContainer, get_string('youhavenottaggedanythingyet'));
            }
            else {
                var tagData = [];
                forEach(data, function(tag) {
                    var tagLink = A({'href':'', 'class':'tag'}, tag.tag);
                    connect(tagLink, 'onclick', function(e) {
                        e.stop();

                        if (typeof formchangemanager !== 'undefined') {
                            // Get the form which contains the tag input
                            var form = jQuery(elem).closest('form')[0];
                            formchangemanager.setFormState(form, FORM_CHANGED);
                        }

                        if (some(elem.value.split(/ *, */), function(t) { return t == tag.tag; })) {
                            // If at the start of the string, remove it and the comma/spaces after
                            elem.value = elem.value.replace(new RegExp('^' + escapeRegExp(tag.tag) + ',? *'), '');
                            // Otherwise, remove the comma/spaces before it
                            elem.value = elem.value.replace(new RegExp(', *' + escapeRegExp(tag.tag)), '');
                            return;
                        }

                        if (elem.value.match(/^ *$/) || elem.value.match(/, *$/)) {
                            elem.value += tag.tag;
                        }
                        else {
                            elem.value += ', ' + tag.tag;
                        }
                    });
                    tagData.push([tagLink, '\u00A0(', tag.count, ')']);
                    tagData.push(', ');
                    //appendChildNodes(tagContainer, tagLink, '\u00A0(', tag.count, ')');
                });
                // Remove the last comma
                tagData.pop();
                forEach(tagData, function(i) {
                    appendChildNodes(tagContainer, i);
                });
            }
        });
    });

    if (typeof(returnContainer) == 'boolean' && returnContainer) {
        return tagContainer;
    }

    var help = getFirstElementByTagAndClassName('span', 'help', elem.parentNode);
    
    var newNode = DIV();
    swapDOM(elem, newNode);
    appendChildNodes(newNode, tagContainer, elem, ' ', help);
};

function progressbarUpdate(artefacttype, remove) {
    if (! $('progress_bar')) {
        return;
    }
    // are we adding or deleting?
    var change = 1;
    if (remove) {
        change = -1;
    }

    // if we have the artefacttype and it needs to be updated
    if (typeof artefacttype != 'undefined') {
        if ($('progress_counting_' + artefacttype)) {
            var counting = parseInt($('progress_counting_' + artefacttype).innerHTML, 10);
            var oldcompleted = parseInt($('progress_completed_' + artefacttype).innerHTML, 10);
            var completed = oldcompleted + change;
            $('progress_completed_' + artefacttype).innerHTML = completed;
            var progressitem = $('progress_item_' + artefacttype);
            progressitem.innerHTML = progressitem.innerHTML.replace(/-?\d+/, counting - completed);

            // when progress is met
            if ((counting - completed) <= 0) {
                addElementClass(progressitem.parentNode.parentNode,'hidden');
            }
            else {
                removeElementClass(progressitem.parentNode.parentNode,'hidden');
            }
            // now update the totals if we need to
            if ((oldcompleted > 0 && oldcompleted <= counting && remove ) || (completed <= counting && !remove)) {
                var totalcounting = parseInt($('progress_counting_total').innerHTML, 10);
                var totalcompleted = parseInt($('progress_completed_total').innerHTML, 10) + change;
                $('progress_completed_total').innerHTML = totalcompleted;
                var percentage = roundToFixed((totalcompleted / totalcounting) * 100, 0);
                $('progress_bar_percentage').innerHTML = percentage + '%';
                setStyle($('progress_bar_fill'), {'width': (percentage*2) + 'px'});
            }
        }
    }
}

function quotaUpdate(quotaused, quota) {
    if ($('instconf')) {
        return;
    }
    if (! $('quota_percentage')) {
        logWarning('quotaUpdate(', quotaused, quota, ') called but no id="quota_percentage" on page');
        return;
    }

    var update = function(data) {
        if ( data.quota >= 1048576 ) {
            data.quota_display = roundToFixed(data.quota / 1048576, 1) + 'MB';
            data.quotaused_display = roundToFixed(data.quotaused / 1048576, 1) + 'MB';
        }
        else if (data.quota >= 1024 ) {
            data.quota_display = roundToFixed(data.quota / 1024, 1) + 'KB';
            data.quotaused_display = roundToFixed(data.quotaused / 1024, 1) + 'KB';
        }
        else {
            data.quota_display = data.quota + ' bytes';
            data.quotaused_display = data.quotaused + ' bytes';
        }

        var percentage = roundToFixed(data.quotaused / data.quota * 100, 0);
        var ref = $('quota_bar_100') || $('quota_bar');

        if (percentage < 100) {
            $('quota_fill').style.display = 'block';
            if (ref.id != 'quota_bar') {
                swapDOM(ref, P({'id': 'quota_bar'}, SPAN({'id': 'quota_percentage'})));
            }
        }
        else {
            $('quota_fill').style.display = 'none';
            if (ref.id != 'quota_bar_100') {
                swapDOM(ref, P({'id': 'quota_bar_100'}, SPAN({'id': 'quota_percentage'})));
            }
        }

        $('quota_used').innerHTML = data.quotaused_display;
        $('quota_total').innerHTML = data.quota_display;
        $('quota_percentage').innerHTML = percentage + '%';
        $('quota_fill').style.width = (percentage * 2) + 'px';
    };

    if ((typeof(quotaused) == 'number' || typeof(quotaused) == 'string') && quota) {
        var data = { 'quotaused': quotaused, 'quota': quota };
        update(data);
    }
    else {
        sendjsonrequest(config.wwwroot + 'json/quota.php', {}, 'POST', function (data) {
            update(data);
        }, null, true);
    }
}

function updateUnreadCount(data) {
    var inboxmenu = getFirstElementByTagAndClassName(null, 'inbox', 'right-nav');
    if (!inboxmenu) {
        return;
    }
    if (typeof(data.data.newunreadcount) != 'undefined') {
        var countnode = getFirstElementByTagAndClassName('span', 'unreadmessagecount', inboxmenu);
        if (countnode) {
            countnode.innerHTML = data.data.newunreadcount;
        }
    }
    if (data.data.newimage) {
        var oldimage = getFirstElementByTagAndClassName('img', null, inboxmenu);
        if (oldimage) {
            setNodeAttribute(oldimage, 'src', data.data.newimage);
        }
    }
}

// Work around hack for Mochikit Event key function
// (returns 0 for ff and onkeypress)
function keypressKeyCode(e) {
    if (typeof(e._event.charCode) != 'undefined' && e._event.charCode !== 0 &&
        !MochiKit.Signal._specialMacKeys[e._event.charCode]) {
        return e._event.charCode;
    }
    if (e._event.keyCode && (typeof(e._event.charCode) == 'undefined' || e._event.charCode == 0)) {
        return e._event.keyCode;
    }
    return 0;
}

function is_FF() {
    if ( /Firefox|Gecko|Iceweasel/.test(navigator.userAgent) && !/Chromium|Chrome|Safari|AppleWebKit/.test(navigator.userAgent) ) {
        return true;
    }
    return false;
}

// Escapes all special characters for RegExp, code from https://developer.mozilla.org/en/docs/Web/JavaScript/Guide/Regular_Expressions
function escapeRegExp(string) {
  return string.replace(/([.*+?^=!:${}()|\[\]\/\\])/g, "\\$1");
}

// Fix for Chrome and IE, which don't change focus when going to a fragment identifier link
// Manually focuses the main content when the "skip to main content" link is activated
jQuery(document).ready(function() {
    $j('a.skiplink').click(function() {
        var id = $j(this).attr('href');
        $j(id).attr('tabIndex', -1).focus();
    });
});

/**
* Allow the js / no-js toggle on all pages for theme styling
*/
jQuery(document).ready(function() {
    jQuery('body').removeClass('no-js').addClass('js');
});
