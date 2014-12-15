<?php
/**
 *
 * @package    mahara
 * @subpackage artefact-file
 * @author     Catalyst IT Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL version 3 or later
 * @copyright  For copyright information on Mahara, please see the README file distributed with this software.
 *
 */

defined('INTERNAL') || die();

/**
 * Browser for files area.
 *
 * @param Pieform  $form    The form to render the element for
 * @param array    $element The element to render
 * @return string           The HTML for the element
 */
function pieform_element_filebrowser(Pieform $form, $element) {
    require_once('license.php');
    global $USER, $_PIEFORM_FILEBROWSERS;
    $smarty = smarty_core();

    $group = $form->get_property('group');
    $institution = $form->get_property('institution');


    $formid = $form->get_name();
    $prefix = $formid . '_' . $element['name'];

    if (!empty($element['tabs'])) {
        $tabdata = pieform_element_filebrowser_configure_tabs($element['tabs'], $prefix);
        $smarty->assign('tabs', $tabdata);
        if (!$group && $tabdata['owner'] == 'group') {
            $group = $tabdata['ownerid'];
        }
        else if (!$institution) {
            if ($tabdata['owner'] == 'institution') {
                $institution = $tabdata['ownerid'];
            }
            else if ($tabdata['owner'] == 'site') {
                $institution = 'mahara';
            }
        }
    }

    $userid = ($group || $institution) ? null : $USER->get('id');

    // refresh quotas
    if ($userid) {
        $USER->quota_refresh();
    }

    $folder = $element['folder'];
    if ($group && !pieform_element_filebrowser_view_group_folder($group, $folder)) {
        $folder = null;
    }
    $path = pieform_element_filebrowser_get_path($folder);
    $smarty->assign('folder', $folder);
    $smarty->assign('foldername', $path[0]->title);
    $smarty->assign('path', array_reverse($path));
    $smarty->assign('highlight', $element['highlight'][0]);
    $smarty->assign('edit', !empty($element['edit']) ? $element['edit'] : -1);
    if (isset($element['browse'])) {
        $smarty->assign('browse', (int) $element['browse']);
    }

    $config = array_map('intval', $element['config']);

    if ($group && $config['edit']) {
        $smarty->assign('groupinfo', pieform_element_filebrowser_get_groupinfo($group));
    }

    if ($config['select']) {
        if (function_exists($element['selectlistcallback'])) {
            if ($form->is_submitted() && $form->has_errors() && isset($_POST[$prefix . '_selected']) && is_array($_POST[$prefix . '_selected'])) {
                $value = array_keys($_POST[$prefix . '_selected']);
            }
            else if (isset($element['defaultvalue'])) {
                $value = $element['defaultvalue'];
            }
            else {
                $value = null;
            }
            // check to see if attached artefact items in $value array are actually allowed
            // to be seen by this user
            if (!empty($value)) {
                foreach ($value as $k => $v) {
                    $file = artefact_instance_from_id($v);
                    if ((!($file instanceof ArtefactTypeFile) && !($file instanceof ArtefactTypeFolder))
                        || !$USER->can_view_artefact($file)) {
                        unset($value[$k]);
                    }
                }
            }
            $selected = $element['selectlistcallback']($value);
        }
        $smarty->assign('selectedlist', $selected);
        $selectedliststr = json_encode($selected);
    }

    if ($config['uploadagreement']) {
        if (get_config_plugin('artefact', 'file', 'usecustomagreement')) {
            $smarty->assign('agreementtext', get_field('site_content', 'content', 'name', 'uploadcopyright'));
        }
        else {
            $smarty->assign('agreementtext', get_string('uploadcopyrightdefaultcontent', 'install'));
        }
    }
    else if (!isset($config['simpleupload'])) {
        $config['simpleupload'] = 1;
    }

    $licensing = '<table class="fileuploadlicense"><tbody>'
               . license_form_files($prefix)
               . '</tbody></table>';
    $smarty->assign('licenseform', $licensing);

    if ($config['resizeonuploaduseroption'] == 1) {
        $smarty->assign('resizeonuploadenable', get_config_plugin('artefact', 'file', 'resizeonuploadenable'));
        $smarty->assign('resizeonuploadmaxwidth', get_config_plugin('artefact', 'file', 'resizeonuploadmaxwidth'));
        $smarty->assign('resizeonuploadmaxheight', get_config_plugin('artefact', 'file', 'resizeonuploadmaxheight'));
    }

    if ($config['upload']) {
        $maxuploadsize = display_size(get_max_upload_size(!$institution && !$group));
        $smarty->assign('maxuploadsize', $maxuploadsize);
        $smarty->assign('phpmaxfilesize', get_max_upload_size(false));
        if ($group) {
            $smarty->assign('uploaddisabled', !pieform_element_filebrowser_edit_group_folder($group, $folder));
        }
    }

    if (!empty($element['browsehelp'])) {
        $config['plugintype'] = $form->get_property('plugintype');
        $config['pluginname'] = $form->get_property('pluginname');
        $config['browsehelp'] = $element['browsehelp'];
    }

    $config['showtags'] = !empty($config['tag']) ? (int) $userid : 0;
    $config['editmeta'] = (int) ($userid && !$config['edit'] && !empty($config['tag']));

    $smarty->assign('config', $config);

    $filters = isset($element['filters']) ? $element['filters'] : null;
    $filedata = ArtefactTypeFileBase::get_my_files_data($folder, $userid, $group, $institution, $filters);
    $smarty->assign('filelist', $filedata);

    $configstr = json_encode($config);
    $fileliststr = json_encode($filedata);

    $smarty->assign('prefix', $prefix);

    $initjs = "{$prefix} = new FileBrowser('{$prefix}', {$folder}, {$configstr}, config);
{$prefix}.filedata = {$fileliststr};";
    if ($config['select']) {
        $initjs .= "{$prefix}.selecteddata = {$selectedliststr};";
    }

    if (isset($tabdata)) {
        $initjs .= "{$prefix}.tabdata = " . json_encode($tabdata) . ';';
    }

    $_PIEFORM_FILEBROWSERS[$prefix]['views_js'] = $initjs;

    $initjs .= "addLoadEvent({$prefix}.init);";
    $initjs .= "upload_max_filesize = '" . get_real_size(ini_get('upload_max_filesize')) . "';";

    $smarty->assign('initjs', $initjs);
    $smarty->assign('querybase', $element['page'] . (strpos($element['page'], '?') === false ? '?' : '&'));

    $params = 'folder=' . $folder;
    if ($group) {
        $params .= '&group=' . $group;
    }
    if ($institution) {
        $params .= '&institution=' . $institution;
    }
    $smarty->assign('folderparams', $params);

    return $smarty->fetch('artefact:file:form/filebrowser.tpl');
}

