<?php
/**
 *
 * @package    mahara
 * @subpackage admin
 * @author     Catalyst IT Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL version 3 or later
 * @copyright  For copyright information on Mahara, please see the README file distributed with this software.
 *
 */

define('INTERNAL', 1);
define('INSTITUTIONALADMIN', 1);
define('MENUITEM', 'configusers/usersearch');
require(dirname(dirname(dirname(__FILE__))) . '/init.php');
define('TITLE', get_string('accountsettings', 'admin'));
define('SECTION_PLUGINTYPE', 'core');
define('SECTION_PLUGINNAME', 'admin');
require_once('pieforms/pieform.php');
require_once('activity.php');
require_once(get_config('docroot') . 'lib/antispam.php');

$id = param_integer('id');
$user = new User;
$user->find_by_id($id);
$authobj = AuthFactory::create($user->authinstance);

if (!$USER->is_admin_for_user($user)) {
    $SESSION->add_error_msg(get_string('youcannotadministerthisuser', 'admin'));
    redirect(profile_url($user));
}

if ($user->deleted) {
    $smarty = smarty();
    $smarty->assign('PAGEHEADING', TITLE . ': ' . display_name($user));
    $smarty->assign('message', get_string('thisuserdeleted', 'admin'));
    $smarty->display('message.tpl');
    exit;
}

// Site-wide account settings
$currentdate = getdate();
$elements = array();
$elements['id'] = array(
    'type'    => 'hidden',
    'rules'   => array('integer' => true),
    'value'   => $id,
);

if (method_exists($authobj, 'change_username')) {
    $elements['username'] = array(
        'type'         => 'text',
        'title'        => get_string('changeusername', 'admin'),
        'description'  => get_string('changeusernamedescription', 'admin'),
        'defaultvalue' => $user->username,
        'rules' => array(
            'maxlength' => 236,
         ),
    );
}

if (method_exists($authobj, 'change_password')) {
    // Only show the password options if the plugin allows for the functionality
    $elements['password'] = array(
        'type'         => 'password',
        'title'        => get_string('resetpassword','admin'),
        'description'  => get_string('resetpassworddescription','admin'),
    );

    $elements['passwordchange'] = array(
        'type'         => 'checkbox',
        'title'        => get_string('forcepasswordchange','admin'),
        'description'  => get_string('forcepasswordchangedescription','admin'),
        'defaultvalue' => $user->passwordchange,
    );
}
if ($USER->get('admin')) {
    $elements['staff'] = array(
        'type'         => 'checkbox',
        'title'        => get_string('sitestaff','admin'),
        'defaultvalue' => $user->staff,
        'help'         => true,
    );
    $elements['admin'] = array(
        'type'         => 'checkbox',
        'title'        => get_string('siteadmin','admin'),
        'defaultvalue' => $user->admin,
        'help'         => true,
    );
}
$elements['maildisabled'] = array(
    'type' => 'checkbox',
    'defaultvalue' => get_account_preference($user->id, 'maildisabled'),
    'title' => get_string('disableemail', 'admin'),
    'help' => true,
);
$elements['expiry'] = array(
    'type'         => 'date',
    'title'        => get_string('accountexpiry', 'admin'),
    'description'  => get_string('accountexpirydescription', 'admin'),
    'minyear'      => $currentdate['year'] - 2,
    'maxyear'      => $currentdate['year'] + 20,
    'defaultvalue' => $user->expiry
);
$quotaused = get_string('quotaused', 'admin') . ': ' . display_size($user->quotaused);
if ($USER->get('admin') || get_config_plugin('artefact', 'file', 'institutionaloverride')) {
    $elements['quota'] = array(
        'type'         => 'bytes',
        'title'        => get_string('filequota1','admin'),
        'description'  => get_string('filequotadescription','admin') . '<br>' . $quotaused,
        'rules'        => array('integer' => true),
        'defaultvalue' => $user->quota,
    );
}
else {
    $elements['quota'] = array(
        'type'         => 'text',
        'disabled'     => true,
        'title'        => get_string('filequota1', 'admin'),
        'description'  => get_string('filequotadescription', 'admin') . '<br>' . $quotaused,
        'value'        => display_size($user->quota),
    );
}

