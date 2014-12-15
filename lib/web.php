<?php
/**
 *
 * @package    mahara
 * @subpackage core
 * @author     Catalyst IT Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL version 3 or later
 * @copyright  For copyright information on Mahara, please see the README file distributed with this software.
 * @copyright  (C) portions from Moodle, (C) Martin Dougiamas http://dougiamas.com
 */

defined('INTERNAL') || die();


function smarty_core() {
    require_once 'dwoo/dwoo/dwooAutoload.php';
    require_once 'dwoo/mahara/Dwoo_Mahara.php';

    return new Dwoo_Mahara();
}


/**
 * This function creates a Smarty object and sets it up for use within our
 * podclass app, setting up some variables.
 *
 * WARNING: If you are using pieforms, set them up BEFORE calling this function.
 *
 * The variables that it sets up are:
 *
 * - WWWROOT: The base url for the Mahara system
 * - USER: The user object
 * - JAVASCRIPT: A list of javascript files to include in the header.  This
 *   list is passed into this function (see below).
 * - HEADERS: An array of any further headers to set.  Each header is just
 *   straight HTML (see below).
 * - PUBLIC: Set true if this page is a public page
 * - MAINNAV: Array defining the main navigation
 *
 * @param $javascript A list of javascript includes.  Each include should be just
 *                    the name of a file, and reside in js/{filename}
 * @param $headers    A list of additional headers.  These are to be specified as
 *                    actual HTML.
 * @param $strings    A list of language strings required by the javascript code.
 * @return Smarty
 */

function smarty($javascript = array(), $headers = array(), $pagestrings = array(), $extraconfig = array()) {
    global $USER, $SESSION, $THEME, $HEADDATA, $langselectform;

    if (!is_array($headers)) {
        $headers = array();
    }
    if (!is_array($pagestrings)) {
        $pagestrings = array();
    }
    if (!is_array($extraconfig)) {
        $extraconfig = array();
    }

    $sideblocks = array();
    // Some things like die_info() will try and create a smarty() call when we are already in one, which causes
    // language_select_form() to throw headdata error as it is called twice.
    if (!isset($langselectform)) {
        $langselectform = language_select_form();
    }
    $smarty = smarty_core();

    $wwwroot = get_config('wwwroot');
    // NOTE: not using jswwwroot - it seems to wreck image paths if you
    // drag them around the wysiwyg editor
    $jswwwroot = json_encode($wwwroot);

    // Workaround for $cfg->cleanurlusersubdomains.
    // When cleanurlusersubdomains is on, ajax requests might come from somewhere other than
    // the wwwroot.  To avoid cross-domain requests, set a js variable when this page is on a
    // different subdomain, and let the ajax wrapper function sendjsonrequest rewrite its url
    // if necessary.
    if (get_config('cleanurls') && get_config('cleanurlusersubdomains')) {
        if ($requesthost = get_requested_host_name()) {
            $wwwrootparts = parse_url($wwwroot);
            if ($wwwrootparts['host'] != $requesthost) {
                $fakewwwroot = $wwwrootparts['scheme'] . '://' . $requesthost . '/';
                $headers[] = '<script type="text/javascript">var fakewwwroot = ' . json_encode($fakewwwroot) . ';</script>';
            }
        }
    }

    $theme_list = array();

    if (function_exists('pieform_get_headdata')) {
        $headers = array_merge($headers, pieform_get_headdata());
        if (!defined('PIEFORM_GOT_HEADDATA')) {
          define('PIEFORM_GOT_HEADDATA', 1);
        }
    }

    // Define the stylesheets array early so that javascript modules can add extras
    $stylesheets = array();

    // Insert the appropriate javascript tags
    $javascript_array = array();
    $jsroot = $wwwroot . 'js/';

    $langdirection = get_string('thisdirection', 'langconfig');

    // Make jQuery accessible with $j (Mochikit has $)
    $javascript_array[] = $jsroot . 'jquery/jquery.js';
    $javascript_array[] = $jsroot . 'jquery/deprecated_jquery.js';
    $headers[] = '<script type="text/javascript">$j=jQuery;</script>';

    // TinyMCE must be included first for some reason we're not sure about
    //
    // Note: we do not display tinyMCE for mobile devices
    // as it doesn't work on some of them and can
    // disable the editing of a textarea field
    if ($SESSION->get('handheld_device') == false) {
        $checkarray = array(&$javascript, &$headers);
        $found_tinymce = false;
        foreach ($checkarray as &$check) {
            if (($key = array_search('tinymce', $check)) !== false || ($key = array_search('tinytinymce', $check)) !== false) {
                if (!$found_tinymce) {
                    $found_tinymce = $check[$key];
                    $javascript_array[] = $jsroot . 'tinymce/tinymce.js';
                    $stylesheets = array_merge($stylesheets, array_reverse(array_values($THEME->get_url('style/tinymceskin.css', true))));
                    $content_css = json_encode($THEME->get_url('style/tinymce.css'));
                    $language = current_language();
                    $language = substr($language, 0, ((substr_count($language, '_') > 0) ? 5 : 2));
                    if ($language != 'en' && !file_exists(get_config('docroot') . 'js/tinymce/langs/' . $language . '.js')) {
                        // In case the language file exists as a string with both lower and upper case, eg fr_FR we test for this
                        $language = substr($language, 0, 2) . '_' . strtoupper(substr($language, 0, 2));
                        if (!file_exists(get_config('docroot') . 'js/tinymce/langs/' . $language . '.js')) {
                            // In case we fail to find a language of 5 chars, eg pt_BR (Portugese, Brazil) we try the 'parent' pt (Portugese)
                            $language = substr($language, 0, 2);
                            if ($language != 'en' && !file_exists(get_config('docroot') . 'js/tinymce/langs/' . $language . '.js')) {
                                $language = 'en';
                            }
                        }
                    }
                    $extrasetup = isset($extraconfig['tinymcesetup']) ? $extraconfig['tinymcesetup'] : '';
                    $extramceconfig = isset($extraconfig['tinymceconfig']) ? $extraconfig['tinymceconfig'] : '';

                    // Check whether to make the spellchecker available
                    $aspellpath = get_config('pathtoaspell');
                    if ($aspellpath && file_exists($aspellpath) && is_executable($aspellpath)) {
                        $spellchecker = ',spellchecker';
                        $spellchecker_config = "gecko_spellcheck : false, spellchecker_rpc_url : \"{$jsroot}tinymce/plugins/spellchecker/rpc.php\",";
                    }
                    else {
                        $spellchecker = '';
                        $spellchecker_config = 'gecko_spellcheck : true,';
                    }

                    $toolbar = array(
                        null,
                        '"toolbar_toggle | formatselect | bold italic | bullist numlist | link unlink | image | undo redo"',
                        '"underline strikethrough subscript superscript | alignleft aligncenter alignright alignjustify | outdent indent | forecolor backcolor | ltr rtl | fullscreen"',
                        '"fontselect | fontsizeselect | emoticons nonbreaking charmap | spellchecker | table | removeformat pastetext | code"',
                    );

                    // For right-to-left langs, reverse button order & align controls right.
                    $tinymce_langdir = $langdirection == 'rtl' ? 'rtl' : 'ltr';
                    $toolbar_align = 'left';

                    // Language strings required for TinyMCE
                    $pagestrings['mahara'] = isset($pagestrings['mahara']) ? $pagestrings['mahara'] : array();
                    $pagestrings['mahara'][] = 'attachedimage';

                    if ($check[$key] == 'tinymce') {
                        $tinymceconfig = <<<EOF
    theme: "modern",
    plugins: "tooltoggle,textcolor,link,image,table,emoticons,spellchecker,paste,code,fullscreen,directionality,searchreplace,nonbreaking,charmap",
    toolbar1: {$toolbar[1]},
    toolbar2: {$toolbar[2]},
    toolbar3: {$toolbar[3]},
    menubar: false,
    fix_list_elements: true,
    image_advtab: true,
    {$spellchecker_config}
EOF;
                    }
                    else {
                        $tinymceconfig = <<<EOF
    selector: "textarea.tinywysiwyg",
    theme: "modern",
    plugins: "fullscreen,autoresize",
    toolbar: {$toolbar[0]},
EOF;
                    }

                    $headers[] = <<<EOF
<script type="text/javascript">
tinyMCE.init({
    {$tinymceconfig}
    schema: 'html4',
    extended_valid_elements : "object[width|height|classid|codebase],param[name|value],embed[src|type|width|height|flashvars|wmode],script[src,type,language],+ul[id|type|compact],iframe[src|width|height|align|title|class|type|frameborder|allowfullscreen]",
    urlconverter_callback : "custom_urlconvert",
    language: '{$language}',
    directionality: "{$tinymce_langdir}",
    content_css : {$content_css},
    remove_script_host: false,
    relative_urls: false,
    {$extramceconfig}
    setup: function(ed) {
        ed.on('init', function(ed) {
            if (typeof(editor_to_focus) == 'string' && ed.editorId == editor_to_focus) {
                ed.focus();
            }
        });
        ed.on('LoadContent', function(e) {
            // Hide all the 2nd/3rd row menu buttons
            jQuery('.mce-toolbar.mce-first').siblings().toggleClass('hidden');
        });
        {$extrasetup}
    }
});
function custom_urlconvert (u, n, e) {
    // Don't convert the url on the skype status buttons.
    if (u.indexOf('skype:') == 0) {
      return u;
    }
    var t = tinyMCE.activeEditor, s = t.settings;

    // Don't convert link href since thats the CSS files that gets loaded into the editor also skip local file URLs
    if (!s.convert_urls || (e && e.nodeName == 'LINK') || u.indexOf('file:') === 0)
      return u;

    // Convert to relative
    if (s.relative_urls)
      return t.documentBaseURI.toRelative(u);

    // Convert to absolute
    u = t.documentBaseURI.toAbsolute(u, s.remove_script_host);

    return u;
}
</script>

EOF;
                    unset($check[$key]);
                }
                else {
                    if ($check[$key] != $found_tinymce) {
                        log_warn('Two differently configured tinyMCE instances have been asked for on this page! This is not possible');
                    }
                    unset($check[$key]);
                }
            }

            // If any page adds jquery explicitly, remove it from the list
            if (($key = array_search('jquery', $check)) !== false) {
                unset($check[$key]);
            }
        }
    }
    else {
        if (($key = array_search('tinymce', $javascript)) !== false || ($key = array_search('tinytinymce', $javascript)) !== false) {
            unset($javascript[$key]);
        }
        if (($key = array_search('tinymce', $headers)) !== false || ($key = array_search('tinytinymce', $headers)) !== false) {
            unset($headers[$key]);
        }
    }

    if (get_config('developermode') & DEVMODE_UNPACKEDJS) {
        $javascript_array[] = $jsroot . 'MochiKit/MochiKit.js';
        $javascript_array[] = $jsroot . 'MochiKit/Position.js';
        $javascript_array[] = $jsroot . 'MochiKit/Color.js';
        $javascript_array[] = $jsroot . 'MochiKit/Visual.js';
        $javascript_array[] = $jsroot . 'MochiKit/DragAndDrop.js';
        $javascript_array[] = $jsroot . 'MochiKit/Format.js';
    }
    else {
        $javascript_array[] = $jsroot . 'MochiKit/Packed.js';
    }
    $javascript_array[] = $jsroot . 'keyboardNavigation.js';

    $strings = array();
    foreach ($pagestrings as $k => $v) {
        if (is_array($v)) {
            foreach ($v as $tag) {
                $strings[$tag] = get_raw_string($tag, $k);
            }
        }
        else {
            $strings[$k] = get_raw_string($k, $v);
        }
    }

    $jsstrings = jsstrings();
    $themepaths = themepaths();

    foreach ($javascript as $jsfile) {
        // For now, if there's no path in the js file, assume it's in
        // $jsroot and append '.js' to the name.  Later we may want to
        // ensure all smarty() calls include the full path to the js
        // file, with the proper extension.
        if (strpos($jsfile, '/') === false) {
            $javascript_array[] = $jsroot . $jsfile . '.js';
            if (isset($jsstrings[$jsfile])) {
                foreach ($jsstrings[$jsfile] as $section => $tags) {
                    foreach ($tags as $tag) {
                        $strings[$tag] = get_raw_string($tag, $section);
                    }
                }
            }
            if (isset($themepaths[$jsfile])) {
                foreach ($themepaths[$jsfile] as $themepath) {
                    $theme_list[$themepath] = $THEME->get_url($themepath);
                }
            }
        }
        else if (stripos($jsfile, 'http://') === false && stripos($jsfile, 'https://') === false) {
            // A local .js file with a fully specified path
            $javascript_array[] = $wwwroot . $jsfile;
            // If $jsfile is from a plugin (i.e. plugintype/pluginname/js/foo.js)
            // Then get js strings from static function jsstrings in plugintype/pluginname/lib.php
            $bits = explode('/', $jsfile);
            if (count($bits) == 4) {
                safe_require($bits[0], $bits[1]);
                $pluginclass = generate_class_name($bits[0], $bits[1]);
                $name = substr($bits[3], 0, strpos($bits[3], '.js'));
                if (is_callable(array($pluginclass, 'jsstrings'))) {
                    $tempstrings = call_static_method($pluginclass, 'jsstrings', $name);
                    foreach ($tempstrings as $section => $tags) {
                        foreach ($tags as $tag) {
                            $strings[$tag] = get_raw_string($tag, $section);
                        }
                    }
                }
                if (is_callable(array($pluginclass, 'jshelp'))) {
                    $tempstrings = call_static_method($pluginclass, 'jshelp', $name);
                    foreach ($tempstrings as $section => $tags) {
                        foreach ($tags as $tag) {
                            $strings[$tag . '.help'] = get_help_icon($bits[0], $bits[1], null, null,
                                                                     null, $tag);
                        }
                    }
                }
                if (is_callable(array($pluginclass, 'themepaths'))) {
                    $tmpthemepaths = call_static_method($pluginclass, 'themepaths', $name);
                    foreach ($tmpthemepaths as $themepath) {
                        $theme_list[$themepath] = $THEME->get_url($themepath);
                    }
                }
            }
        }
        else {
            // A remote .js file
            $javascript_array[] = $jsfile;
        }
    }

    $javascript_array[] = $jsroot . 'mahara.js';
    $javascript_array[] = $jsroot . 'formchangechecker.js';
    if (get_config('developermode') & DEVMODE_DEBUGJS) {
        $javascript_array[] = $jsroot . 'debug.js';
    }

    foreach ($jsstrings['mahara'] as $section => $tags) {
        foreach ($tags as $tag) {
            $strings[$tag] = get_raw_string($tag, $section);
        }
    }
    if (isset($extraconfig['themepaths']) && is_array($extraconfig['themepaths'])) {
        foreach ($extraconfig['themepaths'] as $themepath) {
            $theme_list[$themepath] = $THEME->get_url($themepath);
        }
    }

    $stringjs = '<script type="text/javascript">';
    $stringjs .= 'var strings = ' . json_encode($strings) . ';';
    $stringjs .= "\nfunction plural(n) { return " . get_raw_string('pluralrule', 'langconfig') . "; }\n";
    $stringjs .= '</script>';

    // stylesheet set up - if we're in a plugin also get its stylesheet
    $stylesheets = array_merge($stylesheets, array_reverse(array_values($THEME->get_url('style/style.css', true))));
    if (defined('SECTION_PLUGINTYPE') && defined('SECTION_PLUGINNAME') && SECTION_PLUGINTYPE != 'core') {
        if ($pluginsheets = $THEME->get_url('style/style.css', true, SECTION_PLUGINTYPE . '/' . SECTION_PLUGINNAME)) {
            $stylesheets = array_merge($stylesheets, array_reverse($pluginsheets));
        }
    }

    if ($adminsection = in_admin_section()) {
        if ($adminsheets = $THEME->get_url('style/admin.css', true)) {
            $stylesheets = array_merge($stylesheets, array_reverse($adminsheets));
        }
    }

    if (get_config('developermode') & DEVMODE_DEBUGCSS) {
        $stylesheets[] = get_config('wwwroot') . 'theme/debug.css';
    }

    // look for extra stylesheets
    if (isset($extraconfig['stylesheets']) && is_array($extraconfig['stylesheets'])) {
        foreach ($extraconfig['stylesheets'] as $extrasheet) {
            if ($sheets = $THEME->get_url($extrasheet, true)) {
                $stylesheets = array_merge($stylesheets, array_reverse(array_values($sheets)));
            }
        }
    }
    if ($sheets = $THEME->additional_stylesheets()) {
        $stylesheets = array_merge($stylesheets, $sheets);
    }

    // Give the skin a chance to affect the page
    if (!empty($extraconfig['skin'])) {
        require_once(get_config('docroot').'/lib/skin.php');
        $skinobj = new Skin($extraconfig['skin']['skinid']);
        $viewid = isset($extraconfig['skin']['viewid']) ? $extraconfig['skin']['viewid'] : null;
        $stylesheets = array_merge($stylesheets, $skinobj->get_stylesheets($viewid));
    }

    // Allow us to set the HTML lang attribute
    $smarty->assign('LANGUAGE', substr(current_language(), 0, 2));

    // Include rtl.css for right-to-left langs
    if ($langdirection == 'rtl') {
        $smarty->assign('LANGDIRECTION', 'rtl');
        if ($rtlsheets = $THEME->get_url('style/rtl.css', true)) {
            $stylesheets = array_merge($stylesheets, array_reverse($rtlsheets));
        }
    }

    $smarty->assign('STRINGJS', $stringjs);
    $stylesheets = append_version_number($stylesheets);
    $smarty->assign('STYLESHEETLIST', $stylesheets);
    if (!empty($theme_list)) {
        // this gets assigned in smarty_core, but do it again here if it's changed locally
        $smarty->assign('THEMELIST', json_encode(array_merge((array)json_decode($smarty->get_template_vars('THEMELIST')),  $theme_list)));
    }

    $dropdownmenu = get_config('dropdownmenu');
    // disable drop-downs if overridden at institution level
    $sitethemeprefs = get_config('sitethemeprefs');
    $institutions = $USER->institutions;
    if (!empty($institutions)) {
        foreach ($institutions as $i) {
            if (!empty($sitethemeprefs)) {
                if (!empty($USER->accountprefs['theme']) && $USER->accountprefs['theme'] == $THEME->basename . '/' . $i->institution) {
                    $dropdownmenu = $i->dropdownmenu;
                }
            }
            else {
                if ((!empty($USER->accountprefs['theme']) && $USER->accountprefs['theme'] == $THEME->basename . '/' . $i->institution)
                    || (empty($USER->accountprefs) && $i->theme == $THEME->basename && $USER->institutiontheme->institutionname == $i->institution)) {
                    $dropdownmenu = $i->dropdownmenu;
                }
            }
        }
    }

    // and/or disable drop-downs if a handheld device detected
    $dropdownmenu = $SESSION->get('handheld_device') ? false : $dropdownmenu;

    if ($dropdownmenu) {
        $smarty->assign('DROPDOWNMENU', $dropdownmenu);
        $javascript_array[] = $jsroot . 'dropdown-nav.js';
    }

    $smarty->assign('MOBILE', $SESSION->get('mobile'));
    $smarty->assign('HANDHELD_DEVICE', $SESSION->get('handheld_device'));

    $sitename = get_config('sitename');
    if (!$sitename) {
       $sitename = 'Mahara';
    }
    $smarty->assign('sitename', $sitename);
    $sitelogo = $THEME->header_logo();
    $sitelogo = append_version_number($sitelogo);
    $smarty->assign('sitelogo', $sitelogo);
    $smarty->assign('sitelogo4facebook', $THEME->facebook_logo());
    $smarty->assign('sitedescription4facebook', get_string('facebookdescription', 'mahara'));

    if (defined('TITLE')) {
        $smarty->assign('PAGETITLE', TITLE . ' - ' . $sitename);
        $smarty->assign('heading', TITLE);
    }
    else {
        $smarty->assign('PAGETITLE', $sitename);
    }

    $smarty->assign('PRODUCTIONMODE', get_config('productionmode'));
    if (function_exists('local_header_top_content')) {
        $sitetop = (isset($sitetop) ? $sitetop : '') . local_header_top_content();
    }
    if (isset($sitetop)) {
        $smarty->assign('SITETOP', $sitetop);
    }
    if (defined('PUBLIC')) {
        $smarty->assign('PUBLIC', true);
    }
    if (defined('ADMIN')) {
        $smarty->assign('ADMIN', true);
    }
    if (defined('INSTITUTIONALADMIN')) {
        $smarty->assign('INSTITUTIONALADMIN', true);
    }
    if (defined('STAFF')) {
        $smarty->assign('STAFF', true);
    }
    if (defined('INSTITUTIONALSTAFF')) {
        $smarty->assign('INSTITUTIONALSTAFF', true);
    }

    $smarty->assign('LOGGEDIN', $USER->is_logged_in());
    $publicsearchallowed = false;
    $searchplugin = get_config('searchplugin');
    if ($searchplugin) {
        safe_require('search', $searchplugin);
        $publicsearchallowed = (call_static_method(generate_class_name('search', $searchplugin), 'publicform_allowed') && get_config('publicsearchallowed'));
    }
    $smarty->assign('publicsearchallowed', $publicsearchallowed);
    if ($USER->is_logged_in()) {
        global $SELECTEDSUBNAV; // It's evil, but rightnav & mainnav stuff are now in different templates.
        $smarty->assign('MAINNAV', main_nav());
        $mainnavsubnav = $SELECTEDSUBNAV;
        $smarty->assign('RIGHTNAV', right_nav());
        if (!$mainnavsubnav && $dropdownmenu) {
            // In drop-down navigation, the submenu is only usable if its parent is one of the top-level menu
            // items.  But if the submenu comes from something in right_nav (settings), it's unreachable.
            // Turning the submenu into SUBPAGENAV group-style tabs makes it usable.
            $smarty->assign('SUBPAGENAV', $SELECTEDSUBNAV);
        }
        else {
            $smarty->assign('SELECTEDSUBNAV', $SELECTEDSUBNAV);
        }
    }
    else {
        $smarty->assign('languageform', $langselectform);
    }
    $smarty->assign('FOOTERMENU', footer_menu());

    $smarty->assign_by_ref('USER', $USER);
    $smarty->assign('SESSKEY', $USER->get('sesskey'));
    $smarty->assign('CC_ENABLED', get_config('cookieconsent_enabled'));
    $javascript_array = append_version_number($javascript_array);
    $smarty->assign_by_ref('JAVASCRIPT', $javascript_array);
    $smarty->assign('RELEASE', get_config('release'));
    $smarty->assign('SERIES', get_config('series'));
    $smarty->assign('CACHEVERSION', get_config('cacheversion'));
    $siteclosedforupgrade = get_config('siteclosed');
    if ($siteclosedforupgrade && get_config('disablelogin')) {
        $smarty->assign('SITECLOSED', 'logindisabled');
    }
    else if ($siteclosedforupgrade || get_config('siteclosedbyadmin')) {
        $smarty->assign('SITECLOSED', 'loginallowed');
    }

    if ((!isset($extraconfig['pagehelp']) || $extraconfig['pagehelp'] !== false)
        and $help = has_page_help()) {
        $smarty->assign('PAGEHELPNAME', $help[0]);
        $smarty->assign('PAGEHELPICON', $help[1]);
    }
    if (defined('GROUP')) {
        require_once('group.php');
        if ($group = group_current_group()) {
            $smarty->assign('GROUP', $group);
            if (!defined('NOGROUPMENU')) {
                $smarty->assign('SUBPAGENAV', group_get_menu_tabs());
                $smarty->assign('PAGEHEADING', $group->name);
            }
        }
    }

    // ---------- sideblock stuff ----------
    $sidebars = !isset($extraconfig['sidebars']) || $extraconfig['sidebars'] !== false;
    if ($sidebars && !defined('INSTALLER') && (!defined('MENUITEM') || substr(MENUITEM, 0, 5) != 'admin')) {
        if (get_config('installed') && !$adminsection) {
            $data = site_menu();
            if (!empty($data)) {
                $smarty->assign('SITEMENU', site_menu());
                $sideblocks[] = array(
                    'name'   => 'linksandresources',
                    'weight' => 10,
                    'data'   => $data,
                );
            }
        }

        if ($USER->is_logged_in() && defined('MENUITEM') &&
            (substr(MENUITEM, 0, 11) == 'myportfolio' || substr(MENUITEM, 0, 7) == 'content')) {
            if (get_config('showselfsearchsideblock')) {
                $sideblocks[] = array(
                    'name'   => 'selfsearch',
                    'weight' => 0,
                    'data'   => array(),
                );
            }
            if (get_config('showtagssideblock')) {
                $sideblocks[] = array(
                    'name'   => 'tags',
                    'id'     => 'sb-tags',
                    'weight' => 0,
                    'data'   => tags_sideblock(),
                );
            }
        }

        if ($USER->is_logged_in() && !$adminsection) {
            $sideblocks[] = array(
                'name'   => 'profile',
                'id'     => 'sb-profile',
                'weight' => -20,
                'data'   => profile_sideblock()
            );
            $showusers = 2;
            $institutions = $USER->institutions;
            if (!empty($institutions)) {
                $showusers = 0;
                foreach ($institutions as $i) {
                    if ($i->showonlineusers == 2) {
                        $showusers = 2;
                        break;
                    }
                    if ($i->showonlineusers == 1) {
                        $showusers = 1;
                    }
                }
            }
            if (get_config('showonlineuserssideblock') && $showusers > 0) {
                $sideblocks[] = array(
                    'name'   => 'onlineusers',
                    'id'     => 'sb-onlineusers',
                    'weight' => -10,
                    'data'   => onlineusers_sideblock(),
                );
            }
            if (get_config('showprogressbar') && $USER->get_account_preference('showprogressbar')) {
                $sideblocks[] = array(
                    'name'   => 'progressbar',
                    'id'     => 'sb-progressbar',
                    'weight' => -8,
                    'data'   => progressbar_sideblock(),
                );
            }
        }

        if ($USER->is_logged_in() && $adminsection && defined('SECTION_PAGE') && SECTION_PAGE == 'progressbar') {
            $sideblocks[] = array(
                'name'   => 'progressbar',
                'id'     => 'sb-progressbar',
                'weight' => -8,
                'data'   => progressbar_sideblock(true),
            );
        }

        if (!$USER->is_logged_in() && !(get_config('siteclosed') && get_config('disablelogin'))) {
            $sideblocks[] = array(
                'name'   => 'login',
                'weight' => -10,
                'id'     => 'sb-loginbox',
                'data'   => array(
                    'loginform' => auth_generate_login_form(),
                ),
            );
        }

        if (get_config('enablenetworking')) {
            require_once(get_config('docroot') .'api/xmlrpc/lib.php');
            if ($USER->is_logged_in() && $ssopeers = get_service_providers($USER->authinstance)) {
                $sideblocks[] = array(
                    'name'   => 'ssopeers',
                    'weight' => 1,
                    'data'   => $ssopeers,
                );
            }
        }

        if (isset($extraconfig['sideblocks']) && is_array($extraconfig['sideblocks'])) {
            foreach ($extraconfig['sideblocks'] as $sideblock) {
                $sideblocks[] = $sideblock;
            }
        }

        // local_sideblocks_update allows sites to customise the sideblocks by munging the $sideblocks array.
        if (function_exists('local_sideblocks_update')) {
            local_sideblocks_update($sideblocks);
        }

        usort($sideblocks, create_function('$a,$b', 'if ($a["weight"] == $b["weight"]) return 0; return ($a["weight"] < $b["weight"]) ? -1 : 1;'));

        // Place all sideblocks on the right. If this structure is munged
        // appropriately, you can put blocks on the left. In future versions of
        // Mahara, we'll make it easy to do this.
        $sidebars = $sidebars && !empty($sideblocks);
        $sideblocks = array('left' => array(), 'right' => $sideblocks);

        $smarty->assign('userauthinstance', $SESSION->get('authinstance'));
        $smarty->assign('MNETUSER', $SESSION->get('mnetuser'));
        $smarty->assign('SIDEBLOCKS', $sideblocks);
        $smarty->assign('SIDEBARS', $sidebars);

    }

    if (is_array($HEADDATA) && !empty($HEADDATA)) {
        $headers = array_merge($HEADDATA, $headers);
    }
    $smarty->assign_by_ref('HEADERS', $headers);

    if ($USER->get('parentuser')) {
        $smarty->assign('USERMASQUERADING', true);
        $smarty->assign('masqueradedetails', get_string('youaremasqueradingas', 'mahara', display_name($USER)));
        $smarty->assign('becomeyouagain',
            ' <a href="' . hsc($wwwroot) . 'admin/users/changeuser.php?restore=1">'
            . get_string('becomeadminagain', 'admin', hsc($USER->get('parentuser')->name))
            . '</a>');
    }

    // Define additional html content
    if (get_config('installed')) {
        $additionalhtmlitems = array(
            'ADDITIONALHTMLHEAD'      => get_config('additionalhtmlhead'),
            'ADDITIONALHTMLTOPOFBODY' => get_config('additionalhtmltopofbody'),
            'ADDITIONALHTMLFOOTER'    => get_config('additionalhtmlfooter')
        );
        if ($additionalhtmlitems) {
            foreach ($additionalhtmlitems as $name=>$content) {
                $smarty->assign($name, $content);
            }
        }
    }

    // If Cookie Consent is enabled, than define conent
    if (get_config('cookieconsent_enabled')) {
        require_once('cookieconsent.php');
        $smarty->assign('COOKIECONSENTCODE', get_cookieconsent_code());
    }
    return $smarty;
}


