<?php
/* 
 * To change this template, choose Tools | Templates
 * and open the template in the editor.
*/

/**
 * Description of manager
 *
 * @author lebigjay
 *
 *
 * functions:
 * Features : save (new/edit), togglePublish
 * Media : save (new/edit), uploadimage, togglePublish
 * Comments : validate, delete
 *
 */

class manager {
    var $status = "200 OK";
    var $error = false;
    var $message = "";

    var $feature_id = 0;
    var $media_id = 0;
    var $comment_id = 0;

    var $feature = null;
    var $medium = null;

    var $features = array();
    var $episodes = array();
    var $media = array();
    var $comments = array();
    var $comments_pending = 0;

    var $media_types = array("audio", "video", "image");
    var $mysql = null;
    var $mysql_table_prefix = null;

    var $languages = null;
    var $descriptions = array('features' => array ('title'), 'media' => array('title', 'caption', 'description'));

    var $preview = array("audio"=> array (120, 60), "video"=>array (120, 60), "image"=>array (120, 60));
    var $upload_file_ext = array (".jpg", ".bmp", ".gif", ".png");

    function manager () {
        global $config, $languages;

        $this->mysql_table_prefix = $config["sql"]["tableprefix"];
        $this->mysql = new Mysql ();

        $this->languages = $languages;

        return true;
    }

    function get_features ($feature_id = 0) {
        $feature_id = (int)$feature_id;
        $query = (!empty($feature_id))?'WHERE `feature_id` = '.$feature_id.' LIMIT 1':'';
        if ($this->mysql->query ("SELECT * FROM `".$this->mysql_table_prefix."features` ".$query)) {

            $this->features = $this->mysql->result;
            for ($i=0; $i<count($this->features); $i++) {
                $this->mysql->query("SELECT `lang`, `name`, `value` FROM `".$this->mysql_table_prefix."descriptions` WHERE `type_id` = {$this->features[$i]->feature_id} AND `type` = 'feature'");
                foreach ($this->mysql->result as $description) {
                    $this->features[$i]->{$description->lang}->{$description->name} = $description->value;
                }
            }
        }
        else {
            $this->message = $this->mysql->error;
            return $this->error = false;
        }
        if (!empty($feature_id) AND count($this->features) == 1) {
            $this->feature_id = $feature_id;
            $this->feature = $this->features[0];
            $this->get_episodes($this->feature_id);
            if (empty($this->media_id)) return $this->get_media_for_feature ();
        }
        return true;
    }

    function get_episodes ($feature_id) {
        $this->mysql->query("SELECT DISTINCT `episode_id` FROM `".$this->mysql_table_prefix."media` WHERE `feature_id` = $feature_id ORDER BY `episode_id` ASC");
        foreach ($this->mysql->result as $obj) {
            $this->episodes[] = $obj->episode_id;
        }
        return $this->episodes;
    }

    function get_media ($media_id) {
        $media_id = (int)$media_id;
        if ($this->mysql->query ("SELECT * FROM `".$this->mysql_table_prefix."media` WHERE `media_id` = $media_id LIMIT 1")) {
            $this->medium = $this->mysql->result[0];
            $this->mysql->query("SELECT `lang`, `name`, `value` FROM `".$this->mysql_table_prefix."descriptions` WHERE `type_id` = $media_id AND `type` = 'media'");
            foreach ($this->mysql->result as $description) {
                $this->medium->{$description->lang}->{$description->name} = $description->value;
            }
        }
        else {
            $this->message = $this->mysql->error;
            return $this->error = false;
        }
        switch ($this->medium->type) {
            case "image":
                if ($this->medium->source == "flickr") {
                    global $config;
                    $f = new phpFlickr($config["flickr_key"]);
                    $f->photos_getInfo($this->medium->sid);
                    $response = unserialize($f->response);
                    $farm = $response["photo"]["farm"];
                    $server = $response["photo"]["server"];
                    $secret = $response["photo"]["secret"];
                    $id = $response["photo"]["id"];
                    $this->medium->surl = "http://farm".$farm.".static.flickr.com/".$server."/{$id}_{$secret}_m.jpg";
                    $this->medium->embed = "<img src=\"".$this->medium->surl."\" />";
                }
                break;
            case "video":
                if ($this->medium->source == "youtube") {
                    $this->medium->surl = "http://www.youtube.com/watch?v=".$this->medium->sid;
                    $this->medium->embed = '<img src="http://i2.ytimg.com/vi/'.$this->medium->sid.'/default.jpg" />';
                }
                break;
        }
        $this->media_id = $media_id;
        $this->get_episodes($this->medium->feature_id);
        return $this->get_features($this->medium->feature_id);
    }

