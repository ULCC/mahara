<?php
/**
 *
 * @package    mahara
 * @subpackage blocktype-gallery
 * @author     Catalyst IT Ltd
 * @author     Gregor Anzelj (External Galleries, e.g. Flickr, Picasa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL version 3 or later
 * @copyright  For copyright information on Mahara, please see the README file distributed with this software.
 * @copyright  (C) 2011 Gregor Anzelj <gregor.anzelj@gmail.com>
 *
 */

defined('INTERNAL') || die();

class PluginBlocktypeGallery extends PluginBlocktype {

    public static function get_title() {
        return get_string('title', 'blocktype.file/gallery');
    }

    public static function get_description() {
        return get_string('description1', 'blocktype.file/gallery');
    }

    public static function get_categories() {
        return array('fileimagevideo');
    }

    public static function get_instance_javascript(BlockInstance $instance) {
        $configdata = $instance->get('configdata');
        $style = isset($configdata['style']) ? intval($configdata['style']) : 2;
        switch ($style) {
            case 0: // thumbnails
            case 2: // squarethumbs
                return array();
            case 1: // slideshow
                return array('js/slideshow.js');
        }
    }

    public static function get_instance_config_javascript() {
        return array(
            'js/configform.js',
            'js/slideshow.js',
        );
    }