/**
 * Manages theme configuration.
 *
 * Does its best to give the user _a_ theme, even if it's not the theme they
 * want to use (e.g. the theme they want has been uninstalled)
 */
class Theme {

    /**
     * The base name of the theme (the name of the directory in which it lives)
     */
    public $basename = '';

    /**
     * A user may have had the header logo overridden by an institution
     */
    public $headerlogo;

    /**
     * Additional stylesheets to display after the basename theme's stylesheets
     */
    public $addedstylesheets;

    /**
     * A human-readable version of the theme name
     */
    public $displayname = '';

    /**
     * Which pieform renderer to use by default for all forms
     */
    public $formrenderer = '';

    /**
     * Directories where to look for templates by default
     */
    public $templatedirs = array();

    /**
     * Theme inheritance path from this theme to 'raw'
     */
    public $inheritance = array();

    /**
     * What unit the left/center/right column widths are in. 'pixels' or 'percent'
     */
    public $columnwidthunits    = '';

    /**
     * Width of the left column. Integer - see $columnwidthunits
     */
    public $leftcolumnwidth     = 256;

    /**
     * Background colour for the left column
     */
    public $leftcolumnbgcolor   = '#fff';

    /**
     * Background colour for the center column
     */
    public $centercolumnbgcolor = '#fff';

    /**
     * Width of the right column. Integer - see $columnwidthunits
     */
    public $rightcolumnwidth    = 256;

    /**
     * Background colour for the right column
     */
    public $rightcolumnbgcolor  = '#fff';


    /**
     * Initialises a theme object based on the theme 'hint' passed.
     *
     * If arg is a string, it's taken to be a theme name. If it's a user
     * object, we ask it for a theme name. If it's an integer, we pretend
     * that's a user ID and ask for the theme for that user.
     *
     * If the theme they want doesn't exist, the object is initialised for the
     * default theme. This means you can initialise one of these for a user
     * and then use it without worrying if the theme exists.
     *
     * @param mixed $arg Theme name, user object or user ID
     */
    public function __construct($arg) {
        if (is_string($arg)) {
            $themename = $arg;
            $themedata = null;
        }
        else if ($arg instanceof User) {
            $themedata = $arg->get_themedata();
        }
        else if (is_int($arg)) {
            $user = new User();
            $user->find_by_id($arg);
            $themedata = $user->get_themedata();
        }
        else {
            throw new SystemException("Argument to Theme::__construct was not a theme name, user object or user ID");
        }

        if (isset($themedata)) {
            $themename = $themedata->basename;
        }

        if (empty($themename)) {
            // Theme to show to when no theme has been suggested
            if (!$themename = get_config('theme')) {
                $themename = 'raw';
            }
        }

        // check the validity of the name
        if (!$this->name_is_valid($themename)) {
            throw new SystemException("Theme name is in invalid form: '$themename'");
        }

        $this->init_theme($themename, $themedata);
    }

    /**
     * Given a theme name, check that it is valid
     */
    public static function name_is_valid($themename) {
        // preg_match returns 0 if invalid characters were found, 1 if not
        return (preg_match('/^[a-zA-Z0-9_-]+$/', $themename) == 1);
    }

    /**
     * Given a theme name, reads in all config and sets fields on this object
     */
    private function init_theme($themename, $themedata) {
        $this->basename = $themename;

        $themeconfigfile = get_config('docroot') . 'theme/' . $this->basename . '/themeconfig.php';
        if (!is_readable($themeconfigfile)) {
            // We can safely assume that the default theme is installed, users
            // should never be able to remove it
            $this->basename = 'default';
            $themeconfigfile = get_config('docroot') . 'theme/default/themeconfig.php';
        }

        require($themeconfigfile);

        foreach (get_object_vars($theme) as $key => $value) {
            $this->$key = $value;
        }

        if (!isset($this->displayname)) {
            $this->displayname = $this->basename;
        }
        if (!isset($theme->parent) || !$theme->parent) {
            $theme->parent = 'raw';
        }

        // Local theme overrides come first
        $this->templatedirs[] = get_config('docroot') . 'local/theme/templates/';

        // Then the current theme
        $this->templatedirs[] = get_config('docroot') . 'theme/' . $this->basename . '/templates/';
        $this->inheritance[]  = $this->basename;


        // Now go through the theme hierarchy assigning variables from the
        // parent themes
        $currenttheme = $this->basename;
        while ($currenttheme != 'raw') {
            $currenttheme = isset($theme->parent) ? $theme->parent : 'raw';
            $parentconfigfile = get_config('docroot') . 'theme/' . $currenttheme . '/themeconfig.php';
            require($parentconfigfile);
            foreach (get_object_vars($theme) as $key => $value) {
                if (!isset($this->$key) || !$this->$key) {
                    $this->$key = $value;
                }
            }
            $this->templatedirs[] = get_config('docroot') . 'theme/' . $currenttheme . '/templates/';
            $this->inheritance[]  = $currenttheme;
        }

        if (!empty($themedata->headerlogo)) {
            $this->headerlogo = $themedata->headerlogo;
        }
        if (!empty($themedata->stylesheets)) {
            $this->addedstylesheets = $themedata->stylesheets;
        }
    }

