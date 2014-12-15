<?php
/**
 *
 * @package    mahara
 * @subpackage artefact-blog
 * @author     Catalyst IT Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL version 3 or later
 * @copyright  For copyright information on Mahara, please see the README file distributed with this software.
 *
 */

define('INTERNAL', 1);
define('MENUITEM', 'content/blogs');
define('SECTION_PLUGINTYPE', 'artefact');
define('SECTION_PLUGINNAME', 'blog');
define('SECTION_PAGE', 'post');

require(dirname(dirname(dirname(__FILE__))) . '/init.php');
require_once('pieforms/pieform.php');
require_once('license.php');

safe_require('artefact', 'blog');
safe_require('artefact', 'file');
if (!PluginArtefactBlog::is_active()) {
    throw new AccessDeniedException(get_string('plugindisableduser', 'mahara', get_string('blog','artefact.blog')));
}
/*
 * For a new post, the 'blog' parameter will be set to the blog's
 * artefact id.  For an existing post, the 'blogpost' parameter will
 * be set to the blogpost's artefact id.
 */
$blogpost = param_integer('blogpost', param_integer('id', 0));
if (!$blogpost) {
/*
 *  For a new post, a tag can be set from tagged blogpost block
 */
    $tagselect = param_variable('tagselect', '');
    $blog = param_integer('blog');
    if (!get_record('artefact', 'id', $blog, 'owner', $USER->get('id'))) {
        // Blog security is also checked closer to when blogs are added, this
        // check ensures that malicious users do not even see the screen for
        // adding a post to a blog that is not theirs
        throw new AccessDeniedException(get_string('youarenottheownerofthisblog', 'artefact.blog'));
    }
    $title = '';
    $description = '';
    $tags = array($tagselect);
    $checked = '';
    $pagetitle = get_string('newblogpost', 'artefact.blog', get_field('artefact', 'title', 'id', $blog));
    $focuselement = 'title';
    $attachments = array();
    define('TITLE', $pagetitle);
}
else {
    $blogpostobj = new ArtefactTypeBlogPost($blogpost);
    $blogpostobj->check_permission();
    if ($blogpostobj->get('locked')) {
        throw new AccessDeniedException(get_string('submittedforassessment', 'view'));
    }
    $blog = $blogpostobj->get('parent');
    $title = $blogpostobj->get('title');
    $description = $blogpostobj->get('description');
    $tags = $blogpostobj->get('tags');
    $checked = !$blogpostobj->get('published');
    $pagetitle = get_string('editblogpost', 'artefact.blog');
    $focuselement = 'description'; // Doesn't seem to work with tinyMCE.
    $attachments = $blogpostobj->attachment_id_list();
    define('TITLE', get_string('editblogpost','artefact.blog'));
}

$folder = param_integer('folder', 0);
$browse = (int) param_variable('browse', 0);
$highlight = null;
if ($file = param_integer('file', 0)) {
    $highlight = array($file);
}


$form = pieform(array(
    'name'               => 'editpost',
    'method'             => 'post',
    'autofocus'          => $focuselement,
    'jsform'             => true,
    'newiframeonsubmit'  => true,
    'jssuccesscallback'  => 'editpost_callback',
    'jserrorcallback'    => 'editpost_callback',
    'plugintype'         => 'artefact',
    'pluginname'         => 'blog',
    'configdirs'         => array(get_config('libroot') . 'form/', get_config('docroot') . 'artefact/file/form/'),
    'elements' => array(
        'blog' => array(
            'type' => 'hidden',
            'value' => $blog,
        ),
        'blogpost' => array(
            'type' => 'hidden',
            'value' => $blogpost,
        ),
        'title' => array(
            'type' => 'text',
            'title' => get_string('posttitle', 'artefact.blog'),
            'rules' => array(
                'required' => true
            ),
            'defaultvalue' => $title,
        ),
        'description' => array(
            'type' => 'wysiwyg',
            'rows' => 20,
            'cols' => 70,
            'title' => get_string('postbody', 'artefact.blog'),
            'description' => get_string('postbodydesc', 'artefact.blog'),
            'rules' => array(
                'maxlength' => 65536,
                'required' => true
            ),
            'defaultvalue' => $description,
        ),
        'tags'       => array(
            'defaultvalue' => $tags,
            'type'         => 'tags',
            'title'        => get_string('tags'),
            'description'  => get_string('tagsdesc'),
            'help' => true,
        ),
        'license' => license_form_el_basic(isset($blogpostobj) ? $blogpostobj : null),
        'licensing_advanced' => license_form_el_advanced(isset($blogpostobj) ? $blogpostobj : null),
        'filebrowser' => array(
            'type'         => 'filebrowser',
            'title'        => get_string('attachments', 'artefact.blog'),
            'folder'       => $folder,
            'highlight'    => $highlight,
            'browse'       => $browse,
            'page'         => get_config('wwwroot') . 'artefact/blog/post.php?' . ($blogpost ? ('id=' . $blogpost) : ('blog=' . $blog)) . '&browse=1',
            'browsehelp'   => 'browsemyfiles',
            'config'       => array(
                'upload'          => true,
                'uploadagreement' => get_config_plugin('artefact', 'file', 'uploadagreement'),
                'resizeonuploaduseroption' => get_config_plugin('artefact', 'file', 'resizeonuploaduseroption'),
                'resizeonuploaduserdefault' => $USER->get_account_preference('resizeonuploaduserdefault'),
                'createfolder'    => false,
                'edit'            => false,
                'select'          => true,
            ),
            'defaultvalue'       => $attachments,
            'selectlistcallback' => 'artefact_get_records_by_id',
            'selectcallback'     => 'add_attachment',
            'unselectcallback'   => 'delete_attachment',
        ),
        'draft' => array(
            'type' => 'checkbox',
            'title' => get_string('draft', 'artefact.blog'),
            'description' => get_string('thisisdraftdesc', 'artefact.blog'),
            'defaultvalue' => $checked,
            'help' => true,
        ),
        'allowcomments' => array(
            'type'         => 'checkbox',
            'title'        => get_string('allowcomments','artefact.comment'),
            'description'  => get_string('allowcommentsonpost','artefact.blog'),
            'defaultvalue' => $blogpost ? $blogpostobj->get('allowcomments') : 1,
        ),
        'submitpost' => array(
            'type' => 'submitcancel',
            'value' => array(get_string('savepost', 'artefact.blog'), get_string('cancel')),
            'goto' => get_config('wwwroot') . 'artefact/blog/view/index.php?id=' . $blog,
        )
    )
));