    public static function render_instance(BlockInstance $instance, $editing=false) {
        $configdata = $instance->get('configdata'); // this will make sure to unserialize it for us
        $configdata['viewid'] = $instance->get('view');
        $style = isset($configdata['style']) ? intval($configdata['style']) : 2;
        $copyright = null; // Needed to set Panoramio copyright later...
        $width = !empty($configdata['width']) ? $configdata['width'] : 75;
        switch ($style) {
            case 0: // thumbnails
                $template = 'thumbnails';
                break;
            case 1: // slideshow
                $template = 'slideshow';
                $width = !empty($configdata['width']) ? $configdata['width'] : 400;
                break;
            case 2: // square thumbnails
                $template = 'squarethumbs';
                break;
        }

        $images = array();
        $slimbox2 = get_config_plugin('blocktype', 'gallery', 'useslimbox2');
        if ($slimbox2) {
            $slimbox2attr = 'lightbox_' . $instance->get('id');
        }
        else {
            $slimbox2attr = null;
        }

        // if we're trying to embed external gallery (thumbnails or slideshow)
        if (isset($configdata['select']) && $configdata['select'] == 2) {
            $gallery = self::make_gallery_url($configdata['external']);
            if (empty($gallery)) {
                return get_string('externalnotsupported', 'blocktype.file/gallery');
            }
            $url  = isset($gallery['url']) ? hsc($gallery['url']) : null;
            $type = isset($gallery['type']) ? hsc($gallery['type']) : null;
            $var1 = isset($gallery['var1']) ? hsc($gallery['var1']) : null;
            $var2 = isset($gallery['var2']) ? hsc($gallery['var2']) : null;

            switch ($type) {
                case 'widget':
                /*****************************
                  Roy Tanck's FLICKR WIDGET
                  for Flickr RSS & Picasa RSS
          http://www.roytanck.com/get-my-flickr-widget/
                 *****************************/
                    $widget_sizes = array(100, 200, 300);
                    $width = self::find_nearest($widget_sizes, $width);
                    $images = urlencode(str_replace('&amp;', '&', $url));
                    $template = 'imagecloud';
                    break;
                case 'picasa':
                    // Slideshow
                    if ($style == 1) {
                        $picasa_show_sizes = array(144, 288, 400, 600, 800);
                        $width = self::find_nearest($picasa_show_sizes, $width);
                        $height = round($width * 0.75);
                        $images = array('user' => $var1, 'gallery' => $var2);
                        $template = 'picasashow';
                    }
                    // Thumbnails
                    else {
                        $picasa_thumbnails = array(32, 48, 64, 72, 104, 144, 150, 160);
                        $width = self::find_nearest($picasa_thumbnails, $width);
                        // If the Thumbnails should be Square...
                        if ($style == 2) {
                            $small = 's' . $width . '-c';
                            $URL = 'http://picasaweb.google.com/data/feed/api/user/' . $var1 . '/album/' . $var2 . '?kind=photo&thumbsize=' . $width . 'c';
                        }
                        else {
                            $small = 's' . $width;
                            $URL = 'http://picasaweb.google.com/data/feed/api/user/' . $var1 . '/album/' . $var2 . '?kind=photo&thumbsize=' . $width;
                        }
                        $big = 's' . get_config_plugin('blocktype', 'gallery', 'previewwidth');

                        $xmlDoc = new DOMDocument('1.0', 'UTF-8');
                        $config = array(
                            CURLOPT_URL => $URL,
                            CURLOPT_RETURNTRANSFER => true,
                        );
                        $result = mahara_http_request($config);
                        $xmlDoc->loadXML($result->data);
                        $photos = $xmlDoc->getElementsByTagNameNS('http://search.yahoo.com/mrss/', 'group');
                        foreach ($photos as $photo) {
                            $children = $photo->cloneNode(true);
                            $thumb = $children->getElementsByTagNameNS('http://search.yahoo.com/mrss/', 'thumbnail')->item(0)->getAttribute('url');
                            $description = null;
                            if (isset($children->getElementsByTagNameNS('http://search.yahoo.com/mrss/', 'description')->item(0)->firstChild->nodeValue)) {
                                $description = $children->getElementsByTagNameNS('http://search.yahoo.com/mrss/', 'description')->item(0)->firstChild->nodeValue;
                            }

                            $images[] = array(
                                'link' => str_replace($small, $big, $thumb),
                                'source' => $thumb,
                                'title' => $description,
                                'slimbox2' => $slimbox2attr
                            );
                        }
                    }
                    break;
                case 'flickr':
                    // Slideshow
                    if ($style == 1) {
                        $flickr_show_sizes = array(400, 500, 700, 800);
                        $width = self::find_nearest($flickr_show_sizes, $width);
                        $height = round($width * 0.75);
                        $images = array('user' => $var1, 'gallery' => $var2);
                        $template = 'flickrshow';
                    }
                    // Thumbnails
                    else {
                        $width = 75; // Currently only thumbnail size, that Flickr supports

                        $api_key = get_config_plugin('blocktype', 'gallery', 'flickrapikey');
                        $URL = 'http://api.flickr.com/services/rest/?method=flickr.photosets.getPhotos&extras=url_sq,url_t&photoset_id=' . $var2 . '&api_key=' . $api_key;
                        $xmlDoc = new DOMDocument('1.0', 'UTF-8');
                        $config = array(
                            CURLOPT_URL => $URL,
                            CURLOPT_RETURNTRANSFER => true,
                        );
                        $result = mahara_http_request($config);
                        $xmlDoc->loadXML($result->data);
                        $photos = $xmlDoc->getElementsByTagName('photo');
                        foreach ($photos as $photo) {
                            // If the Thumbnails should be Square...
                            if ($style == 2) {
                                $thumb = $photo->getAttribute('url_sq');
                                $link = str_replace('_s.jpg', '_b.jpg', $thumb);
                            }
                            else {
                                $thumb = $photo->getAttribute('url_t');
                                $link = str_replace('_t.jpg', '_b.jpg', $thumb);
                            }
                            $description = $photo->getAttribute('title');

                            $images[] = array(
                                'link' => $link,
                                'source' => $thumb,
                                'title' => $description,
                                'slimbox2' => $slimbox2attr
                            );
                        }
                    }
                    break;
                case 'panoramio':
                    // Slideshow
                    if ($style == 1) {
                        $height = round($width * 0.75);
                        $images = array('user' => $var1);
                        $template = 'panoramioshow';
                    }
                    // Thumbnails
                    else {
                        $copyright = get_string('panoramiocopyright', 'blocktype.file/gallery');
                        $URL = 'http://www.panoramio.com/map/get_panoramas.php?set=' . $var1 . '&from=0&to=50&size=original&mapfilter=true';
                        $config = array(
                            CURLOPT_URL => $URL,
                            CURLOPT_RETURNTRANSFER => true,
                        );
                        $result = mahara_http_request($config);
                        $data = json_decode($result->data, true);
                        foreach ($data['photos'] as $photo) {
                            $link = str_replace('/original/', '/large/', $photo['photo_file_url']);
                            // If the Thumbnails should be Square...
                            if ($style == 2) {
                                $thumb = str_replace('/original/', '/square/', $photo['photo_file_url']);
                                $width = 60; // Currently only square thumbnail size, that Panoramio supports
                            }
                            else {
                                $thumb = str_replace('/original/', '/thumbnail/', $photo['photo_file_url']);
                            }
                            $title = (!empty($photo['photo_title']) ? $photo['photo_title'] : get_string('Photo', 'blocktype.file/gallery'));
                            $description =  '<a href="' . $photo['photo_url'] . '" target="_blank">' . $title . '</a>'
                                         . '&nbsp;' . get_string('by', 'blocktype.file/gallery') . '&nbsp;'
                                         . '<a href="' . $photo['owner_url'] . '" target="_blank">' . $photo['owner_name'] . '</a>';

                            $images[] = array(
                                'link' => $link,
                                'source' => $thumb,
                                'title' => $description,
                                'slimbox2' => $slimbox2attr
                            );
                        }
                    }
                    break;
                case 'photobucket':
                    // Slideshow
                    if ($style == 1) {
                        $height = round($width * 0.75);
                        $images = array('url' => $url, 'user' => $var1, 'album' => $var2);
                        $template = 'photobucketshow';
                    }
                    // Thumbnails
                    else {
                        $consumer_key = get_config_plugin('blocktype', 'gallery', 'pbapikey'); // PhotoBucket API key
                        $consumer_secret = get_config_plugin('blocktype', 'gallery', 'pbapiprivatekey'); //PhotoBucket API private key

                        $oauth_signature_method = 'HMAC-SHA1';
                        $oauth_version = '1.0';
                        $oauth_timestamp = time();
                        $mt = microtime();
                        $rand = mt_rand();
                        $oauth_nonce = md5($mt . $rand);

                        $method = 'GET';
                        $albumname = $var1 . '/' . $var2;
                        $api_url = 'http://api.photobucket.com/album/' . urlencode($albumname);

                        $params = null;
                        $paramstring = 'oauth_consumer_key=' . $consumer_key . '&oauth_nonce=' . $oauth_nonce . '&oauth_signature_method=' . $oauth_signature_method . '&oauth_timestamp=' . $oauth_timestamp . '&oauth_version=' . $oauth_version;
                        $base = urlencode($method) . '&' . urlencode($api_url) . '&' . urlencode($paramstring);
                        $oauth_signature = base64_encode(hash_hmac('sha1', $base, $consumer_secret.'&', true));

                        $URL = $api_url . '?' . $paramstring . '&oauth_signature=' . urlencode($oauth_signature);
                        $xmlDoc = new DOMDocument('1.0', 'UTF-8');
                        $config = array(
                            CURLOPT_URL => $URL,
                            CURLOPT_HEADER => false,
                            CURLOPT_RETURNTRANSFER => true,
                        );
                        $result = mahara_http_request($config);
                        $xmlDoc->loadXML($result->data);

                        $xmlDoc2 = new DOMDocument('1.0', 'UTF-8');
                        $config2 = array(
                            CURLOPT_URL => $xmlDoc->getElementsByTagName('url')->item(0)->firstChild->nodeValue,
                            CURLOPT_HEADER => false,
                            CURLOPT_RETURNTRANSFER => true,
                        );
                        $result2 = mahara_http_request($config2);
                        $xmlDoc2->loadXML($result->data);

                        $photos = $xmlDoc2->getElementsByTagName('media');
                        foreach ($photos as $photo) {
                            $children = $photo->cloneNode(true);
                            $link = $children->getElementsByTagName('url')->item(0)->firstChild->nodeValue;
                            $thumb = $children->getElementsByTagName('thumb')->item(0)->firstChild->nodeValue;
                            $description = null;
                            if (isset($children->getElementsByTagName('description')->item(0)->firstChild->nodeValue)) {
                                $description = $children->getElementsByTagName('description')->item(0)->firstChild->nodeValue;
                            }

                            $images[] = array(
                                'link' => $link,
                                'source' => $thumb,
                                'title' => $description,
                                'slimbox2' => $slimbox2attr
                            );
                        }
                    }
                    break;
                case 'windowslive':
                    // Slideshow
                    if ($style == 1) {
                        $images = array('url' => $url, 'user' => $var1, 'album' => $var2);
                        $template = 'windowsliveshow';
                    }
                    // Thumbnails
                    else {
                        $config = array(
                            CURLOPT_URL => str_replace(' ', '%20', $url),
                            CURLOPT_HEADER => false,
                            CURLOPT_RETURNTRANSFER => true,
                        );
                        $result = mahara_http_request($config);
                        $data = $result->data;

                        // Extract data about images and thumbs from HTML source - hack!
                        preg_match_all("#previewImageUrl: '([a-zA-Z0-9\_\-\.\\\/]+)'#", $data, $photos);
                        preg_match_all("#thumbnailImageUrl: '([a-zA-Z0-9\_\-\.\\\/]+)'#", $data, $thumbs);

                        for ($i = 0; $i < sizeof($photos[1]); $i++) {
                            $images[] = array(
                                'link' => str_replace(array('\x3a','\x2f','\x25','\x3fpsid\x3d1'), array(':','/','%',''), $photos[1][$i]),
                                'source' => str_replace(array('\x3a','\x2f','\x25','\x3fpsid\x3d1'), array(':','/','%',''), $thumbs[1][$i]),
                                'title' => null,
                                'slimbox2' => $slimbox2attr
                            );
                        }
                    }
                    break;
            }
        }
        else {
            safe_require('artefact', 'file');
            $artefactids = array();
            if (isset($configdata['select']) && $configdata['select'] == 1 && is_array($configdata['artefactids'])) {
                $artefactids = $configdata['artefactids'];
            }
            else if (!empty($configdata['artefactid'])) {
                // Get descendents of this folder.
                $artefactids = artefact_get_descendants(array(intval($configdata['artefactid'])));
            }

            // This can be either an image or profileicon. They both implement
            // render_self
            foreach ($artefactids as $artefactid) {
                $image = $instance->get_artefact_instance($artefactid);

                if ($image instanceof ArtefactTypeProfileIcon) {
                    $src = get_config('wwwroot') . 'thumb.php?type=profileiconbyid&id=' . $artefactid;
                    $description = $image->get('title');
                }
                else if ($image instanceof ArtefactTypeImage) {
                    $src = get_config('wwwroot') . 'artefact/file/download.php?file=' . $artefactid;
                    $src .= '&view=' . $instance->get('view');
                    $description = $image->get('description');
                }
                else {
                    continue;
                }

                if ($slimbox2) {
                    $link = $src . '&maxwidth=' . get_config_plugin('blocktype', 'gallery', 'previewwidth');
                }
                else {
                    $link = get_config('wwwroot') . 'artefact/artefact.php?artefact=' . $artefactid . '&view=' . $instance->get('view');
                }

                // If the Thumbnails are Square or not...
                if ($style == 2) {
                    $src .= '&size=' . $width . 'x' . $width;
                    $height = $width;
                }
                else {
                    $src .= '&maxwidth=' . $width;
                    $imgwidth = $image->get('width');
                    $imgheight = $image->get('height');
                    $height = ($imgwidth > $width) ? intval(($width / $imgwidth) * $imgheight) : $imgheight;
                }

                $images[] = array(
                    'link' => $link,
                    'source' => $src,
                    'height' => $height,
                    'title' => $image->get('description'),
                    'slimbox2' => $slimbox2attr
                );
            }
        }

        $smarty = smarty_core();
        $smarty->assign('instanceid', $instance->get('id'));
        $smarty->assign('count', count($images));
        $smarty->assign('images', $images);
        $smarty->assign('showdescription', (!empty($configdata['showdescription'])) ? $configdata['showdescription'] : false);
        $smarty->assign('width', $width);
        $smarty->assign('captionwidth', (get_config_plugin('blocktype', 'gallery', 'photoframe') ? $width + 8 : $width));
        if (isset($height)) {
            $smarty->assign('height', $height);
        }
        if (isset($needsapikey)) {
            $smarty->assign('needsapikey', $needsapikey);
        }
        $smarty->assign('frame', get_config_plugin('blocktype', 'gallery', 'photoframe'));
        $smarty->assign('copyright', $copyright);

        return $smarty->fetch('blocktype:gallery:' . $template . '.tpl');
    }