    /**
     * Get the URL of a particular theme asset (i.e. an image or CSS file). Checks first for a copy
     * in /local/theme/static, then in the current theme, then this theme's parent, grandparent, etc.
     *
     * @param string $filename Relative path of the asset, e.g. 'images/newmail.png'
     * @param boolean $all Whether to return the first found copy of the asset, or all copies of it from all themes
     * in the hierarchy.
     * @param string $plugindirectory For if it's a plugin theme asset, e.g. 'artefact/file'
     * @return string|array The URL of the first match, or all matching ones, depending on $all
     */
    public function get_url($filename, $all=false, $plugindirectory='') {
        return $this->_get_path($filename, $all, $plugindirectory, get_config('wwwroot'));
    }

    /**
     * Get the full filesystem path of a particular theme asset (i.e. an image or CSS file). Checks first for a copy
     * in /local/theme/static, then in the current theme, then this theme's parent, grandparent, etc.
     *
     * @param string $filename Relative path of the asset, e.g. 'images/newmail.png'
     * @param boolean $all Whether to return the first found copy of the asset, or all copies it from all
     * themes in the hierarchy
     * @param string $plugindirectory For if it's a plugin theme asset, e.g. 'artefact/file'
     * @return string|array The full filesystem path of the first match, or of all matches, depending on $all
     */
    public function get_path($filename, $all=false, $plugindirectory='') {
        return $this->_get_path($filename, $all, $plugindirectory, get_config('docroot'));
    }

    /**
     * Internal function to return the path or URL of a particular theme asset. Relies on the fact that the URL
     * and the filesystem path are the same, except that one is prefaced by docroot and the other by wwwroot.
     *
     * @param string $filename Relative path of the asset, e.g. 'images/newmail.png'
     * @param boolean $all Whether to return the first found copy of the asset, or all copies it from all
     * themes in the hierarchy
     * @param string $plugindirectory For if it's a plugin theme asset, e.g. 'artefact/file'
     * @param string $returnprefix The part to put before the Mahara-relative path of the file. (i.e. docroot or wwwroot)
     * @return string|array The first match, or of all matches, depending on $all
     */
    private function _get_path($filename, $all, $plugindirectory, $returnprefix) {
        $list = array();
        $plugindirectory = ($plugindirectory && substr($plugindirectory, -1) != '/') ? $plugindirectory . '/' : $plugindirectory;

        // Local theme overrides come first
        $localloc = "local/theme/{$plugindirectory}static/{$filename}";
        if (is_readable(get_config('docroot') . $localloc)) {
            if ($all) {
                $list['local'] = $returnprefix . $localloc;
            }
            else {
                return $returnprefix . $localloc;
            }
        }

        // Then check each theme
        foreach ($this->inheritance as $themedir) {
            $searchloc = array();
            // Check in the /theme directory
            $searchloc[] = "theme/{$themedir}/{$plugindirectory}static/{$filename}";
            if ($plugindirectory) {
                // Then check in the plugin's own directory
                $searchloc[] = "{$plugindirectory}theme/{$themedir}/static/{$filename}";
            }
            foreach($searchloc as $loc) {
                if (is_readable(get_config('docroot') . $loc)) {
                    if ($all) {
                        $list[$themedir] = $returnprefix . $loc;
                    }
                    else {
                        return $returnprefix . $loc;
                    }
                }
            }
        }
        if ($all) {
            return $list;
        }

        $extra = '';
        if ($plugindirectory) {
            $extra = ", plugindir $plugindirectory";
        }
        log_debug("Missing file in theme {$this->basename}{$extra}: $filename");
        return $returnprefix . $plugindirectory . 'theme/' . $themedir . '/static/' . $filename;
    }

    /**
     * Displaying of the header logo
     * If $name is specified the site-logo-[$name].png will be returned
     */
    public function header_logo($name = false) {
        if (!empty($this->headerlogo)) {
            return get_config('wwwroot') . 'thumb.php?type=logobyid&id=' . $this->headerlogo;
        }
        else if ($name) {
            return $this->get_url('images/site-logo-' . $name . '.png');
        }
        return $this->get_url('images/site-logo.png');
    }

    public function facebook_logo() {
        return $this->get_url('images/site-logo4facebook.png');
    }

    public function additional_stylesheets() {
        return $this->addedstylesheets;
    }
}


/**
 * Returns the lists of strings used in the .js files
 * @return array
 */

function jsstrings() {
    return array(
       'mahara' => array(                        // js file
            'mahara' => array(                   // section
                'namedfieldempty',               // string name
                'processing',
                'unknownerror',
                'loading',
                'showtags',
                'couldnotgethelp',
                'password',
                'deleteitem',
                'moveitemup',
                'moveitemdown',
                'username',
                'login',
                'sessiontimedout',
                'loginfailed',
                'home',
                'youhavenottaggedanythingyet',
                'wanttoleavewithoutsaving?',
                'Help',
                'closehelp',
                'tabs',
                'toggletoolbarson',
                'toggletoolbarsoff',
                'imagexofy',
            ),
            'pieforms' => array(
                'element.calendar.opendatepicker'
            )
        ),
        'tablerenderer' => array(
            'mahara' => array(
                'firstpage',
                'nextpage',
                'prevpage',
                'lastpage',
            )
        ),
        'views' => array(
            'view' => array(
                'confirmdeleteblockinstance',
                'blocksinstructionajax',
            ),
        ),
    );
}

function themepaths() {

    static $paths;
    if (empty($paths)) {
        $paths = array(
            'mahara' => array(
                'images/btn_close.png',
                'images/btn_deleteremove.png',
                'images/btn_edit.png',
                'images/failure.png',
                'images/loading.gif',
                'images/success.png',
                'images/warning.png',
                'images/help.png',
                'style/js.css',
            ),
        );
    }
    return $paths;
}

/**
 * Takes an array of string identifiers and returns an array of the
 * corresponding strings, quoted for use in inline javascript here
 * docs.
 */

function quotestrings($strings) {
    $qstrings = array();
    foreach ($strings as $section => $tags) {
        foreach ($tags as $tag) {
            $qstrings[$tag] = json_encode(get_string($tag, $section));
        }
    }
    return $qstrings;
}

/**
 * This function sets up and caches info about the current selected theme
 * contains inheritance path (used for locating images) and template dirs
 * and potentially more stuff later ( like mime header to send (html vs xhtml))
 * @return object
 */
function theme_setup() {
    global $THEME;
    log_warn("theme_setup() is deprecated - please use the global \$THEME object instead");
    return $THEME;
}

/**
 * This function returns the full url to an image
 * Always use it to get image urls
 * @param $imagelocation path to image relative to theme/$theme/static/
 * @param $pluginlocation path to plugin relative to docroot
 */
function theme_get_url($location, $pluginlocation='', $all = false) {
    global $THEME;
    log_warn("theme_get_url() is deprecated: Use \$THEME->get_url() instead");
    $plugintype = $pluginname = '';
    if ($pluginlocation) {
        list($plugintype, $pluginname) = explode('/', $pluginlocation);
        $pluginname = substr($pluginname, 0, -1);
    }
    return $THEME->get_url($location, $all, $plugintype, $pluginname);
}

/**
 * This function returns the full path to an image
 * Always use it to get image paths
 * @param $imagelocation path to image relative to theme/$theme/static/
 * @param $pluginlocation path to plugin relative to docroot
 */
function theme_get_path($location, $pluginlocation='', $all=false) {
    global $THEME;
    log_warn("theme_get_path() is deprecated: Use \$THEME->get_path() instead");
    $plugintype = $pluginname = '';
    if ($pluginlocation) {
        list($plugintype, $pluginname) = explode('/', $pluginlocation);
        $pluginname = substr($pluginname, 0, -1);
    }
    return $THEME->get_path($location, $all, $plugintype, $pluginname);
}

/**
 * This function sends headers suitable for all JSON returning scripts.
 *
 */
function json_headers() {
    header('Content-type: application/json');
    header('Pragma: no-cache');
}

/**
 * This function sends a JSON message, and ends the script.
 *
 * Scripts receiving replies will recieve a JSON array with two fields:
 *
 *  - error: True or false depending on whether the request was successful
 *  - message: JSON data representing a message sent back from the script
 *
 * @param boolean $error   Whether the script ended in an error or not
 * @param string  $message A message to pass back to the user, can be an
 *                         array of JSON data
 */
function json_reply($error, $message, $returncode=0) {
    json_headers();
    echo json_encode(array('error' => $error, 'message' => $message, 'returnCode' => $returncode));
    perf_to_log();
    exit;
}

function _param_retrieve($name) {
    // prefer post
    if (isset($_POST[$name])) {
        $value = $_POST[$name];
    }
    else if (isset($_GET[$name])) {
        $value = $_GET[$name];
    }
    else if (func_num_args() == 2) {
        $php_work_around = func_get_arg(1);
        return array($php_work_around, true);
    }
    else {
        throw new ParameterException("Missing parameter '$name' and no default supplied");
    }

    return array($value, false);
}

function param_exists($name) {
    return isset($_POST[$name]) || isset($_GET[$name]);
}

/**
 * This function returns a GET or POST parameter with optional default.  If the
 * default isn't specified and the parameter hasn't been sent, a
 * ParameterException exception is thrown
 *
 * @param string The GET or POST parameter you want returned
 * @param mixed [optional] the default value for this parameter
 *
 * @return string The value of the parameter
 *
 */
function param_variable($name) {
    $args = func_get_args();
    list ($value) = call_user_func_array('_param_retrieve', $args);
    return $value;
}

/**
 * This function returns a GET or POST parameter as an integer with optional
 * default.  If the default isn't specified and the parameter hasn't been sent,
 * a ParameterException exception is thrown. Likewise, if the parameter isn't a
 * valid integer, a ParameterException exception is thrown
 *
 * @param string The GET or POST parameter you want returned
 * @param mixed [optional] the default value for this parameter
 *
 * @return int The value of the parameter
 *
 */
function param_integer($name) {
    $args = func_get_args();

    list ($value, $defaultused) = call_user_func_array('_param_retrieve', $args);

    if ($defaultused) {
        return $value;
    }

    $value = trim($value);

    if (preg_match('/^\d+$/',$value)) {
        return (int)$value;
    }
    else if ($value == '' && isset($args[1])) {
        return $args[1];
    }

    throw new ParameterException("The '$name' parameter is not an integer");
}

/**
 * This function returns a GET or POST parameter as an integer with optional
 * default.  If the default isn't specified and the parameter hasn't been sent,
 * a ParameterException exception is thrown. Likewise, if the parameter isn't a
 * valid integer(allows signed integers), a ParameterException exception is thrown
 *
 * @param string The GET or POST parameter you want returned
 * @param mixed [optional] the default value for this parameter
 *
 * @return int The value of the parameter
 *
 */
function param_signed_integer($name) {
    $args = func_get_args();

    list ($value, $defaultused) = call_user_func_array('_param_retrieve', $args);

    if ($defaultused) {
        return $value;
    }

    $value = trim($value);

    if (preg_match('/^[+-]?[0-9]+$/', $value)) {
        return (int)$value;
    }
    else if ($value == '' && isset($args[1])) {
        return $args[1];
    }

    throw new ParameterException("The '$name' parameter is not an integer");
}

/**
 * This function returns a GET or POST parameter as an alpha string with optional
 * default.  If the default isn't specified and the parameter hasn't been sent,
 * a ParameterException exception is thrown. Likewise, if the parameter isn't a
 * valid alpha string, a ParameterException exception is thrown
 *
 * Valid characters are a-z and A-Z
 *
 * @param string The GET or POST parameter you want returned
 * @param mixed [optional] the default value for this parameter
 *
 * @return string The value of the parameter
 *
 */
function param_alpha($name) {
    $args = func_get_args();

    list ($value, $defaultused) = call_user_func_array('_param_retrieve', $args);


    if ($defaultused) {
        return $value;
    }

    $value = trim($value);

    if (preg_match('/^[a-zA-Z]+$/',$value)) {
        return $value;
    }

    throw new ParameterException("The '$name' parameter is not alphabetical only");
}

/**
 * This function returns a GET or POST parameter as an alphanumeric string with optional
 * default.  If the default isn't specified and the parameter hasn't been sent,
 * a ParameterException exception is thrown. Likewise, if the parameter isn't a
 * valid alpha string, a ParameterException exception is thrown
 *
 * Valid characters are a-z and A-Z and 0-9
 *
 * @param string The GET or POST parameter you want returned
 * @param mixed [optional] the default value for this parameter
 *
 * @return string The value of the parameter
 *
 */
function param_alphanum($name) {
    $args = func_get_args();

    list ($value, $defaultused) = call_user_func_array('_param_retrieve', $args);

    if ($defaultused) {
        return $value;
    }

    $value = trim($value);

    if (preg_match('/^[a-zA-Z0-9]+$/',$value)) {
        return $value;
    }

    throw new ParameterException("The '$name' parameter is not alphanumeric only");
}

/**
 * This function returns a GET or POST parameter as an alphanumeric string with optional
 * default.  If the default isn't specified and the parameter hasn't been sent,
 * a ParameterException exception is thrown. Likewise, if the parameter isn't a
 * valid alpha string, a ParameterException exception is thrown
 *
 * Valid characters are a-z and A-Z and 0-9 and _ and - and .
 *
 * @param string The GET or POST parameter you want returned
 * @param mixed [optional] the default value for this parameter
 *
 * @return string The value of the parameter
 *
 */
function param_alphanumext($name) {
    $args = func_get_args();

    list ($value, $defaultused) = call_user_func_array('_param_retrieve', $args);

    if ($defaultused) {
        return $value;
    }

    $value = trim($value);

    if (preg_match('/^[a-zA-Z0-9_.-]+$/',$value)) {
        return $value;
    }

    throw new ParameterException("The '$name' parameter contains invalid characters");
}

/**
 * This function returns a GET or POST parameter as an array of integers with optional
 * default.  If the default isn't specified and the parameter hasn't been sent,
 * a ParameterException exception is thrown. Likewise, if the parameter isn't a
 * valid integer list , a ParameterException exception is thrown.
 *
 * An integer list is integers separated by commas (with optional whitespace),
 * or just whitespace which indicates an empty list
 *
 * @param string The GET or POST parameter you want returned
 * @param mixed [optional] the default value for this parameter
 *
 * @return array The value of the parameter
 *
 */
function param_integer_list($name) {
    $args = func_get_args();

    list ($value, $defaultused) = call_user_func_array('_param_retrieve', $args);

    if ($defaultused) {
        return $value;
    }

    $value = trim($value);

    if ($value == '') {
        return array();
    }

    if (preg_match('/^(\d+(\s*,\s*\d+)*)$/',$value)) {
        return array_map('intval', explode(',', $value));
    }

    throw new ParameterException("The '$name' parameter is not an integer list");
}

/**
 * This function returns a GET or POST parameter as a boolean.
 *
 * @param string The GET or POST parameter you want returned
 *
 * @return bool The value of the parameter
 *
 */
function param_boolean($name) {

    list ($value) = _param_retrieve($name, false);

    if (!is_null($value)) {
        $value = trim($value);
    }

    if (empty($value) || $value == 'off' || $value == 'no' || $value == 'false') {
        return false;
    }
    else {
        return true;
    }
}

/**
 * NOTE: this function is only meant to be used by get_imagesize_parameters(),
 * which you should use in your scripts.
 *
 * It expects the parameter to be a string, in the form /\d+x\d+/ - e.g.
 * 200x150.
 *
 * @param string The GET or POST parameter you want checked
 * TODO: i18n for the error messages
 */
function param_imagesize($name) {
    $args = func_get_args();

    list ($value, $defaultused) = call_user_func_array('_param_retrieve', $args);

    if ($defaultused) {
        return $value;
    }

    $value = trim($value);

    if (!preg_match('/\d+x\d+/', $value)) {
        throw new ParameterException('Invalid size for image specified');
    }

    return $value;
}

/**
 * Works out what size a requested image should be, based on request parameters
 *
 * The result of this function can be passed to get_dataroot_image_path to
 * retrieve the filesystem path of the appropriate image
 */
function get_imagesize_parameters($sizeparam='size', $widthparam='width', $heightparam='height',
    $maxsizeparam='maxsize', $maxwidthparam='maxwidth', $maxheightparam='maxheight') {

    $size      = param_imagesize($sizeparam, '');
    $width     = param_integer($widthparam, 0);
    $height    = param_integer($heightparam, 0);
    $maxsize   = param_integer($maxsizeparam, 0);
    $maxwidth  = param_integer($maxwidthparam, 0);
    $maxheight = param_integer($maxheightparam, 0);

    return imagesize_data_to_internal_form($size, $width, $height, $maxsize, $maxwidth, $maxheight);
}

/**
 * Given sizing information, converts it to a form that get_dataroot_image_path
 * can use.
 *
 * @param mixed $size    either an array with 'w' and 'h' keys, or a string 'WxH'.
 *                       Image will be exactly this size
 * @param int $width     Width. Image will be scaled to be exactly this wide
 * @param int $height    Height. Image will be scaled to be exactly this high
 * @param int $maxsize   The longest side will be scaled to be this size
 * @param int $maxwidth  Use with maxheight - image dimensions will be made as
 *                       large as possible but not exceed either one
 * @param int $maxheight Use with maxwidth - image dimensions will be made as
 *                       large as possible but not exceed either one
 * @return mixed         A sizing parameter that can be used with get_dataroot_image_path()
 */