    function get_media_for_feature () {
        global $config;
        $this->mysql->query("SELECT * FROM `".$this->mysql_table_prefix."media` WHERE `feature_id` = {$this->feature_id} ORDER BY `episode_id` ASC, `type` ASC, `lead` DESC, `pubDate` ASC");
        $result = $this->mysql->result;

        for ($i=0; $i<count($result);$i++) {
            $this->mysql->query("SELECT `lang`, `name`, `value` FROM `".$this->mysql_table_prefix."descriptions` WHERE `type_id` = {$result[$i]->media_id} AND `type` = 'media'");
            foreach ($this->mysql->result as $description) {
                $result[$i]->{$description->lang}->{$description->name} = $description->value;
                if ($description->lang == $_SESSION["lang"]) $result[$i]->description->{$description->name} = $description->value;
                if (file_exists(UPLOAD_PATH.'/'.$result[$i]->uid.'.jpg')) {
                    $result[$i]->preview = $config["basehref"].'/preview/'.$result[$i]->uid.'.jpg';
                }
                switch ($result[$i]->type) {
                    case "image":
                        if ($result[$i]->source == "flickr") {
                            global $config;
                            $f = new phpFlickr($config["flickr_key"]);
                            $f->photos_getInfo($this->medium->sid);
                            $response = unserialize($f->response);
                            $farm = $response["photo"]["farm"];
                            $server = $response["photo"]["server"];
                            $secret = $response["photo"]["secret"];
                            $id = $response["photo"]["id"];
                            $result[$i]->surl = "http://farm".$farm.".static.flickr.com/".$server."/{$id}_{$secret}_m.jpg";
                            $result[$i]->embed = "<img src=\"".$this->medium->surl."\" />";
                        }
                        if ($result[$i]->source == "picasa") {
                            $result[$i]->preview = $result[$i]->sid;
                        }
                        break;
                    case "video":
                        if ($this->medium->source == "youtube") {
                            $this->medium->surl = "http://www.youtube.com/watch?v=".$this->medium->sid;
                            $this->medium->embed = '<img src="http://i2.ytimg.com/vi/'.$this->medium->sid.'/default.jpg" />';
                        }
                        break;
                }

            }
        }
        $this->media = $result;
        return true;
    }