function pieform_element_filebrowser_get_groupinfo($group) {
    require_once('group.php');
    $groupinfo = array(
        'roles' => group_get_role_info($group),
        'perms' => group_get_default_artefact_permissions($group),
        'perm'  => array(),
    );
    foreach (current($groupinfo['perms']) as $k => $v) {
        $groupinfo['perm'][$k] = get_string('filepermission.' . $k, 'artefact.file');
    }
    return $groupinfo;
}


function pieform_element_filebrowser_get_path($folder) {
    $path = array();
    if ($folder) {
        $folders = ArtefactTypeFileBase::artefactchooser_folder_data(artefact_instance_from_id($folder))->data;
        $f = $folder;
        while ($f) {
            $path[] = (object) array('title' => $folders[$f]->title, 'id' => $f);
            $f = $folders[$f]->parent;
        }
    }

    $path[] = (object) array('title' => get_string('home', 'artefact.file'), 'id' => 0);
    return $path;
}


function pieform_element_filebrowser_build_path($form, $element, $folder, $owner=null, $ownerid=null) {
    if (!$form->submitted_by_js()) {
        return;
    }
    $querybase = $element['page'] . (strpos($element['page'], '?') === false ? '?' : '&');

    $path = pieform_element_filebrowser_get_path($folder);
    $foldername = $path[0]->title;

    $smarty = smarty_core();
    $smarty->assign('path', array_reverse($path));
    $smarty->assign('owner', $owner);
    $smarty->assign('ownerid', $ownerid);
    $smarty->assign('querybase', $querybase);
    return array('html' => $smarty->fetch('artefact:file:form/folderpath.tpl'), 'foldername' => $foldername);
}


function pieform_element_filebrowser_build_filelist($form, $element, $folder, $highlight=null, $user=null, $group=null, $institution=null) {
    require_once('license.php');
    if (!$form->submitted_by_js()) {
        // We're going to rebuild the page from scratch anyway.
        return;
    }

    global $USER;

    $smarty = smarty_core();
    $userid = null;
    if (is_null($institution) && is_null($group) && is_null($user)) {
        $group = $form->get_property('group');
    }
    else if ($user) {
        $userid = $USER->get('id');
        $smarty->assign('owner', 'user');
        $smarty->assign('ownerid', $userid);
    }
    else {
        $smarty->assign('owner', 'group');
        $smarty->assign('ownerid', $group);
    }
    if (is_null($institution)) {
        $institution = $form->get_property('institution');
    }
    else {
        $smarty->assign('owner', 'institution');
        $smarty->assign('ownerid', $institution);
    }

    if (is_null($userid)) {
        $userid = ($group || $institution) ? null : $USER->get('id');
    }
    $editable = (int) $element['config']['edit'];
    $selectable = (int) $element['config']['select'];
    $selectfolders = (int) !empty($element['config']['selectfolders']);
    $publishing = (int) !empty($element['config']['publishing']);
    $showtags = !empty($element['config']['tag']) ? (int) $userid : 0;
    $editmeta = (int) ($userid && !$editable && !empty($element['config']['tag']));
    $querybase = $element['page'] . (strpos($element['page'], '?') === false ? '?' : '&');
    $prefix = $form->get_name() . '_' . $element['name'];

    $filters = isset($element['filters']) ? $element['filters'] : null;
    $filedata = ArtefactTypeFileBase::get_my_files_data($folder, $userid, $group, $institution, $filters);

    $smarty->assign('edit', -1);
    $smarty->assign('highlight', $highlight);
    $smarty->assign('editable', $editable);
    $smarty->assign('selectable', $selectable);
    $smarty->assign('selectfolders', $selectfolders);
    $smarty->assign('publishing', $publishing);
    $smarty->assign('showtags', $showtags);
    $smarty->assign('editmeta', $editmeta);
    $smarty->assign('filelist', $filedata);
    $smarty->assign('querybase', $querybase);
    $smarty->assign('prefix', $prefix);

    $params = 'folder=' . ($folder === null ? 0 : $folder);
    if ($group !== null) {
        $params .= '&group=' . $group;
    }
    if ($institution !== null) {
        $params .= '&institution=' . $institution;
    }

    $smarty->assign('folderparams', $params);

    return array(
        'data' => $filedata,
        'html' => $smarty->fetch('artefact:file:form/filelist.tpl'),
    );
}


function pieform_element_filebrowser_configure_tabs($viewowner, $prefix) {
    if ($viewowner['type'] == 'institution' && $viewowner['id'] == 'mahara') {
        // No filebrowser tabs for site views
        return null;
    }

    $tabs = array();
    $subtabs = array();

    $upload = null;
    $selectedsubtab = null;
    if ($viewowner['type'] == 'institution') {
        $selectedtab = param_variable($prefix . '_owner', 'institution');
        $upload = $selectedtab == 'institution';
        $tabs['institution'] = get_string('institutionfiles', 'admin');
    }
    else if ($viewowner['type'] == 'group') {
        $selectedtab = param_variable($prefix . '_owner', 'group');
        $upload = $selectedtab == 'group';
        $tabs['user'] = get_string('myfiles', 'artefact.file');
        $tabs['group'] = get_string('groupfiles', 'artefact.file');
    }
    else { // $viewowner['type'] == 'user'
        global $USER;
        $selectedtab = param_variable($prefix . '_owner', 'user');
        $upload = $selectedtab == 'user';
        $tabs['user'] = get_string('myfiles', 'artefact.file');
        if ($groups = $USER->get('grouproles')) {
            $tabs['group'] = get_string('groupfiles', 'artefact.file');
            require_once(get_config('libroot') . 'group.php');
            $groups = group_get_user_groups($USER->get('id'));
            if ($selectedtab == 'group') {
                if (!$selectedsubtab = (int) param_variable($prefix . '_ownerid', 0)) {
                    $selectedsubtab = $groups[0]->id;
                }
                foreach ($groups as &$g) {
                    $subtabs[$g->id] = $g->name;
                }
            }
        }
        if ($institutions = $USER->get('institutions')) {
            $tabs['institution'] = get_string('institutionfiles', 'admin');
            $institutions = get_records_select_array('institution', 'name IN ('
                . join(',', array_map('db_quote', array_keys($institutions))) . ')');
            if ($selectedtab == 'institution') {
                if (!$selectedsubtab = param_variable($prefix . '_ownerid', '')) {
                    $selectedsubtab = $institutions[0]->name;
                }
                $selectedsubtab = hsc($selectedsubtab);
                foreach ($institutions as &$i) {
                    $subtabs[$i->name] = $i->displayname;
                }
            }
        }
    }
    $tabs['site'] = get_string('sitefiles', 'admin');
    return array(
        'tabs'    => $tabs,
        'subtabs' => $subtabs,
        'owner'   => $selectedtab,
        'ownerid' => $viewowner['type'] == 'group' ? $viewowner['id'] : $selectedsubtab,
        'upload'  => $upload
    );
}


