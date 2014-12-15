<?php
/**
 *
 * @package    mahara
 * @subpackage core
 * @author     Martin Dougiamas <martin@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL version 3 or later
 * @copyright  For copyright information on Mahara, please see the README file distributed with this software.
 * @copyright  (C) 2001-3001 Martin Dougiamas http://dougiamas.com
 *
 */

defined('INTERNAL') || die();

class upload_manager {

   /**
    * Array to hold local copy of stuff in $_FILES
    * @var array $file
    */
    public $file;

   /**
    * Name of the file input
    * @var string $inputname
    */
    public $inputname;

   /**
    * Whether to try to rename files when they already exist
    * @var bool $handlecollisions
    */
    public $handlecollisions;

    /**
     * Indicates whether this file is optional to the form.
     * @var bool $optional
     */
    public $optional;

    /**
     * Indicates that the file input was optional and was skipped on purpose.
     * @var bool $optional
     */
    public $optionalandnotsupplied = false;

    /**
     * Constructor.
     *
     * @param string $inputname Name in $_FILES.
     */
    public function __construct($inputname, $handlecollisions=false, $inputindex=null, $optional=false) {
        $this->inputname = $inputname;
        $this->handlecollisions = $handlecollisions;
        $this->inputindex = $inputindex;
        $this->optional = $optional;
    }

    /**
     * Gets file information out of $_FILES and stores it locally in $files.
     * Checks file against max upload file size.
     * Scans file for viruses.
     * @return false for no errors, or a string describing the error
     */
    public function preprocess_file() {

        $name = $this->inputname;
        if (!isset($_FILES[$name])) {
            if ($this->optional) {
                $this->optionalandnotsupplied = true;
                return false;
            }
            else {
                return get_string('noinputnamesupplied');
            }
        }
        $file = $_FILES[$name];

        $maxsize = get_config('maxuploadsize');
        if (isset($this->inputindex)) {
            $size = $file['size'][$this->inputindex];
            $error = $file['error'][$this->inputindex];
            $tmpname = $file['tmp_name'][$this->inputindex];
        }
        else {
            $size = $file['size'];
            $error = $file['error'];
            $tmpname = $file['tmp_name'];
        }
        if ($maxsize && $size > $maxsize) {
            return get_string('uploadedfiletoobig');
        }

        if ($error != UPLOAD_ERR_OK) {
            $errormsg = get_string('phpuploaderror', 'mahara', get_string('phpuploaderror_' . $error), $error);
            if ($error == UPLOAD_ERR_NO_TMP_DIR || $error == UPLOAD_ERR_CANT_WRITE) {
                // The admin probably needs to fix this; notify them
                // @TODO: Create a new activity type for general admin messages.
                $message = (object) array(
                    'users' => get_column('usr', 'id', 'admin', 1),
                    'subject' => get_string('adminphpuploaderror'),
                    'message' => $errormsg,
                );
                require_once('activity.php');
                activity_occurred('maharamessage', $message);
            }
            else if ($error == UPLOAD_ERR_INI_SIZE || $error == UPLOAD_ERR_FORM_SIZE) {
                return get_string('uploadedfiletoobig');
            }
        }

        if (!is_uploaded_file($tmpname)) {
            if ($this->optional) {
                $this->optionalandnotsupplied = true;
                return false;
            }
            else {
                return get_string('notphpuploadedfile');
            }
        }

        if (get_config('viruschecking')) {
            $pathtoclam = escapeshellcmd(trim(get_config('pathtoclam')));
            if ($pathtoclam && file_exists($pathtoclam) && is_executable($pathtoclam)) {
                if ($errormsg = mahara_clam_scan_file($file, $this->inputindex)) {
                    return $errormsg;
                }
            }
            else {
                clam_mail_admins(get_string('clamlost', 'mahara', $pathtoclam));
            }
        }

        $this->file = $file;
        return false;
    }


    /**
     * Moves the file to the destination directory.
     *
     * @uses $CFG
     * @param string $destination The destination directory.
     * @param string $newname The filename
     * @return false if no errors, or a string describing the error
     */
    public function save_file($destination, $newname) {

        // It's an optional file that was not uploaded.
        if ($this->optional && $this->optionalandnotsupplied) {
            return false;
        }

        if (!isset($this->file)) {
            return get_string('unknownerror');
        }

        $dataroot = get_config('dataroot');

        if (!(strpos($destination, $dataroot) === false)) {
            // take it out for giving to make_upload_directory
            $destination = substr($destination, strlen($dataroot)+1);
        }

        if ($destination{strlen($destination)-1} == '/') { // strip off a trailing / if we have one
            $destination = substr($destination, 0, -1);
        }

        if (empty($destination)) {
            $destination = $dataroot;
        } else {
            $destination = $dataroot . '/' . $destination;
        }

        if (!check_dir_exists($destination, true, true)) {
            throw new UploadException('Unable to create upload directory');
        }

        if (file_exists($destination . '/' . $newname) && $this->handlecollisions) {
            $newname = $this->rename_duplicate_file($destination, $newname);
        }

        if (isset($this->inputindex)) {
            $tmpname = $this->file['tmp_name'][$this->inputindex];
        }
        else {
            $tmpname = $this->file['tmp_name'];
        }
        if (move_uploaded_file($tmpname, $destination . '/' . $newname)) {
            chmod($destination . '/' . $newname, get_config('filepermissions'));
            return false;
        }
        return get_string('failedmovingfiletodataroot');
    }