    public static function has_config() {
        return true;
    }

    public static function get_config_options() {
        $elements = array();
        $elements['gallerysettings'] = array(
            'type' => 'fieldset',
            'legend' => get_string('gallerysettings', 'blocktype.file/gallery'),
            'collapsible' => true,
            'elements' => array(
                'useslimbox2' => array(
                    'type'         => 'checkbox',
                    'title'        => get_string('useslimbox2', 'blocktype.file/gallery'),
                    'description'  => get_string('useslimbox2desc', 'blocktype.file/gallery'),
                    'defaultvalue' => get_config_plugin('blocktype', 'gallery', 'useslimbox2'),
                ),
                'photoframe' => array(
                    'type'         => 'checkbox',
                    'title'        => get_string('photoframe', 'blocktype.file/gallery'),
                    'description'  => get_string('photoframedesc', 'blocktype.file/gallery'),
                    'defaultvalue' => get_config_plugin('blocktype', 'gallery', 'photoframe'),
                ),
                'previewwidth' => array(
                    'type'         => 'text',
                    'size'         => 4,
                    'title'        => get_string('previewwidth', 'blocktype.file/gallery'),
                    'description'  => get_string('previewwidthdesc', 'blocktype.file/gallery'),
                    'defaultvalue' => get_config_plugin('blocktype', 'gallery', 'previewwidth'),
                    'rules'        => array('integer' => true, 'minvalue' => 16, 'maxvalue' => 1600),
                )
            ),
        );
        $elements['flickrsettings'] = array(
            'type' => 'fieldset',
            'legend' => get_string('flickrsettings', 'blocktype.file/gallery'),
            'collapsible' => true,
            'collapsed' => true,
            'elements' => array(
                'flickrapikey' => array(
                    'type'         => 'text',
                    'title'        => get_string('flickrapikey', 'blocktype.file/gallery'),
                    'size'         => 40, // Flickr API key is actually 32 characters long
                    'description'  => get_string('flickrapikeydesc', 'blocktype.file/gallery'),
                    'defaultvalue' => get_config_plugin('blocktype', 'gallery', 'flickrapikey'),
                ),
            ),
        );
        $elements['photobucketsettings'] = array(
            'type' => 'fieldset',
            'legend' => get_string('pbsettings', 'blocktype.file/gallery'),
            'collapsible' => true,
            'collapsed' => true,
            'elements' => array(
                'pbapikey' => array(
                    'type'         => 'text',
                    'title'        => get_string('pbapikey', 'blocktype.file/gallery'),
                    'size'         => 20, // PhotoBucket API key is actually 9 characters long
                    'description'  => get_string('pbapikeydesc', 'blocktype.file/gallery'),
                    'defaultvalue' => get_config_plugin('blocktype', 'gallery', 'pbapikey'),
                ),
                'pbapiprivatekey' => array(
                    'type'         => 'text',
                    'title'        => get_string('pbapiprivatekey', 'blocktype.file/gallery'),
                    'size'         => 40, // PhotoBucket API private key is actually 32 characters long
                    'defaultvalue' => get_config_plugin('blocktype', 'gallery', 'pbapiprivatekey'),
                ),
            ),
        );
        return array(
            'elements' => $elements,
        );

    }

