#!/usr/bin/env php
<?php
/*
 * Laconica - a distributed open-source microblogging tool
 * Copyright (C) 2008, Controlez-Vous, Inc.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.     See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.     If not, see <http://www.gnu.org/licenses/>.
 */

// Abort if called from a web server
if (isset($_SERVER) && array_key_exists('REQUEST_METHOD', $_SERVER)) {
    print "This script must be run from the command line\n";
    exit();
}

define('INSTALLDIR', realpath(dirname(__FILE__) . '/..'));
define('LACONICA', true);

// Tune number of processes and how often to poll Twitter
// XXX: Should these things be in config.php?
define('MAXCHILDREN', 2);
define('POLL_INTERVAL', 60); // in seconds

// Uncomment this to get useful logging
define('SCRIPT_DEBUG', true);

require_once(INSTALLDIR . '/lib/common.php');
require_once(INSTALLDIR . '/lib/daemon.php');

class TwitterStatusFetcher extends Daemon
{

    private $children = array();

    function name()
    {
        return ('twitterstatusfetcher.generic');
    }

    function run()
    {
        do {

            $flinks = $this->refreshFlinks();

            foreach ($flinks as $f){

                // We have to disconnect from the DB before forking so
                // each sub-process will open its own connection and
                // avoid stomping on the others

                $conn = &$f->getDatabaseConnection();
                $conn->disconnect();

                $pid = pcntl_fork();

                if ($pid == -1) {
                    die ("Couldn't fork!");
                }

                if ($pid) {

                    // Parent
                    if (defined('SCRIPT_DEBUG')) {
                        common_debug("Parent: forked new status fetcher process " . $pid);
                    }

                    $this->children[] = $pid;

                } else {

                    // Child
                    $this->getTimeline($f);
                    exit();
                }

                // Remove child from ps list as it finishes
                while(($c = pcntl_wait($status, WNOHANG OR WUNTRACED)) > 0) {

                    if (defined('SCRIPT_DEBUG')) {
                        common_debug("Child $c finished.");
                    }

                    $this->remove_ps($this->children, $c);
                }

                // Wait! We have too many damn kids.
                if (sizeof($this->children) > MAXCHILDREN) {

                    if (defined('SCRIPT_DEBUG')) {
                        common_debug('Too many children. Waiting...');
                    }

                    if (($c = pcntl_wait($status, WUNTRACED)) > 0){

                        if (defined('SCRIPT_DEBUG')) {
                            common_debug("Finished waiting for $c");
                        }

                        $this->remove_ps($this->children, $c);
                    }
                }
            }

            // Remove all children from the process list before restarting
            while(($c = pcntl_wait($status, WUNTRACED)) > 0) {

                if (defined('SCRIPT_DEBUG')) {
                    common_debug("Child $c finished.");
                }

                $this->remove_ps($this->children, $c);
            }

            // Rest for a bit before we fetch more statuses

            if (defined('SCRIPT_DEBUG')) {
                common_debug('Waiting ' . POLL_INTERVAL .
                    ' secs before hitting Twitter again.');
            }

            if (POLL_INTERVAL > 0) {
                sleep(POLL_INTERVAL);
            }

        } while (true);
    }

    function refreshFlinks() {

        $flink = new Foreign_link();
        $flink->service = 1; // Twitter
        $flink->orderBy('last_noticesync');

        $cnt = $flink->find();

        if (defined('SCRIPT_DEBUG')) {
            common_debug('Updating Twitter friends subscriptions' .
                " for $cnt users.");
        }

        $flinks = array();

        while ($flink->fetch()) {

            if (($flink->noticesync & FOREIGN_NOTICE_RECV) ==
                FOREIGN_NOTICE_RECV) {
                $flinks[] = clone($flink);
            }
        }

        $flink->free();
        unset($flink);

        return $flinks;
    }

    function remove_ps(&$plist, $ps){
        for ($i = 0; $i < sizeof($plist); $i++) {
            if ($plist[$i] == $ps) {
                unset($plist[$i]);
                $plist = array_values($plist);
                break;
            }
        }
    }