function imagesize_data_to_internal_form($size, $width, $height, $maxsize, $maxwidth, $maxheight) {
    $imagemaxwidth  = get_config('imagemaxwidth');
    $imagemaxheight = get_config('imagemaxheight');

    if ($size) {
        if (is_array($size)) {
            if (isset($size['w']) && isset($size['h'])) {
                $width  = $size['w'];
                $height = $size['h'];
            }
            else {
                throw new ParameterException('Size parameter is corrupt');
            }
        }
        else if (is_string($size)) {
            list($width, $height) = explode('x', $size);
        }
        else {
            throw new ParameterException('Size parameter is corrupt');
        }
        if ($width > get_config('imagemaxwidth') || $height > get_config('imagemaxheight')) {
            throw new ParameterException('Requested image size is too big');
        }
        if ($width < 16 || $height < 16) {
            throw new ParameterException('Requested image size is too small');
        }
        return array('w' => $width, 'h' => $height);
    }
    if ($maxsize) {
        if ($maxsize > $imagemaxwidth && $maxsize > $imagemaxheight) {
            throw new ParameterException('Requested image size is too big');
        }
        if ($maxsize < 16) {
            throw new ParameterException('Requested image size is too small');
        }
        return $maxsize;
    }
    if ($width) {
        if ($width > $imagemaxwidth) {
            throw new ParameterException('Requested image size is too big');
        }
        if ($width < 16) {
            throw new ParameterException('Requested image size is too small');
        }
        return array('w' => $width);
    }
    if ($height) {
        if ($height > $imagemaxheight) {
            throw new ParameterException('Requested image size is too big');
        }
        if ($height < 16) {
            throw new ParameterException('Requested image size is too small');
        }
        return array('h' => $height);
    }
    $max = array();
    if ($maxwidth) {
        if ($maxwidth > $imagemaxwidth) {
            throw new ParameterException('Requested image size is too big');
        }
        if ($maxwidth < 16) {
            throw new ParameterException('Requested image size is too small');
        }
        $max['maxw'] = $maxwidth;
    }
    if ($maxheight) {
        if ($maxheight > $imagemaxheight) {
            throw new ParameterException('Requested image size is too big');
        }
        if ($maxheight < 16) {
            throw new ParameterException('Requested image size is too small');
        }
        $max['maxh'] = $maxheight;
    }
    if (!empty($max)) {
        return $max;
    }
    return null;
}

/**
 * Gets a cookie, respecting the configured cookie prefix
 *
 * @param string $name The name of the cookie to get the value of
 * @return string      The value of the cookie, or null if the cookie does not
 *                     exist.
 */
function get_cookie($name) {
    $name = get_config('cookieprefix') . $name;
    return (isset($_COOKIE[$name])) ? $_COOKIE[$name] : null;
}

function get_cookies($prefix) {
    static $prefixes = array();
    if (!isset($prefixes[$prefix])) {
        $prefixes[$prefix] = array();
        $cprefix = get_config('cookieprefix') . $prefix;
        foreach ($_COOKIE as $k => $v) {
            if (strpos($k, $cprefix) === 0) {
                $prefixes[$prefix][substr($k, strlen($cprefix))] = $v;
            }
        }
    }
    return $prefixes[$prefix];
}

/**
 * Sets a cookie, respecting the configured cookie prefix
 *
 * @param string $name    The name of the cookie
 * @param string $value   The value for the cookie
 * @param int    $expires The unix timestamp of the time the cookie should expire
 */
function set_cookie($name, $value='', $expires=0, $access=false) {
    $name = get_config('cookieprefix') . $name;
    $url = parse_url(get_config('wwwroot'));
    if (!$domain = get_config('cookiedomain')) {
        $domain = $url['host'];
    }

    // If Cookie Consent is enabled with cc_necessary cookie set to 'yes'
    // or Cookie Consent is not enabled
    if (empty($_COOKIE['cc_necessary']) || (isset($_COOKIE['cc_necessary']) && $_COOKIE['cc_necessary'] == 'yes')) {
        setcookie($name, $value, $expires, $url['path'], $domain, is_https(), true);
    }

    if ($access) {  // View access cookies may be needed on this request
        $_COOKIE[$name] = $value;
    }
}

/**
 * Returns an assoc array of countrys suitable for use with the "select" form
 * element
 *
 * @return array Associative array of countrycodes => countrynames
 */
function getoptions_country() {
    static $countries;
    if (!empty($countries)) {
        return $countries;
    }
    $codes = array(
        'af',
        'ax',
        'al',
        'dz',
        'as',
        'ad',
        'ao',
        'ai',
        'aq',
        'ag',
        'ar',
        'am',
        'aw',
        'au',
        'at',
        'az',
        'bs',
        'bh',
        'bd',
        'bb',
        'by',
        'be',
        'bz',
        'bj',
        'bm',
        'bt',
        'bo',
        'ba',
        'bw',
        'bv',
        'br',
        'io',
        'bn',
        'bg',
        'bf',
        'bi',
        'kh',
        'cm',
        'ca',
        'cv',
        'ky',
        'cf',
        'td',
        'cl',
        'cn',
        'cx',
        'cc',
        'co',
        'km',
        'cg',
        'cd',
        'ck',
        'cr',
        'ci',
        'hr',
        'cu',
        'cy',
        'cz',
        'dk',
        'dj',
        'dm',
        'do',
        'ec',
        'eg',
        'sv',
        'gq',
        'er',
        'ee',
        'et',
        'fk',
        'fo',
        'fj',
        'fi',
        'fr',
        'gf',
        'pf',
        'tf',
        'ga',
        'gm',
        'ge',
        'de',
        'gh',
        'gi',
        'gr',
        'gl',
        'gd',
        'gp',
        'gu',
        'gt',
        'gg',
        'gn',
        'gw',
        'gy',
        'ht',
        'hm',
        'va',
        'hn',
        'hk',
        'hu',
        'is',
        'in',
        'id',
        'ir',
        'iq',
        'ie',
        'im',
        'il',
        'it',
        'jm',
        'jp',
        'je',
        'jo',
        'kz',
        'ke',
        'ki',
        'kp',
        'kr',
        'kw',
        'kg',
        'la',
        'lv',
        'lb',
        'ls',
        'lr',
        'ly',
        'li',
        'lt',
        'lu',
        'mo',
        'mk',
        'mg',
        'mw',
        'my',
        'mv',
        'ml',
        'mt',
        'mh',
        'mq',
        'mr',
        'mu',
        'yt',
        'mx',
        'fm',
        'md',
        'mc',
        'mn',
        'ms',
        'ma',
        'mz',
        'mm',
        'na',
        'nr',
        'np',
        'nl',
        'an',
        'nc',
        'nz',
        'ni',
        'ne',
        'ng',
        'nu',
        'nf',
        'mp',
        'no',
        'om',
        'pk',
        'pw',
        'ps',
        'pa',
        'pg',
        'py',
        'pe',
        'ph',
        'pn',
        'pl',
        'pt',
        'pr',
        'qa',
        're',
        'ro',
        'ru',
        'rw',
        'sh',
        'kn',
        'lc',
        'pm',
        'vc',
        'ws',
        'sm',
        'st',
        'sa',
        'sn',
        'cs',
        'sc',
        'sl',
        'sg',
        'sk',
        'si',
        'sb',
        'so',
        'za',
        'gs',
        'es',
        'lk',
        'sd',
        'sr',
        'sj',
        'sz',
        'se',
        'ch',
        'sy',
        'tw',
        'tj',
        'tz',
        'th',
        'tl',
        'tg',
        'tk',
        'to',
        'tt',
        'tn',
        'tr',
        'tm',
        'tc',
        'tv',
        'ug',
        'ua',
        'ae',
        'gb',
        'us',
        'um',
        'uy',
        'uz',
        'vu',
        've',
        'vn',
        'vg',
        'vi',
        'wf',
        'eh',
        'ye',
        'zm',
        'zw',
    );

    foreach ($codes as $c) {
        $countries[$c] = get_string("country.{$c}");
    };
    uasort($countries, 'strcoll');
    return $countries;
}

/**
 * Returns HTML string with help icon image that can be used on a page.
 *
 * @param string $plugintype
 * @param string $pluginname
 * @param string $form
 * @param string $element
 * @param string $page
 * @param string $section
 *
 * @return string HTML with help icon element
 */
function get_help_icon($plugintype, $pluginname, $form, $element, $page='', $section='') {
    global $THEME;

    return ' <span class="help"><a href="" onclick="'.
        hsc(
            'contextualHelp(' . json_encode($form) . ',' .
            json_encode($element) . ',' . json_encode($plugintype) . ',' .
            json_encode($pluginname) . ',' . json_encode($page) . ',' .
            json_encode($section)
            . ',this); return false;'
        ) . '"><img src="' . $THEME->get_url('images/help.png') . '" alt="' . get_string('Help') . '" title="' . get_string('Help') . '"></a></span>';
}

function pieform_get_help(Pieform $form, $element) {
    $plugintype = isset($element['helpplugintype']) ? $element['helpplugintype'] : $form->get_property('plugintype');
    $pluginname = isset($element['helppluginname']) ? $element['helppluginname'] : $form->get_property('pluginname');
    $formname = isset($element['helpformname']) ? $element['helpformname'] : $form->get_name();
    return get_help_icon($plugintype, $pluginname, $formname, $element['name']);
}

/**
 * Is this a page in the admin area?
 *
 * @return bool
 */
function in_admin_section() {
    return defined('ADMIN') || defined('INSTITUTIONALADMIN') || defined('STAFF') || defined('INSTITUTIONALSTAFF');
}

/**
 * Returns the entries in the standard admin menu
 *
 * See the function find_menu_children() in lib/web.php
 * for a description of the expected array structure.
 *
 * @return $adminnav a data structure containing the admin navigation
 */
function admin_nav() {
    $menu = array(
        'adminhome' => array(
            'path'   => 'adminhome',
            'url'    => 'admin/index.php',
            'title'  => get_string('adminhome', 'admin'),
            'weight' => 10,
            'accesskey' => 'a',
        ),
        'adminhome/home' => array(
            'path'   => 'adminhome/home',
            'url'    => 'admin/index.php',
            'title'  => get_string('overview'),
            'weight' => 10,
        ),
        'adminhome/registersite' => array(
            'path'   => 'adminhome/registersite',
            'url'    => 'admin/registersite.php',
            'title'  => get_string('register'),
            'weight' => 20,
        ),
        'adminhome/statistics' => array(
            'path'   => 'adminhome/statistics',
            'url'    => 'admin/statistics.php',
            'title'  => get_string('sitestatistics', 'admin'),
            'weight' => 30,
        ),
        'configsite' => array(
            'path'   => 'configsite',
            'url'    => 'admin/site/options.php',
            'title'  => get_string('configsite', 'admin'),
            'weight' => 20,
            'accesskey' => 'c',
        ),
        'configsite/siteoptions' => array(
            'path'   => 'configsite/siteoptions',
            'url'    => 'admin/site/options.php',
            'title'  => get_string('siteoptions', 'admin'),
            'weight' => 10,
        ),
        'configsite/sitepages' => array(
            'path'   => 'configsite/sitepages',
            'url'    => 'admin/site/pages.php',
            'title'  => get_string('staticpages', 'admin'),
            'weight' => 20
        ),
        'configsite/sitemenu' => array(
            'path'   => 'configsite/sitemenu',
            'url'    => 'admin/site/menu.php',
            'title'  => get_string('menus', 'admin'),
            'weight' => 30,
        ),
        'configsite/networking' => array(
            'path'   => 'configsite/networking',
            'url'    => 'admin/site/networking.php',
            'title'  => get_string('networking', 'admin'),
            'weight' => 40,
        ),
        'configsite/sitelicenses' => array(
            'path'   => 'configsite/sitelicenses',
            'url'    => 'admin/site/licenses.php',
            'title'  => get_string('sitelicenses', 'admin'),
            'weight' => 45,
        ),
        'configsite/siteviews' => array(
            'path'   => 'configsite/siteviews',
            'url'    => 'admin/site/views.php',
            'title'  => get_string('Views', 'view'),
            'weight' => 50,
        ),
        'configsite/collections' => array(
            'path'   => 'configsite/collections',
            'url'    => 'collection/index.php?institution=mahara',
            'title'  => get_string('Collections', 'collection'),
            'weight' => 60,
        ),
        'configsite/share' => array(
            'path'   => 'configsite/share',
            'url'    => 'admin/site/shareviews.php',
            'title'  => get_string('share', 'view'),
            'weight' => 70,
        ),
        'configsite/sitefiles' => array(
            'path'   => 'configsite/sitefiles',
            'url'    => 'artefact/file/sitefiles.php',
            'title'  => get_string('Files', 'artefact.file'),
            'weight' => 80,
        ),
        'configsite/cookieconsent' => array(
            'path'   => 'configsite/cookieconsent',
            'url'    => 'admin/site/cookieconsent.php',
            'title'  => get_string('cookieconsent', 'cookieconsent'),
            'weight' => 90,
        ),
        'configusers' => array(
            'path'   => 'configusers',
            'url'    => 'admin/users/search.php',
            'title'  => get_string('users'),
            'weight' => 30,
            'accesskey' => 'u',
        ),
        'configusers/usersearch' => array(
            'path'   => 'configusers/usersearch',
            'url'    => 'admin/users/search.php',
            'title'  => get_string('usersearch', 'admin'),
            'weight' => 10,
        ),
        'configusers/suspendedusers' => array(
            'path'   => 'configusers/suspendedusers',
            'url'    => 'admin/users/suspended.php',
            'title'  => get_string('suspendeduserstitle', 'admin'),
            'weight' => 15,
        ),
        'configusers/staffusers' => array(
            'path'   => 'configusers/staffusers',
            'url'    => 'admin/users/staff.php',
            'title'  => get_string('sitestaff', 'admin'),
            'weight' => 20,
        ),
        'configusers/adminusers' => array(
            'path'   => 'configusers/adminusers',
            'url'    => 'admin/users/admins.php',
            'title'  => get_string('siteadmins', 'admin'),
            'weight' => 30,
        ),
        'configusers/exportqueue' => array(
            'path'   => 'configusers/exportqueue',
            'url'    => 'admin/users/exportqueue.php',
            'title'  => get_string('exportqueue', 'admin'),
            'weight' => 35,
        ),
        'configusers/adduser' => array(
            'path'   => 'configusers/adduser',
            'url'    => 'admin/users/add.php',
            'title'  => get_string('adduser', 'admin'),
            'weight' => 40,
        ),
        'configusers/uploadcsv' => array(
            'path'   => 'configusers/uploadcsv',
            'url'    => 'admin/users/uploadcsv.php',
            'title'  => get_string('uploadcsv', 'admin'),
            'weight' => 50,
        ),
        'managegroups' => array(
            'path'   => 'managegroups',
            'url'    => 'admin/groups/groups.php',
            'title'  => get_string('groups', 'admin'),
            'accessibletitle' => get_string('administergroups', 'admin'),
            'weight' => 40,
            'accesskey' => 'g',
        ),
        'managegroups/groups' => array(
            'path'   => 'managegroups/groups',
            'url'    => 'admin/groups/groups.php',
            'title'  => get_string('administergroups', 'admin'),
            'weight' => 10,
        ),
        'managegroups/categories' => array(
            'path'   => 'managegroups/categories',
            'url'    => 'admin/groups/groupcategories.php',
            'title'  => get_string('groupcategories', 'admin'),
            'weight' => 20,
        ),
        'managegroups/archives' => array(
            'path'   => 'managegroups/archives',
            'url'    => 'admin/groups/archives.php',
            'title'  => get_string('archivedsubmissions', 'admin'),
            'weight' => 25,
        ),
        'managegroups/uploadcsv' => array(
            'path'   => 'managegroups/uploadcsv',
            'url'    => 'admin/groups/uploadcsv.php',
            'title'  => get_string('uploadgroupcsv', 'admin'),
            'weight' => 30,
        ),
        'managegroups/uploadmemberscsv' => array(
            'path'   => 'managegroups/uploadmemberscsv',
            'url'    => 'admin/groups/uploadmemberscsv.php',
            'title'  => get_string('uploadgroupmemberscsv', 'admin'),
            'weight' => 40,
        ),
        'manageinstitutions' => array(
            'path'   => 'manageinstitutions',
            'url'    => 'admin/users/institutions.php',
            'title'  => get_string('Institutions', 'admin'),
            'weight' => 50,
            'accesskey' => 'i',
        ),
        'manageinstitutions/institutions' => array(
            'path'   => 'manageinstitutions/institutions',
            'url'    => 'admin/users/institutions.php',
            'title'  => get_string('Institutions', 'admin'),
            'weight' => 10,
        ),
        'manageinstitutions/sitepages' => array(
            'path'   => 'manageinstitutions/sitepages',
            'url'    => 'admin/users/institutionpages.php',
            'title'  => get_string('staticpages', 'admin'),
            'weight' => 15
        ),
        'manageinstitutions/institutionusers' => array(
            'path'   => 'manageinstitutions/institutionusers',
            'url'    => 'admin/users/institutionusers.php',
            'title'  => get_string('Members', 'admin'),
            'weight' => 20,
        ),
        'manageinstitutions/institutionstaff' => array(
            'path'   => 'manageinstitutions/institutionstaff',
            'url'    => 'admin/users/institutionstaff.php',
            'title'  => get_string('Staff', 'admin'),
            'weight' => 30,
        ),
        'manageinstitutions/institutionadmins' => array(
            'path'   => 'manageinstitutions/institutionadmins',
            'url'    => 'admin/users/institutionadmins.php',
            'title'  => get_string('Admins', 'admin'),
            'weight' => 40,
        ),
        'manageinstitutions/adminnotifications' => array(
            'path'   => 'manageinstitutions/adminnotifications',
            'url'    => 'admin/users/notifications.php',
            'title'  => get_string('adminnotifications', 'admin'),
            'weight' => 50,
        ),
        'manageinstitutions/progressbar' => array(
            'path'   => 'manageinstitutions/progressbar',
            'url'    => 'admin/users/progressbar.php',
            'title'  => get_string('progressbar', 'admin'),
            'weight' => 55,
        ),
        'manageinstitutions/institutionviews' => array(
            'path'   => 'manageinstitutions/institutionviews',
            'url'    => 'view/institutionviews.php',
            'title'  => get_string('Views', 'view'),
            'weight' => 60,
        ),
        'manageinstitutions/institutioncollections' => array(
            'path'   => 'manageinstitutions/institutioncollections',
            'url'    => 'collection/index.php?institution=1',
            'title'  => get_string('Collections', 'collection'),
            'weight' => 70,
        ),
        'manageinstitutions/share' => array(
            'path'   => 'manageinstitutions/share',
            'url'    => 'view/institutionshare.php',
            'title'  => get_string('share', 'view'),
            'weight' => 80,
        ),
        'manageinstitutions/institutionfiles' => array(
            'path'   => 'manageinstitutions/institutionfiles',
            'url'    => 'artefact/file/institutionfiles.php',
            'title'  => get_string('Files', 'artefact.file'),
            'weight' => 90,
        ),
        'manageinstitutions/statistics' => array(
            'path'   => 'manageinstitutions/statistics',
            'url'    => 'admin/users/statistics.php',
            'title'  => get_string('statistics', 'admin'),
            'weight' => 100,
        ),
        'manageinstitutions/pendingregistrations' => array(
            'path'   => 'manageinstitutions/pendingregistrations',
            'url'    => 'admin/users/pendingregistrations.php',
            'title'  => get_string('pendingregistrations', 'admin'),
            'weight' => 110,
        ),
        'configextensions' => array(
            'path'   => 'configextensions',
            'url'    => 'admin/extensions/plugins.php',
            'title'  => get_string('Extensions', 'admin'),
            'weight' => 60,
            'accesskey' => 'e',
        ),
        'configextensions/pluginadmin' => array(
            'path'   => 'configextensions/pluginadmin',
            'url'    => 'admin/extensions/plugins.php',
            'title'  => get_string('pluginadmin', 'admin'),
            'weight' => 10,
        ),
        'configextensions/filters' => array(
            'path'   => 'configextensions/filters',
            'url'    => 'admin/extensions/filter.php',
            'title'  => get_string('htmlfilters', 'admin'),
            'weight' => 20,
        ),
        'configextensions/iframesites' => array(
            'path'   => 'configextensions/iframesites',
            'url'    => 'admin/extensions/iframesites.php',
            'title'  => get_string('allowediframesites', 'admin'),
            'weight' => 30,
        ),
        'configextensions/cleanurls' => array(
            'path'   => 'configextensions/cleanurls',
            'url'    => 'admin/extensions/cleanurls.php',
            'title'  => get_string('cleanurls', 'admin'),
            'weight' => 40,
        ),
    );

    // Add the menu items for skins, if that feature is enabled
    if (get_config('skins')) {
        $menu['configsite/siteskins'] = array(
           'path'   => 'configsite/siteskins',
           'url'    => 'admin/site/skins.php',
           'title'  => get_string('siteskinmenu', 'skin'),
           'weight' => 75,
        );
        $menu['configsite/sitefonts'] = array(
           'path'   => 'configsite/sitefonts',
           'url'    => 'admin/site/fonts.php',
           'title'  => get_string('sitefontsmenu', 'skin'),
           'weight' => 76,
        );
    }
    return $menu;
}

