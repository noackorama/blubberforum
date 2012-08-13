<?php
/*
 *  Copyright (c) 2012  Rasmus Fuhse <fuhse@data-quest.de>
 * 
 *  This program is free software; you can redistribute it and/or
 *  modify it under the terms of the GNU General Public License as
 *  published by the Free Software Foundation; either version 2 of
 *  the License, or (at your option) any later version.
 */

require_once dirname(__file__)."/application.php";

class ForumController extends ApplicationController {
    
    protected $max_threads = 20;
    
    public function forum_action() {
        object_set_visit($_SESSION['SessionSeminar'], "forum");
        PageLayout::addHeadElement("script", array('src' => $this->assets_url."/javascripts/autoresize.jquery.min.js"), "");
        PageLayout::addHeadElement("script", array('src' => $this->assets_url."/javascripts/blubberforum.js"), "");
        PageLayout::setTitle($GLOBALS['SessSemName']["header_line"]." - ".$this->plugin->getDisplayTitle());
        Navigation::getItem("/course/blubberforum")->setImage($this->plugin->getPluginURL()."/assets/images/blubber.png");
        
        ForumPosting::expireThreads($_SESSION['SessionSeminar']);
        $this->threads = ForumPosting::getThreads($_SESSION['SessionSeminar'], false, $this->max_threads + 1);
        $this->more_threads = count($this->threads) > $max_threads;
        $this->course_id = $_SESSION['SessionSeminar'];
        if ($this->more_threads) {
            $this->threads = array_slice($this->threads, 0, $max_threads);
        }
    }
    
    public function more_comments_action() {
        if (!$_SESSION['SessionSeminar'] || !$GLOBALS['perm']->have_studip_perm("autor", $_SESSION['SessionSeminar'])) {
            throw new AccessDeniedException("Kein Zugriff");
        }
        $thread = new ForumPosting(Request::option("thread_id"));
        $output = array();
        $factory = new Flexi_TemplateFactory($this->plugin->getPluginPath()."/views");
        $comments = $thread->getChildren();
        foreach ($comments as $posting) {
            $template = $factory->open("forum/comment.php");
            $template->set_attribute('posting', $posting);
            $template->set_attribute('course_id', $_SESSION['SessionSeminar']);
            $output['comments'][] = array(
                'content' => studip_utf8encode($template->render()),
                'mkdate' => $posting['mkdate'],
                'posting_id' => $posting->getId()
            );
        }
        $this->render_json($output);
    }
    
    public function more_postings_action() {
        if (!$_SESSION['SessionSeminar'] || !$GLOBALS['perm']->have_studip_perm("autor", $_SESSION['SessionSeminar'])) {
            throw new AccessDeniedException("Kein Zugriff");
        }
        $output = array();
        $threads = ForumPosting::getThreads($_SESSION['SessionSeminar'], Request::int("before"), $this->max_threads + 1);
        $output['more'] = count($this->threads) > $max_threads;
        if ($output['more']) {
            $threads = array_slice($threads, 0, $max_threads);
        }
        $output['threads'] = array();
        $factory = new Flexi_TemplateFactory($this->plugin->getPluginPath()."/views");
        foreach ($threads as $posting) {
            $template = $factory->open("forum/thread.php");
            $template->set_attribute('thread', $posting);
            $template->set_attribute('course_id', $_SESSION['SessionSeminar']);
            $output['threads'][] = array(
                'content' => studip_utf8encode($template->render()),
                'mkdate' => $posting['mkdate'],
                'posting_id' => $posting->getId()
            );
        }
        $this->render_json($output);
    }
    
    public function new_posting_action() {
        if (!$_SESSION['SessionSeminar'] || !$GLOBALS['perm']->have_studip_perm("autor", $_SESSION['SessionSeminar'])) {
            throw new AccessDeniedException("Kein Zugriff");
        }
        $output = array();
        $thread = new ForumPosting(Request::option("thread"));
        $thread['seminar_id'] = $_SESSION['SessionSeminar'];
        $thread['parent_id'] = 0;
        $content = studip_utf8decode(Request::get("content"));
        if (strpos($content, "\n") !== false) {
            $thread['name'] = substr($content, 0, strpos($content, "\n"));
            $thread['description'] = $content;
        } else {
            if (strlen($content) > 255) {
                $thread['name'] = "";
            } else {
                $thread['name'] = $content;
            }
            $thread['description'] = $content;
        }
        $thread['user_id'] = $GLOBALS['user']->id;
        $thread['author'] = get_fullname();
        $thread['author_host'] = $_SERVER['REMOTE_ADDR'];
        if ($thread->store()) {
            $thread->restore();
            $thread['root_id'] = $thread->getId();
            $thread->store();
            $factory = new Flexi_TemplateFactory($this->plugin->getPluginPath()."/views");
            $template = $factory->open("forum/thread.php");
            $template->set_attribute('thread', $thread);
            $template->set_attribute('course_id', $_SESSION['SessionSeminar']);
            $output['content'] = studip_utf8encode($template->render());
            $output['mkdate'] = time();
            $output['posting_id'] = $thread->getId();
        }
        $this->render_json($output);
    }
    
    public function get_source_action() {
        $posting = new ForumPosting(Request::get("topic_id"));
        if (!$GLOBALS['perm']->have_studip_perm("autor", $posting['Seminar_id'])) {
            throw new AccessDeniedException("Kein Zugriff");
        }
        echo studip_utf8encode(forum_kill_edit($posting['description']));
        $this->render_nothing();
    }
    
