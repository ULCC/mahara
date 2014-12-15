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

defined('INTERNAL') || die();

// Constants for the different group roles
define('GROUP_ROLES_ALL', 1);
define('GROUP_ROLES_NONMEMBER', 2);
define('GROUP_ROLES_ADMIN', 3);

// Role related functions

/**
 * Establishes what role a user has in a given group.
 *
 * If the user is not in the group, this returns false.
 *
 * @param mixed $groupid  ID of the group to check
 * @param mixed $userid   ID of the user to check. Defaults to the logged in
 *                        user.
 * @return mixed          The role the user has in the group, or false if they
 *                        have no role in the group
 */
function group_user_access($groupid, $userid=null, $refresh=null) {
    static $result;

    if (empty($userid) && !is_logged_in()) {
        return false;
    }

    $groupid = group_param_groupid($groupid);
    $userid  = group_param_userid($userid);

    if (isset($result[$groupid][$userid]) && !isset($refresh)) {
        return $result[$groupid][$userid];
    }

    return $result[$groupid][$userid] = get_field('group_member', 'role', 'group', $groupid, 'member', $userid);
}

/**
 * Returns whether the given user is the only administrator in the given group.
 *
 * If the user isn't in the group, or they're not an admin, or there is another admin, false
 * is returned.
 *
 * @param int $groupid The ID of the group to check
 * @param int $userid  The ID of the user to check
 * @returns boolean
 */
function group_is_only_admin($groupid, $userid=null) {
    static $result;

    $groupid = group_param_groupid($groupid);
    $userid  = group_param_userid($userid);

    if (isset($result[$groupid][$userid])) {
        return $result[$groupid][$userid];
    }

    return $result[$groupid][$userid] = (group_user_access($groupid, $userid) == 'admin'
        && count_records('group_member', 'group', $groupid, 'role', 'admin') == 1);
}

/**
 * Returns whether the given user is allowed to change their role to the
 * requested role in the given group.
 *
 * This function is checking whether _role changes_ are allowed, not if a user
 * is allowed to be added to a group.
 *
 * @param int $groupid The ID of the group to check
 * @param int $userid  The ID of the user to check
 * @param string $role The role the user wishes to switch to
 * @returns boolean
 */
function group_can_change_role($groupid, $userid, $role) {
    $groupid = group_param_groupid($groupid);
    $userid  = group_param_userid($userid);

    if (!group_user_access($groupid, $userid)) {
        return false;
    }

    // Sole remaining admins can never change their role
    if (group_is_only_admin($groupid, $userid)) {
        return false;
    }

    return true;
}

/**
 * Changes a user role in a group, if this is allowed.
 *
 * @param int $groupid The ID of the group
 * @param int $userid  The ID of the user whose role needs changing
 * @param string $role The role the user wishes to switch to
 * @throws AccessDeniedException If the specified role change is not allowed.
 *                               Check with group_can_change_role first if you
 *                               need to.
 */
function group_change_role($groupid, $userid, $role) {
    // group_can_change_role checks whether the group and user parameters are valid
    if (!group_can_change_role($groupid, $userid, $role)) {
        throw new AccessDeniedException(get_string('usercannotchangetothisrole', 'group'));
    }

    set_field('group_member', 'role', $role, 'group', $groupid, 'member', $userid);
}

/**
 * Returns whether a user is allowed to edit views in a given group
 *
 * @param mixed $group The ID of the group
 * @param int $userid The ID of the user
 * @returns boolean
 */
function group_user_can_edit_views($group, $userid=null) {
    // root user can always do whatever it wants
    $sysuser = get_record('usr', 'username', 'root');
    if ($sysuser->id == $userid) {
        return true;
    }

    if (!is_logged_in()) {
        return false;
    }

    $groupid = is_numeric($group) ? group_param_groupid($group) : intval($group->id);
    $userid  = group_param_userid($userid);

    if ($role = group_user_access($groupid, $userid)) {
        return group_role_can_edit_views($group, $role);
    }
    return false;
}

function group_role_can_edit_views($group, $role) {

    if (empty($role)) {
        return false;
    }

    if ($role == 'admin') {
        return true;
    }

    if (is_numeric($group)) {
        $editroles = get_field('group', 'editroles', 'id', $group);
    }
    else if (!isset($group->editroles)) {
        $editroles = get_field('group', 'editroles', 'id', $group->id);
    }
    else {
        $editroles = $group->editroles;
    }

    if ($role == 'member') {
        return ($editroles == 'all' && group_within_edit_window($group));
    }

    return $editroles != 'admin';
}


/**
 * Determine if the current date/time is within the editable window of the
 * group if one is set. By default, a group admin is considered to be within
 * the window.
 * @param object $group the group to check
 * @param bool $admin_always whether the admin should be OK regardless of time
 */
function group_within_edit_window($group, $admin_always=true) {
    if (is_numeric($group)) {
        $group = get_record('group', 'id', $group);
    }

    if ($admin_always && group_user_access($group->id) == 'admin') {
      return true;
    }

    $start = !empty($group->editwindowstart) ? strtotime($group->editwindowstart) : null;
    $end = !empty($group->editwindowend) ? strtotime($group->editwindowend) : null;
    $now = time();

    return (empty($start) && empty($end)) ||
        (!empty($start) && $now > $start && empty($end)) ||
        (empty($start) && $now < $end && !empty($end)) ||
        ($start < $now && $now < $end);
}

function group_role_can_moderate_views($group, $role) {
    static $moderatingroles = array();

    if (empty($role)) {
        return false;
    }

    if ($role == 'admin') {
        return true;
    }

    if (!isset($moderatingroles[$group])) {
        $grouptype = get_field('group', 'grouptype', 'id', $group);
        safe_require('grouptype', $grouptype);
        $moderatingroles[$group] = call_static_method('GroupType' . ucfirst($grouptype), 'get_view_moderating_roles');
    }

    return in_array($role, $moderatingroles[$group]);
}

/**
 * Returns whether a user is allowed to see the report
 *
 * @param obj $group The group object
 * @param str $role The role of the user
 * @returns boolean
 */
function group_role_can_access_report($group, $role) {
    global $USER;

    if (!$group->groupparticipationreports) {
        return false;
    }

    if (group_user_access($group->id) && ($role == 'admin' || $USER->get('admin') || $USER->is_institutional_admin() || $USER->is_institutional_staff())) {
        return true;
    }

    return false;
}

/**
 * Returns whether a user is allowed to assess views that have been submitted
 * to the given group.
 *
 * @param int $groupid ID of group
 * @param int $userid  ID of user
 * @return boolean
 */