// Probation points
if (is_using_probation($user->id)) {
    $elements['probationpoints'] = array(
        'type' => 'select',
        'title' => get_string('probationtitle', 'admin'),
        'help' => true,
        'options' => probation_form_options(),
        'defaultvalue' => ensure_valid_probation_points($user->probation),
    );
}

$authinstances = auth_get_auth_instances();
if (count($authinstances) > 1) {
    $options = array();

    // NOTE: This is a little broken at the moment. The "username in the remote
    // system" setting is only actively used by the XMLRPC authentication
    // plugin, and thus only makes sense when the user is authenticating in
    // this manner.
    //
    // We hope to one day make it possible for users to get into accounts via
    // multiple methods, at which time we can tie the username-in-remote-system
    // setting to the XMLRPC plugin only, making the UI a bit more consistent
    $external = false;
    $externalauthjs = array();
    foreach ($authinstances as $authinstance) {
        // If a user has a "No Institution" auth method (institution "mahara", id = 1) and he belongs to an Institution,
        // his Institution Admin will be able to change his auth method away to one of the Institution's auth methods
        // that's the second part of the "if"
        if ($USER->can_edit_institution($authinstance->name) || ($authinstance->id == 1 && $user->authinstance == 1)) {
            $options[$authinstance->id] = $authinstance->displayname . ': ' . $authinstance->instancename;
            $authobj = AuthFactory::create($authinstance->id);
            if ($authobj->needs_remote_username()) {
                $externalauthjs[] = $authinstance->id;
                $external = true;
            }
        }
    }

    if (isset($options[$user->authinstance])) {
        $elements['authinstance'] = array(
            'type'         => 'select',
            'title'        => get_string('authenticatedby', 'admin'),
            'description'  => get_string('authenticatedbydescription', 'admin'),
            'options'      => $options,
            'defaultvalue' => $user->authinstance,
            'help'         => true,
        );
        $un = get_field('auth_remote_user', 'remoteusername', 'authinstance', $user->authinstance, 'localusr', $user->id);
        $elements['remoteusername'] = array(
            'type'         => 'text',
            'title'        => get_string('remoteusername', 'admin'),
            'description'  => get_string('remoteusernamedescription1', 'admin', hsc(get_config('sitename'))),
            'help'         => true,
        );
        if ($un) {
            $elements['remoteusername']['defaultvalue'] = $un;
        }
    }
    $remoteusernames = json_encode(get_records_menu('auth_remote_user', 'localusr', $id));
    $js = "<script type='text/javascript'>
          var externalauths = ['" . implode("','", $externalauthjs) . "'];
          var remoteusernames = " . $remoteusernames . ";
          jQuery(document).ready(function() {
          // set up initial display
          var authinstanceid = jQuery('#edituser_site_authinstance :selected').val();
          is_external(authinstanceid);

          // update display as auth method dropdown changes
          jQuery('#edituser_site_authinstance').change(function() {
              authinstanceid = jQuery('#edituser_site_authinstance :selected').val();
              is_external(authinstanceid);
          });

          function is_external(id) {
              if (jQuery.inArray(authinstanceid,externalauths) != -1) {
                  // is external option so show external auth field and help text rows
                  jQuery('#edituser_site_remoteusername_container').css('display','table-row');
                  jQuery('#edituser_site_remoteusername_container').next('tr').css('display','table-row');
                  if (remoteusernames[id]) {
                      // if value exists in auth_remote_user display it
                      jQuery('#edituser_site_remoteusername').val(remoteusernames[id]);
                  }
                  else {
                      jQuery('#edituser_site_remoteusername').val('');
                  }
              }
              else {
                  // is internal option so hide external auth field and help text rows
                  jQuery('#edituser_site_remoteusername_container').css('display','none');
                  jQuery('#edituser_site_remoteusername_container').next('tr').css('display','none');
              }
          }
      });
      </script>";

    $elements['externalauthjs'] = array(
        'type'         => 'html',
        'value'        => $js,
    );
}

