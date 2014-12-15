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
define('MENUITEM', 'groups/mygroups');
require(dirname(dirname(__FILE__)) . '/init.php');
require_once('pieforms/pieform.php');
define('TITLE', get_string('mygroups'));
define('SECTION_PLUGINTYPE', 'core');
define('SECTION_PLUGINNAME', 'group');
define('SECTION_PAGE', 'mygroups');
require_once('group.php');
$filter = param_alpha('filter', 'all');
$offset = param_integer('offset', 'all');
$groupcategory = param_signed_integer('groupcategory', 0);

$groupsperpage = 10;
$offset = (int)($offset / $groupsperpage) * $groupsperpage;

$results = group_get_associated_groups($USER->get('id'), $filter, $groupsperpage, $offset, $groupcategory);
$elements = array();
$elements['options'] = array(
            'title' => get_string('filter'),
            'hiddenlabel' => true,
            'type' => 'select',
            'options' => array(
                'all'     => get_string('allmygroups', 'group'),
                'admin'   => get_string('groupsiown', 'group'),
                'member'  => get_string('groupsimin', 'group'),
                'invite'  => get_string('groupsiminvitedto', 'group')
            ),
            'defaultvalue' => $filter);
if (get_config('allowgroupcategories')
    && $groupcategories = get_records_menu('group_category','','','displayorder', 'id,title')
) {
    $options[0] = get_string('allcategories', 'group');
    $options[-1] = get_string('categoryunassigned', 'group');
    $options += $groupcategories;
    $elements['groupcategory'] = array(
                'title'        => get_string('groupcategory', 'group'),
                'hiddenlabel'  => true,
                'type'         => 'select',
                'options'      => $options,
                'defaultvalue' => $groupcategory,
                'help'         => true);
}
$elements['submit'] = array(
            'type' => 'submit',
            'value' => get_string('filter'));
$form = pieform(array(
    'name'   => 'filter',
    'checkdirtychange' => false,
    'method' => 'post',
    'renderer' => 'oneline',
    'elements' => $elements
));

$params = array();
if ($filter != 'all') {
    $params['filter'] = $filter;
}
if ($groupcategory != 0) {
    $params['groupcategory'] = $groupcategory;
}

$pagination = build_pagination(array(
    'url' => get_config('wwwroot') . 'group/mygroups.php' . (!empty($params) ? ('?' . http_build_query($params)) : ''),
    'count' => $results['count'],
    'limit' => $groupsperpage,
    'offset' => $offset,
    'jumplinks' => 6,
    'numbersincludeprevnext' => 2,
    'resultcounttextsingular' => get_string('group', 'group'),
    'resultcounttextplural' => get_string('groups', 'group'),
));

group_prepare_usergroups_for_display($results['groups'], 'mygroups');

$smarty = smarty();
$smarty->assign('groups', $results['groups']);
$smarty->assign('cancreate', group_can_create_groups());
$smarty->assign('form', $form);
$smarty->assign('filter', $filter);
$smarty->assign('pagination', $pagination['html']);
$smarty->assign('searchingforgroups', array('<a href="' . get_config('wwwroot') . 'group/find.php">', '</a>'));
$smarty->assign('PAGEHEADING', TITLE);
$smarty->display('group/mygroups.tpl');

function filter_submit(Pieform $form, $values) {
    redirect('/group/mygroups.php?filter=' . $values['options']. (!empty($values['groupcategory']) ? '&groupcategory=' . intval($values['groupcategory']) : ''));
}