    public static function save_config_options($form, $values) {
        set_config_plugin('blocktype', 'gallery', 'useslimbox2', (int)$values['useslimbox2']);
        set_config_plugin('blocktype', 'gallery', 'photoframe', (int)$values['photoframe']);
        set_config_plugin('blocktype', 'gallery', 'previewwidth', (int)$values['previewwidth']);
        set_config_plugin('blocktype', 'gallery', 'flickrapikey', $values['flickrapikey']);
        set_config_plugin('blocktype', 'gallery', 'pbapikey', $values['pbapikey']);
        set_config_plugin('blocktype', 'gallery', 'pbapiprivatekey', $values['pbapiprivatekey']);
    }

    public static function postinst($prevversion) {
        if ($prevversion == 0) {
            set_config_plugin('blocktype', 'gallery', 'useslimbox2', 1); // Use Slimbox 2 by default
            set_config_plugin('blocktype', 'gallery', 'photoframe', 1); // Show frame around photos
            set_config_plugin('blocktype', 'gallery', 'previewwidth', 1024); // Maximum photo width for slimbox2 preview
        }
    }

    public static function has_instance_config() {
        return true;
    }

    public static function instance_config_form($instance) {
        $configdata = $instance->get('configdata');
        safe_require('artefact', 'file');
        $instance->set('artefactplugin', 'file');
        $user = $instance->get('view_obj')->get('owner');
        $select_options = array(
            0 => get_string('selectfolder', 'blocktype.file/gallery'),
            1 => get_string('selectimages', 'blocktype.file/gallery'),
            2 => get_string('selectexternal', 'blocktype.file/gallery'),
        );
        $style_options = array(
            0 => get_string('stylethumbs', 'blocktype.file/gallery'),
            2 => get_string('stylesquares', 'blocktype.file/gallery'),
            1 => get_string('styleslideshow', 'blocktype.file/gallery'),
        );
        if (isset($configdata['select']) && $configdata['select'] == 1) {
            $imageids = isset($configdata['artefactids']) ? $configdata['artefactids'] : array();
            $imageselector = self::imageselector($instance, $imageids);
            $folderselector = self::folderselector($instance, null, 'hidden');
            $externalurl = self::externalurl($instance, null, 'hidden');
        }
        else if (isset($configdata['select']) && $configdata['select'] == 2) {
            $imageselector = self::imageselector($instance, null, 'hidden');
            $folderselector = self::folderselector($instance, null, 'hidden');
            $url = isset($configdata['external']) ? urldecode($configdata['external']) : null;
            $externalurl = self::externalurl($instance, $url);
        }
        else {
            $imageselector = self::imageselector($instance, null, 'hidden');
            $folderid = !empty($configdata['artefactid']) ? array(intval($configdata['artefactid'])) : null;
            $folderselector = self::folderselector($instance, $folderid);
            $externalurl = self::externalurl($instance, null, 'hidden');
        }
        return array(
            'user' => array(
                'type' => 'hidden',
                'value' => $user,
            ),
            'select' => array(
                'type' => 'radio',
                'title' => get_string('select', 'blocktype.file/gallery'),
                'options' => $select_options,
                'defaultvalue' => (isset($configdata['select'])) ? $configdata['select'] : 0,
                'separator' => '<br>',
            ),
            'images' => $imageselector,
            'folder' => $folderselector,
            'external' => $externalurl,
            'style' => array(
                'type' => 'radio',
                'title' => get_string('style', 'blocktype.file/gallery'),
                'options' => $style_options,
                'defaultvalue' => (isset($configdata['style'])) ? $configdata['style'] : 2, // Square thumbnails should be default...
                'separator' => '<br>',
            ),
            'showdescription' => array(
                'type'  => 'checkbox',
                'title' => get_string('showdescriptions', 'blocktype.file/gallery'),
                'description' => get_string('showdescriptionsdescription', 'blocktype.file/gallery'),
                'defaultvalue' => !empty($configdata['showdescription']) ? true : false,
            ),
            'width' => array(
                'type' => 'text',
                'title' => get_string('width', 'blocktype.file/gallery'),
                'size' => 3,
                'description' => get_string('widthdescription', 'blocktype.file/gallery'),
                'rules' => array(
                    'minvalue' => 16,
                    'maxvalue' => get_config('imagemaxwidth'),
                ),
                'defaultvalue' => (isset($configdata['width'])) ? $configdata['width'] : '75',
            ),
        );
    }