$tags = get_column_sql('SELECT tag FROM {usr_tag} WHERE usr = ? AND NOT tag ' . db_ilike() . " 'lastinstitution:%'", array($user->id));

$elements['tags'] = array(
    'defaultvalue' => $tags,
    'type'         => 'tags',
    'title'        => get_string('tags'),
    'description'  => get_string('tagsdesc'),
    'help'         => true,
);

$elements['submit'] = array(
    'type'  => 'submit',
    'value' => get_string('savechanges','admin'),
);

$siteform = pieform(array(
    'name'       => 'edituser_site',
    'renderer'   => 'table',
    'plugintype' => 'core',
    'pluginname' => 'admin',
    'elements'   => $elements,
));

function edituser_site_validate(Pieform $form, $values) {
    global $USER, $SESSION;
    if (!$user = get_record('usr', 'id', $values['id'])) {
        return false;
    }
    if ($USER->get('admin') || get_config_plugin('artefact', 'file', 'institutionaloverride')) {
        $maxquotaenabled = get_config_plugin('artefact', 'file', 'maxquotaenabled');
        $maxquota = get_config_plugin('artefact', 'file', 'maxquota');
        if ($maxquotaenabled && $values['quota'] > $maxquota) {
            $form->set_error('quota', get_string('maxquotaexceededform', 'artefact.file', display_size($maxquota)));
            $SESSION->add_error_msg(get_string('maxquotaexceeded', 'artefact.file', display_size($maxquota)));
        }
    }

    $userobj = new User();
    $userobj = $userobj->find_by_id($user->id);

    if (isset($values['username']) && !empty($values['username']) && $values['username'] != $userobj->username) {

        if (!isset($values['authinstance'])) {
            $authobj = AuthFactory::create($userobj->authinstance);
        }
        else {
            $authobj = AuthFactory::create($values['authinstance']);
        }

        if (method_exists($authobj, 'change_username')) {

            if (method_exists($authobj, 'is_username_valid_admin')) {
                if (!$authobj->is_username_valid_admin($values['username'])) {
                    $form->set_error('username', get_string('usernameinvalidadminform', 'auth.internal'));
                }
            }
            else if (method_exists($authobj, 'is_username_valid')) {
                if (!$authobj->is_username_valid($values['username'])) {
                    $form->set_error('username', get_string('usernameinvalidform', 'auth.internal'));
                }
            }

            if (!$form->get_error('username') && record_exists_select('usr', 'LOWER(username) = ?', strtolower($values['username']))) {
                $form->set_error('username', get_string('usernamealreadytaken', 'auth.internal'));
            }
        }
        else {
            $form->set_error('username', get_string('usernamechangenotallowed', 'admin'));
        }
    }

    // Check that the external username isn't already in use by someone else
    if (isset($values['authinstance']) && isset($values['remoteusername'])) {
        // there are 4 cases for changes on the page
        // 1) ai and remoteuser have changed
        // 2) just ai has changed
        // 3) just remoteuser has changed
        // 4) the ai changes and the remoteuser is wiped - this is a delete of the old ai-remoteuser

        // determine the current remoteuser
        $current_remotename = get_field('auth_remote_user', 'remoteusername',
                                        'authinstance', $user->authinstance, 'localusr', $user->id);
        if (!$current_remotename) {
            $current_remotename = $user->username;
        }
        // what should the new remoteuser be
        $new_remoteuser = get_field('auth_remote_user', 'remoteusername',
                                    'authinstance', $values['authinstance'], 'localusr', $user->id);
        if (!$new_remoteuser) {
            $new_remoteuser = $user->username;
        }
        if (strlen(trim($values['remoteusername'])) > 0) {
            // value changed on page - use it
            if ($values['remoteusername'] != $current_remotename) {
                $new_remoteuser = $values['remoteusername'];
            }
        }

        // what really counts is who owns the target remoteuser slot
        $target_owner = get_field('auth_remote_user', 'localusr',
                                  'authinstance', $values['authinstance'], 'remoteusername', $new_remoteuser);
        // target remoteuser is owned by someone else
        if ($target_owner && $target_owner != $user->id) {
            $usedbyuser = get_field('usr', 'username', 'id', $target_owner);
            $SESSION->add_error_msg(get_string('duplicateremoteusername', 'auth', $usedbyuser));
            $form->set_error('remoteusername', get_string('duplicateremoteusernameformerror', 'auth'));
        }
    }
}