/**
 * Returns the entries in the standard institutional admin menu
 *
 * See the function find_menu_children() in lib/web.php
 * for a description of the expected array structure.
 *
 * @return $adminnav a data structure containing the admin navigation
 */
function institutional_admin_nav() {
    global $USER;

    $ret = array(
        'configusers' => array(
            'path'   => 'configusers',
            'url'    => 'admin/users/search.php',
            'title'  => get_string('users'),
            'weight' => 10,
            'accesskey' => 'u',
        ),
        'configusers/usersearch' => array(
            'path'   => 'configusers/usersearch',
            'url'    => 'admin/users/search.php',
            'title'  => get_string('usersearch', 'admin'),
            'weight' => 10,
        ),
        'configusers/suspendedusers' => array(
            'path'   => 'configusers/suspendedusers',
            'url'    => 'admin/users/suspended.php',
            'title'  => get_string('suspendeduserstitle', 'admin'),
            'weight' => 20,
        ),
        'configusers/exportqueue' => array(
            'path'   => 'configusers/exportqueue',
            'url'    => 'admin/users/exportqueue.php',
            'title'  => get_string('exportqueue', 'admin'),
            'weight' => 25,
        ),
        'configusers/adduser' => array(
            'path'   => 'configusers/adduser',
            'url'    => 'admin/users/add.php',
            'title'  => get_string('adduser', 'admin'),
            'weight' => 30,
        ),
        'configusers/uploadcsv' => array(
            'path'   => 'configusers/uploadcsv',
            'url'    => 'admin/users/uploadcsv.php',
            'title'  => get_string('uploadcsv', 'admin'),
            'weight' => 40,
        ),
        'managegroups' => array(
            'path'   => 'managegroups',
            'url'    => 'admin/groups/uploadcsv.php',
            'title'  => get_string('groups', 'admin'),
            'accessibletitle' => get_string('administergroups', 'admin'),
            'weight' => 20,
            'accesskey' => 'g',
        ),
        'managegroups/archives' => array(
            'path'   => 'managegroups/archives',
            'url'    => 'admin/groups/archives.php',
            'title'  => get_string('archivedsubmissions', 'admin'),
            'weight' => 5,
        ),
        'managegroups/uploadcsv' => array(
            'path'   => 'managegroups/uploadcsv',
            'url'    => 'admin/groups/uploadcsv.php',
            'title'  => get_string('uploadgroupcsv', 'admin'),
            'weight' => 10,
        ),
        'managegroups/uploadmemberscsv' => array(
            'path'   => 'managegroups/uploadmemberscsv',
            'url'    => 'admin/groups/uploadmemberscsv.php',
            'title'  => get_string('uploadgroupmemberscsv', 'admin'),
            'weight' => 20,
        ),
        'manageinstitutions' => array(
            'path'   => 'manageinstitutions',
            'url'    => 'admin/users/institutions.php',
            'title'  => get_string('Institutions', 'admin'),
            'weight' => 30,
            'accesskey' => 'i',
        ),
        'manageinstitutions/institutions' => array(
            'path'   => 'manageinstitutions/institutions',
            'url'    => 'admin/users/institutions.php',
            'title'  => get_string('settings'),
            'weight' => 10,
        ),
        'manageinstitutions/sitepages' => array(
            'path'   => 'manageinstitutions/sitepages',
            'url'    => 'admin/users/institutionpages.php',
            'title'  => get_string('staticpages', 'admin'),
            'weight' => 15
        ),
        'manageinstitutions/institutionusers' => array(
            'path'   => 'manageinstitutions/institutionusers',
            'url'    => 'admin/users/institutionusers.php',
            'title'  => get_string('Members', 'admin'),
            'weight' => 20,
        ),
        'manageinstitutions/institutionstaff' => array(
            'path'   => 'manageinstitutions/institutionstaff',
            'url'    => 'admin/users/institutionstaff.php',
            'title'  => get_string('Staff', 'admin'),
            'weight' => 30,
        ),
        'manageinstitutions/institutionadmins' => array(
            'path'   => 'manageinstitutions/institutionadmins',
            'url'    => 'admin/users/institutionadmins.php',
            'title'  => get_string('Admins', 'admin'),
            'weight' => 40,
        ),
        'manageinstitutions/adminnotifications' => array(
            'path'   => 'manageinstitutions/adminnotifications',
            'url'    => 'admin/users/notifications.php',
            'title'  => get_string('adminnotifications', 'admin'),
            'weight' => 50,
        ),
        'manageinstitutions/institutionviews' => array(
            'path'   => 'manageinstitutions/institutionviews',
            'url'    => 'view/institutionviews.php',
            'title'  => get_string('Views', 'view'),
            'weight' => 60,
        ),
        'manageinstitutions/institutioncollections' => array(
            'path'   => 'manageinstitutions/institutioncollections',
            'url'    => 'collection/index.php?institution=1',
            'title'  => get_string('Collections', 'collection'),
            'weight' => 70,
        ),
        'manageinstitutions/share' => array(
            'path'   => 'manageinstitutions/share',
            'url'    => 'view/institutionshare.php',
            'title'  => get_string('share', 'view'),
            'weight' => 80,
        ),
        'manageinstitutions/institutionfiles' => array(
            'path'   => 'manageinstitutions/institutionfiles',
            'url'    => 'artefact/file/institutionfiles.php',
            'title'  => get_string('Files', 'artefact.file'),
            'weight' => 90,
        ),
        'manageinstitutions/statistics' => array(
            'path'   => 'manageinstitutions/statistics',
            'url'    => 'admin/users/statistics.php',
            'title'  => get_string('statistics', 'admin'),
            'weight' => 100,
        ),
        'manageinstitutions/pendingregistrations' => array(
            'path'   => 'manageinstitutions/pendingregistrations',
            'url'    => 'admin/users/pendingregistrations.php',
            'title'  => get_string('pendingregistrations', 'admin'),
            'weight' => 110,
        ),
    );
    if ($USER->get('staff')) {
        $ret['adminhome'] = array(
            'path'   => 'adminhome',
            'url'    => 'admin/statistics.php',
            'title'  => get_string('site', 'admin'),
            'weight' => 40,
            'accesskey' => 'a',
        );
        $ret['adminhome/statistics'] = array(
            'path'   => 'adminhome/statistics',
            'url'    => 'admin/statistics.php',
            'title'  => get_string('statistics', 'admin'),
            'weight' => 10,
        );
    };

    return $ret;

}

/**
 * Returns the entries in the staff menu
 *
 * See the function find_menu_children() in lib/web.php
 * for a description of the expected array structure.
 *
 * @return a data structure containing the staff navigation
 */
function staff_nav() {
    return array(
        'usersearch' => array(
            'path'   => 'usersearch',
            'url'    => 'admin/users/search.php',
            'title'  => get_string('usersearch', 'admin'),
            'weight' => 10,
            'accesskey' => 'u',
        ),
        'statistics' => array(
            'path'   => 'statistics',
            'url'    => 'admin/statistics.php',
            'title'  => get_string('sitestatistics', 'admin'),
            'weight' => 20,
            'accesskey' => 's',
        ),
        'institutionalstatistics' => array(
            'path'   => 'statistics',
            'url'    => 'admin/users/statistics.php',
            'title'  => get_string('institutionstatistics', 'admin'),
            'weight' => 30,
            'accesskey' => 'i',
        ),
    );
}

/**
 * Returns the entries in the institutional staff menu
 *
 * See the function find_menu_children() in lib/web.php
 * for a description of the expected array structure.
 *
 * @return a data structure containing the institutional staff navigation
 */
function institutional_staff_nav() {
    return array(
        'usersearch' => array(
            'path'   => 'usersearch',
            'url'    => 'admin/users/search.php',
            'title'  => get_string('usersearch', 'admin'),
            'weight' => 10,
            'accesskey' => 'u',
        ),
        'institutionalstatistics' => array(
            'path'   => 'statistics',
            'url'    => 'admin/users/statistics.php',
            'title'  => get_string('institutionstatistics', 'admin'),
            'weight' => 20,
            'accesskey' => 'i',
        ),
    );
}

/**
 * Returns the entries in the standard user menu
 *
 * See the function find_menu_children() in lib/web.php
 * for a description of the expected array structure.
 *
 * @return $standardnav a data structure containing the standard navigation
 */
function mahara_standard_nav() {
    global $SESSION;

    $exportenabled = (plugins_installed('export') && !$SESSION->get('handheld_device')) ? TRUE : FALSE;

    $menu = array(
        'home' => array(
            'path' => '',
            'url' => '',
            'title' => get_string('dashboard', 'view'),
            'weight' => 10,
            'accesskey' => 'd',
        ),
        'content' => array(
            'path' => 'content',
            'url'  => 'artefact/internal/index.php', // @todo possibly do path aliasing and dispatch?
            'title' => get_string('Content'),
            'weight' => 20,
            'accesskey' => 'c',
        ),
        'myportfolio' => array(
            'path' => 'myportfolio',
            'url' => 'view/index.php',
            'title' => get_string('myportfolio'),
            'weight' => 30,
            'accesskey' => 'p',
        ),
        'myportfolio/views' => array(
            'path' => 'myportfolio/views',
            'url' => 'view/index.php',
            'title' => get_string('Views', 'view'),
            'weight' => 10,
        ),
        'myportfolio/share' => array(
            'path' => 'myportfolio/share',
            'url' => 'view/share.php',
            'title' => get_string('sharedbyme', 'view'),
            'weight' => 30,
        ),
        'myportfolio/sharedviews' => array(
            'path' => 'myportfolio/sharedviews',
            'url' => 'view/sharedviews.php',
            'title' => get_string('sharedwithme', 'view'),
            'weight' => 60,
        ),
        'myportfolio/export' => array(
            'path' => 'myportfolio/export',
            'url' => 'export/index.php',
            'title' => get_string('Export', 'export'),
            'weight' => 70,
            'ignore' => !$exportenabled,
        ),
        'myportfolio/import' => array(
            'path' => 'myportfolio/import',
            'url' => 'import/index.php',
            'title' => get_string('Import', 'import'),
            'weight' => 80,
        ),
        'myportfolio/collection' => array(
            'path' => 'myportfolio/collection',
            'url' => 'collection/index.php',
            'title' => get_string('Collections', 'collection'),
            'weight' => 20,
        ),
        'groups' => array(
            'path' => 'groups',
            'url' => 'group/mygroups.php',
            'title' => get_string('groups'),
            'weight' => 40,
            'accesskey' => 'g',
        ),
        'groups/mygroups' => array(
            'path' => 'groups/mygroups',
            'url' => 'group/mygroups.php',
            'title' => get_string('mygroups'),
            'weight' => 10,
        ),
        'groups/find' => array(
            'path' => 'groups/find',
            'url' => 'group/find.php',
            'title' => get_string('findgroups'),
            'weight' => 20,
        ),
        'groups/myfriends' => array(
            'path' => 'groups/myfriends',
            'url' => 'user/myfriends.php',
            'title' => get_string('myfriends'),
            'weight' => 30,
        ),
        'groups/findfriends' => array(
            'path' => 'groups/findfriends',
            'url' => 'user/find.php',
            'title' => get_string('findfriends'),
            'weight' => 40,
        ),
        'groups/institutionmembership' => array(
            'path' => 'groups/institutions',
            'url' => 'account/institutions.php',
            'title' => get_string('institutionmembership'),
            'weight' => 50,
        ),
    );

    if (can_use_skins()) {
        $menu['myportfolio/skins'] = array(
           'path' => 'myportfolio/skins',
           'url' => 'skin/index.php',
           'title' => get_string('myskins', 'skin'),
           'weight' => 65,
        );
    }
    return $menu;
}

/**
 * Builds a data structure representing the menu for Mahara.
 *
 * @return array
 */
function main_nav() {
    if (in_admin_section()) {
        global $USER, $SESSION;
        if ($USER->get('admin')) {
            $menu = admin_nav();
        }
        else if ($USER->is_institutional_admin()) {
            $menu = institutional_admin_nav();
        }
        else if ($USER->get('staff')) {
            $menu = staff_nav();
        }
        else {
            $menu = institutional_staff_nav();
        }
    }
    else {
        // Build the menu structure for the site
        $menu = mahara_standard_nav();
    }

    $menu = array_filter($menu, create_function('$a', 'return empty($a["ignore"]);'));

    // enable plugins to augment the menu structure
    foreach (array('artefact', 'interaction', 'module') as $plugintype) {
        if ($plugins = plugins_installed($plugintype)) {
            foreach ($plugins as &$plugin) {
                if (safe_require_plugin($plugintype, $plugin->name)) {
                    $plugin_menu = call_static_method(generate_class_name($plugintype,$plugin->name), 'menu_items');
                    $menu = array_merge($menu, $plugin_menu);
                }
            }
        }
    }

    // local_main_nav_update allows sites to customise the menu by munging the $menu array.
    if (function_exists('local_main_nav_update')) {
        local_main_nav_update($menu);
    }
    $menu_structure = find_menu_children($menu, '');
    return $menu_structure;
}

function right_nav() {
    global $USER, $THEME;

    safe_require('notification', 'internal');
    $unread = $USER->get('unread');

    $menu = array(
        'settings' => array(
            'path' => 'settings',
            'url' => 'account/index.php',
            'title' => get_string('settings'),
            'icon' => $THEME->get_url('images/settings.png'),
            'alt' => '',
            'weight' => 10,
        ),
        'inbox' => array(
            'path' => 'inbox',
            'url' => 'account/activity/index.php',
            'icon' => $THEME->get_url($unread ? 'images/newmail.png' : 'images/message.png'),
            'alt' => get_string('inbox'),
            'count' => $unread,
            'countclass' => 'unreadmessagecount',
            'weight' => 20,
        ),
        'settings/account' => array(
            'path' => 'settings/account',
            'url' => 'account/index.php',
            'title' => get_config('dropdownmenu') ? get_string('general') : get_string('account'),
            'weight' => 10,
        ),
        'settings/notifications' => array(
            'path' => 'settings/notifications',
            'url' => 'account/activity/preferences/index.php',
            'title' => get_string('notifications'),
            'weight' => 30,
        ),
    );

    // enable plugins to augment the menu structure
    foreach (array('artefact', 'interaction', 'module') as $plugintype) {
        if ($plugins = plugins_installed($plugintype)) {
            foreach ($plugins as &$plugin) {
                safe_require($plugintype, $plugin->name);
                $plugin_nav_menu = call_static_method(generate_class_name($plugintype, $plugin->name),
                    'right_nav_menu_items');
                $menu = array_merge($menu, $plugin_nav_menu);
            }
        }
    }
    // local_right_nav_update allows sites to customise the menu by munging the $menu array.
    if (function_exists('local_right_nav_update')) {
        local_right_nav_update($menu);
    }
    $menu_structure = find_menu_children($menu, '');
    return $menu_structure;
}


