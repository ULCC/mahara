<?php
/**
 *
 * @package    mahara
 * @subpackage artefact-plans
 * @author     Catalyst IT Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL version 3 or later
 * @copyright  For copyright information on Mahara, please see the README file distributed with this software.
 *
 */

define('INTERNAL', true);
define('MENUITEM', 'content/plans');

require_once(dirname(dirname(dirname(dirname(__FILE__)))) . '/init.php');
require_once('pieforms/pieform.php');
safe_require('artefact','plans');

define('TITLE', get_string('deleteplan','artefact.plans'));

$id = param_integer('id');
$todelete = new ArtefactTypePlan($id);
if (!$USER->can_edit_artefact($todelete)) {
    throw new AccessDeniedException(get_string('accessdenied', 'error'));
}

$deleteform = array(
    'name' => 'deleteplanform',
    'plugintype' => 'artefact',
    'pluginname' => 'plan',
    'renderer' => 'div',
    'elements' => array(
        'submit' => array(
            'type' => 'submitcancel',
            'value' => array(get_string('deleteplan','artefact.plans'), get_string('cancel')),
            'goto' => get_config('wwwroot') . '/artefact/plans/index.php',
        ),
    )
);
$form = pieform($deleteform);

$smarty = smarty();
$smarty->assign('form', $form);
$smarty->assign('PAGEHEADING', $todelete->get('title'));
$smarty->assign('subheading', get_string('deletethisplan','artefact.plans',$todelete->get('title')));
$smarty->assign('message', get_string('deleteplanconfirm','artefact.plans'));
$smarty->display('artefact:plans:delete.tpl');

// calls this function first so that we can get the artefact and call delete on it
function deleteplanform_submit(Pieform $form, $values) {
    global $SESSION, $todelete;

    $todelete->delete();
    $SESSION->add_ok_msg(get_string('plandeletedsuccessfully', 'artefact.plans'));

    redirect('/artefact/plans/index.php');
}