function edituser_site_submit(Pieform $form, $values) {
    global $USER, $authobj, $SESSION;

    if (!$user = get_record('usr', 'id', $values['id'])) {
        return false;
    }

    if (is_using_probation()) {
        // Value should be between 0 and 10 inclusive
        $user->probation = ensure_valid_probation_points($values['probationpoints']);
    }

    if ($USER->get('admin') || get_config_plugin('artefact', 'file', 'institutionaloverride')) {
        $user->quota = $values['quota'];
        // check if the user has gone over the quota notify limit
        $quotanotifylimit = get_config_plugin('artefact', 'file', 'quotanotifylimit');
        if ($quotanotifylimit <= 0 || $quotanotifylimit >= 100) {
            $quotanotifylimit = 100;
        }
        $user->quotausedpercent = $user->quotaused / $user->quota * 100;
        $overlimit = false;
        if ($quotanotifylimit <= $user->quotausedpercent) {
            $overlimit = true;
        }
        $notified = get_field('usr_account_preference', 'value', 'field', 'quota_exceeded_notified', 'usr', $user->id);
        if ($overlimit && '1' !== $notified) {
            require_once(get_config('docroot') . 'artefact/file/lib.php');
            ArtefactTypeFile::notify_users_threshold_exceeded(array($user), false);
            // no need to email admin as we can alert them right now
            $SESSION->add_error_msg(get_string('useroverquotathreshold', 'artefact.file', display_name($user)));
        }
        else if ($notified && !$overlimit) {
            set_account_preference($user->id, 'quota_exceeded_notified', false);
        }
    }

    $unexpire = $user->expiry && strtotime($user->expiry) < time() && (empty($values['expiry']) || $values['expiry'] > time());
    $newexpiry = db_format_timestamp($values['expiry']);
    if ($user->expiry != $newexpiry) {
        $user->expiry = $newexpiry;
        if ($unexpire) {
            $user->expirymailsent = 0;
            $user->lastaccess = db_format_timestamp(time());
        }
    }

    // Try to kick the user from any active login sessions, before saving data.
    require_once(get_config('docroot') . 'auth/session.php');
    remove_user_sessions($user->id);

    if ($USER->get('admin')) {  // Not editable by institutional admins
        $user->staff = (int) ($values['staff'] == 'on');
        $user->admin = (int) ($values['admin'] == 'on');
        if ($user->admin) {
            activity_add_admin_defaults(array($user->id));
        }
    }

    if ($values['maildisabled'] == 0 && get_account_preference($user->id, 'maildisabled') == 1) {
        // Reset the sent and bounce counts otherwise mail will be disabled
        // on the next send attempt
        $u = new StdClass;
        $u->email = $user->email;
        $u->id = $user->id;
        update_bounce_count($u,true);
        update_send_count($u,true);
    }
    set_account_preference($user->id, 'maildisabled', $values['maildisabled']);

    // process the change of the authinstance and or the remoteuser
    if (isset($values['authinstance']) && isset($values['remoteusername'])) {
        // Authinstance can be changed by institutional admins if both the
        // old and new authinstances belong to the admin's institutions
        $authinst = get_records_select_assoc('auth_instance', 'id = ? OR id = ?',
                                             array($values['authinstance'], $user->authinstance));
        // But don't bother if the auth instance doesn't take a remote username
        $authobj = AuthFactory::create($values['authinstance']);
        if (
            $USER->get('admin')
            || (
                $USER->is_institutional_admin($authinst[$values['authinstance']]->institution)
                && (
                    $USER->is_institutional_admin($authinst[$user->authinstance]->institution)
                    || $user->authinstance == 1
                )
            )
        ) {
            if ($authobj->needs_remote_username()) {
                // determine the current remoteuser
                $current_remotename = get_field('auth_remote_user', 'remoteusername',
                                                'authinstance', $user->authinstance, 'localusr', $user->id);
                if (!$current_remotename) {
                    $current_remotename = $user->username;
                }
                // if the remoteuser is empty
                if (strlen(trim($values['remoteusername'])) == 0) {
                    delete_records('auth_remote_user', 'authinstance', $user->authinstance, 'localusr', $user->id);
                }
                // what should the new remoteuser be
                $new_remoteuser = get_field('auth_remote_user', 'remoteusername',
                                            'authinstance', $values['authinstance'], 'localusr', $user->id);
                // save the remotename for the target existence check
                $target_remotename = $new_remoteuser;
                if (!$new_remoteuser) {
                    $new_remoteuser = $user->username;
                }
                if (strlen(trim($values['remoteusername'])) > 0) {
                    // value changed on page - use it
                    if ($values['remoteusername'] != $current_remotename) {
                        $new_remoteuser = $values['remoteusername'];
                    }
                }
                // only update remote name if the input actually changed on the page  or it doesn't yet exist
                if ($current_remotename != $new_remoteuser || !$target_remotename) {
                    // only remove the ones related to this traget authinstance as we now allow multiple
                    // for dual login mechanisms
                    delete_records('auth_remote_user', 'authinstance', $values['authinstance'], 'localusr', $user->id);
                    insert_record('auth_remote_user', (object) array(
                        'authinstance'   => $values['authinstance'],
                        'remoteusername' => $new_remoteuser,
                        'localusr'       => $user->id,
                    ));
                }
            }
            // update the ai on the user master
            $user->authinstance = $values['authinstance'];

            // update the global $authobj to match the new authinstance
            // this is used by the password/username change methods
            // if either/both has been requested at the same time
            $authobj = AuthFactory::create($user->authinstance);
        }
    }

    // Only change the pw if the new auth instance allows for it
    if (method_exists($authobj, 'change_password')) {
        $user->passwordchange = (int) (isset($values['passwordchange']) && $values['passwordchange'] == 'on' ? 1 : 0);

        if (isset($values['password']) && $values['password'] !== '') {
            $userobj = new User();
            $userobj = $userobj->find_by_id($user->id);

            $user->password = $authobj->change_password($userobj, $values['password']);
            $user->salt = $userobj->salt;

            unset($userobj);
        }
    } else {
        // inform the user that the chosen auth instance doesn't allow password changes
        // but only if they tried changing it
        if (isset($values['password']) && $values['password'] !== '') {
            $SESSION->add_error_msg(get_string('passwordchangenotallowed', 'admin'));

            // Set empty pw with salt
            $user->password = '';
            $user->salt = auth_get_random_salt();
        }
    }

    if (isset($values['username']) && $values['username'] !== '') {
        $userobj = new User();
        $userobj = $userobj->find_by_id($user->id);

        if ($userobj->username != $values['username']) {
            // Only change the username if the auth instance allows for it
            if (method_exists($authobj, 'change_username')) {
                // check the existence of the chosen username
                try {
                    if ($authobj->user_exists($values['username'])) {
                        // set an error message if it is already in use
                        $SESSION->add_error_msg(get_string('usernameexists', 'account'));
                    }
                } catch (AuthUnknownUserException $e) {
                    // update the username otherwise
                    $user->username = $authobj->change_username($userobj, $values['username']);
                }
            } else {
                // inform the user that the chosen auth instance doesn't allow username changes
                $SESSION->add_error_msg(get_string('usernamechangenotallowed', 'admin'));
            }
        }

        unset($userobj);
    }

    db_begin();
    update_record('usr', $user);

    delete_records('usr_tag', 'usr', $user->id);
    if (is_array($values['tags'])) {
        $values['tags'] = check_case_sensitive($values['tags'], 'usr_tag');
        foreach(array_unique($values['tags']) as $tag) {
            if (empty($tag)) {
                continue;
            }
            insert_record(
                'usr_tag',
                (object) array(
                    'usr' => $user->id,
                    'tag' => strtolower($tag),
                )
            );
        }
    }
    db_commit();
    $SESSION->add_ok_msg(get_string('usersitesettingschanged', 'admin'));
    redirect('/admin/users/edit.php?id='.$user->id);
}