function footer_menu($all=false) {
    $wwwroot = get_config('wwwroot');

    $menu = array(
        'termsandconditions' => array(
            'url'   => $wwwroot . 'terms.php',
            'title' => get_string('termsandconditions'),
        ),
        'privacystatement' => array(
            'url'   => $wwwroot . 'privacy.php',
            'title' => get_string('privacystatement'),
        ),
        'about' => array(
            'url'   => $wwwroot . 'about.php',
            'title' => get_string('about'),
        ),
        'contactus' => array(
            'url'   => $wwwroot . 'contact.php',
            'title' => get_string('contactus'),
        ),
    );
    if ($all) {
        return $menu;
    }
    if ($enabled = get_config('footerlinks')) {
        $enabled = unserialize($enabled);
        foreach ($menu as $k => $v) {
            if (!in_array($k, $enabled)) {
                unset($menu[$k]);
            }
        }
    }
    if ($customlinks = get_config('footercustomlinks')) {
        $customlinks = unserialize($customlinks);
        foreach ($customlinks as $k => $v) {
            if (!empty($menu[$k])) {
                $menu[$k]['url'] = $v;
            }
        }
    }
    return $menu;
}


/**
 * Given a menu structure and a path, returns a data structure representing all
 * of the child menu items of the path, and removes those items from the menu
 * structure
 *
 * The menu structure should be an array. Each item in the array should be
 * a sub-array representing one of the nodes in the menu.
 *
 * The keys of each menu node are as follows:
 *   path: Where the link sits in the menu. E.g. 'myporfolio/myplugin'
 *   url:  The URL to the page, relative to wwwroot. E.g. 'artefact/myplugin/'
 *   title: Translated text to use for the text of the link. E.g. get_string('myplugin', 'artefact.myplugin')
 *   weight: Where in the menu the item should be inserted. Larger number are to the right.
 *
 * Used by main_nav()
 */
function find_menu_children(&$menu, $path) {
    global $SELECTEDSUBNAV;
    $result = array();
    if (!$menu) {
        return array();
    }

    foreach ($menu as $key => $item) {
        $item['selected'] = defined('MENUITEM')
            && ($item['path'] == MENUITEM
                || ($item['path'] . '/' == substr(MENUITEM, 0, strlen($item['path'])+1)));
        if (
            ($path == '' && $item['path'] == '') ||
            ($item['path'] != '' && substr($item['path'], 0, strlen($path)) == $path && !preg_match('%/%', substr($item['path'], strlen($path) + 1)))) {
            $result[] = $item;
            unset($menu[$key]);
        }
    }

    if ($menu) {
        foreach ($result as &$item) {
            $item['submenu'] = find_menu_children($menu, $item['path']);
            if ($item['selected']) {
                $SELECTEDSUBNAV = $item['submenu'];
            }
        }
    }

    uasort($result, 'menu_sort_items');

    return $result;
}

/**
 * Comparison function for sorting menu items
 */
function menu_sort_items(&$a, &$b) {
    !isset($a['weight']) && $a['weight'] = 0;
    !isset($b['weight']) && $b['weight'] = 0;
    return $a['weight'] > $b['weight'];
}

/**
 * Site-level sidebar menu (list of links)
 * There is no admin files table yet so just get the urls.
 * @return $menu a data structure containing the site menu
 */
function site_menu() {
    global $USER;
    $menu = array();
    if ($menuitems = get_records_array('site_menu','public',(int) !$USER->is_logged_in(),'displayorder')) {
        foreach ($menuitems as $i) {
            if ($i->url) {
                $safeurl = sanitize_url($i->url);
                if ($safeurl != '') {
                    $menu[] = array('name' => $i->title,
                                    'link' => $safeurl);
                }
            }
            else if ($i->file) {
                $menu[] = array('name' => $i->title,
                                'link' => get_config('wwwroot') . 'artefact/file/download.php?file=' . $i->file);
            }
        }
    }
    return $menu;
}

/**
 * Returns the list of site content pages
 * @return array of names
 */
function site_content_pages() {
    return array('about', 'home', 'loggedouthome', 'privacy', 'termsandconditions');
}

function get_site_page_content($pagename) {
    global $USER;
    $institution = $USER->sitepages_institutionname_by_theme($pagename);

    // try to get the content for this institution and if it fails try to get default site information
    // first check to see if the db upgrade has been run so the institution column exists
    if (get_config('version') >= '2014010801') {
        if ($pagedata = get_record('site_content', 'name', $pagename, 'institution', $institution)) {
            return $pagedata->content;
        }
        else if ($defaultpagedata = get_record('site_content', 'name', $pagename, 'institution', 'mahara')) {
            return $defaultpagedata->content;
        }
        return get_string('sitecontentnotfound', 'mahara', get_string($pagename, $institution));
    }
    else {
        if ($pagedata = get_record('site_content', 'name', $pagename)) {
            return $pagedata->content;
        }
    }
    return get_string('sitecontentnotfound', 'mahara', get_string($pagename));
}



/**
 * Redirects the browser to a new location. The path to redirect to can take
 * two forms:
 *
 * - http[something]: will redirect the user to that exact URL
 * - /[something]: will redirect to WWWROOT/[something]
 *
 * Any other form is illegal and will cause an error.
 *
 * @param string $location The location to redirect the user to. Defaults to
 *                         the application home page.
 */
function redirect($location='/') {
    $file = $line = null;
    if (headers_sent($file, $line)) {
        throw new SystemException("Headers already sent when redirect() was called (output started in $file on line $line");
    }

    if (substr($location, 0, 4) != 'http') {
        if (substr($location, 0, 1) != '/') {
            throw new SystemException('redirect() should be called with either'
                . ' /[something] for local redirects or http[something] for'
                . ' absolute redirects');
        }

        $location = get_config('wwwroot') . substr($location, 1);
    }

    header('HTTP/1.1 303 See Other');
    header('Location:' . $location);
    perf_to_log();
    exit;
}

/**
 * Returns a string, HTML escaped
 *
 * @param string $text The text to escape
 * @return string      The text, HTML escaped
 */
function hsc ($text) {
    return htmlspecialchars($text, ENT_COMPAT, 'UTF-8');
}

/**
 * Builds the pieform for the search field in the page header
 */
function header_search_form() {
    $plugin = get_config('searchplugin');
    safe_require('search', $plugin);
    return call_static_method(
        generate_class_name('search', $plugin),
        'header_search_form'
    );
}


/**
 * Returns the name of the current script, WITH the querystring portion.
 * this function is necessary because PHP_SELF and REQUEST_URI and SCRIPT_NAME
 * return different things depending on a lot of things like your OS, Web
 * server, and the way PHP is compiled (ie. as a CGI, module, ISAPI, etc.)
 * <b>NOTE:</b> This function returns false if the global variables needed are not set.
 *
 * @return string
 */
function get_script_path() {

    if (!empty($_SERVER['REQUEST_URI'])) {
        return $_SERVER['REQUEST_URI'];

    } else if (!empty($_SERVER['PHP_SELF'])) {
        if (!empty($_SERVER['QUERY_STRING'])) {
            return $_SERVER['PHP_SELF'] .'?'. $_SERVER['QUERY_STRING'];
        }
        return $_SERVER['PHP_SELF'];

    } else if (!empty($_SERVER['SCRIPT_NAME'])) {
        if (!empty($_SERVER['QUERY_STRING'])) {
            return $_SERVER['SCRIPT_NAME'] .'?'. $_SERVER['QUERY_STRING'];
        }
        return $_SERVER['SCRIPT_NAME'];

    } else if (!empty($_SERVER['URL'])) {     // May help IIS (not well tested)
        if (!empty($_SERVER['QUERY_STRING'])) {
            return $_SERVER['URL'] .'?'. $_SERVER['QUERY_STRING'];
        }
        return $_SERVER['URL'];

    }
    else {
        log_warn('Warning: Could not find any of these web server variables: $REQUEST_URI, $PHP_SELF, $SCRIPT_NAME or $URL');
        return false;
    }
}

/**
 * Get the requested servername in preference to the host in the configured
 * wwwroot.  Usually the same unless some parts of the site are at subdomains.
 *
 * @return string
 */
function get_requested_host_name() {
    global $CFG;

    $hostname = false;
    if (false === $hostname && !empty($_SERVER['SERVER_NAME'])) {
        $hostname = $_SERVER['SERVER_NAME'];
    }
    if (false === $hostname && !empty($_ENV['SERVER_NAME'])) {
        $hostname = $_ENV['SERVER_NAME'];
    }
    if (false === $hostname && !empty($_SERVER['HTTP_HOST'])) {
        $hostname = $_SERVER['HTTP_HOST'];
    }
    if (false === $hostname && !empty($_ENV['HTTP_HOST'])) {
        $hostname = $_ENV['HTTP_HOST'];
    }
    if (false === $hostname && !empty($CFG->wwwroot)) {
        $url = parse_url($CFG->wwwroot);
        if (!empty($url['host'])) {
            $hostname = $url['host'];
        }
    }

    if (false === $hostname) {
        log_warn('Warning: could not find the name of this server!');
        return false;
    }
    else {
        $hostname = strtolower($hostname);
        // Because the hostname can be user provided data (from the HTTP request), we
        // should whitelist it.
        if (!preg_match(
                '/^([a-z0-9]|[a-z0-9][a-z0-9-]*[a-z0-9])(\\.([a-z0-9]|[a-z0-9][a-z0-9-]*[a-z0-9]))*$/',
                $hostname
            )
        ) {
            log_warn('Warning: invalid hostname found in get_requested_host_name.');
            return false;
        }

        return $hostname;
    }
}

/**
 * Like {@link get_script_path()} but returns a full URL
 * @see get_script_path()
 * @return string
 */
function get_full_script_path() {

    global $CFG;

    if (!empty($CFG->wwwroot)) {
        $url = parse_url($CFG->wwwroot);
    }

    if (!$hostname = get_requested_host_name()) {
        return false;
    }

    if (!empty($url['port'])) {
        $hostname .= ':'.$url['port'];
    } else if (!empty($_SERVER['SERVER_PORT'])) {
        if ($_SERVER['SERVER_PORT'] != 80 && $_SERVER['SERVER_PORT'] != 443) {
            $hostname .= ':'.$_SERVER['SERVER_PORT'];
        }
    }

    if (isset($_SERVER['HTTPS'])) {
        $protocol = ($_SERVER['HTTPS'] == 'on') ? 'https://' : 'http://';
    }
    else if (isset($_SERVER['SERVER_PORT'])) { # Apache2 does not export $_SERVER['HTTPS']
        $protocol = ($_SERVER['SERVER_PORT'] == '443') ? 'https://' : 'http://';
    }
    else {
        $protocol = 'http://';
    }

    $url_prefix = $protocol.$hostname;
    return $url_prefix . get_script_path();
}

/**
 * Like {@link get_script_path()} but returns a URI relative to WWWROOT
 * @see get_script_path()
 * @return string
 */
function get_relative_script_path() {
    $maharadir = get_mahara_install_subdirectory();
    // $maharadir always has a trailing '/'
    return substr(get_script_path(), strlen($maharadir) - 1);
}

/**
 * Get query string from url
 *
 * Takes in a URL and returns the querystring portion
 * or returns $_SERVER['QUERY_STRING']) if set
 *
 * @param string $url the url which may have a query string attached
 * @return string
 */
function get_querystring($url = null) {

    if (!empty($url) && $commapos = strpos($url, '?')) {
        return substr($url, $commapos + 1);
    }
    else if (!empty($_SERVER['QUERY_STRING'])) {
        return $_SERVER['QUERY_STRING'];
    }
    else {
        return '';
    }
}

/**
 * Remove query string from url
 *
 * Takes in a URL and returns it without the querystring portion
 *
 * @param string $url the url which may have a query string attached
 * @return string
 */
function strip_querystring($url) {

    if ($commapos = strpos($url, '?')) {
        return substr($url, 0, $commapos);
    }
    else {
        return $url;
    }
}

function has_page_help() {
    $pt = defined('SECTION_PLUGINTYPE') ? SECTION_PLUGINTYPE : null;
    $pn = defined('SECTION_PLUGINNAME') ? SECTION_PLUGINNAME : null;
    $sp = defined('SECTION_PAGE')       ? SECTION_PAGE       : null;

    if (empty($pt) || ($pt != 'core' && empty($pn))) {
        // we can't have a plugin type but no plugin name
        return false;
    }

    if (in_array($pt, plugin_types())) {
        $pagehelp = get_config('docroot') . $pt . '/' . $pn . '/lang/en.utf8/help/pages/' . $sp . '.html';
    }
    else {
        $pagehelp = get_config('docroot') . 'lang/en.utf8/help/pages/' . $pn . '/' . $sp . '.html';
    }

    if (is_readable($pagehelp)) {
        return array($sp, get_help_icon($pt, $pn, '', '', $sp));
    }
    return false;
}

//
// Cleaning/formatting functions
//

/**
 * Converts bbcodes in the given text to HTML. Also auto-links URLs.
 *
 * @param string $text The text to parse
 * @return string
 */
function parse_bbcode($text) {
    require_once('stringparser_bbcode/stringparser_bbcode.class.php');

    $bbcode = new StringParser_BBCode();
    $bbcode->setGlobalCaseSensitive(false);
    $bbcode->setRootParagraphHandling(true);

    // Convert all newlines to a common form
    $bbcode->addFilter(STRINGPARSER_FILTER_PRE, create_function('$a', 'return preg_replace("/\015\012|015\012/", "\n", $a);'));

    $bbcode->addParser(array('block', 'inline'), 'format_whitespace');
    $bbcode->addParser(array('block', 'inline'), 'autolink_text');

    // The bbcodes themselves
    $bbcode->addCode('b', 'simple_replace', null, array ('start_tag' => '<strong>', 'end_tag' => '</strong>'),
                          'inline', array('listitem', 'block', 'inline', 'link'), array());
    $bbcode->addCode ('i', 'simple_replace', null, array ('start_tag' => '<em>', 'end_tag' => '</em>'),
                          'inline', array('listitem', 'block', 'inline', 'link'), array());
    $bbcode->addCode ('url', 'usecontent?', 'bbcode_url', array('usecontent_param' => 'default'),
                          'link', array('listitem', 'block', 'inline'), array('link'));
    $bbcode->addCode ('img', 'usecontent', 'bbcode_img', array(),
                      'image', array ('listitem', 'block', 'inline', 'link'), array());

    $text = $bbcode->parse($text);
    return $text;
}

/**
 * Given some plain text, adds the appropriate HTML to it to make it appear in
 * an HTML document with the same formatting
 *
 * This includes escaping entities, replacing newlines etc. It is not
 * particularly intelligent about paragraphs, it just adds <br> to every
 * newline
 *
 * @param string $text The text to format
 * @return string
 */
function format_whitespace($text) {
    $text = str_replace("\r\n", "\n", $text);
    $text = str_replace("\r", "\n", $text);
    $text = hsc($text);
    $text = str_replace('  ', '&nbsp; ', $text);
    $text = str_replace('  ', ' &nbsp;', $text);
    $text = str_replace("\n", "<br>\n", $text);
    return $text;
}

/**
 * Get the list of custom filters to be used in HTMLPurifier
 * @return array
 */
function get_htmlpurifier_custom_filters() {
    $customfilters = array();
    if (get_config('filters')) {
        foreach (unserialize(get_config('filters')) as $filter) {
            // These filters are no longer necessary and have been removed
            $builtinfilters = array('YouTube', 'TeacherTube', 'SlideShare', 'SciVee', 'GoogleVideo');

            if (!in_array($filter->file, $builtinfilters)) {
                include_once(get_config('libroot') . 'htmlpurifiercustom/' . $filter->file . '.php');
                $classname = 'HTMLPurifier_Filter_' . $filter->file;
                if (class_exists($classname)) {
                    $customfilters[] = new $classname();
                }
            }
        }
    }
    return $customfilters;
}

/**
 * Given raw html (eg typed in by a user), this function cleans it up
 * and removes any nasty tags that could mess up pages.
 *
 * @param string $text The text to be cleaned
 * @param boolean $xhtml HTML 4.01 will be used for all of mahara, except very special cases (eg leap2a exports)
 * @return string The cleaned up text
 */