    public function edit_posting_action () {
        $posting = new ForumPosting(Request::get("topic_id"));
        if (!$GLOBALS['perm']->have_studip_perm("tutor", $posting['Seminar_id']) 
                && ($posting['user_id'] !== $GLOBALS['user']->id)) {
            throw new AccessDeniedException("Kein Zugriff");
        }
        if (Request::get("content")) {
            $posting['description'] = studip_utf8decode(Request::get("content"));
            $posting->store();
        } else {
            $posting->delete();
        }
        $this->render_text(studip_utf8encode(formatReady($posting['description'])));
    }
    
    public function post_action() {
        if (!$_SESSION['SessionSeminar'] || !$GLOBALS['perm']->have_studip_perm("autor", $_SESSION['SessionSeminar'])) {
            throw new AccessDeniedException("Kein Zugriff");
        }
        $thread = new ForumPosting(Request::option("thread"));
        if (Request::option("thread")) {
            $output = array();
            $thread = new ForumPosting(Request::option("thread"));
            $posting = new ForumPosting();
            $posting['description'] = studip_utf8decode(Request::get("content"));
            $posting['seminar_id'] = $_SESSION['SessionSeminar'];
            $posting['root_id'] = $posting['parent_id'] = Request::option("thread");
            $posting['name'] = "Re: ".$thread['name'];
            $posting['user_id'] = $GLOBALS['user']->id;
            $posting['author'] = get_fullname();
            $posting['author_host'] = $_SERVER['REMOTE_ADDR'];
            if ($posting->store()) {
                $factory = new Flexi_TemplateFactory($this->plugin->getPluginPath()."/views/forum");
                $template = $factory->open("comment.php");
                $template->set_attribute('posting', $posting);
                $template->set_attribute('course_id', $_SESSION['SessionSeminar']);
                $output['content'] = studip_utf8encode($template->render($template->render()));
                $output['mkdate'] = time();
                $output['posting_id'] = $posting->getId();
            }
            $this->render_json($output);
        } else {
            $this->render_json(array(
                'error' => "Konnte thread nicht zuordnen."
            ));
        }
    }

    public function post_files_action() {
        if (count($_POST) === 0 || !$_SESSION['SessionSeminar'] || !$GLOBALS['perm']->have_studip_perm("autor", $_SESSION['SessionSeminar'])) {
            throw new AccessDeniedException("Kein Zugriff");
        }
        $files = Request::getArray("files");
        //check folders
        $db = DBManager::get();
        $folder_id = md5("Blubber_".$_SESSION['SessionSeminar']."_".$GLOBALS['user']->id);
        $folder = $db->query(
            "SELECT * " .
            "FROM folder " .
            "WHERE folder_id = ".$db->quote($folder_id)." " .
        "")->fetch(PDO::FETCH_COLUMN, 0);
        if (!$folder) {
            $parent_folder_id = md5("Blubber_".$_SESSION['SessionSeminar']);
            $folder = $db->query(
                "SELECT * " .
                "FROM folder " .
                "WHERE folder_id = ".$db->quote($parent_folder_id)." " .
            "")->fetch(PDO::FETCH_COLUMN, 0);
            if (!$folder) {
                $db->exec(
                    "INSERT IGNORE INTO folder " .
                    "SET folder_id = ".$db->quote($parent_folder_id).", " .
                        "range_id = ".$db->quote($_SESSION['SessionSeminar']).", " .
                        "user_id = ".$db->quote($GLOBALS['user']->id).", " .
                        "name = ".$db->quote("BlubberBilder").", " .
                        "permission = '7', " .
                        "mkdate = ".$db->quote(time()).", " .
                        "chdate = ".$db->quote(time())." " .
                "");
            }
            $db->exec(
                "INSERT IGNORE INTO folder " .
                "SET folder_id = ".$db->quote($folder_id).", " .
                    "range_id = ".$db->quote($parent_folder_id).", " .
                    "user_id = ".$db->quote($GLOBALS['user']->id).", " .
                    "name = ".$db->quote(get_fullname()).", " .
                    "permission = '7', " .
                    "mkdate = ".$db->quote(time()).", " .
                    "chdate = ".$db->quote(time())." " .
            "");
        }
        

        $output = array();
        foreach ($files as $file) {
            $document = new StudipDocument();
            $document['name'] = $document['filename'] = studip_utf8decode(strtolower($file['filename']));
            $document['user_id'] = $GLOBALS['user']->id;
            $document['author_name'] = get_fullname();
            $document['seminar_id'] = $_SESSION['SessionSeminar'];
            $document['range_id'] = $folder_id;
            $document->store();
            $path = get_upload_file_path($document->getId());
            file_put_contents($path, base64_decode($file['content']));
            $document['size'] = filesize($path);
            $document->store();
            $image = false;
            foreach (array(".jpg",".png",".bmp",".gif",".svg") as $type) {
                if (strpos($document['filename'], $type)) {
                    $image = true;
                }
            }
            if ($image) {
                $output['inserts'][] = "[img]".GetDownloadLink($document->getId(), $document['filename']);
            } else {
                $output['inserts'][] = "[".$document['filename']."]".GetDownloadLink($document->getId(), $document['filename']);
            }
        }
        $this->render_json($output);
    }
    
}