function group_user_can_assess_submitted_views($groupid, $userid) {
    $groupid = group_param_groupid($groupid);
    $userid  = group_param_userid($userid);

    return get_field_sql('
        SELECT
            r.see_submitted_views
        FROM
            {group_member} m
            INNER JOIN {group} g ON (m.group = g.id AND g.deleted = 0)
            INNER JOIN {grouptype_roles} r ON (g.grouptype = r.grouptype AND r.role = m.role)
        WHERE
            m.member = ?
            AND m.group = ?', array($userid, $groupid));
}

// Functions for creation/deletion of groups, and adding/removing users to groups

/**
 * Creates a group.
 *
 * All group creation should be done through this function, as the
 * implementation of group creation may change over time.
 *
 * @param array $data Data required to create the group. The following
 * key/value pairs can be specified:
 *
 * - name: The group name [required, must be unique]
 * - description: The group description [optional, defaults to empty string]
 * - grouptype: The grouptype for the new group. Must be an installed grouptype.
 * - open (jointype): anyone can join the group
 * - controlled (jointype): admin adds members; members cannot leave the group
 * - request: allows membership requests
 * - ctime: The unix timestamp of the time the group will be recorded as having
 *          been created. Defaults to the current time.
 * - members: Array of users who should be in the group, structured like this:
 *            array(
 *                userid => role,
 *                userid => role,
 *                ...
 *            )
 * @return int The ID of the created group
 */
function group_create($data) {
    if (!is_array($data)) {
        throw new InvalidArgumentException("group_create: data must be an array, see the doc comment for this "
            . "function for details on its format");
    }

    if (!isset($data['name'])) {
        throw new InvalidArgumentException("group_create: must specify a name for the group");
    }
    if (get_records_sql_array('SELECT id FROM {group} WHERE LOWER(TRIM(name)) = ?', array(strtolower(trim($data['name']))))) {
        throw new UserException(get_string('groupalreadyexists', 'group') . ': ' . $data['name']);
    }

    if (!isset($data['grouptype']) || !in_array($data['grouptype'], group_get_grouptypes())) {
        throw new InvalidArgumentException("group_create: grouptype specified must be an installed grouptype");
    }

    safe_require('grouptype', $data['grouptype']);

    if (!empty($data['open'])) {
        if (!empty($data['controlled'])) {
            throw new InvalidArgumentException("group_create: a group cannot have both open and controlled membership");
        }
        if (!empty($data['request'])) {
            throw new InvalidArgumentException("group_create: open-membership groups don't accept membership requests");
        }
        $jointype = 'open';
    }
    else if (!empty($data['controlled'])) {
        $jointype = 'controlled';
    }
    else {
        $jointype = 'approve';
    }

    if (isset($data['jointype'])) {
        log_warn("group_create: ignoring supplied jointype");
    }

    if (!isset($data['ctime'])) {
        $data['ctime'] = time();
    }
    $data['ctime'] = db_format_timestamp($data['ctime']);

    $data['public'] = (isset($data['public'])) ? intval($data['public']) : 0;
    $data['hidden'] = (isset($data['hidden'])) ? intval($data['hidden']) : 0;
    $data['hidemembers'] = (isset($data['hidemembers'])) ? intval($data['hidemembers']) : 0;
    $data['hidemembersfrommembers'] = (isset($data['hidemembersfrommembers'])) ? intval($data['hidemembersfrommembers']) : 0;
    $data['groupparticipationreports'] = (isset($data['groupparticipationreports'])) ? intval($data['groupparticipationreports']) : 0;
    $data['usersautoadded'] = (isset($data['usersautoadded'])) ? intval($data['usersautoadded']) : 0;

    $data['quota'] = get_config_plugin('artefact', 'file', 'defaultgroupquota');

    if (!empty($data['invitefriends']) && !empty($data['suggestfriends'])) {
        throw new InvalidArgumentException("group_create: a group cannot enable both invitefriends and suggestfriends");
    }
    $data['invitefriends'] = (isset($data['invitefriends'])) ? intval($data['invitefriends']) : 0;
    $data['suggestfriends'] = (isset($data['suggestfriends'])) ? intval($data['suggestfriends']) : 0;

    if (isset($data['shortname']) && strlen($data['shortname'])) {
        // This is a group whose details and membership can be updated automatically, using a
        // webservice api or possibly csv upload.

        // On updates to this group, it will be identified using the institution and shortname
        // which must be unique.

        // The $USER object will be set to someone with at least institutional admin permission.
        global $USER;

        if (empty($data['institution'])) {
            throw new SystemException("group_create: a group with a shortname must have an institution; shortname: " . $data['shortname']);
        }
        if (!$USER->can_edit_institution($data['institution'])) {
            throw new AccessDeniedException("group_create: cannot create a group in this institution");
        }
        if (!preg_match('/^[a-zA-Z0-9_.-]{2,255}$/', $data['shortname'])) {
            $message = get_string('invalidshortname', 'group') . ': ' . $data['shortname'];
            $message .= "\n" . get_string('shortnameformat', 'group');
            throw new UserException($message);
        }
        if (record_exists('group', 'shortname', $data['shortname'], 'institution', $data['institution'])) {
            throw new UserException('group_create: group with shortname ' . $data['shortname'] . ' and institution ' . $data['institution'] . ' already exists');
        }
        if (empty($data['members'])) {
            $data['members'] = array($USER->get('id') => 'admin');
        }
    }
    else {
        if (!empty($data['institution'])) {
            throw new SystemException("group_create: group institution only available for api-controlled groups");
        }
        $data['shortname'] = null;
    }

    if (get_config('cleanurls') && (!isset($data['urlid']) || strlen($data['urlid']) == 0)) {
        $data['urlid'] = generate_urlid($data['name'], get_config('cleanurlgroupdefault'), 3, 30);
        $data['urlid'] = group_get_new_homepage_urlid($data['urlid']);
    }

    if (!is_array($data['members']) || count($data['members']) == 0) {
        throw new InvalidArgumentException("group_create: at least one member must be specified for adding to the group");
    }

    if (!isset($data['submittableto'])) {
        $data['submittableto'] = $data['grouptype'] != 'standard';
    }

    if (!isset($data['editroles'])) {
        $data['editroles'] = $data['grouptype'] == 'standard' ? 'all' : 'notmember';
    }
    else if (!in_array($data['editroles'], array_keys(group_get_editroles_options()))) {
        throw new InvalidArgumentException("group_create: invalid option for page editroles setting");
    }

    if (!isset($data['editwindowstart'])) {
        $data['editwindowstart'] = null;
    }
    if (!isset($data['editwindowend'])) {
        $data['editwindowend'] = null;
    }
    if (!isset($data['sendnow'])) {
        $data['sendnow'] = null;
    }

    db_begin();

    $id = insert_record(
        'group',
        (object) array(
            'name'           => $data['name'],
            'description'    => isset($data['description']) ? $data['description'] : null,
            'urlid'          => isset($data['urlid']) ? $data['urlid'] : null,
            'grouptype'      => $data['grouptype'],
            'category'       => isset($data['category']) ? intval($data['category']) : null,
            'jointype'       => $jointype,
            'ctime'          => $data['ctime'],
            'mtime'          => $data['ctime'],
            'public'         => $data['public'],
            'usersautoadded' => $data['usersautoadded'],
            'quota'          => $data['quota'],
            'institution'    => !empty($data['institution']) ? $data['institution'] : null,
            'shortname'      => $data['shortname'],
            'request'        => isset($data['request']) ? intval($data['request']) : 0,
            'submittableto'  => intval($data['submittableto']),
            'allowarchives'  => (!empty($data['submittableto']) && !empty($data['allowarchives'])) ? intval($data['allowarchives']) : 0,
            'editroles'      => $data['editroles'],
            'hidden'         => $data['hidden'],
            'hidemembers'    => $data['hidemembers'],
            'hidemembersfrommembers' => $data['hidemembersfrommembers'],
            'groupparticipationreports' => $data['groupparticipationreports'],
            'invitefriends'  => $data['invitefriends'],
            'suggestfriends' => $data['suggestfriends'],
            'editwindowstart' => $data['editwindowstart'],
            'editwindowend'  => $data['editwindowend'],
            'sendnow'        => isset($data['sendnow']) ? $data['sendnow'] : null,
            'viewnotify'     => isset($data['viewnotify']) ? $data['viewnotify'] : null,
            'feedbacknotify' => isset($data['feedbacknotify']) ? $data['feedbacknotify'] : null,
        ),
        'id',
        true
    );

    foreach ($data['members'] as $userid => $role) {
        insert_record(
            'group_member',
            (object) array(
                'group'  => $id,
                'member' => $userid,
                'role'   => $role,
                'ctime'  => $data['ctime'],
            )
        );
    }

    // Copy views for the new group
    $templates = get_column('view_autocreate_grouptype', 'view', 'grouptype', $data['grouptype']);
    $templates = get_records_sql_array("
        SELECT v.id, v.title, v.description
        FROM {view} v
        INNER JOIN {view_autocreate_grouptype} vag ON vag.view = v.id
        LEFT JOIN {collection_view} cv ON v.id = cv.view
        WHERE vag.grouptype = 'standard'
            AND cv.view IS NULL", array());
    if ($templates) {
        require_once(get_config('libroot') . 'view.php');
        foreach ($templates as $template) {
            list($view) = View::create_from_template(array(
                'group'       => $id,
                'title'       => $template->title,
                'description' => $template->description,
            ), $template->id, null, false);
            $view->set_access(array(array(
                'type'      => 'group',
                'id'        => $id,
                'startdate' => null,
                'stopdate'  => null,
                'role'      => null
            )));
        }
    }
    // Copy collections for the new group
    $templates = get_records_sql_array("
        SELECT DISTINCT c.id, c.name
        FROM {view} v
        INNER JOIN {view_autocreate_grouptype} vag ON vag.view = v.id
        INNER JOIN {collection_view} cv ON v.id = cv.view
        INNER JOIN {collection} c ON cv.collection = c.id
        WHERE vag.grouptype = ?", array($data['grouptype']));
    if ($templates) {
        require_once('collection.php');
        foreach ($templates as $template) {
            Collection::create_from_template(array('group' => $id), $template->id, null, false, true);
        }
    }
    $data['id'] = $id;
    // install the homepage
    if ($t = get_record('view', 'type', 'grouphomepage', 'template', 1, 'owner', 0)) {
        require_once('view.php');
        $template = new View($t->id, (array)$t);
        list($homepage) = View::create_from_template(array(
            'group' => $id,
            'title' => $template->get('title'),
            'description' => $template->get('description'),
            'type' => 'grouphomepage',
        ), $t->id, 0, false);
    }
    insert_record('view_access', (object) array(
        'view'       => $homepage->get('id'),
        'accesstype' => $data['public'] ? 'public' : 'loggedin',
        'ctime'      => db_format_timestamp(time()),
    ));
    handle_event('creategroup', $data);
    db_commit();

    return $id;
}


/**
 * Update details of an existing group.
 *
 * @param array $new New values for the group table.
 * @param bool  $create Create the group if it doesn't exist yet
 */
function group_update($new, $create=false) {

    if (!empty($new->id)) {
        $old = get_record_select('group', 'id = ? AND deleted = 0', array($new->id));
    }
    else if (!empty($new->institution) && isset($new->shortname) && strlen($new->shortname)) {
        $old = get_record_select(
            'group',
            'shortname = ? AND institution = ? AND deleted = 0',
            array($new->shortname, $new->institution)
        );

        if (!$old && $create) {
            return group_create((array)$new);
        }
    }

    if (!$old) {
        throw new NotFoundException("group_update: group not found");
    }

    if (!empty($old->institution)) {
        // Api-controlled group; check permissions.
        global $USER;

        if (!$USER->can_edit_institution($old->institution)) {
            throw new AccessDeniedException("group_update: cannot update a group in this institution");
        }
    }
    if (
        (isset($new->submittableto) && empty($new->submittableto)) ||
        (!isset($new->submittableto) && empty($old->submittableto))
       ) {
        $new->allowarchives = 0;
    }

    // Institution and shortname cannot be updated (yet)
    unset($new->institution);
    unset($new->shortname);

    foreach (array('id', 'grouptype', 'public', 'request', 'submittableto', 'allowarchives', 'editroles',
        'hidden', 'hidemembers', 'hidemembersfrommembers', 'groupparticipationreports') as $f) {
        if (!isset($new->$f)) {
            $new->$f = $old->$f;
        }
    }

    if (isset($new->jointype)) {
        log_warn("group_update: ignoring supplied jointype");
        unset($new->jointype);
    }

    // If the caller isn't trying to enable open/controlled, use the old values
    if (!isset($new->open)) {
        $new->open = empty($new->controlled) && $old->jointype == 'open';
    }
    if (!isset($new->controlled)) {
        $new->controlled = empty($new->open) && $old->jointype == 'controlled';
    }

    if ($new->open) {
        if ($new->controlled) {
            throw new InvalidArgumentException("group_update: a group cannot have both open and controlled membership");
        }
        $new->request = 0;
        $new->jointype = 'open';
    }
    else if ($new->controlled) {
        $new->jointype = 'controlled';
    }
    else {
        $new->jointype = 'approve';
    }

    unset($new->open);
    unset($new->controlled);

    // Ensure only one of invitefriends,suggestfriends gets enabled.
    if (!empty($new->invitefriends)) {
        $new->suggestfriends = 0;
    }
    else if (!isset($new->invitefriends)) {
        $new->invitefriends = (int) ($old->invitefriends && empty($new->suggestfriends));
    }
    if (!isset($new->suggestfriends)) {
        $new->suggestfriends = $old->suggestfriends;
    }

    $diff = array_diff_assoc((array)$new, (array)$old);
    if (empty($diff)) {
        return null;
    }

    db_begin();

    if (isset($new->members)) {
        group_update_members($new->id, $new->members);
        unset($new->members);
    }

    update_record('group', $new, 'id');

    // Add users who have requested membership of a group that's becoming
    // open
    if ($old->jointype != 'open' && $new->jointype == 'open') {
        $userids = get_column_sql('
            SELECT u.id
            FROM {usr} u JOIN {group_member_request} r ON u.id = r.member
            WHERE r.group = ? AND u.deleted = 0',
            array($new->id)
        );
        if ($userids) {
            foreach ($userids as $uid) {
                group_add_user($new->id, $uid);
            }
        }
    }

    // Invitations to controlled groups are allowed, but if the admin is
    // changing a group to controlled membership, we'll assume they want
    // want to revoke all the existing invitations.
    if ($old->jointype != 'controlled' && $new->jointype == 'controlled') {
        delete_records('group_member_invite', 'group', $new->id);
    }

    // Remove requests
    if ($old->request && !$new->request) {
        delete_records('group_member_request', 'group', $new->id);
    }

    // When the group type changes, make sure everyone has a valid role.
    safe_require('grouptype', $new->grouptype);
    $allowedroles = call_static_method('GroupType' . ucfirst($new->grouptype), 'get_roles');
    set_field_select(
        'group_member', 'role', 'member',
        '"group" = ? AND NOT role IN (' . join(',', array_fill(0, count($allowedroles), '?')) . ')',
        array_merge(array($new->id), $allowedroles)
    );

    // When a group changes from public -> private or vice versa, set the
    // appropriate access permissions on the group homepage view.
    if ($old->public != $new->public) {
        $homepageid = get_field('view', 'id', 'type', 'grouphomepage', 'group', $new->id);
        if ($old->public && !$new->public) {
            delete_records('view_access', 'view', $homepageid, 'accesstype', 'public');
            insert_record('view_access', (object) array(
                'view'       => $homepageid,
                'accesstype' => 'loggedin',
                'ctime'      => db_format_timestamp(time()),
            ));
        }
        else if (!$old->public && $new->public) {
            delete_records('view_access', 'view', $homepageid, 'accesstype', 'loggedin');
            insert_record('view_access', (object) array(
                'view'       => $homepageid,
                'accesstype' => 'public',
                'ctime'      => db_format_timestamp(time()),
            ));
        }
    }

    db_commit();

    return $diff;
}

/**
 * Fetch group records from the db and convert them to a format suitable
 * for passing into group_update().
 *
 * @param array $ids List of group ids
 *
 * @return array of stdclass objects
 */
function group_get_groups_for_editing($ids=null) {
    if (empty($ids)) {
        return array();
    }

    $ids = array_map('intval', $ids);
    $groups = get_records_select_array(
        'group',
        'id IN (' . join(',', array_fill(0, count($ids), '?')) . ') AND deleted = 0',
        $ids
    );

    if (!$groups) {
        return array();
    }

    foreach ($groups as &$g) {
        $g->open       = (int) ($g->jointype == 'open');
        $g->controlled = (int) ($g->jointype == 'controlled');
        unset($g->jointype);
    }

    return $groups;
}

/**
 * Deletes a group.
 *
 * All group deleting should be done through this function, even though it is
 * simple. What is required to perform group deletion may change over time.
 *
 * @param int $groupid The group to delete
 * @param string $shortname   shortname of the group
 * @param string $institution institution of the group
 *
 * {{@internal Maybe later we can have a group_can_be_deleted function if
 * necessary}}
 */
function group_delete($groupid, $shortname=null, $institution=null, $notifymembers=true) {
    if (empty($groupid) && !empty($institution) && !is_null($shortname) && strlen($shortname)) {
        // External call to delete a group, check permission of $USER.
        global $USER;
        if (!$USER->can_edit_institution($institution)) {
            throw new AccessDeniedException("group_delete: cannot delete a group in this institution");
        }

        $group = get_record('group', 'shortname', $shortname, 'institution', $institution);
    }
    else {
        $groupid = group_param_groupid($groupid);
        $group = get_record('group', 'id', $groupid);
    }

    db_begin();
    // Leave the group_member table alone, it's needed for the deleted
    // group notification that's about to happen on cron.
    delete_records('group_member_invite', 'group', $group->id);
    delete_records('group_member_request', 'group', $group->id);
    delete_records('view_access', 'group', $group->id);

    // Delete views owned by the group
    require_once(get_config('libroot') . 'view.php');
    foreach (get_column('view', 'id', 'group', $group->id) as $viewid) {
        $view = new View($viewid);
        $view->delete();
    }

    // Release views submitted to the group
    foreach (get_column('view', 'id', 'submittedgroup', $group->id) as $viewid) {
        $view = new View($viewid);
        $view->release();
    }

    // Delete artefacts
    require_once(get_config('docroot') . 'artefact/lib.php');
    ArtefactType::delete_by_artefacttype(get_column('artefact', 'id', 'group', $group->id));

    // Delete forums
    require_once(get_config('docroot') . 'interaction/lib.php');
    foreach (get_column('interaction_instance', 'id', 'group', $group->id) as $forumid) {
        $forum = interaction_instance_from_id($forumid);
        $forum->delete();
    }

    if ($notifymembers) {
        require_once('activity.php');
        activity_occurred('groupmessage', array(
            'group'         => $group->id,
            'deletedgroup'  => true,
            'strings'       => (object) array(
                'subject' => (object) array(
                    'key'     => 'deletegroupnotificationsubject',
                    'section' => 'group',
                    'args'    => array(hsc($group->name)),
                ),
                'message' => (object) array(
                    'key'     => 'deletegroupnotificationmessage',
                    'section' => 'group',
                    'args'    => array(hsc($group->name), get_config('sitename')),
                ),
            ),
        ));
    }

    update_record('group',
        array(
            'deleted' => 1,
            'name' => $group->name . '.deleted.' . time(),
            'shortname' => null,
            'institution' => null,
            'category' => null,
            'urlid' => null,
        ),
        array(
            'id' => $group->id,
        )
    );
    db_commit();
}

/**
 * Adds a member to a group.
 *
 * Doesn't do any jointype checking, that should be handled by the caller.
 *
 * TODO: it should though. We should probably have group_user_can_be_added
 *
 * @param int $groupid
 * @param int $userid
 * @param string $role
 */
function group_add_user($groupid, $userid, $role=null, $method='internal') {
    $groupid = group_param_groupid($groupid);
    $userid  = group_param_userid($userid);

    $gm = new StdClass;
    $gm->member = $userid;
    $gm->group = $groupid;
    $gm->ctime =  db_format_timestamp(time());
    if (!$role) {
        $role = get_field_sql('SELECT gt.defaultrole FROM {grouptype} gt, {group} g WHERE g.id = ? AND g.grouptype = gt.name', array($groupid));
    }
    $gm->role = $role;
    $gm->method = $method;

    db_begin();
    insert_record('group_member', $gm);
    delete_records('group_member_request', 'group', $groupid, 'member', $userid);
    delete_records('group_member_invite', 'group', $groupid, 'member', $userid);
    handle_event('userjoinsgroup', $gm);
    db_commit();
    global $USER;
    $USER->reset_grouproles();
}

/**
 * Checks whether a user is allowed to leave a group.
 *
 * This checks things like if they're the owner and the group membership type
 *
 * @param mixed $group  DB record or ID of group to check
 * @param int   $userid (optional, will default to logged in user)
 */
function group_user_can_leave($group, $userid=null) {
    global $USER;
    static $result;

    $userid = optional_userid($userid);

    if (is_numeric($group)) {
        if (!$group = get_record('group', 'id', $group, 'deleted', 0)) {
            return false;
        }
    }

    // Return cached value if we have it
    if (isset($result[$group->id][$userid])) {
        return $result[$group->id][$userid];
    }

    if ($group->jointype == 'controlled' && group_user_access($group->id, $USER->get('id')) != 'admin') {
        return ($result[$group->id][$userid] = false);
    }

    if (group_is_only_admin($group->id, $userid)) {
        return ($result[$group->id][$userid] = false);
    }

    return ($result[$group->id][$userid] = true);
}

/**
 * Removes a user from a group.
 *
 * @param int $groupid ID of group
 * @param int $userid  ID of user to remove
 */
function group_remove_user($groupid, $userid=null, $force=false) {
    // group_user_can_leave checks the validity of groupid and userid
    if (!$force && !group_user_can_leave($groupid, $userid)) {
        throw new AccessDeniedException(get_string('usercantleavegroup', 'group'));
    }
    delete_records('group_member', 'group', $groupid, 'member', $userid);

    global $USER;
    $USER->reset_grouproles();

    require_once(get_config('docroot') . 'interaction/lib.php');
    $interactions = get_column('interaction_instance', 'id', 'group', $groupid);
    foreach ($interactions as $interaction) {
        interaction_instance_from_id($interaction)->interaction_remove_user($userid);
    }
}

/**
 * Completely update the membership of a group
 *
 * @param int $groupid ID of group
 * @param array $members list of members and roles, structured like this:
 *            array(
 *                userid => role,
 *                userid => role,
 *                ...
 *            )
 */
function group_update_members($groupid, $members) {
    global $USER;

    $groupid = group_param_groupid($groupid);

    if (!$group = get_record('group', 'id', $groupid, 'deleted', 0)) {
        throw new NotFoundException("group_update_members: group not found: $groupid");
    }

    if (($group->institution && !$USER->can_edit_institution($group->institution))
        || (!$group->institution && !$USER->get('admin'))) {
        throw new AccessDeniedException("group_update_members: access denied");
    }

    if (!is_array($members)) {
        throw new SystemException("group_update_members: members must be an array");
    }

    $badroles = array_unique(array_diff($members, array_keys(group_get_role_info($groupid))));

    if (!empty($badroles)) {
        throw new UserException("group_update_members: invalid role(s) specified for group $group->name (id $groupid): " . join(', ', $badroles));
    }

    if (!in_array('admin', $members)) {
        $groupname = get_field('group', 'name', 'id', $groupid);
        throw new UserException("group_update_members: no group admins listed for group $group->name (id $groupid)");
    }

    // Check the new members list for invalid users

    if (!empty($members)) {
        $userids = array_map('intval', array_keys($members));
        if ($group->institution && $group->institution != 'mahara') {
            $gooduserids = get_column_sql('
                SELECT usr
                FROM {usr_institution}
                WHERE usr IN (' . join(',', $userids) . ') AND institution = ?',
                array($group->institution)
            );

            if ($baduserids = array_diff($userids, $gooduserids)) {
                if (!(count($baduserids) == 1 && $baduserids[0] == $USER->id)) {
                    throw new UserException("group_update_members: some members are not in the institution $group->institution: " . join(',', $baduserids));
                }
            }
        }
        else {
            $gooduserids = get_column_sql('
                SELECT id FROM {usr} WHERE id IN (' . join(',', $userids) . ') AND deleted = 0',
                array()
            );
            if ($baduserids = array_diff($userids, $gooduserids)) {
                throw new UserException("group_update_members: some new members do not exist: " . join(',', $baduserids));
            }
        }
    }

    // Update group members

    $oldmembers = get_records_assoc('group_member', 'group', $groupid, '', 'member,role');

    $added = 0;
    $removed = 0;
    $updated = 0;

    db_begin();

    foreach ($members as $userid => $role) {
        if (!isset($oldmembers[$userid])) {
            group_add_user($groupid, $userid, $role);
            $added ++;
        }
        else if ($oldmembers[$userid]->role != $role) {
            set_field('group_member', 'role', $role, 'group', $groupid, 'member', $userid);
            $updated ++;
        }
    }

    foreach (array_keys($oldmembers) as $userid) {
        if (!isset($members[$userid])) {
            group_remove_user($groupid, $userid, true);
            $removed ++;
        }
    }

    db_commit();

    if ($added == 0 && $removed == 0 && $updated == 0) {
        return null;
    }

    return array('added' => $added, 'removed' => $removed, 'updated' => $updated);
}

/**
 * Invite a user to a group.
 *
 * @param object $group group
 * @param object $userid  User to invite
 * @param object $userfrom  User sending the invitation
 */
function group_invite_user($group, $userid, $userfrom, $role='member', $delay=null) {
    $user = optional_userobj($userid);

    $data = new StdClass;
    $data->group = $group->id;
    $data->member= $user->id;
    $data->ctime = db_format_timestamp(time());
    $data->role = $role;
    ensure_record_exists('group_member_invite', $data, $data);
    $lang = get_user_language($user->id);
    require_once('activity.php');
    $activitydata = array(
        'users'   => array($user->id),
        'subject' => get_string_from_language($lang, 'invitetogroupsubject', 'group'),
        'message' => get_string_from_language($lang, 'invitetogroupmessage', 'group', display_name($userfrom, $user), $group->name),
        'url'     => group_homepage_url($group, false),
        'urltext' => $group->name,
    );
    activity_occurred('maharamessage', $activitydata, null, null, $delay);
}

// Pieforms for various operations on groups

/**
 * Form for users to join a given group
 */
function group_get_join_form($name, $groupid, $returnto='view') {
    return pieform(array(
        'name' => $name,
        'successcallback' => 'joingroup_submit',
        'autofocus' => false,
        'elements' => array(
            'join' => array(
                'type' => 'submit',
                'value' => get_string('joingroup', 'group')
            ),
            'group' => array(
                'type' => 'hidden',
                'value' => $groupid
            ),
            'returnto' => array(
                'type' => 'hidden',
                'value' => $returnto
            ),
        )
    ));
}

/**
 * Form for accepting/declining a group invite
 */
function group_get_accept_form($name, $groupid, $returnto) {
    return pieform(array(
       'name'     => $name,
       'renderer' => 'oneline',
       'successcallback' => 'group_invite_submit',
       'elements' => array(
            'accept' => array(
                'type'  => 'submit',
                'value' => get_string('acceptinvitegroup', 'group')
            ),
            'decline' => array(
                'type'  => 'submit',
                'value' => get_string('declineinvitegroup', 'group')
            ),
            'group' => array(
                'type' => 'hidden',
                'value' => $groupid
            ),
            'returnto' => array(
                'type' => 'hidden',
                'value' => $returnto
            )
        )
    ));
}

/**
 * Form for adding a user to a group
 */
function group_get_adduser_form($userid, $groupid) {
    return pieform(array(
        'name'                => 'adduser' . $userid,
        'successcallback'     => 'group_adduser_submit',
        'renderer'            => 'div',
        'elements'            => array(
            'group' => array(
                'type'    => 'hidden',
                'value' => $groupid,
            ),
            'member' => array(
                'type'  => 'hidden',
                'value' => $userid,
            ),
            'submit' => array(
                'type'  => 'submit',
                'value' => get_string('add') . ' ' . display_name($userid),
            ),
        ),
    ));
}

/**
 * Form for removing a user from a group
 */
function group_get_removeuser_form($userid, $groupid) {
    require_once('pieforms/pieform.php');
    return pieform(array(
        'name'                => 'removeuser' . $userid,
        'validatecallback'    => 'group_removeuser_validate',
        'successcallback'     => 'group_removeuser_submit',
        'renderer'            => 'oneline',
        'elements'            => array(
            'group' => array(
                'type'    => 'hidden',
                'value' => $groupid,
            ),
            'member' => array(
                'type'  => 'hidden',
                'value' => $userid,
            ),
            'removeuser' => array(
                'type'  => 'submit',
                'value' => get_string('removefromgroup', 'group'),
            ),
        ),
    ));
}

/**
 * Form for denying request (request group)
 */
function group_get_denyuser_form($userid, $groupid) {
    require_once('pieforms/pieform.php');
    return pieform(array(
        'name'                => 'denyuser' . $userid,
        'successcallback'     => 'group_denyuser_submit',
        'renderer'            => 'oneline',
        'elements'            => array(
            'group' => array(
                'type'    => 'hidden',
                'value' => $groupid,
            ),
            'member' => array(
                'type'  => 'hidden',
                'value' => $userid,
            ),
            'denyuser' => array(
                'type'  => 'submit',
                'value' => get_string('declinerequest', 'group'),
            ),
        ),
    ));
}

// Functions for handling submission of group related forms

function joingroup_submit(Pieform $form, $values) {
    global $SESSION, $USER;
    group_add_user($values['group'], $USER->get('id'));
    $SESSION->add_ok_msg(get_string('joinedgroup', 'group'));
    if (substr($values['returnto'], 0, 1) == '/') {
        $next = $values['returnto'];
    }
    else {
        $next = group_homepage_url(get_record('group', 'id', $values['group']));
    }
    redirect($next);
}

function group_invite_submit(Pieform $form, $values) {
    global $SESSION, $USER;
    $inviterecord = get_record('group_member_invite', 'member', $USER->get('id'), 'group', $values['group']);
    if ($inviterecord) {
        delete_records('group_member_invite', 'group', $values['group'], 'member', $USER->get('id'));
        if (isset($values['accept'])) {
            group_add_user($values['group'], $USER->get('id'), $inviterecord->role);
            $SESSION->add_ok_msg(get_string('groupinviteaccepted', 'group'));
            if (substr($values['returnto'], 0, 1) == '/') {
                $next = $values['returnto'];
            }
            else {
                $next = group_homepage_url(get_record('group', 'id', $values['group']));
            }
            redirect($next);
        }
        else {
            $SESSION->add_ok_msg(get_string('groupinvitedeclined', 'group'));
            redirect($values['returnto'] == 'find' ? '/group/find.php' : '/group/mygroups.php');
        }
    }
}

function group_adduser_submit(Pieform $form, $values) {
    global $SESSION;
    $group = (int)$values['group'];
    if (group_user_access($group) != 'admin') {
        $SESSION->add_error_msg(get_string('accessdenied', 'error'));
        redirect('/group/members.php?id=' . $group . '&membershiptype=request');
    }
    group_add_user($group, $values['member']);
    $SESSION->add_ok_msg(get_string('useradded', 'group'));
    if (count_records('group_member_request', 'group', $group)) {
        redirect('/group/members.php?id=' . $group . '&membershiptype=request');
    }
    redirect('/group/members.php?id=' . $group);
}

/**
 * Denying request (request group)
 */
function group_denyuser_submit(Pieform $form, $values) {
    global $SESSION;
    $group = (int)$values['group'];
    if (group_user_access($group) != 'admin') {
        $SESSION->add_error_msg(get_string('accessdenied', 'error'));
        redirect('/group/members.php?id=' . $group . '&membershiptype=request');
    }
    delete_records('group_member_request', 'group', $values['group'], 'member', $values['member']);
    $SESSION->add_ok_msg(get_string('declinerequestsuccess', 'group'));
    if (count_records('group_member_request', 'group', $group)) {
        redirect('/group/members.php?id=' . $group . '&membershiptype=request');
    }
    redirect('/group/members.php?id=' . $group);
}

function group_removeuser_validate(Pieform $form, $values) {
    global $user, $group, $SESSION;
    if (!group_user_can_leave($values['group'], $values['member'])) {
        $form->set_error('submit', get_string('usercantleavegroup', 'group'));
    }
}

function group_removeuser_submit(Pieform $form, $values) {
    global $SESSION;
    $group = (int)$values['group'];
    if (group_user_access($group) != 'admin') {
        $SESSION->add_error_msg(get_string('accessdenied', 'error'));
        redirect('/group/members.php?id=' . $group);
    }
    group_remove_user($group, $values['member']);
    $SESSION->add_ok_msg(get_string('userremoved', 'group'));
    redirect('/group/members.php?id=' . $group);
}

/**
 * Form for submitting views to a group
 */
function group_view_submission_form($groupid) {
    global $USER;

    list($collections, $views) = View::get_views_and_collections($USER->get('id'));

    $viewoptions = $collectionoptions = array();

    foreach ($collections as $c) {
        if (empty($c['submittedgroup']) && empty($c['submittedhost'])) {
            $collectionoptions['c:' . $c['id']] = $c['name'];
        }
    }

    foreach ($views as $v) {
        if ($v['type'] != 'profile' && empty($v['submittedgroup']) && empty($v['submittedhost'])) {
            $viewoptions['v:' . $v['id']] = $v['name'];
        }
    }

    $options = $optgroups = null;

    if (!empty($collectionoptions) && !empty($viewoptions)) {
        $optgroups = array(
            'collections' => array(
                'label'   => get_string('Collections', 'collection'),
                'options' => $collectionoptions,
            ),
            'views'       => array(
                'label'   => get_string('Views', 'view'),
                'options' => $viewoptions,
            ),
        );
    }
    else if (!empty($collectionoptions)) {
        $options = $collectionoptions;
    }
    else if (!empty($viewoptions)) {
        $options = $viewoptions;
    }
    else {
        return;
    }

    return pieform(array(
        'name' => 'group_view_submission_form_' . $groupid,
        'method' => 'post',
        'renderer' => 'oneline',
        'autofocus' => false,
        'successcallback' => 'group_view_submission_form_submit',
        'elements' => array(
            'text1' => array(
                'type' => 'html', 'value' => get_string('submit', 'group') . ' ',
            ),
            'options' => array(
                'type' => 'select',
                'collapseifoneoption' => false,
                'optgroups' => $optgroups,
                'options' => $options,
            ),
            'text2' => array(
                'type' => 'html',
                'value' => get_string('forassessment', 'view'),
            ),
            'submit' => array(
                'type' => 'submit',
                'value' => get_string('submit')
            ),
            'group' => array(
                'type' => 'hidden',
                'value' => $groupid
            ),
        ),
    ));
}

function group_view_submission_form_submit(Pieform $form, $values) {
    if (substr($values['options'], 0, 2) == 'v:') {
        $viewid = substr($values['options'], 2);
        redirect('/view/submit.php?id=' . $viewid . '&group=' . $values['group'] . '&returnto=group');
    }
    if (substr($values['options'], 0, 2) == 'c:') {
        $collectionid = substr($values['options'], 2);
        redirect('/view/submit.php?collection=' . $collectionid . '&group=' . $values['group'] . '&returnto=group');
    }
    redirect('/group/view.php?id=' . $values['group']);
}

// Miscellaneous group related functions

/**
 * Returns a list of user IDs who are admins for a group
 *
 * @param int ID of group
 * @return array
 */
function group_get_admin_ids($groupid) {
    return (array)get_column_sql("SELECT \"member\"
        FROM {group_member}
        WHERE \"group\" = ?
        AND \"role\" = 'admin'", $groupid);
}

/**
 * Gets information about what the roles in a given group are able to do
 *
 * @param int $groupid ID of group to get role information for
 * @return array
 */
function group_get_role_info($groupid) {
    $roles = get_records_sql_assoc('SELECT "role", see_submitted_views, gr.grouptype FROM {grouptype_roles} gr
        INNER JOIN {group} g ON g.grouptype = gr.grouptype
        WHERE g.id = ?', array($groupid));
    foreach ($roles as $role) {
        $role->display = get_string($role->role, 'grouptype.'.$role->grouptype);
        $role->name = $role->role;
    }
    return $roles;
}

function group_get_default_artefact_permissions($groupid) {
    $permissions = array();
    $records = get_records_sql_array('
        SELECT g.editroles, r.role
        FROM {grouptype_roles} r, {group} g
        WHERE g.grouptype = r.grouptype AND g.id = ?',
        array($groupid)
    );
    foreach ($records as $r) {
        $canedit = $r->role == 'admin' || $r->editroles == 'all' || ($r->role != 'member' && $r->editroles != 'admin');
        $permissions[$r->role] = (object) array(
            'view'      => true,
            'edit'      => $canedit,
            'republish' => $canedit,
        );
    }
    return $permissions;
}

// Retrieve a list of group admins
function group_get_admins($groupids) {
    $groupids = array_map('intval', $groupids);

    if (empty($groupids)) {
        return array();
    }

    $groupadmins = get_records_sql_array('
        SELECT m.group, m.member, u.id, u.username, u.firstname, u.lastname, u.preferredname, u.email, u.profileicon, u.urlid
        FROM {group_member} m JOIN {usr} u ON u.id = m.member
        WHERE m.group IN (' . implode(',', db_array_to_ph($groupids)) . ")
        AND m.role = 'admin'",
        $groupids
    );

    if (!$groupadmins) {
        $groupadmins = array();
    }

    return $groupadmins;
}

/**
 * Sets up groups for display in mygroups.php and find.php
 *
 * @param array $groups    Initial group data, including the current user's
 *                         membership type in each group. See mygroups.php for
 *                         the query to build this information.
 * @param string $returnto Where forms generated for display should be told to return to
 */
function group_prepare_usergroups_for_display($groups, $returnto='mygroups') {
    if (!$groups) {
        return;
    }

    $groupids = array_map(create_function('$a', 'return $a->id;'), $groups);
    $groupadmins = group_get_admins($groupids);

    $i = 0;
    foreach ($groups as $group) {
        $group->admins = array();
        foreach ($groupadmins as $admin) {
            if ($admin->group == $group->id) {
                $group->admins[] = $admin;
            }
        }
        if ($group->membershiptype == 'member') {
            $group->canleave = group_user_can_leave($group->id);
        }
        else if ($group->membershiptype == 'invite') {
            $group->invite = group_get_accept_form('invite' . $i++, $group->id, $returnto);
        }
        else if ($group->jointype == 'open') {
            $group->groupjoin = group_get_join_form('joingroup' . $i++, $group->id);
        }

        $showmembercount = !$group->hidemembersfrommembers && !$group->hidemembers
            || $group->membershiptype == 'member' && !$group->hidemembersfrommembers
            || $group->membershiptype == 'admin';

        if (!$showmembercount) {
            unset($group->membercount);
        }

        $group->editwindow = group_format_editwindow($group);

        $group->settingsdescription = group_display_settings($group);
    }
}

/*
 * Formats the edit window of a group into human readable format.
 */
function group_format_editwindow($group) {
    $dateformat = 'strftimedatetime';

    $editwindowstart = isset($group->editwindowstart) ? strtotime($group->editwindowstart) : null;
    $editwindowend = isset($group->editwindowend) ? strtotime($group->editwindowend) : null;

    if (empty($editwindowstart) && empty($editwindowend)) {
        $formatted = "";
    }
    else if (!empty($editwindowstart) && empty($editwindowend)) {
        $formatted = get_string('editwindowfrom', 'group', format_date($editwindowstart, $dateformat));
    }
    else if (empty($editwindowstart) && !empty($editwindowend)) {
        $formatted = get_string('editwindowuntil', 'group', format_date($editwindowend, $dateformat));
    }
    else {
        $formatted = get_string('editwindowbetween', 'group', format_date($editwindowstart, $dateformat), format_date($editwindowend, $dateformat));
    }

    return $formatted;
}

/*
 * Used by admin/groups/groups.php and admin/groups/groups.json.php for listing groups.
 */
function build_grouplist_html($query, $limit, $offset, &$count=null) {

    $groups = search_group($query, $limit, $offset, 'all');
    $count = $groups['count'];

    if ($ids = array_map(create_function('$a', 'return intval($a->id);'), $groups['data'])) {
        $sumsql = "(m.role = 'admin')";
        if (is_postgres()) {
            $sumsql .= '::int';
        }

        // Member & admin counts
        $ids = join(',', $ids);
        $counts = get_records_sql_assoc("
            SELECT m.group, COUNT(m.member) AS members, SUM($sumsql) AS admins
            FROM {group_member} m
            WHERE m.group IN ($ids)
            GROUP BY m.group",
            array()
        );
    }

    foreach ($groups['data'] as &$group) {
        $group->visibility = $group->public ? get_string('Public', 'group') : get_string('Members', 'group');
        $group->admins = empty($counts[$group->id]->admins) ? 0 : $counts[$group->id]->admins;
        $group->members = empty($counts[$group->id]->members) ? 0 : $counts[$group->id]->members;
        if (get_config('allowgroupcategories')) {
            $group->categorytitle = ($group->category) ? get_field('group_category', 'title', 'id', $group->category) : '';
        }
        $group->homepage_url = group_homepage_url($group);
    }

    $smarty = smarty_core();
    $smarty->assign('groups', $groups['data']);
    $data = array();
    $data['tablerows'] = $smarty->fetch('admin/groups/groupsresults.tpl');

    $pagination = build_pagination(array(
                'id' => 'admgroupslist_pagination',
                'datatable' => 'admgroupslist',
                'url' => get_config('wwwroot') . 'admin/groups/groups.php' . (($query != '') ? '?query=' . urlencode($query) : ''),
                'jsonscript' => 'admin/groups/groups.json.php',
                'count' => $count,
                'limit' => $limit,
                'offset' => $offset,
                'jumplinks' => 6,
                'numbersincludeprevnext' => 2,
                'resultcounttextsingular' => get_string('group', 'group'),
                'resultcounttextplural' => get_string('groups', 'group'),
            ));

    $data['pagination'] = $pagination['html'];
    $data['pagination_js'] = $pagination['javascript'];

    return $data;
}

function group_get_membersearch_data($results, $group, $query, $membershiptype, $setlimit=false, $sortoption='') {
    global $USER;

    $params = array();
    if ($query != '') {
        $params['query'] = $query;
    }
    if (!empty($membershiptype)) {
        $params['membershiptype'] = $membershiptype;
    }
    if (!empty($sortoption)) {
        $params['sortoption'] = $sortoption;
    }
    $searchurl = get_config('wwwroot') . 'group/members.php?id=' . $group . (!empty($params) ? ('&' . http_build_query($params)) : '');

    $smarty = smarty_core();

    $role = group_user_access($group);
    $userid = $USER->get('id');
    foreach ($results['data'] as &$r) {
        if ($role == 'admin' && ($r['id'] != $userid || group_user_can_leave($group, $r['id']))) {
            $r['removeform'] = group_get_removeuser_form($r['id'], $group);
        }
        // NOTE: this is a quick approximation. We should really check whether,
        // for each role in the group, that the user can change to it (using
        // group_can_change_role).  This only controls whether the 'change
        // role' link appears though, so it doesn't matter too much. If the
        // user clicks on this link, changerole.php does the full check and
        // sends them back here saying that the user has no roles they can
        // change to anyway.
        $r['canchangerole'] = !group_is_only_admin($group, $r['id']);
    }

    if (!empty($membershiptype)) {
        if ($membershiptype == 'request') {
            foreach ($results['data'] as &$r) {
                $r['addform'] = group_get_adduser_form($r['id'], $group);
                $r['denyform'] = group_get_denyuser_form($r['id'], $group);
                // TODO: this will suck when there's quite a few on the page,
                // would be better to grab all the reasons in one go
                $r['reason']  = get_field('group_member_request', 'reason', 'group', $group, 'member', $r['id']);
            }
        }
        $smarty->assign('membershiptype', $membershiptype);
    }

    $results['cdata'] = array_chunk($results['data'], 2);
    $results['roles'] = group_get_role_info($group);
    $smarty->assign_by_ref('results', $results);
    $smarty->assign('searchurl', $searchurl);
    $smarty->assign('pagebaseurl', $searchurl);
    $smarty->assign('caneditroles', group_user_access($group) == 'admin');
    $smarty->assign('group', $group);
    $html = $smarty->fetch('group/membersearchresults.tpl');

    $pagination = build_pagination(array(
        'id' => 'member_pagination',
        'class' => 'center',
        'url' => $searchurl,
        'count' => $results['count'],
        'setlimit' => $setlimit,
        'limit' => $results['limit'],
        'offset' => $results['offset'],
        'jumplinks' => 8,
        'numbersincludeprevnext' => 2,
        'datatable' => 'membersearchresults',
        'searchresultsheading' => 'searchresultsheading',
        'jsonscript' => 'group/membersearchresults.json.php',
        'firsttext' => '',
        'previoustext' => '',
        'nexttext' => '',
        'lasttext' => '',
        'numbersincludefirstlast' => false,
        'resultcounttextsingular' => get_string('member', 'group'),
        'resultcounttextplural' => get_string('members', 'group'),
    ));

    return array($html, $pagination, $results['count'], $results['offset'], $membershiptype);
}


/**
 * Returns a list of available grouptypes
 *
 * @return array
 */
function group_get_grouptypes() {
    static $grouptypes = null;

    if (is_null($grouptypes)) {
        $grouptypes = get_column('grouptype', 'name');
    }

    return $grouptypes;
}


/**
 * Returns a list of grouptype options to be used in the edit
 * group drop-down.
 */
function group_get_grouptype_options($currentgrouptype=null) {
    $groupoptions = array();
    $grouptypes = group_get_grouptypes();
    $enabled = array_map(create_function('$a', 'return $a->name;'), plugins_installed('grouptype'));
    if (is_null($currentgrouptype) || in_array($currentgrouptype, $enabled)) {
        $grouptypes = array_intersect($enabled, $grouptypes);
    }
    foreach ($grouptypes as $grouptype) {
        safe_require('grouptype', $grouptype);
        if (call_static_method('GroupType' . $grouptype, 'can_be_created_by_user')) {
            $roles = array();
            foreach (call_static_method('GroupType' . $grouptype, 'get_roles') as $role) {
                $roles[] = get_string($role, 'grouptype.' . $grouptype);
            }
            $groupoptions[$grouptype] = get_string('name', 'grouptype.' . $grouptype) . ': ' . join(', ', $roles);
        }
    }
    return $groupoptions;
}

function group_get_editroles_options($intkeys = false) {
    if ($intkeys) {
        return array(GROUP_ROLES_ALL => get_string('allgroupmembers', 'group'),
                     GROUP_ROLES_NONMEMBER => get_string('allexceptmember', 'group'),
                     GROUP_ROLES_ADMIN => get_string('groupadmins', 'group'),
                     );
    }
    $options = array(
        'all'       => get_string('allgroupmembers', 'group'),
        'notmember' => get_string('allexceptmember', 'group'),
        'admin'     => get_string('groupadmins', 'group'),
    );

    return $options;
}

function group_can_list_members($group, $role) {
    return !$group->hidemembersfrommembers && !$group->hidemembers
        || $role && !$group->hidemembersfrommembers
        || $role == 'admin';
}

/**
 * Returns a datastructure describing the tabs that appear on a group page
 *
 * @param object $group Database record of group to get tabs for
 * @return array
 */
function group_get_menu_tabs() {
    static $menu;

    $group = group_current_group();
    if (!$group) {
        return null;
    }

    $role = group_user_access($group->id);

    $menu = array(
        'info' => array(
            'path' => 'groups/info',
            'url' => group_homepage_url($group, false),
            'title' => get_string('About', 'group'),
            'weight' => 20
        ),
    );

    if (group_can_list_members($group, $role)) {
        $menu['members'] = array(
            'path' => 'groups/members',
            'url' => 'group/members.php?id='.$group->id,
            'title' => get_string('Members', 'group'),
            'weight' => 30
        );
    }

    if ($interactionplugins = plugins_installed('interaction')) {
        foreach ($interactionplugins as $plugin) {
            safe_require('interaction', $plugin->name);
            $plugin_menu = call_static_method(generate_class_name('interaction', $plugin->name), 'group_menu_items', $group);
            $menu = array_merge($menu, $plugin_menu);
        }
    }

    $menu['views'] = array(
        'path' => 'groups/views',
        'url' => 'view/groupviews.php?group='.$group->id,
        'title' => get_string('Views', 'group'),
        'weight' => 50,
    );
    $menu['collections'] = array(
        'path' => 'groups/collections',
        'url' => 'collection/index.php?group='.$group->id,
        'title' => get_string('Collections', 'group'),
        'weight' => 60,
    );


    if (group_role_can_edit_views($group, $role)) {
        $menu['share'] = array(
            'path' => 'groups/share',
            'url' => 'group/shareviews.php?group='.$group->id,
            'title' => get_string('share', 'view'),
            'weight' => 70,
        );
    }

    if ($role) {
        safe_require('grouptype', $group->grouptype);
        $artefactplugins = call_static_method('GroupType' . $group->grouptype, 'get_group_artefact_plugins');
        if ($plugins = plugins_installed('artefact')) {
            foreach ($plugins as &$plugin) {
                if (!in_array($plugin->name, $artefactplugins)) {
                    continue;
                }
                safe_require('artefact', $plugin->name);
                $plugin_menu = call_static_method(generate_class_name('artefact',$plugin->name), 'group_tabs', $group->id);
                $menu = array_merge($menu, $plugin_menu);
            }
        }
    }

    if (group_role_can_access_report($group, $role)) {
        $menu['report'] = array(
            'path' => 'groups/report',
            'url' => 'group/report.php?group=' . $group->id,
            'title' => get_string('report', 'group'),
            'weight' => 70,
        );
    }

    if (defined('MENUITEM')) {
        $key = substr(MENUITEM, strlen('groups/'));
        if ($key && isset($menu[$key])) {
            $menu[$key]['selected'] = true;
        }
    }

    return $menu;
}

/**
 * Used by this file to perform validation of group ID function arguments
 *
 * @param int $groupid
 * @return int
 * @throws InvalidArgumentException
 */
function group_param_groupid($groupid) {
    $groupid = (int)$groupid;

    if ($groupid == 0) {
        throw new InvalidArgumentException("group_user_access: group argument should be an integer");
    }

    return $groupid;
}

/**
 * Used by this file to perform validation of user ID function arguments
 *
 * @param int $userid
 * @return int
 * @throws InvalidArgumentException
 */
function group_param_userid($userid) {
    if (is_null($userid)) {
        global $USER;
        $userid = (int)$USER->get('id');
    }
    else {
        $userid = (int)$userid;
    }

    if ($userid == 0) {
        throw new InvalidArgumentException("group_user_access: user argument should be an integer");
    }

    return $userid;
}


function group_current_group() {
    static $group;

    if (isset($group)) {
        return $group;
    }

    if (defined('GROUP')) {
        $group = get_record_select('group', 'id = ? AND deleted = 0', array(GROUP), '*, ' . db_format_tsfield('ctime'));
        if (!$group) {
            throw new GroupNotFoundException(get_string('groupnotfound', 'group', GROUP));
        }
    }
    else if (defined('GROUPURLID')) {
        $group = get_record_select('group', 'urlid = ? AND deleted = 0', array(GROUPURLID), '*, ' . db_format_tsfield('ctime'));
        if (!$group) {
            throw new GroupNotFoundException(get_string('groupnotfoundname', 'group', GROUPURLID));
        }
        define('GROUP', $group->id);
    }
    else {
        $group = null;
    }

    return $group;
}

function group_get_associated_groups($userid, $filter='all', $limit=20, $offset=0, $category='') {

    // Strangely, casting is only needed for invite, request and admin and only in
    // postgres
    if (is_mysql()) {
        $invitesql  = "'invite'";
        $requestsql = "'request'";
        $adminsql   = "'admin'";
        $empty      = "''";
    }
    else {
        $invitesql  = "CAST('invite' AS TEXT)";
        $requestsql = "CAST('request' AS TEXT)";
        $adminsql   = "CAST('admin' AS TEXT)";
        $empty      = "CAST('' AS TEXT)";
    }
    // TODO: make it work on other databases?

    // Different filters join on the different kinds of association
    if ($filter == 'admin') {
        $sql = "
            INNER JOIN (
                SELECT g.id, $adminsql AS membershiptype, $empty AS reason, $adminsql AS role
                FROM {group} g
                INNER JOIN {group_member} gm ON (gm.group = g.id AND gm.member = ? AND gm.role = 'admin')
            ) t ON t.id = g.id";
        $values = array($userid);
    }
    else if ($filter == 'member') {
        $sql = "
            INNER JOIN (
                SELECT g.id, 'admin' AS membershiptype, $empty AS reason, $adminsql AS role
                FROM {group} g
                INNER JOIN {group_member} gm ON (gm.group = g.id AND gm.member = ? AND gm.role = 'admin')
                UNION
                SELECT g.id, 'member' AS type, $empty AS reason, gm.role AS role
                FROM {group} g
                INNER JOIN {group_member} gm ON (gm.group = g.id AND gm.member = ? AND gm.role != 'admin')
            ) t ON t.id = g.id";
        $values = array($userid, $userid);
    }
    else if ($filter == 'invite') {
        $sql = "
            INNER JOIN (
                SELECT g.id, $invitesql AS membershiptype, gmi.reason, gmi.role
                FROM {group} g
                INNER JOIN {group_member_invite} gmi ON (gmi.group = g.id AND gmi.member = ?)
            ) t ON t.id = g.id";
        $values = array($userid);
    }
    else if ($filter == 'request') {
        $sql = "
            INNER JOIN (
                SELECT g.id, $requestsql AS membershiptype, gmr.reason, $empty AS role
                FROM {group} g
                INNER JOIN {group_member_request} gmr ON (gmr.group = g.id AND gmr.member = ?)
            ) t ON t.id = g.id";
        $values = array($userid);
    }
    else { // all or some other text
        $filter = 'all';
        $sql = "
            INNER JOIN (
                SELECT g.id, 'admin' AS membershiptype, '' AS reason, 'admin' AS role
                FROM {group} g
                INNER JOIN {group_member} gm ON (gm.group = g.id AND gm.member = ? AND gm.role = 'admin')
                UNION
                SELECT g.id, 'member' AS membershiptype, '' AS reason, gm.role AS role
                FROM {group} g
                INNER JOIN {group_member} gm ON (g.id = gm.group AND gm.member = ? AND gm.role != 'admin')
                UNION
                SELECT g.id, 'invite' AS membershiptype, gmi.reason, gmi.role
                FROM {group} g
                INNER JOIN {group_member_invite} gmi ON (gmi.group = g.id AND gmi.member = ?)
                UNION SELECT g.id, 'request' AS membershiptype, gmr.reason, '' AS role
                FROM {group} g
                INNER JOIN {group_member_request} gmr ON (gmr.group = g.id AND gmr.member = ?)
            ) t ON t.id = g.id";
        $values = array($userid, $userid, $userid, $userid);
    }

    $values[] = 0;

    $catsql = '';
    if (!empty($category)) {
        if ($category == -1) { //find unassigned groups
            $catsql = ' AND g.category IS NULL';
        } else {
            $catsql = ' AND g.category = ?';
            $values[] = $category;
        }
    }

    $count = count_records_sql('SELECT COUNT(*) FROM {group} g ' . $sql . ' WHERE g.deleted = ?'.$catsql, $values);

    // almost the same as query used in find - common parts should probably be pulled out
    // gets the groups filtered by above

    $sql = '
        SELECT g1.id, g1.name, g1.description, g1.public, g1.jointype, g1.request, g1.grouptype, g1.submittableto,
            g1.hidemembers, g1.hidemembersfrommembers, g1.groupparticipationreports, g1.urlid, g1.membershiptype, g1.reason, g1.role, g1.membercount,
            COUNT(gmr.member) AS requests, g1.editwindowstart, g1.editwindowend
        FROM (
            SELECT g.id, g.name, g.description, g.public, g.jointype, g.request, g.grouptype, g.submittableto,
                g.hidemembers, g.hidemembersfrommembers, g.groupparticipationreports, g.urlid, t.membershiptype, t.reason, t.role,
                COUNT(gm.member) AS membercount, g.editwindowstart, g.editwindowend
            FROM {group} g
            LEFT JOIN {group_member} gm ON (gm.group = g.id)' .
            $sql . '
            WHERE g.deleted = ?' .
            $catsql . '
            GROUP BY g.id, g.name, g.description, g.public, g.jointype, g.request, g.grouptype, g.submittableto,
                g.hidemembers, g.hidemembersfrommembers, g.groupparticipationreports, g.urlid, t.membershiptype, t.reason, t.role, g.editwindowstart, g.editwindowend
        ) g1
        LEFT JOIN {group_member_request} gmr ON (gmr.group = g1.id)
        GROUP BY g1.id, g1.name, g1.description, g1.public, g1.jointype, g1.request, g1.grouptype, g1.submittableto,
            g1.hidemembers, g1.hidemembersfrommembers, g1.groupparticipationreports, g1.urlid, g1.membershiptype, g1.reason, g1.role, g1.membercount, g1.editwindowstart, g1.editwindowend
        ORDER BY g1.name';

    $groups = get_records_sql_array($sql, $values, $offset, $limit);

    return array('groups' => $groups ? $groups : array(), 'count' => $count);

}

/**
 * returns a list of groups of a user by $userid or the current logged in user, given $roles from cache or database
 * where $roles is the list of the user's role in a group
 * if the user id is null, the logged in user will take into account
 * if $roles is empty, all groups of the user will be returned
 *
 * @param int $userid
 * @param array $roles
 * @param string $sort is 'earliest', 'latest', or 'alphabetical'(default),
 *     sorts the list of groups based on the date the user joined the group or group name
 *     if empty, the list will be sorted by group name, admin role first
 * @param int $limit  The number of groups to display per page (page size)
 * @param int $offset The first group index in the current page to display
 * @param boolean $fromcache if yes, try to return the list from the cache first
 *                             or no, force to query database and update the cache
 * @return array $usergroups    An array of groups the user belongs to.
 * Or if the $limit option is not empty
 * @return array, int $usergroups, $count  You can fetch the results as list($usergroups, $count)
 */
function group_get_user_groups($userid=null, $roles=null, $sort=null, $limit=null, $offset=0, $fromcache=true) {
    global $USER;

    static $usergroups = array();

    $loggedinid = $USER->get('id');

    if (is_null($userid)) {
        $userid = $loggedinid;
    }

    if (!$fromcache || !isset($usergroups[$userid])) {

        $groups = get_records_sql_array("
            SELECT g.id, g.name, gm.role, g.jointype, g.request, g.grouptype, gtr.see_submitted_views, g.category,
                g.hidemembers, g.invitefriends, g.urlid, gm.ctime, gm1.role AS loggedinrole
            FROM {group} g
                JOIN {group_member} gm ON gm.group = g.id
                JOIN {grouptype_roles} gtr ON g.grouptype = gtr.grouptype AND gm.role = gtr.role
                LEFT OUTER JOIN {group_member} gm1 ON gm1.group = gm.group AND gm1.member = ?
            WHERE gm.member = ?
                AND g.deleted = 0
            ORDER BY g.name, gm.role = 'admin' DESC, gm.role, g.id",
            array($loggedinid, $userid)
        );
        $usergroups[$userid] = $groups ? $groups : array();
    }

    if (!empty($sort)) {
        // Sort the list of groups based on the date the user joined the group
        if ($sort == 'earliest') {
            usort($usergroups[$userid],
                function ($g1, $g2) {
                    if ($g1->ctime == $g2->ctime) {
                        return ($g1->name < $g2->name) ? -1 : 1;
                    }
                    return ($g1->ctime < $g2->ctime) ? -1 : 1;
                }
            );
        }
        else if ($sort == 'latest') {
            usort($usergroups[$userid],
                function ($g1, $g2) {
                    if ($g1->ctime == $g2->ctime) {
                        return ($g1->name < $g2->name) ? -1 : 1;
                    }
                    return ($g1->ctime > $g2->ctime) ? -1 : 1;
                }
            );
        }
        else if ($sort == 'alphabetical') {
            // Do nothing, the list sorted by the SQL query
        }
        else {
            throw new SystemException('Unknown sort flag: "' . $sort . '"');
        }
    }

    if (empty($roles) && $userid == $loggedinid) {
        $count = count($usergroups[$userid]);
        if (!empty($limit)) {
            $truncatedusergroups = array_slice($usergroups[$userid], $offset, $limit);
            return array($truncatedusergroups, $count);
        }
        return $usergroups[$userid];
    }

    $filtered = array();

    foreach ($usergroups[$userid] as $g) {
        $goodrole = empty($roles) || in_array($g->role, $roles);
        $visible = !$g->hidemembers || $g->loggedinrole || $USER->get('admin') || $USER->get('staff');
        if ($goodrole && $visible) {
            $filtered[] = $g;
        }
    }
    $count = count($filtered);
    if (!empty($limit)) {
        $filtered = array_slice($filtered, $offset, $limit);
        return array($filtered, $count);
    }
    return $filtered;
}

function group_get_user_admintutor_groups() {
    $groups = array();

    foreach (group_get_user_groups() as $g) {
        if ($g->role == 'admin' || $g->see_submitted_views) {
            $groups[] = $g;
        }
    }

    return $groups;
}

function group_get_member_ids($group, $roles=null, $includedeleted=false) {
    $rolesql = is_null($roles) ? '' : (' AND gm.role IN (' . join(',', array_map('db_quote', $roles)) . ')');
    return get_column_sql('
        SELECT gm.member
        FROM {group_member} gm INNER JOIN {group} g ON gm.group = g.id
        WHERE g.id = ? ' . ($includedeleted ? '' : ' AND g.deleted = 0') . $rolesql,
        array($group)
    );
}

function group_can_create_groups() {
    global $USER;
    $creators = get_config('creategroups');
    if ($creators == 'all') {
        return true;
    }
    if ($USER->get('admin') || $USER->is_institutional_admin()) {
        return true;
    }
    return $creators == 'staff' && ($USER->get('staff') || $USER->is_institutional_staff());
}

/* Returns groups containing a given member which accept view submissions */
function group_get_user_course_groups($userid=null) {
    if (is_null($userid)) {
        global $USER;
        $userid = $USER->get('id');
    }
    if ($groups = get_records_sql_array(
        "SELECT g.id, g.name
        FROM {group_member} u
        INNER JOIN {group} g ON (u.group = g.id AND g.deleted = 0)
        WHERE u.member = ?
        AND g.submittableto = 1
        ORDER BY g.name
        ", array($userid))) {
        return $groups;
    }
    return array();
}

function group_display_settings($group) {
    $settings = array();
    if ($group->jointype != 'approve') {
        $settings[] = get_string('membershiptype.abbrev.'.$group->jointype, 'group');
    }
    if ($group->request) {
        $settings[] = get_string('requestmembership', 'group');
    }
    if ($group->submittableto) {
        $settings[] = get_string('allowssubmissions', 'group');
    }
    if ($group->public) {
        $settings[] = get_string('publiclyvisible', 'group');
    }
    return join(', ', $settings);
}

function group_get_groupinfo_data($group) {
    safe_require('artefact', 'file');
    safe_require('interaction', 'forum');

    $group->admins = group_get_admins(array($group->id));
    $group->settingsdescription = group_display_settings($group);
    if (get_config('allowgroupcategories')) {
        $group->categorytitle = ($group->category) ? get_field('group_category', 'title', 'id', $group->category) : '';
    }

    if (group_can_list_members($group, group_user_access($group->id))) {
        $group->membercount = count_records('group_member', 'group', $group->id);
    }

    $group->viewcount = count_records('view', 'group', $group->id);

    $group->filecounts = ArtefactTypeFileBase::count_user_files(null, $group->id, null);

    $group->forumcounts = PluginInteractionForum::count_group_forums($group->id);

    $group->topiccounts = PluginInteractionForum::count_group_topics($group->id);

    $group->postcounts = PluginInteractionForum::count_group_posts($group->id);

    return $group;
}

/**
 * Return the view object for this group's homepage view
 *
 * @param int $groupid the id of the group to fetch the view for
 *
 * @throws ViewNotFoundException
 */
function group_get_homepage_view($groupid) {
    $v = get_record('view', 'group', $groupid, 'type', 'grouphomepage');
    return new View($v->id, (array)$v);
}

/**
 * Return the groupview block object of this group's homepage view
 *
 * @param int $groupid the id of the group to fetch the view for
 * @return object block instance
 * @throws SQLException if there are more than one groupview block instance
 */
function group_get_homepage_view_groupview_block($groupid) {
    $bi = get_record_sql('
        SELECT bi.id
        FROM {view} v
            INNER JOIN {block_instance} bi ON v.id = bi.view
        WHERE bi.blocktype = ?
            AND v.group = ? AND v.type = ?',
        array('groupviews', $groupid, 'grouphomepage')
    );
    return new BlockInstance($bi->id);
}

/**
 * install the group homepage view
 * This creates a template at system level
 * which is subsequently copied to group hompages
 *
 * @return int the id of the new template
 */
function install_system_grouphomepage_view() {
    $dbtime = db_format_timestamp(time());
    // create a system template for group homepage views
    require_once(get_config('libroot') . 'view.php');
    $view = View::create(array(
        'type'        => 'grouphomepage',
        'owner'       => 0,
        'numcolumns'  => 2,
        'numrows'     => 1,
        'columnsperrow' => array((object)array('row' => 1, 'columns' => 1)),
        'template'    => 1,
        'title'       => get_string('grouphomepage', 'view'),
    ));
    $view->set_access(array(array(
        'type' => 'loggedin'
    )));
    $blocktypes = array(
        array(
            'blocktype' => 'groupinfo',
            'title' => '',
            'row'    => 1,
            'column' => 1,
            'config' => null,
        ),
        array(
            'blocktype' => 'recentforumposts',
            'title' => '',
            'row'    => 1,
            'column' => 1,
            'config' => null,
        ),
        array(
            'blocktype' => 'groupviews',
            'title' => '',
            'row'    => 1,
            'column' => 1,
            'config' => array('showgroupviews' => 1, 'showsharedviews' => 1),
        ),
        array(
            'blocktype' => 'groupmembers',
            'title' => '',
            'row'    => 1,
            'column' => 1,
            'config' => null,
        ),
    );
    $installed = get_column_sql('SELECT name FROM {blocktype_installed}');
    $weights = array(1 => 0);
    foreach ($blocktypes as $blocktype) {
        if (in_array($blocktype['blocktype'], $installed)) {
            $weights[$blocktype['column']]++;
            $newblock = new BlockInstance(0, array(
                    'blocktype'  => $blocktype['blocktype'],
                    'title'      => $blocktype['title'],
                    'view'       => $view->get('id'),
                    'row'        => $blocktype['row'],
                    'column'     => $blocktype['column'],
                    'order'      => $weights[$blocktype['column']],
                    'configdata' => $blocktype['config'],
            ));
            $newblock->commit();
        }
    }

    return $view->get('id');
}

function get_forum_list($groupid, $userid = 0) {
    $forums = array();
    if (
        (is_numeric($groupid) && $groupid > 0)
        && (is_numeric($userid) && $userid >= 0)
    ) {
        $forums = get_records_sql_array(
            'SELECT f.id, f.title, f.description, m.user AS moderator, COUNT(t.id) AS topiccount, s.forum AS subscribed
            FROM {interaction_instance} f
            LEFT JOIN (
                SELECT m.forum, m.user
                FROM {interaction_forum_moderator} m
                INNER JOIN {usr} u ON (m.user = u.id AND u.deleted = 0)
            ) m ON m.forum = f.id
            LEFT JOIN {interaction_forum_topic} t ON (t.forum = f.id AND t.deleted != 1)
            INNER JOIN {interaction_forum_instance_config} c ON (c.forum = f.id AND c.field = \'weight\')
            LEFT JOIN {interaction_forum_subscription_forum} s ON (s.forum = f.id AND s."user" = ?)
            WHERE f.group = ?
            AND f.deleted != 1
            GROUP BY 1, 2, 3, 4, 6, c.value
            ORDER BY CHAR_LENGTH(c.value), c.value, m.user',
            array($userid, $groupid)
        );
    }

    return $forums;
}

function group_quota_allowed($groupid, $bytes) {
    if (!is_numeric($bytes) || $bytes < 0) {
        throw new InvalidArgumentException('parameter must be a positive integer to add to the quota');
    }
    if (!$group = get_record('group', 'id', $groupid, 'deleted', 0)) {
        throw new GroupNotFoundException(get_string('groupnotfound', 'group', $groupid));
    }
    if ($group->quotaused + $bytes > $group->quota) {
        return false;
    }

    return true;
}

function group_quota_add($groupid, $bytes) {
    if (!group_quota_allowed($groupid, $bytes)) {
        throw new QuotaExceededException('Adding ' . $bytes . ' bytes would exceed the group\'s quota');
    }
    if (!$group = get_record('group', 'id', $groupid, 'deleted', 0)) {
        throw new GroupNotFoundException(get_string('groupnotfound', 'group', $groupid));
    }
    $newquota = $group->quotaused + $bytes;
    $group = new StdClass;
    $group->id = $groupid;
    $group->quotaused = $newquota;
    update_record('group', $group);
}

function group_quota_remove($groupid, $bytes) {
    if (!is_numeric($bytes) || $bytes < 0) {
        throw new InvalidArgumentException('parameter must be a positive integer to add to the quota');
    }
    if (!$group = get_record('group', 'id', $groupid, 'deleted', 0)) {
        throw new GroupNotFoundException(get_string('groupnotfound', 'group', $groupid));
    }
    $newquota = max(0, $group->quotaused - $bytes);
    $group = new StdClass;
    $group->id = $groupid;
    $group->quotaused = $newquota;
    update_record('group', $group);
}

function group_get_new_homepage_urlid($desired) {
    $maxlen = 30;
    $desired = strtolower(substr($desired, 0, $maxlen));
    $taken = get_column_sql('SELECT urlid FROM {group} WHERE urlid LIKE ?', array(substr($desired, 0, $maxlen - 6) . '%'));
    if (!$taken) {
        return $desired;
    }

    $i = 1;
    $newname = substr($desired, 0, $maxlen - 2) . '-1';
    while (in_array($newname, $taken)) {
        $i++;
        $newname = substr($desired, 0, $maxlen - strlen($i) - 1) . '-' . $i;
    }
    return $newname;
}

/**
 * Returns the homepage url for a group
 *
 * @param stdclass $group object with at least an id
 * @param bool $full return a full url
 * @param bool $useid ignore clean url settings and always return a url with an id in it
 *
 * @return string
 */
function group_homepage_url($group, $full=true, $useid=false) {
    if (!$useid && !empty($group->urlid) && get_config('cleanurls')) {
        $url = get_config('cleanurlgroupdefault') . '/' . $group->urlid;
    }
    else if ($group->id) {
        $url = 'group/view.php?id=' . $group->id;
    }
    else {
        throw new SystemException("group_homepage_url called with no group id");
    }
    if ($full) {
        $url = get_config('wwwroot') . $url;
    }
    return $url;
}

/**
 * Returns whether 'send now' is set for all memebers or not
 * If not set only admins/tutors/moderators can use 'send now'
 *
 * @param string $groupid the id of the group
 * @return boolean
 */
function group_sendnow($groupid) {
    if (!$sendnow = get_field('group', 'sendnow', 'id', $groupid)) {
        return false;
    }
    return !empty($sendnow);
}