// Suspension/deletion controls
$suspended = $user->get('suspendedcusr');
if (empty($suspended)) {
    $suspendform = pieform(array(
        'name'       => 'edituser_suspend',
        'plugintype' => 'core',
        'pluginname' => 'admin',
        'elements'   => array(
            'id' => array(
                 'type'    => 'hidden',
                 'value'   => $id,
            ),
            'reason' => array(
                'type'        => 'textarea',
                'rows'        => 5,
                'cols'        => 28,
                'title'       => get_string('reason'),
                'description' => get_string('suspendedreasondescription', 'admin'),
            ),
            'submit' => array(
                'type'  => 'submit',
                'value' => get_string('suspenduser','admin'),
            ),
        )
    ));
}
else {
    $suspendformdef = array(
        'name'       => 'edituser_unsuspend',
        'plugintype' => 'core',
        'pluginname' => 'admin',
        'renderer'   => 'oneline',
        'elements'   => array(
            'id' => array(
                 'type'    => 'hidden',
                 'value'   => $id,
            ),
            'submit' => array(
                'type'  => 'submit',
                'value' => get_string('unsuspenduser','admin'),
            ),
        )
    );

    // Create two forms for unsuspension - one in the suspend message and the
    // other where the 'suspend' button normally goes. This keeps the HTML IDs
    // unique
    $suspendform  = pieform($suspendformdef);
    $suspendformdef['name'] = 'edituser_suspend2';
    $suspendformdef['successcallback'] = 'edituser_unsuspend_submit';
    $suspendform2 = pieform($suspendformdef);

    $suspender = display_name(get_record('usr', 'id', $suspended));
    $suspendedtime = format_date($user->get('suspendedctime'), 'strftimedate');
}