function pieform_element_filebrowser_get_value(Pieform $form, $element) {
    $prefix = $form->get_name() . '_' . $element['name'];


    // Cancel edit a file artefact
    // This value only available when the filebrowser was submitted by non-js web browser
    $canceledit = param_variable($prefix . '_canceledit', null);
    if (!empty($canceledit)) {
        redirect($element['page']);
    }


    // The value of this element is the list of selected artefact ids
    $selected = param_variable($prefix . '_selected', null);
    if (is_array($selected)) {
        $selected = array_keys($selected);
    }


    // Process actions that must occur before form validation and
    // which can safely occur without affecting the element's value
    $result = pieform_element_filebrowser_doupdate($form, $element);

    if (is_array($result)) {
        // We did something.  If js, replace the filebrowser now and
        // don't continue form submission.
        if (!isset($result['folder'])) {
            $result['folder'] = $element['folder'];
        }
        if ($form->submitted_by_js()) {
            $replacehtml = false; // Don't replace the entire form when replying with json data.
            $result['formelement'] = $prefix;
            if (!empty($result['error'])) {
                $result['formelementerror'] = $prefix . '.callback';
            }
            else {
                $result['formelementsuccess'] = $prefix . '.callback';
            }
            $form->json_reply(empty($result['error']) ? PIEFORM_OK : PIEFORM_ERR, $result, $replacehtml);
        }

        // Not js. Add some params & redirect back to the page
        $params = array();
        if (!empty($result['folder'])) {
            $params[] = 'folder=' . $result['folder'];
        }
        if (!empty($result['edit'])) {
            $params[] = 'edit=' . $result['edit'];
        }
        if (!empty($result['highlight'])) {
            $params[] = 'file=' . $result['highlight'];
        }
        if (!empty($result['browse'])) {
            $params[] = 'browse=1';
        }

        $result['goto'] = $element['page'];
        if (!empty($params)) {
            $result['goto'] .= (strpos($element['page'], '?') === false ? '?' : '&') . join('&', $params);
        }

        if (empty($result['select']) && empty($result['unselect'])) {
            $form->reply(empty($result['error']) ? PIEFORM_OK : PIEFORM_ERR, $result);
        }

        // If we got to this point, the doupdate function couldn't select or unselect a file,
        // so we need to let it go through to the form's submit function to deal with.
        if (!empty($result['select'])) {
            if ($element['config']['selectone']) {
                $selected = array($result['select']);
            }
            else {
                $selected = is_array($selected) ? $selected : array();
                if (!in_array($result['select'], $selected)) {
                    $selected[] = $result['select'];
                }
            }
        }
        else if (!empty($result['unselect'])) {
            $selected = is_array($selected) ? array_diff($selected, array($result['unselect'])) : array();
        }
    }

    if (is_array($selected) && !empty($selected)) {
        if (!empty($element['config']['selectone'])) {
            return $selected[0];
        }
        return $selected;
    }
    return null;
}