/*
 * Javascript specific to this page.  Creates the list of files
 * attached to the blog post.
 */
$wwwroot = get_config('wwwroot');
$noimagesmessage = json_encode(get_string('noimageshavebeenattachedtothispost', 'artefact.blog'));

$javascript = <<<EOF
function editpost_callback(form, data) {
    editpost_filebrowser.callback(form, data);
};
EOF;

$smarty = smarty(array(), array(), array(), array(
    'tinymceconfig' => '
        plugins: "tooltoggle,textcolor,link,maharaimage,table,emoticons,spellchecker,paste,code,fullscreen,directionality,searchreplace,nonbreaking,charmap",
        image_filebrowser: "editpost_filebrowser",
    ',
    'sideblocks' => array(
        array(
            'name'   => 'quota',
            'weight' => -10,
            'data'   => array(),
        ),
    ),
));
$smarty->assign('INLINEJAVASCRIPT', $javascript);
$smarty->assign_by_ref('form', $form);
$smarty->assign('PAGEHEADING', $pagetitle);
$smarty->display('artefact:blog:editpost.tpl');



/**
 * This function get called to cancel the form submission. It returns to the
 * blog list.
 */
function editpost_cancel_submit() {
    global $blog;
    redirect(get_config('wwwroot') . 'artefact/blog/view/index.php?id=' . $blog);
}

function editpost_submit(Pieform $form, $values) {
    global $USER, $SESSION, $blogpost, $blog;

    db_begin();
    $postobj = new ArtefactTypeBlogPost($blogpost, null);
    $postobj->set('title', $values['title']);
    $postobj->set('description', $values['description']);
    $postobj->set('tags', $values['tags']);
    if (get_config('licensemetadata')) {
        $postobj->set('license', $values['license']);
        $postobj->set('licensor', $values['licensor']);
        $postobj->set('licensorurl', $values['licensorurl']);
    }
    $postobj->set('published', !$values['draft']);
    $postobj->set('allowcomments', (int) $values['allowcomments']);
    if (!$blogpost) {
        $postobj->set('parent', $blog);
        $postobj->set('owner', $USER->id);
    }
    $postobj->commit();
    $blogpost = $postobj->get('id');

    // Attachments
    $old = $postobj->attachment_id_list();
    // $new = is_array($values['filebrowser']['selected']) ? $values['filebrowser']['selected'] : array();
    $new = is_array($values['filebrowser']) ? $values['filebrowser'] : array();
    // only allow the attaching of files that exist and are editable by user
    foreach ($new as $key => $fileid) {
        $file = artefact_instance_from_id($fileid);
        if (!($file instanceof ArtefactTypeFile) || !$USER->can_publish_artefact($file)) {
            unset($new[$key]);
        }
    }
    if (!empty($new) || !empty($old)) {
        foreach ($old as $o) {
            if (!in_array($o, $new)) {
                try {
                    $postobj->detach($o);
                }
                catch (ArtefactNotFoundException $e) {}
            }
        }
        foreach ($new as $n) {
            if (!in_array($n, $old)) {
                try {
                    $postobj->attach($n);
                }
                catch (ArtefactNotFoundException $e) {}
            }
        }
    }
    db_commit();

    $result = array(
        'error'   => false,
        'message' => get_string('blogpostsaved', 'artefact.blog'),
        'goto'    => get_config('wwwroot') . 'artefact/blog/view/index.php?id=' . $blog,
    );
    if ($form->submitted_by_js()) {
        // Redirect back to the blog page from within the iframe
        $SESSION->add_ok_msg($result['message']);
        $form->json_reply(PIEFORM_OK, $result, false);
    }
    $form->reply(PIEFORM_OK, $result);
}

function add_attachment($attachmentid) {
    global $blogpostobj;
    if ($blogpostobj) {
        $blogpostobj->attach($attachmentid);
    }
}

function delete_attachment($attachmentid) {
    global $blogpostobj;
    if ($blogpostobj) {
        $blogpostobj->detach($attachmentid);
    }
}