    /**
     * Wrapper function that calls {@link preprocess_files()} and {@link viruscheck_files()} and then {@link save_files()}
     * Modules that require the insert id in the filepath should not use this and call these functions seperately in the required order.
     * @parameter string $destination Where to save the uploaded file to.
     * @parameter string $newname What to call the saved file.
     * @return false for no errors, or a string describing the error.
     */
    public function process_file_upload($destination, $newname) {
        $error = $this->preprocess_file();
        if (!$error) {
            return $this->save_file($destination, $newname);
        }
        return $error;
    }

    /**
     * Handles filename collisions - if the desired filename exists it will rename it according to the pattern in $format
     * @param string $destination Destination directory (to check existing files against)
     * @param object $file Passed in by reference. The current file from $files we're processing.
     * @param string $format The printf style format to rename the file to (defaults to filename_number.extn)
     * @return string The new filename.
     */
    public function rename_duplicate_file($destination, $filename, $format='%s_%d.%s') {
        // If there's no dot or more than one dot we get yucky stuff like 'foo_1.', 'foo_1.bar.baz'
        $bits = explode('.', $filename);
        // check for collisions and append a nice numberydoo.
        for ($i = 1; true; $i++) {
            $try = sprintf($format, $bits[0], $i, $bits[1]);
            if (!file_exists($destination . '/' . $try)) {
                return $try;
            }
        }
    }

    public function original_filename_extension() {
        if (isset($this->file)) {
            if (isset($this->inputindex)) {
                $filename = $this->file['name'][$this->inputindex];
            }
            else {
                $filename = $this->file['name'];
            }
        }
        if (isset($filename)
            && !empty($filename)
            && preg_match("/\.([^\.]+)$/", $filename, $m)) {
            return strtolower($m[1]);
        }
        return null;
    }


}

/**************************************************************************************
THESE FUNCTIONS ARE OUTSIDE THE CLASS BECAUSE THEY NEED TO BE CALLED FROM OTHER PLACES.
FOR EXAMPLE CLAM_HANDLE_INFECTED_FILE AND CLAM_REPLACE_INFECTED_FILE USED FROM CRON
UPLOAD_PRINT_FORM_FRAGMENT DOESN'T REALLY BELONG IN THE CLASS BUT CERTAINLY IN THIS FILE
***************************************************************************************/

/**
 * Deals with an infected file - either moves it to a quarantinedir
 * (specified in CFG->quarantinedir) or deletes it.
 *
 * If moving it fails, it deletes it.
 *
 *@uses $CFG
 * @uses $USER
 * @param string $file Full path to the file
 * @param int $userid If not used, defaults to $USER->id (there in case called from cron)
 * @param boolean $basiconly Admin level reporting or user level reporting.
 * @return string Details of what the function did.
 */
function clam_handle_infected_file($file) {
    global $USER;
    $userid = $USER->get('id');

    $quarantinedir = get_config('dataroot') . get_string('quarantinedirname');
    check_dir_exists($quarantinedir);

    if (is_dir($quarantinedir) && is_writable($quarantinedir)) {
        $now = date('YmdHis');
        $newname = $quarantinedir .'/'. $now .'-user-'. $userid .'-infected';
        if (rename($file, $newname)) {
            clam_log_infected($file, $newname);
            return get_string('clammovedfile');
        }
    }
    if (unlink($file)) {
        clam_log_infected($file, '', $userid);
        return get_string('clamdeletedfile');
    }
    return get_string('clamdeletefilefailed');
}


/**
 * Scan a file for viruses using clamav.
 *
 * @param mixed $file The file to scan from $files. or an absolute path to a file.
 * @return false if no errors, or a string if there's an error.
 */