function pieform_element_filebrowser_doupdate(Pieform $form, $element) {
    require_once('license.php');
    $result = null;

    $prefix = $form->get_name() . '_' . $element['name'];

    $delete = param_variable($prefix . '_delete', null);
    if (is_array($delete)) {
        $keys = array_keys($delete);
        return pieform_element_filebrowser_delete($form, $element, (int) ($keys[0]));
    }

    $resizeonuploaduserenable = param_variable($prefix . '_resizeonuploaduserenable', null);
    if (!empty($resizeonuploaduserenable)) {
        $resizeimage = 1;
    }
    else {
        $resizeimage = 0;
    }

    $update = param_variable($prefix . '_update', null);
    if (is_array($update)) {
        $keys = array_keys($update);
        $artefactid = (int) ($keys[0]);
        $edit_title = param_variable($prefix . '_edit_title');
        $namelength = strlen($edit_title);
        if (!$namelength) {
            return array(
                'edit'    => $artefactid,
                'error'   => true,
                'message' => get_string('filenamefieldisrequired1', 'artefact.file')
            );
        }
        else if ($namelength > 1024) {
            return array(
                'edit'    => $artefactid,
                'error'   => true,
                'message' => get_string('nametoolong', 'artefact.file'),
            );
        }
        $data = array(
            'artefact'    => $artefactid,
            'title'       => $edit_title,
            'description' => param_variable($prefix . '_edit_description'),
            'tags'        => param_variable($prefix . '_edit_tags'),
            'folder'      => $element['folder'],
            'allowcomments' => param_boolean($prefix . '_edit_allowcomments'),
        );
        if (get_config('licensemetadata')) {
            $data = array_merge($data, array(
                'license'     => license_coalesce(null,
                    param_variable($prefix . '_edit_license'),
                    param_variable($prefix . '_edit_license_other', null)),
                'licensor'    => param_variable($prefix . '_edit_licensor'),
                'licensorurl' => param_variable($prefix . '_edit_licensorurl'),
            ));
        }
        if ($form->get_property('group')) {
            $data['permissions']  = array('admin' => (object) array('view' => true, 'edit' => true, 'republish' => true));
            foreach ($_POST as $k => $v) {
                if (preg_match('/^' . $prefix . '_permission:([a-z]+):([a-z]+)$/', $k, $m)) {
                    if (!isset($data['permissions'][$m[1]])) {
                        $data['permissions'][$m[1]] = new stdClass;
                    }
                    $data['permissions'][$m[1]]->{$m[2]} = (bool) $v;
                }
            }
        }
        return pieform_element_filebrowser_update($form, $element, $data);
    }

    $move = param_variable($prefix . '_move', null);
    if (!empty($move)) {
        return pieform_element_filebrowser_move($form, $element, array(
            'artefact'  => (int) $move,
            'newparent' => param_integer($prefix . '_moveto'),
            'folder'    => $element['folder'],
        ));
    }

    $createfolder = param_variable($prefix . '_createfolder', null);
    if (!empty($createfolder)) {
        $createfolder_name = param_variable($prefix . '_createfolder_name');
        $namelength = strlen($createfolder_name);
        if (!$namelength) {
            return array(
                'error'   => true,
                'message' => get_string('foldernamerequired', 'artefact.file'),
            );
        }
        else if ($namelength > 1024) {
            return array(
                'error'   => true,
                'message' => get_string('nametoolong', 'artefact.file'),
            );
        }
        return pieform_element_filebrowser_createfolder($form, $element, array(
            'title'  => $createfolder_name,
            'folder' => $element['folder'],
        ));
    }

    // {$prefix}_upload is set in all browsers except safari when javascript is
    // on (and set in all browsers when it's not)
    $upload = param_variable($prefix . '_upload', null);
    if (!empty($upload)) {
        if (empty($_FILES['userfile']['name'])) {
            return array(
                'error'   => true,
                'message' => get_string('filenamefieldisrequired', 'artefact.file'),
                'browse'  => 1,
            );
        }
        else if (is_array($_FILES['userfile']['name'])) {
            foreach ($_FILES['userfile']['name'] as $filename) {
                if (empty($filename)) {
                    // TODO, how to specify which file is in error...
                    return array(
                        'error'   => true,
                        'message' => get_string('filenamefieldisrequired', 'artefact.file'),
                        'browse'  => 1,
                    );
                }
            }
        }
    }

    if (!empty($_FILES['userfile']['name'])) {
        if (!is_array($_FILES['userfile']['name'])) {
            if (!empty($_POST['_userfile']) && is_array($_POST['_userfile'])) {
                // renaming file for drag and drop
                $_FILES['userfile']['name'] = $_POST['_userfile']['name'];
                $_FILES['userfile']['type'] = $_POST['_userfile']['type'];
            }
            if (strlen($_FILES['userfile']['name']) > 1024) {
                return array(
                    'error'   => true,
                    'message' => get_string('nametoolong', 'artefact.file'),
                );
            }
            else if ($element['config']['uploadagreement'] && !param_boolean($prefix . '_notice', false)) {
                return array(
                    'error'   => true,
                    'message' => get_string('youmustagreetothecopyrightnotice', 'artefact.file'),
                    'browse'  => 1,
                );
            }
            $data = array(
                'userfile'         => $_FILES['userfile'],
                'uploadnumber'     => param_integer($prefix . '_uploadnumber'),
                'uploadfolder'     => $element['folder'] ? $element['folder'] : null,
                'uploadfoldername' => param_variable($prefix . '_foldername'),
                'resizeonuploaduserenable' => $resizeimage,
            );
            if (get_config('licensemetadata') && param_variable('dropzone')) {
                $data = array_merge($data, array(
                    'license'     => license_coalesce(null,
                        param_variable($prefix . '_license'),
                        param_variable($prefix . '_license_other', null)),
                    'licensor'    => param_variable($prefix . '_licensor'),
                    'licensorurl' => param_variable($prefix . '_licensorurl'),
                ));
            }
            else if (get_config('licensemetadata')) {
                $data = array_merge($data, array(
                    'license'     => license_coalesce(null,
                        param_variable($prefix . '_edit_license'),
                        param_variable($prefix . '_edit_license_other', null)),
                    'licensor'    => param_variable($prefix . '_edit_licensor'),
                    'licensorurl' => param_variable($prefix . '_edit_licensorurl'),
                ));
            }
            $result = pieform_element_filebrowser_upload($form, $element, $data);
            // If it's a non-js upload, automatically select the newly uploaded file.
            $result['browse'] = 1;
            if (!$form->submitted_by_js() && !$result['error'] && !empty($element['config']['select'])) {
                if (isset($element['selectcallback']) && is_callable($element['selectcallback'])) {
                    $element['selectcallback']($result['highlight']);
                }
                else {
                    $result['select'] = $result['highlight'];
                }
            }
            return $result;
        }
        else if (!empty($_FILES['userfile']['name'][0])) {
            if ($element['config']['uploadagreement'] && !param_boolean($prefix . '_notice', false)) {
                return array(
                    'error'   => true,
                    'message' => get_string('youmustagreetothecopyrightnotice', 'artefact.file'),
                    'browse'  => 1,
                );
            }
            $result = array('multiuploads' => array());
            $size = sizeof($_FILES['userfile']['name']);
            for ($i = 0; $i < $size; $i ++) {
                if (strlen($_FILES['userfile']['name'][$i]) > 1024) {
                    return array(
                        'error'   => true,
                        'message' => get_string('nametoolong', 'artefact.file'),
                    );
                }
                $data = array(
                    'userfile'         => $_FILES['userfile'],
                    'userfileindex'    => $i,
                    'uploadnumber'     => param_integer($prefix . '_uploadnumber') - ($size - $i - 1),
                    'uploadfolder'     => $element['folder'] ? $element['folder'] : null,
                    'uploadfoldername' => param_variable($prefix . '_foldername'),
                    'resizeonuploaduserenable' => $resizeimage,
                );
                if (get_config('licensemetadata')) {
                    $data = array_merge($data, array(
                        'license'     => license_coalesce(null,
                            param_variable($prefix . '_license'),
                            param_variable($prefix . '_license_other', null)),
                        'licensor'    => param_variable($prefix . '_licensor'),
                        'licensorurl' => param_variable($prefix . '_licensorurl'),
                    ));
                }
                $result['multiuploads'][$i] = pieform_element_filebrowser_upload($form, $element, $data);
                // TODO, what to do here...
                // If it's a non-js upload, automatically select the newly uploaded file.
                $result['multiuploads'][$i]['browse'] = 1;
                if (!$form->submitted_by_js() && !$result['multiuploads'][$i]['error'] && !empty($element['config']['select'])) {
                    if (isset($element['selectcallback']) && is_callable($element['selectcallback'])) {
                        $element['selectcallback']($result['multiuploads'][$i]['highlight']);
                    }
                    else {
                        $result['multiuploads'][$i]['select'] = $result['multiuploads'][$i]['highlight'];
                    }
                }
                $result['multiuploads'][$i]['folder'] = $element['folder'];
            }
            return $result;
        }
    }

    if (!$form->submitted_by_js()) {

        $select = param_variable($prefix . '_select', null);
        if (is_array($select)) {
            $keys = array_keys($select);
            $add = (int) $keys[0];
            if (isset($element['selectcallback']) && is_callable($element['selectcallback'])) {
                try {
                    $element['selectcallback']($add);
                }
                catch (ArtefactNotFoundException $e) {
                    $result = array(
                        'error' => true,
                        'message' => get_string('selectingfailed', 'artefact.file'),
                    );
                    return $result;
                }
            }
            else {
                $result['select'] = $add;
            }
            $result['message'] = get_string('fileadded', 'artefact.file');
            $result['browse'] = 1;
            return $result;
        }

        $unselect = param_variable($prefix . '_unselect', null);
        if (is_array($unselect)) {
            $keys = array_keys($unselect);
            $del = (int) $keys[0];
            if (isset($element['unselectcallback']) && is_callable($element['unselectcallback'])) {
                try {
                    $element['unselectcallback']($del);
                }
                catch (ArtefactNotFoundException $e) {
                    $result = array(
                        'error' => true,
                        'message' => get_string('removingfailed', 'artefact.file'),
                    );
                    return $result;
                }
            }
            else {
                $result['unselect'] = $del;
            }
            $result['message'] = get_string('fileremoved', 'artefact.file');
            return $result;
        }

        $edit = param_variable($prefix . '_edit', null);
        if (is_array($edit)) {
            $keys = array_keys($edit);
            $result['edit'] = (int) $keys[0];
            return $result;
        }

        if (param_variable('browse', 0) && !param_variable($prefix . '_cancelbrowse', 0)) {
            $result['browse'] = 1;
            return $result;
        }

    }

    $changeowner = param_variable($prefix . '_changeowner', null);
    if (!empty($changeowner)) {
        $result = pieform_element_filebrowser_changeowner($form, $element);
        $result['browse'] = 1;
        return $result;
    }

    $newfolder = param_variable($prefix . '_changefolder', null);
    if (!is_null($newfolder) && is_numeric($newfolder)) {
        $result = pieform_element_filebrowser_changefolder($form, $element, $newfolder);
        $result['browse'] = 1;
        $result['folder'] = $newfolder;
        return $result;
    }

}