function clean_html($text, $xhtml=false) {
    require_once('htmlpurifier/HTMLPurifier.auto.php');
    $config = HTMLPurifier_Config::createDefault();
    $config->set('Cache.SerializerPermissions', get_config('directorypermissions'));
    $config->set('Cache.SerializerPath', get_config('dataroot') . 'htmlpurifier');
    if (empty($xhtml)) {
        $config->set('HTML.Doctype', 'HTML 4.01 Transitional');
    }
    else {
        $config->set('HTML.Doctype', 'XHTML 1.0 Transitional');
    }
    $config->set('AutoFormat.Linkify', true);

    if (get_config('disableexternalresources')) {
        $config->set('URI.DisableExternalResources', true);
    }

    // Permit embedding contents from other sites
    $config->set('HTML.SafeEmbed', true);
    $config->set('HTML.SafeObject', true);
    $config->set('Output.FlashCompat', true);
    if ($iframeregexp = get_config('iframeregexp')) {
        $config->set('HTML.SafeIframe', true);
        $config->set('URI.SafeIframeRegexp', $iframeregexp);
    }

    // Allow namespaced IDs
    // see http://htmlpurifier.org/docs/enduser-id.html
    $config->set('Attr.EnableID', true);
    $config->set('Attr.IDPrefix', 'user_');

    $customfilters = get_htmlpurifier_custom_filters();
    if (!empty($customfilters)) {
        $config->set('Filter.Custom', $customfilters);
    }

    // These settings help identify the configuration definition. If the
    // definition (the $def object below) is changed (e.g. new method calls
    // made on it), the DefinitionRev needs to be increased. See
    // http://htmlpurifier.org/live/configdoc/plain.html#HTML.DefinitionID
    $config->set('HTML.DefinitionID', 'Mahara customisations to default config');
    $config->set('HTML.DefinitionRev', 1);

    if ($def = $config->maybeGetRawHTMLDefinition()) {
        $def->addAttribute('a', 'target', 'Enum#_blank,_self,_target,_top');
        // allow the tags used with image map to be rendered
        // see http://htmlpurifier.org/phorum/read.php?3,5046
        $def->addAttribute('img', 'usemap', 'CDATA');
        // Add map tag
        $map = $def->addElement(
            'map',
            'Block',
            'Flow',
            'Common',
            array(
                'name' => 'CDATA',
            )
        );
        $map->excludes = array('map' => true);

        // Add area tag
        $area = $def->addElement(
            'area',
            'Block',
            'Empty',
            'Common',
            array(
                'name' => 'CDATA',
                'alt' => 'Text',
                'coords' => 'CDATA',
                'accesskey' => 'Character',
                'nohref' => new HTMLPurifier_AttrDef_Enum(array('nohref')),
                'href' => 'URI',
                'shape' => new HTMLPurifier_AttrDef_Enum(array('rect','circle','poly','default')),
                'tabindex' => 'Number',
                'target' => new HTMLPurifier_AttrDef_Enum(array('_blank','_self','_target','_top'))
            )
        );
        $area->excludes = array('area' => true);
    }

    $purifier = new HTMLPurifier($config);
    return $purifier->purify($text);
}

/**
 * Like clean_html(), but for CSS!
 *
 * Much of the code in this function was taken from the sample code in this post:
 * http://stackoverflow.com/questions/3241616/sanitize-user-defined-css-in-php#5209050
 *
 * @param string $input_css
 * @param string $preserve_css, if turns on the CSS comments will be preserved
 * @return string The cleaned CSS
 */
function clean_css($input_css, $preserve_css=false) {
    require_once('htmlpurifier/HTMLPurifier.auto.php');
    require_once('csstidy/class.csstidy.php');

    // Create a new configuration object
    $config = HTMLPurifier_Config::createDefault();
    $config->set('Cache.SerializerPermissions', get_config('directorypermissions'));
    $config->set('Cache.SerializerPath', get_config('dataroot') . 'htmlpurifier');

    $config->set('Filter.ExtractStyleBlocks', true);
    $config->set('Filter.ExtractStyleBlocks.PreserveCSS', $preserve_css);

    if (get_config('disableexternalresources')) {
        $config->set('URI.DisableExternalResources', true);
    }

    $customfilters = get_htmlpurifier_custom_filters();
    if (!empty($customfilters)) {
        $config->set('Filter.Custom', $customfilters);
    }

    $config->set('HTML.DefinitionID', 'Mahara customisations to default config for CSS');
    $config->set('HTML.DefinitionRev', 1);

    // Create a new purifier instance
    $purifier = new HTMLPurifier($config);

    // Wrap our CSS in style tags and pass to purifier.
    // we're not actually interested in the html response though
    $html = $purifier->purify('<style>'.$input_css.'</style>');

    // The "style" blocks are stored seperately
    $output_css = $purifier->context->get('StyleBlocks');

    // Get the first style block
    if (is_array($output_css) && count($output_css)) {
        return $output_css[0];
    }
    return '';
}


/**
 * Given HTML, converts and formats it as text
 *
 * @param string $html The html to be formatted
 * @return string The formatted text
 */
function html2text($html, $fragment=true) {
    require_once('htmltotext/htmltotext.php');
    if ($fragment) {
        $html = '<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"/></head>' . $html;
    }
    $h2t = new HtmltoText($html, get_config('wwwroot'));
    return $h2t->text();
}

/**
 * Given some text, locates URLs in it and converts them to HTML
 *
 * @param string $text The text to locate URLs in
 * @return string
 *
 * {@internal{Note, it's perhaps unreasonably expected that the input to this
 * function is HTML escaped already. Especially because it's expected that
 * there are no <a href="...">s in there. This works for now because the bbcode
 * parser breaks things out into tokens, but this function might need reworking
 * to be more useful in other places.}}
 */
function autolink_text($text) {
    $text = preg_replace(
        '#(^|.)(https?://\S+)#me',
        "_autolink_text_helper('$2', '$1')",
        $text
    );
    return $text;
}

/**
 * Helps autolink_text by providing the HTML to link up URLs found.
 *
 * Intelligently decides what parts of the matched URL should be linked up, to
 * get around issues where URLs are surrounded by brackets or have trailing
 * punctuation on them
 *
 * @param string $potentialurl     The URL to check. It should already have been run through hsc()
 * @param string $leadingcharacter The character (if any) before the URL. Used
 *                                 to check for URLs surrounded by brackets
 */
function _autolink_text_helper($potentialurl, $leadingcharacter) {
    static $brackets = array('(' => ')', '{' => '}', '[' => ']', "'" => "'");
    $trailingcharacter = substr($potentialurl, -1);
    $startofurl = substr($potentialurl, 0, -1);

    // Attempt to intelligently handle several annoyances that happen with URL
    // auto linking. We don't want to link up brackets if the URL is enclosed
    // in them. We also don't want to link up punctuation after URLs
    if (in_array($leadingcharacter, array_keys($brackets)) &&
        in_array($trailingcharacter, $brackets)) {
        // The URL was surrounded by brackets
        return $leadingcharacter . '<a href="' . $startofurl . '">' . $startofurl . '</a>' . $trailingcharacter;
    }
    else {
        foreach($brackets as $opener => $closer) {
            if ($trailingcharacter == $closer &&
                false === strpos($startofurl, $opener)) {
                // The URL ended in a bracket and didn't contain one
                // Note that we can't just use this clause without using the clause
                // about URLs surrounded by brackets, because otherwise we won't catch
                // URLs with balanced brackets in them like http://url/?(foo)&bar=1
                return $leadingcharacter . '<a href="' . $startofurl . '">' . $startofurl . '</a>' . $trailingcharacter;
            }
        }

        // Check for trailing punctuation
        if (in_array($trailingcharacter, array('.', ',', '!', '?'))) {
            return $leadingcharacter . '<a href="' . $startofurl . '">' . $startofurl . '</a>' . $trailingcharacter;
        }
        else {
            return $leadingcharacter . '<a href="' . $potentialurl . '">' . $potentialurl . '</a>';
        }
    }

    // Execution should never get here
    return $potentialurl;
}

/**
 * Callback for StringParser_BBCode to handle [url] and [link] bbcode
 */
function bbcode_url($action, $attributes, $content, $params, $node_object) {
    if (!isset ($attributes['default'])) {
        $url = $content;
        $text = hsc($content);
    }
    else {
        $url = $attributes['default'];
        $text = $content;
    }
    if ($action == 'validate') {
        $valid_protos = array('http://', 'https://', 'ftp://');
        foreach ($valid_protos as $proto) {
            if (substr($url, 0, strlen($proto)) == $proto) {
                return true;
            }
        }
        return false;
    }
    return '<a href="' . hsc($url) . '">' . $text . '</a>';
}

/**
 * Callback for StringParser_BBCode to handle [img] bbcode
 */
function bbcode_img($action, $attributes, $content, $params, $node_object) {
    if ($action == 'validate') {
        $valid_protos = array('http://', 'https://');
        foreach ($valid_protos as $proto) {
            if (substr($content, 0, strlen($proto)) == $proto) {
                return true;
            }
        }
        return false;
    }
    return '<img src="' . hsc($content) . '" alt="">';
}

/**
 * Returns a message that can be used as help text for BBCode
 *
 * @return string
 */
function bbcode_format_post_message() {
    return get_string('formatpostbbcode', 'mahara', '<a href="" onclick="contextualHelp(\'\',\'\',\'core\',\'site\',null,\'bbcode\',this); return false;">', '</a>');
}


/**
 * Displays purified html on a page with an explanatory message.
 *
 * @param string $html     The purified html.
 * @param string $filename The filename to serve the file as
 * @param array $params    Parameters previously passed to serve_file
 */
function display_cleaned_html($html, $filename, $params) {
    $smarty = smarty_core();
    $smarty->assign('params', $params);
    if ($params['owner']) {
        $smarty->assign('htmlremovedmessage', get_string('htmlremovedmessage', 'artefact.file', hsc($filename), profile_url((int) $params['owner']), hsc(display_name($params['owner']))));
    }
    else {
        $smarty->assign('htmlremovedmessage', get_string('htmlremovedmessagenoowner', 'artefact.file', hsc($filename)));
    }
    $smarty->assign('content', $html);
    $smarty->display('cleanedhtml.tpl');
    exit;
}

/**
 * Takes a string and a length, and ensures that the string is no longer than
 * this length, by putting '...' in it somewhere.
 *
 * It also strips all tags except <br> and <p>.
 *
 * This version is appropriate for use on HTML. See str_shorten_text() for use
 * on text strings.
 *
 * @param string $str    The string to shorten
 * @param int $maxlen    The maximum length the new string should be (default 100)
 * @param bool $truncate If true, cut the string at the end rather than in the middle (default false)
 * @param bool $newlines If false, cut off after the first newline (default true)
 * @return string
 */
function str_shorten_html($str, $maxlen=100, $truncate=false, $newlines=true) {
    if (empty($str)) {
        return $str;
    }
    if (!$newlines) {
        $nextbreak = strpos($str, '<p', 1);
        if ($nextbreak !== false) {
            $str = substr($str, 0, $nextbreak);
        }
        $nextbreak = strpos($str, '<br', 1);
        if ($nextbreak !== false) {
            $str = substr($str, 0, $nextbreak);
        }
    }
    // so newlines don't disappear but ignore the first <p>
    $str = $str[0] . str_replace('<p', "\n\n<p", substr($str, 1));
    $str = str_replace('<br', "\n<br", $str);

    $str = strip_tags($str);
    $str = html_entity_decode($str, ENT_COMPAT, 'UTF-8'); // no things like &nbsp; only take up one character
    // take the first $length chars, then up to the first space (max length $length + $extra chars)

    if (function_exists('mb_substr')) {
        if ($truncate && mb_strlen($str, 'UTF-8') > $maxlen) {
            $str = mb_substr($str, 0, $maxlen-3, 'UTF-8') . '...';
        }
        if (mb_strlen($str, 'UTF-8') > $maxlen) {
            $str = mb_substr($str, 0, floor($maxlen / 2) - 1, 'UTF-8') . '...' . mb_substr($str, -(floor($maxlen / 2) - 2), mb_strlen($str, 'UTF-8'), 'UTF-8');
        }
    }
    else {
        if ($truncate && strlen($str) > $maxlen) {
            $str = substr($str, 0, $maxlen-3) . '...';
        }
        if (strlen($str) > $maxlen) {
            $str = substr($str, 0, floor($maxlen / 2) - 1) . '...' . substr($str, -(floor($maxlen / 2) - 2), strlen($str));
        }
    }
    $str = nl2br(hsc($str));
    // this should be ok, because the string gets checked before going into the database
    $str = str_replace('&amp;', '&', $str);
    return $str;
}

/**
 * Takes a string and a length, and ensures that the string is no longer than
 * this length, by putting '...' in it somewhere.
 *
 * This version is appropriate for use on plain text. See str_shorten_html()
 * for use on HTML strings.
 *
 * @param string $str    The string to shorten
 * @param int $maxlen    The maximum length the new string should be (default 100)
 * @param bool $truncate If true, cut the string at the end rather than in the middle (default false)
 * @return string
 */
function str_shorten_text($str, $maxlen=100, $truncate=false) {
    if (function_exists('mb_substr')) {
        if (mb_strlen($str, 'UTF-8') > $maxlen) {
            if ($truncate) {
                return mb_substr($str, 0, $maxlen - 3, 'UTF-8') . '...';
            }
            return mb_substr($str, 0, floor($maxlen / 2) - 1, 'UTF-8') . '...' . mb_substr($str, -(floor($maxlen / 2) - 2), mb_strlen($str, 'UTF-8'), 'UTF-8');
        }
        return $str;
    }
    if (strlen($str) > $maxlen) {
        if ($truncate) {
            return substr($str, 0, $maxlen - 3) . '...';
        }
        return substr($str, 0, floor($maxlen / 2) - 1) . '...' . substr($str, -(floor($maxlen / 2) - 2));
    }
    return $str;
}

/**
 * Builds pagination links for HTML display.
 *
 * The pagination is quite configurable, but at the same time gives a consistent
 * look and feel to all pagination.
 *
 * This function takes one array that contains the options to configure the
 * pagination. Required options include:
 *
 * - url: The base URL to use for all links (it should not contain special characters)
 * - count: The total number of results to paginate for
 * - setlimit: toggle variable for enabling/disabling limit dropbox, default value = false
 * - limit: How many to show per page
 * - offset: At which result to start showing results
 *
 * Optional options include:
 *
 * - id: The ID of the div enclosing the pagination
 * - class: The class of the div enclosing the pagination
 * - offsetname: The name of the offset parameter in the url
 * - firsttext: The text to use for the 'first page' link
 * - previoustext: The text to use for the 'previous page' link
 * - nexttext: The text to use for the 'next page' link
 * - lasttext: The text to use for the 'last page' link
 * - numbersincludefirstlast: Whether the page numbering should include links
 *   for the first and last pages
 * - numbersincludeprevnext: The number of pagelinks, adjacent the the current page,
 *   to include per side
 * - jumplinks: The maximum number of page jump links to have between first- and current-,
     and current- and last page
 * - resultcounttextsingular: The text to use for 'result'
 * - resultcounttextplural: The text to use for 'results'
 * - limittext: The text to use for the limitoption, e.g. "Max items per page" or "Page size"
 *
 * Optional options to support javascript pagination include:
 *
 * - datatable: The ID of the table whose TBODY's rows will be replaced with the
 *   resulting rows
 * - jsonscript: The script to make a json request to in order to retrieve
 *   both the new rows and the new pagination. See js/artefactchooser.json.php
 *   for an example. Note that the paginator javascript library is NOT
 *   automatically included just because you call this function, so make sure
 *   that your smarty() call hooks it in.
 *
 * @param array $params Options for the pagination
 */