    public static function instance_config_validate($form, $values) {
        global $USER;

        if (!empty($values['images'])) {
            foreach ($values['images'] as $id) {
                $image = new ArtefactTypeImage($id);
                if (!($image instanceof ArtefactTypeImage) || !$USER->can_view_artefact($image)) {
                    $result['message'] = get_string('unrecoverableerror', 'error');
                    $form->set_error(null, $result['message']);
                    $form->reply(PIEFORM_ERR, $result);
                }
            }
        }

        if (!empty($values['folder'])) {
            $folder = artefact_instance_from_id($values['folder']);
            if (!($folder instanceof ArtefactTypeFolder) || !$USER->can_view_artefact($folder)) {
                $result['message'] = get_string('unrecoverableerror', 'error');
                $form->set_error(null, $result['message']);
                $form->reply(PIEFORM_ERR, $result);
            }
        }
    }

    public static function instance_config_save($values) {
        if ($values['select'] == 0) {
            $values['artefactid'] = $values['folder'];
            unset($values['artefactids']);
            unset($values['external']);
        }
        else if ($values['select'] == 1) {
            $values['artefactids'] = $values['images'];
            unset($values['artefactid']);
            unset($values['external']);
        }
        else if ($values['select'] == 2) {
            unset($values['artefactid']);
            unset($values['artefactids']);
        }
        unset($values['folder']);
        unset($values['images']);
        switch ($values['style']) {
            case 0: // thumbnails
            case 2: // square thumbnails
                $values['width'] = !empty($values['width']) ? $values['width'] : 75;
                break;
            case 1: // slideshow
                $values['width'] = !empty($values['width']) ? $values['width'] : 400;
                break;
        }
        return $values;
    }