function pieform_element_filebrowser_upload(Pieform $form, $element, $data) {
    global $USER;

    $parentfolder     = $data['uploadfolder'] ? (int) $data['uploadfolder'] : null;
    $institution      = $form->get_property('institution');
    $group            = $form->get_property('group');
    if (get_config('licensemetadata')) {
        $license          = $data['license'];
        $licensor         = $data['licensor'];
        $licensorurl      = $data['licensorurl'];
    }
    $uploadnumber     = (int) $data['uploadnumber'];
    $editable         = (int) $element['config']['edit'];
    $selectable       = (int) $element['config']['select'];
    $querybase        = $element['page'] . (strpos($element['page'], '?') === false ? '?' : '&');
    $prefix           = $form->get_name() . '_' . $element['name'];
    $userfileindex    = isset($data['userfileindex']) ? $data['userfileindex'] : null;
    $resizeonuploadenable = get_config_plugin('artefact', 'file', 'resizeonuploadenable');
    $resizeonuploaduseroption = get_config_plugin('artefact', 'file', 'resizeonuploaduseroption');
    $resizeonuploaduserenable = (int) $data['resizeonuploaduserenable'];

    $result = array('error' => false, 'uploadnumber' => $uploadnumber);

    if ($parentfolder == 0) {
        $parentfolder = null;
    }

    $data             = new StdClass;
    $data->parent     = $parentfolder;
    $data->owner      = null;
    if (get_config('licensemetadata')) {
        $data->license    = $license;
        $data->licensor   = $licensor;
        $data->licensorurl= $licensorurl;
    }

    if ($parentfolder) {
        $parentartefact = artefact_instance_from_id($parentfolder);
        if (!$USER->can_edit_artefact($parentartefact)) {
            $result['error'] = true;
            $result['message'] = get_string('cannoteditfolder', 'artefact.file');
            return $result;
        }
        else if ($parentartefact->get('locked')) {
            $result['error'] = true;
            $result['message'] = get_string('cannoteditfoldersubmitted', 'artefact.file');
            return $result;
        }
        $parentfoldername = $parentartefact->get('title');
    }
    else {
        $parentfoldername = null;
    }
    if ($institution) {
        if (!$USER->can_edit_institution($institution)) {
            $result['error'] = true;
            $result['message'] = get_string('notadminforinstitution', 'admin');
            return $result;
        }
        $data->institution = $institution;
    }
    else if ($group) {
        if (!group_within_edit_window($group)) {
            return array('error' => true, 'message' => get_string('cannoteditfolder', 'artefact.file'));
        }
        if (!$parentfolder) {
            if (!pieform_element_filebrowser_edit_group_folder($group, 0)) {
                return array('error' => true, 'message' => get_string('cannoteditfolder', 'artefact.file'));
            }
        }
        $data->group = $group;
    }
    else {
        $data->owner = $USER->get('id');
    }

    $data->container = 0;

    if (isset($userfileindex)) {
        $originalname = $_FILES['userfile']['name'][$userfileindex];
    }
    else {
        $originalname = $_FILES['userfile']['name'];
    }
    $originalname = $originalname ? basename($originalname) : get_string('file', 'artefact.file');
    $data->title = ArtefactTypeFileBase::get_new_file_title($originalname, $parentfolder, $data->owner, $group, $institution);

    // Overwrite image file with resized version if required
    $resized = false;
    $resizeattempted = false;
    // resize specified if (resizing is enabled AND user has enabled resizing) OR (resizing is enabled AND user is not given an option to enable/disable)
    if (($resizeonuploadenable && $resizeonuploaduserenable) || ($resizeonuploadenable && !$resizeonuploaduseroption)) {

        require_once('file.php');
        require_once('imageresizer.php');

        $file = $_FILES['userfile'];
        if (isset($userfileindex)) {
            $tmpname = $file['tmp_name'][$userfileindex];
        }
        else {
            $tmpname = $file['tmp_name'];
        }
        if (is_image_file($tmpname)) {
            $imageinfo = getimagesize($tmpname);
            $mimetype = $imageinfo['mime'];
            $width    = $imageinfo[0];
            $height   = $imageinfo[1];
            $bmptypes = array('image/bmp', 'image/x-bmp', 'image/ms-bmp', 'image/x-ms-bmp');

            // resize image if necessary
            $resizeonuploadmaxwidth  = get_config_plugin('artefact', 'file', 'resizeonuploadmaxwidth');
            $resizeonuploadmaxheight = get_config_plugin('artefact', 'file', 'resizeonuploadmaxheight');

            // Don't support bmps for now
            if (($width > $resizeonuploadmaxwidth || $height > $resizeonuploadmaxheight) && !in_array($mimetype, $bmptypes)) {
                $resizeattempted = true;
                $imgrs = new ImageResizer($tmpname, $mimetype);
                $img = $imgrs->get_image();
                if (!empty($img)) {
                    $imgrs->resize_image(array('w' => $resizeonuploadmaxwidth, 'h' => $resizeonuploadmaxheight), $mimetype); //auto
                    $saveresize = $imgrs->save_image($tmpname, $mimetype, 85);
                    if (!$saveresize) {
                        return array('error' => true, 'message' => get_string('problemresizing', 'artefact.file'));
                    }
                    $resized = true;
                }
            }
        }
    }

    try {
        $newid = ArtefactTypeFile::save_uploaded_file('userfile', $data, $userfileindex, $resized);
    }
    catch (QuotaExceededException $e) {
        prepare_upload_failed_message($result, $e, $parentfoldername, $originalname);
        // update the file listing
        if (defined('GROUP')) {
            $group = group_current_group();
            $result['quota'] = $group->quota;
            $result['quotaused'] = $group->quotaused;
        }
        else {
            $result['quota'] = $USER->get('quota');
            $result['quotaused'] = $USER->get('quotaused');
        }
        $result['newlist'] = pieform_element_filebrowser_build_filelist($form, $element, $parentfolder);
        return $result;
    }
    catch (UploadException $e) {
        prepare_upload_failed_message($result, $e, $parentfoldername, $originalname);
        if (defined('GROUP')) {
            $group = group_current_group();
            $result['quota'] = $group->quota;
            $result['quotaused'] = $group->quotaused;
        }
        return $result;
    }

    // Upload succeeded

    if (isset($element['filters'])) {
        $artefacttypes = isset($element['filters']['artefacttype']) ? $element['filters']['artefacttype'] : null;
        $filetypes = isset($element['filters']['filetype']) ? $element['filters']['filetype'] : null;
        if (!empty($artefacttypes) || !empty($filetypes)) {
            // Need to check the artefacttype or filetype (mimetype) of the uploaded file.
            $file = artefact_instance_from_id($newid);
            if (is_array($artefacttypes) && !in_array($file->get('artefacttype'), $artefacttypes)
                || is_array($filetypes) && !in_array($file->get('filetype'), $filetypes)) {
                $result['error'] = true;
                $result['uploaded'] = true;
                $result['message'] = get_string('wrongfiletypeforblock', 'artefact.file');
                return $result;
            }
        }
    }

    if ($parentfoldername) {
        if ($data->title == $originalname) {
            $result['message'] = get_string('uploadoffiletofoldercomplete', 'artefact.file',
                                            $originalname, $parentfoldername);
        }
        else {
            $result['message'] = get_string('fileuploadedtofolderas', 'artefact.file',
                                            $originalname, $parentfoldername, $data->title);
        }
    }
    else if ($data->title == $originalname) {
        $result['message'] = get_string('uploadoffilecomplete', 'artefact.file', $originalname);
    }
    else {
        $result['message'] = get_string('fileuploadedas', 'artefact.file', $originalname, $data->title);
    }

    if ($resizeattempted && !$resized) {
        $result['message'] .= get_string('insufficientmemoryforresize', 'artefact.file');
    }

    $result['highlight'] = $newid;
    $artefact = artefact_instance_from_id($newid);
    $result['artefacttype'] = $artefact->get('artefacttype');
    $result['uploaded'] = true;
    $result['newlist'] = pieform_element_filebrowser_build_filelist($form, $element, $parentfolder, $newid);
    if (defined('GROUP')) {
        $group = group_current_group();
        $result['quota'] = $group->quota;
        $result['quotaused'] = $group->quotaused;
    }
    else {
        $result['quota'] = $USER->get('quota');
        $result['quotaused'] = $USER->get('quotaused');
    }
    $result['maxuploadsize'] = display_size(get_max_upload_size(!$institution && !$group));
    return $result;
}