function edituser_suspend_submit(Pieform $form, $values) {
    global $SESSION, $USER, $user;
    if (!$USER->get('admin') && ($user->get('admin') || $user->get('staff'))) {
        $SESSION->add_error_msg(get_string('errorwhilesuspending', 'admin'));
    }
    else {
        suspend_user($user->get('id'), $values['reason']);
        $SESSION->add_ok_msg(get_string('usersuspended', 'admin'));
    }
    redirect('/admin/users/edit.php?id=' . $user->get('id'));
}

function edituser_unsuspend_submit(Pieform $form, $values) {
    global $SESSION;
    unsuspend_user($values['id']);
    $SESSION->add_ok_msg(get_string('userunsuspended', 'admin'));
    redirect('/admin/users/edit.php?id=' . $values['id']);
}

$deleteform = pieform(array(
    'name' => 'edituser_delete',
    'plugintype' => 'core',
    'pluginname' => 'admin',
    'renderer' => 'oneline',
    'elements'   => array(
        'id' => array(
            'type' => 'hidden',
            'value' => $id,
        ),
        'submit' => array(
            'type' => 'submit',
            'value' => get_string('deleteuser', 'admin'),
            'confirm' => get_string('confirmdeleteuser', 'admin'),
        ),
    ),
));

function edituser_delete_validate(Pieform $form, $values) {
    global $USER, $SESSION;
    if (!$USER->get('admin')) {
        $form->set_error('submit', get_string('deletefailed', 'admin'));
        $SESSION->add_error_msg(get_string('deletefailed', 'admin'));
    }
    // Check to see if there are any pending archives in the export_queue for this user.
    // We can't delete them if there are.
    if ($results = count_records('export_queue', 'usr', $values['id'])) {
        $form->set_error('submit', get_string('deletefailed', 'admin'));
        $SESSION->add_error_msg(get_string('exportqueuenotempty', 'export'));
    }
}