    function getTimeline($flink)
    {

        if (empty($flink)) {
            common_log(LOG_WARNING,
                "Can't retrieve Foreign_link for foreign ID $fid");
            return;
        }

        $fuser = $flink->getForeignUser();

        if (empty($fuser)) {
            common_log(LOG_WARNING, "Unmatched user for ID " .
                $flink->user_id);
            return;
        }

        if (defined('SCRIPT_DEBUG')) {
            common_debug('Trying to get timeline for Twitter user ' .
                "$fuser->nickname ($flink->foreign_id).");
        }

        // XXX: Biggest remaining issue - How do we know at which status
        // to start importing?  How many statuses?  Right now I'm going
        // with the default last 20.

        $url = 'http://twitter.com/statuses/friends_timeline.json';

        $timeline_json = get_twitter_data($url, $fuser->nickname,
            $flink->credentials);

        $timeline = json_decode($timeline_json);

        if (empty($timeline)) {
            common_log(LOG_WARNING, "Empty timeline.");
            return;
        }

        foreach ($timeline as $status) {

            // Hacktastic: filter out stuff coming from this Laconica
            $source = mb_strtolower(common_config('integration', 'source'));

            if (preg_match("/$source/", mb_strtolower($status->source))) {
                if (defined('SCRIPT_DEBUG')) {
                    common_debug('Skipping import of status ' . $status->id .
                        ' with source ' . $source);
                }
                continue;
            }

            $this->saveStatus($status, $flink);
        }

        // Okay, record the time we synced with Twitter for posterity
        $flink->last_noticesync = common_sql_now();
        $flink->update();
    }

    function saveStatus($status, $flink)
    {
        $id = $this->ensureProfile($status->user);
        $profile = Profile::staticGet($id);

        if (!$profile) {
            common_log(LOG_ERR,
                'Problem saving notice. No associated Profile.');
            return null;
        }

        $uri = 'http://twitter.com/' . $status->user->screen_name .
            '/status/' . $status->id;

        $notice = Notice::staticGet('uri', $uri);

        // check to see if we've already imported the status
        if (!$notice) {

            $notice = new Notice();
            $notice->profile_id = $id;

            $notice->query('BEGIN');

            // XXX: figure out reply_to
            $notice->reply_to = null;

            // XXX: Should this be common_sql_now() instead of status create date?

            $notice->created = strftime('%Y-%m-%d %H:%M:%S',
                strtotime($status->created_at));
            $notice->content = $status->text;
            $notice->rendered = common_render_content($status->text, $notice);
            $notice->source = 'twitter';
            $notice->is_local = 0;
            $notice->uri = $uri;

            $notice_id = $notice->insert();

            if (!$notice_id) {
                common_log_db_error($notice, 'INSERT', __FILE__);
                if (defined('SCRIPT_DEBUG')) {
                    common_debug('Could not save notice!');
                }
            }

            // XXX: Figure out a better way to link Twitter replies?
            $notice->saveReplies();

            // XXX: Do we want to pollute our tag cloud with
            // hashtags from Twitter?
            $notice->saveTags();
            $notice->saveGroups();

            $notice->query('COMMIT');

            if (defined('SCRIPT_DEBUG')) {
                common_debug("Saved status $status->id" .
                    " as notice $notice->id.");
            }
        }

        if (!Notice_inbox::staticGet('notice_id', $notice->id)) {

            // Add to inbox
            $inbox = new Notice_inbox();
            $inbox->user_id = $flink->user_id;
            $inbox->notice_id = $notice->id;
            $inbox->created = common_sql_now();

            $inbox->insert();
        }
    }

    function ensureProfile($user)
    {
        // check to see if there's already a profile for this user
        $profileurl = 'http://twitter.com/' . $user->screen_name;
        $profile = Profile::staticGet('profileurl', $profileurl);

        if ($profile) {
            if (defined('SCRIPT_DEBUG')) {
                common_debug("Profile for $profile->nickname found.");
            }

            // Check to see if the user's Avatar has changed
            $this->checkAvatar($user, $profile);

            return $profile->id;

        } else {
            if (defined('SCRIPT_DEBUG')) {
                common_debug('Adding profile and remote profile ' .
                    "for Twitter user: $profileurl");
            }

            $profile = new Profile();
            $profile->query("BEGIN");

            $profile->nickname = $user->screen_name;
            $profile->fullname = $user->name;
            $profile->homepage = $user->url;
            $profile->bio = $user->description;
            $profile->location = $user->location;
            $profile->profileurl = $profileurl;
            $profile->created = common_sql_now();

            $id = $profile->insert();

            if (empty($id)) {
                common_log_db_error($profile, 'INSERT', __FILE__);
                $profile->query("ROLLBACK");
                return false;
            }

            // check for remote profile
            $remote_pro = Remote_profile::staticGet('uri', $profileurl);

            if (!$remote_pro) {

                $remote_pro = new Remote_profile();

                $remote_pro->id = $id;
                $remote_pro->uri = $profileurl;
                $remote_pro->created = common_sql_now();

                $rid = $remote_pro->insert();

                if (empty($rid)) {
                    common_log_db_error($profile, 'INSERT', __FILE__);
                    $profile->query("ROLLBACK");
                    return false;
                }
            }

            $profile->query("COMMIT");

            $this->saveAvatars($user, $id);

            return $id;
        }
    }