/**
 * Helper function used above to minimise code duplication
 */
function prepare_upload_failed_message(&$result, $exception, $parentfoldername, $title) {
    $result['error'] = true;
    if ($parentfoldername) {
        $result['message'] = get_string('uploadoffiletofolderfailed', 'artefact.file',
                                        $title, $parentfoldername);
    }
    else {
        $result['message'] = get_string('uploadoffilefailed', 'artefact.file',  $title);
    }
    $result['message'] .= ': ' . $exception->getMessage();
}


function pieform_element_filebrowser_createfolder(Pieform $form, $element, $data) {
    global $USER;

    $parentfolder     = $data['folder'] ? (int) $data['folder'] : null;
    $institution      = $form->get_property('institution');
    $group            = $form->get_property('group');

    $result = array();

    $data = (object) array(
        'parent'      => $parentfolder,
        'owner'       => null,
        'title'       => trim($data['title']),
    );

    if ($parentfolder) {
        $parentartefact = artefact_instance_from_id($parentfolder);
        if (!$USER->can_edit_artefact($parentartefact)) {
            return array('error' => true, 'message' => get_string('cannoteditfolder', 'artefact.file'));
        }
        else if ($parentartefact->get('locked')) {
            return array('error' => true, 'message' => get_string('cannoteditfoldersubmitted', 'artefact.file'));
        }
    }
    if ($institution) {
        $data->institution = $institution;
    }
    else if ($group) {
        if (!group_within_edit_window($group)) {
            return array('error' => true, 'message' => get_string('cannoteditfolder', 'artefact.file'));
        }
        if (!$parentfolder) {
            if (!pieform_element_filebrowser_edit_group_folder($group, 0)) {
                return array('error' => true, 'message' => get_string('cannoteditfolder', 'artefact.file'));
            }
        }
        $data->group = $group;
    }
    else {
        $data->owner = $USER->get('id');
    }

    if ($oldid = ArtefactTypeFileBase::file_exists($data->title, $data->owner, $parentfolder, $institution, $group)) {
        return array('error' => true, 'message' => get_string('fileexists', 'artefact.file'));
    }

    $f = new ArtefactTypeFolder(0, $data);
    $f->set('dirty', true);
    $f->commit();

    return array(
        'error'     => false,
        'message'   => get_string('foldercreated', 'artefact.file'),
        'highlight' => $f->get('id'),
        'newlist'   => pieform_element_filebrowser_build_filelist($form, $element, $parentfolder, $f->get('id')),
        'foldercreated' => true,
    );
}


function pieform_element_filebrowser_update(Pieform $form, $element, $data) {
    global $USER;
    $collide = !empty($data['collide']) ? $data['collide'] : 'fail';

    try {
        $artefact = artefact_instance_from_id($data['artefact']);
    }
    catch (ArtefactNotFoundException $e) {
        $parentfolder = $element['folder'] ? $element['folder'] : null;
        $result = array(
            'error'   => true,
            'message' => get_string('editingfailed', 'artefact.file'),
            'newlist' => pieform_element_filebrowser_build_filelist($form, $element, $parentfolder),
        );
        return $result;
    }

    if (!$USER->can_edit_artefact($artefact) || $artefact->get('locked')) {
        return array('error' => true, 'message' => get_string('noeditpermission', 'mahara'));
    }

    if ($existingid = ArtefactTypeFileBase::file_exists($data['title'], $artefact->get('owner'), $data['folder'],
                                                        $artefact->get('institution'), $artefact->get('group'))) {
        if ($existingid != $data['artefact']) {
            if ($collide == 'replace') {
                log_debug('deleting ' . $existingid);
                $copy = artefact_instance_from_id($existingid);
                $copy->delete();
            }
            else {
                return array('error' => true, 'message' => get_string('fileexists', 'artefact.file'));
            }
        }
    }

    $artefact->set('title', trim($data['title']));
    $artefact->set('description', $data['description']);
    $artefact->set('allowcomments', (int) $data['allowcomments']);

    $oldtags = $artefact->get('tags');
    $newtags = preg_split("/\s*,\s*/", trim($data['tags']));
    $updatetags = $oldtags != $newtags;
    if ($updatetags) {
        $artefact->set('tags', $newtags);
    }

    if (get_config('licensemetadata')) {
        foreach (array('license', 'licensor', 'licensorurl') as $licensef) {
            if ($data[$licensef] !== null) {
                $data[$licensef] = trim($data[$licensef]);
                if ($artefact->get($licensef) !== $data[$licensef]) {
                    $artefact->set($licensef, $data[$licensef]);
                }
            }
        }
    }

    if ($form->get_property('group') && $data['permissions']) {
        $artefact->set('rolepermissions', $data['permissions']);
    }
    $artefact->commit();

    $prefix = $form->get_name() . '_' . $element['name'];
    $newtabdata = (isset($element['tabs']) ? pieform_element_filebrowser_configure_tabs($element['tabs'], $prefix) : null);

    $group = null;
    $institution = null;
    $user = null;
    if (!empty($element['tabs'])) {
        $newtabdata = pieform_element_filebrowser_configure_tabs($element['tabs'], $prefix);

        if ($newtabdata['owner'] == 'site') {
            $institution = 'mahara';
        }
        else if ($newtabdata['owner'] == 'institution') {
            $institution = $newtabdata['ownerid'];
        }
        else if ($newtabdata['owner'] == 'group') {
            $group = $newtabdata['ownerid'];
        }
        else if ($newtabdata['owner'] == 'user') {
            $user = true;
        }
    }

    $returndata = array(
        'error' => false,
        'message' => get_string('changessaved', 'artefact.file'),
        'newlist' => pieform_element_filebrowser_build_filelist($form, $element, $artefact->get('parent'), null, $user, $group, $institution),
    );

    if ($updatetags && $form->submitted_by_js()) {
        $smarty = smarty_core();
        $tagdata = tags_sideblock();
        $smarty->assign('sbdata', $tagdata);
        $returndata['tagblockhtml'] = $smarty->fetch('sideblocks/tags.tpl');
    }

    return $returndata;
}