function edituser_delete_submit(Pieform $form, $values) {
    global $SESSION, $USER;
    if ($USER->get('admin')) {
        delete_user($values['id']);
        $SESSION->add_ok_msg(get_string('userdeletedsuccessfully', 'admin'));
    }
    redirect('/admin/users/search.php');
}


// Institution settings form
$elements = array(
    'id' => array(
         'type'    => 'hidden',
         'value'   => $id,
     ),
);

function is_institute_admin($institution) {
    return $institution->admin;
}

$institutions = $user->get('institutions');
if ( !$USER->get('admin') ) { // for institution admins
    $admin_institutions = $USER->get('institutions');
    $admin_institutions = array_filter($admin_institutions, "is_institute_admin");
    $institutions = array_intersect_key($institutions, $admin_institutions);
}

$allinstitutions = get_records_assoc('institution', '', '', 'displayname', 'name, displayname');
foreach ($institutions as $i) {
    $elements[$i->institution.'_settings'] = array(
        'type' => 'fieldset',
        'legend' => $i->displayname,
        'elements' => array(
            $i->institution.'_expiry' => array(
                'type'         => 'date',
                'title'        => get_string('membershipexpiry', 'admin'),
                'description'  => get_string('membershipexpirydescription', 'admin'),
                'minyear'      => $currentdate['year'],
                'maxyear'      => $currentdate['year'] + 20,
                'defaultvalue' => $i->membership_expiry
            ),
            $i->institution.'_studentid' => array(
                'type'         => 'text',
                'title'        => get_string('studentid', 'admin'),
                'description'  => get_string('institutionstudentiddescription', 'admin'),
                'defaultvalue' => $i->studentid,
            ),
            $i->institution.'_staff' => array(
                'type'         => 'checkbox',
                'title'        => get_string('institutionstaff','admin'),
                'defaultvalue' => $i->staff,
            ),
            $i->institution.'_admin' => array(
                'type'         => 'checkbox',
                'title'        => get_string('institutionadmin','admin'),
                'description'  => get_string('institutionadmindescription','admin'),
                'defaultvalue' => $i->admin,
            ),
            $i->institution.'_submit' => array(
                'type'  => 'submit',
                'value' => get_string('update'),
            ),
            $i->institution.'_remove' => array(
                'type'  => 'submit',
                'value' => get_string('removeuserfrominstitution', 'admin'),
                'confirm' => get_string('confirmremoveuserfrominstitution', 'admin'),
            ),
        ),
    );
}

// Only site admins can add institutions; institutional admins must invite
if ($USER->get('admin')
    && (get_config('usersallowedmultipleinstitutions') || count($user->institutions) == 0)) {
    $options = array();
    foreach ($allinstitutions as $i) {
        if (!$user->in_institution($i->name) && $i->name != 'mahara') {
            $options[$i->name] = $i->displayname;
        }
    }
    if (!empty($options)) {
        $elements['addinstitutionheader'] = array(
            'type'  => 'markup',
            'value' => '<tr><td colspan="2"><h4>' . get_string('addusertoinstitution', 'admin') . '</h4></td></tr>',
        );
        $elements['addinstitution'] = array(
            'type'         => 'select',
            'title'        => get_string('institution'),
            'options'      => $options,
        );
        $elements['add'] = array(
            'type'  => 'submit',
            'value' => get_string('addusertoinstitution', 'admin'),
        );
    }
}

$institutionform = pieform(array(
    'name'       => 'edituser_institution',
    'renderer'   => 'table',
    'plugintype' => 'core',
    'pluginname' => 'admin',
    'elements'   => $elements,
));

function edituser_institution_validate(Pieform $form, $values) {
    $user = new User;
    if (!$user->find_by_id($values['id'])) {
        return false;
    }
    global $USER;

    $userinstitutions = $user->get('institutions');
    if (isset($values['add']) && $USER->get('admin')
        && (empty($userinstitutions) || get_config('usersallowedmultipleinstitutions'))) {
        // check if the institution is full
        require_once(get_config('docroot') . 'lib/institution.php');
        $institution = new Institution($values['addinstitution']);
        if ($institution->isFull()) {
            $institution->send_admin_institution_is_full_message();
            $form->set_error(null,get_string('institutionmaxusersexceeded', 'admin'));
        }
    }
}