function mahara_clam_scan_file($file, $inputindex=null) {

    if (isset($inputindex)) {
        $tmpname = $file['tmp_name'][$inputindex];
    }
    else if (is_array($file)) {
        $tmpname = $file['tmp_name'];
    }
    if (is_array($file) && is_uploaded_file($tmpname)) { // it's from $_FILES
        $fullpath = $tmpname;
    }
    else if (file_exists($file)) {
        $fullpath = $file;
    }
    else {
        throw new SystemException('mahara_clam_scan_file: not called correctly, read phpdoc for this function');
    }

    $pathtoclam = escapeshellcmd(trim(get_config('pathtoclam')));

    if (!$pathtoclam) {
        return false;
    }

    if (!file_exists($pathtoclam) || !is_executable($pathtoclam)) {
        clam_mail_admins(get_string('clamlost', 'mahara', $pathtoclam));
        clam_handle_infected_file($fullpath);
        return get_string('clambroken');
    }
    $clamparam = ' ';
    // If we are dealing with clamdscan, clamd is likely run as a different user
    // that might not have permissions to access your file.
    // To make clamdscan work, we use --fdpass parameter that passes the file
    // descriptor permissions to clamd, which allows it to scan given file
    // irrespective of directory and file permissions.
    if (basename($pathtoclam) == 'clamdscan') {
        $clamparam .= '--fdpass ';
    }
    $cmd = $pathtoclam . $clamparam . escapeshellarg($fullpath) ." 2>&1";

    exec($cmd, $output, $return);

    switch ($return) {
    case 0: // glee! we're ok.
        return false; // no error
    case 1:  // bad wicked evil, we have a virus.
        global $USER;
        $userid = $USER->get('id');
        clam_handle_infected_file($fullpath);
        // Notify admins if user has uploaded more than 3 infected
        // files in the last month
        if (count_records_sql('
            SELECT
                COUNT(*)
            FROM {usr_infectedupload}
            WHERE usr = ? AND time > ?',
            array($userid, db_format_timestamp(time() - 60*60*24*30))) >= 2) {
            log_debug('sending virusrepeat notification');
            $data = (object) array('username' => $USER->get('username'),
                                   'userid' => $userid,
                                   'fullname' => full_name());
            require_once('activity.php');
            activity_occurred('virusrepeat', $data);
        }
        $data = (object) array('usr' => $userid, 'time' => db_format_timestamp(time()));
        insert_record('usr_infectedupload', $data, 'id');
        return get_string('virusfounduser', 'mahara', display_name($USER));
    default:
        // error - clam failed to run or something went wrong
        $notice = get_string('clamfailed', 'mahara', get_clam_error_code($return));
        $notice .= "\n\n". implode("\n", $output);
        $notice .= "\n". clam_handle_infected_file($fullpath);
        clam_mail_admins($notice);
        return get_string('clambroken');
    }

}

/**
 * Emails admins about a clam outcome
 *
 * @param string $notice The body of the email to be sent.
 */
function clam_mail_admins($notice) {
    $subject = get_string('clamemailsubject', 'mahara', get_config('sitename'));
    $adminusers = get_records_array('usr', 'admin', 1);
    if ($adminusers) {
        foreach ($adminusers as $admin) {
            $message = new StdClass;
            $message->users = array($admin->id);

            $message->subject = $subject;
            $message->message = $notice;

            require_once('activity.php');
            activity_occurred('maharamessage', $message);
        }
    }
}

/**
 * Returns the string equivalent of a numeric clam error code
 *
 * @param int $returncode The numeric error code in question.
 * return string The definition of the error code
 */
function get_clam_error_code($returncode) {
    $returncodes = array();
    $returncodes[0] = 'No virus found.';
    $returncodes[1] = 'Virus(es) found.';
    $returncodes[2] = ' An error occurred'; // specific to clamdscan
    // all after here are specific to clamscan
    $returncodes[40] = 'Unknown option passed.';
    $returncodes[50] = 'Database initialization error.';
    $returncodes[52] = 'Not supported file type.';
    $returncodes[53] = 'Can\'t open directory.';
    $returncodes[54] = 'Can\'t open file. (ofm)';
    $returncodes[55] = 'Error reading file. (ofm)';
    $returncodes[56] = 'Can\'t stat input file / directory.';
    $returncodes[57] = 'Can\'t get absolute path name of current working directory.';
    $returncodes[58] = 'I/O error, please check your filesystem.';
    $returncodes[59] = 'Can\'t get information about current user from /etc/passwd.';
    $returncodes[60] = 'Can\'t get information about user \'clamav\' (default name) from /etc/passwd.';
    $returncodes[61] = 'Can\'t fork.';
    $returncodes[63] = 'Can\'t create temporary files/directories (check permissions).';
    $returncodes[64] = 'Can\'t write to temporary directory (please specify another one).';
    $returncodes[70] = 'Can\'t allocate and clear memory (calloc).';
    $returncodes[71] = 'Can\'t allocate memory (malloc).';
    if (isset($returncodes[$returncode])) {
       return $returncodes[$returncode];
    }
    return get_string('clamunknownerror');

}

/**
 * This function logs to error_log and to the log table that an infected file has been found and what's happened to it.
 *
 * @param string $oldfilepath Full path to the infected file before it was moved.
 * @param string $newfilepath Full path to the infected file since it was moved to the quarantine directory (if the file was deleted, leave empty).
 * @param int $userid The user id of the user who uploaded the file.
 */
function clam_log_infected($oldfilepath='', $newfilepath='', $userid=0) {

    global $USER;
    $username = $USER->get('username') . ' (' . full_name() . ')';

    $errorstr = 'Clam AV has found a file that is infected with a virus. It was uploaded by '
        . full_name()
        . ((empty($oldfilepath)) ? '. The infected file was caught on upload ('.$oldfilepath.')'
           : '. The original file path of the infected file was '. $oldfilepath)
        . ((empty($newfilepath)) ? '. The file has been deleted ' : '. The file has been moved to a quarantine directory and the new path is '. $newfilepath);

    log_debug($errorstr);
}