function pieform_element_filebrowser_delete(Pieform $form, $element, $artefact) {
    global $USER;
    $institution = $form->get_property('institution');
    $group       = $form->get_property('group');

    try {
        $artefact = artefact_instance_from_id($artefact);
    }
    catch (ArtefactNotFoundException $e) {
        $parentfolder = $element['folder'] ? $element['folder'] : null;
        $result = array(
            'error'   => true,
            'message' => get_string('deletingfailed', 'artefact.file'),
            'newlist' => pieform_element_filebrowser_build_filelist($form, $element, $parentfolder),
        );
        return $result;
    }

    if (!$USER->can_edit_artefact($artefact)) {
        return array('error' => true, 'message' => get_string('nodeletepermission', 'mahara'));
    }
    if (!$artefact->can_be_deleted()) {
        return array('error' => true, 'message' => get_string('cantbedeleted', 'mahara'));
    }

    $parentfolder = $artefact->get('parent');

    // Remove the skin background and update the skin thumbs
    require_once(get_config('libroot') . 'skin.php');
    Skin::remove_background($artefact->get('id'));

    $artefact->delete();

    $result = array(
        'error' => false,
        'deleted' => true,
        'artefacttype' => $artefact->get('artefacttype'),
        'message' => get_string('filethingdeleted', 'artefact.file',
                                get_string($artefact->get('artefacttype'), 'artefact.file') . ' ' . $artefact->get('title')),
        'maxuploadsize' => display_size(get_max_upload_size(!$institution && !$group)),
        'newlist' => pieform_element_filebrowser_build_filelist($form, $element, $parentfolder),
    );

    if (defined('GROUP')) {
        $group = group_current_group();
        $result['quota'] = $group->quota;
        $result['quotaused'] = $group->quotaused;
    }
    else {
        $result['quota'] = $USER->get('quota');
        $result['quotaused'] = $USER->get('quotaused');
    }
    return $result;
}

function pieform_element_filebrowser_move(Pieform $form, $element, $data) {
    global $USER;
    $artefactid  = $data['artefact'];    // Artefact being moved
    $newparentid = $data['newparent'];   // Folder to move it to

    try {
        $artefact = artefact_instance_from_id($artefactid);
    }
    catch (ArtefactNotFoundException $e) {
        $result = array(
            'error' => true,
            'message' => get_string('movingfailed', 'artefact.file'),
            'newlist' => pieform_element_filebrowser_build_filelist($form, $element, $data['folder']),
        );
        return $result;
    }

    if (!$USER->can_edit_artefact($artefact)) {
        return array('error' => true, 'message' => get_string('movefailednotowner', 'artefact.file'));
    }
    if (!in_array($artefact->get('artefacttype'), PluginArtefactFile::get_artefact_types())) {
        return array('error' => true, 'message' => get_string('movefailednotfileartefact', 'artefact.file'));
    }

    if ($newparentid > 0) {
        if ($newparentid == $artefactid) {
            return array('error' => true, 'message' => get_string('movefaileddestinationinartefact', 'artefact.file'));
        }
        if ($newparentid == $artefact->get('parent')) {
            return array('error' => false, 'message' => get_string('filealreadyindestination', 'artefact.file'));
        }
        try {
            $newparent = artefact_instance_from_id($newparentid);
        }
        catch (ArtefactNotFoundException $e) {
            $parentfolder = $element['folder'] ? $element['folder'] : null;
            $result = array(
                'error' => true,
                'message' => get_string('movingfailed', 'artefact.file'),
                'newlist' => pieform_element_filebrowser_build_filelist($form, $element, $data['folder']),
            );
            return $result;
        }
        if (!$USER->can_edit_artefact($newparent)) {
            return array('error' => true, 'message' => get_string('movefailednotowner', 'artefact.file'));
        }
        else if ($newparent->get('locked')) {
            return array('error' => true, 'message' => get_string('cannoteditfoldersubmitted', 'artefact.file'));
        }
        $group = $artefact->get('group');
        if ($group && $group !== $newparent->get('group')) {
            return array('error' => true, 'message' => get_string('movefailednotowner', 'artefact.file'));
        }
        if ($newparent->get('artefacttype') != 'folder') {
            return array('error' => true, 'message' => get_string('movefaileddestinationnotfolder', 'artefact.file'));
        }
        $nextparentid = $newparent->get('parent');
        while (!empty($nextparentid)) {
            if ($nextparentid != $artefactid) {
                $ancestor = artefact_instance_from_id($nextparentid);
                $nextparentid = $ancestor->get('parent');
            }
            else {
                return array('error' => true, 'message' => get_string('movefaileddestinationinartefact', 'artefact.file'));
            }
        }
    }
    else { // $newparentid === 0
        if ($artefact->get('parent') == null) {
            return array('error' => false, 'message' => get_string('filealreadyindestination', 'artefact.file'));
        }
        $group = $artefact->get('group');
        if ($group) {
            if (!pieform_element_filebrowser_edit_group_folder($group, 0)) {
                return array('error' => true, 'message' => get_string('movefailednotowner', 'artefact.file'));
            }
        }
        $newparentid = null;
    }

    if ($oldparentid = $artefact->get('parent')) {
        $oldparent = artefact_instance_from_id($oldparentid);
        if ($oldparent->get('locked')) {
            return array('error' => true, 'message' => get_string('cannotremovefromsubmittedfolder', 'artefact.file'));
        }
    }


    if ($artefact->move($newparentid)) {
        return array(
            'error' => false,
            'newlist' => pieform_element_filebrowser_build_filelist($form, $element, $data['folder']),
        );
    }
    return array('error' => true, 'message' => get_string('movefailed', 'artefact.file'));
}


