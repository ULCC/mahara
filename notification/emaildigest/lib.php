<?php
/**
 *
 * @package    mahara
 * @subpackage notification-emaildigest
 * @author     Catalyst IT Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL version 3 or later
 * @copyright  For copyright information on Mahara, please see the README file distributed with this software.
 *
 */

defined('INTERNAL') || die();

require_once(get_config('docroot') . 'notification/lib.php');

class PluginNotificationEmaildigest extends PluginNotification {

    public static function notify_user($user, $data) {
        $toinsert = new StdClass;
        $toinsert->type = $data->type;
        $toinsert->usr = $user->id;
        // Some messages are all html (or the message is not required).
        // When they're 'cleaned' for display, they are left empty.
        // Make sure something is in the field as it's NOT NULL in the database.
        $toinsert->message = (empty($data->message) ? ' ' : $data->message);
        $toinsert->ctime = db_format_timestamp(time());
        if (!empty($data->url)) {
            $toinsert->url = $data->url;
        }

        insert_record('notification_emaildigest_queue', $toinsert);
    }

    public static function get_cron() {
        $emaildigest = new StdClass;
        $emaildigest->callfunction = 'send_digest';
        $emaildigest->hour = '6';
        $emaildigest->minute = '0';
        return array($emaildigest);
    }

    public static function send_digest() {
        $users = array();
        $sitename = get_config('sitename');

        $types = get_records_assoc('activity_type', 'admin', 0, 'plugintype,pluginname,name', 'id,name,plugintype,pluginname');
        foreach ($types as &$type) {
            if (!empty($type->plugintype)) {
                $type->section = "{$type->plugintype}.{$type->pluginname}";
            }
            else {
                $type->section = "activity";
            }
        }

        $sql = 'SELECT q.id, u.username, u.firstname, u.lastname, u.preferredname, u.email, u.admin, u.staff,
                    p.value AS lang, q.*,' . db_format_tsfield('q.ctime', 'qctime').'
                FROM {usr} u
                    JOIN {notification_emaildigest_queue} q
                        ON q.usr = u.id
                    LEFT OUTER JOIN {usr_account_preference} p ON (p.usr = u.id AND p.field = \'lang\')
                ORDER BY usr,type,q.ctime';

        if ($tosend = get_records_sql_array($sql, array())) {
            foreach ($tosend as $queue) {
                if (!isset($users[$queue->usr])) {
                    $users[$queue->usr] = new StdClass;

                    $users[$queue->usr]->user = new StdClass;
                    $users[$queue->usr]->user->username      = $queue->username;
                    $users[$queue->usr]->user->firstname     = $queue->firstname;
                    $users[$queue->usr]->user->lastname      = $queue->lastname;
                    $users[$queue->usr]->user->preferredname = $queue->preferredname;
                    $users[$queue->usr]->user->email         = $queue->email;
                    $users[$queue->usr]->user->admin         = $queue->admin;
                    $users[$queue->usr]->user->staff         = $queue->staff;
                    $users[$queue->usr]->user->id            = $queue->usr;
                    $users[$queue->usr]->user->lang          = (empty($queue->lang) || $queue->lang == 'default') ? get_config('lang') : $queue->lang;

                    $users[$queue->usr]->entries = array();
                }
                $queue->nicetype = get_string_from_language($users[$queue->usr]->user->lang,
                                                            'type' . $types[$queue->type]->name, $types[$queue->type]->section);
                $users[$queue->usr]->entries[$queue->id] = $queue;
            }
        }
        foreach ($users as $user) {
            $lang = $user->user->lang;
            $subject = get_string_from_language($lang, 'emailsubject', 'notification.emaildigest', $sitename);
            $body = get_string_from_language($lang, 'emailbodynoreply', 'notification.emaildigest', $sitename);
            foreach ($user->entries as $entry) {
                $body .= get_string_from_language($lang, 'type', 'activity') . ': ' . $entry->nicetype
                    . ' ' . get_string_from_language($lang, 'attime', 'activity')  . ' ' . format_date($entry->qctime) . "\n";
                if (!empty($entry->subject)) {
                    $body .= get_string_from_language($lang, 'subject') . $entry->subject ."\n";
                }
                if (!empty($entry->message)) {
                    $body .= "\n" . $entry->message;
                }
                if (!empty($entry->url)) {
                    if (stripos($entry->url, 'http://') !== 0 && stripos($entry->url, 'https://') !== 0) {
                        $entry->url = get_config('wwwroot') . $entry->url;
                    }
                    $body .= "\n" . $entry->url;
                }
                $body .= "\n\n";
            }
            $prefurl = get_config('wwwroot') . 'account/activity/preferences/index.php';
            $body .= "\n\n" . get_string_from_language($lang, 'emailbodyending', 'notification.emaildigest', $prefurl);
            try {
                email_user($user->user, null, $subject, $body);
                //only delete them if the email succeeded!
                $in = db_array_to_ph($user->entries);
                delete_records_select('notification_emaildigest_queue',
                                      'id IN (' . implode(', ', $in) . ')',
                                      array_keys($user->entries));
            }
            catch (Exception $e) {
                // @todo
            }
        }
    }

    public static function get_event_subscriptions() {
        $subscriptions = array(
            (object)array(
                'plugin'       => 'emaildigest',
                'event'        => 'deleteuser',
                'callfunction' => 'deleteuser',
            ),
        );
        return $subscriptions;
    }

    public static function deleteuser($event, $user) {
        delete_records('notification_emaildigest_queue', 'usr', $user['id']);
    }

}