    public static function imageselector(&$instance, $default=array(), $class=null) {
        $element = ArtefactTypeFileBase::blockconfig_filebrowser_element($instance, $default);
        $element['title'] = get_string('Images', 'artefact.file');
        $element['name'] = 'images';
        if ($class) {
            $element['class'] = $class;
        }
        $element['config']['selectone'] = false;
        $element['filters'] = array(
            'artefacttype'    => array('image', 'profileicon'),
        );
        return $element;
    }

    public static function folderselector(&$instance, $default=array(), $class=null) {
        $element = ArtefactTypeFileBase::blockconfig_filebrowser_element($instance, $default);
        $element['title'] = get_string('folder', 'artefact.file');
        $element['name'] = 'folder';
        if ($class) {
            $element['class'] = $class;
        }
        $element['config']['upload'] = false;
        $element['config']['selectone'] = true;
        $element['config']['selectfolders'] = true;
        $element['filters'] = array(
            'artefacttype'    => array('folder'),
        );
        return $element;
    }

    public static function externalurl(&$instance, $default=null, $class=null) {
        $element['title'] = get_string('externalgalleryurl', 'blocktype.file/gallery');
        $element['name'] = 'external';
        $element['type'] = 'textarea';
        if ($class) {
            $element['class'] = $class;
        }
        $element['rows'] = 5;
        $element['cols'] = 76;
        $element['defaultvalue'] = $default;
        $element['description'] = '<tr id="externalgalleryhelp" class="'.($class ? $class : '').'"><td colspan="2" class="description">'.
                                  get_string('externalgalleryurldesc', 'blocktype.file/gallery') . self::get_supported_external_galleries() .
                                  '</td></tr>';
        $element['help'] = true;
        return $element;
    }

