<?php
/**
 *
 * @package    mahara
 * @subpackage core
 * @author     Catalyst IT Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL version 3 or later
 * @copyright  For copyright information on Mahara, please see the README file distributed with this software.
 *
 */

define('INTERNAL', 1);
define('PUBLIC', 1);
define('SECTION_PLUGINTYPE', 'core');
define('SECTION_PLUGINNAME', 'core');
define('SECTION_PAGE', 'artefact');

require(dirname(dirname(__FILE__)) . '/init.php');
require_once(get_config('libroot') . 'view.php');
require_once(get_config('libroot') . 'objectionable.php');
safe_require('artefact', 'comment');

$artefactid = param_integer('artefact');
$viewid     = param_integer('view');
$blockid    = param_integer('block', null);

$view = new View($viewid);
if (!can_view_view($view)) {
    throw new AccessDeniedException();
}

require_once(get_config('docroot') . 'artefact/lib.php');
$artefact = artefact_instance_from_id($artefactid);

if (!$artefact->in_view_list()) {
    throw new AccessDeniedException(get_string('artefactonlyviewableinview', 'error'));
}

// Build the path to the artefact through its parents.
$artefactpath = array();
$ancestors = $artefact->get_item_ancestors();
$artefactok = false;

if (artefact_in_view($artefact, $viewid)) {
    $artefactok = true;
    $baseobject = $artefact;
}

if (!empty($ancestors)) {
    foreach ($ancestors as $ancestor) {
        $pathitem = artefact_instance_from_id($ancestor);
        if (artefact_in_view($pathitem, $viewid)) {
            $artefactpath[] = array(
                'url'   => get_config('wwwroot') . 'artefact/artefact.php?artefact=' . $pathitem->get('id') . '&view=' . $viewid,
                'title' => $pathitem->display_title(),
            );
            $artefactok = true;
            $baseobject = $pathitem;
        }
    }
}

if ($artefactok == false) {
    throw new AccessDeniedException(get_string('artefactnotinview', 'error', $artefactid, $viewid));
}

// Feedback list pagination requires limit/offset params
$limit       = param_integer('limit', 10);
$offset      = param_integer('offset', 0);
$showcomment = param_integer('showcomment', null);

if ($artefact && $viewid && $blockid) {
    // use the block instance title rather than the artefact title if it exists
    $title = artefact_title_for_view_and_block($artefact, $viewid, $blockid);
}
else {
    $title = $artefact->display_title();
}

// Create the "make feedback private form" now if it's been submitted
if (param_variable('make_public_submit', null)) {
    pieform(ArtefactTypeComment::make_public_form(param_integer('comment')));
}
else if (param_variable('delete_comment_submit_x', null)) {
    pieform(ArtefactTypeComment::delete_comment_form(param_integer('comment')));
}

define('TITLE', $title . ' ' . get_string('in', 'view') . ' ' . $view->get('title'));

// Render the artefact
$options = array(
    'viewid' => $viewid,
    'details' => true,
    'metadata' => 1,
);

if ($artefact->get('artefacttype') == 'folder') {
    // Get folder block sort order - returns the first instance of folder on view unless $blockid is set.
    // TODO: get the clicking on a subfolder to carry the block id as well - that way we can get exact configdata.
    if ($block = get_records_sql_array('SELECT block FROM {view_artefact} WHERE view = ? AND artefact = ?', array($viewid, $baseobject->get('id')))) {
        require_once(get_config('docroot') . 'blocktype/lib.php');
        $key = 0;
        // If we have a $blockid, then we will use block's configdata.
        if ($blockid) {
            foreach ($block as $k => $b) {
                if ($b->block == $blockid) {
                    $key = $k;
                    break;
                }
            }
        }
        $bi = new BlockInstance($block[$key]->block);
        $configdata = $bi->get('configdata');
        if (!empty($configdata['sortorder'])) {
            $options['sortorder'] = $configdata['sortorder'];
        }
        if (!empty($configdata['folderdownloadzip'])) {
            $options['folderdownloadzip'] = true;
        }
    }
}
$rendered = $artefact->render_self($options);
$content = '';
if (!empty($rendered['javascript'])) {
    $content = '<script type="text/javascript">' . $rendered['javascript'] . '</script>';
}
$content .= $rendered['html'];