function edituser_institution_submit(Pieform $form, $values) {
    $user = new User;
    if (!$user->find_by_id($values['id'])) {
        return false;
    }
    $userinstitutions = $user->get('institutions');

    global $USER, $SESSION;
    foreach ($userinstitutions as $i) {
        if ($USER->can_edit_institution($i->institution)) {
            if (isset($values[$i->institution.'_submit'])) {
                $newuser = (object) array(
                    'usr'         => $user->id,
                    'institution' => $i->institution,
                    'ctime'       => db_format_timestamp($i->ctime),
                    'studentid'   => $values[$i->institution . '_studentid'],
                    'staff'       => (int) ($values[$i->institution . '_staff'] == 'on'),
                    'admin'       => (int) ($values[$i->institution . '_admin'] == 'on'),
                );
                if ($values[$i->institution . '_expiry']) {
                    $newuser->expiry = db_format_timestamp($values[$i->institution . '_expiry']);
                }
                db_begin();
                delete_records('usr_institution', 'usr', $user->id, 'institution', $i->institution);
                insert_record('usr_institution', $newuser);
                if ($newuser->admin) {
                    activity_add_admin_defaults(array($user->id));
                }
                handle_event('updateuser', $user->id);
                db_commit();
                $SESSION->add_ok_msg(get_string('userinstitutionupdated', 'admin', $i->displayname));
                break;
            }
            else if (isset($values[$i->institution.'_remove'])) {
                if ($user->id == $USER->id) {
                    $USER->leave_institution($i->institution);
                }
                else {
                    $user->leave_institution($i->institution);
                }
                $SESSION->add_ok_msg(get_string('userinstitutionremoved', 'admin', $i->displayname));
                // Institutional admins can no longer access this page
                // if they remove the user from the institution, so
                // send them back to user search.
                if (!$USER->get('admin')) {
                    if (!$USER->is_institutional_admin()) {
                        redirect(get_config('wwwroot'));
                    }
                    redirect('/admin/users/search.php');
                }
                break;
            }
        }
    }

    if (isset($values['add']) && $USER->get('admin')
        && (empty($userinstitutions) || get_config('usersallowedmultipleinstitutions'))) {
        if ($user->id == $USER->id) {
            $USER->join_institution($values['addinstitution']);
            $USER->commit();
            $userinstitutions = $USER->get('institutions');
        }
        else {
            $user->join_institution($values['addinstitution']);
            $userinstitutions = $user->get('institutions');
        }
        $SESSION->add_ok_msg(get_string('userinstitutionjoined', 'admin', $userinstitutions[$values['addinstitution']]->displayname));
    }

    redirect('/admin/users/edit.php?id='.$user->id);
}

$smarty = smarty();
$smarty->assign('user', $user);
$smarty->assign('suspended', $suspended);
if ($suspended) {
    $smarty->assign('suspendedby', get_string('suspendedinfo', 'admin', $suspender, $suspendedtime));
}
$smarty->assign('suspendform', $suspendform);
if (isset($suspendform2)) {
    $smarty->assign('suspendform2', $suspendform2);
}
$smarty->assign('deleteform', $deleteform);
$smarty->assign('siteform', $siteform);
$smarty->assign('institutions', count($allinstitutions) > 1);
$smarty->assign('institutionform', $institutionform);

$smarty->assign('loginas', $id != $USER->get('id') && is_null($USER->get('parentuser')));
$smarty->assign('PAGEHEADING', TITLE . ': ' . display_name($user));

# Only allow deletion and suspension of a user if the viewed user is not
# the current user; or if they are the current user, they're not the only
# admin
if ($id != $USER->get('id') || count_records('usr', 'admin', 1, 'deleted', 0) > 1) {
    $smarty->assign('suspendable', ($USER->get('admin') || !$user->get('admin') && !$user->get('staff')));
    $smarty->assign('deletable', $USER->get('admin'));
}

$smarty->display('admin/users/edit.tpl');