function pieform_element_filebrowser_edit_group_folder($group, $folder) {
    global $USER;
    if ($folder) {
        if (!$folder instanceof ArtefactTypeFolder) {
            $folder = new ArtefactTypeFolder($folder);
        }
        return $USER->can_edit_artefact($folder);
    }
    require_once(get_config('libroot') . 'group.php');
    // Group root directory: use default grouptype artefact permissions
    if (!$role = group_user_access($group)) {
        return false;
    }
    $permissions = group_get_default_artefact_permissions($group);
    return $permissions[$role]->edit;
}


function pieform_element_filebrowser_view_group_folder($group, $folder) {
    global $USER;
    if ($folder) {
        if (!$folder instanceof ArtefactTypeFolder) {
            $folder = new ArtefactTypeFolder($folder);
        }
        return $USER->can_view_artefact($folder);
    }
    require_once(get_config('libroot') . 'group.php');
    // Group root directory: use default grouptype artefact permissions
    if (!$role = group_user_access($group)) {
        return false;
    }
    $permissions = group_get_default_artefact_permissions($group);
    return $permissions[$role]->view;
}


function pieform_element_filebrowser_changeowner(Pieform $form, $element) {
    $prefix = $form->get_name() . '_' . $element['name'];
    $newtabdata = pieform_element_filebrowser_configure_tabs($element['tabs'], $prefix);
    $smarty = smarty_core();
    $smarty->assign('prefix', $prefix);
    $smarty->assign('querybase', $element['page'] . (strpos($element['page'], '?') === false ? '?' : '&'));
    $smarty->assign('tabs', $newtabdata);
    $newtabhtml = $smarty->fetch('artefact:file:form/ownertabs.tpl');
    $newsubtabhtml = $smarty->fetch('artefact:file:form/ownersubtabs.tpl');

    $group = null;
    $institution = null;
    $user = null;
    $userid = null;
    $folder = 0;
    if ($newtabdata['owner'] == 'site') {
        global $USER;
        if (!$USER->get('admin')) {
            $folder = ArtefactTypeFolder::admin_public_folder_id();
        }
        $institution = 'mahara';
    }
    else if ($newtabdata['owner'] == 'institution') {
        $institution = $newtabdata['ownerid'];
    }
    else if ($newtabdata['owner'] == 'group') {
        $group = $newtabdata['ownerid'];
    }
    else if ($newtabdata['owner'] == 'user') {
        $user = true;
        $userid = $newtabdata['ownerid'];
    }

    return array(
        'error'         => false,
        'changedowner'  => true,
        'changedfolder' => true,
        'editmeta'      =>  (int) ($user && !$element['config']['edit'] && !empty($element['config']['tag'])),
        'newtabdata'    => $newtabdata,
        'folder'        => $folder,
        'disableedit'   => $group && !pieform_element_filebrowser_edit_group_folder($group, $folder),
        'newlist'       => pieform_element_filebrowser_build_filelist($form, $element, $folder, null, $user, $group, $institution),
        'newpath'       => pieform_element_filebrowser_build_path($form, $element, $folder, $newtabdata['owner'], $newtabdata['ownerid']),
        'newtabs'       => $newtabhtml,
        'newsubtabs'    => $newsubtabhtml,
    );
}


function pieform_element_filebrowser_changefolder(Pieform $form, $element, $folder) {
    $owner = $ownerid = $group = $institution = $user = null;

    $prefix = $form->get_name() . '_' . $element['name'];

    if (isset($element['tabs'])) {
        if ($owner = param_variable($prefix . '_owner', null)) {
            if ($owner == 'site') {
                $owner = 'institution';
                $institution = $ownerid = 'mahara';
            }
            else if ($ownerid = param_variable($prefix . '_ownerid', null)) {
                if ($owner == 'group') {
                    $group = (int) $ownerid;
                }
                else if ($owner == 'institution') {
                    $institution = $ownerid;
                }
                else if ($owner == 'user') {
                    $user = true;
                }
            }
        }
    }

    // If changing to a group folder, check whether the user can edit it
    if ($g = ($owner ? $group : $form->get_property('group'))) {
        if (!pieform_element_filebrowser_view_group_folder($g, $folder)) {
            return array(
                'error'   => true,
                'message' => get_string('cannotviewfolder', 'artefact.file'),
            );
        }
        $editgroupfolder = pieform_element_filebrowser_edit_group_folder($g, $folder);
    }

    return array(
        'error'         => false,
        'changedfolder' => true,
        'folder'        => $folder,
        'disableedit'   => isset($editgroupfolder) && $editgroupfolder == false,
        'newlist'       => pieform_element_filebrowser_build_filelist($form, $element, $folder, null, $user, $group, $institution),
        'newpath'       => pieform_element_filebrowser_build_path($form, $element, $folder, $owner, $ownerid),
    );
}


function pieform_element_filebrowser_views_js(Pieform $form, $element) {
    global $_PIEFORM_FILEBROWSERS;
    $formname = $form->get_name();
    $prefix = $formname . '_' . $element['name'];
    return $_PIEFORM_FILEBROWSERS[$prefix]['views_js'] . " {$prefix}.init();";
}


/**
 * When the element exists in a form that's present when the page is
 * first generated the following function gets called and the js file
 * below will be inserted into the head data.  Unfortunately, when
 * this element is present in a form that gets called in an ajax
 * request (currently on the view layout page), the .js file is not
 * loaded and so it's added explicitly to the smarty() call.
 */
function pieform_element_filebrowser_get_headdata($element) {
    global $THEME;
    $headdata = array('<script type="text/javascript" src="' . get_config('wwwroot') . 'artefact/file/js/filebrowser.js"></script>');
    if ($element['config']['upload']) {
        // only add dropzone if filebrowser is allowed to upload
        $headdata[] = '<script type="text/javascript" src="' . get_config('wwwroot') . 'js/dropzone/dropzone.min.js"></script>';
        $headdata[] = '<link href="' . get_config('wwwroot') . 'js/dropzone/css/dropzone.css" type="text/css" rel="stylesheet">';
        $headdata[] = '<script type="text/javascript" src="' . get_config('wwwroot') . 'artefact/file/js/filedropzone.js"></script>';
    }
    $strings = PluginArtefactFile::jsstrings('filebrowser');
    $jsstrings = '';
    foreach ($strings as $section => $sectionstrings) {
        foreach ($sectionstrings as $s) {
            $jsstrings .= "strings.$s=" . json_encode(get_raw_string($s, $section)) . ';';
        }
    }
    $headdata[] = '<script type="text/javascript">' . $jsstrings . '</script>';

    $pluginsheets = $THEME->get_url('style/style.css', true, 'artefact/file');
    foreach (array_reverse($pluginsheets) as $sheet) {
        $headdata[] = '<link rel="stylesheet" type="text/css" href="' . $sheet . '">';
    }

    return $headdata;
}


function pieform_element_filebrowser_set_attributes($element) {/*{{{*/
    $element['needsmultipart'] = true;
    return $element;
}/*}}}*/