// Feedback
$feedback = ArtefactTypeComment::get_comments($limit, $offset, $showcomment, $view, $artefact);

$inlinejavascript = <<<EOF
var viewid = {$viewid};
var artefactid = {$artefactid};
addLoadEvent(function () {
    paginator = {$feedback->pagination_js}
});
EOF;

$javascript = array('paginator', 'viewmenu', 'expandable');
$extrastylesheets = array('style/views.css');

if ($artefact->get('allowcomments')) {
    $addfeedbackform = pieform(ArtefactTypeComment::add_comment_form(false, $artefact->get('approvecomments')));
    $extrastylesheets[] = 'style/jquery.rating.css';
    $javascript[] = 'jquery.rating';
}
$objectionform = pieform(objection_form());
if ($notrudeform = notrude_form()) {
    $notrudeform = pieform($notrudeform);
}

$viewbeingwatched = (int)record_exists('usr_watchlist_view', 'usr', $USER->get('id'), 'view', $viewid);

// Set up theme
$viewtheme = $view->get('theme');
if ($viewtheme && $THEME->basename != $viewtheme) {
    $THEME = new Theme($viewtheme);
}
$headers = array('<link rel="stylesheet" type="text/css" href="' . append_version_number(get_config('wwwroot') . 'theme/views.css' ) . '">',);

// Set up skin, if the page has one
$owner    = $view->get('owner');
$viewskin = $view->get('skin');
if ($viewskin && get_config('skins') && can_use_skins($owner) && (!isset($THEME->skins) || $THEME->skins !== false)) {
    $skin = array('skinid' => $viewskin, 'viewid' => $view->get('id'));
    $skindata = unserialize(get_field('skin', 'viewskin', 'id', $viewskin));
}
else {
    $skin = false;
}

$hasfeed = false;
$feedlink = '';
// add a link to the ATOM feed in the header if the view is public
if ($artefact->get('artefacttype') == 'blog' && $view->is_public()) {
    $hasfeed = true;
    $feedlink = get_config('wwwroot') . 'artefact/blog/atom.php?artefact=' .
                $artefactid . '&view=' . $viewid;
    $headers[] = '<link rel="alternate" type="application/atom+xml" href="' . $feedlink . '">';
}

$smarty = smarty(
    $javascript,
    $headers,
    array(),
    array(
        'stylesheets' => $extrastylesheets,
        'sidebars'    => false,
        'skin'        => $skin,
    )
);

$smarty->assign('artefact', $content);
$smarty->assign('artefactpath', $artefactpath);
$smarty->assign('INLINEJAVASCRIPT', $inlinejavascript);

if (get_config('viewmicroheaders')) {
    $smarty->assign('maharalogofilename', 'images/site-logo-small.png');
    $smarty->assign('microheaders', true);
    $smarty->assign('microheadertitle', $view->display_title(true, false));

    // Support for normal, light, or dark small Mahara logo - to use with skins
    if ($skin) {
        if ($skindata['header_logo_image'] == 'light') {
            $smarty->assign('maharalogofilename', 'images/site-logo-small-light.png');
        }
        else if ($skindata['header_logo_image'] == 'dark') {
            $smarty->assign('maharalogofilename', 'images/site-logo-small-dark.png');
        }
    }
}

$smarty->assign('view', $view);
$smarty->assign('viewid', $viewid);
$smarty->assign('feedback', $feedback);

$smarty->assign('hasfeed', $hasfeed);
$smarty->assign('feedlink', $feedlink);

if (isset($addfeedbackform)) {
    $smarty->assign('enablecomments', 1);
    $smarty->assign('addfeedbackform', $addfeedbackform);
}
$smarty->assign('objectionform', $objectionform);
$smarty->assign('notrudeform', $notrudeform);
$smarty->assign('viewbeingwatched', $viewbeingwatched);

$smarty->display('artefact/artefact.tpl');