    private static function make_gallery_url($url) {
        static $embedsources = array(
            // PicasaWeb Album (RSS) - for Roy Tanck's widget
            array(
                'match' => '#.*picasaweb.google.([a-zA-Z]{3}).*user\/([a-zA-Z0-9\_\-\=\&\.\/\:\%]+)\/albumid\/(\d+).*#',
                'url'   => 'http://picasaweb.google.$1/data/feed/base/user/$2/albumid/$3?alt=rss&kind=photo',
                'type'  => 'widget',
                'var1' => '$2',
                'var2' => '$3',
            ),
            // PicasaWeb Album (embed code)
            array(
                'match' => '#.*picasaweb.google.([a-zA-Z]{3})\/s\/c.*picasaweb.google.([a-zA-Z]{3})\/([a-zA-Z0-9\_\-\.]+)\/([a-zA-Z0-9\_\-\=\&\.\/\:\%]+).*#',
                'url'   => 'http://picasaweb.google.$2',
                'type'  => 'picasa',
                'var1' => '$3',
                'var2' => '$4',
            ),
            // PicasaWeb Album (direct link)
            array(
                'match' => '#.*picasaweb.google.([a-zA-Z]{3})\/([a-zA-Z0-9\_\-\.]+)\/([a-zA-Z0-9\_\-\=\&\.\/\:\%]+).*#',
                'url'   => 'http://picasaweb.google.$1',
                'type'  => 'picasa',
                'var1' => '$2',
                'var2' => '$3',
            ),
            // Flickr Set (RSS) - for Roy Tanck's widget
            array(
                'match' => '#.*api.flickr.com.*set=(\d+).*nsid=([a-zA-Z0-9\@]+).*#',
                'url'   => 'http://api.flickr.com/services/feeds/photoset.gne?set=$1&nsid=$2',
                'type'  => 'widget',
                'var1' => '$2',
                'var2' => '$1',
            ),
            // Flickr Set (direct link)
            array(
                'match' => '#.*www.flickr.com/photos/([a-zA-Z0-9\_\-\.\@]+).*/sets/([0-9]+).*#',
                'url'   => 'http://www.flickr.com/photos/$1/sets/$2/',
                'type'  => 'flickr',
                'var1' => '$1',
                'var2' => '$2',
            ),
            // Panoramio User Photos (direct link)
            array(
                'match' => '#.*www.panoramio.com/user/(\d+).*#',
                'url'   => 'http://www.panoramio.com/user/$1/',
                'type'  => 'panoramio',
                'var1' => '$1',
                'var2' => null,
            ),
            // Photobucket User Photos (direct link)
            array(
                'match' => '#.*([a-zA-Z0-9]+).photobucket.com/albums/([a-zA-Z0-9]+)/([a-zA-Z0-9\.\,\:\;\@\-\_\+\ ]+).*#',
                'url'   => 'http://$1.photobucket.com/albums/$2/$3',
                'type'  => 'photobucket',
                'var1' => '$3',
                'var2' => null,
            ),
            // Photobucket User Album Photos (direct link)
            array(
                'match' => '#.*([a-zA-Z0-9]+).photobucket.com/albums/([a-zA-Z0-9]+)/([a-zA-Z0-9\.\,\:\;\@\-\_\+\ ]+)/([a-zA-Z0-9\.\,\:\;\@\-\_\+\ ]*).*#',
                'url'   => 'http://$1.photobucket.com/albums/$2/$3/$4',
                'type'  => 'photobucket',
                'var1' => '$3',
                'var2' => '$4',
            ),
            // Windows Live Photo Gallery (MUST be a direct link to one of the photos in the album!)
            // This is a hack - in order to show photos from the album, we require a direct link to one of the photos.
            array(
                'match' => '#.*cid-([a-zA-Z0-9]+).photos.live.com/self.aspx/([a-zA-Z0-9\.\,\:\;\@\-\_\+\%\ ]+)/([a-zA-Z0-9\,\:\;\@\-\_\+\%\ ]+).(gif|png|jpg|jpeg)*#',
                'url'   => 'http://cid-$1.photos.live.com/self.aspx/$2/$3.$4',
                'type'  => 'windowslive',
                'var1' => 'cid-$1',
                'var2' => '$2',
            ),
        );

        foreach ($embedsources as $source) {
            $url = htmlspecialchars_decode($url); // convert &amp; back to &, etc.
            if (preg_match($source['match'], $url)) {
                $images_url = preg_replace($source['match'], $source['url'], $url);
                $images_type = $source['type'];
                $images_var1 = preg_replace($source['match'], $source['var1'], $url);
                $images_var2 = preg_replace($source['match'], $source['var2'], $url);
                return array('url' => $images_url, 'type' => $images_type, 'var1' => $images_var1, 'var2' => $images_var2);
            }
        }
        return array();
    }