function build_pagination($params) {
    $limitoptions = array(1, 10, 20, 50, 100, 500);
    // Bail if the required attributes are not present
    $required = array('url', 'count', 'limit', 'offset');
    foreach ($required as $option) {
        if (!isset($params[$option])) {
            throw new ParameterException('You must supply option "' . $option . '" to build_pagination');
        }
    }

    if (isset($params['setlimit']) && $params['setlimit']) {
        if (!in_array($params['limit'], $limitoptions)) {
            $params['limit'] = 10;
        }
        if (!isset($params['limittext'])) {
            $params['limittext'] = get_string('maxitemsperpage');
        }
    }
    else {
        $params['setlimit'] = false;
    }

    // Work out default values for parameters
    if (!isset($params['id'])) {
        $params['id'] = substr(md5(microtime()), 0, 4);
    }

    $params['offsetname'] = (isset($params['offsetname'])) ? $params['offsetname'] : 'offset';
    if (isset($params['forceoffset']) && !is_null($params['forceoffset'])) {
        $params['offset'] = (int) $params['forceoffset'];
    }
    else if (!isset($params['offset'])) {
        $params['offset'] = param_integer($params['offsetname'], 0);
    }

    // Correct for odd offsets
    if ($params['limit']) {
        $params['offset'] -= $params['offset'] % $params['limit'];
    }

    $params['firsttext'] = (isset($params['firsttext'])) ? $params['firsttext'] : get_string('first');
    $params['previoustext'] = (isset($params['previoustext'])) ? $params['previoustext'] : get_string('previous');
    $params['nexttext']  = (isset($params['nexttext']))  ? $params['nexttext'] : get_string('next');
    $params['lasttext']  = (isset($params['lasttext']))  ? $params['lasttext'] : get_string('last');
    $params['resultcounttextsingular'] = (isset($params['resultcounttextsingular'])) ? $params['resultcounttextsingular'] : get_string('result');
    $params['resultcounttextplural'] = (isset($params['resultcounttextplural'])) ? $params['resultcounttextplural'] : get_string('results');

    if (!isset($params['numbersincludefirstlast'])) {
        $params['numbersincludefirstlast'] = true;
    }
    if (!isset($params['numbersincludeprevnext'])) {
        $params['numbersincludeprevnext'] = 1;
    }
    else {
        $params['numbersincludeprevnext'] = (int) $params['numbersincludeprevnext'];
    }

    if (!isset($params['extradata'])) {
        $params['extradata'] = null;
    }

    // Begin building the output
    $output = '<div id="' . $params['id'] . '" class="pagination';
    if (isset($params['class'])) {
        $output .= ' ' . hsc($params['class']);
    }
    $output .= '">';

    if ($params['limit'] && ($params['limit'] < $params['count'])) {
        $pages = ceil($params['count'] / $params['limit']);
        $page = $params['offset'] / $params['limit'];

        $last = $pages - 1;
        if (!empty($params['lastpage'])) {
            $page = $last;
        }
        $prev = max(0, $page - 1);
        $next = min($last, $page + 1);

        // Build a list of what pagenumbers will be put between the previous/next links
        $pagenumbers = array();

        // First page
        if ($params['numbersincludefirstlast']) {
            $pagenumbers[] = 0;
        }

        $maxjumplinks = isset($params['jumplinks']) ? (int) $params['jumplinks'] : 0;

        // Jump pages between first page and current page
        $betweencount = $page;
        $jumplinks = $pages ? round($maxjumplinks * ($betweencount / $pages)) : 0;
        $jumpcount = $jumplinks ? round($betweencount / ($jumplinks + 1)) : 0;
        $gapcount = 1;
        if ($jumpcount > 1) {
            for ($bc = 1; $bc < $betweencount; $bc++) {
                if ($gapcount > $jumpcount) {
                    $pagenumbers[] = $bc;
                    $gapcount = 1;
                }
                $gapcount++;
            }
        }

        // Current page with adjacent prev and next pages
        if ($params['numbersincludeprevnext'] > 0) {
            for ($i = 1; $i <= $params['numbersincludeprevnext']; $i++) {
                $prevlink = $page - $i;
                if ($prevlink < 0) {
                    break;
                }
                $pagenumbers[] = $prevlink;
            }
            unset($prevlink);
        }
        $pagenumbers[] = $page;
        if ($params['numbersincludeprevnext'] > 0) {
            for ($i = 1; $i <= $params['numbersincludeprevnext']; $i++) {
                $nextlink = $page + $i;
                if ($nextlink > $last) {
                    break;
                }
                $pagenumbers[] = $nextlink;
            }
        }

        // Jump pages between current and last
        $betweencount = $pages - $page;
        $jumplinks = $pages ? round($maxjumplinks * ($betweencount / $pages)) : 0;
        $jumpcount = $jumplinks ? round($betweencount / ($jumplinks + 1)) : 0;
        $gapcount = 1;
        if ($jumpcount > 1) {
            for ($bc = $page; $bc < $last; $bc++) {
                if ($gapcount > $jumpcount) {
                    $pagenumbers[] = $bc;
                    $gapcount = 1;
                }
                $gapcount++;
            }
        }

        // Last page
        if ($params['numbersincludefirstlast']) {
            $pagenumbers[] = $last;
        }
        $pagenumbers = array_unique($pagenumbers);
        sort($pagenumbers);

        // Build the first/previous links
        $isfirst = $page == 0;
        $output .= build_pagination_pagelink('first', $params['url'], $params['setlimit'], $params['limit'], 0, '&laquo; ' . $params['firsttext'], get_string('firstpage'), $isfirst, $params['offsetname']);
        $output .= build_pagination_pagelink('prev', $params['url'], $params['setlimit'], $params['limit'], $params['limit'] * $prev, '&larr; ' . $params['previoustext'], get_string('prevpage'), $isfirst, $params['offsetname']);

        // Build the pagenumbers in the middle
        foreach ($pagenumbers as $k => $i) {
            if ($k != 0 && $prevpagenum < $i - 1) {
                $output .= '…';
            }
            if ($i == $page) {
                $output .= '<span class="selected">' . ($i + 1) . '</span>';
            }
            else {
                $output .= build_pagination_pagelink('', $params['url'], $params['setlimit'], $params['limit'],
                    $params['limit'] * $i, $i + 1, '', false, $params['offsetname']);
            }
            $prevpagenum = $i;
        }

        // Build the next/last links
        $islast = $page == $last;
        $output .= build_pagination_pagelink('next', $params['url'], $params['setlimit'], $params['limit'], $params['limit'] * $next,
            $params['nexttext'] . ' &rarr;', get_string('nextpage'), $islast, $params['offsetname']);
        $output .= build_pagination_pagelink('last', $params['url'], $params['setlimit'], $params['limit'], $params['limit'] * $last,
            $params['lasttext'] . ' &raquo;', get_string('lastpage'), $islast, $params['offsetname']);
    }

    // Build limitoptions dropbox if results are more than 10 (minimum dropbox pagination)
    if ($params['setlimit'] && $params['count'] > 10) {
        $strlimitoptions = array();
        $limit = $params['limit'];
        for ($i = 0; $i < count($limitoptions); $i++) {
            if ($limit == $limitoptions[$i]) {
                $strlimitoptions[] = "<option value = '$limit' selected='selected'> $limit </option>";
            }
            else {
                $strlimitoptions[] = "<option value = '$limitoptions[$i]'> $limitoptions[$i] </option>";
            }
        }
        $output .= '<form class="pagination" action="' . hsc($params['url']) . '" method="POST">
            <label for="setlimitselect" class="pagination"> ' . $params['limittext'] . ' </label>' .
            '<select id="setlimitselect" class="pagination" name="limit"> '.
                join(' ', $strlimitoptions) .
            '</select>
            <input class="currentoffset" type="hidden" name="' . $params['offsetname'] . '" value="' . $params['offset'] . '"/>
            <input class="pagination js-hidden" type="submit" name="submit" value="' . get_string('change') . '"/>
        </form>';
    }
    // if $params['count'] is less than 10 add the setlimitselect as a hidden field so that elasticsearch js can access it
    else if ($params['setlimit']) {
        $output .= '<input type="hidden" id="setlimitselect" name="limit" value="' . $params['limit'] . '">';
    }

    // Work out what javascript we need for the paginator
    $js = '';
    $id = json_encode($params['id']);
    if (isset($params['jsonscript']) && isset($params['datatable'])) {
        $paginator_js = hsc(get_config('wwwroot') . 'js/paginator.js');
        $datatable    = json_encode($params['datatable']);
        if (!empty($params['searchresultsheading'])) {
            $heading  = json_encode($params['searchresultsheading']);
        }
        else {
            $heading  = 'null';
        }
        $jsonscript   = json_encode($params['jsonscript']);
        $extradata    = json_encode($params['extradata']);
        $js .= "new Paginator($id, $datatable, $heading, $jsonscript, $extradata);";
    }
    else {
        $js .= "new Paginator($id, null, null, null, null);";
    }

    // Output the count of results
    $resultsstr = ($params['count'] == 1) ? $params['resultcounttextsingular'] : $params['resultcounttextplural'];
    $output .= '<div class="results">' . $params['count'] . ' ' . $resultsstr . '</div>';

    // Close the container div
    $output .= '</div>';

    return array('html' => $output, 'javascript' => $js);

}

/**
 * Used by build_pagination to build individual links. Shouldn't be used
 * elsewhere.
 */
function build_pagination_pagelink($class, $url, $setlimit, $limit, $offset, $text, $title, $disabled=false, $offsetname='offset') {
    $return = '<span class="pagination';
    $return .= ($class) ? " $class" : '';

    $url = (false === strpos($url, '?')) ? $url . '?' : $url . '&';
    $url .= "$offsetname=$offset";
    if ($setlimit) {
        $url .= '&' . "setlimit=$setlimit";
        $url .= '&' . "limit=$limit";
    }

    if ($disabled) {
        $return .= ' disabled">' . $text . '</span>';
    }
    else {
        $return .= '">'
            . '<a href="' . hsc($url) . '" title="' . $title
            . '">' . $text . '</a></span>';
    }

    return $return;
}

function mahara_http_request($config, $quiet=false) {
    $ch = curl_init();

    // standard curl_setopt stuff; configs passed to the function can override these
    curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if (!ini_get('open_basedir')) {
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    }

    curl_setopt_array($ch, $config);

    if($proxy_address = get_config('proxyaddress')) {
        curl_setopt($ch, CURLOPT_PROXY, $proxy_address);

        if($proxy_authmodel = get_config('proxyauthmodel') && $proxy_credentials = get_config('proxyauthcredentials')) {
            // todo: actually do something with $proxy_authmodel
            curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy_credentials);
        }
    }

    if (strpos($config[CURLOPT_URL], 'https://') === 0) {
        if ($cainfo = get_config('cacertinfo')) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            curl_setopt($ch, CURLOPT_CAINFO, $cainfo);
        }
    }

    $result = new StdClass();
    $result->data = curl_exec($ch);
    $result->info = curl_getinfo($ch);
    $result->error = curl_error($ch);
    $result->errno = curl_errno($ch);

    if ($result->errno) {
        if ($quiet) {
            // When doing something unimportant like fetching rss feeds, some errors should not pollute the logs.
            $dontcare = array(
                CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT, CURLE_PARTIAL_FILE, CURLE_OPERATION_TIMEOUTED,
                CURLE_GOT_NOTHING,
            );
            $quiet = in_array($result->errno, $dontcare);
        }
        if (!$quiet) {
            log_warn('Curl error: ' . $result->errno . ': ' . $result->error);
        }
    }

    curl_close($ch);

    return $result;
}

/**
 * Fetch the true full url from a shorthand url by getting
 * the location from the redirected header information.
 *
 * @param   string $url    The shorthand url eg https://goo.gl/maps/pZTiA
 * @param   bool   $quiet  To record errors in the logs
 *
 * @return  object  $result Contains the short url, full url, the headers, and any errors
 */
function mahara_shorturl_request($url, $quiet=false) {
    $ch = curl_init($url);

    // standard curl_setopt stuff; configs passed to the function can override these
    curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 1);

    $result = new StdClass();
    $result->shorturl = $url;
    $result->data = curl_exec($ch);
    $result->error = curl_error($ch);
    $result->errno = curl_errno($ch);

    if ($result->errno) {
        if ($quiet) {
            // When doing something unimportant like fetching rss feeds, some errors should not pollute the logs.
            $dontcare = array(
                CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT, CURLE_PARTIAL_FILE, CURLE_OPERATION_TIMEOUTED,
                CURLE_GOT_NOTHING,
            );
            $quiet = in_array($result->errno, $dontcare);
        }
        if (!$quiet) {
            log_warn('Curl error: ' . $result->errno . ': ' . $result->error);
        }
    }

    curl_close($ch);

    $fields = explode("\r\n", preg_replace('/\x0D\x0A[\x09\x20]+/', ' ', $result->data)); // Parse information
    $result->fullurl = false;
    foreach ($fields as $field) {
        if (strpos($field, 'Location') !== false) {
            $result->fullurl = str_replace('Location: ', '', $field);
        }
    }

    return $result;
}

/**
 * Returns a language select form
 *
 * @return string      HTML of language select form
 */
function language_select_form() {
    global $SESSION;

    $languageform = '';
    $languages = get_languages();

    if (count($languages) > 1) {

        $languages = array_merge(array('default' => get_string('sitedefault', 'admin') . ' (' .
            get_string_from_language(get_config('lang'), 'thislanguage') . ')'), $languages);

        require_once('pieforms/pieform.php');
        $languageform = pieform(array(
            'name'                => 'languageselect',
            'renderer'            => 'oneline',
            'validate'            => false,
            'presubmitcallback'   => '',
            'elements'            => array(
                'lang' => array(
                    'type' => 'select',
                    'title' => get_string('language') . ':',
                    'options' => $languages,
                    'defaultvalue' => $SESSION->get('lang') ? $SESSION->get('lang') : 'default',
                ),
                'changelang' => array(
                    'type' => 'submit',
                    'value' => get_string('change'),
                )
            )
        ));
    }
    return $languageform;
}

/**
 * Sanitises URIs provided before displaying them to the world, as well as checking they are of
 * appropriate protocols and complete.
 *
 *  @return string    Either an empty string if supplied URI fails tests, or the supplied URI verbatim
 */
function sanitize_url($url) {

    $parsedurl = parse_url($url);
    if (!isset($parsedurl['scheme'])) {
        if (isset($parsedurl['path'])) {
            $url = get_config('wwwroot') . ltrim($url, '/');
            $parsedurl = parse_url($url);
        }
        else {
            return '';
        }
    }
    if (!in_array($parsedurl['scheme'], array('https', 'http', 'ftp', 'mailto'))) {
        return '';
    }
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return '';
    }
    return $url;
}

/**
 * Sanitises header text per rfc5322
 *
 *  @return string    A string with undesired characters filtered out
 */
function clean_email_headers($headertext) {

    $decoloned = str_replace(':', '', $headertext);
    $filtered = filter_var($decoloned, FILTER_SANITIZE_STRING, array(FILTER_FLAG_STRIP_LOW, FILTER_FLAG_STRIP_HIGH));
    return substr($filtered, 0, 100);

}

function favicon_display_url($host) {
    $url = sprintf(get_config('favicondisplay'), $host);
    if (is_https()) {
        $url = str_replace('http://', 'https://', $url);
    }
    return $url;
}

/**
 * Given an arbitrary string, generate a string containing only the allowed
 * characters for use in a clean url.
 *
 * @param string  $dirty string containing invalid or undesirable url characters
 * @param mixed   $default an integer id or clean string to use as the default
 * @param integer $minlength
 * @param integer $maxlength
 *
 * @return string    A string of the specified length containing only valid characters
 */
function generate_urlid($dirty, $default, $minlength=3, $maxlength=100) {
    $charset = get_config('cleanurlcharset');
    if ($charset != 'ASCII' || preg_match('/[^\x00-\x7F]/', $dirty)) {
        $dirty = iconv('UTF-8', $charset . '//TRANSLIT', $dirty);
    }
    $dirty = preg_replace(get_config('cleanurlinvalidcharacters'), '-', $dirty);
    $s = substr(strtolower(trim($dirty, '-')), 0, $maxlength);

    // If the string is too short, use the default, padding with zeros if necessary
    $length = strlen($s);
    if ($length < $minlength) {
        if (is_numeric($default)) {
            $format = '%0' . $minlength . 'd';
            $default = sprintf($format, (int) $default);
        }
        if ($length > 0) {
            $default .= '-' . $s;
        }
        $s = $default;
    }
    return $s;
}

/**
 * Sorts an array by one of the value fields
 *
 * @param array  $data an array of arrays
 * @param string $sort a key field value of second tier array
 * @param string $direction the direction of the sort
 */
function sorttablebycolumn(&$data, $sort, $direction) {
    global $sortvalue;
    $sortvalue = $sort;
    if ($direction == 'desc') {
        usort($data, 'sorttablearraydesc');    }
    else {
        usort($data, 'sorttablearrayasc');
    }

}

/**
 * Compare function for sorttablebycolumn()
 * Sorts ascending.
 */
function sorttablearrayasc($a, $b) {
    global $sortvalue;
    if (is_string($a[$sortvalue])) {
        return strcmp(strtolower($a[$sortvalue]), strtolower($b[$sortvalue]));
    }
    return ($a[$sortvalue] < $b[$sortvalue]) ? -1 : 1;
}

/**
 * Compare function for sorttablebycolumn()
 * Sorts descending
 */
function sorttablearraydesc($a, $b) {
    global $sortvalue;
    if (is_string($a[$sortvalue])) {
        return strcmp(strtolower($b[$sortvalue]), strtolower($a[$sortvalue]));
    }
    return ($b[$sortvalue] < $a[$sortvalue]) ? -1 : 1;
}

/**
 * Add version number to url
 * This allows auto refreshing of cache when upgrading
 * or updating Mahara to different version
 */
function append_version_number($urls) {
    if (is_array($urls)) {
        $formattedurls = array();
        foreach ($urls as $url) {
            if (preg_match('/\?/',$url)) {
                $url .= '&v=' . get_config('cacheversion');
            }
            else {
                $url .= '?v=' . get_config('cacheversion');
            }
            $formattedurls[] = $url;
        }
        return $formattedurls;
    }
    if (preg_match('/\?/',$urls)) {
        $urls .= '&v=' . get_config('cacheversion');
    }
    else {
        $urls .= '?v=' . get_config('cacheversion');
    }
    return $urls;
}

/**
 * Escape a string so that it's suitable to be used as a CSS quote-enclosed string
 * If it's single-quoted, preface single-quotes with a backslash. If it's double-quoted,
 * preface double-quotes with a backslash. Preface non-escaping backslashes with a
 * backslash. Remove newlines.
 * @param string $string The string to escape
 * @param bool $singlequote True to escape for single quotes, False to escape for double
 * @return string
 */
function escape_css_string($string, $singlequote=true) {
    if ($singlequote) {
        $delim = "'";
    }
    else {
        $delim = '"';
    }
    return str_replace(
        array('\\', "\n", $delim),
        array('\\\\', '', "\\$delim"),
        $string
    );
}

/**
 * Indicates whether a particular user can use skins on their pages or not. This is in
 * lib/web.php instead of lib/skin.php so that we can use it while generating the main nav.

 * @param int $userid The Id of the user to check. Null checks the current user.
 * @param bool $managesiteskin = true if admins try to manage the site skin
 * @param bool $issiteview = true if admins try to use skins for site views
 * @return bool
 */
function can_use_skins($userid = null, $managesiteskin=false, $issiteview=false) {
    global $USER;

    if (!get_config('skins')) {
        return false;
    }

    // Site Admins can access site skin
    if ($USER->get('admin') && ($managesiteskin || $issiteview)) {
        return true;
    }

    // A user can belong to multiple institutions. If any of their institutions allow it, then
    // let them use skins!
    $results = get_configs_user_institutions('skins', $userid);
    foreach ($results as $r) {
        if ($r) {
            return true;
        }
    }
    return false;
}