    function toggle_online ($type, $id, $online = 1) {
        $id = (int)$id;
        switch ($type) {
            case "feature":
                return $this->mysql->query("UPDATE FROM `".$this->mysql_table_prefix."features` SET `published` = '$online' WHERE `feature_id` = $id LIMIT 1");
                break;
            case "media":
                return $this->mysql->query("UPDATE FROM `".$this->mysql_table_prefix."media` SET `published` = '$online' WHERE `media_id` = $id LIMIT 1");
                break;
        }
        return false;

    }
    function saveFeature () {            //
        if (!empty($this->feature_id) AND !$this->mysql->query ("UPDATE `".$this->mysql_table_prefix."features` SET
             `modified` = CURRENT_TIMESTAMP, `pubDate` = '{$this->feature->pubDate}', `published` = '{$this->feature->published}'
            WHERE `feature_id` = {$this->feature_id} LIMIT 1")) {

            $this->error = true;
            $this->message = $this->mysql->error;
            return false;
        }
        elseif (empty($this->feature_id)) {
            if (!$this->mysql->query ("INSERT INTO `".$this->mysql_table_prefix."features` (
                 `feature_id` ,`modified` ,`published` ,`pubDate` )
                VALUES ( NULL ,CURRENT_TIMESTAMP , '{$this->feature->published}', '{$this->feature->pubDate}');")) {
                break;
                $this->error = true;
                $this->message = $this->mysql->error;
                return false;
            }
            else
                $this->feature_id = $this->feature->feature_id = $this->mysql->insert_id;
        }
        if (!empty ($this->feature_id))
            return $this->update_descriptions ("feature", $this->feature_id, $this->feature);
    }

    function saveMedia () {
        if (!$this->mysql->query ("UPDATE `".$this->mysql_table_prefix."media` SET
            `modified` = CURRENT_TIMESTAMP
            , `episode_id` = {$this->medium->episode_id}
            , `published` = '{$this->medium->published}'
            , `pubDate` = '{$this->medium->pubDate}'
            , `lead` = '{$this->medium->lead}'
            , `type` = '{$this->medium->type}'
            , `source` = '{$this->medium->source}'
            , `sid` = '{$this->medium->sid}'
            WHERE `media_id` = {$this->media_id} LIMIT 1")) {

            $this->error = true;
            $this->message = $this->mysql->error;
            return false;
        }
        elseif ($this->mysql->affected_rows != 1) {
            if (!$this->mysql->query ("INSERT INTO `".$this->mysql_table_prefix."media` ( `media_id` ,
                `modified` ,`feature_id` ,`episode_id`, `lead` ,`uid` ,`published` ,`pubDate` ,`type` ,`source` ,`sid`)
                VALUES (NULL ,CURRENT_TIMESTAMP , '{$this->medium->feature_id}', '{$this->medium->episode_id}', '{$this->medium->lead}', '{$this->medium->uid}', '{$this->medium->published}', '{$this->medium->pubDate}', '{$this->medium->type}', '{$this->medium->source}', '{$this->medium->sid}');")) {
                $this->error = true;
                $this->message = $this->mysql->error;
                return false;
            }
            else
                $this->media_id = $this->medium->media_id = $this->mysql->insert_id;
        }
        if (!empty ($this->media_id)) {
            if ($this->medium->lead == 1) {
                $this->mysql->query ("UPDATE `".$this->mysql_table_prefix."media` SET `lead` = '0' WHERE `feature_id` = {$this->medium->feature_id} AND `episode_id` =  {$this->medium->episode_id} AND `media_id` != {$this->media_id}");
            }
            if (!empty($this->medium->delpreview)) @unlink(UPLOAD_PATH.'/'.$this->medium->uid.'.jpg');
            if (!empty($_FILES["preview"])) {
                $this->upload_image();
            }
            return $this->update_descriptions ("media", $this->media_id, $this->medium);

        }


    }

    function update_descriptions ($type, $id, $data) {

        foreach ($this->languages as $lang) {

            if (!empty($data->{$lang})) {
                foreach ($data->{$lang} as $name => $value) {
                    if (!$this->mysql->query ("UPDATE `".$this->mysql_table_prefix."descriptions` SET
                            `modified` = CURRENT_TIMESTAMP
                            , `value` = '".input2query($value)."'
                        WHERE `type_id` = $id AND `type` = '$type' AND `lang` = '$lang' AND `name` = '$name'  LIMIT 1")) {
                        break;
                        $this->error = true;
                        $this->message = $this->mysql->error;

                        return false;
                    }
                    elseif ($this->mysql->affected_rows != 1) {

                        if (!$this->mysql->query ("INSERT INTO `".$this->mysql_table_prefix."descriptions` (
                            `id` ,`type` ,`type_id` ,`modified` ,`lang` ,`name` ,`value`)
                            VALUES ( NULL , '$type', '$id', CURRENT_TIMESTAMP , '$lang', '$name', '".input2query($value)."');")) {
                            break;
                            $this->error = true;
                            $this->message = $this->mysql->error;
                            return false;
                        }
                    }

                }
            }
        }

        return true;
    }
    function deleteMedia() {
        return $this->mysql->query ("DELETE FROM `".$this->mysql_table_prefix."media` WHERE `media_id` = {$this->media_id} LIMIT 1");
    }
    function deleteFeature () {
        if ($this->mysql->query ("DELETE FROM `".$this->mysql_table_prefix."features` WHERE `feature_id` = {$this->feature_id} LIMIT 1"))
            return $this->mysql->query ("DELETE FROM `".$this->mysql_table_prefix."media` WHERE `feature_id` = {$this->feature_id} LIMIT 1");
        else
            $this->error = true;
        $this->message = $this->mysql->error;
        return false;
    }

    function upload_image () {
        $file = $_FILES["preview"];
        $file_name = $file["name"];

        //check if you have selected a file.
        $file["ext"] = strtolower(strrchr($file_name,"."));
        if (in_array($file["ext"],$this->upload_file_ext)) {

            // file is ok, resize, store
            list($file["width"], $file["height"]) = getimagesize($file["tmp_name"]);
            list($preview["width"], $preview["height"]) = $this->preview[$this->medium->type];

            if ($preview["height"]< $file["height"]*$preview["width"]/$file["width"]) {
                $tow = $file["width"];
                $toh =  $file["width"]*$preview["height"]/$preview["width"];
            }
            else {
                $tow = $preview["width"]*$file["height"]/$preview["height"];
                $toh = $file["height"];
            }

            $destination = imagecreatetruecolor($preview["width"], $preview["height"]);
            if ($file["ext"] == ".jpg") {
                $image = imagecreatefromjpeg($file["tmp_name"]);
            }
            if ($file["ext"] == ".gif") {
                $image = imagecreatefromgif($file["tmp_name"]);
            }
            if ($file["ext"] == ".png") {
                $image = imagecreatefrompng($file["tmp_name"]);
            }
            if ($file["ext"] == ".bmp") {
                $image = imagecreatefromxbmp($file["tmp_name"]);
            }

            imagecopyresampled($destination, $image, 0, 0, 0, 0, $preview["width"], $preview["height"], $tow, $toh);


            // save main
            if (!imagejpeg($destination, UPLOAD_PATH."/{$this->medium->uid}.jpg", 75)) {
                $this->message = ("There was an error while saving the image.\n");
                return false;
            }
        }
        else {
            $this->message = ("Format de fichier incorrect.\n");
        }
        unlink($file["tmp_name"]);
        return $file["id"];
    }

}
?>