    /**
     * Returns a block of HTML that the Gallery block can use to list
     * which external galleries or photo services are supported.
     */
    private static function get_supported_external_galleries() {
        $smarty = smarty_core();
        $smarty->assign('wwwroot', get_config('wwwroot'));
        if (is_https() === true) {
            $smarty->assign('protocol', 'https');
        }
        else {
            $smarty->assign('protocol', 'http');
        }
        return $smarty->fetch('blocktype:gallery:supported.tpl');
    }

    // Function to find nearest value (in array of values) to given value
    // e.g.: user defined thumbnail width is 75, abvaliable picasa thumbnails are array(32, 48, 64, 72, 104, 144, 150, 160)
    //         so this function should return 72 (which is nearest form available values)
    // Function found at http://www.sitepoint.com/forums/showthread.php?t=537541
    public static function find_nearest($values, $item) {
        if (in_array($item,$values)) {
            $out = $item;
        }
        else {
            sort($values);
            $length = count($values);
            for ($i=0; $i<$length; $i++) {
                if ($values[$i] > $item) {
                    if ($i == 0) {
                        return $values[$i];
                    }
                    $out = ($item - $values[$i-1]) > ($values[$i]-$item) ? $values[$i] : $values[$i-1];
                    break;
                }
            }
        }
        if (!isset($out)) {
            $out = end($values);
        }
        return $out;
    }

    public static function artefactchooser_element($default=null) {
    }

    public static function default_copy_type() {
        return 'full';
    }
}