    function checkAvatar($user, $profile)
    {
        global $config;

        $path_parts = pathinfo($user->profile_image_url);
        $newname = 'Twitter_' . $user->id . '_' .
            $path_parts['basename'];

        $oldname = $profile->getAvatar(48)->filename;

        if ($newname != $oldname) {

            if (defined('SCRIPT_DEBUG')) {
                common_debug('Avatar for Twitter user ' .
                    "$profile->nickname has changed.");
                common_debug("old: $oldname new: $newname");
            }

            $img_root = substr($path_parts['basename'], 0, -11);
            $ext = $path_parts['extension'];
            $mediatype = $this->getMediatype($ext);

            foreach (array('mini', 'normal', 'bigger') as $size) {
                $url = $path_parts['dirname'] . '/' .
                    $img_root . '_' . $size . ".$ext";
                $filename = 'Twitter_' . $user->id . '_' .
                    $img_root . "_$size.$ext";

                if ($this->fetchAvatar($url, $filename)) {
                    $this->updateAvatar($profile->id, $size, $mediatype, $filename);
                }
            }
        }
    }

    function getMediatype($ext)
    {
        $mediatype = null;

        switch (strtolower($ext)) {
        case 'jpg':
            $mediatype = 'image/jpg';
            break;
        case 'gif':
            $mediatype = 'image/gif';
            break;
        default:
            $mediatype = 'image/png';
        }

        return $mediatype;
    }

    function saveAvatars($user, $id)
    {
        global $config;

        $path_parts = pathinfo($user->profile_image_url);
        $ext = $path_parts['extension'];
        $end = strlen('_normal' . $ext);
        $img_root = substr($path_parts['basename'], 0, -($end+1));
        $mediatype = $this->getMediatype($ext);

        foreach (array('mini', 'normal', 'bigger') as $size) {
            $url = $path_parts['dirname'] . '/' .
                $img_root . '_' . $size . ".$ext";
            $filename = 'Twitter_' . $user->id . '_' .
                $img_root . "_$size.$ext";

            if ($this->fetchAvatar($url, $filename)) {
                $this->newAvatar($id, $size, $mediatype, $filename);
            } else {
                common_log(LOG_WARNING, "Problem fetching Avatar: $url", __FILE__);
            }
        }
    }

    function updateAvatar($profile_id, $size, $mediatype, $filename) {

        if (defined('SCRIPT_DEBUG')) {
            common_debug("Updating avatar: $size");
        }

        $profile = Profile::staticGet($profile_id);

        if (!$profile) {
            if (defined('SCRIPT_DEBUG')) {
                common_debug("Couldn't get profile: $profile_id!");
            }
            return;
        }

        $sizes = array('mini' => 24, 'normal' => 48, 'bigger' => 73);
        $avatar = $profile->getAvatar($sizes[$size]);

        if ($avatar) {
            if (defined('SCRIPT_DEBUG')) {
                common_debug("Deleting $size avatar for $profile->nickname.");
            }
            @unlink(INSTALLDIR . '/avatar/' . $avatar->filename);
            $avatar->delete();
        }

        $this->newAvatar($profile->id, $size, $mediatype, $filename);
    }

    function newAvatar($profile_id, $size, $mediatype, $filename)
    {
        global $config;

        $avatar = new Avatar();
        $avatar->profile_id = $profile_id;

        switch($size) {
        case 'mini':
            $avatar->width  = 24;
            $avatar->height = 24;
            break;
        case 'normal':
            $avatar->width  = 48;
            $avatar->height = 48;
            break;
        default:

            // Note: Twitter's big avatars are a different size than
            // Laconica's (Laconica's = 96)

            $avatar->width  = 73;
            $avatar->height = 73;
        }

        $avatar->original = 0; // we don't have the original
        $avatar->mediatype = $mediatype;
        $avatar->filename = $filename;
        $avatar->url = Avatar::url($filename);

        if (defined('SCRIPT_DEBUG')) {
            common_debug("new filename: $avatar->url");
        }

        $avatar->created = common_sql_now();

        $id = $avatar->insert();

        if (!$id) {
            common_log_db_error($avatar, 'INSERT', __FILE__);
            return null;
        }

        if (defined('SCRIPT_DEBUG')) {
            common_debug("Saved new $size avatar for $profile_id.");
        }

        return $id;
    }

    function fetchAvatar($url, $filename)
    {
        $avatar_dir = INSTALLDIR . '/avatar/';

        $avatarfile = $avatar_dir . $filename;

        $out = fopen($avatarfile, 'wb');
        if (!$out) {
            common_log(LOG_WARNING, "Couldn't open file $filename", __FILE__);
            return false;
        }

        if (defined('SCRIPT_DEBUG')) {
            common_debug("Fetching avatar: $url");
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_FILE, $out);
        curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0);
        $result = curl_exec($ch);
        curl_close($ch);

        fclose($out);

        return $result;
    }
}

ini_set("max_execution_time", "0");
ini_set("max_input_time", "0");
set_time_limit(0);
mb_internal_encoding('UTF-8');
declare(ticks = 1);

$fetcher = new TwitterStatusFetcher();
$fetcher->runOnce();
