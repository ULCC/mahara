<?php
/**
 *
 * @package    mahara
 * @subpackage artefact-comment
 * @author     Catalyst IT Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL version 3 or later
 * @copyright  For copyright information on Mahara, please see the README file distributed with this software.
 *
 */

define('INTERNAL', 1);
define('MENUITEM', 'myportfolio');

require(dirname(dirname(dirname(__FILE__))) . '/init.php');
define('TITLE', get_string('editcomment', 'artefact.comment'));
safe_require('artefact', 'comment');

$id = param_integer('id');
$viewid = param_integer('view');
$comment = new ArtefactTypeComment($id);

if ($USER->get('id') != $comment->get('author')) {
    throw new AccessDeniedException(get_string('canteditnotauthor', 'artefact.comment'));
}

$onview = $comment->get('onview');
if ($onview && $onview != $viewid) {
    throw new NotFoundException(get_string('commentnotinview', 'artefact.comment', $id, $viewid));
}

$maxage = (int) get_config_plugin('artefact', 'comment', 'commenteditabletime');
$editableafter = time() - 60 * $maxage;

$goto = $comment->get_view_url($viewid, false);

if ($comment->get('ctime') < $editableafter) {
    $SESSION->add_error_msg(get_string('cantedittooold', 'artefact.comment', $maxage));
    redirect($goto);
}

$lastcomment = ArtefactTypeComment::last_public_comment($viewid, $comment->get('onartefact'));

if (!$comment->get('private') && $id != $lastcomment->id) {
    $SESSION->add_error_msg(get_string('cantedithasreplies', 'artefact.comment'));
    redirect($goto);
}

$elements = array();
$elements['message'] = array(
    'type'         => 'wysiwyg',
    'title'        => get_string('message'),
    'rows'         => 5,
    'cols'         => 80,
    'defaultvalue' => $comment->get('description'),
    'rules'        => array('maxlength' => 8192),
);
if (get_config_plugin('artefact', 'comment', 'commentratings')) {
    $elements['rating'] = array(
        'type'  => 'radio',
        'title' => get_string('rating', 'artefact.comment'),
        'options' => array('1' => '', '2' => '', '3' => '', '4' => '', '5' => ''),
        'class' => 'star',
        'defaultvalue' => $comment->get('rating'),
    );
}
else {
    $elements['rating'] = array(
        'type'  => 'hidden',
        'value' => $comment->get('rating'),
    );
}
$elements['ispublic'] = array(
    'type'  => 'checkbox',
    'title' => get_string('makepublic', 'artefact.comment'),
    'defaultvalue' => !$comment->get('private'),
);
if (get_config('licensemetadata')) {
    $elements['license'] = license_form_el_basic($comment);
    $elements['licensing_advanced'] = license_form_el_advanced($comment);
}
$elements['submit'] = array(
    'type'  => 'submitcancel',
    'value' => array(get_string('save'), get_string('cancel')),
    'goto'  => $goto,
);

$form = pieform(array(
    'name'            => 'edit_comment',
    'method'          => 'post',
    'plugintype'      => 'artefact',
    'pluginname'      => 'comment',
    'elements'        => $elements,
));

function edit_comment_validate(Pieform $form, $values) {
    require_once(get_config('libroot.php') . 'antispam.php');
    $result = probation_validate_content($values['message']);
    if ($result !== true) {
        $form->set_error('message', get_string('newuserscantpostlinksorimages'));
    }
}

function edit_comment_submit(Pieform $form, $values) {
    global $viewid, $comment, $SESSION, $goto, $USER;

    db_begin();

    $comment->set('description', $values['message']);
    $comment->set('rating', valid_rating($values['rating']));
    require_once(get_config('libroot') . 'view.php');
    $view = new View($viewid);
    $owner = $view->get('owner');
    $group = $comment->get('group');
    $approvecomments = $view->get('approvecomments');
    if (!empty($group) && ($approvecomments || (!$approvecomments && $view->user_comments_allowed($USER) == 'private')) && $values['ispublic'] && !$USER->can_edit_view($view)) {
        $comment->set('requestpublic', 'author');
    }
    else if (($approvecomments || (!$approvecomments && $view->user_comments_allowed($USER) == 'private')) && $values['ispublic'] && (!empty($owner) && $owner != $comment->get('author'))) {
        $comment->set('requestpublic', 'author');
    }
    else {
        $comment->set('private', 1 - (int) $values['ispublic']);
        $comment->set('requestpublic', null);
    }
    $comment->commit();

    require_once('activity.php');
    $data = (object) array(
        'commentid' => $comment->get('id'),
        'viewid'    => $viewid,
    );

    activity_occurred('feedback', $data, 'artefact', 'comment');
    if ($comment->get('requestpublic') == 'author') {
        if (!empty($owner)) {
            edit_comment_notify($view, $comment->get('author'), $owner);
        }
        else if (!empty($group)) {
            $group_admins = group_get_admin_ids($group);
            // TO DO: need to notify the group admins bug #1197197
        }
    }

    db_commit();

    $SESSION->add_ok_msg(get_string('commentupdated', 'artefact.comment'));
    redirect($goto);
}

function edit_comment_notify($view, $author, $owner) {
    global $comment, $SESSION;

    $data = (object) array(
        'subject'   => false,
        'message'   => false,
        'strings'   => (object) array(
            'subject' => (object) array(
                'key'     => 'makepublicrequestsubject',
                'section' => 'artefact.comment',
                'args'    => array(),
            ),
            'message' => (object) array(
                'key'     => 'makepublicrequestbyauthormessage',
                'section' => 'artefact.comment',
                'args'    => array(hsc(display_name($author, $owner))),
            ),
            'urltext' => (object) array(
                'key'     => 'Comment',
                'section' => 'artefact.comment',
            ),
        ),
        'users'     => array($owner),
        'url'       => $comment->get_view_url($view->get('id'), true, false),
    );
    if (!empty($owner)) {
        $SESSION->add_ok_msg(get_string('makepublicrequestsent', 'artefact.comment', display_name($owner)));
    }
    activity_occurred('maharamessage', $data);
}

$stylesheets = array('style/jquery.rating.css');
$smarty = smarty(array('jquery.rating'), array(), array(), array('stylesheets' => $stylesheets));
$smarty->assign('PAGEHEADING', TITLE);
$smarty->assign('strdescription', get_string('editcommentdescription', 'artefact.comment', $maxage));
$smarty->assign('form', $form);
$smarty->display('artefact:comment:edit.tpl');
