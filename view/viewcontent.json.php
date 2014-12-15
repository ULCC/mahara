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
define('JSON', 1);
require(dirname(dirname(__FILE__)) . '/init.php');
require_once(get_config('libroot') . 'view.php');

$id = param_integer('id');
if (!can_view_view($id)) {
    json_reply('local', get_string('accessdenied', 'error'));
}
$view = new View($id);

$smarty = smarty_core();
$smarty->assign('viewtitle', $view->get('title'));
$smarty->assign('ownername', $view->formatted_owner());
$smarty->assign('viewdescription', $view->get('description'));
$smarty->assign('viewcontent', $view->build_rows());
$smarty->assign('tags', $view->get('tags'));
$html = $smarty->fetch('view/viewcontent.tpl');

json_reply(false, array(
    'message' => null,
    'html' => $html,
));
